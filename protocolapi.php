<?php
ob_start(function($buf) {
    if (headers_sent()) return $buf;
    $clean = trim($buf);
    if ($clean !== '' && $clean[0] !== '{' && $clean[0] !== '[') {
        header('Content-Type: application/json');
        return json_encode(['error' => 'Server output error', 'detail' => substr($clean, 0, 300)]);
    }
    return $buf;
});
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth/Security.php';
require_once __DIR__ . '/auth/Auth.php';
require_once __DIR__ . '/auth/Portal.php';
require_once __DIR__ . '/auth/Storage.php';
require_once __DIR__ . '/Graph.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!Auth::isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Not authenticated']); exit; }
Security::sendHeaders(true);
// CSRF on all state-changing protocol actions
$_safeProtoReads = ['get_rules','get_rules_all','get_summaries','get_state','debug_rules'];
if (!in_array($_GET['action'] ?? '', $_safeProtoReads, true)) {
    Security::verifyCsrf();
}

// Current portal user — always scoped
$portalUser = $_SESSION['portal_user'] ?? null;
$isAdmin    = Portal::isAdmin() && !Portal::isImpersonating();

// Helper: check a rule belongs to the current portal user
function ruleOwnedByMe(array $r, ?string $portalUser): bool {
    return ($r['portal_user'] ?? null) === $portalUser;
}

$action = $_GET['action'] ?? '';

try { switch ($action) {

    // ── Rules CRUD ───────────────────────────────────────────────

    case 'get_rules':
        // Rules for the currently active account, owned by current portal user
        $rules   = Storage::read(RULES_FILE);
        $accId   = Auth::getActiveAccountId();
        $mine    = array_values(array_filter($rules, function($r) use ($accId, $portalUser) {
            return ruleOwnedByMe($r, $portalUser) && $r['account_id'] === $accId;
        }));
        echo json_encode(['rules' => $mine]);
        break;

    case 'get_rules_all':
        // All rules belonging to current portal user (for forwarding engine in browser)
        $rules    = Storage::read(RULES_FILE);
        $accounts = Auth::getAccounts(); // already scoped to portal user
        $accMap   = [];
        foreach ($accounts as $a) $accMap[$a['id']] = $a['email'];
        $myAccountIds = array_column($accounts, 'id');

        // Admins (not impersonating) see all rules across all users
        if ($isAdmin) {
            $allAccounts = Auth::getAllAccounts();
            foreach ($allAccounts as $a) $accMap[$a['id']] = $a['email'];
            $out = array_map(function($r) use ($accMap) {
                $r['account_email'] = $accMap[$r['account_id']] ?? 'All accounts';
                return $r;
            }, $rules);
            echo json_encode(['rules' => array_values($out)]);
            break;
        }

        // Regular users: only their own rules
        $filtered = array_filter($rules, function($r) use ($myAccountIds, $portalUser) {
            if (!ruleOwnedByMe($r, $portalUser)) return false;
            return in_array($r['account_id'] ?? '', $myAccountIds) || ($r['all_accounts'] ?? false);
        });
        $out = array_map(function($r) use ($accMap) {
            $r['account_email'] = $accMap[$r['account_id']] ?? 'All accounts';
            return $r;
        }, $filtered);
        echo json_encode(['rules' => array_values($out)]);
        break;

    case 'save_rule':
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $rules  = Storage::read(RULES_FILE);
        $accId  = Auth::getActiveAccountId();
        $ruleId = $body['id'] ?? uniqid('rule_');

        // If editing existing rule, verify ownership
        foreach ($rules as $existing) {
            if ($existing['id'] === $ruleId) {
                if (!$isAdmin && !ruleOwnedByMe($existing, $portalUser)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Not your rule.']);
                    exit;
                }
                break;
            }
        }

        // Preserve history if editing
        $history = [];
        foreach ($rules as $r) { if ($r['id'] === $ruleId) { $history = $r['history'] ?? []; break; } }

        $rule = [
            'id'           => $ruleId,
            'account_id'   => $accId,
            'portal_user'  => $portalUser,   // always stamp owner
            'keyword'      => trim($body['keyword'] ?? ''),
            'match_in'     => $body['match_in'] ?? ['subject','body','from'],
            'forward_to'   => trim($body['forward_to'] ?? ''),
            'label'        => trim($body['label'] ?? ''),
            'active'       => (bool)($body['active'] ?? true),
            'all_accounts' => (bool)($body['all_accounts'] ?? false),
            'created_at'   => $body['created_at'] ?? date('c'),
            'history'      => $history,
        ];

        if (!$rule['keyword'] || !$rule['forward_to']) throw new Exception('Keyword and forward address are required');

        $found = false;
        foreach ($rules as &$r) { if ($r['id'] === $ruleId) { $r = $rule; $found = true; break; } }
        unset($r);
        if (!$found) $rules[] = $rule;
        Storage::write(RULES_FILE, $rules);
        echo json_encode(['status' => 'ok', 'rule' => $rule]);
        break;

    case 'delete_rule':
        $id    = $_GET['id'] ?? '';
        $rules = Storage::read(RULES_FILE);
        // Verify ownership before deleting
        foreach ($rules as $r) {
            if ($r['id'] === $id) {
                if (!$isAdmin && !ruleOwnedByMe($r, $portalUser)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Not your rule.']);
                    exit;
                }
                break;
            }
        }
        $rules = array_values(array_filter($rules, fn($r) => $r['id'] !== $id));
        Storage::write(RULES_FILE, $rules);
        echo json_encode(['status' => 'ok']);
        break;

    case 'toggle_rule':
        $id    = $_GET['id'] ?? '';
        $rules = Storage::read(RULES_FILE);
        $found = false;
        foreach ($rules as &$r) {
            if ($r['id'] === $id) {
                if (!$isAdmin && !ruleOwnedByMe($r, $portalUser)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Not your rule.']);
                    exit;
                }
                $r['active'] = !($r['active'] ?? true);
                $found = true;
                break;
            }
        }
        unset($r);
        if (!$found) throw new Exception('Rule not found');
        Storage::write(RULES_FILE, $rules);
        echo json_encode(['status' => 'ok']);
        break;

    // ── Summaries ────────────────────────────────────────────────

    case 'get_summaries':
        $summaries = Storage::read(SUMMARIES_FILE);
        $accId     = Auth::getActiveAccountId();
        $mine      = array_reverse($summaries[$accId] ?? []);
        echo json_encode(['summaries' => array_slice($mine, 0, 10)]);
        break;

    case 'run_now':
        define('PROTOCOL_INCLUDED', true);
        require_once __DIR__ . '/protocol.php';
        echo json_encode($result ?? ['status'=>'ok']);
        break;

    // ── Protocol state ───────────────────────────────────────────

    case 'get_state':
        $state = Storage::read(__DIR__ . '/data/protocol_state.json');
        $accId = Auth::getActiveAccountId();
        $log   = [];
        if (file_exists(PROTOCOL_LOG)) {
            $lines = file(PROTOCOL_LOG);
            $log   = array_slice(array_reverse($lines), 0, 50);
        }
        echo json_encode([
            'last_run'   => $state[$accId]['last_run'] ?? null,
            'last_count' => $state[$accId]['last_count'] ?? 0,
            'log'        => $log,
        ]);
        break;

    case 'debug_rules':
        $rules    = Storage::read(RULES_FILE);
        $accounts = Auth::getAccounts();
        $results  = [];
        foreach ($accounts as $acc) {
            // Only apply rules owned by the same portal user
            $applicable = array_filter($rules, function($r) use ($acc, $portalUser, $isAdmin) {
                $ownerOk = $isAdmin || ruleOwnedByMe($r, $portalUser);
                return $ownerOk && ($r['active'] ?? true)
                    && ($r['account_id'] === $acc['id'] || ($r['all_accounts'] ?? false));
            });
            try {
                $emails = Graph::getEmailsSince('inbox', date('Y-m-d\\TH:i:s\\Z', strtotime('-48 hours')), 10, $acc['id']);
                $matches = [];
                foreach ($emails['value'] ?? [] as $email) {
                    foreach ($applicable as $rule) {
                        $keyword  = strtolower(trim($rule['keyword'] ?? ''));
                        $matchIn  = $rule['match_in'] ?? ['subject','body','from'];
                        $haystack = '';
                        if (in_array('subject', $matchIn)) $haystack .= ' ' . strtolower($email['subject'] ?? '');
                        if (in_array('body',    $matchIn)) $haystack .= ' ' . strtolower($email['bodyPreview'] ?? '');
                        if (in_array('from',    $matchIn)) $haystack .= ' ' . strtolower($email['from']['emailAddress']['address'] ?? '');
                        if ($keyword && strpos($haystack, $keyword) !== false) {
                            $matches[] = ['subject' => $email['subject'], 'rule' => $rule['keyword'], 'forward_to' => $rule['forward_to']];
                        }
                    }
                }
                $results[] = [
                    'account'        => $acc['email'],
                    'rules_count'    => count($applicable),
                    'emails_checked' => count($emails['value'] ?? []),
                    'matches'        => $matches,
                ];
            } catch (Exception $e) {
                $results[] = ['account' => $acc['email'], 'error' => $e->getMessage()];
            }
        }
        echo json_encode(['debug' => $results, 'all_rules' => $rules]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}}
catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
