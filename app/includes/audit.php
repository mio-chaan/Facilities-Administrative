<?php
/**
 * audit.php
 * Minimal writer for auth and access events such as login, logout,
 * and 403s (see login.php, logout.php, permissions.php).
 *
 * Requires $pdo (see db_connect.php).
 */

declare(strict_types=1);

if (!function_exists('t8_audit_log')) {
    function t8_audit_log(
        PDO $pdo,
        ?int $userId,
        string $entityType,
        int $entityId,
        string $action,
        ?string $oldValue = null,
        ?string $newValue = null
    ): void {
        // audit_logs.user_id is NOT NULL in the shared schema - an
        // action with no known actor (e.g. a failed login) isn't
        // logged here rather than writing a fake user id.
        if ($userId === null) {
            return;
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO audit_logs (user_id, entity_type, entity_id, action, old_value, new_value)
                 VALUES (:user_id, :entity_type, :entity_id, :action, :old_value, :new_value)'
            );
            $stmt->execute([
                'user_id'     => $userId,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'action'      => $action,
                'old_value'   => $oldValue,
                'new_value'   => $newValue,
            ]);
        } catch (PDOException $e) {
            // Audit logging must never break the request it's logging.
            error_log('Audit log write failed: ' . $e->getMessage());
        }
    }
}
