# IPDAMS v0.2.243 — immediate internal property transfer; policy checkpoint (2026-11-01)

Owner confirms all current property records are **test data**, and requests a simpler documentary workflow.

## Agreed behavior
- **INTERNAL (same school) transfer:** recording the ITR/PTR immediately updates the named receiving employee's property custodian in the same database transaction that creates the versioned document, movement, links, numbering and audit. No later signed-paper receipt step, employee login, extra confirmation, or Division Supply/Property Officer approval.
- **Paper documents:** printable ITR/PTR and companion RIS remain documentation; physically signed copies stay outside IPDAMS for any later verification. IPDAMS does not claim wet/electronic signatures or paper verification.
- **EXTERNAL (inter-school):** remains document-only pending; avoid cross-school asset organization reassignment until item catalog and IAR lineage protections are implemented. Do not silently move property across schools.
- **Previously pending internal ITR/PTR:** provide one-time “Apply saved internal transfer” action to reconcile without pretending a signature, rather than automatically changing historical records at installation. Previously RECEIVED and CANCELLED records remain untouched. Use a distinct `RECORDED` status for documentary internal custody updates.
- **Division Supply/Property Officer authorization (owner clarification, 2026-11-02):** required for **property disposal** and only for **condition changes involving an unserviceable classification or a high-value property**. Ordinary condition updates for other assets are recorded by an authorized school user with reason, before/after history and audit; no division approval. Internal employee transfers remain immediate and do not require division approval. The monetary high-value threshold has **not yet been provided**, so it must be explicitly configured from an authorized policy before the condition-approval workflow is activated; never assume an amount. An unserviceable-to-other-condition change should also remain controlled to avoid evading review. These are future approval workflow requirements **not implemented in the v0.2.243 transfer patch**.
- Maintain school organization scope, active staff validation, property review, no duplicate asset lines, no stale custodian overwrite, audit history and atomic rollback.

## Prepared version-gated package
`IPDAMS_v0.2.243_IMMEDIATE_INTERNAL_PROPERTY_TRANSFER_FROM_v0.2.242.zip`
SHA256: `63d5ebdf8a6104c1cd333e8aaae86b466588fb33f99bc13b35696956ac1ca131`.

Requires installed **v0.2.242**, two application files:
- `app/PropertyTransferWorkflow.php`
- `public/api/property-transfers.php`
No SQL migrations or other site/app JS replacements. The package is built from the owner's uploaded application source; previous 0.2.237–0.2.242 updates changed unrelated report and ICS/updater source paths.

## Validation
- PHP CLI syntax of both files: PASS.
- 18 focused static contract checks: PASS.
- **19 behavioral assertions** against the patched real `PropertyTransferWorkflow` with an in-memory/mock PDO query adapter: PASS. Includes atomic single/multi-asset internal transfer, duplicate denial, audit, unsigned evidence fields, simulated link-insert failure and complete transactional rollback, cross-school denial, external document-only unchanged asset, one-time legacy pending conversion, repeated legacy prevention, and same-custodian denial.
- ZIP CRC and per-file SHA256 manifest verification: PASS.
- **Not yet verified:** actual MariaDB-backed versioned transfer workflow, authenticated browser end-to-end UAT in installed v0.2.243, future Division Supply/Property Officer approval flows.
- Preserve source preflight and independent test-site backup. This is a staging candidate, not a certified property-accounting release. No Actions or live DB writes in its preparation.

## One focused owner UAT after clean staged install
Record new INTERNAL transfer, confirm ITR/PTR immediately shows RECORDED and custodian updated with exactly one TRANSFER event; print document, verify no fake signature. Confirm old pending internal allows explicit one-time apply, and external remains prepared only. Do not repeat earlier ICS/PAR Docker suites—they test a different feature.
