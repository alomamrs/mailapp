<?php
/**
 * summarize.php — AI email summarizer via OpenRouter
 */

// Catch ALL fatal errors and return JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    header('Content-Type: application/json');
    echo json_encode(['error' => "PHP Error ($errno): $errstr in $errfile line $errline"]);
    exit;
});
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['error' => 'PHP Fatal: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']]);
    }
});
ob_start(function($buf) {
    if (headers_sent()) return $buf;
    $clean = trim($buf);
    if ($clean !== '' && $clean[0] !== '{' && $clean[0] !== '[') {
        header('Content-Type: application/json');
        return json_encode(['error' => 'Server output error', 'detail' => substr($clean, 0, 300)]);
    }
    return $buf;
});

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth/Security.php';
require_once __DIR__ . '/auth/Portal.php';
require_once __DIR__ . '/auth/Auth.php';
require_once __DIR__ . '/Graph.php';

ob_clean();
header('Content-Type: application/json');

// Must be portal-authenticated
if (!Portal::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}
Security::sendHeaders(true);
Security::verifyCsrf();

require_once __DIR__ . '/auth/UserTokens.php';

// Load AI settings — per-user first, fall back to global
$currentUser    = $_SESSION['portal_user'] ?? '';
$aiSettings     = resolveAiSettings($currentUser);

$apiKey = trim($aiSettings['openrouter_api_key'] ?? '');
$model  = trim($aiSettings['openrouter_model'] ?? 'openai/gpt-4o-mini');

if (!$apiKey) {
    echo json_encode(['error' => 'No OpenRouter API key set. Go to Manage → My AI Settings to add your key.']);
    exit;
}

// Parse request
$body    = json_decode(file_get_contents('php://input'), true);
$emailId = trim($body['email_id'] ?? '');
if (!$emailId) {
    echo json_encode(['error' => 'No email ID provided.']);
    exit;
}

// Fetch email from Microsoft Graph
try {
    $token = Auth::getToken();
    if (!$token) {
        echo json_encode(['error' => 'No active Microsoft account.']);
        exit;
    }

    $graph = new Graph($token);
    $email = $graph->get('/me/messages/' . urlencode($emailId) . '?$select=subject,from,toRecipients,receivedDateTime,body,importance');

    if (isset($email['error'])) {
        echo json_encode(['error' => 'Could not fetch email: ' . ($email['error']['message'] ?? 'Unknown error')]);
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['error' => 'Graph API error: ' . $e->getMessage()]);
    exit;
}

// Extract and clean email body
$subject     = $email['subject'] ?? '(No subject)';
$fromName    = $email['from']['emailAddress']['name'] ?? '';
$fromAddr    = $email['from']['emailAddress']['address'] ?? '';
$sender      = $fromName ? "$fromName <$fromAddr>" : $fromAddr;
$date        = $email['receivedDateTime'] ?? '';
$bodyContent = $email['body']['content'] ?? '';
$bodyType    = $email['body']['contentType'] ?? 'text';

if (strtolower($bodyType) === 'html') {
    $bodyContent = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $bodyContent);
    $bodyContent = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $bodyContent);
    $bodyContent = preg_replace('/<br\s*\/?>/i', "\n", $bodyContent);
    $bodyContent = preg_replace('/<\/p>/i', "\n\n", $bodyContent);
    $bodyContent = strip_tags($bodyContent);
}
$bodyContent = html_entity_decode($bodyContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$bodyContent = preg_replace('/[ \t]+/', ' ', $bodyContent);
$bodyContent = preg_replace('/\n{3,}/', "\n\n", trim($bodyContent));
if (strlen($bodyContent) > 3000) {
    $bodyContent = substr($bodyContent, 0, 3000) . "\n\n[... email truncated ...]";
}

// Build prompt
$prompt = <<<PROMPT
You are an email assistant. Summarize the following email concisely for a busy professional.

Email details:
- Subject: $subject
- From: $sender
- Date: $date

Email body:
$bodyContent

Respond ONLY with a JSON object (no markdown, no code fences) in this exact format:
{
  "summary": "2-4 sentence plain-English summary",
  "action_items": ["action item 1", "action item 2"],
  "sentiment": "one of: positive, neutral, negative, urgent, informational"
}

If there are no action items, return an empty array.
PROMPT;

// Call OpenRouter API
$requestBody = json_encode([
    'model'    => $model,
    'messages' => [
        ['role' => 'user', 'content' => $prompt]
    ],
    'max_tokens' => 600,
]);

$ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $requestBody,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
        'HTTP-Referer: https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        'X-Title: Mail App Summarizer',
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo json_encode(['error' => 'Network error calling OpenRouter: ' . $curlError]);
    exit;
}

if ($httpCode !== 200) {
    $errData = json_decode($response, true);
    $errMsg  = $errData['error']['message'] ?? "HTTP $httpCode: $response";
    echo json_encode(['error' => 'OpenRouter error: ' . $errMsg]);
    exit;
}

$orResponse = json_decode($response, true);
$text = $orResponse['choices'][0]['message']['content'] ?? '';
$text = trim($text);
$text = preg_replace('/^```json\s*/i', '', $text);
$text = preg_replace('/\s*```$/', '', $text);

$result = json_decode($text, true);
if (!$result || !isset($result['summary'])) {
    echo json_encode([
        'summary'      => $text ?: 'Could not parse summary.',
        'action_items' => [],
        'sentiment'    => ''
    ]);
    exit;
}

echo json_encode([
    'summary'      => $result['summary'] ?? '',
    'action_items' => array_values(array_filter($result['action_items'] ?? [])),
    'sentiment'    => $result['sentiment'] ?? ''
]);
