<?php
/**
 * constants.php
 * Central place for values used across modules (route keys, roles,
 * status strings) so nobody hardcodes "magic strings" in random files.
 * Extend this as each module milestone lands.
 */

declare(strict_types=1);

define('T8_PAGES', array_keys(require __DIR__ . '/routes.php'));

// Roles expected from the shared `roles` table (auth team owns the real
// source of truth — mirror values here only for local checks/UI logic).
const T8_ROLES = [
    'admin',
    'facilities_staff',
    'front_desk',
    'records_officer',
    'legal_officer',
    'employee',
];

// Shared status vocabulary (keep consistent with schema CHECK/ENUM values).
const T8_RESERVATION_STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];
const T8_VISIT_STATUSES       = ['expected', 'checked_in', 'checked_out', 'denied'];
const T8_RECORD_STATUSES      = ['active', 'archived', 'disposed'];
const T8_CONTRACT_STATUSES    = ['draft', 'active', 'expired', 'terminated', 'renewed'];
const T8_LEGAL_CASE_STATUSES  = ['open', 'in_progress', 'closed'];
