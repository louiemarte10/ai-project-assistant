<?php
/**
 * App-styled login for the Project Assistant.
 *
 * The credential handling is still 100% Pipeline X (px_login): this page only
 * provides a dark, on-brand form. It reuses the framework's own client-side
 * hashing (login/sha256.js → SHA256.hmac(password, challenge)) so the posted
 * auth_hash is byte-identical to the standard Pipeline login — meaning password
 * validation, the 5-attempt lockout, and Google 2FA all behave exactly the same.
 *
 * Flow:
 *   - GET (not logged in): render this styled form.
 *   - POST: hand auth_name/auth_hash to px_login::init() to validate.
 *       success  -> redirect to the app
 *       2FA/fail -> px_login renders the standard Pipeline page (fallback)
 */
ini_set('display_errors', '0');
error_reporting(E_ERROR | E_PARSE);

require "{$_SERVER['DOCUMENT_ROOT']}/config/pipeline-x.php";

$APP_URL = '/playground/doromal/projects-assistant-tool/';

if (px_login::is_logged_in()) {
    header('Location: ' . $APP_URL);
    exit;
}

if (!empty($_POST['auth_name'])) {
    // Validate via the framework (handles success, lockout, and 2FA identically).
    px_login::init();
    if (px_login::is_logged_in()) {
        header('Location: ' . $APP_URL);
        exit;
    }
    // On wrong password / 2FA, px_login::init() already rendered the standard
    // Pipeline page — stop here.
    exit;
}

if (empty($_SESSION['pipelinex_auth_challenge'])) {
    $_SESSION['pipelinex_auth_challenge'] = sha1(mt_rand());
}
$challenge = $_SESSION['pipelinex_auth_challenge'];

// Force UTF-8 so the browser doesn't mis-decode (Apache may default to ISO-8859-1,
// which overrides the <meta charset> and garbles non-ASCII characters).
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Sign in · Project Assistant</title>
  <style>
    :root {
      --bg: #0b0f17; --surface: #1c2230; --surface2: #10151e;
      --border: #2b3547; --text: #e5e9f0; --muted: #94a3b8; --brand: #3b82f6;
    }
    * { box-sizing: border-box; }
    html, body { height: 100%; margin: 0; }
    body {
      background: var(--bg); color: var(--text);
      font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
      display: flex; align-items: center; justify-content: center; padding: 16px;
    }
    .card {
      width: 100%; max-width: 380px; background: var(--surface);
      border: 1px solid var(--border); border-radius: 14px; padding: 28px;
      box-shadow: 0 12px 40px rgba(0,0,0,.45);
    }
    .brand { display: flex; align-items: center; gap: 8px; font-size: 18px; font-weight: 600; }
    .sub { color: var(--muted); font-size: 13px; margin: 6px 0 20px; }
    label.fld { display: block; font-size: 13px; color: var(--muted); margin: 14px 0 4px; }
    input[type=text], input[type=password] {
      width: 100%; background: var(--surface2); border: 1px solid var(--border);
      color: var(--text); border-radius: 8px; padding: 10px 12px; font-size: 14px; outline: none;
    }
    input[type=text]:focus, input[type=password]:focus { border-color: var(--brand); }
    .pass-wrap { position: relative; }
    .toggle {
      position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
      background: none; border: 0; color: var(--muted); cursor: pointer; font-size: 14px; padding: 4px;
    }
    .remember { display: flex; align-items: center; gap: 8px; color: var(--muted); font-size: 13px; margin: 16px 0; }
    .remember input { accent-color: var(--brand); }
    .btn {
      width: 100%; background: var(--brand); color: #fff; border: 0; border-radius: 8px;
      padding: 11px; font-size: 14px; font-weight: 600; cursor: pointer;
    }
    .btn:hover { filter: brightness(1.05); }
    .err { background: rgba(220,38,38,.15); color: #fca5a5; border: 1px solid rgba(220,38,38,.4);
           border-radius: 8px; padding: 8px 10px; font-size: 13px; margin-bottom: 14px; }
  </style>
</head>
<body>
  <form id="f_login" class="card" action="?" method="post" autocomplete="on">
    <div class="brand">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#f1b500" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76" fill="#f1b500" stroke="none"/></svg>
      <span>Project Assistant</span>
    </div>
    <p class="sub">Sign in with your Pipeline account</p>

    <label class="fld" for="auth_name">Username</label>
    <input type="text" id="auth_name" name="auth_name" autocomplete="username" autofocus />

    <label class="fld" for="pass">Password</label>
    <div class="pass-wrap">
      <input type="password" id="pass" autocomplete="current-password" />
      <button type="button" class="toggle" id="toggle" aria-label="Show password"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg></button>
    </div>

    <input type="hidden" name="auth_hash" id="auth_hash" value="" />
    <input type="hidden" id="auth_challenge" value="<?php echo htmlspecialchars($challenge, ENT_QUOTES); ?>" />

    <label class="remember"><input type="checkbox" name="auth_remember" id="auth_remember" value="1" /> Remember me on this computer</label>

    <button type="submit" class="btn">Log in</button>
  </form>

  <!-- Reuse the framework's jQuery + SHA256 so the hash matches the Pipeline login exactly -->
  <script src="/framework/js/3rd-party/jquery/jquery-2.1.4.min.js"></script>
  <script src="/framework/libraries/login/sha256.js"></script>
  <script>
    $(function () {
      $('#toggle').on('click', function () {
        var p = $('#pass');
        p.attr('type', p.attr('type') === 'password' ? 'text' : 'password');
      });
      $('#f_login').on('submit', function (e) {
        if (!$('#auth_name').val().trim()) { alert('Please enter username.'); $('#auth_name').focus(); e.preventDefault(); return false; }
        if (!$('#pass').val()) { alert('Please enter password.'); $('#pass').focus(); e.preventDefault(); return false; }
        // Matches the framework login exactly: HMAC( SHA256(password), challenge ).
        // (The inner SHA256.hash is required — the server compares against sha256(pass_key).)
        $('#auth_hash').val(SHA256.hmac(SHA256.hash($('#pass').val()), $('#auth_challenge').val()));
      });
    });
  </script>
</body>
</html>
