<?php
/**
 * modules/notifications/index.php
 * DASHBOARD UPDATE: dedicated "View all notifications" page linked
 * from the navbar bell popover. Read-only list (marking read happens
 * from the popover itself, or by visiting this page — see below).
 * Not in the sidebar (routes.php marks it hidden).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/notifications.php';

$pageTitle = 'Notifications';
$currentUserId = t8_current_user_id();

// Visiting the full list is a reasonable "I've seen these" signal too,
// same as opening the popover — mark everything read on load.
t8_mark_all_notifications_read($pdo, (int) $currentUserId);

$notifications = t8_all_notifications($pdo, $currentUserId, 200);
?>
<h1>Notifications</h1>
<p class="t8-help-text">Your most recent notifications, newest first.</p>

<div class="t8-card">
    <?php if ($notifications === []): ?>
        <div class="t8-empty">You don't have any notifications yet.</div>
    <?php else: ?>
        <div class="t8-notification-list" style="padding: 0 var(--t8-space-4);">
            <?php foreach ($notifications as $n): ?>
                <div class="t8-notification-item">
                    <i class="fa-solid fa-bell"></i>
                    <div>
                        <?php if (!empty($n['target_url'])): ?><p><a href="<?= e((string) $n['target_url']) ?>"><?= e((string) $n['message']) ?></a></p><?php else: ?><p><?= e((string) $n['message']) ?></p><?php endif; ?>
                        <time><?= e(format_date((string) $n['created_at'], 'M d, Y g:i A')) ?></time>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
