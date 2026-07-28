-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 22, 2026 at 09:49 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `capstone_shared_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `entity_type` varchar(100) NOT NULL,
  `entity_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `entity_type`, `entity_id`, `action`, `old_value`, `new_value`, `created_at`) VALUES
(1, 1, 'visitor', 1, 'check_in', NULL, NULL, '2026-07-22 00:00:02'),
(2, 1, 'visitor', 1, 'check_out', NULL, NULL, '2026-07-22 00:00:40'),
(3, 1, 'visitor', 2, 'check_in', NULL, NULL, '2026-07-22 00:02:38'),
(4, 1, 'reservation', 1, 'create_auto_approved', NULL, NULL, '2026-07-22 00:03:35'),
(5, 1, 'user', 1, 'logout', NULL, NULL, '2026-07-22 00:04:40'),
(6, 1, 'visitor', 2, 'check_out', NULL, NULL, '2026-07-22 00:32:38'),
(7, 1, 'visitor', 4, 'check_in', NULL, NULL, '2026-07-22 04:31:29'),
(8, 1, 'visitor', 4, 'check_out', NULL, NULL, '2026-07-22 04:31:48'),
(9, 1, 'contract', 1, 'create', NULL, NULL, '2026-07-22 15:44:20'),
(10, 1, 'legal_case', 1, 'create', NULL, NULL, '2026-07-22 15:45:31'),
(11, 1, 'document', 1, 'create', NULL, NULL, '2026-07-22 15:46:27');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `name`, `created_at`, `updated_at`) VALUES
(1, 'Facilities & Administration', '2026-07-21 23:20:58', '2026-07-21 23:20:58'),
(2, 'Legal', '2026-07-21 23:20:58', '2026-07-21 23:20:58'),
(3, 'General Staff', '2026-07-21 23:20:58', '2026-07-21 23:20:58');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` varchar(500) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'unread',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `role_name` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `role_name`, `created_at`) VALUES
(1, 'admin', '2026-07-21 23:20:58'),
(2, 'facilities_staff', '2026-07-21 23:20:58'),
(3, 'front_desk', '2026-07-21 23:20:58'),
(4, 'records_officer', '2026-07-21 23:20:58'),
(5, 'legal_officer', '2026-07-21 23:20:58'),
(6, 'employee', '2026-07-21 23:20:58');

-- --------------------------------------------------------

--
-- Table structure for table `team8_compliance_checks`
--

CREATE TABLE `team8_compliance_checks` (
  `id` int(11) NOT NULL,
  `record_id` int(11) NOT NULL,
  `checked_by` int(11) NOT NULL,
  `check_date` date NOT NULL,
  `result` varchar(30) NOT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `team8_contracts`
--

CREATE TABLE `team8_contracts` (
  `id` int(11) NOT NULL,
  `owner_id` int(11) NOT NULL,
  `renewed_from_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'draft',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_contracts`
--

INSERT INTO `team8_contracts` (`id`, `owner_id`, `renewed_from_id`, `title`, `start_date`, `end_date`, `status`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 2, NULL, 'foods', '2026-07-22', '2026-07-23', 'active', '2026-07-22 15:44:20', '2026-07-22 15:44:20', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `team8_contract_documents`
--

CREATE TABLE `team8_contract_documents` (
  `id` int(11) NOT NULL,
  `contract_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `team8_contract_obligations`
--

CREATE TABLE `team8_contract_obligations` (
  `id` int(11) NOT NULL,
  `contract_id` int(11) NOT NULL,
  `description` varchar(500) NOT NULL,
  `due_date` date DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `team8_contract_parties`
--

CREATE TABLE `team8_contract_parties` (
  `id` int(11) NOT NULL,
  `contract_id` int(11) NOT NULL,
  `party_id` int(11) NOT NULL,
  `role_in_contract` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `team8_documents`
--

CREATE TABLE `team8_documents` (
  `id` int(11) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `current_version` int(11) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_documents`
--

INSERT INTO `team8_documents` (`id`, `category_id`, `uploaded_by`, `title`, `file_path`, `current_version`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 3, 1, 'report', 'documents/report_v1_c83059ef.docx', 1, '2026-07-22 15:46:27', '2026-07-22 15:46:27', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `team8_document_categories`
--

CREATE TABLE `team8_document_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_document_categories`
--

INSERT INTO `team8_document_categories` (`id`, `name`, `created_at`) VALUES
(1, 'Policies', '2026-07-21 23:20:58'),
(2, 'Contracts', '2026-07-21 23:20:58'),
(3, 'Legal Filings', '2026-07-21 23:20:58');

-- --------------------------------------------------------

--
-- Table structure for table `team8_document_versions`
--

CREATE TABLE `team8_document_versions` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `version_no` int(11) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` bigint(20) NOT NULL DEFAULT 0,
  `checksum` varchar(128) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_document_versions`
--

INSERT INTO `team8_document_versions` (`id`, `document_id`, `version_no`, `file_path`, `file_size`, `checksum`, `uploaded_at`) VALUES
(1, 1, 1, 'documents/report_v1_c83059ef.docx', 322955, '769b2a9fe8960f855660067c7ee5fcad557b8da668d3b27700e4bdbd75af29fd', '2026-07-22 15:46:27');

-- --------------------------------------------------------

--
-- Table structure for table `team8_equipment`
--

CREATE TABLE `team8_equipment` (
  `id` int(11) NOT NULL,
  `home_facility_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_equipment`
--

INSERT INTO `team8_equipment` (`id`, `home_facility_id`, `name`, `quantity`, `created_at`, `updated_at`) VALUES
(1, 1, 'Projector', 2, '2026-07-21 23:20:58', '2026-07-21 23:20:58'),
(2, 1, 'Wireless Microphone', 4, '2026-07-21 23:20:58', '2026-07-21 23:20:58'),
(3, 2, 'Portable Speaker', 3, '2026-07-21 23:20:58', '2026-07-21 23:20:58'),
(4, 2, 'Foldable Chairs', 60, '2026-07-21 23:20:58', '2026-07-21 23:20:58');

-- --------------------------------------------------------

--
-- Table structure for table `team8_facilities`
--

CREATE TABLE `team8_facilities` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `location` varchar(200) NOT NULL,
  `capacity` int(11) NOT NULL DEFAULT 0,
  `description` text DEFAULT NULL,
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_facilities`
--

INSERT INTO `team8_facilities` (`id`, `name`, `location`, `capacity`, `description`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Main Conference Room', 'Building A, 2nd Floor', 20, NULL, 'active', '2026-07-21 23:20:58', '2026-07-21 23:20:58'),
(2, 'Training Hall', 'Building B, Ground Floor', 60, NULL, 'active', '2026-07-21 23:20:58', '2026-07-21 23:20:58');

-- --------------------------------------------------------

--
-- Table structure for table `team8_legal_cases`
--

CREATE TABLE `team8_legal_cases` (
  `id` int(11) NOT NULL,
  `assigned_to` int(11) NOT NULL,
  `contract_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'open',
  `filed_date` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_legal_cases`
--

INSERT INTO `team8_legal_cases` (`id`, `assigned_to`, `contract_id`, `title`, `status`, `filed_date`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 2, NULL, 'client', 'in_progress', '2026-07-22', '2026-07-22 15:45:31', '2026-07-22 15:45:31', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `team8_legal_documents`
--

CREATE TABLE `team8_legal_documents` (
  `id` int(11) NOT NULL,
  `case_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `team8_parties`
--

CREATE TABLE `team8_parties` (
  `id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `type` varchar(50) NOT NULL,
  `contact_email` varchar(150) DEFAULT NULL,
  `contact_phone` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `team8_records`
--

CREATE TABLE `team8_records` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `schedule_id` int(11) NOT NULL,
  `custodian_id` int(11) NOT NULL,
  `disposition_date` date DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `team8_reservations`
--

CREATE TABLE `team8_reservations` (
  `id` int(11) NOT NULL,
  `facility_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `description` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_reservations`
--

INSERT INTO `team8_reservations` (`id`, `facility_id`, `user_id`, `start_time`, `end_time`, `status`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 1, 1, '2026-07-22 00:03:00', '2026-07-23 00:03:00', 'approved', '2026-07-22 00:03:35', '2026-07-22 00:03:35', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `team8_reservation_approvals`
--

CREATE TABLE `team8_reservation_approvals` (
  `id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `approver_id` int(11) NOT NULL,
  `step_order` int(11) NOT NULL DEFAULT 1,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `decided_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_reservation_approvals`
--

INSERT INTO `team8_reservation_approvals` (`id`, `reservation_id`, `approver_id`, `step_order`, `status`, `decided_at`) VALUES
(1, 1, 1, 1, 'approved', '2026-07-22 00:03:35');

-- --------------------------------------------------------

--
-- Table structure for table `team8_reservation_equipment`
--

CREATE TABLE `team8_reservation_equipment` (
  `id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `equipment_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `team8_retention_schedules`
--

CREATE TABLE `team8_retention_schedules` (
  `id` int(11) NOT NULL,
  `record_type` varchar(150) NOT NULL,
  `retention_years` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_retention_schedules`
--

INSERT INTO `team8_retention_schedules` (`id`, `record_type`, `retention_years`, `created_at`) VALUES
(1, 'HR Records', 5, '2026-07-21 23:20:58'),
(2, 'Financial Records', 7, '2026-07-21 23:20:58'),
(3, 'Legal Filings', 10, '2026-07-21 23:20:58');

-- --------------------------------------------------------

--
-- Table structure for table `team8_visitors`
--

CREATE TABLE `team8_visitors` (
  `id` int(11) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `id_number` varchar(100) DEFAULT NULL,
  `contact` varchar(150) DEFAULT NULL,
  `person_to_visit` varchar(150) NOT NULL,
  `purpose` varchar(255) NOT NULL,
  `check_in_time` datetime NOT NULL,
  `check_out_time` datetime DEFAULT NULL,
  `logged_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_visitors`
--

INSERT INTO `team8_visitors` (`id`, `full_name`, `id_number`, `contact`, `person_to_visit`, `purpose`, `check_in_time`, `check_out_time`, `logged_by`, `created_at`, `updated_at`) VALUES
(1, 'James', '12345689', '000099999', 'my ari ng aircon', 'checking and cleaning', '2026-07-21 23:56:00', '2026-07-22 00:00:40', 1, '2026-07-22 00:00:02', '2026-07-22 00:00:40'),
(2, 'christian', '22334455', '99990000', 'my ari ng aircon', 'checking and cleaning', '2026-07-22 00:02:00', '2026-07-22 00:32:38', 1, '2026-07-22 00:02:38', '2026-07-22 00:32:38'),
(4, 'chris', '31231231', '5634626234', 'computer', 'checking', '2026-07-22 04:30:00', '2026-07-22 04:31:48', 1, '2026-07-22 04:31:29', '2026-07-22 04:31:48');

-- --------------------------------------------------------

--
-- Table structure for table `team8_visits`
--

CREATE TABLE `team8_visits` (
  `id` int(11) NOT NULL,
  `visitor_id` int(11) NOT NULL,
  `host_id` int(11) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'expected',
  `check_in` datetime DEFAULT NULL,
  `check_out` datetime DEFAULT NULL,
  `purpose` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `department_id`, `full_name`, `email`, `password_hash`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 1, 'Dev Tester', 'dev.tester@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-07-21 23:20:58', '2026-07-21 23:20:58', NULL),
(2, 1, 'Facilities Fran', 'facilities@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-07-21 23:20:58', '2026-07-21 23:20:58', NULL),
(3, 3, 'Frontdesk Fred', 'frontdesk@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-07-21 23:20:58', '2026-07-21 23:20:58', NULL),
(4, 2, 'Legal Lena', 'legal@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-07-21 23:20:58', '2026-07-21 23:20:58', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`id`, `user_id`, `role_id`, `created_at`) VALUES
(1, 1, 1, '2026-07-21 23:20:58'),
(2, 2, 2, '2026-07-21 23:20:58'),
(3, 3, 3, '2026-07-21 23:20:58'),
(4, 4, 5, '2026-07-21 23:20:58');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_audit_logs_user` (`user_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_notifications_user` (`user_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Indexes for table `team8_compliance_checks`
--
ALTER TABLE `team8_compliance_checks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_compliance_record` (`record_id`),
  ADD KEY `fk_team8_compliance_checker` (`checked_by`);

--
-- Indexes for table `team8_contracts`
--
ALTER TABLE `team8_contracts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_contracts_owner` (`owner_id`),
  ADD KEY `fk_team8_contracts_renewed` (`renewed_from_id`),
  ADD KEY `idx_team8_contracts_status` (`status`),
  ADD KEY `idx_team8_contracts_enddate` (`end_date`);

--
-- Indexes for table `team8_contract_documents`
--
ALTER TABLE `team8_contract_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_contractdocs_contract` (`contract_id`),
  ADD KEY `fk_team8_contractdocs_document` (`document_id`);

--
-- Indexes for table `team8_contract_obligations`
--
ALTER TABLE `team8_contract_obligations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_contractobl_contract` (`contract_id`),
  ADD KEY `idx_team8_contractobl_duedate` (`due_date`);

--
-- Indexes for table `team8_contract_parties`
--
ALTER TABLE `team8_contract_parties`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_contractparties_contract` (`contract_id`),
  ADD KEY `fk_team8_contractparties_party` (`party_id`);

--
-- Indexes for table `team8_documents`
--
ALTER TABLE `team8_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_documents_category` (`category_id`),
  ADD KEY `fk_team8_documents_uploader` (`uploaded_by`),
  ADD KEY `idx_team8_documents_title` (`title`);

--
-- Indexes for table `team8_document_categories`
--
ALTER TABLE `team8_document_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `team8_document_versions`
--
ALTER TABLE `team8_document_versions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_docversions_document` (`document_id`);

--
-- Indexes for table `team8_equipment`
--
ALTER TABLE `team8_equipment`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_equipment_facility` (`home_facility_id`);

--
-- Indexes for table `team8_facilities`
--
ALTER TABLE `team8_facilities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_team8_facilities_status` (`status`);

--
-- Indexes for table `team8_legal_cases`
--
ALTER TABLE `team8_legal_cases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_legalcases_assignee` (`assigned_to`),
  ADD KEY `fk_team8_legalcases_contract` (`contract_id`),
  ADD KEY `idx_team8_legalcases_status` (`status`);

--
-- Indexes for table `team8_legal_documents`
--
ALTER TABLE `team8_legal_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_legaldocs_case` (`case_id`),
  ADD KEY `fk_team8_legaldocs_document` (`document_id`);

--
-- Indexes for table `team8_parties`
--
ALTER TABLE `team8_parties`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `team8_records`
--
ALTER TABLE `team8_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_records_document` (`document_id`),
  ADD KEY `fk_team8_records_schedule` (`schedule_id`),
  ADD KEY `fk_team8_records_custodian` (`custodian_id`),
  ADD KEY `idx_team8_records_status` (`status`),
  ADD KEY `idx_team8_records_disposition` (`disposition_date`);

--
-- Indexes for table `team8_reservations`
--
ALTER TABLE `team8_reservations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_reservations_facility` (`facility_id`),
  ADD KEY `fk_team8_reservations_user` (`user_id`),
  ADD KEY `idx_team8_reservations_status` (`status`),
  ADD KEY `idx_team8_reservations_dates` (`start_time`,`end_time`);

--
-- Indexes for table `team8_reservation_approvals`
--
ALTER TABLE `team8_reservation_approvals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_resapproval_reservation` (`reservation_id`),
  ADD KEY `fk_team8_resapproval_approver` (`approver_id`);

--
-- Indexes for table `team8_reservation_equipment`
--
ALTER TABLE `team8_reservation_equipment`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_resequip_reservation` (`reservation_id`),
  ADD KEY `fk_team8_resequip_equipment` (`equipment_id`);

--
-- Indexes for table `team8_retention_schedules`
--
ALTER TABLE `team8_retention_schedules`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `team8_visitors`
--
ALTER TABLE `team8_visitors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_visitors_logged_by` (`logged_by`);

--
-- Indexes for table `team8_visits`
--
ALTER TABLE `team8_visits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_visits_visitor` (`visitor_id`),
  ADD KEY `fk_team8_visits_host` (`host_id`),
  ADD KEY `idx_team8_visits_status` (`status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `fk_users_department` (`department_id`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_user_roles_user` (`user_id`),
  ADD KEY `fk_user_roles_role` (`role_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `team8_compliance_checks`
--
ALTER TABLE `team8_compliance_checks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team8_contracts`
--
ALTER TABLE `team8_contracts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `team8_contract_documents`
--
ALTER TABLE `team8_contract_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team8_contract_obligations`
--
ALTER TABLE `team8_contract_obligations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team8_contract_parties`
--
ALTER TABLE `team8_contract_parties`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team8_documents`
--
ALTER TABLE `team8_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `team8_document_categories`
--
ALTER TABLE `team8_document_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `team8_document_versions`
--
ALTER TABLE `team8_document_versions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `team8_equipment`
--
ALTER TABLE `team8_equipment`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `team8_facilities`
--
ALTER TABLE `team8_facilities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `team8_legal_cases`
--
ALTER TABLE `team8_legal_cases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `team8_legal_documents`
--
ALTER TABLE `team8_legal_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team8_parties`
--
ALTER TABLE `team8_parties`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team8_records`
--
ALTER TABLE `team8_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team8_reservations`
--
ALTER TABLE `team8_reservations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `team8_reservation_approvals`
--
ALTER TABLE `team8_reservation_approvals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `team8_reservation_equipment`
--
ALTER TABLE `team8_reservation_equipment`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team8_retention_schedules`
--
ALTER TABLE `team8_retention_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `team8_visitors`
--
ALTER TABLE `team8_visitors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `team8_visits`
--
ALTER TABLE `team8_visits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `user_roles`
--
ALTER TABLE `user_roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `team8_compliance_checks`
--
ALTER TABLE `team8_compliance_checks`
  ADD CONSTRAINT `fk_team8_compliance_checker` FOREIGN KEY (`checked_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_compliance_record` FOREIGN KEY (`record_id`) REFERENCES `team8_records` (`id`);

--
-- Constraints for table `team8_contracts`
--
ALTER TABLE `team8_contracts`
  ADD CONSTRAINT `fk_team8_contracts_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_contracts_renewed` FOREIGN KEY (`renewed_from_id`) REFERENCES `team8_contracts` (`id`);

--
-- Constraints for table `team8_contract_documents`
--
ALTER TABLE `team8_contract_documents`
  ADD CONSTRAINT `fk_team8_contractdocs_contract` FOREIGN KEY (`contract_id`) REFERENCES `team8_contracts` (`id`),
  ADD CONSTRAINT `fk_team8_contractdocs_document` FOREIGN KEY (`document_id`) REFERENCES `team8_documents` (`id`);

--
-- Constraints for table `team8_contract_obligations`
--
ALTER TABLE `team8_contract_obligations`
  ADD CONSTRAINT `fk_team8_contractobl_contract` FOREIGN KEY (`contract_id`) REFERENCES `team8_contracts` (`id`);

--
-- Constraints for table `team8_contract_parties`
--
ALTER TABLE `team8_contract_parties`
  ADD CONSTRAINT `fk_team8_contractparties_contract` FOREIGN KEY (`contract_id`) REFERENCES `team8_contracts` (`id`),
  ADD CONSTRAINT `fk_team8_contractparties_party` FOREIGN KEY (`party_id`) REFERENCES `team8_parties` (`id`);

--
-- Constraints for table `team8_documents`
--
ALTER TABLE `team8_documents`
  ADD CONSTRAINT `fk_team8_documents_category` FOREIGN KEY (`category_id`) REFERENCES `team8_document_categories` (`id`),
  ADD CONSTRAINT `fk_team8_documents_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `team8_document_versions`
--
ALTER TABLE `team8_document_versions`
  ADD CONSTRAINT `fk_team8_docversions_document` FOREIGN KEY (`document_id`) REFERENCES `team8_documents` (`id`);

--
-- Constraints for table `team8_equipment`
--
ALTER TABLE `team8_equipment`
  ADD CONSTRAINT `fk_team8_equipment_facility` FOREIGN KEY (`home_facility_id`) REFERENCES `team8_facilities` (`id`);

--
-- Constraints for table `team8_legal_cases`
--
ALTER TABLE `team8_legal_cases`
  ADD CONSTRAINT `fk_team8_legalcases_assignee` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_legalcases_contract` FOREIGN KEY (`contract_id`) REFERENCES `team8_contracts` (`id`);

--
-- Constraints for table `team8_legal_documents`
--
ALTER TABLE `team8_legal_documents`
  ADD CONSTRAINT `fk_team8_legaldocs_case` FOREIGN KEY (`case_id`) REFERENCES `team8_legal_cases` (`id`),
  ADD CONSTRAINT `fk_team8_legaldocs_document` FOREIGN KEY (`document_id`) REFERENCES `team8_documents` (`id`);

--
-- Constraints for table `team8_records`
--
ALTER TABLE `team8_records`
  ADD CONSTRAINT `fk_team8_records_custodian` FOREIGN KEY (`custodian_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_records_document` FOREIGN KEY (`document_id`) REFERENCES `team8_documents` (`id`),
  ADD CONSTRAINT `fk_team8_records_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `team8_retention_schedules` (`id`);

--
-- Constraints for table `team8_reservations`
--
ALTER TABLE `team8_reservations`
  ADD CONSTRAINT `fk_team8_reservations_facility` FOREIGN KEY (`facility_id`) REFERENCES `team8_facilities` (`id`),
  ADD CONSTRAINT `fk_team8_reservations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `team8_reservation_approvals`
--
ALTER TABLE `team8_reservation_approvals`
  ADD CONSTRAINT `fk_team8_resapproval_approver` FOREIGN KEY (`approver_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_resapproval_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `team8_reservations` (`id`);

--
-- Constraints for table `team8_reservation_equipment`
--
ALTER TABLE `team8_reservation_equipment`
  ADD CONSTRAINT `fk_team8_resequip_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `team8_equipment` (`id`),
  ADD CONSTRAINT `fk_team8_resequip_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `team8_reservations` (`id`);

--
-- Constraints for table `team8_visitors`
--
ALTER TABLE `team8_visitors`
  ADD CONSTRAINT `fk_team8_visitors_logged_by` FOREIGN KEY (`logged_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `team8_visits`
--
ALTER TABLE `team8_visits`
  ADD CONSTRAINT `fk_team8_visits_host` FOREIGN KEY (`host_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_visits_visitor` FOREIGN KEY (`visitor_id`) REFERENCES `team8_visitors` (`id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`);

--
-- Constraints for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  ADD CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
