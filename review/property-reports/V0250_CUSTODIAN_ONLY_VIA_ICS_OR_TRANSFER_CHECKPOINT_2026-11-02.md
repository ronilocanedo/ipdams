# IPDAMS v0.2.250 — Remove custodian from property physical-review forms
Date: 2026-11-02. Owner confirms the prior v0.2.249 update is installed and working, but Review Newly Received → Edit Details exposes a misleading `reported custodian` text field.

## Exact release
- **Requires:** installed v0.2.249. **Installs:** v0.2.250.
- ZIP: `IPDAMS_v0.2.250_CUSTODIAN_ONLY_BY_ICS_TRANSFER_FROM_v0.2.249.zip`.
- ZIP SHA256: `f345436b87da4aca668a9ac1f7e0f1b8b641a53216fd08728a6f8cc9f4d0844b`.
- Three source files `app/Domain.php`, `public/assets/app.js`, `public/app.html`; zero migrations.
- Source and test code was prepared from owner-updated source ZIPs; full current app files are **not in GitHub main**. No GitHub Actions or hosted website writes were performed during this build.

## UX fix
- Remove editable `reported_custodian` from **Review Newly Received / Edit Details**, historical opening unit grid, and Asset Register **View/Edit** profile modal. Keep *actual current custodian* visible **read-only** from authoritative `assets.custodian`, and keep historic reported-custodian differences in audit view.
- Add guidance: property review confirms serial, location, and condition; custody is created only through `Issue ICS/PAR` or a documented `Property Transfer`. No extra appointment/approval flow added.
- The condition field stays a controlled dropdown; no change to unserviceable/disposal approvals.

## Backend hardening
- `Domain::recordAsset`: reject nonblank direct legacy `custodian` and nonblank serialized `reported_custodian` on historical opening; no employee can be silently registered as custodian.
- `Domain::correctPropertyRegistration`: reject *all* changes to saved `property_profile.unit.reported_custodian` in both old pre-ICS review and newer profile-edit routes. Preserve older historical metadata exactly for traceability; don't delete, backfill, or promote it to custody.
- Actual `assets.custodian` remains unchanged; only ICS/PAR and Property Transfer workflows assign/transfer real custody.
- No migration or overwrite of finalized property/accountability records.

## Validation
- PHP lint and JS `node --check`: PASS.
- **38** simulated backend behavior assertions PASS, including 30 existing property-profile checks + 8 custody separation tests.
- **10** static UI/back-end assertions PASS: no exposed fields, actual custodian shown read-only, saved historical audit unchanged, server guards present, frontend cache updated, no migrations.
- ZIP CRC and individual manifest SHA256 PASS.
- Real authenticated browser / actual MariaDB testing **PENDING**.

## Owner acceptance
Use normal version-gated installer preflight on v0.2.249 with independent backups. Open Property Accountability → Review Newly Received → Edit Details and confirm no reported-custodian field. Verify serial, location, condition and legacy property number still edit/audit. In Asset Register, actual custodian should be shown read-only. Issue a test ICS/PAR or record an internal transfer to confirm custody changes only through approved workflows.

Release notes are downloadable in the conversation as `IPDAMS_v0.2.250_PROPERTY_REVIEW_NO_CUSTODIAN_RELEASE_NOTES.md`. PR #16 remains a draft.
