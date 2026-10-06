<?php
/**
 * dashboard.php
 * Conventional "post-login landing" URL (auth team's login flow can
 * redirect here). All it does is hand off to the front controller —
 * the actual dashboard content lives in modules/dashboard/index.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/app/config/config.php';
header('Location: ' . APP_URL . '/index.php?page=dashboard');
exit;
