<?php
/**
 * index.php — Front Controller
 * ---------------------------------------------------------------
 * Every module page is reached through here: index.php?page=reservation
 * Unknown/unlisted pages fall through to a 404 — the route map in
 * app/config/routes.php is a whitelist, not a guess.
 *
 * Flow: bootstrap -> auth check -> resolve route -> role check ->
 *       render (header + navbar + sidebar) -> module content -> footer
 * ---------------------------------------------------------------
 */

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/constants.php';
require_once __DIR__ . '/app/includes/db_connect.php';
require_once __DIR__ . '/app/includes/auth_check.php';   // sets up $_SESSION, redirects if unauthenticated
require_once __DIR__ . '/app/includes/helpers.php';
require_once __DIR__ . '/app/includes/permissions.php';
require_once __DIR__ . '/app/includes/audit.php';
require_once __DIR__ . '/app/includes/notifications.php';

$routes = require __DIR__ . '/app/config/routes.php';

$page = $_GET['page'] ?? 'dashboard';
$t8UnreadNotifications = t8_unread_notification_count($pdo, t8_current_user_id());

$moduleFile = array_key_exists($page, $routes)
    ? __DIR__ . '/' . $routes[$page]['file']
    : null;

if ($moduleFile === null || !is_file($moduleFile)) {
    http_response_code(404);
    $pageTitle = 'Page Not Found';
    require __DIR__ . '/templates/header.php';
    require __DIR__ . '/templates/navbar.php';
    echo '<main class="t8-main t8-main-full">';
    echo '  <div class="t8-alert t8-alert-danger">404 — That page does not exist.</div>';
    echo '  <p><a href="' . e(page_url('dashboard')) . '">Back to dashboard</a></p>';
    echo '</main>';
    require __DIR__ . '/templates/footer.php';
    ob_end_flush();
    exit;
}

// $pageTitle can be overridden inside the module file before it echoes
// content — templates/header.php reads it.
$pageTitle = ucfirst($page);

require __DIR__ . '/templates/header.php';
require __DIR__ . '/templates/navbar.php';
?>
<div class="t8-shell">
    <?php require __DIR__ . '/templates/sidebar.php'; ?>
    <main class="t8-main">
        <?php
        if (!empty($routes[$page]['roles'])) {
            t8_require_role($routes[$page]['roles']);
        }
        require $moduleFile;
        ?>
    </main>
</div>
<?php
require __DIR__ . '/templates/footer.php';
ob_end_flush();
