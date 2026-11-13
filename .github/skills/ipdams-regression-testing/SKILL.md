---
name: ipdams-regression-testing
description: Plan and run focused tests and cross-module regressions for IPDAMS enhancements, especially procurement, DV, travel and property workflows.
---

# IPDAMS Regression Testing

Never treat passing syntax checks as evidence that the hosted application works.

## Minimum verification by risk
- PHP change: `php -l` for all touched PHP files; focused PHP unit tests using synthetic fixtures.
- JS change: syntax/transpilation check compatible with actual React 16 environment; render/interact test on the actual changed component when possible.
- SQL/migration change: disposable MariaDB, upgrade on predecessor schema, repeat/partial-failure behavior, scope, data conservation, indexes and recovery. A mocked DB test is not a substitute.
- UI/auth change: authenticated cross-role and cross-school click-through or Playwright against a disposable environment. Check browser console and blank screens.
- Financial write change: concurrent request tests, double posting, funds/check uniqueness, out-of-order transitions and reconciliation.
- Property/inventory change: movement, received condition, ICS/PAR and chain-of-custody regressions.

## Golden workflow matrix
| Flow | Happy path | Negative / regression |
| --- | --- | --- |
| Procurement | PR → RFQ → quotation → award → PO → IAR | quantity, stage dates, duplicate quote, partial fulfillment |
| Payments | IAR/approved source → DV → review → finalize → check | single charge, stale draft, no duplicate DV/check |
| Travel | TA → itinerary/receipts → expense review → reimbursement claim → consolidated draft DV | evidence checks, same employee/fund, double inclusion |
| Property | Register → assign ICS/PAR → transfer → receive → inspect/return | organization scope, condition, audit history |
| Funds | download → allocation → gross expense → withholding → check/transfer | no double deduction, bank/fund-specific availability |
| Access | create role → assign school/Division → revoke | APIs, print, export and attachment deny after revocation |

## Execution protocol
1. Identify exactly which workflow nodes and shared services are changed.
2. Run smallest relevant tests first, then adjacent regressions.
3. Record commands, fixtures, test environment and results; do not say all tests passed if some were skipped.
4. Create a concise UAT script for the owner showing exact steps and expected results.
5. Never run tests or data fixtures against production SQL.

See `docs/TEST_MATRIX.md` for the growing project-wide checklist.
