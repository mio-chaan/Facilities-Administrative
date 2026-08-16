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

if (!function_exists('t8_recent_notifications')) {
    /**
     * DASHBOARD UPDATE: powers the new bell popover (templates/navbar.php).
     * Newest-first, capped at $limit. Fails soft (empty array) so a
     * missing/incomplete shared `notifications` table never breaks the
     * navbar, matching t8_unread_notification_count()'s behavior.
     */
    function t8_recent_notifications(PDO $pdo, ?int $userId, int $limit = 8): array
    {
        if ($userId === null) {
            return [];
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT id, message, status, created_at
                 FROM notifications
                 WHERE user_id = :user_id
                 ORDER BY created_at DESC, id DESC
                 LIMIT :limit"
            );
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
}

if (!function_exists('t8_all_notifications')) {
    /** Powers the dedicated "View all notifications" page. */
    function t8_all_notifications(PDO $pdo, ?int $userId, int $limit = 200): array
    {
        if ($userId === null) {
            return [];
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT id, message, status, created_at
                 FROM notifications
                 WHERE user_id = :user_id
                 ORDER BY created_at DESC, id DESC
                 LIMIT :limit"
            );
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
}

if (!function_exists('t8_mark_notification_read')) {
    /** Marks a single notification read — scoped to its owner, never trusts the id alone. */
    function t8_mark_notification_read(PDO $pdo, int $userId, int $notificationId): bool
    {
        try {
            $stmt = $pdo->prepare(
                "UPDATE notifications SET status = 'read' WHERE id = :id AND user_id = :user_id"
            );
            $stmt->execute(['id' => $notificationId, 'user_id' => $userId]);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('t8_mark_all_notifications_read')) {
    function t8_mark_all_notifications_read(PDO $pdo, int $userId): bool
    {
        try {
            $stmt = $pdo->prepare(
                "UPDATE notifications SET status = 'read' WHERE user_id = :user_id AND status = 'unread'"
            );
            $stmt->execute(['user_id' => $userId]);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
}
