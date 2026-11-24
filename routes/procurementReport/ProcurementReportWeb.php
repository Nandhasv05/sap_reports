<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Procurement report routes
 */
/** @var Router $router */
sap_reports_require_module_controller('ProcurementReportController');

$router->get('procurement', [ProcurementReportController::class, 'page']);

/*
 * GET procurement/data — SAP API call
 * Request URL : http://APP-PROD.evolvclothing.com:8000/sap/opu/odata/sap/ZBUSINESS_API_SRV/ProcurementDashboardSet?$filter=SalesDoc%20eq%20%274203%27&$expand=ProcurementPRSet&$format=json
 * Request Payload : {"SalesDoc":"4203"}
 * Example : GET /procurement/data?so=4203
 */
$router->get('procurement/data', [ProcurementReportController::class, 'reportData']);

/*
 * GET procurement/pr-details — local mock (no SAP call)
 * Example : GET /procurement/pr-details?pr=10001234&material=3H001262&so=4203
 */
$router->get('procurement/pr-details', [ProcurementReportController::class, 'prDetails']);
?>
