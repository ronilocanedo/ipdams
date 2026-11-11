# IPDAMS — Fund utilization, withholding tax and period audit (v0.2.351 design)
**Design checkpoint:** 2026-11-11 PHT. **This document changes no application code, database, bank records or payments.**  
**Starting point:** user-reported installed v0.2.351. Use the [release checkpoint](IPDAMS_V0.2.351_RELEASE_CHECKPOINT_2026-11-11.md) and its evidence before implementation.

## Navigation and responsibilities
- **Fund Management -> Fund Utilization & Balances**: current/fiscal period money summary. Add **gross utilized expenditure**, **net checks actually paid**, **withheld tax**, **retained confirmed**, **retained utilized**, **withholding for Division transfer**, and **outstanding remittance liability**. Do not combine sums that measure different things.
- **Fund Management -> Transaction History & Audit Trail**: authoritative joined, read-only-at-report-time, date-filtered **financial transaction register** covering source authorization/downloads, obligations, finalized supplier and employee DVs, check commitments and clearings, tax component recognition, confirmed retained tax, utilization links, Division remittance DV, check, acknowledgement and permitted correction/reversal. Each row has drilldown to the original record and immutable audit event.
- **Fund Management -> Withheld Tax Utilization**: preserve existing component confirmation/reconciliation UI, linked by source component and fund; do not create a duplicate cash movement.
- **Cash & Bank -> Bank Transactions / Fund Balance Register**: preserve v0.2.351 bank-book logic; actual net bank movement comes from the check ledger, *not* gross DV and *not* a tax-status event.
- **Division monitoring**: read-only across authorized schools, with statuses by school, fund, month, ATC, total tax originated, pending Division receipts, Division remittance/return references, and unmatched amounts. Only authorized school finance actors prepare/finalize transfers; Division appropriately acknowledges receipt/settlement in its separate workflow. Server must enforce tenant and role scopes.

## Three distinct financial amounts
1. **Gross expense/budget utilization:** total allowed finalized DV gross posted against its originating fund, inclusive of the portion withheld. Draft DVs and canceled documents do not become actual expenditure. Outstanding PR/PO obligations are displayed separately, not silently double-counted with finalized DVs.
2. **Net payment/bank-book outflow:** actual supplier/employee or Division *check amount*. Reserve from available fund cash once when its physical check is ISSUED/SIGNED (per existing book conventions); RELEASED/CLEARED must not subtract it again. Distinguish cash-book commitment from verified bank-cleared payment in labels and totals.
3. **Withholding:** tax deducted from the gross, tracked by originating finalized DV, component type, ATC, fund, school, effective policy, and original evidence. A withholding component is neither a new fund download nor automatically a cash transfer, and may not enter two policy lanes.

## Policy-aware withheld-tax disposition
**A. Eligible Non-IU school fund:** Effective `DIVISION_SETTLES_UTILIZABLE` with valid Division finance authority. Recognize original component as `SCHOOL_UTILIZATION`; source remains retained in school bank; after independent authorized confirmation, record only eligible retained amount and subsequent *source-linked* usage against a new DV. Do not automatically create a payable-to-Division check or credit the school's bank again. Division's tax-remittance and general-ledger obligations remain distinct and subject to accounting policy.

**B. School fund requiring remittance to Division:** Original effective policy snapshot `REMIT_TO_DIVISION` (or another explicitly configured approved Division-remittance policy). The school must **create a dedicated DV payable to the Division-designated account/payee** for one or more eligible outstanding withheld-tax components. Preparing the DV reserves the referenced amount; it is **NOT yet an actual transfer of cash**. Finalizing the authorized DV recognizes the proper payable/journal in accordance with reviewed accounting rules; issuing/signing the physical check commits the exact *net payment* to the bank book; bank clearance proves debit. Division acknowledgement/deposit confirmation separately proves receipt. An actual Division BIR remittance requires its own evidence and separate status. Do not mark transferred or BIR-remitted merely because a draft DV exists.

**C. IU remitting directly to BIR:** Separate `IUS_REMIT_DIRECT_BIR` treatment; exclude from school-to-Division tax transfer and retained school-utilization claims. Follow distinct authorized BIR payment/reporting flow.

## Minimum state chain for Division-payable withholding
| Event | Register label | Budget/cash treatment |
| --- | --- | --- |
| Original supplier DV finalized, withholding calculated | Withheld / Awaiting transfer DV | Tax liability/component exists; original supplier check equals gross less withholding; no separate deposit |
| Division tax-transfer DV created as DRAFT | Reserved for transfer | Link exact component IDs and portions; no bank debit, no completed transfer, no check or BIR payment |
| Transfer DV approved/finalized | Authorized for check | Validate fund, school, remaining liability, Division payee/account, correct UACS and authority; keep original expenditure from being counted a second time |
| Physical numbered check issued | Check committed / In transit | One bank-book cash debit/commitment for transfer check; check must be from matching fund |
| Check bank-cleared | Check cleared / Paid to Division account | Confirm debit happened; no second bank-book deduction |
| Division confirms deposit with supporting evidence | Division received | School transfer obligation reconciled to Division receipt; no synthetic second bank movement |
| Division remits to BIR with evidence | BIR remitted (Division) | Distinct tax settlement monitoring state; never infer from school check |

**Exception rule:** Rejected, voided, spoiled, stopped or stale check states demand a controlled audited correction; an issued but uncleared check cannot be reused as free cash. Draft cancellation releases only its own active source reservations; finalized/issued events require approved reversals, not record erasure. Report discrepancies (issued but not cleared, cleared but Division not acknowledged, Division received but no BIR evidence) separately.

## Suggested transaction register columns
- Event date/time (with documented local timestamp and posted date), Fiscal year, School/Division, Fund, Bank account as applicable, Event type / status.
- Source document no. and type (PR/PO/IAR/DV/check), **originating supplier DV**, per-component ID and ATC, **tax-transfer DV**, payer/payee and actual authorized Division payee/account identifier without displaying unnecessary sensitive account details.
- Gross expense (₱), Net check debit/commitment (₱), Withheld amount (₱), Retained confirmed (₱), Retained utilized (₱), Tax transfer reserved (₱), Tax transfer check issued (₱), Division received (₱), Unsettled amount (₱).
- Policy code/version + effective date; actor/role, timestamp, approval/evidence reference, original/reversal links and verification state.
- Filters: organization, school, inclusive date period, fiscal year, fund, type, policy, ATC, status, source/DV/check number; optional current-period/all-months; exports CSV and print with generation time, filter parameters and totals. Period opening/closing balances derive from **full earlier history**, never only visible rows. Distinguish economic transaction date from entry/audit timestamp and late posting. Pagination must preserve full-period totals, fail closed if data truncated.
- Summary should present separate **Gross utilized**, **Net checks committed**, **Net bank cleared**, **Tax withheld**, **Tax retained eligible**, **Tax actually reconciled/used**, **Division transfer pending**, **Division transfer cleared**, **Division acknowledged**. No ambiguous one-number "utilized" that silently sums them.

## Example of non-duplication (illustrative)
A MOOE-linked verified download of **₱50,635** and finalized supplier DV **₱49,000 gross** with **₱2,625 tax withheld** produces a supplier **₱46,375** check and **₱4,260** bank-book fund cash if no other entries. Ordinary gross authorization remaining is **₱1,635** before other obligations. The ₱2,625 withholding is **not** another ₱2,625 cash credit. For eligible utilization, it is independently verified, confirmed, constrained and linked; for required remittance, it remains an obligation until a Division-payable DV/check is lawfully processed. Do **not** switch a historical component's lane when Division policy is later modified.

## Engineering and accounting acceptance gates
- First inspect exact hosted v0.2.351 source and **structure-only** schema; preserve earlier ledger sources and source-hash validation.
- Use DB-enforced unique source-component reservation/settlement keys, amount caps, transaction-level row/fund locks and idempotency for simultaneous DVs and multi-component partial remittances. A component may contribute across approved remittance DVs only up to **remaining** original withholding; prevent the same amount being both retained-used and Division-remitted.
- Separate journal entries from budget utilization and bank movements; authorized accountants determine correct liability accounts, treatment for tax-transfer DV, and non-duplication of original procurement expense. Do not post a second procurement expense from a tax remittance DV.
- Test role/workspace boundaries, one school/fund vs pooled bank account, different effective dated policies, duplicate source/reused tax, partial remittance, void/reissue, cleared and not acknowledged, reopened periods, backdated events, audit immutability, report totals and file-export access.
- Require real Division financial and BIR treatment signoff before enabling actual finalization of tax-transfer DVs. Backups, version gate from v0.2.351, MariaDB isolation and authenticated functional UAT before any installable patch.
