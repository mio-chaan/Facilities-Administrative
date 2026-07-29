<?php
/**
 * modules/facilities/index.php
 * Facility Management — Administrator-only.
 *
 * Lets the Administrator add, edit, archive, and reactivate facilities
 * entirely through the UI, instead of direct SQL/phpMyAdmin. Reused
 * by the Reservation module's facility dropdown (only status='active'
 * rows are offered there).
 *
 * Facilities are archived, never hard-deleted — team8_reservations and
 * team8_equipment both hold FKs into team8_facilities, so deleting a
 * facility with any history would either violate the FK or orphan
 * historical reservations.
 *
 * Backing table: team8_facilities (name, location, facility_type,
 * capacity, description, status).
 *
 * REVISION (professor feedback - "design for future business
 * expansion"): added facility_type as a dropdown (T8_FACILITY_TYPES
 * below), so new categories of facility (e.g. "Function Hall 2",
 * additional sports courts, parking areas) can be added just by
 * picking/adding a type - no other code changes needed. Adding a new
 * TYPE (not just a new facility of an existing type) only requires
 * editing the T8_FACILITY_TYPES array below.
 *
 * Whole module is admin-only (guarded below), per project decision —
 * Facilities Staff never reaches this page even by direct URL (see
 * index.php's route-level role check, driven by routes.php's
 * 'roles' => ['admin'] on the 'facilities' route).
 */

declare(strict_types=1);

t8_require_role(['admin']);

$pageTitle = 'Facility Management';
$currentUserId = t8_current_user_id();
$action = $_GET['action'] ?? 'list';
$errors = [];

// Dropdown options for Facility Type. Add new types here as the
// business grows - no other code needs to change.
const T8_FACILITY_TYPES = [
    'Function Hall',
    'Conference Room',
    'Meeting Room',
    'Training Room',
    'Sports Facility',
    'Parking Area',
    'Outdoor Area',
    'Other',
];

/** Fetch a single facility row or null. */
function t8_facility_fetch(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM team8_facilities WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Shared validation for both create and edit forms. */
function t8_facility_validate(string $name, string $location, string $facilityType, int $capacity): array
{
    $errors = [];
    if ($name === '') {
        $errors[] = 'Facility name is required.';
    } elseif (mb_strlen($name) > 150) {
        $errors[] = 'Facility name must be 150 characters or fewer.';
    }
    if ($location === '') {
        $errors[] = 'Location is required.';
    } elseif (mb_strlen($location) > 200) {
        $errors[] = 'Location must be 200 characters or fewer.';
    }
    if (!in_array($facilityType, T8_FACILITY_TYPES, true)) {
        $errors[] = 'Please select a valid facility type.';
    }
    if ($capacity < 1) {
        $errors[] = 'Capacity must be at least 1.';
    }
    return $errors;
}

$facility = ['name' => '', 'location' => '', 'facility_type' => '', 'capacity' => '', 'description' => ''];

switch ($action) {
    case 'create':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $facility = [
                'name'          => trim((string) ($_POST['name'] ?? '')),
                'location'      => trim((string) ($_POST['location'] ?? '')),
                'facility_type' => (string) ($_POST['facility_type'] ?? ''),
                'capacity'      => (string) ($_POST['capacity'] ?? ''),
                'description'   => trim((string) ($_POST['description'] ?? '')),
            ];

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                $capacityInt = (int) $facility['capacity'];
                $errors = t8_facility_validate($facility['name'], $facility['location'], $facility['facility_type'], $capacityInt);

                if (!$errors) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO team8_facilities (name, location, facility_type, capacity, description, status)
                         VALUES (:name, :location, :facility_type, :capacity, :description, "active")'
                    );
                    $stmt->execute([
                        'name'          => $facility['name'],
                        'location'      => $facility['location'],
                        'facility_type' => $facility['facility_type'],
                        'capacity'      => $capacityInt,
                        'description'   => $facility['description'] !== '' ? $facility['description'] : null,
                    ]);
                    $newId = (int) $pdo->lastInsertId();
                    t8_audit_log($pdo, $currentUserId, 'facility', $newId, 'create', null, $facility['name']);
                    t8_flash_set('success', 'Facility "' . $facility['name'] . '" was added.');
                    redirect(page_url('facilities'));
                }
            }
        }
        break;

    case 'edit':
        $id = (int) ($_GET['id'] ?? 0);
        $existing = $id ? t8_facility_fetch($pdo, $id) : null;
        if (!$existing) {
            t8_flash_set('danger', 'Facility not found.');
            redirect(page_url('facilities'));
        }
        $facility = $existing;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $facility['name']          = trim((string) ($_POST['name'] ?? ''));
            $facility['location']      = trim((string) ($_POST['location'] ?? ''));
            $facility['facility_type'] = (string) ($_POST['facility_type'] ?? '');
            $facility['capacity']      = (string) ($_POST['capacity'] ?? '');
            $facility['description']   = trim((string) ($_POST['description'] ?? ''));

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                $capacityInt = (int) $facility['capacity'];
                $errors = t8_facility_validate($facility['name'], $facility['location'], $facility['facility_type'], $capacityInt);

                if (!$errors) {
                    $stmt = $pdo->prepare(
                        'UPDATE team8_facilities
                         SET name = :name, location = :location, facility_type = :facility_type,
                             capacity = :capacity, description = :description
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        'name'          => $facility['name'],
                        'location'      => $facility['location'],
                        'facility_type' => $facility['facility_type'],
                        'capacity'      => $capacityInt,
                        'description'   => $facility['description'] !== '' ? $facility['description'] : null,
                        'id'            => $id,
                    ]);
                    t8_audit_log($pdo, $currentUserId, 'facility', $id, 'update', null, $facility['name']);
                    t8_flash_set('success', 'Facility "' . $facility['name'] . '" was updated.');
                    redirect(page_url('facilities'));
                }
            }
        }
        break;

    case 'archive':
    case 'reactivate':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('facilities'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('facilities'));
        }
        $id = (int) ($_POST['id'] ?? 0);
        $target = t8_facility_fetch($pdo, $id);
        if (!$target) {
            t8_flash_set('danger', 'Facility not found.');
            redirect(page_url('facilities'));
        }
        $newStatus = $action === 'archive' ? 'archived' : 'active';
        $stmt = $pdo->prepare('UPDATE team8_facilities SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $newStatus, 'id' => $id]);
        t8_audit_log($pdo, $currentUserId, 'facility', $id, $action, null, $target['name']);
        t8_flash_set('success', 'Facility "' . $target['name'] . '" ' . ($action === 'archive' ? 'archived' : 'reactivated') . '.');
        redirect(page_url('facilities'));
        break;
}

$showForm = in_array($action, ['create', 'edit'], true);

if (!$showForm) {
    $facilities = $pdo->query('SELECT * FROM team8_facilities ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
}
?>
<h1>Facility Management</h1>
<p class="t8-help-text">Add, edit, archive, and reactivate the facilities available for reservation.</p>

<?php if ($showForm): ?>

    <?php foreach ($errors as $error): ?>
        <div class="t8-alert t8-alert-danger"><?= e($error) ?></div>
    <?php endforeach; ?>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= $action === 'edit' ? 'Edit Facility' : 'Add Facility' ?></h2>
        </div>

        <form method="post" action="<?= e(page_url('facilities', array_filter(['action' => $action, 'id' => $facility['id'] ?? null]))) ?>" novalidate>
            <?= t8_csrf_field() ?>

            <div class="t8-field">
                <label class="t8-label" for="name">Facility Name</label>
                <input class="t8-input" type="text" id="name" name="name" maxlength="150"
                       value="<?= e((string) $facility['name']) ?>" placeholder="e.g. Function Hall 2" required autofocus>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="facility_type">Facility Type</label>
                <select class="t8-select" id="facility_type" name="facility_type" required>
                    <option value="">Select a type…</option>
                    <?php foreach (T8_FACILITY_TYPES as $type): ?>
                        <option value="<?= e($type) ?>" <?= $type === $facility['facility_type'] ? 'selected' : '' ?>><?= e($type) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="t8-help-text">Add new types anytime by editing T8_FACILITY_TYPES in the code — no database change needed.</span>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="location">Location</label>
                <input class="t8-input" type="text" id="location" name="location" maxlength="200"
                       value="<?= e((string) $facility['location']) ?>" required>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="capacity">Capacity</label>
                <input class="t8-input" type="number" id="capacity" name="capacity" min="1"
                       value="<?= e((string) $facility['capacity']) ?>" required>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="description">Description <span class="t8-help-text">(optional)</span></label>
                <textarea class="t8-textarea" id="description" name="description" rows="3"><?= e((string) $facility['description']) ?></textarea>
            </div>

            <button class="t8-btn t8-btn-accent" type="submit">
                <i class="fa-solid fa-check"></i> <?= $action === 'edit' ? 'Save Changes' : 'Add Facility' ?>
            </button>
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('facilities')) ?>">Cancel</a>
        </form>
    </div>

<?php else: ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
        <a class="t8-btn t8-btn-accent" href="<?= e(page_url('facilities', ['action' => 'create'])) ?>">
            <i class="fa-solid fa-plus"></i> Add Facility
        </a>
    </div>

    <?php if ($facilities === []): ?>
        <div class="t8-empty">
            No facilities have been added yet.
            <br><br>
            <a class="t8-btn t8-btn-accent" href="<?= e(page_url('facilities', ['action' => 'create'])) ?>">
                <i class="fa-solid fa-plus"></i> Add Your First Facility
            </a>
        </div>
    <?php else: ?>
        <div class="t8-table-wrap">
            <table class="t8-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Location</th>
                        <th>Capacity</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($facilities as $f): ?>
                        <tr>
                            <td><?= e($f['name']) ?></td>
                            <td><?= e((string) ($f['facility_type'] ?? '—')) ?></td>
                            <td><?= e($f['location']) ?></td>
                            <td><?= e((string) $f['capacity']) ?></td>
                            <td><span class="t8-badge t8-badge-<?= e($f['status']) ?>"><?= e(ucfirst($f['status'])) ?></span></td>
                            <td style="display:flex; gap:8px; flex-wrap:wrap;">
                                <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('facilities', ['action' => 'edit', 'id' => $f['id']])) ?>">
                                    <i class="fa-solid fa-pen"></i> Edit
                                </a>
                                <?php if ($f['status'] === 'active'): ?>
                                    <form method="post" action="<?= e(page_url('facilities', ['action' => 'archive'])) ?>" onsubmit="return confirm('Archive this facility? It will no longer be available for new reservations.');">
                                        <?= t8_csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= e((string) $f['id']) ?>">
                                        <button class="t8-btn t8-btn-warning t8-btn-sm" type="submit">
                                            <i class="fa-solid fa-box-archive"></i> Archive
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="<?= e(page_url('facilities', ['action' => 'reactivate'])) ?>">
                                        <?= t8_csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= e((string) $f['id']) ?>">
                                        <button class="t8-btn t8-btn-success t8-btn-sm" type="submit">
                                            <i class="fa-solid fa-rotate-left"></i> Reactivate
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php endif; ?>