<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 05/11/2026
 * DESCRIPTION : Persist all SAP Reports API activity into dbo.sap_report_auditLog
 */
declare(strict_types=1);

final class ApiAuditLogService
{
    private const TABLE = 'sap_report_auditLog';
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

    public static function requestMethod(): string
    {
        $method = strtoupper(trim((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')));
        return $method !== '' ? $method : 'GET';
    }

    /**
     * Human page / module label from the request URL.
     */
    public static function pageNameFromUrl(?string $url = null): string
    {
        $path = (string) (parse_url($url ?? self::requestUrl(), PHP_URL_PATH) ?: '/');
        $path = preg_replace('#^/sap_reports#', '', $path) ?: '/';
        $path = strtolower(trim($path, '/'));

        $map = [
            '' => 'Reports Hub',
            'purchase-order' => 'Purchase Order Report',
            'po-costing' => 'PO Costing Validation',
            'po-costing-validation' => 'PO Costing Validation',
            'sales' => 'Sales Dashboard',
            'fabric' => 'Fabric Utilization',
            'fabric-sale' => 'Fabric Sale Order',
            'production' => 'Production Report',
            'procurement' => 'Procurement Report',
            'trims' => 'Trims Utilization',
            'utilization' => 'Overall Utilization',
            'audit-log' => 'Audit Log',
            'api/audit-log' => 'Audit Log API',
            'user-access-control' => 'User Access Control',
            'api/user-access-control/get' => 'User Access Control API',
            'api/user-access-control/save' => 'User Access Control API',
            'zcost' => 'Zcost Costing Sheet',
            'zcost/api' => 'Zcost API',
            'zcost/api.php' => 'Zcost API',
            'zcost/audit' => 'Zcost Audit',
        ];

        if (isset($map[$path])) {
            return $map[$path];
        }

        foreach ($map as $key => $label) {
            if ($key !== '' && str_starts_with($path, $key)) {
                return $label;
            }
        }

        if (str_contains($path, 'table-preferences')) {
            return 'Table Preferences API';
        }
        if (str_contains($path, 'purchase-order')) {
            return 'Purchase Order / PO Costing API';
        }
        if (str_contains($path, 'api/')) {
            return 'API';
        }

        $seg = explode('/', $path)[0] ?? '';
        return $seg !== '' ? ucwords(str_replace(['-', '_'], ' ', $seg)) : 'Unknown';
    }

    public static function statusMessage(int $status, array|string|null $response = null): string
    {
        $defaults = [
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            422 => 'Unprocessable Entity',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ];
        $base = $defaults[$status] ?? ('HTTP ' . $status);

        if (is_array($response)) {
            foreach (['message', 'error', 'msg'] as $key) {
                $msg = trim((string) ($response[$key] ?? ''));
                if ($msg !== '') {
                    return mb_substr($base . ' — ' . $msg, 0, 500);
                }
            }
        }

        return $base;
    }

    /**
     * Capture request payload (query / form / cached raw JSON body).
     */
    public static function captureRequestPayload(): ?string
    {
        $method = self::requestMethod();
        if (in_array($method, ['GET', 'HEAD'], true)) {
            return !empty($_GET) ? self::encodePayload($_GET) : null;
        }
        if (!empty($_POST)) {
            return self::encodePayload($_POST);
        }
        if (isset($GLOBALS['sap_reports_raw_request_body']) && is_string($GLOBALS['sap_reports_raw_request_body'])) {
            $raw = trim($GLOBALS['sap_reports_raw_request_body']);
            return $raw !== '' ? self::clip($raw) : null;
        }
        if (function_exists('sap_reports_request_raw_body')) {
            $raw = trim((string) sap_reports_request_raw_body());
            return $raw !== '' ? self::clip($raw) : null;
        }
        return null;
    }

    /**
     * Save one API activity row. Never throws to callers.
     *
     * @param array|string|null $response Response body
     * @param array|string|null $request  Optional explicit request body
     */
    public static function log(
        int $status,
        array|string|null $response = null,
        ?string $url = null,
        ?string $method = null,
        array|string|null $request = null,
        ?string $pageName = null,
        ?string $statusMessage = null
    ): void {
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

            $finalUrl = $url ?? self::requestUrl();
            $finalMethod = strtoupper(trim((string) ($method ?? self::requestMethod())));
            if ($finalMethod === '') {
                $finalMethod = 'GET';
            }

            $requestJson = $request !== null ? self::encodePayload($request) : self::captureRequestPayload();
            $responseJson = self::encodePayload($response);
            $msg = $statusMessage ?? self::statusMessage($status, $response);
            $page = $pageName ?? self::pageNameFromUrl($finalUrl);

            $stmt = $pdo->prepare(
                'INSERT INTO ' . self::TABLE . '
                    (url, status, status_message, method, page_name, username, user_id,
                     created_at, ip, request_payload, response_payload)
                 VALUES (?, ?, ?, ?, ?, ?, ?, SYSUTCDATETIME(), ?, ?, ?)'
            );
            $stmt->execute([
                mb_substr($finalUrl, 0, 2000),
                $status,
                mb_substr($msg, 0, 500),
                mb_substr($finalMethod, 0, 16),
                mb_substr($page, 0, 150),
                $username !== '' ? mb_substr($username, 0, 100) : null,
                $userId !== '' ? mb_substr($userId, 0, 100) : null,
                mb_substr(self::clientIp(), 0, 64) ?: null,
                $requestJson,
                $responseJson,
            ]);
        } catch (Throwable $e) {
            error_log('ApiAuditLogService::log failed: ' . $e->getMessage());
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function latest(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        try {
            require_once base_path('app/core/Database.php');
            $pdo = Database::connection();
            self::ensureTable($pdo);
            $stmt = $pdo->query(
                'SELECT TOP ' . (int) $limit . '
                    id, url, status, status_message, method, page_name,
                    username, user_id, created_at, ip,
                    request_payload, response_payload
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
        return self::clip($json);
    }

    private static function clip(string $value): string
    {
        if (strlen($value) > self::MAX_PAYLOAD_CHARS) {
            return substr($value, 0, self::MAX_PAYLOAD_CHARS) . '…[truncated]';
        }
        return $value;
    }

    private static function ensureTable(PDO $pdo): void
    {
        if (self::$ensured) {
            return;
        }

        $pdo->exec("
            IF OBJECT_ID(N'[dbo].[sap_report_auditLog]', N'U') IS NULL
            BEGIN
                CREATE TABLE [dbo].[sap_report_auditLog] (
                    [id] BIGINT IDENTITY(1,1) NOT NULL,
                    [url] NVARCHAR(2000) NOT NULL,
                    [status] INT NOT NULL,
                    [status_message] NVARCHAR(500) NULL,
                    [method] NVARCHAR(16) NULL,
                    [page_name] NVARCHAR(150) NULL,
                    [username] NVARCHAR(100) NULL,
                    [user_id] NVARCHAR(100) NULL,
                    [created_at] DATETIME2 NOT NULL CONSTRAINT [DF_sap_report_auditLog_created] DEFAULT SYSUTCDATETIME(),
                    [ip] NVARCHAR(64) NULL,
                    [request_payload] NVARCHAR(MAX) NULL,
                    [response_payload] NVARCHAR(MAX) NULL,
                    CONSTRAINT [PK_sap_report_auditLog] PRIMARY KEY CLUSTERED ([id])
                );
                CREATE NONCLUSTERED INDEX [IX_sap_report_auditLog_created]
                    ON [dbo].[sap_report_auditLog] ([created_at] DESC);
            END
        ");

        // Migrate existing table — add any missing columns
        $alters = [
            'status_message' => 'NVARCHAR(500) NULL',
            'method' => 'NVARCHAR(16) NULL',
            'page_name' => 'NVARCHAR(150) NULL',
            'request_payload' => 'NVARCHAR(MAX) NULL',
        ];
        foreach ($alters as $col => $def) {
            try {
                $pdo->exec("
                    IF COL_LENGTH('dbo.sap_report_auditLog', '{$col}') IS NULL
                        ALTER TABLE [dbo].[sap_report_auditLog] ADD [{$col}] {$def}
                ");
            } catch (Throwable $e) {
                error_log("ApiAuditLogService alter {$col}: " . $e->getMessage());
            }
        }

        self::$ensured = true;
    }

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
