# v0.2.214 — Property batch registration and editing (review only)

**Status: NOT INSTALLABLE — NOT production-ready.** Owner-confirmed installed version is v0.2.213.

The proposed change supports historical property batches with shared brand/model/date/cost and optional historical PO/IAR/DV references, individual serial/location/condition and custody references, immutable lifetime school property numbers, and audited descriptive corrections. Existing finalized ICS/PAR records remain protected.

## Review artifacts

The complete source-review ZIP has SHA256:
`2f7d9323c7799c706e5a8224548d2c5eb2c34651bd4a912f1466ea6de001d4fb`

Disposable MariaDB CI-gate test kit SHA256:
`bd0b3b71a52c218fe6593888ca13eee9d5d4c580f1e0f7177ab206cd1d5528bc`

The source/test ZIPs are **not** mirrored into this repository yet; hashes are pointers to the verified local review artifacts. Do not claim GitHub contains the full source implementation until that happens.

The two SQL files in `review/migrations/` are **proposals**, have not run anywhere, and must be validated against the exact deployed updater and real isolated MariaDB.

## Approval gates

- Reconstruct and commit verified sanitized v0.2.213 baseline, then apply source candidate as reviewed diff.
- Real InnoDB simultaneous-school-number registration, failure rollback, audit persistence.
- Historical and finalized IAR source restrictions, idempotency, multi-school RBAC, Revocation, ICS/PAR edit denial.
- MariaDB additive schema migration interrupted-updater and independent backup/restore rehearsal.
- Owner review of staging results before any installable v0.2.214; no deployment via GitHub.

Project sync pointers: https://docs.google.com/document/d/13NWruT1RTeFoxcL14l9-5UY9e2ENuX2RHEveAUGHOF8/edit

## Exact source archive now committed to this review branch

The 13-file v0.2.214 source-review ZIP is now available at [`review/artifacts/IPDAMS_v0.2.214_SOURCE_REVIEW_DO_NOT_INSTALL.zip`](artifacts/IPDAMS_v0.2.214_SOURCE_REVIEW_DO_NOT_INSTALL.zip), **Git blob SHA** `edb543a6edb4045eb9dbde4952feb114ccb95c85`, **ZIP SHA256** `2f7d9323c7799c706e5a8224548d2c5eb2c34651bd4a912f1466ea6de001d4fb`. This is the exact previously validated archive, not a deployable application or updater.

A separate **staging-only** manifest ZIP (v0.2.213→v0.2.214, with two additive migrations) is now stored on [Drive](https://drive.google.com/file/d/1R1dEUkhaSt-BsERR1kFopb7IlRlRhxUk/view); its SHA256 is `f4f7e03f8573fb4c0e7b92aa9d7f7b76024e36d7bb628bd908ad5d0a6f1528bf`. [Staging guide](https://drive.google.com/file/d/1ojOtIOyvv8Mof9rHtc8B2EFBaTRBM_Xo/view). **Do not run this on production** until real staging MariaDB, school-scope, accountability and independent backup/restore acceptance has passed.
