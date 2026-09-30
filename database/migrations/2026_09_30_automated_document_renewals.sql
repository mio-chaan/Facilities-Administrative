CREATE TABLE IF NOT EXISTS daily_check_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    check_date DATE NOT NULL,
    t14_run_at DATETIME DEFAULT NULL,
    t7_run_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_daily_check_log_date (check_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS renewal_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    milestone ENUM('t14', 't7') NOT NULL,
    status ENUM('processing', 'processed') NOT NULL DEFAULT 'processing',
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_renewal_notification_document_milestone (document_id, milestone),
    KEY idx_renewal_notifications_sent_at (sent_at),
    CONSTRAINT fk_renewal_notifications_document
        FOREIGN KEY (document_id) REFERENCES team8_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE audit_logs MODIFY action VARCHAR(100) NOT NULL;