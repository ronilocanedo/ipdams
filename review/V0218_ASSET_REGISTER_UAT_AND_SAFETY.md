# IPDAMS v0.2.218 — Property Asset Register Details & Filters

**Status:** staging review candidate, NOT installed. Prepared 2026-10-26 (Asia/Manila). Strictly updates installed `v0.2.217` → `v0.2.218`.

## Goal

Make each Asset Register row verifiable, not merely a property number. Open **Details** to view the full saved unit record, shared acquisition details, exact original batch and linked units, formal custody documents, movement history and recent audited corrections. Use filters that follow the school's actual catalog and Division-approved classifications rather than hard-coded ICT/office category names.

## Manifest and proof

- ZIP: `IPDAMS_v0.2.218_ASSET_REGISTER_DETAILS_FILTERS_FROM_v0.2.217_STAGING_REVIEW.zip`
- SHA256: `17400f9480c23627d3018bd999f8eb1997a325b96ce5b8ff46db894b9997f939`
- Four files: `app/Domain.php`, `public/assets/app.js`, `public/app.html`, `public/assets/property-register-details.css`.
- Zero migrations; no altered property/custody/stock/check/fund/financial posting code.
- Existing, verified predecessor snapshots: v0.2.216 `app/Domain.php` SHA256 `3c170bddaeeec42d03874bd5ea098923aaf047f639104070660fa018ae6cbb0a`; v0.2.217 `public/assets/app.js` SHA256 `86c8830f96b790fa1cbb558f9b294e5620deab239036eba358f4eaa5f6e5a383`.

## Functionality

1. **Asset Register search and filters:** text search for tag/item/serial/location/custodian/condition; main category (PPE/semi-expendable); Division-approved classification from actual item mappings/subcategories (such as ICT Equipment, Office Equipment, ICT Equipment ADM when configured); exact catalog item; accountable custodian including unassigned; status; registration source (historical vs verified IAR).
2. **Pagination:** server-side school-organization-scoped list, 25 assets per page with next-page cursor. Avoid relying on the old 100-row display limit for browsing.
3. **Details:** asset property number, item, classification, acquisition date/cost, serial number, room, condition, custodian and historical custodian reference, legacy property number, shared brand/model/acquisition type/useful life, historical PO/IAR/DV textual evidence, verified IAR reference, exact batch key and members.
4. **Custody/history:** issued ICS/PAR records, asset movements, recent 30 audited actions including before/after descriptive changes with correction reason and actor.
5. **Security:** reuse existing authenticated `list` and `property.registration.detail` routes. Server-side active-organization scope, operational permission restriction, parameterized bounded queries, and exact-school batch members. Do not expose new details to a Division MOOE viewer or unauthorized cross-school requester.

## Verified local tests

- PHP syntax `app/Domain.php` PASS; Node syntax `app.js` PASS; manifest file list, ZIP CRC and all file SHA256 PASS.
- React 16 Chromium simulated: open property details, show changed Room 103 and audit before/after, navigate within exact two-unit batch, close details, filter Division classification, enter/search/clear query, no browser exceptions PASS.
- Static review: route reuses `list` with organization scope; correction/detail query requires same school and existing authorization; no SQL migrations; no write operations introduced.
- **Not yet established:** real deployed MariaDB HTTP behavior, actual accessible Division class options, cross-school/VIEWER request denial, complete audit JSON and real UI pagination beyond 25. These must be tested on the existing disposable staging site after owner installation. No GitHub Actions run was requested.

## Owner staging installation & acceptance

1. Confirm staging currently shows installed **v0.2.217**. Preserve existing site/database backups already made. Settings → System Updates → upload v0.2.218 ZIP → review **four** files and **zero** migrations → Apply once.
2. Refresh IPDAMS and open Property Accountability → Asset Register. Click **Details** on `306351-2610-00001`. Confirm location is `Room 103`, serial `TEST-SERIAL-001` (or the actual saved serial), school property number unchanged, and `PROPERTY_DETAILS_CORRECTED` history shows previous vs current location and the entered reason.
3. Open sibling `306351-2610-00002` from the same batch. Confirm original room/location and serial remain unchanged. Verify both share the same batch acquisition profile.
4. Try Asset type **Semi-expendable**, Division classification (whatever is actually configured), item **Colored Printer**, Custodian **Unassigned**, Status **ACTIVE**, Origin **Historical**, search by tag/serial/room; clear filters.
5. Create enough disposable property units to cross 25 rows *only if useful for this test*; check Load next 25 and no duplicates. Do not reset existing assets to do so.
6. As an unauthorized school account / restricted VIEWER, try the existing authorized interface/direct API with another school's asset ID and ensure 403/404 rather than leaking data. Test issued ICS/PAR lock on edits separately, and ensure there are no new stock/fund/DV/PO events from merely viewing Details.

**No production deployment, data reset, new database migration or GitHub Actions run was performed in preparing this candidate.**
