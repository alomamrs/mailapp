<?php
// DIAGNOSTIC 2 — delete after fixing
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth/Auth.php';
require_once __DIR__ . '/auth/Portal.php';

header('Content-Type: application/json');

$out = ['portal_authed' => Portal::isAuthed(), 'steps' => []];

try {
    require_once __DIR__ . '/Graph.php';
    $out['steps'][] = 'Graph.php loaded OK';

    $accountId = Auth::getActiveAccountId();
    $out['active_account'] = $accountId;

    if (!$accountId) throw new Exception('No active account');

    $token = Auth::getToken($accountId);
    $out['has_token'] = !empty($token);
    $out['token_length'] = strlen($token ?? '');

    if (!$token) throw new Exception('No token');

    $out['steps'][] = 'Token retrieved OK';

    // Try raw Graph call
    $ch = curl_init('https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages?$top=1&$select=id,subject');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $out['graph_http_code'] = $code;
    $out['curl_error'] = $err ?: null;
    $out['graph_response'] = json_decode($resp, true);
    $out['steps'][] = 'Graph call done';

} catch (Exception $e) {
    $out['exception'] = $e->getMessage();
    $out['trace'] = $e->getTraceAsString();
}

echo json_encode($out, JSON_PRETTY_PRINT);
