<?php
/**
 * templates/navbar.php
 *
 * White sticky top bar with search, notifications, and avatar controls.
 *
 * DASHBOARD UPDATE: the notification bell used to be a plain link to
 * the dashboard's #notifications anchor. It now toggles an anchored
 * popover (list + "Mark all as read" + "View all notifications"),
 * populated from $t8RecentNotifications (set in public/index.php via
 * t8_recent_notifications()). Falls back to an empty list gracefully
 * if that variable wasn't set for some reason, so this template still
 * degrades safely.
 */
declare(strict_types=1);

$pageTitle = $pageTitle ?? APP_NAME;

// Small local lookup for the subtitle line under the page title.
// Not part of routes.php's contract (file/label) - purely cosmetic,
// safe to extend per-page without touching the route whitelist.
$t8NavSubtitles = [
    'dashboard'   => 'Facilities & administrative overview',
    'reservation' => 'Manage facility bookings and approvals',
    'visitor'     => 'Track visitor check-in and check-out',
    'documents'   => 'Upload, manage, version, approve, and archive documents',
    'retention'   => 'Track retention policies, disposition schedules, and compliance for records',
    'legal'       => 'Legal cases and related filings',
    'contracts'   => 'Contracts, parties, and obligations',
];
$t8NavSubtitle = $t8NavSubtitles[current_page()] ?? '';

$t8UserName = function_exists('t8_current_user_name') ? t8_current_user_name() : 'Guest';
$t8UserInitial = strtoupper(substr(trim($t8UserName), 0, 1) ?: '?');
$t8UnreadNotifications = $t8UnreadNotifications ?? 0;
$t8RecentNotifications = $t8RecentNotifications ?? [];
?>
<header class="t8-navbar">
    <div class="t8-navbar-left">
        <button class="t8-navbar-toggle" id="t8SidebarToggle" type="button" aria-label="Toggle menu">
            <span></span><span></span><span></span>
        </button>
        <div class="t8-navbar-heading">
            <div class="t8-navbar-title"><?= e($pageTitle) ?></div>
            <?php if ($t8NavSubtitle !== ''): ?>
                <div class="t8-navbar-subtitle"><?= e($t8NavSubtitle) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="t8-navbar-search">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="search" id="t8GlobalSearch" placeholder="Search anything..." autocomplete="off"
               aria-label="Search all modules" aria-controls="t8GlobalSearchResults"
               data-search-url="<?= e(base_url('global_search.php')) ?>">
        <div class="t8-global-search-results" id="t8GlobalSearchResults" role="region" aria-live="polite" hidden></div>
    </div>

    <?php require __DIR__ . '/context_bar.php'; ?>

    <div class="t8-navbar-user">
        <div class="t8-notif-wrap">
            <button type="button" class="t8-navbar-bell" id="t8NotifBell"
                    aria-haspopup="true" aria-expanded="false"
                    aria-label="Notifications<?= $t8UnreadNotifications > 0 ? ' (' . e((string) $t8UnreadNotifications) . ' unread)' : '' ?>">
                <i class="fa-regular fa-bell"></i>
                <?php if ($t8UnreadNotifications > 0): ?>
                    <span class="t8-navbar-bell-dot" id="t8NotifBellDot"><?= e((string) min($t8UnreadNotifications, 99)) ?></span>
                <?php endif; ?>
            </button>

            <div class="t8-notif-popover" id="t8NotifPopover" role="menu"
                 data-action-url="<?= e(base_url('notifications_action.php')) ?>">
                <?= t8_csrf_field() ?>
                <div class="t8-notif-popover-header">
                    <span>Notifications</span>
                    <button type="button" class="t8-notif-mark-all" id="t8NotifMarkAll">Mark all read</button>
                </div>
                <div class="t8-notif-list" id="t8NotifList">
                    <?php if ($t8RecentNotifications === []): ?>
                        <div class="t8-notif-empty">You're all caught up.</div>
                    <?php else: ?>
                        <?php foreach ($t8RecentNotifications as $n): ?>
                            <?php $isUnread = ($n['status'] ?? '') === 'unread'; ?>
                            <button type="button" class="t8-notif-item<?= $isUnread ? ' t8-notif-item-unread' : '' ?>"
                                    data-notif-id="<?= e((string) $n['id']) ?>"
                                    data-target-url="<?= e((string) ($n['target_url'] ?? '')) ?>">
                                <p class="t8-notif-item-message"><?= e((string) $n['message']) ?></p>
                                <span class="t8-notif-item-time"><?= e(format_date((string) $n['created_at'], 'M d, Y g:i A')) ?></span>
                            </button>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="t8-notif-popover-footer">
                    <a href="<?= e(page_url('notifications')) ?>">View all notifications</a>
                </div>
            </div>
        </div>

        <span class="t8-navbar-avatar"><?= e($t8UserInitial) ?></span>
        <span class="t8-navbar-username-block">
            <span class="t8-navbar-username"><?= e($t8UserName) ?></span>
            <span class="t8-navbar-role-text"><?= e(strtoupper(t8_current_role() ?? 'guest')) ?></span>
        </span>

        <!-- Logout uses a POST form so state-changing requests are explicit. -->
        <form method="post" action="<?= e(APP_URL) ?>/logout.php" class="t8-navbar-logout-form">
            <button type="submit" class="t8-btn t8-btn-outline t8-btn-sm">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                <span>Logout</span>
            </button>
        </form>
    </div>
</header>
