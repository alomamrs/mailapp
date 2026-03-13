<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth/Auth.php';
require_once __DIR__ . '/auth/Portal.php';
Portal::guard();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Sign in — Mail</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@300;400;600&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet"/>
  <style>
    *{margin:0;padding:0;box-sizing:border-box}
    html,body{height:100%;font-family:'Segoe UI',system-ui,sans-serif;overflow:hidden}

    /* ═══════════════════════════════════════════════════════════
       BACKGROUND — edit this section to customise the look
       Options:
         A) Solid colour      → background: #0078d4;
         B) Gradient          → background: linear-gradient(...);
         C) Image             → background: url('your-image.jpg') center/cover no-repeat;
         D) External HTML bg  → set USE_HTML_BG = true below and edit bg.html
       ═══════════════════════════════════════════════════════════ */
    .bg {
      position: fixed;
      inset: 0;
      /* ▼▼▼ EDIT BELOW ▼▼▼ */
      background: #0078d4;
      background-image:
        radial-gradient(ellipse 80% 60% at 10% 20%, #106ebe 0%, transparent 60%),
        radial-gradient(ellipse 60% 80% at 90% 80%, #005a9e 0%, transparent 60%),
        radial-gradient(ellipse 40% 40% at 50% 50%, #0091ff22 0%, transparent 70%);
      /* ▲▲▲ EDIT ABOVE ▲▲▲ */
      overflow: hidden;
    }

    /* If using a background image uncomment and edit this: */
    /*
    .bg {
      position: fixed;
      inset: 0;
      background: url('bg.jpg') center / cover no-repeat;
    }
    .bg-grid, .bg-shapes, .orb { display: none; }
    */

    /* Grid overlay */
    .bg-grid {
      position:absolute;inset:0;
      background-image:
        linear-gradient(rgba(255,255,255,.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.04) 1px, transparent 1px);
      background-size:48px 48px;
    }

    /* Animated orbs */
    .orb {
      position:absolute;border-radius:50%;
      filter:blur(80px);opacity:.18;
      animation:orb 12s ease-in-out infinite alternate;
    }
    .orb1{width:600px;height:600px;background:#40b4ff;top:-200px;left:-200px;animation-delay:0s}
    .orb2{width:500px;height:500px;background:#ffffff;top:50%;right:-100px;animation-delay:-4s}
    .orb3{width:400px;height:400px;background:#00b4d8;bottom:-150px;left:30%;animation-delay:-8s}
    @keyframes orb{from{transform:scale(1) translate(0,0)}to{transform:scale(1.2) translate(30px,20px)}}

    /* Floating shapes */
    .bg-shapes{position:absolute;inset:0;overflow:hidden}
    .shape{position:absolute;opacity:0.07;animation:float linear infinite}
    .shape svg{display:block}
    @keyframes float{
      0%  {transform:translateY(100vh) rotate(0deg);opacity:0}
      5%  {opacity:.07}
      95% {opacity:.07}
      100%{transform:translateY(-200px) rotate(360deg);opacity:0}
    }

    /* ── CARD ── */
    .page{
      position:relative;z-index:10;
      height:100vh;display:flex;align-items:center;justify-content:center;
      padding:1rem;
    }
    .card{
      background:#fff;border-radius:8px;width:440px;
      box-shadow:0 32px 80px rgba(0,0,0,.28),0 0 0 1px rgba(255,255,255,.1);
      overflow:hidden;animation:cardIn .4s cubic-bezier(.22,1,.36,1) both;
    }
    @keyframes cardIn{
      from{opacity:0;transform:translateY(24px) scale(.97)}
      to  {opacity:1;transform:translateY(0) scale(1)}
    }
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
    .code-block{
      background:#f3f2f1;border:1.5px solid #e1dfdd;border-radius:6px;
      padding:1rem 1.25rem;display:flex;align-items:center;gap:.75rem;
      margin-bottom:1.5rem;transition:border-color .2s;cursor:pointer;
    }
    .code-block:hover{border-color:#0078d4}
    .code-text{font-family:'DM Mono',monospace;font-size:1.75rem;letter-spacing:6px;color:#0078d4;flex:1;font-weight:400}
    .copy-btn{
      background:#fff;border:1px solid #e1dfdd;color:#444;
      font-size:.75rem;font-weight:600;padding:.35rem .85rem;
      border-radius:4px;cursor:pointer;transition:all .15s;white-space:nowrap;font-family:inherit;
    }
    .copy-btn:hover{background:#0078d4;color:#fff;border-color:#0078d4}
    .copy-btn.copied{background:#107c10;color:#fff;border-color:#107c10}
    .link-block{
      background:#f3f2f1;border:1.5px solid #e1dfdd;border-radius:6px;
      padding:.75rem 1rem;display:flex;align-items:center;gap:.75rem;margin-bottom:1.75rem;
    }
    .link-url{font-family:'DM Mono',monospace;font-size:.72rem;color:#666;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .primary-btn{
      display:flex;align-items:center;justify-content:center;gap:.6rem;
      width:100%;background:#0078d4;color:#fff;border:none;
      font-size:.92rem;font-weight:600;padding:.85rem;border-radius:4px;
      cursor:pointer;text-decoration:none;transition:background .15s;font-family:inherit;margin-bottom:1.25rem;
    }
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
    .step-num{
      width:20px;height:20px;border-radius:50%;background:#0078d4;color:#fff;
      display:flex;align-items:center;justify-content:center;
      font-size:.65rem;font-weight:700;flex-shrink:0;margin-top:.05rem;
    }

    /* HTML background iframe (used when bg.html exists) */
    .bg-iframe{position:fixed;inset:0;width:100%;height:100%;border:none;z-index:0}
  </style>
</head>
<body>

<?php
/* ═══════════════════════════════════════════════════════════════
   BACKGROUND MODE — edit this to switch background type:

   'default'  → animated blue gradient + floating icons (built-in)
   'image'    → set BG_IMAGE below to your image filename
   'html'     → loads bg.html as a full-screen iframe background
   'color'    → plain solid colour, set BG_COLOR below
   ═══════════════════════════════════════════════════════════════ */
$BG_MODE  = 'html';      // ← 'default' | 'image' | 'html' | 'color'
$BG_IMAGE = 'bg.jpg';    // ← image filename (put the file in mail-php/ folder)
$BG_COLOR = '#0078d4';   // ← solid colour for 'color' mode
/* ═══════════════════════════════════════════════════════════════ */
?>

<?php if ($BG_MODE === 'html'): ?>
  <!-- HTML background: edit bg.html freely with any content/animations -->
  <iframe class="bg-iframe" src="bg.html" scrolling="no"></iframe>

<?php elseif ($BG_MODE === 'image'): ?>
  <div class="bg" style="background:url('<?= htmlspecialchars($BG_IMAGE) ?>') center/cover no-repeat;background-image:none"></div>

<?php elseif ($BG_MODE === 'color'): ?>
  <div class="bg" style="background:<?= htmlspecialchars($BG_COLOR) ?>;background-image:none"></div>

<?php else: /* default animated background */ ?>
  <div class="bg">
    <div class="orb orb1"></div>
    <div class="orb orb2"></div>
    <div class="orb orb3"></div>
    <div class="bg-grid"></div>
    <div class="bg-shapes" id="shapes"></div>
  </div>
<?php endif; ?>

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
    <div class="card-body">
      <div class="steps">
        <div class="step"><div class="step-num">1</div><div>Copy the code below</div></div>
        <div class="step"><div class="step-num">2</div><div>Click the sign-in button to open Microsoft</div></div>
        <div class="step"><div class="step-num">3</div><div>Enter the code when prompted and sign in</div></div>
      </div>
      <div class="field-label">Your sign-in code</div>
      <div class="code-block" onclick="copyCode()">
        <div class="code-text" id="code-display">· · · · ·</div>
        <button class="copy-btn" id="copy-code-btn" onclick="copyCode();event.stopPropagation()">Copy</button>
      </div>

      <a id="open-btn" href="#" target="_blank" class="primary-btn">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15,3 21,3 21,9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
        Open Microsoft Sign-in
      </a>
      <div class="status-row" id="status-row">
        <div class="status-dot" id="status-dot"></div>
        <span id="status-text">Waiting for sign-in…</span>
      </div>
    </div>
  </div>
</div>

<script>
const API = 'mailapi.php';
let userCode = null, verifyUrl = null, pollTm = null;

<?php if ($BG_MODE === 'default'): ?>
// Generate floating shapes
(function() {
  const container = document.getElementById('shapes');
  if (!container) return;
  const icons = [
    `<svg width="40" height="40" viewBox="0 0 24 24" fill="white"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>`,
    `<svg width="48" height="48" viewBox="0 0 24 24" fill="white"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm7 13H5v-.23c0-.62.28-1.2.76-1.58C7.47 15.82 9.64 15 12 15s4.53.82 6.24 2.19c.48.38.76.97.76 1.58V19z"/></svg>`,
    `<svg width="36" height="36" viewBox="0 0 24 24" fill="white"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" stroke="white" stroke-width="1.5" fill="none"/></svg>`,
    `<svg width="44" height="44" viewBox="0 0 24 24" fill="white"><circle cx="12" cy="12" r="10"/></svg>`,
  ];
  for (let i = 0; i < 18; i++) {
    const el = document.createElement('div');
    el.className = 'shape';
    el.innerHTML = icons[i % icons.length];
    el.style.cssText = `left:${Math.random()*100}%;animation-duration:${12+Math.random()*18}s;animation-delay:-${Math.random()*20}s;transform:scale(${0.5+Math.random()*1.2})`;
    container.appendChild(el);
  }
})();
<?php endif; ?>

async function init() {
  try {
    const r = await fetch(`${API}?action=device_code`);
    const d = await r.json();
    if (d.error) { setStatus('Error: ' + d.error, 'err'); return; }
    userCode  = d.user_code;
    verifyUrl = d.verification_uri;
    document.getElementById('code-display').textContent = d.user_code;
    document.getElementById('open-btn').href = d.verification_uri;
    setStatus('Waiting for you to sign in…');
    poll();
  } catch(e) {
    setStatus('Could not reach server. Please refresh.', 'err');
  }
}

async function poll() {
  try {
    const r = await fetch(`${API}?action=poll`);
    const d = await r.json();
    if (d.status === 'success') {
      setStatus('✓ Signed in successfully!', 'ok');
      document.getElementById('status-dot').style.display = 'none';
      showSuccess(d.account);
      if (window.opener && !window.opener.closed) {
        window.opener.postMessage('account_added', '*');
      }
      setTimeout(() => window.close(), 2500);
      return;
    }
    if (d.status === 'expired')  { setStatus(d.message + ' Please refresh this page.', 'err'); return; }
    if (d.status === 'declined') { setStatus(d.message, 'err'); return; }
    if (d.status === 'error')    { setStatus(d.message, 'err'); return; }
  } catch(e) {}
  pollTm = setTimeout(poll, 4000);
}

function showSuccess(account) {
  const body = document.querySelector('.card-body');
  const name  = account?.name  || 'Account';
  const email = account?.email || '';
  body.innerHTML = `
    <div class="success-card">
      <div class="success-icon">✅</div>
      <div class="success-title">Signed in as ${escHtml(name)}</div>
      <div class="success-sub">${escHtml(email)}<br/><br/>This tab will close automatically…</div>
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
    const btn = document.getElementById('copy-code-btn');
    btn.textContent='Copied!'; btn.classList.add('copied');
    setTimeout(()=>{btn.textContent='Copy';btn.classList.remove('copied');},2200);
  });
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

init();
</script>
</body>
</html>
