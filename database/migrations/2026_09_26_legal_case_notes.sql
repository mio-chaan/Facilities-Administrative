-- Phase 10: private, append-only notes linked to legal cases.
-- Apply only to an existing database; fresh installs use database/schema.sql.

CREATE TABLE team8_legal_case_notes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    case_id     INT NOT NULL,
    author_id   INT NOT NULL,
    content     TEXT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_team8_legal_case_notes_case_date (case_id, created_at),
    CONSTRAINT fk_team8_legal_case_notes_case FOREIGN KEY (case_id) REFERENCES team8_legal_cases(id),
    CONSTRAINT fk_team8_legal_case_notes_author FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB;