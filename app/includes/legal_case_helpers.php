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

function t8_legal_case_date_is_not_before_filed(string $filedDate, string $date): bool
{
    return t8_legal_is_valid_iso_date($filedDate)
        && t8_legal_is_valid_iso_date($date)
        && $date >= $filedDate;
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