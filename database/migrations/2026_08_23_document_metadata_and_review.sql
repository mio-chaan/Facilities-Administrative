-- Metadata and staff review lifecycle for uploaded documents.
ALTER TABLE team8_documents
    ADD COLUMN department_id INT NULL AFTER document_type,
    ADD COLUMN owner_id INT NULL AFTER department_id,
    ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'pending' AFTER current_version,
    ADD COLUMN expiration_date DATE NULL AFTER status,
    ADD CONSTRAINT fk_team8_documents_department FOREIGN KEY (department_id) REFERENCES departments(id),
    ADD CONSTRAINT fk_team8_documents_owner FOREIGN KEY (owner_id) REFERENCES users(id);

CREATE INDEX idx_team8_documents_status ON team8_documents (status);
CREATE INDEX idx_team8_documents_expiration ON team8_documents (expiration_date);
