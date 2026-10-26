# IPDAMS v0.2.220 — Property Details Floating Window

**Environment:** Owner-confirmed staging/development website only. Do not represent this as a production deployment.
**Required predecessor:** installed v0.2.219.
**Proposed release:** v0.2.220 (frontend-only; no database migration).

## Enhancement

The **Asset Register → Details** action now opens an accessible floating dialog without moving the register below the filters and rows. This replaces the long inline Details panel in v0.2.219.

- **Overview:** immutable school property number, item, current saved location, serial, accounting/Division classification, condition, present custodian, legacy reference.
- **Acquisition:** acquired date, cost, brand/model, useful life, acquisition source and historical PO/IAR/DV (clearly marked as text-only historical evidence), verified IAR when present.
- **Registration batch:** exact original batch identifier and unit count, all matching physical units, individual serial/location/status, and **View unit** to navigate without closing the dialog.
- **Custody & audit:** ICS/PAR, asset movement history, and expand-on-demand audit records. Changes to location/serial/shared metadata are shown as readable **Before → After** fields and the correction reason, while unchanged metadata is not mistaken for a correction.
- Keyboard accessibility: focus starts in the dialog, Tab wraps, Escape closes and restores focus to the original Details button. Clicking outside closes; body scrolling stays locked while open.
- Responsive modal width/height and internal scrolling on desktop and mobile; no printing, posting, migration, asset mutation or backend access rule change.

## Reviewed package

- ZIP: `IPDAMS_v0.2.220_PROPERTY_DETAILS_MODAL_FROM_v0.2.219_STAGING_REVIEW.zip`
- SHA256: `f9dc0eece3da077415798389b0658a8ea07ac11a78cbcd085489012c9117d115`
- Files: `public/assets/app.js` SHA `1fe639c49e1e830b3e010ca57037997849d159f9f3975255f94d6480a4b10f4a`; `public/app.html` SHA `b247c07f5b8246d430f04b369c67e446563d0591b53bf949567deb013e724915`; `public/assets/property-detail-modal.css` SHA `b2813ba65e201b6f4d06c37b89eb56603d697599b80f4b92bb37507291039e49`.
- Manifest `format=1`, `from_version=0.2.219`, `version=0.2.220`, **zero migrations**. Existing `app/Domain.php`, permissions, database, and property edit/numbering workflows are not modified.

## Checks already passed locally

Node JavaScript syntax; ZIP CRC and manifest hashes; real React 16 first-load Chromium fixture; modal first open with correct unit; acquisition and historical evidence; exact batch sibling navigation; custody empty states; audit before/after verification (`Room 101` → `Room 103`); Escape and backdrop close, return-focus and scroll lock; live classification/search controls unaffected; 390 px viewport sizing; **zero runtime exceptions**. These browser fixtures use synthetic responses. Server-side authenticated staging testing remains required.

## Owner acceptance test on staging

1. In **Settings → System Updates**, confirm currently installed is **v0.2.219** before staging/applying this review candidate. It must show 3 application files and zero database migrations.
2. After installing, hard refresh the site (`Ctrl+Shift+R`) and confirm **v0.2.220**.
3. Open **Property Accountability → Asset Register**. Both original school properties should remain: `306351-2610-00001` at **Room 103** and `306351-2610-00002` at **Room 102**. Filters should continue working.
4. Click **Details** for the first property. A centered floating window should open; the underlying register stays in place. Review **Overview → Acquisition → Registration batch → Custody & audit**.
5. In Registration batch, view the second unit without closing the window; confirm its location is Room 102 and that it is in the same two-unit batch. Then view the first property again.
6. Open **Custody & audit**, expand the correction event. Verify the reason and the actual **old Room 101 → new Room 103** values (assuming that is the recorded old location).
7. Press Escape to close, reopen and click **Close**. Try narrow browser/mobile width. Confirm there is no white screen.
8. Verify your current school cannot open another school's property by changing the property ID in a direct API request using an authorized low-privilege test account; expected server denial. This is an existing access rule, not a changed one.

Report screenshots or exact errors. Keep the GitHub pull request draft until authenticated staging UAT passes. Do not reset, re-register these existing assets, or rerun GitHub Actions for this frontend-only change.