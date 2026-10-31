# v0.2.237 ICS duplicate-evidence review — 2026-11-01

Superseding **review-only** candidate: `IPDAMS_v0.2.237_ICS_DUPLICATE_REVIEW_FROM_v0.2.236.zip`. SHA256 `be612dfaf2b850989798728e2a69859a060b1424ebcc1eff5070e224e49d1397`.

Based on current 0.2.237 consolidated review. Replaces two additive files only: `app/SemiExpendableReports.php` and `public/api/semi_expendable_reports.php`.

## Correction
Earlier report failed the **entire** result with HTTP 409 if the same asset appeared on multiple finalized ICS documents in the selected period. The revised report retains those records and marks both rows as REVIEW_REQUIRED with `multiple_finalized_ics`, adds a duplicate asset count and grouped warning counts. It never silently chooses one ICS as the current legal custodian.

## Checks
PHP CLI lint on both files passed, archive CRC passed and file SHA256 values embedded in manifest were generated from exact payload. No database-backed UAT or authenticated school permission testing. No migrations, no official Annex A.1/A.4/A.7 outputs and no other annexes. **Not installation-ready.** Owner production/staging site untouched; no GitHub Actions.

Next: sanitized fixture-based ICS/event matching and transactional custody-source identity, then official annex rendering and protected print authorizations.
