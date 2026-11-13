# IPDAMS — Hostinger v0.2.375 source-to-development baseline
Date: 2026-11-13 (Asia/Manila)

## Authority and current version
- **Read-only Hostinger verification:** file `private/storage/private/settings/installed-version.json` reports the currently installed application **0.2.375** on 2026-11-13.
- **This GitHub branch:** `develop/hostinger-v0.2.375` provides an integrated source workspace for local OpenCode/other coding agents. It is not GitHub `main` and it is NOT a deployable release.
- **Original Hostinger source capture:** 210 first-party files from private draft PR #83; independently verified all 210 SHA-256 hashes against the recorded Hostinger capture manifest. Owner-uploaded code-only ZIP SHA-256 `f06202a2342d9d48716d6313dc038acfa295ea709c96d5fe9bbd46a9548a8da4`; original on-owner TAR SHA-256 `42e5b85cd67e61f7bd71257a55cdb7320aa95faf97a6e1328fb85e133fe4f312`.
- **Frontend:** The exact original deployed third-party UMD files for React 16.0.0 and ReactDOM 16.0.1, plus deterministic static source assembler from draft PR #85. These are **legacy / unsupported**, not a recommended React version. Owner's actual WSL checkout passed 6/6 offline tests and generated 102 assets with 81 local HTML references.
- **Settings regression:** includes the corrected test-only smoke coverage from draft PR #84, reflecting critical-file release acknowledgement semantics. Application updater permissions were not altered by the test fix.

## Why the former OpenCode readback said v0.2.368
GitHub's default `main` branch was a **release-coordination/documentation branch**, not a copy of Hostinger application code. Its README had a historical Nov 12 2026 v0.2.368 checkpoint. OpenCode using `main` saw that stale documentation. We have now marked the old checkpoint as historical and introduced this canonical source-containing development branch.

The runtime `version.php` reads private Hostinger installation state, which must never be committed. Its fallback bootstrap version is not the deployed version. Do not hard-code the runtime to 0.2.375 merely to satisfy a coding agent. Use this document and the read-only production check as version evidence.

## Local OpenCode checkout
```bash
git clone --single-branch --branch develop/hostinger-v0.2.375 \
  https://github.com/ronilocanedo/ipdams.git ipdams-opencode-v0.2.375
cd ipdams-opencode-v0.2.375
git branch --show-current
```
Open this exact local folder as the OpenCode project/workspace. OpenCode recognizes root `AGENTS.md` instructions; any external LLM model provider changes model access, **not** the repository code or branch automatically.

## Quality and safety limitations
- This is a validated **historical source capture on the installed 0.2.375 version**. Hostinger's installed version was freshly checked, but no all-file **live, post-capture** hash comparison was performed in this checkpoint. Verify live byte-level changes before any future deployment.
- The existing PHP integration suite creates financial/stock transaction test rows and MUST NOT be executed against production, real-data backups or any database with private records. Only disposable, isolated synthetic MariaDB with `APP_ENV=test`.
- No isolated DB finance/procurement/property/travel integration UAT, browser behavioral acceptance, comprehensive privacy/security/licensing signoff or modern React migration has been completed.
- Nothing here authorizes GitHub `main` merge, GitHub Actions, a Hostinger deployment, schema migration, DV/check posting or production data edits. Draft PRs #83, #84 and #85 remain **draft**.

## Next work
Start new feature branches from `develop/hostinger-v0.2.375` and add feature-specific synthetic regression tests before making reviewed patches. First candidate: employee travel reimbursement approved claim amounts consolidated into a single DV for that employee, with strict duplicate allocation prevention and actual settlement only through DV.
