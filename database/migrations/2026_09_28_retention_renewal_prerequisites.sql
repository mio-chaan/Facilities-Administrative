CREATE TABLE IF NOT EXISTS retention_renewal_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    main_document_type VARCHAR(150) NOT NULL,
    prerequisite_document_type VARCHAR(150) NOT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_retention_renewal_rule (main_document_type, prerequisite_document_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS renewal_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    main_document_type VARCHAR(150) NOT NULL,
    status ENUM('in_progress', 'completed') NOT NULL DEFAULT 'in_progress',
    initiated_by INT NOT NULL,
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_renewal_requests_initiated_by (initiated_by),
    CONSTRAINT fk_renewal_requests_user FOREIGN KEY (initiated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO retention_renewal_rules (main_document_type, prerequisite_document_type, is_required) VALUES
    ('Mayor''s Business Permit', 'Barangay Business Clearance', 1),
    ('Mayor''s Business Permit', 'Community Tax Certificate (Cedula)', 1),
    ('Mayor''s Business Permit', 'Real Property Tax Clearance', 1),
    ('Sanitary Permit', 'Staff Health Certificates', 1),
    ('Sanitary Permit', 'Water Potability Test Result', 1),
    ('Fire Safety Inspection Certificate', 'Fire Extinguisher Inspection Log', 1),
    ('Fire Safety Inspection Certificate', 'Building Safety Clearance', 1)
ON DUPLICATE KEY UPDATE is_required = VALUES(is_required);CREATE TABLE IF NOT EXISTS retention_renewal_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    main_document_type VARCHAR(150) NOT NULL,
    prerequisite_document_type VARCHAR(150) NOT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_retention_renewal_rule (main_document_type, prerequisite_document_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS renewal_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    main_document_type VARCHAR(150) NOT NULL,
    status ENUM('in_progress', 'completed') NOT NULL DEFAULT 'in_progress',
    initiated_by INT NOT NULL,
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_renewal_requests_initiated_by (initiated_by),
    CONSTRAINT fk_renewal_requests_user FOREIGN KEY (initiated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO retention_renewal_rules (main_document_type, prerequisite_document_type, is_required) VALUES
    ('Mayor''s Business Permit', 'Barangay Business Clearance', 1),
    ('Mayor''s Business Permit', 'Community Tax Certificate (Cedula)', 1),
    ('Mayor''s Business Permit', 'Real Property Tax Clearance', 1),
    ('Sanitary Permit', 'Staff Health Certificates', 1),
    ('Sanitary Permit', 'Water Potability Test Result', 1),
    ('Fire Safety Inspection Certificate', 'Fire Extinguisher Inspection Log', 1),
    ('Fire Safety Inspection Certificate', 'Building Safety Clearance', 1)
ON DUPLICATE KEY UPDATE is_required = VALUES(is_required);