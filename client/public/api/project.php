<?php
/** GET    /api/project.php?id=N  → { project, metadata }
 *  DELETE /api/project.php?id=N  → delete project (cascades) */
require_once dirname(__FILE__) . '/_lib.php';

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) { fail('Invalid project id.', 400); }

if ($method === 'GET') {
    $pr = $DB->query("SELECT project_id, project_name, repository_url, created_at FROM projects WHERE project_id=" . (int) $id);
    if (!$pr || $pr->num_rows === 0) { fail('Project not found.', 404); }
    $project = $pr->fetch_assoc();
    $project['project_id'] = (int) $project['project_id'];
    // repository_url is a JSON array of URLs (decode tolerantly).
    $rv = $project['repository_url'];
    if ($rv === null || $rv === '') { $project['repository_url'] = array(); }
    else { $dec = json_decode($rv, true); $project['repository_url'] = is_array($dec) ? array_values(array_filter($dec, 'strlen')) : array($rv); }

    // Include optional columns only if they exist (graceful before the ALTERs).
    $existing = array();
    $cr = $DB->query("SHOW COLUMNS FROM project_metadata");
    if ($cr) { while ($c = $cr->fetch_assoc()) { $existing[$c['Field']] = true; } }
    $sel = array('meta_id', 'project_id', 'server_location', 'tech_stack', 'functional_purpose');
    if (!empty($existing['overview']))     { $sel[] = 'overview'; }
    if (!empty($existing['generated_at'])) { $sel[] = 'generated_at'; }

    $meta = null;
    $mr = $DB->query("SELECT " . implode(', ', $sel) . " FROM project_metadata WHERE project_id=" . (int) $id);
    if ($mr && $m = $mr->fetch_assoc()) {
        $m['meta_id'] = (int) $m['meta_id'];
        $m['project_id'] = (int) $m['project_id'];
        if (!isset($m['overview'])) { $m['overview'] = ''; }
        $meta = $m;
    }
    send_json(array('project' => $project, 'metadata' => $meta), 200);
}

if ($method === 'PUT') {
    $in = body_json();
    $name = isset($in['projectName']) ? trim($in['projectName']) : '';
    if ($name === '') { fail('projectName is required.', 400); }

    $urls = array();
    if (isset($in['repositoryUrls']) && is_array($in['repositoryUrls'])) {
        foreach ($in['repositoryUrls'] as $u) { if (is_string($u) && trim($u) !== '') { $urls[] = trim($u); } }
    }
    $repoJson = count($urls) ? json_encode(array_values($urls)) : null;

    $stmt = $DB->prepare("UPDATE projects SET project_name=?, repository_url=? WHERE project_id=?");
    $stmt->bind_param('ssi', $name, $repoJson, $id);
    $stmt->execute();
    $stmt->close();

    // Import .md/.env from the repos, skipping files already present (so newly
    // added repos get imported, existing ones aren't duplicated).
    require_once dirname(__FILE__) . '/github.php';
    $existing = array();
    $er = $DB->query("SELECT file_name FROM project_documents WHERE project_id=" . (int) $id);
    if ($er) { while ($e = $er->fetch_assoc()) { $existing[$e['file_name']] = true; } }

    $imported = array();
    $importErrors = array();
    foreach ($urls as $u) {
        if (stripos($u, 'github.com') === false) { continue; }
        $imp = github_import($u, $GITHUB_TOKEN, $GITHUB_MAX_FILES, $GITHUB_MAX_FILE_BYTES);
        if (isset($imp['files'])) {
            $prefix = gh_doc_prefix($u, $imp);
            foreach ($imp['files'] as $f) {
                $fname = $prefix . $f['path'];
                if (isset($existing[$fname])) { continue; }
                $fp = 'github:' . $imp['owner'] . '/' . $imp['repo'] . '/' . $f['path'];
                $sz = (int) $f['size'];
                $stmt3 = $DB->prepare("INSERT INTO project_documents (project_id, file_name, file_path, content_text, byte_size) VALUES (?, ?, ?, ?, ?)");
                $stmt3->bind_param('isssi', $id, $fname, $fp, $f['text'], $sz);
                $stmt3->execute();
                $stmt3->close();
                $existing[$fname] = true;
                $imported[] = $fname;
            }
        } else {
            $importErrors[] = $u . ': ' . (isset($imp['error']) ? $imp['error'] : 'import failed');
        }
    }

    send_json(array(
        'success' => true,
        'project' => array('project_id' => (int) $id, 'project_name' => $name, 'repository_url' => $urls),
        'github_import' => array('imported' => $imported, 'errors' => $importErrors),
    ), 200);
}

if ($method === 'DELETE') {
    // Deleting the project row cascades (ON DELETE CASCADE) to project_metadata,
    // project_documents, and chat_logs.
    $stmt = $DB->prepare("DELETE FROM projects WHERE project_id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $aff = $stmt->affected_rows;
    $stmt->close();
    if ($aff === 0) { fail('Project not found.', 404); }

    // The DB cascade doesn't touch disk — remove the project's uploaded image
    // files (document images and chat attachment images).
    foreach (array($UPLOAD_DIR . '/' . (int) $id, $UPLOAD_DIR . '/chat/' . (int) $id) as $dir) {
        if (is_dir($dir) && strpos($dir, $UPLOAD_DIR) === 0) {
            $files = @glob($dir . '/*');
            if (is_array($files)) { foreach ($files as $f) { if (is_file($f)) { @unlink($f); } } }
            @rmdir($dir);
        }
    }

    send_json(array('success' => true), 200);
}

fail('Method not allowed.', 405);
?>
