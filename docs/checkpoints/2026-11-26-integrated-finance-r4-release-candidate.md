# Integrated Finance R4 — development acceptance and packaged release candidates

2026-11-26 (Asia/Manila). Owner requested recording the milestone and preparing Hostinger patches. **Not installed; Main application source remains v0.2.454.** The owner-approved Main policy requires successful installed operation before application promotion.

- Verified predecessor: `8a3a10c1e9eb0c8ece73156f2b1554aa138db036`, 258 exact source files.
- R4 consolidated review SHA-256: `3fa3ea3ccfaa13c2c3e0cf8aecbfb6627df987230735a398dd5474e33083cdba`; 468 bundle checksums, 87 changed-source hashes, 316 pinned test-source hashes (verified CRLF differences).
- Codex synthetic Docker/MariaDB/Playwright evidence: 302 structured PASS, 0 FAIL across 18 suites; 19 actual HTTP installer/recovery tests included. Independent ChatGPT rerun: 75 pure assertions (included in 302), 25 PHP/JS syntax checks. Docker/HTTP suite evidence reviewed, not independently rerun.
- Scope: account-wide bank reconciliation, historical financial snapshots, explicit individual fund-download allocation and utilization, read-only Division monitoring and reports, preserved existing workflows; no online liquidation submission or approval.
- Exact R4 application bytes and four migration statements repackaged; release title/notes only changed. Both ZIP inventories and manifest validation passed. No live installation or migration performed.

| Candidate | Requires | Application files | Migrations | ZIP SHA-256 |
| --- | --- | --- | --- | --- |
| IPDAMS_v0.2.456_R4_INSTALLER_CAPABILITY.zip | 0.2.454 | 4 | 0 | c397f3e4c185159ae3c5f71a509d140985eae371ef5334227a1ab262c50d834b |
| IPDAMS_v0.2.457_R4_INTEGRATED_FINANCE.zip | 0.2.456 | 22 | 4 | b28fde6df4b018fd5a7f27276d810d587e9f5791c07b286ff5336c57e6ad8242 |

## Mandatory production gates

1. Confirm installed v0.2.454, independent complete website and SQL backups, recoverability, current schema compatibility and a maintenance/onboarding plan.
2. Review intentional restriction: direct SQL DML disabled; read queries retained.
3. Historical payment attribution must be explicitly reconciled against evidence. The integrated update blocks affected new spending rather than guessing. Schedule operational onboarding before activating it.
4. Install bridge only with authorization; refresh PHP workers/OPcache and verify new executable installer by successful validation of the integrated package. Stop if stale.
5. Apply integrated stage only after review and authorization. Four additive migrations create finance_allocation_sets, finance_allocation_lines, finance_allocation_events and bank_reconciliation_sources. Do not manually execute migration SQL. File-only rollback is prohibited after DDL; preserve verified site + database backups and coordinated recovery.
6. Confirm actual operation and version with owner, reconcile complete candidate source (276 files including migration sources), then promote application source to Main without force push.

Live Hostinger hashes, actual hosting schema/permissions, cache behavior, historical exception onboarding and production recovery have not been verified. Synthetic acceptance is not proof of production compatibility or official certification. No production data, credentials, raw logs or backups are included in this public checkpoint.
