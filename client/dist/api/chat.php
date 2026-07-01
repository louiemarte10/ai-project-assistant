<?php
/** GET  /api/chat.php?project_id=N           → chat history (scoped to project)
 *  POST /api/chat.php  { project_id, message } → send message, get AI reply */
require_once dirname(__FILE__) . '/_lib.php';
require_once dirname(__FILE__) . '/ai.php';
require_once dirname(__FILE__) . '/budget.php';
require_once dirname(__FILE__) . '/apikeys.php';
require_once dirname(__FILE__) . '/extract.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $pid = project_id_param();

    // Stream a message's attached image (for <img> display in the chat history).
    if (isset($_GET['attach'])) {
        $mid = isset($_GET['message_id']) ? (int) $_GET['message_id'] : 0;
        $ai  = isset($_GET['i']) ? (int) $_GET['i'] : 0;
        $list = $mid > 0 ? chat_attach_list($UPLOAD_DIR, $pid, $mid) : array();
        $f = isset($list[$ai]) ? $list[$ai] : null;
        if ($f && is_file($f)) {
            header('Content-Type: ' . chat_mime_for_file($f));
            header('Content-Disposition: inline');
            readfile($f);
            exit;
        }
        header('HTTP/1.1 404 Not Found');
        exit;
    }

    $gAgentId = current_user_id();
    $gCols = chat_cols($DB);

    if (chat_conv_enabled($DB)) {
        // Conversation mode: messages belong to a specific thread.
        $cid = isset($_GET['conversation_id']) ? (int) $_GET['conversation_id'] : 0;
        if ($cid <= 0) { send_json(array(), 200); } // no thread selected yet
        $where = "project_id=" . (int) $pid . " AND conversation_id=" . (int) $cid;
    } else {
        // Legacy single-thread: scope to the agent.
        $where = "project_id=" . (int) $pid;
        if (!empty($gCols['user_id'])) { $where .= " AND user_id=" . (int) $gAgentId; }
    }

    $res = $DB->query("SELECT message_id, project_id, sender_role, message_payload, timestamp FROM chat_logs WHERE " . $where . " ORDER BY timestamp ASC, message_id ASC");
    $rows = array();
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $r['message_id'] = (int) $r['message_id'];
            $r['project_id'] = (int) $r['project_id'];
            // Surface any attached images stored for this message.
            $alist = chat_attach_list($UPLOAD_DIR, $pid, $r['message_id']);
            if (count($alist)) {
                $atts = array();
                foreach ($alist as $idx => $path) {
                    $mime = chat_mime_for_file($path);
                    $atts[] = array('i' => $idx, 'mime' => $mime, 'is_image' => (strpos($mime, 'image/') === 0));
                }
                $r['attachments'] = $atts;
            }
            $rows[] = $r;
        }
    }
    send_json($rows, 200);
}

if ($method === 'POST') {
    $in = body_json();
    $pid = 0;
    if (isset($in['project_id'])) { $pid = (int) $in['project_id']; }
    else if (isset($_GET['project_id'])) { $pid = (int) $_GET['project_id']; }
    $msg = isset($in['message']) ? trim($in['message']) : '';

    // Optional attachments for THIS question (base64). Images → vision input;
    // .csv/.xlsx/.docx/.pdf/text → extracted to text. Supports MULTIPLE files
    // (accepts the legacy single attach_* fields too).
    $attachIn = array();
    if (isset($in['attachments']) && is_array($in['attachments'])) {
        foreach ($in['attachments'] as $a) {
            if (is_array($a) && isset($a['data']) && $a['data'] !== '') {
                $attachIn[] = array(
                    'data' => $a['data'],
                    'name' => isset($a['name']) && $a['name'] !== '' ? $a['name'] : 'attachment',
                    'mime' => isset($a['mime']) ? $a['mime'] : 'application/octet-stream',
                );
            }
        }
    } else if (isset($in['attach_data']) && $in['attach_data'] !== '') {
        $attachIn[] = array(
            'data' => $in['attach_data'],
            'name' => isset($in['attach_name']) && $in['attach_name'] !== '' ? $in['attach_name'] : 'attachment',
            'mime' => isset($in['attach_mime']) ? $in['attach_mime'] : '',
        );
    }

    if ($pid <= 0) { fail('project_id is required.', 400); }
    if ($msg === '' && count($attachIn) === 0) { fail('message is required.', 400); }

    // Per-user API keys: the chat uses the logged-in user's own Gemini key(s).
    // Multiple keys are tried in order (fallback) if one hits its limit. If the
    // table exists but they have none, block with a recognizable code so the UI
    // shows the key-entry form.
    $chatUid = current_user_id();
    $cfgModel = $GEMINI_MODEL;
    $userKeys = array();
    if (apikey_table_exists($DB)) {
        $userKeys = apikey_get_keys($DB, $chatUid);
        if (count($userKeys) === 0) { fail('NO_API_KEY', 428); }
    } else {
        $userKeys = array(array('api_key' => $GEMINI_API_KEY, 'ai_model' => $cfgModel));
    }

    // Budget guard: stop calling the AI once the month's estimated spend reaches
    // the lock threshold (per user), so a key can't run past its budget.
    $bstat = ai_budget_status($DB, '28d', 0, $chatUid > 0 ? $chatUid : null);
    if (!empty($bstat['budget']['locked'])) {
        fail('AI chat is disabled: the monthly AI budget has been reached ('
            . $bstat['budget']['percent'] . '% of $' . $bstat['budget']['monthly_usd']
            . '). Raise the budget in the server config or wait until next month.', 402);
    }

    // Split attachments into images (vision + saved to disk) and text (extracted).
    $imageAttachments = array(); // each array('mime','data') — kept for vision + disk
    $attTextBlocks = array();
    $attNames = array();
    foreach ($attachIn as $a) {
        $attNames[] = $a['name'];
        if ($a['mime'] !== '' && strpos($a['mime'], 'image/') === 0) {
            $imageAttachments[] = array('mime' => $a['mime'], 'data' => $a['data']);
        } else {
            $raw = base64_decode($a['data']);
            if ($raw !== false && $raw !== '') {
                $tmp = tempnam(sys_get_temp_dir(), 'att');
                @file_put_contents($tmp, $raw);
                $t = extract_text($tmp, $a['name']);
                @unlink($tmp);
                if ($t !== null && trim($t) !== '') { $attTextBlocks[] = "### ATTACHED FILE: " . $a['name'] . "\n" . substr($t, 0, 80000); }
            }
        }
    }
    if ($msg === '') { $msg = '(attachment)'; }
    // Stored message records the attachment filenames so chat history shows them.
    $storedMsg = count($attNames) ? ($msg . "\n\n[attached: " . implode(', ', $attNames) . "]") : $msg;

    $pr = $DB->query("SELECT project_name FROM projects WHERE project_id=" . (int) $pid);
    if (!$pr || $pr->num_rows === 0) { fail('Project not found.', 404); }
    $prow = $pr->fetch_assoc();
    $pn = $prow['project_name'];

    // Logged-in agent (from the px_login session): token + user id for each message.
    $sid = session_id();
    $agentId = current_user_id();
    $uidParam = $agentId > 0 ? $agentId : null;
    $agentName = chat_agent_name($DB);
    $cols = chat_cols($DB);
    $convEnabled = chat_conv_enabled($DB);

    // Resolve / create the conversation (thread). A short AI title is generated
    // from the first question further below.
    $convId = 0;
    $convTitle = null;
    $createdNew = false;
    if ($convEnabled) {
        $convId = isset($in['conversation_id']) ? (int) $in['conversation_id'] : 0;
        if ($convId <= 0) {
            $convTitle = substr(trim($msg), 0, 80); // temporary; refined after the reply
            if ($convTitle === '') { $convTitle = 'New chat'; }
            $cstmt = $DB->prepare("INSERT INTO chat_conversations (project_id, user_id, title, updated_at) VALUES (?, ?, ?, NOW())");
            $cstmt->bind_param('iis', $pid, $uidParam, $convTitle);
            $cstmt->execute();
            $convId = $DB->insert_id;
            $cstmt->close();
            $createdNew = true;
        }
    }

    // Persist the user message first (token_used / user_id / conversation_id if present).
    $role = 'user';
    $userId = chat_insert($DB, $cols, $pid, $convId, $role, $storedMsg, $sid, $uidParam, null);

    // Save pasted/attached images to disk keyed by message_id + index so they can
    // be shown in the chat history (and re-shown after reload).
    $savedAtt = array(); // [{ i, mime, is_image }]
    if ($userId && count($imageAttachments)) {
        $adir = chat_attach_dir($UPLOAD_DIR, $pid);
        if (!is_dir($adir)) { @mkdir($adir, 0775, true); }
        $i = 0;
        foreach ($imageAttachments as $ia) {
            $raw = base64_decode($ia['data']);
            if ($raw !== false && $raw !== '') {
                if (@file_put_contents($adir . '/' . (int) $userId . '_' . $i . '.' . chat_ext_for_mime($ia['mime']), $raw) !== false) {
                    $savedAtt[] = array('i' => $i, 'mime' => $ia['mime'], 'is_image' => true);
                    $i++;
                }
            }
        }
    }

    // Build context ONLY from this project's metadata + documents.
    $context = '';
    $mr = $DB->query("SELECT server_location, tech_stack, functional_purpose FROM project_metadata WHERE project_id=" . (int) $pid);
    if ($mr && $m = $mr->fetch_assoc()) {
        $context .= "Known metadata — server location: " . $m['server_location'] . "; tech stack: " . $m['tech_stack'] . "; purpose: " . $m['functional_purpose'] . ".\n\n";
    }
    // Image docs are attached as inline_data; text docs go into the context string.
    $dHasMime = false;
    $dmc = $DB->query("SHOW COLUMNS FROM project_documents LIKE 'mime_type'");
    if ($dmc && $dmc->num_rows > 0) { $dHasMime = true; }
    $dCols = $dHasMime ? "file_name, content_text, mime_type, file_path" : "file_name, content_text";

    $imageParts = array();
    $maxImages = 12;
    $dr = $DB->query("SELECT $dCols FROM project_documents WHERE project_id=" . (int) $pid . " ORDER BY created_at ASC");
    if ($dr) {
        while ($d = $dr->fetch_assoc()) {
            $dmime = isset($d['mime_type']) ? $d['mime_type'] : null;
            if (is_inline_doc_mime($dmime)) {
                if (count($imageParts) < $maxImages && !empty($d['file_path'])) {
                    $part = ai_image_part($d['file_path'], $dmime);
                    if ($part) {
                        $imageParts[] = $part;
                        $kind = ($dmime === 'application/pdf') ? 'PDF' : 'IMAGE';
                        $context .= "### " . $kind . ": " . $d['file_name'] . " (attached)\n\n";
                    }
                }
                continue;
            }
            $block = "### FILE: " . $d['file_name'] . "\n" . $d['content_text'] . "\n\n";
            if (strlen($context) + strlen($block) > $AI_CONTEXT_CHAR_LIMIT) {
                $context .= substr($block, 0, max(0, $AI_CONTEXT_CHAR_LIMIT - strlen($context)));
                break;
            }
            $context .= $block;
        }
    }

    // Attachments for this question: images → inline_data; sheet/doc → extracted text.
    foreach ($imageAttachments as $ia) {
        $p = ai_image_part_b64($ia['data'], $ia['mime']);
        if ($p) { $imageParts[] = $p; }
    }
    foreach ($attTextBlocks as $blk) {
        $context .= "\n\n" . $blk . "\n";
    }

    // Repository awareness: for the project's MAPPED GitHub repo(s) only, give the
    // AI the repo file index, then let it pick the most relevant files to actually
    // read (like an editor agent) — plus any files the question names explicitly.
    $repoUrls = array();
    $pj = $DB->query("SELECT repository_url FROM projects WHERE project_id=" . (int) $pid);
    if ($pj && $prow2 = $pj->fetch_assoc()) {
        $rv = $prow2['repository_url'];
        if ($rv) { $dec = json_decode($rv, true); $repoUrls = is_array($dec) ? $dec : array($rv); }
    }
    if (count($repoUrls)) {
        require_once dirname(__FILE__) . '/github.php';

        // Build a combined, noise-filtered file index across the mapped repos.
        $index = array();   // "repo/path" => array(owner, repo, branch, path)
        $listing = array(); // display strings, "repo/path"
        foreach ($repoUrls as $u) {
            if (stripos($u, 'github.com') === false) { continue; }
            $t = github_tree($u, $GITHUB_TOKEN);
            if (!$t) { continue; }
            foreach ($t['paths'] as $node) {
                if (github_path_is_noise($node['path'])) { continue; }
                $disp = $t['repo'] . '/' . $node['path'];
                if (isset($index[$disp])) { continue; }
                $index[$disp] = array('owner' => $t['owner'], 'repo' => $t['repo'], 'branch' => $t['branch'], 'path' => $node['path']);
                $listing[] = $disp;
            }
        }

        if (count($listing)) {
            // 1) Always show the repo file index so the AI knows the codebase.
            $shown = array_slice($listing, 0, 250);
            $context .= "\n\n### PROJECT REPOSITORY FILES (" . count($listing) . " files in the mapped repo(s)):\n" . implode("\n", $shown);
            if (count($listing) > count($shown)) { $context .= "\n… (" . (count($listing) - count($shown)) . " more not shown)"; }

            $wanted = array();
            // 2a) Files named explicitly in the question (e.g. routes/admin.ts).
            if (preg_match_all('~[\w./\-]+\.(?:ts|tsx|js|jsx|mjs|cjs|py|php|go|rs|java|rb|c|cpp|h|hpp|cs|json|ya?ml|sql|sh|css|scss|html|vue|svelte|md|txt|env|ini|toml)\b~i', $msg, $mm)) {
                foreach (array_unique($mm[0]) as $cand) {
                    $cl = strtolower(trim($cand, '/'));
                    if ($cl === '') { continue; }
                    foreach ($listing as $disp) {
                        $pl = strtolower($disp);
                        if ($pl === $cl || substr($pl, -(strlen($cl) + 1)) === '/' . $cl || (strpos($cl, '/') === false && basename($pl) === $cl)) {
                            $wanted[$disp] = true;
                        }
                    }
                }
            }
            // 2b) Let the model choose the most relevant files to read for this question.
            // Skip this extra call for very short/greeting messages to save API quota.
            if ($msg !== '' && $msg !== '(attachment)' && strlen($msg) >= 12) {
                $promptList = array_slice($listing, 0, 400);
                $selPrompt = "A user asked this about a software project:\n\"" . $msg . "\"\n\n"
                    . "Here are the files in the project's GitHub repository(ies):\n" . implode("\n", $promptList) . "\n\n"
                    . "Choose the files whose CONTENTS you most need to read to answer well. "
                    . "Reply with ONLY a JSON array of up to 6 file paths copied EXACTLY from the list above "
                    . "(e.g. [\"repo/src/x.ts\"]). If none are needed, reply []";
                $selOut = ai_generate($selPrompt, true);
                if ($selOut['ok']) {
                    $arr = json_decode(trim($selOut['text']), true);
                    if (is_array($arr)) {
                        foreach ($arr as $p) { if (is_string($p) && isset($index[$p])) { $wanted[$p] = true; } }
                    }
                }
            }
            // 3) Fetch the chosen files (capped count + total bytes) into the context.
            $fetched = 0; $maxFetch = 6; $fileMax = 150 * 1024; $budget = 400 * 1024; $used = 0;
            foreach (array_keys($wanted) as $disp) {
                if ($fetched >= $maxFetch || $used >= $budget) { break; }
                $m = $index[$disp];
                $code = github_get_file($m['owner'], $m['repo'], $m['branch'], $m['path'], $GITHUB_TOKEN, $fileMax);
                if ($code !== null && $code !== '') {
                    $code = substr($code, 0, max(0, $budget - $used));
                    $context .= "\n\n### REPO FILE: " . $disp . "\n```\n" . $code . "\n```";
                    $used += strlen($code);
                    $fetched++;
                }
            }
        }
    }

    // Branch listing: if the question mentions branches, pull each repo's branch
    // list live and tell the AI which branch this project is linked to.
    if (count($repoUrls) && preg_match('~\bbranch(es)?\b~i', $msg)) {
        require_once dirname(__FILE__) . '/github.php';
        foreach ($repoUrls as $u) {
            if (stripos($u, 'github.com') === false) { continue; }
            $b = github_branches($u, $GITHUB_TOKEN);
            if (!$b) { continue; }
            $linked = github_parse_url($u);
            $linkedRef = ($linked && $linked['branch']) ? $linked['branch'] : '(default branch)';
            $context .= "\n\n### BRANCHES in " . $b['repo'] . " (this project is linked to: " . $linkedRef . "):\n- " . implode("\n- ", $b['branches']);
        }
    }

    // Shareday / EOD report lookup from the database (read-only, structured).
    $eodLine = '';
    if (preg_match('~\b(shareday|share[ -]?day|eod|end[ -]of[ -]day|softdev|software\s*development)\b~i', $msg)) {
        require_once dirname(__FILE__) . '/eod.php';
        $params = eod_extract_params($msg);
        if ($params) {
            // The extractor already resolved any relative period into a date range.
            $start = $params['start'];
            $end = $params['end'];
            $nm = $params['name'];
            $isTeam = ($nm === '' || preg_match('/\b(team|all|everyone|softdev|software\s*develop)/i', $nm));

            if ($start === '') {
                $context .= "\n\n### SHAREDAY REPORT: Please specify a date or month for the report.";
            } else if ($isTeam) {
                $rep = eod_fetch_team_report($DB, $start, $end, 300);
                $context .= "\n\n" . eod_build_team_text($rep);
            } else {
                $matches = eod_find_users($DB, $nm);
                if (count($matches) === 0) {
                    $context .= "\n\n### SHAREDAY REPORT: No active employee found matching \"" . $nm . "\".";
                } else if (count($matches) > 1) {
                    $names = array();
                    foreach ($matches as $mm) { $names[] = $mm['name']; }
                    $context .= "\n\n### SHAREDAY REPORT: Multiple employees match \"" . $nm . "\": " . implode('; ', $names) . ". Ask the user which one.";
                } else {
                    $rep = eod_fetch_report($DB, $matches[0]['user_id'], $start, $end, 60);
                    $context .= "\n\n" . eod_build_text($matches[0]['name'], $matches[0]['user_id'], $rep);
                }
            }
            $eodLine = "The SHAREDAY/EOD REPORT block below is LIVE data queried from the database: the employee is matched by name in the employees table and entries are filtered by the created_date column in eod_main. "
                . "It is the ONLY source of truth for shareday/EOD reports — NEVER say you cannot provide the report because of screenshots, missing user-ID mapping, or limited data, and NEVER use uploaded table screenshots for this. "
                . "If the block lists entries, present them; if it says no entries were found for the period, simply state that no shareday entries were found for that date range. "
                . "Do NOT write or suggest SQL. Identify people by their FULL NAME only — never show numeric user IDs. Only include Software Development entries. "
                . "Present each work item on its OWN markdown bullet line (- item), strip leading '##' markers, never join items on one line. "
                . "If it says no employee matched / multiple matches / specify a date, relay that.\n";
        }
    }

    $agentLine = $agentName ? ("You are assisting the logged-in agent \"" . $agentName . "\". Address them by name when natural.\n") : '';
    $repoLine = count($repoUrls)
        ? "This project is linked to GitHub repo(s). The context includes the repository's file index under 'PROJECT REPOSITORY FILES' and the full contents of the most relevant files under 'REPO FILE' (read live from the mapped repo). Use the file index to understand the codebase structure, and quote/explain from the included file contents. If you need a file that wasn't included, name its exact path from the index and ask the user to mention it so it can be fetched. Only the repositories mapped to THIS project are accessible. If a 'BRANCHES in <repo>' block is present, it is the live list of branches.\n"
        : '';
    $system =
        "You are a debugging assistant for the software project \"" . $pn . "\". " . $agentLine . $repoLine . $eodLine .
        "Answer using ONLY the project context below — the project's uploaded documentation, source, linked repositories, " .
        "and any database report data included in the context. If the answer is not present, say so plainly and suggest " .
        "which file or doc to check. Be concise and use markdown (code blocks for code).\n" .
        "STAY ON TOPIC: only answer questions about THIS project (\"" . $pn . "\") — its documents, source, linked " .
        "repositories, or the project/EOD report data provided. If the user's request is unrelated to this project " .
        "(e.g., generic coding tasks like \"make me python code\", general knowledge, trivia, or other off-topic chatter), " .
        "do NOT attempt to answer it. Instead reply briefly with exactly: \"I can only help with questions about this project. " .
        "Please ask something related to \\\"" . $pn . "\\\".\"\n\n" .
        "=== PROJECT CONTEXT START ===\n" . $context . "\n=== PROJECT CONTEXT END ===";

    // Prior history (before the message we just inserted). Scoped to the
    // conversation (if enabled) so each thread is independent; otherwise to the agent.
    $hist = array();
    if ($convEnabled) {
        $histWhere = "project_id=" . (int) $pid . " AND conversation_id=" . (int) $convId . " AND message_id < " . (int) $userId;
    } else {
        $histWhere = "project_id=" . (int) $pid . " AND message_id < " . (int) $userId;
        if (!empty($cols['user_id'])) { $histWhere .= " AND user_id=" . (int) $agentId; }
    }
    $hr = $DB->query("SELECT sender_role, message_payload FROM chat_logs WHERE " . $histWhere . " ORDER BY message_id ASC");
    if ($hr) {
        while ($h = $hr->fetch_assoc()) {
            // Don't feed prior error notices back into the model's context.
            if (strpos($h['message_payload'], "\xE2\x9A\xA0") === 0) { continue; }
            $hist[] = array('role' => $h['sender_role'], 'text' => $h['message_payload']);
        }
    }

    // Streaming (SSE) when ?stream=1: emit text deltas as they arrive, then fall
    // through to the normal persistence/finalize code and emit a final "done" event.
    $streamMode = isset($_GET['stream']) && $_GET['stream'];
    $sseEmit = null;
    if ($streamMode) {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no'); // ask nginx/proxies not to buffer
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', '1'); }
        while (ob_get_level() > 0) { @ob_end_flush(); }
        @ob_implicit_flush(true);
        $sseEmit = function ($obj) { echo 'data: ' . json_encode($obj) . "\n\n"; @flush(); };
        $k0 = $userKeys[0];
        $GEMINI_API_KEY = $k0['api_key'];
        $GEMINI_MODEL = !empty($k0['ai_model']) ? $k0['ai_model'] : $cfgModel;
        $out = ai_chat_stream(
            $system, $hist, $msg, $imageParts,
            function ($t) use ($sseEmit) { $sseEmit(array('type' => 'delta', 'text' => $t)); },
            function ($t) use ($sseEmit) { $sseEmit(array('type' => 'thought', 'text' => $t)); }
        );
    } else {
        // Build attempts: for each key, try its model, then Flash-Lite, then
        // 2.0-flash — each is a separate quota/backend, so a quota limit (429) or
        // an overloaded model (503) auto-falls forward to the next.
        $attempts = array();
        foreach ($userKeys as $uk) {
            $m = !empty($uk['ai_model']) ? $uk['ai_model'] : $cfgModel;
            $seen = array();
            foreach (array($m, 'gemini-2.5-flash-lite', 'gemini-2.0-flash') as $mm) {
                if (isset($seen[$mm])) { continue; }
                $seen[$mm] = true;
                $attempts[] = array('key' => $uk['api_key'], 'model' => $mm);
            }
        }
        // Try in order; on a transient/limit error (429/503/500) move to the next.
        $out = array('ok' => false, 'error' => 'No API key.', 'code' => 0);
        foreach ($attempts as $at) {
            $GEMINI_API_KEY = $at['key'];
            $GEMINI_MODEL = $at['model'];
            $out = ai_chat($system, $hist, $msg, $imageParts);
            if (!empty($out['ok'])) { break; }
            $c = isset($out['code']) ? (int) $out['code'] : 0;
            if (!in_array($c, array(429, 503, 500), true)) { break; } // hard error → stop
        }
    }
    // On failure, persist a visible error reply (instead of erroring the request)
    // so the user sees what went wrong in the conversation — live and after reload.
    $aiError = !$out['ok'];
    if ($aiError) {
        $warn = "\xE2\x9A\xA0\xEF\xB8\x8F ";
        $ecode = isset($out['code']) ? (int) $out['code'] : 0;
        if ($ecode === 429) {
            $reply = $warn . "Your Gemini API key has reached its limit (free-tier daily quota or rate limit). "
                . "Add another API key or switch the model on the Dashboard, enable billing on your key, or try again later.";
        } else if ($ecode === 503 || $ecode === 500) {
            $reply = $warn . "The AI model is busy right now (high demand). Please send your message again in a few seconds.";
        } else {
            $reply = $warn . "The assistant couldn't respond: " . $out['error'];
        }
        $usageJson = null;
    } else {
        $reply = $out['text'];
        $usageJson = (isset($out['usage']) && $out['usage'] !== null) ? json_encode($out['usage']) : null;
    }

    $airole = 'ai';
    $aiId = chat_insert($DB, $cols, $pid, $convId, $airole, $reply, $sid, $uidParam, $usageJson);

    // Log token usage to the persistent ledger so it survives conversation/project
    // deletion (the dashboard reads the ledger, not chat_logs).
    if (isset($out['usage']) && $out['usage'] !== null) { ai_usage_log($pid, $out['usage'], 'chat'); }

    // For a brand-new thread, title it from the first question. Done locally
    // (no extra AI call) to conserve the API quota — take the first ~8 words.
    if ($convEnabled && $convId > 0 && $createdNew) {
        $t = trim(preg_replace('/\s+/', ' ', $msg));
        $words = explode(' ', $t);
        if (count($words) > 8) { $t = implode(' ', array_slice($words, 0, 8)) . '…'; }
        $t = substr($t, 0, 80);
        if ($t !== '' && $t !== '(attachment)') {
            $convTitle = $t;
            $upd = $DB->prepare("UPDATE chat_conversations SET title=? WHERE conversation_id=?");
            $upd->bind_param('si', $convTitle, $convId);
            $upd->execute();
            $upd->close();
        }
    }

    // Touch the conversation so it sorts to the top of the thread list.
    if ($convEnabled && $convId > 0) {
        $DB->query("UPDATE chat_conversations SET updated_at=NOW() WHERE conversation_id=" . (int) $convId);
    }

    // Construct the response from known values rather than reading back — the
    // MaxScale proxy may route a read after an autocommit insert to a replica
    // that hasn't replicated yet. (timestamp is display-only; message_id ordering
    // is preserved since aiId > userId.)
    $now = date('Y-m-d H:i:s');
    $u = array('message_id' => (int) $userId, 'project_id' => (int) $pid, 'sender_role' => 'user', 'message_payload' => $msg, 'token_used' => $sid, 'user_id' => $uidParam, 'timestamp' => $now);
    if (count($savedAtt)) { $u['attachments'] = $savedAtt; }
    $a = array('message_id' => (int) $aiId, 'project_id' => (int) $pid, 'sender_role' => 'ai', 'message_payload' => $reply, 'token_used' => $sid, 'user_id' => $uidParam, 'timestamp' => $now);
    if ($aiError) { $a['is_error'] = true; }

    if ($streamMode) {
        $sseEmit(array('type' => 'done', 'userMessage' => $u, 'aiMessage' => $a, 'conversation_id' => (int) $convId, 'conversation_title' => $convTitle));
        exit;
    }
    send_json(array('userMessage' => $u, 'aiMessage' => $a, 'conversation_id' => (int) $convId, 'conversation_title' => $convTitle), 200);
}

fail('Method not allowed.', 405);

// ── helpers ────────────────────────────────────────────────────────────────
/** Where a chat message's pasted/attached image is stored on disk. Keyed by the
 *  message_id so no schema change is needed — we just glob for {id}.* on read. */
function chat_attach_dir($UPLOAD_DIR, $pid) { return $UPLOAD_DIR . '/chat/' . (int) $pid; }
/** All attachment files for a message, ordered (multi: {mid}_{i}.*, legacy: {mid}.*). */
function chat_attach_list($UPLOAD_DIR, $pid, $mid) {
    $dir = chat_attach_dir($UPLOAD_DIR, $pid);
    $files = array();
    $g1 = @glob($dir . '/' . (int) $mid . '_*.*');
    if (is_array($g1)) { foreach ($g1 as $f) { $files[] = $f; } }
    $g2 = @glob($dir . '/' . (int) $mid . '.*');
    if (is_array($g2)) { foreach ($g2 as $f) { $files[] = $f; } }
    sort($files); // "{mid}.ext" < "{mid}_0.ext" < "{mid}_1.ext"
    return $files;
}
function chat_ext_for_mime($mime) {
    $map = array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/bmp' => 'bmp');
    return isset($map[$mime]) ? $map[$mime] : 'img';
}
function chat_mime_for_file($path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = array('png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp');
    return isset($map[$ext]) ? $map[$ext] : 'application/octet-stream';
}

/** Set of existing chat_logs column names (so we degrade gracefully if the
 *  token_used / user_id columns haven't been added to the live table yet). */
function chat_cols($DB) {
    $cols = array();
    $r = $DB->query("SHOW COLUMNS FROM chat_logs");
    if ($r) { while ($c = $r->fetch_assoc()) { $cols[$c['Field']] = true; } }
    return $cols;
}

/** Insert a chat row, including conversation_id / token_used / user_id /
 *  usage_meta_data only if those columns exist. */
function chat_insert($DB, $cols, $pid, $convId, $role, $payload, $tok, $uid, $usage) {
    $fields = array('project_id', 'sender_role', 'message_payload');
    $place  = array('?', '?', '?');
    $types  = 'iss';
    $vals   = array($pid, $role, $payload);
    if (!empty($cols['conversation_id']) && $convId) { $fields[] = 'conversation_id'; $place[] = '?'; $types .= 'i'; $vals[] = $convId; }
    if (!empty($cols['token_used']))      { $fields[] = 'token_used';      $place[] = '?'; $types .= 's'; $vals[] = $tok; }
    if (!empty($cols['user_id']))         { $fields[] = 'user_id';         $place[] = '?'; $types .= 'i'; $vals[] = $uid; }
    if (!empty($cols['usage_meta_data'])) { $fields[] = 'usage_meta_data'; $place[] = '?'; $types .= 's'; $vals[] = $usage; }

    $sql = "INSERT INTO chat_logs (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $place) . ")";
    $stmt = $DB->prepare($sql);

    // bind_param needs arguments by reference.
    $bindArgs = array($types);
    for ($i = 0; $i < count($vals); $i++) { $bindArgs[] = &$vals[$i]; }
    call_user_func_array(array($stmt, 'bind_param'), $bindArgs);

    $stmt->execute();
    $id = $DB->insert_id;
    $stmt->close();
    return $id;
}

/** Name of the logged-in agent (employees), or null if not logged in. */
function chat_agent_name($DB) {
    $info = isset($_SESSION['px_login_info']['info']) ? $_SESSION['px_login_info']['info'] : null;
    $uid = ($info && isset($info['user_id'])) ? (int) $info['user_id'] : 0;
    if ($uid <= 0) { return null; }
    $er = $DB->query("SELECT first_name, last_name, alias FROM callbox_pipeline2.employees WHERE user_id=" . (int) $uid . " LIMIT 1");
    if ($er && $e = $er->fetch_assoc()) {
        $nm = trim($e['first_name'] . ' ' . $e['last_name']);
        if ($nm !== '') { return $nm; }
        if (!empty($e['alias'])) { return $e['alias']; }
    }
    return (isset($info['user_name']) && $info['user_name'] !== '') ? $info['user_name'] : ('User ' . $uid);
}
?>
