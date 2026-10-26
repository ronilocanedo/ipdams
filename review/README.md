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
