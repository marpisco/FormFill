<?php
/**
 * FormFill Configuration Template
 * 
 * Copy this file to src/config.php and fill in your values.
 * src/config.php is gitignored and will never be committed.
 * 
 * IMPORTANT: At least one authentication provider must be enabled:
 *   - SMTP (smtp_config['enabled'] => true) for email OTP login, OR
 *   - OAuth2 (oauth2_config['enabled'] => true) for Microsoft sign-in, OR
 *   - Both. With neither enabled, no user can authenticate.
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
// Set 'enabled' => false to completely disable email sending (development/testing)
$smtp_config = [
    'enabled'      => false,                  // false = skip all email sending silently
    'host'         => 'smtp.example.com',
    'port'         => 587,
    'auth'         => true,
    'security'     => 'tls',                  // 'tls' (STARTTLS) or 'ssl' (SMTPS)
    'username'     => 'SMTP_LOGIN_USERNAME',  // SMTP authentication login
    'password'     => 'CHANGE_ME',
    'from_address' => 'noreply@example.com',  // From: header address (can differ from username)
    'from_name'    => 'FormFill',             // From: header display name (overridable via DB config 'email_account_name')
];

// Microsoft Azure OAuth2 (for "Sign in with Microsoft")
// Set 'enabled' => false to disable OAuth2 — only email OTP will be available.
// Register an app at https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps
$oauth2_config = [
    'enabled'      => false,                                   // false = hide "Sign in with Microsoft" button
    'clientId'     => '00000000-0000-0000-0000-000000000000',
    'clientSecret' => 'CHANGE_ME',
    'tenant'       => 'common',          // 'common' for multi-tenant, or your tenant ID
    'redirectUri'  => 'https://example.com/login/',
];
