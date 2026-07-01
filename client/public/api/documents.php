<?php
/** GET  /api/documents.php?project_id=N  → list documents
 *  POST /api/documents.php?project_id=N  → upload files[] (multipart)
 *  DELETE /api/documents.php?project_id=N&document_id=M
 *
 *  Text/code/csv/docx/pdf/xlsx are extracted to content_text. Images are saved to
 *  disk (uploads/) with their mime_type and read back for the AI (multimodal). */
require_once dirname(__FILE__) . '/_lib.php';
require_once dirname(__FILE__) . '/extract.php';

$method = $_SERVER['REQUEST_METHOD'];
$pid = project_id_param();

// mime_type column exists? (graceful before the ALTER)
$hasMime = false;
$mc = $DB->query("SHOW COLUMNS FROM project_documents LIKE 'mime_type'");
if ($mc && $mc->num_rows > 0) { $hasMime = true; }

if ($method === 'GET') {
    // Single document: return its content (text), or stream the raw image (&raw=1).
    $docId = isset($_GET['document_id']) ? (int) $_GET['document_id'] : 0;
    if ($docId > 0) {
        $dcols = $hasMime
            ? "document_id, project_id, file_name, file_path, mime_type, content_text, byte_size, created_at"
            : "document_id, project_id, file_name, file_path, content_text, byte_size, created_at";
        $r = $DB->query("SELECT $dcols FROM project_documents WHERE document_id=" . (int) $docId . " AND project_id=" . (int) $pid);
        if (!$r || $r->num_rows === 0) { fail('Document not found.', 404); }
        $d = $r->fetch_assoc();
        $mime = isset($d['mime_type']) ? $d['mime_type'] : null;

        if (isset($_GET['raw']) && is_inline_doc_mime($mime)) {
            // Stream the image bytes for <img> display (overrides the JSON header).
            if (!empty($d['file_path']) && is_file($d['file_path'])) {
                header('Content-Type: ' . $mime);
                header('Content-Disposition: inline; filename="' . basename($d['file_name']) . '"');
                readfile($d['file_path']);
                exit;
            }
            header('HTTP/1.1 404 Not Found');
            exit;
        }

        $d['document_id'] = (int) $d['document_id'];
        $d['project_id']  = (int) $d['project_id'];
        $d['byte_size']   = (int) $d['byte_size'];
        send_json($d, 200);
    }

    $cols = $hasMime
        ? "document_id, project_id, file_name, file_path, mime_type, byte_size, created_at"
        : "document_id, project_id, file_name, file_path, byte_size, created_at";
    $res = $DB->query("SELECT $cols FROM project_documents WHERE project_id=" . (int) $pid . " ORDER BY created_at ASC");
    $rows = array();
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $r['document_id'] = (int) $r['document_id'];
            $r['project_id']  = (int) $r['project_id'];
            $r['byte_size']   = (int) $r['byte_size'];
            $rows[] = $r;
        }
    }
    send_json($rows, 200);
}

if ($method === 'POST') {
    $chk = $DB->query("SELECT project_id FROM projects WHERE project_id=" . (int) $pid);
    if (!$chk || $chk->num_rows === 0) { fail('Project not found.', 404); }

    if (empty($_FILES) || !isset($_FILES['files'])) {
        fail('No files uploaded (use multipart field name "files[]").', 400);
    }

    $files = $_FILES['files'];
    $names = is_array($files['name']) ? $files['name'] : array($files['name']);
    $tmps  = is_array($files['tmp_name']) ? $files['tmp_name'] : array($files['tmp_name']);
    $sizes = is_array($files['size']) ? $files['size'] : array($files['size']);
    $errs  = is_array($files['error']) ? $files['error'] : array($files['error']);

    $saved = array();
    $skipped = array();
    $seen = array(); // names handled in THIS request (guards against replica lag within a batch)

    for ($i = 0; $i < count($names); $i++) {
        if ($errs[$i] !== UPLOAD_ERR_OK) { $skipped[] = $names[$i] . ' (upload error)'; continue; }
        $orig = $names[$i];

        // Skip duplicates: don't save a file whose name already exists for this project
        // (either already in the DB, or seen earlier in this same upload batch).
        $nameEsc = $DB->real_escape_string($orig);
        $dup = $DB->query("SELECT 1 FROM project_documents WHERE project_id=" . (int) $pid . " AND file_name='" . $nameEsc . "' LIMIT 1");
        if (isset($seen[$orig]) || ($dup && $dup->num_rows > 0)) { $skipped[] = $orig . ' (already uploaded)'; continue; }
        $seen[$orig] = true;
        $size = (int) $sizes[$i];

        $diskMime = inline_doc_mime($orig); // images + PDF → read natively by the AI
        if ($diskMime !== null) {
            // Save the file to disk; the AI reads it from there (multimodal / PDF).
            if ($size > $MAX_IMAGE_BYTES) { $skipped[] = $orig . ' (file too large, max 8 MB)'; continue; }
            $dir = $UPLOAD_DIR . '/' . (int) $pid;
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $safe = preg_replace('/[^\w.\-]+/', '_', $orig);
            $dest = $dir . '/' . time() . '_' . mt_rand(1000, 9999) . '_' . $safe;
            if (!@move_uploaded_file($tmps[$i], $dest)) { $skipped[] = $orig . ' (save failed)'; continue; }
            $contentText = '';
            $filePath = $dest;
            $mime = $diskMime;
        } else {
            // Everything else → extract text (text/code/csv/docx/pdf/xlsx).
            $text = extract_text($tmps[$i], $orig);
            if ($text === null || trim($text) === '') { $skipped[] = $orig; continue; }
            $contentText = $text;
            $filePath = $orig;
            $mime = null;
        }

        if ($hasMime) {
            $stmt = $DB->prepare("INSERT INTO project_documents (project_id, file_name, file_path, content_text, mime_type, byte_size) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('issssi', $pid, $orig, $filePath, $contentText, $mime, $size);
        } else {
            $stmt = $DB->prepare("INSERT INTO project_documents (project_id, file_name, file_path, content_text, byte_size) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('isssi', $pid, $orig, $filePath, $contentText, $size);
        }
        $stmt->execute();
        $stmt->close();
        $saved[] = $orig;
    }

    send_json(array('saved' => $saved, 'skipped' => $skipped), 201);
}

if ($method === 'DELETE') {
    $docId = isset($_GET['document_id']) ? (int) $_GET['document_id'] : 0;
    if ($docId <= 0) { fail('document_id is required.', 400); }

    // Grab the file path first so we can remove an on-disk image after deleting.
    $fpath = null;
    $fr = $DB->query("SELECT file_path FROM project_documents WHERE document_id=" . (int) $docId . " AND project_id=" . (int) $pid);
    if ($fr && $frow = $fr->fetch_assoc()) { $fpath = $frow['file_path']; }

    // Scoped to the project so a document can only be deleted via its own project.
    $stmt = $DB->prepare("DELETE FROM project_documents WHERE document_id=? AND project_id=?");
    $stmt->bind_param('ii', $docId, $pid);
    $stmt->execute();
    $aff = $stmt->affected_rows;
    $stmt->close();
    if ($aff === 0) { fail('Document not found.', 404); }

    // Remove the stored image file (only paths under the uploads dir).
    if ($fpath && strpos($fpath, $UPLOAD_DIR) === 0 && is_file($fpath)) { @unlink($fpath); }

    send_json(array('success' => true, 'document_id' => $docId), 200);
}

fail('Method not allowed.', 405);
?>
