# IPDAMS — Current development state / active handoff

**As of:** 2026-11-17, updated after verified continuity-only GitHub push (Asia/Manila)
**Prepared from:** connected GitHub source, read-only Hostinger file listings, Drive QA source/evidence, and owner-shared local PowerShell output.
**Status:** REVIEW / SOURCE RECONCILIATION — **not yet synchronized across all three environments**.

## Start here — no change of strategy just because the chat changes

Follow root `AGENTS.md`, `docs/AI_CONTINUITY_PROTOCOL.md`, `docs/DECISION_REGISTER.md`. Continue the NEXT ACTION below. User wants **fast, narrow fixes and proven incremental updates**, not repeated full QA cycles or new plans every conversation. Owner approval gates remain mandatory.

## Environment state / source of truth

| Location | Verified or reported state | Confidence |
| --- | --- | --- |
| GitHub repository | PRIVATE `ronilocanedo/ipdams`; `main` documentation/history; application branch `develop/hostinger-v0.2.375` contains imported source; continuity documentation is now pushed and independently verified, while application source candidates remain unpushed | Verified by connected GitHub 2026-11-17 |
| Owner's local GitHub Desktop | `C:\Users\rbcan\OneDrive\Documents\GitHub\ipdams`, branch `develop/hostinger-v0.2.375` tracks origin; **13 local review-package files copied**, still uncommitted/unpushed; the 4 continuity-document paths were separately committed and pushed | Owner's PowerShell 2026-11-17 |
| Hostinger production | `ipdams.depedtagb.online`; v0.2.375 verified 2026-11-13. Later v0.2.378 is owner-reported; **today's exact installed version is NOT independently verified** | Mixed historical direct + later owner report |
| Google Drive official | Folder `IPDAMS Development`, approved references and long-lived QA artifacts, `IPDAMS-DV-Local-QA` synthetic test evidence | Connected Drive reviewed |

## Current local 13-file delta (owner executed copy; no commit)

- `app.html` modified: added reference to branding script.
- `assets/property-workspace-navigation.css` modified: appended mobile Property Accountability containment rules.
- `assets/branding-missing-display-377.js` added: branding fallback.
- `docs/HOSTINGER_GITHUB_SYNC_REVIEW_2026-11-17.md` and `docs/QA_BASELINE_2026-11-16.md` added.
- `qa/playwright/reference-only/` contains 4 disabled `.spec.cjs.txt` references, 2 `.cjs.txt` helpers and `README.md`; `qa/tools/check_saved_baseline.py` added.
- PowerShell review script said 13 READY, then `LOCAL COPY COMPLETE` with backups outside repository. `git diff --check` had no whitespace errors; Windows LF/CRLF warnings were informational; Git status showed 2 modified tracked files and untracked additions including `qa/`.
- `?? .idea/` from IDE was ignored **locally only** using `.git/info/exclude`, not deleted or committed.
- The 13-file package was a **QA snapshot candidate**, not byte-proven Hostinger state. It has not been tested in a browser after application, committed, pushed, deployed or installed.

## Read-only production-vs-source reconnaissance

- Hostinger and GitHub branch *file names and sizes* matched in all 21 `api/` files and 73 `private/app/` files inspected. These are **metadata matches, NOT source hashes**.
- GitHub `app.html` 6,270 bytes; QA source/Hostinger listing 6,339 bytes. The QA version adds a 69-byte script reference.
- GitHub `assets/property-workspace-navigation.css` 4,223 bytes; QA source/Hostinger listing 6,039 bytes, with 1,816 appended QA-source CSS bytes.
- New `assets/branding-missing-display-377.js` absent in baseline GitHub, present as 3,139-byte QA source and 3,139-byte Hostinger listing.
- `private/tests/settings_smoke.php` differed in file size (GitHub 2,797; Hostinger 646); investigate before claiming complete parity. It is a **test-only** discrepancy, not evidence of a production runtime failure.
- Other Hostinger assets and top-level files are not yet fully reconciled; some may be deployed images/runtime/private configuration deliberately excluded from GitHub.
- The Hostinger file-content connector returned no usable text for checked files; source bytes and active private installed-version state remain **UNVERIFIED** from today's inspection. Do not say parity is proven.

## QA checkpoint — retain, do not replay indiscriminately

- Saved comprehensive QA result (2026-11-16): **162 PASS, 0 FAIL, 8 SKIPPED** on synthetic isolated data. Test execution was historical, not repeated here.
- Two approved synthetic claims (PHP 125.50 + PHP 234.25) are consolidated into synthetic **DRAFT DV 13** (PHP 359.75). No successful authorized settlement, official DV number, check release or payment.
- Payment guard tests and negative authorization checks passed; full settlement and tax transfer were **NOT TESTED/BLOCKED**.
- Latest funded synthetic positive `dv.finalize` attempt returned **HTTP 422 `Required nonempty accounting (max 100)`**. Do not invent a field or policy; inspect contract read-only first.
- Read-only accounting preflight reported conditional debit/credit allocations but synthetic UACS is NOT policy approved, actual check leaf not proven and bank cash proof is absent.
- Some one-time positive tests already committed synthetic rows; never clear markers or rerun against the same clone.

## NEXT ACTION — continue this, not a brand-new plan

1. **Preserve the 13 uncommitted local source/QA review paths**; the continuity-only documents are already committed/pushed and independently verified. Do not commit the remaining application candidates merely to make GitHub Desktop appear synchronized. They can remain a reversible local review delta with existing backups.
2. **Get a code-only, read-only Hostinger SHA-256 manifest** for the allowlisted application files (no private `.env`, installed-version private metadata, database, uploads, backups or secrets). Compare filenames/hashes to the current GitHub baseline and owner QA candidate; mark extra, missing, changed. Use a verified Hostinger read-only mechanism or owner-assisted hPanel/SSH export; never use production for experiments.
3. Resolve `app.html`, branding script, mobile CSS, `settings_smoke.php`, and any other actual hash deltas. If Hostinger source cannot be read, preserve the explicit blocker rather than substituting equal byte lengths as evidence.
4. Prepare a reviewed, versioned source update in local VS Code. Apply focused isolated, synthetic regression checks for affected behavior. A release-grade finance test is needed before future payment implementation, not for a docs-only checkpoint.
5. **Continuity documents were pushed and verified on private GitHub on 2026-11-17.** After byte-level Hostinger reconciliation, targeted QA and separate owner approval, commit/push the *remaining reviewed application source*; verify remote commit SHA and reconcile GitHub Desktop. Do not infer production parity from the documentation push.
6. Continue the reimbursement/DV accounting contract work: inspect `dv.finalize` request shape read-only and identify policy decisions before implementing any positive DV settlement.

## Hard blocks and non-actions

- **Two owner-made continuity documentation commits were pushed and their four resulting files verified on private GitHub.** No *application-source* commit/push, Hostinger deployment, SQL migration, production DB tests, new payment/check, tax posting or backup mutation was performed in this continuity effort.
- Do not auto-run old `.spec.cjs.txt` tests. React 16 legacy/runtime remains as captured; security upgrade is a separate proposal.
- Do not treat 2026-10-26 or 2026-11-11 historical notes as the present installed release.
- Need current Hostinger source hash evidence, verified production version and accounting-policy signoff to claim parity/release readiness.

## At end of each work session

Update this file with date/time, verified production & GitHub/local state, source hashes, actions actually executed, QA PASS/FAIL/SKIPPED/BLOCKED/NOT TESTED, explicit owner approvals, unresolved issues, and exactly one next milestone. Avoid rephrasing old unverified claims as current facts. Keep Git history for every updated checkpoint.

## Verified continuity publication — 2026-11-17 (PHT)

- Owner committed and pushed two documentation-only checkpoints on `develop/hostinger-v0.2.375`. Independently read back `AGENTS.md`, `docs/AI_CONTINUITY_PROTOCOL.md`, `docs/CURRENT_STATE.md`, `docs/DECISION_REGISTER.md` from remote GitHub.
- Application source `app.html` and `assets/property-workspace-navigation.css` still matched their pre-sync remote blob SHAs (`fb509e263e1bcd209c1a9eeadb37e6fb1608d229`, `23cbd5280a6a0982edb0ab03c1e95aeedd1c54ca`). `assets/branding-missing-display-377.js` remained absent remotely. The source/QA synchronization candidates remain local-only.
- Version and byte-level Hostinger parity remain unverified. The **NEXT ACTION remains a read-only code-only Hostinger SHA-256 manifest**, followed by selective source reconciliation and synthetic targeted QA. This status update neither authorizes nor performs a production deployment.
