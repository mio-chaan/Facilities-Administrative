<?php
/**
 * modules/reservation/index.php
 * Milestone 3 - Facilities Reservation.
 *
 * Workflow:
 *   Facilities Staff creates a reservation -> status = 'pending'
 *   Administrator approves/rejects -> status = 'approved'/'rejected'
 *   Administrator-created reservations skip Pending and are recorded
 *   as auto-approved (still logged as a single approval step, below).
 *
 * Facilities Staff may edit or cancel ONLY their own reservation, and
 * ONLY while it's still 'pending'. Administrator's only lever over
 * someone else's request is Approve/Reject - editing/cancelling stays
 * exclusive to the original requester by design decision.
 *
 * Approvals are recorded in team8_reservation_approvals as a single
 * row per reservation (step_order = 1, approver = the Administrator
 * who decided) - kept as one row per decision by design, even though
 * the table's shape supports multi-step chains, to stay aligned with
 * the schema instead of leaving the table unused.
 *
 * Backing tables: team8_facilities (status='active' only, managed via
 * modules/facilities/), team8_reservations, team8_reservation_approvals.
 * team8_equipment / team8_reservation_equipment are intentionally not
 * used here - Equipment Management is out of scope for this iteration.
 */

declare(strict_types=1);

$pageTitle = 'Facilities Reservation';
$currentUserId = t8_current_user_id();
$isAdmin = t8_has_role('admin');
$action = $_GET['action'] ?? 'list';
$errors = [];

/** Fetch a single reservation with its facility/requester names, or null. */
function t8_reservation_fetch(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT r.*, f.name AS facility_name, f.location AS facility_location, u.full_name AS requester_name
         FROM team8_reservations r
         JOIN team8_facilities f ON f.id = r.facility_id
         JOIN users u ON u.id = r.user_id
         WHERE r.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** True if an APPROVED reservation already occupies this facility/time range. */
function t8_reservation_has_conflict(PDO $pdo, int $facilityId, string $start, string $end, ?int $excludeId = null): bool
{
    $sql = "SELECT COUNT(*) FROM team8_reservations
            WHERE facility_id = :facility_id AND status = 'approved'
              AND start_time < :end_time AND end_time > :start_time";
    $params = ['facility_id' => $facilityId, 'start_time' => $start, 'end_time' => $end];
    if ($excludeId !== null) {
        $sql .= ' AND id != :exclude_id';
        $params['exclude_id'] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

function t8_reservation_has_time_conflict(PDO $pdo, int $facilityId, string $start, string $end, ?int $excludeId = null): bool
{
    $sql = "SELECT COUNT(*) FROM team8_reservations
            WHERE facility_id = :facility_id
              AND status IN ('pending', 'approved')
              AND deleted_at IS NULL
              AND start_time < :end_time AND end_time > :start_time";
    $params = ['facility_id' => $facilityId, 'start_time' => $start, 'end_time' => $end];
    if ($excludeId !== null) {
        $sql .= ' AND id != :exclude_id';
        $params['exclude_id'] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

function t8_reservation_display_status(array $reservation): string
{
    if ($reservation['status'] === 'approved' && strtotime($reservation['end_time']) <= time()) {
        return 'completed';
    }
    return $reservation['status'];
}

/**
 * datetime-local inputs submit "Y-m-d\TH:i" (T separator, no seconds).
 * MySQL's strict-mode DATETIME literal parsing rejects that shape, so
 * normalize to "Y-m-d H:i:s" before it ever reaches a query. Safe to
 * call on an already-normalized value too (idempotent).
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

/** Shared create/edit field validation. Returns an errors array. */
function t8_reservation_validate(array $activeFacilities, int $facilityId, string $start, string $end): array
{
    $errors = [];
    $validFacility = false;
    foreach ($activeFacilities as $f) {
        if ((int) $f['id'] === $facilityId) {
            $validFacility = true;
            break;
        }
    }
    if (!$validFacility) {
        $errors[] = 'Please select a valid, active facility.';
    }
    if ($start === '' || $end === '') {
        $errors[] = 'Start and end time are both required.';
    } elseif (strtotime($start) === false || strtotime($end) === false) {
        $errors[] = 'Start and end time must be valid dates/times.';
    } elseif (strtotime($start) >= strtotime($end)) {
        $errors[] = 'End time must be after start time.';
    }
    return $errors;
}

$activeFacilities = $pdo->query(
    "SELECT id, name, location, capacity FROM team8_facilities WHERE status = 'active' ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);
$hasActiveFacilities = $activeFacilities !== [];

$formValues = ['facility_id' => '', 'start_time' => '', 'end_time' => '', 'description' => ''];

switch ($action) {
    case 'create':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $formValues = [
                'facility_id' => (string) ($_POST['facility_id'] ?? ''),
                'start_time'  => t8_normalize_datetime((string) ($_POST['start_time'] ?? '')),
                'end_time'    => t8_normalize_datetime((string) ($_POST['end_time'] ?? '')),
                'description' => trim((string) ($_POST['description'] ?? '')),
            ];

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } elseif (!$hasActiveFacilities) {
                $errors[] = 'No active facilities are available to reserve right now.';
            } else {
                $facilityId = (int) $formValues['facility_id'];
                $errors = t8_reservation_validate($activeFacilities, $facilityId, $formValues['start_time'], $formValues['end_time']);

                if (!$errors) {
                    $status = $isAdmin ? 'approved' : 'pending';
                    $stmt = $pdo->prepare(
                        'INSERT INTO team8_reservations (facility_id, user_id, start_time, end_time, status, description)
                         VALUES (:facility_id, :user_id, :start_time, :end_time, :status, :description)'
                    );
                    $stmt->execute([
                        'facility_id' => $facilityId,
                        'user_id'     => $currentUserId,
                        'start_time'  => $formValues['start_time'],
                        'end_time'    => $formValues['end_time'],
                        'status'      => $status,
                        'description' => $formValues['description'] !== '' ? $formValues['description'] : null,
                    ]);
                    $newId = (int) $pdo->lastInsertId();

                    if ($isAdmin) {
                        // Administrator-created reservations bypass Pending -
                        // still recorded as a single decided approval step so
                        // team8_reservation_approvals reflects who approved it,
                        // same as a normal Approve click would.
                        $pdo->prepare(
                            'INSERT INTO team8_reservation_approvals (reservation_id, approver_id, step_order, status, decided_at)
                             VALUES (:reservation_id, :approver_id, 1, "approved", NOW())'
                        )->execute(['reservation_id' => $newId, 'approver_id' => $currentUserId]);
                        t8_audit_log($pdo, $currentUserId, 'reservation', $newId, 'create_auto_approved');

                        $hasConflict = t8_reservation_has_conflict(
                            $pdo, $facilityId, $formValues['start_time'], $formValues['end_time'], $newId
                        );
                        if ($hasConflict) {
                            t8_flash_set('warning', 'Reservation created and approved, but it overlaps with another approved reservation for this facility.');
                        } else {
                            t8_flash_set('success', 'Reservation created and approved.');
                        }
                    } else {
                        t8_audit_log($pdo, $currentUserId, 'reservation', $newId, 'create');
                        t8_flash_set('success', 'Reservation request submitted for approval.');
                    }

                    redirect(page_url('reservation'));
                }
            }
        }
        break;

    case 'edit':
        $id = (int) ($_GET['id'] ?? 0);
        $existing = $id ? t8_reservation_fetch($pdo, $id) : null;
        if (!$existing || (int) $existing['user_id'] !== $currentUserId || $existing['status'] !== 'pending') {
            t8_flash_set('danger', "That reservation can't be edited.");
            redirect(page_url('reservation'));
        }

        $formValues = [
            'facility_id' => (string) $existing['facility_id'],
            'start_time'  => (string) $existing['start_time'],
            'end_time'    => (string) $existing['end_time'],
            'description' => (string) ($existing['description'] ?? ''),
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $formValues = [
                'facility_id' => (string) ($_POST['facility_id'] ?? ''),
                'start_time'  => t8_normalize_datetime((string) ($_POST['start_time'] ?? '')),
                'end_time'    => t8_normalize_datetime((string) ($_POST['end_time'] ?? '')),
                'description' => trim((string) ($_POST['description'] ?? '')),
            ];

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                $facilityId = (int) $formValues['facility_id'];
                $errors = t8_reservation_validate($activeFacilities, $facilityId, $formValues['start_time'], $formValues['end_time']);

                if (!$errors) {
                    $pdo->prepare(
                        'UPDATE team8_reservations SET facility_id = :facility_id, start_time = :start_time, end_time = :end_time, description = :description WHERE id = :id'
                    )->execute([
                        'facility_id' => $facilityId,
                        'start_time'  => $formValues['start_time'],
                        'end_time'    => $formValues['end_time'],
                        'description' => $formValues['description'] !== '' ? $formValues['description'] : null,
                        'id'          => $id,
                    ]);
                    t8_audit_log($pdo, $currentUserId, 'reservation', $id, 'update');
                    t8_flash_set('success', 'Reservation updated.');
                    redirect(page_url('reservation'));
                }
            }
        }
        break;

    case 'cancel':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('reservation'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('reservation'));
        }
        $id = (int) ($_POST['id'] ?? 0);
        $target = t8_reservation_fetch($pdo, $id);
        if ($target && (int) $target['user_id'] === $currentUserId && $target['status'] === 'pending') {
            $pdo->prepare("UPDATE team8_reservations SET status = 'cancelled' WHERE id = :id")->execute(['id' => $id]);
            t8_audit_log($pdo, $currentUserId, 'reservation', $id, 'cancel');
            t8_flash_set('success', 'Reservation cancelled.');
        } else {
            t8_flash_set('danger', "That reservation can't be cancelled.");
        }
        redirect(page_url('reservation'));
        break;

    case 'archive':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('reservation'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('reservation'));
        }
        $id = (int) ($_POST['id'] ?? 0);
        $target = t8_reservation_fetch($pdo, $id);
        if ($target && $target['status'] === 'cancelled' && ($isAdmin || (int) $target['user_id'] === $currentUserId)) {
            $pdo->prepare('UPDATE team8_reservations SET deleted_at = NOW() WHERE id = :id')->execute(['id' => $id]);
            t8_audit_log($pdo, $currentUserId, 'reservation', $id, 'archive');
            t8_flash_set('success', 'Reservation archived.');
        } else {
            t8_flash_set('danger', 'That reservation cannot be archived.');
        }
        redirect(page_url('reservation'));
        break;

    case 'approve':
    case 'reject':
        // Approve/Reject is Administrator-only. Facilities Staff never sees
        // these buttons, but this guard blocks a direct POST too.
        t8_require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('reservation'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('reservation'));
        }
        $id = (int) ($_POST['id'] ?? 0);
        $target = t8_reservation_fetch($pdo, $id);
        if ($target && $target['status'] === 'pending') {
            $newStatus = $action === 'approve' ? 'approved' : 'rejected';
            $pdo->prepare('UPDATE team8_reservations SET status = :status WHERE id = :id')
                ->execute(['status' => $newStatus, 'id' => $id]);
            $pdo->prepare(
                'INSERT INTO team8_reservation_approvals (reservation_id, approver_id, step_order, status, decided_at)
                 VALUES (:reservation_id, :approver_id, 1, :status, NOW())'
            )->execute(['reservation_id' => $id, 'approver_id' => $currentUserId, 'status' => $newStatus]);
            t8_audit_log($pdo, $currentUserId, 'reservation', $id, $action);
            t8_flash_set('success', 'Reservation ' . $newStatus . '.');
        } else {
            t8_flash_set('danger', 'That reservation is no longer pending.');
        }
        redirect(page_url('reservation'));
        break;
}

$showForm = in_array($action, ['create', 'edit'], true);

// ---- Data for the list view ----
if (!$showForm) {
    if ($isAdmin) {
        $allReservations = $pdo->query(
            'SELECT r.*, f.name AS facility_name, u.full_name AS requester_name
             FROM team8_reservations r
             JOIN team8_facilities f ON f.id = r.facility_id
             JOIN users u ON u.id = r.user_id
             WHERE r.deleted_at IS NULL
             ORDER BY r.start_time DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $pendingReservations = $pdo->query(
            "SELECT r.*, f.name AS facility_name, u.full_name AS requester_name
             FROM team8_reservations r
             JOIN team8_facilities f ON f.id = r.facility_id
             JOIN users u ON u.id = r.user_id
             WHERE r.status = 'pending' AND r.deleted_at IS NULL
             ORDER BY r.start_time ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($pendingReservations as &$pending) {
            $pending['has_conflict'] = strtotime($pending['end_time']) > time()
                && t8_reservation_has_time_conflict(
                    $pdo,
                    (int) $pending['facility_id'],
                    (string) $pending['start_time'],
                    (string) $pending['end_time'],
                    (int) $pending['id']
                );
            $pending['display_status'] = t8_reservation_display_status($pending);
        }
        unset($pending);

        foreach ($allReservations as &$reservation) {
            $reservation['has_conflict'] = strtotime($reservation['end_time']) > time()
                && t8_reservation_has_time_conflict(
                    $pdo,
                    (int) $reservation['facility_id'],
                    (string) $reservation['start_time'],
                    (string) $reservation['end_time'],
                    (int) $reservation['id']
                );
            $reservation['display_status'] = t8_reservation_display_status($reservation);
        }
        unset($reservation);
        unset($pending);
    } else {
        $allStmt = $pdo->prepare(
            'SELECT r.*, f.name AS facility_name, u.full_name AS requester_name
             FROM team8_reservations r
             JOIN team8_facilities f ON f.id = r.facility_id
             JOIN users u ON u.id = r.user_id
             WHERE r.deleted_at IS NULL
             ORDER BY r.start_time DESC'
        );
        $allStmt->execute();
        $allReservations = $allStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allReservations as &$reservation) {
            $reservation['has_conflict'] = strtotime($reservation['end_time']) > time()
                && t8_reservation_has_time_conflict(
                    $pdo,
                    (int) $reservation['facility_id'],
                    (string) $reservation['start_time'],
                    (string) $reservation['end_time'],
                    (int) $reservation['id']
                );
            $reservation['display_status'] = t8_reservation_display_status($reservation);
        }
        unset($reservation);

        $myReservations = array_values(array_filter($allReservations, static function ($reservation) use ($currentUserId) {
            return (int) $reservation['user_id'] === $currentUserId;
        }));
    }
}
?>
<h1>Facilities Reservation</h1>
<p class="t8-help-text">
    <?= $isAdmin
        ? 'Review pending requests and manage all reservations.'
        : 'Submit a reservation request and track its approval status.' ?>
</p>

<?php if ($showForm): ?>

    <?php foreach ($errors as $error): ?>
        <div class="t8-alert t8-alert-danger"><?= e($error) ?></div>
    <?php endforeach; ?>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= $action === 'edit' ? 'Edit Reservation' : 'New Reservation' ?></h2>
        </div>

        <?php if (!$hasActiveFacilities): ?>
            <div class="t8-empty">
                No active facilities are available to reserve right now.
                <?php if ($isAdmin): ?>
                    <br><br>
                    <a class="t8-btn t8-btn-accent" href="<?= e(page_url('facilities', ['action' => 'create'])) ?>">
                        <i class="fa-solid fa-plus"></i> Add Facility
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <form id="t8ReservationForm" method="post"
                  action="<?= e(page_url('reservation', array_filter(['action' => $action, 'id' => $_GET['id'] ?? null]))) ?>"
                  novalidate>
                <?= t8_csrf_field() ?>

                <div class="t8-field">
                    <label class="t8-label" for="facility_id">Facility</label>
                    <select class="t8-select" id="facility_id" name="facility_id" required>
                        <option value="">Select a facility…</option>
                        <?php foreach ($activeFacilities as $f): ?>
                            <option value="<?= e((string) $f['id']) ?>" <?= (string) $f['id'] === $formValues['facility_id'] ? 'selected' : '' ?>>
                                <?= e($f['name']) ?> — <?= e($f['location']) ?> (cap. <?= e((string) $f['capacity']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="t8-field">
                    <label class="t8-label" for="start_time">Start</label>
                    <input class="t8-input" type="datetime-local" id="start_time" name="start_time"
                           value="<?= e(str_replace(' ', 'T', substr($formValues['start_time'], 0, 16))) ?>" required>
                </div>

                <div class="t8-field">
                    <label class="t8-label" for="end_time">End</label>
                    <input class="t8-input" type="datetime-local" id="end_time" name="end_time"
                           value="<?= e(str_replace(' ', 'T', substr($formValues['end_time'], 0, 16))) ?>" required>
                </div>

                <div class="t8-field">
                    <label class="t8-label" for="description">Description</label>
                    <textarea class="t8-textarea" id="description" name="description" rows="3" placeholder="Describe the event or purpose of this reservation."><?= e($formValues['description']) ?></textarea>
                </div>

                <button class="t8-btn t8-btn-accent" type="submit">
                    <i class="fa-solid fa-check"></i>
                    <?= $action === 'edit' ? 'Save Changes' : ($isAdmin ? 'Create & Approve' : 'Submit Request') ?>
                </button>
                <a class="t8-btn t8-btn-outline" href="<?= e(page_url('reservation')) ?>">Cancel</a>
            </form>
        <?php endif; ?>
    </div>

<?php else: ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
        <?php if ($hasActiveFacilities): ?>
            <a class="t8-btn t8-btn-accent" href="<?= e(page_url('reservation', ['action' => 'create'])) ?>">
                <i class="fa-solid fa-calendar-plus"></i> New Reservation
            </a>
        <?php endif; ?>
    </div>

    <?php if (!$hasActiveFacilities): ?>
        <div class="t8-empty">
            No active facilities are available yet.
            <?php if ($isAdmin): ?>
                <br><br>
                <a class="t8-btn t8-btn-accent" href="<?= e(page_url('facilities', ['action' => 'create'])) ?>">
                    <i class="fa-solid fa-plus"></i> Add Facility
                </a>
            <?php else: ?>
                <br>Please check back once an administrator has added one.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>

        <div class="t8-card">
            <div class="t8-card-header">
                <h2 class="t8-card-title">Pending Approvals</h2>
                <?php if ($pendingReservations !== []): ?>
                    <span class="t8-notification-count"><?= e((string) count($pendingReservations)) ?> pending</span>
                <?php endif; ?>
            </div>
            <?php if ($pendingReservations === []): ?>
                <div class="t8-empty">No reservations are waiting for approval.</div>
            <?php else: ?>
                <div class="t8-table-wrap">
                    <table class="t8-table">
                        <thead>
                            <tr>
                                <th>Facility</th>
                                <th>Requested By</th>
                                <th>Description</th>
                                <th>Start</th>
                                <th>End</th>
                                <th>Conflict</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingReservations as $p): ?>
                                <tr>
                                    <td><?= e($p['facility_name']) ?></td>
                                    <td><?= e($p['requester_name']) ?></td>
                                    <td><?= e($p['description'] ?: '—') ?></td>
                                    <td><?= e(format_date($p['start_time'], 'M d, Y g:i A')) ?></td>
                                    <td><?= e(format_date($p['end_time'], 'M d, Y g:i A')) ?></td>
                                    <td>
                                        <?php if ($p['has_conflict']): ?>
                                            <span class="t8-badge t8-badge-pending">
                                                <i class="fa-solid fa-triangle-exclamation"></i> Possible double-booking
                                            </span>
                                        <?php else: ?>
                                            <span class="t8-help-text">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="display:flex; gap:8px; flex-wrap:wrap;">
                                        <form method="post" action="<?= e(page_url('reservation', ['action' => 'approve'])) ?>">
                                            <?= t8_csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $p['id']) ?>">
                                            <button class="t8-btn t8-btn-success t8-btn-sm" type="submit">
                                                <i class="fa-solid fa-check"></i> Approve
                                            </button>
                                        </form>
                                        <form method="post" action="<?= e(page_url('reservation', ['action' => 'reject'])) ?>"
                                              onsubmit="return confirm('Reject this reservation request?');">
                                            <?= t8_csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $p['id']) ?>">
                                            <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit">
                                                <i class="fa-solid fa-xmark"></i> Reject
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="t8-card">
            <div class="t8-card-header">
                <h2 class="t8-card-title">All Reservations</h2>
            </div>
            <?php if ($allReservations === []): ?>
                <div class="t8-empty">No reservations have been made yet.</div>
            <?php else: ?>
                <div class="t8-table-wrap">
                    <table class="t8-table">
                        <thead>
                            <tr>
                                <th>Facility</th>
                                <th>Requested By</th>
                                <th>Description</th>
                                <th>Start</th>
                                <th>End</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allReservations as $r): ?>
                                <tr class="<?= $r['has_conflict'] ? 't8-table-row-conflict' : '' ?>">
                                    <td><?= e($r['facility_name']) ?></td>
                                    <td><?= e($r['requester_name']) ?></td>
                                    <td><?= e($r['description'] ?: '—') ?></td>
                                    <td><?= e(format_date($r['start_time'], 'M d, Y g:i A')) ?></td>
                                    <td><?= e(format_date($r['end_time'], 'M d, Y g:i A')) ?></td>
                                    <td>
                                        <span class="t8-badge t8-badge-<?= e($r['display_status']) ?>"><?= e(ucfirst($r['display_status'])) ?></span>
                                        <?php if ($r['has_conflict']): ?>
                                            <span class="t8-badge t8-badge-pending" style="margin-left:0.5rem;">Conflict</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($r['status'] === 'cancelled'): ?>
                                            <form method="post" action="<?= e(page_url('reservation', ['action' => 'archive'])) ?>"
                                                  onsubmit="return confirm('Archive this cancelled reservation?');">
                                                <?= t8_csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= e((string) $r['id']) ?>">
                                                <button class="t8-btn t8-btn-outline t8-btn-sm" type="submit">
                                                    <i class="fa-solid fa-box-archive"></i> Archive
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="t8-help-text">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>

        <div class="t8-card">
            <div class="t8-card-header">
                <h2 class="t8-card-title">My Reservations</h2>
            </div>
            <?php if ($myReservations === []): ?>
                <div class="t8-empty">You haven't made any reservations yet.</div>
            <?php else: ?>
                <div class="t8-table-wrap">
                    <table class="t8-table">
                        <thead>
                            <tr>
                                <th>Facility</th>
                                <th>Description</th>
                                <th>Start</th>
                                <th>End</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($myReservations as $r): ?>
                                <?php $canManage = $isAdmin || ((int) $r['user_id'] === $currentUserId); ?>
                                <tr class="<?= $r['has_conflict'] ? 't8-table-row-conflict' : '' ?>">
                                    <td><?= e($r['facility_name']) ?></td>
                                    <td><?= e($r['description'] ?: '—') ?></td>
                                    <td><?= e(format_date($r['start_time'], 'M d, Y g:i A')) ?></td>
                                    <td><?= e(format_date($r['end_time'], 'M d, Y g:i A')) ?></td>
                                    <td>
                                        <span class="t8-badge t8-badge-<?= e($r['display_status']) ?>"><?= e(ucfirst($r['display_status'])) ?></span>
                                        <?php if ($r['has_conflict']): ?>
                                            <span class="t8-badge t8-badge-pending" style="margin-left:0.5rem;">Conflict</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="display:flex; gap:8px; flex-wrap:wrap;">
                                        <?php if ($canManage && $r['status'] === 'pending'): ?>
                                            <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('reservation', ['action' => 'edit', 'id' => $r['id']])) ?>">
                                                <i class="fa-solid fa-pen"></i> Edit
                                            </a>
                                            <form method="post" action="<?= e(page_url('reservation', ['action' => 'cancel'])) ?>"
                                                  onsubmit="return confirm('Cancel this reservation request?');">
                                                <?= t8_csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= e((string) $r['id']) ?>">
                                                <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit">
                                                    <i class="fa-solid fa-xmark"></i> Cancel
                                                </button>
                                            </form>
                                        <?php elseif ($canManage && $r['status'] === 'cancelled'): ?>
                                            <form method="post" action="<?= e(page_url('reservation', ['action' => 'archive'])) ?>"
                                                  onsubmit="return confirm('Archive this cancelled reservation?');">
                                                <?= t8_csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= e((string) $r['id']) ?>">
                                                <button class="t8-btn t8-btn-outline t8-btn-sm" type="submit">
                                                    <i class="fa-solid fa-box-archive"></i> Archive
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="t8-help-text">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="t8-card">
            <div class="t8-card-header">
                <h2 class="t8-card-title">All Reservations</h2>
            </div>
            <?php if ($allReservations === []): ?>
                <div class="t8-empty">No reservations have been made yet.</div>
            <?php else: ?>
                <div class="t8-table-wrap">
                    <table class="t8-table">
                        <thead>
                            <tr>
                                <th>Facility</th>
                                <th>Requested By</th>
                                <th>Description</th>
                                <th>Start</th>
                                <th>End</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allReservations as $r): ?>
                                <tr class="<?= $r['has_conflict'] ? 't8-table-row-conflict' : '' ?>">
                                    <td><?= e($r['facility_name']) ?></td>
                                    <td><?= e($r['requester_name']) ?></td>
                                    <td><?= e($r['description'] ?: '—') ?></td>
                                    <td><?= e(format_date($r['start_time'], 'M d, Y g:i A')) ?></td>
                                    <td><?= e(format_date($r['end_time'], 'M d, Y g:i A')) ?></td>
                                    <td>
                                        <span class="t8-badge t8-badge-<?= e($r['display_status']) ?>"><?= e(ucfirst($r['display_status'])) ?></span>
                                        <?php if ($r['has_conflict']): ?>
                                            <span class="t8-badge t8-badge-pending" style="margin-left:0.5rem;">Conflict</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>

<?php endif; ?>
