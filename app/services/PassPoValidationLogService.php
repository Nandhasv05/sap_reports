<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 06/11/2026
 * DESCRIPTION : Persist PASS PO / e-sign API calls into dbo.Pass_po_validtion
 */
require_once base_path('app/core/Database.php');

class PassPoValidationLogService
{
    private const TABLE = 'Pass_po_validtion';

    /** @var bool|null */
    private static ?bool $tableReady = null;

    public function tableExists(?PDO $pdo = null): bool
    {
        if (self::$tableReady === true) {
            return true;
        }
        try {
            $pdo = $pdo ?? Database::connection();
            $stmt = $pdo->query(
                "SELECT CASE WHEN OBJECT_ID(N'[dbo].[" . self::TABLE . "]', N'U') IS NULL THEN 0 ELSE 1 END AS ok"
            );
            $ok = (int) ($stmt->fetchColumn() ?: 0) === 1;
            self::$tableReady = $ok ? true : false;
            return $ok;
        } catch (Throwable $e) {
            self::$tableReady = false;
            return false;
        }
    }

    /**
     * Ensure dbo.Pass_po_validtion exists. Never throws.
     * Live DB user (client_api_user) usually lacks CREATE TABLE — skip DDL when denied.
     */
    public function ensureTable(): void
    {
        try {
            $pdo = Database::connection();
            if ($this->tableExists($pdo)) {
                $this->ensureStatusColumn($pdo);
                return;
            }

            // Only attempt CREATE when missing. Catch permission errors on live.
            try {
                $pdo->exec("
                    IF OBJECT_ID(N'[dbo].[" . self::TABLE . "]', N'U') IS NULL
                    BEGIN
                        CREATE TABLE [dbo].[" . self::TABLE . "] (
                            [id] BIGINT IDENTITY(1,1) NOT NULL,
                            [user_id] NVARCHAR(100) NULL,
                            [username] NVARCHAR(100) NULL,
                            [purchase_order] NVARCHAR(20) NOT NULL,
                            [request_id] NVARCHAR(40) NULL,
                            [action] NVARCHAR(20) NULL,
                            [status] NVARCHAR(60) NULL,
                            [value_flag] NVARCHAR(2) NULL,
                            [method] NVARCHAR(16) NULL,
                            [url] NVARCHAR(2000) NULL,
                            [payload] NVARCHAR(MAX) NULL,
                            [response] NVARCHAR(MAX) NULL,
                            [http_code] INT NULL,
                            [success] BIT NOT NULL CONSTRAINT [DF_Pass_po_validtion_success] DEFAULT (0),
                            [created_at] DATETIME2 NOT NULL CONSTRAINT [DF_Pass_po_validtion_created] DEFAULT SYSUTCDATETIME(),
                            CONSTRAINT [PK_Pass_po_validtion] PRIMARY KEY CLUSTERED ([id])
                        );
                        CREATE NONCLUSTERED INDEX [IX_Pass_po_validtion_po_created]
                            ON [dbo].[" . self::TABLE . "] ([purchase_order], [created_at] DESC);
                        CREATE NONCLUSTERED INDEX [IX_Pass_po_validtion_user_created]
                            ON [dbo].[" . self::TABLE . "] ([username], [created_at] DESC);
                    END
                ");
                self::$tableReady = null;
                if ($this->tableExists($pdo)) {
                    $this->ensureStatusColumn($pdo);
                }
            } catch (Throwable $createErr) {
                // CREATE TABLE permission denied (262) on live — expected; DBA must create once.
                error_log('PassPoValidationLogService ensureTable: ' . $createErr->getMessage());
                self::$tableReady = false;
            }
        } catch (Throwable $e) {
            error_log('PassPoValidationLogService ensureTable connect: ' . $e->getMessage());
            self::$tableReady = false;
        }
    }

    private function ensureStatusColumn(PDO $pdo): void
    {
        try {
            $pdo->exec("
                IF COL_LENGTH('dbo." . self::TABLE . "', 'status') IS NULL
                    ALTER TABLE [dbo].[" . self::TABLE . "] ADD [status] NVARCHAR(60) NULL
            ");
        } catch (Throwable $ignored) {
        }
    }

    /**
     * Human status for UI / DB (E-Sign Completed, Approved Completed, …).
     */
    public static function statusFor(string $action, bool $success, ?string $valueFlag = null): string
    {
        $action = strtolower(trim($action));
        $flag = strtoupper(trim((string) $valueFlag));
        if ($action === 'esign') {
            return $success ? 'E-Sign Completed' : 'E-Sign Failed';
        }
        if ($action === 'pass' || $flag === 'X') {
            // Local tracking label only — real approval is SAP POApprovalSet
            return $success ? 'Approved Completed' : 'Approval Failed';
        }
        if ($action === 'revert') {
            return $success ? 'Reverted' : 'Revert Failed';
        }
        return $success ? 'Completed' : 'Failed';
    }

    /**
     * @param array{
     *   user_id?:string|int|null,
     *   username?:string|null,
     *   purchase_order:string,
     *   request_id?:string|null,
     *   action?:string|null,
     *   status?:string|null,
     *   value_flag?:string|null,
     *   method?:string|null,
     *   url?:string|null,
     *   payload?:mixed,
     *   response?:mixed,
     *   http_code?:int|null,
     *   success?:bool
     * } $row
     * @return array{success:bool,id:?int,error:?string}
     */
    public function save(array $row): array
    {
        try {
            $this->ensureTable();
            $pdo = Database::connection();
            if (!$this->tableExists($pdo)) {
                return [
                    'success' => false,
                    'id' => null,
                    'error' => 'Pass log table dbo.' . self::TABLE . ' is missing. Ask a DBA to run sql/pass_po_validtion.sql.',
                ];
            }

            $po = preg_replace('/\D/', '', (string) ($row['purchase_order'] ?? '')) ?? '';
            if ($po === '') {
                return ['success' => false, 'id' => null, 'error' => 'purchase_order is required'];
            }
            $displayPo = ltrim($po, '0');
            if ($displayPo === '') {
                $displayPo = '0';
            }

            $payload = $row['payload'] ?? null;
            if (is_array($payload) || is_object($payload)) {
                $payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $response = $row['response'] ?? null;
            if (is_array($response) || is_object($response)) {
                $response = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $action = trim((string) ($row['action'] ?? ''));
            $ok = !empty($row['success']);
            $valueFlag = isset($row['value_flag']) ? (string) $row['value_flag'] : null;
            $status = trim((string) ($row['status'] ?? ''));
            if ($status === '') {
                $status = self::statusFor($action, $ok, $valueFlag);
            }

            $stmt = $pdo->prepare("
                INSERT INTO [dbo].[Pass_po_validtion] (
                    user_id, username, purchase_order, request_id, action, status, value_flag,
                    method, url, payload, response, http_code, success, created_at
                )
                OUTPUT INSERTED.id
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, SYSUTCDATETIME())
            ");
            $stmt->execute([
                $row['user_id'] !== null && $row['user_id'] !== '' ? (string) $row['user_id'] : null,
                trim((string) ($row['username'] ?? '')) !== '' ? trim((string) $row['username']) : null,
                $displayPo,
                trim((string) ($row['request_id'] ?? '')) !== '' ? trim((string) $row['request_id']) : null,
                $action !== '' ? $action : null,
                $status,
                $valueFlag,
                strtoupper(trim((string) ($row['method'] ?? 'POST'))) ?: 'POST',
                trim((string) ($row['url'] ?? '')) !== '' ? trim((string) $row['url']) : null,
                $payload !== null && $payload !== '' ? (string) $payload : null,
                $response !== null && $response !== '' ? (string) $response : null,
                isset($row['http_code']) ? (int) $row['http_code'] : null,
                $ok ? 1 : 0,
            ]);
            $id = (int) ($stmt->fetchColumn() ?: 0);
            return ['success' => true, 'id' => $id > 0 ? $id : null, 'error' => null];
        } catch (Throwable $e) {
            return ['success' => false, 'id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Latest successful PASS / e-sign rows (one row per purchase order).
     *
     * @param array{limit?:int,po?:string,username?:string,action?:string,from?:string,to?:string} $filters
     * @return array{success:bool,rows:list<array<string,mixed>>,stats:array<string,mixed>,error:?string}
     */
    public function listPassed(array $filters = []): array
    {
        try {
            $this->ensureTable();
            $pdo = Database::connection();
            if (!$this->tableExists($pdo)) {
                return [
                    'success' => true,
                    'rows' => [],
                    'stats' => ['total_pos' => 0],
                    'error' => null,
                ];
            }

            $limit = max(1, min(500, (int) ($filters['limit'] ?? 200)));
            $po = preg_replace('/\D/', '', (string) ($filters['po'] ?? '')) ?? '';
            $username = trim((string) ($filters['username'] ?? ''));
            $action = strtolower(trim((string) ($filters['action'] ?? '')));
            $from = trim((string) ($filters['from'] ?? ''));
            $to = trim((string) ($filters['to'] ?? ''));

            $where = [
                'success = 1',
                "(LOWER(ISNULL(action, '')) IN ('pass', 'esign') OR ISNULL(value_flag, '') = 'X')",
            ];
            $params = [];
            if ($po !== '') {
                $where[] = 'purchase_order LIKE ?';
                $params[] = '%' . ltrim($po, '0') . '%';
            }
            if ($username !== '') {
                $where[] = 'username LIKE ?';
                $params[] = '%' . $username . '%';
            }
            if ($action !== '' && in_array($action, ['pass', 'esign'], true)) {
                $where[] = 'LOWER(ISNULL(action, \'\')) = ?';
                $params[] = $action;
            }
            if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                $where[] = 'CAST(created_at AS DATE) >= ?';
                $params[] = $from;
            }
            if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                $where[] = 'CAST(created_at AS DATE) <= ?';
                $params[] = $to;
            }
            $whereSql = implode(' AND ', $where);

            $sql = "
                SELECT TOP ({$limit})
                    l.id,
                    l.user_id,
                    l.username,
                    l.purchase_order,
                    l.request_id,
                    l.action,
                    l.status,
                    l.value_flag,
                    l.success,
                    l.http_code,
                    l.payload,
                    l.response,
                    l.created_at
                FROM [dbo].[Pass_po_validtion] l
                INNER JOIN (
                    SELECT purchase_order, MAX(id) AS max_id
                    FROM [dbo].[Pass_po_validtion]
                    WHERE {$whereSql}
                    GROUP BY purchase_order
                ) latest ON latest.max_id = l.id
                ORDER BY l.created_at DESC, l.id DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $rows[] = $this->mapPassRow($row);
            }

            return [
                'success' => true,
                'rows' => $rows,
                'stats' => ['total_pos' => count($rows)],
                'error' => null,
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'rows' => [], 'stats' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Prefer signed PDF on network share:
     *   \\10.103.10.26\server32\{YYYY-MM-DD}\Po_esgin\povalidation\po-{po}.pdf
     *
     * @return array{has_file:bool,pdf_file:?string,pdf_url:?string,folder:?string,path:?string,network_path:?string}
     */
    public function resolveSignedPdf(string $po): array
    {
        $digits = preg_replace('/\D/', '', $po) ?? '';
        $display = ltrim($digits, '0');
        if ($display === '') {
            $display = $digits !== '' ? $digits : '';
        }
        if ($display === '') {
            return [
                'has_file' => false,
                'pdf_file' => null,
                'pdf_url' => null,
                'folder' => null,
                'path' => null,
                'network_path' => null,
            ];
        }

        $fileUrl = function_exists('url')
            ? url('purchase-order/signed-file?po=' . rawurlencode($display))
            : ('purchase-order/signed-file?po=' . rawurlencode($display));

        // Primary: network date-wise po_esign\final
        try {
            require_once base_path('app/services/PoPassApprovalService.php');
            $found = (new PoPassApprovalService())->findEsignNetworkPdf($display);
            if (!empty($found['has_file']) && !empty($found['path'])) {
                return [
                    'has_file' => true,
                    'pdf_file' => $found['pdf_file'] ?? ('po-' . $display . '.pdf'),
                    'pdf_url' => $fileUrl,
                    'folder' => $found['folder'] ?? null,
                    'path' => $found['path'],
                    'network_path' => $found['network_path'] ?? $found['path'],
                ];
            }
        } catch (Throwable $ignored) {
            // Fall through to legacy local mirror
        }

        // Legacy fallback (older saves under public/upload)
        $folderRel = 'upload/PoZcostValidation/Po-' . $display;
        $folderAbs = base_path('public/' . $folderRel);
        $candidates = [
            'PO-' . $display . '_signed.pdf',
            'po-' . $display . '_signed.pdf',
            'PO_' . $display . '_signed.pdf',
            'PO-' . $display . '.pdf',
            'po-' . $display . '.pdf',
            'PO_' . $display . '.pdf',
        ];
        $pdfFile = null;
        $absPath = null;
        foreach ($candidates as $name) {
            $try = $folderAbs . DIRECTORY_SEPARATOR . $name;
            if (is_file($try)) {
                $pdfFile = $name;
                $absPath = $try;
                break;
            }
        }
        $has = $pdfFile !== null;
        return [
            'has_file' => $has,
            'pdf_file' => $pdfFile,
            'pdf_url' => $has ? $fileUrl : null,
            'folder' => $has ? $folderRel : null,
            'path' => $absPath,
            'network_path' => null,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function mapPassRow(array $row): array
    {
        $po = ltrim(preg_replace('/\D/', '', (string) ($row['purchase_order'] ?? '')) ?? '', '0');
        if ($po === '') {
            $po = (string) ($row['purchase_order'] ?? '');
        }
        $created = function_exists('sap_reports_to_mumbai')
            ? sap_reports_to_mumbai($row['created_at'] ?? null)
            : null;
        if ($created === '') {
            $created = null;
        }
        $action = trim((string) ($row['action'] ?? '')) ?: null;
        $ok = !empty($row['success']);
        $valueFlag = isset($row['value_flag']) ? (string) $row['value_flag'] : null;
        $status = trim((string) ($row['status'] ?? ''));
        if ($status === '') {
            $status = self::statusFor((string) $action, $ok, $valueFlag);
        }
        $file = $this->resolveSignedPdf($po);

        // Prefer network_path stored in response JSON when present
        $responseRaw = $row['response'] !== null ? (string) $row['response'] : '';
        if ($responseRaw !== '' && empty($file['has_file'])) {
            $decoded = json_decode($responseRaw, true);
            if (is_array($decoded)) {
                $netPath = trim((string) ($decoded['network_path'] ?? $decoded['path'] ?? ''));
                if ($netPath !== '' && @is_file($netPath)) {
                    $file['has_file'] = true;
                    $file['pdf_file'] = basename($netPath);
                    $file['path'] = $netPath;
                    $file['network_path'] = $netPath;
                    $file['folder'] = dirname($netPath);
                    $file['pdf_url'] = function_exists('url')
                        ? url('purchase-order/signed-file?po=' . rawurlencode($po))
                        : ('purchase-order/signed-file?po=' . rawurlencode($po));
                }
            }
        }

        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'user_id' => $row['user_id'] !== null ? (string) $row['user_id'] : null,
            'username' => trim((string) ($row['username'] ?? '')) ?: null,
            'purchase_order' => $po,
            'request_id' => trim((string) ($row['request_id'] ?? '')) ?: null,
            'action' => $action,
            'status' => $status,
            'value_flag' => $valueFlag,
            'success' => $ok,
            'http_code' => isset($row['http_code']) ? (int) $row['http_code'] : null,
            'payload' => $row['payload'] !== null ? (string) $row['payload'] : null,
            'response' => $row['response'] !== null ? (string) $row['response'] : null,
            'created_at' => $created,
            'has_file' => !empty($file['has_file']),
            'pdf_file' => $file['pdf_file'] ?? null,
            'pdf_url' => $file['pdf_url'] ?? null,
            'folder' => $file['folder'] ?? null,
        ];
    }
}
