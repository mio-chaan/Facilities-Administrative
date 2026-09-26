-- Phase 2: legal case priority for list display and filtering.
-- Apply only to an existing database; fresh installs use database/schema.sql.

ALTER TABLE team8_legal_cases
    ADD COLUMN priority VARCHAR(20) NOT NULL DEFAULT 'medium' AFTER status;

UPDATE team8_legal_cases
SET priority = 'medium'
WHERE priority IS NULL OR priority = '';

CREATE INDEX idx_team8_legalcases_priority ON team8_legal_cases(priority, status);