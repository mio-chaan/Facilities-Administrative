ALTER TABLE team8_legal_cases
    MODIFY subject TEXT NULL,
    ADD COLUMN created_by INT NULL AFTER assigned_to;

UPDATE team8_legal_cases
SET created_by = assigned_to
WHERE created_by IS NULL;

ALTER TABLE team8_legal_cases
    MODIFY created_by INT NOT NULL,
    ADD CONSTRAINT fk_team8_legalcases_creator
        FOREIGN KEY (created_by) REFERENCES users(id);