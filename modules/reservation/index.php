<?php
declare(strict_types=1);

$pageTitle = 'Facilities Reservation';
$currentUserId = t8_current_user_id();
$isAdmin = t8_has_role('admin');
$isReservationStaff = t8_has_role('facilities_staff');
$action = $_GET['action'] ?? 'list';
$errors = [];

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

// Single source of truth for facility-type-driven reservation fields.
const T8_FACILITY_RESERVATION_CONFIG = [
    'Room' => [
        'event_categories' => [
            'Meeting',
            'Training / Seminar',
            'Client / Guest Event',
            'Celebration / Social Event',
            'Orientation',
        ],
        'visible_fields' => ['participants', 'time_range'],
        'required_fields' => ['participants', 'time_range'],
    ],
    'Equipment' => [
        'event_categories' => [
            'Equipment Borrowing',
            'Maintenance',
            'Repair',
            'Inspection',
            'Demonstration',
        ],
        'visible_fields' => ['quantity', 'return_date'],
        'required_fields' => ['quantity', 'return_date'],
    ],
    'Asset' => [
        'event_categories' => [
            'Asset Borrowing',
            'Event Setup',
            'Inventory Check',
            'Maintenance',
        ],
        'visible_fields' => ['quantity', 'return_date'],
        'required_fields' => ['quantity', 'return_date'],
    ],
    'Area' => [
        'event_categories' => [
            'Event',
            'Maintenance',
            'Inspection',
            'Cleaning',
            'Setup',
        ],
        'visible_fields' => ['participants', 'time_range'],
        'required_fields' => ['time_range'],
    ],
    'Utility' => [
        'event_categories' => [
            'Maintenance',
            'Repair',
            'Inspection',
            'Installation',
        ],
        'visible_fields' => ['remarks', 'schedule', 'requirements'],
        'required_fields' => ['remarks', 'schedule', 'requirements'],
    ],
];

/** Fetch a single reservation with its facility/requester names, or null. */
function t8_reservation_fetch(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT r.*, f.name AS facility_name, f.location AS facility_location, f.facility_type, u.full_name AS requester_name
         FROM team8_reservations r
         JOIN team8_facilities f ON f.id = r.facility_id
         JOIN users u ON u.id = r.user_id
         WHERE r.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** True if an active/pending reservation already occupies this facility/time range. */
function t8_reservation_has_conflict(PDO $pdo, int $facilityId, string $start, string $end, ?int $excludeId = null): bool
{
    $sql = "SELECT COUNT(*) FROM team8_reservations
            WHERE facility_id = :facility_id AND status IN ('pending', 'approved', 'cancellation_pending')
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

/**
 * FIX (quantity/capacity limit): sum of quantity currently committed
 * (pending or approved, not yet returned) against a facility, so a
 * new/edited Equipment or Asset reservation can be checked against
 * what is actually still AVAILABLE rather than the facility's total
 * capacity alone. "Not yet returned" = no expected_return_date set,
 * or that date hasn't passed yet.
 */
function t8_reservation_committed_quantity(PDO $pdo, int $facilityId, ?int $excludeId = null): int
{
    $sql = "SELECT COALESCE(SUM(quantity), 0) FROM team8_reservations
            WHERE facility_id = :facility_id
              AND status IN ('pending', 'approved')
              AND quantity IS NOT NULL
              AND (expected_return_date IS NULL OR expected_return_date >= CURDATE())";
    $params = ['facility_id' => $facilityId];
    if ($excludeId !== null) {
        $sql .= ' AND id != :exclude_id';
        $params['exclude_id'] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** Annotates a list of reservation rows in-place with a 'has_conflict' bool. */
function t8_reservations_annotate_conflicts(PDO $pdo, array $rows): array
{
    $now = time();
    foreach ($rows as &$row) {
        $start = isset($row['start_time']) && $row['start_time'] !== '' ? strtotime((string) $row['start_time']) : false;
        $end = isset($row['end_time']) && $row['end_time'] !== '' ? strtotime((string) $row['end_time']) : false;

        // Conflicts apply only to approved reservations that have not ended
        // (both upcoming and ongoing bookings).
        if (($row['status'] ?? '') === 'approved'
            && $start !== false
            && $end !== false
            && $end >= $now) {
            $row['has_conflict'] = t8_reservation_has_conflict(
                $pdo,
                (int) $row['facility_id'],
                (string) $row['start_time'],
                (string) $row['end_time'],
                isset($row['id']) ? (int) $row['id'] : null
            );
        } else {
            $row['has_conflict'] = false;
        }
    }
    unset($row);
    return $rows;
}

/**
 * FIX (staff cancel window): true once a reservation's start (or, for
 * types with no start_time, its schedule datetime) has already
 * passed. Equipment/Asset reservations (no start_time/schedule, only
 * a return date) are never considered "started" by this check.
 */
function t8_reservation_has_started(array $reservation): bool
{
    $reference = $reservation['start_time'] ?: ($reservation['schedule'] ?? null);
    if ($reference === null || $reference === '') {
        return false;
    }
    $ts = strtotime((string) $reference);
    return $ts !== false && $ts <= time();
}

/** Return the booking's required future reference, independent of its type. */
function t8_reservation_schedule_reference(array $reservation): ?string
{
    foreach (['start_time', 'schedule', 'expected_return_date'] as $field) {
        $value = trim((string) ($reservation[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return null;
}

/** Whether the booking still represents a usable future reservation. */
function t8_reservation_is_future(array $reservation): bool
{
    $reference = t8_reservation_schedule_reference($reservation);
    if ($reference === null) {
        return false;
    }
    $timestamp = strtotime($reference);
    return $timestamp !== false && $timestamp > time();
}

/**
 * Apply lifecycle transitions before processing actions. Pending bookings
 * whose scheduled start has passed are expired; approved bookings whose end
 * time has passed are completed. Each transition has its own audit record.
 */
function t8_refresh_reservation_booking_statuses(PDO $pdo, int $actorId): void
{
        $expiredRows = $pdo->query(
                "SELECT id, status FROM team8_reservations
          WHERE status IN ('pending', 'cancellation_pending')
            AND COALESCE(start_time, schedule, expected_return_date) IS NOT NULL
            AND COALESCE(start_time, schedule, expected_return_date) < NOW()"
        )->fetchAll(PDO::FETCH_ASSOC);
    if ($expiredRows !== []) {
        $pdo->query(
            "UPDATE team8_reservations SET status = 'expired', archived_at = COALESCE(archived_at, NOW())
              WHERE status IN ('pending', 'cancellation_pending')
                AND COALESCE(start_time, schedule, expected_return_date) IS NOT NULL
                AND COALESCE(start_time, schedule, expected_return_date) < NOW()"
        );
        foreach ($expiredRows as $row) {
            t8_audit_log($pdo, $actorId, 'reservation', (int) $row['id'], 'expired', (string) $row['status'], 'scheduled date/time passed');
        }
    }

    $completedIds = $pdo->query(
        "SELECT id FROM team8_reservations
          WHERE status = 'approved'
            AND COALESCE(end_time, schedule, expected_return_date) IS NOT NULL
            AND COALESCE(end_time, schedule, expected_return_date) < NOW()"
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($completedIds !== []) {
        $pdo->query(
            "UPDATE team8_reservations SET status = 'completed', archived_at = COALESCE(archived_at, NOW())
              WHERE status = 'approved'
                AND COALESCE(end_time, schedule, expected_return_date) IS NOT NULL
                AND COALESCE(end_time, schedule, expected_return_date) < NOW()"
        );
        foreach ($completedIds as $id) {
            t8_audit_log($pdo, $actorId, 'reservation', (int) $id, 'completed', 'approved', 'scheduled end passed');
        }
    }
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

/** Return the config for a facility type, or an empty config if it is unknown. */
function t8_reservation_get_facility_type_config(string $facilityType): array
{
    return T8_FACILITY_RESERVATION_CONFIG[$facilityType] ?? [
        'event_categories' => [],
        'visible_fields' => [],
        'required_fields' => [],
    ];
}

/** Resolve a facility type from the selected facility id. */
function t8_reservation_detect_facility_type(array $activeFacilities, string $facilityId): string
{
    foreach ($activeFacilities as $facility) {
        if ((string) $facility['id'] === $facilityId) {
            return (string) ($facility['facility_type'] ?? '');
        }
    }
    return '';
}

/** Extract a return date stored alongside notes for equipment/asset reservations. */
function t8_reservation_extract_return_date(string $description): string
{
    if (preg_match('/Return Date:\s*(\d{4}-\d{2}-\d{2})/i', $description, $matches)) {
        return $matches[1];
    }
    return '';
}

/** Extract notes from the description field, if any. */
function t8_reservation_extract_notes(string $description): string
{
    $description = trim($description);
    if (preg_match('/^Remarks:\s*(.+)$/i', $description, $matches)) {
        return '';
    }
    if (preg_match('/\bNotes:\s*(.+)$/i', $description, $matches)) {
        return trim($matches[1]);
    }
    return trim(preg_replace('/\s*Return Date:\s*\d{4}-\d{2}-\d{2}\s*/i', '', $description));
}

/** Extract remarks from the description field, if any. */
function t8_reservation_extract_remarks(string $description): string
{
    $description = trim($description);
    if (preg_match('/^Remarks:\s*(.+)$/i', $description, $matches)) {
        return trim($matches[1]);
    }
    if (preg_match('/\bRemarks:\s*(.+)$/i', $description, $matches)) {
        return trim($matches[1]);
    }
    return '';
}

/** Normalize posted reservation values shared by create and edit. */
function t8_reservation_form_values(array $source): array
{
    return [
        'facility_id' => (string) ($source['facility_id'] ?? ''),
        'start_time' => t8_normalize_datetime((string) ($source['start_time'] ?? '')),
        'end_time' => t8_normalize_datetime((string) ($source['end_time'] ?? '')),
        'department' => trim((string) ($source['department'] ?? '')),
        'key_person' => trim((string) ($source['key_person'] ?? '')),
        'expected_participants' => trim((string) ($source['expected_participants'] ?? '')),
        'quantity' => trim((string) ($source['quantity'] ?? '')),
        'event_category' => trim((string) ($source['event_category'] ?? '')),
        'description' => trim((string) ($source['description'] ?? '')),
        'return_date' => trim((string) ($source['return_date'] ?? '')),
        'remarks' => trim((string) ($source['remarks'] ?? '')),
        'schedule' => t8_normalize_datetime((string) ($source['schedule'] ?? '')),
        'requirements' => trim((string) ($source['requirements'] ?? '')),
    ];
}

/**
 * Shared create/edit field validation. Returns an errors array.
 *
 * FIX (empty required fields): every branch below now pushes a
 * specific, human-readable message instead of silently passing, and
 * every field that can be required is checked here regardless of
 * whether client-side JS also checked it - the server is always the
 * source of truth, and $errors is always rendered as alerts above
 * the form (see the markup below).
 *
 * FIX (quantity/capacity): for the 'quantity' field specifically (Equipment/
 * Asset facility types), the request is checked against how much of
 * the facility's capacity is still AVAILABLE - i.e. capacity minus
 * whatever is already committed to other pending/approved, not-yet-
 * returned reservations - not just the facility's total capacity.
 */
function t8_reservation_validate(PDO $pdo, array $activeFacilities, array $values, string $facilityType, ?int $excludeId = null): array
{
    $errors = [];
    $validFacility = false;
    foreach ($activeFacilities as $f) {
        if ((int) $f['id'] === (int) $values['facility_id']) {
            $validFacility = true;
            break;
        }
    }
    if (!$validFacility) {
        $errors[] = 'Please select a valid, active facility.';
    }

    $config = t8_reservation_get_facility_type_config($facilityType);
    $requiredFields = $config['required_fields'] ?? [];
    if ($facilityType === '' || $config['event_categories'] === []) {
        $errors[] = 'The selected facility does not have a supported reservation type.';
    }

    if (in_array('time_range', $requiredFields, true)) {
        if ($values['start_time'] === '' || $values['end_time'] === '') {
            $errors[] = 'Start and end time are both required.';
        } elseif (strtotime($values['start_time']) === false || strtotime($values['end_time']) === false) {
            $errors[] = 'Start and end time must be valid dates/times.';
        } elseif (strtotime($values['start_time']) >= strtotime($values['end_time'])) {
            $errors[] = 'End time must be after start time.';
        } elseif (strtotime($values['start_time']) <= time()) {
            $errors[] = 'This reservation date and time has already passed. Please select a future schedule.';
        } elseif ($validFacility && t8_reservation_has_conflict($pdo, (int) $values['facility_id'], $values['start_time'], $values['end_time'], $excludeId)) {
            $errors[] = 'This facility already has an overlapping reservation. Please select a different schedule.';
        }
    }

    foreach (['participants' => 'expected_participants', 'quantity' => 'quantity'] as $field => $valueKey) {
        if (!in_array($field, $requiredFields, true) && $values[$valueKey] === '') {
            continue;
        }
        if ($values[$valueKey] === '') {
            $errors[] = in_array('quantity', $requiredFields, true)
                ? 'Quantity is required for this reservation type.'
                : 'Participants are required for this reservation type.';
        } elseif (!ctype_digit($values[$valueKey]) || (int) $values[$valueKey] < 1) {
            $errors[] = in_array('quantity', $requiredFields, true)
                ? 'Quantity must be a positive whole number.'
                : 'Participants must be a positive whole number.';
        } elseif ($field === 'quantity' && (int) $values[$valueKey] > 3) {
            $errors[] = 'Equipment borrowing is limited to 3 units per request.';
        } else {
            foreach ($activeFacilities as $f) {
                if ((int) $f['id'] === (int) $values['facility_id']) {
                    if ((int) $values[$valueKey] > (int) $f['capacity']) {
                        $errors[] = ($field === 'quantity' ? 'The selected quantity' : 'Participants') . ' cannot exceed the selected facility capacity (' . e((string) $f['capacity']) . ').';
                    } elseif ($field === 'quantity') {
                        $committed = t8_reservation_committed_quantity($pdo, (int) $f['id'], $excludeId);
                        $available = max(0, (int) $f['capacity'] - $committed);
                        if ((int) $values[$valueKey] > $available) {
                            $errors[] = 'Only ' . $available . ' out of ' . (int) $f['capacity']
                                . ' unit(s) of "' . $f['name'] . '" are currently available - the rest are already committed to other pending or approved reservations.';
                        }
                    }
                    break;
                }
            }
        }
    }

    if (in_array('return_date', $requiredFields, true)) {
        if ($values['return_date'] === '') {
            $errors[] = 'Expected return date is required.';
        } elseif (strtotime($values['return_date']) === false) {
            $errors[] = 'Expected return date must be a valid date.';
        } elseif (strtotime($values['return_date']) < strtotime('today')) {
            $errors[] = 'Expected return date cannot be in the past.';
        }
    }

    if (in_array('remarks', $requiredFields, true)) {
        if ($values['remarks'] === '') {
            $errors[] = 'Remarks are required.';
        }
    }

    if (in_array('schedule', $requiredFields, true)) {
        if ($values['schedule'] === '') {
            $errors[] = 'Schedule is required.';
        } elseif (strtotime($values['schedule']) === false) {
            $errors[] = 'Schedule must be a valid date/time.';
        } elseif (strtotime($values['schedule']) <= time()) {
            $errors[] = 'This reservation date and time has already passed. Please select a future schedule.';
        }
    }

    if (in_array('requirements', $requiredFields, true) && $values['requirements'] === '') {
        $errors[] = 'Requirements are required.';
    }
    if ($values['department'] === '') {
        $errors[] = 'Department is required.';
    }
    if ($values['key_person'] === '') {
        $errors[] = 'Key person / point of contact is required.';
    }
    $eventCategories = $config['event_categories'] ?? [];
    if ($values['event_category'] !== '' && $eventCategories !== [] && !in_array($values['event_category'], $eventCategories, true)) {
        $errors[] = 'Please select a valid event category for the chosen facility type.';
    } elseif ($values['event_category'] === '' && $eventCategories !== []) {
        $errors[] = 'Please select an event category.';
    }
    return $errors;
}

$activeFacilities = $pdo->query(
    "SELECT id, name, location, facility_type, capacity FROM team8_facilities
     WHERE status = 'active' AND COALESCE(maintenance_status, 'operational') <> 'maintenance'
     ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);
$hasActiveFacilities = $activeFacilities !== [];

// Lifecycle checks happen before create, edit, approval, cancellation, and
// list actions. This makes direct requests subject to the same rules as UI.
t8_refresh_reservation_booking_statuses($pdo, (int) $currentUserId);

$formValues = [
    'facility_id'           => '',
    'start_time'            => '',
    'end_time'              => '',
    'department'            => '',
    'key_person'            => '',
    'expected_participants' => '',
    'quantity'              => '',
    'event_category'        => '',
    'description'           => '',
    'return_date'           => '',
    'remarks'               => '',
    'schedule'              => '',
    'requirements'          => '',
];
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['facility_id'])) {
    $formValues['facility_id'] = (string) $_GET['facility_id'];
}

/** Return the compact, type-aware label used in reservation list tables. */
function t8_reservation_summary(array $reservation): array
{
    $category = trim((string) ($reservation['event_category'] ?? ''));
    $type = trim((string) ($reservation['facility_type'] ?? ''));
    $detail = '';

    if (in_array($type, ['Equipment', 'Asset'], true) && !empty($reservation['quantity'])) {
        $detail = 'Qty: ' . (int) $reservation['quantity'];
    } elseif (isset($reservation['facility_capacity']) && $reservation['facility_capacity'] !== '') {
        $detail = 'Cap: ' . (int) $reservation['facility_capacity'];
    }

    return [
        'category' => $category !== '' ? $category : 'Reservation',
        'detail' => $detail,
    ];
}

/** Return the two-line schedule representation for a reservation list table. */
function t8_reservation_schedule(array $reservation): array
{
    $start = (string) ($reservation['start_time'] ?? '');
    $end = (string) ($reservation['end_time'] ?? '');
    if ($start !== '' && $end !== '') {
        return [
            'primary' => format_date($start, 'M d, Y'),
            'secondary' => format_date($start, 'g:i A') . ' – ' . format_date($end, 'g:i A'),
        ];
    }

    $schedule = (string) ($reservation['schedule'] ?? '');
    if ($schedule !== '') {
        return ['primary' => format_date($schedule, 'M d, Y'), 'secondary' => format_date($schedule, 'g:i A')];
    }

    $returnDate = (string) ($reservation['expected_return_date'] ?? '');
    if ($returnDate !== '') {
        return ['primary' => 'Return by ' . format_date($returnDate, 'M d, Y'), 'secondary' => ''];
    }

    return ['primary' => 'N/A', 'secondary' => ''];
}

/** Date used by the client-side month/year reservation filters. */
function t8_reservation_filter_date(array $reservation): string
{
    $date = (string) ($reservation['start_time'] ?? $reservation['schedule'] ?? '');
    $timestamp = strtotime($date);
    return $timestamp === false ? '' : date('Y-m-d', $timestamp);
}

/** Build a safe pagination URL while preserving reservation filters. */
function t8_reservation_page_url(int $page, array $filters, string $pageKey = 'page'): string
{
    return page_url('reservation', array_filter($filters + [$pageKey => $page], static fn ($value) => $value !== '' && $value !== null));
}

/** Render compact pagination controls for a reservation table. */
function t8_reservation_pagination(int $page, int $totalPages, array $filters, string $pageKey = 'page'): void
{
    if ($totalPages < 2) {
        return;
    }
    echo '<nav class="t8-pagination" aria-label="Reservation pages">';
    if ($page > 1) {
        echo '<a class="t8-btn t8-btn-outline t8-btn-sm" href="' . e(t8_reservation_page_url($page - 1, $filters, $pageKey)) . '">Previous</a>';
    }
    echo '<span class="t8-help-text">Page ' . e((string) $page) . ' of ' . e((string) $totalPages) . '</span>';
    if ($page < $totalPages) {
        echo '<a class="t8-btn t8-btn-outline t8-btn-sm" href="' . e(t8_reservation_page_url($page + 1, $filters, $pageKey)) . '">Next</a>';
    }
    echo '</nav>';
}

switch ($action) {
    case 'create':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $formValues = t8_reservation_form_values($_POST);

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } elseif (!$hasActiveFacilities) {
                $errors[] = 'No active facilities are available to reserve right now.';
            } else {
                $facilityId = (int) $formValues['facility_id'];
                $facilityType = t8_reservation_detect_facility_type($activeFacilities, $formValues['facility_id']);
                $config = t8_reservation_get_facility_type_config($facilityType);
                $visibleFields = $config['visible_fields'];
                foreach (['expected_participants' => 'participants', 'quantity' => 'quantity', 'return_date' => 'return_date', 'remarks' => 'remarks', 'schedule' => 'schedule', 'requirements' => 'requirements', 'start_time' => 'time_range', 'end_time' => 'time_range'] as $key => $field) {
                    if (!in_array($field, $visibleFields, true)) {
                        $formValues[$key] = '';
                    }
                }
                if ($formValues['quantity'] !== '' && $formValues['return_date'] === '' && in_array($facilityType, ['Equipment', 'Asset'], true)) {
                    $formValues['return_date'] = date('Y-m-d');
                }
                $errors = t8_reservation_validate($pdo, $activeFacilities, $formValues, $facilityType);
                $participants = $formValues['expected_participants'] !== '' ? (int) $formValues['expected_participants'] : null;
                $quantity = $formValues['quantity'] !== '' ? (int) $formValues['quantity'] : null;

                if (!$errors) {
                    // Treat all created reservations as requests to be approved.
                    // Administrators no longer auto-approve upon creation.
                    $status = 'pending';
                    $stmt = $pdo->prepare(
                        'INSERT INTO team8_reservations
                            (facility_id, user_id, start_time, end_time, status, department, key_person, expected_participants, quantity, event_category, description, expected_return_date, remarks, schedule, requirements)
                         VALUES
                            (:facility_id, :user_id, :start_time, :end_time, :status, :department, :key_person, :expected_participants, :quantity, :event_category, :description, :return_date, :remarks, :schedule, :requirements)'
                    );
                    $stmt->execute([
                        'facility_id'           => $facilityId,
                        'user_id'               => $currentUserId,
                        'start_time'            => $formValues['start_time'] !== '' ? $formValues['start_time'] : null,
                        'end_time'              => $formValues['end_time'] !== '' ? $formValues['end_time'] : null,
                        'status'                => $status,
                        'department'            => $formValues['department'],
                        'key_person'            => $formValues['key_person'],
                        'expected_participants' => $participants,
                        'quantity'              => $quantity,
                        'event_category'        => $formValues['event_category'],
                        'description'           => $formValues['description'] !== '' ? $formValues['description'] : null,
                        'return_date'           => $formValues['return_date'] !== '' ? $formValues['return_date'] : null,
                        'remarks'               => $formValues['remarks'] !== '' ? $formValues['remarks'] : null,
                        'schedule'              => $formValues['schedule'] !== '' ? $formValues['schedule'] : null,
                        'requirements'          => $formValues['requirements'] !== '' ? $formValues['requirements'] : null,
                    ]);
                    $newId = (int) $pdo->lastInsertId();

                    // Log creation and notify the user that the request is pending.
                    t8_audit_log($pdo, $currentUserId, 'reservation', $newId, 'create');
                    t8_flash_set('success', 'Reservation request submitted for approval.');

                    redirect(page_url('reservation'));
                }
            }
        }
        break;

    case 'edit':
        $id = (int) ($_GET['id'] ?? 0);
        $existing = $id ? t8_reservation_fetch($pdo, $id) : null;
        if (!$existing
            || (!$isAdmin && (int) $existing['user_id'] !== $currentUserId)
            || (!$isAdmin && $existing['status'] !== 'pending')
            || ($isAdmin && !in_array($existing['status'], ['pending', 'approved'], true))) {
            t8_flash_set('danger', "That reservation can't be edited.");
            redirect(page_url('reservation'));
        }

        $formValues = t8_reservation_form_values([
            'facility_id'           => (string) $existing['facility_id'],
            'start_time'            => (string) $existing['start_time'],
            'end_time'              => (string) $existing['end_time'],
            'department'            => (string) ($existing['department'] ?? ''),
            'key_person'            => (string) ($existing['key_person'] ?? ''),
            'expected_participants' => (string) ($existing['expected_participants'] ?? ''),
            'quantity'              => (string) ($existing['quantity'] ?? ''),
            'event_category'        => (string) ($existing['event_category'] ?? ''),
            'description'           => t8_reservation_extract_notes((string) ($existing['description'] ?? '')),
            'return_date'           => (string) ($existing['expected_return_date'] ?? t8_reservation_extract_return_date((string) ($existing['description'] ?? ''))),
            'remarks'               => (string) ($existing['remarks'] ?? t8_reservation_extract_remarks((string) ($existing['description'] ?? ''))),
            'schedule'              => (string) ($existing['schedule'] ?? ''),
            'requirements'          => (string) ($existing['requirements'] ?? ''),
        ]);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $formValues = t8_reservation_form_values($_POST);

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                $facilityId = (int) $formValues['facility_id'];
                $facilityType = t8_reservation_detect_facility_type($activeFacilities, $formValues['facility_id']);
                $config = t8_reservation_get_facility_type_config($facilityType);
                $visibleFields = $config['visible_fields'];
                foreach (['expected_participants' => 'participants', 'quantity' => 'quantity', 'return_date' => 'return_date', 'remarks' => 'remarks', 'schedule' => 'schedule', 'requirements' => 'requirements', 'start_time' => 'time_range', 'end_time' => 'time_range'] as $key => $field) {
                    if (!in_array($field, $visibleFields, true)) {
                        $formValues[$key] = '';
                    }
                }
                if ($formValues['quantity'] !== '' && $formValues['return_date'] === '' && in_array($facilityType, ['Equipment', 'Asset'], true)) {
                    $formValues['return_date'] = date('Y-m-d');
                }
                $errors = t8_reservation_validate($pdo, $activeFacilities, $formValues, $facilityType, $id);
                $participants = $formValues['expected_participants'] !== '' ? (int) $formValues['expected_participants'] : null;
                $quantity = $formValues['quantity'] !== '' ? (int) $formValues['quantity'] : null;

                if (!$errors) {
                    $pdo->prepare(
                        'UPDATE team8_reservations SET
                            facility_id = :facility_id, start_time = :start_time, end_time = :end_time,
                            department = :department, key_person = :key_person,
                            expected_participants = :expected_participants, quantity = :quantity, event_category = :event_category,
                            description = :description, expected_return_date = :return_date, remarks = :remarks,
                            schedule = :schedule, requirements = :requirements
                         WHERE id = :id'
                    )->execute([
                        'facility_id'           => $facilityId,
                        'start_time'            => $formValues['start_time'] !== '' ? $formValues['start_time'] : null,
                        'end_time'              => $formValues['end_time'] !== '' ? $formValues['end_time'] : null,
                        'department'            => $formValues['department'],
                        'key_person'            => $formValues['key_person'],
                        'expected_participants' => $participants,
                        'quantity'              => $quantity,
                        'event_category'        => $formValues['event_category'],
                        'description'           => $formValues['description'] !== '' ? $formValues['description'] : null,
                        'return_date'           => $formValues['return_date'] !== '' ? $formValues['return_date'] : null,
                        'remarks'               => $formValues['remarks'] !== '' ? $formValues['remarks'] : null,
                        'schedule'              => $formValues['schedule'] !== '' ? $formValues['schedule'] : null,
                        'requirements'          => $formValues['requirements'] !== '' ? $formValues['requirements'] : null,
                        'id'                    => $id,
                    ]);
                    $auditAction = $isAdmin && $existing['status'] === 'approved' ? 'reschedule' : 'update';
                    t8_audit_log($pdo, $currentUserId, 'reservation', $id, $auditAction);
                    t8_flash_set('success', $auditAction === 'reschedule' ? 'Reservation rescheduled.' : 'Reservation updated.');
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
        if (!$target || !in_array($target['status'], ['pending', 'approved', 'cancellation_pending'], true)) {
            t8_flash_set('danger', "That reservation can't be cancelled.");
        } elseif ($isAdmin) {
            $pdo->prepare("UPDATE team8_reservations
                           SET status = 'cancelled', archived_at = NOW(), cancellation_decision = 'admin_cancelled', cancellation_reviewed_by = :admin_id, cancellation_reviewed_at = NOW()
                           WHERE id = :id")
                ->execute(['admin_id' => $currentUserId, 'id' => $id]);
            $pdo->prepare("UPDATE team8_reservation_cancellation_requests
                           SET status = 'approved', reviewed_by = :admin_id, reviewed_at = NOW(), admin_remark = 'Cancelled by administrator'
                           WHERE reservation_id = :id AND status = 'pending'")
                ->execute(['admin_id' => $currentUserId, 'id' => $id]);
            t8_audit_log($pdo, $currentUserId, 'reservation', $id, 'admin_cancel');
            t8_flash_set('success', 'Reservation cancelled and moved to Archive.');
        } elseif ((int) $target['user_id'] === $currentUserId && $target['status'] === 'pending') {
            $pdo->prepare("UPDATE team8_reservations
                           SET status = 'cancelled', archived_at = NOW()
                           WHERE id = :id")
                ->execute(['id' => $id]);
            t8_audit_log($pdo, $currentUserId, 'reservation', $id, 'cancel');
            t8_flash_set('success', 'Reservation cancelled.');
        } elseif ((int) $target['user_id'] === $currentUserId && $target['status'] === 'approved') {
            // FIX (cancel button in staff "All Reservations"): Facilities
            // Staff can now REQUEST cancellation of any approved
            // reservation they can see in All Reservations, not just
            // their own — the request still always goes to an
            // Administrator for review/approval below, it is never an
            // immediate cancel.
            //
            // FIX (cancel only if not started): staff may only request
            // cancellation of an APPROVED reservation while it is still
            // in the future. Once its start (or schedule) time has
            // passed, it must go through an administrator instead.
            if (t8_reservation_has_started($target)) {
                t8_flash_set('danger', 'This reservation has already started and can no longer be cancelled by staff. Please contact an administrator.');
            } else {
                $reason = trim((string) ($_POST['cancellation_reason'] ?? ''));
                if ($reason === '') {
                    t8_flash_set('danger', 'A reason for cancellation is required.');
                } else {
                    $pdo->prepare("UPDATE team8_reservations
                                   SET status = 'cancellation_pending', cancellation_reason = :reason,
                                       cancellation_requested_by = :user_id, cancellation_requested_at = NOW(), cancellation_decision = 'pending'
                                   WHERE id = :id")
                        ->execute(['reason' => $reason, 'user_id' => $currentUserId, 'id' => $id]);
                    $requestStmt = $pdo->prepare(
                        "INSERT INTO team8_reservation_cancellation_requests (reservation_id, requested_by, reason, status)
                         VALUES (:reservation_id, :requested_by, :reason, 'pending')"
                    );
                    $requestStmt->execute(['reservation_id' => $id, 'requested_by' => $currentUserId, 'reason' => $reason]);
                    t8_audit_log($pdo, $currentUserId, 'reservation', $id, 'cancellation_request', 'approved', $reason);
                    t8_flash_set('success', 'Cancellation request sent to an administrator for review.');
                }
            }
        } else {
            t8_flash_set('danger', "That reservation can't be cancelled.");
        }
        redirect(page_url('reservation'));
        break;

    case 'review_cancellation':
        t8_require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('reservation'));
        }
        $id = (int) ($_POST['id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        $target = t8_reservation_fetch($pdo, $id);
        if (!$target || $target['status'] !== 'cancellation_pending' || !in_array($decision, ['approved', 'rejected'], true)) {
            t8_flash_set('danger', 'That cancellation request is no longer available for review.');
        } elseif ($decision === 'approved') {
            $pdo->prepare("UPDATE team8_reservations
                           SET status = 'cancelled', archived_at = NOW(), cancellation_decision = 'approved',
                               cancellation_reviewed_by = :admin_id, cancellation_reviewed_at = NOW()
                           WHERE id = :id")
                ->execute(['admin_id' => $currentUserId, 'id' => $id]);
            $pdo->prepare("UPDATE team8_reservation_cancellation_requests
                           SET status = 'approved', reviewed_by = :admin_id, reviewed_at = NOW()
                           WHERE reservation_id = :id AND status = 'pending'")
                ->execute(['admin_id' => $currentUserId, 'id' => $id]);
            t8_audit_log($pdo, $currentUserId, 'reservation', $id, 'cancellation_approved', 'cancellation_pending', (string) ($target['cancellation_reason'] ?? ''));
            t8_flash_set('success', 'Cancellation approved. Reservation moved to Archive.');
        } else {
            $pdo->prepare("UPDATE team8_reservations
                           SET status = 'approved', cancellation_decision = 'rejected',
                               cancellation_reviewed_by = :admin_id, cancellation_reviewed_at = NOW()
                           WHERE id = :id")
                ->execute(['admin_id' => $currentUserId, 'id' => $id]);
            $pdo->prepare("UPDATE team8_reservation_cancellation_requests
                           SET status = 'rejected', reviewed_by = :admin_id, reviewed_at = NOW()
                           WHERE reservation_id = :id AND status = 'pending'")
                ->execute(['admin_id' => $currentUserId, 'id' => $id]);
            t8_audit_log($pdo, $currentUserId, 'reservation', $id, 'cancellation_rejected', 'cancellation_pending', (string) ($target['cancellation_reason'] ?? ''));
            t8_flash_set('success', 'Cancellation request rejected. Reservation remains active.');
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
                $pdo->prepare('DELETE FROM team8_reservation_cancellation_requests WHERE reservation_id = :id')
                    ->execute(['id' => $id]);
                $pdo->prepare('DELETE FROM team8_reservation_equipment WHERE reservation_id = :id')
                    ->execute(['id' => $id]);
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
        if ($target && $target['status'] === 'pending' && t8_reservation_is_future($target)) {
            if ($target['start_time'] && $target['end_time'] && t8_reservation_has_conflict($pdo, (int) $target['facility_id'], (string) $target['start_time'], (string) $target['end_time'], $id)) {
                t8_flash_set('danger', 'This facility already has an overlapping reservation. Please reschedule before approval.');
                redirect(page_url('reservation'));
            }
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
            if ($target && $target['status'] === 'pending') {
                $pdo->prepare("UPDATE team8_reservations SET status = 'expired', archived_at = COALESCE(archived_at, NOW()) WHERE id = :id AND status = 'pending'")
                    ->execute(['id' => $id]);
                t8_audit_log($pdo, $currentUserId, 'reservation', $id, 'expired', 'pending', 'scheduled date/time passed');
                t8_flash_set('danger', 'This reservation date and time has already passed. Please select a future schedule.');
            } else {
                t8_flash_set('danger', 'That reservation is no longer pending.');
            }
        }
        redirect(page_url('reservation'));
        break;
}

$showForm = in_array($action, ['create', 'edit'], true);
$showArchive = $isAdmin && $action === 'archive';
$selectedFacilityType = t8_reservation_detect_facility_type($activeFacilities, $formValues['facility_id']);
$selectedReservationConfig = t8_reservation_get_facility_type_config($selectedFacilityType);

// ---- Data for the list view ----
if (!$showForm) {
    $reservationPageSize = 15;
    $reservationFacilityFilter = (int) ($_GET['facility'] ?? 0);
    $reservationTypeFilter = trim((string) ($_GET['type'] ?? ''));
    $reservationStatusFilter = trim((string) ($_GET['status'] ?? ''));
    $reservationFilters = [
        'facility' => $reservationFacilityFilter > 0 ? $reservationFacilityFilter : '',
        'type' => $reservationTypeFilter,
        'status' => $reservationStatusFilter,
    ];

    // Completed approved bookings are retained for audit purposes with an
    // explicit final status and are no longer part of active reservation lists.
    //
    // FIX (Equipment/Asset missing from lists): this used to key off
    // COALESCE(end_time, schedule) only, which is NULL for Equipment/
    // Asset reservations (they only ever set expected_return_date) -
    // so those rows could never "complete" or move to the Archive.
    // expected_return_date is now included in the COALESCE chain
    // everywhere a completion/active-window comparison happens below.
    //
    // DASHBOARD UPDATE: this used to be a single bulk UPDATE with no
    // audit trail, so "how many reservations were completed this
    // month" had no source of truth for the new Reservation Activity
    // card on the dashboard. It now fetches the affected ids first,
    // writes one 'completed' audit_logs entry per reservation, then
    // performs the same bulk UPDATE. Known limitation: this only runs
    // when someone views the Reservation module, so a reservation
    // "completes" (for activity-counting purposes) at the next visit
    // to this page after its end time passes, not the instant it
    // passes — acceptable for a dashboard trend, flagged here rather
    // than silently assumed.
    $justCompletedIds = $pdo->query(
        "SELECT id FROM team8_reservations
          WHERE status = 'approved'
            AND COALESCE(end_time, schedule, expected_return_date) IS NOT NULL
            AND COALESCE(end_time, schedule, expected_return_date) < NOW()"
    )->fetchAll(PDO::FETCH_COLUMN);

    if ($justCompletedIds !== []) {
        foreach ($justCompletedIds as $completedId) {
            t8_audit_log($pdo, $currentUserId, 'reservation', (int) $completedId, 'completed');
        }
        $pdo->query(
            "UPDATE team8_reservations
              SET status = 'completed', archived_at = COALESCE(archived_at, NOW())
             WHERE status = 'approved'
               AND COALESCE(end_time, schedule, expected_return_date) IS NOT NULL
               AND COALESCE(end_time, schedule, expected_return_date) < NOW()"
        );
    }

    if ($isAdmin) {
        $allWhere = [
            "r.status IN ('approved', 'cancellation_pending')",
            'r.archived_at IS NULL',
            'COALESCE(r.end_time, r.schedule, r.expected_return_date) >= NOW()',
        ];
        $allParams = [];
        if ($reservationFacilityFilter > 0) {
            $allWhere[] = 'r.facility_id = :filter_facility';
            $allParams['filter_facility'] = $reservationFacilityFilter;
        }
        if ($reservationTypeFilter !== '') {
            $allWhere[] = 'f.facility_type = :filter_type';
            $allParams['filter_type'] = $reservationTypeFilter;
        }
        if ($reservationStatusFilter !== '' && in_array($reservationStatusFilter, ['approved', 'cancellation_pending'], true)) {
            $allWhere[] = 'r.status = :filter_status';
            $allParams['filter_status'] = $reservationStatusFilter;
        }
        $allWhereSql = implode(' AND ', $allWhere);
        $allCountStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM team8_reservations r
             JOIN team8_facilities f ON f.id = r.facility_id
             WHERE {$allWhereSql}"
        );
        $allCountStmt->execute($allParams);
        $allTotal = (int) $allCountStmt->fetchColumn();
        $allTotalPages = max(1, (int) ceil($allTotal / $reservationPageSize));
        $allPage = min(max(1, (int) ($_GET['page'] ?? 1)), $allTotalPages);
        $allOffset = ($allPage - 1) * $reservationPageSize;
        $allStmt = $pdo->prepare(
            "SELECT r.*, f.name AS facility_name, f.facility_type, f.capacity AS facility_capacity, u.full_name AS requester_name
             FROM team8_reservations r
             JOIN team8_facilities f ON f.id = r.facility_id
             JOIN users u ON u.id = r.user_id
             WHERE {$allWhereSql}
             ORDER BY COALESCE(r.start_time, r.schedule, r.created_at) ASC, r.id ASC
             LIMIT {$reservationPageSize} OFFSET {$allOffset}"
        );
        $allStmt->execute($allParams);
        $allReservations = $allStmt->fetchAll(PDO::FETCH_ASSOC);
        $allReservations = t8_reservations_annotate_conflicts($pdo, $allReservations);

        $archivedReservations = $pdo->query(
            "SELECT r.*, f.name AS facility_name, f.facility_type, f.capacity AS facility_capacity, u.full_name AS requester_name
             FROM team8_reservations r
             JOIN team8_facilities f ON f.id = r.facility_id
             JOIN users u ON u.id = r.user_id
             WHERE r.archived_at IS NOT NULL
             ORDER BY COALESCE(r.end_time, r.schedule, r.expected_return_date) DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        // Lists EVERY reservation currently awaiting approval - nothing
        // filters this further, so it's always the complete pending set.
        $pendingReservations = $pdo->query(
            "SELECT r.*, f.name AS facility_name, f.facility_type, f.capacity AS facility_capacity, u.full_name AS requester_name
             FROM team8_reservations r
             JOIN team8_facilities f ON f.id = r.facility_id
             JOIN users u ON u.id = r.user_id
             WHERE r.status = 'pending'
             ORDER BY r.start_time ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $pendingReservations = t8_reservations_annotate_conflicts($pdo, $pendingReservations);

        $cancellationRequests = $pdo->query(
            "SELECT r.*, f.name AS facility_name, f.capacity AS facility_capacity, u.full_name AS requester_name
             FROM team8_reservations r
             JOIN team8_facilities f ON f.id = r.facility_id
             JOIN users u ON u.id = r.user_id
             WHERE r.status = 'cancellation_pending'
             ORDER BY r.cancellation_requested_at ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $allWhere = [
            "r.status = 'approved'",
            'r.archived_at IS NULL',
            'COALESCE(r.end_time, r.schedule, r.expected_return_date) >= NOW()',
        ];
        $allParams = [];
        if ($reservationFacilityFilter > 0) {
            $allWhere[] = 'r.facility_id = :filter_facility';
            $allParams['filter_facility'] = $reservationFacilityFilter;
        }
        if ($reservationTypeFilter !== '') {
            $allWhere[] = 'f.facility_type = :filter_type';
            $allParams['filter_type'] = $reservationTypeFilter;
        }
        $allWhereSql = implode(' AND ', $allWhere);
        $allCountStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM team8_reservations r JOIN team8_facilities f ON f.id = r.facility_id WHERE {$allWhereSql}"
        );
        $allCountStmt->execute($allParams);
        $allTotal = (int) $allCountStmt->fetchColumn();
        $allTotalPages = max(1, (int) ceil($allTotal / $reservationPageSize));
        $allPage = min(max(1, (int) ($_GET['page'] ?? 1)), $allTotalPages);
        $allOffset = ($allPage - 1) * $reservationPageSize;
        $allReservationsStmt = $pdo->prepare(
            "SELECT r.id, r.facility_id, r.user_id, r.start_time, r.end_time, r.schedule, r.expected_return_date, r.status, f.name AS facility_name, f.facility_type, f.capacity AS facility_capacity
             FROM team8_reservations r
             JOIN team8_facilities f ON f.id = r.facility_id
             WHERE {$allWhereSql}
             ORDER BY COALESCE(r.start_time, r.schedule, r.created_at) DESC, r.id DESC
             LIMIT {$reservationPageSize} OFFSET {$allOffset}"
        );
        $allReservationsStmt->execute($allParams);
        $allReservations = $allReservationsStmt->fetchAll(PDO::FETCH_ASSOC);
        $allReservations = t8_reservations_annotate_conflicts($pdo, $allReservations);

        $myWhere = ['r.user_id = :user_id'];
        $myParams = ['user_id' => $currentUserId];
        if ($reservationFacilityFilter > 0) {
            $myWhere[] = 'r.facility_id = :filter_facility';
            $myParams['filter_facility'] = $reservationFacilityFilter;
        }
        if ($reservationTypeFilter !== '') {
            $myWhere[] = 'f.facility_type = :filter_type';
            $myParams['filter_type'] = $reservationTypeFilter;
        }
        if ($reservationStatusFilter !== '' && in_array($reservationStatusFilter, T8_RESERVATION_STATUSES, true)) {
            $myWhere[] = 'r.status = :filter_status';
            $myParams['filter_status'] = $reservationStatusFilter;
        }
        $myWhereSql = implode(' AND ', $myWhere);
        $myCountStmt = $pdo->prepare("SELECT COUNT(*) FROM team8_reservations r JOIN team8_facilities f ON f.id = r.facility_id WHERE {$myWhereSql}");
        $myCountStmt->execute($myParams);
           $myTotal = (int) $myCountStmt->fetchColumn();
           $myTotalPages = max(1, (int) ceil($myTotal / $reservationPageSize));
           $myPage = min(max(1, (int) ($_GET['my_page'] ?? 1)), $myTotalPages);
           $myOffset = ($myPage - 1) * $reservationPageSize;
           $myStmt = $pdo->prepare(
             "SELECT r.*, f.name AS facility_name, f.facility_type, f.capacity AS facility_capacity
               FROM team8_reservations r
               JOIN team8_facilities f ON f.id = r.facility_id
             WHERE {$myWhereSql}
               ORDER BY COALESCE(r.start_time, r.schedule, r.created_at) DESC, r.id DESC
               LIMIT {$reservationPageSize} OFFSET {$myOffset}"
           );
        $myStmt->execute($myParams);
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

    <?php /* FIX (empty-field errors): every validation message collected
             server-side is always rendered here, whether or not client-side
             JS also caught it - this block never silently disappears. */ ?>
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
            <form id="t8ReservationForm" class="t8-reservation-form-grid" method="post"
                  action="<?= e(page_url('reservation', array_filter(['action' => $action, 'id' => $_GET['id'] ?? null]))) ?>"
                  novalidate data-facility-config="<?= e((string) json_encode(T8_FACILITY_RESERVATION_CONFIG)) ?>">
                <?= t8_csrf_field() ?>

                <div class="t8-field">
                    <label class="t8-label" for="facility_id">Facility</label>
                    <select class="t8-select" id="facility_id" name="facility_id" required>
                        <option value="">Select a facility…</option>
                        <?php foreach ($activeFacilities as $f): ?>
                            <?php $capacityUnit = in_array($f['facility_type'], ['Asset', 'Equipment', 'Utility'], true) ? 'Qty.' : 'Cap.'; ?>
                            <option value="<?= e((string) $f['id']) ?>" data-facility-type="<?= e((string) $f['facility_type']) ?>" data-facility-capacity="<?= e((string) $f['capacity']) ?>" <?= (string) $f['id'] === $formValues['facility_id'] ? 'selected' : '' ?>>

                              <?= e($f['name']) ?><?= $f['facility_type'] ? ' — ' . e($f['facility_type']) : '' ?> — <?= e($f['location']) ?> (<?= e($capacityUnit) ?> <?= e((string) $f['capacity']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="t8-field">
                    <label class="t8-label" for="event_category">Event Category</label>
                    <select class="t8-select" id="event_category" name="event_category" required <?= $selectedFacilityType === '' ? 'disabled' : '' ?>>
                        <option value="">Select a category…</option>
                        <?php foreach ($selectedReservationConfig['event_categories'] as $cat): ?>
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

                <div class="t8-field" data-reservation-field="participants">
                    <label class="t8-label" for="expected_participants">Participants</label>
                    <input class="t8-input" type="number" id="expected_participants" name="expected_participants" min="1"
                           value="<?= e($formValues['expected_participants']) ?>" placeholder="Headcount">
                </div>

                <div class="t8-field" data-reservation-field="quantity">
                    <label class="t8-label" for="quantity">Quantity</label>
                    <input class="t8-input" type="number" id="quantity" name="quantity" min="1"
                           value="<?= e($formValues['quantity']) ?>" placeholder="Quantity to reserve">
                    <?php if ($selectedFacilityType !== '' && in_array($selectedFacilityType, ['Equipment', 'Asset'], true)): ?>
                        <?php
                        $selectedFacilityRow = null;
                        foreach ($activeFacilities as $facilityOption) {
                            if ((string) $facilityOption['id'] === $formValues['facility_id']) {
                                $selectedFacilityRow = $facilityOption;
                                break;
                            }
                        }
                        if ($selectedFacilityRow !== null):
                            $excludeForAvailability = $action === 'edit' ? (int) ($_GET['id'] ?? 0) : null;
                            $committedNow = t8_reservation_committed_quantity($pdo, (int) $selectedFacilityRow['id'], $excludeForAvailability);
                            $availableNow = max(0, (int) $selectedFacilityRow['capacity'] - $committedNow);
                        ?>
                            <span class="t8-help-text">
                                <?= e((string) $availableNow) ?> of <?= e((string) $selectedFacilityRow['capacity']) ?> currently available.
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="t8-field" data-reservation-field="time_range">
                    <label class="t8-label" for="start_time">Start</label>
                    <input class="t8-input t8-datetime-input" type="datetime-local" id="start_time" name="start_time"
                           value="<?= e(str_replace(' ', 'T', substr($formValues['start_time'], 0, 16))) ?>"
                           min="<?= e(date('Y-m-d\\TH:i')) ?>"
                           onclick="this.showPicker && this.showPicker();">
                </div>

                <div class="t8-field" data-reservation-field="time_range">
                    <label class="t8-label" for="end_time">End</label>
                    <input class="t8-input t8-datetime-input" type="datetime-local" id="end_time" name="end_time"
                           value="<?= e(str_replace(' ', 'T', substr($formValues['end_time'], 0, 16))) ?>"
                           min="<?= e(date('Y-m-d\\TH:i')) ?>"
                           onclick="this.showPicker && this.showPicker();">
                </div>

                <div id="t8ReservationAvailability" class="t8-help-text" role="status" aria-live="polite" hidden></div>
                <button id="t8ReservationSuggestionsButton" class="t8-btn t8-btn-outline t8-btn-sm" type="button" hidden>
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Suggest alternatives
                </button>
                <div id="t8ReservationSuggestions" class="t8-help-text" role="status" aria-live="polite" hidden></div>

                <div class="t8-field" data-reservation-field="return_date">
                    <label class="t8-label" for="return_date">Expected Return Date</label>
                    <input class="t8-input" type="date" id="return_date" name="return_date" value="<?= e($formValues['return_date']) ?>"
                           min="<?= e(date('Y-m-d')) ?>">
                </div>

                <div class="t8-field" data-reservation-field="remarks">
                    <label class="t8-label" for="remarks">Remarks</label>
                    <input class="t8-input" type="text" id="remarks" name="remarks" value="<?= e($formValues['remarks']) ?>">
                </div>

                <div class="t8-field" data-reservation-field="schedule">
                    <label class="t8-label" for="schedule">Schedule</label>
                    <input class="t8-input t8-datetime-input" type="datetime-local" id="schedule" name="schedule"
                           value="<?= e(str_replace(' ', 'T', substr($formValues['schedule'], 0, 16))) ?>"
                           min="<?= e(date('Y-m-d\\TH:i')) ?>">
                </div>

                <div class="t8-field" data-reservation-field="requirements">
                    <label class="t8-label" for="requirements">Requirements</label>
                    <input class="t8-input" type="text" id="requirements" name="requirements" value="<?= e($formValues['requirements']) ?>">
                </div>

                <div class="t8-field">
                    <label class="t8-label" for="description">Additional Details / Notes</label>
                    <input class="t8-input" type="text" id="description" name="description"
                           value="<?= e($formValues['description']) ?>"
                           placeholder="Optional — anything not covered above">
                    <span class="t8-help-text">Event Category covers the main purpose; use this only for extra notes.</span>
                </div>

                <div class="t8-reservation-actions">
                    <button class="t8-btn t8-btn-accent" type="submit">
                        <i class="fa-solid fa-check"></i>
                        <?= $action === 'edit' ? 'Save Changes' : ($isAdmin ? 'Continue' : 'Submit Request') ?>
                    </button>
                    <a class="t8-btn t8-btn-outline" href="<?= e(page_url('reservation')) ?>">Cancel</a>
                </div>
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
        <?php if ($isAdmin): ?>
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('reservation', ['action' => 'archive'])) ?>">
                <i class="fa-solid fa-box-archive"></i> Archive
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

    <?php if ($showArchive): ?>

        <div class="t8-card">
            <div class="t8-card-header">
                <h2 class="t8-card-title">Archived Reservations</h2>
                <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('reservation')) ?>">Back to Reservations</a>
            </div>
            <div class="t8-reservation-filters" data-reservation-filters data-filter-table="t8ArchiveReservations">
                <label>Month <select class="t8-select" data-filter-month><option value="">All months</option><?php foreach (range(1, 12) as $month): ?><option value="<?= e((string) $month) ?>"><?= e(date('F', mktime(0, 0, 0, $month, 1))) ?></option><?php endforeach; ?></select></label>
                <label>Year <select class="t8-select" data-filter-year><option value="">All years</option></select></label>
            </div>
            <?php /* FIX (Type + Status columns): archived rows previously had
                     no facility-Type column and no Status column, so it was
                     impossible to see whether an archived row was a
                     Completed booking or a Cancelled one, and Equipment/Asset
                     rows had no visible "Qty" context. Both are added below. */ ?>
            <div class="t8-table-wrap"><table class="t8-table" id="t8ArchiveReservations"><thead><tr><th>Facility</th><th>Type</th><th>Requested By</th><th>Department</th><th>Key Person</th><th>Reservation</th><th>Schedule</th><th>Status</th><th>Archived</th></tr></thead><tbody>
                <?php if ($archivedReservations === []): ?>
                    <tr class="t8-filter-empty"><td colspan="9" class="t8-table-empty-row">No completed reservations have been archived yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($archivedReservations as $r): ?>
                        <?php $summary = t8_reservation_summary($r); $schedule = t8_reservation_schedule($r); ?>
                        <tr data-reservation-row data-reservation-date="<?= e(t8_reservation_filter_date($r)) ?>">
                            <td><?= e($r['facility_name']) ?></td>
                            <td><span class="t8-type-pill"><?= e((string) ($r['facility_type'] ?? 'Unknown')) ?></span></td>
                            <td><?= e($r['requester_name']) ?></td>
                            <td><?= e((string) ($r['department'] ?? '-')) ?></td>
                            <td><?= e((string) ($r['key_person'] ?? '-')) ?></td>
                            <td><strong><?= e($summary['category']) ?></strong><?php if ($summary['detail'] !== ''): ?><span class="t8-table-subtext">• <?= e($summary['detail']) ?></span><?php endif; ?></td>
                            <td><?= e($schedule['primary']) ?></td>
                            <td><span class="t8-badge t8-badge-<?= e($r['status']) ?>"><?= e(ucfirst((string) $r['status'])) ?></span></td>
                            <td><?= e(format_date((string) $r['archived_at'], 'M d, Y g:i A')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody></table></div>
        </div>

    <?php elseif ($isAdmin): ?>

        <div class="t8-card">
            <div class="t8-card-header">
                <h2 class="t8-card-title">Pending Approvals</h2>
                <?php if ($pendingReservations !== []): ?>
                    <span class="t8-notification-count"><?= e((string) count($pendingReservations)) ?> pending</span>
                <?php endif; ?>
            </div>
            <div class="t8-table-wrap">
                <table class="t8-table" id="t8PendingReservations">
                    <thead>
                        <tr>
                            <th>Facility</th>
                            <th>Type</th>
                            <th>Requested By</th>
                            <th>Department</th>
                            <th>Key Person</th>
                            <th>Reservation</th>
                            <th>Schedule</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($pendingReservations === []): ?>
                            <tr>
                                <td colspan="9" class="t8-table-empty-row">
                                    No reservation requests are waiting for approval yet.
                                    Once a request is submitted, it will appear here.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pendingReservations as $p): ?>
                                <?php $summary = t8_reservation_summary($p); $schedule = t8_reservation_schedule($p); ?>
                                <tr <?= $p['has_conflict'] ? 'style="background: rgba(230,126,34,0.14);"' : '' ?>>
                                    <td><?= e($p['facility_name']) ?></td>
                                    <td><span class="t8-type-pill"><?= e((string) ($p['facility_type'] ?? 'Unknown')) ?></span></td>
                                    <td><?= e($p['requester_name']) ?></td>
                                    <td><?= e((string) ($p['department'] ?? '-')) ?></td>
                                    <td><?= e((string) ($p['key_person'] ?? '-')) ?></td>
                                    <td><strong><?= e($summary['category']) ?></strong><?php if ($summary['detail'] !== ''): ?><span class="t8-table-subtext">• <?= e($summary['detail']) ?></span><?php endif; ?></td>
                                    <td><strong><?= e($schedule['primary']) ?></strong><?php if ($schedule['secondary'] !== ''): ?><span class="t8-table-subtext"><?= e($schedule['secondary']) ?></span><?php endif; ?></td>
                                    <td style="display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; align-items:center;">
                                        <?php if ($p['has_conflict']): ?>
                                            <span class="t8-conflict-indicator" title="Time Conflict">
                                                <i class="fa-solid fa-triangle-exclamation"></i>
                                            </span>
                                        <?php endif; ?>
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
                <h2 class="t8-card-title">Cancellation Requests</h2>
                <?php if ($cancellationRequests !== []): ?><span class="t8-notification-count"><?= e((string) count($cancellationRequests)) ?> pending</span><?php endif; ?>
            </div>
            <div class="t8-table-wrap"><table class="t8-table"><thead><tr><th>Facility</th><th>Requested By</th><th>Reason</th><th>Requested At</th><th>Actions</th></tr></thead><tbody>
                <?php if ($cancellationRequests === []): ?>
                    <tr><td colspan="5" class="t8-table-empty-row">No cancellation requests are waiting for review.</td></tr>
                <?php else: foreach ($cancellationRequests as $request): ?>
                    <tr><td><?= e($request['facility_name']) ?></td><td><?= e($request['requester_name']) ?></td><td><?= e((string) $request['cancellation_reason']) ?></td><td><?= e(format_date((string) $request['cancellation_requested_at'], 'M d, Y g:i A')) ?></td><td style="display:flex;gap:8px;">
                        <form method="post" action="<?= e(page_url('reservation', ['action' => 'review_cancellation'])) ?>"><?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $request['id']) ?>"><input type="hidden" name="decision" value="approved"><button class="t8-btn t8-btn-success t8-btn-sm" type="submit">Approve Cancellation</button></form>
                        <form method="post" action="<?= e(page_url('reservation', ['action' => 'review_cancellation'])) ?>"><?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $request['id']) ?>"><input type="hidden" name="decision" value="rejected"><button class="t8-btn t8-btn-danger t8-btn-sm" type="submit">Reject Request</button></form>
                    </td></tr>
                <?php endforeach; endif; ?>
            </tbody></table></div>
        </div>

        <div class="t8-card">
            <div class="t8-card-header">
                <h2 class="t8-card-title">All Reservations</h2>
            </div>
            <div class="t8-reservation-filters" data-filter-table="t8AllReservations" data-filter-type="all">
                <label>Facility <select class="t8-select" data-filter-facility><option value="">All facilities</option><?php foreach ($activeFacilities as $facilityOption): ?><option value="<?= e((string) $facilityOption['id']) ?>" <?= $reservationFilters['facility'] === (int) $facilityOption['id'] ? 'selected' : '' ?>><?= e($facilityOption['name']) ?></option><?php endforeach; ?></select></label>
                <label>Type <select class="t8-select" data-filter-type-select><option value="">All types</option><?php foreach (array_keys(T8_FACILITY_RESERVATION_CONFIG) as $type): ?><option value="<?= e($type) ?>" <?= $reservationFilters['type'] === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></label>
                <label>Status <select class="t8-select" data-filter-status><option value="">All statuses</option><?php foreach (['approved', 'cancellation_pending'] as $status): ?><option value="<?= e($status) ?>" <?= $reservationFilters['status'] === $status ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $status))) ?></option><?php endforeach; ?></select></label>
            </div>
            <div class="t8-table-wrap">
                <table class="t8-table" id="t8AllReservations">
                    <thead>
                        <tr>
                            <th>Facility</th>
                            <th>Type</th>
                            <th>Requested By</th>
                            <th>Department</th>
                            <th>Key Person</th>
                            <th>Reservation</th>
                            <th>Schedule</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($allReservations === []): ?>
                            <tr>
                                <td colspan="10" class="t8-table-empty-row">
                                    No reservations have been made yet.
                                    Once a reservation is submitted, it will appear here.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allReservations as $r): ?>
                                <?php $summary = t8_reservation_summary($r); $schedule = t8_reservation_schedule($r); ?>
                                <tr data-reservation-row data-reservation-date="<?= e(t8_reservation_filter_date($r)) ?>" <?= $r['has_conflict'] ? 'style="background: rgba(230,126,34,0.14);"' : '' ?>>
                                    <td><?= e($r['facility_name']) ?></td>
                                    <td><span class="t8-type-pill"><?= e((string) ($r['facility_type'] ?? 'Unknown')) ?></span></td>
                                    <td><?= e($r['requester_name']) ?></td>
                                    <td><?= e((string) ($r['department'] ?? '-')) ?></td>
                                    <td><?= e((string) ($r['key_person'] ?? '-')) ?></td>
                                    <td><strong><?= e($summary['category']) ?></strong><?php if ($summary['detail'] !== ''): ?><span class="t8-table-subtext">• <?= e($summary['detail']) ?></span><?php endif; ?></td>
                                    <td><strong><?= e($schedule['primary']) ?></strong><?php if ($schedule['secondary'] !== ''): ?><span class="t8-table-subtext"><?= e($schedule['secondary']) ?></span><?php endif; ?></td>
                                    <td><span class="t8-badge t8-badge-<?= e($r['status']) ?>"><?= e($r['status'] === 'cancellation_pending' ? 'Pending' : ucfirst($r['status'])) ?></span></td>
                                    <td style="display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; align-items:center;">
                                        <?php if ($r['has_conflict']): ?>
                                            <span class="t8-conflict-indicator" title="Time Conflict">
                                                <i class="fa-solid fa-triangle-exclamation"></i>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($r['status'] === 'approved'): ?>
                                            <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('reservation', ['action' => 'edit', 'id' => $r['id']])) ?>">
                                                <i class="fa-solid fa-calendar-pen"></i> Reschedule
                                            </a>
                                            <form method="post" action="<?= e(page_url('reservation', ['action' => 'cancel'])) ?>"
                                                  onsubmit="return confirm('Cancel this reservation and move it to Archive?');">
                                                <?= t8_csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= e((string) $r['id']) ?>">
                                                <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit">
                                                    <i class="fa-solid fa-xmark"></i> Cancel
                                                </button>
                                            </form>
                                        <?php elseif ($r['status'] === 'cancellation_pending'): ?>
                                            <span class="t8-help-text">Cancellation review pending</span>
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
            <?php t8_reservation_pagination($allPage, $allTotalPages, $reservationFilters); ?>
        </div>

    <?php else: ?>

        <div class="t8-card">
            <div class="t8-card-header">
                <h2 class="t8-card-title">My Reservations</h2>
            </div>
            <div class="t8-reservation-filters" data-filter-table="t8MyReservations" data-filter-type="my">
                <label>Facility <select class="t8-select" data-filter-facility><option value="">All facilities</option><?php foreach ($activeFacilities as $facilityOption): ?><option value="<?= e((string) $facilityOption['id']) ?>" <?= $reservationFilters['facility'] === (int) $facilityOption['id'] ? 'selected' : '' ?>><?= e($facilityOption['name']) ?></option><?php endforeach; ?></select></label>
                <label>Type <select class="t8-select" data-filter-type-select><option value="">All types</option><?php foreach (array_keys(T8_FACILITY_RESERVATION_CONFIG) as $type): ?><option value="<?= e($type) ?>" <?= $reservationFilters['type'] === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></label>
                <label>Status <select class="t8-select" data-filter-status><option value="">All statuses</option><option value="approved" <?= $reservationFilters['status'] === 'approved' ? 'selected' : '' ?>>Approved</option></select></label>
            </div>
            <div class="t8-table-wrap">
                <table class="t8-table" id="t8MyReservations">
                    <thead>
                        <tr>
                            <th>Facility</th>
                            <th>Type</th>
                            <th>Reservation</th>
                            <th>Schedule</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($myReservations === []): ?>
                            <tr>
                                <td colspan="7" class="t8-table-empty-row">
                                    You haven't made any reservations yet.
                                    Create one to see it listed here.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($myReservations as $r): ?>
                                <?php $summary = t8_reservation_summary($r); $schedule = t8_reservation_schedule($r); ?>
                                <tr <?= $r['has_conflict'] ? 'style="background: rgba(230,126,34,0.14);"' : '' ?>>
                                    <td><?= e($r['facility_name']) ?></td>
                                    <td><span class="t8-type-pill"><?= e((string) ($r['facility_type'] ?? 'Unknown')) ?></span></td>
                                    <td><strong><?= e($summary['category']) ?></strong><?php if ($summary['detail'] !== ''): ?><span class="t8-table-subtext">• <?= e($summary['detail']) ?></span><?php endif; ?></td>
                                    <td><strong><?= e($schedule['primary']) ?></strong><?php if ($schedule['secondary'] !== ''): ?><span class="t8-table-subtext"><?= e($schedule['secondary']) ?></span><?php endif; ?></td>
                                    <td><span class="t8-badge t8-badge-<?= e($r['status']) ?>"><?= e($r['status'] === 'cancellation_pending' ? 'Pending' : ucfirst($r['status'])) ?></span></td>
                                    <td style="display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; align-items:center;">
                                        <?php if ($r['has_conflict']): ?>
                                            <span class="t8-conflict-indicator" title="Time Conflict">
                                                <i class="fa-solid fa-triangle-exclamation"></i>
                                            </span>
                                        <?php endif; ?>
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
                                        <?php elseif (!$isAdmin && $r['status'] === 'approved'): ?>
                                            <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('reservation', ['action' => 'edit', 'id' => $r['id']])) ?>">
                                                <i class="fa-solid fa-pen"></i> Edit
                                            </a>
                                            <?php /* FIX (cancel only before start): once the reservation's
                                                     start/schedule time has passed, staff can no longer
                                                     self-request a cancellation - the button is replaced
                                                     with an explanatory note instead of being hidden
                                                     silently. */ ?>
                                            <?php if (!t8_reservation_has_started($r)): ?>
                                                <button class="t8-btn t8-btn-danger t8-btn-sm" type="button" data-cancel-reservation-id="<?= e((string) $r['id']) ?>">
                                                    <i class="fa-solid fa-xmark"></i> Cancel Reservation
                                                </button>
                                            <?php else: ?>
                                                <span class="t8-help-text">Already started — contact an administrator to cancel.</span>
                                            <?php endif; ?>
                                            <?php if (($r['cancellation_decision'] ?? '') === 'rejected'): ?>
                                                <span class="t8-help-text">Previous cancellation request was rejected.</span>
                                            <?php endif; ?>
                                        <?php elseif ($r['status'] === 'cancellation_pending'): ?>
                                            <span class="t8-help-text">Cancellation request pending</span>
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
            <?php t8_reservation_pagination($myPage, $myTotalPages, $reservationFilters, 'my_page'); ?>
        </div>

        <div class="t8-card">
            <div class="t8-card-header">
                <h2 class="t8-card-title">All Reservations</h2>
            </div>
            <div class="t8-reservation-filters" data-filter-table="t8MyReservations" data-filter-type="my">
                <label>Facility <select class="t8-select" data-filter-facility><option value="">All facilities</option><?php foreach ($activeFacilities as $facilityOption): ?><option value="<?= e((string) $facilityOption['id']) ?>" <?= $reservationFilters['facility'] === (int) $facilityOption['id'] ? 'selected' : '' ?>><?= e($facilityOption['name']) ?></option><?php endforeach; ?></select></label>
                <label>Type <select class="t8-select" data-filter-type-select><option value="">All types</option><?php foreach (array_keys(T8_FACILITY_RESERVATION_CONFIG) as $type): ?><option value="<?= e($type) ?>" <?= $reservationFilters['type'] === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></label>
                <label>Status <select class="t8-select" data-filter-status><option value="">All statuses</option><option value="approved" <?= $reservationFilters['status'] === 'approved' ? 'selected' : '' ?>>Approved</option></select></label>
            </div>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead>
                        <tr>
                            <th>Facility</th>
                            <th>Type</th>
                            <th>Requested By</th>
                            <th>Reservation</th>
                            <th>Schedule</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($allReservations === []): ?>
                            <tr>
                                <td colspan="7" class="t8-table-empty-row">
                                    No reservations have been made yet.
                                    Once a reservation is submitted, it will appear here.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allReservations as $r): ?>
                                <?php $summary = t8_reservation_summary($r); $schedule = t8_reservation_schedule($r); ?>
                                <?php
                                    // FIX (cancel button in staff "All Reservations"): Facilities
                                    // Staff can request cancellation of ANY approved reservation
                                    // shown here (not just their own) - matching the broadened
                                    // server-side rule in the 'cancel' action switch above. It is
                                    // always sent as a REQUEST for an Administrator to approve or
                                    // reject, never an immediate cancel.
                                    $isOwnRow = (int) ($r['user_id'] ?? 0) === $currentUserId;
                                ?>
                                <tr <?= $r['has_conflict'] ? 'style="background: rgba(230,126,34,0.14);"' : '' ?>>
                                    <td><?= e($r['facility_name']) ?></td>
                                    <td><span class="t8-type-pill"><?= e((string) ($r['facility_type'] ?? 'Unknown')) ?></span></td>
                                    <td><?= e((string) ($r['requester_name'] ?? '—')) ?></td>
                                    <td><strong><?= e($summary['category']) ?></strong><?php if ($summary['detail'] !== ''): ?><span class="t8-table-subtext">• <?= e($summary['detail']) ?></span><?php endif; ?></td>
                                    <td><strong><?= e($schedule['primary']) ?></strong><?php if ($schedule['secondary'] !== ''): ?><span class="t8-table-subtext"><?= e($schedule['secondary']) ?></span><?php endif; ?></td>
                                    <td><span class="t8-badge t8-badge-<?= e($r['status']) ?>"><?= e($r['status'] === 'cancellation_pending' ? 'Pending' : ucfirst($r['status'])) ?></span></td>
                                    <td style="display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; align-items:center;">
                                        <?php if ($r['has_conflict']): ?>
                                            <span class="t8-conflict-indicator" title="Time Conflict">
                                                <i class="fa-solid fa-triangle-exclamation"></i>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!$isAdmin && $isOwnRow && $r['status'] === 'approved'): ?>
                                            <?php if (!t8_reservation_has_started($r)): ?>
                                                <button class="t8-btn t8-btn-danger t8-btn-sm" type="button"
                                                        data-cancel-reservation-id="<?= e((string) $r['id']) ?>"
                                                        title="<?= $isOwnRow ? 'Request cancellation of your reservation' : 'Request cancellation on behalf of ' . e($r['requester_name'] ?? 'this requester') ?>">
                                                    <i class="fa-solid fa-xmark"></i> Request Cancellation
                                                </button>
                                            <?php else: ?>
                                                <span class="t8-help-text">Already started</span>
                                            <?php endif; ?>
                                        <?php elseif ($r['status'] === 'cancellation_pending'): ?>
                                            <span class="t8-help-text">Cancellation pending</span>
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
            <?php t8_reservation_pagination($allPage, $allTotalPages, $reservationFilters); ?>
        </div>

    <?php endif; ?>

    <?php if (!$isAdmin): ?>
        <dialog id="t8CancellationRequestModal" class="t8-cancellation-modal">
            <form method="post" action="<?= e(page_url('reservation', ['action' => 'cancel'])) ?>">
                <?= t8_csrf_field() ?>
                <input type="hidden" id="t8CancellationReservationId" name="id">
                <h2>Request Reservation Cancellation</h2>
                <p>Are you sure you want to request cancellation of this reservation?</p>
                <label class="t8-label" for="t8CancellationReason">Reason for Cancellation</label>
                <textarea class="t8-input" id="t8CancellationReason" name="cancellation_reason" rows="4" required></textarea>
                <span id="t8CancellationReasonError" class="t8-error-text" hidden>Please enter a reason for cancellation.</span>
                <p class="t8-help-text">This request will be sent to an Administrator for approval. The reservation remains active until it is approved.</p>
                <div style="display:flex;gap:8px;margin-top:16px;"><button class="t8-btn t8-btn-outline" type="button" data-close-cancellation-modal>Cancel</button><button class="t8-btn t8-btn-danger" type="submit">Submit Cancellation Request</button></div>
            </form>
        </dialog>
    <?php endif; ?>

<?php endif; ?>
