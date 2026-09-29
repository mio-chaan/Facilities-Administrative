<?php
/** Reusable contract party directory. */
declare(strict_types=1);

t8_require_role(['admin', 'legal_officer', 'facilities_staff', 'employee']);
$pageTitle = 'Party Registry';
$canCreate = t8_has_role('admin') || t8_has_role('legal_officer');

if (isset($_GET['ajax_create_party']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$canCreate || !t8_csrf_verify($_POST['csrf_token'] ?? null)) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized or invalid CSRF token.']);
        exit;
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    $primaryContact = trim((string) ($_POST['primary_contact'] ?? ''));
    $contactPhone = trim((string) ($_POST['contact_phone'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));
    $contactEmail = trim((string) ($_POST['contact_email'] ?? ''));
    $tin = trim((string) ($_POST['tin'] ?? ''));
    $errors = [];
    if ($name === '') $errors[] = 'Party name is required.';
    if ($primaryContact === '') $errors[] = 'Primary contact is required.';
    if ($address === '') $errors[] = 'Address is required.';
    if ($contactEmail !== '' && filter_var($contactEmail, FILTER_VALIDATE_EMAIL) === false) $errors[] = 'Enter a valid email address.';
    if ($tin !== '' && !ctype_digit($tin)) $errors[] = 'TIN must contain only numbers.';
    $cleanPhone = preg_replace('/[^0-9]/', '', $contactPhone);
    if (strlen($cleanPhone) !== 10) $errors[] = 'Phone number must be exactly 10 digits (e.g., 9171234567).';
    if ($errors) {
        echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
        exit;
    }
    try {
        $pdo->prepare(
            'INSERT INTO team8_parties
             (name, trade_name, registration_number, tin, type, contact_email, contact_phone,
              primary_contact, address, authorized_signatory_name, authorized_signatory_position)
             VALUES (:name, :trade, :registration, :tin, :type, :email, :phone, :contact, :address, :signatory, :position)'
        )->execute([
            'name' => $name,
            'trade' => trim((string) ($_POST['trade_name'] ?? '')) ?: null,
            'registration' => trim((string) ($_POST['registration_number'] ?? '')) ?: null,
            'tin' => $tin ?: null,
            'type' => ($_POST['type'] ?? '') === 'individual' ? 'individual' : 'organization',
            'email' => $contactEmail ?: null,
            'phone' => '+63' . $cleanPhone,
            'contact' => $primaryContact,
            'address' => $address,
            'signatory' => trim((string) ($_POST['authorized_signatory_name'] ?? '')) ?: null,
            'position' => trim((string) ($_POST['authorized_signatory_position'] ?? '')) ?: null,
        ]);
        $newId = (int) $pdo->lastInsertId();
        t8_audit_log($pdo, t8_current_user_id(), 'party', $newId, 'create');
        echo json_encode(['success' => true, 'id' => $newId, 'name' => $name]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Party could not be saved.']);
    }
    exit;
}

if (($_GET['action'] ?? '') === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canCreate || !t8_csrf_verify($_POST['csrf_token'] ?? null)) {
        t8_flash_set('danger', 'Your session expired or you are not authorized.');
        redirect(page_url('party_registry'));
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    $primaryContact = trim((string) ($_POST['primary_contact'] ?? ''));
    $contactPhone = trim((string) ($_POST['contact_phone'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));
    $contactEmail = trim((string) ($_POST['contact_email'] ?? ''));
    if ($name === '') {
        t8_flash_set('danger', 'Party name is required.');
        redirect(page_url('party_registry'));
    }
    if ($primaryContact === '') {
        t8_flash_set('danger', 'Primary contact is required.');
        redirect(page_url('party_registry'));
    }
    if ($contactPhone === '') {
        t8_flash_set('danger', 'Phone is required.');
        redirect(page_url('party_registry'));
    }
    $cleanPhone = preg_replace('/[^0-9]/', '', $contactPhone);
    if (strlen($cleanPhone) !== 10) {
        t8_flash_set('danger', 'Phone number must be exactly 10 digits (e.g., 9171234567).');
        redirect(page_url('party_registry'));
    }
    if ($address === '') {
        t8_flash_set('danger', 'Address is required.');
        redirect(page_url('party_registry'));
    }
    if ($contactEmail !== '' && filter_var($contactEmail, FILTER_VALIDATE_EMAIL) === false) {
        t8_flash_set('danger', 'Enter a valid email address.');
        redirect(page_url('party_registry'));
    }
    $tin = trim((string) ($_POST['tin'] ?? ''));
    if ($tin !== '' && !ctype_digit($tin)) {
        t8_flash_set('danger', 'TIN must contain only numbers.');
        redirect(page_url('party_registry'));
    }
    $type = ($_POST['type'] ?? '') === 'individual' ? 'individual' : 'organization';
    try {
        $pdo->prepare(
            'INSERT INTO team8_parties
             (name, trade_name, registration_number, tin, type, contact_email, contact_phone,
              primary_contact, address, authorized_signatory_name, authorized_signatory_position)
             VALUES (:name, :trade, :registration, :tin, :type, :email, :phone, :contact, :address, :signatory, :position)'
        )->execute([
            'name' => $name,
            'trade' => trim((string) ($_POST['trade_name'] ?? '')) ?: null,
            'registration' => trim((string) ($_POST['registration_number'] ?? '')) ?: null,
            'tin' => $tin ?: null,
            'type' => $type,
            'email' => trim((string) ($_POST['contact_email'] ?? '')) ?: null,
            'phone' => '+63' . $cleanPhone,
            'contact' => trim((string) ($_POST['primary_contact'] ?? '')) ?: null,
            'address' => trim((string) ($_POST['address'] ?? '')) ?: null,
            'signatory' => trim((string) ($_POST['authorized_signatory_name'] ?? '')) ?: null,
            'position' => trim((string) ($_POST['authorized_signatory_position'] ?? '')) ?: null,
        ]);
        $newId = (int) $pdo->lastInsertId();
        t8_audit_log($pdo, t8_current_user_id(), 'party', $newId, 'create');
        t8_flash_set('success', 'Party created.');
    } catch (PDOException $e) {
        t8_flash_set('danger', 'Party could not be saved.');
    }
    redirect(page_url('party_registry'));
}

$counts = ['total' => 0, 'organization' => 0, 'individual' => 0];
foreach ($pdo->query('SELECT type, COUNT(*) AS n FROM team8_parties GROUP BY type')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $counts['total'] += (int) $row['n'];
    $counts[$row['type']] = (int) $row['n'];
}
$search = trim((string) ($_GET['search'] ?? ''));
$type = in_array($_GET['type'] ?? '', ['organization', 'individual'], true) ? (string) $_GET['type'] : '';
$where = '1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (p.name LIKE :name OR p.trade_name LIKE :trade OR p.primary_contact LIKE :contact OR p.contact_email LIKE :email)';
    $term = '%' . $search . '%';
    $params = ['name' => $term, 'trade' => $term, 'contact' => $term, 'email' => $term];
}
if ($type !== '') {
    $where .= ' AND p.type = :type';
    $params['type'] = $type;
}
$stmt = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM team8_contract_parties cp WHERE cp.party_id = p.id) AS contract_count FROM team8_parties p WHERE {$where} ORDER BY p.name");
$stmt->execute($params);
$parties = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="t8-card-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--t8-space-4)">
    <div><h1>Contract Management</h1><p class="t8-help-text">Manage contracts throughout their lifecycle.</p></div>
    <?php if ($canCreate): ?><div style="display:flex;gap:8px;align-items:center"><a class="t8-btn t8-btn-outline" href="<?= e(page_url('party_registry')) ?>">Parties</a><a class="t8-btn t8-btn-accent" href="<?= e(page_url('contracts')) ?>">Contract</a></div><?php endif; ?>
</div>
<div class="t8-card-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--t8-space-4)">
    <div><h1>Party Registry</h1><p class="t8-help-text">Organizations and individuals connected to contracts.</p></div>
    <?php if ($canCreate): ?><button type="button" class="t8-btn t8-btn-accent" id="t8OpenPartyModalTop"><i class="fa-solid fa-plus"></i> Add Party</button><?php endif; ?>
</div>
<div class="t8-stat-row">
    <div class="t8-stat-card"><span>Total Parties</span><strong><?= (int) $counts['total'] ?></strong></div>
    <div class="t8-stat-card"><span>Organizations</span><strong><?= (int) $counts['organization'] ?></strong></div>
    <div class="t8-stat-card"><span>Individuals</span><strong><?= (int) $counts['individual'] ?></strong></div>
</div>
<div class="t8-tabs">
    <a class="t8-tab <?= $type === '' ? 'is-active' : '' ?>" href="<?= e(page_url('party_registry')) ?>">All</a>
    <a class="t8-tab <?= $type === 'organization' ? 'is-active' : '' ?>" href="<?= e(page_url('party_registry', ['type' => 'organization'])) ?>">Organizations</a>
    <a class="t8-tab <?= $type === 'individual' ? 'is-active' : '' ?>" href="<?= e(page_url('party_registry', ['type' => 'individual'])) ?>">Individuals</a>
</div>
<form class="t8-filter-row" method="get" action="<?= e(base_url('index.php')) ?>">
    <input type="hidden" name="page" value="party_registry">
    <?php if ($type !== ''): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
    <input class="t8-input" type="search" name="search" value="<?= e($search) ?>" placeholder="Search parties">
</form>
<div class="t8-table-wrap"><table class="t8-table"><thead><tr><th>Party</th><th>Type</th><th>Contact</th><th>Contracts</th></tr></thead><tbody>
<?php if (!$parties): ?><tr><td colspan="4" class="t8-table-empty-row">No parties found.</td></tr><?php else: foreach ($parties as $party): ?>
<tr><td><strong><?= e((string) $party['name']) ?></strong><?php if (!empty($party['trade_name'])): ?><br><span class="t8-table-subtext"><?= e((string) $party['trade_name']) ?></span><?php endif; ?></td><td><?= e(ucfirst((string) $party['type'])) ?></td><td><?= e((string) ($party['primary_contact'] ?? $party['contact_email'] ?? '—')) ?></td><td><?= (int) $party['contract_count'] ?></td></tr>
<?php endforeach; endif; ?>
</tbody></table></div>
<?php if ($canCreate): ?>
    <?php include __DIR__ . '/_party_modal.php'; ?>
    <script>window.T8_PARTY_MODAL_OPEN_TRIGGERS = ['t8OpenPartyModalTop'];</script>
<?php endif; ?>
