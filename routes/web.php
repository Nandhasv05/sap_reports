<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Web routes — hub + table prefs + module route loader
 */
/** @var Router $router */

sap_reports_require_controller('HubController');
sap_reports_require_controller('ErrorController');
sap_reports_require_controller('TablePreferencesController');

$router->get('/', [HubController::class, 'page']);
$router->get('404', [ErrorController::class, 'notFound']);

$router->get('api/user/table-preferences/{tableKey}', [TablePreferencesController::class, 'show']);
$router->put('api/user/table-preferences/{tableKey}', [TablePreferencesController::class, 'update']);
$router->delete('api/user/table-preferences/{tableKey}', [TablePreferencesController::class, 'destroy']);

// Page modules: fabric, trims, production, procurement, PO, PO costing, sales, audit, UAC, zcost…
sap_reports_load_module_routes($router);
?>
