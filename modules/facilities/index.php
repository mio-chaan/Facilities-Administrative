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

/**
 * Fetch every facility location, newest name-order, from the DB.
 * Returns [] (never throws) if the table/migration isn't present yet
 * so the page can render a graceful empty state instead of a 500.
 */
function t8_facility_locations_fetch(PDO $pdo): array
{
    try {
        return $pdo->query('SELECT id, name FROM team8_facility_locations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/** Fetch a single facility row or null. */
function t8_facility_fetch(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM team8_facilities WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Shared validation for both create and edit forms.
 * $validLocations is the list of currently allowed location NAMEs
 * (from team8_facility_locations) — when it's non-empty, the chosen
 * location must be one of them (the dropdown never lets a user type
 * an arbitrary value, only pick or add-then-pick).
 */
function t8_facility_validate(string $name, string $location, string $facilityType, int $capacity, array $validLocations): array
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
    } elseif ($validLocations !== [] && !in_array($location, $validLocations, true)) {
        $errors[] = 'Please select a location from the list, or add it first with "+ Add Location".';
    }
    if ($facilityType === '') {
        $errors[] = 'Please select a facility type.';
    }
    if ($capacity < 1) {
        $errors[] = 'Capacity must be at least 1.';
    }
    return $errors;
}

$facility = ['name' => '', 'location' => '', 'facility_type' => '', 'capacity' => '', 'next_maintenance_date' => '', 'maintenance_note' => ''];
$equipment = ['id' => 0, 'name' => '', 'home_facility_id' => '', 'quantity' => 0];

switch ($action) {
    // ---- Dynamic "+ Add Location" (AJAX, JSON) ----
    case 'location_create':
        t8_require_role(['admin']);

        // FIX: discard every buffered output level (header/navbar/shell
        // markup the front controller already wrote for this request)
        // BEFORE emitting the Content-Type header and JSON body below.
        // Without this, the response body is "<!DOCTYPE html>...json",
        // which is what produced the "Unexpected token '<'" error in
        // the Add Location modal.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed.']);
            exit;
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            http_response_code(419);
            echo json_encode(['error' => 'Your session expired. Please refresh the page and try again.']);
            exit;
        }

        $newLocationName = trim((string) ($_POST['name'] ?? ''));
        if ($newLocationName === '') {
            echo json_encode(['error' => 'Please enter a location name.']);
            exit;
        }
        if (mb_strlen($newLocationName) > 150) {
            echo json_encode(['error' => 'Location name must be 150 characters or fewer.']);
            exit;
        }

        try {
            $existingStmt = $pdo->prepare('SELECT id, name FROM team8_facility_locations WHERE name = :name LIMIT 1');
            $existingStmt->execute(['name' => $newLocationName]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                // Already exists — treat as success so the UI can just select it.
                echo json_encode(['id' => (int) $existing['id'], 'name' => $existing['name']]);
                exit;
            }

            $pdo->prepare('INSERT INTO team8_facility_locations (name) VALUES (:name)')->execute(['name' => $newLocationName]);
            $newLocationId = (int) $pdo->lastInsertId();
            t8_audit_log($pdo, $currentUserId, 'facility_location', $newLocationId, 'create', null, $newLocationName);
            echo json_encode(['id' => $newLocationId, 'name' => $newLocationName]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Could not add this location right now. Please try again.']);
        }
        exit;

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
        $facilityLocations = t8_facility_locations_fetch($pdo);
        $validLocationNames = array_column($facilityLocations, 'name');

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
                $errors = t8_facility_validate($facility['name'], $facility['location'], $facility['facility_type'], $capacityInt, $validLocationNames);
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
        $facilityLocations = t8_facility_locations_fetch($pdo);
        $validLocationNames = array_column($facilityLocations, 'name');

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
                $errors = t8_facility_validate($facility['name'], $facility['location'], $facility['facility_type'], $capacityInt, $validLocationNames);
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

// The "Location" dropdown is always driven by the DB. On GET requests
// to create/edit (no POST branch above already fetched it), fetch it
// here too so the form always has fresh data.
if ($showForm && !isset($facilityLocations)) {
    $facilityLocations = t8_facility_locations_fetch($pdo);
}
$facilityLocationsUnavailable = $showForm && $facilityLocations === [] && $_SERVER['REQUEST_METHOD'] === 'GET';

// ---------------------------------------------------------------
// Facility list (cards grid) — fully dynamic. No hardcoded demo
// facilities: $facilities (and therefore the card grid + the
// "X Facilities" count) come only from team8_facilities. A DB error
// here is caught so the rest of the page (nav, sidebar, etc.) still
// renders, with a clear inline error instead of a fatal.
// ---------------------------------------------------------------
$facilities = [];
$facilitiesError = null;
if (!$showForm && !$showEquipmentForm) {
    try {
        $sql = $isAdmin
            ? 'SELECT f.*, (SELECT COUNT(*) FROM team8_reservations r WHERE r.facility_id = f.id) AS reservation_count FROM team8_facilities f ORDER BY f.name'
            : "SELECT f.*, (SELECT COUNT(*) FROM team8_reservations r WHERE r.facility_id = f.id) AS reservation_count FROM team8_facilities f WHERE f.status = 'active' ORDER BY f.name";
        $facilities = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $facilitiesError = 'Facilities could not be loaded right now. Please refresh the page or try again shortly.';
    }
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
    <strong class="t8-facilities-count"><?= e((string) count($facilities)) ?> Facilit<?= count($facilities) === 1 ? 'y' : 'ies' ?></strong>
</div>

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

    <?php if ($facilityLocationsUnavailable): ?>
        <div class="t8-alert t8-alert-warning">
            No locations have been added yet. Use "+ Add Location" in the dropdown below to create the first one.
        </div>
    <?php endif; ?>

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
                        <?php foreach (['Room', 'Area', 'Equipment', 'Asset', 'Utility'] as $type): ?>
                            <option value="<?= e($type) ?>" <?= $type === $facility['facility_type'] ? 'selected' : '' ?>><?= e($type) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="t8-field">
                    <label class="t8-label" for="location">Location</label>
                    <div class="t8-location-dropdown"
                         id="t8LocationDropdown"
                         data-create-url="<?= e(page_url('facilities', ['action' => 'location_create'])) ?>"
                         data-csrf="<?= e(t8_csrf_token()) ?>">
                        <button type="button" class="t8-location-trigger" id="t8LocationTrigger" aria-haspopup="listbox" aria-expanded="false">
                            <span id="t8LocationTriggerText"><?= $facility['location'] !== '' ? e($facility['location']) : 'Select a location' ?></span>
                            <i class="fa-solid fa-chevron-down"></i>
                        </button>
                        <input type="hidden" id="location" name="location" value="<?= e((string) $facility['location']) ?>" required>

                        <div class="t8-location-panel" id="t8LocationPanel" role="listbox" hidden>
                            <ul class="t8-location-options" id="t8LocationOptions">
                                <?php foreach ($facilityLocations as $loc): ?>
                                    <li role="option" data-value="<?= e($loc['name']) ?>" class="t8-location-option"><?= e($loc['name']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if ($facilityLocations === []): ?>
                                <p class="t8-location-empty-msg">No locations yet. Add the first one below.</p>
                            <?php endif; ?>
                            <button type="button" class="t8-location-add" id="t8LocationAdd">
                                <i class="fa-solid fa-plus"></i> Add Location
                            </button>
                        </div>
                    </div>
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

    <!--
        "+ Add Location" modal — deliberately a SIBLING of the facility
        <form> above, not nested inside it (see the FIX note at the top
        of this file for why). Only ever asks for the location name —
        no facility name/type/capacity/quantity fields live here.
        Closes via the × button, Cancel, clicking the backdrop, or
        Escape (native <dialog> behavior). Submits via AJAX to the same
        `location_create` action used before, with the same CSRF token
        already present on the page (data-csrf on #t8LocationDropdown).
    -->
    <dialog id="t8LocationAddModal" class="t8-location-modal">
        <form id="t8LocationAddForm" novalidate>
            <div class="t8-location-modal-header">
                <strong>Add Location</strong>
                <button type="button" id="t8LocationAddModalClose" class="t8-location-modal-close" aria-label="Close">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="t8-location-modal-body">
                <div class="t8-field" style="margin-bottom:0;">
                    <label class="t8-label" for="t8LocationAddInput">Location Name</label>
                    <input type="text" id="t8LocationAddInput" class="t8-input" placeholder="e.g. Ground Floor" maxlength="150" autocomplete="off">
                </div>
                <p class="t8-location-modal-error" id="t8LocationAddError" hidden></p>
            </div>
            <div class="t8-location-modal-footer">
                <button type="button" id="t8LocationAddCancel" class="t8-btn t8-btn-outline">Cancel</button>
                <button type="submit" id="t8LocationAddSubmit" class="t8-btn t8-btn-accent">
                    <i class="fa-solid fa-plus"></i> Add Location
                </button>
            </div>
        </form>
    </dialog>

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

    <?php if ($facilitiesError !== null): ?>
        <div class="t8-alert t8-alert-danger t8-facilities-error"><?= e($facilitiesError) ?></div>
    <?php elseif ($facilities === []): ?>
        <div class="t8-empty">
            <?= $isAdmin ? 'No facilities yet. Add your first facility to get started.' : 'No facilities are available right now.' ?>
            <?php if ($isAdmin): ?>
                <br><br>
                <a class="t8-btn t8-btn-accent" href="<?= e(page_url('facilities', ['action' => 'create'])) ?>">
                    <i class="fa-solid fa-plus"></i> Add Facility
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="t8-facilities-grid">
        <?php foreach ($facilities as $facilityCard):
                $facilityId = (int) ($facilityCard['id'] ?? 0);
                $facilityName = (string) ($facilityCard['name'] ?? '');
                $facilityPhoto = 'img/ramyunbg.jpg';
                $maintenanceStatus = (string) ($facilityCard['maintenance_status'] ?? 'operational');
                $cardStatus = (($facilityCard['status'] ?? 'active') === 'archived')
                    ? 'Inactive'
                    : ($maintenanceStatus === 'maintenance' ? 'Maintenance' : 'Available');
                $statusClass = match ($cardStatus) {
                    'Inactive' => 'inactive',
                    'Maintenance' => 'maintenance',
                    default => 'available',
                };
                $editUrl = page_url('facilities', ['action' => 'edit', 'id' => $facilityId]);
                $maintenanceButtonLabel = $maintenanceStatus === 'maintenance' ? 'Activate' : 'Maintenance';

                $equipmentCountStmt = $pdo->prepare('SELECT COUNT(*) FROM team8_equipment WHERE home_facility_id = :id');
                $equipmentCountStmt->execute(['id' => $facilityId]);
                $equipmentCount = (int) $equipmentCountStmt->fetchColumn();

                $todaysReservationsStmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM team8_reservations
                     WHERE facility_id = :id
                       AND status IN ('pending', 'approved')
                       AND DATE(COALESCE(start_time, schedule, expected_return_date)) = CURDATE()"
                );
                $todaysReservationsStmt->execute(['id' => $facilityId]);
                $todaysReservations = (int) $todaysReservationsStmt->fetchColumn();
        ?>
            <article class="t8-facility-card">
                <div class="t8-facility-card-photo" role="img" aria-label="<?= e($facilityName) ?>"><img src="<?= e($facilityPhoto) ?>" alt="" onerror="this.onerror=null;this.src='https://images.unsplash.com/photo-1519167758481-83f550bb49b3?auto=format&amp;fit=crop&amp;w=700&amp;q=80';"><span class="t8-facility-status t8-facility-status-<?= e($statusClass) ?>"><i></i><?= e($cardStatus) ?></span></div>
                <div class="t8-facility-card-content">
                    <div class="t8-facility-card-heading"><h2><?= e($facilityName) ?></h2></div>
                    <div class="t8-facility-card-details">
                        <span><i class="fa-solid fa-users"></i><b>Capacity</b> <?= e((string) $facilityCard['capacity']) ?> persons</span>
                        <span><i class="fa-solid fa-location-dot"></i><b>Location</b> <?= e((string) $facilityCard['location']) ?></span>
                        <span><i class="fa-solid fa-boxes-stacked"></i><b>Equipment</b> <?= e((string) $equipmentCount) ?> items</span>
                        <span><i class="fa-solid fa-calendar-check"></i><b>Today's Reservations</b> <?= e((string) $todaysReservations) ?></span>
                    </div>
                    <?php if ($isAdmin): ?>
                        <div class="t8-facility-card-actions">
                            <a class="t8-btn t8-btn-accent" href="<?= e($editUrl) ?>">Edit</a>
                            <form method="post" action="<?= e(page_url('facilities', ['action' => 'toggle_maintenance'])) ?>"><?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $facilityId) ?>"><button class="t8-btn t8-btn-outline" type="submit"><?= e($maintenanceButtonLabel) ?></button></form>
                            <form method="post" action="<?= e(page_url('facilities', ['action' => 'delete'])) ?>" onsubmit="return confirm('Delete this facility permanently? This is only available when it has no linked records.');"><?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $facilityId) ?>"><button class="t8-facility-more t8-facility-more-bottom t8-facility-delete" type="submit" title="Delete facility" aria-label="Delete facility"><i class="fa-solid fa-trash"></i></button></form>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>
