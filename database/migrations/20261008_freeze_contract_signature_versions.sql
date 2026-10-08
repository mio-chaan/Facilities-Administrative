ALTER TABLE `team8_contracts`
  ADD COLUMN `signature_version_no` int(11) DEFAULT NULL AFTER `status`,
  ADD COLUMN `signature_document_hash` char(64) DEFAULT NULL AFTER `signature_version_no`;

ALTER TABLE `team8_contract_signatures`
  DROP INDEX `uq_team8_contract_signatures_contract_party`,
  DROP INDEX `uq_team8_contract_signatures_contract_email`,
  DROP INDEX `idx_team8_contract_signatures_status`,
  ADD COLUMN `signature_version_no` int(11) DEFAULT NULL AFTER `party_id`,
  ADD COLUMN `document_hash` char(64) DEFAULT NULL AFTER `signature_version_no`,
  ADD COLUMN `signature_hash` char(64) DEFAULT NULL AFTER `document_hash`,
  ADD COLUMN `voided_at` datetime DEFAULT NULL AFTER `signed_at`,
  ADD COLUMN `voided_reason` varchar(500) DEFAULT NULL AFTER `voided_at`,
  ADD COLUMN `ip_address` varchar(45) DEFAULT NULL AFTER `voided_reason`,
  ADD COLUMN `user_agent` varchar(500) DEFAULT NULL AFTER `ip_address`,
  ADD KEY `idx_team8_contract_signatures_contract_party_version` (`contract_id`,`party_id`,`signature_version_no`,`voided_at`),
  ADD KEY `idx_team8_contract_signatures_status` (`contract_id`,`status`,`signature_version_no`,`signed_at`,`voided_at`);
