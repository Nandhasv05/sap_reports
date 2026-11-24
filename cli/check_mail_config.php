<?php
/*
 * Check / test outbound mail config (Daily E-Sign report uses this).
 *
 * Usage:
 *   php cli/check_mail_config.php
 *   php cli/check_mail_config.php --send you@evolvclothing.com
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

define('EVOLV_ROOT', dirname(__DIR__));
require EVOLV_ROOT . '/app/core/helpers.php';
$GLOBALS['app_base'] = '/sap_reports';

$mail = function_exists('config') ? (config('mail') ?: []) : [];
$from = is_array($mail['from'] ?? null) ? $mail['from'] : [];
$smtp = is_array($mail['smtp'] ?? null) ? $mail['smtp'] : [];
$pass = (string) ($smtp['password'] ?? '');
$masked = $pass === ''
    ? '(empty)'
    : (strlen($pass) <= 4 ? str_repeat('*', strlen($pass)) : substr($pass, 0, 2) . str_repeat('*', max(4, strlen($pass) - 4)) . substr($pass, -2));

echo "=== Mail config (config/mail.php) ===\n";
echo 'enabled       : ' . (!empty($mail['enabled']) ? 'yes' : 'no') . "\n";
echo 'driver        : ' . (string) ($mail['driver'] ?? '') . "\n";
echo 'from.name     : ' . (string) ($from['name'] ?? '') . "\n";
echo 'from.address  : ' . (string) ($from['address'] ?? '') . "\n";
echo 'smtp.host     : ' . (string) ($smtp['host'] ?? '') . "\n";
echo 'smtp.port     : ' . (string) ($smtp['port'] ?? '') . "\n";
echo 'smtp.enc      : ' . (string) ($smtp['encryption'] ?? '') . "\n";
echo 'smtp.username : ' . (string) ($smtp['username'] ?? '') . "\n";
echo 'smtp.password : ' . $masked . ' (len=' . strlen($pass) . ")\n";
echo 'fallback_dom  : ' . (string) ($mail['fallback_domain'] ?? '') . "\n";

echo "\n=== Daily report recipients (Workflow → Daily Report) ===\n";
try {
    require_once base_path('app/services/PoDailyEsignReportService.php');
    $recipients = (new PoDailyEsignReportService())->resolveRecipients();
    if ($recipients === []) {
        echo "(none) — add users under Daily Report Assignment and Save.\n";
    } else {
        foreach ($recipients as $r) {
            echo '  - ' . ($r['username'] ?? '') . ' <' . ($r['email'] ?? '') . ">\n";
        }
    }
} catch (Throwable $e) {
    echo 'recipient check failed: ' . $e->getMessage() . "\n";
}

$sendTo = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--send' && isset($argv[$i + 1])) {
        $sendTo = trim((string) $argv[$i + 1]);
    }
}

if ($sendTo === null) {
    echo "\nTip: send a test mail with:\n";
    echo "  php cli/check_mail_config.php --send your.name@evolvclothing.com\n";
    echo "Dry-run daily report:\n";
    echo "  php cli/send_daily_esign_report.php --dry-run\n";
    exit(0);
}

if (!filter_var($sendTo, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid --send email: {$sendTo}\n");
    exit(2);
}

require_once base_path('app/services/SimpleMailService.php');
$mailer = new SimpleMailService($mail);
$fromEmail = (string) ($from['address'] ?? 'apps@evolvclothing.com');
$fromName = (string) ($from['name'] ?? 'Apps');
$html = '<p>This is a test from <b>sap_reports</b> mail config.</p>'
    . '<p>From: ' . htmlspecialchars($fromName . ' &lt;' . $fromEmail . '&gt;', ENT_QUOTES, 'UTF-8') . '</p>'
    . '<p>Time: ' . date('Y-m-d H:i:s') . ' IST</p>';

echo "\n=== Sending test mail to {$sendTo} ===\n";
$result = $mailer->send([$sendTo], 'sap_reports mail config test', $html, $fromEmail, $fromName);
echo 'sent=' . (int) ($result['sent'] ?? 0) . "\n";
echo 'failed=' . (int) ($result['failed'] ?? 0) . "\n";
echo 'driver=' . (string) ($result['driver'] ?? '') . "\n";
if (!empty($result['errors'])) {
    foreach ($result['errors'] as $err) {
        echo 'error: ' . $err . "\n";
    }
}
exit(((int) ($result['sent'] ?? 0)) > 0 ? 0 : 3);
