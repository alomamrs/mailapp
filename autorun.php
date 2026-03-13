<?php
/**
 * autorun.php — triggered by the browser every 5 minutes while app is open.
 * Runs protocol (forwarding rules + sent cleanup) if enough time has passed.
 * No cron job needed.
 */

// Session must start with same settings as rest of app
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_httponly', 1);
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth/Auth.php';
require_once __DIR__ . '/auth/Portal.php';
require_once __DIR__ . '/auth/Storage.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

// Accept portal session OR PROTOCOL_SECRET token as auth
$secretOk  = isset($_GET['token']) && $_GET['token'] === PROTOCOL_SECRET;
$portalOk  = Portal::isAuthed();
$accountOk = Auth::isLoggedIn(); // has at least one MS account

if (!$secretOk && !$portalOk && !$accountOk) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Must have at least one Microsoft account to forward from
if (!Auth::isLoggedIn()) {
    echo json_encode(['skipped' => 'no accounts added yet']);
    exit;
}

$stateFile   = __DIR__ . '/data/protocol_state.json';
$lockFile    = __DIR__ . '/data/autorun.lock';
$minInterval = 300; // run at most every 5 minutes

// Check last run time
$state   = file_exists($stateFile) ? (json_decode(file_get_contents($stateFile), true) ?: []) : [];
$lastRun = $state['_autorun_last'] ?? 0;
$now     = time();

if (($now - $lastRun) < $minInterval && !isset($_GET['force'])) {
    echo json_encode(['skipped' => 'too soon', 'next_in' => ($minInterval - ($now - $lastRun)) . 's']);
    exit;
}

// Prevent concurrent runs
if (file_exists($lockFile) && ($now - filemtime($lockFile)) < 120) {
    echo json_encode(['skipped' => 'already running']);
    exit;
}

touch($lockFile);
$state['_autorun_last'] = $now;
file_put_contents($stateFile, json_encode($state));

try {
    define('PROTOCOL_INCLUDED', true);
    require_once __DIR__ . '/Graph.php';
    require_once __DIR__ . '/protocol.php';
    $result = Protocol::run();
    echo json_encode(['ran' => true, 'result' => $result]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
} finally {
    @unlink($lockFile);
}
