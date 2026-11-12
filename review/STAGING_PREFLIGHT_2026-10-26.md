# v0.2.214 staging preflight — 2026-10-26

**State: PARTIAL PASS; full-application isolated hosting UAT is still pending. Do not deploy to production.**

## Completed and verified

- v0.2.213 package SHA256 `3a701dc7ef05e94bc744db46b935a8414044dfa0e0f3a417fddb85957ec1c824`; predecessor `0.2.212`, 6 files, zero migrations.
- Corrected v0.2.214 REV2 staging package SHA256 `6e779280ac704917892996a78dbce500d7bab44e01aeac22e8c14f860ddbbe61`; predecessor `0.2.213`, 6 files, 2 additive migrations.
- ZIP CRC and all release.json file/migration SHA256 values: verified.
- Source-review v0.2.213 predecessor hash check: passed for six modified source files.
- PHP property metadata tests: 20 assertions passed.
- PHP API/migration/authorization/numbering/audit static invariants: passed.
- React16 Chromium simulated browser: historical 3-unit batch, PO/IAR/DV evidence, edit guard, IAR form, mobile fit, zero JavaScript errors: passed.
- GitHub [manual run #2](https://github.com/ronilocanedo/ipdams/actions/runs/40265443265): **SUCCESS** against MariaDB 10.11 isolated synthetic schema, including rollback, four parallel school property numbers, audit, unit/shared edits, stale-edit and ICS lock checks.

## Hard remaining gate: genuine full IPDAMS application staging

The Google Drive working-source folder still has a **v0.2.141** complete source snapshot, not a verified full **v0.2.213** source. We must **not** construct a pretend full-app staging copy from the older source plus incomplete release patches.

1. Create a private, separately configured Hostinger custom PHP/HTML staging copy of the exact installed v0.2.213 website and a separate test MariaDB database.
2. Ensure staging code does NOT point to the production database, assets folder or outgoing financial/notification services. HTTP password protect or restrict access to staging. Do not upload unsanitized live data or credentials to GitHub/Drive.
3. Take independent website and DB backups, and test a restore. Set up synthetic school A/B/Division fixtures; if any real DB clone is temporarily used, keep within restricted hosting and sanitize before sharing.
4. Only in isolated staging, run the corrected REV2 v0.2.214 updater from v0.2.213 after verifying ZIP SHA256. Test the real register/edit flows, IAR source safety, finalized/cancelled ICS/PAR protections, account/school scope, direct API 403, idempotency, shared batch vs serial, and rollback.
5. Rehearse failed/interrupted migration and site+DB recovery before production consideration.
6. Record signed PASS/FAIL evidence in Drive and link its exact checksum and Git commit.

Hostinger instructions for custom PHP duplication: https://www.hostinger.com/support/5981410-how-to-duplicate-a-custom-website-at-hostinger/

**No further GitHub Actions runs are needed for current reduced-schema MariaDB tests.**
