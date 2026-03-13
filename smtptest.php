<?php
/**
 * smtptest.php — Standalone SMTP diagnostic tool
 * DELETE THIS FILE after testing. Password is sent in POST body.
 * Access: smtptest.php (admin session required)
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth/Portal.php';
Portal::guardAdmin();

$result = null;
$log    = [];

function smtp_test(string $host, int $port, string $user, string $pass, string $secure, string $to, array &$log): array {
    $CRLF = "\r\n";
    $log  = [];

    // ── Step 1: connect ───────────────────────────────────────────
    $log[] = "Connecting to {$host}:{$port} (secure={$secure})…";
    $errno = 0; $errstr = '';
    if ($secure === 'ssl') {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
        ]]);
        $sock = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    } else {
        $sock = @fsockopen($host, $port, $errno, $errstr, 20);
    }
    if (!$sock) return ['ok' => false, 'step' => 'connect', 'error' => "Connection failed: {$errstr} (errno={$errno})\nCheck host/port. cPanel may block outbound port {$port}."];
    stream_set_timeout($sock, 20);
    $log[] = "Connected.";

    // ── Helpers ───────────────────────────────────────────────────
    $readResp = function() use ($sock, &$log): string {
        $buf = ''; $max = 40;
        while ($max-- > 0) {
            $line = @fgets($sock, 1024);
            if ($line === false || $line === '') break;
            $buf .= $line;
            $log[] = '< ' . rtrim($line);
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $buf;
    };
    $send = function(string $cmd, bool $hide = false) use ($sock, $CRLF, &$log, $readResp): string {
        $log[] = '> ' . ($hide ? '(hidden)' : $cmd);
        fwrite($sock, $cmd . $CRLF);
        return $readResp();
    };

    // ── Step 2: banner ────────────────────────────────────────────
    $banner = $readResp();
    if (strpos($banner, '220') === false) {
        fclose($sock);
        return ['ok' => false, 'step' => 'banner', 'error' => "Unexpected banner: " . trim($banner)];
    }

    // ── Step 3: EHLO ──────────────────────────────────────────────
    $domain = gethostname() ?: 'localhost';
    $ehloR  = $send("EHLO {$domain}");
    if (strpos($ehloR, '250') === false) $send("HELO {$domain}");

    // ── Step 4: STARTTLS ──────────────────────────────────────────
    if ($secure === 'tls') {
        $log[] = "Sending STARTTLS…";
        $stls = $send('STARTTLS');
        if (strpos($stls, '220') === false) {
            fclose($sock);
            return ['ok' => false, 'step' => 'starttls', 'error' => "STARTTLS rejected: " . trim($stls) . "\nTry port 465 with SSL mode."];
        }
        stream_context_set_option($sock, 'ssl', 'verify_peer',       false);
        stream_context_set_option($sock, 'ssl', 'verify_peer_name',  false);
        stream_context_set_option($sock, 'ssl', 'allow_self_signed',  true);

        // Try methods in order of preference
        $methods = [];
        if (defined('STREAM_CRYPTO_METHOD_TLS_ANY_CLIENT'))  $methods[] = STREAM_CRYPTO_METHOD_TLS_ANY_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT'))  $methods[] = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT'))  $methods[] = STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLS_CLIENT'))      $methods[] = STREAM_CRYPTO_METHOD_TLS_CLIENT;

        $tlsOk = false;
        foreach ($methods as $m) {
            $log[] = "Trying TLS method constant={$m}…";
            if (@stream_socket_enable_crypto($sock, true, $m)) { $tlsOk = true; $log[] = "TLS ok with method {$m}"; break; }
        }
        if (!$tlsOk) {
            fclose($sock);
            return ['ok' => false, 'step' => 'tls', 'error' => "TLS handshake failed on all methods.\nYour PHP or server may not support this TLS version.\nTry port 465 SSL mode or check your host's TLS config."];
        }
        $send("EHLO {$domain}"); // re-introduce after upgrade
    }

    // ── Step 5: AUTH ──────────────────────────────────────────────
    $log[] = "Attempting AUTH LOGIN…";
    $authR = $send('AUTH LOGIN');
    if (strpos($authR, '334') !== false) {
        $send(base64_encode($user), true);
        $passR = $send(base64_encode($pass), true);
        if (strpos($passR, '235') === false) {
            fclose($sock);
            return ['ok' => false, 'step' => 'auth', 'error' => "AUTH LOGIN failed: " . trim($passR) . "\nCheck username/password. Gmail needs an App Password."];
        }
    } else {
        $log[] = "AUTH LOGIN not supported, trying AUTH PLAIN…";
        $plain = base64_encode("\0{$user}\0{$pass}");
        $plainR = $send("AUTH PLAIN {$plain}", true);
        if (strpos($plainR, '235') === false) {
            fclose($sock);
            return ['ok' => false, 'step' => 'auth', 'error' => "AUTH PLAIN failed: " . trim($plainR) . "\nCheck credentials."];
        }
    }
    $log[] = "AUTH ok.";

    // ── Step 6: send email ────────────────────────────────────────
    $mfR = $send("MAIL FROM:<{$user}>");
    if (strpos($mfR, '250') === false) {
        fclose($sock); return ['ok' => false, 'step' => 'mail_from', 'error' => trim($mfR)];
    }
    $rtR = $send("RCPT TO:<{$to}>");
    if (strpos($rtR, '25') === false) {
        fclose($sock); return ['ok' => false, 'step' => 'rcpt_to', 'error' => "Recipient rejected: " . trim($rtR)];
    }
    $dataR = $send('DATA');
    if (strpos($dataR, '354') === false) {
        fclose($sock); return ['ok' => false, 'step' => 'data', 'error' => "DATA rejected: " . trim($dataR)];
    }
    $subj = '=?UTF-8?B?' . base64_encode('SMTP Test – Mail App') . '?=';
    $body = chunk_split(base64_encode("SMTP test from smtptest.php\r\nHost: {$host}:{$port} ({$secure})\r\nUser: {$user}"));
    $msg  = "From: <{$user}>\r\nTo: {$to}\r\nSubject: {$subj}\r\nMIME-Version: 1.0\r\n"
          . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n"
          . "Date: " . date('r') . "\r\n\r\n{$body}";
    fwrite($sock, $msg . "\r\n.\r\n");
    $log[] = '> (message body + .)';
    $resp = $readResp();
    $send('QUIT');
    fclose($sock);

    if (strpos($resp, '250') === false) {
        return ['ok' => false, 'step' => 'send', 'error' => "Message rejected: " . trim($resp)];
    }
    return ['ok' => true];
}

// ── Handle form POST ──────────────────────────────────────────────
$diag = []; // network diagnostics
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host   = trim($_POST['host']   ?? '');
    $port   = (int)($_POST['port']  ?? 587);
    $user   = trim($_POST['user']   ?? '');
    $pass   = $_POST['pass']        ?? '';
    $secure = trim($_POST['secure'] ?? 'tls');
    $to     = trim($_POST['to']     ?? '');

    // Pre-fill from saved settings if fields empty
    $saved = [];
    $f = __DIR__ . '/data/smtp_settings.json';
    if (file_exists($f)) $saved = json_decode(file_get_contents($f), true) ?? [];
    if (!$host)      $host   = $saved['host']   ?? '';
    if (!$user)      $user   = $saved['user']   ?? '';
    if (!$pass)      $pass   = $saved['pass']   ?? '';
    if (!$secure)    $secure = $saved['secure'] ?? 'tls';
    if ($port === 0) $port   = (int)($saved['port'] ?? 587);

    if (!$host || !$user || !$pass || !$to) {
        $result = ['ok' => false, 'step' => 'validation', 'error' => 'All fields required.'];
    } else {
        // ── Run network diagnostics first ─────────────────────────
        // 1. DNS resolve
        $ip = @gethostbyname($host);
        if ($ip === $host) {
            $diag[] = ['status'=>'err', 'label'=>'DNS', 'msg'=>"Cannot resolve \"{$host}\" — hostname not found. Check for typos. Your server may have DNS restrictions."];
        } else {
            $diag[] = ['status'=>'ok', 'label'=>'DNS', 'msg'=>"Resolved to {$ip}"];

            // 2. TCP connect probe (no TLS yet)
            $probe = @fsockopen($ip, $port, $pErr, $pStr, 8);
            if ($probe) {
                fclose($probe);
                $diag[] = ['status'=>'ok', 'label'=>"TCP {$port}", 'msg'=>"Port {$port} is reachable"];
            } else {
                $diag[] = ['status'=>'err', 'label'=>"TCP {$port}", 'msg'=>"Port {$port} blocked or refused: {$pStr}. Try a different port."];
                // Try alternate ports
                foreach ([587,465,25,2525] as $altPort) {
                    if ($altPort === $port) continue;
                    $alt = @fsockopen($ip, $altPort, $aErr, $aStr, 5);
                    if ($alt) {
                        fclose($alt);
                        $diag[] = ['status'=>'warn', 'label'=>"TCP {$altPort}", 'msg'=>"Port {$altPort} IS reachable — try using this port instead"];
                    } else {
                        $diag[] = ['status'=>'info', 'label'=>"TCP {$altPort}", 'msg'=>"Port {$altPort} also blocked ({$aStr})"];
                    }
                }
            }
        }

        // 3. Check if allow_url_fopen / sockets enabled
        $diag[] = ['status'=>ini_get('allow_url_fopen')?'ok':'warn', 'label'=>'allow_url_fopen', 'msg'=>ini_get('allow_url_fopen')?'Enabled':'Disabled — may affect connections'];
        $diag[] = ['status'=>function_exists('fsockopen')?'ok':'err', 'label'=>'fsockopen', 'msg'=>function_exists('fsockopen')?'Available':'NOT available — cannot make TCP connections'];
        $diag[] = ['status'=>extension_loaded('openssl')?'ok':'err', 'label'=>'OpenSSL', 'msg'=>extension_loaded('openssl')?'Loaded (version: '.OPENSSL_VERSION_TEXT.')':'NOT loaded — TLS/SSL impossible'];

        // Only run full SMTP test if TCP connect succeeded
        $tcpOk = ($ip !== $host) && @fsockopen($ip ?? $host, $port, $x, $y, 5);
        if ($tcpOk) { fclose($tcpOk); $result = smtp_test($host, $port, $user, $pass, $secure, $to, $log); }
        else { $result = ['ok'=>false,'step'=>'connect','error'=>"Cannot reach {$host}:{$port}. See diagnostics above.\n\nMost likely causes:\n• Your cPanel host blocks outbound SMTP (very common on shared hosting)\n• Try using your cPanel's own mail server instead:\n  Host: mail.yourdomain.com  Port: 587  User: you@yourdomain.com\n• Or contact your host and ask them to allow outbound port 587/465"]; }
    }
}

// ── Pre-fill from saved ───────────────────────────────────────────
$saved = [];
$f = __DIR__ . '/data/smtp_settings.json';
if (file_exists($f)) $saved = json_decode(file_get_contents($f), true) ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>SMTP Diagnostic</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',sans-serif;background:#0d0d14;color:#e8e8f0;min-height:100vh;padding:2rem 1rem}
.wrap{max-width:620px;margin:0 auto}
h1{font-size:1.3rem;margin-bottom:.25rem;color:#d4a843}
.sub{font-size:.78rem;color:#6b6b8a;margin-bottom:2rem}
.warn{background:rgba(224,82,82,.12);border:1px solid rgba(224,82,82,.35);border-radius:8px;padding:.75rem 1rem;font-size:.78rem;color:#f87171;margin-bottom:1.5rem}
.card{background:#13131f;border:1px solid #2a2a3e;border-radius:10px;padding:1.5rem;margin-bottom:1.25rem}
label{display:block;font-size:.65rem;text-transform:uppercase;letter-spacing:1.2px;color:#6b6b8a;margin-bottom:.3rem;font-family:monospace}
input,select{width:100%;background:#1a1a2e;border:1.5px solid #2a2a3e;border-radius:6px;padding:.5rem .75rem;font-size:.85rem;color:#e8e8f0;outline:none;margin-bottom:.85rem}
input:focus,select:focus{border-color:#d4a843}
.row{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}
button{background:#d4a843;color:#1a1200;border:none;border-radius:7px;padding:.6rem 1.5rem;font-size:.85rem;font-weight:700;cursor:pointer;width:100%}
button:hover{background:#e8bc50}
.result{margin-top:1.25rem;border-radius:8px;padding:1rem;font-size:.8rem;line-height:1.6}
.result.ok{background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.3);color:#4ade80}
.result.err{background:rgba(224,82,82,.1);border:1px solid rgba(224,82,82,.3);color:#f87171}
.log{background:#0a0a12;border:1px solid #2a2a3e;border-radius:6px;padding:.85rem;margin-top:1rem;font-family:monospace;font-size:.68rem;color:#9090b0;white-space:pre-wrap;max-height:400px;overflow-y:auto;line-height:1.7}
.log .s{color:#6b9fd4} /* send */
.log .r{color:#9090b0} /* recv */
.log .i{color:#d4a843} /* info */
a{color:#d4a843;font-size:.75rem}
</style>
</head>
<body>
<div class="wrap">
  <h1>⚡ SMTP Diagnostic</h1>
  <p class="sub">Tests your SMTP connection step by step and shows the full conversation log.</p>
  <div class="warn">⚠ This page sends your SMTP password over POST. <strong>Delete smtptest.php</strong> after testing.</div>

  <div style="background:rgba(212,168,67,.08);border:1px solid rgba(212,168,67,.25);border-radius:8px;padding:1rem 1.1rem;margin-bottom:1.25rem;font-size:.78rem;line-height:1.8;color:#e8e8f0">
    <strong style="color:#d4a843">💡 On cPanel / shared hosting?</strong> External SMTP (Gmail, Outlook) is usually blocked.<br>
    Use your <strong>cPanel mail account</strong> instead:<br>
    <code style="color:#d4a843">Host:</code> mail.<em>yourdomain.com</em> &nbsp;
    <code style="color:#d4a843">Port:</code> 587 &nbsp;
    <code style="color:#d4a843">Security:</code> TLS &nbsp;
    <code style="color:#d4a843">User:</code> you@yourdomain.com &nbsp;
    <code style="color:#d4a843">Pass:</code> your cPanel email password<br>
    <span style="color:#6b6b8a">Create the email account first in cPanel → Email Accounts.</span>
  </div>

  <div class="card">
    <form method="POST">
      <div class="row">
        <div>
          <label>SMTP Host</label>
          <input name="host" type="text" value="<?= htmlspecialchars($_POST['host'] ?? $saved['host'] ?? '') ?>" placeholder="smtp.gmail.com"/>
        </div>
        <div>
          <label>Port</label>
          <input name="port" type="number" value="<?= htmlspecialchars($_POST['port'] ?? $saved['port'] ?? '587') ?>"/>
        </div>
      </div>
      <label>Security</label>
      <select name="secure">
        <?php foreach (['tls'=>'TLS (STARTTLS) — port 587','ssl'=>'SSL — port 465','none'=>'None (plaintext) — port 25'] as $v=>$l): ?>
          <option value="<?=$v?>" <?= (($_POST['secure'] ?? $saved['secure'] ?? 'tls')===$v)?'selected':'' ?>><?=$l?></option>
        <?php endforeach; ?>
      </select>
      <label>Username (email)</label>
      <input name="user" type="email" value="<?= htmlspecialchars($_POST['user'] ?? $saved['user'] ?? '') ?>" placeholder="you@gmail.com"/>
      <label>Password / App Password</label>
      <input name="pass" type="password" value="<?= htmlspecialchars($_POST['pass'] ?? '') ?>" placeholder="Leave blank to use saved password"/>
      <label>Send Test Email To</label>
      <input name="to" type="email" value="<?= htmlspecialchars($_POST['to'] ?? '') ?>" placeholder="inbox@example.com" required/>
      <button type="submit">⚡ Run SMTP Test</button>
    </form>

    <?php if (!empty($diag)): ?>
    <div style="margin-top:1.25rem">
      <div style="font-size:.65rem;font-family:monospace;text-transform:uppercase;letter-spacing:1.2px;color:#6b6b8a;margin-bottom:.6rem">Network Diagnostics</div>
      <?php foreach ($diag as $d): ?>
        <?php
          $colors = ['ok'=>'#4ade80','err'=>'#f87171','warn'=>'#fbbf24','info'=>'#9090b0'];
          $icons  = ['ok'=>'✓','err'=>'✕','warn'=>'⚠','info'=>'·'];
          $c = $colors[$d['status']] ?? '#9090b0';
          $i = $icons[$d['status']] ?? '·';
        ?>
        <div style="display:flex;gap:.75rem;align-items:flex-start;padding:.35rem 0;border-bottom:1px solid #1a1a2e;font-size:.78rem">
          <span style="color:<?=$c?>;flex-shrink:0;width:1rem;text-align:center"><?=$i?></span>
          <span style="color:#6b9fd4;flex-shrink:0;min-width:120px;font-family:monospace;font-size:.7rem"><?=htmlspecialchars($d['label'])?></span>
          <span style="color:<?=$c?>"><?=htmlspecialchars($d['msg'])?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($result !== null): ?>
    <div class="result <?= $result['ok'] ? 'ok' : 'err' ?>">
      <?php if ($result['ok']): ?>
        ✓ <strong>SMTP test passed!</strong> Email sent — check your inbox.
      <?php else: ?>
        ✕ <strong>Failed at step: <?= htmlspecialchars($result['step'] ?? 'unknown') ?></strong><br><br>
        <?= nl2br(htmlspecialchars($result['error'] ?? 'Unknown error')) ?>
      <?php endif; ?>
    </div>
    <?php if (!empty($log)): ?>
    <div class="log"><?php
      foreach ($log as $line) {
        if (strpos($line, '>') === 0) echo '<span class="s">' . htmlspecialchars($line) . '</span>' . "\n";
        elseif (strpos($line, '<') === 0) echo '<span class="r">' . htmlspecialchars($line) . '</span>' . "\n";
        else echo '<span class="i">' . htmlspecialchars($line) . '</span>' . "\n";
      }
    ?></div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <p style="font-size:.72rem;color:#6b6b8a;text-align:center">
    <a href="admin.php">← Back to Admin Panel</a> &nbsp;·&nbsp;
    <strong style="color:#f87171">Delete this file when done!</strong>
  </p>
</div>
</body>
</html>
