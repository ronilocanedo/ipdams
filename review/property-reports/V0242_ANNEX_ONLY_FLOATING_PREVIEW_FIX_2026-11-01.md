# IPDAMS v0.2.242 — Annex-only floating report preview (2026-11-01)

## User report / root cause
After v0.2.241 the entire Report Center (date filters, annex tab selectors, warning and report) opened automatically in a modal on click **Property Accountability → Reports**. The owner wants the Report Center and dates embedded in the regular IPDAMS page; ONLY a selected A.1, A.4, etc. report should float after clicking that annex.

The prior `changeTab` set `propertyReportModal='A1'` on entry, and the modal iframe rendered the whole `semi_expendable_reports.php` page. This was a UI interaction defect, not an ICS or database problem.

## Corrected v0.2.242 updater
Artifact: `IPDAMS_v0.2.242_FLOATING_ANNEX_PREVIEW_FROM_v0.2.241.zip`
SHA256: `26bccd9e0f69ab6da7416722fdfbe0a3c2826ce8d76fd41a6d69edf4d3381798`.
Requires installed version **v0.2.241**. Three code-only files, no migrations:
- `public/assets/app.js` SHA256 `59cb68162b2131b732c4c7505e03b06be8d5e62b24fd8be5b67e9a4a5a3e1bd2`
- `public/app.html` SHA256 `3f388ea91607ae48e1c0c6e7593b2c81ea0d0076629427b74901099e6a2a6af2`
- `public/api/semi_expendable_reports.php` SHA256 `f4782d8ea2ed4a2daef7b1c9cd73816974de95b6c66e6827d03cfed96990b796`

## Correct behavior
1. Click Property Accountability → Reports: **remain in the normal IPDAMS tab**; do not auto-launch a modal. Regular page shows From and Through date filters and eight labeled annex choices.
2. Click an annex (A.1 / A.4 / A.5 / A.6 / A.7 / A.8 / A.9 / A.10): a same-origin floating dialog opens containing **only that report's warning and report sheet** with print/close controls in its own header. Date form, Report Center heading and annex navigation do not appear inside the modal.
3. Close returns focus to the selected annex trigger and preserves the date range. Changing dates and clicking a different annex loads that specific report.
4. Standalone non-preview report URLs preserve the original full report page. `preview=1` is honored **only together with** `embedded=1`; framing remains SAMEORIGIN in embedded mode and DENY otherwise. Authentication and organization scope unchanged.
5. Existing ICS/PAR, asset movements, print contents/values and historical records unaffected. The reports remain **preliminary**, not certified COA annexes.

## Validation
- JS syntax `node --check` PASS
- PHP syntax `php -l` PASS
- 24 PHP rendering fixtures PASS (8 annexes x standalone, embedded full and embedded preview-only)
- ZIP integrity, manifest hashes, predecessor/version and zero migrations PASS
- Authenticated browser UI acceptance on owner's site NOT YET RUN. No new GitHub Actions/production writes.

Install only through normal version-gated updater with backups and clean preflight. Do not install against an unexpected version or drifted source. This replaces the incorrect v0.2.241 UX without changing financial or accountability data.