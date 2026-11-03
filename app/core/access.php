<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : SAP Reports access & RBAC Permission Checking
*/

/*
 * Get the directory of the EVOL portal
*/
function sap_reports_evol_dir(): string
{
    $candidates = [
        dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'EVOL',
        dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'EVOL',
        (string) ($_SERVER['DOCUMENT_ROOT'] ?? '') . DIRECTORY_SEPARATOR . 'EVOL',
        (string) ($_SERVER['DOCUMENT_ROOT'] ?? '') . DIRECTORY_SEPARATOR . 'Evolv-Application' . DIRECTORY_SEPARATOR . 'EVOL',
        '/var/www/html/Evolv-Application/EVOL',
        '/var/www/html/EVOL',
    ];
    foreach ($candidates as $dir) {
        if ($dir !== '' && is_file($dir . DIRECTORY_SEPARATOR . 'portal_access_lib.php')) {
            return $dir;
        }
    }
    return '';
}

/*
 * REQUIRE THE PORTAL ACCESS LIBARY IF IT EXISTS
*/
function sap_reports_require_access(): void
{
    $evolDir = sap_reports_evol_dir();
    if ($evolDir === '') {
        http_response_code(500);
        echo 'EVOL portal files were not found.';
        exit;
    }

    require_once $evolDir . DIRECTORY_SEPARATOR . 'portal_access_lib.php';

    $role = (string) ($_SESSION['role'] ?? '');
    $user = (string) ($_SESSION['username'] ?? '');
    if (!function_exists('portal_user_can_sap_reports') || !portal_user_can_sap_reports($role, $user)) {
        if (sap_reports_wants_json()) {
            sap_reports_json_error(403, 'You do not have access to SAP Reports.', 'no_access');
        }
        header('Location: ' . sap_reports_evol_url('portal_dashboard.php'));
        exit;
    }
}

/**
 * Check if the current user has access to a specific report module
*/
function sap_reports_user_can_access(string $module, ?array $user = null): bool
{
    if ($user === null && function_exists('portal_user')) {
        $user = portal_user();
    }
    if (!$user) {
        $user = [
            'username'   => $_SESSION['username'] ?? '',
            'role'       => $_SESSION['role'] ?? '',
            'department' => $_SESSION['department'] ?? '',
            'is_admin'   => $_SESSION['is_admin'] ?? 0,
        ];
    }

    $role = strtolower(trim((string) ($user['role'] ?? '')));
    $dept = strtolower(trim((string) ($user['department'] ?? '')));
    $isAdmin = !empty($user['is_admin']) || in_array($role, ['admin', 'it_admin', 'super_admin', 'administrator', 'director', 'management'], true);

    if ($isAdmin) {
        return true;
    }

    $username = strtolower(trim((string) ($user['username'] ?? '')));
    if (function_exists('portal_normalize_username')) {
        $username = portal_normalize_username((string) ($user['username'] ?? ''));
    }
    if ($username !== '' && function_exists('portal_sap_reports_usernames') && in_array($username, portal_sap_reports_usernames(), true)) {
        return true;
    }

    $mod = strtolower(trim($module));
    if ($mod === 'po') {
        $mod = 'purchase-order';
    }

    if ($mod === 'user-access-control' || $mod === 'useraccesscontrol' || $mod === 'access-control' || $mod === 'user-access') {
        $uacAdmins = ['admin', 'saravan', 'saravanan', 'nandhakumar'];
        return in_array($username, $uacAdmins, true);
    }

    // Check granular permission in dbo.tblSapUseracessControl if configured
    try {
        $userId = (int) ($user['id'] ?? ($user['Id'] ?? ($_SESSION['user_id'] ?? 0)));
        if ($userId > 0) {
            require_once base_path('app/core/Database.php');
            $pdo = Database::connection();
            $stmt = $pdo->prepare("SELECT can_view FROM [dbo].[tblSapUseracessControl] WHERE user_id = ? AND page_route = ?");
            $stmt->execute([$userId, $mod]);
            $val = $stmt->fetchColumn();
            if ($val !== false && $val !== null) {
                return (int) $val === 1;
            }
        }
    } catch (Throwable $e) {
        // Fallback to department / role checks
    }

    if (function_exists('portal_module_granted')) {
        $portalModuleMap = [
            'fabric'         => ['SAP Fabric Report', 'SAP Reports', 'Fabric Upload'],
            'trims'          => ['SAP Trims Report', 'SAP Reports', 'Trims Upload'],
            'production'     => ['SAP Production Report', 'SAP Reports', 'FG Creation'],
            'procurement'    => ['SAP Procurement Report', 'SAP Reports'],
            'purchase-order' => ['SAP Purchase Order Report', 'SAP Reports', 'PO Validation', 'G-Star Purchase Orders'],
            'po-costing'     => ['PO Costing Validation', 'SAP Reports'],
            'sales'          => ['Kpis', 'KPIS Dashboard', 'SAP Reports'],
            'fabric-sale'    => ['Fabric Sale Order', 'SAP Reports', 'SAP Fabric Report'],
            'utilization'    => ['SAP Utilization Report', 'SAP Reports'],
            'audit-log'      => ['Audit Log', 'SAP Audit Log'],
            'zcost'          => ['ZCOST Costing Sheet', 'zCost Sales Cost Sheet', 'SAP Reports'],
        ];

        if (isset($portalModuleMap[$mod])) {
            foreach ($portalModuleMap[$mod] as $cand) {
                if (portal_module_granted($cand, false)) {
                    return true;
                }
            }
        }
    }

    switch ($mod) {
        case 'fabric':
            return (bool) preg_match('/fabric|cutting|textile|merchandis|sampling|quality|store|plan/i', $role . ' ' . $dept);

        case 'trims':
            return (bool) preg_match('/trim|accessor|merchandis|procure|purchase|sourcing|store|inventory/i', $role . ' ' . $dept);

        case 'production':
            return (bool) preg_match('/product|sew|cut|wash|finish|operat|merchandis|line|plan|manufact/i', $role . ' ' . $dept);

        case 'procurement':
            return (bool) preg_match('/procure|purchase|sourcing|merchandis|store|supply|material/i', $role . ' ' . $dept);

        case 'purchase-order':
        case 'po-costing':
            return (bool) preg_match('/purchase|procure|account|finance|commercial|audit|bill|store|cost/i', $role . ' ' . $dept);

        case 'sales':
        case 'fabric-sale':
            return (bool) preg_match('/sale|market|merchandis|account|finance|commercial/i', $role . ' ' . $dept);

        case 'zcost':
            return (bool) preg_match('/cost|sale|merchandis|account|finance|commercial|admin/i', $role . ' ' . $dept);

        case 'audit-log':
            return $isAdmin || (bool) preg_match('/\b(admin|it|it_admin|audit|director|super_admin)\b/i', $role . ' ' . $dept);

        case 'utilization':
            return sap_reports_user_can_access('fabric', $user) || sap_reports_user_can_access('trims', $user);

        default:
            return true;
    }
}
?>