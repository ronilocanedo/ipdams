# ICS link verification hardening — 2026-11-01

Reviewed against owner-uploaded v0.2.236 `private/app/Domain.php`. Added a **diff-only** integration package, not a full application-source replacement.

Artifact `IPDAMS_ICS_LINK_HARDENED_PATCH_REVIEW_2026-11-01.zip` SHA256 `4cfe435ea96a7893251f3dd0a891606c41cd3bbbcee21c69ddba4e9b84c6fb29`.

The patch creates explicit document→ISSUE movement links inside the existing transaction and verifies same asset, same organization, finalized ICS/PAR, ISSUE event and matching custodian before linking. Migration defines unique document/movement IDs and three foreign keys. No legacy rows backfilled.

**Local verified:** patch applied cleanly to original uploaded Domain.php, patched file PHP syntax passed, 10 static contract checks passed, ZIP CRC passed. **Not verified:** database-backed migration/replay, transaction rollback, alternate issuance paths, updater compatibility, authenticated cross-school UAT. No live database or production change, no paid Actions. Do not install until those tests pass.

**Caveat:** table FKs alone do not enforce same asset/organization, so application checks and least-privilege database grants are necessary; historical ambiguous custody still requires review. This is not yet the completed eight-annex COA module.
