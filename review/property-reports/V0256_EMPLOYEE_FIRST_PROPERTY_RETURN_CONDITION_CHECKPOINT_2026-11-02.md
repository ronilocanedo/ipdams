# IPDAMS v0.2.256 — Employee-first bulk property return

Owner confirmed v0.2.255 installed, working and not yet end-to-end tested. New acceptance request: search accountable employee first, load all open assignments, select some or all, and record **individual observed return conditions**.

Implementation is a version-gated **0.2.255 → 0.2.256** code-only updater, 7 files, no database migrations. It reuses the existing Property Accountability → Return Property tab. Search current employee's open ICS/PAR items, show up to 200 per transaction with Select all/Clear and selected count, record a condition for every selected unit, and preserve prior and return condition in each immutable receipt snapshot and audit.

Bulk returning mixed semi-expendable/PPE creates separate RRSP/PRR receipts and batches more than 20 units per receipt, in one atomic database transaction. A failure on the second receipt rolls back every earlier receipt, custody update and number allocation. Formal unserviceable/disposal lifecycle states remain protected; for a formally restricted asset the return form does not change its condition. Returned items needing maintenance, inspection or repair cannot be newly issued through ICS/PAR until an audited condition correction to Serviceable.

Release ZIP: `IPDAMS_v0.2.256_EMPLOYEE_FIRST_BULK_PROPERTY_RETURN_FROM_v0.2.255.zip`. SHA256 `385bd0227901f9027eefed49b4ffbc17045d33cc7154998a650fb2526f1454c0`. Release notes are downloadable in the current conversation. New change to the existing public app.js uses a cache key bump in app.html.

Validation: PHP/Node syntax PASS, **56 simulated backend assertions**, **15 executable frontend interaction tests**, **16 source and security integration checks**, ZIP hash/CRC and exact version gate PASS. Hosted authenticated browser, real MariaDB concurrency, and final COA Annex A.6 print fidelity remain pending. No live site, database, historical record, GitHub Actions, or production reset was performed in preparing this update. PR #16 remains draft, and full private site source must not be synchronized via an unfiltered ZIP.
