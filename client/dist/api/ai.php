<?php
/**
 * Provider-agnostic AI layer. Dispatches to Claude (Anthropic) or Gemini based on
 * the $AI_PROVIDER config switch, so the rest of the app calls ai_* and never
 * cares which model is active. PHP 5.3 compatible.
 *
 * All functions return the same contract as the provider helpers:
 *   array('ok'=>bool, 'text'=>string, 'usage'=>..) | array('ok'=>false,'error'=>..)
 *
 * Image parts are provider-neutral: array('mime'=>..., 'data'=>base64).
 */
require_once dirname(__FILE__) . '/gemini.php';
require_once dirname(__FILE__) . '/claude.php';
require_once dirname(__FILE__) . '/budget.php';

/** Neutral image part read from a file on disk (null if unreadable). */
function ai_image_part($filePath, $mime) {
    $bytes = @file_get_contents($filePath);
    if ($bytes === false) { return null; }
    return array('mime' => $mime, 'data' => base64_encode($bytes));
}

/** Neutral image part from already-base64 data (e.g. pasted in chat). */
function ai_image_part_b64($base64, $mime) {
    return array('mime' => $mime, 'data' => $base64);
}

/** Convert neutral image parts to Gemini inline_data parts. */
function ai__to_gemini_parts($imageParts) {
    $out = array();
    foreach ($imageParts as $p) { if ($p && !empty($p['data'])) { $out[] = gemini_image_part_b64($p['data'], $p['mime']); } }
    return $out;
}

/** One-shot generation (optionally JSON), with optional images (multimodal). */
function ai_generate($prompt, $jsonMode, $imageParts = array()) {
    global $AI_PROVIDER, $GEMINI_API_KEY, $GEMINI_MODEL, $ANTHROPIC_API_KEY, $CLAUDE_MODEL, $CLAUDE_MAX_TOKENS;
    if ($AI_PROVIDER === 'claude') {
        $r = claude_generate($ANTHROPIC_API_KEY, $CLAUDE_MODEL, $CLAUDE_MAX_TOKENS, $prompt, $jsonMode, $imageParts);
    } else {
        $r = gemini_generate($GEMINI_API_KEY, $GEMINI_MODEL, $prompt, $jsonMode, ai__to_gemini_parts($imageParts));
    }
    ai_log_attempt_errors($r);
    return $r;
}

/** Multi-turn chat with a system instruction, prior history, and current images. */
function ai_chat($systemText, $history, $userMessage, $imageParts = array()) {
    global $AI_PROVIDER, $GEMINI_API_KEY, $GEMINI_MODEL, $ANTHROPIC_API_KEY, $CLAUDE_MODEL, $CLAUDE_MAX_TOKENS;
    if ($AI_PROVIDER === 'claude') {
        $r = claude_chat($ANTHROPIC_API_KEY, $CLAUDE_MODEL, $CLAUDE_MAX_TOKENS, $systemText, $history, $userMessage, $imageParts);
    } else {
        $r = gemini_chat($GEMINI_API_KEY, $GEMINI_MODEL, $systemText, $history, $userMessage, ai__to_gemini_parts($imageParts));
    }
    ai_log_attempt_errors($r);
    return $r;
}

/** Streaming chat: invokes $onDelta($text) as the reply is generated. For Gemini
 *  this is true SSE; for Claude it falls back to a single delta with the full text. */
function ai_chat_stream($systemText, $history, $userMessage, $imageParts, $onDelta, $onThought = null) {
    global $AI_PROVIDER, $GEMINI_API_KEY, $GEMINI_MODEL, $ANTHROPIC_API_KEY, $CLAUDE_MODEL, $CLAUDE_MAX_TOKENS;
    if ($AI_PROVIDER === 'claude') {
        $r = claude_chat($ANTHROPIC_API_KEY, $CLAUDE_MODEL, $CLAUDE_MAX_TOKENS, $systemText, $history, $userMessage, $imageParts);
        if (!empty($r['ok']) && isset($r['text']) && $r['text'] !== '') { call_user_func($onDelta, $r['text']); }
    } else {
        $r = gemini_chat_stream($GEMINI_API_KEY, $GEMINI_MODEL, $systemText, $history, $userMessage, ai__to_gemini_parts($imageParts), $onDelta, $onThought);
    }
    ai_log_attempt_errors($r);
    return $r;
}
?>
