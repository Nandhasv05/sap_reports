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
if (method_exists(ReportsController::class, 'showPoPassLog')) {
    $router->get('po-costing/pass-log', [ReportsController::class, 'showPoPassLog']);
}
if (method_exists(ReportsController::class, 'showPoValidationHistory')) {
    $router->get('po-costing/validation-history', [ReportsController::class, 'showPoValidationHistory']);
}
$router->get('po-costing', [ReportsController::class, 'showPoCosting']);
$router->get('po-costing-validation', [ReportsController::class, 'showPoCosting']);
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
if (method_exists(ReportsController::class, 'purchaseOrderPdf')) {
    $router->get('purchase-order/pdf', [ReportsController::class, 'purchaseOrderPdf']);
}
if (method_exists(ReportsController::class, 'purchaseOrderPass')) {
    $router->post('purchase-order/pass', [ReportsController::class, 'purchaseOrderPass']);
}
if (method_exists(ReportsController::class, 'purchaseOrderEsignSignature')) {
    $router->get('purchase-order/esign-signature', [ReportsController::class, 'purchaseOrderEsignSignature']);
    $router->post('purchase-order/esign-signature', [ReportsController::class, 'purchaseOrderEsignSignature']);
}
if (method_exists(ReportsController::class, 'purchaseOrderEsignSave')) {
    $router->post('purchase-order/esign-save', [ReportsController::class, 'purchaseOrderEsignSave']);
}
if (method_exists(ReportsController::class, 'purchaseOrderPassLog')) {
    $router->get('purchase-order/pass-log', [ReportsController::class, 'purchaseOrderPassLog']);
}
if (method_exists(ReportsController::class, 'purchaseOrderSignedFile')) {
    $router->get('purchase-order/signed-file', [ReportsController::class, 'purchaseOrderSignedFile']);
}
if (method_exists(ReportsController::class, 'purchaseOrderValidate')) {
    $router->get('purchase-order/validate', [ReportsController::class, 'purchaseOrderValidate']);
}
if (method_exists(ReportsController::class, 'purchaseOrderManualMapSave')) {
    $router->post('purchase-order/manual-map', [ReportsController::class, 'purchaseOrderManualMapSave']);
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
if (method_exists(ReportsController::class, 'purchaseOrderSendMail')) {
    $router->post('purchase-order/send-mail', [ReportsController::class, 'purchaseOrderSendMail']);
}
if (method_exists(ReportsController::class, 'purchaseOrderWorkflow')) {
    $router->get('purchase-order/workflow', [ReportsController::class, 'purchaseOrderWorkflow']);
}
if (method_exists(ReportsController::class, 'purchaseOrderWorkflowSave')) {
    $router->post('purchase-order/workflow/save', [ReportsController::class, 'purchaseOrderWorkflowSave']);
}
if (method_exists(ReportsController::class, 'purchaseOrderWorkflowNotify')) {
    $router->post('purchase-order/workflow/notify', [ReportsController::class, 'purchaseOrderWorkflowNotify']);
}
if (method_exists(ReportsController::class, 'purchaseOrderWorkflowDecide')) {
    $router->post('purchase-order/workflow/decide', [ReportsController::class, 'purchaseOrderWorkflowDecide']);
}
if (method_exists(ReportsController::class, 'purchaseOrderWorkflowAck')) {
    $router->post('purchase-order/workflow/ack', [ReportsController::class, 'purchaseOrderWorkflowAck']);
}
if (method_exists(ReportsController::class, 'purchaseOrderSentBox')) {
    $router->get('purchase-order/sent-box', [ReportsController::class, 'purchaseOrderSentBox']);
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