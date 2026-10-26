# IPDAMS release safety

1. Release version must advance exactly from the **user-confirmed installed predecessor**. Current installed version at this checkpoint: `v0.2.213`.
2. Installer package must include expected release manifest, predecessor-version checks, SHA256 per-file hashes, and independent backup/restore instructions.
3. No implicit migration, disbursement voucher posting, bank/check assignment, accounting, tax remittance, stock movement, asset issuance, document numbering or historical data rewrite on installation.
4. Changes to property data schema require an isolated MariaDB migration/rollback and interrupted-updater recovery rehearsal, **before** preparing a production installer.
5. Respect PHP 8.2+, MariaDB and legacy React **16** browser constraints.
6. Scope API reads/writes, prints, exports and attachments by current role, active school assignment and authorized Special Functions on the server, including after revocation.
7. Use disposable synthetic school databases for CI; never point test code to the hosted IPDAMS database.
8. Never deploy automatically from GitHub. Real release acceptance requires owner-installed staging verification and explicit production authorization.

## Delivery principle — fastest verifiable path (owner directive 2026-10-26)

For every IPDAMS change, choose the shortest implementation-and-test path that preserves security, tenant isolation, audit history, financial integrity, release reversibility and quality. Reuse validated source and passed tests; avoid redundant packages, repeated paid CI, outdated source reconstructions and documentation-only cycles. Do not bypass staging, backups, authentication or actual database acceptance to increase speed. Record proof and blockers once in the GitHub–Drive sync registers.
