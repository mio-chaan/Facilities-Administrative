You are a Senior Software Architect, Senior PHP Developer, Database Architect, UI/UX Designer, QA Engineer, and Security Engineer.

You are working on an EXISTING project called:

TEAM 8 - RAM YUM
Facilities & Administrative Management System

Technology Stack

- PHP (Native)
- MySQL
- HTML
- CSS
- JavaScript

IMPORTANT

DO NOT rewrite the project.

DO NOT redesign unrelated modules.

DO NOT introduce frameworks.

DO NOT modify existing coding style.

DO NOT break existing functionality.

Instead, extend the current Document Management module.

==========================================================
FIRST TASK
==========================================================

Before writing any code:

1. Analyze the current project structure.

2. Analyze the existing Document Management module.

3. Review all related database tables.

4. Detect reusable components.

5. Detect reusable modals.

6. Detect reusable upload logic.

7. Detect reusable notification system.

8. Detect reusable authentication/session handling.

9. Produce an implementation plan.

Only after completing the analysis should implementation begin.

==========================================================
PROJECT GOAL
==========================================================

Upgrade Document Management from a simple file upload system into an HR Document Automation module.

This module must support both:

• Traditional document uploads

AND

• Generated HR documents.

The implementation should feel like an enterprise HR system while remaining appropriate for a capstone project.

==========================================================
SIDEBAR
==========================================================

DO NOT add new sidebar items.

Keep only

Document Management

All new features must exist INSIDE the existing Document Management page.

==========================================================
MAIN PAGE LAYOUT
==========================================================

When the user opens Document Management display a dashboard.

Use a responsive two-column layout.

Top Section

Document Management

Description

Search

Quick Actions

Buttons

Upload Existing Document

Generate HR Document

--------------------------------------------------------

Second Section

LEFT CARD

Quick Actions

• Upload Existing File

• Incident Report

• Notice To Explain

• Memorandum

• Warning Letter

• Certificate

RIGHT CARD

Recent Documents

Display latest uploaded/generated documents.

--------------------------------------------------------

Third Section

LEFT CARD

Document Statistics

Total Documents

Generated Documents

Archived

Templates

RIGHT CARD

Pending Actions

Pending Incident Reports

Pending NTE

Pending Explanations

Pending Approval

==========================================================
GENERATE DOCUMENT
==========================================================

Clicking

Generate HR Document

must NOT create another sidebar page.

Instead replace the main content.

Display

Choose Template

Incident Report

Notice To Explain

Memorandum

Warning Letter

Certificate

Click Continue

Then load the selected form.

==========================================================
INCIDENT REPORT
==========================================================

Security is extremely important.

The system MUST trust the authenticated session.

Never trust user input for identity.

Automatically retrieve from session:

Employee ID

Employee Name

Department

Position

Prepared By

Date Filed

Time Filed

Display these as read-only.

Do NOT allow editing.

User should only fill:

Incident Date

Incident Time

Incident Location

Incident Type

Description

Witness

Attachment

==========================================================
BACKEND SECURITY
==========================================================

Never use

$_POST['employee_id']

Never use

$_POST['department']

Always use

$_SESSION

Example

employee_id

department_id

prepared_by

must come directly from the authenticated session.

Even if a malicious user edits HTML using browser developer tools, the backend must ignore any submitted identity values.

==========================================================
DATABASE
==========================================================

Normalize the database.

Reuse existing tables whenever possible.

Create only the necessary tables.

Incident Reports

Store

employee_id FK

prepared_by FK

status

incident_date

incident_time

incident_location

incident_type

description

witness

attachment

created_at

updated_at

Do not duplicate employee names.

Retrieve employee information using JOINs.

==========================================================
NOTICE TO EXPLAIN
==========================================================

Admin can generate an NTE directly from an Incident Report.

Automatically populate

Employee

Department

Incident Summary

Incident Date

Only require

Deadline

Remarks

Store relationship

Incident Report

↓

Notice To Explain

==========================================================
EXPLANATION LETTER
==========================================================

Employee receives NTE.

Employee submits Explanation Letter.

Workflow

Incident Report

↓

NTE

↓

Employee Explanation

↓

Admin Review

↓

Archive

==========================================================
MEMORANDUM
==========================================================

Allow admin to generate memorandums.

Fields

Title

Recipients

Content

Remarks

Prepared By

Generate printable version.

==========================================================
CERTIFICATES
==========================================================

Provide templates

Certificate of Employment

Certificate of Recognition

Certificate of Attendance

Auto-fill employee information.

==========================================================
DOCUMENT STATUS
==========================================================

Every generated document must support

Draft

Pending

Approved

Rejected

Archived

Display colored badges.

==========================================================
VERSION HISTORY
==========================================================

Editing an approved document must NOT overwrite the original.

Instead create a new version.

Maintain version history.

==========================================================
PRINTING
==========================================================

Every generated document must support

Print Preview

Print

Company Header

Prepared By

Generated Date

Signature Area

Document Number

==========================================================
NOTIFICATIONS
==========================================================

Reuse the existing notification system.

Generate notifications for

New Incident Report

New NTE

Explanation Submitted

Approved

Rejected

Archived

==========================================================
UI/UX
==========================================================

Keep the current project theme.

Do not redesign the sidebar.

Maintain consistent spacing.

Use cards.

Use responsive two-column layouts.

Reduce unnecessary scrolling.

Keep forms clean and professional.

==========================================================
IMPLEMENTATION ORDER
==========================================================

Phase 1

Database Review

Phase 2

UI Layout

Phase 3

Document Dashboard

Phase 4

Template Selection

Phase 5

Incident Report

Phase 6

Notice To Explain

Phase 7

Explanation Letter

Phase 8

Memorandum

Phase 9

Certificates

Phase 10

Notifications

Phase 11

Print Templates

Phase 12

Testing

==========================================================
FINAL REQUIREMENT
==========================================================

Implement everything incrementally.

After each phase:

• Explain what was changed.

• Explain database changes.

• Explain security considerations.

• Ensure existing modules continue working.

Never sacrifice maintainability, normalization, or security.