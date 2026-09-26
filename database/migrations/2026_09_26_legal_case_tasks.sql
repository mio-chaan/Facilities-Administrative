-- Phase 7: legal case tasks and deadlines.
-- Overdue is computed at read time and is not a persisted task status.
-- Apply only to an existing database; fresh installs use database/schema.sql.

CREATE TABLE team8_legal_case_tasks (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    case_id         INT NOT NULL,
    title           VARCHAR(200) NOT NULL,
    description     TEXT NULL,
    assigned_to     INT NOT NULL,
    created_by      INT NOT NULL,
    due_date        DATE NOT NULL,
    priority        VARCHAR(20) NOT NULL DEFAULT 'medium',
    status          VARCHAR(20) NOT NULL DEFAULT 'pending',
    completed_at    DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_team8_legal_tasks_case_due (case_id, due_date, status),
    INDEX idx_team8_legal_tasks_assignee_due (assigned_to, due_date, status),
    CONSTRAINT fk_team8_legal_tasks_case FOREIGN KEY (case_id) REFERENCES team8_legal_cases(id),
    CONSTRAINT fk_team8_legal_tasks_assignee FOREIGN KEY (assigned_to) REFERENCES users(id),
    CONSTRAINT fk_team8_legal_tasks_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT chk_team8_legal_tasks_priority CHECK (priority IN ('low', 'medium', 'high', 'urgent')),
    CONSTRAINT chk_team8_legal_tasks_status CHECK (status IN ('pending', 'in_progress', 'completed', 'cancelled'))
) ENGINE=InnoDB;