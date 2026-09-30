<?php
declare(strict_types=1);

require_once __DIR__ . '/compliance_document_validator.php';
require_once __DIR__ . '/compliance_report_pdf.php';
require_once __DIR__ . '/compliance_summary.php';
require_once __DIR__ . '/gmail_dispatch.php';
require_once __DIR__ . '/notifications.php';

function t8_renewal_recipients(PDO $pdo, ?array $recipientOverride = null): array
{
    if ($recipientOverride !== null) {
        $emails = array_values(array_unique(array_map('trim', $recipientOverride)));
        foreach ($emails as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('A test renewal recipient address is invalid.');
            }
        }
        return $emails;
    }

    $emails = $pdo->query(
        "SELECT DISTINCT u.email FROM users u
         JOIN user_roles ur ON ur.user_id = u.id
         JOIN roles r ON r.id = ur.role_id
         WHERE r.role_name = 'admin' AND u.email <> ''"
    )->fetchAll(PDO::FETCH_COLUMN);
    $emails = array_values(array_filter(array_unique(array_map('trim', $emails)), static fn(string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false));
    if ($emails === [] && defined('RENEWAL_ALERT_RECIPIENT') && filter_var(RENEWAL_ALERT_RECIPIENT, FILTER_VALIDATE_EMAIL)) {
        $emails[] = RENEWAL_ALERT_RECIPIENT;
    }
    return $emails;
}

function t8_renewal_email_html(string $heading, string $body, array $lines, string $linkUrl = ''): string
{
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $items = '';
    foreach ($lines as $line) {
        $items .= '<li>' . $escape((string) $line) . '</li>';
    }
    $link = $linkUrl !== ''
        ? '<p><a href="' . $escape($linkUrl) . '" style="display:inline-block;padding:10px 16px;background:#7b2738;color:#fff;text-decoration:none">Go to Document Management</a></p>'
        : '';
    return '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#292321;line-height:1.6">'
        . '<h1 style="color:#7b2738">' . $escape($heading) . '</h1><p>' . nl2br($escape($body)) . '</p>'
        . ($items !== '' ? '<h2>Prerequisite checklist</h2><ul>' . $items . '</ul>' : '')
        . $link . '</body></html>';
}

function t8_renewal_required_type(string $documentType): string
{
    $normalizedType = strtolower(trim($documentType));
    foreach (RETENTION_RENEWAL_DOCUMENTS as $requiredType) {
        $aliases = array_merge([(string) $requiredType], MANDATORY_COMPLIANCE_DOCUMENTS[(string) $requiredType] ?? []);
        foreach ($aliases as $alias) {
            if (strtolower(trim((string) $alias)) === $normalizedType) {
                return (string) $requiredType;
            }
        }
    }
    return $documentType;
}

function t8_renewal_checklist(PDO $pdo, string $documentType): array
{
    $groups = t8_compliance_check_mandatory_documents($pdo, $documentType, false);
    return $groups[0]['prerequisites'] ?? [];
}

function t8_renewal_checklist_lines(array $prerequisites): array
{
    $lines = [];
    foreach ($prerequisites as $prerequisite) {
        $name = (string) ($prerequisite['name'] ?? 'Required document');
        if (($prerequisite['status'] ?? '') === 'ok') {
            $state = (string) ($prerequisite['detail'] ?? 'Valid');
        } elseif (($prerequisite['status'] ?? '') === 'missing') {
            $state = 'Not uploaded';
        } else {
            $expirationDate = (string) ($prerequisite['expiration_date'] ?? '');
            $state = $expirationDate !== '' ? 'Expired on ' . $expirationDate : 'Expired - no expiration date recorded';
        }
        $lines[] = $name . ': ' . $state;
    }
    return $lines;
}

function t8_renewal_notify_admins(PDO $pdo, string $message, string $targetUrl): void
{
    try {
        $admins = $pdo->query(
            "SELECT DISTINCT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE r.role_name = 'admin'"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) {
            t8_notify_user($pdo, (int) $adminId, $message, $targetUrl);
        }
    } catch (Throwable $e) {
        error_log('Renewal admin notification failed: ' . $e->getMessage());
    }
}

function t8_renewal_claim(PDO $pdo, int $documentId, string $milestone): bool
{
    $pdo->exec("DELETE FROM renewal_notifications WHERE status = 'processing' AND started_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO renewal_notifications (document_id, milestone, status, started_at)
         VALUES (:document_id, :milestone, 'processing', NOW())"
    );
    $stmt->execute(['document_id' => $documentId, 'milestone' => $milestone]);
    return $stmt->rowCount() === 1;
}

function t8_renewal_finish(PDO $pdo, int $documentId, string $milestone): void
{
    $stmt = $pdo->prepare(
        "UPDATE renewal_notifications SET status = 'processed', sent_at = NOW()
         WHERE document_id = :document_id AND milestone = :milestone AND status = 'processing'"
    );
    $stmt->execute(['document_id' => $documentId, 'milestone' => $milestone]);
}

function t8_renewal_release(PDO $pdo, int $documentId, string $milestone): void
{
    $stmt = $pdo->prepare(
        "DELETE FROM renewal_notifications WHERE document_id = :document_id AND milestone = :milestone AND status = 'processing'"
    );
    $stmt->execute(['document_id' => $documentId, 'milestone' => $milestone]);
}

function t8_renewal_documents_due(PDO $pdo, int $days, int $actorId): array
{
    $types = [];
    foreach (RETENTION_RENEWAL_DOCUMENTS as $requiredType) {
        $types = array_merge($types, [(string) $requiredType], MANDATORY_COMPLIANCE_DOCUMENTS[(string) $requiredType] ?? []);
    }
    $types = array_values(array_unique(array_map(static fn(string $type): string => strtolower(trim($type)), $types)));
    $placeholders = implode(',', array_fill(0, count($types), '?'));
    $stmt = $pdo->prepare(
        "SELECT d.id, d.document_type, d.title, d.expiration_date
         FROM team8_documents d
         JOIN team8_document_categories c ON c.id = d.category_id
         WHERE c.name = 'Compliance' AND d.deleted_at IS NULL AND d.status = 'approved'
           AND d.expiration_date = ? AND LOWER(TRIM(d.document_type)) IN ({$placeholders})
         ORDER BY d.id"
    );
    $stmt->execute(array_merge([(new DateTimeImmutable('today'))->modify('+' . $days . ' days')->format('Y-m-d')], $types));
    $documents = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $document) {
        $document = t8_document_sync_tracking($pdo, $document, $actorId);
        if ($document['days_remaining'] === $days) {
            $documents[] = $document;
        }
    }
    return $documents;
}

function t8_renewal_send(PDO $pdo, array $recipients, string $subject, string $html, string $text, array $attachments = []): void
{
    if ($recipients === []) {
        throw new RuntimeException('No renewal alert recipients are configured.');
    }
    t8_gmail_dispatch($recipients, $subject, $html, $text, $attachments);
}

function t8_renewal_generate_pdf(array $document, string $summary, array $checklist, string $newExpiration): string
{
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
    $report = [
        'report_type' => 'Automated Document Renewal',
        'department_name' => 'Compliance',
        'date_from' => date('Y-m-d'),
        'date_to' => date('Y-m-d'),
        'executive_summary' => $summary . ' New expiration date: ' . $newExpiration . '.',
    ];
    $findings = array_merge(
        ['Document: ' . (string) $document['title'], 'Previous expiration: ' . (string) $document['expiration_date'], 'New expiration: ' . $newExpiration],
        $checklist
    );
    $checks = [[
        'check_date' => date('Y-m-d'),
        'record_title' => (string) $document['title'],
        'department_name' => 'Compliance',
        'result' => 'completed',
        'notes' => 'Renewed through ' . $newExpiration,
    ]];
    $pdf = new Dompdf\Dompdf(['isRemoteEnabled' => false]);
    $pdf->loadHtml(t8_compliance_report_pdf_html($report, $findings, $checks), 'UTF-8');
    $pdf->setPaper('A4', 'portrait');
    $pdf->render();
    $directory = UPLOAD_DIR . '/documents';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the renewal report directory.');
    }
    $path = $directory . '/automated-renewal-' . (int) $document['id'] . '-' . bin2hex(random_bytes(4)) . '.pdf';
    if (file_put_contents($path, $pdf->output(), LOCK_EX) === false) {
        throw new RuntimeException('Could not save the renewal PDF.');
    }
    return $path;
}

function t8_run_renewal_checks(PDO $pdo, int $actorId, ?array $recipientOverride = null): array
{
    $result = ['t14' => 0, 't7_success' => 0, 't7_blocked' => 0, 'errors' => []];
    $recipients = t8_renewal_recipients($pdo, $recipientOverride);
    $managementUrl = rtrim(APP_URL, '/') . '/index.php?page=documents';

    foreach (t8_renewal_documents_due($pdo, 14, $actorId) as $document) {
        $id = (int) $document['id'];
        if (!t8_renewal_claim($pdo, $id, 't14')) {
            continue;
        }
        try {
            $documentType = t8_renewal_required_type((string) $document['document_type']);
            $lines = t8_renewal_checklist_lines(t8_renewal_checklist($pdo, $documentType));
            $subject = '[Upcoming Expiration Alert] ' . (string) $document['title'] . ' expires in 14 days';
            $body = (string) $document['title'] . ' expires on ' . (string) $document['expiration_date'] . '. Review the current prerequisites below.';
            t8_renewal_send($pdo, $recipients, $subject, t8_renewal_email_html('Upcoming expiration alert', $body, $lines, $managementUrl), $body . "\n\n" . implode("\n", $lines) . "\n\n" . $managementUrl);
            t8_renewal_notify_admins($pdo, (string) $document['document_type'] . ' expires in 14 days: ' . (string) $document['title'] . '.', 'index.php?page=documents');
            t8_audit_log($pdo, $actorId, 'document', $id, 'Automated 14-day renewal warning sent', null, (string) $document['title'] . ' | expires on ' . (string) $document['expiration_date'], true);
            t8_renewal_finish($pdo, $id, 't14');
            $result['t14']++;
        } catch (Throwable $e) {
            t8_renewal_release($pdo, $id, 't14');
            $result['errors'][] = 'T-14 document #' . $id . ': ' . $e->getMessage();
            error_log('T-14 renewal alert failed for document #' . $id . ': ' . $e->getMessage());
        }
    }

    foreach (t8_renewal_documents_due($pdo, 7, $actorId) as $document) {
        $id = (int) $document['id'];
        if (!t8_renewal_claim($pdo, $id, 't7')) {
            continue;
        }
        $milestoneSucceeded = false;
        $pdfPath = null;
        try {
            $label = (string) $document['document_type'];
            $prerequisites = t8_renewal_checklist($pdo, t8_renewal_required_type($label));
            $lines = t8_renewal_checklist_lines($prerequisites);
            $valid = $prerequisites !== [] && t8_compliance_checklist_is_valid([['prerequisites' => $prerequisites]], false);
            $name = (string) $document['title'];

            if (!$valid) {
                $failed = [];
                foreach ($prerequisites as $index => $prerequisite) {
                    if (($prerequisite['status'] ?? '') !== 'ok') {
                        $failed[] = $lines[$index] ?? ((string) ($prerequisite['name'] ?? 'Required document') . ': Not uploaded');
                    }
                }
                if ($prerequisites === []) {
                    $failed[] = 'No prerequisite rules are configured for ' . $label . '.';
                }
                $subject = '[ACTION REQUIRED] Automated Renewal Failed for ' . $name;
                $body = 'Automated renewal was blocked because one or more required prerequisites are missing or expired.';
                t8_renewal_send($pdo, $recipients, $subject, t8_renewal_email_html('Automated renewal blocked', $body, $failed, $managementUrl), $body . "\n\n" . implode("\n", $failed) . "\n\n" . $managementUrl);
                t8_renewal_notify_admins($pdo, 'Automated renewal blocked for ' . $name . ': ' . implode('; ', $failed), 'index.php?page=documents');
                t8_audit_log($pdo, $actorId, 'document', $id, 'Automated 7-day renewal blocked - missing prerequisites', null, $name . ' | ' . implode('; ', $failed), true);
                $result['t7_blocked']++;
                $milestoneSucceeded = true;
                continue;
            }

            $oldExpiration = (string) $document['expiration_date'];
            $newExpiration = (new DateTimeImmutable($oldExpiration))->modify('+1 year')->format('Y-m-d');
            $summaryContent = t8_compliance_single_check_content(
                [
                    'id' => $id,
                    'report_type' => 'Automated Document Renewal',
                    'date_from' => date('Y-m-d'),
                    'date_to' => date('Y-m-d'),
                    'department_name' => 'Compliance',
                ],
                [
                    'name' => $label,
                    'document_title' => $name,
                    'status' => 'ok',
                    'expiration_date' => $oldExpiration,
                ],
                'Automated Renewal Workflow',
                implode(', ', $recipients),
                true,
                ['valid' => count($prerequisites), 'attention' => 0]
            );
            $summary = $summaryContent['executive_summary'] . ' Renewal extended the expiration date from '
                . $oldExpiration . ' to ' . $newExpiration . '.';
            $pdfPath = t8_renewal_generate_pdf($document, $summary, $lines, $newExpiration);
            $pdo->beginTransaction();
            $update = $pdo->prepare(
                "UPDATE team8_documents SET status = 'Renewed', expiration_date = :new_expiration
                 WHERE id = :id AND status = 'approved' AND expiration_date = :old_expiration"
            );
            $update->execute(['new_expiration' => $newExpiration, 'id' => $id, 'old_expiration' => $oldExpiration]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The document changed during automated renewal.');
            }
            $renewedDocument = $document;
            $renewedDocument['expiration_date'] = $newExpiration;
            $renewedDocument['status'] = 'Renewed';
            t8_document_sync_tracking($pdo, $renewedDocument, $actorId);
            $subject = '[Automated Renewal] ' . $name . ' renewed';
            t8_renewal_send($pdo, $recipients, $subject, t8_renewal_email_html('Automated renewal completed', $summary, $lines), $summary . "\n\n" . implode("\n", $lines), [['path' => $pdfPath, 'name' => 'automated-renewal-' . $id . '.pdf']]);
            t8_audit_log($pdo, $actorId, 'document', $id, 'Automated 7-day renewal successfully processed', $oldExpiration, $name . ' | ' . $oldExpiration . ' -> ' . $newExpiration, true);
            $pdo->commit();
            t8_renewal_notify_admins($pdo, $name . ' was automatically renewed through ' . $newExpiration . '.', 'index.php?page=retention');
            $result['t7_success']++;
            $milestoneSucceeded = true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $result['errors'][] = 'T-7 document #' . $id . ': ' . $e->getMessage();
            error_log('T-7 renewal failed for document #' . $id . ': ' . $e->getMessage());
        } finally {
            if ($pdfPath !== null && is_file($pdfPath)) {
                @unlink($pdfPath);
            }
            if ($milestoneSucceeded) {
                t8_renewal_finish($pdo, $id, 't7');
            } else {
                t8_renewal_release($pdo, $id, 't7');
            }
        }
    }
    return $result;
}

function t8_run_daily_renewal_check(PDO $pdo, int $actorId, bool $force = false, ?array $recipientOverride = null): array
{
    $today = date('Y-m-d');
    $claim = $pdo->prepare('INSERT IGNORE INTO daily_check_log (check_date) VALUES (:check_date)');
    $claim->execute(['check_date' => $today]);
    if (!$force && $claim->rowCount() !== 1) {
        return ['already_ran' => true, 't14' => 0, 't7_success' => 0, 't7_blocked' => 0, 'errors' => []];
    }
    $result = t8_run_renewal_checks($pdo, $actorId, $recipientOverride);
    $update = $pdo->prepare('UPDATE daily_check_log SET t14_run_at = NOW(), t7_run_at = NOW() WHERE check_date = :check_date');
    $update->execute(['check_date' => $today]);
    $result['already_ran'] = false;
    return $result;
}

function t8_run_daily_renewal_check_if_due(PDO $pdo, int $actorId): void
{
    try {
        t8_run_daily_renewal_check($pdo, $actorId);
    } catch (Throwable $e) {
        error_log('Daily renewal check failed: ' . $e->getMessage());
    }
}
