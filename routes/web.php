<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Web routes
 */
require_once base_path('app/controllers/ReportsController.php');
require_once base_path('app/controllers/ErrorController.php');
require_once base_path('app/controllers/TablePreferencesController.php');

/** @var Router $router */
$router->get('/', [ReportsController::class, 'index']);
$router->get('sales', function () {
    (new ReportsController())->show('sales');
});

$router->get('open', function () {
    (new ReportsController())->show('open');
});
$router->get('division', function () {
    (new ReportsController())->show('division');
});
$router->get('fabric/data', function () {
    (new ReportsController())->data('fabric');
});
$router->get('fabric', function () {
    (new ReportsController())->show('fabric');
});
$router->get('trims/data', function () {
    (new ReportsController())->data('trims');
});
$router->get('trims', function () {
    (new ReportsController())->show('trims');
});
$router->get('production/data', function () {
    (new ReportsController())->data('production');
});
$router->get('production', function () {
    (new ReportsController())->show('production');
});
$router->get('procurement/data', function () {
    (new ReportsController())->data('procurement');
});
$router->get('procurement', function () {
    (new ReportsController())->show('procurement');
});

$router->get('purchase-order/data', [ReportsController::class, 'purchaseOrderData']);
$router->get('purchase-order', [ReportsController::class, 'showPurchaseOrder']);

$router->get('api/user/table-preferences/{tableKey}', [TablePreferencesController::class, 'show']);
$router->put('api/user/table-preferences/{tableKey}', [TablePreferencesController::class, 'update']);
$router->delete('api/user/table-preferences/{tableKey}', [TablePreferencesController::class, 'destroy']);
