# IPDAMS v0.2.239 consolidated COA report-center staging candidate — 2026-11-01

Owner-confirmed installed baseline: v0.2.238, screens loaded normally. This candidate is a **single version-gated, code-only updater** from v0.2.238, **not the completed eight official COA annexes**.

## Artifact and source
Artifact downloadable in the current conversation: `IPDAMS_v0.2.239_SEMI_EXPENDABLE_REPORT_CENTER_FROM_v0.2.238_REVIEW.zip`
SHA256 `881917748a34d9fd6757189f8e912dc42c4ee4b3f94e244b6acb803a78e1f560`.

Manifest: `format=1`, `from_version=0.2.238`, `version=0.2.239`, **no migrations**, 5 application files:
- `app/SemiExpendableReportCenter.php` (7a90a6e2a49095718126ab325b1fa34f08a5050310957a9e005e4ac9c3d0db98)
- `public/api/semi_expendable_reports.php` (76546095755ff4889c6365880e775a22d1e32e18082057bf66eb7c446c42fe60)
- `public/assets/semi-expendable-reports.css` (4c6aaf4a2c155249c633c5a33393df57a1dba0e08df2310476607de00395669d)
- `public/assets/semi-expendable-reports.js` (ca8155b28c2d8ce1ac1d2794592b2e8bc6a3cc77a43461c3141ee2d82b4fb9dc)
- `public/assets/app.js` (e41ba3653513b05aa58cdff759f5514a90a665f7449b32c9dd35ac547d9ef120)

Original v0.2.236 source `assets/app.js` SHA `5fc1d55b61c68f153a9e256f3c18348334bdec3990887ae81f00ef24048c6615`; v0.2.237/238 prior updater files changed only SettingsService and Domain, respectively. Still validate actual hosted app.js source has not drifted.

## Delivered scope
- One authenticated report hub, school/org scope on every server-side read; VIEWER denied.
- Exact data categories from supplied Annex A.1/A.4/A.5/A.6/A.7/A.8/A.9/A.10 spreadsheet templates mapped to actual DB columns.
- A.7 verified final ICS issue evidence from explicit v0.2.238 link, per-asset amount using integer centavos; old unlinked docs listed as review exceptions.
- A.1 asset/acquisition preparation card with unverified historical balances clearly unfilled.
- A.4 actual ISSUE/RETURN/TRANSFER/DISPOSAL movement audit, never inferred RRSP/stock ledger.
- A.5 actual transfer evidence + navigation to existing ITR workflow.
- A.6 return evidence worksheet, A.8 physical-count prep list, A.10 disposal-evidence list; no invented signatures/approval.
- A.9 incident workflow guidance (not an incident report).
- No writes, no migrations, no historical backfill, no auto-signing or postings. Print marked PRELIMINARY REVIEW COPY, not a certified official form.

## Validation
PHP syntax files and tests PASS; Node JS syntax PASS; local simulated DB read-only scope and linked ICS fixture tests **10 PASS**, business logic/date/link/cost tests **12 PASS**. ZIP CRC and manifest hashes PASS. **Not tested:** authenticated browser on real v0.2.238 website; real PDO MySQL report SQL, division role permissions, official annex print layout and signatories. These are required before business acceptance. Previous owner Docker A/B/C checks validate v0.2.238 linkage and updater, not this new reporting package.

## Follow-on
Complete formal RRSP, count, incident, inspection/disposal data workflows and prescribed form fidelity and signatories; reconcile opening balances, transfer/legal custody and accounting approvals. Keep the PR draft. Source files are included in candidate ZIP; this GitHub checkpoint records metadata and does not itself claim source synchronisation. No GitHub Actions or production writes.
