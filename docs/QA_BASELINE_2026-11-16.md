# IPDAMS Local QA — Evidence Baseline and Regression Register

**Review date:** 2026-11-16 (Asia/Manila)  
**Source:** Google Drive → IPDAMS Development → IPDAMS-DV-Local-QA → qa  
**Folder:** https://drive.google.com/drive/folders/19HnbPKuI-4ljcWErxXfCLazBUoSO3Afq  
**Scope:** Read-only review of source QA manifests, key machine-generated summaries, supporting README instructions, and representative Playwright tests. The reviewer did **not** execute the test suite, independently verify credentials or bank records, or audit every binary attachment/screenshot.

## 1. Baseline result to retain

Source: `qa/comprehensive/results/overall-summary.json` (generated UTC 2026-11-16T07:16:21Z)

| Suite | PASS | FAIL | SKIPPED |
|---|---:|---:|---:|
| Restored Chromium browser on isolated 8766 | 40 | 0 | 0 |
| Disposable Chromium browser on isolated 8767 | 40 | 0 | 4 |
| Original financial historical audit | 9 | 0 | 2 |
| Disposable financial historical audit | 9 | 0 | 2 |
| Original pure financial rules | 32 | 0 | 0 |
| Disposable pure financial rules | 32 | 0 | 0 |
| **Total** | **162** | **0** | **8** |

Saved assessment: `PASS_WITH_DOCUMENTED_LIMITATIONS`. Original/disposable financial fingerprints unchanged. This applies to the reported synthetic environment and source snapshot, **not** production and **not** a guarantee on modified code. No full payment, tax transfer, or reimbursement settlement was completed.

## 2. Reimbursement evidence retained

| Evidence | Status | Specifics |
|---|---|---|
| `qa/sim/positive-reimbursement-recovery.json` | PASS | Disposable 8767; claims 128 (PHP 125.50) and 131 (PHP 234.25), DV 13 `DRAFT`, gross PHP 359.75; no official DV number, check or payment. Existing positive create returned HTTP **201**; an earlier script incorrectly expected 200. Read-only recovery passed desktop/mobile. |
| `qa/sim/reimbursement-payment-guards.json` | PASS | 14 rejection-guard Chromium tests on 8767: claimed-source reservation, financial attestation, authority reference, duplicate DV, check-before-finalization, cross-role restrictions and UI rendering. Financial/audit fingerprints unchanged. No successful payment. |
| `qa/settlement-qa-20261116/finalization-test-summary.json` | BLOCKED | Clone 8769; `dv.finalize` returned HTTP 422 `Invalid bank_account_id`; draft unchanged. |
| `qa/bankfix-finalization-qa-20261116/bankfix-finalization-summary.json` | BLOCKED | Clone 8770 with fixed QA request; `dv.finalize` returned HTTP 409 requiring actual bank cash verification, charges, outstanding commitments and reserve. Draft unchanged. |
| `qa/comprehensive/results/settlement-readiness.json` | NO FINALIZATION AUTHORITY | 8770; existing PHP 50,635.00 MOOE credit covered by 3 recorded issued checks of the same sum; external bank statement and other real cash evidence not provided. |
| `qa/funded-settlement-qa-20261116/funded-e2e-summary.json` | BLOCKED | Clone 8771; QA-only PHP 1,000.00 fictional MOOE credit and model PHP 725.00 headroom; `dv.finalize` HTTP 422 `Required nonempty accounting (max 100)`; DV 13 still draft with zero checks, accounting versions or released events; 4 read-only browser checks passed. |
| `qa/funded-settlement-qa-20261116/reimbursement-accounting-readiness-8771.json` | EVIDENCE RECONCILED; ACCOUNTING POLICY REVIEW REQUIRED | Two locked debit allocations: ID 20 claim 128 PHP 125.50; ID 21 claim 131 PHP 234.25. Both synthetic UACS `5029999099`; conditional Non-IUS MOOE credit `1990101000` = PHP 359.75, active in QA. Not an approved DepEd/COA classification; no policy signoff, verified physical check leaf or independent Non-IUS policy classification. Read-only; no finalization API call. |
| `qa/e2e-qa-20261116/e2e-readonly-diagnostic-v3.json` | INCOMPLETE | Port 8768; PHP diagnostic exit 1, probe JSON invalid; concurrency HTTP response was not observed (`NOT_OBSERVED_DISPOSED`). Do not mark concurrency PASS. |

## 3. What the tests really cover

- **Restored Chromium tests:** anonymous/authenticated sessions, School/Division role boundaries, 13 sidebar sections, deep-link restore, DV Appendix-32 print, tax transfer screen, finalized and cancelled DV print policies, desktop/mobile stability.
- **Disposable tests:** negative authorization, CSRF/idempotency and duplicate selection guard paths; successful single two-source draft recovery; 14 payment-rejection tests; financial source fingerprint preservation.
- **Financial unit/historical checks:** 64 pure-rule assertions and 18 executed financial historical assertions; 4 audit checks skipped because source fixtures did not support those cases.
- **Not proven:** successful reimbursement DV finalization, balanced posted journal, authorized physical check assignment/release, actual payment, bank reconciliation, withholding liability settlement/Division receipt, concurrency in committed financial operations, approved UACS classification, production functionality.

## 4. Important limitations and non-repeat rules

1. A **previous PASS remains evidence for its source hash and QA environment only**. After code changes, run a small affected test subset, then the complete regression suite at formal milestone/release gating. Do not assume a past pass validates a new release.
2. Several positive tests have one-time `.started.json` markers and may have **already committed synthetic writes**. Never remove their markers, rerun positive transaction creation, reset Docker volumes, or reuse a settled clone. Use a fresh isolated clone for new controlled positive tests.
3. Preserve financial/audit database fingerprints before and after any test and treat a discrepancy as FAIL.
4. The PHP 1,000.00 MOOE credit and PHP 725.00 modeled headroom are fictional QA fixtures, **not** a real fund release or bank confirmation.
5. Record PASS, FAIL, SKIPPED, BLOCKED and NOT TESTED separately; do not convert generated syntax checks into executed browser or financial tests.
6. Do not use live Hostinger/production DBs for experimental tests, real payments, bank postings, or check issuance.

## 5. Repository/Drive classification guidance

| Artifact class | Home and treatment |
|---|---|
| Reviewed Playwright `.spec.cjs`, configs and reusable helper scripts | GitHub under `qa/playwright/` after secret scanning, code review, and explicit owner approval for commit/push. |
| PHP rule tests and fixture validators | GitHub in appropriate `qa/` or `private/tests/` path after review and explicit owner approval. |
| Short sanitized PASS/FAIL/blocked machine summaries, screenshots with verified synthetic-only contents, UAT signoff | Google Drive → IPDAMS Development → Test Results / Verified Milestones; optionally small sanitized summaries in GitHub. |
| Full HTML reports, runtime artifacts, raw fixture logs | Keep as historical QA evidence in Drive; do not automatically commit generated bulk artifacts. |
| `.env*`, credentials, `playwright-qa-secrets.clixml`, secrets, bank statements, live SQL backups, user data | Never put in GitHub. Store restricted and review Drive access. Do not print or share secrets. |
| `node_modules`, containers, generated caches | Exclude from GitHub; regenerate using pinned package manifests. |
| Historic installers/source snapshots | Historical release archive in Drive, never assume they represent latest production source. |

## 6. Next development milestone: narrow accounting-finalization contract

- First reconcile the exact **current Hostinger installed source** with the GitHub `develop/hostinger-v0.2.375` snapshot. The later reported production v0.2.378 is not independently authenticated by the current QA evidence.
- Inspect `dv.finalize`'s `accounting` request contract and source-locked reimbursement lines. Avoid submitting a guessed entry or treating test UACS `5029999099` as policy-approved.
- Implement a read-only itemized debit + conditional credit **preview** if appropriate; review that it does not post funds, issue numbers or alter claims.
- Run targeted isolated tests for reconciliation, invalid allocations, duplicate selection, wrong fund/role, accounting imbalance, and no mutation on rejection; keep existing historic QA assets intact.
- Only after accounting classification and physical check-leaf/bank policy approval should a fresh synthetic clone attempt controlled positive finalization. Final check release/payment remains separate.

**No file moves, source commits, GitHub pushes, Hostinger deployments, production migrations, or financial actions were performed in preparing this register.**
