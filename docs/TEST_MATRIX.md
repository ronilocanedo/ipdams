# IPDAMS progressive quality and regression matrix

**Status:** planning checklist, not proof of executed tests. Run on safe local code/synthetic data once source reconciliation succeeds. Never use the hosted production DB as a test target.

| Area | Positive / functional checks | Critical negative checks | Testing tier |
| --- | --- | --- | --- |
| Access | school user permitted within own assignment; Division role where authorized | revoked Special Function, unrelated school, stale session, ID enumeration | PHP unit + API auth/UAT |
| PR | create/edit draft, searchable catalog, unit selection, auto amount | bad qty, wrong org, invalid dates | unit + UI |
| RFQ / quote | RFQ issue, dated quotation, open/quoted/ordered status | duplicate/late quote, awarded or PO-closed, cross-PR qty | integration + UI |
| Award / PO / IAR | winning bidder, signed forms, complete/partial receipt | early form dates, duplicate receipt, excess quantity | integration |
| DV / bank | reviewed source → draft → finalize → check preview/issue | second DV, duplicate check, insufficient bank/fund, wrong supplier | MariaDB + auth/UI |
| Travel / claims | itinerary/evidence → review → approved claim → consolidated same-employee/fund draft DV | duplicate active link, unsupported amounts, unauthorized reviewer | integration |
| Funds / tax | downloaded funds, gross expense, net check, withheld amount, fund statement | double subtraction, false tax-utilized status, liability transfer as expense | finance unit + MariaDB |
| Inventory / property | issue ICS/PAR, transfer, receipt, condition correction, history | cross-organization edit, unusable condition, double assignment | integration |
| Textbooks | catalog, CO receipt, custody and transfer | duplicate receipt, wrong recipient, insufficient available quantity | unit + integration |
| Print/export | accurate forms, headers, signatories and authorized download | print data leak, wrong school branding/signatory, missing audit | auth/UI |
| Updater | exact predecessor install in disposable environment | mismatched version, interrupted migration, partial DDL, unsafe reset | isolated MariaDB |

## Tool introduction order
1. Run already available PHP syntax and JS syntax checks locally with pinned PHP/runtime versions; test new pure functions without adding paid services.
2. After secure source import, identify dependencies and decide if PHPStan/PHPUnit can be introduced incrementally, with explicit baselines and no wholesale suppression.
3. Add Playwright smoke checks only against disposable/synthetic authenticated environments, for white screens, Save/Cancel actions, sidebars, month/year filters and responsive layout.
4. Introduce local secret scan (for example Gitleaks) against all candidate source files before commit; investigate flags manually.
5. Only consider manual `workflow_dispatch` GitHub Actions with synthetic MariaDB after explicit owner approval and spending-limit review. No `push`, `pull_request`, `schedule` or deploy credentials in Actions.

## Evidence standard
For each test note: application version; commit; environment; fixture set; command/steps; expected/actual results; pass/fail/skipped; reason; UAT operator. A file or test plan is **not evidence of passing**.
