# v0.2.237 read-only semi-expendable evidence package — local review checkpoint

**Candidate only — not deployed or certified; does not complete all eight COA annexes.**

The supplied IPDAMS website archive (2026-10-31) contains installed-version metadata **0.2.236**. Local build artifact:
`IPDAMS_v0.2.237_SEMI_EXPENDABLE_EVIDENCE_FROM_v0.2.236_REVIEW.zip`
SHA256: `f8deca0cb0f6f7d27cc7e3da090637d5df8bc75399a113fba8c99d2457615bf9`.

Two additive application files:
- `app/SemiExpendableReports.php`: scoped read-only finalized ICS evidence reader; identifies each asset and grouped ICS control numbers; excludes drafts/cancelled; rejects unbounded export.
- `public/api/semi_expendable_reports.php`: authenticated HTML evidence review with school/date selectors and browser Print (Ctrl+P).

No migrations, no changes to existing application PHP/JS, no asset movement or accounting writes. ZIP CRC and its declared manifest hashes were checked; both PHP sources pass PHP syntax lint. **No authenticated hosted UAT, database fixture validation, full package installer dry run or exact COA form fidelity acceptance.**

**Explicit limitations:** output is an evidence register, not a signed, COA-prescribed Annex A.1/A.4/A.7. ICS created_at is not a verified signed issue date; returns, transfers, disposals and opening balances are not reconciled. Annexes A.5/A.6/A.8/A.9/A.10 are not implemented in this update. Division-wide access beyond authorized existing organization scopes not granted.

**Follow-up:** reconcile deployed v0.2.236 code and schema with the candidate, do staged install and permission/database UAT, then build transactionally correct custody events, accurate running property balances and official template renderers. Do not merge as a completed feature or deploy by default.
