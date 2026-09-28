<?php
/**
 * db_connect.php
 * Single include point for a ready-to-use PDO connection.
 * Usage in any module: require_once __DIR__ . '/../../app/includes/db_connect.php';
 * then use the $pdo variable.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$pdo = Database::getConnection();
