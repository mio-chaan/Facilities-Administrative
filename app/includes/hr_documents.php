<?php
/**
 * app/includes/hr_documents.php
 * Shared helpers for the HR Document Automation extension to
 * Document Management (Incident Reports, Notice To Explain,
 * Explanation Letters, Memorandums/Warning Letters, Certificates).
 *
 * SECURITY CONTRACT (read before touching any hr/*.php controller):
 *   Every "who is this document about / who filed it" field
 *   (employee_id, prepared_by, department_id) is resolved ONLY from
 *   $_SESSION via t8_hr_current_employee() / t8_current_user_id().
 *   $_POST is NEVER trusted for identity - not even to prefill a
 *   hidden field - because a tampered request could otherwise file a
 *   report as a different employee or forge who "prepared" a
 *   document. Business data that legitimately comes from an admin's
 *   choice (e.g. which employee a certificate is issued to) is fine
 *   to accept from POST, since that's not an identity claim about the
 *   requester themselves - but it is always validated against the
 *   `users` table before use.
 *
 * Requires $pdo (see db_connect.php) and auth_check.php's
 * t8_current_user_id()/t8_current_role() to already be loaded.
 */

declare(strict_types=1);

const T8_HR_STATUSES = ['draft', 'pending', 'approved', 'rejected', 'archived'];

const T8_INCIDENT_TYPES = [
    'Tardiness', 'Absence', 'Misconduct', 'Policy Violation',
    'Property Damage', 'Safety Violation', 'Insubordination', 'Other',
];

const T8_CERTIFICATE_TYPES = [
    'employment'  => 'Certificate of Employment',
    'recognition' => 'Certificate of Recognition',
    'attendance'  => 'Certificate of Attendance',
];

// NOTE: a T8_HR_TEMPLATES constant (label/icon/action per HR doc type)
// previously lived here but was never referenced anywhere — both
// hr/generate.php and hr/dashboard.php hardcode their own template
// tile lists instead of looping over it. Removed as dead code; if a
// data-driven template list is wanted later, reintroduce it and wire
// BOTH of those files to loop over it instead of hardcoding.

if (!function_exists('t8_hr_current_employee')) {
    /**
     * Resolves the CURRENT SESSION USER's identity fields fresh from
     * the database (keyed only off the trusted session user_id — not
     * off any session-cached name/department, and never off $_POST),
     * so the read-only identity block on every HR form always
     * reflects the latest users/departments data.
     *
     * "Position" has no dedicated column in the shared schema yet, so
     * it is derived from the user's system role as a stand-in. This
     * is a known simplification, documented here rather than hidden.
     */
    function t8_hr_current_employee(PDO $pdo): array
    {
        $userId = t8_current_user_id();
        $stmt = $pdo->prepare(
            'SELECT u.id, u.full_name, u.department_id, d.name AS department_name
             FROM users u
             LEFT JOIN departments d ON d.id = u.department_id
             WHERE u.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $role = t8_current_role();

        return [
            'employee_id'     => (int) ($row['id'] ?? $userId),
            'full_name'       => $row['full_name'] ?? t8_current_user_name(),
            'department_id'   => isset($row['department_id']) ? (int) $row['department_id'] : null,
            'department_name' => $row['department_name'] ?? '—',
            // Stand-in until a dedicated position/title column exists.
            'position'        => $role !== null ? ucwords(str_replace('_', ' ', $role)) : '—',
        ];
    }
}

if (!function_exists('t8_hr_generate_doc_number')) {
    /** Sequential, human-readable document number, e.g. "IR-2026-000042". */
    function t8_hr_generate_doc_number(PDO $pdo, string $prefix, string $table): string
    {
        $year = date('Y');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE YEAR(created_at) = :year");
        $stmt->execute(['year' => $year]);
        $sequence = (int) $stmt->fetchColumn() + 1;
        return sprintf('%s-%s-%06d', $prefix, $year, $sequence);
    }
}

if (!function_exists('t8_hr_status_badge')) {
    function t8_hr_status_badge(string $status): string
    {
        $map = [
            'draft'    => 't8-badge-pending',
            'pending'  => 't8-badge-pending',
            'approved' => 't8-badge-approved',
            'rejected' => 't8-badge-rejected',
            'archived' => 't8-badge-archived',
        ];
        return $map[$status] ?? 't8-badge-pending';
    }
}

if (!function_exists('t8_hr_notify')) {
    /** Reuses the existing shared `notifications` table/bell widget. */
    function t8_hr_notify(PDO $pdo, int $userId, string $message): void
    {
        try {
            $pdo->prepare('INSERT INTO notifications (user_id, message, status) VALUES (:user_id, :message, "unread")')
                ->execute(['user_id' => $userId, 'message' => $message]);
        } catch (PDOException $e) {
            // A failed notification must never break the request that triggered it.
            error_log('HR document notification failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('t8_hr_notify_admins')) {
    /** Notifies every user holding the 'admin' role. */
    function t8_hr_notify_admins(PDO $pdo, string $message): void
    {
        try {
            $admins = $pdo->query(
                "SELECT ur.user_id FROM user_roles ur
                 JOIN roles r ON r.id = ur.role_id
                 WHERE r.role_name = 'admin'"
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($admins as $adminId) {
                t8_hr_notify($pdo, (int) $adminId, $message);
            }
        } catch (PDOException $e) {
            error_log('HR admin notification failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('t8_hr_save_version')) {
    /**
     * Snapshots $data (the row as it existed BEFORE this edit) into
     * the shared version-history table. Call this only when the
     * document being edited is currently 'approved' — drafts/pending
     * rows are simply updated in place, per the spec ("editing an
     * APPROVED document must not overwrite the original").
     */
    function t8_hr_save_version(PDO $pdo, string $docType, int $docId, array $data, int $userId): int
    {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(MAX(version_no), 0) + 1 FROM team8_hr_document_versions WHERE doc_type = :t AND doc_id = :id'
        );
        $stmt->execute(['t' => $docType, 'id' => $docId]);
        $nextVersion = (int) $stmt->fetchColumn();

        $pdo->prepare(
            'INSERT INTO team8_hr_document_versions (doc_type, doc_id, version_no, data_json, created_by)
             VALUES (:t, :id, :v, :d, :u)'
        )->execute([
            't' => $docType, 'id' => $docId, 'v' => $nextVersion,
            'd' => json_encode($data), 'u' => $userId,
        ]);

        return $nextVersion;
    }
}

if (!function_exists('t8_hr_versions')) {
    /** All prior snapshots for a document, newest first. */
    function t8_hr_versions(PDO $pdo, string $docType, int $docId): array
    {
        $stmt = $pdo->prepare(
            'SELECT v.*, u.full_name AS created_by_name
             FROM team8_hr_document_versions v
             JOIN users u ON u.id = v.created_by
             WHERE v.doc_type = :t AND v.doc_id = :id
             ORDER BY v.version_no DESC'
        );
        $stmt->execute(['t' => $docType, 'id' => $docId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('t8_hr_store_attachment')) {
    /**
     * Optional attachment upload shared by Incident Reports and
     * Explanation Letters. Returns a path RELATIVE to UPLOAD_DIR
     * (e.g. "hr_documents/xxx.pdf"), or null if no file was chosen.
     * Throws RuntimeException on a real upload error so the caller
     * can surface it as a normal validation message.
     */
    function t8_hr_store_attachment(array $file, string $prefix): ?string
    {
        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Attachment upload failed. Please try again.');
        }

        $maxBytes = UPLOAD_MAX_SIZE_MB * 1024 * 1024;
        if ($file['size'] > $maxBytes) {
            throw new RuntimeException('Attachment is too large. Maximum size is ' . UPLOAD_MAX_SIZE_MB . 'MB.');
        }

        $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            throw new RuntimeException('Attachment type not allowed. Allowed: ' . implode(', ', $allowed) . '.');
        }

        $dir = UPLOAD_DIR . '/hr_documents';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $storedName = $prefix . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destination = $dir . '/' . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Could not save the attachment.');
        }

        return 'hr_documents/' . $storedName;
    }
}

// ---------------------------------------------------------------
// Fetch helpers — each resolves display names via JOIN, never by
// storing a duplicate copy of the name/department on the row itself.
// ---------------------------------------------------------------

if (!function_exists('t8_hr_incident_report_fetch')) {
    function t8_hr_incident_report_fetch(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT ir.*, e.full_name AS employee_name, p.full_name AS prepared_by_name, d.name AS department_name
             FROM team8_incident_reports ir
             JOIN users e ON e.id = ir.employee_id
             JOIN users p ON p.id = ir.prepared_by
             LEFT JOIN departments d ON d.id = ir.department_id
             WHERE ir.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('t8_hr_nte_for_incident')) {
    /** The single NTE generated from a given incident report, if any. */
    function t8_hr_nte_for_incident(PDO $pdo, int $incidentReportId): ?array
    {
        $stmt = $pdo->prepare('SELECT id, document_number, status FROM team8_notice_to_explain WHERE incident_report_id = :id LIMIT 1');
        $stmt->execute(['id' => $incidentReportId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('t8_hr_nte_fetch')) {
    function t8_hr_nte_fetch(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT n.*, e.full_name AS employee_name, p.full_name AS prepared_by_name,
                    ir.document_number AS incident_number, ir.incident_date, ir.incident_type,
                    ir.incident_location, ir.description AS incident_description
             FROM team8_notice_to_explain n
             JOIN users e ON e.id = n.employee_id
             JOIN users p ON p.id = n.prepared_by
             JOIN team8_incident_reports ir ON ir.id = n.incident_report_id
             WHERE n.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('t8_hr_explanation_for_nte')) {
    function t8_hr_explanation_for_nte(PDO $pdo, int $nteId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM team8_explanations WHERE nte_id = :id LIMIT 1');
        $stmt->execute(['id' => $nteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('t8_hr_explanation_fetch')) {
    function t8_hr_explanation_fetch(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT ex.*, e.full_name AS employee_name, n.document_number AS nte_number, n.deadline,
                    r.full_name AS reviewed_by_name
             FROM team8_explanations ex
             JOIN users e ON e.id = ex.employee_id
             JOIN team8_notice_to_explain n ON n.id = ex.nte_id
             LEFT JOIN users r ON r.id = ex.reviewed_by
             WHERE ex.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('t8_hr_memorandum_fetch')) {
    function t8_hr_memorandum_fetch(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT m.*, p.full_name AS prepared_by_name
             FROM team8_memorandums m
             JOIN users p ON p.id = m.prepared_by
             WHERE m.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('t8_hr_certificate_fetch')) {
    function t8_hr_certificate_fetch(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT c.*, e.full_name AS employee_name, e.department_id, d.name AS department_name, p.full_name AS prepared_by_name
             FROM team8_certificates c
             JOIN users e ON e.id = c.employee_id
             LEFT JOIN departments d ON d.id = e.department_id
             JOIN users p ON p.id = c.prepared_by
             WHERE c.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('t8_hr_dashboard_stats')) {
    /** Powers the Document Statistics + Pending Actions cards. */
    function t8_hr_dashboard_stats(PDO $pdo): array
    {
        $stats = [
            'total_documents'     => 0,
            'generated_documents' => 0,
            'archived'            => 0,
            'templates'           => 5, // Incident Report, NTE, Memorandum, Warning Letter, Certificate (see hr/generate.php)
            'pending_incidents'   => 0,
            'pending_nte'         => 0,
            'pending_explanations' => 0,
            'pending_approval'    => 0,
        ];

        try {
            $stats['total_documents'] = (int) $pdo->query("SELECT COUNT(*) FROM team8_documents WHERE deleted_at IS NULL")->fetchColumn();

            $generated = 0;
            $archived = (int) $pdo->query("SELECT COUNT(*) FROM team8_documents WHERE deleted_at IS NOT NULL")->fetchColumn();
            // BUG FIX: team8_explanations was missing from this list, so
            // explanations that an admin archived via explanation_review
            // never showed up in "Generated Documents" or "Archived" here.
            foreach (['team8_incident_reports', 'team8_notice_to_explain', 'team8_explanations', 'team8_memorandums', 'team8_certificates'] as $table) {
                $generated += (int) $pdo->query("SELECT COUNT(*) FROM {$table} WHERE status != 'archived'")->fetchColumn();
                $archived  += (int) $pdo->query("SELECT COUNT(*) FROM {$table} WHERE status = 'archived'")->fetchColumn();
            }
            $stats['generated_documents'] = $generated;
            $stats['archived'] = $archived;

            $stats['pending_incidents']    = (int) $pdo->query("SELECT COUNT(*) FROM team8_incident_reports WHERE status = 'pending'")->fetchColumn();
            $stats['pending_nte']          = (int) $pdo->query("SELECT COUNT(*) FROM team8_notice_to_explain WHERE status = 'pending'")->fetchColumn();
            $stats['pending_explanations'] = (int) $pdo->query("SELECT COUNT(*) FROM team8_explanations WHERE status = 'pending'")->fetchColumn();
            $stats['pending_approval']     = (int) $pdo->query("SELECT COUNT(*) FROM team8_documents WHERE status = 'pending' AND deleted_at IS NULL")->fetchColumn();
        } catch (PDOException $e) {
            // Fresh clone without the HR migration imported yet — fail soft like the rest of the dashboard.
        }

        return $stats;
    }
}

if (!function_exists('t8_hr_recent_documents')) {
    /**
     * Merges uploaded documents (team8_documents) with every HR
     * generated document type into one "Recent Documents" feed.
     * Built in PHP (not a SQL UNION) since the source tables have
     * unrelated column shapes and id spaces.
     *
     * PRIVACY: uploads and memorandums are broadcast-style and stay
     * visible to everyone (matching their existing, non-owner-scoped
     * behavior elsewhere in this module). Incident reports, notices,
     * and certificates concern one specific employee, so a non-admin
     * viewer only sees their OWN — this mirrors the same ownership
     * check enforced server-side on the *_view actions themselves,
     * so the feed never advertises a document the click-through would
     * 403 on anyway.
     */
    function t8_hr_recent_documents(PDO $pdo, bool $isAdmin, int $currentUserId, int $limit = 8): array
    {
        $items = [];

        try {
            $uploads = $pdo->query(
                "SELECT id, title AS label, 'upload' AS doc_type, 'approved' AS status, updated_at AS ts
                 FROM team8_documents WHERE deleted_at IS NULL ORDER BY updated_at DESC LIMIT 20"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($uploads as $row) {
                $items[] = $row + ['url_action' => 'versions'];
            }

            if ($isAdmin) {
                $incidentSql = "SELECT id, document_number AS label, 'incident_report' AS doc_type, status, created_at AS ts
                                 FROM team8_incident_reports ORDER BY created_at DESC LIMIT 20";
                $incidents = $pdo->query($incidentSql)->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $pdo->prepare(
                    "SELECT id, document_number AS label, 'incident_report' AS doc_type, status, created_at AS ts
                     FROM team8_incident_reports WHERE employee_id = :uid ORDER BY created_at DESC LIMIT 20"
                );
                $stmt->execute(['uid' => $currentUserId]);
                $incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            foreach ($incidents as $row) {
                $items[] = $row + ['url_action' => 'incident_report_view'];
            }

            if ($isAdmin) {
                $ntes = $pdo->query(
                    "SELECT id, document_number AS label, 'nte' AS doc_type, status, created_at AS ts
                     FROM team8_notice_to_explain ORDER BY created_at DESC LIMIT 20"
                )->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $pdo->prepare(
                    "SELECT id, document_number AS label, 'nte' AS doc_type, status, created_at AS ts
                     FROM team8_notice_to_explain WHERE employee_id = :uid ORDER BY created_at DESC LIMIT 20"
                );
                $stmt->execute(['uid' => $currentUserId]);
                $ntes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            foreach ($ntes as $row) {
                $items[] = $row + ['url_action' => 'nte_view'];
            }

            $memos = $pdo->query(
                "SELECT id, CONCAT(IF(kind='warning_letter','Warning Letter: ','Memo: '), title) AS label,
                        kind AS doc_type, status, created_at AS ts
                 FROM team8_memorandums ORDER BY created_at DESC LIMIT 20"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($memos as $row) {
                $items[] = $row + ['url_action' => 'memorandum_view'];
            }

            if ($isAdmin) {
                $certs = $pdo->query(
                    "SELECT id, document_number AS label, 'certificate' AS doc_type, status, created_at AS ts
                     FROM team8_certificates ORDER BY created_at DESC LIMIT 20"
                )->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $pdo->prepare(
                    "SELECT id, document_number AS label, 'certificate' AS doc_type, status, created_at AS ts
                     FROM team8_certificates WHERE employee_id = :uid ORDER BY created_at DESC LIMIT 20"
                );
                $stmt->execute(['uid' => $currentUserId]);
                $certs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            foreach ($certs as $row) {
                $items[] = $row + ['url_action' => 'certificate_view'];
            }
        } catch (PDOException $e) {
            return [];
        }

        usort($items, static fn ($a, $b) => strtotime((string) $b['ts']) <=> strtotime((string) $a['ts']));

        return array_slice($items, 0, $limit);
    }
}

if (!function_exists('t8_hr_doc_type_label')) {
    function t8_hr_doc_type_label(string $docType): string
    {
        $map = [
            'upload'           => 'Uploaded File',
            'incident_report'  => 'Incident Report',
            'nte'              => 'Notice To Explain',
            'memorandum'       => 'Memorandum',
            'warning_letter'   => 'Warning Letter',
            'certificate'      => 'Certificate',
            'explanation'      => 'Explanation Letter',
        ];
        return $map[$docType] ?? ucfirst($docType);
    }
}
