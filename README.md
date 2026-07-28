# RAM YUM — Facilities & Administrative Management (Team 8)

Facilities & Administrative Management subsystem for the multi-team
capstone project. Covers: Facilities Reservation, Visitor Management,
Document Management/Archiving, Records Retention & Compliance, Legal
Management, and Contract Management — as one unified system with a
single dashboard and entry point (not six separate mini-apps).

**Stack:** HTML, CSS, vanilla JavaScript, PHP 8.2+, MySQL 8+. No
framework — routing is a small hand-rolled front controller
(`index.php?page=...`), see `docs/API.md`.

## Requirements
- PHP 8.2+
- MySQL 8+
- Git
- Apache with `mod_rewrite` + `mod_authz_core` (or an nginx equivalent
  of the deny rules in `.htaccess` — see the note below)

## Setup

1. Clone the repo and `cd` into it.
2. Open `app/config/database.php` and edit `DB_HOST`/`DB_NAME`/`DB_USER`/
   `DB_PASS` for your local MySQL setup (defaults match a stock XAMPP
   install — `root` with no password). App-level settings like
   `APP_URL` live in `app/config/config.php`, edited the same way —
   this project uses plain PHP constants instead of a `.env` file (see
   the note at the top of each of those files for why).
3. Create the database and import the schema (Team 8's tables only —
   see `docs/Database.md` for why the shared-table section exists):
   ```bash
   mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS capstone_shared_db"
   mysql -u root -p capstone_shared_db < database/schema.sql
   mysql -u root -p capstone_shared_db < database/seed.sql   # optional
   ```
4. Start PHP's built-in dev server from the project root:
   ```bash
   php -S localhost:8000
   ```
5. Visit `http://localhost:8000/index.php?page=dashboard` (or just
   `http://localhost:8000/dashboard.php`, which redirects there).

   SIMPLIFIED SETUP
  - DL & INSTALL XAMMP
  - START APCHE & MYSQL
  - OPEN PHPMYADMIN
  - CLICK DATABASE & CREATE 1
  - NAME IT "capstone_shared_db"
  - IMPORT SQL "schema" | "seed.sql" [OPTIONAL]
  - START LOCAL HOST "php -S localhost:8000"

> **Note:** PHP's built-in dev server (`php -S`) does **not** read
> `.htaccess`. The deny rules that block `database/`/`app/` from being
> served (see Security note below) only take effect under Apache.
> That's fine for local dev via `php -S`, but before this ever runs
> under Apache/XAMPP on a shared server, confirm those rules are
> actually active (`curl http://host/app/config/database.php` should
> 403, not return source code).

## Auth note
Login/logout/session/RBAC is owned by another team and is meant to be
system-wide. Until it's integrated, Team 8 built a real **temporary**
login (`login.php` / `logout.php`) against the shared `users` table —
see `docs/Auth.md` for the full contract, seeded demo accounts, and
how to swap it out later. Default seeded login:
`dev.tester@example.local` / `Password123!`.

## Security note
This database is **shared across all 10 teams**. DB credentials live
in `app/config/database.php` (no `.env` file — see that file's
docblock for why); the whole `app/` and `database/` trees are denied
at the web-server level by the root `.htaccess` (plus a deny-all
`.htaccess` inside each folder itself) — a leaked config file here
isn't just a Team 8 problem. See `docs/Database.md` and `docs/Auth.md`
for the rest of the hardening in place (session cookie flags, login
throttling, POST-only logout).

## Modules
- **Facilities Reservation** (Milestone 3, built) — book a facility,
  optionally reserve equipment, track approval status, and browse a
  monthly calendar of bookings. See `modules/reservation/index.php`.
- Visitor Management, Document Management, Records Retention, Legal
  Management, Contract Management — scaffolded and routed, not yet
  built (see `docs/Milestones.md`).

## Docs
- `docs/Milestones.md` — milestone checklist
- `docs/Database.md` — schema/table ownership notes
- `docs/API.md` — routing conventions
- `docs/Auth.md` — temporary auth system, demo accounts, swap-out plan
- `docs/TechDebt.md` — code-review findings and fix status
- `docs/ERD.pdf` — entity-relationship diagram

## Coding standard
- One responsibility per controller/module file
- Prepared statements via PDO everywhere (no string-concatenated SQL)
- Reusable templates (`templates/header.php`, `sidebar.php`,
  `navbar.php`, `footer.php`)
- CSS classes prefixed `.t8-` to avoid collisions with other teams'
  stylesheets if pages ever get embedded together
- No duplicated logic — shared helpers live in `app/includes/helpers.php`
