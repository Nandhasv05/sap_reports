<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 13/11/2026
 * DESCRIPTION : PO Costing Sent / Receive inbox (dbo.po_costing_sent_log)
 */
require_once base_path('app/core/Database.php');

class PoSentLogService
{
    private const TABLE = 'po_costing_sent_log';

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

    public function ensureTable(): void
    {
        try {
            $pdo = Database::connection();
            if ($this->tableExists($pdo)) {
                return;
            }
            try {
                $pdo->exec("
                    IF OBJECT_ID(N'[dbo].[" . self::TABLE . "]', N'U') IS NULL
                    BEGIN
                        CREATE TABLE [dbo].[" . self::TABLE . "] (
                            [id] BIGINT IDENTITY(1,1) NOT NULL,
                            [purchase_order] NVARCHAR(20) NOT NULL,
                            [from_username] NVARCHAR(100) NOT NULL,
                            [to_username] NVARCHAR(100) NOT NULL,
                            [to_email] NVARCHAR(255) NULL,
                            [remarks] NVARCHAR(MAX) NULL,
                            [status] NVARCHAR(40) NOT NULL CONSTRAINT [DF_po_costing_sent_log_status] DEFAULT (N'sent'),
                            [created_at] DATETIME2 NOT NULL CONSTRAINT [DF_po_costing_sent_log_created] DEFAULT SYSUTCDATETIME(),
                            CONSTRAINT [PK_po_costing_sent_log] PRIMARY KEY CLUSTERED ([id])
                        );
                        CREATE NONCLUSTERED INDEX [IX_po_costing_sent_log_to_created]
                            ON [dbo].[" . self::TABLE . "] ([to_username], [created_at] DESC);
                        CREATE NONCLUSTERED INDEX [IX_po_costing_sent_log_from_created]
                            ON [dbo].[" . self::TABLE . "] ([from_username], [created_at] DESC);
                        CREATE NONCLUSTERED INDEX [IX_po_costing_sent_log_po_created]
                            ON [dbo].[" . self::TABLE . "] ([purchase_order], [created_at] DESC);
                    END
                ");
                self::$tableReady = null;
                $this->tableExists($pdo);
            } catch (Throwable $createErr) {
                error_log('PoSentLogService ensureTable: ' . $createErr->getMessage());
                self::$tableReady = false;
            }
        } catch (Throwable $e) {
            error_log('PoSentLogService ensureTable connect: ' . $e->getMessage());
            self::$tableReady = false;
        }
    }

    /**
     * @param array{
     *   purchase_order:string,
     *   from_username:string,
     *   to_username:string,
     *   to_email?:string|null,
     *   remarks?:string|null,
     *   status?:string|null
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
                    'error' => 'Sent log table dbo.' . self::TABLE . ' is missing. Ask a DBA to create it.',
                ];
            }

            $po = preg_replace('/\D/', '', (string) ($row['purchase_order'] ?? '')) ?? '';
            $display = ltrim($po, '0');
            if ($display === '') {
                $display = $po;
            }
            $from = trim((string) ($row['from_username'] ?? ''));
            $to = trim((string) ($row['to_username'] ?? ''));
            if ($display === '' || $from === '' || $to === '') {
                return ['success' => false, 'id' => null, 'error' => 'purchase_order, from_username and to_username are required'];
            }

            $status = trim((string) ($row['status'] ?? 'sent'));
            if ($status === '') {
                $status = 'sent';
            }
            $email = trim((string) ($row['to_email'] ?? ''));
            $remarks = trim((string) ($row['remarks'] ?? ''));

            $stmt = $pdo->prepare("
                INSERT INTO [dbo].[" . self::TABLE . "] (
                    purchase_order, from_username, to_username, to_email, remarks, status, created_at
                )
                OUTPUT INSERTED.id
                VALUES (?, ?, ?, ?, ?, ?, SYSUTCDATETIME())
            ");
            $stmt->execute([
                $display,
                $from,
                $to,
                $email !== '' ? $email : null,
                $remarks !== '' ? $remarks : null,
                $status,
            ]);
            $id = (int) ($stmt->fetchColumn() ?: 0);

            // Also mirror into unified Pass_po_validtion (one table for send / receive / esign / approve)
            try {
                require_once base_path('app/services/PassPoValidationLogService.php');
                (new PassPoValidationLogService())->save([
                    'user_id' => $_SESSION['user_id'] ?? null,
                    'username' => $from,
                    'purchase_order' => $display,
                    'action' => 'send',
                    'status' => $status,
                    'method' => 'POST',
                    'url' => 'local://po-costing/send-mail',
                    'payload' => [
                        'from_username' => $from,
                        'to_username' => $to,
                        'to_email' => $email,
                        'remarks' => $remarks,
                        'status' => $status,
                    ],
                    'response' => [
                        'success' => true,
                        'status' => $status,
                        'inbox' => ['from' => $from, 'to' => $to],
                    ],
                    'http_code' => 200,
                    'success' => true,
                    'from_username' => $from,
                    'to_username' => $to,
                    'to_email' => $email,
                    'remarks' => $remarks,
                ]);
            } catch (Throwable $ignored) {
            }

            return ['success' => true, 'id' => $id > 0 ? $id : null, 'error' => null];
        } catch (Throwable $e) {
            return ['success' => false, 'id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Latest sent/receive log row per purchase order (for PO Validation list columns).
     *
     * @param list<string> $purchaseOrders
     * @return array<string, array{remarks:?string,status:string,from_username:?string,to_username:?string,sent_receive_status:string}>
     */
    public function latestForPurchaseOrders(array $purchaseOrders): array
    {
        $out = [];
        $keys = [];
        foreach ($purchaseOrders as $po) {
            $digits = preg_replace('/\D/', '', (string) $po) ?? '';
            $norm = ltrim($digits, '0');
            if ($norm === '') {
                $norm = $digits;
            }
            if ($norm !== '') {
                $keys[$norm] = true;
            }
        }
        $poList = array_keys($keys);
        if ($poList === []) {
            return $out;
        }

        try {
            $this->ensureTable();
            $pdo = Database::connection();
            if (!$this->tableExists($pdo)) {
                return $out;
            }
        } catch (Throwable $e) {
            return $out;
        }

        foreach (array_chunk($poList, 200) as $chunk) {
            $variants = [];
            foreach ($chunk as $po) {
                $variants[$po] = true;
                if (preg_match('/^\d+$/', $po)) {
                    $variants[str_pad($po, 10, '0', STR_PAD_LEFT)] = true;
                }
            }
            $variantList = array_keys($variants);
            $placeholders = implode(',', array_fill(0, count($variantList), '?'));
            // Newest first — keep first hit per normalized PO in PHP
            $sql = "
                SELECT TOP 4000
                    id, purchase_order, from_username, to_username, to_email, remarks, status, created_at
                FROM [dbo].[" . self::TABLE . "]
                WHERE (
                    REPLACE(REPLACE(LTRIM(RTRIM(ISNULL(purchase_order, N''))), N'#', N''), N' ', N'') IN ({$placeholders})
                    OR LTRIM(RTRIM(ISNULL(purchase_order, N''))) IN ({$placeholders})
                    OR LTRIM(STR(TRY_CONVERT(BIGINT, purchase_order), 20)) IN ({$placeholders})
                )
                ORDER BY id DESC
            ";
            try {
                $bind = array_merge($variantList, $variantList, $variantList);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($bind);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $mapped = $this->mapRow($row);
                    $po = (string) ($mapped['purchase_order'] ?? '');
                    if ($po === '' || !isset($keys[$po]) || isset($out[$po])) {
                        continue;
                    }
                    $rawStatus = strtolower((string) ($mapped['status'] ?? 'sent'));
                    $label = str_contains($rawStatus, 'receiv') ? 'Received' : 'Sent';
                    $out[$po] = [
                        'remarks' => $mapped['remarks'] ?? null,
                        'status' => (string) ($mapped['status'] ?? 'sent'),
                        'from_username' => $mapped['from_username'] ?? null,
                        'to_username' => $mapped['to_username'] ?? null,
                        'to_email' => $mapped['to_email'] ?? null,
                        'sent_receive_status' => $label,
                        'created_at' => $mapped['created_at'] ?? null,
                    ];
                }
            } catch (Throwable $e) {
                error_log('PoSentLogService latestForPurchaseOrders: ' . $e->getMessage());
                continue;
            }
        }

        return $out;
    }

    /**
     * Full sent/receive history for one PO (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function historyForPurchaseOrder(string $purchaseOrder, int $limit = 40): array
    {
        $po = preg_replace('/\D/', '', $purchaseOrder) ?? '';
        $po = ltrim($po, '0');
        if ($po === '' || !preg_match('/^\d{1,10}$/', $po)) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        try {
            $this->ensureTable();
            $pdo = Database::connection();
            if (!$this->tableExists($pdo)) {
                return [];
            }
            $variants = [$po, str_pad($po, 10, '0', STR_PAD_LEFT)];
            $variants = array_values(array_unique($variants));
            $ph = implode(',', array_fill(0, count($variants), '?'));
            $sql = "
                SELECT TOP ({$limit})
                    id, purchase_order, from_username, to_username, to_email, remarks, status, created_at
                FROM [dbo].[" . self::TABLE . "]
                WHERE (
                    REPLACE(REPLACE(LTRIM(RTRIM(ISNULL(purchase_order, N''))), N'#', N''), N' ', N'') IN ({$ph})
                    OR LTRIM(RTRIM(ISNULL(purchase_order, N''))) IN ({$ph})
                    OR LTRIM(STR(TRY_CONVERT(BIGINT, purchase_order), 20)) IN ({$ph})
                )
                ORDER BY id DESC
            ";
            $bind = array_merge($variants, $variants, $variants);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bind);
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $mapped = $this->mapRow($row);
                $rawStatus = strtolower((string) ($mapped['status'] ?? 'sent'));
                $mapped['sent_receive_status'] = str_contains($rawStatus, 'receiv') ? 'Received' : 'Sent';
                $rows[] = $mapped;
            }
            return $rows;
        } catch (Throwable $e) {
            error_log('PoSentLogService historyForPurchaseOrder: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @param array{box?:string,username?:string,limit?:int,po?:string} $filters
     * @return array{success:bool,rows:list<array<string,mixed>>,error:?string}
     */
    public function listBox(array $filters = []): array
    {
        try {
            $this->ensureTable();
            $pdo = Database::connection();
            if (!$this->tableExists($pdo)) {
                return ['success' => true, 'rows' => [], 'error' => null];
            }

            $box = strtolower(trim((string) ($filters['box'] ?? 'sent')));
            if (!in_array($box, ['sent', 'received'], true)) {
                $box = 'sent';
            }
            $username = trim((string) ($filters['username'] ?? ''));
            $limit = max(1, min(300, (int) ($filters['limit'] ?? 80)));
            $po = preg_replace('/\D/', '', (string) ($filters['po'] ?? '')) ?? '';

            $where = [];
            $params = [];
            if ($username !== '') {
                if ($box === 'received') {
                    $where[] = 'LOWER(to_username) = LOWER(?)';
                } else {
                    $where[] = 'LOWER(from_username) = LOWER(?)';
                }
                $params[] = $username;
            }
            if ($po !== '') {
                $where[] = 'purchase_order LIKE ?';
                $params[] = '%' . ltrim($po, '0') . '%';
            }
            $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            $sql = "
                SELECT TOP ({$limit})
                    id, purchase_order, from_username, to_username, to_email, remarks, status, created_at
                FROM [dbo].[" . self::TABLE . "]
                {$whereSql}
                ORDER BY created_at DESC, id DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $rows[] = $this->mapRow($row);
            }

            // Attach latest eSign / Approve / Validate status from Pass_po_validtion
            try {
                require_once base_path('app/services/PassPoValidationLogService.php');
                $pos = [];
                foreach ($rows as $r) {
                    $p = (string) ($r['purchase_order'] ?? '');
                    if ($p !== '') {
                        $pos[] = $p;
                    }
                }
                $statuses = (new PassPoValidationLogService())->latestStatusesForPos($pos);
                foreach ($rows as &$r) {
                    $p = (string) ($r['purchase_order'] ?? '');
                    $info = $statuses[$p] ?? null;
                    $r['esign_status'] = $info['esign_status'] ?? null;
                    $r['approve_status'] = $info['approve_status'] ?? null;
                    $r['validate_status'] = $info['validate_status'] ?? null;
                    $r['workflow_status'] = $info['workflow_status'] ?? null;
                    if (!empty($info['esign_status'])) {
                        $r['status'] = (string) ($r['status'] ?? 'sent');
                    }
                }
                unset($r);
            } catch (Throwable $ignored) {
            }

            return ['success' => true, 'rows' => $rows, 'error' => null];
        } catch (Throwable $e) {
            return ['success' => false, 'rows' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function mapRow(array $row): array
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
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'purchase_order' => $po,
            'from_username' => trim((string) ($row['from_username'] ?? '')) ?: null,
            'to_username' => trim((string) ($row['to_username'] ?? '')) ?: null,
            'to_email' => trim((string) ($row['to_email'] ?? '')) ?: null,
            'remarks' => trim((string) ($row['remarks'] ?? '')) ?: null,
            'status' => trim((string) ($row['status'] ?? 'sent')) ?: 'sent',
            'created_at' => $created,
        ];
    }
}
?>