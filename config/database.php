<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 30/09/2026
 * DESCRIPTION : Portal database (CLIENT_API_LIVE) configuration
 *
 * Auto-selects LIVE vs DEV from host / EVOLV_ENV.
 * Override any field with SAP_REPORTS_DB_* environment variables.
 */
$env = static function (string $name, string $default): string {
    $value = getenv($name);
    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
};

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? getenv('EVOLV_HTTP_HOST') ?: ''));
$isLive = getenv('EVOLV_ENV') === 'production'
    || str_contains($host, 'apps.evolvclothing.com');

/*
 * PRODUCTION / LIVE DATABASE  (apps.evolvclothing.com)
 * Server: 192.168.9.19
 * Port: 1433
 * Database: CLIENT_API_LIVE
 * User: client_api_user
 * Password: dev@123
 */
$live = [
    'server'   => $env('SAP_REPORTS_DB_SERVER', '192.168.9.19'),
    'port'     => $env('SAP_REPORTS_DB_PORT', '1433'),
    'database' => $env('SAP_REPORTS_DB_DATABASE', 'CLIENT_API_LIVE'),
    'user'     => $env('SAP_REPORTS_DB_USER', 'client_api_user'),
    'password' => $env('SAP_REPORTS_DB_PASSWORD', 'dev@123'),
    'timeout'  => 8,
];

/*
 * DEVELOPMENT DATABASE  (localhost / 10.103.10.33)
 * Server: 10.103.10.33,1433
 * Database: CLIENT_API_LIVE
 * User: sa
 * Password: Developer@123
 */
$dev = [
    'server'   => $env('SAP_REPORTS_DB_SERVER', '10.103.10.33,1433'),
    'database' => $env('SAP_REPORTS_DB_DATABASE', 'CLIENT_API_LIVE'),
    'user'     => $env('SAP_REPORTS_DB_USER', 'sa'),
    'password' => $env('SAP_REPORTS_DB_PASSWORD', 'Developer@123'),
    'timeout'  => 8,
];

return $isLive ? $live : $dev;
