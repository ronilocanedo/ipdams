# 2026-11-01 — 24/24 real Domain ICS/PAR assertions passed; updater migration gate

## Owner-executed result
Windows Docker MariaDB 10.11 + real patched `Domain::issueAccountability()`: **24 application-level assertions passed**. Covered single/group ICS, PAR, document movement explicit links, duplicates, school cross-organization denial, second-link fault rollback (documents, events, links, counters, custodians), custodian/event tampering, and historical ICS preservation. Disposable synthetic DB only. Together with prior hardened SQL **17/17**, these clear the synthetic data-integrity and PHP transaction checkpoints. This is NOT authenticated browser UAT.

## Newly confirmed compatibility blocker
Read original owner-uploaded v0.2.236 `private/app/SettingsService.php`. Actual `settingsMigrationSql()` accepts one SQL statement per file and only a narrow set of explicit migration shapes (add-column, UACS insert, exact checkbook-leaf table). The hardened link migration consists of 3 parent composite unique indexes plus one link table. **The original update installer rejects those statements before installation**. In addition, `settingsRollbackPreflight()` explicitly blocks file-only rollback after schema migrations; DDL can auto-commit. Do not bundle the 4-statement SQL as a v0.2.237 web updater.

Verified locally with PHP 8.4:
- Original `settingsMigrationSql` rejects new index/table shapes.
- A separate **review-only**, narrowly patched parser admits only four exact property-link statements, each in a separate one-statement migration file.
- The bridge rejects DROP, multiple SQL statements, substituted index columns, substituted FK, and arbitrary CREATE.
- PHP lint, Bash syntax and ZIP CRC passed.
- Not yet Docker-executed against MariaDB, not end-to-end website updater stage/apply/recovery, not deployable.

## Docker compatibility retest
Artifact: `IPDAMS_v0237_UPDATER_MIGRATION_DOCKER_REVIEW.zip`, SHA256 `5576e2f364cfb11fedb357a14bf34c7599e937cadf41751a6c912b9f91e65277`. Synthetic PHP 8.2/PDO MySQL/ZIP + MariaDB 10.11 runner tests manifest parser, four independently checksummed DDL files, actual `settingsApplyMigrations`, ledger idempotence, DDL-before-ledger retry, schema drift rejection, and cross-school rejection. The runner is offline relative to any website, with no host ports/volumes; run on owner Docker. **No actual Docker execution verified yet.**

### Deployment sequencing
Safest browser-updater route likely requires a separate, code-only migration-allowlist bridge release followed by the version-gated schema+Domain release. Both require exact current installed version verification, full schema backup and restore runbook. The standalone review bundle is *not* a website installer. Following successful migration compatibility checks: staged updater rehearsal, then authenticated school/division UAT on existing site. Official COA Annex A.1/A.4/A.7 and other annex completion is separate.

No production modifications or paid GitHub Actions.
