<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/10/2026
 * DESCRIPTION : Persist sap_reports API audit rows (url, status, user, ip, payload, datetime only)
 */
declare(strict_types=1);

final class ApiAuditLogService
{
    private const TABLE = 'sap_reports_api_audit_log';
    private const MAX_PAYLOAD_CHARS = 65000;
    private static bool $ensured = false;
    private static bool $disabled = false;

    public static function clientIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['HTTP_X_REAL_IP'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];
        foreach ($candidates as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            // X-Forwarded-For may be a list
            $ip = trim(explode(',', $raw)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return '';
    }

    public static function requestUrl(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if (strlen($uri) > 2000) {
            return substr($uri, 0, 2000);
        }
        return $uri !== '' ? $uri : '/';
    }

    /**
     * Save one API audit row. Never throws to callers.
     *
     * @param array|string|null $payload Response body (array encoded to JSON)
     */
    public static function log(int $status, array|string|null $payload = null, ?string $url = null): void
    {
        if (self::$disabled) {
            return;
        }

        try {
            require_once base_path('app/core/Database.php');
            $pdo = Database::connection();
            self::ensureTable($pdo);

            $user = function_exists('portal_user') ? portal_user() : null;
            $username = (string) ($user['username'] ?? ($_SESSION['username'] ?? ''));
            $userId = (string) ($user['id'] ?? ($_SESSION['user_id'] ?? ''));

            $payloadJson = self::encodePayload($payload);

            $stmt = $pdo->prepare(
                'INSERT INTO ' . self::TABLE . ' (url, status, username, user_id, created_at, ip, response_payload)
                 VALUES (?, ?, ?, ?, SYSUTCDATETIME(), ?, ?)'
            );
            $stmt->execute([
                $url ?? self::requestUrl(),
                $status,
                $username !== '' ? mb_substr($username, 0, 100) : null,
                $userId !== '' ? mb_substr($userId, 0, 100) : null,
                mb_substr(self::clientIp(), 0, 64) ?: null,
                $payloadJson,
            ]);
        } catch (Throwable $e) {
            // Audit must never break API responses
            error_log('ApiAuditLogService::log failed: ' . $e->getMessage());
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function latest(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        try {
            require_once base_path('app/core/Database.php');
            $pdo = Database::connection();
            self::ensureTable($pdo);
            $stmt = $pdo->query(
                'SELECT TOP ' . (int) $limit . '
                    id, url, status, username, user_id, created_at, ip, response_payload
                 FROM ' . self::TABLE . '
                 ORDER BY id DESC'
            );
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            error_log('ApiAuditLogService::latest failed: ' . $e->getMessage());
            return [];
        }
    }

    private static function encodePayload(array|string|null $payload): ?string
    {
        if ($payload === null) {
            return null;
        }
        if (is_array($payload)) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } else {
            $json = (string) $payload;
        }
        if ($json === false || $json === '') {
            return null;
        }
        if (strlen($json) > self::MAX_PAYLOAD_CHARS) {
            return substr($json, 0, self::MAX_PAYLOAD_CHARS) . '…[truncated]';
        }
        return $json;
    }

    private static function ensureTable(PDO $pdo): void
    {
        if (self::$ensured) {
            return;
        }
        $sql = "IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = N'sap_reports_api_audit_log')
BEGIN
    CREATE TABLE dbo.sap_reports_api_audit_log (
        id BIGINT IDENTITY(1,1) NOT NULL,
        url NVARCHAR(2000) NOT NULL,
        status INT NOT NULL,
        username NVARCHAR(100) NULL,
        user_id NVARCHAR(100) NULL,
        created_at DATETIME2 NOT NULL CONSTRAINT DF_sap_reports_api_audit_log_created DEFAULT SYSUTCDATETIME(),
        ip NVARCHAR(64) NULL,
        response_payload NVARCHAR(MAX) NULL,
        CONSTRAINT PK_sap_reports_api_audit_log PRIMARY KEY CLUSTERED (id)
    );
    CREATE NONCLUSTERED INDEX IX_sap_reports_api_audit_log_created
        ON dbo.sap_reports_api_audit_log (created_at DESC);
END";
        $pdo->exec($sql);
        self::$ensured = true;
    }

    /** Skip logging while serving the audit-log list itself (avoids noise/recursion). */
    public static function withoutLogging(callable $fn): mixed
    {
        $prev = self::$disabled;
        self::$disabled = true;
        try {
            return $fn();
        } finally {
            self::$disabled = $prev;
        }
    }
}
