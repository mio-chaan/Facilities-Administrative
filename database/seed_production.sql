-- Departments
INSERT INTO departments (id, name) VALUES
    (1, 'Facilities & Administration'),
    (2, 'Legal'),
    (3, 'General Staff')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Roles
INSERT INTO roles (id, role_name) VALUES
    (1, 'admin'),
    (2, 'facilities_staff'),
    (3, 'front_desk'),
    (4, 'records_officer'),
    (5, 'legal_officer'),
    (6, 'employee')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

-- Document Categories
INSERT INTO team8_document_categories (id, name) VALUES
    (1, 'Administrative'),
    (2, 'Contracts'),
    (3, 'Compliance'),
    (4, 'Finance'),
    (5, 'Inventory'),
    (6, 'Facilities'),
    (7, 'Human Resources'),
    (8, 'Others'),
    (9, 'Legal'),
    (10, 'HR')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Retention Schedules
INSERT INTO team8_retention_schedules (id, record_type, retention_years) VALUES
    (1, 'HR Records', 5),
    (2, 'Financial Records', 7),
    (3, 'Legal Filings', 10)
ON DUPLICATE KEY UPDATE
    record_type = VALUES(record_type),
    retention_years = VALUES(retention_years);
