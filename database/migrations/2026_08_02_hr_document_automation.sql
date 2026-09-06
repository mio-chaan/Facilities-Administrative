-- =========================================================
-- database/migrations/2026_08_02_hr_document_automation.sql
-- HR Document Automation extension for Document Management (Team 8)
--
-- Adds: Incident Reports, Notice To Explain, Explanation Letters,
-- Memorandums (also used for Warning Letters, see `kind` column),
-- Certificates, and one shared/polymorphic version-history table
-- used by all of the above.
--
-- Design notes:
--   - Every new table is prefixed team8_, per project convention.
--   - No employee names/departments are duplicated anywhere: every
--     table stores only FKs into the shared `users` / `departments`
--     tables and the display layer (modules/documents/hr/*.php)
--     always resolves names via JOIN at read time.
--   - Status is a single 5-value field (draft/pending/approved/
--     rejected/archived) per the spec — there is no separate
--     deleted_at on these tables; "archived" IS the soft-delete
--     state, driven entirely through the normal status workflow.
--   - Run this AFTER database/schema.sql. Safe to re-run — every
--     statement is IF NOT EXISTS / idempotent-guarded.
-- =========================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------
-- Incident Reports
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS team8_incident_reports (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    document_number     VARCHAR(40) NOT NULL UNIQUE,
    employee_id         INT NOT NULL,          -- subject/filer — ALWAYS the session user, never from POST
    prepared_by         INT NOT NULL,          -- ALWAYS the session user (see app/includes/hr_documents.php)
    department_id       INT NULL,              -- session-derived snapshot of the filer's department at filing time
    status              VARCHAR(30) NOT NULL DEFAULT 'pending', -- pending | approved | rejected | archived
    incident_date       DATE NOT NULL,
    incident_time       TIME NOT NULL,
    incident_location   VARCHAR(200) NOT NULL,
    incident_type       VARCHAR(100) NOT NULL,
    description         TEXT NOT NULL,
    witness              VARCHAR(200) NULL,
    attachment_path     VARCHAR(500) NULL,
    current_version     INT NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_team8_ir_employee   FOREIGN KEY (employee_id)   REFERENCES users(id),
    CONSTRAINT fk_team8_ir_preparer   FOREIGN KEY (prepared_by)   REFERENCES users(id),
    CONSTRAINT fk_team8_ir_department FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Notice To Explain (generated FROM an incident report)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS team8_notice_to_explain (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    document_number     VARCHAR(40) NOT NULL UNIQUE,
    incident_report_id  INT NOT NULL,
    employee_id         INT NOT NULL,          -- copied from the source incident report, not re-entered
    prepared_by         INT NOT NULL,          -- admin who generated it (session user)
    status              VARCHAR(30) NOT NULL DEFAULT 'pending', -- pending | approved | rejected | archived
    deadline            DATE NOT NULL,
    remarks             TEXT NULL,
    current_version     INT NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_team8_nte_incident FOREIGN KEY (incident_report_id) REFERENCES team8_incident_reports(id),
    CONSTRAINT fk_team8_nte_employee FOREIGN KEY (employee_id)        REFERENCES users(id),
    CONSTRAINT fk_team8_nte_preparer FOREIGN KEY (prepared_by)        REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Employee Explanation Letters (reply to an NTE)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS team8_explanations (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    nte_id              INT NOT NULL,
    employee_id         INT NOT NULL,          -- must equal the NTE's employee_id — enforced server-side against session
    explanation_text    TEXT NOT NULL,
    attachment_path     VARCHAR(500) NULL,
    status              VARCHAR(30) NOT NULL DEFAULT 'pending', -- pending | approved | rejected | archived
    admin_remarks       TEXT NULL,
    reviewed_by         INT NULL,
    reviewed_at         DATETIME NULL,
    submitted_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_team8_expl_nte      FOREIGN KEY (nte_id)      REFERENCES team8_notice_to_explain(id),
    CONSTRAINT fk_team8_expl_employee FOREIGN KEY (employee_id) REFERENCES users(id),
    CONSTRAINT fk_team8_expl_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Memorandums (kind = 'memorandum' | 'warning_letter' — the task
-- spec gives Warning Letter no distinct field set of its own, so it
-- reuses this table/form with a discriminator column instead of a
-- near-duplicate table).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS team8_memorandums (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    document_number     VARCHAR(40) NOT NULL UNIQUE,
    kind                VARCHAR(30) NOT NULL DEFAULT 'memorandum', -- memorandum | warning_letter
    title               VARCHAR(200) NOT NULL,
    recipients          VARCHAR(500) NOT NULL,
    content             TEXT NOT NULL,
    remarks             TEXT NULL,
    prepared_by         INT NOT NULL,          -- always the session admin user
    status              VARCHAR(30) NOT NULL DEFAULT 'draft',
    current_version     INT NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_team8_memo_preparer FOREIGN KEY (prepared_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Certificates
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS team8_certificates (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    document_number     VARCHAR(40) NOT NULL UNIQUE,
    certificate_type    VARCHAR(50) NOT NULL,  -- employment | recognition | attendance
    employee_id         INT NOT NULL,          -- recipient, chosen by the admin from the users directory
    prepared_by         INT NOT NULL,          -- always the session admin user
    details             TEXT NULL,             -- free-text purpose/remarks specific to the certificate
    status              VARCHAR(30) NOT NULL DEFAULT 'draft',
    current_version     INT NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_team8_cert_employee FOREIGN KEY (employee_id) REFERENCES users(id),
    CONSTRAINT fk_team8_cert_preparer FOREIGN KEY (prepared_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Shared, polymorphic version history.
-- Editing a document that is already 'approved' must never overwrite
-- the approved row in place — the controller snapshots the pre-edit
-- row here first (see t8_hr_save_version() in
-- app/includes/hr_documents.php), then modifies the row and bumps
-- current_version. Drafts/pending rows are simply updated in place
-- (no snapshot needed until something has actually been approved).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS team8_hr_document_versions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    doc_type    VARCHAR(30) NOT NULL, -- incident_report | nte | memorandum | certificate
    doc_id      INT NOT NULL,
    version_no  INT NOT NULL,
    data_json   TEXT NOT NULL,        -- full snapshot of the row before this edit
    created_by  INT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_team8_hrver_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT uq_team8_hrver UNIQUE (doc_type, doc_id, version_no)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Indexes for common lookups / dashboard stat queries
-- ---------------------------------------------------------
CREATE INDEX idx_team8_ir_status     ON team8_incident_reports(status);
CREATE INDEX idx_team8_ir_employee   ON team8_incident_reports(employee_id);
CREATE INDEX idx_team8_nte_status    ON team8_notice_to_explain(status);
CREATE INDEX idx_team8_nte_incident  ON team8_notice_to_explain(incident_report_id);
CREATE INDEX idx_team8_expl_status   ON team8_explanations(status);
CREATE INDEX idx_team8_expl_nte      ON team8_explanations(nte_id);
CREATE INDEX idx_team8_memo_status   ON team8_memorandums(status);
CREATE INDEX idx_team8_memo_kind     ON team8_memorandums(kind);
CREATE INDEX idx_team8_cert_status   ON team8_certificates(status);
CREATE INDEX idx_team8_cert_type     ON team8_certificates(certificate_type);
CREATE INDEX idx_team8_hrver_lookup  ON team8_hr_document_versions(doc_type, doc_id);

SET FOREIGN_KEY_CHECKS = 1;
