ALTER TABLE team8_contracts
    ADD COLUMN contract_number VARCHAR(40) NULL AFTER id,
    ADD COLUMN contract_type VARCHAR(100) NULL AFTER title,
    ADD COLUMN description TEXT NULL AFTER contract_type,
    ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'PHP' AFTER amount,
    ADD COLUMN payment_terms VARCHAR(255) NULL AFTER currency,
    ADD COLUMN payment_frequency VARCHAR(50) NULL AFTER payment_terms,
    ADD COLUMN payment_schedule TEXT NULL AFTER payment_frequency,
    ADD COLUMN deposit_amount DECIMAL(14,2) NULL AFTER payment_schedule,
    ADD COLUMN financial_notes TEXT NULL AFTER deposit_amount,
    ADD COLUMN notice_period_days INT NULL AFTER financial_notes,
    ADD COLUMN termination_date DATE NULL AFTER notice_period_days,
    ADD COLUMN termination_reason VARCHAR(500) NULL AFTER termination_date,
    ADD UNIQUE INDEX uq_team8_contracts_number (contract_number);

UPDATE team8_contracts
SET contract_number = CONCAT('CON-', LPAD(id, 6, '0'))
WHERE contract_number IS NULL;

ALTER TABLE team8_contracts
    MODIFY COLUMN contract_number VARCHAR(40) NOT NULL;

CREATE TABLE team8_contract_approvals (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    contract_id  INT NOT NULL,
    reviewer_id  INT NULL,
    approver_id  INT NULL,
    action       VARCHAR(30) NOT NULL,
    comment      VARCHAR(1000) NULL,
    acted_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_team8_contractapproval_contract FOREIGN KEY (contract_id) REFERENCES team8_contracts(id),
    CONSTRAINT fk_team8_contractapproval_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id),
    CONSTRAINT fk_team8_contractapproval_approver FOREIGN KEY (approver_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE INDEX idx_team8_contractapproval_contract ON team8_contract_approvals (contract_id, acted_at);