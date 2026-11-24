<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : User Access Control routes
 */
/** @var Router $router */
sap_reports_require_module_controller('UserAccessControlController');

$router->get('user-access-control', [UserAccessControlController::class, 'index']);
$router->get('user-access', [UserAccessControlController::class, 'index']);
$router->get('useraccesscontrol', [UserAccessControlController::class, 'index']);
$router->get('api/user-access-control/get', [UserAccessControlController::class, 'getUserPermissions']);
$router->post('api/user-access-control/save', [UserAccessControlController::class, 'saveUserPermissions']);
?>
