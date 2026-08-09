<?php
/**
 * modules/contracts/index.php
 * Contract Management - Administrator only.
 *
 * Backing tables:
 *   team8_contracts            (id, owner_id, renewed_from_id, title,
 *     start_date, end_date, status, created_at, updated_at, deleted_at)
 *   team8_contract_parties     (id, contract_id, party_id, role_in_contract, created_at)
 *   team8_contract_obligations (id, contract_id, description, due_date, status, created_at, updated_at)
 *   team8_contract_documents   (id, contract_id, document_id, created_at)
 *   team8_parties              (id, name, type, contact_email, contact_phone, created_at, updated_at)
 *     - shared party directory; a party can appear on multiple contracts.
 *
 * team8_contract_obligations has no deleted_at column, so obligations
 * are hard-deleted when removed (nothing else references them).
 * Contracts/parties links use the same attach/detach pattern as
 * Legal Management's document attachments.
 *
 * Access: Administrator only.
 */

declare(strict_types=1);

t8_require_role(['admin']);

$pageTitle = 'Contract Management';
$currentUserId = t8_current_user_id();
$action = $_GET['action'] ?? 'list';
$errors = [];

if (!defined('T8_CONTRACT_STATUSES')) {
    define('T8_CONTRACT_STATUSES', ['draft', 'active', 'expired', 'terminated']);
}
if (!defined('T8_OBLIGATION_STATUSES')) {
    define('T8_OBLIGATION_STATUSES', ['pending', 'completed']);
}

function t8_contract_fetch(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT c.*, u.full_name AS owner_name, r.title AS renewed_from_title
         FROM team8_contracts c
         JOIN users u ON u.id = c.owner_id
         LEFT JOIN team8_contracts r ON r.id = c.renewed_from_id
         WHERE c.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function t8_contract_status_badge(string $status): string
{
    $map = [
        'draft'      => 't8-badge-pending',
        'active'     => 't8-badge-approved',
        'expired'    => 't8-badge-rejected',
        'terminated' => 't8-badge-rejected',
    ];
    return $map[$status] ?? 't8-badge-pending';
}

$owners = $pdo->query('SELECT id, full_name FROM users ORDER BY full_name')->fetchAll(PDO::FETCH_ASSOC);

switch ($action) {
    case 'create':
    case 'edit':
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
                'title'            => $existing['title'],
                'owner_id'         => (string) $existing['owner_id'],
                'start_date'       => $existing['start_date'],
                'end_date'         => (string) $existing['end_date'],
                'status'           => $existing['status'],
                'renewed_from_id'  => (string) ($existing['renewed_from_id'] ?? ''),
            ]
            : [
                'title' => '', 'owner_id' => (string) $currentUserId, 'start_date' => date('Y-m-d'),
                'end_date' => '', 'status' => 'draft', 'renewed_from_id' => '',
            ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $formValues = [
                'title'           => trim((string) ($_POST['title'] ?? '')),
                'owner_id'        => (string) ($_POST['owner_id'] ?? ''),
                'start_date'      => trim((string) ($_POST['start_date'] ?? '')),
                'end_date'        => trim((string) ($_POST['end_date'] ?? '')),
                'status'          => (string) ($_POST['status'] ?? 'draft'),
                'renewed_from_id' => trim((string) ($_POST['renewed_from_id'] ?? '')),
            ];

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                if ($formValues['title'] === '') {
                    $errors[] = 'Contract title is required.';
                }
                if (!$formValues['owner_id']) {
                    $errors[] = 'Please select a contract owner.';
                }
                if ($formValues['start_date'] === '' || strtotime($formValues['start_date']) === false) {
                    $errors[] = 'Start date must be a valid date.';
                }
                if ($formValues['end_date'] !== '' && strtotime($formValues['end_date']) === false) {
                    $errors[] = 'End date must be a valid date.';
                }
                if ($formValues['end_date'] !== '' && strtotime($formValues['end_date']) < strtotime($formValues['start_date'])) {
                    $errors[] = 'End date must be on or after the start date.';
                }
                if (!in_array($formValues['status'], T8_CONTRACT_STATUSES, true)) {
                    $errors[] = 'Invalid status selected.';
                }

                if (!$errors) {
                    $params = [
                        'owner_id'        => (int) $formValues['owner_id'],
                        'title'           => $formValues['title'],
                        'start_date'      => $formValues['start_date'],
                        'end_date'        => $formValues['end_date'] !== '' ? $formValues['end_date'] : null,
                        'status'          => $formValues['status'],
                        'renewed_from_id' => $formValues['renewed_from_id'] !== '' ? (int) $formValues['renewed_from_id'] : null,
                    ];

                    if ($action === 'create') {
                        $pdo->prepare(
                            'INSERT INTO team8_contracts (owner_id, renewed_from_id, title, start_date, end_date, status)
                             VALUES (:owner_id, :renewed_from_id, :title, :start_date, :end_date, :status)'
                        )->execute($params);
                        $newId = (int) $pdo->lastInsertId();
                        t8_audit_log($pdo, $currentUserId, 'contract', $newId, 'create');
                        t8_flash_set('success', 'Contract created.');
                    } else {
                        $params['id'] = $contractId;
                        $pdo->prepare(
                            'UPDATE team8_contracts SET owner_id = :owner_id, renewed_from_id = :renewed_from_id,
                             title = :title, start_date = :start_date, end_date = :end_date, status = :status WHERE id = :id'
                        )->execute($params);
                        t8_audit_log($pdo, $currentUserId, 'contract', $contractId, 'update');
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
            } elseif (!$documentId) {
                $errors[] = 'Please select a document to attach.';
            } else {
                $pdo->prepare(
                    'INSERT INTO team8_contract_documents (contract_id, document_id) VALUES (:contract_id, :document_id)'
                )->execute(['contract_id' => $contractId, 'document_id' => $documentId]);
                t8_audit_log($pdo, $currentUserId, 'contract', $contractId, 'attach_document');
                t8_flash_set('success', 'Document attached to contract.');
                redirect(page_url('contracts', ['action' => 'documents', 'id' => $contractId]));
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

        $availableDocs = $pdo->query(
            'SELECT id, title FROM team8_documents WHERE deleted_at IS NULL ORDER BY title'
        )->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'detach_document':
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
    $where = $archivedFilter ? 'c.deleted_at IS NOT NULL' : 'c.deleted_at IS NULL';
    $contracts = $pdo->query(
        "SELECT c.*, u.full_name AS owner_name
         FROM team8_contracts c
         JOIN users u ON u.id = c.owner_id
         WHERE $where
         ORDER BY c.start_date DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
}
?>
<h1>Contract Management</h1>
<p class="t8-help-text">Manage contracts, parties, obligations, and supporting documents. Administrator only.</p>

<?php foreach ($errors as $error): ?>
    <div class="t8-alert t8-alert-danger"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if ($showForm): ?>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= $action === 'edit' ? 'Edit Contract' : 'New Contract' ?></h2>
        </div>
        <form method="post"
              action="<?= e(page_url('contracts', array_filter(['action' => $action, 'id' => $_GET['id'] ?? null]))) ?>"
              novalidate>
            <?= t8_csrf_field() ?>

            <div class="t8-field">
                <label class="t8-label" for="title">Contract Title</label>
                <input class="t8-input" type="text" id="title" name="title" value="<?= e($formValues['title']) ?>" required>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="owner_id">Owner</label>
                <select class="t8-select" id="owner_id" name="owner_id" required>
                    <option value="">Select an owner…</option>
                    <?php foreach ($owners as $o): ?>
                        <option value="<?= e((string) $o['id']) ?>" <?= (string) $o['id'] === $formValues['owner_id'] ? 'selected' : '' ?>>
                            <?= e($o['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="start_date">Start Date</label>
                <input class="t8-input" type="date" id="start_date" name="start_date" value="<?= e($formValues['start_date']) ?>" required>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="end_date">End Date</label>
                <input class="t8-input" type="date" id="end_date" name="end_date" value="<?= e($formValues['end_date']) ?>">
                <span class="t8-help-text">Optional — leave blank for open-ended contracts.</span>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="status">Status</label>
                <select class="t8-select" id="status" name="status" required>
                    <?php foreach (T8_CONTRACT_STATUSES as $s): ?>
                        <option value="<?= e($s) ?>" <?= $s === $formValues['status'] ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

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

            <button class="t8-btn t8-btn-accent" type="submit">
                <i class="fa-solid fa-check"></i> <?= $action === 'edit' ? 'Save Changes' : 'Create Contract' ?>
            </button>
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('contracts')) ?>">Cancel</a>
        </form>
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
                <input class="t8-input" type="date" id="due_date" name="due_date" placeholder="Optional">
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

        <?php if ($availableDocs === []): ?>
            <div class="t8-empty">No documents exist yet. Upload one in Document Management first.</div>
        <?php else: ?>
            <form method="post" action="<?= e(page_url('contracts', ['action' => 'documents', 'id' => $contractId])) ?>"
                  style="padding: 0 var(--t8-space-4) var(--t8-space-4);" novalidate>
                <?= t8_csrf_field() ?>
                <div class="t8-field">
                    <label class="t8-label" for="document_id">Attach Document</label>
                    <select class="t8-select" id="document_id" name="document_id" required>
                        <option value="">Select a document…</option>
                        <?php foreach ($availableDocs as $d): ?>
                            <option value="<?= e((string) $d['id']) ?>"><?= e($d['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-paperclip"></i> Attach</button>
            </form>
        <?php endif; ?>

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
                                    <form method="post" action="<?= e(page_url('contracts', ['action' => 'detach_document'])) ?>"
                                          onsubmit="return confirm('Remove this document from the contract?');">
                                        <?= t8_csrf_field() ?>
                                        <input type="hidden" name="link_id" value="<?= e((string) $ad['id']) ?>">
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

<?php else: ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <a class="t8-btn t8-btn-accent" href="<?= e(page_url('contracts', ['action' => 'create'])) ?>"><i class="fa-solid fa-plus"></i> New Contract</a>
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('contracts', ['archived' => $archivedFilter ? '0' : '1'])) ?>">
            <i class="fa-solid fa-box-archive"></i> <?= $archivedFilter ? 'View Active' : 'View Archived' ?>
        </a>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= $archivedFilter ? 'Archived Contracts' : 'Contracts' ?></h2>
        </div>
        <?php if ($contracts === []): ?>
            <div class="t8-empty"><?= $archivedFilter ? 'No archived contracts.' : 'No contracts yet.' ?></div>
        <?php else: ?>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead>
                        <tr><th>Title</th><th>Owner</th><th>Start</th><th>End</th><th>Status</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contracts as $c): ?>
                            <tr>
                                <td><?= e($c['title']) ?></td>
                                <td><?= e($c['owner_name']) ?></td>
                                <td><?= e(format_date($c['start_date'], 'M d, Y')) ?></td>
                                <td><?= $c['end_date'] ? e(format_date($c['end_date'], 'M d, Y')) : '—' ?></td>
                                <td><span class="t8-badge <?= t8_contract_status_badge($c['status']) ?>"><?= e(ucfirst($c['status'])) ?></span></td>
                                <td style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('contracts', ['action' => 'parties', 'id' => $c['id']])) ?>"><i class="fa-solid fa-users"></i> Parties</a>
                                    <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('contracts', ['action' => 'obligations', 'id' => $c['id']])) ?>"><i class="fa-solid fa-list-check"></i> Obligations</a>
                                    <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('contracts', ['action' => 'documents', 'id' => $c['id']])) ?>"><i class="fa-solid fa-paperclip"></i> Documents</a>
                                    <?php if (!$archivedFilter): ?>
                                        <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('contracts', ['action' => 'edit', 'id' => $c['id']])) ?>"><i class="fa-solid fa-pen"></i> Edit</a>
                                        <form method="post" action="<?= e(page_url('contracts', ['action' => 'archive'])) ?>" onsubmit="return confirm('Archive this contract?');">
                                            <?= t8_csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $c['id']) ?>">
                                            <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit"><i class="fa-solid fa-box-archive"></i> Archive</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="<?= e(page_url('contracts', ['action' => 'restore'])) ?>">
                                            <?= t8_csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $c['id']) ?>">
                                            <button class="t8-btn t8-btn-success t8-btn-sm" type="submit"><i class="fa-solid fa-rotate-left"></i> Restore</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php endif; ?>