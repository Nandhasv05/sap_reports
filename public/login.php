<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Login file
 */
declare(strict_types=1);

if (!defined('EVOLV_ROOT')) {
    define('EVOLV_ROOT', dirname(__DIR__));
}
require EVOLV_ROOT . '/app/core/helpers.php';
header('Location: ' . sap_reports_evol_url('login.php'));
exit;
