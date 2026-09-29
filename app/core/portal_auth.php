<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : SAP Reports portal authentication
 */
$candidates = array(
    dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'portal_auth.php',
    dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'portal_auth.php',
    dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'portal_auth.php',
    (string) ($_SERVER['DOCUMENT_ROOT'] ?? '') . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'portal_auth.php',
    (string) ($_SERVER['DOCUMENT_ROOT'] ?? '') . DIRECTORY_SEPARATOR . 'EVOL' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'portal_auth.php',
    '/var/www/html/includes/portal_auth.php',
    '/var/www/html/EVOL/includes/portal_auth.php',
    '/var/www/html/Evolv-Application/includes/portal_auth.php',
    '/home/evolv/evolvclothing/includes/portal_auth.php',
);

/*
 * Require the first portal authentication file that exists
 */
foreach ($candidates as $candidate) {
    if ($candidate !== '' && is_file($candidate)) {
        require_once $candidate;
        break;
    }
}

/*
 * Fallbacks for servers whose portal_auth.php only defines part of the API
 */
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

if (!function_exists('portal_user')) {
    function portal_user(): ?array
    {
        portal_session_start();
        if (empty($_SESSION['username']) && empty($_SESSION['user_id'])) {
            return null;
        }
        return array(
            'id' => $_SESSION['user_id'] ?? '',
            'username' => $_SESSION['username'] ?? 'User',
            'email' => $_SESSION['email'] ?? '',
            'role' => $_SESSION['role'] ?? '',
            'department' => $_SESSION['department'] ?? '',
            'is_admin' => $_SESSION['is_admin'] ?? 0,
        );
    }
}

if (!function_exists('portal_require_login')) {
    function portal_require_login(): void
    {
        if (!portal_user()) {
            header('Location: ' . (function_exists('sap_reports_evol_url') ? sap_reports_evol_url('login.php') : '/EVOL/login.php'));
            exit;
        }
    }
}

if (!function_exists('portal_logout')) {
    function portal_logout(): void
    {
        portal_session_start();
        $_SESSION = array();
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', (bool) ($params['secure'] ?? false), (bool) ($params['httponly'] ?? true));
        }
        session_destroy();
    }
}
