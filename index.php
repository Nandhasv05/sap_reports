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

if (preg_match('#^/(login|logout)\.php$#', $rel, $m)) {
    require EVOLV_ROOT . '/public/' . $m[1] . '.php';
    exit;
}

require EVOLV_ROOT . '/public/index.php';
