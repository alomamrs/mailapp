<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth/Security.php';
require_once __DIR__ . '/auth/Portal.php';

Security::startSecureSession();
Security::sendHeaders();

// Already logged in — go straight to app
if (Portal::isAuthed()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    Security::verifyCsrf();

    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    // Rate limit: max 5 attempts per IP per minute, lockout 10 min
    $rateKey = 'login_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!Security::rateLimit($rateKey, 5, 60, 600)) {
        $error = 'Too many login attempts. Please wait 10 minutes before trying again.';
    } elseif (Portal::login($user, $pass)) {
        Security::rateLimitClear($rateKey);
        Security::regenerateSession();
        header('Location: index.php');
        exit;
    } else {
        // Small delay to slow brute force
        usleep(400000); // 0.4s
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,viewport-fit=cover"/>
  <meta name="apple-mobile-web-app-capable" content="yes"/>
  <meta name="theme-color" content="#0d0d14"/>
  <title>Sign in — Mail Portal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=Syne:wght@400;500;600;700&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { margin:0; padding:0; box-sizing:border-box }
    :root {
      --bg:       #0d0d14;
      --surface:  #13131f;
      --surface2: #1a1a2e;
      --border:   #2a2a3e;
      --text:     #e8e8f0;
      --muted:    #6b6b8a;
      --gold:     #d4a843;
      --gold-lt:  rgba(212,168,67,.08);
      --danger:   #e05252;
    }
    html, body {
      height:100%; font-family:'Syne',sans-serif;
      background:var(--bg); color:var(--text);
      overflow:hidden;
    }
    .card { margin: env(safe-area-inset-top,0px) env(safe-area-inset-right,0px) env(safe-area-inset-bottom,0px) env(safe-area-inset-left,0px); }

    /* ── Animated background ── */
    .bg {
      position:fixed; inset:0; z-index:0;
      background:
        radial-gradient(ellipse 70% 60% at 15% 15%, rgba(212,168,67,.06) 0%, transparent 60%),
        radial-gradient(ellipse 60% 70% at 85% 85%, rgba(99,89,255,.07) 0%, transparent 60%),
        var(--bg);
    }
    .bg-grid {
      position:fixed; inset:0; z-index:0;
      background-image:
        linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px);
      background-size:52px 52px;
    }

    /* ── Page layout ── */
    .page {
      position:relative; z-index:10;
      height:100vh; display:flex; align-items:center; justify-content:center;
      padding:1rem;
    }

    /* ── Card ── */
    .card {
      width:100%; max-width:400px;
      background:var(--surface);
      border:1px solid var(--border);
      border-radius:14px;
      padding:2.5rem 2.75rem 2.25rem;
      box-shadow: 0 40px 80px rgba(0,0,0,.5), 0 0 0 1px rgba(255,255,255,.03);
      animation: cardIn .45s cubic-bezier(.22,1,.36,1) both;
    }
    @keyframes cardIn {
      from { opacity:0; transform:translateY(22px) scale(.97) }
      to   { opacity:1; transform:translateY(0) scale(1) }
    }

    /* ── Logo / brand ── */
    .brand {
      display:flex; align-items:center; gap:.75rem;
      margin-bottom:2rem;
    }
    .brand-icon {
      width:40px; height:40px; border-radius:10px;
      background:linear-gradient(135deg, var(--gold), #9a6f20);
      display:flex; align-items:center; justify-content:center;
      font-size:1.1rem; flex-shrink:0;
      box-shadow: 0 4px 16px rgba(212,168,67,.25);
    }
    .brand-name {
      font-family:'Cormorant Garamond', serif;
      font-size:1.5rem; font-weight:600; color:var(--text);
      letter-spacing:-.01em;
    }
    .brand-name span { color:var(--gold); }

    .card-title {
      font-size:1.05rem; font-weight:600; color:var(--text);
      margin-bottom:.3rem;
    }
    .card-sub {
      font-size:.78rem; color:var(--muted); margin-bottom:1.75rem; line-height:1.5;
    }

    /* ── Form ── */
    .field { margin-bottom:1.1rem; }
    .field-label {
      display:block;
      font-family:'DM Mono', monospace;
      font-size:.6rem; text-transform:uppercase; letter-spacing:1.5px;
      color:var(--muted); margin-bottom:.45rem;
    }
    .field-input {
      width:100%;
      background:var(--surface2);
      border:1.5px solid var(--border);
      border-radius:8px;
      padding:.65rem .85rem;
      font-size:.88rem; font-family:'Syne', sans-serif;
      color:var(--text); outline:none;
      transition:border-color .2s, box-shadow .2s;
    }
    .field-input:focus {
      border-color:var(--gold);
      box-shadow:0 0 0 3px rgba(212,168,67,.12);
    }
    .field-input::placeholder { color:var(--muted); }
    .field-input:-webkit-autofill {
      -webkit-box-shadow:0 0 0 100px var(--surface2) inset !important;
      -webkit-text-fill-color:var(--text) !important;
      caret-color:var(--text);
    }

    /* Password wrapper */
    .pw-wrap { position:relative; }
    .pw-toggle {
      position:absolute; right:.75rem; top:50%; transform:translateY(-50%);
      background:none; border:none; color:var(--muted); cursor:pointer;
      font-size:.82rem; padding:.2rem; transition:color .15s;
    }
    .pw-toggle:hover { color:var(--gold); }

    /* ── Error ── */
    .error-box {
      background:rgba(224,82,82,.1); border:1px solid rgba(224,82,82,.3);
      border-radius:7px; padding:.6rem .85rem; margin-bottom:1.1rem;
      font-size:.78rem; color:var(--danger);
      display:flex; align-items:center; gap:.5rem;
    }

    /* ── Submit ── */
    .submit-btn {
      width:100%; padding:.75rem;
      background:var(--gold); color:#1a1200;
      border:none; border-radius:8px;
      font-family:'Syne', sans-serif; font-size:.88rem; font-weight:700;
      cursor:pointer; transition:all .15s; letter-spacing:.02em;
      margin-top:.25rem;
      display:flex; align-items:center; justify-content:center; gap:.5rem;
    }
    .submit-btn:hover { background:#e8bc50; box-shadow:0 4px 20px rgba(212,168,67,.3); }
    .submit-btn:active { transform:scale(.98); }
    .submit-btn:disabled { opacity:.5; cursor:not-allowed; }

    /* Spinner */
    .spinner { width:14px; height:14px; border:2px solid rgba(26,18,0,.3); border-top-color:#1a1200; border-radius:50%; animation:spin .6s linear infinite; display:none; }
    @keyframes spin { to { transform:rotate(360deg) } }

    /* ── Footer ── */
    .card-footer {
      margin-top:1.5rem; padding-top:1.25rem; border-top:1px solid var(--border);
      text-align:center; font-size:.7rem; color:var(--muted);
    }
  </style>
</head>
<body>
<div class="bg"></div>
<div class="bg-grid"></div>

<div class="page">
  <div class="card">

    <div class="brand">
      <div class="brand-icon">✉</div>
      <div class="brand-name">Mail<span>.</span></div>
    </div>

    <div class="card-title">Welcome back</div>
    <div class="card-sub">Sign in to access your mail portal</div>

    <?php if ($error): ?>
    <div class="error-box">
      <span>⚠</span> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="login.php" onsubmit="handleSubmit(event)">
      <?= Security::csrfField() ?>
      <div class="field">
        <label class="field-label" for="username">Username</label>
        <input class="field-input" id="username" name="username" type="text"
          autocomplete="username" placeholder="Enter your username"
          value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus/>
      </div>

      <div class="field">
        <label class="field-label" for="password">Password</label>
        <div class="pw-wrap">
          <input class="field-input" id="password" name="password" type="password"
            autocomplete="current-password" placeholder="Enter your password" required/>
          <button type="button" class="pw-toggle" onclick="togglePw()" id="pw-eye">👁</button>
        </div>
      </div>

      <button type="submit" class="submit-btn" id="submit-btn">
        <div class="spinner" id="spinner"></div>
        <span id="submit-text">Sign in →</span>
      </button>
    </form>

    <div class="card-footer">
      Secure portal · <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') ?>
    </div>
  </div>
</div>

<script>
function togglePw() {
  const inp = document.getElementById('password');
  const eye = document.getElementById('pw-eye');
  inp.type = inp.type === 'password' ? 'text' : 'password';
  eye.textContent = inp.type === 'password' ? '👁' : '🙈';
}
function handleSubmit(e) {
  const btn = document.getElementById('submit-btn');
  const sp  = document.getElementById('spinner');
  const tx  = document.getElementById('submit-text');
  btn.disabled = true;
  sp.style.display = 'block';
  tx.textContent = 'Signing in…';
}
// Auto-focus password if username already filled
window.addEventListener('DOMContentLoaded', () => {
  const u = document.getElementById('username');
  const p = document.getElementById('password');
  if (u.value) p.focus();
});
</script>
</body>
</html>
