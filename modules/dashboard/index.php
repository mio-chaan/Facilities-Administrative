<?php
/**
 * modules/dashboard/index.php
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
    'login'      => 'fa-right-to-bracket',
    'logout'     => 'fa-right-from-bracket',
    '403_denied' => 'fa-shield-halved',
];
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
// Trend month/year selection — validated server-side so a tampered
// query string never causes a DB error or a weird chart.
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
$reservationByStatus = ['approved' => 0, 'pending' => 0, 'rejected' => 0, 'cancelled' => 0];
$reservationTotal    = 0;
$facilityUsage       = [];
$docCategories       = [];

try {
    // Reservation status breakdown (all-time, for the pie chart).
    $stmt = $pdo->query('SELECT status, COUNT(*) AS cnt FROM team8_reservations GROUP BY status');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $s   = strtolower((string) $row['status']);
        $cnt = (int) $row['cnt'];
        if (array_key_exists($s, $reservationByStatus)) {
            $reservationByStatus[$s] = $cnt;
        }
        $reservationTotal += $cnt;
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
    //
    // Source date: COALESCE(start_time, schedule)
    //   — start_time is set for Room/Area/Asset/Equipment reservations.
    //   — schedule is set for Utility-type reservations that only have
    //     a single point-in-time datetime (no end_time range).
    //   Mirrors the same COALESCE used in t8_reservation_filter_date()
    //   inside modules/reservation/index.php, so the chart and the
    //   list table always agree on which date a reservation belongs to.
    //
    // Status filter: IN ('approved', 'completed')
    //   — 'completed' is the status the reservation list auto-assigns
    //     once a booking's end_time has passed. It is still a real,
    //     confirmed booking that happened on a specific date; dropping
    //     it from the trend just because it ended would silently erase
    //     history from the chart.
    //   — pending / rejected / cancelled are excluded: they were either
    //     never confirmed or were explicitly turned down/withdrawn.
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

// Pie chart percentages (avoid division by zero).
$statusPercents = [];
foreach ($reservationByStatus as $k => $v) {
    $statusPercents[$k] = $reservationTotal > 0 ? round($v / $reservationTotal * 100, 1) : 0;
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
//   labels = day-of-month integers [1, 2, 3 ... 28/29/30/31]
//   data   = reservation count for that calendar day
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

// "X today" badge — only meaningful when viewing the current month.
$isCurrentMonth   = ($trendYear === (int) date('Y') && $trendMonth === (int) date('n'));
$trendTodayCount  = $isCurrentMonth ? ($trendCounts[date('Y-m-d')] ?? 0) : null;

// Month/Year selector options.
$trendMonthNames = [
    1=>'January',2=>'February',3=>'March',4=>'April',
    5=>'May',6=>'June',7=>'July',8=>'August',
    9=>'September',10=>'October',11=>'November',12=>'December',
];
// Show previous year, current year, and next two years — enough to
// cover reservations booked well in advance.
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
            <span class="t8-trend-badge">
                <span class="t8-trend-dot"></span>
                <?php if ($trendTodayCount !== null): ?>
                    <?= e((string) $trendTodayCount) ?> today
                <?php else: ?>
                    <?= e((string) $trendMonthTotal) ?> this month
                <?php endif; ?>
            </span>
        </div>

        <div class="t8-card-body">
            <!-- Month / Year selector -------------------------------->
            <form method="get"
                  action="<?= e(base_url('index.php')) ?>"
                  class="t8-trend-month-select"
                  style="display:flex; gap:8px; align-items:center; margin-bottom:10px; flex-wrap:wrap;">
                <input type="hidden" name="page" value="dashboard">

                <label class="t8-help-text" style="margin:0;" for="t8TrendMonth">Month</label>
                <select class="t8-select"
                        id="t8TrendMonth"
                        name="trend_month"
                        style="width:auto; padding:6px 10px;"
                        onchange="this.form.submit()">
                    <?php foreach ($trendMonthNames as $num => $name): ?>
                        <option value="<?= e((string) $num) ?>"
                            <?= $num === $trendMonth ? 'selected' : '' ?>>
                            <?= e($name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label class="t8-help-text" style="margin:0;" for="t8TrendYear">Year</label>
                <select class="t8-select"
                        id="t8TrendYear"
                        name="trend_year"
                        style="width:auto; padding:6px 10px;"
                        onchange="this.form.submit()">
                    <?php foreach ($trendYearOptions as $yo): ?>
                        <option value="<?= e((string) $yo) ?>"
                            <?= $yo === $trendYear ? 'selected' : '' ?>>
                            <?= e((string) $yo) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <!-- fallback submit for browsers / JS-off environments -->
                <noscript>
                    <button class="t8-btn t8-btn-outline t8-btn-sm" type="submit">View</button>
                </noscript>
            </form>

            <!-- Chart canvas ---------------------------------------->
            <div class="t8-chart-shell">
                <canvas id="t8TrendChart"
                        aria-label="Reservation trend for <?= e($trendMonthLabel) ?>"
                        role="img"></canvas>
            </div>
        </div>
    </div>

    <!-- ============================================================
         RESERVATION STATUS PIE
         ============================================================ -->
    <div class="t8-card t8-reservation-status">
        <div class="t8-card-header"><h2 class="t8-card-title">Reservation Status</h2></div>
        <div class="t8-card-body t8-reservation-body">
            <div class="t8-pie-wrap">
                <?php
                $s1 = $statusPercents['approved'] ?? 0;
                $s2 = $s1 + ($statusPercents['pending']  ?? 0);
                $s3 = $s2 + ($statusPercents['rejected'] ?? 0);
                $pieStyle = "background: conic-gradient(#d9534f 0 {$s1}%, #ffc107 {$s1}% {$s2}%, #6c757d {$s2}% {$s3}%, #e9ecef {$s3}% 100%);";
                ?>
                <div class="t8-pie" style="<?= e($pieStyle) ?>">
                    <div class="t8-pie-center"><?= e((string) $reservationTotal) ?><br>Total</div>
                </div>
            </div>
            <ul class="t8-legend">
                <li><span class="legend-dot legend-approved"></span> Approved (<?= e((string) $reservationByStatus['approved']) ?>)</li>
                <li><span class="legend-dot legend-pending"></span>  Pending  (<?= e((string) $reservationByStatus['pending'])  ?>)</li>
                <li><span class="legend-dot legend-rejected"></span> Rejected (<?= e((string) $reservationByStatus['rejected']) ?>)</li>
                <li><span class="legend-dot legend-cancelled"></span>Cancelled(<?= e((string) ($reservationByStatus['cancelled'] ?? 0)) ?>)</li>
            </ul>
        </div>
    </div>

    <!-- ============================================================
         AI INSIGHTS
         ============================================================ -->
    <div class="t8-card t8-ai-insights">
        <div class="t8-card-header"><h2 class="t8-card-title">AI Insights</h2></div>
        <div class="t8-card-body">
            <ul class="t8-ai-list">
                <li>Pending reservations: <strong><?= e((string) ($reservationByStatus['pending'] ?? 0)) ?></strong></li>
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
         ============================================================ -->
    <div class="t8-card t8-activity-timeline">
        <div class="t8-card-header"><h2 class="t8-card-title">Recent Activities Timeline</h2></div>
        <div class="t8-card-body">
            <div class="t8-timeline">
                <?php if ($recentActivities === []): ?>
                    <div class="t8-empty">No activity has been recorded yet.</div>
                <?php else: ?>
                    <?php foreach ($recentActivities as $activity): ?>
                        <?php
                        $action = (string) $activity['action'];
                        $description = sprintf(
                            '%s %s %s',
                            (string) $activity['full_name'],
                            str_replace('_', ' ', $action),
                            str_replace('_', ' ', (string) $activity['entity_type'])
                        );
                        ?>
                        <div class="t8-timeline-item">
                            <div class="t8-timeline-avatar">
                                <i class="fa-solid <?= e($activityIcons[$action] ?? 'fa-user') ?>"></i>
                            </div>
                            <div class="t8-timeline-content">
                                <div class="t8-timeline-desc"><?= e(ucfirst($description)) ?></div>
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

<script>
    // Passed to public/js/dashboard.js for Chart.js rendering.
    //
    // labels: day-of-month integers for every day in the selected month
    //         (1 … 28/29/30/31) — the X-axis always spans the full
    //         calendar month, including days with 0 reservations.
    //
    // data:   reservation count per day, keyed off each booking's actual
    //         scheduled date (COALESCE(start_time, schedule)) — not
    //         creation date, not end_time.
    window.t8TrendData = <?= json_encode(
        ['labels' => $trendLabels, 'data' => $trendValues],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;
</script>
</section>
