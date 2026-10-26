# IPDAMS — GitHub ↔ Google Drive synchronization register


**CURRENT CHECKPOINT — 26 OCT 2026:** v0.2.215 owner-installed, selected disposable test-transaction Custom Reset reported successful. The next proposed v0.2.216 is **staging review only**. The chronological sections below include superseded v0.2.213/v0.2.214 events and must not be used as current installation or release advice. No live automatic sync is enabled.

**Established:** 2026-10-26 (Asia/Manila)  
**Sync status:** Manually reconciled coordination links; **not** continuous, automatic or bidirectional file mirroring.


## Latest verified coordinator checkpoint — v0.2.215 → v0.2.216 (26 October 2026)

- Hosted UI reported **Custom Reset succeeded**; Division, Schools, Users and access scopes preserved. No second reset. Actual post-reset master counts and transaction clearing have not been independently reconciled.
- Installed hotfixes: v0.2.214 physical checkbook reset safety; v0.2.215 selective fund-ledger reset safety. Old *property* ZIPs named v0.2.214 and v0.2.215 are **obsolete**.
- Next candidate: `IPDAMS_v0.2.216_PROPERTY_BATCH_REGISTRATION_FROM_v0.2.215_STAGING_REVIEW.zip`, [Drive file](https://drive.google.com/file/d/1A3RIuJm1uGjFkno-ve85PMTfZQcS9I6g/view), SHA256 `aecb5727cbc271e6c18861ca475756f15f2bfe0e85bce3f78f70d491e979b265`; [guide](https://drive.google.com/file/d/1XWcYV5BcjOOdebvp3dw0lX8L5hA4K4fL/view). Strict predecessor v0.2.215; 6 app files, 2 additive asset migrations; ResetService not modified.
- [Draft PR #3](https://github.com/ronilocanedo/ipdams/pull/3) tracks this review candidate. Synthetic MariaDB 10.11 [manual Run #2](https://github.com/ronilocanedo/ipdams/actions/runs/40265443265) passed; no rerun requested. Authenticated IAR/ICS/PAR/school UAT and true deployed migration+recovery remain unverified.
- **One needed source:** actual hosted `app/SettingsService.php` only. No verified copy found in GitHub/Drive accessible source; do not guess parser behavior from v0.2.141.
- Full source baseline for current installation not in GitHub. Preserve owner backups, secrets and school isolation. Single-site policy; no staging-site duplication without owner request, no production install/migration until safety gates.
- [Portable handoff](https://github.com/ronilocanedo/ipdams/blob/main/docs/HANDOFF_2026-10-26_v0.2.215_to_v0.2.216.md); [Drive master progress](https://docs.google.com/document/d/1AB5SmgnGI3j6Y02q1alVdR7p5ty-sS4HFFBobRkV2lM/edit); [Drive sync register](https://docs.google.com/document/d/13NWruT1RTeFoxcL14l9-5UY9e2ENuX2RHEveAUGHOF8/edit).

## Canonical locations

| Type | Authority | Reference |
| --- | --- | --- |
| Source code, tests, code reviews, branches | GitHub (private) | https://github.com/ronilocanedo/ipdams |
| Project working folders, review ZIPs, guides | Google Drive | https://drive.google.com/drive/folders/1gwRRZYNdsbEhtINpNybAG1JF54WFuyGE |
| Canonical development progress | Google Docs | https://docs.google.com/document/d/1AB5SmgnGI3j6Y02q1alVdR7p5ty-sS4HFFBobRkV2lM/edit |
| Sync register and changes | Google Docs | https://docs.google.com/document/d/13NWruT1RTeFoxcL14l9-5UY9e2ENuX2RHEveAUGHOF8/edit |
| Role/security design | Google Docs | https://docs.google.com/document/d/19kZEhOmTeZrRtDYd4NCtXJybYP_1uoTzM1LKM6Z1ZyA/edit |
| Authoritative installed version | Owner-confirmed hosted IPDAMS | **v0.2.215**; selected Custom Reset reported successful; read-only reconciliation pending |
| Full code-only source ZIP currently in Drive working-source folder | Drive historical snapshot | v0.2.141 (outdated; do NOT deploy as v0.2.213) |

## Historical release-candidate artifacts (superseded, for provenance only)

| Artifact | State | SHA256 |
| --- | --- | --- |
| `IPDAMS_v0.2.213_AUTO_PROPERTY_NUMBERS_FROM_v0.2.212_STAGING_REVIEW.zip` | Owner-confirmed installed update | `3a701dc7ef05e94bc744db46b935a8414044dfa0e0f3a417fddb85957ec1c824` |
| `IPDAMS_v0.2.214_PROPERTY_BATCH_AND_EDIT_SOURCE_REVIEW_DO_NOT_INSTALL.zip` | Source review only; **not installable** | `2f7d9323c7799c706e5a8224548d2c5eb2c34651bd4a912f1466ea6de001d4fb` |
| `IPDAMS_v0.2.214_DISPOSABLE_MARIADB_CI_GATE_DO_NOT_INSTALL.zip` | Manual test kit only; **not installable** | `bd0b3b71a52c218fe6593888ca13eee9d5d4c580f1e0f7177ab206cd1d5528bc` |

These local package hashes were checked against the available source archives. This is **not** proof that they have been uploaded to the Drive release folders or committed to GitHub.

## Reconciliation rule for each future approved milestone

1. Verify current installed version, predecessor and SHA256 of the exact artifact.
2. Reconstruct/review **sanitized code-only** baseline before committing to GitHub. Never commit live records, secrets, database dumps, signed documents, financial details, backups or production logs.
3. Record code commit, branch, release artifact SHA, Drive evidence link and testing outcome here and in the [Drive register](https://docs.google.com/document/d/13NWruT1RTeFoxcL14l9-5UY9e2ENuX2RHEveAUGHOF8/edit).
4. Mark a checkpoint reconciled only after **both connectors read back** the matching metadata. A GitHub commit does not mean production installation.
5. Release artifacts and documentation remain on Drive; GitHub is authoritative for reviewed code only.
6. In case of mismatch, **stop the sync and resolve explicitly** rather than overwrite whichever file is newer.
7. Do not deploy, run database migrations, trigger GitHub Actions or run recurring jobs as part of this synchronization.

## Outstanding work

- Import the exact complete v0.2.213 sanitized source baseline; the available Drive source snapshot is v0.2.141.
- Place the v0.2.214 candidate code on `feature/v0.2.214-property-batch-review` after source review; never on stable `main` before acceptance.
- Run real isolated MariaDB and authenticated multi-school UAT before any installable v0.2.214.
- Verify cost controls separately in GitHub Actions/Billing settings. No Actions workflow is present in this repository at this checkpoint.

## GitHub development tracking (verified 26 October 2026)

- [Issue #1 — restore verified v0.2.213 code-only baseline](https://github.com/ronilocanedo/ipdams/issues/1): source import blocked until exact current snapshot is verified and sanitized.
- [Issue #2 — validate v0.2.214 on isolated MariaDB](https://github.com/ronilocanedo/ipdams/issues/2): real DB concurrency, rollback and authenticated UAT outstanding.
- [Draft PR #3 — v0.2.214 schema proposals, review only](https://github.com/ronilocanedo/ipdams/pull/3): cannot be treated as release or merged before approval.

No automated source/ZIP replication, Actions workflow or recurring monitor has been configured. At the next approved milestone, update this register and the linked Drive register together using release-version evidence and exact hashes.

## 26 October 2026 — first real MariaDB test workflow ready (NOT RUN)

- Reviewed source-method+synthetic-schema MariaDB test ZIP committed to `feature/v0.2.214-property-batch-review`, git commit `555331452888c346b5501333093b6439c959e84e`, blob SHA `4f78add8a2ad6ab4257555099783e8f7e24be9fd`.
- Identical ZIP in Drive Testing Patches: https://drive.google.com/file/d/1y6cXMtcGT_ffFls3EpybXL-YAVooJI8Z/view
- Archive SHA256 `bd0b3b71a52c218fe6593888ca13eee9d5d4c580f1e0f7177ab206cd1d5528bc`. PHP Domain and metadata helper files in ZIP match the staging v0.2.214 updater exactly.
- One **manual-only** GitHub Actions workflow now added to `main`: `.github/workflows/ipdams214-manual-mariadb.yml`, commit `0e973d20831c4a1306129aaf603cc0361103ca93`. It has only `workflow_dispatch`, a **12-minute ceiling**, one Ubuntu job, disposable MariaDB 10.11 synthetic fixtures, and no deployment/production credentials. No push/PR/schedule triggers.
- Environment here still lacks MariaDB server and PHP MySQL driver. Real GitHub Actions test **has not run** because the connected GitHub API does not expose a workflow-dispatch action. The project owner must explicitly start a run in the Actions UI after verifying their budget/settings.
- Local 21 mock transaction assertions and archived migration SQL allowlist checks passed again on 26 October 2026. This is not real MariaDB acceptance.
- User-confirmed installed website is v0.2.213; v0.2.214 staging only, not production-approved.


## 26 October 2026 — Post-reset counts and local v0.2.216 review update

- Owner read-only SQL results: 13 reference/access tables retained at expected counts, and all 14 selected transaction tables empty. Audit events: 254; preserved document counters: 13. No further reset.
- A corrected v0.2.216 review ZIP was created locally from the verified original Drive ZIP. SHA256 `16b531b51a9771cb3485eac413921215d414443c3c9f4a78958b7cc3fbf5a025`, strictly v0.2.215 to v0.2.216, with 7 application files and 2 additive migrations. The existing six feature files and both migrations are unchanged; one rollback-safety PHP file was added.
- The revised archive is a **local review artifact only; not yet on Drive or approved for installation**. See [GitHub review evidence](https://github.com/ronilocanedo/ipdams/blob/feature/v0.2.214-property-batch-review/review/V0216_POST_RESET_REV2_REVIEW_2026-10-26.md).
- Remaining: verify current hosted property columns and migration IDs read-only, recovery plan, and authenticated property/IAR/ICS/PAR testing. No new GitHub Actions run.


## 26 October 2026 — Property migration baseline and Drive REV2 verified

- Owner's authenticated read-only database check: `assets.registration_batch_key` = NOT PRESENT; `assets.property_profile` = NOT PRESENT; count of both proposed IDs in `ipdams_schema_migrations` = 0. **3/3 baseline checks passed.** This establishes readiness of the *pre-migration schema only*; it does not prove an applied update or permissions.
- The corrected v0.2.216 REV2 review ZIP is now stored in the existing **private** Drive Testing Patches folder: https://drive.google.com/file/d/1V6HbJCMHe1yRIu3sIwnYWfkRVdW4l66h/view . Verified provider readback: 310861 bytes; SHA256 of retrieved ZIP `16b531b51a9771cb3485eac413921215d414443c3c9f4a78958b7cc3fbf5a025`, matching locally built revision.
- This verified REV2 supersedes the unpatched property ZIP on Drive. It remains **STAGING REVIEW ONLY**: required v0.2.215, 7 app files (the earlier six unchanged plus rollback safety SettingsService), 2 unchanged additive migrations. Do not deploy from GitHub or install it on the hosted site until owner-approved change window and verified recovery controls; do not claim full authenticated property UAT yet.
- No new GitHub Actions usage, no hosted modification, no reset, no migration executed.
