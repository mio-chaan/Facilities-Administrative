-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: capstone_shared_db
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `entity_type` varchar(100) NOT NULL,
  `entity_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_audit_logs_user` (`user_id`),
  CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `message` varchar(500) NOT NULL,
  `target_url` varchar(500) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'unread',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user_status` (`user_id`,`status`,`created_at`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_certificate_recipients`
--

DROP TABLE IF EXISTS `team8_certificate_recipients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_certificate_recipients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `certificate_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team8_cert_recipient` (`certificate_id`,`employee_id`),
  KEY `idx_team8_cert_recipient_employee` (`employee_id`),
  CONSTRAINT `fk_team8_cert_recipient_certificate` FOREIGN KEY (`certificate_id`) REFERENCES `team8_certificates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_team8_cert_recipient_employee` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_certificates`
--

DROP TABLE IF EXISTS `team8_certificates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_certificates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_number` varchar(40) NOT NULL,
  `certificate_type` varchar(50) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `prepared_by` int(11) NOT NULL,
  `details` text DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'draft',
  `rejection_reason` text DEFAULT NULL,
  `current_version` int(11) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_number` (`document_number`),
  KEY `fk_team8_cert_employee` (`employee_id`),
  KEY `fk_team8_cert_preparer` (`prepared_by`),
  KEY `idx_team8_cert_status` (`status`),
  KEY `idx_team8_cert_type` (`certificate_type`),
  KEY `idx_team8_cert_browse` (`status`,`employee_id`,`updated_at`,`id`),
  CONSTRAINT `fk_team8_cert_employee` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_team8_cert_preparer` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_compliance_checks`
--

DROP TABLE IF EXISTS `team8_compliance_checks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_compliance_checks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `record_id` int(11) NOT NULL,
  `checked_by` int(11) NOT NULL,
  `check_date` date NOT NULL,
  `result` varchar(30) NOT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_team8_compliance_record` (`record_id`),
  KEY `fk_team8_compliance_checker` (`checked_by`),
  CONSTRAINT `fk_team8_compliance_checker` FOREIGN KEY (`checked_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_team8_compliance_record` FOREIGN KEY (`record_id`) REFERENCES `team8_records` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_contract_approvals`
--

DROP TABLE IF EXISTS `team8_contract_approvals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_contract_approvals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contract_id` int(11) NOT NULL,
  `reviewer_id` int(11) DEFAULT NULL,
  `approver_id` int(11) DEFAULT NULL,
  `action` varchar(30) NOT NULL,
  `comment` varchar(1000) DEFAULT NULL,
  `acted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_team8_contractapproval_reviewer` (`reviewer_id`),
  KEY `fk_team8_contractapproval_approver` (`approver_id`),
  KEY `idx_team8_contractapproval_contract` (`contract_id`,`acted_at`),
  CONSTRAINT `fk_team8_contractapproval_approver` FOREIGN KEY (`approver_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_team8_contractapproval_contract` FOREIGN KEY (`contract_id`) REFERENCES `team8_contracts` (`id`),
  CONSTRAINT `fk_team8_contractapproval_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_contract_documents`
--

DROP TABLE IF EXISTS `team8_contract_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_contract_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contract_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team8_contract_documents_contract_document` (`contract_id`,`document_id`),
  KEY `fk_team8_contractdocs_document` (`document_id`),
  CONSTRAINT `fk_team8_contractdocs_contract` FOREIGN KEY (`contract_id`) REFERENCES `team8_contracts` (`id`),
  CONSTRAINT `fk_team8_contractdocs_document` FOREIGN KEY (`document_id`) REFERENCES `team8_documents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_contract_history`
--

DROP TABLE IF EXISTS `team8_contract_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_contract_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contract_id` int(11) NOT NULL,
  `version_no` int(11) NOT NULL,
  `data_json` longtext NOT NULL,
  `changed_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team8_contract_history` (`contract_id`,`version_no`),
  KEY `fk_team8_contracthistory_user` (`changed_by`),
  CONSTRAINT `fk_team8_contracthistory_contract` FOREIGN KEY (`contract_id`) REFERENCES `team8_contracts` (`id`),
  CONSTRAINT `fk_team8_contracthistory_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_contract_parties`
--

DROP TABLE IF EXISTS `team8_contract_parties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_contract_parties` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contract_id` int(11) NOT NULL,
  `party_id` int(11) NOT NULL,
  `role_in_contract` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team8_contract_parties_contract_party_role` (`contract_id`,`party_id`,`role_in_contract`),
  KEY `fk_team8_contractparties_party` (`party_id`),
  CONSTRAINT `fk_team8_contractparties_contract` FOREIGN KEY (`contract_id`) REFERENCES `team8_contracts` (`id`),
  CONSTRAINT `fk_team8_contractparties_party` FOREIGN KEY (`party_id`) REFERENCES `team8_parties` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_contracts`
--

DROP TABLE IF EXISTS `team8_contracts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_contracts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contract_number` varchar(40) NOT NULL,
  `owner_id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `renewed_from_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `contract_type` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `renewal_date` date DEFAULT NULL,
  `amount` decimal(14,2) DEFAULT NULL,
  `currency` char(3) NOT NULL DEFAULT 'PHP',
  `payment_terms` varchar(255) DEFAULT NULL,
  `payment_frequency` varchar(50) DEFAULT NULL,
  `deposit_amount` decimal(14,2) DEFAULT NULL,
  `financial_notes` text DEFAULT NULL,
  `notice_period_days` int(11) DEFAULT NULL,
  `termination_date` date DEFAULT NULL,
  `termination_reason` varchar(500) DEFAULT NULL,
  `rejection_reason` varchar(500) DEFAULT NULL,
  `attachment_path` varchar(500) DEFAULT NULL,
  `attachment_type` varchar(20) DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `status` enum('draft','approval','signing','active','renewal','expiration','termination','archived') NOT NULL DEFAULT 'draft',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contract_number` (`contract_number`),
  KEY `fk_team8_contracts_owner` (`owner_id`),
  KEY `fk_team8_contracts_renewed` (`renewed_from_id`),
  KEY `idx_team8_contracts_status` (`status`),
  KEY `idx_team8_contracts_deleted_status` (`deleted_at`,`status`),
  KEY `idx_team8_contracts_enddate` (`end_date`),
  KEY `idx_team8_contracts_number` (`contract_number`),
  KEY `idx_team8_contracts_department` (`department_id`,`status`),
  KEY `idx_team8_contracts_type_status` (`contract_type`,`status`),
  CONSTRAINT `fk_team8_contracts_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  CONSTRAINT `fk_team8_contracts_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_team8_contracts_renewed` FOREIGN KEY (`renewed_from_id`) REFERENCES `team8_contracts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_document_categories`
--

DROP TABLE IF EXISTS `team8_document_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_document_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_document_number_sequences`
--

DROP TABLE IF EXISTS `team8_document_number_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_document_number_sequences` (
  `prefix` varchar(30) NOT NULL,
  `sequence_year` smallint(5) unsigned NOT NULL,
  `last_number` int(10) unsigned NOT NULL,
  PRIMARY KEY (`prefix`,`sequence_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_document_versions`
--

DROP TABLE IF EXISTS `team8_document_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_document_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` int(11) NOT NULL,
  `version_no` int(11) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` bigint(20) NOT NULL DEFAULT 0,
  `checksum` varchar(128) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team8_docversions_doc_version` (`document_id`,`version_no`),
  CONSTRAINT `fk_team8_docversions_document` FOREIGN KEY (`document_id`) REFERENCES `team8_documents` (`id`),
  CONSTRAINT `chk_team8_docversions_version` CHECK (`version_no` >= 1),
  CONSTRAINT `chk_team8_docversions_file_size` CHECK (`file_size` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_documents`
--

DROP TABLE IF EXISTS `team8_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) DEFAULT NULL,
  `document_type` varchar(150) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `owner_id` int(11) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `current_version` int(11) NOT NULL DEFAULT 1,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `review_reason` text DEFAULT NULL,
  `expiration_date` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_team8_documents_department` (`department_id`),
  KEY `fk_team8_documents_owner` (`owner_id`),
  KEY `idx_team8_documents_title` (`title`),
  KEY `idx_team8_documents_status` (`status`),
  KEY `idx_team8_documents_expiration` (`expiration_date`),
  KEY `idx_team8_documents_browse_owner` (`uploaded_by`,`status`,`deleted_at`,`updated_at`,`id`),
  KEY `idx_team8_documents_browse_category` (`category_id`,`status`,`deleted_at`,`updated_at`,`id`),
  KEY `idx_team8_documents_browse_deleted` (`deleted_at`,`updated_at`,`id`),
  CONSTRAINT `fk_team8_documents_category` FOREIGN KEY (`category_id`) REFERENCES `team8_document_categories` (`id`),
  CONSTRAINT `fk_team8_documents_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  CONSTRAINT `fk_team8_documents_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_team8_documents_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Tables for retention renewal prerequisites and requests
--

CREATE TABLE IF NOT EXISTS `retention_renewal_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `main_document_type` varchar(150) NOT NULL,
  `prerequisite_document_type` varchar(150) NOT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_retention_renewal_rule` (`main_document_type`,`prerequisite_document_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `retention_renewal_rules` (`main_document_type`, `prerequisite_document_type`, `is_required`) VALUES
  ('Mayor''s Business Permit', 'Barangay Business Clearance', 1),
  ('Mayor''s Business Permit', 'Community Tax Certificate (Cedula)', 1),
  ('Mayor''s Business Permit', 'Real Property Tax Clearance', 1),
  ('Sanitary Permit', 'Staff Health Certificates', 1),
  ('Sanitary Permit', 'Water Potability Test Result', 1),
  ('Fire Safety Inspection Certificate', 'Fire Extinguisher Inspection Log', 1),
  ('Fire Safety Inspection Certificate', 'Building Safety Clearance', 1)
ON DUPLICATE KEY UPDATE `is_required` = VALUES(`is_required`);

CREATE TABLE IF NOT EXISTS `renewal_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `main_document_type` varchar(150) NOT NULL,
  `status` enum('in_progress','completed') NOT NULL DEFAULT 'in_progress',
  `initiated_by` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_renewal_requests_initiated_by` (`initiated_by`),
  CONSTRAINT `fk_renewal_requests_user` FOREIGN KEY (`initiated_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `team8_compliance_reports`
--

DROP TABLE IF EXISTS `team8_compliance_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_compliance_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_type` varchar(120) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `executive_summary` text NOT NULL,
  `key_findings` longtext NOT NULL,
  `check_details` longtext NOT NULL,
  `generated_by` int(11) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `document_id` int(11) DEFAULT NULL,
  `recipient_emails` text DEFAULT NULL,
  `email_sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_team8_compliance_reports_status` (`status`,`created_at`),
  KEY `idx_team8_compliance_reports_department_dates` (`department_id`,`date_from`,`date_to`),
  KEY `fk_team8_compliance_reports_generated_by` (`generated_by`),
  KEY `fk_team8_compliance_reports_approved_by` (`approved_by`),
  KEY `fk_team8_compliance_reports_document` (`document_id`),
  CONSTRAINT `fk_team8_compliance_reports_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  CONSTRAINT `fk_team8_compliance_reports_generated_by` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_team8_compliance_reports_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_team8_compliance_reports_document` FOREIGN KEY (`document_id`) REFERENCES `team8_documents` (`id`),
  CONSTRAINT `chk_team8_compliance_reports_dates` CHECK (`date_to` >= `date_from`),
  CONSTRAINT `chk_team8_compliance_reports_status` CHECK (`status` IN ('draft','approved'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_compliance_report_documents`
--

DROP TABLE IF EXISTS `team8_compliance_report_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_compliance_report_documents` (
  `compliance_report_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `attached_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`compliance_report_id`,`document_id`),
  KEY `idx_team8_compliance_report_documents_document` (`document_id`),
  CONSTRAINT `fk_team8_compliance_report_documents_report` FOREIGN KEY (`compliance_report_id`) REFERENCES `team8_compliance_reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_team8_compliance_report_documents_document` FOREIGN KEY (`document_id`) REFERENCES `team8_documents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_equipment`
--

DROP TABLE IF EXISTS `team8_equipment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_equipment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `home_facility_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_team8_equipment_facility` (`home_facility_id`),
  CONSTRAINT `fk_team8_equipment_facility` FOREIGN KEY (`home_facility_id`) REFERENCES `team8_facilities` (`id`),
  CONSTRAINT `chk_team8_equipment_quantity` CHECK (`quantity` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_explanations`
--

DROP TABLE IF EXISTS `team8_explanations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_explanations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nte_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `explanation_text` text NOT NULL,
  `attachment_path` varchar(500) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `admin_remarks` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team8_explanation_nte` (`nte_id`),
  KEY `fk_team8_expl_employee` (`employee_id`),
  KEY `fk_team8_expl_reviewer` (`reviewed_by`),
  KEY `idx_team8_expl_status` (`status`),
  KEY `idx_team8_expl_nte` (`nte_id`),
  KEY `idx_team8_expl_browse` (`status`,`employee_id`,`updated_at`,`id`),
  CONSTRAINT `fk_team8_expl_employee` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_team8_expl_nte` FOREIGN KEY (`nte_id`) REFERENCES `team8_notice_to_explain` (`id`),
  CONSTRAINT `fk_team8_expl_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_facilities`
--

DROP TABLE IF EXISTS `team8_facilities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_facilities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `location` varchar(200) NOT NULL,
  `facility_type` varchar(100) DEFAULT NULL,
  `capacity` int(11) NOT NULL DEFAULT 1,
  `description` text DEFAULT NULL,
  `equipment_notes` text DEFAULT NULL,
  `maintenance_status` varchar(30) NOT NULL DEFAULT 'operational',
  `next_maintenance_date` date DEFAULT NULL,
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_team8_facilities_status` (`status`),
  CONSTRAINT `chk_team8_facilities_capacity` CHECK (`capacity` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_facility_locations`
--

DROP TABLE IF EXISTS `team8_facility_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_facility_locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_facility_maintenance_history`
--

DROP TABLE IF EXISTS `team8_facility_maintenance_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_facility_maintenance_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `facility_id` int(11) NOT NULL,
  `performed_by` int(11) NOT NULL,
  `maintenance_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_t8_maintenance_user` (`performed_by`),
  KEY `idx_team8_maintenance_facility_date` (`facility_id`,`maintenance_date`),
  CONSTRAINT `fk_t8_maintenance_facility` FOREIGN KEY (`facility_id`) REFERENCES `team8_facilities` (`id`),
  CONSTRAINT `fk_t8_maintenance_user` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_hr_document_sequences`
--

DROP TABLE IF EXISTS `team8_hr_document_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_hr_document_sequences` (
  `prefix` varchar(20) NOT NULL,
  `document_year` smallint(6) NOT NULL,
  `next_number` int(11) NOT NULL,
  PRIMARY KEY (`prefix`,`document_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_hr_document_versions`
--

DROP TABLE IF EXISTS `team8_hr_document_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_hr_document_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `doc_type` varchar(30) NOT NULL,
  `doc_id` int(11) NOT NULL,
  `version_no` int(11) NOT NULL,
  `data_json` text NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team8_hrver` (`doc_type`,`doc_id`,`version_no`),
  KEY `fk_team8_hrver_creator` (`created_by`),
  KEY `idx_team8_hrver_lookup` (`doc_type`,`doc_id`),
  CONSTRAINT `fk_team8_hrver_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_team8_hrver_version` CHECK (`version_no` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_incident_reports`
--

DROP TABLE IF EXISTS `team8_incident_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_incident_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_number` varchar(40) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `prepared_by` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `rejection_reason` text DEFAULT NULL,
  `incident_date` date NOT NULL,
  `incident_time` time NOT NULL,
  `incident_location` varchar(200) NOT NULL,
  `incident_type` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `witness` varchar(200) DEFAULT NULL,
  `attachment_path` varchar(500) DEFAULT NULL,
  `current_version` int(11) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_number` (`document_number`),
  KEY `fk_team8_ir_preparer` (`prepared_by`),
  KEY `fk_team8_ir_department` (`department_id`),
  KEY `idx_team8_ir_status` (`status`),
  KEY `idx_team8_ir_employee` (`employee_id`),
  KEY `idx_team8_ir_browse` (`status`,`employee_id`,`updated_at`,`id`),
  CONSTRAINT `fk_team8_ir_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  CONSTRAINT `fk_team8_ir_employee` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_team8_ir_preparer` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_legal_cases`
--

DROP TABLE IF EXISTS `team8_legal_cases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_legal_cases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `assigned_to` int(11) NOT NULL,
  `contract_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `subject` varchar(200) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'open',
  `filed_date` date NOT NULL,
  `deadline` date DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_team8_legalcases_department` (`department_id`),
  KEY `fk_team8_legalcases_contract` (`contract_id`),
  KEY `idx_team8_legalcases_status` (`status`),
  KEY `idx_team8_legalcases_deleted_status` (`deleted_at`,`status`),
  KEY `idx_team8_legal_cases_assignee` (`assigned_to`,`status`),
  CONSTRAINT `fk_team8_legalcases_assignee` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_team8_legalcases_contract` FOREIGN KEY (`contract_id`) REFERENCES `team8_contracts` (`id`),
  CONSTRAINT `fk_team8_legalcases_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_legal_documents`
--

DROP TABLE IF EXISTS `team8_legal_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_legal_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `case_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team8_legal_documents_case_document` (`case_id`,`document_id`),
  KEY `fk_team8_legaldocs_document` (`document_id`),
  CONSTRAINT `fk_team8_legaldocs_case` FOREIGN KEY (`case_id`) REFERENCES `team8_legal_cases` (`id`),
  CONSTRAINT `fk_team8_legaldocs_document` FOREIGN KEY (`document_id`) REFERENCES `team8_documents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_login_throttle`
--

DROP TABLE IF EXISTS `team8_login_throttle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_login_throttle` (
  `identifier` varchar(191) NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_memorandum_recipients`
--

DROP TABLE IF EXISTS `team8_memorandum_recipients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_memorandum_recipients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `memorandum_id` int(11) NOT NULL,
  `recipient_type` varchar(30) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team8_memo_recipient` (`memorandum_id`,`recipient_type`,`department_id`),
  KEY `idx_team8_memo_recipient_department` (`department_id`),
  KEY `idx_team8_memo_recipient_browse` (`memorandum_id`,`department_id`,`recipient_type`),
  CONSTRAINT `fk_team8_memo_recipient_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  CONSTRAINT `fk_team8_memo_recipient_memo` FOREIGN KEY (`memorandum_id`) REFERENCES `team8_memorandums` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_team8_memo_recipient_type` CHECK (`recipient_type` in ('all_departments','department'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_memorandums`
--

DROP TABLE IF EXISTS `team8_memorandums`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_memorandums` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_number` varchar(40) NOT NULL,
  `kind` varchar(30) NOT NULL DEFAULT 'memorandum',
  `title` varchar(200) NOT NULL,
  `recipients` varchar(500) NOT NULL,
  `content` text NOT NULL,
  `remarks` text DEFAULT NULL,
  `prepared_by` int(11) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'draft',
  `rejection_reason` text DEFAULT NULL,
  `current_version` int(11) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_number` (`document_number`),
  KEY `fk_team8_memo_preparer` (`prepared_by`),
  KEY `idx_team8_memo_status` (`status`),
  KEY `idx_team8_memo_kind` (`kind`),
  KEY `idx_team8_memo_browse` (`status`,`kind`,`updated_at`,`id`),
  CONSTRAINT `fk_team8_memo_preparer` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_notice_to_explain`
--

DROP TABLE IF EXISTS `team8_notice_to_explain`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_notice_to_explain` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_number` varchar(40) NOT NULL,
  `incident_report_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `prepared_by` int(11) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `rejection_reason` text DEFAULT NULL,
  `deadline` date NOT NULL,
  `remarks` text DEFAULT NULL,
  `current_version` int(11) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_number` (`document_number`),
  UNIQUE KEY `uq_team8_nte_incident` (`incident_report_id`),
  KEY `fk_team8_nte_employee` (`employee_id`),
  KEY `fk_team8_nte_preparer` (`prepared_by`),
  KEY `idx_team8_nte_status` (`status`),
  KEY `idx_team8_nte_incident` (`incident_report_id`),
  KEY `idx_team8_nte_browse` (`status`,`employee_id`,`updated_at`,`id`),
  CONSTRAINT `fk_team8_nte_employee` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_team8_nte_incident` FOREIGN KEY (`incident_report_id`) REFERENCES `team8_incident_reports` (`id`),
  CONSTRAINT `fk_team8_nte_preparer` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_parties`
--

DROP TABLE IF EXISTS `team8_parties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_parties` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `trade_name` varchar(200) DEFAULT NULL,
  `registration_number` varchar(100) DEFAULT NULL,
  `tin` varchar(50) DEFAULT NULL,
  `type` enum('organization','individual') NOT NULL,
  `contact_email` varchar(150) DEFAULT NULL,
  `contact_phone` varchar(50) DEFAULT NULL,
  `primary_contact` varchar(150) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `authorized_signatory_name` varchar(150) DEFAULT NULL,
  `authorized_signatory_position` varchar(150) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_team8_parties_type_name` (`type`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_records`
--

DROP TABLE IF EXISTS `team8_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity_type` varchar(20) NOT NULL,
  `entity_id` int(11) NOT NULL,
  `schedule_id` int(11) DEFAULT NULL,
  `retention_basis` varchar(255) NOT NULL,
  `retention_years` int(11) NOT NULL,
  `retention_start_date` date NOT NULL,
  `custodian_id` int(11) NOT NULL,
  `disposition_date` date DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'active',
  `archived_at` datetime DEFAULT NULL,
  `archive_reason` varchar(500) DEFAULT NULL,
  `disposed_at` datetime DEFAULT NULL,
  `disposal_reason` varchar(500) DEFAULT NULL,
  `disposal_requested_by` int(11) DEFAULT NULL,
  `disposal_requested_at` datetime DEFAULT NULL,
  `disposal_authorized_by` int(11) DEFAULT NULL,
  `disposal_authorized_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team8_records_entity` (`entity_type`,`entity_id`),
  KEY `fk_team8_records_schedule` (`schedule_id`),
  KEY `fk_team8_records_custodian` (`custodian_id`),
  KEY `fk_team8_records_disposal_requester` (`disposal_requested_by`),
  KEY `fk_team8_records_disposal_authorizer` (`disposal_authorized_by`),
  KEY `idx_team8_records_status` (`status`),
  KEY `idx_team8_records_status_deleted` (`status`,`deleted_at`),
  KEY `idx_team8_records_disposition_date` (`disposition_date`),
  KEY `idx_team8_records_entity` (`entity_type`,`entity_id`),
  KEY `idx_team8_records_disposition` (`disposition_date`,`status`),
  CONSTRAINT `fk_team8_records_custodian` FOREIGN KEY (`custodian_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_team8_records_disposal_authorizer` FOREIGN KEY (`disposal_authorized_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_team8_records_disposal_requester` FOREIGN KEY (`disposal_requested_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_team8_records_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `team8_retention_schedules` (`id`),
  CONSTRAINT `chk_team8_records_entity_type` CHECK (`entity_type` in ('document','contract','legal_case')),
  CONSTRAINT `chk_team8_records_retention_years` CHECK (`retention_years` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_reservation_approvals`
--

DROP TABLE IF EXISTS `team8_reservation_approvals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_reservation_approvals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reservation_id` int(11) NOT NULL,
  `approver_id` int(11) NOT NULL,
  `step_order` int(11) NOT NULL DEFAULT 1,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_team8_resapproval_reservation` (`reservation_id`),
  KEY `fk_team8_resapproval_approver` (`approver_id`),
  CONSTRAINT `fk_team8_resapproval_approver` FOREIGN KEY (`approver_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_team8_resapproval_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `team8_reservations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_reservation_cancellation_requests`
--

DROP TABLE IF EXISTS `team8_reservation_cancellation_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_reservation_cancellation_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reservation_id` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `reason` text NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `admin_remark` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_team8_cancel_request_reservation` (`reservation_id`),
  KEY `fk_team8_cancel_request_requester` (`requested_by`),
  KEY `fk_team8_cancel_request_reviewer` (`reviewed_by`),
  KEY `idx_team8_cancel_request_pending` (`status`,`requested_at`),
  CONSTRAINT `fk_team8_cancel_request_requester` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_team8_cancel_request_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `team8_reservations` (`id`),
  CONSTRAINT `fk_team8_cancel_request_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_reservation_equipment`
--

DROP TABLE IF EXISTS `team8_reservation_equipment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_reservation_equipment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reservation_id` int(11) NOT NULL,
  `equipment_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team8_reservation_equipment` (`reservation_id`,`equipment_id`),
  KEY `fk_team8_resequip_equipment` (`equipment_id`),
  CONSTRAINT `fk_team8_resequip_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `team8_equipment` (`id`),
  CONSTRAINT `fk_team8_resequip_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `team8_reservations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_reservations`
--

DROP TABLE IF EXISTS `team8_reservations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_reservations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `facility_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `department_id` int(11) DEFAULT NULL,
  `department` varchar(150) DEFAULT NULL,
  `key_person` varchar(150) DEFAULT NULL,
  `expected_participants` int(11) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `event_category` varchar(100) DEFAULT NULL,
  `description` varchar(500) DEFAULT NULL,
  `expected_return_date` date DEFAULT NULL,
  `remarks` varchar(500) DEFAULT NULL,
  `schedule` datetime DEFAULT NULL,
  `requirements` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `archived_at` datetime DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `cancellation_requested_by` int(11) DEFAULT NULL,
  `cancellation_requested_at` datetime DEFAULT NULL,
  `cancellation_reviewed_by` int(11) DEFAULT NULL,
  `cancellation_reviewed_at` datetime DEFAULT NULL,
  `cancellation_decision` varchar(30) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_team8_reservations_facility` (`facility_id`),
  KEY `fk_team8_reservations_user` (`user_id`),
  KEY `fk_team8_reservations_cancel_requester` (`cancellation_requested_by`),
  KEY `fk_team8_reservations_cancel_reviewer` (`cancellation_reviewed_by`),
  KEY `idx_team8_reservations_status` (`status`),
  KEY `idx_team8_reservations_dates` (`start_time`,`end_time`),
  KEY `idx_team8_reservations_deleted_at` (`deleted_at`),
  KEY `idx_team8_reservations_archived_at` (`archived_at`),
  KEY `idx_team8_reservations_cancellation_status` (`status`,`cancellation_requested_at`),
  KEY `idx_team8_reservations_department` (`department_id`),
  CONSTRAINT `fk_team8_reservations_cancel_requester` FOREIGN KEY (`cancellation_requested_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_team8_reservations_cancel_reviewer` FOREIGN KEY (`cancellation_reviewed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_team8_reservations_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  CONSTRAINT `fk_team8_reservations_facility` FOREIGN KEY (`facility_id`) REFERENCES `team8_facilities` (`id`),
  CONSTRAINT `fk_team8_reservations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_team8_reservations_participants` CHECK (`expected_participants` is null or `expected_participants` > 0),
  CONSTRAINT `chk_team8_reservations_quantity` CHECK (`quantity` is null or `quantity` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_retention_schedules`
--

DROP TABLE IF EXISTS `team8_retention_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_retention_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `record_type` varchar(150) NOT NULL,
  `retention_years` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team8_retention_record_type` (`record_type`),
  CONSTRAINT `chk_team8_retention_years` CHECK (`retention_years` >= 1)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `team8_visitors`
--

DROP TABLE IF EXISTS `team8_visitors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team8_visitors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(150) NOT NULL,
  `visitor_type` varchar(100) DEFAULT NULL,
  `contact` varchar(30) DEFAULT NULL,
  `company` varchar(150) DEFAULT NULL,
  `person_to_visit` varchar(150) DEFAULT NULL,
  `purpose` varchar(255) NOT NULL,
  `scheduled_date` datetime NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'scheduled',
  `check_in_time` datetime DEFAULT NULL,
  `check_out_time` datetime DEFAULT NULL,
  `logged_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_team8_visitors_logger` (`logged_by`),
  KEY `idx_team8_visitors_status` (`status`),
  KEY `idx_team8_visitors_status_scheduled` (`status`,`scheduled_date`),
  KEY `idx_team8_visitors_scheduled` (`scheduled_date`),
  CONSTRAINT `fk_team8_visitors_logger` FOREIGN KEY (`logged_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_roles`
--

DROP TABLE IF EXISTS `user_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_roles_user_role` (`user_id`,`role_id`),
  KEY `fk_user_roles_role` (`role_id`),
  CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `department_id` int(11) DEFAULT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `fk_users_department` (`department_id`),
  CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping routines for database 'capstone_shared_db'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-27  1:37:38