<?php
/** GET /api/session.php → current logged-in agent (from px_login session).
 *  Returns JSON only (never renders a login form), so the SPA can decide what
 *  to show. user_id comes from callbox_pipeline2.users (via the session), the
 *  name from callbox_pipeline2.employees. */
require_once dirname(__FILE__) . '/_lib.php';

$info = isset($_SESSION['px_login_info']['info']) ? $_SESSION['px_login_info']['info'] : null;
$userId = ($info && isset($info['user_id'])) ? (int) $info['user_id'] : 0;

if ($userId <= 0) {
    // Not logged in — tell the SPA where to send the user to authenticate.
    send_json(array('logged_in' => false, 'login_url' => 'login.php'), 200);
}

$userName = (isset($info['user_name']) && $info['user_name'] !== '') ? $info['user_name'] : null;
$fullName = $userName ? $userName : ('User ' . $userId);

// Name from employees (cross-DB query on the same server).
$er = $DB->query("SELECT first_name, last_name, alias FROM callbox_pipeline2.employees WHERE user_id=" . (int) $userId . " LIMIT 1");
if ($er && $e = $er->fetch_assoc()) {
    $nm = trim($e['first_name'] . ' ' . $e['last_name']);
    if ($nm !== '') { $fullName = $nm; }
    else if (!empty($e['alias'])) { $fullName = $e['alias']; }
}

send_json(array(
    'logged_in' => true,
    'user_id' => $userId,
    'user_name' => $userName,
    'name' => $fullName,
    'session_token' => session_id(),
), 200);
?>
