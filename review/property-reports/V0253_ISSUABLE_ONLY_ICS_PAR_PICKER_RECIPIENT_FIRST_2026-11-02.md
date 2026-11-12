# IPDAMS v0.2.253 — Issuable-only ICS/PAR picker and recipient-first layout

**Owner-observed baseline:** v0.2.252 installed, compact 17px checkboxes working. Screenshot shows issued Computer Table and unissued Desktop Computer Set. Owner requests hide previously assigned properties, move Accountable Employee **before** property search so property rows expand downward, and a selected count.

## Release artifact
`IPDAMS_v0.2.253_ICS_UNASSIGNED_EMPLOYEE_FIRST_FROM_v0.2.252.zip` — SHA256 `fb5f582449cad87949d48097b049ae5edb0d9456a75f2152594bb8d138eff15e`.

**Version-gated 0.2.252 → 0.2.253**, code only, no migrations. Files:
- `public/assets/app.js`, SHA256 `98bd7204ce5761b40eca779cdf928cd6ac7f25a4b5e29466227bbff7fb82d713`
- `public/app.html`, SHA256 `289bcb88ac200079ceef0a07b59f8ba79f8a40536eefb799ecf73eb989039195`
- `public/assets/ics-property-picker.css`, SHA256 `dfb878b1b53e7abac82252ccff7bb7b468646496262d75cd5ea02b06190cfb95`

## Behavior
1. Property Accountability → Issue ICS/PAR now shows **1. Accountable employee / custodian** first, with original authenticated organization-scoped active employee typeahead, then **2. Search unassigned properties**, then **3. Select properties**.
2. The picker filters `assets` to `status=ACTIVE`, reviewed or already cleared (not `PENDING_REVIEW`), a valid required ICS/PAR type, **no actual current custodian**, **no latest accountability number**, and **no finalized accountability document** for the asset. Finalized IDs are indexed once in a client-side Set for efficient lookup. This does not modify or backfill legacy records; previously assigned items remain in **Issued Slips**, reassignments occur through **Property Transfers**.
3. Rows expand down the page instead of scrolling within a 320px-tall box. The heading shows live `N selected` and `N unassigned property/properties shown`; a bottom summary repeats selection count, document kind, and chosen employee when present.
4. Original one-PAR, max-20-ICS, no mixed ICS/PAR and explicit submit semantics are unchanged. Backend issuance authorization remains authoritative.

## Validation
- Node `--check` PASS full current app.js.
- **22 executable rendered-UI and eligibility assertions PASS** including hidden issued/assigned/unreviewed/disposed, search, recipient-first order, counts, item select, PAR one-item, 20-item ICS limit, and empty states.
- Stylesheet 28 top-level CSS rules parse PASS.
- ZIP CRC, hashes, gate, zero migrations PASS.
- **Not verified:** actual hosted browser click/visual end-to-end UAT. No web site/db writes or GitHub Actions. Branch documents release; this is not the full private application source synchronization.
- Preserve owner backup and existing updater preflight; do not install if version or source hashes disagree.

Release notes: `IPDAMS_v0.2.253_ICS_PICKER_RELEASE_NOTES.md` downloadable in conversation. PR #16 remains draft.
