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
    // Same working Office365 mailbox as EVOL portal (password_reset / fabric statement / PO e-sign)
    'from' => [
        'address' => getenv('EVOLV_SMTP_USER') ?: 'support@evolvclothing.com',
        'name'    => 'Evolv Apps',
    ],
    'smtp' => [
        'host'       => getenv('EVOLV_SMTP_HOST') ?: 'smtp.office365.com',
        'port'       => (int) (getenv('EVOLV_SMTP_PORT') ?: 587),
        'encryption' => getenv('EVOLV_SMTP_ENCRYPTION') ?: 'tls', // tls | ssl | ''
        'username'   => getenv('EVOLV_SMTP_USER') ?: 'support@evolvclothing.com',
        // Microsoft 365 app password (shared with EVOL mail senders)
        'password'   => getenv('EVOLV_SMTP_PASS') ?: 'qjjyjbddpgcbjkmn',
        'timeout'    => 20,
    ],
    // If a tagged user has no Email in users table, append this domain
    'fallback_domain' => 'evolvclothing.com',
];
