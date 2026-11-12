# IPDAMS v0.2.262 — Sidebar Action Center for incoming property transfers

Owner confirms v0.2.261 installed but receiving acceptance not yet tested. Request: receiving school/Division should see pending transaction notifications in a new sidebar Action Center, instead of needing to discover pending transfers inside Property Accountability.

## Version-gated code-only candidate

- ZIP: `IPDAMS_v0.2.262_ACTION_CENTER_INCOMING_TRANSFERS_FROM_v0.2.261.zip`
- SHA256: `28439072d0434bfe5cb126869e47d8f99d54e75451de920ab0b6f4d3ef518b1f`
- Requires v0.2.261, installs v0.2.262. Five application files (`app/ActionCenterWorkflow.php`, `public/api/action-center.php`, `public/assets/action-center.css`, `public/assets/app.js`, `public/app.html`) plus release manifest. No migrations, official record changes, financial changes, acceptance permission changes, or automatic document actions.

## Behavior / boundaries

- New Action Center under Overview, small sidebar pending-count indicator, school/Division-specific pending-transfer listing with document number, sender, property count and direct inspect link. New GET-only authenticated server query derives notifications from authoritative latest ITR/PTR snapshots, not a parallel table.
- The chosen workspace is always the exact receiving organization. Division oversight does not include transfers addressed to its schools. `Outside IPDAMS` names and already `RECEIVED` transfers never become pending receiving-office actions.
- A formally bound receiving Property Custodian sees `Inspect / Accept`; other scoped viewers see `View pending transfer`. Super Admin cannot accept in the underlying intake workflow. Incoming-transfer actions remain bound to the receiving custodian role and real receipt.
- Refreshes when organization is selected, Action Center opened, visible browser tab resumes, and approximately every 120 seconds while visible. Read errors show explicitly and never claim zero pending work. Workspace switches invalidate old results.
- Existing v0.2.261 receiving acceptance and complete cross-office property provenance are unchanged and **still require authenticated real MariaDB/site acceptance testing**.

## Validation

- **14** executable PHP mock DTO/permission/queue assertions PASS.
- **22** executable frontend route/notification/accessibility/error and stale-workspace assertions PASS.
- Prior **50** receiving-acceptance simulated transaction assertions and **31** transfer destination/regression assertions plus authorization/printing tests PASS.
- PHP and full app JS syntax, CSS parse (18 rules), version gate and ZIP CRC/SHA256 PASS.
- Real-hosting browser, live PHP/MariaDB and actual Division/school receipt UAT pending. No GitHub Actions triggered, no host database writes, no live deployment. PR #16 remains a draft and the checkpoint does not represent synchronization of the entire private site source.

Full test and install guidance is downloadable as `IPDAMS_v0.2.262_ACTION_CENTER_RELEASE_NOTES.md` in the current conversation.