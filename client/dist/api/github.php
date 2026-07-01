<?php
/**
 * GitHub import helpers — pull a repo's .md and .env* files via the GitHub API.
 * PHP 5.3 compatible. A token is required for private repos.
 */

function gh_headers($token) {
    $h = array(
        'User-Agent: Callbox-Project-Assistant',
        'Accept: application/vnd.github+json',
    );
    if ($token) { $h[] = 'Authorization: Bearer ' . $token; }
    return $h;
}

/** GET a GitHub API URL and decode JSON (or null on any non-2xx / error). */
function gh_get_json($url, $token) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, gh_headers($token));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code < 200 || $code >= 300) { return null; }
    return json_decode($resp, true);
}

/** Parse owner/repo/ref from a GitHub URL, or null if not GitHub.
 *  'branch' holds the RAW ref after /tree/ — it may contain slashes (a branch
 *  name like feature/chatbot) and/or a trailing subpath; resolve it with
 *  gh_resolve_ref() before using it against the API. */
function github_parse_url($url) {
    if (!preg_match('~github\.com/([^/]+)/([^/#?]+)~i', $url, $m)) { return null; }
    $owner = $m[1];
    $repo = preg_replace('/\.git$/i', '', $m[2]);
    $branch = null;
    // Capture everything after /tree/ (stop only at ? or #); keep slashes so a
    // branch like "feature/chatbot" survives — github_parse_url's old [^/]+ chopped it to "feature".
    if (preg_match('~/tree/([^#?]+)~', $url, $bm)) { $branch = rtrim($bm[1], '/'); }
    return array('owner' => $owner, 'repo' => $repo, 'branch' => $branch);
}

/** Resolve a raw URL ref (which may include slashes and/or a subpath) to the
 *  actual branch name. A ref with slashes is ambiguous — "feature/chatbot" could
 *  be one branch, or branch "feature" + path "chatbot" — so we fetch the branch
 *  list and pick the longest branch name that is a prefix of the ref. */
function gh_resolve_ref($owner, $repo, $ref, $token) {
    if ($ref === null || $ref === '') {
        $info = gh_get_json("https://api.github.com/repos/$owner/$repo", $token);
        return ($info && isset($info['default_branch'])) ? $info['default_branch'] : 'main';
    }
    if (strpos($ref, '/') === false) { return $ref; }
    $branches = gh_get_json("https://api.github.com/repos/$owner/$repo/branches?per_page=100", $token);
    if (is_array($branches)) {
        $best = null;
        foreach ($branches as $b) {
            if (!isset($b['name'])) { continue; }
            $name = $b['name'];
            if ($ref === $name || strpos($ref, $name . '/') === 0) {
                if ($best === null || strlen($name) > strlen($best)) { $best = $name; }
            }
        }
        if ($best !== null) { return $best; }
    }
    return $ref; // fall back to treating the whole ref as the branch
}

/** List a repo's branch names. Returns array('owner','repo','branches'=>[..]) or null. */
function github_branches($url, $token) {
    $p = github_parse_url($url);
    if (!$p) { return null; }
    $branches = gh_get_json("https://api.github.com/repos/" . $p['owner'] . "/" . $p['repo'] . "/branches?per_page=100", $token);
    if (!is_array($branches)) { return null; }
    $names = array();
    foreach ($branches as $b) { if (isset($b['name'])) { $names[] = $b['name']; } }
    return array('owner' => $p['owner'], 'repo' => $p['repo'], 'branches' => $names);
}

/** Document filename prefix for an imported repo file. Includes the branch only
 *  when the URL explicitly named one (via /tree/), so the same repo linked on
 *  multiple branches doesn't collide while default-branch imports stay clean
 *  (e.g. "datahub-new/README.md" vs "datahub-new@feature/chatbot/README.md"). */
function gh_doc_prefix($url, $imp) {
    $p = github_parse_url($url);
    $explicit = ($p && $p['branch'] !== null && $p['branch'] !== '');
    return $explicit ? ($imp['repo'] . '@' . $imp['branch'] . '/') : ($imp['repo'] . '/');
}

/** True for repo paths that aren't worth listing/reading (deps, build output,
 *  lockfiles, binaries) so the AI's file index stays focused on real source. */
function github_path_is_noise($path) {
    $p = strtolower($path);
    if (strpos($p, 'node_modules/') !== false) { return true; }
    if (strpos($p, '.next/') !== false || strpos($p, 'dist/') !== false || strpos($p, 'build/') !== false || strpos($p, 'vendor/') !== false || strpos($p, '.git/') !== false) { return true; }
    if (preg_match('/(?:^|\/)(?:package-lock\.json|yarn\.lock|pnpm-lock\.yaml|composer\.lock)$/', $p)) { return true; }
    if (preg_match('/\.(?:png|jpe?g|gif|webp|bmp|ico|svg|woff2?|ttf|eot|otf|map|lock|pdf|zip|gz|mp4|mp3|woff)$/', $p)) { return true; }
    if (preg_match('/\.min\.(?:js|css)$/', $p)) { return true; }
    return false;
}

/** rawurlencode each path segment but keep the slashes. */
function gh_encode_path($path) {
    $parts = explode('/', $path);
    foreach ($parts as $i => $seg) { $parts[$i] = rawurlencode($seg); }
    return implode('/', $parts);
}

/**
 * Import the repo's markdown + env files.
 * Returns array('files'=>[{name,path,text,size}], 'branch'=>..) or array('error'=>..).
 */
function github_import($url, $token, $maxFiles, $maxBytes) {
    $p = github_parse_url($url);
    if (!$p) { return array('error' => 'Not a GitHub URL'); }
    $owner = $p['owner']; $repo = $p['repo'];
    $branch = gh_resolve_ref($owner, $repo, $p['branch'], $token);

    $tree = gh_get_json("https://api.github.com/repos/$owner/$repo/git/trees/" . rawurlencode($branch) . "?recursive=1", $token);
    if (!$tree || !isset($tree['tree'])) { return array('error' => 'Could not read the repository file tree.'); }

    $paths = array();
    foreach ($tree['tree'] as $node) {
        if (!isset($node['type']) || $node['type'] !== 'blob') { continue; }
        $path = $node['path'];
        $base = basename($path);
        $size = isset($node['size']) ? (int) $node['size'] : 0;
        $isMd  = preg_match('/\.md$/i', $base);
        $isEnv = (strpos($base, '.env') === 0) || preg_match('/\.env$/i', $base);
        if (!$isMd && !$isEnv) { continue; }
        if ($size > $maxBytes) { continue; }
        $paths[] = $path;
        if (count($paths) >= $maxFiles) { break; }
    }

    $files = array();
    foreach ($paths as $path) {
        $c = gh_get_json("https://api.github.com/repos/$owner/$repo/contents/" . gh_encode_path($path) . "?ref=" . rawurlencode($branch), $token);
        if (!$c || !isset($c['content'])) { continue; }
        $text = base64_decode(str_replace("\n", '', $c['content']));
        if ($text === false || $text === '') { continue; }
        $files[] = array('name' => basename($path), 'path' => $path, 'text' => $text, 'size' => strlen($text));
    }

    return array('files' => $files, 'branch' => $branch, 'owner' => $owner, 'repo' => $repo);
}

/**
 * Full file tree of a repo (all blob paths), cached to a temp file for 10 min to
 * avoid re-hitting the API on every chat message. Returns
 * array('owner','repo','branch','paths'=>[{path,size}]) or null.
 */
function github_tree($url, $token) {
    $p = github_parse_url($url);
    if (!$p) { return null; }
    $owner = $p['owner']; $repo = $p['repo'];
    $branch = gh_resolve_ref($owner, $repo, $p['branch'], $token);

    $cacheFile = sys_get_temp_dir() . '/ghtree_' . md5($owner . '/' . $repo . '/' . $branch) . '.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < 600)) {
        $cached = json_decode(@file_get_contents($cacheFile), true);
        if (is_array($cached)) { return $cached; }
    }

    $tree = gh_get_json("https://api.github.com/repos/$owner/$repo/git/trees/" . rawurlencode($branch) . "?recursive=1", $token);
    if (!$tree || !isset($tree['tree'])) { return null; }
    $paths = array();
    foreach ($tree['tree'] as $node) {
        if (!isset($node['type']) || $node['type'] !== 'blob') { continue; }
        $paths[] = array('path' => $node['path'], 'size' => isset($node['size']) ? (int) $node['size'] : 0);
    }
    $result = array('owner' => $owner, 'repo' => $repo, 'branch' => $branch, 'paths' => $paths);
    @file_put_contents($cacheFile, json_encode($result));
    return $result;
}

/** Fetch one file's text content from a repo (null if missing or > $maxBytes). */
function github_get_file($owner, $repo, $branch, $path, $token, $maxBytes) {
    $c = gh_get_json("https://api.github.com/repos/$owner/$repo/contents/" . gh_encode_path($path) . "?ref=" . rawurlencode($branch), $token);
    if (!$c || !isset($c['content'])) { return null; }
    if ($maxBytes && isset($c['size']) && (int) $c['size'] > $maxBytes) { return null; }
    $t = base64_decode(str_replace("\n", '', $c['content']));
    return ($t === false) ? null : $t;
}
?>
