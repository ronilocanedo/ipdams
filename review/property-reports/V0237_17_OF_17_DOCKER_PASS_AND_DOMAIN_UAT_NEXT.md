# 2026-11-01 — Hardened ICS/PAR migration: owner Docker retest confirmed

**Owner-reported terminal output** from Windows Docker Desktop and MariaDB 10.11:

```text
PASS: authenticated disposable MariaDB readiness
PASS: M1 hardened composite-key migration on synthetic schema
PASS: three composite foreign keys present
PASS: valid scoped issuance accepted
PASS: duplicate document rejected by constraint
PASS: duplicate movement rejected by constraint
PASS: missing document rejected by constraint
PASS: missing movement rejected by constraint
PASS: missing organization rejected by constraint
PASS: cross-organization document to movement rejected by constraint
PASS: cross-organization movement to document rejected by constraint
PASS: cross-organization asset label rejected by constraint
PASS: different movement asset rejected by constraint
PASS: different document asset rejected by constraint
PASS: forged asset label rejected by constraint
PASS: independent organization valid issuance accepted
PASS: synthetic document + movement + link rollback
DONE: 17/17 isolated assertions passed. No database-connected website was accessed.
```

Disposable database was removed without ports/volumes. This is **successful synthetic composite-key SQL validation**; it supersedes the previous failed FK-only security gate. Do NOT extrapolate these results to an authenticated installed site or application rollback.

**Next executable test:** `IPDAMS_v0237_REAL_DOMAIN_DOCKER_UAT_REVIEW.zip` (SHA256 `b3b7ed56a7c6fc4e569cd6b823841b39aa4fccdf6bc1c6a5eb57b65ab4e5ae8e`). Built from the uploaded original v0.2.236 Domain source, with reviewed explicit-link patch applied, and a synthetic targeted schema. It runs real `Domain::issueAccountability()` under PHP 8.2 + PDO MySQL against MariaDB 10.11 in a disposable Docker namespace; covers single ICS, group ICS, PAR, duplicate handling, authorization, deliberate second-link failure rollback, and tampered movement custodian/event. Local PHP lint and Bash syntax passed, ZIP CRC verified. **Not executed in Docker yet.** No user or production data in bundle.

Still required before any installable v0.2.237 updater: exact deployed-schema migration + failure recovery, authenticated school/division site UAT, and (separately) official COA report layouts/ledger reconciliation. No GitHub Actions, no production writes, no silent historical backfill.
