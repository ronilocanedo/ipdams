# IPDAMS v0.2.273 book-profile editing — executable review package

**Status: not installable. Do not stage in Settings > System Updates.**

The owner requested a full, optionally completed book profile with a strict edit rule: the registering receiving school and its supervising Division may edit; an independently receiving school may reuse the same edition but may not edit its metadata; Division-created book profiles are edited by that Division only.

## Verified implementation in this review branch

- `TextbookBookProfilePolicy.php`: executable PHP validation and permission-policy class.
- `test_book_policy.php`: 25 independent assertions for minimal drafts, optional details, canonical ISBN, LR identifiers, cross-school and cross-Division denial, Super Admin denial, before/after change comparisons.
- Local copy of this module passed PHP 8.4 syntax checks and 25 tests on 2026-11-04. **This is NOT a test of the site's routing, database persistence or authenticated browser.**
- Local downloadable review bundle is supplied separately in the current ChatGPT response; **it is not the production updater**.

## Why we did not issue a production ZIP

The Hostinger site has `assets/app.js` of size 966,595 bytes. The `feature/v0.2.272-co-textbook-receipts` development branch's `public/assets/app.js` is visibly an earlier ~945 KB baseline, missing the latest interactive CO receipt UI. The Hostinger file-content API returned empty bodies for text files. Shipping the older complete `app.js` would roll back verified working features. The current `textbook_titles` table has only title, ISBN, grade and subject in the available source; storing additional optional book details requires verified persistence design. The existing system updater SQL migration allowlist and safe reset rules must be reviewed before any new table is introduced.

## Remaining integration gates, in order
1. Recover the exact v0.2.272 live source files via authorized backup or readable website file content and verify hashes.
2. Verify school-to-Division authoritative hierarchy, users/scopes, actual SQL types and required snapshot/audit tables.
3. Create persistent, versioned metadata drafts and publication records with per-office provenance, canonical ISBN/LR uniqueness and no stock mutation.
4. Integrate authenticated API and full Book Directory UI Add/Edit; serve other schools read-only, including server enforcement.
5. Reconcile existing registered ISBNs; preserve CO receiving and transfer controls, no rekey of old titles.
6. Test isolation, full scope matrix, data audit history, migrations/rollback, browser and updater strict predecessor **v0.2.272**.
7. Package an actual installable **v0.2.273** ZIP only after these tests and independent website and database backups.

No production site or database changes were made here. GitHub `main` is not a deployable live-site clone.
