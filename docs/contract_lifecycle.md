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
  └─ propose changes / accept negotiation ─────────────────► under_review

pending_approval
  └─ sign (admin; freeze latest history version) ──────────► pending_signature

pending_signature
  ├─ party signs frozen version ───────────────────────────► pending_signature
  ├─ fully_execute (admin; every party signature valid) ───► fully_executed
  ├─ activate (admin; reason required) ────────────────────► active
  └─ reopen_for_revision (admin; void prior signatures) ──► negotiation

partially_signed (legacy status)
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

- Involved parties are every row in `team8_contract_parties` for the contract. Execution requires at least one such party and one valid, non-voided signature per party.
- When an administrator sends a contract for signature, the system freezes the highest `team8_contract_history.version_no`. Its exact `data_json` bytes are hashed with SHA-256; both the version and document hash are stored on `team8_contracts`. Signatures carry the same version and hash, and verification fails if the frozen history data changes.
- A signature record stores its frozen version, document hash, signer-bound SHA-256 digest, signer name/email, signing timestamp, IP address, and user agent. The digest is SHA-256 over JSON with the ordered keys `contract_id`, `party_id`, `document_hash`, `signed_at`, `signer_name`, and lowercased `signer_email`. Existing rows without a version and valid hashes do not satisfy execution.
- `partially_signed` is retained for compatibility with older records. New signature writes do not automatically move a contract out of `pending_signature`.
- Freezing the signature version records `CONTRACT_VERSION_FROZEN_FOR_SIGNATURE`. `fully_executed` is reached only through the explicit administrator action after all signature records verify; the status change and `FULLY_EXECUTED` event are committed together. Updating signature rows alone never changes the contract status.
- The normal `fully_executed` → `active` transition records `ACTIVATED`. The `pending_signature` escape hatch (also accepted from legacy `partially_signed`) requires a non-empty administrator reason and records `ACTIVATED_WITHOUT_EXECUTION`, including the actual source status and reason in event metadata. Activation is blocked if the frozen version is not the latest history version or a negotiation remains pending.
- Reopening a pending-signature, partially signed, or fully executed contract is an administrator action. It voids every live signature with reason `superseded by new version`, clears the frozen version and hash, and enters `negotiation`; the revised version must be frozen and signed again.
- Missing signature schema, history, or verification data fails closed; execution and activation do not proceed.
- Stored hashes establish snapshot integrity and audit correlation; they are not a cryptographic e-signature or proof of signer identity.

## Other lifecycle constraints

- Review approval remains gated on acceptance by every involved party for the latest circulated review.
- A fully executed contract may be cancelled before activation. Renewal, supersede, and termination actions remain limited to active contracts.
