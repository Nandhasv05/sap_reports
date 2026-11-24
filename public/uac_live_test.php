<?php
/**
 * Live diagnose + save for admin / maideen against sap_report_userascesscontrol
 * http://localhost:8888/sap_reports/public/uac_live_test.php
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');

if (!defined('EVOLV_ROOT')) {
    define('EVOLV_ROOT', dirname(__DIR__));
}
require EVOLV_ROOT . '/app/core/helpers.php';
require EVOLV_ROOT . '/app/core/Database.php';
require EVOLV_ROOT . '/app/controllers/UserAccessControl/UserAccessControlController.php';

$table = UserAccessControlController::ACCESS_TABLE;

try {
    $pdo = Database::connection();
    echo "TABLE={$table}\n";

    echo "\n=== COLUMNS ===\n";
    foreach ($pdo->query("
        SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, COLLATION_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA='dbo' AND LOWER(TABLE_NAME)=LOWER('{$table}')
        ORDER BY ORDINAL_POSITION
    ") as $r) {
        echo "{$r['COLUMN_NAME']} {$r['DATA_TYPE']}(" . ($r['CHARACTER_MAXIMUM_LENGTH'] ?? '-') . ") {$r['COLLATION_NAME']}\n";
    }

    echo "\n=== DUPLICATE TABLES ===\n";
    $dups = $pdo->query("
        SELECT name FROM sys.tables
        WHERE schema_id = SCHEMA_ID('dbo')
          AND (name LIKE 'sap_report_user%' OR name LIKE 'tblSapUser%' OR name LIKE 'uac_%')
        ORDER BY name
    ")->fetchAll(PDO::FETCH_COLUMN);
    echo $dups ? implode("\n", $dups) . "\n" : "(none)\n";

    echo "\n=== USERS ===\n";
    $users = $pdo->query("
        SELECT Id, UserName, Email, Role
        FROM [dbo].[users]
        WHERE LOWER(UserName) IN ('admin','maideen')
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as $u) {
        echo "id={$u['Id']} user={$u['UserName']} email={$u['Email']} role={$u['Role']}\n";
    }

    $ref = new ReflectionClass('UserAccessControlController');
    $ctrl = $ref->newInstanceWithoutConstructor();
    $persist = $ref->getMethod('persistPermissions');
    $persist->setAccessible(true);
    $ensure = $ref->getMethod('ensureTableExists');
    $ensure->setAccessible(true);
    $ensure->invoke($ctrl);

    $catalog = UserAccessControlController::getPagesCatalog();
    $map = [];
    foreach ($catalog as $p) {
        $map[$p['page_no']] = $p;
    }

    foreach ($users as $u) {
        $uid = (int) $u['Id'];
        $uname = (string) $u['UserName'];
        echo "\n=== SAVE {$uname} (id={$uid}) ===\n";
        $perms = [];
        foreach ($catalog as $p) {
            $row = [
                'page_no' => $p['page_no'],
                'can_view' => 1,
                'can_add' => 0,
                'can_edit' => 0,
                'can_approve' => 0,
                'can_copy' => 0,
                'can_assign' => 0,
                'can_validate' => 0,
            ];
            if (($p['kind'] ?? '') === 'po-costing') {
                $row['can_approve'] = 1;
                $row['can_assign'] = 1;
                $row['can_validate'] = 1;
            }
            if (($p['kind'] ?? '') === 'zcost') {
                $row['can_add'] = 1;
                $row['can_edit'] = 1;
                $row['can_copy'] = 1;
                $row['can_approve'] = 1;
            }
            $perms[] = $row;
        }
        try {
            $n = $persist->invoke($ctrl, $pdo, $table, $uid, $uname, $perms, $map);
            echo "OK saved={$n}\n";
            $rows = $pdo->prepare("SELECT page_no, page_name, can_view, can_approve, can_copy FROM [dbo].[{$table}] WHERE user_id=? ORDER BY page_no");
            $rows->execute([$uid]);
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                echo "  P{$r['page_no']} {$r['page_name']} v={$r['can_view']} a={$r['can_approve']} c={$r['can_copy']}\n";
            }
        } catch (Throwable $e) {
            echo "FAIL: " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
        }
    }

    echo "\nDONE\n";
} catch (Throwable $e) {
    echo "FATAL: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
