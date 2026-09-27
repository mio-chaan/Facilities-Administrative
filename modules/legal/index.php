<?php
/**
 * modules/legal/index.php
 * Legal Management - administrators manage core case ownership/lifecycle;
 * Legal Officers can access assigned cases and their authorized work items.
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
 * Access: admins may create/reassign/archive cases. Legal Officers may
 * edit case facts and manage authorized work items, but cannot change
 * ownership or archive. Case access is scoped to direct, supporting-staff,
 * or active task assignment.
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

function t8_legal_has_case_information(PDO $pdo): bool
{
    try {
        return (bool) $pdo->query("SHOW COLUMNS FROM team8_legal_cases LIKE 'court_agency'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function t8_legal_has_party_schema(PDO $pdo): bool
{
    try {
        return (bool) $pdo->query("SHOW TABLES LIKE 'team8_legal_case_parties'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function t8_legal_has_task_schema(PDO $pdo): bool
{
    try {
        return (bool) $pdo->query("SHOW TABLES LIKE 'team8_legal_case_tasks'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function t8_legal_has_hearing_schema(PDO $pdo): bool
{
    try {
        return (bool) $pdo->query("SHOW TABLES LIKE 'team8_legal_case_hearings'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function t8_legal_has_document_type(PDO $pdo): bool
{
    try {
        return (bool) $pdo->query("SHOW COLUMNS FROM team8_legal_documents LIKE 'legal_document_type'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function t8_legal_has_notes_schema(PDO $pdo): bool
{
    try {
        return (bool) $pdo->query("SHOW TABLES LIKE 'team8_legal_case_notes'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function t8_legal_has_resolution_schema(PDO $pdo): bool
{
    try {
        return (bool) $pdo->query("SHOW TABLES LIKE 'team8_legal_case_resolutions'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function t8_legal_has_communication_schema(PDO $pdo): bool
{
    try {
        return (bool) $pdo->query("SHOW TABLES LIKE 'team8_legal_case_communications'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

$legalHasCaseMetadata = t8_legal_has_case_metadata($pdo);
$legalHasClosedAt = t8_legal_has_closed_at($pdo);
$legalHasPriority = t8_legal_has_priority($pdo);
$legalHasCaseCreationFields = t8_legal_has_case_creation_fields($pdo);
$legalHasCaseInformation = t8_legal_has_case_information($pdo);
$legalHasPartySchema = t8_legal_has_party_schema($pdo);
$legalHasTaskSchema = t8_legal_has_task_schema($pdo);
$legalHasHearingSchema = t8_legal_has_hearing_schema($pdo);
$legalHasDocumentType = t8_legal_has_document_type($pdo);
$legalHasNotesSchema = t8_legal_has_notes_schema($pdo);
$legalHasCommunicationSchema = t8_legal_has_communication_schema($pdo);
$legalHasResolutionSchema = t8_legal_has_resolution_schema($pdo);
$legalDocumentTypes = t8_legal_document_types();
$legalCommunicationTypes = t8_legal_communication_types();
$legalResolutionTypes = t8_legal_resolution_types();
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
    global $legalHasCaseCreationFields, $legalHasTaskSchema;
    $scope = '';
    $params = ['id' => $id];
    if (!t8_has_role('admin')) {
        $userId = t8_current_user_id();
        $scopeConditions = ['lc.assigned_to = :assigned_to'];
        $params['assigned_to'] = $userId;
        if ($legalHasCaseCreationFields) {
            $scopeConditions[] = 'lc.supporting_staff_id = :supporting_staff_id';
            $params['supporting_staff_id'] = $userId;
        }
        if ($legalHasTaskSchema) {
            $scopeConditions[] = "EXISTS (SELECT 1 FROM team8_legal_case_tasks scope_task WHERE scope_task.case_id = lc.id AND scope_task.assigned_to = :task_assigned_to AND scope_task.status <> 'cancelled')";
            $params['task_assigned_to'] = $userId;
        }
        $scope = ' AND (' . implode(' OR ', $scopeConditions) . ')';
    }
    $stmt = $pdo->prepare(
        'SELECT lc.*, u.full_name AS assigned_to_name
         FROM team8_legal_cases lc
         JOIN users u ON u.id = lc.assigned_to
         WHERE lc.id = :id' . $scope . ' LIMIT 1'
    );
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
$legalOfficers = $pdo->query(
    "SELECT DISTINCT u.id, u.full_name
     FROM users u
     JOIN user_roles ur ON ur.user_id = u.id
     JOIN roles r ON r.id = ur.role_id
     WHERE r.role_name = 'legal_officer' AND u.deleted_at IS NULL
     ORDER BY u.full_name"
)->fetchAll(PDO::FETCH_ASSOC);
$departments = $legalHasCaseMetadata ? $pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) : [];

switch ($action) {
    case 'dashboard':
        $dashboardStats = [
            'total_active' => 0,
            'open' => 0,
            'upcoming_deadlines' => 0,
            'upcoming_hearings' => 0,
            'overdue_tasks' => 0,
            'recently_closed' => 0,
        ];
        $dashboardDeadlines = [];
        $dashboardHearings = [];
        $dashboardScope = [];
        $dashboardParams = [];
        if (!$isAdmin) {
            $dashboardAccess = ['lc.assigned_to = :dashboard_assigned_to'];
            $dashboardParams['dashboard_assigned_to'] = $currentUserId;
            if ($legalHasCaseCreationFields) {
                $dashboardAccess[] = 'lc.supporting_staff_id = :dashboard_supporting_staff';
                $dashboardParams['dashboard_supporting_staff'] = $currentUserId;
            }
            if ($legalHasTaskSchema) {
                $dashboardAccess[] = "EXISTS (SELECT 1 FROM team8_legal_case_tasks dashboard_scope_task WHERE dashboard_scope_task.case_id = lc.id AND dashboard_scope_task.assigned_to = :dashboard_task_assigned AND dashboard_scope_task.status <> 'cancelled')";
                $dashboardParams['dashboard_task_assigned'] = $currentUserId;
            }
            $dashboardScope[] = '(' . implode(' OR ', $dashboardAccess) . ')';
        }
        $dashboardScopeSql = $dashboardScope === [] ? '1 = 1' : implode(' AND ', $dashboardScope);
        $dashboardWhereSql = "lc.status NOT IN ('closed', 'archived') AND " . $dashboardScopeSql;
        try {
            $dashboardCount = $pdo->prepare(
                "SELECT
                    SUM(lc.status NOT IN ('closed', 'archived')) AS total_active,
                    SUM(lc.status = 'open') AS open_cases,
                    SUM(lc.status NOT IN ('closed', 'archived') AND lc.deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS upcoming_deadlines
                 FROM team8_legal_cases lc WHERE " . $dashboardScopeSql
            );
            $dashboardCount->execute($dashboardParams);
            $dashboardCounts = $dashboardCount->fetch(PDO::FETCH_ASSOC) ?: [];
            $dashboardStats['total_active'] = (int) ($dashboardCounts['total_active'] ?? 0);
            $dashboardStats['open'] = (int) ($dashboardCounts['open_cases'] ?? 0);
            $dashboardStats['upcoming_deadlines'] = (int) ($dashboardCounts['upcoming_deadlines'] ?? 0);

            $recentClosedStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM team8_legal_cases lc
                 WHERE lc.status = 'closed'
                   AND " . ($legalHasClosedAt ? 'lc.closed_at' : 'lc.closing_date') . " >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                   AND " . $dashboardScopeSql
            );
            $recentClosedStmt->execute($dashboardParams);
            $dashboardStats['recently_closed'] = (int) $recentClosedStmt->fetchColumn();

            if ($legalHasTaskSchema) {
                $overdueTaskStmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM team8_legal_case_tasks task
                     JOIN team8_legal_cases lc ON lc.id = task.case_id
                     WHERE task.status IN ('pending', 'in_progress') AND task.due_date < CURDATE()
                       AND lc.status NOT IN ('closed', 'archived')
                       AND " . ($isAdmin ? '1 = 1' : 'task.assigned_to = :dashboard_overdue_assigned')
                );
                $overdueTaskStmt->execute($isAdmin ? [] : ['dashboard_overdue_assigned' => $currentUserId]);
                $dashboardStats['overdue_tasks'] = (int) $overdueTaskStmt->fetchColumn();
            }

            $deadlineStmt = $pdo->prepare(
                'SELECT lc.id, lc.case_number, lc.title, lc.deadline, u.full_name AS assigned_to_name
                 FROM team8_legal_cases lc JOIN users u ON u.id = lc.assigned_to
                 WHERE ' . $dashboardWhereSql . ' AND lc.deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                 ORDER BY lc.deadline ASC, lc.id ASC LIMIT 10'
            );
            $deadlineStmt->execute($dashboardParams);
            $dashboardDeadlines = $deadlineStmt->fetchAll(PDO::FETCH_ASSOC);

            if ($legalHasHearingSchema) {
                $hearingWhere = ["h.status = 'scheduled'", 'h.event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)', $dashboardWhereSql];
                $hearingCountStmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM team8_legal_case_hearings h JOIN team8_legal_cases lc ON lc.id = h.case_id WHERE ' . implode(' AND ', $hearingWhere)
                );
                $hearingCountStmt->execute($dashboardParams);
                $dashboardStats['upcoming_hearings'] = (int) $hearingCountStmt->fetchColumn();
                $hearingStmt = $pdo->prepare(
                    'SELECT h.case_id, h.event_date, h.event_time, h.venue, h.hearing_type, lc.case_number, lc.title
                     FROM team8_legal_case_hearings h JOIN team8_legal_cases lc ON lc.id = h.case_id
                     WHERE ' . implode(' AND ', $hearingWhere) . ' ORDER BY h.event_date ASC, h.event_time ASC, h.id ASC LIMIT 10'
                );
                $hearingStmt->execute($dashboardParams);
                $dashboardHearings = $hearingStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            $errors[] = 'Could not load the legal dashboard. Please verify the legal migrations are applied.';
        }
        break;

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
        if ($action === 'create') {
            t8_require_role(['admin']);
        } else {
            t8_require_role(['admin', 'legal_officer']);
        }
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
                'description' => (string) ($existing['description'] ?? ''),
                'court_agency' => (string) ($existing['court_agency'] ?? ''),
                'branch_office' => (string) ($existing['branch_office'] ?? ''),
                'docket_reference' => (string) ($existing['docket_reference'] ?? ''),
                'jurisdiction' => (string) ($existing['jurisdiction'] ?? ''),
                'location' => (string) ($existing['location'] ?? ''),
                'legal_basis' => (string) ($existing['legal_basis'] ?? ''),
                'current_action' => (string) ($existing['current_action'] ?? ''),
                'case_type_id' => (string) $existing['case_type_id'],
                'department_id' => (string) ($existing['department_id'] ?? ''),
                'status'      => $existing['status'],
                'priority'    => $legalHasPriority ? (string) ($existing['priority'] ?? 'medium') : 'medium',
                'filed_date'  => $existing['filed_date'],
                'deadline'    => (string) ($existing['deadline'] ?? ''),
                'assigned_to' => (string) $existing['assigned_to'],
            ]
           : ['title' => '', 'description' => '', 'court_agency' => '', 'branch_office' => '', 'docket_reference' => '', 'jurisdiction' => '', 'location' => '', 'legal_basis' => '', 'current_action' => '', 'case_type_id' => '', 'department_id' => '', 'status' => 'under_review', 'priority' => 'medium', 'filed_date' => date('Y-m-d'), 'deadline' => '', 'assigned_to' => (string) ($legalOfficers[0]['id'] ?? $currentUserId)];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $formValues = [
                'title'       => trim((string) ($_POST['title'] ?? '')),
                'description' => trim((string) ($_POST['description'] ?? '')),
                'court_agency' => trim((string) ($_POST['court_agency'] ?? '')),
                'branch_office' => trim((string) ($_POST['branch_office'] ?? '')),
                'docket_reference' => trim((string) ($_POST['docket_reference'] ?? '')),
                'jurisdiction' => trim((string) ($_POST['jurisdiction'] ?? '')),
                'location' => trim((string) ($_POST['location'] ?? '')),
                'legal_basis' => trim((string) ($_POST['legal_basis'] ?? '')),
                'current_action' => trim((string) ($_POST['current_action'] ?? '')),
                'case_type_id' => trim((string) ($_POST['case_type_id'] ?? '')),
                'department_id' => $isAdmin ? (string) ($_POST['department_id'] ?? '') : (string) ($existing['department_id'] ?? ''),
                'status'      => (string) ($_POST['status'] ?? ($existing['status'] ?? 'under_review')),
                'priority'    => (string) ($_POST['priority'] ?? $formValues['priority']),
                'filed_date'  => trim((string) ($_POST['filed_date'] ?? '')),
                'deadline'    => trim((string) ($_POST['deadline'] ?? '')),
                'assigned_to' => $isAdmin ? (string) ($_POST['assigned_to'] ?? '') : (string) ($existing['assigned_to'] ?? ''),
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
                if ($legalHasCaseInformation) {
                    foreach ([
                        'court_agency' => 200,
                        'branch_office' => 150,
                        'docket_reference' => 150,
                        'jurisdiction' => 150,
                        'location' => 200,
                        'legal_basis' => 5000,
                        'current_action' => 5000,
                    ] as $field => $maxLength) {
                        if (mb_strlen($formValues[$field]) > $maxLength) {
                            $errors[] = ucwords(str_replace('_', ' ', $field)) . ' must be ' . $maxLength . ' characters or fewer.';
                        }
                    }
                }
                $unchangedInactiveStatus = $existing !== null
                    && $formValues['status'] === $existing['status']
                    && !in_array($existing['status'], $legalEditableStatuses, true);
                if (!in_array($formValues['status'], $legalEditableStatuses, true) && !$unchangedInactiveStatus) {
                    $errors[] = 'Invalid status selected.';
                }
                                if ($existing === null && $formValues['status'] !== 'under_review') {
                                        $errors[] = 'New legal cases must start in Under Review status.';
                                } elseif ($existing !== null
                    && $formValues['status'] !== $existing['status']
                    && !t8_legal_case_status_transition_is_allowed((string) $existing['status'], $formValues['status'])
                ) {
                    $errors[] = 'That case status transition is not allowed.';
                }
                if ($existing !== null
                    && $formValues['status'] !== $existing['status']
                    && in_array($formValues['status'], ['resolved', 'closed'], true)
                ) {
                    if (!$legalHasResolutionSchema) {
                        $errors[] = 'Apply the case resolution migration before resolving or closing a case.';
                    } else {
                        $resolutionExistsStmt = $pdo->prepare('SELECT id FROM team8_legal_case_resolutions WHERE case_id = :case_id');
                        $resolutionExistsStmt->execute(['case_id' => $caseId]);
                        if ($resolutionExistsStmt->fetchColumn() === false) {
                            $errors[] = 'Add a resolution record before setting this case to Resolved or Closed.';
                        }
                    }
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
                    && !($existing !== null && $caseTypeId === (int) $existing['case_type_id'] && $selectedCaseType && !(bool) $selectedCaseType['is_active'])
                ) {
                    $errors[] = 'Select an active legal case type.';
                }
                $deadlineRequired = $formValues['status'] !== 'under_review';

                if ($deadlineRequired && $formValues['deadline'] === '') {
                    $errors[] = 'Deadline is required once the case leaves Under Review.';
                } elseif ($formValues['deadline'] !== '' && !t8_legal_is_valid_iso_date($formValues['deadline'])) {
                    $errors[] = 'Deadline must be a valid date.';
                } elseif ($formValues['deadline'] !== ''
                    && t8_legal_is_valid_iso_date($formValues['filed_date'])
                    && !t8_legal_case_date_is_not_before_filed($formValues['filed_date'], $formValues['deadline'])
                ) {
                    $errors[] = 'Deadline cannot be earlier than the Filed Date.';
                }
                if ($formValues['deadline'] !== '' && t8_legal_is_valid_iso_date($formValues['deadline'])) {
                    $deadlineTs = strtotime($formValues['deadline']);
                    $todayTs    = strtotime(date('Y-m-d'));

                    if ($deadlineTs < $todayTs) {
                        $errors[] = 'Deadline cannot be in the past.';
                    }
                }
                $legalOfficerIds = array_map('intval', array_column($legalOfficers, 'id'));
                $assignedToId = filter_var($formValues['assigned_to'], FILTER_VALIDATE_INT);
                if ($assignedToId === false || !in_array($assignedToId, $legalOfficerIds, true)) {
                    $errors[] = 'Select a valid assigned legal officer.';
                }
                $departmentIds = array_map('intval', array_column($departments, 'id'));
                $departmentId = $formValues['department_id'] === ''
                    ? null
                    : filter_var($formValues['department_id'], FILTER_VALIDATE_INT);
                if (!$legalHasCaseMetadata || ($departmentId !== null && ($departmentId === false || !in_array($departmentId, $departmentIds, true)))) {
                    $errors[] = 'Select a valid department.';
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
                            'title' => $formValues['title'],
                            'description' => $formValues['description'] !== '' ? $formValues['description'] : null,
                            'case_type_id' => $caseTypeId,
                            'department_id' => $departmentId,
                            'status' => $formValues['status'],
                            'filed_date' => $formValues['filed_date'],
                            'deadline' => $formValues['deadline'] !== '' ? $formValues['deadline'] : null,
                        ];
                        if ($legalHasCaseInformation) {
                            foreach (['court_agency', 'branch_office', 'docket_reference', 'jurisdiction', 'location', 'legal_basis', 'current_action'] as $field) {
                                $params[$field] = $formValues[$field] !== '' ? $formValues[$field] : null;
                            }
                        }
                        if ($legalHasPriority) {
                            $params['priority'] = $formValues['priority'];
                        }

                        if ($action === 'create') {
                            $caseYear = (int) $pdo->query('SELECT YEAR(CURRENT_DATE)')->fetchColumn();
                            $caseNumber = t8_legal_next_case_number($pdo, $caseYear);
                            $columns = 'case_number, assigned_to, title, description, case_type_id, department_id, status'
                                . ($legalHasPriority ? ', priority' : '')
                                . ($legalHasCaseInformation ? ', court_agency, branch_office, docket_reference, jurisdiction, location, legal_basis, current_action' : '')
                                . ', filed_date, deadline'
                                . ($justClosed ? ', closed_at' : '');
                            $values = ':case_number, :assigned_to, :title, :description, :case_type_id, :department_id, :status'
                                . ($legalHasPriority ? ', :priority' : '')
                                . ($legalHasCaseInformation ? ', :court_agency, :branch_office, :docket_reference, :jurisdiction, :location, :legal_basis, :current_action' : '')
                                . ', :filed_date, :deadline'
                                . ($justClosed ? ', NOW()' : '');
                            $params['case_number'] = $caseNumber;
                            $pdo->prepare('INSERT INTO team8_legal_cases (' . $columns . ') VALUES (' . $values . ')')->execute($params);
                            $savedCaseId = (int) $pdo->lastInsertId();
                            t8_audit_log(
                                $pdo,
                                $currentUserId,
                                'legal_case',
                                $savedCaseId,
                                'create',
                                null,
                                json_encode(['case_number' => $caseNumber, 'assigned_to' => (int) $assignedToId], JSON_THROW_ON_ERROR),
                                true
                            );
                        } else {
                            $params['id'] = $caseId;
                            $caseAuditValues = [
                                'title' => [$existing['title'], $formValues['title']],
                                'description' => [$existing['description'] ?? null, $formValues['description'] !== '' ? $formValues['description'] : null],
                                'case_type_id' => [(int) $existing['case_type_id'], $caseTypeId],
                                'department_id' => [$existing['department_id'] ?? null, $departmentId],
                                'filed_date' => [$existing['filed_date'], $formValues['filed_date']],
                            ];
                            if ($legalHasPriority) {
                                $caseAuditValues['priority'] = [$existing['priority'] ?? null, $formValues['priority']];
                            }
                            if ($legalHasCaseInformation) {
                                foreach (['court_agency', 'branch_office', 'docket_reference', 'jurisdiction', 'location', 'legal_basis', 'current_action'] as $field) {
                                    $caseAuditValues[$field] = [$existing[$field] ?? null, $formValues[$field] !== '' ? $formValues[$field] : null];
                                }
                            }
                            $changedCaseFields = [];
                            foreach ($caseAuditValues as $field => [$oldValue, $newValue]) {
                                if ((string) ($oldValue ?? '') !== (string) ($newValue ?? '')) {
                                    $changedCaseFields[] = $field;
                                }
                            }
                            $pdo->prepare(
                                'UPDATE team8_legal_cases SET assigned_to = :assigned_to, title = :title, description = :description, case_type_id = :case_type_id, department_id = :department_id, status = :status'
                                . ($legalHasPriority ? ', priority = :priority' : '')
                                . ($legalHasCaseInformation ? ', court_agency = :court_agency, branch_office = :branch_office, docket_reference = :docket_reference, jurisdiction = :jurisdiction, location = :location, legal_basis = :legal_basis, current_action = :current_action' : '')
                                . ', filed_date = :filed_date, deadline = :deadline'
                                . ($justClosed ? ', closed_at = NOW()' : '')
                                . ' WHERE id = :id'
                            )->execute($params);
                            if ($formValues['status'] !== $existing['status']) {
                                t8_audit_log($pdo, $currentUserId, 'legal_case', $caseId, 'status_change', (string) $existing['status'], $formValues['status'], true);
                            }
                            if ((int) $existing['assigned_to'] !== (int) $assignedToId) {
                                t8_audit_log($pdo, $currentUserId, 'legal_case', $caseId, 'assignment_change', (string) $existing['assigned_to'], (string) $assignedToId, true);
                            }
                            $newDeadline = $formValues['deadline'] !== '' ? $formValues['deadline'] : null;
                            $oldDeadline = $existing['deadline'] ?? null;
                            if ((string) ($oldDeadline ?? '') !== (string) ($newDeadline ?? '')) {
                                t8_audit_log($pdo, $currentUserId, 'legal_case', $caseId, 'deadline_change', $oldDeadline === null ? null : (string) $oldDeadline, $newDeadline, true);
                            }
                            if ($changedCaseFields !== []) {
                                t8_audit_log(
                                    $pdo,
                                    $currentUserId,
                                    'legal_case',
                                    $caseId,
                                    'case_fields_updated',
                                    null,
                                    json_encode($changedCaseFields, JSON_THROW_ON_ERROR),
                                    true
                                );
                            }
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

    case 'view':
        $caseId = (int) ($_GET['id'] ?? 0);
        $case = $caseId ? t8_legal_case_fetch($pdo, $caseId) : null;
        if (!$case) {
            t8_flash_set('danger', 'Legal case not found.');
            redirect(page_url('legal'));
        }

        $caseTypeStmt = $pdo->prepare('SELECT name FROM team8_legal_case_types WHERE id = :id');
        $caseTypeStmt->execute(['id' => (int) $case['case_type_id']]);
        $detailCaseType = $caseTypeStmt->fetchColumn() ?: 'Unknown';
        $detailDepartment = '—';
        if ($legalHasCaseMetadata && !empty($case['department_id'])) {
            $departmentStmt = $pdo->prepare('SELECT name FROM departments WHERE id = :id');
            $departmentStmt->execute(['id' => (int) $case['department_id']]);
            $detailDepartment = (string) ($departmentStmt->fetchColumn() ?: '—');
        }
        $caseResolution = null;
        if ($legalHasResolutionSchema) {
            $caseResolutionStmt = $pdo->prepare(
                'SELECT r.*, u.full_name AS recorded_by_name, ld.document_id AS supporting_document_id, d.title AS supporting_document_title, v.id AS supporting_version_id
                 FROM team8_legal_case_resolutions r
                 JOIN users u ON u.id = r.recorded_by
                 LEFT JOIN team8_legal_documents ld ON ld.id = r.supporting_legal_document_id
                 LEFT JOIN team8_documents d ON d.id = ld.document_id
                 LEFT JOIN team8_document_versions v ON v.document_id = d.id AND v.version_no = d.current_version
                 WHERE r.case_id = :case_id'
            );
            $caseResolutionStmt->execute(['case_id' => $caseId]);
            $caseResolution = $caseResolutionStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $detailDocuments = [];
        if ($legalHasDocumentType) {
            $detailDocumentsStmt = $pdo->prepare(
                'SELECT ld.created_at, d.title
                 FROM team8_legal_documents ld
                 JOIN team8_documents d ON d.id = ld.document_id
                 WHERE ld.case_id = :case_id AND d.deleted_at IS NULL
                 ORDER BY ld.created_at DESC, ld.id DESC
                 LIMIT 5'
            );
            $detailDocumentsStmt->execute(['case_id' => $caseId]);
            $detailDocuments = $detailDocumentsStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $detailActivityStmt = $pdo->prepare(
            'SELECT a.entity_type, a.action, a.created_at, u.full_name AS actor_name
             FROM audit_logs a
             JOIN users u ON u.id = a.user_id
             WHERE a.entity_type = \'legal_case\' AND a.entity_id = :case_id AND a.action <> \'view_documents\'
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT 5'
        );
        $detailActivityStmt->execute(['case_id' => $caseId]);
        $detailActivity = $detailActivityStmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'resolution':
        $caseId = (int) ($_GET['id'] ?? 0);
        $case = $caseId ? t8_legal_case_fetch($pdo, $caseId) : null;
        if (!$case) {
            t8_flash_set('danger', 'Legal case not found.');
            redirect(page_url('legal'));
        }
        $resolution = null;
        $resolutionDocuments = [];
        $resolutionInput = [
            'resolution_type' => '',
            'resolution_summary' => '',
            'resolved_date' => '',
            'final_outcome' => '',
            'supporting_legal_document_id' => '',
        ];
        if (!$legalHasResolutionSchema) {
            $errors[] = 'Apply database/migrations/2026_09_26_legal_case_resolutions.sql before managing case resolutions.';
            break;
        }

        $resolutionStmt = $pdo->prepare(
            'SELECT r.*, u.full_name AS recorded_by_name, ld.document_id AS supporting_document_id, d.title AS supporting_document_title, v.id AS supporting_version_id
             FROM team8_legal_case_resolutions r
             JOIN users u ON u.id = r.recorded_by
             LEFT JOIN team8_legal_documents ld ON ld.id = r.supporting_legal_document_id
             LEFT JOIN team8_documents d ON d.id = ld.document_id
             LEFT JOIN team8_document_versions v ON v.document_id = d.id AND v.version_no = d.current_version
             WHERE r.case_id = :case_id'
        );
        $resolutionStmt->execute(['case_id' => $caseId]);
        $resolution = $resolutionStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($resolution !== null) {
            $resolutionInput = [
                'resolution_type' => (string) $resolution['resolution_type'],
                'resolution_summary' => (string) $resolution['resolution_summary'],
                'resolved_date' => (string) $resolution['resolved_date'],
                'final_outcome' => (string) ($resolution['final_outcome'] ?? ''),
                'supporting_legal_document_id' => (string) ($resolution['supporting_legal_document_id'] ?? ''),
            ];
        }
        $resolutionDocumentsStmt = $pdo->prepare(
            'SELECT ld.id, d.title FROM team8_legal_documents ld
             JOIN team8_documents d ON d.id = ld.document_id
             WHERE ld.case_id = :case_id AND d.deleted_at IS NULL
             ORDER BY d.title'
        );
        $resolutionDocumentsStmt->execute(['case_id' => $caseId]);
        $resolutionDocuments = $resolutionDocumentsStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            t8_require_role(['admin', 'legal_officer']);
            $resolutionInput = [
                'resolution_type' => (string) ($_POST['resolution_type'] ?? ''),
                'resolution_summary' => trim((string) ($_POST['resolution_summary'] ?? '')),
                'resolved_date' => trim((string) ($_POST['resolved_date'] ?? '')),
                'final_outcome' => trim((string) ($_POST['final_outcome'] ?? '')),
                'supporting_legal_document_id' => (string) ($_POST['supporting_legal_document_id'] ?? ''),
            ];
            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } elseif (!in_array($case['status'], ['active', 'resolved'], true)) {
                $errors[] = 'Record a resolution after the case becomes Active and before it is Closed.';
            } else {
                if (!in_array($resolutionInput['resolution_type'], $legalResolutionTypes, true)) {
                    $errors[] = 'Select a valid resolution type.';
                }
                if ($resolutionInput['resolution_summary'] === '' || mb_strlen($resolutionInput['resolution_summary']) > 5000) {
                    $errors[] = 'Resolution summary is required and must be 5000 characters or fewer.';
                }
                if (!t8_legal_is_valid_iso_date($resolutionInput['resolved_date'])
                    || !t8_legal_case_date_is_not_before_filed((string) $case['filed_date'], $resolutionInput['resolved_date'])
                    || $resolutionInput['resolved_date'] > date('Y-m-d')
                ) {
                    $errors[] = 'Resolved date must be valid, on or after the filed date, and not in the future.';
                }
                if (mb_strlen($resolutionInput['final_outcome']) > 5000) {
                    $errors[] = 'Final outcome must be 5000 characters or fewer.';
                }
                $supportingDocumentId = null;
                if ($resolutionInput['supporting_legal_document_id'] !== '') {
                    $supportingDocumentId = filter_var($resolutionInput['supporting_legal_document_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($supportingDocumentId === false) {
                        $errors[] = 'Select a valid supporting document.';
                    } else {
                        $supportingDocumentStmt = $pdo->prepare(
                            'SELECT id FROM team8_legal_documents WHERE id = :id AND case_id = :case_id'
                        );
                        $supportingDocumentStmt->execute(['id' => $supportingDocumentId, 'case_id' => $caseId]);
                        if ($supportingDocumentStmt->fetchColumn() === false) {
                            $errors[] = 'The supporting document must already be attached to this case.';
                        }
                    }
                }

                if (!$errors) {
                    try {
                        $pdo->beginTransaction();
                        if ($resolution === null) {
                            $saveResolution = $pdo->prepare(
                                'INSERT INTO team8_legal_case_resolutions
                                    (case_id, resolution_type, resolution_summary, resolved_date, final_outcome, supporting_legal_document_id, recorded_by)
                                 VALUES (:case_id, :resolution_type, :resolution_summary, :resolved_date, :final_outcome, :supporting_legal_document_id, :recorded_by)'
                            );
                        } else {
                            $saveResolution = $pdo->prepare(
                                'UPDATE team8_legal_case_resolutions
                                 SET resolution_type = :resolution_type, resolution_summary = :resolution_summary,
                                     resolved_date = :resolved_date, final_outcome = :final_outcome,
                                     supporting_legal_document_id = :supporting_legal_document_id, updated_at = NOW()
                                 WHERE case_id = :case_id'
                            );
                        }
                        $resolutionParams = [
                            'case_id' => $caseId,
                            'resolution_type' => $resolutionInput['resolution_type'],
                            'resolution_summary' => $resolutionInput['resolution_summary'],
                            'resolved_date' => $resolutionInput['resolved_date'],
                            'final_outcome' => $resolutionInput['final_outcome'] !== '' ? $resolutionInput['final_outcome'] : null,
                            'supporting_legal_document_id' => $supportingDocumentId,
                        ];
                        if ($resolution === null) {
                            $resolutionParams['recorded_by'] = $currentUserId;
                        }
                        $saveResolution->execute($resolutionParams);
                        $oldResolutionAudit = $resolution === null
                            ? null
                            : (string) $resolution['resolution_type'] . ' on ' . (string) $resolution['resolved_date'];
                        $newResolutionAudit = $resolutionInput['resolution_type'] . ' on ' . $resolutionInput['resolved_date'];
                        t8_audit_log(
                            $pdo,
                            $currentUserId,
                            'legal_case',
                            $caseId,
                            $resolution === null ? 'resolution_recorded' : 'resolution_updated',
                            $oldResolutionAudit,
                            $newResolutionAudit,
                            true
                        );
                        $pdo->commit();
                        t8_flash_set('success', $resolution === null ? 'Case resolution recorded.' : 'Case resolution updated.');
                        redirect(page_url('legal', ['action' => 'resolution', 'id' => $caseId]));
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $errors[] = 'The case resolution could not be saved.';
                    }
                }
            }
        }
        $caseResolution = null;
        if ($legalHasResolutionSchema) {
            $caseResolutionStmt = $pdo->prepare(
                'SELECT r.*, ld.document_id AS supporting_document_id, d.title AS supporting_document_title, v.id AS supporting_version_id
                 FROM team8_legal_case_resolutions r
                 LEFT JOIN team8_legal_documents ld ON ld.id = r.supporting_legal_document_id
                 LEFT JOIN team8_documents d ON d.id = ld.document_id
                 LEFT JOIN team8_document_versions v ON v.document_id = d.id AND v.version_no = d.current_version
                 WHERE r.case_id = :case_id'
            );
            $caseResolutionStmt->execute(['case_id' => $caseId]);
            $caseResolution = $caseResolutionStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        break;

    case 'tasks':
        $caseId = (int) ($_GET['id'] ?? 0);
        $case = $caseId ? t8_legal_case_fetch($pdo, $caseId) : null;
        if (!$case) {
            t8_flash_set('danger', 'Legal case not found.');
            redirect(page_url('legal'));
        }
        $tasks = [];
        $taskInput = ['title' => '', 'description' => '', 'assigned_to' => (string) ($case['assigned_to'] ?? $currentUserId), 'due_date' => '', 'priority' => 'medium'];
        $taskPriorities = ['low', 'medium', 'high', 'urgent'];
        $editableTaskStatuses = ['pending', 'in_progress', 'completed', 'cancelled'];
        if (!$legalHasTaskSchema) {
            $errors[] = 'Apply database/migrations/2026_09_26_legal_case_tasks.sql before managing tasks.';
            break;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            t8_require_role(['admin', 'legal_officer']);
            $taskAction = (string) ($_POST['task_action'] ?? '');
            if ($taskAction === 'create') {
                $taskInput = [
                    'title' => trim((string) ($_POST['title'] ?? '')),
                    'description' => trim((string) ($_POST['description'] ?? '')),
                    'assigned_to' => (string) ($_POST['assigned_to'] ?? ''),
                    'due_date' => trim((string) ($_POST['due_date'] ?? '')),
                    'priority' => (string) ($_POST['priority'] ?? 'medium'),
                ];
            }

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } elseif ($case['status'] === 'archived') {
                $errors[] = 'Archived cases cannot be changed.';
            } elseif ($taskAction === 'create') {
                if ($taskInput['title'] === '' || mb_strlen($taskInput['title']) > 200) {
                    $errors[] = 'Task title is required and must be 200 characters or fewer.';
                }
                if (mb_strlen($taskInput['description']) > 5000) {
                    $errors[] = 'Task description must be 5000 characters or fewer.';
                }
                if (!t8_legal_is_valid_iso_date($taskInput['due_date'])
                    || !t8_legal_case_date_is_not_before_filed((string) $case['filed_date'], $taskInput['due_date'])
                ) {
                    $errors[] = 'Enter a valid due date on or after the case filed date.';
                }
                if (!in_array($taskInput['priority'], $taskPriorities, true)) {
                    $errors[] = 'Select a valid task priority.';
                }
                $assignedToId = filter_var($taskInput['assigned_to'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $legalOfficerIds = array_map('intval', array_column($legalOfficers, 'id'));
                if ($assignedToId === false || !in_array($assignedToId, $legalOfficerIds, true)) {
                    $errors[] = 'Select a valid task assignee.';
                }

                if (!$errors) {
                    try {
                        $pdo->beginTransaction();
                        $insertTask = $pdo->prepare(
                            "INSERT INTO team8_legal_case_tasks
                                (case_id, title, description, assigned_to, created_by, due_date, priority, status)
                             VALUES (:case_id, :title, :description, :assigned_to, :created_by, :due_date, :priority, 'pending')"
                        );
                        $insertTask->execute([
                            'case_id' => $caseId,
                            'title' => $taskInput['title'],
                            'description' => $taskInput['description'] !== '' ? $taskInput['description'] : null,
                            'assigned_to' => (int) $assignedToId,
                            'created_by' => $currentUserId,
                            'due_date' => $taskInput['due_date'],
                            'priority' => $taskInput['priority'],
                        ]);
                        $taskId = (int) $pdo->lastInsertId();
                        t8_audit_log(
                            $pdo,
                            $currentUserId,
                            'legal_case_task',
                            $taskId,
                            'create',
                            null,
                            json_encode(['due_date' => $taskInput['due_date'], 'priority' => $taskInput['priority']], JSON_THROW_ON_ERROR),
                            true
                        );
                        $pdo->commit();
                        t8_flash_set('success', 'Task added to legal case.');
                        redirect(page_url('legal', ['action' => 'tasks', 'id' => $caseId]));
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $errors[] = 'The task could not be added.';
                    }
                }
            } elseif ($taskAction === 'status') {
                $taskId = filter_var($_POST['task_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $nextStatus = (string) ($_POST['status'] ?? '');
                if ($taskId === false || !in_array($nextStatus, $editableTaskStatuses, true)) {
                    $errors[] = 'Select a valid task and status.';
                } else {
                    try {
                        $pdo->beginTransaction();
                        $taskStmt = $pdo->prepare('SELECT status FROM team8_legal_case_tasks WHERE id = :id AND case_id = :case_id FOR UPDATE');
                        $taskStmt->execute(['id' => $taskId, 'case_id' => $caseId]);
                        $previousStatus = $taskStmt->fetchColumn();
                        if ($previousStatus === false) {
                            $pdo->rollBack();
                            $errors[] = 'Task not found for this case.';
                        } else {
                            $updateTask = $pdo->prepare(
                                "UPDATE team8_legal_case_tasks
                                 SET status = :status,
                                     completed_at = CASE WHEN :completion_status = 'completed' THEN COALESCE(completed_at, NOW()) ELSE NULL END
                                 WHERE id = :id AND case_id = :case_id"
                            );
                            $updateTask->execute([
                                'status' => $nextStatus,
                                'completion_status' => $nextStatus,
                                'id' => $taskId,
                                'case_id' => $caseId,
                            ]);
                            t8_audit_log($pdo, $currentUserId, 'legal_case_task', (int) $taskId, 'status_change', (string) $previousStatus, $nextStatus, true);
                            $pdo->commit();
                            t8_flash_set('success', 'Task status updated.');
                            redirect(page_url('legal', ['action' => 'tasks', 'id' => $caseId]));
                        }
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $errors[] = 'The task status could not be updated.';
                    }
                }
            } else {
                $errors[] = 'Invalid task action.';
            }
        }

        $taskStmt = $pdo->prepare(
            'SELECT t.*, u.full_name AS assignee_name
             FROM team8_legal_case_tasks t
             JOIN users u ON u.id = t.assigned_to
             WHERE t.case_id = :case_id
             ORDER BY CASE WHEN t.status IN (\'completed\', \'cancelled\') THEN 1 ELSE 0 END, t.due_date, t.id DESC'
        );
        $taskStmt->execute(['case_id' => $caseId]);
        $tasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'hearings':
        $caseId = (int) ($_GET['id'] ?? 0);
        $case = $caseId ? t8_legal_case_fetch($pdo, $caseId) : null;
        if (!$case) {
            t8_flash_set('danger', 'Legal case not found.');
            redirect(page_url('legal'));
        }
        $hearings = [];
        $hearingStatuses = ['scheduled', 'completed', 'postponed', 'cancelled'];
        $hearingInput = [
            'event_date' => '',
            'event_time' => '',
            'venue' => '',
            'hearing_type' => '',
            'purpose' => '',
            'status' => 'scheduled',
            'notes' => '',
        ];
        $editHearingId = filter_var($_GET['edit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$legalHasHearingSchema) {
            $errors[] = 'Apply database/migrations/2026_09_26_legal_case_hearings.sql before managing hearings.';
            break;
        }

        if ($editHearingId !== false) {
            $editHearingStmt = $pdo->prepare('SELECT * FROM team8_legal_case_hearings WHERE id = :id AND case_id = :case_id');
            $editHearingStmt->execute(['id' => $editHearingId, 'case_id' => $caseId]);
            $editHearing = $editHearingStmt->fetch(PDO::FETCH_ASSOC);
            if (!$editHearing) {
                $errors[] = 'Hearing not found for this case.';
                $editHearingId = false;
            } else {
                $hearingInput = [
                    'event_date' => (string) $editHearing['event_date'],
                    'event_time' => substr((string) ($editHearing['event_time'] ?? ''), 0, 5),
                    'venue' => (string) ($editHearing['venue'] ?? ''),
                    'hearing_type' => (string) $editHearing['hearing_type'],
                    'purpose' => (string) ($editHearing['purpose'] ?? ''),
                    'status' => (string) $editHearing['status'],
                    'notes' => (string) ($editHearing['notes'] ?? ''),
                ];
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            t8_require_role(['admin', 'legal_officer']);
            $hearingAction = (string) ($_POST['hearing_action'] ?? '');
            $hearingInput = [
                'event_date' => trim((string) ($_POST['event_date'] ?? '')),
                'event_time' => trim((string) ($_POST['event_time'] ?? '')),
                'venue' => trim((string) ($_POST['venue'] ?? '')),
                'hearing_type' => trim((string) ($_POST['hearing_type'] ?? '')),
                'purpose' => trim((string) ($_POST['purpose'] ?? '')),
                'status' => (string) ($_POST['status'] ?? 'scheduled'),
                'notes' => trim((string) ($_POST['notes'] ?? '')),
            ];
            $postedHearingId = filter_var($_POST['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($hearingAction === 'update' && $postedHearingId !== false) {
                $editHearingId = $postedHearingId;
            }

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } elseif ($case['status'] === 'archived') {
                $errors[] = 'Archived cases cannot be changed.';
            } elseif (!in_array($hearingAction, ['create', 'update'], true)) {
                $errors[] = 'Invalid hearing action.';
            } else {
                if (!t8_legal_is_valid_iso_date($hearingInput['event_date'])
                    || !t8_legal_case_date_is_not_before_filed((string) $case['filed_date'], $hearingInput['event_date'])
                ) {
                    $errors[] = 'Enter a valid hearing date on or after the case filed date.';
                }
                if ($hearingInput['event_time'] !== '' && !t8_legal_is_valid_iso_time($hearingInput['event_time'])) {
                    $errors[] = 'Enter a valid hearing time.';
                }
                if ($hearingInput['hearing_type'] === '' || mb_strlen($hearingInput['hearing_type']) > 150) {
                    $errors[] = 'Hearing type is required and must be 150 characters or fewer.';
                }
                if (mb_strlen($hearingInput['venue']) > 200 || mb_strlen($hearingInput['purpose']) > 500 || mb_strlen($hearingInput['notes']) > 5000) {
                    $errors[] = 'Venue, purpose, or notes exceed the allowed length.';
                }
                if (!in_array($hearingInput['status'], $hearingStatuses, true)) {
                    $errors[] = 'Select a valid hearing status.';
                }
                if ($hearingAction === 'update' && $postedHearingId === false) {
                    $errors[] = 'Select a hearing to update.';
                }

                $previousHearing = null;
                if (!$errors && $hearingAction === 'update') {
                    $previousHearingStmt = $pdo->prepare(
                        'SELECT event_date, event_time, venue, hearing_type, purpose, status, notes
                         FROM team8_legal_case_hearings WHERE id = :id AND case_id = :case_id'
                    );
                    $previousHearingStmt->execute(['id' => $postedHearingId, 'case_id' => $caseId]);
                    $previousHearing = $previousHearingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    if ($previousHearing === null) {
                        $errors[] = 'Hearing not found for this case.';
                    }
                }

                if (!$errors) {
                    $hearingParams = [
                        'event_date' => $hearingInput['event_date'],
                        'event_time' => $hearingInput['event_time'] !== '' ? $hearingInput['event_time'] : null,
                        'venue' => $hearingInput['venue'] !== '' ? $hearingInput['venue'] : null,
                        'hearing_type' => $hearingInput['hearing_type'],
                        'purpose' => $hearingInput['purpose'] !== '' ? $hearingInput['purpose'] : null,
                        'status' => $hearingInput['status'],
                        'notes' => $hearingInput['notes'] !== '' ? $hearingInput['notes'] : null,
                        'case_id' => $caseId,
                    ];
                    if ($hearingAction === 'create') {
                        try {
                            $pdo->beginTransaction();
                            $insertHearing = $pdo->prepare(
                                'INSERT INTO team8_legal_case_hearings
                                    (case_id, event_date, event_time, venue, hearing_type, purpose, status, notes, created_by)
                                 VALUES (:case_id, :event_date, :event_time, :venue, :hearing_type, :purpose, :status, :notes, :created_by)'
                            );
                            $insertHearing->execute($hearingParams + ['created_by' => $currentUserId]);
                            $hearingId = (int) $pdo->lastInsertId();
                            t8_audit_log(
                                $pdo,
                                $currentUserId,
                                'legal_case_hearing',
                                $hearingId,
                                'create',
                                null,
                                json_encode([
                                    'event_date' => $hearingInput['event_date'],
                                    'event_time' => $hearingInput['event_time'],
                                    'hearing_type' => $hearingInput['hearing_type'],
                                    'status' => $hearingInput['status'],
                                ], JSON_THROW_ON_ERROR),
                                true
                            );
                            $pdo->commit();
                            t8_flash_set('success', 'Hearing added to legal case.');
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            $errors[] = 'The hearing could not be added.';
                        }
                    } else {
                        $hearingAuditValues = [
                            'event_date' => [$previousHearing['event_date'], $hearingInput['event_date']],
                            'event_time' => [$previousHearing['event_time'], $hearingParams['event_time']],
                            'venue' => [$previousHearing['venue'], $hearingParams['venue']],
                            'hearing_type' => [$previousHearing['hearing_type'], $hearingInput['hearing_type']],
                            'purpose' => [$previousHearing['purpose'], $hearingParams['purpose']],
                            'notes' => [$previousHearing['notes'], $hearingParams['notes']],
                        ];
                        $changedHearingFields = [];
                        foreach ($hearingAuditValues as $field => [$oldValue, $newValue]) {
                            if ((string) ($oldValue ?? '') !== (string) ($newValue ?? '')) {
                                $changedHearingFields[] = $field;
                            }
                        }
                        $hearingParams['id'] = $postedHearingId;
                        $updateHearing = $pdo->prepare(
                            'UPDATE team8_legal_case_hearings
                             SET event_date = :event_date, event_time = :event_time, venue = :venue,
                                 hearing_type = :hearing_type, purpose = :purpose, status = :status, notes = :notes
                             WHERE id = :id AND case_id = :case_id'
                        );
                        try {
                            $pdo->beginTransaction();
                            $updateHearing->execute($hearingParams);
                            if ($previousHearing['status'] !== $hearingInput['status']) {
                                t8_audit_log($pdo, $currentUserId, 'legal_case_hearing', (int) $postedHearingId, 'status_change', (string) $previousHearing['status'], $hearingInput['status'], true);
                            }
                            if ($changedHearingFields !== []) {
                                t8_audit_log($pdo, $currentUserId, 'legal_case_hearing', (int) $postedHearingId, 'update', null, json_encode($changedHearingFields, JSON_THROW_ON_ERROR), true);
                            }
                            $pdo->commit();
                            t8_flash_set('success', 'Hearing updated.');
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            $errors[] = 'The hearing could not be updated.';
                        }
                    }
                    if (!$errors) {
                        redirect(page_url('legal', ['action' => 'hearings', 'id' => $caseId]));
                    }
                }
            }
        }

        $hearingStmt = $pdo->prepare(
            'SELECT * FROM team8_legal_case_hearings WHERE case_id = :case_id ORDER BY event_date, event_time, id'
        );
        $hearingStmt->execute(['case_id' => $caseId]);
        $hearings = $hearingStmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'notes':
        $caseId = (int) ($_GET['id'] ?? 0);
        $case = $caseId ? t8_legal_case_fetch($pdo, $caseId) : null;
        if (!$case) {
            t8_flash_set('danger', 'Legal case not found.');
            redirect(page_url('legal'));
        }
        $caseNotes = [];
        $noteContent = '';
        if (!$legalHasNotesSchema) {
            $errors[] = 'Apply database/migrations/2026_09_26_legal_case_notes.sql before managing case notes.';
            break;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            t8_require_role(['admin', 'legal_officer']);
            $noteContent = trim((string) ($_POST['content'] ?? ''));
            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } elseif ($case['status'] === 'archived') {
                $errors[] = 'Archived cases cannot be changed.';
            } elseif ($noteContent === '' || mb_strlen($noteContent) > 5000) {
                $errors[] = 'Note content is required and must be 5000 characters or fewer.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $insertNote = $pdo->prepare(
                        'INSERT INTO team8_legal_case_notes (case_id, author_id, content)
                         VALUES (:case_id, :author_id, :content)'
                    );
                    $insertNote->execute(['case_id' => $caseId, 'author_id' => $currentUserId, 'content' => $noteContent]);
                    t8_audit_log($pdo, $currentUserId, 'legal_case', $caseId, 'add_note', null, 'Internal note added', true);
                    $pdo->commit();
                    t8_flash_set('success', 'Case note added.');
                    redirect(page_url('legal', ['action' => 'notes', 'id' => $caseId]));
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = 'The case note could not be saved.';
                }
            }
        }

        $notesStmt = $pdo->prepare(
            'SELECT n.*, u.full_name AS author_name
             FROM team8_legal_case_notes n
             JOIN users u ON u.id = n.author_id
             WHERE n.case_id = :case_id
             ORDER BY n.created_at DESC, n.id DESC'
        );
        $notesStmt->execute(['case_id' => $caseId]);
        $caseNotes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'communications':
        $caseId = (int) ($_GET['id'] ?? 0);
        $case = $caseId ? t8_legal_case_fetch($pdo, $caseId) : null;
        if (!$case) {
            t8_flash_set('danger', 'Legal case not found.');
            redirect(page_url('legal'));
        }
        $communications = [];
        $communicationInput = [
            'date' => '',
            'time' => '',
            'type' => '',
            'direction' => 'incoming',
            'sender' => '',
            'recipient' => '',
            'subject' => '',
            'summary' => '',
            'attachment_legal_document_id' => '',
        ];
        $caseLegalDocuments = [];
        if (!$legalHasCommunicationSchema) {
            $errors[] = 'Apply database/migrations/2026_09_26_legal_case_communications.sql before managing communications.';
            break;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            t8_require_role(['admin', 'legal_officer']);
            $communicationInput = [
                'date' => trim((string) ($_POST['communication_date'] ?? '')),
                'time' => trim((string) ($_POST['communication_time'] ?? '')),
                'type' => (string) ($_POST['communication_type'] ?? ''),
                'direction' => (string) ($_POST['direction'] ?? ''),
                'sender' => trim((string) ($_POST['sender'] ?? '')),
                'recipient' => trim((string) ($_POST['recipient'] ?? '')),
                'subject' => trim((string) ($_POST['subject'] ?? '')),
                'summary' => trim((string) ($_POST['summary'] ?? '')),
                'attachment_legal_document_id' => (string) ($_POST['attachment_legal_document_id'] ?? ''),
            ];
            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } elseif ($case['status'] === 'archived') {
                $errors[] = 'Archived cases cannot be changed.';
            } else {
                if (!t8_legal_is_valid_iso_date($communicationInput['date'])) {
                    $errors[] = 'Enter a valid communication date.';
                }
                if ($communicationInput['time'] !== '' && !t8_legal_is_valid_iso_time($communicationInput['time'])) {
                    $errors[] = 'Enter a valid communication time.';
                }
                if (!in_array($communicationInput['type'], $legalCommunicationTypes, true)) {
                    $errors[] = 'Select a valid communication type.';
                }
                if (!in_array($communicationInput['direction'], ['incoming', 'outgoing'], true)) {
                    $errors[] = 'Select Incoming or Outgoing direction.';
                }
                foreach (['sender' => 200, 'recipient' => 200, 'subject' => 200, 'summary' => 5000] as $field => $maxLength) {
                    if (($field === 'summary' && $communicationInput[$field] === '') || mb_strlen($communicationInput[$field]) > $maxLength) {
                        $errors[] = ucwords(str_replace('_', ' ', $field)) . ' is required and must be ' . $maxLength . ' characters or fewer.';
                    }
                }

                $attachmentId = null;
                if ($communicationInput['attachment_legal_document_id'] !== '') {
                    $attachmentId = filter_var($communicationInput['attachment_legal_document_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($attachmentId === false) {
                        $errors[] = 'Select a valid attached legal document.';
                    } else {
                        $attachmentStmt = $pdo->prepare(
                            'SELECT id FROM team8_legal_documents WHERE id = :id AND case_id = :case_id'
                        );
                        $attachmentStmt->execute(['id' => $attachmentId, 'case_id' => $caseId]);
                        if ($attachmentStmt->fetchColumn() === false) {
                            $errors[] = 'The selected document is not attached to this case.';
                        }
                    }
                }

                if (!$errors) {
                    $communicationTime = $communicationInput['time'] === ''
                        ? null
                        : (strlen($communicationInput['time']) === 5 ? $communicationInput['time'] . ':00' : $communicationInput['time']);
                    $insertCommunication = $pdo->prepare(
                        'INSERT INTO team8_legal_case_communications
                            (case_id, communication_date, communication_time, communication_type, direction, sender, recipient, subject, summary, attachment_legal_document_id, recorded_by)
                         VALUES (:case_id, :communication_date, :communication_time, :communication_type, :direction, :sender, :recipient, :subject, :summary, :attachment_legal_document_id, :recorded_by)'
                    );
                    try {
                        $pdo->beginTransaction();
                        $insertCommunication->execute([
                            'case_id' => $caseId,
                            'communication_date' => $communicationInput['date'],
                            'communication_time' => $communicationTime,
                            'communication_type' => $communicationInput['type'],
                            'direction' => $communicationInput['direction'],
                            'sender' => $communicationInput['sender'] !== '' ? $communicationInput['sender'] : null,
                            'recipient' => $communicationInput['recipient'] !== '' ? $communicationInput['recipient'] : null,
                            'subject' => $communicationInput['subject'] !== '' ? $communicationInput['subject'] : null,
                            'summary' => $communicationInput['summary'],
                            'attachment_legal_document_id' => $attachmentId,
                            'recorded_by' => $currentUserId,
                        ]);
                        t8_audit_log(
                            $pdo,
                            $currentUserId,
                            'legal_case',
                            $caseId,
                            'record_communication',
                            null,
                            json_encode([
                                'date' => $communicationInput['date'],
                                'type' => $communicationInput['type'],
                                'direction' => $communicationInput['direction'],
                                'attachment_legal_document_id' => $attachmentId,
                            ], JSON_THROW_ON_ERROR),
                            true
                        );
                        $pdo->commit();
                        t8_flash_set('success', 'Communication recorded.');
                        redirect(page_url('legal', ['action' => 'communications', 'id' => $caseId]));
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $errors[] = 'The communication could not be recorded.';
                    }
                }
            }
        }

        $caseLegalDocumentsStmt = $pdo->prepare(
            'SELECT ld.id, d.title
             FROM team8_legal_documents ld
             JOIN team8_documents d ON d.id = ld.document_id
             WHERE ld.case_id = :case_id AND d.deleted_at IS NULL
             ORDER BY d.title'
        );
        $caseLegalDocumentsStmt->execute(['case_id' => $caseId]);
        $caseLegalDocuments = $caseLegalDocumentsStmt->fetchAll(PDO::FETCH_ASSOC);
        $communicationsStmt = $pdo->prepare(
            'SELECT c.*, u.full_name AS recorder_name, ld.document_id AS attachment_document_id,
                    d.title AS attachment_title, v.id AS attachment_version_id
             FROM team8_legal_case_communications c
             JOIN users u ON u.id = c.recorded_by
             LEFT JOIN team8_legal_documents ld ON ld.id = c.attachment_legal_document_id
             LEFT JOIN team8_documents d ON d.id = ld.document_id
             LEFT JOIN team8_document_versions v ON v.document_id = d.id AND v.version_no = d.current_version
             WHERE c.case_id = :case_id
             ORDER BY c.communication_date DESC, c.communication_time DESC, c.id DESC'
        );
        $communicationsStmt->execute(['case_id' => $caseId]);
        $communications = $communicationsStmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'timeline':
        $caseId = (int) ($_GET['id'] ?? 0);
        $case = $caseId ? t8_legal_case_fetch($pdo, $caseId) : null;
        if (!$case) {
            t8_flash_set('danger', 'Legal case not found.');
            redirect(page_url('legal'));
        }
        $timelineConditions = [
            "(a.entity_type = 'legal_case' AND a.entity_id = :case_id AND a.action <> 'view_documents')",
        ];
        $timelineParams = ['case_id' => $caseId];
        if ($legalHasTaskSchema) {
            $timelineConditions[] = "(a.entity_type = 'legal_case_task' AND a.entity_id IN (SELECT id FROM team8_legal_case_tasks WHERE case_id = :task_case_id))";
            $timelineParams['task_case_id'] = $caseId;
        }
        if ($legalHasHearingSchema) {
            $timelineConditions[] = "(a.entity_type = 'legal_case_hearing' AND a.entity_id IN (SELECT id FROM team8_legal_case_hearings WHERE case_id = :hearing_case_id))";
            $timelineParams['hearing_case_id'] = $caseId;
        }
        $timelineStmt = $pdo->prepare(
            'SELECT a.id, a.entity_type, a.entity_id, a.action, a.old_value, a.new_value, a.created_at,
                    u.full_name AS actor_name
             FROM audit_logs a
             JOIN users u ON u.id = a.user_id
             WHERE ' . implode(' OR ', $timelineConditions) . '
             ORDER BY a.created_at ASC, a.id ASC'
        );
        $timelineStmt->execute($timelineParams);
        $timelineEvents = $timelineStmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'parties':
        $caseId = (int) ($_GET['id'] ?? 0);
        $case = $caseId ? t8_legal_case_fetch($pdo, $caseId) : null;
        if (!$case) {
            t8_flash_set('danger', 'Legal case not found.');
            redirect(page_url('legal'));
        }

        $partyTypes = [];
        $availableParties = [];
        $attachedParties = [];
        $partyInput = [
            'name' => '',
            'entity_type' => 'individual',
            'organization' => '',
            'contact_email' => '',
            'contact_phone' => '',
            'role_in_case' => '',
            'party_id' => '',
            'party_type_id' => '',
        ];

        if (!$legalHasPartySchema) {
            $errors[] = 'Apply database/migrations/2026_09_26_legal_case_parties.sql before managing case parties.';
            break;
        }

        $partyTypes = $pdo->query(
            'SELECT id, name FROM team8_legal_party_types WHERE is_active = 1 ORDER BY sort_order, name'
        )->fetchAll(PDO::FETCH_ASSOC);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            t8_require_role(['admin']);
            $partyMode = (string) ($_POST['party_mode'] ?? '');
            $partyInput = [
                'name' => trim((string) ($_POST['name'] ?? '')),
                'entity_type' => (string) ($_POST['entity_type'] ?? 'individual'),
                'organization' => trim((string) ($_POST['organization'] ?? '')),
                'contact_email' => trim((string) ($_POST['contact_email'] ?? '')),
                'contact_phone' => trim((string) ($_POST['contact_phone'] ?? '')),
                'role_in_case' => trim((string) ($_POST['role_in_case'] ?? '')),
                'party_id' => (string) ($_POST['party_id'] ?? ''),
                'party_type_id' => (string) ($_POST['party_type_id'] ?? ''),
            ];
            $partyTypeId = filter_var($partyInput['party_type_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } elseif ($case['status'] === 'archived') {
                $errors[] = 'Archived cases cannot be changed.';
            } elseif ($partyTypeId === false || $partyInput['role_in_case'] === '' || mb_strlen($partyInput['role_in_case']) > 100) {
                $errors[] = 'Select a party type and enter a role of 1 to 100 characters.';
            } else {
                $typeStmt = $pdo->prepare('SELECT id FROM team8_legal_party_types WHERE id = :id AND is_active = 1');
                $typeStmt->execute(['id' => $partyTypeId]);
                if ($typeStmt->fetchColumn() === false) {
                    $errors[] = 'Select an active legal party type.';
                }

                $partyId = 0;
                if ($partyMode === 'existing') {
                    $partyIdValue = filter_var($partyInput['party_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($partyIdValue === false) {
                        $errors[] = 'Select an existing party.';
                    } else {
                        $partyStmt = $pdo->prepare('SELECT id FROM team8_parties WHERE id = :id');
                        $partyStmt->execute(['id' => $partyIdValue]);
                        $partyId = (int) ($partyStmt->fetchColumn() ?: 0);
                        if (!$partyId) {
                            $errors[] = 'The selected party no longer exists.';
                        } else {
                            $duplicateStmt = $pdo->prepare(
                                'SELECT id FROM team8_legal_case_parties WHERE case_id = :case_id AND party_id = :party_id'
                            );
                            $duplicateStmt->execute(['case_id' => $caseId, 'party_id' => $partyId]);
                            if ($duplicateStmt->fetchColumn() !== false) {
                                $errors[] = 'That party is already attached to this case.';
                            }
                        }
                    }
                } elseif ($partyMode === 'new') {
                    if ($partyInput['name'] === '' || mb_strlen($partyInput['name']) > 200) {
                        $errors[] = 'Party name is required and must be 200 characters or fewer.';
                    }
                    if (!in_array($partyInput['entity_type'], ['individual', 'organization'], true)) {
                        $errors[] = 'Select an individual or organization record type.';
                    }
                    if (mb_strlen($partyInput['organization']) > 200) {
                        $errors[] = 'Organization must be 200 characters or fewer.';
                    }
                    if (mb_strlen($partyInput['contact_email']) > 150 || ($partyInput['contact_email'] !== '' && filter_var($partyInput['contact_email'], FILTER_VALIDATE_EMAIL) === false)) {
                        $errors[] = 'Enter a valid contact email of 150 characters or fewer.';
                    }
                    if (mb_strlen($partyInput['contact_phone']) > 50) {
                        $errors[] = 'Contact phone must be 50 characters or fewer.';
                    }
                    if (!$errors) {
                        $duplicatePartyStmt = $pdo->prepare(
                            "SELECT id FROM team8_parties
                             WHERE LOWER(name) = LOWER(:name)
                               AND type = :entity_type
                               AND LOWER(COALESCE(organization, '')) = LOWER(:organization)
                               AND LOWER(COALESCE(contact_email, '')) = LOWER(:contact_email)
                             LIMIT 1"
                        );
                        $duplicatePartyStmt->execute([
                            'name' => $partyInput['name'],
                            'entity_type' => $partyInput['entity_type'],
                            'organization' => $partyInput['organization'],
                            'contact_email' => $partyInput['contact_email'],
                        ]);
                        if ($duplicatePartyStmt->fetchColumn() !== false) {
                            $errors[] = 'A matching party record already exists. Select it from the existing-party list.';
                        }
                    }
                } else {
                    $errors[] = 'Invalid party action.';
                }

                if (!$errors) {
                    try {
                        $pdo->beginTransaction();
                        if ($partyMode === 'new') {
                            $pdo->prepare(
                                'INSERT INTO team8_parties (name, type, organization, contact_email, contact_phone)
                                 VALUES (:name, :type, :organization, :contact_email, :contact_phone)'
                            )->execute([
                                'name' => $partyInput['name'],
                                'type' => $partyInput['entity_type'],
                                'organization' => $partyInput['organization'] !== '' ? $partyInput['organization'] : null,
                                'contact_email' => $partyInput['contact_email'] !== '' ? $partyInput['contact_email'] : null,
                                'contact_phone' => $partyInput['contact_phone'] !== '' ? $partyInput['contact_phone'] : null,
                            ]);
                            $partyId = (int) $pdo->lastInsertId();
                        }
                        $pdo->prepare(
                            'INSERT INTO team8_legal_case_parties (case_id, party_id, party_type_id, role_in_case)
                             VALUES (:case_id, :party_id, :party_type_id, :role_in_case)'
                        )->execute([
                            'case_id' => $caseId,
                            'party_id' => $partyId,
                            'party_type_id' => $partyTypeId,
                            'role_in_case' => $partyInput['role_in_case'],
                        ]);
                        $partyLinkId = (int) $pdo->lastInsertId();
                        t8_audit_log(
                            $pdo,
                            $currentUserId,
                            'legal_case',
                            $caseId,
                            'add_party',
                            null,
                            json_encode(['link_id' => $partyLinkId, 'party_id' => $partyId, 'party_type_id' => $partyTypeId, 'role' => $partyInput['role_in_case']], JSON_THROW_ON_ERROR),
                            true
                        );
                        $pdo->commit();
                        t8_flash_set('success', 'Party added to legal case.');
                        redirect(page_url('legal', ['action' => 'parties', 'id' => $caseId]));
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $errors[] = 'The party could not be added. It may already be linked to this case.';
                    }
                }
            }
        }

        if ($isAdmin) {
            $availableParties = $pdo->prepare(
                'SELECT p.id, p.name, p.type, p.organization
                 FROM team8_parties p
                 WHERE NOT EXISTS (
                     SELECT 1 FROM team8_legal_case_parties cp
                     WHERE cp.case_id = :case_id AND cp.party_id = p.id
                 )
                 ORDER BY p.name'
            );
            $availableParties->execute(['case_id' => $caseId]);
            $availableParties = $availableParties->fetchAll(PDO::FETCH_ASSOC);
        }
        $attachedParties = $pdo->prepare(
            'SELECT cp.id, cp.party_id, cp.role_in_case, pt.name AS legal_party_type,
                    p.name AS party_name, p.type AS entity_type, p.organization,
                    p.contact_email, p.contact_phone
             FROM team8_legal_case_parties cp
             JOIN team8_parties p ON p.id = cp.party_id
             JOIN team8_legal_party_types pt ON pt.id = cp.party_type_id
             WHERE cp.case_id = :case_id
             ORDER BY p.name, cp.id'
        );
        $attachedParties->execute(['case_id' => $caseId]);
        $attachedParties = $attachedParties->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'remove_party':
        t8_require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('legal'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('legal'));
        }
        $caseId = (int) ($_POST['case_id'] ?? 0);
        $linkId = (int) ($_POST['link_id'] ?? 0);
        $case = $caseId ? t8_legal_case_fetch($pdo, $caseId) : null;
        if (!$legalHasPartySchema || !$case || $case['status'] === 'archived') {
            t8_flash_set('danger', 'Legal case or party link not found.');
        } else {
            try {
                $pdo->beginTransaction();
                $partyLinkStmt = $pdo->prepare(
                    'SELECT party_id, party_type_id, role_in_case FROM team8_legal_case_parties
                     WHERE id = :id AND case_id = :case_id FOR UPDATE'
                );
                $partyLinkStmt->execute(['id' => $linkId, 'case_id' => $caseId]);
                $partyLink = $partyLinkStmt->fetch(PDO::FETCH_ASSOC);
                if ($partyLink === false) {
                    $pdo->rollBack();
                    t8_flash_set('danger', 'Party link not found.');
                } else {
                    $removePartyStmt = $pdo->prepare('DELETE FROM team8_legal_case_parties WHERE id = :id AND case_id = :case_id');
                    $removePartyStmt->execute(['id' => $linkId, 'case_id' => $caseId]);
                    t8_audit_log(
                        $pdo,
                        $currentUserId,
                        'legal_case',
                        $caseId,
                        'remove_party',
                        json_encode(['link_id' => $linkId, 'party_id' => (int) $partyLink['party_id'], 'party_type_id' => (int) $partyLink['party_type_id'], 'role' => $partyLink['role_in_case']], JSON_THROW_ON_ERROR),
                        null,
                        true
                    );
                    $pdo->commit();
                    t8_flash_set('success', 'Party removed from legal case.');
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                t8_flash_set('danger', 'The party link could not be removed.');
            }
        }
        redirect(page_url('legal', ['action' => 'parties', 'id' => $caseId]));
        break;

     // ---------------------------------------------------------------
    // PHASE 3+ — Quick status changer from the list view's row menu.
    // Mirrors the validation and side effects of the Edit form's
    // status dropdown, but without requiring the user to open the
    // full edit form. Same transition rules, same resolution
    // requirement, same retention hook on first close.
    // ---------------------------------------------------------------
    case 'change_status':
        t8_require_role(['admin', 'legal_officer']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('legal'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('legal'));
        }

        $caseId = (int) ($_POST['case_id'] ?? 0);
        $nextStatus = (string) ($_POST['next_status'] ?? '');
        $case = $caseId ? t8_legal_case_fetch($pdo, $caseId) : null;

        if (!$case) {
            t8_flash_set('danger', 'Legal case not found.');
            redirect(page_url('legal'));
        }
        if ($case['status'] === 'archived') {
            t8_flash_set('danger', 'Archived cases must be restored before changing status.');
            redirect(page_url('legal', ['archived' => '1']));
        }
        if (!in_array($nextStatus, $legalEditableStatuses, true)
            || !t8_legal_case_status_transition_is_allowed((string) $case['status'], $nextStatus)
        ) {
            t8_flash_set('danger', 'That case status transition is not allowed.');
            redirect(page_url('legal'));
        }

        // Resolved/Closed require a resolution record first — same guard
        // as the Edit form's submit handler above.
        if (in_array($nextStatus, ['resolved', 'closed'], true)) {
            if (!$legalHasResolutionSchema) {
                t8_flash_set('danger', 'Apply the case resolution migration before resolving or closing a case.');
                redirect(page_url('legal'));
            }
            $resolutionExistsStmt = $pdo->prepare('SELECT id FROM team8_legal_case_resolutions WHERE case_id = :case_id');
            $resolutionExistsStmt->execute(['case_id' => $caseId]);
            if ($resolutionExistsStmt->fetchColumn() === false) {
                t8_flash_set('danger', 'Add a resolution record before setting this case to Resolved or Closed.');
                redirect(page_url('legal', ['action' => 'resolution', 'id' => $caseId]));
            }
        }

        // Only the FIRST transition into 'closed' stamps closed_at and
        // triggers retention registration — same logic as the Edit form.
        $wasAlreadyClosed = $case['status'] === 'closed';
        $justClosed = $legalHasClosedAt && $nextStatus === 'closed' && !$wasAlreadyClosed;

        try {
            $pdo->beginTransaction();
            $sql = 'UPDATE team8_legal_cases SET status = :status';
            $params = ['status' => $nextStatus, 'id' => $caseId];
            if ($justClosed) {
                $sql .= ', closed_at = NOW()';
            }
            $sql .= ' WHERE id = :id';
            $pdo->prepare($sql)->execute($params);

            t8_audit_log(
                $pdo,
                $currentUserId,
                'legal_case',
                $caseId,
                'status_change',
                (string) $case['status'],
                $nextStatus,
                true
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            t8_flash_set('danger', 'The case status could not be changed.');
            redirect(page_url('legal'));
        }

        if ($justClosed) {
            $savedCase = t8_legal_case_fetch($pdo, $caseId);
            if ($savedCase !== null) {
                t8_legal_register_retention($pdo, $savedCase, $currentUserId);
            }
        }

        t8_flash_set('success', 'Case status changed to ' . ucwords(str_replace('_', ' ', $nextStatus)) . '.');
        redirect(page_url('legal'));
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
            if ($action === 'archive' && $case['status'] !== 'closed') {
                t8_flash_set('danger', 'Close the case before archiving it.');
                redirect(page_url('legal'));
            }
            if ($action === 'restore' && $case['status'] !== 'archived') {
                t8_flash_set('danger', 'The case is not in a state that can be restored.');
                redirect(page_url('legal', ['archived' => '1']));
            }

            $statusBefore = (string) $case['status'];
            $statusAfter = $action === 'archive'
                ? 'archived'
                : (string) ($case['archived_from_status'] ?: 'open');
            try {
                $pdo->beginTransaction();
                if ($action === 'archive') {
                    $pdo->prepare("UPDATE team8_legal_cases SET archived_from_status = status, status = 'archived' WHERE id = :id")
                        ->execute(['id' => $id]);
                } else {
                    $pdo->prepare("UPDATE team8_legal_cases SET status = COALESCE(archived_from_status, 'open'), archived_from_status = NULL WHERE id = :id")
                        ->execute(['id' => $id]);
                }
                t8_audit_log($pdo, $currentUserId, 'legal_case', $id, $action, $statusBefore, $statusAfter, true);
                $pdo->commit();
                t8_flash_set('success', $action === 'archive' ? 'Case archived.' : 'Case restored.');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                t8_flash_set('danger', 'The case status could not be changed.');
            }
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

        $attachedDocs = [];
        $availableDocs = [];
        if (!$legalHasDocumentType) {
            $errors[] = 'Apply database/migrations/2026_09_26_legal_case_documents.sql before managing legal documents.';
            break;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'attach') {
            t8_require_role(['admin']);
            $documentId = filter_var($_POST['document_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $legalDocumentType = (string) ($_POST['legal_document_type'] ?? '');
            $description = trim((string) ($_POST['description'] ?? ''));

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } elseif ($case['status'] === 'archived') {
                $errors[] = 'Archived cases cannot receive documents.';
            } elseif ($documentId === false) {
                $errors[] = 'Please select a document to attach.';
            } elseif (!in_array($legalDocumentType, $legalDocumentTypes, true)) {
                $errors[] = 'Select a valid legal document type.';
            } elseif (mb_strlen($description) > 500) {
                $errors[] = 'Document note must be 500 characters or fewer.';
            } else {
                $documentStmt = $pdo->prepare('SELECT id FROM team8_documents WHERE id = :id AND deleted_at IS NULL');
                $documentStmt->execute(['id' => $documentId]);
                $alreadyAttached = $pdo->prepare('SELECT id FROM team8_legal_documents WHERE case_id = :case_id AND document_id = :document_id');
                $alreadyAttached->execute(['case_id' => $caseId, 'document_id' => $documentId]);
                if ($documentStmt->fetchColumn() === false) {
                    $errors[] = 'The selected document is unavailable.';
                } elseif ($alreadyAttached->fetchColumn() !== false) {
                    $errors[] = 'That document is already attached to this case.';
                } else {
                    try {
                        $pdo->beginTransaction();
                        $pdo->prepare(
                            'INSERT INTO team8_legal_documents (case_id, document_id, legal_document_type, description)
                             VALUES (:case_id, :document_id, :legal_document_type, :description)'
                        )->execute([
                            'case_id' => $caseId,
                            'document_id' => $documentId,
                            'legal_document_type' => $legalDocumentType,
                            'description' => $description !== '' ? $description : null,
                        ]);
                        $documentLinkId = (int) $pdo->lastInsertId();
                        t8_audit_log(
                            $pdo,
                            $currentUserId,
                            'legal_case',
                            $caseId,
                            'attach_document',
                            null,
                            json_encode(['link_id' => $documentLinkId, 'document_id' => (int) $documentId, 'type' => $legalDocumentType], JSON_THROW_ON_ERROR),
                            true
                        );
                        $pdo->commit();
                        t8_flash_set('success', 'Document attached to case.');
                        redirect(page_url('legal', ['action' => 'documents', 'id' => $caseId]));
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $errors[] = 'The document could not be attached. Please try again.';
                    }
                }
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

        $availableDocs = $pdo->prepare(
            'SELECT d.id, d.title FROM team8_documents d
             WHERE d.deleted_at IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM team8_legal_documents ld
                   WHERE ld.case_id = :case_id AND ld.document_id = d.id
               )
             ORDER BY d.title'
        );
        $availableDocs->execute(['case_id' => $caseId]);
        $availableDocs = $availableDocs->fetchAll(PDO::FETCH_ASSOC);
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
        $case = $caseId ? t8_legal_case_fetch($pdo, $caseId) : null;
        if (!$legalHasDocumentType || !$case || $case['status'] === 'archived') {
            t8_flash_set('danger', 'Legal case or document link not found.');
        } else {
            try {
                $pdo->beginTransaction();
                $documentLinkStmt = $pdo->prepare(
                    'SELECT document_id, legal_document_type FROM team8_legal_documents
                     WHERE id = :id AND case_id = :case_id FOR UPDATE'
                );
                $documentLinkStmt->execute(['id' => $linkId, 'case_id' => $caseId]);
                $documentLink = $documentLinkStmt->fetch(PDO::FETCH_ASSOC);
                if ($documentLink === false) {
                    $pdo->rollBack();
                    t8_flash_set('danger', 'Document link not found.');
                } else {
                    $communicationReference = false;
                    if ($legalHasCommunicationSchema) {
                        $communicationStmt = $pdo->prepare(
                            'SELECT id FROM team8_legal_case_communications WHERE attachment_legal_document_id = :link_id LIMIT 1'
                        );
                        $communicationStmt->execute(['link_id' => $linkId]);
                        $communicationReference = $communicationStmt->fetchColumn() !== false;
                    }
                    if ($communicationReference) {
                        $pdo->rollBack();
                        t8_flash_set('danger', 'This document is referenced by a recorded communication and cannot be unlinked.');
                    } else {
                        $pdo->prepare('DELETE FROM team8_legal_documents WHERE id = :id AND case_id = :case_id')
                            ->execute(['id' => $linkId, 'case_id' => $caseId]);
                        t8_audit_log(
                            $pdo,
                            $currentUserId,
                            'legal_case',
                            $caseId,
                            'detach_document',
                            json_encode(['link_id' => $linkId, 'document_id' => (int) $documentLink['document_id'], 'type' => $documentLink['legal_document_type']], JSON_THROW_ON_ERROR),
                            null,
                            true
                        );
                        $pdo->commit();
                        t8_flash_set('success', 'Document removed from case.');
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                t8_flash_set('danger', 'The document link could not be removed.');
            }
        }
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
$showDashboard = $action === 'dashboard';
$showDetails = $action === 'view';
$showResolution = $action === 'resolution';
$showTimeline = $action === 'timeline';
$showCommunications = $action === 'communications';
$showNotes = $action === 'notes';
$showHearings = $action === 'hearings';
$showTasks = $action === 'tasks';
$showParties = $action === 'parties';
$showDocuments = $action === 'documents';
$showRetention = $action === 'retention';
$showCaseTypes = $action === 'case_types';
$showList = !$showForm && !$showDashboard && !$showDetails && !$showResolution && !$showTimeline && !$showCommunications && !$showNotes && !$showHearings && !$showTasks && !$showParties && !$showDocuments && !$showRetention && !$showCaseTypes;

$currentCaseType = null;
$formStatusOptions = [];
if ($showForm && $existing !== null) {
    $caseTypeStmt = $pdo->prepare('SELECT id, type_code, name, is_active FROM team8_legal_case_types WHERE id = :id');
    $caseTypeStmt->execute(['id' => (int) $existing['case_type_id']]);
    $currentCaseType = $caseTypeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
if ($showForm) {
    if ($action === 'create') {
                $formStatusOptions = in_array('under_review', $legalEditableStatuses, true) ? ['under_review'] : [];
    } else {
        $formStatusOptions[] = (string) $existing['status'];
        $caseResolutionExists = false;
        if ($legalHasResolutionSchema) {
            $resolutionExistsStmt = $pdo->prepare('SELECT id FROM team8_legal_case_resolutions WHERE case_id = :case_id');
            $resolutionExistsStmt->execute(['case_id' => (int) $existing['id']]);
            $caseResolutionExists = $resolutionExistsStmt->fetchColumn() !== false;
        }
        foreach ($legalEditableStatuses as $candidateStatus) {
            if (t8_legal_case_status_transition_is_allowed((string) $existing['status'], $candidateStatus)
                && (!in_array($candidateStatus, ['resolved', 'closed'], true) || $caseResolutionExists)
            ) {
                $formStatusOptions[] = $candidateStatus;
            }
        }
    }
}
$formAssignedOfficerName = '';
$formDepartmentName = '—';
if ($showForm && $existing !== null) {
    $formAssignedOfficerName = (string) $existing['assigned_to_name'];
    foreach ($departments as $department) {
        if ((string) $department['id'] === $formValues['department_id']) {
            $formDepartmentName = (string) $department['name'];
            break;
        }
    }
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
    $operationalFilter = (string) ($_GET['operational'] ?? '');
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
        if ($legalHasCaseCreationFields) {
            $searchColumns[] = 'lc.case_number LIKE :search_case_number';
        }
        if ($legalHasCaseMetadata) {
            $searchColumns[] = 'lc.subject LIKE :search_subject';
            $searchColumns[] = 'dep.name LIKE :search_department';
        }
        if ($legalHasCaseInformation) {
            $searchColumns[] = 'lc.docket_reference LIKE :search_docket';
        }
        if ($legalHasPartySchema) {
            $searchColumns[] = 'EXISTS (SELECT 1 FROM team8_legal_case_parties search_cp JOIN team8_parties search_party ON search_party.id = search_cp.party_id WHERE search_cp.case_id = lc.id AND search_party.name LIKE :search_party)';
        }
        $where[] = '(' . implode(' OR ', $searchColumns) . ')';
        $params['search'] = '%' . $searchFilter . '%';
        $params['search_id'] = '%' . preg_replace('/^CASE-0*/', '', strtoupper($searchFilter)) . '%';
        $params['search_assignee'] = '%' . $searchFilter . '%';
        $params['search_type'] = '%' . $searchFilter . '%';
        if ($legalHasCaseCreationFields) {
            $params['search_case_number'] = '%' . $searchFilter . '%';
        }
        if ($legalHasCaseMetadata) {
            $params['search_subject'] = '%' . $searchFilter . '%';
            $params['search_department'] = '%' . $searchFilter . '%';
        }
        if ($legalHasCaseInformation) {
            $params['search_docket'] = '%' . $searchFilter . '%';
        }
        if ($legalHasPartySchema) {
            $params['search_party'] = '%' . $searchFilter . '%';
        }
    }
    foreach ([['filed_from', 'filedFrom', '>='], ['filed_to', 'filedTo', '<='], ['deadline_from', 'deadlineFrom', '>='], ['deadline_to', 'deadlineTo', '<=']] as [$field, $variable, $operator]) {
        if ($$variable !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $$variable) === 1) {
            $where[] = 'lc.' . ($field === 'filed_from' || $field === 'filed_to' ? 'filed_date' : 'deadline') . ' ' . $operator . ' :' . $field;
            $params[$field] = $$variable;
        }
    }
    if (!$isAdmin) {
        $caseAccessConditions = ['lc.assigned_to = :assigned_to'];
        $params['assigned_to'] = $currentUserId;
        if ($legalHasCaseCreationFields) {
            $caseAccessConditions[] = 'lc.supporting_staff_id = :supporting_staff_access';
            $params['supporting_staff_access'] = $currentUserId;
        }
        if ($legalHasTaskSchema) {
            $caseAccessConditions[] = "EXISTS (SELECT 1 FROM team8_legal_case_tasks scope_task WHERE scope_task.case_id = lc.id AND scope_task.assigned_to = :task_assigned_access AND scope_task.status <> 'cancelled')";
            $params['task_assigned_access'] = $currentUserId;
        }
        $where[] = '(' . implode(' OR ', $caseAccessConditions) . ')';
    }
    if ($operationalFilter === 'overdue' && $legalHasTaskSchema) {
        $where[] = "EXISTS (SELECT 1 FROM team8_legal_case_tasks operational_task WHERE operational_task.case_id = lc.id AND operational_task.status IN ('pending', 'in_progress') AND operational_task.due_date < CURDATE())";
    } elseif ($operationalFilter === 'upcoming_deadline' && $legalHasCaseMetadata) {
        $where[] = 'lc.deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
    } elseif ($operationalFilter === 'upcoming_hearing' && $legalHasHearingSchema) {
        $where[] = "EXISTS (SELECT 1 FROM team8_legal_case_hearings operational_hearing WHERE operational_hearing.case_id = lc.id AND operational_hearing.status = 'scheduled' AND operational_hearing.event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))";
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
        'operational' => $operationalFilter,
    ], static fn ($value): bool => $value !== '' && $value !== null);
}

function t8_legal_status_badge(string $status): string
{
    $map = [
        'open'         => 't8-badge-pending', // legacy — retained for pre-migration rows
        'under_review' => 't8-badge-pending',
        'active'       => 't8-badge-approved',
        'resolved'     => 't8-badge-approved',
        'closed'       => 't8-badge-archived',
        'archived'     => 't8-badge-archived',
    ];
    return $map[$status] ?? 't8-badge-pending';
}

function t8_legal_task_status_badge(string $status): string
{
    $map = [
        'pending' => 't8-badge-pending',
        'in_progress' => 't8-badge-active',
        'completed' => 't8-badge-approved',
        'overdue' => 't8-badge-rejected',
        'cancelled' => 't8-badge-cancelled',
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
    global $legalEditableStatuses;

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
            <a class="t8-row-menu-item" role="menuitem" href="<?= e(page_url('legal', ['action' => 'view', 'id' => $id])) ?>">
                <i class="fa-solid fa-folder-open"></i> Open Case Workspace
            </a>
            <button type="button" class="t8-row-menu-item t8-row-copy-ref" role="menuitem" data-copy="<?= e($ref) ?>">
                <i class="fa-solid fa-copy"></i> Copy Case Ref
            </button>
                     <?php
            // Quick status changer — only renders legal next-statuses for
            // this case's current status, so the menu can never offer an
            // invalid transition. Server-side validation still runs in the
            // change_status handler above; this is purely a convenience.
            $legalNextStatuses = [];
            foreach ($legalEditableStatuses as $candidateStatus) {
                if (t8_legal_case_status_transition_is_allowed((string) $c['status'], $candidateStatus)) {
                    $legalNextStatuses[] = $candidateStatus;
                }
            }
            ?>
            <?php if (!$archivedFilter && $c['status'] !== 'archived' && $legalNextStatuses !== []): ?>
                <div class="t8-row-menu-divider"></div>
                <div class="t8-row-menu-label" role="presentation">Change Status</div>
                <?php foreach ($legalNextStatuses as $legalNextStatus): ?>
                    <?php $legalNeedsResolution = in_array($legalNextStatus, ['resolved', 'closed'], true); ?>
                    <form method="post" action="<?= e(page_url('legal', ['action' => 'change_status'])) ?>"
                          onsubmit="return confirm('Change status to <?= e(ucwords(str_replace('_', ' ', $legalNextStatus))) ?>?<?= $legalNeedsResolution ? ' A resolution record must already exist.' : '' ?>');">
                        <?= t8_csrf_field() ?>
                        <input type="hidden" name="case_id" value="<?= e((string) $id) ?>">
                        <input type="hidden" name="next_status" value="<?= e($legalNextStatus) ?>">
                        <button class="t8-row-menu-item" type="submit" role="menuitem">
                            <i class="fa-solid fa-arrow-right"></i>
                            Move to <?= e(ucwords(str_replace('_', ' ', $legalNextStatus))) ?>
                        </button>
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($c['status'] === 'closed'): ?>
                <a class="t8-row-menu-item" role="menuitem" href="<?= e(page_url('legal', ['action' => 'retention', 'id' => $id])) ?>">
                    <i class="fa-solid fa-box-archive"></i> <?= $retentionRecord !== null ? 'Manage Retention' : 'View Retention' ?>
                </a>
            <?php endif; ?>
            <?php if (($isAdmin || t8_has_role('legal_officer')) && !$archivedFilter): ?>
                <div class="t8-row-menu-divider"></div>
                <a class="t8-row-menu-item" role="menuitem" href="<?= e(page_url('legal', ['action' => 'edit', 'id' => $id])) ?>">
                    <i class="fa-solid fa-pen"></i> Edit
                </a>
                <?php if ($isAdmin): ?>
                <?php if ($c['status'] === 'closed'): ?>
                <form method="post" action="<?= e(page_url('legal', ['action' => 'archive'])) ?>" onsubmit="return confirm('Archive this case?');">
                    <?= t8_csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <button class="t8-row-menu-item t8-danger" type="submit" role="menuitem">
                        <i class="fa-solid fa-box-archive"></i> Archive
                    </button>
                </form>
                <?php else: ?>
                    <button class="t8-row-menu-item t8-danger" type="button" role="menuitem" disabled title="Close the case before archiving it">
                        <i class="fa-solid fa-box-archive"></i> Archive
                    </button>
                <?php endif; ?>
                <?php endif; ?>
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
<header class="t8-legal-page-header">
    <div>
        <h1><?= $showDetails ? 'Case Details' : ($action === 'list' ? ($archivedFilter ? 'Archived Cases' : 'Legal Cases') : 'Legal Management') ?></h1>
        <p class="t8-help-text"><?= $showDetails ? 'Manage case information, deadlines, and resolution.' : ($isAdmin ? 'Track legal cases and their supporting documents.' : 'View legal cases assigned to you.') ?></p>
    </div>
    <?php if ($action === 'list'): ?>
        <nav class="t8-legal-page-actions" aria-label="Legal case actions">
            <?php if ($isAdmin): ?><a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal', ['action' => 'case_types'])) ?>"><i class="fa-solid fa-list"></i> Manage Case Types</a><?php endif; ?>
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal', array_merge($legalListFilters, ['archived' => $archivedFilter ? '0' : '1']))) ?>">
                <i class="fa-solid fa-box-archive"></i> <?= $archivedFilter ? 'View Active' : 'View Archived' ?>
            </a>
            <?php if ($isAdmin && !$archivedFilter): ?><a class="t8-btn t8-btn-accent" href="<?= e(page_url('legal', ['action' => 'create'])) ?>"><i class="fa-solid fa-plus"></i> New Legal Case</a><?php endif; ?>
        </nav>
    <?php elseif ($showDetails): ?>
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal')) ?>"><i class="fa-solid fa-arrow-left"></i> Back to Cases</a>
    <?php endif; ?>
</header>

<?php foreach ($errors as $error): ?>
    <div class="t8-alert t8-alert-danger"><?= e($error) ?></div>
<?php endforeach; ?>
<?php if ($showForm && (!$legalHasCaseCreationFields || !$legalHasCaseMetadata || !$legalHasPriority)): ?>
    <div class="t8-alert t8-alert-danger">The legal case creation migration must be applied before this form can be used.</div>
<?php endif; ?>

<?php if ($showDashboard): ?>
    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal')) ?>"><i class="fa-solid fa-list"></i> Case List</a>
    </div>
    <div class="t8-dashboard-summary-grid" style="margin-bottom:var(--t8-space-4)">
        <?php foreach ([
            'TOTAL ACTIVE CASES' => ['total_active', 'fa-scale-balanced'],
            'OPEN CASES' => ['open', 'fa-folder-open'],
            'UPCOMING DEADLINES' => ['upcoming_deadlines', 'fa-calendar-days'],
            'UPCOMING HEARINGS' => ['upcoming_hearings', 'fa-gavel'],
            'OVERDUE TASKS' => ['overdue_tasks', 'fa-triangle-exclamation'],
            'RECENTLY CLOSED' => ['recently_closed', 'fa-check-double'],
        ] as $label => [$key, $icon]): ?>
            <div class="t8-card t8-dashboard-stat-card"><div class="t8-dashboard-stat-icon" aria-hidden="true"><i class="fa-solid <?= e($icon) ?>"></i></div><div class="t8-dashboard-stat-body"><p class="t8-help-text"><?= e($label) ?></p><div class="t8-dashboard-stat-value"><?= e((string) $dashboardStats[$key]) ?></div></div></div>
        <?php endforeach; ?>
    </div>
    <div class="t8-main-grid">
        <div class="t8-card"><div class="t8-card-header"><h2 class="t8-card-title">Upcoming Deadlines</h2></div>
            <?php if ($dashboardDeadlines === []): ?><div class="t8-empty">No upcoming deadlines in the next 30 days.</div><?php else: ?><div class="t8-table-wrap"><table class="t8-table"><thead><tr><th>Case</th><th>Title</th><th>Deadline</th><th>Assigned To</th></tr></thead><tbody><?php foreach ($dashboardDeadlines as $deadline): ?><tr><td><a href="<?= e(page_url('legal', ['action' => 'view', 'id' => (int) $deadline['id']])) ?>"><?= e((string) ($deadline['case_number'] ?? 'CASE-' . str_pad((string) $deadline['id'], 6, '0', STR_PAD_LEFT))) ?></a></td><td><?= e((string) $deadline['title']) ?></td><td><?= e(format_date((string) $deadline['deadline'], 'M d, Y')) ?></td><td><?= e((string) $deadline['assigned_to_name']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        </div>
        <div class="t8-card"><div class="t8-card-header"><h2 class="t8-card-title">Upcoming Hearings</h2></div>
            <?php if ($dashboardHearings === []): ?><div class="t8-empty">No scheduled hearings in the next 30 days.</div><?php else: ?><div class="t8-table-wrap"><table class="t8-table"><thead><tr><th>Case</th><th>Proceeding</th><th>Date</th><th>Venue</th></tr></thead><tbody><?php foreach ($dashboardHearings as $hearing): ?><tr><td><a href="<?= e(page_url('legal', ['action' => 'view', 'id' => (int) $hearing['case_id']])) ?>"><?= e((string) ($hearing['case_number'] ?? 'CASE-' . str_pad((string) $hearing['case_id'], 6, '0', STR_PAD_LEFT))) ?></a></td><td><?= e((string) $hearing['hearing_type']) ?></td><td><?= e(format_date((string) $hearing['event_date'], 'M d, Y')) ?><?= !empty($hearing['event_time']) ? ' ' . e(substr((string) $hearing['event_time'], 0, 5)) : '' ?></td><td><?= e((string) ($hearing['venue'] ?: '—')) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        </div>
    </div>

<?php elseif ($showForm): ?>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= $action === 'edit' ? 'Edit Legal Case' : 'New Legal Case' ?></h2>
        </div>
        <form method="post"
              action="<?= e(page_url('legal', array_filter(['action' => $action, 'id' => $_GET['id'] ?? null]))) ?>"
              class="t8-legal-form-grid">
           
            <?= t8_csrf_field() ?>

            <div class="t8-field">
                <label class="t8-label" for="title">Case Title <span class="t8-required">*</span></label>
                <input class="t8-input" type="text" id="title" name="title"
                       value="<?= e($formValues['title']) ?>" maxlength="200" required>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="case_type_id">Case Type <span class="t8-required">*</span></label>
                <?php if ($currentCaseType !== null && !(bool) $currentCaseType['is_active']): ?>
                    <span class="t8-help-text">Existing type: <?= e($currentCaseType['name']) ?> (inactive; retained on this case).</span>
                    <select class="t8-select" id="case_type_id" name="case_type_id" required>
                        <option value="<?= e((string) $existing['case_type_id']) ?>" selected>Keep existing type</option>
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

            <div class="t8-field t8-form-span-2">
                <label class="t8-label" for="description">Description / Summary</label>
                <textarea class="t8-input" id="description" name="description" rows="4"><?= e($formValues['description']) ?></textarea>
            </div>

            <?php if ($legalHasCaseMetadata): ?>
                <details class="t8-form-span-2">
                    <summary class="t8-label">Other Details</summary>
                    <div class="t8-legal-form-grid">
                        <?php if ($isAdmin): ?><div class="t8-field">
                            <label class="t8-label" for="department_id">Department</label>
                            <select class="t8-select" id="department_id" name="department_id"><option value="">No department</option><?php foreach ($departments as $department): ?><option value="<?= e((string) $department['id']) ?>" <?= (string) $department['id'] === $formValues['department_id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select>
                        </div><?php else: ?>
                            <div class="t8-field"><span class="t8-label">Department</span><strong><?= e($formDepartmentName) ?></strong></div>
                        <?php endif; ?>
                        <?php if ($legalHasCaseInformation): ?>
                            <div class="t8-field"><label class="t8-label" for="court_agency">Court / Agency</label><input class="t8-input" id="court_agency" name="court_agency" maxlength="200" value="<?= e($formValues['court_agency']) ?>"></div>
                            <div class="t8-field"><label class="t8-label" for="branch_office">Branch / Office</label><input class="t8-input" id="branch_office" name="branch_office" maxlength="150" value="<?= e($formValues['branch_office']) ?>"></div>
                            <div class="t8-field"><label class="t8-label" for="docket_reference">Docket / Reference Number</label><input class="t8-input" id="docket_reference" name="docket_reference" maxlength="150" value="<?= e($formValues['docket_reference']) ?>"></div>
                            <div class="t8-field"><label class="t8-label" for="jurisdiction">Jurisdiction</label><input class="t8-input" id="jurisdiction" name="jurisdiction" maxlength="150" value="<?= e($formValues['jurisdiction']) ?>"></div>
                            <div class="t8-field"><label class="t8-label" for="location">Location</label><input class="t8-input" id="location" name="location" maxlength="200" value="<?= e($formValues['location']) ?>"></div>
                            <div class="t8-field t8-form-span-2"><label class="t8-label" for="legal_basis">Legal Basis / Applicable Law</label><textarea class="t8-input" id="legal_basis" name="legal_basis" rows="3" maxlength="5000"><?= e($formValues['legal_basis']) ?></textarea></div>
                            <div class="t8-field t8-form-span-2"><label class="t8-label" for="current_action">Current Action / Next Step</label><textarea class="t8-input" id="current_action" name="current_action" rows="3" maxlength="5000"><?= e($formValues['current_action']) ?></textarea></div>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>

            <?php if ($action === 'edit'): ?><div class="t8-field">
                <label class="t8-label" for="status">Status <span class="t8-required">*</span></label>
                <select class="t8-select" id="status" name="status" required data-current-status="<?= e((string) ($existing['status'] ?? $formValues['status'])) ?>">
                    <?php foreach ($formStatusOptions as $s): ?>
                        <option value="<?= e($s) ?>" <?= $s === $formValues['status'] ? 'selected' : '' ?>>
                            <?= e(ucwords(str_replace('_', ' ', $s))) ?><?= !in_array($s, $legalEditableStatuses, true) ? ' (inactive)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($existing !== null && $existing['status'] === 'closed'): ?>
                    <span class="t8-help-text">This case is already closed<?= !empty($existing['closed_at']) ? ' (on ' . e(format_date((string) $existing['closed_at'], 'M j, Y g:i A')) . ')' : '' ?> and under retention. Manage disposal from its Retention screen, not by changing status here.</span>
                <?php elseif ($existing !== null): ?>
                    <span class="t8-help-text">Setting this to "Closed" registers the case under retention automatically.</span>
                <?php endif; ?>
            </div><?php endif; ?>

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
                <label class="t8-label" for="deadline">Deadline <span class="t8-required">*</span></label>
                <input class="t8-input" type="date" id="deadline" name="deadline" value="<?= e($formValues['deadline']) ?>" data-t8-date-rule="future">
            </div><?php endif; ?>

            <?php if ($legalHasCaseMetadata): ?>
            <script>
                document.addEventListener("DOMContentLoaded", function () {
                    var filedDate = document.getElementById("filed_date");
                    var deadlineInput = document.getElementById("deadline");
                    var statusSelect = document.getElementById("status");
                    var dateFields = [deadlineInput].filter(Boolean);
                    if (!filedDate || dateFields.length === 0) {
                        return;
                    }

                    function syncDeadlineRequired() {
                        if (!deadlineInput) { return; }
                        var status = statusSelect ? statusSelect.value : "under_review";
                        deadlineInput.required = status !== "under_review";
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
                    if (statusSelect) {
                        statusSelect.addEventListener("change", syncDeadlineRequired);
                    }
                    syncDeadlineRequired();
                    syncDateMinimums();
                    window.setTimeout(syncDateMinimums, 0);
                });
            </script>
            <?php endif; ?>

            <?php if ($isAdmin): ?><div class="t8-field">
                <label class="t8-label" for="assigned_to">Assigned Legal Officer <span class="t8-required">*</span></label>
                <select class="t8-select" id="assigned_to" name="assigned_to" required>
                    <option value="">Select a person…</option>
                    <?php foreach ($legalOfficers as $a): ?>
                        <option value="<?= e((string) $a['id']) ?>" <?= (string) $a['id'] === $formValues['assigned_to'] ? 'selected' : '' ?>>
                            <?= e($a['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div><?php else: ?><div class="t8-field"><span class="t8-label">Assigned Legal Officer</span><strong><?= e($formAssignedOfficerName) ?></strong></div><?php endif; ?>

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

<?php elseif ($showDetails): ?>

    <nav class="t8-legal-detail-tabs" aria-label="Case workspace">
        <a class="is-active" href="#legal-case-overview-heading" aria-current="page">Overview</a>
        <a href="<?= e(page_url('legal', ['action' => 'documents', 'id' => $caseId])) ?>">Documents</a>
        <?php if ($legalHasTaskSchema): ?><a href="<?= e(page_url('legal', ['action' => 'tasks', 'id' => $caseId])) ?>">Tasks</a><?php endif; ?>
        <?php if ($legalHasPartySchema): ?>
            <a href="<?= e(page_url('legal', ['action' => 'parties', 'id' => $caseId])) ?>">Parties</a>
        <?php endif; ?>
        <?php if ($legalHasNotesSchema): ?>
            <a href="<?= e(page_url('legal', ['action' => 'notes', 'id' => $caseId])) ?>">Notes</a>
        <?php endif; ?>
        <details class="t8-legal-tab-more">
            <summary>More <i class="fa-solid fa-chevron-down" aria-hidden="true"></i></summary>
            <div class="t8-legal-tab-menu">
                <a href="<?= e(page_url('legal', ['action' => 'dashboard'])) ?>">Legal Dashboard</a>
                <?php if ($legalHasHearingSchema): ?><a href="<?= e(page_url('legal', ['action' => 'hearings', 'id' => $caseId])) ?>">Hearings</a><?php endif; ?>
                <?php if ($legalHasCommunicationSchema): ?><a href="<?= e(page_url('legal', ['action' => 'communications', 'id' => $caseId])) ?>">Communications</a><?php endif; ?>
                <?php if ($legalHasResolutionSchema): ?><a href="<?= e(page_url('legal', ['action' => 'resolution', 'id' => $caseId])) ?>">Resolution</a><?php endif; ?>
                <a href="<?= e(page_url('legal', ['action' => 'timeline', 'id' => $caseId])) ?>">Timeline</a>
                <?php if ($case['status'] === 'closed'): ?><a href="<?= e(page_url('legal', ['action' => 'retention', 'id' => $caseId])) ?>">Retention</a><?php endif; ?>
                <?php if ($isAdmin && $case['status'] !== 'archived'): ?><a href="<?= e(page_url('legal', ['action' => 'edit', 'id' => $caseId])) ?>">Edit Case</a><?php endif; ?>
            </div>
        </details>
    </nav>

    <div class="t8-legal-detail-layout">
    <div class="t8-legal-detail-main">
    <article class="t8-card t8-legal-case-card">
        <div class="t8-card-header t8-legal-case-header">
            <div>
                <h2 class="t8-card-title"><?= e((string) $case['title']) ?></h2>
                <p class="t8-help-text"><?= e((string) ($case['case_number'] ?? ('CASE-' . str_pad((string) $caseId, 6, '0', STR_PAD_LEFT)))) ?> &middot; <?= e((string) $detailCaseType) ?></p>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <span class="t8-badge <?= e(t8_legal_status_badge((string) $case['status'])) ?>"><?= e(ucwords(str_replace('_', ' ', (string) $case['status']))) ?></span>
                <span class="t8-badge t8-badge-pending"><?= e(ucfirst((string) ($case['priority'] ?? 'medium'))) ?> Priority</span>
                <?php if (t8_legal_is_monitoring($case)): ?><span class="t8-badge-monitoring">Monitoring</span><?php endif; ?>
            </div>
        </div>

        <section aria-labelledby="legal-case-overview-heading" class="t8-legal-case-overview">
            <h3 id="legal-case-overview-heading">Overview</h3>
            <div class="t8-legal-overview-grid">
                <div class="t8-legal-overview-item">
                    <span class="t8-legal-overview-label">Filed Date</span>
                    <strong class="t8-legal-overview-value"><?= e(format_date((string) $case['filed_date'], 'M d, Y')) ?></strong>
                </div>
                <div class="t8-legal-overview-item">
                    <span class="t8-legal-overview-label">Deadline</span>
                    <strong class="t8-legal-overview-value"><?= !empty($case['deadline']) ? e(format_date((string) $case['deadline'], 'M d, Y')) : '—' ?></strong>
                </div>
                <?php if ($case['status'] === 'closed' && !empty($case['closed_at'])): ?>
                <div class="t8-legal-overview-item">
                    <span class="t8-legal-overview-label">Closed At</span>
                    <strong class="t8-legal-overview-value"><?= e(format_date((string) $case['closed_at'], 'M j, Y g:i A')) ?></strong>
                </div>
                <?php endif; ?>
                <div class="t8-legal-overview-item">
                    <span class="t8-legal-overview-label">Assigned Legal Officer</span>
                    <strong class="t8-legal-overview-value"><?= e((string) $case['assigned_to_name']) ?></strong>
                </div>
                <div class="t8-legal-overview-item">
                    <span class="t8-legal-overview-label">Department</span>
                    <strong class="t8-legal-overview-value"><?= e($detailDepartment) ?></strong>
                </div>
                <?php if ($legalHasCaseMetadata && !empty($case['subject'])): ?>
                <div class="t8-legal-overview-item t8-legal-overview-item-wide">
                    <span class="t8-legal-overview-label">Subject</span>
                    <strong class="t8-legal-overview-value"><?= e((string) $case['subject']) ?></strong>
                </div>
                <?php endif; ?>
            </div>
            <?php if (!$legalHasCaseMetadata && trim((string) ($case['subject'] ?? '')) !== ''): ?>
                <h3>Subject</h3>
                <p><?= nl2br(e((string) $case['subject'])) ?></p>
            <?php endif; ?>

            <h3>Description / Summary</h3>
            <?php if (trim((string) ($case['description'] ?? '')) !== '' && $case['description'] !== 'asd'): ?>
                <p><?= nl2br(e((string) $case['description'])) ?></p>
            <?php else: ?>
                <p class="t8-help-text">No case summary has been added.</p>
            <?php endif; ?>
            <?php if ($legalHasCaseInformation): ?>
                <h3>External / Legal Information</h3>
                <div class="t8-legal-overview-grid">
                    <?php foreach ([
                        'Court / Agency' => 'court_agency',
                        'Branch / Office' => 'branch_office',
                        'Docket / Reference Number' => 'docket_reference',
                        'Jurisdiction' => 'jurisdiction',
                        'Location' => 'location',
                    ] as $label => $field): ?>
                        <?php if (!empty($case[$field])): ?>
                            <div class="t8-legal-overview-item">
                                <span class="t8-legal-overview-label"><?= e($label) ?></span>
                                <strong class="t8-legal-overview-value"><?= e((string) $case[$field]) ?></strong>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($case['legal_basis'])): ?>
                    <h4>Legal Basis / Applicable Law</h4>
                    <p><?= nl2br(e((string) $case['legal_basis'])) ?></p>
                <?php endif; ?>
                <?php if (!empty($case['current_action'])): ?>
                    <h4>Current Action / Next Step</h4>
                    <p><?= nl2br(e((string) $case['current_action'])) ?></p>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($legalHasResolutionSchema): ?>
                <h3>Resolution</h3>
                <?php if ($caseResolution !== null): ?>
                    <div class="t8-legal-overview-grid">
                        <div class="t8-legal-overview-item">
                            <span class="t8-legal-overview-label">Type</span>
                            <strong class="t8-legal-overview-value"><?= e($caseResolution['resolution_type']) ?></strong>
                        </div>
                        <div class="t8-legal-overview-item">
                            <span class="t8-legal-overview-label">Resolved Date</span>
                            <strong class="t8-legal-overview-value"><?= e(format_date((string) $caseResolution['resolved_date'], 'M d, Y')) ?></strong>
                        </div>
                        <?php if (!empty($caseResolution['final_outcome'])): ?>
                        <div class="t8-legal-overview-item">
                            <span class="t8-legal-overview-label">Final Outcome</span>
                            <strong class="t8-legal-overview-value"><?= e((string) $caseResolution['final_outcome']) ?></strong>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($caseResolution['supporting_version_id'])): ?>
                        <div class="t8-legal-overview-item">
                            <span class="t8-legal-overview-label">Supporting Document</span>
                            <strong class="t8-legal-overview-value">
                                <a href="<?= e(page_url('documents', ['action' => 'download', 'version_id' => $caseResolution['supporting_version_id']])) ?>">
                                    <?= e((string) $caseResolution['supporting_document_title']) ?>
                                </a>
                            </strong>
                        </div>
                        <?php endif; ?>
                    </div>
                    <p><?= nl2br(e((string) $caseResolution['resolution_summary'])) ?></p>
                <?php elseif (in_array($case['status'], ['resolved', 'closed'], true)): ?>
                    <div class="t8-alert t8-alert-warning">No resolution record is associated with this legacy case.</div>
                <?php else: ?>
                    <p class="t8-help-text">Record the resolution before moving this case to Resolved or Closed.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </article>
        </div>

    <aside class="t8-legal-detail-sidebar">
        <section class="t8-card t8-legal-sidebar-card" aria-labelledby="legal-documents-heading">
            <header class="t8-legal-sidebar-heading">
                <h2 id="legal-documents-heading">Recent Documents</h2>
                <a href="<?= e(page_url('legal', ['action' => 'documents', 'id' => $caseId])) ?>">View All</a>
            </header>
            <?php if ($detailDocuments === []): ?>
                <p class="t8-help-text">No documents are linked to this case.</p>
            <?php else: ?>
                <ul class="t8-legal-document-list">
                    <?php foreach ($detailDocuments as $document): ?>
                        <li><i class="fa-regular fa-file-lines" aria-hidden="true"></i><div><strong><?= e((string) $document['title']) ?></strong><span>Added <?= e(format_date((string) $document['created_at'], 'M d, Y')) ?></span></div></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="t8-card t8-legal-sidebar-card" aria-labelledby="legal-activity-heading">
            <header class="t8-legal-sidebar-heading">
                <h2 id="legal-activity-heading">Activity Timeline</h2>
                <a href="<?= e(page_url('legal', ['action' => 'timeline', 'id' => $caseId])) ?>">Full Timeline</a>
            </header>
            <?php if ($detailActivity === []): ?>
                <p class="t8-help-text">No case activity has been recorded yet.</p>
            <?php else: ?>
                <ol class="t8-legal-activity-list">
                    <?php foreach ($detailActivity as $event): ?>
                        <li>
                            <strong><?= e(t8_legal_timeline_event_title((string) $event['entity_type'], (string) $event['action'])) ?></strong>
                            <span><?= e((string) $event['actor_name']) ?></span>
                            <time datetime="<?= e((string) $event['created_at']) ?>"><?= e(format_date((string) $event['created_at'], 'M d, Y g:i A')) ?></time>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
    </aside>
    </div>

<?php elseif ($showResolution): ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal', ['action' => 'view', 'id' => $caseId])) ?>"><i class="fa-solid fa-arrow-left"></i> Case Workspace</a>
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal')) ?>">Back to Cases</a>
    </div>
    <div class="t8-card">
        <div class="t8-card-header"><h2 class="t8-card-title">Resolution &mdash; <?= e((string) $case['title']) ?></h2></div>
        <?php if (!$legalHasResolutionSchema): ?>
            <div class="t8-alert t8-alert-danger">Apply the legal case resolutions migration before recording a resolution.</div>
        <?php elseif ($resolution !== null): ?>
            <div class="t8-hr-readonly-block">
                <div class="t8-hr-readonly-item"><span>Resolution Type</span><strong><?= e($resolution['resolution_type']) ?></strong></div>
                <div class="t8-hr-readonly-item"><span>Resolved Date</span><strong><?= e(format_date((string) $resolution['resolved_date'], 'M d, Y')) ?></strong></div>
                <div class="t8-hr-readonly-item"><span>Recorded By</span><strong><?= e((string) $resolution['recorded_by_name']) ?></strong></div>
                <?php if (!empty($resolution['final_outcome'])): ?><div class="t8-hr-readonly-item"><span>Final Outcome</span><strong><?= e((string) $resolution['final_outcome']) ?></strong></div><?php endif; ?>
                <?php if (!empty($resolution['supporting_version_id'])): ?><div class="t8-hr-readonly-item"><span>Supporting Document</span><strong><a href="<?= e(page_url('documents', ['action' => 'download', 'version_id' => $resolution['supporting_version_id']])) ?>"><?= e((string) $resolution['supporting_document_title']) ?></a></strong></div><?php endif; ?>
            </div>
            <h3>Resolution Summary</h3>
            <p><?= nl2br(e((string) $resolution['resolution_summary'])) ?></p>
        <?php endif; ?>

        <?php if ($legalHasResolutionSchema && in_array($case['status'], ['active', 'resolved'], true)): ?>
            <section style="padding: var(--t8-space-4);">
                <h3><?= $resolution !== null ? 'Update Resolution' : 'Record Resolution' ?></h3>
                <form method="post" action="<?= e(page_url('legal', ['action' => 'resolution', 'id' => $caseId])) ?>" class="t8-legal-form-grid">
                    <?= t8_csrf_field() ?>
                    <div class="t8-field"><label class="t8-label" for="resolution_type">Resolution Type</label><select class="t8-select" id="resolution_type" name="resolution_type" required><option value="">Select type</option><?php foreach ($legalResolutionTypes as $type): ?><option value="<?= e($type) ?>" <?= $resolutionInput['resolution_type'] === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></div>
                    <div class="t8-field"><label class="t8-label" for="resolved_date">Resolved Date</label><input class="t8-input" type="date" id="resolved_date" name="resolved_date" min="<?= e((string) $case['filed_date']) ?>" max="<?= e(date('Y-m-d')) ?>" value="<?= e($resolutionInput['resolved_date']) ?>" required></div>
                    <div class="t8-field t8-form-span-2"><label class="t8-label" for="resolution_summary">Resolution Summary</label><textarea class="t8-input" id="resolution_summary" name="resolution_summary" rows="4" maxlength="5000" required><?= e($resolutionInput['resolution_summary']) ?></textarea></div>
                    <div class="t8-field t8-form-span-2"><label class="t8-label" for="final_outcome">Final Outcome</label><textarea class="t8-input" id="final_outcome" name="final_outcome" rows="3" maxlength="5000"><?= e($resolutionInput['final_outcome']) ?></textarea></div>
                    <div class="t8-field t8-form-span-2"><label class="t8-label" for="resolution_document">Supporting Document</label><select class="t8-select" id="resolution_document" name="supporting_legal_document_id"><option value="">None</option><?php foreach ($resolutionDocuments as $document): ?><option value="<?= e((string) $document['id']) ?>" <?= $resolutionInput['supporting_legal_document_id'] === (string) $document['id'] ? 'selected' : '' ?>><?= e($document['title']) ?></option><?php endforeach; ?></select></div>
                    <div class="t8-form-actions"><button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-check"></i> Save Resolution</button></div>
                </form>
            </section>
        <?php elseif (!$legalHasResolutionSchema): ?>
            <div class="t8-alert t8-alert-danger">Resolution is unavailable until the migration is applied.</div>
        <?php elseif (!in_array($case['status'], ['closed', 'archived'], true)): ?>
            <div class="t8-alert t8-alert-info">Move the case to Active before recording a resolution.</div>
        <?php else: ?>
            <div class="t8-alert t8-alert-info">Closed and archived case resolutions are read-only.</div>
        <?php endif; ?>
    </div>

<?php elseif ($showTimeline): ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal', ['action' => 'view', 'id' => $caseId])) ?>"><i class="fa-solid fa-arrow-left"></i> Case Workspace</a>
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal')) ?>">Back to Cases</a>
    </div>
    <div class="t8-card">
        <div class="t8-card-header"><h2 class="t8-card-title">Case Timeline &mdash; <?= e((string) $case['title']) ?></h2></div>
        <?php if ($timelineEvents === []): ?>
            <div class="t8-empty">No case activity has been recorded yet.</div>
        <?php else: ?>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead><tr><th>Date / Time</th><th>Event</th><th>Details</th><th>By</th></tr></thead>
                    <tbody>
                        <?php foreach ($timelineEvents as $event): ?>
                            <?php $timelineDetail = t8_legal_timeline_event_detail($event); ?>
                            <tr>
                                <td><?= e(format_date((string) $event['created_at'], 'M d, Y g:i A')) ?></td>
                                <td><?= e(t8_legal_timeline_event_title((string) $event['entity_type'], (string) $event['action'])) ?></td>
                                <td><?= $timelineDetail !== '' ? e($timelineDetail) : '—' ?></td>
                                <td><?= e($event['actor_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($showCommunications): ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal', ['action' => 'view', 'id' => $caseId])) ?>"><i class="fa-solid fa-arrow-left"></i> Case Workspace</a>
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal')) ?>">Back to Cases</a>
    </div>
    <div class="t8-card">
        <div class="t8-card-header"><h2 class="t8-card-title">Communications &mdash; <?= e((string) $case['title']) ?></h2></div>
        <?php if (!$legalHasCommunicationSchema): ?>
            <div class="t8-alert t8-alert-danger">Apply the legal case communications migration before recording communications.</div>
        <?php else: ?>
            <?php if ($communications === []): ?>
                <div class="t8-empty">No communications have been recorded for this case.</div>
            <?php else: ?>
                <div class="t8-table-wrap">
                    <table class="t8-table">
                        <thead><tr><th>Date</th><th>Type / Direction</th><th>Sender / Recipient</th><th>Subject / Summary</th><th>Attachment</th><th>Recorded By</th></tr></thead>
                        <tbody>
                            <?php foreach ($communications as $communication): ?>
                                <tr>
                                    <td><?= e(format_date((string) $communication['communication_date'], 'M d, Y')) ?><?= !empty($communication['communication_time']) ? '<br>' . e(date('g:i A', strtotime((string) $communication['communication_time']))) : '' ?></td>
                                    <td><?= e((string) $communication['communication_type']) ?><br><?= e(ucfirst((string) $communication['direction'])) ?></td>
                                    <td><?= e((string) ($communication['sender'] ?: '—')) ?><br><?= e((string) ($communication['recipient'] ?: '—')) ?></td>
                                    <td><strong><?= e((string) ($communication['subject'] ?: '—')) ?></strong><br><?= nl2br(e((string) $communication['summary'])) ?></td>
                                    <td><?php if (!empty($communication['attachment_version_id'])): ?><a href="<?= e(page_url('documents', ['action' => 'download', 'version_id' => $communication['attachment_version_id']])) ?>"><i class="fa-solid fa-download"></i> <?= e((string) $communication['attachment_title']) ?></a><?php else: ?>—<?php endif; ?></td>
                                    <td><?= e($communication['recorder_name']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($case['status'] !== 'archived'): ?>
                <section style="padding: var(--t8-space-4);">
                    <h3>Record Communication</h3>
                    <form method="post" action="<?= e(page_url('legal', ['action' => 'communications', 'id' => $caseId])) ?>" class="t8-legal-form-grid">
                        <?= t8_csrf_field() ?>
                        <div class="t8-field"><label class="t8-label" for="communication_date">Date</label><input class="t8-input" type="date" id="communication_date" name="communication_date" value="<?= e($communicationInput['date']) ?>" required></div>
                        <div class="t8-field"><label class="t8-label" for="communication_time">Time</label><input class="t8-input" type="time" id="communication_time" name="communication_time" value="<?= e($communicationInput['time']) ?>"></div>
                        <div class="t8-field"><label class="t8-label" for="communication_type">Communication Type</label><select class="t8-select" id="communication_type" name="communication_type" required><option value="">Select type</option><?php foreach ($legalCommunicationTypes as $type): ?><option value="<?= e($type) ?>" <?= $communicationInput['type'] === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></div>
                        <div class="t8-field"><label class="t8-label" for="communication_direction">Direction</label><select class="t8-select" id="communication_direction" name="direction" required><option value="incoming" <?= $communicationInput['direction'] === 'incoming' ? 'selected' : '' ?>>Incoming</option><option value="outgoing" <?= $communicationInput['direction'] === 'outgoing' ? 'selected' : '' ?>>Outgoing</option></select></div>
                        <div class="t8-field"><label class="t8-label" for="communication_sender">Sender</label><input class="t8-input" id="communication_sender" name="sender" maxlength="200" value="<?= e($communicationInput['sender']) ?>"></div>
                        <div class="t8-field"><label class="t8-label" for="communication_recipient">Recipient</label><input class="t8-input" id="communication_recipient" name="recipient" maxlength="200" value="<?= e($communicationInput['recipient']) ?>"></div>
                        <div class="t8-field t8-form-span-2"><label class="t8-label" for="communication_subject">Subject</label><input class="t8-input" id="communication_subject" name="subject" maxlength="200" value="<?= e($communicationInput['subject']) ?>"></div>
                        <div class="t8-field t8-form-span-2"><label class="t8-label" for="communication_summary">Summary</label><textarea class="t8-input" id="communication_summary" name="summary" rows="4" maxlength="5000" required><?= e($communicationInput['summary']) ?></textarea></div>
                        <div class="t8-field t8-form-span-2"><label class="t8-label" for="communication_attachment">Attached Legal Document</label><select class="t8-select" id="communication_attachment" name="attachment_legal_document_id"><option value="">None</option><?php foreach ($caseLegalDocuments as $legalDocument): ?><option value="<?= e((string) $legalDocument['id']) ?>" <?= $communicationInput['attachment_legal_document_id'] === (string) $legalDocument['id'] ? 'selected' : '' ?>><?= e($legalDocument['title']) ?></option><?php endforeach; ?></select></div>
                        <div class="t8-form-actions"><button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-plus"></i> Record Communication</button></div>
                    </form>
                </section>
            <?php else: ?>
                <div class="t8-alert t8-alert-info">Archived cases are read-only.</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

<?php elseif ($showNotes): ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal', ['action' => 'view', 'id' => $caseId])) ?>"><i class="fa-solid fa-arrow-left"></i> Case Workspace</a>
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal')) ?>">Back to Cases</a>
    </div>
    <div class="t8-card">
        <div class="t8-card-header"><h2 class="t8-card-title">Case Notes &mdash; <?= e((string) $case['title']) ?></h2></div>
        <?php if (!$legalHasNotesSchema): ?>
            <div class="t8-alert t8-alert-danger">Apply the legal case notes migration before viewing notes.</div>
        <?php else: ?>
            <?php if ($caseNotes === []): ?>
                <div class="t8-empty">No internal notes have been added to this case.</div>
            <?php else: ?>
                <div class="t8-table-wrap">
                    <table class="t8-table">
                        <thead><tr><th>Note</th><th>Author</th><th>Date / Time</th></tr></thead>
                        <tbody>
                            <?php foreach ($caseNotes as $note): ?>
                                <tr>
                                    <td><?= nl2br(e((string) $note['content'])) ?></td>
                                    <td><?= e($note['author_name']) ?></td>
                                    <td><?= e(format_date((string) $note['created_at'], 'M d, Y g:i A')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php if ($case['status'] !== 'archived'): ?>
                <section style="padding: var(--t8-space-4);">
                    <h3>Add Internal Note</h3>
                    <form method="post" action="<?= e(page_url('legal', ['action' => 'notes', 'id' => $caseId])) ?>">
                        <?= t8_csrf_field() ?>
                        <div class="t8-field"><label class="t8-label" for="legal_case_note">Note</label><textarea class="t8-input" id="legal_case_note" name="content" rows="5" maxlength="5000" required><?= e($noteContent) ?></textarea></div>
                        <button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-plus"></i> Add Note</button>
                    </form>
                </section>
            <?php else: ?>
                <div class="t8-alert t8-alert-info">Archived cases are read-only.</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

<?php elseif ($showHearings): ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal', ['action' => 'view', 'id' => $caseId])) ?>"><i class="fa-solid fa-arrow-left"></i> Case Workspace</a>
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal')) ?>">Back to Cases</a>
    </div>
    <div class="t8-card">
        <div class="t8-card-header"><h2 class="t8-card-title">Hearings &amp; Proceedings &mdash; <?= e((string) $case['title']) ?></h2></div>
        <?php if (!$legalHasHearingSchema): ?>
            <div class="t8-alert t8-alert-danger">Apply the legal case hearings migration before managing proceedings.</div>
        <?php else: ?>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead><tr><th>Date / Time</th><th>Venue</th><th>Type</th><th>Purpose</th><th>Status</th><th>Notes</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($hearings as $hearing): ?>
                            <tr>
                                <td><?= e(format_date((string) $hearing['event_date'], 'M d, Y')) ?><?= !empty($hearing['event_time']) ? '<br>' . e(date('g:i A', strtotime((string) $hearing['event_time']))) : '' ?></td>
                                <td><?= e((string) ($hearing['venue'] ?: '—')) ?></td>
                                <td><?= e($hearing['hearing_type']) ?></td>
                                <td><?= e((string) ($hearing['purpose'] ?: '—')) ?></td>
                                <td><span class="t8-badge <?= e($hearing['status'] === 'completed' ? 't8-badge-approved' : ($hearing['status'] === 'cancelled' ? 't8-badge-cancelled' : 't8-badge-pending')) ?>"><?= e(ucfirst((string) $hearing['status'])) ?></span></td>
                                <td><?= e((string) ($hearing['notes'] ?: '—')) ?></td>
                                <td><?php if ($case['status'] !== 'archived'): ?><a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('legal', ['action' => 'hearings', 'id' => $caseId, 'edit_id' => (int) $hearing['id']])) ?>"><i class="fa-solid fa-pen"></i> Edit</a><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($hearings === []): ?><tr><td colspan="7" class="t8-empty">No hearings or proceedings have been scheduled.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($case['status'] !== 'archived'): ?>
                <section style="padding: var(--t8-space-4);">
                    <h3><?= $editHearingId !== false ? 'Edit Hearing / Proceeding' : 'Add Hearing / Proceeding' ?></h3>
                    <form method="post" action="<?= e(page_url('legal', ['action' => 'hearings', 'id' => $caseId])) ?>" class="t8-legal-form-grid">
                        <?= t8_csrf_field() ?>
                        <input type="hidden" name="hearing_action" value="<?= $editHearingId !== false ? 'update' : 'create' ?>">
                        <?php if ($editHearingId !== false): ?><input type="hidden" name="hearing_id" value="<?= e((string) $editHearingId) ?>"><?php endif; ?>
                        <div class="t8-field"><label class="t8-label" for="hearing_date">Date</label><input class="t8-input" type="date" id="hearing_date" name="event_date" min="<?= e((string) $case['filed_date']) ?>" value="<?= e($hearingInput['event_date']) ?>" required></div>
                        <div class="t8-field"><label class="t8-label" for="hearing_time">Time</label><input class="t8-input" type="time" id="hearing_time" name="event_time" value="<?= e($hearingInput['event_time']) ?>"></div>
                        <div class="t8-field"><label class="t8-label" for="hearing_venue">Venue</label><input class="t8-input" id="hearing_venue" name="venue" maxlength="200" value="<?= e($hearingInput['venue']) ?>"></div>
                        <div class="t8-field"><label class="t8-label" for="hearing_type">Hearing / Proceeding Type</label><input class="t8-input" id="hearing_type" name="hearing_type" maxlength="150" value="<?= e($hearingInput['hearing_type']) ?>" required></div>
                        <div class="t8-field t8-form-span-2"><label class="t8-label" for="hearing_purpose">Purpose</label><input class="t8-input" id="hearing_purpose" name="purpose" maxlength="500" value="<?= e($hearingInput['purpose']) ?>"></div>
                        <div class="t8-field"><label class="t8-label" for="hearing_status">Status</label><select class="t8-select" id="hearing_status" name="status" required><?php foreach ($hearingStatuses as $hearingStatus): ?><option value="<?= e($hearingStatus) ?>" <?= $hearingInput['status'] === $hearingStatus ? 'selected' : '' ?>><?= e(ucfirst($hearingStatus)) ?></option><?php endforeach; ?></select></div>
                        <div class="t8-field t8-form-span-2"><label class="t8-label" for="hearing_notes">Notes</label><textarea class="t8-input" id="hearing_notes" name="notes" rows="3" maxlength="5000"><?= e($hearingInput['notes']) ?></textarea></div>
                        <div class="t8-form-actions"><button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-check"></i> <?= $editHearingId !== false ? 'Save Hearing' : 'Add Hearing' ?></button><?php if ($editHearingId !== false): ?><a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal', ['action' => 'hearings', 'id' => $caseId])) ?>">Cancel</a><?php endif; ?></div>
                    </form>
                </section>
            <?php else: ?>
                <div class="t8-alert t8-alert-info">Archived cases are read-only.</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

<?php elseif ($showTasks): ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal', ['action' => 'view', 'id' => $caseId])) ?>"><i class="fa-solid fa-arrow-left"></i> Case Workspace</a>
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal')) ?>">Back to Cases</a>
    </div>
    <div class="t8-card">
        <div class="t8-card-header"><h2 class="t8-card-title">Tasks &amp; Deadlines &mdash; <?= e((string) $case['title']) ?></h2></div>
        <?php if (!$legalHasTaskSchema): ?>
            <div class="t8-alert t8-alert-danger">Apply the legal case tasks migration before managing tasks.</div>
        <?php else: ?>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead><tr><th>Task</th><th>Assigned To</th><th>Due Date</th><th>Priority</th><th>Status</th><th>Update Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($tasks as $task): ?>
                            <?php $displayTaskStatus = t8_legal_task_display_status($task); ?>
                            <tr>
                                <td><strong><?= e($task['title']) ?></strong><?php if (!empty($task['description'])): ?><br><span class="t8-help-text"><?= nl2br(e((string) $task['description'])) ?></span><?php endif; ?></td>
                                <td><?= e($task['assignee_name']) ?></td>
                                <td><?= e(format_date((string) $task['due_date'], 'M d, Y')) ?></td>
                                <td><?= e(ucfirst((string) $task['priority'])) ?></td>
                                <td><span class="t8-badge <?= e(t8_legal_task_status_badge($displayTaskStatus)) ?>"<?= $displayTaskStatus === 'overdue' ? ' title="Computed from the due date"' : '' ?>><?= e(ucwords(str_replace('_', ' ', $displayTaskStatus))) ?></span></td>
                                <td>
                                    <?php if ($case['status'] !== 'archived'): ?>
                                        <form method="post" action="<?= e(page_url('legal', ['action' => 'tasks', 'id' => $caseId])) ?>" style="display:flex; gap:6px; align-items:center;">
                                            <?= t8_csrf_field() ?>
                                            <input type="hidden" name="task_action" value="status">
                                            <input type="hidden" name="task_id" value="<?= e((string) $task['id']) ?>">
                                            <select class="t8-select" name="status" aria-label="Status for <?= e($task['title']) ?>">
                                                <?php foreach (['pending', 'in_progress', 'completed', 'cancelled'] as $taskStatus): ?><option value="<?= e($taskStatus) ?>" <?= $task['status'] === $taskStatus ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $taskStatus))) ?></option><?php endforeach; ?>
                                            </select>
                                            <button class="t8-btn t8-btn-outline t8-btn-sm" type="submit" title="Update task status" aria-label="Update status for <?= e($task['title']) ?>"><i class="fa-solid fa-check"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($tasks === []): ?><tr><td colspan="6" class="t8-empty">No tasks have been added to this case.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($case['status'] !== 'archived'): ?>
                <section style="padding: var(--t8-space-4);">
                    <h3>Add Task</h3>
                    <form method="post" action="<?= e(page_url('legal', ['action' => 'tasks', 'id' => $caseId])) ?>" class="t8-legal-form-grid">
                        <?= t8_csrf_field() ?>
                        <input type="hidden" name="task_action" value="create">
                        <div class="t8-field t8-form-span-2"><label class="t8-label" for="task_title">Task</label><input class="t8-input" id="task_title" name="title" maxlength="200" value="<?= e($taskInput['title']) ?>" required></div>
                        <div class="t8-field"><label class="t8-label" for="task_assigned_to">Assigned To</label><select class="t8-select" id="task_assigned_to" name="assigned_to" required><option value="">Select a Legal Officer</option><?php foreach ($legalOfficers as $assignee): ?><option value="<?= e((string) $assignee['id']) ?>" <?= $taskInput['assigned_to'] === (string) $assignee['id'] ? 'selected' : '' ?>><?= e($assignee['full_name']) ?></option><?php endforeach; ?></select></div>
                        <div class="t8-field"><label class="t8-label" for="task_due_date">Due Date</label><input class="t8-input" type="date" id="task_due_date" name="due_date" min="<?= e((string) $case['filed_date']) ?>" value="<?= e($taskInput['due_date']) ?>" required></div>
                        <div class="t8-field"><label class="t8-label" for="task_priority">Priority</label><select class="t8-select" id="task_priority" name="priority" required><?php foreach ($taskPriorities as $taskPriority): ?><option value="<?= e($taskPriority) ?>" <?= $taskInput['priority'] === $taskPriority ? 'selected' : '' ?>><?= e(ucfirst($taskPriority)) ?></option><?php endforeach; ?></select></div>
                        <div class="t8-field t8-form-span-2"><label class="t8-label" for="task_description">Description</label><textarea class="t8-input" id="task_description" name="description" rows="3" maxlength="5000"><?= e($taskInput['description']) ?></textarea></div>
                        <div class="t8-form-actions"><button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-plus"></i> Add Task</button></div>
                    </form>
                </section>
            <?php else: ?>
                <div class="t8-alert t8-alert-info">Archived cases are read-only.</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

<?php elseif ($showParties): ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal', ['action' => 'view', 'id' => $caseId])) ?>"><i class="fa-solid fa-arrow-left"></i> Case Workspace</a>
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal')) ?>">Back to Cases</a>
    </div>
    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title">Parties &mdash; <?= e((string) $case['title']) ?></h2>
        </div>

        <?php if (!$legalHasPartySchema): ?>
            <div class="t8-alert t8-alert-danger">Apply the legal case parties migration before managing parties.</div>
        <?php elseif ($isAdmin && $case['status'] !== 'archived'): ?>
            <section style="padding: 0 var(--t8-space-4) var(--t8-space-4);">
                <h3>Link Existing Party</h3>
                <?php if ($availableParties === []): ?>
                    <p class="t8-help-text">All existing party records are already linked, or none have been created.</p>
                <?php else: ?>
                    <form method="post" action="<?= e(page_url('legal', ['action' => 'parties', 'id' => $caseId])) ?>" class="t8-legal-form-grid">
                        <?= t8_csrf_field() ?>
                        <input type="hidden" name="party_mode" value="existing">
                        <div class="t8-field"><label class="t8-label" for="existing_party_id">Party</label>
                            <select class="t8-select" id="existing_party_id" name="party_id" required><option value="">Select a party</option>
                                <?php foreach ($availableParties as $party): ?>
                                    <option value="<?= e((string) $party['id']) ?>"><?= e($party['name']) ?> (<?= e($party['type']) ?><?= $party['organization'] ? ', ' . e($party['organization']) : '' ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="t8-field"><label class="t8-label" for="existing_party_type">Party Type</label>
                            <select class="t8-select" id="existing_party_type" name="party_type_id" required><option value="">Select type</option>
                                <?php foreach ($partyTypes as $partyType): ?><option value="<?= e((string) $partyType['id']) ?>"><?= e($partyType['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="t8-field"><label class="t8-label" for="existing_party_role">Role in Case</label><input class="t8-input" id="existing_party_role" name="role_in_case" maxlength="100" required></div>
                        <div class="t8-form-actions"><button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-link"></i> Link Party</button></div>
                    </form>
                <?php endif; ?>

                <h3>Add New Party</h3>
                <form method="post" action="<?= e(page_url('legal', ['action' => 'parties', 'id' => $caseId])) ?>" class="t8-legal-form-grid">
                    <?= t8_csrf_field() ?>
                    <input type="hidden" name="party_mode" value="new">
                    <div class="t8-field"><label class="t8-label" for="new_party_name">Name</label><input class="t8-input" id="new_party_name" name="name" maxlength="200" value="<?= e($partyInput['name']) ?>" required></div>
                    <div class="t8-field"><label class="t8-label" for="new_party_type">Party Type</label>
                        <select class="t8-select" id="new_party_type" name="party_type_id" required><option value="">Select type</option>
                            <?php foreach ($partyTypes as $partyType): ?><option value="<?= e((string) $partyType['id']) ?>" <?= $partyInput['party_type_id'] === (string) $partyType['id'] ? 'selected' : '' ?>><?= e($partyType['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="t8-field"><label class="t8-label" for="entity_type">Record Type</label>
                        <select class="t8-select" id="entity_type" name="entity_type"><option value="individual" <?= $partyInput['entity_type'] === 'individual' ? 'selected' : '' ?>>Individual</option><option value="organization" <?= $partyInput['entity_type'] === 'organization' ? 'selected' : '' ?>>Organization</option></select>
                    </div>
                    <div class="t8-field"><label class="t8-label" for="party_organization">Organization</label><input class="t8-input" id="party_organization" name="organization" maxlength="200" value="<?= e($partyInput['organization']) ?>"></div>
                    <div class="t8-field"><label class="t8-label" for="party_contact_email">Contact Email</label><input class="t8-input" type="email" id="party_contact_email" name="contact_email" maxlength="150" value="<?= e($partyInput['contact_email']) ?>"></div>
                    <div class="t8-field"><label class="t8-label" for="party_contact_phone">Contact Phone</label><input class="t8-input" type="tel" id="party_contact_phone" name="contact_phone" maxlength="50" value="<?= e($partyInput['contact_phone']) ?>"></div>
                    <div class="t8-field"><label class="t8-label" for="new_party_role">Role in Case</label><input class="t8-input" id="new_party_role" name="role_in_case" maxlength="100" value="<?= e($partyInput['role_in_case']) ?>" required></div>
                    <div class="t8-form-actions"><button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-plus"></i> Add Party</button></div>
                </form>
            </section>
        <?php elseif ($case['status'] === 'archived'): ?>
            <div class="t8-alert t8-alert-info">Archived cases are read-only.</div>
        <?php endif; ?>

        <?php if ($attachedParties === []): ?>
            <div class="t8-empty">No parties are linked to this case yet.</div>
        <?php else: ?>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead><tr><th>Name</th><th>Party Type</th><th>Organization</th><th>Role</th><th>Contact</th><?php if ($isAdmin && $case['status'] !== 'archived'): ?><th>Actions</th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php foreach ($attachedParties as $party): ?>
                            <tr>
                                <td><?= e($party['party_name']) ?></td>
                                <td><?= e($party['legal_party_type']) ?></td>
                                <td><?= e((string) ($party['organization'] ?: '—')) ?></td>
                                <td><?= e($party['role_in_case']) ?></td>
                                <td><?= e((string) ($party['contact_email'] ?: ($party['contact_phone'] ?: '—'))) ?></td>
                                <?php if ($isAdmin && $case['status'] !== 'archived'): ?><td>
                                    <form method="post" action="<?= e(page_url('legal', ['action' => 'remove_party'])) ?>" onsubmit="return confirm('Remove this party from the case?');">
                                        <?= t8_csrf_field() ?>
                                        <input type="hidden" name="case_id" value="<?= e((string) $caseId) ?>">
                                        <input type="hidden" name="link_id" value="<?= e((string) $party['id']) ?>">
                                        <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit"><i class="fa-solid fa-xmark"></i> Remove</button>
                                    </form>
                                </td><?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

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
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal', ['action' => 'view', 'id' => $caseId])) ?>"><i class="fa-solid fa-arrow-left"></i> Case Workspace</a>
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal')) ?>">Back to Cases</a>
        <?php if ($legalHasDocumentType && $case['status'] !== 'archived'): ?><a class="t8-btn t8-btn-accent" href="<?= e(page_url('documents', ['action' => 'create', 'legal_case_id' => $caseId])) ?>"><i class="fa-solid fa-upload"></i> Upload Legal Document</a><?php endif; ?>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= e($case['title']) ?> — Attached Documents</h2>
        </div>

        <?php if (!$legalHasDocumentType): ?>
            <div class="t8-alert t8-alert-danger">Apply the legal case documents migration before managing attachments.</div>
        <?php elseif ($case['status'] === 'archived'): ?>
            <div class="t8-alert t8-alert-info">Archived cases are read-only.</div>
        <?php elseif ($isAdmin && $availableDocs === []): ?>
            <div class="t8-empty">No unlinked documents are available. Upload a legal document above or create one in Document Management.</div>
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
                    <label class="t8-label" for="legal_document_type">Legal Document Type</label>
                    <select class="t8-select" id="legal_document_type" name="legal_document_type" required>
                        <option value="">Select a type</option>
                        <?php foreach ($legalDocumentTypes as $documentType): ?><option value="<?= e($documentType) ?>"><?= e($documentType) ?></option><?php endforeach; ?>
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
                            <th>Legal Type</th>
                            <th>Note</th>
                            <th>Attached On</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attachedDocs as $ad): ?>
                            <tr>
                                <td><?= e($ad['document_title']) ?></td>
                                <td><?= e($ad['legal_document_type']) ?></td>
                                <td><?= e((string) ($ad['description'] ?? '—')) ?></td>
                                <td><?= e(format_date($ad['created_at'], 'M d, Y g:i A')) ?></td>
                                <td style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <?php if ($isAdmin): ?><a class="t8-btn t8-btn-outline t8-btn-sm"
                                       href="<?= e(page_url('documents', ['action' => 'versions', 'id' => $ad['document_id']])) ?>">
                                        <i class="fa-solid fa-eye"></i> View
                                    </a><?php endif; ?>
                                    <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('documents', ['action' => 'download', 'version_id' => $ad['version_id']])) ?>"><i class="fa-solid fa-download"></i> Download</a>
                                    <?php if ($isAdmin && $case['status'] !== 'archived'): ?><form method="post" action="<?= e(page_url('legal', ['action' => 'detach_document'])) ?>"
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

    <?php
    $legalAdvancedFiltersActive = $operationalFilter !== '' || $priorityFilter !== ''
        || $departmentFilter !== false || $assignedFilter !== false
        || $filedFrom !== '' || $filedTo !== '' || $deadlineFrom !== '' || $deadlineTo !== '';
    ?>
    <div class="t8-card t8-legal-list-card">
        <form method="get" action="<?= e(page_url('legal')) ?>" class="t8-legal-filters">
            <input type="hidden" name="page" value="legal">
            <input type="hidden" name="archived" value="<?= $archivedFilter ? '1' : '0' ?>">
            <div class="t8-legal-quick-filters">
                <div class="t8-legal-search">
                    <label class="t8-legal-visually-hidden" for="legal-search">Search cases, subjects, or departments</label>
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <input class="t8-input" type="search" id="legal-search" name="search" value="<?= e($searchFilter) ?>" placeholder="Search cases, subjects, or departments...">
                </div>
                <label class="t8-legal-visually-hidden" for="legal-status">Filter by status</label>
                <select class="t8-select" id="legal-status" name="status">
                    <option value="">All Statuses</option>
                    <?php foreach ($legalStatuses as $status): ?><option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $status))) ?></option><?php endforeach; ?>
                </select>
                <label class="t8-legal-visually-hidden" for="legal-case-type">Filter by case type</label>
                <select class="t8-select" id="legal-case-type" name="case_type">
                    <option value="">All Case Types</option>
                    <?php foreach ($legalCaseTypes as $caseType): ?><option value="<?= e((string) $caseType['id']) ?>" <?= $caseTypeFilter !== false && (int) $caseType['id'] === $caseTypeFilter ? 'selected' : '' ?>><?= e($caseType['name']) ?></option><?php endforeach; ?>
                </select>
                <button class="t8-btn t8-btn-outline t8-legal-filter-toggle" type="button" id="legal-filter-toggle" aria-expanded="<?= $legalAdvancedFiltersActive ? 'true' : 'false' ?>" aria-controls="legal-advanced-filters">
                    <i class="fa-solid fa-sliders" aria-hidden="true"></i> Filters
                </button>
                <?php if (count($legalListFilters) > ($archivedFilter ? 1 : 0)): ?>
                    <a class="t8-btn t8-btn-ghost t8-legal-clear" href="<?= e(page_url('legal', ['archived' => $archivedFilter ? '1' : '0'])) ?>">Clear</a>
                <?php endif; ?>
            </div>
            <div class="t8-legal-advanced-filters" id="legal-advanced-filters" <?= $legalAdvancedFiltersActive ? '' : 'hidden' ?>>
                <?php if ($legalHasPriority): ?>
                    <div class="t8-field"><label class="t8-label" for="legal-priority">Priority</label><select class="t8-select" id="legal-priority" name="priority"><option value="">All Priorities</option><?php foreach ($legalPriorities as $priority): ?><option value="<?= e($priority) ?>" <?= $priorityFilter === $priority ? 'selected' : '' ?>><?= e(ucfirst($priority)) ?></option><?php endforeach; ?></select></div>
                <?php endif; ?>
                <div class="t8-field"><label class="t8-label" for="legal-operational">Operational View</label><select class="t8-select" id="legal-operational" name="operational"><option value="">All Case Work</option><option value="overdue" <?= $operationalFilter === 'overdue' ? 'selected' : '' ?>>Overdue tasks</option><option value="upcoming_deadline" <?= $operationalFilter === 'upcoming_deadline' ? 'selected' : '' ?>>Upcoming deadlines</option><option value="upcoming_hearing" <?= $operationalFilter === 'upcoming_hearing' ? 'selected' : '' ?>>Upcoming hearings</option></select></div>
                <?php if ($legalHasCaseMetadata): ?>
                    <div class="t8-field"><label class="t8-label" for="legal-department">Department</label><select class="t8-select" id="legal-department" name="department"><option value="">All Departments</option><?php foreach ($departments as $department): ?><option value="<?= e((string) $department['id']) ?>" <?= $departmentFilter !== false && (int) $department['id'] === $departmentFilter ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select></div>
                <?php endif; ?>
                <div class="t8-field"><label class="t8-label" for="legal-assignee">Assigned Officer</label><select class="t8-select" id="legal-assignee" name="assigned_to"><option value="">All Assigned Officers</option><?php foreach ($legalOfficers as $assignee): ?><option value="<?= e((string) $assignee['id']) ?>" <?= $assignedFilter !== false && (int) $assignee['id'] === $assignedFilter ? 'selected' : '' ?>><?= e($assignee['full_name']) ?></option><?php endforeach; ?></select></div>
                <div class="t8-field"><label class="t8-label" for="legal-filed-from">Filed From</label><input class="t8-input" type="date" id="legal-filed-from" name="filed_from" value="<?= e($filedFrom) ?>"></div>
                <div class="t8-field"><label class="t8-label" for="legal-filed-to">Filed To</label><input class="t8-input" type="date" id="legal-filed-to" name="filed_to" value="<?= e($filedTo) ?>"></div>
                <?php if ($legalHasCaseMetadata): ?>
                    <div class="t8-field"><label class="t8-label" for="legal-deadline-from">Deadline From</label><input class="t8-input" type="date" id="legal-deadline-from" name="deadline_from" value="<?= e($deadlineFrom) ?>"></div>
                    <div class="t8-field"><label class="t8-label" for="legal-deadline-to">Deadline To</label><input class="t8-input" type="date" id="legal-deadline-to" name="deadline_to" value="<?= e($deadlineTo) ?>"></div>
                <?php endif; ?>
                <div class="t8-legal-filter-actions"><button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-filter" aria-hidden="true"></i> Apply Filters</button></div>
            </div>
        </form>
        <script>
            (() => {
                const filterForm = document.querySelector('.t8-legal-filters');
                const filterToggle = document.getElementById('legal-filter-toggle');
                const advancedFilters = document.getElementById('legal-advanced-filters');

                filterToggle?.addEventListener('click', () => {
                    const isExpanded = filterToggle.getAttribute('aria-expanded') === 'true';
                    filterToggle.setAttribute('aria-expanded', String(!isExpanded));
                    advancedFilters.hidden = isExpanded;
                });

                ['legal-status', 'legal-case-type'].forEach((filterId) => {
                    document.getElementById(filterId)?.addEventListener('change', () => filterForm.requestSubmit());
                });
            })();
        </script>
        <?php if ($cases === []): ?>
            <div class="t8-empty"><?= count($legalListFilters) > ($archivedFilter ? 1 : 0) ? 'No legal cases match the selected filters.' : ($archivedFilter ? 'No archived cases.' : 'No legal cases yet.') ?></div>
        <?php else: ?>
            <div class="t8-table-wrap">
                <table class="t8-table t8-legal-cases-table">
                    <thead>
                        <tr>
                            <th>Case No.</th>
                            <th>Title</th>
                            <th>Case Type</th>
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
                                <td class="t8-table-ref"><?= e((string) ($c['case_number'] ?? ('CASE-' . str_pad((string) $c['id'], 6, '0', STR_PAD_LEFT)))) ?></td>
                                <td><a class="t8-legal-case-title" href="<?= e(page_url('legal', ['action' => 'view', 'id' => (int) $c['id']])) ?>"><?= e($c['title']) ?></a></td>
                                <td><?= e($c['case_type_name']) ?><?= !(bool) $c['case_type_active'] ? ' (Inactive)' : '' ?></td>
                                <td>
                                    <span class="t8-badge <?= t8_legal_status_badge($c['status']) ?>">
                                        <?= e(ucwords(str_replace('_', ' ', $c['status']))) ?>
                                    </span>
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
