# All-in-one offline Docker validation — IPDAMS v0.2.237 (1 Nov 2026)

Owner requested **one combined test package**, not incremental downloaded test suites.

## Artifact
- ZIP: `IPDAMS_v0237_ALL_IN_ONE_DOCKER_VALIDATION_2026-11-01.zip`
- SHA256: `50cf3327c1ca41b7757997a6d05ca9dc1794ef064fe480510d15dc4493a4306d`
- Contains 36 files (including SHA256 manifest for 35 component files), no .env/database dumps/production backups/credentials.
- After extraction, run `bash run_all_tests.sh` from the `IPDAMS_ALL_IN_ONE_V0237_VALIDATION` folder. Results are written to `results/LATEST_SUMMARY.txt` and `results/LATEST_COMBINED_LOG.txt`.

## Bundled tests / release boundaries
A. Hardened MariaDB 10.11 composite FK, organization/asset integrity and synthetic rollback — 17 expected assertions, **previously owner-executed 17/17**.
B. Real patched `Domain::issueAccountability()` with single/group ICS, PAR, duplicate rejection and fault-injected rollback — 24 expected assertions, **previously owner-executed 24/24**.
C. Actual review-patched `SettingsService` migration parser + DDL replay/idempotence/drift/cross-school rejection — **not yet Docker-executed by owner**.

One runner executes A+B+C sequentially, checks exit statuses and exact completion markers, continues after an individual failure, saves a consolidated outcome and verifies file hashes before using Docker. `--self-check` works without Docker; local package SHA check, Bash syntax and ZIP CRC passed. Fail-path orchestration was exercised using a mock Docker executable (not a database result). **The complete combined Docker suite has not been executed here.**

### Not included in automated offline result
- D. Actual hosted updater stage/apply/recovery with exact deployed schema and independent backup restore
- E. Authenticated school/division browser UAT
- F. Complete COA Annex A.1/A.4/A.5/A.6/A.7/A.8/A.9/A.10 outputs and custody/accounting sign-off.

These remain NOT RUN and **release decision remains NOT APPROVED** even if A–C pass. Do not install this package on IPDAMS; it is a test bundle only. No live site/database access; no new GitHub Actions run.
