<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : App configuration
 */
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? getenv('EVOLV_HTTP_HOST') ?: ''));
$onServer = getenv('EVOLV_ENV') === 'production' || str_contains($host, '10.103.10.33');

/*
 * Return the app configuration
 */
// LOCAL URL : http://localhost:8888/sap_reports/
// DEVELOPMENT URL : http://10.103.10.33/sap_reports/
// PRODUCTION URL : http://apps.evolvclothing.com/sap_reports/
return [
    'name'      => 'SAP Reports',
    'url'       => $onServer ? 'http://10.103.10.33/sap_reports/' : 'http://localhost:8888/sap_reports/ ',
    'env'       => $onServer ? 'production' : 'local' ,
    'base_path' => '',
];
