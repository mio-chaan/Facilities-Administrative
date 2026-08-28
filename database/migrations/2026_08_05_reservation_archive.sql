-- Reservation archive support. Run after database/schema.sql for existing databases.
ALTER TABLE team8_reservations
    ADD COLUMN archived_at DATETIME NULL AFTER updated_at;

CREATE INDEX idx_team8_reservations_archived_at
    ON team8_reservations (archived_at);
