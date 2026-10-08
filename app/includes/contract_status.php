<?php
declare(strict_types=1);

if (!function_exists('t8_contract_is_open')) {
    function t8_contract_is_open(array $contract): bool
    {
        return trim((string) ($contract['end_date'] ?? '')) === '';
    }
}

if (!function_exists('t8_contract_is_monitoring')) {
    function t8_contract_is_monitoring(array $contract, int $withinDays = 90): bool
    {
        $status = strtolower(str_replace('-', '_', trim((string) ($contract['status'] ?? ''))));
        if ($status !== 'active' || t8_contract_is_open($contract)) {
            return false;
        }

        $storedAlert = strtolower(trim((string) ($contract['expiration_alert'] ?? '')));
        if (in_array($storedAlert, ['low', 'medium', 'high', 'critical'], true)) {
            return true;
        }

        $horizon = strtotime('+' . $withinDays . ' days');
        foreach (['end_date', 'renewal_date'] as $field) {
            $date = $contract[$field] ?? null;
            $timestamp = $date ? strtotime((string) $date) : false;
            if ($timestamp !== false && $timestamp <= $horizon) {
                return true;
            }
        }
        return false;
    }
}
