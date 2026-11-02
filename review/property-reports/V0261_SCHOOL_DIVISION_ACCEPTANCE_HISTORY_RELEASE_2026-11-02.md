# IPDAMS v0.2.261 — Receiving Property Acceptance and Verified Full History

Owner confirms installed v0.2.260 and requests one secure receiving-acceptance patch applying **both to schools and Division Offices**, with access to the **complete verifiable history of transferred property**.

## Built release

- `IPDAMS_v0.2.261_SCHOOL_DIVISION_RECEIVING_ACCEPTANCE_FULL_HISTORY_FROM_v0.2.260.zip`
- SHA256: `b2838f1f399c23b14f8b60b9862d553fc2866babb91f2aed3b33033faa7c870b`
- Exact version gate **0.2.260 → 0.2.261**. Eight app source files and `release.json`; **zero SQL migrations**.
- Files: `app/Domain.php`, `app/PropertyReturnWorkflow.php`, new `app/PropertyTransferAcceptance.php`, `app/PropertyTransferWorkflow.php`, new `public/api/property-transfer-intake.php`, `public/api/property-transfers.php`, `public/assets/app.js`, `public/app.html`.

## Security and workflow

A registered receiving school or Division Office has Incoming Transfers → Inspect / Accept, and a linked receiving Asset Register / Received Assets view. Super Administrator must audit-bind an existing scoped SCHOOL or DIVISION user to the currently appointed AO/Property Custodian employee and appointment version; it does not grant new organization scope. Actual receipt is permitted **only to this linked account**, never to Super Admin acting as substitute. Actual paper signatures remain external.

Each receiving office chooses matching local/shared same-category catalog item, receipt date, physical room/location, per-unit observed condition, official handover reference and physical confirmation. Atomic transaction locks source and recipient organizations, source assets, the original transfer and approval binding; rejects unauthorized office, completed/unverified transfer, duplicate tag/serial, wrong category, abnormal formal lifecycle status, and conflicting custody. Marks source asset `TRANSFERRED`, preserves original IAR and ICS/PAR, creates new `ACTIVE` asset linked to the source transfer with no fabricated local IAR/acquisition accounting, closes old open accountability through immutable transfer-document evidence, writes dual-office audits and movements, and appends `RECEIVED` transfer snapshot. Multi-item failure rolls back all entries and document status.

The receiving office's **Full Property History** follows a verified chain of original source asset and accepted receiving-transfer snapshots, not a guessed matching tag. Shows ownership sequence, original acquisition cost/date/IAR and available PO reference, historical ICS/PAR, returns and transfer document references, movements, audit changes, lifecycle status versions, and signed handover reference. Read-only view protects original source files and personal/attached documents behind separate permission checks; it is not electronic-signature proof. Bounded queries fail explicitly rather than silently truncate.

## Validation and release boundary

- **50 executable simulated v261 PHP transaction, permission, chain-of-title and multi-item rollback assertions PASS.** Existing v260 transfer regression suite PASS (including 31 destination assertions and Division permission/print tests). PHP lint six files PASS; full main JS syntax PASS; ZIP CRC, manifest SHA256 and exact version gate PASS.
- **Still not completed:** actual authenticated hosted browser transaction, real MariaDB concurrency and inter-org acceptance, physical inventory reconciliation, signed print fidelity, and final COA receiving/acquisition reporting treatment. This is a controlled development update, not certified production acceptance.
- No hosted application deployment, database mutation, historic record renumbering, automated approval, test reset or GitHub Actions in preparation. PR #16 remains draft; this release checkpoint does **not** claim the full private site code is synchronized to GitHub.

Detailed package release notes were provided in the conversation as `IPDAMS_v0.2.261_RECEIVING_ACCEPTANCE_FULL_HISTORY_RELEASE_NOTES.md`. Before installation, independent site and SQL backups plus the normal updater preflight and critical-file source checks are required. Begin testing with one synthetic pending transfer (e.g., `ITR 2026-11-0002` addressed to registered Division Office) and **actual receiving custodian login**, then confirm origin historical asset, recipient new asset, Full history, duplicates prevented and no double acceptance.
