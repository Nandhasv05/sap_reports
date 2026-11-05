<?php
/**
 * Full saveUserPermissions path (same as Access Studio Save) for admin + maideen.
 * http://localhost:8888/sap_reports/public/uac_api_save_test.php
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');

if (!defined('EVOLV_ROOT')) {
    define('EVOLV_ROOT', dirname(__DIR__));
}
require EVOLV_ROOT . '/app/core/helpers.php';
require EVOLV_ROOT . '/app/core/Database.php';
require EVOLV_ROOT . '/app/core/portal_auth.php';
require EVOLV_ROOT . '/app/controllers/UserAccessControlController.php';
require_once EVOLV_ROOT . '/app/services/ApiAuditLogService.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['username'] = 'admin';
$_SESSION['role'] = 'Admin';
$_SESSION['is_admin'] = 1;
$_SESSION['user_id'] = 1;
$_SESSION['department'] = 'IT';

try {
    $pdo = Database::connection();

    echo "=== auditLog columns ===\n";
    foreach ($pdo->query("
        SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA='dbo' AND LOWER(TABLE_NAME)='sap_report_auditlog'
        ORDER BY ORDINAL_POSITION
    ") as $r) {
        echo "{$r['COLUMN_NAME']} {$r['DATA_TYPE']}(" . ($r['CHARACTER_MAXIMUM_LENGTH'] ?? '-') . ")\n";
    }

    $users = $pdo->query("
        SELECT Id, UserName FROM [dbo].[users]
        WHERE LOWER(UserName) IN ('admin','maideen')
        ORDER BY Id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $ctrl = new UserAccessControlController();
    $ref = new ReflectionClass($ctrl);
    $method = $ref->getMethod('saveUserPermissions');

    foreach ($users as $u) {
        $uid = (int) $u['Id'];
        $uname = (string) $u['UserName'];
        echo "\n=== HTTP-like save for {$uname} ===\n";

        $catalog = UserAccessControlController::getPagesCatalog();
        $permissions = [];
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
                $row['can_approve'] = $row['can_assign'] = $row['can_validate'] = 1;
            }
            if (($p['kind'] ?? '') === 'zcost') {
                $row['can_add'] = $row['can_edit'] = $row['can_copy'] = $row['can_approve'] = 1;
            }
            $permissions[] = $row;
        }

        // Simulate php://input by calling persist via the public method internals
        // Capture output from saveUserPermissions by overriding input
        $payload = json_encode([
            'user_id' => $uid,
            'username' => $uname,
            'permissions' => $permissions,
        ], JSON_UNESCAPED_UNICODE);

        // Direct invoke of private pieces in order matching saveUserPermissions
        $ensure = $ref->getMethod('ensureTableExists');
        $ensure->setAccessible(true);
        $ensure->invoke($ctrl);

        $ensureBit = $ref->getMethod('ensureBitColumns');
        $ensureBit->setAccessible(true);
        $ensureBit->invoke($ctrl, $pdo);

        $ensureWide = $ref->getMethod('ensureStringColumnsWide');
        $ensureWide->setAccessible(true);
        $ensureWide->invoke($ctrl, $pdo, false);

        $persist = $ref->getMethod('persistPermissions');
        $persist->setAccessible(true);

        $map = [];
        foreach ($catalog as $p) {
            $map[$p['page_no']] = $p;
        }

        try {
            $n = $persist->invoke($ctrl, $pdo, UserAccessControlController::ACCESS_TABLE, $uid, $uname, $permissions, $map);
            echo "persist OK saved={$n}\n";
            ApiAuditLogService::log(200, ['success' => true, 'saved' => $n, 'user' => $uname], '/sap_reports/api/user-access-control/save');
            echo "audit log OK\n";
        } catch (Throwable $e) {
            echo "FAIL: " . $e->getMessage() . "\n";
            // Try forced rebuild path like the controller
            if (str_contains($e->getMessage(), '22001') || stripos($e->getMessage(), 'truncat') !== false) {
                echo "Retrying with force rebuild...\n";
                $ensureWide->invoke($ctrl, $pdo, true);
                $n = $persist->invoke($ctrl, $pdo, UserAccessControlController::ACCESS_TABLE, $uid, $uname, $permissions, $map);
                echo "retry OK saved={$n}\n";
            }
        }
    }

    // Mimic worst-case: username = email from UI mis-bind
    echo "\n=== edge: username=email for admin ===\n";
    try {
        $persist = $ref->getMethod('persistPermissions');
        $persist->setAccessible(true);
        $map = [];
        foreach (UserAccessControlController::getPagesCatalog() as $p) {
            $map[$p['page_no']] = $p;
        }
        $perms = array_map(static fn ($p) => [
            'page_no' => $p['page_no'], 'can_view' => 1,
            'can_add' => 0, 'can_edit' => 0, 'can_approve' => 0,
            'can_copy' => 0, 'can_assign' => 0, 'can_validate' => 0,
        ], UserAccessControlController::getPagesCatalog());
        $n = $persist->invoke($ctrl, $pdo, UserAccessControlController::ACCESS_TABLE, 1, 'nandhakumar@evolvclothing.com', $perms, $map);
        echo "email-as-username OK saved={$n}\n";
    } catch (Throwable $e) {
        echo "email-as-username FAIL: " . $e->getMessage() . "\n";
    }

    echo "\nDONE\n";
} catch (Throwable $e) {
    echo "FATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
