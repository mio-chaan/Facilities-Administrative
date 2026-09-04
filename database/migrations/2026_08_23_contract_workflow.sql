ALTER TABLE team8_contracts
    ADD COLUMN department_id INT NULL AFTER owner_id,
    ADD COLUMN renewal_date DATE NULL AFTER end_date,
    ADD COLUMN amount DECIMAL(14,2) NULL AFTER renewal_date,
    ADD CONSTRAINT fk_team8_contracts_department FOREIGN KEY (department_id) REFERENCES departments(id);

CREATE TABLE team8_contract_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    version_no INT NOT NULL,
    data_json LONGTEXT NOT NULL,
    changed_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_team8_contract_history UNIQUE (contract_id, version_no),
    CONSTRAINT fk_team8_contracthistory_contract FOREIGN KEY (contract_id) REFERENCES team8_contracts(id),
    CONSTRAINT fk_team8_contracthistory_user FOREIGN KEY (changed_by) REFERENCES users(id)
);

CREATE INDEX idx_team8_contracts_department ON team8_contracts (department_id, status);
