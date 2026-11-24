<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Sales Dashboard routes
 */
/** @var Router $router */
sap_reports_require_module_controller('SalesController');

$router->get('sales', [SalesController::class, 'index']);

/*
 * GET sales/data — SAP API call
 * Request URL : http://APP-PROD.evolvclothing.com:8000/sap/opu/odata/sap/ZI_QUOTATIONSALESORDER_HUB_CDS/ZI_QuotationSalesOrder_HUB?$filter=QuotationDate%20ge%20datetime%272026-11-18T00%3A00%3A00%27%20and%20QuotationDate%20le%20datetime%272026-11-24T23%3A59%3A59%27&$format=json
 * Request Payload : {"from_date":"2026-11-18","to_date":"2026-11-24"}
 * Example : GET /sales/data?from=2026-11-18&to=2026-11-24
 */
$router->get('sales/data', [SalesController::class, 'data']);
$router->get('api/sales', [SalesController::class, 'data']);

/*
 * GET sales/procurement — SAP API call
 * Request URL : http://APP-PROD.evolvclothing.com:8000/sap/opu/odata/sap/ZBUSINESS_API_SRV/ProcurementDashboardSet?$filter=SalesDoc%20eq%20%274203%27&$format=json
 * Request Payload : {"SalesDoc":"4203"}
 * Example : GET /sales/procurement?sales_doc=4203
 */
$router->get('sales/procurement', [SalesController::class, 'procurementData']);

/*
 * GET sales/procurement-pr — SAP API call
 * Request URL : http://APP-PROD.evolvclothing.com:8000/sap/opu/odata/sap/ZBUSINESS_API_SRV/ProcurementDashboardSet(SalesDoc='0000004203',ComponentMaterial='3H001262')/ProcurementPRSet?$format=json
 * Request Payload : {"SalesDoc":"4203","ComponentMaterial":"3H001262"}
 * Example : GET /sales/procurement-pr?sales_doc=4203&component_material=3H001262
 */
$router->get('sales/procurement-pr', [SalesController::class, 'procurementPrData']);

/*
 * GET sales/bom — SAP API call
 * Request URL : http://APP-PROD.evolvclothing.com:8000/sap/opu/odata/sap/ZC_COOISCOMP_HUB_CDS/ZC_COOISComp_Hub?$filter=SalesOrder%20eq%20%274203%27&$format=json
 * Request Payload : {"SalesOrder":"4203"}
 * Example : GET /sales/bom?sales_order=4203
 */
$router->get('sales/bom', [SalesController::class, 'bomData']);

/*
 * GET sales/material-api — SAP API call
 * Request URL : http://APP-PROD.evolvclothing.com:8000/sap/opu/odata/sap/ZI_MATERIALAPI_HUB_CDS/ZI_MaterialAPI_HUB?$filter=Product%20eq%20%273H001262%27&$format=json
 * Request Payload : {"Product":"3H001262"}
 * Example : GET /sales/material-api?material=3H001262
 */
$router->get('sales/material-api', [SalesController::class, 'materialApiData']);

/*
 * GET sales/sales-api — SAP API call
 * Request URL : http://APP-PROD.evolvclothing.com:8000/sap/opu/odata/sap/ZI_SALESAPI_HUB_CDS/ZI_SalesApi_HUB?$filter=SalesOrder%20eq%20%274203%27&$format=json
 * Request Payload : {"SalesOrder":"4203"}
 * Example : GET /sales/sales-api?so=4203
 */
$router->get('sales/sales-api', [SalesController::class, 'salesApiHubData']);

/*
 * GET sales/orders-by-date — SAP API call
 * Request URL : http://APP-PROD.evolvclothing.com:8000/sap/opu/odata/sap/ZI_SALESAPI_HUB_CDS/ZI_SalesApi_HUB?$filter=CreationDate%20ge%20datetime%272026-09-25T00%3A00%3A00%27%20and%20CreationDate%20lt%20datetime%272026-09-26T00%3A00%3A00%27&$format=json
 * Request Payload : {"from":"2026-09-25","to":"2026-09-25"}
 * Example : GET /sales/orders-by-date?from=2026-09-25&to=2026-09-25
 */
$router->get('sales/orders-by-date', [SalesController::class, 'ordersByDate']);

$router->get('sale', function () {
    header('Location: ' . url('sales'));
    exit;
});
$router->get('open', function () {
    sap_reports_require_module_controller('ReportsController');
    (new ReportsController())->show('open');
});
$router->get('division', function () {
    sap_reports_require_module_controller('ReportsController');
    (new ReportsController())->show('division');
});
?>
