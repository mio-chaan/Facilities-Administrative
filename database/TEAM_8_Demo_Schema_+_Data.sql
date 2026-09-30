-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 30, 2026 at 11:31 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

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
(28, 1, 'user', 1, 'login', NULL, NULL, '2026-09-30 17:02:15'),
(29, 1, 'visitor', 15, 'late', 'scheduled', 'scheduled arrival time passed', '2026-09-30 17:03:13'),
(30, 1, 'visitor', 12, 'expired', 'scheduled', '10:00 PM cutoff passed without check-in', '2026-09-30 17:03:13'),
(31, 1, 'visitor', 13, 'expired', 'scheduled', '10:00 PM cutoff passed without check-in', '2026-09-30 17:03:13'),
(32, 1, 'visitor', 14, 'expired', 'scheduled', '10:00 PM cutoff passed without check-in', '2026-09-30 17:03:13'),
(33, 1, 'reservation', 5, 'approve', NULL, NULL, '2026-09-30 17:18:48'),
(34, 1, 'reservation', 11, 'approve', NULL, NULL, '2026-09-30 17:19:16'),
(35, 1, 'user', 1, 'login', NULL, NULL, '2026-09-30 17:22:38');

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
(1, 'Administration', '2026-09-30 16:50:45', '2026-09-30 16:50:45'),
(2, 'Finance', '2026-09-30 16:50:45', '2026-09-30 16:50:45'),
(3, 'Human Resources', '2026-09-30 16:50:45', '2026-09-30 16:50:45'),
(4, 'Information Technology', '2026-09-30 16:50:45', '2026-09-30 16:50:45'),
(5, 'Legal', '2026-09-30 16:50:45', '2026-09-30 16:50:45'),
(6, 'Operations', '2026-09-30 16:50:45', '2026-09-30 16:50:45'),
(7, 'Procurement', '2026-09-30 16:50:45', '2026-09-30 16:50:45'),
(8, 'Facilities', '2026-09-30 16:50:45', '2026-09-30 16:50:45'),
(9, 'Security', '2026-09-30 16:50:45', '2026-09-30 16:50:45'),
(10, 'Marketing', '2026-09-30 16:50:45', '2026-09-30 16:50:45'),
(11, 'Customer Service', '2026-09-30 16:50:45', '2026-09-30 16:50:45');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` varchar(500) NOT NULL,
  `target_url` varchar(500) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'unread',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `message`, `target_url`, `status`, `created_at`) VALUES
(1, 1, 'Reservation approvals require review.', 'index.php?page=reservation', 'unread', '2026-09-30 17:02:16'),
(2, 25, 'Reservation approvals require review.', 'index.php?page=reservation', 'unread', '2026-09-30 17:02:16'),
(3, 1, 'Document submissions require review.', 'index.php?page=documents&action=browse&status=pending', 'unread', '2026-09-30 17:02:16'),
(4, 25, 'Document submissions require review.', 'index.php?page=documents&action=browse&status=pending', 'unread', '2026-09-30 17:02:16'),
(5, 1, 'Visitor activity is scheduled today.', 'index.php?page=visitor', 'unread', '2026-09-30 17:02:16'),
(6, 25, 'Visitor activity is scheduled today.', 'index.php?page=visitor', 'unread', '2026-09-30 17:02:16');

-- --------------------------------------------------------

--
-- Table structure for table `renewal_requests`
--

CREATE TABLE `renewal_requests` (
  `id` int(11) NOT NULL,
  `main_document_type` varchar(150) NOT NULL,
  `status` enum('in_progress','completed') NOT NULL DEFAULT 'in_progress',
  `initiated_by` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `renewal_requests`
--

INSERT INTO `renewal_requests` (`id`, `main_document_type`, `status`, `initiated_by`, `notes`, `created_at`, `updated_at`) VALUES
(1, 'Mayor\'s Business Permit', 'in_progress', 25, 'Renewal for 2027. Gathering prerequisites.', '2026-09-30 17:00:25', '2026-09-30 17:00:25'),
(2, 'Sanitary Permit', 'in_progress', 25, 'Renewal for 2027. Health certificates being updated.', '2026-09-30 17:00:25', '2026-09-30 17:00:25'),
(3, 'Fire Safety Inspection Certificate', 'completed', 25, 'Renewed for 2026-2027.', '2026-09-30 17:00:25', '2026-09-30 17:00:25'),
(4, 'Mayor\'s Business Permit', 'completed', 25, 'Renewed for 2026.', '2026-09-30 17:00:25', '2026-09-30 17:00:25'),
(5, 'Sanitary Permit', 'completed', 25, 'Renewed for 2026.', '2026-09-30 17:00:25', '2026-09-30 17:00:25');

-- --------------------------------------------------------

--
-- Table structure for table `retention_renewal_rules`
--

CREATE TABLE `retention_renewal_rules` (
  `id` int(11) NOT NULL,
  `main_document_type` varchar(150) NOT NULL,
  `prerequisite_document_type` varchar(150) NOT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `retention_renewal_rules`
--

INSERT INTO `retention_renewal_rules` (`id`, `main_document_type`, `prerequisite_document_type`, `is_required`, `created_at`, `updated_at`) VALUES
(1, 'Mayor\'s Business Permit', 'Barangay Business Clearance', 1, '2026-09-30 08:50:35', '2026-09-30 08:50:35'),
(2, 'Mayor\'s Business Permit', 'Community Tax Certificate (Cedula)', 1, '2026-09-30 08:50:35', '2026-09-30 08:50:35'),
(3, 'Mayor\'s Business Permit', 'Real Property Tax Clearance', 1, '2026-09-30 08:50:35', '2026-09-30 08:50:35'),
(4, 'Sanitary Permit', 'Staff Health Certificates', 1, '2026-09-30 08:50:35', '2026-09-30 08:50:35'),
(5, 'Sanitary Permit', 'Water Potability Test Result', 1, '2026-09-30 08:50:35', '2026-09-30 08:50:35'),
(6, 'Fire Safety Inspection Certificate', 'Fire Extinguisher Inspection Log', 1, '2026-09-30 08:50:35', '2026-09-30 08:50:35'),
(7, 'Fire Safety Inspection Certificate', 'Building Safety Clearance', 1, '2026-09-30 08:50:35', '2026-09-30 08:50:35');

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
(1, 'admin', '2026-09-30 16:50:45'),
(2, 'facilities_staff', '2026-09-30 16:50:45'),
(3, 'front_desk', '2026-09-30 16:50:45'),
(4, 'records_officer', '2026-09-30 16:50:45'),
(5, 'legal_officer', '2026-09-30 16:50:45'),
(6, 'employee', '2026-09-30 16:50:45');

-- --------------------------------------------------------

--
-- Table structure for table `team8_certificates`
--

CREATE TABLE `team8_certificates` (
  `id` int(11) NOT NULL,
  `document_number` varchar(40) NOT NULL,
  `certificate_type` varchar(50) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `prepared_by` int(11) NOT NULL,
  `details` text DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'draft',
  `rejection_reason` text DEFAULT NULL,
  `current_version` int(11) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `team8_certificate_recipients`
--

CREATE TABLE `team8_certificate_recipients` (
  `id` int(11) NOT NULL,
  `certificate_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

--
-- Dumping data for table `team8_compliance_checks`
--

INSERT INTO `team8_compliance_checks` (`id`, `record_id`, `checked_by`, `check_date`, `result`, `notes`, `created_at`) VALUES
(1, 1, 25, '2026-01-15', 'compliant', 'All documents in order.', '2026-09-30 17:00:24'),
(2, 2, 25, '2026-01-20', 'compliant', 'Contract terms met.', '2026-09-30 17:00:24'),
(3, 3, 25, '2026-02-01', 'non-compliant', 'Missing safety certificate.', '2026-09-30 17:00:24'),
(4, 4, 25, '2026-02-10', 'compliant', 'Financial records complete.', '2026-09-30 17:00:24'),
(5, 5, 25, '2026-02-15', 'compliant', 'Inventory records verified.', '2026-09-30 17:00:24'),
(6, 6, 25, '2026-03-01', 'compliant', 'Maintenance logs updated.', '2026-09-30 17:00:24'),
(7, 7, 25, '2026-03-10', 'compliant', 'HR records complete.', '2026-09-30 17:00:24'),
(8, 8, 25, '2026-03-15', 'pending', 'Awaiting IT security review.', '2026-09-30 17:00:24'),
(9, 9, 25, '2026-04-01', 'compliant', 'Legal templates approved.', '2026-09-30 17:00:24'),
(10, 10, 25, '2026-04-10', 'compliant', 'Service agreement compliant.', '2026-09-30 17:00:24'),
(11, 11, 25, '2026-05-01', 'compliant', 'Remote work policy compliant.', '2026-09-30 17:00:24'),
(12, 12, 25, '2026-05-15', 'non-compliant', 'Fire safety certificate expired.', '2026-09-30 17:00:24'),
(13, 13, 25, '2026-06-01', 'pending', 'Budget proposal under review.', '2026-09-30 17:00:24'),
(14, 14, 25, '2026-06-10', 'compliant', 'Recruitment plan approved.', '2026-09-30 17:00:24'),
(15, 15, 25, '2026-07-01', 'compliant', 'IP policy compliant.', '2026-09-30 17:00:24'),
(16, 16, 25, '2026-07-15', 'pending', 'Vendor agreement pending approval.', '2026-09-30 17:00:24'),
(17, 17, 25, '2026-08-01', 'compliant', 'Operational report complete.', '2026-09-30 17:00:24'),
(18, 18, 25, '2026-08-10', 'compliant', 'HVAC maintenance up to date.', '2026-09-30 17:00:24'),
(19, 19, 25, '2026-09-01', 'compliant', 'Security audit passed.', '2026-09-30 17:00:24'),
(20, 20, 25, '2026-09-10', 'compliant', 'Tax filing complete.', '2026-09-30 17:00:24'),
(21, 21, 25, '2026-09-15', 'compliant', 'Training needs assessed.', '2026-09-30 17:00:24'),
(22, 22, 25, '2026-09-20', 'compliant', 'Marketing strategy approved.', '2026-09-30 17:00:24'),
(23, 23, 25, '2026-09-25', 'compliant', 'Equipment list verified.', '2026-09-30 17:00:24'),
(24, 24, 25, '2026-09-28', 'compliant', 'Lease agreement compliant.', '2026-09-30 17:00:24'),
(25, 25, 25, '2026-09-29', 'compliant', 'Compliance checklist complete.', '2026-09-30 17:00:24');

-- --------------------------------------------------------

--
-- Table structure for table `team8_compliance_reports`
--

CREATE TABLE `team8_compliance_reports` (
  `id` int(11) NOT NULL,
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
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `team8_compliance_reports`
--

INSERT INTO `team8_compliance_reports` (`id`, `report_type`, `department_id`, `date_from`, `date_to`, `executive_summary`, `key_findings`, `check_details`, `generated_by`, `status`, `approved_by`, `approved_at`, `document_id`, `recipient_emails`, `email_sent_at`, `created_at`, `updated_at`) VALUES
(1, 'Monthly Compliance Report', 1, '2026-01-01', '2026-01-31', 'All departments compliant for January 2026.', 'No major issues found.', '[]', 25, 'approved', 1, '2026-02-01 10:00:00', NULL, NULL, NULL, '2026-09-30 17:00:25', '2026-09-30 17:00:25'),
(2, 'Monthly Compliance Report', 1, '2026-02-01', '2026-02-28', 'Two minor issues identified in February 2026.', 'Safety certificate missing; fire safety expired.', '[]', 25, 'approved', 1, '2026-03-01 10:00:00', NULL, NULL, NULL, '2026-09-30 17:00:25', '2026-09-30 17:00:25'),
(3, 'Quarterly Compliance Report', 1, '2026-01-01', '2026-03-31', 'Q1 2026 overall compliance at 92%.', 'Improved from Q4 2025.', '[]', 25, 'approved', 1, '2026-04-05 10:00:00', NULL, NULL, NULL, '2026-09-30 17:00:25', '2026-09-30 17:00:25'),
(4, 'Monthly Compliance Report', 1, '2026-03-01', '2026-03-31', 'March 2026 compliance report.', 'All items compliant.', '[]', 25, 'approved', 1, '2026-04-01 10:00:00', NULL, NULL, NULL, '2026-09-30 17:00:25', '2026-09-30 17:00:25'),
(5, 'Monthly Compliance Report', 1, '2026-04-01', '2026-04-30', 'April 2026 compliance report.', 'All items compliant.', '[]', 25, 'approved', 1, '2026-05-01 10:00:00', NULL, NULL, NULL, '2026-09-30 17:00:25', '2026-09-30 17:00:25'),
(6, 'Monthly Compliance Report', 1, '2026-05-01', '2026-05-31', 'May 2026 compliance report.', 'Fire safety certificate expired.', '[]', 25, 'approved', 1, '2026-06-01 10:00:00', NULL, NULL, NULL, '2026-09-30 17:00:25', '2026-09-30 17:00:25'),
(7, 'Monthly Compliance Report', 1, '2026-06-01', '2026-06-30', 'June 2026 compliance report.', 'All items compliant.', '[]', 25, 'approved', 1, '2026-07-01 10:00:00', NULL, NULL, NULL, '2026-09-30 17:00:25', '2026-09-30 17:00:25'),
(8, 'Quarterly Compliance Report', 1, '2026-04-01', '2026-06-30', 'Q2 2026 overall compliance at 95%.', 'Continued improvement.', '[]', 25, 'approved', 1, '2026-07-05 10:00:00', NULL, NULL, NULL, '2026-09-30 17:00:25', '2026-09-30 17:00:25'),
(9, 'Monthly Compliance Report', 1, '2026-07-01', '2026-07-31', 'July 2026 compliance report.', 'All items compliant.', '[]', 25, 'draft', NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:25', '2026-09-30 17:00:25'),
(10, 'Monthly Compliance Report', 1, '2026-08-01', '2026-08-31', 'August 2026 compliance report.', 'All items compliant.', '[]', 25, 'draft', NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:25', '2026-09-30 17:00:25');

-- --------------------------------------------------------

--
-- Table structure for table `team8_compliance_report_documents`
--

CREATE TABLE `team8_compliance_report_documents` (
  `compliance_report_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `attached_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `team8_contracts`
--

CREATE TABLE `team8_contracts` (
  `id` int(11) NOT NULL,
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
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_contracts`
--

INSERT INTO `team8_contracts` (`id`, `contract_number`, `owner_id`, `department_id`, `renewed_from_id`, `title`, `contract_type`, `description`, `start_date`, `end_date`, `renewal_date`, `amount`, `currency`, `payment_terms`, `payment_frequency`, `deposit_amount`, `financial_notes`, `notice_period_days`, `termination_date`, `termination_reason`, `rejection_reason`, `attachment_path`, `attachment_type`, `attachment_name`, `status`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'CON-2026-001', 4, 5, NULL, 'Supplier Agreement - ABC Corp', 'Supply Agreement', 'Annual supply of office materials.', '2026-01-01', '2027-12-31', '2027-11-01', 1500000.00, 'PHP', 'Net 30', 'Quarterly', 50000.00, NULL, 60, NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2026-09-30 16:58:05', '2026-09-30 16:58:05', NULL),
(2, 'CON-2026-002', 4, 5, NULL, 'Service Agreement - XYZ Ltd', 'Service Agreement', 'IT managed services agreement.', '2026-02-01', '2028-03-15', '2028-02-01', 3600000.00, 'PHP', 'Net 15', 'Monthly', 100000.00, NULL, 90, NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2026-09-30 16:58:05', '2026-09-30 16:58:05', NULL),
(3, 'CON-2026-003', 10, 7, NULL, 'Vendor Agreement - DEF Inc', 'Vendor Agreement', 'Supply of specialized equipment.', '2026-03-01', '2028-06-30', '2028-05-01', 2400000.00, 'PHP', 'Net 45', 'Quarterly', 75000.00, NULL, 30, NULL, NULL, NULL, NULL, NULL, NULL, 'approval', '2026-09-30 16:58:05', '2026-09-30 16:58:05', NULL),
(4, 'CON-2026-004', 8, 5, NULL, 'Lease Agreement - Main Office', 'Lease Agreement', 'Lease of main office space.', '2026-01-01', '2030-12-31', '2030-11-01', 12000000.00, 'PHP', 'Net 30', 'Monthly', 200000.00, NULL, 120, NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2026-09-30 16:58:05', '2026-09-30 16:58:05', NULL),
(5, 'CON-2026-005', 9, 6, NULL, 'Maintenance Contract - GHI Ent', 'Maintenance', 'HVAC and facility maintenance.', '2026-04-01', '2027-03-31', '2027-02-01', 960000.00, 'PHP', 'Net 30', 'Monthly', 30000.00, NULL, 30, NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2026-09-30 16:58:05', '2026-09-30 16:58:05', NULL),
(6, 'CON-2026-006', 13, 10, NULL, 'Marketing Services - JKL Sol', 'Service Agreement', 'Digital marketing services.', '2026-05-01', '2027-04-30', '2027-03-01', 1800000.00, 'PHP', 'Net 30', 'Monthly', 50000.00, NULL, 60, NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2026-09-30 16:58:05', '2026-09-30 16:58:05', NULL),
(7, 'CON-2026-007', 10, 7, NULL, 'Supply Contract - MNO Svc', 'Supply Agreement', 'Office supplies and consumables.', '2026-01-15', '2026-12-31', '2026-11-15', 720000.00, 'PHP', 'Net 30', 'Quarterly', 20000.00, NULL, 30, NULL, NULL, NULL, NULL, NULL, NULL, 'renewal', '2026-09-30 16:58:05', '2026-09-30 16:58:05', NULL),
(8, 'CON-2026-008', 4, 5, NULL, 'Trading Agreement - PQR Trade', 'Trading Agreement', 'Trading of goods and materials.', '2026-02-01', '2027-01-31', '2026-12-31', 3000000.00, 'PHP', 'Net 60', 'Quarterly', 100000.00, NULL, 45, NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2026-09-30 16:58:05', '2026-09-30 16:58:05', NULL),
(9, 'CON-2026-009', 9, 6, NULL, 'Logistics Contract - STU Log', 'Service Agreement', 'Logistics and delivery services.', '2026-03-01', '2027-02-28', '2027-01-31', 1440000.00, 'PHP', 'Net 30', 'Monthly', 40000.00, NULL, 30, NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2026-09-30 16:58:05', '2026-09-30 16:58:05', NULL),
(10, 'CON-2026-010', 16, 2, NULL, 'Manufacturing Agreement - VWX Mfg', 'Supply Agreement', 'Manufacturing of custom parts.', '2026-01-01', '2026-12-31', '2026-11-01', 4800000.00, 'PHP', 'Net 45', 'Quarterly', 150000.00, NULL, 60, NULL, NULL, NULL, NULL, NULL, NULL, 'expiration', '2026-09-30 16:58:05', '2026-09-30 16:58:05', NULL),
(11, 'CON-2026-011', 4, 5, NULL, 'Consultancy Agreement - YZA Cons', 'Consultancy', 'Business consultancy services.', '2026-06-01', '2027-05-31', '2027-04-30', 1200000.00, 'PHP', 'Net 30', 'Monthly', 30000.00, NULL, 30, NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2026-09-30 16:58:05', '2026-09-30 16:58:05', NULL),
(12, 'CON-2026-012', 8, 5, NULL, 'Consultancy - John Smith', 'Consultancy', 'Legal consultancy services.', '2026-07-01', '2027-06-30', '2027-05-31', 600000.00, 'PHP', 'Net 30', 'Monthly', 15000.00, NULL, 30, NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2026-09-30 16:58:05', '2026-09-30 16:58:05', NULL),
(13, 'CON-2026-013', 8, 5, NULL, 'Consultancy - Maria Santos', 'Consultancy', 'HR consultancy services.', '2026-08-01', '2027-07-31', '2027-06-30', 480000.00, 'PHP', 'Net 30', 'Monthly', 12000.00, NULL, 30, NULL, NULL, NULL, NULL, NULL, NULL, 'draft', '2026-09-30 16:58:05', '2026-09-30 16:58:05', NULL),
(14, 'CON-2026-014', 10, 7, NULL, 'Supply - Robert Johnson', 'Supply Agreement', 'Supply of packaging materials.', '2026-09-01', '2027-08-31', '2027-07-31', 360000.00, 'PHP', 'Net 30', 'Quarterly', 10000.00, NULL, 30, NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2026-09-30 16:58:05', '2026-09-30 16:58:05', NULL),
(15, 'CON-2026-015', 4, 5, NULL, 'Service - Patricia Lee', 'Service Agreement', 'Cleaning services agreement.', '2026-10-01', '2027-09-30', '2027-08-31', 240000.00, 'PHP', 'Net 15', 'Monthly', 8000.00, NULL, 15, NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2026-09-30 16:58:05', '2026-09-30 16:58:05', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `team8_contract_approvals`
--

CREATE TABLE `team8_contract_approvals` (
  `id` int(11) NOT NULL,
  `contract_id` int(11) NOT NULL,
  `reviewer_id` int(11) DEFAULT NULL,
  `approver_id` int(11) DEFAULT NULL,
  `action` varchar(30) NOT NULL,
  `comment` varchar(1000) DEFAULT NULL,
  `acted_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_contract_approvals`
--

INSERT INTO `team8_contract_approvals` (`id`, `contract_id`, `reviewer_id`, `approver_id`, `action`, `comment`, `acted_at`) VALUES
(1, 1, 4, 1, 'approved', 'Contract terms reviewed and approved.', '2026-09-30 16:58:05'),
(2, 2, 4, 1, 'approved', 'Service agreement approved.', '2026-09-30 16:58:05'),
(3, 3, 4, NULL, 'reviewed', 'Pending final approval from admin.', '2026-09-30 16:58:05'),
(4, 4, 8, 1, 'approved', 'Lease agreement approved.', '2026-09-30 16:58:05'),
(5, 5, 4, 1, 'approved', 'Maintenance contract approved.', '2026-09-30 16:58:05'),
(6, 6, 8, 1, 'approved', 'Marketing services approved.', '2026-09-30 16:58:05'),
(7, 7, 4, 1, 'approved', 'Supply contract approved.', '2026-09-30 16:58:05'),
(8, 8, 4, 1, 'approved', 'Trading agreement approved.', '2026-09-30 16:58:05'),
(9, 9, 4, 1, 'approved', 'Logistics contract approved.', '2026-09-30 16:58:05'),
(10, 10, 8, 1, 'approved', 'Manufacturing agreement approved.', '2026-09-30 16:58:05'),
(11, 11, 4, 1, 'approved', 'Consultancy agreement approved.', '2026-09-30 16:58:05'),
(12, 12, 8, 1, 'approved', 'Legal consultancy approved.', '2026-09-30 16:58:05'),
(13, 13, 4, NULL, 'pending', 'Awaiting review.', '2026-09-30 16:58:05'),
(14, 14, 4, 1, 'approved', 'Supply agreement approved.', '2026-09-30 16:58:05'),
(15, 15, 4, 1, 'approved', 'Cleaning services approved.', '2026-09-30 16:58:05'),
(16, 1, 4, 1, 'approved', 'Contract terms reviewed and approved.', '2026-09-30 16:58:33'),
(17, 2, 4, 1, 'approved', 'Service agreement approved.', '2026-09-30 16:58:33'),
(18, 3, 4, NULL, 'reviewed', 'Pending final approval from admin.', '2026-09-30 16:58:33'),
(19, 4, 8, 1, 'approved', 'Lease agreement approved.', '2026-09-30 16:58:33'),
(20, 5, 4, 1, 'approved', 'Maintenance contract approved.', '2026-09-30 16:58:33'),
(21, 6, 8, 1, 'approved', 'Marketing services approved.', '2026-09-30 16:58:33'),
(22, 7, 4, 1, 'approved', 'Supply contract approved.', '2026-09-30 16:58:33'),
(23, 8, 4, 1, 'approved', 'Trading agreement approved.', '2026-09-30 16:58:33'),
(24, 9, 4, 1, 'approved', 'Logistics contract approved.', '2026-09-30 16:58:33'),
(25, 10, 8, 1, 'approved', 'Manufacturing agreement approved.', '2026-09-30 16:58:33'),
(26, 11, 4, 1, 'approved', 'Consultancy agreement approved.', '2026-09-30 16:58:33'),
(27, 12, 8, 1, 'approved', 'Legal consultancy approved.', '2026-09-30 16:58:33'),
(28, 13, 4, NULL, 'pending', 'Awaiting review.', '2026-09-30 16:58:33'),
(29, 14, 4, 1, 'approved', 'Supply agreement approved.', '2026-09-30 16:58:33'),
(30, 15, 4, 1, 'approved', 'Cleaning services approved.', '2026-09-30 16:58:33');

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
-- Table structure for table `team8_contract_history`
--

CREATE TABLE `team8_contract_history` (
  `id` int(11) NOT NULL,
  `contract_id` int(11) NOT NULL,
  `version_no` int(11) NOT NULL,
  `data_json` longtext NOT NULL,
  `changed_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_contract_history`
--

INSERT INTO `team8_contract_history` (`id`, `contract_id`, `version_no`, `data_json`, `changed_by`, `created_at`) VALUES
(1, 1, 1, '{\"title\":\"Supplier Agreement - ABC Corp\",\"status\":\"draft\"}', 4, '2026-09-30 16:58:05'),
(2, 1, 2, '{\"title\":\"Supplier Agreement - ABC Corp\",\"status\":\"approval\"}', 4, '2026-09-30 16:58:05'),
(3, 1, 3, '{\"title\":\"Supplier Agreement - ABC Corp\",\"status\":\"active\"}', 1, '2026-09-30 16:58:05'),
(4, 2, 1, '{\"title\":\"Service Agreement - XYZ Ltd\",\"status\":\"draft\"}', 4, '2026-09-30 16:58:05'),
(5, 2, 2, '{\"title\":\"Service Agreement - XYZ Ltd\",\"status\":\"active\"}', 1, '2026-09-30 16:58:05'),
(6, 3, 1, '{\"title\":\"Vendor Agreement - DEF Inc\",\"status\":\"draft\"}', 10, '2026-09-30 16:58:05'),
(7, 3, 2, '{\"title\":\"Vendor Agreement - DEF Inc\",\"status\":\"approval\"}', 10, '2026-09-30 16:58:05'),
(8, 4, 1, '{\"title\":\"Lease Agreement - Main Office\",\"status\":\"draft\"}', 8, '2026-09-30 16:58:05'),
(9, 4, 2, '{\"title\":\"Lease Agreement - Main Office\",\"status\":\"active\"}', 1, '2026-09-30 16:58:05');

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

--
-- Dumping data for table `team8_contract_parties`
--

INSERT INTO `team8_contract_parties` (`id`, `contract_id`, `party_id`, `role_in_contract`, `created_at`) VALUES
(1, 1, 1, 'Supplier', '2026-09-30 16:58:05'),
(2, 2, 2, 'Service Provider', '2026-09-30 16:58:05'),
(3, 3, 3, 'Vendor', '2026-09-30 16:58:05'),
(4, 4, 4, 'Lessor', '2026-09-30 16:58:05'),
(5, 5, 5, 'Maintenance Provider', '2026-09-30 16:58:05'),
(6, 6, 6, 'Marketing Agency', '2026-09-30 16:58:05'),
(7, 7, 7, 'Supplier', '2026-09-30 16:58:05'),
(8, 8, 8, 'Trading Partner', '2026-09-30 16:58:05'),
(9, 9, 9, 'Logistics Provider', '2026-09-30 16:58:05'),
(10, 10, 10, 'Manufacturer', '2026-09-30 16:58:05'),
(11, 11, 11, 'Consultant', '2026-09-30 16:58:05'),
(12, 12, 12, 'Consultant', '2026-09-30 16:58:05'),
(13, 13, 13, 'Consultant', '2026-09-30 16:58:05'),
(14, 14, 14, 'Supplier', '2026-09-30 16:58:05'),
(15, 15, 15, 'Service Provider', '2026-09-30 16:58:05');

-- --------------------------------------------------------

--
-- Table structure for table `team8_documents`
--

CREATE TABLE `team8_documents` (
  `id` int(11) NOT NULL,
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
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_documents`
--

INSERT INTO `team8_documents` (`id`, `category_id`, `document_type`, `department_id`, `owner_id`, `uploaded_by`, `title`, `file_path`, `current_version`, `status`, `review_reason`, `expiration_date`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 1, 'Memo', 1, 1, 1, 'Company Policy Manual 2026', '/uploads/docs/policy_manual_2026.pdf', 1, 'approved', NULL, NULL, '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(2, 2, 'Contract', 5, 4, 4, 'Supplier Agreement - ABC Corp', '/uploads/docs/supplier_agreement_abc.pdf', 1, 'approved', NULL, '2027-12-31', '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(3, 3, 'Compliance', 1, 25, 25, 'Annual Safety Inspection Report', '/uploads/docs/safety_inspection_2026.pdf', 1, 'approved', NULL, '2027-06-30', '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(4, 4, 'Financial', 2, 16, 16, 'Q1 2026 Financial Report', '/uploads/docs/q1_2026_financial.pdf', 1, 'approved', NULL, NULL, '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(5, 5, 'Inventory', 8, 11, 11, 'Office Supplies Inventory Q1', '/uploads/docs/inventory_q1_2026.xlsx', 1, 'approved', NULL, NULL, '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(6, 6, 'Facilities', 8, 21, 21, 'Building Maintenance Schedule', '/uploads/docs/maintenance_schedule_2026.pdf', 1, 'approved', NULL, '2027-01-31', '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(7, 7, 'HR', 3, 15, 15, 'Employee Handbook 2026', '/uploads/docs/employee_handbook_2026.pdf', 1, 'approved', NULL, NULL, '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(8, 8, 'Others', 4, 7, 7, 'IT Security Guidelines', '/uploads/docs/it_security_guidelines.pdf', 1, 'pending', NULL, NULL, '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(9, 9, 'Legal', 5, 8, 8, 'NDA Template - Standard', '/uploads/docs/nda_template.pdf', 1, 'approved', NULL, NULL, '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(10, 2, 'Contract', 5, 4, 4, 'Service Agreement - XYZ Ltd', '/uploads/docs/service_agreement_xyz.pdf', 1, 'approved', NULL, '2028-03-15', '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(11, 1, 'Memo', 1, 25, 25, 'Updated Remote Work Policy', '/uploads/docs/remote_work_policy_2026.pdf', 1, 'approved', NULL, NULL, '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(12, 3, 'Compliance', 1, 25, 25, 'Fire Safety Certificate 2026', '/uploads/docs/fire_safety_2026.pdf', 1, 'approved', NULL, '2027-02-28', '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(13, 4, 'Financial', 2, 26, 26, 'Q2 2026 Budget Proposal', '/uploads/docs/q2_2026_budget.pdf', 1, 'pending', NULL, NULL, '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(14, 7, 'HR', 3, 27, 27, 'Recruitment Plan 2026', '/uploads/docs/recruitment_plan_2026.pdf', 1, 'approved', NULL, NULL, '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(15, 9, 'Legal', 5, 29, 29, 'Intellectual Property Policy', '/uploads/docs/ip_policy.pdf', 1, 'approved', NULL, NULL, '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(16, 2, 'Contract', 7, 10, 10, 'Vendor Agreement - DEF Inc', '/uploads/docs/vendor_agreement_def.pdf', 1, 'approval', NULL, '2028-06-30', '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(17, 1, 'Memo', 6, 9, 9, 'Operational Efficiency Report', '/uploads/docs/ops_efficiency_2026.pdf', 1, 'approved', NULL, NULL, '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(18, 6, 'Facilities', 8, 21, 21, 'HVAC Maintenance Report', '/uploads/docs/hvac_maintenance_2026.pdf', 1, 'approved', NULL, '2027-04-30', '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(19, 3, 'Compliance', 9, 22, 22, 'Security Audit Report Q1', '/uploads/docs/security_audit_q1_2026.pdf', 1, 'approved', NULL, NULL, '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(20, 4, 'Financial', 2, 16, 16, 'Annual Tax Filing 2025', '/uploads/docs/tax_filing_2025.pdf', 1, 'approved', NULL, NULL, '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(21, 7, 'HR', 3, 15, 15, 'Training Needs Assessment', '/uploads/docs/training_needs_2026.pdf', 1, 'approved', NULL, NULL, '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(22, 8, 'Others', 10, 13, 13, 'Marketing Strategy 2026', '/uploads/docs/marketing_strategy_2026.pdf', 1, 'approved', NULL, NULL, '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(23, 5, 'Inventory', 11, 24, 24, 'Customer Service Equipment List', '/uploads/docs/cs_equipment_list.pdf', 1, 'approved', NULL, NULL, '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(24, 2, 'Contract', 5, 8, 8, 'Lease Agreement - Main Office', '/uploads/docs/lease_agreement_main_office.pdf', 1, 'approved', NULL, '2030-12-31', '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(25, 9, 'Legal', 5, 18, 18, 'Compliance Checklist 2026', '/uploads/docs/compliance_checklist_2026.pdf', 1, 'approved', NULL, NULL, '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(26, 1, 'Memo', 4, 28, 28, 'IT Disaster Recovery Plan', '/uploads/docs/disaster_recovery_plan.pdf', 1, 'approved', NULL, NULL, '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(27, 3, 'Compliance', 1, 25, 25, 'Business Permit 2026', '/uploads/docs/business_permit_2026.pdf', 1, 'approved', NULL, '2026-12-31', '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(28, 7, 'HR', 3, 27, 27, 'Performance Review Guidelines', '/uploads/docs/performance_review_guidelines.pdf', 1, 'approved', NULL, NULL, '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(29, 6, 'Facilities', 8, 11, 11, 'Parking Allocation Policy', '/uploads/docs/parking_policy.pdf', 1, 'approved', NULL, NULL, '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL),
(30, 8, 'Others', 1, 25, 25, 'Company Directory 2026', '/uploads/docs/company_directory_2026.pdf', 1, 'approved', NULL, NULL, '2026-09-30 16:57:01', '2026-09-30 16:57:01', NULL);

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
(1, 'Administrative', '2026-09-30 16:50:45'),
(2, 'Contracts', '2026-09-30 16:50:45'),
(3, 'Compliance', '2026-09-30 16:50:45'),
(4, 'Finance', '2026-09-30 16:50:45'),
(5, 'Inventory', '2026-09-30 16:50:45'),
(6, 'Facilities', '2026-09-30 16:50:45'),
(7, 'Human Resources', '2026-09-30 16:50:45'),
(8, 'Others', '2026-09-30 16:50:45'),
(9, 'Legal', '2026-09-30 16:50:45');

-- --------------------------------------------------------

--
-- Table structure for table `team8_document_number_sequences`
--

CREATE TABLE `team8_document_number_sequences` (
  `prefix` varchar(30) NOT NULL,
  `sequence_year` smallint(5) UNSIGNED NOT NULL,
  `last_number` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
) ;

--
-- Dumping data for table `team8_document_versions`
--

INSERT INTO `team8_document_versions` (`id`, `document_id`, `version_no`, `file_path`, `file_size`, `checksum`, `uploaded_at`) VALUES
(1, 1, 1, '/uploads/docs/policy_manual_2026.pdf', 1048576, 'chk_doc01_v1', '2026-09-30 16:57:01'),
(2, 2, 1, '/uploads/docs/supplier_agreement_abc.pdf', 524288, 'chk_doc02_v1', '2026-09-30 16:57:01'),
(3, 3, 1, '/uploads/docs/safety_inspection_2026.pdf', 786432, 'chk_doc03_v1', '2026-09-30 16:57:01'),
(4, 4, 1, '/uploads/docs/q1_2026_financial.pdf', 655360, 'chk_doc04_v1', '2026-09-30 16:57:01'),
(5, 5, 1, '/uploads/docs/inventory_q1_2026.xlsx', 131072, 'chk_doc05_v1', '2026-09-30 16:57:01'),
(6, 6, 1, '/uploads/docs/maintenance_schedule_2026.pdf', 262144, 'chk_doc06_v1', '2026-09-30 16:57:01'),
(7, 7, 1, '/uploads/docs/employee_handbook_2026.pdf', 917504, 'chk_doc07_v1', '2026-09-30 16:57:01'),
(8, 8, 1, '/uploads/docs/it_security_guidelines.pdf', 393216, 'chk_doc08_v1', '2026-09-30 16:57:01'),
(9, 9, 1, '/uploads/docs/nda_template.pdf', 196608, 'chk_doc09_v1', '2026-09-30 16:57:01'),
(10, 10, 1, '/uploads/docs/service_agreement_xyz.pdf', 458752, 'chk_doc10_v1', '2026-09-30 16:57:01'),
(11, 11, 1, '/uploads/docs/remote_work_policy_2026.pdf', 327680, 'chk_doc11_v1', '2026-09-30 16:57:01'),
(12, 12, 1, '/uploads/docs/fire_safety_2026.pdf', 294912, 'chk_doc12_v1', '2026-09-30 16:57:01'),
(13, 13, 1, '/uploads/docs/q2_2026_budget.pdf', 360448, 'chk_doc13_v1', '2026-09-30 16:57:01'),
(14, 14, 1, '/uploads/docs/recruitment_plan_2026.pdf', 229376, 'chk_doc14_v1', '2026-09-30 16:57:01'),
(15, 15, 1, '/uploads/docs/ip_policy.pdf', 278528, 'chk_doc15_v1', '2026-09-30 16:57:01'),
(16, 16, 1, '/uploads/docs/vendor_agreement_def.pdf', 491520, 'chk_doc16_v1', '2026-09-30 16:57:01'),
(17, 17, 1, '/uploads/docs/ops_efficiency_2026.pdf', 344064, 'chk_doc17_v1', '2026-09-30 16:57:01'),
(18, 18, 1, '/uploads/docs/hvac_maintenance_2026.pdf', 212992, 'chk_doc18_v1', '2026-09-30 16:57:01'),
(19, 19, 1, '/uploads/docs/security_audit_q1_2026.pdf', 409600, 'chk_doc19_v1', '2026-09-30 16:57:01'),
(20, 20, 1, '/uploads/docs/tax_filing_2025.pdf', 540672, 'chk_doc20_v1', '2026-09-30 16:57:01'),
(21, 21, 1, '/uploads/docs/training_needs_2026.pdf', 245760, 'chk_doc21_v1', '2026-09-30 16:57:01'),
(22, 22, 1, '/uploads/docs/marketing_strategy_2026.pdf', 573440, 'chk_doc22_v1', '2026-09-30 16:57:01'),
(23, 23, 1, '/uploads/docs/cs_equipment_list.pdf', 163840, 'chk_doc23_v1', '2026-09-30 16:57:01'),
(24, 24, 1, '/uploads/docs/lease_agreement_main_office.pdf', 622592, 'chk_doc24_v1', '2026-09-30 16:57:01'),
(25, 25, 1, '/uploads/docs/compliance_checklist_2026.pdf', 180224, 'chk_doc25_v1', '2026-09-30 16:57:01'),
(26, 26, 1, '/uploads/docs/disaster_recovery_plan.pdf', 475136, 'chk_doc26_v1', '2026-09-30 16:57:01'),
(27, 27, 1, '/uploads/docs/business_permit_2026.pdf', 311296, 'chk_doc27_v1', '2026-09-30 16:57:01'),
(28, 28, 1, '/uploads/docs/performance_review_guidelines.pdf', 204800, 'chk_doc28_v1', '2026-09-30 16:57:01'),
(29, 29, 1, '/uploads/docs/parking_policy.pdf', 139264, 'chk_doc29_v1', '2026-09-30 16:57:01'),
(30, 30, 1, '/uploads/docs/company_directory_2026.pdf', 155648, 'chk_doc30_v1', '2026-09-30 16:57:01'),
(31, 1, 2, '/uploads/docs/policy_manual_2026_v2.pdf', 1100000, 'chk_doc01_v2', '2026-09-30 16:57:01'),
(32, 1, 3, '/uploads/docs/policy_manual_2026_v3.pdf', 1150000, 'chk_doc01_v3', '2026-09-30 16:57:01');

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
) ;

--
-- Dumping data for table `team8_equipment`
--

INSERT INTO `team8_equipment` (`id`, `home_facility_id`, `name`, `quantity`, `created_at`, `updated_at`) VALUES
(1, 1, 'Projector', 2, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(2, 1, 'Whiteboard', 1, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(3, 2, 'Laptop', 30, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(4, 2, 'Smartboard', 1, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(5, 3, 'Video Conferencing System', 1, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(6, 4, 'Sound System', 2, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(7, 4, 'Stage Lights', 4, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(8, 5, 'Desktop Computer', 25, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(9, 6, 'TV', 1, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(10, 7, 'TV', 1, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(11, 8, 'Microphone', 6, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(12, 8, 'Speaker', 4, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(13, 9, 'Tent', 3, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(14, 9, 'Folding Chairs', 50, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(15, 9, 'Folding Tables', 10, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(16, 11, 'Sofa', 4, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(17, 11, 'TV', 1, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(18, 12, 'Server Rack', 10, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(19, 13, 'Dining Table', 20, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(20, 13, 'Dining Chair', 80, '2026-09-30 17:01:50', '2026-09-30 17:01:50');

-- --------------------------------------------------------

--
-- Table structure for table `team8_explanations`
--

CREATE TABLE `team8_explanations` (
  `id` int(11) NOT NULL,
  `nte_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `explanation_text` text NOT NULL,
  `attachment_path` varchar(500) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `admin_remarks` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_explanations`
--

INSERT INTO `team8_explanations` (`id`, `nte_id`, `employee_id`, `explanation_text`, `attachment_path`, `status`, `admin_remarks`, `reviewed_by`, `reviewed_at`, `submitted_at`, `updated_at`) VALUES
(1, 1, 5, 'I apologize for being late. Traffic was heavy.', NULL, 'approved', 'Noted. Please be mindful of your time.', 15, '2026-01-18 10:00:00', '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(2, 2, 7, 'I did not realize I was violating policy. I will not do it again.', NULL, 'approved', 'Warning issued.', 15, '2026-02-13 10:00:00', '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(3, 3, 9, 'I was provoked by the other employee.', NULL, 'approved', 'Both parties counseled.', 15, '2026-03-20 10:00:00', '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(4, 4, 13, 'I apologize for being late. Personal emergency.', NULL, 'approved', 'Noted.', 15, '2026-04-16 10:00:00', '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(5, 5, 19, 'I forgot to wear my safety gear. I will be more careful.', NULL, 'approved', 'Warning issued.', 15, '2026-05-28 10:00:00', '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(6, 6, 5, 'I apologize for being late again. I will improve.', NULL, 'approved', 'Final warning.', 27, '2026-06-23 10:00:00', '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(7, 7, 7, 'I understand the violation. I will follow IT policy.', NULL, 'approved', 'Warning issued.', 27, '2026-07-30 10:00:00', '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(8, 8, 9, 'I apologize for my behavior. It will not happen again.', NULL, 'approved', 'Counseling provided.', 27, '2026-08-26 10:00:00', '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(9, 9, 13, 'I apologize for being late. Traffic was bad.', NULL, 'pending', NULL, NULL, NULL, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(10, 10, 19, 'I did not realize I was violating protocol. I will review the safety manual.', NULL, 'pending', NULL, NULL, NULL, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(11, 11, 5, 'I was not aware of the updated remote work policy.', NULL, 'approved', 'Additional training required.', 15, '2026-02-28 10:00:00', '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(12, 12, 7, 'I did not know the information was confidential.', NULL, 'approved', 'Warning issued.', 15, '2026-04-02 10:00:00', '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(13, 13, 9, 'I apologize for being late.', NULL, 'approved', 'Noted.', 15, '2026-05-08 10:00:00', '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(14, 14, 13, 'I was not aware of the dress code update.', NULL, 'approved', 'Noted.', 15, '2026-05-23 10:00:00', '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(15, 15, 19, 'I will review the emergency procedures.', NULL, 'approved', 'Warning issued.', 15, '2026-07-06 10:00:00', '2026-09-30 17:02:11', '2026-09-30 17:02:11');

-- --------------------------------------------------------

--
-- Table structure for table `team8_facilities`
--

CREATE TABLE `team8_facilities` (
  `id` int(11) NOT NULL,
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
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `team8_facilities`
--

INSERT INTO `team8_facilities` (`id`, `name`, `location`, `facility_type`, `capacity`, `description`, `equipment_notes`, `maintenance_status`, `next_maintenance_date`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Main Conference Room', 'Building A, 2nd Floor', 'Conference Room', 50, 'Large conference room with projector and video conferencing.', 'Projector, whiteboard, video conferencing', 'operational', '2026-11-15', 'active', '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(2, 'Training Room 1', 'Building B, 1st Floor', 'Training Room', 30, 'Training room with computers and smartboard.', 'Smartboard, 30 computers', 'operational', '2026-11-20', 'active', '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(3, 'Board Room', 'Building A, 3rd Floor', 'Board Room', 15, 'Executive board room for meetings.', 'Video conferencing, large TV', 'operational', '2026-12-01', 'active', '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(4, 'Multi-Purpose Hall', 'Building C, Ground Floor', 'Event Hall', 200, 'Large hall for events and gatherings.', 'Sound system, stage, lights', 'operational', '2026-12-10', 'active', '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(5, 'Computer Lab', 'Building B, 2nd Floor', 'Computer Lab', 25, 'Computer laboratory for training.', '25 computers, projector', 'maintenance', '2026-10-30', 'active', '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(6, 'Meeting Room A', 'Building A, 1st Floor', 'Meeting Room', 8, 'Small meeting room.', 'TV, whiteboard', 'operational', '2026-11-25', 'active', '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(7, 'Meeting Room B', 'Building A, 1st Floor', 'Meeting Room', 8, 'Small meeting room.', 'TV, whiteboard', 'operational', '2026-11-25', 'active', '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(8, 'Auditorium', 'Building D, Ground Floor', 'Auditorium', 300, 'Large auditorium for company events.', 'Stage, sound system, lighting', 'operational', '2026-12-15', 'active', '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(9, 'Rooftop Garden', 'Building A, Rooftop', 'Outdoor Space', 100, 'Outdoor rooftop garden for events.', 'Tent, chairs, tables', 'operational', '2026-11-30', 'active', '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(10, 'Storage Room', 'Building C, Basement', 'Storage', 5, 'Storage room for equipment.', 'Shelves, cabinets', 'operational', '2026-12-20', 'active', '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(11, 'Executive Lounge', 'Building A, 4th Floor', 'Lounge', 20, 'Executive lounge for VIP guests.', 'Sofa, TV, refreshments', 'operational', '2026-12-05', 'active', '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(12, 'Server Room', 'Building B, Basement', 'Server Room', 3, 'Server room for IT infrastructure.', 'Server racks, cooling system', 'operational', '2026-11-10', 'active', '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(13, 'Cafeteria', 'Building C, 1st Floor', 'Cafeteria', 150, 'Company cafeteria.', 'Tables, chairs, kitchen equipment', 'operational', '2026-12-25', 'active', '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(14, 'Reception Area', 'Building A, Ground Floor', 'Reception', 10, 'Main reception area.', 'Sofa, TV, reception desk', 'operational', '2026-12-18', 'active', '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(15, 'Parking Lot', 'Building A, Ground Floor', 'Parking', 100, 'Company parking lot.', 'Parking barriers, CCTV', 'operational', '2026-12-30', 'active', '2026-09-30 17:01:50', '2026-09-30 17:01:50');

-- --------------------------------------------------------

--
-- Table structure for table `team8_facility_locations`
--

CREATE TABLE `team8_facility_locations` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_facility_locations`
--

INSERT INTO `team8_facility_locations` (`id`, `name`, `created_at`) VALUES
(1, 'Building A', '2026-09-30 17:01:50'),
(2, 'Building B', '2026-09-30 17:01:50'),
(3, 'Building C', '2026-09-30 17:01:50'),
(4, 'Building D', '2026-09-30 17:01:50'),
(5, 'Rooftop', '2026-09-30 17:01:50'),
(6, 'Basement', '2026-09-30 17:01:50');

-- --------------------------------------------------------

--
-- Table structure for table `team8_facility_maintenance_history`
--

CREATE TABLE `team8_facility_maintenance_history` (
  `id` int(11) NOT NULL,
  `facility_id` int(11) NOT NULL,
  `performed_by` int(11) NOT NULL,
  `maintenance_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_facility_maintenance_history`
--

INSERT INTO `team8_facility_maintenance_history` (`id`, `facility_id`, `performed_by`, `maintenance_date`, `notes`, `created_at`) VALUES
(1, 1, 2, '2026-01-15', 'Projector bulb replaced.', '2026-09-30 17:01:50'),
(2, 2, 11, '2026-02-01', 'Computers updated and cleaned.', '2026-09-30 17:01:50'),
(3, 3, 2, '2026-02-15', 'Video conferencing system tested.', '2026-09-30 17:01:50'),
(4, 4, 11, '2026-03-01', 'Sound system checked.', '2026-09-30 17:01:50'),
(5, 5, 2, '2026-03-15', 'Computers undergoing maintenance.', '2026-09-30 17:01:50'),
(6, 6, 11, '2026-04-01', 'TV and whiteboard cleaned.', '2026-09-30 17:01:50'),
(7, 7, 2, '2026-04-15', 'TV and whiteboard cleaned.', '2026-09-30 17:01:50'),
(8, 8, 11, '2026-05-01', 'Stage and lighting checked.', '2026-09-30 17:01:50'),
(9, 9, 2, '2026-05-15', 'Tent and chairs inspected.', '2026-09-30 17:01:50'),
(10, 10, 11, '2026-06-01', 'Storage room organized.', '2026-09-30 17:01:50'),
(11, 11, 2, '2026-06-15', 'Lounge furniture cleaned.', '2026-09-30 17:01:50'),
(12, 12, 11, '2026-07-01', 'Server room cooling checked.', '2026-09-30 17:01:50'),
(13, 13, 2, '2026-07-15', 'Cafeteria equipment inspected.', '2026-09-30 17:01:50'),
(14, 14, 11, '2026-08-01', 'Reception area cleaned.', '2026-09-30 17:01:50'),
(15, 15, 2, '2026-08-15', 'Parking lot barriers checked.', '2026-09-30 17:01:50');

-- --------------------------------------------------------

--
-- Table structure for table `team8_hr_document_sequences`
--

CREATE TABLE `team8_hr_document_sequences` (
  `prefix` varchar(20) NOT NULL,
  `document_year` smallint(6) NOT NULL,
  `next_number` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `team8_hr_document_versions`
--

CREATE TABLE `team8_hr_document_versions` (
  `id` int(11) NOT NULL,
  `doc_type` varchar(30) NOT NULL,
  `doc_id` int(11) NOT NULL,
  `version_no` int(11) NOT NULL,
  `data_json` text NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `team8_incident_reports`
--

CREATE TABLE `team8_incident_reports` (
  `id` int(11) NOT NULL,
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
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_incident_reports`
--

INSERT INTO `team8_incident_reports` (`id`, `document_number`, `employee_id`, `prepared_by`, `department_id`, `status`, `rejection_reason`, `incident_date`, `incident_time`, `incident_location`, `incident_type`, `description`, `witness`, `attachment_path`, `current_version`, `created_at`, `updated_at`) VALUES
(1, 'IR-2026-001', 5, 15, 3, 'approved', NULL, '2026-01-10', '09:30:00', 'Building A, 2nd Floor', 'Late Arrival', 'Employee arrived 30 minutes late.', 'HR Hannah', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(2, 'IR-2026-002', 7, 15, 4, 'approved', NULL, '2026-02-05', '14:00:00', 'Building B, 1st Floor', 'Policy Violation', 'Employee used company resources for personal use.', 'IT Irene', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(3, 'IR-2026-003', 9, 15, 6, 'approved', NULL, '2026-03-12', '11:15:00', 'Building C, Ground Floor', 'Misconduct', 'Employee involved in verbal altercation.', 'Ops Oscar', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(4, 'IR-2026-004', 13, 15, 10, 'approved', NULL, '2026-04-08', '16:30:00', 'Building A, 1st Floor', 'Late Arrival', 'Employee arrived 45 minutes late.', 'Marketing Max', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(5, 'IR-2026-005', 19, 15, 6, 'approved', NULL, '2026-05-20', '10:00:00', 'Building D, Ground Floor', 'Safety Violation', 'Employee not wearing required safety gear.', 'Security Sam', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(6, 'IR-2026-006', 5, 27, 3, 'approved', NULL, '2026-06-15', '08:45:00', 'Building A, Ground Floor', 'Late Arrival', 'Employee arrived 15 minutes late.', 'Frontdesk Fred', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(7, 'IR-2026-007', 7, 27, 4, 'approved', NULL, '2026-07-22', '13:30:00', 'Building B, 2nd Floor', 'Policy Violation', 'Employee violated IT security policy.', 'IT Ivan', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(8, 'IR-2026-008', 9, 27, 6, 'approved', NULL, '2026-08-18', '15:45:00', 'Building C, 1st Floor', 'Misconduct', 'Employee was disrespectful to a colleague.', 'Ops Olivia', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(9, 'IR-2026-009', 13, 27, 10, 'pending', NULL, '2026-09-05', '10:30:00', 'Building A, 2nd Floor', 'Late Arrival', 'Employee arrived 20 minutes late.', 'Marketing Mia', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(10, 'IR-2026-010', 19, 27, 6, 'pending', NULL, '2026-09-12', '14:15:00', 'Building D, Ground Floor', 'Safety Violation', 'Employee violated safety protocol.', 'Security Sarah', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(11, 'IR-2026-011', 5, 15, 3, 'approved', NULL, '2026-02-20', '11:00:00', 'Building A, 1st Floor', 'Policy Violation', 'Employee did not follow remote work policy.', 'HR Henry', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(12, 'IR-2026-012', 7, 15, 4, 'approved', NULL, '2026-03-25', '16:00:00', 'Building B, 1st Floor', 'Misconduct', 'Employee shared confidential information.', 'IT Ian', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(13, 'IR-2026-013', 9, 15, 6, 'approved', NULL, '2026-04-30', '09:45:00', 'Building C, Ground Floor', 'Late Arrival', 'Employee arrived 25 minutes late.', 'Ops Owen', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(14, 'IR-2026-014', 13, 15, 10, 'approved', NULL, '2026-05-15', '13:15:00', 'Building A, 1st Floor', 'Policy Violation', 'Employee violated dress code policy.', 'Marketing Max', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(15, 'IR-2026-015', 19, 15, 6, 'approved', NULL, '2026-06-28', '10:45:00', 'Building D, Ground Floor', 'Safety Violation', 'Employee did not follow emergency procedures.', 'Security Sam', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11');

-- --------------------------------------------------------

--
-- Table structure for table `team8_legal_cases`
--

CREATE TABLE `team8_legal_cases` (
  `id` int(11) NOT NULL,
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
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_legal_cases`
--

INSERT INTO `team8_legal_cases` (`id`, `assigned_to`, `contract_id`, `title`, `subject`, `department_id`, `status`, `filed_date`, `deadline`, `closed_at`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 4, 1, 'Contract Dispute - ABC Corp', 'Disputed delivery terms', 5, 'open', '2026-01-15', '2026-12-31', NULL, '2026-09-30 17:01:41', '2026-09-30 17:01:41', NULL),
(2, 8, 2, 'Service Level Agreement Review', 'SLA compliance review', 5, 'open', '2026-02-01', '2026-11-30', NULL, '2026-09-30 17:01:41', '2026-09-30 17:01:41', NULL),
(3, 4, 4, 'Lease Agreement Review', 'Lease terms review', 5, 'closed', '2026-01-10', '2026-06-30', '2026-06-15 10:00:00', '2026-09-30 17:01:41', '2026-09-30 17:01:41', NULL),
(4, 18, 5, 'Maintenance Contract Dispute', 'Disputed maintenance costs', 5, 'open', '2026-03-01', '2026-12-15', NULL, '2026-09-30 17:01:41', '2026-09-30 17:01:41', NULL),
(5, 8, NULL, 'Trademark Registration', 'Company trademark filing', 5, 'open', '2026-04-01', '2027-03-31', NULL, '2026-09-30 17:01:41', '2026-09-30 17:01:41', NULL),
(6, 4, NULL, 'Employee Grievance - Case 001', 'Workplace grievance', 3, 'open', '2026-05-01', '2026-10-31', NULL, '2026-09-30 17:01:41', '2026-09-30 17:01:41', NULL),
(7, 29, NULL, 'Data Privacy Compliance', 'GDPR/DPA compliance review', 4, 'open', '2026-06-01', '2026-12-31', NULL, '2026-09-30 17:01:41', '2026-09-30 17:01:41', NULL),
(8, 8, 9, 'Logistics Contract Dispute', 'Delivery delays dispute', 6, 'open', '2026-07-01', '2027-01-31', NULL, '2026-09-30 17:01:41', '2026-09-30 17:01:41', NULL),
(9, 18, NULL, 'Intellectual Property Filing', 'Patent application', 5, 'open', '2026-08-01', '2027-07-31', NULL, '2026-09-30 17:01:41', '2026-09-30 17:01:41', NULL),
(10, 4, 11, 'Consultancy Agreement Review', 'Review of consultancy terms', 5, 'closed', '2026-06-01', '2026-09-30', '2026-09-20 14:00:00', '2026-09-30 17:01:41', '2026-09-30 17:01:41', NULL),
(11, 29, NULL, 'Vendor Contract Review - DEF', 'Review of vendor terms', 7, 'open', '2026-09-01', '2027-02-28', NULL, '2026-09-30 17:01:41', '2026-09-30 17:01:41', NULL),
(12, 8, 15, 'Cleaning Services Contract Review', 'Review of cleaning contract', 5, 'open', '2026-09-15', '2027-03-31', NULL, '2026-09-30 17:01:41', '2026-09-30 17:01:41', NULL),
(13, 4, NULL, 'Labor Law Compliance', 'Review of labor practices', 3, 'open', '2026-09-20', '2027-03-31', NULL, '2026-09-30 17:01:41', '2026-09-30 17:01:41', NULL),
(14, 18, 3, 'Vendor Agreement Dispute - DEF', 'Disputed equipment specs', 7, 'open', '2026-09-25', '2027-04-30', NULL, '2026-09-30 17:01:41', '2026-09-30 17:01:41', NULL),
(15, 29, NULL, 'Corporate Governance Review', 'Review of governance policies', 1, 'open', '2026-09-28', '2027-03-31', NULL, '2026-09-30 17:01:41', '2026-09-30 17:01:41', NULL);

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

--
-- Dumping data for table `team8_legal_documents`
--

INSERT INTO `team8_legal_documents` (`id`, `case_id`, `document_id`, `description`, `created_at`) VALUES
(1, 1, 2, 'Supplier agreement related to dispute.', '2026-09-30 17:01:41'),
(2, 2, 10, 'Service agreement under review.', '2026-09-30 17:01:41'),
(3, 3, 24, 'Lease agreement for review.', '2026-09-30 17:01:41'),
(4, 4, 6, 'Maintenance schedule referenced.', '2026-09-30 17:01:41'),
(5, 5, 15, 'IP policy referenced.', '2026-09-30 17:01:41'),
(6, 6, 7, 'Employee handbook referenced.', '2026-09-30 17:01:41'),
(7, 7, 8, 'IT security guidelines referenced.', '2026-09-30 17:01:41'),
(8, 8, 9, 'NDA template referenced.', '2026-09-30 17:01:41'),
(9, 9, 15, 'IP policy referenced.', '2026-09-30 17:01:41'),
(10, 10, 10, 'Consultancy agreement under review.', '2026-09-30 17:01:41'),
(11, 11, 16, 'Vendor agreement under review.', '2026-09-30 17:01:41'),
(12, 12, 30, 'Company directory referenced.', '2026-09-30 17:01:41'),
(13, 13, 7, 'Employee handbook referenced.', '2026-09-30 17:01:41'),
(14, 14, 16, 'Vendor agreement related to dispute.', '2026-09-30 17:01:41'),
(15, 15, 25, 'Compliance checklist referenced.', '2026-09-30 17:01:41');

-- --------------------------------------------------------

--
-- Table structure for table `team8_login_throttle`
--

CREATE TABLE `team8_login_throttle` (
  `identifier` varchar(191) NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `team8_memorandums`
--

CREATE TABLE `team8_memorandums` (
  `id` int(11) NOT NULL,
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
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_memorandums`
--

INSERT INTO `team8_memorandums` (`id`, `document_number`, `kind`, `title`, `recipients`, `content`, `remarks`, `prepared_by`, `status`, `rejection_reason`, `current_version`, `created_at`, `updated_at`) VALUES
(1, 'MEMO-2026-001', 'memorandum', 'Updated Health Protocols', 'All Employees', 'Please follow the updated health protocols effective immediately.', 'Effective immediately.', 25, 'approved', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(2, 'MEMO-2026-002', 'memorandum', 'Office Holiday Schedule', 'All Employees', 'The office will be closed on the following dates.', 'See attached schedule.', 25, 'approved', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(3, 'MEMO-2026-003', 'memorandum', 'New Attendance Policy', 'All Employees', 'A new attendance policy will take effect next month.', 'Please review the attached policy.', 15, 'approved', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(4, 'MEMO-2026-004', 'memorandum', 'IT Security Reminder', 'All Employees', 'Please ensure you follow IT security guidelines.', 'Mandatory compliance.', 7, 'approved', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(5, 'MEMO-2026-005', 'memorandum', 'Upcoming Training Sessions', 'All Employees', 'Training sessions will be held next week.', 'Attendance is mandatory.', 15, 'approved', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(6, 'MEMO-2026-006', 'memorandum', 'Office Renovation Notice', 'All Employees', 'The office will undergo renovation starting next month.', 'Plan accordingly.', 25, 'approved', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(7, 'MEMO-2026-007', 'memorandum', 'New Employee Onboarding', 'HR Department', 'New employees will be onboarded next week.', 'See attached list.', 15, 'approved', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(8, 'MEMO-2026-008', 'memorandum', 'Year-End Party', 'All Employees', 'The year-end party will be held on December 20.', 'Save the date.', 25, 'approved', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(9, 'MEMO-2026-009', 'memorandum', 'Updated Travel Policy', 'All Employees', 'The travel policy has been updated.', 'Effective immediately.', 25, 'approved', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(10, 'MEMO-2026-010', 'memorandum', 'Fire Drill Schedule', 'All Employees', 'A fire drill will be conducted next month.', 'Mandatory participation.', 22, 'approved', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(11, 'MEMO-2026-011', 'memorandum', 'New Procurement Process', 'Procurement Department', 'The procurement process has been updated.', 'Please review.', 10, 'approved', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(12, 'MEMO-2026-012', 'memorandum', 'Customer Service Guidelines', 'Customer Service', 'New customer service guidelines are now in effect.', 'Please comply.', 14, 'approved', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(13, 'MEMO-2026-013', 'memorandum', 'Legal Compliance Reminder', 'All Departments', 'Please ensure all legal documents are up to date.', 'Mandatory compliance.', 8, 'approved', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(14, 'MEMO-2026-014', 'memorandum', 'Marketing Campaign Launch', 'Marketing Department', 'The new marketing campaign will launch next month.', 'See attached plan.', 13, 'approved', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(15, 'MEMO-2026-015', 'memorandum', 'Operations Update', 'Operations Department', 'Operations procedures have been updated.', 'Please review.', 9, 'approved', NULL, 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11');

-- --------------------------------------------------------

--
-- Table structure for table `team8_memorandum_recipients`
--

CREATE TABLE `team8_memorandum_recipients` (
  `id` int(11) NOT NULL,
  `memorandum_id` int(11) NOT NULL,
  `recipient_type` varchar(30) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ;

--
-- Dumping data for table `team8_memorandum_recipients`
--

INSERT INTO `team8_memorandum_recipients` (`id`, `memorandum_id`, `recipient_type`, `department_id`, `created_at`) VALUES
(1, 1, 'all_departments', NULL, '2026-09-30 17:02:12'),
(2, 2, 'all_departments', NULL, '2026-09-30 17:02:12'),
(3, 3, 'all_departments', NULL, '2026-09-30 17:02:12'),
(4, 4, 'all_departments', NULL, '2026-09-30 17:02:12'),
(5, 5, 'all_departments', NULL, '2026-09-30 17:02:12'),
(6, 6, 'all_departments', NULL, '2026-09-30 17:02:12'),
(7, 7, 'department', 3, '2026-09-30 17:02:12'),
(8, 8, 'all_departments', NULL, '2026-09-30 17:02:12'),
(9, 9, 'all_departments', NULL, '2026-09-30 17:02:12'),
(10, 10, 'all_departments', NULL, '2026-09-30 17:02:12'),
(11, 11, 'department', 7, '2026-09-30 17:02:12'),
(12, 12, 'department', 11, '2026-09-30 17:02:12'),
(13, 13, 'all_departments', NULL, '2026-09-30 17:02:12'),
(14, 14, 'department', 10, '2026-09-30 17:02:12'),
(15, 15, 'department', 6, '2026-09-30 17:02:12');

-- --------------------------------------------------------

--
-- Table structure for table `team8_notice_to_explain`
--

CREATE TABLE `team8_notice_to_explain` (
  `id` int(11) NOT NULL,
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
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_notice_to_explain`
--

INSERT INTO `team8_notice_to_explain` (`id`, `document_number`, `incident_report_id`, `employee_id`, `prepared_by`, `status`, `rejection_reason`, `deadline`, `remarks`, `current_version`, `created_at`, `updated_at`) VALUES
(1, 'NTE-2026-001', 1, 5, 15, 'approved', NULL, '2026-01-17', 'Please explain your tardiness.', 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(2, 'NTE-2026-002', 2, 7, 15, 'approved', NULL, '2026-02-12', 'Explain the policy violation.', 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(3, 'NTE-2026-003', 3, 9, 15, 'approved', NULL, '2026-03-19', 'Explain your involvement in the altercation.', 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(4, 'NTE-2026-004', 4, 13, 15, 'approved', NULL, '2026-04-15', 'Explain your tardiness.', 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(5, 'NTE-2026-005', 5, 19, 15, 'approved', NULL, '2026-05-27', 'Explain the safety violation.', 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(6, 'NTE-2026-006', 6, 5, 27, 'approved', NULL, '2026-06-22', 'Explain your tardiness.', 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(7, 'NTE-2026-007', 7, 7, 27, 'approved', NULL, '2026-07-29', 'Explain the IT security violation.', 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(8, 'NTE-2026-008', 8, 9, 27, 'approved', NULL, '2026-08-25', 'Explain your behavior towards a colleague.', 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(9, 'NTE-2026-009', 9, 13, 27, 'pending', NULL, '2026-09-12', 'Explain your tardiness.', 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(10, 'NTE-2026-010', 10, 19, 27, 'pending', NULL, '2026-09-19', 'Explain the safety violation.', 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(11, 'NTE-2026-011', 11, 5, 15, 'approved', NULL, '2026-02-27', 'Explain the remote work policy violation.', 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(12, 'NTE-2026-012', 12, 7, 15, 'approved', NULL, '2026-04-01', 'Explain the confidentiality breach.', 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(13, 'NTE-2026-013', 13, 9, 15, 'approved', NULL, '2026-05-07', 'Explain your tardiness.', 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(14, 'NTE-2026-014', 14, 13, 15, 'approved', NULL, '2026-05-22', 'Explain the dress code violation.', 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11'),
(15, 'NTE-2026-015', 15, 19, 15, 'approved', NULL, '2026-07-05', 'Explain the safety procedure violation.', 1, '2026-09-30 17:02:11', '2026-09-30 17:02:11');

-- --------------------------------------------------------

--
-- Table structure for table `team8_parties`
--

CREATE TABLE `team8_parties` (
  `id` int(11) NOT NULL,
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
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_parties`
--

INSERT INTO `team8_parties` (`id`, `name`, `trade_name`, `registration_number`, `tin`, `type`, `contact_email`, `contact_phone`, `primary_contact`, `address`, `authorized_signatory_name`, `authorized_signatory_position`, `created_at`, `updated_at`) VALUES
(1, 'ABC Corporation', 'ABC Corp', 'REG-2020-001', 'TIN-001-001', 'organization', 'contact@abccorp.com', '+63-2-8123-4567', 'Alice Brown', '123 Business Ave, Makati', 'Alice Brown', 'CEO', '2026-09-30 16:58:05', '2026-09-30 16:58:05'),
(2, 'XYZ Limited', 'XYZ Ltd', 'REG-2019-002', 'TIN-002-002', 'organization', 'contact@xyzltd.com', '+63-2-8234-5678', 'Xavier Young', '456 Corporate Blvd, BGC', 'Xavier Young', 'Managing Director', '2026-09-30 16:58:05', '2026-09-30 16:58:05'),
(3, 'DEF Incorporated', 'DEF Inc', 'REG-2021-003', 'TIN-003-003', 'organization', 'contact@definc.com', '+63-2-8345-6789', 'Diana Evans', '789 Enterprise St, Ortigas', 'Diana Evans', 'President', '2026-09-30 16:58:05', '2026-09-30 16:58:05'),
(4, 'GHI Enterprises', 'GHI Ent', 'REG-2018-004', 'TIN-004-004', 'organization', 'contact@ghient.com', '+63-2-8456-7890', 'George Harris', '321 Commerce Rd, Pasig', 'George Harris', 'Owner', '2026-09-30 16:58:05', '2026-09-30 16:58:05'),
(5, 'JKL Solutions', 'JKL Sol', 'REG-2022-005', 'TIN-005-005', 'organization', 'contact@jklsol.com', '+63-2-8567-8901', 'Jane Kim', '654 Tech Park, Taguig', 'Jane Kim', 'CTO', '2026-09-30 16:58:05', '2026-09-30 16:58:05'),
(6, 'MNO Services', 'MNO Svc', 'REG-2020-006', 'TIN-006-006', 'organization', 'contact@mnosvc.com', '+63-2-8678-9012', 'Mark Nolan', '987 Service Lane, Mandaluyong', 'Mark Nolan', 'GM', '2026-09-30 16:58:05', '2026-09-30 16:58:05'),
(7, 'PQR Trading', 'PQR Trade', 'REG-2017-007', 'TIN-007-007', 'organization', 'contact@pqrtrade.com', '+63-2-8789-0123', 'Paula Quinn', '147 Trade Center, Manila', 'Paula Quinn', 'President', '2026-09-30 16:58:05', '2026-09-30 16:58:05'),
(8, 'STU Logistics', 'STU Log', 'REG-2019-008', 'TIN-008-008', 'organization', 'contact@stulog.com', '+63-2-8890-1234', 'Steve Turner', '258 Logistics Hub, Parañaque', 'Steve Turner', 'COO', '2026-09-30 16:58:05', '2026-09-30 16:58:05'),
(9, 'VWX Manufacturing', 'VWX Mfg', 'REG-2016-009', 'TIN-009-009', 'organization', 'contact@vwxmfg.com', '+63-2-8901-2345', 'Vera Wong', '369 Factory Rd, Cavite', 'Vera Wong', 'Plant Manager', '2026-09-30 16:58:05', '2026-09-30 16:58:05'),
(10, 'YZA Consultancy', 'YZA Cons', 'REG-2021-010', 'TIN-010-010', 'organization', 'contact@yzacons.com', '+63-2-9012-3456', 'Yves Adams', '741 Consultancy Plaza, Makati', 'Yves Adams', 'Principal', '2026-09-30 16:58:05', '2026-09-30 16:58:05'),
(11, 'John Smith', NULL, NULL, 'TIN-011-011', 'individual', 'john.smith@email.com', '+63-917-123-4567', 'John Smith', 'Unit 101, Residences, BGC', 'John Smith', 'N/A', '2026-09-30 16:58:05', '2026-09-30 16:58:05'),
(12, 'Maria Santos', NULL, NULL, 'TIN-012-012', 'individual', 'maria.santos@email.com', '+63-917-234-5678', 'Maria Santos', 'Unit 202, Residences, Makati', 'Maria Santos', 'N/A', '2026-09-30 16:58:05', '2026-09-30 16:58:05'),
(13, 'Robert Johnson', NULL, NULL, 'TIN-013-013', 'individual', 'robert.j@email.com', '+63-917-345-6789', 'Robert Johnson', 'Unit 303, Residences, Pasig', 'Robert Johnson', 'N/A', '2026-09-30 16:58:05', '2026-09-30 16:58:05'),
(14, 'Patricia Lee', NULL, NULL, 'TIN-014-014', 'individual', 'patricia.lee@email.com', '+63-917-456-7890', 'Patricia Lee', 'Unit 404, Residences, Taguig', 'Patricia Lee', 'N/A', '2026-09-30 16:58:05', '2026-09-30 16:58:05'),
(15, 'Michael Tan', NULL, NULL, 'TIN-015-015', 'individual', 'michael.tan@email.com', '+63-917-567-8901', 'Michael Tan', 'Unit 505, Residences, Mandaluyong', 'Michael Tan', 'N/A', '2026-09-30 16:58:05', '2026-09-30 16:58:05');

-- --------------------------------------------------------

--
-- Table structure for table `team8_records`
--

CREATE TABLE `team8_records` (
  `id` int(11) NOT NULL,
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
  `deleted_at` datetime DEFAULT NULL
) ;

--
-- Dumping data for table `team8_records`
--

INSERT INTO `team8_records` (`id`, `entity_type`, `entity_id`, `schedule_id`, `retention_basis`, `retention_years`, `retention_start_date`, `custodian_id`, `disposition_date`, `status`, `archived_at`, `archive_reason`, `disposed_at`, `disposal_reason`, `disposal_requested_by`, `disposal_requested_at`, `disposal_authorized_by`, `disposal_authorized_at`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'document', 1, 1, 'Company Policy', 5, '2026-01-01', 6, '2031-01-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(2, 'document', 2, 3, 'Contract Retention', 10, '2026-01-01', 6, '2036-01-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(3, 'document', 3, 3, 'Compliance Record', 10, '2026-01-01', 6, '2036-01-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(4, 'document', 4, 2, 'Financial Record', 7, '2026-01-01', 6, '2033-01-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(5, 'document', 5, 1, 'Inventory Record', 5, '2026-01-01', 6, '2031-01-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(6, 'document', 6, 1, 'Facilities Record', 5, '2026-01-01', 6, '2031-01-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(7, 'document', 7, 1, 'HR Record', 5, '2026-01-01', 6, '2031-01-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(8, 'document', 8, 1, 'IT Record', 5, '2026-01-01', 6, '2031-01-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(9, 'document', 9, 3, 'Legal Record', 10, '2026-01-01', 6, '2036-01-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(10, 'document', 10, 3, 'Contract Retention', 10, '2026-01-01', 6, '2036-01-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(11, 'contract', 1, 3, 'Contract Retention', 10, '2026-01-01', 6, '2036-01-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(12, 'contract', 2, 3, 'Contract Retention', 10, '2026-01-01', 6, '2036-01-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(13, 'contract', 3, 3, 'Contract Retention', 10, '2026-01-01', 6, '2036-01-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(14, 'contract', 4, 3, 'Contract Retention', 10, '2026-01-01', 6, '2036-01-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(15, 'contract', 5, 3, 'Contract Retention', 10, '2026-01-01', 6, '2036-01-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(16, 'legal_case', 1, 3, 'Legal Filing', 10, '2026-01-15', 6, '2036-01-15', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(17, 'legal_case', 2, 3, 'Legal Filing', 10, '2026-02-01', 6, '2036-02-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(18, 'legal_case', 3, 3, 'Legal Filing', 10, '2026-01-10', 6, '2036-01-10', 'archived', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(19, 'legal_case', 4, 3, 'Legal Filing', 10, '2026-03-01', 6, '2036-03-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(20, 'legal_case', 5, 3, 'Legal Filing', 10, '2026-04-01', 6, '2036-04-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(21, 'document', 11, 1, 'Company Policy', 5, '2026-01-01', 6, '2031-01-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(22, 'document', 12, 3, 'Compliance Record', 10, '2026-01-01', 6, '2036-01-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(23, 'document', 13, 2, 'Financial Record', 7, '2026-01-01', 6, '2033-01-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(24, 'document', 14, 1, 'HR Record', 5, '2026-01-01', 6, '2031-01-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL),
(25, 'document', 15, 3, 'Legal Record', 10, '2026-01-01', 6, '2036-01-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-30 17:00:16', '2026-09-30 17:00:16', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `team8_reservations`
--

CREATE TABLE `team8_reservations` (
  `id` int(11) NOT NULL,
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
  `deleted_at` datetime DEFAULT NULL
) ;

--
-- Dumping data for table `team8_reservations`
--

INSERT INTO `team8_reservations` (`id`, `facility_id`, `user_id`, `start_time`, `end_time`, `status`, `department_id`, `department`, `key_person`, `expected_participants`, `quantity`, `event_category`, `description`, `expected_return_date`, `remarks`, `schedule`, `requirements`, `created_at`, `updated_at`, `archived_at`, `cancellation_reason`, `cancellation_requested_by`, `cancellation_requested_at`, `cancellation_reviewed_by`, `cancellation_reviewed_at`, `cancellation_decision`, `deleted_at`) VALUES
(1, 1, 5, '2026-10-01 09:00:00', '2026-10-01 11:00:00', 'approved', 3, 'Human Resources', 'HR Hannah', 20, NULL, 'Meeting', 'Monthly team meeting', NULL, 'Need projector', NULL, 'Projector, whiteboard', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(2, 2, 5, '2026-10-02 13:00:00', '2026-10-02 17:00:00', 'approved', 3, 'Human Resources', 'HR Henry', 25, NULL, 'Training', 'New employee orientation', NULL, 'Need laptops', NULL, '30 laptops, smartboard', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(3, 3, 9, '2026-10-03 10:00:00', '2026-10-03 12:00:00', 'approved', 6, 'Operations', 'Ops Olivia', 10, NULL, 'Meeting', 'Operations review', NULL, 'Need video conferencing', NULL, 'Video conferencing, TV', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(4, 4, 13, '2026-10-05 08:00:00', '2026-10-05 18:00:00', 'approved', 10, 'Marketing', 'Marketing Mia', 150, NULL, 'Event', 'Company anniversary', NULL, 'Need sound system', NULL, 'Sound system, stage, lights', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(5, 5, 7, '2026-10-07 09:00:00', '2026-10-07 12:00:00', 'approved', 4, 'IT', 'IT Ivan', 20, NULL, 'Training', 'IT security training', NULL, 'Need computers', NULL, '25 computers, projector', '2026-09-30 17:02:02', '2026-09-30 17:18:48', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(6, 6, 15, '2026-10-08 14:00:00', '2026-10-08 15:00:00', 'approved', 3, 'Human Resources', 'HR Hannah', 5, NULL, 'Meeting', 'HR policy review', NULL, 'Need TV', NULL, 'TV, whiteboard', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(7, 7, 16, '2026-10-09 10:00:00', '2026-10-09 11:00:00', 'approved', 2, 'Finance', 'Finance Felix', 5, NULL, 'Meeting', 'Budget review', NULL, 'Need TV', NULL, 'TV, whiteboard', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(8, 8, 25, '2026-10-10 09:00:00', '2026-10-10 17:00:00', 'approved', 1, 'Administration', 'Admin Anna', 200, NULL, 'Event', 'Company town hall', NULL, 'Need microphones', NULL, 'Microphone, speaker, stage', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(9, 9, 13, '2026-10-12 16:00:00', '2026-10-12 20:00:00', 'approved', 10, 'Marketing', 'Marketing Max', 50, NULL, 'Event', 'Product launch', NULL, 'Need tent and chairs', NULL, 'Tent, chairs, tables', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(10, 1, 11, '2026-10-14 09:00:00', '2026-10-14 11:00:00', 'approved', 8, 'Facilities', 'Facilities Fiona', 15, NULL, 'Meeting', 'Facilities planning', NULL, 'Need projector', NULL, 'Projector, whiteboard', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(11, 2, 19, '2026-10-15 13:00:00', '2026-10-15 17:00:00', 'approved', 6, 'Operations', 'Ops Oscar', 25, NULL, 'Training', 'Operations training', NULL, 'Need laptops', NULL, '30 laptops, smartboard', '2026-09-30 17:02:02', '2026-09-30 17:19:16', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(12, 4, 24, '2026-10-18 08:00:00', '2026-10-18 18:00:00', 'approved', 11, 'Customer Service', 'Service Sofia', 100, NULL, 'Event', 'Customer appreciation day', NULL, 'Need sound system', NULL, 'Sound system, stage', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(13, 8, 22, '2026-10-20 09:00:00', '2026-10-20 12:00:00', 'approved', 9, 'Security', 'Security Sam', 100, NULL, 'Training', 'Security training', NULL, 'Need microphones', NULL, 'Microphone, speaker', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(14, 3, 8, '2026-10-22 14:00:00', '2026-10-22 16:00:00', 'approved', 5, 'Legal', 'Legal Lucas', 8, NULL, 'Meeting', 'Legal case review', NULL, 'Need video conferencing', NULL, 'Video conferencing, TV', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(15, 5, 28, '2026-10-25 09:00:00', '2026-10-25 12:00:00', 'approved', 4, 'IT', 'IT Ian', 20, NULL, 'Training', 'Network security training', NULL, 'Need computers', NULL, '25 computers, projector', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(16, 6, 20, '2026-10-26 10:00:00', '2026-10-26 11:00:00', 'cancelled', 7, 'Procurement', 'Procurement Petra', 5, NULL, 'Meeting', 'Vendor evaluation', NULL, 'Cancelled due to conflict', NULL, 'TV, whiteboard', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(17, 7, 14, '2026-10-28 15:00:00', '2026-10-28 16:00:00', 'approved', 11, 'Customer Service', 'Service Simon', 5, NULL, 'Meeting', 'Customer feedback review', NULL, 'Need TV', NULL, 'TV, whiteboard', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(18, 9, 23, '2026-10-30 17:00:00', '2026-10-30 21:00:00', 'pending', 10, 'Marketing', 'Marketing Max', 60, NULL, 'Event', 'Halloween party', NULL, 'Need tent and chairs', NULL, 'Tent, chairs, tables', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(19, 1, 1, '2026-11-02 09:00:00', '2026-11-02 11:00:00', 'approved', 1, 'Administration', 'Dev Tester', 20, NULL, 'Meeting', 'Management meeting', NULL, 'Need projector', NULL, 'Projector, whiteboard', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(20, 2, 15, '2026-11-04 13:00:00', '2026-11-04 17:00:00', 'approved', 3, 'Human Resources', 'HR Hannah', 25, NULL, 'Training', 'Leadership training', NULL, 'Need laptops', NULL, '30 laptops, smartboard', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(21, 3, 4, '2026-11-06 10:00:00', '2026-11-06 12:00:00', 'approved', 5, 'Legal', 'Legal Lena', 10, NULL, 'Meeting', 'Contract review', NULL, 'Need video conferencing', NULL, 'Video conferencing, TV', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(22, 4, 9, '2026-11-08 08:00:00', '2026-11-08 18:00:00', 'approved', 6, 'Operations', 'Ops Olivia', 150, NULL, 'Event', 'Operations summit', NULL, 'Need sound system', NULL, 'Sound system, stage, lights', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(23, 8, 25, '2026-11-10 09:00:00', '2026-11-10 12:00:00', 'pending', 1, 'Administration', 'Admin Anna', 200, NULL, 'Event', 'Year-end planning', NULL, 'Need microphones', NULL, 'Microphone, speaker', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(24, 5, 17, '2026-11-12 09:00:00', '2026-11-12 12:00:00', 'approved', 4, 'IT', 'IT Irene', 20, NULL, 'Training', 'Software training', NULL, 'Need computers', NULL, '25 computers, projector', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(25, 1, 29, '2026-11-15 14:00:00', '2026-11-15 16:00:00', 'approved', 5, 'Legal', 'Legal Lily', 15, NULL, 'Meeting', 'Legal compliance review', NULL, 'Need projector', NULL, 'Projector, whiteboard', '2026-09-30 17:02:02', '2026-09-30 17:02:02', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

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
  `remarks` text DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_reservation_approvals`
--

INSERT INTO `team8_reservation_approvals` (`id`, `reservation_id`, `approver_id`, `step_order`, `status`, `remarks`, `decided_at`) VALUES
(1, 1, 1, 1, 'approved', 'Approved.', '2026-09-20 10:00:00'),
(2, 2, 1, 1, 'approved', 'Approved.', '2026-09-20 10:05:00'),
(3, 3, 1, 1, 'approved', 'Approved.', '2026-09-20 10:10:00'),
(4, 4, 1, 1, 'approved', 'Approved.', '2026-09-20 10:15:00'),
(5, 5, 1, 1, 'pending', 'Awaiting review.', NULL),
(6, 6, 1, 1, 'approved', 'Approved.', '2026-09-21 09:00:00'),
(7, 7, 1, 1, 'approved', 'Approved.', '2026-09-21 09:05:00'),
(8, 8, 1, 1, 'approved', 'Approved.', '2026-09-21 09:10:00'),
(9, 9, 1, 1, 'approved', 'Approved.', '2026-09-21 09:15:00'),
(10, 10, 1, 1, 'approved', 'Approved.', '2026-09-22 10:00:00'),
(11, 11, 1, 1, 'pending', 'Awaiting review.', NULL),
(12, 12, 1, 1, 'approved', 'Approved.', '2026-09-22 10:05:00'),
(13, 13, 1, 1, 'approved', 'Approved.', '2026-09-22 10:10:00'),
(14, 14, 1, 1, 'approved', 'Approved.', '2026-09-23 09:00:00'),
(15, 15, 1, 1, 'approved', 'Approved.', '2026-09-23 09:05:00'),
(16, 16, 1, 1, 'rejected', 'Cancelled by requester.', '2026-09-24 10:00:00'),
(17, 17, 1, 1, 'approved', 'Approved.', '2026-09-24 10:05:00'),
(18, 18, 1, 1, 'pending', 'Awaiting review.', NULL),
(19, 19, 1, 1, 'approved', 'Approved.', '2026-09-25 09:00:00'),
(20, 20, 1, 1, 'approved', 'Approved.', '2026-09-25 09:05:00'),
(21, 21, 1, 1, 'approved', 'Approved.', '2026-09-25 09:10:00'),
(22, 22, 1, 1, 'approved', 'Approved.', '2026-09-26 09:00:00'),
(23, 23, 1, 1, 'pending', 'Awaiting review.', NULL),
(24, 24, 1, 1, 'approved', 'Approved.', '2026-09-26 09:05:00'),
(25, 25, 1, 1, 'approved', 'Approved.', '2026-09-26 09:10:00'),
(26, 5, 1, 1, 'approved', NULL, '2026-09-30 17:18:48'),
(27, 11, 1, 1, 'approved', NULL, '2026-09-30 17:19:16');

-- --------------------------------------------------------

--
-- Table structure for table `team8_reservation_cancellation_requests`
--

CREATE TABLE `team8_reservation_cancellation_requests` (
  `id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `reason` text NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `admin_remark` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_reservation_cancellation_requests`
--

INSERT INTO `team8_reservation_cancellation_requests` (`id`, `reservation_id`, `requested_by`, `reason`, `status`, `requested_at`, `reviewed_by`, `reviewed_at`, `admin_remark`) VALUES
(1, 16, 20, 'Conflict with another meeting.', 'approved', '2026-09-23 10:00:00', 1, '2026-09-24 09:00:00', 'Cancellation approved.'),
(2, 1, 5, 'Team meeting rescheduled.', 'approved', '2026-09-19 08:00:00', 1, '2026-09-19 09:00:00', 'Cancellation approved.'),
(3, 2, 5, 'Training postponed.', 'pending', '2026-09-28 10:00:00', NULL, NULL, NULL);

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

--
-- Dumping data for table `team8_reservation_equipment`
--

INSERT INTO `team8_reservation_equipment` (`id`, `reservation_id`, `equipment_id`, `quantity`, `created_at`) VALUES
(1, 1, 1, 1, '2026-09-30 17:02:02'),
(2, 1, 2, 1, '2026-09-30 17:02:02'),
(3, 2, 3, 25, '2026-09-30 17:02:02'),
(4, 2, 4, 1, '2026-09-30 17:02:02'),
(5, 3, 5, 1, '2026-09-30 17:02:02'),
(6, 3, 9, 1, '2026-09-30 17:02:02'),
(7, 4, 6, 2, '2026-09-30 17:02:02'),
(8, 4, 7, 4, '2026-09-30 17:02:02'),
(9, 5, 8, 25, '2026-09-30 17:02:02'),
(10, 5, 1, 1, '2026-09-30 17:02:02'),
(11, 6, 9, 1, '2026-09-30 17:02:02'),
(12, 6, 2, 1, '2026-09-30 17:02:02'),
(13, 7, 10, 1, '2026-09-30 17:02:02'),
(14, 8, 11, 6, '2026-09-30 17:02:02'),
(15, 8, 12, 4, '2026-09-30 17:02:02'),
(16, 9, 13, 3, '2026-09-30 17:02:02'),
(17, 9, 14, 50, '2026-09-30 17:02:02'),
(18, 9, 15, 10, '2026-09-30 17:02:02'),
(19, 10, 1, 1, '2026-09-30 17:02:02'),
(20, 10, 2, 1, '2026-09-30 17:02:02'),
(21, 11, 3, 25, '2026-09-30 17:02:02'),
(22, 11, 4, 1, '2026-09-30 17:02:02'),
(23, 12, 6, 2, '2026-09-30 17:02:02'),
(24, 12, 7, 4, '2026-09-30 17:02:02'),
(25, 13, 11, 6, '2026-09-30 17:02:02'),
(26, 13, 12, 4, '2026-09-30 17:02:02'),
(27, 14, 5, 1, '2026-09-30 17:02:02'),
(28, 14, 9, 1, '2026-09-30 17:02:02'),
(29, 15, 8, 25, '2026-09-30 17:02:02'),
(30, 15, 1, 1, '2026-09-30 17:02:02'),
(31, 16, 9, 1, '2026-09-30 17:02:02'),
(32, 16, 2, 1, '2026-09-30 17:02:02'),
(33, 17, 10, 1, '2026-09-30 17:02:02'),
(34, 18, 13, 3, '2026-09-30 17:02:02'),
(35, 18, 14, 50, '2026-09-30 17:02:02'),
(36, 18, 15, 10, '2026-09-30 17:02:02'),
(37, 19, 1, 1, '2026-09-30 17:02:02'),
(38, 19, 2, 1, '2026-09-30 17:02:02'),
(39, 20, 3, 25, '2026-09-30 17:02:02'),
(40, 20, 4, 1, '2026-09-30 17:02:02'),
(41, 21, 5, 1, '2026-09-30 17:02:02'),
(42, 21, 9, 1, '2026-09-30 17:02:02'),
(43, 22, 6, 2, '2026-09-30 17:02:02'),
(44, 22, 7, 4, '2026-09-30 17:02:02'),
(45, 23, 11, 6, '2026-09-30 17:02:02'),
(46, 23, 12, 4, '2026-09-30 17:02:02'),
(47, 24, 8, 25, '2026-09-30 17:02:02'),
(48, 24, 1, 1, '2026-09-30 17:02:02'),
(49, 25, 1, 1, '2026-09-30 17:02:02'),
(50, 25, 2, 1, '2026-09-30 17:02:02');

-- --------------------------------------------------------

--
-- Table structure for table `team8_retention_schedules`
--

CREATE TABLE `team8_retention_schedules` (
  `id` int(11) NOT NULL,
  `record_type` varchar(150) NOT NULL,
  `retention_years` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ;

--
-- Dumping data for table `team8_retention_schedules`
--

INSERT INTO `team8_retention_schedules` (`id`, `record_type`, `retention_years`, `created_at`) VALUES
(1, 'HR Records', 5, '2026-09-30 16:50:45'),
(2, 'Financial Records', 7, '2026-09-30 16:50:45'),
(3, 'Legal Filings', 10, '2026-09-30 16:50:45');

-- --------------------------------------------------------

--
-- Table structure for table `team8_visitors`
--

CREATE TABLE `team8_visitors` (
  `id` int(11) NOT NULL,
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
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team8_visitors`
--

INSERT INTO `team8_visitors` (`id`, `full_name`, `visitor_type`, `contact`, `company`, `person_to_visit`, `purpose`, `scheduled_date`, `status`, `check_in_time`, `check_out_time`, `logged_by`, `created_at`, `updated_at`) VALUES
(1, 'Juan Dela Cruz', 'Client', '+63-917-111-2222', 'ABC Corp', 'Dev Tester', 'Business meeting', '2026-09-01 09:00:00', 'completed', '2026-09-01 09:05:00', '2026-09-01 10:30:00', 3, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(2, 'Maria Clara', 'Supplier', '+63-917-222-3333', 'XYZ Ltd', 'Procurement Paul', 'Contract discussion', '2026-09-02 10:00:00', 'completed', '2026-09-02 10:10:00', '2026-09-02 11:45:00', 3, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(3, 'Jose Rizal', 'Consultant', '+63-917-333-4444', 'YZA Cons', 'Legal Lena', 'Legal consultation', '2026-09-03 14:00:00', 'completed', '2026-09-03 14:05:00', '2026-09-03 15:30:00', 3, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(4, 'Andres Bonifacio', 'Client', '+63-917-444-5555', 'PQR Trade', 'Admin Anna', 'Partnership discussion', '2026-09-05 11:00:00', 'completed', '2026-09-05 11:10:00', '2026-09-05 12:00:00', 3, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(5, 'Emilio Aguinaldo', 'Government', '+63-917-555-6666', 'LGU', 'Admin Anna', 'Permit inspection', '2026-09-07 08:30:00', 'completed', '2026-09-07 08:35:00', '2026-09-07 09:30:00', 3, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(6, 'Apolinario Mabini', 'Client', '+63-917-666-7777', 'MNO Svc', 'Ops Olivia', 'Service review', '2026-09-08 13:00:00', 'completed', '2026-09-08 13:05:00', '2026-09-08 14:15:00', 3, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(7, 'Gabriela Silang', 'Supplier', '+63-917-777-8888', 'STU Log', 'Procurement Paul', 'Logistics discussion', '2026-09-10 09:30:00', 'completed', '2026-09-10 09:35:00', '2026-09-10 10:45:00', 3, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(8, 'Melchora Aquino', 'Client', '+63-917-888-9999', 'VWX Mfg', 'Ops Olivia', 'Manufacturing review', '2026-09-12 15:00:00', 'completed', '2026-09-12 15:05:00', '2026-09-12 16:30:00', 3, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(9, 'Lapu-Lapu', 'VIP', '+63-917-999-0000', 'GHI Ent', 'Dev Tester', 'Executive meeting', '2026-09-15 10:00:00', 'completed', '2026-09-15 10:05:00', '2026-09-15 11:30:00', 3, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(10, 'Sultan Kudarat', 'Client', '+63-918-111-2222', 'JKL Sol', 'Marketing Mia', 'Marketing strategy', '2026-09-18 14:00:00', 'completed', '2026-09-18 14:05:00', '2026-09-18 15:00:00', 3, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(11, 'Rajah Sulayman', 'Supplier', '+63-918-222-3333', 'DEF Inc', 'Procurement Paul', 'Vendor discussion', '2026-09-20 09:00:00', 'completed', '2026-09-20 09:10:00', '2026-09-20 10:00:00', 3, '2026-09-30 17:01:50', '2026-09-30 17:01:50'),
(12, 'Datu Puti', 'Client', '+63-918-333-4444', 'ABC Corp', 'Admin Anna', 'Contract signing', '2026-09-22 11:00:00', 'expired', NULL, NULL, 3, '2026-09-30 17:01:50', '2026-09-30 17:03:13'),
(13, 'Princess Urduja', 'Consultant', '+63-918-444-5555', 'YZA Cons', 'Legal Lena', 'Legal advisory', '2026-09-25 14:00:00', 'expired', NULL, NULL, 3, '2026-09-30 17:01:50', '2026-09-30 17:03:13'),
(14, 'Sultan Dipatuan', 'Government', '+63-918-555-6666', 'LGU', 'Admin Anna', 'Compliance inspection', '2026-09-28 08:00:00', 'expired', NULL, NULL, 3, '2026-09-30 17:01:50', '2026-09-30 17:03:13'),
(15, 'Bai Labi', 'Client', '+63-918-666-7777', 'PQR Trade', 'Ops Olivia', 'Trading discussion', '2026-09-30 10:00:00', 'late', NULL, NULL, 3, '2026-09-30 17:01:50', '2026-09-30 17:03:13');

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
(1, 1, 'Dev Tester', 'dev.tester@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(2, 8, 'Facilities Fran', 'facilities@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(3, 3, 'Frontdesk Fred', 'frontdesk@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(4, 5, 'Legal Lena', 'legal@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(5, 3, 'Employee Ella', 'employee@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(6, 1, 'Records Rita', 'records@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(7, 4, 'IT Ivan', 'it.ivan@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(8, 5, 'Legal Lucas', 'legal.lucas@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(9, 6, 'Ops Olivia', 'ops.olivia@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(10, 7, 'Procurement Paul', 'procurement.paul@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(11, 8, 'Facilities Fiona', 'facilities.fiona@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(12, 9, 'Security Sam', 'security.sam@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(13, 10, 'Marketing Mia', 'marketing.mia@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(14, 11, 'Service Sofia', 'service.sofia@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(15, 3, 'HR Hannah', 'hr.hannah@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(16, 2, 'Finance Felix', 'finance.felix@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(17, 4, 'IT Irene', 'it.irene@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(18, 5, 'Legal Leo', 'legal.leo@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(19, 6, 'Ops Oscar', 'ops.oscar@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(20, 7, 'Procurement Petra', 'procurement.petra@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(21, 8, 'Facilities Frank', 'facilities.frank@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(22, 9, 'Security Sarah', 'security.sarah@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(23, 10, 'Marketing Max', 'marketing.max@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(24, 11, 'Service Simon', 'service.simon@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(25, 1, 'Admin Anna', 'admin.anna@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(26, 2, 'Finance Fiona', 'finance.fiona@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(27, 3, 'HR Henry', 'hr.henry@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(28, 4, 'IT Ian', 'it.ian@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(29, 5, 'Legal Lily', 'legal.lily@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL),
(30, 6, 'Ops Owen', 'ops.owen@example.local', '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om', '2026-09-30 16:56:16', '2026-09-30 16:56:16', NULL);

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
(7, 1, 1, '2026-09-30 16:56:16'),
(8, 2, 2, '2026-09-30 16:56:16'),
(9, 3, 3, '2026-09-30 16:56:16'),
(10, 4, 5, '2026-09-30 16:56:16'),
(11, 5, 6, '2026-09-30 16:56:16'),
(12, 6, 4, '2026-09-30 16:56:16'),
(13, 7, 6, '2026-09-30 16:56:16'),
(14, 8, 5, '2026-09-30 16:56:16'),
(15, 9, 6, '2026-09-30 16:56:16'),
(16, 10, 6, '2026-09-30 16:56:16'),
(17, 11, 2, '2026-09-30 16:56:16'),
(18, 12, 6, '2026-09-30 16:56:16'),
(19, 13, 6, '2026-09-30 16:56:16'),
(20, 14, 6, '2026-09-30 16:56:16'),
(21, 15, 6, '2026-09-30 16:56:16'),
(22, 16, 6, '2026-09-30 16:56:16'),
(23, 17, 6, '2026-09-30 16:56:16'),
(24, 18, 5, '2026-09-30 16:56:16'),
(25, 19, 6, '2026-09-30 16:56:16'),
(26, 20, 6, '2026-09-30 16:56:16'),
(27, 21, 2, '2026-09-30 16:56:16'),
(28, 22, 6, '2026-09-30 16:56:16'),
(29, 23, 6, '2026-09-30 16:56:16'),
(30, 24, 6, '2026-09-30 16:56:16'),
(31, 25, 1, '2026-09-30 16:56:16'),
(32, 26, 6, '2026-09-30 16:56:16'),
(33, 27, 6, '2026-09-30 16:56:16'),
(34, 28, 6, '2026-09-30 16:56:16'),
(35, 29, 5, '2026-09-30 16:56:16'),
(36, 30, 6, '2026-09-30 16:56:16');

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
  ADD KEY `idx_notifications_user_status` (`user_id`,`status`,`created_at`);

--
-- Indexes for table `renewal_requests`
--
ALTER TABLE `renewal_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_renewal_requests_initiated_by` (`initiated_by`);

--
-- Indexes for table `retention_renewal_rules`
--
ALTER TABLE `retention_renewal_rules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_retention_renewal_rule` (`main_document_type`,`prerequisite_document_type`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Indexes for table `team8_certificates`
--
ALTER TABLE `team8_certificates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `document_number` (`document_number`),
  ADD KEY `fk_team8_cert_employee` (`employee_id`),
  ADD KEY `fk_team8_cert_preparer` (`prepared_by`),
  ADD KEY `idx_team8_cert_status` (`status`),
  ADD KEY `idx_team8_cert_type` (`certificate_type`),
  ADD KEY `idx_team8_cert_browse` (`status`,`employee_id`,`updated_at`,`id`);

--
-- Indexes for table `team8_certificate_recipients`
--
ALTER TABLE `team8_certificate_recipients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_team8_cert_recipient` (`certificate_id`,`employee_id`),
  ADD KEY `idx_team8_cert_recipient_employee` (`employee_id`);

--
-- Indexes for table `team8_compliance_checks`
--
ALTER TABLE `team8_compliance_checks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_compliance_record` (`record_id`),
  ADD KEY `fk_team8_compliance_checker` (`checked_by`);

--
-- Indexes for table `team8_compliance_reports`
--
ALTER TABLE `team8_compliance_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_team8_compliance_reports_status` (`status`,`created_at`),
  ADD KEY `idx_team8_compliance_reports_department_dates` (`department_id`,`date_from`,`date_to`),
  ADD KEY `fk_team8_compliance_reports_generated_by` (`generated_by`),
  ADD KEY `fk_team8_compliance_reports_approved_by` (`approved_by`),
  ADD KEY `fk_team8_compliance_reports_document` (`document_id`);

--
-- Indexes for table `team8_compliance_report_documents`
--
ALTER TABLE `team8_compliance_report_documents`
  ADD PRIMARY KEY (`compliance_report_id`,`document_id`),
  ADD KEY `idx_team8_compliance_report_documents_document` (`document_id`);

--
-- Indexes for table `team8_contracts`
--
ALTER TABLE `team8_contracts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `contract_number` (`contract_number`),
  ADD KEY `fk_team8_contracts_owner` (`owner_id`),
  ADD KEY `fk_team8_contracts_renewed` (`renewed_from_id`),
  ADD KEY `idx_team8_contracts_status` (`status`),
  ADD KEY `idx_team8_contracts_deleted_status` (`deleted_at`,`status`),
  ADD KEY `idx_team8_contracts_enddate` (`end_date`),
  ADD KEY `idx_team8_contracts_number` (`contract_number`),
  ADD KEY `idx_team8_contracts_department` (`department_id`,`status`),
  ADD KEY `idx_team8_contracts_type_status` (`contract_type`,`status`);

--
-- Indexes for table `team8_contract_approvals`
--
ALTER TABLE `team8_contract_approvals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_contractapproval_reviewer` (`reviewer_id`),
  ADD KEY `fk_team8_contractapproval_approver` (`approver_id`),
  ADD KEY `idx_team8_contractapproval_contract` (`contract_id`,`acted_at`);

--
-- Indexes for table `team8_contract_documents`
--
ALTER TABLE `team8_contract_documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_team8_contract_documents_contract_document` (`contract_id`,`document_id`),
  ADD KEY `fk_team8_contractdocs_document` (`document_id`);

--
-- Indexes for table `team8_contract_history`
--
ALTER TABLE `team8_contract_history`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_team8_contract_history` (`contract_id`,`version_no`),
  ADD KEY `fk_team8_contracthistory_user` (`changed_by`);

--
-- Indexes for table `team8_contract_parties`
--
ALTER TABLE `team8_contract_parties`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_team8_contract_parties_contract_party_role` (`contract_id`,`party_id`,`role_in_contract`),
  ADD KEY `fk_team8_contractparties_party` (`party_id`);

--
-- Indexes for table `team8_documents`
--
ALTER TABLE `team8_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_documents_department` (`department_id`),
  ADD KEY `fk_team8_documents_owner` (`owner_id`),
  ADD KEY `idx_team8_documents_title` (`title`),
  ADD KEY `idx_team8_documents_status` (`status`),
  ADD KEY `idx_team8_documents_expiration` (`expiration_date`),
  ADD KEY `idx_team8_documents_browse_owner` (`uploaded_by`,`status`,`deleted_at`,`updated_at`,`id`),
  ADD KEY `idx_team8_documents_browse_category` (`category_id`,`status`,`deleted_at`,`updated_at`,`id`),
  ADD KEY `idx_team8_documents_browse_deleted` (`deleted_at`,`updated_at`,`id`);

--
-- Indexes for table `team8_document_categories`
--
ALTER TABLE `team8_document_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `team8_document_number_sequences`
--
ALTER TABLE `team8_document_number_sequences`
  ADD PRIMARY KEY (`prefix`,`sequence_year`);

--
-- Indexes for table `team8_document_versions`
--
ALTER TABLE `team8_document_versions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_team8_docversions_doc_version` (`document_id`,`version_no`);

--
-- Indexes for table `team8_equipment`
--
ALTER TABLE `team8_equipment`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_equipment_facility` (`home_facility_id`);

--
-- Indexes for table `team8_explanations`
--
ALTER TABLE `team8_explanations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_team8_explanation_nte` (`nte_id`),
  ADD KEY `fk_team8_expl_employee` (`employee_id`),
  ADD KEY `fk_team8_expl_reviewer` (`reviewed_by`),
  ADD KEY `idx_team8_expl_status` (`status`),
  ADD KEY `idx_team8_expl_nte` (`nte_id`),
  ADD KEY `idx_team8_expl_browse` (`status`,`employee_id`,`updated_at`,`id`);

--
-- Indexes for table `team8_facilities`
--
ALTER TABLE `team8_facilities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_team8_facilities_status` (`status`);

--
-- Indexes for table `team8_facility_locations`
--
ALTER TABLE `team8_facility_locations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `team8_facility_maintenance_history`
--
ALTER TABLE `team8_facility_maintenance_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_t8_maintenance_user` (`performed_by`),
  ADD KEY `idx_team8_maintenance_facility_date` (`facility_id`,`maintenance_date`);

--
-- Indexes for table `team8_hr_document_sequences`
--
ALTER TABLE `team8_hr_document_sequences`
  ADD PRIMARY KEY (`prefix`,`document_year`);

--
-- Indexes for table `team8_hr_document_versions`
--
ALTER TABLE `team8_hr_document_versions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_team8_hrver` (`doc_type`,`doc_id`,`version_no`),
  ADD KEY `fk_team8_hrver_creator` (`created_by`),
  ADD KEY `idx_team8_hrver_lookup` (`doc_type`,`doc_id`);

--
-- Indexes for table `team8_incident_reports`
--
ALTER TABLE `team8_incident_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `document_number` (`document_number`),
  ADD KEY `fk_team8_ir_preparer` (`prepared_by`),
  ADD KEY `fk_team8_ir_department` (`department_id`),
  ADD KEY `idx_team8_ir_status` (`status`),
  ADD KEY `idx_team8_ir_employee` (`employee_id`),
  ADD KEY `idx_team8_ir_browse` (`status`,`employee_id`,`updated_at`,`id`);

--
-- Indexes for table `team8_legal_cases`
--
ALTER TABLE `team8_legal_cases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_legalcases_department` (`department_id`),
  ADD KEY `fk_team8_legalcases_contract` (`contract_id`),
  ADD KEY `idx_team8_legalcases_status` (`status`),
  ADD KEY `idx_team8_legalcases_deleted_status` (`deleted_at`,`status`),
  ADD KEY `idx_team8_legal_cases_assignee` (`assigned_to`,`status`);

--
-- Indexes for table `team8_legal_documents`
--
ALTER TABLE `team8_legal_documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_team8_legal_documents_case_document` (`case_id`,`document_id`),
  ADD KEY `fk_team8_legaldocs_document` (`document_id`);

--
-- Indexes for table `team8_login_throttle`
--
ALTER TABLE `team8_login_throttle`
  ADD PRIMARY KEY (`identifier`);

--
-- Indexes for table `team8_memorandums`
--
ALTER TABLE `team8_memorandums`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `document_number` (`document_number`),
  ADD KEY `fk_team8_memo_preparer` (`prepared_by`),
  ADD KEY `idx_team8_memo_status` (`status`),
  ADD KEY `idx_team8_memo_kind` (`kind`),
  ADD KEY `idx_team8_memo_browse` (`status`,`kind`,`updated_at`,`id`);

--
-- Indexes for table `team8_memorandum_recipients`
--
ALTER TABLE `team8_memorandum_recipients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_team8_memo_recipient` (`memorandum_id`,`recipient_type`,`department_id`),
  ADD KEY `idx_team8_memo_recipient_department` (`department_id`),
  ADD KEY `idx_team8_memo_recipient_browse` (`memorandum_id`,`department_id`,`recipient_type`);

--
-- Indexes for table `team8_notice_to_explain`
--
ALTER TABLE `team8_notice_to_explain`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `document_number` (`document_number`),
  ADD UNIQUE KEY `uq_team8_nte_incident` (`incident_report_id`),
  ADD KEY `fk_team8_nte_employee` (`employee_id`),
  ADD KEY `fk_team8_nte_preparer` (`prepared_by`),
  ADD KEY `idx_team8_nte_status` (`status`),
  ADD KEY `idx_team8_nte_incident` (`incident_report_id`),
  ADD KEY `idx_team8_nte_browse` (`status`,`employee_id`,`updated_at`,`id`);

--
-- Indexes for table `team8_parties`
--
ALTER TABLE `team8_parties`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_team8_parties_type_name` (`type`,`name`);

--
-- Indexes for table `team8_records`
--
ALTER TABLE `team8_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_team8_records_entity` (`entity_type`,`entity_id`),
  ADD KEY `fk_team8_records_schedule` (`schedule_id`),
  ADD KEY `fk_team8_records_custodian` (`custodian_id`),
  ADD KEY `fk_team8_records_disposal_requester` (`disposal_requested_by`),
  ADD KEY `fk_team8_records_disposal_authorizer` (`disposal_authorized_by`),
  ADD KEY `idx_team8_records_status` (`status`),
  ADD KEY `idx_team8_records_status_deleted` (`status`,`deleted_at`),
  ADD KEY `idx_team8_records_disposition_date` (`disposition_date`),
  ADD KEY `idx_team8_records_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_team8_records_disposition` (`disposition_date`,`status`);

--
-- Indexes for table `team8_reservations`
--
ALTER TABLE `team8_reservations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_reservations_facility` (`facility_id`),
  ADD KEY `fk_team8_reservations_user` (`user_id`),
  ADD KEY `fk_team8_reservations_cancel_requester` (`cancellation_requested_by`),
  ADD KEY `fk_team8_reservations_cancel_reviewer` (`cancellation_reviewed_by`),
  ADD KEY `idx_team8_reservations_status` (`status`),
  ADD KEY `idx_team8_reservations_dates` (`start_time`,`end_time`),
  ADD KEY `idx_team8_reservations_deleted_at` (`deleted_at`),
  ADD KEY `idx_team8_reservations_archived_at` (`archived_at`),
  ADD KEY `idx_team8_reservations_cancellation_status` (`status`,`cancellation_requested_at`),
  ADD KEY `idx_team8_reservations_department` (`department_id`);

--
-- Indexes for table `team8_reservation_approvals`
--
ALTER TABLE `team8_reservation_approvals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_resapproval_reservation` (`reservation_id`),
  ADD KEY `fk_team8_resapproval_approver` (`approver_id`);

--
-- Indexes for table `team8_reservation_cancellation_requests`
--
ALTER TABLE `team8_reservation_cancellation_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_cancel_request_reservation` (`reservation_id`),
  ADD KEY `fk_team8_cancel_request_requester` (`requested_by`),
  ADD KEY `fk_team8_cancel_request_reviewer` (`reviewed_by`),
  ADD KEY `idx_team8_cancel_request_pending` (`status`,`requested_at`);

--
-- Indexes for table `team8_reservation_equipment`
--
ALTER TABLE `team8_reservation_equipment`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_team8_reservation_equipment` (`reservation_id`,`equipment_id`),
  ADD KEY `fk_team8_resequip_equipment` (`equipment_id`);

--
-- Indexes for table `team8_retention_schedules`
--
ALTER TABLE `team8_retention_schedules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_team8_retention_record_type` (`record_type`);

--
-- Indexes for table `team8_visitors`
--
ALTER TABLE `team8_visitors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_team8_visitors_logger` (`logged_by`),
  ADD KEY `idx_team8_visitors_status` (`status`),
  ADD KEY `idx_team8_visitors_status_scheduled` (`status`,`scheduled_date`),
  ADD KEY `idx_team8_visitors_scheduled` (`scheduled_date`);

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
  ADD UNIQUE KEY `uq_user_roles_user_role` (`user_id`,`role_id`),
  ADD KEY `fk_user_roles_role` (`role_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `renewal_requests`
--
ALTER TABLE `renewal_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `retention_renewal_rules`
--
ALTER TABLE `retention_renewal_rules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `team8_certificates`
--
ALTER TABLE `team8_certificates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team8_certificate_recipients`
--
ALTER TABLE `team8_certificate_recipients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team8_compliance_checks`
--
ALTER TABLE `team8_compliance_checks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `team8_compliance_reports`
--
ALTER TABLE `team8_compliance_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team8_contracts`
--
ALTER TABLE `team8_contracts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `team8_contract_approvals`
--
ALTER TABLE `team8_contract_approvals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `team8_contract_documents`
--
ALTER TABLE `team8_contract_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team8_contract_history`
--
ALTER TABLE `team8_contract_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `team8_contract_parties`
--
ALTER TABLE `team8_contract_parties`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `team8_documents`
--
ALTER TABLE `team8_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `team8_document_categories`
--
ALTER TABLE `team8_document_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `team8_document_versions`
--
ALTER TABLE `team8_document_versions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team8_equipment`
--
ALTER TABLE `team8_equipment`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team8_explanations`
--
ALTER TABLE `team8_explanations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `team8_facilities`
--
ALTER TABLE `team8_facilities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team8_facility_locations`
--
ALTER TABLE `team8_facility_locations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `team8_facility_maintenance_history`
--
ALTER TABLE `team8_facility_maintenance_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `team8_hr_document_versions`
--
ALTER TABLE `team8_hr_document_versions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team8_incident_reports`
--
ALTER TABLE `team8_incident_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `team8_legal_cases`
--
ALTER TABLE `team8_legal_cases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `team8_legal_documents`
--
ALTER TABLE `team8_legal_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `team8_memorandums`
--
ALTER TABLE `team8_memorandums`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `team8_memorandum_recipients`
--
ALTER TABLE `team8_memorandum_recipients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team8_notice_to_explain`
--
ALTER TABLE `team8_notice_to_explain`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `team8_parties`
--
ALTER TABLE `team8_parties`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `team8_records`
--
ALTER TABLE `team8_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team8_reservations`
--
ALTER TABLE `team8_reservations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team8_reservation_approvals`
--
ALTER TABLE `team8_reservation_approvals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `team8_reservation_cancellation_requests`
--
ALTER TABLE `team8_reservation_cancellation_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `team8_reservation_equipment`
--
ALTER TABLE `team8_reservation_equipment`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=51;

--
-- AUTO_INCREMENT for table `team8_retention_schedules`
--
ALTER TABLE `team8_retention_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team8_visitors`
--
ALTER TABLE `team8_visitors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `user_roles`
--
ALTER TABLE `user_roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

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
-- Constraints for table `renewal_requests`
--
ALTER TABLE `renewal_requests`
  ADD CONSTRAINT `fk_renewal_requests_user` FOREIGN KEY (`initiated_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `team8_certificates`
--
ALTER TABLE `team8_certificates`
  ADD CONSTRAINT `fk_team8_cert_employee` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_cert_preparer` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `team8_certificate_recipients`
--
ALTER TABLE `team8_certificate_recipients`
  ADD CONSTRAINT `fk_team8_cert_recipient_certificate` FOREIGN KEY (`certificate_id`) REFERENCES `team8_certificates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_team8_cert_recipient_employee` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `team8_compliance_checks`
--
ALTER TABLE `team8_compliance_checks`
  ADD CONSTRAINT `fk_team8_compliance_checker` FOREIGN KEY (`checked_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_compliance_record` FOREIGN KEY (`record_id`) REFERENCES `team8_records` (`id`);

--
-- Constraints for table `team8_compliance_reports`
--
ALTER TABLE `team8_compliance_reports`
  ADD CONSTRAINT `fk_team8_compliance_reports_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_compliance_reports_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  ADD CONSTRAINT `fk_team8_compliance_reports_document` FOREIGN KEY (`document_id`) REFERENCES `team8_documents` (`id`),
  ADD CONSTRAINT `fk_team8_compliance_reports_generated_by` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `team8_compliance_report_documents`
--
ALTER TABLE `team8_compliance_report_documents`
  ADD CONSTRAINT `fk_team8_compliance_report_documents_document` FOREIGN KEY (`document_id`) REFERENCES `team8_documents` (`id`),
  ADD CONSTRAINT `fk_team8_compliance_report_documents_report` FOREIGN KEY (`compliance_report_id`) REFERENCES `team8_compliance_reports` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `team8_contracts`
--
ALTER TABLE `team8_contracts`
  ADD CONSTRAINT `fk_team8_contracts_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  ADD CONSTRAINT `fk_team8_contracts_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_contracts_renewed` FOREIGN KEY (`renewed_from_id`) REFERENCES `team8_contracts` (`id`);

--
-- Constraints for table `team8_contract_approvals`
--
ALTER TABLE `team8_contract_approvals`
  ADD CONSTRAINT `fk_team8_contractapproval_approver` FOREIGN KEY (`approver_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_contractapproval_contract` FOREIGN KEY (`contract_id`) REFERENCES `team8_contracts` (`id`),
  ADD CONSTRAINT `fk_team8_contractapproval_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `team8_contract_documents`
--
ALTER TABLE `team8_contract_documents`
  ADD CONSTRAINT `fk_team8_contractdocs_contract` FOREIGN KEY (`contract_id`) REFERENCES `team8_contracts` (`id`),
  ADD CONSTRAINT `fk_team8_contractdocs_document` FOREIGN KEY (`document_id`) REFERENCES `team8_documents` (`id`);

--
-- Constraints for table `team8_contract_history`
--
ALTER TABLE `team8_contract_history`
  ADD CONSTRAINT `fk_team8_contracthistory_contract` FOREIGN KEY (`contract_id`) REFERENCES `team8_contracts` (`id`),
  ADD CONSTRAINT `fk_team8_contracthistory_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`);

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
  ADD CONSTRAINT `fk_team8_documents_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  ADD CONSTRAINT `fk_team8_documents_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`),
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
-- Constraints for table `team8_explanations`
--
ALTER TABLE `team8_explanations`
  ADD CONSTRAINT `fk_team8_expl_employee` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_expl_nte` FOREIGN KEY (`nte_id`) REFERENCES `team8_notice_to_explain` (`id`),
  ADD CONSTRAINT `fk_team8_expl_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `team8_facility_maintenance_history`
--
ALTER TABLE `team8_facility_maintenance_history`
  ADD CONSTRAINT `fk_t8_maintenance_facility` FOREIGN KEY (`facility_id`) REFERENCES `team8_facilities` (`id`),
  ADD CONSTRAINT `fk_t8_maintenance_user` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `team8_hr_document_versions`
--
ALTER TABLE `team8_hr_document_versions`
  ADD CONSTRAINT `fk_team8_hrver_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `team8_incident_reports`
--
ALTER TABLE `team8_incident_reports`
  ADD CONSTRAINT `fk_team8_ir_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  ADD CONSTRAINT `fk_team8_ir_employee` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_ir_preparer` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `team8_legal_cases`
--
ALTER TABLE `team8_legal_cases`
  ADD CONSTRAINT `fk_team8_legalcases_assignee` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_legalcases_contract` FOREIGN KEY (`contract_id`) REFERENCES `team8_contracts` (`id`),
  ADD CONSTRAINT `fk_team8_legalcases_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`);

--
-- Constraints for table `team8_legal_documents`
--
ALTER TABLE `team8_legal_documents`
  ADD CONSTRAINT `fk_team8_legaldocs_case` FOREIGN KEY (`case_id`) REFERENCES `team8_legal_cases` (`id`),
  ADD CONSTRAINT `fk_team8_legaldocs_document` FOREIGN KEY (`document_id`) REFERENCES `team8_documents` (`id`);

--
-- Constraints for table `team8_memorandums`
--
ALTER TABLE `team8_memorandums`
  ADD CONSTRAINT `fk_team8_memo_preparer` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `team8_memorandum_recipients`
--
ALTER TABLE `team8_memorandum_recipients`
  ADD CONSTRAINT `fk_team8_memo_recipient_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  ADD CONSTRAINT `fk_team8_memo_recipient_memo` FOREIGN KEY (`memorandum_id`) REFERENCES `team8_memorandums` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `team8_notice_to_explain`
--
ALTER TABLE `team8_notice_to_explain`
  ADD CONSTRAINT `fk_team8_nte_employee` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_nte_incident` FOREIGN KEY (`incident_report_id`) REFERENCES `team8_incident_reports` (`id`),
  ADD CONSTRAINT `fk_team8_nte_preparer` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `team8_records`
--
ALTER TABLE `team8_records`
  ADD CONSTRAINT `fk_team8_records_custodian` FOREIGN KEY (`custodian_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_records_disposal_authorizer` FOREIGN KEY (`disposal_authorized_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_records_disposal_requester` FOREIGN KEY (`disposal_requested_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_records_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `team8_retention_schedules` (`id`);

--
-- Constraints for table `team8_reservations`
--
ALTER TABLE `team8_reservations`
  ADD CONSTRAINT `fk_team8_reservations_cancel_requester` FOREIGN KEY (`cancellation_requested_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_reservations_cancel_reviewer` FOREIGN KEY (`cancellation_reviewed_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_reservations_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  ADD CONSTRAINT `fk_team8_reservations_facility` FOREIGN KEY (`facility_id`) REFERENCES `team8_facilities` (`id`),
  ADD CONSTRAINT `fk_team8_reservations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `team8_reservation_approvals`
--
ALTER TABLE `team8_reservation_approvals`
  ADD CONSTRAINT `fk_team8_resapproval_approver` FOREIGN KEY (`approver_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_resapproval_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `team8_reservations` (`id`);

--
-- Constraints for table `team8_reservation_cancellation_requests`
--
ALTER TABLE `team8_reservation_cancellation_requests`
  ADD CONSTRAINT `fk_team8_cancel_request_requester` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_team8_cancel_request_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `team8_reservations` (`id`),
  ADD CONSTRAINT `fk_team8_cancel_request_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`);

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
  ADD CONSTRAINT `fk_team8_visitors_logger` FOREIGN KEY (`logged_by`) REFERENCES `users` (`id`);

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
