# IPDAMS v0.2.219 — Asset Register first-render white-screen repair

**Status: prepared for owner-led staging installation; NOT installed or production-ready.** Owner confirms v0.2.218 installed on existing staging/dev IPDAMS and Property Accountability shows an empty white screen.

## Exact regression and reproduced cause

v0.2.218 introduced `propertyRegisterFilters`, `propertyRegister`, and `propertyDetail*` in the **organization-change reset state only**. It omitted these from the actual `const initial = {...}` app state. On first navigation to Property Accountability → Asset Register, rendering `filters.search` throws **`Cannot read properties of undefined (reading 'search')`** and blanks React's UI.

The v0.2.218 Chromium test wrongly pre-populated these state fields in its harness, so it failed to expose the initial-state crash. A new regression harness uses the **actual initial object extracted from the packaged app.js**, and proves both the original crash and the corrected first-render behavior.

## Focused correction

- Initialize `propertyRegister` with empty rows/facets, `propertyRegisterFilters` with complete ALL/default values, plus loading/error and selected-property detail state in the true initial app state.
- Guard paging and async rendering against optional state during an incomplete transition.
- Cache-bust `public/assets/app.js` in `public/app.html`.
- **Only two files changed:** `public/assets/app.js`, `public/app.html`. **Zero database migrations, backend changes, data changes or posting effects.** Existing asset Details/filter backend from v0.2.218 remains unchanged.

## Package review

- Sequential manifest `0.2.218` → `0.2.219`, PHP ≥8.2, two file checksums, zero migrations.
- ZIP name: `IPDAMS_v0.2.219_PROPERTY_REGISTER_INITIAL_STATE_WHITE_SCREEN_FIX_FROM_v0.2.218_STAGING_REVIEW.zip`
- ZIP SHA256: `ee9a4e73546c7d673547e74f755d30d356225e0709ed5fbc4cfb47bc05bfb233` (184985 bytes).
- `public/assets/app.js`: `b303e7dc150351c9ff60d3147fb11d7ca4bb5039dbf04adf95a419b6df04ac93`.
- `public/app.html`: `b65a8833317fca540397b6336ba778e1bdfda1bb7ecd4d4c4da5282583ae12a1`.
- ZIP CRC, exact file inventory, manifest SHA256 and Node `--check`: **PASS**.
- Isolated Chromium bundled React16 **original v0.2.218: crash reproduced**; **fixed v0.2.219: first Asset Register render, Details open/close, Division classification, search and Clear filters pass with zero browser errors**.
- No paid GitHub Actions run. No live hosted changes performed by assistant.

## Ownership and distribution

The verified ZIP has been generated as a **conversation download**, and full UAT notes are available in the conversation. The Google Drive Testing Patches upload is **not yet confirmed**; do not claim mirroring or present an unverified Drive URL. Do not merge this review branch until owner confirms staged ZIP installation/UAT. GitHub does not contain a full verified source baseline for the deployed app; treat this as patch evidence, not automated deployment.

## Owner UAT

1. On staging IPDAMS Settings → System Updates, upload exact ZIP. Expect 2 file changes, zero migrations; apply only when predecessor reports `v0.2.218`.
2. Confirm installed `v0.2.219`; hard-refresh browser.
3. Property Accountability → Asset Register must render. Check existing Taloto National High School assets `306351-2610-00001`, `306351-2610-00002` remain; do not re-register.
4. Open property `00001` Details and verify saved location Room 103, batch members, correction history. Check `00002` separately unchanged.
5. Test classification filter and tag/room search, Clear filters, Historical and Edit tabs.
6. Report UI/HTTP results. Do not claim authenticated cross-school/UAT passed before testing.

**Source note:** owner confirms staging/development site with disposable test data; no additional site, reset or GitHub Actions needed.
