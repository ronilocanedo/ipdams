# IPDAMS v0.2.249 — interrupted custodian appointment save recovery (2026-11-02)

Owner screenshot confirms v0.2.248 installed on Division workspace. AO/Property Custodian official effective date 2025-11-11 precedes new 2026-11-02 directory entry. Evidence 38/20 characters, historical checkbox checked. Saving shows **Custodian appointment: Failed to fetch**, which is a browser network rejection, NOT the server's normal 422 historical-date rule. The backend already accepts this earlier date with true attestation and >=20-character authentic reference.

## Independent read-only investigation
- Current `api/index.php` route `staff.custodian.save` exists; `Domain::saveSupplyCustodian` accepts genuine older appointments with evidence, versions and audits without rewriting staff history.
- JS `staffSaveOne` uses `this.api` with a single POST; the raw fetch rejection can conceal whether a DB commit happened before the response was interrupted.
- Connected Hostinger hosting settings for owner site report SSL **active**, CDN **enabled**, server-side cache **disabled**; installed app.js listed at 918,970 bytes, matching v0.2.248 patch. These observations do NOT establish the failed POST cause. The hosting connector did not expose a PHP request/error log for the failure. Do not claim a server exception has been reproduced.

## Code-only version-gated diagnostic/resilience package
Artifact `IPDAMS_v0.2.249_CUSTODIAN_SAVE_CONNECTION_GUARD_FROM_v0.2.248.zip`
SHA256 `e791a2eef8c0d4a5527bfd58099066e4e755a70ed63e6e6e47ffefb73b41d37a`.
From **0.2.248** to **0.2.249**, 2 frontend files `public/assets/app.js` and `public/app.html`, no migrations or backend changes. On a `TypeError` network failure for `staff.custodian.save`, perform *one read-only staff.directory* GET in same organization; compare returned new appointment version, employee and effective date to attempted save, showing verified success only when all agree. If unverifiable, explain the ambiguous outcome and request Refresh Data; **do not retry a POST automatically**. Other HTTP/application errors stay unchanged. Bump app.js cache query string. All tests offline; does not claim to correct CDN, browser, PHP or transport failure.

## Checks
- Full Node JS syntax PASS.
- **Eight behavioral cases PASS**: interrupted response after commit, before commit, failed readback, normal HTTP validation error, ordinary success, wrong employee, wrong effective date, other appointment action unaffected.
- ZIP CRC/per-file manifest SHA256 and gate: PASS.
- Hosted real save and network root cause: NOT YET VERIFIED.

## Immediate owner test
Before even installing the update, click **Refresh Data** and inspect the saved AO/Property Custodian office and effective date. If already saved, do **not** repeat. If not saved, v0.2.249 offers safer reconciliation; if fetch interruption persists capture DevTools Network request `api/index.php?route=staff.custodian.save` Status/ERR code only, with headers/body/cookies/redacted. Avoid changing DNS, TLS, CSRF, permissions, CDN settings without hard evidence.

Release notes distributed in current conversation: `IPDAMS_v0.2.249_CUSTODIAN_SAVE_CONNECTION_GUARD_RELEASE_NOTES.md`.
PR #16 remains draft. No GitHub Actions, migrations, live database writes or deployment initiated.
