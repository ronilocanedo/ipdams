# 2026-11-01 — Consolidated Docker A/B/C: all passed; release NOT approved

Owner executed `bash run_all_tests.sh` from the all-in-one validation ZIP (UTC `20261101T120856Z`).

**A PASS** — hardened MariaDB 10.11 composite key/tenant/asset constraints, uniqueness, synthetic rollback: **17/17**.

**B PASS** — real patched `Domain::issueAccountability()` using PHP 8.2/PDO MySQL and synthetic MariaDB: **24/24**. Tests single/group ICS, PAR, duplicates, organization denial, fault-injected link failures, full document/movement/link/number/custodian rollback, historical ICS retained/unlinked.

**C PASS** — review-patched `SettingsService` parser and actual migration apply: only four exact statements accepted, arbitrary SQL rejected, ledger replay idempotence, recovery after DDL/ledger interruption, schema drift rejected, scoped cross-school constraint holds. **Not** an actual hosted stage/apply simulation.

The all-in-one suite verified 35 packaged-file hashes and four Bash syntax checks. No live DB, host ports/volumes, or GitHub Actions.

Original uploaded `ipdams.zip` installed-version metadata: **v0.2.236** (release ID `20261031214349-c93bfef45ce6`). SHA256 from that archive: `private/app/Domain.php` = `d63b584efd2b37f2b1389b57cfdcc6ebb9e57573dc6d81fbe0a0afe914472694`; `private/app/SettingsService.php` = `03ba24fafa4b033b892fc2a9641f5030bb20d9a76a7f70107ffce01bf9f4ea30`. Not proof that host files still match.

**Outstanding D — NOT RUN:** verify actual host version/schema, independently restorable backups, migration/recovery with exact schema, real updater stage/preflight/apply/restore. Existing updater cannot accept new DDL; review a code-only strict allowlist bridge first, then a version-gated migration+Domain release (or test a safely equivalent sequence). Database DDL is not rolled back by file rollback.

**Outstanding E — NOT RUN:** authenticated school, division and cross-division browser/API/print/export scope and issuance/rollback UAT on existing authorized test website. Do not submit credentials or live records.

**Outstanding F — INCOMPLETE:** official COA eight-annex forms and accuracy/print/signatory approval. Current A.1/A.4/A.7 are prototypes/evidence; A.5/A.6/A.8/A.9/A.10 not finished.

**Release decision: NOT APPROVED**. PR stays draft; no production migrations or historical backfill; no old review ZIP as an installer. The owner-requested one-cycle staged acceptance should cover D/E/F once all needed functionality exists.
