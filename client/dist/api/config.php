<?php
/**
 * Project Turnover Assistant — DB + AI config.
 * PHP 5.3 compatible (array() syntax, no ?? operator).
 *
 * Mirrors the reference tools (lh_transfer_contact_v2): load the Pipeline X
 * framework so the `config` helper resolves DB hosts, then open mysqli.
 * Target database: callbox_reports on the "main" MaxScale proxy.
 */

require_once "{$_SERVER['DOCUMENT_ROOT']}/config/pipeline-x.php";

$DB = new mysqli(config::get_server_by_name('main'), "app_pipe", "a33-pipe", "callbox_reports");
if ($DB->connect_errno) {
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => 'DB connection failed: ' . $DB->connect_error));
    exit;
}
$DB->set_charset('utf8mb4');

// Gemini key: loaded from gitignored secret-gemini.php (deployed via dist/),
// falling back to an environment variable. NEVER commit the real key.
$GEMINI_API_KEY = '';
$__keyfile = dirname(__FILE__) . '/secret-gemini.php';
if (file_exists($__keyfile)) {
    $GEMINI_API_KEY = include $__keyfile;
}
if (!$GEMINI_API_KEY) {
    $GEMINI_API_KEY = getenv('GEMINI_API_KEY');
}
// flash-lite has a much higher free-tier daily quota than 2.5-flash (which is 20/day).
$GEMINI_MODEL = 'gemini-2.5-flash-lite';

// Which AI provider the chat/summary/EOD uses: 'claude' (Anthropic) or 'gemini'.
// Flip this one value to switch models; the other stays available as a fallback.
$AI_PROVIDER = 'gemini';

// Anthropic Claude key: loaded from gitignored secret-anthropic.php (deployed via
// dist/), falling back to an environment variable. NEVER commit the real key.
$ANTHROPIC_API_KEY = '';
$__antkey = dirname(__FILE__) . '/secret-anthropic.php';
if (file_exists($__antkey)) { $ANTHROPIC_API_KEY = include $__antkey; }
if (!$ANTHROPIC_API_KEY) { $ANTHROPIC_API_KEY = getenv('ANTHROPIC_API_KEY'); }
$CLAUDE_MODEL = 'claude-opus-4-8';
$CLAUDE_MAX_TOKENS = 4096;

// ── AI cost metering / budget ───────────────────────────────────────────────
// APIs don't expose a dollar balance, so we estimate spend from the token counts
// we already store (usage_meta_data). Rates are per provider (USD per 1M tokens);
// EDIT to match the current pricing in your console. NOTE: Gemini's free tier is
// $0 — the Gemini rates below are the paid-overage estimate if you exceed it.
$AI_PRICING = array(
    'claude' => array('input' => 15.0, 'output' => 75.0, 'cache_write' => 18.75, 'cache_read' => 1.5),   // Opus 4.8
    'gemini' => array('input' => 0.30, 'output' => 2.50, 'cache_write' => 0.0,   'cache_read' => 0.075), // 2.5 Flash
);

// Monthly spend budget (USD) and the % at which the chat is auto-disabled.
$AI_MONTHLY_BUDGET_USD = 50.0;
$AI_BUDGET_LOCK_PCT    = 85;

// Timezone offset (hours) for displaying dashboard times. Stored timestamps are
// UTC; the dashboard shows them in UTC+8 (Manila / PHT).
$AI_TZ_OFFSET_HOURS = 8;

$AI_CONTEXT_CHAR_LIMIT = 120000;

// Where uploaded image files are stored on disk (deploy-folder/uploads, which is
// excluded from the deploy sync so images persist). Pre-created writable (apache).
$UPLOAD_DIR = dirname(dirname(__FILE__)) . '/uploads';
$MAX_IMAGE_BYTES = 8 * 1024 * 1024; // 8 MB per image

// GitHub Personal Access Token for auto-importing repo docs (.md/.env) on project
// create. Loaded from gitignored secret-github.php (deployed via dist/), or env.
// Needed for PRIVATE repos; optional (rate-limited) for public ones.
$GITHUB_TOKEN = '';
$__ghkey = dirname(__FILE__) . '/secret-github.php';
if (file_exists($__ghkey)) { $GITHUB_TOKEN = include $__ghkey; }
if (!$GITHUB_TOKEN) { $GITHUB_TOKEN = getenv('GITHUB_TOKEN'); }
$GITHUB_MAX_FILES = 40;
$GITHUB_MAX_FILE_BYTES = 300 * 1024; // 300 KB per file (md/env are small)
?>
