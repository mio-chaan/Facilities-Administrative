<?php
/** Shared, fail-soft notification helpers. */

declare(strict_types=1);

if (!function_exists('t8_unread_notification_count')) {
    function t8_unread_notification_count(PDO $pdo, ?int $userId): int
    {
        if ($userId === null) {
            return 0;
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND status = 'unread'"
            );
            $stmt->execute(['user_id' => $userId]);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            // An incomplete shared-schema import must not stop page rendering.
            return 0;
        }
    }
}
