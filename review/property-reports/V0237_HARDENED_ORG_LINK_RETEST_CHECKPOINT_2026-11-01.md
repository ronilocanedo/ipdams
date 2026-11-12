# 1 Nov 2026 — Docker test result and hardened cross-organization link retest

The owner ran the authenticated MariaDB 10.11 synthetic suite on Windows Docker. Screenshot output confirmed valid migration, duplicate and missing-link rejections, and synthetic rollback. **The old FK-only schema ACCEPTED a cross-organization link**, as the existing test deliberately warned. This blocks v0.2.237 release.

## Hardened candidate
- Source basis: owner-uploaded `ipdams.zip` containing application version `0.2.236`.
- Downloadable review artifact: `IPDAMS_v0237_HARDENED_ORG_LINK_DOCKER_RETEST.zip` SHA256 `92b2eacde1010206b3928c75ed494cfc79bd147de04b8090b4905bb157136c96`.
- GitHub reviewed design files: `237_accountability_movement_links_hardened.sql` and `Domain.php.hardened.review.patch` in this folder.
- SQL now uses composite FKs to enforce matching document organization and asset, movement asset identity and current asset organization. It needs new unique parent indexes.
- Existing ICS/PAR flow remains unchanged to users; the patch links new issuances inside its existing transaction and writes `asset_id` to the link row.
- Historical ICS/PAR records are not backfilled.
- Local baseline patch application, PHP syntax, Bash syntax and archive CRC passed. **The hardened MariaDB test has not yet run**; the owner must run the supplied disposable Docker script. Expected results: 17 isolated checks, no cross-organization warning. Do not infer pass from static checks.

## Remaining required gates
- Migration against an exact sanitized v0.2.236 database, including updater and recovery semantics and ALTER TABLE cost/locks.
- Actual ICS/PAR single/group issuance rollback and duplicate handling under injection failure, separate from synthetic rollback.
- Authenticated school/division UAT on existing staging site.
- Permission-gated COA annex reports and exact template fidelity still incomplete.

No production writes; no GitHub Actions manually triggered. This is not an installable updater.
