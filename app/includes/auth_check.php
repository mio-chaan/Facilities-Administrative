<?php
/**
 * auth_check.php
 *
 * Session contract:
 *
 *      $_SESSION['user_id']
 *      $_SESSION['full_name']
 *      $_SESSION['role']          (string, e.g. 'admin')
 *      $_SESSION['department_id']
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/helpers.php';

t8_session_start();

// ---- Optional dev bypass (local env only, off by default) --------------
$devBypass = APP_ENV === 'local' && AUTH_DEV_BYPASS === true;
if ($devBypass && empty($_SESSION['user_id'])) {
    $_SESSION['user_id']       = 1;
    $_SESSION['full_name']     = 'Dev Tester';
    $_SESSION['role']          = 'admin';
    $_SESSION['department_id'] = null;
}
// ---- END dev bypass -------------------------------------------------------

if (empty($_SESSION['user_id'])) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

/**
 * Convenience accessors — modules should use these instead of touching
 * $_SESSION directly, so swapping in real auth later only changes
 * login.php/logout.php, not every module.
 */
function t8_current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function t8_current_user_name(): string
{
    return $_SESSION['full_name'] ?? 'Unknown User';
}

function t8_current_role(): ?string
{
    return $_SESSION['role'] ?? null;
}
