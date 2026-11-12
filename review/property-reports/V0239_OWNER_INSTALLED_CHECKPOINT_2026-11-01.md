# IPDAMS v0.2.239 — Owner installation checkpoint (2026-11-01)

The owner reports **v0.2.239 Semi-Expendable Property Report Center successfully installed** from `IPDAMS_v0.2.239_SEMI_EXPENDABLE_REPORT_CENTER_FROM_v0.2.238_REVIEW.zip` on the existing website.

**Verification scope:** Owner-reported successful updater installation only. No authenticated post-install report-page, server-side SQL query, school/division scope, browser print, or signatory UAT results have been received yet. Do not mark official COA annexes as completed or approved.

**Previous baseline:** v0.2.238 owner-confirmed installed and Property/Inventory screens loaded normally. Prior 17/17 MariaDB, 24/24 Domain transaction, and updater migration/replay tests concern the v0.2.238 ICS/PAR linkage and do not certify new v0.2.239 report queries.

## Immediate site acceptance: one focused pass
1. Confirm `v0.2.239` is displayed and Property Accountability / Reports screen loads (no white screen).
2. Open Semi-Expendable Report Center with an authorized school selected and a known reporting date range.
3. View A.1, A.4, A.7; verify linked finalized ICS entries, values, and review exceptions against an existing authorized test case. Do not create fake balances or historical links.
4. View A.5/A.6/A.8/A.9/A.10; ensure preparatory/report guidance is clearly labelled, never presented as certified official forms. Existing ITR navigation should work.
5. Verify school access stays scoped; viewer restrictions; division cross-school privileges only for explicitly authorized roles. Check print previews visibly marked preliminary.
6. Report first failing screen/error; if possible include screenshot and name of view.

**Future completion:** Authentic COA annex print/export fidelity, source-to-transaction reconciliation, approval workflows and role acceptance. No new migrations or GitHub Actions needed for this checkpoint. Keep PR #16 draft.
