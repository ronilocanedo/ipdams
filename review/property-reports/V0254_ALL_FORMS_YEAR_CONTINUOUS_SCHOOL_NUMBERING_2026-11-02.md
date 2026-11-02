# IPDAMS v0.2.254 — Year-continuous numbering for **ALL** official forms

**Owner specification (2026-11-02):** All numbered forms have continuous serials through the calendar year, never resetting when the month changes. The printed/control number remains `YYYY-MM-NNNN`. Each school has its own independent starting sequence **per form type**, using the **existing School Numbering** settings (and its existing Super Administrator counterpart). This supersedes the earlier v0.2.254 ICS/PAR-only candidate: **do not install that earlier ZIP**.

## Release candidate
- Installed prerequisite: **0.2.253** (owner confirmed).
- ZIP: `IPDAMS_v0.2.254_ALL_FORMS_ANNUAL_SCHOOL_NUMBERING_FROM_v0.2.253.zip`.
- SHA256: `421c9b479b0a0bbc529319e4dca77ce1c1d1a2d8433a0a6b6befbce4231376d3`.
- Version gate: 0.2.253 → 0.2.254. No migrations.
- Files: `app/AnnualDocumentNumbering.php` (new shared allocator), `app/Domain.php`, `app/TravelWorkflow.php`, `app/TravelReimbursement.php`, `app/PropertyTransferWorkflow.php`, `app/NumberingSetup.php`, `app/SchoolNumberingPage.php`, `public/settings.php`. The last is a sensitive Settings entrypoint and should receive required updater critical-file review.
- Covers **14 form types**: AR, TA, RC, PR, RFQ, AWARD, PO, IAR, DV, RIS, ICS, PAR, ITR, PTR. The new ITR/PTR configuration rows are included in the EXISTING School Numbering and administrator settings; this adds no competing settings page.
- Form number *month* still reflects the existing issuance/document-date rules. The serial is per school/type/year. Example October `2026-10-0125`, November `2026-11-0126`. A separate school or document type has a separate counter. Property-tag sequences and bank check-leaf numbering are separate and unchanged.

## Storage and safety
- Reuses `document_counters` with `period='YYYY-00'` as the annual lock/next-sequence row. `document_registry` and finalized slip numbers keep real `YYYY-MM`.
- Existing monthly settings/counters remain intact and are protective floors. Prior issued year-to-date numbers are also scanned and counted to avoid collisions even if existing October/November TEST data both ended with `0001`. No historical document, counter, source financial record or ICS/PAR history is rewritten by this package.
- The existing School Numbering server saves per-school/year/form starts in an audited transaction, requires authorized role/password/CSRF from existing entrypoints, and blocks changing a year's starting series once any numbered form of that type has been issued in the same school/year. Reject a proposed annual start lower than old monthly configured counters. One shared allocator must execute inside each issuing service's existing DB transaction; transfer's provisional PENDING registry row is explicitly excluded from its year-to-date check.
- On later authorized clearing of test records, school administrators set the real continuation number in the existing School Numbering before first issuance of each form/year.

## Tests and limitations
- `php -l`: all **8** changed PHP files pass.
- **104 executable synthetic PHP assertions pass**, including every form type, every year/month transition, separate organizations/types, configured starts, legacy monthly floors, old duplicated serials, transfer placeholders, transaction gates, malformed types/months and 9999 exhaustion.
- ZIP integrity and all manifest hashes verified; exact from/to versions and no migrations.
- **Not yet tested:** authenticated browser preflight/install on the owner's website, real MariaDB transaction concurrency, year transition during live issuance, and printed output. A safe test-site candidate only.
- This branch's checkpoint is not a claim of full private source synchronization. Do not upload backups, secrets or database dumps. PR #16 remains draft; no GitHub Actions triggered.

Release notes delivered in conversation as `IPDAMS_v0.2.254_ALL_FORMS_ANNUAL_NUMBERING_RELEASE_NOTES.md`. **Use ONLY the ALL_FORMS ZIP, never the earlier ICS_PAR-only ZIP.**