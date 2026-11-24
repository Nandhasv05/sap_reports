<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Zcost costing sheet routes
 */
/** @var Router $router */
sap_reports_require_module_controller('ZcostController');

$router->get('zcost', [ZcostController::class, 'page']);

/*
 * GET/POST zcost/api — SAP API call
 * Request URL : http://APP-PROD.evolvclothing.com:8000/sap/opu/odata/sap/ZCOST_SRV/ZCOSTHSet(Vbeln='3000008',Posnr='000010')?$expand=to_Fabric,to_Trim,to_Special,to_Packing&$format=json
 * Request Payload : {"Vbeln":"3000008","Posnr":"000010"}
 * Example : GET /zcost/api?vbeln=3000008&posnr=000010
 *
 * Save (POST):
 * Request URL : http://APP-PROD.evolvclothing.com:8000/sap/opu/odata/sap/ZCOST_SRV/ZCOSTHSet
 * Request Payload : {"action":"save","data":{…}}
 * Example : POST /zcost/api
 */
$router->get('zcost/api', [ZcostController::class, 'api']);
$router->post('zcost/api', [ZcostController::class, 'api']);
$router->get('zcost/api.php', [ZcostController::class, 'api']);
$router->post('zcost/api.php', [ZcostController::class, 'api']);

$router->get('zcost/audit', [ZcostController::class, 'audit']);
$router->get('zcost/audit.php', [ZcostController::class, 'audit']);
?>
