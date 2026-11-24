<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Shared EVOL portal session for SAP Reports (same PHPSESSID as /EVOL/login.php)
 */
declare(strict_types=1);

if (!function_exists('portal_session_start')) {
    function portal_session_start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        session_name('PHPSESSID');
        session_start();
    }
}

if (!function_exists('portal_evol_login_url')) {
    function portal_evol_login_url(): string
    {
        if (function_exists('sap_reports_evol_url')) {
            return sap_reports_evol_url('login.php');
        }

        return '/EVOL/login.php';
    }
}

if (!function_exists('portal_require_login')) {
    function portal_require_login(): void
    {
        portal_session_start();
        $uid = $_SESSION['user_id'] ?? null;
        $user = trim((string) ($_SESSION['username'] ?? ''));
        if (($uid === null || $uid === '' || $uid === 0) && $user === '') {
            header('Location: ' . portal_evol_login_url());
            exit;
        }
    }
}

if (!function_exists('portal_logout')) {
    function portal_logout(): void
    {
        portal_session_start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', (bool) ($params['secure'] ?? false), (bool) ($params['httponly'] ?? true));
        }
        session_destroy();
    }
}
