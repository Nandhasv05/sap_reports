<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : SAP Reports access
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
 * Require the portal access library if it exists
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
        header('Location: ' . sap_reports_evol_url('portal_dashboard.php'));
        exit;
    }
}
