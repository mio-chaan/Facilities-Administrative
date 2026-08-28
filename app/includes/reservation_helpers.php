<?php
/**
 * app/includes/reservation_helpers.php
 *
 * Shared reservation logic used by BOTH modules/reservation/index.php
 * (the page module) and the small JSON endpoints added alongside it:
 *   - public/reservation_status_poll.php  (live status/conflict polling)
 *   - public/facility_availability.php    (live quantity/capacity check)
 *
 * These functions used to live only inside modules/reservation/index.php,
 * which meant the new endpoints had no safe way to reuse them (that
 * file also renders a full HTML page as a side effect of being
 * required). Extracting them here keeps a single source of truth and
 * lets every consumer require_once this file instead.
 *
 * All functions are wrapped in function_exists() guards so this file
 * is safe to require_once from multiple entry points in the same
 * request without a redeclaration error.
 */

declare(strict_types=1);

if (!function_exists('t8_reservation_fetch')) {
    /** Fetch a single reservation with its facility/requester names, or null. */
    function t8_reservation_fetch(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT r.*, f.name AS facility_name, f.location AS facility_location, f.facility_type, u.full_name AS requester_name
             FROM team8_reservations r
             JOIN team8_facilities f ON f.id = r.facility_id
             JOIN users u ON u.id = r.user_id
             WHERE r.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('t8_reservation_has_conflict')) {
    /** True if an APPROVED reservation already occupies this facility/time range. */
    function t8_reservation_has_conflict(PDO $pdo, int $facilityId, string $start, string $end, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM team8_reservations
                WHERE facility_id = :facility_id AND status = 'approved'
                  AND start_time < :end_time AND end_time > :start_time";
        $params = ['facility_id' => $facilityId, 'start_time' => $start, 'end_time' => $end];
        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }
}

if (!function_exists('t8_reservation_committed_quantity')) {
    /**
     * Sum of quantity currently committed (pending or approved, not yet
     * returned) against a facility, so Equipment/Asset quantity checks
     * (both server-side validation AND the live facility_availability.php
     * endpoint used by the "capacity" JS enhancement) always compare
     * against what is actually still AVAILABLE, not just total capacity.
     */
    function t8_reservation_committed_quantity(PDO $pdo, int $facilityId, ?int $excludeId = null): int
    {
        $sql = "SELECT COALESCE(SUM(quantity), 0) FROM team8_reservations
                WHERE facility_id = :facility_id
                  AND status IN ('pending', 'approved')
                  AND quantity IS NOT NULL
                  AND (expected_return_date IS NULL OR expected_return_date >= CURDATE())";
        $params = ['facility_id' => $facilityId];
        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}

if (!function_exists('t8_reservation_has_started')) {
    /**
     * True once a reservation's start (or, for types with no start_time,
     * its schedule datetime) has already passed. Equipment/Asset
     * reservations (no start_time/schedule, only a return date) are
     * never considered "started" by this check.
     */
    function t8_reservation_has_started(array $reservation): bool
    {
        $reference = $reservation['start_time'] ?: ($reservation['schedule'] ?? null);
        if ($reference === null || $reference === '') {
            return false;
        }
        $ts = strtotime((string) $reference);
        return $ts !== false && $ts <= time();
    }
}

if (!function_exists('t8_reservation_display_status')) {
    /**
     * DYNAMIC STATUS: the raw `status` column only ever holds
     * pending/approved/cancellation_pending/rejected/cancelled/completed
     * — there is no stored "ongoing" state. This computes a display-only
     * status that turns an 'approved' reservation into 'ongoing' once
     * its start time has passed and it hasn't ended yet, without
     * touching the database. Used everywhere a status badge is shown
     * (list views + the live-polling endpoint) so "Ongoing" is
     * consistent across every screen without a manual refresh.
     */
    function t8_reservation_display_status(array $reservation): string
    {
        $status = (string) ($reservation['status'] ?? '');
        if ($status !== 'approved' || !t8_reservation_has_started($reservation)) {
            return $status;
        }

        $endReference = $reservation['end_time']
            ?? $reservation['schedule']
            ?? $reservation['expected_return_date']
            ?? null;

        if ($endReference === null || $endReference === '') {
            return 'ongoing';
        }

        $endTs = strtotime((string) $endReference);
        if ($endTs === false || $endTs >= time()) {
            return 'ongoing';
        }

        // Already past its end reference - the list view's periodic
        // "just completed" sweep will flip this to status='completed'
        // on its next load; treat it as completed here too so polling
        // clients never show a stale "Ongoing" badge in the meantime.
        return 'completed';
    }
}

if (!function_exists('t8_reservations_annotate_conflicts')) {
    /** Annotates a list of reservation rows in-place with a 'has_conflict' bool. */
    function t8_reservations_annotate_conflicts(PDO $pdo, array $rows): array
    {
        $now = time();
        foreach ($rows as &$row) {
            $start = isset($row['start_time']) && $row['start_time'] !== '' ? strtotime((string) $row['start_time']) : false;
            $end = isset($row['end_time']) && $row['end_time'] !== '' ? strtotime((string) $row['end_time']) : false;

            // Conflicts apply only to approved reservations that have not ended
            // (both upcoming and ongoing bookings).
            if (($row['status'] ?? '') === 'approved'
                && $start !== false
                && $end !== false
                && $end >= $now) {
                $row['has_conflict'] = t8_reservation_has_conflict(
                    $pdo,
                    (int) $row['facility_id'],
                    (string) $row['start_time'],
                    (string) $row['end_time'],
                    isset($row['id']) ? (int) $row['id'] : null
                );
            } else {
                $row['has_conflict'] = false;
            }
        }
        unset($row);
        return $rows;
    }
}

if (!function_exists('t8_normalize_datetime')) {
    /**
     * datetime-local inputs submit "Y-m-d\TH:i" (T separator, no seconds).
     * MySQL's strict-mode DATETIME literal parsing rejects that shape, so
     * normalize to "Y-m-d H:i:s" before it ever reaches a query. Safe to
     * call on an already-normalized value too (idempotent).
     */
    function t8_normalize_datetime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = str_replace('T', ' ', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
            $value .= ':00';
        }
        return $value;
    }
}
