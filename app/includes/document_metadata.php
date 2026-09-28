<?php

declare(strict_types=1);

function t8_document_valid_metadata_date(mixed $date): bool
{
    if (!is_string($date)) {
        return false;
    }
    if ($date === '') {
        return true;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
        return false;
    }

    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $dateErrors = DateTimeImmutable::getLastErrors();
    return $parsedDate !== false
        && $parsedDate->format('Y-m-d') === $date
        && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0));
}

function t8_document_metadata_positive_id(mixed $value): int|false
{
    if (is_int($value)) {
        return $value > 0 ? $value : false;
    }
    if (!is_string($value) || preg_match('/^[1-9]\d*$/D', $value) !== 1) {
        return false;
    }

    $id = filter_var($value, FILTER_VALIDATE_INT);
    return $id !== false && $id > 0 ? $id : false;
}

function t8_document_metadata_version_token(array $document): string
{
    $fields = ['title', 'category_id', 'document_type', 'department_id', 'owner_id', 'expiration_date', 'status', 'deleted_at'];
    $snapshot = [];
    foreach ($fields as $field) {
        $value = $document[$field] ?? null;
        $snapshot[$field] = $value === null ? null : (string) $value;
    }

    return hash('sha256', serialize($snapshot));
}

function t8_document_metadata_version_matches(mixed $version, array $document): bool
{
    return is_string($version)
        && preg_match('/^[a-f0-9]{64}$/D', $version) === 1
        && hash_equals(t8_document_metadata_version_token($document), $version);
}

function t8_document_metadata_csrf_valid(mixed $token): bool
{
    return is_string($token) && t8_csrf_verify($token);
}

function t8_document_can_edit_metadata(?array $document, int $userId, bool $isAdmin): bool
{
    if ($document === null || $userId <= 0) {
        return false;
    }

    if (!t8_document_can_access($document, $userId, $isAdmin, $GLOBALS['pdo'] ?? null, 'edit', ['department_id' => $_SESSION['department_id'] ?? null])) {
        return false;
    }

    return $isAdmin || (empty($document['deleted_at']) && (string) ($document['status'] ?? '') !== 'archived');
}

function t8_document_update_metadata(PDO $pdo, int $documentId, array $updates, int $actorId, bool $isAdmin, ?string $expectedVersion = null): bool
{
    if ($documentId <= 0 || $updates === [] || $actorId <= 0) {
        return false;
    }

    $document = t8_document_fetch($pdo, $documentId);
    if (!t8_document_can_edit_metadata($document, $actorId, $isAdmin)) {
        return false;
    }
    if ($expectedVersion !== null && !t8_document_metadata_version_matches($expectedVersion, $document)) {
        return false;
    }

    $allowedFields = ['title', 'category_id', 'document_type', 'department_id', 'owner_id', 'expiration_date'];
    $updateValues = [];
    $setClauses = [];
    $auditChanges = [];
    $selectedCategoryId = array_key_exists('category_id', $updates)
        ? t8_document_metadata_positive_id($updates['category_id'])
        : t8_document_metadata_positive_id($document['category_id'] ?? null);
    $selectedTypeValue = array_key_exists('document_type', $updates) ? $updates['document_type'] : ($document['document_type'] ?? '');
    if (!is_string($selectedTypeValue)) {
        return false;
    }
    $selectedType = trim($selectedTypeValue);
    $typeOptions = $GLOBALS['documentTypeOptions'] ?? [];

    if ($selectedCategoryId === false || $selectedType === '' || mb_strlen($selectedType) > 150) {
        return false;
    }
    if (isset($typeOptions[(string) $selectedCategoryId])) {
        if (!in_array($selectedType, $typeOptions[(string) $selectedCategoryId], true)) {
            return false;
        }
    } elseif (mb_strlen($selectedType) > 100) {
        return false;
    }

    foreach ($updates as $field => $value) {
        if (!in_array($field, $allowedFields, true) || (in_array($field, ['department_id', 'owner_id'], true) && !$isAdmin)) {
            return false;
        }

        $oldValue = $document[$field] ?? null;
        switch ($field) {
            case 'title':
                if (!is_string($value)) {
                    return false;
                }
                $normalized = trim($value);
                if ($normalized === '' || mb_strlen($normalized) > 200) {
                    return false;
                }
                break;
            case 'category_id':
                $normalized = t8_document_metadata_positive_id($value);
                if ($normalized === false) {
                    return false;
                }
                $stmt = $pdo->prepare('SELECT 1 FROM team8_document_categories WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $normalized]);
                if ($stmt->fetchColumn() === false) {
                    return false;
                }
                break;
            case 'document_type':
                if (!is_string($value)) {
                    return false;
                }
                $normalized = trim($value);
                if ($normalized === '' || mb_strlen($normalized) > 150) {
                    return false;
                }
                break;
            case 'department_id':
            case 'owner_id':
                $normalized = $value === '' || $value === null ? null : t8_document_metadata_positive_id($value);
                if ($normalized !== null) {
                    if ($normalized === false) {
                        return false;
                    }
                    $table = $field === 'department_id' ? 'departments' : 'users';
                    $activeUserClause = $field === 'owner_id' ? ' AND deleted_at IS NULL' : '';
                    $stmt = $pdo->prepare('SELECT 1 FROM ' . $table . ' WHERE id = :id' . $activeUserClause . ' LIMIT 1');
                    $stmt->execute(['id' => $normalized]);
                    if ($stmt->fetchColumn() === false) {
                        return false;
                    }
                }
                break;
            case 'expiration_date':
                if (!t8_document_valid_metadata_date($value)) {
                    return false;
                }
                $normalized = $value === '' ? null : $value;
                break;
        }

        $oldNormalized = $oldValue === '' ? null : $oldValue;
        if ((string) ($oldNormalized ?? '') !== (string) ($normalized ?? '')) {
            $setClauses[] = $field . ' = :' . $field;
            $updateValues[$field] = $normalized;
            $auditChanges[$field] = [
                $oldNormalized === null ? null : (string) $oldNormalized,
                $normalized === null ? null : (string) $normalized,
            ];
        }
    }

    if ($setClauses === []) {
        return true;
    }

    $whereClauses = ['id = :id'];
    foreach (['title', 'category_id', 'document_type', 'department_id', 'owner_id', 'expiration_date', 'status', 'deleted_at'] as $field) {
        $column = in_array($field, ['title', 'document_type', 'status'], true) ? 'BINARY ' . $field : $field;
        $whereClauses[] = $column . ' <=> BINARY :original_' . $field;
        $updateValues['original_' . $field] = $document[$field] ?? null;
    }
    if (!$isAdmin) {
        $whereClauses[] = 'deleted_at IS NULL';
        $whereClauses[] = "status <> 'archived'";
    }
    $updateValues['id'] = $documentId;
    $updateSql = 'UPDATE team8_documents SET ' . implode(', ', $setClauses) . ', updated_at = NOW() WHERE ' . implode(' AND ', $whereClauses);

    try {
        if (!$pdo->beginTransaction()) {
            throw new RuntimeException('Could not start the metadata transaction.');
        }
        if (isset($updateValues['owner_id']) && $updateValues['owner_id'] !== null) {
            $ownerStatement = $pdo->prepare('SELECT id FROM users WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
            if ($ownerStatement === false
                || !$ownerStatement->execute(['id' => $updateValues['owner_id']])
                || $ownerStatement->fetchColumn() === false) {
                throw new RuntimeException('The selected owner is no longer active.');
            }
        }
        $updateStatement = $pdo->prepare($updateSql);
        if ($updateStatement === false || !$updateStatement->execute($updateValues)) {
            throw new RuntimeException('Metadata update statement failed.');
        }
        if ($updateStatement->rowCount() !== 1) {
            throw new RuntimeException('Document metadata changed before the update could be saved.');
        }
        foreach ($auditChanges as $field => [$oldValue, $newValue]) {
            t8_audit_log($pdo, $actorId, 'document', $documentId, 'metadata_update:' . $field, $oldValue, $newValue, true);
        }
        if (!$pdo->commit()) {
            throw new RuntimeException('Could not commit the metadata transaction.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Throwable $rollbackError) {
                error_log('Metadata transaction rollback failed: ' . $rollbackError->getMessage());
            }
        }
        return false;
    }

    return true;
}