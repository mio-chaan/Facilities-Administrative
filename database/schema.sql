-- =========================================================
-- TEAM 8 — FACILITIES & ADMINISTRATIVE MANAGEMENT
-- Subsystem schema (integrates into shared capstone database)
--


SET FOREIGN_KEY_CHECKS = 0;

-- =========================================================
-- SHARED CORE TABLES (placeholder — replace with real shared schema)
-- =========================================================

CREATE TABLE IF NOT EXISTS departments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    department_id   INT NULL,
    full_name       VARCHAR(150) NOT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME NULL,
    CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS roles (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    role_name   VARCHAR(100) NOT NULL UNIQUE,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_roles (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    role_id     INT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    message     VARCHAR(500) NOT NULL,
    target_url  VARCHAR(500) NULL,
    status      VARCHAR(30) NOT NULL DEFAULT 'unread',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    entity_id   INT NOT NULL,
    action      VARCHAR(50) NOT NULL,
    old_value   TEXT NULL,
    new_value   TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- QA FIX: dedicated persistent login-throttle table (login.php previously
-- rate-limited by $_SESSION, which is trivially bypassed by dropping
-- cookies — see database/migrations/2026_07_29_qa_fixes.sql / H1).
CREATE TABLE IF NOT EXISTS team8_login_throttle (
    identifier      VARCHAR(191) PRIMARY KEY, -- lowercased email
    attempts        INT NOT NULL DEFAULT 0,
    locked_until    DATETIME NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;


-- =========================================================
-- MODULE: FACILITIES RESERVATION
-- =========================================================

CREATE TABLE team8_facilities (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(150) NOT NULL,
    location      VARCHAR(200) NOT NULL,
    facility_type VARCHAR(100) NULL,
    capacity      INT NOT NULL DEFAULT 1,
    description   TEXT NULL,
    equipment_notes TEXT NULL,
    maintenance_status VARCHAR(30) NOT NULL DEFAULT 'operational',
    status        ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_team8_facilities_capacity CHECK (capacity >= 1)
) ENGINE=InnoDB;

CREATE TABLE team8_facility_maintenance_history (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    facility_id   INT NOT NULL,
    performed_by  INT NOT NULL,
    maintenance_date DATE NOT NULL,
    notes         TEXT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_t8_maintenance_facility FOREIGN KEY (facility_id) REFERENCES team8_facilities(id),
    CONSTRAINT fk_t8_maintenance_user FOREIGN KEY (performed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE team8_equipment (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    home_facility_id INT NULL,
    name            VARCHAR(150) NOT NULL,
    quantity        INT NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_team8_equipment_quantity CHECK (quantity >= 0),
    CONSTRAINT fk_team8_equipment_facility FOREIGN KEY (home_facility_id) REFERENCES team8_facilities(id)
) ENGINE=InnoDB;

CREATE TABLE team8_reservations (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    facility_id             INT NOT NULL,
    user_id                 INT NOT NULL,
    start_time              DATETIME NULL,
    end_time                DATETIME NULL,
    status                  VARCHAR(30) NOT NULL DEFAULT 'pending', -- pending | approved | rejected | cancellation_pending | cancelled | completed | expired
    department              VARCHAR(150) NULL,
    key_person              VARCHAR(150) NULL,
    expected_participants   INT NULL,
    quantity                INT NULL,
    event_category          VARCHAR(100) NULL,
    description             VARCHAR(500) NULL,
    expected_return_date    DATE NULL,
    remarks                 VARCHAR(500) NULL,
    schedule                DATETIME NULL,
    requirements            VARCHAR(500) NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    archived_at             DATETIME NULL,
    cancellation_reason     TEXT NULL,
    cancellation_requested_by INT NULL,
    cancellation_requested_at DATETIME NULL,
    cancellation_reviewed_by INT NULL,
    cancellation_reviewed_at DATETIME NULL,
    cancellation_decision   VARCHAR(30) NULL,
    deleted_at              DATETIME NULL,
    CONSTRAINT chk_team8_reservations_participants CHECK (expected_participants IS NULL OR expected_participants > 0),
    CONSTRAINT chk_team8_reservations_quantity CHECK (quantity IS NULL OR quantity > 0),
    CONSTRAINT fk_team8_reservations_facility FOREIGN KEY (facility_id) REFERENCES team8_facilities(id),
    CONSTRAINT fk_team8_reservations_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_team8_reservations_cancel_requester FOREIGN KEY (cancellation_requested_by) REFERENCES users(id),
    CONSTRAINT fk_team8_reservations_cancel_reviewer FOREIGN KEY (cancellation_reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE team8_reservation_equipment (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id  INT NOT NULL,
    equipment_id    INT NOT NULL,
    quantity        INT NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_team8_resequip_reservation FOREIGN KEY (reservation_id) REFERENCES team8_reservations(id),
    CONSTRAINT fk_team8_resequip_equipment FOREIGN KEY (equipment_id) REFERENCES team8_equipment(id)
) ENGINE=InnoDB;

CREATE TABLE team8_reservation_cancellation_requests (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id  INT NOT NULL,
    requested_by    INT NOT NULL,
    reason          TEXT NOT NULL,
    status          VARCHAR(30) NOT NULL DEFAULT 'pending',
    requested_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by     INT NULL,
    reviewed_at     DATETIME NULL,
    admin_remark    TEXT NULL,
    CONSTRAINT fk_team8_cancel_request_reservation FOREIGN KEY (reservation_id) REFERENCES team8_reservations(id),
    CONSTRAINT fk_team8_cancel_request_requester FOREIGN KEY (requested_by) REFERENCES users(id),
    CONSTRAINT fk_team8_cancel_request_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE team8_reservation_approvals (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id  INT NOT NULL,
    approver_id     INT NOT NULL,
    step_order      INT NOT NULL DEFAULT 1,
    status          VARCHAR(30) NOT NULL DEFAULT 'pending',
    remarks         TEXT NULL,
    decided_at      DATETIME NULL,
    CONSTRAINT fk_team8_resapproval_reservation FOREIGN KEY (reservation_id) REFERENCES team8_reservations(id),
    CONSTRAINT fk_team8_resapproval_approver FOREIGN KEY (approver_id) REFERENCES users(id)
) ENGINE=InnoDB;


-- =========================================================
-- MODULE: VISITOR MANAGEMENT
-- =========================================================

CREATE TABLE team8_visitors (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    full_name               VARCHAR(150) NOT NULL,
    visitor_type            VARCHAR(100) NULL,
    contact                 VARCHAR(30) NULL,
    company                 VARCHAR(150) NULL,
    person_to_visit         VARCHAR(150) NULL,
    purpose                 VARCHAR(255) NOT NULL,
    scheduled_date          DATETIME NOT NULL,
    status                  VARCHAR(30) NOT NULL DEFAULT 'scheduled', -- scheduled | checked_in | checked_out | cancelled | expired
    check_in_time           DATETIME NULL,
    check_out_time          DATETIME NULL,
    logged_by               INT NOT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_team8_visitors_logger FOREIGN KEY (logged_by) REFERENCES users(id)
) ENGINE=InnoDB;


-- =========================================================
-- MODULE: DOCUMENT MANAGEMENT
-- =========================================================

CREATE TABLE team8_document_categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE team8_documents (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    category_id     INT NULL,
    document_type   VARCHAR(150) NULL,
    department_id   INT NULL,
    owner_id        INT NULL,
    uploaded_by     INT NOT NULL,
    title           VARCHAR(200) NOT NULL,
    file_path       VARCHAR(500) NOT NULL, -- path of CURRENT version
    current_version INT NOT NULL DEFAULT 1,
    status          VARCHAR(30) NOT NULL DEFAULT 'pending', -- pending | approved | returned_for_revision
    expiration_date DATE NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME NULL,
    CONSTRAINT fk_team8_documents_category FOREIGN KEY (category_id) REFERENCES team8_document_categories(id),
    CONSTRAINT fk_team8_documents_department FOREIGN KEY (department_id) REFERENCES departments(id),
    CONSTRAINT fk_team8_documents_owner FOREIGN KEY (owner_id) REFERENCES users(id),
    CONSTRAINT fk_team8_documents_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE team8_document_versions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    version_no  INT NOT NULL,
    file_path   VARCHAR(500) NOT NULL, -- path of THIS specific version (patch)
    file_size   BIGINT NOT NULL DEFAULT 0,
    checksum    VARCHAR(128) NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_team8_docversions_version CHECK (version_no >= 1),
    CONSTRAINT chk_team8_docversions_file_size CHECK (file_size >= 0),
    CONSTRAINT fk_team8_docversions_document FOREIGN KEY (document_id) REFERENCES team8_documents(id),
    -- QA FIX (M2): closes a race condition where two concurrent
    -- "upload new version" requests could both compute and insert the
    -- same version_no.
    CONSTRAINT uq_team8_docversions_doc_version UNIQUE (document_id, version_no)
) ENGINE=InnoDB;


-- =========================================================
-- MODULE: RECORDS RETENTION & COMPLIANCE
-- =========================================================

CREATE TABLE team8_retention_schedules (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    record_type     VARCHAR(150) NOT NULL,
    retention_years INT NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_team8_retention_years CHECK (retention_years >= 1),
    -- QA FIX (L4): prevents duplicate schedules for the same record type.
    CONSTRAINT uq_team8_retention_record_type UNIQUE (record_type)
) ENGINE=InnoDB;

CREATE TABLE team8_records (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    document_id     INT NOT NULL,
    schedule_id     INT NOT NULL,
    custodian_id    INT NOT NULL,
    disposition_date DATE NULL,
    status          VARCHAR(30) NOT NULL DEFAULT 'active', -- active | archived | for_disposal | disposed
    archived_at     DATETIME NULL,
    archive_reason  VARCHAR(500) NULL,
    disposed_at     DATETIME NULL,
    disposal_reason VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME NULL,
    CONSTRAINT fk_team8_records_document FOREIGN KEY (document_id) REFERENCES team8_documents(id),
    CONSTRAINT fk_team8_records_schedule FOREIGN KEY (schedule_id) REFERENCES team8_retention_schedules(id),
    CONSTRAINT fk_team8_records_custodian FOREIGN KEY (custodian_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE team8_compliance_checks (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    record_id   INT NOT NULL,
    checked_by  INT NOT NULL,
    check_date  DATE NOT NULL,
    result      VARCHAR(30) NOT NULL, -- compliant | non_compliant | needs_review
    notes       VARCHAR(500) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_team8_compliance_record FOREIGN KEY (record_id) REFERENCES team8_records(id),
    CONSTRAINT fk_team8_compliance_checker FOREIGN KEY (checked_by) REFERENCES users(id)
) ENGINE=InnoDB;


-- =========================================================
-- MODULE: LEGAL MANAGEMENT
-- =========================================================

CREATE TABLE team8_legal_cases (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    assigned_to INT NOT NULL,
    contract_id INT NULL, -- FK added after team8_contracts is created (see below)
    title       VARCHAR(200) NOT NULL,
    subject     VARCHAR(200) NULL,
    department_id INT NULL,
    status      VARCHAR(30) NOT NULL DEFAULT 'open',
    filed_date  DATE NOT NULL,
    deadline    DATE NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  DATETIME NULL,
    CONSTRAINT fk_team8_legalcases_assignee FOREIGN KEY (assigned_to) REFERENCES users(id),
    CONSTRAINT fk_team8_legalcases_department FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB;

CREATE TABLE team8_legal_documents (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    case_id     INT NOT NULL,
    document_id INT NOT NULL,
    description VARCHAR(500) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_team8_legaldocs_case FOREIGN KEY (case_id) REFERENCES team8_legal_cases(id),
    CONSTRAINT fk_team8_legaldocs_document FOREIGN KEY (document_id) REFERENCES team8_documents(id)
) ENGINE=InnoDB;


-- =========================================================
-- MODULE: CONTRACT MANAGEMENT
-- =========================================================

CREATE TABLE team8_contracts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    owner_id        INT NOT NULL,
    department_id   INT NULL,
    renewed_from_id INT NULL,
    title           VARCHAR(200) NOT NULL,
    start_date      DATE NOT NULL,
    end_date        DATE NULL,
    renewal_date    DATE NULL,
    amount          DECIMAL(14,2) NULL,
    status          VARCHAR(30) NOT NULL DEFAULT 'draft',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME NULL,
    CONSTRAINT fk_team8_contracts_owner FOREIGN KEY (owner_id) REFERENCES users(id),
    CONSTRAINT fk_team8_contracts_department FOREIGN KEY (department_id) REFERENCES departments(id),
    CONSTRAINT fk_team8_contracts_renewed FOREIGN KEY (renewed_from_id) REFERENCES team8_contracts(id)
) ENGINE=InnoDB;

CREATE TABLE team8_parties (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(200) NOT NULL,
    type            VARCHAR(50) NOT NULL, -- e.g. individual | organization
    contact_email   VARCHAR(150) NULL,
    contact_phone   VARCHAR(50) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE team8_contract_parties (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    contract_id     INT NOT NULL,
    party_id        INT NOT NULL,
    role_in_contract VARCHAR(100) NOT NULL, -- e.g. vendor, client, witness
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_team8_contractparties_contract FOREIGN KEY (contract_id) REFERENCES team8_contracts(id),
    CONSTRAINT fk_team8_contractparties_party FOREIGN KEY (party_id) REFERENCES team8_parties(id)
) ENGINE=InnoDB;

CREATE TABLE team8_contract_documents (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    document_id INT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_team8_contractdocs_contract FOREIGN KEY (contract_id) REFERENCES team8_contracts(id),
    CONSTRAINT fk_team8_contractdocs_document FOREIGN KEY (document_id) REFERENCES team8_documents(id)
) ENGINE=InnoDB;

CREATE TABLE team8_contract_history (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    version_no  INT NOT NULL,
    data_json   LONGTEXT NOT NULL,
    changed_by  INT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_team8_contract_history UNIQUE (contract_id, version_no),
    CONSTRAINT fk_team8_contracthistory_contract FOREIGN KEY (contract_id) REFERENCES team8_contracts(id),
    CONSTRAINT fk_team8_contracthistory_user FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE team8_contract_obligations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    description VARCHAR(500) NOT NULL,
    due_date    DATE NULL,
    status      VARCHAR(30) NOT NULL DEFAULT 'pending',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_team8_contractobl_contract FOREIGN KEY (contract_id) REFERENCES team8_contracts(id)
) ENGINE=InnoDB;

-- Now that team8_contracts exists, attach the deferred FK from legal_cases
ALTER TABLE team8_legal_cases
    ADD CONSTRAINT fk_team8_legalcases_contract FOREIGN KEY (contract_id) REFERENCES team8_contracts(id);


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
-- app/includes/hr_documents.php), THEN updates the row and bumps
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
    CONSTRAINT chk_team8_hrver_version CHECK (version_no >= 1),
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


-- =========================================================
-- INDEXES (beyond PK/FK auto-indexes) for common lookups
-- =========================================================

CREATE UNIQUE INDEX uq_user_roles_user_role ON user_roles(user_id, role_id);
CREATE INDEX idx_notifications_user_status ON notifications(user_id, status, created_at);
CREATE INDEX idx_team8_reservations_status ON team8_reservations(status);
CREATE INDEX idx_team8_reservations_dates ON team8_reservations(start_time, end_time);
CREATE INDEX idx_team8_reservations_deleted_at ON team8_reservations(deleted_at);
CREATE INDEX idx_team8_reservations_archived_at ON team8_reservations(archived_at);
CREATE INDEX idx_team8_reservations_cancellation_status ON team8_reservations(status, cancellation_requested_at);
CREATE INDEX idx_team8_facilities_status ON team8_facilities(status);
CREATE INDEX idx_team8_maintenance_facility_date ON team8_facility_maintenance_history(facility_id, maintenance_date);
CREATE UNIQUE INDEX uq_team8_reservation_equipment ON team8_reservation_equipment(reservation_id, equipment_id);
CREATE INDEX idx_team8_cancel_request_pending ON team8_reservation_cancellation_requests(status, requested_at);
CREATE INDEX idx_team8_visitors_status ON team8_visitors(status);
CREATE INDEX idx_team8_visitors_status_scheduled ON team8_visitors(status, scheduled_date);
CREATE INDEX idx_team8_visitors_scheduled ON team8_visitors(scheduled_date);
CREATE INDEX idx_team8_documents_title ON team8_documents(title);
CREATE INDEX idx_team8_documents_status ON team8_documents(status);
CREATE INDEX idx_team8_documents_expiration ON team8_documents(expiration_date);
CREATE INDEX idx_team8_records_status ON team8_records(status);
CREATE INDEX idx_team8_records_status_deleted ON team8_records(status, deleted_at);
CREATE INDEX idx_team8_records_disposition_date ON team8_records(disposition_date);
CREATE INDEX idx_team8_legalcases_status ON team8_legal_cases(status);
CREATE INDEX idx_team8_legalcases_deleted_status ON team8_legal_cases(deleted_at, status);
CREATE INDEX idx_team8_legal_cases_assignee ON team8_legal_cases(assigned_to, status);
CREATE INDEX idx_team8_contracts_status ON team8_contracts(status);
CREATE INDEX idx_team8_contracts_deleted_status ON team8_contracts(deleted_at, status);
CREATE INDEX idx_team8_contracts_enddate ON team8_contracts(end_date);
CREATE INDEX idx_team8_contracts_department ON team8_contracts(department_id, status);
CREATE INDEX idx_team8_contractobl_duedate ON team8_contract_obligations(due_date);
CREATE UNIQUE INDEX uq_team8_legal_documents_case_document ON team8_legal_documents(case_id, document_id);
CREATE UNIQUE INDEX uq_team8_contract_documents_contract_document ON team8_contract_documents(contract_id, document_id);
CREATE UNIQUE INDEX uq_team8_contract_parties_contract_party_role ON team8_contract_parties(contract_id, party_id, role_in_contract);

SET FOREIGN_KEY_CHECKS = 1;
