<?php
/** GET  /api/usage.php?days=28  → AI token-usage + estimated cost + budget + series.
 *  POST /api/usage.php { "reset": true }  → reset the usage counters (cutoff = now). */
require_once dirname(__FILE__) . '/_lib.php';
require_once dirname(__FILE__) . '/budget.php';

$method = $_SERVER['REQUEST_METHOD'];

// The cost/usage dashboard (and its reset/backfill actions) are restricted to the
// allowed account. Other users can still chat — they just can't see the figures.
$isDash = is_dashboard_user();

if ($method === 'POST') {
    if (!$isDash) { fail('Not authorized.', 403); }
    $in = body_json();
    if (!empty($in['backfill'])) {
        $n = ai_usage_backfill($DB);
        send_json(array('success' => true, 'imported' => $n), 200);
    }
    if (!empty($in['reset'])) {
        $at = ai_usage_reset_set();
        send_json(array('success' => true, 'reset_at' => $at), 200);
    }
    fail('Nothing to do.', 400);
}

if ($method !== 'GET') { fail('Method not allowed.', 405); }

$range = isset($_GET['range']) ? preg_replace('/[^0-9a-z]/', '', $_GET['range']) : '28d';
$projectId = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;

// Admin (dashboard user) sees ALL usage, or one user via ?user_id=N; everyone
// else is locked to their own usage.
$reqUid = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$forUid = $isDash ? ($reqUid > 0 ? $reqUid : null) : current_user_id();
$breakdown = isset($_GET['breakdown']) && in_array($_GET['breakdown'], array('model', 'user', 'project'), true) ? $_GET['breakdown'] : 'model';
$status = ai_budget_status($DB, $range, $projectId, $forUid, $breakdown);
$status['user_id_filter'] = $forUid === null ? 0 : (int) $forUid;
$status['is_admin'] = $isDash;
$status['scope'] = $isDash ? 'all' : 'self';
send_json($status, 200);
?>
