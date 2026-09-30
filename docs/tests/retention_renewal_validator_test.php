<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/includes/compliance_document_validator.php';
require_once __DIR__ . '/../app/includes/renewal_automation.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$pdo = Database::getConnection();
$assert(t8_renewal_required_type("Mayor's Permit") === "Mayor's Business Permit", 'The Mayor\'s Permit alias must resolve to the configured renewal rule.');
$checklistLines = t8_renewal_checklist_lines([
    ['name' => 'Missing Permit', 'status' => 'missing'],
    ['name' => 'Expired Permit', 'status' => 'expired', 'expiration_date' => '2026-09-29'],
]);
$assert($checklistLines === ['Missing Permit: Not uploaded', 'Expired Permit: Expired on Sep 29, 2026'], 'Renewal messages must retain the required missing and expired checklist wording.');
$userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
if ($userId < 1) {
    throw new RuntimeException('The integration test requires at least one user.');
}

$prerequisiteTypes = [
    'Barangay Business Clearance',
    'Community Tax Certificate (Cedula)',
    'Real Property Tax Clearance',
    'Staff Health Certificates',
    'Water Potability Test Result',
    'Fire Extinguisher Inspection Log',
    'Building Safety Clearance',
];
$insertDocument = $pdo->prepare(
    'INSERT INTO team8_documents (category_id, document_type, uploaded_by, title, file_path, status, expiration_date)
     VALUES (NULL, :document_type, :uploaded_by, :title, :file_path, :status, :expiration_date)'
);
$insertFixture = static function (string $type, ?string $expirationDate) use ($insertDocument, $userId): void {
    $insertDocument->execute([
        'document_type' => $type,
        'uploaded_by' => $userId,
        'title' => 'Renewal Validator Test - ' . $type,
        'file_path' => 'test-only/renewal-validator-' . bin2hex(random_bytes(5)),
        'status' => 'approved',
        'expiration_date' => $expirationDate,
    ]);
};

$mode = (string) ($argv[1] ?? 'test');
if ($mode === 'seed-browser') {
    $futureDate = date('Y-m-d', strtotime('+30 days'));
    foreach ($prerequisiteTypes as $type) {
        $insertDocument->execute([
            'document_type' => $type,
            'uploaded_by' => $userId,
            'title' => 'T8 Renewal Browser Test - ' . $type,
            'file_path' => 'test-only/renewal-browser-' . bin2hex(random_bytes(5)),
            'status' => 'approved',
            'expiration_date' => $futureDate,
        ]);
    }
    echo "Seeded seven temporary browser fixtures.\n";
    exit(0);
}
if ($mode === 'seed-mixed-browser') {
    $insertDocument->execute([
        'document_type' => 'Barangay Business Clearance',
        'uploaded_by' => $userId,
        'title' => 'T8 Renewal Browser Test - Barangay Business Clearance',
        'file_path' => 'test-only/renewal-browser-' . bin2hex(random_bytes(5)),
        'status' => 'approved',
        'expiration_date' => date('Y-m-d', strtotime('+30 days')),
    ]);
    $insertDocument->execute([
        'document_type' => 'Community Tax Certificate (Cedula)',
        'uploaded_by' => $userId,
        'title' => 'T8 Renewal Browser Test - Community Tax Certificate (Cedula)',
        'file_path' => 'test-only/renewal-browser-' . bin2hex(random_bytes(5)),
        'status' => 'approved',
        'expiration_date' => date('Y-m-d', strtotime('-1 day')),
    ]);
    echo "Seeded mixed-state renewal checklist fixtures.\n";
    exit(0);
}
if ($mode === 'cleanup-browser') {
    $testNotes = 'T8 renewal browser test cleanup marker';
    $pdo->prepare(
        "DELETE al FROM audit_logs al
         JOIN renewal_requests rr ON rr.id = al.entity_id
         WHERE al.entity_type = 'renewal_request' AND rr.notes = :notes"
    )->execute(['notes' => $testNotes]);
    $pdo->prepare('DELETE FROM renewal_requests WHERE notes = :notes')->execute(['notes' => $testNotes]);
    $pdo->prepare("DELETE FROM team8_documents WHERE title LIKE 'T8 Renewal Browser Test - %'")->execute();
    $remainingFixtures = (int) $pdo->query(
        "SELECT COUNT(*) FROM team8_documents WHERE title LIKE 'T8 Renewal Browser Test - %'"
    )->fetchColumn();
    $requestCheck = $pdo->prepare('SELECT COUNT(*) FROM renewal_requests WHERE notes = :notes');
    $requestCheck->execute(['notes' => $testNotes]);
    $remainingRequests = (int) $requestCheck->fetchColumn();
    if ($remainingFixtures !== 0 || $remainingRequests !== 0) {
        throw new RuntimeException('Temporary browser test data was not fully removed.');
    }
    echo "Removed temporary browser fixtures.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $futureDate = date('Y-m-d', strtotime('+30 days'));
    foreach ($prerequisiteTypes as $type) {
        $insertFixture($type, $futureDate);
    }

    $groups = t8_compliance_check_mandatory_documents($pdo);
    $counts = t8_compliance_renewal_prerequisite_counts($groups);
    $assert($counts === ['valid' => 7, 'attention' => 0], 'All seven future-dated prerequisites should pass.');
    $assert(t8_compliance_checklist_is_valid($groups, false), 'A valid prerequisite-only renewal checklist should unlock renewal.');

    $insertFixture('Water Potability Test Result', date('Y-m-d'));
    $groups = t8_compliance_check_mandatory_documents($pdo);
    $sanitaryGroup = array_values(array_filter($groups, static fn(array $group): bool => $group['name'] === 'Sanitary Permit'))[0];
    $waterCheck = array_values(array_filter($sanitaryGroup['prerequisites'], static fn(array $item): bool => $item['name'] === 'Water Potability Test Result'))[0];
    $assert($waterCheck['status'] === 'expired', 'A prerequisite expiring today should be expired.');

    $insertFixture('Staff Health Certificates', null);
    $groups = t8_compliance_check_mandatory_documents($pdo);
    $sanitaryGroup = array_values(array_filter($groups, static fn(array $group): bool => $group['name'] === 'Sanitary Permit'))[0];
    $healthCheck = array_values(array_filter($sanitaryGroup['prerequisites'], static fn(array $item): bool => $item['name'] === 'Staff Health Certificates'))[0];
    $assert($healthCheck['status'] === 'expired' && $healthCheck['detail'] === 'No expiration date', 'An undated prerequisite should be invalid.');

    $pdo->prepare(
        'INSERT INTO retention_renewal_rules (main_document_type, prerequisite_document_type, is_required)
         VALUES (:main_document_type, :prerequisite_document_type, 1)'
    )->execute([
        'main_document_type' => 'Sanitary Permit',
        'prerequisite_document_type' => 'T8 Test Missing Prerequisite',
    ]);
    $groups = t8_compliance_check_mandatory_documents($pdo);
    $sanitaryGroup = array_values(array_filter($groups, static fn(array $group): bool => $group['name'] === 'Sanitary Permit'))[0];
    $missingCheck = array_values(array_filter($sanitaryGroup['prerequisites'], static fn(array $item): bool => $item['name'] === 'T8 Test Missing Prerequisite'))[0];
    $assert($missingCheck['status'] === 'missing', 'A prerequisite with no approved non-deleted document should be missing.');
    $assert(!t8_compliance_checklist_is_valid($groups, false), 'A missing prerequisite must keep renewal locked.');

    echo "Renewal prerequisite validator checks passed; fixtures are transaction-scoped.\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}