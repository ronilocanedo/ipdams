# IPDAMS — GitHub ↔ Google Drive synchronization register

**Established:** 2026-10-26 (Asia/Manila)  
**Sync status:** Manually reconciled coordination links; **not** continuous, automatic or bidirectional file mirroring.

## Canonical locations

| Type | Authority | Reference |
| --- | --- | --- |
| Source code, tests, code reviews, branches | GitHub (private) | https://github.com/ronilocanedo/ipdams |
| Project working folders, review ZIPs, guides | Google Drive | https://drive.google.com/drive/folders/1gwRRZYNdsbEhtINpNybAG1JF54WFuyGE |
| Canonical development progress | Google Docs | https://docs.google.com/document/d/1AB5SmgnGI3j6Y02q1alVdR7p5ty-sS4HFFBobRkV2lM/edit |
| Sync register and changes | Google Docs | https://docs.google.com/document/d/13NWruT1RTeFoxcL14l9-5UY9e2ENuX2RHEveAUGHOF8/edit |
| Role/security design | Google Docs | https://docs.google.com/document/d/19kZEhOmTeZrRtDYd4NCtXJybYP_1uoTzM1LKM6Z1ZyA/edit |
| Authoritative installed version | Owner-confirmed hosted IPDAMS | v0.2.213 |
| Full code-only source ZIP currently in Drive working-source folder | Drive historical snapshot | v0.2.141 (outdated; do NOT deploy as v0.2.213) |

## Release-candidate artifacts at this checkpoint

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
