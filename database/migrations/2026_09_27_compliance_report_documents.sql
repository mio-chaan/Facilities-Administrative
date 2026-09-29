CREATE TABLE IF NOT EXISTS team8_compliance_report_documents (
    compliance_report_id INT NOT NULL,
    document_id INT NOT NULL,
    attached_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (compliance_report_id, document_id),
    KEY idx_team8_compliance_report_documents_document (document_id),
    CONSTRAINT fk_team8_compliance_report_documents_report
        FOREIGN KEY (compliance_report_id) REFERENCES team8_compliance_reports(id) ON DELETE CASCADE,
    CONSTRAINT fk_team8_compliance_report_documents_document
        FOREIGN KEY (document_id) REFERENCES team8_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;