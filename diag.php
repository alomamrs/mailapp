<?php
// DIAGNOSTIC — delete after fixing
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth/Auth.php';
require_once __DIR__ . '/auth/Portal.php';

header('Content-Type: application/json');

$out = [];
$out['php_version']    = PHP_VERSION;
$out['session_status'] = session_status();
$out['session_id']     = session_id();
$out['portal_user']    = $_SESSION['portal_user'] ?? null;
$out['portal_authed']  = Portal::isAuthed();
$out['db_file']        = DB_FILE;
$out['db_exists']      = file_exists(DB_FILE);
$out['db_readable']    = is_readable(DB_FILE);
$out['error']          = null;

try {
    if (file_exists(DB_FILE)) {
        $out['active_account'] = Auth::getActiveAccountId();
        $out['accounts']       = Auth::getAccounts();
        if (!empty($out['active_account'])) {
            $tok = Auth::getToken($out['active_account']);
            $out['has_token'] = !empty($tok);
        }
    }
} catch (Exception $e) {
    $out['error'] = $e->getMessage();
}

echo json_encode($out, JSON_PRETTY_PRINT);
