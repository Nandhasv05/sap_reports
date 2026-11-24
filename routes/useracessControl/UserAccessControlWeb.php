<?php
/*
 * Legacy typo folder — real routes live in routes/userAccessControl/UserAccessControlWeb.php
 * Avoid double-registering: no-op when canonical file exists.
 */
$canonical = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'userAccessControl' . DIRECTORY_SEPARATOR . 'UserAccessControlWeb.php';
if (!is_file($canonical)) {
    sap_reports_require_module_controller('UserAccessControlController');
    /** @var Router $router */
    $router->get('user-access-control', [UserAccessControlController::class, 'index']);
    $router->get('user-access', [UserAccessControlController::class, 'index']);
    $router->get('useraccesscontrol', [UserAccessControlController::class, 'index']);
    $router->get('api/user-access-control/get', [UserAccessControlController::class, 'getUserPermissions']);
    $router->post('api/user-access-control/save', [UserAccessControlController::class, 'saveUserPermissions']);
}
?>
