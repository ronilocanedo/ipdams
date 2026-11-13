# IPDAMS — Integrated Procurement, Disbursement and Asset Management System

## Latest verified Hostinger checkpoint — 13 November 2026

- **Currently installed on Hostinger: `v0.2.375`.** Independently checked read-only against `private/storage/private/settings/installed-version.json` on 2026-11-13.
- **GitHub application source is available in branch [`develop/hostinger-v0.2.375`](https://github.com/ronilocanedo/ipdams/tree/develop/hostinger-v0.2.375)** — 210 SHA-256-verified first-party files, two exact legacy React runtime files, tested offline static build tooling, and updated Settings smoke test.
- **For OpenCode or another coding agent:** clone or switch to `develop/hostinger-v0.2.375`. The default `main` branch remains **documentation/coordination**, NOT the up-to-date application workspace. OpenCode can otherwise report the older `v0.2.368` from historical documentation.
- **No automatic Hostinger ↔ GitHub synchronization exists.** This is a pinned source snapshot, not an authorization to deploy. PHP integration/financial UAT, React 16 security replacement, ownership/privacy/licensing approval and release readiness remain outstanding.
- Source/audit history: [PR #83](https://github.com/ronilocanedo/ipdams/pull/83) (complete source, DRAFT), [PR #84](https://github.com/ronilocanedo/ipdams/pull/84) (Settings test fix, DRAFT), [PR #85](https://github.com/ronilocanedo/ipdams/pull/85) (frontend build, DRAFT). Keep PRs draft; no merge, production update, live DB access or GitHub Actions is implied.


Private coordination and review repository for DepEd school and Division procurement, disbursement, inventory, property accountability, reporting, and audit workflows.

## Historical checkpoint — 12 November 2026 (superseded)

- **Owner-reported installed application: `v0.2.368`; `v0.2.369` installer prepared for owner UAT, not yet installed.** The installed version is not independently authenticated against Hostinger/MariaDB.
- [v0.2.369 quotation totals release evidence and installer SHA256](docs/IPDAMS_V0.2.369_QUOTATION_TOTALS_RELEASE_2026-11-12.md). A narrow review-only UI diff and CSS live under `patches/v0.2.369/`; these **are not deployable by themselves**.
- Earlier [v0.2.351 checkpoint](docs/IPDAMS_V0.2.351_RELEASE_CHECKPOINT_2026-11-11.md) and [fund/tax audit design](docs/IPDAMS_FUND_TAX_PERIOD_AUDIT_DESIGN_2026-11-11.md) remain historical records, **not** current installed-code assertions.
- **GitHub is NOT an up-to-date deployable copy of the hosted application.** The owner installed incremental ZIPs to Hostinger; `main` only tracks reviewed release coordination. Do not use `main` to overwrite Hostinger, merge untested old draft feature PRs, or trigger automatic deployments.

## Development safety

- Never commit actual school/personnel records, financial transaction records, production SQL exports, credentials, tokens, original attachments, signed forms or backup archives.
- Each installable patch must use the **exact user-confirmed installed predecessor**, verified file digests, independent website and MariaDB backups, isolated tests and authenticated role/workflow checks.
- No automatic Hostinger deployment, GitHub Actions runs, accounting postings, DV/check issuance, migrations, remittances or school-record edits are triggered by documentation-only GitHub changes.
- Keep historical policy dispositions and tax component identities immutable; no retroactive tax reclassification to make new funding appear.

See [Development Handoff](docs/DEVELOPMENT_HANDOFF.md) and [Sync Register](docs/SYNC_REGISTER.md) for **historical** 2026-10-26 checkpoints, [Release Safety](docs/RELEASE_SAFETY.md), [Security](SECURITY.md), and [Actions & Cost Policy](docs/ACTIONS_AND_COST_POLICY.md).

## Drive coordination

[IPDAMS Development Drive](https://drive.google.com/drive/folders/1gwRRZYNdsbEhtINpNybAG1JF54WFuyGE) holds release UAT instructions and artifacts. GitHub/Drive reconciliation is **manual**, not continuous or automatically bidirectional. The release checkpoint identifies evidence and known verification gaps.
