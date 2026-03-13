<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth/Auth.php';

class Graph {

    // ── Core HTTP ────────────────────────────────────────────────

    public static function get(string $endpoint, ?string $accountId = null): array {
        $token = Auth::getToken($accountId);
        if (!$token) throw new Exception('Not authenticated');
        $ch = curl_init(GRAPH_URL . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer '.$token,'Content-Type: application/json','Accept: application/json'],
            CURLOPT_TIMEOUT        => 20,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);
        if ($error) throw new Exception('cURL error: '.$error);
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new Exception('Invalid JSON response');
        if ($httpCode === 401) throw new Exception('Token expired. Please sign in again.');
        if (isset($data['error'])) throw new Exception($data['error']['message'] ?? 'Graph API error');
        return $data;
    }

    public static function post(string $endpoint, array $body, ?string $accountId = null): array {
        $token = Auth::getToken($accountId);
        if (!$token) throw new Exception('Not authenticated');
        $json = json_encode($body);
        $ch   = curl_init(GRAPH_URL . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer '.$token,'Content-Type: application/json','Accept: application/json','Content-Length: '.strlen($json)],
            CURLOPT_TIMEOUT        => 20,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);
        if ($error) throw new Exception('cURL error: '.$error);
        if ($httpCode === 401) throw new Exception('Token expired.');
        if (empty($response)) return ['status' => 'sent'];
        $data = json_decode($response, true);
        if (isset($data['error'])) throw new Exception($data['error']['message'] ?? 'Graph API error');
        return $data ?: ['status' => 'sent'];
    }

    public static function patch(string $endpoint, array $body, ?string $accountId = null): array {
        $token = Auth::getToken($accountId);
        if (!$token) throw new Exception('Not authenticated');
        $json = json_encode($body);
        $ch   = curl_init(GRAPH_URL . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PATCH',
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer '.$token,'Content-Type: application/json','Content-Length: '.strlen($json)],
            CURLOPT_TIMEOUT        => 20,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 401) throw new Exception('Token expired.');
        $data = json_decode($response, true);
        if (isset($data['error'])) throw new Exception($data['error']['message'] ?? 'Graph API error');
        return $data ?: ['status' => 'ok'];
    }

    public static function delete(string $endpoint, ?string $accountId = null): bool {
        $token = Auth::getToken($accountId);
        if (!$token) throw new Exception('Not authenticated');
        $ch = curl_init(GRAPH_URL . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer '.$token],
            CURLOPT_TIMEOUT        => 20,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $httpCode >= 200 && $httpCode < 300;
    }

    // Raw GET returning bytes (for attachments)
    public static function getRaw(string $endpoint, ?string $accountId = null): string {
        $token = Auth::getToken($accountId);
        if (!$token) throw new Exception('Not authenticated');
        $ch = curl_init(GRAPH_URL . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer '.$token],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 401) throw new Exception('Token expired.');
        return $response;
    }

    // ── User ─────────────────────────────────────────────────────

    public static function getMe(?string $accountId = null): array {
        return self::get('/me?$select=displayName,mail,userPrincipalName', $accountId);
    }

    // ── Mail read ────────────────────────────────────────────────

    /**
     * Get emails. When $search is set, fetches ALL pages automatically up to $maxResults.
     * Search always uses /me/messages (all mailboxes) for broadest results.
     * Regular (no search) uses folder-scoped endpoint with $skip paging.
     */
    public static function getEmails(string $folder='inbox', int $top=20, int $skip=0, string $search='', string $filter='', ?string $accountId=null, string $skipToken='', bool $useMessagesEndpoint=false, int $maxResults=500): array {
        $select = 'id,subject,from,receivedDateTime,isRead,bodyPreview,hasAttachments';

        if ($search !== '') {
            // Search: always use /me/messages for all-mailbox search, fetch ALL pages up to $maxResults
            $pageSize = 50; // max Graph allows per page for search
            $allItems = [];
            $nextLink = null;

            // Build first page URL
            $endpoint = "/me/messages?\$top={$pageSize}&\$select={$select}&\$search=" . rawurlencode($search);
            if ($filter !== '') $endpoint .= '&$filter=' . rawurlencode($filter);

            $pages = 0;
            $maxPages = (int)ceil($maxResults / $pageSize);

            do {
                if ($nextLink) {
                    // nextLink is a full URL — strip the base so self::get() can prepend it
                    $endpoint = str_replace(GRAPH_URL, '', $nextLink);
                }
                $data = self::get($endpoint, $accountId);
                if (isset($data['error'])) return $data;

                $items = $data['value'] ?? [];
                $allItems = array_merge($allItems, $items);
                $nextLink = $data['@odata.nextLink'] ?? null;
                $pages++;

            } while ($nextLink && count($allItems) < $maxResults && $pages < $maxPages);

            // Sort by receivedDateTime desc (Graph search doesn't guarantee order)
            usort($allItems, function($a, $b) {
                return strcmp($b['receivedDateTime'] ?? '', $a['receivedDateTime'] ?? '');
            });

            return [
                'value'  => array_slice($allItems, 0, $maxResults),
                '@odata.count' => count($allItems),
            ];

        } else {
            // Normal folder browse — single page with $skip
            $endpoint = "/me/mailFolders/{$folder}/messages?\$top={$top}&\$skip={$skip}&\$select={$select}";
            $endpoint .= '&$orderby=receivedDateTime+desc';
            if ($filter !== '') $endpoint .= '&$filter=' . rawurlencode($filter);
            return self::get($endpoint, $accountId);
        }
    }

    public static function getEmail(string $id, ?string $accountId = null): array {
        return self::get("/me/messages/{$id}?\$select=id,subject,from,toRecipients,ccRecipients,bccRecipients,receivedDateTime,body,hasAttachments,isRead", $accountId);
    }

    public static function getAttachments(string $emailId, ?string $accountId = null): array {
        return self::get("/me/messages/{$emailId}/attachments?\$select=id,name,contentType,size,isInline", $accountId);
    }

    public static function getAttachmentContent(string $emailId, string $attachmentId, ?string $accountId = null): array {
        return self::get("/me/messages/{$emailId}/attachments/{$attachmentId}", $accountId);
    }

    public static function getEmailsSince(string $folder, string $since, int $top=50, ?string $accountId=null): array {
        $select   = 'id,subject,from,toRecipients,receivedDateTime,isRead,bodyPreview,body,hasAttachments';
        $filter   = urlencode("receivedDateTime ge {$since}");
        $endpoint = "/me/mailFolders/{$folder}/messages?\$top={$top}&\$filter={$filter}&\$orderby=receivedDateTime+desc&\$select={$select}";
        return self::get($endpoint, $accountId);
    }

    // ── Folders ──────────────────────────────────────────────────

    public static function getFolders(?string $accountId = null): array {
        return self::get('/me/mailFolders?$top=100&$select=id,displayName,unreadItemCount,totalItemCount,parentFolderId,childFolderCount', $accountId);
    }

    public static function getAllFolders(?string $accountId = null): array {
        // Recursively fetch all folders and subfolders
        $all = [];
        self::fetchFoldersRecursive(null, $all, $accountId);
        return ['value' => $all];
    }

    private static function fetchFoldersRecursive(?string $parentId, array &$all, ?string $accountId): void {
        $endpoint = $parentId
            ? "/me/mailFolders/{$parentId}/childFolders?\$top=100&\$select=id,displayName,unreadItemCount,totalItemCount,parentFolderId,childFolderCount"
            : '/me/mailFolders?$top=100&$select=id,displayName,unreadItemCount,totalItemCount,parentFolderId,childFolderCount';
        $result = self::get($endpoint, $accountId);
        $folders = $result['value'] ?? [];
        foreach ($folders as $folder) {
            $all[] = $folder;
            // Recurse if this folder has children
            if (($folder['childFolderCount'] ?? 0) > 0) {
                self::fetchFoldersRecursive($folder['id'], $all, $accountId);
            }
        }
    }

    public static function createFolder(string $name, ?string $parentId = null, ?string $accountId = null): array {
        $endpoint = $parentId ? "/me/mailFolders/{$parentId}/childFolders" : '/me/mailFolders';
        return self::post($endpoint, ['displayName' => $name], $accountId);
    }

    public static function renameFolder(string $folderId, string $newName, ?string $accountId = null): array {
        return self::patch("/me/mailFolders/{$folderId}", ['displayName' => $newName], $accountId);
    }

    public static function deleteFolder(string $folderId, ?string $accountId = null): bool {
        return self::delete("/me/mailFolders/{$folderId}", $accountId);
    }

    // ── Email actions ────────────────────────────────────────────

    public static function moveEmail(string $emailId, string $destinationFolderId, ?string $accountId = null): array {
        return self::post("/me/messages/{$emailId}/move", ['destinationId' => $destinationFolderId], $accountId);
    }

    public static function markRead(string $emailId, bool $isRead = true, ?string $accountId = null): array {
        return self::patch("/me/messages/{$emailId}", ['isRead' => $isRead], $accountId);
    }

    public static function deleteEmail(string $emailId, ?string $accountId = null): bool {
        return self::delete("/me/messages/{$emailId}", $accountId);
    }

    // ── Send / Reply / Forward ───────────────────────────────────

    public static function sendEmail(string $to, string $subject, string $body, string $cc='', string $bcc='', ?string $accountId=null): array {
        $recipients = array_filter(array_map('trim', explode(',', $to)));
        $ccList     = $cc  ? array_filter(array_map('trim', explode(',', $cc)))  : [];
        $bccList    = $bcc ? array_filter(array_map('trim', explode(',', $bcc))) : [];
        $msg = [
            'subject'      => $subject,
            'body'         => ['contentType' => 'HTML', 'content' => $body],
            'toRecipients' => array_map(fn($a)=>['emailAddress'=>['address'=>$a]], $recipients),
        ];
        if ($ccList)  $msg['ccRecipients']  = array_map(fn($a)=>['emailAddress'=>['address'=>$a]], $ccList);
        if ($bccList) $msg['bccRecipients'] = array_map(fn($a)=>['emailAddress'=>['address'=>$a]], $bccList);
        return self::post('/me/sendMail', ['message'=>$msg,'saveToSentItems'=>true], $accountId);
    }

    public static function replyEmail(string $emailId, string $body, bool $replyAll=false, ?string $accountId=null): array {
        $endpoint = $replyAll ? "/me/messages/{$emailId}/replyAll" : "/me/messages/{$emailId}/reply";
        return self::post($endpoint, ['comment' => $body], $accountId);
    }

    public static function forwardEmail(string $emailId, string $toAddress, string $comment='', ?string $accountId=null): array {
        return self::post("/me/messages/{$emailId}/forward", [
            'comment'      => $comment ?: 'Forwarded',
            'toRecipients' => [['emailAddress' => ['address' => $toAddress]]],
        ], $accountId);
    }

    // Create draft
    public static function createDraft(string $to, string $subject, string $body, string $cc='', ?string $inReplyToId=null, ?string $accountId=null): array {
        $recipients = array_filter(array_map('trim', explode(',', $to)));
        $ccList     = $cc ? array_filter(array_map('trim', explode(',', $cc))) : [];
        $msg = [
            'subject'      => $subject,
            'body'         => ['contentType' => 'HTML', 'content' => $body],
            'toRecipients' => array_map(fn($a)=>['emailAddress'=>['address'=>$a]], $recipients),
        ];
        if ($ccList) $msg['ccRecipients'] = array_map(fn($a)=>['emailAddress'=>['address'=>$a]], $ccList);
        if ($inReplyToId) $msg['inReplyTo'] = $inReplyToId;
        return self::post('/me/messages', $msg, $accountId);
    }

    public static function sendDraft(string $draftId, ?string $accountId = null): array {
        return self::post("/me/messages/{$draftId}/send", [], $accountId);
    }

    // ── Inbox rules ──────────────────────────────────────────────

    public static function getInboxRules(?string $accountId = null): array {
        try {
            return self::get('/me/mailFolders/inbox/messageRules', $accountId);
        } catch (Exception $e) {
            // Personal Microsoft accounts don't support server-side rules
            return ['value' => [], '_error' => $e->getMessage()];
        }
    }

    public static function createInboxRule(array $rule, ?string $accountId = null): array {
        // sequence is required by Graph API
        if (empty($rule['sequence'])) {
            $rule['sequence'] = rand(100, 999);
        }
        // Remove null/undefined fields that break validation
        $rule = array_filter($rule, fn($v) => $v !== null);
        return self::post('/me/mailFolders/inbox/messageRules', $rule, $accountId);
    }

    public static function updateInboxRule(string $ruleId, array $rule, ?string $accountId = null): array {
        // Remove sequence and null fields — PATCH only needs changed fields
        unset($rule['sequence']);
        $rule = array_filter($rule, fn($v) => $v !== null);
        return self::patch("/me/mailFolders/inbox/messageRules/{$ruleId}", $rule, $accountId);
    }

    public static function deleteInboxRule(string $ruleId, ?string $accountId = null): bool {
        return self::delete("/me/mailFolders/inbox/messageRules/{$ruleId}", $accountId);
    }

    // ── Calendar ─────────────────────────────────────────────────

    public static function getCalendarEvents(string $start, string $end, ?string $accountId = null): array {
        $select   = 'id,subject,start,end,location,isAllDay,organizer,attendees,bodyPreview,showAs,importance';
        $endpoint = "/me/calendarView?\$top=50&\$select={$select}&startDateTime=".urlencode($start)."&endDateTime=".urlencode($end)."&\$orderby=start/dateTime";
        return self::get($endpoint, $accountId);
    }

    public static function createEvent(array $event, ?string $accountId = null): array {
        return self::post('/me/events', $event, $accountId);
    }

    public static function deleteEvent(string $eventId, ?string $accountId = null): bool {
        return self::delete("/me/events/{$eventId}", $accountId);
    }
}
