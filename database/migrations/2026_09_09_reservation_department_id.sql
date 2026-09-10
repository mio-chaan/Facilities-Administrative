-- Store reservation departments by foreign-key ID. The legacy department
-- text column remains for display compatibility with pre-migration rows.
ALTER TABLE team8_reservations
    ADD COLUMN department_id INT NULL AFTER status,
    ADD INDEX idx_team8_reservations_department (department_id),
    ADD CONSTRAINT fk_team8_reservations_department FOREIGN KEY (department_id) REFERENCES departments(id);

UPDATE team8_reservations r
JOIN departments d ON d.name = r.department
SET r.department_id = d.id
WHERE r.department_id IS NULL AND r.department IS NOT NULL;