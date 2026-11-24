<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : Shared utilities + module path maps (routes, views, assets, services, models)
 */
declare(strict_types=1);

/**
 * Load every module route file under routes/{module}/*Web.php
 */
function sap_reports_load_module_routes(Router $router): void
{
    $routesRoot = base_path('routes');
    if (!is_dir($routesRoot)) {
        return;
    }

    $modules = [
        'fabricReport/FabricReportWeb.php',
        'trimsReport/TrimsReportWeb.php',
        'utilizationReport/UtilizationReportWeb.php',
        'productionReport/ProductionReportWeb.php',
        'procurementReport/ProcurementReportWeb.php',
        'purchaseOrderReport/PurchaseOrderReportWeb.php',
        'PoCostingValidation/PoCostingValidationWeb.php',
        'fabricSaleReport/FabricSaleReportWeb.php',
        'salesDashboard/SalesDashboardWeb.php',
        'audit/AuditLogWeb.php',
        'sapApiLogs/SapApiLogsWeb.php',
        'userAccessControl/UserAccessControlWeb.php',
        'zcost/ZcostWeb.php',
    ];

    foreach ($modules as $rel) {
        $path = $routesRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
        if (is_file($path)) {
            require $path;
        }
    }
}

function sap_reports_require_module_controller(string $class): void
{
    sap_reports_require_controller($class);
}

/**
 * Legacy public asset path → module path (under public/).
 * Example: js/utilization.js → js/fabricReport/utilization.js
 *
 * @return array<string,string>
 */
function sap_reports_asset_aliases(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [
        // common
        'js/loader.js' => 'js/common/loader.js',
        'js/excel-export.js' => 'js/common/excel-export.js',
        'js/table-columns.js' => 'js/common/table-columns.js',
        'css/app.css' => 'css/common/app.css',
        'css/loader.css' => 'css/common/loader.css',
        'css/zcost/logo.png' => 'css/common/logo.png',

        // fabric / trims (utilization)
        'js/utilization.js' => 'js/fabricReport/utilization.js',
        'css/fabric.css' => 'css/fabricReport/fabric.css',
        'css/trims.css' => 'css/trimsReport/trims.css',

        // production
        'js/production.js' => 'js/productionReport/production.js',
        'css/production.css' => 'css/productionReport/production.css',

        // procurement
        'js/procurement.js' => 'js/procurementReport/procurement.js',

        // purchase order (shared with po costing UI pieces)
        'js/purchase-order.js' => 'js/purchaseOrderReport/purchase-order.js',
        'js/po-report.js' => 'js/purchaseOrderReport/po-report.js',
        'js/po-costing-workbench.js' => 'js/purchaseOrderReport/po-costing-workbench.js',
        'js/po-costing-bulk.js' => 'js/purchaseOrderReport/po-costing-bulk.js',
        'js/po-pass-log.js' => 'js/purchaseOrderReport/po-pass-log.js',

        // po costing validation
        'js/related-po.js' => 'js/PoCostingValidation/related-po.js',
        'js/search-po.js' => 'js/PoCostingValidation/search-po.js',
        'js/po-valuation-report.js' => 'js/PoCostingValidation/po-valuation-report.js',
        'js/po-validation-history.js' => 'js/PoCostingValidation/po-validation-history.js',

        // fabric sale
        'js/fabric-sale.js' => 'js/fabricSaleReport/fabric-sale.js',
        'css/fabricSale.css' => 'css/fabricSaleReport/fabricSale.css',

        // sales
        'js/sales.js' => 'js/salesDashboard/sales.js',
        'css/sales.css' => 'css/salesDashboard/sales.css',
        'css/sales-dashboard.css' => 'css/salesDashboard/sales-dashboard.css',

        // uac
        'js/user-access-control.js' => 'js/userAccessControl/user-access-control.js',
        'css/user_access_control.css' => 'css/userAccessControl/user_access_control.css',

        // zcost (canonical under module folders; public/zcost is legacy mirror)
        'js/zcost/sap_costing.js' => 'js/zcost/sap_costing.js',
        'js/zcost/app.js' => 'js/zcost/app.js',
        'js/zcost/bootstrap.bundle.min.js' => 'js/zcost/bootstrap.bundle.min.js',
        'css/zcost/sap_theme.css' => 'css/zcost/sap_theme.css',
        'css/zcost/sap_initial.css' => 'css/zcost/sap_initial.css',
        'css/zcost/evolv_landing.css' => 'css/zcost/evolv_landing.css',
        'css/zcost/audit_page.css' => 'css/zcost/audit_page.css',
    ];
    return $map;
}

/**
 * Resolve a public asset relative path (supports module aliases + legacy flat paths).
 */
function sap_reports_resolve_public_path(string $rel): string
{
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    $aliases = sap_reports_asset_aliases();
    $candidates = [];
    if (isset($aliases[$rel])) {
        $candidates[] = $aliases[$rel];
    }
    $candidates[] = $rel;

    foreach ($candidates as $candidate) {
        $abs = base_path('public/' . $candidate);
        if (is_file($abs)) {
            return $candidate;
        }
    }
    return $rel;
}

/**
 * Legacy view name → module view path (under app/views/).
 *
 * @return array<string,string>
 */
function sap_reports_view_aliases(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [
        'reports/index' => 'hub/index',
        'reports/material' => 'fabricReport/material',
        'reports/production' => 'productionReport/production',
        'reports/procurement' => 'procurementReport/procurement',
        'reports/purchase_order' => 'purchaseOrderReport/purchase_order',
        'reports/po_costing' => 'PoCostingValidation/po_costing',
        'reports/po_pass_log' => 'purchaseOrderReport/po_pass_log',
        'reports/po_validation_history' => 'PoCostingValidation/po_validation_history',
        'reports/fabric_sale' => 'fabricSaleReport/fabric_sale',
        'reports/audit_log' => 'audit/audit_log',
        'reports/user_access_control' => 'userAccessControl/user_access_control',
        'reports/Zcost' => 'zcost/Zcost',
        'reports/zcost_audit' => 'zcost/zcost_audit',
        'reports/show' => 'hub/show',
        'sales/index' => 'salesDashboard/index',
        'layouts/main' => 'common/layouts/main',
        'partials/loader' => 'common/partials/loader',
        'partials/footer' => 'common/partials/footer',
        'partials/header_nav_right' => 'common/partials/header_nav_right',
        'partials/table_preferences' => 'common/partials/table_preferences',
        'errors/404' => 'common/errors/404',

        // PO partials
        'reports/partials/po_costing_workbench' => 'purchaseOrderReport/partials/po_costing_workbench',
        'reports/partials/po_costing_bulk' => 'purchaseOrderReport/partials/po_costing_bulk',
        'reports/partials/po_esign_modal' => 'purchaseOrderReport/partials/po_esign_modal',
        'reports/partials/po_validation_offcanvas' => 'purchaseOrderReport/partials/po_validation_offcanvas',
        'reports/partials/po_sent_modal' => 'PoCostingValidation/partials/po_sent_modal',
        'reports/partials/po_related' => 'PoCostingValidation/partials/po_related',
        'reports/partials/po_search' => 'PoCostingValidation/partials/po_search',
        'reports/partials/po_valuation_report' => 'PoCostingValidation/partials/po_valuation_report',
    ];
    return $map;
}

/**
 * Resolve view file absolute path.
 */
function sap_reports_resolve_view_file(string $view): string
{
    $view = str_replace('.', '/', $view);
    $view = trim(str_replace('\\', '/', $view), '/');
    $aliases = sap_reports_view_aliases();
    $candidates = [];
    if (isset($aliases[$view])) {
        $candidates[] = $aliases[$view];
    }
    $candidates[] = $view;

    foreach ($candidates as $candidate) {
        $abs = base_path('app/views/' . $candidate . '.php');
        if (is_file($abs)) {
            return $abs;
        }
    }
    return base_path('app/views/' . $view . '.php');
}

/**
 * Service class file → module folder under app/services/.
 *
 * @return array<string,string> classBaseName => moduleFolder
 */
function sap_reports_service_modules(): array
{
    return [
        'SapUtilizationService' => 'fabricReport',
        'SapProductionService' => 'productionReport',
        'SapProcurementService' => 'procurementReport',
        'SapPurchaseOrderService' => 'purchaseOrderReport',
        'SapCostService' => 'purchaseOrderReport',
        'PoValidationStatusService' => 'purchaseOrderReport',
        'PoPassApprovalService' => 'purchaseOrderReport',
        'PassPoValidationLogService' => 'purchaseOrderReport',
        'UserEsignService' => 'purchaseOrderReport',
        'PoCostValidationService' => 'PoCostingValidation',
        'PoValuationReportService' => 'PoCostingValidation',
        'PoValidationLogService' => 'PoCostingValidation',
        'PoReleaseLogService' => 'PoCostingValidation',
        'PoSentLogService' => 'PoCostingValidation',
        'PoWorkflowAssignmentService' => 'PoCostingValidation',
        'PoManualCostingMapService' => 'PoCostingValidation',
        'PoDailyEsignReportService' => 'PoCostingValidation',
        'SapSalesService' => 'salesDashboard',
        'ApiAuditLogService' => 'audit',
        'SapValueFormat' => 'common',
        'TableColumnRegistry' => 'common',
        'TablePreferenceService' => 'common',
        'TablePreferenceException' => 'common',
        'SimpleMailService' => 'common',
        'PoTempfinCostingService' => 'PoCostingValidation',
        'ZcostService' => 'zcost',
    ];
}

/**
 * Resolve service PHP file path (module folder first, then flat legacy).
 */
function sap_reports_service_path(string $classOrFile): string
{
    $name = basename(str_replace('\\', '/', $classOrFile));
    $name = preg_replace('/\.php$/i', '', $name) ?: $name;
    $modules = sap_reports_service_modules();
    if (isset($modules[$name])) {
        $mod = base_path('app/services/' . $modules[$name] . '/' . $name . '.php');
        if (is_file($mod)) {
            return $mod;
        }
    }
    return base_path('app/services/' . $name . '.php');
}

function sap_reports_require_service(string $classOrFile): void
{
    $path = sap_reports_service_path($classOrFile);
    if (!is_file($path)) {
        throw new RuntimeException('Service not found: ' . $path);
    }
    require_once $path;
}

/**
 * Model class → module folder under app/models/.
 *
 * @return array<string,string>
 */
function sap_reports_model_modules(): array
{
    return [
        'ReportsModel' => 'hub',
        'SalesModel' => 'salesDashboard',
        'DashboardModel' => 'salesDashboard',
        'UserTablePreferenceModel' => 'common',
    ];
}

function sap_reports_model_path(string $classOrFile): string
{
    $name = basename(str_replace('\\', '/', $classOrFile));
    $name = preg_replace('/\.php$/i', '', $name) ?: $name;
    $modules = sap_reports_model_modules();
    if (isset($modules[$name])) {
        $mod = base_path('app/models/' . $modules[$name] . '/' . $name . '.php');
        if (is_file($mod)) {
            return $mod;
        }
    }
    return base_path('app/models/' . $name . '.php');
}

function sap_reports_require_model(string $classOrFile): void
{
    $path = sap_reports_model_path($classOrFile);
    if (!is_file($path)) {
        throw new RuntimeException('Model not found: ' . $path);
    }
    require_once $path;
}
