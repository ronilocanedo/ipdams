# IPDAMS v0.2.369 — Supplier quotation automatic totals

**Prepared:** 2026-11-12 (Asia/Manila). **Owner-confirmed installed baseline:** v0.2.368. **Status:** Installer prepared and locally validated; **NOT yet installed or tested against authenticated Hostinger/MariaDB**.

## Exact installer

- Filename: `IPDAMS_v0.2.369_SUPPLIER_QUOTATION_AUTOMATIC_TOTALS_FROM_v0.2.368.zip`
- SHA-256: `c08fd30cd6c3f8947aedd900ec0af2b491f572009d80414852d3cfa87a3fbfb5`
- Strict predecessor: `0.2.368`; version after install: `0.2.369`
- Three frontend files: `public/assets/app.js`, new `public/assets/workflow-quote-live-total-369.css`, `public/app.html`
- Zero database migrations, no PHP/SQL modifications, and no install-time transaction, payment, or tax posting.

## User change

In **Procurement → Supplier Quotations → New Supplier Quotation**, show a **Total (₱)** column beside **Offered unit price (₱)**. Update the row total immediately as unit price changes, then show a grand **Quotation total** for all RFQ items. The quantity is always taken from the saved RFQ, and the displayed amount equals that quantity multiplied by the entered offered price. Missing/invalid prices show a dash rather than a fictitious zero. Calculations use integer centavo/thousandth arithmetic before display rounding.

**Examples:** 4 printer units × ₱9,500.00 = ₱38,000.00. A second item of 2.500 units × ₱400.00 contributes ₱1,000.00, giving ₱39,000.00 total.

These are **read-only previews**. The existing supplier quote submit payload remains unchanged; server-side RFQ, price, supplier, duplicate, award/PO closure and official document checks still apply. No existing quotations change.

## Verification and release controls

- Verified v0.2.368 `app.js` and `app.html` digests against predecessor release.json before modification.
- `node --check` and actual bundled React event-handler/element-tree tests passed: computed totals, partial quantities, live input updates, missing price, invalid price and retained Save button.
- ZIP CRC, exact member list, per-file SHA-256, release title length, predecessor and zero-migration checks passed.
- **Pending:** authenticated browser/MariaDB integration test against the hosted system, and user acceptance after installation.
- Require independent website and database backups; install **only from v0.2.368** using Settings → System Updates; no automatic GitHub Actions/Hostinger deploy.

## Repository scope

This private repository is **not a synchronized application-source mirror**. The review-only JS patch and new CSS stored under `patches/v0.2.369/` describe the incremental change; **they are not deployable alone**, because the full Hostinger v0.2.368 source is not committed to `main`. Never deploy GitHub `main` over Hostinger or merge old draft application code to make the repo look current. The ZIP is the actual installer distributed through the user conversation.

## Recent owner-installed release history (reported, not independently authenticated)

The owner reported successful installs of v0.2.352 through v0.2.368, most recently v0.2.368 on 2026-11-12. Reports and local installer checks are not equivalent to authenticated financial UAT or full source mirroring. Current ongoing work also tracks [Fund/withholding issue #72](https://github.com/ronilocanedo/ipdams/issues/72) and [Supplier Quotations issue #79](https://github.com/ronilocanedo/ipdams/issues/79).
