<?php

declare(strict_types=1);

function t8_legal_case_type_code_from_name(string $name): string
{
    $code = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $name));
    return trim($code, '_');
}

function t8_legal_case_type_is_selectable(array $caseType): bool
{
    return (int) ($caseType['is_active'] ?? 0) === 1;
}

function t8_legal_case_type_can_deactivate(string $typeCode): bool
{
    return $typeCode !== 'other';
}

function t8_legal_is_valid_iso_date(string $date): bool
{
    if (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $date, $parts)) {
        return false;
    }

    return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
}

function t8_legal_is_valid_iso_time(string $time): bool
{
    if (!preg_match('/\A([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?\z/', $time, $parts)) {
        return false;
    }

    return true;
}

function t8_legal_case_date_is_not_before_filed(string $filedDate, string $date): bool
{
    return t8_legal_is_valid_iso_date($filedDate)
        && t8_legal_is_valid_iso_date($date)
        && $date >= $filedDate;
}

function t8_legal_task_is_overdue(string $status, string $dueDate, ?string $today = null): bool
{
    $today ??= date('Y-m-d');
    if (!in_array($status, ['pending', 'in_progress'], true)
        || !t8_legal_is_valid_iso_date($dueDate)
        || !t8_legal_is_valid_iso_date($today)
    ) {
        return false;
    }

    return $dueDate < $today;
}

function t8_legal_task_display_status(array $task, ?string $today = null): string
{
    $status = (string) ($task['status'] ?? 'pending');
    return t8_legal_task_is_overdue($status, (string) ($task['due_date'] ?? ''), $today)
        ? 'overdue'
        : $status;
}

function t8_legal_case_status_transition_is_allowed(string $currentStatus, string $nextStatus): bool
{
    $transitions = [
        'under_review' => ['active'],
        'active'       => ['resolved', 'under_review'],
        'resolved'     => ['closed', 'active'],
        'closed'       => [],
    ];

    return in_array($nextStatus, $transitions[$currentStatus] ?? [], true);
}
function t8_legal_document_types(): array
{
    return [
        'Complaint',
        'Notice',
        'Response',
        'Affidavit',
        'Evidence',
        'Government Letter',
        'Court / Agency Document',
        'Settlement Agreement',
        'Decision',
        'Other',
    ];
}

function t8_legal_resolution_types(): array
{
    return [
        'Settled',
        'Dismissed',
        'Resolved Internally',
        'Government Decision',
        'Court Decision',
        'Compliance Completed',
        'Other',
    ];
}

function t8_legal_timeline_event_title(string $entityType, string $action): string
{
    $titles = [
        'legal_case' => [
            'create' => 'Case created',
            'update' => 'Case updated',
            'case_fields_updated' => 'Case fields updated',
            'status_change' => 'Case status changed',
            'assignment_change' => 'Assignment changed',
            'deadline_change' => 'Deadline changed',
            'resolution_recorded' => 'Resolution recorded',
            'resolution_updated' => 'Resolution updated',
            'archive' => 'Case archived',
            'restore' => 'Case restored',
            'add_party' => 'Party added',
            'remove_party' => 'Party removed',
            'attach_document' => 'Document attached',
            'detach_document' => 'Document detached',
            'add_note' => 'Internal note added',
            'document_download' => 'Document downloaded',
            'retention_archived' => 'Retention record archived',
            'disposal_requested' => 'Disposal requested',
            'disposed' => 'Record disposed',
        ],
        'legal_case_task' => [
            'create' => 'Task created',
            'status_change' => 'Task status changed',
        ],
        'legal_case_hearing' => [
            'create' => 'Hearing scheduled',
            'update' => 'Hearing updated',
        ],
    ];

    return $titles[$entityType][$action] ?? ucwords(str_replace(['_', ':'], ' ', $action));
}

function t8_legal_timeline_event_detail(array $event): string
{
    $entityType = (string) ($event['entity_type'] ?? '');
    $action = (string) ($event['action'] ?? '');
    $oldValue = trim((string) ($event['old_value'] ?? ''));
    $newValue = trim((string) ($event['new_value'] ?? ''));
    $payload = json_decode($newValue !== '' ? $newValue : $oldValue, true);

    if ($action === 'case_fields_updated' && is_array($payload)) {
        $fields = array_values(array_filter(array_map('strval', $payload)));
        return $fields !== [] ? 'Changed fields: ' . implode(', ', $fields) : '';
    }
    if ($entityType === 'legal_case_hearing' && $action === 'update' && is_array($payload)) {
        $fields = array_values(array_filter(array_map('strval', $payload)));
        return $fields !== [] ? 'Changed fields: ' . implode(', ', $fields) : '';
    }
    if ($entityType === 'legal_case' && $action === 'create' && is_array($payload) && isset($payload['case_number'])) {
        return 'Case ' . (string) $payload['case_number'];
    }
    if ($entityType === 'legal_case' && in_array($action, ['add_party', 'remove_party'], true) && is_array($payload)) {
        $partyLabel = isset($payload['party_id']) ? 'Party #' . (int) $payload['party_id'] : 'Party';
        return $partyLabel . (isset($payload['role']) ? ' (' . (string) $payload['role'] . ')' : '');
    }
    if ($entityType === 'legal_case' && in_array($action, ['attach_document', 'detach_document'], true) && is_array($payload)) {
        $documentLabel = isset($payload['document_id']) ? 'Document #' . (int) $payload['document_id'] : 'Document';
        return $documentLabel . (isset($payload['type']) ? ' (' . (string) $payload['type'] . ')' : '');
    }
    if ($entityType === 'legal_case_task' && $action === 'create' && is_array($payload)) {
        return implode('; ', array_filter([
            isset($payload['due_date']) ? 'Due ' . (string) $payload['due_date'] : '',
            isset($payload['priority']) ? 'Priority ' . (string) $payload['priority'] : '',
        ]));
    }
    if ($entityType === 'legal_case_hearing' && $action === 'create' && is_array($payload)) {
        return implode('; ', array_filter([
            isset($payload['event_date']) ? 'Date ' . (string) $payload['event_date'] : '',
            isset($payload['hearing_type']) ? (string) $payload['hearing_type'] : '',
        ]));
    }
    if ($action === 'resolution_recorded') {
        return $newValue;
    }
    if (!in_array($action, ['status_change', 'update', 'assignment_change', 'deadline_change', 'resolution_updated', 'archive', 'restore'], true)
        || $oldValue === '' || $newValue === '' || $oldValue === $newValue
    ) {
        return '';
    }

    return $oldValue . ' -> ' . $newValue;
}

function t8_legal_format_case_number(int $year, int $sequence): string
{
    if ($year < 1 || $year > 9999 || $sequence < 1) {
        throw new InvalidArgumentException('Case number year and sequence must be positive.');
    }

    return sprintf('LC-%04d-%03d', $year, $sequence);
}

function t8_legal_next_case_number(PDO $pdo, int $year): string
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Case numbers must be allocated within the case creation transaction.');
    }

    $insert = $pdo->prepare(
        'INSERT INTO team8_legal_case_number_sequences (case_year, last_number)
         VALUES (:case_year, 0)
         ON DUPLICATE KEY UPDATE case_year = VALUES(case_year)'
    );
    $insert->execute(['case_year' => $year]);

    $select = $pdo->prepare(
        'SELECT last_number FROM team8_legal_case_number_sequences WHERE case_year = :case_year FOR UPDATE'
    );
    $select->execute(['case_year' => $year]);
    $sequence = $select->fetchColumn();
    if ($sequence === false) {
        throw new RuntimeException('Unable to allocate a legal case number.');
    }

    $sequence = (int) $sequence + 1;
    $update = $pdo->prepare(
        'UPDATE team8_legal_case_number_sequences SET last_number = :last_number WHERE case_year = :case_year'
    );
    $update->execute(['last_number' => $sequence, 'case_year' => $year]);

    return t8_legal_format_case_number($year, $sequence);
}