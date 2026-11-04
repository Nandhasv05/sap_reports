<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Web routes
 */
require_once base_path('app/controllers/ReportsController.php');
require_once base_path('app/controllers/ErrorController.php');
require_once base_path('app/controllers/TablePreferencesController.php');
require_once base_path('app/controllers/SalesController.php');

$auditController = base_path('app/controllers/AuditLogController.php');
$hasAuditLog = is_file($auditController);
if ($hasAuditLog) {
    require_once $auditController;
}

/** @var Router $router */
$router->get('/', [ReportsController::class, 'index']);
$router->get('404', [ErrorController::class, 'notFound']);

// START SALES DASHBOARD ROUTES (Migrated from KPIS)
$router->get('sales', [SalesController::class, 'index']);
$router->get('sales/data', [SalesController::class, 'data']);
$router->get('api/sales', [SalesController::class, 'data']);
$router->get('sales/procurement', [SalesController::class, 'procurementData']);
$router->get('sales/procurement-pr', [SalesController::class, 'procurementPrData']);
$router->get('sales/bom', [SalesController::class, 'bomData']);
$router->get('sales/material-api', [SalesController::class, 'materialApiData']);
$router->get('sales/sales-api', [SalesController::class, 'salesApiHubData']);
$router->get('sales/orders-by-date', [SalesController::class, 'ordersByDate']);
$router->get('fabric-sale', [ReportsController::class, 'showFabricSale']);
$router->get('sale', function () {
    header('Location: ' . url('sales'));
    exit;
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
$router->get('procurement/pr-details', function () {
    (new ReportsController())->prDetails();
});
$router->get('procurement', function () {
    (new ReportsController())->show('procurement');
});
$router->get('purchase-order/data', [ReportsController::class, 'purchaseOrderData']);
if (method_exists(ReportsController::class, 'purchaseOrderValidate')) {
    $router->get('purchase-order/validate', [ReportsController::class, 'purchaseOrderValidate']);
}
if (method_exists(ReportsController::class, 'purchaseOrderUsers')) {
    $router->get('purchase-order/users', [ReportsController::class, 'purchaseOrderUsers']);
}
if (method_exists(ReportsController::class, 'purchaseOrderValidationLog')) {
    $router->get('purchase-order/validation-log', [ReportsController::class, 'purchaseOrderValidationLog']);
}
if (method_exists(ReportsController::class, 'purchaseOrderValidationLogSave')) {
    $router->post('purchase-order/validation-log', [ReportsController::class, 'purchaseOrderValidationLogSave']);
}
$router->get('purchase-order', [ReportsController::class, 'showPurchaseOrder']);
$router->get('api/user/table-preferences/{tableKey}', [TablePreferencesController::class, 'show']);
$router->put('api/user/table-preferences/{tableKey}', [TablePreferencesController::class, 'update']);
$router->delete('api/user/table-preferences/{tableKey}', [TablePreferencesController::class, 'destroy']);
if ($hasAuditLog) {
    $router->get('audit-log', [AuditLogController::class, 'page']);
    $router->get('api/audit-log', [AuditLogController::class, 'index']);
}

require_once base_path('app/controllers/ZcostController.php');
$router->get('zcost', [ZcostController::class, 'page']);
$router->get('zcost/api', [ZcostController::class, 'api']);
$router->post('zcost/api', [ZcostController::class, 'api']);
$router->get('zcost/api.php', [ZcostController::class, 'api']);
$router->post('zcost/api.php', [ZcostController::class, 'api']);
$router->get('zcost/audit', [ZcostController::class, 'audit']);
$router->get('zcost/audit.php', [ZcostController::class, 'audit']);


require_once base_path('app/controllers/UserAccessControlController.php');
$router->get('user-access-control', [UserAccessControlController::class, 'index']);
$router->get('user-access', [UserAccessControlController::class, 'index']);
$router->get('useraccesscontrol', [UserAccessControlController::class, 'index']);
$router->get('api/user-access-control/get', [UserAccessControlController::class, 'getUserPermissions']);
$router->post('api/user-access-control/save', [UserAccessControlController::class, 'saveUserPermissions']);
?>