<?php
/**
 * SearchParser.php
 * Translates user-friendly search strings into Microsoft Graph API KQL.
 *
 * Supported operators:
 *   from:"Name" / from:email@x.com
 *   to:"Name" / to:email@x.com
 *   subject:"phrase" / subject:word
 *   hasattachments:yes|no
 *   received:today|yesterday|this week|last week|this month|last month|MM/DD/YYYY
 *   filetype:pdf / filetype:docx  (mapped to attachment name contains)
 *   isread:yes|no
 *   isflagged:yes|no
 *   "exact phrase"
 *   word AND word
 *   word NOT word / word OR word
 *   plain keywords (passed through as-is to KQL)
 *
 * Graph API notes:
 *  - $search uses KQL (Keyword Query Language)
 *  - $filter uses OData — used for isRead, isFlagged, receivedDateTime, hasAttachments
 *  - Some operators (isread, isflagged, hasattachments, received) are better as $filter
 *  - from/to/subject/body/filetype are KQL-only ($search)
 *  - When BOTH $search and $filter are needed, Graph supports them together
 *    ONLY on /me/messages (not /me/mailFolders/{id}/messages) — we handle this.
 */
class SearchParser {

    /**
     * Parse a raw user query string.
     * Returns ['search' => string, 'filter' => string, 'use_messages_endpoint' => bool]
     *
     * 'search'  — KQL string for $search (empty if not needed)
     * 'filter'  — OData string for $filter (empty if not needed)
     * 'use_messages_endpoint' — true if we must use /me/messages instead of /me/mailFolders/{id}/messages
     *   (required when combining $search + $filter, or when searching across all folders)
     */
    public static function parse(string $raw): array {
        $raw = trim($raw);
        if ($raw === '') return ['search' => '', 'filter' => '', 'use_messages_endpoint' => false];

        $filterParts = [];   // OData $filter clauses
        $kqlParts    = [];   // KQL tokens for $search
        $needsFilter = false;
        $needsSearch = false;

        // Tokenise: split into operator:value tokens and bare words
        // Handles: key:"quoted value", key:value, "quoted phrase", bare words, AND/OR/NOT
        $tokens = self::tokenise($raw);

        foreach ($tokens as $tok) {
            $type  = $tok['type'];
            $key   = strtolower($tok['key']   ?? '');
            $value = $tok['value'] ?? '';

            switch ($type) {
                case 'operator':
                    switch ($key) {
                        // ── OData filter operators ──────────────────────────────
                        case 'isread':
                            $bool = self::boolVal($value);
                            $filterParts[] = 'isRead eq ' . ($bool ? 'true' : 'false');
                            $needsFilter   = true;
                            break;

                        case 'isflagged':
                            $bool = self::boolVal($value);
                            $filterParts[] = "flag/flagStatus eq '" . ($bool ? 'flagged' : 'notFlagged') . "'";
                            $needsFilter   = true;
                            break;

                        case 'hasattachments':
                            $bool = self::boolVal($value);
                            $filterParts[] = 'hasAttachments eq ' . ($bool ? 'true' : 'false');
                            $needsFilter   = true;
                            break;

                        case 'received':
                            $clause = self::receivedToFilter($value);
                            if ($clause) {
                                $filterParts[] = $clause;
                                $needsFilter   = true;
                            }
                            break;

                        // ── KQL search operators ────────────────────────────────
                        case 'from':
                            $kqlParts[]  = 'from:' . self::kqlQuote($value);
                            $needsSearch = true;
                            break;

                        case 'to':
                            $kqlParts[]  = 'to:' . self::kqlQuote($value);
                            $needsSearch = true;
                            break;

                        case 'subject':
                            $kqlParts[]  = 'subject:' . self::kqlQuote($value);
                            $needsSearch = true;
                            break;

                        case 'body':
                            $kqlParts[]  = 'body:' . self::kqlQuote($value);
                            $needsSearch = true;
                            break;

                        case 'filetype':
                            // Graph KQL: attachment:*.pdf style doesn't work well;
                            // best approach is search for the extension as a keyword
                            $ext = ltrim(strtolower($value), '.');
                            $kqlParts[]  = '".' . $ext . '"';
                            $needsSearch = true;
                            break;

                        default:
                            // Unknown operator — pass through as KQL keyword:value
                            $kqlParts[]  = $key . ':' . self::kqlQuote($value);
                            $needsSearch = true;
                    }
                    break;

                case 'quoted':
                    // Exact phrase
                    // KQL exact phrase — wrap in double quotes, escape inner double quotes
                    $kqlParts[]  = '"' . str_replace('"', '\\"', $value) . '"';
                    $needsSearch = true;
                    break;

                case 'boolean':
                    // AND / OR / NOT — pass through uppercase to KQL
                    $kqlParts[]  = strtoupper($value);
                    $needsSearch = true;
                    break;

                case 'word':
                    // Graph KQL does prefix matching natively — no wildcard needed
                    $kqlParts[]  = $value;
                    $needsSearch = true;
                    break;
            }
        }

        $searchStr = $needsSearch ? implode(' ', $kqlParts) : '';
        $filterStr = $needsFilter ? implode(' and ', $filterParts) : '';

        // When combining $search + $filter, Graph requires /me/messages endpoint
        $useMsgEndpoint = ($needsSearch && $needsFilter);

        // Build highlight terms: bare words (without wildcard), quoted phrases, from/to/subject values
        $highlights = self::extractHighlightTerms($tokens);

        return [
            'search'                 => $searchStr,
            'filter'                 => $filterStr,
            'use_messages_endpoint'  => $useMsgEndpoint,
            'highlight_terms'        => $highlights,
        ];
    }

    // ── Tokeniser ──────────────────────────────────────────────────────────────

    private static function tokenise(string $raw): array {
        $tokens = [];
        $len    = strlen($raw);
        $i      = 0;

        while ($i < $len) {
            // Skip whitespace
            while ($i < $len && ctype_space($raw[$i])) $i++;
            if ($i >= $len) break;

            // Check for operator:value pattern
            // Scan ahead for a word followed immediately by ':'
            if (preg_match('/^([a-zA-Z]+):/', substr($raw, $i), $m)) {
                $key  = $m[1];
                $i   += strlen($key) + 1; // skip "key:"

                // Value may be quoted or unquoted
                $value = self::readValue($raw, $i, $len);
                $tokens[] = ['type' => 'operator', 'key' => $key, 'value' => $value];
                continue;
            }

            // Quoted phrase
            if ($raw[$i] === '"') {
                $i++; // skip opening quote
                $value = '';
                while ($i < $len && $raw[$i] !== '"') {
                    $value .= $raw[$i++];
                }
                if ($i < $len) $i++; // skip closing quote
                $tokens[] = ['type' => 'quoted', 'value' => $value];
                continue;
            }

            // Bare word (may be AND/OR/NOT boolean)
            $word = '';
            while ($i < $len && !ctype_space($raw[$i]) && $raw[$i] !== '"') {
                $word .= $raw[$i++];
            }
            if ($word !== '') {
                $upper = strtoupper($word);
                if (in_array($upper, ['AND', 'OR', 'NOT'])) {
                    $tokens[] = ['type' => 'boolean', 'value' => $upper];
                } else {
                    $tokens[] = ['type' => 'word', 'value' => $word];
                }
            }
        }

        return $tokens;
    }

    private static function readValue(string $raw, int &$i, int $len): string {
        if ($i >= $len) return '';
        if ($raw[$i] === '"') {
            $i++; // skip opening quote
            $value = '';
            while ($i < $len && $raw[$i] !== '"') $value .= $raw[$i++];
            if ($i < $len) $i++; // skip closing quote
            return $value;
        }
        // Unquoted — read until whitespace
        $value = '';
        while ($i < $len && !ctype_space($raw[$i])) $value .= $raw[$i++];
        return $value;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private static function kqlQuote(string $value): string {
        // If value contains spaces or special chars, wrap in quotes
        if (strpos($value, ' ') !== false || preg_match('/[@.<>]/', $value)) {
            return '"' . str_replace('"', '\\"', $value) . '"';
        }
        return $value;
    }

    private static function boolVal(string $value): bool {
        return in_array(strtolower($value), ['yes', 'true', '1', 'on']);
    }

    /**
     * Convert a received: value into an OData $filter clause.
     * Supports: today, yesterday, this week, last week, this month, last month, MM/DD/YYYY
     */
    /**
     * Extract plain terms to highlight in the UI from parsed tokens.
     * Returns array of strings (words and phrases) — no KQL syntax.
     */
    private static function extractHighlightTerms(array $tokens): array {
        $terms = [];
        foreach ($tokens as $tok) {
            switch ($tok['type']) {
                case 'word':
                    // Skip boolean operators
                    if (!in_array(strtoupper($tok['value']), ['AND','OR','NOT'])) {
                        $terms[] = $tok['value'];
                    }
                    break;
                case 'quoted':
                    $terms[] = $tok['value'];
                    break;
                case 'operator':
                    // Highlight the value for from/to/subject/body
                    if (in_array(strtolower($tok['key']), ['from','to','subject','body'])) {
                        $terms[] = $tok['value'];
                    }
                    break;
            }
        }
        return array_values(array_unique(array_filter($terms)));
    }

    private static function receivedToFilter(string $value): string {
        $v = strtolower(trim($value));
        $now = new DateTime('now', new DateTimeZone('UTC'));

        switch ($v) {
            case 'today': {
                $start = (clone $now)->setTime(0, 0, 0)->format('Y-m-d\TH:i:s\Z');
                $end   = (clone $now)->setTime(23, 59, 59)->format('Y-m-d\TH:i:s\Z');
                return "receivedDateTime ge $start and receivedDateTime le $end";
            }
            case 'yesterday': {
                $y     = (clone $now)->modify('-1 day');
                $start = (clone $y)->setTime(0, 0, 0)->format('Y-m-d\TH:i:s\Z');
                $end   = (clone $y)->setTime(23, 59, 59)->format('Y-m-d\TH:i:s\Z');
                return "receivedDateTime ge $start and receivedDateTime le $end";
            }
            case 'this week': {
                $dow   = (int)$now->format('N'); // 1=Mon .. 7=Sun
                $start = (clone $now)->modify('-' . ($dow - 1) . ' days')->setTime(0,0,0)->format('Y-m-d\TH:i:s\Z');
                $end   = $now->format('Y-m-d\TH:i:s\Z');
                return "receivedDateTime ge $start and receivedDateTime le $end";
            }
            case 'last week': {
                $dow      = (int)$now->format('N');
                $thisMonday = (clone $now)->modify('-' . ($dow - 1) . ' days')->setTime(0,0,0);
                $lastMonday = (clone $thisMonday)->modify('-7 days');
                $lastSunday = (clone $thisMonday)->modify('-1 second');
                return "receivedDateTime ge " . $lastMonday->format('Y-m-d\TH:i:s\Z')
                     . " and receivedDateTime le " . $lastSunday->format('Y-m-d\TH:i:s\Z');
            }
            case 'this month': {
                $start = (clone $now)->modify('first day of this month')->setTime(0,0,0)->format('Y-m-d\TH:i:s\Z');
                $end   = $now->format('Y-m-d\TH:i:s\Z');
                return "receivedDateTime ge $start and receivedDateTime le $end";
            }
            case 'last month': {
                $start = (clone $now)->modify('first day of last month')->setTime(0,0,0)->format('Y-m-d\TH:i:s\Z');
                $end   = (clone $now)->modify('last day of last month')->setTime(23,59,59)->format('Y-m-d\TH:i:s\Z');
                return "receivedDateTime ge $start and receivedDateTime le $end";
            }
            default: {
                // Try MM/DD/YYYY or YYYY-MM-DD
                $date = false;
                if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) {
                    $date = DateTime::createFromFormat('m/d/Y', $m[1].'/'.$m[2].'/'.$m[3], new DateTimeZone('UTC'));
                } elseif (preg_match('#^\d{4}-\d{2}-\d{2}$#', $v)) {
                    $date = DateTime::createFromFormat('Y-m-d', $v, new DateTimeZone('UTC'));
                }
                if ($date) {
                    $start = (clone $date)->setTime(0,0,0)->format('Y-m-d\TH:i:s\Z');
                    $end   = (clone $date)->setTime(23,59,59)->format('Y-m-d\TH:i:s\Z');
                    return "receivedDateTime ge $start and receivedDateTime le $end";
                }
                return '';
            }
        }
    }
}
