# Legal Management System — Development Plan

## 1. Purpose and Scope

The Legal Management System for RAM YUM should function as a **business legal-management module**, rather than a full law-firm case-management platform.

Its primary purpose is to help RAM YUM manage:

- Employee-related legal matters
- Labor-related cases
- Government and regulatory matters
- Business disputes
- Administrative/legal cases
- Compliance-related legal matters
- Other business legal matters

### Core Workflow

```text
Legal Matter Identified
        ↓
Create Legal Case
        ↓
Initial Assessment
        ↓
Assignment
        ↓
Actions / Tasks / Deadlines
        ↓
Hearings / Government Proceedings
        ↓
Documents & Evidence
        ↓
Resolution
        ↓
Closed / Archived
```

The first implementation should focus on the core legal-management workflow instead of attempting to reproduce every feature of a commercial legal platform.

---

# 2. Core Modules

The Legal Management System should be built around the following modules:

1. Legal Cases
2. Parties
3. Tasks & Deadlines
4. Hearings / Proceedings
5. Legal Documents
6. Notes
7. Communications
8. Case Timeline
9. Case Resolution
10. Audit Trail
11. Access Control
12. Dashboard & Reports

---

# 3. Phase 1 — Define the Legal Scope

Before implementation, finalize the types of legal matters the system will support.

### Initial Case Types

Possible case types include:

- Labor
- Employee Legal Matter
- Civil
- Administrative
- Corporate / Business
- Government / Regulatory
- Compliance
- Contract Dispute
- Other

The exact list should be stored in the database so that authorized administrators can manage it without modifying source code.

---

# 4. Phase 2 — Legal Cases Module

The Legal Cases module is the core of the system.

## 4.1 Legal Cases List

Recommended layout:

```text
LEGAL CASES

[Search................] [Status ▼] [Type ▼] [Department ▼]
                                      [+ New Legal Case]

-----------------------------------------------------------------------
Case No. | Title | Type | Department | Status | Deadline | Assigned | ⋮
-----------------------------------------------------------------------
LC-2026-001
Employee Complaint
Labor
Human Resources
Open
Oct 15, 2026
Dev Tester
⋮
-----------------------------------------------------------------------
```

### Recommended table columns

- Case Number
- Case Title
- Case Type
- Department
- Status
- Priority
- Filed Date
- Deadline
- Assigned Officer
- Actions

### Required list features

- Search
- Status filter
- Case Type filter
- Department filter
- Priority filter
- Assigned Officer filter
- Filed Date filter
- Deadline filter
- New Legal Case button
- Pagination when necessary

### Phase 2 implementation exception

The Phase 2 list currently displays the existing synthetic reference format
(`CASE-000001`). Persistent generated case numbers in the planned
`LC-YYYY-NNN` format are deferred to the Create / Edit Case phase because
they require a database-backed numbering field and generation workflow.

---

# 5. Phase 3 — Create New Legal Case

The creation form should collect the minimum information necessary to establish a legal case.

Do not turn the creation form into one enormous form containing every possible case field.

## 5.1 Basic Information

Recommended fields:

- Case Number — system generated
- Case Title — required
- Case Type — required
- Subject
- Description / Summary
- Priority — required
- Status — required

### Case Number

The case number should be automatically generated.

Example:

```text
LC-2026-001
LC-2026-002
LC-2026-003
```

Users should not manually enter or freely modify the generated case number.

---

## 5.2 Organization and Assignment

Fields:

- Department — required
- Assigned Legal Officer — required
- Supporting Staff — optional

Where appropriate, the currently authenticated user may be recorded automatically as the creator.

---

## 5.3 Important Dates

Fields:

- Filed Date — required
- Deadline
- Next Action Date
- Closing Date

Validation should prevent logically invalid dates, such as a deadline occurring before the filed date.

### Phase 3 Completion Checklist

- [x] Basic information is captured: generated case number, required title, case type, priority, and status; subject and description are optional.
- [x] Case numbers use the `LC-YYYY-NNN` format, are allocated safely per year, and cannot be edited by users.
- [x] Department and assigned legal officer are required; supporting staff is optional.
- [x] Filed date is required; deadline, next-action date, and closing date are optional.
- [x] Server-side date validation rejects invalid dates and dates before the filed date.

---

# 6. Phase 4 — Parties

Parties should be represented as structured records instead of relying only on free-text fields.

## Recommended structure

```text
Parties

[+ Add Party]

Name
Party Type
Contact
Organization
Role
```

Example:

```text
Juan Dela Cruz
Party Type: Employee
Role: Complainant

ABC Supplier Inc.
Party Type: External Organization
Role: Respondent
```

## Possible Party Types

- Employee
- Customer
- Supplier
- Government Agency
- Company / Organization
- Complainant
- Respondent
- Witness
- Other

A case should support multiple parties.

---

# 7. Phase 5 — Case Details

The Case Details page is the main workspace for a legal case.

Recommended structure:

```text
LEGAL CASE
LC-2026-001

Employee Complaint
Labor Case

[Edit Case] [⋮]

OPEN
HIGH PRIORITY

Filed: September 26, 2026
Deadline: October 15, 2026
Assigned: Dev Tester
Department: Human Resources
```

## Recommended tabs / sections

```text
Overview
Parties
Timeline
Tasks
Hearings
Documents
Notes
Communications
Activity
```

The system should avoid putting all information into one excessively long page.

---

# 8. Phase 6 — Case Information

Legal cases may require additional external/legal information.

Recommended fields:

- Court / Agency
- Branch / Office
- Docket / Reference Number
- Jurisdiction
- Location
- Legal Basis / Applicable Law
- Current Action / Next Step

These fields may be optional because not every business legal matter involves a court or government agency.

---

# 9. Phase 7 — Tasks & Deadlines

Legal work is deadline-driven, so tasks should be a first-class feature.

## Task structure

```text
Tasks

[+ Add Task]

Task:
Submit response to agency

Assigned To:
Legal Officer

Due Date:
October 15, 2026

Priority:
High

Status:
Pending
```

## Task statuses

- Pending
- In Progress
- Completed
- Overdue
- Cancelled

The system should automatically determine whether a task is overdue based on its due date instead of requiring users to manually select `Overdue`.

---

# 10. Phase 8 — Hearings / Proceedings

Create a dedicated feature for hearings and legal proceedings.

## Hearing fields

- Date
- Time
- Venue
- Hearing / Proceeding Type
- Purpose
- Status
- Notes

## Hearing statuses

- Scheduled
- Completed
- Postponed
- Cancelled

Example:

```text
October 20, 2026
9:00 AM

NLRC Branch ___

Type:
Initial Conference

Purpose:
Initial conference with parties

Status:
Scheduled
```

---

# 11. Phase 9 — Legal Documents

Legal Management should integrate with the existing **Document Management System** rather than duplicating it.

The Legal module should establish the relationship:

```text
Legal Case
    ↓
Documents
```

Users should be able to:

- Attach an existing document
- Upload a new legal document
- View attached documents
- Remove an attachment when authorized
- Open the document through the existing document-management access rules

## Possible legal document types

- Complaint
- Notice
- Response
- Affidavit
- Evidence
- Government Letter
- Court / Agency Document
- Settlement Agreement
- Decision
- Other

The actual document repository remains part of Document Management.

---

# 12. Phase 10 — Legal Notes

Legal notes should be separate from official documents.

Example:

```text
CASE NOTES

Sep 28, 2026
Reviewed employee statement.
Need additional supporting documents.

— Dev Tester
```

Notes should record:

- Author
- Date/time
- Content
- Case relationship

Important distinction:

```text
Notes = internal case-management information
Documents = formal stored records/files
```

---

# 13. Phase 11 — Communications

The system should be able to record important communications related to a case.

Examples:

- Email
- Letter
- Government communication
- Internal communication
- External counsel communication
- Phone call record

Possible fields:

- Communication Date
- Communication Type
- Direction (Incoming / Outgoing)
- Sender
- Recipient
- Subject
- Summary
- Attachment
- Recorded By

The first implementation does not need to integrate directly with email. Manual communication records are sufficient.

---

# 14. Phase 12 — Case Timeline

Every significant case event should appear in chronological order.

Example:

```text
CASE TIMELINE

Sep 26, 2026
Case Created
by Dev Tester

Sep 27, 2026
Initial Assessment Added
by Legal Officer

Oct 02, 2026
Notice Received
by Legal Officer

Oct 05, 2026
Response Submitted
by Legal Officer
```

Timeline events may include:

- Case creation
- Case edits
- Assignment changes
- Status changes
- Party changes
- Task creation/completion
- Hearing creation/completion
- Document attachment
- Notes
- Communications
- Resolution
- Closure
- Archive

---

# 15. Phase 13 — Case Status and Lifecycle

Define the case lifecycle before implementation.

Recommended statuses:

```text
OPEN
   ↓
UNDER REVIEW
   ↓
ACTIVE
   ↓
RESOLVED
   ↓
CLOSED
   ↓
ARCHIVED
```

The system should control valid status transitions.

For example:

- An archived case should not normally be edited.
- A case should be closed before it can be archived.
- Resolution information should be recorded before closing a case where appropriate.

Avoid allowing users to arbitrarily select statuses that do not make sense for the case lifecycle.

---

# 16. Phase 14 — Case Resolution

A proper resolution section should be provided before a case is closed.

## Resolution fields

- Resolution Type
- Resolution Summary
- Resolved Date
- Final Outcome
- Supporting Document

Possible resolution types:

- Settled
- Dismissed
- Resolved Internally
- Government Decision
- Court Decision
- Compliance Completed
- Other

Avoid forcing `Won` or `Lost` onto every case because many business legal matters do not have a winner/loser outcome.

---

# 17. Phase 15 — Audit Trail

Legal information requires strong accountability.

Record:

```text
WHO
WHAT
WHEN
```

Example:

```text
Dev Tester
Created Case
Sep 26, 2026 10:15 AM

Admin
Changed Status:
Open → Under Review
Sep 27, 2026 2:31 PM

Legal Officer
Added Hearing
Sep 28, 2026 9:02 AM
```

## Important actions to audit

- Case creation
- Case edits
- Status changes
- Assignment changes
- Party changes
- Deadline changes
- Hearing changes
- Document attachment/removal
- Case closure
- Case archive
- Resolution changes

Use the existing system audit architecture where possible rather than creating unrelated logging mechanisms.

---

# 18. Phase 16 — Access Control

Legal records may contain confidential information, so access should be restricted.

## Initial permission model

| Role | Access |
|---|---|
| Admin | Full legal management |
| Legal Officer | Manage assigned / authorized cases |
| Authorized Staff | Access relevant cases and tasks |
| Other Employees | No legal-case access unless explicitly authorized |

The implementation should enforce authorization on the server side, not only hide buttons in the interface.

---

# 19. Phase 17 — Legal Dashboard

Build the dashboard only after the core case workflow works.

Recommended dashboard cards:

```text
TOTAL ACTIVE CASES       12
OPEN CASES                8
UPCOMING DEADLINES        4
UPCOMING HEARINGS         2
OVERDUE TASKS             1
RECENTLY CLOSED           3
```

## Operational dashboard sections

### Upcoming Deadlines

```text
LC-2026-001
Submit response
Oct 15

LC-2026-004
Agency submission
Oct 18
```

### Upcoming Hearings

```text
LC-2026-002
Initial Conference
Oct 20
```

The dashboard should help legal staff act on upcoming work, rather than only display decorative statistics.

---

# 20. Phase 18 — Search and Filtering

As the number of cases increases, search becomes essential.

## Searchable fields

- Case Number
- Case Title
- Subject
- Party Name
- Docket / Reference Number

## Filters

- Status
- Case Type
- Department
- Priority
- Assigned Officer
- Filed Date
- Deadline

Additional operational filters:

- Overdue
- Upcoming Deadline
- Upcoming Hearing

---

# 21. Phase 19 — Database Architecture

The database should be relational and avoid putting all legal information into one table.

Recommended structure:

```text
legal_cases
    │
    ├── legal_case_parties
    │       └── parties
    │
    ├── legal_case_tasks
    │
    ├── legal_case_hearings
    │
    ├── legal_case_documents
    │
    ├── legal_case_notes
    │
    ├── legal_case_communications
    │
    ├── legal_case_timeline
    │
    └── legal_case_audit
```

Supporting tables:

```text
legal_case_types
legal_case_statuses
legal_party_types
```

Reuse existing system tables wherever appropriate:

```text
users
employees
departments
documents
audit_logs
```

Avoid creating duplicate user, employee, department, or document tables.

---

# 22. Phase 20 — Validation and Security

Before considering the module complete, test the following.

## Authorization

- Can unauthorized users access legal cases?
- Can unauthorized users edit cases?
- Can unauthorized users download legal documents?
- Can unauthorized users archive cases?

## Validation

- Can a deadline be before the filed date?
- Can a closed case be edited?
- Can an archived case be modified?
- Can a required assignment be left empty?
- Can duplicate parties be created accidentally?
- Can invalid status transitions occur?

## Security

Apply the same security principles used by the rest of the RAM YUM system:

- Authentication
- Role-based authorization
- Prepared SQL statements
- CSRF protection
- Server-side validation
- Secure file access
- Secure file upload handling
- Audit logging
- Input/output escaping

---

# 23. Recommended Implementation Order

Do not attempt to build the entire Legal Management System in one implementation prompt.

Implement it in controlled phases:

```text
PHASE 1
Database + Case Types + Statuses
        ↓
PHASE 2
Legal Cases List
        ↓
PHASE 3
Create / Edit Case
        ↓
PHASE 4
Case Details
        ↓
PHASE 5
Parties
        ↓
PHASE 6
Tasks + Deadlines
        ↓
PHASE 7
Hearings
        ↓
PHASE 8
Legal Documents
        ↓
PHASE 9
Notes + Communications
        ↓
PHASE 10
Timeline + Audit Trail
        ↓
PHASE 11
Permissions / Security
        ↓
PHASE 12
Dashboard + Reports
        ↓
PHASE 13
Full QA / Validation
```

---

# 24. Target System Architecture

The final Legal Management System should conceptually work like this:

```text
                         LEGAL MANAGEMENT SYSTEM
                                  │
                                  ▼
                            LEGAL CASES
                                  │
             ┌────────────────────┼────────────────────┐
             │                    │                    │
             ▼                    ▼                    ▼
          Parties              Tasks              Hearings
             │                    │                    │
             └────────────────────┼────────────────────┘
                                  │
                                  ▼
                              Timeline
                                  │
             ┌────────────────────┼────────────────────┐
             │                    │                    │
             ▼                    ▼                    ▼
         Documents              Notes          Communications
             │                    │                    │
             └────────────────────┼────────────────────┘
                                  │
                                  ▼
                              Resolution
                                  │
                                  ▼
                           Closed / Archive
                                  │
                                  ▼
                             Audit Trail
```

---

# 25. Core Design Principle

The most important architectural distinction is:

```text
Document Management
        =
Managing documents and files

Legal Management
        =
Managing legal matters and everything that happens to them
```

A legal case may contain documents, but a legal case is not simply a document category.

The Legal Management System should therefore manage:

```text
Case
 ├── Parties
 ├── Tasks
 ├── Deadlines
 ├── Hearings
 ├── Documents
 ├── Notes
 ├── Communications
 ├── Timeline
 ├── Resolution
 └── Audit History
```

This structure makes the module a genuine **Legal Management System** while keeping it appropriate for RAM YUM's business environment.

---

# 26. Future Enhancements

These features can be considered after the core system is stable:

- External Counsel Management
- Legal Billing & Expenses
- Advanced Legal Research / Knowledge Base
- Contract-to-Case linking
- Automated deadline notifications
- Advanced compliance tracking
- E-signature integration
- Advanced reporting
- Calendar integration
- Email integration
- AI-assisted legal document summarization
- AI-assisted case timeline summarization

These should remain secondary until the core case-management workflow is reliable.
