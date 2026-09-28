<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * Example Local Environment Secrets
 *
 * Copy this file to:
 *
 *     config/secrets.php
 *
 * Then replace the placeholder values below with the credentials for
 * your local or production environment.
 *
 * IMPORTANT:
 * config/secrets.php is excluded from source control.
 * Never commit real database, SMTP, API, or other private credentials.
 */

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_username');
define('DB_PASSWORD', 'your_database_password');
define('DB_CHARSET', 'utf8mb4');

/*
|--------------------------------------------------------------------------
| SMTP
|--------------------------------------------------------------------------
*/

define('MAIL_HOST', 'your_smtp_host');
define('MAIL_PORT', 465);
define('MAIL_ENCRYPTION', 'ssl');

define('MAIL_USERNAME', 'your_smtp_username');
define('MAIL_PASSWORD', 'your_smtp_password');

/*
|--------------------------------------------------------------------------
| Sender Identity
|--------------------------------------------------------------------------
*/

define('MAIL_FROM_ADDRESS', 'academy@example.com');
define('MAIL_FROM_NAME', 'Blackthorne Academy');

/*
|--------------------------------------------------------------------------
| Contact Form
|--------------------------------------------------------------------------
*/

define('CONTACT_RECIPIENT', 'admin@example.com');