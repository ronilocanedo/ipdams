# IPDAMS COA semi-expendable reports — implementation contract (2026-11-01)

Status: **scope agreed; implementation not yet integrated**. User supplied NEW-INVENTORY-FORMS.zip with COA Circular No. 2022-004 and Annex A.1, A.4, A.5, A.6, A.7, A.8, A.9 and A.10 spreadsheets. Keep source templates unchanged. Verify Annex A.2/A.3 against circular and existing ICS behavior.

## Source checkpoint and deployment gate
The default-branch docs/SYNC_REGISTER.md identifies owner-confirmed installed **v0.2.215** as of 2026-10-26, but v0.2.216–v0.2.222 branches/packages are not confirmed installed. The full current deployed application source is not present on main. Do not generate/install a versioned updater or migrate/reset a database before reconciling the real deployed files and schema.

## Phase 1 (read only, no migrations)
- **A.1 Semi-Expendable Property Card:** chronological finalized receipts, issues, returns, transfers and authorized disposals; reference/date, unit cost, running quantity and value balance, organization/asset/fund/date filters.
- **A.4 Registry of Semi-Expendable Property Issued:** original ICS, responsible employee/office, issues, RRSP-linked returns, reissues, transfers/disposals, outstanding quantity; prevent duplicate events.
- **A.7 Report of Semi-Expendable Property Issued:** period-specific finalized ICS line items with responsibility center, property number, description, quantity, unit price, total and applicable signatories.
- All reports use immutable source document/event identity. Cancelled/reversed/draft records do not count as active issuance. Expose unreconciled opening balances; never invent values. Historical as-of reports sort same-day events deterministically.
- Invariant: opening + receipts + returns + incoming transfers − issues − outgoing transfers − authorized dispositions = closing, respecting organization, asset, unit of measure and valuation basis.

## Phase 2 (controlled custody changes)
- **A.5 Inventory Transfer Report:** initiator, releasing custodian, approver and receiving custodian; finalization once, scoped authority, no negative quantities.
- **A.6 Receipt of Returned Semi-Expendable Property:** original ICS linkage, accepted return quantity/condition, returned-by and received-by attestations; block excess/duplicate returns.

## Phase 3 (counts and discrepancies)
- **A.8 Report on Physical Count:** as-of snapshot, actual/book counts, shortage or overage and value, signed review; discrepancy never silently adjusts the register.

## Phase 4 (incident, inspection and disposal)
- **A.9 Report of Lost, Stolen, Damaged or Destroyed:** incident and police details where applicable, accountable party, original ICS, affected quantities/costs; recording incident does not automatically write off property.
- **A.10 Inventory and Inspection Report of Unserviceable Property:** condition, historical acquisition, impairment/carrying values where applicable, authorized disposal, appraisal, receipt and proceeds; independently authorize accounting effect.

## Division Supply/Property Officer
Division-assigned schools: property read/export/consolidate by default; approvals only with explicitly granted workflow-specific authority. No unrestricted superadmin, bank, DV, user management, cross-division, or direct alteration of finalized school records. Enforce authorization server-side on preview, export, print, and APIs.

## Required print and tests
Match exact annex headings, columns, certifications, signature placement and paper settings based on supplied workbook/circular. Replace hard-coded school/SDO names with tenant configuration; never create fake signed approvals. Test zero transactions, reversals, partial return, duplicate receipts, historical cutoffs, valuation precision, cross-school/SDO denial, empty/long paginated print, and audited exports. Use sanitized source only; no secrets or database dumps in GitHub. Do not run paid GitHub Actions unless authorized.

## Next integration deliverable
Obtain exact current deployed sanitized source and read-only schema for assets, organizations, accountability_documents, ICS/RIS, property movements, custodians and report rendering. Build A.1/A.4/A.7 as read-only endpoints and preview/print/export with tests on a reviewed branch, then create one verified updater with exact predecessor and rollback documentation. No production mutation during template work.
