<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Production report routes
 */
/** @var Router $router */
sap_reports_require_module_controller('ProductionReportController');

$router->get('production', [ProductionReportController::class, 'page']);

/*
 * GET production/data — SAP API call
 * Request URL : http://APP-PROD.evolvclothing.com:8000/sap/opu/odata/sap/ZBUSINESS_API_SRV/ZPROD_NEWSet?$filter=SalesOrder%20eq%20%274203%27%20and%20Plant%20eq%20%27P002%27&$format=json
 * Request Payload : {"SalesOrder":"4203","Plant":"P002"}
 * Example : GET /production/data?so=4203&plant=P002
 *
 * Date-range mode:
 * Request URL : …/ZPROD_NEWSet?$filter=Plant%20eq%20%27P002%27%20and%20FromDate%20eq%20%2720261101%27%20and%20ToDate%20eq%20%2720261124%27&$format=json
 * Request Payload : {"Plant":"P002","FromDate":"20261101","ToDate":"20261124"}
 * Example : GET /production/data?range=this_month&from=2026-11-01&to=2026-11-24&plant=P002
 */
$router->get('production/data', [ProductionReportController::class, 'reportData']);
?>
