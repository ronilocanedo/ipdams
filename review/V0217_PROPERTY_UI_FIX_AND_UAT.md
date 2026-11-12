# IPDAMS v0.2.217 — React 16 Property UI Repair

**Environment:** Owner-confirmed staging/development website; latest installed **v0.2.216** at the time of packaging. **Staging-review only; NOT installed or UAT-confirmed on hosting.**

## Reported reproducible defects

1. Property Accountability → Edit Property Details → change location (also affected serial, condition, brand/model fields): full React UI blanks out.
2. Historical Property → successful multi-unit registration: acquisition date, cost, and shared historical batch fields remain in the next form.
3. Historical registration success banner remains indefinitely, including across tab changes.

The owner already registered two disposable staging assets at Taloto National High School, with tags `306351-2610-00001`, `306351-2610-00002`. This update does **not** touch the asset table, numbering sequence, accounting or audit source.

## Verified diagnosis

`public/assets/app.js` in the exact checksum-matched staging-installed v0.2.216 REV2 ZIP uses `e.target.value` **inside** the functional `setState(prev => ...)` callback for per-unit and per-batch property edit inputs. React 16 pools/recycles SyntheticEvent objects. A real Chromium/React 16.0.0 reproduction produced `Cannot read properties of null (reading 'value')` and an empty root. Capturing the text value synchronously *before* passing the functional state updater eliminated the exception.

## Reviewed correction (frontend only)

- In per-unit and per-batch edit inputs, capture `const value=e.target.value;` synchronously and use captured `value` in `setState`. Guard nullable `batch_units` in preview labels.
- On successfully saved historical batch, reset **all** form fields: selected catalog item, number of units, acquisition date, unit cost, shared brand/model/source/evidence values, serials/locations/legacy identifiers. Clear IAR registration shared/units after its own success as well. Do **not** clear fields on error or cancellation.
- Show a dismissible success message, automatically expire after 7 seconds and dismiss upon property-tab navigation. Prevent an earlier notice timer from removing a newer message.
- Bust browser cache with updated `public/app.html` app.js query string.

## Candidate

- **Exact ZIP on private Google Drive:** https://drive.google.com/file/d/13CVjCILIBt9YE9mmIXCvN8NH8nHZVvgF/view
- **Exact ZIP in private GitHub branch:** [review artifact](artifacts/IPDAMS_v0.2.217_PROPERTY_FORM_AND_EDIT_WHITE_SCREEN_FIX_FROM_v0.2.216_STAGING_REVIEW.zip)
- **ZIP SHA256:** `68d83e18772ef338ddead487ce540a12f1e9431d95dcbdf0e46c1bac398a5adf`
- **Git blob SHA:** `c58bac2570c487861724e8c86deb3488a3ef0c02`
- **Manifest:** strict predecessor `0.2.216`, target `0.2.217`; exactly **2 files** `public/assets/app.js`, `public/app.html`; **0 migrations**. No PHP, database or accounting logic changes.
- **File SHA256:** app.js `86c8830f96b790fa1cbb558f9b294e5620deab239036eba358f4eaa5f6e5a383`; app.html `498a9aba88b079df20d7891b200a8b47ef11c5ec269afe87c790ed744cd1d4c3`.

## Tests already passed

1. Local node `--check` syntax.
2. ZIP CRC, manifest file hashes, predecessor/version checks and no SQL inventory.
3. Chromium with actual bundled React 16.0.0: **unpatched** edit crashes with null SyntheticEvent target; **patched** UNIT location, serial, condition and BATCH brand/model edits pass with zero page errors.
4. Browser mock API registration of two units: emitted unique property tag values preserved; entire historical registration form resets after success.
5. Successful correction mock sends `property.registration.correct` with asset ID, UNIT scope, expected IDs/versions and new location; dismissal button, tab-dismissal and 7-second expiration pass.

The browser tests are local UI simulations; server-side save, audit durability, and cross-school permissions still require **owner-led staging HTTP/UAT**.

## Owner UAT after staging install

1. Confirm Settings shows installed v0.2.217 (no migrations).
2. Hard-refresh; open Property Accountability → Edit Property Details. Select `306351-2610-00001`, change only location, reason ≥12 characters, save. Confirm no white screen and the second property unchanged.
3. Reopen same asset; BATCH edit brand/model only, save; confirm both matching batch units updated while property tags, serials, costs, source and ICS/PAR custody remain protected.
4. Register a new small *disposable* historical batch; after success, ensure fields cleared; notice dismisses by button, navigation, or 7-second timer.
5. Inspect audit and accounting/stock unchanged if further certification desired.

**No additional staging site, reset, paid GitHub Actions run or actual hosted installation was performed when building this candidate.** Keep review PR draft pending owner verification.
