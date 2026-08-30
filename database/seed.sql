-- =========================================================
-- database/seed.sql
-- Local development test data. Run AFTER schema.sql.
-- Re-running this file is safe: the fixed IDs below are updated in place.
-- =========================================================


INSERT INTO departments (id, name) VALUES
    (1, 'Facilities & Administration'),
    (2, 'Legal'),
    (3, 'General Staff')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO users (id, department_id, full_name, email, password_hash) VALUES
    (1, 1, 'Dev Tester',      'dev.tester@example.local',   '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (2, 1, 'Facilities Fran', 'facilities@example.local',   '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (3, 3, 'Frontdesk Fred',  'frontdesk@example.local',    '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (4, 2, 'Legal Lena',      'legal@example.local',        '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (5, 3, 'Employee Ella',   'employee@example.local',     '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (6, 1, 'Records Rita',    'records@example.local',      '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om')
ON DUPLICATE KEY UPDATE department_id = VALUES(department_id), full_name = VALUES(full_name), email = VALUES(email), password_hash = VALUES(password_hash);

INSERT INTO roles (id, role_name) VALUES
    (1, 'admin'),
    (2, 'facilities_staff'),
    (3, 'front_desk'),
    (4, 'records_officer'),
    (5, 'legal_officer'),
    (6, 'employee')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

INSERT INTO user_roles (id, user_id, role_id) VALUES
    (1, 1, 1), -- Dev Tester -> admin
    (2, 2, 2), -- Facilities Fran -> facilities_staff
    (3, 3, 3), -- Frontdesk Fred -> front_desk
    (4, 4, 5), -- Legal Lena -> legal_officer
    (5, 5, 6), -- Employee Ella -> employee
    (6, 6, 4)  -- Records Rita -> records_officer
ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), role_id = VALUES(role_id);


INSERT INTO team8_document_categories (id, name) VALUES
    (1, 'Administrative'),
    (2, 'Contracts'),
    (3, 'Compliance'),
    (4, 'Finance'),
    (5, 'Inventory'),
    (6, 'Facilities'),
    (7, 'Human Resources'),
    (8, 'Others'),
    (9, 'Legal'),
    (10, 'HR')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO team8_retention_schedules (id, record_type, retention_years) VALUES
    (1, 'HR Records', 5),
    (2, 'Financial Records', 7),
    (3, 'Legal Filings', 10)
ON DUPLICATE KEY UPDATE record_type = VALUES(record_type);

-- =========================================================
-- FACILITIES, EQUIPMENT, AND RESERVATIONS
-- =========================================================
INSERT INTO team8_facilities (id, name, location, facility_type, capacity, description, equipment_notes, maintenance_status, next_maintenance_date, status) VALUES
    (101, 'Main Conference Room', 'Administration Building, Floor 2', 'Conference Room', 24, 'Presentation-ready meeting room.', 'Projector, whiteboard, video conference bar.', 'operational', DATE_ADD(CURDATE(), INTERVAL 45 DAY), 'active'),
    (102, 'Training Hall', 'Administration Building, Ground Floor', 'Training Room', 80, 'Large room for orientations and workshops.', 'PA system, projector, 80 chairs.', 'operational', DATE_ADD(CURDATE(), INTERVAL 60 DAY), 'active'),
    (103, 'Executive Meeting Room', 'Administration Building, Floor 3', 'Meeting Room', 12, 'Private executive meeting space.', 'Display, whiteboard.', 'maintenance', DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'active'),
    (104, 'Archive Room', 'Records Building, Floor 1', 'Storage', 10, 'Controlled-access paper records storage.', 'Shelving and document boxes.', 'operational', DATE_ADD(CURDATE(), INTERVAL 120 DAY), 'archived')
ON DUPLICATE KEY UPDATE name = VALUES(name), location = VALUES(location), facility_type = VALUES(facility_type), capacity = VALUES(capacity), description = VALUES(description), equipment_notes = VALUES(equipment_notes), maintenance_status = VALUES(maintenance_status), next_maintenance_date = VALUES(next_maintenance_date), status = VALUES(status);

INSERT INTO team8_equipment (id, home_facility_id, name, quantity) VALUES
    (101, 101, 'HD Projector', 2),
    (102, 101, 'Wireless Microphone', 6),
    (103, 102, 'Folding Chair', 80),
    (104, 102, 'Portable Whiteboard', 3)
ON DUPLICATE KEY UPDATE home_facility_id = VALUES(home_facility_id), name = VALUES(name), quantity = VALUES(quantity);

INSERT INTO team8_facility_maintenance_history (id, facility_id, performed_by, maintenance_date, notes) VALUES
    (101, 103, 2, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Air-conditioning inspection in progress.'),
    (102, 101, 2, DATE_SUB(CURDATE(), INTERVAL 30 DAY), 'Projector lamp and cables checked.')
ON DUPLICATE KEY UPDATE facility_id = VALUES(facility_id), performed_by = VALUES(performed_by), maintenance_date = VALUES(maintenance_date), notes = VALUES(notes);

INSERT INTO team8_reservations (id, facility_id, user_id, start_time, end_time, status, department, key_person, expected_participants, quantity, event_category, description, expected_return_date, remarks, schedule, requirements, archived_at, cancellation_reason, cancellation_requested_by, cancellation_requested_at, cancellation_reviewed_by, cancellation_reviewed_at, cancellation_decision) VALUES
    (101, 101, 2, DATE_ADD(CURDATE(), INTERVAL 2 DAY) + INTERVAL 9 HOUR, DATE_ADD(CURDATE(), INTERVAL 2 DAY) + INTERVAL 11 HOUR, 'approved', 'Facilities & Administration', 'Facilities Fran', 18, 1, 'Meeting', 'Weekly facilities coordination meeting.', NULL, 'Approved for the scheduled slot.', DATE_ADD(CURDATE(), INTERVAL 2 DAY) + INTERVAL 9 HOUR, 'Projector and whiteboard', NULL, NULL, NULL, NULL, 1, NOW(), 'approved'),
    (102, 102, 5, DATE_ADD(CURDATE(), INTERVAL 5 DAY) + INTERVAL 13 HOUR, DATE_ADD(CURDATE(), INTERVAL 5 DAY) + INTERVAL 16 HOUR, 'pending', 'General Staff', 'Employee Ella', 55, 1, 'Training', 'New employee orientation session.', NULL, 'Awaiting facilities approval.', DATE_ADD(CURDATE(), INTERVAL 5 DAY) + INTERVAL 13 HOUR, 'PA system and 60 chairs', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
    (103, 101, 3, DATE_SUB(CURDATE(), INTERVAL 3 DAY) + INTERVAL 10 HOUR, DATE_SUB(CURDATE(), INTERVAL 3 DAY) + INTERVAL 12 HOUR, 'completed', 'General Staff', 'Frontdesk Fred', 10, 1, 'Briefing', 'Completed visitor reception briefing.', NULL, 'Completed successfully.', DATE_SUB(CURDATE(), INTERVAL 3 DAY) + INTERVAL 10 HOUR, 'Whiteboard', DATE_SUB(NOW(), INTERVAL 3 DAY), NULL, NULL, NULL, NULL, NULL, NULL),
    (104, 102, 5, DATE_ADD(CURDATE(), INTERVAL 8 DAY) + INTERVAL 9 HOUR, DATE_ADD(CURDATE(), INTERVAL 8 DAY) + INTERVAL 11 HOUR, 'cancellation_pending', 'General Staff', 'Employee Ella', 30, 1, 'Workshop', 'Workshop moved to an external venue.', NULL, 'Cancellation needs approval.', DATE_ADD(CURDATE(), INTERVAL 8 DAY) + INTERVAL 9 HOUR, '30 chairs', NULL, 'Venue is no longer required.', 5, NOW(), NULL, NULL, NULL)
ON DUPLICATE KEY UPDATE facility_id = VALUES(facility_id), user_id = VALUES(user_id), start_time = VALUES(start_time), end_time = VALUES(end_time), status = VALUES(status), department = VALUES(department), key_person = VALUES(key_person), expected_participants = VALUES(expected_participants), quantity = VALUES(quantity), event_category = VALUES(event_category), description = VALUES(description), remarks = VALUES(remarks), schedule = VALUES(schedule), requirements = VALUES(requirements), archived_at = VALUES(archived_at), cancellation_reason = VALUES(cancellation_reason), cancellation_requested_by = VALUES(cancellation_requested_by), cancellation_requested_at = VALUES(cancellation_requested_at), cancellation_reviewed_by = VALUES(cancellation_reviewed_by), cancellation_reviewed_at = VALUES(cancellation_reviewed_at), cancellation_decision = VALUES(cancellation_decision);

INSERT INTO team8_reservation_equipment (id, reservation_id, equipment_id, quantity) VALUES
    (101, 101, 101, 1), (102, 102, 102, 2), (103, 102, 103, 60)
ON DUPLICATE KEY UPDATE reservation_id = VALUES(reservation_id), equipment_id = VALUES(equipment_id), quantity = VALUES(quantity);

INSERT INTO team8_reservation_approvals (id, reservation_id, approver_id, step_order, status, remarks, decided_at) VALUES
    (101, 101, 1, 1, 'approved', 'Schedule and capacity verified.', NOW()),
    (102, 102, 1, 1, 'pending', NULL, NULL)
ON DUPLICATE KEY UPDATE reservation_id = VALUES(reservation_id), approver_id = VALUES(approver_id), step_order = VALUES(step_order), status = VALUES(status), remarks = VALUES(remarks), decided_at = VALUES(decided_at);

INSERT INTO team8_reservation_cancellation_requests (id, reservation_id, requested_by, reason, status, requested_at, reviewed_by, reviewed_at, admin_remark) VALUES
    (101, 104, 5, 'Venue is no longer required.', 'pending', NOW(), NULL, NULL, NULL)
ON DUPLICATE KEY UPDATE reservation_id = VALUES(reservation_id), requested_by = VALUES(requested_by), reason = VALUES(reason), status = VALUES(status), requested_at = VALUES(requested_at), reviewed_by = VALUES(reviewed_by), reviewed_at = VALUES(reviewed_at), admin_remark = VALUES(admin_remark);

-- =========================================================
-- VISITORS AND DOCUMENTS
-- =========================================================
INSERT INTO team8_visitors (id, full_name, visitor_type, contact, company, person_to_visit, purpose, scheduled_date, status, check_in_time, check_out_time, logged_by) VALUES
    (101, 'Maria Santos', 'Vendor', '09171234567', 'OfficePro Supplies', 'Facilities Fran', 'Quarterly supplier meeting', DATE_ADD(CURDATE(), INTERVAL 1 DAY) + INTERVAL 10 HOUR, 'scheduled', NULL, NULL, 3),
    (102, 'John Lim', 'Applicant', '09179876543', 'N/A', 'Dev Tester', 'Administrative assistant interview', DATE_ADD(CURDATE(), INTERVAL 2 HOUR), 'checked_in', NOW(), NULL, 3),
    (103, 'Grace Tan', 'Client', '09175551234', 'Tan Consulting', 'Legal Lena', 'Contract review', DATE_SUB(CURDATE(), INTERVAL 1 DAY) + INTERVAL 14 HOUR, 'checked_out', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY) + INTERVAL 2 HOUR, 3),
    (104, 'Leo Cruz', 'Guest', '09176667890', 'N/A', 'Employee Ella', 'Campus tour', DATE_SUB(CURDATE(), INTERVAL 2 DAY) + INTERVAL 9 HOUR, 'cancelled', NULL, NULL, 3)
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), visitor_type = VALUES(visitor_type), contact = VALUES(contact), company = VALUES(company), person_to_visit = VALUES(person_to_visit), purpose = VALUES(purpose), scheduled_date = VALUES(scheduled_date), status = VALUES(status), check_in_time = VALUES(check_in_time), check_out_time = VALUES(check_out_time), logged_by = VALUES(logged_by);

INSERT INTO team8_documents (id, category_id, document_type, department_id, owner_id, uploaded_by, title, file_path, current_version, status, expiration_date, deleted_at) VALUES
    (101, 6, 'Facility Plan', 1, 2, 2, 'Facilities Preventive Maintenance Plan', 'documents/files_v1_d6e61835.docx', 2, 'approved', DATE_ADD(CURDATE(), INTERVAL 180 DAY), NULL),
    (102, 2, 'Service Agreement', 2, 4, 4, 'OfficePro Service Agreement', 'documents/files_v1_d6e61835.docx', 1, 'pending', DATE_ADD(CURDATE(), INTERVAL 90 DAY), NULL),
    (103, 3, 'Compliance Checklist', 1, 6, 6, 'Annual Records Compliance Checklist', 'documents/files_v1_d6e61835.docx', 1, 'returned_for_revision', DATE_ADD(CURDATE(), INTERVAL 20 DAY), NULL),
    (104, 9, 'Case File', 2, 4, 4, 'Supplier Dispute Supporting File', 'documents/files_v1_d6e61835.docx', 1, 'approved', NULL, NULL)
ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), document_type = VALUES(document_type), department_id = VALUES(department_id), owner_id = VALUES(owner_id), uploaded_by = VALUES(uploaded_by), title = VALUES(title), file_path = VALUES(file_path), current_version = VALUES(current_version), status = VALUES(status), expiration_date = VALUES(expiration_date), deleted_at = VALUES(deleted_at);

INSERT INTO team8_document_versions (id, document_id, version_no, file_path, file_size, checksum) VALUES
    (101, 101, 1, 'documents/files_v1_d6e61835.docx', 0, NULL),
    (102, 101, 2, 'documents/files_v1_d6e61835.docx', 0, NULL),
    (103, 102, 1, 'documents/files_v1_d6e61835.docx', 0, NULL),
    (104, 103, 1, 'documents/files_v1_d6e61835.docx', 0, NULL),
    (105, 104, 1, 'documents/files_v1_d6e61835.docx', 0, NULL)
ON DUPLICATE KEY UPDATE document_id = VALUES(document_id), version_no = VALUES(version_no), file_path = VALUES(file_path), file_size = VALUES(file_size), checksum = VALUES(checksum);

-- =========================================================
-- RETENTION, CONTRACTS, AND LEGAL
-- =========================================================
INSERT INTO team8_records (id, document_id, schedule_id, custodian_id, disposition_date, status, archived_at, archive_reason, disposed_at, disposal_reason, deleted_at) VALUES
    (101, 101, 1, 6, DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'active', NULL, NULL, NULL, NULL, NULL),
    (102, 103, 1, 6, DATE_ADD(CURDATE(), INTERVAL 365 DAY), 'archived', DATE_SUB(NOW(), INTERVAL 10 DAY), 'Superseded checklist retained in archive.', NULL, NULL, DATE_SUB(NOW(), INTERVAL 10 DAY)),
    (103, 104, 3, 4, DATE_SUB(CURDATE(), INTERVAL 30 DAY), 'for_disposal', NULL, NULL, NULL, 'Retention period reached; awaiting disposal approval.', NULL),
    (104, 102, 2, 6, DATE_SUB(CURDATE(), INTERVAL 90 DAY), 'disposed', NULL, NULL, DATE_SUB(NOW(), INTERVAL 5 DAY), 'Disposed after approved retention review.', NULL)
ON DUPLICATE KEY UPDATE document_id = VALUES(document_id), schedule_id = VALUES(schedule_id), custodian_id = VALUES(custodian_id), disposition_date = VALUES(disposition_date), status = VALUES(status), archived_at = VALUES(archived_at), archive_reason = VALUES(archive_reason), disposed_at = VALUES(disposed_at), disposal_reason = VALUES(disposal_reason), deleted_at = VALUES(deleted_at);

INSERT INTO team8_compliance_checks (id, record_id, checked_by, check_date, result, notes) VALUES
    (101, 101, 6, CURDATE(), 'compliant', 'Record metadata and retention date verified.'),
    (102, 102, 6, DATE_SUB(CURDATE(), INTERVAL 7 DAY), 'needs_review', 'Archive location requires a shelf-label update.'),
    (103, 103, 4, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'non_compliant', 'Disposal approval is still pending.')
ON DUPLICATE KEY UPDATE record_id = VALUES(record_id), checked_by = VALUES(checked_by), check_date = VALUES(check_date), result = VALUES(result), notes = VALUES(notes);

INSERT INTO team8_contracts (id, owner_id, department_id, renewed_from_id, title, start_date, end_date, renewal_date, amount, status, deleted_at) VALUES
    (101, 4, 2, NULL, 'OfficePro Annual Supply Agreement', DATE_SUB(CURDATE(), INTERVAL 10 MONTH), DATE_ADD(CURDATE(), INTERVAL 20 DAY), DATE_ADD(CURDATE(), INTERVAL 10 DAY), 250000.00, 'expiring_soon', NULL),
    (102, 2, 1, NULL, 'Elevator Maintenance Contract', DATE_SUB(CURDATE(), INTERVAL 2 MONTH), DATE_ADD(CURDATE(), INTERVAL 10 MONTH), DATE_ADD(CURDATE(), INTERVAL 9 MONTH), 180000.00, 'active', NULL),
    (103, 4, 2, 101, 'OfficePro Annual Supply Agreement Renewal', DATE_ADD(CURDATE(), INTERVAL 21 DAY), DATE_ADD(CURDATE(), INTERVAL 385 DAY), DATE_ADD(CURDATE(), INTERVAL 355 DAY), 275000.00, 'draft', NULL)
ON DUPLICATE KEY UPDATE owner_id = VALUES(owner_id), department_id = VALUES(department_id), renewed_from_id = VALUES(renewed_from_id), title = VALUES(title), start_date = VALUES(start_date), end_date = VALUES(end_date), renewal_date = VALUES(renewal_date), amount = VALUES(amount), status = VALUES(status), deleted_at = VALUES(deleted_at);

INSERT INTO team8_parties (id, name, type, contact_email, contact_phone) VALUES
    (101, 'OfficePro Supplies Inc.', 'organization', 'contracts@officepro.example', '02-8123-4567'),
    (102, 'MetroLift Services', 'organization', 'service@metrolift.example', '02-8765-4321')
ON DUPLICATE KEY UPDATE name = VALUES(name), type = VALUES(type), contact_email = VALUES(contact_email), contact_phone = VALUES(contact_phone);

INSERT INTO team8_contract_parties (id, contract_id, party_id, role_in_contract) VALUES
    (101, 101, 101, 'vendor'), (102, 102, 102, 'vendor'), (103, 103, 101, 'vendor')
ON DUPLICATE KEY UPDATE contract_id = VALUES(contract_id), party_id = VALUES(party_id), role_in_contract = VALUES(role_in_contract);

INSERT INTO team8_contract_documents (id, contract_id, document_id) VALUES
    (101, 101, 102), (102, 101, 104), (103, 102, 101)
ON DUPLICATE KEY UPDATE contract_id = VALUES(contract_id), document_id = VALUES(document_id);

INSERT INTO team8_contract_history (id, contract_id, version_no, data_json, changed_by) VALUES
    (101, 101, 1, '{"status":"active","note":"Initial approved agreement"}', 4),
    (102, 101, 2, '{"status":"expiring_soon","note":"Renewal review started"}', 4)
ON DUPLICATE KEY UPDATE contract_id = VALUES(contract_id), version_no = VALUES(version_no), data_json = VALUES(data_json), changed_by = VALUES(changed_by);

INSERT INTO team8_contract_obligations (id, contract_id, description, due_date, status) VALUES
    (101, 101, 'Submit renewal recommendation.', DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'pending'),
    (102, 101, 'Confirm final supply inventory.', DATE_ADD(CURDATE(), INTERVAL 15 DAY), 'in_progress'),
    (103, 102, 'Complete quarterly elevator inspection.', DATE_ADD(CURDATE(), INTERVAL 45 DAY), 'pending')
ON DUPLICATE KEY UPDATE contract_id = VALUES(contract_id), description = VALUES(description), due_date = VALUES(due_date), status = VALUES(status);

INSERT INTO team8_legal_cases (id, assigned_to, contract_id, title, subject, department_id, status, filed_date, deadline, deleted_at) VALUES
    (101, 4, 101, 'OfficePro Delivery Dispute', 'Late delivery of contracted office supplies', 2, 'under_review', DATE_SUB(CURDATE(), INTERVAL 14 DAY), DATE_ADD(CURDATE(), INTERVAL 16 DAY), NULL),
    (102, 4, NULL, 'Records Access Request', 'Review of records release request', 1, 'open', DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_ADD(CURDATE(), INTERVAL 27 DAY), NULL)
ON DUPLICATE KEY UPDATE assigned_to = VALUES(assigned_to), contract_id = VALUES(contract_id), title = VALUES(title), subject = VALUES(subject), department_id = VALUES(department_id), status = VALUES(status), filed_date = VALUES(filed_date), deadline = VALUES(deadline), deleted_at = VALUES(deleted_at);

INSERT INTO team8_legal_documents (id, case_id, document_id, description) VALUES
    (101, 101, 102, 'Primary service agreement.'), (102, 101, 104, 'Supporting dispute correspondence.')
ON DUPLICATE KEY UPDATE case_id = VALUES(case_id), document_id = VALUES(document_id), description = VALUES(description);

-- =========================================================
-- HR DOCUMENT AUTOMATION
-- =========================================================
INSERT INTO team8_incident_reports (id, document_number, employee_id, prepared_by, department_id, status, incident_date, incident_time, incident_location, incident_type, description, witness, attachment_path, current_version) VALUES
    (101, 'IR-2026-0001', 5, 5, 3, 'pending', DATE_SUB(CURDATE(), INTERVAL 2 DAY), '09:30:00', 'Training Hall', 'Safety', 'Wet floor observed before the orientation session.', 'Facilities Fran', NULL, 1),
    (102, 'IR-2026-0002', 2, 1, 1, 'approved', DATE_SUB(CURDATE(), INTERVAL 10 DAY), '15:00:00', 'Main Conference Room', 'Equipment', 'Projector cable was damaged during setup.', 'Dev Tester', NULL, 2)
ON DUPLICATE KEY UPDATE document_number = VALUES(document_number), employee_id = VALUES(employee_id), prepared_by = VALUES(prepared_by), department_id = VALUES(department_id), status = VALUES(status), incident_date = VALUES(incident_date), incident_time = VALUES(incident_time), incident_location = VALUES(incident_location), incident_type = VALUES(incident_type), description = VALUES(description), witness = VALUES(witness), attachment_path = VALUES(attachment_path), current_version = VALUES(current_version);

INSERT INTO team8_notice_to_explain (id, document_number, incident_report_id, employee_id, prepared_by, status, deadline, remarks, current_version) VALUES
    (101, 'NTE-2026-0001', 102, 2, 1, 'pending', DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'Please provide an explanation of the equipment handling process.', 1)
ON DUPLICATE KEY UPDATE document_number = VALUES(document_number), incident_report_id = VALUES(incident_report_id), employee_id = VALUES(employee_id), prepared_by = VALUES(prepared_by), status = VALUES(status), deadline = VALUES(deadline), remarks = VALUES(remarks), current_version = VALUES(current_version);

INSERT INTO team8_explanations (id, nte_id, employee_id, explanation_text, attachment_path, status, admin_remarks, reviewed_by, reviewed_at) VALUES
    (101, 101, 2, 'The cable was already worn before setup. I reported it immediately and requested a replacement.', NULL, 'pending', NULL, NULL, NULL)
ON DUPLICATE KEY UPDATE nte_id = VALUES(nte_id), employee_id = VALUES(employee_id), explanation_text = VALUES(explanation_text), attachment_path = VALUES(attachment_path), status = VALUES(status), admin_remarks = VALUES(admin_remarks), reviewed_by = VALUES(reviewed_by), reviewed_at = VALUES(reviewed_at);

INSERT INTO team8_memorandums (id, document_number, kind, title, recipients, content, remarks, prepared_by, status, current_version) VALUES
    (101, 'MEMO-2026-0001', 'memorandum', 'Updated Facility Booking Procedure', 'All Facilities and Administrative Staff', 'All bookings must include equipment requirements before submission.', 'For acknowledgement.', 1, 'approved', 2),
    (102, 'WL-2026-0001', 'warning_letter', 'Attendance Reminder', 'Employee Ella', 'Please observe the published attendance and reporting procedures.', 'Issued for documentation.', 1, 'draft', 1)
ON DUPLICATE KEY UPDATE document_number = VALUES(document_number), kind = VALUES(kind), title = VALUES(title), recipients = VALUES(recipients), content = VALUES(content), remarks = VALUES(remarks), prepared_by = VALUES(prepared_by), status = VALUES(status), current_version = VALUES(current_version);

INSERT INTO team8_certificates (id, document_number, certificate_type, employee_id, prepared_by, details, status, current_version) VALUES
    (101, 'CERT-2026-0001', 'employment', 5, 1, 'Certificate of employment for Employee Ella.', 'approved', 1),
    (102, 'CERT-2026-0002', 'recognition', 2, 1, 'Recognition for completing the facilities safety audit.', 'draft', 1)
ON DUPLICATE KEY UPDATE document_number = VALUES(document_number), certificate_type = VALUES(certificate_type), employee_id = VALUES(employee_id), prepared_by = VALUES(prepared_by), details = VALUES(details), status = VALUES(status), current_version = VALUES(current_version);

INSERT INTO team8_hr_document_versions (id, doc_type, doc_id, version_no, data_json, created_by) VALUES
    (101, 'incident_report', 102, 1, '{"status":"pending","description":"Initial equipment incident report"}', 1),
    (102, 'memorandum', 101, 1, '{"status":"draft","title":"Updated Facility Booking Procedure"}', 1)
ON DUPLICATE KEY UPDATE doc_type = VALUES(doc_type), doc_id = VALUES(doc_id), version_no = VALUES(version_no), data_json = VALUES(data_json), created_by = VALUES(created_by);

-- Dashboard and audit examples.
INSERT INTO notifications (id, user_id, message, target_url, status, created_at) VALUES
    (101, 1, 'Reservation approvals require review.', 'index.php?page=reservation', 'unread', NOW()),
    (102, 4, 'Contract renewal attention is required.', 'index.php?page=contracts', 'unread', NOW()),
    (103, 6, 'Retention records are approaching disposition.', 'index.php?page=retention', 'read', DATE_SUB(NOW(), INTERVAL 1 DAY))
ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), message = VALUES(message), target_url = VALUES(target_url), status = VALUES(status), created_at = VALUES(created_at);

INSERT INTO audit_logs (id, user_id, entity_type, entity_id, action, old_value, new_value, created_at) VALUES
    (101, 1, 'reservation', 101, 'approved', '{"status":"pending"}', '{"status":"approved"}', NOW()),
    (102, 6, 'record', 102, 'archived', '{"status":"active"}', '{"status":"archived"}', DATE_SUB(NOW(), INTERVAL 10 DAY)),
    (103, 4, 'contract', 101, 'updated', '{"status":"active"}', '{"status":"expiring_soon"}', NOW())
ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), entity_type = VALUES(entity_type), entity_id = VALUES(entity_id), action = VALUES(action), old_value = VALUES(old_value), new_value = VALUES(new_value), created_at = VALUES(created_at);
