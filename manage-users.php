<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth/Security.php';
require_once __DIR__ . '/auth/Portal.php';
require_once __DIR__ . '/auth/UserTokens.php';

Portal::guard();
Security::startSecureSession();
Security::sendHeaders();
$currentUser = Portal::currentUser();
$isAdmin     = Portal::isAdmin() && !Portal::isImpersonating();

$success = '';
$error   = '';

// ── Handle POST actions ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Invite token actions (all users) ─────────────────────────
    if ($action === 'generate_token') {
        setUserToken($currentUser, generateUserToken());
        header('Location: manage-users.php?token_saved=1'); exit;
    }
    if ($action === 'clear_token') {
        setUserToken($currentUser, '');
        header('Location: manage-users.php?token_cleared=1'); exit;
    }
    if ($action === 'save_token') {
        $t = trim($_POST['custom_token'] ?? '');
        if (strlen($t) >= 8) { setUserToken($currentUser, $t); header('Location: manage-users.php?token_saved=1'); exit; }
        else $error = 'Token must be at least 8 characters.';
    }

    // ── Personal AI settings (all users) ─────────────────────────
    if ($action === 'save_my_ai') {
        $myAi = loadUserAiSettings($currentUser);
        $myAi['openrouter_api_key'] = trim($_POST['my_openrouter_api_key'] ?? '');
        $myAi['openrouter_model']   = trim($_POST['my_openrouter_model'] ?? '') ?: 'openai/gpt-4o-mini';
        if (saveUserAiSettings($currentUser, $myAi)) $success = 'Your AI settings saved.';
        else $error = 'Could not save AI settings — check data/ folder is writable.';
    }

    // ── Change own password (all users) ──────────────────────────
    if ($action === 'change_own_password') {
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm']  ?? '';
        if (strlen($password) < 6)      $error = 'Password must be at least 6 characters.';
        elseif ($password !== $confirm)  $error = 'Passwords do not match.';
        elseif (!Portal::changePassword($currentUser, $password)) $error = 'Could not update password.';
        else $success = 'Password updated.';
    }

    // ── Admin-only actions ────────────────────────────────────────
    if ($isAdmin) {
        if ($action === 'add') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirm  = $_POST['confirm']  ?? '';
            if (!$username)               $error = 'Username cannot be empty.';
            elseif (strlen($password) < 6) $error = 'Password must be at least 6 characters.';
            elseif ($password !== $confirm) $error = 'Passwords do not match.';
            elseif (!Portal::addUser($username, $password)) $error = "User \"$username\" already exists.";
            else $success = "User \"$username\" added.";
        }
        if ($action === 'change_password') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirm  = $_POST['confirm']  ?? '';
            if (!$username)               $error = 'Select a user.';
            elseif (strlen($password) < 6) $error = 'Password must be at least 6 characters.';
            elseif ($password !== $confirm) $error = 'Passwords do not match.';
            elseif (!Portal::changePassword($username, $password)) $error = 'User not found.';
            else $success = "Password for \"$username\" updated.";
        }
        if ($action === 'remove') {
            $username = trim($_POST['username'] ?? '');
            if ($username === $currentUser)   $error = 'Cannot remove your own account.';
            elseif (!Portal::removeUser($username)) $error = 'User not found.';
            else $success = "User \"$username\" removed.";
        }
        if ($action === 'save_ai') {
            $aiSettingsFile = __DIR__ . '/data/ai_settings.json';
            $aiSettings = file_exists($aiSettingsFile) ? (json_decode(file_get_contents($aiSettingsFile), true) ?? []) : [];
            $aiSettings['openrouter_api_key'] = trim($_POST['openrouter_api_key'] ?? '');
            $aiSettings['openrouter_model']   = trim($_POST['openrouter_model'] ?? '') ?: 'openai/gpt-4o-mini';
            if (file_put_contents($aiSettingsFile, json_encode($aiSettings, JSON_PRETTY_PRINT))) $success = 'AI settings saved.';
            else $error = 'Could not save AI settings — check data/ folder is writable.';
        }
    }
}

// ── Load data ─────────────────────────────────────────────────────
$users   = $isAdmin ? Portal::getUsers() : [];
$myToken = getUserToken($currentUser);
$myAiSettings = loadUserAiSettings($currentUser);
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
         . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$myInviteUrl = $myToken ? $baseUrl . '/invite.php?token=' . urlencode($myToken) . '&pu=' . urlencode($currentUser) : '';

$aiSettingsFile = __DIR__ . '/data/ai_settings.json';
$aiSettings = $isAdmin && file_exists($aiSettingsFile)
    ? (json_decode(file_get_contents($aiSettingsFile), true) ?? []) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Manage — Mail Portal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <style>
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
    :root{--bg:#f9f9f9;--surface:#fff;--surface2:#f3f2f1;--border:#e1dfdd;--text:#201f1e;--muted:#a19f9d;--gold:#0078d4;--danger:#d13438}
    @media(prefers-color-scheme:dark){:root{--bg:#1b1a19;--surface:#252423;--surface2:#323130;--border:#3b3a39;--text:#f3f2f1;--muted:#797775}}
    html,body{min-height:100%;font-family:'Geist',sans-serif;background:var(--bg);color:var(--text)}
    .bg{display:none}
    .bg-grid{display:none}
    .page{max-width:700px;margin:0 auto;padding:1.5rem 1.5rem}
    .topnav{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem}
    .topnav-brand{font-size:1.1rem;font-weight:700}.topnav-brand span{color:var(--gold)}
    .back-btn{font-size:.75rem;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .75rem;border-radius:6px;transition:all .15s;cursor:pointer;background:none;font-family:'Geist',sans-serif}
    .back-btn:hover{border-color:var(--gold);color:var(--gold)}
    h1{font-size:1.4rem;font-weight:700;margin-bottom:.3rem}
    .page-sub{font-size:.8rem;color:var(--muted);margin-bottom:2rem}
    .card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1.75rem;margin-bottom:1.25rem}
    .card-title{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:var(--gold);margin-bottom:1.25rem;font-family:'Geist Mono',monospace}
    .alert{padding:.7rem 1rem;border-radius:8px;font-size:.8rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.5rem}
    .alert-ok{background:rgba(22,163,74,.1);border:1px solid rgba(22,163,74,.3);color:#4ade80}
    .alert-err{background:rgba(224,82,82,.1);border:1px solid rgba(224,82,82,.3);color:var(--danger)}
    table{width:100%;border-collapse:collapse}
    th{font-family:'Geist Mono',monospace;font-size:.58rem;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);padding:.5rem .75rem;text-align:left;border-bottom:1px solid var(--border)}
    td{padding:.65rem .75rem;font-size:.84rem;border-bottom:1px solid var(--border)}
    tr:last-child td{border-bottom:none}
    .badge-you{font-size:.58rem;background:var(--gold);color:#1a1200;padding:.1rem .35rem;border-radius:3px;margin-left:.4rem;font-weight:700}
    .badge-admin{font-size:.58rem;background:rgba(212,168,67,.15);color:var(--gold);border:1px solid rgba(212,168,67,.3);padding:.1rem .35rem;border-radius:3px;margin-left:.4rem}
    .remove-btn{background:none;border:1px solid var(--border);color:var(--muted);font-size:.7rem;padding:.22rem .55rem;border-radius:5px;cursor:pointer;font-family:'Geist',sans-serif;transition:all .15s}
    .remove-btn:hover{border-color:var(--danger);color:var(--danger);background:rgba(224,82,82,.08)}
    .remove-btn:disabled{opacity:.3;cursor:not-allowed}
    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:.85rem}
    @media(max-width:500px){.form-grid{grid-template-columns:1fr}}
    .field{display:flex;flex-direction:column;gap:.4rem}
    .field-label{font-family:'Geist Mono',monospace;font-size:.58rem;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted)}
    .field-input{background:var(--surface2);border:1.5px solid var(--border);border-radius:7px;padding:.55rem .75rem;font-size:.84rem;font-family:'Geist',sans-serif;color:var(--text);outline:none;transition:border-color .2s;width:100%}
    .field-input:focus{border-color:var(--gold)}
    .field-input::placeholder{color:var(--muted)}
    .field-full{grid-column:1/-1}
    .btn{font-family:'Geist',sans-serif;font-size:.78rem;font-weight:600;padding:.5rem 1.1rem;border-radius:7px;cursor:pointer;border:1.5px solid var(--border);background:none;color:var(--text);transition:all .15s;margin-top:.85rem}
    .btn.primary{background:var(--gold);color:#1a1200;border-color:var(--gold)}
    .btn.primary:hover{background:#e8bc50;box-shadow:0 4px 16px rgba(212,168,67,.25)}
    .btn.sm{padding:.3rem .7rem;font-size:.72rem;margin-top:0}
    .divider{border:none;border-top:1px solid var(--border);margin:1rem 0}
    .section-badge{display:inline-block;font-size:.58rem;font-family:'Geist Mono',monospace;background:rgba(212,168,67,.1);color:var(--gold);border:1px solid rgba(212,168,67,.25);padding:.15rem .5rem;border-radius:4px;margin-bottom:1rem}
  </style>
</head>
<body>
<div class="page">
  <div class="topnav">
    <div class="topnav-brand" style="display:none"></div>
    <button class="back-btn" onclick="window.parent.postMessage('iframe-modal-close','*')">✕ Close</button>
  </div>
  <h1>My Account</h1>
  <div class="page-sub">Logged in as <strong><?= htmlspecialchars($currentUser) ?></strong><?php if ($isAdmin): ?> <span class="badge-admin">admin</span><?php endif; ?></div>

  <?php if ($success): ?><div class="alert alert-ok">✓ <?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if ($error):   ?><div class="alert alert-err">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>

  <!-- ══ My Invite Token (all users) ══ -->
  <div class="card">
    <div class="card-title">🔑 My Invite Link</div>
    <p style="font-size:.8rem;color:var(--muted);margin-bottom:1rem;line-height:1.6">
      Use this link to connect a Microsoft 365 account to your inbox. Keep it private — it's yours alone.
    </p>

    <?php if (isset($_GET['token_saved'])): ?>
      <div class="alert alert-ok" style="margin-bottom:.85rem">✓ Token saved.</div>
    <?php elseif (isset($_GET['token_cleared'])): ?>
      <div class="alert alert-err" style="margin-bottom:.85rem">Token cleared — Add Account will not work until you generate a new one.</div>
    <?php endif; ?>

    <?php if ($myToken): ?>
      <div style="margin-bottom:.85rem">
        <div class="field-label" style="margin-bottom:.4rem">Your Invite Link</div>
        <div style="display:flex;gap:.5rem;align-items:center">
          <input class="field-input" id="my-invite-url" type="text"
            value="<?= htmlspecialchars($myInviteUrl) ?>" readonly
            style="font-family:'Geist Mono',monospace;font-size:.68rem;flex:1"/>
          <button class="btn primary sm" onclick="copyMyInvite()">Copy</button>
        </div>
        <p style="font-size:.68rem;color:var(--muted);margin-top:.4rem">Opening this link starts the Microsoft sign-in flow and adds the account to your inbox.</p>
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <form method="POST" style="display:inline">
      <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="generate_token"/>
          <button type="submit" class="btn sm">↺ Regenerate</button>
        </form>
        <form method="POST" style="display:inline" onsubmit="return confirm('Clear token? Add Account will stop working.')">
      <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="clear_token"/>
          <button type="submit" class="btn sm" style="opacity:.5">✕ Clear</button>
        </form>
      </div>
    <?php else: ?>
      <div style="background:rgba(212,168,67,.07);border:1px solid rgba(212,168,67,.2);border-radius:8px;padding:.85rem;margin-bottom:1rem;font-size:.8rem;color:var(--muted)">
        ⚠ No invite token yet — the <strong>Add Account</strong> button in the mail app won't work until you generate one.
      </div>
      <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
        <form method="POST">
      <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="generate_token"/>
          <button type="submit" class="btn primary">⚡ Generate My Token</button>
        </form>
        <form method="POST" style="display:flex;gap:.4rem;flex:1;min-width:200px">
      <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="save_token"/>
          <input class="field-input" name="custom_token" type="text"
            placeholder="Or type a custom token (min 8 chars)"
            style="font-size:.78rem;flex:1"/>
          <button type="submit" class="btn sm">Save</button>
        </form>
      </div>
    <?php endif; ?>
  </div>

  <!-- ══ Change My Password (all users) ══ -->
  <div class="card">
    <div class="card-title">🔒 Change My Password</div>
    <form method="POST">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="change_own_password"/>
      <div class="form-grid">
        <div class="field">
          <label class="field-label">New Password</label>
          <input class="field-input" name="password" type="password" placeholder="Min 6 characters" autocomplete="new-password" required/>
        </div>
        <div class="field">
          <label class="field-label">Confirm Password</label>
          <input class="field-input" name="confirm" type="password" placeholder="Repeat password" autocomplete="new-password" required/>
        </div>
      </div>
      <button type="submit" class="btn primary">Update Password</button>
    </form>
  </div>

  <!-- ══ My AI Settings (all users) ══ -->
  <div class="card">
    <div class="card-title">✨ My AI Settings</div>
    <p style="font-size:.8rem;color:var(--muted);margin-bottom:1.25rem;line-height:1.6">
      Set your personal <a href="https://openrouter.ai" target="_blank" rel="noopener" style="color:var(--gold)">OpenRouter</a> API key for the ✨ Summarize feature.
      Your key is private and used only for your account. Free keys available at <strong style="color:var(--text)">openrouter.ai</strong>.
    </p>
    <form method="POST">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="save_my_ai"/>
      <div class="form-grid">
        <div class="field field-full">
          <label class="field-label">My OpenRouter API Key</label>
          <input class="field-input" name="my_openrouter_api_key" type="password"
            placeholder="sk-or-v1-..."
            value="<?= htmlspecialchars($myAiSettings['openrouter_api_key'] ?? '') ?>"
            autocomplete="off"/>
        </div>
        <div class="field field-full">
          <label class="field-label">Model</label>
          <input class="field-input" name="my_openrouter_model" type="text"
            placeholder="openai/gpt-4o-mini"
            value="<?= htmlspecialchars($myAiSettings['openrouter_model'] ?? '') ?>"/>
          <span style="font-size:.68rem;color:var(--muted);margin-top:.25rem">
            Examples: <code style="color:var(--gold)">openai/gpt-4o-mini</code> &nbsp;·&nbsp;
            <code style="color:var(--gold)">anthropic/claude-3-haiku</code> &nbsp;·&nbsp;
            <code style="color:var(--gold)">google/gemini-flash-1.5</code> &nbsp;·&nbsp;
            <code style="color:var(--gold)">mistralai/mistral-7b-instruct:free</code>
          </span>
        </div>
      </div>
      <button type="submit" class="btn primary">Save My AI Settings</button>
    </form>
    <?php if (!empty($myAiSettings['openrouter_api_key'])): ?>
      <p style="font-size:.72rem;color:#4ade80;margin-top:.85rem">✓ Your personal key is active — Summarize will use it.</p>
    <?php else: ?>
      <?php
      $globalFile = __DIR__ . '/data/ai_settings.json';
      $globalAi   = file_exists($globalFile) ? (json_decode(file_get_contents($globalFile), true) ?? []) : [];
      ?>
      <?php if (!empty($globalAi['openrouter_api_key'])): ?>
        <p style="font-size:.72rem;color:var(--muted);margin-top:.85rem">ℹ No personal key — the shared admin key will be used as fallback.</p>
      <?php else: ?>
        <p style="font-size:.72rem;color:#f87171;margin-top:.85rem">⚠ No key set — ✨ Summarize is disabled until you add one.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

<?php if ($isAdmin): ?>
  <!-- ══════════════════════════════════════════════════════════════
       ADMIN-ONLY SECTION
  ══════════════════════════════════════════════════════════════ -->
  <div style="margin:1.5rem 0 1rem;display:flex;align-items:center;gap:.75rem">
    <div style="flex:1;height:1px;background:var(--border)"></div>
    <span style="font-size:.62rem;font-family:'Geist Mono',monospace;color:var(--gold);letter-spacing:1.5px">ADMIN ONLY</span>
    <div style="flex:1;height:1px;background:var(--border)"></div>
  </div>

  <!-- Current Users -->
  <div class="card">
    <div class="card-title">Current Users</div>
    <?php if (empty($users)): ?>
      <div style="font-size:.8rem;color:var(--muted)">No users.</div>
    <?php else: ?>
    <table>
      <thead><tr><th>Username</th><th></th></tr></thead>
      <tbody>
      <?php
      $adminUsers = defined('ADMIN_USERS') ? ADMIN_USERS : [];
      foreach ($users as $u): ?>
        <tr>
          <td>
            <?= htmlspecialchars($u) ?>
            <?php if ($u === $currentUser): ?><span class="badge-you">YOU</span><?php endif; ?>
            <?php if (in_array($u, $adminUsers, true)): ?><span class="badge-admin">admin</span><?php endif; ?>
          </td>
          <td style="text-align:right">
            <form method="POST" style="display:inline" onsubmit="return confirm('Remove <?= htmlspecialchars(addslashes($u)) ?>
      <?= Security::csrfField() ?>?')">
              <input type="hidden" name="action" value="remove"/>
              <input type="hidden" name="username" value="<?= htmlspecialchars($u) ?>"/>
              <button class="remove-btn" <?= $u === $currentUser ? 'disabled title="Cannot remove yourself"' : '' ?>>Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- Add User -->
  <div class="card">
    <div class="card-title">Add New User</div>
    <form method="POST">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="add"/>
      <div class="form-grid">
        <div class="field field-full">
          <label class="field-label">Username</label>
          <input class="field-input" name="username" type="text" placeholder="newuser" autocomplete="off" required/>
        </div>
        <div class="field">
          <label class="field-label">Password</label>
          <input class="field-input" name="password" type="password" placeholder="Min 6 characters" autocomplete="new-password" required/>
        </div>
        <div class="field">
          <label class="field-label">Confirm Password</label>
          <input class="field-input" name="confirm" type="password" placeholder="Repeat password" autocomplete="new-password" required/>
        </div>
      </div>
      <button type="submit" class="btn primary">Add User</button>
    </form>
  </div>

  <!-- Change Any User's Password -->
  <div class="card">
    <div class="card-title">Change Any User's Password</div>
    <form method="POST">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="change_password"/>
      <div class="form-grid">
        <div class="field field-full">
          <label class="field-label">User</label>
          <select class="field-input" name="username" required>
            <?php foreach ($users as $u): ?>
              <option value="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars($u) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="field-label">New Password</label>
          <input class="field-input" name="password" type="password" placeholder="Min 6 characters" autocomplete="new-password" required/>
        </div>
        <div class="field">
          <label class="field-label">Confirm Password</label>
          <input class="field-input" name="confirm" type="password" placeholder="Repeat password" autocomplete="new-password" required/>
        </div>
      </div>
      <button type="submit" class="btn primary">Update Password</button>
    </form>
  </div>

  <!-- AI Settings -->
  <div class="card">
    <div class="card-title">AI Settings — Summarize</div>
    <p style="font-size:.8rem;color:var(--muted);margin-bottom:1.25rem;line-height:1.6">
      The ✨ Summarize button uses <a href="https://openrouter.ai" target="_blank" rel="noopener" style="color:var(--gold)">OpenRouter</a>
      to summarize emails with AI. Get a free key at <strong style="color:var(--text)">openrouter.ai</strong>.
    </p>
    <form method="POST">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="save_ai"/>
      <div class="form-grid">
        <div class="field field-full">
          <label class="field-label">OpenRouter API Key</label>
          <input class="field-input" name="openrouter_api_key" type="password"
            placeholder="sk-or-v1-..."
            value="<?= htmlspecialchars($aiSettings['openrouter_api_key'] ?? '') ?>"
            autocomplete="off"/>
        </div>
        <div class="field field-full">
          <label class="field-label">Model</label>
          <input class="field-input" name="openrouter_model" type="text"
            placeholder="openai/gpt-4o-mini"
            value="<?= htmlspecialchars($aiSettings['openrouter_model'] ?? 'openai/gpt-4o-mini') ?>"/>
          <span style="font-size:.68rem;color:var(--muted);margin-top:.25rem">
            Examples: <code style="color:var(--gold)">openai/gpt-4o-mini</code> &nbsp;·&nbsp;
            <code style="color:var(--gold)">anthropic/claude-3-haiku</code> &nbsp;·&nbsp;
            <code style="color:var(--gold)">google/gemini-flash-1.5</code> &nbsp;·&nbsp;
            <code style="color:var(--gold)">mistralai/mistral-7b-instruct:free</code>
          </span>
        </div>
      </div>
      <button type="submit" class="btn primary">Save AI Settings</button>
    </form>
    <?php if (!empty($aiSettings['openrouter_api_key'])): ?>
      <p style="font-size:.72rem;color:#4ade80;margin-top:.85rem">✓ API key is configured.</p>
    <?php else: ?>
      <p style="font-size:.72rem;color:var(--muted);margin-top:.85rem">⚠ No API key set — Summarize is disabled.</p>
    <?php endif; ?>
  </div>

  <!-- Link to full admin panel -->
  <div style="text-align:center;padding:.5rem 0 1rem">
    <a href="admin.php" style="font-size:.78rem;color:var(--gold);text-decoration:none;border:1px solid rgba(212,168,67,.3);padding:.45rem 1.1rem;border-radius:7px;transition:all .15s" onmouseover="this.style.background='rgba(212,168,67,.08)'" onmouseout="this.style.background=''">
      ⚙ Open Full Admin Panel →
    </a>
  </div>

<?php endif; ?>
</div>
<script>
function copyMyInvite() {
  const inp = document.getElementById('my-invite-url');
  if (!inp) return;
  navigator.clipboard.writeText(inp.value).then(() => {
    document.querySelectorAll('[onclick="copyMyInvite()"]').forEach(b => {
      b.textContent = 'Copied!';
      setTimeout(() => b.textContent = 'Copy', 2000);
    });
  });
}
</script>
<script>
// If inside iframe modal, signal parent to keep modal open after redirect
(function(){
  if(window.self !== window.top){
    // Style adjustments for iframe context
    document.documentElement.style.setProperty('--bg','transparent');
    document.body.style.background='transparent';
  }
})();
</script>
</body>
</html>
