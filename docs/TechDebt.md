# Tech Debt / Code Review Findings

Transcribed from the senior-dev code review. Status reflects this
fix pass; anything marked "not fixed" is still open.

## 🔴 Critical

- **No `.htaccess`** — `.env` and `database/*.sql` were directly
  downloadable if deployed under Apache/XAMPP as the README suggests.
  **FIXED** — root `.htaccess` denies dotfiles and the
  `app/database/modules/templates/docs` trees; `database/.htaccess`
  and `app/.htaccess` add deny-all as defense in depth. See
  `docs/Database.md` and the README's Security note. Note: `php -S`
  does not read `.htaccess` — this only takes effect under Apache.
  **Update:** the project no longer uses a `.env` file at all (see
  `app/config/config.php`/`database.php`) — DB credentials are plain
  constants in `app/config/database.php`, protected by the same
  `app/.htaccess` deny-all instead of a separate dotfile rule.

## 🟠 High

- **Flash message set-then-read ordering bug**
  (`modules/dashboard/index.php` + `templates/header.php`).
  `header.php` read-and-cleared flashes before the module file ran,
  so a same-request DB failure warning never showed on that page load.
  **FIXED** — the dashboard now uses a local `$dbError` variable
  rendered inline instead of `t8_flash_set()`. Flash is still used
  correctly elsewhere (e.g. login's next-request "Welcome back"
  message, and Milestone 3's create/cancel/approve/reject redirects).
- **Logout was a bare GET link** (`templates/navbar.php`), a known
  anti-pattern for state-changing actions (prefetch/crawlers could
  silently log a user out). **FIXED** — logout is now a POST `<form>`;
  `logout.php` returns 405 for non-POST requests.

## 🟡 Medium

- **Route list duplicated in three places** (`T8_PAGES` in
  `constants.php`, `routes.php`, `$navItems` in `sidebar.php`).
  **FIXED** — `routes.php` now holds `['file' => ..., 'label' => ...]`
  per key and is the single source of truth; `T8_PAGES` derives its
  keys from it, and `sidebar.php` reads labels from it directly.
- **No session hardening** — three separate `session_start()` call
  sites, none setting `httponly`/`samesite`/`secure`. **FIXED** — one
  `t8_session_start()` helper (`app/includes/helpers.php`) sets cookie
  params before starting the session; `auth_check.php`, `login.php`,
  and `logout.php` all call it instead of raw `session_start()`.
- **No brute-force protection on `login.php`.** **FIXED** — simple
  session-based lockout: 5 failed attempts locks the form for 5
  minutes. Adequate for a capstone demo; not a substitute for
  IP-based rate limiting on a shared/public server.
- **Stale comment in `navbar.php`** claiming logout was "owned by the
  auth team, wire the real endpoint once known." **FIXED** — removed;
  replaced with a comment describing the POST-form fix above.
- **`header.php`/`footer.php` read `$page` implicitly** from the
  including scope rather than as a parameter. **PARTIALLY ADDRESSED**
  — both now default `$page ??= current_page()` and carry an explicit
  doc comment stating the contract, so a missing/renamed `$page`
  degrades gracefully instead of silently dropping a stylesheet/script.
  Not converted to explicit function parameters (would touch every
  call site) — still worth doing if this drifts again.
- **`t8_require_role()` truncated the page on 403** (echoed a div and
  `exit()` mid-render, after header/navbar/sidebar/`.t8-main` were
  already open). **FIXED** — it now closes `</main></div>` and
  requires `footer.php` before exiting, so a 403 is valid, complete
  HTML. It also now writes a `403_denied` entry to `audit_logs` when
  possible (best-effort, never blocks the 403 itself).

## 🟢 Low / nice-to-have

- **`helpers.php`'s doc comment said "no DB, no session"** while
  `t8_flash_*()`/CSRF functions touch `$_SESSION`. **FIXED** — comment
  corrected; also now documents `t8_session_start()`.
- **`Database::getConnection()` and `t8_require_role()` used
  `die()`/`exit()`** for error handling. **NOT FIXED** —
  `t8_require_role()`'s exit path was made safer (see above) but still
  exits; `Database::getConnection()` is unchanged. Both are untestable
  and bypass any future logging/response layer — revisit if/when the
  app grows a real error-handling layer.
- **No `date_default_timezone_set()` anywhere.** **FIXED** —
  `app/config/config.php` now calls
  `date_default_timezone_set(APP_TIMEZONE)`, where `APP_TIMEZONE` is a
  plain constant at the top of that file (edit it there, defaults to
  `Asia/Manila`).
- **`$moduleFile` in `index.php` wasn't checked with `is_file()`
  before `require`.** **FIXED** — a missing/typo'd route now falls
  through to the normal 404 page instead of an uncaught fatal.
- **`audit_logs` table existed but nothing wrote to it.** **FIXED** —
  `app/includes/audit.php`'s `t8_audit_log()` is wired up for login,
  logout, 403 events, and — as of Milestone 3 — reservation create,
  cancel, approve, and reject events.

## Milestone 3 code review pass (Facilities Reservation)
Before this module was considered done, it was actually run end-to-end
against a real MySQL/MariaDB database and PHP dev server (not just
read-through) - three real bugs turned up and were fixed:
- **`logout.php`'s 405 was never actually sent.** `header('Location:
  ...')` silently overrides an already-set `http_response_code(405)`
  with PHP's own default 302 - confirmed with `curl -i`. The code
  comment and `docs/Auth.md` both claimed 405 but the wire behavior
  was a redirect. **FIXED** - non-POST requests now get a real 405
  with a small text body instead of a redirect.
- **`?month=` on the reservation calendar could crash the whole page.**
  The validation regex only checked the `YYYY-MM` shape, not that the
  month was 01-12. `month=2026-13` passed validation,
  `strtotime('2026-13-01')` returned `false`, and `date('t', false)`
  then threw an uncaught `TypeError` (PHP 8+), which is a fatal error
  for any visitor who edited the query string. **FIXED** - the regex
  now validates the real month range, with a defensive fallback in
  case `strtotime()` still somehow fails.
- **Equipment quantity was only clamped client-side** (an HTML `max`
  attribute that was itself wrong for 0-stock items, allowing `max="1"`
  on something with 0 available). A crafted POST could reserve more of
  an item than existed, or reserve out-of-stock equipment entirely.
  **FIXED** - the create handler now looks up real stock levels from
  `team8_equipment` and clamps/skips server-side; the picker also
  disables out-of-stock rows instead of rendering them selectable.

## Milestone 3 known limitations (not bugs, by design for now)

- No double-booking prevention at the DB level (no exclusion
  constraint / unique index on overlapping time ranges — MySQL has no
  native range-exclusion constraint). The create form does a
  best-effort overlap check in PHP against `approved`/`pending`
  reservations for the same facility before inserting, but this is a
  check-then-act race under concurrent submissions, not an atomic
  guarantee. Acceptable for a capstone demo's traffic level; would
  need a `SELECT ... FOR UPDATE` transaction (or an app-level lock) to
  fully close the race if this ever sees concurrent real usage.
- Approval workflow is single-step: any `admin`/`facilities_staff`
  user can approve or reject a pending reservation (the
  `team8_reservation_approvals` table supports `step_order` for a
  future multi-step chain, but only one step is created/used today).
