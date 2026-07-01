<?php
/**
 * Per-user AI API keys (table: api_key_by_user). Each logged-in user stores their
 * own Gemini key; the chat uses that user's key. PHP 5.3 compatible.
 * Degrades gracefully if the table hasn't been created yet.
 */

function apikey_table_exists($DB) {
    static $cached = null;
    if ($cached !== null) { return $cached; }
    $r = $DB->query("SHOW TABLES LIKE 'api_key_by_user'");
    $cached = ($r && $r->num_rows > 0);
    return $cached;
}

/** All active, non-expired key rows for a user (oldest first = primary). */
function apikey_get_keys($DB, $uid) {
    if (!apikey_table_exists($DB) || (int) $uid <= 0) { return array(); }
    $uid = (int) $uid;
    $rows = array();
    $res = $DB->query(
        "SELECT id, user_id, ai_model, api_key, create_date, expiration
         FROM api_key_by_user
         WHERE user_id=" . $uid . " AND x='active'
           AND (expiration IS NULL OR expiration > NOW())
         ORDER BY id ASC"
    );
    if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }
    return $rows;
}

/** The active, non-expired key row for a user, or null. */
function apikey_get_active($DB, $uid) {
    if (!apikey_table_exists($DB) || (int) $uid <= 0) { return null; }
    $uid = (int) $uid;
    $res = $DB->query(
        "SELECT id, user_id, ai_model, api_key, create_date, expiration
         FROM api_key_by_user
         WHERE user_id=" . $uid . " AND x='active'
           AND (expiration IS NULL OR expiration > NOW())
         ORDER BY id DESC LIMIT 1"
    );
    if ($res && $row = $res->fetch_assoc()) { return $row; }
    return null;
}

/** Mask a key for display (keep first 6 + last 4). */
function apikey_mask($key) {
    $k = (string) $key;
    $n = strlen($k);
    if ($n <= 12) { return str_repeat('*', max(4, $n)); }
    return substr($k, 0, 6) . '…' . substr($k, -4);
}

/** Mark all of a user's active keys as deleted (so there's one active at a time). */
function apikey_deactivate_all($DB, $uid) {
    if (!apikey_table_exists($DB)) { return; }
    $stmt = $DB->prepare("UPDATE api_key_by_user SET x='deleted' WHERE user_id=? AND x='active'");
    $u = (int) $uid;
    $stmt->bind_param('i', $u);
    $stmt->execute();
    $stmt->close();
}
?>
