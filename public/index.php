<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Index file
 */
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if (!defined('EVOLV_ROOT')) {
    define('EVOLV_ROOT', dirname(__DIR__));
}

require EVOLV_ROOT . '/app/core/helpers.php';
require EVOLV_ROOT . '/app/core/Controller.php';
require EVOLV_ROOT . '/app/core/Router.php';
require EVOLV_ROOT . '/app/core/portal_auth.php';
require EVOLV_ROOT . '/app/core/access.php';

evolv_boot_app_base();
portal_require_login();
sap_reports_require_access();

$routePath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($routePath, '/sap_reports')) {
    $routePath = substr($routePath, strlen('/sap_reports')) ?: '/';
}
$base = $GLOBALS['app_base'] ?? '';
if ($base !== '' && $base !== '/sap_reports' && str_starts_with($routePath, $base)) {
    $routePath = substr($routePath, strlen($base)) ?: '/';
}
$routePath = preg_replace('#/index\.php(/|$)#', '/', $routePath) ?: '/';
$routePath = preg_replace('#/+#', '/', $routePath) ?: '/';
if ($routePath === '/' || $routePath === '') {
    $r = strtolower(trim((string) ($_GET['r'] ?? '')));
    $routePath = $r !== '' ? '/' . $r : '/';
}

$router = new Router();
require EVOLV_ROOT . '/routes/web.php';
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $routePath);
