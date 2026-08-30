<?php
/**
 * modules/visitor/index.php
 * Visitor Management - request/scheduling workflow with visitor type
 * classification and generated Visitor IDs.
 *
 * REVISION (professor feedback):
 *   - Visitors are no longer assumed to be walk-ins. A visit can be
 *     SCHEDULED ahead of time (status='scheduled', check_in_time is
 *     NULL until they actually arrive), then checked in later, or
 *     logged as an immediate arrival ("Arriving now" checkbox skips
 *     straight to status='checked_in').
 *   - visitor_type classifies who the visitor is (Supplier,
 *     Maintenance Personnel, Auditor, Barangay Official, Government
 *     Official, Client/Guest, Job Applicant, Other) - a dropdown, per
 *     the automation guidance to minimize free typing.
 *   - Every visit gets a generated, human-readable Visitor ID shown
 *     as "VIS-000123" (derived from the row's own auto-increment id
 *     at display time - nothing extra to store or keep in sync).
 *   - "Currently On-Site" now monitors status='checked_in' rows, and
 *     a new "Scheduled / Upcoming Visits" section lists status=
 *     'scheduled' rows awaiting arrival, with Check In / Cancel.
 *
 * REVISION (equal staff/admin access):
 *   - Previously, visibility/management ($canViewAllVisitors /
 *     $canManageVisits) was restricted to a hardcoded role list
 *     (admin, front_desk, facilities_staff). Any other staff role
 *     fell through to an "own records only" scope, which is why an
 *     admin could see and act on everything while some staff could
 *     not see visitors logged by others (including the admin) and
 *     had no check-out access.
 *   - This module has no role restriction at the route level
 *     (app/config/routes.php has no 'roles' entry for 'visitor'), so
 *     every authenticated user who can reach this page now gets the
 *     SAME permissions here: full visibility of every visitor
 *     (regardless of who logged them), check-in, check-out, cancel,
 *     reschedule, and the full visitor log/stats. There is
 *     intentionally no staff-vs-admin distinction left in this file.
 *
 * Status lifecycle: scheduled -> checked_in -> checked_out
 *                              \-> cancelled
 *
 * Backing table: team8_visitors (see database/visitor_scheduling_fields.sql).
 *
 * CLEANUP: two dead helpers, t8_normalize_ph_contact() and
 * t8_validate_ph_contact(), were defined here but never called — the
 * contact number field is validated inline below with a simple
 * 10-digit regex against a hardcoded +63 prefix. Removed rather than
 * left unreferenced; if richer multi-format PH number support
 * (accepting +63…, 0…, 63…, or bare 10-digit input) is wanted later,
 * reintroduce them and wire the create-form validation to actually
 * call them instead of the inline regex.
 */

declare(strict_types=1);

$pageTitle = 'Visitor Management';
$currentUserId = t8_current_user_id();
$isAdmin = t8_has_role('admin');

// Equal access: every authenticated user reaching this module (the
// 'visitor' route carries no role restriction) sees and manages every
// visitor the same way — no admin-only vs staff-only branch.
$canViewAllVisitors = true;
$canManageVisits = true;

$action = $_GET['action'] ?? 'list';
$errors = [];

// Dropdown options for Visitor Type. Add more here as needed - no
// other code changes required.
const T8_VISITOR_TYPES = [
    'Delivery',
    'Manager',
    'Quality Inspector',
    'Supplier',
    'Maintenance',
    'Official Visitor',
    'Maintenance Personnel',
    'Auditor',
    'Barangay Official',
    'Government Official',
    'Client / Guest',
    'Job Applicant',
    'Other',
];

const T8_VISITOR_TYPE_PURPOSES = [
    'Delivery' => 'Delivery / Delivery of Supplies',
    'Manager' => 'Management Visit',
    'Quality Inspector' => 'Quality Inspection',
    'Supplier' => 'Supplier / Business Transaction',
    'Maintenance' => 'Maintenance / Repair',
    'Official Visitor' => 'Official Business',
    'Maintenance Personnel' => 'Maintenance / Repair',
    'Auditor' => 'Quality Inspection',
    'Barangay Official' => 'Official Business',
    'Government Official' => 'Official Business',
    'Client / Guest' => 'Management Visit',
    'Job Applicant' => 'Job Application',
    'Other' => '',
];

/** Turns a row id into a display-only Visitor ID, e.g. "VIS-000123". */
function t8_visitor_id_label(int $id): string
{
    return 'VIS-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function t8_visitor_pagination(int $page, int $totalPages, string $pageKey): void
{
    if ($totalPages < 2) {
        return;
    }
    echo '<nav class="t8-pagination" aria-label="Visitor pages">';
    if ($page > 1) {
        echo '<a class="t8-btn t8-btn-outline t8-btn-sm" href="' . e(page_url('visitor', [$pageKey => $page - 1])) . '">Previous</a>';
    }
    echo '<span class="t8-help-text">Page ' . e((string) $page) . ' of ' . e((string) $totalPages) . '</span>';
    if ($page < $totalPages) {
        echo '<a class="t8-btn t8-btn-outline t8-btn-sm" href="' . e(page_url('visitor', [$pageKey => $page + 1])) . '">Next</a>';
    }
    echo '</nav>';
}

/** Fetch a single visitor row with the logger's name, or null. */
function t8_visitor_fetch(PDO $pdo, int $id, ?int $ownerId = null): ?array
{
    $sql =
        'SELECT v.*, u.full_name AS logged_by_name
         FROM team8_visitors v
         JOIN users u ON u.id = v.logged_by
         WHERE v.id = :id';
    $params = ['id' => $id];
    if ($ownerId !== null) {
        $sql .= ' AND v.logged_by = :owner_id';
        $params['owner_id'] = $ownerId;
    }
    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * datetime-local inputs submit "Y-m-d\TH:i" (T separator, no seconds).
 * Normalize to "Y-m-d H:i:s" before it reaches a query. Idempotent.
 */
function t8_normalize_datetime(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = str_replace('T', ' ', $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
        $value .= ':00';
    }
    return $value;
}

function t8_visitor_status_badge(string $status): string
{
    $map = [
        'scheduled'   => 't8-badge-pending',
        'checked_in'  => 't8-badge-approved',
        'checked_out' => 't8-badge-archived',
        'cancelled'   => 't8-badge-rejected',
        'expired'     => 't8-badge-rejected',
    ];
    return $map[$status] ?? 't8-badge-pending';
}

$formValues = [
    'full_name'       => '',
    'visitor_type'    => '',
    'contact_suffix'  => '',
    'purpose'         => '',
    'scheduled_date'  => date('Y-m-d\TH:i'), // default to "now" for the form
    'arriving_now'    => '0',
];

/** Expire unclaimed visits once their scheduled check-in time has passed. */
function t8_expire_visitor_bookings(PDO $pdo, int $actorId): void
{
    $ids = $pdo->query("SELECT id FROM team8_visitors WHERE status = 'scheduled' AND scheduled_date < NOW()")
        ->fetchAll(PDO::FETCH_COLUMN);
    if ($ids === []) {
        return;
    }

    $pdo->query("UPDATE team8_visitors SET status = 'expired' WHERE status = 'scheduled' AND scheduled_date < NOW()");
    foreach ($ids as $id) {
        t8_audit_log($pdo, $actorId, 'visitor', (int) $id, 'expired', 'scheduled', 'scheduled date passed');
    }
}

// Run before every action and list query so an expired visit can never be
// accepted by a direct POST or remain presented as available for check-in.
t8_expire_visitor_bookings($pdo, (int) $currentUserId);

switch ($action) {
    case 'create':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $formValues = [
                'full_name'       => trim((string) ($_POST['full_name'] ?? '')),
                'visitor_type'    => (string) ($_POST['visitor_type'] ?? ''),
                'contact_suffix'  => trim((string) ($_POST['contact_suffix'] ?? '')),
                'purpose'         => trim((string) ($_POST['purpose'] ?? '')),
                'scheduled_date'  => t8_normalize_datetime((string) ($_POST['scheduled_date'] ?? '')),
                'arriving_now'    => isset($_POST['arriving_now']) ? '1' : '0',
            ];

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                $mappedPurpose = T8_VISITOR_TYPE_PURPOSES[$formValues['visitor_type']] ?? '';
                if ($mappedPurpose !== '') {
                    $formValues['purpose'] = $mappedPurpose;
                }
                if ($formValues['full_name'] === '') {
                    $errors[] = 'Visitor name is required.';
                }
                if (!in_array($formValues['visitor_type'], T8_VISITOR_TYPES, true)) {
                    $errors[] = 'Please select a visitor type.';
                }
                if ($formValues['purpose'] === '') {
                    $errors[] = 'Purpose of visit is required.';
                }
                $arrivingNow = $formValues['arriving_now'] === '1';
                if (!$arrivingNow && ($formValues['scheduled_date'] === '' || strtotime($formValues['scheduled_date']) === false)) {
                    $errors[] = 'Scheduled date/time must be valid.';
                } elseif (!$arrivingNow && strtotime($formValues['scheduled_date']) <= time()) {
                    $errors[] = 'Scheduled visit date and time must be in the future.';
                }
                if ($formValues['contact_suffix'] !== '' && !preg_match('/^\d{10}$/', $formValues['contact_suffix'])) {
                    $errors[] = 'Contact number must be 10 digits after +63.';
                }

                if (!$errors) {
                    $contact = $formValues['contact_suffix'] !== '' ? '+63' . $formValues['contact_suffix'] : '';
                    $status = $arrivingNow ? 'checked_in' : 'scheduled';
                    $checkInTime = $arrivingNow ? date('Y-m-d H:i:s') : $formValues['scheduled_date'];                    $scheduledDate = $arrivingNow ? date('Y-m-d H:i:s') : $formValues['scheduled_date'];
                    $stmt = $pdo->prepare(
                        'INSERT INTO team8_visitors
                            (full_name, visitor_type, contact, purpose, scheduled_date, status, check_in_time, logged_by)
                         VALUES
                            (:full_name, :visitor_type, :contact, :purpose, :scheduled_date, :status, :check_in_time, :logged_by)'
                    );
                    $stmt->execute([
                        'full_name'       => $formValues['full_name'],
                        'visitor_type'    => $formValues['visitor_type'],
                        'contact'         => $contact !== '' ? $contact : null,
                        'purpose'         => $formValues['purpose'],
                        'scheduled_date'  => $scheduledDate,
                        'status'          => $status,
                        'check_in_time'   => $checkInTime,
                        'logged_by'       => $currentUserId,
                    ]);
                    $newId = (int) $pdo->lastInsertId();
                    t8_audit_log($pdo, $currentUserId, 'visitor', $newId, $arrivingNow ? 'check_in' : 'schedule');
                    t8_flash_set('success', $arrivingNow
                        ? 'Visitor checked in. Visitor ID: ' . t8_visitor_id_label($newId)
                        : 'Visit request scheduled. Visitor ID: ' . t8_visitor_id_label($newId));
                    redirect(page_url('visitor'));
                }
            }
        }
        break;

    case 'checkin':
        // Equal access: check-in is available to any authenticated user
        // who can reach this module — no admin/staff distinction.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('visitor'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('visitor'));
        }
        $id = (int) ($_POST['id'] ?? 0);
        $target = t8_visitor_fetch($pdo, $id);
        if ($target && $target['status'] === 'scheduled' && strtotime((string) $target['scheduled_date']) > time()) {
            $pdo->prepare("UPDATE team8_visitors SET status = 'checked_in', check_in_time = NOW() WHERE id = :id")
                ->execute(['id' => $id]);
            t8_audit_log($pdo, $currentUserId, 'visitor', $id, 'check_in');
            // Keep the request owner informed without exposing visitor details
            // in a broad notification feed.
            $pdo->prepare('INSERT INTO notifications (user_id, message, status) VALUES (:user_id, :message, "unread")')
                ->execute([
                    'user_id' => (int) $target['logged_by'],
                    'message' => 'Your scheduled visitor has arrived and checked in.',
                ]);
            t8_flash_set('success', 'Visitor checked in.');
        } else {
            if ($target && $target['status'] === 'scheduled') {
                $pdo->prepare("UPDATE team8_visitors SET status = 'expired' WHERE id = :id AND status = 'scheduled'")->execute(['id' => $id]);
                t8_audit_log($pdo, $currentUserId, 'visitor', $id, 'expired', 'scheduled', 'scheduled date passed');
                t8_flash_set('danger', 'This visitor booking is no longer valid because the scheduled date has already passed.');
            } else {
                t8_flash_set('danger', 'That visit is not awaiting check-in.');
            }
        }
        redirect(page_url('visitor'));
        break;

    case 'reschedule':
        // Equal access: rescheduling is available to any authenticated
        // user who can reach this module.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('visitor'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('visitor'));
        }
        $id = (int) ($_POST['id'] ?? 0);
        $scheduledDate = t8_normalize_datetime((string) ($_POST['scheduled_date'] ?? ''));
        $target = t8_visitor_fetch($pdo, $id);
        if (!$target || $target['status'] !== 'scheduled') {
            t8_flash_set('danger', 'Only a scheduled visitor booking can be rescheduled.');
        } elseif ($scheduledDate === '' || strtotime($scheduledDate) === false || strtotime($scheduledDate) <= time()) {
            t8_flash_set('danger', 'Scheduled visit date and time must be in the future.');
        } else {
            $pdo->prepare('UPDATE team8_visitors SET scheduled_date = :scheduled_date WHERE id = :id')
                ->execute(['scheduled_date' => $scheduledDate, 'id' => $id]);
            t8_audit_log($pdo, $currentUserId, 'visitor', $id, 'reschedule', (string) $target['scheduled_date'], $scheduledDate);
            t8_flash_set('success', 'Visitor schedule updated.');
        }
        redirect(page_url('visitor'));
        break;

    case 'checkout':
        // Equal access: check-out is available to any authenticated user
        // who can reach this module — this is the action that was
        // previously admin-only in practice for some staff roles.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('visitor'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('visitor'));
        }
        $id = (int) ($_POST['id'] ?? 0);
        $target = t8_visitor_fetch($pdo, $id);
        if ($target && $target['status'] === 'checked_in') {
            $pdo->prepare("UPDATE team8_visitors SET status = 'checked_out', check_out_time = NOW() WHERE id = :id")
                ->execute(['id' => $id]);
            t8_audit_log($pdo, $currentUserId, 'visitor', $id, 'check_out');
            t8_flash_set('success', 'Visitor checked out.');
        } else {
            t8_flash_set('danger', 'That visitor is not currently checked in.');
        }
        redirect(page_url('visitor'));
        break;

    case 'cancel':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('visitor'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('visitor'));
        }
        $id = (int) ($_POST['id'] ?? 0);
        // Equal access: any authenticated user may cancel any scheduled
        // visit, not just the one they personally logged.
        $target = t8_visitor_fetch($pdo, $id);
        if ($target && $target['status'] === 'scheduled') {
            $pdo->prepare("UPDATE team8_visitors SET status = 'cancelled' WHERE id = :id")->execute(['id' => $id]);
            t8_audit_log($pdo, $currentUserId, 'visitor', $id, 'cancel');
            t8_flash_set('success', 'Scheduled visit cancelled.');
        } else {
            t8_flash_set('danger', "Only a scheduled visit can be cancelled.");
        }
        redirect(page_url('visitor'));
        break;
}

$showForm = $action === 'create';

if (!$showForm) {
    // Equal access: no owner-scoping — everyone sees every visitor
    // record in every list/table below, regardless of who logged it.
    $visitorPageSize = 5;
    $scheduledTotalStmt = $pdo->query("SELECT COUNT(*) FROM team8_visitors v WHERE v.status = 'scheduled'");
    $scheduledTotalPages = max(1, (int) ceil((int) $scheduledTotalStmt->fetchColumn() / $visitorPageSize));
    $scheduledPage = min(max(1, (int) ($_GET['scheduled_page'] ?? 1)), $scheduledTotalPages);
    $scheduledStmt = $pdo->prepare(
        "SELECT v.*, u.full_name AS logged_by_name
         FROM team8_visitors v
         JOIN users u ON u.id = v.logged_by
         WHERE v.status = 'scheduled'
         ORDER BY v.scheduled_date ASC, v.id ASC
         LIMIT {$visitorPageSize} OFFSET " . (($scheduledPage - 1) * $visitorPageSize)
    );
    $scheduledStmt->execute();
    $scheduledVisits = $scheduledStmt->fetchAll(PDO::FETCH_ASSOC);

    $currentlyInTotalStmt = $pdo->query("SELECT COUNT(*) FROM team8_visitors v WHERE v.status = 'checked_in'");
    $currentlyInTotalPages = max(1, (int) ceil((int) $currentlyInTotalStmt->fetchColumn() / $visitorPageSize));
    $currentlyInPage = min(max(1, (int) ($_GET['onsite_page'] ?? 1)), $currentlyInTotalPages);
    $currentlyInStmt = $pdo->prepare(
        "SELECT v.*, u.full_name AS logged_by_name
         FROM team8_visitors v
         JOIN users u ON u.id = v.logged_by
         WHERE v.status = 'checked_in'
         ORDER BY v.check_in_time ASC, v.id ASC
         LIMIT {$visitorPageSize} OFFSET " . (($currentlyInPage - 1) * $visitorPageSize)
    );
    $currentlyInStmt->execute();
    $currentlyIn = $currentlyInStmt->fetchAll(PDO::FETCH_ASSOC);

    $allVisitorsTotalStmt = $pdo->query('SELECT COUNT(*) FROM team8_visitors v WHERE 1=1');
    $allVisitorsTotalPages = max(1, (int) ceil((int) $allVisitorsTotalStmt->fetchColumn() / $visitorPageSize));
    $allVisitorsPage = min(max(1, (int) ($_GET['log_page'] ?? 1)), $allVisitorsTotalPages);
    $allVisitorsStmt = $pdo->prepare(
        'SELECT v.*, u.full_name AS logged_by_name
         FROM team8_visitors v
         JOIN users u ON u.id = v.logged_by
         WHERE 1=1
         ORDER BY v.created_at DESC, v.id DESC
         LIMIT ' . $visitorPageSize . ' OFFSET ' . (($allVisitorsPage - 1) * $visitorPageSize)
    );
    $allVisitorsStmt->execute();
    $allVisitors = $allVisitorsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Equal access: the summary stats / KPI cards are computed and
    // shown for everyone now, not only for admin.
    $visitorStats = [];
    $visitorStatMeta = [
        'Visitors Today' => ['icon' => 'fa-users', 'variant' => 't8-visitor-icon-danger'],
        'Scheduled Visitors' => ['icon' => 'fa-calendar-days', 'variant' => 't8-visitor-icon-warning'],
        'Currently On-Site' => ['icon' => 'fa-user-check', 'variant' => 't8-visitor-icon-success'],
        'Checked-Out' => ['icon' => 'fa-door-open', 'variant' => 't8-visitor-icon-info'],
        'Overdue Visitors' => ['icon' => 'fa-clock', 'variant' => 't8-visitor-icon-purple'],
    ];
    $visitorStats = [
        'Visitors Today' => (int) $pdo->query('SELECT COUNT(*) FROM team8_visitors WHERE DATE(scheduled_date) = CURDATE()')->fetchColumn(),
        'Scheduled Visitors' => (int) $pdo->query("SELECT COUNT(*) FROM team8_visitors WHERE status = 'scheduled'")->fetchColumn(),
        'Currently On-Site' => (int) $pdo->query("SELECT COUNT(*) FROM team8_visitors WHERE status = 'checked_in'")->fetchColumn(),
        'Checked-Out' => (int) $pdo->query("SELECT COUNT(*) FROM team8_visitors WHERE status = 'checked_out' AND DATE(check_out_time) = CURDATE()")->fetchColumn(),
        'Overdue Visitors' => (int) $pdo->query("SELECT COUNT(*) FROM team8_visitors WHERE status IN ('scheduled', 'checked_in') AND scheduled_date < NOW() - INTERVAL 1 DAY")->fetchColumn(),
    ];
    // Visitor requests currently do not have a separate approval state;
    // keep these explicit until that workflow is introduced.
    $pendingVisitRequests = 0;
    $visitorsRequiringAttention = 0;
}
?>
<div class="t8-visitor-heading">
    <div>
        <h1>Visitor Management</h1>
        <p class="t8-help-text">Schedule visit requests, check visitors in on arrival, and monitor who is currently on-site.</p>
    </div>
    <?php if (!$showForm): ?>
        <a class="t8-btn t8-btn-accent" href="<?= e(page_url('visitor', ['action' => 'create'])) ?>">
            <i class="fa-solid fa-user-plus"></i> New Visit Request
        </a>
    <?php endif; ?>
</div>

<?php if (!$showForm): ?>
    <div class="t8-visitor-stat-grid" aria-label="Visitor summary statistics">
        <?php foreach ($visitorStats as $label => $count): ?>
            <?php $meta = $visitorStatMeta[$label] ?? ['icon' => 'fa-chart-simple', 'variant' => '']; ?>
            <section class="t8-card t8-visitor-stat-card">
                <div class="t8-visitor-stat-icon <?= e($meta['variant']) ?>" aria-hidden="true">
                    <i class="fa-solid <?= e($meta['icon']) ?>"></i>
                </div>
                <div class="t8-visitor-stat-body">
                    <p class="t8-help-text"><?= e($label) ?></p>
                    <div class="t8-visitor-stat-value"><?= e((string) $count) ?></div>
                </div>
            </section>
        <?php endforeach; ?>
        <section class="t8-card t8-visitor-pending-card">
            <div class="t8-card-header"><h2 class="t8-card-title">Pending Actions</h2></div>
            <div class="t8-card-body">
                <p><strong><?= e((string) $pendingVisitRequests) ?></strong> visit requests awaiting approval</p>
                <p><strong><?= e((string) $visitorsRequiringAttention) ?></strong> visitors requiring attention</p>
                <p class="t8-visitor-pending-links">
                    <a href="#scheduled-visits">Review visit requests</a>
                    <span aria-hidden="true">·</span>
                    <a href="#visitor-log">View visitor logs</a>
                </p>
            </div>
        </section>
    </div>
<?php endif; ?>

<?php if ($showForm): ?>

    <?php foreach ($errors as $error): ?>
        <div class="t8-alert t8-alert-danger"><?= e($error) ?></div>
    <?php endforeach; ?>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title">New Visit Request</h2>
        </div>

        <form method="post" action="<?= e(page_url('visitor', ['action' => 'create'])) ?>" novalidate>
            <?= t8_csrf_field() ?>

            <div class="t8-field">
                <label class="t8-label" for="full_name">Visitor Name</label>
                <input class="t8-input" type="text" id="full_name" name="full_name"
                       value="<?= e($formValues['full_name']) ?>" required>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="visitor_type">Visitor Type</label>
                <select class="t8-select" id="visitor_type" name="visitor_type" required data-purpose-map="<?= e((string) json_encode(T8_VISITOR_TYPE_PURPOSES)) ?>">
                    <option value="">Select a type…</option>
                    <?php foreach (T8_VISITOR_TYPES as $type): ?>
                        <option value="<?= e($type) ?>" <?= $type === $formValues['visitor_type'] ? 'selected' : '' ?>><?= e($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="contact_suffix">Contact Number</label>
                <div style="display: flex; gap: 8px; align-items: center;">
    <span style="padding: 12px 14px; background: var(--t8-secondary); border: 1.5px solid var(--t8-border); border-radius: var(--t8-radius-sm) 0 0 var(--t8-radius-sm);">
        +63
    </span>

    <input
        class="t8-input"
        type="tel"
        inputmode="numeric"
        id="contact_suffix"
        name="contact_suffix"
        value="<?= e($formValues['contact_suffix']) ?>"
        maxlength="10"
        placeholder="9123456789"
        pattern="[0-9]{10}"
        title="Enter 10 digits after +63"
        oninput="this.value = this.value.replace(/[^0-9]/g, '')"
        style="border-radius: 0 var(--t8-radius-sm) var(--t8-radius-sm) 0; flex: 1;"
    >
</div>

                <span class="t8-help-text">Optional. Enter exactly 10 digits after +63, e.g. 9123456789.</span>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="purpose">Purpose of Visit</label>
                <input class="t8-input" type="text" id="purpose" name="purpose"
                       value="<?= e($formValues['purpose']) ?>" required>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="scheduled_date">Scheduled Date &amp; Time</label>
                <input class="t8-input t8-datetime-input" type="datetime-local" id="scheduled_date" name="scheduled_date"
                       value="<?= e(str_replace(' ', 'T', substr($formValues['scheduled_date'], 0, 16))) ?>"
                       min="<?= e(date('Y-m-d\\TH:i')) ?>"
                       onclick="this.showPicker && this.showPicker();" required>
                <span class="t8-help-text">When the visitor is expected to arrive.</span>
            </div>

            <div class="t8-field">
                <label class="t8-label" style="display:flex; align-items:center; gap:8px; font-weight:500;">
                    <input type="checkbox" id="arriving_now" name="arriving_now" value="1"
                           onchange="document.getElementById('scheduled_date').disabled = this.checked;">
                    Visitor is arriving right now (check in immediately instead of scheduling)
                </label>
            </div>

            <button class="t8-btn t8-btn-accent" type="submit">
                <i class="fa-solid fa-check"></i> Submit
            </button>
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('visitor')) ?>">Cancel</a>
        </form>
    </div>

<?php else: ?>

    <div class="t8-card" id="scheduled-visits">
        <div class="t8-card-header">
            <h2 class="t8-card-title">Scheduled / Upcoming Visits</h2>
            <?php if ($scheduledVisits !== []): ?>
                <span class="t8-notification-count"><?= e((string) count($scheduledVisits)) ?> scheduled</span>
            <?php endif; ?>
        </div>
        <div class="t8-table-wrap">
            <table class="t8-table">
                <thead>
                    <tr>
                        <th>Visitor ID</th>
                        <th>Visitor</th>
                        <th>Type</th>
                        <th>Purpose</th>
                        <th>Scheduled For</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($scheduledVisits === []): ?>
                        <tr class="t8-table-empty-row">
                            <td colspan="6">No visits are currently scheduled.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($scheduledVisits as $v): ?>
                            <tr>
                                <td class="t8-table-ref"><?= e(t8_visitor_id_label((int) $v['id'])) ?></td>
                                <td><?= e($v['full_name']) ?></td>
                                <td><?= e((string) ($v['visitor_type'] ?? '—')) ?></td>
                                <td><?= e($v['purpose']) ?></td>
                                <td><?= e(format_date($v['scheduled_date'], 'M d, Y g:i A')) ?></td>
                                <td>
                                    <div class="t8-visitor-inline-actions">
                                        <form method="post" action="<?= e(page_url('visitor', ['action' => 'checkin'])) ?>">
                                            <?= t8_csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $v['id']) ?>">
                                            <button class="t8-btn t8-btn-success t8-btn-sm" type="submit">
                                                <i class="fa-solid fa-right-to-bracket"></i> Check In
                                            </button>
                                        </form>

                                        <details class="t8-visitor-menu">
                                            <summary class="t8-btn t8-btn-outline t8-btn-sm" aria-label="More actions">
                                                <i class="fa-solid fa-ellipsis-vertical"></i>
                                            </summary>
                                            <div class="t8-visitor-menu-panel">
                                                <form method="post" action="<?= e(page_url('visitor', ['action' => 'reschedule'])) ?>">
                                                    <?= t8_csrf_field() ?>
                                                    <input type="hidden" name="id" value="<?= e((string) $v['id']) ?>">
                                                    <label class="t8-help-text" for="reschedule-<?= e((string) $v['id']) ?>">New schedule</label>
                                                    <input id="reschedule-<?= e((string) $v['id']) ?>" class="t8-input" type="datetime-local" name="scheduled_date"
                                                           min="<?= e(date('Y-m-d\TH:i')) ?>"
                                                           value="<?= e(str_replace(' ', 'T', substr((string) $v['scheduled_date'], 0, 16))) ?>" required>
                                                    <button class="t8-btn t8-btn-outline t8-btn-sm" type="submit">
                                                        <i class="fa-solid fa-calendar-pen"></i> Change Schedule
                                                    </button>
                                                </form>

                                                <form method="post" action="<?= e(page_url('visitor', ['action' => 'cancel'])) ?>"
                                                      onsubmit="return confirm('Cancel this scheduled visit?');">
                                                    <?= t8_csrf_field() ?>
                                                    <input type="hidden" name="id" value="<?= e((string) $v['id']) ?>">
                                                    <button class="t8-btn t8-btn-danger t8-btn-sm t8-visitor-menu-cancel" type="submit">
                                                        <i class="fa-solid fa-xmark"></i> Cancel Visit
                                                    </button>
                                                </form>
                                            </div>
                                        </details>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
            <?php t8_visitor_pagination($scheduledPage, $scheduledTotalPages, 'scheduled_page'); ?>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title">Currently On-Site</h2>
            <?php if ($currentlyIn !== []): ?>
                <span class="t8-notification-count"><?= e((string) count($currentlyIn)) ?> checked in</span>
            <?php endif; ?>
        </div>
        <div class="t8-table-wrap">
            <table class="t8-table">
                <thead>
                    <tr>
                        <th>Visitor ID</th>
                        <th>Visitor</th>
                        <th>Type</th>
                        <th>Purpose</th>
                        <th>Check-In Time</th>
                        <th>Logged By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($currentlyIn === []): ?>
                        <tr class="t8-table-empty-row">
                            <td colspan="7">No visitors currently checked in.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($currentlyIn as $v): ?>
                            <tr>
                                <td class="t8-table-ref"><?= e(t8_visitor_id_label((int) $v['id'])) ?></td>
                                <td><?= e($v['full_name']) ?></td>
                                <td><?= e((string) ($v['visitor_type'] ?? '—')) ?></td>
                                <td><?= e($v['purpose']) ?></td>
                                <td><?= e(format_date($v['check_in_time'], 'M d, Y g:i A')) ?></td>
                                <td><?= e($v['logged_by_name']) ?></td>
                                <td>
                                    <form method="post" action="<?= e(page_url('visitor', ['action' => 'checkout'])) ?>"
                                          onsubmit="return confirm('Check out this visitor?');">
                                        <?= t8_csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= e((string) $v['id']) ?>">
                                        <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit">
                                            <i class="fa-solid fa-right-from-bracket"></i> Check Out
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
            <?php t8_visitor_pagination($currentlyInPage, $currentlyInTotalPages, 'onsite_page'); ?>
    </div>

    <div class="t8-card" id="visitor-log">
        <div class="t8-card-header">
            <h2 class="t8-card-title">Visitor Logs</h2>
        </div>
        <div class="t8-table-wrap">
            <table class="t8-table">
                <thead>
                    <tr>
                        <th>Visitor ID</th>
                        <th>Visitor</th>
                        <th>Type</th>
                        <th>Purpose</th>
                        <th>Scheduled For</th>
                        <th>Check-In</th>
                        <th>Check-Out</th>
                        <th>Status</th>
                        <th>Logged By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($allVisitors === []): ?>
                        <tr class="t8-table-empty-row">
                            <td colspan="9">No visitors have been logged yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($allVisitors as $v): ?>
                            <tr>
                                <td class="t8-table-ref"><?= e(t8_visitor_id_label((int) $v['id'])) ?></td>
                                <td><?= e($v['full_name']) ?></td>
                                <td><?= e((string) ($v['visitor_type'] ?? '—')) ?></td>
                                <td><?= e($v['purpose']) ?></td>
                                <td><?= $v['scheduled_date'] ? e(format_date($v['scheduled_date'], 'M d, Y g:i A')) : '—' ?></td>
                                <td><?= $v['check_in_time'] ? e(format_date($v['check_in_time'], 'M d, Y g:i A')) : '—' ?></td>
                                <td><?= $v['check_out_time'] ? e(format_date($v['check_out_time'], 'M d, Y g:i A')) : '—' ?></td>
                                <td>
                                    <span class="t8-badge <?= t8_visitor_status_badge($v['status']) ?>">
                                        <?= e(ucwords(str_replace('_', ' ', (string) $v['status']))) ?>
                                    </span>
                                </td>
                                <td><?= e($v['logged_by_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
            <?php t8_visitor_pagination($allVisitorsPage, $allVisitorsTotalPages, 'log_page'); ?>
    </div>

<?php endif; ?>
