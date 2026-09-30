<?php
declare(strict_types=1);

function t8_compliance_single_check_content(array $report, array $requirement, string $preparedBy, string $recipientEmails, bool $dispatchSent, array $renewalCounts = ['valid' => 0, 'attention' => 0]): array
{
    $generatedAt = date('M d, Y g:i A');
    $period = format_date((string) $report['date_from'], 'M d, Y') . ' - ' . format_date((string) $report['date_to'], 'M d, Y');
    $department = trim((string) ($report['department_name'] ?? '')) ?: 'All Departments';
    $documentType = (string) $requirement['name'];
    $documentTitle = trim((string) ($requirement['document_title'] ?? ''));
    $documentLabel = $documentTitle !== '' ? $documentType . ' (' . $documentTitle . ')' : $documentType;
    $status = (string) $requirement['status'];
    $expirationDate = (string) ($requirement['expiration_date'] ?? '');

    if ($status === 'missing') {
        $validationText = 'Missing - Not uploaded in Document Management.';
    } elseif ($status === 'expired') {
        $validationText = $expirationDate !== ''
            ? 'Expired on ' . format_date($expirationDate, 'M d, Y') . '.'
            : 'Expired - no expiration date is recorded.';
    } else {
        $validationText = $expirationDate !== ''
            ? 'Valid through ' . format_date($expirationDate, 'M d, Y') . '.'
            : 'Valid; no expiration date applies.';
    }

    $dispatchText = $dispatchSent
        ? 'Gmail dispatch was sent to ' . $recipientEmails . '.'
        : 'Gmail dispatch is paused until all required documents and renewal prerequisites are valid.';
    $summary = sprintf(
        'Report #%d (%s) covers %s for %s. Generated %s and prepared by %s. The single requirement checked was %s: %s Recipient: %s. %s',
        (int) $report['id'],
        (string) $report['report_type'],
        $period,
        $department,
        $generatedAt,
        $preparedBy,
        $documentLabel,
        $validationText,
        $recipientEmails,
        $dispatchText
    );
    $summary .= sprintf(
        ' Renewal prerequisites: %d valid, %d need attention.',
        (int) ($renewalCounts['valid'] ?? 0),
        (int) ($renewalCounts['attention'] ?? 0)
    );
    $trackingStatus = (string) ($requirement['tracking_status'] ?? '');
    if ($trackingStatus !== '') {
        $daysRemaining = $requirement['days_remaining'] ?? null;
        $summary .= ' Expiration tracking: ' . $trackingStatus
            . ($daysRemaining !== null ? ' (' . (int) $daysRemaining . ' days remaining).' : '.');
    }

    $findings = ['Expiration/Validity Audit: ' . $documentLabel . ' - ' . $validationText];
    if ($trackingStatus !== '') {
        $findings[] = 'Expiration tracking status: ' . $trackingStatus
            . (isset($requirement['days_remaining']) ? ' (' . (int) $requirement['days_remaining'] . ' days remaining).' : '.');
    }
    if ($status === 'ok' && $dispatchSent) {
        $findings[] = 'Verification: The selected document requirement was verified on ' . $generatedAt . '; the report was sent via Gmail to ' . $recipientEmails . '.';
    } elseif ($status === 'ok') {
        $findings[] = 'Verification: The selected document requirement is valid, but another mandatory document or renewal prerequisite needs attention; Gmail dispatch remains paused.';
    } else {
        $action = $status === 'missing'
            ? 'Upload a current ' . $documentType . ' in Document Management.'
            : 'Renew or replace the ' . $documentType . ' in Document Management.';
        $findings[] = 'Action Item: ' . $action . ' Checked on ' . $generatedAt . '. Gmail dispatch is paused.';
    }

    return ['executive_summary' => $summary, 'key_findings' => $findings, 'generated_at' => $generatedAt];
}