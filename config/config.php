<?php

declare(strict_types=1);

/**
 * Blackthorne Academy
 * Core Application Configuration
 */

/*
|--------------------------------------------------------------------------
| Application Information
|--------------------------------------------------------------------------
*/

define('APP_NAME', 'Blackthorne Academy');
define('APP_URL', 'https://blkthrnacad.com');

/*
|--------------------------------------------------------------------------
| Environment
|--------------------------------------------------------------------------
|
| Use "development" while we are building the site.
| Later, when the academy is ready to launch, we will change this
| to "production".
|
*/

define('APP_ENV', 'development');

/*
|--------------------------------------------------------------------------
| Error Reporting
|--------------------------------------------------------------------------
|
| During development we want PHP errors visible so problems are easier
| to diagnose.
|
| IMPORTANT:
| These will be turned off when the website goes live.
|
*/

if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

/*
|--------------------------------------------------------------------------
| Timezone
|--------------------------------------------------------------------------
*/

date_default_timezone_set('America/New_York');

/*
|--------------------------------------------------------------------------
| File System Paths
|--------------------------------------------------------------------------
|
| BASE_PATH points to the main Blackthorne Academy directory.
|
| These constants will let us reference files consistently without
| having to manually calculate ../ paths throughout the application.
|
*/

define('BASE_PATH', dirname(__DIR__));

define('CONFIG_PATH', BASE_PATH . '/config');
define('INCLUDES_PATH', BASE_PATH . '/includes');
define('VIEWS_PATH', BASE_PATH . '/views');
define('ASSETS_PATH', BASE_PATH . '/assets');
define('UPLOADS_PATH', BASE_PATH . '/uploads');

/*
|--------------------------------------------------------------------------
| Public Asset URLs
|--------------------------------------------------------------------------
*/

define('ASSETS_URL', APP_URL . '/assets');
define('UPLOADS_URL', APP_URL . '/uploads');

/*
|--------------------------------------------------------------------------
| Application URLs
|--------------------------------------------------------------------------
|
| These give us reusable URLs for commonly used pages.
|
*/

define('HOME_URL', APP_URL . '/');
define('LOGIN_URL', APP_URL . '/login.php');
define('REGISTER_URL', APP_URL . '/register.php');
define('LOGOUT_URL', APP_URL . '/logout.php');
define('DASHBOARD_URL', APP_URL . '/dashboard.php');
define('COURSES_URL', APP_URL . '/courses.php');
define('FORUMS_URL', APP_URL . '/forums.php');

/*
|--------------------------------------------------------------------------
| Account Rules
|--------------------------------------------------------------------------
*/

define('MINIMUM_USER_AGE', 13);

/*
|--------------------------------------------------------------------------
| Registration / Verification
|--------------------------------------------------------------------------
*/

define('EMAIL_VERIFICATION_HOURS', 24);

define('TERMS_VERSION', '1.0');
define('PRIVACY_VERSION', '1.0');

/*
|--------------------------------------------------------------------------
| Academic Defaults
|--------------------------------------------------------------------------
|
| The database will remain authoritative for individual school-year
| settings. This is only a system-level fallback/default.
|
*/

define('DEFAULT_PROGRESSION_PERCENTAGE', 85.00);

/*
|--------------------------------------------------------------------------
| Upload Settings
|--------------------------------------------------------------------------
|
| These are application-level limits.
|
| PHP/server limits may also apply and will be checked separately.
|
*/

define('MAX_AVATAR_FILE_SIZE', 5 * 1024 * 1024);          // 5 MB
define('MAX_FORUM_FILE_SIZE', 10 * 1024 * 1024);          // 10 MB
define('MAX_ASSIGNMENT_FILE_SIZE', 25 * 1024 * 1024);     // 25 MB
define('MAX_COURSE_FILE_SIZE', 25 * 1024 * 1024);         // 25 MB

/*
|--------------------------------------------------------------------------
| Security Settings
|--------------------------------------------------------------------------
*/

/*
 * Session name used by Blackthorne Academy.
 *
 * This keeps our authentication session distinct from other PHP
 * applications that may exist on the same hosting account.
 */
define('SESSION_NAME', 'blackthorne_session');

/*
 * Remember-me login duration.
 *
 * 30 days expressed in seconds.
 */
define('REMEMBER_ME_LIFETIME', 60 * 60 * 24 * 30);

/*
|--------------------------------------------------------------------------
| Application Display Settings
|--------------------------------------------------------------------------
*/

define('DEFAULT_DATE_FORMAT', 'F j, Y');
define('DEFAULT_DATETIME_FORMAT', 'F j, Y \a\t g:i A');

/*
|--------------------------------------------------------------------------
| Prevent Direct Configuration Output
|--------------------------------------------------------------------------
|
| config.php intentionally produces no HTML or visible output.
|
*/