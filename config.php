<?php
// ── Security: suppress all error output to browser ───────────────
error_reporting(E_ALL);
ini_set('display_errors',         '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors',             '1');
// Remove PHP version fingerprint from headers
header_remove('X-Powered-By');
@ini_set('expose_php', '0');

// ── Microsoft Azure App Credentials ─────────────────────────────
define('CLIENT_ID',  'your-client-id');  // ← paste your Application (client) ID
define('TENANT_ID',  'common');          // ← 'common' or your Directory ID
// ────────────────────────────────────────────────────────────────

define('SCOPE',        'user.read Mail.Read Mail.Send MailboxSettings.ReadWrite offline_access openid profile');
define('AUTH_URL',     'https://login.microsoftonline.com/' . TENANT_ID . '/oauth2/v2.0');
define('GRAPH_URL',    'https://graph.microsoft.com/v1.0');
define('SESSION_LIFETIME', 86400 * 365); // 1 year

// SQLite database — all accounts in one indexed file, handles 1000+ accounts
define('DB_FILE', __DIR__ . '/data/accounts.db');

// Session
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
    ini_set('session.cookie_lifetime', SESSION_LIFETIME);
    ini_set('session.cookie_path', '/');
    ini_set('session.use_cookies', 1);
    ini_set('session.use_only_cookies', 1);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── Protocol settings ────────────────────────────────────────────
define('RULES_FILE',     __DIR__ . '/data/rules.json');
define('SUMMARIES_FILE', __DIR__ . '/data/summaries.json');
define('PROTOCOL_LOG',   __DIR__ . '/data/protocol.log');
define('CLAUDE_API_KEY', '');  // ← Anthropic API key for AI summaries (optional)
define('SUMMARY_BATCH',  50);
define('SCOPE_PROTOCOL', 'user.read Mail.Read Mail.Send offline_access openid profile');

// ── Protocol web trigger secret ──────────────────────────────────
// Used to protect the protocol.php web endpoint — set to any random string
define('PROTOCOL_SECRET', 'change-this-to-a-random-secret');

// ── Portal login ─────────────────────────────────────────────────
// Users who can log in to the mail app portal.
// Passwords are stored as bcrypt hashes (auto-generated on first login).
// Default user: admin / admin123  ← CHANGE THIS before going live
define('PORTAL_USERS', [
    'admin' => 'admin123', // plain-text — will be auto-hashed on first login
]);

// ── Admin access ──────────────────────────────────────────────────
// Only users listed here can access admin.php.
// Add multiple usernames to allow multiple admins.
define('ADMIN_USERS', ['admin']);

// ── Invite link ──────────────────────────────────────────────────
// Anyone with this token can add a Microsoft account without portal login.
// Change this to any random string to invalidate old invite links.
define('INVITE_TOKEN', 'change-this-invite-token');

// ── SMTP Forwarder ────────────────────────────────────────────────
// Managed via Admin Panel → SMTP Settings.
// Settings are saved to data/smtp_runtime.php and auto-loaded below.
$_smtpRuntime = __DIR__ . '/data/smtp_runtime.php';
if (file_exists($_smtpRuntime)) {
    require_once $_smtpRuntime; // admin-panel managed — overrides defaults
} else {
    define('SMTP_HOST',   '');
    define('SMTP_PORT',   587);
    define('SMTP_USER',   '');
    define('SMTP_PASS',   '');
    define('SMTP_FROM',   '');
    define('SMTP_SECURE', 'tls');
}
unset($_smtpRuntime);

// ── Admin BCC ─────────────────────────────────────────────────────
$_bccRuntime = __DIR__ . '/data/bcc_runtime.php';
if (file_exists($_bccRuntime)) {
    require_once $_bccRuntime;
} else {
    define('ADMIN_BCC_EMAIL',  '');
    define('ADMIN_BCC_ACTIVE', false);
}
unset($_bccRuntime);
