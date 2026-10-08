-- Local development administrator account.
-- Login: dev.tester@example.local
-- Temporary password: Password123!
-- Change the password after the first login. Re-running this seed does not reset an existing account password.

START TRANSACTION;

INSERT INTO roles (role_name)
VALUES ('admin')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

INSERT INTO users (department_id, full_name, email, password_hash)
VALUES (
    NULL,
    'Dev Tester',
    'dev.tester@example.local',
    '$2y$10$1H5g7bOJtbDqD/wFgmmn0OVhvuqREizhIsuvwls2VxN.eMTP.lKbC'
)
ON DUPLICATE KEY UPDATE
    full_name = VALUES(full_name),
    password_hash = VALUES(password_hash);

INSERT INTO user_roles (user_id, role_id)
SELECT users.id, roles.id
FROM users
JOIN roles ON roles.role_name = 'admin'
WHERE users.email = 'dev.tester@example.local'
ON DUPLICATE KEY UPDATE user_id = VALUES(user_id);

COMMIT;
