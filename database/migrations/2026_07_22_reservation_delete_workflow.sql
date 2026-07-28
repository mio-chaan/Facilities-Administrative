-- =========================================================
-- database/migrations/2026_07_22_reservation_delete_workflow.sql
-- Reservation Delete Request workflow: users can no longer hard-delete
-- a reservation. Instead they request deletion, an Administrator
-- approves (soft delete via deleted_at) or rejects (status restored).
--
-- Only needed if you're updating an EXISTING database that already
-- ran an older copy of schema.sql. A fresh
-- `mysql < database/schema.sql` import already has these columns.
-- =========================================================

ALTER TABLE team8_reservations
    ADD COLUMN previous_status     VARCHAR(30) NULL AFTER status,
    ADD COLUMN delete_reason       TEXT NULL AFTER previous_status,
    ADD COLUMN delete_requested_by INT NULL AFTER delete_reason,
    ADD COLUMN delete_requested_at DATETIME NULL AFTER delete_requested_by,
    ADD COLUMN rejection_reason    TEXT NULL AFTER delete_requested_at,
    ADD CONSTRAINT fk_team8_reservations_delete_requester
        FOREIGN KEY (delete_requested_by) REFERENCES users(id);

CREATE INDEX idx_team8_reservations_deleted_at ON team8_reservations(deleted_at);
