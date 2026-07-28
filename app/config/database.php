<?php
/**
 * database.php
 * DB credentials + PDO singleton.
 */

declare(strict_types=1);

define('DB_HOST', 'localhost');
define('DB_NAME', 'capstone_shared_db'); // create db from xampp admin then import database
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

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
            self::$connection = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Known low-priority tech debt (docs/TechDebt.md): a bare
            // die() here bypasses any future error-handling/logging
            // layer. Acceptable for now — a DB-less app can't do
            // anything else useful anyway.
            die('Database connection failed: ' . $e->getMessage());
        }

        return self::$connection;
    }
}
