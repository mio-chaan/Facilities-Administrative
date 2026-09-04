<?php
/**
 * index.php — Front Controller
 * ---------------------------------------------------------------
 * Every module page is reached through here: index.php?page=reservation
 * Unknown/unlisted pages fall through to a 404 — the route map in
 * app/config/routes.php is a whitelist, not a guess.
 *
 * Flow: bootstrap -> auth check -> resolve route -> role check ->
 *       render (header + navbar + sidebar) -> module content -> footer
 * ---------------------------------------------------------------
 */

declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db_connect.php';
require_once __DIR__ . '/../app/includes/auth_check.php';   // sets up $_SESSION, redirects if unauthenticated
require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/includes/permissions.php';
require_once __DIR__ . '/../app/includes/audit.php';
require_once __DIR__ . '/../app/includes/notifications.php';

// Handle AJAX filter requests before output buffering
if (isset($_GET['ajax_filter']) && $_GET['page'] === 'reservation') {
    header('Content-Type: application/json');
    
    $table_type = (string) ($_GET['table'] ?? 'all');
    $facilityFilter = (int) ($_GET['facility'] ?? 0);
    $typeFilter = trim((string) ($_GET['type'] ?? ''));
    $statusFilter = trim((string) ($_GET['status'] ?? ''));
    
    $currentUserId = t8_current_user_id();
    $isAdmin = t8_has_role('admin');
    
    // Helper functions for rendering
    require_once __DIR__ . '/../app/config/routes.php';
    
    function t8_reservation_summary($reservation) {
        $category = trim((string) ($reservation['event_category'] ?? ''));
        $type = trim((string) ($reservation['facility_type'] ?? ''));
        $detail = '';
        if (in_array($type, ['Equipment', 'Asset'], true) && !empty($reservation['quantity'])) {
            $detail = 'Qty: ' . (int) $reservation['quantity'];
        }
        return ['category' => $category !== '' ? $category : 'Reservation', 'detail' => $detail];
    }
    
    function t8_reservation_schedule($reservation) {
        $start = (string) ($reservation['start_time'] ?? '');
        $end = (string) ($reservation['end_time'] ?? '');
        if ($start !== '' && $end !== '') {
            return [
                'primary' => date('M d, Y', strtotime($start)),
                'secondary' => date('g:i A', strtotime($start)) . ' – ' . date('g:i A', strtotime($end)),
            ];
        }
        $schedule = (string) ($reservation['schedule'] ?? '');
        if ($schedule !== '') {
            return ['primary' => date('M d, Y', strtotime($schedule)), 'secondary' => date('g:i A', strtotime($schedule))];
        }
        $returnDate = (string) ($reservation['expected_return_date'] ?? '');
        if ($returnDate !== '') {
            return ['primary' => 'Return by ' . date('M d, Y', strtotime($returnDate)), 'secondary' => ''];
        }
        return ['primary' => 'N/A', 'secondary' => ''];
    }
    
    // Build base query based on table type
    $baseQuery = "SELECT r.*, f.name AS facility_name, f.location AS facility_location, f.facility_type, u.full_name AS requester_name
                  FROM team8_reservations r
                  JOIN team8_facilities f ON f.id = r.facility_id
                  JOIN users u ON u.id = r.user_id
                  WHERE 1=1";
    $params = [];
    
    // Apply table type filters
    if ($table_type === 'pending' && $isAdmin) {
        $baseQuery .= " AND r.status = 'pending'";
    } elseif ($table_type === 'my') {
        $baseQuery .= " AND r.user_id = :user_id AND r.status = 'approved'";
        $params['user_id'] = $currentUserId;
    } else { // all
        $baseQuery .= " AND r.status IN ('approved', 'cancellation_pending')";
    }
    
    // Apply filter conditions
    if ($facilityFilter > 0) {
        $baseQuery .= " AND r.facility_id = :filter_facility";
        $params['filter_facility'] = $facilityFilter;
    }
    
    if ($typeFilter !== '') {
        $baseQuery .= " AND f.facility_type = :filter_type";
        $params['filter_type'] = $typeFilter;
    }
    
    if ($statusFilter !== '' && in_array($statusFilter, ['approved', 'cancellation_pending'], true)) {
        $baseQuery .= " AND r.status = :filter_status";
        $params['filter_status'] = $statusFilter;
    }
    
    $baseQuery .= " ORDER BY COALESCE(r.start_time, r.schedule, r.expected_return_date) DESC LIMIT 100";
    
    $stmt = $pdo->prepare($baseQuery);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $html = '';
    if (empty($rows)) {
        $html = '<tr><td colspan="10" class="t8-table-empty-row">No reservations match your filters.</td></tr>';
    } else {
        foreach ($rows as $r) {
            $summary = t8_reservation_summary($r);
            $schedule = t8_reservation_schedule($r);
            $statusLabel = ucwords(str_replace('_', ' ', (string) ($r['status'] ?? 'Unknown')));
            
            $html .= '<tr data-reservation-row>'
                . '<td>' . e($r['facility_name']) . '</td>'
                . '<td><span class="t8-type-pill">' . e((string) ($r['facility_type'] ?? 'Unknown')) . '</span></td>'
                . '<td>' . e($r['requester_name']) . '</td>'
                . '<td>' . e((string) ($r['department'] ?? 'N/A')) . '</td>'
                . '<td>' . e((string) ($r['key_person'] ?? 'N/A')) . '</td>'
                . '<td>' . e($summary['category']) . ($summary['detail'] ? ' <small>' . e($summary['detail']) . '</small>' : '') . '</td>'
                . '<td><strong>' . e($schedule['primary']) . '</strong><br><small>' . e($schedule['secondary']) . '</small></td>'
                . '<td>' . e($statusLabel) . '</td>'
                . '<td class="t8-table-actions"><!-- action links here --></td>'
                . '</tr>';
        }
    }
    
    echo json_encode(['html' => $html]);
    exit;
}

// Handle AJAX filter requests for audit logs
if (isset($_GET['ajax_filter']) && $_GET['page'] === 'audit') {
    header('Content-Type: application/json');
    t8_require_role(['admin']);

    $action = trim((string) ($_GET['action'] ?? ''));
    $module = trim((string) ($_GET['module'] ?? ''));
    $pageSize = 10;

    $params = [];
    $where = [];
    if ($action !== '') { $where[] = 'a.action = :action'; $params['action'] = $action; }
    if ($module !== '') { $where[] = 'a.entity_type = :module'; $params['module'] = $module; }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs a $whereSql");
    $countStmt->execute($params);
    $totalLogs = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalLogs / $pageSize));
    $currentPage = min(max(1, (int) ($_GET['audit_page'] ?? 1)), $totalPages);
    $offset = ($currentPage - 1) * $pageSize;

    $stmt = $pdo->prepare("SELECT a.*, u.full_name FROM audit_logs a JOIN users u ON u.id = a.user_id $whereSql ORDER BY a.created_at DESC, a.id DESC LIMIT $pageSize OFFSET $offset");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $actionLabels = [
        '403_denied' => '403 Denied',
        'approve' => 'Approve',
        'cancel' => 'Cancel',
        'cancellation_approved' => 'Cancellation Approved',
        'cancellation_rejected' => 'Cancellation Rejected',
        'cancellation_request' => 'Cancellation Request',
        'check_in' => 'Check In',
        'check_out' => 'Check Out',
        'completed' => 'Completed',
        'create' => 'Create',
        'delete' => 'Delete',
        'expired' => 'Expired',
        'login' => 'Login',
        'logout' => 'Logout',
        'reschedule' => 'Reschedule',
        'schedule' => 'Schedule',
        'toggle_maintenance' => 'Toggle Maintenance',
        'update' => 'Update',
    ];

    $formatActionLabel = static function (string $value) use ($actionLabels): string {
        return $actionLabels[$value] ?? ucwords(str_replace('_', ' ', $value));
    };

    $html = '';
    if (empty($logs)) {
        $html = '<tr class="t8-table-empty-row"><td colspan="6">No matching audit events.</td></tr>';
    } else {
        foreach ($logs as $log) {
            $html .= '<tr>'
                . '<td>' . e($log['full_name']) . '</td>'
                . '<td>' . e($formatActionLabel((string) $log['action'])) . '</td>'
                . '<td>' . e(ucwords(str_replace('_', ' ', $log['entity_type']))) . '</td>'
                . '<td>#' . e((string) $log['entity_id']) . '</td>'
                . '<td>' . e((string) ($log['new_value'] ?? 'Recorded')) . '</td>'
                . '<td>' . e(format_date($log['created_at'], 'M d, Y g:i A')) . '</td>'
                . '</tr>';
        }
    }

    $paginationHtml = '';
    if ($totalPages > 1) {
        ob_start();
        ?>
        <nav id="t8AuditPagination" class="t8-pagination" aria-label="Audit log pages">
            <?php if ($currentPage > 1): ?>
                <a class="t8-btn t8-btn-outline t8-btn-sm t8-audit-page-link" href="<?= e(base_url('index.php?page=audit&action=' . rawurlencode($action) . '&module=' . rawurlencode($module) . '&audit_page=' . ($currentPage - 1))) ?>" data-audit-page="<?= e((string) ($currentPage - 1)) ?>">Previous</a>
            <?php endif; ?>
            <span class="t8-help-text">Page <?= e((string) $currentPage) ?> of <?= e((string) $totalPages) ?></span>
            <?php if ($currentPage < $totalPages): ?>
                <a class="t8-btn t8-btn-outline t8-btn-sm t8-audit-page-link" href="<?= e(base_url('index.php?page=audit&action=' . rawurlencode($action) . '&module=' . rawurlencode($module) . '&audit_page=' . ($currentPage + 1))) ?>" data-audit-page="<?= e((string) ($currentPage + 1)) ?>">Next</a>
            <?php endif; ?>
        </nav>
        <?php
        $paginationHtml = (string) ob_get_clean();
    }

    echo json_encode(['html' => $html, 'pagination_html' => $paginationHtml]);
    exit;
}

ob_start();

// Generic operational alerts contain no record details and are delivered only
// to administrators. User-specific notifications remain owner-scoped.
t8_refresh_operational_notifications($pdo);

$routes = require __DIR__ . '/../app/config/routes.php';

$page = $_GET['page'] ?? 'dashboard';
$t8UnreadNotifications = t8_unread_notification_count($pdo, t8_current_user_id());
// DASHBOARD UPDATE: feeds the navbar bell popover (templates/navbar.php).
$t8RecentNotifications = t8_recent_notifications($pdo, t8_current_user_id(), 8);

$moduleFile = array_key_exists($page, $routes)
    ? dirname(__DIR__) . '/' . $routes[$page]['file']
    : null;

if ($moduleFile === null || !is_file($moduleFile)) {
    http_response_code(404);
    $pageTitle = 'Page Not Found';
    require dirname(__DIR__) . '/templates/header.php';
    require dirname(__DIR__) . '/templates/navbar.php';
    echo '<main class="t8-main t8-main-full">';
    echo '  <div class="t8-alert t8-alert-danger">404 — That page does not exist.</div>';
    echo '  <p><a href="' . e(page_url('dashboard')) . '">Back to dashboard</a></p>';
    echo '</main>';
    require dirname(__DIR__) . '/templates/footer.php';
    ob_end_flush();
    exit;
}

// $pageTitle can be overridden inside the module file before it echoes
// content — templates/header.php reads it.
$pageTitle = ucfirst($page);

require dirname(__DIR__) . '/templates/header.php';
require dirname(__DIR__) . '/templates/navbar.php';
?>
<div class="t8-shell">
    <?php require dirname(__DIR__) . '/templates/sidebar.php'; ?>
    <main class="t8-main">
        <?php
        if (!empty($routes[$page]['roles'])) {
            t8_require_role($routes[$page]['roles']);
        }
        require $moduleFile;
        ?>
    </main>
</div>
<?php
require dirname(__DIR__) . '/templates/footer.php';
ob_end_flush();
