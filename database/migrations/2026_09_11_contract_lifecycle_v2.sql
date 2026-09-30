-- =========================================================
-- database/migrations/2026_09_11_contract_lifecycle_v2.sql
-- PHASE 2 of the Document/Legal/Contract/Retention rebuild.
--
-- Remaps team8_contracts.status from the old flat vocabulary to the
-- confirmed 9-stage lifecycle:
--   draft -> review -> negotiation -> approval -> signing -> active
--     -> renewal_or_amendment -> expiration_or_termination -> archived
--
-- This is a RELABELING migration only - no rows are deleted, no
-- schema change is needed (status is already VARCHAR(30), and the
-- longest new value, "expiration_or_termination", is 26 chars).
--
-- 'expiring_soon' is intentionally NOT mapped to its own new value -
-- it becomes the computed "monitoring" sub-state (see
-- t8_contract_is_monitoring() in modules/contracts/index.php) instead
-- of a stored status, so it can never drift out of sync with the
-- contract's actual dates the way a separately-stored status could.
-- Every 'expiring_soon' row below is mapped back to 'active'.
-- =========================================================

UPDATE team8_contracts
SET status = CASE status
    WHEN 'draft'             THEN 'draft'
    WHEN 'for_review'        THEN 'review'
    WHEN 'changes_requested' THEN 'negotiation'
    WHEN 'pending_approval'  THEN 'approval'
    WHEN 'approved'          THEN 'signing'                     -- old "approved, not yet active" == new "signing"
    WHEN 'active'            THEN 'active'
    WHEN 'expiring_soon'     THEN 'active'                      -- now computed, not stored (see docblock)
    WHEN 'pending_renewal'   THEN 'renewal_or_amendment'
    WHEN 'renewed'           THEN 'renewal_or_amendment'
    WHEN 'expired'           THEN 'expiration_or_termination'
    WHEN 'terminated'        THEN 'expiration_or_termination'
    WHEN 'archived'          THEN 'archived'
    ELSE status                                                  -- unknown value, left untouched rather than guessed
END;

-- ---------------------------------------------------------
-- Retroactive retention registration.
-- Any contract that landed in the new terminal status above (whether
-- it was 'expired', 'terminated', or already 'archived' under the old
-- vocabulary) should already be under retention going forward. This
-- backfills team8_records for exactly those rows that don't already
-- have one - it will never touch a contract a records officer has
-- already registered manually with a different basis/period.
--
-- Retention clock: COALESCE(termination_date, end_date, CURDATE())
-- Basis: Civil Code Art. 1144 (10-year prescriptive period, written contracts)
-- Requires: database/migrations/2026_09_11_retention_polymorphic_rebuild.sql
-- to have already run (Phase 1).
-- ---------------------------------------------------------
INSERT INTO team8_records
    (entity_type, entity_id, retention_basis, retention_years, retention_start_date, disposition_date, custodian_id, status)
SELECT
    'contract',
    c.id,
    'Civil Code Art. 1144 - written contract (10-year prescriptive period)',
    10,
    COALESCE(c.termination_date, c.end_date, CURDATE())                                    AS retention_start_date,
    DATE_ADD(COALESCE(c.termination_date, c.end_date, CURDATE()), INTERVAL 10 YEAR)         AS disposition_date,
    c.owner_id,
    'active'
FROM team8_contracts c
WHERE c.status IN ('expiration_or_termination', 'archived')
  AND c.deleted_at IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM team8_records r WHERE r.entity_type = 'contract' AND r.entity_id = c.id
  );
