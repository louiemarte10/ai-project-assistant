<?php
/** GET    /api/apikey.php          → current user's key status (+ ?all=1 for admin)
 *  POST   /api/apikey.php { api_key, ai_model?, expiration? } → validate + save
 *  DELETE /api/apikey.php          → deactivate current user's key */
require_once dirname(__FILE__) . '/_lib.php';
require_once dirname(__FILE__) . '/apikeys.php';
require_once dirname(__FILE__) . '/gemini.php';

$method = $_SERVER['REQUEST_METHOD'];
$uid = current_user_id();
if ($uid <= 0) { fail('Not logged in.', 401); }

if (!apikey_table_exists($DB)) {
    // Table not created yet → report unavailable so the UI falls back gracefully.
    if ($method === 'GET') { send_json(array('available' => false, 'has_key' => false), 200); }
    fail('The api_key_by_user table has not been created yet.', 503);
}

if ($method === 'GET') {
    $mineRows = apikey_get_keys($DB, $uid);
    $mine = array();
    foreach ($mineRows as $r) {
        $mine[] = array(
            'id' => (int) $r['id'],
            'ai_model' => $r['ai_model'],
            'key_masked' => apikey_mask($r['api_key']),
            'create_date' => $r['create_date'],
            'expiration' => $r['expiration'],
        );
    }
    $out = array(
        'available'   => true,
        'has_key'     => count($mine) > 0,
        'mine'        => $mine,
        'is_admin'    => is_dashboard_user(),
    );
    // Admin: list every user's keys (masked) with their name.
    if (is_dashboard_user() && isset($_GET['all'])) {
        $rows = array();
        $res = $DB->query(
            "SELECT k.id, k.user_id, k.ai_model, k.api_key, k.create_date, k.expiration, k.x,
                    e.first_name, e.last_name
             FROM api_key_by_user k
             LEFT JOIN callbox_pipeline2.employees e ON e.user_id = k.user_id
             ORDER BY k.x ASC, k.id DESC"
        );
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $nm = trim($r['first_name'] . ' ' . $r['last_name']);
                $rows[] = array(
                    'id' => (int) $r['id'],
                    'user_id' => (int) $r['user_id'],
                    'name' => $nm !== '' ? $nm : ('User ' . (int) $r['user_id']),
                    'ai_model' => $r['ai_model'],
                    'key_masked' => apikey_mask($r['api_key']),
                    'create_date' => $r['create_date'],
                    'expiration' => $r['expiration'],
                    'active' => ($r['x'] === 'active'),
                );
            }
        }
        $out['all'] = $rows;
    }
    send_json($out, 200);
}

if ($method === 'POST') {
    $in = body_json();

    // Model-only update for an existing key (switch model without re-pasting).
    if (isset($in['id']) && (int) $in['id'] > 0 && isset($in['ai_model']) && !isset($in['api_key'])) {
        $allowed = array('gemini-2.5-flash', 'gemini-2.5-flash-lite');
        $m = in_array(trim($in['ai_model']), $allowed) ? trim($in['ai_model']) : 'gemini-2.5-flash-lite';
        $kid = (int) $in['id'];
        $stmt = $DB->prepare("UPDATE api_key_by_user SET ai_model=? WHERE id=? AND user_id=? AND x='active'");
        $stmt->bind_param('sii', $m, $kid, $uid);
        $stmt->execute();
        $stmt->close();
        send_json(array('success' => true), 200);
    }

    $key = isset($in['api_key']) ? trim($in['api_key']) : '';
    $allowedModels = array('gemini-2.5-flash', 'gemini-2.5-flash-lite');
    $model = isset($in['ai_model']) && in_array(trim($in['ai_model']), $allowedModels) ? trim($in['ai_model']) : 'gemini-2.5-flash-lite';
    $expiration = (isset($in['expiration']) && preg_match('/^\d{4}-\d{2}-\d{2}/', $in['expiration'])) ? substr($in['expiration'], 0, 10) . ' 23:59:59' : null;
    if ($key === '') { fail('API key is required.', 400); }

    // Validate the key with a tiny Gemini call before saving it. Only reject on a
    // genuine auth/invalid-key error — a transient 429/503 ("busy"/"limit") shouldn't
    // block a valid key from being saved.
    $test = gemini_generate($key, $model, 'ping', false);
    if (empty($test['ok'])) {
        $code = isset($test['code']) ? (int) $test['code'] : 0;
        $msg = isset($test['error']) ? $test['error'] : 'unknown error';
        $invalid = ($code === 400 || $code === 401 || $code === 403)
            || (stripos($msg, 'api key not valid') !== false)
            || (stripos($msg, 'API_KEY_INVALID') !== false)
            || (stripos($msg, 'permission') !== false);
        if ($invalid) {
            fail('That API key was rejected by Gemini: ' . $msg, 400);
        }
        // Otherwise (busy / rate limited / network) accept it; it'll work for chat.
    }

    // Multiple keys allowed (for fallback). Dedupe: if this exact key is already
    // active, just update its model instead of inserting a duplicate.
    $keyEsc = $DB->real_escape_string($key);
    $dupe = $DB->query("SELECT id FROM api_key_by_user WHERE user_id=" . (int) $uid . " AND x='active' AND api_key='" . $keyEsc . "' LIMIT 1");
    if ($dupe && $dupe->num_rows > 0) {
        $row = $dupe->fetch_assoc();
        $upd = $DB->prepare("UPDATE api_key_by_user SET ai_model=?, expiration=? WHERE id=?");
        $rid = (int) $row['id'];
        $upd->bind_param('ssi', $model, $expiration, $rid);
        $upd->execute();
        $upd->close();
    } else {
        $stmt = $DB->prepare("INSERT INTO api_key_by_user (user_id, ai_model, api_key, expiration) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('isss', $uid, $model, $key, $expiration);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) { fail('Failed to save the API key.', 500); }
    }

    send_json(array('success' => true, 'has_key' => true), 200);
}

if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id > 0) {
        $stmt = $DB->prepare("UPDATE api_key_by_user SET x='deleted' WHERE id=? AND user_id=?");
        $stmt->bind_param('ii', $id, $uid);
        $stmt->execute();
        $stmt->close();
    } else {
        apikey_deactivate_all($DB, $uid);
    }
    send_json(array('success' => true), 200);
}

fail('Method not allowed.', 405);
?>
