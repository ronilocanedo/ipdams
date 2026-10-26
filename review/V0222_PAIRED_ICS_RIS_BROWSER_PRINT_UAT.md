# IPDAMS v0.2.222 — browser-generated RIS above ICS

**Status: staging-review candidate built, not installed.** Required installed predecessor: **v0.2.221** (owner's current installed version must be confirmed by staging updater). Owner previously confirmed v0.2.220; v0.2.221 was delivered but owner has not explicitly confirmed its installation in chat.

## Artifact provenance

- [Private Drive ZIP](https://drive.google.com/file/d/1mqxaaDU7HOobJHJxtRd8XwhtLn1_83_V/view)
- [Private GitHub exact ZIP](artifacts/IPDAMS_v0.2.222_RIS_ABOVE_ICS_STAGING_REVIEW.zip)
- ZIP SHA-256: `ee9d8abebc28f1dba5d9f8c4a50ff43e3f560a208bb8416451f113743c37a76b`
- ZIP size: **191449 bytes**. Google Drive raw-file readback size **191449** and GitHub blob SHA **81a352a013d3a08345f1e970577a421122676025** match local `git hash-object`.
- Exact application paths: `public/assets/ics-ris-pair-print.js` (new), `public/assets/app.js`, `public/app.html`. **0 SQL migrations.** No PHP/controller file, stock, accounting, asset row, numbering or database alteration.
- [Drive acceptance/UAT guide](https://drive.google.com/file/d/1bZQHl88rmCdGop0xWsrhejcQlFf8Zzfe/view).

## Implementation route

Prior design expected to modify staging `public/api/print.php`, which is **not present in current GitHub/Drive individual source**. Instead this bounded staging candidate adds a **client-side print window from the existing authenticated school-scoped frontend data**; it neither creates a new public route nor modifies the existing print.php. Existing `Print ICS` and `Print PAR` remain untouched. For a finalized ICS on **Issued Slips**, an extra **Print RIS + ICS** action:

1. Opens a print window synchronously from the user's button action.
2. Uses authenticated `property.registration.detail` GET for the selected organization and asset; verifies matching saved asset ID, unique tag and `FINALIZED` ICS number; stops if school changes or detail mismatches. Optionally reads the existing school-scoped `staff.directory` to suggest currently configured Principal and AO identities; unavailable/wrong AO returns blanks rather than inventing a name.
3. Renders RIS Appendix 63 above ICS Appendix 59 using **the same ICS number** for documentary filing. Printed ICS Inventory Item No. is the unique physical property tag, never Division registry code. RIS one-row Stock No. is 1. Requestor and receiver are the recipient; issuer is assigned school AO; approver is Principal. Names are current staff suggestions, **not signed issue-date approval evidence**. Signature and unverified date fields are blank. Fund/RCC/Division fields left blank when not safely available.
4. Does not POST or create additional actual RIS, stock event, fund event, issue/approval, counter or accounting posting. The form is a **companion print**, not an independently approved RIS transaction.

## Local test evidence

- ZIP CRC, manifest format/from/to, per-file SHA-256 and allowed paths verified against owner-uploaded SettingsService.php. Both JS files pass `node --check`.
- React16-like actual App component test confirms button only for FINALIZED ICS, legacy print link retained, correct secure detail route, same-number RIS + ICS and valid/current-role signer suggestions; mismatched property/tag fails closed, cancelled ICS ignored. All assertions PASS.
- Browser renderer rejects PAR and cancelled ICS, escapes untrusted HTML, has blank signatures/dates and no extra Division registry printed identifier.
- Headless Chromium renders both appendices in **one 612×792 point US Letter portrait PDF page** with accessible on-screen Print action, no clipped sections in proof fixture.
- **Not tested**: real server authenticated browser workflow (including popup policy and actual school A/B denial) or exact real-world principal/recipient paperwork and all existing print regressions. Required during staging UAT before production use.

## Required UAT

1. Confirm installed v0.2.221 before staging the verified v0.2.222 ZIP (3 frontend files, 0 migrations); do not bypass preflight if installed version differs.
2. On the existing staging site, issue a disposable ICS for a previously unissued SEMI_EXPENDABLE test asset; do not reissue the existing finalized asset, and do not issue PAR to ICS.
3. Open **Property Accountability → Issued Slips** and click **Print RIS + ICS**. Confirm one Letter page, saved ICS number in both fields, correct unique property number, item/cost/useful life, recipient, principal/AO as configured, wet signature and dates blank, no stock available flags invented. Confirm original Print ICS/PAR actions still work.
4. Compare row/columns and signatures to owner Appendix 63/59 PDFs; compare DB event/counter counts before/after printing (all unchanged); test cross-school denial, popup blocked, long description overflow and unavailable staff appointments.
5. Keep this source on private draft PR until authenticated acceptance. No more staging sites, resets or Actions.

## Limitations

This solves the **documentation-first one-asset-per-ICS companion RIS** print requirement. A future separately approved transactionally linked RIS, signed attestation ledger, or multi-unit ICS header will require different backend/schema/print work. This update does not add those features.
