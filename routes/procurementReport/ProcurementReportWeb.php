<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Procurement report routes
 */
/** @var Router $router */
sap_reports_require_module_controller('ProcurementReportController');

$router->get('procurement', [ProcurementReportController::class, 'page']);
$router->get('procurement/data', [ProcurementReportController::class, 'reportData']);
$router->get('procurement/pr-details', [ProcurementReportController::class, 'prDetails']);
?>
