<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 06/11/2026
 * DESCRIPTION : Persist / load per-user e-signature images (dbo.user_esignatures)
 */
require_once base_path('app/core/Database.php');

class UserEsignService
{
    public function ensureTable(): void
    {
        $pdo = Database::connection();
        $pdo->exec("
            IF OBJECT_ID(N'[dbo].[user_esignatures]', N'U') IS NULL
            BEGIN
                CREATE TABLE [dbo].[user_esignatures] (
                    [id] BIGINT IDENTITY(1,1) NOT NULL,
                    [user_id] NVARCHAR(100) NULL,
                    [username] NVARCHAR(100) NOT NULL,
                    [signature_data] NVARCHAR(MAX) NOT NULL,
                    [updated_at] DATETIME2 NOT NULL CONSTRAINT [DF_user_esignatures_updated] DEFAULT SYSUTCDATETIME(),
                    [created_at] DATETIME2 NOT NULL CONSTRAINT [DF_user_esignatures_created] DEFAULT SYSUTCDATETIME(),
                    CONSTRAINT [PK_user_esignatures] PRIMARY KEY CLUSTERED ([id])
                );
                CREATE UNIQUE NONCLUSTERED INDEX [UX_user_esignatures_username]
                    ON [dbo].[user_esignatures] ([username]);
            END
        ");
    }

    /**
     * @return array{success:bool,found:bool,username:?string,signature_data:?string,error:?string}
     */
    public function getForUsername(string $username): array
    {
        $username = trim($username);
        if ($username === '') {
            return ['success' => false, 'found' => false, 'username' => null, 'signature_data' => null, 'error' => 'Username is required.'];
        }
        try {
            $this->ensureTable();
            $pdo = Database::connection();
            $stmt = $pdo->prepare("
                SELECT TOP 1 username, signature_data
                FROM [dbo].[user_esignatures]
                WHERE LOWER(username) = LOWER(?)
            ");
            $stmt->execute([$username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || trim((string) ($row['signature_data'] ?? '')) === '') {
                return ['success' => true, 'found' => false, 'username' => $username, 'signature_data' => null, 'error' => null];
            }
            return [
                'success' => true,
                'found' => true,
                'username' => (string) $row['username'],
                'signature_data' => (string) $row['signature_data'],
                'error' => null,
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'found' => false, 'username' => $username, 'signature_data' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Upsert signature for a user (data URL or raw base64 PNG/JPEG).
     *
     * @return array{success:bool,error:?string}
     */
    public function saveForUser(?string $userId, string $username, string $signatureData): array
    {
        $username = trim($username);
        $signatureData = trim($signatureData);
        if ($username === '' || $signatureData === '') {
            return ['success' => false, 'error' => 'Username and signature are required.'];
        }
        if (!preg_match('#^data:image/(png|jpeg|jpg);base64,#i', $signatureData)) {
            // Normalize raw base64 to PNG data URL for consistent UI reload
            $signatureData = 'data:image/png;base64,' . $signatureData;
        }
        try {
            $this->ensureTable();
            $pdo = Database::connection();
            $existing = $pdo->prepare("SELECT TOP 1 id FROM [dbo].[user_esignatures] WHERE LOWER(username) = LOWER(?)");
            $existing->execute([$username]);
            $id = $existing->fetchColumn();
            if ($id) {
                $stmt = $pdo->prepare("
                    UPDATE [dbo].[user_esignatures]
                    SET user_id = COALESCE(?, user_id),
                        signature_data = ?,
                        updated_at = SYSUTCDATETIME()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $userId !== null && $userId !== '' ? (string) $userId : null,
                    $signatureData,
                    (int) $id,
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO [dbo].[user_esignatures] (user_id, username, signature_data, updated_at, created_at)
                    VALUES (?, ?, ?, SYSUTCDATETIME(), SYSUTCDATETIME())
                ");
                $stmt->execute([
                    $userId !== null && $userId !== '' ? (string) $userId : null,
                    $username,
                    $signatureData,
                ]);
            }
            return ['success' => true, 'error' => null];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
