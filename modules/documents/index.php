<?php
/**
 * modules/documents/index.php
 * Document Management - upload, version, and archive documents.
 *
 * Matches the existing schema:
 *   team8_documents (id, category_id, uploaded_by, title, file_path,
 *     current_version, created_at, updated_at, deleted_at)
 *   team8_document_versions (id, document_id, version_no, file_path,
 *     file_size, checksum, uploaded_at)
 *   team8_document_categories (id, name, created_at)
 *
 * "Archived" = deleted_at IS NOT NULL (soft delete, nothing removed
 * from disk or DB). team8_documents.file_path / current_version always
 * mirror the newest row in team8_document_versions for that document,
 * so anything else in the app that reads documents.file_path directly
 * keeps working without needing to know about versioning.
 *
 * Any logged-in user (staff or admin) can upload, version, archive,
 * or restore a document.
 */

declare(strict_types=1);

$pageTitle = 'Document Management';
$currentUserId = t8_current_user_id();
$isAdmin = t8_has_role('admin');
$action = $_GET['action'] ?? 'list';
$errors = [];

const T8_DOC_ALLOWED_EXT = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'png', 'jpg', 'jpeg'];

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
    $stmt = $pdo->prepare(
        'SELECT d.*, c.name AS category_name, u.full_name AS uploaded_by_name
         FROM team8_documents d
         LEFT JOIN team8_document_categories c ON c.id = d.category_id
         JOIN users u ON u.id = d.uploaded_by
         WHERE d.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
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

$categories = $pdo->query('SELECT id, name FROM team8_document_categories ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

switch ($action) {
    case 'create':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $categoryId = (string) ($_POST['category_id'] ?? '') !== '' ? (int) $_POST['category_id'] : null;

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                if ($title === '') {
                    $errors[] = 'Document title is required.';
                }
                $uploadError = t8_document_validate_upload($_FILES['file'] ?? []);
                if ($uploadError !== '') {
                    $errors[] = $uploadError;
                }

                if (!$errors) {
                    $stored = t8_document_store_upload($_FILES['file'], $title, 1);

                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare(
                            'INSERT INTO team8_documents (category_id, uploaded_by, title, file_path, current_version)
                             VALUES (:category_id, :uploaded_by, :title, :file_path, 1)'
                        );
                        $stmt->execute([
                            'category_id' => $categoryId,
                            'uploaded_by' => $currentUserId,
                            'title'       => $title,
                            'file_path'   => $stored['file_path'],
                        ]);
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
                    t8_flash_set('success', 'Document uploaded.');
                    redirect(page_url('documents'));
                }
            }
        }
        break;

    case 'upload_version':
        $documentId = (int) ($_GET['id'] ?? 0);
        $document = $documentId ? t8_document_fetch($pdo, $documentId) : null;
        if (!$document) {
            t8_flash_set('danger', 'Document not found.');
            redirect(page_url('documents'));
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
                    $pdo->prepare(
                        'UPDATE team8_documents SET file_path = :file_path, current_version = :version_no, updated_at = NOW() WHERE id = :id'
                    )->execute([
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

    case 'archive':
    case 'restore':
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
            'SELECT v.*, d.title FROM team8_document_versions v
             JOIN team8_documents d ON d.id = v.document_id
             WHERE v.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $versionId]);
        $version = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$version) {
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
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
}

$showCreateForm = $action === 'create';
$showUploadVersionForm = $action === 'upload_version' && !empty($document);
$showVersions = $action === 'versions';

if ($showVersions) {
    $documentId = (int) ($_GET['id'] ?? 0);
    $document = $documentId ? t8_document_fetch($pdo, $documentId) : null;
    if (!$document) {
        t8_flash_set('danger', 'Document not found.');
        redirect(page_url('documents'));
    }
    $versions = t8_document_all_versions($pdo, $documentId);
}

$showList = !$showCreateForm && !$showUploadVersionForm && !$showVersions;

if ($showList) {
    $statusFilter = ($_GET['status'] ?? 'active') === 'archived' ? 'archived' : 'active';
    $whereClause = $statusFilter === 'archived' ? 'd.deleted_at IS NOT NULL' : 'd.deleted_at IS NULL';
    $documents = $pdo->query(
        "SELECT d.*, c.name AS category_name, u.full_name AS uploaded_by_name
         FROM team8_documents d
         LEFT JOIN team8_document_categories c ON c.id = d.category_id
         JOIN users u ON u.id = d.uploaded_by
         WHERE $whereClause
         ORDER BY d.updated_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
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
        <form method="post" action="<?= e(page_url('documents', ['action' => 'create'])) ?>" enctype="multipart/form-data" novalidate>
            <?= t8_csrf_field() ?>

            <div class="t8-field">
                <label class="t8-label" for="title">Title</label>
                <input class="t8-input" type="text" id="title" name="title"
                       value="<?= e((string) ($_POST['title'] ?? '')) ?>" required>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="category_id">Category</label>
                <select class="t8-select" id="category_id" name="category_id">
                    <option value="">Uncategorized</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e((string) $cat['id']) ?>"><?= e($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="file">File</label>
                <input class="t8-input" type="file" id="file" name="file" required>
                <span class="t8-help-text">
                    Max <?= e((string) UPLOAD_MAX_SIZE_MB) ?>MB. Allowed: <?= e(implode(', ', T8_DOC_ALLOWED_EXT)) ?>
                </span>
            </div>

            <button class="t8-btn t8-btn-accent" type="submit">
                <i class="fa-solid fa-upload"></i> Upload
            </button>
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents')) ?>">Cancel</a>
        </form>
    </div>

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
            </div>

            <button class="t8-btn t8-btn-accent" type="submit">
                <i class="fa-solid fa-upload"></i> Upload Version
            </button>
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents', ['action' => 'versions', 'id' => $document['id']])) ?>">Cancel</a>
        </form>
    </div>

<?php elseif ($showVersions): ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents')) ?>">
            <i class="fa-solid fa-arrow-left"></i> Back to Documents
        </a>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= e($document['title']) ?> — Version History</h2>
            <a class="t8-btn t8-btn-accent" href="<?= e(page_url('documents', ['action' => 'upload_version', 'id' => $document['id']])) ?>">
                <i class="fa-solid fa-upload"></i> Upload New Version
            </a>
        </div>
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
                            <td>
                                <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('documents', ['action' => 'download', 'version_id' => $v['id']])) ?>">
                                    <i class="fa-solid fa-download"></i> Download
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php else: ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <a class="t8-btn t8-btn-accent" href="<?= e(page_url('documents', ['action' => 'create'])) ?>">
            <i class="fa-solid fa-upload"></i> Upload New Document
        </a>
        <?php if ($statusFilter === 'active'): ?>
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents', ['status' => 'archived'])) ?>">
                <i class="fa-solid fa-box-archive"></i> View Archived
            </a>
        <?php else: ?>
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents')) ?>">
                <i class="fa-solid fa-list"></i> View Active
            </a>
        <?php endif; ?>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= $statusFilter === 'archived' ? 'Archived Documents' : 'Active Documents' ?></h2>
        </div>
        <?php if ($documents === []): ?>
            <div class="t8-empty">
                <?= $statusFilter === 'archived' ? 'No archived documents.' : 'No documents uploaded yet.' ?>
            </div>
        <?php else: ?>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Category</th>
                            <th>Current Version</th>
                            <th>Last Updated</th>
                            <th>Uploaded By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documents as $doc): ?>
                            <tr>
                                <td><?= e($doc['title']) ?></td>
                                <td><?= e($doc['category_name'] ?? '—') ?></td>
                                <td>v<?= e((string) $doc['current_version']) ?></td>
                                <td><?= e(format_date($doc['updated_at'], 'M d, Y g:i A')) ?></td>
                                <td><?= e($doc['uploaded_by_name']) ?></td>
                                <td style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('documents', ['action' => 'versions', 'id' => $doc['id']])) ?>">
                                        <i class="fa-solid fa-clock-rotate-left"></i> Versions
                                    </a>
                                    <?php if ($statusFilter === 'active'): ?>
                                        <form method="post" action="<?= e(page_url('documents', ['action' => 'archive'])) ?>"
                                              onsubmit="return confirm('Archive this document?');">
                                            <?= t8_csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $doc['id']) ?>">
                                            <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit">
                                                <i class="fa-solid fa-box-archive"></i> Archive
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="<?= e(page_url('documents', ['action' => 'restore'])) ?>">
                                            <?= t8_csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $doc['id']) ?>">
                                            <button class="t8-btn t8-btn-success t8-btn-sm" type="submit">
                                                <i class="fa-solid fa-rotate-left"></i> Restore
                                            </button>
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