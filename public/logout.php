<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Logout file
 */
declare(strict_types=1);

if (!defined('EVOLV_ROOT')) {
    define('EVOLV_ROOT', dirname(__DIR__));
}
require EVOLV_ROOT . '/app/core/helpers.php';
require EVOLV_ROOT . '/app/core/portal_auth.php';
evolv_boot_app_base();
portal_logout();
header('Location: ' . sap_reports_evol_url('login.php'));
exit;
