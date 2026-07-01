<?php
/**
 * Anthropic Claude (Messages API) helpers. PHP 5.3 compatible.
 * Same return contract as gemini.php: array('ok'=>bool, 'text'=>string, 'usage'=>..)
 * or array('ok'=>false, 'error'=>string).
 *
 * Image parts are the provider-neutral shape used by ai.php:
 *   array('mime' => 'image/png', 'data' => <base64, no data: prefix>)
 */

function claude_post($apiKey, $model, $maxTokens, $system, $messages) {
    $payload = array(
        'model' => $model,
        'max_tokens' => (int) $maxTokens,
        'messages' => $messages,
    );
    if ($system !== null && $system !== '') { $payload['system'] = $system; }
    $body = json_encode($payload);

    $url = 'https://api.anthropic.com/v1/messages';
    $headers = array(
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    );

    // Retry transient overload/rate-limit/5xx (Anthropic uses 529 for overloaded).
    $maxAttempts = 4;
    $resp = false; $code = 0; $err = '';
    $attemptErrors = array();
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $code < 200 || $code >= 300) { $attemptErrors[] = ($resp === false) ? 0 : $code; }
        $transient = ($resp === false) || $code === 529 || $code === 429 || $code === 503 || $code === 500;
        if (!$transient || $attempt === $maxAttempts) { break; }
        usleep(700000 * $attempt); // 0.7s, 1.4s, 2.1s
    }

    if ($resp === false) {
        return array('ok' => false, 'error' => 'curl: ' . $err, 'code' => 0, 'attempt_errors' => $attemptErrors);
    }
    $data = json_decode($resp, true);
    if ($code !== 200) {
        $msg = isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
        if ($code === 529 || $code === 429) {
            $msg = 'The AI model is busy right now. Please send your message again in a few seconds.';
        }
        return array('ok' => false, 'error' => $msg, 'code' => $code, 'attempt_errors' => $attemptErrors);
    }
    $text = '';
    if (isset($data['content']) && is_array($data['content'])) {
        foreach ($data['content'] as $blk) {
            if (isset($blk['type']) && $blk['type'] === 'text' && isset($blk['text'])) { $text .= $blk['text']; }
        }
    }
    $usage = isset($data['usage']) ? $data['usage'] : null;
    return array('ok' => true, 'text' => $text, 'usage' => $usage, 'code' => 200, 'attempt_errors' => $attemptErrors);
}

/** Build the content blocks for one user/assistant turn (text + optional images). */
function claude_user_blocks($text, $imageParts) {
    $blocks = array();
    if ($text !== '' && $text !== null) { $blocks[] = array('type' => 'text', 'text' => $text); }
    foreach ($imageParts as $ip) {
        if (!$ip || empty($ip['data'])) { continue; }
        $blocks[] = array(
            'type' => 'image',
            'source' => array('type' => 'base64', 'media_type' => $ip['mime'], 'data' => $ip['data']),
        );
    }
    if (count($blocks) === 0) { $blocks[] = array('type' => 'text', 'text' => '(no content)'); }
    return $blocks;
}

/** One-shot generation. $jsonMode just nudges the prompt + strips code fences. */
function claude_generate($apiKey, $model, $maxTokens, $prompt, $jsonMode, $imageParts = array()) {
    $messages = array(array('role' => 'user', 'content' => claude_user_blocks($prompt, $imageParts)));
    $out = claude_post($apiKey, $model, $maxTokens, null, $messages);
    if ($out['ok'] && $jsonMode) { $out['text'] = claude_strip_fences($out['text']); }
    return $out;
}

/** Multi-turn chat with a system instruction, prior history, and current images. */
function claude_chat($apiKey, $model, $maxTokens, $systemText, $history, $userMessage, $imageParts = array()) {
    $messages = array();
    foreach ($history as $h) {
        $role = ($h['role'] === 'ai') ? 'assistant' : 'user';
        $messages[] = array('role' => $role, 'content' => array(array('type' => 'text', 'text' => $h['text'])));
    }
    $messages[] = array('role' => 'user', 'content' => claude_user_blocks($userMessage, $imageParts));
    return claude_post($apiKey, $model, $maxTokens, $systemText, $messages);
}

/** Strip ```json … ``` fences so json_decode works on JSON-mode replies. */
function claude_strip_fences($t) {
    $t = preg_replace('/^\s*```(?:json)?/i', '', trim($t));
    $t = preg_replace('/```\s*$/', '', $t);
    return trim($t);
}
?>
