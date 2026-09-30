-- =====================================================================
-- COMPREHENSIVE DEMO DATA SEED
-- Database: capstone_shared_db
-- Purpose : Showcase all modules with realistic interconnected data
-- Order   : Respects FK dependencies (parents before children)
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;
SET time_zone = '+08:00';  -- Philippine time

-- =====================================================================
-- 0. CLEANUP (safe re-run)
-- =====================================================================
DELETE FROM audit_logs;
DELETE FROM notifications;
DELETE FROM team8_compliance_report_documents;
DELETE FROM team8_compliance_reports;
DELETE FROM team8_compliance_checks;
DELETE FROM team8_explanations;
DELETE FROM team8_notice_to_explain;
DELETE FROM team8_incident_reports;
DELETE FROM team8_memorandum_recipients;
DELETE FROM team8_memorandums;
DELETE FROM team8_certificate_recipients;
DELETE FROM team8_certificates;
DELETE FROM team8_legal_documents;
DELETE FROM team8_legal_cases;
DELETE FROM team8_contract_approvals;
DELETE FROM team8_contract_history;
DELETE FROM team8_contract_parties;
DELETE FROM team8_contract_documents;
DELETE FROM team8_contracts;
DELETE FROM team8_document_versions;
DELETE FROM team8_documents;
DELETE FROM team8_reservation_approvals;
DELETE FROM team8_reservation_cancellation_requests;
DELETE FROM team8_reservation_equipment;
DELETE FROM team8_reservations;
DELETE FROM team8_visitors;
DELETE FROM team8_facility_maintenance_history;
DELETE FROM team8_equipment;
DELETE FROM team8_facilities;
DELETE FROM team8_facility_locations;
DELETE FROM team8_records;
DELETE FROM team8_parties;
DELETE FROM renewal_requests;
DELETE FROM team8_document_number_sequences;
DELETE FROM team8_hr_document_sequences;
DELETE FROM team8_login_throttle;
DELETE FROM user_roles;
DELETE FROM users;
DELETE FROM team8_retention_schedules;
DELETE FROM team8_document_categories;
DELETE FROM roles;
DELETE FROM departments;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- 1. DEPARTMENTS
-- =====================================================================
INSERT INTO departments (id, name) VALUES
    (1, 'Administration'),
    (2, 'Finance'),
    (3, 'Human Resources'),
    (4, 'Information Technology'),
    (5, 'Legal'),
    (6, 'Operations'),
    (7, 'Procurement'),
    (8, 'Facilities'),
    (9, 'Security'),
    (10, 'Marketing'),
    (11, 'Customer Service');

-- =====================================================================
-- 2. ROLES
-- =====================================================================
INSERT INTO roles (id, role_name) VALUES
    (1, 'admin'),
    (2, 'facilities_staff'),
    (3, 'front_desk'),
    (4, 'records_officer'),
    (5, 'legal_officer'),
    (6, 'employee');

-- =====================================================================
-- 3. USERS
-- Password hash below = "password" (bcrypt, cost 10)
-- =====================================================================
INSERT INTO users (id, department_id, full_name, email, password_hash) VALUES
    (1,  1, 'Dev Tester',        'dev.tester@example.local',        '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (2,  8, 'Facilities Fran',   'facilities@example.local',        '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (3,  3, 'Frontdesk Fred',    'frontdesk@example.local',         '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (4,  5, 'Legal Lena',        'legal@example.local',             '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (5,  3, 'Employee Ella',     'employee@example.local',          '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (6,  1, 'Records Rita',      'records@example.local',           '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (7,  2, 'Finance Felix',     'finance@example.local',           '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (8,  7, 'Procurement Pete',  'procurement@example.local',       '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (9,  4, 'IT Ivan',           'it.admin@example.local',          '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (10, 6, 'Operations Omar',   'operations@example.local',        '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (11, 3, 'HR Hannah',         'hr@example.local',                '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (12, 9, 'Security Sam',      'security@example.local',          '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om');

-- =====================================================================
-- 4. USER ROLES
-- =====================================================================
INSERT INTO user_roles (id, user_id, role_id) VALUES
    (1,  1,  1),   -- Dev Tester        -> admin
    (2,  2,  2),   -- Facilities Fran   -> facilities_staff
    (3,  3,  3),   -- Frontdesk Fred    -> front_desk
    (4,  4,  5),   -- Legal Lena        -> legal_officer
    (5,  5,  6),   -- Employee Ella     -> employee
    (6,  6,  4),   -- Records Rita      -> records_officer
    (7,  7,  6),   -- Finance Felix     -> employee
    (8,  8,  6),   -- Procurement Pete  -> employee
    (9,  9,  1),   -- IT Ivan           -> admin
    (10, 10, 6),   -- Operations Omar   -> employee
    (11, 11, 6),   -- HR Hannah         -> employee
    (12, 12, 6);   -- Security Sam      -> employee

-- =====================================================================
-- 5. DOCUMENT CATEGORIES
-- =====================================================================
INSERT INTO team8_document_categories (id, name) VALUES
    (1, 'Administrative'),
    (2, 'Contracts'),
    (3, 'Compliance'),
    (4, 'Finance'),
    (5, 'Inventory'),
    (6, 'Facilities'),
    (7, 'Human Resources'),
    (8, 'Others'),
    (9, 'Legal');

-- =====================================================================
-- 6. RETENTION SCHEDULES
-- =====================================================================
INSERT INTO team8_retention_schedules (id, record_type, retention_years) VALUES
    (1, 'HR Records', 5),
    (2, 'Financial Records', 7),
    (3, 'Legal Filings', 10);

-- =====================================================================
-- 7. DOCUMENT NUMBER SEQUENCES
-- =====================================================================
INSERT INTO team8_document_number_sequences (prefix, sequence_year, last_number) VALUES
    ('DOC',  2026, 3),
    ('CON',  2026, 3),
    ('MEMO', 2026, 2),
    ('CERT', 2026, 2),
    ('IR',   2026, 2),
    ('NTE',  2026, 1);

INSERT INTO team8_hr_document_sequences (prefix, document_year, next_number) VALUES
    ('IR',   2026, 3),
    ('NTE',  2026, 2),
    ('MEMO', 2026, 3),
    ('CERT', 2026, 3);

-- =====================================================================
-- 8. FACILITY LOCATIONS
-- =====================================================================
INSERT INTO team8_facility_locations (id, name) VALUES
    (1, 'Main Building - Ground Floor'),
    (2, 'Main Building - 2nd Floor'),
    (3, 'Annex Building'),
    (4, 'Outdoor Grounds');

-- =====================================================================
-- 9. FACILITIES
-- =====================================================================
INSERT INTO team8_facilities
    (id, name, location, facility_type, capacity, description, equipment_notes,
     maintenance_status, next_maintenance_date, status) VALUES
    (1, 'Executive Boardroom',   'Main Building - 2nd Floor', 'Meeting Room', 20,
        'Premium boardroom with panoramic view and video conferencing.',
        'Projector, smart TV, conference mic, whiteboard.',
        'operational', '2026-11-15', 'active'),
    (2, 'Training Room A',       'Main Building - Ground Floor', 'Training Room', 50,
        'Standard training room for workshops and seminars.',
        'Projector, 50 chairs, 10 tables, sound system.',
        'operational', '2026-10-20', 'active'),
    (3, 'Multi-Purpose Hall',    'Annex Building', 'Event Hall', 200,
        'Large hall for company events, town halls, and conferences.',
        'Stage, PA system, 200 chairs, 20 tables.',
        'maintenance_due', '2026-10-05', 'active'),
    (4, 'Sports Court',          'Outdoor Grounds', 'Outdoor Facility', 100,
        'Covered basketball/volleyball court.',
        'Bleachers, lighting, scoreboard.',
        'operational', '2026-12-01', 'active'),
    (5, 'Old Storage Room',      'Annex Building', 'Storage', 5,
        'Decommissioned storage room pending renovation.',
        'None', 'out_of_service', NULL, 'archived');

-- =====================================================================
-- 10. EQUIPMENT
-- =====================================================================
INSERT INTO team8_equipment (id, home_facility_id, name, quantity) VALUES
    (1, 1, 'Epson EB-2250U Projector',      2),
    (2, 1, 'Samsung 65" Smart TV',          1),
    (3, 1, 'Jabra Speak 750 Conference Mic',2),
    (4, 2, 'Portable Projector',            3),
    (5, 2, 'Wireless Microphone Set',       2),
    (6, 3, 'JBL EON715 PA Speaker',         4),
    (7, 3, 'Wireless Mic (Shure)',          4),
    (8, 4, 'Portable Scoreboard',           1),
    (9, 4, 'Volleyball Net Set',            2),
    (10, 2, 'Extension Cord (20m)',         10);

-- =====================================================================
-- 11. FACILITY MAINTENANCE HISTORY
-- =====================================================================
INSERT INTO team8_facility_maintenance_history
    (id, facility_id, performed_by, maintenance_date, notes) VALUES
    (1, 1, 2, '2026-08-15', 'Projector lamp replaced and AC serviced.'),
    (2, 3, 2, '2026-09-01', 'Stage lighting checked; one spotlight needs replacement.'),
    (3, 2, 2, '2026-09-10', 'Chairs re-upholstered; sound system rewired.'),
    (4, 4, 2, '2026-09-20', 'Court repainted; ring replacement scheduled.');

-- =====================================================================
-- 12. PARTIES (for contracts)
-- =====================================================================
INSERT INTO team8_parties
    (id, name, trade_name, registration_number, tin, type, contact_email,
     contact_phone, primary_contact, address, authorized_signatory_name,
     authorized_signatory_position) VALUES
    (1, 'Metro Pacific Solutions Inc.', 'MPSI', 'SEC-2019-00123', '009-123-456-000',
        'organization', 'contracts@mpsi.example', '+63-2-8123-4567',
        'Maria Santos', '12F Ayala Tower, Makati City',
        'Maria Santos', 'President & CEO'),
    (2, 'Bright Office Supplies Corp.', 'BOSC', 'SEC-2020-00456', '009-234-567-000',
        'organization', 'sales@bos.example', '+63-2-8234-5678',
        'Jose Reyes', '88 Ortigas Ave, Pasig City',
        'Jose Reyes', 'General Manager'),
    (3, 'Atty. Carlos Dizon', NULL, NULL, '123-456-789-000',
        'individual', 'carlos.dizon@law.example', '+63-917-555-0101',
        NULL, 'Unit 5B, Legaspi Village, Makati City',
        'Carlos Dizon', 'Legal Counsel'),
    (4, 'CleanPro Facility Services', 'CleanPro', 'SEC-2021-00789', '009-345-678-000',
        'organization', 'ops@cleanpro.example', '+63-2-8345-6789',
        'Aisha Cruz', '45 Quezon Ave, Quezon City',
        'Aisha Cruz', 'Operations Head');

-- =====================================================================
-- 13. CONTRACTS
-- =====================================================================
INSERT INTO team8_contracts
    (id, contract_number, owner_id, department_id, renewed_from_id, title,
     contract_type, description, start_date, end_date, renewal_date, amount,
     currency, payment_terms, payment_frequency, deposit_amount, financial_notes,
     notice_period_days, termination_date, termination_reason, rejection_reason,
     attachment_path, attachment_type, attachment_name, status) VALUES
    (1, 'CON-2026-0001', 8, 7, NULL, 'Annual Office Supplies Contract',
        'Supply Agreement', 'Supply of general office supplies for FY2026.',
        '2026-01-01', '2026-12-31', '2026-11-30', 850000.00,
        'PHP', 'Net 30', 'Quarterly', 50000.00, 'Includes delivery to all floors.',
        30, NULL, NULL, NULL,
        '/uploads/contracts/CON-2026-0001.pdf', 'pdf', 'CON-2026-0001.pdf', 'active'),

    (2, 'CON-2026-0002', 7, 2, NULL, 'IT Equipment Lease Agreement',
        'Lease', 'Lease of 25 laptops and 5 printers for 12 months.',
        '2026-02-01', '2027-01-31', '2026-12-31', 1200000.00,
        'PHP', 'Net 15', 'Monthly', 100000.00, 'Includes on-site support.',
        60, NULL, NULL, NULL,
        '/uploads/contracts/CON-2026-0002.pdf', 'pdf', 'CON-2026-0002.pdf', 'approval'),

    (3, 'CON-2026-0003', 4, 5, NULL, 'Retainer Agreement - Legal Counsel',
        'Professional Services', 'Monthly retainer for legal advisory services.',
        '2026-03-01', '2027-02-28', '2027-01-31', 600000.00,
        'PHP', 'Net 30', 'Monthly', NULL, 'Covers 40 hours/month.',
        30, NULL, NULL, NULL,
        '/uploads/contracts/CON-2026-0003.pdf', 'pdf', 'CON-2026-0003.pdf', 'active'),

    (4, 'CON-2026-0004', 2, 8, NULL, 'Janitorial Services Contract',
        'Service Agreement', 'Daily janitorial services for Main & Annex buildings.',
        '2026-04-01', '2027-03-31', '2027-02-28', 960000.00,
        'PHP', 'Net 15', 'Monthly', 80000.00, 'Includes consumables.',
        45, NULL, NULL, NULL,
        '/uploads/contracts/CON-2026-0004.pdf', 'pdf', 'CON-2026-0004.pdf', 'signing'),

    (5, 'CON-2026-0005', 1, 1, 3, 'Renewal: Legal Counsel Retainer',
        'Professional Services', 'Renewal of CON-2026-0003.',
        '2027-03-01', '2028-02-29', NULL, 660000.00,
        'PHP', 'Net 30', 'Monthly', NULL, '5% escalation from prior year.',
        30, NULL, NULL, NULL,
        '/uploads/contracts/CON-2026-0005.pdf', 'pdf', 'CON-2026-0005.pdf', 'draft');

-- =====================================================================
-- 14. CONTRACT PARTIES
-- =====================================================================
INSERT INTO team8_contract_parties (id, contract_id, party_id, role_in_contract) VALUES
    (1, 1, 2, 'Supplier'),
    (2, 2, 1, 'Lessor'),
    (3, 3, 3, 'Counsel'),
    (4, 4, 4, 'Service Provider'),
    (5, 5, 3, 'Counsel');

-- =====================================================================
-- 15. CONTRACT APPROVALS
-- =====================================================================
INSERT INTO team8_contract_approvals
    (id, contract_id, reviewer_id, approver_id, action, comment, acted_at) VALUES
    (1, 1, 4, NULL, 'reviewed', 'Terms acceptable; recommend approval.', '2025-12-15 10:00:00'),
    (2, 1, NULL, 1, 'approved', 'Approved for execution.', '2025-12-18 14:30:00'),
    (3, 2, 4, NULL, 'reviewed', 'Clarify liability clause.', '2026-01-20 09:15:00'),
    (4, 2, NULL, 1, 'rejected', 'Liability clause needs revision.', '2026-01-22 16:45:00'),
    (5, 3, 4, NULL, 'reviewed', 'Standard retainer; ok.', '2026-02-10 11:00:00'),
    (6, 3, NULL, 1, 'approved', 'Approved.', '2026-02-12 13:00:00'),
    (7, 4, 4, NULL, 'reviewed', 'Service scope clear.', '2026-03-15 15:20:00'),
    (8, 4, NULL, 1, 'approved', 'For signing.', '2026-03-18 10:00:00');

-- =====================================================================
-- 16. CONTRACT HISTORY (version snapshots)
-- =====================================================================
INSERT INTO team8_contract_history
    (id, contract_id, version_no, data_json, changed_by) VALUES
    (1, 1, 1, '{"status":"draft","amount":850000}', 8),
    (2, 1, 2, '{"status":"approval","amount":850000}', 4),
    (3, 1, 3, '{"status":"active","amount":850000}', 1),
    (4, 2, 1, '{"status":"draft","amount":1200000}', 7),
    (5, 2, 2, '{"status":"approval","amount":1200000}', 4),
    (6, 3, 1, '{"status":"draft","amount":600000}', 4),
    (7, 3, 2, '{"status":"active","amount":600000}', 1),
    (8, 4, 1, '{"status":"draft","amount":960000}', 2),
    (9, 4, 2, '{"status":"signing","amount":960000}', 1);

-- =====================================================================
-- 17. DOCUMENTS
-- =====================================================================
INSERT INTO team8_documents
    (id, category_id, document_type, department_id, owner_id, uploaded_by,
     title, file_path, current_version, status, review_reason,
     expiration_date) VALUES
    (1, 2, 'Contract', 7, 8, 8,
        'Annual Office Supplies Contract 2026', '/uploads/docs/DOC-2026-0001.pdf',
        1, 'approved', NULL, '2026-12-31'),
    (2, 2, 'Contract', 2, 7, 7,
        'IT Equipment Lease 2026', '/uploads/docs/DOC-2026-0002.pdf',
        1, 'pending', 'Awaiting legal review.', '2027-01-31'),
    (3, 3, 'Compliance', 1, 6, 6,
        'Mayor''s Business Permit 2026', '/uploads/docs/DOC-2026-0003.pdf',
        1, 'approved', NULL, '2026-12-31'),
    (4, 4, 'Financial', 2, 7, 7,
        'Q3 2026 Financial Statement', '/uploads/docs/DOC-2026-0004.pdf',
        1, 'pending', 'For CFO signature.', NULL),
    (5, 7, 'HR', 3, 11, 11,
        'Employee Handbook v3', '/uploads/docs/DOC-2026-0005.pdf',
        1, 'approved', NULL, NULL),
    (6, 6, 'Facilities', 8, 2, 2,
        'Fire Safety Inspection Certificate 2026', '/uploads/docs/DOC-2026-0006.pdf',
        1, 'approved', NULL, '2027-01-15'),
    (7, 1, 'Administrative', 1, 6, 6,
        'Board Resolution No. 2026-012', '/uploads/docs/DOC-2026-0007.pdf',
        1, 'archived', NULL, NULL);

-- =====================================================================
-- 18. DOCUMENT VERSIONS
-- =====================================================================
INSERT INTO team8_document_versions
    (id, document_id, version_no, file_path, file_size, checksum) VALUES
    (1, 1, 1, '/uploads/docs/DOC-2026-0001_v1.pdf', 245000, 'a1b2c3d4e5f6'),
    (2, 2, 1, '/uploads/docs/DOC-2026-0002_v1.pdf', 198000, 'b2c3d4e5f6a7'),
    (3, 3, 1, '/uploads/docs/DOC-2026-0003_v1.pdf', 312000, 'c3d4e5f6a7b8'),
    (4, 5, 1, '/uploads/docs/DOC-2026-0005_v1.pdf', 780000, 'd4e5f6a7b8c9'),
    (5, 6, 1, '/uploads/docs/DOC-2026-0006_v1.pdf', 156000, 'e5f6a7b8c9d0');

-- =====================================================================
-- 19. CONTRACT DOCUMENTS (link)
-- =====================================================================
INSERT INTO team8_contract_documents (id, contract_id, document_id) VALUES
    (1, 1, 1),
    (2, 2, 2);

-- =====================================================================
-- 20. RECORDS (retention)
-- =====================================================================
INSERT INTO team8_records
    (id, entity_type, entity_id, schedule_id, retention_basis, retention_years,
     retention_start_date, custodian_id, disposition_date, status,
     archived_at, archive_reason, disposed_at, disposal_reason,
     disposal_requested_by, disposal_requested_at,
     disposal_authorized_by, disposal_authorized_at) VALUES
    (1, 'document', 1, 2, 'Finance retention policy', 7,
        '2026-01-01', 6, '2033-01-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
    (2, 'contract', 3, 3, 'Legal retention policy', 10,
        '2026-03-01', 6, '2036-03-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
    (3, 'document', 7, 1, 'Admin records policy', 5,
        '2020-01-01', 6, '2025-01-01', 'archived',
        '2025-06-01 09:00:00', 'Retention period elapsed; pending disposal.', NULL, NULL, NULL, NULL, NULL, NULL),
    (4, 'legal_case', 1, 3, 'Legal filings retention', 10,
        '2026-05-01', 6, '2036-05-01', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

-- =====================================================================
-- 21. COMPLIANCE CHECKS
-- =====================================================================
INSERT INTO team8_compliance_checks
    (id, record_id, checked_by, check_date, result, notes) VALUES
    (1, 1, 6, '2026-09-01', 'compliant',    'All financial docs complete.'),
    (2, 2, 6, '2026-09-05', 'compliant',    'Legal retainer valid.'),
    (3, 3, 6, '2026-09-10', 'non_compliant','Missing board resolution for disposal.'),
    (4, 4, 6, '2026-09-15', 'pending',      'Awaiting court documents.');

-- =====================================================================
-- 22. COMPLIANCE REPORTS
-- =====================================================================
INSERT INTO team8_compliance_reports
    (id, report_type, department_id, date_from, date_to, executive_summary,
     key_findings, check_details, generated_by, status, approved_by,
     approved_at, document_id, recipient_emails, email_sent_at) VALUES
    (1, 'Quarterly Compliance Report', 1, '2026-07-01', '2026-09-30',
        'Overall compliance remains satisfactory with minor gaps.',
        'One non-compliant record (ID 3) flagged for board resolution.',
        '[{"check_id":1,"result":"compliant"},{"check_id":2,"result":"compliant"},{"check_id":3,"result":"non_compliant"},{"check_id":4,"result":"pending"}]',
        6, 'approved', 1, '2026-09-25 10:00:00', 3,
        'admin@example.local,legal@example.local', '2026-09-25 10:05:00');

INSERT INTO team8_compliance_report_documents (compliance_report_id, document_id) VALUES
    (1, 3),
    (1, 6);

-- =====================================================================
-- 23. INCIDENT REPORTS
-- =====================================================================
INSERT INTO team8_incident_reports
    (id, document_number, employee_id, prepared_by, department_id, status,
     rejection_reason, incident_date, incident_time, incident_location,
     incident_type, description, witness, attachment_path, current_version) VALUES
    (1, 'IR-2026-0001', 5, 11, 3, 'approved', NULL,
        '2026-09-05', '14:30:00', 'Main Building - 2nd Floor',
        'Tardiness', 'Employee arrived 45 minutes late without prior notice.',
        'Frontdesk Fred', '/uploads/ir/IR-2026-0001.pdf', 1),
    (2, 'IR-2026-0002', 10, 11, 6, 'pending', NULL,
        '2026-09-18', '10:15:00', 'Annex Building',
        'Policy Violation', 'Unauthorized use of company vehicle.',
        'Security Sam', NULL, 1);

-- =====================================================================
-- 24. NOTICE TO EXPLAIN
-- =====================================================================
INSERT INTO team8_notice_to_explain
    (id, document_number, incident_report_id, employee_id, prepared_by,
     status, rejection_reason, deadline, remarks, current_version) VALUES
    (1, 'NTE-2026-0001', 1, 5, 11, 'approved', NULL,
        '2026-09-20', 'Please explain in writing within 5 days.', 1);

-- =====================================================================
-- 25. EXPLANATIONS
-- =====================================================================
INSERT INTO team8_explanations
    (id, nte_id, employee_id, explanation_text, attachment_path, status,
     admin_remarks, reviewed_by, reviewed_at) VALUES
    (1, 1, 5,
        'I apologize for the tardiness. I had a medical emergency that morning and informed my supervisor verbally.',
        '/uploads/expl/EXP-2026-0001.pdf', 'accepted',
        'Verbal notice confirmed with supervisor; explanation accepted.',
        11, '2026-09-22 09:00:00');

-- =====================================================================
-- 26. MEMORANDUMS
-- =====================================================================
INSERT INTO team8_memorandums
    (id, document_number, kind, title, recipients, content, remarks,
     prepared_by, status, rejection_reason, current_version) VALUES
    (1, 'MEMO-2026-0001', 'memorandum', 'Implementation of New Attendance Policy',
        'All Departments',
        'Effective October 1, 2026, all employees must use the biometric system for time-in and time-out.',
        'Please cascade to all staff.', 11, 'approved', NULL, 1),
    (2, 'MEMO-2026-0002', 'memorandum', 'Annual Fire Drill Schedule',
        'All Departments',
        'The annual fire drill will be conducted on November 15, 2026 at 9:00 AM.',
        'Coordinate with Facilities.', 2, 'draft', NULL, 1);

INSERT INTO team8_memorandum_recipients (id, memorandum_id, recipient_type, department_id) VALUES
    (1, 1, 'all_departments', NULL),
    (2, 2, 'department', 8),
    (3, 2, 'department', 6);

-- =====================================================================
-- 27. CERTIFICATES
-- =====================================================================
INSERT INTO team8_certificates
    (id, document_number, certificate_type, employee_id, prepared_by,
     details, status, rejection_reason, current_version) VALUES
    (1, 'CERT-2026-0001', 'Certificate of Employment', 5, 11,
        'This certifies that Employee Ella has been employed since 2020.',
        'approved', NULL, 1),
    (2, 'CERT-2026-0002', 'Certificate of Compensation', 10, 7,
        'This certifies the monthly compensation of Operations Omar.',
        'pending', NULL, 1);

INSERT INTO team8_certificate_recipients (id, certificate_id, employee_id) VALUES
    (1, 1, 5),
    (2, 2, 10);

-- =====================================================================
-- 28. LEGAL CASES
-- =====================================================================
INSERT INTO team8_legal_cases
    (id, assigned_to, contract_id, title, subject, department_id, status,
     filed_date, deadline, closed_at) VALUES
    (1, 4, 2, 'MPSI vs. Company - Lease Dispute', 'Contract interpretation',
        2, 'open', '2026-05-01', '2026-12-31', NULL),
    (2, 4, NULL, 'Labor Case - Former Employee', 'Illegal dismissal claim',
        3, 'open', '2026-07-15', '2026-11-30', NULL),
    (3, 4, 3, 'Retainer Agreement Review', 'Contract compliance',
        5, 'closed', '2026-03-01', '2026-04-01', '2026-04-05 15:00:00');

INSERT INTO team8_legal_documents (id, case_id, document_id, description) VALUES
    (1, 1, 2, 'Lease contract copy'),
    (2, 3, 3, 'Retainer agreement');

-- =====================================================================
-- 29. RESERVATIONS
-- =====================================================================
INSERT INTO team8_reservations
    (id, facility_id, user_id, start_time, end_time, status, department_id,
     department, key_person, expected_participants, quantity, event_category,
     description, expected_return_date, remarks, schedule, requirements) VALUES
    (1, 1, 5, '2026-10-05 09:00:00', '2026-10-05 11:00:00', 'approved', 3,
        'Human Resources', 'HR Hannah', 15, NULL, 'Meeting',
        'Q4 HR Planning Session', NULL, 'Need projector.', '2026-10-05 09:00:00',
        'Projector, whiteboard markers'),
    (2, 3, 10, '2026-10-20 13:00:00', '2026-10-20 17:00:00', 'pending', 6,
        'Operations', 'Operations Omar', 120, NULL, 'Company Event',
        'Annual Town Hall', NULL, 'Need PA system.', '2026-10-20 13:00:00',
        'PA system, microphones, stage'),
    (3, 2, 7, '2026-10-12 08:00:00', '2026-10-12 12:00:00', 'approved', 2,
        'Finance', 'Finance Felix', 30, NULL, 'Training',
        'Financial Reporting Workshop', NULL, NULL, '2026-10-12 08:00:00',
        'Projector, 30 chairs'),
    (4, 4, 12, '2026-11-05 15:00:00', '2026-11-05 18:00:00', 'cancelled', 9,
        'Security', 'Security Sam', 20, NULL, 'Sports',
        'Inter-department Basketball', NULL, 'Cancelled due to weather.',
        '2026-11-05 15:00:00', 'Scoreboard, volleyball net');

INSERT INTO team8_reservation_equipment (id, reservation_id, equipment_id, quantity) VALUES
    (1, 1, 1, 1),
    (2, 1, 3, 1),
    (3, 2, 6, 2),
    (4, 2, 7, 2),
    (5, 3, 4, 1),
    (6, 4, 8, 1),
    (7, 4, 9, 1);

INSERT INTO team8_reservation_approvals
    (id, reservation_id, approver_id, step_order, status, remarks, decided_at) VALUES
    (1, 1, 2, 1, 'approved', 'Facility available.', '2026-09-28 10:00:00'),
    (2, 2, 2, 1, 'pending', NULL, NULL),
    (3, 3, 2, 1, 'approved', 'Approved.', '2026-09-30 14:00:00'),
    (4, 4, 2, 1, 'rejected', 'Cancelled by requester.', '2026-10-30 09:00:00');

INSERT INTO team8_reservation_cancellation_requests
    (id, reservation_id, requested_by, reason, status, requested_at,
     reviewed_by, reviewed_at, admin_remark) VALUES
    (1, 4, 12, 'Weather forecast predicts heavy rain.', 'approved',
        '2026-10-29 16:00:00', 2, '2026-10-30 09:00:00', 'Approved cancellation.');

-- =====================================================================
-- 30. VISITORS
-- =====================================================================
INSERT INTO team8_visitors
    (id, full_name, visitor_type, contact, company, person_to_visit,
     purpose, scheduled_date, status, check_in_time, check_out_time, logged_by) VALUES
    (1, 'Juan Dela Cruz', 'Supplier', '+63-917-111-2222', 'Bright Office Supplies',
        'Procurement Pete', 'Deliver office supplies', '2026-10-01 09:00:00',
        'checked_out', '2026-10-01 09:05:00', '2026-10-01 10:30:00', 3),
    (2, 'Maria Clara', 'Client', '+63-917-333-4444', 'MPSI',
        'Legal Lena', 'Contract signing meeting', '2026-10-02 14:00:00',
        'checked_in', '2026-10-02 13:55:00', NULL, 3),
    (3, 'Pedro Penduko', 'Applicant', '+63-917-555-6666', NULL,
        'HR Hannah', 'Job interview', '2026-10-03 10:00:00',
        'scheduled', NULL, NULL, 3),
    (4, 'Ana Reyes', 'Government', '+63-917-777-8888', 'BIR',
        'Finance Felix', 'Tax audit', '2026-10-04 08:30:00',
        'scheduled', NULL, NULL, 3);

-- =====================================================================
-- 31. RENEWAL REQUESTS (retention renewal prerequisites)
-- =====================================================================
INSERT INTO renewal_requests
    (id, main_document_type, status, initiated_by, notes) VALUES
    (1, 'Mayor''s Business Permit', 'in_progress', 6,
        'Renewal for 2027. Gathering prerequisite documents.'),
    (2, 'Sanitary Permit', 'in_progress', 6,
        'Awaiting water potability test result.'),
    (3, 'Fire Safety Inspection Certificate', 'completed', 6,
        'All prerequisites submitted and verified.');

-- =====================================================================
-- 32. NOTIFICATIONS
-- =====================================================================
INSERT INTO notifications (id, user_id, message, target_url, status) VALUES
    (1, 1, 'Contract CON-2026-0002 is pending your approval.',
        '/contracts/2', 'unread'),
    (2, 4, 'New legal case assigned: MPSI vs. Company.',
        '/legal-cases/1', 'unread'),
    (3, 2, 'Reservation #2 for Multi-Purpose Hall is pending approval.',
        '/reservations/2', 'read'),
    (4, 6, 'Compliance report for Q3 2026 has been approved.',
        '/compliance-reports/1', 'read'),
    (5, 5, 'Your explanation for NTE-2026-0001 has been accepted.',
        '/explanations/1', 'read'),
    (6, 11, 'Incident Report IR-2026-0002 is pending review.',
        '/incident-reports/2', 'unread');

-- =====================================================================
-- 33. AUDIT LOGS
-- =====================================================================
INSERT INTO audit_logs
    (id, user_id, entity_type, entity_id, action, old_value, new_value) VALUES
    (1, 1, 'contract', 1, 'status_change', '{"status":"approval"}', '{"status":"active"}'),
    (2, 4, 'legal_case', 1, 'create', NULL, '{"title":"MPSI vs. Company"}'),
    (3, 2, 'reservation', 1, 'approve', '{"status":"pending"}', '{"status":"approved"}'),
    (4, 11, 'explanation', 1, 'review', '{"status":"pending"}', '{"status":"accepted"}'),
    (5, 1, 'document', 3, 'approve', '{"status":"pending"}', '{"status":"approved"}'),
    (6, 6, 'compliance_report', 1, 'approve', '{"status":"draft"}', '{"status":"approved"}');

-- =====================================================================
-- 34. LOGIN THROTTLE (demo of locked account)
-- =====================================================================
INSERT INTO team8_login_throttle (identifier, attempts, locked_until) VALUES
    ('attacker@example.local', 5, '2026-10-10 12:00:00');

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- END OF SEED
-- =====================================================================