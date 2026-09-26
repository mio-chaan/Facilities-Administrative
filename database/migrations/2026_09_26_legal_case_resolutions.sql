-- Phase 14: one structured resolution record per legal case.
-- Apply only to an existing database; fresh installs use database/schema.sql.

CREATE TABLE team8_legal_case_resolutions (
    id                           INT AUTO_INCREMENT PRIMARY KEY,
    case_id                      INT NOT NULL,
    resolution_type              VARCHAR(100) NOT NULL,
    resolution_summary           TEXT NOT NULL,
    resolved_date                DATE NOT NULL,
    final_outcome                TEXT NULL,
    supporting_legal_document_id INT NULL,
    recorded_by                  INT NOT NULL,
    created_at                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_team8_legal_case_resolution (case_id),
    CONSTRAINT fk_team8_legal_resolution_case FOREIGN KEY (case_id) REFERENCES team8_legal_cases(id),
    CONSTRAINT fk_team8_legal_resolution_document FOREIGN KEY (supporting_legal_document_id) REFERENCES team8_legal_documents(id),
    CONSTRAINT fk_team8_legal_resolution_recorder FOREIGN KEY (recorded_by) REFERENCES users(id),
    CONSTRAINT chk_team8_legal_resolution_type CHECK (resolution_type IN ('Settled', 'Dismissed', 'Resolved Internally', 'Government Decision', 'Court Decision', 'Compliance Completed', 'Other'))
) ENGINE=InnoDB;