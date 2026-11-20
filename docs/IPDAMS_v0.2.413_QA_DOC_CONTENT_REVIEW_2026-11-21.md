# IPDAMS v0.2.413 — QA/Documentation Reconciliation Review

**Review date:** 2026-11-21 (Asia/Manila)  
**Original archive:** `IPDAMS_QA_DOC_CONTENT_FOR_REVIEW_20261121.zip`  
**Repository:** `ronilocanedo/ipdams`  
**GitHub base commit:** `207a113ad18b1e3754b6107f3fff67e7209175fe` (`develop/hostinger-v0.2.375`)  
**GitHub PR #86 head reviewed:** `2e87f48e518c8499658054b0833ee56c3e1cd8a6` (draft review branch)  
**Purpose:** Preservation and review only; no permission to stage, commit, push, merge, install, migrate, or deploy.

## Integrity and scope

- ZIP has 28 entries: 26 QA/documentation files, one inventory JSON, one sharing note. No directories, duplicate names, encrypted entries or unsafe traversal names.
- All **26/26** files exactly match supplied SHA-256 and length manifest. This verifies package consistency, not provenance from Hostinger.
- Complete Git tree checks (not truncated) show **0 of 26** in the development base and **0 of 26** in PR #86 head.
- GitHub metadata currently reports **public visibility** (`private=false`). This contradicts some project documents that call it private. Confirm intention before any commit.
- Heuristic sensitive-material scan did not find literal private keys, recognizable API keys, or SQL dumps. Environment variable references and fictional QA account names exist; heuristics are not exhaustive.
- Static syntax tests: 10 JavaScript files including historical `.txt` references, one PHP file, one YAML file, two JSON files (manifest plus package JSON where applicable), one Python file: **0 syntax errors**.
- No Docker, Chromium, PHP functional checks, financial transactions, Hostinger data reads, production SQL access, Google Drive writes, or Git operations were performed.

## File-by-file disposition

| # | Source path | Recommended handling | Reason / guard |
|---:|---|---|---|
| 1 | `docs/HOSTINGER_GITHUB_SYNC_REVIEW_2026-11-17.md` | KEEP — historical reference | Describes earlier v0.2.378 source candidate; not a v0.2.413 production parity certificate. |
| 2 | `docs/QA_BASELINE_2026-11-16.md` | KEEP — evidence register | 162/0/8 is a historical reported summary; raw results are not in this ZIP. |
| 3 | `qa/local/apache-ipdams-qa.conf` | KEEP | Local-only web hardening; not a production Apache config. |
| 4 | `qa/local/check_schema.php` | PATCH BEFORE COMMIT | Add DB_HOST=db guard; limited schema columns, not financial-schema certification. |
| 5 | `qa/local/compose.yaml` | PATCH BEFORE COMMIT | Change qa_isolated network internal:false to internal:true; preserve localhost port and no DB published port. |
| 6 | `qa/local/Dockerfile` | KEEP | QA image built from allowlisted application paths; Docker build not executed. |
| 7 | `qa/local/Dockerfile.dockerignore` | KEEP | Dockerfile-specific deny-by-default build context; requires operational review. |
| 8 | `qa/local/Dockerfile.playwright` | KEEP — QA only | Browser image uses pinned direct version, but npm transitive lockfile absent; no install performed. |
| 9 | `qa/local/Dockerfile.playwright.dockerignore` | KEEP | Playwright test image copy exclusions. |
| 10 | `qa/local/init.ps1` | KEEP | Generates only synthetic local credentials; never commit .env.qa. |
| 11 | `qa/local/README.md` | PATCH BEFORE COMMIT | Clarify default smoke-only run and separately gated PR test. |
| 12 | `qa/local/start.ps1` | KEEP — unexecuted | Starts only local Docker QA when owner chooses to run; not run in review. |
| 13 | `qa/playwright/.gitignore` | KEEP | Excludes generated reports and node_modules; root .gitignore excludes .env.*. |
| 14 | `qa/playwright/package.json` | KEEP | Pins @playwright/test version 1.64.1; not yet installed or run. |
| 15 | `qa/playwright/playwright.config.cjs` | PATCH BEFORE COMMIT | Default testMatch should include only anonymous-smoke.spec.cjs. |
| 16 | `qa/playwright/pr-uacs-v0380.config.cjs` | KEEP — gated | Separate test runner requires explicit isolated synthetic fixture flag. |
| 17 | `qa/playwright/reference-only/04-reimbursement-recovery-readonly.spec.cjs.txt` | ARCHIVE DISABLED | Historical read-only DV 13/claims 128,131 reconciliation; synthetic login required. |
| 18 | `qa/playwright/reference-only/05-reimbursement-payment-guards.spec.cjs.txt` | ARCHIVE DISABLED | Contains negative financial POST calls; must not be activated without isolated fixtures. |
| 19 | `qa/playwright/reference-only/14-qa-sidebar-sweep.spec.cjs.txt` | ARCHIVE DISABLED | Historical browser navigation sweep; read-only browser after synthetic login. |
| 20 | `qa/playwright/reference-only/18-qa-all-finalized-dv-print.spec.cjs.txt` | ARCHIVE DISABLED | Requires previously saved read-only audit result and matching test roles. |
| 21 | `qa/playwright/reference-only/README.md` | KEEP | Documents intentional nonexecution of reference .cjs.txt files. |
| 22 | `qa/playwright/reference-only/_qa-support.cjs.txt` | ARCHIVE DISABLED | Historical 8766 helper; keep .txt to prevent accidental execution. |
| 23 | `qa/playwright/reference-only/_sim-support.cjs.txt` | ARCHIVE DISABLED | Historical 8767 helper; synthetic authentication and POST helper, keep disabled. |
| 24 | `qa/playwright/tests/anonymous-smoke.spec.cjs` | KEEP — smoke candidate | Anonymous API, browser white-screen/JS/network/overflow checks only. |
| 25 | `qa/playwright/tests/pr-uacs-v0380.spec.cjs` | KEEP — exclude default | Requires QA v0380 synthetic accounts, fixture and flag; do not run in initial smoke. |
| 26 | `qa/tools/check_saved_baseline.py` | KEEP — read-only utility | Checks three external saved QA JSON reports; those reports are not included. |

## Concrete defects to fix before staging

1. **Docker isolation:** `qa/local/compose.yaml` declares `qa_isolated.internal: false` even though the README claims an internal-only network. Review copy changes this to `true`; test whether published loopback port is reachable after owner-approved local startup.
2. **Default smoke-suite selection:** `qa/playwright/playwright.config.cjs` discovers both active specs. `pr-uacs-v0380.spec.cjs` always requires `IPDAMS_QA_V0380_ISOLATED=1`, `PLAYWRIGHT_BASE_URL=http://web`, a synthetic password and seeded fictional accounts, which are absent from base Compose. Review copy adds `testMatch` for `anonymous-smoke.spec.cjs` only.
3. **Schema guard:** `qa/local/check_schema.php` checks CLI, APP_ENV and DB_NAME but not DB_HOST. Review copy adds `DB_HOST=db` to avoid directing this tool toward configured remote hosts. This is a limited safeguard, not proof of connection authenticity.
4. **QA secrets:** The repository root `.gitignore` already ignores `.env.*`; review copy adds explicit `qa/local/.gitignore` protection for `.env.qa` and `.env.qa.*` as defense in depth.
5. **Documentation:** `qa/local/README.md` should explicitly distinguish anonymous smoke tests from the gated PR/UACS suite. The review copy does this.
6. **Reproducibility:** The Playwright Dockerfile uses pinned direct `@playwright/test` but installs without a committed `package-lock.json`. Consider a lockfile and `npm ci` in a later review before CI; do not auto-enable Actions.

## Retained financial test evidence — reported historical, not rerun here

- `docs/QA_BASELINE_2026-11-16.md` reports **162 PASS / 0 FAIL / 8 SKIPPED** from a previous synthetic fixture set, with explicit limitations. The machine-produced reports cited in that document are **not included** in this 26-file ZIP.
- Reports mention two fictional reimbursement claims 128 (PHP 125.50) and 131 (PHP 234.25), in unnumbered, unpaid DRAFT DV 13 (PHP 359.75). The report states successful financial finalization and check release are unproven.
- `dv.finalize` was historically **BLOCKED** in synthetic QA at different validation stages. Do not convert these blocked attempts into a pass or rerun positive transaction tests without a fresh isolated clone.
- The synthetic debit UACS `5029999099` and conditional Non-IUS MOOE credit `1990101000` are **not** by themselves approved DepEd accounting classifications.

## GitHub and Hostinger release gate

- [ ] Confirm if a **public GitHub repository** is intentional; audit all existing commits for secrets before publication of new QA materials.
- [ ] Review the proposed four file edits and the new local QA secret-ignore file.
- [ ] Obtain owner approval to stage and commit to an approved review branch; do not merge PR #86 automatically.
- [ ] Independently hash actual Hostinger source files against the v0.2.413 checkpoint; matching filename and byte length is not hash equality.
- [ ] After owner-approved local installation, run **anonymous Chromium desktop/mobile smoke only** on synthetic Docker, record actual PASS/FAIL/SKIPPED.
- [ ] Review the latest application schema and synthetic fixtures before authenticated PR, DV, property, or reimbursement workflows.
- [ ] Keep financial actions and production deployment blocked until formal policy and owner signoff.

## Proposed review package

`IPDAMS_0413_QA_DOC_REVIEW_ONLY_20261121.zip` contains the 26 preserved files, 4 limited safety edits, explicit `qa/local/.gitignore`, and this review record under `docs/IPDAMS_v0.2.413_QA_DOC_CONTENT_REVIEW_2026-11-21.md` (28 repository-target files total). The original submitted ZIP remains unchanged.

**Do not treat this review ZIP as a Hostinger installer or a Git commit runner.**
