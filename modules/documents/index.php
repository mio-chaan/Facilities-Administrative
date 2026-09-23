<?php
/**
 * modules/documents/index.php
 * Document Management — traditional file uploads/versioning/archiving
 * (unchanged from the original module) PLUS the HR Document
 * Automation extension: a dashboard landing page, a template picker,
 * and generated documents (Incident Report, Notice To Explain,
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
            <?php if ($isAdmin && $statusFilter === 'active' && $doc['status'] === 'pending'): ?>
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

/** Admins may access all documents; staff may access only their own uploads. */
function t8_document_is_authorized(?array $document, int $userId, bool $isAdmin): bool
{
    return $document !== null && ($isAdmin || (int) $document['uploaded_by'] === $userId);
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
    
    // MIME type validation (optional if fileinfo is not available)
    if (extension_loaded('fileinfo')) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowedMimes = [
            'pdf' => ['application/pdf'], 'txt' => ['text/plain'],
            'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
            'doc' => ['application/msword', 'application/octet-stream'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'ppt' => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        ];
        if ($mime === false || !in_array($mime, $allowedMimes[$ext] ?? [], true)) {
            return 'The file contents do not match the selected file type.';
        }
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
$owners = $isAdmin ? ($pdo->query('SELECT id, full_name FROM users ORDER BY full_name')->fetchAll(PDO::FETCH_ASSOC) ?? []) : [];

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
                if ($expirationDate !== '' && strtotime($expirationDate) === false) {
                    $errors[] = 'Expiration date must be a valid date.';
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

    case 'upload_version':
        $documentId = (int) ($_GET['id'] ?? 0);
        $document = $documentId ? t8_document_fetch($pdo, $documentId) : null;
        if (!t8_document_is_authorized($document, $currentUserId, $isAdmin)) {
            t8_flash_set('danger', 'Document not found.');
            redirect(page_url('documents'));
        }
        if (!$isAdmin && $document['status'] !== 'returned_for_revision') {
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
        if (!$documentHasStatus || !in_array($status, ['approved', 'returned_for_revision'], true) || !t8_document_fetch($pdo, $id)) {
            t8_flash_set('danger', 'The document review request is invalid.');
        } elseif ($status === 'returned_for_revision' && $reviewReason === '') {
            t8_flash_set('danger', 'A reason is required when returning a document.');
        } else {
            $document = t8_document_fetch($pdo, $id);
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

    case 'download':
        $versionId = (int) ($_GET['version_id'] ?? 0);
        $stmt = $pdo->prepare(
            'SELECT v.*, d.title, d.uploaded_by FROM team8_document_versions v
             JOIN team8_documents d ON d.id = v.document_id
             WHERE v.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $versionId]);
        $version = $stmt->fetch(PDO::FETCH_ASSOC);
        $legalAccess = false;
        if ($version && !$isAdmin && t8_has_role('legal_officer')) {
            $legalStmt = $pdo->prepare(
                'SELECT lc.id FROM team8_legal_documents ld
                 JOIN team8_legal_cases lc ON lc.id = ld.case_id
                 WHERE ld.document_id = :document_id AND lc.assigned_to = :user_id AND lc.deleted_at IS NULL LIMIT 1'
            );
            $legalStmt->execute(['document_id' => $version['document_id'], 'user_id' => $currentUserId]);
            $legalCaseId = $legalStmt->fetchColumn();
            $legalAccess = $legalCaseId !== false;
        }
        if (!$version || (!$isAdmin && (int) $version['uploaded_by'] !== $currentUserId && !$legalAccess)) {
            http_response_code(404);
            echo 'File not found.';
            exit;
        }
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
        $summaryVersion = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$summaryVersion || (!$isAdmin && (int) $summaryVersion['uploaded_by'] !== $currentUserId)) {
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
$showUploadVersionForm = $action === 'upload_version' && !empty($document);
$showVersions = $action === 'versions';

if ($showVersions) {
    $documentId = (int) ($_GET['id'] ?? 0);
    $document = $documentId ? t8_document_fetch($pdo, $documentId) : null;
    if (!t8_document_is_authorized($document, $currentUserId, $isAdmin)) {
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

if ($showList) {
    $hasMetadata = t8_document_has_column($pdo, 'department_id') && t8_document_has_column($pdo, 'owner_id');
    $requestedStatus = (string) ($_GET['status'] ?? 'all');
    $statusFilter = in_array($requestedStatus, ['all', 'active', 'rejected', 'archived'], true) ? $requestedStatus : 'all';
    $reviewFilter = (string) ($_GET['review_status'] ?? '');
    if ($reviewFilter === 'rejected') {
        $statusFilter = 'rejected';
    }
    $allDocumentsView = $statusFilter !== 'archived';
    $whereClause = match ($statusFilter) {
        'archived' => 'd.deleted_at IS NOT NULL',
        'active' => "d.deleted_at IS NULL AND d.status = 'approved'",
        'rejected' => "d.deleted_at IS NULL AND d.status = 'returned_for_revision'",
        default => "d.deleted_at IS NULL AND d.status IN ('draft', 'pending', 'approved')",
    };
    $scopeSql = $isAdmin ? '' : ' AND d.uploaded_by = :user_id';
    $search = trim((string) ($_GET['q'] ?? ''));
    $categoryFilter = (int) ($_GET['category_id'] ?? 0);
    $filterSql = '';
    $filterParams = $isAdmin ? [] : ['user_id' => $currentUserId];
    if ($search !== '') {
        $filterSql .= ' AND (d.title LIKE :search OR d.document_type LIKE :search OR c.name LIKE :search OR u.full_name LIKE :search'
            . ($hasMetadata ? ' OR dep.name LIKE :search OR owner.full_name LIKE :search' : '') . ')';
        $filterParams['search'] = '%' . $search . '%';
    }
    if ($categoryFilter > 0) {
        $filterSql .= ' AND d.category_id = :category_id';
        $filterParams['category_id'] = $categoryFilter;
    }
    if (in_array($reviewFilter, ['pending', 'approved', 'returned_for_revision'], true)) {
        $filterSql .= ' AND d.status = :review_status';
        $filterParams['review_status'] = $reviewFilter;
    } elseif ($reviewFilter === 'rejected') {
        $filterSql .= " AND d.status = 'returned_for_revision'";
    }
    $documentsStmt = $pdo->prepare(
        "SELECT d.*, c.name AS category_name, u.full_name AS uploaded_by_name" . ($hasMetadata ? ", dep.name AS department_name, owner.full_name AS owner_name" : '') . "
         FROM team8_documents d
         LEFT JOIN team8_document_categories c ON c.id = d.category_id
         JOIN users u ON u.id = d.uploaded_by
         " . ($hasMetadata ? 'LEFT JOIN departments dep ON dep.id = d.department_id LEFT JOIN users owner ON owner.id = d.owner_id' : '') . "
         WHERE $whereClause$scopeSql$filterSql
         ORDER BY d.updated_at DESC"
    );
    $documentsStmt->execute($filterParams);
    $documents = $documentsStmt->fetchAll(PDO::FETCH_ASSOC);
    $rejectedHrRecords = $statusFilter === 'rejected'
        ? t8_document_rejected_hr_records($pdo, $isAdmin)
        : [];
    $documentCategories = [];
    foreach ($documents as $document) {
        $documentCategories[(int) $document['id']] = (string) ($document['category_name'] ?? 'Uploaded Document');
    }
    $searchNeedle = strtolower($search);
    $allActiveDocuments = $allDocumentsView && $statusFilter !== 'rejected'
        ? t8_hr_recent_documents($pdo, $isAdmin, $currentUserId, 5000)
        : [];
    if ($allDocumentsView) {
        $allActiveDocuments = array_values(array_filter(
            $allActiveDocuments,
            static function (array $document) use ($statusFilter, $reviewFilter, $searchNeedle, $documentCategories): bool {
                $status = (string) $document['status'];
                if ((string) ($document['doc_type'] ?? '') === 'upload'
                    && !isset($documentCategories[(int) $document['id']])) {
                    return false;
                }
                if ($statusFilter === 'active' && $status !== 'approved') {
                    return false;
                }
                if ($reviewFilter !== '' && $status !== $reviewFilter) {
                    return false;
                }
                if ($searchNeedle !== '') {
                    $searchText = strtolower(implode(' ', [
                        (string) ($document['label'] ?? ''),
                        t8_hr_doc_type_label((string) ($document['doc_type'] ?? '')),
                        (string) ($document['doc_type'] ?? ''),
                        (string) ($document['doc_type'] ?? '') === 'upload'
                            ? ($documentCategories[(int) $document['id']] ?? '')
                            : 'Human Resources',
                    ]));
                    if (!str_contains($searchText, $searchNeedle)) {
                        return false;
                    }
                }
                return true;
            }
        ));
    } else {
        foreach ($documents as $document) {
            $allActiveDocuments[] = [
                'id' => (int) $document['id'],
                'label' => (string) $document['title'],
                'doc_type' => 'upload',
                'status' => 'rejected',
                'ts' => (string) $document['updated_at'],
                'category' => $documentCategories[(int) $document['id']] ?? 'Uploaded Document',
                'url_action' => 'versions',
            ];
        }
        foreach ($rejectedHrRecords as $record) {
            $allActiveDocuments[] = [
                'id' => (int) $record['id'],
                'label' => (string) $record['title'],
                'doc_type' => (string) $record['type'],
                'status' => 'rejected',
                'ts' => (string) $record['date'],
                'category' => 'Human Resources - ' . (string) $record['type'],
                'url_action' => (string) $record['url_action'],
            ];
        }
        if ($searchNeedle !== '') {
            $allActiveDocuments = array_values(array_filter(
                $allActiveDocuments,
                static fn (array $document): bool => str_contains(strtolower(implode(' ', [
                    (string) $document['label'],
                    t8_hr_doc_type_label((string) $document['doc_type']),
                    (string) ($document['category'] ?? ''),
                ])), $searchNeedle)
            ));
        }
        usort($allActiveDocuments, static fn (array $a, array $b): int => strtotime((string) $b['ts']) <=> strtotime((string) $a['ts']));
    }
    $archivedHrRecords = $statusFilter === 'archived'
        ? t8_document_archived_hr_records($pdo, $isAdmin)
        : [];
}

if (!$showCreateForm && !$showUploadVersionForm && !$showVersions && !$showList) {
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
            <?php if ($isAdmin || $document['status'] === 'returned_for_revision'): ?>
                <a class="t8-btn t8-btn-accent" href="<?= e(page_url('documents', ['action' => 'upload_version', 'id' => $document['id']])) ?>">
                    <i class="fa-solid fa-upload"></i> Upload New Version
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
        <?php if (!$isAdmin): ?><p class="t8-help-text" style="margin-top:0;">My Documents: <a href="<?= e(page_url('documents', ['action' => 'browse', 'status' => 'all', 'review_status' => 'pending'])) ?>">Pending</a> · <a href="<?= e(page_url('documents', ['action' => 'browse', 'status' => 'active'])) ?>">Approved</a> · <a href="<?= e(page_url('documents', ['action' => 'browse', 'status' => 'rejected'])) ?>">Rejected</a></p><?php endif; ?>
        <div class="t8-documents-filters">
            <label>Search<input class="t8-input" type="search" name="q" value="<?= e($search) ?>" placeholder="Title, category, department, owner"></label>
            <label>Category<select class="t8-select" name="category_id"><option value="">All categories</option><?php foreach ($categories as $cat): ?><option value="<?= e((string) $cat['id']) ?>" <?= $categoryFilter === (int) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option><?php endforeach; ?></select></label>
            <label>Status<select class="t8-select" name="status"><option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option><option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option><option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option><option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>Archived</option></select></label>
        </div>
    </form>

    <?php if ($allDocumentsView): ?>
        <div id="t8DocumentsResults" class="t8-card" style="margin-bottom:var(--t8-space-4);">
            <div class="t8-card-header">
                <h2 class="t8-card-title"><?= $statusFilter === 'rejected' ? 'Rejected Documents' : ($statusFilter === 'active' ? 'Active Documents' : 'All Active Documents') ?></h2>
            </div>
            <?php if ($allActiveDocuments === []): ?>
                <div class="t8-empty">No <?= e($statusFilter === 'rejected' ? 'rejected' : ($statusFilter === 'active' ? 'active' : 'live')) ?> documents found.</div>
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
                                    <td><?= e((string) ($document['category'] ?? ((string) $document['doc_type'] === 'upload' ? ($documentCategories[(int) $document['id']] ?? 'Uploaded Document') : 'Human Resources - ' . t8_hr_doc_type_label((string) $document['doc_type'])))) ?></td>
                                    <td><?= e(t8_hr_doc_type_label((string) $document['doc_type'])) ?></td>
                                    <td><span class="t8-badge <?= e(t8_hr_status_badge((string) $document['status'])) ?>"><?= e(ucfirst((string) $document['status'])) ?></span></td>
                                    <td><?= e(format_date((string) $document['ts'], 'M d, Y')) ?></td>
                                    <td class="t8-row-actions">
                                        <?php if ($document['doc_type'] === 'upload'):
                                            $feedDocument = t8_document_fetch($pdo, (int) $document['id']);
                                            $feedRetentionRecord = $feedDocument !== null && function_exists('t8_retention_fetch_for_entity')
                                                ? t8_retention_fetch_for_entity($pdo, 'document', (int) $document['id'])
                                                : null;
                                            if ($feedDocument !== null):
                                                t8_document_render_menu($feedDocument, $isAdmin, 'active', $feedRetentionRecord);
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
                                <td><?= $doc['expiration_date'] ? e(format_date($doc['expiration_date'], 'M d, Y')) : '—' ?><?php if ($doc['expiration_date'] && strtotime((string) $doc['expiration_date']) <= strtotime('+30 days')): ?> <span class="t8-badge t8-badge-rejected"><?= strtotime((string) $doc['expiration_date']) < strtotime('today') ? 'Expired' : 'Expiring soon' ?></span><?php endif; ?></td>
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
