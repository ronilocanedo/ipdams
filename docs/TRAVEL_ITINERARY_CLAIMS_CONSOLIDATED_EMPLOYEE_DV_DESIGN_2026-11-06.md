# IPDAMS — Itinerary → Travel Reimbursement → Employee Consolidated DV (Design)

**Date:** November 6, 2026 (Asia/Manila). **Status: design reviewed against existing source; no installable release, schema migration, real financial posting or site changes.**

**Full implementation plan:** https://drive.google.com/file/d/1otq-fFWDgxdJ4RSVaXb4SCIyoqUUMKdM/view  
**Plan SHA-256:** `0303765b8d0c910a40699c6ce040743d057e5a27ce27b0b9cc608106f191d4fb`

## Owner requirement

The reimbursement component captures/documentarily reviews **only the eligible reimbursable amount**, not payment. The **DV** shall combine one or more different reimbursement claims of **the same employee** into **one voucher**, subject to same authentic paying office/fund and no previous active DV link. Actual reimbursement is linked to existing documented payment/check-release events; saving claim or finalizing DV is **not** proof of payment. Preserve paper signatures and app encoder audit; do not require named paper signatories to electronically approve.

The uploaded sample is one-page Appendix 45 **Itinerary of Travel** covering September 18–20, 2026, itinerary number 2026-10-0001. Amounts: fare PHP5,730, per diem PHP3,150, other/terminal fees PHP110; claimed total **PHP8,990**, **not automatically payable**. The itinerary lists dated routes, times, modes, and hand-signed certification/approval sections. Its Division entity header and school official station do not conclusively determine the actual paying office/fund. Link that decision to TA/AR chargeability and the real accountable fund.

## Existing source inspection

- `app/TravelReimbursement.php` currently registers one `RC` claim per `TA` as a versioned non-posting document with 1–20 expense lines, evidence attachments and `DOCUMENTS_CHECKED_FOR_ACCOUNTING_REVIEW`; no eligible amount, DV, payment link or authoritative Appendix 45 line builder.
- Current `Domain::createDV` `REIMBURSEMENT` is limited to **one procurement advance** against PR→RFQ→Award→PO→IAR. Do **NOT** extend its assumptions to travel by selecting an advance or spoofing a vendor/PO.
- `disbursement_vouchers.dv_type` ENUM currently has `REGULAR`, `DIRECT`, `REIMBURSEMENT`. `dv_allocation_lines` references procurement sources; a genuine multi-claim DV needs a **reviewed forward-only schema migration**, a new distinct `TRAVEL_REIMBURSEMENT` DV type, and transactionally unique claim-link rows.
- The existing check workflow records genuine `RELEASED` and `CLEARED` events; check release creates fund DISBURSEMENT once. Link payment history to real events rather than assumed DV payment.
- Documentary review currently requires a separate Division account; align future workflow with user's **paper-based documentation** rule: record the real written evidence and named paper reviewer, not an electronic-signature approval.

## Proposed workflow

1. Link Activity Request → Travel Authority → **actual Appendix 45 itinerary** (upload scan plus structured dated itinerary legs). Preserve original PDF; do not silently parse/guess.
2. Reimbursement claim pre-fills actual trip data and itemizes fare, daily expenses and other eligible travel costs. Keep `claimed_total` separate from `eligible_total`, disallowed amounts/reasons, authentic prior advances or externally-paid offsets, and `reimbursable_amount`.
3. An authorized encoder records real documentary review and evidence; **READY_FOR_DV** means amount reviewed, not paid or approved electronically.
4. **Prepare DV → Employee Travel Reimbursement**: choose one authoritative employee ID and any number of full **eligible, unlinked** claims with **same payor and fund**. DV uses exact claim amounts and generates an attached claim schedule. Different employee/fund/office, existing DV link, or paid claim is blocked.
5. Reserve selected claims atomically when DV draft is saved. Cancelled draft releases holds with immutable audit evidence. Finalized DV locks sources and requires the real finance UACS/accounting/fund/date guards. **Payment** status updates only on documented check release or other supported actual payment event.
6. Reports distinguish claim total, eligible amount, reserved in DV, finalized unpaid DV, paid, and corrections.

## Implementation order and tests

- **Stage A:** Appendix 45 capture/upload, link to TA/RC, metadata/amount reconciliation, versions and audit (no accounting writes).
- **Stage B:** per-line allowable/rejected expense and offsets with paper evidence; payer/fund eligibility, ready-to-DV state.
- **Stage C:** backward-compatible DV schema/route addition for multiple employee travel claims; safe atomic linking, race/duplicate/cancel cases, fund/UACS/employee scope and existing procurement DV regression.
- **Stage D:** Appendix 32 voucher + claim schedule print, actual check/bank event linkage and cross-module reports.

Test with synthetic data mirroring the September sample (claimed PHP8,990; payable amount remains unverified until real paper review), plus **two different eligible trips for one test employee** combined into one DV. Reject mixed employee/fund, already linked/paid claim, double click/race, unbalanced journal, unsigned paper evidence, and insufficient fund. Existing procurement reimbursement DVs, PO/IAR, tax and checks remain unaffected.

**Owner version:** last explicitly confirmed installed **v0.2.296**; v0.2.297 TA print update was prepared but not yet confirmed installed. Never assume a version gate for financial changes. Preserve independent website and SQL backups and perform isolated migration-interruption and rollback rehearsals before any patch. GitHub main is a documentation/review index, not a synchronized production source.
