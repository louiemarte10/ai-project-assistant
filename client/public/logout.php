<?php
/**
 * Logout for the Project Turnover Assistant. Mirrors px_login::logoff() (clears
 * $_SESSION + the pipelinex_auth_* cookies), then redirects back to the app —
 * the SPA's session check then shows the login screen.
 *
 * Note: like the framework's own logoff, this clears the shared Pipeline session,
 * so it signs the agent out of the portal session (by design).
 */
ini_set('display_errors', '0');
error_reporting(E_ERROR | E_PARSE);

require "{$_SERVER['DOCUMENT_ROOT']}/config/pipeline-x.php";

if (session_id() === '') { @session_start(); }

$lastUser = isset($_SESSION['px_login_info']['info']['user_name']) ? $_SESSION['px_login_info']['info']['user_name'] : '';
$_SESSION = array();
if ($lastUser !== '') { $_SESSION['pipelinex_auth_lastuser'] = $lastUser; }

setcookie('pipelinex_auth_name', '', time() - 3600, '/');
setcookie('pipelinex_auth_hash', '', time() - 3600, '/');

header('Location: /playground/doromal/projects-assistant-tool/');
exit;
?>
