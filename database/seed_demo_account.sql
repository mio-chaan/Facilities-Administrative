
INSERT INTO users (id, department_id, full_name, email, password_hash) VALUES
    (1, 1, 'Dev Tester',      'dev.tester@example.local',   '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (2, 1, 'Facilities Fran', 'facilities@example.local',   '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (3, 3, 'Frontdesk Fred',  'frontdesk@example.local',    '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (4, 2, 'Legal Lena',      'legal@example.local',        '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (5, 3, 'Employee Ella',   'employee@example.local',     '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (6, 1, 'Records Rita',    'records@example.local',      '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om')
ON DUPLICATE KEY UPDATE department_id = VALUES(department_id), full_name = VALUES(full_name), email = VALUES(email), password_hash = VALUES(password_hash);

INSERT INTO user_roles (id, user_id, role_id) VALUES
    (1, 1, 1), -- Dev Tester -> admin
    (2, 2, 2), -- Facilities Fran -> facilities_staff
    (3, 3, 3), -- Frontdesk Fred -> front_desk
    (4, 4, 5), -- Legal Lena -> legal_officer
    (5, 5, 6), -- Employee Ella -> employee
    (6, 6, 4)  -- Records Rita -> records_officer
ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), role_id = VALUES(role_id);





