-- Phase 5: structured parties shared with Contract Management and linked to legal cases.
-- Apply only to an existing database; fresh installs use database/schema.sql.

ALTER TABLE team8_parties
    ADD COLUMN organization VARCHAR(200) NULL AFTER type;

CREATE TABLE team8_legal_party_types (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    type_code   VARCHAR(60) NOT NULL,
    name        VARCHAR(100) NOT NULL,
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_team8_legal_party_types_code (type_code),
    UNIQUE KEY uq_team8_legal_party_types_name (name)
) ENGINE=InnoDB;

INSERT INTO team8_legal_party_types (type_code, name, sort_order) VALUES
    ('employee', 'Employee', 10),
    ('customer', 'Customer', 20),
    ('supplier', 'Supplier', 30),
    ('government_agency', 'Government Agency', 40),
    ('company_organization', 'Company / Organization', 50),
    ('complainant', 'Complainant', 60),
    ('respondent', 'Respondent', 70),
    ('witness', 'Witness', 80),
    ('other', 'Other', 90);

CREATE TABLE team8_legal_case_parties (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    case_id         INT NOT NULL,
    party_id        INT NOT NULL,
    party_type_id   INT NOT NULL,
    role_in_case    VARCHAR(100) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_team8_legal_case_party (case_id, party_id),
    INDEX idx_team8_legal_case_parties_party (party_id),
    CONSTRAINT fk_team8_legal_case_parties_case FOREIGN KEY (case_id) REFERENCES team8_legal_cases(id),
    CONSTRAINT fk_team8_legal_case_parties_party FOREIGN KEY (party_id) REFERENCES team8_parties(id),
    CONSTRAINT fk_team8_legal_case_parties_type FOREIGN KEY (party_type_id) REFERENCES team8_legal_party_types(id)
) ENGINE=InnoDB;