-- =========================================================
-- database/migrations/2026_07_17_add_facility_management_fields.sql
-- First real migration (see migrations/README.md). Adds the two
-- columns the new Facility Management module (modules/facilities/)
-- needs. Safe to run on an existing database that already has
-- team8_facilities from schema.sql without these columns.
--
-- database/schema.sql has also been updated directly so a FRESH
-- clone/import already includes these columns - this file is only
-- needed if you're updating an existing local database instead of
-- re-importing schema.sql from scratch.
-- =========================================================

ALTER TABLE team8_facilities
    ADD COLUMN description TEXT NULL AFTER capacity,
    ADD COLUMN status ENUM('active','archived') NOT NULL DEFAULT 'active' AFTER description;
