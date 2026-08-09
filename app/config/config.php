<?php
/**
 * config.php
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


define('AUTH_DEV_BYPASS', false);

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
