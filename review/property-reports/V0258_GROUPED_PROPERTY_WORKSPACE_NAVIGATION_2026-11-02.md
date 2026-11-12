# IPDAMS v0.2.258 — Grouped Property Accountability navigation

Owner confirms v0.2.257 installed/working, requests eliminate horizontal overflow by grouping Property Accountability tabs with sublevel navigation.

This UI-only release reorganizes the same role-scoped tabs into four groups:
- **Register & Review:** Asset Register / read-only Division special register, Review Newly Received, Earlier IAR Receipts, Historical Property.
- **Custody & Returns:** Issue ICS/PAR, Issued Slips, Property Transfers, Return Property.
- **Condition & Disposal:** direct condition / disposal workflow.
- **Reports:** direct COA annex reporting tab.

Secondary tabs wrap rather than overflow. Switching primary groups remembers the most recently visited subtab. Existing section IDs, assets fetching, report-shortcut navigation, audit, receipt, transfer, return, ICS/PAR, permission, numbering, document and disposal behavior remains unchanged.

**Install only on 0.2.257.** Artifact: `IPDAMS_v0.2.258_GROUPED_PROPERTY_TABS_FROM_v0.2.257.zip` SHA256 `c692a160ff3d98f3defd0ef3fe4d3ef65af616a7fcc82955e66beaabb7550e31`. Contains updated `public/assets/app.js`, `public/app.html`, new `public/assets/property-workspace-navigation.css`, manifest; **zero database migrations**. Existing school and Division permissions are used to decide available sub-tabs; no new grant mechanism.

Validation: complete app.js passes Node syntax; **27 executable navigation/group state assertions** pass; CSS parser passes; headless Chromium isolated nav layout at 1440 / 900 / 650 / 390 / 320 px shows no horizontal page overflow; all installer hashes, gate and CRC pass. Authenticated live website UAT still pending; no live database writes, deployment, or GitHub Actions. Full private site source is not claimed to be synchronized by this review checkpoint. PR #16 remains draft. Complete release notes were included as a downloadable `.md` in this conversation.
