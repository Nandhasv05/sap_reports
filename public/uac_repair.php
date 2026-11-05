<?php
/**
 * One-shot schema repair for sap_report_userAccessControl
 * Open: http://localhost:8888/sap_reports/uac_repair.php
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=UTF-8');

if (!defined('EVOLV_ROOT')) {
    define('EVOLV_ROOT', dirname(__DIR__));
}
require EVOLV_ROOT . '/app/core/helpers.php';
require EVOLV_ROOT . '/app/core/Database.php';

$table = 'sap_report_userAccessControl';
$suffix = date('YmdHis');

try {
    $pdo = Database::connection();
    echo "Connected OK\n";

    $cols = $pdo->query("
        SELECT c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE, c.CHARACTER_MAXIMUM_LENGTH
        FROM INFORMATION_SCHEMA.COLUMNS c
        WHERE c.TABLE_SCHEMA = 'dbo'
          AND LOWER(c.TABLE_NAME) IN (LOWER('{$table}'), 'tblsapuseracesscontrol')
        ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "=== BEFORE ===\n";
    if (!$cols) {
        echo "(no columns found)\n";
    }
    foreach ($cols as $r) {
        echo "{$r['TABLE_NAME']}.{$r['COLUMN_NAME']} {$r['DATA_TYPE']}(" . ($r['CHARACTER_MAXIMUM_LENGTH'] ?? '-') . ")\n";
    }

    $bak = $table . '_bak_' . $suffix;
    $exists = $pdo->query("SELECT OBJECT_ID(N'[dbo].[{$table}]', N'U')")->fetchColumn();
    if ($exists) {
        // Prefer copy+drop so constraint names are freed for recreate
        try {
            $pdo->exec("SELECT * INTO [dbo].[{$bak}] FROM [dbo].[{$table}]");
            echo "Copied data -> {$bak}\n";
            $pdo->exec("DROP TABLE [dbo].[{$table}]");
            echo "Dropped old {$table}\n";
        } catch (Throwable $e) {
            echo "Copy/drop failed: {$e->getMessage()}\nTrying rename...\n";
            try {
                $pdo->exec("EXEC sp_rename N'[dbo].[{$table}]', N'{$bak}'");
                echo "Renamed old table -> {$bak}\n";
                // Drop constraints on bak so names can be reused (SQL Server keeps them after rename)
                $cons = $pdo->query("
                    SELECT kc.name
                    FROM sys.key_constraints kc
                    WHERE kc.parent_object_id = OBJECT_ID(N'[dbo].[{$bak}]')
                    UNION
                    SELECT dc.name
                    FROM sys.default_constraints dc
                    WHERE dc.parent_object_id = OBJECT_ID(N'[dbo].[{$bak}]')
                ")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($cons as $cname) {
                    $cname = (string) $cname;
                    if ($cname === '') {
                        continue;
                    }
                    try {
                        $pdo->exec("ALTER TABLE [dbo].[{$bak}] DROP CONSTRAINT [{$cname}]");
                        echo "Dropped constraint {$cname} on bak\n";
                    } catch (Throwable $ce) {
                        echo "Constraint drop {$cname}: {$ce->getMessage()}\n";
                    }
                }
            } catch (Throwable $e2) {
                echo "Rename failed: {$e2->getMessage()}\n";
                $pdo->exec("IF OBJECT_ID(N'[dbo].[{$table}]', N'U') IS NOT NULL DROP TABLE [dbo].[{$table}]");
                echo "Dropped old table\n";
                $bak = '';
            }
        }
    } else {
        // Recover from a failed prior rename: find newest bak
        $baks = $pdo->query("
            SELECT name FROM sys.tables
            WHERE schema_id = SCHEMA_ID('dbo')
              AND name LIKE 'sap_report_userAccessControl_bak_%'
            ORDER BY name DESC
        ")->fetchAll(PDO::FETCH_COLUMN);
        if ($baks) {
            $bak = (string) $baks[0];
            echo "Main table missing; will restore from {$bak}\n";
            foreach ($baks as $bname) {
                $cons = $pdo->query("
                    SELECT kc.name FROM sys.key_constraints kc
                    WHERE kc.parent_object_id = OBJECT_ID(N'[dbo].[{$bname}]')
                    UNION
                    SELECT dc.name FROM sys.default_constraints dc
                    WHERE dc.parent_object_id = OBJECT_ID(N'[dbo].[{$bname}]')
                ")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($cons as $cname) {
                    $cname = (string) $cname;
                    if ($cname === '') continue;
                    try {
                        $pdo->exec("ALTER TABLE [dbo].[{$bname}] DROP CONSTRAINT [{$cname}]");
                    } catch (Throwable $ignored) {
                    }
                }
            }
        } else {
            $bak = '';
            echo "Table did not exist yet\n";
        }
    }

    $pdo->exec("
        IF OBJECT_ID(N'[dbo].[{$table}]', N'U') IS NOT NULL DROP TABLE [dbo].[{$table}];
        CREATE TABLE [dbo].[{$table}] (
            [id] INT IDENTITY(1,1) NOT NULL,
            [user_id] INT NOT NULL,
            [username] NVARCHAR(100) NULL,
            [page_no] INT NOT NULL,
            [page_name] NVARCHAR(100) NOT NULL,
            [page_route] NVARCHAR(100) NULL,
            [can_add] BIT NOT NULL CONSTRAINT [DF_uac_{$suffix}_add] DEFAULT 0,
            [can_view] BIT NOT NULL CONSTRAINT [DF_uac_{$suffix}_view] DEFAULT 0,
            [can_edit] BIT NOT NULL CONSTRAINT [DF_uac_{$suffix}_edit] DEFAULT 0,
            [can_approve] BIT NOT NULL CONSTRAINT [DF_uac_{$suffix}_approve] DEFAULT 0,
            [can_copy] BIT NOT NULL CONSTRAINT [DF_uac_{$suffix}_copy] DEFAULT 0,
            [can_assign] BIT NOT NULL CONSTRAINT [DF_uac_{$suffix}_assign] DEFAULT 0,
            [can_validate] BIT NOT NULL CONSTRAINT [DF_uac_{$suffix}_validate] DEFAULT 0,
            [created_at] DATETIME NOT NULL CONSTRAINT [DF_uac_{$suffix}_created] DEFAULT GETDATE(),
            [updated_at] DATETIME NOT NULL CONSTRAINT [DF_uac_{$suffix}_updated] DEFAULT GETDATE(),
            CONSTRAINT [PK_uac_{$suffix}] PRIMARY KEY ([id]),
            CONSTRAINT [uq_uac_{$suffix}_user_page] UNIQUE ([user_id], [page_no])
        )
    ");
    echo "Created {$table}\n";

    if ($bak !== '') {
        try {
            $pdo->exec("
                INSERT INTO [dbo].[{$table}]
                (user_id, username, page_no, page_name, page_route, can_add, can_view, can_edit, can_approve, created_at, updated_at)
                SELECT
                    user_id,
                    LEFT(CAST(ISNULL(username,'') AS NVARCHAR(100)), 100),
                    page_no,
                    LEFT(CAST(ISNULL(page_name,'Page') AS NVARCHAR(100)), 100),
                    LEFT(CAST(ISNULL(page_route,'') AS NVARCHAR(100)), 100),
                    CAST(ISNULL(can_add,0) AS BIT),
                    CAST(ISNULL(can_view,0) AS BIT),
                    CAST(ISNULL(can_edit,0) AS BIT),
                    CAST(ISNULL(can_approve,0) AS BIT),
                    ISNULL(created_at, GETDATE()),
                    ISNULL(updated_at, GETDATE())
                FROM [dbo].[{$bak}]
            ");
            $n = (int) $pdo->query("SELECT COUNT(*) FROM [dbo].[{$table}]")->fetchColumn();
            echo "Restored {$n} rows from {$bak}\n";
        } catch (Throwable $e) {
            echo "Restore skipped: {$e->getMessage()}\n";
        }
    }

    try {
        $legacy = $pdo->query("SELECT OBJECT_ID(N'[dbo].[tblSapUseracessControl]', N'U')")->fetchColumn();
        $count = (int) $pdo->query("SELECT COUNT(*) FROM [dbo].[{$table}]")->fetchColumn();
        if ($legacy && $count === 0) {
            $pdo->exec("
                INSERT INTO [dbo].[{$table}]
                (user_id, username, page_no, page_name, page_route, can_add, can_view, can_edit, can_approve, created_at, updated_at)
                SELECT
                    user_id,
                    LEFT(CAST(ISNULL(username,'') AS NVARCHAR(100)), 100),
                    page_no,
                    LEFT(CAST(ISNULL(page_name,'Page') AS NVARCHAR(100)), 100),
                    LEFT(CAST(ISNULL(page_route,'') AS NVARCHAR(100)), 100),
                    CAST(ISNULL(can_add,0) AS BIT),
                    CAST(ISNULL(can_view,0) AS BIT),
                    CAST(ISNULL(can_edit,0) AS BIT),
                    CAST(ISNULL(can_approve,0) AS BIT),
                    ISNULL(created_at, GETDATE()),
                    ISNULL(updated_at, GETDATE())
                FROM [dbo].[tblSapUseracessControl]
            ");
            echo "Migrated from tblSapUseracessControl\n";
        }
    } catch (Throwable $e) {
        echo "Legacy migrate: {$e->getMessage()}\n";
    }

    $pdo->exec("DELETE FROM [dbo].[{$table}] WHERE user_id = -1");
    $pages = [
        [1, 'Purchase Order Report', 'purchase-order'],
        [2, 'PO Costing Validation', 'po-costing'],
        [3, 'Sales Dashboard', 'sales'],
        [4, 'Fabric Utilization', 'fabric'],
        [5, 'Fabric Sale Order', 'fabric-sale'],
        [6, 'Production Report', 'production'],
        [7, 'Procurement Report', 'procurement'],
        [8, 'Trims Utilization', 'trims'],
        [9, 'Overall Utilization', 'overall'],
        [10, 'Audit Log', 'audit-log'],
        [11, 'User Access Control', 'user-access-control'],
        [12, 'Zcost Costing Sheet', 'zcost'],
    ];
    $ins = $pdo->prepare("
        INSERT INTO [dbo].[{$table}]
        (user_id, username, page_no, page_name, page_route, can_add, can_view, can_edit, can_approve, can_copy, can_assign, can_validate)
        VALUES (
            -1,
            LEFT(CAST(? AS NVARCHAR(100)), 100),
            ?,
            LEFT(CAST(? AS NVARCHAR(100)), 100),
            LEFT(CAST(? AS NVARCHAR(100)), 100),
            0, 1, 0, 0, 0, 0, 0
        )
    ");
    foreach ($pages as $p) {
        $ins->execute(['admin', $p[0], $p[1], $p[2]]);
    }
    $pdo->exec("DELETE FROM [dbo].[{$table}] WHERE user_id = -1");
    echo "Smoke insert OK (12 catalog pages)\n";

    $cols2 = $pdo->query("
        SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'dbo' AND LOWER(TABLE_NAME) = LOWER('{$table}')
        ORDER BY ORDINAL_POSITION
    ")->fetchAll(PDO::FETCH_ASSOC);
    echo "=== AFTER ===\n";
    foreach ($cols2 as $r) {
        echo "{$r['COLUMN_NAME']} {$r['DATA_TYPE']}(" . ($r['CHARACTER_MAXIMUM_LENGTH'] ?? '-') . ")\n";
    }
    echo "DONE — Save Permissions again on Access Studio.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
