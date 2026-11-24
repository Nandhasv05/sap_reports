<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Fabric Sale Order routes
 */
/** @var Router $router */
sap_reports_require_module_controller('FabricSaleReportController');

$router->get('fabric-sale', [FabricSaleReportController::class, 'page']);
?>
