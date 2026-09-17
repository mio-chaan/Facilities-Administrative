<?php
/**
 * modules/contracts/index.php
 * Contract Management - administrators manage contracts; staff have scoped,
 * read-only access plus supporting-document submission.
 *
 * PHASE 2 (Document/Legal/Contract/Retention rebuild) — lifecycle v2:
 *   draft -> review -> negotiation -> approval -> signing -> active
 *     -> renewal_or_amendment -> expiration_or_termination -> archived
 * See T8_CONTRACT_STATUSES below for the full rationale and the
 * "monitoring" computed sub-state that replaces the old stored
 * 'expiring_soon' status. See
 * database/migrations/2026_09_11_contract_lifecycle_v2.sql for the
 * one-time status remapping applied to existing rows.
 *
 * Backing tables:
 *   team8_contracts            (id, owner_id, renewed_from_id, title,
 *     start_date, end_date, status, created_at, updated_at, deleted_at)
 *   team8_contract_parties     (id, contract_id, party_id, role_in_contract, created_at)
 *   team8_contract_obligations (id, contract_id, description, due_date, status, created_at, updated_at)
 *   team8_contract_documents   (id, contract_id, document_id, created_at)
 *   team8_parties              (id, name, type, contact_email, contact_phone, created_at, updated_at)
 *     - shared party directory; a party can appear on multiple contracts.
 *   team8_records              (polymorphic retention table - see
 *     app/includes/retention_helpers.php - Phase 1 of this rebuild)
 *
 * team8_contract_obligations has no deleted_at column, so obligations
 * are hard-deleted when removed (nothing else references them).
 * Contracts/parties links use the same attach/detach pattern as
 * Legal Management's document attachments.
 *
 * Access: administrators manage contracts; other authorized roles can view
 * their scoped contracts and submit supporting documents.
 */

declare(strict_types=1);

// Retention registration hook (Phase 1/2 of the Document/Legal/Contract/
// Retention rebuild) - defensive require, safe even if already loaded
// centrally elsewhere, same pattern as ai_helper.php's require in
// modules/documents/index.php.
$retentionHelperPath = __DIR__ . '/../../app/includes/retention_helpers.php';
if (is_file($retentionHelperPath)) {
    require_once $retentionHelperPath;
}

t8_require_role(['admin', 'legal_officer', 'facilities_staff', 'employee']);

$pageTitle = 'Contract Management';
$currentUserId = t8_current_user_id();
$isAdmin = t8_has_role('admin');
$action = $_GET['action'] ?? 'list';
$errors = [];

if (!defined('T8_CONTRACT_STATUSES')) {
    // Lifecycle v2 (Phase 2 rebuild). Order here IS the stepper order
    // rendered by t8_contract_render_lifecycle_stepper() below, so
    // don't reorder without checking that function's assumptions.
    //
    //   draft -> review -> negotiation -> approval -> signing -> active
    //     -> renewal_or_amendment -> expiration_or_termination -> archived
    //
    // "monitoring" is NOT a stored status - it is a computed sub-state
    // of 'active' (see t8_contract_is_monitoring()), shown as an extra
    // badge alongside the real status. This replaces the old, separately
    // stored 'expiring_soon' status, which could silently drift out of
    // sync with the contract's actual dates if the sweep below didn't
    // run at the right moment. A computed value can never drift.
    define('T8_CONTRACT_STATUSES', ['draft', 'review', 'negotiation', 'approval', 'signing', 'active', 'renewal_or_amendment', 'expiration_or_termination', 'archived']);
}
if (!defined('T8_OBLIGATION_STATUSES')) {
    define('T8_OBLIGATION_STATUSES', ['pending', 'completed']);
}
if (!defined('T8_CONTRACT_CURRENCIES')) {
    define('T8_CONTRACT_CURRENCIES', ['PHP', 'USD', 'JPY', 'KRW', 'EUR']);
}
if (!defined('T8_CONTRACT_TYPES')) {
    define('T8_CONTRACT_TYPES', ['Service Agreement', 'Supplier/Vendor Agreement', 'Employment Contract', 'Lease Agreement', 'Partnership Agreement', 'Non-Disclosure Agreement', 'Maintenance Agreement', 'Purchase Agreement', 'Other']);
}
if (!defined('T8_CONTRACT_PAYMENT_FREQUENCIES')) {
    define('T8_CONTRACT_PAYMENT_FREQUENCIES', ['One-time', 'Monthly', 'Quarterly', 'Semi-annually', 'Annually', 'Per milestone', 'Other']);
}

/** Keep the module usable while its additive metadata migration is pending. */
function t8_contract_has_metadata(PDO $pdo): bool
{
    try {
        return (bool) $pdo->query("SHOW COLUMNS FROM team8_contracts LIKE 'department_id'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function t8_contract_has_column(PDO $pdo, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM team8_contracts LIKE :column");
        $stmt->execute(['column' => $column]);
        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function t8_contract_next_number(PDO $pdo): string
{
    $year = date('Y');
    $nextId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM team8_contracts')->fetchColumn();
    return 'CON-' . $year . '-' . str_pad((string) $nextId, 6, '0', STR_PAD_LEFT);
}

function t8_contract_upload(array $file, string $title, int $version): array
{
    $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'png', 'jpg', 'jpeg'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Please choose a valid contract document.');
    }
    if (($file['size'] ?? 0) > UPLOAD_MAX_SIZE_MB * 1024 * 1024) {
        throw new RuntimeException('The contract document is too large.');
    }
    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed, true)) {
        throw new RuntimeException('This document type is not allowed.');
    }
    $directory = UPLOAD_DIR . '/documents';
    if (!is_dir($directory)) { mkdir($directory, 0755, true); }
    $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $title), '-')) ?: 'contract';
    $filename = $slug . '_v' . $version . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $destination = $directory . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('The contract document could not be stored.');
    }
    return ['file_path' => 'documents/' . $filename, 'file_size' => (int) $file['size'], 'checksum' => hash_file('sha256', $destination) ?: null];
}

$contractHasMetadata = t8_contract_has_metadata($pdo);

function t8_contract_fetch(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT c.*, u.full_name AS owner_name, r.title AS renewed_from_title
         FROM team8_contracts c
         JOIN users u ON u.id = c.owner_id
         LEFT JOIN team8_contracts r ON r.id = c.renewed_from_id
         WHERE c.id = :id' . (t8_has_role('admin') || t8_has_role('legal_officer') ? '' : ' AND c.owner_id = :user_id') . ' LIMIT 1'
    );
    $params = ['id' => $id];
    if (!t8_has_role('admin') && !t8_has_role('legal_officer')) { $params += ['user_id' => t8_current_user_id()]; }
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function t8_contract_save_history(PDO $pdo, int $contractId, array $data, int $userId): void
{
    try {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(version_no), 0) + 1 FROM team8_contract_history WHERE contract_id = :id');
        $stmt->execute(['id' => $contractId]);
        $pdo->prepare('INSERT INTO team8_contract_history (contract_id, version_no, data_json, changed_by) VALUES (:id, :version_no, :data, :user_id)')
            ->execute(['id' => $contractId, 'version_no' => (int) $stmt->fetchColumn(), 'data' => json_encode($data, JSON_THROW_ON_ERROR), 'user_id' => $userId]);
    } catch (PDOException $e) {
        // The history table is created by the same additive migration.
    }
}

function t8_contract_status_badge(string $status): string
{
    $map = [
        'draft'                     => 't8-badge-pending',
        'review'                    => 't8-badge-pending',
        'negotiation'               => 't8-badge-pending',
        'approval'                  => 't8-badge-pending',
        'signing'                   => 't8-badge-pending',
        'active'                    => 't8-badge-approved',
        'renewal_or_amendment'      => 't8-badge-pending',
        'expiration_or_termination' => 't8-badge-rejected',
        'archived'                  => 't8-badge-archived',
    ];
    return $map[$status] ?? 't8-badge-pending';
}

/**
 * MONITORING (computed, not stored - see T8_CONTRACT_STATUSES docblock).
 * True when an ACTIVE contract is within $withinDays of its own end
 * date, its termination date (if already set), its renewal date, or
 * any still-pending obligation's due date - whichever comes soonest.
 * Mirrors the same "compute a display sub-state from real dates
 * instead of storing a second status" pattern already used by
 * t8_reservation_display_status() in app/includes/reservation_helpers.php.
 */
function t8_contract_is_monitoring(PDO $pdo, array $contract, int $withinDays = 90): bool
{
    if (($contract['status'] ?? '') !== 'active') {
        return false;
    }

    $horizon = strtotime("+{$withinDays} days");
    foreach (['end_date', 'termination_date', 'renewal_date'] as $field) {
        $value = $contract[$field] ?? null;
        if ($value === null || $value === '') {
            continue;
        }
        $ts = strtotime((string) $value);
        if ($ts !== false && $ts <= $horizon) {
            return true;
        }
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM team8_contract_obligations
         WHERE contract_id = :id AND status = 'pending' AND due_date IS NOT NULL AND due_date <= :horizon"
    );
    $stmt->execute(['id' => (int) $contract['id'], 'horizon' => date('Y-m-d', $horizon)]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Compact horizontal stepper showing where a contract currently sits
 * across the 9-stage lifecycle. Purely presentational - reads
 * T8_CONTRACT_STATUSES for the stage order so it can never drift out
 * of sync with the actual status list.
 */
function t8_contract_render_lifecycle_stepper(string $currentStatus): void
{
    $stages = T8_CONTRACT_STATUSES;
    $currentIndex = array_search($currentStatus, $stages, true);
    if ($currentIndex === false) {
        $currentIndex = 0;
    }
    ?>
    <ol class="t8-contract-stepper" aria-label="Contract lifecycle progress">
        <?php foreach ($stages as $index => $stage): ?>
            <?php
            $state = $index < $currentIndex ? 'done' : ($index === $currentIndex ? 'current' : 'upcoming');
            $label = ucwords(str_replace('_', ' ', $stage));
            ?>
            <li class="t8-contract-step t8-contract-step-<?= e($state) ?>">
                <span class="t8-contract-step-dot" aria-hidden="true"></span>
                <span class="t8-contract-step-label"><?= e($label) ?></span>
            </li>
        <?php endforeach; ?>
    </ol>
    <?php
}

/**
 * Registers a terminal-status contract under retention EXACTLY ONCE.
 * Deliberately checks t8_retention_fetch_for_entity() first and does
 * nothing if a retention row already exists, so this can be safely
 * called from both the manual workflow action and the automatic
 * expiry sweep without ever clobbering a records officer's later
 * manual adjustment to retention_years/basis on that same contract.
 */
function t8_contract_register_retention(PDO $pdo, array $contract, int $actorId): void
{
    if (!function_exists('t8_retention_register') || !function_exists('t8_retention_fetch_for_entity')) {
        return; // retention_helpers.php not present yet (Phase 1 not deployed)
    }
    if (t8_retention_fetch_for_entity($pdo, 'contract', (int) $contract['id']) !== null) {
        return; // already registered - never overwrite a manual override
    }

    $clockStart = $contract['termination_date'] ?: $contract['end_date'] ?: date('Y-m-d');
    t8_retention_register(
        $pdo,
        'contract',
        (int) $contract['id'],
        'Civil Code Art. 1144 - written contract (10-year prescriptive period)',
        10,
        (string) $clockStart,
        $actorId
    );
    t8_audit_log($pdo, $actorId, 'contract', (int) $contract['id'], 'retention_registered');
}

/**
 * Renders the meatball trigger + dropdown menu used by the "Contracts"
 * list table, plus the data-* attributes consumed by the shared
 * #t8ContractDetailModal (see row markup near the bottom of this file
 * and public/js/row-menu.js). Same pattern as
 * t8_reservation_render_menu() in modules/reservation/index.php.
 */
function t8_contract_render_menu(array $c, bool $isAdmin, bool $archivedFilter, bool $isMonitoring, ?array $retentionRecord): void
{
    $id = (int) $c['id'];
    $ref = (string) ($c['contract_number'] ?? ('CON-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT)));
    ?>
    <div class="t8-row-menu">
        <button type="button" class="t8-row-menu-trigger" aria-haspopup="true" aria-expanded="false" title="More actions"
                data-detail-modal="t8ContractDetailModal"
                data-ref="<?= e($ref) ?>"
                data-title="<?= e((string) $c['title']) ?>"
                data-contract-type="<?= e((string) ($c['contract_type'] ?? '')) ?>"
                data-description="<?= e((string) ($c['description'] ?? '')) ?>"
                data-department="<?= e((string) ($c['department_name'] ?? '—')) ?>"
                data-owner="<?= e((string) $c['owner_name']) ?>"
                data-status="<?= e(ucwords(str_replace('_', ' ', (string) $c['status']))) ?><?= $isMonitoring ? ' · Monitoring' : '' ?>"
                data-start="<?= e(format_date((string) $c['start_date'], 'M d, Y')) ?>"
                data-end="<?= e($c['end_date'] ? format_date((string) $c['end_date'], 'M d, Y') : '—') ?>"
                data-renewal="<?= e((string) ($c['renewal_date'] ?? '') !== '' ? format_date((string) $c['renewal_date'], 'M d, Y') : '') ?>"
                data-amount="<?= e($c['amount'] !== null ? (string) ($c['currency'] ?? 'PHP') . ' ' . number_format((float) $c['amount'], 2) : '') ?>"
                data-payment-frequency="<?= e((string) ($c['payment_frequency'] ?? '')) ?>"
                data-payment-terms="<?= e((string) ($c['payment_terms'] ?? '')) ?>"
                data-deposit="<?= e($c['deposit_amount'] !== null ? number_format((float) $c['deposit_amount'], 2) : '') ?>"
                data-notice-period="<?= e($c['notice_period_days'] !== null ? (string) $c['notice_period_days'] . ' days' : '') ?>"
                data-additional-notes="<?= e((string) ($c['financial_notes'] ?? '')) ?>"
                data-renewed-from="<?= e((string) ($c['renewed_from_title'] ?? '')) ?>">
            <i class="fa-solid fa-ellipsis-vertical"></i>
        </button>
        <div class="t8-row-menu-panel" role="menu">
            <button type="button" class="t8-row-menu-item t8-row-view-details" role="menuitem">
                <i class="fa-solid fa-eye"></i> View Details
            </button>
            <button type="button" class="t8-row-menu-item t8-row-copy-ref" role="menuitem" data-copy="<?= e($ref) ?>">
                <i class="fa-solid fa-copy"></i> Copy Contract Ref
            </button>
            <div class="t8-row-menu-divider"></div>
            <?php if ($isAdmin): ?>
                <a class="t8-row-menu-item" role="menuitem" href="<?= e(page_url('contracts', ['action' => 'parties', 'id' => $id])) ?>">
                    <i class="fa-solid fa-users"></i> Parties
                </a>
                <a class="t8-row-menu-item" role="menuitem" href="<?= e(page_url('contracts', ['action' => 'obligations', 'id' => $id])) ?>">
                    <i class="fa-solid fa-list-check"></i> Obligations
                </a>
            <?php endif; ?>
            <a class="t8-row-menu-item" role="menuitem" href="<?= e(page_url('contracts', ['action' => 'documents', 'id' => $id])) ?>">
                <i class="fa-solid fa-paperclip"></i> Documents
            </a>
            <?php if ($retentionRecord !== null): ?>
                <a class="t8-row-menu-item" role="menuitem" href="<?= e(page_url('retention', ['action' => 'view', 'id' => $retentionRecord['id']])) ?>">
                    <i class="fa-solid fa-box-archive"></i> View Retention Record
                </a>
            <?php endif; ?>
            <?php if ($isAdmin && !$archivedFilter): ?>
                <div class="t8-row-menu-divider"></div>
                <?php if (in_array((string) $c['status'], ['draft', 'negotiation'], true)): ?>
                    <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>">
                        <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $id) ?>"><input type="hidden" name="workflow_action" value="submit">
                        <button class="t8-row-menu-item" type="submit" role="menuitem"><i class="fa-solid fa-paper-plane"></i> Submit for Review</button>
                    </form>
                <?php elseif ((string) $c['status'] === 'review'): ?>
                    <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>">
                        <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $id) ?>"><input type="hidden" name="workflow_action" value="approve">
                        <button class="t8-row-menu-item t8-success" type="submit" role="menuitem"><i class="fa-solid fa-check"></i> Move to Approval</button>
                    </form>
                    <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>">
                        <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $id) ?>"><input type="hidden" name="workflow_action" value="request_changes"><input class="t8-input" type="text" name="comment" placeholder="Comment (optional)">
                        <button class="t8-row-menu-item" type="submit" role="menuitem"><i class="fa-solid fa-comment-dots"></i> Send Back to Negotiation</button>
                    </form>
                <?php elseif ((string) $c['status'] === 'approval'): ?>
                    <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>">
                        <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $id) ?>"><input type="hidden" name="workflow_action" value="sign">
                        <button class="t8-row-menu-item t8-success" type="submit" role="menuitem"><i class="fa-solid fa-signature"></i> Move to Signing</button>
                    </form>
                <?php elseif ((string) $c['status'] === 'signing'): ?>
                    <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>">
                        <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $id) ?>"><input type="hidden" name="workflow_action" value="activate">
                        <button class="t8-row-menu-item t8-success" type="submit" role="menuitem"><i class="fa-solid fa-bolt"></i> Mark Active</button>
                    </form>
                <?php elseif ((string) $c['status'] === 'active'): ?>
                    <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>">
                        <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $id) ?>"><input type="hidden" name="workflow_action" value="renew">
                        <button class="t8-row-menu-item" type="submit" role="menuitem"><i class="fa-solid fa-rotate"></i> Start Renewal / Amendment</button>
                    </form>
                    <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>" onsubmit="return confirm('Terminate this contract? This moves it to Expiration/Termination.');">
                        <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $id) ?>"><input type="hidden" name="workflow_action" value="terminate">
                        <button class="t8-row-menu-item t8-danger" type="submit" role="menuitem"><i class="fa-solid fa-ban"></i> Terminate</button>
                    </form>
                <?php elseif ((string) $c['status'] === 'renewal_or_amendment'): ?>
                    <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>">
                        <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $id) ?>"><input type="hidden" name="workflow_action" value="activate">
                        <button class="t8-row-menu-item t8-success" type="submit" role="menuitem"><i class="fa-solid fa-bolt"></i> Return to Active</button>
                    </form>
                    <form method="post" action="<?= e(page_url('contracts', ['action' => 'workflow'])) ?>" onsubmit="return confirm('Terminate this contract? This moves it to Expiration/Termination.');">
                        <?= t8_csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $id) ?>"><input type="hidden" name="workflow_action" value="terminate">
                        <button class="t8-row-menu-item t8-danger" type="submit" role="menuitem"><i class="fa-solid fa-ban"></i> Terminate</button>
                    </form>
                <?php endif; ?>
                <a class="t8-row-menu-item" role="menuitem" href="<?= e(page_url('contracts', ['action' => 'edit', 'id' => $id])) ?>">
                    <i class="fa-solid fa-pen"></i> Edit
                </a>
                <form method="post" action="<?= e(page_url('contracts', ['action' => 'archive'])) ?>" onsubmit="return confirm('Archive this contract?');">
                    <?= t8_csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <button class="t8-row-menu-item t8-danger" type="submit" role="menuitem">
                        <i class="fa-solid fa-box-archive"></i> Archive
                    </button>
                </form>
            <?php elseif ($isAdmin): ?>
                <div class="t8-row-menu-divider"></div>
                <form method="post" action="<?= e(page_url('contracts', ['action' => 'restore'])) ?>">
                    <?= t8_csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <button class="t8-row-menu-item t8-success" type="submit" role="menuitem">
                        <i class="fa-solid fa-rotate-left"></i> Restore
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

if (defined('T8_CONTRACTS_AJAX_FILTER')) {
    header('Content-Type: application/json');

    $archivedFilter = ($_GET['archived'] ?? '0') === '1';
    $search = trim((string) ($_GET['search'] ?? ''));
    $statusFilter = trim((string) ($_GET['status'] ?? ''));
    $typeFilter = trim((string) ($_GET['contract_type'] ?? ''));
    $where = $archivedFilter ? 'c.deleted_at IS NOT NULL' : 'c.deleted_at IS NULL';
    $listParams = [];
    if ($search !== '') {
        $where .= ' AND (c.contract_number LIKE :search_number OR c.title LIKE :search_title OR c.description LIKE :search_description OR EXISTS (SELECT 1 FROM team8_contract_parties cp JOIN team8_parties p ON p.id = cp.party_id WHERE cp.contract_id = c.id AND p.name LIKE :party_search))';
        $searchTerm = '%' . $search . '%';
        $listParams['search_number'] = $searchTerm;
        $listParams['search_title'] = $searchTerm;
        $listParams['search_description'] = $searchTerm;
        $listParams['party_search'] = '%' . $search . '%';
    }
    if ($statusFilter !== '' && in_array($statusFilter, T8_CONTRACT_STATUSES, true)) {
        $where .= ' AND c.status = :status';
        $listParams['status'] = $statusFilter;
    }
    if ($typeFilter !== '') {
        $where .= ' AND c.contract_type = :contract_type';
        $listParams['contract_type'] = $typeFilter;
    }
    $scope = $isAdmin ? '' : ($contractHasMetadata ? ' AND (c.owner_id = :user_id OR c.department_id = :department_id)' : ' AND c.owner_id = :user_id');
    if (!$isAdmin) {
        $listParams += $contractHasMetadata
            ? ['user_id' => $currentUserId, 'department_id' => $_SESSION['department_id'] ?? 0]
            : ['user_id' => $currentUserId];
    }
    $fromSql = ' FROM team8_contracts c JOIN users u ON u.id = c.owner_id ' . ($contractHasMetadata ? 'LEFT JOIN departments d ON d.id = c.department_id ' : '');
    $countStmt = $pdo->prepare('SELECT COUNT(*)' . $fromSql . " WHERE $where$scope");
    $countStmt->execute($listParams);
    $total = (int) $countStmt->fetchColumn();
    $contractsStmt = $pdo->prepare(
        "SELECT c.*, u.full_name AS owner_name" . ($contractHasMetadata ? ', d.name AS department_name' : '') . $fromSql . " WHERE $where$scope ORDER BY c.start_date DESC"
    );
    $contractsStmt->execute($listParams);
    $contracts = $contractsStmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if ($contracts === []) {
        ?><tr><td colspan="<?= $contractHasMetadata ? '10' : '9' ?>" class="t8-table-empty-row"><?= $search !== '' || $statusFilter !== '' || $typeFilter !== '' ? 'No matching contracts.' : ($archivedFilter ? 'No archived contracts.' : 'No contracts yet.') ?></td></tr><?php
    } else {
        foreach ($contracts as $c) {
            $isMonitoring = t8_contract_is_monitoring($pdo, $c);
            $retentionRecord = function_exists('t8_retention_fetch_for_entity')
                ? t8_retention_fetch_for_entity($pdo, 'contract', (int) $c['id'])
                : null;
            ?>
            <tr>
                <td><?= e((string) $c['contract_number']) ?></td>
                <td><?= e($c['title']) ?></td>
                <td><?= e((string) ($c['contract_type'] ?? '—')) ?></td>
                <?php if ($contractHasMetadata): ?><td><?= e((string) ($c['department_name'] ?? '—')) ?></td><?php endif; ?>
                <td><?= e($c['owner_name']) ?></td>
                <td><?= e(format_date($c['start_date'], 'M d, Y')) ?></td>
                <td><?= $c['end_date'] ? e(format_date($c['end_date'], 'M d, Y')) : '—' ?></td>
                <td><?= $c['amount'] !== null ? e((string) ($c['currency'] ?? 'PHP') . ' ' . number_format((float) $c['amount'], 2)) : '—' ?></td>
                <td><span class="t8-badge <?= t8_contract_status_badge($c['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $c['status']))) ?></span><?php if ($isMonitoring): ?><span class="t8-badge-monitoring" title="Within 90 days of end date, termination date, renewal date, or a pending obligation">Monitoring</span><?php endif; ?></td>
                <td style="text-align:right;"><?php t8_contract_render_menu($c, $isAdmin, $archivedFilter, $isMonitoring, $retentionRecord); ?></td>
            </tr>
            <?php
        }
    }
    echo json_encode(['html' => (string) ob_get_clean(), 'total' => $total, 'count' => count($contracts)]);
    exit;
}

$owners = $pdo->query('SELECT id, full_name FROM users ORDER BY full_name')->fetchAll(PDO::FETCH_ASSOC);
$currentUserName = '';
foreach ($owners as $owner) {
    if ((int) $owner['id'] === $currentUserId) {
        $currentUserName = (string) $owner['full_name'];
        break;
    }
}
$departments = $contractHasMetadata ? $pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) : [];

// ---------------------------------------------------------------
// Lifecycle reminders - evaluated whenever the module is used.
//
// Lifecycle v2: 'expiring_soon' is no longer a stored status - it is
// the computed "monitoring" sub-state (see t8_contract_is_monitoring()
// above), shown as an extra badge on an 'active' contract. This sweep
// now only flips a contract whose end_date has actually passed into
// the terminal 'expiration_or_termination' status, and registers it
// under retention the first time that happens (see
// t8_contract_register_retention() above).
// ---------------------------------------------------------------
$expiryRows = $pdo->query(
    "SELECT id, owner_id, status, end_date, termination_date FROM team8_contracts
     WHERE deleted_at IS NULL AND end_date IS NOT NULL AND status = 'active' AND end_date < CURDATE()"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($expiryRows as $expiryRow) {
    $pdo->prepare("UPDATE team8_contracts SET status = 'expiration_or_termination' WHERE id = :id")
        ->execute(['id' => $expiryRow['id']]);
    t8_audit_log($pdo, $currentUserId, 'contract', (int) $expiryRow['id'], 'expiration_or_termination', (string) $expiryRow['status'], 'automatic expiration review');
    $pdo->prepare('INSERT INTO notifications (user_id, message, status) VALUES (:user_id, :message, "unread")')
        ->execute(['user_id' => $expiryRow['owner_id'], 'message' => 'A contract assigned to you has reached its end date and moved to Expiration/Termination.']);
    t8_contract_register_retention($pdo, $expiryRow, $currentUserId);
}

if (!$isAdmin && !in_array($action, ['list', 'documents', 'workflow'], true)) {
    t8_require_role(['admin']);
}

switch ($action) {
    case 'workflow':
        t8_require_role(['admin', 'legal_officer']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('contracts'));
        }
        $workflowId = (int) ($_POST['id'] ?? 0);
        $workflowAction = (string) ($_POST['workflow_action'] ?? '');
        $workflowComment = trim((string) ($_POST['comment'] ?? ''));
        // Lifecycle v2 mapping - see T8_CONTRACT_STATUSES docblock above.
        $workflowMap = [
            'submit'          => 'review',
            'request_changes' => 'negotiation',
            'approve'         => 'approval',
            'sign'            => 'signing',
            'activate'        => 'active',
            'renew'           => 'renewal_or_amendment',
            'terminate'       => 'expiration_or_termination',
        ];
        $workflowContract = t8_contract_fetch($pdo, $workflowId);
        if (!$workflowContract || !isset($workflowMap[$workflowAction])) {
            t8_flash_set('danger', 'Contract workflow action is invalid.');
            redirect(page_url('contracts'));
        }
        if (in_array($workflowAction, ['approve', 'sign'], true) && !$isAdmin) {
            t8_flash_set('danger', 'Only an administrator can approve or sign contracts.');
            redirect(page_url('contracts'));
        }
        $newWorkflowStatus = $workflowMap[$workflowAction];
        $pdo->prepare('UPDATE team8_contracts SET status = :status WHERE id = :id')->execute(['status' => $newWorkflowStatus, 'id' => $workflowId]);
        $pdo->prepare(
            'INSERT INTO team8_contract_approvals (contract_id, reviewer_id, approver_id, action, comment) VALUES (:contract_id, :reviewer_id, :approver_id, :action, :comment)'
        )->execute([
            'contract_id' => $workflowId,
            'reviewer_id' => in_array($workflowAction, ['submit', 'request_changes'], true) ? $currentUserId : null,
            'approver_id' => in_array($workflowAction, ['approve', 'sign', 'activate'], true) ? $currentUserId : null,
            'action' => $workflowAction,
            'comment' => $workflowComment !== '' ? $workflowComment : null,
        ]);
        t8_audit_log($pdo, $currentUserId, 'contract', $workflowId, $workflowAction, (string) $workflowContract['status'], $workflowComment);

        // RETENTION HOOK: the moment a contract reaches end-of-life via a
        // manual "Terminate" action (as opposed to the automatic expiry
        // sweep above), register it under retention the same way.
        if ($newWorkflowStatus === 'expiration_or_termination') {
            $refreshedContract = t8_contract_fetch($pdo, $workflowId);
            if ($refreshedContract !== null) {
                t8_contract_register_retention($pdo, $refreshedContract, $currentUserId);
            }
        }

        t8_flash_set('success', 'Contract status updated.');
        redirect(page_url('contracts'));
        break;

    case 'create':
    case 'edit':
        t8_require_role(['admin']);
        $contractId = $action === 'edit' ? (int) ($_GET['id'] ?? 0) : 0;
        $existing = $contractId ? t8_contract_fetch($pdo, $contractId) : null;
        if ($action === 'edit' && !$existing) {
            t8_flash_set('danger', 'Contract not found.');
            redirect(page_url('contracts'));
        }
        $renewableContracts = $pdo->prepare(
            'SELECT id, title FROM team8_contracts WHERE id != :id ORDER BY title'
        );
        $renewableContracts->execute(['id' => $contractId]);
        $renewableContracts = $renewableContracts->fetchAll(PDO::FETCH_ASSOC);

        $formValues = $existing !== null
            ? [
                'contract_number' => $existing['contract_number'],
                'title'            => $existing['title'],
                'contract_type'    => (string) ($existing['contract_type'] ?? ''),
                'description'     => (string) ($existing['description'] ?? ''),
                'owner_id'         => (string) $existing['owner_id'],
                'department_id'    => (string) ($existing['department_id'] ?? ''),
                'start_date'       => $existing['start_date'],
                'end_date'         => (string) $existing['end_date'],
                'renewal_date'     => (string) ($existing['renewal_date'] ?? ''),
                'amount'           => (string) ($existing['amount'] ?? ''),
                'currency'         => (string) ($existing['currency'] ?? 'PHP'),
                'payment_terms'    => (string) ($existing['payment_terms'] ?? ''),
                'payment_frequency'=> (string) ($existing['payment_frequency'] ?? ''),
                'deposit_amount'   => (string) ($existing['deposit_amount'] ?? ''),
                'financial_notes'  => (string) ($existing['financial_notes'] ?? ''),
                'notice_period_days' => (string) ($existing['notice_period_days'] ?? ''),
                'termination_date' => (string) ($existing['termination_date'] ?? ''),
                'termination_reason' => (string) ($existing['termination_reason'] ?? ''),
                'status'           => $existing['status'],
                'renewed_from_id'  => (string) ($existing['renewed_from_id'] ?? ''),
            ]
            : [
                'contract_number' => t8_contract_next_number($pdo), 'title' => '', 'contract_type' => '', 'description' => '', 'owner_id' => (string) $currentUserId, 'start_date' => date('Y-m-d'),
                'end_date' => '', 'renewal_date' => '', 'amount' => '', 'department_id' => '', 'status' => 'draft', 'renewed_from_id' => '',
                'currency' => 'PHP', 'payment_terms' => '', 'payment_frequency' => '', 'deposit_amount' => '', 'financial_notes' => '', 'notice_period_days' => '', 'termination_date' => '', 'termination_reason' => '',
            ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $formValues = [
                'contract_number' => $existing !== null ? (string) $existing['contract_number'] : (string) $formValues['contract_number'],
                'title'           => trim((string) ($_POST['title'] ?? '')),
                'contract_type'   => trim((string) ($_POST['contract_type'] ?? '')),
                'description'    => trim((string) ($_POST['description'] ?? '')),
                'owner_id'        => $existing !== null ? (string) $existing['owner_id'] : (string) $currentUserId,
                'department_id'   => (string) ($_POST['department_id'] ?? ''),
                'start_date'      => trim((string) ($_POST['start_date'] ?? '')),
                'end_date'        => trim((string) ($_POST['end_date'] ?? '')),
                'renewal_date'    => trim((string) ($_POST['renewal_date'] ?? '')),
                'amount'          => trim((string) ($_POST['amount'] ?? '')),
                'currency'        => strtoupper(trim((string) ($_POST['currency'] ?? 'PHP'))),
                'payment_terms'   => trim((string) ($_POST['payment_terms'] ?? '')),
                'payment_frequency' => trim((string) ($_POST['payment_frequency'] ?? '')),
                'deposit_amount'  => trim((string) ($_POST['deposit_amount'] ?? '')),
                'financial_notes' => trim((string) ($_POST['financial_notes'] ?? '')),
                'notice_period_days' => trim((string) ($_POST['notice_period_days'] ?? '')),
                'termination_date' => trim((string) ($_POST['termination_date'] ?? '')),
                'termination_reason' => trim((string) ($_POST['termination_reason'] ?? '')),
                'status'          => $existing !== null ? (string) $existing['status'] : 'draft',
                'renewed_from_id' => trim((string) ($_POST['renewed_from_id'] ?? '')),
            ];

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                if ($formValues['title'] === '') {
                    $errors[] = 'Contract title is required.';
                }
                if ($formValues['contract_number'] === '') { $errors[] = 'Contract number is required.'; }
                if (!$formValues['owner_id']) {
                    $errors[] = 'Please select a contract owner.';
                }
                if ($formValues['start_date'] === '' || strtotime($formValues['start_date']) === false) {
                    $errors[] = 'Start date must be a valid date.';
                }
                if ($formValues['end_date'] !== '' && strtotime($formValues['end_date']) === false) {
                    $errors[] = 'End date must be a valid date.';
                }
                if ($formValues['start_date'] !== '' && strtotime($formValues['start_date']) !== false && $formValues['end_date'] !== '' && strtotime($formValues['end_date']) !== false && strtotime($formValues['end_date']) < strtotime($formValues['start_date'])) {
                    $errors[] = 'End date must be on or after the start date.';
                }
                if ($formValues['renewal_date'] !== '' && strtotime($formValues['renewal_date']) === false) { $errors[] = 'Renewal date must be a valid date.'; }
                if ($formValues['amount'] !== '' && (!is_numeric($formValues['amount']) || (float) $formValues['amount'] < 0)) { $errors[] = 'Amount must be a non-negative number.'; }
                if ($formValues['deposit_amount'] !== '' && (!is_numeric($formValues['deposit_amount']) || (float) $formValues['deposit_amount'] < 0)) { $errors[] = 'Deposit must be a non-negative number.'; }
                if ($formValues['notice_period_days'] !== '' && filter_var($formValues['notice_period_days'], FILTER_VALIDATE_INT) === false) { $errors[] = 'Notice period must be a whole number of days.'; }
                if (!in_array($formValues['currency'], T8_CONTRACT_CURRENCIES, true)) { $errors[] = 'Please select a valid currency.'; }
                if ($formValues['payment_frequency'] !== '' && !in_array($formValues['payment_frequency'], T8_CONTRACT_PAYMENT_FREQUENCIES, true)) { $errors[] = 'Please select a valid payment frequency.'; }

                if (!$errors) {
                    $params = [
                        'contract_number' => $formValues['contract_number'],
                        'owner_id'        => (int) $formValues['owner_id'],
                        'title'           => $formValues['title'],
                        'start_date'      => $formValues['start_date'],
                        'end_date'        => $formValues['end_date'] !== '' ? $formValues['end_date'] : null,
                        'status'          => $formValues['status'],
                        'renewed_from_id' => $formValues['renewed_from_id'] !== '' ? (int) $formValues['renewed_from_id'] : null,
                        'contract_type'   => $formValues['contract_type'] !== '' ? $formValues['contract_type'] : null,
                        'description'     => $formValues['description'] !== '' ? $formValues['description'] : null,
                        'currency'        => $formValues['currency'] !== '' ? $formValues['currency'] : 'PHP',
                        'payment_terms'   => $formValues['payment_terms'] !== '' ? $formValues['payment_terms'] : null,
                        'payment_frequency' => $formValues['payment_frequency'] !== '' ? $formValues['payment_frequency'] : null,
                        'deposit_amount'  => $formValues['deposit_amount'] !== '' ? $formValues['deposit_amount'] : null,
                        'financial_notes' => $formValues['financial_notes'] !== '' ? $formValues['financial_notes'] : null,
                        'notice_period_days' => $formValues['notice_period_days'] !== '' ? (int) $formValues['notice_period_days'] : null,
                        'termination_date' => $formValues['termination_date'] !== '' ? $formValues['termination_date'] : null,
                        'termination_reason' => $formValues['termination_reason'] !== '' ? $formValues['termination_reason'] : null,
                    ];

                    if ($action === 'create') {
                        if ($contractHasMetadata) {
                            $params += ['department_id' => $formValues['department_id'] !== '' ? (int) $formValues['department_id'] : null, 'renewal_date' => $formValues['renewal_date'] !== '' ? $formValues['renewal_date'] : null, 'amount' => $formValues['amount'] !== '' ? $formValues['amount'] : null];
                        }
                        $pdo->prepare('INSERT INTO team8_contracts (contract_number, owner_id, department_id, renewed_from_id, title, contract_type, description, start_date, end_date, renewal_date, amount, currency, payment_terms, payment_frequency, deposit_amount, financial_notes, notice_period_days, termination_date, termination_reason, status) VALUES (:contract_number, :owner_id, :department_id, :renewed_from_id, :title, :contract_type, :description, :start_date, :end_date, :renewal_date, :amount, :currency, :payment_terms, :payment_frequency, :deposit_amount, :financial_notes, :notice_period_days, :termination_date, :termination_reason, :status)')->execute($params);
                        $newId = (int) $pdo->lastInsertId();
                        t8_contract_save_history($pdo, $newId, $params, $currentUserId);
                        t8_audit_log($pdo, $currentUserId, 'contract', $newId, 'create');
                        t8_flash_set('success', 'Contract created.');
                    } else {
                        $params['id'] = $contractId;
                        if ($contractHasMetadata) {
                            $params += ['department_id' => $formValues['department_id'] !== '' ? (int) $formValues['department_id'] : null, 'renewal_date' => $formValues['renewal_date'] !== '' ? $formValues['renewal_date'] : null, 'amount' => $formValues['amount'] !== '' ? $formValues['amount'] : null];
                        }
                        $pdo->prepare('UPDATE team8_contracts SET title = :title, contract_type = :contract_type, description = :description, department_id = :department_id, renewed_from_id = :renewed_from_id, start_date = :start_date, end_date = :end_date, renewal_date = :renewal_date, amount = :amount, currency = :currency, payment_terms = :payment_terms, payment_frequency = :payment_frequency, deposit_amount = :deposit_amount, financial_notes = :financial_notes, notice_period_days = :notice_period_days, termination_date = :termination_date, termination_reason = :termination_reason WHERE id = :id')->execute($params);
                        t8_contract_save_history($pdo, $contractId, $params, $currentUserId);
                        t8_audit_log($pdo, $currentUserId, 'contract', $contractId, 'update');

                        // A manual edit can ALSO be how a contract reaches its
                        // terminal status (status dropdown set directly, rather
                        // than via the workflow buttons) - register the same way.
                        if ($params['status'] === 'expiration_or_termination') {
                            $refreshedContract = t8_contract_fetch($pdo, $contractId);
                            if ($refreshedContract !== null) {
                                t8_contract_register_retention($pdo, $refreshedContract, $currentUserId);
                            }
                        }

                        t8_flash_set('success', 'Contract updated.');
                    }
                    redirect(page_url('contracts'));
                }
            }
        }
        break;

    case 'archive':
    case 'restore':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('contracts'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('contracts'));
        }
        $id = (int) ($_POST['id'] ?? 0);
        if (t8_contract_fetch($pdo, $id)) {
            $sql = $action === 'archive'
                ? 'UPDATE team8_contracts SET deleted_at = NOW() WHERE id = :id'
                : 'UPDATE team8_contracts SET deleted_at = NULL WHERE id = :id';
            $pdo->prepare($sql)->execute(['id' => $id]);
            t8_audit_log($pdo, $currentUserId, 'contract', $id, $action);
            t8_flash_set('success', $action === 'archive' ? 'Contract archived.' : 'Contract restored.');
        } else {
            t8_flash_set('danger', 'Contract not found.');
        }
        redirect(page_url('contracts'));
        break;

    // ---- Parties ----
    case 'parties':
        $contractId = (int) ($_GET['id'] ?? 0);
        $contract = $contractId ? t8_contract_fetch($pdo, $contractId) : null;
        if (!$contract) {
            t8_flash_set('danger', 'Contract not found.');
            redirect(page_url('contracts'));
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $partyId = (int) ($_POST['party_id'] ?? 0);
            $newName = trim((string) ($_POST['new_name'] ?? ''));
            $newType = trim((string) ($_POST['new_type'] ?? ''));
            $newEmail = trim((string) ($_POST['new_email'] ?? ''));
            $newPhone = trim((string) ($_POST['new_phone'] ?? ''));
            $role = trim((string) ($_POST['role_in_contract'] ?? ''));

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                if (!$partyId && $newName === '') {
                    $errors[] = 'Select an existing party or enter a name for a new one.';
                }
                if ($role === '') {
                    $errors[] = "Please specify the party's role in this contract.";
                }

                if (!$errors) {
                    if (!$partyId) {
                        if ($newType === '') {
                            $errors[] = 'Party type is required for a new party.';
                        } else {
                            $pdo->prepare(
                                'INSERT INTO team8_parties (name, type, contact_email, contact_phone) VALUES (:name, :type, :email, :phone)'
                            )->execute([
                                'name'  => $newName,
                                'type'  => $newType,
                                'email' => $newEmail !== '' ? $newEmail : null,
                                'phone' => $newPhone !== '' ? $newPhone : null,
                            ]);
                            $partyId = (int) $pdo->lastInsertId();
                        }
                    }

                    if (!$errors) {
                        $pdo->prepare(
                            'INSERT INTO team8_contract_parties (contract_id, party_id, role_in_contract) VALUES (:contract_id, :party_id, :role)'
                        )->execute(['contract_id' => $contractId, 'party_id' => $partyId, 'role' => $role]);
                        t8_audit_log($pdo, $currentUserId, 'contract', $contractId, 'add_party');
                        t8_flash_set('success', 'Party added to contract.');
                        redirect(page_url('contracts', ['action' => 'parties', 'id' => $contractId]));
                    }
                }
            }
        }

        $availableParties = $pdo->query('SELECT id, name, type FROM team8_parties ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare(
            'SELECT cp.*, p.name AS party_name, p.type AS party_type, p.contact_email, p.contact_phone
             FROM team8_contract_parties cp
             JOIN team8_parties p ON p.id = cp.party_id
             WHERE cp.contract_id = :contract_id
             ORDER BY cp.created_at DESC'
        );
        $stmt->execute(['contract_id' => $contractId]);
        $attachedParties = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'remove_party':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('contracts'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('contracts'));
        }
        $linkId = (int) ($_POST['link_id'] ?? 0);
        $contractId = (int) ($_POST['contract_id'] ?? 0);
        $pdo->prepare('DELETE FROM team8_contract_parties WHERE id = :id AND contract_id = :contract_id')
            ->execute(['id' => $linkId, 'contract_id' => $contractId]);
        t8_audit_log($pdo, $currentUserId, 'contract', $contractId, 'remove_party');
        t8_flash_set('success', 'Party removed from contract.');
        redirect(page_url('contracts', ['action' => 'parties', 'id' => $contractId]));
        break;

    // ---- Obligations ----
    case 'obligations':
        $contractId = (int) ($_GET['id'] ?? 0);
        $contract = $contractId ? t8_contract_fetch($pdo, $contractId) : null;
        if (!$contract) {
            t8_flash_set('danger', 'Contract not found.');
            redirect(page_url('contracts'));
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $description = trim((string) ($_POST['description'] ?? ''));
            $dueDate = trim((string) ($_POST['due_date'] ?? ''));

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                if ($description === '') {
                    $errors[] = 'Obligation description is required.';
                }
                if ($dueDate !== '' && strtotime($dueDate) === false) {
                    $errors[] = 'Due date must be a valid date.';
                }

                if (!$errors) {
                    $pdo->prepare(
                        'INSERT INTO team8_contract_obligations (contract_id, description, due_date, status)
                         VALUES (:contract_id, :description, :due_date, "pending")'
                    )->execute([
                        'contract_id' => $contractId,
                        'description' => $description,
                        'due_date'    => $dueDate !== '' ? $dueDate : null,
                    ]);
                    t8_audit_log($pdo, $currentUserId, 'contract', $contractId, 'add_obligation');
                    t8_flash_set('success', 'Obligation added.');
                    redirect(page_url('contracts', ['action' => 'obligations', 'id' => $contractId]));
                }
            }
        }

        $obligationsStmt = $pdo->prepare(
            'SELECT * FROM team8_contract_obligations WHERE contract_id = :contract_id ORDER BY due_date ASC'
        );
        $obligationsStmt->execute(['contract_id' => $contractId]);
        $obligations = $obligationsStmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'complete_obligation':
    case 'reopen_obligation':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('contracts'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('contracts'));
        }
        $obligationId = (int) ($_POST['id'] ?? 0);
        $contractId = (int) ($_POST['contract_id'] ?? 0);
        $newStatus = $action === 'complete_obligation' ? 'completed' : 'pending';
        $pdo->prepare('UPDATE team8_contract_obligations SET status = :status WHERE id = :id AND contract_id = :contract_id')
            ->execute(['status' => $newStatus, 'id' => $obligationId, 'contract_id' => $contractId]);
        t8_audit_log($pdo, $currentUserId, 'contract', $contractId, $action);
        t8_flash_set('success', $newStatus === 'completed' ? 'Obligation marked complete.' : 'Obligation reopened.');
        redirect(page_url('contracts', ['action' => 'obligations', 'id' => $contractId]));
        break;

    case 'delete_obligation':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('contracts'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('contracts'));
        }
        $obligationId = (int) ($_POST['id'] ?? 0);
        $contractId = (int) ($_POST['contract_id'] ?? 0);
        $pdo->prepare('DELETE FROM team8_contract_obligations WHERE id = :id AND contract_id = :contract_id')
            ->execute(['id' => $obligationId, 'contract_id' => $contractId]);
        t8_audit_log($pdo, $currentUserId, 'contract', $contractId, 'delete_obligation');
        t8_flash_set('success', 'Obligation deleted.');
        redirect(page_url('contracts', ['action' => 'obligations', 'id' => $contractId]));
        break;

    // ---- Documents ----
    case 'documents':
        $contractId = (int) ($_GET['id'] ?? 0);
        $contract = $contractId ? t8_contract_fetch($pdo, $contractId) : null;
        if (!$contract) {
            t8_flash_set('danger', 'Contract not found.');
            redirect(page_url('contracts'));
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $documentId = (int) ($_POST['document_id'] ?? 0);
            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } elseif (isset($_FILES['contract_file']) && ($_FILES['contract_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                try {
                    $version = 1;
                    if ($documentId) {
                        $versionStmt = $pdo->prepare('SELECT current_version, title FROM team8_documents WHERE id = :id AND deleted_at IS NULL');
                        $versionStmt->execute(['id' => $documentId]);
                        $document = $versionStmt->fetch(PDO::FETCH_ASSOC);
                        if (!$document) { throw new RuntimeException('The selected document is not available.'); }
                        $version = (int) $document['current_version'] + 1;
                        $documentTitle = (string) $document['title'];
                    } else {
                        $documentTitle = trim((string) ($_POST['document_title'] ?? '')) ?: $contract['title'];
                    }
                    $stored = t8_contract_upload($_FILES['contract_file'], $documentTitle, $version);
                    if (!$documentId) {
                        $pdo->prepare('INSERT INTO team8_documents (uploaded_by, owner_id, title, file_path, current_version, status) VALUES (:uploaded_by, :owner_id, :title, :file_path, 1, :status)')->execute([
                            'uploaded_by' => $currentUserId, 'owner_id' => $contract['owner_id'], 'title' => $documentTitle, 'file_path' => $stored['file_path'],
                            'status' => $isAdmin ? 'approved' : 'pending',
                        ]);
                        $documentId = (int) $pdo->lastInsertId();
                        $pdo->prepare('INSERT INTO team8_document_versions (document_id, version_no, file_path, file_size, checksum) VALUES (:document_id, 1, :file_path, :file_size, :checksum)')->execute(['document_id' => $documentId] + $stored);
                    } else {
                        $pdo->prepare('INSERT INTO team8_document_versions (document_id, version_no, file_path, file_size, checksum) VALUES (:document_id, :version_no, :file_path, :file_size, :checksum)')->execute(['document_id' => $documentId, 'version_no' => $version] + $stored);
                        $pdo->prepare('UPDATE team8_documents SET file_path = :file_path, current_version = :version, status = :status WHERE id = :id')->execute(['file_path' => $stored['file_path'], 'version' => $version, 'status' => $isAdmin ? 'approved' : 'pending', 'id' => $documentId]);
                    }
                    $pdo->prepare('INSERT IGNORE INTO team8_contract_documents (contract_id, document_id) VALUES (:contract_id, :document_id)')->execute(['contract_id' => $contractId, 'document_id' => $documentId]);
                    t8_audit_log($pdo, $currentUserId, 'contract', $contractId, 'upload_document', null, 'v' . $version);
                    t8_flash_set('success', 'Contract document uploaded.');
                    redirect(page_url('contracts', ['action' => 'documents', 'id' => $contractId]));
                } catch (RuntimeException $exception) {
                    $errors[] = $exception->getMessage();
                }
            } elseif (!$documentId) {
                $errors[] = 'Please select a document to attach.';
            } else {
                if (!$isAdmin) {
                    $ownerStmt = $pdo->prepare('SELECT id FROM team8_documents WHERE id = :id AND uploaded_by = :user_id AND deleted_at IS NULL');
                    $ownerStmt->execute(['id' => $documentId, 'user_id' => $currentUserId]);
                    if (!$ownerStmt->fetchColumn()) { $errors[] = 'You may submit only your own supporting documents.'; }
                }
                if (!$errors) {
                $pdo->prepare(
                    'INSERT INTO team8_contract_documents (contract_id, document_id) VALUES (:contract_id, :document_id)'
                )->execute(['contract_id' => $contractId, 'document_id' => $documentId]);
                t8_audit_log($pdo, $currentUserId, 'contract', $contractId, 'attach_document');
                t8_flash_set('success', 'Document attached to contract.');
                redirect(page_url('contracts', ['action' => 'documents', 'id' => $contractId]));
                }
            }
        }

        $attachedDocsStmt = $pdo->prepare(
            'SELECT cd.*, d.title AS document_title
             FROM team8_contract_documents cd
             JOIN team8_documents d ON d.id = cd.document_id
             WHERE cd.contract_id = :contract_id
             ORDER BY cd.created_at DESC'
        );
        $attachedDocsStmt->execute(['contract_id' => $contractId]);
        $attachedDocs = $attachedDocsStmt->fetchAll(PDO::FETCH_ASSOC);

        $availableDocsStmt = $pdo->prepare('SELECT id, title FROM team8_documents WHERE deleted_at IS NULL' . ($isAdmin ? '' : ' AND uploaded_by = :user_id') . ' ORDER BY title');
        $availableDocsStmt->execute($isAdmin ? [] : ['user_id' => $currentUserId]);
        $availableDocs = $availableDocsStmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'detach_document':
        t8_require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('contracts'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('contracts'));
        }
        $linkId = (int) ($_POST['link_id'] ?? 0);
        $contractId = (int) ($_POST['contract_id'] ?? 0);
        $pdo->prepare('DELETE FROM team8_contract_documents WHERE id = :id AND contract_id = :contract_id')
            ->execute(['id' => $linkId, 'contract_id' => $contractId]);
        t8_audit_log($pdo, $currentUserId, 'contract', $contractId, 'detach_document');
        t8_flash_set('success', 'Document removed from contract.');
        redirect(page_url('contracts', ['action' => 'documents', 'id' => $contractId]));
        break;
}

$showForm = in_array($action, ['create', 'edit'], true);
$showParties = $action === 'parties';
$showObligations = $action === 'obligations';
$showDocuments = $action === 'documents';
$showList = !$showForm && !$showParties && !$showObligations && !$showDocuments;

if ($showList) {
    $archivedFilter = ($_GET['archived'] ?? '0') === '1';
    $search = trim((string) ($_GET['search'] ?? ''));
    $statusFilter = trim((string) ($_GET['status'] ?? ''));
    $typeFilter = trim((string) ($_GET['contract_type'] ?? ''));
    $where = $archivedFilter ? 'c.deleted_at IS NOT NULL' : 'c.deleted_at IS NULL';
    $listParams = [];
    if ($search !== '') { $where .= ' AND (c.contract_number LIKE :search_number OR c.title LIKE :search_title OR c.description LIKE :search_description OR EXISTS (SELECT 1 FROM team8_contract_parties cp JOIN team8_parties p ON p.id = cp.party_id WHERE cp.contract_id = c.id AND p.name LIKE :party_search))'; $searchTerm = '%' . $search . '%'; $listParams['search_number'] = $searchTerm; $listParams['search_title'] = $searchTerm; $listParams['search_description'] = $searchTerm; $listParams['party_search'] = $searchTerm; }
    if ($statusFilter !== '' && in_array($statusFilter, T8_CONTRACT_STATUSES, true)) { $where .= ' AND c.status = :status'; $listParams['status'] = $statusFilter; }
    if ($typeFilter !== '') { $where .= ' AND c.contract_type = :contract_type'; $listParams['contract_type'] = $typeFilter; }
    $scope = $isAdmin ? '' : ($contractHasMetadata ? ' AND (c.owner_id = :user_id OR c.department_id = :department_id)' : ' AND c.owner_id = :user_id');
    $contractsStmt = $pdo->prepare(
        "SELECT c.*, u.full_name AS owner_name" . ($contractHasMetadata ? ', d.name AS department_name' : '') . "
         FROM team8_contracts c
         JOIN users u ON u.id = c.owner_id
         " . ($contractHasMetadata ? 'LEFT JOIN departments d ON d.id = c.department_id' : '') . "
         WHERE $where$scope
         ORDER BY c.start_date DESC"
    );
    $listParams += $isAdmin ? [] : ($contractHasMetadata ? ['user_id' => $currentUserId, 'department_id' => $_SESSION['department_id'] ?? 0] : ['user_id' => $currentUserId]);
    $contractsStmt->execute($listParams);
    $contracts = $contractsStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<h1>Contract Management</h1>
<p class="t8-help-text"><?= $isAdmin ? 'Manage contracts, parties, obligations, and supporting documents.' : 'View contracts assigned to you or your department and submit supporting documents.' ?></p>

<?php foreach ($errors as $error): ?>
    <div class="t8-alert t8-alert-danger"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if ($showForm): ?>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= $action === 'edit' ? 'Edit Contract' : 'New Contract' ?></h2>
        </div>

        <?php if ($existing !== null): ?>
            <?php t8_contract_render_lifecycle_stepper((string) $existing['status']); ?>
        <?php endif; ?>

        <form method="post"
              action="<?= e(page_url('contracts', array_filter(['action' => $action, 'id' => $_GET['id'] ?? null]))) ?>"
              id="t8ContractForm"
              class="t8-contract-form-grid"
              novalidate>
            <?= t8_csrf_field() ?>

            <div class="t8-field">
                <label class="t8-label" for="contract_number">Contract Number</label>
                <input class="t8-input" type="text" id="contract_number" name="contract_number" value="<?= e($formValues['contract_number']) ?>" readonly>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="contract_type">Contract Type</label>
                <select class="t8-select" id="contract_type" name="contract_type">
                    <option value="">Select type</option>
                    <?php foreach (T8_CONTRACT_TYPES as $type): ?>
                        <option value="<?= e($type) ?>" <?= $formValues['contract_type'] === $type ? 'selected' : '' ?>><?= e($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="t8-field t8-form-span-2">
                <label class="t8-label" for="title">Contract Title</label>
                <input class="t8-input" type="text" id="title" name="title" value="<?= e($formValues['title']) ?>" required>
            </div>

            <div class="t8-field t8-form-span-2">
                <label class="t8-label" for="description">Description</label>
                <textarea class="t8-input" id="description" name="description" rows="3"><?= e($formValues['description']) ?></textarea>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="responsible_officer">Responsible Officer</label>
                <input class="t8-input" type="text" id="responsible_officer" value="<?= e($existing !== null ? (string) ($existing['owner_name'] ?? '') : $currentUserName) ?>" readonly>
                <span class="t8-help-text">Who is responsible for the contract.</span>
            </div>

            <?php if ($contractHasMetadata): ?><div class="t8-field"><label class="t8-label" for="department_id">Department</label><select class="t8-select" id="department_id" name="department_id"><option value="">Not assigned</option><?php foreach ($departments as $department): ?><option value="<?= e((string) $department['id']) ?>" <?= (string) $department['id'] === $formValues['department_id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>

            <div class="t8-field">
                <label class="t8-label" for="start_date">Start Date</label>
                <input class="t8-input" type="date" id="start_date" name="start_date" value="<?= e($formValues['start_date']) ?>" required>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="end_date">End Date</label>
                <input class="t8-input" type="date" id="end_date" name="end_date" value="<?= e($formValues['end_date']) ?>">
                <span class="t8-help-text">Optional — leave blank for open-ended contracts. This is also the retention-clock start date, unless the contract is later terminated early.</span>
            </div>

            <div class="t8-field"><label class="t8-label" for="renewal_date">Renewal Date</label><input class="t8-input" type="date" id="renewal_date" name="renewal_date" value="<?= e($formValues['renewal_date']) ?>" data-t8-date-rule="future"></div>
            <div class="t8-field"><label class="t8-label" for="amount">Contract Value</label><input class="t8-input" type="number" min="0" step="0.01" id="amount" name="amount" value="<?= e($formValues['amount']) ?>"></div>
            <div class="t8-field"><label class="t8-label" for="currency">Currency</label><select class="t8-select" id="currency" name="currency" required><?php foreach (T8_CONTRACT_CURRENCIES as $currency): ?><option value="<?= e($currency) ?>" <?= $formValues['currency'] === $currency ? 'selected' : '' ?>><?= e($currency) ?></option><?php endforeach; ?></select></div>
            <div class="t8-field"><label class="t8-label" for="payment_frequency">Payment Frequency</label><select class="t8-select" id="payment_frequency" name="payment_frequency"><option value="">Select frequency</option><?php foreach (T8_CONTRACT_PAYMENT_FREQUENCIES as $frequency): ?><option value="<?= e($frequency) ?>" <?= $formValues['payment_frequency'] === $frequency ? 'selected' : '' ?>><?= e($frequency) ?></option><?php endforeach; ?></select></div>
            <div class="t8-field t8-form-span-2"><label class="t8-label" for="payment_terms">Payment Terms</label><input class="t8-input" type="text" id="payment_terms" name="payment_terms" value="<?= e($formValues['payment_terms']) ?>"></div>
            <div class="t8-field"><label class="t8-label" for="deposit_amount">Deposit / Advance</label><input class="t8-input" type="number" min="0" step="0.01" id="deposit_amount" name="deposit_amount" value="<?= e($formValues['deposit_amount']) ?>"></div>
            <div class="t8-field"><label class="t8-label" for="notice_period_days">Notice Period (days)</label><input class="t8-input" type="number" min="0" id="notice_period_days" name="notice_period_days" value="<?= e($formValues['notice_period_days']) ?>"></div>
            <div class="t8-field t8-form-span-2"><label class="t8-label" for="financial_notes">Additional Notes</label><textarea class="t8-input" id="financial_notes" name="financial_notes" rows="2"><?= e($formValues['financial_notes']) ?></textarea></div>

            <div class="t8-field">
                <span class="t8-label">Status</span>
                <strong><?= e(ucwords(str_replace('_', ' ', $formValues['status']))) ?></strong>
                <span class="t8-help-text">Status is controlled by the contract lifecycle workflow.</span>
            </div>

            <div class="t8-field"><label class="t8-label" for="termination_date">Termination Date</label><input class="t8-input" type="date" id="termination_date" name="termination_date" value="<?= e($formValues['termination_date']) ?>"></div>
            <div class="t8-field t8-form-span-2"><label class="t8-label" for="termination_reason">Termination Reason</label><textarea class="t8-input" id="termination_reason" name="termination_reason" rows="2"><?= e($formValues['termination_reason']) ?></textarea></div>

            <div class="t8-field">
                <label class="t8-label" for="renewed_from_id">Renewed From (optional)</label>
                <select class="t8-select" id="renewed_from_id" name="renewed_from_id">
                    <option value="">— Not a renewal —</option>
                    <?php foreach ($renewableContracts as $rc): ?>
                        <option value="<?= e((string) $rc['id']) ?>" <?= (string) $rc['id'] === $formValues['renewed_from_id'] ? 'selected' : '' ?>>
                            <?= e($rc['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="t8-form-actions t8-contract-form-actions">
                <button class="t8-btn t8-btn-accent" type="submit">
                    <i class="fa-solid fa-check"></i> <?= $action === 'edit' ? 'Save Changes' : 'Create Contract' ?>
                </button>
                <a class="t8-btn t8-btn-outline" href="<?= e(page_url('contracts')) ?>">Cancel</a>
            </div>
        </form>
        <script>
            (function () {
                var form = document.getElementById('t8ContractForm');
                var start = document.getElementById('start_date');
                var end = document.getElementById('end_date');
                if (!form || !start || !end) return;
                function validateDates() {
                    end.setCustomValidity(start.value && end.value && end.value < start.value ? 'End date must be on or after the start date.' : '');
                }
                start.addEventListener('input', validateDates);
                end.addEventListener('input', validateDates);
                form.addEventListener('submit', validateDates);
            }());
        </script>
    </div>

<?php elseif ($showParties): ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('contracts')) ?>"><i class="fa-solid fa-arrow-left"></i> Back to Contracts</a>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= e($contract['title']) ?> — Parties</h2>
        </div>

        <form method="post" action="<?= e(page_url('contracts', ['action' => 'parties', 'id' => $contractId])) ?>"
              style="padding: 0 var(--t8-space-4) var(--t8-space-4);" novalidate>
            <?= t8_csrf_field() ?>

            <div class="t8-field">
                <label class="t8-label" for="party_id">Existing Party</label>
                <select class="t8-select" id="party_id" name="party_id">
                    <option value="">— Add a new party instead —</option>
                    <?php foreach ($availableParties as $p): ?>
                        <option value="<?= e((string) $p['id']) ?>"><?= e($p['name']) ?> (<?= e($p['type']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="t8-field">
                <label class="t8-label" for="new_name">New Party Name</label>
                <input class="t8-input" type="text" id="new_name" name="new_name" placeholder="Only needed if not selected above">
            </div>
            <div class="t8-field">
                <label class="t8-label" for="new_type">New Party Type</label>
                <input class="t8-input" type="text" id="new_type" name="new_type" placeholder="e.g. Vendor, Client">
            </div>
            <div class="t8-field">
                <label class="t8-label" for="new_email">Contact Email</label>
                <input class="t8-input" type="text" id="new_email" name="new_email" placeholder="Optional">
            </div>
            <div class="t8-field">
                <label class="t8-label" for="new_phone">Contact Phone</label>
                <input class="t8-input" type="text" id="new_phone" name="new_phone" placeholder="Optional">
            </div>
            <div class="t8-field">
                <label class="t8-label" for="role_in_contract">Role in This Contract</label>
                <input class="t8-input" type="text" id="role_in_contract" name="role_in_contract" placeholder="e.g. Supplier, Counterparty" required>
            </div>

            <button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-plus"></i> Add Party</button>
        </form>

        <?php if ($attachedParties === []): ?>
            <div class="t8-empty">No parties added to this contract yet.</div>
        <?php else: ?>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead>
                        <tr><th>Name</th><th>Type</th><th>Role</th><th>Contact</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attachedParties as $ap): ?>
                            <tr>
                                <td><?= e($ap['party_name']) ?></td>
                                <td><?= e($ap['party_type']) ?></td>
                                <td><?= e($ap['role_in_contract']) ?></td>
                                <td><?= e((string) ($ap['contact_email'] ?? $ap['contact_phone'] ?? '—')) ?></td>
                                <td>
                                    <form method="post" action="<?= e(page_url('contracts', ['action' => 'remove_party'])) ?>"
                                          onsubmit="return confirm('Remove this party from the contract?');">
                                        <?= t8_csrf_field() ?>
                                        <input type="hidden" name="link_id" value="<?= e((string) $ap['id']) ?>">
                                        <input type="hidden" name="contract_id" value="<?= e((string) $contractId) ?>">
                                        <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit"><i class="fa-solid fa-xmark"></i> Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($showObligations): ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('contracts')) ?>"><i class="fa-solid fa-arrow-left"></i> Back to Contracts</a>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= e($contract['title']) ?> — Obligations</h2>
        </div>

        <form method="post" action="<?= e(page_url('contracts', ['action' => 'obligations', 'id' => $contractId])) ?>"
              style="padding: 0 var(--t8-space-4) var(--t8-space-4);" novalidate>
            <?= t8_csrf_field() ?>
            <div class="t8-field">
                <label class="t8-label" for="description">Obligation</label>
                <input class="t8-input" type="text" id="description" name="description" required>
            </div>
            <div class="t8-field">
                <label class="t8-label" for="due_date">Due Date</label>
                <input class="t8-input" type="date" id="due_date" name="due_date" placeholder="Optional" data-t8-date-rule="future">
            </div>
            <button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-plus"></i> Add Obligation</button>
        </form>

        <?php if ($obligations === []): ?>
            <div class="t8-empty">No obligations recorded for this contract yet.</div>
        <?php else: ?>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead>
                        <tr><th>Description</th><th>Due Date</th><th>Status</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($obligations as $ob): ?>
                            <?php $isOverdue = $ob['status'] === 'pending' && $ob['due_date'] && strtotime($ob['due_date']) < strtotime('today'); ?>
                            <tr>
                                <td><?= e($ob['description']) ?></td>
                                <td><?= $ob['due_date'] ? e(format_date($ob['due_date'], 'M d, Y')) : '—' ?></td>
                                <td>
                                    <?php if ($ob['status'] === 'completed'): ?>
                                        <span class="t8-badge t8-badge-approved">Completed</span>
                                    <?php elseif ($isOverdue): ?>
                                        <span class="t8-badge t8-badge-pending"><i class="fa-solid fa-triangle-exclamation"></i> Overdue</span>
                                    <?php else: ?>
                                        <span class="t8-badge t8-badge-pending">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <?php if ($ob['status'] === 'pending'): ?>
                                        <form method="post" action="<?= e(page_url('contracts', ['action' => 'complete_obligation'])) ?>">
                                            <?= t8_csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $ob['id']) ?>">
                                            <input type="hidden" name="contract_id" value="<?= e((string) $contractId) ?>">
                                            <button class="t8-btn t8-btn-success t8-btn-sm" type="submit"><i class="fa-solid fa-check"></i> Complete</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="<?= e(page_url('contracts', ['action' => 'reopen_obligation'])) ?>">
                                            <?= t8_csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $ob['id']) ?>">
                                            <input type="hidden" name="contract_id" value="<?= e((string) $contractId) ?>">
                                            <button class="t8-btn t8-btn-outline t8-btn-sm" type="submit"><i class="fa-solid fa-rotate-left"></i> Reopen</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="<?= e(page_url('contracts', ['action' => 'delete_obligation'])) ?>"
                                          onsubmit="return confirm('Delete this obligation?');">
                                        <?= t8_csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= e((string) $ob['id']) ?>">
                                        <input type="hidden" name="contract_id" value="<?= e((string) $contractId) ?>">
                                        <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit"><i class="fa-solid fa-trash"></i> Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($showDocuments): ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('contracts')) ?>"><i class="fa-solid fa-arrow-left"></i> Back to Contracts</a>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= e($contract['title']) ?> — Documents</h2>
        </div>

        <?php if ($availableDocs === []): ?><div class="t8-empty">No existing documents are available. Upload a contract document below.</div><?php endif; ?>
            <form method="post" enctype="multipart/form-data" action="<?= e(page_url('contracts', ['action' => 'documents', 'id' => $contractId])) ?>"
                  style="padding: 0 var(--t8-space-4) var(--t8-space-4);" novalidate>
                <?= t8_csrf_field() ?>
                <div class="t8-field">
                    <label class="t8-label" for="document_id">Attach Document</label>
                    <select class="t8-select" id="document_id" name="document_id">
                        <option value="">Select a document…</option>
                        <?php foreach ($availableDocs as $d): ?>
                            <option value="<?= e((string) $d['id']) ?>"><?= e($d['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="t8-field">
                    <label class="t8-label" for="document_title">New Document Title</label>
                    <input class="t8-input" type="text" id="document_title" name="document_title" placeholder="Optional for a new upload">
                </div>
                <div class="t8-field">
                    <label class="t8-label" for="contract_file">Upload New Version</label>
                    <input class="t8-input" type="file" id="contract_file" name="contract_file" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.png,.jpg,.jpeg">
                </div>
                <button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-paperclip"></i> Attach</button>
            </form>

        <?php if ($attachedDocs === []): ?>
            <div class="t8-empty">No documents attached to this contract yet.</div>
        <?php else: ?>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead><tr><th>Document</th><th>Attached On</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($attachedDocs as $ad): ?>
                            <tr>
                                <td><?= e($ad['document_title']) ?></td>
                                <td><?= e(format_date($ad['created_at'], 'M d, Y g:i A')) ?></td>
                                <td style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('documents', ['action' => 'versions', 'id' => $ad['document_id']])) ?>">
                                        <i class="fa-solid fa-eye"></i> View
                                    </a>
                                    <?php if ($isAdmin): ?><form method="post" action="<?= e(page_url('contracts', ['action' => 'detach_document'])) ?>"
                                          onsubmit="return confirm('Remove this document from the contract?');">
                                        <?= t8_csrf_field() ?>
                                        <input type="hidden" name="link_id" value="<?= e((string) $ad['id']) ?>">
                                        <input type="hidden" name="contract_id" value="<?= e((string) $contractId) ?>">
                                        <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit"><i class="fa-solid fa-xmark"></i> Remove</button>
                                    </form><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php else: ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <?php if ($isAdmin): ?><a class="t8-btn t8-btn-accent" href="<?= e(page_url('contracts', ['action' => 'create'])) ?>"><i class="fa-solid fa-plus"></i> New Contract</a><?php endif; ?>
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('contracts', ['archived' => $archivedFilter ? '0' : '1'])) ?>">
            <i class="fa-solid fa-box-archive"></i> <?= $archivedFilter ? 'View Active' : 'View Archived' ?>
        </a>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= $archivedFilter ? 'Archived Contracts' : 'Contracts' ?></h2>
        </div>
        <form method="get" action="<?= e(base_url('index.php')) ?>" class="t8-contract-filters" id="t8ContractsFilterForm" data-contract-filter-table="t8ContractsTable">
            <input type="hidden" name="page" value="contracts">
            <div class="t8-field"><label class="t8-label" for="search">Search</label><input class="t8-input" id="search" name="search" data-contract-search value="<?= e($search) ?>" placeholder="Number, title, party"></div>
            <div class="t8-field"><label class="t8-label" for="status_filter">Status</label><select class="t8-select" id="status_filter" name="status" data-contract-status><option value="">All statuses</option><?php foreach (T8_CONTRACT_STATUSES as $status): ?><option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $status))) ?></option><?php endforeach; ?></select></div>
            <div class="t8-field"><label class="t8-label" for="contract_type_filter">Type</label><select class="t8-select" id="contract_type_filter" name="contract_type" data-contract-type><option value="">All types</option><?php foreach (T8_CONTRACT_TYPES as $type): ?><option value="<?= e($type) ?>" <?= $typeFilter === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></div>
            <?php if ($archivedFilter): ?><input type="hidden" name="archived" value="1"><?php endif; ?>
        </form>
        <div class="t8-table-wrap">
                <table class="t8-table" id="t8ContractsTable">
                    <thead>
                        <tr><th>Contract No.</th><th>Title</th><th>Type</th><?php if ($contractHasMetadata): ?><th>Department</th><?php endif; ?><th>Responsible Person</th><th>Start</th><th>End</th><th>Value</th><th>Status</th><th>Actions</th></tr>
                    </thead>
                    <tbody data-contract-results>
                        <?php if ($contracts === []): ?>
                            <tr><td colspan="<?= $contractHasMetadata ? '10' : '9' ?>" class="t8-table-empty-row"><?= $archivedFilter ? 'No archived contracts.' : 'No contracts yet.' ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($contracts as $c): ?>
                            <?php
                            $isMonitoring = t8_contract_is_monitoring($pdo, $c);
                            $retentionRecord = function_exists('t8_retention_fetch_for_entity')
                                ? t8_retention_fetch_for_entity($pdo, 'contract', (int) $c['id'])
                                : null;
                            ?>
                            <tr>
                                <td><?= e((string) $c['contract_number']) ?></td>
                                <td><?= e($c['title']) ?></td>
                                <td><?= e((string) ($c['contract_type'] ?? '—')) ?></td>
                                <?php if ($contractHasMetadata): ?><td><?= e((string) ($c['department_name'] ?? '—')) ?></td><?php endif; ?>
                                <td><?= e($c['owner_name']) ?></td>
                                <td><?= e(format_date($c['start_date'], 'M d, Y')) ?></td>
                                <td><?= $c['end_date'] ? e(format_date($c['end_date'], 'M d, Y')) : '—' ?></td>
                                <td><?= $c['amount'] !== null ? e((string) ($c['currency'] ?? 'PHP') . ' ' . number_format((float) $c['amount'], 2)) : '—' ?></td>
                                <td>
                                    <span class="t8-badge <?= t8_contract_status_badge($c['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $c['status']))) ?></span>
                                    <?php if ($isMonitoring): ?><span class="t8-badge-monitoring" title="Within 90 days of end date, termination date, renewal date, or a pending obligation">Monitoring</span><?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <?php t8_contract_render_menu($c, $isAdmin, $archivedFilter, $isMonitoring, $retentionRecord); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
        </div>
    </div>

    <!--
        Shared View Details modal for the Contracts list table's
        meatball menu (see t8_contract_render_menu() above). One
        dialog, filled via JS from the clicked trigger's data-*
        attributes (public/js/row-menu.js) - same pattern as
        Facilities Reservation's #t8ReservationDetailModal.
    -->
    <dialog id="t8ContractDetailModal" class="t8-detail-modal">
        <div class="t8-detail-header">
            <div>
                <h2 data-detail-field="title">Contract</h2>
                <span class="t8-detail-ref" data-detail-field="ref"></span>
            </div>
            <button type="button" class="t8-detail-close" data-close-detail-modal aria-label="Close">&times;</button>
        </div>
        <div class="t8-detail-body">
            <div class="t8-detail-grid">
                <div class="t8-detail-item"><span>Contract Type</span><strong data-detail-field="contract-type">—</strong></div>
                <div class="t8-detail-item"><span>Status</span><strong data-detail-field="status">—</strong></div>
                <div class="t8-detail-item"><span>Responsible Officer</span><strong data-detail-field="owner">—</strong></div>
                <div class="t8-detail-item"><span>Department</span><strong data-detail-field="department">—</strong></div>
                <div class="t8-detail-item"><span>Start Date</span><strong data-detail-field="start">—</strong></div>
                <div class="t8-detail-item"><span>End Date</span><strong data-detail-field="end">—</strong></div>
                <div class="t8-detail-item" data-detail-wrap="renewal" hidden><span>Renewal Date</span><strong data-detail-field="renewal">—</strong></div>
                <div class="t8-detail-item" data-detail-wrap="amount" hidden><span>Amount</span><strong data-detail-field="amount">—</strong></div>
                <div class="t8-detail-item" data-detail-wrap="payment-frequency" hidden><span>Payment Frequency</span><strong data-detail-field="payment-frequency">—</strong></div>
                <div class="t8-detail-item" data-detail-wrap="payment-terms" hidden><span>Payment Terms</span><strong data-detail-field="payment-terms">—</strong></div>
                <div class="t8-detail-item" data-detail-wrap="deposit" hidden><span>Deposit / Advance</span><strong data-detail-field="deposit">—</strong></div>
                <div class="t8-detail-item" data-detail-wrap="notice-period" hidden><span>Notice Period</span><strong data-detail-field="notice-period">—</strong></div>
                <div class="t8-detail-item full" data-detail-wrap="description" hidden><span>Description</span><strong data-detail-field="description">—</strong></div>
                <div class="t8-detail-item full" data-detail-wrap="additional-notes" hidden><span>Additional Notes</span><strong data-detail-field="additional-notes">—</strong></div>
                <div class="t8-detail-item full" data-detail-wrap="renewedFrom" hidden><span>Renewed From</span><strong data-detail-field="renewedFrom">—</strong></div>
            </div>
        </div>
        <div class="t8-detail-footer">
            <button type="button" class="t8-btn t8-btn-outline" data-close-detail-modal>Close</button>
        </div>
    </dialog>

<?php endif; ?>
