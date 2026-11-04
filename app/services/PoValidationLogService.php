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

    public function ensureTable(): void
    {
        $pdo = Database::connection();
        $pdo->exec("
            IF NOT EXISTS (
                SELECT 1 FROM sys.objects
                WHERE object_id = OBJECT_ID(N'[dbo].[po_validation_log]') AND type IN (N'U')
            )
            BEGIN
                CREATE TABLE [dbo].[po_validation_log] (
                    [id] INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                    [purchase_order] NVARCHAR(20) NOT NULL,
                    [po_item] NVARCHAR(20) NULL,
                    [material] NVARCHAR(100) NULL,
                    [mat_desc] NVARCHAR(255) NULL,
                    [mat_cat] NVARCHAR(20) NULL,
                    [assigned_user_id] INT NULL,
                    [assigned_username] NVARCHAR(100) NOT NULL,
                    [validation_status] NVARCHAR(40) NOT NULL,
                    [description] NVARCHAR(MAX) NULL,
                    [matched_zcost_item] NVARCHAR(100) NULL,
                    [matched_maktx] NVARCHAR(255) NULL,
                    [row_status] NVARCHAR(40) NULL,
                    [created_by] NVARCHAR(100) NULL,
                    [created_at] DATETIME NOT NULL CONSTRAINT [df_po_validation_log_created] DEFAULT GETDATE()
                );
                CREATE INDEX [ix_po_validation_log_po] ON [dbo].[po_validation_log] ([purchase_order]);
                CREATE INDEX [ix_po_validation_log_user] ON [dbo].[po_validation_log] ([assigned_username]);
            END
        ");
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

        $po = preg_replace('/\D/', '', (string) ($payload['purchase_order'] ?? '')) ?? '';
        $username = trim((string) ($payload['assigned_username'] ?? ''));
        $status = strtoupper(trim((string) ($payload['validation_status'] ?? '')));
        if ($po === '' || $username === '') {
            return ['success' => false, 'id' => null, 'error' => 'Purchase order and assigned user are required.'];
        }
        if (!in_array($status, [self::STATUS_VALIDATE, self::STATUS_NOT_VALIDATE], true)) {
            return ['success' => false, 'id' => null, 'error' => 'validation_status must be VALIDATE_PO or NOT_VALIDATE.'];
        }

        $userId = $payload['assigned_user_id'] ?? null;
        $userId = ($userId === '' || $userId === null) ? null : (int) $userId;
        $desc = trim((string) ($payload['description'] ?? ''));
        $createdBy = trim((string) ($payload['created_by'] ?? ($_SESSION['username'] ?? 'system')));

        try {
            $pdo = Database::connection();
            $params = [
                $this->displayNumber($po),
                trim((string) ($payload['po_item'] ?? '')),
                trim((string) ($payload['material'] ?? '')),
                trim((string) ($payload['mat_desc'] ?? '')),
                trim((string) ($payload['mat_cat'] ?? '')),
                $userId,
                $username,
                $status,
                $desc !== '' ? $desc : null,
                trim((string) ($payload['matched_zcost_item'] ?? '')),
                trim((string) ($payload['matched_maktx'] ?? '')),
                trim((string) ($payload['row_status'] ?? '')),
                $createdBy !== '' ? $createdBy : 'system',
            ];

            // Prefer OUTPUT INSERTED — ODBC Driver 18 does not support PDO::lastInsertId()
            $id = 0;
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO [dbo].[po_validation_log] (
                        purchase_order, po_item, material, mat_desc, mat_cat,
                        assigned_user_id, assigned_username, validation_status, description,
                        matched_zcost_item, matched_maktx, row_status, created_by
                    )
                    OUTPUT INSERTED.id
                    VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?
                    )
                ");
                $stmt->execute($params);
                $id = (int) ($stmt->fetchColumn() ?: 0);
            } catch (Throwable $outputErr) {
                $stmt = $pdo->prepare("
                    INSERT INTO [dbo].[po_validation_log] (
                        purchase_order, po_item, material, mat_desc, mat_cat,
                        assigned_user_id, assigned_username, validation_status, description,
                        matched_zcost_item, matched_maktx, row_status, created_by
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?
                    )
                ");
                $stmt->execute($params);
                $id = (int) ($pdo->query('SELECT CAST(SCOPE_IDENTITY() AS INT)')->fetchColumn() ?: 0);
            }

            return ['success' => true, 'id' => $id > 0 ? $id : null, 'error' => null];
        } catch (Throwable $e) {
            return ['success' => false, 'id' => null, 'error' => $e->getMessage()];
        }
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
        $po = preg_replace('/\D/', '', $purchaseOrder) ?? '';
        if ($po === '') {
            return [];
        }
        $fetchLimit = max(50, min(500, $limit * 20));
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            SELECT TOP ({$fetchLimit})
                id, purchase_order, po_item, material, mat_desc, mat_cat,
                assigned_user_id, assigned_username, validation_status, description,
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

        return $grouped;
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
