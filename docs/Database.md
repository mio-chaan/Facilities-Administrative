# Database

## Ownership and conventions

- The database is shared across all 10 capstone teams. Team 8 does not drop or restructure the shared core tables (`users`, `roles`, `departments`, `user_roles`, `notifications`, and `audit_logs`). They are included as `CREATE TABLE IF NOT EXISTS` placeholders so the project can run locally.
- Every Team 8 table is prefixed `team8_` to avoid collisions.
- Team 8 entities use `created_at`, `updated_at`, and, where applicable, `deleted_at` for soft deletion.
- Do not expose the `database/` directory over HTTP. SQL files and database configuration contain sensitive local-development data.

## Modules and tables

| Module | Tables |
|---|---|
| Facilities Reservation | `team8_facilities`, `team8_facility_maintenance_history`, `team8_equipment`, `team8_reservations`, `team8_reservation_equipment`, `team8_reservation_cancellation_requests`, `team8_reservation_approvals` |
| Visitor Management | `team8_visitors` |
| Document Management | `team8_document_categories`, `team8_documents`, `team8_document_versions` |
| Records Retention and Compliance | `team8_retention_schedules`, `team8_records`, `team8_compliance_checks` |
| Legal Management | `team8_legal_case_types`, `team8_legal_case_statuses`, `team8_legal_party_types`, `team8_legal_cases`, `team8_legal_case_parties`, `team8_legal_case_tasks`, `team8_legal_case_hearings`, `team8_legal_case_notes`, `team8_legal_documents`, `team8_legal_case_resolutions`, `team8_legal_case_communications` |
| Contract Management | `team8_contracts`, `team8_parties`, `team8_contract_parties`, `team8_contract_documents`, `team8_contract_history`, `team8_contract_obligations` |
| HR Document Automation | `team8_incident_reports`, `team8_notice_to_explain`, `team8_explanations`, `team8_memorandums`, `team8_memorandum_recipients`, `team8_certificates`, `team8_certificate_recipients`, `team8_hr_document_versions` |

## Design notes

- `team8_legal_cases.contract_id` references `team8_contracts` through a deferred foreign key because legal cases are defined first.
- Legal case types are stored in `team8_legal_case_types`; case statuses are constrained by `team8_legal_case_statuses`. Case types are deactivated rather than deleted, and `Other` is permanent.
- Case status transitions are enforced by the Legal module; a case must be closed before archiving.
- Legal case parties reuse `team8_parties`; `team8_legal_case_parties` stores the case-specific type and role and prevents duplicate links to the same case. The optional `team8_parties.organization` value is shared with Contract Management.
- `team8_legal_case_tasks` stores task status as pending, in progress, completed, or cancelled. Overdue is computed from `due_date` for pending and in-progress tasks and is never stored.
- `team8_legal_case_hearings` stores dated proceedings with optional time, venue, purpose, and notes; supported statuses are scheduled, completed, postponed, and cancelled.
- `team8_legal_case_notes` stores append-only internal notes with their case, author, and database creation timestamp.
- `team8_legal_case_communications` stores manual incoming/outgoing records; an optional attachment references a legal-document link already belonging to that case.
- `team8_legal_case_resolutions` stores one resolution per case and may reference a legal-document link supporting the outcome.
- `team8_legal_documents` links cases to shared `team8_documents` records and stores a legal-specific document type and note; removing a link never deletes the underlying document or versions.
- Legal cases use the `archived` status for module archiving; `archived_from_status` preserves the prior status for restore. Retention-record archival remains separate.
- `team8_documents.file_path` is the current file, while `team8_document_versions.file_path` preserves each historical file.
- Visitor status values are `scheduled`, `late`, `checked_in`, `checked_out`, `cancelled`, and `expired`. Scheduled visits become late after their expected arrival time and expire at the 10:00 PM cutoff if they have not checked in.
- A reservation's free-text reason is stored in `team8_reservations.description`; approver notes are stored in `team8_reservation_approvals.remarks`.
- `schema.sql` is the complete final schema for new installations. Import this file only; do not import the dated migration files afterward.
- Apply the dated scripts in `database/migrations/` only when upgrading an existing database that already contains older Team 8 tables. Those scripts preserve and backfill existing data and are not required for a fresh database.
- Existing databases must apply `2026_09_26_legal_case_catalogs.sql` after earlier Legal migrations; do not run it after importing `schema.sql`.
- Existing databases must apply `2026_09_26_legal_case_parties.sql` after the legal case creation migration; do not run it after importing `schema.sql`.
- Existing databases must apply `2026_09_26_legal_case_information.sql` to add the optional court, docket, jurisdiction, and next-action fields; do not run it after importing `schema.sql`.
- Existing databases must apply `2026_09_26_legal_case_tasks.sql` to add legal task storage; do not run it after importing `schema.sql`.
- Existing databases must apply `2026_09_26_legal_case_hearings.sql` to add hearing and proceeding storage; do not run it after importing `schema.sql`.
- Existing databases must apply `2026_09_26_legal_case_documents.sql` to add the legal-specific document type; legacy links default to `Other`.
- Existing databases must apply `2026_09_26_legal_case_notes.sql` to add append-only legal case notes.
- Existing databases must apply `2026_09_26_legal_case_communications.sql` after the legal-document type migration to add communication records.
- Existing databases must apply `2026_09_26_legal_case_resolutions.sql` after the legal-document type migration to add the resolution workflow.

## Setup

```bash
mysql -u root -p < database/schema.sql
mysql -u root -p < database/seed_account.sql       # optional
mysql -u root -p < database/seed_dummy_data.sql     # optional
```
