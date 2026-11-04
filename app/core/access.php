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
    if ($mod === 'po') {
        $mod = 'purchase-order';
    }

    // User Access Control is strictly for super admins
    if ($mod === 'user-access-control' || $mod === 'useraccesscontrol' || $mod === 'access-control' || $mod === 'user-access') {
        return false;
    }

    // Check granular permission in dbo.tblSapUseracessControl
    try {
        $userId = (int) ($user['id'] ?? ($user['Id'] ?? ($_SESSION['user_id'] ?? 0)));
        require_once base_path('app/core/Database.php');
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            SELECT TOP 1 can_view 
            FROM [dbo].[tblSapUseracessControl] 
            WHERE (user_id = ? OR (username IS NOT NULL AND LOWER(username) = ?))
              AND (LOWER(page_route) = ? OR LOWER(REPLACE(page_route, '-', '')) = ? OR LOWER(page_route) LIKE ?)
            ORDER BY can_view DESC
        ");
        $stmt->execute([
            $userId,
            $username,
            $mod,
            str_replace('-', '', $mod),
            '%' . $mod . '%'
        ]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null) {
            return (int) $val === 1;
        }
    } catch (Throwable $e) {
        error_log('sap_reports_user_can_access error: ' . $e->getMessage());
    }

    // Default to false for any user without an explicit can_view=1 grant in tblSapUseracessControl
    return false;
}
?>