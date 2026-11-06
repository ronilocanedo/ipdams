# IPDAMS Release Checkpoint — November 6, 2026 (v0.2.296 staged)

**Owner-confirmed installed version:** **v0.2.295**. It installed successfully; owner could not save a Division MOOE in-charge appointment for a school dated before the person's IPDAMS employment episode. Owner has NOT yet confirmed the v0.2.295 Appendix 64 Posted-by print acceptance because the assignment remains unconfigured.

**Root cause confirmed in v0.2.295 code:** `Domain::saveDivisionMooeAssignment` omitted the posted request `$d` from the closure `use(...)` list; hence the backend read the genuine historical-confirmation checkbox and official document reference as absent. A separate bug referenced `$staff['id']` where the real field is `$staff['assignment_id']`. The UI also displayed stale errors after checking the box.

**New staged v0.2.296:** fixes both backend defects, aligns the checkbox and clears stale errors with documented reference validation; still requires genuine official Division evidence and preserves active employee, dates, school scope, audit and accounting safeguards.

- [v0.2.296 installer ZIP — IPDAMS Development Drive](https://drive.google.com/file/d/1vAOSovJd0qOHLsfWs_MFjpiVg3kc7uK7/view)
- [v0.2.296 detailed release/UAT and security boundary](V0.2.296_DIVISION_MOOE_ASSIGNMENT_SAVE_HOTFIX_UAT_2026-11-06.md)
- [v0.2.296 installation guide](https://drive.google.com/file/d/153GzeTI-DT6I8h3glL7FDv_PE5IPuOXh/view)
- Strict updater **v0.2.295 → v0.2.296**, **3 files, zero SQL migrations**, SHA256 `d54a97ab4da98315966140afc94d9d64fb9f123f202fe1a1c4769be50d0a204a`.
- **8 isolated PHP save/validation scenarios and 12 frontend tests passed**; PHP/Node syntax, ZIP CRC and manifest file checksums passed. Genuine authenticated live MariaDB and printed report UAT are pending. No Hostinger website/database change made while packaging.

Next step: owner installs 0.2.296, refreshes, then saves a *real* Division officer assignment supported by an authentic memorandum. Check the as-of date and actual officer on the school's Appendix 64 semi-expendable report. Do not rewrite employee employment dates or claim an electronic signature.

---

# IPDAMS Release Checkpoint — November 6, 2026 (v0.2.295 staged)

**Owner-confirmed installed and working:** **v0.2.294**. The owner reports Appendix 64 semi-expendable issued report prints successfully. Owner clarifies **Posted by = the genuine Division MOOE In-Charge assigned to that school**, while **Certified by = the school's Property Custodian**.

**Next package staged, not yet installed:** **v0.2.295**, an exact v0.2.294 → v0.2.295 update, **5 changed files, zero migrations**, SHA-256 `9db368d67d7317dba3d0faf45660110702bca504950c488008fee5d051b112c7`. It uses the previously configured Division MOOE Assignments, effective on the ICS issuance date, to fill the officer's name and designation in the Posted by section; signature and date stay blank for paper. If no valid dated assignment exists, leaves identity blank with a preview warning; other-school officers are never substituted. Printing does not post accounts, ICS/RIS or stock.

- [v0.2.295 installer — IPDAMS Development Drive](https://drive.google.com/file/d/1p08Kc8jkWzcv5m2gRhEmA9ZWTVdEtOKs/view)
- [v0.2.295 release notes and UAT](V0.2.295_SEMI_REPORT_POSTED_BY_DIVISION_MOOE_OFFICER_UAT_2026-11-06.md)
- [v0.2.295 detailed owner test guide](https://drive.google.com/file/d/162b2r9Ic0PUDm3Mb6KoHN_L2MF1o8r0k/view)

**Validation:** 18 as-of and scope checks, 8 renderer assertions, PHP/JS syntax, A4 PDF fixtures and ZIP manifest/checksum verification passed. Production authenticated assignment and actual print acceptance remain outstanding. No live website/SQL changes or GitHub Actions were made to prepare this version.

---

# IPDAMS Development Checkpoint — November 6, 2026 (v0.2.294 prepared)

**Owner-confirmed installed and working:** v0.2.293. Owner confirms Print RIS action works, and requests formal **Report of Semi-Expendable Property Issued** matching the provided Appendix 64 styling and smaller italic official UACS title under each code.

**v0.2.294 packaged, not installed or live-tested.** Strict installer `0.2.293 → 0.2.294`, 5 changed files, 0 migrations, SHA256 `a52598e58c06a33e662db72fecdc57f718531c05570967963a78cfefe6510e18`.

- [v0.2.294 installer — IPDAMS Development Drive](https://drive.google.com/file/d/1sSFUmDJgbi75320siGpQ-hjRH6PxjSqU/view)
- [v0.2.294 detailed release, limitations, tests](V0.2.294_SEMI_EXPENDABLE_APPENDIX64_UACS_TITLE_PRINT_UAT_2026-11-06.md)
- [v0.2.294 installation guide](https://drive.google.com/file/d/1pmF_WV6M1eOSTjQKAskd7WSBSMEZzS3s/view)

The report uses **CSP-safe external print CSS**, black-bordered item/recap tables, manual-signature boxes and **official UACS account titles** obtained from effective-dated mappings to the exact authenticated catalog item. Codes/titles, RIS numbers, fund clusters, actual accounting staff signatures/dates and the report serial are **not invented** when unrecorded. Printing cannot post stock or accounting. Existing Print RIS, Print ICS and Print RIS + ICS modes preserved.

Local tests: 56 report/HTML checks, 9 effective-date UACS resolver checks, 80 older RIS/ICS regressions passed; PHP lint, JS syntax, ZIP checks passed; A4 WeasyPrint verified 1/2/4 items one sheet and 8/20 items paginate. **Chromium browser navigation blocked in this environment; owner must perform real authenticated print UAT and financial reference verification**. Actual live Hostinger/SQL untouched.

---

# IPDAMS Installed / Staged Checkpoint — 2026-11-06

**Owner confirmed installed v0.2.292** and its compact RIS + ICS print output works.

**v0.2.293 is staged for owner installation**, adding a standalone **Print RIS** action for finalized ICS. This uses the same authenticated, non-mutating RIS printer and Appendix 63 layout as the paired print. Installer strict predecessor **v0.2.292 → v0.2.293**, 3 frontend files and 0 migrations.

- [v0.2.293 installer — IPDAMS Development Google Drive](https://drive.google.com/file/d/1SCsJwPm-NP_wk_SofvQNQY-YMRMj444j/view)
- [v0.2.293 release note and UAT](V0.2.293_STANDALONE_PRINT_RIS_FROM_FINALIZED_ICS_UAT_2026-11-06.md)
- SHA-256: `36eb640d69d10181ececc91a85c66f4741fbeec20a5501c28ccf0776f1c42735`
- Local 80 JS assertions and Chromium one-/multi-page A4 RIS-only print checks passed. **No live production install or printer acceptance claimed.**

Back up website and SQL independently. Never install GitHub main as site source. The change only prints documentary RIS; does not create a new RIS number, ICS, movement, approval, or accounting entry.

---

# IPDAMS Development Checkpoint — November 6, 2026

## Last explicitly owner-confirmed installed release

**v0.2.291** is installed and working; the owner confirmed the updated Appendix-style formal RIS and ICS printouts render. Earlier v0.2.288 Historical Property quick-add appeared after refresh. Release v0.2.291 was delivered with a direct-from-0.2.290 installer and roll-ups from 0.2.288/0.2.289; the exact upgrade route chosen wasn't specified, but the **installed version v0.2.291** was confirmed.

## NEXT: v0.2.292 PACKAGED, awaiting owner installation

**Print RIS + ICS on the same A4 sheet for short 1–4 item finalized ICS batches**; show saved per-asset **Brand, Model and Serial No.** beneath the item name in small italic font on both Appendix 63 RIS and Appendix 59 ICS. Long batches, long names and unusual descriptions fall back to multiple separate pages. Standalone ICS printing receives the same subtext. No metadata is invented.

- [v0.2.292 release, tests and owner UAT](V0.2.292_RIS_ICS_ONE_PAGE_PRINT_AND_ITEM_DETAILS_UAT_2026-11-06.md).
- [v0.2.292 strict installer](https://drive.google.com/file/d/1zLAKyB-0Gv1SOERVoMaY321Kotz04m-d/view).
- Gate: **v0.2.291 → v0.2.292**, **six print/frontend files, zero SQL migrations**.
- SHA256 `fe8766598cb5ac672273ef958da3c8c939df9dd6cdc509bd6dea9775bec0b582`.
- Local Chromium print-to-PDF fixtures confirm combined **1/2/3/4 item output = one A4 page**, larger batches use separate/continuation pages, no missing signature sections. Standalone PHP ICS/RIS and escaping tested. **Live authenticated user print acceptance pending**.

## General product policy and work priorities

IPDAMS documents paper transactions and genuine signatory names; it does not require named inspectors, custodians, approvers or teachers to log in to electronically approve/sign. Issuing/receiving stock and property movements are guarded by existing backend permissions and immutable audit trails. RIS/ICS print-only changes never create new property, stock, accounting transactions, documents, approvals or document numbers.

After v0.2.292 owner testing: continue Property Accountability/Inventory Management testing; ensure school current holdings exclude transferred-out property while retaining cross-office transfer history and school/Division audit visibility. Preserve exact installed code; GitHub main is a release/documentation index, not a verified deployable mirror of Hostinger. All future patches require independent website/SQL backups and exact version gate. No live production website/SQL writes, migrations or GitHub Actions were performed while preparing v0.2.292.
