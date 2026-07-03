<?php
/** TEMP: validate the funnel EMAIL-count query against the real tool. Token-gated.
 *  GET /api/funnel_test.php?token=funnelprobe-2026&client_id=21715&from=2026-05-08&to=2026-05-22&tz=%2B08:00&cidcol=ca.client_id
 *  Remove after the query is validated. */
require_once dirname(__FILE__) . '/_lib.php';

if (!isset($_GET['token']) || $_GET['token'] !== 'funnelprobe-2026') { fail('nope', 403); }

$clientId = isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0;
$from = isset($_GET['from']) ? preg_replace('/[^0-9\-]/', '', $_GET['from']) : '';
$to   = isset($_GET['to'])   ? preg_replace('/[^0-9\-]/', '', $_GET['to'])   : '';
$tz   = isset($_GET['tz']) ? $_GET['tz'] : '+08:00';
$cidcol = isset($_GET['cidcol']) ? preg_replace('/[^a-zA-Z0-9_.]/', '', $_GET['cidcol']) : 'ca.client_id';
if ($clientId <= 0 || $from === '' || $to === '') { fail('client_id, from, to required', 400); }

$qtz = "'" . $DB->real_escape_string($tz) . "'";
$qfrom = "'" . $from . " 00:00:00'";
$qto = "'" . $to . " 23:59:59'";
$out = array('client_id' => $clientId, 'from' => $from, 'to' => $to, 'tz' => $tz, 'cidcol' => $cidcol);

// Campaign name
$r = $DB->query("SELECT client FROM callbox_pipeline2._vw_client_list_info WHERE is_active=1 AND client_id=" . $clientId . " LIMIT 1");
$out['campaign'] = ($r && $row = $r->fetch_assoc()) ? $row['client'] : null;

// save_n_send email count (schema-qualified port of getEmailActivities part 1)
$sql = "SELECT COUNT(*) AS c
    FROM callbox_pipeline2.mailer_events e
    INNER JOIN callbox_pipeline2.client_lists USING (client_list_id)
    INNER JOIN callbox_pipeline2.client_job_orders cj USING (client_job_order_id)
    INNER JOIN callbox_pipeline2.client_accounts ca USING (client_account_id)
    INNER JOIN callbox_pipeline2.target_details td ON (td.target_detail_id = e.target_detail_id)
    INNER JOIN callbox_pipeline2.targets USING (target_id)
    WHERE " . $cidcol . " = " . $clientId . " AND e.source = 'save_n_send'
      AND CONVERT_TZ(e.sent, 'UTC', " . $qtz . ") BETWEEN " . $qfrom . " AND " . $qto;
$res = $DB->query($sql);
if ($res) { $row = $res->fetch_assoc(); $out['save_n_send_count'] = (int) $row['c']; }
else { $out['save_n_send_error'] = $DB->error; }

// Mass-mail (smtp_mass_mail): templates owned by this client, then count events.
$tplIds = array();
$tr = $DB->query("SELECT mt.mailer_template_id FROM callbox_pipeline2.mailer_templates mt
                  INNER JOIN callbox_pipeline2.mailer_accounts ma USING (mailer_account_id)
                  WHERE ma.client_ids IN (" . $clientId . ")");
if ($tr) { while ($t = $tr->fetch_assoc()) { $tplIds[] = (int) $t['mailer_template_id']; } }
else { $out['template_error'] = $DB->error; }
$out['template_count'] = count($tplIds);

if (count($tplIds)) {
    $tplList = implode(',', $tplIds);
    $msql = "SELECT COUNT(*) AS c
        FROM callbox_pipeline2.mailer_events e
        INNER JOIN callbox_mailing_system.mailing_list_blasts mlb USING (mailing_list_blast_id)
        WHERE e.source IN ('smtp_mass_mail')
          AND e.mailer_template_id IN (" . $tplList . ")
          AND e.mailer_template_id NOT IN (0)
          AND mlb.x = 'active'
          AND CONVERT_TZ(e.sent, 'UTC', " . $qtz . ") BETWEEN " . $qfrom . " AND " . $qto;
    $mres = $DB->query($msql);
    if ($mres) { $mrow = $mres->fetch_assoc(); $out['mass_mail_count'] = (int) $mrow['c']; }
    else { $out['mass_mail_error'] = $DB->error; }
}

$out['email_total'] = (isset($out['save_n_send_count']) ? $out['save_n_send_count'] : 0)
                    + (isset($out['mass_mail_count']) ? $out['mass_mail_count'] : 0);

// ── Call / Social / IM / Data-Enrichment via Hubspot logs (port of getCallActivities, count-only) ──
$cid = $clientId;
$callSql = "SELECT IF(etxt.channel_lkp_id = 11, 'DATA_ENRICHMENT', htol.type) AS t, COUNT(DISTINCT etxt.event_tm_ob_txn_id) AS c
    FROM callbox_hubspot_reports.hs_tm_ob_logs htol
    LEFT OUTER JOIN callbox_hubspot_reports.hs_tm_ob_engagements htoe ON htoe.hs_engagement_id = htol.hs_engagement_id
    LEFT OUTER JOIN callbox_pipeline2.events_tm_ob_txn etxt ON etxt.event_tm_ob_txn_id = htoe.event_tm_ob_txn_id
    LEFT OUTER JOIN callbox_pipeline2.events_tm_ob_lkp etol ON etol.event_tm_ob_lkp_id = etxt.event_tm_ob_lkp_id
    INNER JOIN callbox_pipeline2.client_lists cl ON etol.client_list_id = cl.client_list_id
    INNER JOIN callbox_pipeline2.client_job_orders cjo USING (client_job_order_id)
    INNER JOIN callbox_pipeline2.client_accounts ca USING (client_account_id)
    WHERE 1
      AND (htol.type IN ('CALL','EMAIL','LINKEDIN_MESSAGE','WHATS_APP') OR etxt.channel_lkp_id = 11)
      AND ca.client_id IN (" . $cid . ")
      AND etxt.x = 'active' AND cl.x = 'active'
      AND CONCAT_WS(' ', etol.date_contacted, etxt.time_contacted)
          BETWEEN CONVERT_TZ(" . $qfrom . ", " . $qtz . ", 'UTC') AND CONVERT_TZ(" . $qto . ", " . $qtz . ", 'UTC')
    GROUP BY t";
$out['hubspot_by_type'] = array();
$cr = $DB->query($callSql);
if ($cr) { while ($row = $cr->fetch_assoc()) { $out['hubspot_by_type'][$row['t']] = (int) $row['c']; } }
else { $out['hubspot_error'] = $DB->error; }

// ── Non-Hubspot activities (port of getNonHubspotActivities, count-only) ──
// NOTE: tool passes date_from/date_to WITHOUT time component here.
$qfromD = "'" . $from . "'";
$qtoD   = "'" . $to . "'";
$nhSql = "SELECT CASE
        WHEN cl.channel = 'Calling' THEN 'CALL'
        WHEN cl.channel = 'Email' THEN 'EMAIL'
        WHEN cl.channel = 'Social Media' THEN 'LINKEDIN_MESSAGE'
        WHEN cl.channel = 'Instant Message' THEN 'WHATS_APP'
        WHEN etx.channel_lkp_id = 11 OR cl.channel = 'Data Enrichment' THEN 'DATA_ENRICHMENT'
        ELSE cl.channel END AS t,
        COUNT(DISTINCT etx.event_tm_ob_txn_id) AS c
    FROM callbox_pipeline2.events_tm_ob_txn etx
    INNER JOIN callbox_pipeline2.events_tm_ob_lkp etl ON etl.event_tm_ob_lkp_id = etx.event_tm_ob_lkp_id
    INNER JOIN callbox_pipeline2.channels_lkp cl ON etx.channel_lkp_id = cl.channel_lkp_id
    INNER JOIN callbox_pipeline2.client_lists cll ON etl.client_list_id = cll.client_list_id
    LEFT OUTER JOIN callbox_hubspot_reports.hs_tm_ob_engagements tob ON etx.event_tm_ob_txn_id = tob.event_tm_ob_txn_id
    WHERE tob.id IS NULL
      AND etl.client_list_id IN (SELECT client_list_id FROM callbox_pipeline2._vw_client_list_info WHERE client_id IN (" . $cid . "))
      AND cll.x = 'active' AND etx.x = 'active'
      AND CONCAT_WS(' ', etl.date_contacted, etx.time_contacted)
          BETWEEN CONVERT_TZ(" . $qfromD . ", " . $qtz . ", 'UTC') AND CONVERT_TZ(" . $qtoD . ", " . $qtz . ", 'UTC')
    GROUP BY t";
$out['nonhubspot_by_type'] = array();
$nr = $DB->query($nhSql);
if ($nr) { while ($row = $nr->fetch_assoc()) { $out['nonhubspot_by_type'][$row['t']] = (int) $row['c']; } }
else { $out['nonhubspot_error'] = $DB->error; }

// ── Merged funnel (mirror handleAllType type buckets) ──
function _typ($out, $k, $t) { return isset($out[$k][$t]) ? $out[$k][$t] : 0; }
$out['funnel'] = array(
    'Call'            => _typ($out, 'hubspot_by_type', 'CALL')             + _typ($out, 'nonhubspot_by_type', 'CALL'),
    'Email'           => $out['email_total'],
    'Social Media'    => _typ($out, 'hubspot_by_type', 'LINKEDIN_MESSAGE') + _typ($out, 'nonhubspot_by_type', 'LINKEDIN_MESSAGE'),
    'Instant Message' => _typ($out, 'hubspot_by_type', 'WHATS_APP')        + _typ($out, 'nonhubspot_by_type', 'WHATS_APP'),
    'Data Enrichment' => _typ($out, 'hubspot_by_type', 'DATA_ENRICHMENT')  + _typ($out, 'nonhubspot_by_type', 'DATA_ENRICHMENT'),
);

send_json($out, 200);
?>
