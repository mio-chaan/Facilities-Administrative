-- =====================================================================
-- COMPLETE DEMO SEED (rewritten from the top)
-- Database : capstone_shared_db
-- Order    : Parents before children, always.
-- Every FK target is inserted earlier in this same file.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;
SET time_zone = '+08:00';

-- =====================================================================
-- 0. CLEANUP (children first, then parents)
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
-- 1. DEPARTMENTS (11)  [no FKs]
-- =====================================================================
INSERT INTO departments (id, name) VALUES
    (1,'Administration'),(2,'Finance'),(3,'Human Resources'),
    (4,'Information Technology'),(5,'Legal'),(6,'Operations'),
    (7,'Procurement'),(8,'Facilities'),(9,'Security'),
    (10,'Marketing'),(11,'Customer Service');

-- =====================================================================
-- 2. ROLES (6)  [no FKs]
-- =====================================================================
INSERT INTO roles (id, role_name) VALUES
    (1,'admin'),(2,'facilities_staff'),(3,'front_desk'),
    (4,'records_officer'),(5,'legal_officer'),(6,'employee');

-- =====================================================================
-- 3. USERS (25)  [FK → departments]
-- =====================================================================
INSERT INTO users (id, department_id, full_name, email, password_hash) VALUES
    (1, 1,'Dev Tester',       'dev.tester@example.local',   '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (2, 8,'Facilities Fran',  'facilities@example.local',   '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (3, 3,'Frontdesk Fred',   'frontdesk@example.local',    '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (4, 5,'Legal Lena',       'legal@example.local',        '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (5, 3,'Employee Ella',    'employee@example.local',     '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (6, 1,'Records Rita',     'records@example.local',      '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (7, 2,'Finance Felix',    'finance@example.local',      '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (8, 7,'Procurement Pete', 'procurement@example.local',  '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (9, 4,'IT Ivan',          'it.admin@example.local',     '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (10,6,'Operations Omar',  'operations@example.local',   '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (11,3,'HR Hannah',        'hr@example.local',           '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (12,9,'Security Sam',     'security@example.local',     '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (13,10,'Marketing Mia',   'marketing@example.local',    '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (14,11,'Service Sofia',   'service@example.local',      '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (15,4,'Developer Dave',   'dave.dev@example.local',     '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (16,4,'Sysadmin Sasha',   'sasha.sys@example.local',    '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (17,2,'Accountant Anna',  'anna.acct@example.local',    '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (18,2,'Auditor Alex',     'alex.audit@example.local',   '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (19,5,'Paralegal Paula',  'paula.legal@example.local',  '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (20,8,'Maintenance Mike', 'mike.maint@example.local',   '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (21,8,'Electrician Ed',   'ed.electric@example.local',  '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (22,6,'Logistics Liza',   'liza.logistics@example.local','$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (23,7,'Buyer Ben',        'ben.buyer@example.local',    '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (24,9,'Guard Gina',       'gina.guard@example.local',   '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om'),
    (25,11,'Support Sam',     'sam.support@example.local',  '$2y$10$gU/eY.idJyyabXowhB5lGOdUVC3NrbnzheiGStqcpZRa9xC7IE9om');

-- =====================================================================
-- 4. USER ROLES (25)  [FK → users, roles]
-- =====================================================================
INSERT INTO user_roles (id, user_id, role_id) VALUES
    (1,1,1),(2,2,2),(3,3,3),(4,4,5),(5,5,6),(6,6,4),(7,7,6),(8,8,6),
    (9,9,1),(10,10,6),(11,11,6),(12,12,6),(13,13,6),(14,14,6),(15,15,6),
    (16,16,1),(17,17,6),(18,18,6),(19,19,5),(20,20,2),(21,21,2),(22,22,6),
    (23,23,6),(24,24,6),(25,25,6);

-- =====================================================================
-- 5. DOCUMENT CATEGORIES (9)  [no FKs]
-- =====================================================================
INSERT INTO team8_document_categories (id, name) VALUES
    (1,'Administrative'),(2,'Contracts'),(3,'Compliance'),
    (4,'Finance'),(5,'Inventory'),(6,'Facilities'),
    (7,'Human Resources'),(8,'Others'),(9,'Legal');

-- =====================================================================
-- 6. RETENTION SCHEDULES (3)  [no FKs]
-- =====================================================================
INSERT INTO team8_retention_schedules (id, record_type, retention_years) VALUES
    (1,'HR Records',5),(2,'Financial Records',7),(3,'Legal Filings',10);

-- =====================================================================
-- 7. DOCUMENT NUMBER SEQUENCES  [no FKs]
-- =====================================================================
INSERT INTO team8_document_number_sequences (prefix, sequence_year, last_number) VALUES
    ('DOC',2026,25),('CON',2026,25),('MEMO',2026,22),
    ('CERT',2026,22),('IR',2026,22),('NTE',2026,20);

INSERT INTO team8_hr_document_sequences (prefix, document_year, next_number) VALUES
    ('IR',2026,23),('NTE',2026,21),('MEMO',2026,23),('CERT',2026,23);

-- =====================================================================
-- 8. FACILITY LOCATIONS (6)  [no FKs]
-- =====================================================================
INSERT INTO team8_facility_locations (id, name) VALUES
    (1,'Main Building - Ground Floor'),(2,'Main Building - 2nd Floor'),
    (3,'Annex Building'),(4,'Outdoor Grounds'),(5,'Basement Level'),
    (6,'Rooftop Deck');

-- =====================================================================
-- 9. FACILITIES (22)  [FK → none required; location is free-text]
-- =====================================================================
INSERT INTO team8_facilities
    (id, name, location, facility_type, capacity, description, equipment_notes,
     maintenance_status, next_maintenance_date, status) VALUES
    (1,'Executive Boardroom','Main Building - 2nd Floor','Meeting Room',20,'Premium boardroom.','Projector, TV, mic.','operational','2026-11-15','active'),
    (2,'Training Room A','Main Building - Ground Floor','Training Room',50,'Standard training room.','Projector, chairs.','operational','2026-10-20','active'),
    (3,'Multi-Purpose Hall','Annex Building','Event Hall',200,'Large hall.','Stage, PA.','maintenance_due','2026-10-05','active'),
    (4,'Sports Court','Outdoor Grounds','Outdoor Facility',100,'Covered court.','Scoreboard.','operational','2026-12-01','active'),
    (5,'Old Storage Room','Annex Building','Storage',5,'Decommissioned.','None','out_of_service',NULL,'archived'),
    (6,'Conference Room B','Main Building - 2nd Floor','Meeting Room',12,'Small room.','TV.','operational','2026-11-20','active'),
    (7,'Conference Room C','Main Building - 2nd Floor','Meeting Room',8,'Huddle.','Monitor.','operational','2026-11-25','active'),
    (8,'Training Room B','Main Building - Ground Floor','Training Room',30,'Computer lab.','30 PCs.','operational','2026-10-30','active'),
    (9,'Auditorium','Annex Building','Event Hall',300,'Auditorium.','Stage.','operational','2026-12-10','active'),
    (10,'Cafeteria','Main Building - Ground Floor','Dining',150,'Cafeteria.','Tables.','operational','2026-11-01','active'),
    (11,'Reception Area','Main Building - Ground Floor','Lobby',20,'Lobby.','Sofa.','operational','2026-12-15','active'),
    (12,'Server Room','Basement Level','Utility',4,'Servers.','Racks.','operational','2026-10-15','active'),
    (13,'Rooftop Function Area','Rooftop Deck','Event Space',80,'Rooftop.','Tents.','operational','2026-12-20','active'),
    (14,'Wellness Room','Main Building - 2nd Floor','Wellness',6,'Quiet.','Bed.','operational','2026-11-30','active'),
    (15,'Lactation Room','Main Building - 2nd Floor','Wellness',2,'Private.','Chair.','operational','2026-11-30','active'),
    (16,'Storage Room A','Basement Level','Storage',10,'Storage.','Shelves.','operational','2027-01-10','active'),
    (17,'Storage Room B','Basement Level','Storage',10,'Storage.','Shelves.','operational','2027-01-10','active'),
    (18,'Gym','Annex Building','Recreation',40,'Gym.','Equipment.','operational','2026-12-05','active'),
    (19,'Prayer Room','Main Building - Ground Floor','Wellness',15,'Prayer.','Mats.','operational','2026-11-30','active'),
    (20,'Parking Level 1','Basement Level','Parking',60,'Parking.','CCTV.','operational','2027-02-01','active'),
    (21,'Parking Level 2','Basement Level','Parking',60,'Parking.','CCTV.','operational','2027-02-01','active'),
    (22,'Guard House','Outdoor Grounds','Security',4,'Gate.','Radio.','operational','2026-12-01','active');

-- =====================================================================
-- 10. EQUIPMENT (25)  [FK → facilities 1–22 ✅]
-- =====================================================================
INSERT INTO team8_equipment (id, home_facility_id, name, quantity) VALUES
    (1,1,'Epson EB-2250U Projector',2),(2,1,'Samsung 65" Smart TV',1),
    (3,1,'Jabra Speak 750',2),(4,2,'Portable Projector',3),
    (5,2,'Wireless Microphone Set',2),(6,3,'JBL EON715 PA Speaker',4),
    (7,3,'Shure Wireless Mic',4),(8,4,'Portable Scoreboard',1),
    (9,4,'Volleyball Net Set',2),(10,2,'Extension Cord (20m)',10),
    (11,6,'Logitech Rally Bar',1),(12,6,'Whiteboard 4x6',2),
    (13,7,'Dell 27" Monitor',1),(14,8,'Desktop PC i5',30),
    (15,8,'Desktop PC i7',5),(16,9,'Stage Lights',12),
    (17,9,'Wireless Lapel Mic',6),(18,10,'Chafing Dish',10),
    (19,10,'Coffee Machine',3),(20,12,'UPS 3kVA',4),
    (21,13,'Canopy Tent 3x3',8),(22,18,'Treadmill',4),
    (23,18,'Dumbbell Set',6),(24,22,'Handheld Radio',8),
    (25,22,'Flashlight Heavy',6);

-- =====================================================================
-- 11. FACILITY MAINTENANCE HISTORY (25)  [FK → facilities, users ✅]
-- =====================================================================
INSERT INTO team8_facility_maintenance_history
    (id, facility_id, performed_by, maintenance_date, notes) VALUES
    (1,1,2,'2026-08-15','Projector lamp replaced.'),
    (2,3,2,'2026-09-01','Stage lighting checked.'),
    (3,2,2,'2026-09-10','Chairs re-upholstered.'),
    (4,4,2,'2026-09-20','Court repainted.'),
    (5,6,20,'2026-09-22','TV mount tightened.'),
    (6,7,20,'2026-09-25','Monitor replaced.'),
    (7,8,21,'2026-09-28','PCs cleaned and updated.'),
    (8,9,20,'2026-10-01','Stage lights rewired.'),
    (9,10,20,'2026-10-03','Coffee machine descaled.'),
    (10,11,20,'2026-10-05','Sofa vacuumed.'),
    (11,12,21,'2026-10-06','AC serviced.'),
    (12,13,20,'2026-10-08','Tents inspected.'),
    (13,14,20,'2026-10-10','Bed linens replaced.'),
    (14,15,20,'2026-10-10','Fridge cleaned.'),
    (15,16,20,'2026-10-12','Shelves reinforced.'),
    (16,17,20,'2026-10-12','Shelves reinforced.'),
    (17,18,21,'2026-10-14','Treadmill belts replaced.'),
    (18,19,20,'2026-10-15','Mats cleaned.'),
    (19,20,21,'2026-10-16','CCTV checked.'),
    (20,21,21,'2026-10-16','CCTV checked.'),
    (21,22,21,'2026-10-18','Radios reprogrammed.'),
    (22,1,20,'2026-10-20','AC filter replaced.'),
    (23,2,20,'2026-10-21','Projector cleaned.'),
    (24,3,20,'2026-10-22','PA system tested.'),
    (25,4,20,'2026-10-23','Scoreboard battery replaced.');

-- =====================================================================
-- 12. PARTIES (24)  [no FKs]
-- =====================================================================
INSERT INTO team8_parties
    (id, name, trade_name, registration_number, tin, type, contact_email,
     contact_phone, primary_contact, address, authorized_signatory_name,
     authorized_signatory_position) VALUES
    (1,'Metro Pacific Solutions Inc.','MPSI','SEC-2019-00123','009-123-456-000','organization','contracts@mpsi.example','+63-2-8123-4567','Maria Santos','12F Ayala Tower, Makati','Maria Santos','President'),
    (2,'Bright Office Supplies Corp.','BOSC','SEC-2020-00456','009-234-567-000','organization','sales@bos.example','+63-2-8234-5678','Jose Reyes','88 Ortigas Ave, Pasig','Jose Reyes','GM'),
    (3,'Atty. Carlos Dizon',NULL,NULL,'123-456-789-000','individual','carlos.dizon@law.example','+63-917-555-0101',NULL,'Unit 5B, Legaspi Village','Carlos Dizon','Counsel'),
    (4,'CleanPro Facility Services','CleanPro','SEC-2021-00789','009-345-678-000','organization','ops@cleanpro.example','+63-2-8345-6789','Aisha Cruz','45 Quezon Ave, QC','Aisha Cruz','Ops Head'),
    (5,'TechLease Philippines','TechLease','SEC-2018-00111','009-456-789-000','organization','lease@techlease.example','+63-2-8456-7890','Ryan Lim','10F BGC Tower, Taguig','Ryan Lim','CEO'),
    (6,'SafeGuard Security Agency','SafeGuard','SEC-2017-00222','009-567-890-000','organization','ops@safeguard.example','+63-2-8567-8901','Liza Tan','22 Shaw Blvd, Mandaluyong','Liza Tan','President'),
    (7,'Prime Builders Inc.','PrimeBuild','SEC-2016-00333','009-678-901-000','organization','projects@primebuild.example','+63-2-8678-9012','Mark Villanueva','100 EDSA, QC','Mark Villanueva','COO'),
    (8,'GreenEnergy Solutions','GreenEnergy','SEC-2019-00444','009-789-012-000','organization','info@greenenergy.example','+63-2-8789-0123','Grace Ong','5F Rockwell, Makati','Grace Ong','CEO'),
    (9,'MedCare Health Services','MedCare','SEC-2020-00555','009-890-123-000','organization','clinic@medcare.example','+63-2-8890-1234','Dr. Ben Cruz','77 Aurora Blvd, QC','Dr. Ben Cruz','Medical Director'),
    (10,'FoodCraft Catering','FoodCraft','SEC-2021-00666','009-901-234-000','organization','events@foodcraft.example','+63-2-8901-2345','Chef Mia Reyes','12 Katipunan Ave, QC','Mia Reyes','Owner'),
    (11,'PrintWorks Digital','PrintWorks','SEC-2019-00777','009-012-345-000','organization','orders@printworks.example','+63-2-8012-3456','Paul Sy','33 Binondo, Manila','Paul Sy','Manager'),
    (12,'NetConnect ISP','NetConnect','SEC-2018-00888','009-111-222-000','organization','support@netconnect.example','+63-2-8111-2222','Carlo Tan','8F Ortigas Center','Carlo Tan','CTO'),
    (13,'FurnitureHub Inc.','FurnitureHub','SEC-2017-00999','009-222-333-000','organization','sales@furniturehub.example','+63-2-8222-3333','Elena Lim','55 Mabini St, Manila','Elena Lim','President'),
    (14,'LegalEdge Partners','LegalEdge','SEC-2020-01010','009-333-444-000','organization','partners@legaledge.example','+63-2-8333-4444','Atty. Rey Santos','3F Salcedo Village','Rey Santos','Managing Partner'),
    (15,'WaterPure Systems','WaterPure','SEC-2021-01111','009-444-555-000','organization','service@waterpure.example','+63-2-8444-5555','Nina Cruz','19 Pasong Tamo','Nina Cruz','GM'),
    (16,'AirCare HVAC Services','AirCare','SEC-2019-01212','009-555-666-000','organization','service@aircare.example','+63-2-8555-6666','Ben Uy','21 Chino Roces','Ben Uy','Owner'),
    (17,'Atty. Patricia Lim',NULL,NULL,'234-567-890-000','individual','patricia.lim@law.example','+63-917-666-7777',NULL,'Unit 8A, Rockwell','Patricia Lim','Counsel'),
    (18,'Dr. Ramon Santos',NULL,NULL,'345-678-901-000','individual','ramon.santos@clinic.example','+63-917-777-8888',NULL,'77 Medical Plaza, Ortigas','Ramon Santos','Physician'),
    (19,'Engineer Karlo Reyes',NULL,NULL,'456-789-012-000','individual','karlo.reyes@eng.example','+63-917-888-9999',NULL,'12 Engineering Bldg, QC','Karlo Reyes','Engineer'),
    (20,'Ms. Diane Cruz',NULL,NULL,'567-890-123-000','individual','diane.cruz@consult.example','+63-917-999-0000',NULL,'45 Consultancy Row, Makati','Diane Cruz','Consultant'),
    (21,'Mr. Eric Tan',NULL,NULL,'678-901-234-000','individual','eric.tan@consult.example','+63-918-111-2222',NULL,'9F Enterprise Bldg','Eric Tan','Advisor'),
    (22,'Skyline Advertising','Skyline','SEC-2018-01313','009-666-777-000','organization','ads@skyline.example','+63-2-8666-7777','Rica Gomez','14F Ad Tower, BGC','Rica Gomez','CEO'),
    (23,'Atty. Maria Bautista',NULL,NULL,'789-012-345-000','individual','maria.bautista@law.example','+63-918-222-3333',NULL,'77 Salcedo Village, Makati','Maria Bautista','Counsel'),
    (24,'Mr. Renato Cruz',NULL,NULL,'890-123-456-000','individual','renato.cruz@security.example','+63-918-333-4444',NULL,'12 Security Ave, QC','Renato Cruz','Guard Supervisor');

-- =====================================================================
-- 13. CONTRACTS (25)  [FK → users, departments, self(renewed_from_id)]
--     NOTE: row 25 has renewed_from_id=20 → insert 20 first.
--     MySQL evaluates FK per row, so row 20 must appear before row 25.
-- =====================================================================
INSERT INTO team8_contracts
    (id, contract_number, owner_id, department_id, renewed_from_id, title,
     contract_type, description, start_date, end_date, renewal_date, amount,
     currency, payment_terms, payment_frequency, deposit_amount, financial_notes,
     notice_period_days, status, attachment_path, attachment_type, attachment_name) VALUES
    (1,'CON-2026-0001',8,7,NULL,'Annual Office Supplies Contract','Supply Agreement','Supply of general office supplies for FY2026.','2026-01-01','2026-12-31','2026-11-30',850000.00,'PHP','Net 30','Quarterly',50000.00,'Includes delivery.','30','active','/uploads/contracts/CON-2026-0001.pdf','pdf','CON-2026-0001.pdf'),
    (2,'CON-2026-0002',7,2,NULL,'IT Equipment Lease Agreement','Lease','Lease of 25 laptops.','2026-02-01','2027-01-31','2026-12-31',1200000.00,'PHP','Net 15','Monthly',100000.00,'On-site support.','60','approval','/uploads/contracts/CON-2026-0002.pdf','pdf','CON-2026-0002.pdf'),
    (3,'CON-2026-0003',4,5,NULL,'Retainer Agreement - Legal Counsel','Professional Services','Monthly retainer.','2026-03-01','2027-02-28','2027-01-31',600000.00,'PHP','Net 30','Monthly',NULL,'40 hrs/month.','30','active','/uploads/contracts/CON-2026-0003.pdf','pdf','CON-2026-0003.pdf'),
    (4,'CON-2026-0004',2,8,NULL,'Janitorial Services Contract','Service Agreement','Daily janitorial.','2026-04-01','2027-03-31','2027-02-28',960000.00,'PHP','Net 15','Monthly',80000.00,'Includes consumables.','45','signing','/uploads/contracts/CON-2026-0004.pdf','pdf','CON-2026-0004.pdf'),
    (5,'CON-2026-0005',1,1,3,'Renewal: Legal Counsel Retainer','Professional Services','Renewal of CON-2026-0003.','2027-03-01','2028-02-29',NULL,660000.00,'PHP','Net 30','Monthly',NULL,'5% escalation.','30','draft','/uploads/contracts/CON-2026-0005.pdf','pdf','CON-2026-0005.pdf'),
    (6,'CON-2026-0006',8,7,NULL,'Security Services Contract','Service Agreement','24/7 guards.','2026-01-15','2027-01-14','2026-12-15',1440000.00,'PHP','Net 15','Monthly',120000.00,'8 guards.','60','active','/uploads/contracts/CON-2026-0006.pdf','pdf','CON-2026-0006.pdf'),
    (7,'CON-2026-0007',9,4,NULL,'Network Maintenance Contract','Service Agreement','Annual support.','2026-02-01','2027-01-31','2026-12-31',480000.00,'PHP','Net 30','Quarterly',40000.00,'24/7.','30','active','/uploads/contracts/CON-2026-0007.pdf','pdf','CON-2026-0007.pdf'),
    (8,'CON-2026-0008',8,7,NULL,'Construction of Annex Wing','Construction','Build annex.','2026-03-01','2027-06-30',NULL,8500000.00,'PHP','Milestone','Milestone',850000.00,'5 milestones.','90','active','/uploads/contracts/CON-2026-0008.pdf','pdf','CON-2026-0008.pdf'),
    (9,'CON-2026-0009',13,10,NULL,'Digital Marketing Campaign','Service Agreement','Q1-Q4.','2026-01-01','2026-12-31','2026-11-30',720000.00,'PHP','Net 30','Monthly',NULL,'Social + SEO.','30','active','/uploads/contracts/CON-2026-0009.pdf','pdf','CON-2026-0009.pdf'),
    (10,'CON-2026-0010',17,2,NULL,'Audit Services Contract','Professional Services','External audit.','2026-01-01','2026-06-30',NULL,350000.00,'PHP','Net 30','Milestone',NULL,'Annual.','30','archived','/uploads/contracts/CON-2026-0010.pdf','pdf','CON-2026-0010.pdf'),
    (11,'CON-2026-0011',8,7,NULL,'Catering Services Contract','Service Agreement','Events.','2026-05-01','2027-04-30','2027-03-31',300000.00,'PHP','Net 15','Per Event',NULL,'As needed.','15','active','/uploads/contracts/CON-2026-0011.pdf','pdf','CON-2026-0011.pdf'),
    (12,'CON-2026-0012',13,10,NULL,'Printing Services Contract','Service Agreement','Collaterals.','2026-01-01','2026-12-31','2026-11-30',240000.00,'PHP','Net 30','Quarterly',NULL,'On-demand.','15','active','/uploads/contracts/CON-2026-0012.pdf','pdf','CON-2026-0012.pdf'),
    (13,'CON-2026-0013',9,4,NULL,'Internet Service Contract','Service Agreement','1Gbps.','2026-01-01','2026-12-31','2026-11-30',600000.00,'PHP','Net 30','Monthly',50000.00,'SLA 99.9%.','30','active','/uploads/contracts/CON-2026-0013.pdf','pdf','CON-2026-0013.pdf'),
    (14,'CON-2026-0014',8,7,NULL,'Office Furniture Purchase','Purchase','Chairs.','2026-06-01','2026-08-31',NULL,450000.00,'PHP','Net 30','On Delivery',NULL,'100 chairs.','15','archived','/uploads/contracts/CON-2026-0014.pdf','pdf','CON-2026-0014.pdf'),
    (15,'CON-2026-0015',4,5,NULL,'Litigation Services Contract','Professional Services','Case rep.','2026-07-01','2027-06-30','2027-05-31',800000.00,'PHP','Net 30','Milestone',NULL,'Retainer + fees.','30','active','/uploads/contracts/CON-2026-0015.pdf','pdf','CON-2026-0015.pdf'),
    (16,'CON-2026-0016',2,8,NULL,'Water Refilling Contract','Supply Agreement','Water.','2026-01-01','2026-12-31','2026-11-30',120000.00,'PHP','Net 15','Monthly',NULL,'Weekly.','15','active','/uploads/contracts/CON-2026-0016.pdf','pdf','CON-2026-0016.pdf'),
    (17,'CON-2026-0017',2,8,NULL,'HVAC Maintenance Contract','Service Agreement','Quarterly.','2026-02-01','2027-01-31','2026-12-31',360000.00,'PHP','Net 30','Quarterly',30000.00,'All units.','30','active','/uploads/contracts/CON-2026-0017.pdf','pdf','CON-2026-0017.pdf'),
    (18,'CON-2026-0018',4,5,NULL,'Consulting - Compliance','Professional Services','Compliance.','2026-03-01','2026-08-31',NULL,280000.00,'PHP','Net 30','Milestone',NULL,'Project.','15','expiration','/uploads/contracts/CON-2026-0018.pdf','pdf','CON-2026-0018.pdf'),
    (19,'CON-2026-0019',9,4,NULL,'Software Licensing','License','50 users.','2026-01-01','2026-12-31','2026-11-30',900000.00,'PHP','Net 30','Annual',NULL,'Renewable.','30','active','/uploads/contracts/CON-2026-0019.pdf','pdf','CON-2026-0019.pdf'),
    (20,'CON-2026-0020',8,7,NULL,'Vehicle Lease Contract','Lease','3 vans.','2026-04-01','2029-03-31','2029-02-28',2160000.00,'PHP','Net 15','Monthly',180000.00,'3-year.','60','active','/uploads/contracts/CON-2026-0020.pdf','pdf','CON-2026-0020.pdf'),
    (21,'CON-2026-0021',7,2,NULL,'Payroll Software','License','Annual.','2026-01-01','2026-12-31','2026-11-30',180000.00,'PHP','Net 30','Annual',NULL,'Cloud.','30','active','/uploads/contracts/CON-2026-0021.pdf','pdf','CON-2026-0021.pdf'),
    (22,'CON-2026-0022',4,5,NULL,'Notary Services','Professional Services','Notary.','2026-05-01','2027-04-30','2027-03-31',120000.00,'PHP','Net 30','Monthly',NULL,'On-call.','15','active','/uploads/contracts/CON-2026-0022.pdf','pdf','CON-2026-0022.pdf'),
    (23,'CON-2026-0023',8,7,NULL,'Medical Services','Service Agreement','Physicals.','2026-08-01','2026-10-31',NULL,220000.00,'PHP','Net 30','On Delivery',NULL,'Employees.','15','expiration','/uploads/contracts/CON-2026-0023.pdf','pdf','CON-2026-0023.pdf'),
    (24,'CON-2026-0024',13,10,NULL,'Event Management','Service Agreement','Events.','2026-06-01','2027-05-31','2027-04-30',500000.00,'PHP','Net 30','Per Event',NULL,'3 events.','30','active','/uploads/contracts/CON-2026-0024.pdf','pdf','CON-2026-0024.pdf'),
    (25,'CON-2026-0025',1,1,20,'Renewal: Vehicle Lease','Lease','Renewal.','2029-04-01','2032-03-31',NULL,2400000.00,'PHP','Net 15','Monthly',200000.00,'3-year renewal.','60','draft','/uploads/contracts/CON-2026-0025.pdf','pdf','CON-2026-0025.pdf');

-- =====================================================================
-- 14. CONTRACT PARTIES (30)  [FK → contracts 1–25 ✅, parties 1–24 ✅]
-- =====================================================================
INSERT INTO team8_contract_parties (id, contract_id, party_id, role_in_contract) VALUES
    (1,1,2,'Supplier'),(2,2,5,'Lessor'),(3,3,3,'Counsel'),(4,4,4,'Service Provider'),
    (5,5,3,'Counsel'),(6,6,6,'Service Provider'),(7,7,12,'Service Provider'),
    (8,8,7,'Contractor'),(9,9,22,'Agency'),(10,10,14,'Auditor'),
    (11,11,10,'Caterer'),(12,12,11,'Printer'),(13,13,12,'ISP'),
    (14,14,13,'Supplier'),(15,15,14,'Counsel'),(16,16,15,'Supplier'),
    (17,17,16,'Service Provider'),(18,18,20,'Consultant'),(19,19,5,'Licensor'),
    (20,20,5,'Lessor'),(21,21,5,'Licensor'),(22,22,17,'Counsel'),
    (23,23,9,'Provider'),(24,24,22,'Agency'),(25,25,5,'Lessor'),
    (26,1,2,'Secondary Contact'),(27,8,19,'Engineer'),(28,15,17,'Co-Counsel'),
    (29,20,18,'Medical Advisor'),(30,6,24,'Guard Supervisor');

-- =====================================================================
-- 15. CONTRACT APPROVALS (30)  [FK → contracts, users ✅]
-- =====================================================================
INSERT INTO team8_contract_approvals
    (id, contract_id, reviewer_id, approver_id, action, comment, acted_at) VALUES
    (1,1,4,NULL,'reviewed','Terms acceptable.','2025-12-15 10:00:00'),
    (2,1,NULL,1,'approved','Approved.','2025-12-18 14:30:00'),
    (3,2,4,NULL,'reviewed','Clarify liability.','2026-01-20 09:15:00'),
    (4,2,NULL,1,'rejected','Revise liability clause.','2026-01-22 16:45:00'),
    (5,3,4,NULL,'reviewed','Standard retainer.','2026-02-10 11:00:00'),
    (6,3,NULL,1,'approved','Approved.','2026-02-12 13:00:00'),
    (7,4,4,NULL,'reviewed','Scope clear.','2026-03-15 15:20:00'),
    (8,4,NULL,1,'approved','For signing.','2026-03-18 10:00:00'),
    (9,5,4,NULL,'reviewed','For renewal.','2026-09-01 09:00:00'),
    (10,6,4,NULL,'reviewed','Ok.','2025-12-20 10:00:00'),
    (11,6,NULL,1,'approved','Approved.','2025-12-22 14:00:00'),
    (12,7,4,NULL,'reviewed','Ok.','2026-01-25 11:00:00'),
    (13,7,NULL,1,'approved','Approved.','2026-01-28 15:00:00'),
    (14,8,4,NULL,'reviewed','Large project.','2026-02-15 09:00:00'),
    (15,8,NULL,1,'approved','Approved.','2026-02-18 10:00:00'),
    (16,9,4,NULL,'reviewed','Ok.','2025-12-10 10:00:00'),
    (17,9,NULL,1,'approved','Approved.','2025-12-12 11:00:00'),
    (18,10,4,NULL,'reviewed','Ok.','2025-11-15 10:00:00'),
    (19,10,NULL,1,'approved','Approved.','2025-11-18 10:00:00'),
    (20,11,4,NULL,'reviewed','Ok.','2026-04-10 10:00:00'),
    (21,11,NULL,1,'approved','Approved.','2026-04-12 10:00:00'),
    (22,12,4,NULL,'reviewed','Ok.','2025-12-05 10:00:00'),
    (23,12,NULL,1,'approved','Approved.','2025-12-08 10:00:00'),
    (24,13,4,NULL,'reviewed','Ok.','2025-12-06 10:00:00'),
    (25,13,NULL,1,'approved','Approved.','2025-12-09 10:00:00'),
    (26,14,4,NULL,'reviewed','Ok.','2026-05-10 10:00:00'),
    (27,14,NULL,1,'approved','Approved.','2026-05-12 10:00:00'),
    (28,15,4,NULL,'reviewed','Ok.','2026-06-10 10:00:00'),
    (29,15,NULL,1,'approved','Approved.','2026-06-12 10:00:00'),
    (30,16,4,NULL,'reviewed','Ok.','2025-12-10 10:00:00');

-- =====================================================================
-- 16. CONTRACT HISTORY (30)  [FK → contracts, users ✅]
-- =====================================================================
INSERT INTO team8_contract_history
    (id, contract_id, version_no, data_json, changed_by) VALUES
    (1,1,1,'{"status":"draft"}',8),(2,1,2,'{"status":"approval"}',4),(3,1,3,'{"status":"active"}',1),
    (4,2,1,'{"status":"draft"}',7),(5,2,2,'{"status":"approval"}',4),
    (6,3,1,'{"status":"draft"}',4),(7,3,2,'{"status":"active"}',1),
    (8,4,1,'{"status":"draft"}',2),(9,4,2,'{"status":"signing"}',1),
    (10,5,1,'{"status":"draft"}',1),
    (11,6,1,'{"status":"draft"}',8),(12,6,2,'{"status":"active"}',1),
    (13,7,1,'{"status":"draft"}',9),(14,7,2,'{"status":"active"}',1),
    (15,8,1,'{"status":"draft"}',8),(16,8,2,'{"status":"active"}',1),
    (17,9,1,'{"status":"draft"}',13),(18,9,2,'{"status":"active"}',1),
    (19,10,1,'{"status":"draft"}',17),(20,10,2,'{"status":"archived"}',1),
    (21,11,1,'{"status":"draft"}',8),(22,11,2,'{"status":"active"}',1),
    (23,12,1,'{"status":"draft"}',13),(24,12,2,'{"status":"active"}',1),
    (25,13,1,'{"status":"draft"}',9),(26,13,2,'{"status":"active"}',1),
    (27,14,1,'{"status":"draft"}',8),(28,14,2,'{"status":"archived"}',1),
    (29,15,1,'{"status":"draft"}',4),(30,15,2,'{"status":"active"}',1);

-- =====================================================================
-- 17. DOCUMENTS (25)  [FK → categories, departments, users ✅]
-- =====================================================================
INSERT INTO team8_documents
    (id, category_id, document_type, department_id, owner_id, uploaded_by,
     title, file_path, current_version, status, review_reason, expiration_date) VALUES
    (1,2,'Contract',7,8,8,'Annual Office Supplies Contract 2026','/uploads/docs/DOC-2026-0001.pdf',1,'approved',NULL,'2026-12-31'),
    (2,2,'Contract',2,7,7,'IT Equipment Lease 2026','/uploads/docs/DOC-2026-0002.pdf',1,'pending','Awaiting legal review.','2027-01-31'),
    (3,3,'Compliance',1,6,6,'Mayor''s Business Permit 2026','/uploads/docs/DOC-2026-0003.pdf',1,'approved',NULL,'2026-12-31'),
    (4,4,'Financial',2,7,7,'Q3 2026 Financial Statement','/uploads/docs/DOC-2026-0004.pdf',1,'pending','For CFO signature.',NULL),
    (5,7,'HR',3,11,11,'Employee Handbook v3','/uploads/docs/DOC-2026-0005.pdf',1,'approved',NULL,NULL),
    (6,6,'Facilities',8,2,2,'Fire Safety Inspection Certificate 2026','/uploads/docs/DOC-2026-0006.pdf',1,'approved',NULL,'2027-01-15'),
    (7,1,'Administrative',1,6,6,'Board Resolution No. 2026-012','/uploads/docs/DOC-2026-0007.pdf',1,'archived',NULL,NULL),
    (8,2,'Contract',7,8,8,'Security Services Contract 2026','/uploads/docs/DOC-2026-0008.pdf',1,'approved',NULL,'2027-01-14'),
    (9,3,'Compliance',1,6,6,'Sanitary Permit 2026','/uploads/docs/DOC-2026-0009.pdf',1,'approved',NULL,'2026-12-31'),
    (10,4,'Financial',2,7,7,'Annual Budget 2026','/uploads/docs/DOC-2026-0010.pdf',1,'approved',NULL,NULL),
    (11,7,'HR',3,11,11,'Org Chart 2026','/uploads/docs/DOC-2026-0011.pdf',1,'approved',NULL,NULL),
    (12,6,'Facilities',8,2,2,'Facility Inventory 2026','/uploads/docs/DOC-2026-0012.pdf',1,'approved',NULL,NULL),
    (13,1,'Administrative',1,6,6,'Policy Manual v2','/uploads/docs/DOC-2026-0013.pdf',1,'approved',NULL,NULL),
    (14,2,'Contract',5,4,4,'Legal Retainer 2026','/uploads/docs/DOC-2026-0014.pdf',1,'approved',NULL,'2027-02-28'),
    (15,5,'Inventory',7,8,8,'Asset Registry 2026','/uploads/docs/DOC-2026-0015.pdf',1,'pending','Pending validation.',NULL),
    (16,8,'Others',4,9,9,'IT DR Plan','/uploads/docs/DOC-2026-0016.pdf',1,'approved',NULL,NULL),
    (17,9,'Legal',5,4,4,'Case Brief - MPSI','/uploads/docs/DOC-2026-0017.pdf',1,'approved',NULL,NULL),
    (18,3,'Compliance',9,12,12,'Security Audit Q3','/uploads/docs/DOC-2026-0018.pdf',1,'pending','Reviewing.',NULL),
    (19,4,'Financial',2,17,17,'Tax Filing 2025','/uploads/docs/DOC-2026-0019.pdf',1,'approved',NULL,NULL),
    (20,7,'HR',3,11,11,'Training Plan 2027','/uploads/docs/DOC-2026-0020.pdf',1,'draft',NULL,NULL),
    (21,6,'Facilities',8,20,20,'Maintenance Schedule Q4','/uploads/docs/DOC-2026-0021.pdf',1,'approved',NULL,NULL),
    (22,1,'Administrative',1,6,6,'Board Minutes Sept','/uploads/docs/DOC-2026-0022.pdf',1,'approved',NULL,NULL),
    (23,2,'Contract',10,13,13,'Marketing Agreement','/uploads/docs/DOC-2026-0023.pdf',1,'pending','Legal review.',NULL),
    (24,9,'Legal',5,19,19,'Affidavit - Case 2','/uploads/docs/DOC-2026-0024.pdf',1,'approved',NULL,NULL),
    (25,8,'Others',4,15,15,'Architecture Doc','/uploads/docs/DOC-2026-0025.pdf',1,'archived',NULL,NULL);

-- =====================================================================
-- 18. DOCUMENT VERSIONS (30)  [FK → documents 1–25 ✅]
-- =====================================================================
INSERT INTO team8_document_versions
    (id, document_id, version_no, file_path, file_size, checksum) VALUES
    (1,1,1,'/uploads/docs/DOC-2026-0001_v1.pdf',245000,'a1b2c3d4e5f6'),
    (2,2,1,'/uploads/docs/DOC-2026-0002_v1.pdf',198000,'b2c3d4e5f6a7'),
    (3,3,1,'/uploads/docs/DOC-2026-0003_v1.pdf',312000,'c3d4e5f6a7b8'),
    (4,5,1,'/uploads/docs/DOC-2026-0005_v1.pdf',780000,'d4e5f6a7b8c9'),
    (5,6,1,'/uploads/docs/DOC-2026-0006_v1.pdf',156000,'e5f6a7b8c9d0'),
    (6,8,1,'/uploads/docs/DOC-2026-0008_v1.pdf',220000,'f6a7b8c9d0e1'),
    (7,9,1,'/uploads/docs/DOC-2026-0009_v1.pdf',180000,'a7b8c9d0e1f2'),
    (8,10,1,'/uploads/docs/DOC-2026-0010_v1.pdf',410000,'b8c9d0e1f2a3'),
    (9,11,1,'/uploads/docs/DOC-2026-0011_v1.pdf',150000,'c9d0e1f2a3b4'),
    (10,12,1,'/uploads/docs/DOC-2026-0012_v1.pdf',175000,'d0e1f2a3b4c5'),
    (11,13,1,'/uploads/docs/DOC-2026-0013_v1.pdf',560000,'e1f2a3b4c5d6'),
    (12,14,1,'/uploads/docs/DOC-2026-0014_v1.pdf',210000,'f2a3b4c5d6e7'),
    (13,15,1,'/uploads/docs/DOC-2026-0015_v1.pdf',330000,'a3b4c5d6e7f8'),
    (14,16,1,'/uploads/docs/DOC-2026-0016_v1.pdf',290000,'b4c5d6e7f8a9'),
    (15,17,1,'/uploads/docs/DOC-2026-0017_v1.pdf',195000,'c5d6e7f8a9b0'),
    (16,18,1,'/uploads/docs/DOC-2026-0018_v1.pdf',240000,'d6e7f8a9b0c1'),
    (17,19,1,'/uploads/docs/DOC-2026-0019_v1.pdf',380000,'e7f8a9b0c1d2'),
    (18,20,1,'/uploads/docs/DOC-2026-0020_v1.pdf',120000,'f8a9b0c1d2e3'),
    (19,21,1,'/uploads/docs/DOC-2026-0021_v1.pdf',165000,'a9b0c1d2e3f4'),
    (20,22,1,'/uploads/docs/DOC-2026-0022_v1.pdf',145000,'b0c1d2e3f4a5'),
    (21,23,1,'/uploads/docs/DOC-2026-0023_v1.pdf',230000,'c1d2e3f4a5b6'),
    (22,24,1,'/uploads/docs/DOC-2026-0024_v1.pdf',190000,'d2e3f4a5b6c7'),
    (23,25,1,'/uploads/docs/DOC-2026-0025_v1.pdf',350000,'e3f4a5b6c7d8'),
    (24,1,2,'/uploads/docs/DOC-2026-0001_v2.pdf',250000,'f4a5b6c7d8e9'),
    (25,5,2,'/uploads/docs/DOC-2026-0005_v2.pdf',800000,'a5b6c7d8e9f0'),
    (26,13,2,'/uploads/docs/DOC-2026-0013_v2.pdf',580000,'b6c7d8e9f0a1'),
    (27,17,2,'/uploads/docs/DOC-2026-0017_v2.pdf',200000,'c7d8e9f0a1b2'),
    (28,22,2,'/uploads/docs/DOC-2026-0022_v2.pdf',150000,'d8e9f0a1b2c3'),
    (29,8,2,'/uploads/docs/DOC-2026-0008_v2.pdf',225000,'e9f0a1b2c3d4'),
    (30,14,2,'/uploads/docs/DOC-2026-0014_v2.pdf',215000,'f0a1b2c3d4e5');

-- =====================================================================
-- 19. CONTRACT DOCUMENTS (20)  [FK → contracts 1–25 ✅, documents 1–25 ✅]
-- =====================================================================
INSERT INTO team8_contract_documents (id, contract_id, document_id) VALUES
    (1,1,1),(2,2,2),(3,3,14),(4,4,8),(5,5,14),(6,6,8),(7,7,16),
    (8,8,16),(9,9,23),(10,10,19),(11,11,1),(12,12,23),(13,13,16),
    (14,14,15),(15,15,17),(16,16,1),(17,17,21),(18,18,17),
    (19,19,16),(20,20,15);

-- =====================================================================
-- 20. RECORDS (25)  [FK → schedules, users ✅]
-- =====================================================================
INSERT INTO team8_records
    (id, entity_type, entity_id, schedule_id, retention_basis, retention_years,
     retention_start_date, custodian_id, disposition_date, status,
     archived_at, archive_reason, disposed_at, disposal_reason,
     disposal_requested_by, disposal_requested_at,
     disposal_authorized_by, disposal_authorized_at) VALUES
    (1,'document',1,2,'Finance retention',7,'2026-01-01',6,'2033-01-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (2,'contract',3,3,'Legal retention',10,'2026-03-01',6,'2036-03-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (3,'document',7,1,'Admin records',5,'2020-01-01',6,'2025-01-01','archived','2025-06-01 09:00:00','Retention elapsed.',NULL,NULL,NULL,NULL,NULL,NULL),
    (4,'legal_case',1,3,'Legal filings',10,'2026-05-01',6,'2036-05-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (5,'document',2,2,'Finance retention',7,'2026-02-01',6,'2033-02-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (6,'document',4,2,'Finance retention',7,'2026-07-01',6,'2033-07-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (7,'document',5,1,'HR retention',5,'2026-01-01',6,'2031-01-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (8,'document',6,3,'Facilities retention',10,'2026-01-01',6,'2036-01-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (9,'document',8,3,'Legal retention',10,'2026-01-01',6,'2036-01-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (10,'document',9,3,'Compliance retention',10,'2026-01-01',6,'2036-01-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (11,'document',10,2,'Finance retention',7,'2026-01-01',6,'2033-01-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (12,'document',11,1,'HR retention',5,'2026-01-01',6,'2031-01-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (13,'document',12,3,'Facilities retention',10,'2026-01-01',6,'2036-01-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (14,'document',13,1,'Admin records',5,'2026-01-01',6,'2031-01-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (15,'document',14,3,'Legal retention',10,'2026-01-01',6,'2036-01-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (16,'document',15,2,'Inventory retention',7,'2026-01-01',6,'2033-01-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (17,'document',16,3,'IT retention',10,'2026-01-01',6,'2036-01-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (18,'document',17,3,'Legal retention',10,'2026-01-01',6,'2036-01-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (19,'document',18,3,'Security retention',10,'2026-01-01',6,'2036-01-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (20,'document',19,2,'Finance retention',7,'2026-01-01',6,'2033-01-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (21,'document',20,1,'HR retention',5,'2026-01-01',6,'2031-01-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (22,'document',21,3,'Facilities retention',10,'2026-01-01',6,'2036-01-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (23,'document',22,1,'Admin records',5,'2026-01-01',6,'2031-01-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (24,'document',23,2,'Marketing retention',7,'2026-01-01',6,'2033-01-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
    (25,'document',24,3,'Legal retention',10,'2026-01-01',6,'2036-01-01','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL);

-- =====================================================================
-- 21. COMPLIANCE CHECKS (25)  [FK → records 1–25 ✅, users ✅]
-- =====================================================================
INSERT INTO team8_compliance_checks
    (id, record_id, checked_by, check_date, result, notes) VALUES
    (1,1,6,'2026-09-01','compliant','All financial docs complete.'),
    (2,2,6,'2026-09-05','compliant','Legal retainer valid.'),
    (3,3,6,'2026-09-10','non_compliant','Missing board resolution.'),
    (4,4,6,'2026-09-15','pending','Awaiting court documents.'),
    (5,5,6,'2026-09-16','compliant','Ok.'),
    (6,6,6,'2026-09-17','compliant','Ok.'),
    (7,7,6,'2026-09-18','compliant','Ok.'),
    (8,8,6,'2026-09-19','compliant','Ok.'),
    (9,9,6,'2026-09-20','compliant','Ok.'),
    (10,10,6,'2026-09-21','compliant','Ok.'),
    (11,11,6,'2026-09-22','non_compliant','Missing signature.'),
    (12,12,6,'2026-09-23','compliant','Ok.'),
    (13,13,6,'2026-09-24','compliant','Ok.'),
    (14,14,6,'2026-09-25','compliant','Ok.'),
    (15,15,6,'2026-09-26','pending','Under review.'),
    (16,16,6,'2026-09-27','compliant','Ok.'),
    (17,17,6,'2026-09-28','compliant','Ok.'),
    (18,18,6,'2026-09-29','non_compliant','Outdated version.'),
    (19,19,6,'2026-09-30','compliant','Ok.'),
    (20,20,6,'2026-10-01','compliant','Ok.'),
    (21,21,6,'2026-10-02','compliant','Ok.'),
    (22,22,6,'2026-10-03','pending','Awaiting input.'),
    (23,23,6,'2026-10-04','compliant','Ok.'),
    (24,24,6,'2026-10-05','compliant','Ok.'),
    (25,25,6,'2026-10-06','compliant','Ok.');

-- =====================================================================
-- 22. COMPLIANCE REPORTS (22)  [FK → departments, users, documents ✅]
-- =====================================================================
INSERT INTO team8_compliance_reports
    (id, report_type, department_id, date_from, date_to, executive_summary,
     key_findings, check_details, generated_by, status, approved_by,
     approved_at, document_id, recipient_emails, email_sent_at) VALUES
    (1,'Quarterly Compliance Report',1,'2026-07-01','2026-09-30','Satisfactory.','One gap.','[]',6,'approved',1,'2026-09-25 10:00:00',3,'admin@example.local','2026-09-25 10:05:00'),
    (2,'Monthly Compliance Report',2,'2026-09-01','2026-09-30','Ok.','None.','[]',6,'approved',1,'2026-10-01 10:00:00',4,'finance@example.local','2026-10-01 10:05:00'),
    (3,'Monthly Compliance Report',3,'2026-09-01','2026-09-30','Ok.','None.','[]',6,'approved',1,'2026-10-02 10:00:00',5,'hr@example.local','2026-10-02 10:05:00'),
    (4,'Monthly Compliance Report',4,'2026-09-01','2026-09-30','Ok.','Minor.','[]',6,'approved',1,'2026-10-03 10:00:00',16,'it.admin@example.local','2026-10-03 10:05:00'),
    (5,'Monthly Compliance Report',5,'2026-09-01','2026-09-30','Ok.','None.','[]',6,'approved',1,'2026-10-04 10:00:00',17,'legal@example.local','2026-10-04 10:05:00'),
    (6,'Monthly Compliance Report',6,'2026-09-01','2026-09-30','Ok.','None.','[]',6,'approved',1,'2026-10-05 10:00:00',21,'operations@example.local','2026-10-05 10:05:00'),
    (7,'Monthly Compliance Report',7,'2026-09-01','2026-09-30','Ok.','None.','[]',6,'approved',1,'2026-10-06 10:00:00',15,'procurement@example.local','2026-10-06 10:05:00'),
    (8,'Monthly Compliance Report',8,'2026-09-01','2026-09-30','Ok.','None.','[]',6,'approved',1,'2026-10-07 10:00:00',12,'facilities@example.local','2026-10-07 10:05:00'),
    (9,'Monthly Compliance Report',9,'2026-09-01','2026-09-30','Ok.','None.','[]',6,'approved',1,'2026-10-08 10:00:00',18,'security@example.local','2026-10-08 10:05:00'),
    (10,'Monthly Compliance Report',10,'2026-09-01','2026-09-30','Ok.','None.','[]',6,'approved',1,'2026-10-09 10:00:00',23,'marketing@example.local','2026-10-09 10:05:00'),
    (11,'Monthly Compliance Report',11,'2026-09-01','2026-09-30','Ok.','None.','[]',6,'approved',1,'2026-10-10 10:00:00',24,'service@example.local','2026-10-10 10:05:00'),
    (12,'Quarterly Compliance Report',1,'2026-04-01','2026-06-30','Ok.','None.','[]',6,'approved',1,'2026-07-05 10:00:00',3,'admin@example.local','2026-07-05 10:05:00'),
    (13,'Quarterly Compliance Report',2,'2026-04-01','2026-06-30','Ok.','None.','[]',6,'approved',1,'2026-07-06 10:00:00',4,'finance@example.local','2026-07-06 10:05:00'),
    (14,'Quarterly Compliance Report',3,'2026-04-01','2026-06-30','Ok.','None.','[]',6,'approved',1,'2026-07-07 10:00:00',5,'hr@example.local','2026-07-07 10:05:00'),
    (15,'Quarterly Compliance Report',4,'2026-04-01','2026-06-30','Ok.','None.','[]',6,'approved',1,'2026-07-08 10:00:00',16,'it.admin@example.local','2026-07-08 10:05:00'),
    (16,'Quarterly Compliance Report',5,'2026-04-01','2026-06-30','Ok.','None.','[]',6,'approved',1,'2026-07-09 10:00:00',17,'legal@example.local','2026-07-09 10:05:00'),
    (17,'Quarterly Compliance Report',6,'2026-04-01','2026-06-30','Ok.','None.','[]',6,'approved',1,'2026-07-10 10:00:00',21,'operations@example.local','2026-07-10 10:05:00'),
    (18,'Quarterly Compliance Report',7,'2026-04-01','2026-06-30','Ok.','None.','[]',6,'approved',1,'2026-07-11 10:00:00',15,'procurement@example.local','2026-07-11 10:05:00'),
    (19,'Quarterly Compliance Report',8,'2026-04-01','2026-06-30','Ok.','None.','[]',6,'approved',1,'2026-07-12 10:00:00',12,'facilities@example.local','2026-07-12 10:05:00'),
    (20,'Quarterly Compliance Report',9,'2026-04-01','2026-06-30','Ok.','None.','[]',6,'approved',1,'2026-07-13 10:00:00',18,'security@example.local','2026-07-13 10:05:00'),
    (21,'Quarterly Compliance Report',10,'2026-04-01','2026-06-30','Ok.','None.','[]',6,'approved',1,'2026-07-14 10:00:00',23,'marketing@example.local','2026-07-14 10:05:00'),
    (22,'Quarterly Compliance Report',11,'2026-04-01','2026-06-30','Ok.','None.','[]',6,'draft',NULL,NULL,NULL,NULL,NULL);

INSERT INTO team8_compliance_report_documents (compliance_report_id, document_id) VALUES
    (1,3),(1,6),(2,4),(3,5),(4,16),(5,17),(6,21),(7,15),(8,12),(9,18),
    (10,23),(11,24),(12,3),(13,4),(14,5),(15,16),(16,17),(17,21),
    (18,15),(19,12),(20,18),(21,23),(22,24);

-- =====================================================================
-- 23. INCIDENT REPORTS (22)  [FK → users ✅, departments ✅]
--     All employee_id and prepared_by values are users 1–25 ✅
-- =====================================================================
INSERT INTO team8_incident_reports
    (id, document_number, employee_id, prepared_by, department_id, status,
     incident_date, incident_time, incident_location, incident_type,
     description, witness, current_version) VALUES
    (1,'IR-2026-0001',5,11,3,'approved','2026-09-05','14:30:00','Main Building','Tardiness','Arrived 45 min late.','Frontdesk Fred',1),
    (2,'IR-2026-0002',10,11,6,'pending','2026-09-18','10:15:00','Annex Building','Policy Violation','Unauthorized vehicle use.','Security Sam',1),
    (3,'IR-2026-0003',13,11,10,'approved','2026-09-20','09:00:00','Main Building','Tardiness','30 min late.','Frontdesk Fred',1),
    (4,'IR-2026-0004',15,11,4,'approved','2026-09-22','11:00:00','Main Building','Absence','No show.','IT Ivan',1),
    (5,'IR-2026-0005',17,11,2,'rejected','2026-09-25','15:00:00','Main Building','Policy Violation','Alleged misuse.','Finance Felix',1),
    (6,'IR-2026-0006',19,11,5,'approved','2026-09-28','10:00:00','Main Building','Tardiness','20 min late.','Legal Lena',1),
    (7,'IR-2026-0007',20,11,8,'approved','2026-09-30','08:30:00','Annex Building','Absence','No show.','Facilities Fran',1),
    (8,'IR-2026-0008',13,11,10,'pending','2026-10-01','14:00:00','Main Building','Policy Violation','Smoking.','Security Sam',1),
    (9,'IR-2026-0009',14,11,11,'approved','2026-10-02','09:15:00','Main Building','Tardiness','25 min late.','Operations Omar',1),
    (10,'IR-2026-0010',16,11,4,'approved','2026-10-03','13:00:00','Main Building','Policy Violation','Late report.','IT Ivan',1),
    (11,'IR-2026-0011',18,11,2,'approved','2026-10-04','22:00:00','Outdoor Grounds','Policy Violation','Sleeping.','Security Sam',1),
    (12,'IR-2026-0012',20,11,8,'pending','2026-10-05','10:30:00','Main Building','Tardiness','15 min late.','Frontdesk Fred',1),
    (13,'IR-2026-0013',5,11,3,'approved','2026-10-06','11:00:00','Main Building','Absence','No show.','HR Hannah',1),
    (14,'IR-2026-0014',7,11,2,'approved','2026-10-07','09:45:00','Main Building','Tardiness','30 min late.','Finance Felix',1),
    (15,'IR-2026-0015',15,11,4,'approved','2026-10-08','14:15:00','Main Building','Policy Violation','Unapproved software.','IT Ivan',1),
    (16,'IR-2026-0016',12,11,9,'approved','2026-10-09','06:30:00','Outdoor Grounds','Tardiness','20 min late.','Security Sam',1),
    (17,'IR-2026-0017',14,11,11,'approved','2026-10-10','08:00:00','Main Building','Absence','No show.','Service Sofia',1),
    (18,'IR-2026-0018',16,11,4,'pending','2026-10-11','10:00:00','Main Building','Policy Violation','Unauthorized access.','IT Ivan',1),
    (19,'IR-2026-0019',18,11,2,'approved','2026-10-12','09:30:00','Main Building','Tardiness','10 min late.','Auditor Alex',1),
    (20,'IR-2026-0020',10,11,6,'approved','2026-10-13','13:45:00','Annex Building','Policy Violation','Missed deadline.','Operations Omar',1),
    (21,'IR-2026-0021',13,11,10,'rejected','2026-10-14','11:00:00','Main Building','Tardiness','Duplicate.','Marketing Mia',1),
    (22,'IR-2026-0022',20,11,8,'approved','2026-10-15','15:30:00','Main Building','Tardiness','20 min late.','Facilities Fran',1);

-- =====================================================================
-- 24. NOTICE TO EXPLAIN (20)  [FK → incident_reports 1–22 ✅, users ✅]
-- =====================================================================
INSERT INTO team8_notice_to_explain
    (id, document_number, incident_report_id, employee_id, prepared_by,
     status, deadline, remarks, current_version) VALUES
    (1,'NTE-2026-0001',1,5,11,'approved','2026-09-20','Explain in 5 days.',1),
    (2,'NTE-2026-0002',2,10,11,'pending','2026-10-01','Explain in 5 days.',1),
    (3,'NTE-2026-0003',3,13,11,'approved','2026-10-05','Explain in 5 days.',1),
    (4,'NTE-2026-0004',4,15,11,'approved','2026-10-06','Explain in 5 days.',1),
    (5,'NTE-2026-0005',5,17,11,'rejected','2026-10-08','Explain in 5 days.',1),
    (6,'NTE-2026-0006',6,19,11,'approved','2026-10-10','Explain in 5 days.',1),
    (7,'NTE-2026-0007',7,20,11,'approved','2026-10-12','Explain in 5 days.',1),
    (8,'NTE-2026-0008',8,13,11,'pending','2026-10-14','Explain in 5 days.',1),
    (9,'NTE-2026-0009',9,14,11,'approved','2026-10-15','Explain in 5 days.',1),
    (10,'NTE-2026-0010',10,16,11,'approved','2026-10-16','Explain in 5 days.',1),
    (11,'NTE-2026-0011',11,18,11,'approved','2026-10-17','Explain in 5 days.',1),
    (12,'NTE-2026-0012',12,20,11,'pending','2026-10-18','Explain in 5 days.',1),
    (13,'NTE-2026-0013',13,5,11,'approved','2026-10-19','Explain in 5 days.',1),
    (14,'NTE-2026-0014',14,7,11,'approved','2026-10-20','Explain in 5 days.',1),
    (15,'NTE-2026-0015',15,15,11,'approved','2026-10-21','Explain in 5 days.',1),
    (16,'NTE-2026-0016',16,12,11,'approved','2026-10-22','Explain in 5 days.',1),
    (17,'NTE-2026-0017',17,14,11,'approved','2026-10-23','Explain in 5 days.',1),
    (18,'NTE-2026-0018',18,16,11,'pending','2026-10-24','Explain in 5 days.',1),
    (19,'NTE-2026-0019',19,18,11,'approved','2026-10-25','Explain in 5 days.',1),
    (20,'NTE-2026-0020',20,10,11,'approved','2026-10-26','Explain in 5 days.',1);

-- =====================================================================
-- 25. EXPLANATIONS (20)  [FK → NTE 1–20 ✅, users 1–25 ✅]
--     reviewer_id = 11 (HR Hannah) exists ✅
--     employee_ids: 5,7,9,10,12,13,14,15,16,17,18,19,20 — all exist ✅
-- =====================================================================
INSERT INTO team8_explanations
    (id, nte_id, employee_id, explanation_text, attachment_path, status,
     admin_remarks, reviewed_by, reviewed_at) VALUES
    (1,1,5,'Medical emergency.','/uploads/expl/EXP-2026-0001.pdf','accepted','Verified.','2026-09-22 09:00:00',11),
    (2,2,10,'Traffic congestion.','/uploads/expl/EXP-2026-0002.pdf','pending',NULL,NULL,NULL),
    (3,3,13,'Family emergency.','/uploads/expl/EXP-2026-0003.pdf','accepted','Verified.','2026-10-07 09:00:00',11),
    (4,4,15,'Sick leave.','/uploads/expl/EXP-2026-0004.pdf','accepted','Medical cert.','2026-10-08 09:00:00',11),
    (5,5,17,'Denies allegation.','/uploads/expl/EXP-2026-0005.pdf','rejected','Insufficient.','2026-10-10 09:00:00',11),
    (6,6,19,'Traffic.','/uploads/expl/EXP-2026-0006.pdf','accepted','Verified.','2026-10-12 09:00:00',11),
    (7,7,20,'Sick.','/uploads/expl/EXP-2026-0007.pdf','accepted','Verified.','2026-10-14 09:00:00',11),
    (8,8,13,'Did not know policy.','/uploads/expl/EXP-2026-0008.pdf','pending',NULL,NULL,NULL),
    (9,9,14,'Traffic.','/uploads/expl/EXP-2026-0009.pdf','accepted','Verified.','2026-10-17 09:00:00',11),
    (10,10,16,'Forgot deadline.','/uploads/expl/EXP-2026-0010.pdf','accepted','Verified.','2026-10-18 09:00:00',11),
    (11,11,18,'Fatigue.','/uploads/expl/EXP-2026-0011.pdf','accepted','Verified.','2026-10-19 09:00:00',11),
    (12,12,20,'Traffic.','/uploads/expl/EXP-2026-0012.pdf','pending',NULL,NULL,NULL),
    (13,13,5,'Sick.','/uploads/expl/EXP-2026-0013.pdf','accepted','Verified.','2026-10-21 09:00:00',11),
    (14,14,7,'Traffic.','/uploads/expl/EXP-2026-0014.pdf','accepted','Verified.','2026-10-22 09:00:00',11),
    (15,15,15,'Testing software.','/uploads/expl/EXP-2026-0015.pdf','accepted','Verified.','2026-10-23 09:00:00',11),
    (16,16,12,'Traffic.','/uploads/expl/EXP-2026-0016.pdf','accepted','Verified.','2026-10-24 09:00:00',11),
    (17,17,14,'Sick.','/uploads/expl/EXP-2026-0017.pdf','accepted','Verified.','2026-10-25 09:00:00',11),
    (18,18,16,'Testing system.','/uploads/expl/EXP-2026-0018.pdf','pending',NULL,NULL,NULL),
    (19,19,18,'Traffic.','/uploads/expl/EXP-2026-0019.pdf','accepted','Verified.','2026-10-27 09:00:00',11),
    (20,20,10,'Missed deadline.','/uploads/expl/EXP-2026-0020.pdf','accepted','Verified.','2026-10-28 09:00:00',11);

-- =====================================================================
-- 26. MEMORANDUMS (22)  [FK → users ✅]
-- =====================================================================
INSERT INTO team8_memorandums
    (id, document_number, kind, title, recipients, content, remarks,
     prepared_by, status, current_version) VALUES
    (1,'MEMO-2026-0001','memorandum','New Attendance Policy','All Departments','Effective Oct 1, biometric system.','Cascade to staff.',11,'approved',1),
    (2,'MEMO-2026-0002','memorandum','Annual Fire Drill','All Departments','Nov 15, 2026 at 9AM.','Coordinate with Facilities.',2,'draft',1),
    (3,'MEMO-2026-0003','memorandum','Holiday Schedule 2026','All Departments','List of holidays.','See attached.',11,'approved',1),
    (4,'MEMO-2026-0004','memorandum','IT Security Reminder','All Departments','Do not share passwords.','Strict compliance.',9,'approved',1),
    (5,'MEMO-2026-0005','memorandum','Office Cleaning Schedule','All Departments','Weekly cleaning every Friday.','Coordinate with Facilities.',2,'approved',1),
    (6,'MEMO-2026-0006','memorandum','Q4 Planning Session','All Departments','Oct 5, 9AM-11AM.','Attendance required.',11,'approved',1),
    (7,'MEMO-2026-0007','memorandum','Dress Code Reminder','All Departments','Business casual Mon-Thu.','Effective immediately.',11,'approved',1),
    (8,'MEMO-2026-0008','memorandum','Budget Submission Deadline','All Departments','Submit by Oct 15.','Use new template.',7,'approved',1),
    (9,'MEMO-2026-0009','memorandum','New Hire Orientation','All Departments','Oct 20, 2026.','HR to coordinate.',11,'approved',1),
    (10,'MEMO-2026-0010','memorandum','Parking Guidelines','All Departments','Use designated slots only.','Strict compliance.',12,'approved',1),
    (11,'MEMO-2026-0011','memorandum','System Maintenance Notice','All Departments','Oct 25, 10PM-2AM.','Save work.',9,'approved',1),
    (12,'MEMO-2026-0012','memorandum','Performance Review Schedule','All Departments','Nov 1-30, 2026.','HR to coordinate.',11,'approved',1),
    (13,'MEMO-2026-0013','memorandum','Office Supplies Request','All Departments','Submit via portal.','New process.',8,'approved',1),
    (14,'MEMO-2026-0014','memorandum','Safety Drill Reminder','All Departments','Nov 15, 2026.','Mandatory.',12,'approved',1),
    (15,'MEMO-2026-0015','memorandum','Year-End Party','All Departments','Dec 15, 2026.','RSVP by Dec 1.',11,'approved',1),
    (16,'MEMO-2026-0016','memorandum','New Procurement Process','All Departments','Effective Nov 1.','See attached.',8,'draft',1),
    (17,'MEMO-2026-0017','memorandum','IT Equipment Policy','All Departments','Return upon resignation.','Strict compliance.',9,'approved',1),
    (18,'MEMO-2026-0018','memorandum','Office Renovation Notice','All Departments','Annex wing renovation starts Nov 20.','Expect noise.',2,'approved',1),
    (19,'MEMO-2026-0019','memorandum','Health and Wellness Program','All Departments','Free flu shots Nov 5.','Sign up at HR.',11,'approved',1),
    (20,'MEMO-2026-0020','memorandum','Data Privacy Reminder','All Departments','Handle data per DPA.','Strict compliance.',4,'approved',1),
    (21,'MEMO-2026-0021','memorandum','Emergency Contact Update','All Departments','Update by Oct 30.','HR to collect.',11,'approved',1),
    (22,'MEMO-2026-0022','memorandum','New Vendor Onboarding','All Departments','New process for vendors.','See attached.',8,'draft',1);

INSERT INTO team8_memorandum_recipients (id, memorandum_id, recipient_type, department_id) VALUES
    (1,1,'all_departments',NULL),(2,2,'department',8),(3,2,'department',6),
    (4,3,'all_departments',NULL),(5,4,'all_departments',NULL),(6,5,'all_departments',NULL),
    (7,6,'all_departments',NULL),(8,7,'all_departments',NULL),(9,8,'all_departments',NULL),
    (10,9,'all_departments',NULL),(11,10,'all_departments',NULL),(12,11,'all_departments',NULL),
    (13,12,'all_departments',NULL),(14,13,'all_departments',NULL),(15,14,'all_departments',NULL),
    (16,15,'all_departments',NULL),(17,16,'department',7),(18,17,'all_departments',NULL),
    (19,18,'department',8),(20,19,'all_departments',NULL),(21,20,'all_departments',NULL),
    (22,21,'all_departments',NULL),(23,22,'department',7);

-- =====================================================================
-- 27. CERTIFICATES (22)  [FK → users ✅]
-- =====================================================================
INSERT INTO team8_certificates
    (id, document_number, certificate_type, employee_id, prepared_by,
     details, status, current_version) VALUES
    (1,'CERT-2026-0001','Certificate of Employment',5,11,'Employed since 2020.','approved',1),
    (2,'CERT-2026-0002','Certificate of Compensation',10,7,'Monthly compensation.','pending',1),
    (3,'CERT-2026-0003','Certificate of Employment',13,11,'Employed since 2019.','approved',1),
    (4,'CERT-2026-0004','Certificate of Employment',15,11,'Employed since 2021.','approved',1),
    (5,'CERT-2026-0005','Certificate of Compensation',17,7,'Monthly compensation.','approved',1),
    (6,'CERT-2026-0006','Certificate of Employment',19,11,'Employed since 2018.','approved',1),
    (7,'CERT-2026-0007','Certificate of Employment',20,11,'Employed since 2020.','approved',1),
    (8,'CERT-2026-0008','Certificate of Compensation',21,7,'Monthly compensation.','approved',1),
    (9,'CERT-2026-0009','Certificate of Employment',22,11,'Employed since 2019.','approved',1),
    (10,'CERT-2026-0010','Certificate of Employment',23,11,'Employed since 2021.','approved',1),
    (11,'CERT-2026-0011','Certificate of Compensation',24,7,'Monthly compensation.','approved',1),
    (12,'CERT-2026-0012','Certificate of Employment',25,11,'Employed since 2022.','pending',1),
    (13,'CERT-2026-0013','Certificate of Employment',5,11,'For visa application.','approved',1),
    (14,'CERT-2026-0014','Certificate of Compensation',7,7,'For loan application.','approved',1),
    (15,'CERT-2026-0015','Certificate of Employment',9,11,'For bank application.','approved',1),
    (16,'CERT-2026-0016','Certificate of Employment',12,11,'For passport.','approved',1),
    (17,'CERT-2026-0017','Certificate of Compensation',14,7,'For loan.','approved',1),
    (18,'CERT-2026-0018','Certificate of Employment',16,11,'For school.','pending',1),
    (19,'CERT-2026-0019','Certificate of Employment',18,11,'For visa.','approved',1),
    (20,'CERT-2026-0020','Certificate of Compensation',10,7,'For loan.','approved',1),
    (21,'CERT-2026-0021','Certificate of Employment',13,11,'For bank.','approved',1),
    (22,'CERT-2026-0022','Certificate of Employment',15,11,'For visa.','approved',1);

INSERT INTO team8_certificate_recipients (id, certificate_id, employee_id) VALUES
    (1,1,5),(2,2,10),(3,3,13),(4,4,15),(5,5,17),(6,6,19),(7,7,20),
    (8,8,21),(9,9,22),(10,10,23),(11,11,24),(12,12,25),(13,13,5),
    (14,14,7),(15,15,9),(16,16,12),(17,17,14),(18,18,16),(19,19,18),
    (20,20,10),(21,21,13),(22,22,15);

-- =====================================================================
-- 28. LEGAL CASES (22)  [FK → users ✅, contracts 1–25 ✅, departments ✅]
-- =====================================================================
INSERT INTO team8_legal_cases
    (id, assigned_to, contract_id, title, subject, department_id, status,
     filed_date, deadline, closed_at) VALUES
    (1,4,2,'MPSI vs. Company - Lease Dispute','Contract interpretation',2,'open','2026-05-01','2026-12-31',NULL),
    (2,4,NULL,'Labor Case - Former Employee','Illegal dismissal',3,'open','2026-07-15','2026-11-30',NULL),
    (3,4,3,'Retainer Agreement Review','Contract compliance',5,'closed','2026-03-01','2026-04-01','2026-04-05 15:00:00'),
    (4,19,1,'Supplier Dispute - BOSC','Breach of contract',7,'open','2026-06-01','2026-12-31',NULL),
    (5,19,4,'Janitorial Services Dispute','Service quality',8,'open','2026-06-15','2026-12-15',NULL),
    (6,4,6,'Security Agency Issue','Coverage gap',9,'closed','2026-04-01','2026-05-01','2026-05-05 15:00:00'),
    (7,19,7,'Network Outage Claim','SLA breach',4,'open','2026-07-01','2026-12-31',NULL),
    (8,4,8,'Construction Delay Claim','Delay damages',7,'open','2026-08-01','2027-01-31',NULL),
    (9,19,9,'Marketing Campaign Dispute','Deliverable quality',10,'closed','2026-03-01','2026-04-15','2026-04-20 15:00:00'),
    (10,4,NULL,'Trademark Infringement','IP',10,'open','2026-08-15','2027-02-28',NULL),
    (11,19,NULL,'Data Privacy Complaint','DPA',4,'open','2026-08-20','2026-12-31',NULL),
    (12,4,11,'Catering Dispute','Event issue',7,'open','2026-09-01','2026-12-31',NULL),
    (13,19,NULL,'Employee Grievance','Workplace',3,'closed','2026-05-01','2026-06-01','2026-06-05 15:00:00'),
    (14,4,13,'Internet Service Dispute','SLA',4,'open','2026-09-15','2027-01-31',NULL),
    (15,19,15,'Litigation - Case 2','Labor',5,'open','2026-07-15','2026-11-30',NULL),
    (16,4,NULL,'Tax Assessment Protest','Tax',2,'open','2026-09-20','2027-03-31',NULL),
    (17,19,NULL,'Property Dispute','Real estate',1,'open','2026-09-25','2027-03-31',NULL),
    (18,4,20,'Vehicle Lease Dispute','Lease',8,'open','2026-10-01','2027-04-30',NULL),
    (19,19,NULL,'Insurance Claim','Insurance',1,'closed','2026-06-01','2026-07-01','2026-07-05 15:00:00'),
    (20,4,NULL,'Vendor Payment Dispute','Payment',7,'open','2026-10-05','2027-04-30',NULL),
    (21,19,NULL,'Contract Breach - Skyline','Breach',10,'open','2026-10-10','2027-04-30',NULL),
    (22,4,17,'HVAC Service Dispute','Service quality',8,'closed','2026-05-01','2026-06-01','2026-06-05 15:00:00');

INSERT INTO team8_legal_documents (id, case_id, document_id, description) VALUES
    (1,1,2,'Lease contract copy'),(2,3,14,'Retainer agreement'),
    (3,4,1,'Supply contract'),(4,5,8,'Janitorial contract'),
    (5,6,8,'Security contract'),(6,7,16,'Network contract'),
    (7,8,16,'Construction contract'),(8,9,23,'Marketing contract'),
    (9,12,1,'Catering contract'),(10,14,16,'Internet contract'),
    (11,15,17,'Litigation brief'),(12,18,15,'Vehicle lease'),
    (13,22,21,'HVAC contract');

-- =====================================================================
-- 29. RESERVATIONS (25)  [FK → facilities 1–22 ✅, users 1–25 ✅]
-- =====================================================================
INSERT INTO team8_reservations
    (id, facility_id, user_id, start_time, end_time, status, department_id,
     department, key_person, expected_participants, event_category,
     description, remarks, schedule, requirements) VALUES
    (1,1,5,'2026-10-05 09:00:00','2026-10-05 11:00:00','approved',3,'Human Resources','HR Hannah',15,'Meeting','Q4 HR Planning','Need projector.','2026-10-05 09:00:00','Projector'),
    (2,3,10,'2026-10-20 13:00:00','2026-10-20 17:00:00','pending',6,'Operations','Operations Omar',120,'Company Event','Annual Town Hall','Need PA.','2026-10-20 13:00:00','PA system'),
    (3,2,7,'2026-10-12 08:00:00','2026-10-12 12:00:00','approved',2,'Finance','Finance Felix',30,'Training','Financial Reporting',NULL,'2026-10-12 08:00:00','Projector'),
    (4,4,12,'2026-11-05 15:00:00','2026-11-05 18:00:00','cancelled',9,'Security','Security Sam',20,'Sports','Basketball','Cancelled.','2026-11-05 15:00:00','Scoreboard'),
    (5,6,4,'2026-10-06 10:00:00','2026-10-06 11:00:00','approved',5,'Legal','Legal Lena',8,'Meeting','Case review',NULL,'2026-10-06 10:00:00','Monitor'),
    (6,8,9,'2026-10-07 09:00:00','2026-10-07 12:00:00','approved',4,'IT','IT Ivan',25,'Training','Security training',NULL,'2026-10-07 09:00:00','30 PCs'),
    (7,9,13,'2026-10-08 14:00:00','2026-10-08 17:00:00','approved',10,'Marketing','Marketing Mia',200,'Company Event','Product launch','Need stage.','2026-10-08 14:00:00','Stage, PA'),
    (8,10,11,'2026-10-09 11:00:00','2026-10-09 13:00:00','approved',3,'HR','HR Hannah',50,'Meeting','Employee assembly',NULL,'2026-10-09 11:00:00','Tables'),
    (9,11,3,'2026-10-10 09:00:00','2026-10-10 10:00:00','approved',3,'Front Desk','Frontdesk Fred',10,'Meeting','Visitor briefing',NULL,'2026-10-10 09:00:00','TV'),
    (10,12,9,'2026-10-11 08:00:00','2026-10-11 10:00:00','approved',4,'IT','IT Ivan',4,'Maintenance','Server maintenance',NULL,'2026-10-11 08:00:00','Racks'),
    (11,13,14,'2026-10-13 16:00:00','2026-10-13 20:00:00','approved',11,'Customer Service','Service Sofia',60,'Company Event','Team building',NULL,'2026-10-13 16:00:00','Tents'),
    (12,14,11,'2026-10-14 10:00:00','2026-10-14 11:00:00','approved',3,'HR','HR Hannah',2,'Wellness','Counseling',NULL,'2026-10-14 10:00:00','Sofa'),
    (13,15,11,'2026-10-15 09:00:00','2026-10-15 10:00:00','approved',3,'HR','HR Hannah',1,'Wellness','Lactation',NULL,'2026-10-15 09:00:00','Chair'),
    (14,16,20,'2026-10-16 08:00:00','2026-10-16 17:00:00','approved',8,'Facilities','Facilities Fran',5,'Maintenance','Storage audit',NULL,'2026-10-16 08:00:00','Shelves'),
    (15,17,20,'2026-10-17 08:00:00','2026-10-17 17:00:00','approved',8,'Facilities','Facilities Fran',5,'Maintenance','Storage audit',NULL,'2026-10-17 08:00:00','Shelves'),
    (16,18,10,'2026-10-18 18:00:00','2026-10-18 20:00:00','approved',6,'Operations','Operations Omar',20,'Recreation','Gym session',NULL,'2026-10-18 18:00:00','Equipment'),
    (17,19,11,'2026-10-19 07:00:00','2026-10-19 08:00:00','approved',3,'HR','HR Hannah',10,'Wellness','Prayer',NULL,'2026-10-19 07:00:00','Mats'),
    (18,20,12,'2026-10-20 08:00:00','2026-10-20 18:00:00','approved',9,'Security','Security Sam',30,'Parking','VIP parking',NULL,'2026-10-20 08:00:00','CCTV'),
    (19,21,12,'2026-10-21 08:00:00','2026-10-21 18:00:00','approved',9,'Security','Security Sam',30,'Parking','VIP parking',NULL,'2026-10-21 08:00:00','CCTV'),
    (20,22,12,'2026-10-22 08:00:00','2026-10-22 18:00:00','approved',9,'Security','Security Sam',4,'Security','Gate duty',NULL,'2026-10-22 08:00:00','Radios'),
    (21,1,4,'2026-10-23 14:00:00','2026-10-23 16:00:00','pending',5,'Legal','Legal Lena',10,'Meeting','Case prep',NULL,'2026-10-23 14:00:00','Projector'),
    (22,2,11,'2026-10-24 09:00:00','2026-10-24 12:00:00','pending',3,'HR','HR Hannah',40,'Training','Onboarding',NULL,'2026-10-24 09:00:00','Projector'),
    (23,3,13,'2026-10-25 13:00:00','2026-10-25 17:00:00','pending',10,'Marketing','Marketing Mia',150,'Company Event','Brand launch',NULL,'2026-10-25 13:00:00','Stage'),
    (24,4,10,'2026-10-26 15:00:00','2026-10-26 18:00:00','approved',6,'Operations','Operations Omar',30,'Sports','Volleyball',NULL,'2026-10-26 15:00:00','Net'),
    (25,6,19,'2026-10-27 10:00:00','2026-10-27 11:00:00','approved',5,'Legal','Legal Lena',6,'Meeting','Deposition prep',NULL,'2026-10-27 10:00:00','Monitor');

INSERT INTO team8_reservation_equipment (id, reservation_id, equipment_id, quantity) VALUES
    (1,1,1,1),(2,1,3,1),(3,2,6,2),(4,2,7,2),(5,3,4,1),(6,4,8,1),
    (7,4,9,1),(8,5,13,1),(9,6,14,25),(10,7,16,8),(11,7,17,4),
    (12,8,18,2),(13,9,2,1),(14,10,20,2),(15,11,21,4),(16,12,22,1),
    (17,13,23,1),(18,14,24,2),(19,15,24,2),(20,16,22,4),
    (21,17,25,1),(22,18,24,4),(23,19,24,4),(24,20,24,2),
    (25,21,1,1),(26,22,4,1),(27,23,6,2),(28,24,9,2),(29,25,13,1);

INSERT INTO team8_reservation_approvals
    (id, reservation_id, approver_id, step_order, status, remarks, decided_at) VALUES
    (1,1,2,1,'approved','Ok.','2026-09-28 10:00:00'),
    (2,2,2,1,'pending',NULL,NULL),
    (3,3,2,1,'approved','Ok.','2026-09-30 14:00:00'),
    (4,4,2,1,'rejected','Cancelled.','2026-10-30 09:00:00'),
    (5,5,2,1,'approved','Ok.','2026-10-01 10:00:00'),
    (6,6,2,1,'approved','Ok.','2026-10-02 10:00:00'),
    (7,7,2,1,'approved','Ok.','2026-10-03 10:00:00'),
    (8,8,2,1,'approved','Ok.','2026-10-04 10:00:00'),
    (9,9,2,1,'approved','Ok.','2026-10-05 10:00:00'),
    (10,10,2,1,'approved','Ok.','2026-10-06 10:00:00'),
    (11,11,2,1,'approved','Ok.','2026-10-07 10:00:00'),
    (12,12,2,1,'approved','Ok.','2026-10-08 10:00:00'),
    (13,13,2,1,'approved','Ok.','2026-10-09 10:00:00'),
    (14,14,2,1,'approved','Ok.','2026-10-10 10:00:00'),
    (15,15,2,1,'approved','Ok.','2026-10-11 10:00:00'),
    (16,16,2,1,'approved','Ok.','2026-10-12 10:00:00'),
    (17,17,2,1,'approved','Ok.','2026-10-13 10:00:00'),
    (18,18,2,1,'approved','Ok.','2026-10-14 10:00:00'),
    (19,19,2,1,'approved','Ok.','2026-10-15 10:00:00'),
    (20,20,2,1,'approved','Ok.','2026-10-16 10:00:00'),
    (21,21,2,1,'pending',NULL,NULL),
    (22,22,2,1,'pending',NULL,NULL),
    (23,23,2,1,'pending',NULL,NULL),
    (24,24,2,1,'approved','Ok.','2026-10-20 10:00:00'),
    (25,25,2,1,'approved','Ok.','2026-10-21 10:00:00');

INSERT INTO team8_reservation_cancellation_requests
    (id, reservation_id, requested_by, reason, status, requested_at,
     reviewed_by, reviewed_at, admin_remark) VALUES
    (1,4,12,'Heavy rain forecast.','approved','2026-10-29 16:00:00',2,'2026-10-30 09:00:00','Approved.');

-- =====================================================================
-- 30. VISITORS (25)  [FK → users ✅]
-- =====================================================================
INSERT INTO team8_visitors
    (id, full_name, visitor_type, contact, company, person_to_visit,
     purpose, scheduled_date, status, check_in_time, check_out_time, logged_by) VALUES
    (1,'Juan Dela Cruz','Supplier','+63-917-111-2222','Bright Office Supplies','Procurement Pete','Deliver supplies','2026-10-01 09:00:00','checked_out','2026-10-01 09:05:00','2026-10-01 10:30:00',3),
    (2,'Maria Clara','Client','+63-917-333-4444','MPSI','Legal Lena','Contract signing','2026-10-02 14:00:00','checked_in','2026-10-02 13:55:00',NULL,3),
    (3,'Pedro Penduko','Applicant','+63-917-555-6666',NULL,'HR Hannah','Interview','2026-10-03 10:00:00','scheduled',NULL,NULL,3),
    (4,'Ana Reyes','Government','+63-917-777-8888','BIR','Finance Felix','Tax audit','2026-10-04 08:30:00','scheduled',NULL,NULL,3),
    (5,'Luis Tan','Client','+63-917-999-0000','TechLease','IT Ivan','Demo','2026-10-05 10:00:00','checked_out','2026-10-05 10:00:00','2026-10-05 11:30:00',3),
    (6,'Grace Lim','Supplier','+63-918-111-2222','SafeGuard','Security Sam','Meeting','2026-10-06 14:00:00','checked_out','2026-10-06 14:00:00','2026-10-06 15:00:00',3),
    (7,'Mark Ong','Client','+63-918-333-4444','PrimeBuild','Facilities Fran','Site visit','2026-10-07 09:00:00','checked_out','2026-10-07 09:00:00','2026-10-07 11:00:00',3),
    (8,'Nina Cruz','Supplier','+63-918-555-6666','WaterPure','Facilities Fran','Water testing','2026-10-08 10:00:00','checked_out','2026-10-08 10:00:00','2026-10-08 11:00:00',3),
    (9,'Ben Uy','Supplier','+63-918-777-8888','AirCare','Facilities Fran','HVAC check','2026-10-09 13:00:00','checked_out','2026-10-09 13:00:00','2026-10-09 15:00:00',3),
    (10,'Rica Gomez','Client','+63-918-999-0000','Skyline','Marketing Mia','Ad review','2026-10-10 15:00:00','checked_out','2026-10-10 15:00:00','2026-10-10 16:00:00',3),
    (11,'Dr. Ben Cruz','Client','+63-919-111-2222','MedCare','HR Hannah','Medical check','2026-10-11 08:00:00','checked_out','2026-10-11 08:00:00','2026-10-11 12:00:00',3),
    (12,'Chef Mia Reyes','Supplier','+63-919-333-4444','FoodCraft','HR Hannah','Catering','2026-10-12 11:00:00','checked_out','2026-10-12 11:00:00','2026-10-12 13:00:00',3),
    (13,'Paul Sy','Supplier','+63-919-555-6666','PrintWorks','Marketing Mia','Print delivery','2026-10-13 14:00:00','checked_out','2026-10-13 14:00:00','2026-10-13 15:00:00',3),
    (14,'Carlo Tan','Supplier','+63-919-777-8888','NetConnect','IT Ivan','Network check','2026-10-14 09:00:00','checked_out','2026-10-14 09:00:00','2026-10-14 10:30:00',3),
    (15,'Elena Lim','Supplier','+63-919-999-0000','FurnitureHub','Procurement Pete','Furniture delivery','2026-10-15 13:00:00','checked_out','2026-10-15 13:00:00','2026-10-15 15:00:00',3),
    (16,'Atty. Rey Santos','Client','+63-920-111-2222','LegalEdge','Legal Lena','Meeting','2026-10-16 10:00:00','checked_out','2026-10-16 10:00:00','2026-10-16 11:30:00',3),
    (17,'Atty. Patricia Lim','Client','+63-920-333-4444',NULL,'Legal Lena','Consultation','2026-10-17 14:00:00','checked_out','2026-10-17 14:00:00','2026-10-17 15:00:00',3),
    (18,'Dr. Ramon Santos','Client','+63-920-555-6666',NULL,'HR Hannah','Medical','2026-10-18 09:00:00','scheduled',NULL,NULL,3),
    (19,'Engr. Karlo Reyes','Supplier','+63-920-777-8888',NULL,'Facilities Fran','Inspection','2026-10-19 10:00:00','scheduled',NULL,NULL,3),
    (20,'Ms. Diane Cruz','Client','+63-920-999-0000',NULL,'Operations Omar','Consulting','2026-10-20 14:00:00','scheduled',NULL,NULL,3),
    (21,'Mr. Eric Tan','Client','+63-921-111-2222',NULL,'Finance Felix','Advisory','2026-10-21 09:00:00','scheduled',NULL,NULL,3),
    (22,'Aisha Cruz','Supplier','+63-921-333-4444','CleanPro','Facilities Fran','Cleaning demo','2026-10-22 10:00:00','scheduled',NULL,NULL,3),
    (23,'Ryan Lim','Supplier','+63-921-555-6666','TechLease','IT Ivan','Lease renewal','2026-10-23 14:00:00','scheduled',NULL,NULL,3),
    (24,'Liza Tan','Supplier','+63-921-777-8888','SafeGuard','Security Sam','Contract discussion','2026-10-24 09:00:00','scheduled',NULL,NULL,3),
    (25,'Mark Villanueva','Supplier','+63-921-999-0000','PrimeBuild','Facilities Fran','Renovation update','2026-10-25 10:00:00','scheduled',NULL,NULL,3);

-- =====================================================================
-- 31. RENEWAL REQUESTS (20)  [FK → users ✅]
-- =====================================================================
INSERT INTO renewal_requests
    (id, main_document_type, status, initiated_by, notes) VALUES
    (1,'Mayor''s Business Permit','in_progress',6,'Gathering prerequisites.'),
    (2,'Sanitary Permit','in_progress',6,'Awaiting water test.'),
    (3,'Fire Safety Inspection Certificate','completed',6,'All submitted.'),
    (4,'Mayor''s Business Permit','completed',6,'Renewed for 2027.'),
    (5,'Sanitary Permit','completed',6,'Renewed for 2027.'),
    (6,'Fire Safety Inspection Certificate','completed',6,'Renewed for 2027.'),
    (7,'Mayor''s Business Permit','in_progress',6,'For 2028.'),
    (8,'Sanitary Permit','in_progress',6,'For 2028.'),
    (9,'Fire Safety Inspection Certificate','in_progress',6,'For 2028.'),
    (10,'Mayor''s Business Permit','completed',6,'2026 renewal.'),
    (11,'Sanitary Permit','completed',6,'2026 renewal.'),
    (12,'Fire Safety Inspection Certificate','completed',6,'2026 renewal.'),
    (13,'Mayor''s Business Permit','in_progress',6,'2029 prep.'),
    (14,'Sanitary Permit','in_progress',6,'2029 prep.'),
    (15,'Fire Safety Inspection Certificate','in_progress',6,'2029 prep.'),
    (16,'Mayor''s Business Permit','completed',6,'2025 renewal.'),
    (17,'Sanitary Permit','completed',6,'2025 renewal.'),
    (18,'Fire Safety Inspection Certificate','completed',6,'2025 renewal.'),
    (19,'Mayor''s Business Permit','in_progress',6,'2030 prep.'),
    (20,'Sanitary Permit','in_progress',6,'2030 prep.');

-- =====================================================================
-- 32. NOTIFICATIONS (30)  [FK → users 1–25 ✅]
-- =====================================================================
INSERT INTO notifications (id, user_id, message, target_url, status) VALUES
    (1,1,'Contract CON-2026-0002 pending approval.','/contracts/2','unread'),
    (2,4,'New legal case assigned.','/legal-cases/1','unread'),
    (3,2,'Reservation #2 pending approval.','/reservations/2','read'),
    (4,6,'Compliance report approved.','/compliance-reports/1','read'),
    (5,5,'Explanation accepted.','/explanations/1','read'),
    (6,11,'Incident report pending review.','/incident-reports/2','unread'),
    (7,1,'Contract CON-2026-0004 ready for signing.','/contracts/4','unread'),
    (8,4,'Legal case #4 opened.','/legal-cases/4','unread'),
    (9,2,'Reservation #21 pending approval.','/reservations/21','unread'),
    (10,6,'Compliance check #3 flagged non-compliant.','/compliance-checks/3','unread'),
    (11,11,'NTE-2026-0002 pending.','/nte/2','unread'),
    (12,5,'NTE-2026-0001 deadline approaching.','/nte/1','read'),
    (13,1,'Memo MEMO-2026-0002 pending approval.','/memos/2','unread'),
    (14,7,'Certificate CERT-2026-0002 pending.','/certificates/2','unread'),
    (15,13,'Reservation #23 pending.','/reservations/23','unread'),
    (16,19,'Legal case #15 assigned.','/legal-cases/15','unread'),
    (17,12,'Visitor Maria Clara still checked in.','/visitors/2','unread'),
    (18,6,'Record #3 archived.','/records/3','read'),
    (19,2,'Facility #3 maintenance due.','/facilities/3','unread'),
    (20,9,'Document DOC-2026-0018 pending review.','/documents/18','unread'),
    (21,11,'Explanation #2 submitted.','/explanations/2','unread'),
    (22,1,'New login throttle lockout.','/security/throttle','unread'),
    (23,4,'Legal case #22 closed.','/legal-cases/22','read'),
    (24,8,'Contract CON-2026-0008 milestone due.','/contracts/8','unread'),
    (25,6,'Renewal request #1 in progress.','/renewals/1','read'),
    (26,14,'Reservation #11 approved.','/reservations/11','read'),
    (27,20,'Maintenance task assigned.','/maintenance/26','unread'),
    (28,17,'Financial statement pending signature.','/documents/4','unread'),
    (29,22,'Logistics report due.','/reports/logistics','unread'),
    (30,25,'New support ticket assigned.','/support/1','unread');

-- =====================================================================
-- 33. AUDIT LOGS (30)  [FK → users ✅]
-- =====================================================================
INSERT INTO audit_logs
    (id, user_id, entity_type, entity_id, action, old_value, new_value) VALUES
    (1,1,'contract',1,'status_change','{"status":"approval"}','{"status":"active"}'),
    (2,4,'legal_case',1,'create',NULL,'{"title":"MPSI vs. Company"}'),
    (3,2,'reservation',1,'approve','{"status":"pending"}','{"status":"approved"}'),
    (4,11,'explanation',1,'review','{"status":"pending"}','{"status":"accepted"}'),
    (5,1,'document',3,'approve','{"status":"pending"}','{"status":"approved"}'),
    (6,6,'compliance_report',1,'approve','{"status":"draft"}','{"status":"approved"}'),
    (7,1,'contract',4,'status_change','{"status":"approval"}','{"status":"signing"}'),
    (8,4,'legal_case',4,'create',NULL,'{"title":"Supplier Dispute"}'),
    (9,2,'reservation',3,'approve','{"status":"pending"}','{"status":"approved"}'),
    (10,11,'explanation',3,'review','{"status":"pending"}','{"status":"accepted"}'),
    (11,1,'document',5,'approve','{"status":"pending"}','{"status":"approved"}'),
    (12,6,'compliance_report',2,'approve','{"status":"draft"}','{"status":"approved"}'),
    (13,9,'document',16,'approve','{"status":"pending"}','{"status":"approved"}'),
    (14,11,'memo',1,'approve','{"status":"draft"}','{"status":"approved"}'),
    (15,11,'certificate',1,'approve','{"status":"draft"}','{"status":"approved"}'),
    (16,7,'certificate',5,'approve','{"status":"draft"}','{"status":"approved"}'),
    (17,1,'contract',6,'status_change','{"status":"approval"}','{"status":"active"}'),
    (18,1,'contract',7,'status_change','{"status":"approval"}','{"status":"active"}'),
    (19,1,'contract',8,'status_change','{"status":"approval"}','{"status":"active"}'),
    (20,1,'contract',9,'status_change','{"status":"approval"}','{"status":"active"}'),
    (21,6,'record',3,'archive','{"status":"active"}','{"status":"archived"}'),
    (22,2,'facility',3,'maintenance','{"status":"operational"}','{"status":"maintenance_due"}'),
    (23,12,'reservation',4,'cancel','{"status":"approved"}','{"status":"cancelled"}'),
    (24,4,'legal_case',22,'close','{"status":"open"}','{"status":"closed"}'),
    (25,1,'user',10,'create',NULL,'{"email":"operations@example.local"}'),
    (26,1,'user',11,'create',NULL,'{"email":"hr@example.local"}'),
    (27,9,'document',18,'review','{"status":"pending"}','{"status":"pending"}'),
    (28,6,'compliance_check',3,'flag','{"result":"pending"}','{"result":"non_compliant"}'),
    (29,11,'nte',2,'create',NULL,'{"document_number":"NTE-2026-0002"}'),
    (30,11,'incident_report',22,'create',NULL,'{"document_number":"IR-2026-0022"}');

-- =====================================================================
-- 34. LOGIN THROTTLE  [no FKs]
-- =====================================================================
INSERT INTO team8_login_throttle (identifier, attempts, locked_until) VALUES
    ('attacker@example.local', 5, '2026-10-10 12:00:00'),
    ('spammer@example.local', 3, NULL);

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- END OF SEED
-- =====================================================================