<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : PO Sale Costing Validation routes
 *               Page: list → detail + PO Items aside
 */
/** @var Router $router */
sap_reports_require_module_controller('PoSaleCostingController');

/*
 * GET posalecosting — search PO number → validation detail + PO Items aside
 * Example : GET /posalecosting
 * Example : GET /posalecosting?po=1000028206
 *
 * SAP validate (on search):
 * Request URL : http://APP-PROD.evolvclothing.com:8000/sap/opu/odata/sap/Z_QUOTATIONZCOSTCOM_CDS/Z_QuotationZCostCom?$filter=PurchaseOrder%20eq%20%271000028206%27&$expand=to_Fabric,to_Trim,to_Packing,to_Special&$format=json
 * Request Payload : {"PurchaseOrder":"1000028206"}
 * Example : GET /purchase-order/validate?po=1000028206
*/
$router->get('posalecosting', [PoSaleCostingController::class, 'page']);
$router->get('po-sale-costing', [PoSaleCostingController::class, 'page']);

/*
 * GET posalecosting/data — optional list API (not used by search UI)
 * Example : GET /posalecosting/data?list=1&mat_cat=all&page=1
 */
$router->get('posalecosting/data', [PoSaleCostingController::class, 'reportData']);
$router->get('po-sale-costing/data', [PoSaleCostingController::class, 'reportData']);
?>
