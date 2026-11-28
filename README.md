# IPDAMS — Integrated Procurement, Disbursement and Asset Management System

## Current stable baseline - 28 November 2026 (Asia/Manila)

- **IPDAMS v0.2.468** installed and working on Hostinger (owner-confirmed; GitHub promotion explicitly approved). Retained withholding reconciliation, shared withholding reporting and compatibility with original confirmed-retention DV funding format.
- **`main` is the stable source baseline.** [Source manifest](docs/checkpoints/2026-11-28-v0.2.468-source-manifest.json): 283 source files; seven modified and one added since v0.2.464. No new migrations. Four separate historical targeted synthetic suites: 85, 96, 99 and 139 passes, not additive. [Latest QA](docs/checkpoints/2026-11-28-v0.2.468-qa.json).
- Read-only adjustment uses verified historical policy and matching DV funding snapshot/audit, valid original components and bank-cash evidence; no extra allotment, bank deposit, tax clearance or spending authority. Offset capped to current ordinary deficit; genuine deficits or incomplete evidence remain visible. School-utilized and remittance views reuse existing reports and remittance workflows. Division-wide and individual-download calculations unchanged.
- Preserve prior paid-only Actual APP rules, integrated finance, textbook access and report safeguards. Actual live hashes, full production reconciliation, MariaDB/HTTP integration and installer rollback remain independently unverified. No production testing or automatic deployment.

Coordination and source repository for DepEd school and Division procurement, disbursement, inventory, property accountability, reporting and audit workflows.

## Latest completed milestone - Retained withholding reconciliation

- v0.2.465 through v0.2.468 installed; final v0.2.468 owner-confirmed working. Four release manifests and QA records preserved under `docs/checkpoints/2026-11-28-v0.2.*`. No financial postings or historical record changes on installation.
- Next proposed development version: v0.2.469. Review actual source and any reported issues before further changes.

## Standard development and release policy

**Main must represent the latest verified IPDAMS version successfully installed and confirmed working on Hostinger, not experimental development.** Adopted by the owner on 25 November 2026.

| Branch | Purpose |
| --- | --- |
| `main` | Latest verified, owner-confirmed working production source |
| `develop/*` | Ongoing enhancements and bug fixes, based on the current stable source |
| `feature/*` | Major features needing separate development and review |
| `checkpoint/*` | Historical milestones and verification evidence |

### Release cycle

1. Develop related features from the latest stable source; keep changes small, versioned and reversible.
2. Review code, security and financial integrity. Run relevant isolated Docker/MariaDB and Playwright tests using synthetic data only; retain prior QA evidence and avoid unnecessary repetition.
3. Prepare and verify the exact versioned update package, excluding QA-only code, fixtures and experimental changes not approved for production.
4. Obtain explicit owner approval and verify complete website and database backups before installation on Hostinger.
5. Obtain owner confirmation that the installed version works; record any unresolved issues and the limits of verification.
6. Reconcile the installed release's available source with the tested package and GitHub, verify source integrity, and promote the successful version to `main` with explicit owner approval. Preserve both histories; do not force-push or silently discard changes. Create a version tag for the stable release when separately approved.
7. Update the README baseline, current development handoff and verification evidence; continue new features from the newly established stable version.

No automatic deployment or automatic merge is authorized by this policy. A successful test run alone does not establish a working Hostinger release. Owner confirmation alone does not prove live file-hash parity. Keep historical branches and audit history; never publish production records, credentials, private configuration, backups or sensitive QA evidence.

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
