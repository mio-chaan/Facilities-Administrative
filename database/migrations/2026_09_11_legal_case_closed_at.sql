-- =========================================================
-- database/migrations/2026_09_11_legal_case_closed_at.sql
-- PHASE 3 of the Document/Legal/Contract/Retention rebuild.
--
-- Legal Cases have no dedicated "when did this actually close" column
-- - updated_at is not safe to use for the retention clock, since it
-- changes on ANY edit to the case, not only the transition to
-- 'closed'. This adds a single-purpose timestamp set exactly once,
-- the moment status first becomes 'closed' (see modules/legal/index.php).
-- =========================================================

ALTER TABLE team8_legal_cases
    ADD COLUMN closed_at DATETIME NULL AFTER deadline;

-- Best-effort backfill for cases already closed before this migration
-- ran: use updated_at as the closest available proxy, since the real
-- closure moment wasn't recorded. Flagged here rather than hidden -
-- a records officer should spot-check these against case files if
-- retention timing precision matters for a specific case.
UPDATE team8_legal_cases
SET closed_at = updated_at
WHERE status = 'closed' AND closed_at IS NULL;
