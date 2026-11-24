<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 05/11/2026
 * DESCRIPTION : Persist POST-only portal API activity (30-day retention) into dbo.sap_report_auditLog
 */
declare(strict_types=1);

final class ApiAuditLogService
{
    private const TABLE = 'sap_report_auditLog';
    private const MAX_PAYLOAD_CHARS = 65000;
    private const RETENTION_DAYS = 30;

    private static bool $ensured = false;
    private static bool $disabled = false;

    /** @var array{url:?string,payload:?string,response:?string}|null */
    private static ?array $sapContext = null;

    /** Action keys used by the audit page filter */
    public const ACTION_FILTERS = [
        'portal_revert'   => 'Portal Revert',
        'sap_revert'      => 'SAP Revert',
        'passpo_approved' => 'Pass PO Approved',
        'esign'           => 'E-Sign',
        'uac_pobutton'    => 'User Access Control (PO Button)',
    ];

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
     * Remember the latest SAP outbound call for the current portal request.
     * Attached to the next ApiAuditLogService::log() insert.
     *
     * @param array|string|null $payload
     * @param array|string|null $response
     */
    public static function rememberSapCall(?string $url, array|string|null $payload = null, array|string|null $response = null): void
    {
        self::$sapContext = [
            'url'      => $url !== null && $url !== '' ? mb_substr($url, 0, 2000) : null,
            'payload'  => self::encodePayload($payload),
            'response' => self::encodePayload($response),
        ];
    }

    /**
     * @return array{url:?string,payload:?string,response:?string}|null
     */
    private static function takeSapContext(): ?array
    {
        $ctx = self::$sapContext;
        self::$sapContext = null;
        return $ctx;
    }

    /**
     * Resolve a stable action key for audit filters.
     */
    public static function resolveActionKey(?string $url = null, ?string $requestJson = null): string
    {
        $path = strtolower((string) (parse_url($url ?? self::requestUrl(), PHP_URL_PATH) ?: ''));
        $path = preg_replace('#^/sap_reports#', '', $path) ?: $path;

        if (str_contains($path, 'portal-revert')) {
            return 'portal_revert';
        }
        if (str_contains($path, 'esign')) {
            return 'esign';
        }
        if (str_contains($path, 'user-access-control') && str_contains($path, 'save')) {
            return 'uac_pobutton';
        }

        $isPass = str_contains($path, 'purchase-order/pass')
            || preg_match('#purchase-order/pass$#', $path)
            || str_ends_with(rtrim($path, '/'), 'purchase-order/pass');
        if ($isPass && !str_contains($path, 'pass-log')) {
            $action = '';
            if (is_string($requestJson) && $requestJson !== '') {
                $decoded = json_decode($requestJson, true);
                if (is_array($decoded)) {
                    $action = strtolower(trim((string) ($decoded['action'] ?? '')));
                }
            }
            if ($action === '' && !empty($_POST['action'])) {
                $action = strtolower(trim((string) $_POST['action']));
            }
            if (in_array($action, ['revert', 'unapprove', 'sap_revert'], true)) {
                return 'sap_revert';
            }
            return 'passpo_approved';
        }

        return '';
    }

    /**
     * Human page / module label from the request URL (+ action).
     */
    public static function pageNameFromUrl(?string $url = null, ?string $actionKey = null): string
    {
        if ($actionKey !== null && $actionKey !== '' && isset(self::ACTION_FILTERS[$actionKey])) {
            return self::ACTION_FILTERS[$actionKey];
        }

        $path = (string) (parse_url($url ?? self::requestUrl(), PHP_URL_PATH) ?: '/');
        $path = preg_replace('#^/sap_reports#', '', $path) ?: '/';
        $path = strtolower(trim($path, '/'));

        $map = [
            '' => 'Reports Hub',
            'purchase-order' => 'Purchase Order Report',
            'purchase-order/pass' => 'Pass PO Approved',
            'purchase-order/portal-revert' => 'Portal Revert',
            'purchase-order/esign-save' => 'E-Sign',
            'purchase-order/esign-signature' => 'E-Sign',
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
            'api/user-access-control/save' => 'User Access Control (PO Button)',
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
     * Capture request payload (form / cached raw JSON body). GET is ignored.
     */
    public static function captureRequestPayload(): ?string
    {
        $method = self::requestMethod();
        if (in_array($method, ['GET', 'HEAD'], true)) {
            return null;
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
     * Save one POST API activity row. Never throws to callers.
     * Non-POST methods are skipped. Retention purge runs opportunistically.
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
            $finalMethod = strtoupper(trim((string) ($method ?? self::requestMethod())));
            if ($finalMethod === '') {
                $finalMethod = 'GET';
            }
            // Audit log stores POST (mutating) portal API calls only.
            if ($finalMethod !== 'POST') {
                self::takeSapContext();
                return;
            }

            require_once base_path('app/core/Database.php');
            $pdo = Database::connection();
            self::ensureTable($pdo);
            self::purgeExpired($pdo);

            $user = function_exists('portal_user') ? portal_user() : null;
            $username = (string) ($user['username'] ?? ($_SESSION['username'] ?? ''));
            $userId = (string) ($user['id'] ?? ($_SESSION['user_id'] ?? ''));
            $userEmail = (string) ($user['email'] ?? ($_SESSION['email'] ?? $_SESSION['user_email'] ?? ''));

            $finalUrl = $url ?? self::requestUrl();
            $requestJson = $request !== null ? self::encodePayload($request) : self::captureRequestPayload();
            $responseJson = self::encodePayload($response);
            $actionKey = self::resolveActionKey($finalUrl, $requestJson);
            $msg = $statusMessage ?? self::statusMessage($status, $response);
            $page = $pageName ?? self::pageNameFromUrl($finalUrl, $actionKey !== '' ? $actionKey : null);

            $sap = self::takeSapContext() ?? ['url' => null, 'payload' => null, 'response' => null];

            $stmt = $pdo->prepare(
                'INSERT INTO ' . self::TABLE . '
                    (url, status, status_message, method, page_name, action_key,
                     username, user_id, user_email, created_at, ip,
                     request_payload, response_payload,
                     sap_api_url, sap_api_payload, sap_api_response)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, SYSUTCDATETIME(), ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                mb_substr($finalUrl, 0, 2000),
                $status,
                mb_substr($msg, 0, 500),
                mb_substr($finalMethod, 0, 16),
                mb_substr($page, 0, 150),
                $actionKey !== '' ? mb_substr($actionKey, 0, 64) : null,
                $username !== '' ? mb_substr($username, 0, 100) : null,
                $userId !== '' ? mb_substr($userId, 0, 100) : null,
                $userEmail !== '' ? mb_substr($userEmail, 0, 255) : null,
                mb_substr(self::clientIp(), 0, 64) ?: null,
                $requestJson,
                $responseJson,
                $sap['url'] ?? null,
                $sap['payload'] ?? null,
                $sap['response'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log('ApiAuditLogService::log failed: ' . $e->getMessage());
        }
    }

    /**
     * Latest POST rows within the 30-day retention window (IST display via mapRow).
     *
     * @return list<array<string, mixed>>
     */
    public static function latest(int $limit = 2000): array
    {
        $limit = max(1, min(5000, $limit));
        try {
            require_once base_path('app/core/Database.php');
            $pdo = Database::connection();
            self::ensureTable($pdo);
            self::purgeExpired($pdo);

            $stmt = $pdo->query(
                'SELECT TOP ' . (int) $limit . '
                    id, url, status, status_message, method, page_name, action_key,
                    username, user_id, user_email, created_at, ip,
                    request_payload, response_payload,
                    sap_api_url, sap_api_payload, sap_api_response
                 FROM ' . self::TABLE . '
                 WHERE UPPER(ISNULL(method, \'\')) = \'POST\'
                   AND created_at >= DATEADD(day, -' . (int) self::RETENTION_DAYS . ', SYSUTCDATETIME())
                 ORDER BY id DESC'
            );
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            if (!is_array($rows)) {
                return [];
            }
            return array_map([self::class, 'mapRow'], $rows);
        } catch (Throwable $e) {
            error_log('ApiAuditLogService::latest failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Delete rows older than retention (and leftover non-POST rows).
     */
    public static function purgeExpired(?PDO $pdo = null): int
    {
        try {
            if ($pdo === null) {
                require_once base_path('app/core/Database.php');
                $pdo = Database::connection();
            }
            self::ensureTable($pdo);
            $deleted = 0;

            $stmt = $pdo->exec(
                'DELETE FROM ' . self::TABLE . '
                 WHERE created_at < DATEADD(day, -' . (int) self::RETENTION_DAYS . ', SYSUTCDATETIME())'
            );
            if (is_int($stmt) && $stmt > 0) {
                $deleted += $stmt;
            }

            // Keep table aligned with POST-only policy.
            $stmt2 = $pdo->exec(
                "DELETE FROM " . self::TABLE . "
                 WHERE UPPER(ISNULL(method, '')) <> 'POST'"
            );
            if (is_int($stmt2) && $stmt2 > 0) {
                $deleted += $stmt2;
            }

            return $deleted;
        } catch (Throwable $e) {
            error_log('ApiAuditLogService::purgeExpired failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Chart + KPI aggregates for the current row set (already Mumbai-local).
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public static function buildStats(array $rows): array
    {
        $ok = 0;
        $bad = 0;
        $methods = [];
        $byDay = [];
        $byMethod = [];
        $byPage = [];
        $byAction = [];
        foreach ($rows as $row) {
            $status = (int) ($row['status'] ?? 0);
            if ($status >= 200 && $status < 300) {
                $ok++;
            } elseif ($status >= 400) {
                $bad++;
            }
            $method = strtoupper(trim((string) ($row['method'] ?? 'POST'))) ?: 'POST';
            $methods[$method] = true;
            $byMethod[$method] = ($byMethod[$method] ?? 0) + 1;
            $day = substr((string) ($row['created_at'] ?? ''), 0, 10);
            if ($day !== '') {
                $byDay[$day] = ($byDay[$day] ?? 0) + 1;
            }
            $page = trim((string) ($row['page_name'] ?? '')) ?: 'Unknown';
            $byPage[$page] = ($byPage[$page] ?? 0) + 1;
            $action = trim((string) ($row['action_key'] ?? ''));
            if ($action !== '') {
                $byAction[$action] = ($byAction[$action] ?? 0) + 1;
            }
        }
        ksort($byDay);
        arsort($byMethod);
        arsort($byPage);
        return [
            'total' => count($rows),
            'success' => $ok,
            'errors' => $bad,
            'methods' => array_keys($methods),
            'retention_days' => self::RETENTION_DAYS,
            'by_day' => array_map(
                static fn ($day, $count) => ['day' => $day, 'count' => (int) $count],
                array_keys($byDay),
                array_values($byDay)
            ),
            'by_method' => array_map(
                static fn ($method, $count) => ['method' => $method, 'count' => (int) $count],
                array_keys($byMethod),
                array_values($byMethod)
            ),
            'by_page' => array_map(
                static fn ($page, $count) => ['page' => $page, 'count' => (int) $count],
                array_keys(array_slice($byPage, 0, 8, true)),
                array_values(array_slice($byPage, 0, 8, true))
            ),
            'by_action' => $byAction,
            'action_filters' => self::ACTION_FILTERS,
            'timezone' => function_exists('sap_reports_app_timezone') ? sap_reports_app_timezone() : 'Asia/Kolkata',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapRow(array $row): array
    {
        $createdRaw = $row['created_at'] ?? null;
        $created = function_exists('sap_reports_to_mumbai')
            ? sap_reports_to_mumbai($createdRaw)
            : (string) ($createdRaw ?? '');
        $actionKey = $row['action_key'] !== null ? (string) $row['action_key'] : '';
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'url' => (string) ($row['url'] ?? ''),
            'portal_api_url' => (string) ($row['url'] ?? ''),
            'status' => (int) ($row['status'] ?? 0),
            'status_message' => $row['status_message'] !== null ? (string) $row['status_message'] : null,
            'method' => strtoupper(trim((string) ($row['method'] ?? 'POST'))) ?: 'POST',
            'page_name' => $row['page_name'] !== null ? (string) $row['page_name'] : null,
            'action_key' => $actionKey !== '' ? $actionKey : null,
            'action_label' => ($actionKey !== '' && isset(self::ACTION_FILTERS[$actionKey]))
                ? self::ACTION_FILTERS[$actionKey]
                : null,
            'username' => $row['username'] !== null ? (string) $row['username'] : null,
            'user_id' => $row['user_id'] !== null ? (string) $row['user_id'] : null,
            'user_email' => isset($row['user_email']) && $row['user_email'] !== null
                ? (string) $row['user_email']
                : null,
            'usermailid' => isset($row['user_email']) && $row['user_email'] !== null
                ? (string) $row['user_email']
                : null,
            'created_at' => $created !== '' ? $created : null,
            'createdon' => $created !== '' ? $created : null,
            'created_at_utc' => self::normalizeUtcStamp($createdRaw),
            'ip' => $row['ip'] !== null ? (string) $row['ip'] : null,
            'request_payload' => $row['request_payload'] !== null ? (string) $row['request_payload'] : null,
            'response_payload' => $row['response_payload'] !== null ? (string) $row['response_payload'] : null,
            'portal_api_payload' => $row['request_payload'] !== null ? (string) $row['request_payload'] : null,
            'portal_api_response' => $row['response_payload'] !== null ? (string) $row['response_payload'] : null,
            'sap_api_url' => isset($row['sap_api_url']) && $row['sap_api_url'] !== null
                ? (string) $row['sap_api_url']
                : null,
            'sap_api_payload' => isset($row['sap_api_payload']) && $row['sap_api_payload'] !== null
                ? (string) $row['sap_api_payload']
                : null,
            'sap_api_response' => isset($row['sap_api_response']) && $row['sap_api_response'] !== null
                ? (string) $row['sap_api_response']
                : null,
        ];
    }

    private static function normalizeUtcStamp($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        $s = trim((string) $value);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $s, $m)) {
            return $m[1] . ' ' . $m[2];
        }
        return $s !== '' ? $s : null;
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
                    [action_key] NVARCHAR(64) NULL,
                    [username] NVARCHAR(100) NULL,
                    [user_id] NVARCHAR(100) NULL,
                    [user_email] NVARCHAR(255) NULL,
                    [created_at] DATETIME2 NOT NULL CONSTRAINT [DF_sap_report_auditLog_created] DEFAULT SYSUTCDATETIME(),
                    [ip] NVARCHAR(64) NULL,
                    [request_payload] NVARCHAR(MAX) NULL,
                    [response_payload] NVARCHAR(MAX) NULL,
                    [sap_api_url] NVARCHAR(2000) NULL,
                    [sap_api_payload] NVARCHAR(MAX) NULL,
                    [sap_api_response] NVARCHAR(MAX) NULL,
                    CONSTRAINT [PK_sap_report_auditLog] PRIMARY KEY CLUSTERED ([id])
                );
                CREATE NONCLUSTERED INDEX [IX_sap_report_auditLog_created]
                    ON [dbo].[sap_report_auditLog] ([created_at] DESC);
                CREATE NONCLUSTERED INDEX [IX_sap_report_auditLog_action]
                    ON [dbo].[sap_report_auditLog] ([action_key], [created_at] DESC);
            END
        ");

        $alters = [
            'status_message'   => 'NVARCHAR(500) NULL',
            'method'           => 'NVARCHAR(16) NULL',
            'page_name'        => 'NVARCHAR(150) NULL',
            'action_key'       => 'NVARCHAR(64) NULL',
            'request_payload'  => 'NVARCHAR(MAX) NULL',
            'user_email'       => 'NVARCHAR(255) NULL',
            'sap_api_url'      => 'NVARCHAR(2000) NULL',
            'sap_api_payload'  => 'NVARCHAR(MAX) NULL',
            'sap_api_response' => 'NVARCHAR(MAX) NULL',
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

        try {
            $pdo->exec("
                IF NOT EXISTS (
                    SELECT 1 FROM sys.indexes
                    WHERE name = N'IX_sap_report_auditLog_action'
                      AND object_id = OBJECT_ID(N'dbo.sap_report_auditLog')
                )
                CREATE NONCLUSTERED INDEX [IX_sap_report_auditLog_action]
                    ON [dbo].[sap_report_auditLog] ([action_key], [created_at] DESC);
            ");
        } catch (Throwable $e) {
            error_log('ApiAuditLogService index action: ' . $e->getMessage());
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
?>
