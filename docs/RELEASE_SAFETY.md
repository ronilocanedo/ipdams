> **Version status update (2026-11-11):** Owner-reported installed IPDAMS is **v0.2.351**; see [current checkpoint](IPDAMS_V0.2.351_RELEASE_CHECKPOINT_2026-11-11.md). The v0.2.215 / v0.2.216 references in the historical controls below describe 26 October 2026 only. A future installer must require **exact v0.2.351** until owner confirms a newer installed version; no code sync or release is authorized merely by this documentation update. The existing migration, bank, accountant, tenant-security and manual-deployment safety gates remain in force.

# IPDAMS release safety

1. Release version must advance exactly from the **user-confirmed installed predecessor**. Current owner-confirmed installed version at this checkpoint: `v0.2.215` (successful selected Custom Reset reported; read-only reconciliation pending).
2. Installer package must include expected release manifest, predecessor-version checks, SHA256 per-file hashes, and independent backup/restore instructions.
3. No implicit migration, disbursement voucher posting, bank/check assignment, accounting, tax remittance, stock movement, asset issuance, document numbering or historical data rewrite on installation.
4. Changes to property data schema require an isolated MariaDB migration/rollback and interrupted-updater recovery rehearsal, **before** preparing a production installer.
5. Respect PHP 8.2+, MariaDB and legacy React **16** browser constraints.
6. Scope API reads/writes, prints, exports and attachments by current role, active school assignment and authorized Special Functions on the server, including after revocation.
7. Use disposable synthetic school databases for CI; never point test code to the hosted IPDAMS database.
8. Never deploy automatically from GitHub. Real release acceptance requires owner-installed staging verification and explicit production authorization.

## Delivery principle — fastest verifiable path (owner directive 2026-10-26)

For every IPDAMS change, choose the shortest implementation-and-test path that preserves security, tenant isolation, audit history, financial integrity, release reversibility and quality. Reuse validated source and passed tests; avoid redundant packages, repeated paid CI, outdated source reconstructions and documentation-only cycles. Do not bypass staging, backups, authentication or actual database acceptance to increase speed. Record proof and blockers once in the GitHub–Drive sync registers.

## Active v0.2.216 release gate (26 October 2026)

- Existing historical property batch registration ZIP from Drive is review-only, strictly `v0.2.215` → `v0.2.216`; SHA256 `aecb5727cbc271e6c18861ca475756f15f2bfe0e85bce3f78f70d491e979b265`.
- Two additive `assets` migrations are not authorized on the live single-site database until exact deployed `app/SettingsService.php` parser/allowlist/partial DDL and verified backup/restore are reviewed.
- Earlier synthetic MariaDB manual Action run #2 passed; do not rerun without a material source/test change. No paid CI or automated deployment.
- The owner's hosted system and read-only post-reset check are authoritative for retained records; GitHub currently lacks a complete verified v0.2.215 source tree.
