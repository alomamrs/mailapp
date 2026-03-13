<?php
// DIAGNOSTIC 3 — delete after fixing
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth/Auth.php';
require_once __DIR__ . '/auth/Portal.php';
require_once __DIR__ . '/Graph.php';

header('Content-Type: application/json');

try {
    $result = Graph::getEmails('inbox', 5, 0, '', '');
    echo json_encode(['success' => true, 'count' => count($result['value'] ?? []), 'sample' => array_slice($result['value'] ?? [], 0, 1), 'keys' => array_keys($result)], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}
