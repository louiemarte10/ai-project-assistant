<?php
/**
 * Gemini REST helpers (generativelanguage API). PHP 5.3 compatible.
 * Each function returns array('ok'=>bool, 'text'=>string) or array('ok'=>false,'error'=>string).
 */

function gemini_post($url, $payload) {
    $body = json_encode($payload);
    // The model occasionally returns 503 (overloaded) / 429 (rate limit). These
    // are transient, so retry a few times with a short backoff before giving up.
    $maxAttempts = 4;
    $resp = false; $code = 0; $err = '';
    $attemptErrors = array(); // HTTP code (or 0 for network) of each failed attempt
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $code < 200 || $code >= 300) { $attemptErrors[] = ($resp === false) ? 0 : $code; }
        $transient = ($resp === false) || $code === 503 || $code === 429 || $code === 500;
        if (!$transient || $attempt === $maxAttempts) { break; }
        usleep(700000 * $attempt); // 0.7s, 1.4s, 2.1s backoff
    }

    if ($resp === false) {
        return array('ok' => false, 'error' => 'curl: ' . $err, 'code' => 0, 'attempt_errors' => $attemptErrors);
    }
    $data = json_decode($resp, true);
    if ($code !== 200) {
        $msg = isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
        if ($code === 503 || $code === 429) {
            $msg = 'The AI model is busy right now. Please send your message again in a few seconds.';
        }
        return array('ok' => false, 'error' => $msg, 'code' => $code, 'attempt_errors' => $attemptErrors);
    }
    $text = '';
    if (isset($data['candidates'][0]['content']['parts'])) {
        foreach ($data['candidates'][0]['content']['parts'] as $p) {
            if (isset($p['text'])) { $text .= $p['text']; }
        }
    }
    // Token usage (Gemini usageMetadata: totalTokenCount, promptTokenCount,
    // thoughtsTokenCount, promptTokensDetails, candidatesTokenCount).
    $usage = isset($data['usageMetadata']) ? $data['usageMetadata'] : null;
    return array('ok' => true, 'text' => $text, 'usage' => $usage, 'code' => 200, 'attempt_errors' => $attemptErrors);
}

/** Build an inline_data image part by reading a file from disk. */
function gemini_image_part($filePath, $mime) {
    $bytes = @file_get_contents($filePath);
    if ($bytes === false) { return null; }
    return array('inline_data' => array('mime_type' => $mime, 'data' => base64_encode($bytes)));
}

/** Build an inline_data image part from already-base64 data (e.g. pasted in chat). */
function gemini_image_part_b64($base64, $mime) {
    return array('inline_data' => array('mime_type' => $mime, 'data' => $base64));
}

/** One-shot generation (optionally JSON), with optional image parts (multimodal). */
function gemini_generate($apiKey, $model, $prompt, $jsonMode, $imageParts = array()) {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . urlencode($apiKey);
    $parts = array(array('text' => $prompt));
    foreach ($imageParts as $ip) { if ($ip) { $parts[] = $ip; } }
    $payload = array('contents' => array(array('parts' => $parts)));
    if ($jsonMode) {
        $payload['generationConfig'] = array('responseMimeType' => 'application/json');
    }
    return gemini_post($url, $payload);
}

/** Build the {system_instruction, contents} payload for a chat turn. */
function gemini_chat_payload($systemText, $history, $userMessage, $imageParts) {
    $contents = array();
    foreach ($history as $h) {
        $role = ($h['role'] === 'ai') ? 'model' : 'user';
        $contents[] = array('role' => $role, 'parts' => array(array('text' => $h['text'])));
    }
    $userParts = array(array('text' => $userMessage));
    foreach ($imageParts as $ip) { if ($ip) { $userParts[] = $ip; } }
    $contents[] = array('role' => 'user', 'parts' => $userParts);
    return array('system_instruction' => array('parts' => array(array('text' => $systemText))), 'contents' => $contents);
}

/** Streaming chat via streamGenerateContent (SSE). Calls $onDelta($text) for answer
 *  chunks and $onThought($text) for the model's live "thought summary" chunks.
 *  Returns array('ok','text'(full),'usage','code','attempt_errors'). */
function gemini_chat_stream($apiKey, $model, $systemText, $history, $userMessage, $imageParts, $onDelta, $onThought = null) {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':streamGenerateContent?alt=sse&key=' . urlencode($apiKey);
    $payload = gemini_chat_payload($systemText, $history, $userMessage, $imageParts);
    // Ask Gemini 2.5 to include its thought summaries in the stream.
    $payload['generationConfig'] = array('thinkingConfig' => array('includeThoughts' => true));
    $body = json_encode($payload);

    $full = ''; $usage = null; $sseBuf = '';
    $writer = function ($ch, $data) use (&$sseBuf, &$full, &$usage, $onDelta, $onThought) {
        $sseBuf .= $data;
        while (($nl = strpos($sseBuf, "\n")) !== false) {
            $line = rtrim(substr($sseBuf, 0, $nl), "\r");
            $sseBuf = substr($sseBuf, $nl + 1);
            if (strpos($line, 'data:') !== 0) { continue; }
            $jsonStr = trim(substr($line, 5));
            if ($jsonStr === '' || $jsonStr === '[DONE]') { continue; }
            $j = json_decode($jsonStr, true);
            if (!is_array($j)) { continue; }
            if (isset($j['candidates'][0]['content']['parts'])) {
                foreach ($j['candidates'][0]['content']['parts'] as $p) {
                    if (!isset($p['text']) || $p['text'] === '') { continue; }
                    if (!empty($p['thought'])) {
                        if ($onThought) { call_user_func($onThought, $p['text']); }
                    } else {
                        $full .= $p['text'];
                        call_user_func($onDelta, $p['text']);
                    }
                }
            }
            if (isset($j['usageMetadata'])) { $usage = $j['usageMetadata']; }
        }
        return strlen($data);
    };

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, $writer);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        $msg = ($code === 429 || $code === 503) ? 'The AI model is busy right now. Please send your message again in a few seconds.' : ('HTTP ' . $code);
        return array('ok' => false, 'error' => $msg, 'code' => $code, 'attempt_errors' => array($code ? $code : 0), 'text' => $full);
    }
    return array('ok' => true, 'text' => $full, 'usage' => $usage, 'code' => 200, 'attempt_errors' => array());
}

/** Multi-turn chat with a system instruction, prior history, and optional images
 *  attached to the current user turn (project image docs + a pasted image). */
function gemini_chat($apiKey, $model, $systemText, $history, $userMessage, $imageParts = array()) {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . urlencode($apiKey);
    $contents = array();
    foreach ($history as $h) {
        $role = ($h['role'] === 'ai') ? 'model' : 'user';
        $contents[] = array('role' => $role, 'parts' => array(array('text' => $h['text'])));
    }
    $userParts = array(array('text' => $userMessage));
    foreach ($imageParts as $ip) { if ($ip) { $userParts[] = $ip; } }
    $contents[] = array('role' => 'user', 'parts' => $userParts);
    $payload = array(
        'system_instruction' => array('parts' => array(array('text' => $systemText))),
        'contents' => $contents,
    );
    return gemini_post($url, $payload);
}
?>
