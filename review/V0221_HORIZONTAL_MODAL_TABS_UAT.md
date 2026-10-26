# IPDAMS v0.2.221 — Property Details modal tab layout hotfix

**Status: local candidate prepared, NOT installed or production deployed.** Owner confirmed v0.2.220 installed on a staging website, with Details working except four vertically stacked tabs.

## Scope and evidence

The reviewed v0.2.220 stylesheet had an intended flex navigation row, but the owner screenshot showed every tab occupying its own vertical row. This indicates conflicting CSS cascade; exact production computed styles were not collected. New modal-specific rules force horizontal grid layout and prevent global nav/button styling from stacking tabs. On screens <=700px, all tabs remain in one horizontally scrollable row.

**Sequential package:** `0.2.220` → `0.2.221`. Exactly two frontend paths: `public/assets/property-detail-modal.css` and `public/app.html`. Zero SQL migrations, no React/app.js, API, property rows, accounts, custody or audit changes.

**ZIP SHA-256:** `450d2fb5d91d16873977aa4730f3233fcea394585a2a7f87b51b9c9ab927f3d9` (3919 bytes), generated and verified locally in the current conversation. The ZIP is available as a direct conversation download; **it is NOT uploaded to Google Drive/GitHub**, so do not mistake the older v0.2.220 Drive candidate for this ZIP. Exact [review-only CSS patch](V0221_MODAL_TABS_REVIEW.diff).

**Static/package verification:** v0.2.220 predecessor file hashes matched its release.json; v0.2.221 manifest hashes and ZIP CRC passed. Chromium isolated CSS-cascade test: original stylesheet stacked tabs vertically in a hostile global nav rule fixture; corrected version displayed four side-by-side tabs at viewport widths 1100px and 700px, and one horizontally scrollable row at 375px. Desktop corrected screenshot inspected visually. This does not replace authenticated staging acceptance.

## Owner acceptance after install

1. In Settings > System Updates stage and install v0.2.221 on same existing staging site from installed v0.2.220. Verify 2 files/0 migrations.
2. Hard refresh browser, open Property Accountability > Asset Register and Details of existing Colored Printer.
3. Confirm Overview, Acquisition, Registration batch, Custody & audit appear in **one horizontal row**.
4. Click every tab and confirm contents, batch-member navigation, Escape/Close, and property filtering still work.
5. In a narrow browser window/phone, swipe horizontally through the four tabs instead of seeing vertical stacking.
6. Ensure saved first and second property locations remain Room 103/Room 102; this update is presentation-only.

No duplicate staging sites, data resets, paid CI runs or production deployment.
