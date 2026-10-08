ALTER TABLE `team8_contract_portal_invites`
  MODIFY COLUMN `status` enum('pending','accepted','rejected','negotiation_requested','expired') NOT NULL DEFAULT 'pending',
  ADD COLUMN `response_feedback` text DEFAULT NULL,
  ADD COLUMN `meeting_details` text DEFAULT NULL;

ALTER TABLE `team8_contract_negotiations`
  DROP FOREIGN KEY `fk_team8_contractnegotiations_user`,
  MODIFY COLUMN `proposed_by` int(11) DEFAULT NULL,
  ADD COLUMN `proposed_party_id` int(11) DEFAULT NULL AFTER `proposed_by`,
  ADD COLUMN `external_meeting_details` text DEFAULT NULL AFTER `proposed_data`,
  ADD CONSTRAINT `fk_team8_contractnegotiations_user` FOREIGN KEY (`proposed_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_contractnegotiations_party` FOREIGN KEY (`proposed_party_id`) REFERENCES `team8_parties` (`id`);
