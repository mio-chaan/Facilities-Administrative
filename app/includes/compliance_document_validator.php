<?php
declare(strict_types=1);

require_once __DIR__ . '/document_tracking.php';

function t8_compliance_check_mandatory_documents(PDO $pdo, ?string $onlyRequiredName = null, bool $expireOnToday = true): array
{
    $requirements = MANDATORY_COMPLIANCE_DOCUMENTS;
    if ($onlyRequiredName !== null) {
        if (!array_key_exists($onlyRequiredName, $requirements)) {
            return [];
        }
        $requirements = [$onlyRequiredName => $requirements[$onlyRequiredName]];
    }

    $expirationColumnStmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name LIMIT 1'
    );
    $expirationColumnStmt->execute(['table_name' => 'team8_documents', 'column_name' => 'expiration_date']);
    $hasExpirationDate = (bool) $expirationColumnStmt->fetchColumn();
    $expirationSelect = $hasExpirationDate ? 'd.expiration_date' : 'NULL AS expiration_date';

    $trackingColumnStmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name LIMIT 1'
    );
    $trackingColumnStmt->execute(['table_name' => 'team8_documents', 'column_name' => 'tracking_status']);
    $hasTrackingStatus = (bool) $trackingColumnStmt->fetchColumn();
    $trackingSelect = $hasTrackingStatus ? 'd.tracking_status' : 'NULL AS tracking_status';
    $rules = $pdo->query(
        'SELECT main_document_type, prerequisite_document_type
         FROM retention_renewal_rules WHERE is_required = 1
         ORDER BY main_document_type, id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $results = [];

    foreach ($requirements as $requiredName => $matchingTypes) {
        $matchingTypes = is_array($matchingTypes) ? $matchingTypes : [(string) $matchingTypes];
        $resolveRequirement = static function (string $name, array $types, bool $mainDocument) use (
            $pdo,
            $hasExpirationDate,
            $expirationSelect,
            $hasTrackingStatus,
            $trackingSelect,
            $expireOnToday
        ): array {
            $placeholders = implode(',', array_fill(0, count($types), '?'));
            $categoryJoin = $mainDocument ? 'JOIN team8_document_categories c ON c.id = d.category_id' : '';
            $categoryWhere = $mainDocument ? " AND c.name = 'Compliance'" : '';
            $query = $pdo->prepare(
                "SELECT d.id, d.title, {$trackingSelect}, {$expirationSelect}
                 FROM team8_documents d {$categoryJoin}
                 WHERE d.deleted_at IS NULL AND d.status = 'approved'
                   AND LOWER(TRIM(d.document_type)) IN ({$placeholders}){$categoryWhere}
                 ORDER BY d.updated_at DESC, d.created_at DESC, d.id DESC LIMIT 1"
            );
            $query->execute(array_map(static fn(string $type): string => strtolower(trim($type)), $types));
            $document = $query->fetch(PDO::FETCH_ASSOC);
            if ($document === false) {
                return [
                    'name' => $name,
                    'status' => 'missing',
                    'detail' => 'Not uploaded in Document Management.',
                    'document_id' => null,
                    'document_title' => null,
                    'expiration_date' => null,
                    'tracking_status' => null,
                    'days_remaining' => null,
                    'alert_level' => null,
                ];
            }

            $document = t8_document_sync_tracking($pdo, $document);
            $expirationDate = $hasExpirationDate ? (string) ($document['expiration_date'] ?? '') : '';
            if ($expirationDate === '') {
                return [
                    'name' => $name,
                    'status' => 'expired',
                    'detail' => 'No expiration date',
                    'document_id' => (int) $document['id'],
                    'document_title' => (string) $document['title'],
                    'expiration_date' => null,
                    'tracking_status' => (string) $document['tracking_status'],
                    'days_remaining' => $document['days_remaining'],
                    'alert_level' => (string) $document['alert_level'],
                ];
            }
            $isExpired = (string) $document['tracking_status'] === 'Expired';
            if ($isExpired) {
                return [
                    'name' => $name,
                    'status' => 'expired',
                    'detail' => 'Expired on ' . format_date($expirationDate, 'M d, Y'),
                    'document_id' => (int) $document['id'],
                    'document_title' => (string) $document['title'],
                    'expiration_date' => $expirationDate,
                    'tracking_status' => (string) $document['tracking_status'],
                    'days_remaining' => $document['days_remaining'],
                    'alert_level' => (string) $document['alert_level'],
                ];
            }
            return [
                'name' => $name,
                'status' => 'ok',
                'detail' => (string) $document['title'] . ' - valid through ' . format_date($expirationDate, 'M d, Y'),
                'document_id' => (int) $document['id'],
                'document_title' => (string) $document['title'],
                'expiration_date' => $expirationDate,
                'tracking_status' => (string) $document['tracking_status'],
                'days_remaining' => $document['days_remaining'],
                'alert_level' => (string) $document['alert_level'],
            ];
        };

        $mainCheck = $resolveRequirement((string) $requiredName, $matchingTypes, true);
        $normalizedNames = array_map(static fn(string $type): string => strtolower(trim($type)), $matchingTypes);
        $prerequisiteTypes = [];
        foreach ($rules as $rule) {
            $ruleMainType = strtolower(trim((string) $rule['main_document_type']));
            if ($ruleMainType === strtolower(trim((string) $requiredName)) || in_array($ruleMainType, $normalizedNames, true)) {
                $prerequisiteTypes[] = (string) $rule['prerequisite_document_type'];
            }
        }

        $mainCheck['prerequisites'] = [];
        foreach (array_values(array_unique($prerequisiteTypes)) as $prerequisiteType) {
            $mainCheck['prerequisites'][] = $resolveRequirement($prerequisiteType, [$prerequisiteType], false);
        }
        $results[] = $mainCheck;
    }

    return $results;
}

function t8_compliance_renewal_prerequisite_counts(array $groups): array
{
    $valid = 0;
    $attention = 0;
    foreach ($groups as $group) {
        foreach (($group['prerequisites'] ?? []) as $prerequisite) {
            if (($prerequisite['status'] ?? '') === 'ok') {
                $valid++;
            } else {
                $attention++;
            }
        }
    }
    return ['valid' => $valid, 'attention' => $attention];
}

function t8_compliance_checklist_is_valid(array $groups, bool $includeMainDocuments = true): bool
{
    foreach ($groups as $group) {
        if ($includeMainDocuments && ($group['status'] ?? '') !== 'ok') {
            return false;
        }
        foreach (($group['prerequisites'] ?? []) as $prerequisite) {
            if (($prerequisite['status'] ?? '') !== 'ok') {
                return false;
            }
        }
    }
    return true;
}