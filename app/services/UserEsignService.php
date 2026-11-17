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
     * Prefer public/Esign/{username}.png (login name = file name), then DB.
     * Example: login "suparna" → Esign/suparna.cleaned.png or Esign/suparna.png
     *
     * @return array{success:bool,found:bool,username:?string,signature_data:?string,source:?string,file:?string,error:?string}
     */
    public function getForUsername(string $username): array
    {
        $username = trim($username);
        if ($username === '') {
            return ['success' => false, 'found' => false, 'username' => null, 'signature_data' => null, 'source' => null, 'file' => null, 'error' => 'Username is required.'];
        }

        // 1) Always prefer folder file matching login username
        $fileHit = $this->loadFromPublicEsignFolder($username);
        if (is_array($fileHit) && !empty($fileHit['data_url'])) {
            return [
                'success' => true,
                'found' => true,
                'username' => $username,
                'signature_data' => (string) $fileHit['data_url'],
                'source' => 'file',
                'file' => (string) ($fileHit['file'] ?? ''),
                'error' => null,
            ];
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
            if ($row && trim((string) ($row['signature_data'] ?? '')) !== '') {
                return [
                    'success' => true,
                    'found' => true,
                    'username' => (string) $row['username'],
                    'signature_data' => (string) $row['signature_data'],
                    'source' => 'database',
                    'file' => null,
                    'error' => null,
                ];
            }

            return ['success' => true, 'found' => false, 'username' => $username, 'signature_data' => null, 'source' => null, 'file' => null, 'error' => null];
        } catch (Throwable $e) {
            return ['success' => false, 'found' => false, 'username' => $username, 'signature_data' => null, 'source' => null, 'file' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Load signature image from sap_reports/public/Esign.
     * Login username maps to file name: nagarajan → nagarajan.png
     * Prefers *.cleaned.png (transparent) over the raw black-background scan.
     *
     * @return array{data_url:string,file:string}|null
     */
    private function loadFromPublicEsignFolder(string $username): ?array
    {
        $user = strtolower(trim($username));
        if ($user === '') {
            return null;
        }
        // Alias spellings used in the portal
        $candidates = [$user];
        if ($user === 'suprana') {
            $candidates[] = 'suparna';
        } elseif ($user === 'suparna') {
            $candidates[] = 'suprana';
        } elseif ($user === 'shanthi') {
            $candidates[] = 'santhi';
        } elseif ($user === 'santhi') {
            $candidates[] = 'shanthi';
        } elseif ($user === 'saminath') {
            $candidates[] = 'saminathan';
        } elseif ($user === 'saminathan') {
            $candidates[] = 'saminath';
        }

        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'Esign';
        if (!is_dir($dir)) {
            return null;
        }

        foreach ($candidates as $name) {
            $safe = preg_replace('/[^a-z0-9._-]/', '', $name) ?? '';
            if ($safe === '') {
                continue;
            }
            foreach ([
                $dir . DIRECTORY_SEPARATOR . $safe . '.cleaned.png',
                $dir . DIRECTORY_SEPARATOR . $safe . '.png',
                $dir . DIRECTORY_SEPARATOR . $safe . '.jpg',
                $dir . DIRECTORY_SEPARATOR . $safe . '.jpeg',
            ] as $path) {
                if (!is_file($path) || !is_readable($path)) {
                    continue;
                }
                $bytes = @file_get_contents($path);
                if ($bytes === false || $bytes === '') {
                    continue;
                }
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $mime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
                return [
                    'data_url' => 'data:' . $mime . ';base64,' . base64_encode($bytes),
                    'file' => basename($path),
                ];
            }
        }
        return null;
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
