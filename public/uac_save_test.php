<?php
/**
 * Simulate exact UAC persist path — http://localhost:8888/sap_reports/uac_save_test.php
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');

if (!defined('EVOLV_ROOT')) {
    define('EVOLV_ROOT', dirname(__DIR__));
}
require EVOLV_ROOT . '/app/core/helpers.php';
require EVOLV_ROOT . '/app/core/Database.php';
require EVOLV_ROOT . '/app/controllers/UserAccessControl/UserAccessControlController.php';

try {
    $pdo = Database::connection();
    $ref = new ReflectionClass('UserAccessControlController');
    $ctrl = $ref->newInstanceWithoutConstructor();

    $catalog = UserAccessControlController::getPagesCatalog();
    $map = [];
    foreach ($catalog as $p) {
        $map[$p['page_no']] = $p;
    }

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

    $method = $ref->getMethod('persistPermissions');
    $method->setAccessible(true);

    $uid = -97;
    echo "Running persistPermissions for user_id={$uid}...\n";
    $n = $method->invoke($ctrl, $pdo, UserAccessControlController::ACCESS_TABLE, $uid, 'admin', $perms, $map);
    echo "Saved {$n} pages OK\n";

    $rows = $pdo->query("SELECT page_no, page_name, page_route, can_view, can_approve, can_copy FROM [dbo].[sap_report_userascesscontrol] WHERE user_id = {$uid} ORDER BY page_no")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo "P{$r['page_no']} {$r['page_name']} /{$r['page_route']} v={$r['can_view']} a={$r['can_approve']} c={$r['can_copy']}\n";
    }
    $pdo->exec("DELETE FROM [dbo].[sap_report_userascesscontrol] WHERE user_id = {$uid}");
    echo "CLEANED\n";
    echo "CODE_VERSION=persist-v6-userascesscontrol\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
