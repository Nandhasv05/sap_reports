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

    $username = strtolower(trim((string) ($user['username'] ?? '')));
    if (function_exists('portal_normalize_username')) {
        $username = portal_normalize_username((string) ($user['username'] ?? ''));
    }

    // Super Admins: strictly master administrative users
    $superAdmins = ['admin', 'saravan', 'saravanan', 'nandhakumar'];
    if (in_array($username, $superAdmins, true)) {
        return true;
    }

    $mod = strtolower(trim($module));
    $mod = str_replace('_', '-', $mod);
    if ($mod === 'po') {
        $mod = 'purchase-order';
    }

    if ($mod === 'po-costing') {
        return sap_reports_user_can($mod, 'view', $user)
            || sap_reports_user_can($mod, 'find_po', $user)
            || sap_reports_user_can($mod, 'workbench', $user)
            || sap_reports_user_can($mod, 'approve', $user)
            || sap_reports_user_can($mod, 'assign', $user)
            || sap_reports_user_can($mod, 'validate', $user)
            || sap_reports_user_can($mod, 'esign', $user)
            || sap_reports_user_can($mod, 'receive', $user)
            || sap_reports_user_can($mod, 'sent', $user)
            || sap_reports_user_can($mod, 'workflow', $user)
            || sap_reports_user_can($mod, 'approved', $user)
            || sap_reports_user_can($mod, 'related', $user);
    }
    if ($mod === 'zcost') {
        return sap_reports_user_can($mod, 'view', $user)
            || sap_reports_user_can($mod, 'add', $user)
            || sap_reports_user_can($mod, 'edit', $user)
            || sap_reports_user_can($mod, 'copy', $user)
            || sap_reports_user_can($mod, 'approve', $user);
    }

    return sap_reports_user_can($module, 'view', $user);
}

/**
 * Open zCOST sheet from a quotation link (PO Costing workbench / bulk).
 * Full zCOST module access, or PO Costing access with an explicit quotation + view mode.
 */
function sap_reports_can_open_zcost_sheet(?array $user = null, ?string $vbeln = null, bool $viewMode = false): bool
{
    if (sap_reports_user_can_access('zcost', $user)) {
        return true;
    }
    $quote = trim((string) $vbeln);
    if ($quote === '' || !$viewMode) {
        return false;
    }
    return sap_reports_user_can_access('po-costing', $user);
}

/**
 * Canonical SAP Reports access table.
 */
function sap_reports_access_table(): string
{
    return 'sap_report_userascesscontrol';
}

/**
 * Route aliases stored in sap_report_userascesscontrol.page_route vs live app routes.
 *
 * @return list<string>
 */
function sap_reports_access_route_aliases(string $module): array
{
    $mod = strtolower(trim($module));
    $mod = str_replace('_', '-', $mod);
    if ($mod === 'po') {
        $mod = 'purchase-order';
    }

    $map = [
        'purchase-order'        => ['purchase-order', 'purchaseorder', 'po'],
        'po-costing'            => ['po-costing', 'pocosting', 'purchase-order-costing', 'purchase-order?view=costing'],
        'sales'                 => ['sales', 'sale'],
        'fabric'                => ['fabric', 'fabric-utilization'],
        'fabric-sale'           => ['fabric-sale', 'fabricsale'],
        'production'            => ['production'],
        'procurement'           => ['procurement'],
        'trims'                 => ['trims', 'trims-utilization'],
        'utilization'           => ['utilization', 'overall-utilization'],
        'audit-log'             => ['audit-log', 'auditlog'],
        'user-access-control'   => ['user-access-control', 'user-access', 'useraccesscontrol'],
        'zcost'                 => ['zcost', 'zcost costing sheet', 'zcost-costing-sheet'],
    ];

    $aliases = $map[$mod] ?? [$mod];
    $out = [];
    foreach ($aliases as $alias) {
        $alias = strtolower(trim((string) $alias));
        if ($alias !== '' && !in_array($alias, $out, true)) {
            $out[] = $alias;
        }
        $compact = str_replace(['-', ' '], '', $alias);
        if ($compact !== '' && !in_array($compact, $out, true)) {
            $out[] = $compact;
        }
    }
    return $out;
}

/**
 * Granular right from dbo.sap_report_userascesscontrol.
 */
function sap_reports_user_can(string $module, string $action = 'view', ?array $user = null): bool
{
    $action = strtolower(trim($action));
    $actionMap = [
        'pass_po' => 'approve',
        'esign' => 'copy', // PO Costing eSign stored in can_copy
        'workbench' => 'add', // PO Costing Workbench stored in can_add
        'update' => 'edit',
    ];
    if (isset($actionMap[$action])) {
        $action = $actionMap[$action];
    }
    if (!in_array($action, [
        'add', 'view', 'edit', 'approve', 'copy', 'assign', 'validate',
        'receive', 'sent', 'workflow', 'approved', 'related', 'find_po',
    ], true)) {
        $action = 'view';
    }

    if ($user === null && function_exists('portal_user')) {
        $user = portal_user();
    }
    if (!$user) {
        $user = [
            'username'   => $_SESSION['username'] ?? '',
            'role'       => $_SESSION['role'] ?? '',
            'department' => $_SESSION['department'] ?? '',
            'is_admin'   => $_SESSION['is_admin'] ?? 0,
            'id'         => $_SESSION['user_id'] ?? 0,
        ];
    }

    $username = strtolower(trim((string) ($user['username'] ?? '')));
    if (function_exists('portal_normalize_username')) {
        $username = portal_normalize_username((string) ($user['username'] ?? ''));
    }

    $superAdmins = ['admin', 'saravan', 'saravanan', 'nandhakumar'];
    if (in_array($username, $superAdmins, true)) {
        return true;
    }

    $mod = strtolower(trim($module));
    if ($mod === 'po') {
        $mod = 'purchase-order';
    }

    // Audit Log + User Access Control: only admin / nandhakumar / saravanan (and saravan alias)
    if (in_array($mod, [
        'user-access-control', 'useraccesscontrol', 'access-control', 'user-access',
        'audit-log', 'auditlog',
    ], true)) {
        return in_array($username, ['admin', 'nandhakumar', 'saravanan', 'saravan'], true);
    }

    $col = 'can_' . $action;
    $aliases = sap_reports_access_route_aliases($mod);

    try {
        $userId = (int) ($user['id'] ?? ($user['Id'] ?? ($user['user_id'] ?? ($_SESSION['user_id'] ?? 0))));
        require_once base_path('app/core/Database.php');
        $pdo = Database::connection();
        $table = sap_reports_access_table();
        if (in_array($action, ['copy', 'assign', 'validate', 'receive', 'sent', 'workflow', 'approved', 'related', 'find_po'], true)) {
            $len = $pdo->query("SELECT COL_LENGTH('dbo.{$table}', '{$col}')")->fetchColumn();
            if ((int) $len <= 0) {
                // Fallback: older table without new columns
                if (in_array($action, ['copy', 'receive', 'sent', 'workflow', 'approved', 'related', 'find_po'], true)) {
                    // Find PO Costing used to be covered by View before can_find_po existed
                    if ($action === 'find_po') {
                        return sap_reports_user_can($module, 'view', $user);
                    }
                    return false;
                }
                // assign/validate fall back to can_approve if column missing
                $col = 'can_approve';
            }
        }

        $routeHolders = [];
        $params = [$userId, $username];
        foreach ($aliases as $i => $alias) {
            $routeHolders[] = 'LOWER(LTRIM(RTRIM(ISNULL(page_route, \'\')))) = ?';
            $params[] = $alias;
        }

        try {
            $sql = "
                SELECT TOP 1 CAST({$col} AS INT) AS allowed
                FROM [dbo].[{$table}]
                WHERE (user_id = ? OR (username IS NOT NULL AND LOWER(LTRIM(RTRIM(username))) = ?))
                  AND (" . implode(' OR ', $routeHolders) . ")
                ORDER BY CAST({$col} AS INT) DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $val = $stmt->fetchColumn();
            if ($val !== false && $val !== null) {
                return (int) $val === 1;
            }
        } catch (Throwable $inner) {
            error_log('sap_reports_user_can query: ' . $inner->getMessage());
        }
    } catch (Throwable $e) {
        error_log('sap_reports_user_can error: ' . $e->getMessage());
    }

    return false;
}
?>