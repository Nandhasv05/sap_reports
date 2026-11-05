<?php
/**
 * Functional check: P2 PO Costing "View" permission
 * http://localhost:8888/sap_reports/public/uac_p2_view_test.php
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');

if (!defined('EVOLV_ROOT')) {
    define('EVOLV_ROOT', dirname(__DIR__));
}
require EVOLV_ROOT . '/app/core/helpers.php';
require EVOLV_ROOT . '/app/core/Database.php';
require EVOLV_ROOT . '/app/core/access.php';
require EVOLV_ROOT . '/app/controllers/UserAccessControlController.php';

$table = UserAccessControlController::ACCESS_TABLE;

function dumpFlags(PDO $pdo, string $table, int $uid): void
{
    $st = $pdo->prepare("
        SELECT page_no, page_route, CAST(can_view AS INT) v, CAST(can_approve AS INT) a,
               CAST(can_assign AS INT) s, CAST(can_validate AS INT) val
        FROM [dbo].[{$table}] WHERE user_id = ? AND page_no = 2
    ");
    $st->execute([$uid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    echo $r
        ? "  DB P2 route={$r['page_route']} view={$r['v']} pass={$r['a']} assign={$r['s']} validate={$r['val']}\n"
        : "  DB P2: (no row)\n";
}

try {
    $pdo = Database::connection();
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

    $users = $pdo->query("
        SELECT Id, UserName FROM [dbo].[users]
        WHERE LOWER(UserName) IN ('admin','maideen')
        ORDER BY Id
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as $u) {
        $uid = (int) $u['Id'];
        $uname = (string) $u['UserName'];
        $userArr = ['id' => $uid, 'username' => $uname, 'role' => 'Admin', 'is_admin' => 0];

        echo "======== {$uname} (id={$uid}) ========\n";

        // A) View ONLY
        $perms = [];
        foreach ($catalog as $p) {
            $row = [
                'page_no' => $p['page_no'],
                'can_view' => $p['page_no'] === 2 ? 1 : 0,
                'can_add' => 0, 'can_edit' => 0, 'can_approve' => 0,
                'can_copy' => 0, 'can_assign' => 0, 'can_validate' => 0,
            ];
            $perms[] = $row;
        }
        $persist->invoke($ctrl, $pdo, $table, $uid, $uname, $perms, $map);
        dumpFlags($pdo, $table, $uid);

        $canView = sap_reports_user_can('po-costing', 'view', $userArr);
        $canAccess = sap_reports_user_can_access('po-costing', $userArr);
        $canPass = sap_reports_user_can('po-costing', 'approve', $userArr);
        $canAssign = sap_reports_user_can('po-costing', 'assign', $userArr);
        $canValidate = sap_reports_user_can('po-costing', 'validate', $userArr);
        echo "  View-only => view=" . ($canView ? 'Y' : 'N')
            . " access=" . ($canAccess ? 'Y' : 'N')
            . " pass=" . ($canPass ? 'Y' : 'N')
            . " assign=" . ($canAssign ? 'Y' : 'N')
            . " validate=" . ($canValidate ? 'Y' : 'N') . "\n";

        $expectAccess = true; // view alone must open module
        $expectPass = in_array(strtolower($uname), ['admin', 'nandhakumar', 'saravanan', 'saravan'], true);
        $okA = ($canAccess === $expectAccess) && ($canPass === $expectPass) && ($canAssign === $expectPass) && ($canValidate === $expectPass);
        echo $okA ? "  PASS View-only\n" : "  FAIL View-only\n";

        // B) ALL OFF
        $permsOff = [];
        foreach ($catalog as $p) {
            $permsOff[] = [
                'page_no' => $p['page_no'],
                'can_view' => 0, 'can_add' => 0, 'can_edit' => 0, 'can_approve' => 0,
                'can_copy' => 0, 'can_assign' => 0, 'can_validate' => 0,
            ];
        }
        $persist->invoke($ctrl, $pdo, $table, $uid, $uname, $permsOff, $map);
        dumpFlags($pdo, $table, $uid);
        $canAccessOff = sap_reports_user_can_access('po-costing', $userArr);
        $canViewOff = sap_reports_user_can('po-costing', 'view', $userArr);
        $isSuper = in_array(strtolower($uname), ['admin', 'nandhakumar', 'saravanan', 'saravan'], true);
        $expectOff = $isSuper; // supers always allowed
        echo "  All-off => view=" . ($canViewOff ? 'Y' : 'N') . " access=" . ($canAccessOff ? 'Y' : 'N')
            . " (super=" . ($isSuper ? 'Y' : 'N') . ")\n";
        $okB = ($canAccessOff === $expectOff);
        echo $okB ? "  PASS All-off\n" : "  FAIL All-off\n";

        // C) Pass PO only (should auto-enable view in persist)
        $permsPass = [];
        foreach ($catalog as $p) {
            $permsPass[] = [
                'page_no' => $p['page_no'],
                'can_view' => 0,
                'can_add' => 0, 'can_edit' => 0,
                'can_approve' => $p['page_no'] === 2 ? 1 : 0,
                'can_copy' => 0, 'can_assign' => 0, 'can_validate' => 0,
            ];
        }
        $persist->invoke($ctrl, $pdo, $table, $uid, $uname, $permsPass, $map);
        dumpFlags($pdo, $table, $uid);
        $canAccessPass = sap_reports_user_can_access('po-costing', $userArr);
        $canPassOnly = sap_reports_user_can('po-costing', 'approve', $userArr);
        echo "  Pass-only => access=" . ($canAccessPass ? 'Y' : 'N') . " pass=" . ($canPassOnly ? 'Y' : 'N') . "\n";
        $okC = $canAccessPass === true && ($canPassOnly === true || $isSuper);
        echo $okC ? "  PASS Pass-only\n" : "  FAIL Pass-only\n";
        echo "\n";
    }

    // Restore useful defaults: admin all view, maideen view+pass on P2
    foreach ($users as $u) {
        $uid = (int) $u['Id'];
        $uname = (string) $u['UserName'];
        $perms = [];
        foreach ($catalog as $p) {
            $row = [
                'page_no' => $p['page_no'],
                'can_view' => 1,
                'can_add' => 0, 'can_edit' => 0, 'can_approve' => 0,
                'can_copy' => 0, 'can_assign' => 0, 'can_validate' => 0,
            ];
            if ($p['page_no'] === 2) {
                $row['can_approve'] = 1;
                $row['can_assign'] = 1;
                $row['can_validate'] = 1;
            }
            if (($p['kind'] ?? '') === 'zcost') {
                $row['can_add'] = $row['can_edit'] = $row['can_copy'] = $row['can_approve'] = 1;
            }
            $perms[] = $row;
        }
        $persist->invoke($ctrl, $pdo, $table, $uid, $uname, $perms, $map);
    }
    echo "Restored default grants for admin + maideen.\nDONE\n";
} catch (Throwable $e) {
    echo 'FAIL: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
?>