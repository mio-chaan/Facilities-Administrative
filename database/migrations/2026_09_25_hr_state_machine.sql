-- =========================================================
-- database/migrations/2026_09_25_hr_state_machine.sql
-- Phase 6: HR State Machine
--
-- Resubmission must be allowed after a rejected explanation, so the
-- explanation table cannot enforce a single row per NTE. The server
-- layer handles the active/resubmission rule instead.
-- =========================================================

ALTER TABLE team8_explanations
    DROP INDEX IF EXISTS uq_team8_explanation_nte;

CREATE INDEX idx_team8_expl_nte_status
    ON team8_explanations (nte_id, status);
