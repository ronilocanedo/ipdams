# IPDAMS v0.2.252 — Compact ICS/PAR property selection rows
2026-11-02. User confirmed **v0.2.251 installed and working**, but screenshot of Property Accountability → Issue ICS / PAR shows huge stretched checkbox controls and property text far to the right.

## Root cause
The property picker rendered generic `check-label` labels and bare checkbox inputs nested under a broad `.field` container whose `input` selectors can stretch checkbox width. Even though the label had `display:flex`, the checkbox could still occupy enormous space, separating the checkmark from the property description and causing inconsistent row heights.

## Version-gated release candidate
- `IPDAMS_v0.2.252_COMPACT_ICS_PAR_SELECTION_FROM_v0.2.251.zip` (SHA256 **`13f8d47b3bc6f556d2a048a46ad3a16fe5ad106fb7fde991c7899c825543bfe7`**).
- Requires installed **0.2.251**; installs **0.2.252**. Code-only; `migrations={}`.
- Includes 3 application files:
  - `public/assets/app.js` SHA256 `b7be8148a25b52e7c7abdd2910ce8bc4045f389b0509c90e249c3beae82deab2`.
  - `public/assets/ics-property-picker.css` SHA256 `29c23d97f9a747e14ff1d42c616983a7f88b9b9fcc9c9aed852410584ca1f249`.
  - `public/app.html` SHA256 `d87153c5f4c059be6a99ce2307a8ae01928e2754824081879ab0962decb3d56a`.

## User-facing changes
- Each property shows a native **17 × 17 px checkbox immediately beside** its property tag, item name, ICS/PAR classification and eligibility status; a single compact selection row replaces the oversized gap.
- Scoped `ics-selection-row` and `ics-selection-check` styles override generic form input width without changing any other checkbox on the site.
- Selected rows subtly highlighted, issued/review-pending entries disabled with explanatory status; mobile text wraps without a horizontal form-grid.
- Exactly the existing 20-per-ICS restriction, 1-PAR restriction, mixed document-type lock, selected-items counter, authorized custodian selection and all backend issuance checks remain unchanged.
- No database migrations, old ICS/PAR edits, asset movement edits, financial changes or changes to the printed ICS/RIS form.

## Checks
- `node --check` on patched ~900KB app.js: PASS.
- 16 executable UI mapping/selection/eligibility assertions (issued, pending review, not active, missing required document, PAR, 20-ICS limit and toggle) against actual `eligible.map()` render expression: **PASS**.
- 22 CSS rule parse: PASS.
- ZIP CRC, SHA256 per file, version gate and manifest/no migrations: **PASS**.
- Chromium headless visual test attempt timed out due to local browser startup; visual alignment and real authenticated browser acceptance on user's site remain **NOT YET VERIFIED**. The owner can confirm a normal-sized checkbox and text immediately alongside after installing.
- No GitHub Actions run. The reviewed source/package artifact lives in this conversation; this review branch documents it and does **not** assert that the latest complete site source is synced to GitHub. Keep PR #16 draft.

## Owner acceptance
Confirm installed 0.2.251 in Technical Settings, back up site/database and ensure clean updater preflight. Install v0.2.252, refresh browser, open Property Accountability → Issue ICS / PAR; verify no oversized checkbox, property number and description aligned on same row, previously-issued property disabled, eligible unissued test property selectable and count updates. No need to create an ICS to confirm layout. Report any remaining horizontal scrolling or overlapping text.

Release notes: `IPDAMS_v0.2.252_COMPACT_ICS_PAR_SELECTION_RELEASE_NOTES.md` in conversation.
