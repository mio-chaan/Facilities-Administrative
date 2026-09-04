<?php
/**
 * database.php
 * DB credentials + PDO singleton.
 */

declare(strict_types=1);

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'capstone_shared_db');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

final class Database
{
    private static ?PDO $connection = null;

    public static function getConnection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

        try {
            if (defined('APP_ENV') && APP_ENV === 'production' && DB_USER === 'root' && DB_PASS === '') {
                throw new RuntimeException('Production database credentials are not configured.');
            }
            self::$connection = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (Throwable $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            if (defined('APP_DEBUG') && APP_DEBUG) {
                die('Database connection failed.');
            }
            http_response_code(503);
            die('The service is temporarily unavailable.');
        }

        return self::$connection;
    }
}
