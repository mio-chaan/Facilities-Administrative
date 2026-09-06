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
| Legal Management | `team8_legal_cases`, `team8_legal_documents` |
| Contract Management | `team8_contracts`, `team8_parties`, `team8_contract_parties`, `team8_contract_documents`, `team8_contract_history`, `team8_contract_obligations` |
| HR Document Automation | `team8_incident_reports`, `team8_notice_to_explain`, `team8_explanations`, `team8_memorandums`, `team8_certificates`, `team8_hr_document_versions` |

## Design notes

- `team8_legal_cases.contract_id` references `team8_contracts` through a deferred foreign key because legal cases are defined first.
- `team8_documents.file_path` is the current file, while `team8_document_versions.file_path` preserves each historical file.
- Visitor status values are `scheduled`, `checked_in`, `checked_out`, `cancelled`, and `expired`.
- A reservation's free-text reason is stored in `team8_reservations.description`; approver notes are stored in `team8_reservation_approvals.remarks`.
- `schema.sql` is the complete baseline for new installations. Apply the dated scripts in `database/migrations/` to upgrade an existing database.

## Setup

```bash
mysql -u root -p < database/schema.sql
mysql -u root -p < database/seed_account.sql       # optional
mysql -u root -p < database/seed_dummy_data.sql     # optional
```
