# v0.2.237 bootstrap-path correction — 2026-11-01

A source audit of the owner-uploaded `ipdams.zip` revealed that the previous report endpoint used `dirname(__DIR__,2).'/app/bootstrap.php'`, inconsistent with the deployed `api/monthly_issuances.php` pattern.

The corrected review artifact is `IPDAMS_v0.2.237_BOOTSTRAP_PATH_FIXED_REVIEW_FROM_v0.2.236.zip`, SHA256 `e2d99b0a1952fa14382df859b90b07c6a6c701913716646c1b702d50a9f74db8`.

The two endpoint include expressions now use `dirname(__DIR__).'/private/app/bootstrap.php'` and `dirname(__DIR__).'/private/app/SemiExpendableReports.php'`, matching the active root-level `api/` layout. The package remains two additive application files with format-1 manifest and no migrations. Both PHP files passed CLI syntax checks; bootstrap path consistency test passed; ZIP CRC passed.

**This is NOT a finished eight-annex COA property-report release**, nor has it undergone authenticated role or database testing, deployment simulation, template print fidelity review, or installed-site acceptance. Earlier v0.2.237 review ZIPs should not be used. Do not install this artifact before full UAT and updater mapping confirmation.

Prioritize source-to-movement linkage, dated opening balances and true custody reconciliation, exact official annex layouts, workflow authorization, and the five pending forms before final acceptance. No GitHub Actions or production changes were performed.
