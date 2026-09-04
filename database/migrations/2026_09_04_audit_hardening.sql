-- Audit hardening: facility locations and one-to-one HR workflow records.
-- Consolidate existing duplicates before applying this migration.

CREATE TABLE IF NOT EXISTS team8_facility_locations (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(150) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS team8_hr_document_sequences (
    prefix        VARCHAR(20) NOT NULL,
    document_year SMALLINT NOT NULL,
    next_number   INT NOT NULL,
    PRIMARY KEY (prefix, document_year)
) ENGINE=InnoDB;

ALTER TABLE team8_notice_to_explain
    ADD UNIQUE INDEX uq_team8_nte_incident (incident_report_id);

ALTER TABLE team8_explanations
    ADD UNIQUE INDEX uq_team8_explanation_nte (nte_id);