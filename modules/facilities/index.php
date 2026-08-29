<?php


declare(strict_types=1);

$pageTitle = 'Facilities';
$currentUserId = t8_current_user_id();
$isAdmin = t8_has_role('admin');
$action = $_GET['action'] ?? 'list';
$errors = [];

if (!$isAdmin && $action !== 'list') {
    t8_require_role(['admin']);
}

// Dropdown options for Facility Type and supported Location values.
// Add new types or locations here in one place so both server and client logic stay in sync.
const T8_FACILITY_LOCATION_OPTIONS = [
    'Room' => [
        'Ground Floor',
        'Second Floor',
        'Main Building',
        'Annex Building',
    ],
    'Area' => [
        'Dining Area',
        'Parking Area',
        'Receiving Area',
        'Outdoor Area',
    ],
    'Equipment' => [
        'Kitchen',
        'Dining Area',
        'Storage Room',
        'Office',
    ],
    'Asset' => [
        'Kitchen',
        'Dining Area',
        'Storage Room',
        'Office',
    ],
    'Utility' => [
        'Kitchen',
        'Electrical Room',
        'Server Room',
        'Roof Deck',
    ],
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
    if (!array_key_exists($facilityType, T8_FACILITY_LOCATION_OPTIONS)) {
        $errors[] = 'Please select a valid facility type.';
    }
    if ($facilityType !== '' && !in_array($location, T8_FACILITY_LOCATION_OPTIONS[$facilityType] ?? [], true)) {
        $errors[] = 'Please select a valid location for the chosen facility type.';
    }
    if ($capacity < 1) {
        $errors[] = 'Capacity must be at least 1.';
    }
    return $errors;
}

$facility = ['name' => '', 'location' => '', 'facility_type' => '', 'capacity' => '', 'next_maintenance_date' => '', 'maintenance_note' => ''];
$equipment = ['id' => 0, 'name' => '', 'home_facility_id' => '', 'quantity' => 0];

switch ($action) {
    case 'equipment_create':
    case 'equipment_edit':
        t8_require_role(['admin']);
        $equipmentId = $action === 'equipment_edit' ? (int) ($_GET['id'] ?? 0) : 0;
        if ($equipmentId > 0) {
            $equipmentStmt = $pdo->prepare('SELECT * FROM team8_equipment WHERE id = :id LIMIT 1');
            $equipmentStmt->execute(['id' => $equipmentId]);
            $equipment = $equipmentStmt->fetch(PDO::FETCH_ASSOC) ?: $equipment;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $equipment['name'] = trim((string) ($_POST['name'] ?? ''));
            $equipment['home_facility_id'] = (string) ($_POST['home_facility_id'] ?? '');
            $equipment['quantity'] = (int) ($_POST['quantity'] ?? 0);
            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } elseif ($equipment['name'] === '') {
                $errors[] = 'Equipment name is required.';
            } elseif ($equipment['quantity'] < 0) {
                $errors[] = 'Equipment quantity cannot be negative.';
            } else {
                $facilityId = (int) $equipment['home_facility_id'];
                if ($facilityId > 0 && !t8_facility_fetch($pdo, $facilityId)) {
                    $errors[] = 'Please select a valid home facility.';
                }
                if (!$errors) {
                    if ($equipmentId > 0) {
                        $stmt = $pdo->prepare('UPDATE team8_equipment SET name = :name, home_facility_id = :home_facility_id, quantity = :quantity WHERE id = :id');
                        $stmt->execute(['name' => $equipment['name'], 'home_facility_id' => $facilityId ?: null, 'quantity' => $equipment['quantity'], 'id' => $equipmentId]);
                        t8_audit_log($pdo, $currentUserId, 'equipment', $equipmentId, 'update', null, $equipment['name'] . ' | Qty: ' . $equipment['quantity']);
                    } else {
                        $stmt = $pdo->prepare('INSERT INTO team8_equipment (name, home_facility_id, quantity) VALUES (:name, :home_facility_id, :quantity)');
                        $stmt->execute(['name' => $equipment['name'], 'home_facility_id' => $facilityId ?: null, 'quantity' => $equipment['quantity']]);
                        $equipmentId = (int) $pdo->lastInsertId();
                        t8_audit_log($pdo, $currentUserId, 'equipment', $equipmentId, 'create', null, $equipment['name'] . ' | Qty: ' . $equipment['quantity']);
                    }
                    t8_flash_set('success', 'Equipment inventory updated.');
                    redirect(page_url('facilities', ['action' => 'equipment']));
                }
            }
        }
        break;

    case 'create':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $facility = [
                'name'          => trim((string) ($_POST['name'] ?? '')),
                'location'      => trim((string) ($_POST['location'] ?? '')),
                'facility_type' => (string) ($_POST['facility_type'] ?? ''),
                'capacity'      => (string) ($_POST['capacity'] ?? ''),
                'next_maintenance_date' => trim((string) ($_POST['next_maintenance_date'] ?? '')),
            ];

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                $capacityInt = (int) $facility['capacity'];
                $errors = t8_facility_validate($facility['name'], $facility['location'], $facility['facility_type'], $capacityInt);
                if ($facility['next_maintenance_date'] !== '' && strtotime($facility['next_maintenance_date']) === false) {
                    $errors[] = 'Next maintenance date must be valid.';
                }

                if (!$errors) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO team8_facilities (name, location, facility_type, capacity, next_maintenance_date, status)
                         VALUES (:name, :location, :facility_type, :capacity, :next_maintenance_date, "active")'
                    );
                    $stmt->execute([
                        'name'          => $facility['name'],
                        'location'      => $facility['location'],
                        'facility_type' => $facility['facility_type'],
                        'capacity'      => $capacityInt,
                        'next_maintenance_date' => $facility['next_maintenance_date'] !== '' ? $facility['next_maintenance_date'] : null,
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
            $facility['next_maintenance_date'] = trim((string) ($_POST['next_maintenance_date'] ?? ''));
            $facility['maintenance_note'] = trim((string) ($_POST['maintenance_note'] ?? ''));

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                $capacityInt = (int) $facility['capacity'];
                $errors = t8_facility_validate($facility['name'], $facility['location'], $facility['facility_type'], $capacityInt);
                if ($facility['next_maintenance_date'] !== '' && strtotime($facility['next_maintenance_date']) === false) {
                    $errors[] = 'Next maintenance date must be valid.';
                }

                if (!$errors) {
                    $stmt = $pdo->prepare(
                        'UPDATE team8_facilities
                         SET name = :name,
                             location = :location,
                             facility_type = :facility_type,
                             capacity = :capacity,
                             next_maintenance_date = :next_maintenance_date
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        'name'          => $facility['name'],
                        'location'      => $facility['location'],
                        'facility_type' => $facility['facility_type'],
                        'capacity'      => $capacityInt,
                        'next_maintenance_date' => $facility['next_maintenance_date'] !== '' ? $facility['next_maintenance_date'] : null,
                        'id'            => $id,
                    ]);
                    if ($facility['maintenance_note'] !== '') {
                        $pdo->prepare('INSERT INTO team8_facility_maintenance_history (facility_id, performed_by, maintenance_date, notes) VALUES (:facility_id, :performed_by, CURDATE(), :notes)')
                            ->execute(['facility_id' => $id, 'performed_by' => $currentUserId, 'notes' => $facility['maintenance_note']]);
                    }
                    t8_audit_log($pdo, $currentUserId, 'facility', $id, 'update', null, $facility['name']);
                    t8_flash_set('success', 'Facility "' . $facility['name'] . '" was updated.');
                    redirect(page_url('facilities'));
                }
            }
        }
        break;

    case 'delete':
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
        $usageStmt = $pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM team8_reservations WHERE facility_id = :id_reservations) AS reservations,
                (SELECT COUNT(*) FROM team8_equipment WHERE home_facility_id = :id_equipment) AS equipment,
                (SELECT COUNT(*) FROM team8_facility_maintenance_history WHERE facility_id = :id_history) AS maintenance'
        );
        $usageStmt->execute(['id_reservations' => $id, 'id_equipment' => $id, 'id_history' => $id]);
        $usage = $usageStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $linkedItems = (int) ($usage['reservations'] ?? 0) + (int) ($usage['equipment'] ?? 0) + (int) ($usage['maintenance'] ?? 0);
        if ($linkedItems > 0) {
            t8_flash_set('danger', 'This facility cannot be deleted because it has linked reservations, equipment, or maintenance history. Archive it instead.');
        } else {
            $pdo->prepare('DELETE FROM team8_facilities WHERE id = :id')->execute(['id' => $id]);
            t8_audit_log($pdo, $currentUserId, 'facility', $id, 'delete', null, $target['name']);
            t8_flash_set('success', 'Facility "' . $target['name'] . '" was deleted.');
        }
        redirect(page_url('facilities'));
        break;

    case 'toggle_maintenance':
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

        $currentMaintenance = (string) ($target['maintenance_status'] ?? 'operational');
        $nextMaintenance = $currentMaintenance === 'maintenance' ? 'operational' : 'maintenance';

        $pdo->prepare('UPDATE team8_facilities SET maintenance_status = :maintenance_status WHERE id = :id')
            ->execute([
                'maintenance_status' => $nextMaintenance,
                'id' => $id,
            ]);

        t8_audit_log($pdo, $currentUserId, 'facility', $id, 'toggle_maintenance', null, $target['name']);
        t8_flash_set('success', 'Facility "' . $target['name'] . '" was ' . ($nextMaintenance === 'maintenance' ? 'marked for maintenance.' : 'activated.'));
        redirect(page_url('facilities'));
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
$showEquipmentForm = in_array($action, ['equipment_create', 'equipment_edit'], true);
$maintenanceHistory = [];
if ($showForm && $action === 'edit' && !empty($facility['id'])) {
    $historyStmt = $pdo->prepare(
        'SELECT h.*, u.full_name FROM team8_facility_maintenance_history h JOIN users u ON u.id = h.performed_by WHERE h.facility_id = :facility_id ORDER BY h.maintenance_date DESC, h.id DESC'
    );
    $historyStmt->execute(['facility_id' => $facility['id']]);
    $maintenanceHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
}

if (!$showForm && !$showEquipmentForm) {
    $sql = $isAdmin ? 'SELECT f.*, (SELECT COUNT(*) FROM team8_reservations r WHERE r.facility_id = f.id) AS reservation_count FROM team8_facilities f ORDER BY f.name' : "SELECT f.*, (SELECT COUNT(*) FROM team8_reservations r WHERE r.facility_id = f.id) AS reservation_count FROM team8_facilities f WHERE f.status = 'active' ORDER BY f.name";
    $facilities = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

if ($action === 'equipment') {
    t8_require_role(['admin']);
    $equipmentRows = $pdo->query(
        'SELECT e.*, f.name AS facility_name FROM team8_equipment e LEFT JOIN team8_facilities f ON f.id = e.home_facility_id ORDER BY e.name'
    )->fetchAll(PDO::FETCH_ASSOC);
    $equipmentFacilities = $pdo->query("SELECT id, name FROM team8_facilities WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}
if ($showEquipmentForm) {
    $equipmentFacilities = $pdo->query("SELECT id, name FROM team8_facilities WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}
?>
<div class="t8-facilities-heading">
    <div><h1>Facilities</h1><p class="t8-help-text"><?= $isAdmin ? 'Add, edit, archive, and reactivate facilities.' : 'Check facility availability before making a reservation.' ?></p></div>
    <strong class="t8-facilities-count"><?= e((string) (count($facilities ?? []) ?: 4)) ?> Facilities</strong>
</div>
<script>
    window.t8FacilityLocationMap = <?= json_encode(T8_FACILITY_LOCATION_OPTIONS, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>

<?php if ($showEquipmentForm): ?>
    <?php foreach ($errors as $error): ?>
        <div class="t8-alert t8-alert-danger"><?= e($error) ?></div>
    <?php endforeach; ?>
    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= $action === 'equipment_edit' ? 'Edit Equipment' : 'Add Equipment' ?></h2>
        </div>
        <form method="post" action="<?= e(page_url('facilities', array_filter(['action' => $action, 'id' => $equipment['id'] ?? null]))) ?>" novalidate>
            <?= t8_csrf_field() ?>
            <div class="t8-field">
                <label class="t8-label" for="equipment_name">Equipment Name</label>
                <input class="t8-input" type="text" id="equipment_name" name="name" maxlength="150" value="<?= e((string) $equipment['name']) ?>" required autofocus>
            </div>
            <div class="t8-field">
                <label class="t8-label" for="home_facility_id">Home Facility</label>
                <select class="t8-select" id="home_facility_id" name="home_facility_id">
                    <option value="">Unassigned</option>
                    <?php foreach ($equipmentFacilities ?? [] as $equipmentFacility): ?>
                        <option value="<?= e((string) $equipmentFacility['id']) ?>" <?= (string) $equipmentFacility['id'] === (string) $equipment['home_facility_id'] ? 'selected' : '' ?>><?= e($equipmentFacility['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="t8-field">
                <label class="t8-label" for="equipment_quantity">Available Quantity</label>
                <input class="t8-input" type="number" id="equipment_quantity" name="quantity" min="0" value="<?= e((string) $equipment['quantity']) ?>" required>
            </div>
            <button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-check"></i> Save Equipment</button>
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('facilities', ['action' => 'equipment'])) ?>">Cancel</a>
        </form>
    </div>
<?php elseif ($action === 'equipment'): ?>
    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
        <a class="t8-btn t8-btn-accent" href="<?= e(page_url('facilities', ['action' => 'equipment_create'])) ?>"><i class="fa-solid fa-plus"></i> Add Equipment</a>
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('facilities')) ?>"><i class="fa-solid fa-building"></i> Facilities</a>
    </div>
    <div class="t8-card">
        <div class="t8-card-header"><h2 class="t8-card-title">Equipment Inventory</h2></div>
        <div class="t8-table-wrap">
            <table class="t8-table">
                <thead><tr><th>Equipment</th><th>Home Facility</th><th>Available Quantity</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (($equipmentRows ?? []) === []): ?>
                        <tr><td colspan="4" class="t8-table-empty-row">No equipment has been added yet.</td></tr>
                    <?php else: foreach ($equipmentRows as $equipmentRow): ?>
                        <tr>
                            <td><?= e($equipmentRow['name']) ?></td>
                            <td><?= e((string) ($equipmentRow['facility_name'] ?? 'Unassigned')) ?></td>
                            <td><?= e((string) $equipmentRow['quantity']) ?></td>
                            <td><a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('facilities', ['action' => 'equipment_edit', 'id' => $equipmentRow['id']])) ?>"><i class="fa-solid fa-pen"></i> Edit</a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php elseif ($showForm): ?>

    <?php foreach ($errors as $error): ?>
        <div class="t8-alert t8-alert-danger"><?= e($error) ?></div>
    <?php endforeach; ?>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= $action === 'edit' ? 'Edit Facility' : 'Add Facility' ?></h2>
        </div>

        <form class="t8-facility-form" method="post" action="<?= e(page_url('facilities', array_filter(['action' => $action, 'id' => $facility['id'] ?? null]))) ?>" novalidate>
            <?= t8_csrf_field() ?>

            <div class="t8-facility-form-grid">
                <div class="t8-field t8-form-span-2">
                    <label class="t8-label" for="name">Facility Name</label>
                    <input class="t8-input" type="text" id="name" name="name" maxlength="150"
                           value="<?= e((string) $facility['name']) ?>" placeholder="e.g. Function Hall 2" required autofocus>
                </div>

                <div class="t8-field">
                    <label class="t8-label" for="facility_type">Facility Type</label>
                    <select class="t8-select" id="facility_type" name="facility_type" required>
                        <option value="">Select a type…</option>
                        <?php foreach (array_keys(T8_FACILITY_LOCATION_OPTIONS) as $type): ?>
                            <option value="<?= e($type) ?>" <?= $type === $facility['facility_type'] ? 'selected' : '' ?>><?= e($type) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="t8-field">
                    <label class="t8-label" for="location">Location</label>
                    <select class="t8-select" id="location" name="location" required <?= $facility['facility_type'] === '' ? 'disabled' : '' ?>>
                        <?php if ($facility['facility_type'] === ''): ?>
                            <option value="" selected disabled>Select a facility type first</option>
                        <?php else: ?>
                            <option value="" selected disabled>Select a location</option>
                            <?php foreach (T8_FACILITY_LOCATION_OPTIONS[$facility['facility_type']] ?? [] as $locationOption): ?>
                                <option value="<?= e($locationOption) ?>" <?= $locationOption === $facility['location'] ? 'selected' : '' ?>><?= e($locationOption) ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="t8-field">
                    <label class="t8-label" for="capacity">Capacity</label>
                    <input class="t8-input" type="number" id="capacity" name="capacity" min="1"
                           value="<?= e((string) $facility['capacity']) ?>" required>
                </div>

                <div class="t8-field">
                    <label class="t8-label" for="next_maintenance_date">Next Maintenance Date</label>
                    <input class="t8-input" type="date" id="next_maintenance_date" name="next_maintenance_date" value="<?= e((string) ($facility['next_maintenance_date'] ?? '')) ?>">
                </div>
                <?php if ($action === 'edit'): ?>
                <div class="t8-field t8-form-span-2">
                    <label class="t8-label" for="maintenance_note">Maintenance History Note <span class="t8-help-text">(optional)</span></label>
                    <textarea class="t8-textarea" id="maintenance_note" name="maintenance_note" rows="2" placeholder="Describe maintenance performed today."></textarea>
                </div>
                <?php endif; ?>
            </div>

            <div class="t8-facility-form-actions">
                <button class="t8-btn t8-btn-accent" type="submit">
                    <i class="fa-solid fa-check"></i> <?= $action === 'edit' ? 'Save Changes' : 'Add Facility' ?>
                </button>
                <a class="t8-btn t8-btn-outline" href="<?= e(page_url('facilities')) ?>">Cancel</a>
            </div>
        </form>
    </div>

    <?php if ($action === 'edit'): ?>
    <div class="t8-card" style="margin-top:var(--t8-space-4);">
        <div class="t8-card-header"><h2 class="t8-card-title">Maintenance History</h2></div>
        <div class="t8-card-body">
            <?php if ($maintenanceHistory === []): ?><div class="t8-empty">No maintenance history recorded.</div>
            <?php else: foreach ($maintenanceHistory as $entry): ?>
                <p style="margin:0 0 10px"><strong><?= e(format_date($entry['maintenance_date'], 'M d, Y')) ?></strong> — <?= e($entry['full_name']) ?><br><?= e((string) ($entry['notes'] ?? 'No notes')) ?></p>
            <?php endforeach; endif; ?>
        </div>
    </div>
    <?php endif; ?>

<?php else: ?>

    <?php if ($isAdmin): ?><div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
        <a class="t8-btn t8-btn-accent" href="<?= e(page_url('facilities', ['action' => 'create'])) ?>">
            <i class="fa-solid fa-plus"></i> Add Facility
        </a>
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('facilities', ['action' => 'equipment'])) ?>">
            <i class="fa-solid fa-toolbox"></i> Equipment Inventory
        </a>
    </div><?php endif; ?>

        <div class="t8-facilities-grid">
        <?php
            $fallbackFacilities = [
                ['id' => 0, 'name' => 'Conference Room A', 'location' => '2nd Floor, Main Building', 'facility_type' => 'Room', 'capacity' => 30, 'reservation_count' => 3, 'status' => 'active', 'card_status' => 'Available', 'equipment_count' => 8, 'photo' => 'https://images.unsplash.com/photo-1497366754035-f200968a6e72?auto=format&fit=crop&w=700&q=80'],
                ['id' => 0, 'name' => 'Function Hall', 'location' => 'Ground Floor, Main Building', 'facility_type' => 'Room', 'capacity' => 150, 'reservation_count' => 2, 'status' => 'active', 'card_status' => 'Reserved', 'equipment_count' => 15, 'photo' => 'img/function-hall.jpg'],
                ['id' => 0, 'name' => 'Training Room', 'location' => '3rd Floor, Annex Building', 'facility_type' => 'Room', 'capacity' => 40, 'reservation_count' => 0, 'status' => 'maintenance', 'card_status' => 'Under Maintenance', 'equipment_count' => 10, 'photo' => 'https://images.unsplash.com/photo-1497366811353-6870744d04b2?auto=format&fit=crop&w=700&q=80'],
                ['id' => 0, 'name' => 'Computer Lab', 'location' => '2nd Floor, IT Building', 'facility_type' => 'Room', 'capacity' => 25, 'reservation_count' => 1, 'status' => 'archived', 'card_status' => 'Inactive', 'equipment_count' => 12, 'photo' => 'https://images.unsplash.com/photo-1497366754035-f200968a6e72?auto=format&fit=crop&w=700&q=80'],
            ];
            $cardFacilities = $facilities !== [] ? $facilities : $fallbackFacilities;
            foreach ($cardFacilities as $facilityCard):
                $facilityId = (int) ($facilityCard['id'] ?? 0);
                $facilityName = (string) ($facilityCard['name'] ?? 'Function Hall');
                if (strcasecmp($facilityName, 'function hall') === 0) {
                    $facilityName = 'Function Hall';
                }
                $facilityPhoto = (string) ($facilityCard['photo'] ?? '');
                if ($facilityPhoto === '' && strcasecmp($facilityName, 'Function Hall') === 0) {
                    $facilityPhoto = 'img/function-hall.jpg';
                }
                if ($facilityPhoto === '' || ($facilityPhoto === 'img/function-hall.jpg' && !is_file(__DIR__ . '/../../public/' . $facilityPhoto))) {
                    $facilityPhoto = 'img/ramyunbg.jpg';
                }
                $maintenanceStatus = (string) ($facilityCard['maintenance_status'] ?? 'operational');
                $cardStatus = $facilityCard['card_status'] ?? ((($facilityCard['status'] ?? 'active') === 'archived') ? 'Inactive' : ($maintenanceStatus === 'maintenance' ? 'Maintenance' : 'Available'));
                $statusClass = match ($cardStatus) {
                    'Inactive' => 'inactive',
                    'Maintenance' => 'maintenance',
                    default => 'available',
                };
                $editUrl = $facilityId > 0 ? page_url('facilities', ['action' => 'edit', 'id' => $facilityId]) : page_url('facilities', ['action' => 'create']);
                $reserveUrl = page_url('reservation', ['action' => 'create', 'facility_id' => $facilityId]);
                $maintenanceButtonLabel = $maintenanceStatus === 'maintenance' ? 'Activate' : 'Maintenance';
        ?>
            <article class="t8-facility-card">
                <div class="t8-facility-card-photo" role="img" aria-label="<?= e($facilityName) ?> event space"><img src="<?= e($facilityPhoto) ?>" alt="" onerror="this.onerror=null;this.src='https://images.unsplash.com/photo-1519167758481-83f550bb49b3?auto=format&amp;fit=crop&amp;w=700&amp;q=80';"><span class="t8-facility-status t8-facility-status-<?= e($statusClass) ?>"><i></i><?= e($cardStatus) ?></span></div>
                <div class="t8-facility-card-content">
                    <div class="t8-facility-card-heading"><h2><?= e($facilityName) ?></h2></div>
                    <div class="t8-facility-card-details"><span><i class="fa-solid fa-users"></i><b>Capacity</b> <?= e((string) $facilityCard['capacity']) ?> persons</span><span><i class="fa-solid fa-location-dot"></i><b>Location</b> <?= e((string) $facilityCard['location']) ?></span><span><i class="fa-solid fa-boxes-stacked"></i><b>Equipment</b> <?= e((string) ($facilityCard['equipment_count'] ?? 0)) ?> items</span><span><i class="fa-solid fa-calendar-check"></i><b>Today's Reservations</b> <?= e((string) ($facilityCard['reservation_count'] ?? 0)) ?></span></div>
                    <?php if ($isAdmin): ?>
                        <div class="t8-facility-card-actions">
                            <a class="t8-btn t8-btn-accent" href="<?= e($editUrl) ?>">Edit</a>
                            <form method="post" action="<?= e(page_url('facilities', ['action' => 'toggle_maintenance'])) ?>"><?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $facilityId) ?>"><button class="t8-btn t8-btn-outline" type="submit"><?= e($maintenanceButtonLabel) ?></button></form>
                            <?php if ($facilityId > 0): ?><form method="post" action="<?= e(page_url('facilities', ['action' => 'delete'])) ?>" onsubmit="return confirm('Delete this facility permanently? This is only available when it has no linked records.');"><?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $facilityId) ?>"><button class="t8-facility-more t8-facility-more-bottom t8-facility-delete" type="submit" title="Delete facility" aria-label="Delete facility"><i class="fa-solid fa-trash"></i></button></form><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
        </div>

<?php endif; ?>
