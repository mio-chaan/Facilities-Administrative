<?php
/** Contract Management v3: registry, draft review, workflow, and party links. */
declare(strict_types=1);

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
}

$action = (string) ($_GET['action'] ?? 'list');
$publicPortalAction = in_array($action, ['portal', 'portal_review'], true);
if (!$publicPortalAction) {
    t8_require_role(['admin', 'legal_officer', 'facilities_staff', 'employee']);
}

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
const T8_CONTRACT_STATUSES = ['draft', 'under_review', 'negotiation', 'pending_approval', 'pending_signature', 'partially_signed', 'fully_executed', 'active', 'renewal', 'expiration', 'termination', 'cancelled', 'superseded', 'archived'];
const T8_CONTRACT_MONITORING_DAYS = 90;
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

function t8_contract_is_monitoring(array $contract, int $withinDays = T8_CONTRACT_MONITORING_DAYS): bool
{
    if (t8_contract_normalize_status((string) ($contract['status'] ?? '')) !== 'active') {
        return false;
    }

    $storedAlert = strtolower(trim((string) ($contract['expiration_alert'] ?? '')));
    if (in_array($storedAlert, ['low', 'medium', 'high', 'critical'], true)) {
        return true;
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

function t8_contract_ensure_portal_invites_table(PDO $pdo): void
{
    try {
        $pdo->query("SHOW TABLES LIKE 'team8_contract_portal_invites'")->fetchColumn();
    } catch (Throwable $e) {
        return;
    }

    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'team8_contract_portal_invites'")->fetchColumn();
        if ($exists !== false) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }

    try {
        $pdo->exec(
            "CREATE TABLE `team8_contract_portal_invites` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `contract_id` int(11) NOT NULL,
                `party_id` int(11) NOT NULL,
                `email` varchar(255) NOT NULL,
                `party_name` varchar(200) DEFAULT NULL,
                `token_hash` varchar(255) NOT NULL,
                `status` enum('pending','accepted','rejected','expired') NOT NULL DEFAULT 'pending',
                `expires_at` datetime DEFAULT NULL,
                `used_at` datetime DEFAULT NULL,
                `created_by` int(11) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_team8_contract_portal_invites_token` (`token_hash`),
                KEY `idx_team8_contract_portal_invites_contract` (`contract_id`,`status`),
                KEY `idx_team8_contract_portal_invites_party` (`party_id`),
                KEY `idx_team8_contract_portal_invites_email` (`email`),
                CONSTRAINT `fk_team8_contractportal_contract` FOREIGN KEY (`contract_id`) REFERENCES `team8_contracts` (`id`),
                CONSTRAINT `fk_team8_contractportal_party` FOREIGN KEY (`party_id`) REFERENCES `team8_parties` (`id`),
                CONSTRAINT `fk_team8_contractportal_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;"
        );
    } catch (Throwable $e) {
        // Optional during partial schema upgrades.
    }
}

function t8_contract_generate_portal_token(): string
{
    return bin2hex(random_bytes(24));
}

function t8_contract_create_portal_invite(PDO $pdo, int $contractId, int $partyId, string $email, ?string $partyName = null, ?int $createdBy = null, ?string $expiresAt = null): ?array
{
    t8_contract_ensure_portal_invites_table($pdo);

    $contract = t8_contract_fetch($pdo, $contractId);
    if ($contract === null) {
        return null;
    }

    $token = t8_contract_generate_portal_token();
    $expires = $expiresAt ?: date('Y-m-d H:i:s', strtotime('+7 days'));
    $status = 'pending';

    $stmt = $pdo->prepare(
        'INSERT INTO team8_contract_portal_invites (contract_id, party_id, email, party_name, token_hash, status, expires_at, created_by) VALUES (:contract_id, :party_id, :email, :party_name, :token_hash, :status, :expires_at, :created_by)'
    );
    $result = $stmt->execute([
        'contract_id' => $contractId,
        'party_id' => $partyId,
        'email' => trim($email),
        'party_name' => $partyName !== null ? trim($partyName) : null,
        'token_hash' => hash('sha256', $token),
        'status' => $status,
        'expires_at' => $expires,
        'created_by' => $createdBy,
    ]);

    if (!$result) {
        return null;
    }

    $inviteId = (int) $pdo->lastInsertId();
    return [
        'id' => $inviteId,
        'contract_id' => $contractId,
        'party_id' => $partyId,
        'email' => trim($email),
        'party_name' => $partyName !== null ? trim($partyName) : null,
        'token' => $token,
        'status' => $status,
        'expires_at' => $expires,
    ];
}

function t8_contract_get_portal_invite(PDO $pdo, string $token): ?array
{
    t8_contract_ensure_portal_invites_table($pdo);
    $cleanToken = trim($token);
    if ($cleanToken === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM team8_contract_portal_invites WHERE token_hash = :token_hash AND status = :status AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1'
    );
    $stmt->execute([
        'token_hash' => hash('sha256', $cleanToken),
        'status' => 'pending',
    ]);
    $invite = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($invite === false) {
        return null;
    }

    $contract = t8_contract_fetch($pdo, (int) $invite['contract_id']);
    if ($contract === null) {
        return null;
    }

    return array_merge($invite, ['token' => $cleanToken, 'contract' => $contract]);
}

function t8_contract_portal_url(string $token): string
{
    return APP_URL . '/index.php?page=contracts&action=portal&token=' . rawurlencode($token);
}

function t8_contract_status_label(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
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

if ($action === 'issue_portal_invite' && $_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
        t8_flash_set('danger', 'Your session expired or the invite request was invalid.');
        redirect(page_url('contracts', ['action' => 'view', 'id' => (int) ($_POST['contract_id'] ?? 0)]));
    }

    $contractId = (int) ($_POST['contract_id'] ?? 0);
    $partyId = (int) ($_POST['party_id'] ?? 0);
    $email = trim((string) ($_POST['email'] ?? ''));
    $partyName = trim((string) ($_POST['party_name'] ?? ''));
    if ($contractId <= 0 || $partyId <= 0 || $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        t8_flash_set('danger', 'A valid party and email are required before issuing a portal invite.');
        redirect(page_url('contracts', ['action' => 'view', 'id' => $contractId]));
    }

    $invite = t8_contract_create_portal_invite($pdo, $contractId, $partyId, $email, $partyName !== '' ? $partyName : null, $currentUserId, date('Y-m-d H:i:s', strtotime('+7 days')));
    if ($invite === null) {
        t8_flash_set('danger', 'The secure contract portal invite could not be created.');
        redirect(page_url('contracts', ['action' => 'view', 'id' => $contractId]));
    }

    t8_contract_event_log($pdo, $contractId, (int) $currentUserId, 'PORTAL_INVITE_ISSUED', 'Secure external portal invite sent to party contact.', ['party_id' => $partyId, 'email' => $email, 'token' => $invite['token']]);
    t8_audit_log($pdo, (int) $currentUserId, 'contract', $contractId, 'portal_invite', '', 'issued');
    t8_flash_set('success', 'A secure portal invite has been issued.');
    redirect(page_url('contracts', ['action' => 'view', 'id' => $contractId]));
}

if ($action === 'portal' || $action === 'portal_review') {
    $token = trim((string) ($_GET['token'] ?? ($_POST['token'] ?? '')));
    $invite = $token !== '' ? t8_contract_get_portal_invite($pdo, $token) : null;

    if ($invite === null) {
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Contract Portal</title></head><body style="font-family:Arial,sans-serif;padding:32px;max-width:720px;margin:0 auto;">';
        echo '<h1>Invalid or expired portal link</h1>';
        echo '<p>This contract invitation is no longer valid. Please contact the contract owner for a fresh access link.</p>';
        echo '</body></html>';
        return;
    }

    $contract = $invite['contract'];
    if ($action === 'portal_review' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your secure session expired. Please try again.');
            redirect(page_url('contracts', ['action' => 'portal', 'token' => $token]));
        }

        $decision = strtolower(trim((string) ($_POST['decision'] ?? '')));
        $comment = trim((string) ($_POST['comment'] ?? ''));
        $allowed = ['accept', 'reject'];
        if (!in_array($decision, $allowed, true)) {
            t8_flash_set('danger', 'Please choose whether to accept or request changes.');
            redirect(page_url('contracts', ['action' => 'portal', 'token' => $token]));
        }

        if ($decision === 'reject') {
            $changeSummary = trim((string) ($_POST['change_summary'] ?? ''));
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
            if ($changeSummary === '') {
                t8_flash_set('danger', 'Add a brief summary of the proposed changes.');
                redirect(page_url('contracts', ['action' => 'portal', 'token' => $token]));
            }
            $proposal = t8_contract_create_negotiation($pdo, (int) $contract['id'], (int) $invite['party_id'], $changeSummary, $proposedData, 'external');
            if ($proposal === null) {
                t8_flash_set('danger', 'The proposed changes could not be saved.');
                redirect(page_url('contracts', ['action' => 'portal', 'token' => $token]));
            }
            $newStatus = 'under_review';
            $eventType = 'NEGOTIATION_STARTED';
            $description = 'External party proposed negotiation changes.';
            $pdo->prepare('UPDATE team8_contract_portal_invites SET status = :status, used_at = NOW() WHERE id = :id')->execute(['status' => 'accepted', 'id' => (int) $invite['id']]);
            t8_contract_event_log($pdo, (int) $contract['id'], 0, $eventType, $changeSummary, ['version_no' => $proposal['version_no'], 'token' => $token]);
            t8_audit_log($pdo, (int) $invite['party_id'], 'contract', (int) $contract['id'], 'negotiation_proposed', (string) $contract['status'], $newStatus);
            t8_flash_set('success', 'Your proposed version was saved and the contract is back in review.');
            redirect(page_url('contracts', ['action' => 'portal', 'token' => $token]));
        }

        $newStatus = 'pending_approval';
        $eventType = 'PARTIES_AGREED';
        $description = 'External party accepted the current contract version.';
        $pdo->prepare('UPDATE team8_contracts SET status = :status WHERE id = :id')->execute(['status' => $newStatus, 'id' => (int) $contract['id']]);
        $pdo->prepare('UPDATE team8_contract_portal_invites SET status = :status, used_at = NOW() WHERE id = :id')->execute(['status' => 'accepted', 'id' => (int) $invite['id']]);
        t8_contract_event_log($pdo, (int) $contract['id'], 0, $eventType, $comment !== '' ? $comment : $description, ['token' => $token, 'decision' => $decision, 'from_status' => (string) $contract['status'], 'to_status' => $newStatus]);
        t8_audit_log($pdo, (int) $invite['party_id'], 'contract', (int) $contract['id'], 'portal_accept', (string) $contract['status'], $newStatus);
        t8_flash_set('success', 'Thank you. The contract has advanced to internal approval.');
        redirect(page_url('contracts', ['action' => 'portal', 'token' => $token]));
    }

    $viewContract = $contract;
    $viewParties = [];
    try {
        $partyStmt = $pdo->prepare(
            'SELECT cp.*, p.name AS party_name, p.type AS party_type, p.primary_contact, p.contact_email
             FROM team8_contract_parties cp
             JOIN team8_parties p ON p.id = cp.party_id
             WHERE cp.contract_id = :contract_id ORDER BY p.name'
        );
        $partyStmt->execute(['contract_id' => (int) $contract['id']]);
        $viewParties = $partyStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $viewParties = [];
    }

    $portalUrl = t8_contract_portal_url($token);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Contract Portal</title><style>body{font-family:Arial,sans-serif;max-width:1100px;margin:0 auto;padding:32px 20px 64px;color:#1f2937;background:#f8fafc}h1,h2,h3{margin:0 0 12px;color:#0f172a}.meta{display:flex;flex-wrap:wrap;gap:12px;margin:16px 0 28px}.pill{display:inline-block;padding:6px 12px;border-radius:999px;background:#e2e8f0;color:#0f172a;font-size:12px;font-weight:700}.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;box-shadow:0 1px 2px rgba(15,23,42,.04)}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px}.label{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:4px}.value{font-size:15px}.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:20px}.btn{display:inline-block;padding:12px 18px;border:0;border-radius:8px;background:#2563eb;color:#fff;text-decoration:none;cursor:pointer;font-weight:700}.btn-danger{background:#dc2626}.btn-success{background:#15803d}.textarea{width:100%;box-sizing:border-box;padding:12px;border:1px solid #cbd5e1;border-radius:8px;min-height:100px;margin-top:10px}.table{width:100%;border-collapse:collapse;margin-top:12px}.table th,.table td{padding:10px 8px;border-bottom:1px solid #e5e7eb;text-align:left}.note{margin-top:16px;padding:12px 14px;border-left:4px solid #0ea5e9;background:#e0f2fe;border-radius:8px}.small{font-size:12px;color:#475569}</style></head><body>';
    echo '<h1>Secure Contract Review Portal</h1>';
    echo '<div class="meta"><span class="pill">' . e((string) $contract['contract_number']) . '</span><span class="pill">' . e(t8_contract_status_label((string) $contract['status'])) . '</span></div>';
    echo '<div class="card"><h2>' . e((string) $contract['title']) . '</h2><p class="small">Access link for ' . e((string) ($invite['party_name'] ?: $invite['email'])) . '</p>';
    echo '<div class="grid"><div><span class="label">Contract Type</span><div class="value">' . e((string) ($contract['contract_type'] ?? '—')) . '</div></div><div><span class="label">Owner</span><div class="value">' . e((string) ($contract['owner_name'] ?? '—')) . '</div></div><div><span class="label">Effective</span><div class="value">' . e((string) ($contract['start_date'] ?? '—')) . ' to ' . e((string) ($contract['end_date'] ?? '—')) . '</div></div><div><span class="label">Value</span><div class="value">' . e((string) ($contract['currency'] ?? 'PHP')) . ' ' . e((string) ($contract['amount'] ?? '—')) . '</div></div></div>';
    echo '<div class="note">Please review the current contract version below. You may accept the terms or request changes before approval continues.</div>';
    echo '<div style="margin-top:16px;"><h3>Contract Summary</h3><p>' . nl2br(e((string) ($contract['description'] ?? 'No description provided.'))) . '</p></div>';
    echo '<div style="margin-top:16px;"><h3>Related Parties</h3>';
    if ($viewParties === []) {
        echo '<p class="small">No parties are assigned to this contract.</p>';
    } else {
        echo '<table class="table"><thead><tr><th>Name</th><th>Type</th><th>Role</th></tr></thead><tbody>';
        foreach ($viewParties as $party) {
            echo '<tr><td>' . e((string) $party['party_name']) . '</td><td>' . e((string) $party['party_type']) . '</td><td>' . e((string) $party['role_in_contract']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
    echo '<div style="margin-top:24px;">';
    echo '<h3>Your decision</h3>';
    echo '<form method="post" action="' . e($portalUrl) . '">' . t8_csrf_field() . '<input type="hidden" name="token" value="' . e($token) . '"><textarea class="textarea" name="comment" placeholder="Add comments, requested changes, or notes for the counterparty."></textarea><div class="actions"><button type="submit" name="decision" value="accept" class="btn btn-success">I agree to this version</button><button type="submit" name="decision" value="reject" class="btn btn-danger">Request changes</button></div></form>';
    echo '<details style="margin-top:18px;"><summary class="btn btn-danger">Submit proposed changes</summary><form method="post" action="' . e($portalUrl) . '">' . t8_csrf_field() . '<input type="hidden" name="token" value="' . e($token) . '"><input type="hidden" name="decision" value="reject"><div class="grid" style="margin-top:12px"><div><label class="small">Change summary</label><input class="textarea" name="change_summary" required placeholder="Briefly describe the changes"></div><div><label class="small">Payment terms</label><input class="textarea" name="payment_terms" value="' . e((string) ($contract['payment_terms'] ?? '')) . '"></div><div><label class="small">Amount</label><input class="textarea" name="amount" type="number" min="0" step="0.01" value="' . e((string) ($contract['amount'] ?? '')) . '"></div><div><label class="small">Currency</label><input class="textarea" name="currency" value="' . e((string) ($contract['currency'] ?? 'PHP')) . '"></div><div><label class="small">Description</label><textarea class="textarea" name="description">' . e((string) ($contract['description'] ?? '')) . '</textarea></div><div><label class="small">Special conditions</label><textarea class="textarea" name="financial_notes">' . e((string) ($contract['financial_notes'] ?? '')) . '</textarea></div></div><div class="actions"><button type="submit" class="btn btn-danger">Save proposed version</button></div></form></details>';
    echo '</div></div>';
    echo '</body></html>';
    return;
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
        'submit' => ['draft', 'under_review'],
        'approve' => ['under_review', 'pending_approval'],
        'request_changes' => ['under_review', 'negotiation'],
        'propose_changes' => ['under_review', 'under_review'],
        'accept_negotiation' => ['under_review', 'under_review'],
        'return_to_review' => ['negotiation', 'under_review'],
        'sign' => ['pending_approval', 'pending_signature'],
        'activate' => ['pending_signature', 'active'],
        'fully_execute' => ['pending_signature', 'fully_executed'],
        'renewal' => ['active', 'renewal'],
        'cancel' => ['draft', 'cancelled'],
        'supersede' => ['active', 'superseded'],
        'terminate' => ['active', 'termination'],
        'expire' => ['active', 'expiration'],
        'reject' => ['under_review', 'archived'],
    ];
    $normalizedStatus = t8_contract_normalize_status((string) ($contract['status'] ?? ''));
    if ($workflow === 'cancel') {
        if (!$isAdmin || !in_array($normalizedStatus, ['draft', 'under_review', 'negotiation', 'pending_approval', 'pending_signature'], true)) {
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
        $proposal = t8_contract_create_negotiation($pdo, $id, $currentUserId, $changeSummary, $proposedData, 'internal');
        if ($proposal === null) {
            t8_flash_set('danger', 'The proposed changes could not be saved.');
            redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
        }
        t8_contract_event_log($pdo, $id, $currentUserId, 'NEGOTIATION_STARTED', $changeSummary, ['version_no' => $proposal['version_no']]);
        t8_audit_log($pdo, $currentUserId, 'contract', $id, 'negotiation_proposed', (string) $contract['status'], 'under_review');
        t8_flash_set('success', 'Proposed version ' . $proposal['version_no'] . ' was saved for review.');
        redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
    }
    if ($workflow === 'accept_negotiation') {
        if (!$isAdmin && !$isLegal) {
            t8_flash_set('danger', 'Only an authorized internal reviewer may accept a proposed version.');
            redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
        }
        $proposalId = (int) ($_POST['proposal_id'] ?? 0);
        if (!t8_contract_accept_negotiation($pdo, $id, $proposalId, $currentUserId)) {
            t8_flash_set('danger', 'The proposed version could not be accepted.');
            redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
        }
        t8_contract_event_log($pdo, $id, $currentUserId, 'NEGOTIATION_ACCEPTED', 'Authorized internal reviewer accepted a proposed contract version.', ['proposal_id' => $proposalId]);
        t8_audit_log($pdo, $currentUserId, 'contract', $id, 'negotiation_accepted', (string) $contract['status'], 'under_review');
        t8_flash_set('success', 'Proposed version accepted. The contract remains in review until internal approval.');
        redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
    }
    if (!$contract || !isset($transitions[$workflow]) || $normalizedStatus !== $transitions[$workflow][0]
        || (in_array($workflow, ['sign', 'activate'], true) && !$isAdmin)) {
        t8_flash_set('danger', 'That contract transition is not allowed.');
        redirect(page_url('contracts'));
    }
    $newStatus = $transitions[$workflow][1];
    if ($workflow === 'renewal') {
        $renewedContract = t8_contract_create_renewal($pdo, $contract, $currentUserId);
        if ($renewedContract === null) {
            t8_flash_set('danger', 'A renewal draft could not be created from this contract.');
            redirect(page_url('contracts', ['action' => 'view', 'id' => $id]));
        }
        t8_flash_set('success', 'A renewal draft was created and the original contract was marked for renewal.');
        redirect(page_url('contracts', ['action' => 'view', 'id' => (int) $renewedContract['id']]));
    }
    if ($workflow === 'terminate') {
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
        'approve' => 'Contract approved and moved to the approval pipeline.',
        'request_changes' => 'Contract review requested changes and has returned to negotiation.',
        'return_to_review' => 'Contract was sent back to review for updated terms.',
        'sign' => 'Contract is ready for signature and external execution.',
        'fully_execute' => 'Contract was fully executed and is ready for activation.',
        'activate' => 'Contract is now active and in force.',
        'renewal' => 'Contract renewal review has been initiated.',
        'terminate' => 'Contract termination has been recorded.',
        'expire' => 'Contract expiration has been recorded.',
        'reject' => 'Contract was rejected and moved to archive.',
        default => 'Contract lifecycle updated.'
    };
    t8_contract_event_log($pdo, $id, $currentUserId, $workflow, $comment !== '' ? $comment : ucfirst(str_replace('_', ' ', $workflow)), ['from_status' => (string) $contract['status'], 'to_status' => $newStatus]);
    t8_contract_send_status_notification($pdo, $id, $currentUserId, $workflow, $notificationMessage);
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
        <?php foreach (['Draft', 'Under Review', 'Pending Approval', 'Pending Signature', 'Active', 'Renewal / Expiration / Termination'] as $stepIndex => $stepLabel): ?>
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
    if (!$canManage || !$contract || t8_contract_normalize_status((string) $contract['status']) !== 'draft') {
        t8_flash_set('danger', 'Only a draft can be submitted for review.'); redirect(page_url('contracts'));
    }
    $pdo->prepare("UPDATE team8_contracts SET status = 'under_review' WHERE id = :id")->execute(['id' => $id]);
    t8_contract_event_log($pdo, $id, $currentUserId, 'CONTRACT_SUBMITTED', 'Contract submitted for review.', ['from_status' => 'draft', 'to_status' => 'under_review']);
    t8_audit_log($pdo, $currentUserId, 'contract', $id, 'submit', 'draft', 'under_review');
    t8_flash_set('success', 'Contract submitted for review.');
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
    $stages = ['draft', 'under_review', 'negotiation', 'pending_approval', 'pending_signature', 'fully_executed', 'active', 'renewal'];
    $normalizedStatus = t8_contract_normalize_status((string) $viewContract['status']);
    $currentStageIndex = array_search($normalizedStatus, $stages, true);
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
                <?php if (!empty($viewParties)): ?>
                    <form method="post" action="<?= e(page_url('contracts', ['action' => 'issue_portal_invite'])) ?>" style="margin-top: 24px; border-top: 1px solid #eee; padding-top: 16px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                        <?= t8_csrf_field() ?><input type="hidden" name="contract_id" value="<?= (int) $viewId ?>">
                        <select class="t8-select" name="party_id" required>
                            <option value="">Select party to invite</option>
                            <?php foreach ($viewParties as $party): ?>
                                <option value="<?= (int) $party['party_id'] ?>"><?= e((string) $party['party_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input class="t8-input" type="email" name="email" placeholder="Party email" required>
                        <input class="t8-input" type="text" name="party_name" placeholder="Optional party name">
                        <button class="t8-btn t8-btn-outline" type="submit">Send secure portal invite</button>
                    </form>
                <?php endif; ?>
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
                        <?php if ($isAdmin): ?>
                            <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>" style="margin: 0;">
                                <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= $viewId ?>"><input type="hidden" name="workflow_action" value="approve">
                                <button class="t8-btn t8-btn-success">Approve for Contracting</button>
                            </form>
                        <?php endif; ?>
                        <details style="margin-top: 8px;">
                            <summary class="t8-link-btn">Propose Contract Changes</summary>
                            <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>" style="margin-top: 12px; display: grid; gap: 8px; max-width: 720px;">
                                <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= $viewId ?>"><input type="hidden" name="workflow_action" value="propose_changes">
                                <input class="t8-input" name="change_summary" placeholder="Change summary" required>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px;">
                                    <input class="t8-input" name="title" value="<?= e((string) $viewContract['title']) ?>" placeholder="Title">
                                    <input class="t8-input" name="contract_type" value="<?= e((string) ($viewContract['contract_type'] ?? '')) ?>" placeholder="Contract type">
                                    <input class="t8-input" name="amount" type="number" min="0" step="0.01" value="<?= e((string) $viewContract['amount']) ?>" placeholder="Amount">
                                    <input class="t8-input" name="currency" value="<?= e((string) $viewContract['currency']) ?>" placeholder="Currency">
                                    <input class="t8-input" name="payment_terms" value="<?= e((string) $viewContract['payment_terms']) ?>" placeholder="Payment terms">
                                    <input class="t8-input" name="payment_frequency" value="<?= e((string) $viewContract['payment_frequency']) ?>" placeholder="Payment frequency">
                                    <input class="t8-input" name="deposit_amount" type="number" min="0" step="0.01" value="<?= e((string) $viewContract['deposit_amount']) ?>" placeholder="Deposit">
                                    <input class="t8-input" name="notice_period_days" type="number" min="0" value="<?= e((string) $viewContract['notice_period_days']) ?>" placeholder="Notice period (days)">
                                </div>
                                <textarea class="t8-input" name="description" rows="3" placeholder="Contract description"><?= e((string) $viewContract['description']) ?></textarea>
                                <textarea class="t8-input" name="financial_notes" rows="3" placeholder="Special conditions"><?= e((string) $viewContract['financial_notes']) ?></textarea>
                                <button class="t8-btn t8-btn-outline" type="submit">Save Proposed Version</button>
                            </form>
                        </details>
                    <?php endif; ?>

                    <?php if ($isAdmin && t8_contract_normalize_status((string) $viewContract['status']) === 'pending_approval'): ?>
                        <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>" style="margin: 0;">
                            <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= $viewId ?>"><input type="hidden" name="workflow_action" value="sign">
                            <button class="t8-btn t8-btn-success">Approve to Signature</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($isAdmin && t8_contract_normalize_status((string) $viewContract['status']) === 'pending_signature'): ?>
                        <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>" style="margin: 0;">
                            <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= $viewId ?>"><input type="hidden" name="workflow_action" value="fully_execute">
                            <button class="t8-btn t8-btn-success">Complete Execution</button>
                        </form>
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
                            <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= $viewId ?>"><input type="hidden" name="workflow_action" value="supersede">
                            <button class="t8-btn t8-btn-outline">Mark Superseded</button>
                        </form>
                        <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>" style="margin: 0;">
                            <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= $viewId ?>"><input type="hidden" name="workflow_action" value="terminate">
                            <button class="t8-btn t8-btn-danger">Terminate</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($isAdmin && in_array(t8_contract_normalize_status((string) $viewContract['status']), ['draft', 'under_review', 'negotiation', 'pending_approval', 'pending_signature'], true)): ?>
                        <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>" style="margin: 0;">
                            <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= $viewId ?>"><input type="hidden" name="workflow_action" value="cancel">
                            <button class="t8-btn t8-btn-danger">Cancel</button>
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
            $negotiations = [];
            try {
                $st = $pdo->prepare('SELECT h.*, u.full_name FROM team8_contract_history h JOIN users u ON u.id = h.changed_by WHERE h.contract_id = :id ORDER BY h.version_no DESC LIMIT 25');
                $st->execute(['id' => $viewId]);
                $history = $st->fetchAll(PDO::FETCH_ASSOC);
                $proposalStmt = $pdo->prepare('SELECT n.*, u.full_name AS proposed_by_name FROM team8_contract_negotiations n JOIN users u ON u.id = n.proposed_by WHERE n.contract_id = :id ORDER BY n.created_at DESC');
                $proposalStmt->execute(['id' => $viewId]);
                $negotiations = $proposalStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
            }
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
                            <?php if ($canManage && $proposal['status'] === 'pending'): ?>
                                <tr>
                                    <td colspan="5">
                                        <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                            <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= $viewId ?>"><input type="hidden" name="workflow_action" value="accept_negotiation"><input type="hidden" name="proposal_id" value="<?= (int) $proposal['id'] ?>">
                                            <button class="t8-btn t8-btn-success t8-btn-sm">Accept Proposed Version</button>
                                            <span class="t8-help-text">The contract remains in review until internal approval.</span>
                                        </form>
                                    </td>
                                </tr>
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
