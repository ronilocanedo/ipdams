# IPDAMS — Integrated Procurement, Disbursement and Asset Management System

Private coordination and review repository for DepEd school and Division procurement, disbursement, inventory, property accountability, reporting, and audit workflows.

## Current checkpoint — 11 November 2026

- **Owner-reported installed application: `v0.2.351`.** The installed version is not independently authenticated against Hostinger/MariaDB in this GitHub documentation update.
- Release lineage from verified Google Drive guidance: `v0.2.348 → v0.2.350 → v0.2.351`. The incompatible `v0.2.349` package **was not part of that upgrade path**. See [v0.2.351 release checkpoint](docs/IPDAMS_V0.2.351_RELEASE_CHECKPOINT_2026-11-11.md).
- **Next designed requirement:** [period fund utilization, tax disposition, Division-payable withholding transfer and audit history](docs/IPDAMS_FUND_TAX_PERIOD_AUDIT_DESIGN_2026-11-11.md). The design is **not installed code**.
- **GitHub is NOT an up-to-date deployable copy of the hosted application.** Its older branches/PRs track design and UAT history, and a verified, sanitized `v0.2.351` source baseline is still required. Do not use `main` to overwrite Hostinger.

## Development safety

- Never commit actual school/personnel records, financial transaction records, production SQL exports, credentials, tokens, original attachments, signed forms or backup archives.
- Each installable patch must use the **exact user-confirmed installed predecessor**, verified file digests, independent website and MariaDB backups, isolated tests and authenticated role/workflow checks.
- No automatic Hostinger deployment, GitHub Actions runs, accounting postings, DV/check issuance, migrations, remittances or school-record edits are triggered by documentation-only GitHub changes.
- Keep historical policy dispositions and tax component identities immutable; no retroactive tax reclassification to make new funding appear.

See [Development Handoff](docs/DEVELOPMENT_HANDOFF.md) and [Sync Register](docs/SYNC_REGISTER.md) for **historical** 2026-10-26 checkpoints, [Release Safety](docs/RELEASE_SAFETY.md), [Security](SECURITY.md), and [Actions & Cost Policy](docs/ACTIONS_AND_COST_POLICY.md).

## Drive coordination

[IPDAMS Development Drive](https://drive.google.com/drive/folders/1gwRRZYNdsbEhtINpNybAG1JF54WFuyGE) holds release UAT instructions and artifacts. GitHub/Drive reconciliation is **manual**, not continuous or automatically bidirectional. The release checkpoint identifies evidence and known verification gaps.
