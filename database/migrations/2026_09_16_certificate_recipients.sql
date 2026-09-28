-- Normalized recipients for certificates. Existing single-recipient
-- certificates are copied into the new table during the upgrade.
CREATE TABLE IF NOT EXISTS team8_certificate_recipients (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    certificate_id INT NOT NULL,
    employee_id    INT NOT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_team8_cert_recipient_certificate FOREIGN KEY (certificate_id) REFERENCES team8_certificates(id) ON DELETE CASCADE,
    CONSTRAINT fk_team8_cert_recipient_employee FOREIGN KEY (employee_id) REFERENCES users(id),
    CONSTRAINT uq_team8_cert_recipient UNIQUE (certificate_id, employee_id)
) ENGINE=InnoDB;

CREATE INDEX idx_team8_cert_recipient_employee ON team8_certificate_recipients (employee_id);

INSERT IGNORE INTO team8_certificate_recipients (certificate_id, employee_id)
SELECT id, employee_id
FROM team8_certificates
WHERE employee_id IS NOT NULL;