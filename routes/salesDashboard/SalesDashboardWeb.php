<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Sales Dashboard routes
 */
/** @var Router $router */
sap_reports_require_module_controller('SalesController');

$router->get('sales', [SalesController::class, 'index']);
$router->get('sales/data', [SalesController::class, 'data']);
$router->get('api/sales', [SalesController::class, 'data']);
$router->get('sales/procurement', [SalesController::class, 'procurementData']);
$router->get('sales/procurement-pr', [SalesController::class, 'procurementPrData']);
$router->get('sales/bom', [SalesController::class, 'bomData']);
$router->get('sales/material-api', [SalesController::class, 'materialApiData']);
$router->get('sales/sales-api', [SalesController::class, 'salesApiHubData']);
$router->get('sales/orders-by-date', [SalesController::class, 'ordersByDate']);

$router->get('sale', function () {
    header('Location: ' . url('sales'));
    exit;
});
$router->get('open', function () {
    sap_reports_require_module_controller('ReportsController');
    (new ReportsController())->show('open');
});
$router->get('division', function () {
    sap_reports_require_module_controller('ReportsController');
    (new ReportsController())->show('division');
});
?>
