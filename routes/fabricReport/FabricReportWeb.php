<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Fabric Utilization routes
 */
/** @var Router $router */
sap_reports_require_module_controller('FabricReportController');

$router->get('fabric', [FabricReportController::class, 'page']);
$router->get('fabric/data', [FabricReportController::class, 'reportData']);
?>
