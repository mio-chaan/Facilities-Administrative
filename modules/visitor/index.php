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
 * Status lifecycle: scheduled -> checked_in -> checked_out
 *                              \-> cancelled
 *
 * Backing table: team8_visitors (see database/visitor_scheduling_fields.sql).
 */

declare(strict_types=1);

$pageTitle = 'Visitor Management';
$currentUserId = t8_current_user_id();
$isAdmin = t8_has_role('admin');
$action = $_GET['action'] ?? 'list';
$errors = [];

// Dropdown options for Visitor Type. Add more here as needed - no
// other code changes required.
const T8_VISITOR_TYPES = [
    'Supplier',
    'Maintenance Personnel',
    'Auditor',
    'Barangay Official',
    'Government Official',
    'Client / Guest',
    'Job Applicant',
    'Other',
];

/** Turns a row id into a display-only Visitor ID, e.g. "VIS-000123". */
function t8_visitor_id_label(int $id): string
{
    return 'VIS-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

/** Fetch a single visitor row with the logger's name, or null. */
function t8_visitor_fetch(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT v.*, u.full_name AS logged_by_name
         FROM team8_visitors v
         JOIN users u ON u.id = v.logged_by
         WHERE v.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
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

function t8_normalize_ph_contact(string $contact): string
{
    $contact = trim($contact);
    if ($contact === '') {
        return '';
    }

    $digits = preg_replace('/[^\d\+]/', '', $contact);

    if ($digits === '+63' || $digits === '63') {
        return '';
    }
    if (preg_match('/^\+63\d{10}$/', $digits)) {
        return $digits;
    }
    if (preg_match('/^0(\d{10})$/', $digits, $matches)) {
        return '+63' . $matches[1];
    }
    if (preg_match('/^63(\d{10})$/', $digits, $matches)) {
        return '+63' . $matches[1];
    }
    if (preg_match('/^(\d{10})$/', $digits, $matches)) {
        return '+63' . $matches[1];
    }

    return $contact;
}

function t8_validate_ph_contact(string $contact): bool
{
    $contact = trim($contact);
    if ($contact === '') {
        return true;
    }

    $normalized = t8_normalize_ph_contact($contact);
    return preg_match('/^\+63\d{10}$/', $normalized) === 1;
}

function t8_visitor_status_badge(string $status): string
{
    $map = [
        'scheduled'   => 't8-badge-pending',
        'checked_in'  => 't8-badge-approved',
        'checked_out' => 't8-badge-archived',
        'cancelled'   => 't8-badge-rejected',
    ];
    return $map[$status] ?? 't8-badge-pending';
}

$formValues = [
    'full_name'       => '',
    'visitor_type'    => '',
    'contact_suffix'  => '',
    'person_to_visit' => '',
    'purpose'         => '',
    'scheduled_date'  => date('Y-m-d\TH:i'), // default to "now" for the form
    'arriving_now'    => '0',
];

switch ($action) {
    case 'create':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $formValues = [
                'full_name'       => trim((string) ($_POST['full_name'] ?? '')),
                'visitor_type'    => (string) ($_POST['visitor_type'] ?? ''),
                'contact_suffix'  => trim((string) ($_POST['contact_suffix'] ?? '')),
                'person_to_visit' => trim((string) ($_POST['person_to_visit'] ?? '')),
                'purpose'         => trim((string) ($_POST['purpose'] ?? '')),
                'scheduled_date'  => t8_normalize_datetime((string) ($_POST['scheduled_date'] ?? '')),
                'arriving_now'    => isset($_POST['arriving_now']) ? '1' : '0',
            ];

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                if ($formValues['full_name'] === '') {
                    $errors[] = 'Visitor name is required.';
                }
                if (!in_array($formValues['visitor_type'], T8_VISITOR_TYPES, true)) {
                    $errors[] = 'Please select a visitor type.';
                }
                if ($formValues['person_to_visit'] === '') {
                    $errors[] = 'Please indicate who the visitor is here to see.';
                }
                if ($formValues['purpose'] === '') {
                    $errors[] = 'Purpose of visit is required.';
                }
                $arrivingNow = $formValues['arriving_now'] === '1';
                if (!$arrivingNow && ($formValues['scheduled_date'] === '' || strtotime($formValues['scheduled_date']) === false)) {
                    $errors[] = 'Scheduled date/time must be valid.';
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
                            (full_name, visitor_type, contact, person_to_visit, purpose, scheduled_date, status, check_in_time, logged_by)
                         VALUES
                            (:full_name, :visitor_type, :contact, :person_to_visit, :purpose, :scheduled_date, :status, :check_in_time, :logged_by)'
                    );
                    $stmt->execute([
                        'full_name'       => $formValues['full_name'],
                        'visitor_type'    => $formValues['visitor_type'],
                        'contact'         => $contact !== '' ? $contact : null,
                        'person_to_visit' => $formValues['person_to_visit'],
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
        if ($target && $target['status'] === 'scheduled') {
            $pdo->prepare("UPDATE team8_visitors SET status = 'checked_in', check_in_time = NOW() WHERE id = :id")
                ->execute(['id' => $id]);
            t8_audit_log($pdo, $currentUserId, 'visitor', $id, 'check_in');
            t8_flash_set('success', 'Visitor checked in.');
        } else {
            t8_flash_set('danger', 'That visit is not awaiting check-in.');
        }
        redirect(page_url('visitor'));
        break;

    case 'checkout':
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
    $scheduledVisits = $pdo->query(
        "SELECT v.*, u.full_name AS logged_by_name
         FROM team8_visitors v
         JOIN users u ON u.id = v.logged_by
         WHERE v.status = 'scheduled'
         ORDER BY v.scheduled_date ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $currentlyIn = $pdo->query(
        "SELECT v.*, u.full_name AS logged_by_name
         FROM team8_visitors v
         JOIN users u ON u.id = v.logged_by
         WHERE v.status = 'checked_in'
         ORDER BY v.check_in_time ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $allVisitors = $pdo->query(
        'SELECT v.*, u.full_name AS logged_by_name
         FROM team8_visitors v
         JOIN users u ON u.id = v.logged_by
         ORDER BY v.created_at DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
}
?>
<h1>Visitor Management</h1>
<p class="t8-help-text">Schedule visit requests, check visitors in on arrival, and monitor who is currently on-site.</p>

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
                <select class="t8-select" id="visitor_type" name="visitor_type" required>
                    <option value="">Select a type…</option>
                    <?php foreach (T8_VISITOR_TYPES as $type): ?>
                        <option value="<?= e($type) ?>" <?= $type === $formValues['visitor_type'] ? 'selected' : '' ?>><?= e($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="contact_suffix">Contact Number</label>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <span style="padding: 12px 14px; background: var(--t8-secondary); border: 1.5px solid var(--t8-border); border-radius: var(--t8-radius-sm) 0 0 var(--t8-radius-sm);">+63</span>
                    <input class="t8-input" type="tel" id="contact_suffix" name="contact_suffix"
                           value="<?= e($formValues['contact_suffix']) ?>"
                           inputmode="tel"
                           maxlength="10"
                           placeholder="9123456789"
                           pattern="[0-9]{10}"
                           title="Enter 10 digits after +63"
                           style="border-radius: 0 var(--t8-radius-sm) var(--t8-radius-sm) 0; flex: 1;">
                </div>
                <span class="t8-help-text">Optional. Enter exactly 10 digits after +63, e.g. 9123456789.</span>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="person_to_visit">Person / Department to Visit</label>
                <input class="t8-input" type="text" id="person_to_visit" name="person_to_visit"
                       value="<?= e($formValues['person_to_visit']) ?>" required>
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

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
        <a class="t8-btn t8-btn-accent" href="<?= e(page_url('visitor', ['action' => 'create'])) ?>">
            <i class="fa-solid fa-user-plus"></i> New Visit Request
        </a>
    </div>

    <div class="t8-card">
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
                        <th>Visiting</th>
                        <th>Purpose</th>
                        <th>Scheduled For</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($scheduledVisits === []): ?>
                        <tr class="t8-table-empty-row">
                            <td colspan="7">No visits are currently scheduled.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($scheduledVisits as $v): ?>
                            <tr>
                                <td class="t8-table-ref"><?= e(t8_visitor_id_label((int) $v['id'])) ?></td>
                                <td><?= e($v['full_name']) ?></td>
                                <td><?= e((string) ($v['visitor_type'] ?? '—')) ?></td>
                                <td><?= e($v['person_to_visit']) ?></td>
                                <td><?= e($v['purpose']) ?></td>
                                <td><?= e(format_date($v['scheduled_date'], 'M d, Y g:i A')) ?></td>
                                <td style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <form method="post" action="<?= e(page_url('visitor', ['action' => 'checkin'])) ?>">
                                        <?= t8_csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= e((string) $v['id']) ?>">
                                        <button class="t8-btn t8-btn-success t8-btn-sm" type="submit">
                                            <i class="fa-solid fa-right-to-bracket"></i> Check In
                                        </button>
                                    </form>
                                    <form method="post" action="<?= e(page_url('visitor', ['action' => 'cancel'])) ?>"
                                          onsubmit="return confirm('Cancel this scheduled visit?');">
                                        <?= t8_csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= e((string) $v['id']) ?>">
                                        <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit">
                                            <i class="fa-solid fa-xmark"></i> Cancel
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
                        <th>Visiting</th>
                        <th>Purpose</th>
                        <th>Check-In Time</th>
                        <th>Logged By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($currentlyIn === []): ?>
                        <tr class="t8-table-empty-row">
                            <td colspan="8">No visitors currently checked in.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($currentlyIn as $v): ?>
                            <tr>
                                <td class="t8-table-ref"><?= e(t8_visitor_id_label((int) $v['id'])) ?></td>
                                <td><?= e($v['full_name']) ?></td>
                                <td><?= e((string) ($v['visitor_type'] ?? '—')) ?></td>
                                <td><?= e($v['person_to_visit']) ?></td>
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
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title">Visitor Log (All)</h2>
        </div>
        <?php if ($allVisitors === []): ?>
            <div class="t8-empty">No visitors have been logged yet.</div>
        <?php else: ?>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead>
                        <tr>
                            <th>Visitor ID</th>
                            <th>Visitor</th>
                            <th>Type</th>
                            <th>Visiting</th>
                            <th>Purpose</th>
                            <th>Scheduled For</th>
                            <th>Check-In</th>
                            <th>Check-Out</th>
                            <th>Status</th>
                            <th>Logged By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allVisitors as $v): ?>
                            <tr>
                                <td class="t8-table-ref"><?= e(t8_visitor_id_label((int) $v['id'])) ?></td>
                                <td><?= e($v['full_name']) ?></td>
                                <td><?= e((string) ($v['visitor_type'] ?? '—')) ?></td>
                                <td><?= e($v['person_to_visit']) ?></td>
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
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php endif; ?>