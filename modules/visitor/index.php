<?php
/**
 * modules/visitor/index.php
 * Visitor Management - simple check-in / check-out log.
 *
 * Workflow:
 *   Any logged-in user (staff or admin) can log a visitor check-in.
 *   Any logged-in user can check a visitor out (front-desk style -
 *   not restricted to the person who logged them in).
 *   No approval step - a visitor is either "Checked In" (check_out_time
 *   IS NULL) or "Checked Out" (check_out_time IS NOT NULL). Status is
 *   derived from that column rather than stored separately, so there's
 *   no risk of the two getting out of sync.
 *
 * Backing table: team8_visitors (see database/visitor_table.sql).
 */

declare(strict_types=1);

$pageTitle = 'Visitor Management';
$currentUserId = t8_current_user_id();
$isAdmin = t8_has_role('admin');
$action = $_GET['action'] ?? 'list';
$errors = [];

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

$formValues = [
    'full_name'       => '',
    'contact'         => '',
    'person_to_visit' => '',
    'purpose'         => '',
    'check_in_time'   => date('Y-m-d\TH:i'), // default to "now" for the form
];

switch ($action) {
    case 'create':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $formValues = [
                'full_name'       => trim((string) ($_POST['full_name'] ?? '')),
                'contact'         => trim((string) ($_POST['contact'] ?? '')),
                'person_to_visit' => trim((string) ($_POST['person_to_visit'] ?? '')),
                'purpose'         => trim((string) ($_POST['purpose'] ?? '')),
                'check_in_time'   => t8_normalize_datetime((string) ($_POST['check_in_time'] ?? '')),
            ];

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                if ($formValues['full_name'] === '') {
                    $errors[] = 'Visitor name is required.';
                }
                if ($formValues['person_to_visit'] === '') {
                    $errors[] = 'Please indicate who the visitor is here to see.';
                }
                if ($formValues['purpose'] === '') {
                    $errors[] = 'Purpose of visit is required.';
                }
                if ($formValues['check_in_time'] === '' || strtotime($formValues['check_in_time']) === false) {
                    $errors[] = 'Check-in time must be a valid date/time.';
                }

                if (!$errors) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO team8_visitors (full_name, contact, person_to_visit, purpose, check_in_time, logged_by)
                         VALUES (:full_name, :contact, :person_to_visit, :purpose, :check_in_time, :logged_by)'
                    );
                    $stmt->execute([
                        'full_name'       => $formValues['full_name'],
                        'contact'         => $formValues['contact'] !== '' ? $formValues['contact'] : null,
                        'person_to_visit' => $formValues['person_to_visit'],
                        'purpose'         => $formValues['purpose'],
                        'check_in_time'   => $formValues['check_in_time'],
                        'logged_by'       => $currentUserId,
                    ]);
                    $newId = (int) $pdo->lastInsertId();
                    t8_audit_log($pdo, $currentUserId, 'visitor', $newId, 'check_in');
                    t8_flash_set('success', 'Visitor checked in.');
                    redirect(page_url('visitor'));
                }
            }
        }
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
        if ($target && $target['check_out_time'] === null) {
            $pdo->prepare('UPDATE team8_visitors SET check_out_time = NOW() WHERE id = :id')
                ->execute(['id' => $id]);
            t8_audit_log($pdo, $currentUserId, 'visitor', $id, 'check_out');
            t8_flash_set('success', 'Visitor checked out.');
        } else {
            t8_flash_set('danger', 'That visitor is already checked out.');
        }
        redirect(page_url('visitor'));
        break;
}

$showForm = $action === 'create';

// ---- Data for the list view ----
if (!$showForm) {
    $currentlyIn = $pdo->query(
        'SELECT v.*, u.full_name AS logged_by_name
         FROM team8_visitors v
         JOIN users u ON u.id = v.logged_by
         WHERE v.check_out_time IS NULL
         ORDER BY v.check_in_time ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $allVisitors = $pdo->query(
        'SELECT v.*, u.full_name AS logged_by_name
         FROM team8_visitors v
         JOIN users u ON u.id = v.logged_by
         ORDER BY v.check_in_time DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
}
?>
<h1>Visitor Management</h1>
<p class="t8-help-text">Log visitor check-ins and track who is currently on-site.</p>

<?php if ($showForm): ?>

    <?php foreach ($errors as $error): ?>
        <div class="t8-alert t8-alert-danger"><?= e($error) ?></div>
    <?php endforeach; ?>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title">New Visitor Check-In</h2>
        </div>

        <form method="post" action="<?= e(page_url('visitor', ['action' => 'create'])) ?>" novalidate>
            <?= t8_csrf_field() ?>

            <div class="t8-field">
                <label class="t8-label" for="full_name">Visitor Name</label>
                <input class="t8-input" type="text" id="full_name" name="full_name"
                       value="<?= e($formValues['full_name']) ?>" required>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="contact">Contact Number</label>
                <input class="t8-input" type="text" id="contact" name="contact"
                       value="<?= e($formValues['contact']) ?>" placeholder="Optional">
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
                <label class="t8-label" for="check_in_time">Check-In Time</label>
                <input class="t8-input" type="datetime-local" id="check_in_time" name="check_in_time"
                       value="<?= e(str_replace(' ', 'T', substr($formValues['check_in_time'], 0, 16))) ?>" required>
            </div>

            <button class="t8-btn t8-btn-accent" type="submit">
                <i class="fa-solid fa-check"></i> Check In
            </button>
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('visitor')) ?>">Cancel</a>
        </form>
    </div>

<?php else: ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
        <a class="t8-btn t8-btn-accent" href="<?= e(page_url('visitor', ['action' => 'create'])) ?>">
            <i class="fa-solid fa-user-plus"></i> New Check-In
        </a>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title">Currently On-Site</h2>
            <?php if ($currentlyIn !== []): ?>
                <span class="t8-notification-count"><?= e((string) count($currentlyIn)) ?> checked in</span>
            <?php endif; ?>
        </div>
        <?php if ($currentlyIn === []): ?>
            <div class="t8-empty">No visitors currently checked in.</div>
        <?php else: ?>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead>
                        <tr>
                            <th>Visitor</th>
                            <th>Visiting</th>
                            <th>Purpose</th>
                            <th>Check-In Time</th>
                            <th>Logged By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($currentlyIn as $v): ?>
                            <tr>
                                <td><?= e($v['full_name']) ?></td>
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
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
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
                            <th>Visitor</th>
                            <th>Visiting</th>
                            <th>Purpose</th>
                            <th>Check-In</th>
                            <th>Check-Out</th>
                            <th>Status</th>
                            <th>Logged By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allVisitors as $v): ?>
                            <?php $checkedOut = $v['check_out_time'] !== null; ?>
                            <tr>
                                <td><?= e($v['full_name']) ?></td>
                                <td><?= e($v['person_to_visit']) ?></td>
                                <td><?= e($v['purpose']) ?></td>
                                <td><?= e(format_date($v['check_in_time'], 'M d, Y g:i A')) ?></td>
                                <td><?= $checkedOut ? e(format_date($v['check_out_time'], 'M d, Y g:i A')) : '—' ?></td>
                                <td>
                                    <span class="t8-badge <?= $checkedOut ? 't8-badge-rejected' : 't8-badge-approved' ?>">
                                        <?= $checkedOut ? 'Checked Out' : 'Checked In' ?>
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