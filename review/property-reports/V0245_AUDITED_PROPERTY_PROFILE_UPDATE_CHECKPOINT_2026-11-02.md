# IPDAMS v0.2.245 — Audited Property Profile Editing (2026-11-02)

User request: implement one **View / Edit Property** action from the Asset Register that opens inside the existing Property Details floating window. Maintain all prior v0.2.244 low/high-value property approval rules, original ICS/PAR, and historical audits.

## Packaged updater variants — choose ONE according to actually installed version

- Installed **0.2.242** → `IPDAMS_v0.2.245_AUDITED_PROPERTY_PROFILE_FROM_v0.2.242.zip`, SHA256 `9a7afcb1e0850e60f1250cec375c954fbabd6e5d73ad2da06afbeb2b545dc184` (8 app files; bridges v243 + v244 workflow features)
- Installed **0.2.243** → `IPDAMS_v0.2.245_AUDITED_PROPERTY_PROFILE_FROM_v0.2.243.zip`, SHA256 `2c0ccb8352b48c84124ac44f27718b17bfa16c4ac96bac5985e042ab75b63bb5` (7 app files; bridges v244 features)
- Installed **0.2.244** → `IPDAMS_v0.2.245_AUDITED_PROPERTY_PROFILE_FROM_v0.2.244.zip`, SHA256 `e39208d9ec31933c3b322d1cbbb1602ec797e1643a74f7ba8f10b979a118f024` (4 app files, only edit-profile code)
- `IPDAMS_v0.2.245_PROPERTY_PROFILE_RELEASE_NOTES.md` and `IPDAMS_v0.2.245_REVIEW_SOURCE_CHANGES.diff` produced in owner conversation.

The owner last **confirmed installed 0.2.242**. v0.2.243 and v0.2.244 had been **prepared**, but no later explicit installation confirmation exists. Only upload the updater whose from_version exactly matches Settings. Preflight any source drift. All ZIPs include an explicit manifest `version=0.2.245`, zero migrations, per-file SHA256 and successful ZIP integrity check.

## Changes in the code

1. `public/assets/app.js`: Existing Asset Register button renamed **View / Edit**; the existing Property Details modal has a fifth **Edit property** tab. A single screen presents registered identifying, acquisition, source, financial, custody, and condition details, with locked values clearly distinguished from editable descriptive fields. Unit and shared batch edit scopes are separate; batch editing only changes shared metadata. Requires correction reason and confirmation. Modal maintains org scope, error handling and audit history.
2. `app/Domain.php`: Existing `property.registration.correct` route extended with explicit `profile_edit=true`, while preserving existing pre-ICS physical review behavior. Descriptive edits after ICS/PAR allowed **only** for serial, location, condition observation (not formal unserviceable/disposal), legacy property number, brand, model and evidence note. Issued-property source/acquisition origin, acquisition type, useful life, textual historical procurement references and historical custodian reference locked server-side. Never edits source-linked IAR, asset number, cost, acquisition date, catalog item, real custodian, lifecycle status, financial postings or finalized ICS/PAR. Requires owned active school asset, no pending physical review or unserviceable/disposal lifecycle, exact group membership and optimistic version hashes. All writes are atomic with before/after actor reason audit. Existing legacy editing still blocks issued assets.
3. `public/app.html`: refreshes app.js and property edit CSS cache keys.
4. `public/assets/property-register-details.css`: compact responsive in-modal property edit layouts.

Existing combined ICS print uses official item, acquisition cost and useful-life, none of which can be changed by the edit path after issued ICS/PAR. This does not establish universal archival correctness for all external form renderers; require real-site reprint verification.

## Validation

- PHP syntax PASS; JavaScript Node syntax PASS.
- **30 behavior assertions PASS**, exercised against patched `Domain::correctPropertyRegistration()` with a transactional mock PDO. Includes post-issue corrections, issued source locks, user-entered audit reason and before/after state, prohibited status words, stale token, duplicate serial, cross-school asset denial, viewer/Division roles, pending/disposed lifecycle rejection, batch metadata-only updates, unissued corrections, audit failure with transaction rollback, and preservation of old pre-issuance route's restrictions.
- All 3 ZIP integrity, SHA256, exact predecessor/target versions and no-migrations checks PASS.
- **Not yet verified:** real authenticated MariaDB and browser UAT; pixel layout, cross-workspace behavior and finalized ICS reprint comparison. Do NOT mark this as production certified or fully merged. No GitHub Actions run.

## Next owner test

Choose correct version-gated ZIP, stage and install after website/database backups. Visit Property Accountability → Asset Register → View / Edit → Edit property. Edit a reviewed test unit's brand/serial/room with reason and confirm updates in read view + audit; for a record with issued ICS/PAR, verify source/monetary fields and useful life stay read-only. Print previously issued ICS again to verify item, cost and useful life remain unchanged. Test batch scope with two units and make sure unserviceable/disposal state cannot be overwritten through generic editor.

This checkpoint is not proof that the whole application source was pushed to GitHub: the full application source was reconstructed locally from an owner-uploaded private site archive and v0.2.244 prepared patch, never the large private archive, backups, credentials or database dumps. PR #16 stays draft pending UAT.
