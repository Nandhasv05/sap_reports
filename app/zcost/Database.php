<?php
/**
 * AUTHOR : NANDHAKUMAR
 * CREATED : 03/11/2026
 * DESCRIPTION : Database helper for ZCOST permissions and audit logs using CLIENT_API_LIVE MSSQL
 **/
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use Throwable;

class Database
{
    private static ?PDO $pdo = null;

    /**
     * Get or initialize the PDO connection to CLIENT_API_LIVE
     */
    public static function pdo(): ?PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $configPaths = [
            dirname(__DIR__, 2) . '/EVOL/db_config_client_api.php',
            'C:/MAMP/htdocs/EVOL/db_config_client_api.php',
        ];

        foreach ($configPaths as $path) {
            if (file_exists($path)) {
                require_once $path;
                break;
            }
        }

        if (function_exists('evol_client_api_pdo')) {
            try {
                self::$pdo = evol_client_api_pdo();
                self::ensureTables(self::$pdo);
                return self::$pdo;
            } catch (Throwable $e) {
                error_log('Database::pdo failed: ' . $e->getMessage());
                return null;
            }
        }

        return null;
    }

    /**
     * Ensure required tables exist in database
     */
    private static function ensureTables(PDO $pdo): void
    {
        try {
            // 1. zcost_user_permissions
            $pdo->exec("
                IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='zcost_user_permissions' AND xtype='U')
                BEGIN
                    CREATE TABLE zcost_user_permissions (
                        id INT IDENTITY(1,1) PRIMARY KEY,
                        user_id INT NULL,
                        username NVARCHAR(100) NOT NULL,
                        can_view BIT NOT NULL DEFAULT 1,
                        can_add BIT NOT NULL DEFAULT 0,
                        can_edit BIT NOT NULL DEFAULT 0,
                        can_delete BIT NOT NULL DEFAULT 0,
                        can_approve BIT NOT NULL DEFAULT 0,
                        created_at DATETIME DEFAULT GETDATE(),
                        updated_at DATETIME DEFAULT GETDATE(),
                        CONSTRAINT uq_zcost_username UNIQUE (username)
                    );
                END
            ");
            $pdo->exec("
                IF COL_LENGTH('dbo.zcost_user_permissions', 'can_copy') IS NULL
                BEGIN
                    ALTER TABLE zcost_user_permissions
                    ADD can_copy BIT NOT NULL CONSTRAINT DF_zcost_user_permissions_can_copy DEFAULT 0;
                END
            ");

            // 2. zcost_audit_log
            $pdo->exec("
                IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='zcost_audit_log' AND xtype='U')
                BEGIN
                    CREATE TABLE zcost_audit_log (
                        id INT IDENTITY(1,1) PRIMARY KEY,
                        log_uuid NVARCHAR(50) NOT NULL,
                        vbeln NVARCHAR(30) NOT NULL,
                        posnr NVARCHAR(20) NOT NULL,
                        action NVARCHAR(50) NOT NULL,
                        approve_status NVARCHAR(10) NULL,
                        username NVARCHAR(100) NOT NULL,
                        user_id INT NULL,
                        ip_address NVARCHAR(50) NULL,
                        sellprice NVARCHAR(50) NULL,
                        subtot NVARCHAR(50) NULL,
                        quota NVARCHAR(50) NULL,
                        cnv NVARCHAR(50) NULL,
                        cnvf NVARCHAR(50) NULL,
                        lines_summary NVARCHAR(MAX) NULL,
                        success BIT NOT NULL DEFAULT 1,
                        http_code INT NULL,
                        message NVARCHAR(MAX) NULL,
                        payload_json NVARCHAR(MAX) NULL,
                        created_at DATETIME DEFAULT GETDATE()
                    );
                END
            ");
        } catch (Throwable $e) {
            error_log('Database::ensureTables error: ' . $e->getMessage());
        }
    }

    /**
     * Retrieve permissions for a user from database.
     * Admin role has full permissions.
     *
     * @return array{view: bool, add: bool, edit: bool, delete: bool, approve: bool, copy: bool}
     */
    public static function getUserPermissions(string $username, ?int $userId = null, ?string $role = null): array
    {
        $roleLower = strtolower(trim((string)$role));
        $userKey = strtolower(trim($username));

        // Admin roles get full access by default
        if ($roleLower === 'admin' || $roleLower === 'superadmin' || in_array($userKey, ['admin', 'nga'], true)) {
            return [
                'view' => true,
                'add' => true,
                'edit' => true,
                'delete' => true,
                'approve' => true,
                'copy' => true,
            ];
        }

        $pdo = self::pdo();
        if ($pdo !== null && $userKey !== '') {
            try {
                // Check zcost_user_permissions table
                $stmt = $pdo->prepare("
                    SELECT TOP 1 can_view, can_add, can_edit, can_delete, can_approve, can_copy
                    FROM zcost_user_permissions
                    WHERE LOWER(username) = LOWER(?) OR (user_id IS NOT NULL AND user_id = ?)
                ");
                $stmt->execute([$userKey, $userId ?? 0]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $add = (bool)$row['can_add'];
                    $edit = (bool)$row['can_edit'];
                    $approve = (bool)$row['can_approve'];
                    $copy = (bool)($row['can_copy'] ?? false);
                    $view = (bool)$row['can_view'] || $add || $edit || $approve || $copy;
                    return [
                        'view' => $view,
                        'add' => $add,
                        'edit' => $edit,
                        'delete' => (bool)$row['can_delete'],
                        'approve' => $approve,
                        'copy' => $copy,
                    ];
                }

                try {
                    $sapStmt = $pdo->prepare("
                        SELECT TOP 1
                            CAST(can_view AS INT) AS can_view,
                            CAST(can_add AS INT) AS can_add,
                            CAST(can_edit AS INT) AS can_edit,
                            CAST(can_approve AS INT) AS can_approve,
                            CAST(ISNULL(can_copy, 0) AS INT) AS can_copy
                        FROM sap_report_userascesscontrol
                        WHERE (user_id = ? OR LOWER(LTRIM(RTRIM(username))) = ?)
                          AND (LOWER(LTRIM(RTRIM(page_route))) IN ('zcost', 'zcost costing sheet', 'zcost-costing-sheet')
                               OR page_no = 12)
                        ORDER BY CAST(can_view AS INT) DESC
                    ");
                    $sapStmt->execute([$userId ?? 0, $userKey]);
                    $sapRow = $sapStmt->fetch(PDO::FETCH_ASSOC);
                    // Only sap_report_userascesscontrol — no legacy duplicate tables
                    if ($sapRow) {
                        $add = (int) $sapRow['can_add'] === 1;
                        $edit = (int) $sapRow['can_edit'] === 1;
                        $approve = (int) $sapRow['can_approve'] === 1;
                        $copy = (int) ($sapRow['can_copy'] ?? 0) === 1;
                        $view = (int) $sapRow['can_view'] === 1 || $add || $edit || $approve || $copy;
                        return [
                            'view' => $view,
                            'add' => $add,
                            'edit' => $edit,
                            'delete' => $edit,
                            'approve' => $approve,
                            'copy' => $copy,
                        ];
                    }
                } catch (Throwable $e) {
                    error_log('tblSapUseracessControl zcost lookup: ' . $e->getMessage());
                }

                // Fallback check: user_access module table in EVOL
                if ($userId && $userId > 0) {
                    $stmt2 = $pdo->prepare("
                        SELECT module_name, is_allowed FROM user_access WHERE user_id = ?
                    ");
                    $stmt2->execute([$userId]);
                    $accessRows = $stmt2->fetchAll(PDO::FETCH_ASSOC);

                    if (!empty($accessRows)) {
                        $accessMap = [];
                        foreach ($accessRows as $ar) {
                            $accessMap[strtolower(trim($ar['module_name']))] = (bool)$ar['is_allowed'];
                        }

                        $hasBase = !empty($accessMap['zcost costing sheet']) || !empty($accessMap['zcost']);
                        $add = !empty($accessMap['zcost_add']) || !empty($accessMap['zcost costing sheet_add']);
                        $edit = !empty($accessMap['zcost_edit']) || !empty($accessMap['zcost costing sheet_edit']);
                        $approve = !empty($accessMap['zcost_approve']) || !empty($accessMap['zcost costing sheet_approve']);
                        $copy = !empty($accessMap['zcost_copy']) || !empty($accessMap['zcost costing sheet_copy']);
                        return [
                            'view' => $hasBase || $add || $edit || $approve || $copy,
                            'add' => $add,
                            'edit' => $edit,
                            'delete' => !empty($accessMap['zcost_delete']) || !empty($accessMap['zcost costing sheet_delete']),
                            'approve' => $approve,
                            'copy' => $copy,
                        ];
                    }
                }
            } catch (Throwable $e) {
                error_log('Database::getUserPermissions error: ' . $e->getMessage());
            }
        }

        // If not found in database, default safe permissions for logged-in portal users
        $isLoggedIn = ($userId !== null && $userId > 0) || $userKey !== '';
        return [
            'view' => $isLoggedIn,
            'add' => false,
            'edit' => false,
            'delete' => false,
            'approve' => false,
            'copy' => false,
        ];
    }

    /**
     * Get all users and their ZCOST permissions for the Access Management panel
     *
     * @return list<array<string, mixed>>
     */
    public static function getAllUserPermissions(): array
    {
        $pdo = self::pdo();
        if ($pdo === null) {
            return [];
        }

        try {
            // Join users with zcost_user_permissions
            $sql = "
                SELECT
                    u.Id AS user_id,
                    u.UserName AS username,
                    u.Role AS role,
                    u.Email AS email,
                    u.IsActive AS is_active,
                    ISNULL(p.can_view, 1) AS can_view,
                    ISNULL(p.can_add, CASE WHEN LOWER(u.Role) IN ('admin', 'superadmin') THEN 1 ELSE 0 END) AS can_add,
                    ISNULL(p.can_edit, CASE WHEN LOWER(u.Role) IN ('admin', 'superadmin') THEN 1 ELSE 0 END) AS can_edit,
                    ISNULL(p.can_delete, CASE WHEN LOWER(u.Role) IN ('admin', 'superadmin') THEN 1 ELSE 0 END) AS can_delete,
                    ISNULL(p.can_approve, CASE WHEN LOWER(u.Role) IN ('admin', 'superadmin') THEN 1 ELSE 0 END) AS can_approve,
                    ISNULL(p.can_copy, CASE WHEN LOWER(u.Role) IN ('admin', 'superadmin') THEN 1 ELSE 0 END) AS can_copy,
                    p.updated_at
                FROM users u
                LEFT JOIN zcost_user_permissions p
                    ON (p.user_id = u.Id OR LOWER(p.username) = LOWER(u.UserName))
                ORDER BY u.UserName ASC
            ";
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Also include any users in zcost_user_permissions not yet in users table
            $sqlExtra = "
                SELECT
                    p.user_id,
                    p.username,
                    'External' AS role,
                    '' AS email,
                    1 AS is_active,
                    p.can_view,
                    p.can_add,
                    p.can_edit,
                    p.can_delete,
                    p.can_approve,
                    p.can_copy,
                    p.updated_at
                FROM zcost_user_permissions p
                WHERE LOWER(p.username) NOT IN (SELECT LOWER(UserName) FROM users WHERE UserName IS NOT NULL)
                ORDER BY p.username ASC
            ";
            $stmtExtra = $pdo->query($sqlExtra);
            $extraRows = $stmtExtra->fetchAll(PDO::FETCH_ASSOC);

            $all = array_merge($rows, $extraRows);

            return array_map(static function ($r) {
                return [
                    'user_id' => $r['user_id'] ? (int)$r['user_id'] : null,
                    'username' => (string)$r['username'],
                    'role' => (string)($r['role'] ?? 'User'),
                    'email' => (string)($r['email'] ?? ''),
                    'is_active' => (bool)($r['is_active'] ?? true),
                    'can_view' => (bool)$r['can_view'],
                    'can_add' => (bool)$r['can_add'],
                    'can_edit' => (bool)$r['can_edit'],
                    'can_delete' => (bool)$r['can_delete'],
                    'can_approve' => (bool)$r['can_approve'],
                    'can_copy' => (bool)($r['can_copy'] ?? false),
                    'updated_at' => (string)($r['updated_at'] ?? ''),
                ];
            }, $all);

        } catch (Throwable $e) {
            error_log('Database::getAllUserPermissions error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Save/update user permissions in zcost_user_permissions table
     *
     * @param array{can_view: bool|int, can_add: bool|int, can_edit: bool|int, can_delete: bool|int, can_approve: bool|int} $perms
     */
    public static function saveUserPermissions(string $username, array $perms, ?int $userId = null): bool
    {
        $pdo = self::pdo();
        if ($pdo === null) {
            return false;
        }

        $username = trim($username);
        if ($username === '') {
            return false;
        }

        $canView = !empty($perms['can_view']) ? 1 : 0;
        $canAdd = !empty($perms['can_add']) ? 1 : 0;
        $canEdit = !empty($perms['can_edit']) ? 1 : 0;
        $canDelete = !empty($perms['can_delete']) ? 1 : 0;
        $canApprove = !empty($perms['can_approve']) ? 1 : 0;
        $canCopy = !empty($perms['can_copy']) ? 1 : 0;

        try {
            $check = $pdo->prepare("SELECT id FROM zcost_user_permissions WHERE LOWER(username) = LOWER(?)");
            $check->execute([$username]);
            $existingId = $check->fetchColumn();

            if ($existingId) {
                $stmt = $pdo->prepare("
                    UPDATE zcost_user_permissions
                    SET can_view = ?, can_add = ?, can_edit = ?, can_delete = ?, can_approve = ?, can_copy = ?, user_id = COALESCE(?, user_id), updated_at = GETDATE()
                    WHERE id = ?
                ");
                return $stmt->execute([$canView, $canAdd, $canEdit, $canDelete, $canApprove, $canCopy, $userId, $existingId]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO zcost_user_permissions
                    (user_id, username, can_view, can_add, can_edit, can_delete, can_approve, can_copy, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())
                ");
                return $stmt->execute([$userId, $username, $canView, $canAdd, $canEdit, $canDelete, $canApprove, $canCopy]);
            }
        } catch (Throwable $e) {
            error_log('Database::saveUserPermissions error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Insert audit log entry into zcost_audit_log
     */
    public static function logAudit(array $data): bool
    {
        $pdo = self::pdo();
        if ($pdo === null) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO zcost_audit_log (
                    log_uuid, vbeln, posnr, action, approve_status, username, user_id, ip_address,
                    sellprice, subtot, quota, cnv, cnvf, lines_summary, success, http_code, message, payload_json, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())
            ");

            return $stmt->execute([
                (string)($data['id'] ?? bin2hex(random_bytes(8))),
                (string)($data['vbeln'] ?? ''),
                (string)($data['posnr'] ?? ''),
                (string)($data['action'] ?? 'save'),
                (string)($data['approve'] ?? ''),
                (string)($data['user'] ?? 'Guest'),
                isset($data['user_id']) ? (int)$data['user_id'] : null,
                (string)($data['ip'] ?? ''),
                (string)($data['sellprice'] ?? ''),
                (string)($data['subtot'] ?? ''),
                (string)($data['quota'] ?? ''),
                (string)($data['cnv'] ?? ''),
                (string)($data['cnvf'] ?? ''),
                is_array($data['lines'] ?? null) ? json_encode($data['lines']) : (string)($data['lines'] ?? ''),
                !empty($data['success']) ? 1 : 0,
                isset($data['http_code']) ? (int)$data['http_code'] : 0,
                (string)($data['message'] ?? ''),
                is_array($data['payload'] ?? null) ? json_encode($data['payload'], JSON_UNESCAPED_SLASHES) : (string)($data['payload'] ?? ''),
            ]);
        } catch (Throwable $e) {
            error_log('Database::logAudit error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Retrieve audit entries from zcost_audit_log table
     *
     * @return list<array<string, mixed>>
     */
    public static function getAuditLogs(int $limit = 40, ?string $vbeln = null): array
    {
        $pdo = self::pdo();
        if ($pdo === null) {
            return [];
        }

        try {
            $limit = max(1, min(500, $limit));
            if ($vbeln !== null && $vbeln !== '') {
                $stmt = $pdo->prepare("
                    SELECT TOP {$limit} *
                    FROM zcost_audit_log
                    WHERE vbeln = ?
                    ORDER BY id DESC
                ");
                $stmt->execute([$vbeln]);
            } else {
                $stmt = $pdo->query("
                    SELECT TOP {$limit} *
                    FROM zcost_audit_log
                    ORDER BY id DESC
                ");
            }

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $results = [];

            foreach ($rows as $r) {
                $lines = null;
                if (!empty($r['lines_summary'])) {
                    $decoded = json_decode($r['lines_summary'], true);
                    $lines = is_array($decoded) ? $decoded : null;
                }

                $payload = null;
                if (!empty($r['payload_json'])) {
                    $decoded = json_decode($r['payload_json'], true);
                    $payload = is_array($decoded) ? $decoded : null;
                }

                $results[] = [
                    'id' => (string)$r['log_uuid'],
                    'db_id' => (int)$r['id'],
                    'ts' => date('c', strtotime((string)$r['created_at'])),
                    'action' => (string)$r['action'],
                    'user' => (string)$r['username'],
                    'user_id' => $r['user_id'] ? (int)$r['user_id'] : null,
                    'ip' => (string)($r['ip_address'] ?? ''),
                    'vbeln' => (string)$r['vbeln'],
                    'posnr' => (string)$r['posnr'],
                    'approve' => (string)($r['approve_status'] ?? ''),
                    'sellprice' => (string)($r['sellprice'] ?? ''),
                    'subtot' => (string)($r['subtot'] ?? ''),
                    'quota' => (string)($r['quota'] ?? ''),
                    'cnv' => (string)($r['cnv'] ?? ''),
                    'cnvf' => (string)($r['cnvf'] ?? ''),
                    'lines' => $lines ?: ['total' => 0],
                    'success' => (bool)$r['success'],
                    'http_code' => (int)($r['http_code'] ?? 0),
                    'message' => (string)($r['message'] ?? ''),
                    'payload' => $payload,
                ];
            }

            return $results;

        } catch (Throwable $e) {
            error_log('Database::getAuditLogs error: ' . $e->getMessage());
            return [];
        }
    }
}

