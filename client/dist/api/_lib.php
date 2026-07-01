<?php
/**
 * Shared bootstrap for all Project Turnover API endpoints.
 * PHP 5.3 compatible.
 */

// Keep PHP 5.3 deprecation warnings out of the JSON response body.
ini_set('display_errors', '0');
error_reporting(E_ERROR | E_PARSE);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
// Never cache API responses — keeps auth/session state from being served stale
// (e.g. after logout, or from the back/forward cache).
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once dirname(__FILE__) . '/config.php';

// Ensure the PHP session is available (px_login stores the logged-in agent in
// $_SESSION; session_id() is the per-message token). pipeline-x.php usually
// starts it already — guard so we don't double-start.
if (session_id() === '') { @session_start(); }

/** Parse a JSON request body into an associative array (empty array if none). */
function body_json() {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    return is_array($j) ? $j : array();
}

/** Emit JSON and stop. */
function send_json($data, $status) {
    if ($status === null) { $status = 200; }
    if ($status !== 200) {
        header('HTTP/1.1 ' . $status);
    }
    echo json_encode($data);
    exit;
}

/** Emit an error JSON and stop. */
function fail($msg, $status) {
    if ($status === null) { $status = 400; }
    send_json(array('error' => $msg), $status);
}

/** True if the DB supports chat conversations (chat_conversations table +
 *  chat_logs.conversation_id column both exist). Lets the code degrade to the
 *  old single-thread behavior before the DDL is applied. */
function chat_conv_enabled($DB) {
    static $cached = null;
    if ($cached !== null) { return $cached; }
    $col = $DB->query("SHOW COLUMNS FROM chat_logs LIKE 'conversation_id'");
    $tbl = $DB->query("SHOW TABLES LIKE 'chat_conversations'");
    $cached = ($col && $col->num_rows > 0 && $tbl && $tbl->num_rows > 0);
    return $cached;
}

/** The logged-in agent's user_id (0 if not logged in). */
function current_user_id() {
    $info = isset($_SESSION['px_login_info']['info']) ? $_SESSION['px_login_info']['info'] : null;
    return ($info && isset($info['user_id'])) ? (int) $info['user_id'] : 0;
}

/** True only for the account allowed to see the AI cost/usage dashboard. */
function is_dashboard_user() {
    if (current_user_id() === 1252979) { return true; }
    $info = isset($_SESSION['px_login_info']['info']) ? $_SESSION['px_login_info']['info'] : null;
    $name = ($info && isset($info['user_name'])) ? strtolower($info['user_name']) : '';
    return strpos($name, 'doromal') !== false;
}

/** Read a positive integer project id from ?project_id= or ?id=. */
function project_id_param() {
    $id = 0;
    if (isset($_GET['project_id'])) { $id = (int) $_GET['project_id']; }
    else if (isset($_GET['id'])) { $id = (int) $_GET['id']; }
    if ($id <= 0) { fail('Invalid project id.', 400); }
    return $id;
}
?>
