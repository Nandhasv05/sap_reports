<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 20/11/2026
 * DESCRIPTION : CLI — send Daily PO E-Signature report email (run at 23:00 IST)
 *
 * Usage (prefer Apache php.ini so pdo_odbc loads):
 *   php -c C:\MAMP\conf\php8.3.1\php.ini cli/send_daily_esign_report.php
 *   php -c C:\MAMP\conf\php8.3.1\php.ini cli/send_daily_esign_report.php --dry-run
 *
 * Or use: cli/schedule_daily_esign_report.bat (HTTP cron first, CLI fallback)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

define('EVOLV_ROOT', dirname(__DIR__));
require EVOLV_ROOT . '/app/core/helpers.php';
$GLOBALS['app_base'] = '/sap_reports';

// Fail fast with a clear message when CLI PHP has no SQL Server PDO driver
$drivers = PDO::getAvailableDrivers();
if (!in_array('odbc', $drivers, true) && !in_array('sqlsrv', $drivers, true)) {
    fwrite(STDERR, "ERROR: PDO ODBC/SQLSRV driver not loaded in this PHP CLI.\n");
    fwrite(STDERR, "Loaded drivers: " . (implode(', ', $drivers) ?: '(none)') . "\n");
    fwrite(STDERR, "Fix: run with Apache's php.ini, e.g.\n");
    fwrite(STDERR, "  C:\\MAMP\\bin\\php\\php8.3.1\\php.exe -c C:\\MAMP\\conf\\php8.3.1\\php.ini cli\\send_daily_esign_report.php --dry-run\n");
    fwrite(STDERR, "Or use HTTP cron (preferred):\n");
    fwrite(STDERR, "  curl \"https://apps.evolvclothing.com/sap_reports/cron_daily_esign_report.php?key=evolv-daily-esign-2300\"\n");
    exit(4);
}

require_once base_path('app/services/PoDailyEsignReportService.php');

$dry = in_array('--dry-run', $argv, true);
$svc = new PoDailyEsignReportService();
$asOf = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));

echo '[' . $asOf->format('Y-m-d H:i:s') . ' IST] Building Daily PO E-Signature report…' . PHP_EOL;

if ($dry) {
    $report = $svc->buildReport($asOf);
    $recipients = $svc->resolveRecipients();
    echo 'success=' . (!empty($report['success']) ? '1' : '0') . PHP_EOL;
    echo 'draft=' . (int) ($report['summary']['draft'] ?? 0) . PHP_EOL;
    echo 'review=' . (int) ($report['summary']['review'] ?? 0) . PHP_EOL;
    echo 'released=' . (int) ($report['summary']['released'] ?? 0) . PHP_EOL;
    echo 'approved=' . (int) ($report['summary']['approved'] ?? 0) . PHP_EOL;
    echo 'pending=' . (int) ($report['summary']['pending_for_sign'] ?? 0) . PHP_EOL;
    echo 'rows_scanned=' . (int) ($report['summary']['rows_scanned'] ?? 0) . PHP_EOL;
    echo 'source=' . (string) ($report['summary']['source_note'] ?? '') . PHP_EOL;
    echo 'sap=' . (!empty($report['summary']['sap_touched']) ? 'ok' : 'skip') . ' ' . (string) ($report['summary']['sap_note'] ?? '') . PHP_EOL;
    echo 'recipients=' . count($recipients) . PHP_EOL;
    foreach ($recipients as $r) {
        echo '  - ' . ($r['username'] ?? '') . ' <' . ($r['email'] ?? '') . '>' . PHP_EOL;
    }
    if (!empty($report['error'])) {
        echo 'error=' . $report['error'] . PHP_EOL;
        exit(2);
    }
    echo 'Dry run only — mail not sent.' . PHP_EOL;
    exit(0);
}

$result = $svc->runAndSend($asOf);
echo 'success=' . (!empty($result['success']) ? '1' : '0') . PHP_EOL;
echo 'sent=' . (int) ($result['sent'] ?? 0) . PHP_EOL;
echo 'failed=' . (int) ($result['failed'] ?? 0) . PHP_EOL;
echo 'draft=' . (int) ($result['summary']['draft'] ?? 0) . PHP_EOL;
echo 'review=' . (int) ($result['summary']['review'] ?? 0) . PHP_EOL;
echo 'released=' . (int) ($result['summary']['released'] ?? 0) . PHP_EOL;
echo 'approved=' . (int) ($result['summary']['approved'] ?? 0) . PHP_EOL;
echo 'pending=' . (int) ($result['summary']['pending_for_sign'] ?? 0) . PHP_EOL;
echo 'rows_scanned=' . (int) ($result['summary']['rows_scanned'] ?? 0) . PHP_EOL;
echo 'source=' . (string) ($result['summary']['source_note'] ?? '') . PHP_EOL;
foreach ($result['recipients'] ?? [] as $email) {
    echo '  to: ' . $email . PHP_EOL;
}
if (!empty($result['errors'])) {
    foreach ($result['errors'] as $err) {
        echo 'error: ' . $err . PHP_EOL;
    }
}
if (!empty($result['error'])) {
    echo 'error=' . $result['error'] . PHP_EOL;
}

exit(!empty($result['success']) ? 0 : 3);
?>
