<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/includes/audit.php';
require_once __DIR__ . '/../app/includes/document_metadata.php';

class T8MetadataStatement extends PDOStatement
{
    private mixed $columnValue = false;

    public function __construct(private T8MetadataPdo $pdo, private string $sql) {}

    public function execute(?array $params = null): bool
    {
        $result = $this->pdo->executeSql($this->sql, $params ?? []);
        $this->columnValue = $result;
        return $result !== false;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->columnValue;
    }

    public function rowCount(): int
    {
        return $this->pdo->affectedRows();
    }
}

class T8MetadataPdo extends PDO
{
    public array $document = [];
    public array $updates = [];
    public array $auditRows = [];
    public array $auditTransactionStates = [];
    public bool $auditFailure = false;
    public int $auditFailureAt = 0;
    public bool $auditReturnsFalse = false;
    public bool $updateFailureAfterWrite = false;
    public bool $updateReturnsFalse = false;
    public bool $beginFailure = false;
    public bool $commitFailure = false;
    public bool $archiveBeforeUpdate = false;
    public bool $deleteBeforeUpdate = false;
    public bool $concurrentTitleChange = false;
    public bool $ownerDeletedBeforeUpdate = false;
    private array $deletedUserIds = [];
    private int $affectedRows = 0;
    private int $auditAttempts = 0;
    private bool $transaction = false;
    private array $snapshot = [];

    public function __construct() {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new T8MetadataStatement($this, $query);
    }

    public function beginTransaction(): bool
    {
        if ($this->beginFailure) {
            return false;
        }
        if ($this->archiveBeforeUpdate) {
            $this->document['status'] = 'archived';
        }
        if ($this->deleteBeforeUpdate) {
            $this->document['deleted_at'] = '2026-09-26 00:00:00';
        }
        if ($this->concurrentTitleChange) {
            $this->document['title'] = 'Concurrent title';
        }
        if ($this->ownerDeletedBeforeUpdate) {
            $this->deletedUserIds[] = 13;
        }
        $this->transaction = true;
        $this->snapshot = [$this->document, $this->updates, $this->auditRows];
        return true;
    }

    public function commit(): bool
    {
        if ($this->commitFailure) {
            return false;
        }
        $this->transaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        [$this->document, $this->updates, $this->auditRows] = $this->snapshot;
        $this->transaction = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }

    public function executeSql(string $sql, array $params): mixed
    {
        if (str_starts_with($sql, 'SELECT id FROM users')) {
            $userId = (int) ($params['id'] ?? 0);
            return in_array($userId, [12, 13, 14], true) && !in_array($userId, $this->deletedUserIds, true)
                ? $userId
                : false;
        }
        if (str_starts_with($sql, 'SELECT 1 FROM ')) {
            if (str_contains($sql, 'users')) {
                $userId = (int) ($params['id'] ?? 0);
                return in_array($userId, [12, 13, 14], true)
                    && (!str_contains($sql, 'deleted_at IS NULL') || ($userId !== 14 && !in_array($userId, $this->deletedUserIds, true)))
                    ? 1
                    : false;
            }
            $ids = match (true) {
                str_contains($sql, 'team8_document_categories') => [10, 11],
                str_contains($sql, 'departments') => [4],
                default => [],
            };
            return in_array((int) ($params['id'] ?? 0), $ids, true) ? 1 : false;
        }

        if (str_starts_with($sql, 'UPDATE team8_documents')) {
            if (($this->document['status'] ?? null) !== ($params['original_status'] ?? null)
                || ($this->document['deleted_at'] ?? null) !== ($params['original_deleted_at'] ?? null)
                || (isset($params['original_title']) && $this->document['title'] !== $params['original_title'])
                || (str_contains($sql, "status <> 'archived'") && $this->document['status'] === 'archived')
                || (str_contains($sql, 'deleted_at IS NULL') && $this->document['deleted_at'] !== null)
            ) {
                $this->affectedRows = 0;
                return true;
            }
            $this->updates[] = $params;
            $this->affectedRows = 1;
            foreach ($params as $field => $value) {
                if ($field !== 'id' && !str_starts_with($field, 'original_')) {
                    $this->document[$field] = $value;
                }
            }
            if ($this->updateFailureAfterWrite) {
                throw new PDOException('Simulated metadata update failure.');
            }
            if ($this->updateReturnsFalse) {
                return false;
            }
            return true;
        }

        if (str_starts_with($sql, 'INSERT INTO audit_logs')) {
            $this->auditTransactionStates[] = $this->transaction;
            $this->auditAttempts++;
            if ($this->auditFailure || $this->auditFailureAt === $this->auditAttempts) {
                throw new PDOException('Simulated audit insert failure.');
            }
            if ($this->auditReturnsFalse) {
                return false;
            }
            $this->auditRows[] = $params;
            return true;
        }

        throw new RuntimeException('Unexpected SQL in test double: ' . $sql);
    }

    public function affectedRows(): int
    {
        return $this->affectedRows;
    }
}

function t8_document_fetch(PDO $pdo, int $id): ?array
{
    return (int) ($pdo->document['id'] ?? 0) === $id ? $pdo->document : null;
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$makePdo = static function (array $overrides = []): T8MetadataPdo {
    $pdo = new T8MetadataPdo();
    $pdo->document = array_merge([
        'id' => 1,
        'uploaded_by' => 12,
        'owner_id' => 12,
        'department_id' => 4,
        'category_id' => 10,
        'document_type' => 'Policy',
        'title' => 'Original title',
        'expiration_date' => null,
        'status' => 'approved',
        'deleted_at' => null,
    ], $overrides);
    return $pdo;
};

$GLOBALS['documentTypeOptions'] = [10 => ['Policy'], 11 => ['Reference']];
$_SESSION = [];
$GLOBALS['pdo'] = $makePdo();
$_SESSION['t8_csrf'] = 'metadata-test-token';
$assert(t8_document_metadata_csrf_valid('metadata-test-token'), 'A valid metadata CSRF token should be accepted.');
$assert(!t8_document_metadata_csrf_valid(['metadata-test-token']), 'An array-valued metadata CSRF token must be rejected without a type error.');
$assert(!t8_document_metadata_csrf_valid('invalid-token'), 'An invalid metadata CSRF token must be rejected.');
$_SESSION = [];

$assert(t8_document_can_edit_metadata($GLOBALS['pdo']->document, 12, false), 'An uploader should be allowed to edit live document metadata.');
$assert(!t8_document_can_edit_metadata(array_merge($GLOBALS['pdo']->document, ['status' => 'archived']), 12, false), 'An uploader must not edit archived document metadata.');
$assert(!t8_document_can_edit_metadata(array_merge($GLOBALS['pdo']->document, ['deleted_at' => '2026-09-01 00:00:00']), 12, false), 'An uploader must not edit deleted document metadata.');
$assert(!t8_document_can_edit_metadata($GLOBALS['pdo']->document, 99, false), 'An unrelated user must not edit document metadata.');
$assert(!t8_document_can_edit_metadata(array_merge($GLOBALS['pdo']->document, ['owner_id' => 15]), 15, false), 'Ownership grants view access but not metadata edit access.');
$_SESSION['department_id'] = 4;
$assert(!t8_document_can_edit_metadata($GLOBALS['pdo']->document, 23, false), 'Department membership grants view access but not metadata edit access.');
$_SESSION = [];
$assert(!t8_document_can_edit_metadata($GLOBALS['pdo']->document, 0, true), 'An admin role without a valid user ID must not edit metadata.');
$assert(t8_document_can_edit_metadata(array_merge($GLOBALS['pdo']->document, ['status' => 'archived']), 7, true), 'Admins may edit archived metadata under the current system-wide override policy.');
$assert(t8_document_can_edit_metadata(array_merge($GLOBALS['pdo']->document, ['deleted_at' => '2026-09-01 00:00:00']), 7, true), 'Admins may edit soft-deleted metadata under the current system-wide override policy.');

$invalidUpdates = [
    ['title' => true],
    ['title' => ['Malformed title']],
    ['title' => null],
    ['title' => '   '],
    ['department_id' => 999],
    ['department_id' => '4junk'],
    ['department_id' => ' 4 '],
    ['department_id' => true],
    ['department_id' => []],
    ['owner_id' => 999],
    ['owner_id' => 14],
    ['owner_id' => '12junk'],
    ['owner_id' => ' 12 '],
    ['owner_id' => true],
    ['owner_id' => []],
    ['category_id' => 999],
    ['category_id' => '10junk'],
    ['category_id' => ' 10 '],
    ['category_id' => '010'],
    ['category_id' => true],
    ['category_id' => []],
    ['category_id' => null],
    ['document_type' => ['Policy']],
    ['document_type' => null],
    ['expiration_date' => null],
    ['expiration_date' => 'September 30, 2026'],
    ['expiration_date' => '2025-1-1'],
    ['expiration_date' => '2025-01-1'],
    ['expiration_date' => '2025-1-01'],
    ['expiration_date' => '01-01-2025'],
    ['expiration_date' => '2025-02-30'],
    ['expiration_date' => '2025-00-00'],
    ['expiration_date' => '2025-13-01'],
    ['expiration_date' => '2026-09-30 12:00:00'],
    ['expiration_date' => ' 2026-09-30 '],
    ['document_type' => 'Reference'],
    ['category_id' => 11, 'document_type' => 'Policy'],
    ['title' => 'Would partially save', 'owner_id' => 999],
    ['unexpected_field' => 'not editable'],
    ['status' => 'approved'],
    ['deleted_at' => null],
    ['uploaded_by' => 99],
];
foreach ($invalidUpdates as $updates) {
    $pdo = $makePdo();
    $GLOBALS['pdo'] = $pdo;
    $assert(!t8_document_update_metadata($pdo, 1, $updates, 12, true), 'Invalid metadata should be rejected: ' . json_encode($updates));
    $assert($pdo->updates === [] && $pdo->auditRows === [], 'Rejected metadata must not update records or write audit rows.');
}
$assert(t8_document_valid_metadata_date('2024-02-29'), 'A valid leap-day date should be accepted.');
$assert(!t8_document_valid_metadata_date('2025-02-29'), 'An invalid leap-day date should be rejected.');
$assert(t8_document_metadata_positive_id('10') === 10 && t8_document_metadata_positive_id(10) === 10, 'Canonical positive integer IDs should be accepted.');
foreach ([true, '01', '1junk', ' 1', '1 ', [], 0, -1, null] as $invalidId) {
    $assert(t8_document_metadata_positive_id($invalidId) === false, 'Malformed metadata IDs must be rejected: ' . json_encode($invalidId));
}
$initialVersion = t8_document_metadata_version_token($GLOBALS['pdo']->document);
$assert(t8_document_metadata_version_matches($initialVersion, $GLOBALS['pdo']->document), 'A snapshot token should match its original metadata.');
$assert(!t8_document_metadata_version_matches($initialVersion, array_merge($GLOBALS['pdo']->document, ['title' => 'Concurrent title'])), 'A snapshot token must detect a concurrent metadata change.');
$assert(!t8_document_metadata_version_matches([], $GLOBALS['pdo']->document), 'Malformed snapshot tokens must be rejected safely.');

$restrictedPdo = $makePdo(['status' => 'archived']);
$GLOBALS['pdo'] = $restrictedPdo;
$assert(!t8_document_update_metadata($restrictedPdo, 1, ['title' => 'Changed'], 12, false), 'Archived metadata updates must be denied even when called directly.');
$adminArchivedPdo = $makePdo(['status' => 'archived']);
$GLOBALS['pdo'] = $adminArchivedPdo;
$assert(t8_document_update_metadata($adminArchivedPdo, 1, ['title' => 'Admin archived edit'], 7, true), 'Admins may edit archived metadata under the existing override policy.');
$adminDeletedPdo = $makePdo(['deleted_at' => '2026-09-01 00:00:00']);
$GLOBALS['pdo'] = $adminDeletedPdo;
$assert(t8_document_update_metadata($adminDeletedPdo, 1, ['title' => 'Admin deleted edit'], 7, true), 'Admins may edit soft-deleted metadata under the existing override policy.');

$unauthorizedPdo = $makePdo();
$GLOBALS['pdo'] = $unauthorizedPdo;
$assert(!t8_document_update_metadata($unauthorizedPdo, 1, ['title' => 'Unauthorized'], 99, false), 'An unrelated user must not update metadata.');
$assert(!t8_document_update_metadata($unauthorizedPdo, 1, ['owner_id' => 13], 12, false), 'Non-admin uploaders must not change owner assignment.');
$assert(!t8_document_update_metadata($unauthorizedPdo, 1, ['department_id' => 4], 12, false), 'Non-admin uploaders must not change department assignment.');
$assert($unauthorizedPdo->updates === [] && $unauthorizedPdo->auditRows === [], 'Unauthorized requests must not write metadata or audits.');

$nonexistentPdo = $makePdo();
$GLOBALS['pdo'] = $nonexistentPdo;
$assert(!t8_document_update_metadata($nonexistentPdo, 404, ['title' => 'Missing'], 12, true), 'A nonexistent document must not be updated.');

$staleFormPdo = $makePdo(['title' => 'Session B title']);
$GLOBALS['pdo'] = $staleFormPdo;
$staleFormVersion = t8_document_metadata_version_token($makePdo()->document);
$assert(!t8_document_update_metadata($staleFormPdo, 1, ['title' => 'Session A stale title'], 12, false, $staleFormVersion), 'A stale form snapshot must not overwrite a newer document value.');
$assert($staleFormPdo->document['title'] === 'Session B title' && $staleFormPdo->updates === [] && $staleFormPdo->auditRows === [], 'A stale form rejection must preserve the concurrent value without audit writes.');

$validPdo = $makePdo();
$GLOBALS['pdo'] = $validPdo;
$GLOBALS['documentTypeOptions'] = [10 => ['Policy'], 11 => ['Reference']];
$valid = t8_document_update_metadata($validPdo, 1, [
    'title' => 'Updated title',
    'category_id' => 11,
    'document_type' => 'Reference',
    'owner_id' => 13,
    'expiration_date' => '2026-09-30',
], 12, true);
$assert($valid, 'Valid metadata should be saved.');
$assert($validPdo->document['title'] === 'Updated title' && $validPdo->document['owner_id'] === 13, 'Valid metadata values should reach the document update.');
$assert(count($validPdo->auditRows) === 5, 'Each changed metadata field should have its own audit row.');
$assert(array_column($validPdo->auditRows, 'action') === [
    'metadata_update:title',
    'metadata_update:category_id',
    'metadata_update:document_type',
    'metadata_update:owner_id',
    'metadata_update:expiration_date',
], 'Audit actions should identify each changed field.');
$assert(array_map(static fn (array $row): array => [$row['old_value'], $row['new_value']], $validPdo->auditRows) === [
    ['Original title', 'Updated title'],
    ['10', '11'],
    ['Policy', 'Reference'],
    ['12', '13'],
    [null, '2026-09-30'],
], 'Every audit old/new value pair must match its metadata change.');
$assert(count(array_filter($validPdo->auditTransactionStates)) === 5, 'Every audit row must be written inside the metadata transaction.');
$assert(t8_document_update_metadata($validPdo, 1, ['title' => 'Updated title'], 12, true), 'A repeated no-op update should succeed.');
$assert(count($validPdo->auditRows) === 5, 'Repeated no-op updates must not duplicate audit entries.');

$clearableMetadataPdo = $makePdo(['owner_id' => 13, 'department_id' => 4, 'expiration_date' => '2026-09-30']);
$GLOBALS['pdo'] = $clearableMetadataPdo;
$assert(t8_document_update_metadata($clearableMetadataPdo, 1, ['owner_id' => null, 'department_id' => '', 'expiration_date' => ''], 12, true), 'Explicit null and empty values should clear nullable metadata.');
$assert($clearableMetadataPdo->document['owner_id'] === null && $clearableMetadataPdo->document['department_id'] === null && $clearableMetadataPdo->document['expiration_date'] === null, 'Nullable metadata clears must persist as SQL NULL values.');

$auditFailurePdo = $makePdo();
$auditFailurePdo->auditFailure = true;
$GLOBALS['pdo'] = $auditFailurePdo;
$assert(!t8_document_update_metadata($auditFailurePdo, 1, ['title' => 'Should roll back'], 12, true), 'Audit failure must fail the metadata update.');
$assert($auditFailurePdo->document['title'] === 'Original title' && !$auditFailurePdo->inTransaction(), 'Audit failure must roll back the document update.');

$partialAuditFailurePdo = $makePdo();
$partialAuditFailurePdo->auditFailureAt = 2;
$GLOBALS['pdo'] = $partialAuditFailurePdo;
$assert(!t8_document_update_metadata($partialAuditFailurePdo, 1, ['title' => 'Changed', 'expiration_date' => '2026-09-30'], 12, true), 'A later audit failure must fail the full metadata update.');
$assert($partialAuditFailurePdo->document['title'] === 'Original title' && $partialAuditFailurePdo->document['expiration_date'] === null && $partialAuditFailurePdo->auditRows === [], 'A later audit failure must roll back metadata and earlier audit rows.');

$silentAuditFailurePdo = $makePdo();
$silentAuditFailurePdo->auditReturnsFalse = true;
$GLOBALS['pdo'] = $silentAuditFailurePdo;
$assert(!t8_document_update_metadata($silentAuditFailurePdo, 1, ['title' => 'Should roll back'], 12, true), 'A silent audit insert failure must fail the metadata update.');
$assert($silentAuditFailurePdo->document['title'] === 'Original title' && !$silentAuditFailurePdo->inTransaction(), 'A silent audit insert failure must roll back metadata.');

$databaseFailurePdo = $makePdo();
$databaseFailurePdo->updateFailureAfterWrite = true;
$GLOBALS['pdo'] = $databaseFailurePdo;
$assert(!t8_document_update_metadata($databaseFailurePdo, 1, ['title' => 'Should roll back'], 12, true), 'A database update exception must fail the metadata update.');
$assert($databaseFailurePdo->document['title'] === 'Original title' && $databaseFailurePdo->auditRows === [], 'A database update exception must roll back metadata and avoid audit rows.');

$silentUpdateFailurePdo = $makePdo();
$silentUpdateFailurePdo->updateReturnsFalse = true;
$GLOBALS['pdo'] = $silentUpdateFailurePdo;
$assert(!t8_document_update_metadata($silentUpdateFailurePdo, 1, ['title' => 'Should roll back'], 12, true), 'A silent metadata update failure must fail the operation.');
$assert($silentUpdateFailurePdo->document['title'] === 'Original title' && $silentUpdateFailurePdo->auditRows === [], 'A silent metadata update failure must roll back and avoid audit rows.');

$beginFailurePdo = $makePdo();
$beginFailurePdo->beginFailure = true;
$GLOBALS['pdo'] = $beginFailurePdo;
$assert(!t8_document_update_metadata($beginFailurePdo, 1, ['title' => 'Should not update'], 12, true), 'A transaction start failure must fail the metadata update.');
$assert($beginFailurePdo->document['title'] === 'Original title' && $beginFailurePdo->auditRows === [], 'A transaction start failure must not write metadata or audits.');

$commitFailurePdo = $makePdo();
$commitFailurePdo->commitFailure = true;
$GLOBALS['pdo'] = $commitFailurePdo;
$assert(!t8_document_update_metadata($commitFailurePdo, 1, ['title' => 'Should roll back'], 12, true), 'A transaction commit failure must fail the metadata update.');
$assert($commitFailurePdo->document['title'] === 'Original title' && $commitFailurePdo->auditRows === [] && !$commitFailurePdo->inTransaction(), 'A transaction commit failure must roll back metadata and audits.');

foreach (['archiveBeforeUpdate', 'deleteBeforeUpdate', 'concurrentTitleChange'] as $race) {
    $racePdo = $makePdo();
    $racePdo->{$race} = true;
    $GLOBALS['pdo'] = $racePdo;
    $assert(!t8_document_update_metadata($racePdo, 1, ['title' => 'Racing update'], 12, false), 'A concurrent ' . $race . ' must prevent a stale non-admin metadata update.');
    $assert($racePdo->auditRows === [], 'A rejected concurrent metadata update must not create audit rows.');
}
$deletedOwnerRacePdo = $makePdo();
$deletedOwnerRacePdo->ownerDeletedBeforeUpdate = true;
$GLOBALS['pdo'] = $deletedOwnerRacePdo;
$assert(!t8_document_update_metadata($deletedOwnerRacePdo, 1, ['owner_id' => 13], 12, true), 'A user soft-deleted during the metadata edit must not be assigned as owner.');
$assert($deletedOwnerRacePdo->document['owner_id'] === 12 && $deletedOwnerRacePdo->auditRows === [], 'A concurrent owner deactivation must not persist the reassignment or an audit row.');

if (file_exists(__DIR__ . '/../modules/documents/hr/generate.php')) {
    fwrite(STDERR, "The unused HR template picker still exists and should be removed.\n");
    exit(1);
}

echo "Document metadata edit tests passed.\n";
