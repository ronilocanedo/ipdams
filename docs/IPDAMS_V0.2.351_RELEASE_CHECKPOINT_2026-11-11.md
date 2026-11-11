# IPDAMS v0.2.351 — release and reconciliation checkpoint
**Recorded:** 2026-11-11 (Asia/Manila)  
**Installed version:** **v0.2.351, owner-reported**. This is not an independent authenticated Hostinger version/database readback.  
**GitHub source-mirror status:** **INCOMPLETE** — this repository's `main` is documentation/governance, not a verified code snapshot of the installed application. **Never deploy repository `main` as IPDAMS v0.2.351.**

## Verified release lineage from private development Drive
| Release | Exact predecessor | Source of release evidence | Installer SHA-256 | Migrations |
| --- | --- | --- | --- | --- |
| v0.2.348 | v0.2.347 | [UAT instructions](https://drive.google.com/file/d/1j1X0Wh7lh5H3gPfOCwBo9GAAhbjJcEXd/view) | `e1a27b36dd6fe3acf6128aaad1ecce5ec37e504e45d4e3d8689a52515487eea9` | None |
| v0.2.350 | v0.2.348 (intentional skip of incompatible v0.2.349) | [UAT instructions](https://drive.google.com/file/d/1jJDdh_aS0w6mDcGlKGEKnghn6w40gXQf/view) | `b0a2f4eaa614fa451bf631cf32bff78f5f571ba861b9625df3edd35c04123c30` | None |
| v0.2.351 | v0.2.350 | [UAT instructions](https://drive.google.com/file/d/17wePMog08Jp-QT_IBKlm8T0QLdErWGGl/view) | `feb66231a8f082e5ad705f6a74212239d9cffd1cfbfd159dbd62aba1a0de27b3` | None |

The v0.2.349 installer targeting v0.2.347 is **not** in this installed upgrade path. Do not install it over v0.2.351.

## What v0.2.351 changes
- DV's policy-enabled Non-IUS cash sufficiency projection uses the same school+fund-scoped bank-linked download and issued-check ledger displayed by Cash & Bank.
- Still enforces correct division policy, gross authorization plus qualified unused confirmed withholding, physical check/account constraints, and an officer's separate *actual bank cash verified* attestation.
- Cash & Bank -> Bank Transactions: debit before credit, chronological oldest-to-newest, running balances across full history, browser-current-month initial filter, "All months" option.
- Cash & Bank -> Fund Balance Register: each fund's current book separately, including funds sharing a bank account.
- **Not** a direct bank statement/feed, an authority to spend, proof of check clearance or actual bank charges, automatic transfer to Division, or a full independent financial UAT.
- No installer-time posting, tax-transfer DV, deposit, bank movement, check issuance, schema change, or historic-data rewrite.

## User-requested next milestone (design only; NOT an installed patch)
**Fund Management -> Transaction History & Audit Trail**, linked to existing **Cash & Bank -> Bank Transactions** and **Fund Balance Register**:
- Show selected school/division scope, calendar/fiscal period, fund, document/source, operation, status, gross approved/finalized expense, actual net check cash, tax withheld by ATC, retained/confirmed/reconciled tax, and remittance-to-Division statuses.
- Separate *gross budget utilization*, *cash spent/committed*, and *withheld-tax liability/settlement*; never add withholding as a new deposit/allotment.
- Historical transaction references and immutable source policy snapshot must survive later policy changes; support controlled reversal/correction events rather than manual deletion.
- Detailed design: [Fund/tax/audit ledger specification](IPDAMS_FUND_TAX_PERIOD_AUDIT_DESIGN_2026-11-11.md).

## Before another installable release
1. Obtain and sanitize exact **v0.2.351** full source from hosted installation (no `.env`, secrets, uploaded evidence, real personal/financial records, production SQL dumps). Compare release hashes and manifest.
2. Inspect actual `Domain.php`, `BankFundRegister.php`, `tax_utilization.php`, check/payment, DV and fund ledger paths and structure-only MariaDB schema. Do not infer the schema from old GitHub docs.
3. Confirm Division Accounting/COA-approved treatment of withheld-tax utilization and **check-paid Division remittance** per school fund. Separate remittance liabilities from actual transfer and BIR settlement.
4. Implement server-enforced permissions, reconciliation, idempotency, per-fund source lineage, bank double-entry/clearing safeguards and concurrent-transaction locks, with isolated synthetic MariaDB and authenticated UAT.
5. Prepare a strictly **v0.2.351-predecessor** installer after independent website+SQL backups and review. No GitHub Actions deployment or financial posting triggered by this documentation.

Historical PRs #38–#40 remain draft design/UAT checkpoints; absence of authenticated finance/migration signoff prevents treating them as deployable current code.
