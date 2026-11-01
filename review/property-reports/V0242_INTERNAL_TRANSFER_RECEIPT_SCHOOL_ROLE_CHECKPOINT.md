# IPDAMS v0.2.242 — internal transfer receipt button role mismatch (2026-11-01)

User confirms v0.2.242 installed. All current records are test records, not yet production. Screenshot of Transfer Register shows ITR 2026-11-0001 pending receipt, internal same-school transfer, Print ITR / Companion RIS, Awaiting signed paper handover and Cancel pending, but no Record Signed Handover action while prior screenshots show a SUPER_ADMIN session.

Verified in owner-uploaded source:
- `api/property-transfers.php`: receipt form is rendered only for `PENDING_RECEIPT`, `INTERNAL`, source school = active organization, **and** `$user['role']==='SCHOOL'` (line ~109).
- `private/app/PropertyTransferWorkflow.php`: `receive()` rejects users whose role is not `SCHOOL` before validating reference, signed paper, date and school scope (line ~137).
- `mayWrite()` does admit `SCHOOL` and `SUPER_ADMIN` for preparation/cancel, so the missing receipt button for SUPER_ADMIN is an intentional or accidental authorization difference; not a missing transfer event.
- Internal receipt flow records signed paper reference 8–160 chars, valid handover date not before preparation/not in future, explicit paper-signature verification, changes asset custodian and logs TRANSFER movement/audit within one transaction, status RECEIVED. Cross-school/external receipt is intentionally blocked pending catalog/IAR lineage safeguards.

**Safest immediate UAT:** sign in with an authorized School Administrator test account assigned to the originating school, open the same transfer, inspect sample signed paper ITR, and use the Record Signed Handover form; confirm status RECEIVED and updated custodian. Do not click Cancel pending. Test data should remain clearly labelled as simulated, but not change server-side signature attestation to a bypass.

**Open design decision:** whether SUPER_ADMIN should receive an *audited delegated school-staff handover recording* privilege in future or maintain school-only separation. Do not silently broaden authorization just to make a button appear. User is testing with synthetic data, so no historical production migration is warranted. v0.2.242 report floating preview change unrelated. No code deployment in this checkpoint.
