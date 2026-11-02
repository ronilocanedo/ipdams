# IPDAMS v0.2.246 — Condition dropdown correction (2026-11-02)

## Owner feedback
Owner confirms **v0.2.245 installed successfully**, and the new in-modal View / Edit Property feature works. Screenshot shows `Condition observation / remarks` is a free-text field. Owner requests **dropdown**.

## Version-gated test-site updater
Artifact: `IPDAMS_v0.2.246_PROPERTY_CONDITION_DROPDOWN_FROM_v0.2.245.zip`
SHA256: `ea3b8ff630c77b200e935c4c0f8ae69fb15d6c9ebb256f453ac4ffe55b2a4fa5`

Requires installed **0.2.245** and clean updater preflight; installs **0.2.246**. Code-only, no migrations, three application files:
- `app/Domain.php` — whitelist permitted ordinary condition values in `correctPropertyRegistration`, while preserving unchanged prior historical condition text. Explicitly reject `Unserviceable` / `For Disposal` through the generic editor; formal lifecycle via Condition & Disposal only. Standardized dropdown is not statutory declaration.
- `public/assets/app.js` — in-Asset-Register View / Edit property modal, use accessible select instead of free-text condition input; align older Review Newly Received dropdown to the same choices; display unrecognized existing observations without silently overwriting them.
- `public/app.html` — `assets/app.js?v=0246-condition-selector` cache refresh.

Options: **Serviceable**, **Needs Maintenance**, **For Inspection**, **For Repair**, **Under Repair**; empty/not recorded allowed; legacy observation retained as an unchanged option when relevant. Formal low-value unserviceable and disposal follow school workflow; high-value needs the two independent Division Supply Officer approvals. No changes to asset cost, permanent number, custody, ICS/PAR, movements, or historical documents.

## Validations
- PHP lint PASS.
- Node JS syntax PASS.
- **44** simulated Domain/property-profile assertions PASS (30 v245 +14 new focused checks, including standard values, rejection of free-form API injection, unserviceable/disposal bypass denial, legacy observation unchanged while editing room, and transactional audit failure rollback).
- ZIP CRC and checksums, exact predecessor/version, zero migrations PASS.
- Actual authenticated browser and real MariaDB queries still NOT tested for v0.2.246.
- No GitHub Actions triggered. No production or test-site website writes from this patch preparation.

Owner should install only on exactly 0.2.245 after backup/preflight, confirm the Current condition dropdown in Asset Register → View / Edit → Edit property, choose For Inspection and save audited details, inspect history, and confirm formal Unserviceable remains in Condition & Disposal.

The review source diff is also downloadable from the conversation as `IPDAMS_v0.2.246_PROPERTY_CONDITION_DROPDOWN_SOURCE.diff`; GitHub branch tracks the release checkpoint rather than asserting the entire current private website source has been synchronized. PR #16 remains draft.
