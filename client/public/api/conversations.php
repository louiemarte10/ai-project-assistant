<?php
/** GET    /api/conversations.php?project_id=N                    → list this agent's threads
 *  DELETE /api/conversations.php?project_id=N&conversation_id=M  → delete a thread + its messages */
require_once dirname(__FILE__) . '/_lib.php';

$method = $_SERVER['REQUEST_METHOD'];
$pid = project_id_param();
$uid = current_user_id();

// Conversations not enabled yet (DDL not applied) → behave harmlessly.
if (!chat_conv_enabled($DB)) {
    if ($method === 'GET') { send_json(array(), 200); }
    send_json(array('success' => true), 200);
}

if ($method === 'GET') {
    $res = $DB->query(
        "SELECT conversation_id, title, created_at, updated_at FROM chat_conversations
         WHERE project_id=" . (int) $pid . " AND user_id=" . (int) $uid . "
         ORDER BY COALESCE(updated_at, created_at) DESC, conversation_id DESC"
    );
    $rows = array();
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $r['conversation_id'] = (int) $r['conversation_id'];
            $rows[] = $r;
        }
    }
    send_json($rows, 200);
}

if ($method === 'DELETE') {
    $cid = isset($_GET['conversation_id']) ? (int) $_GET['conversation_id'] : 0;
    if ($cid <= 0) { fail('conversation_id is required.', 400); }

    // Remove any attached images on disk (uploads/chat/{pid}/{message_id}.*) for
    // this thread's messages before the rows go away.
    $mr = $DB->query("SELECT message_id FROM chat_logs WHERE conversation_id=" . (int) $cid . " AND project_id=" . (int) $pid);
    if ($mr) {
        $adir = $UPLOAD_DIR . '/chat/' . (int) $pid;
        while ($m = $mr->fetch_assoc()) {
            $mid = (int) $m['message_id'];
            $g = array_merge((array) @glob($adir . '/' . $mid . '.*'), (array) @glob($adir . '/' . $mid . '_*.*'));
            foreach ($g as $f) { if (is_file($f)) { @unlink($f); } }
        }
    }

    // Remove the thread's messages, then the thread (scoped to project + agent).
    $DB->query("DELETE FROM chat_logs WHERE conversation_id=" . (int) $cid . " AND project_id=" . (int) $pid);
    $stmt = $DB->prepare("DELETE FROM chat_conversations WHERE conversation_id=? AND project_id=? AND user_id=?");
    $stmt->bind_param('iii', $cid, $pid, $uid);
    $stmt->execute();
    $aff = $stmt->affected_rows;
    $stmt->close();
    if ($aff === 0) { fail('Conversation not found.', 404); }
    send_json(array('success' => true, 'conversation_id' => $cid), 200);
}

fail('Method not allowed.', 405);
?>
