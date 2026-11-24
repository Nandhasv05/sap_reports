<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Zcost costing sheet routes
 */
/** @var Router $router */
sap_reports_require_module_controller('ZcostController');

$router->get('zcost', [ZcostController::class, 'page']);
$router->get('zcost/api', [ZcostController::class, 'api']);
$router->post('zcost/api', [ZcostController::class, 'api']);
$router->get('zcost/api.php', [ZcostController::class, 'api']);
$router->post('zcost/api.php', [ZcostController::class, 'api']);
$router->get('zcost/audit', [ZcostController::class, 'audit']);
$router->get('zcost/audit.php', [ZcostController::class, 'audit']);
?>
