-- Facility-type-driven reservation fields.
-- Run once for databases created before this change.

ALTER TABLE team8_reservations
    MODIFY start_time DATETIME NULL,
    MODIFY end_time DATETIME NULL,
    ADD COLUMN quantity INT NULL AFTER expected_participants,
    ADD COLUMN expected_return_date DATE NULL AFTER description,
    ADD COLUMN remarks VARCHAR(500) NULL AFTER expected_return_date,
    ADD COLUMN schedule DATETIME NULL AFTER remarks,
    ADD COLUMN requirements VARCHAR(500) NULL AFTER schedule;
