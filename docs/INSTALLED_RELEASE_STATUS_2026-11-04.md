# IPDAMS Installed Release Checkpoint — 4 November 2026

## Latest owner installation and staged patch — 2026-11-04

- Owner reports **v0.2.273 installed** (Add Book initially opened to a white screen). **v0.2.274 is installed**, and owner confirmed Add Book works.
- **v0.2.275 is staged, not yet installed.** It adds audit-retained book draft discard and closes additional creation/list safety gaps. Release ZIP and UAT are in the IPDAMS Development Drive, documented in [v0.2.275 release notes](V0.2.275_BOOK_DRAFT_DISCARD_UAT_2026-11-04.md).
- All versions after v0.2.272 still need authenticated live receipt/stock and MariaDB acceptance checks. Do not assume installing equals verified user acceptance.

## Owner-confirmed installation
- Installed release: **v0.2.272**, confirmed by owner in conversation on 2026-11-04.
- Release: `IPDAMS_v0.2.272_CO_TEXTBOOK_RECEIPTS_FROM_v0.2.271.zip`.
- Documented strict predecessor: **v0.2.271**.
- Documented package scope: **6 application files; 0 schema migrations**.
- Hostinger IPDAMS site file listing confirms receipt workflow source present under `private/app/TextbookCoReceiptWorkflow.php`, as well as `TextbookDirectory.php`, `TextbookIsbn.php`, and `TextbookReceiptDistributionRules.php`; the authenticated runtime and installed-version database state have **not** been independently inspected here.

## Current functionality, pending actual owner UAT
1. Shared ISBN registration is catalog-only and must never credit physical inventory.
2. Central Office -> Division direct intake credits only Division upon independently inspected and custodian-accepted receipt.
3. Central Office -> School direct intake credits only the receiving school, never phantom Division inventory.
4. Signed/attested `DRAFT -> SUBMITTED -> INSPECTED -> ACCEPTED` (or discrepancy) with append-only document snapshots; reject/cancel do not post inventory.
5. Serviceable lots are separated from damaged-quarantined lots. Held and missing stock is not issuable.

**Owner's install confirmation is not a confirmation that these flows have passed live UAT.** Keep these statuses separate.

## Next test sequence on the existing authorized IPDAMS site
- **A. New ISBN:** Textbooks -> Central Office textbook receipts -> Register ISBN. Check catalog addition, no change to office stock, duplicate ISBN-10/ISBN-13 blocked.
- **B. Division CO receipt:** Create authentic Division draft; confirm no stock change; submit; independently inspect; accepting custodian attests; confirm Division-only serviceable/quarantined lots; replay refused.
- **C. Direct school CO receipt:** Repeat in an authorized school workspace; confirm school-only stock and cross-office access guard.
- **D. Discrepancy and audit:** Verify counts, quarantine, reject, cancel, duplicate/source replay and immutable history before designing dispatch.
- If a 500/incident reference occurs, stop, inspect the register for an already committed transaction and report the exact action/error before retry.

## Next engineering module after intake UAT
Division-to-school textbook dispatch with an authorized allocation, quantity reservation/in-transit reconciliation, receiving school Action Center acceptance, partial delivery and audit-safe reconciliation. Do not simulate this through generic stock adjustment or legacy transfer writes.

## Sources and safety
- Release/UAT specification on private Drive: https://drive.google.com/file/d/1_edH982vQwz15BHIJi1MbRVEckplqEgh/view
- Prior staged design handoff: https://drive.google.com/file/d/10B4tNlYnBdRUY9uudjUW1clE4zy_DdoV/view
- The `main` branch remains documentation/review-oriented and is **not** a verified synchronized full copy of the live application. Do not deploy GitHub main over Hostinger.
- This commit is **documentation only**: no file installation, live account change, SQL write, reset, migration, financial transaction, or GitHub Actions run.
