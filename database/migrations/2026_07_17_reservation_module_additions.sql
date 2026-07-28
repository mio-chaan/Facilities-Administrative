-- 2026_07_17_reservation_module_additions.sql
-- Milestone 3 (Facilities Reservation) schema additions.
-- Only needed if your database was created from an OLDER copy of
-- schema.sql (before these two columns were added there directly).
-- A fresh `mysql < database/schema.sql` import already includes them.

ALTER TABLE team8_reservations
    ADD COLUMN purpose VARCHAR(255) NULL AFTER end_time;

ALTER TABLE team8_reservation_approvals
    ADD COLUMN remarks TEXT NULL AFTER status;
