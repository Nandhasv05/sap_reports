<?php
/**
 * Read-only UAC schema check (does not ALTER columns).
 * http://localhost:8888/sap_reports/public/uac_diag.php
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');

if (!defined('EVOLV_ROOT')) {
    define('EVOLV_ROOT', dirname(__DIR__));
}
require EVOLV_ROOT . '/app/core/helpers.php';
require EVOLV_ROOT . '/app/core/Database.php';

$table = 'sap_report_userascesscontrol';

try {
    $pdo = Database::connection();
    echo "TABLE={$table}\n\n=== COLUMNS ===\n";
    foreach ($pdo->query("
        SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA='dbo' AND LOWER(TABLE_NAME)=LOWER('{$table}')
        ORDER BY ORDINAL_POSITION
    ") as $r) {
        echo "{$r['COLUMN_NAME']} {$r['DATA_TYPE']}(" . ($r['CHARACTER_MAXIMUM_LENGTH'] ?? '-') . ") null={$r['IS_NULLABLE']}\n";
    }

    echo "\n=== OTHER access-like tables ===\n";
    $others = $pdo->query("
        SELECT name FROM sys.tables
        WHERE schema_id = SCHEMA_ID('dbo')
          AND (name LIKE 'sap_report_user%' OR name LIKE 'tblSapUser%' OR name LIKE 'uac_%')
        ORDER BY name
    ")->fetchAll(PDO::FETCH_COLUMN);
    echo $others ? implode("\n", $others) . "\n" : "(only this table)\n";

    echo "\n=== ROW COUNTS BY USER ===\n";
    foreach ($pdo->query("
        SELECT user_id, username, COUNT(*) AS pages, SUM(CAST(can_view AS INT)) AS views
        FROM [dbo].[{$table}]
        GROUP BY user_id, username
        ORDER BY user_id
    ") as $r) {
        echo "uid={$r['user_id']} user={$r['username']} pages={$r['pages']} views={$r['views']}\n";
    }
    echo "\nDONE\n";
} catch (Throwable $e) {
    echo 'FAIL: ' . $e->getMessage() . "\n";
}
?>