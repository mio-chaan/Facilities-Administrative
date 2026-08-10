-- Dedicated records for staff reservation-cancellation requests.
CREATE TABLE IF NOT EXISTS team8_reservation_cancellation_requests (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id  INT NOT NULL,
    requested_by    INT NOT NULL,
    reason          TEXT NOT NULL,
    status          VARCHAR(30) NOT NULL DEFAULT 'pending',
    requested_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by     INT NULL,
    reviewed_at     DATETIME NULL,
    admin_remark    TEXT NULL,
    CONSTRAINT fk_team8_cancel_request_reservation FOREIGN KEY (reservation_id) REFERENCES team8_reservations(id),
    CONSTRAINT fk_team8_cancel_request_requester FOREIGN KEY (requested_by) REFERENCES users(id),
    CONSTRAINT fk_team8_cancel_request_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE INDEX idx_team8_cancel_request_pending
    ON team8_reservation_cancellation_requests (status, requested_at);
