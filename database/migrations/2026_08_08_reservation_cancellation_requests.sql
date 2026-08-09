-- Cancellation-request workflow for existing reservation databases.
ALTER TABLE team8_reservations
    ADD COLUMN cancellation_reason TEXT NULL AFTER archived_at,
    ADD COLUMN cancellation_requested_by INT NULL AFTER cancellation_reason,
    ADD COLUMN cancellation_requested_at DATETIME NULL AFTER cancellation_requested_by,
    ADD COLUMN cancellation_reviewed_by INT NULL AFTER cancellation_requested_at,
    ADD COLUMN cancellation_reviewed_at DATETIME NULL AFTER cancellation_reviewed_by,
    ADD COLUMN cancellation_decision VARCHAR(30) NULL AFTER cancellation_reviewed_at;

CREATE INDEX idx_team8_reservations_cancellation_status
    ON team8_reservations (status, cancellation_requested_at);
