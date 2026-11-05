<?php
/** Diagnose UAC save truncation — http://localhost:8888/sap_reports/uac_diag.php */
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');

if (!defined('EVOLV_ROOT')) {
    define('EVOLV_ROOT', dirname(__DIR__));
}
require EVOLV_ROOT . '/app/core/helpers.php';
require EVOLV_ROOT . '/app/core/Database.php';

$table = 'sap_report_userAccessControl';

try {
    $pdo = Database::connection();

    echo "=== COLUMNS ===\n";
    foreach ($pdo->query("
        SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA='dbo' AND LOWER(TABLE_NAME)=LOWER('{$table}')
        ORDER BY ORDINAL_POSITION
    ") as $r) {
        echo "{$r['COLUMN_NAME']} {$r['DATA_TYPE']}(" . ($r['CHARACTER_MAXIMUM_LENGTH'] ?? '-') . ") null={$r['IS_NULLABLE']}\n";
    }

    echo "\n=== MAX DATA LENGTHS ===\n";
    try {
        $mx = $pdo->query("
            SELECT
                MAX(LEN(username)) AS u,
                MAX(LEN(page_name)) AS n,
                MAX(LEN(page_route)) AS r,
                COUNT(*) AS rows
            FROM [dbo].[{$table}]
        ")->fetch(PDO::FETCH_ASSOC);
        echo "rows={$mx['rows']} max_username={$mx['u']} max_page_name={$mx['n']} max_page_route={$mx['r']}\n";
        foreach ($pdo->query("
            SELECT TOP 20 user_id, page_no, LEN(page_name) AS nlen, page_name, LEN(username) AS ulen, username
            FROM [dbo].[{$table}]
            ORDER BY LEN(page_name) DESC, LEN(username) DESC
        ") as $row) {
            echo "uid={$row['user_id']} p={$row['page_no']} name({$row['nlen']})={$row['page_name']} user({$row['ulen']})={$row['username']}\n";
        }
    } catch (Throwable $e) {
        echo "maxlen fail: {$e->getMessage()}\n";
    }

    echo "\n=== ALTER TO NVARCHAR(100) ===\n";
    foreach (['username' => 'NULL', 'page_name' => 'NOT NULL', 'page_route' => 'NULL'] as $col => $null) {
        try {
            $pdo->exec("ALTER TABLE [dbo].[{$table}] ALTER COLUMN [{$col}] NVARCHAR(100) {$null}");
            echo "ALTER {$col} OK\n";
        } catch (Throwable $e) {
            echo "ALTER {$col} FAIL: {$e->getMessage()}\n";
        }
    }

    echo "\n=== TRIGGERS ===\n";
    $tr = $pdo->query("
        SELECT t.name FROM sys.triggers t
        WHERE t.parent_id = OBJECT_ID(N'[dbo].[{$table}]')
    ")->fetchAll(PDO::FETCH_COLUMN);
    echo $tr ? implode("\n", $tr) . "\n" : "(none)\n";

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

    $uid = -99;
    $pdo->exec("DELETE FROM [dbo].[{$table}] WHERE user_id = {$uid}");

    echo "\n=== TEST A: direct bind (no CAST) ===\n";
    try {
        $ins = $pdo->prepare("
            INSERT INTO [dbo].[{$table}]
            (user_id, username, page_no, page_name, page_route, can_add, can_view, can_edit, can_approve, can_copy, can_assign, can_validate)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($pages as $p) {
            $ins->execute([$uid, 'admin', $p[0], $p[1], $p[2], 0, 1, 0, 0, 0, 0, 0]);
        }
        echo "OK 12 rows\n";
    } catch (Throwable $e) {
        echo "FAIL: {$e->getMessage()}\n";
    }
    $pdo->exec("DELETE FROM [dbo].[{$table}] WHERE user_id = {$uid}");

    echo "\n=== TEST B: CAST NVARCHAR(100) ===\n";
    try {
        $ins = $pdo->prepare("
            INSERT INTO [dbo].[{$table}]
            (user_id, username, page_no, page_name, page_route, can_add, can_view, can_edit, can_approve, can_copy, can_assign, can_validate)
            VALUES (
                ?,
                LEFT(CAST(? AS NVARCHAR(100)), 100),
                ?,
                LEFT(CAST(? AS NVARCHAR(100)), 100),
                LEFT(CAST(? AS NVARCHAR(100)), 100),
                ?, ?, ?, ?, ?, ?, ?
            )
        ");
        foreach ($pages as $p) {
            $ins->execute([$uid, 'admin', $p[0], $p[1], $p[2], 0, 1, 0, 0, 0, 0, 0]);
        }
        echo "OK 12 rows\n";
    } catch (Throwable $e) {
        echo "FAIL: {$e->getMessage()}\n";
    }
    $pdo->exec("DELETE FROM [dbo].[{$table}] WHERE user_id = {$uid}");

    echo "\n=== TEST C: UPDATE like save ===\n";
    try {
        $pdo->prepare("
            INSERT INTO [dbo].[{$table}]
            (user_id, username, page_no, page_name, page_route, can_view)
            VALUES (?, 'admin', 1, 'Purchase Order Report', 'purchase-order', 1)
        ")->execute([$uid]);
        $upd = $pdo->prepare("
            UPDATE [dbo].[{$table}]
            SET can_add=?, can_view=?, can_edit=?, can_approve=?, can_copy=?, can_assign=?, can_validate=?,
                username=?, page_name=?, page_route=?, updated_at=GETDATE()
            WHERE user_id=? AND page_no=?
        ");
        $upd->execute([0, 1, 0, 0, 0, 0, 0, 'admin', 'Purchase Order Report', 'purchase-order', $uid, 1]);
        echo "OK update\n";
    } catch (Throwable $e) {
        echo "FAIL: {$e->getMessage()}\n";
    }
    $pdo->exec("DELETE FROM [dbo].[{$table}] WHERE user_id = {$uid}");

    echo "\n=== TEST D: long username 200 chars ===\n";
    try {
        $long = str_repeat('x', 200);
        $pdo->prepare("
            INSERT INTO [dbo].[{$table}]
            (user_id, username, page_no, page_name, page_route, can_view)
            VALUES (?, ?, 1, 'X', 'x', 1)
        ")->execute([$uid, $long]);
        echo "OK (unexpected — column should reject)\n";
    } catch (Throwable $e) {
        echo "Expected fail: " . substr($e->getMessage(), 0, 120) . "\n";
    }
    $pdo->exec("DELETE FROM [dbo].[{$table}] WHERE user_id = {$uid}");

    echo "\n=== TEST E: delete+insert like new save (user -98) ===\n";
    try {
        $uid2 = -98;
        $pdo->exec("DELETE FROM [dbo].[{$table}] WHERE user_id = {$uid2}");
        $ins2 = $pdo->prepare("
            INSERT INTO [dbo].[{$table}]
            (user_id, username, page_no, page_name, page_route,
             can_add, can_view, can_edit, can_approve, can_copy, can_assign, can_validate,
             created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())
        ");
        foreach ($pages as $p) {
            $ins2->bindValue(1, $uid2, PDO::PARAM_INT);
            $ins2->bindValue(2, 'admin', PDO::PARAM_STR);
            $ins2->bindValue(3, $p[0], PDO::PARAM_INT);
            $ins2->bindValue(4, $p[1], PDO::PARAM_STR);
            $ins2->bindValue(5, $p[2], PDO::PARAM_STR);
            $ins2->bindValue(6, 0, PDO::PARAM_INT);
            $ins2->bindValue(7, 1, PDO::PARAM_INT);
            $ins2->bindValue(8, 0, PDO::PARAM_INT);
            $ins2->bindValue(9, 0, PDO::PARAM_INT);
            $ins2->bindValue(10, 0, PDO::PARAM_INT);
            $ins2->bindValue(11, 0, PDO::PARAM_INT);
            $ins2->bindValue(12, 0, PDO::PARAM_INT);
            $ins2->execute();
        }
        $n = (int) $pdo->query("SELECT COUNT(*) FROM [dbo].[{$table}] WHERE user_id = {$uid2}")->fetchColumn();
        $pdo->exec("DELETE FROM [dbo].[{$table}] WHERE user_id = {$uid2}");
        echo "OK {$n} rows via bindValue\n";
    } catch (Throwable $e) {
        echo "FAIL: {$e->getMessage()}\n";
    }

    echo "\nDONE\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
