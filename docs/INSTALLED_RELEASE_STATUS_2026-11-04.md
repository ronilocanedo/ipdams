# IPDAMS — Installed and Staged Release Checkpoint (2026-11-04)

## Owner-confirmed installed baseline: v0.2.284

The owner confirmed **v0.2.284 installed and working**, including the newly added Textbooks Teacher Issuance, Disposal and Reports tabs. Earlier owner-confirmed working: v0.2.280 paper-only CO receiving, v0.2.281 navigation persistence/logo home, v0.2.282 two textbook transfer options with RIS/ITR, and v0.2.283 appointed Property Custodian auto-signatory fields.

## Next staged release: v0.2.285 — NOT YET INSTALLED

**Book Directory → Details** opens an authenticated read-only ledger showing quantities received, transferred in/out, teacher issues and returns, stock by batch/condition, pending loss/damage, disposal holds and actual disposal with dated source references.

- [Release design, permission rules and local UAT for v0.2.285](V0.2.285_BOOK_DIRECTORY_DETAILS_LEDGER_UAT_2026-11-04.md).
- Installer: `IPDAMS_v0.2.285_BOOK_DIRECTORY_DETAILS_LEDGER_FROM_v0.2.284.zip`, SHA-256 `3f22b7a18f798c56a86e6d4edea99f85065cc9fb31e8d7098c3e5af23e92a783`.
- Strict predecessor **v0.2.284 → v0.2.285**, five frontend/API/read-only service files, **zero migrations**.
- Local tests passed (PHP mocked query/permission cases, browser with original React library, existing teacher-issuance/Division disposal regression checks). **Live authenticated stock-ledger reconciliation not done.**
- School sees its own office, Division can opt into active supervised-school consolidation, draft book identities remain protected.

## Policy and safety

IPDAMS records real physical transactions and actual paper signatories, not digital approvals. Books identified for disposal cannot be physically disposed in IPDAMS before genuine written Division authorization is recorded. The read-only Details ledger never posts stock, infers approval, or changes paper records. Only authorized scoped encoders operate relevant transactions; each write is auditable.

Retain separate website+SQL backups before installing; use Settings → System Updates and verify strict predecessor. Do not fabricate production transactions for tests.

After v0.2.285 UAT, finish textbook exceptions and printable documents, then roll the successful compact tabs/dialogs/contrasting tables into **Property Accountability** and **Inventory Management** in separate controlled updates.

The private GitHub `main` branch is a release-documentation index, not a verified Hostinger production source mirror. Do not deploy it wholesale. This checkpoint edit is documentation only.
