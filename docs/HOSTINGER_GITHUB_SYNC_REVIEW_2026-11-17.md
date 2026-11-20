# IPDAMS — GitHub Desktop source reconciliation (review only)

**Date:** 2026-11-17 (Asia/Manila)
**GitHub working branch:** `develop/hostinger-v0.2.375`
**Production target inspected:** `ipdams.depedtagb.online` (Hostinger, read-only listing)
**Independent source:** Google Drive `IPDAMS-DV-Local-QA/qa/source/website/public`

## Candidate source changes

| Path | GitHub v0.2.375 baseline | Drive QA source | Hostinger listing | Finding |
| --- | --- | --- | --- | --- |
| `app.html` | 6,270 bytes, Git blob `fb509e263e1bcd209c1a9eeadb37e6fb1608d229` | 6,339 bytes | 6,339 bytes | Adds a 69-byte `<script>` reference to `branding-missing-display-377.js` |
| `assets/property-workspace-navigation.css` | 4,223 bytes, Git blob `23cbd5280a6a0982edb0ab03c1e95aeedd1c54ca` | 6,039 bytes | 6,039 bytes | Appends mobile-scoped v0.2.378 Property Accountability CSS; existing 4,223 bytes unchanged |
| `assets/branding-missing-display-377.js` | Absent | 3,139 bytes | 3,139 bytes | New fallback for failed logo/icon loads |

GitHub preimages were **byte-for-byte reconstructed** by removing only the new HTML script and the appended CSS. Git blob hashes match the actual GitHub branch. The three candidate artifacts therefore have verified provenance from the Drive QA source.

**Important limitation:** Hostinger's current file-list endpoint gave matching sizes, but its content reader returned no usable file data. Matching sizes are not hashes. This package does **not** independently prove that Hostinger bytes match the Drive QA snapshot, or authenticate the installed release number v0.2.378. No database files or financial records were read.

## QA preservation

- Saved comprehensive suite: **162 PASS, 0 FAIL, 8 SKIPPED** (reported by `overall-summary.json` on 2026-11-16).
- Two synthetic claims: 128/131, consolidated DRAFT DV 13, PHP 359.75.
- Negative reimbursement and authorization tests already reported PASS. The most recent fictional `dv.finalize` attempt was **BLOCKED**: HTTP 422 `Required nonempty accounting (max 100)`.
- Successful DV finalization, check release, bank settlement, posted journals, tax transfer and concurrency **NOT TESTED**.
- Legacy Playwright references are stored as `.txt` so they cannot accidentally execute from repo; activation requires an isolated QA plan.

## Change boundaries

- **Review-only**. This is NOT a Hostinger installer, a complete production-source synchronization, or accounting-finalization patch.
- This candidate modifies no APIs, databases, core PHP services, fund balances, signatories or official numbering.
- No Git commit/push, merge, deployment, migration or production test is authorized by the package.
- Before release, compare the complete code-only production export by SHA-256, review sensitive paths and establish approved source baseline.
