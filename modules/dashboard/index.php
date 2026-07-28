<?php
/**
 * modules/dashboard/index.php
 * Milestone 2 will flesh this out fully. For Milestone 0 it already
 * runs real (safe) COUNT queries against the schema so the page isn't
 * just static placeholder markup, and proves db_connect.php works
 * end-to-end.
 *
 * $pdo, e(), page_url() etc. are all available here - the front
 * controller (index.php) already required everything needed.
 */

declare(strict_types=1);

$pageTitle = 'Dashboard';

$stats = [
    'Pending Reservations' => 0,
    'Visitors Today'       => 0,
    'Active Contracts'     => 0,
    'Open Legal Cases'     => 0,
];

// Cosmetic-only metadata per stat card (icon + color variant). Purely
// a display lookup keyed by the same labels above - does not touch
// the $stats values or the queries that populate them.
$statMeta = [
    'Pending Reservations' => ['icon' => 'fa-calendar-check', 'variant' => ''],
    'Visitors Today'       => ['icon' => 'fa-id-card-clip',   'variant' => 't8-stat-icon-info'],
    'Active Contracts'     => ['icon' => 'fa-file-contract',  'variant' => 't8-stat-icon-success'],
    'Open Legal Cases'     => ['icon' => 'fa-scale-balanced', 'variant' => 't8-stat-icon-warning'],
];

$dbError = null;
$recentActivities = [];
$notifications = [];

try {
    $stats['Pending Reservations'] = (int) $pdo
        ->query("SELECT COUNT(*) FROM team8_reservations WHERE status = 'pending'")
        ->fetchColumn();

    $stats['Visitors Today'] = (int) $pdo
        ->query("SELECT COUNT(*) FROM team8_visitors WHERE DATE(check_in_time) = CURDATE()")
        ->fetchColumn();

    $stats['Active Contracts'] = (int) $pdo
        ->query("SELECT COUNT(*) FROM team8_contracts WHERE status = 'active'")
        ->fetchColumn();

    $stats['Open Legal Cases'] = (int) $pdo
        ->query("SELECT COUNT(*) FROM team8_legal_cases WHERE status = 'open'")
        ->fetchColumn();
} catch (PDOException $e) {
    // Tables may not be imported yet on a fresh clone - fail soft, not fatal.
    $dbError = 'Could not load live stats - has database/schema.sql been imported yet?';
}

try {
    $recentActivities = $pdo->query(
        'SELECT a.action, a.entity_type, a.created_at, u.full_name
         FROM audit_logs a
         INNER JOIN users u ON u.id = a.user_id
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT 6'
    )->fetchAll(PDO::FETCH_ASSOC);

    $notificationStmt = $pdo->prepare(
        'SELECT message, status, created_at
         FROM notifications
         WHERE user_id = :user_id
         ORDER BY created_at DESC, id DESC
         LIMIT 5'
    );
    $notificationStmt->execute(['user_id' => t8_current_user_id()]);
    $notifications = $notificationStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError ??= 'Could not load all dashboard information - has database/schema.sql been imported yet?';
}

$activityIcons = [
    'login' => 'fa-right-to-bracket',
    'logout' => 'fa-right-from-bracket',
    '403_denied' => 'fa-shield-halved',
];
?>
<h1>Welcome, <?= e(t8_current_user_name()) ?></h1>
<p class="t8-help-text">Facilities &amp; Administrative Management overview.</p>

<?php if ($dbError !== null): ?>
    <div class="t8-alert t8-alert-warning"><?= e($dbError) ?></div>
<?php endif; ?>

<div class="t8-stat-grid">
    <?php foreach ($stats as $label => $value): ?>
        <?php $meta = $statMeta[$label] ?? ['icon' => 'fa-chart-simple', 'variant' => '']; ?>
        <div class="t8-stat-card">
            <div class="t8-stat-icon <?= e($meta['variant']) ?>">
                <i class="fa-solid <?= e($meta['icon']) ?>"></i>
            </div>
            <div class="t8-stat-body">
                <div class="t8-stat-value"><?= e((string) $value) ?></div>
                <div class="t8-stat-label"><?= e($label) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="t8-dashboard-grid">
    <div class="t8-dashboard-column">
        <div class="t8-card">
            <div class="t8-card-header">
                <h2 class="t8-card-title">Quick Actions</h2>
            </div>
            <div class="t8-quick-actions">
                <a class="t8-btn t8-btn-accent" href="<?= e(page_url('reservation')) ?>">
                    <i class="fa-solid fa-calendar-plus"></i> New Reservation
                </a>
                <a class="t8-btn t8-btn-outline" href="<?= e(page_url('visitor')) ?>">
                    <i class="fa-solid fa-id-card-clip"></i> Register Visitor
                </a>
                <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents')) ?>">
                    <i class="fa-solid fa-file-arrow-up"></i> Upload Document
                </a>
                <a class="t8-btn t8-btn-outline" href="<?= e(page_url('contracts')) ?>">
                    <i class="fa-solid fa-file-contract"></i> New Contract
                </a>
            </div>
        </div>

        <section class="t8-card t8-notifications-card" id="notifications">
            <div class="t8-card-header">
                <h2 class="t8-card-title">Notifications</h2>
                <?php if ($t8UnreadNotifications > 0): ?>
                    <span class="t8-notification-count"><?= e((string) $t8UnreadNotifications) ?> unread</span>
                <?php endif; ?>
            </div>
            <?php if ($notifications === []): ?>
                <div class="t8-empty">You have no notifications.</div>
            <?php else: ?>
                <div class="t8-notification-list">
                    <?php foreach ($notifications as $notification): ?>
                        <div class="t8-notification-item<?= $notification['status'] === 'unread' ? ' t8-notification-unread' : '' ?>">
                            <i class="fa-regular fa-bell"></i>
                            <div>
                                <p><?= e((string) $notification['message']) ?></p>
                                <time datetime="<?= e((string) $notification['created_at']) ?>"><?= e(format_date((string) $notification['created_at'], 'M d, Y g:i A')) ?></time>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title">Recent Activity</h2>
        </div>
        <?php if ($recentActivities === []): ?>
            <div class="t8-empty">No activity has been recorded yet.</div>
        <?php else: ?>
            <div class="t8-activity-list">
                <?php foreach ($recentActivities as $activity): ?>
                    <?php
                    $action = (string) $activity['action'];
                    $description = sprintf('%s %s %s', (string) $activity['full_name'], str_replace('_', ' ', $action), str_replace('_', ' ', (string) $activity['entity_type']));
                    ?>
                    <div class="t8-activity-item">
                        <span class="t8-activity-icon"><i class="fa-solid <?= e($activityIcons[$action] ?? 'fa-clock-rotate-left') ?>"></i></span>
                        <span class="t8-activity-text"><?= e(ucfirst($description)) ?></span>
                        <time class="t8-activity-time" datetime="<?= e((string) $activity['created_at']) ?>"><?= e(format_date((string) $activity['created_at'], 'M d, g:i A')) ?></time>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
