-- Phase 11: manual legal case communications linked to existing legal documents.
-- Apply only to an existing database; fresh installs use database/schema.sql.

CREATE TABLE team8_legal_case_communications (
    id                          INT AUTO_INCREMENT PRIMARY KEY,
    case_id                     INT NOT NULL,
    communication_date          DATE NOT NULL,
    communication_time          TIME NULL,
    communication_type          VARCHAR(100) NOT NULL,
    direction                   VARCHAR(20) NOT NULL,
    sender                      VARCHAR(200) NULL,
    recipient                   VARCHAR(200) NULL,
    subject                     VARCHAR(200) NULL,
    summary                     TEXT NOT NULL,
    attachment_legal_document_id INT NULL,
    recorded_by                 INT NOT NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_team8_legal_communications_case_date (case_id, communication_date, communication_time),
    CONSTRAINT fk_team8_legal_communications_case FOREIGN KEY (case_id) REFERENCES team8_legal_cases(id),
    CONSTRAINT fk_team8_legal_communications_document FOREIGN KEY (attachment_legal_document_id) REFERENCES team8_legal_documents(id),
    CONSTRAINT fk_team8_legal_communications_recorder FOREIGN KEY (recorded_by) REFERENCES users(id),
    CONSTRAINT chk_team8_legal_communications_direction CHECK (direction IN ('incoming', 'outgoing'))
) ENGINE=InnoDB;