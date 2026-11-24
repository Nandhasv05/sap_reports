<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Trims Utilization routes
 */
/** @var Router $router */
sap_reports_require_module_controller('TrimsReportController');

$router->get('trims', [TrimsReportController::class, 'page']);

/*
 * GET trims/data — SAP API call
 * Request URL : http://APP-PROD.evolvclothing.com:8000/sap/opu/odata/sap/ZBUSINESS_API_SRV/TRIMS_UTILIZATIONSet?$filter=SalesOrder%20eq%20%274203%27&$format=json
 * Request Payload : {"SalesOrder":"4203"}
 * Example : GET /trims/data?so=4203
 */
$router->get('trims/data', [TrimsReportController::class, 'reportData']);
?>
