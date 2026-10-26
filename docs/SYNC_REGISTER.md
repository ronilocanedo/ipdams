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


## 26 October 2026 — Hosted v0.2.216 REV2 stage/preflight PASS (not applied)

Owner screenshot from the single running IPDAMS site shows installed **v0.2.215** and the exact corrected v0.2.216 REV2 review package accepted in System Updates as **STAGED** at 2026-10-26 15:09 Asia/Manila. Installer card specifies v0.2.215 predecessor, 7 application files, two migrations `20261026_property_batch_key` and `20261026_property_profile`; on-screen SHA256 prefix `16b53151a97...` matches previously full SHA-verified Drive REV2 package `16b531b51a9771cb3485eac413921215d414443c3c9f4a78958b7cc3fbf5a025`.

**Automatic pre-installation checks shown green:** file hashes, required version, runtime, write access and recovery storage. The `Changed files and checksums` panel is still collapsed in the submitted screenshot; inspect the exact changed-file list and predecessor hashes before applying. **STAGED ≠ APPLIED:** no migration, protected migration database backup, hosted restore rehearsal or authenticated property/IAR/ICS/PAR UAT is established by this screenshot.

Next: expand `Changed files and checksums`; independently verify recoverable owner-held website+database backups and a controlled restore plan, then approve owner-led installation only under release-safety gates. The current GitHub PR remains DRAFT; no GitHub Actions run or automation was triggered.


## 26 October 2026 — Hosted changed-file table independently matched to REV2 ZIP

Owner's expanded System Updates screenshot shows 7/7 intended application paths and their first-12 SHA256 prefixes; each prefix was independently compared against the exact local REV2 ZIP and **matched**:

| Application file | SHA256 first 12 |
| --- | --- |
| `app/Domain.php` | `3c170bddaeee` |
| `app/PropertyBatchMetadata.php` | `8782f5831b10` |
| `public/api/index.php` | `94eaed831b05` |
| `public/app.html` | `b06a76429f94` |
| `public/assets/app.js` | `a090fb109eb3` |
| `public/assets/property-batch-registration.css` | `05ddaa04a9de` |
| `app/SettingsService.php` | `03ba24fafa4b` |

The packaged `SettingsService.php` was also diffed against the owner's uploaded current source SHA256 `1492f45b4c5042a88ac57a22010bf685de586cedaeece1e2e22bec46b7a3fa84`: only the reviewed migration rollback guard changes. REV2 package full SHA256 `16b531b51a9771cb3485eac413921215d414443c3c9f4a78958b7cc3fbf5a025`, as previously verified by Drive readback. These checks verify package provenance; staged state does **not** mean installed.

**Remaining safety decision:** Existing owner-held independent website + database backups were reported already made; restore capability has not been independently established in this chat. MariaDB DDL auto-commits and the updater's rollback guard intentionally prevents file-only rollback for a migration release. With single-site preference, require owner verification of a recoverable matching website/database backup pair and a quiet maintenance window before authorizing Apply. After owner-approved install, perform authenticated synthetic property/IAR/ICS/PAR and tenant isolation UAT; this full hosted UAT cannot be claimed beforehand. Do not run more resets/Actions or add a second hosted site.


## 26 October 2026 — STAGING INSTALLED v0.2.216 (not production)

**Environment correction from owner:** The IPDAMS website used for the entire preceding v0.2.214–v0.2.216 installation/testing sequence is a **staging/development website**, NOT a production/live installation. All data inside it are disposable test data per owner. Earlier text referring to a “live site,” “production,” or “first deployment” must not be taken as evidence of a production rollout. Preserve secure school isolation and accounting/audit protections even on staging; no reset is requested.

**Verified staging installation screenshot:** Settings → System Updates displays green banner “Installed v0.2.216 with 2 verified database migration(s)” and “Currently installed version v0.2.216”; PHP 8.2.35, update timestamp 2026-10-26T15:20:19+08:00, staged REV2 seven-file release; v0.2.215 predecessor. The installer reports both migrations verified, but an independent post-install `information_schema` / `ipdams_schema_migrations` SQL reconciliation remains outstanding. Stage environment is not yet considered functional-UAT-complete.

**Exact staging artifact:** v0.2.216 rollback-guard REV2 SHA256 `16b531b51a9771cb3485eac413921215d414443c3c9f4a78958b7cc3fbf5a025`, [private Drive Testing Patches](https://drive.google.com/file/d/1V6HbJCMHe1yRIu3sIwnYWfkRVdW4l66h/view); prior file/ZIP checks and pre-installation checks passed. This artifact has been **installed on staging**; no production deployment or automated Actions run.

**Next staging UAT:** (1) Confirm the two assets columns and two migration IDs in SQL console, plus empty initial property registers. (2) Refresh the interface, select Property Accountability and test two-unit **historical** property opening registration (shared descriptors, unique serial/location and independent automatically assigned immutable school-level numbers). (3) Test individual and exact-batch audited edits, stale-edit denial, IAR-linked registration vs typed historical evidence, finalized/cancelled ICS/PAR locks, school/Division permission separation and absence of unintended stock/finance postings. Fix any defects incrementally in the next reviewed sequential update. Keep GitHub PR #3 draft until authenticated UAT acceptance.

**Source-of-truth separation:** staging currently runs v0.2.216; production deployment status has **not** been established in this conversation. GitHub remains the private review/audit record; Drive stores release ZIP and guides. The historical complete v0.2.141 source is not a current build baseline.


## 26 October 2026 — Staging property edit white-screen bug and v0.2.217 repair candidate

- Staging site **remains installed at v0.2.216**; two newly registered Taloto National High School test assets have property tags `306351-2610-00001` and `306351-2610-00002`. Owner confirms registration UI success. Subsequent edit of first property's location causes blank page; historical registration inputs (cost/date/shared details) and success notice persist unexpectedly.
- **Confirmed frontend root cause:** React 16 event pooling; property edit handlers referenced `e.target.value` inside asynchronous functional `setState(prev=>...)`, causing `Cannot read properties of null (reading 'value')`. Chromium reproduction on React 16 passed for both failing original and corrected code.
- **Proposed v0.2.217** (strict predecessor `0.2.216`): two frontend files only, `public/assets/app.js` and `public/app.html`, **zero migrations**. Fix UNIT/BATCH edit event capture; clear complete historical form on successful save, auto-dismiss property successes after 7 seconds with explicit Dismiss and tab-navigation reset; cache-bust JS. Original source and server-side property, numbering, audit, custody and financial rules unchanged.
- Reviewed ZIP: SHA256 `68d83e18772ef338ddead487ce540a12f1e9431d95dcbdf0e46c1bac398a5adf`; verified [private Drive file](https://drive.google.com/file/d/13CVjCILIBt9YE9mmIXCvN8NH8nHZVvgF/view) (182363 bytes), mirrored [private GitHub review artifact](https://github.com/ronilocanedo/ipdams/blob/feature/v0.2.217-property-frontend-fix/review/artifacts/IPDAMS_v0.2.217_PROPERTY_FORM_AND_EDIT_WHITE_SCREEN_FIX_FROM_v0.2.216_STAGING_REVIEW.zip) Git blob `c58bac2570c487861724e8c86deb3488a3ef0c02`. [Draft PR #9](https://github.com/ronilocanedo/ipdams/pull/9), [review/UAT guide](https://github.com/ronilocanedo/ipdams/blob/feature/v0.2.217-property-frontend-fix/review/V0217_PROPERTY_UI_FIX_AND_UAT.md).
- ZIP CRC/manifest/hash integrity and Node syntax PASS. React16 Chromium fixture passed two-unit historical form reset, per-unit location/serial/condition edit without blank screen, shared batch brand/model edit, corrected API request shape, notice dismissal and 7-second expiration. **Local simulated browser tests; actual hosted post-install UAT remains pending.** No Actions runs, second staging site, data reset, migrations or production deployment.


## 26 October 2026 — Owner-confirmed staging v0.2.217 installed and correction reason

Owner confirms v0.2.217 installed on staging. Edit Property Details page displays without white screen and location for 306351-2610-00001 has been typed as Room 103. Save is disabled because correction reason field is empty; exact source requires trimmed reason length >= 12, along with normal busy/issued checks. User was guided to provide an auditable correction reason. Edit persistence, other asset unchanged, and audit not yet verified. Future UX improvement: explain disabled button with inline text, without weakening the audit reason requirement. No additional patch or migration required for this behavior.


## 26 October 2026 — v0.2.218 Asset Register Details and filters

Owner requested a practical way to verify the newly saved location and registration metadata of two staging properties (Taloto National High School tags `306351-2610-00001` and `306351-2610-00002`). The staging v0.2.217 correction operation showed a success banner; complete stored details/audit and impact on the other unit were not yet individually verified by owner.

**Prepared, NOT installed:** v0.2.218 Asset Register Details & Filters, strict predecessor 0.2.217, 4 application files (`app/Domain.php`, `public/assets/app.js`, `public/app.html`, `public/assets/property-register-details.css`), **zero database migrations**. Features: per-row Details with saved unit/cost/source, current Divison catalog classification, exact original batch members, official IAR references and ICS/PAR custody, movement history, append-only audited correction before/after; school-scoped server-side filters for type, actual Division classification (ICT Equipment, Office Equipment, ICT ADM where configured), item, accountable custodian (including unassigned), status, origin and text; keyset pages of 25 to avoid 100-row browsing limit. Uses existing authorized API paths and read-only queries; not an asset mutation feature.

**Exact reviewed ZIP:** `IPDAMS_v0.2.218_ASSET_REGISTER_DETAILS_FILTERS_FROM_v0.2.217_STAGING_REVIEW.zip`, SHA256 `17400f9480c23627d3018bd999f8eb1997a325b96ce5b8ff46db894b9997f939`; 291853 bytes; ZIP CRC, manifest hashes and PHP/Node lint PASS. [Private Drive Testing Patches](https://drive.google.com/file/d/1HHBObGlI25WGK8bCyyQ6alItvXIsqxJH/view) (upload and raw-file readback), [Private GitHub binary source review](https://github.com/ronilocanedo/ipdams/blob/feature/v0.2.218-asset-register-details/review/artifacts/IPDAMS_v0.2.218_ASSET_REGISTER_DETAILS_FILTERS_FROM_v0.2.217_STAGING_REVIEW.zip) blob SHA `b407d45c65e19d75df7c08299302de36fc6a8894`, draft [PR #10](https://github.com/ronilocanedo/ipdams/pull/10), [UAT guide](https://drive.google.com/file/d/1_L0EOhAj6dsIUdskDH8cJMCXoPhzDoCP/view).

Local Chromium running React16 PASS: exact property detail, saved Room 103 and historical batch, sibling navigation, audit history before/after, dynamic classification filter, search and clear, zero browser exceptions. Static school scope and parameterized bounded SQL checks PASS; **actual authenticated hosting API/DB and cross-school/VIEWER denials still require owner staging UAT**. No GitHub Actions run, DB changes, reset or additional staging site. Keep review PR DRAFT. Current owner-confirmed installed **v0.2.217**, pending v0.2.218 installation.


## 26 October 2026 — v0.2.218 initial-state white-screen regression, v0.2.219 review

Owner confirms v0.2.218 installed on existing staging site but opening Property Accountability resulted in completely blank UI. The exact v0.2.218 ZIP code omitted register filter/result/detail state from **initial** React state; fields existed only on organization switch and in the synthetic Chromium test fixture. A new Chromium harness using actual packaged `initial` reproduces `Cannot read properties of undefined (reading 'search')` and blank `#root`; same test with v0.2.219 patch PASS for first render, Details, school classification, search and Clear with zero browser errors.

**Reviewed staging-only candidate v0.2.219** (strict predecessor 0.2.218), ZIP SHA256 `ee9a4e73546c7d673547e74f755d30d356225e0709ed5fbc4cfb47bc05bfb233`, 184985 bytes; **two files only** `public/assets/app.js` SHA256 `b303e7dc150351c9ff60d3147fb11d7ca4bb5039dbf04adf95a419b6df04ac93`, `public/app.html` SHA256 `b65a8833317fca540397b6336ba778e1bdfda1bb7ecd4d4c4da5282583ae12a1`; **zero migrations**, no Domain/backend or financial/asset changes. ZIP CRC/manifest/hash and Node syntax PASS. [Private GitHub draft PR #11](https://github.com/ronilocanedo/ipdams/pull/11) + [technical UAT document](https://github.com/ronilocanedo/ipdams/blob/hotfix/v0.2.219-property-register-first-render/review/V0219_FIRST_RENDER_WHITE_SCREEN_RECOVERY.md). Exact ZIP downloadable in the conversation; a separate Drive binary upload **is not verified**, so do not claim that the corrected ZIP is in Drive. Prior v0.2.218 Drive ZIP must **not** be reinstalled as a fix. Owner-hosted version remains 0.2.218 until confirmation; no reset/site duplication/GitHub Actions.


## 26 October 2026 — v0.2.219 exact artifacts mirrored

**Supersedes earlier pending-upload statement:** Exact v0.2.219 Asset Register first-render no-migration hotfix (strict predecessor v0.2.218, 2 frontend files) is now in the owner's [private Google Drive Testing Patches folder](https://drive.google.com/file/d/1NIKRd6lbLUun14Hq8v6heB4LKpJucalu/view), 184985 bytes, unshared. [Drive UAT guide](https://drive.google.com/file/d/1yuWUgoRpRc7LldyVaHs2HlkNJI5yxNY7/view). The ZIP's bytes were fetched from Drive and copied to [private GitHub review artifacts](https://github.com/ronilocanedo/ipdams/blob/hotfix/v0.2.219-property-register-first-render/review/artifacts/IPDAMS_v0.2.219_PROPERTY_REGISTER_INITIAL_STATE_WHITE_SCREEN_FIX_FROM_v0.2.218_STAGING_REVIEW.zip), Git blob `a589b853ffb5452410abc15ab45ef0f6864a75eb`. Local SHA256 `ee9a4e73546c7d673547e74f755d30d356225e0709ed5fbc4cfb47bc05bfb233`. [Draft PR #11](https://github.com/ronilocanedo/ipdams/pull/11); original v0.2.218 crash reproduced in actual-initial-state Chromium, fixed candidate passes property Details and filters. **Owner site still last confirmed v0.2.218; staging install/UAT of v0.2.219 not yet performed.** No migration/reset/paid Actions or production deployment.
