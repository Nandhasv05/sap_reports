<?php
/**
 * AUTHOR : NANDHAKUMAR
 * CREATED : 02/11/2026
 * UPDATED : 03/11/2026
 * DESCRIPTION : Database-driven Audit Log for ZCOST save, edit, approve, and creation events
 **/
declare(strict_types=1);

namespace App;

require_once __DIR__ . '/Database.php';

/**
 * Persists and retrieves ZCOST audit trails to/from MSSQL database (CLIENT_API_LIVE.dbo.zcost_audit_log)
 * with a resilient local JSONL file fallback.
 */
class AuditLog
{
    private string $logFile;
    private int $maxEntries;

    public function __construct(?string $logFile = null, int $maxEntries = 500)
    {
        $dir = is_dir(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'zcost_audit')
            ? (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'zcost_audit')
            : (dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'audit');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $this->logFile = $logFile ?: ($dir . DIRECTORY_SEPARATOR . 'zcost_saves.jsonl');
        $this->maxEntries = max(50, $maxEntries);
    }

    /**
     * Log one audit event to database and fallback file.
     *
     * @param array<string, mixed> $payload Cost sheet header snapshot
     * @param array<string, mixed> $saveResult Result from SapClient::saveCostSheet
     * @return array<string, mixed> Written audit entry
     */
    public function logSave(array $payload, array $saveResult, string $action = 'save'): array
    {
        $fab = $this->countNav($payload, 'to_Fabric');
        $trim = $this->countNav($payload, 'to_Trim');
        $pack = $this->countNav($payload, 'to_Packing');
        $special = $this->countNav($payload, 'to_Special');

        $user = PortalAuth::currentUser();
        $username = !empty($user['username']) && $user['username'] !== 'Guest' ? (string)$user['username'] : $this->resolveUser();
        $userId = !empty($user['user_id']) ? (int)$user['user_id'] : null;

        $entry = [
            'id' => bin2hex(random_bytes(8)),
            'ts' => date('c'),
            'action' => $action,
            'user' => $username,
            'user_id' => $userId,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'vbeln' => (string)($payload['Vbeln'] ?? ''),
            'posnr' => (string)($payload['Posnr'] ?? ''),
            'approve' => (string)($payload['Approve'] ?? ''),
            'cnv' => (string)($payload['Cnv'] ?? ''),
            'cnvf' => (string)($payload['Cnvf'] ?? ''),
            'sellprice' => (string)($payload['Sellprice'] ?? ''),
            'subtot' => (string)($payload['Subtot'] ?? ''),
            'quota' => (string)($payload['Quota'] ?? ''),
            'lines' => [
                'fabric' => $fab,
                'trim' => $trim,
                'packing' => $pack,
                'special' => $special,
                'total' => $fab + $trim + $pack + $special,
            ],
            'success' => !empty($saveResult['success']),
            'source' => (string)($saveResult['source'] ?? ''),
            'http_code' => (int)($saveResult['http_code'] ?? $saveResult['sap_http_code'] ?? 0),
            'message' => (string)($saveResult['message'] ?? $saveResult['error'] ?? ''),
            'method' => strtoupper((string)($saveResult['method'] ?? 'POST')),
            'url' => (string)($saveResult['url'] ?? ''),
            'payload' => $this->compactPayload($saveResult['payload'] ?? $payload),
            'csrf_method' => strtoupper((string)($saveResult['csrf_method'] ?? 'GET')),
            'csrf_url' => (string)($saveResult['csrf_url'] ?? ''),
        ];

        // 1. Persist to Database table zcost_audit_log
        $dbOk = Database::logAudit($entry);
        $entry['db_saved'] = $dbOk;

        // 2. Also append to JSONL file for redundant backup
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line !== false) {
            $dir = dirname($this->logFile);
            if (is_dir($dir) || @mkdir($dir, 0755, true)) {
                @file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
                $this->trimFile();
            }
        }

        return $entry;
    }

    /**
     * Retrieve recent audit entries, querying the database table first.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 40, ?string $vbeln = null): array
    {
        // 1. Try fetching from Database table first
        $dbEntries = Database::getAuditLogs($limit, $vbeln);
        if (!empty($dbEntries)) {
            return $dbEntries;
        }

        // 2. Fallback to file-based logs if DB returned empty or wasn't connected
        $files = [$this->logFile];
        $fallback = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'zcost_saves.jsonl';
        if ($fallback !== $this->logFile) {
            $files[] = $fallback;
        }

        $entries = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $raw = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($raw) || $raw === []) {
                continue;
            }
            foreach (array_reverse($raw) as $line) {
                $decoded = json_decode($line, true);
                if (!is_array($decoded)) {
                    continue;
                }
                if ($vbeln !== null && $vbeln !== '' && (string)($decoded['vbeln'] ?? '') !== $vbeln) {
                    continue;
                }
                $entries[] = $decoded;
            }
        }

        usort($entries, static function (array $a, array $b): int {
            return strcmp((string)($b['ts'] ?? ''), (string)($a['ts'] ?? ''));
        });

        $seen = [];
        $unique = [];
        foreach ($entries as $entry) {
            $id = (string)($entry['id'] ?? '');
            if ($id !== '' && isset($seen[$id])) {
                continue;
            }
            if ($id !== '') {
                $seen[$id] = true;
            }
            $unique[] = $entry;
            if (count($unique) >= $limit) {
                break;
            }
        }

        return $unique;
    }

    private function countNav(array $payload, string $key): int
    {
        if (!isset($payload[$key]['results']) || !is_array($payload[$key]['results'])) {
            return 0;
        }
        return count($payload[$key]['results']);
    }

    private function compactPayload($payload): ?array
    {
        if (!is_array($payload)) {
            return null;
        }
        if (isset($payload['d']) && is_array($payload['d'])) {
            $payload = $payload['d'];
        }
        $out = $payload;
        foreach (['to_Fabric', 'to_Trim', 'to_Packing', 'to_Special'] as $nav) {
            if (!isset($out[$nav]['results']) || !is_array($out[$nav]['results'])) {
                continue;
            }
            $out[$nav] = ['results' => array_values($out[$nav]['results'])];
        }
        return $out;
    }

    private function resolveUser(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $candidates = [
            $_SESSION['username'] ?? null,
            $_SESSION['user'] ?? null,
            $_SESSION['user_name'] ?? null,
            $_SERVER['HTTP_X_USER'] ?? null,
        ];
        foreach ($candidates as $u) {
            if (is_string($u) && trim($u) !== '') {
                return trim($u);
            }
        }
        return 'Admin';
    }

    private function trimFile(): void
    {
        if (!is_file($this->logFile)) {
            return;
        }
        $raw = @file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($raw) || count($raw) <= $this->maxEntries) {
            return;
        }
        $keep = array_slice($raw, -$this->maxEntries);
        @file_put_contents($this->logFile, implode(PHP_EOL, $keep) . PHP_EOL, LOCK_EX);
    }
}
