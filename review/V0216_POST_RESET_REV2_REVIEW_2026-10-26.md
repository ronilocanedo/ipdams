# v0.2.216 — Post-reset counts and rollback-safe review candidate REV2

**Status: REVIEW ONLY — not authorized for hosted installation.** Verified 26 October 2026.

## Owner-supplied read-only hosted counts (v0.2.215)

13 master/reference/access tables match the pre-reset preserved values:
- UACS 1250; funds 2; catalog 9; units 4; suppliers 3; tax ATCs 5; tax rate versions 5; bank account master 1
- employees 10; BAC terms 1; organizations 2; users 3; user organization scopes 3
- Audit events 254 (pre-reset 253); document counters 13 preserved

14 checked transaction tables are empty: PR, RFQ, PO, IAR, DV, checks, checkbook leaves, fund events, inventory lots, stock events, assets, RIS, accountability documents and bank reconciliations. These SQL totals support successful *selected* Custom Reset; they do not prove authenticated role/API operations. **Never repeat reset.** Re-register physical checkbook leaves before future check printing.

## Local corrected candidate — NOT yet mirrored into Drive

- Filename: `IPDAMS_v0.2.216_PROPERTY_BATCH_ROLLBACK_GUARD_FROM_v0.2.215_STAGING_REVIEW_REV2.zip`
- ZIP SHA256 `16b531b51a9771cb3485eac413921215d414443c3c9f4a78958b7cc3fbf5a025`, verified in current session.
- Strict predecessor `0.2.215` → `0.2.216`.
- 7 app files, 2 SQL migrations, release.json. Original v0.2.216 REV1 ZIP SHA256 `aecb5727cbc271e6c18861ca475756f15f2bfe0e85bce3f78f70d491e979b265`. **All six pre-existing application files and both additive SQL scripts are byte-identical to REV1**.
- Seventh file: `app/SettingsService.php`. Owner-supplied current source SHA256 `1492f45b4c5042a88ac57a22010bf685de586cedaeece1e2e22bec46b7a3fa84`. Patched file SHA256 `03ba24fafa4b033b892fc2a9641f5030bb20d9a76a7f70107ffce01bf9f4ea30`. Single focused change: manifest-based fail-closed file-only rollback of schema-changing releases. Exact [review-only diff](patches/v0.2.216-settings-rollback-manifest-guard.REVIEW_ONLY.diff).
- ZIP CRC, manifest checksums, PHP lint 4 files, Node syntax, isolated PHP fixture **PASS**: migration rollback blocked, nonmigration allowed, non-active release blocked. No PHP database/hosting connection. Previously verified synthetic MariaDB Run #2 remains applicable to unchanged property domain code; **no new Actions run**.
- This is a verified **local** conversation artifact, not yet uploaded to Drive. Do not represent the old Drive candidate as REV2 or claim both stores contain the same bytes until Drive file ID and checksum are verified.

## Remaining safety gates

1. Read-only hosted `information_schema.COLUMNS` for `assets.registration_batch_key` and `assets.property_profile` and existing `ipdams_schema_migrations` IDs. Expected absence of both additive fields and zero conflicting migration IDs before install.
2. Owner's independent DB/site backups and restore plan must be validated. MariaDB ALTER TABLE autocommits; file rollback is not DB restore. Current updater checksummed pre-migration archive+fail-closed journal are not a substitute for practicing DB restore.
3. Real authenticated school A/B/Division and direct API, accepted-IAR link, exact-batch/single-unit edits, serial/custodian protection, finalized/cancelled ICS/PAR denial, audit/stale-edit, no stock or financial side effects. Use only approved synthetic records.
4. Single existing site owner policy: do not create extra hosting site, trigger CI, perform live migration/installation, or run another reset without authorization.
5. Once release gates pass, transfer verified ZIP to Drive Testing Patches and cross-reference actual Drive ID/SHA in both sync registers. Keep GitHub PR #3 DRAFT until acceptance.

See [portable current handoff](https://github.com/ronilocanedo/ipdams/blob/main/docs/HANDOFF_2026-10-26_v0.2.215_to_v0.2.216.md), [Drive sync](https://docs.google.com/document/d/13NWruT1RTeFoxcL14l9-5UY9e2ENuX2RHEveAUGHOF8/edit) and [GitHub Issue #2](https://github.com/ronilocanedo/ipdams/issues/2).
