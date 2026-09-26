-- Phase 3: generated legal case numbers and the minimum case-creation details.
-- Apply only to an existing database; fresh installs use database/schema.sql.

ALTER TABLE team8_legal_cases
    ADD COLUMN case_number VARCHAR(20) NULL AFTER id,
    ADD COLUMN supporting_staff_id INT NULL AFTER assigned_to,
    ADD COLUMN description TEXT NULL AFTER subject,
    ADD COLUMN next_action_date DATE NULL AFTER deadline,
    ADD COLUMN closing_date DATE NULL AFTER next_action_date;

-- Give existing rows stable LC-YYYY-NNN numbers before requiring the column.
-- Their IDs are globally unique; sequence rows below continue past each year's
-- highest existing ID to avoid collisions with those legacy numbers.
UPDATE team8_legal_cases
SET case_number = CONCAT('LC-', YEAR(created_at), '-', LPAD(id, 3, '0'))
WHERE case_number IS NULL;

CREATE TABLE team8_legal_case_number_sequences (
    case_year   SMALLINT UNSIGNED PRIMARY KEY,
    last_number INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

INSERT INTO team8_legal_case_number_sequences (case_year, last_number)
SELECT YEAR(created_at), MAX(id)
FROM team8_legal_cases
GROUP BY YEAR(created_at);

ALTER TABLE team8_legal_cases
    MODIFY COLUMN case_number VARCHAR(20) NOT NULL,
    ADD UNIQUE KEY uq_team8_legalcases_case_number (case_number),
    ADD CONSTRAINT fk_team8_legalcases_support_staff
        FOREIGN KEY (supporting_staff_id) REFERENCES users(id);