<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : App configuration
 */
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? getenv('EVOLV_HTTP_HOST') ?: ''));
$isLive = getenv('EVOLV_ENV') === 'production'
    || str_contains($host, 'apps.evolvclothing.com');
$isDevServer = str_contains($host, '10.103.10.33');

/*
 * LOCAL URL : http://localhost:8888/sap_reports/
 * DEVELOPMENT URL : http://10.103.10.33/sap_reports/
 * PRODUCTION URL : https://apps.evolvclothing.com/sap_reports/
 */
if ($isLive) {
    $url = 'https://apps.evolvclothing.com/sap_reports/';
    $envName = 'production';
} elseif ($isDevServer) {
    $url = 'http://10.103.10.33/sap_reports/';
    $envName = 'development';
} else {
    $url = 'http://localhost:8888/sap_reports/';
    $envName = 'local';
}

return [
    'name'      => 'SAP Insight',
    'url'       => $url,
    'env'       => $envName,
    'base_path' => '',
];
