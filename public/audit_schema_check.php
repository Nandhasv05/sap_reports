<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');
if (!defined('EVOLV_ROOT')) {
    define('EVOLV_ROOT', dirname(__DIR__));
}
require EVOLV_ROOT . '/app/core/helpers.php';
require EVOLV_ROOT . '/app/core/Database.php';
require EVOLV_ROOT . '/app/services/ApiAuditLogService.php';

try {
    $pdo = Database::connection();
    echo "=== COLUMNS ===\n";
    foreach ($pdo->query("
        SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA='dbo' AND LOWER(TABLE_NAME)='sap_report_auditlog'
        ORDER BY ORDINAL_POSITION
    ") as $r) {
        echo "{$r['COLUMN_NAME']} {$r['DATA_TYPE']}(" . ($r['CHARACTER_MAXIMUM_LENGTH'] ?? '-') . ")\n";
    }

    echo "\n=== FORCE LOG ===\n";
    $_SESSION['username'] = 'admin';
    $_SESSION['user_id'] = 1;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/sap_reports/api/user-access-control/save';
    $GLOBALS['sap_reports_raw_request_body'] = '{"user_id":1,"test":true}';
    ApiAuditLogService::log(200, ['success' => true, 'message' => 'schema check ok'], '/sap_reports/api/user-access-control/save', 'POST', ['user_id' => 1, 'test' => true], 'User Access Control API', 'OK — schema check ok');

    $row = $pdo->query("SELECT TOP 1 id, method, page_name, status_message, LEFT(request_payload,80) AS req, LEFT(response_payload,80) AS res FROM sap_report_auditLog ORDER BY id DESC")->fetch(PDO::FETCH_ASSOC);
    print_r($row);
    echo "DONE\n";
} catch (Throwable $e) {
    echo 'FAIL: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
