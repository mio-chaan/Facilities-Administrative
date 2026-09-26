<?php
/**
 * modules/legal/index.php
 * Legal Management - administrators manage cases; assigned Legal Officers
 * have read-only access only to their own cases.
 *
 * PHASE 3 (Document/Legal/Contract/Retention rebuild):
 *   - Legal Cases get the same computed "monitoring" sub-state as
 *     Contracts (Phase 2), driven off the existing `deadline` column:
 *     an open/under_review case within 90 days of its deadline shows
 *     a Monitoring badge, exactly mirroring
 *     t8_contract_is_monitoring() in modules/contracts/index.php.
 *   - A case is registered under retention automatically the moment
 *     it first reaches 'closed' status, anchored to the new
 *     `closed_at` timestamp (see
 *     database/migrations/2026_09_11_legal_case_closed_at.sql -
 *     updated_at was not safe to use, since it changes on every edit,
 *     not only the closure event).
 *   - Legal Case disposal is DUAL CONTROL: the confirmed plan requires
 *     the requester and the authorizer to be two different
 *     administrators, given the case's evidentiary/legal significance.
 *     This is enforced in app/includes/retention_helpers.php's
 *     t8_retention_authorize_disposal() (Phase 1) - server-side, not
 *     only in this file's UI. This file's job is to expose that
 *     workflow clearly and disable the "wrong" button as a courtesy,
 *     not as the actual control.
 *
 * Backing tables:
 *   team8_legal_cases     (id, assigned_to, contract_id, title, status,
 *     filed_date, deadline, closed_at, created_at, updated_at, deleted_at)
 *   team8_legal_documents (id, case_id, document_id, description,
 *     created_at) - links an existing Document Management document to
 *     a case; attaching/removing here never touches the document
 *     itself, only the link row.
 *   team8_records         (polymorphic retention table - Phase 1)
 *
 * contract_id is nullable and intentionally left unset by this form
 * for now - it gets wired up once Contract Management exists.
 *
 * Access: Administrator only for mutations. Legal Officers see only
 * their own assigned cases, read-only plus document attachment.
 */

declare(strict_types=1);

// Retention registration + disposal workflow hook (Phase 1/3 of the
// Document/Legal/Contract/Retention rebuild) - defensive require,
// same pattern as ai_helper.php's require in documents/index.php.
$retentionHelperPath = __DIR__ . '/../../app/includes/retention_helpers.php';
if (is_file($retentionHelperPath)) {
    require_once $retentionHelperPath;
}
require_once __DIR__ . '/../../app/includes/legal_case_helpers.php';
require_once __DIR__ . '/../../app/includes/legal_case_list.php';

t8_require_role(['admin', 'legal_officer']);

$pageTitle = 'Legal Management';
$currentUserId = t8_current_user_id();
$isAdmin = t8_has_role('admin');
$action = $_GET['action'] ?? 'list';
$errors = [];

/** Allow the module to remain readable until its additive migration is run. */
function t8_legal_has_case_metadata(PDO $pdo): bool
{
    try {
        return (bool) $pdo->query("SHOW COLUMNS FROM team8_legal_cases LIKE 'department_id'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

/** Guards against modules/legal/index.php running before the Phase 3 migration lands. */
function t8_legal_has_closed_at(PDO $pdo): bool
{
    try {
        return (bool) $pdo->query("SHOW COLUMNS FROM team8_legal_cases LIKE 'closed_at'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function t8_legal_has_priority(PDO $pdo): bool
{
    try {
        return (bool) $pdo->query("SHOW COLUMNS FROM team8_legal_cases LIKE 'priority'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function t8_legal_has_case_creation_fields(PDO $pdo): bool
{
    try {
        return (bool) $pdo->query("SHOW COLUMNS FROM team8_legal_cases LIKE 'case_number'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

$legalHasCaseMetadata = t8_legal_has_case_metadata($pdo);
$legalHasClosedAt = t8_legal_has_closed_at($pdo);
$legalHasPriority = t8_legal_has_priority($pdo);
$legalHasCaseCreationFields = t8_legal_has_case_creation_fields($pdo);
$legalPriorities = ['low', 'medium', 'high', 'urgent'];
$legalStatuses = $pdo->query(
    'SELECT status_code FROM team8_legal_case_statuses WHERE is_active = 1 ORDER BY sort_order'
)->fetchAll(PDO::FETCH_COLUMN);
$legalEditableStatuses = array_values(array_filter($legalStatuses, static fn (string $status): bool => $status !== 'archived'));
$legalCaseTypes = $pdo->query(
    'SELECT id, type_code, name, sort_order, is_active FROM team8_legal_case_types WHERE is_active = 1 ORDER BY sort_order, name'
)->fetchAll(PDO::FETCH_ASSOC);

/** Fetch one legal case with assignee name, or null. */
function t8_legal_case_fetch(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT lc.*, u.full_name AS assigned_to_name
         FROM team8_legal_cases lc
         JOIN users u ON u.id = lc.assigned_to
         WHERE lc.id = :id' . (t8_has_role('admin') ? '' : ' AND lc.assigned_to = :assigned_to') . ' LIMIT 1'
    );
    $params = ['id' => $id];
    if (!t8_has_role('admin')) { $params['assigned_to'] = t8_current_user_id(); }
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * MONITORING (computed, not stored) - mirrors
 * t8_contract_is_monitoring() in modules/contracts/index.php exactly:
 * an open/under_review case within $withinDays of its deadline shows
 * the same visual sub-badge treatment, driven off a real date instead
 * of a second stored status that could drift out of sync.
 */
function t8_legal_is_monitoring(array $case, int $withinDays = 90): bool
{
    if (!in_array($case['status'] ?? '', ['open', 'under_review'], true)) {
        return false;
    }
    $deadline = $case['deadline'] ?? null;
    if ($deadline === null || $deadline === '') {
        return false;
    }
    $ts = strtotime((string) $deadline);
    return $ts !== false && $ts <= strtotime("+{$withinDays} days");
}

/**
 * Registers a newly-closed case under retention EXACTLY ONCE - checks
 * t8_retention_fetch_for_entity() first, same guard as
 * t8_contract_register_retention() in modules/contracts/index.php, so
 * a records officer's later manual override of retention_years/basis
 * is never silently clobbered by a subsequent edit to the case.
 */
function t8_legal_register_retention(PDO $pdo, array $case, int $actorId): void
{
    if (!function_exists('t8_retention_register') || !function_exists('t8_retention_fetch_for_entity')) {
        return; // retention_helpers.php not present yet (Phase 1 not deployed)
    }
    if (t8_retention_fetch_for_entity($pdo, 'legal_case', (int) $case['id']) !== null) {
        return; // already registered - never overwrite a manual override
    }

    $clockStart = $case['closed_at'] ?? date('Y-m-d H:i:s');
    t8_retention_register(
        $pdo,
        'legal_case',
        (int) $case['id'],
        'General civil prescription period (Civil Code Arts. 1139-1155) + records retention practice',
        10,
        (string) $clockStart,
        $actorId
    );
    t8_audit_log($pdo, $actorId, 'legal_case', (int) $case['id'], 'retention_registered');
}

$assignees = $pdo->query('SELECT id, full_name FROM users ORDER BY full_name')->fetchAll(PDO::FETCH_ASSOC);
$departments = $legalHasCaseMetadata ? $pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) : [];

switch ($action) {
    case 'case_types':
        t8_require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                $typeAction = (string) ($_POST['type_action'] ?? '');
                $typeId = (int) ($_POST['type_id'] ?? 0);

                if ($typeAction === 'save') {
                    $name = trim((string) ($_POST['name'] ?? ''));
                    $sortOrder = filter_var($_POST['sort_order'] ?? null, FILTER_VALIDATE_INT);
                    if ($name === '' || mb_strlen($name) > 100) {
                        $errors[] = 'Enter a case type name of 1 to 100 characters.';
                    }
                    if ($sortOrder === false || $sortOrder < 0 || $sortOrder > 65535) {
                        $errors[] = 'Sort order must be between 0 and 65535.';
                    }

                    if (!$errors) {
                        try {
                            if ($typeId > 0) {
                                $stmt = $pdo->prepare('UPDATE team8_legal_case_types SET name = :name, sort_order = :sort_order WHERE id = :id');
                                $stmt->execute(['name' => $name, 'sort_order' => $sortOrder, 'id' => $typeId]);
                                t8_audit_log($pdo, $currentUserId, 'legal_case_type', $typeId, 'update');
                            } else {
                                $typeCode = t8_legal_case_type_code_from_name($name);
                                if ($typeCode === '') {
                                    throw new InvalidArgumentException('Use a name containing letters or numbers.');
                                }
                                $stmt = $pdo->prepare('INSERT INTO team8_legal_case_types (type_code, name, sort_order) VALUES (:type_code, :name, :sort_order)');
                                $stmt->execute(['type_code' => $typeCode, 'name' => $name, 'sort_order' => $sortOrder]);
                                $typeId = (int) $pdo->lastInsertId();
                                t8_audit_log($pdo, $currentUserId, 'legal_case_type', $typeId, 'create');
                            }
                            t8_flash_set('success', 'Case type saved.');
                            redirect(page_url('legal', ['action' => 'case_types']));
                        } catch (InvalidArgumentException $e) {
                            $errors[] = $e->getMessage();
                        } catch (PDOException $e) {
                            $errors[] = 'A case type with that name or generated key already exists.';
                        }
                    }
                } elseif (in_array($typeAction, ['deactivate', 'activate'], true)) {
                    $stmt = $pdo->prepare('SELECT type_code FROM team8_legal_case_types WHERE id = :id');
                    $stmt->execute(['id' => $typeId]);
                    $typeCode = $stmt->fetchColumn();
                    if ($typeCode === false) {
                        $errors[] = 'Case type not found.';
                    } elseif ($typeAction === 'deactivate' && !t8_legal_case_type_can_deactivate((string) $typeCode)) {
                        $errors[] = 'The Other case type cannot be deactivated.';
                    } else {
                        $stmt = $pdo->prepare('UPDATE team8_legal_case_types SET is_active = :is_active WHERE id = :id');
                        $stmt->execute(['is_active' => $typeAction === 'activate' ? 1 : 0, 'id' => $typeId]);
                        t8_audit_log($pdo, $currentUserId, 'legal_case_type', $typeId, $typeAction);
                        t8_flash_set('success', $typeAction === 'activate' ? 'Case type activated.' : 'Case type deactivated.');
                        redirect(page_url('legal', ['action' => 'case_types']));
                    }
                } else {
                    $errors[] = 'Invalid case type action.';
                }
            }
        }
        $caseTypesForAdmin = $pdo->query(
            'SELECT ct.*, (SELECT COUNT(*) FROM team8_legal_cases lc WHERE lc.case_type_id = ct.id) AS case_count
             FROM team8_legal_case_types ct ORDER BY ct.sort_order, ct.name'
        )->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'create':
    case 'edit':
        t8_require_role(['admin']);
        $caseId = $action === 'edit' ? (int) ($_GET['id'] ?? 0) : 0;
        $existing = $caseId ? t8_legal_case_fetch($pdo, $caseId) : null;
        if ($action === 'edit' && !$existing) {
            t8_flash_set('danger', 'Legal case not found.');
            redirect(page_url('legal'));
        }
        if ($action === 'edit' && $existing['status'] === 'archived') {
            t8_flash_set('danger', 'Archived cases must be restored before they can be edited.');
            redirect(page_url('legal', ['archived' => '1']));
        }

        $formValues = $existing !== null
            ? [
                'title'       => $existing['title'],
                'subject'     => (string) ($existing['subject'] ?? ''),
                'description' => (string) ($existing['description'] ?? ''),
                'case_type_id' => (string) $existing['case_type_id'],
                'department_id' => (string) ($existing['department_id'] ?? ''),
                'status'      => $existing['status'],
                'priority'    => $legalHasPriority ? (string) ($existing['priority'] ?? 'medium') : 'medium',
                'filed_date'  => $existing['filed_date'],
                'deadline'    => (string) ($existing['deadline'] ?? ''),
                'next_action_date' => (string) ($existing['next_action_date'] ?? ''),
                'closing_date' => (string) ($existing['closing_date'] ?? ''),
                'assigned_to' => (string) $existing['assigned_to'],
                'supporting_staff_id' => (string) ($existing['supporting_staff_id'] ?? ''),
            ]
            : ['title' => '', 'subject' => '', 'description' => '', 'case_type_id' => '', 'department_id' => '', 'status' => 'open', 'priority' => 'medium', 'filed_date' => date('Y-m-d'), 'deadline' => '', 'next_action_date' => '', 'closing_date' => '', 'assigned_to' => (string) $currentUserId, 'supporting_staff_id' => ''];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $formValues = [
                'title'       => trim((string) ($_POST['title'] ?? '')),
                'subject'     => trim((string) ($_POST['subject'] ?? '')),
                'description' => trim((string) ($_POST['description'] ?? '')),
                'case_type_id' => trim((string) ($_POST['case_type_id'] ?? '')),
                'department_id' => (string) ($_POST['department_id'] ?? ''),
                'status'      => (string) ($_POST['status'] ?? ''),
                'priority'    => (string) ($_POST['priority'] ?? $formValues['priority']),
                'filed_date'  => trim((string) ($_POST['filed_date'] ?? '')),
                'deadline'    => trim((string) ($_POST['deadline'] ?? '')),
                'next_action_date' => trim((string) ($_POST['next_action_date'] ?? '')),
                'closing_date' => trim((string) ($_POST['closing_date'] ?? '')),
                'assigned_to' => (string) ($_POST['assigned_to'] ?? ''),
                'supporting_staff_id' => (string) ($_POST['supporting_staff_id'] ?? ''),
            ];

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                if (!$legalHasCaseCreationFields || !$legalHasCaseMetadata || !$legalHasPriority) {
                    $errors[] = 'Apply the legal case creation migration before creating or editing cases.';
                }
                if ($formValues['title'] === '' || mb_strlen($formValues['title']) > 200) {
                    $errors[] = 'Case title is required and must be 200 characters or fewer.';
                }
                $unchangedInactiveStatus = $existing !== null
                    && $formValues['status'] === $existing['status']
                    && !in_array($existing['status'], $legalEditableStatuses, true);
                if (!in_array($formValues['status'], $legalEditableStatuses, true) && !$unchangedInactiveStatus) {
                    $errors[] = 'Invalid status selected.';
                }
                if ($legalHasPriority && !in_array($formValues['priority'], $legalPriorities, true)) {
                    $errors[] = 'Select a valid case priority.';
                }
                if (!t8_legal_is_valid_iso_date($formValues['filed_date'])) {
                    $errors[] = 'Filed date must be a valid date.';
                }
                $caseTypeId = (int) $formValues['case_type_id'];
                if ($formValues['case_type_id'] === '' && $existing !== null) {
                    $caseType = $pdo->prepare('SELECT is_active FROM team8_legal_case_types WHERE id = :id');
                    $caseType->execute(['id' => (int) $existing['case_type_id']]);
                    if (!(bool) $caseType->fetchColumn()) {
                        $caseTypeId = (int) $existing['case_type_id'];
                    }
                }
                $caseType = $pdo->prepare('SELECT id, is_active FROM team8_legal_case_types WHERE id = :id');
                $caseType->execute(['id' => $caseTypeId]);
                $selectedCaseType = $caseType->fetch(PDO::FETCH_ASSOC);
                if ((!$selectedCaseType || !t8_legal_case_type_is_selectable($selectedCaseType))
                    && !($existing !== null && $caseTypeId === (int) $existing['case_type_id'] && $formValues['case_type_id'] === '')
                ) {
                    $errors[] = 'Select an active legal case type.';
                }
                foreach (['deadline' => 'Deadline', 'next_action_date' => 'Next action date', 'closing_date' => 'Closing date'] as $dateField => $dateLabel) {
                    if ($formValues[$dateField] !== '' && !t8_legal_is_valid_iso_date($formValues[$dateField])) {
                        $errors[] = $dateLabel . ' must be a valid date.';
                    } elseif ($formValues[$dateField] !== ''
                        && t8_legal_is_valid_iso_date($formValues['filed_date'])
                        && !t8_legal_case_date_is_not_before_filed($formValues['filed_date'], $formValues[$dateField])
                    ) {
                        $errors[] = $dateLabel . ' cannot be earlier than the Filed Date.';
                    }
                }
                if ($formValues['deadline'] !== '' && t8_legal_is_valid_iso_date($formValues['deadline'])) {
                    $deadlineTs = strtotime($formValues['deadline']);
                    $todayTs    = strtotime(date('Y-m-d'));

                    if ($deadlineTs < $todayTs) {
                        $errors[] = 'Deadline cannot be in the past.';
                    }
                }
                $assigneeIds = array_map('intval', array_column($assignees, 'id'));
                $assignedToId = filter_var($formValues['assigned_to'], FILTER_VALIDATE_INT);
                if ($assignedToId === false || !in_array($assignedToId, $assigneeIds, true)) {
                    $errors[] = 'Select a valid assigned legal officer.';
                }
                $departmentIds = array_map('intval', array_column($departments, 'id'));
                $departmentId = filter_var($formValues['department_id'], FILTER_VALIDATE_INT);
                if (!$legalHasCaseMetadata || $departmentId === false || !in_array($departmentId, $departmentIds, true)) {
                    $errors[] = 'Select a valid department.';
                }
                $supportingStaffId = null;
                if ($formValues['supporting_staff_id'] !== '') {
                    $supportingStaffId = filter_var($formValues['supporting_staff_id'], FILTER_VALIDATE_INT);
                    if ($supportingStaffId === false || !in_array($supportingStaffId, $assigneeIds, true)) {
                        $errors[] = 'Select a valid supporting staff member.';
                    }
                }

                if (!$errors) {
                    // Was this case ALREADY closed before this save? Only the
                    // FIRST transition into 'closed' sets closed_at and fires
                    // retention registration - re-saving an already-closed
                    // case (e.g. fixing a typo in the title) must not reset
                    // the retention clock.
                    $wasAlreadyClosed = $existing !== null && $existing['status'] === 'closed';
                    $justClosed = $legalHasClosedAt && $formValues['status'] === 'closed' && !$wasAlreadyClosed;

                    $savedCaseId = $caseId;
                    try {
                        $pdo->beginTransaction();
                        $params = [
                            'assigned_to' => (int) $assignedToId,
                            'supporting_staff_id' => $supportingStaffId === false ? null : $supportingStaffId,
                            'title' => $formValues['title'],
                            'subject' => $formValues['subject'] !== '' ? $formValues['subject'] : null,
                            'description' => $formValues['description'] !== '' ? $formValues['description'] : null,
                            'case_type_id' => $caseTypeId,
                            'department_id' => (int) $departmentId,
                            'status' => $formValues['status'],
                            'filed_date' => $formValues['filed_date'],
                            'deadline' => $formValues['deadline'] !== '' ? $formValues['deadline'] : null,
                            'next_action_date' => $formValues['next_action_date'] !== '' ? $formValues['next_action_date'] : null,
                            'closing_date' => $formValues['closing_date'] !== '' ? $formValues['closing_date'] : null,
                        ];
                        if ($legalHasPriority) {
                            $params['priority'] = $formValues['priority'];
                        }

                        if ($action === 'create') {
                            $caseYear = (int) $pdo->query('SELECT YEAR(CURRENT_DATE)')->fetchColumn();
                            $caseNumber = t8_legal_next_case_number($pdo, $caseYear);
                            $columns = 'case_number, assigned_to, supporting_staff_id, title, subject, description, case_type_id, department_id, status'
                                . ($legalHasPriority ? ', priority' : '')
                                . ', filed_date, deadline, next_action_date, closing_date'
                                . ($justClosed ? ', closed_at' : '');
                            $values = ':case_number, :assigned_to, :supporting_staff_id, :title, :subject, :description, :case_type_id, :department_id, :status'
                                . ($legalHasPriority ? ', :priority' : '')
                                . ', :filed_date, :deadline, :next_action_date, :closing_date'
                                . ($justClosed ? ', NOW()' : '');
                            $params['case_number'] = $caseNumber;
                            $pdo->prepare('INSERT INTO team8_legal_cases (' . $columns . ') VALUES (' . $values . ')')->execute($params);
                            $savedCaseId = (int) $pdo->lastInsertId();
                            t8_audit_log($pdo, $currentUserId, 'legal_case', $savedCaseId, 'create');
                        } else {
                            $params['id'] = $caseId;
                            $pdo->prepare(
                                'UPDATE team8_legal_cases SET assigned_to = :assigned_to, supporting_staff_id = :supporting_staff_id, title = :title, subject = :subject, description = :description, case_type_id = :case_type_id, department_id = :department_id, status = :status'
                                . ($legalHasPriority ? ', priority = :priority' : '')
                                . ', filed_date = :filed_date, deadline = :deadline, next_action_date = :next_action_date, closing_date = :closing_date'
                                . ($justClosed ? ', closed_at = NOW()' : '')
                                . ' WHERE id = :id'
                            )->execute($params);
                            t8_audit_log($pdo, $currentUserId, 'legal_case', $caseId, 'update');
                        }
                        $pdo->commit();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $errors[] = 'The legal case could not be saved. Please try again.';
                    }

                    if (!$errors) {
                        if ($justClosed) {
                            $savedCase = t8_legal_case_fetch($pdo, $savedCaseId);
                            if ($savedCase !== null) {
                                t8_legal_register_retention($pdo, $savedCase, $currentUserId);
                            }
                        }
                        t8_flash_set('success', $action === 'create' ? 'Legal case created.' : 'Legal case updated.');
                        redirect(page_url('legal'));
                    }
                }
            }
        }
        break;

    case 'archive':
    case 'restore':
        t8_require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('legal'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('legal'));
        }
        $id = (int) ($_POST['id'] ?? 0);
        $case = t8_legal_case_fetch($pdo, $id);
        if ($case) {
            if ($action === 'archive' && $case['status'] !== 'archived') {
                $pdo->prepare("UPDATE team8_legal_cases SET archived_from_status = status, status = 'archived' WHERE id = :id")
                    ->execute(['id' => $id]);
            } elseif ($action === 'restore' && $case['status'] === 'archived') {
                $pdo->prepare("UPDATE team8_legal_cases SET status = COALESCE(archived_from_status, 'open'), archived_from_status = NULL WHERE id = :id")
                    ->execute(['id' => $id]);
            } else {
                t8_flash_set('danger', 'The case is not in a state that can be ' . ($action === 'archive' ? 'archived.' : 'restored.'));
                redirect(page_url('legal', ['archived' => $action === 'restore' ? '1' : '0']));
            }
            t8_audit_log($pdo, $currentUserId, 'legal_case', $id, $action);
            t8_flash_set('success', $action === 'archive' ? 'Case archived.' : 'Case restored.');
        } else {
            t8_flash_set('danger', 'Legal case not found.');
        }
        redirect(page_url('legal'));
        break;

    case 'documents':
        $caseId = (int) ($_GET['id'] ?? 0);
        $case = $caseId ? t8_legal_case_fetch($pdo, $caseId) : null;
        if (!$case) {
            t8_flash_set('danger', 'Legal case not found.');
            redirect(page_url('legal'));
        }
        t8_audit_log($pdo, $currentUserId, 'legal_case', $caseId, 'view_documents');

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'attach') {
            t8_require_role(['admin']);
            $documentId = (int) ($_POST['document_id'] ?? 0);
            $description = trim((string) ($_POST['description'] ?? ''));

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } elseif (!$documentId) {
                $errors[] = 'Please select a document to attach.';
            } else {
                $pdo->prepare(
                    'INSERT INTO team8_legal_documents (case_id, document_id, description) VALUES (:case_id, :document_id, :description)'
                )->execute([
                    'case_id'     => $caseId,
                    'document_id' => $documentId,
                    'description' => $description !== '' ? $description : null,
                ]);
                t8_audit_log($pdo, $currentUserId, 'legal_case', $caseId, 'attach_document');
                t8_flash_set('success', 'Document attached to case.');
                redirect(page_url('legal', ['action' => 'documents', 'id' => $caseId]));
            }
        }

        $attachedDocs = $pdo->prepare(
            'SELECT ld.*, d.title AS document_title, v.id AS version_id
             FROM team8_legal_documents ld
             JOIN team8_documents d ON d.id = ld.document_id
             JOIN team8_document_versions v ON v.document_id = d.id AND v.version_no = d.current_version
             WHERE ld.case_id = :case_id
             ORDER BY ld.created_at DESC'
        );
        $attachedDocs->execute(['case_id' => $caseId]);
        $attachedDocs = $attachedDocs->fetchAll(PDO::FETCH_ASSOC);

        $availableDocs = $pdo->query(
            'SELECT id, title FROM team8_documents WHERE deleted_at IS NULL ORDER BY title'
        )->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'detach_document':
        t8_require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('legal'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('legal'));
        }
        $linkId = (int) ($_POST['link_id'] ?? 0);
        $caseId = (int) ($_POST['case_id'] ?? 0);
        $pdo->prepare('DELETE FROM team8_legal_documents WHERE id = :id AND case_id = :case_id')
            ->execute(['id' => $linkId, 'case_id' => $caseId]);
        t8_audit_log($pdo, $currentUserId, 'legal_case', $caseId, 'detach_document');
        t8_flash_set('success', 'Document removed from case.');
        redirect(page_url('legal', ['action' => 'documents', 'id' => $caseId]));
        break;

    // ---------------------------------------------------------------
    // PHASE 3 — Retention & disposal sub-view.
    //
    // Deliberately its own screen (like 'documents' above) rather than
    // buttons crammed into the row menu - a dual-control disposal
    // action deserves its own confirmation surface, not a one-click
    // dropdown item.
    // ---------------------------------------------------------------
    case 'retention':
        $caseId = (int) ($_GET['id'] ?? 0);
        $case = $caseId ? t8_legal_case_fetch($pdo, $caseId) : null;
        if (!$case) {
            t8_flash_set('danger', 'Legal case not found.');
            redirect(page_url('legal'));
        }
        $retentionRecord = function_exists('t8_retention_fetch_for_entity')
            ? t8_retention_fetch_for_entity($pdo, 'legal_case', $caseId)
            : null;
        break;

    case 'retention_archive':
        t8_require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('legal'));
        }
        $recordId = (int) ($_POST['record_id'] ?? 0);
        $caseId = (int) ($_POST['case_id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($reason === '' || !function_exists('t8_retention_archive')) {
            t8_flash_set('danger', 'An archive reason is required.');
        } elseif (t8_retention_archive($pdo, $recordId, $reason)) {
            t8_audit_log($pdo, $currentUserId, 'legal_case', $caseId, 'retention_archived', null, $reason);
            t8_flash_set('success', 'Retention record archived.');
        } else {
            t8_flash_set('danger', 'That retention record could not be archived from its current state.');
        }
        redirect(page_url('legal', ['action' => 'retention', 'id' => $caseId]));
        break;

    case 'retention_dispose_request':
        t8_require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('legal'));
        }
        $recordId = (int) ($_POST['record_id'] ?? 0);
        $caseId = (int) ($_POST['case_id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($reason === '' || !function_exists('t8_retention_request_disposal')) {
            t8_flash_set('danger', 'A disposal reason is required.');
        } elseif (t8_retention_request_disposal($pdo, $recordId, $currentUserId, $reason)) {
            t8_audit_log($pdo, $currentUserId, 'legal_case', $caseId, 'disposal_requested', null, $reason);
            t8_flash_set('success', 'Disposal requested. A DIFFERENT administrator must authorize it before it is disposed.');
        } else {
            t8_flash_set('danger', 'That retention record is not in a state that can be requested for disposal.');
        }
        redirect(page_url('legal', ['action' => 'retention', 'id' => $caseId]));
        break;

    case 'retention_dispose_authorize':
        t8_require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('legal'));
        }
        $recordId = (int) ($_POST['record_id'] ?? 0);
        $caseId = (int) ($_POST['case_id'] ?? 0);
        if (!function_exists('t8_retention_authorize_disposal')) {
            t8_flash_set('danger', 'Retention features are not available yet.');
            redirect(page_url('legal', ['action' => 'retention', 'id' => $caseId]));
        }
        // DUAL CONTROL: t8_retention_authorize_disposal() itself rejects
        // this if $currentUserId === the row's disposal_requested_by -
        // that check happens server-side inside the helper, not here.
        // This call site does not (and must not) attempt to duplicate
        // or second-guess that check.
        $result = t8_retention_authorize_disposal($pdo, $recordId, $currentUserId);
        if ($result['ok']) {
            t8_audit_log($pdo, $currentUserId, 'legal_case', $caseId, 'disposed');
            t8_flash_set('success', 'Legal case record disposed.');
        } else {
            t8_flash_set('danger', (string) $result['error']);
        }
        redirect(page_url('legal', ['action' => 'retention', 'id' => $caseId]));
        break;
}

$showForm = in_array($action, ['create', 'edit'], true);
$showDocuments = $action === 'documents';
$showRetention = $action === 'retention';
$showCaseTypes = $action === 'case_types';
$showList = !$showForm && !$showDocuments && !$showRetention && !$showCaseTypes;

$currentCaseType = null;
if ($showForm && $existing !== null) {
    $caseTypeStmt = $pdo->prepare('SELECT id, type_code, name, is_active FROM team8_legal_case_types WHERE id = :id');
    $caseTypeStmt->execute(['id' => (int) $existing['case_type_id']]);
    $currentCaseType = $caseTypeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($showList) {
    $archivedFilter = ($_GET['archived'] ?? '0') === '1';
    $statusFilter = (string) ($_GET['status'] ?? '');
    $priorityFilter = (string) ($_GET['priority'] ?? '');
    $caseTypeFilter = filter_var($_GET['case_type'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $departmentFilter = filter_var($_GET['department'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $assignedFilter = filter_var($_GET['assigned_to'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $searchFilter = trim((string) ($_GET['search'] ?? ''));
    $filedFrom = trim((string) ($_GET['filed_from'] ?? ''));
    $filedTo = trim((string) ($_GET['filed_to'] ?? ''));
    $deadlineFrom = trim((string) ($_GET['deadline_from'] ?? ''));
    $deadlineTo = trim((string) ($_GET['deadline_to'] ?? ''));
    $page = t8_legal_page_number($_GET['page'] ?? 1);
    $pageSize = 25;
    $where = [$archivedFilter ? "lc.status = 'archived'" : "lc.status <> 'archived'"];
    $params = [];

    if (in_array($statusFilter, $legalStatuses, true)) {
        $where[] = 'lc.status = :status';
        $params['status'] = $statusFilter;
    }
    if ($legalHasPriority && in_array($priorityFilter, $legalPriorities, true)) {
        $where[] = 'lc.priority = :priority_filter';
        $params['priority_filter'] = $priorityFilter;
    }
    if ($caseTypeFilter !== false) {
        $where[] = 'lc.case_type_id = :case_type_id';
        $params['case_type_id'] = $caseTypeFilter;
    }
    if ($legalHasCaseMetadata && $departmentFilter !== false) {
        $where[] = 'lc.department_id = :department_id';
        $params['department_id'] = $departmentFilter;
    }
    if ($assignedFilter !== false) {
        $where[] = 'lc.assigned_to = :filter_assigned_to';
        $params['filter_assigned_to'] = $assignedFilter;
    }
    if ($searchFilter !== '') {
        $searchColumns = ['lc.title LIKE :search', 'CAST(lc.id AS CHAR) LIKE :search_id', 'u.full_name LIKE :search_assignee', 'ct.name LIKE :search_type'];
        if ($legalHasCaseMetadata) {
            $searchColumns[] = 'lc.subject LIKE :search_subject';
            $searchColumns[] = 'dep.name LIKE :search_department';
        }
        $where[] = '(' . implode(' OR ', $searchColumns) . ')';
        $params['search'] = '%' . $searchFilter . '%';
        $params['search_id'] = '%' . preg_replace('/^CASE-0*/', '', strtoupper($searchFilter)) . '%';
        $params['search_assignee'] = '%' . $searchFilter . '%';
        $params['search_type'] = '%' . $searchFilter . '%';
        if ($legalHasCaseMetadata) {
            $params['search_subject'] = '%' . $searchFilter . '%';
            $params['search_department'] = '%' . $searchFilter . '%';
        }
    }
    foreach ([['filed_from', 'filedFrom', '>='], ['filed_to', 'filedTo', '<='], ['deadline_from', 'deadlineFrom', '>='], ['deadline_to', 'deadlineTo', '<=']] as [$field, $variable, $operator]) {
        if ($$variable !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $$variable) === 1) {
            $where[] = 'lc.' . ($field === 'filed_from' || $field === 'filed_to' ? 'filed_date' : 'deadline') . ' ' . $operator . ' :' . $field;
            $params[$field] = $$variable;
        }
    }
    if (!$isAdmin) {
        $where[] = 'lc.assigned_to = :assigned_to';
        $params['assigned_to'] = $currentUserId;
    }

    $from = " FROM team8_legal_cases lc
         JOIN users u ON u.id = lc.assigned_to
         JOIN team8_legal_case_types ct ON ct.id = lc.case_type_id
         " . ($legalHasCaseMetadata ? 'LEFT JOIN departments dep ON dep.id = lc.department_id' : '');
    $whereSql = implode(' AND ', $where);
    $countStmt = $pdo->prepare('SELECT COUNT(*)' . $from . ' WHERE ' . $whereSql);
    $countStmt->execute($params);
    $totalCases = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalCases / $pageSize));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $pageSize;
    $stmt = $pdo->prepare(
        'SELECT lc.*, u.full_name AS assigned_to_name, ct.name AS case_type_name, ct.is_active AS case_type_active' . ($legalHasCaseMetadata ? ', dep.name AS department_name' : '') . $from .
        ' WHERE ' . $whereSql . ' ORDER BY lc.filed_date DESC, lc.id DESC LIMIT ' . $pageSize . ' OFFSET ' . $offset
    );
    $stmt->execute($params);
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $legalListFilters = array_filter([
        'archived' => $archivedFilter ? '1' : '',
        'search' => $searchFilter,
        'status' => $statusFilter,
        'priority' => $priorityFilter,
        'case_type' => $caseTypeFilter === false ? '' : (string) $caseTypeFilter,
        'department' => $departmentFilter === false ? '' : (string) $departmentFilter,
        'assigned_to' => $assignedFilter === false ? '' : (string) $assignedFilter,
        'filed_from' => $filedFrom,
        'filed_to' => $filedTo,
        'deadline_from' => $deadlineFrom,
        'deadline_to' => $deadlineTo,
    ], static fn ($value): bool => $value !== '' && $value !== null);
}

function t8_legal_status_badge(string $status): string
{
    $map = [
        'open'        => 't8-badge-pending',
        'under_review' => 't8-badge-pending',
        'active'       => 't8-badge-approved',
        'resolved'     => 't8-badge-approved',
        'closed'       => 't8-badge-archived',
        'archived'     => 't8-badge-archived',
    ];
    return $map[$status] ?? 't8-badge-pending';
}

/**
 * Renders the meatball trigger + dropdown menu for the "Legal Cases"
 * list table, plus the data-* attributes consumed by the shared
 * #t8LegalDetailModal. Same pattern as
 * t8_reservation_render_menu() in modules/reservation/index.php.
 */
function t8_legal_render_menu(array $c, bool $isAdmin, bool $archivedFilter, bool $legalHasCaseMetadata, bool $isMonitoring, ?array $retentionRecord): void
{
    $id = (int) $c['id'];
    $ref = (string) ($c['case_number'] ?? ('CASE-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT)));
    ?>
    <div class="t8-row-menu">
        <button type="button" class="t8-row-menu-trigger" aria-haspopup="true" aria-expanded="false" title="More actions"
                data-detail-modal="t8LegalDetailModal"
                data-ref="<?= e($ref) ?>"
                data-title="<?= e((string) $c['title']) ?>"
                data-subject="<?= e((string) ($c['subject'] ?? '')) ?>"
                data-department="<?= e((string) ($c['department_name'] ?? '')) ?>"
                data-status="<?= e(ucwords(str_replace('_', ' ', (string) $c['status']))) ?><?= $isMonitoring ? ' · Monitoring' : '' ?>"
                data-filed="<?= e(format_date((string) $c['filed_date'], 'M d, Y')) ?>"
                data-deadline="<?= e($legalHasCaseMetadata && !empty($c['deadline']) ? format_date((string) $c['deadline'], 'M d, Y') : '') ?>"
                data-assigned-to="<?= e((string) $c['assigned_to_name']) ?>">
            <i class="fa-solid fa-ellipsis-vertical"></i>
        </button>
        <div class="t8-row-menu-panel" role="menu">
            <button type="button" class="t8-row-menu-item t8-row-view-details" role="menuitem">
                <i class="fa-solid fa-eye"></i> View Details
            </button>
            <button type="button" class="t8-row-menu-item t8-row-copy-ref" role="menuitem" data-copy="<?= e($ref) ?>">
                <i class="fa-solid fa-copy"></i> Copy Case Ref
            </button>
            <div class="t8-row-menu-divider"></div>
            <a class="t8-row-menu-item" role="menuitem" href="<?= e(page_url('legal', ['action' => 'documents', 'id' => $id])) ?>">
                <i class="fa-solid fa-paperclip"></i> Documents
            </a>
            <?php if ($c['status'] === 'closed'): ?>
                <a class="t8-row-menu-item" role="menuitem" href="<?= e(page_url('legal', ['action' => 'retention', 'id' => $id])) ?>">
                    <i class="fa-solid fa-box-archive"></i> <?= $retentionRecord !== null ? 'Manage Retention' : 'View Retention' ?>
                </a>
            <?php endif; ?>
            <?php if ($isAdmin && !$archivedFilter): ?>
                <div class="t8-row-menu-divider"></div>
                <a class="t8-row-menu-item" role="menuitem" href="<?= e(page_url('legal', ['action' => 'edit', 'id' => $id])) ?>">
                    <i class="fa-solid fa-pen"></i> Edit
                </a>
                <form method="post" action="<?= e(page_url('legal', ['action' => 'archive'])) ?>" onsubmit="return confirm('Archive this case?');">
                    <?= t8_csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <button class="t8-row-menu-item t8-danger" type="submit" role="menuitem">
                        <i class="fa-solid fa-box-archive"></i> Archive
                    </button>
                </form>
            <?php elseif ($isAdmin): ?>
                <div class="t8-row-menu-divider"></div>
                <form method="post" action="<?= e(page_url('legal', ['action' => 'restore'])) ?>">
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
?>
<h1>Legal Management</h1>
<p class="t8-help-text"><?= $isAdmin ? 'Track legal cases and their supporting documents.' : 'View legal cases assigned to you.' ?></p>

<?php foreach ($errors as $error): ?>
    <div class="t8-alert t8-alert-danger"><?= e($error) ?></div>
<?php endforeach; ?>
<?php if ($showForm && (!$legalHasCaseCreationFields || !$legalHasCaseMetadata || !$legalHasPriority)): ?>
    <div class="t8-alert t8-alert-danger">The legal case creation migration must be applied before this form can be used.</div>
<?php endif; ?>

<?php if ($showForm): ?>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= $action === 'edit' ? 'Edit Legal Case' : 'New Legal Case' ?></h2>
        </div>
        <form method="post"
              action="<?= e(page_url('legal', array_filter(['action' => $action, 'id' => $_GET['id'] ?? null]))) ?>"
              class="t8-legal-form-grid"
              novalidate>
            <p class="t8-help-text t8-required-legend">* Required field</p>
            <?= t8_csrf_field() ?>

            <div class="t8-field">
                <label class="t8-label" for="case_number">Case Number</label>
                <input class="t8-input" type="text" id="case_number" value="<?= $action === 'edit' ? e((string) ($existing['case_number'] ?? ('CASE-' . str_pad((string) $existing['id'], 6, '0', STR_PAD_LEFT)))) : 'Generated when saved' ?>" readonly>
            </div>

            <div class="t8-field t8-form-span-2">
                <label class="t8-label" for="title">Case Title <span class="t8-required">*</span></label>
                <input class="t8-input" type="text" id="title" name="title"
                       value="<?= e($formValues['title']) ?>" maxlength="200" required>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="case_type_id">Case Type <span class="t8-required">*</span></label>
                <?php if ($currentCaseType !== null && !(bool) $currentCaseType['is_active']): ?>
                    <span class="t8-help-text">Existing type: <?= e($currentCaseType['name']) ?> (inactive; retained on this case).</span>
                    <select class="t8-select" id="case_type_id" name="case_type_id">
                        <option value="" <?= $formValues['case_type_id'] === '' || $formValues['case_type_id'] === (string) $existing['case_type_id'] ? 'selected' : '' ?>>Keep existing type</option>
                        <?php foreach ($legalCaseTypes as $type): ?>
                            <option value="<?= e((string) $type['id']) ?>" <?= (string) $type['id'] === $formValues['case_type_id'] ? 'selected' : '' ?>><?= e($type['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <select class="t8-select" id="case_type_id" name="case_type_id" required>
                        <option value="">Select a case type</option>
                        <?php foreach ($legalCaseTypes as $type): ?>
                            <option value="<?= e((string) $type['id']) ?>" <?= (string) $type['id'] === $formValues['case_type_id'] ? 'selected' : '' ?>><?= e($type['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <?php if ($legalHasCaseMetadata): ?><div class="t8-field">
                <label class="t8-label" for="subject">Subject</label>
                <input class="t8-input" type="text" id="subject" name="subject" value="<?= e($formValues['subject']) ?>">
            </div>

            <div class="t8-field t8-form-span-2">
                <label class="t8-label" for="description">Description / Summary</label>
                <textarea class="t8-input" id="description" name="description" rows="4"><?= e($formValues['description']) ?></textarea>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="department_id">Department <span class="t8-required">*</span></label>
                <select class="t8-select" id="department_id" name="department_id" required><option value="">Select a department</option><?php foreach ($departments as $department): ?><option value="<?= e((string) $department['id']) ?>" <?= (string) $department['id'] === $formValues['department_id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select>
            </div><?php endif; ?>

            <div class="t8-field">
                <label class="t8-label" for="status">Status <span class="t8-required">*</span></label>
                <select class="t8-select" id="status" name="status" required data-current-status="<?= e((string) ($existing['status'] ?? $formValues['status'])) ?>">
                    <?php if (!in_array($formValues['status'], $legalEditableStatuses, true)): ?>
                        <option value="<?= e($formValues['status']) ?>" selected><?= e(ucwords(str_replace('_', ' ', $formValues['status']))) ?> (inactive)</option>
                    <?php endif; ?>
                    <?php foreach ($legalEditableStatuses as $s): ?>
                        <option value="<?= e($s) ?>" <?= $s === $formValues['status'] ? 'selected' : '' ?>>
                            <?= e(ucwords(str_replace('_', ' ', $s))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($existing !== null && $existing['status'] === 'closed'): ?>
                    <span class="t8-help-text">This case is already closed<?= !empty($existing['closed_at']) ? ' (on ' . e(format_date((string) $existing['closed_at'], 'M d, Y')) . ')' : '' ?> and under retention. Manage disposal from its Retention screen, not by changing status here.</span>
                <?php elseif ($existing !== null): ?>
                    <span class="t8-help-text">Setting this to "Closed" registers the case under retention automatically.</span>
                <?php endif; ?>
            </div>

            <?php if ($legalHasPriority): ?><div class="t8-field">
                <label class="t8-label" for="priority">Priority <span class="t8-required">*</span></label>
                <select class="t8-select" id="priority" name="priority" required>
                    <?php foreach ($legalPriorities as $priority): ?>
                        <option value="<?= e($priority) ?>" <?= $priority === $formValues['priority'] ? 'selected' : '' ?>><?= e(ucfirst($priority)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div><?php endif; ?>

            <div class="t8-field">
                <label class="t8-label" for="filed_date">Filed Date <span class="t8-required">*</span></label>
                <input class="t8-input" type="date" id="filed_date" name="filed_date"
                       value="<?= e($formValues['filed_date']) ?>" required>
            </div>

            <?php if ($legalHasCaseMetadata): ?><div class="t8-field">
                <label class="t8-label" for="deadline">Deadline</label>
                <input class="t8-input" type="date" id="deadline" name="deadline" value="<?= e($formValues['deadline']) ?>" data-t8-date-rule="future">
            </div><?php endif; ?>

            <?php if ($legalHasCaseMetadata): ?>
                <div class="t8-field">
                    <label class="t8-label" for="next_action_date">Next Action Date</label>
                    <input class="t8-input" type="date" id="next_action_date" name="next_action_date" value="<?= e($formValues['next_action_date']) ?>" min="<?= e($formValues['filed_date']) ?>">
                </div>
                <div class="t8-field">
                    <label class="t8-label" for="closing_date">Closing Date</label>
                    <input class="t8-input" type="date" id="closing_date" name="closing_date" value="<?= e($formValues['closing_date']) ?>" min="<?= e($formValues['filed_date']) ?>">
                </div>
            <script>
                document.addEventListener("DOMContentLoaded", function () {
                    var filedDate = document.getElementById("filed_date");
                    var dateFields = ["deadline", "next_action_date", "closing_date"]
                        .map(function (id) { return document.getElementById(id); })
                        .filter(Boolean);
                    if (!filedDate || dateFields.length === 0) {
                        return;
                    }

                    function syncDateMinimums() {
                        var today = new Date();
                        var todayValue = today.getFullYear() + "-" + String(today.getMonth() + 1).padStart(2, "0") + "-" + String(today.getDate()).padStart(2, "0");
                        dateFields.forEach(function (dateField) {
                            dateField.min = dateField.id === "deadline" && filedDate.value < todayValue
                                ? todayValue
                                : filedDate.value;
                        });
                    }

                    filedDate.addEventListener("change", syncDateMinimums);
                    syncDateMinimums();
                    window.setTimeout(syncDateMinimums, 0);
                });
            </script>
            <?php endif; ?>

            <div class="t8-field">
                <label class="t8-label" for="assigned_to">Assigned Legal Officer <span class="t8-required">*</span></label>
                <select class="t8-select" id="assigned_to" name="assigned_to" required>
                    <option value="">Select a person…</option>
                    <?php foreach ($assignees as $a): ?>
                        <option value="<?= e((string) $a['id']) ?>" <?= (string) $a['id'] === $formValues['assigned_to'] ? 'selected' : '' ?>>
                            <?= e($a['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="supporting_staff_id">Supporting Staff</label>
                <select class="t8-select" id="supporting_staff_id" name="supporting_staff_id">
                    <option value="">None</option>
                    <?php foreach ($assignees as $a): ?>
                        <option value="<?= e((string) $a['id']) ?>" <?= (string) $a['id'] === $formValues['supporting_staff_id'] ? 'selected' : '' ?>>
                            <?= e($a['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="t8-form-actions t8-legal-form-actions">
                <button class="t8-btn t8-btn-accent" type="submit">
                    <i class="fa-solid fa-check"></i> <?= $action === 'edit' ? 'Save Changes' : 'Create Case' ?>
                </button>
                <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal')) ?>">Cancel</a>
            </div>
        </form>
    </div>

    <?php if ($action === 'edit'): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const form = document.querySelector('.t8-legal-form-grid');
                const statusSelect = document.getElementById('status');
                if (!form || !statusSelect) {
                    return;
                }

                const currentStatus = statusSelect.dataset.currentStatus || statusSelect.value;
                form.addEventListener('submit', function (event) {
                    const nextStatus = statusSelect.value;
                    if (currentStatus !== 'closed' && nextStatus === 'closed') {
                        const confirmed = window.confirm('Closing this case will register it under retention. Do you want to continue?');
                        if (!confirmed) {
                            event.preventDefault();
                        }
                    }
                });
            });
        </script>
    <?php endif; ?>

<?php elseif ($showCaseTypes): ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal')) ?>"><i class="fa-solid fa-arrow-left"></i> Back to Cases</a>
    </div>
    <div class="t8-card">
        <div class="t8-card-header"><h2 class="t8-card-title">Legal Case Types</h2></div>
        <form method="post" action="<?= e(page_url('legal', ['action' => 'case_types'])) ?>" class="t8-legal-form-grid">
            <?= t8_csrf_field() ?>
            <input type="hidden" name="type_action" value="save">
            <div class="t8-field"><label class="t8-label" for="new_type_name">New Type</label><input class="t8-input" id="new_type_name" name="name" maxlength="100" required></div>
            <div class="t8-field"><label class="t8-label" for="new_type_order">Display Order</label><input class="t8-input" id="new_type_order" name="sort_order" type="number" min="0" max="65535" value="100" required></div>
            <div class="t8-form-actions"><button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-plus"></i> Add Type</button></div>
        </form>
        <div class="t8-table-wrap">
            <table class="t8-table">
                <thead><tr><th>Type</th><th>Key</th><th>Cases</th><th>State</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($caseTypesForAdmin as $type): ?>
                        <tr>
                            <td>
                                <form method="post" action="<?= e(page_url('legal', ['action' => 'case_types'])) ?>" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                    <?= t8_csrf_field() ?>
                                    <input type="hidden" name="type_action" value="save">
                                    <input type="hidden" name="type_id" value="<?= e((string) $type['id']) ?>">
                                    <input class="t8-input" name="name" maxlength="100" value="<?= e($type['name']) ?>" required aria-label="Type name">
                                    <input class="t8-input" name="sort_order" type="number" min="0" max="65535" value="<?= e((string) $type['sort_order']) ?>" required aria-label="Display order" style="max-width:100px;">
                                    <button class="t8-btn t8-btn-outline" type="submit"><i class="fa-solid fa-check"></i> Save</button>
                                </form>
                            </td>
                            <td><code><?= e($type['type_code']) ?></code></td>
                            <td><?= e((string) $type['case_count']) ?></td>
                            <td><?= (bool) $type['is_active'] ? 'Active' : 'Inactive' ?></td>
                            <td>
                                <form method="post" action="<?= e(page_url('legal', ['action' => 'case_types'])) ?>">
                                    <?= t8_csrf_field() ?>
                                    <input type="hidden" name="type_action" value="<?= (bool) $type['is_active'] ? 'deactivate' : 'activate' ?>">
                                    <input type="hidden" name="type_id" value="<?= e((string) $type['id']) ?>">
                                    <button class="t8-btn t8-btn-outline" type="submit" <?= $type['type_code'] === 'other' ? 'disabled title="Other cannot be deactivated"' : '' ?>>
                                        <i class="fa-solid <?= (bool) $type['is_active'] ? 'fa-ban' : 'fa-rotate-left' ?>"></i>
                                        <?= (bool) $type['is_active'] ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="t8-help-text">Types are never deleted. Deactivated types remain assigned to existing cases but are unavailable for new selections. Other cannot be deactivated.</p>
    </div>

<?php elseif ($showDocuments): ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal')) ?>">
            <i class="fa-solid fa-arrow-left"></i> Back to Cases
        </a>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= e($case['title']) ?> — Attached Documents</h2>
        </div>

        <?php if ($isAdmin && $availableDocs === []): ?>
            <div class="t8-empty">No documents exist yet. Upload one in Document Management first.</div>
        <?php elseif ($isAdmin): ?>
            <form method="post" action="<?= e(page_url('legal', ['action' => 'documents', 'id' => $caseId])) ?>"
                  style="padding: 0 var(--t8-space-4) var(--t8-space-4);" novalidate>
                <?= t8_csrf_field() ?>
                <input type="hidden" name="form" value="attach">
                <div class="t8-field">
                    <label class="t8-label" for="document_id">Attach Document</label>
                    <select class="t8-select" id="document_id" name="document_id" required>
                        <option value="">Select a document…</option>
                        <?php foreach ($availableDocs as $d): ?>
                            <option value="<?= e((string) $d['id']) ?>"><?= e($d['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="t8-field">
                    <label class="t8-label" for="description">Note</label>
                    <input class="t8-input" type="text" id="description" name="description" placeholder="Optional">
                </div>
                <button class="t8-btn t8-btn-accent" type="submit">
                    <i class="fa-solid fa-paperclip"></i> Attach
                </button>
            </form>
        <?php endif; ?>

        <?php if ($attachedDocs === []): ?>
            <div class="t8-empty">No documents attached to this case yet.</div>
        <?php else: ?>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead>
                        <tr>
                            <th>Document</th>
                            <th>Note</th>
                            <th>Attached On</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attachedDocs as $ad): ?>
                            <tr>
                                <td><?= e($ad['document_title']) ?></td>
                                <td><?= e((string) ($ad['description'] ?? '—')) ?></td>
                                <td><?= e(format_date($ad['created_at'], 'M d, Y g:i A')) ?></td>
                                <td style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <?php if ($isAdmin): ?><a class="t8-btn t8-btn-outline t8-btn-sm"
                                       href="<?= e(page_url('documents', ['action' => 'versions', 'id' => $ad['document_id']])) ?>">
                                        <i class="fa-solid fa-eye"></i> View
                                    </a><?php endif; ?>
                                    <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('documents', ['action' => 'download', 'version_id' => $ad['version_id']])) ?>"><i class="fa-solid fa-download"></i> Download</a>
                                    <?php if ($isAdmin): ?><form method="post" action="<?= e(page_url('legal', ['action' => 'detach_document'])) ?>"
                                          onsubmit="return confirm('Remove this document from the case?');">
                                        <?= t8_csrf_field() ?>
                                        <input type="hidden" name="link_id" value="<?= e((string) $ad['id']) ?>">
                                        <input type="hidden" name="case_id" value="<?= e((string) $caseId) ?>">
                                        <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit">
                                            <i class="fa-solid fa-xmark"></i> Remove
                                        </button>
                                    </form><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($showRetention): ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal')) ?>">
            <i class="fa-solid fa-arrow-left"></i> Back to Cases
        </a>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= e($case['title']) ?> — Retention</h2>
        </div>

        <?php if ($retentionRecord === null): ?>
            <div class="t8-empty">
                This case is closed but has not been registered under retention yet.
                <?php if (!$legalHasClosedAt): ?>
                    <br><br>The <code>closed_at</code> column migration has not run yet - see
                    <code>database/migrations/2026_09_11_legal_case_closed_at.sql</code>.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="t8-hr-readonly-block">
                <div class="t8-hr-readonly-item"><span>Status</span><strong><span class="t8-badge <?= e(t8_retention_status_badge((string) $retentionRecord['status'])) ?>"><?= e(ucwords(str_replace('_', ' ', (string) $retentionRecord['status']))) ?></span></strong></div>
                <div class="t8-hr-readonly-item"><span>Retention Basis</span><strong><?= e((string) $retentionRecord['retention_basis']) ?></strong></div>
                <div class="t8-hr-readonly-item"><span>Retention Period</span><strong><?= e((string) $retentionRecord['retention_years']) ?> years</strong></div>
                <div class="t8-hr-readonly-item"><span>Clock Start</span><strong><?= e(format_date((string) $retentionRecord['retention_start_date'], 'M d, Y')) ?></strong></div>
                <div class="t8-hr-readonly-item"><span>Disposition Date</span><strong><?= e(format_date((string) $retentionRecord['disposition_date'], 'M d, Y')) ?></strong></div>
                <div class="t8-hr-readonly-item"><span>Custodian</span><strong><?= e((string) $retentionRecord['custodian_name']) ?></strong></div>
            </div>

            <?php if ($isAdmin && in_array($retentionRecord['status'], ['active', 'due_review'], true)): ?>
                <div class="t8-alert t8-alert-info">
                    Legal Case retention <strong>defaults to archive-only</strong>. Disposal requires two
                    <strong>different</strong> administrators - one to request it, another to authorize it -
                    given the case's evidentiary and legal significance.
                </div>
                <form method="post" action="<?= e(page_url('legal', ['action' => 'retention_archive'])) ?>" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;">
                    <?= t8_csrf_field() ?>
                    <input type="hidden" name="record_id" value="<?= e((string) $retentionRecord['id']) ?>">
                    <input type="hidden" name="case_id" value="<?= e((string) $caseId) ?>">
                    <div class="t8-field" style="margin-bottom:0; flex:1; min-width:220px;">
                        <label class="t8-label" for="archive_reason">Archive Reason</label>
                        <input class="t8-input" type="text" id="archive_reason" name="reason" required>
                    </div>
                    <button class="t8-btn t8-btn-outline" type="submit"><i class="fa-solid fa-box-archive"></i> Archive</button>
                </form>
            <?php elseif ($isAdmin && $retentionRecord['status'] === 'archived'): ?>
                <div class="t8-alert t8-alert-info">
                    Requesting disposal here does not dispose the record - a <strong>different</strong>
                    administrator must authorize it below before it is actually disposed.
                </div>
                <form method="post" action="<?= e(page_url('legal', ['action' => 'retention_dispose_request'])) ?>" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;">
                    <?= t8_csrf_field() ?>
                    <input type="hidden" name="record_id" value="<?= e((string) $retentionRecord['id']) ?>">
                    <input type="hidden" name="case_id" value="<?= e((string) $caseId) ?>">
                    <div class="t8-field" style="margin-bottom:0; flex:1; min-width:220px;">
                        <label class="t8-label" for="dispose_reason">Disposal Reason</label>
                        <input class="t8-input" type="text" id="dispose_reason" name="reason" required>
                    </div>
                    <button class="t8-btn t8-btn-danger" type="submit"><i class="fa-solid fa-trash"></i> Request Disposal</button>
                </form>
            <?php elseif ($isAdmin && $retentionRecord['status'] === 'pending_disposal'): ?>
                <?php $isSameAdmin = (int) $retentionRecord['disposal_requested_by'] === $currentUserId; ?>
                <div class="t8-hr-readonly-block">
                    <div class="t8-hr-readonly-item"><span>Requested By</span><strong><?= e((string) $retentionRecord['disposal_requested_by_name']) ?></strong></div>
                    <div class="t8-hr-readonly-item"><span>Requested At</span><strong><?= e(format_date((string) $retentionRecord['disposal_requested_at'], 'M d, Y g:i A')) ?></strong></div>
                    <div class="t8-hr-readonly-item"><span>Reason</span><strong><?= e((string) $retentionRecord['disposal_reason']) ?></strong></div>
                </div>
                <?php if ($isSameAdmin): ?>
                    <div class="t8-alert t8-alert-warning">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        You requested this disposal. A <strong>different</strong> administrator must authorize it — dual control cannot be satisfied by the same account.
                    </div>
                    <button class="t8-btn t8-btn-danger" type="button" disabled title="A different administrator must authorize this">
                        <i class="fa-solid fa-lock"></i> Authorize Disposal (unavailable to you)
                    </button>
                <?php else: ?>
                    <form method="post" action="<?= e(page_url('legal', ['action' => 'retention_dispose_authorize'])) ?>" onsubmit="return confirm('Authorize disposal of this legal case record? This cannot be undone.');">
                        <?= t8_csrf_field() ?>
                        <input type="hidden" name="record_id" value="<?= e((string) $retentionRecord['id']) ?>">
                        <input type="hidden" name="case_id" value="<?= e((string) $caseId) ?>">
                        <button class="t8-btn t8-btn-danger" type="submit"><i class="fa-solid fa-check-double"></i> Authorize Disposal</button>
                    </form>
                <?php endif; ?>
            <?php elseif ($retentionRecord['status'] === 'disposed'): ?>
                <div class="t8-hr-readonly-block">
                    <div class="t8-hr-readonly-item"><span>Disposed At</span><strong><?= e(format_date((string) $retentionRecord['disposed_at'], 'M d, Y g:i A')) ?></strong></div>
                    <div class="t8-hr-readonly-item"><span>Authorized By</span><strong><?= e((string) $retentionRecord['disposal_authorized_by_name']) ?></strong></div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

<?php else: ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <?php if ($isAdmin): ?><a class="t8-btn t8-btn-accent" href="<?= e(page_url('legal', ['action' => 'create'])) ?>">
            <i class="fa-solid fa-plus"></i> New Legal Case
        </a><?php endif; ?>
        <?php if ($isAdmin): ?><a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal', ['action' => 'case_types'])) ?>">
            <i class="fa-solid fa-list"></i> Manage Case Types
        </a><?php endif; ?>
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal', array_merge($legalListFilters, ['archived' => $archivedFilter ? '0' : '1', 'page' => 1]))) ?>">
            <i class="fa-solid fa-box-archive"></i> <?= $archivedFilter ? 'View Active' : 'View Archived' ?>
        </a>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= $archivedFilter ? 'Archived Cases' : 'Legal Cases' ?></h2>
        </div>
        <form method="get" action="<?= e(page_url('legal')) ?>" class="t8-filter-grid" style="padding: 0 var(--t8-space-4) var(--t8-space-4);">
            <input type="hidden" name="page" value="legal">
            <input type="hidden" name="archived" value="<?= $archivedFilter ? '1' : '0' ?>">
            <div class="t8-field">
                <label class="t8-label" for="legal-search">Search</label>
                <input class="t8-input" type="search" id="legal-search" name="search" value="<?= e($searchFilter) ?>" placeholder="Case, subject, type, department...">
            </div>
            <div class="t8-field">
                <label class="t8-label" for="legal-status">Status</label>
                <select class="t8-select" id="legal-status" name="status">
                    <option value="">All statuses</option>
                    <?php foreach ($legalStatuses as $status): ?><option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $status))) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php if ($legalHasPriority): ?>
                <div class="t8-field">
                    <label class="t8-label" for="legal-priority">Priority</label>
                    <select class="t8-select" id="legal-priority" name="priority">
                        <option value="">All priorities</option>
                        <?php foreach ($legalPriorities as $priority): ?><option value="<?= e($priority) ?>" <?= $priorityFilter === $priority ? 'selected' : '' ?>><?= e(ucfirst($priority)) ?></option><?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="t8-field">
                <label class="t8-label" for="legal-case-type">Case Type</label>
                <select class="t8-select" id="legal-case-type" name="case_type">
                    <option value="">All case types</option>
                    <?php foreach ($legalCaseTypes as $caseType): ?><option value="<?= e((string) $caseType['id']) ?>" <?= $caseTypeFilter !== false && (int) $caseType['id'] === $caseTypeFilter ? 'selected' : '' ?>><?= e($caseType['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php if ($legalHasCaseMetadata): ?>
                <div class="t8-field">
                    <label class="t8-label" for="legal-department">Department</label>
                    <select class="t8-select" id="legal-department" name="department">
                        <option value="">All departments</option>
                        <?php foreach ($departments as $department): ?><option value="<?= e((string) $department['id']) ?>" <?= $departmentFilter !== false && (int) $department['id'] === $departmentFilter ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="t8-field">
                <label class="t8-label" for="legal-assignee">Assigned Officer</label>
                <select class="t8-select" id="legal-assignee" name="assigned_to">
                    <option value="">All assigned officers</option>
                    <?php foreach ($assignees as $assignee): ?><option value="<?= e((string) $assignee['id']) ?>" <?= $assignedFilter !== false && (int) $assignee['id'] === $assignedFilter ? 'selected' : '' ?>><?= e($assignee['full_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="t8-field"><label class="t8-label" for="legal-filed-from">Filed From</label><input class="t8-input" type="date" id="legal-filed-from" name="filed_from" value="<?= e($filedFrom) ?>"></div>
            <div class="t8-field"><label class="t8-label" for="legal-filed-to">Filed To</label><input class="t8-input" type="date" id="legal-filed-to" name="filed_to" value="<?= e($filedTo) ?>"></div>
            <?php if ($legalHasCaseMetadata): ?>
                <div class="t8-field"><label class="t8-label" for="legal-deadline-from">Deadline From</label><input class="t8-input" type="date" id="legal-deadline-from" name="deadline_from" value="<?= e($deadlineFrom) ?>"></div>
                <div class="t8-field"><label class="t8-label" for="legal-deadline-to">Deadline To</label><input class="t8-input" type="date" id="legal-deadline-to" name="deadline_to" value="<?= e($deadlineTo) ?>"></div>
            <?php endif; ?>
            <div style="display:flex; gap:8px; align-items:end;"><button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-filter"></i> Apply Filters</button><a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal', ['archived' => $archivedFilter ? '1' : '0'])) ?>">Clear</a></div>
        </form>
        <?php if ($cases === []): ?>
            <div class="t8-empty"><?= count($legalListFilters) > ($archivedFilter ? 1 : 0) ? 'No legal cases match the selected filters.' : ($archivedFilter ? 'No archived cases.' : 'No legal cases yet.') ?></div>
        <?php else: ?>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead>
                        <tr>
                            <th>Case No.</th>
                            <th>Title</th>
                            <th>Case Type</th>
                            <?php if ($legalHasCaseMetadata): ?><th>Subject</th>
                            <th>Department</th><?php endif; ?>
                            <th>Status</th>
                            <?php if ($legalHasPriority): ?><th>Priority</th><?php endif; ?>
                            <th>Filed Date</th>
                            <?php if ($legalHasCaseMetadata): ?><th>Deadline</th><?php endif; ?>
                            <th>Assigned To</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cases as $c): ?>
                            <?php
                            $isMonitoring = t8_legal_is_monitoring($c);
                            $retentionRecordForRow = function_exists('t8_retention_fetch_for_entity')
                                ? t8_retention_fetch_for_entity($pdo, 'legal_case', (int) $c['id'])
                                : null;
                            ?>
                            <tr>
                                <td><?= e((string) ($c['case_number'] ?? ('CASE-' . str_pad((string) $c['id'], 6, '0', STR_PAD_LEFT)))) ?></td>
                                <td><?= e($c['title']) ?></td>
                                <td><?= e($c['case_type_name']) ?><?= !(bool) $c['case_type_active'] ? ' (Inactive)' : '' ?></td>
                                <?php if ($legalHasCaseMetadata): ?><td><?= e((string) ($c['subject'] ?? '—')) ?></td>
                                <td><?= e((string) ($c['department_name'] ?? '—')) ?></td><?php endif; ?>
                                <td>
                                    <span class="t8-badge <?= t8_legal_status_badge($c['status']) ?>">
                                        <?= e(ucwords(str_replace('_', ' ', $c['status']))) ?>
                                    </span>
                                    <?php if ($isMonitoring): ?><span class="t8-badge-monitoring" title="Within 90 days of the case deadline">Monitoring</span><?php endif; ?>
                                </td>
                                <?php if ($legalHasPriority): ?><td><?= e(ucfirst((string) ($c['priority'] ?? 'medium'))) ?></td><?php endif; ?>
                                <td><?= e(format_date($c['filed_date'], 'M d, Y')) ?></td>
                                <?php if ($legalHasCaseMetadata): ?><td><?= $c['deadline'] ? e(format_date($c['deadline'], 'M d, Y')) : '—' ?></td><?php endif; ?>
                                <td><?= e($c['assigned_to_name']) ?></td>
                                <td style="text-align:right;">
                                    <?php t8_legal_render_menu($c, $isAdmin, $archivedFilter, $legalHasCaseMetadata, $isMonitoring, $retentionRecordForRow); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php t8_legal_pagination($page, $totalPages, $legalListFilters); ?>
        <?php endif; ?>
    </div>

    <!--
        Shared View Details modal for the Legal Cases list table's
        meatball menu (see t8_legal_render_menu() above).
    -->
    <dialog id="t8LegalDetailModal" class="t8-detail-modal">
        <div class="t8-detail-header">
            <div>
                <h2 data-detail-field="title">Legal Case</h2>
                <span class="t8-detail-ref" data-detail-field="ref"></span>
            </div>
            <button type="button" class="t8-detail-close" data-close-detail-modal aria-label="Close">&times;</button>
        </div>
        <div class="t8-detail-body">
            <div class="t8-detail-grid">
                <div class="t8-detail-item"><span>Status</span><strong data-detail-field="status">—</strong></div>
                <div class="t8-detail-item"><span>Assigned To</span><strong data-detail-field="assignedTo">—</strong></div>
                <div class="t8-detail-item" data-detail-wrap="department" hidden><span>Department</span><strong data-detail-field="department">—</strong></div>
                <div class="t8-detail-item"><span>Filed Date</span><strong data-detail-field="filed">—</strong></div>
                <div class="t8-detail-item" data-detail-wrap="deadline" hidden><span>Deadline</span><strong data-detail-field="deadline">—</strong></div>
            </div>
            <div id="t8LegalDetailSubjectWrap" data-detail-wrap="subject" hidden>
                <div class="t8-detail-section"><i class="fa-solid fa-file-lines"></i> Subject</div>
                <div class="t8-detail-notes" data-detail-field="subject"></div>
            </div>
        </div>
        <div class="t8-detail-footer">
            <button type="button" class="t8-btn t8-btn-outline" data-close-detail-modal>Close</button>
        </div>
    </dialog>

<?php endif; ?>
