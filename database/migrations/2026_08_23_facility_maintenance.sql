ALTER TABLE team8_facilities
    ADD COLUMN equipment_notes TEXT NULL AFTER description,
    ADD COLUMN maintenance_status VARCHAR(30) NOT NULL DEFAULT 'operational' AFTER equipment_notes,
    ADD COLUMN next_maintenance_date DATE NULL AFTER maintenance_status;

CREATE TABLE IF NOT EXISTS team8_facility_maintenance_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    facility_id INT NOT NULL,
    performed_by INT NOT NULL,
    maintenance_date DATE NOT NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_t8_maintenance_facility FOREIGN KEY (facility_id) REFERENCES team8_facilities(id),
    CONSTRAINT fk_t8_maintenance_user FOREIGN KEY (performed_by) REFERENCES users(id)
) ENGINE=InnoDB;
