<?php
/**
 * permissions.php
 * Thin role-checking helpers. Real permission granularity (per-module,
 * per-action) can grow into a proper table (role_permissions) later —
 * for Milestone 0 this only needs to answer "is this role allowed here."
 *
 * Requires auth_check.php to have run first (needs t8_current_role()).
 */

declare(strict_types=1);

/**
 * @param string|string[] $allowedRoles
 */
function t8_has_role($allowedRoles): bool
{
    $role = t8_current_role();
    if ($role === null) {
        return false;
    }

    $allowedRoles = is_array($allowedRoles) ? $allowedRoles : [$allowedRoles];
    return in_array($role, $allowedRoles, true);
}

/**
 * Stops the request with a 403 if the current user's role isn't allowed.
 * Call at the top of a module's index.php:
 *   t8_require_role(['admin', 'facilities_staff']);
 *
 * @param string|string[] $allowedRoles
 */
function t8_require_role($allowedRoles): void
{
    if (t8_has_role($allowedRoles)) {
        return;
    }

    http_response_code(403);
    echo '<div class="t8-alert t8-alert-danger">403 — You do not have permission to view this page.</div>';

    // Try to log who was denied and from where, if we have a DB handle
    // and a logged-in user (best-effort — must never block the 403).
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $userId = function_exists('t8_current_user_id') ? t8_current_user_id() : null;
        if ($userId !== null && function_exists('t8_audit_log')) {
            t8_audit_log(
                $GLOBALS['pdo'],
                $userId,
                'access',
                $userId,
                '403_denied',
                null,
                current_page()
            );
        }
    }

    // Close out whatever shell markup index.php already opened
    // (.t8-main / .t8-shell) so the response is valid HTML.
    echo '</main></div>';
    if (is_file(__DIR__ . '/../../templates/footer.php')) {
        $page = current_page();
        require __DIR__ . '/../../templates/footer.php';
    }
    exit;
}
