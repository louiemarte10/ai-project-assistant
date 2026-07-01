<?php
/**
 * AI usage metering + budget. PHP 5.3 compatible.
 *
 * We can't read Anthropic's dollar balance with a standard key, so we estimate
 * spend from the per-call token counts stored in usage_meta_data (chat_logs +
 * project_metadata) and compare it to a configurable monthly budget. Once the
 * estimated month spend reaches $AI_BUDGET_LOCK_PCT of the budget, the chat is
 * disabled (both the UI and a server-side guard in chat.php).
 */

/** Normalize one usage record (Claude or Gemini shape) to token counts. */
function ai_usage_norm($u) {
    if (!is_array($u)) { return null; }
    $in = 0; $out = 0; $cw = 0; $cr = 0;
    if (isset($u['input_tokens']) || isset($u['output_tokens'])) {
        // Anthropic Claude
        $in = isset($u['input_tokens']) ? (int) $u['input_tokens'] : 0;
        $out = isset($u['output_tokens']) ? (int) $u['output_tokens'] : 0;
        $cw = isset($u['cache_creation_input_tokens']) ? (int) $u['cache_creation_input_tokens'] : 0;
        $cr = isset($u['cache_read_input_tokens']) ? (int) $u['cache_read_input_tokens'] : 0;
    } else if (isset($u['promptTokenCount']) || isset($u['totalTokenCount'])) {
        // Google Gemini
        $in = isset($u['promptTokenCount']) ? (int) $u['promptTokenCount'] : 0;
        $out = isset($u['candidatesTokenCount']) ? (int) $u['candidatesTokenCount'] : 0;
    }
    return array('input' => $in, 'output' => $out, 'cache_write' => $cw, 'cache_read' => $cr);
}

/** Per-MTok rate set for the active provider (falls back to Claude rates). */
function ai_active_pricing() {
    global $AI_PRICING, $AI_PROVIDER;
    if (isset($AI_PRICING[$AI_PROVIDER])) { return $AI_PRICING[$AI_PROVIDER]; }
    return isset($AI_PRICING['claude']) ? $AI_PRICING['claude'] : array('input' => 0, 'output' => 0, 'cache_write' => 0, 'cache_read' => 0);
}

/** Estimated USD cost for a token bucket, using the active provider's rates. */
function ai_cost_usd($b) {
    $p = ai_active_pricing();
    return ($b['input']       / 1000000.0) * $p['input']
         + ($b['output']      / 1000000.0) * $p['output']
         + ($b['cache_write'] / 1000000.0) * $p['cache_write']
         + ($b['cache_read']  / 1000000.0) * $p['cache_read'];
}

function ai_bucket_new() { return array('input' => 0, 'output' => 0, 'cache_write' => 0, 'cache_read' => 0, 'messages' => 0); }
function ai_bucket_add(&$bucket, $n) {
    $bucket['input'] += $n['input']; $bucket['output'] += $n['output'];
    $bucket['cache_write'] += $n['cache_write']; $bucket['cache_read'] += $n['cache_read'];
    $bucket['messages'] += 1;
}

/** True if the column exists on the table (graceful before the ALTERs). */
function ai_col_exists($DB, $table, $col) {
    $r = $DB->query("SHOW COLUMNS FROM " . $table . " LIKE '" . $DB->real_escape_string($col) . "'");
    return ($r && $r->num_rows > 0);
}

// ── Append-only usage ledger ─────────────────────────────────────────────────
// Usage is logged to a file (not just chat_logs) so it PERSISTS on the dashboard
// even after a conversation or project is deleted. No DDL needed (app_pipe can't
// ALTER), and the uploads/ dir is writable + excluded from deploys.
function ai_usage_ledger_file() { global $UPLOAD_DIR; return $UPLOAD_DIR . '/usage_ledger.jsonl'; }

/** Append a prepared record (array) as one JSON line to the ledger. */
function ai_usage_append($rec) {
    $f = ai_usage_ledger_file();
    $dir = dirname($f);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    @file_put_contents($f, json_encode($rec) . "\n", FILE_APPEND | LOCK_EX);
}

/** Append one AI call's token usage to the ledger. $usage is the raw provider
 *  usage object; $source is 'chat' or 'summary'. */
function ai_usage_log($pid, $usage, $source) {
    global $AI_PROVIDER;
    $n = ai_usage_norm($usage);
    if (!$n) { return; }
    $label = ($AI_PROVIDER === 'gemini') ? 'Gemini 2.5 Flash' : 'Claude';
    ai_usage_append(array(
        'ts' => date('Y-m-d H:i:s'), 'pid' => (int) $pid, 'uid' => current_user_id(), 'model' => $label, 'source' => $source,
        'input' => $n['input'], 'output' => $n['output'], 'cache_write' => $n['cache_write'], 'cache_read' => $n['cache_read'],
    ));
}

/** One-time backfill: rebuild the ledger from existing chat_logs + project_metadata
 *  usage rows (with their original timestamps), then clear the reset cutoff so the
 *  historical usage is visible. Returns the number of rows imported. */
function ai_usage_backfill($DB) {
    @file_put_contents(ai_usage_ledger_file(), ''); // start clean (drops test junk)
    $count = 0;
    if (ai_col_exists($DB, 'chat_logs', 'usage_meta_data')) {
        $hasUid = ai_col_exists($DB, 'chat_logs', 'user_id');
        $sel = $hasUid ? 'usage_meta_data, project_id AS pid, user_id AS uid, timestamp AS ts' : 'usage_meta_data, project_id AS pid, timestamp AS ts';
        $res = $DB->query("SELECT $sel FROM chat_logs WHERE usage_meta_data IS NOT NULL");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $u = json_decode($r['usage_meta_data'], true);
                $n = ai_usage_norm($u);
                if (!$n) { continue; }
                ai_usage_append(array(
                    'ts' => (string) $r['ts'], 'pid' => (int) $r['pid'], 'uid' => isset($r['uid']) ? (int) $r['uid'] : 0, 'model' => ai_usage_model_label($u), 'source' => 'chat',
                    'input' => $n['input'], 'output' => $n['output'], 'cache_write' => $n['cache_write'], 'cache_read' => $n['cache_read'],
                ));
                $count++;
            }
        }
    }
    if (ai_col_exists($DB, 'project_metadata', 'usage_meta_data')) {
        $dateCol = ai_col_exists($DB, 'project_metadata', 'generated_at') ? 'generated_at' : 'NULL';
        $res = $DB->query("SELECT usage_meta_data, project_id AS pid, " . $dateCol . " AS ts FROM project_metadata WHERE usage_meta_data IS NOT NULL");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                if ($r['ts'] === null) { continue; }
                $u = json_decode($r['usage_meta_data'], true);
                $n = ai_usage_norm($u);
                if (!$n) { continue; }
                ai_usage_append(array(
                    'ts' => (string) $r['ts'], 'pid' => (int) $r['pid'], 'model' => ai_usage_model_label($u), 'source' => 'summary',
                    'input' => $n['input'], 'output' => $n['output'], 'cache_write' => $n['cache_write'], 'cache_read' => $n['cache_read'],
                ));
                $count++;
            }
        }
    }
    @unlink(ai_usage_reset_file()); // show all history (clear the reset cutoff)
    return $count;
}

// ── API error ledger (append-only file, account-wide) ───────────────────────
function ai_error_ledger_file() { global $UPLOAD_DIR; return $UPLOAD_DIR . '/usage_errors.jsonl'; }

/** Human label for an HTTP error code (matches the AI Studio style). */
function ai_error_label($code) {
    switch ((int) $code) {
        case 429: return '429 TooManyRequests';
        case 503: return '503 ServiceUnavailable';
        case 500: return '500 InternalError';
        case 529: return '529 Overloaded';
        case 400: return '400 BadRequest';
        case 401: case 403: return 'Auth error';
        case 0:   return 'Network error';
        default:  return 'HTTP ' . (int) $code;
    }
}

/** Append one API error to the error ledger. */
function ai_error_log($code) {
    $f = ai_error_ledger_file();
    $dir = dirname($f);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $rec = array('ts' => date('Y-m-d H:i:s'), 'uid' => current_user_id(), 'code' => (int) $code, 'label' => ai_error_label($code));
    @file_put_contents($f, json_encode($rec) . "\n", FILE_APPEND | LOCK_EX);
}

/** Log every failed attempt recorded by a provider call result. */
function ai_log_attempt_errors($result) {
    if (isset($result['attempt_errors']) && is_array($result['attempt_errors'])) {
        foreach ($result['attempt_errors'] as $c) { ai_error_log($c); }
    }
}

// ── Usage reset marker (a writable file under uploads/, since we can't add DDL) ──
function ai_usage_reset_file() { global $UPLOAD_DIR; return $UPLOAD_DIR . '/usage_reset.txt'; }
function ai_usage_reset_get() {
    $f = ai_usage_reset_file();
    if (is_file($f)) { $s = trim((string) @file_get_contents($f)); return $s !== '' ? $s : null; }
    return null;
}
function ai_usage_reset_set() {
    $f = ai_usage_reset_file();
    $dir = dirname($f);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $now = date('Y-m-d H:i:s');
    @file_put_contents($f, $now);
    return $now;
}

/** Human model label inferred from the usage record's shape. */
function ai_usage_model_label($u) {
    if (is_array($u) && (isset($u['input_tokens']) || isset($u['output_tokens']))) { return 'Claude'; }
    return 'Gemini 2.5 Flash';
}

/** All usage rows from the persistent ledger: {dt, date, model, pid, n:{...}}.
 *  Read from the ledger file (not chat_logs) so deletes don't drop usage. */
function ai_usage_records() {
    $rows = array();
    $f = ai_usage_ledger_file();
    if (!is_file($f)) { return $rows; }
    $fh = @fopen($f, 'r');
    if (!$fh) { return $rows; }
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') { continue; }
        $r = json_decode($line, true);
        if (!is_array($r) || !isset($r['ts'])) { continue; }
        $rows[] = array(
            'dt'   => (string) $r['ts'],
            'date' => substr((string) $r['ts'], 0, 10),
            'model' => isset($r['model']) ? $r['model'] : 'AI',
            'pid'  => isset($r['pid']) ? (int) $r['pid'] : 0,
            'uid'  => isset($r['uid']) ? (int) $r['uid'] : 0,
            'n'    => array(
                'input'       => isset($r['input']) ? (int) $r['input'] : 0,
                'output'      => isset($r['output']) ? (int) $r['output'] : 0,
                'cache_write' => isset($r['cache_write']) ? (int) $r['cache_write'] : 0,
                'cache_read'  => isset($r['cache_read']) ? (int) $r['cache_read'] : 0,
            ),
        );
    }
    fclose($fh);
    return $rows;
}

/** Token totals within the selected range window (after $since), optionally
 *  scoped to one project. Used so the stat cards reflect the Time Range filter. */
function ai_range_totals($records, $range, $since, $projectId) {
    $axis = ai_time_axis($range);
    $b = ai_bucket_new();
    foreach ($records as $r) {
        if ($since !== null && $r['dt'] < $since) { continue; }
        if ($projectId > 0 && $r['pid'] !== $projectId) { continue; }
        if (ai_axis_index($axis, $r['dt']) < 0) { continue; }
        ai_bucket_add($b, $r['n']);
    }
    return ai_bucket_out($b);
}

/** Per-project token + cost breakdown WITHIN the selected range window (after
 *  $since), newest spend first. */
function ai_usage_by_project($DB, $records, $since, $range = null) {
    $names = array();
    $pr = $DB->query("SELECT project_id, project_name FROM projects");
    if ($pr) { while ($p = $pr->fetch_assoc()) { $names[(int) $p['project_id']] = $p['project_name']; } }

    $axis = ($range !== null) ? ai_time_axis($range) : null;
    $by = array();
    foreach ($records as $r) {
        if ($since !== null && $r['dt'] < $since) { continue; }
        if ($axis !== null && ai_axis_index($axis, $r['dt']) < 0) { continue; }
        $pid = $r['pid'];
        if (!isset($by[$pid])) { $by[$pid] = ai_bucket_new(); }
        ai_bucket_add($by[$pid], $r['n']);
    }
    $out = array();
    foreach ($by as $pid => $b) {
        $row = ai_bucket_out($b);
        $row['project_id'] = (int) $pid;
        $row['project_name'] = isset($names[$pid]) ? $names[$pid] : ('Project #' . $pid . ' (deleted)');
        $out[] = $row;
    }
    usort($out, 'ai_byproj_cmp');
    return $out;
}
function ai_byproj_cmp($a, $b) {
    if ($a['cost_usd'] === $b['cost_usd']) { return $b['total_tokens'] - $a['total_tokens']; }
    return ($b['cost_usd'] < $a['cost_usd']) ? -1 : 1;
}

/** Seconds to add to a stored (UTC) timestamp to get local display time. */
function ai_tz_offset() {
    global $AI_TZ_OFFSET_HOURS;
    return (is_numeric($AI_TZ_OFFSET_HOURS) ? (float) $AI_TZ_OFFSET_HOURS : 8) * 3600;
}

/** Month + all-time buckets from records, counting only rows at/after $since.
 *  $month is the local (UTC+8) 'YYYY-MM'. */
function ai_usage_aggregate($records, $month, $since) {
    $off = ai_tz_offset();
    $monthB = ai_bucket_new();
    $allB   = ai_bucket_new();
    foreach ($records as $r) {
        if ($since !== null && $r['dt'] < $since) { continue; }
        ai_bucket_add($allB, $r['n']);
        $ym = gmdate('Y-m', strtotime($r['dt'] . ' UTC') + $off);
        if ($ym === $month) { ai_bucket_add($monthB, $r['n']); }
    }
    return array('month' => $monthB, 'all_time' => $allB);
}

/** Range spec: array(window_seconds, bucket_seconds, mode 'day'|'time'). */
function ai_range_spec($range) {
    switch ($range) {
        case '1h': return array(3600, 300, 'time');         // last hour, 5-min buckets
        case '5h': return array(5 * 3600, 1800, 'time');    // last 5h, 30-min buckets
        case '1d': return array(86400, 3600, 'time');       // last 24h, hourly buckets
        case '7d': return array(7 * 86400, 86400, 'day');
        case '90d': return array(90 * 86400, 86400, 'day');
        case '28d':
        default:   return array(28 * 86400, 86400, 'day');
    }
}

/** Build the display-ready time axis for a range (shared by usage + error series). */
function ai_time_axis($range) {
    list($window, $bucket, $mode) = ai_range_spec($range);
    $off = ai_tz_offset();
    $now = time();
    $start = $now - $window;
    $labels = array(); $full = array(); $keyToIdx = array();
    if ($mode === 'day') {
        $days = (int) round($window / 86400);
        for ($i = $days - 1; $i >= 0; $i--) {
            $ts = ($now + $off) - $i * 86400;
            $keyToIdx[gmdate('Y-m-d', $ts)] = count($labels);
            $labels[] = gmdate('M j', $ts);
            $full[]   = gmdate('M j, Y', $ts);
        }
    } else {
        $B = (int) ceil($window / $bucket);
        for ($b = 0; $b < $B; $b++) {
            $bs = $start + $b * $bucket;
            $labels[] = gmdate('G:i', $bs + $off);
            $full[]   = gmdate('M j, g:i A', $bs + $off);
        }
    }
    return array('labels' => $labels, 'full' => $full, 'keyToIdx' => $keyToIdx, 'mode' => $mode, 'bucket' => $bucket, 'start' => $start, 'now' => $now, 'off' => $off, 'count' => count($labels));
}

/** Index of a stored (UTC) timestamp on the axis, or -1 if outside the range. */
function ai_axis_index($axis, $dt) {
    $t = strtotime($dt . ' UTC');
    if ($t === false) { return -1; }
    if ($axis['mode'] === 'day') {
        $key = gmdate('Y-m-d', $t + $axis['off']);
        return isset($axis['keyToIdx'][$key]) ? $axis['keyToIdx'][$key] : -1;
    }
    if ($t < $axis['start'] || $t > $axis['now']) { return -1; }
    $i = (int) floor(($t - $axis['start']) / $axis['bucket']);
    if ($i < 0) { $i = 0; } if ($i >= $axis['count']) { $i = $axis['count'] - 1; }
    return $i;
}

/** Maps of project_id→name and user_id→name for the given records (for breakdowns). */
function ai_series_name_maps($DB, $records) {
    $pids = array(); $uids = array();
    foreach ($records as $r) { $pids[(int) $r['pid']] = true; $uids[(int) $r['uid']] = true; }
    $projNames = array();
    $pr = $DB->query("SELECT project_id, project_name FROM projects");
    if ($pr) { while ($p = $pr->fetch_assoc()) { $projNames[(int) $p['project_id']] = $p['project_name']; } }
    $userNames = array();
    $ids = array_keys($uids);
    $ids = array_filter($ids, 'is_numeric');
    if (count($ids)) {
        $idList = implode(',', array_map('intval', $ids));
        $er = $DB->query("SELECT user_id, first_name, last_name FROM callbox_pipeline2.employees WHERE user_id IN (" . $idList . ")");
        if ($er) { while ($e = $er->fetch_assoc()) { $userNames[(int) $e['user_id']] = trim($e['first_name'] . ' ' . $e['last_name']); } }
    }
    return array('proj' => $projNames, 'user' => $userNames);
}

/** Series key for a record under the chosen breakdown dimension. */
function ai_series_key($r, $dimension, $maps) {
    if ($dimension === 'user') {
        $uid = (int) $r['uid'];
        return ($uid > 0 && isset($maps['user'][$uid]) && $maps['user'][$uid] !== '') ? $maps['user'][$uid] : ('User ' . $uid);
    }
    if ($dimension === 'project') {
        $pid = (int) $r['pid'];
        return isset($maps['proj'][$pid]) ? $maps['proj'][$pid] : ('Project #' . $pid);
    }
    return $r['model'];
}

/** Usage time series for a range, grouped by $dimension (model|user|project). */
function ai_usage_series($DB, $records, $range, $since, $dimension = 'model') {
    $axis = ai_time_axis($range);
    $count = $axis['count'];
    $maps = ($dimension === 'model') ? array('proj' => array(), 'user' => array()) : ai_series_name_maps($DB, $records);
    $requests = array_fill(0, $count, 0);
    $by = array();
    foreach ($records as $r) {
        if ($since !== null && $r['dt'] < $since) { continue; }
        $i = ai_axis_index($axis, $r['dt']);
        if ($i < 0) { continue; }
        $requests[$i] += 1;
        $k = ai_series_key($r, $dimension, $maps);
        if (!isset($by[$k])) {
            $by[$k] = array('input' => array_fill(0, $count, 0), 'output' => array_fill(0, $count, 0), 'requests' => array_fill(0, $count, 0));
        }
        $by[$k]['input'][$i]    += $r['n']['input'];
        $by[$k]['output'][$i]   += $r['n']['output'];
        $by[$k]['requests'][$i] += 1;
    }
    return array('labels' => $axis['labels'], 'full' => $axis['full'], 'requests' => $requests, 'by' => $by);
}

/** Error records from the error ledger: {dt, label}. */
function ai_error_records() {
    $rows = array();
    $f = ai_error_ledger_file();
    if (!is_file($f)) { return $rows; }
    $fh = @fopen($f, 'r');
    if (!$fh) { return $rows; }
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') { continue; }
        $r = json_decode($line, true);
        if (!is_array($r) || !isset($r['ts'])) { continue; }
        $rows[] = array('dt' => (string) $r['ts'], 'uid' => isset($r['uid']) ? (int) $r['uid'] : 0, 'label' => isset($r['label']) ? $r['label'] : ai_error_label(isset($r['code']) ? $r['code'] : 0));
    }
    fclose($fh);
    return $rows;
}

/** Error records, optionally scoped to one user. */
function ai_error_records_scoped($forUid) {
    $errs = ai_error_records();
    if ($forUid === null) { return $errs; }
    $uidF = (int) $forUid; $tmp = array();
    foreach ($errs as $e) { if ((isset($e['uid']) ? $e['uid'] : 0) === $uidF) { $tmp[] = $e; } }
    return $tmp;
}

/** Per-error-type time series for a range, plus a total count. */
function ai_error_series($errors, $range, $since) {
    $axis = ai_time_axis($range);
    $count = $axis['count'];
    $byType = array();
    $total = 0;
    foreach ($errors as $e) {
        if ($since !== null && $e['dt'] < $since) { continue; }
        $i = ai_axis_index($axis, $e['dt']);
        if ($i < 0) { continue; }
        $lbl = $e['label'];
        if (!isset($byType[$lbl])) { $byType[$lbl] = array_fill(0, $count, 0); }
        $byType[$lbl][$i] += 1;
        $total++;
    }
    return array('labels' => $axis['labels'], 'full' => $axis['full'], 'by_type' => $byType, 'total' => $total);
}

/** Shape a bucket for the API: token totals + estimated cost. */
function ai_bucket_out($b) {
    return array(
        'input_tokens'       => $b['input'],
        'output_tokens'      => $b['output'],
        'cache_write_tokens' => $b['cache_write'],
        'cache_read_tokens'  => $b['cache_read'],
        'total_tokens'       => $b['input'] + $b['output'] + $b['cache_write'] + $b['cache_read'],
        'messages'           => $b['messages'],
        'cost_usd'           => round(ai_cost_usd($b), 4),
    );
}

/** Full budget status used by usage.php (dashboard) and chat.php (gate).
 *  $projectId > 0 scopes the month/all-time totals and the charts to one project;
 *  the BUDGET + lock are always account-wide (every project) regardless. */
function ai_budget_status($DB, $range = '28d', $projectId = 0, $forUid = null, $dimension = 'model') {
    global $AI_PROVIDER, $CLAUDE_MODEL, $GEMINI_MODEL, $AI_MONTHLY_BUDGET_USD, $AI_BUDGET_LOCK_PCT;
    $price = ai_active_pricing();
    $since = ai_usage_reset_get();
    $month = gmdate('Y-m', time() + ai_tz_offset()); // local (UTC+8) month

    $records = ai_usage_records();
    // Scope to one user (non-admin dashboards / per-user budget).
    if ($forUid !== null) {
        $uidF = (int) $forUid; $tmp = array();
        foreach ($records as $r) { if ($r['uid'] === $uidF) { $tmp[] = $r; } }
        $records = $tmp;
    }

    // Account-wide spend drives the budget + lock (never scoped to one project).
    $globalAgg = ai_usage_aggregate($records, $month, $since);
    $budget = (float) $AI_MONTHLY_BUDGET_USD;
    $spent  = round(ai_cost_usd($globalAgg['month']), 4);
    $percent = ($budget > 0) ? round(($spent / $budget) * 100, 1) : 0;
    $locked = ($budget > 0) && ($percent >= $AI_BUDGET_LOCK_PCT);

    // Scoped view (charts + totals) for the selected project, or all projects.
    $scoped = $records;
    if ($projectId > 0) {
        $scoped = array();
        foreach ($records as $r) { if ($r['pid'] === $projectId) { $scoped[] = $r; } }
    }
    $agg = ai_usage_aggregate($scoped, $month, $since);
    $monthOut = ai_bucket_out($agg['month']);
    $allOut   = ai_bucket_out($agg['all_time']);
    $series = ai_usage_series($DB, $scoped, $range, $since, $dimension);

    return array(
        'provider' => $AI_PROVIDER,
        'model'    => ($AI_PROVIDER === 'claude') ? $CLAUDE_MODEL : $GEMINI_MODEL,
        'tier'     => ($AI_PROVIDER === 'gemini') ? 'Free tier' : 'Pay-as-you-go',
        'reset_at' => ($since !== null) ? gmdate('Y-m-d H:i:s', strtotime($since . ' UTC') + ai_tz_offset()) : null,
        'range' => $range,
        'project_id' => (int) $projectId,
        'pricing'  => array(
            'input_per_mtok'       => $price['input'],
            'output_per_mtok'      => $price['output'],
            'cache_write_per_mtok' => $price['cache_write'],
            'cache_read_per_mtok'  => $price['cache_read'],
        ),
        'budget' => array(
            'monthly_usd'   => round($budget, 2),
            'lock_pct'      => (int) $AI_BUDGET_LOCK_PCT,
            'spent_usd'     => $spent,
            'remaining_usd' => round(max(0, $budget - $spent), 4),
            'percent'       => $percent,
            'locked'        => $locked,
        ),
        'month'        => array_merge(array('label' => $month), $monthOut),
        'all_time'     => $allOut,
        'range_totals' => ai_range_totals($records, $range, $since, $projectId),
        'series'       => $series,
        'errors'       => ai_error_series(ai_error_records_scoped($forUid), $range, $since),
        'by_project'   => ai_usage_by_project($DB, $records, $since, $range),
    );
}
?>
