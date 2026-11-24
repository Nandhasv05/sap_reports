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
    $router->get('po-costing/search-po', [PoCostingValidationController::class, 'searchPoData']);
    $router->get('po-costing/valuation-report', [PoCostingValidationController::class, 'poValuationReportData']);
}
if (method_exists(PoCostingValidationController::class, 'relatedPoData')) {
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
