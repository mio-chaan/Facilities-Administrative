<?php
/**
 * config.php
 */

declare(strict_types=1);

// ---- App-wide settings — edit these for your environment ---------------

define('APP_NAME', 'RAM YUM - Facilities & Administrative Management');
define('APP_ENV', getenv('APP_ENV') ?: 'local');
define('APP_DEBUG', filter_var(getenv('APP_DEBUG') ?: (APP_ENV === 'local' ? '1' : '0'), FILTER_VALIDATE_BOOLEAN));
define('APP_URL', rtrim(getenv('APP_URL') ?: 'http://localhost:8000', '/'));
define('APP_TIMEZONE', 'Asia/Manila');
define('DB_TEAM8_PREFIX', 'team8_');
// Load local config (contains secrets) if present
$__local_cfg = __DIR__ . '/config.local.php';
if (file_exists($__local_cfg)) {
    require $__local_cfg;
}

// AI PROVIDER: Gemini (previously OpenAI — see app/includes/ai_helper.php).
// Ensure GEMINI_API_KEY is defined: prefer explicit constant (set in
// config.local.php), then getenv, then $_ENV, else empty.
if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? ''));
}
define('UPLOAD_MAX_SIZE_MB', 10);
// Files are served only through authorised application actions, never directly
// by the web server.
define('UPLOAD_DIR', dirname(__DIR__, 2) . '/storage/uploads');


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
