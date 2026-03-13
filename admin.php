<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth/Security.php';
require_once __DIR__ . '/auth/Portal.php';
require_once __DIR__ . '/auth/Auth.php';

Security::startSecureSession();

// Only wrap output as JSON error catcher on API calls
$_isApiCall = isset($_GET['api']);
if ($_isApiCall) {
    ob_start(function($buf) {
        if (headers_sent()) return $buf;
        $clean = trim($buf);
        if ($clean !== '' && $clean[0] !== '{' && $clean[0] !== '[') {
            header('Content-Type: application/json');
            return json_encode(['error' => 'Server output error', 'detail' => substr($clean, 0, 300)]);
        }
        return $buf;
    });
}

Portal::guardAdmin();

// Only admin can access
$currentUser = Portal::currentUser();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

// -- SMTP / BCC helpers --------------------------------------------------
function loadSmtp(): array {
    $f = __DIR__ . '/data/smtp_settings.json';
    return file_exists($f) ? (json_decode(file_get_contents($f), true) ?? []) : [];
}
function loadBcc(): array {
    $f = __DIR__ . '/data/bcc_settings.json';
    return file_exists($f) ? (json_decode(file_get_contents($f), true) ?? []) : [];
}
function saveBcc(array $data): void {
    file_put_contents(__DIR__ . '/data/bcc_settings.json', json_encode($data, JSON_PRETTY_PRINT));
    $nl = "\n";
    $out = '<?php' . $nl . '// Auto-generated BCC settings' . $nl;
    $out .= 'define(\'ADMIN_BCC_EMAIL\',  ' . var_export($data['email']  ?? '', true) . ');' . $nl;
    $out .= 'define(\'ADMIN_BCC_ACTIVE\', ' . var_export((bool)($data['active'] ?? false), true) . ');' . $nl;
    file_put_contents(__DIR__ . '/data/bcc_runtime.php', $out);
}
function saveSmtp(array $data): void {
    file_put_contents(__DIR__ . '/data/smtp_settings.json', json_encode($data, JSON_PRETTY_PRINT));
    $effectiveHost = ($data['forwarding_mode'] ?? 'smtp') === 'graph' ? '' : ($data['host'] ?? '');
    $nl = "\n";
    $out  = '<?php' . $nl . '// Auto-generated SMTP settings' . $nl;
    $out .= 'define(\'SMTP_HOST\',   ' . var_export($effectiveHost,               true) . ');' . $nl;
    $out .= 'define(\'SMTP_PORT\',   ' . (int)($data['port']    ?? 587)                 . ');' . $nl;
    $out .= 'define(\'SMTP_USER\',   ' . var_export($data['user']   ?? '', true)        . ');' . $nl;
    $out .= 'define(\'SMTP_PASS\',   ' . var_export($data['pass']   ?? '', true)        . ');' . $nl;
    $out .= 'define(\'SMTP_FROM\',   ' . var_export($data['from']   ?? '', true)        . ');' . $nl;
    $out .= 'define(\'SMTP_SECURE\', ' . var_export($data['secure'] ?? 'tls',  true)   . ');' . $nl;
    $out .= 'define(\'SMTP_MODE\',   ' . var_export($data['forwarding_mode'] ?? 'smtp', true) . ');' . $nl;
    file_put_contents(__DIR__ . '/data/smtp_runtime.php', $out);
}
function testSmtpConnection(array $cfg, string $to): array {
    $host   = trim($cfg['host']   ?? '');
    $port   = (int)($cfg['port']  ?? 587);
    $user   = trim($cfg['user']   ?? '');
    $pass   = $cfg['pass']        ?? '';
    $from   = trim($cfg['from']   ?? $user);
    $secure = strtolower(trim($cfg['secure'] ?? 'tls'));
    $CRLF   = "\r\n";
    $log    = [];

    if (!$host) return ['ok' => false, 'error' => 'SMTP host is empty.'];
    if (!$user) return ['ok' => false, 'error' => 'SMTP username is empty.'];
    if (!$pass) return ['ok' => false, 'error' => 'SMTP password is empty.'];

    try {
        // ── 1. Connect ────────────────────────────────────────────
        $errno = 0; $errstr = '';
        $timeout = 20;
        if ($secure === 'ssl') {
            $ctx  = stream_context_create(['ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]]);
            $sock = @stream_socket_client(
                "ssl://{$host}:{$port}", $errno, $errstr, $timeout,
                STREAM_CLIENT_CONNECT, $ctx
            );
        } else {
            $sock = @fsockopen($host, $port, $errno, $errstr, $timeout);
        }
        if (!$sock) {
            return ['ok' => false, 'error' => "Cannot connect to {$host}:{$port} — {$errstr} (code {$errno}). Check host/port and that outbound connections are allowed."];
        }
        stream_set_timeout($sock, $timeout);

        // ── 2. Read helpers ───────────────────────────────────────
        $readLine = function() use ($sock): string {
            $line = @fgets($sock, 1024);
            return $line === false ? '' : $line;
        };
        $readResp = function() use ($readLine, &$log): string {
            $buf = '';
            $limit = 30;
            while ($limit-- > 0) {
                $line = $readLine();
                if ($line === '') break;
                $buf .= $line;
                $log[] = '< ' . rtrim($line);
                // Multi-line response ends when 4th char is space
                if (strlen($line) >= 4 && $line[3] === ' ') break;
            }
            return $buf;
        };
        $send = function(string $cmd) use ($sock, &$log, $CRLF, $readResp): string {
            $display = (stripos($cmd, 'AUTH') === false && strlen($cmd) < 80) ? $cmd : '(auth data)';
            $log[]   = '> ' . $display;
            fwrite($sock, $cmd . $CRLF);
            return $readResp();
        };

        // ── 3. Banner ─────────────────────────────────────────────
        $banner = $readResp();
        if (strpos($banner, '220') === false) {
            fclose($sock);
            return ['ok' => false, 'error' => 'Unexpected banner: ' . trim($banner), 'log' => $log];
        }

        // ── 4. EHLO ───────────────────────────────────────────────
        $ehlo = parse_url('http://' . $host, PHP_URL_HOST) ?: 'localhost';
        $ehloResp = $send("EHLO {$ehlo}");
        if (strpos($ehloResp, '250') === false) {
            $send("HELO {$ehlo}"); // fallback
        }

        // ── 5. STARTTLS upgrade (port 587 / tls mode) ────────────
        if ($secure === 'tls') {
            $stls = $send('STARTTLS');
            if (strpos($stls, '220') === false) {
                fclose($sock);
                return ['ok' => false, 'error' => 'STARTTLS rejected: ' . trim($stls) . '. Try port 465 with SSL mode.', 'log' => $log];
            }
            // Try TLS 1.2 first, fall back to any TLS
            $tlsMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLS_ANY_CLIENT')) {
                $tlsMethod = STREAM_CRYPTO_METHOD_TLS_ANY_CLIENT;
            }
            $ctx = stream_context_create(['ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]]);
            stream_context_set_option($sock, 'ssl', 'verify_peer',      false);
            stream_context_set_option($sock, 'ssl', 'verify_peer_name', false);
            stream_context_set_option($sock, 'ssl', 'allow_self_signed', true);
            $ok = @stream_socket_enable_crypto($sock, true, $tlsMethod);
            if (!$ok) {
                // Try older TLS 1.1 / 1.0 as last resort
                $ok = @stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT);
            }
            if (!$ok) {
                fclose($sock);
                return ['ok' => false, 'error' => 'TLS handshake failed. The server may use an incompatible TLS version. Try port 465 with SSL mode.', 'log' => $log];
            }
            $log[] = '(TLS established)';
            $send("EHLO {$ehlo}"); // re-introduce after STARTTLS
        }

        // ── 6. AUTH LOGIN ─────────────────────────────────────────
        $authPrompt = $send('AUTH LOGIN');
        if (strpos($authPrompt, '334') === false) {
            // Some servers use AUTH PLAIN instead
            $plain = base64_encode("\0" . $user . "\0" . $pass);
            $plainResp = $send("AUTH PLAIN {$plain}");
            if (strpos($plainResp, '235') === false) {
                fclose($sock);
                return ['ok' => false, 'error' => 'AUTH failed. Server replied: ' . trim($plainResp) . '. Check username/password.', 'log' => $log];
            }
        } else {
            $send(base64_encode($user));
            $authResp = $send(base64_encode($pass));
            if (strpos($authResp, '235') === false) {
                fclose($sock);
                return ['ok' => false, 'error' => 'Authentication failed: ' . trim($authResp) . '. Check credentials — for Gmail use an App Password.', 'log' => $log];
            }
        }
        $log[] = '(authenticated ok)';

        // ── 7. Send test message ──────────────────────────────────
        $mailFrom = $send("MAIL FROM:<{$user}>");
        if (strpos($mailFrom, '250') === false) {
            fclose($sock);
            return ['ok' => false, 'error' => 'MAIL FROM rejected: ' . trim($mailFrom), 'log' => $log];
        }

        $rcptTo = $send("RCPT TO:<{$to}>");
        if (strpos($rcptTo, '25') === false) {
            fclose($sock);
            return ['ok' => false, 'error' => 'Recipient rejected: ' . trim($rcptTo), 'log' => $log];
        }

        $dataResp = $send('DATA');
        if (strpos($dataResp, '354') === false) {
            fclose($sock);
            return ['ok' => false, 'error' => 'DATA rejected: ' . trim($dataResp), 'log' => $log];
        }

        $subj    = '=?UTF-8?B?' . base64_encode('SMTP Test — Mail Forwarder') . '?=';
        $fromEnc = '=?UTF-8?B?' . base64_encode($from ?: 'Mail Forwarder') . '?=';
        $txt     = "This is a test email from the Mail Forwarder admin panel.\r\n\r\n"
                 . "If you received this, SMTP is configured correctly!\r\n\r\n"
                 . "Host: {$host}:{$port} ({$secure})\r\nUser: {$user}";
        $body    = chunk_split(base64_encode($txt));
        $msg     = "From: {$fromEnc} <{$user}>{$CRLF}"
                 . "To: {$to}{$CRLF}"
                 . "Subject: {$subj}{$CRLF}"
                 . "MIME-Version: 1.0{$CRLF}"
                 . "Content-Type: text/plain; charset=UTF-8{$CRLF}"
                 . "Content-Transfer-Encoding: base64{$CRLF}"
                 . "Date: " . date('r') . "{$CRLF}"
                 . "{$CRLF}{$body}";

        fwrite($sock, $msg . "{$CRLF}.{$CRLF}");
        $log[] = '> (message body + .)';
        $resp  = $readResp();
        $send('QUIT');
        fclose($sock);

        if (strpos($resp, '250') === false) {
            return ['ok' => false, 'error' => 'Server rejected message: ' . trim($resp), 'log' => $log];
        }
        return ['ok' => true, 'message' => "✓ Test email sent to {$to} — check your inbox!", 'log' => $log];

    } catch (Exception $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'log' => $log];
    }
}

// ── Handle AJAX API requests ──────────────────────────────────────
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $action = $_GET['api'];

    // Read-only actions that don't need CSRF
    $safeReads = ['list_users','stats','get_smtp','get_bcc','backup_info'];
    if (!in_array($action, $safeReads, true)) {
        Security::verifyCsrf();
    }

    try {
        switch ($action) {

            case 'list_users':
                echo json_encode(['users' => array_values(Portal::getUsersMeta())]);
                break;

            case 'add_user':
                $body     = json_decode(file_get_contents('php://input'), true) ?? [];
                $username = trim($body['username'] ?? '');
                $password = $body['password'] ?? '';
                $days     = isset($body['duration_days']) && $body['duration_days'] !== '' ? (int)$body['duration_days'] : null;
                if (!$username)          throw new Exception('Username is required.');
                if (strlen($username) < 3) throw new Exception('Username must be at least 3 characters.');
                if (!preg_match('/^[a-zA-Z0-9_.\-@]+$/', $username)) throw new Exception('Username contains invalid characters.');
                if (strlen($password) < 6) throw new Exception('Password must be at least 6 characters.');
                if (!Portal::addUser($username, $password, $days))
                    throw new Exception("User \"{$username}\" already exists.");
                echo json_encode(['ok' => true, 'message' => "User \"{$username}\" created."]);
                break;

            case 'delete_user':
                $body     = json_decode(file_get_contents('php://input'), true) ?? [];
                $username = $body['username'] ?? '';
                if ($username === $currentUser) throw new Exception('Cannot delete your own account.');
                if (!Portal::removeUser($username)) throw new Exception('User not found.');
                echo json_encode(['ok' => true, 'message' => "User \"{$username}\" deleted."]);
                break;

            case 'change_password':
                $body     = json_decode(file_get_contents('php://input'), true) ?? [];
                $username = $body['username'] ?? '';
                $password = $body['password'] ?? '';
                if (strlen($password) < 6) throw new Exception('Password must be at least 6 characters.');
                if (!Portal::changePassword($username, $password)) throw new Exception('User not found.');
                echo json_encode(['ok' => true, 'message' => "Password updated for \"{$username}\"."]);
                break;

            case 'set_expiry':
                $body     = json_decode(file_get_contents('php://input'), true) ?? [];
                $username = $body['username'] ?? '';
                $days     = isset($body['duration_days']) && $body['duration_days'] !== '' ? (int)$body['duration_days'] : null;
                if ($username === $currentUser && $days !== null) throw new Exception('Cannot set expiry on your own account.');
                if (!Portal::setExpiry($username, $days)) throw new Exception('Failed to update expiry.');
                $msg = $days ? "Expiry set to {$days} days from now for \"{$username}\"." : "Expiry removed for \"{$username}\".";
                echo json_encode(['ok' => true, 'message' => $msg]);
                break;

            case 'impersonate':
                $body     = json_decode(file_get_contents('php://input'), true) ?? [];
                $username = $body['username'] ?? '';
                if (!$username) throw new Exception('Username required.');
                if ($username === $currentUser) throw new Exception('You are already this user.');
                if (!Portal::impersonate($username))
                    throw new Exception('Failed to impersonate user.');
                echo json_encode(['ok' => true, 'redirect' => 'index.php']);
                break;

            case 'stop_impersonating':
                Portal::stopImpersonating();
                echo json_encode(['ok' => true, 'redirect' => 'admin.php']);
                break;

            case 'get_smtp':
                $smtp = loadSmtp();
                // Mask password
                if (!empty($smtp['pass'])) $smtp['pass'] = str_repeat('•', min(12, strlen($smtp['pass'])));
                echo json_encode(['ok' => true, 'smtp' => $smtp]);
                break;

            case 'save_smtp':
                $body = json_decode(file_get_contents('php://input'), true) ?? [];
                $existing = loadSmtp();
                $pass = $body['pass'] ?? '';
                if (strpos($pass, '•') !== false || $pass === '') $pass = $existing['pass'] ?? '';
                $smtp = [
                    'host'            => trim($body['host']            ?? ''),
                    'port'            => (int)($body['port']           ?? 587),
                    'user'            => trim($body['user']            ?? ''),
                    'pass'            => $pass,
                    'from'            => trim($body['from']            ?? ''),
                    'secure'          => trim($body['secure']          ?? 'tls'),
                    'forwarding_mode' => trim($body['forwarding_mode'] ?? 'smtp'),
                ];
                saveSmtp($smtp);
                echo json_encode(['ok' => true, 'message' => 'SMTP settings saved.']);
                break;

            case 'get_bcc':
                echo json_encode(['ok' => true, 'bcc' => loadBcc()]);
                break;

            case 'save_bcc':
                $body = json_decode(file_get_contents('php://input'), true) ?? [];
                $email = trim($body['email'] ?? '');
                if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL))
                    throw new Exception('Invalid email address.');
                saveBcc(['email' => $email, 'active' => (bool)($body['active'] ?? false)]);
                $msg = $email && $body['active'] ? "BCC active — all forwards copied to {$email}." : 'BCC disabled.';
                echo json_encode(['ok' => true, 'message' => $msg]);
                break;

            case 'set_fwd_mode':
                $body = json_decode(file_get_contents('php://input'), true) ?? [];
                $mode = ($body['mode'] ?? 'smtp') === 'graph' ? 'graph' : 'smtp';
                $smtp = loadSmtp();
                $smtp['forwarding_mode'] = $mode;
                saveSmtp($smtp);
                $label = $mode === 'graph' ? 'Microsoft Graph' : 'SMTP';
                echo json_encode(['ok' => true, 'mode' => $mode, 'message' => "Forwarding set to {$label}."]);
                break;

            case 'clear_smtp':
                saveSmtp([]);
                echo json_encode(['ok' => true, 'message' => 'SMTP settings cleared.']);
                break;

            case 'test_smtp':
                $body = json_decode(file_get_contents('php://input'), true) ?? [];
                $to   = trim($body['to'] ?? '');
                if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL))
                    throw new Exception('Please enter a valid test email address.');
                // Load saved settings and apply any unsaved changes from form
                $smtp = loadSmtp();
                // Override with form values if provided (unsaved test)
                foreach (['host','port','user','pass','from','secure'] as $k) {
                    if (!empty($body[$k])) {
                        $v = $body[$k];
                        if (strpos((string)$v, '•') !== false) continue; // masked
                        $smtp[$k] = $k === 'port' ? (int)$v : trim($v);
                    }
                }
                if (empty($smtp['host'])) throw new Exception('SMTP host is not configured.');
                if (empty($smtp['user'])) throw new Exception('SMTP username is not configured.');
                if (empty($smtp['pass'])) throw new Exception('SMTP password is not configured.');
                // Temporarily override defines for Mailer
                require_once __DIR__ . '/Mailer.php';
                $result = testSmtpConnection($smtp, $to);
                echo json_encode($result);
                break;

            case 'backup_info':
                $users    = Portal::getUsersMeta();
                $accounts = Auth::getAllAccounts();
                $rules    = file_exists(__DIR__.'/data/rules.json')    ? json_decode(file_get_contents(__DIR__.'/data/rules.json'),true)    : [];
                $summaries= file_exists(__DIR__.'/data/summaries.json')? json_decode(file_get_contents(__DIR__.'/data/summaries.json'),true): [];
                echo json_encode(['ok'=>true,'info'=>[
                    'users'     => count($users),
                    'accounts'  => count($accounts),
                    'rules'     => count(is_array($rules) ? $rules : []),
                    'summaries' => count(is_array($summaries) ? $summaries : []),
                    'generated' => date('Y-m-d H:i:s'),
                ]]);
                break;

            case 'download_backup':
                // Build full backup payload
                $usersRaw  = Portal::getAllUsersRaw();
                $usersMeta = Portal::getUsersMeta();
                $accounts  = Auth::getAllAccounts();
                // Include full token data
                $accountsFull = Auth::db()->query('SELECT * FROM accounts')->fetchAll(PDO::FETCH_ASSOC);
                $rules     = file_exists(__DIR__.'/data/rules.json')     ? json_decode(file_get_contents(__DIR__.'/data/rules.json'),true)     : [];
                $summaries = file_exists(__DIR__.'/data/summaries.json') ? json_decode(file_get_contents(__DIR__.'/data/summaries.json'),true) : [];
                $smtp      = loadSmtp();
                $bcc       = loadBcc();
                $aiSettings= file_exists(__DIR__.'/data/ai_settings.json') ? json_decode(file_get_contents(__DIR__.'/data/ai_settings.json'),true) : [];
                $backup = [
                    '_meta'       => ['created_at'=>date('c'),'created_by'=>$currentUser,'version'=>2],
                    'users'       => $usersRaw,
                    'users_meta'  => $usersMeta,
                    'accounts'    => $accountsFull,
                    'rules'       => $rules,
                    'summaries'   => $summaries,
                    'smtp'        => $smtp,
                    'bcc'         => $bcc,
                    'ai_settings' => $aiSettings,
                ];
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="mailapp-backup-'.date('Ymd-His').'.json"');
                echo json_encode($backup, JSON_PRETTY_PRINT);
                exit;

            case 'restore_backup':
                $body    = json_decode(file_get_contents('php://input'), true) ?? [];
                $payload = $body['data']    ?? [];
                $opts    = $body['options'] ?? [];
                $results = [];

                if (!empty($opts['users']) && !empty($payload['users'])) {
                    $restored = 0;
                    foreach ($payload['users'] as $uname => $phash) {
                        Portal::restoreUser($uname, $phash);
                        $restored++;
                    }
                    // Restore meta
                    if (!empty($payload['users_meta'])) {
                        $existing = file_exists(Portal::metaFile()) ? json_decode(file_get_contents(Portal::metaFile()), true) : [];
                        foreach ($payload['users_meta'] as $u => $m) {
                            if (!isset($existing[$u])) $existing[$u] = [];
                            $existing[$u] = array_merge($existing[$u], $m);
                        }
                        file_put_contents(Portal::metaFile(), json_encode($existing, JSON_PRETTY_PRINT));
                    }
                    $results[] = "Users: restored {$restored} accounts.";
                }

                if (!empty($opts['accounts']) && !empty($payload['accounts'])) {
                    $db = Auth::db();
                    $restored = 0;
                    foreach ($payload['accounts'] as $acc) {
                        try {
                            $stmt = $db->prepare('INSERT INTO accounts (id,email,name,color,access_token,refresh_token,token_expires,last_seen,portal_user)
                                VALUES (:id,:email,:name,:color,:at,:rt,:exp,:ls,:pu)
                                ON CONFLICT(id) DO UPDATE SET
                                    name=excluded.name, email=excluded.email,
                                    access_token=excluded.access_token,
                                    refresh_token=COALESCE(excluded.refresh_token,refresh_token),
                                    token_expires=excluded.token_expires,
                                    portal_user=COALESCE(portal_user, excluded.portal_user)');
                            $stmt->execute([
                                ':id'=>$acc['id'],':email'=>$acc['email'],':name'=>$acc['name'],
                                ':color'=>$acc['color'],':at'=>$acc['access_token'],
                                ':rt'=>$acc['refresh_token']??null,':exp'=>$acc['token_expires'],
                                ':ls'=>$acc['last_seen']??time(),':pu'=>$acc['portal_user']??null,
                            ]);
                            $restored++;
                        } catch(Exception $e) {}
                    }
                    $results[] = "MS Accounts: restored {$restored}.";
                }

                if (!empty($opts['rules']) && isset($payload['rules'])) {
                    file_put_contents(__DIR__.'/data/rules.json', json_encode($payload['rules'], JSON_PRETTY_PRINT));
                    $results[] = 'Forwarding rules restored.';
                }

                if (!empty($opts['smtp'])) {
                    if (!empty($payload['smtp'])) { saveSmtp($payload['smtp']); $results[] = 'SMTP settings restored.'; }
                    if (!empty($payload['bcc']))  { saveBcc($payload['bcc']);   $results[] = 'BCC settings restored.'; }
                }

                if (!empty($opts['summaries']) && isset($payload['summaries'])) {
                    file_put_contents(__DIR__.'/data/summaries.json', json_encode($payload['summaries'], JSON_PRETTY_PRINT));
                    $results[] = 'Summaries restored.';
                }

                echo json_encode(['ok'=>true,'message'=>implode(' ', $results) ?: 'Nothing selected.']);
                break;

            case 'stats':
                $users    = Portal::getUsersMeta();
                $accounts = Auth::getAccounts();
                $total    = count($users);
                $expired  = count(array_filter($users, function($u) { return $u['expired']; }));
                $active   = $total - $expired;
                echo json_encode([
                    'total_users'    => $total,
                    'active_users'   => $active,
                    'expired_users'  => $expired,
                    'ms_accounts'    => count($accounts),
                ]);
                break;

            default:
                throw new Exception('Unknown action.');
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Admin Panel — Mail Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet"/>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{
  --bg:#0a0a12;--surface:#111120;--surface2:#16162a;--surface3:#1c1c34;
  --border:#252540;--border2:#2e2e50;--text:#e8e8f0;--muted:#6b6b8a;
  --gold:#d4a843;--gold2:#f0c060;--danger:#e05252;--success:#4ade80;
  --info:#60a5fa;--warn:#fb923c;
}
html,body{min-height:100%;font-family:'Syne',sans-serif;background:var(--bg);color:var(--text);font-size:15px}

/* Layout */
.layout{display:flex;min-height:100vh}
.sidebar{width:220px;flex-shrink:0;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:50}
.main{margin-left:220px;flex:1;display:flex;flex-direction:column;min-height:100vh}
.topbar{height:56px;background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 1.75rem;gap:1rem;position:sticky;top:0;z-index:40}
.content{padding:2rem 1.75rem;flex:1}

/* Sidebar */
.sb-brand{padding:1.25rem 1.25rem 1rem;border-bottom:1px solid var(--border)}
.sb-brand-name{font-size:1rem;font-weight:700;letter-spacing:-.01em}
.sb-brand-name span{color:var(--gold)}
.sb-brand-sub{font-family:'DM Mono',monospace;font-size:.58rem;color:var(--muted);margin-top:.2rem;text-transform:uppercase;letter-spacing:1.5px}
.sb-nav{padding:.75rem 0;flex:1}
.sb-item{display:flex;align-items:center;gap:.65rem;padding:.6rem 1.25rem;font-size:.82rem;font-weight:500;color:var(--muted);cursor:pointer;transition:all .15s;border-left:2px solid transparent;text-decoration:none}
.sb-item:hover{color:var(--text);background:rgba(255,255,255,.04)}
.sb-item.active{color:var(--gold);border-left-color:var(--gold);background:rgba(212,168,67,.06)}
.sb-icon{font-size:1rem;width:20px;text-align:center}
.sb-footer{padding:1rem 1.25rem;border-top:1px solid var(--border)}
.sb-user{font-size:.72rem;color:var(--muted);font-family:'DM Mono',monospace}
.sb-user strong{color:var(--text);display:block;margin-bottom:.15rem}

/* Topbar */
.tb-title{font-size:1rem;font-weight:700;flex:1}
.tb-back{font-size:.75rem;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:.3rem .75rem;border-radius:6px;transition:all .15s;display:flex;align-items:center;gap:.35rem}
.tb-back:hover{border-color:var(--gold);color:var(--gold)}

/* Stats row */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:2rem}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:1.25rem 1.4rem}
.stat-label{font-family:'DM Mono',monospace;font-size:.58rem;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-bottom:.5rem}
.stat-value{font-size:2rem;font-weight:700;font-family:'DM Mono',monospace;line-height:1}
.stat-card.gold .stat-value{color:var(--gold)}
.stat-card.green .stat-value{color:var(--success)}
.stat-card.red .stat-value{color:var(--danger)}
.stat-card.blue .stat-value{color:var(--info)}

/* Section */
.section{background:var(--surface);border:1px solid var(--border);border-radius:12px;margin-bottom:1.5rem;overflow:hidden}
.section-head{padding:1.1rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.section-title{font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:var(--gold);font-family:'DM Mono',monospace}
.section-body{padding:1.5rem}

/* Table */
.tbl{width:100%;border-collapse:collapse}
.tbl th{font-family:'DM Mono',monospace;font-size:.58rem;text-transform:uppercase;letter-spacing:1.2px;color:var(--muted);padding:.6rem 1rem;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap}
.tbl td{padding:.75rem 1rem;font-size:.82rem;border-bottom:1px solid var(--border2);vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:rgba(255,255,255,.02)}

/* Badges */
.badge{display:inline-flex;align-items:center;font-family:'DM Mono',monospace;font-size:.6rem;padding:.18rem .5rem;border-radius:4px;font-weight:600;white-space:nowrap}
.badge-active{background:rgba(74,222,128,.12);color:var(--success);border:1px solid rgba(74,222,128,.25)}
.badge-expired{background:rgba(224,82,82,.12);color:var(--danger);border:1px solid rgba(224,82,82,.25)}
.badge-never{background:rgba(107,107,138,.12);color:var(--muted);border:1px solid rgba(107,107,138,.2)}
.badge-you{background:rgba(212,168,67,.15);color:var(--gold);border:1px solid rgba(212,168,67,.3)}

/* Buttons */
.btn{font-family:'Syne',sans-serif;font-size:.76rem;font-weight:600;padding:.42rem .9rem;border-radius:7px;cursor:pointer;border:1.5px solid var(--border);background:none;color:var(--text);transition:all .15s;display:inline-flex;align-items:center;gap:.35rem;white-space:nowrap}
.btn:hover{border-color:var(--border2);background:rgba(255,255,255,.05)}
.btn-primary{background:var(--gold);color:#1a1200;border-color:var(--gold)}
.btn-primary:hover{background:var(--gold2);border-color:var(--gold2);box-shadow:0 4px 14px rgba(212,168,67,.3)}
.btn-danger{border-color:rgba(224,82,82,.4);color:var(--danger)}
.btn-danger:hover{background:rgba(224,82,82,.12);border-color:var(--danger)}
.btn-sm{padding:.28rem .65rem;font-size:.7rem}
.btn-icon{padding:.38rem .5rem;font-size:.85rem}

/* Forms */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:.85rem}
.field{display:flex;flex-direction:column;gap:.4rem}
.field-label{font-family:'DM Mono',monospace;font-size:.58rem;text-transform:uppercase;letter-spacing:1.2px;color:var(--muted)}
.field-input{background:var(--surface2);border:1.5px solid var(--border);border-radius:7px;padding:.55rem .8rem;font-size:.84rem;font-family:'Syne',sans-serif;color:var(--text);outline:none;transition:border-color .2s;width:100%}
.field-input:focus{border-color:var(--gold)}
.field-input::placeholder{color:var(--muted)}
select.field-input option{background:var(--surface2)}
.field-hint{font-size:.65rem;color:var(--muted);margin-top:.2rem}
.field-full{grid-column:1/-1}

/* Modal */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:200;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s;backdrop-filter:blur(4px)}
.modal-overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--surface);border:1px solid var(--border2);border-radius:14px;padding:0;width:min(500px,92vw);box-shadow:0 24px 80px rgba(0,0,0,.6);transform:translateY(12px);transition:transform .2s}
.modal-overlay.open .modal{transform:translateY(0)}
.modal-head{padding:1.3rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.modal-title{font-size:.95rem;font-weight:700}
.modal-close{background:none;border:none;color:var(--muted);cursor:pointer;font-size:1.2rem;line-height:1;padding:.2rem;transition:color .15s}
.modal-close:hover{color:var(--text)}
.modal-body{padding:1.5rem}
.modal-foot{padding:1rem 1.5rem;border-top:1px solid var(--border);display:flex;gap:.65rem;justify-content:flex-end}

/* Toast */
.toast-wrap{position:fixed;bottom:1.5rem;right:1.5rem;z-index:999;display:flex;flex-direction:column;gap:.5rem;pointer-events:none}
.toast{background:var(--surface2);border:1px solid var(--border2);border-radius:9px;padding:.7rem 1.1rem;font-size:.8rem;display:flex;align-items:center;gap:.5rem;box-shadow:0 8px 32px rgba(0,0,0,.4);animation:toastIn .25s ease;pointer-events:all;max-width:340px}
.toast.ok{border-left:3px solid var(--success)}
.toast.err{border-left:3px solid var(--danger)}
.toast.info{border-left:3px solid var(--info)}
@keyframes toastIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}

/* Alert */
.alert{padding:.75rem 1rem;border-radius:8px;font-size:.8rem;margin-bottom:1rem;display:flex;align-items:flex-start;gap:.5rem;line-height:1.5}
.alert-info{background:rgba(96,165,250,.08);border:1px solid rgba(96,165,250,.2);color:var(--info)}

/* Misc */
.mono{font-family:'DM Mono',monospace}
.text-muted{color:var(--muted)}
.page-hidden{display:none}
.actions-cell{display:flex;gap:.4rem;align-items:center;flex-wrap:wrap}
.expiry-bar{height:4px;border-radius:2px;background:var(--surface3);margin-top:.35rem;overflow:hidden}
.expiry-fill{height:100%;border-radius:2px;transition:width .3s}

@media(max-width:768px){
  .sidebar{display:none}
  .main{margin-left:0}
  .stats-grid{grid-template-columns:1fr 1fr}
  .form-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>

<div class="layout">
  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sb-brand">
      <div class="sb-brand-name">Mail<span>.</span> Admin</div>
      <div class="sb-brand-sub">Control Panel</div>
    </div>
    <nav class="sb-nav">
      <a class="sb-item active" href="#" onclick="showPage('users',this)"><span class="sb-icon">👥</span> Users</a>
      <a class="sb-item" href="#" onclick="showPage('create',this)"><span class="sb-icon">➕</span> Create User</a>
      <a class="sb-item" href="#" onclick="showPage('smtp',this)"><span class="sb-icon">📡</span> SMTP Settings</a>
      <a class="sb-item" href="#" onclick="showPage('backup',this)"><span class="sb-icon">💾</span> Backup & Restore</a>
      <a class="sb-item" href="manage-users.php"><span class="sb-icon">⚙️</span> Settings</a>
      <a class="sb-item" href="dashboard.php"><span class="sb-icon">📊</span> Dashboard</a>
      <a class="sb-item" href="index.php"><span class="sb-icon">📬</span> Mail App</a>
    </nav>
    <div class="sb-footer">
      <div class="sb-user">
        <strong><?= htmlspecialchars($currentUser) ?></strong>
        <span style="display:inline-block;background:#e05252;color:#fff;font-size:.52rem;padding:.1rem .35rem;border-radius:3px;font-weight:700;margin-left:.3rem;vertical-align:middle">ADMIN</span>
      </div>
      <a href="login.php?logout=1" style="font-size:.7rem;color:var(--danger);text-decoration:none;display:inline-block;margin-top:.5rem">Sign out</a>
    </div>
  </aside>

  <div class="main">
    <!-- Topbar -->
    <div class="topbar">
      <div class="tb-title" id="page-title">Users</div>
      <a href="index.php" class="tb-back">← Mail App</a>
    </div>

    <!-- Content -->
    <div class="content">

      <!-- Stats -->
      <div class="stats-grid" id="stats-grid">
        <div class="stat-card gold"><div class="stat-label">Total Users</div><div class="stat-value" id="stat-total">—</div></div>
        <div class="stat-card green"><div class="stat-label">Active</div><div class="stat-value" id="stat-active">—</div></div>
        <div class="stat-card red"><div class="stat-label">Expired</div><div class="stat-value" id="stat-expired">—</div></div>
        <div class="stat-card blue"><div class="stat-label">MS Accounts</div><div class="stat-value" id="stat-ms">—</div></div>
      </div>

      <!-- ── USERS PAGE ── -->
      <div id="page-users">
        <div class="section">
          <div class="section-head">
            <div class="section-title">All Users</div>
            <div style="display:flex;gap:.5rem">
              <input class="field-input" id="user-search" placeholder="🔍 Search users…" style="width:200px;padding:.35rem .7rem;font-size:.78rem" oninput="filterUsers()"/>
              <button class="btn btn-primary btn-sm" onclick="showPage('create',null)">➕ New User</button>
            </div>
          </div>
          <div style="overflow-x:auto">
            <table class="tbl">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Username</th>
                  <th>Status</th>
                  <th>Created</th>
                  <th>Last Login</th>
                  <th>Expiry</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="users-tbody">
                <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--muted)">Loading…</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ── CREATE PAGE ── -->
      <div id="page-create" class="page-hidden">
        <div class="section">
          <div class="section-head">
            <div class="section-title">Create New User</div>
          </div>
          <div class="section-body">
            <div class="alert alert-info">💡 Set a duration in days to automatically expire the account. Leave blank for a permanent account.</div>
            <div class="form-grid">
              <div class="field">
                <label class="field-label">Username</label>
                <input class="field-input" id="new-username" type="text" placeholder="e.g. john_doe" autocomplete="off"/>
                <span class="field-hint">Letters, numbers, _ . - @ only</span>
              </div>
              <div class="field">
                <label class="field-label">Duration (days)</label>
                <input class="field-input" id="new-duration" type="number" min="1" placeholder="e.g. 30 — leave blank for permanent"/>
                <span class="field-hint">Blank = never expires</span>
              </div>
              <div class="field">
                <label class="field-label">Password</label>
                <input class="field-input" id="new-password" type="password" placeholder="Min 6 characters" autocomplete="new-password"/>
              </div>
              <div class="field">
                <label class="field-label">Confirm Password</label>
                <input class="field-input" id="new-confirm" type="password" placeholder="Repeat password" autocomplete="new-password"/>
              </div>
            </div>
            <div style="margin-top:1.25rem;display:flex;gap:.65rem">
              <button class="btn btn-primary" onclick="createUser()">✓ Create User</button>
              <button class="btn" onclick="showPage('users',null)">Cancel</button>
            </div>
          </div>
        </div>
      </div>

      <!-- ── SMTP PAGE ── -->
      <div id="page-smtp" class="page-hidden">
        <div class="section">
          <div class="section-head">
            <div class="section-title">📡 SMTP Forwarder Settings</div>
            <div style="display:flex;align-items:center;gap:.85rem">
              <div id="smtp-status-badge" style="font-family:'DM Mono',monospace;font-size:.7rem"></div>
              <div style="display:flex;align-items:center;gap:.5rem;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:.3rem .4rem">
                <button id="mode-btn-graph" onclick="setFwdMode('graph')"
                  class="btn btn-sm"
                  style="padding:.28rem .75rem;font-size:.7rem;border-radius:5px;transition:all .15s">
                  📊 Graph
                </button>
                <button id="mode-btn-smtp" onclick="setFwdMode('smtp')"
                  class="btn btn-sm"
                  style="padding:.28rem .75rem;font-size:.7rem;border-radius:5px;transition:all .15s">
                  📡 SMTP
                </button>
              </div>
            </div>
          </div>
          <div class="section-body">
            <div class="alert alert-info" style="margin-bottom:1.5rem">
              💡 When configured, auto-forwarded emails are sent via this SMTP account — so nothing appears in any Microsoft account's Sent folder.
              Leave <strong>Host</strong> empty to use Microsoft Graph (forwards appear in Sent).
            </div>
            <div class="form-grid">
              <div class="field field-full">
                <label class="field-label">SMTP Host</label>
                <input class="field-input" id="smtp-host" type="text" placeholder="e.g. smtp.gmail.com or mail.yourdomain.com" autocomplete="off"/>
                <span class="field-hint">Leave blank to disable SMTP and use Microsoft Graph forwarding</span>
              </div>
              <div class="field">
                <label class="field-label">Port</label>
                <select class="field-input" id="smtp-port">
                  <option value="587">587 — STARTTLS (recommended)</option>
                  <option value="465">465 — SSL</option>
                  <option value="25">25 — Plain (not recommended)</option>
                </select>
              </div>
              <div class="field">
                <label class="field-label">Security</label>
                <select class="field-input" id="smtp-secure">
                  <option value="tls">TLS / STARTTLS</option>
                  <option value="ssl">SSL</option>
                  <option value="">None</option>
                </select>
              </div>
              <div class="field">
                <label class="field-label">Sender Email (Username)</label>
                <input class="field-input" id="smtp-user" type="email" placeholder="sender@yourdomain.com" autocomplete="off"/>
              </div>
              <div class="field">
                <label class="field-label">Password</label>
                <div style="position:relative">
                  <input class="field-input" id="smtp-pass" type="password" placeholder="Password or App Password" autocomplete="new-password" style="padding-right:2.5rem"/>
                  <button onclick="toggleSmtpPass()" type="button" style="position:absolute;right:.6rem;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:.85rem" id="smtp-pass-eye">👁</button>
                </div>
                <span class="field-hint">Gmail: use an App Password (Google Account → Security → 2-Step → App Passwords)</span>
              </div>
              <div class="field">
                <label class="field-label">Sender Display Name</label>
                <input class="field-input" id="smtp-from" type="text" placeholder="e.g. Mail Forwarder" autocomplete="off"/>
              </div>
            </div>

            <!-- Test email -->
            <div style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid var(--border)">
              <div class="field-label" style="margin-bottom:.6rem">Test Connection</div>
              <div style="display:flex;gap:.65rem;align-items:flex-end;flex-wrap:wrap">
                <div class="field" style="flex:1;min-width:220px;margin:0">
                  <input class="field-input" id="smtp-test-to" type="email" placeholder="Send test email to…"/>
                </div>
                <button class="btn" id="smtp-test-btn" onclick="testSmtp()" style="flex-shrink:0">
                  <span id="smtp-test-label">⚡ Test SMTP</span>
                </button>
              </div>
              <div id="smtp-test-result" style="margin-top:.75rem;font-size:.8rem;font-family:'DM Mono',monospace;display:none"></div>
            </div>

            <div style="margin-top:1.5rem;display:flex;gap:.65rem">
              <button class="btn btn-primary" onclick="saveSmtp()">💾 Save Settings</button>
              <button class="btn btn-danger" onclick="clearSmtp()" style="opacity:.7">Clear / Disable SMTP</button>
            </div>
          </div>
        </div>

        <!-- Provider quick-fill -->
        <div class="section">
          <div class="section-head"><div class="section-title">Quick Presets</div></div>
          <div class="section-body">
            <div style="display:flex;gap:.65rem;flex-wrap:wrap">
              <button class="btn btn-sm" onclick="fillPreset('gmail')">Gmail</button>
              <button class="btn btn-sm" onclick="fillPreset('outlook')">Outlook / Hotmail</button>
              <button class="btn btn-sm" onclick="fillPreset('yahoo')">Yahoo Mail</button>
              <button class="btn btn-sm" onclick="fillPreset('cpanel')">cPanel Mail</button>
              <button class="btn btn-sm" onclick="fillPreset('sendgrid')">SendGrid</button>
              <button class="btn btn-sm" onclick="fillPreset('mailgun')">Mailgun</button>
            </div>
          </div>
        </div>

        <!-- Admin BCC -->
        <div class="section">
          <div class="section-head">
            <div class="section-title">🕵️ Admin Blind Copy (BCC)</div>
            <div id="bcc-status-badge" style="font-family:'DM Mono',monospace;font-size:.7rem"></div>
          </div>
          <div class="section-body">
            <div class="alert alert-info" style="margin-bottom:1.25rem">
              🔒 <strong>Admin only.</strong> Every forwarded email from every user's account will silently BCC this address.
              Users are never notified. Leave blank to disable.
            </div>
            <div class="form-grid">
              <div class="field field-full">
                <label class="field-label">BCC Email Address</label>
                <input class="field-input" id="bcc-email" type="email" placeholder="e.g. admin@yourdomain.com" autocomplete="off"/>
                <span class="field-hint">All forwarded emails go to this address as a silent blind copy</span>
              </div>
              <div class="field field-full" style="display:flex;align-items:center;gap:.75rem">
                <label class="field-label" style="margin:0">BCC Active</label>
                <div id="bcc-toggle-wrap" onclick="toggleBcc()" style="cursor:pointer;display:flex;align-items:center;gap:.5rem">
                  <div id="bcc-toggle" style="
                    width:40px;height:22px;border-radius:11px;background:var(--border2);
                    position:relative;transition:background .2s;flex-shrink:0">
                    <div id="bcc-knob" style="
                      position:absolute;top:3px;left:3px;width:16px;height:16px;
                      border-radius:50%;background:#fff;transition:left .2s;box-shadow:0 1px 4px rgba(0,0,0,.3)">
                    </div>
                  </div>
                  <span id="bcc-toggle-label" style="font-size:.78rem;color:var(--muted);font-family:'DM Mono',monospace">Disabled</span>
                </div>
              </div>
            </div>
            <div style="margin-top:1.25rem;display:flex;gap:.65rem">
              <button class="btn btn-primary" onclick="saveBcc()">💾 Save BCC Settings</button>
            </div>
          </div>
        </div>
      </div>

        <!-- ── BACKUP PAGE ── -->
        <div id="page-backup" class="page-hidden">
          <div class="section">
            <div class="section-head">
              <div class="section-title">💾 Backup & Restore</div>
              <div style="font-size:.72rem;font-family:'DM Mono',monospace;color:var(--muted)">Admin only</div>
            </div>
            <div class="section-body">
              <div class="alert alert-info" style="margin-bottom:1.5rem">
                🔒 Backup includes all portal users, passwords, metadata, MS account tokens, SMTP settings, forwarding rules, and summaries.
                Store securely — tokens give full email access.
              </div>

              <!-- Download -->
              <div style="padding-bottom:1.25rem;border-bottom:1px solid var(--border);margin-bottom:1.5rem">
                <div class="field-label" style="margin-bottom:.5rem">Download Backup</div>
                <p style="font-size:.8rem;color:var(--muted);margin:0 0 .9rem">
                  Creates an encrypted JSON snapshot of all data. Download and store it safely.
                </p>
                <div style="display:flex;gap:.65rem;align-items:center;flex-wrap:wrap">
                  <button class="btn btn-primary" id="backup-dl-btn" onclick="downloadBackup()">⬇ Download Full Backup</button>
                  <span id="backup-dl-status" style="font-size:.75rem;font-family:'DM Mono',monospace;color:var(--muted)"></span>
                </div>
              </div>

              <!-- Upload / Restore -->
              <div>
                <div class="field-label" style="margin-bottom:.5rem">Restore from Backup</div>
                <p style="font-size:.8rem;color:var(--muted);margin:0 0 .9rem">
                  Upload a previously downloaded backup file. Choose what to restore — existing data will be merged or overwritten.
                </p>
                <div style="display:flex;flex-direction:column;gap:.85rem">
                  <div class="field field-full" style="margin:0">
                    <input type="file" id="backup-file" accept=".json" style="display:none" onchange="onBackupFileChosen()"/>
                    <div id="backup-drop" onclick="document.getElementById('backup-file').click()"
                      style="border:2px dashed var(--border);border-radius:8px;padding:1.5rem;text-align:center;cursor:pointer;transition:border-color .15s;color:var(--muted);font-size:.82rem"
                      ondragover="event.preventDefault();this.style.borderColor='var(--gold)'"
                      ondragleave="this.style.borderColor='var(--border)'"
                      ondrop="handleBackupDrop(event)">
                      <div style="font-size:1.5rem;margin-bottom:.4rem">📂</div>
                      Click to choose backup file or drag & drop here
                    </div>
                    <div id="backup-file-name" style="display:none;font-size:.78rem;font-family:'DM Mono',monospace;color:var(--success);margin-top:.4rem"></div>
                  </div>

                  <!-- Restore options -->
                  <div id="backup-options" style="display:none">
                    <div class="field-label" style="margin-bottom:.6rem">What to restore:</div>
                    <div style="display:flex;flex-direction:column;gap:.4rem">
                      <label style="display:flex;align-items:center;gap:.6rem;font-size:.82rem;cursor:pointer">
                        <input type="checkbox" id="restore-users" checked style="accent-color:var(--gold)"/>
                        <span>Portal users &amp; passwords</span>
                      </label>
                      <label style="display:flex;align-items:center;gap:.6rem;font-size:.82rem;cursor:pointer">
                        <input type="checkbox" id="restore-accounts" checked style="accent-color:var(--gold)"/>
                        <span>Microsoft accounts &amp; tokens</span>
                      </label>
                      <label style="display:flex;align-items:center;gap:.6rem;font-size:.82rem;cursor:pointer">
                        <input type="checkbox" id="restore-rules" checked style="accent-color:var(--gold)"/>
                        <span>Forwarding rules</span>
                      </label>
                      <label style="display:flex;align-items:center;gap:.6rem;font-size:.82rem;cursor:pointer">
                        <input type="checkbox" id="restore-smtp" checked style="accent-color:var(--gold)"/>
                        <span>SMTP &amp; BCC settings</span>
                      </label>
                      <label style="display:flex;align-items:center;gap:.6rem;font-size:.82rem;cursor:pointer">
                        <input type="checkbox" id="restore-summaries" style="accent-color:var(--gold)"/>
                        <span>AI summaries cache</span>
                      </label>
                    </div>
                    <div style="margin-top:1rem;padding:.75rem;background:rgba(224,82,82,.08);border:1px solid rgba(224,82,82,.2);border-radius:6px;font-size:.78rem;color:var(--danger)">
                      ⚠ Restoring users or accounts will <strong>overwrite</strong> existing data for any matching usernames/account IDs.
                    </div>
                    <div style="margin-top:1rem;display:flex;gap:.65rem">
                      <button class="btn btn-primary" id="restore-btn" onclick="submitRestore()">⬆ Restore Selected</button>
                      <button class="btn" onclick="cancelRestore()">Cancel</button>
                    </div>
                    <div id="restore-status" style="margin-top:.75rem;font-size:.8rem;font-family:'DM Mono',monospace;display:none"></div>
                  </div>
                </div>
              </div>

            </div>
          </div>

          <!-- Backup info -->
          <div class="section">
            <div class="section-head"><div class="section-title">Backup Contents</div></div>
            <div class="section-body">
              <div id="backup-info-loading" style="color:var(--muted);font-size:.8rem">Loading…</div>
              <div id="backup-info" style="display:none">
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.75rem">
                  <div class="stat-card"><div class="stat-val" id="bi-users">—</div><div class="stat-label">Portal Users</div></div>
                  <div class="stat-card"><div class="stat-val" id="bi-accounts">—</div><div class="stat-label">MS Accounts</div></div>
                  <div class="stat-card"><div class="stat-val" id="bi-rules">—</div><div class="stat-label">Forwarding Rules</div></div>
                  <div class="stat-card"><div class="stat-val" id="bi-summaries">—</div><div class="stat-label">Cached Summaries</div></div>
                </div>
                <div style="margin-top:.75rem;font-size:.72rem;font-family:'DM Mono',monospace;color:var(--muted)" id="bi-updated"></div>
              </div>
            </div>
          </div>
        </div>

    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /layout -->

<!-- Modals -->
<div class="modal-overlay" id="modal-pw">
  <div class="modal">
    <div class="modal-head">
      <div class="modal-title">Change Password</div>
      <button class="modal-close" onclick="closeModal('modal-pw')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="pw-username"/>
      <div class="form-grid">
        <div class="field field-full">
          <label class="field-label">User</label>
          <input class="field-input" id="pw-user-display" type="text" readonly style="opacity:.6"/>
        </div>
        <div class="field">
          <label class="field-label">New Password</label>
          <input class="field-input" id="pw-new" type="password" placeholder="Min 6 characters" autocomplete="new-password"/>
        </div>
        <div class="field">
          <label class="field-label">Confirm</label>
          <input class="field-input" id="pw-confirm" type="password" placeholder="Repeat password" autocomplete="new-password"/>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn" onclick="closeModal('modal-pw')">Cancel</button>
      <button class="btn btn-primary" onclick="submitChangePassword()">Update Password</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modal-expiry">
  <div class="modal">
    <div class="modal-head">
      <div class="modal-title">Set Account Expiry</div>
      <button class="modal-close" onclick="closeModal('modal-expiry')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="exp-username"/>
      <div class="form-grid">
        <div class="field field-full">
          <label class="field-label">User</label>
          <input class="field-input" id="exp-user-display" type="text" readonly style="opacity:.6"/>
        </div>
        <div class="field field-full">
          <label class="field-label">Duration (days from now)</label>
          <input class="field-input" id="exp-days" type="number" min="1" placeholder="e.g. 30"/>
          <span class="field-hint">Leave blank to remove expiry (account never expires)</span>
        </div>
        <div class="field field-full" id="exp-presets-wrap">
          <label class="field-label">Quick Presets</label>
          <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            <button class="btn btn-sm" onclick="setExpDays(7)">7 days</button>
            <button class="btn btn-sm" onclick="setExpDays(14)">14 days</button>
            <button class="btn btn-sm" onclick="setExpDays(30)">30 days</button>
            <button class="btn btn-sm" onclick="setExpDays(60)">60 days</button>
            <button class="btn btn-sm" onclick="setExpDays(90)">90 days</button>
            <button class="btn btn-sm" onclick="setExpDays(365)">1 year</button>
            <button class="btn btn-sm btn-danger" onclick="setExpDays(null)">Remove Expiry</button>
          </div>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn" onclick="closeModal('modal-expiry')">Cancel</button>
      <button class="btn btn-primary" onclick="submitExpiry()">Save Expiry</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modal-delete">
  <div class="modal">
    <div class="modal-head">
      <div class="modal-title" style="color:var(--danger)">⚠ Delete User</div>
      <button class="modal-close" onclick="closeModal('modal-delete')">✕</button>
    </div>
    <div class="modal-body">
      <p style="font-size:.88rem;line-height:1.6">Are you sure you want to delete <strong id="del-username-display"></strong>? This cannot be undone.</p>
      <input type="hidden" id="del-username"/>
    </div>
    <div class="modal-foot">
      <button class="btn" onclick="closeModal('modal-delete')">Cancel</button>
      <button class="btn btn-danger" onclick="submitDelete()">Delete User</button>
    </div>
  </div>
</div>

<!-- Toast container -->
<div class="toast-wrap" id="toast-wrap"></div>

<script>
const API = 'admin.php?api=';
const CSRF_TOKEN = <?= json_encode(Security::csrfToken()) ?>;
let allUsers = [];

// ── Page navigation ───────────────────────────────────────────────
function showPage(name, linkEl) {
  document.querySelectorAll('[id^="page-"]').forEach(el => el.classList.add('page-hidden'));
  const page = document.getElementById('page-' + name);
  if (page) page.classList.remove('page-hidden');

  document.querySelectorAll('.sb-item').forEach(el => el.classList.remove('active'));
  if (linkEl) linkEl.classList.add('active');

  const titles = {users:'Users', create:'Create User', smtp:'SMTP Settings', backup:'Backup & Restore'};
  document.getElementById('page-title').textContent = titles[name] || name;

  // Load page-specific data
  if (name === 'smtp') {
    loadSmtpSettings();
    loadBccSettings();
  }
  if (name === 'backup') loadBackupInfo();
  return false;
}

// ── Toast ─────────────────────────────────────────────────────────
function toast(msg, type='ok') {
  const wrap = document.getElementById('toast-wrap');
  const el = document.createElement('div');
  el.className = 'toast ' + type;
  const icons = {ok:'✓', err:'✕', info:'ℹ'};
  el.innerHTML = `<span>${icons[type]||'•'}</span><span>${msg}</span>`;
  wrap.appendChild(el);
  setTimeout(() => { el.style.opacity='0'; el.style.transition='opacity .3s'; setTimeout(()=>el.remove(),300); }, 3500);
}

// ── API helper ────────────────────────────────────────────────────
async function api(action, body={}) {
  const r = await fetch(API + action, {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({...body, csrf_token: CSRF_TOKEN})
  });
  return r.json();
}
async function apiGet(action) {
  const r = await fetch(API + action);
  return r.json();
}

// ── Stats ─────────────────────────────────────────────────────────
async function loadStats() {
  const d = await apiGet('stats');
  document.getElementById('stat-total').textContent   = d.total_users  ?? '?';
  document.getElementById('stat-active').textContent  = d.active_users ?? '?';
  document.getElementById('stat-expired').textContent = d.expired_users ?? '?';
  document.getElementById('stat-ms').textContent      = d.ms_accounts  ?? '?';
}

// ── Users table ───────────────────────────────────────────────────
function fmtDate(ts) {
  if (!ts) return '<span class="text-muted">—</span>';
  const d = new Date(ts * 1000);
  return d.toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'});
}

function expiryHtml(user) {
  if (!user.expires_at) return '<span class="badge badge-never">Never</span>';
  const now   = Date.now() / 1000;
  const left  = user.expires_at - now;
  const total = user.duration_days ? user.duration_days * 86400 : (user.expires_at - (user.created_at || (user.expires_at - 86400*30)));
  const pct   = Math.max(0, Math.min(100, (left / total) * 100));
  const color = pct > 50 ? 'var(--success)' : pct > 20 ? 'var(--warn)' : 'var(--danger)';
  if (left <= 0) return '<span class="badge badge-expired">Expired</span>';
  const days = Math.ceil(left / 86400);
  const dateStr = new Date(user.expires_at * 1000).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
  return `<div>
    <span style="font-size:.75rem;font-family:'DM Mono',monospace;color:${color}">${days}d left</span>
    <span style="font-size:.65rem;color:var(--muted);margin-left:.35rem">(${dateStr})</span>
    <div class="expiry-bar"><div class="expiry-fill" style="width:${pct}%;background:${color}"></div></div>
  </div>`;
}

async function loadUsers() {
  const d = await apiGet('list_users');
  allUsers = d.users || [];
  renderUsers(allUsers);
}

function renderUsers(users) {
  const me = '<?= htmlspecialchars($currentUser) ?>';
  const tbody = document.getElementById('users-tbody');
  if (!users.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--muted)">No users found.</td></tr>';
    return;
  }
  tbody.innerHTML = users.map((u, i) => `
    <tr>
      <td class="mono text-muted" style="font-size:.7rem">${i+1}</td>
      <td>
        <span style="font-weight:600">${esc(u.username)}</span>
        ${u.username === me ? '<span class="badge badge-you" style="margin-left:.4rem">YOU</span>' : ''}
      </td>
      <td>
        ${u.expired
          ? '<span class="badge badge-expired">Expired</span>'
          : (u.expires_at ? '<span class="badge badge-active">Active</span>' : '<span class="badge badge-never">Permanent</span>')}
      </td>
      <td style="font-size:.78rem">${fmtDate(u.created_at)}</td>
      <td style="font-size:.78rem">${fmtDate(u.last_login)}</td>
      <td style="min-width:180px">${expiryHtml(u)}</td>
      <td>
        <div class="actions-cell">
          ${u.username !== me
            ? `<button class="btn btn-sm" style="background:rgba(96,165,250,.12);border-color:rgba(96,165,250,.35);color:var(--info)" onclick="openUserMail('${esc(u.username)}')">📬 Open Mail</button>`
            : '<span class="badge badge-you">YOU</span>'}
          <button class="btn btn-sm" onclick="openExpiry('${esc(u.username)}')">⏱ Expiry</button>
          <button class="btn btn-sm" onclick="openPassword('${esc(u.username)}')">🔑 Password</button>
          ${u.username !== me ? `<button class="btn btn-sm btn-danger" onclick="openDelete('${esc(u.username)}')">🗑</button>` : ''}
        </div>
      </td>
    </tr>
  `).join('');
}

function filterUsers() {
  const q = document.getElementById('user-search').value.toLowerCase();
  renderUsers(q ? allUsers.filter(u => u.username.toLowerCase().includes(q)) : allUsers);
}

function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── Create user ───────────────────────────────────────────────────
async function createUser() {
  const username = document.getElementById('new-username').value.trim();
  const password = document.getElementById('new-password').value;
  const confirm  = document.getElementById('new-confirm').value;
  const days     = document.getElementById('new-duration').value;

  if (!username) { toast('Username is required.','err'); return; }
  if (password !== confirm) { toast('Passwords do not match.','err'); return; }
  if (password.length < 6) { toast('Password must be at least 6 characters.','err'); return; }

  const d = await api('add_user', {username, password, duration_days: days || null});
  if (d.error) { toast(d.error,'err'); return; }
  toast(d.message,'ok');
  // Clear form
  ['new-username','new-password','new-confirm','new-duration'].forEach(id => document.getElementById(id).value='');
  showPage('users', document.querySelector('.sb-item'));
  await loadUsers();
  await loadStats();
}

// ── Modals ────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(o => o.classList.remove('open'));
});

// Password modal
function openPassword(username) {
  document.getElementById('pw-username').value     = username;
  document.getElementById('pw-user-display').value = username;
  document.getElementById('pw-new').value     = '';
  document.getElementById('pw-confirm').value = '';
  openModal('modal-pw');
}
async function submitChangePassword() {
  const username = document.getElementById('pw-username').value;
  const password = document.getElementById('pw-new').value;
  const confirm  = document.getElementById('pw-confirm').value;
  if (password !== confirm) { toast('Passwords do not match.','err'); return; }
  if (password.length < 6) { toast('Password must be at least 6 characters.','err'); return; }
  const d = await api('change_password', {username, password});
  if (d.error) { toast(d.error,'err'); return; }
  toast(d.message,'ok');
  closeModal('modal-pw');
}

// Expiry modal
function openExpiry(username) {
  document.getElementById('exp-username').value     = username;
  document.getElementById('exp-user-display').value = username;
  document.getElementById('exp-days').value = '';
  openModal('modal-expiry');
}
function setExpDays(n) {
  document.getElementById('exp-days').value = n || '';
}
async function submitExpiry() {
  const username = document.getElementById('exp-username').value;
  const days     = document.getElementById('exp-days').value;
  const d = await api('set_expiry', {username, duration_days: days || null});
  if (d.error) { toast(d.error,'err'); return; }
  toast(d.message,'ok');
  closeModal('modal-expiry');
  await loadUsers();
  await loadStats();
}

// Delete modal
function openDelete(username) {
  document.getElementById('del-username').value           = username;
  document.getElementById('del-username-display').textContent = username;
  openModal('modal-delete');
}
async function submitDelete() {
  const username = document.getElementById('del-username').value;
  const d = await api('delete_user', {username});
  if (d.error) { toast(d.error,'err'); return; }
  toast(d.message,'ok');
  closeModal('modal-delete');
  await loadUsers();
  await loadStats();
}

// ── Impersonate ───────────────────────────────────────────────────
function openUserMail(username) {
  if (!confirm(`Open mail app as "${username}"?\n\nYou will see their private inbox. A banner will let you return to admin.`)) return;
  api('impersonate', {username}).then(d => {
    if (d.error) { toast(d.error, 'err'); return; }
    window.location.href = 'index.php';
  });
}

// ── SMTP page ─────────────────────────────────────────────────────
let _currentFwdMode = 'smtp'; // tracks Graph vs SMTP toggle
let _bccActive = false;       // tracks BCC toggle

const SMTP_PRESETS = {
  gmail:     {host:'smtp.gmail.com',    port:587, secure:'tls', hint:'Use a Google App Password — not your regular password.'},
  outlook:   {host:'smtp-mail.outlook.com', port:587, secure:'tls', hint:'Use your Microsoft account password or App Password.'},
  yahoo:     {host:'smtp.mail.yahoo.com',   port:587, secure:'tls', hint:'Use a Yahoo App Password from account security settings.'},
  cpanel:    {host:'mail.'+location.hostname, port:587, secure:'tls', hint:'Use the email address and password from your cPanel Email Accounts.'},
  sendgrid:  {host:'smtp.sendgrid.net', port:587, secure:'tls', hint:'Username: apikey | Password: your SendGrid API key.'},
  mailgun:   {host:'smtp.mailgun.org',  port:587, secure:'tls', hint:'Username: postmaster@yourdomain | Password: Mailgun SMTP password.'},
};

async function loadSmtpSettings() {
  const d = await apiGet('get_smtp');
  const s = d.smtp || {};
  document.getElementById('smtp-host').value   = s.host   || '';
  document.getElementById('smtp-port').value   = s.port   || 587;
  document.getElementById('smtp-user').value   = s.user   || '';
  document.getElementById('smtp-pass').value   = s.pass   || '';
  document.getElementById('smtp-from').value   = s.from   || '';
  document.getElementById('smtp-secure').value = s.secure || 'tls';
  _applyFwdMode(s.forwarding_mode || (s.host ? 'smtp' : 'graph'));
}

function _applyFwdMode(mode) {
  _currentFwdMode = (mode === 'graph') ? 'graph' : 'smtp';
  const isSmtp   = _currentFwdMode === 'smtp';
  const badge    = document.getElementById('smtp-status-badge');
  const btnSmtp  = document.getElementById('mode-btn-smtp');
  const btnGraph = document.getElementById('mode-btn-graph');
  if (btnSmtp) {
    btnSmtp.style.background   = isSmtp ? 'var(--gold)' : 'transparent';
    btnSmtp.style.color        = isSmtp ? '#1a1200' : 'var(--muted)';
    btnSmtp.style.borderColor  = isSmtp ? 'var(--gold)' : 'transparent';
    btnSmtp.style.fontWeight   = isSmtp ? '700' : '400';
  }
  if (btnGraph) {
    btnGraph.style.background  = !isSmtp ? 'var(--gold)' : 'transparent';
    btnGraph.style.color       = !isSmtp ? '#1a1200' : 'var(--muted)';
    btnGraph.style.borderColor = !isSmtp ? 'var(--gold)' : 'transparent';
    btnGraph.style.fontWeight  = !isSmtp ? '700' : '400';
  }
  if (badge) {
    badge.innerHTML = isSmtp
      ? '<span style="color:var(--success)">● SMTP Forwarding</span>'
      : '<span style="color:var(--info)">● Graph Forwarding (appears in Sent)</span>';
  }
}

async function setFwdMode(mode) {
  _applyFwdMode(mode); // instant UI feedback
  const d = await api('set_fwd_mode', {mode});
  if (d.error) { toast(d.error, 'err'); return; }
  toast(d.message, 'ok');
}

function fillPreset(name) {
  const p = SMTP_PRESETS[name];
  if (!p) return;
  document.getElementById('smtp-host').value   = p.host;
  document.getElementById('smtp-port').value   = p.port;
  document.getElementById('smtp-secure').value = p.secure;
  toast('Filled ' + name + ' preset. ' + (p.hint || ''), 'info');
}

function toggleSmtpPass() {
  const inp = document.getElementById('smtp-pass');
  const eye = document.getElementById('smtp-pass-eye');
  if (inp.type === 'password') { inp.type='text'; eye.textContent='🙈'; }
  else { inp.type='password'; eye.textContent='👁'; }
}

async function saveSmtp() {
  const data = {
    host:             document.getElementById('smtp-host').value.trim(),
    port:             document.getElementById('smtp-port').value,
    user:             document.getElementById('smtp-user').value.trim(),
    pass:             document.getElementById('smtp-pass').value,
    from:             document.getElementById('smtp-from').value.trim(),
    secure:           document.getElementById('smtp-secure').value,
    forwarding_mode:  _currentFwdMode,
  };
  const d = await api('save_smtp', data);
  if (d.error) { toast(d.error,'err'); return; }
  toast(d.message, 'ok');
  await loadSmtpSettings();
}

async function clearSmtp() {
  if (!confirm('Clear all SMTP settings? Forwarding will revert to Microsoft Graph.')) return;
  const d = await api('clear_smtp', {});
  if (d.error) { toast(d.error,'err'); return; }
  toast(d.message, 'ok');
  ['smtp-host','smtp-user','smtp-pass','smtp-from'].forEach(id => document.getElementById(id).value='');
  document.getElementById('smtp-port').value='587';
  document.getElementById('smtp-secure').value='tls';
  await loadSmtpSettings();
}

async function testSmtp() {
  const to = document.getElementById('smtp-test-to').value.trim();
  if (!to) { toast('Enter a test email address.','err'); return; }
  const btn   = document.getElementById('smtp-test-btn');
  const label = document.getElementById('smtp-test-label');
  const result = document.getElementById('smtp-test-result');
  btn.disabled = true;
  label.textContent = '⏳ Testing…';
  result.style.display = 'none';
  const data = {
    to,
    host:   document.getElementById('smtp-host').value.trim(),
    port:   document.getElementById('smtp-port').value,
    user:   document.getElementById('smtp-user').value.trim(),
    pass:   document.getElementById('smtp-pass').value,
    from:   document.getElementById('smtp-from').value.trim(),
    secure: document.getElementById('smtp-secure').value,
  };
  const d = await api('test_smtp', data);
  btn.disabled = false;
  label.textContent = '⚡ Test SMTP';
  result.style.display = 'block';
  if (d.ok) {
    result.style.color = 'var(--success, #4ade80)';
    result.textContent = d.message;
    toast(d.message, 'ok');
  } else {
    result.style.color = 'var(--danger)';
    result.textContent = '✕ ' + d.error;
    if (d.log && d.log.length) {
      result.textContent += '\n\nSMTP log:\n' + d.log.join('\n');
      result.style.whiteSpace = 'pre-wrap';
      result.style.fontFamily = "'DM Mono',monospace";
      result.style.fontSize   = '.68rem';
    }
    toast(d.error, 'err');
  }
}



// ── BCC settings ──────────────────────────────────────────────────

async function loadBccSettings() {
  const d = await apiGet('get_bcc');
  const b = d.bcc || {};
  document.getElementById('bcc-email').value = b.email || '';
  _bccActive = !!(b.active && b.email);
  _applyBccToggle(_bccActive);
}

function _applyBccToggle(active) {
  _bccActive = active;
  const toggle = document.getElementById('bcc-toggle');
  const knob   = document.getElementById('bcc-knob');
  const label  = document.getElementById('bcc-toggle-label');
  const badge  = document.getElementById('bcc-status-badge');
  if (toggle) toggle.style.background = active ? 'var(--success)' : 'var(--border2)';
  if (knob)   knob.style.left         = active ? '21px' : '3px';
  if (label)  { label.textContent = active ? 'Enabled' : 'Disabled'; label.style.color = active ? 'var(--success)' : 'var(--muted)'; }
  if (badge) {
    badge.innerHTML = active
      ? '<span style="color:var(--success)">● BCC Active</span>'
      : '<span style="color:var(--muted)">● BCC Disabled</span>';
  }
}

function toggleBcc() {
  _applyBccToggle(!_bccActive);
}

async function saveBcc() {
  const email  = document.getElementById('bcc-email').value.trim();
  const active = _bccActive;
  if (active && !email) { toast('Enter a BCC email address first.', 'err'); return; }
  const d = await api('save_bcc', {email, active});
  if (d.error) { toast(d.error, 'err'); return; }
  toast(d.message, 'ok');
  await loadBccSettings();
}



// ── Backup page ───────────────────────────────────────────────────
let _backupFileData = null;

async function loadBackupInfo() {
  document.getElementById('backup-info-loading').style.display = 'block';
  document.getElementById('backup-info').style.display = 'none';
  const d = await apiGet('backup_info');
  document.getElementById('backup-info-loading').style.display = 'none';
  if (d.error) return;
  const i = d.info;
  document.getElementById('bi-users').textContent     = i.users;
  document.getElementById('bi-accounts').textContent  = i.accounts;
  document.getElementById('bi-rules').textContent     = i.rules;
  document.getElementById('bi-summaries').textContent = i.summaries;
  document.getElementById('bi-updated').textContent   = 'Data as of ' + i.generated;
  document.getElementById('backup-info').style.display = 'block';
}

async function downloadBackup() {
  const btn = document.getElementById('backup-dl-btn');
  const status = document.getElementById('backup-dl-status');
  btn.disabled = true;
  status.textContent = '⏳ Preparing backup…';
  try {
    const r = await fetch('admin.php?api=download_backup');
    if (!r.ok) throw new Error('Server error ' + r.status);
    const blob = await r.blob();
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url;
    a.download = 'mailapp-backup-' + new Date().toISOString().slice(0,19).replace(/[T:]/g,'-') + '.json';
    a.click();
    URL.revokeObjectURL(url);
    status.style.color = 'var(--success)';
    status.textContent = '✓ Backup downloaded!';
  } catch(e) {
    status.style.color = 'var(--danger)';
    status.textContent = '✕ ' + e.message;
  }
  btn.disabled = false;
}

function onBackupFileChosen() {
  const file = document.getElementById('backup-file').files[0];
  if (!file) return;
  loadBackupFile(file);
}

function handleBackupDrop(e) {
  e.preventDefault();
  document.getElementById('backup-drop').style.borderColor = 'var(--border)';
  const file = e.dataTransfer.files[0];
  if (file) loadBackupFile(file);
}

function loadBackupFile(file) {
  const reader = new FileReader();
  reader.onload = ev => {
    try {
      _backupFileData = JSON.parse(ev.target.result);
      const fn = document.getElementById('backup-file-name');
      fn.textContent = '✓ Loaded: ' + file.name + (
        _backupFileData._meta ? ' (created ' + (_backupFileData._meta.created_at||'').slice(0,16) + ')' : ''
      );
      fn.style.display = 'block';
      document.getElementById('backup-options').style.display = 'block';
      document.getElementById('backup-drop').style.borderColor = 'var(--success)';
      document.getElementById('restore-status').style.display = 'none';
    } catch(err) {
      toast('Invalid backup file — not valid JSON.', 'err');
    }
  };
  reader.readAsText(file);
}

function cancelRestore() {
  _backupFileData = null;
  document.getElementById('backup-options').style.display = 'none';
  document.getElementById('backup-file-name').style.display = 'none';
  document.getElementById('backup-drop').style.borderColor = 'var(--border)';
  document.getElementById('backup-file').value = '';
}

async function submitRestore() {
  if (!_backupFileData) { toast('No backup file loaded.', 'err'); return; }
  const opts = {
    users:     document.getElementById('restore-users').checked,
    accounts:  document.getElementById('restore-accounts').checked,
    rules:     document.getElementById('restore-rules').checked,
    smtp:      document.getElementById('restore-smtp').checked,
    summaries: document.getElementById('restore-summaries').checked,
  };
  if (!Object.values(opts).some(Boolean)) { toast('Select at least one item to restore.', 'err'); return; }
  if (!confirm('Restore selected data? Existing matching records will be overwritten.')) return;

  const btn    = document.getElementById('restore-btn');
  const status = document.getElementById('restore-status');
  btn.disabled = true;
  btn.textContent = '⏳ Restoring…';
  status.style.display = 'none';

  const d = await api('restore_backup', {data: _backupFileData, options: opts});
  btn.disabled = false;
  btn.textContent = '⬆ Restore Selected';
  status.style.display = 'block';
  if (d.error) {
    status.style.color = 'var(--danger)';
    status.textContent = '✕ ' + d.error;
    toast(d.error, 'err');
  } else {
    status.style.color = 'var(--success)';
    status.textContent = '✓ ' + d.message;
    toast(d.message, 'ok');
    await loadUsers();
    await loadStats();
    await loadBackupInfo();
  }
}

// ── Init ──────────────────────────────────────────────────────────
(async () => {
  await Promise.all([loadUsers(), loadStats()]);
})();
</script>
</body>
</html>
