-- =========================================================
-- database/migrations/2026_09_11_retention_polymorphic_rebuild.sql
-- PHASE 1 of the Document/Legal/Contract/Retention rebuild.
--
-- Converts team8_records from a documents-only retention table into
-- a polymorphic table that can retain any of three business record
-- types: uploaded documents, contracts, and legal cases.
--
-- SAFE-MIGRATION NOTES:
--   - This is an ALTER-in-place migration, not a drop/recreate. Row
--     ids are preserved so team8_compliance_checks.record_id (which
--     has an existing FK into this table) keeps working without any
--     changes on its side.
--   - Existing rows are backfilled with entity_type = 'document' and
--     entity_id = the old document_id, so nothing already under
--     retention silently falls out of tracking.
--   - retention_start_date is back-computed from the existing
--     disposition_date and the linked schedule's retention_years, so
--     the previously computed disposition_date does not change for
--     any row that already had one.
--   - Run this AFTER database/schema.sql and AFTER
--     database/seed_dummy_data.sql if you want the backfill to have
--     real historical rows to migrate; it is also safe to run against
--     an empty team8_records table.
-- =========================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------
-- 1. Drop the old single-purpose FK before repointing the column.
--    document_id becomes redundant once entity_id covers the same
--    case (entity_type = 'document') plus two more entity types.
-- ---------------------------------------------------------
ALTER TABLE team8_records
    DROP FOREIGN KEY fk_team8_records_document;

-- ---------------------------------------------------------
-- 2. Add the polymorphic columns.
-- ---------------------------------------------------------
ALTER TABLE team8_records
    ADD COLUMN entity_type VARCHAR(20) NULL AFTER id,
    ADD COLUMN entity_id   INT NULL AFTER entity_type;

-- Backfill every existing row as a 'document' retention record.
UPDATE team8_records
SET entity_type = 'document',
    entity_id   = document_id
WHERE entity_type IS NULL;

ALTER TABLE team8_records
    MODIFY COLUMN entity_type VARCHAR(20) NOT NULL,
    MODIFY COLUMN entity_id   INT NOT NULL,
    ADD CONSTRAINT chk_team8_records_entity_type
        CHECK (entity_type IN ('document', 'contract', 'legal_case'));

-- ---------------------------------------------------------
-- 3. Add retention-basis fields. Every record now carries its own
--    legal/policy basis and period, instead of only pointing at a
--    shared team8_retention_schedules row - a Contract and a Legal
--    Case do not share the same "record_type" vocabulary that table
--    was built around.
-- ---------------------------------------------------------
ALTER TABLE team8_records
    ADD COLUMN retention_basis      VARCHAR(255) NULL AFTER schedule_id,
    ADD COLUMN retention_years      INT NULL AFTER retention_basis,
    ADD COLUMN retention_start_date DATE NULL AFTER retention_years;

-- Backfill retention_years from the linked schedule where one exists.
UPDATE team8_records r
JOIN team8_retention_schedules s ON s.id = r.schedule_id
SET r.retention_years = s.retention_years,
    r.retention_basis = CONCAT('Migrated from schedule: ', s.record_type)
WHERE r.retention_years IS NULL;

-- Any row with no schedule at all (shouldn't normally happen, since
-- schedule_id was NOT NULL previously, but guarded defensively).
UPDATE team8_records
SET retention_years = 5,
    retention_basis = 'Internal policy (default - please review)'
WHERE retention_years IS NULL;

-- Back-compute retention_start_date so the EXISTING disposition_date
-- is preserved exactly, instead of being recalculated and possibly
-- drifting from what staff have already been told.
UPDATE team8_records
SET retention_start_date = DATE_SUB(disposition_date, INTERVAL retention_years YEAR)
WHERE retention_start_date IS NULL AND disposition_date IS NOT NULL;

-- Fallback for any row that somehow has no disposition_date either.
UPDATE team8_records
SET retention_start_date = CURDATE(),
    disposition_date = DATE_ADD(CURDATE(), INTERVAL retention_years YEAR)
WHERE retention_start_date IS NULL;

ALTER TABLE team8_records
    MODIFY COLUMN retention_basis      VARCHAR(255) NOT NULL,
    MODIFY COLUMN retention_years      INT NOT NULL,
    MODIFY COLUMN retention_start_date DATE NOT NULL,
    MODIFY COLUMN schedule_id          INT NULL;  -- kept only as an optional named-policy reference, no longer required

-- ---------------------------------------------------------
-- 4. Add the two-step disposal workflow columns (Contracts and Legal
--    Cases require dual control; Documents use disposed_at/
--    disposal_reason directly and skip the request/authorize pair).
-- ---------------------------------------------------------
ALTER TABLE team8_records
    ADD COLUMN disposal_requested_by  INT NULL AFTER disposal_reason,
    ADD COLUMN disposal_requested_at  DATETIME NULL AFTER disposal_requested_by,
    ADD COLUMN disposal_authorized_by INT NULL AFTER disposal_requested_at,
    ADD COLUMN disposal_authorized_at DATETIME NULL AFTER disposal_authorized_by,
    ADD CONSTRAINT fk_team8_records_disposal_requester  FOREIGN KEY (disposal_requested_by)  REFERENCES users(id),
    ADD CONSTRAINT fk_team8_records_disposal_authorizer FOREIGN KEY (disposal_authorized_by) REFERENCES users(id);

-- ---------------------------------------------------------
-- 5. Drop the now-redundant document_id column and enforce one
--    retention record per business record.
-- ---------------------------------------------------------
ALTER TABLE team8_records
    DROP COLUMN document_id,
    ADD UNIQUE KEY uq_team8_records_entity (entity_type, entity_id);

CREATE INDEX idx_team8_records_entity ON team8_records (entity_type, entity_id);
CREATE INDEX idx_team8_records_disposition ON team8_records (disposition_date, status);

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================
-- Resulting shape of team8_records after this migration:
--
--   id                      INT PK
--   entity_type             VARCHAR(20)   'document' | 'contract' | 'legal_case'
--   entity_id               INT            polymorphic FK, resolved in PHP (see retention_helpers.php)
--   schedule_id             INT NULL       optional link to a named team8_retention_schedules policy
--   retention_basis         VARCHAR(255)   e.g. "BIR RR No. 7-2024 (EOPT Act)"
--   retention_years         INT
--   retention_start_date    DATE
--   custodian_id            INT
--   disposition_date        DATE
--   status                  VARCHAR(30)    active | due_review | archived | pending_disposal | disposed
--   archived_at / archive_reason
--   disposal_requested_by / disposal_requested_at
--   disposal_authorized_by / disposal_authorized_at   (must differ from requester - enforced in PHP)
--   disposed_at / disposal_reason
--   created_at / updated_at
-- =========================================================
