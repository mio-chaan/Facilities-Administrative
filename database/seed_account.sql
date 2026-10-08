-- Local development administrator account.
-- Login: ramyum060@gmail.com
-- Temporary password: Password123!@
-- Change the password after the first login. Re-running this seed does not reset an existing account password.

START TRANSACTION;

INSERT INTO roles (role_name)
VALUES ('admin')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

INSERT INTO users (department_id, full_name, email, password_hash)
VALUES (
    NULL,
    'Ram Yum',
    'ramyum060@gmail.com',
    '$2y$10$Ee7UH8lRmKEzYffbtqAI0uuYLWCogHGQ99ADj7t1XoA4JCKcbLcXy'
)
ON DUPLICATE KEY UPDATE
    full_name = VALUES(full_name);

INSERT INTO user_roles (user_id, role_id)
SELECT users.id, roles.id
FROM users
JOIN roles ON roles.role_name = 'admin'
WHERE users.email = 'ramyum060@gmail.com'
ON DUPLICATE KEY UPDATE user_id = VALUES(user_id);

COMMIT;
