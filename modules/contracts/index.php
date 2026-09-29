<?php
/** Contract Management v3: registry, draft review, workflow, and party links. */
declare(strict_types=1);

$retentionHelperPath = __DIR__ . '/../../app/includes/retention_helpers.php';
if (is_file($retentionHelperPath)) {
    require_once $retentionHelperPath;
}

t8_require_role(['admin', 'legal_officer', 'facilities_staff', 'employee']);

$pageTitle = 'Contract Management';
$pdo = $pdo ?? null;
$currentUserId = t8_current_user_id();
$isAdmin = t8_has_role('admin');
$isLegal = t8_has_role('legal_officer');
$canManage = $isAdmin || $isLegal;
$action = (string) ($_GET['action'] ?? 'list');
$errors = [];

const T8_CONTRACT_TYPES = ['Service Agreement', 'Supplier/Vendor Agreement', 'Employment Contract', 'Lease Agreement', 'Partnership Agreement', 'Non-Disclosure Agreement', 'Maintenance Agreement', 'Purchase Agreement', 'Other'];
const T8_CONTRACT_CURRENCIES = ['PHP', 'USD', 'JPY', 'KRW', 'EUR'];
const T8_CONTRACT_PAYMENT_FREQUENCIES = ['One-time', 'Monthly', 'Quarterly', 'Semi-annually', 'Annually', 'Per milestone', 'Other'];
const T8_CONTRACT_MONITORING_DAYS = 90;
const T8_CONTRACT_MAX_UPLOAD_MB = 10;

function t8_contract_next_number(PDO $pdo): string
{
    $nextId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM team8_contracts')->fetchColumn();
    return 'CON-' . date('Y') . '-' . str_pad((string) $nextId, 5, '0', STR_PAD_LEFT);
}

function t8_contract_fetch(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT c.*, u.full_name AS owner_name, d.name AS department_name, r.title AS renewed_from_title
         FROM team8_contracts c JOIN users u ON u.id = c.owner_id
         LEFT JOIN departments d ON d.id = c.department_id
         LEFT JOIN team8_contracts r ON r.id = c.renewed_from_id WHERE c.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function t8_contract_save_history(PDO $pdo, int $id, array $data, int $userId): void
{
    try {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(version_no), 0) + 1 FROM team8_contract_history WHERE contract_id = :id');
        $stmt->execute(['id' => $id]);
        $pdo->prepare('INSERT INTO team8_contract_history (contract_id, version_no, data_json, changed_by) VALUES (:id, :version, :data, :user)')
            ->execute(['id' => $id, 'version' => (int) $stmt->fetchColumn(), 'data' => json_encode($data, JSON_THROW_ON_ERROR), 'user' => $userId]);
    } catch (PDOException $e) {
        // History is optional for installations that have not applied its migration.
    }
}

function t8_contract_status_badge(string $status): string
{
    return match ($status) {
        'active' => 't8-badge-approved',
        'expiration', 'termination' => 't8-badge-rejected',
        'archived' => 't8-badge-archived',
        default => 't8-badge-pending',
    };
}

function t8_contract_is_monitoring(array $contract, int $withinDays = T8_CONTRACT_MONITORING_DAYS): bool
{
    if (($contract['status'] ?? '') !== 'active') {
        return false;
    }
    $horizon = strtotime('+' . $withinDays . ' days');
    foreach (['end_date', 'renewal_date'] as $field) {
        $date = $contract[$field] ?? null;
        $timestamp = $date ? strtotime((string) $date) : false;
        if ($timestamp !== false && $timestamp <= $horizon) {
            return true;
        }
    }
    return false;
}

function t8_contract_store_attachment(array $file): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > T8_CONTRACT_MAX_UPLOAD_MB * 1024 * 1024) {
        throw new RuntimeException('The attachment could not be uploaded or exceeds 10 MB.');
    }
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $imageExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
    $documentExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];
    if (!in_array($extension, array_merge($imageExtensions, $documentExtensions), true)) {
        throw new RuntimeException('Unsupported attachment type.');
    }
    if (function_exists('t8_validate_uploaded_file_mime') && !t8_validate_uploaded_file_mime((string) $file['tmp_name'], (string) $file['name'])) {
        throw new RuntimeException('Attachment contents do not match its file type.');
    }
    $directory = rtrim(UPLOAD_DIR, '/\\') . '/contract_attachments';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Attachment storage directory could not be created.');
    }
    $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', pathinfo((string) $file['name'], PATHINFO_FILENAME)), '-')) ?: 'attachment';
    $filename = $slug . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    if (!move_uploaded_file((string) $file['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('Attachment could not be stored.');
    }
    return ['path' => 'contract_attachments/' . $filename, 'type' => in_array($extension, $imageExtensions, true) ? 'image' : 'document', 'name' => (string) $file['name']];
}

function t8_contract_register_retention(PDO $pdo, array $contract, int $actorId): void
{
    if (!function_exists('t8_retention_register') || !function_exists('t8_retention_fetch_for_entity')) {
        return;
    }
    if (t8_retention_fetch_for_entity($pdo, 'contract', (int) $contract['id']) !== null) {
        return;
    }
    $start = $contract['termination_date'] ?: ($contract['end_date'] ?: date('Y-m-d'));
    t8_retention_register($pdo, 'contract', (int) $contract['id'], 'Civil Code Art. 1144 - written contract (10-year prescriptive period)', 10, (string) $start, $actorId);
    t8_audit_log($pdo, $actorId, 'contract', (int) $contract['id'], 'retention_registered');
}

function t8_contract_status_label(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

function t8_contract_render_rows(array $contracts, bool $isAdmin, bool $isLegal, bool $archived): void
{
    if ($contracts === []) {
        echo '<tr><td colspan="6" class="t8-table-empty-row">No contracts found.</td></tr>';
        return;
    }
    foreach ($contracts as $contract) {
        $id = (int) $contract['id'];
        $status = (string) $contract['status'];
        $monitoring = t8_contract_is_monitoring($contract);
        $rejected = $status === 'archived' && !empty($contract['rejection_reason']);
        ?>
        <tr>
            <td><strong><?= e((string) $contract['contract_number']) ?></strong><br><span class="t8-table-subtext"><?= e((string) ($contract['contract_type'] ?? '—')) ?></span></td>
            <td><a href="<?= e(page_url('contracts', ['action' => 'view', 'id' => $id])) ?>"><?= e((string) $contract['title']) ?></a></td>
            <td><?= e(format_date((string) $contract['start_date'], 'M d, Y')) ?><?= $contract['end_date'] ? ' — ' . e(format_date((string) $contract['end_date'], 'M d, Y')) : '' ?></td>
            <td><?= e((string) $contract['owner_name']) ?></td>
            <td><span class="t8-badge <?= e(t8_contract_status_badge($status)) ?>"><?= e(t8_contract_status_label($status)) ?></span>
                <?php if ($monitoring): ?><span class="t8-badge-monitoring">Expiring Soon</span><?php endif; ?>
                <?php if ($rejected): ?><span class="t8-badge t8-badge-rejected">Rejected</span><?php endif; ?>
            </td>
            <td class="t8-table-actions">
                <div class="t8-meatball-wrap">
                    <button type="button" class="t8-btn t8-btn-outline t8-btn-sm t8-meatball-btn" aria-label="Contract actions" aria-haspopup="true" aria-expanded="false">
                        <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                    </button>
                    <div class="t8-meatball-menu" role="menu" hidden>
                        <ul>
                            <li><a class="t8-row-menu-item" role="menuitem" href="<?= e(page_url('contracts', ['action' => 'view', 'id' => $id])) ?>"><i class="fa-solid fa-eye" aria-hidden="true"></i>View</a></li>
                            <?php if ($isAdmin && !$archived && $status === 'draft'): ?><li><a class="t8-row-menu-item" role="menuitem" href="<?= e(page_url('contracts', ['action' => 'edit', 'id' => $id])) ?>"><i class="fa-solid fa-pen" aria-hidden="true"></i>Edit</a></li><?php endif; ?>
                            <?php if ($isAdmin && $archived): ?>
                                <li><form method="post" action="<?= e(page_url('contracts', ['action' => 'restore'])) ?>"><?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="t8-row-menu-item t8-success" role="menuitem" type="submit"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Restore</button></form></li>
                            <?php elseif ($isAdmin && !$rejected): ?>
                                <li><form method="post" action="<?= e(page_url('contracts', ['action' => 'archive'])) ?>" onsubmit="return confirm('Archive this contract?')"><?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="t8-row-menu-item t8-danger" role="menuitem" type="submit"><i class="fa-solid fa-box-archive" aria-hidden="true"></i>Archive</button></form></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </td>
        </tr>
        <?php
    }
}

$expiryRows = $pdo->query(
    "SELECT * FROM team8_contracts
     WHERE deleted_at IS NULL AND status = 'active' AND end_date IS NOT NULL AND end_date < CURDATE()"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($expiryRows as $expiredContract) {
    $expire = $pdo->prepare("UPDATE team8_contracts SET status = 'expiration' WHERE id = :id AND status = 'active'");
    $expire->execute(['id' => (int) $expiredContract['id']]);
    if ($expire->rowCount() === 0) {
        continue;
    }
    $contractId = (int) $expiredContract['id'];
    t8_audit_log($pdo, $currentUserId, 'contract', $contractId, 'auto_expire', 'active', 'expiration');
    t8_notify_user($pdo, (int) $expiredContract['owner_id'], 'A contract assigned to you has reached its end date.', page_url('contracts', ['action' => 'view', 'id' => $contractId]));
    t8_contract_register_retention($pdo, $expiredContract, $currentUserId);
}

if (isset($_GET['ajax_filter'])) {
    header('Content-Type: application/json; charset=utf-8');
    $archived = ($_GET['archived'] ?? '0') === '1';
    $rejected = ($_GET['rejected'] ?? '0') === '1';
    $conditions = $rejected
        ? "c.deleted_at IS NULL AND c.status = 'archived' AND c.rejection_reason IS NOT NULL"
        : ($archived ? "(c.deleted_at IS NOT NULL OR (c.status = 'archived' AND c.rejection_reason IS NULL))" : "c.deleted_at IS NULL AND c.status <> 'archived'");
    $params = [];
    $search = trim((string) ($_GET['search'] ?? ''));
    if ($search !== '') {
        $conditions .= ' AND (c.contract_number LIKE :s1 OR c.title LIKE :s2 OR EXISTS (SELECT 1 FROM team8_contract_parties cp JOIN team8_parties p ON p.id = cp.party_id WHERE cp.contract_id = c.id AND p.name LIKE :s3))';
        $term = '%' . $search . '%';
        $params += ['s1' => $term, 's2' => $term, 's3' => $term];
    }
    $statusFilter = (string) ($_GET['status'] ?? '');
    if (in_array($statusFilter, T8_CONTRACT_STATUSES, true)) {
        $conditions .= ' AND c.status = :status';
        $params['status'] = $statusFilter;
    }
    $typeFilter = trim((string) ($_GET['contract_type'] ?? ''));
    if ($typeFilter !== '') {
        $conditions .= ' AND c.contract_type = :type';
        $params['type'] = $typeFilter;
    }
    if (!$isAdmin && !$isLegal) {
        $conditions .= ' AND c.owner_id = :owner';
        $params['owner'] = $currentUserId;
    }
    $stmt = $pdo->prepare("SELECT c.*, u.full_name AS owner_name, d.name AS department_name FROM team8_contracts c JOIN users u ON u.id = c.owner_id LEFT JOIN departments d ON d.id = c.department_id WHERE {$conditions} ORDER BY c.created_at DESC");
    $stmt->execute($params);
    ob_start();
    t8_contract_render_rows($stmt->fetchAll(PDO::FETCH_ASSOC), $isAdmin, $isLegal, $archived);
    echo json_encode(['html' => (string) ob_get_clean()]);
    exit;
}

$departments = $pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$owners = $pdo->query('SELECT id, full_name FROM users ORDER BY full_name')->fetchAll(PDO::FETCH_ASSOC);
$currentUserName = '';
foreach ($owners as $owner) {
    if ((int) $owner['id'] === $currentUserId) {
        $currentUserName = (string) $owner['full_name'];
        break;
    }
}

if ($action === 'workflow') {
    if (!$canManage || $_SERVER['REQUEST_METHOD'] !== 'POST' || !t8_csrf_verify($_POST['csrf_token'] ?? null)) {
        t8_flash_set('danger', 'Your session expired or you are not authorized.');
        redirect(page_url('contracts'));
    }
    $id = (int) ($_POST['id'] ?? 0);
    $contract = t8_contract_fetch($pdo, $id);
    $workflow = (string) ($_POST['workflow_action'] ?? '');
    $comment = trim((string) ($_POST['comment'] ?? ''));
    $transitions = [
        'submit' => ['draft', 'approval'], 'sign' => ['approval', 'signing'],
        'activate' => ['signing', 'active'], 'renewal' => ['active', 'renewal'],
        'terminate' => ['active', 'termination'], 'expire' => ['active', 'expiration'],
        'reject' => ['approval', 'archived'],
    ];
    if (!$contract || !isset($transitions[$workflow]) || $contract['status'] !== $transitions[$workflow][0]
        || (in_array($workflow, ['sign', 'activate'], true) && !$isAdmin)) {
        t8_flash_set('danger', 'That contract transition is not allowed.');
        redirect(page_url('contracts'));
    }
    $newStatus = $transitions[$workflow][1];
    if ($workflow === 'reject') {
        $reason = $comment !== '' ? $comment : 'Rejected by ' . $currentUserName;
        $pdo->prepare('UPDATE team8_contracts SET status = :status, rejection_reason = :reason WHERE id = :id')->execute(['status' => $newStatus, 'reason' => $reason, 'id' => $id]);
    } else {
        $pdo->prepare('UPDATE team8_contracts SET status = :status WHERE id = :id')->execute(['status' => $newStatus, 'id' => $id]);
    }
    try {
        $pdo->prepare('INSERT INTO team8_contract_approvals (contract_id, reviewer_id, approver_id, action, comment) VALUES (:cid, :reviewer, :approver, :action, :comment)')
            ->execute(['cid' => $id, 'reviewer' => $workflow === 'submit' ? $currentUserId : null, 'approver' => in_array($workflow, ['sign', 'activate', 'reject'], true) ? $currentUserId : null, 'action' => $workflow, 'comment' => $comment ?: null]);
    } catch (PDOException $e) {
        // Approval history is optional until its migration is applied.
    }
    t8_audit_log($pdo, $currentUserId, 'contract', $id, $workflow, (string) $contract['status'], $comment);
    if (in_array($newStatus, ['expiration', 'termination', 'archived'], true)) {
        $updated = t8_contract_fetch($pdo, $id);
        if ($updated) t8_contract_register_retention($pdo, $updated, $currentUserId);
    }
    t8_flash_set('success', 'Contract status updated to ' . t8_contract_status_label($newStatus) . '.');
    redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
}

if (in_array($action, ['create', 'edit'], true)) {
    if (!$canManage) {
        t8_require_role(['admin', 'legal_officer']);
    }
    $id = $action === 'edit' ? (int) ($_GET['id'] ?? 0) : 0;
    $existing = $id ? t8_contract_fetch($pdo, $id) : null;
    if ($action === 'edit' && (!$existing || $existing['status'] !== 'draft')) {
        t8_flash_set('danger', 'Only draft contracts can be edited.');
        redirect(page_url('contracts'));
    }
    $values = $existing ?: ['contract_number' => t8_contract_next_number($pdo), 'title' => '', 'contract_type' => '', 'description' => '', 'department_id' => '', 'start_date' => date('Y-m-d'), 'end_date' => '', 'renewal_date' => '', 'amount' => '', 'currency' => 'PHP', 'payment_terms' => '', 'payment_frequency' => '', 'deposit_amount' => '', 'financial_notes' => '', 'notice_period_days' => '', 'renewed_from_id' => ''];
    $allParties = $pdo->query('SELECT id, name, type FROM team8_parties ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    $selectedPartyIds = [];
    if ($existing) {
        $st = $pdo->prepare('SELECT party_id FROM team8_contract_parties WHERE contract_id = :id');
        $st->execute(['id' => $id]);
        $selectedPartyIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach (['title', 'contract_type', 'description', 'department_id', 'start_date', 'end_date', 'renewal_date', 'amount', 'currency', 'payment_terms', 'payment_frequency', 'deposit_amount', 'financial_notes', 'notice_period_days', 'renewed_from_id'] as $key) {
            $values[$key] = trim((string) ($_POST[$key] ?? ($key === 'currency' ? 'PHP' : '')));
        }
        $selectedPartyIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['party_ids'] ?? [])))));
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) $errors[] = 'Your session expired.';
        if ($values['title'] === '') $errors[] = 'Contract title is required.';
        if ($selectedPartyIds === []) $errors[] = 'At least one party is required.';
        $validDate = static function (string $value): bool {
            if ($value === '') return true;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
            [$year, $month, $day] = array_map('intval', explode('-', $value));
            return checkdate($month, $day, $year);
        };
        $today = date('Y-m-d');
        if (!$validDate($values['start_date']) || $values['start_date'] === '') {
            $errors[] = 'A valid start date is required.';
        } elseif ($values['start_date'] < $today) {
            $errors[] = 'Start date cannot be in the past.';
        }
        if (!$validDate($values['end_date']) || !$validDate($values['renewal_date'])) $errors[] = 'Enter valid end and renewal dates.';
        if ($values['end_date'] !== '' && $validDate($values['end_date']) && $validDate($values['start_date']) && $values['end_date'] < $values['start_date']) $errors[] = 'End date must be on or after the start date.';
        if ($values['renewal_date'] !== '' && $validDate($values['renewal_date'])) {
            if ($validDate($values['start_date']) && $values['renewal_date'] < $values['start_date']) $errors[] = 'Renewal date cannot be before the start date.';
            if ($values['end_date'] !== '' && $validDate($values['end_date']) && $values['renewal_date'] > $values['end_date']) $errors[] = 'Renewal date cannot be after the end date.';
        }
        if (!in_array(strtoupper($values['currency']), T8_CONTRACT_CURRENCIES, true)) $errors[] = 'Invalid currency.';
        if ($values['amount'] !== '' && (!is_numeric($values['amount']) || (float) $values['amount'] < 0)) $errors[] = 'Contract value must be a non-negative number.';
        if ($values['deposit_amount'] !== '' && (!is_numeric($values['deposit_amount']) || (float) $values['deposit_amount'] < 0)) $errors[] = 'Deposit must be a non-negative number.';
        if (!$errors) {
            $data = [
                'contract_number' => (string) ($existing['contract_number'] ?? $values['contract_number']), 'owner_id' => (int) ($existing['owner_id'] ?? $currentUserId),
                'department_id' => $values['department_id'] !== '' ? (int) $values['department_id'] : null,
                'renewed_from_id' => $values['renewed_from_id'] !== '' ? (int) $values['renewed_from_id'] : null,
                'title' => $values['title'], 'contract_type' => $values['contract_type'] ?: null, 'description' => $values['description'] ?: null,
                'start_date' => $values['start_date'], 'end_date' => $values['end_date'] ?: null, 'renewal_date' => $values['renewal_date'] ?: null,
                'amount' => $values['amount'] !== '' ? $values['amount'] : null, 'currency' => strtoupper($values['currency']),
                'payment_terms' => $values['payment_terms'] ?: null, 'payment_frequency' => $values['payment_frequency'] ?: null,
                'deposit_amount' => $values['deposit_amount'] !== '' ? $values['deposit_amount'] : null,
                'financial_notes' => $values['financial_notes'] ?: null, 'notice_period_days' => $values['notice_period_days'] !== '' ? (int) $values['notice_period_days'] : null,
            ];
            try {
                $attachment = t8_contract_store_attachment($_FILES['attachment'] ?? []);
                if ($attachment) {
                    $data['attachment_path'] = $attachment['path'];
                    $data['attachment_type'] = $attachment['type'];
                    $data['attachment_name'] = $attachment['name'];
                }
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
            if (!$errors) {
                if ($existing) {
                    $set = implode(', ', array_map(static fn($key) => $key . ' = :' . $key, array_keys($data)));
                    $data['id'] = $id;
                    $pdo->prepare("UPDATE team8_contracts SET {$set} WHERE id = :id AND status = 'draft'")->execute($data);
                } else {
                    $columns = array_keys($data);
                    $pdo->prepare('INSERT INTO team8_contracts (' . implode(',', $columns) . ') VALUES (:' . implode(',:', $columns) . ')')->execute($data);
                    $id = (int) $pdo->lastInsertId();
                }
                $pdo->prepare('DELETE FROM team8_contract_parties WHERE contract_id = :id')->execute(['id' => $id]);
                foreach ($selectedPartyIds as $partyId) {
                    $pdo->prepare("INSERT IGNORE INTO team8_contract_parties (contract_id, party_id, role_in_contract) VALUES (:contract, :party, 'Counterparty')")->execute(['contract' => $id, 'party' => $partyId]);
                }
                t8_contract_save_history($pdo, $id, $data, $currentUserId);
                t8_audit_log($pdo, $currentUserId, 'contract', $id, $existing ? 'update' : 'create');
                t8_flash_set('success', $existing ? 'Draft updated.' : 'Contract draft saved. Review it before submitting.');
                redirect(page_url('contracts', ['action' => 'review', 'id' => $id]));
            }
        }
    }
    ?>
    <ol class="t8-lifecycle" aria-label="Contract life cycle">
        <?php foreach (['Draft', 'Approval', 'Signing', 'Active', 'Renewal / Expiration / Termination'] as $stepIndex => $stepLabel): ?>
            <li class="t8-lifecycle-step<?= $stepIndex === 0 ? ' is-active' : '' ?>">
                <span class="t8-lifecycle-indicator" aria-hidden="true"></span>
                <span><?= e($stepLabel) ?></span>
            </li>
        <?php endforeach; ?>
    </ol>
    <h1><?= $action === 'edit' ? 'Edit Draft' : 'Create New Contract' ?></h1>
    <p class="t8-help-text">Record the terms and parties, then review before submitting for approval.</p>
    <?php foreach ($errors as $error): ?><div class="t8-alert t8-alert-danger"><?= e($error) ?></div><?php endforeach; ?>
    <form method="post" enctype="multipart/form-data" action="<?= e(page_url('contracts', array_filter(['action' => $action, 'id' => $id ?: null]))) ?>" class="t8-contract-form" id="t8ContractForm">
        <?= t8_csrf_field() ?>
        <div class="t8-card"><h2 class="t8-card-title">Contract Information</h2><div class="t8-form-grid">
            <div class="t8-field"><label class="t8-label">Contract Number</label><input class="t8-input" value="<?= e((string) $values['contract_number']) ?>" readonly></div>
            <div class="t8-field"><label class="t8-label" for="contract_type">Contract Type *</label><select class="t8-select" id="contract_type" name="contract_type" required><option value="">Select type</option><?php foreach (T8_CONTRACT_TYPES as $type): ?><option value="<?= e($type) ?>" <?= ($values['contract_type'] ?? '') === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></div>
            <div class="t8-field t8-form-span-2"><label class="t8-label" for="title">Contract Title *</label><input class="t8-input" id="title" name="title" required value="<?= e((string) $values['title']) ?>"></div>
            <div class="t8-field t8-form-span-2"><label class="t8-label" for="description">Description / Purpose</label><textarea class="t8-input" id="description" name="description" rows="3"><?= e((string) ($values['description'] ?? '')) ?></textarea></div>
        </div></div>
        <div class="t8-card">
            <h2 class="t8-card-title">Parties *</h2>
            <p class="t8-help-text">At least one party is required. Search to browse existing records, or create a new one.</p>
            <div class="t8-party-picker" id="t8PartyPicker">
                <div class="t8-party-selected" id="t8PartySelected">
                    <?php foreach ($selectedPartyIds as $partyId): foreach ($allParties as $party): if ((int) $party['id'] !== $partyId) continue; ?>
                        <div class="t8-party-chip" data-party-id="<?= (int) $party['id'] ?>">
                            <span><?= e((string) $party['name']) ?></span>
                            <button type="button" class="t8-party-remove" aria-label="Remove">&times;</button>
                            <input type="hidden" name="party_ids[]" value="<?= (int) $party['id'] ?>">
                        </div>
                    <?php endforeach; endforeach; ?>
                </div>
                <div class="t8-party-picker-row">
                    <input class="t8-input" type="search" id="t8PartySearch" placeholder="Search or browse parties…" autocomplete="off">
                </div>
                <div class="t8-party-results" id="t8PartyResults" hidden></div>
                <p class="t8-party-create-hint">
                    Can't find them?
                    <button type="button" class="t8-link-btn" id="t8OpenPartyModal">
                        <i class="fa-solid fa-plus"></i> Create a new party
                    </button>
                </p>
            </div>
        </div>
        <div class="t8-card"><h2 class="t8-card-title">Responsibility &amp; Schedule</h2><div class="t8-form-grid">
            <div class="t8-field"><label class="t8-label">Responsible Officer</label><input class="t8-input" value="<?= e($existing ? (string) $existing['owner_name'] : $currentUserName) ?>" readonly></div>
            <div class="t8-field"><label class="t8-label" for="department_id">Department</label><select class="t8-select" id="department_id" name="department_id"><option value="">Not assigned</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>" <?= (string) $department['id'] === (string) ($values['department_id'] ?? '') ? 'selected' : '' ?>><?= e((string) $department['name']) ?></option><?php endforeach; ?></select></div>
            <?php foreach (['start_date' => 'Start Date *', 'end_date' => 'End Date', 'renewal_date' => 'Renewal Date'] as $field => $label): ?><div class="t8-field"><label class="t8-label" for="<?= e($field) ?>"><?= e($label) ?></label><input class="t8-input" type="date" id="<?= e($field) ?>" name="<?= e($field) ?>" value="<?= e((string) ($values[$field] ?? '')) ?>" <?= $field === 'start_date' ? 'min="' . e(date('Y-m-d')) . '" required' : '' ?>></div><?php endforeach; ?>
            <div class="t8-field"><label class="t8-label" for="renewed_from_id">Renewed From</label><select class="t8-select" id="renewed_from_id" name="renewed_from_id"><option value="">Not a renewal</option><?php foreach ($pdo->query('SELECT id, title FROM team8_contracts WHERE id <> ' . (int) $id . ' ORDER BY title')->fetchAll(PDO::FETCH_ASSOC) as $renewable): ?><option value="<?= (int) $renewable['id'] ?>" <?= (string) $renewable['id'] === (string) ($values['renewed_from_id'] ?? '') ? 'selected' : '' ?>><?= e((string) $renewable['title']) ?></option><?php endforeach; ?></select></div>
        </div></div>
        <div class="t8-card"><h2 class="t8-card-title">Financial Terms</h2><div class="t8-form-grid">
            <div class="t8-field"><label class="t8-label" for="currency">Currency</label><select class="t8-select" id="currency" name="currency"><?php foreach (T8_CONTRACT_CURRENCIES as $currency): ?><option <?= ($values['currency'] ?? 'PHP') === $currency ? 'selected' : '' ?>><?= e($currency) ?></option><?php endforeach; ?></select></div>
            <div class="t8-field"><label class="t8-label" for="amount">Contract Value</label><input class="t8-input" type="number" min="0" step="0.01" id="amount" name="amount" value="<?= e((string) ($values['amount'] ?? '')) ?>"></div>
            <div class="t8-field"><label class="t8-label" for="payment_frequency">Payment Frequency</label><select class="t8-select" id="payment_frequency" name="payment_frequency"><option value="">Select frequency</option><?php foreach (T8_CONTRACT_PAYMENT_FREQUENCIES as $frequency): ?><option <?= ($values['payment_frequency'] ?? '') === $frequency ? 'selected' : '' ?>><?= e($frequency) ?></option><?php endforeach; ?></select></div>
            <div class="t8-field"><label class="t8-label" for="deposit_amount">Deposit / Advance</label><input class="t8-input" type="number" min="0" step="0.01" id="deposit_amount" name="deposit_amount" value="<?= e((string) ($values['deposit_amount'] ?? '')) ?>"></div>
            <div class="t8-field"><label class="t8-label" for="notice_period_days">Notice Period (days)</label><input class="t8-input" type="number" min="0" id="notice_period_days" name="notice_period_days" value="<?= e((string) ($values['notice_period_days'] ?? '')) ?>"></div>
            <div class="t8-field"><label class="t8-label" for="payment_terms">Payment Terms</label><input class="t8-input" id="payment_terms" name="payment_terms" value="<?= e((string) ($values['payment_terms'] ?? '')) ?>"></div>
        </div></div>
        <div class="t8-card"><h2 class="t8-card-title">Additional Notes &amp; Attachment</h2><div class="t8-field"><label class="t8-label" for="financial_notes">Notes / Special Conditions</label><textarea class="t8-input" id="financial_notes" name="financial_notes" rows="3"><?= e((string) ($values['financial_notes'] ?? '')) ?></textarea></div><div class="t8-field"><label class="t8-label" for="attachment">Attachment (image or document, max 10 MB)</label><input class="t8-input" type="file" id="attachment" name="attachment" accept=".png,.jpg,.jpeg,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt"><span class="t8-help-text">An existing attachment is retained when no new file is selected.</span></div></div>
        <div class="t8-form-actions"><button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-arrow-right"></i> Continue to Review</button><a class="t8-btn t8-btn-outline" href="<?= e(page_url('contracts')) ?>">Cancel</a></div>
    </form>
    <?php include __DIR__ . '/_party_modal.php'; ?>
    <?php return; ?>
    <?php
}

if ($action === 'submit') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !t8_csrf_verify($_POST['csrf_token'] ?? null)) {
        t8_flash_set('danger', 'Your session expired.'); redirect(page_url('contracts'));
    }
    $id = (int) ($_POST['id'] ?? 0);
    $contract = t8_contract_fetch($pdo, $id);
    if (!$canManage || !$contract || $contract['status'] !== 'draft') {
        t8_flash_set('danger', 'Only a draft can be submitted for approval.'); redirect(page_url('contracts'));
    }
    $pdo->prepare("UPDATE team8_contracts SET status = 'approval' WHERE id = :id")->execute(['id' => $id]);
    t8_audit_log($pdo, $currentUserId, 'contract', $id, 'submit', 'draft', 'approval');
    t8_flash_set('success', 'Contract submitted for approval.');
    redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
}

if (in_array($action, ['archive', 'restore'], true)) {
    if (!$isAdmin || $_SERVER['REQUEST_METHOD'] !== 'POST' || !t8_csrf_verify($_POST['csrf_token'] ?? null)) {
        t8_flash_set('danger', 'Your session expired or you are not authorized.'); redirect(page_url('contracts'));
    }
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'archive') {
        $pdo->prepare("UPDATE team8_contracts SET status = 'archived' WHERE id = :id")->execute(['id' => $id]);
    } else {
        $pdo->prepare("UPDATE team8_contracts SET status = 'draft', rejection_reason = NULL, deleted_at = NULL WHERE id = :id")->execute(['id' => $id]);
    }
    t8_audit_log($pdo, $currentUserId, 'contract', $id, $action);
    t8_flash_set('success', $action === 'archive' ? 'Contract archived.' : 'Contract restored to draft.');
    redirect(page_url('contracts'));
}

if ($action === 'download_attachment') {
    $id = (int) ($_GET['id'] ?? 0);
    $contract = t8_contract_fetch($pdo, $id);
    if (!$contract || empty($contract['attachment_path']) || (!$canManage && (int) $contract['owner_id'] !== $currentUserId)) {
        t8_flash_set('danger', 'Attachment is unavailable.'); redirect(page_url('contracts'));
    }
    $path = rtrim(UPLOAD_DIR, '/\\') . '/' . ltrim((string) $contract['attachment_path'], '/\\');
    if (!is_file($path)) { t8_flash_set('danger', 'Attachment missing on server.'); redirect(page_url('contracts', ['action' => 'view', 'id' => $id])); }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . rawurlencode(basename((string) $contract['attachment_name'])) . '"');
    readfile($path);
    exit;
}

$archivedFilter = ($_GET['archived'] ?? '0') === '1';
$rejectedFilter = ($_GET['rejected'] ?? '0') === '1';
$viewId = (int) ($_GET['id'] ?? 0);
if (in_array($action, ['review', 'view'], true)) {
    $viewContract = $viewId ? t8_contract_fetch($pdo, $viewId) : null;
    if (!$viewContract || (!$canManage && (int) $viewContract['owner_id'] !== $currentUserId)) {
        t8_flash_set('danger', 'Contract not found.'); redirect(page_url('contracts'));
    }
    $partyStmt = $pdo->prepare('SELECT cp.*, p.name AS party_name, p.type AS party_type, p.primary_contact, p.contact_email FROM team8_contract_parties cp JOIN team8_parties p ON p.id = cp.party_id WHERE cp.contract_id = :id ORDER BY cp.created_at');
    $partyStmt->execute(['id' => $viewId]);
    $viewParties = $partyStmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($action === 'review' || $action === 'view') {
    $stages = ['draft', 'approval', 'signing', 'active', 'renewal'];
    $currentStageIndex = array_search((string) $viewContract['status'], $stages, true);
    if ($currentStageIndex === false) {
        $currentStageIndex = 0;
    }
    ?>
    <div class="t8-card" style="padding: 24px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('contracts')) ?>" style="margin-bottom: 16px;"><i class="fa-solid fa-arrow-left"></i> Back to Registry</a>

        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px;">
            <div>
                <h1 style="margin: 0 0 8px 0;"><?= e((string) $viewContract['title']) ?></h1>
                <p class="t8-help-text" style="margin: 0;"><?= e((string) $viewContract['contract_number']) ?> · <?= e((string) ($viewContract['contract_type'] ?? '—')) ?></p>
            </div>
        </div>

        <div class="t8-lifecycle" aria-label="Contract life cycle">
            <?php foreach ($stages as $index => $stage):
                $isComplete = $index < $currentStageIndex;
                $isActive = $index === $currentStageIndex;
                $class = $isComplete ? 'is-complete' : ($isActive ? 'is-active' : '');
            ?>
                <div class="t8-lifecycle-step <?= $class ?>">
                    <div class="t8-lifecycle-circle"><?= $isComplete ? '<i class="fa-solid fa-check"></i>' : (int) ($index + 1) ?></div>
                    <span><?= e(ucfirst($stage)) ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="t8-tabs" role="tablist" style="margin-top: 24px;">
            <button type="button" class="t8-tab is-active" data-tab="overview" role="tab" aria-selected="true">Overview</button>
            <button type="button" class="t8-tab" data-tab="parties" role="tab" aria-selected="false">Parties</button>
            <button type="button" class="t8-tab" data-tab="documents" role="tab" aria-selected="false">Documents</button>
            <button type="button" class="t8-tab" data-tab="history" role="tab" aria-selected="false">Audit / History</button>
        </div>

        <section class="t8-tab-panel" data-panel="overview" role="tabpanel">
            <div class="t8-detail-grid">
                <div class="t8-detail-item">
                    <span class="t8-detail-label">Status</span>
                    <span class="t8-detail-value">
                        <span class="t8-badge <?= e(t8_contract_status_badge((string) $viewContract['status'])) ?>"><?= e(t8_contract_status_label((string) $viewContract['status'])) ?></span>
                        <?= t8_contract_is_monitoring($viewContract) ? ' <span class="t8-badge-monitoring">Expiring Soon</span>' : '' ?>
                    </span>
                </div>
                <div class="t8-detail-item">
                    <span class="t8-detail-label">Officer</span>
                    <span class="t8-detail-value"><?= e((string) $viewContract['owner_name']) ?></span>
                </div>
                <div class="t8-detail-item">
                    <span class="t8-detail-label">Department</span>
                    <span class="t8-detail-value"><?= e((string) ($viewContract['department_name'] ?? '—')) ?></span>
                </div>
                <div class="t8-detail-item">
                    <span class="t8-detail-label">Period</span>
                    <span class="t8-detail-value"><?= e(format_date((string) $viewContract['start_date'], 'M d, Y')) ?><?= $viewContract['end_date'] ? ' — ' . e(format_date((string) $viewContract['end_date'], 'M d, Y')) : '' ?></span>
                </div>
                <div class="t8-detail-item">
                    <span class="t8-detail-label">Value</span>
                    <span class="t8-detail-value"><?= e($viewContract['amount'] !== null ? $viewContract['currency'] . ' ' . number_format((float) $viewContract['amount'], 2) : '—') ?></span>
                </div>
                <div class="t8-detail-item">
                    <span class="t8-detail-label">Payment Terms</span>
                    <span class="t8-detail-value"><?= e((string) ($viewContract['payment_terms'] ?? '—')) ?></span>
                </div>
                <div class="t8-detail-item t8-detail-span-2">
                    <span class="t8-detail-label">Description</span>
                    <span class="t8-detail-value"><?= nl2br(e((string) ($viewContract['description'] ?? '—'))) ?></span>
                </div>
                <div class="t8-detail-item t8-detail-span-2">
                    <span class="t8-detail-label">Notes</span>
                    <span class="t8-detail-value"><?= nl2br(e((string) ($viewContract['financial_notes'] ?? '—'))) ?></span>
                </div>
                <?php if (!empty($viewContract['attachment_path'])): ?>
                    <div class="t8-detail-item t8-detail-span-2">
                        <span class="t8-detail-label">Attachment</span>
                        <span class="t8-detail-value"><a href="<?= e(page_url('contracts', ['action' => 'download_attachment', 'id' => $viewId])) ?>"><?= e((string) $viewContract['attachment_name']) ?></a></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($viewContract['rejection_reason'])): ?>
                    <div class="t8-detail-item t8-detail-span-2">
                        <span class="t8-detail-label" style="color: #b22222;">Rejection Reason</span>
                        <span class="t8-detail-value" style="color: #b22222;"><?= e((string) $viewContract['rejection_reason']) ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($canManage): ?>
                <div class="t8-form-actions" style="margin-top: 24px; border-top: 1px solid #eee; padding-top: 16px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <?php if ($viewContract['status'] === 'draft'): ?>
                        <form method="post" action="<?= e(page_url('contracts', ['action' => 'submit'])) ?>" style="margin: 0;">
                            <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= $viewId ?>">
                            <button class="t8-btn t8-btn-accent">Submit for Approval</button>
                        </form>
                        <?php if ($action === 'review'): ?>
                            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('contracts', ['action' => 'edit', 'id' => $viewId])) ?>">Back to Edit</a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($viewContract['status'] === 'approval'): ?>
                        <?php if ($isAdmin): ?>
                            <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>" style="margin: 0;">
                                <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= $viewId ?>"><input type="hidden" name="workflow_action" value="sign">
                                <button class="t8-btn t8-btn-success">Approve to Signing</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>" style="margin: 0; display: flex; gap: 8px;">
                            <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= $viewId ?>"><input type="hidden" name="workflow_action" value="reject">
                            <input class="t8-input" name="comment" placeholder="Rejection reason">
                            <button class="t8-btn t8-btn-danger">Reject</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($isAdmin && $viewContract['status'] === 'signing'): ?>
                        <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>" style="margin: 0;">
                            <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= $viewId ?>"><input type="hidden" name="workflow_action" value="activate">
                            <button class="t8-btn t8-btn-success">Mark Active</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($isAdmin && $viewContract['status'] === 'active'): ?>
                        <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>" style="margin: 0;">
                            <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= $viewId ?>"><input type="hidden" name="workflow_action" value="renewal">
                            <button class="t8-btn t8-btn-outline">Mark for Renewal</button>
                        </form>
                        <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>" style="margin: 0;">
                            <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= $viewId ?>"><input type="hidden" name="workflow_action" value="terminate">
                            <button class="t8-btn t8-btn-danger">Terminate</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="t8-tab-panel" data-panel="parties" role="tabpanel" hidden>
            <?php if (!$viewParties): ?>
                <p class="t8-empty">No parties attached.</p>
            <?php else: ?>
                <table class="t8-table">
                    <thead><tr><th>Name</th><th>Type</th><th>Role</th><th>Contact</th></tr></thead>
                    <tbody>
                        <?php foreach ($viewParties as $party): ?>
                            <tr>
                                <td><?= e((string) $party['party_name']) ?></td>
                                <td><?= e(ucfirst((string) $party['party_type'])) ?></td>
                                <td><?= e((string) $party['role_in_contract']) ?></td>
                                <td><?= e((string) ($party['primary_contact'] ?? $party['contact_email'] ?? '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="t8-tab-panel" data-panel="documents" role="tabpanel" hidden>
            <p class="t8-empty">Contract attachment is available from Overview.</p>
        </section>

        <section class="t8-tab-panel" data-panel="history" role="tabpanel" hidden>
            <?php
            $history = [];
            try {
                $st = $pdo->prepare('SELECT h.*, u.full_name FROM team8_contract_history h JOIN users u ON u.id = h.changed_by WHERE h.contract_id = :id ORDER BY h.version_no DESC LIMIT 25');
                $st->execute(['id' => $viewId]);
                $history = $st->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
            }
            ?>
            <?php if (!$history): ?>
                <p class="t8-empty">No history recorded.</p>
            <?php else: ?>
                <table class="t8-table">
                    <thead><tr><th>Version</th><th>Changed By</th><th>When</th></tr></thead>
                    <tbody>
                        <?php foreach ($history as $entry): ?>
                            <tr>
                                <td>v<?= (int) $entry['version_no'] ?></td>
                                <td><?= e((string) $entry['full_name']) ?></td>
                                <td><?= e(format_date((string) $entry['created_at'], 'M d, Y g:i A')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>
    <?php return;
}

$where = $rejectedFilter ? "c.deleted_at IS NULL AND c.status = 'archived' AND c.rejection_reason IS NOT NULL" : ($archivedFilter ? "(c.deleted_at IS NOT NULL OR (c.status = 'archived' AND c.rejection_reason IS NULL))" : "c.deleted_at IS NULL AND c.status <> 'archived'");
$params = [];
$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
$typeFilter = (string) ($_GET['contract_type'] ?? '');
if ($search !== '') { $where .= ' AND (c.contract_number LIKE :s1 OR c.title LIKE :s2 OR EXISTS (SELECT 1 FROM team8_contract_parties cp JOIN team8_parties p ON p.id = cp.party_id WHERE cp.contract_id = c.id AND p.name LIKE :s3))'; $term = '%' . $search . '%'; $params += ['s1' => $term, 's2' => $term, 's3' => $term]; }
if (in_array($statusFilter, T8_CONTRACT_STATUSES, true)) { $where .= ' AND c.status = :status'; $params['status'] = $statusFilter; }
if ($typeFilter !== '') { $where .= ' AND c.contract_type = :type'; $params['type'] = $typeFilter; }
if (!$isAdmin && !$isLegal) { $where .= ' AND c.owner_id = :owner'; $params['owner'] = $currentUserId; }
$listStmt = $pdo->prepare("SELECT c.*, u.full_name AS owner_name, d.name AS department_name FROM team8_contracts c JOIN users u ON u.id = c.owner_id LEFT JOIN departments d ON d.id = c.department_id WHERE {$where} ORDER BY c.created_at DESC");
$listStmt->execute($params);
$contracts = $listStmt->fetchAll(PDO::FETCH_ASSOC);
$counts = array_fill_keys(['total', 'draft', 'approval', 'signing', 'active', 'expiring'], 0);
$countRows = $pdo->query("SELECT status, COUNT(*) AS n FROM team8_contracts WHERE deleted_at IS NULL AND status <> 'archived' GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
foreach ($countRows as $row) { $counts['total'] += (int) $row['n']; if (isset($counts[$row['status']])) $counts[$row['status']] = (int) $row['n']; }
$monitoringRows = $pdo->query("SELECT status, end_date, renewal_date FROM team8_contracts WHERE deleted_at IS NULL AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
$counts['expiring'] = count(array_filter($monitoringRows, 't8_contract_is_monitoring'));
?>
<div class="t8-card-header" style="display:flex;justify-content:space-between;align-items:center"><div><h1>Contract Management</h1><p class="t8-help-text">Manage contracts throughout their lifecycle.</p></div><?php if ($canManage): ?><div style="display:flex;gap:8px;align-items:center"><a class="t8-btn t8-btn-outline" href="<?= e(page_url('party_registry')) ?>">Parties</a><a class="t8-btn t8-btn-accent" href="<?= e(page_url('contracts', ['action' => 'create'])) ?>"><i class="fa-solid fa-plus"></i> New Contract</a></div><?php endif; ?></div>
<div class="t8-stat-row"><?php foreach (['total' => 'Total', 'draft' => 'Draft', 'approval' => 'For Approval', 'signing' => 'Signing', 'active' => 'Active', 'expiring' => 'Expiring Soon'] as $key => $label): ?><div class="t8-stat-card <?= $key === 'expiring' ? 't8-stat-warn' : '' ?>"><span><?= e($label) ?></span><strong><?= (int) $counts[$key] ?></strong></div><?php endforeach; ?></div>
<div class="t8-tabs"><a class="t8-tab <?= !$archivedFilter && !$rejectedFilter ? 'is-active' : '' ?>" href="<?= e(page_url('contracts')) ?>">Active</a><a class="t8-tab <?= $archivedFilter ? 'is-active' : '' ?>" href="<?= e(page_url('contracts', ['archived' => 1])) ?>">Archived</a><a class="t8-tab <?= $rejectedFilter ? 'is-active' : '' ?>" href="<?= e(page_url('contracts', ['rejected' => 1])) ?>">Rejected</a></div>
<form id="t8ContractsFilterForm" class="t8-filter-row" data-contract-filter-table="t8ContractsTable"><input type="hidden" name="archived" value="<?= $archivedFilter ? '1' : '0' ?>"><input type="hidden" name="rejected" value="<?= $rejectedFilter ? '1' : '0' ?>"><input type="search" class="t8-input" name="search" data-contract-search value="<?= e($search) ?>" placeholder="Search number, title, or party"><select class="t8-select" name="status" data-contract-status><option value="">All Status</option><?php foreach (T8_CONTRACT_STATUSES as $status): ?><option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(t8_contract_status_label($status)) ?></option><?php endforeach; ?></select><select class="t8-select" name="contract_type" data-contract-type><option value="">All Types</option><?php foreach (T8_CONTRACT_TYPES as $type): ?><option value="<?= e($type) ?>" <?= $typeFilter === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></form>
<div class="t8-table-wrap"><table class="t8-table" id="t8ContractsTable"><thead><tr><th>Contract</th><th>Party / Title</th><th>Period</th><th>Officer</th><th>Status</th><th></th></tr></thead><tbody data-contract-results><?php t8_contract_render_rows($contracts, $isAdmin, $isLegal, $archivedFilter); ?></tbody></table></div>
