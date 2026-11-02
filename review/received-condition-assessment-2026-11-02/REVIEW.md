# IPDAMS — Post-receipt property condition assessment (review)
Date: 2026-11-02 · State: NOT INSTALLABLE, NOT MERGED

## Confirmed root cause
The installed receiving-office interface `api/property-transfer-intake.php` only offers Property History in the Received Asset Register. The installed `app/Domain.php` ICS/PAR issuance rule correctly blocks a transferred-in asset unless `assets.condition_note` is `Serviceable`. Receiving transfer acceptance in `app/PropertyTransferAcceptance.php` correctly records the receiving inspector's observed condition and creates an independent receiving-office asset with linked provenance. Neither ICS/PAR safety nor the original transfer should be altered.

## Smallest change
Add a post-receipt **Assess condition** action in the Received Asset Register for the receiving organization's formally appointed and currently bound Property Custodian login; record actual inspection date, new observed condition (Serviceable / Needs Maintenance / For Inspection / For Repair / Under Repair), inspection or repair evidence reference, meaningful remarks, and an affirmative physical-inspection attestation. An ordinary assessment may not declare Unserviceable or For Disposal, which remain formal lifecycle actions.

Backend must check authorization even if the UI hides the action: the office must be active and tenant-scoped, the acting login must match the current custodian appointment and binding, the asset must belong to this office, be ACTIVE and unassigned, and prove linked RECEIVED ITR/PTR provenance in saved transfer documentation. Deny if an open ICS/PAR, active formal lifecycle decision, or stale assessment exists.

While locking the organization and asset in a transaction, recheck appointment/binding, old condition, inspection date (on/after receipt; not in future), and provenance. Change only `assets.condition_note`, insert `audit_events` with `entity_type='ASSET'`, before/after condition, actor, inspection date/reference and reason. The existing Property History viewer already lists audit events and before/after fields across the ownership chain. Do not update or forge official document snapshots, original receiving condition, custodian, amount, school, tag, stock, funds, journal or check records. ICS/PAR issuance remains a separate gated action.

## Verification gates
- Correct school/Division custodian only; SUPER_ADMIN, unbound, revoked and other-school login denied.
- Valid transfer provenance required; historical ordinary asset cannot use received-only endpoint.
- ACTIVE, unassigned, no open ICS/PAR, no formal unserviceable/disposal lock.
- Inspection date and reference recorded; stale concurrent edits rejected.
- 2nd inspection changes condition with append-only before/after audit; original transfer receipt condition immutable.
- After actual inspected serviceable correction, new ICS/PAR may be attempted separately and all existing employee/role/numbering safeguards still apply.
- No automatic financial, stock, transfer or lifecycle side effects.
- PHP syntax, authenticated API/browser and MariaDB tests, version-gated updater manifest and exact installed predecessor verification all required before any ZIP release.

## Source and security
Read-only inspection from the connected user's Hostinger IPDAMS installation. No deployment or database mutation. GitHub main does not contain the verified installed complete application source, so this branch is a review note, not an independently deployable release. User already holds independent website/database backups; no new site or GitHub Actions run requested.