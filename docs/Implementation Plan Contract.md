Contract Management Implementation Plan
Phase 1 — Define the Contract Domain ✅ DONE

Establish the core data model before building the UI.

Core entities

Contract

ContractVersion

ContractParty

ContractContact

ContractReview

Negotiation

NegotiationComment

Approval

Signature

ContractNotification

ContractAttachment

ContractEvent / AuditLog

Contract

Stores the main business identity of the contract.

id
contract_reference
title
contract_type
description
status
effective_date
expiration_date
created_by
contract_owner
created_at
updated_at


Example:

CTR-2026-0042
Status: ACTIVE

Phase 2 — Contract Versioning ✅ DONE

This is important because negotiation should not overwrite the previous contract.

Contract
   │
   ├── Version 1
   ├── Version 2
   └── Version 3 ← current


Each version stores the actual negotiated terms/document.

ContractVersion
id
contract_id
version_number
content
document_path
created_by
change_summary
created_at


Example:

CTR-2026-0042

v1
Payment: 30 days

v2
Payment: 45 days

v3
Payment: 60 days


Only the current version proceeds to approval/signing.

Phase 3 — Internal Contract Creation ✅ DONE

Build the employee-side contract creation flow.

Employee
   ↓
Create Contract
   ↓
Fill information
   ↓
Save
   ↓
DRAFT


Allow employees to:

create a contract

save incomplete contracts

upload supporting documents

edit draft information

add parties

specify contract dates

specify responsible contract owner

submit for review

Rule

DRAFT can be freely edited.

Once submitted:

DRAFT
  ↓
UNDER_REVIEW


The normal editing controls should become restricted.

Phase 4 — External Party Portal ✅ DONE

This is one of the most important parts of the design.

The outside party does not need access to the internal employee portal.

Instead:

Internal System
      ↓
Secure invitation
      ↓
Email
      ↓
External Contract Portal

External party capabilities

They can:

view the contract version assigned to them

review contract information

comment

propose changes

upload supporting documents

accept/agree to the current version

reject/request changes

eventually sign

External party restrictions

They cannot:

see internal comments

see other contracts

access employee dashboards

access approval information not intended for them

modify internal records directly

Phase 5 — Review Workflow ✅ DONE

Implement:

DRAFT
   ↓
UNDER_REVIEW


Both parties can review the current contract version.

Scenario A — Agreement
UNDER_REVIEW
      ↓
Both parties agree
      ↓
PENDING_APPROVAL

Scenario B — Changes Requested
UNDER_REVIEW
      ↓
NEGOTIATION

Phase 6 — Negotiation Workflow ✅ DONE

Implement negotiation as a loop.

UNDER_REVIEW
      ↓
NEGOTIATION
      ↓
Proposed changes
      ↓
New ContractVersion
      ↓
UNDER_REVIEW

Example
v1
Payment = 30 days
       ↓
External proposes 60 days
       ↓
NEGOTIATION
       ↓
v2
Payment = 60 days
       ↓
UNDER_REVIEW

Important rule

Never silently overwrite v1.

Keep the history:

v1 → v2 → v3 → v4


The system should be able to show:

who proposed what

when it was proposed

why it was proposed

Phase 7 — Internal Approval ✅ DONE

Once both parties agree:

UNDER_REVIEW
      ↓
PENDING_APPROVAL


The authorized internal approver receives a notification.

They can:

APPROVE
   ↓
APPROVED


or:

REJECT
   ↓
Return to review/negotiation

Important

External party agreement ≠ internal approval.

The external party says:

"We agree with the terms."

The authorized internal person says:

"Our organization approves this contract."

Phase 8 — Approval Notification ✅ DONE

After internal approval:

APPROVED
    ↓
Notify external party
    ↓
PENDING_SIGNATURE


The external party receives an email with a secure link.

Example:

Contract approved.

Your organization has been notified that the
contract is ready for signature.

[Review & Sign]

Phase 9 — Signing ✅ DONE

Implement signature tracking independently for each required signer.

Signature
contract_id
party_id
signer_name
signer_email
status
signed_at


Example:

Party A
SIGNED

Party B
PENDING


Contract status:

PENDING_SIGNATURE
       ↓
PARTIALLY_SIGNED
       ↓
FULLY_EXECUTED


When all required signatures are completed:

FULLY_EXECUTED

Phase 10 — Activation ✅ DONE

After execution:

FULLY_EXECUTED
       ↓
ACTIVE


Store:

effective_date
expiration_date


If the contract has a future effective date, you can later introduce a PENDING_EFFECTIVE_DATE state, but don't add it unless the actual requirements need it.

Phase 11 — Contract Monitoring ✅ DONE

Create a monitoring service/job that checks:

expiration_date

Expiration alerts
90 days → LOW
60 days → MEDIUM
30 days → HIGH
7 days  → CRITICAL


Don't change the lifecycle status to EXPIRING.

Instead:

status = ACTIVE
expiration_alert = HIGH
days_remaining = 27

Notifications

Internal:

Contract Owner

Department/Admin

External:

Outside Party

Notifications should be sent when renewal/action is appropriate.

Phase 12 — Renewal ✅ DONE

When a contract approaches expiration:

ACTIVE
   ↓
Renewal notification
   ↓
External party confirms interest
   ↓
Renewal discussion


If they agree to renew:

Original Contract
CTR-2026-0042
        │
        │ renewal_of
        ↓
New Contract
CTR-2027-XXXX
        ↓
DRAFT


The new contract goes through the normal lifecycle again:

DRAFT
 ↓
UNDER_REVIEW
 ↓
NEGOTIATION (if necessary)
 ↓
PENDING_APPROVAL
 ↓
APPROVED
 ↓
PENDING_SIGNATURE
 ↓
FULLY_EXECUTED
 ↓
ACTIVE

Phase 13 — Expiration & Termination ✅ DONE
Natural expiration
ACTIVE
   ↓
expiration date reached
   ↓
EXPIRED

Early termination
ACTIVE
   ↓
TERMINATION
   ↓
TERMINATED


Store termination information:

termination_date
termination_reason
terminated_by

Phase 14 — Cancellation & Superseding ✅ DONE
Cancellation

Use when the workflow stops before execution.

DRAFT
UNDER_REVIEW
NEGOTIATION
PENDING_APPROVAL
PENDING_SIGNATURE
        ↓
CANCELLED

Superseded

Use when a contract/version is replaced by another.

Contract v1
    ↓
Contract v2
    ↓
v1 = SUPERSEDED
v2 = ACTIVE


This is not related to contract violations.

Phase 15 — Archiving ✅ DONE

After expiration or termination:

EXPIRED
   ↓
ARCHIVED


or:

TERMINATED
   ↓
ARCHIVED


Don't delete historical contracts.

The archive should remain searchable for:

audit

reporting

historical reference

compliance

future renewal reference

Phase 16 — Notification System ✅ DONE

Rather than hardcoding emails into every workflow action, create a centralized notification system.

Contract Event
      ↓
Notification Service
      ↓
Email / In-app notification

Notification events

CONTRACT_SUBMITTED

REVIEW_REQUESTED

CHANGE_REQUESTED

NEGOTIATION_STARTED

VERSION_CREATED

PARTIES_AGREED

APPROVAL_REQUESTED

CONTRACT_APPROVED

CONTRACT_REJECTED

SIGNATURE_REQUESTED

PARTIALLY_SIGNED

FULLY_EXECUTED

EXPIRATION_WARNING

RENEWAL_REQUESTED

CONTRACT_EXPIRED

CONTRACT_TERMINATED

This makes the system easier to maintain.

Phase 17 — Audit Trail ✅ DONE

Every important lifecycle action should be recorded.

ContractEvent
id
contract_id
actor_id
actor_type
event_type
description
metadata
created_at

Example audit history
CTR-2026-0042

2026-10-05 09:30
John created contract

2026-10-05 10:15
External party reviewed v1

2026-10-05 11:00
External party requested payment-term change

2026-10-05 11:30
Version 2 created

2026-10-05 14:00
Both parties agreed to v2

2026-10-05 15:00
Approval requested

2026-10-06 09:00
Business Owner approved

2026-10-06 10:00
Signature requested


This is extremely valuable for a contract-management system.

Recommended Implementation Order
Sprint 1 — Foundation ✅ DONE
Scope

Contract

ContractParty

ContractVersion

ContractAttachment

ContractEvent

Built

database schema

contract reference generation

contract CRUD

draft functionality

Sprint 2 — Internal Workflow ✅ DONE
Workflow
DRAFT
 ↓
UNDER_REVIEW
 ↓
PENDING_APPROVAL
 ↓
APPROVED

Built

workflow transitions

approval

internal notifications

audit trail

Sprint 3 — External Portal ✅ DONE
Built

secure invitation

external contract view

external authentication/verification

external comments

external approval/rejection

privacy boundaries

Sprint 4 — Negotiation ✅ DONE
Build

negotiation records

comments

proposed changes

version creation

version comparison

negotiation loop

Sprint 5 — Signing ✅ DONE
Build

signature records

signer management

signing workflow

PARTIALLY_SIGNED

FULLY_EXECUTED

If integrating an e-signature provider, this is where the integration belongs.

Sprint 6 — Monitoring ✅ DONE
Build

expiration calculation

expiration alerts

internal notifications

external notifications

contract dashboard

Sprint 7 — Renewal / Termination / Archive ✅ DONE
Build

renewal workflow

relationship between old/new contracts

termination

cancellation

superseding

archive

Sprint 8 — Audit / Reporting / Hardening
Build

audit history UI

contract history

reports

notification history

security testing

permission testing

external portal security testing

Final Architecture

At a high level, you're building three related systems, not just one contract form:

                    CONTRACT MANAGEMENT
                           │
          ┌────────────────┼────────────────┐
          │                │                │
          ▼                ▼                ▼
   INTERNAL PORTAL   EXTERNAL PORTAL   BACKGROUND SERVICES
          │                │                │
          │                │                ├─ Expiration monitoring
          │                │                ├─ Notifications
          │                │                └─ Scheduled tasks
          │                │
          ├─ Create       ├─ Review
          ├─ Review       ├─ Negotiate
          ├─ Approve      ├─ Agree
          ├─ Monitor      └─ Sign
          └─ Manage
                  │
                  ▼
              DATABASE
                  │
       ┌──────────┼───────────┐
       │          │           │
    Contract   Versions    Audit Trail
       │
       ├── Parties
       ├── Reviews
       ├── Negotiations
       ├── Approvals
       ├── Signatures
       ├── Attachments
       └── Notifications
