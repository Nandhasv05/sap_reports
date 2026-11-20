<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 20/11/2026
 * DESCRIPTION : Token-gated cron endpoint for Daily PO E-Signature report (no portal login)
 *
 * Schedule at 23:00 IST:
 *   curl "http://localhost:8888/sap_reports/cron_daily_esign_report.php?key=evolv-daily-esign-2300"
 *   OR: php ../cli/send_daily_esign_report.php
 */
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Content-Type: application/json; charset=utf-8');

if (!defined('EVOLV_ROOT')) {
    define('EVOLV_ROOT', dirname(__DIR__));
}

require EVOLV_ROOT . '/app/core/helpers.php';
evolv_boot_app_base();

$cfg = config('app')['daily_esign_report'] ?? [];
if (empty($cfg['enabled'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Disabled', 'data' => []]);
    exit;
}

$expected = trim((string) ($cfg['cron_key'] ?? ''));
$given = trim((string) ($_GET['key'] ?? $_POST['key'] ?? ''));
if ($expected === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden', 'data' => []]);
    exit;
}

require_once base_path('app/services/PoDailyEsignReportService.php');

try {
    $result = (new PoDailyEsignReportService())->runAndSend();
    $ok = !empty($result['success']);
    http_response_code($ok ? 200 : 422);
    echo json_encode([
        'success' => $ok,
        'message' => $ok
            ? ('Daily report sent to ' . (int) ($result['sent'] ?? 0) . ' recipient(s)')
            : (string) ($result['error'] ?? 'Send failed'),
        'data' => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'data' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
