# IPDAMS — Current development state / active handoff

**As of:** 2026-11-26 (Asia/Manila)
**Stable installed source:** v0.2.454, owner-confirmed; Main application files unchanged (258 verified hashes).
**Latest development milestone:** Integrated Finance R4; packaged v0.2.456 capability bridge and v0.2.457 integrated release candidates, not installed.

See [R4 development acceptance and release candidate checkpoint](checkpoints/2026-11-26-integrated-finance-r4-release-candidate.md) for tested scope, package hashes, evidence and limitations. Synthetic acceptance 302/302 across 18 suites; 75 pure checks independently rerun, not additive. Previous stable [source manifest](checkpoints/2026-11-26-v0.2.454-source-manifest.json) and [QA](checkpoints/2026-11-26-v0.2.454-qa.json) remain the installed baseline records.

## NEXT ACTION

Production read-only schema compatibility, site/database backups, historical allocation onboarding and executable-cache refresh plan. Two-stage owner-authorized installation, confirm actual operation, then exact-source promotion to Main. Keep R4 candidate source and evidence isolated until then. No automatic deployment, no production testing, no manual DDL or file-only rollback after migrations. No online liquidation submission/approval workflow. Do not count synthetic test passes as production verification.
