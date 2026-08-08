# RAM YUM — Senior QA / Architecture Review
**Scope:** Full pasted snapshot (Milestone 0–4 code, schema, migrations, templates, JS/CSS)
**Reviewer stance:** No guessing — anything I couldn't confirm from the pasted files is marked **UNCERTAIN**.

---

## How to read this report

Each finding has: **File**, **Function/Action**, **Severity**, **Explanation**, **Repro steps**, **Suggested fix**.

Severity scale:
- **Critical** — breaks core functionality or is a serious security hole
- **High** — real bug/vuln with clear exploit or failure path
- **Medium** — real defect, but narrower impact or harder to trigger
- **Low** — code smell / hardening opportunity, unlikely to bite in normal use

---

## 🔴 CRITICAL

### C1. `database/schema.sql` does not match the code that runs against it (Visitor Management)
- **File(s):** `database/schema.sql`, `database/migrations/2026_07_22_visitor_management_simplify.sql`, `modules/visitor/index.php`, `modules/dashboard/index.php`
- **Function:** entire Visitor Management module; `t8_visitor_fetch()`; dashboard's `Visitors Today` query
- **Severity:** Critical
- **Explanation:**
  `schema.sql` defines `team8_visitors` as a *contact directory* (`id, full_name, contact, created_at, updated_at`) and a **separate** `team8_visits` table (`visitor_id, host_id, status, check_in, check_out, purpose`).
  The migration `2026_07_22_visitor_management_simplify.sql` assumes yet a *third* shape — it does `ALTER TABLE team8_visitors DROP COLUMN id_number, ADD COLUMN company ...`, but `id_number` **never existed** in `schema.sql`'s `team8_visitors` definition. Running this migration against a schema.sql-created database will fail with `Unknown column 'id_number'`.
  Meanwhile, the actual working code in `modules/visitor/index.php` and `modules/dashboard/index.php` queries a single `team8_visitors` table with an entirely different column set: `visitor_type, person_to_visit, purpose, scheduled_date, status, check_in_time, check_out_time, logged_by`. None of these columns exist anywhere in `schema.sql` or the migration. `team8_visits` is never referenced by any PHP file.
  This means a fresh clone that follows the README's own setup steps (`mysql < schema.sql`, optionally the migration) will produce a database the Visitor Management module cannot run against at all.
- **How to reproduce:**
  1. `mysql -u root -p capstone_shared_db < database/schema.sql`
  2. Visit `index.php?page=visitor` → any query (`t8_visitor_fetch`, the list queries, the INSERT in `case 'create'`) throws `SQLSTATE[42S22]: Unknown column 'visitor_type' in 'field list'` (uncaught `PDOException`, since none of these queries are wrapped in try/catch) → 500 error.
- **Suggested fix:** Regenerate `schema.sql`'s Visitor Management section (and a corrected migration) to match the single-table shape actually used by `modules/visitor/index.php`. Treat `team8_visits` as dead/superseded and remove it, or reconcile the two designs — right now there are three incompatible visitor schemas in the repo (schema.sql, migration, live code).

### C2. Reservation "Delete Request" workflow is documented as shipped but is absent from the actual controller
- **File(s):** `modules/reservation/index.php`, `templates/footer.php`, `database/migrations/2026_07_22_reservation_delete_workflow.sql`
- **Function:** entire `switch ($action)` block in `modules/reservation/index.php`
- **Severity:** Critical
- **Explanation:**
  The migration adds `previous_status`, `delete_reason`, `delete_requested_by`, `delete_requested_at`, `rejection_reason` to `team8_reservations`, clearly intended to back an employee-requests / admin-approves soft-delete workflow. But the pasted `modules/reservation/index.php` only implements `create, edit, cancel, delete, approve, reject` — there is no `request_delete`, `review_delete`, `approve_delete`, or `reject_delete` action anywhere, and none of the five new columns are read or written by any query in the file.
  Corroborating this: `templates/footer.php` only conditionally loads `validation.js` and `reservation.js` — it never loads `modal.js`, even though the modal component is described as the delivery mechanism for this exact feature.
  As pasted, this is dead schema (five unused nullable columns) and a documented feature that end users cannot access through the UI at all.
- **How to reproduce:** Open the Reservation module as any role and look for a "Request Deletion" control on a non-cancelled reservation, or a "Review" modal on the admin side — neither exists in the given `modules/reservation/index.php`.
- **Suggested fix:** Either the snapshot is stale (re-paste the current `modules/reservation/index.php` and `templates/footer.php`), or the feature needs to actually be implemented against the existing migration columns before Milestone 4 can be called complete.

### C3. `.env`, `database/*.sql`, and app configs are fully downloadable in the documented local dev workflow
- **File(s):** `README.md`, `.htaccess`, `app/.htaccess`, `database/.htaccess`, `.env`
- **Function:** N/A (deployment/config)
- **Severity:** Critical
- **Explanation:**
  README's own setup instructions tell the developer to run `php -S localhost:8000`, and README explicitly documents that **`.htaccess` rules are not honored by PHP's built-in server**. All of the project's secret-protection (`.env` with a live-looking `OPENAI_API_KEY`, `database/seed.sql` with bcrypt hashes + emails, `database/schema.sql`) relies *entirely* on Apache-level `.htaccess` deny rules. Under the documented (and apparently primary) local workflow, none of that protection is active, so `http://localhost:8000/.env` and `http://localhost:8000/database/seed.sql` are directly fetchable.
  I also want to flag directly: the `.env` file pasted into this review contains what looks like a live OpenAI API key. Whether or not it's still valid, it should be rotated, since it has now been transmitted outside the intended environment.
- **How to reproduce:** Start the app per README (`php -S localhost:8000`), then `curl http://localhost:8000/.env` or `curl http://localhost:8000/database/seed.sql` — both return their full contents (no PHP front-controller involved, static file serving).
- **Suggested fix:** Never rely on `.htaccess` alone. Add an app-level guard (e.g., a tiny router that 404s any request outside `public/` and the whitelisted entry scripts) so protection holds regardless of the server used, and rotate the exposed key immediately.

---

## 🟠 HIGH

### H1. Login brute-force lockout is trivially bypassable (session-scoped, not account/IP-scoped)
- **File:** `login.php`
- **Function:** top-level POST handler (`T8_LOGIN_MAX_ATTEMPTS` / `T8_LOGIN_LOCKOUT_SECS` logic)
- **Severity:** High
- **Explanation:** Failed-attempt counting is stored in `$_SESSION['t8_login_attempts']`. Since the session is tied to the client's cookie, an attacker just needs to drop the session cookie (new browser tab in a fresh private window, or omit the cookie in a scripted request) to reset the counter to 0 every time. This makes the 5-attempt lockout meaningless against any scripted brute-force attempt.
- **How to reproduce:** Script 5 failed logins without persisting cookies between requests (or send `Cookie:` header each time with a fresh/absent session) — the lockout never triggers regardless of attempt count against a given email.
- **Suggested fix:** Track failed attempts keyed by email and/or client IP in a persistent store (DB table or cache), not in `$_SESSION`.

### H2. Uncaught `RuntimeException` from file upload can crash the request
- **File:** `modules/documents/index.php`
- **Function:** `case 'create':` and `case 'upload_version':` — specifically the call to `t8_document_store_upload()`
- **Severity:** High
- **Explanation:** `t8_document_store_upload()` throws `RuntimeException('Could not save the uploaded file.')` if `move_uploaded_file()` fails. In both `create` and `upload_version`, this call happens **before** `$pdo->beginTransaction()`/`try` — it is not inside any try/catch. A disk-full condition, a permissions problem, or an unusual upload edge case will produce an unhandled exception → PHP fatal error. With `APP_DEBUG = true` (the shipped default), this can also leak a stack trace/file paths to the browser.
- **How to reproduce:** Make `public/uploads/documents/` non-writable (or fill the disk), then upload a valid document — the request 500s instead of showing a friendly "upload failed" message.
- **Suggested fix:** Wrap the `t8_document_store_upload()` call in try/catch and convert to a user-facing `$errors[]` entry, consistent with how validation errors are handled elsewhere in the same file.

### H3. Reservation server-side validation does not actually enforce "start time not in the past," contradicting the code's own documentation
- **File:** `modules/reservation/index.php`
- **Function:** `t8_reservation_validate()`
- **Severity:** High
- **Explanation:** `public/js/reservation.js`'s header comment explicitly states: *"modules/reservation/index.php re-validates everything server-side (facility required, start < end, start not in the past, ...)"*. Reading `t8_reservation_validate()`, it checks facility validity, that both times are present/parseable, and that `start < end` — there is no check comparing `$start` against the current time. A reservation can be created or edited with a start time in the past (e.g., backdated to yesterday).
- **How to reproduce:** Submit the reservation create form with a `start_time`/`end_time` both set to yesterday — the request succeeds (`pending` or auto-approved for admin) with no validation error.
- **Suggested fix:** Add `if (strtotime($start) < time()) { $errors[] = 'Start time cannot be in the past.'; }` to `t8_reservation_validate()`.

### H4. AI features (chat assistant + document summarizer) are almost certainly non-functional out of the box — `UNCERTAIN`, please verify
- **File:** `app/config/config.php`, `app/includes/ai_helper.php`
- **Function:** `define('OPENAI_API_KEY', $_ENV['OPENAI_API_KEY'] ?? '')`; `t8_openai_chat()`
- **Severity:** High (confidence: medium — see caveat)
- **Explanation:** PHP does **not** automatically populate `$_ENV` from a `.env` file — that requires an explicit loader (e.g., `vlucas/phpdotenv`, or a hand-rolled parser that calls `putenv`/sets `$_ENV`). The project's own memory/docs describe a "hand-rolled `.env` loader," but no such loader appears anywhere in the pasted files, and `config.php` never requires one. If that's really the full picture, `OPENAI_API_KEY` will always resolve to `''`, and `t8_openai_chat()` will always throw `"AI features are not configured yet."` for both the floating AI Assistant widget and the Document Summarizer.
  **I'm flagging this as uncertain** because the loader may exist in a file that simply wasn't included in this snapshot. Worth a quick sanity check: hit the AI widget and confirm you get real replies vs. the "not configured" error.
- **How to reproduce (if the loader truly doesn't exist):** Load any page, open the AI widget, send a message → response is `"AI features are not configured yet. Add OPENAI_API_KEY to app/config/config.php."`
- **Suggested fix:** Confirm whether a `.env` loader is required/executed anywhere in the real bootstrap chain. If not, add one (or just read the key straight out of `config.php` as a constant, bypassing `.env` entirely, which is what the file's own docblock already half-suggests).

### H5. Reservation approve/reject has a time-of-check/time-of-use race that can produce two overlapping *approved* bookings
- **File:** `modules/reservation/index.php`
- **Function:** `case 'approve': case 'reject':`
- **Severity:** High
- **Explanation:** The "conflict" flag shown to admins (`t8_reservations_annotate_conflicts`) is computed only when the *list page* is rendered. The `approve` action itself does not re-check `t8_reservation_has_conflict()` before flipping `status` to `approved` — it's an unconditional `UPDATE ... WHERE id = :id`. If two pending reservations for the same facility/overlapping time are both open, an admin can approve both (in two separate requests, or two admins acting concurrently) with no server-side block, even though the row that was conflict-free at page-render time may no longer be by the time the POST lands.
- **How to reproduce:** Create two pending reservations for the same facility with overlapping times. Open the Pending Approvals page. Approve reservation A. Without refreshing, approve reservation B (which was rendered before A's approval, so its conflict flag was still "clean" at render time) — both end up `status = 'approved'` with an unhandled overlap.
- **Suggested fix:** Re-check `t8_reservation_has_conflict()` inside the `approve` branch itself, immediately before the `UPDATE`, and reject the approval (or force a warning acknowledgment) if a conflict now exists. Ideally wrap the check+update in a transaction with `SELECT ... FOR UPDATE` on the facility's reservations to close the window entirely.

---

## 🟡 MEDIUM

### M1. Reservation form is missing `id="t8ReservationForm"` — all of `reservation.js` is dead code
- **File:** `modules/reservation/index.php` (the `<form>` in the create/edit view), `public/js/reservation.js`
- **Function:** N/A (markup/script mismatch)
- **Severity:** Medium
- **Explanation:** `reservation.js` does `document.getElementById("t8ReservationForm"); if (!form) return;`. The actual `<form>` in `modules/reservation/index.php` has no `id` attribute at all. Since the element is never found, the script exits immediately on every page load — client-side date-range validation, required-field highlighting, and all equipment-row logic never run. (Equipment picker markup — `.t8-equipment-row` — also doesn't exist anywhere in this file's HTML, so that portion of the script was never wired up to begin with.)
- **How to reproduce:** Open the reservation create form, submit with an end time before the start time — no inline `.t8-error-text` appears client-side (it still gets caught server-side, but the intended UX layer is silently broken).
- **Suggested fix:** Add `id="t8ReservationForm"` to the form, and either build the equipment-picker markup to match `reservation.js`'s expectations or remove that dead code from the script.

### M2. Document version numbering has a race condition (no DB-level uniqueness)
- **File:** `modules/documents/index.php`
- **Function:** `case 'upload_version':`
- **Severity:** Medium
- **Explanation:** The next version number is computed via `SELECT COALESCE(MAX(version_no), 0) ... + 1` and then used in a separate `INSERT`. There is no unique constraint on `(document_id, version_no)` in `schema.sql`, and no transaction/locking around the read-then-write. Two near-simultaneous "upload new version" requests for the same document can both compute the same `$nextVersion` and both insert, leaving two version rows with the same `version_no` (and `team8_documents.current_version` ending in an inconsistent state depending on write order).
- **How to reproduce:** Fire two concurrent `upload_version` POSTs for the same document (e.g., two browser tabs submitting within the same request window). Inspect `team8_document_versions` — duplicate `version_no` values for the same `document_id` are possible.
- **Suggested fix:** Add `UNIQUE (document_id, version_no)` to `team8_document_versions`, and either catch the resulting constraint-violation and retry, or wrap the read+insert in a transaction using `SELECT ... FOR UPDATE` on the parent document row.

### M3. Dashboard's "Visitors Today" stat conflates scheduled (not-yet-arrived) visits with actual check-ins, and survives cancellation
- **File:** `modules/visitor/index.php` (`case 'create':`), `modules/dashboard/index.php`
- **Function:** the `case 'create':` INSERT; dashboard's `SELECT COUNT(*) ... WHERE DATE(check_in_time) = CURDATE()`
- **Severity:** Medium
- **Explanation:** For a *scheduled* (not "arriving now") visit, `check_in_time` is set at creation time to `$formValues['scheduled_date']` rather than left `NULL`. Since the dashboard counts "Visitors Today" purely off `DATE(check_in_time) = CURDATE()`, a visit merely *scheduled* for today (never actually arrived, or later cancelled) is counted the same as a visitor who actually walked in. Cancelling a scheduled visit doesn't clear `check_in_time`, so it keeps counting toward "Visitors Today" indefinitely for that date.
- **How to reproduce:** Create a new visit request scheduled for later today, without checking "arriving now." Immediately cancel it. The dashboard's "Visitors Today" counter still includes it.
- **Suggested fix:** Leave `check_in_time` `NULL` at creation for scheduled visits (only set it in the `checkin` action, which already does `check_in_time = NOW()` correctly), and have the dashboard query filter on `status = 'checked_in'` (or `'checked_out'`) rather than trusting a pre-filled timestamp.

### M4. Dead/unused Philippine phone validation helpers — inconsistent with the inline check actually used
- **File:** `modules/visitor/index.php`
- **Function:** `t8_normalize_ph_contact()`, `t8_validate_ph_contact()` (defined but never called); actual validation done inline in `case 'create':`
- **Severity:** Medium
- **Explanation:** Two helper functions exist to normalize/validate PH numbers in multiple input formats (`+63...`, `0...`, `63...`, bare 10-digit). They are never invoked. The form instead only accepts a raw 10-digit suffix appended to a hardcoded `+63` prefix (`preg_match('/^\d{10}$/', ...)`), which is simpler but makes the two helper functions pure dead code and suggests an incomplete refactor — worth confirming which behavior is actually intended.
- **How to reproduce:** Grep the file for `t8_normalize_ph_contact(` / `t8_validate_ph_contact(` — only their `function` declarations appear; no call sites.
- **Suggested fix:** Remove the dead helpers, or (if richer format support is actually wanted) wire them into the create-form validation and drop the narrower inline regex.

### M5. Archiving a facility gives no warning about existing/future reservations
- **File:** `modules/facilities/index.php`
- **Function:** `case 'archive':`
- **Severity:** Medium
- **Explanation:** Archiving only flips `team8_facilities.status`; it never checks `team8_reservations` for approved/pending future bookings against that facility. Existing reservations remain untouched (fine), but the admin gets no warning that people are still expecting to use a facility that just became unavailable for *new* bookings — and there's no equivalent "block archiving if X future reservations exist" safety net some businesses would want.
- **How to reproduce:** Create an approved reservation for a facility dated next week. Archive that facility. No warning is shown; the archive succeeds silently.
- **Suggested fix:** Before archiving, query for `status IN ('pending','approved') AND end_time > NOW()` on that facility and surface a confirmation warning (or block archiving) if any exist.

### M6. Several `*_fetch()` helpers don't filter `deleted_at`, letting soft-deleted parents still be edited via direct URL
- **File(s):** `modules/contracts/index.php` (`t8_contract_fetch`), `modules/legal/index.php` (`t8_legal_case_fetch`), `modules/documents/index.php` (`t8_document_fetch`)
- **Function:** each named fetch function, used by `edit`, `parties`, `obligations`, `documents`, `upload_version`, etc.
- **Severity:** Medium
- **Explanation:** None of these fetch functions add `WHERE deleted_at IS NULL`. Once a contract/case/document is archived (soft-deleted), a user who still has (or guesses) its URL — e.g. `?page=contracts&action=obligations&id=12` — can still add parties/obligations/documents or upload a new file version to it, silently undermining the "archived = inactive" intent without ever formally restoring the record.
- **How to reproduce:** Archive a contract, then navigate directly to `?page=contracts&action=obligations&id=<archived-id>` — the obligations form still loads and accepts new obligations against the archived contract.
- **Suggested fix:** Add `AND deleted_at IS NULL` to each fetch (or an explicit "is this active?" check after fetch) for any action that mutates state, and show a clear "this record is archived" message instead.

### M7. N+1 query pattern for reservation conflict annotation
- **File:** `modules/reservation/index.php`
- **Function:** `t8_reservations_annotate_conflicts()`
- **Severity:** Medium (performance)
- **Explanation:** For every reservation row in a list, a separate `t8_reservation_has_conflict()` query is issued. This runs once per row, for *both* `$pendingReservations` and `$allReservations` on the admin view (so up to ~2×N queries per page load). This will scale poorly as reservation volume grows.
- **How to reproduce:** Seed a few hundred reservations, load `?page=reservation` as admin, and observe the query count (e.g., via a profiler or `mysql general_log`).
- **Suggested fix:** Replace the per-row lookup with a single self-join query that flags overlaps for the whole result set at once (e.g., `EXISTS` subquery joined against `team8_reservations` in the same `SELECT`).

### M8. Uploaded document storage has no execution-deny hardening beyond an extension whitelist
- **File:** `modules/documents/index.php` (`t8_document_validate_upload`), `public/uploads/` (no `.htaccess`)
- **Function:** `t8_document_validate_upload()`
- **Severity:** Medium
- **Explanation:** Upload acceptance is extension-only (`pdf, doc, docx, xls, xlsx, ppt, pptx, txt, png, jpg, jpeg`) with no MIME/content-type verification and no `.htaccess` inside `public/uploads/` denying script execution (unlike `app/` and `database/`, which both have deny-all `.htaccess` files). None of the whitelisted extensions are natively executable under a stock Apache+PHP config, so this is defense-in-depth rather than an immediate RCE — but it's a real gap given the rest of the project is otherwise careful about this pattern.
- **How to reproduce:** N/A directly exploitable with the current whitelist, but note the asymmetry: `app/.htaccess` and `database/.htaccess` both exist; `public/uploads/` has none.
- **Suggested fix:** Add a `public/uploads/.htaccess` that denies script execution (`RemoveHandler`/`php_flag engine off` or `<FilesMatch>` deny for executable extensions) as a belt-and-suspenders measure, and consider a `finfo`/MIME check in addition to extension matching.

### M9. AI Assistant has no per-user rate limiting
- **File:** `modules/assistant/index.php`
- **Function:** POST handler
- **Severity:** Medium
- **Explanation:** The only limits are message-length (1000 chars) and CSRF. There's nothing stopping a logged-in user (or a compromised session) from firing rapid repeated requests, each of which costs a real OpenAI API call — a cost/DoS exposure once (if) the AI key is actually wired up (see H4).
- **How to reproduce:** Script repeated POSTs to `?page=assistant` with a valid CSRF token and session cookie — no throttling kicks in.
- **Suggested fix:** Add a simple per-user rate limit (e.g., N requests per minute, tracked in session or a DB table).

### M10. Session role/identity is never re-validated against the database per request
- **File:** `app/includes/auth_check.php`
- **Function:** `t8_current_user_id()`, `t8_current_role()`
- **Severity:** Medium
- **Explanation:** Once `$_SESSION['role']` is set at login, every subsequent request trusts it as-is. If an admin changes a user's role, or a user account is disabled/soft-deleted, an already-logged-in session keeps its old privileges until it naturally expires or the user logs out — there's no server-side re-check.
- **How to reproduce:** (Would require a role-change UI, which doesn't currently exist in the pasted code, so this is a latent risk rather than something exploitable today — flagging for when such a feature is added.)
- **Suggested fix:** Periodically (or on each request, if cheap enough) re-verify the session's role/active status against the `users`/`user_roles` tables, or at minimum re-check on sensitive actions.

### M11. Non-admin employees can see every other employee's full reservation details
- **File:** `modules/reservation/index.php`
- **Function:** the "All Reservations" query in the non-admin branch of the list view
- **Severity:** Medium
- **Explanation:** `$allReservationsStmt` for non-admins fetches every reservation system-wide (department, key person, requester name, free-text description/notes) with no scoping. Depending on the business's privacy expectations, this may be over-exposure — any employee can browse the full detail of everyone else's bookings, not just facility/time-slot availability.
- **How to reproduce:** Log in as a non-admin (`facilities_staff`/`front_desk`/etc.), open `?page=reservation` — the "All Reservations" table shows every user's department, key person, and notes.
- **Suggested fix:** If full visibility is intentional (to help avoid double-booking), fine — but confirm that with the product owner; otherwise redact `description`/`key_person` for reservations that aren't the viewer's own.

### M12. Write actions across several modules rely on uncaught `PDOException` for FK/constraint failures
- **File(s):** `modules/legal/index.php` (`case 'create'/'edit'`, `documents` attach), `modules/contracts/index.php` (`case 'create'/'edit'`, `parties`, `documents`), `modules/documents/index.php` (`case 'create'`)
- **Function:** the respective `INSERT`/`UPDATE` calls
- **Severity:** Medium
- **Explanation:** None of these inserts pre-check that referenced foreign keys (e.g., `assigned_to`, `document_id`, `owner_id`) still exist beyond what the dropdown originally offered. If a referenced row is deleted between page-load and submit, the `INSERT` throws a `PDOException` (since `PDO::ATTR_ERRMODE` is `EXCEPTION` project-wide) that nothing here catches — resulting in an unhandled 500 instead of a friendly validation message.
- **How to reproduce:** Load the "New Legal Case" form, then (in another session/tab) hard-delete the user you're about to select as `assigned_to` from `users` directly in the DB, then submit — request 500s with an uncaught `PDOException`.
- **Suggested fix:** Wrap constraint-sensitive inserts in try/catch and translate FK violations into a normal `$errors[]` message ("the selected item no longer exists, please refresh").

---

## 🟢 LOW

### L1. No pagination on any list view
- **File(s):** `modules/documents/index.php`, `modules/retention/index.php`, `modules/contracts/index.php`, `modules/legal/index.php`, `modules/reservation/index.php`
- **Severity:** Low (performance/code smell)
- **Explanation:** Every list query (`$documents`, `$records`, `$contracts`, `$cases`, `$allReservations`, etc.) fetches the entire table with no `LIMIT`/offset. Fine at small-business scale today; will degrade as data grows.
- **Suggested fix:** Add basic offset/limit pagination once row counts grow past a few hundred.

### L2. `templates/sidebar.php` re-`require`s `routes.php` on every request
- **File:** `templates/sidebar.php`
- **Severity:** Low
- **Explanation:** `index.php` already loads `routes.php` once via `require __DIR__ . '/app/config/routes.php'`. `sidebar.php` does its own `require __DIR__ . '/../app/config/routes.php'` (not `require_once`), re-parsing and re-evaluating the file. Harmless (it just returns an array), but duplicated work and a duplicated source of truth for "where is the route list."
- **Suggested fix:** Pass the already-loaded `$routes` array into the template, or use `require_once`.

### L3. Missing nav subtitle for the Facilities route
- **File:** `templates/navbar.php`
- **Severity:** Low (cosmetic)
- **Explanation:** `$t8NavSubtitles` has entries for `dashboard, reservation, visitor, documents, retention, legal, contracts` but not `facilities` — visiting `?page=facilities` shows no subtitle line under the page title.
- **Suggested fix:** Add a `'facilities' => '...'` entry.

### L4. No uniqueness check on retention schedule `record_type`
- **File:** `modules/retention/index.php`
- **Function:** `case 'create_schedule':`
- **Severity:** Low
- **Explanation:** Two schedules with the identical `record_type` (e.g., "Financial Records" typed twice) can both be created, which will confuse the dropdown in `create_record`.
- **Suggested fix:** Check for an existing `record_type` (case-insensitive) before inserting, or add a `UNIQUE` constraint.

### L5. OpenAI error messages relayed verbatim to end users
- **File:** `app/includes/ai_helper.php`
- **Function:** `t8_openai_chat()`
- **Severity:** Low
- **Explanation:** `throw new RuntimeException('OpenAI API error: ' . $apiMessage);` and the caller in `modules/assistant/index.php` sends `$e->getMessage()` straight back to the browser as JSON. Minor information disclosure about the backend integration/provider.
- **Suggested fix:** Log the detailed error server-side; return a generic "the assistant is temporarily unavailable" message to the client.

### L6. `.env` uses spaces around `=` — parser compatibility unconfirmed — `UNCERTAIN`
- **File:** `.env`
- **Severity:** Low (confidence: low, flagging for verification only)
- **Explanation:** The file reads `OPENAI_API_KEY = sk-proj-...` with spaces around `=`. Some minimal hand-rolled `.env` parsers split naively on `=` and don't trim whitespace, which would leave a leading space in the value (breaking the `Authorization: Bearer <key>` header). Since no loader is present in the pasted files (see H4), I can't confirm whether this actually matters — flagging so it's checked once the loader is located.
- **Suggested fix:** Remove spaces around `=` (`OPENAI_API_KEY=sk-proj-...`) to be safe regardless of parser used.

---

## Summary table

| ID | File | Severity |
|----|------|----------|
| C1 | schema.sql / visitor migration / visitor module | Critical |
| C2 | reservation module / footer.php / delete-request migration | Critical |
| C3 | README / .htaccess / .env | Critical |
| H1 | login.php | High |
| H2 | modules/documents/index.php | High |
| H3 | modules/reservation/index.php | High |
| H4 | app/config/config.php / ai_helper.php | High (uncertain) |
| H5 | modules/reservation/index.php | High |
| M1 | modules/reservation/index.php + reservation.js | Medium |
| M2 | modules/documents/index.php | Medium |
| M3 | modules/visitor/index.php + dashboard | Medium |
| M4 | modules/visitor/index.php | Medium |
| M5 | modules/facilities/index.php | Medium |
| M6 | contracts / legal / documents fetch helpers | Medium |
| M7 | modules/reservation/index.php | Medium |
| M8 | modules/documents/index.php + public/uploads | Medium |
| M9 | modules/assistant/index.php | Medium |
| M10 | app/includes/auth_check.php | Medium |
| M11 | modules/reservation/index.php | Medium |
| M12 | legal / contracts / documents modules | Medium |
| L1 | multiple list views | Low |
| L2 | templates/sidebar.php | Low |
| L3 | templates/navbar.php | Low |
| L4 | modules/retention/index.php | Low |
| L5 | app/includes/ai_helper.php | Low |
| L6 | .env | Low (uncertain) |

---

## Recommended priority order

1. **C1** — resolve schema drift for Visitor Management before touching anything else in that module; right now it's arguably not deployable from a clean clone.
2. **C3** — rotate the exposed API key and add app-level protection for `.env`/`database/*.sql` regardless of web server.
3. **C2** — confirm whether the delete-request workflow code exists somewhere not pasted; if not, it needs to be built against the existing migration.
4. **H1, H5** — both are exploitable logic/security gaps with concrete repro steps; worth fixing before the next milestone review.
5. **H2, H3** — quick, contained fixes (wrap in try/catch; add one date comparison).
6. Everything else can be scheduled as normal backlog/tech-debt.
