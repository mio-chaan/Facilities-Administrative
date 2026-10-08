# Contract lifecycle

## State transitions

```text
draft
  └─ submit ───────────────────────────────────────────────► under_review
                                                               ├─ approve (all party reviews accepted) ─► pending_approval
                                                               ├─ request_changes ──────────────────────► negotiation
                                                               ├─ revert_to_draft ──────────────────────► draft
                                                               └─ reject ───────────────────────────────► archived

negotiation
  └─ save revised terms (publish + invite parties) ────────► under_review

pending_approval
  └─ sign (admin; freeze latest history version) ──────────► pending_signature

pending_signature
  ├─ first party signs frozen version ─────────────────────► partially_signed
  ├─ activate (admin; reason required) ────────────────────► active
  └─ reopen_for_revision (admin; void prior signatures) ──► negotiation

partially_signed
  ├─ other parties sign frozen version ────────────────────► partially_signed
  ├─ fully_execute (admin; every party signature valid) ───► fully_executed
  ├─ activate (admin; reason required) ────────────────────► active
  ├─ cancel (admin; before activation) ────────────────────► cancelled
  └─ reopen_for_revision (admin; void prior signatures) ──► negotiation

fully_executed
  ├─ activate (admin) ─────────────────────────────────────► active
  ├─ cancel (admin; before activation) ────────────────────► cancelled
  └─ reopen_for_revision (admin; void prior signatures) ──► negotiation

active
  ├─ renewal ──────────────────────────────────────────────► renewal
  ├─ supersede ────────────────────────────────────────────► superseded
  ├─ terminate ────────────────────────────────────────────► termination
  └─ end date passed ──────────────────────────────────────► expiration
```

## Signature and execution rules

- Involved parties are the distinct parties in `team8_contract_parties` for the contract. Execution requires at least one such party and one valid, non-voided signature per party.
- When an administrator sends a contract for signature, the system freezes the highest `team8_contract_history.version_no`. Its exact `data_json` bytes are hashed with SHA-256; both the version and document hash are stored on `team8_contracts`. Signatures carry the same version and hash, and verification fails if the frozen history data changes.
- Renewal drafts save an initial contract-history snapshot when created. Legacy renewal drafts without history receive a snapshot from their current saved fields before their first signature version is frozen.
- Approval-to-signature sends each unsigned involved party a unique, single-use signing link. Only the token hash is persisted; the link expires after 14 days. Link access is valid only while the contract remains in `pending_signature` or `partially_signed` and its frozen document hash verifies.
- The signing page displays terms from the frozen history snapshot and its SHA-256 hash. Parties choose typed full legal name (default) or a drawn signature, affirm the consent text, and verify a 6-digit OTP sent to the invitation email. OTPs expire after 10 minutes, allow 5 attempts, and have a 60-second resend cooldown. OTP hashes use the configured application pepper; OTPs and raw invitation tokens are never written to logs.
- A signature row stores its frozen version, document hash, signer-bound SHA-256 digest, signer name/email, signing timestamp, IP address, user agent, input method (`typed`, `drawn_touch`, `drawn_pen`, or `drawn_mouse`), recorded consent text/time, and optional private drawn-signature file reference plus file hash. The digest includes the method, consent time/text, and drawn-file hash for new signatures. Existing rows without a version and valid hashes do not satisfy execution.
- Zero valid signatures leaves a contract in `pending_signature`; any signatures move it to `partially_signed`. Even when every party has signed, the status stays `partially_signed` until an administrator explicitly completes execution.
- Freezing the signature version records `CONTRACT_VERSION_FROZEN_FOR_SIGNATURE`. `fully_executed` is reached only through the explicit administrator action after all signature records verify; the status change and `FULLY_EXECUTED` event are committed together. Updating signature rows alone never changes the contract status.
- The normal `fully_executed` → `active` transition records `ACTIVATED`. The `pending_signature` escape hatch (also accepted from legacy `partially_signed`) requires a non-empty administrator reason and records `ACTIVATED_WITHOUT_EXECUTION`, including the actual source status and reason in event metadata. Activation is blocked if the frozen version is not the latest history version or a negotiation remains pending.
- Reopening a pending-signature, partially signed, or fully executed contract is an administrator action. It voids every live signature with reason `superseded by new version`, revokes outstanding signing links, consumes active OTP challenges, clears the frozen version and hash, and enters `negotiation`; the revised version must be frozen and signed again.
- Missing signature schema, history, or verification data fails closed; execution and activation do not proceed.
- Stored hashes establish snapshot integrity and audit correlation; they are not a cryptographic e-signature or proof of signer identity by themselves. OTP plus an invitation email is an identity-verification control, not an absolute guarantee of sole control or legal non-repudiation. Legal counsel should approve compliance claims.

## Deployment requirements

- Apply `20261008_digital_contract_signing.sql` after the existing frozen-signature schema migration, or import the complete `database/schema.sql` on a new database.
- Configure public HTTPS `APP_URL` and working Gmail SMTP credentials outside local development.
- Set `T8_SIGNING_OTP_PEPPER` to a random secret of at least 32 characters. Set `T8_SIGNATURE_STORAGE_DIR` to a writable private path outside the web root for drawn signature captures. Typed signing does not need a drawing-storage directory.

## Other lifecycle constraints

- The revision form shows each linked party's latest review status, feedback, and meeting details. Saving revised terms publishes one current history version, resolves the internal revision record without a separate internal acceptance step, and automatically sends fresh review invitations to all linked parties. The contract remains `under_review` while parties review the published version; invitation delivery failures must be surfaced so staff can resend the links.
- Review approval remains gated on acceptance by every involved party for the latest circulated review.
- A fully executed contract may be cancelled before activation. Renewal, supersede, and termination actions remain limited to active contracts.
- “Open Contract” and “Expiring Soon” are derived indicators, not lifecycle states. A contract without an end date is shown as “Open Contract” (taking precedence over expiry monitoring), while its lifecycle status remains unchanged.
