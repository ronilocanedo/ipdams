# IPDAMS Installed Release Checkpoint — 4 November 2026

## Current confirmed baseline (owner report, 2026-11-04)

**Installed IPDAMS: v0.2.281.** The owner confirms v0.2.281 is installed and that **refresh restores the current page/tab and tapping the IPDAMS logo returns to Dashboard**. The owner also confirms the preceding **v0.2.280** CO textbook paper-documentation receiving update has been tested and is working. These are owner-reported functional checks; they do **not** establish exhaustive live MariaDB, stock reconciliation, or adversarial permission UAT.

**Release chain:** v0.2.272 textbook receiving → v0.2.273 full book profiles → v0.2.274 Add Book white-screen fix → v0.2.275 discard audited drafts → v0.2.276 tabbed textbook UI → v0.2.277 book editor action cleanup → v0.2.278 global book search → v0.2.279 automatic textbook IAR → v0.2.280 paper-only physical receiving and optional observations → **v0.2.281 persistent navigation/logo Dashboard**.

- [v0.2.280 paperwork-only CO textbook receiving and optional inspection notes](V0.2.280_PAPER_ONLY_CO_RECEIVING_OPTIONAL_NOTES_UAT_2026-11-04.md)
- [v0.2.281 refresh-navigation and logo-home release](V0.2.281_NAVIGATION_REFRESH_AND_LOGO_HOME_UAT_2026-11-04.md)
- [v0.2.278 shared searchable ISBN/title/author/edition CO receiving picker](V0.2.278_CO_RECEIPT_GLOBAL_BOOK_SEARCH_UAT_2026-11-04.md)

## Owner-approved business rule — RECORDS, NOT ELECTRONIC APPROVALS

IPDAMS **documents physical transactions and authentic supporting paper documents**; actual inspection/signing/authorizations happen outside IPDAMS. Named inspectors, issuing/receiving officials and custodians **do not need separate logins to electronically approve or sign**. An authenticated scoped encoder records their actual names and document dates/evidence; the encoder is logged in the app's audit history. The system must not imply an electronic signature. Preserve server authorization of the encoder, source evidence, per-office scopes, idempotent inventory posting and append-only audit. Do not restore the original electronic submit→inspect→accept approval dependency.

**CO textbook IAR** is linked to the textbook receipt without generating a false local procurement PO. Recording a genuine physical receipt is the stock-posting boundary. Serviceable vs damaged/quarantined vs held/missing must reconcile; drafts and rejected deliveries do not credit stock.

## Next workflow and UAT

1. Verify one authentic CO textbook receipt end-to-end in Division **or** directly receiving school: registered verified book, expected and physically delivered counts, named paper inspector/receiver, document reference/date, **optional inspection observations**, linked automatic printable IAR, and exactly-once serviceable/quarantined stock posting. Confirm source and audit records.
2. If genuine Division stock is available, develop **Division → School distribution** as a separate documented workflow: source stock/lot available check, issued allocation and dispatch evidence, in-transit/reservation tracking, destination school records the actual paper receipt in Action Center, reconciles discrepancies/partial delivery, posted once to receiving school. Users record named paper signatories; no mandatory electronic approval or signatures.
3. After textbook workflows and stock reconciliation UAT, apply the successful compact tabs, floating dialogs and high-contrast table UI to **Property Accountability** and **Inventory Management** in separate narrowly reviewed versions.

## Engineering/deployment cautions

- Current installed version **v0.2.281** is **owner-confirmed**, not independently read back from the live runtime. Do not claim the current source is fully synchronized with GitHub; `main` is documentation/review-oriented, not a complete deployable mirror.
- All future updates must be strictly predecessor-gated from confirmed installed version, use exact matching on-host or user-backup source, preserve independent website and SQL backups, and avoid live production resets, migrations, stock postings or fabricated source records during test development.
- This checkpoint update is **documentation only**; no live website/database mutations or GitHub Actions runs.
