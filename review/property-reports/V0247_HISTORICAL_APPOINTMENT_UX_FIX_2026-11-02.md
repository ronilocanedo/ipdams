# IPDAMS v0.2.247 — Historical AO/Property Custodian appointment clarification (2026-11-02)

## Owner UAT finding
Owner-installed v0.2.246 screenshot: Division of City Schools - Tagbilaran City → Reference Data → People & Documents → BAC & Heads → Administrative Officer / Supply & Property Custodian. Newly entered Division employee currently shows `employee_assignments.effective_from = 2026-11-02`, but genuine appointment effectivity requested is `2025-11-11`. Red banner: supporting reference must be at least 20 characters. Owner entered `Appointment AO IV`, **17 characters**, and ticked historical-record confirmation.

## Root cause and policy
The server **already explicitly supports** a historically earlier custodian appointment via `Domain::saveSupplyCustodian` (existing code at approx. lines 2298-2345): when the appointment date predates the employee directory assignment, demand `historical_appointment_attested===true`, require `mbSafeLen(reason)>=20`, append a `configuration_versions` appointment and audit event. It does **not** rewrite employee assignment dates or prior IAR signatory history. The user-facing form did not show the minimum until a failed save, making the existing allowed operation look blocked.

**Distinct office roles:** The AO/Property Custodian in Reference Data supplies the **IAR acceptance signatory** only. The Division Supply Officer with approval authority for high-value property unserviceable/disposal must be **independently appointed** using Property Accountability → Condition & Disposal → Technical administrator → Appoint Division Supply Officer, selecting an authorized Division user account. Employee directory addition or AO appointment does NOT grant this role.

## Patch
`IPDAMS_v0.2.247_HISTORICAL_CUSTODIAN_APPOINTMENT_FROM_v0.2.246.zip`
SHA256: `d9613e7cf8dc3e4ba71d00fb551978ec96941c6c08badccda25269e93badbfe3`.
Requires v0.2.246 only; installs v0.2.247; two frontend files only: `public/assets/app.js` + `public/app.html`; browser app.js cache key bumped. No SQL migrations, backend/policy relaxation, privilege granting, financial transactions, current employee assignment rewrites, or historical document edits.

- Show a field-level genuine appointment reference requirement and **live characters entered / required** (20 for appointment earlier than employee assignment, 10 for other past appointments; 0 for current).
- Preserve checkbox confirmation only for older-than-assignment dates; clear prior error messages upon correcting the reason.
- Client-side pre-submit guard reinforces existing server requirement and yields an actionable message with characters missing.
- Explain date discrepancy in everyday language; remind user that no official past record can be invented.
- Clarify on the Division workspace that IAR AO/Property Custodian appointment is distinct from the **Division Supply Officer approval authority**.

## Validation and limitations
- Node `--check` full 892kB app.js PASS.
- **10 direct helper/client-validation boundary assertions PASS** (17-character short reference, >=20 accepted, attestation, other past-date 10 chars, current date 0 chars, missing employee, link/label/cache key).
- ZIP CRC, per-file SHA256, release manifest version gate, zero migrations PASS.
- Actual hosted authenticated browser save test remains pending. No GitHub Actions were run, no site/database writes from patch preparation.
- In this case user can likely save already on v0.2.246 by entering the full **authentic** official order/reference (at least 20 characters) and using the existing checkbox; patch fixes UX, **not** an actual hard backend ban on historic appointments.

Verify installed version exactly 0.2.246, independent backup, successful updater preflight; install v0.2.247 only if desired. Reopen Reference Data → BAC & Heads, choose genuine appointment 2025-11-11 and full supporting order reference, check box, save, verify audit. For high-value approval role use Property Accountability separately. PR #16 remains draft.
