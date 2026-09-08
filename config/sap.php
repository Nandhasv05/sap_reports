<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : SAP configuration
 */
/*
 * SAP OData — ZI_SaleOrderItems CDS view
 */
/*
 * Return the SAP configuration
 */
return [
    'enabled'          => true,
    'base_url'         => 'http://APP-PROD.evolvclothing.com:8000',
    // Dest/Linux has no DNS for this host; local Windows uses C:\Windows\System32\drivers\etc\hosts
    'resolve'          => [
        'APP-PROD.evolvclothing.com:8000:10.103.10.18',
        'app-prod.evolvclothing.com:8000:10.103.10.18',
    ],
    'service'          => '/sap/opu/odata/sap/ZI_SALEORDERITEMS_CDS/ZI_SaleOrderItems',
    'fabric_service'   => '/sap/opu/odata/sap/ZBUSINESS_API_SRV/FABRIC_UTILIZATIONSet',
    'trims_service'    => '/sap/opu/odata/sap/ZBUSINESS_API_SRV/TRIMS_UTILIZATIONSet',
    'username'         => 'APIUSER',
    'password'         => 'Api@321',
    'page_size'        => 500,
    'max_rows'         => 10000,
    'timeout'          => 60,
    'cache_ttl'        => 300,
    'cache_version'    => 5,
    'currency'         => 'INR',
    'currency_symbol'  => '₹',
    'division_labels'  => [
        '10' => 'Apparel',
        '20' => 'Accessories',
    ],
    'status_labels'    => [
        'A' => 'Active',
        'B' => 'In Progress',
        'C' => 'Completed',
    ],
];
