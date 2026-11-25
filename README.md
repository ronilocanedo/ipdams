# IPDAMS — Integrated Procurement, Disbursement and Asset Management System

## Current stable baseline — 25 November 2026 (Asia/Manila)

- **IPDAMS v0.2.449** is the owner-confirmed installed Hostinger version. Financial Reports opens; formatting improvements are deferred. Website and SQL backup availability is owner-confirmed.
- **`main` is the stable source baseline.** Develop new features from `develop/hostinger-v0.2.449` or a reviewed branch based on the current stable baseline. The verified reconstructed source commit is [e668405](https://github.com/ronilocanedo/ipdams/commit/e668405638e67db5fd855bf9bf81c418b1cf31a6).
- The [source manifest](docs/checkpoints/2026-11-25-v0.2.449-source-manifest.json) matches all 256 source files. Exact release candidate QA: 136 PASS, 0 FAIL, 0 BLOCKED. Earlier 267-test and 155-test milestones are historical evidence, not additional tests of this release.
- This public repository is a reviewed source checkpoint, not a deployment mechanism. Live Hostinger file hashes and installer rollback have not been independently verified; historical migration coverage remains incomplete. Reports are uncertified review workpapers. No production configuration, database data or credentials belong here.
- No automated deployment. Owner approval is required for each future commit, push, migration, installation and release.

Coordination and source repository for DepEd school and Division procurement, disbursement, inventory, property accountability, reporting and audit workflows.

## Historical checkpoint — 12 November 2026 (superseded)

- **Owner-reported installed application: `v0.2.368`; `v0.2.369` installer prepared for owner UAT, not yet installed.** The installed version is not independently authenticated against Hostinger/MariaDB.
- [v0.2.369 quotation totals release evidence and installer SHA256](docs/IPDAMS_V0.2.369_QUOTATION_TOTALS_RELEASE_2026-11-12.md). A narrow review-only UI diff and CSS live under `patches/v0.2.369/`; these **are not deployable by themselves**.
- Earlier [v0.2.351 checkpoint](docs/IPDAMS_V0.2.351_RELEASE_CHECKPOINT_2026-11-11.md) and [fund/tax audit design](docs/IPDAMS_FUND_TAX_PERIOD_AUDIT_DESIGN_2026-11-11.md) remain historical records, **not** current installed-code assertions.
- **Historical status as of 12 November 2026 (superseded):** GitHub was not an up-to-date deployable copy; `main` tracked release coordination. Do not use `main` to overwrite Hostinger, merge untested old draft feature PRs, or trigger automatic deployments.

## Development safety

- Never commit actual school/personnel records, financial transaction records, production SQL exports, credentials, tokens, original attachments, signed forms or backup archives.
- Each installable patch must use the **exact user-confirmed installed predecessor**, verified file digests, independent website and MariaDB backups, isolated tests and authenticated role/workflow checks.
- No automatic Hostinger deployment, GitHub Actions runs, accounting postings, DV/check issuance, migrations, remittances or school-record edits are triggered by documentation-only GitHub changes.
- Keep historical policy dispositions and tax component identities immutable; no retroactive tax reclassification to make new funding appear.

See [Development Handoff](docs/DEVELOPMENT_HANDOFF.md) and [Sync Register](docs/SYNC_REGISTER.md) for **historical** 2026-10-26 checkpoints, [Release Safety](docs/RELEASE_SAFETY.md), [Security](SECURITY.md), and [Actions & Cost Policy](docs/ACTIONS_AND_COST_POLICY.md).

## Drive coordination

[IPDAMS Development Drive](https://drive.google.com/drive/folders/1gwRRZYNdsbEhtINpNybAG1JF54WFuyGE) holds release UAT instructions and artifacts. GitHub/Drive reconciliation is **manual**, not continuous or automatically bidirectional. The release checkpoint identifies evidence and known verification gaps.
