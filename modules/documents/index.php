<?php
/**
 * modules/documents/index.php
 * Document Management — traditional file uploads/versioning/archiving
 * (unchanged from the original module) PLUS the HR Document
 * Automation extension: a dashboard landing page and generated
 * documents (Incident Report, Notice To Explain,
 * Explanation Letter, Memorandum/Warning Letter, Certificate).
 *
 * PHASE 4 (Document/Legal/Contract/Retention rebuild):
 *   Every newly uploaded document is registered under retention
 *   automatically (see t8_document_register_retention() below), using
 *   a basis suggested from its category - mirroring the same
 *   "register once, never silently re-overwrite a manual override"
 *   guard already used by Contracts (Phase 2) and Legal Cases
 *   (Phase 3). This ONLY applies to uploads through THIS module's
 *   'create' action (team8_documents) - the separate HR Document
 *   Automation tables (team8_incident_reports, team8_memorandums,
 *   etc.) are a different entity model entirely and are out of scope
 *   for this rebuild's "Documents" retention entity type.
 *
 * STATUS/APPROVAL FIX (2026-09-17):
 *   Every non-admin upload used to be stamped 'pending' unconditionally,
 *   regardless of what kind of document it was - but only some
 *   categories were ever meant to be reviewed, so anything else's
 *   'pending' row had no reviewer ever routed to it and sat stuck
 *   forever. T8_DOC_CATEGORIES_REQUIRING_APPROVAL / 
 *   t8_document_requires_approval() below now decide this from the
 *   document's category: categories that carry compliance/legal/
 *   financial weight keep the existing Pending -> Approved/Returned
 *   admin review workflow (see 'set_status' below and
 *   t8_document_render_menu()'s Approve/Return buttons); everything
 *   else is approved immediately on create/upload since there is no
 *   review step for it to wait in.
 *
 * Routing:
 *   ?action=dashboard (default)         -> modules/documents/hr/dashboard.php
 *   ?action=browse                      -> the original upload list/table (below)
 *   ?action=create|upload_version|
 *           archive|restore|download|
 *           summarize|versions          -> original upload logic (unchanged)
 *   ?action=*_new, *_view,
 *           *_status, *_review,
 *           *_edit, hr_print            -> modules/documents/hr/router.php
 *
 * No new sidebar entry was added — every HR feature lives inside this
 * one Document Management page, per the task spec.
 */

declare(strict_types=1);

// AI Document Summarizer support - defensive require, safe even if
// already loaded centrally (require_once is idempotent). Adjust path
// if your app/ folder isn't two levels up from modules/documents/.
$aiHelperPath = __DIR__ . '/../../app/includes/ai_helper.php';
if (is_file($aiHelperPath)) {
    require_once $aiHelperPath;
}

// HR Document Automation helpers (identity resolution, doc numbers,
// status badges, notifications, versioning, fetch helpers). See
// app/includes/hr_documents.php for the full security contract.
$hrHelperPath = __DIR__ . '/../../app/includes/hr_documents.php';
if (is_file($hrHelperPath)) {
    require_once $hrHelperPath;
}

// Retention registration hook (Phase 1/4 of the Document/Legal/
// Contract/Retention rebuild) - defensive require, same pattern as
// the two requires above.
$retentionHelperPath = __DIR__ . '/../../app/includes/retention_helpers.php';
if (is_file($retentionHelperPath)) {
    require_once $retentionHelperPath;
}
$paginationHelperPath = __DIR__ . '/../../app/includes/document_browse_pagination.php';
if (is_file($paginationHelperPath)) {
    require_once $paginationHelperPath;
}
require_once __DIR__ . '/../../app/includes/document_metadata.php';

$pageTitle = 'Document Management';
$currentUserId = t8_current_user_id();
$isAdmin = t8_has_role('admin');
$action = $_GET['action'] ?? 'dashboard';
$errors = [];

const T8_DOC_ALLOWED_EXT = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'png', 'jpg', 'jpeg'];

/**
 * Categories whose documents must go through the Pending -> Approved /
 * Returned admin review step (see t8_document_render_menu()'s Approve/
 * Return buttons and the 'set_status' action below).
 *
 * Anything NOT in this list has no review step to be routed into, so
 * it is approved immediately on create/upload instead of being
 * stamped 'pending' with nowhere to go - that mismatch (every upload
 * marked pending regardless of category, but only some categories
 * ever having a reviewer look at them) is what previously left
 * documents stuck in Pending indefinitely.
 */
const T8_DOC_CATEGORIES_REQUIRING_APPROVAL = [
    'Compliance',
    'Legal',
    'Contracts',
    'Finance',
    'Human Resources',
    'HR',
];

/** True if $categoryName's documents must go through admin review. */
function t8_document_requires_approval(?string $categoryName): bool
{
    return $categoryName !== null
        && in_array($categoryName, T8_DOC_CATEGORIES_REQUIRING_APPROVAL, true);
}

function t8_document_has_column(PDO $pdo, string $column): bool
{
    try {
        // MariaDB does not support PDO placeholders in SHOW COLUMNS LIKE.
        // information_schema keeps the check parameterized and portable.
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1'
        );
        $stmt->execute(['table_name' => 'team8_documents', 'column_name' => $column]);
        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

$documentHasMetadata = t8_document_has_column($pdo, 'department_id') && t8_document_has_column($pdo, 'owner_id');
$documentHasStatus = t8_document_has_column($pdo, 'status');
$documentHasReviewReason = t8_document_has_column($pdo, 'review_reason');
$documentHasExpiration = t8_document_has_column($pdo, 'expiration_date');

function t8_documents_dir(): string
{
    $dir = UPLOAD_DIR . '/documents';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

/** Turn a title into a filesystem-safe slug for readable stored filenames. */
function t8_slugify(string $text): string
{
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? 'document';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'document';
}

/** Fetch a document row (with category/uploader names), or null. */
function t8_document_fetch(PDO $pdo, int $id): ?array
{
    $hasMetadata = t8_document_has_column($pdo, 'department_id') && t8_document_has_column($pdo, 'owner_id');
    $stmt = $pdo->prepare(
        'SELECT d.*, c.name AS category_name, u.full_name AS uploaded_by_name' . ($hasMetadata ? ', dep.name AS department_name, owner.full_name AS owner_name' : '') . '
         FROM team8_documents d
         LEFT JOIN team8_document_categories c ON c.id = d.category_id
         JOIN users u ON u.id = d.uploaded_by
         ' . ($hasMetadata ? 'LEFT JOIN departments dep ON dep.id = d.department_id LEFT JOIN users owner ON owner.id = d.owner_id' : '') . '
         WHERE d.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Fetch multiple uploaded documents for the current browse page in one query. */
function t8_document_fetch_many(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }

    $hasMetadata = t8_document_has_column($pdo, 'department_id') && t8_document_has_column($pdo, 'owner_id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        'SELECT d.*, c.name AS category_name, u.full_name AS uploaded_by_name' . ($hasMetadata ? ', dep.name AS department_name, owner.full_name AS owner_name' : '') . '
         FROM team8_documents d
         LEFT JOIN team8_document_categories c ON c.id = d.category_id
         JOIN users u ON u.id = d.uploaded_by
         ' . ($hasMetadata ? 'LEFT JOIN departments dep ON dep.id = d.department_id LEFT JOIN users owner ON owner.id = d.owner_id' : '') . '
         WHERE d.id IN (' . $placeholders . ')'
    );
    $stmt->execute($ids);

    $documents = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $document) {
        $documents[(int) $document['id']] = $document;
    }
    return $documents;
}

/** Fetch retention IDs for uploaded documents on the current browse page. */
function t8_document_retention_map(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    if ($ids === [] || !function_exists('t8_retention_fetch_for_entity')) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, entity_id FROM team8_records WHERE entity_type = 'document' AND entity_id IN ($placeholders)"
    );
    $stmt->execute($ids);

    $retention = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $retention[(int) $row['entity_id']] = ['id' => (int) $row['id']];
    }
    return $retention;
}

function t8_document_status_badge(string $status): string
{
    return match ($status) {
        'approved' => 't8-badge-approved',
        'returned_for_revision' => 't8-badge-rejected',
        default => 't8-badge-pending',
    };
}

/**
 * PHASE 4: registers a freshly uploaded document under retention,
 * EXACTLY ONCE - same guard pattern as
 * t8_contract_register_retention() (Phase 2) and
 * t8_legal_register_retention() (Phase 3): checks
 * t8_retention_fetch_for_entity() first, so calling this again for a
 * document that already has a retention row (e.g. if a future code
 * path re-invoked it) never clobbers a records officer's override.
 *
 * Basis is suggested from the document's CATEGORY, matching the
 * confirmed retention matrix:
 *   Finance / Compliance -> BIR RR No. 7-2024 (EOPT Act), 5 years
 *   Human Resources      -> Labor Code / DOLE, 3 years
 *   everything else      -> Internal policy, 5 years
 * Clock starts at the document's own created_at (upload date).
 */
function t8_document_register_retention(PDO $pdo, int $documentId, ?string $categoryName, int $actorId): void
{
    if (!function_exists('t8_retention_register') || !function_exists('t8_retention_fetch_for_entity')) {
        return; // retention_helpers.php not present yet (Phase 1 not deployed)
    }
    if (t8_retention_fetch_for_entity($pdo, 'document', $documentId) !== null) {
        return; // already registered - never overwrite a manual override
    }

    [$basis, $years] = match ($categoryName) {
        'Finance', 'Compliance' => ['BIR RR No. 7-2024 (EOPT Act) - financial/tax-relevant document', 5],
        'Human Resources', 'HR' => ['Labor Code / DOLE - employment record', 3],
        default => ['Internal policy - general administrative document', 5],
    };

    t8_retention_register($pdo, 'document', $documentId, $basis, $years, date('Y-m-d'), $actorId);
    t8_audit_log($pdo, $actorId, 'document', $documentId, 'retention_registered');
}

function t8_document_render_menu(
    array $doc,
    bool $isAdmin,
    string $statusFilter,
    ?array $retentionRecord,
    ?int $latestVersionId = null,
    bool $showVersionsLink = true
): void
{
    $id = (int) $doc['id'];
    $title = (string) ($doc['title'] ?? '');
    $statusLabel = ucwords(str_replace('_', ' ', (string) ($doc['status'] ?? 'pending')));
    $expiration = $doc['expiration_date'] ? format_date((string) $doc['expiration_date'], 'M d, Y') : '—';
    $updatedAt = format_date((string) ($doc['updated_at'] ?? ''), 'M d, Y g:i A');
    $uploadedBy = (string) ($doc['uploaded_by_name'] ?? '—');
    $department = (string) ($doc['department_name'] ?? '—');
    $owner = (string) ($doc['owner_name'] ?? '—');
    $category = (string) ($doc['category_name'] ?? '—');
    ?>
    <div class="t8-row-menu">
        <button type="button" class="t8-row-menu-trigger" aria-haspopup="true" aria-expanded="false" title="More actions"
                data-detail-modal="t8DocumentDetailModal"
                data-title="<?= e($title) ?>"
                data-document-type="<?= e((string) ($doc['document_type'] ?? '—')) ?>"
                data-version="v<?= e((string) ($doc['current_version'] ?? 1)) ?>"
                data-status="<?= e($statusLabel) ?>"
                data-expiration="<?= e($expiration) ?>"
                data-department="<?= e($department) ?>"
                data-owner="<?= e($owner) ?>"
                data-category="<?= e($category) ?>"
                data-last-updated="<?= e($updatedAt) ?>"
                data-uploaded-by="<?= e($uploadedBy) ?>">
            <i class="fa-solid fa-ellipsis-vertical"></i>
        </button>
        <div class="t8-row-menu-panel" role="menu">
            <button type="button" class="t8-row-menu-item t8-row-view-details" role="menuitem">
                <i class="fa-solid fa-eye"></i> View Details
            </button>
            <?php if ($showVersionsLink): ?>
                <a class="t8-row-menu-item" role="menuitem" href="<?= e(page_url('documents', ['action' => 'versions', 'id' => $id])) ?>">
                    <i class="fa-solid fa-file-lines"></i> View / Versions
                </a>
            <?php endif; ?>
            <?php if (t8_document_can_edit_metadata($doc, (int) (t8_current_user_id() ?? 0), $isAdmin)): ?>
                <a class="t8-row-menu-item" role="menuitem" href="<?= e(page_url('documents', ['action' => 'edit_metadata', 'id' => $id])) ?>">
                    <i class="fa-solid fa-pen-to-square"></i> Edit Metadata
                </a>
            <?php endif; ?>
            <?php if ($latestVersionId !== null): ?>
                <a class="t8-row-menu-item" role="menuitem" href="<?= e(page_url('documents', ['action' => 'download', 'version_id' => $latestVersionId])) ?>">
                    <i class="fa-solid fa-download"></i> Download Latest Version
                </a>
                <form method="post" action="<?= e(page_url('documents', ['action' => 'summarize'])) ?>">
                    <?= t8_csrf_field() ?>
                    <input type="hidden" name="version_id" value="<?= e((string) $latestVersionId) ?>">
                    <button class="t8-row-menu-item" type="submit" role="menuitem">
                        <i class="fa-solid fa-robot"></i> AI Summarize Latest
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($retentionRecord !== null): ?>
                <a class="t8-row-menu-item" role="menuitem" href="<?= e(page_url('retention', ['action' => 'view', 'id' => $retentionRecord['id']])) ?>">
                    <i class="fa-solid fa-box-archive"></i> View Retention Record
                </a>
            <?php endif; ?>
            <?php if (t8_document_can_approve($doc, (int) (t8_current_user_id() ?? 0), $isAdmin) && $statusFilter === 'active' && $doc['status'] === 'pending'): ?>
                <div class="t8-row-menu-divider"></div>
                <form method="post" action="<?= e(page_url('documents', ['action' => 'set_status'])) ?>">
                    <?= t8_csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <input type="hidden" name="status" value="approved">
                    <button class="t8-row-menu-item t8-success" type="submit" role="menuitem">
                        <i class="fa-solid fa-check"></i> Approve
                    </button>
                </form>
                <form method="post" action="<?= e(page_url('documents', ['action' => 'set_status'])) ?>">
                    <?= t8_csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <input type="hidden" name="status" value="returned_for_revision">
                    <textarea class="t8-textarea" name="review_reason" rows="2" placeholder="Reason for return" required></textarea>
                    <button class="t8-row-menu-item t8-danger" type="submit" role="menuitem">
                        <i class="fa-solid fa-rotate-left"></i> Reject / Return
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($isAdmin && $statusFilter === 'active'): ?>
                <div class="t8-row-menu-divider"></div>
                <form method="post" action="<?= e(page_url('documents', ['action' => 'archive'])) ?>" onsubmit="return confirm('Archive this document?');">
                    <?= t8_csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <button class="t8-row-menu-item t8-danger" type="submit" role="menuitem">
                        <i class="fa-solid fa-box-archive"></i> Archive
                    </button>
                </form>
            <?php elseif ($isAdmin): ?>
                <div class="t8-row-menu-divider"></div>
                <form method="post" action="<?= e(page_url('documents', ['action' => 'restore'])) ?>">
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

/** Render the shared meatball menu for generated HR records in the feed. */
function t8_document_render_feed_menu(array $item): void
{
    $title = (string) ($item['label'] ?? 'Document');
    $documentType = t8_hr_doc_type_label((string) ($item['doc_type'] ?? ''));
    $status = ucwords(str_replace('_', ' ', (string) ($item['status'] ?? 'pending')));
    $date = format_date((string) ($item['ts'] ?? ''), 'M d, Y');
    ?>
    <div class="t8-row-menu">
        <button type="button" class="t8-row-menu-trigger" aria-haspopup="true" aria-expanded="false" title="More actions"
                data-detail-modal="t8DocumentDetailModal"
                data-title="<?= e($title) ?>"
                data-document-type="<?= e($documentType) ?>"
                data-status="<?= e($status) ?>"
                data-last-updated="<?= e($date) ?>">
            <i class="fa-solid fa-ellipsis-vertical"></i>
        </button>
        <div class="t8-row-menu-panel" role="menu">
            <button type="button" class="t8-row-menu-item t8-row-view-details" role="menuitem">
                <i class="fa-solid fa-eye"></i> View Details
            </button>
            <a class="t8-row-menu-item" role="menuitem" href="<?= e(page_url('documents', ['action' => $item['url_action'], 'id' => (int) $item['id']])) ?>">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Open Document
            </a>
        </div>
    </div>
    <?php
}

function t8_render_document_detail_modal(): void
{
    ?>
    <dialog id="t8DocumentDetailModal" class="t8-detail-modal">
        <div class="t8-detail-header">
            <div>
                <h2 data-detail-field="title">Document</h2>
            </div>
            <button type="button" class="t8-detail-close" data-close-detail-modal aria-label="Close">&times;</button>
        </div>
        <div class="t8-detail-body">
            <div class="t8-detail-grid">
                <div class="t8-detail-item"><span>Document Type</span><strong data-detail-field="document-type">—</strong></div>
                <div class="t8-detail-item"><span>Version</span><strong data-detail-field="version">—</strong></div>
                <div class="t8-detail-item"><span>Status</span><strong data-detail-field="status">—</strong></div>
                <div class="t8-detail-item"><span>Expiration</span><strong data-detail-field="expiration">—</strong></div>
                <div class="t8-detail-item"><span>Department</span><strong data-detail-field="department">—</strong></div>
                <div class="t8-detail-item"><span>Owner</span><strong data-detail-field="owner">—</strong></div>
                <div class="t8-detail-item"><span>Category</span><strong data-detail-field="category">—</strong></div>
                <div class="t8-detail-item"><span>Last Updated</span><strong data-detail-field="last-updated">—</strong></div>
                <div class="t8-detail-item full"><span>Uploaded By</span><strong data-detail-field="uploaded-by">—</strong></div>
            </div>
        </div>
        <div class="t8-detail-footer">
            <button type="button" class="t8-btn t8-btn-outline" data-close-detail-modal>Close</button>
        </div>
    </dialog>
    <?php
}

/**
 * Canonical access rule for uploaded files (team8_documents):
 * admins may access all rows; other users may access only documents they
 * uploaded. Fail closed when the row or actor is missing — a supplied
 * document ID is never enough on its own.
 *
 * Legal-officer download of a file attached to an assigned case is a
 * documented extra path in t8_document_can_download(), not a general
 * VIEW grant.
 */
function t8_document_is_authorized(?array $document, int $userId, bool $isAdmin): bool
{
    if ($document === null || $userId <= 0) {
        return false;
    }

    $matrix = t8_document_access_matrix(
        $document,
        $userId,
        $isAdmin,
        $GLOBALS['pdo'] ?? null,
        ['department_id' => $_SESSION['department_id'] ?? null]
    );

    return $matrix['view'];
}

/** Document id from a document row or a joined version row. */
function t8_document_entity_id(?array $document): int
{
    if ($document === null) {
        return 0;
    }
    if (array_key_exists('document_id', $document) && $document['document_id'] !== null) {
        return (int) $document['document_id'];
    }

    return (int) ($document['id'] ?? 0);
}

function t8_document_can_view(?array $document, int $userId, bool $isAdmin): bool
{
    if ($document === null || $userId <= 0) {
        return false;
    }

    return t8_document_can_access(
        $document,
        $userId,
        $isAdmin,
        $GLOBALS['pdo'] ?? null,
        'view',
        ['department_id' => $_SESSION['department_id'] ?? null]
    );
}

/**
 * Assigned legal-case id when a legal_officer may download this upload.
 * Returns null when the assignment cannot be verified.
 */
function t8_document_assigned_legal_case_id(PDO $pdo, int $documentId, int $userId): ?int
{
    if ($documentId <= 0 || $userId <= 0 || !t8_has_role('legal_officer')) {
        return null;
    }

    $legalStmt = $pdo->prepare(
        'SELECT lc.id FROM team8_legal_documents ld
         JOIN team8_legal_cases lc ON lc.id = ld.case_id
         WHERE ld.document_id = :document_id AND lc.assigned_to = :user_id AND lc.deleted_at IS NULL LIMIT 1'
    );
    $legalStmt->execute(['document_id' => $documentId, 'user_id' => $userId]);
    $legalCaseId = $legalStmt->fetchColumn();

    return $legalCaseId !== false ? (int) $legalCaseId : null;
}

function t8_document_can_download(?array $document, int $userId, bool $isAdmin, ?PDO $pdo = null): bool
{
    if ($document === null || $userId <= 0) {
        return false;
    }

    if (t8_document_can_view($document, $userId, $isAdmin)) {
        return true;
    }
    if ($isAdmin || $pdo === null) {
        return false;
    }

    return t8_document_assigned_legal_case_id($pdo, t8_document_entity_id($document), $userId) !== null;
}

/** Replace/upload a new version: owner/admin, plus staff only after return. */
function t8_document_can_edit(?array $document, int $userId, bool $isAdmin): bool
{
    if ($document === null || $userId <= 0) {
        return false;
    }

    if (!t8_document_can_access($document, $userId, $isAdmin, $GLOBALS['pdo'] ?? null, 'edit', ['department_id' => $_SESSION['department_id'] ?? null])) {
        return false;
    }

    return $isAdmin || (string) ($document['status'] ?? '') === 'returned_for_revision';
}

/** Uploaded files have no separate print action; print follows VIEW. */
function t8_document_can_print(?array $document, int $userId, bool $isAdmin): bool
{
    if ($document === null || $userId <= 0) {
        return false;
    }

    return t8_document_can_access($document, $userId, $isAdmin, $GLOBALS['pdo'] ?? null, 'print', ['department_id' => $_SESSION['department_id'] ?? null]);
}

/** Approve an uploaded document: admin and a loaded document row. */
function t8_document_can_approve(?array $document, int $userId, bool $isAdmin): bool
{
    if ($document === null || $userId <= 0) {
        return false;
    }

    return t8_document_can_access($document, $userId, $isAdmin, $GLOBALS['pdo'] ?? null, 'approve', ['department_id' => $_SESSION['department_id'] ?? null]);
}

/** All versions for a document, newest first. */
function t8_document_all_versions(PDO $pdo, int $documentId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM team8_document_versions WHERE document_id = :document_id ORDER BY version_no DESC'
    );
    $stmt->execute(['document_id' => $documentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Returns archived HR-generated documents in the same display shape used by
 * the uploaded-document archive. Archived HR records are admin-only here,
 * matching the existing certificate and memorandum visibility rules.
 */
function t8_document_archived_hr_records(PDO $pdo, bool $isAdmin): array
{
    if (!$isAdmin) {
        return [];
    }

    $records = [];
    $sources = [
        [
            'table' => 'team8_incident_reports',
            'source' => 'Incident Report',
            'title_sql' => 'h.document_number',
            'type_sql' => 'h.incident_type',
            'subject_sql' => 'u.full_name',
            'date_sql' => 'h.created_at',
            'view_action' => 'incident_report_view',
            'print_type' => 'incident_report',
            'join' => 'JOIN users u ON u.id = h.employee_id',
        ],
        [
            'table' => 'team8_notice_to_explain',
            'source' => 'Notice To Explain',
            'title_sql' => 'h.document_number',
            'type_sql' => "'Notice To Explain'",
            'subject_sql' => 'u.full_name',
            'date_sql' => 'h.created_at',
            'view_action' => 'nte_view',
            'print_type' => 'nte',
            'join' => 'JOIN users u ON u.id = h.employee_id',
        ],
        [
            'table' => 'team8_explanations',
            'source' => 'Explanation Letter',
            'title_sql' => 'n.document_number',
            'type_sql' => "'Explanation Letter'",
            'subject_sql' => 'u.full_name',
            'date_sql' => 'h.submitted_at',
            'view_action' => 'explanation_view',
            'print_type' => null,
            'join' => 'JOIN users u ON u.id = h.employee_id JOIN team8_notice_to_explain n ON n.id = h.nte_id',
        ],
        [
            'table' => 'team8_memorandums',
            'source' => null,
            'title_sql' => 'h.document_number',
            'type_sql' => "CASE WHEN h.kind = 'warning_letter' THEN 'Warning Letter' ELSE 'Memorandum' END",
            'subject_sql' => 'p.full_name',
            'date_sql' => 'h.created_at',
            'view_action' => 'memorandum_view',
            'print_type' => 'memorandum',
            'join' => 'JOIN users p ON p.id = h.prepared_by',
        ],
        [
            'table' => 'team8_certificates',
            'source' => 'Certificate',
            'title_sql' => 'h.document_number',
            'type_sql' => "CASE h.certificate_type WHEN 'employment' THEN 'Certificate of Employment' WHEN 'recognition' THEN 'Certificate of Recognition' WHEN 'attendance' THEN 'Certificate of Attendance' ELSE 'Certificate' END",
            'subject_sql' => 'p.full_name',
            'date_sql' => 'h.created_at',
            'view_action' => 'certificate_view',
            'print_type' => 'certificate',
            'join' => 'JOIN users p ON p.id = h.prepared_by',
        ],
    ];

    foreach ($sources as $source) {
        $stmt = $pdo->query(
            'SELECT h.id, h.status, ' . $source['date_sql'] . ' AS archive_date, '
            . $source['title_sql'] . ' AS archive_title, '
            . $source['type_sql'] . ' AS archive_type, '
            . $source['subject_sql'] . ' AS archive_subject '
            . 'FROM ' . $source['table'] . ' h '
            . $source['join'] . " WHERE h.status = 'archived'"
        );

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sourceLabel = $source['source'] ?? ((string) $row['archive_type']);
            $viewUrl = page_url('documents', [
                'action' => $source['view_action'],
                'id' => (int) $row['id'],
            ]);
            $printUrl = $source['print_type'] !== null
                ? page_url('documents', [
                    'action' => 'hr_print',
                    'type' => $source['print_type'],
                    'id' => (int) $row['id'],
                ])
                : null;

            $records[] = [
                'archive_title' => (string) $row['archive_title'],
                'archive_type' => $sourceLabel,
                'archive_subject' => (string) ($row['archive_subject'] ?? '—'),
                'status' => (string) $row['status'],
                'archived_at' => (string) $row['archive_date'],
                'view_url' => $viewUrl,
                'print_url' => $printUrl,
            ];
        }
    }

    return $records;
}

/** Returns rejected HR-generated documents for the admin rejected view. */
function t8_document_rejected_hr_records(PDO $pdo, bool $isAdmin): array
{
    if (!$isAdmin) {
        return [];
    }

    $records = [];
    $sources = [
        ['table' => 'team8_incident_reports', 'type' => "'Incident Report'", 'title' => 'h.document_number', 'subject' => 'u.full_name', 'date' => 'h.created_at', 'action' => 'incident_report_view', 'join' => 'JOIN users u ON u.id = h.employee_id'],
        ['table' => 'team8_notice_to_explain', 'type' => "'Notice To Explain'", 'title' => 'h.document_number', 'subject' => 'u.full_name', 'date' => 'h.created_at', 'action' => 'nte_view', 'join' => 'JOIN users u ON u.id = h.employee_id'],
        ['table' => 'team8_explanations', 'type' => "'Explanation Letter'", 'title' => 'n.document_number', 'subject' => 'u.full_name', 'date' => 'h.submitted_at', 'action' => 'explanation_view', 'join' => 'JOIN users u ON u.id = h.employee_id JOIN team8_notice_to_explain n ON n.id = h.nte_id'],
        ['table' => 'team8_memorandums', 'type' => "CASE WHEN h.kind = 'warning_letter' THEN 'Warning Letter' ELSE 'Memorandum' END", 'title' => 'h.document_number', 'subject' => 'p.full_name', 'date' => 'h.created_at', 'action' => 'memorandum_view', 'join' => 'JOIN users p ON p.id = h.prepared_by'],
        ['table' => 'team8_certificates', 'type' => "CASE h.certificate_type WHEN 'employment' THEN 'Certificate of Employment' WHEN 'recognition' THEN 'Certificate of Recognition' WHEN 'attendance' THEN 'Certificate of Attendance' ELSE 'Certificate' END", 'title' => 'h.document_number', 'subject' => 'p.full_name', 'date' => 'h.created_at', 'action' => 'certificate_view', 'join' => 'JOIN users p ON p.id = h.prepared_by'],
    ];

    foreach ($sources as $source) {
        $stmt = $pdo->query(
            'SELECT h.id, h.status, ' . $source['date'] . ' AS document_date, '
            . $source['title'] . ' AS document_title, ' . $source['type'] . ' AS document_type, '
            . $source['subject'] . ' AS document_subject '
            . 'FROM ' . $source['table'] . ' h ' . $source['join']
            . " WHERE h.status = 'rejected' ORDER BY " . $source['date'] . ' DESC, h.id DESC'
        );

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $records[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['document_title'],
                'type' => (string) $row['document_type'],
                'subject' => (string) ($row['document_subject'] ?? '—'),
                'status' => 'rejected',
                'date' => (string) $row['document_date'],
                'url_action' => $source['action'],
                'view_url' => page_url('documents', ['action' => $source['action'], 'id' => (int) $row['id']]),
            ];
        }
    }

    usort($records, static fn (array $a, array $b): int => strtotime($b['date']) <=> strtotime($a['date']));
    return $records;
}

/** Validates $_FILES['file']; returns an error string, or '' if OK. */
function t8_document_validate_upload(array $file): string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return 'Please choose a file to upload.';
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'File upload failed. Please try again.';
    }
    $maxBytes = UPLOAD_MAX_SIZE_MB * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        return 'File is too large. Maximum size is ' . UPLOAD_MAX_SIZE_MB . 'MB.';
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, T8_DOC_ALLOWED_EXT, true)) {
        return 'File type not allowed. Allowed: ' . implode(', ', T8_DOC_ALLOWED_EXT) . '.';
    }
    
    if (!t8_validate_uploaded_file_mime($file['tmp_name'], (string) ($file['name'] ?? ''))) {
        return 'The file contents do not match the selected file type.';
    }
    return '';
}

/**
 * Moves the uploaded file onto disk under a readable, collision-proof
 * name, and returns the RELATIVE path (stored in file_path columns)
 * plus size/checksum. Relative to UPLOAD_DIR, e.g. "documents/xxx.pdf".
 */
function t8_document_store_upload(array $file, string $title, int $versionNo): array
{
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $storedName = t8_slugify($title) . '_v' . $versionNo . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $relativePath = 'documents/' . $storedName;
    $destination = t8_documents_dir() . '/' . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Could not save the uploaded file.');
    }
    return [
        'file_path' => $relativePath,
        'file_size' => (int) $file['size'],
        'checksum'  => hash_file('sha256', $destination) ?: null,
    ];
}

$categories = $pdo->query('SELECT id, name FROM team8_document_categories ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?? [];
$departments = $pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?? [];
$owners = $isAdmin ? ($pdo->query('SELECT id, full_name FROM users WHERE deleted_at IS NULL ORDER BY full_name')->fetchAll(PDO::FETCH_ASSOC) ?? []) : [];

// If no categories exist, provide a friendly message
if (empty($categories)) {
    $errors[] = 'Warning: No document categories found. Please run database/seed_account.sql to populate categories.';
}

$categoryTypeTemplates = [
    'Administrative'   => ['Meeting Minutes', 'Forms', 'General Correspondence'],
    'Contracts'        => ['Supplier Contract', 'Lease Agreement', 'Service Agreement'],
    'Finance'          => ['Invoice', 'Purchase Order', 'Financial Report'],
    'Inventory'        => ['Stock Record', 'Asset Register', 'Inventory Adjustment'],
    'Compliance'       => [
        'Business Permit',
        'BIR Certificate of Registration',
        'Mayor\'s Permit',
        'Sanitary Permit',
        'Fire Safety Inspection Certificate',
        'Barangay Clearance',
        'DTI/SEC Registration',
        'Occupational Permit',
    ],
    'Facilities'       => ['Maintenance Request', 'Equipment Inspection', 'Floor Plan'],
    'Human Resources'  => ['Employment Contract', 'Performance Review', 'Training Record'],
    'HR'               => ['Employment Contract', 'Performance Review', 'Training Record'],
    'Legal'            => ['Legal Opinion', 'Case File', 'Demand Letter', 'Policy'],
    'Others'           => ['General Document', 'Reference Material', 'Ad Hoc Record'],
];
$documentTypeOptions = [];
foreach ($categories as $category) {
    if (isset($categoryTypeTemplates[$category['name']])) {
        $documentTypeOptions[(string) $category['id']] = $categoryTypeTemplates[$category['name']];
    }
}

// ---------------------------------------------------------------
// HR Document Automation actions - dispatch and stop. Everything
// below this block is the ORIGINAL upload/version/archive module,
// unchanged.
// ---------------------------------------------------------------
$hrActions = [
    'incident_report_new', 'incident_report_view', 'incident_report_status',
    'nte_new', 'nte_view', 'nte_status',
    'explanation_new', 'explanation_view', 'explanation_review',
    'memorandum_new', 'memorandum_edit', 'memorandum_view', 'memorandum_status',
    'certificate_new', 'certificate_view', 'certificate_status',
    'hr_print',
];

if (in_array($action, $hrActions, true)) {
    require __DIR__ . '/hr/router.php';
    return;
}

if ($action === 'dashboard') {
    require __DIR__ . '/hr/dashboard.php';
    return;
}

switch ($action) {
    case 'create':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $categoryId = (string) ($_POST['category_id'] ?? '') !== '' ? (int) $_POST['category_id'] : null;
            $documentType = trim((string) ($_POST['document_type'] ?? ''));
            $departmentId = $isAdmin && (string) ($_POST['department_id'] ?? '') !== '' ? (int) $_POST['department_id'] : ($_SESSION['department_id'] ?? null);
            $ownerId = $isAdmin && (string) ($_POST['owner_id'] ?? '') !== '' ? (int) $_POST['owner_id'] : $currentUserId;
            $expirationDate = trim((string) ($_POST['expiration_date'] ?? ''));

            // Resolved once here - reused both for the status decision
            // below (STATUS/APPROVAL FIX) and for retention
            // registration after the insert, instead of being looked
            // up twice.
            $categoryName = null;
            foreach ($categories as $cat) {
                if ((int) $cat['id'] === $categoryId) {
                    $categoryName = (string) $cat['name'];
                    break;
                }
            }

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                if ($title === '') {
                    $errors[] = 'Document title is required.';
                }
                if ($categoryId === null) {
                    $errors[] = 'Please choose a document category.';
                }
                if ($documentType === '') {
                    $errors[] = 'Please choose a document type.';
                } elseif (!isset($documentTypeOptions[(string) $categoryId]) || !in_array($documentType, $documentTypeOptions[(string) $categoryId], true)) {
                    $errors[] = 'The selected document type does not match the chosen category.';
                }
                if (!t8_document_valid_metadata_date($expirationDate)) {
                    $errors[] = 'Expiration date must be a valid YYYY-MM-DD date.';
                }
                $uploadError = t8_document_validate_upload($_FILES['file'] ?? []);
                if ($uploadError !== '') {
                    $errors[] = $uploadError;
                }

                if (!$errors) {
                    $stored = t8_document_store_upload($_FILES['file'], $title, 1);

                    $insertColumns = ['category_id', 'document_type', 'uploaded_by', 'title', 'file_path', 'current_version'];
                    $insertValues = [':category_id', ':document_type', ':uploaded_by', ':title', ':file_path', '1'];
                    $insertParams = [
                        'category_id'   => $categoryId,
                        'document_type' => $documentType !== '' ? $documentType : null,
                        'uploaded_by'   => $currentUserId,
                        'title'         => $title,
                        'file_path'     => $stored['file_path'],
                    ];
                    if ($documentHasMetadata) {
                        $insertColumns = array_merge($insertColumns, ['department_id', 'owner_id']);
                        $insertValues = array_merge($insertValues, [':department_id', ':owner_id']);
                        $insertParams['department_id'] = $departmentId ?: null;
                        $insertParams['owner_id'] = $ownerId ?: null;
                    }
                    if ($documentHasStatus) {
                        $insertColumns[] = 'status';
                        $insertValues[] = ':status';
                        // STATUS/APPROVAL FIX: admins still publish
                        // immediately. A non-admin upload is only ever
                        // 'pending' when its category actually has a
                        // review step to send it to (see
                        // T8_DOC_CATEGORIES_REQUIRING_APPROVAL above) -
                        // otherwise it's approved right away instead of
                        // being stranded with no reviewer looking for it.
                        $requiresApproval = t8_document_requires_approval($categoryName);
                        $insertParams['status'] = ($isAdmin || !$requiresApproval) ? 'approved' : 'pending';
                    }
                    if ($documentHasExpiration) {
                        $insertColumns[] = 'expiration_date';
                        $insertValues[] = ':expiration_date';
                        $insertParams['expiration_date'] = $expirationDate !== '' ? $expirationDate : null;
                    }

                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare(
                            'INSERT INTO team8_documents (' . implode(', ', $insertColumns) . ')
                             VALUES (' . implode(', ', $insertValues) . ')'
                        );
                        $stmt->execute($insertParams);
                        $documentId = (int) $pdo->lastInsertId();

                        $pdo->prepare(
                            'INSERT INTO team8_document_versions (document_id, version_no, file_path, file_size, checksum)
                             VALUES (:document_id, 1, :file_path, :file_size, :checksum)'
                        )->execute([
                            'document_id' => $documentId,
                            'file_path'   => $stored['file_path'],
                            'file_size'   => $stored['file_size'],
                            'checksum'    => $stored['checksum'],
                        ]);

                        $pdo->commit();
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        @unlink(t8_documents_dir() . '/' . basename($stored['file_path']));
                        throw $e;
                    }

                    t8_audit_log($pdo, $currentUserId, 'document', $documentId, 'create');

                    // PHASE 4: register under retention immediately, using
                    // the category name (already resolved above) to pick
                    // a suggested basis/period.
                    t8_document_register_retention($pdo, $documentId, $categoryName, $currentUserId);

                    t8_flash_set('success', 'Document uploaded.');
                    redirect(page_url('documents'));
                }
            }
        }
        break;

    case 'edit_metadata':
        $documentId = t8_document_metadata_positive_id($_GET['id'] ?? null);
        $documentId = $documentId === false ? 0 : $documentId;
        $document = $documentId > 0 ? t8_document_fetch($pdo, $documentId) : null;
        if (!t8_document_can_edit_metadata($document, (int) ($currentUserId ?? 0), $isAdmin)) {
            t8_flash_set('danger', 'Document not found.');
            redirect(page_url('documents'));
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $csrfToken = $_POST['csrf_token'] ?? null;
            if (!t8_document_metadata_csrf_valid($csrfToken)) {
                $errors[] = 'Your session expired. Please try again.';
            } elseif (!t8_document_metadata_version_matches($_POST['metadata_version'] ?? null, $document)) {
                $errors[] = 'This document changed after you opened it. Review the latest values and submit again.';
                $_POST = [];
            } else {
                $metadataVersion = $_POST['metadata_version'];
                $categoryInput = $_POST['category_id'] ?? '';
                $categoryId = $categoryInput === ''
                    ? (int) ($document['category_id'] ?? 0)
                    : t8_document_metadata_positive_id($categoryInput);
                $documentTypeInput = $_POST['document_type'] ?? null;
                $documentType = is_string($documentTypeInput) ? trim($documentTypeInput) : '';
                $titleInput = $_POST['title'] ?? null;
                $title = is_string($titleInput) ? trim($titleInput) : '';
                $expirationDateInput = $_POST['expiration_date'] ?? '';
                $expirationDate = is_string($expirationDateInput) ? $expirationDateInput : null;
                $departmentInput = $_POST['department_id'] ?? '';
                $departmentId = $isAdmin
                    ? ($departmentInput === '' ? null : t8_document_metadata_positive_id($departmentInput))
                    : ($document['department_id'] ?? null);
                $ownerInput = $_POST['owner_id'] ?? '';
                $ownerId = $isAdmin
                    ? ($ownerInput === '' ? null : t8_document_metadata_positive_id($ownerInput))
                    : ($document['owner_id'] ?? null);

                if (!is_string($titleInput) || $title === '') {
                    $errors[] = 'Document title is required.';
                }
                if ($categoryId === false || $categoryId <= 0) {
                    $errors[] = 'Please choose a document category.';
                }
                if (!is_string($documentTypeInput) || $documentType === '') {
                    $errors[] = 'Please choose a document type.';
                } elseif (isset($documentTypeOptions[(string) $categoryId]) && !in_array($documentType, $documentTypeOptions[(string) $categoryId], true)) {
                    $errors[] = 'The selected document type does not match the chosen category.';
                }
                if (!is_string($expirationDateInput) || !t8_document_valid_metadata_date($expirationDateInput)) {
                    $errors[] = 'Expiration date must be a valid YYYY-MM-DD date.';
                }
                if ($isAdmin && $departmentId === false) {
                    $errors[] = 'Please choose a valid department.';
                }
                if ($isAdmin && $ownerId === false) {
                    $errors[] = 'Please choose a valid owner.';
                }
                if (!$errors) {
                    $update = [
                        'title' => $title,
                        'category_id' => $categoryId,
                        'document_type' => $documentType,
                        'expiration_date' => $expirationDate,
                    ];
                    if ($isAdmin) {
                        $update['department_id'] = $departmentId;
                        $update['owner_id'] = $ownerId;
                    }
                    if (t8_document_update_metadata($pdo, $documentId, $update, (int) ($currentUserId ?? 0), $isAdmin, $metadataVersion)) {
                        t8_flash_set('success', 'Document metadata updated.');
                        redirect(page_url('documents', ['action' => 'versions', 'id' => $documentId]));
                    }
                    $errors[] = 'The metadata update could not be saved.';
                }
            }
        }
        break;

    case 'upload_version':
        $documentId = (int) ($_GET['id'] ?? 0);
        $document = $documentId ? t8_document_fetch($pdo, $documentId) : null;
        if (!t8_document_can_view($document, (int) ($currentUserId ?? 0), $isAdmin)) {
            t8_flash_set('danger', 'Document not found.');
            redirect(page_url('documents'));
        }
        if (!t8_document_can_edit($document, (int) ($currentUserId ?? 0), $isAdmin)) {
            t8_flash_set('danger', 'You may replace a document only after it has been returned for revision.');
            redirect(page_url('documents', ['action' => 'versions', 'id' => $documentId]));
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                $uploadError = t8_document_validate_upload($_FILES['file'] ?? []);
                if ($uploadError !== '') {
                    $errors[] = $uploadError;
                }

                if (!$errors) {
                    $stored = null;
                    try {
                        $pdo->beginTransaction();

                        $lockStmt = $pdo->prepare(
                            'SELECT id FROM team8_documents WHERE id = :id FOR UPDATE'
                        );
                        $lockStmt->execute(['id' => $documentId]);
                        if (!$lockStmt->fetchColumn()) {
                            throw new RuntimeException('Document not found.');
                        }

                        $stmt = $pdo->prepare(
                            'SELECT COALESCE(MAX(version_no), 0) FROM team8_document_versions WHERE document_id = :id'
                        );
                        $stmt->execute(['id' => $documentId]);
                        $nextVersion = (int) $stmt->fetchColumn() + 1;

                        $stored = t8_document_store_upload($_FILES['file'], $document['title'], $nextVersion);

                        // STATUS/APPROVAL FIX: a re-upload follows the same
                        // rule as a fresh upload - only route back into
                        // 'pending' when the document's own category
                        // actually has a review step (t8_document_fetch()
                        // already joins category_name onto $document).
                        $reuploadStatus = ($isAdmin || !t8_document_requires_approval($document['category_name'] ?? null))
                            ? 'approved'
                            : 'pending';

                        $pdo->prepare(
                            'INSERT INTO team8_document_versions (document_id, version_no, file_path, file_size, checksum)
                             VALUES (:document_id, :version_no, :file_path, :file_size, :checksum)'
                        )->execute([
                            'document_id' => $documentId,
                            'version_no'  => $nextVersion,
                            'file_path'   => $stored['file_path'],
                            'file_size'   => $stored['file_size'],
                            'checksum'    => $stored['checksum'],
                        ]);
                        $versionUpdateSql = $documentHasStatus
                            ? "UPDATE team8_documents SET file_path = :file_path, current_version = :version_no, status = '" . $reuploadStatus . "', updated_at = NOW() WHERE id = :id"
                            : 'UPDATE team8_documents SET file_path = :file_path, current_version = :version_no, updated_at = NOW() WHERE id = :id';
                        $pdo->prepare($versionUpdateSql)->execute([
                            'file_path'  => $stored['file_path'],
                            'version_no' => $nextVersion,
                            'id'         => $documentId,
                        ]);

                        $pdo->commit();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        if (is_array($stored)) {
                            @unlink(t8_documents_dir() . '/' . basename($stored['file_path']));
                        }
                        throw $e;
                    }

                    t8_audit_log($pdo, $currentUserId, 'document', $documentId, 'new_version');
                    t8_flash_set('success', 'New version uploaded (v' . $nextVersion . ').');
                    redirect(page_url('documents', ['action' => 'versions', 'id' => $documentId]));
                }
            }
        }
        break;

    case 'set_status':
        t8_require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('documents', ['action' => 'browse']));
        }
        $id = (int) ($_POST['id'] ?? 0);
        $status = (string) ($_POST['status'] ?? '');
        $reviewReason = trim((string) ($_POST['review_reason'] ?? ''));
        $document = $id > 0 ? t8_document_fetch($pdo, $id) : null;
        if (
            !$documentHasStatus
            || !in_array($status, ['approved', 'returned_for_revision'], true)
            || $document === null
            || ($status === 'approved' && !t8_document_can_approve($document, (int) ($currentUserId ?? 0), $isAdmin))
        ) {
            t8_flash_set('danger', 'The document review request is invalid.');
        } elseif ($status === 'returned_for_revision' && $reviewReason === '') {
            t8_flash_set('danger', 'A reason is required when returning a document.');
        } else {
            if ($documentHasReviewReason) {
                $pdo->prepare('UPDATE team8_documents SET status = :status, review_reason = :reason WHERE id = :id')
                    ->execute(['status' => $status, 'reason' => $status === 'returned_for_revision' ? $reviewReason : null, 'id' => $id]);
            } else {
                // Keep approval usable on installations that have the status
                // migration but not the later review_reason migration yet.
                $pdo->prepare('UPDATE team8_documents SET status = :status WHERE id = :id')
                    ->execute(['status' => $status, 'id' => $id]);
            }
            t8_audit_log($pdo, $currentUserId, 'document', $id, $status);
            t8_document_notify_approval($pdo, $document, $id, $status);
            if ($status === 'returned_for_revision' && function_exists('t8_hr_notify')) {
                t8_hr_notify(
                    $pdo,
                    (int) $document['uploaded_by'],
                    'Your document "' . $document['title'] . '" was returned for revision. Reason: ' . $reviewReason,
                    page_url('documents', ['action' => 'versions', 'id' => $id])
                );
            }
            t8_flash_set('success', $status === 'approved' ? 'Document approved.' : 'Document returned for revision.');
        }
        redirect(page_url('documents', ['action' => 'browse']));
        break;

    case 'archive':
    case 'restore':
        if (!$isAdmin) {
            t8_require_role(['admin']);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('documents'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('documents'));
        }
        $id = (int) ($_POST['id'] ?? 0);
        $document = t8_document_fetch($pdo, $id);
        if ($document) {
            // NOTE: this is the document's own soft-delete flag (hides it
            // from the active/archived browse toggle below), separate
            // from its RETENTION record's own status - a document can be
            // soft-deleted here while its retention record independently
            // stays 'active' until its disposition date, same separation
            // already used for Legal Cases (Phase 3) and their deleted_at.
            $sql = $action === 'archive'
                ? 'UPDATE team8_documents SET deleted_at = NOW() WHERE id = :id'
                : 'UPDATE team8_documents SET deleted_at = NULL WHERE id = :id';
            $pdo->prepare($sql)->execute(['id' => $id]);
            t8_audit_log($pdo, $currentUserId, 'document', $id, $action);
            t8_flash_set('success', $action === 'archive' ? 'Document archived.' : 'Document restored.');
        } else {
            t8_flash_set('danger', 'Document not found.');
        }
        redirect(page_url('documents'));
        break;

    case 'hr_attachment_download':
        $type = strtolower(trim((string) ($_GET['type'] ?? '')));
        $id = (int) ($_GET['id'] ?? 0);
        $attachment = $id > 0 ? t8_hr_attachment_resolve_file($pdo, $type, $id) : null;

        if ($attachment === null || !t8_hr_attachment_can_access($attachment['row'], (int) ($currentUserId ?? 0), $isAdmin)) {
            http_response_code(403);
            echo 'You are not authorized to access this attachment.';
            exit;
        }

        $filePath = $attachment['resolved_path'];
        if (!is_file($filePath)) {
            http_response_code(404);
            echo 'Attachment not found on disk.';
            exit;
        }

        $downloadName = basename($filePath);
        t8_audit_log($pdo, $currentUserId, $type, $id, 'attachment_download');

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;

    case 'download':
        $versionId = (int) ($_GET['version_id'] ?? 0);
        $stmt = $pdo->prepare(
            'SELECT v.*, d.title, d.uploaded_by FROM team8_document_versions v
             JOIN team8_documents d ON d.id = v.document_id
             WHERE v.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $versionId]);
        $version = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $actorId = (int) ($currentUserId ?? 0);
        if ($versionId <= 0 || !t8_document_can_download($version, $actorId, $isAdmin, $pdo)) {
            http_response_code(404);
            echo 'File not found.';
            exit;
        }
        $legalCaseId = t8_document_assigned_legal_case_id($pdo, t8_document_entity_id($version), $actorId);
        $legalAccess = $legalCaseId !== null;
        $filePath = UPLOAD_DIR . '/' . $version['file_path'];
        if (!is_file($filePath)) {
            http_response_code(404);
            echo 'File not found on disk.';
            exit;
        }
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        $downloadName = t8_slugify($version['title']) . '_v' . $version['version_no'] . '.' . $ext;
        t8_audit_log($pdo, $currentUserId, 'document', (int) $version['document_id'], 'download');
        if ($legalAccess) {
            t8_audit_log($pdo, $currentUserId, 'legal_case', (int) $legalCaseId, 'document_download');
        }
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;

    case 'summarize':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('documents'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('documents'));
        }
        $summaryVersionId = (int) ($_POST['version_id'] ?? 0);
        $stmt = $pdo->prepare(
            'SELECT v.*, d.title, d.uploaded_by FROM team8_document_versions v
             JOIN team8_documents d ON d.id = v.document_id
             WHERE v.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $summaryVersionId]);
        $summaryVersion = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!t8_document_can_view($summaryVersion, (int) ($currentUserId ?? 0), $isAdmin)) {
            t8_flash_set('danger', 'Version not found.');
            redirect(page_url('documents'));
        }
        $summaryDocumentId = (int) $summaryVersion['document_id'];
        $summaryFilePath = UPLOAD_DIR . '/' . $summaryVersion['file_path'];
        $summaryExt = pathinfo($summaryFilePath, PATHINFO_EXTENSION);
        $extractedText = is_file($summaryFilePath) && function_exists('t8_extract_text_for_summary')
            ? t8_extract_text_for_summary($summaryFilePath, $summaryExt)
            : null;

        if ($extractedText === null || trim($extractedText) === '') {
            $summaryMessage = strtolower($summaryExt) === 'docx' && !class_exists('ZipArchive')
                ? 'DOCX summarization requires the PHP ZipArchive extension. Enable extension=zip in php.ini and restart Apache, or upload a .txt file.'
                : 'This file type (.' . strtoupper($summaryExt) . ') isn\'t supported for AI summarization yet. Currently supported: .txt and .docx.';
            t8_flash_set('danger', $summaryMessage);
            redirect(page_url('documents', ['action' => 'versions', 'id' => $summaryDocumentId]));
        }

        // Cap input length to stay within a reasonable token budget.
        $extractedText = mb_substr($extractedText, 0, 12000);

        try {
            $aiSummary = t8_ai_chat([
                ['role' => 'system', 'content' => 'You summarize documents for a facilities & administrative management system. Produce a concise summary (3-6 sentences), followed by up to 5 key bullet points if relevant.'],
                ['role' => 'user', 'content' => "Summarize this document titled \"{$summaryVersion['title']}\":\n\n" . $extractedText],
            ]);
            t8_audit_log($pdo, $currentUserId, 'document', $summaryDocumentId, 'ai_summarize');
            // Stashed in session and consumed once on the redirected-to
            // page below - avoids storing AI output in the database.
            $_SESSION['t8_ai_summary_' . $summaryVersionId] = $aiSummary;
        } catch (Throwable $e) {
            t8_flash_set('danger', 'AI summarization failed: ' . $e->getMessage());
        }
        redirect(page_url('documents', ['action' => 'versions', 'id' => $summaryDocumentId, 'summary_version' => $summaryVersionId]));
        break;
}

$showCreateForm = $action === 'create';
$showEditMetadataForm = $action === 'edit_metadata' && !empty($document);
$showUploadVersionForm = $action === 'upload_version' && !empty($document);
$showVersions = $action === 'versions';

if ($showVersions) {
    $documentId = (int) ($_GET['id'] ?? 0);
    $document = $documentId ? t8_document_fetch($pdo, $documentId) : null;
    if (!t8_document_can_view($document, (int) ($currentUserId ?? 0), $isAdmin)) {
        t8_flash_set('danger', 'Document not found.');
        redirect(page_url('documents'));
    }
    $versions = t8_document_all_versions($pdo, $documentId);
    t8_audit_log($pdo, $currentUserId, 'document', $documentId, 'view');

    // One-time AI summary, if the person just clicked "AI Summarize".
    $aiSummaryText = null;
    $aiSummaryVersionId = (int) ($_GET['summary_version'] ?? 0);
    if ($aiSummaryVersionId && isset($_SESSION['t8_ai_summary_' . $aiSummaryVersionId])) {
        $aiSummaryText = $_SESSION['t8_ai_summary_' . $aiSummaryVersionId];
        unset($_SESSION['t8_ai_summary_' . $aiSummaryVersionId]);
    }

    // PHASE 4: resolve this document's own retention record, if any,
    // so the Version History screen can link straight to it.
    $documentRetentionRecord = function_exists('t8_retention_fetch_for_entity')
        ? t8_retention_fetch_for_entity($pdo, 'document', $documentId)
        : null;
}

// The original module's landing list is now reached via ?action=browse
// ("Browse All Uploaded Documents" on the new dashboard), instead of
// being the default view - the default is now the HR dashboard above.
$showList = !$showCreateForm && !$showUploadVersionForm && !$showVersions && $action === 'browse';

/** Labels and routes for the sources shown on the unified browse page. */
function t8_document_browse_sources(): array
{
    return [
        'upload' => ['label' => 'Uploaded File', 'action' => 'versions'],
        'incident_report' => ['label' => 'Incident Report', 'action' => 'incident_report_view'],
        'nte' => ['label' => 'Notice To Explain', 'action' => 'nte_view'],
        'explanation' => ['label' => 'Explanation Letter', 'action' => 'explanation_view'],
        'memorandum' => ['label' => 'Memorandum', 'action' => 'memorandum_view'],
        'warning_letter' => ['label' => 'Warning Letter', 'action' => 'memorandum_view'],
        'certificate' => ['label' => 'Certificate', 'action' => 'certificate_view'],
    ];
}

function t8_document_browse_status_label(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

/**
 * Returns the status predicate for one source. The internal all_states
 * value is used only while discovering valid filter options.
 */
function t8_document_browse_status_sql(string $alias, bool $isUpload, string $status, array &$params): string
{
    if ($status === '__all_states') {
        return '1=1';
    }

    if ($isUpload) {
        return match ($status) {
            'all' => "$alias.deleted_at IS NULL AND $alias.status IN ('draft', 'pending', 'approved')",
            'active' => t8_document_expiration_filter_sql($alias, 'active', $params),
            'expired' => t8_document_expiration_filter_sql($alias, 'expired', $params),
            'expiring_soon' => t8_document_expiration_filter_sql($alias, 'expiring_soon', $params),
            'rejected' => "$alias.deleted_at IS NULL AND $alias.status = 'returned_for_revision'",
            'archived' => "$alias.deleted_at IS NOT NULL",
            'returned_for_revision' => "$alias.deleted_at IS NULL AND $alias.status = 'returned_for_revision'",
            default => (function () use ($alias, $status, &$params): string {
                $params[] = $status;
                return "$alias.deleted_at IS NULL AND $alias.status = ?";
            })(),
        };
    }

    return match ($status) {
        'all' => "$alias.status IN ('draft', 'pending', 'approved')",
        'active' => "$alias.status = 'approved'",
        'expired', 'expiring_soon' => '1=0',
        'rejected' => "$alias.status = 'rejected'",
        'archived' => "$alias.status = 'archived'",
        'returned_for_revision' => '1=0',
        default => (function () use ($alias, $status, &$params): string {
            $params[] = $status;
            return "$alias.status = ?";
        })(),
    };
}

/** Adds a case-insensitive, parameterized search predicate for static SQL expressions. */
function t8_document_browse_search_sql(array $expressions, string $search, array &$params): string
{
    if ($search === '') {
        return '';
    }

    $clauses = [];
    $like = '%' . mb_strtolower($search) . '%';
    foreach ($expressions as $expression) {
        $clauses[] = "LOWER(COALESCE($expression, '')) LIKE ?";
        $params[] = $like;
    }
    return ' AND (' . implode(' OR ', $clauses) . ')';
}

/** Search helper for HR sources; the normalized category label stays bound, not embedded in SQL. */
function t8_document_browse_hr_search_sql(array $expressions, string $search, string $categoryLabel, array &$params): string
{
    if ($search === '') {
        return '';
    }

    $clauses = [];
    $like = '%' . mb_strtolower($search) . '%';
    foreach ($expressions as $expression) {
        $clauses[] = "LOWER(COALESCE($expression, '')) LIKE ?";
        $params[] = $like;
    }
    $clauses[] = 'LOWER(?) LIKE ?';
    $params[] = mb_strtolower($categoryLabel);
    $params[] = $like;
    return ' AND (' . implode(' OR ', $clauses) . ')';
}

/** Keeps the browse option-discovery query within the existing staff visibility rules. */
function t8_document_browse_hr_option_scope_sql(string $alias, bool $isAdmin, string $status): string
{
    return !$isAdmin && $status === '__all_states'
        ? " AND $alias.status IN ('draft', 'pending', 'approved')"
        : '';
}

/**
 * Fetches the browse list source by source. Every source query applies its
 * own authorization and filters before its records are normalized and merged.
 */
function t8_document_browse_records(
    PDO $pdo,
    bool $isAdmin,
    int $currentUserId,
    ?int $currentDepartmentId,
    bool $hasMetadata,
    ?int $humanResourcesCategoryId,
    string $humanResourcesCategoryLabel,
    array $filters,
    int $pageSize = 25,
    int $page = 1,
    ?bool &$hasNextPage = null
): array {
    $sources = t8_document_browse_sources();
    $search = (string) ($filters['search'] ?? '');
    $status = (string) ($filters['status'] ?? 'all');
    $categoryId = (int) ($filters['category_id'] ?? 0);
    $sourceFilter = (string) ($filters['source_type'] ?? '');
    $records = [];
    $pageWindow = t8_document_browse_page_window($page, $pageSize);
    $page = $pageWindow['page'];
    $pageSize = $pageWindow['page_size'];
    $offset = $pageWindow['offset'];
    $sourceLimit = $pageWindow['source_limit'];
    $includeHr = $categoryId === 0 || ($humanResourcesCategoryId !== null && $categoryId === $humanResourcesCategoryId);

    $append = static function (string $sql, array $params, string $sourceKey, ?int $categoryKey, string $categoryLabel) use ($pdo, &$records, $sources, $sourceLimit): void {
        $sql .= ' ORDER BY timestamp DESC, id DESC LIMIT ' . $sourceLimit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $records[] = [
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'category_key' => array_key_exists('category_key', $row) && $row['category_key'] !== null
                    ? (int) $row['category_key']
                    : $categoryKey,
                'category_label' => (string) ($row['category_label'] ?? $categoryLabel),
                'source_key' => $sourceKey,
                'source_label' => $sources[$sourceKey]['label'],
                'status' => (string) $row['status'],
                'status_label' => t8_document_browse_status_label((string) $row['status']),
                'timestamp' => (string) $row['timestamp'],
                // Legacy renderer aliases; the browse table itself now receives
                // only records produced by this normalized pipeline.
                'doc_type' => $sourceKey,
                'ts' => (string) $row['timestamp'],
                'category' => (string) ($row['category_label'] ?? $categoryLabel),
                'url_action' => $sources[$sourceKey]['action'],
                'is_upload' => $sourceKey === 'upload',
            ];
        }
    };

    if (($sourceFilter === '' || $sourceFilter === 'upload')) {
        $params = [];
        $where = t8_document_browse_status_sql('d', true, $status, $params);
        if (!$isAdmin) {
            $where .= ' AND d.uploaded_by = ?';
            $params[] = $currentUserId;
        }
        if ($categoryId > 0) {
            $where .= ' AND d.category_id = ?';
            $params[] = $categoryId;
        }
        $where .= t8_document_browse_search_sql(
            array_filter([
                'd.title', 'd.document_type', 'c.name', 'u.full_name',
                $hasMetadata ? 'dep.name' : null,
                $hasMetadata ? 'owner.full_name' : null,
                "'Uploaded File'",
            ]),
            $search,
            $params
        );
        $append(
            "SELECT d.id, d.title AS label, CASE WHEN d.deleted_at IS NOT NULL THEN 'archived' ELSE d.status END AS status, COALESCE(d.updated_at, d.created_at) AS timestamp,
                    c.id AS category_key, COALESCE(c.name, 'Uncategorized') AS category_label
             FROM team8_documents d
             LEFT JOIN team8_document_categories c ON c.id = d.category_id
             JOIN users u ON u.id = d.uploaded_by "
             . ($hasMetadata ? 'LEFT JOIN departments dep ON dep.id = d.department_id LEFT JOIN users owner ON owner.id = d.owner_id ' : '')
             . "WHERE $where",
            $params,
            'upload',
            null,
            ''
        );
    }

    // HR source queries share one normalized Human Resources category.
    if ($includeHr && ($isAdmin || !in_array($status, ['archived', 'rejected'], true))) {
        $hrCategoryLabel = $humanResourcesCategoryLabel;
        $hrCategoryKey = $humanResourcesCategoryId;
        $hrAllowed = static fn (string $key): bool => $sourceFilter === '' || $sourceFilter === $key;

        if ($hrAllowed('incident_report')) {
            $params = [];
            $where = t8_document_browse_status_sql('ir', false, $status, $params);
            $where .= t8_document_browse_hr_option_scope_sql('ir', $isAdmin, $status);
            if (!$isAdmin) { $where .= ' AND ir.employee_id = ?'; $params[] = $currentUserId; }
            $where .= t8_document_browse_hr_search_sql(['ir.document_number', 'ir.incident_type', 'employee.full_name', 'dep.name', "'Incident Report'"], $search, $hrCategoryLabel, $params);
            $append("SELECT ir.id, ir.document_number AS label, ir.status, COALESCE(ir.updated_at, ir.created_at) AS timestamp FROM team8_incident_reports ir JOIN users employee ON employee.id = ir.employee_id LEFT JOIN departments dep ON dep.id = ir.department_id WHERE $where", $params, 'incident_report', $hrCategoryKey, $hrCategoryLabel);
        }
        if ($hrAllowed('nte')) {
            $params = [];
            $where = t8_document_browse_status_sql('n', false, $status, $params);
            $where .= t8_document_browse_hr_option_scope_sql('n', $isAdmin, $status);
            if (!$isAdmin) { $where .= ' AND n.employee_id = ?'; $params[] = $currentUserId; }
            $where .= t8_document_browse_hr_search_sql(['n.document_number', 'employee.full_name', 'dep.name', "'Notice To Explain'"], $search, $hrCategoryLabel, $params);
            $append("SELECT n.id, n.document_number AS label, n.status, COALESCE(n.updated_at, n.created_at) AS timestamp FROM team8_notice_to_explain n JOIN users employee ON employee.id = n.employee_id LEFT JOIN departments dep ON dep.id = employee.department_id WHERE $where", $params, 'nte', $hrCategoryKey, $hrCategoryLabel);
        }
        if ($hrAllowed('explanation')) {
            $params = [];
            $where = t8_document_browse_status_sql('e', false, $status, $params);
            $where .= t8_document_browse_hr_option_scope_sql('e', $isAdmin, $status);
            if (!$isAdmin) { $where .= ' AND e.employee_id = ?'; $params[] = $currentUserId; }
            $where .= t8_document_browse_hr_search_sql(['n.document_number', 'employee.full_name', 'dep.name', "'Explanation Letter'"], $search, $hrCategoryLabel, $params);
            $append("SELECT e.id, CONCAT('Explanation for ', n.document_number) AS label, e.status, COALESCE(e.updated_at, e.submitted_at) AS timestamp FROM team8_explanations e JOIN team8_notice_to_explain n ON n.id = e.nte_id JOIN users employee ON employee.id = e.employee_id LEFT JOIN departments dep ON dep.id = employee.department_id WHERE $where", $params, 'explanation', $hrCategoryKey, $hrCategoryLabel);
        }
        foreach (['memorandum', 'warning_letter'] as $memoKey) {
            if (!$hrAllowed($memoKey)) { continue; }
            $params = [];
            $where = t8_document_browse_status_sql('m', false, $status, $params) . " AND m.kind = '" . $memoKey . "'";
            $where .= t8_document_browse_hr_option_scope_sql('m', $isAdmin, $status);
            $join = '';
            if (!$isAdmin) {
                $join = ' JOIN team8_memorandum_recipients mr ON mr.memorandum_id = m.id ';
                $where .= " AND (mr.recipient_type = 'all_departments' OR mr.department_id = ?)";
                $params[] = $currentDepartmentId ?? 0;
            }
            $where .= t8_document_browse_hr_search_sql(['m.document_number', 'm.title', 'm.kind', "'$memoKey'"], $search, $hrCategoryLabel, $params);
            $append("SELECT DISTINCT m.id, CONCAT(m.document_number, ' — ', m.title) AS label, m.status, COALESCE(m.updated_at, m.created_at) AS timestamp FROM team8_memorandums m $join WHERE $where", $params, $memoKey, $hrCategoryKey, $hrCategoryLabel);
        }
        if ($hrAllowed('certificate')) {
            $params = [];
            $where = t8_document_browse_status_sql('c', false, $status, $params);
            $where .= t8_document_browse_hr_option_scope_sql('c', $isAdmin, $status);
            $join = ' JOIN users employee ON employee.id = c.employee_id LEFT JOIN departments dep ON dep.id = employee.department_id ';
            if (!$isAdmin) { $join .= ' JOIN team8_certificate_recipients cr ON cr.certificate_id = c.id '; $where .= ' AND cr.employee_id = ?'; $params[] = $currentUserId; }
            $where .= t8_document_browse_hr_search_sql(['c.document_number', 'c.certificate_type', 'employee.full_name', 'dep.name', "'Certificate'"], $search, $hrCategoryLabel, $params);
            $append("SELECT DISTINCT c.id, c.document_number AS label, c.status, COALESCE(c.updated_at, c.created_at) AS timestamp FROM team8_certificates c $join WHERE $where", $params, 'certificate', $hrCategoryKey, $hrCategoryLabel);
        }
    }

    usort($records, static fn (array $a, array $b): int => strtotime($b['timestamp']) <=> strtotime($a['timestamp'])
        ?: strcmp((string) $a['source_key'], (string) $b['source_key'])
        ?: ((int) $b['id'] <=> (int) $a['id']));
    $pageRecords = array_slice($records, $offset, $pageSize);
    $hasNextPage = count($records) > $offset + $pageSize;
    return $pageRecords;
}

if ($showList) {
    $hasMetadata = t8_document_has_column($pdo, 'department_id') && t8_document_has_column($pdo, 'owner_id');
    $search = trim((string) ($_GET['q'] ?? ''));
    $categoryFilter = (int) ($_GET['category_id'] ?? 0);
    $validCategoryIds = array_map(static fn (array $category): int => (int) $category['id'], $categories);
    if (!in_array($categoryFilter, $validCategoryIds, true)) {
        $categoryFilter = 0;
    }
    $humanResourcesCategoryId = null;
    $humanResourcesCategoryLabel = 'Human Resources';
    foreach ($categories as $category) {
        if (strcasecmp((string) $category['name'], 'Human Resources') === 0) {
            $humanResourcesCategoryId = (int) $category['id'];
            $humanResourcesCategoryLabel = (string) $category['name'];
            break;
        }
    }

    $currentDepartmentId = isset($_SESSION['department_id']) ? (int) $_SESSION['department_id'] : null;
    $requestedStatus = trim((string) ($_GET['status'] ?? 'all'));
    $includeHumanResources = $categoryFilter === 0 || ($humanResourcesCategoryId !== null && $categoryFilter === $humanResourcesCategoryId);
    $availableSourceKeys = $includeHumanResources
        ? array_keys(t8_document_browse_sources())
        : ['upload'];
    if (!$isAdmin && in_array($requestedStatus, ['archived', 'rejected'], true)) {
        $availableSourceKeys = ['upload'];
    }
    $availableStatuses = ['draft', 'pending', 'approved', 'rejected', 'returned_for_revision', 'archived'];
    $allowedStatuses = array_merge(['all', 'active', 'expired', 'expiring_soon', 'rejected', 'archived'], $availableStatuses);
    $statusFilter = in_array($requestedStatus, $allowedStatuses, true) ? $requestedStatus : 'all';
    $requestedSource = trim((string) ($_GET['source_type'] ?? ''));
    $sourceFilter = in_array($requestedSource, $availableSourceKeys, true) ? $requestedSource : '';

    $browsePage = t8_document_browse_page_number($_GET['browse_page'] ?? 1);
    $browsePageSize = T8_DOCUMENT_BROWSE_PAGE_SIZE;
    $browseHasNextPage = false;
    $browseRecords = t8_document_browse_records($pdo, $isAdmin, (int) $currentUserId, $currentDepartmentId, $hasMetadata, $humanResourcesCategoryId, $humanResourcesCategoryLabel, [
        'status' => $statusFilter,
        'search' => $search,
        'category_id' => $categoryFilter,
        'source_type' => $sourceFilter,
    ], $browsePageSize, $browsePage, $browseHasNextPage);
    $allDocumentsView = true;
    $allActiveDocuments = $browseRecords;
    $documentCategories = [];
    $browseUploadIds = array_values(array_map(
        static fn (array $record): int => (int) $record['id'],
        array_filter($allActiveDocuments, static fn (array $record): bool => $record['doc_type'] === 'upload')
    ));
    $browseDocuments = t8_document_fetch_many($pdo, $browseUploadIds);
    $browseRetention = t8_document_retention_map($pdo, $browseUploadIds);
    $browsePageUrl = static function (int $page) use ($search, $categoryFilter, $statusFilter, $sourceFilter): string {
        return page_url('documents', [
            'action' => 'browse',
            'q' => $search,
            'category_id' => $categoryFilter,
            'status' => $statusFilter,
            'source_type' => $sourceFilter,
            'browse_page' => $page,
        ]);
    };
}

if (!$showCreateForm && !$showEditMetadataForm && !$showUploadVersionForm && !$showVersions && !$showList) {
    // Neither an HR action, 'dashboard', nor any known upload action -
    // fall back to the dashboard rather than a blank page.
    require __DIR__ . '/hr/dashboard.php';
    return;
}

function t8_format_filesize(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

/**
 * Shared camera-capture widget markup + script. Renders a "Take Photo"
 * button beside the existing #file input; captured photos are pushed
 * into that same input via DataTransfer, so no other code needs to
 * change. Safe to include on any page with an <input id="file">.
 */
function t8_render_camera_capture(): void
{
    ?>
    <div class="t8-camera-capture" style="margin-top: var(--t8-space-2);">
        <button type="button" class="t8-btn t8-btn-outline t8-btn-sm" id="t8CameraBtn">
            <i class="fa-solid fa-camera"></i> Take Photo
        </button>

        <div id="t8CameraPanel" style="display:none; margin-top: var(--t8-space-3); padding: var(--t8-space-3); border: 1px solid var(--t8-border); border-radius: var(--t8-radius-sm); background: var(--t8-cream);">
            <video id="t8CameraVideo" playsinline autoplay muted style="width:100%; max-width:480px; border-radius:8px; display:block; background:#000;"></video>
            <canvas id="t8CameraCanvas" style="display:none;"></canvas>
            <img id="t8CameraPreview" alt="Captured photo preview" style="width:100%; max-width:480px; border-radius:8px; display:none;">
            <div style="margin-top: var(--t8-space-2); display:flex; gap:8px; flex-wrap:wrap;">
                <button type="button" class="t8-btn t8-btn-accent t8-btn-sm" id="t8CameraCapture">
                    <i class="fa-solid fa-camera"></i> Capture
                </button>
                <button type="button" class="t8-btn t8-btn-outline t8-btn-sm" id="t8CameraRetake" style="display:none;">
                    <i class="fa-solid fa-rotate-left"></i> Retake
                </button>
                <button type="button" class="t8-btn t8-btn-success t8-btn-sm" id="t8CameraUse" style="display:none;">
                    <i class="fa-solid fa-check"></i> Use Photo
                </button>
                <button type="button" class="t8-btn t8-btn-danger t8-btn-sm" id="t8CameraCancel">
                    <i class="fa-solid fa-xmark"></i> Cancel
                </button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var cameraBtn = document.getElementById('t8CameraBtn');
        var panel = document.getElementById('t8CameraPanel');
        var video = document.getElementById('t8CameraVideo');
        var canvas = document.getElementById('t8CameraCanvas');
        var preview = document.getElementById('t8CameraPreview');
        var captureBtn = document.getElementById('t8CameraCapture');
        var retakeBtn = document.getElementById('t8CameraRetake');
        var useBtn = document.getElementById('t8CameraUse');
        var cancelBtn = document.getElementById('t8CameraCancel');
        var fileInput = document.getElementById('file');
        var stream = null;

        if (!cameraBtn || !fileInput) { return; }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            cameraBtn.style.display = 'none';
            return;
        }

        function stopStream() {
            if (stream) {
                stream.getTracks().forEach(function (t) { t.stop(); });
                stream = null;
            }
        }

        function resetPanelToLive() {
            video.style.display = 'block';
            preview.style.display = 'none';
            captureBtn.style.display = 'inline-flex';
            retakeBtn.style.display = 'none';
            useBtn.style.display = 'none';
        }

        cameraBtn.addEventListener('click', function () {
            panel.style.display = 'block';
            resetPanelToLive();
            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
                .then(function (s) {
                    stream = s;
                    video.srcObject = s;
                })
                .catch(function () {
                    alert('Could not access the camera. Please check permissions, or use the file upload option instead.');
                    panel.style.display = 'none';
                });
        });

        captureBtn.addEventListener('click', function () {
            canvas.width = video.videoWidth || 640;
            canvas.height = video.videoHeight || 480;
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            preview.src = canvas.toDataURL('image/jpeg', 0.9);
            video.style.display = 'none';
            preview.style.display = 'block';
            captureBtn.style.display = 'none';
            retakeBtn.style.display = 'inline-flex';
            useBtn.style.display = 'inline-flex';
        });

        retakeBtn.addEventListener('click', function () {
            resetPanelToLive();
        });

        useBtn.addEventListener('click', function () {
            canvas.toBlob(function (blob) {
                if (!blob) { return; }
                var capturedFile = new File([blob], 'capture-' + Date.now() + '.jpg', { type: 'image/jpeg' });
                var dt = new DataTransfer();
                dt.items.add(capturedFile);
                fileInput.files = dt.files;
                stopStream();
                panel.style.display = 'none';
            }, 'image/jpeg', 0.9);
        });

        cancelBtn.addEventListener('click', function () {
            stopStream();
            panel.style.display = 'none';
        });

        window.addEventListener('beforeunload', stopStream);
    })();
    </script>
    <?php
}
?>
<h1>Document Management</h1>
<p class="t8-help-text">Upload, version, and archive documents.</p>

<?php foreach ($errors as $error): ?>
    <div class="t8-alert t8-alert-danger"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if ($showCreateForm): ?>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title">Upload New Document</h2>
        </div>
        <form method="post" action="<?= e(page_url('documents', ['action' => 'create'])) ?>" enctype="multipart/form-data" novalidate class="t8-document-form-grid">
            <?= t8_csrf_field() ?>

            <div class="t8-field t8-form-span-full">
                <label class="t8-label" for="title">Title</label>
                <input class="t8-input" type="text" id="title" name="title"
                       value="<?= e((string) ($_POST['title'] ?? '')) ?>" required>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="category_id">Category</label>
                <select class="t8-select" id="category_id" name="category_id" required>
                    <option value="" disabled selected>Choose a category</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e((string) $cat['id']) ?>" <?= isset($_POST['category_id']) && (string) $_POST['category_id'] === (string) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="t8-help-text">Also determines the suggested retention basis/period, and whether this document needs admin approval before it's applied automatically on upload.</span>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="document_type">Document Type</label>
                <select class="t8-select" id="document_type" name="document_type" required disabled>
                    <option value="" disabled selected>Choose a document type</option>
                </select>
            </div>

            <?php if ($isAdmin): ?>
                <div class="t8-field">
                    <label class="t8-label" for="department_id">Department</label>
                    <select class="t8-select" id="department_id" name="department_id">
                        <option value="">Not assigned</option>
                        <?php foreach ($departments as $department): ?>
                            <option value="<?= e((string) $department['id']) ?>"><?= e($department['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="t8-field">
                    <label class="t8-label" for="owner_id">Owner</label>
                    <select class="t8-select" id="owner_id" name="owner_id">
                        <option value="">Not assigned</option>
                        <?php foreach ($owners as $owner): ?>
                            <option value="<?= e((string) $owner['id']) ?>"><?= e($owner['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="t8-field">
                <label class="t8-label" for="expiration_date">Expiration Date</label>
                <input class="t8-input" type="date" id="expiration_date" name="expiration_date"
                      value="<?= e((string) ($_POST['expiration_date'] ?? '')) ?>" data-t8-date-rule="future">
            </div>

            <div class="t8-field t8-form-span-full">
                <label class="t8-label" for="file">File</label>
                <input class="t8-input" type="file" id="file" name="file" required>
                <span class="t8-help-text">
                    Max <?= e((string) UPLOAD_MAX_SIZE_MB) ?>MB. Allowed: <?= e(implode(', ', T8_DOC_ALLOWED_EXT)) ?>
                </span>
                <?php t8_render_camera_capture(); ?>
            </div>

            <div class="t8-form-actions">
                <button class="t8-btn t8-btn-accent" type="submit">
                    <i class="fa-solid fa-upload"></i> Upload
                </button>
                <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents')) ?>">Cancel</a>
            </div>
        </form>
    </div>

    <script>
    (function () {
        var categorySelect = document.getElementById('category_id');
        var typeSelect = document.getElementById('document_type');
        var documentTypeOptions = <?= json_encode($documentTypeOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        var selectedType = <?= json_encode((string) ($_POST['document_type'] ?? '')) ?>;

        function updateTypeOptions() {
            var selectedCategory = categorySelect.value;
            typeSelect.innerHTML = '<option value="" disabled>Choose a document type</option>';
            typeSelect.disabled = true;
            if (!selectedCategory || !documentTypeOptions[selectedCategory]) {
                return;
            }
            documentTypeOptions[selectedCategory].forEach(function (type) {
                var option = document.createElement('option');
                option.value = type;
                option.textContent = type;
                if (type === selectedType) {
                    option.selected = true;
                }
                typeSelect.appendChild(option);
            });
            typeSelect.disabled = false;
            if (!selectedType) {
                typeSelect.selectedIndex = 0;
            }
        }

        categorySelect.addEventListener('change', function () {
            selectedType = '';
            updateTypeOptions();
        });

        updateTypeOptions();
    })();
    </script>

<?php elseif ($showUploadVersionForm): ?>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title">Upload New Version — <?= e($document['title']) ?></h2>
        </div>
        <form method="post" action="<?= e(page_url('documents', ['action' => 'upload_version', 'id' => $document['id']])) ?>"
              enctype="multipart/form-data" novalidate>
            <?= t8_csrf_field() ?>

            <div class="t8-field">
                <label class="t8-label" for="file">File</label>
                <input class="t8-input" type="file" id="file" name="file" required>
                <span class="t8-help-text">
                    Max <?= e((string) UPLOAD_MAX_SIZE_MB) ?>MB. Allowed: <?= e(implode(', ', T8_DOC_ALLOWED_EXT)) ?>
                </span>
                <?php t8_render_camera_capture(); ?>
            </div>

            <div class="t8-form-actions">
                <button class="t8-btn t8-btn-accent" type="submit">
                    <i class="fa-solid fa-upload"></i> Upload Version
                </button>
                <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents', ['action' => 'versions', 'id' => $document['id']])) ?>">Cancel</a>
            </div>
        </form>
    </div>

<?php elseif ($showEditMetadataForm): ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents', ['action' => 'versions', 'id' => $document['id']])) ?>">
            <i class="fa-solid fa-arrow-left"></i> Back to Version History
        </a>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title">Edit Document Metadata</h2>
        </div>
        <form method="post" action="<?= e(page_url('documents', ['action' => 'edit_metadata', 'id' => $document['id']])) ?>" novalidate class="t8-document-form-grid">
            <?= t8_csrf_field() ?>
            <input type="hidden" name="metadata_version" value="<?= e(is_string($_POST['metadata_version'] ?? null) ? $_POST['metadata_version'] : t8_document_metadata_version_token($document)) ?>">

            <div class="t8-field t8-form-span-full">
                <label class="t8-label" for="title">Title</label>
                <input class="t8-input" type="text" id="title" name="title" value="<?= e((string) ($_POST['title'] ?? ($document['title'] ?? ''))) ?>" required>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="category_id">Category</label>
                <select class="t8-select" id="category_id" name="category_id" required>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e((string) $cat['id']) ?>" <?= ((string) ($_POST['category_id'] ?? ($document['category_id'] ?? '')) === (string) $cat['id']) ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="document_type">Document Type</label>
                <select class="t8-select" id="document_type" name="document_type" required>
                    <?php $selectedType = (string) ($_POST['document_type'] ?? ($document['document_type'] ?? ''));
                    $categoryIdForTypes = (string) ($_POST['category_id'] ?? ($document['category_id'] ?? ''));
                    $availableTypes = $categoryIdForTypes !== '' && isset($documentTypeOptions[$categoryIdForTypes]) ? $documentTypeOptions[$categoryIdForTypes] : [];
                    if ($availableTypes === [] && isset($document['category_id']) && isset($documentTypeOptions[(string) $document['category_id']])) {
                        $availableTypes = $documentTypeOptions[(string) $document['category_id']];
                    }
                    if ($availableTypes === []): ?>
                        <option value="<?= e($selectedType) ?>" selected><?= e($selectedType !== '' ? $selectedType : 'Custom Type') ?></option>
                    <?php else: ?>
                        <?php foreach ($availableTypes as $type): ?>
                            <option value="<?= e($type) ?>" <?= $selectedType === $type ? 'selected' : '' ?>><?= e($type) ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <?php if ($isAdmin): ?>
                <div class="t8-field">
                    <label class="t8-label" for="department_id">Department</label>
                    <select class="t8-select" id="department_id" name="department_id">
                        <option value="">Not assigned</option>
                        <?php foreach ($departments as $department): ?>
                            <option value="<?= e((string) $department['id']) ?>" <?= ((string) ($_POST['department_id'] ?? ($document['department_id'] ?? '')) === (string) $department['id']) ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="t8-field">
                    <label class="t8-label" for="owner_id">Owner</label>
                    <select class="t8-select" id="owner_id" name="owner_id">
                        <option value="">Not assigned</option>
                        <?php foreach ($owners as $owner): ?>
                            <option value="<?= e((string) $owner['id']) ?>" <?= ((string) ($_POST['owner_id'] ?? ($document['owner_id'] ?? '')) === (string) $owner['id']) ? 'selected' : '' ?>><?= e($owner['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="t8-field">
                <label class="t8-label" for="expiration_date">Expiration Date</label>
                <input class="t8-input" type="date" id="expiration_date" name="expiration_date" value="<?= e((string) ($_POST['expiration_date'] ?? ($document['expiration_date'] ?? ''))) ?>" data-t8-date-rule="future">
            </div>

            <div class="t8-form-actions t8-form-span-full">
                <button class="t8-btn t8-btn-accent" type="submit">
                    <i class="fa-solid fa-floppy-disk"></i> Save Metadata
                </button>
                <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents', ['action' => 'versions', 'id' => $document['id']])) ?>">Cancel</a>
            </div>
        </form>
    </div>

<?php elseif ($showVersions): ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents')) ?>">
            <i class="fa-solid fa-arrow-left"></i> Back to Documents
        </a>
    </div>

    <?php if ($aiSummaryText !== null): ?>
        <div class="t8-card" style="border-left: 4px solid var(--t8-primary);">
            <div class="t8-card-header">
                <h2 class="t8-card-title"><i class="fa-solid fa-robot"></i> AI Summary</h2>
            </div>
            <p style="white-space: pre-wrap; padding: 0 var(--t8-space-4) var(--t8-space-4);"><?= e($aiSummaryText) ?></p>
        </div>
    <?php endif; ?>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= e($document['title']) ?> — Version History</h2>
            <?php if (t8_document_can_edit($document, (int) ($currentUserId ?? 0), $isAdmin)): ?>
                <a class="t8-btn t8-btn-accent" href="<?= e(page_url('documents', ['action' => 'upload_version', 'id' => $document['id']])) ?>">
                    <i class="fa-solid fa-upload"></i> Upload New Version
                </a>
            <?php endif; ?>
            <?php if (t8_document_can_edit_metadata($document, (int) ($currentUserId ?? 0), $isAdmin)): ?>
                <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents', ['action' => 'edit_metadata', 'id' => $document['id']])) ?>">
                    <i class="fa-solid fa-pen-to-square"></i> Edit Metadata
                </a>
            <?php endif; ?>
        </div>
        <?php if ($document['status'] === 'returned_for_revision'): ?>
            <div class="t8-field" style="padding: 0 var(--t8-space-4) var(--t8-space-4);">
                <label class="t8-label">Reason for Return</label>
                <p><?= nl2br(e((string) ($document['review_reason'] ?? '—'))) ?></p>
            </div>
        <?php endif; ?>
        <div class="t8-table-wrap">
            <table class="t8-table">
                <thead>
                    <tr>
                        <th>Version</th>
                        <th>Size</th>
                        <th>Uploaded At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($versions as $i => $v): ?>
                        <tr>
                            <td>
                                v<?= e((string) $v['version_no']) ?>
                                <?php if ($i === 0): ?>
                                    <span class="t8-badge t8-badge-approved">Latest</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e(t8_format_filesize((int) $v['file_size'])) ?></td>
                            <td><?= e(format_date($v['uploaded_at'], 'M d, Y g:i A')) ?></td>
                            <td class="t8-row-actions t8-version-actions">
                                <?php if ($i === 0): ?>
                                    <?php t8_document_render_menu($document, $isAdmin, 'active', $documentRetentionRecord, (int) $v['id'], false); ?>
                                <?php else: ?>
                                    <div class="t8-row-menu">
                                        <button type="button" class="t8-row-menu-trigger" aria-haspopup="true" aria-expanded="false" title="More actions">
                                            <i class="fa-solid fa-ellipsis-vertical"></i>
                                        </button>
                                        <div class="t8-row-menu-panel" role="menu">
                                            <a class="t8-row-menu-item" role="menuitem" href="<?= e(page_url('documents', ['action' => 'download', 'version_id' => $v['id']])) ?>">
                                                <i class="fa-solid fa-download"></i> Download
                                            </a>
                                            <form method="post" action="<?= e(page_url('documents', ['action' => 'summarize'])) ?>">
                                                <?= t8_csrf_field() ?>
                                                <input type="hidden" name="version_id" value="<?= e((string) $v['id']) ?>">
                                                <button class="t8-row-menu-item" type="submit" role="menuitem">
                                                    <i class="fa-solid fa-robot"></i> AI Summarize
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php t8_render_document_detail_modal(); ?>

<?php elseif ($showList): ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents')) ?>">
            <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
        </a>
        <a class="t8-btn t8-btn-accent" href="<?= e(page_url('documents', ['action' => 'create'])) ?>">
            <i class="fa-solid fa-upload"></i> Upload New Document
        </a>
    </div>

    <form id="t8DocumentsFilterForm" method="get" class="t8-card" style="margin-bottom:var(--t8-space-4); padding:var(--t8-space-4);">
        <input type="hidden" name="page" value="documents">
        <input type="hidden" name="action" value="browse">
        <?php if (!$isAdmin): ?><p class="t8-help-text" style="margin-top:0;">My Documents: <a href="<?= e(page_url('documents', ['action' => 'browse', 'status' => 'pending'])) ?>">Pending</a> · <a href="<?= e(page_url('documents', ['action' => 'browse', 'status' => 'active'])) ?>">Approved</a> · <a href="<?= e(page_url('documents', ['action' => 'browse', 'status' => 'rejected'])) ?>">Rejected</a></p><?php endif; ?>
        <div class="t8-documents-filters">
            <label>Search<input class="t8-input" type="search" name="q" value="<?= e($search) ?>" placeholder="Title, number, category, department"></label>
            <label>Category<select class="t8-select" name="category_id"><option value="">All categories</option><?php foreach ($categories as $cat): ?><option value="<?= e((string) $cat['id']) ?>" <?= $categoryFilter === (int) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option><?php endforeach; ?></select></label>
            <label>Status<select class="t8-select" name="status"><option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All (Live / Current)</option><option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option><option value="expiring_soon" <?= $statusFilter === 'expiring_soon' ? 'selected' : '' ?>>Expiring Soon</option><option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option><?php foreach ($availableStatuses as $availableStatus): ?><?php if (!in_array($availableStatus, ['rejected', 'archived', 'expired', 'expiring_soon'], true)): ?><option value="<?= e($availableStatus) ?>" <?= $statusFilter === $availableStatus ? 'selected' : '' ?>><?= e(t8_document_browse_status_label($availableStatus)) ?></option><?php endif; ?><?php endforeach; ?><option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option><option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>Archived</option></select></label>
            <label>Source / Type<select class="t8-select" name="source_type"><option value="">All sources</option><?php foreach (t8_document_browse_sources() as $sourceKey => $source): ?><?php if (in_array($sourceKey, $availableSourceKeys, true) || $sourceFilter === $sourceKey): ?><option value="<?= e($sourceKey) ?>" <?= $sourceFilter === $sourceKey ? 'selected' : '' ?>><?= e($source['label']) ?></option><?php endif; ?><?php endforeach; ?></select></label>
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents', ['action' => 'browse'])) ?>">Clear Filters</a>
        </div>
    </form>

    <?php if ($allDocumentsView): ?>
        <div id="t8DocumentsResults" class="t8-card" style="margin-bottom:var(--t8-space-4);">
            <div class="t8-card-header">
                <h2 class="t8-card-title"><?= $statusFilter === 'rejected' ? 'Rejected Documents' : ($statusFilter === 'archived' ? 'Archived Documents' : ($statusFilter === 'active' ? 'Active Documents' : 'Documents')) ?></h2>
            </div>
            <?php if ($allActiveDocuments === []): ?>
                <div class="t8-empty"><strong>No documents found</strong><span>No documents match the current search and filter criteria. Try adjusting your filters.</span></div>
            <?php else: ?>
                <div class="t8-table-wrap">
                    <table class="t8-table">
                        <thead>
                            <tr>
                                <th>Title / Number</th>
                                <th>Category</th>
                                <th>Source / Type</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allActiveDocuments as $document): ?>
                                <tr>
                                    <td><?= e((string) $document['label']) ?></td>
                                    <td><?= e((string) $document['category']) ?></td>
                                    <td><?= e((string) $document['source_label']) ?></td>
                                    <td><span class="t8-badge <?= e(t8_hr_status_badge((string) $document['status'])) ?>"><?= e((string) $document['status_label']) ?></span></td>
                                    <td><?= e(format_date((string) $document['ts'], 'M d, Y')) ?></td>
                                    <td class="t8-row-actions">
                                        <?php if ($document['doc_type'] === 'upload'):
                                            $feedDocument = $browseDocuments[(int) $document['id']] ?? null;
                                            $feedRetentionRecord = $browseRetention[(int) $document['id']] ?? null;
                                            if ($feedDocument !== null):
                                                t8_document_render_menu($feedDocument, $isAdmin, $statusFilter === 'archived' ? 'archived' : 'active', $feedRetentionRecord);
                                            endif;
                                        else: ?>
                                            <?php t8_document_render_feed_menu($document); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <nav class="t8-pagination" aria-label="Document pages">
                    <?php if ($browsePage > 1): ?>
                        <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e($browsePageUrl($browsePage - 1)) ?>">Previous</a>
                    <?php endif; ?>
                    <span class="t8-help-text">Page <?= e((string) $browsePage) ?></span>
                    <?php if ($browseHasNextPage): ?>
                        <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e($browsePageUrl($browsePage + 1)) ?>">Next</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>
    <?php else: ?>

    <div id="t8DocumentsResults" class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= $statusFilter === 'archived' ? 'Archived Documents' : ($isAdmin ? 'All Documents' : 'My Documents') ?></h2>
        </div>
        <?php if ($documents === [] && $archivedHrRecords === [] && $rejectedHrRecords === []): ?>
            <div class="t8-empty">
                <?= $statusFilter === 'archived' ? 'No archived documents.' : 'No documents uploaded yet.' ?>
            </div>
        <?php elseif ($statusFilter === 'archived'): ?>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead>
                        <tr>
                            <th>Title / Number</th>
                            <th>Source / Type</th>
                            <th>Owner / Subject</th>
                            <th>Status</th>
                            <th>Archived Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documents as $doc): ?>
                            <?php
                            $docRetentionRecord = function_exists('t8_retention_fetch_for_entity')
                                ? t8_retention_fetch_for_entity($pdo, 'document', (int) $doc['id'])
                                : null;
                            ?>
                            <tr>
                                <td><?= e($doc['title']) ?></td>
                                <td><?= e($doc['document_type'] ?? 'Uploaded File') ?></td>
                                <td><?= e($doc['uploaded_by_name'] ?? '—') ?></td>
                                <td><span class="t8-badge t8-badge-archived">Archived</span></td>
                                <td><?= e(format_date((string) ($doc['updated_at'] ?? $doc['created_at']), 'M d, Y')) ?></td>
                                <td class="t8-row-actions">
                                    <?php t8_document_render_menu($doc, $isAdmin, $statusFilter, $docRetentionRecord); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($archivedHrRecords as $record): ?>
                            <tr>
                                <td><?= e($record['archive_title']) ?></td>
                                <td><?= e($record['archive_type']) ?></td>
                                <td><?= e($record['archive_subject']) ?></td>
                                <td><span class="t8-badge t8-badge-archived">Archived</span></td>
                                <td><?= e(format_date($record['archived_at'], 'M d, Y')) ?></td>
                                <td class="t8-row-actions">
                                    <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e($record['view_url']) ?>">
                                        <i class="fa-solid fa-eye"></i> View
                                    </a>
                                    <?php if ($record['print_url'] !== null): ?>
                                        <a class="t8-btn t8-btn-outline t8-btn-sm" target="_blank" href="<?= e($record['print_url']) ?>">
                                            <i class="fa-solid fa-print"></i> Print
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Document Type</th>
                            <th>Version</th>
                            <th>Status</th>
                            <th>Expiration</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documents as $doc): ?>
                            <?php
                            $docRetentionRecord = function_exists('t8_retention_fetch_for_entity')
                                ? t8_retention_fetch_for_entity($pdo, 'document', (int) $doc['id'])
                                : null;
                            ?>
                            <tr>
                                <td><?= e($doc['title']) ?></td>
                                <td><?= e($doc['document_type'] ?? '—') ?></td>
                                <td>v<?= e((string) $doc['current_version']) ?></td>
                                <td><span class="t8-badge <?= e(t8_document_status_badge((string) $doc['status'])) ?>"><?= e(ucwords(str_replace('_', ' ', (string) $doc['status']))) ?></span></td>
                                <td><?= $doc['expiration_date'] ? e(format_date($doc['expiration_date'], 'M d, Y')) : '—' ?><?php $expirationState = $doc['expiration_date'] ? t8_document_expiration_state((string) $doc['expiration_date']) : 'active'; if ($doc['expiration_date'] && $expirationState !== 'active'): ?> <span class="t8-badge t8-badge-rejected"><?= $expirationState === 'expired' ? 'Expired' : 'Expiring soon' ?></span><?php endif; ?></td>
                                <td class="t8-row-actions">
                                    <?php t8_document_render_menu($doc, $isAdmin, $statusFilter, $docRetentionRecord); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($rejectedHrRecords as $record): ?>
                            <tr>
                                <td><?= e($record['title']) ?></td>
                                <td><?= e($record['type']) ?></td>
                                <td>—</td>
                                <td><span class="t8-badge t8-badge-rejected">Rejected</span></td>
                                <td>—</td>
                                <td class="t8-row-actions">
                                    <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e($record['view_url']) ?>">
                                        <i class="fa-solid fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php t8_render_document_detail_modal(); ?>

<?php endif; ?>
