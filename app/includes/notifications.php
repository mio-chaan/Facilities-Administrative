<?php
/** Shared, fail-soft notification helpers. */

declare(strict_types=1);

if (!function_exists('t8_notification_targets_supported')) {
    function t8_notification_targets_supported(PDO $pdo): bool
    {
        try { return (bool) $pdo->query("SHOW COLUMNS FROM notifications LIKE 'target_url'")->fetch(PDO::FETCH_ASSOC); }
        catch (PDOException $e) { return false; }
    }
}

if (!function_exists('t8_notify_user')) {
    /** Creates a brief, non-sensitive notification owned by exactly one user. */
    function t8_notify_user(PDO $pdo, int $userId, string $message, ?string $targetUrl = null): void
    {
        try {
            if (t8_notification_targets_supported($pdo)) {
                $pdo->prepare('INSERT INTO notifications (user_id, message, target_url, status) VALUES (:user_id, :message, :target_url, "unread")')
                    ->execute(['user_id' => $userId, 'message' => $message, 'target_url' => $targetUrl]);
            } else {
                $pdo->prepare('INSERT INTO notifications (user_id, message, status) VALUES (:user_id, :message, "unread")')
                    ->execute(['user_id' => $userId, 'message' => $message]);
            }
        } catch (PDOException $e) { /* notifications never break a workflow */ }
    }
}

if (!function_exists('t8_admin_notification_once_today')) {
    /** Creates one generic operational alert per administrator each day. */
    function t8_admin_notification_once_today(PDO $pdo, string $message, string $targetUrl): void
    {
        try {
            $admins = $pdo->query("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.role_name = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($admins as $adminId) {
                $lockName = 'team8_notification_' . sha1((string) $adminId . '|' . $message . '|' . date('Y-m-d'));
                $lockStmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 5)');
                $lockStmt->execute(['lock_name' => $lockName]);
                if ((int) $lockStmt->fetchColumn() !== 1) {
                    continue;
                }
                try {
                    $check = $pdo->prepare('SELECT id FROM notifications WHERE user_id = :user_id AND message = :message AND DATE(created_at) = CURDATE() LIMIT 1');
                    $check->execute(['user_id' => $adminId, 'message' => $message]);
                    if (!$check->fetchColumn()) { t8_notify_user($pdo, (int) $adminId, $message, $targetUrl); }
                } finally {
                    $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                    $releaseStmt->execute(['lock_name' => $lockName]);
                }
            }
        } catch (PDOException $e) { /* optional alert generation */ }
    }
}

if (!function_exists('t8_refresh_operational_notifications')) {
    function t8_refresh_operational_notifications(PDO $pdo): void
    {
        $alerts = [
            ["SELECT COUNT(*) FROM team8_reservations WHERE status = 'pending'", 'Reservation approvals require review.', 'index.php?page=reservation'],
            ["SELECT COUNT(*) FROM team8_documents WHERE status = 'pending'", 'Document submissions require review.', 'index.php?page=documents&action=browse&review_status=pending'],
            ["SELECT COUNT(*) FROM team8_contracts WHERE status IN ('expiring_soon', 'pending_renewal')", 'Contract renewal attention is required.', 'index.php?page=contracts'],
            ["SELECT COUNT(*) FROM team8_records WHERE disposition_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND status = 'active'", 'Retention records are approaching disposition.', 'index.php?page=retention'],
            ["SELECT COUNT(*) FROM team8_visitors WHERE status = 'scheduled' AND DATE(scheduled_date) = CURDATE()", 'Visitor activity is scheduled today.', 'index.php?page=visitor'],
        ];
        foreach ($alerts as [$sql, $message, $targetUrl]) {
            try { if ((int) $pdo->query($sql)->fetchColumn() > 0) { t8_admin_notification_once_today($pdo, $message, $targetUrl); } }
            catch (PDOException $e) { /* migration/table may not yet exist */ }
        }
    }
}

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
    function t8_recent_notifications(PDO $pdo, ?int $userId, int $limit = 8): array
    {
        if ($userId === null) {
            return [];
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT id, message, " . (t8_notification_targets_supported($pdo) ? 'target_url,' : 'NULL AS target_url,') . " status, created_at
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
                "SELECT id, message, " . (t8_notification_targets_supported($pdo) ? 'target_url,' : 'NULL AS target_url,') . " status, created_at
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
