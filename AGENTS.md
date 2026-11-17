# IPDAMS — OpenCode and AI coding agent instructions

## Mandatory development continuity

Before beginning IPDAMS work, read:

- docs/AI_CONTINUITY_PROTOCOL.md
- docs/DECISION_REGISTER.md
- docs/CURRENT_STATE.md

Continue the recorded NEXT ACTION.
Never silently change an accepted project decision.
Verify current source and QA evidence before proposing changes.

## Verified development baseline (2026-11-13, Asia/Manila)

- Work in **this repository's `develop/hostinger-v0.2.375` branch**, or a dedicated `feature/*` branch created from it. The default `main` branch is historical coordination documentation and is not the complete application code.
- Confirmed read-only from Hostinger file `private/storage/private/settings/installed-version.json`: **installed IPDAMS v0.2.375** as of 2026-11-13. The runtime `version.php` reads private installed-version state; that state and production credentials are deliberately NOT committed. Do not misinterpret `private/app/ReleaseVersion.php`'s bootstrap fallback as the deployed version.
- This development baseline combines **210 independently SHA-256-verified first-party source files** from original private draft PR #83, the exact **two deployed React 16.0.0/16.0.1 UMD files and offline assembler** from private draft PR #85, and the corrected test-only Settings smoke regression from private draft PR #84.
- The code capture is a SOURCE SNAPSHOT, **not a deployment-approved release**. No complete, fresh live website re-hash, ownership/privacy clearance, browser UAT or MariaDB financial regression certification has been completed. Do not claim production parity solely from a green static build.

## Repository and code layout

- Entry points: `index.php`, `settings.php`, `setup.php`, `api/*.php`.
- Business logic, permissions and financial controls: `private/app/*.php`.
- Browser app: `app.html`, `assets/app.js`, `assets/*.css`; deployed runtime includes old React 16 UMD libraries. `private/frontend/src/app.tsx` is a **legacy prototype**, NOT the build source of the large deployed `assets/app.js`.
- Offline static check: `python3 private/frontend/test_build_static.py` and `python3 private/frontend/build_static.py`. The first six negative-control tests and the second 102-file/81-reference build passed in the owner's WSL checkout on 2026-11-13. Generated `private/frontend/dist-static/` is ignored and must never replace installed assets without review.
- Offline PHP checks: lint changed files with `php -l`; run `php private/tests/unit.php` and `php private/tests/settings_smoke.php` where PHP is available. The settings smoke fix is present only in this integrated development branch and its dedicated PR #84.
- The PHP integration suite `private/tests/integration.php` **WRITES test financial records** even though it rolls back; run ONLY in isolated disposable MariaDB with `APP_ENV=test`, exclusively synthetic fixtures, NO real production credentials and no connection to Hostinger. Do not run it on any live database, replica with personal/financial records, or any copy containing real records.

## Non-negotiable safety practices

1. The **owner must explicitly approve** any merge to `main`, public release, GitHub Actions workflow, installation, Hostinger modification, SQL schema/data migration, check/DV posting, tax remittance or changes to actual school financial/asset/personnel records.
2. **Never** use actual school records, bank numbers, personal data, payment documents, uploaded evidence, operational DB exports, API keys, tokens, `.env` or other secrets as GitHub commits, chat prompts, tests or external AI contexts. Use synthetic fixtures only.
3. Default to small reviewed PRs, no GitHub Actions billing, no force pushes, no production writes. Verify source and test output before asserting a fix.
4. Preserve organizational isolation and approval gates: roles, scopes, audit trails, duplicate claim prevention, withholding/accounting balance, fund and bank separation, property custody and immutable issued document numbering.
5. Use separate versioned installable packages only after owner confirmation of installed predecessor, independent complete website/database backup, source diff review, isolated regression tests and explicit deployment authorization.
6. React 16 is legacy/unsupported, preserved for exact compatibility. Propose a separate security-reviewed upgrade rather than changing React/app.js automatically.
7. When proposing reimbursement/DV features: reimbursement records the **eligible claim amount only**; actual settlement belongs in the DV workflow, allowing consolidation of multiple approved unpaid claims for the same employee while preventing double allocation.

## Coordination
- GitHub: https://github.com/ronilocanedo/ipdams
- Hostinger: `ipdams.depedtagb.online`; **read only** unless owner separately approves a deployment.
- Development register: https://docs.google.com/document/d/13NWruT1RTeFoxcL14l9-5UY9e2ENuX2RHEveAUGHOF8
- Source review PRs: #83 (210-source capture), #84 (Settings smoke fix), #85 (legacy frontend build). All remain separate draft reviews, not merged into `main`.
- Prefer fast, simple user-facing workflows while retaining strict, audited backend controls.
