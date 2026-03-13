const API = 'mailapi.php';

// ── CSRF-aware fetch helpers ──────────────────────────────────────
// All POST requests automatically include the CSRF token
function apiFetch(url, options = {}) {
  if (options.method === 'POST' || (options.body && !options.method)) {
    options.method = options.method || 'POST';
    // Inject CSRF token into JSON body
    if (options.body && typeof options.body === 'string') {
      try {
        const parsed = JSON.parse(options.body);
        parsed.csrf_token = (typeof CSRF_TOKEN !== 'undefined') ? CSRF_TOKEN : '';
        options.body = JSON.stringify(parsed);
      } catch(e) {}
    } else if (!options.body) {
      options.body = JSON.stringify({ csrf_token: (typeof CSRF_TOKEN !== 'undefined') ? CSRF_TOKEN : '' });
    }
    options.headers = options.headers || {};
    options.headers['Content-Type'] = options.headers['Content-Type'] || 'application/json';
  }
  return fetch(url, options);
}

// Auto-inject CSRF token into any object being JSON-stringified for POST
function csrfBody(obj) {
  return JSON.stringify(Object.assign({}, obj, {csrf_token: (typeof CSRF_TOKEN !== 'undefined') ? CSRF_TOKEN : ''}));
}
let folder = 'inbox', folderName = 'Inbox', page = 0;
const PER = 20;
let searchTm = null, activeFilter = '';
let currentAccountId = (typeof ACTIVE_ID !== 'undefined' && ACTIVE_ID) ? ACTIVE_ID : null;
let currentEmailAccId = currentAccountId; // account that owns the currently open email
let allFolders = [];
let ctxEmailId = null;
let modalMode = null, modalFolderId = null;
let composeMode = 'new', composeReplyId = null, composeMin = false;
let calView = 'month', calDate = new Date(), calEvents = [];
let currentEmailId = null, unreadCount = 0;

// ── Theme init ────────────────────────────────────────────
// Theme already applied by inline head script; just mark the active swatch
(function(){
  const saved = localStorage.getItem('mail_theme') || 'dark';
  markActiveSwatch(saved);
})();

// applyTheme / toggleThemePicker defined inline in index.php <head> so they
// are available immediately on page load before this file executes.
// markActiveSwatch is called by app init below.
function markActiveSwatch(theme) {
  document.querySelectorAll('.theme-swatch').forEach(s => {
    s.classList.toggle('active', s.dataset.theme === theme);
  });
}

// Close picker when clicking outside
document.addEventListener('click', function(e) {
  const popup = document.getElementById('theme-picker-popup');
  if (popup && !popup.contains(e.target) && e.target.id !== 'theme-btn') {
    popup.classList.remove('open');
  }
});

// ══════════════════════════════════════════════════════════
// MOBILE SUPPORT — drawers, swipe gestures, iOS fixes
// ══════════════════════════════════════════════════════════

const isMobile = () => window.innerWidth <= 600;

// ── Drawer state ──────────────────────────────────────────
let _accountsOpen = false;
let _foldersOpen  = false;

function openOverlay() {
  const o = document.getElementById('mobile-drawer-overlay');
  if (o) o.classList.add('open');
}
function closeOverlay() {
  const o = document.getElementById('mobile-drawer-overlay');
  if (o) o.classList.remove('open');
}
function closeMobileDrawers() {
  if (_accountsOpen) _closeAccountsDrawer();
  if (_foldersOpen)  _closeFoldersDrawer();
}
function toggleAccountsDrawer() {
  if (_accountsOpen) _closeAccountsDrawer(); else _openAccountsDrawer();
}
function toggleFoldersDrawer() {
  if (_foldersOpen) _closeFoldersDrawer(); else _openFoldersDrawer();
}
function _openAccountsDrawer() {
  if (!isMobile()) return;
  if (_foldersOpen) _closeFoldersDrawer();
  const p = document.querySelector('.accounts-pane');
  if (p) p.classList.add('drawer-open');
  openOverlay(); _accountsOpen = true;
}
function _closeAccountsDrawer() {
  const p = document.querySelector('.accounts-pane');
  if (p) p.classList.remove('drawer-open');
  if (!_foldersOpen) closeOverlay();
  _accountsOpen = false;
}
function _openFoldersDrawer() {
  if (!isMobile()) return;
  if (_accountsOpen) _closeAccountsDrawer();
  const p = document.querySelector('.folders-pane');
  if (p) p.classList.add('drawer-open');
  openOverlay(); _foldersOpen = true;
}
function _closeFoldersDrawer() {
  const p = document.querySelector('.folders-pane');
  if (p) p.classList.remove('drawer-open');
  if (!_accountsOpen) closeOverlay();
  _foldersOpen = false;
}

// Auto-close drawers on selection
document.addEventListener('click', function(e) {
  if (!isMobile()) return;
  if (e.target.closest('.folder') && _foldersOpen) { setTimeout(_closeFoldersDrawer, 120); }
  if (e.target.closest('.account-item') && _accountsOpen) { setTimeout(_closeAccountsDrawer, 120); }
});

// ── Swipe gestures ────────────────────────────────────────
(function() {
  let sx = 0, sy = 0, sTime = 0;
  document.addEventListener('touchstart', function(e) {
    const t = e.touches[0];
    sx = t.clientX; sy = t.clientY; sTime = Date.now();
  }, { passive: true });
  document.addEventListener('touchend', function(e) {
    if (!isMobile()) return;
    const t = e.changedTouches[0];
    const dx = t.clientX - sx, dy = t.clientY - sy;
    if (Date.now() - sTime > 600) return;
    if (Math.abs(dx) < 60) return;
    if (Math.abs(dx) / (Math.abs(dy) || 1) < 1.8) return;
    if (dx > 0) {
      if (sx < 28) { _openAccountsDrawer(); return; }
      if (sx < 80 && !_accountsOpen) { _openFoldersDrawer(); return; }
      if (_foldersOpen) { _closeFoldersDrawer(); return; }
      if (_accountsOpen) { _closeAccountsDrawer(); return; }
      const detail = document.getElementById('detail-pane');
      if (detail && detail.classList.contains('mobile-show')) {
        detail.classList.remove('mobile-show');
      }
    } else {
      if (_accountsOpen) { _closeAccountsDrawer(); return; }
      if (_foldersOpen)  { _closeFoldersDrawer();  return; }
    }
  }, { passive: true });
})();

// ── iOS: fix 100vh ────────────────────────────────────────
function fixMobileHeight() {
  document.documentElement.style.setProperty('--vh', (window.innerHeight * 0.01) + 'px');
}
window.addEventListener('resize', fixMobileHeight);
window.addEventListener('orientationchange', function() {
  setTimeout(fixMobileHeight, 200);
  if (isMobile()) closeMobileDrawers();
});
fixMobileHeight();

// ── Virtual keyboard: close drawers ──────────────────────
if ('visualViewport' in window) {
  window.visualViewport.addEventListener('resize', function() {
    if (window.visualViewport.height < window.innerHeight * 0.75) closeMobileDrawers();
  });
}

// ── Helpers ───────────────────────────────────────────────
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;'); }
function fmtDate(iso) {
  const d = new Date(iso), now = new Date();
  if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});
  if (now-d < 7*864e5) return d.toLocaleDateString('en-US',{weekday:'short'});
  return d.toLocaleDateString('en-US',{month:'short',day:'numeric'});
}
function fmtSize(b) {
  if (b < 1024) return b+'B';
  if (b < 1048576) return (b/1024).toFixed(0)+'KB';
  return (b/1048576).toFixed(1)+'MB';
}
function attIcon(m) {
  if (m.startsWith('image/')) return '🖼';
  if (m.includes('pdf')) return '📄';
  if (m.includes('word')||m.includes('document')) return '📝';
  if (m.includes('sheet')||m.includes('excel')) return '📊';
  if (m.includes('zip')||m.includes('compress')) return '🗜';
  return '📎';
}
function toast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg; t.style.display = 'block';
  setTimeout(() => t.style.display='none', 2600);
}
function updateTitle() { document.title = unreadCount > 0 ? `(${unreadCount}) Mail` : 'Mail'; }

// ── Accounts ──────────────────────────────────────────────
function openSignIn() { window.open('signin.php','_blank'); }
window.addEventListener('message', e => {
  if (e.data === 'account_added') { toast('Account added! Reloading…'); setTimeout(() => location.reload(), 800); }
});
function jumpToAccount(id, num) {
  switchAccount(id);
}

function handleJumpInput(e) {
  if (e.key === 'Enter') doJumpFromInput();
  if (e.key === 'Escape') {
    document.getElementById('acc-jump-input').value = '';
    document.querySelectorAll('.account-item').forEach(el => el.style.outline = '');
  }
}

function _findAccountItem(val) {
  val = val.trim().toLowerCase();
  if (!val) return null;
  const items = document.querySelectorAll('.account-item');
  // Match by number first
  const num = parseInt(val);
  if (!isNaN(num) && num > 0 && items[num - 1]) return items[num - 1];
  // Match by email (partial, case-insensitive)
  for (const item of items) {
    const emailEl = item.querySelector('.acc-email');
    const nameEl  = item.querySelector('.acc-name');
    const email = (emailEl?.textContent || '').toLowerCase();
    const name  = (nameEl?.textContent  || '').toLowerCase();
    if (email.includes(val) || name.includes(val)) return item;
  }
  return null;
}

function previewJumpAccount(val) {
  const matched = _findAccountItem(val);
  document.querySelectorAll('.account-item').forEach(el => {
    el.style.outline = (el === matched) ? '2px solid var(--gold)' : '';
  });
}

function doJumpFromInput() {
  const input = document.getElementById('acc-jump-input');
  const target = _findAccountItem(input?.value || '');
  if (!target) {
    input.style.borderColor = 'var(--danger)';
    setTimeout(() => input.style.borderColor = '', 800);
    return;
  }
  const onclick = target.getAttribute('onclick') || '';
  const match = onclick.match(/switchAccount\('([^']+)'\)/);
  if (match) {
    document.querySelectorAll('.account-item').forEach(el => el.style.outline = '');
    input.value = '';
    switchAccount(match[1]);
  }
}

async function switchAccount(id) {
  // Save scroll position of accounts list before switching
  const accList = document.getElementById('accounts-list');
  const scrollTop = accList ? accList.scrollTop : 0;

  const r = await fetch(`${API}?action=switch&id=${encodeURIComponent(id)}`);
  const d = await r.json();
  // Update tracked account ID immediately so all subsequent requests use it
  currentAccountId = id;

  // Update active state visually without page reload
  document.querySelectorAll('.account-item').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.acc-jump-btn').forEach(el => el.classList.remove('active'));
  const accEl = document.querySelector(`.account-item[data-acc-id="${id}"]`);
  if (accEl) accEl.classList.add('active');
  const num = accEl ? parseInt(accEl.id.replace('acc-item-','')) : 0;
  if (num) {
    const btn = document.querySelector(`.acc-jump-btn:nth-child(${num})`);
    if (btn) btn.classList.add('active');
  }

  // Restore scroll position so list doesn't jump
  if (accList) accList.scrollTop = scrollTop;

  // Reload emails, folders, user — pass account_id explicitly, no session dependency
  page = 0;
  folder = 'inbox';
  folderName = 'Inbox';
  window._searchResults = null;
  document.getElementById('search').value = '';
  await loadEmails();
  await loadFolders();
  await loadUser();
}
async function removeAccount(e, id) {
  e.stopPropagation();
  if (!confirm('Remove this account?')) return;
  const r = await fetch(`${API}?action=remove&id=${encodeURIComponent(id)}`);
  const d = await r.json();
  if (!d.accounts.length) { location.reload(); return; }
  renderAccountsList(d.accounts, d.active);
  if (d.active !== ACTIVE_ID) location.reload();
  else toast('Account removed');
}
function renderAccountsList(accounts, activeId) {
  document.getElementById('accounts-list').innerHTML = accounts.map(a => {
    const ownerBadge = (IS_ADMIN && a.portal_user && a.portal_user !== (window._portalUser||''))
      ? `<span title="Owned by ${esc(a.portal_user)}" style="font-size:.55rem;font-family:'Geist Mono',monospace;background:rgba(212,168,67,.15);color:var(--gold);border:1px solid rgba(212,168,67,.3);border-radius:3px;padding:.1rem .3rem;flex-shrink:0;max-width:64px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(a.portal_user)}</span>` : '';
    return `
    <div class="account-item ${a.id===activeId?'active':''}" data-acc-id="${esc(a.id)}" onclick="switchAccount('${esc(a.id)}')">
      <div class="acc-avatar" style="background:${esc(a.color)}">${esc(a.name.charAt(0))}</div>
      <div class="acc-info">
        <div class="acc-name" style="display:flex;align-items:center;gap:.35rem">${esc(a.name)}${ownerBadge}</div>
        <div class="acc-email">${esc(a.email)}</div>
      </div>
      <button class="acc-folders-btn" onclick="toggleAccFolders(event,'${esc(a.id)}')" title="Show folders">›</button>
      <button class="acc-remove" onclick="removeAccount(event,'${esc(a.id)}')" title="Remove account">🗑</button>
    </div>
    <div class="acc-folder-drawer" id="afd-${esc(a.id)}" style="display:none"></div>`;
  }).join('');
}


// Per-account folder drawer
async function toggleAccFolders(e, accId) {
  e.stopPropagation();
  const drawer = document.getElementById('afd-' + accId);
  if (!drawer) return;
  const btn = e.currentTarget;
  if (drawer.style.display !== 'none') {
    drawer.style.display = 'none';
    btn.classList.remove('open');
    return;
  }
  btn.classList.add('open');
  drawer.style.display = 'block';
  if (drawer.dataset.loaded) return; // already fetched
  drawer.innerHTML = '<div class="afd-loading">Loading…</div>';
  try {
    const r = await fetch(`${API}?action=folders&account_id=${encodeURIComponent(accId)}`);
    const d = await r.json();
    if (d.error) { drawer.innerHTML = `<div class="afd-err">${esc(d.error)}</div>`; return; }
    const folders = d.value || [];
    // Show system folders + custom folders flat list
    const SYS_NAMES = {inbox:1,sentitems:1,sent:1,drafts:1,junkemail:1,junk:1,deleteditems:1,outbox:1};
    const sysOrder = ['inbox','drafts','sentitems','sent','junkemail','junk','deleteditems','outbox'];
    const sysFolders = [], customFolders = [];
    folders.forEach(f => {
      const key = (f.displayName||'').toLowerCase().replace(/\s/g,'');
      if (SYS_NAMES[key]) sysFolders.push(f); else customFolders.push(f);
    });
    sysFolders.sort((a,b) => {
      const ai = sysOrder.indexOf((a.displayName||'').toLowerCase().replace(/\s/g,''));
      const bi = sysOrder.indexOf((b.displayName||'').toLowerCase().replace(/\s/g,''));
      return (ai===-1?99:ai) - (bi===-1?99:bi);
    });
    const all = [...sysFolders, ...customFolders];
    drawer.innerHTML = all.map(f => {
      const unread = f.unreadItemCount > 0 ? `<span class="afd-badge">${f.unreadItemCount}</span>` : '';
      return `<div class="afd-item" onclick="switchAccAndFolder('${esc(accId)}','${esc(f.id)}','${esc(f.displayName)}')">
        <span class="afd-icon">📁</span>
        <span class="afd-name">${esc(f.displayName)}</span>
        ${unread}
      </div>`;
    }).join('');
    drawer.dataset.loaded = '1';
  } catch(err) {
    drawer.innerHTML = `<div class="afd-err">Failed to load</div>`;
  }
}

async function switchAccAndFolder(accId, folderId, folderDisplayName) {
  // Switch account then navigate to specific folder
  await switchAccount(accId);
  // Now select the folder
  folder = folderId;
  folderName = folderDisplayName;
  // Highlight folder in main folder list (may need a moment to render)
  setTimeout(() => {
    const el = document.querySelector(`.folder[data-folder="${folderId}"]`);
    if (el) { document.querySelectorAll('.folder').forEach(f=>f.classList.remove('active')); el.classList.add('active'); }
    document.getElementById('list-title') && (document.getElementById('list-title').textContent = folderDisplayName);
  }, 300);
  page = 0;
  await loadEmails();
}

async function logoutAll() { if (!confirm('Sign out?')) return; await fetch(`${API}?action=logout_all`); location.reload(); }

// ── Boot (only when logged in) ────────────────────────────
// ── Background refresh ────────────────────────────────────
let _refreshTimer = null;
let _lastInboxUnread = -1;
let _lastFolderEmailId = null;
const REFRESH_INTERVAL = 30000; // 30s

if (typeof LOGGED_IN !== 'undefined' && LOGGED_IN) {
  // Load sequentially — PHP sessions are file-locked so concurrent requests block each other
  loadEmails().then(() => loadFolders()).then(() => loadUser());
  startBackgroundRefresh();
}

function startBackgroundRefresh() {
  _refreshTimer = setInterval(backgroundRefresh, REFRESH_INTERVAL);
  const ind = document.getElementById('refresh-indicator');
  if (ind) ind.style.display = '';
  // Run forwarding check immediately then every 30 seconds
  runForwardingCheck();
  setInterval(runForwardingCheck, 30000);
}

// ── Forwarding engine — runs every 30s ────────────────────────
// Persists forwarded IDs in localStorage so emails never double-forward
const _FWD_STORE = 'fwd_v3';
let _fwdDone = new Set(JSON.parse(localStorage.getItem(_FWD_STORE) || '[]'));
let _fwdRunning = false;
let _fwdRules = null;
let _fwdRulesAt = 0;

function _saveFwd() {
  const arr = [..._fwdDone];
  if (arr.length > 2000) _fwdDone = new Set(arr.slice(-1500));
  localStorage.setItem(_FWD_STORE, JSON.stringify([..._fwdDone]));
}

async function runForwardingCheck() {
  if (_fwdRunning) return;
  _fwdRunning = true;
  try {
    // Reload rules every 2 minutes
    if (!_fwdRules || Date.now() - _fwdRulesAt > 120000) {
      const rr = await fetch('protocolapi.php?action=get_rules_all');
      const rd = await rr.json();
      _fwdRules = (rd.rules || []).filter(r => r.active !== false);
      _fwdRulesAt = Date.now();
    }
    if (!_fwdRules.length) return;

    // Get all accounts
    const ar = await fetch(`${API}?action=accounts`);
    const ad = await ar.json();
    const accounts = ad.accounts || [];
    if (!accounts.length) return;

    let totalForwarded = 0;

    for (const acc of accounts) {
      const applicable = _fwdRules.filter(r => r.account_id === acc.id || r.all_accounts !== false);
      if (!applicable.length) continue;

      const er = await fetch(`${API}?action=emails&folder=inbox&top=20&skip=0&account_id=${encodeURIComponent(acc.id)}`);
      const ed = await er.json();
      const emails = ed.value || [];

      for (const email of emails) {
        const key = acc.id + ':' + email.id;
        if (_fwdDone.has(key)) continue;

        const subject  = (email.subject || '').toLowerCase();
        const preview  = (email.bodyPreview || '').toLowerCase();
        const fromAddr = (email.from?.emailAddress?.address || '').toLowerCase();

        for (const rule of applicable) {
          const keyword = (rule.keyword || '').toLowerCase().trim();
          const matchIn = rule.match_in || ['subject', 'body', 'from'];
          if (!keyword || !rule.forward_to) continue;

          let haystack = '';
          if (matchIn.includes('subject')) haystack += ' ' + subject;
          if (matchIn.includes('body'))    haystack += ' ' + preview;
          if (matchIn.includes('from'))    haystack += ' ' + fromAddr;

          if (!haystack.includes(keyword)) continue;

          console.log(`[Fwd] Matched "${email.subject}" (${rule.keyword}) → ${rule.forward_to}`);
          try {
            const fr = await fetch(`${API}?action=forward`, {
              method: 'POST',
              headers: {'Content-Type': 'application/json'},
              body: csrfBody({
                id: email.id,
                to: rule.forward_to,
                keyword: rule.keyword,
                account_id: acc.id
              })
            });
            const fd = await fr.json();
            if (fd.error) {
              console.warn('[Fwd] Error:', fd.error);
            } else {
              _fwdDone.add(key);
              _saveFwd();
              totalForwarded++;
              console.log(`[Fwd] ✓ Forwarded to ${rule.forward_to}`);
            }
          } catch(e) {
            console.warn('[Fwd] Request failed:', e);
          }
          break; // one rule per email
        }
      }
    }

    if (totalForwarded > 0) {
      toast(`↗ Forwarded ${totalForwarded} email${totalForwarded > 1 ? 's' : ''}`);
      loadEmails();
    }
  } catch(e) {
    console.warn('[Fwd] Engine error:', e);
  } finally {
    _fwdRunning = false;
  }
}

async function backgroundRefresh() {
  try {
    // Use simple non-recursive folder fetch for background check (faster)
    const fr = await fetch(`${API}?action=folders_simple`);
    const fd = await fr.json();
    if (!fd.value) return;

    const newFolders = fd.value || [];
    const inbox = newFolders.find(f => f.displayName?.toLowerCase() === 'inbox' || f.id === 'inbox');
    const newUnread = inbox?.unreadItemCount ?? 0;

    // 2. Check if current folder has new emails
    const search = document.getElementById('search')?.value.trim() || '';
    const composing = document.getElementById('compose-panel')?.classList.contains('show');
    let hasNewEmail = false;

    if (!composing && page === 0) {
      let url = `${API}?action=emails&folder=${encodeURIComponent(folder)}&top=1&skip=0`;
      if (search) url += `&search=${encodeURIComponent(search)}`;
      else if (activeFilter) url += `&filter=${encodeURIComponent(activeFilter)}`;
      const er = await fetch(url);
      const ed = await er.json();
      const latestId = ed.value?.[0]?.id || null;
      if (_lastFolderEmailId && latestId && latestId !== _lastFolderEmailId) {
        hasNewEmail = true;
      }
      if (latestId) _lastFolderEmailId = latestId;
    }

    // 3. Detect new mail by unread count jump
    const unreadJumped = _lastInboxUnread >= 0 && newUnread > _lastInboxUnread;
    _lastInboxUnread = newUnread;

    // 4. Update allFolders + badges silently
    allFolders = newFolders;
    updateBadges();

    // 5. If new mail arrived — refresh list and notify
    if (hasNewEmail || unreadJumped) {
      loadEmails(search);
      if (unreadJumped) {
        const diff = newUnread - (_lastInboxUnread - (newUnread - _lastInboxUnread + newUnread - newUnread));
        showNewMailToast(newUnread);
      }
    }

    // Pulse the indicator
    const ind = document.getElementById('refresh-indicator');
    if (ind) { ind.classList.add('pulse'); setTimeout(() => ind.classList.remove('pulse'), 600); }

  } catch(e) {}
}

// ── Browser-based forwarding rules engine ─────────────────
// Runs every background refresh — no cron needed
let _forwardingRunning = false;

// Persist forwarded IDs in localStorage so we never double-forward across sessions
// Key format: accountId:emailId
const _FWD_KEY = 'fwd_done_v2';
let _forwardedIds = new Set(JSON.parse(localStorage.getItem(_FWD_KEY) || '[]'));

function _saveFwdIds() {
  // Keep only last 1000 to prevent localStorage bloat
  const arr = [..._forwardedIds];
  if (arr.length > 1000) _forwardedIds = new Set(arr.slice(-800));
  localStorage.setItem(_FWD_KEY, JSON.stringify([..._forwardedIds]));
}

async function runForwardingRules() {
  if (_forwardingRunning) return;
  _forwardingRunning = true;
  try {
    // 1. Load all active rules
    const rr = await fetch(`protocolapi.php?action=get_rules_all`);
    const rd = await rr.json();
    const rules = (rd.rules || []).filter(r => r.active !== false);
    if (!rules.length) return;

    // 2. Load all accounts
    const ar = await fetch(`${API}?action=accounts`);
    const ad = await ar.json();
    const accounts = ad.accounts || [];
    if (!accounts.length) return;

    // 3. For each account fetch recent inbox emails (last 20)
    for (const acc of accounts) {
      const er = await fetch(`${API}?action=emails&folder=inbox&top=20&skip=0&account_id=${encodeURIComponent(acc.id)}`);
      const ed = await er.json();
      const emails = ed.value || [];
      if (!emails.length) continue;

      const applicable = rules.filter(r => r.account_id === acc.id || r.all_accounts !== false);
      if (!applicable.length) continue;

      for (const email of emails) {
        const key = acc.id + ':' + email.id;

        // Skip already forwarded
        if (_forwardedIds.has(key)) continue;

        const subject  = (email.subject  || '').toLowerCase();
        const preview  = (email.bodyPreview || '').toLowerCase();
        const fromAddr = (email.from?.emailAddress?.address || '').toLowerCase();

        for (const rule of applicable) {
          const keyword = (rule.keyword || '').toLowerCase().trim();
          const matchIn = rule.match_in || ['subject', 'body', 'from'];
          if (!keyword || !rule.forward_to) continue;

          let haystack = '';
          if (matchIn.includes('subject')) haystack += ' ' + subject;
          if (matchIn.includes('body'))    haystack += ' ' + preview;
          if (matchIn.includes('from'))    haystack += ' ' + fromAddr;

          if (haystack.includes(keyword)) {
            console.log(`[Rules] MATCH "${email.subject}" → forwarding to ${rule.forward_to}`);
            try {
              const fr = await fetch(`${API}?action=forward`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: csrfBody({
                  id:         email.id,
                  to:         rule.forward_to,
                  comment:    `Auto-forwarded: matched keyword "${rule.keyword}"`,
                  account_id: acc.id
                })
              });
              const fd = await fr.json();
              if (fd.error) {
                console.warn('[Rules] Forward error:', fd.error);
              } else {
                // Mark as forwarded so it never triggers again
                _forwardedIds.add(key);
                _saveFwdIds();
                toast(`↗ Forwarded: "${(email.subject||'').substring(0,45)}" → ${rule.forward_to}`);
                console.log(`[Rules] ✓ Done`);
              }
            } catch(e) {
              console.warn('[Rules] Request failed:', e);
            }
            break;
          }
        }
      }
    }
  } catch(e) {
    console.warn('[Rules] Engine error:', e);
  } finally {
    _forwardingRunning = false;
  }
}

function showNewMailToast(unread) {
  const t = document.getElementById('toast');
  if (!t) return;
  t.textContent = `📬 New mail — ${unread} unread`;
  t.style.display = 'block';
  t.style.background = 'var(--gold)';
  t.style.color = '#fff';
  clearTimeout(t._tm);
  t._tm = setTimeout(() => { t.style.display = 'none'; t.style.background = ''; t.style.color = ''; }, 4000);
}

async function loadUser() {
  try {
    const r = await fetch(`${API}?action=me`);
    const d = await r.json();
    document.getElementById('active-name').textContent = d.displayName || d.mail || '';
  } catch(e) {}
}

// ── Folders ───────────────────────────────────────────────
async function loadFolders() {
  try {
    const _faccId = currentAccountId || (typeof ACTIVE_ID !== 'undefined' ? ACTIVE_ID : '');
    const r = await fetch(`${API}?action=folders${_faccId ? '&account_id='+encodeURIComponent(_faccId) : ''}`);
    const d = await r.json();
    if (d.error) { console.warn('Folders error:', d.error); return; }
    allFolders = d.value || [];
    renderCustomFolders(); updateBadges(); populateFolderSelects();
  } catch(e) { console.warn('loadFolders failed:', e); }
}
// System folder names (lowercase, no spaces) — Graph uses display names not well-known IDs in recursive fetch
const SYS = {
  inbox:1, sentitems:1, sent:1, drafts:1, junkemail:1, junk:1,
  deleteditems:1, deletedItems:1, outbox:1, archivemessagesfolder:1,
  archive:1, conversationhistory:1, syncissues:1, rssfeed:1, rssfeeds:1,
  recoverableitemsdeletions:1, recoverableitemsroot:1
};
function isSysFolder(f) {
  const dn = f.displayName?.toLowerCase().replace(/\s/g,'') || '';
  return !!(SYS[dn]);
}

// Build a tree from flat folder list using parentFolderId
function buildFolderTree(folders) {
  const map = {}, roots = [];
  folders.forEach(f => { map[f.id] = { ...f, children: [] }; });
  folders.forEach(f => {
    const pid = f.parentFolderId;
    if (pid && map[pid]) {
      map[pid].children.push(map[f.id]);
    } else {
      roots.push(map[f.id]);
    }
  });
  return roots;
}

function renderFolderNode(node, depth = 0) {
  const indent = depth * 14;
  const hasChildren = node.children && node.children.length > 0;
  const isSys = isSysFolder(node);
  if (isSys) {
    // Still recurse sys folder children (e.g. Inbox subfolders)
    return hasChildren ? node.children.map(c => renderFolderNode(c, depth)).join('') : '';
  }
  const expandId = 'fx-' + node.id.replace(/[^a-z0-9]/gi,'');
  return `
    <div class="folder-node" style="padding-left:${indent}px">
      <div class="folder" data-folder="${esc(node.id)}" onclick="selectFolder(this)">
        ${hasChildren
          ? `<button class="folder-expand-btn" id="${expandId}" onclick="toggleSubfolders(event,'${expandId}','${esc(node.id)}')">▶</button>`
          : `<span style="display:inline-block;width:14px;flex-shrink:0"></span>`
        }
        <span class="fi">${hasChildren ? '📂' : '📁'}</span>
        <span class="fn">${esc(node.displayName)}</span>
        ${node.unreadItemCount > 0 ? `<span class="folder-badge">${node.unreadItemCount}</span>` : ''}
        <div class="folder-actions">
          <button class="folder-action-btn" onclick="renameFolder(event,'${esc(node.id)}','${esc(node.displayName)}')" title="Rename">✏</button>
          <button class="folder-action-btn" onclick="deleteFolder(event,'${esc(node.id)}','${esc(node.displayName)}')" title="Delete" style="color:var(--danger)">✕</button>
        </div>
      </div>
      ${hasChildren
        ? `<div class="subfolder-list" id="sub-${esc(node.id)}" style="display:none">
            ${node.children.map(c => renderFolderNode(c, depth + 1)).join('')}
           </div>`
        : ''}
    </div>`;
}

function toggleSubfolders(e, btnId, folderId) {
  e.stopPropagation();
  const sub = document.getElementById('sub-' + folderId);
  const btn = document.getElementById(btnId);
  if (!sub) return;
  const open = sub.style.display === 'none';
  sub.style.display = open ? '' : 'none';
  if (btn) btn.textContent = open ? '▼' : '▶';
}

function renderCustomFolders() {
  const el = document.getElementById('custom-folders-list');
  const custom = allFolders.filter(f => !isSysFolder(f));
  if (!custom.length) {
    const sysChildren = allFolders.filter(f => {
      const pid = f.parentFolderId;
      return pid && allFolders.find(p => p.id === pid && isSysFolder(p));
    });
    if (!sysChildren.length) {
      el.innerHTML = '<div style="padding:.2rem .9rem;font-family:\'DM Mono\',monospace;font-size:.6rem;color:var(--muted)">No custom folders</div>';
      return;
    }
  }
  const tree = buildFolderTree(custom);
  el.innerHTML = tree.map(node => renderFolderNode(node, 0)).join('') || '<div style="padding:.2rem .9rem;font-family:\'DM Mono\',monospace;font-size:.6rem;color:var(--muted)">No custom folders</div>';
}

function updateBadges() {
  const inbox = allFolders.find(f => f.id==='inbox' || f.displayName?.toLowerCase()==='inbox');
  if (inbox) {
    unreadCount = inbox.unreadItemCount || 0;
    const badge = document.getElementById('badge-inbox');
    if (badge) { if (unreadCount > 0) { badge.textContent = unreadCount; badge.style.display = ''; } else badge.style.display = 'none'; }
    updateTitle();
  }
}

// Flat list of all folders for selects/menus — with indent prefix for subfolders
function flattenForSelect(folders) {
  const result = [];
  function walk(list, depth) {
    list.forEach(f => {
      result.push({ ...f, _depth: depth });
      if (f.children?.length) walk(f.children, depth + 1);
    });
  }
  walk(buildFolderTree(folders), 0);
  return result;
}

function populateFolderSelects() {
  const flat = flattenForSelect(allFolders);
  const opts = flat.map(f => `<option value="${esc(f.id)}">${'　'.repeat(f._depth)}${esc(f.displayName)}</option>`).join('');
  const rpf  = document.getElementById('rpf-folder'); if (rpf) rpf.innerHTML = opts;
  const fltf = document.getElementById('flt-folder'); if (fltf) fltf.innerHTML = opts;
  const mp   = document.getElementById('move-panel-list');
  if (mp) mp.innerHTML = flat.map(f => `<div class="move-item" style="padding-left:${.75 + f._depth*.9}rem" onclick="doMove(currentEmailId,'${esc(f.id)}');closeMovePanel()">📁 ${esc(f.displayName)}</div>`).join('');
  const cf = document.getElementById('ctx-folders');
  if (cf) cf.innerHTML = flat.map(f => `<div class="ctx-item" style="padding-left:${.75 + f._depth*.9}rem" onclick="ctxMove('${esc(f.id)}')">📁 ${esc(f.displayName)}</div>`).join('');
}

// Show/hide folder row and Run button based on action selection
document.getElementById('flt-action')?.addEventListener('change', function() {
  document.getElementById('flt-folder-row').style.display = this.value === 'move' ? '' : 'none';
  document.getElementById('flt-run-btn').style.display    = this.value ? '' : 'none';
});

async function runFilterAction() {
  const action = document.getElementById('flt-action').value;
  if (!action) { toast('Select an action first'); return; }
  const folderId = document.getElementById('flt-folder')?.value;
  if (action === 'move' && !folderId) { toast('Select a destination folder'); return; }

  // Build filter and fetch ALL matching emails (up to 100)
  const filter = buildFilter();
  let url = `${API}?action=emails&folder=${encodeURIComponent(folder)}&top=100&skip=0`;
  if (activeFilter || filter) url += `&filter=${encodeURIComponent(filter || activeFilter)}`;

  const btn = document.getElementById('flt-run-btn');
  btn.textContent = '⏳ Working…'; btn.disabled = true;

  try {
    const r = await fetch(url);
    const d = await r.json();
    const emails = d.value || [];
    if (!emails.length) { toast('No emails match the filter'); return; }

    if (!confirm(`${action === 'markRead' ? 'Mark' : 'Move'} ${emails.length} email(s)?`)) return;

    let done = 0;
    for (const e of emails) {
      try {
        if (action === 'markRead') {
          await fetch(`${API}?action=mark_read`, {method:'POST', headers:{'Content-Type':'application/json'}, body:csrfBody({id:e.id, isRead:true})});
        } else if (action === 'move') {
          await fetch(`${API}?action=move_email`, {method:'POST', headers:{'Content-Type':'application/json'}, body:csrfBody({emailId:e.id, folderId})});
        }
        done++;
      } catch(e) {}
    }
    toast(`Done! ${done} email(s) ${action === 'markRead' ? 'marked as read' : 'moved'}`);
    loadEmails(); loadFolders();
  } catch(e) {
    toast('Error: ' + e.message);
  } finally {
    btn.textContent = '▶ Run'; btn.disabled = false;
  }
}

// ── Folder CRUD ───────────────────────────────────────────
function promptNewFolder() {
  modalMode = 'create';
  document.getElementById('modal-title').textContent = 'New Folder';
  document.getElementById('modal-label').textContent = 'Folder name';
  document.getElementById('modal-input').value = '';
  document.getElementById('modal-confirm').textContent = 'Create';
  document.getElementById('modal-body').innerHTML = '<div class="modal-label">Folder name</div><input class="modal-input" id="modal-input" type="text" placeholder="e.g. Projects"/>';
  document.getElementById('modal-overlay').classList.add('show');
  setTimeout(() => document.getElementById('modal-input')?.focus(), 80);
}
function renameFolder(e, id, name) {
  e.stopPropagation(); modalMode = 'rename'; modalFolderId = id;
  document.getElementById('modal-title').textContent = 'Rename Folder';
  document.getElementById('modal-body').innerHTML = '<div class="modal-label">New name</div><input class="modal-input" id="modal-input" type="text"/>';
  document.getElementById('modal-input').value = name;
  document.getElementById('modal-confirm').textContent = 'Rename';
  document.getElementById('modal-overlay').classList.add('show');
  setTimeout(() => { const i = document.getElementById('modal-input'); i.focus(); i.select(); }, 80);
}
async function deleteFolder(e, id, name) {
  e.stopPropagation();
  if (!confirm(`Delete folder "${name}"?`)) return;
  const r = await fetch(`${API}?action=delete_folder&id=${encodeURIComponent(id)}`);
  const d = await r.json();
  if (d.status === 'ok') { toast('Folder deleted'); loadFolders(); } else toast('Could not delete');
}
async function modalConfirm() {
  const val = document.getElementById('modal-input')?.value.trim();
  if (!val) { toast('Please enter a value'); return; }
  closeModal();
  if (modalMode === 'create') {
    const r = await fetch(`${API}?action=create_folder`,{method:'POST',headers:{'Content-Type':'application/json'},body:csrfBody({name:val})});
    const d = await r.json(); if (d.error) { toast('Error: '+d.error); return; }
    toast(`Folder "${val}" created`); loadFolders();
  } else if (modalMode === 'rename') {
    const r = await fetch(`${API}?action=rename_folder`,{method:'POST',headers:{'Content-Type':'application/json'},body:csrfBody({id:modalFolderId,name:val})});
    const d = await r.json(); if (d.error) { toast('Error: '+d.error); return; }
    toast('Folder renamed'); loadFolders();
  } else if (modalMode === 'event') {
    await submitNewEvent();
  }
}
function closeModal() { document.getElementById('modal-overlay').classList.remove('show'); modalMode = null; modalFolderId = null; }
document.getElementById('modal-overlay').addEventListener('click', e => { if (e.target === document.getElementById('modal-overlay')) closeModal(); });
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeCtx(); closeMovePanel(); closeModal(); }
  if (e.key === 'Enter' && document.getElementById('modal-overlay').classList.contains('show')) modalConfirm();
});

// ── Filters ───────────────────────────────────────────────
function toggleFilterBar() {
  const bar = document.getElementById('filter-bar');
  const btn = document.getElementById('filter-toggle-btn');
  const open = bar.classList.toggle('show');
  btn.classList.toggle('active-filter', open);
  if (open && document.getElementById('rules-panel').classList.contains('open')) toggleRulesPanel();
}
function buildFilter() {
  const parts = [];
  const from = document.getElementById('flt-from')?.value.trim();
  const date = document.getElementById('flt-date')?.value;
  const read = document.getElementById('flt-read')?.value;
  if (from) parts.push(`from/emailAddress/address eq '${from}'`);
  if (date) {
    const now = new Date(); let d;
    if (date==='today') { d=new Date(now); d.setHours(0,0,0,0); }
    else if (date==='week') { d=new Date(now); d.setDate(d.getDate()-7); }
    else if (date==='month') { d=new Date(now); d.setMonth(d.getMonth()-1); }
    if (d) parts.push(`receivedDateTime ge ${d.toISOString()}`);
  }
  if (read === 'unread') parts.push('isRead eq false');
  else if (read === 'read') parts.push('isRead eq true');
  return parts.join(' and ');
}
function applyFilters() { activeFilter = buildFilter(); page = 0; searchSkipTokens.length=0; searchSkipTokens[0]=''; loadEmails(); toast('Filters applied'); }
function clearFilters() {
  document.getElementById('flt-from').value = '';
  document.getElementById('flt-date').value = '';
  document.getElementById('flt-read').value = '';
  activeFilter = ''; page = 0; searchSkipTokens.length=0; searchSkipTokens[0]=''; loadEmails(); toast('Filters cleared');
  document.getElementById('filter-toggle-btn').classList.remove('active-filter');
}

// ── Rules (Outlook-style modal) ───────────────────────────
let allRules = [], editingRuleId = null;

function toggleRulesPanel() { openRulesModal(); }

function openRulesModal() {
  document.getElementById('rules-modal').classList.add('show');
  loadInboxRules();
}
function closeRulesModal() {
  document.getElementById('rules-modal').classList.remove('show');
  editingRuleId = null;
}
document.getElementById('rules-modal').addEventListener('click', e => {
  if (e.target === document.getElementById('rules-modal')) closeRulesModal();
});

async function loadInboxRules() {
  const inner = document.getElementById('rules-list-col-inner');
  inner.innerHTML = '<div style="padding:1rem;font-family:\'DM Mono\',monospace;font-size:.65rem;color:var(--muted)">Loading…</div>';
  try {
    const r = await fetch(`${API}?action=get_inbox_rules`);
    const d = await r.json();
    allRules = d.value || [];
    if (d._error) {
      inner.innerHTML = `<div style="padding:.85rem;color:var(--danger);font-size:.72rem;line-height:1.5;background:rgba(220,50,50,.07);border-radius:6px;margin:.5rem">
        <strong>Rules not available</strong><br>${esc(d._error)}<br><br>
        <span style="color:var(--muted)">Server-side rules require a Microsoft 365 business/Exchange account.</span>
      </div>`;
      return;
    }
    renderRulesList();
  } catch(e) {
    inner.innerHTML = '<div style="padding:1rem;color:var(--danger);font-size:.72rem">Could not load rules: ' + esc(e.message) + '</div>';
  }
}

function renderRulesList() {
  const inner  = document.getElementById('rules-list-col-inner');
  const empty  = document.getElementById('rules-list-empty');
  if (!allRules.length) {
    inner.innerHTML = ''; empty.style.display = '';
    showEditorEmpty(); return;
  }
  empty.style.display = 'none';
  inner.innerHTML = '';
  // Wrap in scrollable div
  const scroll = document.createElement('div');
  scroll.className = 'rules-list-col-scroll';
  allRules.forEach(rule => {
    const enabled = rule.isEnabled !== false;
    const conds   = describeConditions(rule.conditions);
    const acts    = describeActions(rule.actions);
    const item    = document.createElement('div');
    item.className = 'rule-list-item' + (editingRuleId === rule.id ? ' active' : '');
    item.dataset.id = rule.id;
    item.innerHTML = `
      <div class="rule-list-item-name">${esc(rule.displayName || 'Unnamed rule')}</div>
      <div class="rule-list-item-desc">${esc(conds)}</div>
      <div class="rule-list-item-desc">${esc(acts)}</div>
      <div class="rule-list-item-meta">
        <div class="rule-enabled-dot" style="background:${enabled?'#16a34a':'#aaa'}"></div>
        <span style="font-size:.62rem;color:var(--muted)">${enabled?'Enabled':'Disabled'}</span>
      </div>
      <div class="rule-list-item-actions">
        <button class="rule-item-btn" onclick="editRule('${esc(rule.id)}')">Edit</button>
        <button class="rule-item-btn" onclick="toggleRule('${esc(rule.id)}')">${enabled?'Disable':'Enable'}</button>
        <button class="rule-item-btn danger" onclick="deleteRule('${esc(rule.id)}')">Delete</button>
      </div>`;
    scroll.appendChild(item);
  });
  inner.appendChild(scroll);
}

function describeConditions(c) {
  if (!c) return 'No conditions';
  const parts = [];
  if (c.senderContains?.length)  parts.push('From: ' + c.senderContains.join(', '));
  if (c.subjectContains?.length) parts.push('Subject: ' + c.subjectContains.join(', '));
  if (c.bodyContains?.length)    parts.push('Body: ' + c.bodyContains.join(', '));
  if (c.hasAttachments)          parts.push('Has attachments');
  if (c.importance)              parts.push('Importance: ' + c.importance);
  return parts.join(' · ') || 'Any message';
}
function describeActions(a) {
  if (!a) return 'No actions';
  const parts = [];
  if (a.moveToFolder)         parts.push('Move to folder');
  if (a.markAsRead)           parts.push('Mark as read');
  if (a.markImportance)       parts.push('Mark importance: ' + a.markImportance);
  if (a.forwardTo?.length)    parts.push('Forward to: ' + a.forwardTo.map(f=>f.emailAddress?.address||'').join(', '));
  if (a.redirectTo?.length)   parts.push('Redirect to: ' + a.redirectTo.map(f=>f.emailAddress?.address||'').join(', '));
  if (a.delete)               parts.push('Delete');
  if (a.stopProcessingRules)  parts.push('Stop processing');
  return parts.join(' · ') || 'No actions';
}

function showEditorEmpty() {
  document.getElementById('rules-editor-empty').style.display = '';
  document.getElementById('rules-editor-form').style.display  = 'none';
  editingRuleId = null;
}

function openNewRuleForm() {
  editingRuleId = null;
  document.getElementById('re-name').value = '';
  document.getElementById('re-cond-type').value = 'from';
  document.getElementById('re-cond-val').value  = '';
  document.getElementById('re-cond-val').style.display = '';
  document.getElementById('re-cond-imp').style.display = 'none';
  document.getElementById('re-actions-list').innerHTML = '';
  document.getElementById('re-exceptions-list').innerHTML = '';
  document.getElementById('re-stop-processing').checked = false;
  document.getElementById('re-enabled').checked = true;
  addActionRow();
  document.getElementById('rules-editor-empty').style.display = 'none';
  document.getElementById('rules-editor-form').style.display  = '';
  document.getElementById('re-name').focus();
  // Deselect list items
  document.querySelectorAll('.rule-list-item').forEach(el => el.classList.remove('active'));
}

function editRule(id) {
  const rule = allRules.find(r => r.id === id);
  if (!rule) return;
  editingRuleId = id;

  document.getElementById('re-name').value = rule.displayName || '';

  // Conditions
  const c = rule.conditions || {};
  if (c.senderContains?.length) {
    document.getElementById('re-cond-type').value = 'from';
    document.getElementById('re-cond-val').value  = c.senderContains.join(', ');
    document.getElementById('re-cond-val').style.display = '';
    document.getElementById('re-cond-imp').style.display = 'none';
  } else if (c.subjectContains?.length) {
    document.getElementById('re-cond-type').value = 'subject';
    document.getElementById('re-cond-val').value  = c.subjectContains.join(', ');
    document.getElementById('re-cond-val').style.display = '';
    document.getElementById('re-cond-imp').style.display = 'none';
  } else if (c.bodyContains?.length) {
    document.getElementById('re-cond-type').value = 'body';
    document.getElementById('re-cond-val').value  = c.bodyContains.join(', ');
    document.getElementById('re-cond-val').style.display = '';
    document.getElementById('re-cond-imp').style.display = 'none';
  } else if (c.hasAttachments) {
    document.getElementById('re-cond-type').value = 'hasAttachments';
    document.getElementById('re-cond-val').style.display = 'none';
    document.getElementById('re-cond-imp').style.display = 'none';
  } else if (c.importance) {
    document.getElementById('re-cond-type').value = 'importance';
    document.getElementById('re-cond-imp').value  = c.importance;
    document.getElementById('re-cond-val').style.display = 'none';
    document.getElementById('re-cond-imp').style.display = '';
  }

  // Actions
  const actionList = document.getElementById('re-actions-list');
  actionList.innerHTML = '';
  const a = rule.actions || {};
  if (a.moveToFolder)       addActionRow('move',       a.moveToFolder);
  if (a.markAsRead)         addActionRow('markRead');
  if (a.markImportance)     addActionRow('markImportance', a.markImportance);
  if (a.forwardTo?.length)  addActionRow('forward',    a.forwardTo[0]?.emailAddress?.address||'');
  if (a.redirectTo?.length) addActionRow('redirect',   a.redirectTo[0]?.emailAddress?.address||'');
  if (a.delete)             addActionRow('delete');
  if (!actionList.children.length) addActionRow();

  // Exceptions
  document.getElementById('re-exceptions-list').innerHTML = '';
  const ex = rule.exceptions || {};
  if (ex.senderContains?.length)  addExceptionRow('from',    ex.senderContains.join(', '));
  if (ex.subjectContains?.length) addExceptionRow('subject', ex.subjectContains.join(', '));

  document.getElementById('re-stop-processing').checked = rule.actions?.stopProcessingRules || false;
  document.getElementById('re-enabled').checked = rule.isEnabled !== false;

  document.getElementById('rules-editor-empty').style.display = 'none';
  document.getElementById('rules-editor-form').style.display  = '';

  document.querySelectorAll('.rule-list-item').forEach(el => el.classList.toggle('active', el.dataset.id === id));
}

function cancelRuleEdit() { showEditorEmpty(); document.querySelectorAll('.rule-list-item').forEach(el=>el.classList.remove('active')); }

// ── Action rows ───────────────────────────────────────────
let actionRowCount = 0;
function addActionRow(type='move', value='') {
  const id  = 'ar-' + (++actionRowCount);
  const row = document.createElement('div');
  row.className = 're-action-row'; row.id = id;
  row.innerHTML = `
    <select class="re-select re-action-type" onchange="onActionTypeChange('${id}')">
      <option value="move"           ${type==='move'?'selected':''}>Move to folder</option>
      <option value="markRead"       ${type==='markRead'?'selected':''}>Mark as read</option>
      <option value="markImportance" ${type==='markImportance'?'selected':''}>Mark importance</option>
      <option value="forward"        ${type==='forward'?'selected':''}>Forward to</option>
      <option value="redirect"       ${type==='redirect'?'selected':''}>Redirect to</option>
      <option value="delete"         ${type==='delete'?'selected':''}>Delete message</option>
    </select>
    <select class="re-select re-action-folder" style="display:${type==='move'?'':'none'}">
      ${allFolders.map(f=>`<option value="${esc(f.id)}" ${value===f.id?'selected':''}>${esc(f.displayName)}</option>`).join('')}
    </select>
    <select class="re-select re-action-imp" style="display:${type==='markImportance'?'':'none'}">
      <option value="high"   ${value==='high'?'selected':''}>High</option>
      <option value="normal" ${value==='normal'?'selected':''}>Normal</option>
      <option value="low"    ${value==='low'?'selected':''}>Low</option>
    </select>
    <input class="re-input re-action-email" type="email" placeholder="email@example.com"
      style="display:${(type==='forward'||type==='redirect')?'':'none'}" value="${esc(value)}"/>
    <button class="re-action-remove" onclick="document.getElementById('${id}').remove()">✕</button>`;
  document.getElementById('re-actions-list').appendChild(row);
}
function onActionTypeChange(rowId) {
  const row  = document.getElementById(rowId);
  const type = row.querySelector('.re-action-type').value;
  row.querySelector('.re-action-folder').style.display = type==='move'?'':'none';
  row.querySelector('.re-action-imp').style.display    = type==='markImportance'?'':'none';
  row.querySelector('.re-action-email').style.display  = (type==='forward'||type==='redirect')?'':'none';
}

// ── Exception rows ────────────────────────────────────────
let exRowCount = 0;
function addExceptionRow(type='from', value='') {
  const id  = 'ex-' + (++exRowCount);
  const row = document.createElement('div');
  row.className = 're-action-row'; row.id = id;
  row.innerHTML = `
    <select class="re-select">
      <option value="from"    ${type==='from'?'selected':''}>Sender contains</option>
      <option value="subject" ${type==='subject'?'selected':''}>Subject contains</option>
    </select>
    <input class="re-input" placeholder="exception value" value="${esc(value)}"/>
    <button class="re-action-remove" onclick="document.getElementById('${id}').remove()">✕</button>`;
  document.getElementById('re-exceptions-list').appendChild(row);
}

// ── Condition type change ─────────────────────────────────
document.getElementById('re-cond-type')?.addEventListener('change', function() {
  const noVal = this.value === 'hasAttachments';
  const isImp = this.value === 'importance';
  document.getElementById('re-cond-val').style.display = (noVal||isImp) ? 'none' : '';
  document.getElementById('re-cond-imp').style.display = isImp ? '' : 'none';
});

// ── Save rule ─────────────────────────────────────────────
async function saveRule() {
  const name = document.getElementById('re-name').value.trim();
  if (!name) { toast('Enter a rule name'); return; }

  // Build conditions
  const condType = document.getElementById('re-cond-type').value;
  const condVal  = document.getElementById('re-cond-val').value.trim();
  const condImp  = document.getElementById('re-cond-imp').value;
  const keywords = condVal.split(',').map(s=>s.trim()).filter(Boolean);
  const conditions = {};
  if (condType === 'from'    && keywords.length) conditions.senderContains  = keywords;
  if (condType === 'subject' && keywords.length) conditions.subjectContains = keywords;
  if (condType === 'body'    && keywords.length) conditions.bodyContains    = keywords;
  if (condType === 'hasAttachments') conditions.hasAttachments = true;
  if (condType === 'importance')     conditions.importance = condImp;

  // Build actions
  const actions = {};
  document.querySelectorAll('#re-actions-list .re-action-row').forEach(row => {
    const type = row.querySelector('.re-action-type').value;
    if (type === 'move') {
      const fid = row.querySelector('.re-action-folder').value;
      if (fid) actions.moveToFolder = fid;
    } else if (type === 'markRead') {
      actions.markAsRead = true;
    } else if (type === 'markImportance') {
      actions.markImportance = row.querySelector('.re-action-imp').value;
    } else if (type === 'forward') {
      const addr = row.querySelector('.re-action-email').value.trim();
      if (addr) actions.forwardTo = [{emailAddress:{address:addr}}];
    } else if (type === 'redirect') {
      const addr = row.querySelector('.re-action-email').value.trim();
      if (addr) actions.redirectTo = [{emailAddress:{address:addr}}];
    } else if (type === 'delete') {
      actions.permanentDelete = true;
    }
  });
  if (!Object.keys(actions).length) { toast('Add at least one action'); return; }
  if (document.getElementById('re-stop-processing').checked) actions.stopProcessingRules = true;

  // Build exceptions
  const exceptions = {};
  document.querySelectorAll('#re-exceptions-list .re-action-row').forEach(row => {
    const type = row.querySelector('select').value;
    const val  = row.querySelector('input').value.trim();
    const kws  = val.split(',').map(s=>s.trim()).filter(Boolean);
    if (!kws.length) return;
    if (type === 'from')    exceptions.senderContains  = kws;
    if (type === 'subject') exceptions.subjectContains = kws;
  });

  const rule = {
    displayName: name,
    isEnabled: document.getElementById('re-enabled').checked,
    conditions, actions,
    ...(Object.keys(exceptions).length ? {exceptions} : {}),
  };
  // sequence required for new rules only
  if (!editingRuleId) rule.sequence = Math.floor(Math.random()*900)+100;

  const btn = document.getElementById('re-save-spinner');
  btn.style.display = 'inline-block';

  try {
    let r, d;
    if (editingRuleId) {
      r = await fetch(`${API}?action=update_inbox_rule&id=${encodeURIComponent(editingRuleId)}`, {method:'POST', headers:{'Content-Type':'application/json'}, body:csrfBody(rule)});
    } else {
      r = await fetch(`${API}?action=create_inbox_rule`, {method:'POST', headers:{'Content-Type':'application/json'}, body:csrfBody(rule)});
    }
    d = await r.json();
    if (d.error) { toast('Error: ' + (typeof d.error === 'string' ? d.error : JSON.stringify(d.error))); return; }
    toast(editingRuleId ? 'Rule updated ✓' : 'Rule created ✓');
    showEditorEmpty();
    loadInboxRules();
  } catch(e) { toast('Failed to save rule'); }
  finally { btn.style.display = 'none'; }
}

async function toggleRule(id) {
  const rule = allRules.find(r => r.id === id);
  if (!rule) return;
  const r = await fetch(`${API}?action=update_inbox_rule&id=${encodeURIComponent(id)}`, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: csrfBody({isEnabled: rule.isEnabled === false})
  });
  const d = await r.json();
  if (d.error) { toast('Error: '+d.error); return; }
  toast(rule.isEnabled === false ? 'Rule enabled' : 'Rule disabled');
  loadInboxRules();
}

async function deleteRule(id) {
  if (!confirm('Delete this rule?')) return;
  await fetch(`${API}?action=delete_inbox_rule&id=${encodeURIComponent(id)}`);
  if (editingRuleId === id) showEditorEmpty();
  toast('Rule deleted');
  loadInboxRules();
}

// ── Emails ────────────────────────────────────────────────
// skipToken pages for search results (Graph doesn't support $skip with $search)
const searchSkipTokens = ['']; // index = page number, value = skipToken for that page
let hlTerms = []; // active highlight terms from last search

// ── Highlight helper ─────────────────────────────────────────────
// Wraps matched terms in <mark class="hl"> inside a plain-text string.
// Safe: escapes the string first, then injects marks.
function highlightText(str, terms) {
  if (!terms || !terms.length || !str) return esc(str);
  let out = esc(str);
  terms.forEach(term => {
    if (!term) return;
    // Escape regex special chars in the term
    const re = new RegExp('(' + term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
    out = out.replace(re, '<mark class="hl">$1</mark>');
  });
  return out;
}

// Highlight inside an iframe's document (detail body)
function highlightInIframe(iframe, terms) {
  if (!terms || !terms.length) return;
  try {
    const doc = iframe.contentDocument || iframe.contentWindow?.document;
    if (!doc || !doc.body) return;
    highlightNode(doc.body, terms);
  } catch(e) { /* cross-origin or not ready */ }
}

function highlightNode(node, terms) {
  if (node.nodeType === 3) { // TEXT_NODE
    const val = node.nodeValue;
    if (!val.trim()) return;
    const re = new RegExp('(' + terms.map(t => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|') + ')', 'gi');
    if (!re.test(val)) return;
    const frag = document.createDocumentFragment();
    let last = 0;
    val.replace(re, (match, p1, offset) => {
      frag.appendChild(document.createTextNode(val.slice(last, offset)));
      const mark = document.createElement('mark');
      mark.className = 'hl';
      mark.style.cssText = 'background:#f5c842;color:#1a1200;border-radius:2px;padding:0 1px';
      mark.textContent = match;
      frag.appendChild(mark);
      last = offset + match.length;
    });
    frag.appendChild(document.createTextNode(val.slice(last)));
    node.parentNode.replaceChild(frag, node);
  } else if (node.nodeType === 1 && !['SCRIPT','STYLE','MARK'].includes(node.tagName)) {
    Array.from(node.childNodes).forEach(child => highlightNode(child, terms));
  }
}

async function loadEmails(search='') {
  const list = document.getElementById('email-list');
  if (!list) return;
  const prog = document.getElementById('progress');
  prog.style.display = 'block';
  if (search) {
    list.innerHTML = `<div style="padding:2rem;text-align:center;font-family:'Geist Mono',monospace;font-size:.72rem;color:var(--muted)">🔍 Searching all mail…</div>`;
  }
  try {
    const _accId = currentAccountId || (typeof ACTIVE_ID !== 'undefined' ? ACTIVE_ID : '');
    let url = `${API}?action=emails&folder=${encodeURIComponent(folder)}&top=${PER}${_accId ? '&account_id='+encodeURIComponent(_accId) : ''}`;
    if (search) {
      // Server fetches all pages and returns full result set
      url += `&search=${encodeURIComponent(search)}`;
    } else {
      url += `&skip=${page*PER}`;
      if (activeFilter) url += `&filter=${encodeURIComponent(activeFilter)}`;
    }
    const r = await fetch(url); const d = await r.json();
    prog.style.display = 'none';
    if (d.error) { list.innerHTML = `<div style="padding:2rem;color:var(--danger);font-size:.8rem">${esc(d.error)}</div>`; return; }
    const allEmails = d.value || [];
    // Store highlight terms from server (parsed from search operators)
    hlTerms = search ? (d._highlight_terms || []) : [];
    // For search results: do client-side paging on the full result set
    const emails = search
      ? allEmails.slice(page * PER, page * PER + PER)
      : allEmails;
    // Cache full search results for client-side paging
    if (search) window._searchResults = allEmails;
    const total = search ? allEmails.length : null;
    document.getElementById('list-count').textContent = total !== null
      ? `${total} result${total!==1?'s':''} — page ${page+1}`
      : (emails.length ? `${emails.length} shown` : '');
    document.getElementById('page-info').textContent  = `Page ${page+1}`;
    document.getElementById('prev-btn').disabled = page === 0;
    document.getElementById('next-btn').disabled = search
      ? (page * PER + PER >= allEmails.length)
      : (emails.length < PER);
    if (!emails.length) { list.innerHTML = `<div style="padding:2rem;text-align:center;font-family:'Geist Mono',monospace;font-size:.72rem;color:var(--muted)">No emails found</div>`; return; }
    const hl = hlTerms;
    list.innerHTML = emails.map(e => {
      const from = e.from?.emailAddress;
      const name = hl.length ? highlightText(from?.name || from?.address || 'Unknown', hl) : esc(from?.name || from?.address || 'Unknown');
      const tags = e.hasAttachments ? '<span class="row-tag">📎</span>' : '';
      return `<div class="email-row${!e.isRead?' unread':''}" data-id="${e.id}" data-acc="${esc(currentAccountId||ACTIVE_ID||'')}" onclick="openEmail('${e.id}',this)" oncontextmenu="showCtx(event,'${e.id}',this)">
        <div class="row-top"><div class="row-from">${name}</div><div class="row-time">${fmtDate(e.receivedDateTime)}</div></div>
        <div class="row-subject">${hl.length ? highlightText(e.subject||'(No subject)', hl) : esc(e.subject||'(No subject)')}</div>
        <div class="row-preview">${hl.length ? highlightText(e.bodyPreview||'', hl) : esc(e.bodyPreview||'')}</div>
        ${tags?`<div class="row-tags">${tags}</div>`:''}
      </div>`;
    }).join('');
  } catch(err) {
    document.getElementById('progress').style.display = 'none';
    list.innerHTML = `<div style="padding:2rem;color:var(--danger);font-size:.8rem">${esc(err.message)}</div>`;
  }
}

async function openEmail(id, el) {
  currentEmailId = id;
  // Read the account this email belongs to from the row's data attribute
  const emailAccId = el.getAttribute('data-acc') || currentAccountId || ACTIVE_ID || '';
  currentEmailAccId = emailAccId; // track for toolbar actions
  document.querySelectorAll('.email-row').forEach(r => r.classList.remove('active'));
  el.classList.add('active'); el.classList.remove('unread');
  const detail = document.getElementById('detail-pane');
  detail.classList.add('mobile-show');
  detail.innerHTML = `<div style="padding:2rem"><div class="sk" style="height:20px;width:62%;margin-bottom:1.1rem"></div><div class="sk" style="width:38%"></div><div style="height:1rem"></div><div class="sk"></div><div class="sk" style="width:78%"></div><div class="sk" style="width:58%"></div></div>`;
  try {
    const accParam = emailAccId ? `&account_id=${encodeURIComponent(emailAccId)}` : '';
    const r = await fetch(`${API}?action=email&id=${encodeURIComponent(id)}${accParam}`);
    const e = await r.json();
    if (e.error) { detail.innerHTML = `<div style="padding:2rem;color:var(--danger)">${esc(e.error)}</div>`; return; }

    // Fetch attachment list separately if email has attachments
    let attachments = [];
    if (e.hasAttachments) {
      try {
        const ar = await fetch(`${API}?action=attachments&id=${encodeURIComponent(id)}${accParam}`);
        const ad = await ar.json();
        attachments = ad.value || [];
      } catch(e) {}
    }
    const from  = e.from?.emailAddress;
    const name  = from?.name || from?.address || 'Unknown';
    const dated = new Date(e.receivedDateTime).toLocaleString('en-US',{weekday:'short',month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'});
    const toList  = (e.toRecipients||[]).map(r => esc(r.emailAddress?.name||r.emailAddress?.address||'')).join(', ');
    const ccList  = (e.ccRecipients||[]).map(r => esc(r.emailAddress?.name||r.emailAddress?.address||'')).join(', ');
    const bodyRaw  = e.body?.contentType==='html' ? e.body.content : `<pre style="white-space:pre-wrap;font-family:inherit">${esc(e.body?.content||'')}</pre>`;
    const body     = bodyRaw; // highlighting applied post-render into iframe
    const atts    = attachments.filter(a => !a.isInline);
    const attBar  = atts.length ? `
      <div class="attachments-bar show">
        <div class="att-bar-title">📎 ${atts.length} attachment${atts.length>1?'s':''}</div>
        <div class="att-chips">
          ${atts.map(a => {
            const canPreview = (a.contentType||'').startsWith('image/') || (a.contentType||'').includes('pdf');
            const dlUrl = `${API}?action=attachment_download&email_id=${encodeURIComponent(id)}&att_id=${encodeURIComponent(a.id)}&name=${encodeURIComponent(a.name||'file')}&mime=${encodeURIComponent(a.contentType||'application/octet-stream')}`;
            const _ext = (a.name||'').split('.').pop().toLowerCase();
            return `<div class="att-chip" data-ext="${_ext}">
              <span class="att-icon">${attIcon(a.contentType||'')}</span>
              <div class="att-info">
                <span class="att-name">${esc(a.name||'file')}</span>
                <span class="att-size">${fmtSize(a.size||0)}</span>
              </div>
              <div class="att-actions">
                ${canPreview ? `<button class="att-btn att-view-btn" onclick="previewAttachment('${encodeURIComponent(id)}','${encodeURIComponent(a.id)}','${esc(a.name||'file')}','${esc(a.contentType||'')}')">👁 View</button>` : ''}
                <a class="att-btn att-dl-btn" href="${dlUrl}" download="${esc(a.name||'file')}">↓ Save</a>
              </div>
            </div>`;
          }).join('')}
        </div>
      </div>` : '';
    detail.innerHTML = `
      <div class="detail-toolbar">
        <button class="dt-btn" onclick="openReply('reply')">↩ Reply</button>
        <button class="dt-btn" onclick="openReply('replyAll')">↩↩ All</button>
        <button class="dt-btn" onclick="openReply('forward')">↗ Fwd</button>
        <button class="dt-btn" onclick="showMovePanel(event,'${e.id}')">📁 Move</button>
        <button class="dt-btn" onclick="markEmail('${e.id}',false)">○ Unread</button>
        <button class="dt-btn danger" onclick="deleteEmail('${e.id}')">🗑</button>
        <button class="dt-btn ai-btn" onclick="summarizeEmail('${e.id}')">✨ Summarize</button>
        <button class="dt-btn" id="detail-back-btn" onclick="document.getElementById('detail-pane').classList.remove('mobile-show')" style="display:none;margin-left:auto;font-size:.85rem;padding:.35rem .65rem">&#8592; Back</button>
      </div>
      <div id="ai-summary-panel" style="display:none"></div>
      <div class="detail-scroll">
        <div class="detail-banner">
          <div class="detail-subject">${hlTerms.length ? highlightText(e.subject||'(No subject)', hlTerms) : esc(e.subject||'(No subject)')}</div>
          <div class="detail-from">
            <div class="d-avatar">${esc(name.charAt(0).toUpperCase())}</div>
            <div><div class="from-name">${hlTerms.length ? highlightText(name, hlTerms) : esc(name)}</div><div class="from-email">${esc(from?.address||'')}</div></div>
            <div class="detail-date">${dated}</div>
          </div>
          ${toList?`<div class="detail-to">To: <span>${toList}</span></div>`:''}
          ${ccList?`<div class="detail-to">CC: <span>${ccList}</span></div>`:''}
        </div>
        ${attBar}
        <div class="detail-body">${body}</div>
      </div>`;
    const backBtn = document.getElementById('detail-back-btn');
    if (backBtn) backBtn.style.display = '';
    // Highlight search terms in body iframe
    if (hlTerms.length) {
      const bodyEl = detail.querySelector('.detail-body');
      if (bodyEl) highlightNode(bodyEl, hlTerms);
    }
    // mark read silently
    fetch(`${API}?action=mark_read`,{method:'POST',headers:{'Content-Type':'application/json'},body:csrfBody({id,isRead:true,account_id:currentEmailAccId})});
    if (!e.isRead) { unreadCount = Math.max(0, unreadCount-1); updateTitle(); }
  } catch(err) {
    detail.innerHTML = `<div style="padding:2rem;color:var(--danger)">Failed to load email.</div>`;
  }
}

// ── AI Summary ────────────────────────────────────────────
async function summarizeEmail(id) {
  const panel = document.getElementById('ai-summary-panel');
  if (!panel) return;

  // Toggle off if already showing
  if (panel.style.display !== 'none' && panel.dataset.emailId === id) {
    panel.style.display = 'none';
    panel.dataset.emailId = '';
    return;
  }

  panel.dataset.emailId = id;
  panel.style.display = 'block';
  panel.innerHTML = `
    <div class="ai-summary-box loading">
      <div class="ai-summary-header">
        <span class="ai-label">✨ AI Summary</span>
        <div class="ai-spinner"></div>
      </div>
      <div class="ai-summary-text">Analysing email…</div>
    </div>`;

  try {
    const r = await fetch('summarize.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: csrfBody({email_id: id})
    });
    const d = await r.json();
    if (d.error) {
      panel.innerHTML = `
        <div class="ai-summary-box error">
          <div class="ai-summary-header">
            <span class="ai-label">✨ AI Summary</span>
            <button class="ai-close" onclick="document.getElementById('ai-summary-panel').style.display='none'">✕</button>
          </div>
          <div class="ai-summary-text" style="color:var(--danger)">${esc(d.error)}</div>
        </div>`;
      return;
    }
    panel.innerHTML = `
      <div class="ai-summary-box">
        <div class="ai-summary-header">
          <span class="ai-label">✨ AI Summary</span>
          <button class="ai-close" onclick="document.getElementById('ai-summary-panel').style.display='none'">✕</button>
        </div>
        <div class="ai-summary-text">${esc(d.summary)}</div>
        ${d.action_items && d.action_items.length ? `
          <div class="ai-actions-title">Action Items</div>
          <ul class="ai-action-list">${d.action_items.map(a => `<li>${esc(a)}</li>`).join('')}</ul>
        ` : ''}
        ${d.sentiment ? `<div class="ai-sentiment">Tone: <span class="ai-sentiment-val">${esc(d.sentiment)}</span></div>` : ''}
      </div>`;
  } catch(err) {
    panel.innerHTML = `
      <div class="ai-summary-box error">
        <div class="ai-summary-header">
          <span class="ai-label">✨ AI Summary</span>
          <button class="ai-close" onclick="document.getElementById('ai-summary-panel').style.display='none'">✕</button>
        </div>
        <div class="ai-summary-text" style="color:var(--danger)">Failed to connect to AI service: ${esc(err.message || String(err))}</div>
      </div>`;
  }
}

// ── Move ──────────────────────────────────────────────────
function showMovePanel(event, emailId) {
  event.stopPropagation(); currentEmailId = emailId;
  if (!allFolders.length) loadFolders();
  populateFolderSelects();
  const panel = document.getElementById('move-panel');
  panel.style.left = event.pageX+'px'; panel.style.top = event.pageY+'px';
  panel.classList.add('show');
}
function closeMovePanel() { document.getElementById('move-panel').classList.remove('show'); }
async function doMove(emailId, folderId) {
  const r = await fetch(`${API}?action=move_email`,{method:'POST',headers:{'Content-Type':'application/json'},body:csrfBody({emailId,folderId,account_id:currentEmailAccId||currentAccountId})});
  const d = await r.json(); if (d.error){toast('Error: '+d.error);return;}
  toast('Email moved');
  const el = document.querySelector(`[data-id="${emailId}"]`); if (el) el.remove();
  document.getElementById('detail-pane').innerHTML = `<div class="detail-empty"><div class="detail-empty-icon">✉</div><div class="detail-empty-text">Select an email to read</div></div>`;
}
async function markEmail(id, isRead) {
  await fetch(`${API}?action=mark_read`,{method:'POST',headers:{'Content-Type':'application/json'},body:csrfBody({id,isRead,account_id:currentEmailAccId})});
  const el = document.querySelector(`[data-id="${id}"]`);
  if (el) { if(isRead) el.classList.remove('unread'); else el.classList.add('unread'); }
  toast(isRead?'Marked as read':'Marked as unread');
}
async function deleteEmail(id) {
  if (!confirm('Delete this email?')) return;
  await fetch(`${API}?action=delete_email&id=${encodeURIComponent(id)}&account_id=${encodeURIComponent(currentEmailAccId||currentAccountId||'')}`);
  const el = document.querySelector(`[data-id="${id}"]`); if (el) el.remove();
  document.getElementById('detail-pane').innerHTML = `<div class="detail-empty"><div class="detail-empty-icon">✉</div><div class="detail-empty-text">Select an email to read</div></div>`;
  toast('Email deleted');
}

// ── Context menu ──────────────────────────────────────────
function showCtx(e, id, el) {
  e.preventDefault(); ctxEmailId = id; populateFolderSelects();
  const menu = document.getElementById('ctx-menu');
  menu.style.left = e.pageX+'px'; menu.style.top = e.pageY+'px';
  menu.classList.add('show');
}
function ctxReply()   { openReplyById(ctxEmailId,'reply');   closeCtx(); }
function ctxReplyAll(){ openReplyById(ctxEmailId,'replyAll');closeCtx(); }
function ctxForward() { openReplyById(ctxEmailId,'forward'); closeCtx(); }
function ctxMarkRead()  { markEmail(ctxEmailId,true);  closeCtx(); }
function ctxMarkUnread(){ markEmail(ctxEmailId,false); closeCtx(); }
function ctxDelete()    { deleteEmail(ctxEmailId);     closeCtx(); }
async function ctxMove(folderId){ await doMove(ctxEmailId,folderId); closeCtx(); }
function closeCtx(){ document.getElementById('ctx-menu').classList.remove('show'); ctxEmailId=null; }
document.addEventListener('click', () => { closeCtx(); closeMovePanel(); });

// ── Folder select ─────────────────────────────────────────
function selectFolder(el) {
  document.querySelectorAll('.folder').forEach(f => f.classList.remove('active'));
  el.classList.add('active');
  folder     = el.dataset.folder;
  folderName = el.querySelector('.fn')?.textContent || el.textContent.trim().replace(/\d+/g,'').trim();
  page = 0;
  const lt = document.getElementById('list-title'); if (lt) lt.textContent = folderName;
  document.getElementById('detail-pane').innerHTML = `<div class="detail-empty"><div class="detail-empty-icon">✉</div><div class="detail-empty-text">Select an email to read</div></div>`;
  loadEmails();
}
function prevPage(){
  if(page>0){
    page--;
    const search = document.getElementById('search')?.value.trim()||'';
    if (search && window._searchResults) renderSearchPage(search);
    else loadEmails();
  }
}
function nextPage(){
  page++;
  const search = document.getElementById('search')?.value.trim()||'';
  if (search && window._searchResults) renderSearchPage(search);
  else loadEmails();
}

function renderSearchPage(search) {
  const list = document.getElementById('email-list');
  const allEmails = window._searchResults || [];
  const emails = allEmails.slice(page * PER, page * PER + PER);
  const total  = allEmails.length;
  document.getElementById('list-count').textContent = `${total} result${total!==1?'s':''} — page ${page+1}`;
  document.getElementById('page-info').textContent  = `Page ${page+1}`;
  document.getElementById('prev-btn').disabled = page === 0;
  document.getElementById('next-btn').disabled = (page * PER + PER) >= total;
  if (!emails.length) { list.innerHTML = `<div style="padding:2rem;text-align:center;font-family:'Geist Mono',monospace;font-size:.72rem;color:var(--muted)">No more results</div>`; return; }
  const hl = hlTerms;
  list.innerHTML = emails.map(e => {
    const from = e.from?.emailAddress;
    const name = hl.length ? highlightText(from?.name || from?.address || 'Unknown', hl) : esc(from?.name || from?.address || 'Unknown');
    const tags = e.hasAttachments ? '<span class="row-tag">📎</span>' : '';
    return `<div class="email-row${!e.isRead?' unread':''}" data-id="${e.id}" data-acc="${esc(currentAccountId||ACTIVE_ID||'')}" onclick="openEmail('${e.id}',this)" oncontextmenu="showCtx(event,'${e.id}',this)">
      <div class="row-top"><div class="row-from">${name}</div><div class="row-time">${fmtDate(e.receivedDateTime)}</div></div>
      <div class="row-subject">${hl.length ? highlightText(e.subject||'(No subject)', hl) : esc(e.subject||'(No subject)')}</div>
      <div class="row-preview">${hl.length ? highlightText(e.bodyPreview||'', hl) : esc(e.bodyPreview||'')}</div>
      ${tags?`<div class="row-tags">${tags}</div>`:''}
    </div>`;
  }).join('');
}
const searchEl = document.getElementById('search');
if (searchEl) searchEl.addEventListener('input', e => { clearTimeout(searchTm); searchTm = setTimeout(()=>{ page=0; window._searchResults=null; loadEmails(e.target.value.trim()); },450); });

// ── Search help popover ───────────────────────────────────
function toggleSearchHelp(e) {
  e.stopPropagation();
  const pop = document.getElementById('search-help-popover');
  if (!pop) return;
  pop.style.display = pop.style.display === 'none' ? 'block' : 'none';
}
// Click outside to close
document.addEventListener('click', e => {
  const pop = document.getElementById('search-help-popover');
  const btn = document.getElementById('search-help-btn');
  if (pop && pop.style.display !== 'none' && !pop.contains(e.target) && e.target !== btn) {
    pop.style.display = 'none';
  }
});
// Click a code snippet to insert into search
document.addEventListener('click', e => {
  if (e.target.tagName === 'CODE' && e.target.closest('.search-help-popover')) {
    const val = e.target.textContent.trim();
    const inp = document.getElementById('search');
    if (inp) {
      inp.value = val;
      inp.focus();
      page = 0; searchSkipTokens.length = 0; searchSkipTokens[0] = '';
      loadEmails(val);
    }
    document.getElementById('search-help-popover').style.display = 'none';
  }
});

// ── Compose ───────────────────────────────────────────────
let composeExpanded = false;

function openCompose() {
  composeMode = 'new'; composeReplyId = null;
  document.getElementById('compose-title').textContent = 'New Message';
  document.getElementById('compose-to').value      = '';
  document.getElementById('compose-subject').value = '';
  document.getElementById('compose-editor').innerHTML = '';
  document.getElementById('compose-cc').value      = '';
  document.getElementById('compose-bcc').value     = '';
  document.getElementById('compose-cc-row').style.display  = 'none';
  document.getElementById('compose-bcc-row').style.display = 'none';
  showCompose();
}
function openReply(mode){ openReplyById(currentEmailId, mode); }
async function openReplyById(emailId, mode) {
  if (!emailId) return;
  composeMode = mode; composeReplyId = emailId;
  const r = await fetch(`${API}?action=email&id=${encodeURIComponent(emailId)}`);
  const e = await r.json();
  if (e.error) { toast('Could not load email'); return; }
  const fromAddr = e.from?.emailAddress?.address || '';
  const fromName = e.from?.emailAddress?.name || fromAddr;
  const subj     = e.subject || '';
  const dated    = new Date(e.receivedDateTime).toLocaleString();
  const origBody = e.body?.contentType==='html' ? e.body.content : esc(e.body?.content||'');
  const quote    = `<br><br><div style="border-left:3px solid var(--gold);padding-left:12px;color:var(--muted);font-size:.85em"><div style="margin-bottom:4px;font-size:.8em">On ${dated}, ${esc(fromName)} &lt;${esc(fromAddr)}&gt; wrote:</div>${origBody}</div>`;
  if (mode === 'forward') {
    document.getElementById('compose-title').textContent = 'Forward';
    document.getElementById('compose-to').value      = '';
    document.getElementById('compose-subject').value = 'Fwd: '+subj;
  } else if (mode === 'replyAll') {
    document.getElementById('compose-title').textContent = 'Reply All';
    const allTo = [fromAddr,...(e.toRecipients||[]).map(r=>r.emailAddress?.address||'').filter(Boolean)].join(', ');
    document.getElementById('compose-to').value      = allTo;
    document.getElementById('compose-subject').value = subj.startsWith('Re:')?subj:'Re: '+subj;
  } else {
    document.getElementById('compose-title').textContent = 'Reply';
    document.getElementById('compose-to').value      = fromAddr;
    document.getElementById('compose-subject').value = subj.startsWith('Re:')?subj:'Re: '+subj;
  }
  document.getElementById('compose-editor').innerHTML = quote;
  document.getElementById('compose-cc-row').style.display  = 'none';
  document.getElementById('compose-bcc-row').style.display = 'none';
  showCompose();
  const ed = document.getElementById('compose-editor');
  ed.focus();
  try { const range=document.createRange();range.setStart(ed,0);range.collapse(true);const sel=window.getSelection();sel.removeAllRanges();sel.addRange(range); } catch(e){}
}
function showCompose() {
  document.getElementById('compose-panel').classList.add('show');
  composeMin = false; composeExpanded = false;
  document.getElementById('compose-body-wrap').style.display = '';
  document.querySelector('.compose-toolbar').style.display   = '';
  document.getElementById('compose-panel').classList.remove('compose-expanded');
  document.getElementById('compose-expanded-overlay').style.display = 'none';
  // Reset source mode
  if (_sourceMode) toggleSource();
}
function closeCompose() {
  document.getElementById('compose-panel').classList.remove('show','compose-expanded');
  document.getElementById('compose-expanded-overlay').style.display = 'none';
  composeExpanded = false;
}
function minimizeCompose() {
  if (composeExpanded) collapseCompose();
  composeMin = !composeMin;
  document.getElementById('compose-body-wrap').style.display = composeMin ? 'none' : '';
  document.querySelector('.compose-toolbar').style.display   = composeMin ? 'none' : '';
}
function expandCompose() {
  composeExpanded = true; composeMin = false;
  document.getElementById('compose-panel').classList.add('compose-expanded');
  document.getElementById('compose-expanded-overlay').style.display = 'block';
  document.getElementById('compose-body-wrap').style.display = '';
  document.querySelector('.compose-toolbar').style.display   = '';
  document.getElementById('compose-editor').focus();
}
function collapseCompose() {
  composeExpanded = false;
  document.getElementById('compose-panel').classList.remove('compose-expanded');
  document.getElementById('compose-expanded-overlay').style.display = 'none';
}
function toggleCC() {
  const r = document.getElementById('compose-cc-row');
  const show = r.style.display === 'none';
  r.style.display = show ? '' : 'none';
  document.getElementById('cc-toggle-btn').classList.toggle('active', show);
  if (show) document.getElementById('compose-cc').focus();
}
function toggleBCC() {
  const r = document.getElementById('compose-bcc-row');
  const show = r.style.display === 'none';
  r.style.display = show ? '' : 'none';
  document.getElementById('bcc-toggle-btn').classList.toggle('active', show);
  if (show) document.getElementById('compose-bcc').focus();
}
function fmt(cmd) {
  document.getElementById('compose-editor').focus();
  document.execCommand(cmd, false, null);
  updateFmtState();
}
function fmtList() { fmt('insertUnorderedList'); }

function fmtFont(font) {
  if (!font || font === 'inherit') return;
  document.getElementById('compose-editor').focus();
  document.execCommand('fontName', false, font);
  document.getElementById('fmt-font').value = 'inherit';
}
function fmtSize(size) {
  if (!size) return;
  document.getElementById('compose-editor').focus();
  document.execCommand('fontSize', false, size);
  document.getElementById('fmt-size').value = '';
}
function fmtColor(color) {
  document.getElementById('fmt-color-bar').style.background = color;
  document.getElementById('compose-editor').focus();
  document.execCommand('foreColor', false, color);
}
function fmtBg(color) {
  document.getElementById('fmt-bg-bar').style.background = color;
  document.getElementById('compose-editor').focus();
  document.execCommand('hiliteColor', false, color);
}
function fmtLink() {
  const url = prompt('Enter URL:', 'https://');
  if (!url) return;
  document.getElementById('compose-editor').focus();
  const sel = window.getSelection();
  if (sel && sel.toString()) {
    document.execCommand('createLink', false, url);
  } else {
    const text = prompt('Link text:', url) || url;
    document.execCommand('insertHTML', false, `<a href="${url}" target="_blank">${text}</a>`);
  }
}
function fmtImage() {
  const url = prompt('Image URL (or paste a web URL):');
  if (!url) return;
  document.getElementById('compose-editor').focus();
  document.execCommand('insertHTML', false, `<img src="${url}" style="max-width:100%;height:auto" />`);
}
let _sourceMode = false;
function toggleSource() {
  const editor = document.getElementById('compose-editor');
  const source = document.getElementById('compose-source');
  const btn    = document.getElementById('fmt-source-btn');
  _sourceMode  = !_sourceMode;
  if (_sourceMode) {
    source.value = editor.innerHTML;
    editor.style.display = 'none';
    source.style.display = '';
    source.focus();
    btn.classList.add('active');
  } else {
    editor.innerHTML = source.value;
    source.style.display = 'none';
    editor.style.display = '';
    editor.focus();
    btn.classList.remove('active');
  }
}
function updateFmtState() {
  ['bold','italic','underline','strikeThrough'].forEach(cmd => {
    const id = {bold:'fmtb-bold',italic:'fmtb-italic',underline:'fmtb-underline',strikeThrough:'fmtb-strike'}[cmd];
    const el = document.getElementById(id);
    if (el) el.classList.toggle('active', document.queryCommandState(cmd));
  });
}
// Update toolbar state on selection change
document.addEventListener('selectionchange', () => {
  if (document.activeElement === document.getElementById('compose-editor')) updateFmtState();
});

async function sendCompose() {
  const to      = document.getElementById('compose-to').value.trim();
  const subject = document.getElementById('compose-subject').value.trim();
  const cc      = document.getElementById('compose-cc').value.trim();
  const bcc     = document.getElementById('compose-bcc').value.trim();
  const body    = _sourceMode
    ? document.getElementById('compose-source').value.trim()
    : document.getElementById('compose-editor').innerHTML.trim();
  if (!to) { toast('Enter a recipient'); return; }
  const btn = document.getElementById('send-btn');
  const sp  = document.getElementById('send-spinner');
  btn.disabled = true; sp.style.display = 'inline-block';
  try {
    let r, d;
    if (composeMode==='reply'||composeMode==='replyAll') {
      r = await fetch(`${API}?action=reply`,{method:'POST',headers:{'Content-Type':'application/json'},body:csrfBody({id:composeReplyId,body,replyAll:composeMode==='replyAll',account_id:currentEmailAccId||currentAccountId})});
    } else if (composeMode==='forward') {
      r = await fetch(`${API}?action=forward`,{method:'POST',headers:{'Content-Type':'application/json'},body:csrfBody({id:composeReplyId,to,comment:body,account_id:currentEmailAccId||currentAccountId})});
    } else {
      if (!subject) { toast('Enter a subject'); btn.disabled=false; sp.style.display='none'; return; }
      r = await fetch(`${API}?action=send`,{method:'POST',headers:{'Content-Type':'application/json'},body:csrfBody({to,subject,body,cc,bcc})});
    }
    d = await r.json();
    if (d.error) { toast('Error: '+d.error); return; }
    toast('Message sent! ✓'); closeCompose();
  } catch(err) { toast('Failed to send'); }
  finally { btn.disabled=false; sp.style.display='none'; }
}

// ── Calendar ──────────────────────────────────────────────
let calShowing = false;
function toggleCalendar() {
  calShowing = !calShowing;
  document.getElementById('calendar-view').classList.toggle('show', calShowing);
  document.getElementById('mail-view').style.display = calShowing ? 'none' : '';
  const btn = document.getElementById('cal-toggle-btn');
  if (btn) btn.classList.toggle('active', calShowing);
  if (calShowing) loadCalendar();
}
function setCalView(v) {
  calView = v;
  document.getElementById('cal-month-btn').classList.toggle('active', v==='month');
  document.getElementById('cal-week-btn').classList.toggle('active', v==='week');
  renderCalendar();
}
function calPrev() { if(calView==='month') calDate.setMonth(calDate.getMonth()-1); else calDate.setDate(calDate.getDate()-7); loadCalendar(); }
function calNext() { if(calView==='month') calDate.setMonth(calDate.getMonth()+1); else calDate.setDate(calDate.getDate()+7); loadCalendar(); }
async function loadCalendar() {
  let start, end;
  if (calView==='month') {
    start = new Date(calDate.getFullYear(), calDate.getMonth(), 1);
    end   = new Date(calDate.getFullYear(), calDate.getMonth()+1, 1);
  } else {
    const dow = calDate.getDay();
    start = new Date(calDate); start.setDate(calDate.getDate()-dow);
    end   = new Date(start);   end.setDate(start.getDate()+7);
  }
  try {
    const r = await fetch(`${API}?action=calendar&start=${encodeURIComponent(start.toISOString())}&end=${encodeURIComponent(end.toISOString())}`);
    const d = await r.json();
    if (d.error) { document.getElementById('cal-body').innerHTML=`<div class="cal-empty">${esc(d.error)}</div>`; return; }
    calEvents = d.value||[]; renderCalendar();
  } catch(e) { document.getElementById('cal-body').innerHTML=`<div class="cal-empty">Could not load calendar.</div>`; }
}
function renderCalendar() { calView==='month' ? renderMonth() : renderWeek(); }
function renderMonth() {
  const y=calDate.getFullYear(), m=calDate.getMonth();
  document.getElementById('cal-title').textContent = new Date(y,m,1).toLocaleDateString('en-US',{month:'long',year:'numeric'});
  const first=new Date(y,m,1).getDay(), days=new Date(y,m+1,0).getDate();
  const today=new Date(); today.setHours(0,0,0,0);
  let html = `<div class="cal-month-grid">`;
  ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(d => html+=`<div class="cal-day-label">${d}</div>`);
  for (let i=0;i<first;i++) html+=`<div class="cal-day other-month"><div class="cal-day-num"></div></div>`;
  for (let d=1;d<=days;d++) {
    const day=new Date(y,m,d); day.setHours(0,0,0,0);
    const isToday=day.getTime()===today.getTime();
    const evts=calEvents.filter(e=>{const ed=new Date(e.start?.dateTime||e.start?.date);ed.setHours(0,0,0,0);return ed.getTime()===day.getTime();});
    html+=`<div class="cal-day${isToday?' today':''}"><div class="cal-day-num">${d}</div>`;
    evts.slice(0,3).forEach(e=>{
      const cls=e.isAllDay?'allday':(e.showAs==='busy'?'busy':'');
      html+=`<div class="cal-event-pill ${cls}" title="${esc(e.subject||'')}" onclick="deleteEventPrompt('${esc(e.id)}','${esc(e.subject||'Event')}')">${esc(e.subject||'(No title)')}</div>`;
    });
    if(evts.length>3) html+=`<div style="font-size:.6rem;color:var(--muted)">+${evts.length-3} more</div>`;
    html+=`</div>`;
  }
  html+=`</div>`;
  document.getElementById('cal-body').innerHTML = html;
}
function renderWeek() {
  const dow=calDate.getDay();
  const ws=new Date(calDate); ws.setDate(calDate.getDate()-dow);
  const days=[]; for(let i=0;i<7;i++){const d=new Date(ws);d.setDate(ws.getDate()+i);days.push(d);}
  const fmt=d=>d.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric'});
  document.getElementById('cal-title').textContent=`${fmt(days[0])} – ${fmt(days[6])}`;
  const today=new Date(); today.setHours(0,0,0,0);
  let html='';
  days.forEach(day=>{
    const d=new Date(day); d.setHours(0,0,0,0);
    const evts=calEvents.filter(e=>{const ed=new Date(e.start?.dateTime||e.start?.date);ed.setHours(0,0,0,0);return ed.getTime()===d.getTime();});
    const isToday=d.getTime()===today.getTime();
    html+=`<div class="cal-week-row"><div class="cal-week-time" style="${isToday?'color:var(--gold);font-weight:700':''}">${day.toLocaleDateString('en-US',{weekday:'short'})}<br><span style="font-size:.75rem">${day.getDate()}</span></div><div class="cal-week-evts">`;
    if(!evts.length) html+=`<div style="font-size:.7rem;color:var(--muted);padding:.28rem 0">No events</div>`;
    evts.forEach(e=>{
      const st=e.isAllDay?'All day':new Date(e.start?.dateTime).toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});
      const et=e.isAllDay?'':' – '+new Date(e.end?.dateTime).toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});
      html+=`<div class="cal-event-card"><button class="cal-event-del" onclick="deleteEventPrompt('${esc(e.id)}','${esc(e.subject||'Event')}')">🗑</button><div class="cal-event-title">${esc(e.subject||'(No title)')}</div><div class="cal-event-time">${st}${et}</div>${e.location?.displayName?`<div class="cal-event-loc">📍 ${esc(e.location.displayName)}</div>`:''}</div>`;
    });
    html+=`</div></div>`;
  });
  document.getElementById('cal-body').innerHTML = html || '<div class="cal-empty">No events this week</div>';
}
function openNewEventModal() {
  const now=new Date(), end=new Date(now.getTime()+3600000);
  const pad=n=>String(n).padStart(2,'0');
  const toLocal=d=>`${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  document.getElementById('modal-title').textContent='New Event';
  document.getElementById('modal-body').innerHTML=`
    <div class="event-form-row"><label>Title</label><input class="event-form-input" id="modal-input" placeholder="Event title"/></div>
    <div class="event-form-row"><label>Start</label><input class="event-form-input" id="evt-start" type="datetime-local" value="${toLocal(now)}"/></div>
    <div class="event-form-row"><label>End</label><input class="event-form-input" id="evt-end" type="datetime-local" value="${toLocal(end)}"/></div>
    <div class="event-form-row"><label>Location (optional)</label><input class="event-form-input" id="evt-loc" placeholder="Room, URL…"/></div>`;
  document.getElementById('modal-confirm').textContent = 'Create Event';
  modalMode = 'event';
  document.getElementById('modal-overlay').classList.add('show');
  setTimeout(()=>document.getElementById('modal-input')?.focus(),80);
}
async function submitNewEvent() {
  const title=document.getElementById('modal-input')?.value.trim();
  const start=document.getElementById('evt-start')?.value;
  const end  =document.getElementById('evt-end')?.value;
  const loc  =document.getElementById('evt-loc')?.value.trim();
  if (!title||!start||!end){toast('Title, start and end required');return;}
  const ev={subject:title,start:{dateTime:new Date(start).toISOString(),timeZone:'UTC'},end:{dateTime:new Date(end).toISOString(),timeZone:'UTC'}};
  if (loc) ev.location={displayName:loc};
  const r=await fetch(`${API}?action=create_event`,{method:'POST',headers:{'Content-Type':'application/json'},body:csrfBody(ev)});
  const d=await r.json(); if(d.error){toast('Error: '+d.error);return;}
  toast('Event created!'); loadCalendar();
}
async function deleteEventPrompt(id,title){
  if(!confirm(`Delete "${title}"?`))return;
  const r=await fetch(`${API}?action=delete_event&id=${encodeURIComponent(id)}`);
  const d=await r.json(); if(d.status==='ok'){toast('Event deleted');loadCalendar();}else toast('Could not delete');
}

// ── Pane resizers ─────────────────────────────────────────────────
(function() {
  function initResizer(resizer) {
    const paneClass = resizer.dataset.pane;
    const storageKey = resizer.dataset.key;
    const minW = parseInt(resizer.dataset.min) || 120;
    const maxW = parseInt(resizer.dataset.max) || 600;
    const pane = resizer.closest('.' + paneClass);
    if (!pane) return;

    // Restore saved width
    const saved = localStorage.getItem(storageKey);
    if (saved) pane.style.width = saved + 'px';

    let startX, startW;
    resizer.addEventListener('mousedown', e => {
      e.preventDefault();
      startX = e.clientX;
      startW = pane.offsetWidth;
      resizer.classList.add('dragging');
      document.body.style.cursor = 'col-resize';
      document.body.style.userSelect = 'none';

      function onMove(e) {
        const w = Math.min(maxW, Math.max(minW, startW + (e.clientX - startX)));
        pane.style.width = w + 'px';
      }
      function onUp() {
        resizer.classList.remove('dragging');
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
        localStorage.setItem(storageKey, pane.offsetWidth);
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
      }
      document.addEventListener('mousemove', onMove);
      document.addEventListener('mouseup', onUp);
    });
  }

  // Init accounts pane resizer
  const accResizer = document.getElementById('accounts-resizer');
  if (accResizer) {
    accResizer.dataset.pane    = 'accounts-pane';
    accResizer.dataset.key     = 'accounts-pane-width';
    accResizer.dataset.min     = '120';
    accResizer.dataset.max     = '400';
    initResizer(accResizer);
  }

  // Init all generic pane resizers
  document.querySelectorAll('.pane-resizer[data-pane]').forEach(initResizer);
})();

// ── Extract email addresses ───────────────────────────────────────
let _extractedAll = [], _extractedFiltered = [], _extractAccountName = '';

async function promptExtractEmails() {
  try {
    const r = await fetch(`${API}?action=accounts`);
    const d = await r.json();
    const id   = d.active;
    const acc  = (d.accounts || []).find(a => a.id === id);
    const name = acc?.name || acc?.email || 'Account';
    if (!id) { toast('No active account'); return; }
    extractEmails(id, name);
  } catch(e) { toast('Could not get active account'); }
}

async function extractEmails(accountId, accountName) {
  _extractAccountName = accountName;
  _extractedAll = [];
  _extractedFiltered = [];

  // Show modal in loading state
  document.getElementById('extract-modal').style.display = 'flex';
  document.getElementById('extract-modal-title').textContent = accountName + ' — Email Addresses';
  document.getElementById('extract-modal-sub').textContent = 'Scanning all messages…';
  document.getElementById('extract-list').innerHTML = `
    <div class="extract-loading">
      <div class="extract-spinner"></div>
      <span id="extract-loading-text">Scanning all messages… this may take a moment.</span>
    </div>`;
  document.getElementById('extract-copy-btn').style.display = 'none';
  document.getElementById('extract-dl-btn').style.display = 'none';
  document.getElementById('extract-search').value = '';
  document.getElementById('extract-count').textContent = '';

  try {
    const r = await fetch(`${API}?action=extract_emails&account_id=${encodeURIComponent(accountId)}`);
    const text = await r.text();
    let d;
    try {
      d = JSON.parse(text);
    } catch(e) {
      // Server returned HTML — extract visible error text
      const stripped = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300);
      showExtractError('Server error: ' + (stripped || 'Unknown error. Check PHP error logs.'));
      return;
    }
    if (d.error) { showExtractError(d.error); return; }

    _extractedAll = d.addresses || [];
    _extractedFiltered = _extractedAll;

    document.getElementById('extract-modal-sub').textContent = `${d.count.toLocaleString()} unique addresses found`;
    document.getElementById('extract-copy-btn').style.display = '';
    document.getElementById('extract-dl-btn').style.display = '';
    renderExtracted(_extractedAll);
  } catch(err) {
    showExtractError('Request failed: ' + err.message);
  }
}

function showExtractError(msg) {
  document.getElementById('extract-modal-sub').textContent = 'Error';
  document.getElementById('extract-list').innerHTML = `<div class="extract-loading" style="color:var(--danger)">⚠ ${esc(msg)}</div>`;
}

function filterExtracted() {
  const q = document.getElementById('extract-search').value.toLowerCase();
  _extractedFiltered = q
    ? _extractedAll.filter(a => a.email.includes(q) || a.name.toLowerCase().includes(q))
    : _extractedAll;
  renderExtracted(_extractedFiltered);
}

function renderExtracted(list) {
  document.getElementById('extract-count').textContent = list.length.toLocaleString() + ' addresses';
  if (!list.length) {
    document.getElementById('extract-list').innerHTML = '<div class="extract-loading">No addresses found.</div>';
    return;
  }
  document.getElementById('extract-list').innerHTML = list.map(a => `
    <div class="extract-row">
      <span class="extract-email">${esc(a.email)}</span>
      ${a.name ? `<span class="extract-name">${esc(a.name)}</span>` : ''}
      <button class="extract-copy-one" onclick="navigator.clipboard.writeText('${esc(a.email)}');this.textContent='✓';setTimeout(()=>this.textContent='⎘',1500)" title="Copy">⎘</button>
    </div>`).join('');
}

function copyExtracted() {
  const text = _extractedFiltered.map(a => a.name ? `${a.name} <${a.email}>` : a.email).join('\n');
  navigator.clipboard.writeText(text).then(() => {
    const btn = document.getElementById('extract-copy-btn');
    btn.textContent = '✓ Copied!';
    setTimeout(() => btn.textContent = '⎘ Copy all', 2000);
  });
}

function downloadExtracted() {
  const rows = [['Name','Email'], ..._extractedFiltered.map(a => [a.name, a.email])];
  const csv  = rows.map(r => r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');
  const blob = new Blob([csv], {type:'text/csv'});
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url; a.download = (_extractAccountName || 'emails') + '-addresses.csv';
  a.click(); URL.revokeObjectURL(url);
}

function closeExtractModal() {
  document.getElementById('extract-modal').style.display = 'none';
}

// ── Attachment Preview ────────────────────────────────────────────
async function previewAttachment(emailId, attId, name, contentType) {
  // Show modal immediately with spinner
  let modal = document.getElementById('att-preview-modal');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'att-preview-modal';
    modal.className = 'modal-overlay';
    modal.style.cssText = 'display:flex;z-index:9999';
    modal.onclick = function(e) { if (e.target === modal) closeAttPreview(); };
    document.body.appendChild(modal);
  }
  modal.innerHTML = `
    <div class="att-preview-box">
      <div class="att-preview-head">
        <span class="att-preview-name">${esc(decodeURIComponent(name))}</span>
        <div style="display:flex;gap:.5rem;align-items:center">
          <a class="att-btn att-dl-btn" id="att-preview-dl" href="${API}?action=attachment_download&email_id=${emailId}&att_id=${attId}&name=${name}&mime=${encodeURIComponent(contentType)}" download="${esc(decodeURIComponent(name))}">↓ Download</a>
          <button class="extract-close-btn" onclick="closeAttPreview()">✕</button>
        </div>
      </div>
      <div class="att-preview-body" id="att-preview-body">
        <div class="extract-loading"><div class="extract-spinner"></div> Loading…</div>
      </div>
    </div>`;
  modal.style.display = 'flex';

  try {
    const r = await fetch(`${API}?action=attachment_content&email_id=${emailId}&att_id=${attId}`);
    const d = await r.json();
    if (d.error) throw new Error(d.error);

    const body = document.getElementById('att-preview-body');
    const isImage = contentType.startsWith('image/');
    const isPdf   = contentType.includes('pdf');
    const b64     = d.contentBytes || '';

    // Convert base64 to blob URL — works in all browsers, avoids iframe data: restrictions
    const binary  = atob(b64);
    const bytes   = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    const blob    = new Blob([bytes], { type: contentType });
    const blobUrl = URL.createObjectURL(blob);

    // Update download link to use blob too
    const dlLink = document.getElementById('att-preview-dl');
    if (dlLink) { dlLink.href = blobUrl; dlLink.download = decodeURIComponent(name); }

    if (isImage) {
      body.innerHTML = `<img src="${blobUrl}" style="max-width:100%;max-height:75vh;object-fit:contain;display:block;margin:auto;border-radius:6px" />`;
    } else if (isPdf) {
      body.innerHTML = `<iframe src="${blobUrl}" style="width:100%;height:75vh;border:none;border-radius:6px"></iframe>`;
    } else {
      body.innerHTML = `<div class="extract-loading">Preview not available. <a href="${blobUrl}" download="${esc(decodeURIComponent(name))}" style="color:var(--gold)">Download instead</a></div>`;
    }
  } catch(err) {
    const body = document.getElementById('att-preview-body');
    if (body) body.innerHTML = `<div class="extract-loading" style="color:var(--danger)">⚠ ${esc(err.message)}</div>`;
  }
}

function closeAttPreview() {
  const m = document.getElementById('att-preview-modal');
  if (m) m.style.display = 'none';
}
