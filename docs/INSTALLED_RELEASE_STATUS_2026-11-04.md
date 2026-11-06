# IPDAMS Installed and Staged Release Checkpoint — Updated 2026-11-06

## Most recent owner-confirmed installed version

**v0.2.287 installed and working.** The owner confirmed that the v0.2.287 correction resolved the oversized stacked Property Accountability tabs and differing Division theme. v0.2.286's Super Admin/Property & Inventory UI improvements are included. Previously confirmed: textbook teacher issuance/disposal and reporting v0.2.284; book ledger v0.2.285 was included via the appropriate v0.2.286 roll-up if needed.

## v0.2.288 — Staged only, owner installation pending

The new owner-requested patch allows **creating a missing PPE or Semi-expendable catalog item from inside Historical Property registration** without navigating away. Selecting **+ Add missing catalog item** opens a compact modal. Enter item name, existing unit, active Division-approved classification/UACS, then Save; the new item is selected automatically in the preserved historical property form.

- [Version 0.2.288 release notes, controls, local browser testing and UAT](V0.2.288_HISTORICAL_PROPERTY_CATALOG_QUICK_ADD_UAT_2026-11-06.md).
- [v0.2.288 ZIP installer](https://drive.google.com/file/d/1y0JJcOoRa5qpZ9prrk8xeIGGEDX-lJHL/view).
- [v0.2.288 installation/UAT guide](https://drive.google.com/file/d/1gaSD963uQZvN7SUrZtIt1ArxnqAN4b-_/view).

**Predecessor gate 0.2.287 → 0.2.288. 2 frontend changed files; zero database migrations. SHA256 `e04e71186adb014ab472eaa705c21fc249eeebe509d2d24c83251419448d2703`.**

Uses existing server `master.item.create`, with original school/Division scoping, UACS approval, role permissions and audit. Creating a catalog item does **not** register physical historical property, move inventory or post accounting entries. School catalog is scoped, Division catalog shared according to existing policy, Super Admin read-only Office Viewer remains non-mutating.

**Testing distinction:** 15 React component checks and local Chromium desktop/mobile full dialog interactions passed using mocked authorized API responses. Live production MariaDB and real role credentials remain untested and require owner UAT. Don't invent production asset transactions.

## Next

Confirm owner install of v0.2.288 and test historical catalog quick-add with a real missing approved item. Continue Property Accountability and Inventory Management improvements based on actual user testing.

## Security and deployment cautions

- Keep independent website and SQL backups and use strict version-gated updater ZIPs.
- Do not override Division classification or catalog scope rules.
- GitHub main is documentation/review focused, not a verified live source mirror; never deploy it over Hostinger wholesale.
- Assistant prepared ZIP/docs only; no production web/SQL writes, reset, migration, posting, or GitHub Actions run.
