<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Trims Utilization routes
 */
/** @var Router $router */
sap_reports_require_module_controller('TrimsReportController');

$router->get('trims', [TrimsReportController::class, 'page']);
$router->get('trims/data', [TrimsReportController::class, 'reportData']);
?>
