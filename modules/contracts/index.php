<?php
/** Contract Management v3: registry, draft review, workflow, and party links. */
declare(strict_types=1);

require_once __DIR__ . '/../../app/config/constants.php';
require_once __DIR__ . '/../../app/includes/gmail_dispatch.php';
require_once __DIR__ . '/../../app/includes/contract_portal.php';
require_once __DIR__ . '/../../app/includes/contract_status.php';

$retentionHelperPath = __DIR__ . '/../../app/includes/retention_helpers.php';
if (is_file($retentionHelperPath)) {
    require_once $retentionHelperPath;
}

$contractRenewalPath = __DIR__ . '/../../app/includes/contract_renewal.php';
if (is_file($contractRenewalPath)) {
    require_once $contractRenewalPath;
}

$contractNegotiationPath = __DIR__ . '/../../app/includes/contract_negotiation.php';
if (is_file($contractNegotiationPath)) {
    require_once $contractNegotiationPath;
}

$contractSigningPath = __DIR__ . '/../../app/includes/contract_signing.php';
if (is_file($contractSigningPath)) {
    require_once $contractSigningPath;
    require_once __DIR__ . '/../../app/includes/contract_signing_portal.php';
}
$contractWorkflowPath = __DIR__ . '/../../app/includes/contract_workflow.php';
if (is_file($contractWorkflowPath)) {
    require_once $contractWorkflowPath;
}

$action = (string) ($_GET['action'] ?? 'list');
t8_require_role(['admin', 'legal_officer', 'facilities_staff', 'employee']);

$pageTitle = 'Contract Management';
$pdo = $pdo ?? null;
if ($pdo instanceof PDO) {
    try {
        $pdo->query('SELECT expiration_alert, days_remaining, last_monitor_check_at FROM team8_contracts LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE team8_contracts ADD COLUMN expiration_alert ENUM("normal","low","medium","high","critical") NOT NULL DEFAULT "normal"');
            $pdo->exec('ALTER TABLE team8_contracts ADD COLUMN days_remaining INT NULL DEFAULT NULL');
            $pdo->exec('ALTER TABLE team8_contracts ADD COLUMN last_monitor_check_at DATETIME NULL DEFAULT NULL');
        } catch (Throwable $ignored) {
            // Older database snapshots do not include the monitoring metadata columns.
        }
    }
}
$currentUserId = t8_current_user_id();
$isAdmin = t8_has_role('admin');
$isLegal = t8_has_role('legal_officer');
$canManage = $isAdmin || $isLegal;
$errors = [];

const T8_CONTRACT_TYPES = ['Service Agreement', 'Supplier/Vendor Agreement', 'Employment Contract', 'Lease Agreement', 'Partnership Agreement', 'Non-Disclosure Agreement', 'Maintenance Agreement', 'Purchase Agreement', 'Other'];
const T8_CONTRACT_CURRENCIES = ['PHP', 'USD', 'JPY', 'KRW', 'EUR'];
const T8_CONTRACT_PAYMENT_FREQUENCIES = ['One-time', 'Monthly', 'Quarterly', 'Semi-annually', 'Annually', 'Per milestone', 'Other'];
const T8_CONTRACT_MAX_UPLOAD_MB = 10;
const T8_CONTRACT_PAGE_SIZE = 10;

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

function t8_contract_event_log(PDO $pdo, int $contractId, int $actorId, string $eventType, string $description, array $metadata = []): void
{
    try {
        $pdo->prepare(
            'INSERT INTO team8_contract_events (contract_id, actor_id, event_type, description, metadata) VALUES (:contract_id, :actor_id, :event_type, :description, :metadata)'
        )->execute([
            'contract_id' => $contractId,
            'actor_id' => $actorId,
            'event_type' => $eventType,
            'description' => $description,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);
    } catch (Throwable $e) {
        // Contract event logging is optional during partial schema upgrades.
    }
}

function t8_contract_event_log_required(PDO $pdo, int $contractId, int $actorId, string $eventType, string $description, array $metadata = []): void
{
    $logged = $pdo->prepare(
        'INSERT INTO team8_contract_events (contract_id, actor_id, event_type, description, metadata)
         VALUES (:contract_id, :actor_id, :event_type, :description, :metadata)'
    )->execute([
        'contract_id' => $contractId,
        'actor_id' => $actorId,
        'event_type' => $eventType,
        'description' => $description,
        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
    ]);
    if (!$logged) {
        throw new RuntimeException('Required contract audit event could not be written.');
    }
}

function t8_contract_days_remaining(array $contract): int
{
    $candidateDates = [];
    foreach (['end_date', 'renewal_date'] as $field) {
        $date = isset($contract[$field]) ? trim((string) $contract[$field]) : '';
        if ($date === '') {
            continue;
        }
        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            $candidateDates[] = $timestamp;
        }
    }

    if ($candidateDates === []) {
        return 0;
    }

    $now = time();
    $daysRemaining = [];
    foreach ($candidateDates as $timestamp) {
        $daysRemaining[] = (int) floor(($timestamp - $now) / 86400);
    }

    if ($daysRemaining === []) {
        return 0;
    }

    $nearest = min($daysRemaining);
    return max(0, $nearest);
}

function t8_contract_monitoring_level(array $contract): string
{
    $days = t8_contract_days_remaining($contract);
    if ($days <= 7) {
        return 'critical';
    }
    if ($days <= 30) {
        return 'high';
    }
    if ($days <= 60) {
        return 'medium';
    }
    if ($days <= 90) {
        return 'low';
    }
    return 'normal';
}

function t8_contract_refresh_monitoring_state(PDO $pdo, int $contractId): ?array
{
    $contractId = (int) $contractId;
    if ($contractId <= 0) {
        return null;
    }

    $contract = t8_contract_fetch($pdo, $contractId);
    if ($contract === null) {
        return null;
    }

    $status = t8_contract_normalize_status((string) ($contract['status'] ?? ''));
    $daysRemaining = $status === 'active' ? t8_contract_days_remaining($contract) : 0;
    $alert = $status === 'active' ? t8_contract_monitoring_level($contract) : 'normal';

    $metadata = [
        'days_remaining' => $daysRemaining,
        'expiration_alert' => $alert,
    ];

    try {
        $fieldsToUpdate = ['days_remaining' => ':days_remaining', 'expiration_alert' => ':expiration_alert'];
        $setClause = implode(', ', array_map(static fn (string $column, string $placeholder): string => $column . ' = ' . $placeholder, array_keys($fieldsToUpdate), array_values($fieldsToUpdate)));
        $stmt = $pdo->prepare('UPDATE team8_contracts SET ' . $setClause . ' WHERE id = :id');
        $stmt->execute([
            'days_remaining' => $daysRemaining,
            'expiration_alert' => $alert,
            'id' => $contractId,
        ]);
    } catch (Throwable $e) {
        // Monitoring metadata is a best-effort persistence layer for active contracts.
    }

    return $metadata;
}

function t8_contract_send_monitoring_notification(PDO $pdo, int $contractId, int $actorId): void
{
    $contract = t8_contract_fetch($pdo, $contractId);
    if ($contract === null) {
        return;
    }

    $status = t8_contract_normalize_status((string) ($contract['status'] ?? ''));
    if ($status !== 'active') {
        return;
    }

    $daysRemaining = t8_contract_days_remaining($contract);
    $alert = t8_contract_monitoring_level($contract);
    if ($alert === 'normal') {
        return;
    }

    $message = 'Contract ' . (string) ($contract['contract_number'] ?? 'unknown') . ' has ' . $daysRemaining . ' days remaining and is now at ' . strtoupper($alert) . ' monitoring level.';
    $contractUrl = page_url('contracts', ['action' => 'view', 'id' => $contractId]);

    $recipients = t8_contract_party_notification_users($pdo, $contractId);
    $ownerId = (int) ($contract['owner_id'] ?? 0);
    if ($ownerId > 0) {
        $recipients[] = $ownerId;
    }
    foreach (array_values(array_unique(array_filter($recipients, static fn ($userId): bool => (int) $userId > 0))) as $userId) {
        if ((int) $userId === (int) $actorId) {
            continue;
        }
        if (function_exists('t8_notify_user')) {
            t8_notify_user($pdo, (int) $userId, $message, $contractUrl);
        }
    }
}

function t8_contract_party_notification_users(PDO $pdo, int $contractId): array
{
    $users = [];
    try {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT u.id
             FROM team8_contract_parties cp
             JOIN team8_parties p ON p.id = cp.party_id
             LEFT JOIN users u ON u.email = p.contact_email OR u.full_name = p.name
             WHERE cp.contract_id = :contract_id AND u.id IS NOT NULL'
        );
        $stmt->execute(['contract_id' => $contractId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            $users[] = (int) $userId;
        }
    } catch (Throwable $e) {
        // External-party notifications are optional when the contact model is not fully mapped.
    }

    try {
        $stmt = $pdo->prepare('SELECT owner_id FROM team8_contracts WHERE id = :contract_id LIMIT 1');
        $stmt->execute(['contract_id' => $contractId]);
        $ownerId = (int) $stmt->fetchColumn();
        if ($ownerId > 0) {
            $users[] = $ownerId;
        }
    } catch (Throwable $e) {
        // Owner lookup is optional.
    }

    return array_values(array_unique(array_filter($users, static fn ($userId) => (int) $userId > 0)));
}

function t8_contract_send_status_notification(PDO $pdo, int $contractId, int $actorId, string $eventType, string $message): void
{
    if (!function_exists('t8_notify_user')) {
        return;
    }

    $recipients = t8_contract_party_notification_users($pdo, $contractId);
    foreach ($recipients as $userId) {
        if ((int) $userId === (int) $actorId) {
            continue;
        }
        t8_notify_user($pdo, (int) $userId, $message, page_url('contracts', ['action' => 'view', 'id' => $contractId]));
    }

    try {
        $stmt = $pdo->prepare('SELECT owner_id FROM team8_contracts WHERE id = :contract_id LIMIT 1');
        $stmt->execute(['contract_id' => $contractId]);
        $ownerId = (int) $stmt->fetchColumn();
        if ($ownerId > 0 && $ownerId !== $actorId) {
            t8_notify_user($pdo, $ownerId, $message, page_url('contracts', ['action' => 'view', 'id' => $contractId]));
        }
    } catch (Throwable $e) {
        // Notification generation is optional for partial installations.
    }
}

function t8_contract_normalize_status(string $status): string
{
    $normalized = strtolower(trim($status));
    return match ($normalized) {
        'approval', 'approved' => 'pending_approval',
        'signing' => 'pending_signature',
        'signed' => 'fully_executed',
        'expired', 'expiration' => 'expiration',
        'pending_approval' => 'pending_approval',
        'pending_signature' => 'pending_signature',
        'partially_signed', 'partially-signed' => 'partially_signed',
        'fully_executed', 'fully-executed' => 'fully_executed',
        'under_review', 'under-review' => 'under_review',
        'negotiation' => 'negotiation',
        'draft', 'active', 'renewal', 'termination', 'cancelled', 'canceled', 'superseded', 'archived' => $normalized,
        'approved_status', 'approval_status' => 'pending_approval',
        default => $normalized,
    };
}

function t8_contract_status_badge(string $status): string
{
    $normalized = t8_contract_normalize_status($status);
    return match ($normalized) {
        'active', 'fully_executed' => 't8-badge-approved',
        'expiration', 'termination', 'cancelled', 'superseded' => 't8-badge-rejected',
        'archived' => 't8-badge-archived',
        'draft', 'under_review', 'negotiation', 'pending_approval', 'pending_signature', 'partially_signed', 'renewal' => 't8-badge-pending',
        default => 't8-badge-pending',
    };
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

function t8_contract_sanitize_money_value(mixed $value): string
{
    $normalized = trim((string) $value);
    if ($normalized === '') {
        return '';
    }

    $normalized = str_replace(',', '', $normalized);
    return $normalized;
}

function t8_contract_format_money(mixed $value, ?string $currency = null): string
{
    $normalized = t8_contract_sanitize_money_value($value);
    if ($normalized === '' || !is_numeric($normalized)) {
        return '—';
    }

    $amount = (float) $normalized;
    $formatted = number_format($amount, 2, '.', ',');
    $currencyCode = strtoupper(trim((string) ($currency ?? '')));
    return $currencyCode !== '' ? $currencyCode . ' ' . $formatted : $formatted;
}

function t8_contract_render_pagination(int $currentPage, int $totalPages, array $filters): void
{
    if ($totalPages <= 1) {
        return;
    }
    ?>
    <nav id="t8ContractsPagination" class="t8-pagination" aria-label="Contract pages">
        <?php if ($currentPage > 1): ?><a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('contracts', array_merge($filters, ['contract_page' => $currentPage - 1]))) ?>" data-contract-page="<?= $currentPage - 1 ?>">Previous</a><?php endif; ?>
        <span class="t8-help-text">Page <?= $currentPage ?> of <?= $totalPages ?></span>
        <?php if ($currentPage < $totalPages): ?><a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('contracts', array_merge($filters, ['contract_page' => $currentPage + 1]))) ?>" data-contract-page="<?= $currentPage + 1 ?>">Next</a><?php endif; ?>
    </nav>
    <?php
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
                <?php if (t8_contract_is_open($contract)): ?><span class="t8-badge-monitoring t8-badge-open-contract">Open Contract</span><?php elseif ($monitoring): ?><span class="t8-badge-monitoring">Expiring Soon</span><?php endif; ?>
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

$monitoringRefreshRows = $pdo->query(
    "SELECT id, status, end_date, renewal_date FROM team8_contracts WHERE deleted_at IS NULL AND status = 'active'"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($monitoringRefreshRows as $monitoringRow) {
    t8_contract_refresh_monitoring_state($pdo, (int) $monitoringRow['id']);
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
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM team8_contracts c JOIN users u ON u.id = c.owner_id LEFT JOIN departments d ON d.id = c.department_id WHERE {$conditions}");
    $countStmt->execute($params);
    $totalPages = max(1, (int) ceil((int) $countStmt->fetchColumn() / T8_CONTRACT_PAGE_SIZE));
    $requestedPage = filter_var($_GET['contract_page'] ?? 1, FILTER_VALIDATE_INT);
    $currentPage = min(max(1, $requestedPage === false ? 1 : $requestedPage), $totalPages);
    $offset = ($currentPage - 1) * T8_CONTRACT_PAGE_SIZE;
    $stmt = $pdo->prepare("SELECT c.*, u.full_name AS owner_name, d.name AS department_name FROM team8_contracts c JOIN users u ON u.id = c.owner_id LEFT JOIN departments d ON d.id = c.department_id WHERE {$conditions} ORDER BY c.created_at DESC LIMIT " . T8_CONTRACT_PAGE_SIZE . " OFFSET {$offset}");
    $stmt->execute($params);
    ob_start();
    t8_contract_render_rows($stmt->fetchAll(PDO::FETCH_ASSOC), $isAdmin, $isLegal, $archived);
    $rowsHtml = (string) ob_get_clean();
    $paginationFilters = ['archived' => $archived ? 1 : 0, 'rejected' => $rejected ? 1 : 0];
    foreach (['search' => $search, 'status' => $statusFilter, 'contract_type' => $typeFilter] as $key => $value) {
        if ($value !== '') {
            $paginationFilters[$key] = $value;
        }
    }
    ob_start();
    t8_contract_render_pagination($currentPage, $totalPages, $paginationFilters);
    $paginationHtml = (string) ob_get_clean();
    echo json_encode(['html' => $rowsHtml, 'pagination' => $paginationHtml]);
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
    $normalizedStatus = t8_contract_normalize_status((string) ($contract['status'] ?? ''));
    if ($workflow === 'resend_signing_invites') {
        if (!$contract || !$isAdmin
            || !in_array($normalizedStatus, ['pending_signature', 'partially_signed'], true)) {
            t8_flash_set('danger', 'Only an administrator can resend signing invitations while the contract is accepting signatures.');
            redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
        }
        try {
            $delivery = t8_contract_send_signing_invitations($pdo, $id, $currentUserId);
            if ($delivery['failed'] !== []) {
                t8_flash_set('danger', 'Signing links sent to ' . $delivery['sent'] . ' party/parties; delivery failed for: ' . implode(', ', $delivery['failed']) . '. Correct contact details or mail configuration, then retry.');
            } elseif ($delivery['sent'] === 0 && $delivery['signed'] > 0) {
                t8_flash_set('success', 'All involved parties have already signed this frozen version.');
            } elseif ($delivery['sent'] === 0) {
                t8_flash_set('danger', 'No unsigned parties could be sent a signing link.');
            } else {
                t8_flash_set('success', 'Signing links sent to ' . $delivery['sent'] . ' unsigned party/parties.');
            }
        } catch (Throwable $e) {
            t8_gmail_diagnostic_log('Contract Signing Invitation Setup Failure', 'Contract #' . $id . ': ' . $e->getMessage());
            t8_flash_set('danger', 'Signing invitations could not be prepared. Check the frozen contract version, party email addresses, HTTPS URL, and SMTP configuration.');
        }
        redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
    }
    if ($workflow === 'cancel') {
        if (!$isAdmin || t8_contract_workflow_transition_target($workflow, $normalizedStatus, $isAdmin) === null) {
            t8_flash_set('danger', 'That contract cancellation is not allowed.');
            redirect(page_url('contracts'));
        }
        $reason = $comment !== '' ? $comment : 'Cancelled by ' . $currentUserName;
        $success = t8_contract_cancel($pdo, $id, $currentUserId, $reason);
        if (!$success) {
            t8_flash_set('danger', 'The contract could not be cancelled.');
            redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
        }
        t8_flash_set('success', 'Contract cancelled.');
        redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
    }
    if ($workflow === 'reopen_for_revision') {
        if (!$contract || !$isAdmin
            || t8_contract_workflow_transition_target($workflow, $normalizedStatus, $isAdmin) === null) {
            t8_flash_set('danger', 'Only an administrator can reopen a signed contract for revision.');
            redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
        }
        try {
            $pdo->beginTransaction();
            t8_contract_void_signatures_for_revision($pdo, $id);
            t8_contract_revoke_signing_invites_for_revision($pdo, $id);
            $update = $pdo->prepare(
                "UPDATE team8_contracts SET status = 'negotiation', signature_version_no = NULL, signature_document_hash = NULL
                 WHERE id = :id AND status IN ('pending_signature', 'partially_signed', 'fully_executed')"
            );
            $update->execute(['id' => $id]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Contract status changed before it could be reopened.');
            }
            $pdo->prepare(
                "UPDATE team8_contract_negotiations SET status = 'superseded', resolved_at = CURRENT_TIMESTAMP
                 WHERE contract_id = :contract_id AND status = 'pending'"
            )->execute(['contract_id' => $id]);
            $revisionMetadata = [
                'from_status' => $normalizedStatus,
                'to_status' => 'negotiation',
                'voided_reason' => 'superseded by new version',
            ];
            if ($comment !== '') {
                $revisionMetadata['reason'] = $comment;
            }
            t8_contract_event_log_required(
                $pdo,
                $id,
                $currentUserId,
                'CONTRACT_REOPENED_FOR_REVISION',
                'Contract reopened for revision; prior signatures were voided.',
                $revisionMetadata
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            t8_flash_set('danger', 'The contract could not be reopened for revision; its signatures were not changed.');
            redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
        }
        t8_contract_send_status_notification($pdo, $id, $currentUserId, 'reopen_for_revision', 'The contract was reopened for revision. Previous signatures are void and the revised version must be signed again.');
        t8_audit_log($pdo, $currentUserId, 'contract', $id, 'reopen_for_revision', $normalizedStatus, 'negotiation');
        t8_flash_set('success', 'Contract reopened for revision. Prior signatures were voided.');
        redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
    }
    if ($workflow === 'supersede') {
        if (!$isAdmin || $normalizedStatus !== 'active') {
            t8_flash_set('danger', 'Only active contracts can be superseded.');
            redirect(page_url('contracts'));
        }
        $reason = $comment !== '' ? $comment : 'Superseded by a newer contract version.';
        $success = t8_contract_mark_superseded($pdo, $id, $currentUserId, $reason);
        if (!$success) {
            t8_flash_set('danger', 'The contract could not be marked as superseded.');
            redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
        }
        t8_flash_set('success', 'Contract marked as superseded.');
        redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
    }
    if ($workflow === 'propose_changes') {
        $changeSummary = trim((string) ($_POST['change_summary'] ?? ''));
        if ($changeSummary === '') {
            t8_flash_set('danger', 'Add a brief summary of the proposed changes.');
            redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
        }
        $proposedData = [
            'title' => trim((string) ($_POST['title'] ?? $contract['title'])),
            'contract_type' => trim((string) ($_POST['contract_type'] ?? $contract['contract_type'])),
            'description' => trim((string) ($_POST['description'] ?? $contract['description'])),
            'amount' => trim((string) ($_POST['amount'] ?? $contract['amount'])),
            'currency' => trim((string) ($_POST['currency'] ?? $contract['currency'])),
            'payment_terms' => trim((string) ($_POST['payment_terms'] ?? $contract['payment_terms'])),
            'payment_frequency' => trim((string) ($_POST['payment_frequency'] ?? $contract['payment_frequency'])),
            'deposit_amount' => trim((string) ($_POST['deposit_amount'] ?? $contract['deposit_amount'])),
            'financial_notes' => trim((string) ($_POST['financial_notes'] ?? $contract['financial_notes'])),
            'notice_period_days' => trim((string) ($_POST['notice_period_days'] ?? $contract['notice_period_days'])),
        ];
        if ($proposedData['title'] === ''
            || !in_array($proposedData['currency'], T8_CONTRACT_CURRENCIES, true)
            || ($proposedData['payment_frequency'] !== ''
                && !in_array($proposedData['payment_frequency'], T8_CONTRACT_PAYMENT_FREQUENCIES, true))
            || ($proposedData['amount'] !== '' && (!is_numeric($proposedData['amount']) || (float) $proposedData['amount'] < 0))
            || ($proposedData['deposit_amount'] !== '' && (!is_numeric($proposedData['deposit_amount']) || (float) $proposedData['deposit_amount'] < 0))
            || ($proposedData['notice_period_days'] !== ''
                && (filter_var($proposedData['notice_period_days'], FILTER_VALIDATE_INT) === false
                    || (int) $proposedData['notice_period_days'] < 0))) {
            t8_flash_set('danger', 'Check the revised title, currency, payment frequency, amount, deposit, and notice period.');
            redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
        }
        $proposal = t8_contract_create_negotiation($pdo, $id, $currentUserId, $changeSummary, $proposedData, 'internal');
        if ($proposal === null) {
            t8_flash_set('danger', 'The proposed changes could not be saved.');
            redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
        }
        t8_contract_event_log($pdo, $id, $currentUserId, 'NEGOTIATION_TERMS_PUBLISHED', $changeSummary, ['version_no' => $proposal['version_no']]);
        t8_audit_log($pdo, $currentUserId, 'contract', $id, 'negotiation_terms_published', (string) $contract['status'], 'under_review');
        try {
            $inviteResult = t8_contract_send_involved_party_invites($pdo, $id, $currentUserId);
            if ($inviteResult['failed'] !== [] || $inviteResult['sent'] === 0) {
                $sentDetail = $inviteResult['sent'] > 0
                    ? $inviteResult['sent'] . ' linked ' . ($inviteResult['sent'] === 1 ? 'party received' : 'parties received') . ' an invitation.'
                    : '';
                $failureDetail = $inviteResult['failed'] !== []
                    ? ' Invitations could not be sent to: ' . implode(', ', $inviteResult['failed']) . '.'
                    : ' No linked parties could be invited.';
                t8_flash_set(
                    'danger',
                    'Changes saved as current version ' . $proposal['version_no'] . ' and the contract is In Review, but not every party received a review invitation.'
                    . ' ' . $sentDetail . $failureDetail . ' Use Resend review invitations after resolving the issue.'
                );
            } elseif ($inviteResult['sent'] > 0) {
                t8_flash_set(
                    'success',
                    'Changes saved — awaiting party review. Version ' . $proposal['version_no'] . ' is now current; review invitations were sent to '
                    . $inviteResult['sent'] . ' linked ' . ($inviteResult['sent'] === 1 ? 'party' : 'parties') . '.'
                );
            }
        } catch (Throwable $e) {
            t8_gmail_diagnostic_log('Revised Contract Review Invite Setup Failure', $e->getMessage());
            t8_flash_set(
                'danger',
                'Changes were saved as current version ' . $proposal['version_no'] . ' and the contract is In Review, but review invitations could not be prepared. Use Resend review invitations after resolving the issue.'
            );
        }
        redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
    }
    if ($workflow === 'approve' && $contract && $normalizedStatus === 'under_review'
        && !t8_contract_parties_have_accepted_latest_review($pdo, $id)) {
        t8_flash_set('danger', 'The contract cannot be approved for contracting until every involved party accepts the latest circulated version.');
        redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
    }
    $newStatus = t8_contract_workflow_transition_target($workflow, $normalizedStatus, $isAdmin);
    if (!$contract || $newStatus === null) {
        t8_flash_set('danger', 'That contract transition is not allowed.');
        redirect(page_url('contracts'));
    }
    $activationAudit = null;
    if ($workflow === 'fully_execute') {
        try {
            if (!t8_contract_all_parties_signed($pdo, $id)) {
                t8_flash_set('danger', 'Cannot mark as fully executed until every involved party has a valid signature for the frozen contract version.');
                redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
            }
        } catch (Throwable $e) {
            t8_flash_set('danger', 'Cannot verify the required signatures or frozen contract version. Apply the signature schema and retry.');
            redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
        }
    }
    if ($workflow === 'activate') {
        $activationAudit = t8_contract_activation_audit($normalizedStatus, $comment);
        if ($activationAudit === null) {
            t8_flash_set('danger', 'Activating without recorded execution requires a reason.');
            redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
        }
        try {
            if (!t8_contract_can_activate($pdo, $id)
                || ($normalizedStatus === 'fully_executed' && !t8_contract_all_parties_signed($pdo, $id))) {
                t8_flash_set('danger', 'The contract cannot be activated because its frozen version is stale, a negotiation is pending, or required signatures are invalid.');
                redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
            }
        } catch (Throwable $e) {
            t8_flash_set('danger', 'The contract cannot be activated until its frozen version and negotiation state can be verified.');
            redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
        }
    }
    if ($workflow === 'renewal') {
        $renewedContract = t8_contract_create_renewal($pdo, $contract, $currentUserId);
        if ($renewedContract === null) {
            t8_flash_set('danger', 'A renewal draft could not be created from this contract.');
            redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
        }
        t8_flash_set('success', 'A renewal draft was created and the original contract was marked for renewal.');
        redirect(page_url('contracts', ['action' => 'view', 'id' => (int) $renewedContract['id']]));
    }
    if ($workflow === 'sign') {
        try {
            $pdo->beginTransaction();
            t8_contract_ensure_renewal_history($pdo, $id, $currentUserId);
            $frozenDocument = t8_contract_freeze_signature_version($pdo, $id);
            t8_contract_event_log_required(
                $pdo,
                $id,
                $currentUserId,
                'CONTRACT_VERSION_FROZEN_FOR_SIGNATURE',
                'The latest saved contract version was frozen for signature.',
                [
                    'from_status' => 'pending_approval',
                    'to_status' => 'pending_signature',
                    'version_no' => $frozenDocument['version_no'],
                    'document_hash' => $frozenDocument['document_hash'],
                ]
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            t8_flash_set('danger', 'The contract cannot be sent for signature because its latest saved version could not be frozen.');
            redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
        }
        try {
            $delivery = t8_contract_send_signing_invitations($pdo, $id, $currentUserId);
            if ($delivery['failed'] !== []) {
                $signingFlash = [
                    'type' => 'danger',
                    'message' => 'The contract version was frozen. Signing invitations failed for: '
                        . implode(', ', $delivery['failed']) . '. Correct contact details or mail configuration, then use Resend Signing Invitations.',
                ];
            } else {
                $signingFlash = [
                    'type' => 'success',
                    'message' => 'The contract version was frozen and signing links were sent to '
                        . $delivery['sent'] . ' involved party/parties.',
                ];
            }
        } catch (Throwable $e) {
            t8_gmail_diagnostic_log('Contract Signing Invitation Setup Failure', 'Contract #' . $id . ': ' . $e->getMessage());
            $signingFlash = [
                'type' => 'danger',
                'message' => 'The contract version was frozen, but signing invitations could not be prepared. Check party contacts, HTTPS URL, and SMTP configuration; then retry from the contract.',
            ];
        }
    } elseif ($workflow === 'fully_execute') {
        try {
            $pdo->beginTransaction();
            $executed = t8_contract_mark_fully_executed($pdo, $id);
            if (!$executed) {
                throw new RuntimeException('Required party signatures are incomplete.');
            }
            $frozenDocument = t8_contract_frozen_document($pdo, $id);
            t8_contract_event_log_required(
                $pdo,
                $id,
                $currentUserId,
                'FULLY_EXECUTED',
                'All involved parties signed the frozen contract version.',
                [
                    'from_status' => $normalizedStatus,
                    'to_status' => 'fully_executed',
                    'version_no' => $frozenDocument['version_no'],
                    'document_hash' => $frozenDocument['document_hash'],
                ]
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            t8_flash_set('danger', 'Execution could not be committed because signature verification or contract locking failed.');
            redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
        }
    } elseif ($workflow === 'activate') {
        try {
            $pdo->beginTransaction();
            $update = $pdo->prepare(
                "UPDATE team8_contracts SET status = 'active' WHERE id = :id AND status = :expected_status"
            );
            $update->execute(['id' => $id, 'expected_status' => $normalizedStatus]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Contract status changed before activation.');
            }
            t8_contract_event_log_required(
                $pdo,
                $id,
                $currentUserId,
                $activationAudit['event_type'],
                $activationAudit['description'],
                $activationAudit['metadata']
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            t8_flash_set('danger', 'The contract was not activated because its status or required audit record could not be saved.');
            redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
        }
    } elseif ($workflow === 'terminate') {
        $reason = $comment !== '' ? $comment : 'Termination recorded by ' . $currentUserName;
        $pdo->prepare('UPDATE team8_contracts SET status = :status, termination_date = :termination_date, termination_reason = :reason, terminated_by = :terminated_by WHERE id = :id')->execute([
            'status' => $newStatus,
            'termination_date' => date('Y-m-d'),
            'reason' => $reason,
            'terminated_by' => $currentUserId,
            'id' => $id,
        ]);
    } elseif ($workflow === 'reject') {
        $reason = $comment !== '' ? $comment : 'Rejected by ' . $currentUserName;
        $pdo->prepare('UPDATE team8_contracts SET status = :status, rejection_reason = :reason WHERE id = :id')->execute(['status' => $newStatus, 'reason' => $reason, 'id' => $id]);
    } else {
        $pdo->prepare('UPDATE team8_contracts SET status = :status WHERE id = :id')->execute(['status' => $newStatus, 'id' => $id]);
    }
    try {
        $pdo->prepare('INSERT INTO team8_contract_approvals (contract_id, reviewer_id, approver_id, action, comment) VALUES (:cid, :reviewer, :approver, :action, :comment)')
            ->execute(['cid' => $id, 'reviewer' => $workflow === 'submit' ? $currentUserId : null, 'approver' => in_array($workflow, ['approve', 'sign', 'activate', 'fully_execute', 'reject'], true) ? $currentUserId : null, 'action' => $workflow, 'comment' => $comment ?: null]);
    } catch (PDOException $e) {
        // Approval history is optional until its migration is applied.
    }
    $notificationMessage = match ($workflow) {
        'submit' => 'Contract submitted for review and is awaiting internal assessment.',
        'revert_to_draft' => 'Contract returned to draft for editing.',
        'approve' => 'Contract approved and moved to the approval pipeline.',
        'request_changes' => 'Contract review requested changes and has returned to negotiation.',
        'return_to_review' => 'Contract was sent back to review for updated terms.',
        'sign' => 'Contract is ready for signature and external execution.',
        'fully_execute' => 'Contract was fully executed and is ready for activation.',
        'activate' => in_array($normalizedStatus, ['pending_signature', 'partially_signed'], true)
            ? 'Contract was activated before recorded execution; administrator reason: ' . $comment
            : 'Fully executed contract is now active and in force.',
        'renewal' => 'Contract renewal review has been initiated.',
        'terminate' => 'Contract termination has been recorded.',
        'expire' => 'Contract expiration has been recorded.',
        'reject' => 'Contract was rejected and moved to archive.',
        default => 'Contract lifecycle updated.'
    };
    if (!in_array($workflow, ['activate', 'sign', 'fully_execute'], true)) {
        $eventType = $workflow === 'revert_to_draft' ? 'CONTRACT_REVERTED' : $workflow;
        t8_contract_event_log($pdo, $id, $currentUserId, $eventType, $comment !== '' ? $comment : ucfirst(str_replace('_', ' ', $workflow)), ['from_status' => (string) $contract['status'], 'to_status' => $newStatus]);
    }
    t8_contract_send_status_notification($pdo, $id, $currentUserId, $workflow, $notificationMessage);
    t8_audit_log($pdo, $currentUserId, 'contract', $id, $workflow, (string) $contract['status'], $workflow === 'revert_to_draft' ? $newStatus : $comment);
    if (in_array($newStatus, ['expiration', 'termination', 'archived'], true)) {
        $updated = t8_contract_fetch($pdo, $id);
        if ($updated) t8_contract_register_retention($pdo, $updated, $currentUserId);
    }
    if ($workflow === 'sign' && isset($signingFlash)) {
        t8_flash_set($signingFlash['type'], $signingFlash['message']);
    } else {
        t8_flash_set('success', 'Contract status updated to ' . t8_contract_status_label($newStatus) . '.');
    }
    redirect($workflow === 'revert_to_draft'
        ? page_url('contracts', ['action' => 'edit', 'id' => $id])
        : page_url('contracts', ['action' => 'view', 'id' => $id]));
}

if (in_array($action, ['create', 'edit'], true)) {
    if (!$canManage) {
        t8_require_role(['admin', 'legal_officer']);
    }
    $id = $action === 'edit' ? (int) ($_GET['id'] ?? 0) : 0;
    $existing = $id ? t8_contract_fetch($pdo, $id) : null;
    if ($action === 'edit' && (!$existing || t8_contract_normalize_status((string) $existing['status']) !== 'draft')) {
        t8_flash_set('danger', 'Only draft contracts can be edited.');
        redirect(page_url('contracts'));
    }
    $values = $existing ?: ['contract_number' => t8_contract_next_number($pdo), 'title' => '', 'contract_type' => '', 'description' => '', 'department_id' => '', 'start_date' => date('Y-m-d'), 'end_date' => '', 'renewal_date' => '', 'amount' => '', 'currency' => 'PHP', 'payment_terms' => '', 'payment_frequency' => '', 'deposit_amount' => '', 'financial_notes' => '', 'notice_period_days' => '', 'renewed_from_id' => ''];
    $allParties = $pdo->query("SELECT id, COALESCE(NULLIF(trade_name, ''), name) AS name, type FROM team8_parties ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $selectedPartyIds = [];
    if ($existing) {
        $st = $pdo->prepare('SELECT party_id FROM team8_contract_parties WHERE contract_id = :id');
        $st->execute(['id' => $id]);
        $selectedPartyIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach (['title', 'contract_type', 'description', 'department_id', 'start_date', 'end_date', 'renewal_date', 'amount', 'currency', 'payment_terms', 'payment_frequency', 'deposit_amount', 'financial_notes', 'notice_period_days', 'renewed_from_id'] as $key) {
            $values[$key] = trim((string) ($_POST[$key] ?? ($key === 'currency' ? 'PHP' : '')));
            if (in_array($key, ['amount', 'deposit_amount'], true)) {
                $values[$key] = t8_contract_sanitize_money_value($values[$key]);
            }
        }
        $selectedPartyIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['party_ids'] ?? [])))));
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) $errors[] = 'Your session expired.';
        if ($values['title'] === '') $errors[] = 'Contract title is required.';
        if ($selectedPartyIds === []) $errors[] = 'At least one party is required.';
        if ($selectedPartyIds !== []) {
            $partyPlaceholders = [];
            $partyParams = [];
            foreach ($selectedPartyIds as $index => $partyId) {
                $placeholder = 'party_' . $index;
                $partyPlaceholders[] = ':' . $placeholder;
                $partyParams[$placeholder] = $partyId;
            }
            $selectedPartyStmt = $pdo->prepare(
                "SELECT id, COALESCE(NULLIF(trade_name, ''), name) AS name, contact_email FROM team8_parties WHERE id IN (" . implode(', ', $partyPlaceholders) . ')'
            );
            $selectedPartyStmt->execute($partyParams);
            $selectedPartyRows = $selectedPartyStmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($selectedPartyRows) !== count($selectedPartyIds)) {
                $errors[] = 'One or more selected parties could not be found.';
            }
            foreach ($selectedPartyRows as $selectedParty) {
                $partyEmail = trim((string) ($selectedParty['contact_email'] ?? ''));
                if ($partyEmail === '' || filter_var($partyEmail, FILTER_VALIDATE_EMAIL) === false) {
                    $errors[] = 'A valid Party Email is required for ' . (string) $selectedParty['name'] . '. Update the party in the Party Registry before continuing.';
                }
            }
        }
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
        <?php foreach (['Draft', 'Under Review / Negotiation', 'Pending Approval', 'Pending Signature / Partially Signed', 'Fully Executed', 'Active', 'Renewal / Expiration / Termination'] as $stepIndex => $stepLabel): ?>
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
            <div class="t8-field"><label class="t8-label" for="contract_type">Contract Type <span class="t8-required">*</span></label><select class="t8-select" id="contract_type" name="contract_type" required><option value="">Select type</option><?php foreach (T8_CONTRACT_TYPES as $type): ?><option value="<?= e($type) ?>" <?= ($values['contract_type'] ?? '') === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></div>
            <div class="t8-field t8-form-span-2"><label class="t8-label" for="title">Contract Title <span class="t8-required">*</span></label><input class="t8-input" id="title" name="title" required value="<?= e((string) $values['title']) ?>"></div>
            <div class="t8-field t8-form-span-2"><label class="t8-label" for="description">Description / Purpose</label><textarea class="t8-input" id="description" name="description" rows="3"><?= e((string) ($values['description'] ?? '')) ?></textarea></div>
        </div></div>
        <div class="t8-card">
            <h2 class="t8-card-title">Parties <span class="t8-required">*</span></h2>
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
            <?php foreach (['start_date' => 'Start Date', 'end_date' => 'End Date', 'renewal_date' => 'Renewal Date'] as $field => $label): ?><div class="t8-field"><label class="t8-label" for="<?= e($field) ?>"><?= e($label) ?><?= $field === 'start_date' ? ' <span class="t8-required">*</span>' : '' ?></label><input class="t8-input" type="date" id="<?= e($field) ?>" name="<?= e($field) ?>" value="<?= e((string) ($values[$field] ?? '')) ?>" <?= $field === 'start_date' ? 'min="' . e(date('Y-m-d')) . '" required' : '' ?>></div><?php endforeach; ?>
            <div class="t8-field"><label class="t8-label" for="renewed_from_id">Renewed From</label><select class="t8-select" id="renewed_from_id" name="renewed_from_id"><option value="">Not a renewal</option><?php foreach ($pdo->query('SELECT id, title FROM team8_contracts WHERE id <> ' . (int) $id . ' ORDER BY title')->fetchAll(PDO::FETCH_ASSOC) as $renewable): ?><option value="<?= (int) $renewable['id'] ?>" <?= (string) $renewable['id'] === (string) ($values['renewed_from_id'] ?? '') ? 'selected' : '' ?>><?= e((string) $renewable['title']) ?></option><?php endforeach; ?></select></div>
        </div></div>
        <div class="t8-card"><h2 class="t8-card-title">Financial Terms</h2><div class="t8-form-grid">
            <div class="t8-field"><label class="t8-label" for="currency">Currency</label><select class="t8-select" id="currency" name="currency"><?php foreach (T8_CONTRACT_CURRENCIES as $currency): ?><option <?= ($values['currency'] ?? 'PHP') === $currency ? 'selected' : '' ?>><?= e($currency) ?></option><?php endforeach; ?></select></div>
            <div class="t8-field"><label class="t8-label" for="amount">Contract Value</label><input class="t8-input" type="text" inputmode="decimal" id="amount" name="amount" value="<?= e((string) ($values['amount'] ?? '')) ?>" data-money-input></div>
            <div class="t8-field"><label class="t8-label" for="payment_frequency">Payment Frequency</label><select class="t8-select" id="payment_frequency" name="payment_frequency"><option value="">Select frequency</option><?php foreach (T8_CONTRACT_PAYMENT_FREQUENCIES as $frequency): ?><option <?= ($values['payment_frequency'] ?? '') === $frequency ? 'selected' : '' ?>><?= e($frequency) ?></option><?php endforeach; ?></select></div>
            <div class="t8-field"><label class="t8-label" for="deposit_amount">Deposit / Advance</label><input class="t8-input" type="text" inputmode="decimal" id="deposit_amount" name="deposit_amount" value="<?= e((string) ($values['deposit_amount'] ?? '')) ?>" data-money-input></div>
            <div class="t8-field"><label class="t8-label" for="notice_period_days">Notice Period (days)</label><input class="t8-input" type="number" min="0" id="notice_period_days" name="notice_period_days" value="<?= e((string) ($values['notice_period_days'] ?? '')) ?>"></div>
            <div class="t8-field"><label class="t8-label" for="payment_terms">Payment Terms</label><input class="t8-input" id="payment_terms" name="payment_terms" value="<?= e((string) ($values['payment_terms'] ?? '')) ?>"></div>
        </div></div>
        <div class="t8-card"><h2 class="t8-card-title">Additional Notes &amp; Attachment</h2><div class="t8-field"><label class="t8-label" for="financial_notes">Notes / Special Conditions</label><textarea class="t8-input" id="financial_notes" name="financial_notes" rows="3"><?= e((string) ($values['financial_notes'] ?? '')) ?></textarea></div><div class="t8-field"><label class="t8-label" for="attachment">Attachment (image or document, max 10 MB)</label><input class="t8-input" type="file" id="attachment" name="attachment" accept=".png,.jpg,.jpeg,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt"><span class="t8-help-text">An existing attachment is retained when no new file is selected.</span></div></div>
        <div class="t8-form-actions"><button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-arrow-right"></i> Continue to Review</button><a class="t8-btn t8-btn-outline" href="<?= e(page_url('contracts')) ?>">Cancel</a></div>
    </form>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const normalizeMoneyInput = function (value) {
                let sanitized = (value ?? '').toString().replace(/[^\d.]/g, '');
                const firstDot = sanitized.indexOf('.');
                if (firstDot !== -1) {
                    const whole = sanitized.slice(0, firstDot).replace(/\D/g, '');
                    const fraction = sanitized.slice(firstDot + 1).replace(/\./g, '').slice(0, 2);
                    sanitized = whole + (fraction !== '' ? '.' + fraction : '');
                } else {
                    sanitized = sanitized.replace(/\D/g, '');
                }
                return sanitized;
            };

            const formatMoneyValue = function (value) {
                const normalized = normalizeMoneyInput(value);
                if (normalized === '' || normalized === '.') {
                    return '';
                }

                const [wholePart, fractionPart = ''] = normalized.split('.');
                const formattedWhole = wholePart === '' ? '0' : Number(wholePart).toLocaleString('en-US');
                return fractionPart !== '' ? formattedWhole + '.' + fractionPart : formattedWhole;
            };

            document.querySelectorAll('[data-money-input]').forEach(function (input) {
                input.addEventListener('input', function () {
                    const normalized = normalizeMoneyInput(input.value);
                    input.value = formatMoneyValue(normalized);
                });

                input.addEventListener('focus', function () {
                    input.value = normalizeMoneyInput(input.value);
                });

                input.addEventListener('blur', function () {
                    const normalized = normalizeMoneyInput(input.value);
                    if (normalized === '') {
                        input.value = '';
                        return;
                    }
                    const numericValue = Number(normalized);
                    if (!Number.isFinite(numericValue)) {
                        input.value = '';
                        return;
                    }
                    input.value = new Intl.NumberFormat('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                    }).format(numericValue);
                });
            });

            document.addEventListener('submit', function (event) {
                const form = event.target;
                if (!(form instanceof HTMLFormElement)) {
                    return;
                }
                form.querySelectorAll('[data-money-input]').forEach(function (input) {
                    input.value = normalizeMoneyInput(input.value);
                });
            });
        });
    </script>
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
    if (!$canManage || !$contract || t8_contract_normalize_status((string) $contract['status']) !== 'draft') {
        t8_flash_set('danger', 'Only a draft can be submitted for review.'); redirect(page_url('contracts'));
    }
    $pdo->prepare("UPDATE team8_contracts SET status = 'under_review' WHERE id = :id")->execute(['id' => $id]);
    t8_contract_event_log($pdo, $id, $currentUserId, 'CONTRACT_SUBMITTED', 'Contract submitted for review.', ['from_status' => 'draft', 'to_status' => 'under_review']);
    t8_audit_log($pdo, $currentUserId, 'contract', $id, 'submit', 'draft', 'under_review');
    try {
        $inviteResult = t8_contract_send_involved_party_invites($pdo, $id, $currentUserId);
        if ($inviteResult['failed'] !== []) {
            $failedNames = implode(', ', $inviteResult['failed']);
            t8_flash_set('danger', 'Contract submitted for review, but secure review invitations could not be sent to: ' . $failedNames . '. Check the mail configuration and try sending the invites again.');
        } elseif ($inviteResult['sent'] > 0) {
            t8_flash_set('success', 'Contract submitted for review and secure review invitations sent to ' . $inviteResult['sent'] . ' involved ' . ($inviteResult['sent'] === 1 ? 'party.' : 'parties.'));
        } else {
            t8_flash_set('danger', 'Contract submitted for review, but no involved parties with valid email addresses were available to invite.');
        }
    } catch (Throwable $e) {
        t8_gmail_diagnostic_log('Contract Portal Invite Setup Failure', $e->getMessage());
        t8_flash_set('danger', 'Contract submitted for review, but secure review invitations could not be prepared. Check the application log and try again.');
    }
    redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
}

if ($action === 'send_invites') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !t8_csrf_verify($_POST['csrf_token'] ?? null)) {
        t8_flash_set('danger', 'Your session expired.');
        redirect(page_url('contracts'));
    }
    $id = (int) ($_POST['id'] ?? 0);
    $contract = t8_contract_fetch($pdo, $id);
    if (!$canManage || !$contract || t8_contract_normalize_status((string) $contract['status']) !== 'under_review') {
        t8_flash_set('danger', 'Only an under-review contract can send external review invitations.');
        redirect(page_url('contracts'));
    }
    try {
        $inviteResult = t8_contract_send_involved_party_invites($pdo, $id, $currentUserId);
        if ($inviteResult['failed'] !== []) {
            t8_flash_set('danger', 'Review invitations sent to ' . $inviteResult['sent'] . ' parties. Failed: ' . implode(', ', $inviteResult['failed']) . '. Check the mail configuration and try again.');
        } elseif ($inviteResult['sent'] > 0) {
            t8_flash_set('success', 'Secure review invitations sent to ' . $inviteResult['sent'] . ' involved ' . ($inviteResult['sent'] === 1 ? 'party.' : 'parties.'));
        } else {
            t8_flash_set('danger', 'No involved parties with valid email addresses were available to invite.');
        }
    } catch (Throwable $e) {
        t8_gmail_diagnostic_log('Contract Portal Invite Setup Failure', $e->getMessage());
        t8_flash_set('danger', 'Secure review invitations could not be prepared. Check the application log and try again.');
    }
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
    $partyStmt = $pdo->prepare("SELECT cp.*, COALESCE(NULLIF(p.trade_name, ''), p.name) AS party_name, p.type AS party_type, p.primary_contact, p.contact_email FROM team8_contract_parties cp JOIN team8_parties p ON p.id = cp.party_id WHERE cp.contract_id = :id ORDER BY cp.created_at");
    $partyStmt->execute(['id' => $viewId]);
    $viewParties = $partyStmt->fetchAll(PDO::FETCH_ASSOC);
    $viewPartyReviews = t8_contract_get_party_review_statuses($pdo, $viewId);
    $viewPartiesAccepted = t8_contract_parties_have_accepted_latest_review($pdo, $viewId);
    $viewLatestPublishedRevision = null;
    if (t8_contract_normalize_status((string) $viewContract['status']) === 'under_review') {
        $latestRevisionStmt = $pdo->prepare(
            "SELECT n.version_no, n.change_summary, n.created_at
             FROM team8_contract_negotiations n
             WHERE n.contract_id = :contract_id AND n.source = 'internal' AND n.status = 'accepted'
               AND n.version_no = (
                   SELECT MAX(h.version_no)
                   FROM team8_contract_history h
                   WHERE h.contract_id = n.contract_id
               )
             ORDER BY n.created_at DESC
             LIMIT 1"
        );
        $latestRevisionStmt->execute(['contract_id' => $viewId]);
        $viewLatestPublishedRevision = $latestRevisionStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $viewSigningStatuses = [];
    if (in_array(t8_contract_normalize_status((string) $viewContract['status']), ['pending_signature', 'partially_signed', 'fully_executed'], true)) {
        $viewSigningStatuses = t8_contract_signing_party_statuses($pdo, $viewId);
    }
}

if ($action === 'review' || $action === 'view') {
    $stages = ['draft', 'under_review', 'negotiation', 'pending_approval', 'pending_signature', 'partially_signed', 'fully_executed', 'active', 'renewal'];
    $normalizedStatus = t8_contract_normalize_status((string) $viewContract['status']);
    $currentStageIndex = array_search($normalizedStatus, $stages, true);
    if ($currentStageIndex === false) {
        $currentStageIndex = 0;
    }
    $canResendReviewInvites = $canManage && $normalizedStatus === 'under_review';
    $canCancelContract = $isAdmin && in_array($normalizedStatus, ['draft', 'under_review', 'negotiation', 'pending_approval', 'pending_signature', 'partially_signed', 'fully_executed'], true);
    ?>
    <div class="t8-card" style="padding: 24px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
        <div class="t8-contract-view-toolbar">
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('contracts')) ?>"><i class="fa-solid fa-arrow-left"></i> Back to Registry</a>
            <?php if ($canResendReviewInvites || $canCancelContract): ?>
                <div class="t8-contract-view-actions">
                    <div class="t8-meatball-wrap">
                        <button type="button" class="t8-btn t8-btn-outline t8-btn-sm t8-meatball-btn" aria-label="More contract actions" aria-haspopup="true" aria-expanded="false" title="More actions">
                            <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="t8-meatball-menu" role="menu" hidden>
                            <ul>
                                <?php if ($canResendReviewInvites): ?>
                                    <li>
                                        <form method="post" action="<?= e(page_url('contracts', ['action' => 'send_invites'])) ?>">
                                            <?= t8_csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $viewId ?>">
                                            <button class="t8-row-menu-item" type="submit" role="menuitem"><i class="fa-solid fa-envelope" aria-hidden="true"></i>Resend review invitations</button>
                                        </form>
                                    </li>
                                <?php endif; ?>
                                <?php if ($canCancelContract): ?>
                                    <li>
                                        <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>" onsubmit="return confirm('Cancel this contract? This action cannot be undone.')">
                                            <?= t8_csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $viewId ?>">
                                            <input type="hidden" name="workflow_action" value="cancel">
                                            <button class="t8-row-menu-item t8-danger" type="submit" role="menuitem"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>Cancel contract</button>
                                        </form>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
        </div>

        <div class="t8-contract-view-header">
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
            <?php if ($viewLatestPublishedRevision !== null): ?>
                <div class="t8-revision-saved-notice" role="status">
                    <strong>Changes saved — awaiting party review</strong>
                    <span>Version <?= (int) $viewLatestPublishedRevision['version_no'] ?> is current. <?= e((string) $viewLatestPublishedRevision['change_summary']) ?></span>
                </div>
            <?php endif; ?>
            <div class="t8-detail-grid">
                <div class="t8-detail-item">
                    <span class="t8-detail-label">Status</span>
                    <span class="t8-detail-value">
                        <span class="t8-badge <?= e(t8_contract_status_badge((string) $viewContract['status'])) ?>"><?= e(t8_contract_status_label((string) $viewContract['status'])) ?></span>
                        <?php if (t8_contract_is_open($viewContract)): ?> <span class="t8-badge-monitoring t8-badge-open-contract">Open Contract</span><?php elseif (t8_contract_is_monitoring($viewContract)): ?> <span class="t8-badge-monitoring">Expiring Soon</span><?php endif; ?>
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
                    <span class="t8-detail-value"><?= e($viewContract['amount'] !== null ? t8_contract_format_money((string) $viewContract['amount'], (string) ($viewContract['currency'] ?? 'PHP')) : '—') ?></span>
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

            <?php if ($canManage && t8_contract_normalize_status((string) $viewContract['status']) === 'negotiation'): ?>
                <?php
                $partyFeedbackRequests = array_values(array_filter(
                    $viewPartyReviews,
                    static fn (array $review): bool => !empty($review['response_feedback']) || !empty($review['meeting_details'])
                ));
                ?>
                <?php if ($partyFeedbackRequests !== []): ?>
                    <section class="t8-external-request-card" aria-labelledby="external-request-heading">
                        <h3 id="external-request-heading">External request</h3>
                        <p class="t8-help-text">Read what each party asked for, then choose how to respond.</p>
                        <div class="t8-party-feedback-list">
                            <?php foreach ($partyFeedbackRequests as $partyReview): ?>
                                <article class="t8-party-feedback-item">
                                    <div class="t8-party-feedback-heading">
                                        <strong><?= e((string) $partyReview['party_name']) ?></strong>
                                        <span class="t8-badge"><?= e(ucfirst(str_replace('_', ' ', (string) ($partyReview['status'] ?? 'not invited')))) ?></span>
                                    </div>
                                    <?php if (!empty($partyReview['response_feedback'])): ?>
                                        <blockquote><?= nl2br(e((string) $partyReview['response_feedback'])) ?></blockquote>
                                    <?php endif; ?>
                                    <?php if (!empty($partyReview['meeting_details'])): ?>
                                        <p class="t8-party-meeting-request"><strong>Meeting requested:</strong> <?= nl2br(e((string) $partyReview['meeting_details'])) ?></p>
                                    <?php endif; ?>
                                    <small class="t8-party-request-meta">
                                        Requested by: <?= e((string) $partyReview['party_name']) ?>
                                        <?php if (!empty($partyReview['invited_at'])): ?>
                                            · <?= e(format_date((string) $partyReview['invited_at'], 'M d, Y g:i A')) ?>
                                        <?php endif; ?>
                                    </small>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
                <details class="t8-proposal-editor">
                    <summary class="t8-btn t8-btn-accent">Respond with revised terms</summary>
                    <form class="t8-contract-proposal-form" method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>">
                        <h3>Prepare revised terms</h3>
                        <p class="t8-help-text">Saving publishes these terms as the current version and automatically sends every linked party a fresh review invitation.</p>
                        <?= t8_csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $viewId ?>">
                        <input type="hidden" name="workflow_action" value="propose_changes">
                        <label class="t8-form-label" for="change-summary">Summary of proposed changes</label>
                        <textarea id="change-summary" name="change_summary" required maxlength="1000" rows="3"></textarea>
                        <div class="t8-form-grid">
                            <label>Contract title<input name="title" value="<?= e((string) $viewContract['title']) ?>" required></label>
                            <label>Description<textarea name="description" rows="3"><?= e((string) ($viewContract['description'] ?? '')) ?></textarea></label>
                            <label>Amount<input name="amount" type="number" min="0" step="0.01" value="<?= e((string) ($viewContract['amount'] ?? '')) ?>"></label>
                            <label>Currency<select name="currency"><?php foreach (T8_CONTRACT_CURRENCIES as $currency): ?><option value="<?= e($currency) ?>" <?= (string) ($viewContract['currency'] ?? 'PHP') === $currency ? 'selected' : '' ?>><?= e($currency) ?></option><?php endforeach; ?></select></label>
                            <label>Payment terms<textarea name="payment_terms" rows="3"><?= e((string) ($viewContract['payment_terms'] ?? '')) ?></textarea></label>
                            <label>Payment frequency<select name="payment_frequency"><option value="">Not specified</option><?php foreach (T8_CONTRACT_PAYMENT_FREQUENCIES as $frequency): ?><option value="<?= e($frequency) ?>" <?= (string) ($viewContract['payment_frequency'] ?? '') === $frequency ? 'selected' : '' ?>><?= e($frequency) ?></option><?php endforeach; ?></select></label>
                            <label>Deposit amount<input name="deposit_amount" type="number" min="0" step="0.01" value="<?= e((string) ($viewContract['deposit_amount'] ?? '')) ?>"></label>
                            <label>Additional financial notes<textarea name="financial_notes" rows="3"><?= e((string) ($viewContract['financial_notes'] ?? '')) ?></textarea></label>
                            <label>Notice period (days)<input name="notice_period_days" type="number" min="0" value="<?= e((string) ($viewContract['notice_period_days'] ?? '')) ?>"></label>
                        </div>
                        <button class="t8-btn t8-btn-accent" type="submit">Save changes and send for review</button>
                    </form>
                </details>
            <?php endif; ?>

            <?php if ($canManage): ?>
                <div class="t8-form-actions" style="margin-top: 24px; border-top: 1px solid #eee; padding-top: 16px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <?php if ($viewContract['status'] === 'draft'): ?>
                        <form method="post" action="<?= e(page_url('contracts', ['action' => 'submit'])) ?>" style="margin: 0;">
                            <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= $viewId ?>">
                            <button class="t8-btn t8-btn-accent">Submit for Review</button>
                        </form>
                        <?php if ($action === 'review'): ?>
                            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('contracts', ['action' => 'edit', 'id' => $viewId])) ?>">Back to Edit</a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (t8_contract_normalize_status((string) $viewContract['status']) === 'under_review'): ?>
                        <?php if ($isAdmin && $viewPartiesAccepted): ?>
                            <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>" style="margin: 0;">
                                <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= $viewId ?>"><input type="hidden" name="workflow_action" value="approve">
                                <button class="t8-btn t8-btn-success">Approve for Contracting</button>
                            </form>
                        <?php elseif ($isAdmin): ?>
                            <p class="t8-help-text" style="flex-basis:100%;">Contracting approval is locked until every involved party accepts the latest circulated version.</p>
                        <?php endif; ?>
                        <?php if ($canManage): ?>
                            <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>" style="margin: 0;">
                                <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= $viewId ?>"><input type="hidden" name="workflow_action" value="revert_to_draft">
                                <button class="t8-btn t8-btn-outline" type="submit">Back to Edit (Revert to Draft)</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($isAdmin && t8_contract_normalize_status((string) $viewContract['status']) === 'pending_approval'): ?>
                        <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>" style="margin: 0;">
                            <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= $viewId ?>"><input type="hidden" name="workflow_action" value="sign">
                            <button class="t8-btn t8-btn-success">Approve to Signature</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($isAdmin && in_array(t8_contract_normalize_status((string) $viewContract['status']), ['pending_signature', 'partially_signed'], true)): ?>
                        <?php
                        $signedPartyCount = count(array_filter($viewSigningStatuses, static fn (array $party): bool => $party['signing_status'] === 'signed'));
                        $totalSigningParties = count($viewSigningStatuses);
                        $allPartiesSigned = $totalSigningParties > 0 && $signedPartyCount === $totalSigningParties;
                        $pendingSignatureCount = $totalSigningParties - $signedPartyCount;
                        $frozenDocumentHash = (string) ($viewContract['signature_document_hash'] ?? '');
                        ?>
                        <section class="t8-signature-actions" aria-label="Signature execution actions">
                            <div class="t8-signature-primary" role="status">
                                <p class="t8-signature-primary-text">
                                    <?php if ($allPartiesSigned): ?>
                                        <strong>Ready to execute.</strong> All parties have signed.
                                    <?php else: ?>
                                        <strong>Not ready to execute.</strong>
                                        Waiting on <?= $pendingSignatureCount ?> of <?= $totalSigningParties ?> signature<?= $totalSigningParties === 1 ? '' : 's' ?>.
                                    <?php endif; ?>
                                </p>
                                <div class="t8-signature-primary-buttons">
                                    <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>">
                                        <?= t8_csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $viewId ?>">
                                        <input type="hidden" name="workflow_action" value="resend_signing_invites">
                                        <button class="t8-btn t8-btn-outline" type="submit">Resend Signing Invitations</button>
                                    </form>
                                    <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>">
                                        <?= t8_csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $viewId ?>">
                                        <input type="hidden" name="workflow_action" value="fully_execute">
                                        <button class="t8-btn t8-btn-success" type="submit" <?= $allPartiesSigned ? '' : 'disabled title="All parties must sign first"' ?>>Complete Execution</button>
                                    </form>
                                </div>
                            </div>

                            <details class="t8-signature-escape">
                                <summary>Activate without recorded execution</summary>
                                <div class="t8-signature-escape-body">
                                    <p class="t8-signature-escape-hint">Admin-only override. This logs an <code>ACTIVATED_WITHOUT_EXECUTION</code> audit event and requires a written reason. Signatures remain outstanding.</p>
                                    <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>">
                                        <?= t8_csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $viewId ?>">
                                        <input type="hidden" name="workflow_action" value="activate">
                                        <label class="t8-signature-sr-only" for="activation-reason">Reason for activating without recorded execution (required)</label>
                                        <input class="t8-signature-escape-input" id="activation-reason" name="comment" required maxlength="1000" placeholder="Reason for activating without recorded execution (required)">
                                        <button class="t8-btn t8-btn-danger" type="submit">Mark Active</button>
                                    </form>
                                </div>
                            </details>

                            <details class="t8-signature-escape">
                                <summary>Reopen for revision</summary>
                                <div class="t8-signature-escape-body">
                                    <p class="t8-signature-escape-hint">Voids all existing signatures for this version and returns the contract to negotiation. A new version must be frozen before signing resumes.</p>
                                    <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>">
                                        <?= t8_csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $viewId ?>">
                                        <input type="hidden" name="workflow_action" value="reopen_for_revision">
                                        <label class="t8-signature-sr-only" for="revision-reason">Optional revision reason</label>
                                        <input class="t8-signature-escape-input" id="revision-reason" name="comment" maxlength="1000" placeholder="Optional revision reason">
                                        <button class="t8-btn t8-btn-outline" type="submit">Reopen for Revision</button>
                                    </form>
                                </div>
                            </details>

                            <div class="t8-signature-audit-strip">
                                <span>Frozen version:</span>
                                <code>v<?= (int) ($viewContract['signature_version_no'] ?? 0) ?></code>
                                <span aria-hidden="true">·</span>
                                <span>Document hash:</span>
                                <?php if ($frozenDocumentHash !== ''): ?>
                                    <code title="<?= e($frozenDocumentHash) ?>"><?= e(substr($frozenDocumentHash, 0, 4)) ?>&hellip;<?= e(substr($frozenDocumentHash, -4)) ?></code>
                                <?php else: ?>
                                    <code>Unavailable</code>
                                <?php endif; ?>
                            </div>
                        </section>
                    <?php endif; ?>

                    <?php if ($isAdmin && t8_contract_normalize_status((string) $viewContract['status']) === 'fully_executed'): ?>
                        <div class="t8-activation-panel">
                            <div class="t8-activation-header">
                                <p class="t8-activation-copy">
                                    <span class="t8-activation-title">Ready to activate.</span>
                                    <span class="t8-activation-subtitle">Marking active records the start of the contract period.</span>
                                </p>
                                <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>" class="t8-activation-form">
                                    <?= t8_csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $viewId ?>">
                                    <input type="hidden" name="workflow_action" value="activate">
                                    <button class="t8-btn t8-btn-success t8-activation-btn" type="submit">Mark Active</button>
                                </form>
                            </div>

                            <details class="t8-revision-panel">
                                <summary>Reopen for revision</summary>
                                <div class="t8-revision-panel-body">
                                    <p class="t8-signature-escape-hint">
                                        Voids all existing signatures for this version and returns the contract to negotiation. A new version must be frozen before signing resumes.
                                    </p>
                                    <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>" class="t8-revision-form">
                                        <?= t8_csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $viewId ?>">
                                        <input type="hidden" name="workflow_action" value="reopen_for_revision">
                                        <input class="t8-revision-input" name="comment" maxlength="1000" placeholder="Optional revision reason">
                                        <button class="t8-btn t8-btn-outline t8-revision-submit" type="submit">Reopen for Revision</button>
                                    </form>
                                </div>
                            </details>
                        </div>
                    <?php endif; ?>

                    <?php if ($isAdmin && $viewContract['status'] === 'active'): ?>
                        <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>" style="margin: 0;">
                            <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= $viewId ?>"><input type="hidden" name="workflow_action" value="renewal">
                            <button class="t8-btn t8-btn-outline">Mark for Renewal</button>
                        </form>
                        <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>" style="margin: 0;">
                            <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= $viewId ?>"><input type="hidden" name="workflow_action" value="supersede">
                            <button class="t8-btn t8-btn-outline">Mark Superseded</button>
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
                    <thead><tr><th>Name</th><th>Type</th><th>Role</th><th>Contact</th><th>Latest Review</th><th>Signing</th><th>Party Feedback</th></tr></thead>
                    <tbody>
                        <?php foreach ($viewParties as $party): ?>
                            <?php
                            $partyReview = null;
                            foreach ($viewPartyReviews as $review) {
                                if ((int) $review['party_id'] === (int) $party['party_id']) {
                                    $partyReview = $review;
                                    break;
                                }
                            }
                            $signingStatus = '—';
                            foreach ($viewSigningStatuses as $signingParty) {
                                if ((int) $signingParty['party_id'] === (int) $party['party_id']) {
                                    $signingStatus = str_replace('_', ' ', (string) $signingParty['signing_status']);
                                    break;
                                }
                            }
                            ?>
                            <tr>
                                <td><?= e((string) $party['party_name']) ?></td>
                                <td><?= e(ucfirst((string) $party['party_type'])) ?></td>
                                <td><?= e((string) $party['role_in_contract']) ?></td>
                                <td><?= e((string) ($party['primary_contact'] ?? $party['contact_email'] ?? '—')) ?></td>
                                <td><?= e(ucfirst(str_replace('_', ' ', (string) ($partyReview['status'] ?? 'not invited')))) ?></td>
                                <td><?= e(ucfirst($signingStatus)) ?></td>
                                <td>
                                    <?= !empty($partyReview['response_feedback']) ? nl2br(e((string) $partyReview['response_feedback'])) : '—' ?>
                                    <?php if (!empty($partyReview['meeting_details'])): ?>
                                        <br><strong>Meeting:</strong> <?= nl2br(e((string) $partyReview['meeting_details'])) ?>
                                    <?php endif; ?>
                                </td>
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
            $negotiations = [];
            $st = $pdo->prepare('SELECT h.*, u.full_name FROM team8_contract_history h JOIN users u ON u.id = h.changed_by WHERE h.contract_id = :id ORDER BY h.version_no DESC LIMIT 25');
            $st->execute(['id' => $viewId]);
            $history = $st->fetchAll(PDO::FETCH_ASSOC);
            $proposalStmt = $pdo->prepare(
                "SELECT n.*, COALESCE(u.full_name, NULLIF(p.trade_name, ''), p.name, 'External party') AS proposed_by_name
                 FROM team8_contract_negotiations n
                 LEFT JOIN users u ON u.id = n.proposed_by
                 LEFT JOIN team8_parties p ON p.id = n.proposed_party_id
                 WHERE n.contract_id = :id ORDER BY n.created_at DESC"
            );
            $proposalStmt->execute(['id' => $viewId]);
            $negotiations = $proposalStmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <h3>Contract Versions</h3>
            <?php if (!$history): ?>
                <p class="t8-empty">No version history recorded.</p>
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
            <h3 style="margin-top:24px;">Negotiation Proposals</h3>
            <?php if (!$negotiations): ?>
                <p class="t8-empty">No negotiation proposals recorded.</p>
            <?php else: ?>
                <table class="t8-table">
                    <thead><tr><th>Version</th><th>Proposed By</th><th>When</th><th>Summary</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($negotiations as $proposal): ?>
                            <tr>
                                <td>v<?= (int) $proposal['version_no'] ?></td>
                                <td><?= e((string) $proposal['proposed_by_name']) ?></td>
                                <td><?= e(format_date((string) $proposal['created_at'], 'M d, Y g:i A')) ?></td>
                                <td><?= e((string) $proposal['change_summary']) ?></td>
                                <td><span class="t8-badge <?= e(t8_contract_status_badge((string) $proposal['status'])) ?>"><?= e(t8_contract_status_label((string) $proposal['status'])) ?></span></td>
                            </tr>
                            <?php if (!empty($proposal['external_meeting_details'])): ?>
                                <tr><td colspan="5"><strong>Meeting request:</strong> <?= nl2br(e((string) $proposal['external_meeting_details'])) ?></td></tr>
                            <?php endif; ?>
                            <?php if ($canManage && $proposal['status'] === 'pending' && $proposal['source'] === 'external'): ?>
                                <tr><td colspan="5" class="t8-help-text">External request recorded. Prepare revised terms above; party requests are not applied directly to the contract.</td></tr>
                            <?php endif; ?>
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
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM team8_contracts c JOIN users u ON u.id = c.owner_id LEFT JOIN departments d ON d.id = c.department_id WHERE {$where}");
$countStmt->execute($params);
$totalPages = max(1, (int) ceil((int) $countStmt->fetchColumn() / T8_CONTRACT_PAGE_SIZE));
$requestedPage = filter_var($_GET['contract_page'] ?? 1, FILTER_VALIDATE_INT);
$currentPage = min(max(1, $requestedPage === false ? 1 : $requestedPage), $totalPages);
$offset = ($currentPage - 1) * T8_CONTRACT_PAGE_SIZE;
$listStmt = $pdo->prepare("SELECT c.*, u.full_name AS owner_name, d.name AS department_name FROM team8_contracts c JOIN users u ON u.id = c.owner_id LEFT JOIN departments d ON d.id = c.department_id WHERE {$where} ORDER BY c.created_at DESC LIMIT " . T8_CONTRACT_PAGE_SIZE . " OFFSET {$offset}");
$listStmt->execute($params);
$contracts = $listStmt->fetchAll(PDO::FETCH_ASSOC);
$counts = array_fill_keys(['total', 'draft', 'under_review', 'negotiation', 'pending_approval', 'pending_signature', 'active', 'expiring'], 0);
$countRows = $pdo->query("SELECT status, COUNT(*) AS n FROM team8_contracts WHERE deleted_at IS NULL AND status <> 'archived' GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
foreach ($countRows as $row) {
    $statusKey = t8_contract_normalize_status((string) $row['status']);
    $counts['total'] += (int) $row['n'];
    if (isset($counts[$statusKey])) {
        $counts[$statusKey] = (int) $row['n'];
    }
}
$monitoringRows = $pdo->query("SELECT status, end_date, renewal_date FROM team8_contracts WHERE deleted_at IS NULL AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
$counts['expiring'] = count(array_filter($monitoringRows, 't8_contract_is_monitoring'));
?>
<div class="t8-card-header" style="display:flex;justify-content:space-between;align-items:center"><div><h1>Contract Management</h1><p class="t8-help-text">Manage contracts throughout their lifecycle.</p></div><?php if ($canManage): ?><div style="display:flex;gap:8px;align-items:center"><a class="t8-btn t8-btn-outline" href="<?= e(page_url('party_registry')) ?>">Parties</a><a class="t8-btn t8-btn-accent" href="<?= e(page_url('contracts', ['action' => 'create'])) ?>"><i class="fa-solid fa-plus"></i> New Contract</a></div><?php endif; ?></div>
<div class="t8-stat-row"><?php foreach (['total' => 'Total', 'draft' => 'Draft', 'under_review' => 'In Review', 'negotiation' => 'Negotiation', 'pending_approval' => 'Pending Approval', 'pending_signature' => 'Pending Signature', 'active' => 'Active', 'expiring' => 'Expiring Soon'] as $key => $label): ?><div class="t8-stat-card <?= $key === 'expiring' ? 't8-stat-warn' : '' ?>"><span><?= e($label) ?></span><strong><?= (int) $counts[$key] ?></strong></div><?php endforeach; ?></div>
<div class="t8-tabs"><a class="t8-tab <?= !$archivedFilter && !$rejectedFilter ? 'is-active' : '' ?>" href="<?= e(page_url('contracts')) ?>">Active</a><a class="t8-tab <?= $archivedFilter ? 'is-active' : '' ?>" href="<?= e(page_url('contracts', ['archived' => 1])) ?>">Archived</a><a class="t8-tab <?= $rejectedFilter ? 'is-active' : '' ?>" href="<?= e(page_url('contracts', ['rejected' => 1])) ?>">Rejected</a></div>
<form id="t8ContractsFilterForm" class="t8-filter-row" data-contract-filter-table="t8ContractsTable"><input type="hidden" name="archived" value="<?= $archivedFilter ? '1' : '0' ?>"><input type="hidden" name="rejected" value="<?= $rejectedFilter ? '1' : '0' ?>"><input type="search" class="t8-input" name="search" data-contract-search value="<?= e($search) ?>" placeholder="Search number, title, or party"><select class="t8-select" name="status" data-contract-status><option value="">All Status</option><?php foreach (T8_CONTRACT_STATUSES as $status): ?><option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(t8_contract_status_label($status)) ?></option><?php endforeach; ?></select><select class="t8-select" name="contract_type" data-contract-type><option value="">All Types</option><?php foreach (T8_CONTRACT_TYPES as $type): ?><option value="<?= e($type) ?>" <?= $typeFilter === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></form>
<div class="t8-table-wrap"><table class="t8-table" id="t8ContractsTable"><thead><tr><th>Contract</th><th>Party / Title</th><th>Period</th><th>Officer</th><th>Status</th><th></th></tr></thead><tbody data-contract-results><?php t8_contract_render_rows($contracts, $isAdmin, $isLegal, $archivedFilter); ?></tbody></table></div>
<div id="t8ContractsPaginationContainer"><?php
$paginationFilters = ['archived' => $archivedFilter ? 1 : 0, 'rejected' => $rejectedFilter ? 1 : 0];
foreach (['search' => $search, 'status' => $statusFilter, 'contract_type' => $typeFilter] as $key => $value) {
    if ($value !== '') {
        $paginationFilters[$key] = $value;
    }
}
t8_contract_render_pagination($currentPage, $totalPages, $paginationFilters);
?></div>
