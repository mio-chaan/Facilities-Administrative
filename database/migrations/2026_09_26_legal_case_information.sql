-- Phase 6: optional external/legal case information.
-- Apply only to an existing database; fresh installs use database/schema.sql.

ALTER TABLE team8_legal_cases
    ADD COLUMN court_agency VARCHAR(200) NULL AFTER description,
    ADD COLUMN branch_office VARCHAR(150) NULL AFTER court_agency,
    ADD COLUMN docket_reference VARCHAR(150) NULL AFTER branch_office,
    ADD COLUMN jurisdiction VARCHAR(150) NULL AFTER docket_reference,
    ADD COLUMN location VARCHAR(200) NULL AFTER jurisdiction,
    ADD COLUMN legal_basis TEXT NULL AFTER location,
    ADD COLUMN current_action TEXT NULL AFTER legal_basis;