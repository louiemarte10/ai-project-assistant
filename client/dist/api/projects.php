<?php
/** GET  /api/projects.php   → list projects (repository_url decoded to an array)
 *  POST /api/projects.php   → create project (+ pending metadata, + GitHub import) */
require_once dirname(__FILE__) . '/_lib.php';

$method = $_SERVER['REQUEST_METHOD'];

/** repository_url is a JSON array of URLs. Decode tolerantly (handles JSON,
 *  a legacy plain-URL string, or null/empty). Returns array of non-empty URLs. */
function decode_repo_urls($val) {
    if ($val === null || $val === '') { return array(); }
    $d = json_decode($val, true);
    if (is_array($d)) {
        $out = array();
        foreach ($d as $u) { if (is_string($u) && trim($u) !== '') { $out[] = trim($u); } }
        return $out;
    }
    return array(trim($val)); // legacy single URL stored as a plain string
}

if ($method === 'GET') {
    $res = $DB->query("SELECT project_id, project_name, repository_url, created_at FROM projects ORDER BY created_at DESC");
    $rows = array();
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $r['project_id'] = (int) $r['project_id'];
            $r['repository_url'] = decode_repo_urls($r['repository_url']);
            $rows[] = $r;
        }
    }
    send_json($rows, 200);
}

if ($method === 'POST') {
    $in = body_json();
    $name = isset($in['projectName']) ? trim($in['projectName']) : '';
    if ($name === '') { fail('projectName is required.', 400); }

    // Accept repositoryUrls (array) or a single repositoryUrl (back-compat).
    $urls = array();
    if (isset($in['repositoryUrls']) && is_array($in['repositoryUrls'])) {
        foreach ($in['repositoryUrls'] as $u) { if (is_string($u) && trim($u) !== '') { $urls[] = trim($u); } }
    } else if (isset($in['repositoryUrl']) && trim($in['repositoryUrl']) !== '') {
        $urls[] = trim($in['repositoryUrl']);
    }
    $repoJson = count($urls) ? json_encode(array_values($urls)) : null;

    $DB->autocommit(false);

    $stmt = $DB->prepare("INSERT INTO projects (project_name, repository_url) VALUES (?, ?)");
    $stmt->bind_param('ss', $name, $repoJson);
    if (!$stmt->execute()) {
        $DB->rollback(); $DB->autocommit(true);
        fail('Failed to create project: ' . $DB->error, 500);
    }
    $pid = $DB->insert_id;
    $stmt->close();

    // NOT NULL columns → seed with "pending"; the AI summary updates them later.
    $pending = 'pending';
    $stmt2 = $DB->prepare("INSERT INTO project_metadata (project_id, server_location, tech_stack, functional_purpose) VALUES (?, ?, ?, ?)");
    $stmt2->bind_param('isss', $pid, $pending, $pending, $pending);
    $stmt2->execute();
    $stmt2->close();

    // Read back inside the transaction (MaxScale: keeps the read on master).
    $res = $DB->query("SELECT project_id, project_name, repository_url, created_at FROM projects WHERE project_id=" . (int) $pid);
    $row = $res ? $res->fetch_assoc() : null;
    if ($row) {
        $row['project_id'] = (int) $row['project_id'];
        $row['repository_url'] = decode_repo_urls($row['repository_url']);
    }

    $DB->commit();
    $DB->autocommit(true);

    // Auto-import .md/.env from EVERY repo (best-effort). File names are prefixed
    // with the repo so files from different repos don't collide.
    require_once dirname(__FILE__) . '/github.php';
    $imported = array();
    $importErrors = array();
    foreach ($urls as $u) {
        if (stripos($u, 'github.com') === false) { continue; }
        $imp = github_import($u, $GITHUB_TOKEN, $GITHUB_MAX_FILES, $GITHUB_MAX_FILE_BYTES);
        if (isset($imp['files'])) {
            $prefix = gh_doc_prefix($u, $imp);
            foreach ($imp['files'] as $f) {
                $fname = $prefix . $f['path'];
                $fp = 'github:' . $imp['owner'] . '/' . $imp['repo'] . '/' . $f['path'];
                $sz = (int) $f['size'];
                $stmt3 = $DB->prepare("INSERT INTO project_documents (project_id, file_name, file_path, content_text, byte_size) VALUES (?, ?, ?, ?, ?)");
                $stmt3->bind_param('isssi', $pid, $fname, $fp, $f['text'], $sz);
                $stmt3->execute();
                $stmt3->close();
                $imported[] = $fname;
            }
        } else {
            $importErrors[] = $u . ': ' . (isset($imp['error']) ? $imp['error'] : 'import failed');
        }
    }
    $import = (count($imported) || count($importErrors)) ? array('imported' => $imported, 'errors' => $importErrors) : null;

    if ($row !== null) { $row['github_import'] = $import; }
    send_json($row, 201);
}

fail('Method not allowed.', 405);
?>
