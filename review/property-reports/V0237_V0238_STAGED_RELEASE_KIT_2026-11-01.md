# IPDAMS property linkage — coordinated staging release kit (2026-11-01)

Owner has supplied read-only hosted DDL for organizations, assets, accountability_documents and asset_movements, and confirmed MariaDB **11.8.9**. All four tables are InnoDB, their identity columns match the hardened composite FK design, and the new link table is not yet installed. Original uploaded site archive has installed-version state **0.2.236**; verify the currently hosted application version and source SHA before any installation.

## Prepared artifacts — available in current conversation
Outer ZIP: `IPDAMS_v0.2.237_0238_TWO_STEP_RELEASE_KIT_2026-11-01.zip`
SHA256: `df41244f184c7f5901414fa429b91889cb37e9d5f07fc379d99fb5af97f86a63`.
Contains these separate updater manifests plus installation notes:
1. `IPDAMS_v0.2.237_UPDATER_PROPERTY_LINK_ALLOWLIST_FROM_v0.2.236.zip` — SHA256 `4d2b987dbc253a183cb535a9326d809cb00c218d55b7827b88f91f2d3e6be301`. One code-only file `app/SettingsService.php`; extends strict allowlist solely for four previously reviewed exact DDL statements, **zero migrations**. Requires installed 0.2.236.
2. `IPDAMS_v0.2.238_EXPLICIT_ICS_PAR_MOVEMENT_LINK_FROM_v0.2.237.zip` — SHA256 `da4cd7c8330220aca4a94dab4621edc0080881b49f203745f2d0699d786de69d`. One source file `app/Domain.php` plus four one-statement additive migrations. Requires installed 0.2.237. Links only new ICS/PAR issuance documents and movements transactionally; no backfill.

Original file SHA256:
- SettingsService.php `03ba24fafa4b033b892fc2a9641f5030bb20d9a76a7f70107ffce01bf9f4ea30`
- Domain.php `d63b584efd2b37f2b1389b57cfdcc6ebb9e57573dc6d81fbe0a0afe914472694`

Patched file SHA256:
- SettingsService.php `2bce2d65ae581c45d5642923fac3d9b7dd102ac5b22ed1a4a16bd44a8699091c`
- Domain.php `a3b7da38d6faed75bdf1e8ad79b9c00370c7ade17dc61952a735014ed33c00ef`

## Verification
Previously OWNER-EXECUTED combined disposable Docker: **A 17/17**, **B 24/24**, **C updater strict parser/migration replay PASS** on MariaDB 10.11 and PHP 8.2 with synthetic data. This kit reuses the identical tested patched files, verified SHA256; each entry matches manifest hash; ZIP integrity passed; both PHP files lint; local strict migration parser accepts the four statements and rejects DROP. No secrets, production DB dumps or historical records included. No paid GitHub Actions.

## Release decisions and safeguards
**Staging candidates only**: installer stage/apply/recovery and user permissions on the hosted website remain untested. Schema DDL is not undone by file rollback; require independent website+DB backups and verified restore before the second package. Apply **sequentially** on existing authorized test website, never both at once. Stop on predecessor mismatch, source drift, failed preflight, or migration-review journal. Do not manually edit the database to evade preflight. PR remains draft.

**Not included:** official eight COA report annex layouts, accounting reconciliation and division-level sign-off. Do not present this package as completion of the reporting module.