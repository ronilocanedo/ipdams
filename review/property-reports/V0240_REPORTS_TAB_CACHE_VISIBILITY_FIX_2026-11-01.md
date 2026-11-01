# IPDAMS v0.2.240 — Property Reports navigation hotfix

Owner installed v0.2.239, but screenshot shows Property Accountability's original tabs without Reports or the new guide link, while the left version badge reads 0.2.239.

## Cause
The v0.2.239 reporting package updated `public/assets/app.js` to add a separate external report-center link, **but did not update** `public/app.html`'s `app.js?v=0236-property-transfers-tab` asset URL. Existing browsers may continue loading the cached pre-239 JS. It also added no actual tab to the `inventory-tabbar`.

## v0.2.240 targeted correction
Version-gated updater from **0.2.239** only, code-only, 2 frontend files; **zero migrations**:
1. `public/assets/app.js` = SHA256 `c719a9e20fe3863356210210fe0ab796da4b91fe757bce5ccd384e523b2bf435` — place a compact **Reports** tab after the first property tab (when not VIEWER). This tab links to the existing authenticated organization-scoped report endpoint and Annex A.1. Do not iframe it: the server sends X-Frame-Options DENY.
2. `public/app.html` = SHA256 `0ac6310ef37167edf97139b6218bbbe5794fffba09d4e29c55261cfb9e2a854f` — update app.js cache query from `0236-property-transfers-tab` to `0240-property-reports-tab`.

Artifact: `IPDAMS_v0.2.240_PROPERTY_REPORTS_TAB_FROM_v0.2.239.zip`
SHA256: `de2504fd5089c7cbf6029072c75ea679d1c26ceca720b1e833e799343bbf376e`
3 ZIP entries (two app files + release.json), no changes to backend reports, ICS/PAR, access scopes or any database schema.

**Validation completed locally:** Node `--check` passed on 891KB app.js; ZIP CRC and manifest file hash verification passed; static assertions confirm exact tab insertion, report endpoint links, updated cache key, and zero migrations. **Actual hosted browser click / endpoint and report DB query UAT not yet tested.**

Install through existing updater on exactly 0.2.239 with verified backups and preflight. Stop on source drift. Verify Reports tab appears, click Open Property Report Center, verify correct school and no server error. Do not claim completion of COA annex print formats; all remain preliminary reports.

## Code change details (minimal)
```js
// After existing optional Property Transfers tab addition:
if(canWrite)tabs.splice(1,0,['reports','Reports']);

// New 'panel' entry, adjacent to 'transfers':
reports:()=>h('section',{className:'surface'},
  h('h3',null,'Semi-Expendable Property Reports'),
  h('p',{className:'body-muted'},'Open the report center for Annexes A.1, A.4, A.5, A.6, A.7, A.8, A.9 and A.10. Official COA forms, approvals and custody balances still require verification.'),
  h('div',{className:'inline-actions',style:{display:'flex',flexWrap:'wrap',gap:'10px'}},
    h('a',{className:'outline',href:'api/semi_expendable_reports.php?organization_id='+encodeURIComponent(this.state.organization_id),target:'_blank',rel:'noopener noreferrer'},'Open Property Report Center ↗'),
    h('a',{className:'outline',href:'api/semi_expendable_reports.php?organization_id='+encodeURIComponent(this.state.organization_id)+'&annex=A1',target:'_blank',rel:'noopener noreferrer'},'Annex A.1 · Property Card ↗')),
  h('p',{className:'hint'},'Reports open in a separate browser tab for secure full-page printing. Preliminary evidence is clearly labeled; no records are modified.')),
```

GitHub branch is a review coordination branch, not yet a complete v0.2.239 source mirror. Installer ZIP source is grounded in the existing package and original app.html. No GitHub Actions run.
