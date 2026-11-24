<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Audit Log routes
 */
/** @var Router $router */
$auditController = sap_reports_controller_path('AuditLogController');
if (is_file($auditController)) {
    require_once $auditController;
    $router->get('audit-log', [AuditLogController::class, 'page']);
    $router->get('api/audit-log', [AuditLogController::class, 'index']);
}
?>
