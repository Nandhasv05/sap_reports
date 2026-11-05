<?php
/**
 * Ensure ONE access table + ONE audit table, then drop duplicates.
 *
 *   Keep: dbo.sap_report_userascesscontrol
 *   Keep: dbo.sap_report_auditLog
 *
 * Open: http://localhost:8888/sap_reports/public/create_sap_report_tables.php
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');

if (!defined('EVOLV_ROOT')) {
    define('EVOLV_ROOT', dirname(__DIR__));
}
require EVOLV_ROOT . '/app/core/helpers.php';
require EVOLV_ROOT . '/app/core/Database.php';

$uac = 'sap_report_userascesscontrol';
$audit = 'sap_report_auditLog';

try {
    $pdo = Database::connection();
    echo "Connected OK\n\n";

    // ---- Ensure single access table ----
    $pdo->exec("
        IF OBJECT_ID(N'[dbo].[{$uac}]', N'U') IS NULL
        BEGIN
            CREATE TABLE [dbo].[{$uac}] (
                [id] INT IDENTITY(1,1) NOT NULL,
                [user_id] INT NOT NULL,
                [username] NVARCHAR(100) NULL,
                [page_no] INT NOT NULL,
                [page_name] NVARCHAR(150) NOT NULL,
                [page_route] NVARCHAR(100) NULL,
                [can_add] BIT NOT NULL CONSTRAINT [DF_{$uac}_add] DEFAULT 0,
                [can_view] BIT NOT NULL CONSTRAINT [DF_{$uac}_view] DEFAULT 0,
                [can_edit] BIT NOT NULL CONSTRAINT [DF_{$uac}_edit] DEFAULT 0,
                [can_approve] BIT NOT NULL CONSTRAINT [DF_{$uac}_approve] DEFAULT 0,
                [can_copy] BIT NOT NULL CONSTRAINT [DF_{$uac}_copy] DEFAULT 0,
                [can_assign] BIT NOT NULL CONSTRAINT [DF_{$uac}_assign] DEFAULT 0,
                [can_validate] BIT NOT NULL CONSTRAINT [DF_{$uac}_validate] DEFAULT 0,
                [created_at] DATETIME NOT NULL CONSTRAINT [DF_{$uac}_created] DEFAULT GETDATE(),
                [updated_at] DATETIME NOT NULL CONSTRAINT [DF_{$uac}_updated] DEFAULT GETDATE(),
                CONSTRAINT [PK_{$uac}] PRIMARY KEY ([id]),
                CONSTRAINT [uq_{$uac}_user_page] UNIQUE ([user_id], [page_no])
            );
        END
    ");
    echo "OK keep [{$uac}]\n";

    foreach (['username' => 'NULL', 'page_name' => 'NOT NULL', 'page_route' => 'NULL'] as $col => $null) {
        $len = $col === 'page_name' ? 150 : 100;
        try {
            $pdo->exec("ALTER TABLE [dbo].[{$uac}] ALTER COLUMN [{$col}] NVARCHAR({$len}) {$null}");
            echo "ALTER {$col} NVARCHAR({$len}) OK\n";
        } catch (Throwable $e) {
            echo "ALTER {$col}: {$e->getMessage()}\n";
        }
    }

    $count = (int) $pdo->query("SELECT COUNT(*) FROM [dbo].[{$uac}]")->fetchColumn();
    echo "Rows in {$uac}: {$count}\n";

    if ($count === 0) {
        foreach (['sap_report_userAccessControl', 'tblSapUseracessControl'] as $src) {
            $exists = $pdo->query("SELECT OBJECT_ID(N'[dbo].[{$src}]', N'U')")->fetchColumn();
            if (!$exists) {
                echo "Skip migrate from {$src} (missing)\n";
                continue;
            }
            try {
                $pdo->exec("
                    INSERT INTO [dbo].[{$uac}]
                    (user_id, username, page_no, page_name, page_route,
                     can_add, can_view, can_edit, can_approve, can_copy, can_assign, can_validate,
                     created_at, updated_at)
                    SELECT
                        user_id,
                        LEFT(CAST(ISNULL(username,'') AS NVARCHAR(100)), 100),
                        page_no,
                        LEFT(CAST(ISNULL(page_name,'Page') AS NVARCHAR(150)), 150),
                        LEFT(CAST(ISNULL(page_route,'') AS NVARCHAR(100)), 100),
                        CAST(ISNULL(can_add,0) AS BIT),
                        CAST(ISNULL(can_view,0) AS BIT),
                        CAST(ISNULL(can_edit,0) AS BIT),
                        CAST(ISNULL(can_approve,0) AS BIT),
                        CAST(ISNULL(can_copy,0) AS BIT),
                        CAST(ISNULL(can_assign,0) AS BIT),
                        CAST(ISNULL(can_validate,0) AS BIT),
                        ISNULL(created_at, GETDATE()),
                        ISNULL(updated_at, GETDATE())
                    FROM [dbo].[{$src}]
                ");
                echo "Migrated from {$src} -> " . (int) $pdo->query("SELECT COUNT(*) FROM [dbo].[{$uac}]")->fetchColumn() . " rows\n";
                break;
            } catch (Throwable $e) {
                echo "Migrate {$src} failed: {$e->getMessage()}\n";
            }
        }
    }

    // ---- Ensure single audit table ----
    $pdo->exec("
        IF OBJECT_ID(N'[dbo].[{$audit}]', N'U') IS NULL
        BEGIN
            CREATE TABLE [dbo].[{$audit}] (
                [id] BIGINT IDENTITY(1,1) NOT NULL,
                [url] NVARCHAR(2000) NOT NULL,
                [status] INT NOT NULL,
                [username] NVARCHAR(100) NULL,
                [user_id] NVARCHAR(100) NULL,
                [created_at] DATETIME2 NOT NULL CONSTRAINT [DF_{$audit}_created] DEFAULT SYSUTCDATETIME(),
                [ip] NVARCHAR(64) NULL,
                [response_payload] NVARCHAR(MAX) NULL,
                CONSTRAINT [PK_{$audit}] PRIMARY KEY CLUSTERED ([id])
            );
            CREATE NONCLUSTERED INDEX [IX_{$audit}_created]
                ON [dbo].[{$audit}] ([created_at] DESC);
        END
    ");
    echo "OK keep [{$audit}]\n";

    $auditCount = (int) $pdo->query("SELECT COUNT(*) FROM [dbo].[{$audit}]")->fetchColumn();
    $oldAudit = 'sap_reports_api_audit_log';
    $oldExists = $pdo->query("SELECT OBJECT_ID(N'[dbo].[{$oldAudit}]', N'U')")->fetchColumn();
    if ($auditCount === 0 && $oldExists) {
        try {
            $pdo->exec("
                INSERT INTO [dbo].[{$audit}]
                (url, status, username, user_id, created_at, ip, response_payload)
                SELECT url, status, username, user_id, created_at, ip, response_payload
                FROM [dbo].[{$oldAudit}]
            ");
            echo "Migrated audit from {$oldAudit}: " . (int) $pdo->query("SELECT COUNT(*) FROM [dbo].[{$audit}]")->fetchColumn() . "\n";
        } catch (Throwable $e) {
            echo "Audit migrate: {$e->getMessage()}\n";
        }
    }

    // ---- Drop EVERY duplicate / leftover access + old audit table ----
    echo "\n=== DROP duplicates (keep only {$uac} + {$audit}) ===\n";
    $dropNames = $pdo->query("
        SELECT name
        FROM sys.tables
        WHERE schema_id = SCHEMA_ID(N'dbo')
          AND (
                name IN (
                    N'sap_report_userAccessControl',
                    N'tblSapUseracessControl',
                    N'tblSapUserAccessControl',
                    N'sap_reports_api_audit_log'
                )
             OR name LIKE N'sap_report_userAccessControl[_]%'
             OR name LIKE N'sap_report_userascesscontrol[_]%'
             OR name LIKE N'uac_tmp[_]%'
          )
        ORDER BY name
    ")->fetchAll(PDO::FETCH_COLUMN);

    $keepLower = [strtolower($uac), strtolower($audit)];
    foreach ($dropNames as $name) {
        $name = (string) $name;
        if ($name === '' || in_array(strtolower($name), $keepLower, true)) {
            continue;
        }
        try {
            $safe = str_replace([']', '['], '', $name);
            $pdo->exec("DROP TABLE [dbo].[{$safe}]");
            echo "DROPPED {$name}\n";
        } catch (Throwable $e) {
            echo "DROP {$name} failed: {$e->getMessage()}\n";
        }
    }
    if (!$dropNames) {
        echo "(no duplicates found)\n";
    }

    echo "\n=== Remaining sap_report* / uac* tables ===\n";
    foreach ($pdo->query("
        SELECT name FROM sys.tables
        WHERE schema_id = SCHEMA_ID(N'dbo')
          AND (name LIKE N'sap_report%' OR name LIKE N'uac_%' OR name LIKE N'tblSapUser%')
        ORDER BY name
    ") as $r) {
        echo $r['name'] . "\n";
    }

    echo "\nDONE — only one access table: {$uac}\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
