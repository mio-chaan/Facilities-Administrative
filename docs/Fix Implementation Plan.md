# audit by cursor validate by GPT
# Document & HR Management — Fix Implementation Plan

## Purpose

This document converts the Document Management and HR Management audit findings into an ordered implementation plan.

The fixes must be implemented **piece by piece**, not as one large change. Each phase must be completed, tested, and committed before proceeding to the next phase.

---

# Implementation Rules

1. Do not rewrite unrelated modules.
2. Do not change existing business rules unless explicitly required by a fix.
3. Inspect existing code before modifying it.
4. Reuse existing helpers, constants, routes, and database structures where possible.
5. Do not duplicate authorization logic across modules.
6. Every security-sensitive change must be tested using both authorized and unauthorized users.
7. Database changes must use migrations.
8. Do not remove existing data during implementation.
9. Preserve existing audit logging.
10. After every phase:

* Run the application.
* Test the affected workflow.
* Check PHP errors/logs.
* Review the diff.
* Commit the changes.

---

# Phase 0 — Baseline and Backup

## Goal

Create a safe rollback point before implementing fixes.

### Tasks

* Commit the current working `dashboard` branch.
* Create a backup/tag before fixes.
* Confirm the application currently starts correctly.
* Confirm the current database schema/migrations are known.
* Do not modify application behavior.

### Acceptance Criteria

* Application runs normally.
* Current branch is committed.
* A rollback point exists.
* Database can be restored if necessary.

---

# Phase 1 — Document Authorization Foundation

## Audit References

* Security #1–4
* Missing #1
* Missing #2
* Missing #9
* Incomplete #3
* Incomplete #9
* Incomplete #10

## Goal

Create one consistent authorization layer for documents.

### Required Authorization Concepts

Implement reusable authorization checks for:

```text
VIEW
DOWNLOAD
EDIT
PRINT
APPROVE
```

Prefer reusable helpers such as:

```php
t8_document_can_view()
t8_document_can_download()
t8_document_can_edit()
t8_document_can_print()
t8_document_can_approve()
```

Use the project's existing authentication/session system.

### Important

Do not automatically grant access based on `owner_id` or department until the actual business rule has been decided.

The authorization layer must clearly distinguish:

* Admin access
* Uploader access
* Owner access
* Department access, if applicable
* Legal-case access
* Recipient access

### Acceptance Criteria

* Authorization is centralized.
* Existing legitimate access continues to work.
* Unauthorized users cannot access records by changing URL IDs.
* The helpers can be reused by Documents, HR, Legal, Contracts, View, Download, and Print.

---

# Phase 2 — Secure HR Attachment Downloads

## Audit References

* Missing #1
* Security #2

## Goal

Prevent HR attachments from being accessed through public asset URLs.

### Current Problem

HR attachments are stored under protected upload storage but are linked using public asset paths.

### Required Change

Create an authenticated HR/document download action.

Expected flow:

```text
Authenticated User
        ↓
Download Action
        ↓
Fetch Attachment
        ↓
Authorization Check
        ↓
Audit Log
        ↓
readfile()
```

The physical upload path must not be exposed as a public URL.

### Required Checks

* Authentication
* Record existence
* File existence
* Document ownership/authorization
* Safe file path resolution
* Audit logging

### Acceptance Criteria

* Authorized user can download.
* Unauthorized user receives 403/appropriate denial.
* Invalid IDs do not expose files.
* Deleted/nonexistent files are handled safely.
* Download is recorded in the audit log.
* Direct public `/uploads/...` access is not used by HR UI.

---

# Phase 3 — Certificate Authorization

## Audit References

* Missing #2
* Security #1
* Incomplete #5

## Goal

Allow certificate recipients to access their certificates while keeping administrative actions restricted.

### Expected Rules

```text
Admin
    VIEW
    PRINT
    CREATE
    APPROVE/STATUS ACTIONS

Certificate Recipient
    VIEW
    PRINT

Other Staff
    NO ACCESS
```

### Required Changes

* Remove blanket admin-only protection from certificate viewing.
* Add recipient authorization.
* Protect `print.php` with the same authorization rules.
* Prevent access by manipulating certificate IDs.
* Remove dead approval controls if certificates are created as approved.

### Acceptance Criteria

* Admin can view/print certificates.
* Named recipient can view/print their certificate.
* Other employees cannot view/print it.
* Direct print URLs are protected.
* Status transitions cannot be arbitrarily changed through POST requests.

---

# Phase 4 — Legal and Contract Document Authorization

## Audit Reference

* Incomplete #10

## Goal

Ensure Legal and Contract modules use the same document authorization model.

### Tasks

* Review Legal document listing.
* Review Legal download.
* Review document versions.
* Review AI summarize access.
* Review Contract-created documents.
* Ensure Legal officers only access documents related to authorized cases.
* Ensure Contract-created documents do not bypass validation.
* Apply document registration/retention rules where required.

### Acceptance Criteria

* Legal can only access authorized case documents.
* View, download, versions, and summarize use compatible authorization.
* Contracts cannot create documents that bypass Document Management security rules.
* Unauthorized documents do not appear as accessible.

---

# Phase 5 — Upload and MIME Security

## Audit Reference

* Bugs / Logic Issues #8

## Goal

Strengthen file validation.

### Tasks

Review and tighten:

* Extension validation
* MIME validation
* File size validation
* File type mapping
* HR attachment validation

Avoid accepting generic MIME types simply because the extension matches.

### Test Cases

```text
Valid PDF
Valid DOCX
Valid XLSX
Valid PPTX
Valid image
Valid TXT

Renamed ZIP → DOCX
Renamed executable → allowed extension
Unsupported extension
Invalid MIME
Oversized file
```

### Acceptance Criteria

Files cannot pass validation solely by renaming their extension.

---

# Phase 6 — HR State Machine

## Audit References

* Incomplete #4
* Missing #3
* Bugs #3
* Edge Cases

## Goal

Make the Incident → NTE → Explanation workflow logically consistent.

### Expected Flow

```text
Incident Report
      ↓
Pending
      ↓
Approved
      ↓
Generate NTE
      ↓
NTE Issued
      ↓
Explanation Allowed
      ↓
Explanation Submitted
      ↓
Admin Review
      ↓
Accepted
      OR
Returned for Revision
      ↓
Resubmission
```

### Rules

* Explanation cannot be submitted before NTE issuance.
* NTE generation must follow the incident state rules.
* Rejected/archived records cannot arbitrarily return to active states.
* Returned explanations can be resubmitted.
* Existing explanation uniqueness rules must support revision/resubmission.
* Dependent records must remain consistent when a parent record is rejected.

### Acceptance Criteria

Test both valid and invalid transitions.

Example invalid actions:

```text
NTE not issued → submit explanation
Archived → approve
Rejected → approve without valid transition
Second NTE → create duplicate
```

All must be blocked server-side.

---

# Phase 7 — Incident Subject and NTE Improvements

## Audit References

* Missing #7
* Bugs #1
* Edge Cases

## Goal

Separate the person preparing/reporting an incident from the employee who is the subject of the incident when disciplinary workflows require it.

### Required Concepts

```text
prepared_by
subject_employee_id
```

The current user remains the preparer.

The selected employee becomes the subject.

### NTE Picker

Fix:

* This Week
* This Month
* Past incidents
* Current incidents

The filters must include relevant past dates rather than only future dates.

### Acceptance Criteria

* Supervisor/admin can select the subject employee when authorized.
* Prepared-by remains the authenticated user.
* NTE picker correctly finds past incidents.
* Duplicate NTE creation is prevented gracefully.

---

# Phase 8 — Pending Review Queue and Visibility

## Audit References

* Incomplete #1
* Incomplete #2
* Incomplete #3
* Bugs #5

## Goal

Create one consistent administrative review workflow.

### Fix

Replace incorrect:

```text
review_status=pending
```

with the actual browse filter:

```text
status=pending
```

### Pending Review

Provide an admin review queue for applicable records:

```text
Uploads
Incident Reports
NTEs
Explanations
Memorandums
Other reviewable HR documents
```

### Visibility

Synchronize:

```text
Dashboard
Browse
Recent Documents
Notifications
Search
View
Download
```

with the same authorization rules.

### Acceptance Criteria

If a document is shown to a user as accessible, the user must actually be authorized to open it.

---

# Phase 9 — Expiration, Retention, and Notifications

## Audit References

* Missing #5
* Missing #6
* Missing #10
* Edge Cases

## 9.1 Expiration

Implement document lifecycle indicators where applicable:

```text
Active
Expiring Soon
Expired
```

Applicable categories may include:

* Compliance documents
* BIR documents
* Permits
* Legal documents
* Other documents with expiration dates

Do not automatically invalidate documents unless that is an explicit business rule.

### Acceptance Criteria

* Expired documents can be filtered.
* Expiring documents can be identified.
* Owner/admin can be notified when appropriate.

---

## 9.2 Retention

Separate:

```text
Archive
Retention
Disposition
Deletion
```

Do not treat `status=archived` as completion of retention.

### Retention Model

```text
Document
    ↓
Retention Rule
    ↓
Retention Period
    ↓
Retention Start Event
    ↓
Eligible for Disposition
```

Determine appropriate retention policies by document type instead of assuming one universal period.

### HR Records

Explicitly decide whether these participate in the retention system:

* Incident Reports
* NTEs
* Explanations
* Memorandums
* Certificates

---

## 9.3 Approval Notifications

Add notifications when appropriate:

```text
Pending
   ↓
Approved
   ↓
Uploader notified
```

Notifications should include a usable destination to the relevant document.

---

# Phase 10 — Document Number and Transaction Safety

## Audit References

* Bugs #2
* Bugs #4

## Goal

Prevent duplicate document numbers and orphaned files.

### Document Numbers

Replace:

```text
COUNT(*) + 1
```

with the existing sequence mechanism or another concurrency-safe approach.

### Version Upload

Make version creation transactional:

```text
BEGIN
   ↓
Validate
   ↓
Save file
   ↓
Insert version
   ↓
Update parent
   ↓
COMMIT
```

On failure:

```text
ROLLBACK
+
Remove orphan file
```

### Acceptance Criteria

* Concurrent document creation cannot generate duplicate numbers.
* Failed version creation does not leave orphan files.
* Database and filesystem remain consistent.

---

# Phase 11 — Pagination and Performance

## Audit References

* Missing #8
* Edge Cases

## Goal

Prevent unified document browsing from loading unnecessary records.

### Tasks

* Add pagination.
* Add database-level limits.
* Use offset or keyset pagination where appropriate.
* Reduce repeated `t8_document_fetch()` calls.
* Reduce repeated retention queries.
* Move filtering into SQL where practical.
* Add appropriate indexes.

### Acceptance Criteria

Browse does not load the entire document history into PHP memory.

---

# Phase 12 — Search, Template, Version, and Metadata Cleanup

## Audit References

* Missing #4
* Missing #9
* Missing #12
* Incomplete #7
* Incomplete #8
* Incomplete #11

## Tasks

### Template Picker

Either:

* Wire `generate.php` into the actual generation workflow,

or:

* Remove the unused template picker.

Do not leave a dead second generation UX.

### Global Search

Include HR documents while respecting authorization.

Search should support relevant:

* Document numbers
* Titles
* Types
* Statuses
* HR document identifiers

Results must open the actual authorized record.

### Metadata Editing

Allow authorized users to correct appropriate metadata:

* Title
* Category
* Type
* Department
* Owner
* Expiration date

All edits must be audited.

### Version History

Either implement meaningful snapshots or hide empty version-history UI.

If snapshots are implemented, users must be able to view the snapshot.

### Explanation Print

Add an explanation print template if explanations are intended to be printable records.

### Dead Code

Remove or properly wire:

* Unused browse branch
* Unused archive/rejected helpers
* Other dead document-management paths

---

# Phase 13 — Final Permission and Workflow Audit

Perform a complete regression test.

## Role Testing

Test at minimum:

```text
Admin
Facilities Staff
Front Desk
Records Officer
Legal Officer
Employee
```

## Document Testing

Test:

```text
Create
View
Edit
Upload
Download
Approve
Return
Reject
Print
Version
Archive
Restore
Expire
Retention
Delete/Disposition
```

## Security Testing

Attempt direct URL access using another user's:

```text
document ID
certificate ID
attachment ID
version ID
legal document ID
contract document ID
```

Expected result:

```text
Authorized → allowed
Unauthorized → denied
```

---

# Final Acceptance Criteria

The implementation is considered complete only when:

* [ ] HR attachments are not publicly accessible.
* [ ] All downloads require authorization.
* [ ] Certificate printing is authorized.
* [ ] Document authorization is centralized.
* [ ] Legal/Contract document access follows authorization rules.
* [ ] MIME validation is tightened.
* [ ] IR → NTE → Explanation workflow is enforced server-side.
* [ ] Rejected explanations can be returned/resubmitted where applicable.
* [ ] Incident subject and preparer are properly separated where required.
* [ ] Pending Review works correctly.
* [ ] Dashboard and Browse visibility are consistent.
* [ ] Expiration handling exists for applicable documents.
* [ ] Retention policy is explicitly defined.
* [ ] Approval notifications work.
* [ ] Document numbers are concurrency-safe.
* [ ] Version uploads are transactional.
* [ ] Browse uses pagination.
* [ ] Global search respects document authorization.
* [ ] Template picker is either functional or removed.
* [ ] Metadata editing is audited.
* [ ] Version history is functional or intentionally removed.
* [ ] Dead code is removed.
* [ ] Direct URL authorization tests pass.
* [ ] Regression testing passes for all major roles.

---

# Implementation Strategy

Each phase must be implemented as a separate development task.

Recommended cycle:

```text
READ CURRENT CODE
      ↓
PLAN THE CHANGE
      ↓
IMPLEMENT ONE PHASE
      ↓
RUN APPLICATION
      ↓
TEST
      ↓
REVIEW DIFF
      ↓
COMMIT
      ↓
NEXT PHASE
```

Do not give the entire document to Copilot as one instruction to modify the entire system.

Use each phase as a separate Copilot prompt so changes remain reviewable, reversible, and easy to explain during development and defense.
