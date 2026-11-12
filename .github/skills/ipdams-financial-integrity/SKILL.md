---
name: ipdams-financial-integrity
description: Preserve accounting, procurement, funds, travel reimbursements, tax components, DV, checks and financial controls across IPDAMS changes.
---

# IPDAMS Financial Integrity

## Golden rules
- Treat draft, reviewed, approved, finalized, check-committed, released, cleared, reversed and acknowledged as distinct states where applicable.
- Never post a payment, assign a check number, consume an allocation, use retained withholding, or mutate accounting entries merely on rendering, preview, import, installation or draft save.
- Enforce unique active claim→DV links and appropriate cancellation/reversal before re-selection.
- Compare **gross expense** with **net check/cash** and **withheld tax liability** as separate facets. Never subtract the same expense or withheld cash twice.
- Tax transferred to Division through a dedicated liability-settlement DV is **not a new expense**, and Division acknowledgement is not proof of BIR tax remittance.
- Restrict obligations and cash availability to the correct school, fiscal period, fund, bank and approved allocation/policy.
- Retain original DV/PO/PR, ATC, withholding components, funding_basis and audit evidence; do not silently reclassify history.

## Risk-driven checks
1. Review who may draft, account-review, finalize, issue, release, reverse and acknowledge.
2. Trace data from originating PR/RFQ/PO/IAR or employee claim to a **single** finalized financial or inventory effect.
3. Test duplicate submission, stale browser, concurrent API requests, partial failure, rollback and correction.
4. Validate gross/net/withholding equations and multi-line journals where required; fail closed if the accounting policy has not been signed off.
5. Do not make an additive schema or release change without exact current migration parser and isolated MariaDB testing.
6. Verify print/export and historical reports present the same immutable financial facts as the database.

## Output
Write a state transition table, a ledger effect table (none/draft/commit/cash), and synthetic acceptance cases. Clearly label any pending accountant-policy approval or authenticated UAT. Never claim release-ready while these are missing.
