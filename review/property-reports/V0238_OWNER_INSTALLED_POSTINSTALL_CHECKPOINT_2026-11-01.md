# IPDAMS — owner-reported installation checkpoint (2026-11-01)

The owner reported: **both version-gated update ZIPs installed successfully** on the existing IPDAMS website:
- v0.2.237 — updater property-link strict-allowlist bridge, predecessor v0.2.236, code-only.
- v0.2.238 — new ICS/PAR-to-ISSUE movement explicit link and four additive schema migrations, predecessor v0.2.237.

**Status:** owner-reported successful updater installations; not independently verified by connected site inspection. Do not confuse this with acceptance of the eight COA annex report forms or authenticated permission testing.

Already validated earlier in owner-run isolated Docker: A hardened SQL 17/17, B real Domain finalization 24/24, C updater migration/replay PASS. Hosted MariaDB 11.8.9 and relevant parent-table DDL were inspected read-only before installation.

## Immediate post-install checks on existing authorized test environment
1. Confirm installed version displayed is v0.2.238; Settings and Property/Inventory screens load without a blank screen.
2. Verify older finalized ICS/PAR are still visible with original display numbers; no historical movement auto-backfill.
3. On **authorized disposable test records only**, finalize one new ICS and inspect that one accountability document, ISSUE event and link appear. Then test PAR if safe using distinct asset.
4. Check school-scoped operator cannot read/write another school’s ICS/asset, including direct API routes; division visibility must follow existing assigned permissions, not broad superadmin. No real confidential data in logs.
5. Confirm migration link table / four migration ledger entries using read-only metadata without revealing document/custodian values; review app logs if any server warning.

## Still open
- Post-install database/event and authenticated browser UAT — NOT YET VERIFIED.
- Official COA Annexes A.1/A.4/A.5/A.6/A.7/A.8/A.9/A.10, print-layout/signatory, custody/valuation reconciliation — NOT COMPLETE.
- Recovery after real migration failure and restoration remains not independently exercised. Maintain off-site independent website + database backups; DDL cannot be reversed by file rollback. No new migrations pending in this checkpoint.

Do not recommend reinstalling either ZIP just to retest. Preserve original user-facing ICS workflow; do not manually backfill historical records or run destructive resets. Track further property reports on this draft PR until accepted.
