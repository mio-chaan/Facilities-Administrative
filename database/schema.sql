-- =========================================================
-- TEAM 8 — FACILITIES & ADMINISTRATIVE MANAGEMENT
-- Subsystem schema (integrates into shared capstone database)
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

-- CHANGED (Facility Management module, 2026-07-17): added
-- `description` (optional detail shown in the admin UI) and `status`
-- (active/archived) so facilities can be managed entirely through
-- modules/facilities/index.php instead of direct SQL. Facilities are
-- archived, never deleted, since team8_reservations.facility_id and
-- team8_equipment.home_facility_id both hold FKs into this table.
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

CREATE TABLE team8_reservations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    facility_id INT NOT NULL,
    user_id     INT NOT NULL,
    start_time  DATETIME NOT NULL,
    end_time    DATETIME NOT NULL,
    status      VARCHAR(30) NOT NULL DEFAULT 'pending',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  DATETIME NULL,
    CONSTRAINT fk_team8_reservations_facility FOREIGN KEY (facility_id) REFERENCES team8_facilities(id),
    CONSTRAINT fk_team8_reservations_user FOREIGN KEY (user_id) REFERENCES users(id)
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
-- See modules/reservation/index.php.
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

CREATE TABLE team8_visitors (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    full_name   VARCHAR(150) NOT NULL,
    contact     VARCHAR(150) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

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

-- NEW TABLE (patch): periodic compliance audits, distinct from retention scheduling
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
CREATE INDEX idx_team8_facilities_status ON team8_facilities(status);
CREATE INDEX idx_team8_visits_status ON team8_visits(status);
CREATE INDEX idx_team8_documents_title ON team8_documents(title);
CREATE INDEX idx_team8_records_status ON team8_records(status);
CREATE INDEX idx_team8_records_disposition ON team8_records(disposition_date);
CREATE INDEX idx_team8_legalcases_status ON team8_legal_cases(status);
CREATE INDEX idx_team8_contracts_status ON team8_contracts(status);
CREATE INDEX idx_team8_contracts_enddate ON team8_contracts(end_date);
CREATE INDEX idx_team8_contractobl_duedate ON team8_contract_obligations(due_date);

SET FOREIGN_KEY_CHECKS = 1;
