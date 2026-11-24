<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : PO Costing / Validation page routes
 *               Shared pass/esign/PDF/workflow APIs stay on purchaseOrderReport module.
 */
/** @var Router $router */
sap_reports_require_module_controller('PoCostingValidationController');

$router->get('po-costing', [PoCostingValidationController::class, 'page']);
$router->get('po-costing-validation', [PoCostingValidationController::class, 'page']);

if (method_exists(PoCostingValidationController::class, 'passLogPage')) {
    $router->get('po-costing/pass-log', [PoCostingValidationController::class, 'passLogPage']);
}
if (method_exists(PoCostingValidationController::class, 'validationHistoryPage')) {
    $router->get('po-costing/validation-history', [PoCostingValidationController::class, 'validationHistoryPage']);
}

if (method_exists(PoCostingValidationController::class, 'searchPoData')) {
    /*
     * GET po-costing/search-po — SAP API call
     * Request URL : http://APP-PROD.evolvclothing.com:8000/sap/opu/odata/sap/Z_QUOTATIONZCOSTCOM_CDS/Z_QuotationZCostCom?$filter=PurchaseOrder%20eq%20%274000008618%27&$expand=to_Fabric,to_Trim,to_Packing,to_Special&$format=json
     * Request Payload : {"PurchaseOrder":"4000008618"}
     * Example : GET /po-costing/search-po?po=4000008618
     */
    $router->get('po-costing/search-po', [PoCostingValidationController::class, 'searchPoData']);

    /*
     * GET po-costing/valuation-report — local DB (no SAP call)
     * Example : GET /po-costing/valuation-report?from=2026-11-01&to=2026-11-24
     */
    $router->get('po-costing/valuation-report', [PoCostingValidationController::class, 'poValuationReportData']);
}
if (method_exists(PoCostingValidationController::class, 'relatedPoData')) {
    /*
     * GET po-costing/related-po — SAP API call
     * Request URL : http://APP-PROD.evolvclothing.com:8000/sap/opu/odata/sap/Z_QUOTATIONZCOSTCOM_CDS/Z_QuotationZCostCom?$filter=ApprovedStatus%20eq%20%27NOTAPPROVED%27&$format=json
     * Request Payload : {"ApprovedStatus":"NOTAPPROVED"}
     * Example : GET /po-costing/related-po
     */
    $router->get('po-costing/related-po', [PoCostingValidationController::class, 'relatedPoData']);
}
if (method_exists(PoCostingValidationController::class, 'relatedPoStatusGet')) {
    $router->get('po-costing/related-po/status', [PoCostingValidationController::class, 'relatedPoStatusGet']);
}
if (method_exists(PoCostingValidationController::class, 'relatedPoStatusSave')) {
    $router->post('po-costing/related-po/status', [PoCostingValidationController::class, 'relatedPoStatusSave']);
}
if (method_exists(PoCostingValidationController::class, 'relatedPoSentLogGet')) {
    $router->get('po-costing/related-po/sent-log', [PoCostingValidationController::class, 'relatedPoSentLogGet']);
}
if (method_exists(PoCostingValidationController::class, 'cronDailyEsignReport')) {
    $router->get('cron/daily-esign-report', [PoCostingValidationController::class, 'cronDailyEsignReport']);
    $router->post('cron/daily-esign-report', [PoCostingValidationController::class, 'cronDailyEsignReport']);
}
?>
