<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Purchase Order report + shared PO APIs (used by PO Costing too)
 */
/** @var Router $router */
sap_reports_require_module_controller('PurchaseOrderReportController');

$router->get('purchase-order', [PurchaseOrderReportController::class, 'page']);
$router->get('purchase-order/data', [PurchaseOrderReportController::class, 'reportData']);

if (method_exists(PurchaseOrderReportController::class, 'purchaseOrderPdf')) {
    $router->get('purchase-order/pdf', [PurchaseOrderReportController::class, 'purchaseOrderPdf']);
}
if (method_exists(PurchaseOrderReportController::class, 'purchaseOrderPass')) {
    $router->post('purchase-order/pass', [PurchaseOrderReportController::class, 'purchaseOrderPass']);
}
if (method_exists(PurchaseOrderReportController::class, 'purchaseOrderPortalRevert')) {
    $router->post('purchase-order/portal-revert', [PurchaseOrderReportController::class, 'purchaseOrderPortalRevert']);
}
if (method_exists(PurchaseOrderReportController::class, 'purchaseOrderEsignSignature')) {
    $router->get('purchase-order/esign-signature', [PurchaseOrderReportController::class, 'purchaseOrderEsignSignature']);
    $router->post('purchase-order/esign-signature', [PurchaseOrderReportController::class, 'purchaseOrderEsignSignature']);
}
if (method_exists(PurchaseOrderReportController::class, 'purchaseOrderEsignSave')) {
    $router->post('purchase-order/esign-save', [PurchaseOrderReportController::class, 'purchaseOrderEsignSave']);
}
if (method_exists(PurchaseOrderReportController::class, 'purchaseOrderPassLog')) {
    $router->get('purchase-order/pass-log', [PurchaseOrderReportController::class, 'purchaseOrderPassLog']);
}
if (method_exists(PurchaseOrderReportController::class, 'purchaseOrderSignedFile')) {
    $router->get('purchase-order/signed-file', [PurchaseOrderReportController::class, 'purchaseOrderSignedFile']);
}
if (method_exists(PurchaseOrderReportController::class, 'purchaseOrderValidate')) {
    $router->get('purchase-order/validate', [PurchaseOrderReportController::class, 'purchaseOrderValidate']);
    $router->get('purchase-order/charges', [PurchaseOrderReportController::class, 'purchaseOrderCharges']);
}
if (method_exists(PurchaseOrderReportController::class, 'purchaseOrderManualMapSave')) {
    $router->post('purchase-order/manual-map', [PurchaseOrderReportController::class, 'purchaseOrderManualMapSave']);
}
if (method_exists(PurchaseOrderReportController::class, 'purchaseOrderUsers')) {
    $router->get('purchase-order/users', [PurchaseOrderReportController::class, 'purchaseOrderUsers']);
}
if (method_exists(PurchaseOrderReportController::class, 'purchaseOrderValidationLog')) {
    $router->get('purchase-order/validation-log', [PurchaseOrderReportController::class, 'purchaseOrderValidationLog']);
}
if (method_exists(PurchaseOrderReportController::class, 'purchaseOrderValidationLogSave')) {
    $router->post('purchase-order/validation-log', [PurchaseOrderReportController::class, 'purchaseOrderValidationLogSave']);
}
if (method_exists(PurchaseOrderReportController::class, 'purchaseOrderSendMail')) {
    $router->post('purchase-order/send-mail', [PurchaseOrderReportController::class, 'purchaseOrderSendMail']);
}
if (method_exists(PurchaseOrderReportController::class, 'purchaseOrderWorkflow')) {
    $router->get('purchase-order/workflow', [PurchaseOrderReportController::class, 'purchaseOrderWorkflow']);
}
if (method_exists(PurchaseOrderReportController::class, 'purchaseOrderWorkflowSave')) {
    $router->post('purchase-order/workflow/save', [PurchaseOrderReportController::class, 'purchaseOrderWorkflowSave']);
}
if (method_exists(PurchaseOrderReportController::class, 'purchaseOrderWorkflowNotify')) {
    $router->post('purchase-order/workflow/notify', [PurchaseOrderReportController::class, 'purchaseOrderWorkflowNotify']);
}
if (method_exists(PurchaseOrderReportController::class, 'purchaseOrderWorkflowDecide')) {
    $router->post('purchase-order/workflow/decide', [PurchaseOrderReportController::class, 'purchaseOrderWorkflowDecide']);
}
if (method_exists(PurchaseOrderReportController::class, 'purchaseOrderWorkflowAck')) {
    $router->post('purchase-order/workflow/ack', [PurchaseOrderReportController::class, 'purchaseOrderWorkflowAck']);
}
if (method_exists(PurchaseOrderReportController::class, 'purchaseOrderSentBox')) {
    $router->get('purchase-order/sent-box', [PurchaseOrderReportController::class, 'purchaseOrderSentBox']);
}
?>
