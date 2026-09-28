ALTER TABLE team8_records
    ADD COLUMN archived_at DATETIME NULL AFTER status,
    ADD COLUMN archive_reason VARCHAR(500) NULL AFTER archived_at,
    ADD COLUMN disposed_at DATETIME NULL AFTER archive_reason,
    ADD COLUMN disposal_reason VARCHAR(500) NULL AFTER disposed_at;

ALTER TABLE team8_legal_cases
    ADD COLUMN subject VARCHAR(200) NULL AFTER title,
    ADD COLUMN department_id INT NULL AFTER subject,
    ADD COLUMN deadline DATE NULL AFTER filed_date,
    ADD CONSTRAINT fk_team8_legalcases_department FOREIGN KEY (department_id) REFERENCES departments(id);

CREATE INDEX idx_team8_records_disposition_date ON team8_records (disposition_date);
CREATE INDEX idx_team8_legal_cases_assignee ON team8_legal_cases (assigned_to, status);

UPDATE team8_legal_cases SET status = 'under_review' WHERE status = 'in_progress';
UPDATE team8_legal_cases SET status = 'resolved' WHERE status = 'dismissed';
