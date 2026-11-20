<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 20/11/2026
 * DESCRIPTION : CLI — send Daily PO E-Signature report email (run at 23:00 IST)
 *
 * Usage:
 *   php cli/send_daily_esign_report.php
 *   php cli/send_daily_esign_report.php --dry-run
 *
 * Windows Task Scheduler (daily 23:00):
 *   Program: C:\MAMP\bin\php\php8.x.x\php.exe
 *   Arguments: C:\MAMP\htdocs\sap_reports\cli\send_daily_esign_report.php
 *   Start in: C:\MAMP\htdocs\sap_reports
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

define('EVOLV_ROOT', dirname(__DIR__));
require EVOLV_ROOT . '/app/core/helpers.php';
$GLOBALS['app_base'] = '/sap_reports';

require_once base_path('app/services/PoDailyEsignReportService.php');

$dry = in_array('--dry-run', $argv, true);
$svc = new PoDailyEsignReportService();
$asOf = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));

echo '[' . $asOf->format('Y-m-d H:i:s') . ' IST] Building Daily PO E-Signature report…' . PHP_EOL;

if ($dry) {
    $report = $svc->buildReport($asOf);
    $recipients = $svc->resolveRecipients();
    echo 'success=' . (!empty($report['success']) ? '1' : '0') . PHP_EOL;
    echo 'pending=' . (int) ($report['summary']['pending_for_sign'] ?? 0) . PHP_EOL;
    echo 'created_today=' . (int) ($report['summary']['created_today'] ?? 0) . PHP_EOL;
    echo 'signed_today=' . (int) ($report['summary']['signed_today'] ?? 0) . PHP_EOL;
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
echo 'pending=' . (int) ($result['summary']['pending_for_sign'] ?? 0) . PHP_EOL;
echo 'created_today=' . (int) ($result['summary']['created_today'] ?? 0) . PHP_EOL;
echo 'signed_today=' . (int) ($result['summary']['signed_today'] ?? 0) . PHP_EOL;
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
