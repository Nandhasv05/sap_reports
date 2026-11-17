<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 04/11/2026
 * DESCRIPTION : Persist manual PO validate / not-validate decisions with assigned user + remarks
 */
require_once base_path('app/core/Database.php');

class PoValidationLogService
{
    public const STATUS_VALIDATE = 'VALIDATE_PO';
    public const STATUS_NOT_VALIDATE = 'NOT_VALIDATE';
    public const STATUS_COMMENT = 'COMMENT';

    private const TABLE = 'po_validation_log';

    /** @var bool|null */
    private static ?bool $tableReady = null;

    /** @var bool|null */
    private static ?bool $emailColumnReady = null;

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
            self::$tableReady = $ok;
            return $ok;
        } catch (Throwable $e) {
            self::$tableReady = false;
            return false;
        }
    }

    public function hasEmailColumn(?PDO $pdo = null): bool
    {
        if (self::$emailColumnReady === true) {
            return true;
        }
        if (self::$emailColumnReady === false) {
            return false;
        }
        try {
            $pdo = $pdo ?? Database::connection();
            $stmt = $pdo->query("
                SELECT CASE WHEN COL_LENGTH(N'dbo." . self::TABLE . "', N'assigned_email') IS NULL THEN 0 ELSE 1 END AS ok
            ");
            $ok = (int) ($stmt->fetchColumn() ?: 0) === 1;
            self::$emailColumnReady = $ok;
            return $ok;
        } catch (Throwable $e) {
            self::$emailColumnReady = false;
            return false;
        }
    }

    /** Ensure assigned_email column exists when ALTER is allowed. */
    public function ensureEmailColumn(?PDO $pdo = null): void
    {
        try {
            $pdo = $pdo ?? Database::connection();
            if (!$this->tableExists($pdo) || $this->hasEmailColumn($pdo)) {
                return;
            }
            $pdo->exec("
                IF COL_LENGTH(N'dbo." . self::TABLE . "', N'assigned_email') IS NULL
                BEGIN
                    ALTER TABLE [dbo].[" . self::TABLE . "]
                    ADD [assigned_email] NVARCHAR(150) NULL;
                END
            ");
            self::$emailColumnReady = null;
            $this->hasEmailColumn($pdo);
        } catch (Throwable $e) {
            error_log('PoValidationLogService ensureEmailColumn: ' . $e->getMessage());
            self::$emailColumnReady = false;
        }
    }

    /**
     * Ensure dbo.po_validation_log exists. Never throws.
     * Live DB user usually lacks CREATE TABLE — skip DDL when table exists or create is denied.
     */
    public function ensureTable(): void
    {
        try {
            $pdo = Database::connection();
            if ($this->tableExists($pdo)) {
                $this->ensureEmailColumn($pdo);
                return;
            }

            try {
                $pdo->exec("
                    IF OBJECT_ID(N'[dbo].[" . self::TABLE . "]', N'U') IS NULL
                    BEGIN
                        CREATE TABLE [dbo].[" . self::TABLE . "] (
                            [id] INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                            [purchase_order] NVARCHAR(20) NOT NULL,
                            [po_item] NVARCHAR(20) NULL,
                            [material] NVARCHAR(100) NULL,
                            [mat_desc] NVARCHAR(255) NULL,
                            [mat_cat] NVARCHAR(20) NULL,
                            [assigned_user_id] INT NULL,
                            [assigned_username] NVARCHAR(100) NOT NULL,
                            [assigned_email] NVARCHAR(150) NULL,
                            [validation_status] NVARCHAR(40) NOT NULL,
                            [description] NVARCHAR(MAX) NULL,
                            [matched_zcost_item] NVARCHAR(100) NULL,
                            [matched_maktx] NVARCHAR(255) NULL,
                            [row_status] NVARCHAR(40) NULL,
                            [created_by] NVARCHAR(100) NULL,
                            [created_at] DATETIME NOT NULL CONSTRAINT [df_po_validation_log_created] DEFAULT GETDATE()
                        );
                        CREATE INDEX [ix_po_validation_log_po] ON [dbo].[" . self::TABLE . "] ([purchase_order]);
                        CREATE INDEX [ix_po_validation_log_user] ON [dbo].[" . self::TABLE . "] ([assigned_username]);
                    END
                ");
                self::$tableReady = null;
                $this->tableExists($pdo);
            } catch (Throwable $createErr) {
                // CREATE TABLE permission denied (262) on live — expected; DBA must create once.
                error_log('PoValidationLogService ensureTable: ' . $createErr->getMessage());
                self::$tableReady = false;
            }
        } catch (Throwable $e) {
            error_log('PoValidationLogService ensureTable connect: ' . $e->getMessage());
            self::$tableReady = false;
        }
    }

    /**
     * Active portal users for assign / search dropdown.
     *
     * @return list<array{id:int|string,username:string,email:string,role:string,department:string}>
     */
    public function searchUsers(string $q = '', int $limit = 40): array
    {
        $pdo = Database::connection();
        $limit = max(1, min(100, $limit));
        $q = trim($q);
        if ($q === '') {
            $stmt = $pdo->query("
                SELECT TOP ({$limit}) Id, UserName, Email, Role, Department
                FROM [dbo].[users]
                WHERE IsActive = 1
                ORDER BY UserName ASC
            ");
            return $this->mapUsers($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }

        $like = '%' . $q . '%';
        $stmt = $pdo->prepare("
            SELECT TOP ({$limit}) Id, UserName, Email, Role, Department
            FROM [dbo].[users]
            WHERE IsActive = 1
              AND (
                    UserName LIKE ?
                 OR Email LIKE ?
                 OR Department LIKE ?
                 OR CAST(Id AS NVARCHAR(20)) LIKE ?
              )
            ORDER BY UserName ASC
        ");
        $stmt->execute([$like, $like, $like, $like]);
        return $this->mapUsers($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{success:bool,id:?int,error:?string}
     */
    public function save(array $payload): array
    {
        $this->ensureTable();
        if (!$this->tableExists()) {
            return [
                'success' => false,
                'id' => null,
                'error' => 'Table dbo.po_validation_log is missing and this DB user cannot create it. Ask DBA to create the table once.',
            ];
        }

        $po = preg_replace('/\D/', '', (string) ($payload['purchase_order'] ?? '')) ?? '';
        $username = trim((string) ($payload['assigned_username'] ?? ''));
        $status = strtoupper(trim((string) ($payload['validation_status'] ?? '')));
        $desc = trim((string) ($payload['description'] ?? ''));
        $createdBy = trim((string) ($payload['created_by'] ?? ($_SESSION['username'] ?? 'system')));

        if ($po === '') {
            return ['success' => false, 'id' => null, 'error' => 'Purchase order is required.'];
        }
        if (!in_array($status, [self::STATUS_VALIDATE, self::STATUS_NOT_VALIDATE, self::STATUS_COMMENT], true)) {
            return ['success' => false, 'id' => null, 'error' => 'validation_status must be VALIDATE_PO, NOT_VALIDATE, or COMMENT.'];
        }
        if ($status === self::STATUS_COMMENT) {
            if ($desc === '') {
                return ['success' => false, 'id' => null, 'error' => 'Comment text is required.'];
            }
            if ($username === '') {
                $username = $createdBy !== '' ? $createdBy : 'system';
            }
        } elseif ($username === '') {
            return ['success' => false, 'id' => null, 'error' => 'Purchase order and assigned user are required.'];
        }

        $userId = $payload['assigned_user_id'] ?? null;
        $userId = ($userId === '' || $userId === null) ? null : (int) $userId;
        $email = trim((string) ($payload['assigned_email'] ?? $payload['email'] ?? $payload['mail_id'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = $this->resolveUserEmail($username, $userId);
        }

        try {
            $pdo = Database::connection();
            $this->ensureEmailColumn($pdo);
            $withEmail = $this->hasEmailColumn($pdo);

            $baseParams = [
                $this->displayNumber($po),
                trim((string) ($payload['po_item'] ?? '')),
                trim((string) ($payload['material'] ?? '')),
                trim((string) ($payload['mat_desc'] ?? '')),
                trim((string) ($payload['mat_cat'] ?? '')),
                $userId,
                $username,
            ];
            $tailParams = [
                $status,
                $desc !== '' ? $desc : null,
                trim((string) ($payload['matched_zcost_item'] ?? '')),
                trim((string) ($payload['matched_maktx'] ?? '')),
                trim((string) ($payload['row_status'] ?? '')),
                $createdBy !== '' ? $createdBy : 'system',
            ];
            $params = $withEmail
                ? array_merge($baseParams, [$email !== '' ? $email : null], $tailParams)
                : array_merge($baseParams, $tailParams);

            $colsEmail = $withEmail ? "assigned_user_id, assigned_username, assigned_email, validation_status, description," : "assigned_user_id, assigned_username, validation_status, description,";
            $valsEmail = $withEmail ? "?, ?, ?, ?, ?," : "?, ?, ?, ?,";

            // Prefer OUTPUT INSERTED — ODBC Driver 18 does not support PDO::lastInsertId()
            $id = 0;
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO [dbo].[po_validation_log] (
                        purchase_order, po_item, material, mat_desc, mat_cat,
                        {$colsEmail}
                        matched_zcost_item, matched_maktx, row_status, created_by
                    )
                    OUTPUT INSERTED.id
                    VALUES (
                        ?, ?, ?, ?, ?,
                        {$valsEmail}
                        ?, ?, ?, ?
                    )
                ");
                $stmt->execute($params);
                $id = (int) ($stmt->fetchColumn() ?: 0);
            } catch (Throwable $outputErr) {
                $stmt = $pdo->prepare("
                    INSERT INTO [dbo].[po_validation_log] (
                        purchase_order, po_item, material, mat_desc, mat_cat,
                        {$colsEmail}
                        matched_zcost_item, matched_maktx, row_status, created_by
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        {$valsEmail}
                        ?, ?, ?, ?
                    )
                ");
                $stmt->execute($params);
                $id = (int) ($pdo->query('SELECT CAST(SCOPE_IDENTITY() AS INT)')->fetchColumn() ?: 0);
            }

            // Mirror validate / not-validate into unified Pass_po_validtion
            if (in_array($status, [self::STATUS_VALIDATE, self::STATUS_NOT_VALIDATE], true)) {
                try {
                    require_once base_path('app/services/PassPoValidationLogService.php');
                    $act = $status === self::STATUS_VALIDATE ? 'validate' : 'not_validate';
                    $ok = true;
                    (new PassPoValidationLogService())->save([
                        'user_id' => $_SESSION['user_id'] ?? null,
                        'username' => $createdBy !== '' ? $createdBy : 'system',
                        'purchase_order' => $this->displayNumber($po),
                        'action' => $act,
                        'status' => PassPoValidationLogService::statusFor($act, $ok),
                        'method' => 'POST',
                        'url' => 'local://po-costing/validation-log',
                        'payload' => [
                            'validation_status' => $status,
                            'assigned_username' => $username,
                            'assigned_email' => $email,
                            'description' => $desc,
                            'row_status' => trim((string) ($payload['row_status'] ?? '')),
                        ],
                        'response' => [
                            'success' => true,
                            'status' => PassPoValidationLogService::statusFor($act, $ok),
                            'validation_log_id' => $id > 0 ? $id : null,
                        ],
                        'http_code' => 200,
                        'success' => $ok,
                        'from_username' => $createdBy,
                        'to_username' => $username,
                        'to_email' => $email,
                        'remarks' => $desc,
                    ]);
                } catch (Throwable $ignored) {
                }
            }

            return ['success' => true, 'id' => $id > 0 ? $id : null, 'error' => null];
        } catch (Throwable $e) {
            return ['success' => false, 'id' => null, 'error' => $e->getMessage()];
        }
    }

    private function resolveUserEmail(string $username, ?int $userId = null): string
    {
        try {
            $pdo = Database::connection();
            if ($userId !== null && $userId > 0) {
                $stmt = $pdo->prepare("SELECT TOP 1 Email FROM [dbo].[users] WHERE Id = ?");
                $stmt->execute([$userId]);
                $email = trim((string) ($stmt->fetchColumn() ?: ''));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return $email;
                }
            }
            $username = trim($username);
            if ($username === '') {
                return '';
            }
            $stmt = $pdo->prepare("SELECT TOP 1 Email FROM [dbo].[users] WHERE LOWER(UserName) = LOWER(?) AND IsActive = 1");
            $stmt->execute([$username]);
            $email = trim((string) ($stmt->fetchColumn() ?: ''));
            return ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function enrichEmails(array $rows): array
    {
        $need = [];
        foreach ($rows as $row) {
            $email = trim((string) ($row['assigned_email'] ?? ''));
            if ($email !== '') {
                continue;
            }
            $u = trim((string) ($row['assigned_username'] ?? ''));
            if ($u !== '') {
                $need[strtolower($u)] = $u;
            }
        }
        if (!$need) {
            return $rows;
        }
        $found = [];
        foreach ($this->findUsersByUsernames(array_values($need)) as $u) {
            $key = strtolower((string) ($u['username'] ?? ''));
            $email = trim((string) ($u['email'] ?? ''));
            if ($key !== '' && $email !== '') {
                $found[$key] = $email;
            }
        }
        foreach ($rows as &$row) {
            $email = trim((string) ($row['assigned_email'] ?? ''));
            if ($email !== '') {
                continue;
            }
            $key = strtolower(trim((string) ($row['assigned_username'] ?? '')));
            if ($key !== '' && isset($found[$key])) {
                $row['assigned_email'] = $found[$key];
            } else {
                $row['assigned_email'] = $row['assigned_email'] ?? '';
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * Latest PO-level validation logs (one row per validation action).
     * Legacy per-line inserts are collapsed by batch key.
     *
     * @return list<array<string,mixed>>
     */
    public function listForPo(string $purchaseOrder, int $limit = 50): array
    {
        $this->ensureTable();
        if (!$this->tableExists()) {
            return [];
        }
        $po = preg_replace('/\D/', '', $purchaseOrder) ?? '';
        if ($po === '') {
            return [];
        }
        $fetchLimit = max(50, min(500, $limit * 20));
        $pdo = Database::connection();
        $this->ensureEmailColumn($pdo);
        $emailSelect = $this->hasEmailColumn($pdo) ? 'assigned_email,' : "CAST('' AS NVARCHAR(150)) AS assigned_email,";
        $stmt = $pdo->prepare("
            SELECT TOP ({$fetchLimit})
                id, purchase_order, po_item, material, mat_desc, mat_cat,
                assigned_user_id, assigned_username, {$emailSelect} validation_status, description,
                matched_zcost_item, matched_maktx, row_status, created_by, created_at
            FROM [dbo].[po_validation_log]
            WHERE purchase_order = ? OR LTRIM(STR(TRY_CONVERT(BIGINT, purchase_order), 20)) = ?
            ORDER BY created_at DESC, id DESC
        ");
        $display = $this->displayNumber($po);
        $stmt->execute([$display, $display]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $grouped = [];
        $seen = [];
        foreach ($rows as $row) {
            $when = substr(str_replace('T', ' ', (string) ($row['created_at'] ?? '')), 0, 19);
            $key = implode('|', [
                $when,
                (string) ($row['created_by'] ?? ''),
                (string) ($row['assigned_username'] ?? ''),
                (string) ($row['validation_status'] ?? ''),
                (string) ($row['description'] ?? ''),
            ]);
            if (isset($seen[$key])) {
                $idx = $seen[$key];
                $grouped[$idx]['item_count'] = (int) ($grouped[$idx]['item_count'] ?? 1) + 1;
                continue;
            }
            $row['item_count'] = 1;
            $row['created_at'] = $when !== '' ? $when : ($row['created_at'] ?? null);
            $seen[$key] = count($grouped);
            $grouped[] = $row;
            if (count($grouped) >= $limit) {
                break;
            }
        }

        return $this->enrichEmails($grouped);
    }

    /**
     * Recent validation history across POs (optional PO / date filters).
     *
     * @param array{po?:string,from?:string,to?:string,limit?:int} $filters
     * @return list<array<string,mixed>>
     */
    public function listRecent(array $filters = []): array
    {
        $this->ensureTable();
        if (!$this->tableExists()) {
            return [];
        }

        $po = preg_replace('/\D/', '', (string) ($filters['po'] ?? '')) ?? '';
        $from = trim((string) ($filters['from'] ?? ''));
        $to = trim((string) ($filters['to'] ?? ''));
        $limit = max(20, min(500, (int) ($filters['limit'] ?? 200)));

        if ($po !== '') {
            return $this->listForPo($po, $limit);
        }

        $pdo = Database::connection();
        $this->ensureEmailColumn($pdo);
        $emailSelect = $this->hasEmailColumn($pdo) ? 'assigned_email,' : "CAST('' AS NVARCHAR(150)) AS assigned_email,";
        $where = ['1=1'];
        $params = [];
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = 'created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[] = 'created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }
        $sql = "
            SELECT TOP ({$limit})
                id, purchase_order, po_item, material, mat_desc, mat_cat,
                assigned_user_id, assigned_username, {$emailSelect} validation_status, description,
                matched_zcost_item, matched_maktx, row_status, created_by, created_at
            FROM [dbo].[po_validation_log]
            WHERE " . implode(' AND ', $where) . "
            ORDER BY created_at DESC, id DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $grouped = [];
        $seen = [];
        foreach ($rows as $row) {
            $when = substr(str_replace('T', ' ', (string) ($row['created_at'] ?? '')), 0, 19);
            $key = implode('|', [
                (string) ($row['purchase_order'] ?? ''),
                $when,
                (string) ($row['created_by'] ?? ''),
                (string) ($row['assigned_username'] ?? ''),
                (string) ($row['validation_status'] ?? ''),
                (string) ($row['description'] ?? ''),
            ]);
            if (isset($seen[$key])) {
                $idx = $seen[$key];
                $grouped[$idx]['item_count'] = (int) ($grouped[$idx]['item_count'] ?? 1) + 1;
                continue;
            }
            $row['item_count'] = 1;
            $row['created_at'] = $when !== '' ? $when : ($row['created_at'] ?? null);
            $seen[$key] = count($grouped);
            $grouped[] = $row;
        }

        return $this->enrichEmails($grouped);
    }

    /**
     * Resolve portal users by username (for @mentions).
     *
     * @param list<string> $usernames
     * @return list<array{id:int|string,username:string,email:string,role:string,department:string}>
     */
    public function findUsersByUsernames(array $usernames): array
    {
        $names = [];
        foreach ($usernames as $u) {
            $u = trim((string) $u);
            if ($u !== '') {
                $names[strtolower($u)] = $u;
            }
        }
        if (!$names) {
            return [];
        }

        $pdo = Database::connection();
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $stmt = $pdo->prepare("
            SELECT Id, UserName, Email, Role, Department
            FROM [dbo].[users]
            WHERE IsActive = 1
              AND LOWER(UserName) IN ({$placeholders})
        ");
        $stmt->execute(array_values($names));
        return $this->mapUsers($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Extract @usernames from free text.
     *
     * @return list<string>
     */
    public function extractMentions(string $text): array
    {
        if (!preg_match_all('/@([A-Za-z0-9._-]+)/', $text, $m)) {
            return [];
        }
        $out = [];
        foreach ($m[1] as $name) {
            $key = strtolower($name);
            if (!isset($out[$key])) {
                $out[$key] = $name;
            }
        }
        return array_values($out);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{id:int|string,username:string,email:string,role:string,department:string}>
     */
    private function mapUsers(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'         => $r['Id'] ?? $r['id'] ?? '',
                'username'   => (string) ($r['UserName'] ?? $r['username'] ?? ''),
                'email'      => (string) ($r['Email'] ?? $r['email'] ?? ''),
                'role'       => (string) ($r['Role'] ?? $r['role'] ?? ''),
                'department' => (string) ($r['Department'] ?? $r['department'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Status & Remarks texts per PO (newest first). Prefer COMMENT rows; include count.
     *
     * @param list<string> $purchaseOrders
     * @return array<string, array{remarks:string,remarks_all:list<string>,remarks_count:int,validation_status:string,created_by:string,created_at:?string}>
     */
    public function latestRemarksForPurchaseOrders(array $purchaseOrders): array
    {
        $out = [];
        $keys = [];
        foreach ($purchaseOrders as $po) {
            $norm = $this->displayNumber((string) $po);
            if ($norm !== '') {
                $keys[$norm] = true;
            }
        }
        $poList = array_keys($keys);
        if ($poList === []) {
            return $out;
        }

        $this->ensureTable();
        if (!$this->tableExists()) {
            return $out;
        }

        try {
            $pdo = Database::connection();
        } catch (Throwable $e) {
            return $out;
        }

        $maxPerPo = 8;
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
            // Match listForPo: exact string OR numeric display form (avoids missing padded POs)
            $sql = "
                SELECT
                    purchase_order, validation_status, description, created_by, created_at, id
                FROM [dbo].[po_validation_log]
                WHERE (
                    REPLACE(REPLACE(LTRIM(RTRIM(ISNULL(purchase_order, N''))), N'#', N''), N' ', N'') IN ({$placeholders})
                    OR LTRIM(RTRIM(ISNULL(purchase_order, N''))) IN ({$placeholders})
                    OR LTRIM(STR(TRY_CONVERT(BIGINT, purchase_order), 20)) IN ({$placeholders})
                )
                  AND LTRIM(RTRIM(ISNULL(description, N''))) <> N''
                ORDER BY id DESC
            ";
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_merge($variantList, $variantList, $variantList));
                $buckets = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $po = $this->displayNumber((string) ($row['purchase_order'] ?? ''));
                    if ($po === '' || !isset($keys[$po])) {
                        continue;
                    }
                    $desc = preg_replace('/\s+/', ' ', trim((string) ($row['description'] ?? ''))) ?? '';
                    if ($desc === '') {
                        continue;
                    }
                    $st = strtoupper(trim((string) ($row['validation_status'] ?? '')));
                    if (!isset($buckets[$po])) {
                        $buckets[$po] = [
                            'comments' => [],
                            'other' => [],
                            'created_by' => trim((string) ($row['created_by'] ?? '')),
                            'created_at' => substr(str_replace('T', ' ', (string) ($row['created_at'] ?? '')), 0, 19) ?: null,
                            'validation_status' => $st,
                        ];
                    }
                    $entry = [
                        'text' => $desc,
                        'validation_status' => $st,
                        'created_by' => trim((string) ($row['created_by'] ?? '')),
                        'created_at' => substr(str_replace('T', ' ', (string) ($row['created_at'] ?? '')), 0, 19) ?: null,
                    ];
                    if ($st === self::STATUS_COMMENT) {
                        if (count($buckets[$po]['comments']) < $maxPerPo) {
                            $buckets[$po]['comments'][] = $entry;
                        }
                    } elseif (count($buckets[$po]['other']) < $maxPerPo) {
                        $buckets[$po]['other'][] = $entry;
                    }
                }

                foreach ($buckets as $po => $bucket) {
                    $list = $bucket['comments'] !== [] ? $bucket['comments'] : $bucket['other'];
                    if ($list === []) {
                        continue;
                    }
                    $texts = [];
                    foreach ($list as $item) {
                        $t = (string) ($item['text'] ?? '');
                        if ($t === '' || in_array($t, $texts, true)) {
                            continue;
                        }
                        $texts[] = $t;
                    }
                    if ($texts === []) {
                        continue;
                    }
                    $first = $list[0];
                    $out[$po] = [
                        'remarks' => $texts[0],
                        'remarks_all' => $texts,
                        'remarks_count' => count($texts),
                        'validation_status' => (string) ($first['validation_status'] ?? $bucket['validation_status']),
                        'created_by' => (string) ($first['created_by'] ?? $bucket['created_by']),
                        'created_at' => $first['created_at'] ?? $bucket['created_at'],
                    ];
                }
            } catch (Throwable $e) {
                error_log('PoValidationLogService latestRemarksForPurchaseOrders: ' . $e->getMessage());
                continue;
            }
        }

        return $out;
    }

    private function displayNumber(string $digits): string
    {
        $digits = preg_replace('/\D/', '', $digits) ?? '';
        if ($digits === '') {
            return '';
        }
        $trimmed = ltrim($digits, '0');
        return $trimmed === '' ? '0' : $trimmed;
    }
}
