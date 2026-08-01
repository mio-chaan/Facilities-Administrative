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
// Build additional dashboard data from the database. Use fail-safe fallbacks
// so a fresh clone without the schema won't break the page.
$reservationByStatus = ['approved' => 0, 'pending' => 0, 'rejected' => 0, 'cancelled' => 0];
$reservationTotal = 0;
$facilityUsage = [];
$docCategories = [];
$trendCounts = [];

try {
    $stmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM team8_reservations GROUP BY status");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $s = strtolower((string) $row['status']);
        $cnt = (int) $row['cnt'];
        if (array_key_exists($s, $reservationByStatus)) {
            $reservationByStatus[$s] = $cnt;
        } else {
            $reservationByStatus[$s] = $cnt;
        }
        $reservationTotal += $cnt;
    }

    // Facility usage: attempt to group by facility_id and resolve names if possible.
    $facStmt = $pdo->query("SELECT facility_id, COUNT(*) AS cnt FROM team8_reservations GROUP BY facility_id ORDER BY cnt DESC LIMIT 5");
    $facRows = $facStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($facRows !== false) {
        $getName = $pdo->prepare("SELECT name FROM team8_facilities WHERE id = :id LIMIT 1");
        foreach ($facRows as $r) {
            $id = $r['facility_id'];
            $cnt = (int) $r['cnt'];
            $name = null;
            if ($id !== null) {
                try {
                    $getName->execute(['id' => $id]);
                    $name = $getName->fetchColumn();
                } catch (PDOException $e) {
                    $name = null;
                }
            }
            $label = $name ?: ($id !== null ? "Facility #{$id}" : 'Unknown');
            $facilityUsage[] = ['label' => $label, 'count' => $cnt];
        }
    }

    // Trend: counts for the last 30 days, including dates with zero reservations
    $trendStart = new DateTimeImmutable('today');
    $trendStart = $trendStart->sub(new DateInterval('P29D'));
    $trendEnd = new DateTimeImmutable('today');

    $current = $trendStart;
    while ($current <= $trendEnd) {
        $trendCounts[$current->format('Y-m-d')] = 0;
        $current = $current->add(new DateInterval('P1D'));
    }

    $trendStmt = $pdo->prepare(
        'SELECT DATE(start_time) AS d, COUNT(*) AS cnt
         FROM team8_reservations
         WHERE DATE(start_time) BETWEEN :start_date AND :end_date
         GROUP BY d
         ORDER BY d ASC'
    );
    $trendStmt->execute([
        'start_date' => $trendStart->format('Y-m-d'),
        'end_date' => $trendEnd->format('Y-m-d'),
    ]);
    foreach ($trendStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $trendCounts[(string) $r['d']] = (int) $r['cnt'];
    }
} catch (PDOException $e) {
    echo '<div class="t8-alert t8-alert-warning">Could not load some dashboard data - has database/schema.sql been imported yet?</div>';
}

// Compute percentages for reservation status (avoid division by zero)
$statusPercents = [];
if ($reservationTotal > 0) {
    foreach ($reservationByStatus as $k => $v) {
        $statusPercents[$k] = round($v / $reservationTotal * 100, 1);
    }
} else {
    foreach ($reservationByStatus as $k => $v) { $statusPercents[$k] = 0; }
}

// Helper to render bar width safely
function pctWidth(int $value, int $max): string {
    if ($max <= 0) return '0%';
    $w = round($value / $max * 100, 1);
    return $w . '%';
}

// Prepare facility usage percentages (relative to top value)
$facilityMax = 0;
foreach ($facilityUsage as $f) { $facilityMax = max($facilityMax, (int) $f['count']); }

// For document categories, compute a simple width relative to max count
$docMax = 0;
foreach ($docCategories as $d) { $docMax = max($docMax, (int) $d['count']); }

// Build trend SVG points from $trendCounts — normalize into 600x180 viewport
$trendPoints = [];
$trendMax = 0;
if (!empty($trendCounts)) {
    $trendMax = max($trendCounts);
    $widthStep = 600 / max(1, count($trendCounts) - 1);
    $i = 0;
    foreach ($trendCounts as $date => $cnt) {
        $x = round(10 + $i * $widthStep);
        $y = 180 - ($trendMax > 0 ? round($cnt / $trendMax * 150) : 0);
        $trendPoints[] = ['x' => $x, 'y' => $y, 'date' => $date, 'day' => (int) (new DateTimeImmutable($date))->format('j'), 'count' => $cnt];
        $i++;
    }
}

// Helper to convert points array to svg polyline string (supports both formats)
function pointsToSvg(array $pts): string {
    return implode(' ', array_map(function($p){
        if (isset($p[0]) && isset($p[1])) return $p[0] . ',' . $p[1];
        return ($p['x'] ?? 0) . ',' . ($p['y'] ?? 0);
    }, $pts));
}

?>

<div class="t8-main-grid">
    <div class="t8-card t8-trend-card">
        <div class="t8-card-header"><h2 class="t8-card-title">Monthly Reservation Trend</h2></div>
        <div class="t8-card-body">
            <div class="t8-chart t8-chart-line">
                <?php if (!empty($trendPoints)): ?>
                    <?php
                        $gridCount = 4;
                        $gridColor = '#E5E7EB';
                        $showDayNumbers = ((int) date('t') === 31);
                        $pointCount = count($trendPoints);
                        $labelStep = $pointCount > 0 ? max(1, (int) floor($pointCount / 6)) : 1;
                    ?>
                    <svg viewBox="0 0 600 200" preserveAspectRatio="none" class="t8-sparkline">
                        <defs>
                            <linearGradient id="g1" x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0%" stop-color="rgba(255,99,71,0.14)" />
                                <stop offset="100%" stop-color="transparent" />
                            </linearGradient>
                        </defs>
                        <!-- Horizontal grid lines -->
                        <?php for ($g = 0; $g <= $gridCount; $g++):
                            $gy = 30 + ($g * (150 / $gridCount));
                            $labelVal = (int) round($trendMax * (1 - ($g / $gridCount)));
                        ?>
                            <line x1="10" y1="<?= $gy ?>" x2="610" y2="<?= $gy ?>" stroke="<?= $gridColor ?>" stroke-width="1" stroke-dasharray="4 3" />
                            <text x="6" y="<?= (int)($gy + 4) ?>" fill="#6b7280" font-size="11" text-anchor="start"><?= e((string) $labelVal) ?></text>
                        <?php endfor; ?>

                        <!-- Y axis label -->
                        <text x="12" y="100" fill="#374151" font-size="12" transform="rotate(-90 12 100)" text-anchor="middle">Reservation Count</text>

                        <!-- X axis day labels (only shown when current month has 31 days) -->
                        <?php if ($showDayNumbers): ?>
                            <?php foreach ($trendPoints as $idx => $p): if ($idx % $labelStep !== 0) continue; ?>
                                <text x="<?= (int) $p['x'] ?>" y="195" fill="#6b7280" font-size="11" text-anchor="middle"><?= e((string) $p['day']) ?></text>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Trend area and line -->
                        <polyline fill="url(#g1)" points="<?= e(pointsToSvg($trendPoints)) ?>" stroke="none" />
                        <polyline fill="none" stroke="#d9534f" stroke-width="3" points="<?= e(pointsToSvg($trendPoints)) ?>" />
                    </svg>
                <?php else: ?>
                    <div class="t8-empty">No trend data available.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="t8-card t8-reservation-status">
        <div class="t8-card-header"><h2 class="t8-card-title">Reservation Status</h2></div>
        <div class="t8-card-body t8-reservation-body">
            <div class="t8-pie-wrap">
                <?php
                $s1 = $statusPercents['approved'] ?? 0;
                $s2 = ($s1 + ($statusPercents['pending'] ?? 0));
                $s3 = ($s2 + ($statusPercents['rejected'] ?? 0));
                $pieStyle = "background: conic-gradient(#d9534f 0 {$s1}%, #ffc107 {$s1}% {$s2}%, #6c757d {$s2}% {$s3}%, #e9ecef {$s3}% 100%);";
                ?>
                <div class="t8-pie" style="<?= e($pieStyle) ?>">
                    <div class="t8-pie-center"><?= e((string) $reservationTotal) ?><br>Total</div>
                </div>
            </div>
            <ul class="t8-legend">
                <li><span class="legend-dot legend-approved"></span> Approved (<?= e((string) $reservationByStatus['approved']) ?>)</li>
                <li><span class="legend-dot legend-pending"></span> Pending (<?= e((string) $reservationByStatus['pending']) ?>)</li>
                <li><span class="legend-dot legend-rejected"></span> Rejected (<?= e((string) $reservationByStatus['rejected']) ?>)</li>
                <li><span class="legend-dot legend-cancelled"></span> Cancelled (<?= e((string) ($reservationByStatus['cancelled'] ?? 0)) ?>)</li>
            </ul>
        </div>
    </div>

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

    <div class="t8-card t8-facility-util">
        <div class="t8-card-header"><h2 class="t8-card-title">Facility Utilization</h2></div>
        <div class="t8-card-body t8-bars">
            <?php if (empty($facilityUsage)): ?>
                <div class="t8-empty">No facility usage data.</div>
            <?php else: ?>
                <?php foreach ($facilityUsage as $f): ?>
                    <?php $w = pctWidth((int)$f['count'], $facilityMax); ?>
                    <div class="t8-bar-row"><span><?= e($f['label']) ?></span><div class="t8-bar"><div style="width:<?= e($w) ?>"></div></div><span class="t8-bar-percent"><?= e((string) $f['count']) ?></span></div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="t8-card t8-doc-categories">
        <div class="t8-card-header"><h2 class="t8-card-title">Document Categories</h2></div>
        <div class="t8-card-body t8-bars">
            <?php if (empty($docCategories)): ?>
                <div class="t8-empty">No documents data.</div>
            <?php else: ?>
                <?php foreach ($docCategories as $d): ?>
                    <?php $w = pctWidth((int)$d['count'], $docMax); ?>
                    <div class="t8-bar-row"><span><?= e($d['label']) ?></span><div class="t8-bar"><div style="width:<?= e($w) ?>"></div></div><span class="t8-bar-percent"><?= e((string) $d['count']) ?></span></div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

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
                        $description = sprintf('%s %s %s', (string) $activity['full_name'], str_replace('_', ' ', $action), str_replace('_', ' ', (string) $activity['entity_type']));
                        ?>
                        <div class="t8-timeline-item">
                            <div class="t8-timeline-avatar"><i class="fa-solid <?= e($activityIcons[$action] ?? 'fa-user') ?>"></i></div>
                            <div class="t8-timeline-content">
                                <div class="t8-timeline-desc"><?= e(ucfirst($description)) ?></div>
                                <time class="t8-timeline-time" datetime="<?= e((string) $activity['created_at']) ?>"><?= e(format_date((string) $activity['created_at'], 'g:i A')) ?></time>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</section>
