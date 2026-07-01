<?php
/** POST /api/summary.php  { project_id }  → (re)generate AI summary, UPDATE metadata */
require_once dirname(__FILE__) . '/_lib.php';
require_once dirname(__FILE__) . '/ai.php';
require_once dirname(__FILE__) . '/budget.php';
require_once dirname(__FILE__) . '/apikeys.php';
require_once dirname(__FILE__) . '/extract.php';

function strip_json_fences($t) {
    $t = preg_replace('/^\s*```(?:json)?/i', '', $t);
    $t = preg_replace('/```\s*$/', '', $t);
    return trim($t);
}

$in = body_json();
$pid = 0;
if (isset($in['project_id'])) { $pid = (int) $in['project_id']; }
else if (isset($_GET['project_id'])) { $pid = (int) $_GET['project_id']; }
if ($pid <= 0) { fail('project_id is required.', 400); }

// Use the logged-in user's own Gemini key (if per-user keys are enabled).
if (apikey_table_exists($DB)) {
    $keyRow = apikey_get_active($DB, current_user_id());
    if (!$keyRow) { fail('NO_API_KEY', 428); }
    $GEMINI_API_KEY = $keyRow['api_key'];
    if (!empty($keyRow['ai_model'])) { $GEMINI_MODEL = $keyRow['ai_model']; }
}

// Does project_documents have the mime_type column? (graceful before the ALTER)
$dHasMime = false;
$dmc = $DB->query("SHOW COLUMNS FROM project_documents LIKE 'mime_type'");
if ($dmc && $dmc->num_rows > 0) { $dHasMime = true; }
$dCols = $dHasMime ? "file_name, content_text, mime_type, file_path" : "file_name, content_text";

$res = $DB->query("SELECT $dCols FROM project_documents WHERE project_id=" . (int) $pid . " ORDER BY created_at ASC");
if (!$res || $res->num_rows === 0) {
    fail('Upload at least one document before generating a summary.', 400);
}

$docsText = '';
$imageParts = array();
$maxImages = 12;
while ($r = $res->fetch_assoc()) {
    $mime = isset($r['mime_type']) ? $r['mime_type'] : null;
    if (is_inline_doc_mime($mime)) {
        // Image/PDF doc → attach as inline_data (read from disk by the AI).
        if (count($imageParts) < $maxImages && !empty($r['file_path'])) {
            $part = ai_image_part($r['file_path'], $mime);
            if ($part) {
                $imageParts[] = $part;
                $kind = ($mime === 'application/pdf') ? 'PDF' : 'IMAGE';
                $docsText .= "\n\n### " . $kind . ": " . $r['file_name'] . " (attached below)";
            }
        }
        continue;
    }
    $block = "\n\n### FILE: " . $r['file_name'] . "\n" . $r['content_text'];
    if (strlen($docsText) + strlen($block) > $AI_CONTEXT_CHAR_LIMIT) {
        $docsText .= substr($block, 0, max(0, $AI_CONTEXT_CHAR_LIMIT - strlen($docsText)));
        break;
    }
    $docsText .= $block;
}

$pn = '';
$pr = $DB->query("SELECT project_name FROM projects WHERE project_id=" . (int) $pid);
if ($pr && $row = $pr->fetch_assoc()) { $pn = $row['project_name']; }

$prompt =
    "You are a senior engineer analyzing a newly handed-over software project named \"" . $pn . "\".\n" .
    "Respond with ONLY a JSON object (no markdown fences) with exactly these string fields:\n" .
    "  serverLocation: IP address, domain, or env/config file path where it runs, or 'Not specified in documentation'.\n" .
    "  techStack: comma-separated frameworks, languages, databases and key libraries.\n" .
    "  functionalPurpose: 1-3 sentences on the high-level business/functional purpose.\n" .
    "  overview: a short markdown overview (architecture, entry points, how to run, gotchas).\n\n" .
    "Any attached images are screenshots/diagrams for this project — use them as context.\n\n" .
    "=== PROJECT FILES START ===\n" . $docsText . "\n=== PROJECT FILES END ===";

$out = ai_generate($prompt, true, $imageParts);
if (!$out['ok']) { fail('AI error: ' . $out['error'], 502); }

// Persist usage to the ledger (survives deletes; powers the dashboard).
if (isset($out['usage']) && $out['usage'] !== null) { ai_usage_log($pid, $out['usage'], 'summary'); }

$parsed = json_decode(strip_json_fences($out['text']), true);
if (!is_array($parsed)) {
    $parsed = array(
        'serverLocation' => 'Not specified in documentation',
        'techStack' => 'Unknown',
        'functionalPurpose' => 'Could not be determined automatically.',
        'overview' => $out['text'],
    );
}

$server  = (isset($parsed['serverLocation'])  && trim($parsed['serverLocation'])  !== '') ? $parsed['serverLocation']  : 'Not specified in documentation';
$stack   = (isset($parsed['techStack'])       && trim($parsed['techStack'])       !== '') ? $parsed['techStack']       : 'Unknown';
$purpose = (isset($parsed['functionalPurpose']) && trim($parsed['functionalPurpose']) !== '') ? $parsed['functionalPurpose'] : 'Not determined.';
$overview = isset($parsed['overview']) ? $parsed['overview'] : '';

// Gemini token usage (stored as JSON in usage_meta_data).
$usageJson = (isset($out['usage']) && $out['usage'] !== null) ? json_encode($out['usage']) : null;

// Which optional columns exist (graceful before the ALTERs).
$existing = array();
$cr = $DB->query("SHOW COLUMNS FROM project_metadata");
if ($cr) { while ($c = $cr->fetch_assoc()) { $existing[$c['Field']] = true; } }

// UPSERT (project_id has a UNIQUE key): updates the existing row, or creates it
// if the metadata row is missing. UPDATE-only would silently no-op when the row
// doesn't exist, leaving project_metadata empty even after a summary.
$generatedAt = gmdate('Y-m-d H:i:s'); // UTC; UI renders it in UTC+8

$fields = array('project_id', 'server_location', 'tech_stack', 'functional_purpose');
$types  = 'isss';
$vals   = array($pid, $server, $stack, $purpose);
if (!empty($existing['overview']))        { $fields[] = 'overview';        $types .= 's'; $vals[] = $overview; }
if (!empty($existing['usage_meta_data'])) { $fields[] = 'usage_meta_data'; $types .= 's'; $vals[] = $usageJson; }
if (!empty($existing['generated_at']))    { $fields[] = 'generated_at';    $types .= 's'; $vals[] = $generatedAt; }

$place = array_fill(0, count($fields), '?');
$updates = array();
foreach ($fields as $f) { if ($f !== 'project_id') { $updates[] = "$f=VALUES($f)"; } }
$sql = "INSERT INTO project_metadata (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $place) . ")"
     . " ON DUPLICATE KEY UPDATE " . implode(', ', $updates);

$stmt = $DB->prepare($sql);
$bindArgs = array($types);
for ($i = 0; $i < count($vals); $i++) { $bindArgs[] = &$vals[$i]; }
call_user_func_array(array($stmt, 'bind_param'), $bindArgs);
$stmt->execute();
$stmt->close();

// Build the response from the values we just wrote — do NOT read them back.
// The "main" host is a MaxScale read/write-split proxy, so a SELECT right after
// an autocommit UPDATE can hit a not-yet-replicated replica (stale read). meta_id
// is stable (created with the project), so reading just that is safe.
$metaId = 0;
$mr = $DB->query("SELECT meta_id FROM project_metadata WHERE project_id=" . (int) $pid);
if ($mr && $m = $mr->fetch_assoc()) { $metaId = (int) $m['meta_id']; }

$meta = array(
    'meta_id' => $metaId,
    'project_id' => (int) $pid,
    'server_location' => $server,
    'tech_stack' => $stack,
    'functional_purpose' => $purpose,
    'overview' => $overview,
    'usage_meta_data' => isset($out['usage']) ? $out['usage'] : null,
    'generated_at' => $generatedAt,
);

send_json(array('metadata' => $meta, 'overview' => $overview, 'usage' => isset($out['usage']) ? $out['usage'] : null), 200);
?>
