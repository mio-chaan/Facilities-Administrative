# Auth (Temporary)

Authentication/RBAC is owned by another team and is meant to be
**system-wide** — one login serving all 10 teams' subsystems. That
module hasn't been integrated yet, so Team 8 built a minimal
**temporary** stand-in to unblock development and demoing.

## What exists
- `login.php` — email/password form. Verifies against the shared
  `users` table (`password_hash`, bcrypt via `password_hash()` /
  `password_verify()`), looks up the user's role from
  `user_roles`/`roles`, and sets a real PHP session.
- `logout.php` — destroys the session, clears the cookie, redirects
  to `login.php`. **POST-only** (see code review fixes below).
- `app/includes/auth_check.php` — guards every page behind the front
  controller. If there's no session, redirects to `login.php`.

## Session contract
This is the part that matters for a clean swap later — every module
reads these, nothing reads `$_SESSION` directly outside `auth_check.php`:

| Key | Type | Source |
|---|---|---|
| `user_id` | int | `users.id` |
| `full_name` | string | `users.full_name` |
| `role` | string | `roles.role_name` (first role found for the user) |
| `department_id` | int\|null | `users.department_id` |

## Security notes (temporary ≠ sloppy)
- Passwords are bcrypt-hashed (`password_hash()`), never stored/compared
  in plaintext.
- Login form is CSRF-protected (`t8_csrf_field()` / `t8_csrf_verify()`
  in `app/includes/helpers.php` — reusable for any future POST form).
- Login errors are intentionally vague ("Invalid email or password")
  so the form doesn't leak which emails exist.
- `session_regenerate_id(true)` on successful login to prevent session
  fixation.
- **Session cookies are hardened** (`httponly`, `samesite=Lax`, and
  `secure` when `APP_URL` is https) via a single `t8_session_start()`
  helper (`app/includes/helpers.php`), called from `auth_check.php`,
  `login.php`, and `logout.php` instead of each calling a raw
  `session_start()`.
- **Brute-force throttling**: `login.php` locks out further attempts
  for 5 minutes after 5 failed attempts in a row (session-based
  counter). This is a basic deterrent suitable for a capstone
  demo/local server — not a substitute for IP-based rate limiting or
  a real lockout table if this ever sits on a shared/public host.
- **Logout is a POST-only action** (`templates/navbar.php` submits a
  small `<form>`; `logout.php` rejects non-POST requests with 405).
  Previously a bare GET `<a href>`, which is a known anti-pattern for
  state-changing actions (prefetch/crawlers could silently log a user
  out).
- **Audit logging**: successful logins, logouts, and 403s (denied by
  `t8_require_role()`) are written to the shared `audit_logs` table
  via `app/includes/audit.php`'s `t8_audit_log()`. Best-effort only —
  a logging failure never blocks the actual login/logout/403 response.
  Milestone 3 extends this same helper to reservation create/cancel/
  approve/reject events (see `docs/Database.md`).

## Roles relevant to Milestone 3 (Facilities Reservation)
- Any authenticated role can submit and view/cancel their own
  reservation requests.
- `admin` and `facilities_staff` can additionally see and act on the
  approvals queue (approve/reject pending requests for any user).

## Dev convenience
`AUTH_DEV_BYPASS` is a constant in `app/config/config.php` (no `.env`
file — see that file's docblock). Set it to `true` (local env only) to
skip the login form entirely and auto-sign in as the seeded
"Dev Tester" (admin role). Off by default — flip it on only for quick
throwaway testing.

## Seeded demo accounts (`database/seed.sql`)
All seeded accounts share the password `Password123!`:

| Email | Role |
|---|---|
| `dev.tester@example.local` | admin |
| `facilities@example.local` | facilities_staff |
| `frontdesk@example.local` | front_desk |
| `legal@example.local` | legal_officer |

`database/seed.sql` (hashes + emails) is blocked from direct HTTP
access by the root and `database/` `.htaccess` files — see
`docs/Database.md`.

## Swapping in the real system
When the owning team's auth module is ready:
1. Delete `login.php` and `logout.php`.
2. Point their login flow's redirect at whatever sets the session keys
   listed above (or adjust `auth_check.php` to read theirs directly).
3. Remove the `AUTH_DEV_BYPASS` block and env var.
4. Nothing else changes — every module already reads
   `t8_current_user_id()` / `t8_current_role()` / `t8_current_user_name()`
   from `auth_check.php`, not `$_SESSION` directly.
