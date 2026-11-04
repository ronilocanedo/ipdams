# IPDAMS — Central Office Textbook Receiving (Staged Transaction Engine)

**Date:** 2026-11-04  
**Status:** **Development-only, NOT an installable IPDAMS release. No live website or SQL changes.**  
**Source baseline:** v0.2.271 ISBN directory update (package source). Whether v0.2.271 is installed has not been confirmed by the user.  
**GitHub branch:** `feature/textbook-co-receipts-staged-transaction-engine`

## Confirmed business rule

The receiving office creates the receipt and books are credited **only to that receiving office** after physical inspection and authenticated acceptance. Two authorized paths share the same backend:

1. **Central Office → Division Office:** Division records the official delivery, inspection and custodial acceptance. Only Division stock increases. Later school allocations must use an independent, authorized Division dispatch and school acknowledgment.
2. **Central Office → School (direct):** The school records its own external Central Office receipt, inspection and acceptance. Only that school stock increases. Division reads the receipt in consolidated oversight but does not create a second phantom receipt or increase its own stock.

In both paths, a global title directory identifies each edition by a validated canonical ISBN-13; adding a catalog entry is **not** adding physical copies. Current legacy ISBN conflicts prevent acceptance rather than silently selecting an edition. Material without an ISBN requires its own approved learning-resource identifier in a later catalog release.

## Delivered source files

- `app/TextbookCoReceiptWorkflow.php` — staged scoped transaction service: `create`, `submit`, `inspect`, `accept`, `reject`, `cancelDraft`, `list`, `detail`.
- `app/TextbookIsbn.php` and `app/TextbookReceiptDistributionRules.php` — existing reviewed ISBN and quantity-state functions, copied into the developer test bundle.
- `public/api/index.php` — **staged** v0.2.271 API wiring. Uses current CSRF, scope and route+payload idempotency transaction. This PHP file **MUST NOT** be deployed before the schema, migration approval and UAT.
- `schema/20261104_textbook_co_receipts.sql`, `schema/20261104_textbook_co_receipt_lines.sql`, `schema/20261104_textbook_co_receipt_postings.sql` — three proposed additive InnoDB tables, **not executed**.
- `development_scripts/test_co_receipt_workflow.php` — independent simulated DB tests with authorized school and Division actors.
- `development_scripts/test_co_receipt_schema_and_api.py` — source, constraints, and portable SQLite synthetic checks (not a live MariaDB migration rehearsal).

### Transaction states

`DRAFT → SUBMITTED → INSPECTED → ACCEPTED` or `ACCEPTED_WITH_DISCREPANCY`; legitimate rejection from `SUBMITTED` or `INSPECTED`, and draft cancellation from `DRAFT`. Only `accept` creates stock.

A signed physical inspector and receiving Property Custodian must use **different authenticated accounts**, and only the currently appointed and linked custodian can finalize acceptance or rejection. A technical `SUPER_ADMIN` cannot impersonate an office as receiver.

**On acceptance**, one serviceable stock lot is created for accepted serviceable copies, and an independent quarantined lot for accepted damaged copies. Both are traceable to a CO receipt line and append a `RECEIPT` movement. Rejected/held and missing counts are never credited to serviceable stock. The old generic posting API remains blocked. Legacy stock screens may display physical condition lots, but future **available-to-distribute** calculations must explicitly exclude quarantined copies.

### Example

Central Office physically delivers 99 of 100 expected copies to Division: 96 serviceable, 2 damaged, 1 held for review, 1 missing. After official acceptance, Division records **96 serviceable + 2 quarantined**; the held and missing copies are unresolved discrepancy entries and are not available. If another shipment of 100 is delivered directly to a school, those 100 are recorded only in that school. Identical Central Office delivery reference strings are permitted in distinct real receiving offices but unique inside each office.

## Verification performed

- 49 simulated transaction tests: exact scope/role checks; canonical ISBN; conflicting legacy catalog; duplicate source references; draft/submit non-posting; inspection count reconciliation; separate inspector and custodian; signed receipt date; condition-quarantine postings; direct school versus Division ledger; idempotent finalization behavior; rejection/cancellation and no posting.
- 26 API/static/SQLite synthetic checks: three append-only tables, per-office unique delivery reference, per-receipt ISBN uniqueness, separate condition posting uniqueness, foreign-key model, and staged API dispatch safeguards.
- PHP 8.4 `php -l` passes all staged PHP source and tests.
- Existing 41 quantity/state transition tests passed previously.
- **Not run** against production data, a live authenticated session, or a genuine MariaDB schema. No inference of live integration pass is made.

## Mandatory deployment blockers and safe next engineering work

1. **Confirm installed version.** Owner has not confirmed installation of v0.2.271. Do not pick a predecessor gate blindly.
2. **Exact MariaDB types and constraints.** Read `information_schema.COLUMNS` of live `organizations`, `users`, `textbook_titles`, `textbook_batches`, `textbook_movements` to verify proposed FK types and the `RECEIPT` event/condition field compatibility before approving any DDL. The current connector has no safe database-schema query action.
3. **Migration allowlist.** `private/app/SettingsService.php` permits only a curated set of exact additive statements; it does **not** permit general CREATE TABLE. New DDL requires a specific audited updater whitelist, result verification, interruption recovery and a pre-migration backup. A generic migration ZIP will be rejected and must not be bypassed with ad hoc SQL.
4. **Safe reset inventory.** `private/app/ResetService.php` has a fixed `IPDAMS_RESET_TABLES` allowlist and Textbooks reset group. Extend both for the three new tables and test reset/backup/restore order under foreign keys. Do not create unexpected production tables first.
5. **Permissions and paper authorities.** Validate the independent inspector account and signed inspection evidence within existing office roles. The current stage records document references, but not file uploads or cryptographic proof. Do not assume every office already has a separate inspector login.
6. **UI remains to be built.** The stage includes API wiring but not a public Textbooks → Central Office Receipts form. Add a compact directory-first receipt form, inspection preview, signed acceptance confirmation, and per-office register before an installable release is published.
7. **Catalog reconciliation.** The stage only accepts verified existing ISBN editions in the legacy directory (up to its audited first 3,000 rows). It intentionally does not auto-add a brand-new book or merge conflicting legacy ISBNs. The division/school can request catalog registration; unique-ISBN administration and missing-ISBN LR-code support must be implemented in a separate safe, globally unique registry.
8. **Fresh transaction integration tests.** Run PHP and MariaDB tests under actual PDO, including a two-user concurrency race for the same CO source reference and duplicate acceptance, the receipt audit and batch/ledger constraints, plus an authenticated browser test of both paths.
9. **UAT stock conservation.** Ensure drafts and inspection never change balances, acceptance creates only physical-receiver stock, quarantined copies remain nonissuable, and partial/held discrepancies cannot be silently corrected without authenticated documentary review.

## Release order

- **Schema-readiness patch**: allow only exact textbook DDL and update reset/backup allowlists. No table creation in this patch. Verify with production-like isolated database restore.
- **CO receipt first installable patch**: audited additive migrations plus complete UI, service/API and tests, from verified installed predecessor. Site and independent SQL backups mandatory.
- **Following patch**: Division dispatch → school Action Center acceptance, with quantity reservations/in-transit reconciliation.

The source in this bundle is for review and testing only. **Do not upload this ZIP to Settings → System Updates or copy `public/api/index.php` onto the live website.**
