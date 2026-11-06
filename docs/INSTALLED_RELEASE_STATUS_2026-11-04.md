# IPDAMS Development Checkpoint — November 6, 2026

## Last explicit owner-confirmed installation

The owner confirmed **v0.2.288** installed and that Historical Property **+ Add Missing Catalog Item** appeared after refreshing. Subsequent **v0.2.289** condition dropdown and **v0.2.290** Historical Property register / transferred-out holdings correction packages were prepared, but the owner has not explicitly stated the currently installed release. **Do not assume v0.2.290 installed.** The latest user screenshots concern the ICS/RIS printed format and do not identify the update version.

## NEW: v0.2.291 — STAGED, NOT YET INSTALLED

User provided official-looking template examples for **Appendix 59 ICS** and **Appendix 63 RIS** after showing a plain unbordered browser output from "Print RIS + ICS".

This update corrects both the Property Accountability combined ICS/RIS and the standalone ICS and consumable supplies RIS print routes. It separates RIS and ICS into full black-bordered A4 portrait sheets, official group columns, quantity/amount and signature sections, with an external stylesheet resistant to restrictive CSP. Does not create a new RIS record or fabricate its separate number, paper signatures or fund code.

- [v0.2.291 release notes, technical controls and UAT](V0.2.291_FORMAL_RIS_ICS_APPENDICES_PRINT_UAT_2026-11-06.md)
- [v0.2.291 installation and testing guide](https://drive.google.com/file/d/1vv748moAQwSEF_76tc6x2CoWrnXoyf_c/view)
- [Direct installer **v0.2.290 → v0.2.291**](https://drive.google.com/file/d/1wyIxcL8zQiKGRulhCSOFKotSgHhKqkAG/view) — 7 application files, SHA256 `841d33f845085272f093599836bff1f39f81db7a005ee622189dc690316785e8`
- [Rollup **v0.2.289 → v0.2.291**](https://drive.google.com/file/d/18CbsIFqmgKWuJGIKXSn3lUuyOVah-ZKe/view) — 11 files, SHA256 `1a183dc86fcca1632c5de2e298621a73213130edab94425ed7a8d8ef2afc67c1`
- [Rollup **v0.2.288 → v0.2.291**](https://drive.google.com/file/d/1-3txK5ShFK4DNzyBBfsBR2V0qNQzgnvT/view) — 11 files, SHA256 `4dd61829efd743221e596d4a3fda4e200c12fcd5726ad0afc773a625ae343884`

**No database migrations**, financial/accountability postings or changes to existing issued documents. The exact package must match Settings → System Updates predecessor version; don't bypass gates.

### Test distinction

PHP and JS syntax, ZIP hashes and A4 PDF rendering passed. Combined RIS+ICS rendered as exactly **2 pages**, standalone ICS and supplies RIS as **1 page each**, with both 8-item and 20-item cases and signature sections. Chromium local navigation was blocked by administrator restrictions so **real browser print, authentic role and live MariaDB UAT are NOT confirmed**. Owner should print existing genuine finalized documents and verify physical output, scope, source amount and zero new stock records before accepting.

## Business rules still in force

IPDAMS documents actual paperwork; it does not electronically approve, sign or simulate approvals. Original fund sources/acquisition metadata are documentary, not financial postings. Transferred-out assets must not count as current school holdings after Division receipt but must retain sending school transfer history. Each update has independent website+SQL backups, exact version gate, scope validation and no production resets. GitHub default branch is documentation-oriented, not a verified deployable production source mirror.
