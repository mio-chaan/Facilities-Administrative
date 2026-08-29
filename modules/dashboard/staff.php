<?php
/** Personal dashboard rendered for every non-admin role. */
declare(strict_types=1);

$pageTitle = 'My Dashboard';
$userId = t8_current_user_id();
$stats = ['My Reservations' => 0, 'My Visitors' => 0, 'My Documents' => 0, 'My Records' => 0, 'My Tasks' => 0];
$statMeta = [
    'My Reservations' => ['icon' => 'fa-calendar-check', 'class' => 't8-staff-icon-red'],
    'My Visitors' => ['icon' => 'fa-users', 'class' => 't8-staff-icon-green'],
    'My Documents' => ['icon' => 'fa-file-lines', 'class' => 't8-staff-icon-blue'],
    'My Records' => ['icon' => 'fa-box-archive', 'class' => 't8-staff-icon-orange'],
    'My Tasks' => ['icon' => 'fa-list-check', 'class' => 't8-staff-icon-purple'],
];
$pending = [];

try {
    $queries = [
        'My Reservations' => ['SELECT COUNT(*) FROM team8_reservations WHERE user_id = :id AND deleted_at IS NULL', 'reservation'],
        'My Visitors' => ['SELECT COUNT(*) FROM team8_visitors WHERE logged_by = :id', 'visitor'],
        'My Documents' => ['SELECT COUNT(*) FROM team8_documents WHERE uploaded_by = :id AND deleted_at IS NULL', 'document'],
        'My Records' => ['SELECT COUNT(*) FROM team8_records WHERE custodian_id = :id AND deleted_at IS NULL', 'record'],
    ];
    foreach ($queries as $label => [$sql]) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $userId]);
        $stats[$label] = (int) $stmt->fetchColumn();
    }

    $taskStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM team8_compliance_checks WHERE checked_by = :id AND result IN ('needs_review', 'non_compliant')"
    );
    $taskStmt->execute(['id' => $userId]);
    $stats['My Tasks'] = (int) $taskStmt->fetchColumn();

    $pendingStmt = $pdo->prepare(
        "SELECT id, status, COALESCE(start_time, schedule) AS scheduled_at
         FROM team8_reservations
         WHERE user_id = :id AND status IN ('pending', 'rejected', 'cancellation_pending') AND deleted_at IS NULL
         ORDER BY updated_at DESC LIMIT 5"
    );
    $pendingStmt->execute(['id' => $userId]);
    $pending = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // A partial local schema should not expose unrelated data or stop the page.
}
?>
<section class="t8-dashboard" aria-labelledby="dashboard-title">
    <div class="t8-dashboard-heading">
        <div>
            <h1 id="dashboard-title">My Dashboard</h1>
            <p class="t8-help-text">Your requests, documents, tasks, and notifications.</p>
        </div>
    </div>

    <div class="t8-staff-summary-grid">
        <?php foreach ($stats as $label => $count): ?>
            <?php $meta = $statMeta[$label]; ?>
            <div class="t8-card t8-staff-stat-card">
                <div class="t8-staff-stat-icon <?= e($meta['class']) ?>" aria-hidden="true"><i class="fa-solid <?= e($meta['icon']) ?>"></i></div>
                <div class="t8-staff-stat-body">
                    <p class="t8-help-text"><?= e($label) ?></p>
                    <div class="t8-staff-stat-value"><?= e((string) $count) ?></div>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="t8-card t8-staff-actions-card">
            <div class="t8-card-header"><h2 class="t8-card-title">Quick Actions</h2></div>
            <div class="t8-card-body" style="display:flex;gap:8px;flex-wrap:wrap">
                <a class="t8-btn t8-btn-accent" href="<?= e(page_url('reservation', ['action' => 'create'])) ?>"><i class="fa-solid fa-calendar-plus"></i> New Reservation</a>
                <a class="t8-btn t8-btn-outline" href="<?= e(page_url('visitor', ['action' => 'create'])) ?>"><i class="fa-solid fa-user-plus"></i> Visitor Request</a>
                <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents', ['action' => 'create'])) ?>"><i class="fa-solid fa-upload"></i> Upload Document</a>
                <a class="t8-btn t8-btn-outline" href="<?= e(page_url('retention')) ?>"><i class="fa-solid fa-list-check"></i> View Tasks</a>
            </div>
        </div>

        <div class="t8-card t8-staff-updates-card">
            <div class="t8-card-header"><h2 class="t8-card-title">My Reservation Updates</h2></div>
            <div class="t8-card-body">
                <?php if ($pending === []): ?>
                    <div class="t8-staff-empty"><i class="fa-regular fa-calendar-check"></i><span>No reservation updates need your attention.</span></div>
                <?php else: foreach ($pending as $row): ?>
                    <p style="margin:0 0 10px">Reservation #<?= e((string) $row['id']) ?> — <span class="t8-badge t8-badge-pending"><?= e(ucwords(str_replace('_', ' ', (string) $row['status']))) ?></span></p>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="t8-card t8-staff-notifications-card">
            <div class="t8-card-header"><h2 class="t8-card-title">Notifications</h2></div>
            <div class="t8-card-body">
            <?php if (empty($t8RecentNotifications)): ?><div class="t8-staff-empty"><i class="fa-regular fa-bell-slash"></i><span>No notifications.</span></div>
                <?php else: foreach ($t8RecentNotifications as $notification): ?>
                    <p style="margin:0 0 10px"><?= e((string) $notification['message']) ?></p>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</section>
