# IPDAMS — Installed and Staged Release Checkpoint (2026-11-04)

## Most recent packaged update: v0.2.286 (2026-11-06) — not yet installed

**Owner's last confirmed installed version remains v0.2.284.** The v0.2.285 installation has not been confirmed. Two mutually exclusive strict-gated v0.2.286 installers are available:

- v0.2.284 → v0.2.286 **roll-up**, including the v0.2.285 Book Directory Details ledger: [Drive](https://drive.google.com/file/d/1Lv6ph_WbzrxUw1APr3hbC-iWApL98QYs/view), 7 files / no migrations
- v0.2.285 → v0.2.286 **direct**: [Drive](https://drive.google.com/file/d/19KrXGdrkGvP9QuSirFuL1EIhK935Hc4n/view), 4 files / no migrations
- [Design, package checks and owner UAT](V0.2.286_SUPERADMIN_OFFICE_VIEW_PROPERTY_INVENTORY_HISTORICAL_FUND_UAT_2026-11-06.md)

Scope: Super Admin convenience read-only Office Viewer over existing all-office selector; Textbooks-like compact Property/Inventory navigation, high-contrast tables and floating historical/RIS/issue/opening forms; optional historical original_fund_source as JSON evidence displayed/audited. The viewer is a **UI convenience and frontend write guard**, not a newly enforced server role or cross-school grant. School user scopes unchanged. No live website or stock changes performed.

Isolated validation: **12 PHP + 28 Node component/API assertions passed**, PHP/JS syntax and ZIP hash/CRC verified. Chromium graphical test blocked by administrator; **authenticated production acceptance pending**. Do not assume new update installed.

## Owner-confirmed installed baseline: v0.2.284

The owner confirmed **v0.2.284 installed and working**, including the newly added Textbooks Teacher Issuance, Disposal and Reports tabs. Earlier owner-confirmed working: v0.2.280 paper-only CO receiving, v0.2.281 navigation persistence/logo home, v0.2.282 two textbook transfer options with RIS/ITR, and v0.2.283 appointed Property Custodian auto-signatory fields.

## Previous separately staged release: v0.2.285 — installation not confirmed

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
