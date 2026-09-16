-- Persist the reason whenever an administrator rejects a document.
ALTER TABLE team8_documents
    ADD COLUMN review_reason TEXT NULL AFTER status;

ALTER TABLE team8_incident_reports
    ADD COLUMN rejection_reason TEXT NULL AFTER status;

ALTER TABLE team8_notice_to_explain
    ADD COLUMN rejection_reason TEXT NULL AFTER status;

ALTER TABLE team8_memorandums
    ADD COLUMN rejection_reason TEXT NULL AFTER status;

ALTER TABLE team8_certificates
    ADD COLUMN rejection_reason TEXT NULL AFTER status;
