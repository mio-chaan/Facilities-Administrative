-- =========================================================
-- TEAM 8 — FACILITIES & ADMINISTRATIVE MANAGEMENT
-- Subsystem schema (integrates into shared capstone database)
-- =========================================================
-- REGENERATED 2026-07-24: previous schema.sql had drifted from the
-- actual live shared database (database/capstone_shared_db.sql) and
-- from the application code — a fresh import of the old file would
-- have crashed Visitor Management and Facilities Reservation
-- immediately. This version is generated FROM the live DB dump
-- (source of truth), not the other way around. See the review notes
-- for the full list of what changed:
--   - team8_visitors: replaced with the columns actually in use
--     (person_to_visit, purpose, check_in_time, check_out_time,
--     logged_by). id_number kept (still live, never dropped).
--   - team8_reservations: `description` added (matches live DB and
--     modules/reservation/index.php — NOT `purpose`, which an older
--     migration added but was never actually applied anywhere).
--   - team8_reservations: delete-request-workflow columns added
--     (previous_status, delete_reason, delete_requested_by,
--     delete_requested_at, rejection_reason) — NOT yet in the live
--     DB as of this regeneration. Included here because the feature
--     is being built against this schema next; run
--     database/migrations/2026_07_22_reservation_delete_workflow.sql
--     against the live shared DB to bring it up to date with this
--     file before that feature goes live.
--   - team8_reservation_approvals: NO `remarks` column — an older
--     migration/doc claimed one was added, but the live DB and the
--     current code both agree it was never actually added. Left out
--     here to match reality; add it back via a real migration if/when
--     the code starts using it.
-- =========================================================
-- NOTE: The "SHARED CORE TABLES" section below is NOT owned by Team 8.
-- It is included here only so this file can run standalone during
-- development/testing (e.g. on a local machine or XAMPP).
-- Before final integration, DROP this section and point the
-- foreign keys at the actual shared tables already created by
-- whichever team/adviser owns system-wide auth.
-- =========================================================

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


-- =========================================================
-- MODULE: FACILITIES RESERVATION
-- =========================================================

-- Facilities are archived, never deleted, since team8_reservations.
-- facility_id and team8_equipment.home_facility_id both hold FKs
-- into this table. Matches live DB / modules/facilities/index.php.
CREATE TABLE team8_facilities (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150) NOT NULL,
    location    VARCHAR(200) NOT NULL,
    capacity    INT NOT NULL DEFAULT 0,
    description TEXT NULL,
    status      ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE team8_equipment (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    home_facility_id INT NULL,
    name            VARCHAR(150) NOT NULL,
    quantity        INT NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_team8_equipment_facility FOREIGN KEY (home_facility_id) REFERENCES team8_facilities(id)
) ENGINE=InnoDB;

-- `description` matches live DB + modules/reservation/index.php.
-- The five delete-request-workflow columns below (previous_status
-- through rejection_reason) are NOT yet in the live shared DB — run
-- database/migrations/2026_07_22_reservation_delete_workflow.sql
-- against it before shipping that feature. They're included here so
-- a fresh install/import already has them.
CREATE TABLE team8_reservations (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    facility_id           INT NOT NULL,
    user_id               INT NOT NULL,
    start_time            DATETIME NOT NULL,
    end_time              DATETIME NOT NULL,
    status                VARCHAR(30) NOT NULL DEFAULT 'pending',
    previous_status       VARCHAR(30) NULL,
    description           VARCHAR(255) NULL,
    delete_reason         TEXT NULL,
    delete_requested_by   INT NULL,
    delete_requested_at   DATETIME NULL,
    rejection_reason      TEXT NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at            DATETIME NULL,
    CONSTRAINT fk_team8_reservations_facility FOREIGN KEY (facility_id) REFERENCES team8_facilities(id),
    CONSTRAINT fk_team8_reservations_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_team8_reservations_delete_requester FOREIGN KEY (delete_requested_by) REFERENCES users(id)
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

-- NOTE: kept as a single-step approval table by design decision -
-- one row per reservation decision (step_order = 1, approver = the
-- Administrator), even though the schema supports multi-step chains.
-- No `remarks` column — matches live DB and current code; an earlier
-- migration/doc claiming one exists was never actually applied.
CREATE TABLE team8_reservation_approvals (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id  INT NOT NULL,
    approver_id     INT NOT NULL,
    step_order      INT NOT NULL DEFAULT 1,
    status          VARCHAR(30) NOT NULL DEFAULT 'pending',
    decided_at      DATETIME NULL,
    CONSTRAINT fk_team8_resapproval_reservation FOREIGN KEY (reservation_id) REFERENCES team8_reservations(id),
    CONSTRAINT fk_team8_resapproval_approver FOREIGN KEY (approver_id) REFERENCES users(id)
) ENGINE=InnoDB;


-- =========================================================
-- MODULE: VISITOR MANAGEMENT
-- =========================================================

-- Matches live DB + modules/visitor/index.php exactly: a single
-- check-in/check-out log row per visit, no separate "visit" record.
-- id_number stays (optional, still live — never dropped despite an
-- old unapplied migration proposing to). team8_visits below is kept
-- for compatibility but is UNUSED by the app (0 rows in production);
-- do not build new features against it without confirming first.
CREATE TABLE team8_visitors (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(150) NOT NULL,
    contact         VARCHAR(150) NULL,
    person_to_visit VARCHAR(150) NOT NULL,
    purpose         VARCHAR(255) NOT NULL,
    check_in_time   DATETIME NOT NULL,
    check_out_time  DATETIME NULL,
    logged_by       INT NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_team8_visitors_logged_by FOREIGN KEY (logged_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- UNUSED by the application today (see note above) — kept only
-- because it already exists in the live shared DB.
CREATE TABLE team8_visits (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    visitor_id  INT NOT NULL,
    host_id     INT NOT NULL,
    status      VARCHAR(30) NOT NULL DEFAULT 'expected', -- expected | checked_in | checked_out | denied
    check_in    DATETIME NULL,
    check_out   DATETIME NULL,
    purpose     VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_team8_visits_visitor FOREIGN KEY (visitor_id) REFERENCES team8_visitors(id),
    CONSTRAINT fk_team8_visits_host FOREIGN KEY (host_id) REFERENCES users(id)
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
    uploaded_by     INT NOT NULL,
    title           VARCHAR(200) NOT NULL,
    file_path       VARCHAR(500) NOT NULL, -- path of CURRENT version
    current_version INT NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME NULL,
    CONSTRAINT fk_team8_documents_category FOREIGN KEY (category_id) REFERENCES team8_document_categories(id),
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
    CONSTRAINT fk_team8_docversions_document FOREIGN KEY (document_id) REFERENCES team8_documents(id)
) ENGINE=InnoDB;


-- =========================================================
-- MODULE: RECORDS RETENTION & COMPLIANCE
-- =========================================================

CREATE TABLE team8_retention_schedules (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    record_type     VARCHAR(150) NOT NULL,
    retention_years INT NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE team8_records (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    document_id     INT NOT NULL,
    schedule_id     INT NOT NULL,
    custodian_id    INT NOT NULL,
    disposition_date DATE NULL,
    status          VARCHAR(30) NOT NULL DEFAULT 'active',
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
    status      VARCHAR(30) NOT NULL DEFAULT 'open',
    filed_date  DATE NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  DATETIME NULL,
    CONSTRAINT fk_team8_legalcases_assignee FOREIGN KEY (assigned_to) REFERENCES users(id)
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
    renewed_from_id INT NULL,
    title           VARCHAR(200) NOT NULL,
    start_date      DATE NOT NULL,
    end_date        DATE NULL,
    status          VARCHAR(30) NOT NULL DEFAULT 'draft',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME NULL,
    CONSTRAINT fk_team8_contracts_owner FOREIGN KEY (owner_id) REFERENCES users(id),
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


-- =========================================================
-- INDEXES (beyond PK/FK auto-indexes) for common lookups
-- =========================================================

CREATE INDEX idx_team8_reservations_status ON team8_reservations(status);
CREATE INDEX idx_team8_reservations_dates ON team8_reservations(start_time, end_time);
CREATE INDEX idx_team8_reservations_deleted_at ON team8_reservations(deleted_at);
CREATE INDEX idx_team8_facilities_status ON team8_facilities(status);
CREATE INDEX idx_team8_documents_title ON team8_documents(title);
CREATE INDEX idx_team8_records_status ON team8_records(status);
CREATE INDEX idx_team8_records_disposition ON team8_records(disposition_date);
CREATE INDEX idx_team8_legalcases_status ON team8_legal_cases(status);
CREATE INDEX idx_team8_contracts_status ON team8_contracts(status);
CREATE INDEX idx_team8_contracts_enddate ON team8_contracts(end_date);
CREATE INDEX idx_team8_contractobl_duedate ON team8_contract_obligations(due_date);

SET FOREIGN_KEY_CHECKS = 1;
