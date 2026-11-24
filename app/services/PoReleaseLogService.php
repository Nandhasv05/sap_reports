<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 18/11/2026
 * DESCRIPTION : PO release status updates → dbo.po_release_log (Draft / Review / Released)
 */
require_once base_path('app/core/Database.php');

class PoReleaseLogService
{
    private const TABLE = 'po_release_log';

    public const STATUS_DRAFT = 'Draft';
    public const STATUS_REVIEW = 'Review';
    public const STATUS_RELEASED = 'Released';

    /** @var bool|null */
    private static ?bool $tableReady = null;

    /** @return list<string> */
    public static function allowedStatuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_REVIEW, self::STATUS_RELEASED];
    }

    public static function normalizeStatus(string $status): string
    {
        $raw = strtolower(trim($status));
        if ($raw === '') {
            return '';
        }
        if ($raw === 'draft') {
            return self::STATUS_DRAFT;
        }
        if ($raw === 'review' || $raw === 'passed' || $raw === 'pass') {
            return self::STATUS_REVIEW;
        }
        if (in_array($raw, ['released', 'release', 'related', 'relasted', 'moved', 'move'], true)) {
            return self::STATUS_RELEASED;
        }
        foreach (self::allowedStatuses() as $allowed) {
            if (strcasecmp($allowed, trim($status)) === 0) {
                return $allowed;
            }
        }
        return '';
    }

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
     * Ensure dbo.po_release_log exists. Never throws.
     * Live DB user may lack CREATE TABLE — DBA can create once using provided DDL.
     */
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
                            [id] INT IDENTITY(1,1) NOT NULL,
                            [po_number] VARCHAR(50) NOT NULL,
                            [status] VARCHAR(50) NULL,
                            [created_on] DATETIME NOT NULL CONSTRAINT [DF_po_release_log_created] DEFAULT GETDATE(),
                            [created_by] VARCHAR(100) NULL,
                            [remarks] NVARCHAR(500) NULL,
                            CONSTRAINT [PK_po_release_log] PRIMARY KEY CLUSTERED ([id])
                        );
                        CREATE NONCLUSTERED INDEX [IX_po_release_log_po_created]
                            ON [dbo].[" . self::TABLE . "] ([po_number], [created_on] DESC);
                    END
                ");
                self::$tableReady = null;
                $this->tableExists($pdo);
            } catch (Throwable $createErr) {
                error_log('PoReleaseLogService ensureTable: ' . $createErr->getMessage());
                self::$tableReady = false;
            }
        } catch (Throwable $e) {
            error_log('PoReleaseLogService ensureTable connect: ' . $e->getMessage());
            self::$tableReady = false;
        }
    }

    /**
     * @return array{success:bool,id:?int,error:?string,status:?string}
     */
    public function save(string $poNumber, string $status, string $createdBy = '', string $remarks = ''): array
    {
        $po = preg_replace('/\D/', '', $poNumber) ?? '';
        $po = ltrim($po, '0');
        if ($po === '' || !preg_match('/^\d{1,10}$/', $po)) {
            return ['success' => false, 'id' => null, 'error' => 'Invalid purchase order.', 'status' => null];
        }
        $statusNorm = self::normalizeStatus($status);
        if ($statusNorm === '') {
            return [
                'success' => false,
                'id' => null,
                'error' => 'Status must be Draft, Review, or Released.',
                'status' => null,
            ];
        }
        $this->ensureTable();
        if (!$this->tableExists()) {
            return [
                'success' => false,
                'id' => null,
                'error' => 'po_release_log table is not available.',
                'status' => null,
            ];
        }

        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare("
                INSERT INTO [dbo].[" . self::TABLE . "]
                    ([po_number], [status], [created_by], [remarks])
                VALUES
                    (?, ?, ?, ?)
            ");
            $stmt->execute([
                $po,
                $statusNorm,
                substr(trim($createdBy), 0, 100),
                substr(trim($remarks), 0, 500),
            ]);
            $id = (int) $pdo->lastInsertId();
            return [
                'success' => true,
                'id' => $id > 0 ? $id : null,
                'error' => null,
                'status' => $statusNorm,
            ];
        } catch (Throwable $e) {
            error_log('PoReleaseLogService save: ' . $e->getMessage());
            return [
                'success' => false,
                'id' => null,
                'error' => 'Unable to save status update.',
                'status' => null,
            ];
        }
    }

    /**
     * Delete all po_release_log rows for a PO (portal revert).
     *
     * @return array{success:bool,deleted:int,error:?string}
     */
    public function deleteForPo(string $purchaseOrder): array
    {
        $po = preg_replace('/\D/', '', $purchaseOrder) ?? '';
        $norm = ltrim($po, '0');
        if ($norm === '' || !preg_match('/^\d{1,10}$/', $norm)) {
            return ['success' => false, 'deleted' => 0, 'error' => 'Invalid purchase order.'];
        }
        $this->ensureTable();
        if (!$this->tableExists()) {
            return ['success' => true, 'deleted' => 0, 'error' => null];
        }

        try {
            $pdo = Database::connection();
            $variants = array_values(array_unique(array_filter([
                $norm,
                str_pad($norm, 10, '0', STR_PAD_LEFT),
                $po,
            ])));
            $ph = implode(',', array_fill(0, count($variants), '?'));
            $stmt = $pdo->prepare("
                DELETE FROM [dbo].[" . self::TABLE . "]
                WHERE [po_number] IN ({$ph})
            ");
            $stmt->execute($variants);
            return ['success' => true, 'deleted' => (int) $stmt->rowCount(), 'error' => null];
        } catch (Throwable $e) {
            error_log('PoReleaseLogService deleteForPo: ' . $e->getMessage());
            return ['success' => false, 'deleted' => 0, 'error' => 'Unable to delete po_release_log rows.'];
        }
    }

    /**
     * @return array{status:string,created_by:string,created_on:?string,remarks:string}|null
     */
    public function latestForPo(string $purchaseOrder): ?array
    {
        $map = $this->latestStatusesForPos([$purchaseOrder]);
        $poKey = ltrim(preg_replace('/\D/', '', $purchaseOrder) ?? '', '0');
        if ($poKey === '' || !isset($map[$poKey])) {
            return null;
        }
        return $map[$poKey];
    }

    /**
     * Latest status per PO number.
     *
     * @param list<string> $purchaseOrders
     * @return array<string, array{status:string,created_by:string,created_on:?string,remarks:string}>
     */
    public function latestStatusesForPos(array $purchaseOrders): array
    {
        $keys = [];
        foreach ($purchaseOrders as $po) {
            $digits = preg_replace('/\D/', '', (string) $po) ?? '';
            $norm = ltrim($digits, '0');
            if ($norm !== '' && preg_match('/^\d{1,10}$/', $norm)) {
                $keys[$norm] = true;
            }
        }
        $poList = array_keys($keys);
        if ($poList === []) {
            return [];
        }

        $this->ensureTable();
        if (!$this->tableExists()) {
            return [];
        }

        $out = [];
        try {
            $pdo = Database::connection();
            foreach (array_chunk($poList, 200) as $chunk) {
                $variants = [];
                foreach ($chunk as $po) {
                    $variants[$po] = true;
                    $variants[str_pad($po, 10, '0', STR_PAD_LEFT)] = true;
                }
                $list = array_keys($variants);
                $ph = implode(',', array_fill(0, count($list), '?'));
                $sql = "
                    SELECT r.po_number, r.status, r.created_by, r.created_on, r.remarks
                    FROM [dbo].[" . self::TABLE . "] r
                    INNER JOIN (
                        SELECT po_number, MAX(id) AS max_id
                        FROM [dbo].[" . self::TABLE . "]
                        WHERE po_number IN ({$ph})
                        GROUP BY po_number
                    ) t ON t.max_id = r.id
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($list);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $poKey = ltrim(preg_replace('/\D/', '', (string) ($row['po_number'] ?? '')) ?? '', '0');
                    if ($poKey === '') {
                        continue;
                    }
                    $rawStatus = trim((string) ($row['status'] ?? ''));
                    $status = self::normalizeStatus($rawStatus);
                    if ($status === '' && $rawStatus !== '') {
                        $status = $rawStatus;
                    }
                    $out[$poKey] = [
                        'status'     => $status,
                        'created_by' => trim((string) ($row['created_by'] ?? '')),
                        'created_on' => isset($row['created_on']) ? (string) $row['created_on'] : null,
                        'remarks'    => trim((string) ($row['remarks'] ?? '')),
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log('PoReleaseLogService latestStatusesForPos: ' . $e->getMessage());
            return [];
        }
        return $out;
    }
}
