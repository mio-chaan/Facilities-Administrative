-- Phase 8: legal case hearings and proceedings.
-- Apply only to an existing database; fresh installs use database/schema.sql.

CREATE TABLE team8_legal_case_hearings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    case_id         INT NOT NULL,
    event_date      DATE NOT NULL,
    event_time      TIME NULL,
    venue           VARCHAR(200) NULL,
    hearing_type    VARCHAR(150) NOT NULL,
    purpose         VARCHAR(500) NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'scheduled',
    notes           TEXT NULL,
    created_by      INT NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_team8_legal_hearings_case_date (case_id, event_date, event_time),
    CONSTRAINT fk_team8_legal_hearings_case FOREIGN KEY (case_id) REFERENCES team8_legal_cases(id),
    CONSTRAINT fk_team8_legal_hearings_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT chk_team8_legal_hearings_status CHECK (status IN ('scheduled', 'completed', 'postponed', 'cancelled'))
) ENGINE=InnoDB;