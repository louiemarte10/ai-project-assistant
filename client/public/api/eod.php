<?php
/**
 * Shareday / End-of-Day (EOD) report lookup — read-only, parameterized queries
 * against callbox_reports.eod_main + eod_items (same DB the app uses), with the
 * employee resolved from callbox_pipeline2.employees. PHP 5.3 compatible.
 *
 * This is NOT free-form SQL: only these two tables are read, with bound int ids
 * and escaped name filters, so the AI can't run arbitrary queries.
 */
require_once dirname(__FILE__) . '/ai.php';

/** Ask Gemini to pull {name, start, end} out of the request, resolving any
 *  relative period ("last week", "yesterday", "this month"…) into an actual
 *  created_date range using today's date in Manila time. */
function eod_extract_params($message) {
    $tz = new DateTimeZone('Asia/Manila');
    $now = new DateTime('now', $tz);
    $today = $now->format('Y-m-d');
    $dow = $now->format('l'); // e.g. "Tuesday"
    $prompt = "Today's date is $today ($dow), timezone Asia/Manila. "
        . "From the request below, extract for an EOD/shareday report:\n"
        . "- name: the employee's full name, or empty string for a whole-team report.\n"
        . "- start: first day of the requested period as YYYY-MM-DD.\n"
        . "- end: last day of the requested period as YYYY-MM-DD.\n"
        . "Resolve relative periods from today's date. Weeks run Monday to Sunday: "
        . "'today'=today for both; 'yesterday'=yesterday for both; 'this week'=this week's Mon..Sun; "
        . "'last week'=previous week's Mon..Sun; 'this month'=1st..last day of this month; "
        . "'last month'=previous month; a single date=that day for both; a month name=that whole month. "
        . "If no period is mentioned, leave start and end empty.\n"
        . "Reply with ONLY JSON: {\"name\":\"\",\"start\":\"\",\"end\":\"\"}.\n\nRequest: " . $message;
    $out = ai_generate($prompt, true);
    if (!$out['ok']) { return null; }
    $j = json_decode(trim($out['text']), true);
    if (!is_array($j)) { return null; }
    $start = (isset($j['start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $j['start'])) ? $j['start'] : '';
    $end   = (isset($j['end'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $j['end']))   ? $j['end']   : '';
    if ($start !== '' && $end === '') { $end = $start; }
    if ($end !== '' && $start === '') { $start = $end; }
    if ($start !== '' && $end !== '' && $start > $end) { $tmp = $start; $start = $end; $end = $tmp; }
    return array(
        'name'  => isset($j['name']) ? trim($j['name']) : '',
        'start' => $start,
        'end'   => $end,
    );
}

/** Resolve an employee name to user_id(s) in callbox_pipeline2.employees. */
function eod_find_users($DB, $name) {
    $name = trim($name);
    if ($name === '') { return array(); }
    $tokens = preg_split('/\s+/', $name);
    $first = $DB->real_escape_string($tokens[0]);
    $last  = $DB->real_escape_string($tokens[count($tokens) - 1]);
    $full  = $DB->real_escape_string($name);
    $sql = "SELECT user_id, first_name, last_name FROM callbox_pipeline2.employees
            WHERE x='active' AND (
                (first_name LIKE '" . $first . "%' AND last_name LIKE '" . $last . "%')
                OR CONCAT(first_name, ' ', last_name) LIKE '%" . $full . "%'
            )
            ORDER BY last_name, first_name LIMIT 6";
    $res = $DB->query($sql);
    $out = array();
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $out[] = array('user_id' => (int) $r['user_id'], 'name' => trim($r['first_name'] . ' ' . $r['last_name']));
        }
    }
    return $out;
}

/** Convert the stored HTML content to readable plain text, one item per line.
 *  ShareDay entries use "## " as an item marker, so treat those as line breaks. */
function eod_html_to_text($html) {
    $s = preg_replace('~<\s*br\s*/?>~i', "\n", $html);
    $s = preg_replace('~</(p|li|div|h[1-6]|tr)>~i', "\n", $s);
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/\s*#{2,}\s+/', "\n", $s);   // "## " bullet markers → new lines
    $s = preg_replace("/[ \t]+\n/", "\n", $s);
    $s = preg_replace("/\n{2,}/", "\n", $s);
    return trim($s);
}

/** Fetch a user's EOD entries (eod_main + eod_items) for a date range. */
function eod_fetch_report($DB, $userId, $start, $end, $maxDays) {
    $res = $DB->query(
        "SELECT id, title, created_date FROM eod_main
         WHERE created_by = " . (int) $userId . " AND x='active'
           AND created_date BETWEEN '" . $start . "' AND '" . $end . "'
         ORDER BY created_date ASC LIMIT " . (int) $maxDays
    );
    $days = array();
    if ($res) {
        while ($m = $res->fetch_assoc()) {
            $mid = (int) $m['id'];
            $items = array();
            $ir = $DB->query("SELECT type, content FROM eod_items WHERE eod_main_id = " . $mid . " AND x='active' ORDER BY id ASC");
            if ($ir) {
                while ($it = $ir->fetch_assoc()) {
                    $c = json_decode($it['content'], true);
                    $val = (is_array($c) && isset($c['value'])) ? $c['value'] : (is_string($it['content']) ? $it['content'] : '');
                    $txt = eod_html_to_text($val);
                    if ($txt !== '') { $items[] = array('type' => $it['type'], 'text' => $txt); }
                }
            }
            $days[] = array('date' => $m['created_date'], 'title' => $m['title'], 'items' => $items);
        }
    }
    return array('start' => $start, 'end' => $end, 'days' => $days);
}

/** Team report: all Software Development EODs for the month, grouped by employee
 *  NAME (created_by resolved via employees; user IDs are never shown). */
function eod_fetch_team_report($DB, $start, $end, $maxRows) {
    $res = $DB->query(
        "SELECT id, title, created_date, created_by FROM eod_main
         WHERE x='active' AND title LIKE '%Software Development%'
           AND created_date BETWEEN '" . $start . "' AND '" . $end . "'
         ORDER BY created_by ASC, created_date ASC LIMIT " . (int) $maxRows
    );
    $rows = array(); $ids = array();
    if ($res) { while ($m = $res->fetch_assoc()) { $rows[] = $m; $ids[(int) $m['created_by']] = true; } }

    $names = array();
    if (count($ids)) {
        $idList = implode(',', array_map('intval', array_keys($ids)));
        $nr = $DB->query("SELECT user_id, first_name, last_name FROM callbox_pipeline2.employees WHERE user_id IN (" . $idList . ")");
        if ($nr) { while ($e = $nr->fetch_assoc()) { $names[(int) $e['user_id']] = trim($e['first_name'] . ' ' . $e['last_name']); } }
    }

    $byEmp = array();
    foreach ($rows as $m) {
        $uid = (int) $m['created_by'];
        $nm = isset($names[$uid]) && $names[$uid] !== '' ? $names[$uid] : 'Unknown employee';
        $items = array();
        $ir = $DB->query("SELECT content FROM eod_items WHERE eod_main_id = " . (int) $m['id'] . " AND x='active' ORDER BY id ASC");
        if ($ir) {
            while ($it = $ir->fetch_assoc()) {
                $c = json_decode($it['content'], true);
                $val = (is_array($c) && isset($c['value'])) ? $c['value'] : (is_string($it['content']) ? $it['content'] : '');
                $txt = eod_html_to_text($val);
                if ($txt !== '') { $items[] = $txt; }
            }
        }
        if (!isset($byEmp[$nm])) { $byEmp[$nm] = array(); }
        $byEmp[$nm][] = array('date' => $m['created_date'], 'title' => $m['title'], 'items' => $items);
    }
    ksort($byEmp);
    return array('start' => $start, 'end' => $end, 'byEmp' => $byEmp);
}

/** Format a team report grouped by employee name (no user IDs). */
function eod_build_team_text($report) {
    $out = "### SHAREDAY / EOD REPORT (Software Development) — " . $report['start'] . " to " . $report['end'] . "\n";
    if (count($report['byEmp']) === 0) { return $out . "(No Software Development EOD entries found for this period.)\n"; }
    foreach ($report['byEmp'] as $name => $days) {
        $out .= "\n#### " . $name . "\n";
        foreach ($days as $d) {
            $out .= "**" . $d['date'] . " — " . $d['title'] . "**\n";
            foreach ($d['items'] as $text) {
                foreach (explode("\n", $text) as $ln) {
                    $ln = preg_replace('/^\s*(?:#{1,6}|[-*\x{2022}])\s*/u', '', $ln);
                    $ln = trim($ln);
                    if ($ln !== '') { $out .= "- " . $ln . "\n"; }
                }
            }
        }
    }
    return $out;
}

/** Format the report as a text block for the AI context. */
function eod_build_text($name, $userId, $report) {
    $out = "### SHAREDAY / EOD REPORT — " . $name . ", " . $report['start'] . " to " . $report['end'] . "\n";
    if (count($report['days']) === 0) {
        return $out . "(No EOD/shareday entries found for this employee in this period.)\n";
    }
    foreach ($report['days'] as $d) {
        $out .= "\n**" . $d['date'] . " — " . $d['title'] . "**\n";
        foreach ($d['items'] as $it) {
            foreach (explode("\n", $it['text']) as $ln) {
                // Strip any leading bullet/heading markers; emit a clean markdown bullet.
                $ln = preg_replace('/^\s*(?:#{1,6}|[-*\x{2022}])\s*/u', '', $ln);
                $ln = trim($ln);
                if ($ln !== '') { $out .= "- " . $ln . "\n"; }
            }
        }
    }
    return $out;
}
?>
