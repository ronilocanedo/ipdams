# IPDAMS v0.2.241 — in-app floating Property Report Center (2026-11-01)

## User feedback and correction
The owner confirmed v0.2.240 Reports navigation works but observed the A.1/A.4 reports open as a **separate web page**, requiring repeated scrolling between report filters/annex selectors and the result. A.4 was initially empty for November 1 because the source movements occurred earlier than the selected period; the owner identified the date filter and requested a floating report window in the real IPDAMS interface.

## Prepared code-only updater
- Artifact: `IPDAMS_v0.2.241_INTEGRATED_PROPERTY_REPORT_MODAL_FROM_v0.2.240.zip`
- SHA256: `a7a690380fc61dd0b2b14e3b4be0eee13b228cfd21dfe7bf6ddac415dd6be67e`
- Requires exactly **0.2.240**, installs **0.2.241**.
- Four source files, **no migrations**: `public/assets/app.js`, `public/app.html`, `public/api/semi_expendable_reports.php`, `public/assets/semi-expendable-reports.css`.
- The React-like Property Accountability interface now opens the Report Center in an on-page accessible modal from the Reports tab and the Property Reports shortcut; it stays in IPDAMS, with close/print controls. Embedded report maintains original organization, date filters and annex tabs across navigation; filters and tabs remain sticky during report scrolling.
- The endpoint grants framing **only when `embedded=1` and only from the same origin** (X-Frame-Options SAMEORIGIN, CSP frame-ancestors self); standalone remains DENY/none. Server-side session, scope and VIEWER denial are unchanged. Uses same-origin embed pattern already used by the Property Transfers tab, no external iframe.
- Changes only navigation/layout and cache version; no edits to final ICS/PAR records, issued numbers, document balances, historical linkage, or underlying reporting data.
- Earlier source/report data are still **preliminary COA review evidence**, not certified eight-annex outputs.

## Local validation
- `node --check` on 892 KB app.js: PASS.
- `php -l` on report endpoint: PASS.
- Eight synthetic PHP rendered-page fixtures (embedded and standalone, A.1/A.4/A.7/A.10) check active tabs, date preservation, embedded query persistence, report output: **8 PASS**.
- ZIP CRC, manifest SHA256, exact from/to version and zero migrations: PASS.
- Authenticated hosted browser modal/print checks: **NOT YET RUN**.
- No GitHub Actions used; no database or website writes from this development step.

## Next owner UAT
If installed on exactly 0.2.240 after normal backup/stage preflight, click Property Accountability → Reports. Confirm modal opens within the same page, change From to 2026-10-01 and Through to 2026-11-01 for A.4, verify date controls remain visible during scrolling, switch A.1/A.4, print preview, and close modal. If iframe is blocked, inspect response CSP rather than disable site-wide security. Do not infer missing movement from a default one-day filter.

PR #16 remains draft until official report accuracy/layout and role tests pass.
