<?php
/**
 * modules/dashboard/index.php
 *
 * DASHBOARD UPDATE — see docs of the requesting spec for full context.
 * Summary of what changed vs. the previous version:
 *   - Reservation Trend header: title left, Month/Year filter
 *     upper-right; "X Today" is now plain informational text (no red
 *     dot/badge styling).
 *   - The same $trendMonth/$trendYear selection now also drives the
 *     Reservation Activity card (previously all-time, current-status
 *     based) — both cards stay in sync because both read from the
 *     same GET params via one full-page reload on filter change.
 *   - "Reservation Status" -> "Reservation Activity": counts EVENTS
 *     (approve / reject / cancel / complete) that happened during the
 *     selected month, from audit_logs — not current reservation
 *     status. A reservation approved then later cancelled contributes
 *     to both Approved and Cancelled, per spec. Pending/Ongoing are
 *     no longer shown here.
 *   - "AI Insights" -> "Quick Insights" (label only — these were
 *     always plain dashboard metrics).
 *   - Recent Activities: capped to the 5 latest MEANINGFUL business
 *     events (login/logout/403 excluded), plus a meatballs menu ->
 *     "View Activity History" modal with the fuller list, a text
 *     search, an activity-type filter, and a "load more" button
 *     instead of an internal scrollbar.
 */

declare(strict_types=1);

$pageTitle = 'Dashboard';

$stats = [
    'Pending Reservations' => 0,
    'Visitors Today'       => 0,
    'Active Contracts'     => 0,
    'Open Legal Cases'     => 0,
];

$statMeta = [
    'Pending Reservations' => ['icon' => 'fa-calendar-check', 'variant' => ''],
    'Visitors Today'       => ['icon' => 'fa-id-card-clip',   'variant' => 't8-stat-icon-info'],
    'Active Contracts'     => ['icon' => 'fa-file-contract',  'variant' => 't8-stat-icon-success'],
    'Open Legal Cases'     => ['icon' => 'fa-scale-balanced', 'variant' => 't8-stat-icon-warning'],
];

$dbError = null;
$recentActivities = [];
$fullActivityHistory = [];
$notifications = [];

// Activity-log actions that represent real business events worth
// showing on the compact dashboard timeline. Auth/access noise
// (login, logout, 403_denied) is excluded here but still available
// in the full Activity History modal below.
const T8_DASHBOARD_MEANINGFUL_ACTIONS = [
    'create', 'update', 'approve', 'reject', 'cancel', 'admin_cancel',
    'cancellation_request', 'cancellation_approved', 'cancellation_rejected',
    'completed', 'delete', 'archive', 'restore', 'reactivate',
    'add_party', 'remove_party', 'add_obligation', 'delete_obligation',
    'complete_obligation', 'reopen_obligation', 'attach_document',
    'detach_document', 'new_version', 'check_in', 'check_out', 'schedule',
];

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
    $dbError = 'Could not load live stats - has database/schema.sql been imported yet?';
}

try {
    // Compact list for the dashboard card itself: latest 5 meaningful
    // events only.
    $placeholders = implode(',', array_fill(0, count(T8_DASHBOARD_MEANINGFUL_ACTIONS), '?'));
    $recentStmt = $pdo->prepare(
        "SELECT a.action, a.entity_type, a.created_at, u.full_name
         FROM audit_logs a
         INNER JOIN users u ON u.id = a.user_id
         WHERE a.action IN ($placeholders)
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT 5"
    );
    $recentStmt->execute(T8_DASHBOARD_MEANINGFUL_ACTIONS);
    $recentActivities = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fuller list for the "View Activity History" modal — includes
    // everything (login/logout stay available here, just not on the
    // compact dashboard card).
    $fullActivityHistory = $pdo->query(
        'SELECT a.action, a.entity_type, a.created_at, u.full_name
         FROM audit_logs a
         INNER JOIN users u ON u.id = a.user_id
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT 200'
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
    'login'      => 'fa-right-to-bracket',
    'logout'     => 'fa-right-from-bracket',
    '403_denied' => 'fa-shield-halved',
    'create'     => 'fa-plus',
    'update'     => 'fa-pen',
    'approve'    => 'fa-check',
    'reject'     => 'fa-xmark',
    'cancel'     => 'fa-ban',
    'admin_cancel' => 'fa-ban',
    'completed'  => 'fa-flag-checkered',
    'delete'     => 'fa-trash',
    'archive'    => 'fa-box-archive',
    'restore'    => 'fa-rotate-left',
];

/** Human-readable label for an audit_logs (action, entity_type) pair. */
function t8_activity_label(string $action, string $entityType): string
{
    $entity = str_replace('_', ' ', $entityType);
    $verb = str_replace('_', ' ', $action);
    return ucfirst(trim($entity . ' ' . $verb));
}
?>
<section class="t8-dashboard" aria-labelledby="dashboard-title">
<div class="t8-dashboard-heading">
    <div>
        <h1 id="dashboard-title">Dashboard</h1>
        <p class="t8-help-text">Facilities &amp; administrative management overview.</p>
    </div>
    <div class="t8-dashboard-date"><i class="fa-regular fa-calendar"></i> <?= e(date('F j, Y')) ?></div>
</div>

<?php if ($dbError !== null): ?>
    <div class="t8-alert t8-alert-warning"><?= e($dbError) ?></div>
<?php endif; ?>

<?php
// ---------------------------------------------------------------
// Trend/Activity month/year selection — validated server-side so a
// tampered query string never causes a DB error or a weird chart.
// This ONE selection now drives BOTH the Reservation Trend chart and
// the Reservation Activity donut below (per spec: same filter, both
// cards).
// ---------------------------------------------------------------
$trendMonth = isset($_GET['trend_month']) ? (int) $_GET['trend_month'] : (int) date('n');
$trendYear  = isset($_GET['trend_year'])  ? (int) $_GET['trend_year']  : (int) date('Y');
if ($trendMonth < 1 || $trendMonth > 12) { $trendMonth = (int) date('n'); }
if ($trendYear  < 2000 || $trendYear > 2100) { $trendYear = (int) date('Y'); }

// Full calendar month: 1st day through last day.
$trendMonthStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $trendYear, $trendMonth));
$trendMonthEnd   = $trendMonthStart->modify('last day of this month');

// Pre-fill every day of the month with 0 so the X-axis is always
// continuous even when some days have no reservations at all.
$trendCounts = [];
$cur = $trendMonthStart;
while ($cur <= $trendMonthEnd) {
    $trendCounts[$cur->format('Y-m-d')] = 0;
    $cur = $cur->add(new DateInterval('P1D'));
}

// ---------------------------------------------------------------
// Rest of dashboard data
// ---------------------------------------------------------------
// DASHBOARD UPDATE: Reservation Activity now counts EVENTS from
// audit_logs for the selected month, not current reservation status.
// Approved / Rejected / Cancelled / Completed only — Pending and
// Ongoing removed per spec (this card is an activity/history view,
// not a live status snapshot).
$activityCounts = ['approved' => 0, 'rejected' => 0, 'cancelled' => 0, 'completed' => 0];
$facilityUsage       = [];
$docCategories       = [];

try {
    $actStmt = $pdo->prepare(
        "SELECT action, COUNT(*) AS cnt
         FROM audit_logs
         WHERE entity_type = 'reservation'
           AND action IN ('approve', 'reject', 'cancel', 'admin_cancel', 'cancellation_approved', 'completed')
           AND created_at BETWEEN :start_dt AND :end_dt
         GROUP BY action"
    );
    $actStmt->execute([
        'start_dt' => $trendMonthStart->format('Y-m-d 00:00:00'),
        'end_dt'   => $trendMonthEnd->format('Y-m-d 23:59:59'),
    ]);
    foreach ($actStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cnt = (int) $row['cnt'];
        switch ($row['action']) {
            case 'approve':
                $activityCounts['approved'] += $cnt;
                break;
            case 'reject':
                $activityCounts['rejected'] += $cnt;
                break;
            case 'cancel':
            case 'admin_cancel':
            case 'cancellation_approved':
                $activityCounts['cancelled'] += $cnt;
                break;
            case 'completed':
                $activityCounts['completed'] += $cnt;
                break;
        }
    }

    // Facility utilisation bar chart (top 5 by booking count).
    $facStmt = $pdo->query(
        'SELECT facility_id, COUNT(*) AS cnt
         FROM team8_reservations
         GROUP BY facility_id
         ORDER BY cnt DESC
         LIMIT 5'
    );
    $facRows = $facStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($facRows !== false) {
        $getName = $pdo->prepare('SELECT name FROM team8_facilities WHERE id = :id LIMIT 1');
        foreach ($facRows as $r) {
            $fid  = $r['facility_id'];
            $cnt  = (int) $r['cnt'];
            $name = null;
            if ($fid !== null) {
                try {
                    $getName->execute(['id' => $fid]);
                    $name = $getName->fetchColumn();
                } catch (PDOException $e) {
                    $name = null;
                }
            }
            $facilityUsage[] = [
                'label' => $name ?: ($fid !== null ? "Facility #{$fid}" : 'Unknown'),
                'count' => $cnt,
            ];
        }
    }

    // ---------------------------------------------------------------
    // Monthly Reservation Trend — what this chart is actually answering:
    //   "On which dates do we have reservations, and how many are
    //    scheduled for each date?"
    // (unchanged from the previous version — see prior inline notes)
    // ---------------------------------------------------------------
    $trendStmt = $pdo->prepare(
        'SELECT DATE(COALESCE(start_time, schedule)) AS d, COUNT(*) AS cnt
         FROM team8_reservations
         WHERE status IN ("approved", "completed")
           AND COALESCE(start_time, schedule) IS NOT NULL
           AND DATE(COALESCE(start_time, schedule)) BETWEEN :start_date AND :end_date
         GROUP BY d
         ORDER BY d ASC'
    );
    $trendStmt->execute([
        'start_date' => $trendMonthStart->format('Y-m-d'),
        'end_date'   => $trendMonthEnd->format('Y-m-d'),
    ]);
    foreach ($trendStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $trendCounts[(string) $r['d']] = (int) $r['cnt'];
    }

} catch (PDOException $e) {
    echo '<div class="t8-alert t8-alert-warning">Could not load some dashboard data - has database/schema.sql been imported yet?</div>';
}

$activityTotal = array_sum($activityCounts);
$activityPercents = [];
foreach ($activityCounts as $k => $v) {
    $activityPercents[$k] = $activityTotal > 0 ? round($v / $activityTotal * 100, 1) : 0;
}

function pctWidth(int $value, int $max): string {
    if ($max <= 0) return '0%';
    return round($value / $max * 100, 1) . '%';
}

$facilityMax = 0;
foreach ($facilityUsage as $f) { $facilityMax = max($facilityMax, (int) $f['count']); }
$docMax = 0;
foreach ($docCategories as $d) { $docMax = max($docMax, (int) $d['count']); }

// ---------------------------------------------------------------
// Build flat arrays for Chart.js.
// ---------------------------------------------------------------
$trendLabels = [];
$trendValues = [];
foreach ($trendCounts as $date => $cnt) {
    $trendLabels[] = (int) (new DateTimeImmutable($date))->format('j');
    $trendValues[] = $cnt;
}

$trendMonthLabel  = $trendMonthStart->format('F Y');   // e.g. "August 2026"
$trendMonthTotal  = array_sum($trendValues);
$trendHasData     = $trendMonthTotal > 0;

// "X today" text — only meaningful when viewing the current month.
$isCurrentMonth   = ($trendYear === (int) date('Y') && $trendMonth === (int) date('n'));
$trendTodayCount  = $isCurrentMonth ? ($trendCounts[date('Y-m-d')] ?? 0) : null;

// Month/Year selector options.
$trendMonthNames = [
    1=>'January',2=>'February',3=>'March',4=>'April',
    5=>'May',6=>'June',7=>'July',8=>'August',
    9=>'September',10=>'October',11=>'November',12=>'December',
];
$trendYearOptions = range((int) date('Y') - 1, (int) date('Y') + 2);
?>

<div class="t8-main-grid">

    <!-- ============================================================
         RESERVATION TREND CARD
         ============================================================ -->
    <div class="t8-card t8-trend-card">
        <div class="t8-card-header">
            <div class="t8-trend-card-heading">
                <h2 class="t8-card-title">Reservation Trend</h2>
                <p class="t8-trend-card-sub">
                    <?= $trendHasData
                        ? e($trendMonthLabel)
                        : e($trendMonthLabel) . ' · no reservations scheduled yet' ?>
                </p>
            </div>

            <!-- Month / Year selector — upper right of the header, same
                 row as the title. Submitting reloads the page with
                 ?trend_month=&trend_year=, which both this chart AND
                 the Reservation Activity donut below read from. -->
            <form method="get" action="<?= e(base_url('index.php')) ?>" class="t8-trend-filter">
                <input type="hidden" name="page" value="dashboard">
                <label class="t8-help-text" style="margin:0;" for="t8TrendMonth">Month</label>
                <select class="t8-select" id="t8TrendMonth" name="trend_month" onchange="this.form.submit()">
                    <?php foreach ($trendMonthNames as $num => $name): ?>
                        <option value="<?= e((string) $num) ?>" <?= $num === $trendMonth ? 'selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="t8-select" id="t8TrendYear" name="trend_year" onchange="this.form.submit()">
                    <?php foreach ($trendYearOptions as $yo): ?>
                        <option value="<?= e((string) $yo) ?>" <?= $yo === $trendYear ? 'selected' : '' ?>><?= e((string) $yo) ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="t8-btn t8-btn-outline t8-btn-sm" type="submit">View</button></noscript>
            </form>
        </div>

        <div class="t8-card-body">
            <!-- "X Today" — plain informational text, not a badge/alert. -->
            <p class="t8-trend-today">
                <?php if ($trendTodayCount !== null): ?>
                    <strong><?= e((string) $trendTodayCount) ?></strong> Today
                <?php else: ?>
                    <strong><?= e((string) $trendMonthTotal) ?></strong> this month
                <?php endif; ?>
            </p>

            <div class="t8-chart-shell">
                <canvas id="t8TrendChart"
                        aria-label="Reservation trend for <?= e($trendMonthLabel) ?>"
                        role="img"></canvas>
            </div>
        </div>
    </div>

    <!-- ============================================================
         RESERVATION ACTIVITY (was: Reservation Status)
         ============================================================ -->
    <div class="t8-card t8-reservation-status">
        <div class="t8-card-header">
            <h2 class="t8-card-title">Reservation Activity</h2>
        </div>
        <div class="t8-card-body t8-reservation-body">
            <div class="t8-pie-wrap">
                <?php
                $s1 = $activityPercents['approved'] ?? 0;
                $s2 = $s1 + ($activityPercents['rejected']  ?? 0);
                $s3 = $s2 + ($activityPercents['cancelled'] ?? 0);
                $pieStyle = "background: conic-gradient(#4CAF50 0 {$s1}%, #F44336 {$s1}% {$s2}%, #6c757d {$s2}% {$s3}%, #2196F3 {$s3}% 100%);";
                if ($activityTotal === 0) {
                    $pieStyle = "background: #e9ecef;";
                }
                ?>
                <div class="t8-pie" style="<?= e($pieStyle) ?>">
                    <div class="t8-pie-center"><?= e((string) $activityTotal) ?><br>Total</div>
                </div>
            </div>
            <ul class="t8-legend">
                <li><span class="legend-dot legend-approved"></span> Approved (<?= e((string) $activityCounts['approved']) ?>)</li>
                <li><span class="legend-dot legend-rejected"></span> Rejected (<?= e((string) $activityCounts['rejected']) ?>)</li>
                <li><span class="legend-dot legend-cancelled"></span> Cancelled (<?= e((string) $activityCounts['cancelled']) ?>)</li>
                <li><span class="legend-dot legend-completed"></span> Completed (<?= e((string) $activityCounts['completed']) ?>)</li>
            </ul>
        </div>
    </div>

    <!-- ============================================================
         QUICK INSIGHTS (was: AI Insights)
         ============================================================ -->
    <div class="t8-card t8-ai-insights">
        <div class="t8-card-header"><h2 class="t8-card-title">Quick Insights</h2></div>
        <div class="t8-card-body">
            <ul class="t8-ai-list">
                <li>Pending reservations: <strong><?= e((string) $stats['Pending Reservations']) ?></strong></li>
                <li>Visitors today: <strong><?= e((string) $stats['Visitors Today']) ?></strong></li>
                <li>Active contracts: <strong><?= e((string) $stats['Active Contracts']) ?></strong></li>
                <li>Top facility: <strong><?= e($facilityUsage[0]['label'] ?? '—') ?></strong></li>
                <li>Unread notifications: <strong><?= e((string) $t8UnreadNotifications) ?></strong></li>
            </ul>
        </div>
    </div>

    <!-- ============================================================
         FACILITY UTILISATION
         ============================================================ -->
    <div class="t8-card t8-facility-util">
        <div class="t8-card-header"><h2 class="t8-card-title">Facility Utilization</h2></div>
        <div class="t8-card-body t8-bars">
            <?php if (empty($facilityUsage)): ?>
                <div class="t8-empty">No facility usage data.</div>
            <?php else: ?>
                <?php foreach ($facilityUsage as $f): ?>
                    <?php $w = pctWidth((int) $f['count'], $facilityMax); ?>
                    <div class="t8-bar-row">
                        <span><?= e($f['label']) ?></span>
                        <div class="t8-bar"><div style="width:<?= e($w) ?>"></div></div>
                        <span class="t8-bar-percent"><?= e((string) $f['count']) ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================================
         DOCUMENT CATEGORIES
         ============================================================ -->
    <div class="t8-card t8-doc-categories">
        <div class="t8-card-header"><h2 class="t8-card-title">Document Categories</h2></div>
        <div class="t8-card-body t8-bars">
            <?php if (empty($docCategories)): ?>
                <div class="t8-empty">No documents data.</div>
            <?php else: ?>
                <?php foreach ($docCategories as $d): ?>
                    <?php $w = pctWidth((int) $d['count'], $docMax); ?>
                    <div class="t8-bar-row">
                        <span><?= e($d['label']) ?></span>
                        <div class="t8-bar"><div style="width:<?= e($w) ?>"></div></div>
                        <span class="t8-bar-percent"><?= e((string) $d['count']) ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================================
         RECENT ACTIVITIES
         DASHBOARD UPDATE: capped at 5 meaningful events + a meatballs
         menu opening the full Activity History modal below.
         ============================================================ -->
    <div class="t8-card t8-activity-timeline">
        <div class="t8-card-header">
            <h2 class="t8-card-title">Recent Activities</h2>
            <div class="t8-card-menu-wrap">
                <button type="button" class="t8-card-menu-btn" id="t8ActivityMenuBtn" aria-haspopup="true" aria-expanded="false" aria-label="Recent Activities options">
                    <i class="fa-solid fa-ellipsis-vertical"></i>
                </button>
                <div class="t8-card-menu-dropdown" id="t8ActivityMenuDropdown">
                    <button type="button" id="t8ViewActivityHistory">View Activity History</button>
                </div>
            </div>
        </div>
        <div class="t8-card-body">
            <div class="t8-timeline">
                <?php if ($recentActivities === []): ?>
                    <div class="t8-empty">No recent activity yet.</div>
                <?php else: ?>
                    <?php foreach ($recentActivities as $activity): ?>
                        <?php
                        $action = (string) $activity['action'];
                        $entity = (string) $activity['entity_type'];
                        $description = e((string) $activity['full_name']) . ' ' . e(t8_activity_label($action, $entity));
                        ?>
                        <div class="t8-timeline-item">
                            <div class="t8-timeline-avatar">
                                <i class="fa-solid <?= e($activityIcons[$action] ?? 'fa-user') ?>"></i>
                            </div>
                            <div class="t8-timeline-content">
                                <div class="t8-timeline-desc"><?= $description ?></div>
                                <time class="t8-timeline-time"
                                      datetime="<?= e((string) $activity['created_at']) ?>">
                                    <?= e(format_date((string) $activity['created_at'], 'g:i A')) ?>
                                </time>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div><!-- /.t8-main-grid -->

<!-- ============================================================
     Activity History modal (opened from the meatballs menu above).
     Client-side search + activity-type filter + a "load more" reveal
     of the pre-fetched, capped 200-row $fullActivityHistory list —
     no extra AJAX endpoint needed for this dashboard-scale view.
     ============================================================ -->
<dialog id="t8ActivityHistoryModal" class="t8-activity-modal">
    <div class="t8-activity-modal-header">
        <h2 class="t8-card-title" style="margin:0;">Activity History</h2>
        <button type="button" class="t8-activity-modal-close" id="t8ActivityHistoryClose" aria-label="Close">&times;</button>
    </div>
    <div class="t8-activity-modal-body">
        <div class="t8-activity-filters">
            <input type="text" class="t8-input" id="t8ActivitySearch" placeholder="Search activity…">
            <select class="t8-select" id="t8ActivityTypeFilter">
                <option value="">All activity types</option>
                <?php
                $seenActions = [];
                foreach ($fullActivityHistory as $row) {
                    $seenActions[(string) $row['action']] = true;
                }
                ksort($seenActions);
                foreach (array_keys($seenActions) as $actionKey):
                ?>
                    <option value="<?= e($actionKey) ?>"><?= e(ucfirst(str_replace('_', ' ', $actionKey))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="t8ActivityHistoryList" class="t8-timeline">
            <?php foreach ($fullActivityHistory as $i => $row): ?>
                <?php
                $action = (string) $row['action'];
                $entity = (string) $row['entity_type'];
                $label = t8_activity_label($action, $entity);
                $who = (string) $row['full_name'];
                $when = format_date((string) $row['created_at'], 'M d, Y g:i A');
                ?>
                <div class="t8-timeline-item"
                     data-activity-row
                     data-activity-action="<?= e($action) ?>"
                     data-activity-search="<?= e(strtolower($who . ' ' . $label)) ?>"
                     <?= $i >= 20 ? 'hidden' : '' ?>>
                    <div class="t8-timeline-avatar">
                        <i class="fa-solid <?= e($activityIcons[$action] ?? 'fa-user') ?>"></i>
                    </div>
                    <div class="t8-timeline-content">
                        <div class="t8-timeline-desc"><?= e($who) ?> <?= e($label) ?></div>
                        <time class="t8-timeline-time"><?= e($when) ?></time>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($fullActivityHistory === []): ?>
            <div class="t8-activity-history-empty">No activity has been recorded yet.</div>
        <?php endif; ?>

        <?php if (count($fullActivityHistory) > 20): ?>
            <div class="t8-load-more-wrap">
                <button type="button" class="t8-btn t8-btn-outline t8-btn-sm" id="t8ActivityLoadMore">Load more</button>
            </div>
        <?php endif; ?>
    </div>
</dialog>

<script>
    // Passed to public/js/dashboard.js for Chart.js rendering.
    window.t8TrendData = <?= json_encode(
        ['labels' => $trendLabels, 'data' => $trendValues],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;
</script>
</section>
