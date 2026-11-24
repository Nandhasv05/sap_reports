<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Production report routes
 */
/** @var Router $router */
sap_reports_require_module_controller('ProductionReportController');

$router->get('production', [ProductionReportController::class, 'page']);
$router->get('production/data', [ProductionReportController::class, 'reportData']);
?>
