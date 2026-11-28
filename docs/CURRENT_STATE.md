# IPDAMS - Current development state / active handoff

**As of:** 2026-11-28 (Asia/Manila)
**Stable source:** v0.2.468, owner-confirmed installed and working; GitHub promotion explicitly approved. Live hashes and full post-installation reconciliation independently unverified.

- Parent Main: 08f3ebe5fc388b392f7c1f2502a851fbc910934c (v0.2.464). Consolidates v0.2.465 retained-tax and withholding views, v0.2.466 review diagnostics, v0.2.467 historical mode compatibility and v0.2.468 confirmed-retention format compatibility. Seven modified and one added application files, 275 unchanged, 283 total. No migration or financial writes.
- [Final manifest](checkpoints/2026-11-28-v0.2.468-source-manifest.json); separate intermediate manifests and QA records. Historical targeted tests: 85, 96, 99 and 139 passes, not additive. Actual authenticated HTTP/MariaDB integration and installer rollback untested; no production tests.
- Historical `NON_IUS_CONFIRMED_RETAINED_WITHHOLDING_NET_BANK_CASH` uses original confirmed component IDs, prior consumed/remaining retention rather than newer same-DV withholding fields. Separate validation paths; original policy, matching snapshot and audit, gross/net and source evidence must reconcile. Reporting offset only, capped at current ordinary deficit. Incomplete evidence remains reviewable. No duplicate allocation, cash, remittance or changes to original DV. Confirmation not equivalent to usage or settlement. Shared withholding report and existing Disbursements remittance workflow remain. Division-wide and individual-download logic unchanged.
- Preserve Actual APP per fund and DV-date range with current paid status only, gross amounts, consolidated accounting titles and travel/training; no invented quantities. Preserve earlier integrated finance, Appendix 43, grouped Reports and textbook access. No online liquidation submission/approval. Fund projections not certified balances or spending authority.

## NEXT ACTION

Continue from verified v0.2.468; next proposed v0.2.469. Address owner-reported discrepancies from actual source. Maintain explicit owner approvals, synthetic-only isolated QA, financial integrity and no automatic deployment.

