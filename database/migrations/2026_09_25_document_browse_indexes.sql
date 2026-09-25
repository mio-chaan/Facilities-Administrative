-- Composite indexes for the unified document browse filters and ordering.
ALTER TABLE team8_documents
    ADD INDEX idx_team8_documents_browse_owner (uploaded_by, status, deleted_at, updated_at, id),
    ADD INDEX idx_team8_documents_browse_category (category_id, status, deleted_at, updated_at, id),
    ADD INDEX idx_team8_documents_browse_deleted (deleted_at, updated_at, id);

ALTER TABLE team8_incident_reports
    ADD INDEX idx_team8_ir_browse (status, employee_id, updated_at, id);

ALTER TABLE team8_notice_to_explain
    ADD INDEX idx_team8_nte_browse (status, employee_id, updated_at, id);

ALTER TABLE team8_explanations
    ADD INDEX idx_team8_expl_browse (status, employee_id, updated_at, id);

ALTER TABLE team8_memorandums
    ADD INDEX idx_team8_memo_browse (status, kind, updated_at, id);

ALTER TABLE team8_memorandum_recipients
    ADD INDEX idx_team8_memo_recipient_browse (memorandum_id, department_id, recipient_type);

ALTER TABLE team8_certificates
    ADD INDEX idx_team8_cert_browse (status, employee_id, updated_at, id);
