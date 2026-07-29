# MEMORY.md — RAM YUM / Team 8 Facilities & Administrative Management

> Living record of project state. Updated only after major milestones,
> architecture, database, or folder-structure changes, and only with
> explicit go-ahead. Not updated for minor bug fixes or UI tweaks.

## Team & Scope
- Team 8, multi-team capstone. Subsystem: Facilities & Administrative
  Management — six modules under one unified system (single dashboard,
  single entry point), not six separate mini-apps.
- Modules: Facilities Reservation, Visitor Management, Document
  Management/Archiving, Records Retention & Compliance, Legal
  Management, Contract Management.
- Stack: HTML, CSS, vanilla JavaScript, PHP 8.2+, MySQL 8+. No
  framework.

## Ownership & Constraints
- Database is **shared across all 10 teams**. Team 8 tables are all
  prefixed `team8_`; shared core tables (`users`, `roles`,
  `departments`, `user_roles`, `notifications`, `audit_logs`) are
  unprefixed and created with `CREATE TABLE IF NOT EXISTS` so the repo
  can run standalone locally — swap for the real shared schema at
  integration time.
- **Authentication/RBAC is owned by another team** and is system-wide.
  Team 8 does not build login/logout/session creation — only reads
  shared session vars (`user_id`, `full_name`, `role`,
  `department_id`) via `app/includes/auth_check.php`.
- No adviser-mandated naming/folder convention exists yet. Current
  structure is Team 8's proactive choice, open to revision if the
  class aligns on a standard.

## Milestone Status
- [x] **Milestone 0 — Project Setup** (complete, smoke-tested end to
      end: schema import, seed import, PHP dev server, all routes
      returning correct status codes, dashboard pulling live DB
      counts)
- [ ] **Milestone 1 — Authentication** — owned by another team; Team 8
      has nothing to build here beyond keeping `auth_check.php`'s
      contract (the session var names above) ready to swap in.
- [ ] Milestone 2 — Dashboard (stat cards are live; recent
      activity/notifications still pending)
- [ ] Milestones 3–10 — not started (see `docs/Milestones.md`)

## Architecture Decisions (Milestone 0)
- **Folder structure:** `team8-facilities-admin/` root, with `app/`
  (config, controllers, models, middleware, services, includes),
  `modules/` (one folder per module), `templates/` (header, navbar,
  sidebar, footer), `public/` (css, js, img, uploads), `database/`
  (schema.sql, seed.sql, migrations/), `docs/`.
- **Routing:** no framework, so a single front controller (`index.php`)
  dispatches on `?page=` against a whitelist in
  `app/config/routes.php`. `dashboard.php` at the root is a thin
  redirect to `index.php?page=dashboard`, kept as the conventional
  post-login landing URL.
- **Config:** hand-rolled `.env` loader in `app/config/config.php`
  (no composer/phpdotenv dependency) feeding `env()` calls and a PDO
  singleton in `app/config/database.php`.
- **CSS:** pure vanilla design system, no library, all classes
  prefixed `.t8-`. Palette/theme: "facility directory board" —
  blueprint-navy structure, signage-amber accents, monospace for
  data/reference codes.
- **Auth stub:** `app/includes/auth_check.php` fakes a session when
  `APP_ENV=local` so modules can be built/tested before the real auth
  team's system is integrated. Clearly marked for removal at
  integration time.
- **Schema:** `database/schema.sql` is the reviewed version — 20
  team8-prefixed tables, 6 shared-core placeholder tables, full FK
  constraints, a deferred `ALTER TABLE` for the
  `team8_legal_cases` ↔ `team8_contracts` circular reference,
  performance indexes on commonly queried columns. Verified to import
  cleanly on MySQL/MariaDB.

## On the Horizon
- Milestone 1 is externally owned — no build action for Team 8 right
  now beyond staying integration-ready.
- Next Team 8 build work is either finishing out Milestone 2
  (dashboard: recent activity feed, notifications) or starting
  Milestone 3 (Reservation module) — pending direction.
