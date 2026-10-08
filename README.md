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
  of the deny rules in `.htaccess`)

## Setup

1. Clone the repo and `cd` into it.
2. Install PHP dependencies from the project root:
  ```bash
  composer install
  ```
  This installs PHPMailer and Dompdf; Composer dependencies are kept in
  the ignored `vendor/` directory.
3. Open `app/config/database.php` and edit `DB_HOST`/`DB_NAME`/`DB_USER`/
   `DB_PASS` for your local MySQL setup (defaults match a stock XAMPP
   install — `root` with no password). For deployment, set `APP_ENV=production`
   and `APP_URL` in the environment; contract review invitations require an
   externally reachable HTTPS URL. Local mode (`APP_ENV=local`, the default)
   also permits `http://localhost:8000` for SMTP testing, but the emailed
   review link only works on the same machine. This project uses plain PHP
   constants instead of a `.env` file.
   For Gmail delivery, add `GMAIL_SMTP_USER` and the 16-character
   `GMAIL_SMTP_APP_PASSWORD` in the ignored `app/config/config.local.php`
   or set those environment variables. Never commit real credentials.
   For contract signing, also configure `T8_SIGNING_OTP_PEPPER` as a random
   secret of at least 32 characters and `T8_SIGNATURE_STORAGE_DIR` as a
   writable private directory outside the web root. Signing links require
   `APP_URL` to be public HTTPS outside local development.
4. Create the database and import the complete schema. It includes the
   tables and columns from the dated migrations, so a new installation
   only needs this import:
   ```bash
   mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS capstone_shared_db"
   mysql -u root -p capstone_shared_db < database/schema.sql
   mysql -u root -p capstone_shared_db < database/seed_account.sql
   ```
   For an existing database, use only the dated migration scripts needed
   to bring its current schema up to date; do not re-import `schema.sql`
   over existing data. The seed account uses a real Gmail inbox so sign-in
   OTP delivery can be tested; Gmail SMTP sender settings must also be configured.
5. Start PHP's built-in dev server from the project root:
   ```bash
   php -S localhost:8000
   ```
6. Visit `http://localhost:8000/index.php?page=dashboard` (or just
   `http://localhost:8000/dashboard.php`, which redirects there).

   SIMPLIFIED SETUP
  - DL & INSTALL XAMMP
  - START APCHE & MYSQL
  - OPEN PHPMYADMIN
  - CLICK DATABASE & CREATE 1
  - NAME IT "capstone_shared_db"
  - IMPORT SQL "database/schema.sql" (complete schema)
  - IMPORT SQL "database/seed_account.sql" (admin account for OTP testing)
  - START LOCAL HOST "php -S localhost:8000"

> **Note:** PHP's built-in dev server (`php -S`) does **not** read
> `.htaccess`. The deny rules that block `database/`/`app/` from being
> served only take effect under Apache. That is fine for local dev via
> `php -S`, but before this runs under Apache/XAMPP on a shared server,
> confirm those rules are active.

## Auth note
Login/logout/session/RBAC is owned by another team and is meant to be
system-wide. Until it's integrated, the project uses a local login flow
(`login.php` / `logout.php`) against the shared `users` table. See
`docs/Auth.md` for the full contract, seeded demo accounts, and how to
swap it out later. Default seeded login:
`ramyum060@gmail.com` / `Password123!@`.

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
- `docs/Auth.md` — authentication flow, demo accounts, and swap-out plan
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
