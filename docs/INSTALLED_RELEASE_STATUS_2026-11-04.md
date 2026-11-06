# IPDAMS — Installed / Staged Release Checkpoint (updated 2026-11-06)

## Most recent owner-confirmed installed release

**v0.2.286** — owner reports installed. Owner screenshot of Property Accountability shows a UI regression: four Property navigation categories stack as large full-width cards, and Division theme differs from Textbooks. Owner has **not** yet confirmed v0.2.287 installed. The exact v0.2.286 predecessor may have arrived by either v0.2.284 roll-up or v0.2.285 direct upgrade; both use the same v0.2.286 frontend stylesheet.

## Corrective release awaiting owner installation

**v0.2.287**, strict gate **v0.2.286 → v0.2.287**, **2 frontend-only changed files**, **0 DB migrations**. This corrects compact Property & Inventory navigation and unifies Division Consolidation blue/navy table contrast. Package SHA-256 `617e3d60c060e20fedae9d21bb35b85f945aa977258af150155311f74d4cd0c6`.

- [v0.2.287 release notes and UAT](V0.2.287_PROPERTY_DIVISION_UI_THEME_CORRECTION_UAT_2026-11-06.md)
- [v0.2.287 ZIP installer](https://drive.google.com/file/d/1KqH5Aq1SkhlFylaRhmaiObcwdsfPaT1g/view)
- [v0.2.287 installation guide](https://drive.google.com/file/d/1gVeVEtdyXsDG2j4eZgF4jpCUA0sXdOx4/view)

Root cause was confirmed by reproducing v0.2.286 CSS in Chromium: legacy CSS retained `flex-direction: column` and `width:100% !important` on Property nav buttons even after new flex display. The patch explicitly overrides both plus legacy teal states, and styles separate Division Consolidation sections.

**36 Chromium layout assertions passed** for desktop/tablet/mobile fixture views. Authenticated live school and Division acceptance remain pending. No server PHP, APIs, React, permissions, stock, finance, document generation or database contents changed in this patch. No production website modified directly by assistant.

## Confirmed functionality and forward work

Earlier owner-confirmed tests include v0.2.280 paper-only CO receiving, v0.2.281 refresh/navigation, v0.2.282 transfers, v0.2.283 custodian names and v0.2.284 Textbooks teacher issuance/disposal/reports. v0.2.285 Book Directory Ledger was included in the v0.2.286 roll-up path where required.

After v0.2.287 owner testing, verify Super Admin read-only viewing of every authorized office, Historical Property original fund source, and Division/School UI parity; then continue Property and Inventory workflows. Preserve independent website and SQL backups and use version-gated ZIP updates only. GitHub main is a documentation index, not deployable production source.
