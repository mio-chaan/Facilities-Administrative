# Database

## Ownership & conventions
- The database is **shared across all 10 capstone teams**. Team 8 never
  drops or restructures shared core tables (`users`, `roles`,
  `departments`, `user_roles`, `notifications`, `audit_logs`) — they're
  included in `database/schema.sql` only as `CREATE TABLE IF NOT EXISTS`
  placeholders so this repo can run standalone during local development.
  Before final integration, point the foreign keys at the real shared
  tables and drop the placeholder section.

- Every Team 8 table is prefixed `team8_` to avoid collisions with the
  other 9 teams' tables in the same database.

- Standard audit fields on Team 8 tables: `created_at`, `updated_at`,
  and (where meaningful) `deleted_at` for soft deletes.

- **`database/*.sql` is never meant to be served over HTTP.** Fixed
  (Critical, code review): the README instructs dropping this repo
  straight into `htdocs/`, and with no server-level deny rule,
  `database/seed.sql` (bcrypt hashes + emails of every seeded account)
  and `database/schema.sql` were directly downloadable. Both the root
  `.htaccess` and `database/.htaccess` now deny this tree at the
  web-server level. Don't rely on this alone in production — treat
  `.sql` files as secrets regardless.

- **DB credentials live in `app/config/database.php`, not a `.env`
  file.** Same protection applies: the root `.htaccess` and
  `app/.htaccess` deny direct HTTP access to the whole `app/` tree.
  Edit the `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` constants at the
  top of that file for your local MySQL setup.
  
- **`audit_logs` is now written to**, not just defined in the schema.
  `app/includes/audit.php`'s `t8_audit_log()` currently logs: login,
  logout, 403 (role-denied), and — as of Milestone 3 — reservation
  create/cancel/approve/reject events. More entity types (documents,
  contracts, etc.) should log through the same helper as those modules
  land.

## Modules → tables
| Module | Tables |
|---|---|
| Facilities Reservation | `team8_facilities`, `team8_equipment`, `team8_reservations`, `team8_reservation_equipment`, `team8_reservation_approvals` |
| Visitor Management | `team8_visitors`, `team8_visits` |
| Document Management | `team8_document_categories`, `team8_documents`, `team8_document_versions` |
| Records Retention & Compliance | `team8_retention_schedules`, `team8_records`, `team8_compliance_checks` |
| Legal Management | `team8_legal_cases`, `team8_legal_documents` |
| Contract Management | `team8_contracts`, `team8_parties`, `team8_contract_parties`, `team8_contract_documents`, `team8_contract_obligations` |

## Notable design decisions
- `team8_legal_cases.contract_id` has a circular dependency with
  `team8_contracts` (a case can reference a contract, contracts don't
  reference cases back) — resolved with a deferred `ALTER TABLE ...
  ADD CONSTRAINT` at the end of `schema.sql`, after both tables exist.
- `team8_documents.file_path` always points at the **current** version;
  `team8_document_versions.file_path` stores each historical version's
  own file.
- `team8_visits.status` tracks `expected | checked_in | checked_out |
  denied`.
- `team8_compliance_checks` is a separate audit table from
  `team8_retention_schedules` / `team8_records` — retention is *when*
  something expires, compliance checks are periodic *reviews* of a
  record's state.
- **Milestone 3 additions**: `team8_reservations.purpose` (short free
  text on why the facility is being booked) and
  `team8_reservation_approvals.remarks` (approver's note on
  approve/reject) were added — the latter matches the schema
  review-patch's standing recommendation to prefer a `remarks TEXT`
  field on approval/rejection tables. Both are additive, nullable
  columns; see `database/migrations/2026_07_17_reservation_module_additions.sql`
  for the diff against any database created from an older schema.sql.
- **Reservation status flow**: `pending` (on create) →
  `approved`/`rejected` (via `team8_reservation_approvals`, written by
  an `admin`/`facilities_staff` user) → the requester (or an approver)
  can move an `approved` or still-`pending` reservation to `cancelled`.
  `team8_reservations.status` is kept in sync with the latest approval
  decision rather than requiring a join on every read.

## Setup
```bash
mysql -u root -p < database/schema.sql
mysql -u root -p < database/seed.sql   # optional local test data
```

Migrations for schema changes after initial setup go in
`database/migrations/` — the first real one landed with Milestone 3
(see above).
