<?php
/**
 * Mail Protocol Engine
 * ─────────────────────────────────────────────
 * Run via cPanel cron: php /path/to/mail-php/protocol.php
 * Or triggered via browser: protocol.php?run=1&token=YOUR_SECRET
 *
 * What it does per account:
 *  1. Fetch new emails since last run
 *  2. Apply keyword forwarding rules
 *  3. Generate summaries (AI or rule-based)
 *  4. Save results to data/summaries.json
 */

// Allow CLI and web trigger
$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    // Web trigger — require a secret token to prevent public abuse
    require_once __DIR__ . '/config.php';
    $token = $_GET['token'] ?? '';
    if ($token !== PROTOCOL_SECRET) {
        http_response_code(403);
        die(json_encode(['error' => 'Unauthorized']));
    }
    header('Content-Type: application/json');
}

if (!$isCli) require_once __DIR__ . '/config.php';
else require_once __DIR__ . '/config.php';

require_once __DIR__ . '/auth/Auth.php';
require_once __DIR__ . '/auth/Storage.php';
require_once __DIR__ . '/Graph.php';
require_once __DIR__ . '/Mailer.php';

class Protocol {

    private static array $results = [];

    public static function run(): array {
        Storage::log('=== Protocol run started ===');
        // Use getAllAccounts so every user's MS accounts get forwarding rules applied
        $accounts = Auth::getAllAccounts();

        if (empty($accounts)) {
            Storage::log('No accounts found. Exiting.');
            return ['status' => 'ok', 'message' => 'No accounts', 'results' => []];
        }

        $rules       = Storage::read(RULES_FILE);
        $state       = Storage::read(__DIR__ . '/data/protocol_state.json');
        $summData    = Storage::read(SUMMARIES_FILE);
        $fwdIdsFile  = __DIR__ . '/data/forwarded_ids.json';
        $forwarded_ids = file_exists($fwdIdsFile) ? (json_decode(file_get_contents($fwdIdsFile), true) ?: []) : [];
        // Keep only last 2000 to prevent file bloat
        if (count($forwarded_ids) > 2000) $forwarded_ids = array_slice($forwarded_ids, -1500);

        foreach ($accounts as $acc) {
            $id = $acc['id'];
            Storage::log("Processing account: {$acc['email']}");
            try {
                self::processAccount($id, $acc, $rules, $state, $summData, $forwarded_ids);
            } catch (Exception $e) {
                Storage::log("ERROR for {$acc['email']}: " . $e->getMessage());
                self::$results[] = ['account' => $acc['email'], 'error' => $e->getMessage()];
            }
        }

        // Save updated summaries, state, and forwarded IDs
        Storage::write(SUMMARIES_FILE, $summData);
        Storage::write(__DIR__ . '/data/protocol_state.json', $state);
        file_put_contents($fwdIdsFile, json_encode(array_values(array_unique($forwarded_ids))));
        Storage::log('=== Protocol run complete ===');

        return ['status' => 'ok', 'results' => self::$results, 'ran_at' => date('c')];
    }

    private static function processAccount(string $id, array $acc, array &$rules, array &$state, array &$summData, array &$forwarded_ids): void {
        // Get last run time for this account
        $lastRun  = $state[$id]['last_run'] ?? date('c', strtotime('-24 hours'));
        $lastRunDt = date('Y-m-d\TH:i:s\Z', strtotime($lastRun));

        // ── 1. Fetch new inbox emails ────────────────────────────
        $inboxData = Graph::getEmailsSince('inbox', $lastRunDt, SUMMARY_BATCH, $id);
        $emails    = $inboxData['value'] ?? [];
        Storage::log("  Found " . count($emails) . " new inbox emails");

        // ── 2. Fetch sent emails ─────────────────────────────────
        $sentData   = Graph::getEmailsSince('sentitems', $lastRunDt, 20, $id);
        $sentEmails = $sentData['value'] ?? [];
        Storage::log("  Found " . count($sentEmails) . " new sent emails");

        $forwarded  = 0;
        $summaries  = [];

        // ── 3. Apply forwarding rules ────────────────────────────
        // Only apply rules owned by the same portal user as this account
        $accPortalUser = $acc['portal_user'] ?? null;
        $accountRules = array_filter($rules, function($r) use ($id, $accPortalUser) {
            if (!($r['active'] ?? true)) return false;
            // Rule must belong to the same portal user as the account
            $ruleOwner = $r['portal_user'] ?? null;
            if ($ruleOwner !== $accPortalUser) return false;
            if ($r['account_id'] === $id) return true;          // own rule
            if ($r['all_accounts'] ?? false) return true;       // user's own global rule
            return false;
        });

        foreach ($emails as $email) {
            $subject  = strtolower($email['subject'] ?? '');
            $preview  = strtolower($email['bodyPreview'] ?? '');
            $from     = strtolower($email['from']['emailAddress']['address'] ?? '');

            foreach ($accountRules as $rule) {
                $keyword   = strtolower(trim($rule['keyword'] ?? ''));
                $matchIn   = $rule['match_in'] ?? ['subject','body','from'];
                if (!$keyword || empty($rule['forward_to'])) continue;

                // Build haystack based on match_in setting
                $haystack = '';
                if (in_array('subject', $matchIn)) $haystack .= ' ' . $subject;
                if (in_array('body',    $matchIn)) $haystack .= ' ' . $preview;
                if (in_array('from',    $matchIn)) $haystack .= ' ' . $from;

                if (strpos($haystack, $keyword) !== false) {
                    // Skip if already forwarded
                    $fwdKey = $id . ':' . $email['id'];
                    if (in_array($fwdKey, $forwarded_ids)) {
                        break;
                    }
                    try {
                        // Forward using the account that received the email
                        // Use SMTP if configured so nothing appears in Sent folder
                        Mailer::forward($email, $rule['forward_to'], $id, $rule['keyword']);
                        $forwarded++;
                        $forwarded_ids[] = $fwdKey;
                        Storage::log("  Forwarded via " . (SMTP_HOST ? 'SMTP' : 'Graph') . ": \"{$email['subject']}\"");

                        // Always force unread — Graph marks emails read when forwarding via Graph
                        try {
                            Graph::markRead($email['id'], false, $id);
                        } catch (Exception $e) {}

                        $accountNote = ($rule['account_id'] !== $id) ? ' [global rule]' : '';
                        Storage::log("  Forwarded \"{$email['subject']}\" (keyword: {$rule['keyword']}{$accountNote}) → {$rule['forward_to']}");

                        // Delete the auto-forwarded copy from Sent folder
                        try {
                            // Find the just-sent forward in Sent Items and delete it
                            sleep(2); // brief wait for Graph to create the sent item
                            $sent = Graph::getEmailsSince('sentitems', date('Y-m-d\TH:i:s\Z', strtotime('-1 minute')), 5, $id);
                            foreach ($sent['value'] ?? [] as $sentEmail) {
                                $sentSubj = strtolower($sentEmail['subject'] ?? '');
                                $origSubj = strtolower($email['subject'] ?? '');
                                // Match by subject (forward prepends FW:)
                                if (strpos($sentSubj, $origSubj) !== false || strpos($sentSubj, 'fw:') !== false) {
                                    Graph::deleteEmail($sentEmail['id'], $id);
                                    Storage::log("  Deleted forwarded copy from Sent: \"{$sentEmail['subject']}\"");
                                    break;
                                }
                            }
                        } catch (Exception $e) {
                            Storage::log("  Could not delete sent copy: " . $e->getMessage());
                        }

                        // Log to rule history
                        foreach ($rules as &$r) {
                            if ($r['id'] === $rule['id']) {
                                $r['history'][] = [
                                    'at'              => date('c'),
                                    'subject'         => $email['subject'],
                                    'to'              => $rule['forward_to'],
                                    'matched_account' => $acc['email'],
                                ];
                                $r['history'] = array_slice($r['history'], -20);
                                break;
                            }
                        }
                        unset($r);
                    } catch (Exception $e) {
                        Storage::log("  Forward failed: " . $e->getMessage());
                    }
                    break; // one rule match per email is enough
                }
            }
        }

        // ── 4. Generate summaries ────────────────────────────────
        $allEmails = array_merge($emails, $sentEmails);

        if (!empty($allEmails)) {
            $useAI = defined('CLAUDE_API_KEY') && CLAUDE_API_KEY !== '';

            if ($useAI) {
                $summary = self::summariseWithAI($allEmails, $acc['email'], $acc['name']);
            } else {
                $summary = self::summariseRuleBased($emails, $sentEmails);
            }

            $summaries[] = [
                'generated_at' => date('c'),
                'account_id'   => $id,
                'account_email'=> $acc['email'],
                'account_name' => $acc['name'],
                'inbox_count'  => count($emails),
                'sent_count'   => count($sentEmails),
                'forwarded'    => $forwarded,
                'method'       => $useAI ? 'ai' : 'rule-based',
                'summary'      => $summary,
                'period_start' => $lastRun,
                'period_end'   => date('c'),
            ];

            // Keep last 30 summaries per account
            if (!isset($summData[$id])) $summData[$id] = [];
            $summData[$id] = array_slice(array_merge($summData[$id], $summaries), -30);
        }

        // ── 5. Update state ──────────────────────────────────────
        $state[$id] = ['last_run' => date('c'), 'last_count' => count($emails)];

        // Save updated rules (with history)
        Storage::write(RULES_FILE, $rules);

        self::$results[] = [
            'account'   => $acc['email'],
            'new_emails'=> count($emails),
            'sent'      => count($sentEmails),
            'forwarded' => $forwarded,
            'summarized'=> count($summaries) > 0,
        ];
    }

    // ── AI Summary via Claude API ────────────────────────────────

    private static function summariseWithAI(array $emails, string $accountEmail, string $accountName): string {
        $emailList = array_map(function($e) {
            $from    = $e['from']['emailAddress']['name'] ?? $e['from']['emailAddress']['address'] ?? 'Unknown';
            $subject = $e['subject'] ?? '(No subject)';
            $preview = substr($e['bodyPreview'] ?? '', 0, 200);
            $date    = date('M j g:ia', strtotime($e['receivedDateTime'] ?? 'now'));
            return "- [{$date}] From: {$from} | Subject: {$subject} | Preview: {$preview}";
        }, array_slice($emails, 0, 30));

        $emailText = implode("\n", $emailList);
        $count     = count($emails);

        $prompt = "You are an email assistant for {$accountName} ({$accountEmail}).\n\n"
            . "Here are {$count} recent emails:\n\n{$emailText}\n\n"
            . "Please provide a concise summary covering:\n"
            . "1. Key highlights and important emails\n"
            . "2. Any action items or urgent matters\n"
            . "3. General themes or patterns\n\n"
            . "Keep it to 3-5 short paragraphs. Be direct and useful.";

        try {
            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    'x-api-key: ' . CLAUDE_API_KEY,
                    'anthropic-version: 2023-06-01',
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'model'      => 'claude-sonnet-4-20250514',
                    'max_tokens' => 600,
                    'messages'   => [['role' => 'user', 'content' => $prompt]],
                ]),
                CURLOPT_TIMEOUT => 30,
            ]);
            $res  = curl_exec($ch);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($err) throw new Exception($err);
            $data = json_decode($res, true);
            return $data['content'][0]['text'] ?? self::summariseRuleBased([], []);
        } catch (Exception $e) {
            Storage::log("AI summary failed: " . $e->getMessage() . ". Falling back to rule-based.");
            return self::summariseRuleBased(array_filter($emails, fn($e) => !isset($e['sentDateTime'])), $emails);
        }
    }

    // ── Rule-based Summary ───────────────────────────────────────

    private static function summariseRuleBased(array $inbox, array $sent): string {
        $total   = count($inbox);
        $unread  = count(array_filter($inbox, fn($e) => !($e['isRead'] ?? true)));
        $withAtt = count(array_filter($inbox, fn($e) => $e['hasAttachments'] ?? false));
        $senders = [];

        foreach ($inbox as $e) {
            $from = $e['from']['emailAddress']['name'] ?? $e['from']['emailAddress']['address'] ?? 'Unknown';
            $senders[$from] = ($senders[$from] ?? 0) + 1;
        }
        arsort($senders);
        $topSenders = array_slice($senders, 0, 3, true);

        $subjects = array_slice(array_map(fn($e) => $e['subject'] ?? '(No subject)', $inbox), 0, 5);

        $lines = [];
        $lines[] = "📬 {$total} new email(s) received, {$unread} unread.";

        if ($withAtt > 0) $lines[] = "📎 {$withAtt} email(s) with attachments.";

        if (!empty($topSenders)) {
            $sndr = implode(', ', array_map(fn($n, $c) => "{$n} ({$c})", array_keys($topSenders), $topSenders));
            $lines[] = "👤 Top senders: {$sndr}.";
        }

        if (!empty($subjects)) {
            $lines[] = "📋 Recent subjects:";
            foreach ($subjects as $s) $lines[] = "  • " . htmlspecialchars($s);
        }

        if (!empty($sent)) {
            $lines[] = "✉ " . count($sent) . " email(s) sent in this period.";
        }

        return implode("\n", $lines);
    }
}

// ── Run ──────────────────────────────────────────────────────────
$result = Protocol::run();

if (!defined('PROTOCOL_INCLUDED')) {
    if ($isCli) {
        echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo json_encode($result);
    }
}
