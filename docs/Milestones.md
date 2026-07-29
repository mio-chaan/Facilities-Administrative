# Milestones

Build one milestone at a time. Complete testing before moving to the next.
Keep commits small and meaningful. Update this file's checkboxes and
`docs/Database.md` / `docs/API.md` after each completed milestone.

- [x] **Milestone 0 — Project Setup**
      Folder structure, plain-PHP-constant config (`app/config/config.php`
      / `database.php`), PDO connection, front-controller routing, base
      layout (navbar/sidebar/footer), vanilla CSS design system, JS setup.
- [x] **Milestone 1 — Authentication (temporary stand-in)** — real
      login/logout built by Team 8 (`login.php`, `logout.php`) against
      the shared `users` table, since the owning team's system-wide
      module hasn't landed yet. See `docs/Auth.md`. Will be swapped
      for the real thing once available — no other module code should
      need to change when that happens.
- [x] **Milestone 2 — Dashboard** — statistics, recent activity, quick
      actions, and notifications are all live (real DB-backed stat
      cards, `audit_logs`-driven activity feed, per-user notification
      list on `modules/dashboard/index.php`).
- [x] **Milestone 3 — Reservation Module** — create/update/cancel,
      approval workflow, reservation calendar. See
      `modules/reservation/index.php`, `public/css/reservation.css`,
      `public/js/reservation.js`, and the Milestone 3 notes in
      `docs/API.md` / `docs/Database.md`.
- [ ] Milestone 4 — Visitor Management — registration, check-in/out,
      visitor history
- [ ] Milestone 5 — Document Management — upload, download, search,
      archive, categories
- [ ] Milestone 6 — Records Retention — retention policies,
      expiration, disposal schedule, archive
- [ ] Milestone 7 — Legal Documents — legal records, compliance,
      case tracking
- [ ] Milestone 8 — Contract Management — contract records, renewal
      reminders, expiration tracking
- [ ] Milestone 9 — Reports — reservation/visitor/document reports,
      export to PDF/Excel
- [ ] Milestone 10 — System Completion — testing, bug fixes,
      performance optimization, documentation, deployment prep

## Future Improvements (post-Milestone 10, not scheduled)
- Email notifications
- SMS notifications
- QR code check-in
- Audit log UI / activity timeline
- Multi-office support
- Dark mode
- Responsive mobile UI polish
- Multi-step reservation approval chains (currently single-step:
  any `admin`/`facilities_staff` user can approve/reject)
