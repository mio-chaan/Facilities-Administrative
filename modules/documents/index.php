<?php
/**
 * modules/documents/index.php
 * Document Management - upload, version, and archive documents.
 *
 */

declare(strict_types=1);

// AI Document Summarizer support - defensive require, safe even if
// already loaded centrally (require_once is idempotent). Adjust path
// if your app/ folder isn't two levels up from modules/documents/.
$aiHelperPath = __DIR__ . '/../../app/includes/ai_helper.php';
if (is_file($aiHelperPath)) {
    require_once $aiHelperPath;
}

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
    'Others'           => ['General Document', 'Reference Material', 'Ad Hoc Record'],
];
$documentTypeOptions = [];
foreach ($categories as $category) {
    if (isset($categoryTypeTemplates[$category['name']])) {
        $documentTypeOptions[(string) $category['id']] = $categoryTypeTemplates[$category['name']];
    }
}

switch ($action) {
    case 'create':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $categoryId = (string) ($_POST['category_id'] ?? '') !== '' ? (int) $_POST['category_id'] : null;
            $documentType = trim((string) ($_POST['document_type'] ?? ''));

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
                $uploadError = t8_document_validate_upload($_FILES['file'] ?? []);
                if ($uploadError !== '') {
                    $errors[] = $uploadError;
                }

                if (!$errors) {
                    $stored = t8_document_store_upload($_FILES['file'], $title, 1);

                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare(
                            'INSERT INTO team8_documents (category_id, document_type, uploaded_by, title, file_path, current_version)
                             VALUES (:category_id, :document_type, :uploaded_by, :title, :file_path, 1)'
                        );
                        $stmt->execute([
                            'category_id'   => $categoryId,
                            'document_type' => $documentType !== '' ? $documentType : null,
                            'uploaded_by'   => $currentUserId,
                            'title'         => $title,
                            'file_path'     => $stored['file_path'],
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
            'SELECT v.*, d.title FROM team8_document_versions v
             JOIN team8_documents d ON d.id = v.document_id
             WHERE v.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $summaryVersionId]);
        $summaryVersion = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$summaryVersion) {
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
            t8_flash_set('danger', 'This file type (.' . strtoupper($summaryExt) . ') isn\'t supported for AI summarization yet. Currently supported: .txt, .docx.');
            redirect(page_url('documents', ['action' => 'versions', 'id' => $summaryDocumentId]));
        }

        // Cap input length to stay within a reasonable token budget.
        $extractedText = mb_substr($extractedText, 0, 12000);

        try {
            $aiSummary = t8_openai_chat([
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
    if (!$document) {
        t8_flash_set('danger', 'Document not found.');
        redirect(page_url('documents'));
    }
    $versions = t8_document_all_versions($pdo, $documentId);

    // One-time AI summary, if the person just clicked "AI Summarize".
    $aiSummaryText = null;
    $aiSummaryVersionId = (int) ($_GET['summary_version'] ?? 0);
    if ($aiSummaryVersionId && isset($_SESSION['t8_ai_summary_' . $aiSummaryVersionId])) {
        $aiSummaryText = $_SESSION['t8_ai_summary_' . $aiSummaryVersionId];
        unset($_SESSION['t8_ai_summary_' . $aiSummaryVersionId]);
    }
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
        <form method="post" action="<?= e(page_url('documents', ['action' => 'create'])) ?>" enctype="multipart/form-data" novalidate>
            <?= t8_csrf_field() ?>

            <div class="t8-field">
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
            </div>

            <div class="t8-field">
                <label class="t8-label" for="document_type">Document Type</label>
                <select class="t8-select" id="document_type" name="document_type" required disabled>
                    <option value="" disabled selected>Choose a document type</option>
                </select>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="file">File</label>
                <input class="t8-input" type="file" id="file" name="file" required>
                <span class="t8-help-text">
                    Max <?= e((string) UPLOAD_MAX_SIZE_MB) ?>MB. Allowed: <?= e(implode(', ', T8_DOC_ALLOWED_EXT)) ?>
                </span>
                <?php t8_render_camera_capture(); ?>
            </div>

            <button class="t8-btn t8-btn-accent" type="submit">
                <i class="fa-solid fa-upload"></i> Upload
            </button>
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents')) ?>">Cancel</a>
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
                            <td style="display:flex; gap:8px; flex-wrap:wrap;">
                                <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('documents', ['action' => 'download', 'version_id' => $v['id']])) ?>">
                                    <i class="fa-solid fa-download"></i> Download
                                </a>
                                <form method="post" action="<?= e(page_url('documents', ['action' => 'summarize'])) ?>" style="display:inline;">
                                    <?= t8_csrf_field() ?>
                                    <input type="hidden" name="version_id" value="<?= e((string) $v['id']) ?>">
                                    <button class="t8-btn t8-btn-outline t8-btn-sm" type="submit">
                                        <i class="fa-solid fa-robot"></i> AI Summarize
                                    </button>
                                </form>
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
                            <th>Document Type</th>
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
                                <td><?= e($doc['document_type'] ?? '—') ?></td>
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