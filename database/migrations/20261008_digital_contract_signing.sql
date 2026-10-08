ALTER TABLE `team8_contract_signatures`
  ADD COLUMN `signature_method` enum('typed','drawn_touch','drawn_pen','drawn_mouse') DEFAULT NULL AFTER `signer_email`,
  ADD COLUMN `drawn_signature_path` varchar(500) DEFAULT NULL AFTER `signature_method`,
  ADD COLUMN `drawn_signature_hash` char(64) DEFAULT NULL AFTER `drawn_signature_path`,
  ADD COLUMN `consented_at` datetime DEFAULT NULL AFTER `drawn_signature_hash`,
  ADD COLUMN `consent_text` varchar(255) DEFAULT NULL AFTER `consented_at`;

CREATE TABLE `team8_contract_signing_invites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contract_id` int(11) NOT NULL,
  `party_id` int(11) NOT NULL,
  `signature_version_no` int(11) NOT NULL,
  `document_hash` char(64) NOT NULL,
  `email` varchar(255) NOT NULL,
  `party_name` varchar(200) NOT NULL,
  `signer_name` varchar(200) DEFAULT NULL,
  `token_hash` char(64) NOT NULL,
  `status` enum('pending','signed','expired','revoked','delivery_failed') NOT NULL DEFAULT 'pending',
  `expires_at` datetime NOT NULL,
  `sent_at` datetime DEFAULT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team8_contract_signing_invites_token` (`token_hash`),
  KEY `idx_team8_contract_signing_invites_contract_party` (`contract_id`,`party_id`,`status`),
  KEY `idx_team8_contract_signing_invites_expiry` (`status`,`expires_at`),
  CONSTRAINT `fk_team8_contract_signing_invites_contract` FOREIGN KEY (`contract_id`) REFERENCES `team8_contracts` (`id`),
  CONSTRAINT `fk_team8_contract_signing_invites_party` FOREIGN KEY (`party_id`) REFERENCES `team8_parties` (`id`),
  CONSTRAINT `fk_team8_contract_signing_invites_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `team8_contract_signing_otps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invite_id` int(11) NOT NULL,
  `otp_hash` char(64) NOT NULL,
  `attempts` tinyint unsigned NOT NULL DEFAULT 0,
  `sent_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `verified_at` datetime DEFAULT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_team8_contract_signing_otps_invite` (`invite_id`,`id`),
  CONSTRAINT `fk_team8_contract_signing_otps_invite` FOREIGN KEY (`invite_id`) REFERENCES `team8_contract_signing_invites` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
