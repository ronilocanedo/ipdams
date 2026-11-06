# IPDAMS Release Checkpoint — Updated 2026-11-06

**Latest owner-confirmed installed baseline:** v0.2.288. The owner subsequently tested the Historical Property form and reported an empty Historical Property tab after registration; source assets already existed in Asset Register. The owner did not explicitly confirm installation of v0.2.289.

**New staged fix: v0.2.290 (NOT confirmed installed)** — [release notes](V0.2.290_HISTORICAL_PROPERTY_REGISTER_AND_TRANSFERRED_OUT_REPORTS_2026-11-06.md). Available as **two strict predecessors** (choose only the matching actual installed version): `v0.2.289 → v0.2.290` and `v0.2.288 → v0.2.290` rollup. Six files, zero migrations.

- Adds an actual paginated Historical Property register with View Details and post-registration refresh, plus Show transferred-out history.
- Excludes accepted transferred-out source assets (status `TRANSFERRED`) from current sending-school Asset Register, standard asset lists, special-role asset register and semi-expendable current A.1/A.8 report datasets. Receiving Division active asset remains. Past ICS/PAR and transfer/movement reports remain intact.
- 26 source/SQLite fixture checks and two desktop/mobile Chromium tests passed; php lint, JS syntax and release manifests verified; **live production data/report reconciliation remains pending**.
- Important: no rewriting ownership, stock or financial data; no production Hostinger/DB actions. User uses backups and strict System Updates ZIP install.

## Prior development record

# IPDAMS Installed / Staged Release Checkpoint — Updated 2026-11-06

## Most recent owner-confirmed installed version

**v0.2.288 is installed and working.** The owner confirmed that Historical Property **+ Add missing catalog item** appears after refreshing the page (initially old JavaScript remained cached). Earlier UI corrections through v0.2.287 also owner-confirmed working.

## Staged new update — owner has not yet installed

**v0.2.289** introduces a required per-unit **Physical condition** dropdown in Historical Property. This replaces the mixed free-text "Condition or remarks" input with the five existing Property Details choices: **Serviceable, Needs Maintenance, For Inspection, For Repair, Under Repair**. Formal Unserviceable/For Disposal remains in the separate Condition & Disposal process.

- [Version v0.2.289 ZIP installer](https://drive.google.com/file/d/1IKNN85v4QBEcojFl54jvLM7pNZOe8gBC/view)
- [v0.2.289 release notes / UAT](V0.2.289_HISTORICAL_PROPERTY_CONDITION_DROPDOWN_UAT_2026-11-06.md)
- Exact version gate: **0.2.288 → 0.2.289**, **2 frontend files, 0 migrations**.
- SHA-256: `9c50a77aad42da3997a4e3d973252888861fc5055691a7ed6001662bb315847a`.
- **22** JS logic checks, plus **2 desktop/mobile Chromium flow runs**, passed. Fixture-backed testing only; production MariaDB and real registration UAT pending.

The registration preserves per-unit serial/location, old property number, and the v0.2.288 catalog quick-add workflow. The server `asset.opening` request format is unchanged, and catalog creation never posts property/stock/finance automatically. Initial Historical Property conditions are selected individually, not defaulted to Serviceable.

## Next acceptance steps

Install via Settings → System Updates only after independent website and SQL backups and exact predecessor confirmation. Refresh browser after install. Open Historical Property registration, choose two units, select two conditions, and use legitimate records to verify the saved conditions show on Property Details. Continue Property Accountability and Inventory Management UX consistency tests.

## Security / deployment

GitHub main is a documentation/review index, **not** a verified deployable mirror. Don't deploy it wholesale. No live production website, SQL, payment, stock, or transaction changes were performed while preparing v0.2.289.
