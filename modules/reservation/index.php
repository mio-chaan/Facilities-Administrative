<?php
declare(strict_types=1);

$pageTitle = 'Facilities Reservation';
$currentUserId = t8_current_user_id();
$isAdmin = t8_has_role('admin');
$action = $_GET['action'] ?? 'list';
$errors = [];

// Dropdown options for Event Category. Edit this list to match your
// organization's actual event types - no other code needs to change.
const T8_EVENT_CATEGORIES = [
    'Meeting',
    'Training / Seminar',
    'Client / Guest Event',
    'Celebration / Social Event',
    'Community / Barangay Event',
    'Equipment Demonstration',
    'Other',
];

// Dropdown options for Department. Edit or extend these values as needed.
const T8_DEPARTMENTS = [
    'Administration',
    'Finance',
    'Human Resources',
    'Information Technology',
    'Legal',
    'Operations',
    'Procurement',
    'Facilities',
    'Security',
    'Marketing',
    'Customer Service',
];

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

/** Annotates a list of reservation rows in-place with a 'has_conflict' bool. */
function t8_reservations_annotate_conflicts(PDO $pdo, array $rows): array
{
    foreach ($rows as &$row) {
        $row['has_conflict'] = t8_reservation_has_conflict(
            $pdo,
            (int) $row['facility_id'],
            (string) $row['start_time'],
            (string) $row['end_time'],
            (int) $row['id']
        );
    }
    unset($row);
    return $rows;
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
function t8_reservation_validate(array $activeFacilities, int $facilityId, string $start, string $end, string $department, string $keyPerson, string $expectedParticipants, string $eventCategory): array
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
    if ($department === '') {
        $errors[] = 'Department is required.';
    }
    if ($keyPerson === '') {
        $errors[] = 'Key person / point of contact is required.';
    }
    if ($expectedParticipants !== '') {
        if (!ctype_digit($expectedParticipants) || (int) $expectedParticipants < 1) {
            $errors[] = 'Expected participants must be a positive whole number.';
        } else {
            foreach ($activeFacilities as $f) {
                if ((int) $f['id'] === $facilityId) {
                    if ((int) $expectedParticipants > (int) $f['capacity']) {
                        $errors[] = 'Expected participants cannot exceed the selected facility capacity (' . e((string) $f['capacity']) . ').';
                    }
                    break;
                }
            }
        }
    }
    if (!in_array($eventCategory, T8_EVENT_CATEGORIES, true)) {
        $errors[] = 'Please select a valid event category.';
    }
    return $errors;
}

$activeFacilities = $pdo->query(
    "SELECT id, name, location, facility_type, capacity FROM team8_facilities WHERE status = 'active' ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);
$hasActiveFacilities = $activeFacilities !== [];

$formValues = [
    'facility_id'           => '',
    'start_time'            => '',
    'end_time'              => '',
    'department'            => '',
    'key_person'            => '',
    'expected_participants' => '',
    'event_category'        => '',
    'description'           => '',
];

switch ($action) {
    case 'create':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $formValues = [
                'facility_id'           => (string) ($_POST['facility_id'] ?? ''),
                'start_time'            => t8_normalize_datetime((string) ($_POST['start_time'] ?? '')),
                'end_time'              => t8_normalize_datetime((string) ($_POST['end_time'] ?? '')),
                'department'            => trim((string) ($_POST['department'] ?? '')),
                'key_person'            => trim((string) ($_POST['key_person'] ?? '')),
                'expected_participants' => trim((string) ($_POST['expected_participants'] ?? '')),
                'event_category'        => (string) ($_POST['event_category'] ?? ''),
                'description'           => trim((string) ($_POST['description'] ?? '')),
            ];

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } elseif (!$hasActiveFacilities) {
                $errors[] = 'No active facilities are available to reserve right now.';
            } else {
                $facilityId = (int) $formValues['facility_id'];
                $errors = t8_reservation_validate(
                    $activeFacilities, $facilityId, $formValues['start_time'], $formValues['end_time'],
                    $formValues['department'], $formValues['key_person'], $formValues['expected_participants'], $formValues['event_category']
                );
                $participants = $formValues['expected_participants'] !== '' ? (int) $formValues['expected_participants'] : null;
                if ($participants !== null && $participants < 1) {
                    $errors[] = 'Expected participants must be at least 1.';
                }

                if (!$errors) {
                    $status = $isAdmin ? 'approved' : 'pending';
                    $stmt = $pdo->prepare(
                        'INSERT INTO team8_reservations
                            (facility_id, user_id, start_time, end_time, status, department, key_person, expected_participants, event_category, description)
                         VALUES
                            (:facility_id, :user_id, :start_time, :end_time, :status, :department, :key_person, :expected_participants, :event_category, :description)'
                    );
                    $stmt->execute([
                        'facility_id'           => $facilityId,
                        'user_id'               => $currentUserId,
                        'start_time'            => $formValues['start_time'],
                        'end_time'              => $formValues['end_time'],
                        'status'                => $status,
                        'department'            => $formValues['department'],
                        'key_person'            => $formValues['key_person'],
                        'expected_participants' => $participants,
                        'event_category'        => $formValues['event_category'],
                        'description'           => $formValues['description'] !== '' ? $formValues['description'] : null,
                    ]);
                    $newId = (int) $pdo->lastInsertId();

                    if ($isAdmin) {
                        // Administrator-created reservations are recorded as a
                        // single approval step in the approvals table.
                        $pdo->prepare(
                            'INSERT INTO team8_reservation_approvals (reservation_id, approver_id, step_order, status, decided_at)
                             VALUES (:reservation_id, :approver_id, 1, "approved", NOW())'
                        )->execute(['reservation_id' => $newId, 'approver_id' => $currentUserId]);
                        t8_audit_log($pdo, $currentUserId, 'reservation', $newId, 'create_auto_approved');

                        // Conflict warnings are shown when an auto-approved
                        // reservation overlaps with another approved booking.
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
            'facility_id'           => (string) $existing['facility_id'],
            'start_time'            => (string) $existing['start_time'],
            'end_time'              => (string) $existing['end_time'],
            'department'            => (string) ($existing['department'] ?? ''),
            'key_person'            => (string) ($existing['key_person'] ?? ''),
            'expected_participants' => (string) ($existing['expected_participants'] ?? ''),
            'event_category'        => (string) ($existing['event_category'] ?? ''),
            'description'           => (string) ($existing['description'] ?? ''),
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $formValues = [
                'facility_id'           => (string) ($_POST['facility_id'] ?? ''),
                'start_time'            => t8_normalize_datetime((string) ($_POST['start_time'] ?? '')),
                'end_time'              => t8_normalize_datetime((string) ($_POST['end_time'] ?? '')),
                'department'            => trim((string) ($_POST['department'] ?? '')),
                'key_person'            => trim((string) ($_POST['key_person'] ?? '')),
                'expected_participants' => trim((string) ($_POST['expected_participants'] ?? '')),
                'event_category'        => (string) ($_POST['event_category'] ?? ''),
                'description'           => trim((string) ($_POST['description'] ?? '')),
            ];

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                $facilityId = (int) $formValues['facility_id'];
                $errors = t8_reservation_validate(
                    $activeFacilities, $facilityId, $formValues['start_time'], $formValues['end_time'],
                    $formValues['department'], $formValues['key_person'], $formValues['expected_participants'], $formValues['event_category']
                );
                $participants = $formValues['expected_participants'] !== '' ? (int) $formValues['expected_participants'] : null;
                if ($participants !== null && $participants < 1) {
                    $errors[] = 'Expected participants must be at least 1.';
                }

                if (!$errors) {
                    $pdo->prepare(
                        'UPDATE team8_reservations SET
                            facility_id = :facility_id, start_time = :start_time, end_time = :end_time,
                            department = :department, key_person = :key_person,
                            expected_participants = :expected_participants, event_category = :event_category,
                            description = :description
                         WHERE id = :id'
                    )->execute([
                        'facility_id'           => $facilityId,
                        'start_time'            => $formValues['start_time'],
                        'end_time'              => $formValues['end_time'],
                        'department'            => $formValues['department'],
                        'key_person'            => $formValues['key_person'],
                        'expected_participants' => $participants,
                        'event_category'        => $formValues['event_category'],
                        'description'           => $formValues['description'] !== '' ? $formValues['description'] : null,
                        'id'                    => $id,
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

    case 'delete':
        // Delete is intentionally narrow: only a 'cancelled' reservation
        // may ever be deleted, by its own requester or an Administrator.
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
        $canDelete = $target
            && $target['status'] === 'cancelled'
            && ($isAdmin || (int) $target['user_id'] === $currentUserId);
        if ($canDelete) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM team8_reservation_approvals WHERE reservation_id = :id')
                    ->execute(['id' => $id]);
                $pdo->prepare('DELETE FROM team8_reservations WHERE id = :id')
                    ->execute(['id' => $id]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            t8_audit_log($pdo, $currentUserId, 'reservation', $id, 'delete');
            t8_flash_set('success', 'Reservation deleted.');
        } else {
            t8_flash_set('danger', "Only a cancelled reservation can be deleted.");
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
            // Moving out of Pending Approvals and into All Reservations is
            // automatic - both tables below simply query by status, so a
            // flipped status is instantly reflected in both without any
            // extra "move" step.
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
             ORDER BY r.start_time DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
        $allReservations = t8_reservations_annotate_conflicts($pdo, $allReservations);

        // Lists EVERY reservation currently awaiting approval - nothing
        // filters this further, so it's always the complete pending set.
        $pendingReservations = $pdo->query(
            "SELECT r.*, f.name AS facility_name, u.full_name AS requester_name
             FROM team8_reservations r
             JOIN team8_facilities f ON f.id = r.facility_id
             JOIN users u ON u.id = r.user_id
             WHERE r.status = 'pending'
             ORDER BY r.start_time ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $pendingReservations = t8_reservations_annotate_conflicts($pdo, $pendingReservations);
    } else {
        $allReservationsStmt = $pdo->prepare(
            'SELECT r.*, f.name AS facility_name, u.full_name AS requester_name
             FROM team8_reservations r
             JOIN team8_facilities f ON f.id = r.facility_id
             JOIN users u ON u.id = r.user_id
             ORDER BY r.start_time DESC'
        );
        $allReservationsStmt->execute();
        $allReservations = $allReservationsStmt->fetchAll(PDO::FETCH_ASSOC);
        $allReservations = t8_reservations_annotate_conflicts($pdo, $allReservations);

        $myStmt = $pdo->prepare(
            'SELECT r.*, f.name AS facility_name
             FROM team8_reservations r
             JOIN team8_facilities f ON f.id = r.facility_id
             WHERE r.user_id = :user_id
             ORDER BY r.start_time DESC'
        );
        $myStmt->execute(['user_id' => $currentUserId]);
        $myReservations = $myStmt->fetchAll(PDO::FETCH_ASSOC);
        $myReservations = t8_reservations_annotate_conflicts($pdo, $myReservations);
    }
}
?>
<h1>Facilities Reservation</h1>
<p class="t8-help-text">
    <?= $isAdmin
        ? 'Review pending requests and manage all reservations.'
        : 'Submit a reservation request and track your bookings. Review your own reservations and the full reservation list.' ?>
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
            <form method="post"
                  action="<?= e(page_url('reservation', array_filter(['action' => $action, 'id' => $_GET['id'] ?? null]))) ?>"
                  novalidate>
                <?= t8_csrf_field() ?>

                <div class="t8-field">
                    <label class="t8-label" for="facility_id">Facility</label>
                    <select class="t8-select" id="facility_id" name="facility_id" required>
                        <option value="">Select a facility…</option>
                        <?php foreach ($activeFacilities as $f): ?>
                            <option value="<?= e((string) $f['id']) ?>" <?= (string) $f['id'] === $formValues['facility_id'] ? 'selected' : '' ?>>

                             <?= e($f['name']) ?><?= $f['facility_type'] ? ' — ' . e($f['facility_type']) : '' ?> — <?= e($f['location']) ?> (cap. <?= e((string) $f['capacity']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="t8-field">
                    <label class="t8-label" for="event_category">Event Category</label>
                    <select class="t8-select" id="event_category" name="event_category" required>
                        <option value="">Select a category…</option>
                        <?php foreach (T8_EVENT_CATEGORIES as $cat): ?>
                            <option value="<?= e($cat) ?>" <?= $cat === $formValues['event_category'] ? 'selected' : '' ?>><?= e($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="t8-field">
                    <label class="t8-label" for="department">Department</label>
                    <select class="t8-select" id="department" name="department" required>
                        <option value="">Select a department…</option>
                        <?php foreach (T8_DEPARTMENTS as $dept): ?>
                            <option value="<?= e($dept) ?>" <?= $dept === $formValues['department'] ? 'selected' : '' ?>><?= e($dept) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="t8-field">
                    <label class="t8-label" for="key_person">Key Person / Point of Contact</label>
                    <input class="t8-input" type="text" id="key_person" name="key_person"
                           value="<?= e($formValues['key_person']) ?>" placeholder="Name of the person to coordinate with" required>
                </div>

                <div class="t8-field">
                    <label class="t8-label" for="expected_participants">Expected Participants</label>
                    <input class="t8-input" type="number" id="expected_participants" name="expected_participants" min="1"
                           value="<?= e($formValues['expected_participants']) ?>" placeholder="Optional headcount">
                </div>

                <!--
                    Date/time click fix: native datetime-local inputs only
                    open their picker when the calendar icon itself is
                    clicked in some browsers. this.showPicker() (Chrome/Edge
                    111+) opens the picker programmatically, so the onclick
                    below makes the ENTIRE field clickable, not just the
                    icon. Browsers without showPicker() silently no-op and
                    fall back to normal native behavior - nothing breaks.
                -->
                <div class="t8-field">
                    <label class="t8-label" for="start_time">Start</label>
                    <input class="t8-input t8-datetime-input" type="datetime-local" id="start_time" name="start_time"
                           value="<?= e(str_replace(' ', 'T', substr($formValues['start_time'], 0, 16))) ?>"
                           onclick="this.showPicker && this.showPicker();" required>
                </div>

                <div class="t8-field">
                    <label class="t8-label" for="end_time">End</label>
                    <input class="t8-input t8-datetime-input" type="datetime-local" id="end_time" name="end_time"
                           value="<?= e(str_replace(' ', 'T', substr($formValues['end_time'], 0, 16))) ?>"
                           onclick="this.showPicker && this.showPicker();" required>
                </div>

                <div class="t8-field">
                    <label class="t8-label" for="description">Additional Details / Notes</label>
                    <input class="t8-input" type="text" id="description" name="description"
                           value="<?= e($formValues['description']) ?>"
                           placeholder="Optional — anything not covered above">
                    <span class="t8-help-text">Event Category covers the main purpose; use this only for extra notes.</span>
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
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead>
                        <tr>
                            <th>Facility</th>
                            <th>Department</th>
                            <th>Key Person</th>
                            <th>Category</th>
                            <th>Requested By</th>
                            <th>Start</th>
                            <th>End</th>
                            <th>Participants</th>
                            <th>Notes</th>
                            <th>Conflict</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($pendingReservations === []): ?>
                            <tr>
                                <td colspan="11" class="t8-table-empty-row">
                                    No reservation requests are waiting for approval yet.
                                    Once a request is submitted, it will appear here.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pendingReservations as $p): ?>
                                <tr <?= $p['has_conflict'] ? 'style="background: rgba(230,126,34,0.14);"' : '' ?>>
                                    <td><?= e($p['facility_name']) ?></td>
                                    <td><?= e((string) ($p['department'] ?? '—')) ?></td>
                                    <td><?= e((string) ($p['key_person'] ?? '—')) ?></td>
                                    <td><?= e((string) ($p['event_category'] ?? '—')) ?></td>
                                    <td><?= e($p['requester_name']) ?></td>
                                    <td><?= e(format_date($p['start_time'], 'M d, Y g:i A')) ?></td>
                                    <td><?= e(format_date($p['end_time'], 'M d, Y g:i A')) ?></td>
                                    <td><?= e((string) ($p['expected_participants'] ?? '—')) ?></td>
                                    <td><?= e((string) ($p['description'] ?? '—')) ?></td>
                                    <td>
                                        <?php if ($p['has_conflict']): ?>
                                            <span class="t8-badge" title="Time Conflict"
                                                  style="background:#E67E22; color:#fff; font-weight:700;">
                                                <i class="fa-solid fa-triangle-exclamation"></i> ! Time Conflict
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
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="t8-card">
            <div class="t8-card-header">
                <h2 class="t8-card-title">All Reservations</h2>
            </div>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead>
                        <tr>
                            <th>Facility</th>
                            <th>Department</th>
                            <th>Key Person</th>
                            <th>Category</th>
                            <th>Requested By</th>
                            <th>Start</th>
                            <th>End</th>
                            <th>Participants</th>
                            <th>Notes</th>
                            <th>Status</th>
                            <th>Conflict</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($allReservations === []): ?>
                            <tr>
                                <td colspan="12" class="t8-table-empty-row">
                                    No reservations have been made yet.
                                    Once a reservation is submitted, it will appear here.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allReservations as $r): ?>
                                <tr <?= $r['has_conflict'] ? 'style="background: rgba(230,126,34,0.14);"' : '' ?>>
                                    <td><?= e($r['facility_name']) ?></td>
                                    <td><?= e((string) ($r['department'] ?? '—')) ?></td>
                                    <td><?= e((string) ($r['key_person'] ?? '—')) ?></td>
                                    <td><?= e((string) ($r['event_category'] ?? '—')) ?></td>
                                    <td><?= e($r['requester_name']) ?></td>
                                    <td><?= e(format_date($r['start_time'], 'M d, Y g:i A')) ?></td>
                                    <td><?= e(format_date($r['end_time'], 'M d, Y g:i A')) ?></td>
                                    <td><?= e((string) ($r['expected_participants'] ?? '—')) ?></td>
                                    <td><?= e((string) ($r['description'] ?? '—')) ?></td>
                                    <td><span class="t8-badge t8-badge-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
                                    <td>
                                        <?php if ($r['has_conflict']): ?>
                                            <span class="t8-badge" title="Time Conflict"
                                                  style="background:#E67E22; color:#fff; font-weight:700;">
                                                <i class="fa-solid fa-triangle-exclamation"></i> !
                                            </span>
                                        <?php else: ?>
                                            <span class="t8-help-text">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($r['status'] === 'cancelled'): ?>
                                            <form method="post" action="<?= e(page_url('reservation', ['action' => 'delete'])) ?>"
                                                  onsubmit="return confirm('Permanently delete this cancelled reservation? This cannot be undone.');">
                                                <?= t8_csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= e((string) $r['id']) ?>">
                                                <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit">
                                                    <i class="fa-solid fa-trash"></i> Delete
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="t8-help-text">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php else: ?>

        <div class="t8-card">
            <div class="t8-card-header">
                <h2 class="t8-card-title">My Reservations</h2>
            </div>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead>
                        <tr>
                            <th>Facility</th>
                            <th>Department</th>
                            <th>Key Person</th>
                            <th>Category</th>
                            <th>Start</th>
                            <th>End</th>
                            <th>Participants</th>
                            <th>Notes</th>
                            <th>Status</th>
                            <th>Conflict</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($myReservations === []): ?>
                            <tr>
                                <td colspan="11" class="t8-table-empty-row">
                                    You haven't made any reservations yet.
                                    Create one to see it listed here.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($myReservations as $r): ?>
                                <tr <?= $r['has_conflict'] ? 'style="background: rgba(230,126,34,0.14);"' : '' ?>>
                                    <td><?= e($r['facility_name']) ?></td>
                                    <td><?= e((string) ($r['department'] ?? '—')) ?></td>
                                    <td><?= e((string) ($r['key_person'] ?? '—')) ?></td>
                                    <td><?= e((string) ($r['event_category'] ?? '—')) ?></td>
                                    <td><?= e(format_date($r['start_time'], 'M d, Y g:i A')) ?></td>
                                    <td><?= e(format_date($r['end_time'], 'M d, Y g:i A')) ?></td>
                                    <td><?= e((string) ($r['expected_participants'] ?? '—')) ?></td>
                                    <td><?= e((string) ($r['description'] ?? '—')) ?></td>
                                    <td><span class="t8-badge t8-badge-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
                                    <td>
                                        <?php if ($r['has_conflict']): ?>
                                            <span class="t8-badge" title="Time Conflict"
                                                  style="background:#E67E22; color:#fff; font-weight:700;">
                                                <i class="fa-solid fa-triangle-exclamation"></i> ! Time Conflict
                                            </span>
                                        <?php else: ?>
                                            <span class="t8-help-text">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="display:flex; gap:8px; flex-wrap:wrap;">
                                        <?php if ($r['status'] === 'pending'): ?>
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
                                        <?php elseif ($r['status'] === 'cancelled'): ?>
                                            <form method="post" action="<?= e(page_url('reservation', ['action' => 'delete'])) ?>"
                                                  onsubmit="return confirm('Permanently delete this cancelled reservation? This cannot be undone.');">
                                                <?= t8_csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= e((string) $r['id']) ?>">
                                                <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit">
                                                    <i class="fa-solid fa-trash"></i> Delete
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="t8-help-text">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="t8-card">
            <div class="t8-card-header">
                <h2 class="t8-card-title">All Reservations</h2>
            </div>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead>
                        <tr>
                            <th>Facility</th>
                            <th>Department</th>
                            <th>Key Person</th>
                            <th>Category</th>
                            <th>Requested By</th>
                            <th>Start</th>
                            <th>End</th>
                            <th>Participants</th>
                            <th>Notes</th>
                            <th>Status</th>
                            <th>Conflict</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($allReservations === []): ?>
                            <tr>
                                <td colspan="11" class="t8-table-empty-row">
                                    No reservations have been made yet.
                                    Once a reservation is submitted, it will appear here.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allReservations as $r): ?>
                                <tr <?= $r['has_conflict'] ? 'style="background: rgba(230,126,34,0.14);"' : '' ?>>
                                    <td><?= e($r['facility_name']) ?></td>
                                    <td><?= e((string) ($r['department'] ?? '—')) ?></td>
                                    <td><?= e((string) ($r['key_person'] ?? '—')) ?></td>
                                    <td><?= e((string) ($r['event_category'] ?? '—')) ?></td>
                                    <td><?= e((string) ($r['requester_name'] ?? '—')) ?></td>
                                    <td><?= e(format_date($r['start_time'], 'M d, Y g:i A')) ?></td>
                                    <td><?= e(format_date($r['end_time'], 'M d, Y g:i A')) ?></td>
                                    <td><?= e((string) ($r['expected_participants'] ?? '—')) ?></td>
                                    <td><?= e((string) ($r['description'] ?? '—')) ?></td>
                                    <td><span class="t8-badge t8-badge-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
                                    <td>
                                        <?php if ($r['has_conflict']): ?>
                                            <span class="t8-badge" title="Time Conflict"
                                                  style="background:#E67E22; color:#fff; font-weight:700;">
                                                <i class="fa-solid fa-triangle-exclamation"></i> !
                                            </span>
                                        <?php else: ?>
                                            <span class="t8-help-text">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>

<?php endif; ?>