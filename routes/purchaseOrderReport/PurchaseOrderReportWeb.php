<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Purchase Order report + shared PO APIs (used by PO Costing too)
 */
/** @var Router $router */
sap_reports_require_module_controller('PurchaseOrderReportController');

$router->get('purchase-order', [PurchaseOrderReportController::class, 'page']);

/*
 * GET purchase-order/data — SAP API call
 * Request URL : http://APP-PROD.evolvclothing.com:8000/sap/opu/odata/sap/ZI_PURCHASEORDERAPI_HUB_CDS/ZI_PurchaseOrderAPI_HUB?$filter=PurchaseOrderDate%20ge%20datetime%272026-11-01T00%3A00%3A00%27%20and%20PurchaseOrderDate%20le%20datetime%272026-11-24T23%3A59%3A59%27%20and%20Plant%20eq%20%27P002%27&$format=json
 * Request Payload : {"from":"2026-11-01","to":"2026-11-24","Plant":"P002"}
 * Example : GET /purchase-order/data?range=this_month&from=2026-11-01&to=2026-11-24&plant=P002
 *
 * Single PO:
 * Request URL : …/ZI_PurchaseOrderAPI_HUB?$filter=PurchaseOrder%20eq%20%271000027905%27&$format=json
 * Request Payload : {"PurchaseOrder":"1000027905"}
 * Example : GET /purchase-order/data?po=1000027905
 */
$router->get('purchase-order/data', [PurchaseOrderReportController::class, 'reportData']);

if (method_exists(PurchaseOrderReportController::class, 'purchaseOrderPdf')) {
    /*
     * GET purchase-order/pdf — SAP API call
     * Request URL : http://APP-PROD.evolvclothing.com:8000/sap/opu/odata/sap/ZCOST_SRV/POPDFSet(EBELN='01000000543')/$value
     * Request Payload : {"EBELN":"1000000543"}
     * Example : GET /purchase-order/pdf?po=1000000543
     */
    $router->get('purchase-order/pdf', [PurchaseOrderReportController::class, 'purchaseOrderPdf']);
}
if (method_exists(PurchaseOrderReportController::class, 'purchaseOrderPass')) {
    /*
     * POST purchase-order/pass — SAP API call
     * Request URL : http://APP-PROD.evolvclothing.com:8000/sap/opu/odata/sap/ZCOST_SRV/POApprovalSet
     * Request Payload : {"PurchaseOrder":"1000027905"}
     * Example : POST /purchase-order/pass
     */
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
    /*
     * GET purchase-order/validate — SAP API call
     * Request URL : http://APP-PROD.evolvclothing.com:8000/sap/opu/odata/sap/Z_QUOTATIONZCOSTCOM_CDS/Z_QuotationZCostCom?$filter=PurchaseOrder%20eq%20%271000027905%27&$expand=to_Fabric,to_Trim,to_Packing,to_Special&$format=json
     * Request Payload : {"PurchaseOrder":"1000027905","mat_cat":"fabric","SalesOrder":"22000914"}
     * Example : GET /purchase-order/validate?po=1000027905&mat_cat=fabric&so=22000914
     */
    $router->get('purchase-order/validate', [PurchaseOrderReportController::class, 'purchaseOrderValidate']);

    /*
     * GET purchase-order/charges — SAP API call
     * Request URL : http://APP-PROD.evolvclothing.com:8000/sap/opu/odata/sap/Z_QUOTATIONZCOSTCOM_CDS/Z_QuotationZCostCom?$filter=PurchaseOrder%20eq%20%274000008618%27&$expand=to_Cond,to_GST&$top=1&$format=json
     * Request Payload : {"PurchaseOrder":"4000008618"}
     * Example : GET /purchase-order/charges?po=4000008618
     */
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
