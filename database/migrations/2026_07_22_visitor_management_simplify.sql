-- =========================================================
-- database/migrations/2026_07_22_visitor_management_simplify.sql
-- Visitor Management (Milestone 4) simplification for a single-store
-- small business — drop the ID-number requirement entirely, add
-- `company`, and support pre-registration by a host with an expected
-- date/time.
--
-- Only needed if you're updating an EXISTING database that already
-- ran an older copy of schema.sql. A fresh
-- `mysql < database/schema.sql` import already has these shapes.
-- =========================================================

-- team8_visitors: drop id_number, add company
ALTER TABLE team8_visitors
    DROP COLUMN id_number,
    ADD COLUMN company VARCHAR(150) NULL AFTER contact;

-- team8_visits: add expected_at + updated_at, make purpose required,
-- and widen status to allow 'cancelled' (host-initiated, only while
-- still 'expected'). If you already have rows with purpose = NULL or
-- expected_at unset, backfill them before running the NOT NULL
-- changes below (fresh/dev databases can skip straight to the ALTERs).
UPDATE team8_visits SET purpose = 'Not specified' WHERE purpose IS NULL OR purpose = '';

ALTER TABLE team8_visits
    ADD COLUMN expected_at DATETIME NULL AFTER host_id,
    MODIFY COLUMN purpose VARCHAR(255) NOT NULL,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- Backfill again now the column exists (MySQL doesn't allow referencing
-- a column being added in the same ALTER's UPDATE), then lock it NOT NULL.
UPDATE team8_visits SET expected_at = created_at WHERE expected_at IS NULL;
ALTER TABLE team8_visits MODIFY COLUMN expected_at DATETIME NOT NULL;

CREATE INDEX idx_team8_visits_expected ON team8_visits(expected_at);
