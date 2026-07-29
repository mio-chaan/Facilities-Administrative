<?php
/**
 * config.php
 * App-wide configuration and constants.
 *
 *
 * Include this ONCE, early, before anything else (db_connect.php requires it).
 */

declare(strict_types=1);

// ---- App-wide settings — edit these for your environment ---------------

define('APP_NAME', 'RAM YUM - Facilities & Administrative Management');
define('APP_ENV', 'local');              // 'local' | 'production'
define('APP_DEBUG', true);               // true = show real PHP errors (local only)
define('APP_URL', 'http://localhost:8000'); 
define('APP_TIMEZONE', 'Asia/Manila');
define('DB_TEAM8_PREFIX', 'team8_');
define('OPENAI_API_KEY', $_ENV['OPENAI_API_KEY'] ?? '');
define('UPLOAD_MAX_SIZE_MB', 10);
define('UPLOAD_DIR', dirname(__DIR__, 2) . '/public/uploads');

// TEMPORARY AUTH dev bypass (see login.php / logout.php / docs/Auth.md).
// When true, skips the login form entirely and auto-logs in as the
// seeded "Dev Tester" (user_id 1). Leave false unless doing quick
// throwaway local testing — auth_check.php also requires
// APP_ENV === 'local' regardless of this flag, so it can never
// accidentally apply outside local dev.
define('AUTH_DEV_BYPASS', true);

// ---- End editable section ------------------------------------------------

date_default_timezone_set(APP_TIMEZONE);

// Basic error visibility toggle for local dev vs prod
if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}
