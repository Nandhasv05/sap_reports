<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Index PHP
 */
declare(strict_types=1);

/*
 * Define the root directory
 */
if (!defined('EVOLV_ROOT')) {
    define('EVOLV_ROOT', __DIR__);
}

$_SERVER['SCRIPT_NAME'] = '/sap_reports/index.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$rel = preg_replace('#^/sap_reports(?=/|$)#', '', $path) ?: '/';
$rel = preg_replace('#/index\.php$#', '/', $rel) ?: '/';

// Public PHP entrypoints (no portal session) — cron uses ?key= secret only
if (preg_match('#^/(login|logout|uac_repair|uac_diag|uac_save_test|cron_daily_esign_report)\.php$#', $rel, $m)) {
    require EVOLV_ROOT . '/public/' . $m[1] . '.php';
    exit;
}

if (preg_match('#^/(css|js|assets|zcost)/(.+)$#', $rel, $m)) {
    $staticFile = EVOLV_ROOT . '/public/' . $m[1] . '/' . $m[2];
    if (is_file($staticFile)) {
        $ext = strtolower(pathinfo($staticFile, PATHINFO_EXTENSION));
        $mimes = [
            'css'   => 'text/css; charset=UTF-8',
            'js'    => 'application/javascript; charset=UTF-8',
            'json'  => 'application/json; charset=UTF-8',
            'png'   => 'image/png',
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'gif'   => 'image/gif',
            'svg'   => 'image/svg+xml',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf'   => 'font/ttf',
            'eot'   => 'application/vnd.ms-fontobject',
        ];
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($staticFile));
        header('Cache-Control: public, max-age=86400');
        readfile($staticFile);
        exit;
    }
}

require EVOLV_ROOT . '/public/index.php';
?>