-- ============================================================
-- DEMO ACCOUNTS ONLY
-- Depends on: seed_production.sql (departments, roles already seeded)
-- Password for ALL demo accounts: "password"
-- Hash: $2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO users (id, department_id, full_name, email, password_hash) VALUES
    (1,  1,  'Dev Tester',        'dev.tester@example.local',        '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (2,  8,  'Facilities Fran',   'facilities@example.local',        '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (3,  3,  'Frontdesk Fred',    'frontdesk@example.local',         '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (4,  5,  'Legal Lena',        'legal@example.local',             '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (5,  3,  'Employee Ella',     'employee@example.local',          '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (6,  1,  'Records Rita',      'records@example.local',           '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (7,  4,  'IT Ivan',           'it.ivan@example.local',           '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (8,  5,  'Legal Lucas',       'legal.lucas@example.local',       '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (9,  6,  'Ops Olivia',        'ops.olivia@example.local',        '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (10, 7,  'Procurement Paul',  'procurement.paul@example.local',  '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (11, 8,  'Facilities Fiona',  'facilities.fiona@example.local',  '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (12, 9,  'Security Sam',      'security.sam@example.local',      '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (13, 10, 'Marketing Mia',     'marketing.mia@example.local',     '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (14, 11, 'Service Sofia',     'service.sofia@example.local',     '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (15, 3,  'HR Hannah',         'hr.hannah@example.local',         '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (16, 2,  'Finance Felix',     'finance.felix@example.local',     '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (17, 4,  'IT Irene',          'it.irene@example.local',          '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (18, 5,  'Legal Leo',         'legal.leo@example.local',         '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (19, 6,  'Ops Oscar',         'ops.oscar@example.local',         '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (20, 7,  'Procurement Petra', 'procurement.petra@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (21, 8,  'Facilities Frank',  'facilities.frank@example.local',  '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (22, 9,  'Security Sarah',    'security.sarah@example.local',    '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (23, 10, 'Marketing Max',     'marketing.max@example.local',     '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (24, 11, 'Service Simon',     'service.simon@example.local',     '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (25, 1,  'Admin Anna',        'admin.anna@example.local',        '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (26, 2,  'Finance Fiona',     'finance.fiona@example.local',     '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (27, 3,  'HR Henry',          'hr.henry@example.local',          '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (28, 4,  'IT Ian',            'it.ian@example.local',            '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (29, 5,  'Legal Lily',        'legal.lily@example.local',        '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (30, 6,  'Ops Owen',          'ops.owen@example.local',          '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om')
ON DUPLICATE KEY UPDATE
    department_id = VALUES(department_id),
    full_name     = VALUES(full_name),
    email         = VALUES(email),
    password_hash = VALUES(password_hash);

-- Role assignments
-- 1=admin, 2=facilities_staff, 3=front_desk, 4=records_officer, 5=legal_officer, 6=employee
INSERT INTO user_roles (user_id, role_id) VALUES
    (1, 1),   -- Dev Tester        -> admin
    (2, 2),   -- Facilities Fran   -> facilities_staff
    (3, 3),   -- Frontdesk Fred    -> front_desk
    (4, 5),   -- Legal Lena        -> legal_officer
    (5, 6),   -- Employee Ella     -> employee
    (6, 4),   -- Records Rita      -> records_officer
    (7, 6), (8, 5), (9, 6), (10, 6), (11, 2), (12, 6), (13, 6), (14, 6),
    (15, 6), (16, 6), (17, 6), (18, 5), (19, 6), (20, 6), (21, 2), (22, 6),
    (23, 6), (24, 6), (25, 1), (26, 6), (27, 6), (28, 6), (29, 5), (30, 6)
ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), role_id = VALUES(role_id);

SET FOREIGN_KEY_CHECKS = 1;