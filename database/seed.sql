-- =========================================================
-- database/seed.sql
-- Minimal local-dev seed data. Run AFTER schema.sql.
-- Kept intentionally small - enough to click through every module
-- once real UI exists, not a full test dataset.
-- =========================================================


INSERT INTO departments (id, name) VALUES
    (1, 'Facilities & Administration'),
    (2, 'Legal'),
    (3, 'General Staff')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO users (id, department_id, full_name, email, password_hash) VALUES
    (1, 1, 'Dev Tester',      'dev.tester@example.local',   '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (2, 1, 'Facilities Fran', 'facilities@example.local',   '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (3, 3, 'Frontdesk Fred',  'frontdesk@example.local',    '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (4, 2, 'Legal Lena',      'legal@example.local',        '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om')
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name);

INSERT INTO roles (id, role_name) VALUES
    (1, 'admin'),
    (2, 'facilities_staff'),
    (3, 'front_desk'),
    (4, 'records_officer'),
    (5, 'legal_officer'),
    (6, 'employee')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

INSERT INTO user_roles (user_id, role_id) VALUES
    (1, 1), -- Dev Tester -> admin
    (2, 2), -- Facilities Fran -> facilities_staff
    (3, 3), -- Frontdesk Fred -> front_desk
    (4, 5)  -- Legal Lena -> legal_officer
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);


INSERT INTO team8_document_categories (id, name) VALUES
    (1, 'Administrative'),
    (2, 'Contracts'),
    (3, 'Compliance'),
    (4, 'Finance'),
    (5, 'Inventory'),
    (6, 'Facilities'),
    (7, 'Human Resources'),
    (8, 'Others')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO team8_retention_schedules (id, record_type, retention_years) VALUES
    (1, 'HR Records', 5),
    (2, 'Financial Records', 7),
    (3, 'Legal Filings', 10)
ON DUPLICATE KEY UPDATE record_type = VALUES(record_type);
