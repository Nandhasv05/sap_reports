<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 30/09/2026
 * DESCRIPTION : Portal database (CLIENT_API_LIVE) configuration
 */
/*
 * Override with SAP_REPORTS_DB_SERVER / SAP_REPORTS_DB_PORT / SAP_REPORTS_DB_DATABASE / SAP_REPORTS_DB_USER / SAP_REPORTS_DB_PASSWORD
 */
$env = static function (string $name, string $default): string {
    $value = getenv($name);
    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
};

/*
 * PRODUCTION DATABASE
 * Server: 192.168.9.19
 * Port: 1433
 * Database: CLIENT_API_LIVE
 * User: client_api_user
 * Password: dev@123
 * Timeout: 8
 */
// return [
//     'server'   => $env('SAP_REPORTS_DB_SERVER', '192.168.9.19'),
//     'port'     => $env('SAP_REPORTS_DB_PORT', '1433'),
//     'database' => $env('SAP_REPORTS_DB_DATABASE', 'CLIENT_API_LIVE'),
//     'user'     => $env('SAP_REPORTS_DB_USER', 'client_api_user'),
//     'password' => $env('SAP_REPORTS_DB_PASSWORD', 'dev@123'),
//     'timeout'  => 8,
// ];


/*
 * DEVELOPMENT DATABASE
 * Server: 10.103.10.33,1433
 * Database: CLIENT_API_LIVE
 * User: sa
 * Password: Developer@123
 * Timeout: 8
 */
return [
    'server'   => $env('SAP_REPORTS_DB_SERVER', '10.103.10.33,1433'),
    'database' => $env('SAP_REPORTS_DB_DATABASE', 'CLIENT_API_LIVE'),
    'user'     => $env('SAP_REPORTS_DB_USER', 'sa'),
    'password' => $env('SAP_REPORTS_DB_PASSWORD', 'Developer@123'),
    'timeout'  => 8,
];
