<?php
/**
 * FormFill Configuration Template
 * 
 * Copy this file to src/config.php and fill in your values.
 * src/config.php is gitignored and will never be committed.
 */

// MySQL/MariaDB connection
$db_config = [
    'servidor' => 'localhost',
    'porta'    => 3306,
    'user'     => 'formfill',
    'password' => 'CHANGE_ME',
    'db'       => 'formfill',
];

// SMTP settings for outgoing email
$smtp_config = [
    'host'     => 'smtp.example.com',
    'port'     => 587,
    'auth'     => true,
    'security' => 'tls',          // 'tls' or 'ssl'
    'username' => 'noreply@example.com',
    'password' => 'CHANGE_ME',
];

// Microsoft Azure OAuth2 (for "Sign in with Microsoft")
// Register an app at https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps
$oauth2_config = [
    'clientId'     => '00000000-0000-0000-0000-000000000000',
    'clientSecret' => 'CHANGE_ME',
    'tenant'       => 'common',          // 'common' for multi-tenant, or your tenant ID
    'redirectUri'  => 'https://example.com/login/',
];
