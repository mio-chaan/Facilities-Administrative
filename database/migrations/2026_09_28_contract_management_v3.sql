-- Upgrade existing contract and party tables to the v3 registry model.
-- Fresh installs should use the corresponding definitions in schema.sql.

ALTER TABLE team8_contracts
    ADD COLUMN IF NOT EXISTS rejection_reason VARCHAR(500) NULL AFTER termination_reason,
    ADD COLUMN IF NOT EXISTS attachment_path VARCHAR(500) NULL,
    ADD COLUMN IF NOT EXISTS attachment_type VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255) NULL;

UPDATE team8_contracts
SET status = CASE status
    WHEN 'for_review' THEN 'approval'
    WHEN 'review' THEN 'approval'
    WHEN 'changes_requested' THEN 'approval'
    WHEN 'negotiation' THEN 'approval'
    WHEN 'pending_approval' THEN 'approval'
    WHEN 'approved' THEN 'signing'
    WHEN 'renewal_or_amendment' THEN 'renewal'
    WHEN 'pending_renewal' THEN 'renewal'
    WHEN 'renewed' THEN 'renewal'
    WHEN 'expiration_or_termination' THEN IF(termination_date IS NOT NULL, 'termination', 'expiration')
    WHEN 'expired' THEN 'expiration'
    WHEN 'terminated' THEN 'termination'
    WHEN 'expiring_soon' THEN 'active'
    ELSE status
END;

ALTER TABLE team8_contracts
    MODIFY COLUMN status ENUM('draft','approval','signing','active','renewal','expiration','termination','archived') NOT NULL DEFAULT 'draft';

ALTER TABLE team8_parties
    ADD COLUMN IF NOT EXISTS trade_name VARCHAR(200) NULL,
    ADD COLUMN IF NOT EXISTS registration_number VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS tin VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS primary_contact VARCHAR(150) NULL,
    ADD COLUMN IF NOT EXISTS address TEXT NULL,
    ADD COLUMN IF NOT EXISTS authorized_signatory_name VARCHAR(150) NULL,
    ADD COLUMN IF NOT EXISTS authorized_signatory_position VARCHAR(150) NULL;

UPDATE team8_parties
SET type = CASE WHEN LOWER(type) = 'individual' THEN 'individual' ELSE 'organization' END;

ALTER TABLE team8_parties
    MODIFY COLUMN type ENUM('organization','individual') NOT NULL;
