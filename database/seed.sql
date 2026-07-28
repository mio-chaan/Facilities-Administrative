-- =========================================================
-- database/seed.sql
-- Minimal local-dev seed data. Run AFTER schema.sql.
-- Kept intentionally small - enough to click through every module
-- once real UI exists, not a full test dataset.
-- =========================================================

-- NOTE: auth_check.php's optional dev bypass (AUTH_DEV_BYPASS=true)
-- hardcodes user_id = 1, so keep that row present if you rely on it.
--
-- All seeded accounts below use the SAME password for convenience:
--     Password123!
-- This is a bcrypt hash of that string. Never reuse this pattern
-- outside local/dev seed data.
--
-- NOTE (code review, Critical): this file is now blocked from direct
-- HTTP access by database/.htaccess and the root .htaccess - see
-- those files for details. Never rely on that alone in production;
-- these are dev-only credentials.

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

INSERT INTO team8_facilities (id, name, location, capacity) VALUES
    (1, 'Main Conference Room', 'Building A, 2nd Floor', 20),
    (2, 'Training Hall', 'Building B, Ground Floor', 60)
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO team8_equipment (id, home_facility_id, name, quantity) VALUES
    (1, 1, 'Projector', 2),
    (2, 1, 'Wireless Microphone', 4),
    (3, 2, 'Portable Speaker', 3),
    (4, 2, 'Foldable Chairs', 60)
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO team8_document_categories (id, name) VALUES
    (1, 'Policies'),
    (2, 'Contracts'),
    (3, 'Legal Filings')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO team8_retention_schedules (id, record_type, retention_years) VALUES
    (1, 'HR Records', 5),
    (2, 'Financial Records', 7),
    (3, 'Legal Filings', 10)
ON DUPLICATE KEY UPDATE record_type = VALUES(record_type);
