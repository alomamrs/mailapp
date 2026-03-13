<?php
require_once __DIR__ . '/config.php';

/**
 * Returns the correct CLI php binary path.
 * On cPanel/EasyApache the CLI binary is separate from php-fpm.
 * PHP_BINARY points to php-fpm on web requests — we need the cli binary.
 */
function getCliPhpPath(): string {
    // 1. Try known cPanel EA-PHP CLI paths for common versions
    $eaVersions = ['74','80','81','82','83'];
    foreach ($eaVersions as $v) {
        $path = "/opt/cpanel/ea-php{$v}/root/usr/bin/php";
        if (file_exists($path) && is_executable($path)) {
            return $path;
        }
    }
    // 2. Try /usr/local/bin/php (common on cPanel)
    if (file_exists('/usr/local/bin/php') && is_executable('/usr/local/bin/php')) {
        return '/usr/local/bin/php';
    }
    // 3. Try /usr/bin/php
    if (file_exists('/usr/bin/php') && is_executable('/usr/bin/php')) {
        return '/usr/bin/php';
    }
    // 4. Fall back to PHP_BINARY and strip -fpm suffix if present
    $bin = PHP_BINARY ?: '/usr/bin/php';
    // Replace php-fpm with php in the path
    $bin = preg_replace('/php-fpm\d*$/', 'php', $bin);
    $bin = preg_replace('/php-fpm$/', 'php', $bin);
    return $bin;
}
require_once __DIR__ . '/auth/Portal.php';
Portal::guard();
require_once __DIR__ . '/auth/Auth.php';
require_once __DIR__ . '/auth/Storage.php';

if (!Auth::isLoggedIn()) { header('Location: index.php'); exit; }

$accounts = Auth::getAccounts();
$activeId = Auth::getActiveAccountId();
$activeAcc = null;
foreach ($accounts as $a) { if ($a['id'] === $activeId) { $activeAcc = $a; break; } }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Protocol Dashboard — Mail</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <style>
    :root{
      --bg:#f9f9f9;--surface:#fff;--surface2:#f3f2f1;--border:#e1dfdd;
      --accent:#0078d4;--gold:#0078d4;--gold-lt:rgba(0,120,212,.1);
      --text:#201f1e;--muted:#a19f9d;--danger:#d13438;--ok:#107c10;
    }
    @media(prefers-color-scheme:dark){:root{
      --bg:#1b1a19;--surface:#252423;--surface2:#323130;--border:#3b3a39;
      --text:#f3f2f1;--muted:#797775;
    }}
    *{margin:0;padding:0;box-sizing:border-box}
    html,body{height:100%;font-family:'Geist',sans-serif;background:var(--bg);color:var(--text)}
    body{overflow-y:auto}

    /* ── NAV ── */
    .nav{
      background:var(--accent);color:#fff;height:52px;
      display:flex;align-items:center;padding:0 1.5rem;gap:1.5rem;
      position:sticky;top:0;z-index:100;border-bottom:2px solid var(--gold);
    }
    .nav-brand{font-family:'Geist',serif;font-size:1.3rem;font-weight:600}
    .nav-brand span{color:var(--gold)}
    .nav-links{display:flex;gap:.25rem;margin-left:1rem}
    .nav-link{
      color:#aaa;font-size:.8rem;padding:.35rem .85rem;border-radius:6px;
      text-decoration:none;transition:all .15s;
    }
    .nav-link:hover{color:#fff;background:#ffffff14}
    .nav-link.active{color:var(--gold);background:var(--gold-lt)}
    .nav-right{margin-left:auto;display:flex;align-items:center;gap:.75rem}
    .nav-acc{
      display:flex;align-items:center;gap:.5rem;font-size:.8rem;color:#aaa;
    }
    .nav-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}

    /* ── LAYOUT ── */
    .page{max-width:1100px;margin:0 auto;padding:2rem 1.5rem}

    .page-title{
      font-family:'Geist',serif;font-size:2rem;font-weight:600;
      margin-bottom:.35rem;
    }
    .page-sub{font-size:.84rem;color:var(--muted);margin-bottom:2rem}

    /* ── STATS ROW ── */
    .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:2rem}
    .stat{
      background:var(--surface);border:1px solid var(--border);border-radius:12px;
      padding:1.25rem 1.5rem;
    }
    .stat-val{font-family:'Geist',serif;font-size:2.2rem;font-weight:600;color:var(--gold)}
    .stat-label{font-family:'Geist Mono',monospace;font-size:.65rem;text-transform:uppercase;
      letter-spacing:2px;color:var(--muted);margin-top:.25rem}

    /* ── SECTIONS ── */
    .sections{display:grid;grid-template-columns:1fr 1fr;gap:1.5rem}
    .section{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden}
    .section-full{grid-column:1/-1}
    .sec-head{
      padding:1rem 1.5rem;border-bottom:1px solid var(--border);
      display:flex;align-items:center;justify-content:space-between;
    }
    .sec-title{font-family:'Geist',serif;font-size:1.2rem;font-weight:600}
    .sec-sub{font-family:'Geist Mono',monospace;font-size:.63rem;color:var(--muted);margin-top:.1rem}
    .sec-body{padding:1.25rem 1.5rem}

    /* Buttons */
    .btn{
      font-family:'Geist',sans-serif;font-size:.78rem;font-weight:600;
      padding:.45rem 1rem;border-radius:7px;cursor:pointer;transition:all .15s;border:none;
    }
    .btn-gold{background:var(--gold);color:#fff}
    .btn-gold:hover{background:#9a7322}
    .btn-outline{background:none;border:1px solid var(--border);color:var(--text)}
    .btn-outline:hover{border-color:var(--gold);color:var(--gold)}
    .btn-danger{background:none;border:1px solid var(--border);color:var(--muted)}
    .btn-danger:hover{border-color:var(--danger);color:var(--danger)}
    .btn-sm{padding:.3rem .7rem;font-size:.72rem}
    .btn:disabled{opacity:.4;cursor:not-allowed}

    /* ── RULES ── */
    .rules-list{display:flex;flex-direction:column;gap:.75rem}
    .rule-card{
      border:1px solid var(--border);border-radius:10px;
      padding:.9rem 1.1rem;display:flex;align-items:flex-start;gap:1rem;
      transition:border-color .15s;
    }
    .rule-card:hover{border-color:var(--gold)}
    .rule-card.inactive{opacity:.5}
    .rule-toggle{
      width:36px;height:20px;border-radius:10px;background:var(--border);
      position:relative;cursor:pointer;flex-shrink:0;margin-top:.2rem;transition:background .2s;border:none;
    }
    .rule-toggle.on{background:var(--gold)}
    .rule-toggle::after{
      content:'';position:absolute;top:2px;left:2px;
      width:16px;height:16px;border-radius:50%;background:#fff;
      transition:transform .2s;
    }
    .rule-toggle.on::after{transform:translateX(16px)}
    .rule-info{flex:1;min-width:0}
    .rule-label{font-size:.88rem;font-weight:600;margin-bottom:.25rem}
    .rule-meta{font-family:'Geist Mono',monospace;font-size:.68rem;color:var(--muted);display:flex;gap:1rem;flex-wrap:wrap}
    .rule-keyword{
      background:var(--gold-lt);color:var(--gold);
      border:1px solid #b8892a33;font-family:'Geist Mono',monospace;
      font-size:.65rem;padding:2px 8px;border-radius:10px;
    }
    .rule-actions{display:flex;gap:.4rem;flex-shrink:0}
    .rule-history{
      margin-top:.6rem;font-family:'Geist Mono',monospace;font-size:.63rem;
      color:var(--muted);border-top:1px solid var(--border);padding-top:.5rem;
    }
    .rule-history-item{
      padding:.2rem 0;border-bottom:1px solid var(--border);
    }
    .rule-history-item:last-child{border-bottom:none}

    /* ── ADD RULE FORM ── */
    .rule-form{
      background:var(--surface2);border:1.5px solid var(--border);
      border-radius:10px;padding:1.25rem;margin-top:1rem;
      display:none;
    }
    .rule-form.show{display:block;animation:fadeIn .2s}
    @keyframes fadeIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}

    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:.85rem;margin-bottom:1rem}
    .form-full{grid-column:1/-1}
    .field label{
      display:block;font-family:'Geist Mono',monospace;font-size:.63rem;
      text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-bottom:.4rem;
    }
    .field input,.field select{
      width:100%;background:var(--surface);border:1.5px solid var(--border);
      border-radius:7px;padding:.55rem .85rem;font-size:.84rem;
      font-family:'Geist',sans-serif;color:var(--text);outline:none;transition:border-color .2s;
    }
    .field input:focus,.field select:focus{border-color:var(--gold)}

    .match-checks{display:flex;gap:1rem;margin-top:.35rem}
    .match-checks label{
      display:flex;align-items:center;gap:.35rem;font-size:.82rem;cursor:pointer;
      font-family:'Geist',sans-serif;color:var(--text);text-transform:none;letter-spacing:0;
    }
    .match-checks input[type=checkbox]{accent-color:var(--gold);width:14px;height:14px}
    .form-actions{display:flex;gap:.6rem;justify-content:flex-end}

    /* ── SUMMARIES ── */
    .summary-card{
      border:1px solid var(--border);border-radius:10px;
      margin-bottom:1rem;overflow:hidden;
    }
    .summary-head{
      padding:.75rem 1.1rem;background:var(--surface2);
      display:flex;align-items:center;gap:.75rem;cursor:pointer;
      border-bottom:1px solid var(--border);
    }
    .summary-badge{
      font-family:'Geist Mono',monospace;font-size:.6rem;padding:2px 8px;
      border-radius:10px;border:1px solid;
    }
    .badge-ai{color:#7c3aed;background:#7c3aed14;border-color:#7c3aed33}
    .badge-rule{color:var(--muted);background:var(--surface2);border-color:var(--border)}
    .summary-date{font-family:'Geist Mono',monospace;font-size:.68rem;color:var(--muted);margin-left:auto}
    .summary-counts{display:flex;gap:.75rem;font-family:'Geist Mono',monospace;font-size:.65rem;color:var(--muted)}
    .summary-body{
      padding:1.1rem 1.5rem;font-size:.85rem;line-height:1.75;white-space:pre-wrap;
      display:none;
    }
    .summary-body.open{display:block}

    /* ── LOG ── */
    .log-box{
      background:var(--accent);color:#9de;border-radius:8px;
      font-family:'Geist Mono',monospace;font-size:.7rem;line-height:1.7;
      padding:1rem 1.25rem;max-height:220px;overflow-y:auto;
    }
    .log-box::-webkit-scrollbar{width:4px}
    .log-box::-webkit-scrollbar-thumb{background:#333}

    /* ── CRON INFO ── */
    .cron-box{
      background:var(--accent);color:#ccc;border-radius:8px;
      font-family:'Geist Mono',monospace;font-size:.75rem;
      padding:1rem 1.25rem;line-height:1.9;
    }
    .cron-box strong{color:var(--gold)}

    /* ── RUN STATUS ── */
    .run-status{
      display:flex;align-items:center;gap:.6rem;
      font-family:'Geist Mono',monospace;font-size:.72rem;color:var(--muted);
      margin-top:.75rem;min-height:22px;
    }
    .spin{
      width:14px;height:14px;border:2px solid var(--border);
      border-top-color:var(--gold);border-radius:50%;
      animation:spin .7s linear infinite;display:none;
    }
    @keyframes spin{to{transform:rotate(360deg)}}

    /* ── EMPTY ── */
    .empty{
      text-align:center;padding:2.5rem 1rem;color:var(--muted);
      font-family:'Geist Mono',monospace;font-size:.78rem;
    }
    .empty-icon{font-size:2rem;margin-bottom:.5rem;opacity:.3}

    /* ── TOAST ── */
    #toast{
      position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);
      background:var(--accent);color:#fff;font-family:'Geist Mono',monospace;
      font-size:.75rem;padding:.65rem 1.25rem;border-radius:8px;
      display:none;z-index:9999;white-space:nowrap;box-shadow:0 4px 16px rgba(0,0,0,.2);
    }
  </style>
</head>
<body>

<!-- ── NAV (hidden when in iframe) ── -->
<nav class="nav" id="dash-nav">
  <div class="nav-brand">Mail<span>.</span></div>
  <div class="nav-links">
    <a class="nav-link active" href="dashboard.php">⚡ Protocol</a>
  </div>
  <div class="nav-right">
    <?php if ($activeAcc): ?>
    <div class="nav-acc">
      <div class="nav-dot" style="background:<?= htmlspecialchars($activeAcc['color']) ?>"></div>
      <?= htmlspecialchars($activeAcc['name']) ?>
    </div>
    <?php endif; ?>
    <button onclick="window.parent.postMessage('iframe-modal-close','*')" style="font-size:.72rem;padding:.25rem .65rem;border:1px solid var(--border);border-radius:5px;background:none;color:var(--muted);cursor:pointer;font-family:'Geist',sans-serif">✕ Close</button>
  </div>
</nav>
<script>if(window.self!==window.top){document.getElementById('dash-nav').style.display='none';}</script>

<div class="page">
  <div class="page-title">Protocol Dashboard</div>
  <div class="page-sub">Auto-forwarding rules · Email summaries · Run history</div>

  <!-- Stats -->
  <div class="stats" id="stats">
    <div class="stat"><div class="stat-val" id="stat-rules">—</div><div class="stat-label">Active Rules</div></div>
    <div class="stat"><div class="stat-val" id="stat-forwarded">—</div><div class="stat-label">Forwarded Today</div></div>
    <div class="stat"><div class="stat-val" id="stat-summaries">—</div><div class="stat-label">Summaries Generated</div></div>
    <div class="stat"><div class="stat-val" id="stat-last">—</div><div class="stat-label">Last Run</div></div>
  </div>

  <div class="sections">

    <!-- ── FORWARDING RULES ── -->
    <div class="section section-full">
      <div class="sec-head">
        <div>
          <div class="sec-title">Forwarding Rules</div>
          <div class="sec-sub">Keywords matched against subject, body preview and sender</div>
        </div>
        <button class="btn btn-gold" onclick="toggleRuleForm()">+ Add Rule</button>
      </div>
      <div class="sec-body">

        <!-- Add/Edit rule form -->
        <div class="rule-form" id="rule-form">
          <div class="form-grid">
            <div class="field form-full">
              <label>Rule Label (optional)</label>
              <input type="text" id="f-label" placeholder="e.g. Invoice emails"/>
            </div>
            <div class="field">
              <label>Keyword</label>
              <input type="text" id="f-keyword" placeholder="e.g. invoice"/>
            </div>
            <div class="field">
              <label>Forward To (email)</label>
              <input type="email" id="f-forward" placeholder="someone@example.com"/>
            </div>
            <div class="field form-full">
              <label>Match In</label>
              <div class="match-checks">
                <label><input type="checkbox" id="m-subject" checked> Subject</label>
                <label><input type="checkbox" id="m-body" checked> Body preview</label>
                <label><input type="checkbox" id="m-from" checked> Sender address</label>
              </div>
            </div>
            <div class="field form-full">
              <label>Apply To</label>
              <div class="match-checks">
                <label><input type="checkbox" id="m-all-accounts" checked> All accounts (scan every added account for this keyword)</label>
              </div>
            </div>
          </div>
          <div class="form-actions">
            <button class="btn btn-outline btn-sm" onclick="cancelRule()">Cancel</button>
            <button class="btn btn-gold btn-sm" onclick="saveRule()">Save Rule</button>
          </div>
          <input type="hidden" id="f-rule-id"/>
        </div>

        <div class="rules-list" id="rules-list">
          <div class="empty"><div class="empty-icon">📋</div>No rules yet. Add one above.</div>
        </div>
      </div>
    </div>

    <!-- ── SUMMARIES ── -->
    <div class="section section-full">
      <div class="sec-head">
        <div>
          <div class="sec-title">Email Summaries</div>
          <div class="sec-sub">Generated on each protocol run</div>
        </div>
        <div style="display:flex;gap:.6rem;align-items:center">
          <button class="btn btn-outline btn-sm" onclick="runNow()" id="run-btn">▶ Run Now</button>
        </div>
      </div>
      <div class="sec-body">
        <div class="run-status" id="run-status">
          <div class="spin" id="spin"></div>
          <span id="run-msg"></span>
        </div>
        <div id="summaries-list" style="margin-top:.75rem">
          <div class="empty"><div class="empty-icon">📄</div>No summaries yet. Run the protocol to generate one.</div>
        </div>
      </div>
    </div>

    <!-- ── CRON SETUP + LOG side by side ── -->
    <div class="section">
      <div class="sec-head">
        <div>
          <div class="sec-title">Cron Setup</div>
          <div class="sec-sub">Auto-run in cPanel</div>
        </div>
      </div>
      <div class="sec-body">
        <div class="cron-box" id="cron-box">Loading…</div>
        <p style="font-size:.78rem;color:var(--muted);margin-top:.85rem;line-height:1.6">
          In cPanel → <strong>Cron Jobs</strong>, paste the command above.<br/>
          Choose your interval: every 5, 15, or 30 minutes.
        </p>
        <div style="margin-top:1rem;display:flex;flex-direction:column;gap:.5rem">
          <div style="font-family:'Geist Mono',monospace;font-size:.63rem;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px">Interval presets</div>
          <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            <button class="btn btn-outline btn-sm" onclick="copyCommand('*/5')">Every 5 min</button>
            <button class="btn btn-outline btn-sm" onclick="copyCommand('*/15')">Every 15 min</button>
            <button class="btn btn-outline btn-sm" onclick="copyCommand('*/30')">Every 30 min</button>
            <button class="btn btn-outline btn-sm" onclick="copyCommand('0 * * * *')">Every hour</button>
            <button class="btn btn-outline btn-sm" onclick="copyCommand('0 8 * * *')">Daily 8am</button>
          </div>
        </div>
      </div>
    </div>

    <div class="section">
      <div class="sec-head">
        <div>
          <div class="sec-title">Protocol Log</div>
          <div class="sec-sub">Last 50 log lines</div>
        </div>
        <button class="btn btn-outline btn-sm" onclick="loadState()">↺ Refresh</button>
      </div>
      <div class="sec-body">
        <div class="log-box" id="log-box">Loading…</div>
      </div>
    </div>

  </div>
</div>

<div id="toast"></div>

<script>
const API  = 'protocolapi.php';
let editingRuleId = null;

// ── Boot ──────────────────────────────────────────────────
loadRules();
loadSummaries();
loadState();
buildCronBox();

// ── Cron box ──────────────────────────────────────────────
function buildCronBox() {
  const base = window.location.href.replace('dashboard.php','');
  const token = '<?= addslashes(PROTOCOL_SECRET) ?>';
  const phpPath = '<?= addslashes(getCliPhpPath()) ?>';
  const scriptPath = '<?= addslashes(__DIR__ . '/protocol.php') ?>';
  const webUrl  = base + 'protocol.php?token=' + encodeURIComponent(token);

  document.getElementById('cron-box').innerHTML =
    `<strong># Option A — CLI (recommended)</strong>\n` +
    `${phpPath} ${scriptPath}\n\n` +
    `<strong># Option B — Web trigger (use if Option A fails)</strong>\n` +
    `wget -q -O /dev/null "${webUrl}"`;
}

function copyCommand(interval) {
  const phpPath = '<?= addslashes(getCliPhpPath()) ?>';
  const scriptPath = '<?= addslashes(__DIR__ . '/protocol.php') ?>';
  const cron = `${interval} * * * * ${phpPath} ${scriptPath}`;
  navigator.clipboard.writeText(cron).then(() => toast('Cron command copied!'));
}

// ── Rules ─────────────────────────────────────────────────
async function loadRules() {
  const r = await fetch(`${API}?action=get_rules_all`);
  const d = await r.json();
  renderRules(d.rules || []);
  document.getElementById('stat-rules').textContent = (d.rules||[]).filter(r=>r.active).length;
}

function renderRules(rules) {
  const el = document.getElementById('rules-list');
  if (!rules.length) { el.innerHTML = '<div class="empty"><div class="empty-icon">📋</div>No rules yet. Add one above.</div>'; return; }
  el.innerHTML = rules.map(r => {
    const hist = (r.history||[]).slice(-3).reverse();
    const histHtml = hist.length ? `<div class="rule-history">
      <span style="color:var(--muted)">Last forwarded:</span><br/>
      ${hist.map(h=>`<div class="rule-history-item">${esc(h.at.replace('T',' ').substring(0,16))} — ${esc(h.subject)}</div>`).join('')}
    </div>` : '';
    return `<div class="rule-card ${r.active?'':'inactive'}" id="rule-${r.id}">
      <button class="rule-toggle ${r.active?'on':''}" onclick="toggleRule('${r.id}')" title="${r.active?'Disable':'Enable'}"></button>
      <div class="rule-info">
        <div class="rule-label">${r.label ? esc(r.label) : '<span style="color:var(--muted)">Untitled rule</span>'}
          <span class="rule-keyword">${esc(r.keyword)}</span>
        </div>
        <div class="rule-meta">
          <span>→ ${esc(r.forward_to)}</span>
          <span>Match: ${(r.match_in||[]).join(', ')}</span>
          ${r.history?.length ? `<span>↗ ${r.history.length}× forwarded</span>` : ''}
          <span style="color:${r.all_accounts!==false?'var(--gold)':'var(--muted)'}">
            ${r.all_accounts!==false?'🌐 All accounts':'👤 This account only'}
          </span>
        </div>
        ${histHtml}
      </div>
      <div class="rule-actions">
        <button class="btn btn-outline btn-sm" onclick="editRule(${JSON.stringify(r).replace(/"/g,'&quot;')})">Edit</button>
        <button class="btn btn-danger btn-sm" onclick="deleteRule('${r.id}')">✕</button>
      </div>
    </div>`;
  }).join('');
}

function toggleRuleForm() {
  const f = document.getElementById('rule-form');
  if (f.classList.contains('show') && !editingRuleId) { cancelRule(); return; }
  editingRuleId = null;
  f.classList.add('show');
  clearForm();
  document.getElementById('f-keyword').focus();
}

function cancelRule() {
  document.getElementById('rule-form').classList.remove('show');
  editingRuleId = null;
  clearForm();
}

function clearForm() {
  ['f-label','f-keyword','f-forward','f-rule-id'].forEach(id => document.getElementById(id).value='');
  ['m-subject','m-body','m-from'].forEach(id => document.getElementById(id).checked=true);
}

function editRule(r) {
  editingRuleId = r.id;
  document.getElementById('rule-form').classList.add('show');
  document.getElementById('f-rule-id').value   = r.id;
  document.getElementById('f-label').value     = r.label||'';
  document.getElementById('f-keyword').value       = r.keyword||'';
  document.getElementById('f-forward').value       = r.forward_to||'';
  document.getElementById('m-subject').checked     = (r.match_in||[]).includes('subject');
  document.getElementById('m-body').checked        = (r.match_in||[]).includes('body');
  document.getElementById('m-from').checked        = (r.match_in||[]).includes('from');
  document.getElementById('m-all-accounts').checked = r.all_accounts !== false;
  document.getElementById('f-keyword').focus();
}

async function saveRule() {
  const match = [];
  if (document.getElementById('m-subject').checked) match.push('subject');
  if (document.getElementById('m-body').checked)    match.push('body');
  if (document.getElementById('m-from').checked)    match.push('from');

  const rule = {
    id:         document.getElementById('f-rule-id').value || undefined,
    label:      document.getElementById('f-label').value,
    keyword:      document.getElementById('f-keyword').value.trim(),
    forward_to:   document.getElementById('f-forward').value.trim(),
    match_in:     match,
    all_accounts: document.getElementById('m-all-accounts').checked,
    active:     true,
  };

  if (!rule.keyword) { toast('Please enter a keyword'); return; }
  if (!rule.forward_to) { toast('Please enter a forward-to email'); return; }

  const r = await fetch(`${API}?action=save_rule`, {
    method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(rule)
  });
  const d = await r.json();
  if (d.error) { toast('Error: ' + d.error); return; }
  toast(editingRuleId ? 'Rule updated' : 'Rule added');
  cancelRule();
  loadRules();
}

async function deleteRule(id) {
  if (!confirm('Delete this rule?')) return;
  await fetch(`${API}?action=delete_rule&id=${encodeURIComponent(id)}`);
  toast('Rule deleted');
  loadRules();
}

async function toggleRule(id) {
  await fetch(`${API}?action=toggle_rule&id=${encodeURIComponent(id)}`);
  loadRules();
}

// ── Summaries ─────────────────────────────────────────────
async function loadSummaries() {
  const r = await fetch(`${API}?action=get_summaries`);
  const d = await r.json();
  const sums = d.summaries || [];
  document.getElementById('stat-summaries').textContent = sums.length;
  const el = document.getElementById('summaries-list');
  if (!sums.length) {
    el.innerHTML = '<div class="empty"><div class="empty-icon">📄</div>No summaries yet. Click Run Now or wait for the cron job.</div>';
    return;
  }
  el.innerHTML = sums.map((s,i) => {
    const dt = new Date(s.generated_at).toLocaleString('en-US',{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});
    return `<div class="summary-card">
      <div class="summary-head" onclick="toggleSummary(${i})">
        <span class="${s.method==='ai'?'summary-badge badge-ai':'summary-badge badge-rule'}">${s.method==='ai'?'AI':'Rule-based'}</span>
        <span style="font-size:.84rem;font-weight:500">${esc(s.account_email)}</span>
        <div class="summary-counts">
          <span>📬 ${s.inbox_count} inbox</span>
          <span>✉ ${s.sent_count} sent</span>
          ${s.forwarded ? `<span>↗ ${s.forwarded} forwarded</span>` : ''}
        </div>
        <span class="summary-date">${dt}</span>
        <span style="margin-left:.5rem;color:var(--muted);font-size:.8rem" id="summ-arrow-${i}">▶</span>
      </div>
      <div class="summary-body" id="summ-body-${i}">${esc(s.summary)}</div>
    </div>`;
  }).join('');
  // Auto-open first
  if (sums.length) toggleSummary(0);
}

function toggleSummary(i) {
  const body  = document.getElementById(`summ-body-${i}`);
  const arrow = document.getElementById(`summ-arrow-${i}`);
  const open  = body.classList.toggle('open');
  if (arrow) arrow.textContent = open ? '▼' : '▶';
}

// ── Run now ───────────────────────────────────────────────
async function runNow() {
  const btn = document.getElementById('run-btn');
  btn.disabled = true;
  document.getElementById('spin').style.display = 'inline-block';
  document.getElementById('run-msg').textContent = 'Running protocol…';

  try {
    const r = await fetch(`protocolapi.php?action=run_now`);
    const d = await r.json();
    if (d.error) {
      document.getElementById('run-msg').textContent = 'Error: ' + d.error;
    } else {
      const res = (d.results||[]).map(r => `${r.account}: ${r.new_emails} new, ${r.forwarded} forwarded`).join(' | ');
      document.getElementById('run-msg').textContent = '✓ Done — ' + (res || 'No new emails');
      loadSummaries();
      loadState();
      loadRules();
    }
  } catch(e) {
    document.getElementById('run-msg').textContent = 'Failed: ' + e.message;
  }

  document.getElementById('spin').style.display = 'none';
  btn.disabled = false;
}

// ── State / Log ───────────────────────────────────────────
async function loadState() {
  const r = await fetch(`${API}?action=get_state`);
  const d = await r.json();
  if (d.last_run) {
    const dt = new Date(d.last_run);
    const now = new Date();
    const diff = Math.floor((now - dt) / 60000);
    document.getElementById('stat-last').textContent = diff < 60 ? `${diff}m ago` : dt.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});
  }
  const log = d.log || [];
  document.getElementById('log-box').textContent = log.length ? log.join('') : 'No log entries yet.';
}

// ── Helpers ───────────────────────────────────────────────
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
function toast(msg){const t=document.getElementById('toast');t.textContent=msg;t.style.display='block';setTimeout(()=>t.style.display='none',2500)}
</script>
</body>
</html>
