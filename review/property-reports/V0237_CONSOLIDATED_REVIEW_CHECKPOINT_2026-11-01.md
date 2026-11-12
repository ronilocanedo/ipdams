# v0.2.237 consolidated review — 2026-11-01

Baseline: user-uploaded IPDAMS website archive identifies v0.2.236. No version bump beyond 0.2.237 because prior v0.2.237 reviews are uninstalled.

Latest candidate: `IPDAMS_v0.2.237_PROPERTY_REPORTING_CONSOLIDATED_REVIEW_FROM_v0.2.236.zip` SHA256 `efaf6dd93ad7e9e0da00351f9931dc8febb434588ab630287c7acfbac5462010`.

Manifest: format=1, from_version=0.2.236, version=0.2.237, min_php=8.2, extensions=[pdo], no migrations, only two new app files:
- `app/SemiExpendableReports.php` SHA256 `ee2ea0bbb37e5f5c509236d5a2e5b32d21a6519e9291e3eb096e4ea288b96c5a`
- `public/api/semi_expendable_reports.php` SHA256 `0c7be8d0230057fb7998a5432da340ea82d11dec07e469c675aba876ccab9b82`

Fixed: movement-count evidence now uses report-through exclusive timestamp, and non-ISSUE movements are restricted to those recorded from ICS creation up to the period cutoff. Movement counts remain asset-associated, not causally linked to specific ICS. No assertion of ownership/legal custody.

Local verification: PHP syntax 2/2 passed, static checks 15/15 passed, ZIP CRC and hash manifest passed. No authenticated DB UAT, no installer dry-run, no official annex printing, no signatory approvals; *not cleared for installation*. No GitHub Actions used. Earlier 0.2.237 review candidates are superseded for review purposes.

Important: This does **not** implement all eight official COA annexes. It is an ICS evidence register foundation only. Further work requires explicit transaction linkage, official A.1/A.4/A.7 layout mapping and the remaining five forms, end-to-end database tests, and owner staging acceptance.
