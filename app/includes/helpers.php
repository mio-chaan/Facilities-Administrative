<?php
/**
 * helpers.php
 * Small stateless-ish utility functions shared by every module/template.
 */

declare(strict_types=1);

if (!function_exists('e')) {
    /** Shorthand HTML-escape for output in templates. */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('t8_validate_ph_contact_suffix')) {
    /** Accepts the 10-digit suffix after +63, e.g. 9123456789. */
    function t8_validate_ph_contact_suffix(string $value): bool
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        return $digits !== '' && preg_match('/^\d{10}$/', $digits) === 1;
    }
}

if (!function_exists('t8_allowed_upload_mime_types')) {
    /** Strict MIME map for the project's allowed upload extensions. */
    function t8_allowed_upload_mime_types(string $extension): array
    {
        $ext = strtolower(trim($extension, '.'));
        return match ($ext) {
            'pdf' => ['application/pdf'],
            'txt' => ['text/plain', 'text/x-plain', 'text/csv'],
            'png' => ['image/png'],
            'jpg', 'jpeg' => ['image/jpeg'],
            'doc' => ['application/msword', 'application/vnd.ms-word'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'ppt' => ['application/vnd.ms-powerpoint'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            default => [],
        };
    }
}

if (!function_exists('t8_detect_magic_mime')) {
    /** Fallback for hosts where fileinfo is unavailable or returns generic data. */
    function t8_detect_magic_mime(string $path, string $extension): string|false
    {
        $ext = strtolower(trim($extension, '.'));
        if (!is_readable($path)) {
            return false;
        }

        $sample = @file_get_contents($path, false, null, 0, 8192);
        if ($sample === false || $sample === '') {
            return false;
        }

        $pdf = str_starts_with($sample, "%PDF-");
        $png = str_starts_with($sample, "\x89PNG\r\n\x1a\n");
        $jpeg = strncmp($sample, "\xFF\xD8\xFF", 3) === 0;
        $ole = strncmp($sample, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0;
        $zipSignature = strncmp($sample, "PK\x03\x04", 4) === 0;

        if ($zipSignature && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($path) === true) {
                $names = [];
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $names[] = $zip->getNameIndex($index);
                }
                $zip->close();

                $required = match ($ext) {
                    'docx' => ['[Content_Types].xml', '_rels/.rels', 'word/document.xml'],
                    'xlsx' => ['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml'],
                    'pptx' => ['[Content_Types].xml', '_rels/.rels', 'ppt/presentation.xml'],
                    default => [],
                };

                if ($required !== [] && count(array_intersect($required, $names)) === count($required)) {
                    return match ($ext) {
                        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                        default => false,
                    };
                }
            }
        }

        return match ($ext) {
            'pdf' => $pdf ? 'application/pdf' : false,
            'png' => $png ? 'image/png' : false,
            'jpg', 'jpeg' => $jpeg ? 'image/jpeg' : false,
            'doc' => $ole ? 'application/msword' : false,
            'xls' => $ole ? 'application/vnd.ms-excel' : false,
            'ppt' => $ole ? 'application/vnd.ms-powerpoint' : false,
            'txt' => (preg_match('/^[\x09\x0A\x0D\x20-\x7E\x80-\xFF]+$/', $sample) === 1) ? 'text/plain' : false,
            default => false,
        };
    }
}

if (!function_exists('t8_validate_uploaded_file_mime')) {
    function t8_validate_uploaded_file_mime(string $path, string $filename): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed = t8_allowed_upload_mime_types($extension);
        if ($allowed === []) {
            return false;
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                $mime = is_string($mime) ? strtolower(trim(strtok((string) $mime, ';'))) : false;
                if ($mime !== false && in_array($mime, $allowed, true)) {
                    return true;
                }
            }
        }

        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($path);
            $mime = is_string($mime) ? strtolower(trim(strtok((string) $mime, ';'))) : false;
            if ($mime !== false && in_array($mime, $allowed, true)) {
                return true;
            }
        }

        $magicMime = t8_detect_magic_mime($path, $extension);
        return $magicMime !== false && in_array($magicMime, $allowed, true);
    }
}

if (!function_exists('t8_document_expiration_state')) {
    /** Returns the lifecycle bucket for a document expiration date. */
    function t8_document_expiration_state(?string $expirationDate, ?string $referenceDate = null): string
    {
        $normalized = trim((string) ($expirationDate ?? ''));
        if ($normalized === '') {
            return 'active';
        }

        $reference = $referenceDate !== null && trim($referenceDate) !== ''
            ? strtotime((string) $referenceDate)
            : strtotime('today');
        $expiresAt = strtotime($normalized);
        if ($reference === false || $expiresAt === false) {
            return 'active';
        }

        if ($expiresAt < $reference) {
            return 'expired';
        }

        if ($expiresAt <= strtotime('+30 days', $reference)) {
            return 'expiring_soon';
        }

        return 'active';
    }
}

if (!function_exists('t8_document_expiration_filter_sql')) {
    /**
     * Builds one mutually-exclusive, live approved-document lifecycle
     * predicate.  The list view uses these predicates directly, so keeping
     * the common visibility scope here prevents lifecycle filters from
     * accidentally exposing pending or archived uploads.
     */
    function t8_document_expiration_filter_sql(string $alias, string $status, array &$params): string
    {
        return match ($status) {
            'active' => "$alias.deleted_at IS NULL AND $alias.status = 'approved' AND ($alias.expiration_date IS NULL OR DATE($alias.expiration_date) > DATE_ADD(CURDATE(), INTERVAL 30 DAY))",
            'expired' => "$alias.deleted_at IS NULL AND $alias.status = 'approved' AND $alias.expiration_date IS NOT NULL AND DATE($alias.expiration_date) < CURDATE()",
            'expiring_soon' => "$alias.deleted_at IS NULL AND $alias.status = 'approved' AND $alias.expiration_date IS NOT NULL AND DATE($alias.expiration_date) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)",
            default => '1=1',
        };
    }
}

if (!function_exists('t8_document_notify_approval')) {
    /**
     * Sends the uploader a document-version link after a real approval
     * transition. An injectable notifier keeps this contract unit-testable
     * without requiring a database connection. Returns whether a
     * notification was dispatched.
     */
    function t8_document_notify_approval(PDO $pdo, array $document, int $documentId, string $newStatus, ?callable $notifier = null): bool
    {
        $uploadedBy = (int) ($document['uploaded_by'] ?? 0);
        if ($newStatus !== 'approved' || (string) ($document['status'] ?? '') === 'approved' || $documentId <= 0 || $uploadedBy <= 0) {
            return false;
        }

        $message = 'Your document "' . (string) ($document['title'] ?? '') . '" was approved and is now active.';
        $targetUrl = page_url('documents', ['action' => 'versions', 'id' => $documentId]);

        if ($notifier !== null) {
            $notifier($pdo, $uploadedBy, $message, $targetUrl);
            return true;
        }

        if (function_exists('t8_hr_notify')) {
            t8_hr_notify($pdo, $uploadedBy, $message, $targetUrl);
            return true;
        }

        return false;
    }
}

if (!function_exists('t8_format_ph_contact')) {
    /** Formats a 10-digit mobile suffix or a full PH number into a normalized +63 format. */
    function t8_format_ph_contact(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return '';
        }

        if (preg_match('/^63\d{10}$/', $digits) === 1) {
            return '+' . $digits;
        }

        if (preg_match('/^\d{10}$/', $digits) === 1) {
            return '+63' . $digits;
        }

        return '';
    }
}

if (!function_exists('base_url')) {
    function base_url(string $path = ''): string
    {
        return APP_URL . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /** URL to a file served from the public web root, e.g. asset('css/style.css') */
    function asset(string $path): string
    {
        return base_url(ltrim($path, '/'));
    }
}

if (!function_exists('page_url')) {
    /** URL for a front-controller route, e.g. page_url('reservation') */
    function page_url(string $page, array $params = []): string
    {
        $query = array_merge(['page' => $page], $params);
        return base_url('index.php') . '?' . http_build_query($query);
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('t8_session_start')) {
    function t8_session_start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => str_starts_with(strtolower((string) (defined('APP_URL') ? APP_URL : '')), 'https://'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }
}

if (!function_exists('t8_flash_set')) {
    function t8_flash_set(string $type, string $message): void
    {
        $_SESSION['t8_flash'][] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('t8_flash_get')) {
    /** Pulls (and clears) all flash messages queued for this request. */
    function t8_flash_get(): array
    {
        $flashes = $_SESSION['t8_flash'] ?? [];
        unset($_SESSION['t8_flash']);
        return $flashes;
    }
}

if (!function_exists('format_date')) {
    function format_date(?string $datetime, string $format = 'M d, Y'): string
    {
        if (!$datetime) {
            return '—';
        }
        $ts = strtotime($datetime);
        return $ts ? date($format, $ts) : '—';
    }
}

if (!function_exists('current_page')) {
    /** Reads the whitelisted ?page= for the current request. */
    function current_page(): string
    {
        return $_GET['page'] ?? 'dashboard';
    }
}

if (!function_exists('t8_csrf_token')) {
    /** Generates (or reuses) a per-session CSRF token. Call t8_session_start() first. */
    function t8_csrf_token(): string
    {
        if (empty($_SESSION['t8_csrf'])) {
            $_SESSION['t8_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['t8_csrf'];
    }
}

if (!function_exists('t8_csrf_field')) {
    /** Ready-to-echo hidden input for forms: <?= t8_csrf_field() ?> */
    function t8_csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . e(t8_csrf_token()) . '">';
    }
}

if (!function_exists('t8_csrf_verify')) {
    function t8_csrf_verify(?string $submittedToken): bool
    {
        return !empty($_SESSION['t8_csrf'])
            && !empty($submittedToken)
            && hash_equals($_SESSION['t8_csrf'], $submittedToken);
    }
}

if (!function_exists('t8_document_access_matrix')) {
    /**
     * Central authorization matrix for uploaded document records.
     *
     * Supported roles/contexts:
     * - admin: system-wide access
     * - uploader: the user who uploaded the record
     * - owner: the document owner (when the row contains owner_id)
     * - department: the current session department matches the document department
     * - recipient: a certificate/related recipient tied to the current employee
     * - legal_case: a legal officer assigned to the case attached to the document
     */
    function t8_document_access_matrix(?array $document, int $userId, bool $isAdmin, ?PDO $pdo = null, array $context = []): array
    {
        $row = $document ?? [];
        $userId = max(0, $userId);
        $departmentId = isset($context['department_id']) ? (int) $context['department_id'] : null;
        $recipientEmployeeId = isset($context['recipient_employee_id']) ? (int) $context['recipient_employee_id'] : null;

        $admin = $isAdmin;
        $uploader = $document !== null && array_key_exists('uploaded_by', $row) && (int) ($row['uploaded_by'] ?? 0) === $userId;
        $owner = $document !== null && array_key_exists('owner_id', $row) && $row['owner_id'] !== null && (int) ($row['owner_id'] ?? 0) === $userId;
        $department = $document !== null
            && array_key_exists('department_id', $row)
            && $row['department_id'] !== null
            && $departmentId !== null
            && (int) ($row['department_id'] ?? 0) === $departmentId;
        $recipient = $document !== null && (
            (array_key_exists('recipient_employee_id', $row) && $row['recipient_employee_id'] !== null && (int) ($row['recipient_employee_id'] ?? 0) === $userId)
            || (array_key_exists('employee_id', $row) && $row['employee_id'] !== null && (int) ($row['employee_id'] ?? 0) === $userId)
            || ($recipientEmployeeId !== null && $recipientEmployeeId === $userId)
        );

        $legalCaseAccess = false;
        if ($document !== null && $userId > 0 && !$admin && $pdo instanceof PDO) {
            $documentId = array_key_exists('document_id', $row) && $row['document_id'] !== null
                ? (int) $row['document_id']
                : (int) ($row['id'] ?? 0);
            if ($documentId > 0 && function_exists('t8_has_role') && t8_has_role('legal_officer')) {
                $legalStmt = $pdo->prepare(
                    'SELECT lc.id FROM team8_legal_documents ld
                     JOIN team8_legal_cases lc ON lc.id = ld.case_id
                     WHERE ld.document_id = :document_id AND lc.assigned_to = :user_id AND lc.deleted_at IS NULL LIMIT 1'
                );
                $legalStmt->execute(['document_id' => $documentId, 'user_id' => $userId]);
                $legalCaseAccess = $legalStmt->fetchColumn() !== false;
            }
        }

        $view = $admin || $uploader || $owner || $department || $recipient || $legalCaseAccess;

        return [
            'admin' => $admin,
            'uploader' => $uploader,
            'owner' => $owner,
            'department' => $department,
            'recipient' => $recipient,
            'legal_case' => $legalCaseAccess,
            'view' => $view,
            'download' => $view,
            'edit' => $admin || $uploader,
            'print' => $view,
            'approve' => $admin,
        ];
    }
}

if (!function_exists('t8_document_can_access')) {
    function t8_document_can_access(?array $document, int $userId, bool $isAdmin, ?PDO $pdo = null, string $action = 'view', array $context = []): bool
    {
        $matrix = t8_document_access_matrix($document, $userId, $isAdmin, $pdo, $context);

        return match ($action) {
            'download' => $matrix['download'],
            'edit' => $matrix['edit'],
            'print' => $matrix['print'],
            'approve' => $matrix['approve'],
            default => $matrix['view'],
        };
    }
}
