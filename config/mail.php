<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 05/11/2026
 * DESCRIPTION : Outbound mail for PO comment @mention alerts
 *
 * REQUIRED: set smtp.password below (or env EVOLV_SMTP_PASS).
 * Outlook / Microsoft 365 for @evolvclothing.com typically uses smtp.office365.com.
 */
return [
    'enabled' => true,
    'driver'  => 'smtp', // smtp | mail
    // Outbound mailbox (Office 365) — From: apps <apps@evolvclothing.com>
    'from' => [
        'address' => 'apps@evolvclothing.com',
        'name'    =>  'EVOLV Applications',
    ],
    'smtp' => [
        'host'       => getenv('EVOLV_SMTP_HOST') ?: 'smtp.office365.com',
        'port'       => (int) (getenv('EVOLV_SMTP_PORT') ?: 587),
        'encryption' => getenv('EVOLV_SMTP_ENCRYPTION') ?: 'tls', // tls | ssl | ''
        'username'   => 'apps@evolvclothing.com',
        'password'   => '#EvloveND5791@',
        'timeout'    => 20,
    ],
    // If a tagged user has no Email in users table, append this domain
    'fallback_domain' => 'evolvclothing.com',
];
