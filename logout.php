<?php
/**
 * logout.php
 * Destroys the session created by login.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/includes/helpers.php';

t8_session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "405 Method Not Allowed - logout must be a POST request.";
    exit;
}

// Best-effort audit entry before the session (and $_SESSION['user_id'])
// is wiped below.
if (!empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/app/includes/db_connect.php';
    require_once __DIR__ . '/app/includes/audit.php';
    t8_audit_log($pdo, (int) $_SESSION['user_id'], 'user', (int) $_SESSION['user_id'], 'logout');
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

header('Location: ' . APP_URL . '/login.php');
exit;
