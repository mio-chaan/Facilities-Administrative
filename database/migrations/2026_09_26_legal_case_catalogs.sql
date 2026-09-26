-- Phase 1: legal case type/status catalogs and status-based case archiving.
-- Apply only to an existing database; fresh installs use database/schema.sql.

CREATE TABLE team8_legal_case_types (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    type_code   VARCHAR(60) NOT NULL,
    name        VARCHAR(100) NOT NULL,
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_team8_legal_case_types_code (type_code),
    UNIQUE KEY uq_team8_legal_case_types_name (name)
) ENGINE=InnoDB;

INSERT INTO team8_legal_case_types (type_code, name, sort_order) VALUES
    ('labor', 'Labor', 10),
    ('employee_legal_matter', 'Employee Legal Matter', 20),
    ('civil', 'Civil', 30),
    ('administrative', 'Administrative', 40),
    ('corporate_business', 'Corporate / Business', 50),
    ('government_regulatory', 'Government / Regulatory', 60),
    ('compliance', 'Compliance', 70),
    ('contract_dispute', 'Contract Dispute', 80),
    ('other', 'Other', 90);

CREATE TABLE team8_legal_case_statuses (
    status_code VARCHAR(30) PRIMARY KEY,
    name        VARCHAR(60) NOT NULL,
    sort_order  SMALLINT UNSIGNED NOT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

INSERT INTO team8_legal_case_statuses (status_code, name, sort_order) VALUES
    ('open', 'Open', 10),
    ('under_review', 'Under Review', 20),
    ('active', 'Active', 30),
    ('resolved', 'Resolved', 40),
    ('closed', 'Closed', 50),
    ('archived', 'Archived', 60);

-- Preserve unknown historical values without making them selectable.
INSERT INTO team8_legal_case_statuses (status_code, name, sort_order, is_active)
SELECT DISTINCT status, status, 65535, 0
FROM team8_legal_cases
WHERE status NOT IN ('open', 'under_review', 'active', 'resolved', 'closed', 'archived');

ALTER TABLE team8_legal_cases
    ADD COLUMN case_type_id INT NULL AFTER subject,
    ADD COLUMN archived_from_status VARCHAR(30) NULL AFTER status;

UPDATE team8_legal_cases
SET status = CASE status
    WHEN 'in_progress' THEN 'under_review'
    WHEN 'dismissed' THEN 'resolved'
    ELSE status
END;

SET @legal_other_type_id = (SELECT id FROM team8_legal_case_types WHERE type_code = 'other');
UPDATE team8_legal_cases SET case_type_id = @legal_other_type_id WHERE case_type_id IS NULL;

-- Preserve the current reversible archive behavior while moving its state
-- from deleted_at to the canonical archived status.
UPDATE team8_legal_cases
SET archived_from_status = status, status = 'archived'
WHERE deleted_at IS NOT NULL;

ALTER TABLE team8_legal_cases
    MODIFY COLUMN case_type_id INT NOT NULL,
    ADD CONSTRAINT fk_team8_legalcases_type FOREIGN KEY (case_type_id) REFERENCES team8_legal_case_types(id),
    ADD CONSTRAINT fk_team8_legalcases_status FOREIGN KEY (status) REFERENCES team8_legal_case_statuses(status_code),
    ADD CONSTRAINT fk_team8_legalcases_archived_status FOREIGN KEY (archived_from_status) REFERENCES team8_legal_case_statuses(status_code),
    DROP INDEX idx_team8_legalcases_deleted_status,
    DROP COLUMN deleted_at;

CREATE INDEX idx_team8_legalcases_type_active ON team8_legal_case_types(is_active, sort_order);
CREATE INDEX idx_team8_legalcases_status_order ON team8_legal_case_statuses(is_active, sort_order);