<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Fabric Sale Order routes
 */
/** @var Router $router */
sap_reports_require_module_controller('FabricSaleReportController');

/*
 * GET fabric-sale — page only (SAP data via sales routes)
 * Request URL : http://APP-PROD.evolvclothing.com:8000/sap/opu/odata/sap/ZI_SALESAPI_HUB_CDS/ZI_SalesApi_HUB?$filter=CreationDate%20ge%20datetime%272026-11-23T00%3A00%3A00%27%20and%20CreationDate%20lt%20datetime%272026-11-24T00%3A00%3A00%27&$format=json
 * Request Payload : {"from":"2026-11-23","to":"2026-11-23"}
 * Example : GET /sales/orders-by-date?from=2026-11-23&to=2026-11-23
 *
 * Per SO lines:
 * Request URL : …/ZI_SalesApi_HUB?$filter=SalesOrder%20eq%20%274203%27&$format=json
 * Request Payload : {"SalesOrder":"4203"}
 * Example : GET /sales/sales-api?sales_order=4203
 */
$router->get('fabric-sale', [FabricSaleReportController::class, 'page']);
?>
