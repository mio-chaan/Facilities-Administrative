-- Normalized audiences for memorandums and warning letters.
CREATE TABLE IF NOT EXISTS team8_memorandum_recipients (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    memorandum_id  INT NOT NULL,
    recipient_type VARCHAR(30) NOT NULL,
    department_id  INT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_team8_memo_recipient_type CHECK (recipient_type IN ('all_departments', 'department')),
    CONSTRAINT fk_team8_memo_recipient_memo FOREIGN KEY (memorandum_id) REFERENCES team8_memorandums(id) ON DELETE CASCADE,
    CONSTRAINT fk_team8_memo_recipient_department FOREIGN KEY (department_id) REFERENCES departments(id),
    CONSTRAINT uq_team8_memo_recipient UNIQUE (memorandum_id, recipient_type, department_id)
) ENGINE=InnoDB;

