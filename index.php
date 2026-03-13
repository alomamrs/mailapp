<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth/Security.php';
require_once __DIR__ . '/auth/Auth.php';
require_once __DIR__ . '/auth/Portal.php';
Portal::guard();
Security::startSecureSession();
Security::sendHeaders();
if (isset($_GET['logout_all'])) { Auth::logoutAll(); header('Location: index.php'); exit; }
if (isset($_GET['portal_logout'])) { Portal::logout(); header('Location: login.php'); exit; }
if (isset($_GET['stop_impersonate'])) { Portal::stopImpersonating(); header('Location: admin.php'); exit; }
$accounts = Auth::getAccounts();
$activeId = Auth::getActiveAccountId();
$loggedIn = Auth::isLoggedIn();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,viewport-fit=cover"/>
  <meta name="mobile-web-app-capable" content="yes"/>
  <meta name="apple-mobile-web-app-capable" content="yes"/>
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"/>
  <meta name="apple-mobile-web-app-title" content="Mail"/>
  <meta name="theme-color" content="#0f6cbd"/>
  <title>Mail</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700&family=Geist+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="style.min.css?v=<?= filemtime(__DIR__.'/style.min.css') ?>"/>
  <script>
  // Theme must be available immediately — before app.js loads
  (function() {
    var t = localStorage.getItem('mail_theme') || 'light';
    document.documentElement.setAttribute('data-theme', t);
  })();

  function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('mail_theme', theme);
    document.querySelectorAll('.theme-swatch').forEach(function(s) {
      s.classList.toggle('active', s.dataset.theme === theme);
    });
    var popup = document.getElementById('theme-picker-popup');
    if (popup) popup.classList.remove('open');
  }

  function toggleThemePicker(e) {
    e.stopPropagation();
    var popup  = document.getElementById('theme-picker-popup');
    var btn    = document.getElementById('theme-btn');
    var isOpen = popup.classList.contains('open');
    popup.classList.toggle('open');
    if (!isOpen && btn) {
      var rect = btn.getBoundingClientRect();
      popup.style.top   = (rect.bottom + 6) + 'px';
      popup.style.right = (window.innerWidth - rect.right) + 'px';
      popup.style.left  = 'auto';
    }
  }

  // ── Source protection ──────────────────────────────────────────
  // Disable right-click context menu
  document.addEventListener('contextmenu', function(e){ e.preventDefault(); return false; });
  // Block common DevTools keyboard shortcuts
  document.addEventListener('keydown', function(e){
    // F12
    if(e.key==='F12'){ e.preventDefault(); return false; }
    // Ctrl+Shift+I / Cmd+Option+I (DevTools)
    if((e.ctrlKey||e.metaKey) && e.shiftKey && (e.key==='I'||e.key==='i')){ e.preventDefault(); return false; }
    // Ctrl+Shift+J (Console)
    if((e.ctrlKey||e.metaKey) && e.shiftKey && (e.key==='J'||e.key==='j')){ e.preventDefault(); return false; }
    // Ctrl+Shift+C (Inspector)
    if((e.ctrlKey||e.metaKey) && e.shiftKey && (e.key==='C'||e.key==='c')){ e.preventDefault(); return false; }
    // Ctrl+U (View source)
    if((e.ctrlKey||e.metaKey) && (e.key==='u'||e.key==='U')){ e.preventDefault(); return false; }
    // Ctrl+S (Save page)
    if((e.ctrlKey||e.metaKey) && (e.key==='s'||e.key==='S')){ e.preventDefault(); return false; }
  });
  // Detect DevTools open via timing trick and warn
  (function(){
    var _dt = false;
    setInterval(function(){
      var t = performance.now();
      (function(){}).toString(); // triggers if DevTools is open
      if(performance.now()-t > 100 && !_dt){ _dt=true; }
    }, 1000);
  })();
  </script>
</head>
<body>
<?php if (Portal::isImpersonating()): ?>
<div id="impersonate-banner" style="
  position:fixed;top:0;left:0;right:0;z-index:9999;
  background:#e05252;color:#fff;
  display:flex;align-items:center;justify-content:space-between;
  padding:.45rem 1.25rem;font-size:.78rem;font-family:'Geist Mono',monospace;
  box-shadow:0 2px 12px rgba(224,82,82,.5)">
  <span>👁 Admin view — viewing <strong><?= htmlspecialchars(Portal::impersonatingAs()) ?></strong>'s inbox</span>
  <div style="display:flex;gap:.75rem;align-items:center">
    <span style="opacity:.7">admin: <?= htmlspecialchars(Portal::originalAdmin()) ?></span>
    <a href="admin.php?stop_impersonate=1" style="background:#fff;color:#e05252;border:none;padding:.25rem .8rem;border-radius:5px;font-size:.73rem;font-weight:700;cursor:pointer;font-family:'Geist Mono',monospace;text-decoration:none;display:inline-block">← Return to Admin</a>
  </div>
</div>
<style>#topbar,#app-topbar,.topbar{margin-top:34px!important}</style>
<?php endif; ?>
<!-- CONTEXT MENU -->
<div class="ctx-menu" id="ctx-menu">
  <div class="ctx-item" onclick="ctxReply()">↩ Reply</div>
  <div class="ctx-item" onclick="ctxReplyAll()">↩↩ Reply All</div>
  <div class="ctx-item" onclick="ctxForward()">↗ Forward</div>
  <div class="ctx-divider"></div>
  <div class="ctx-item" onclick="ctxMarkRead()">✓ Mark read</div>
  <div class="ctx-item" onclick="ctxMarkUnread()">○ Mark unread</div>
  <div class="ctx-divider"></div>
  <div class="ctx-submenu-label">Move to</div>
  <div id="ctx-folders" class="ctx-folders-list"></div>
  <div class="ctx-divider"></div>
  <div class="ctx-item danger" onclick="ctxDelete()">🗑 Delete</div>
</div>
<!-- ══ IFRAME MODAL (Manage Users / Protocol) ══ -->
<div id="iframe-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);z-index:850;align-items:center;justify-content:center" onclick="if(event.target===this)closeIframeModal()">
  <div id="iframe-modal-box" style="background:var(--surface);border:1px solid var(--border);border-radius:10px;width:min(900px,96vw);height:min(88vh,800px);display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.35);animation:fadeUp .15s ease;overflow:hidden">
    <div id="iframe-modal-head" style="display:flex;align-items:center;padding:12px 18px;background:var(--topbar);flex-shrink:0;gap:10px">
      <span id="iframe-modal-title" style="font-size:.92rem;font-weight:600;color:#fff;flex:1"></span>
      <button onclick="closeIframeModal()" style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;width:28px;height:28px;border-radius:4px;cursor:pointer;font-size:.8rem;display:flex;align-items:center;justify-content:center;transition:background .12s" onmouseover="this.style.background='rgba(255,255,255,.28)'" onmouseout="this.style.background='rgba(255,255,255,.15)'">✕</button>
    </div>
    <iframe id="iframe-modal-frame" src="" style="flex:1;border:none;width:100%;min-height:0" allow="same-origin"></iframe>
  </div>
</div>

<!-- MOVE PANEL -->
<div class="move-panel" id="move-panel">
  <div class="move-panel-title">Move to folder</div>
  <div id="move-panel-list"></div>
</div>
<!-- MODAL -->
<div class="modal-overlay" id="modal-overlay">
  <div class="modal-box">
    <div class="modal-head"><div class="modal-title" id="modal-title">New Folder</div></div>
    <div class="modal-body" id="modal-body">
      <div class="modal-label" id="modal-label">Folder name</div>
      <input class="modal-input" id="modal-input" type="text"/>
    </div>
    <div class="modal-foot">
      <button class="modal-btn" onclick="closeModal()">Cancel</button>
      <button class="modal-btn primary" id="modal-confirm" onclick="modalConfirm()">Create</button>
    </div>
  </div>
</div>
<!-- ══ RULES MODAL ══ -->
<div class="modal-overlay" id="rules-modal">
  <div class="modal-box rules-modal-box">
    <div class="modal-head" style="display:flex;align-items:center;justify-content:space-between">
      <div class="modal-title">⚡ Inbox Rules</div>
      <button onclick="closeRulesModal()" style="background:none;border:none;color:#888;font-size:1.1rem;cursor:pointer;line-height:1">✕</button>
    </div>
    <div class="rules-modal-body">
      <!-- Left: rules list -->
      <div class="rules-list-col">
        <div class="rules-list-head">
          <span style="font-family:'Geist Mono',monospace;font-size:.6rem;text-transform:uppercase;letter-spacing:2px;color:var(--muted)">Rules</span>
          <button class="rules-new-btn" onclick="openNewRuleForm()">+ New Rule</button>
        </div>
        <div id="rules-list-col-inner"></div>
        <div class="rules-list-empty" id="rules-list-empty" style="display:none">
          <div style="font-size:2rem;opacity:.15;margin-bottom:.5rem">⚡</div>
          <div style="font-size:.8rem;color:var(--muted)">No rules yet</div>
          <div style="font-size:.72rem;color:var(--muted);margin-top:.25rem">Click "+ New Rule" to create one</div>
        </div>
      </div>
      <!-- Right: rule editor -->
      <div class="rules-editor-col" id="rules-editor-col">
        <div class="rules-editor-empty" id="rules-editor-empty">
          <div style="font-size:2.5rem;opacity:.1;margin-bottom:.75rem">⚡</div>
          <div style="font-size:.88rem;color:var(--muted)">Select a rule to edit<br>or create a new one</div>
        </div>
        <div id="rules-editor-form" style="display:none">
          <!-- Rule name -->
          <div class="re-section">
            <div class="re-label">Rule name</div>
            <input class="re-input" id="re-name" placeholder="e.g. Move invoices to Finance"/>
          </div>

          <!-- CONDITIONS -->
          <div class="re-section">
            <div class="re-section-title">When a message arrives and…</div>

            <div class="re-cond-row">
              <select class="re-select" id="re-cond-type">
                <option value="from">From (sender contains)</option>
                <option value="subject">Subject contains</option>
                <option value="body">Body contains</option>
                <option value="hasAttachments">Has attachments</option>
                <option value="importance">Importance is</option>
              </select>
              <input class="re-input re-cond-val" id="re-cond-val" placeholder="e.g. invoices@company.com"/>
              <select class="re-select re-cond-imp" id="re-cond-imp" style="display:none">
                <option value="high">High</option>
                <option value="normal">Normal</option>
                <option value="low">Low</option>
              </select>
            </div>
            <div style="font-size:.68rem;color:var(--muted);margin-top:.3rem">Multiple keywords: separate with commas</div>
          </div>

          <!-- ACTIONS -->
          <div class="re-section">
            <div class="re-section-title">Do the following…</div>
            <div id="re-actions-list"></div>
            <button class="re-add-action-btn" onclick="addActionRow()">+ Add action</button>
          </div>

          <!-- EXCEPTIONS -->
          <div class="re-section">
            <div class="re-section-title" style="display:flex;align-items:center;justify-content:space-between">
              <span>Except if…</span>
              <button class="re-add-action-btn" onclick="addExceptionRow()">+ Add exception</button>
            </div>
            <div id="re-exceptions-list"></div>
          </div>

          <!-- OPTIONS -->
          <div class="re-section">
            <label class="re-checkbox-row">
              <input type="checkbox" id="re-stop-processing" style="accent-color:var(--gold)">
              <span>Stop processing more rules after this one runs</span>
            </label>
            <label class="re-checkbox-row" style="margin-top:.4rem">
              <input type="checkbox" id="re-enabled" checked style="accent-color:var(--gold)">
              <span>Rule is enabled</span>
            </label>
          </div>

          <div class="re-footer">
            <button class="re-btn" onclick="cancelRuleEdit()">Cancel</button>
            <button class="re-btn primary" onclick="saveRule()"><span id="re-save-spinner" class="send-spinner"></span> Save Rule</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- COMPOSE -->
<div class="compose-panel" id="compose-panel">
  <div class="compose-header">
    <div class="compose-drag-handle"></div>
    <div class="compose-title" id="compose-title">New Message</div>
    <button class="compose-header-btn" onclick="expandCompose()" title="Expand">⤢</button>
    <button class="compose-header-btn" onclick="minimizeCompose()">—</button>
    <button class="compose-header-btn" onclick="closeCompose()">✕</button>
  </div>
  <div class="compose-body-wrap" id="compose-body-wrap">
    <div class="compose-field">
      <div class="compose-field-label">To</div>
      <input class="compose-input" id="compose-to" type="text" placeholder="recipient@example.com"/>
      <div class="compose-cc-bcc-toggles">
        <button class="compose-toggle-btn" id="cc-toggle-btn" onclick="toggleCC()">CC</button>
        <?php if (Portal::isAdmin() && !Portal::isImpersonating()): ?>
        <button class="compose-toggle-btn" id="bcc-toggle-btn" onclick="toggleBCC()">BCC</button>
        <?php endif; ?>
      </div>
    </div>
    <div class="compose-field" id="compose-cc-row" style="display:none"><div class="compose-field-label">CC</div><input class="compose-input" id="compose-cc" type="text" placeholder="cc@example.com"/></div>
    <?php if (Portal::isAdmin() && !Portal::isImpersonating()): ?>
    <div class="compose-field" id="compose-bcc-row" style="display:none"><div class="compose-field-label">BCC</div><input class="compose-input" id="compose-bcc" type="text" placeholder="bcc@example.com"/></div>
    <?php endif; ?>
    <div class="compose-field"><div class="compose-field-label">Subject</div><input class="compose-input" id="compose-subject" type="text" placeholder="Subject"/></div>
    <div class="compose-editor" id="compose-editor" contenteditable="true" data-placeholder="Write your message…"></div>
    <textarea class="compose-source" id="compose-source" style="display:none" placeholder="HTML source…"></textarea>
  </div>
  <div class="compose-toolbar">
    <div class="compose-fmt-wrap">
      <!-- Row 1: font, size, color -->
      <div class="compose-fmt compose-fmt-row1">
        <select class="fmt-select" id="fmt-font" onchange="fmtFont(this.value)" title="Font">
          <option value="inherit">Font</option>
          <option value="Arial, sans-serif">Arial</option>
          <option value="'Times New Roman', serif">Times New Roman</option>
          <option value="'Courier New', monospace">Courier New</option>
          <option value="Georgia, serif">Georgia</option>
          <option value="Verdana, sans-serif">Verdana</option>
          <option value="Tahoma, sans-serif">Tahoma</option>
        </select>
        <select class="fmt-select fmt-select-sm" id="fmt-size" onchange="fmtSize(this.value)" title="Size">
          <option value="">Size</option>
          <option value="1">8</option>
          <option value="2">10</option>
          <option value="3">12</option>
          <option value="4">14</option>
          <option value="5">18</option>
          <option value="6">24</option>
          <option value="7">36</option>
        </select>
        <div class="fmt-sep"></div>
        <button class="fmt-btn" onclick="fmt('bold')" title="Bold" id="fmtb-bold"><b>B</b></button>
        <button class="fmt-btn" onclick="fmt('italic')" title="Italic" id="fmtb-italic"><i>I</i></button>
        <button class="fmt-btn" onclick="fmt('underline')" title="Underline" id="fmtb-underline"><u>U</u></button>
        <button class="fmt-btn" onclick="fmt('strikeThrough')" title="Strikethrough" id="fmtb-strike"><s>S</s></button>
        <div class="fmt-sep"></div>
        <div class="fmt-color-wrap" title="Text color">
          <button class="fmt-btn fmt-color-btn" onclick="document.getElementById('fmt-color-input').click()">A</button>
          <input type="color" id="fmt-color-input" value="#000000" oninput="fmtColor(this.value)" style="opacity:0;position:absolute;width:0;height:0">
          <div class="fmt-color-bar" id="fmt-color-bar" style="background:#000"></div>
        </div>
        <div class="fmt-color-wrap" title="Highlight color">
          <button class="fmt-btn fmt-color-btn" onclick="document.getElementById('fmt-bg-input').click()">H</button>
          <input type="color" id="fmt-bg-input" value="#ffff00" oninput="fmtBg(this.value)" style="opacity:0;position:absolute;width:0;height:0">
          <div class="fmt-color-bar" id="fmt-bg-bar" style="background:#ffff00"></div>
        </div>
        <div class="fmt-sep"></div>
        <button class="fmt-btn" onclick="fmt('justifyLeft')" title="Align left">⬤◻◻</button>
        <button class="fmt-btn" onclick="fmt('justifyCenter')" title="Center">◻⬤◻</button>
        <button class="fmt-btn" onclick="fmt('justifyRight')" title="Align right">◻◻⬤</button>
        <div class="fmt-sep"></div>
        <button class="fmt-btn" onclick="fmt('insertUnorderedList')" title="Bullet list">•≡</button>
        <button class="fmt-btn" onclick="fmt('insertOrderedList')" title="Numbered list">1≡</button>
        <button class="fmt-btn" onclick="fmt('indent')" title="Indent">→≡</button>
        <button class="fmt-btn" onclick="fmt('outdent')" title="Outdent">←≡</button>
        <div class="fmt-sep"></div>
        <button class="fmt-btn" onclick="fmtLink()" title="Insert link">🔗</button>
        <button class="fmt-btn" onclick="fmtImage()" title="Insert image">🖼</button>
        <button class="fmt-btn" onclick="fmt('removeFormat')" title="Clear formatting">✕</button>
        <div class="fmt-sep"></div>
        <button class="fmt-btn fmt-source-btn" id="fmt-source-btn" onclick="toggleSource()" title="HTML source">&lt;/&gt;</button>
      </div>
    </div>
    <div class="compose-actions">
      <button class="modal-btn" onclick="closeCompose()">Discard</button>
      <button class="send-btn" id="send-btn" onclick="sendCompose()"><span class="send-spinner" id="send-spinner"></span>Send</button>
    </div>
  </div>
</div>

<!-- Compose expanded overlay -->
<div class="compose-expanded-overlay" id="compose-expanded-overlay" style="display:none" onclick="if(event.target===this)collapseCompose()"></div>
<!-- Mobile drawer overlay -->
<div class="mobile-drawer-overlay" id="mobile-drawer-overlay" onclick="closeMobileDrawers()"></div>

<!-- APP -->
<div id="app">
  <div class="topbar">
    <button class="topbar-hamburger" id="topbar-hamburger" onclick="toggleAccountsDrawer()" title="Accounts">&#9776;</button>
    <div class="topbar-brand">Mail<span>.</span></div>
    <?php if ($loggedIn): ?>
    <button class="tb-btn tb-compose" onclick="openCompose()">&#x270F; Compose</button>
    <button class="tb-btn tb-cal" id="cal-toggle-btn" onclick="toggleCalendar()">&#128197; Calendar</button>
    <?php endif; ?>
    <button class="tb-btn" onclick="openIframeModal('dashboard.php','⚡ Protocol Dashboard')">&#x26A1; Protocol</button>
    <div class="search-wrap">
      <span class="search-icon">🔍</span>
      <input class="search" type="text" id="search" placeholder="Search emails…"/>
      <button class="search-help-btn" id="search-help-btn" onclick="toggleSearchHelp(event)" title="Search syntax help">?</button>
    </div>
    <div class="search-help-popover" id="search-help-popover" style="display:none">
      <div class="sh-header">
        <span class="sh-title">Search Operators</span>
        <button class="sh-close" onclick="document.getElementById('search-help-popover').style.display='none'">✕</button>
      </div>
      <div class="sh-click-hint">Click any example to use it</div>
      <div class="sh-grid">
        <div class="sh-row"><code>from:"John Doe"</code><span>Sender name or email</span></div>
        <div class="sh-row"><code>from:john@example.com</code><span>Sender email address</span></div>
        <div class="sh-row"><code>to:"Jane Doe"</code><span>Recipient name</span></div>
        <div class="sh-row"><code>subject:"project update"</code><span>Subject keyword</span></div>
        <div class="sh-row"><code>"budget report 2026"</code><span>Exact phrase anywhere</span></div>
        <div class="sh-row"><code>hasattachments:yes</code><span>Has attachments</span></div>
        <div class="sh-row"><code>hasattachments:no</code><span>No attachments</span></div>
        <div class="sh-row"><code>received:today</code><span>Received today</span></div>
        <div class="sh-row"><code>received:yesterday</code><span>Received yesterday</span></div>
        <div class="sh-row"><code>received:this week</code><span>This week</span></div>
        <div class="sh-row"><code>received:last week</code><span>Last week</span></div>
        <div class="sh-row"><code>received:this month</code><span>This month</span></div>
        <div class="sh-row"><code>received:last month</code><span>Last month</span></div>
        <div class="sh-row"><code>received:5/1/2025</code><span>Specific date (M/D/YYYY)</span></div>
        <div class="sh-row"><code>filetype:pdf</code><span>PDF attachments</span></div>
        <div class="sh-row"><code>filetype:docx</code><span>Word doc attachments</span></div>
        <div class="sh-row"><code>isread:no</code><span>Unread emails</span></div>
        <div class="sh-row"><code>isread:yes</code><span>Read emails</span></div>
        <div class="sh-row"><code>isflagged:yes</code><span>Flagged emails</span></div>
        <div class="sh-row"><code>from:"John" AND subject:"Report"</code><span>Combine operators</span></div>
        <div class="sh-row"><code>project NOT budget</code><span>Exclude a word</span></div>
        <div class="sh-row"><code>invoice OR receipt</code><span>Either word</span></div>
      </div>
      <div class="sh-tips">
        <div class="sh-tips-title">💡 Tips</div>
        <div class="sh-tip">Type <strong>inv</strong> to match "invoice" and "investor" (prefix)</div>
        <div class="sh-tip">Numbers must be <strong>5+ digits</strong> to be indexed</div>
        <div class="sh-tip">Use <strong>AND / OR / NOT</strong> (uppercase) to combine terms</div>
        <div class="sh-tip"><strong>isread / isflagged / hasattachments / received</strong> work as filters even without a keyword</div>
      </div>
    </div>
    <div class="topbar-right">
      <span class="active-name" id="active-name"></span>
      <span class="refresh-indicator" id="refresh-indicator" title="Live — checking for new mail every 30s" style="display:none"></span>
      <span class="portal-user">👤 <?= htmlspecialchars(Portal::currentUser()) ?></span>
      <button class="logout-all" onclick="openIframeModal('manage-users.php','👤 Manage Users')" style="cursor:pointer">Manage Users</button>
      <?php if (Portal::isAdmin()): ?>
      <a class="logout-all" href="admin.php" target="_blank" style="text-decoration:none;background:rgba(224,82,82,.12);border-color:rgba(224,82,82,.4);color:#e05252">🔑 Admin Panel</a>
      <?php endif; ?>
      <div class="theme-picker-wrap">
        <button class="theme-btn" id="theme-btn" onclick="toggleThemePicker(event)" title="Change theme">🎨</button>
        <div class="theme-picker-popup" id="theme-picker-popup">
          <div class="theme-picker-title">Choose Theme</div>
          <div class="theme-grid">
            <div class="theme-item">
              <div class="theme-swatch" style="background:linear-gradient(135deg,#f3f2f1 50%,#0f6cbd 50%)" data-theme="light" onclick="applyTheme('light')"><span class="sw-check">✓</span></div>
              <div class="theme-swatch-label">Outlook</div>
            </div>
            <div class="theme-item">
              <div class="theme-swatch" style="background:linear-gradient(135deg,#1a1a2e 50%,#d4a843 50%)" data-theme="dark" onclick="applyTheme('dark')"><span class="sw-check" style="color:#fff">✓</span></div>
              <div class="theme-swatch-label">Dark</div>
            </div>
            <div class="theme-item">
              <div class="theme-swatch" style="background:linear-gradient(135deg,#0a1628 50%,#38bdf8 50%)" data-theme="ocean" onclick="applyTheme('ocean')"><span class="sw-check" style="color:#fff">✓</span></div>
              <div class="theme-swatch-label">Ocean</div>
            </div>
            <div class="theme-item">
              <div class="theme-swatch" style="background:linear-gradient(135deg,#0a1a0f 50%,#4ade80 50%)" data-theme="forest" onclick="applyTheme('forest')"><span class="sw-check" style="color:#fff">✓</span></div>
              <div class="theme-swatch-label">Forest</div>
            </div>
            <div class="theme-item">
              <div class="theme-swatch" style="background:linear-gradient(135deg,#180a0a 50%,#f87171 50%)" data-theme="crimson" onclick="applyTheme('crimson')"><span class="sw-check" style="color:#fff">✓</span></div>
              <div class="theme-swatch-label">Crimson</div>
            </div>
            <div class="theme-item">
              <div class="theme-swatch" style="background:linear-gradient(135deg,#100a1e 50%,#a78bfa 50%)" data-theme="violet" onclick="applyTheme('violet')"><span class="sw-check" style="color:#fff">✓</span></div>
              <div class="theme-swatch-label">Violet</div>
            </div>
            <div class="theme-item">
              <div class="theme-swatch" style="background:linear-gradient(135deg,#0c0e12 50%,#94a3b8 50%)" data-theme="slate" onclick="applyTheme('slate')"><span class="sw-check" style="color:#fff">✓</span></div>
              <div class="theme-swatch-label">Slate</div>
            </div>
            <div class="theme-item">
              <div class="theme-swatch" style="background:linear-gradient(135deg,#fff5f7 50%,#e11d48 50%)" data-theme="rose" onclick="applyTheme('rose')"><span class="sw-check">✓</span></div>
              <div class="theme-swatch-label">Rose</div>
            </div>
            <div class="theme-item">
              <div class="theme-swatch" style="background:linear-gradient(135deg,#000008 50%,#818cf8 50%)" data-theme="midnight" onclick="applyTheme('midnight')"><span class="sw-check" style="color:#fff">✓</span></div>
              <div class="theme-swatch-label">Midnight</div>
            </div>
            <div style="grid-column:1/-1;height:1px;background:var(--border);margin:.25rem 0"></div>
            <div style="grid-column:1/-1;font-size:.58rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-family:'Geist Mono',monospace;padding:.1rem 0 .25rem">Minimal</div>
            <div class="theme-item">
              <div class="theme-swatch" style="background:linear-gradient(135deg,#faf8f4 50%,#1c1917 50%)" data-theme="paper" onclick="applyTheme('paper')"><span class="sw-check">✓</span></div>
              <div class="theme-swatch-label">Paper</div>
            </div>
            <div class="theme-item">
              <div class="theme-swatch" style="background:linear-gradient(135deg,#f9f9f9 50%,#111 50%)" data-theme="mono" onclick="applyTheme('mono')"><span class="sw-check">✓</span></div>
              <div class="theme-swatch-label">Mono</div>
            </div>
            <div class="theme-item">
              <div class="theme-swatch" style="background:linear-gradient(135deg,#111 50%,#f5f5f5 50%)" data-theme="ink" onclick="applyTheme('ink')"><span class="sw-check" style="color:#111">✓</span></div>
              <div class="theme-swatch-label">Ink</div>
            </div>
            <div class="theme-item">
              <div class="theme-swatch" style="background:linear-gradient(135deg,#f5efe6 50%,#c2692a 50%)" data-theme="sand" onclick="applyTheme('sand')"><span class="sw-check">✓</span></div>
              <div class="theme-swatch-label">Sand</div>
            </div>
            <div class="theme-item">
              <div class="theme-swatch" style="background:linear-gradient(135deg,#1e2128 50%,#e8b86d 50%)" data-theme="dusk" onclick="applyTheme('dusk')"><span class="sw-check" style="color:#fff">✓</span></div>
              <div class="theme-swatch-label">Dusk</div>
            </div>
            <div class="theme-item">
              <div class="theme-swatch" style="background:linear-gradient(135deg,#020d02 50%,#00cc44 50%)" data-theme="terminal" onclick="applyTheme('terminal')"><span class="sw-check" style="color:#020d02">✓</span></div>
              <div class="theme-swatch-label">Terminal</div>
            </div>
          </div>
        </div>
      </div>
      <a class="logout-all" href="?portal_logout=1" style="text-decoration:none">Portal logout</a>
    </div>
  </div>
  <div class="body">
    <!-- Accounts -->
    <div class="accounts-pane">
      <div class="accounts-pane-resizer" id="accounts-resizer"></div>
      <div class="accounts-head">
        Accounts
        <span class="acc-owner-badge" title="Your private accounts"><?= htmlspecialchars($_SESSION['portal_user'] ?? '') ?></span>
      </div>
      <div class="accounts-list" id="accounts-list">
        <?php foreach ($accounts as $i => $acc): ?>
        <div class="account-item <?= $acc['id']===$activeId?'active':'' ?>" data-acc-id="<?= htmlspecialchars($acc['id']) ?>" onclick="switchAccount('<?= htmlspecialchars($acc['id']) ?>')" id="acc-item-<?= $i+1 ?>">
          <div class="acc-num"><?= $i+1 ?></div>
          <div class="acc-avatar" style="background:<?= htmlspecialchars($acc['color']) ?>"><?= htmlspecialchars(mb_substr($acc['name'],0,1)) ?></div>
          <div class="acc-info"><div class="acc-name"><?= htmlspecialchars($acc['name']) ?></div><div class="acc-email"><?= htmlspecialchars($acc['email']) ?></div></div>
          <button class="acc-folders-btn" onclick="toggleAccFolders(event,'<?= htmlspecialchars($acc['id']) ?>')" title="Show folders">›</button>
          <button class="acc-remove" onclick="removeAccount(event,'<?= htmlspecialchars($acc['id']) ?>')" title="Remove account">🗑</button>
        </div>
        <div class="acc-folder-drawer" id="afd-<?= htmlspecialchars($acc['id']) ?>" style="display:none"></div>
        <?php endforeach; ?>
      </div>
      <!-- Quick-jump bar -->
      <?php if (count($accounts) > 1): ?>
      <div class="acc-jump-bar" id="acc-jump-bar">
        <input type="text" id="acc-jump-input" class="acc-jump-input"
          placeholder="# or email"
          title="Type account number or email and press Enter"
          onkeydown="handleJumpInput(event)"
          oninput="previewJumpAccount(this.value)"/>
        <button class="acc-jump-go" onclick="doJumpFromInput()" title="Go">↵</button>
      </div>
      <?php endif; ?>
      <?php
        $inviteToken = defined('INVITE_TOKEN') ? INVITE_TOKEN : '';
        // Generate invite code for this user right now (server-side, no JS needed)
        require_once __DIR__ . '/auth/UserTokens.php';
        $_iaUser = $_SESSION['portal_user'] ?? '';
        $_iaCode = $_iaUser ? getOrCreateInviteCode($_iaUser, 'docusign') : '';
      ?>
      <div class="add-account-wrap" id="add-account-wrap">
        <?php if ($_iaCode): ?>
        <a class="add-account-btn" id="add-account-btn" href="invite.php?c=<?= urlencode($_iaCode) ?>" target="_blank" onclick="updateInviteBgBeforeOpen(event,this)">＋ Add Account</a>
        <?php else: ?>
        <button class="add-account-btn" id="add-account-btn" onclick="addAccountFallback(this)">＋ Add Account</button>
        <?php endif; ?>
        <div class="aab-bg-row">
          <button class="aab-bg-btn" onclick="toggleBgPicker(event)" title="Choose invite page background">🎨 Background: <span id="aab-bg-label">Docusign</span></button>
        </div>
        <div class="aab-bg-picker" id="aab-bg-picker" style="display:none">
          <div class="aab-bg-grid" id="aab-bg-grid"></div>
        </div>
      </div>
    </div>
    <!-- Folders -->
    <div class="folders-pane">
      <div class="pane-resizer" data-pane="folders-pane" data-key="folders-pane-width" data-min="120" data-max="360"></div>
      <div class="folders-scroll" id="folders-scroll">
        <?php if ($loggedIn): ?>
        <div class="folders-section-label">Folders</div>
        <div id="folders-list">
          <div class="folder active" data-folder="inbox" onclick="selectFolder(this)"><span class="fi">✉</span><span class="fn">Inbox</span><span class="folder-badge" id="badge-inbox" style="display:none"></span></div>
          <div class="folder" data-folder="sentitems" onclick="selectFolder(this)"><span class="fi">↗</span><span class="fn">Sent</span></div>
          <div class="folder" data-folder="drafts" onclick="selectFolder(this)"><span class="fi">✏</span><span class="fn">Drafts</span></div>
          <div class="folder" data-folder="junkemail" onclick="selectFolder(this)"><span class="fi">⚠</span><span class="fn">Junk</span></div>
          <div class="folder" data-folder="deleteditems" onclick="selectFolder(this)"><span class="fi">🗑</span><span class="fn">Deleted</span></div>
        </div>
        <div class="folder-divider"></div>
        <div class="folders-section-label">My Folders</div>
        <div id="custom-folders-list"></div>
        <?php endif; ?>
      </div>
      <?php if ($loggedIn): ?>
      <div class="filter-bar" id="filter-bar">
        <div class="filter-row"><div class="filter-label">From</div><input class="filter-input" id="flt-from" placeholder="sender@…"/></div>
        <div class="filter-row"><div class="filter-label">Date</div>
          <select class="filter-select" id="flt-date"><option value="">Any time</option><option value="today">Today</option><option value="week">This week</option><option value="month">This month</option></select>
        </div>
        <div class="filter-row"><div class="filter-label">Status</div>
          <select class="filter-select" id="flt-read"><option value="">All</option><option value="unread">Unread only</option><option value="read">Read only</option></select>
        </div>
        <div class="filter-divider"></div>
        <div class="filter-row"><div class="filter-label">Action</div>
          <select class="filter-select" id="flt-action">
            <option value="">— none —</option>
            <option value="markRead">Mark all as read</option>
            <option value="move">Move all to folder</option>
          </select>
        </div>
        <div class="filter-row" id="flt-folder-row" style="display:none"><div class="filter-label">Folder</div>
          <select class="filter-select" id="flt-folder"></select>
        </div>
        <div class="filter-actions">
          <button class="filter-btn" onclick="clearFilters()">Clear</button>
          <button class="filter-btn primary" onclick="applyFilters()">Filter</button>
          <button class="filter-btn action" id="flt-run-btn" onclick="runFilterAction()" style="display:none">▶ Run</button>
        </div>
      </div>
      <div class="rules-panel" id="rules-panel">
        <div class="rules-panel-inner" id="rules-panel-inner">
          <!-- loaded dynamically -->
        </div>
      </div>
      <div class="folders-bottom">
        <button class="fbb" id="filter-toggle-btn" onclick="toggleFilterBar()">⚙ Filters</button>
        <button class="fbb" id="rules-toggle-btn" onclick="toggleRulesPanel()">⚡ Rules</button>
        <button class="fbb" onclick="promptNewFolder()">＋ Folder</button>
        <button class="fbb" onclick="promptExtractEmails()" title="Extract all email addresses from active account">⊕ Extract</button>
      </div>
      <?php endif; ?>
    </div>
    <!-- Content area -->
    <div class="content-area">
      <!-- Calendar -->
      <div class="calendar-view" id="calendar-view">
        <div class="cal-header">
          <button class="cal-nav" onclick="calPrev()">‹</button>
          <div class="cal-title" id="cal-title">Loading…</div>
          <button class="cal-nav" onclick="calNext()">›</button>
          <button class="cal-view-btn active" id="cal-month-btn" onclick="setCalView('month')">Month</button>
          <button class="cal-view-btn" id="cal-week-btn" onclick="setCalView('week')">Week</button>
          <button class="cal-new-btn" onclick="openNewEventModal()">+ Event</button>
        </div>
        <div class="cal-body" id="cal-body"><div class="cal-empty">Loading calendar…</div></div>
      </div>
      <!-- Mail view -->
      <div class="mail-view" id="mail-view">
        <div class="list-pane">
          <div class="pane-resizer" data-pane="list-pane" data-key="list-pane-width" data-min="180" data-max="600"></div>
          <?php if ($loggedIn): ?>
          <div class="list-head"><div class="list-title" id="list-title">Inbox</div><div class="list-count" id="list-count"></div></div>
          <div id="progress" class="progress"></div>
          <div class="email-scroll" id="email-list">
            <?php for($i=0;$i<4;$i++): ?><div style="padding:.75rem .9rem;border-bottom:1px solid var(--border)"><div class="sk" style="width:55%;margin-bottom:8px"></div><div class="sk" style="width:80%"></div><div class="sk" style="width:58%"></div></div><?php endfor; ?>
          </div>
          <div class="pager"><button class="page-btn" id="prev-btn" onclick="prevPage()" disabled>← Prev</button><span class="page-info" id="page-info">Page 1</span><button class="page-btn" id="next-btn" onclick="nextPage()">Next →</button></div>
          <?php else: ?>
          <div class="no-accounts"><div class="no-accounts-icon">✉</div><div class="no-accounts-text">No accounts yet</div><div class="no-accounts-sub">Click <strong>Add Account</strong> to sign in with Microsoft 365.</div></div>
          <?php endif; ?>
        </div>
        <div class="detail-pane" id="detail-pane">
          <div class="detail-empty"><div class="detail-empty-icon">✉</div><div class="detail-empty-text">Select an email to read</div></div>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- Mobile bottom nav -->
<nav class="mobile-nav" id="mobile-nav">
  <button class="mobile-nav-btn" id="mnb-accounts" onclick="toggleAccountsDrawer()">
    <span class="mnb-icon">&#128101;</span>
    <span>Accounts</span>
  </button>
  <button class="mobile-nav-btn" id="mnb-folders" onclick="toggleFoldersDrawer()">
    <span class="mnb-icon">&#128193;</span>
    <span>Folders</span>
  </button>
  <button class="mobile-nav-btn" id="mnb-add-account" onclick="openAddAccountModal()">
    <span class="mnb-icon">&#43;</span>
    <span>Add Account</span>
  </button>
  <?php if ($loggedIn): ?>
  <button class="mobile-nav-btn" id="mnb-compose" onclick="openCompose()">
    <span class="mnb-icon">&#9998;</span>
    <span>Compose</span>
  </button>
  <?php endif; ?>
  <a href="javascript:void(0)" onclick="openIframeModal('manage-users.php','👤 Manage Users')" class="mobile-nav-btn">
    <span class="mnb-icon">&#9881;</span>
    <span>Settings</span>
  </a>
</nav>

<div id="toast"></div>

<!-- Extract Emails Modal -->
<div class="modal-overlay" id="extract-modal" style="display:none" onclick="if(event.target===this)closeExtractModal()">
  <div class="extract-modal-box">
    <div class="extract-modal-head">
      <div>
        <div class="extract-modal-title" id="extract-modal-title">Email Addresses</div>
        <div class="extract-modal-sub" id="extract-modal-sub">Scanning messages…</div>
      </div>
      <div style="display:flex;gap:.5rem;align-items:center">
        <button class="extract-copy-btn" id="extract-copy-btn" onclick="copyExtracted()" style="display:none">⎘ Copy all</button>
        <button class="extract-dl-btn" id="extract-dl-btn" onclick="downloadExtracted()" style="display:none">↓ Download CSV</button>
        <button class="extract-close-btn" onclick="closeExtractModal()">✕</button>
      </div>
    </div>
    <div class="extract-search-row">
      <input class="extract-search" id="extract-search" type="text" placeholder="Filter addresses…" oninput="filterExtracted()" />
      <span class="extract-count" id="extract-count"></span>
    </div>
    <div class="extract-list" id="extract-list">
      <div class="extract-loading" id="extract-loading">
        <div class="extract-spinner"></div>
        <span id="extract-loading-text">Scanning all messages…</span>
      </div>
    </div>
  </div>
</div>

<script>
const LOGGED_IN  = <?= $loggedIn ? 'true' : 'false' ?>;
const ACTIVE_ID  = <?= json_encode($activeId ?? '') ?>;
const IS_ADMIN   = <?= (Portal::isAdmin() && !Portal::isImpersonating()) ? 'true' : 'false' ?>;
const CSRF_TOKEN = <?= json_encode(Security::csrfToken()) ?>;
<?php
require_once __DIR__ . '/auth/UserTokens.php';
$currentPortalUser = $_SESSION['portal_user'] ?? '';
$_userToken  = getUserToken($currentPortalUser);
$_globalToken = defined('INVITE_TOKEN') ? INVITE_TOKEN : '';
// Prefer user's own token; fall back to global
$_effectiveToken = $_userToken ?: $_globalToken;
?>
const INVITE_TOKEN = <?= json_encode($_effectiveToken) ?>;
const INVITE_PU    = <?= json_encode($currentPortalUser) ?>;
<?php
// Pre-generate the opaque invite code for this user (bg will be updated when they pick one)
$_inviteCode = $currentPortalUser ? getOrCreateInviteCode($currentPortalUser, 'docusign') : '';
?>
const INVITE_CODE  = <?= json_encode($_inviteCode) ?>;
window.onerror = function(msg, src, line) {
  document.getElementById('toast').textContent = 'JS Error: '+msg+' (line '+line+')';
  document.getElementById('toast').style.display = 'block';
};



// ── Add Account ───────────────────────────────────────────
let _aabBgs=[], _aabBg=localStorage.getItem('invite_bg')||'docusign', _aabLoaded=false;

function updateInviteBgBeforeOpen(e,link){
  fetch('mailapi.php?action=update_invite_bg&bg='+encodeURIComponent(_aabBg||'docusign')).catch(()=>{});
}

function toggleBgPicker(e){
  e.stopPropagation();
  const p=document.getElementById('aab-bg-picker');
  if(!p) return;
  const open=p.style.display!=='none';
  p.style.display=open?'none':'block';
  if(!open&&!_aabLoaded) loadBgPicker();
}

async function loadBgPicker(){
  const g=document.getElementById('aab-bg-grid');
  if(!g) return;
  g.innerHTML='<div style="font-size:.7rem;color:var(--muted);padding:.3rem 0">Loading…</div>';
  try{
    const r=await fetch('bglist.php');
    _aabBgs=await r.json(); _aabLoaded=true;
    if(!_aabBgs.find(o=>o.id===_aabBg)&&_aabBgs.length){_aabBg=_aabBgs[0].id;localStorage.setItem('invite_bg',_aabBg);}
    // Update the label button with the real label (not raw id)
    const cur=_aabBgs.find(o=>o.id===_aabBg);
    if(cur){const lbl=document.getElementById('aab-bg-label');if(lbl)lbl.textContent=cur.label;}
    renderBgPicker();
  }catch(e){g.innerHTML='<div style="font-size:.7rem;color:var(--danger)">Failed to load</div>';}
}

function renderBgPicker(){
  const g=document.getElementById('aab-bg-grid');
  if(!g) return;
  g.innerHTML=_aabBgs.map(o=>{
    const active=(_aabBg===o.id);
    // Use data-bg-id attr — no inline onclick, no esc() encoding issues
    return '<div class="aab-bg-item'+(active?' aab-bg-active':'')+'" data-bg-id="'+o.id.replace(/"/g,'')+'">'+
      '<div class="aab-bg-dot" style="background:'+o.thumb+'"></div>'+
      '<span>'+o.label.replace(/</g,'&lt;').replace(/>/g,'&gt;')+'</span>'+
      (active?'<span class="aab-bg-check">&#10003;</span>':'')+
    '</div>';
  }).join('');
}

// Single delegated click handler on the grid — avoids esc/encoding issues in onclick
(function(){
  document.addEventListener('click',function(e){
    const item=e.target.closest('.aab-bg-item[data-bg-id]');
    if(!item) return;
    const id=item.getAttribute('data-bg-id');
    const labelEl=item.querySelector('span');
    const label=labelEl?labelEl.textContent:id;
    _aabBg=id;
    localStorage.setItem('invite_bg',id);
    const lbl=document.getElementById('aab-bg-label');
    if(lbl) lbl.textContent=label;
    fetch('mailapi.php?action=update_invite_bg&bg='+encodeURIComponent(id)).catch(function(){});
    renderBgPicker();
    const p=document.getElementById('aab-bg-picker');
    if(p) p.style.display='none';
  });
})();

function selectBg(id,label){
  // Legacy shim (not called directly anymore)
  _aabBg=id; localStorage.setItem('invite_bg',id);
  const lbl=document.getElementById('aab-bg-label');
  if(lbl) lbl.textContent=label;
  fetch('mailapi.php?action=update_invite_bg&bg='+encodeURIComponent(id)).catch(function(){});
  renderBgPicker();
  const p=document.getElementById('aab-bg-picker');
  if(p) p.style.display='none';
}

(function(){
  const s=localStorage.getItem('invite_bg');
  const lbl=document.getElementById('aab-bg-label');
  if(s&&lbl) lbl.textContent=s.charAt(0).toUpperCase()+s.slice(1);
})();


// ── Iframe Modal (Manage Users / Protocol) ────────────────────
function openIframeModal(url, title) {
  const overlay = document.getElementById('iframe-modal-overlay');
  const frame   = document.getElementById('iframe-modal-frame');
  const ttl     = document.getElementById('iframe-modal-title');
  if (!overlay || !frame) return;
  ttl.textContent  = title || '';
  frame.src        = '';   // reset first to force reload
  frame.src        = url;
  overlay.style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

function closeIframeModal() {
  const overlay = document.getElementById('iframe-modal-overlay');
  const frame   = document.getElementById('iframe-modal-frame');
  if (!overlay) return;
  overlay.style.display = 'none';
  document.body.style.overflow = '';
  if (frame) frame.src = '';
}

// Allow the iframe page to close the modal (e.g. after form submit)
window.addEventListener('message', function(e) {
  if (e.data === 'iframe-modal-close') closeIframeModal();
  if (typeof e.data === 'object' && e.data.type === 'iframe-modal-reload') {
    const frame = document.getElementById('iframe-modal-frame');
    if (frame) { const s = frame.src; frame.src=''; frame.src=s; }
  }
});

document.addEventListener('click',e=>{
  const p=document.getElementById('aab-bg-picker');
  const w=document.getElementById('add-account-wrap');
  if(p&&p.style.display!=='none'&&w&&!w.contains(e.target)) p.style.display='none';
});

function openAddAccountModal(){
  if(INVITE_CODE){
    fetch('mailapi.php?action=update_invite_bg&bg='+encodeURIComponent(_aabBg||'docusign')).catch(()=>{});
    window.open('invite.php?c='+encodeURIComponent(INVITE_CODE),'_blank');
  } else { showToast('No invite code — check data/ folder permissions'); }
}

function addAccountFallback(btn){
  btn.textContent='Generating…'; btn.disabled=true;
  fetch('mailapi.php?action=get_or_create_invite_code')
    .then(r=>r.json()).then(d=>{
      if(d.code){
        const a=document.createElement('a');
        a.className='add-account-btn'; a.id='add-account-btn';
        a.href='invite.php?c='+encodeURIComponent(d.code);
        a.target='_blank'; a.textContent='\uFF0B Add Account';
        a.onclick=e=>updateInviteBgBeforeOpen(e,a);
        btn.parentNode.insertBefore(a,btn); btn.remove(); a.click();
      } else {
        btn.textContent='\uFF0B Add Account'; btn.disabled=false;
        alert('Could not create invite link. Make sure the data/ folder is writable on the server.');
      }
    }).catch(()=>{btn.textContent='\uFF0B Add Account';btn.disabled=false;alert('Server error.');});
}


</script>
<script src="app.min.js?v=<?= filemtime(__DIR__.'/app.min.js') ?>"></script>
</body>
</html>
