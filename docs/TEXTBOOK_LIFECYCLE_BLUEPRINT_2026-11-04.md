# IPDAMS · Textbook Lifecycle Blueprint

**Prepared:** 2026-11-04 · **Source baseline:** read-only inspection of the live v0.2.269-era Textbooks and Division Consolidation code; the owner's v0.2.270 installation remains unconfirmed. **Status:** proposed workflow and development plan, not an installed feature or approved accounting policy.

## 1. Outcome and boundaries

Track every edition/ISBN from its first authenticated receipt to its current physical holding office, teacher custody, reallocation, damage/loss review, and authorized retirement. Provide division-level visibility by title and school **without double-counting books in transit or under teacher custody**. Preserve every original transfer, delivery, report, and approval. Keep individual learners and their identity-level issues/returns **outside IPDAMS**; the teacher uses School Form 3 (SF3) and the system records only aggregate reconciliation and the official SF3 document reference.

The system must distinguish:
1. **Ownership / holding office**: Division Office or an individual school.
2. **Custody**: property custodian / named teacher of an office-owned quantity.
3. **Location and physical condition**: store, in transit, teacher-held, damaged quarantine, etc.
4. **Accountability and formal relief**: documentary issuance, pending loss, approval, COA relief if required.
5. **Catalogue identity**: title/edition/ISBN is not a stock batch, movement, or teacher assignment.

## 2. Confirmed baseline and immediate risks

Current website sources expose:
- `textbook_titles`, `textbook_batches`, `textbook_movements`, and `list(kind=textbook_batches)` / paginated `list(kind=textbook_movements)`.
- `Domain::textbookOpening()` **inserts a new textbook_titles record for every opening batch**, with free-form optional ISBN. This does not ensure a single unique canonical title per ISBN.
- `Domain::textbookMove()` immediately posts quantity deltas for `RECEIPT`, `RETURN`, `DISTRIBUTION`, `TRANSFER`, `LOST`, `DISPOSAL`, and `COUNT_ADJUSTMENT`. The general form requires only a reference, and cannot verify a receiving school, appointed teacher, Division approval, physical receipt, or authorized write-off.
- Division Consolidation already provides `TEXTBOOK`, `TEXTBOOK_EVENT` and `TEXTBOOK_TOTALS`, but current report outputs are **batch and movement aggregates**, not source-to-destination transfer proofs or teacher-accountability totals.
- `DivisionSchoolAccess` restricts default Division-user school operations; any new school-to-school workflow must explicitly enforce named permissions and scope.

**Safety boundary:** Do **not** use the legacy generic movement form to declare lost/disposed books or interoffice transfers. Those actions require the documented workflows specified below. Do not silently convert old rows or assume all existing ISBNs are unique.

## 3. Book directory — canonical ISBN

One shared, read-only-to-schools **book edition directory** accessible to the Division Office and every authorized school. `ISBN-13` is canonical for books with a valid ISBN; accept an ISBN-10 only after check digit validation and conversion where appropriate. Strip display hyphens and spaces before uniqueness checks, while preserving the original printed string as metadata. Validate both ISBN check digits; treat 979-prefix books correctly (no ISBN-10 conversion). A single ISBN identifies one edition/product; the **same ISBN is reused across all batches and schools**. Different editions/ISBNs must remain distinct even when titles match. Include title, publication/edition, publisher, subject, grade/level, language, material class (TX/TM/IM), optional official resource code, active state, and auditable corrections. An official material without an ISBN receives an **LR code**, not a made-up ISBN; avoid title-only deduplication.

Initial directory submissions may be made by School/Division staff, but unresolved duplicates or contradictory bibliographic fields should enter a **review queue** for the Division catalogue steward. A Division-curated ISBN library should not disclose another school's inventory or individual teachers. DB-level unique normalized ISBN and transaction-safe creation are required **after** auditing existing `textbook_titles` rows, absent ISBNs, legacy duplicates, and in-use `title_id` references. Never merge/rekey old title IDs without approved mapping and independent backup.

## 4. Proposed transactions and states

### A. Central Office → Division or direct-to-school receipt

Central Office is recorded initially as an **external source** with official delivery/packing-list reference; do not create an impersonable Central Office login or invent a transferred source balance. Record ISBN/title, batch/lot, expected copies, actual accepted copies, shortage/damage on arrival, date, delivery reference, receiving custodian and physical verification. DRAFT → SUBMITTED → VERIFIED/ACCEPTED, or DISCREPANCY. Only accepted serviceable copies become available; damaged-on-arrival copies are quarantined and separately accounted for. A direct-to-school receipt is attributed to that school; its Division can see the summary through authorized reporting.

### B. Division → school distribution

Division custodian prepares allocation for a school, checks uncommitted available stock, submits, and records approval. On dispatch, reserve/debit from available and create **in-transit** movement; the school receives in its own Action Center, inspects and accepts actual copies (possibly partial). Accepted quantity credits only the destination office, with matching source transfer and receiving records in the same immutable transaction chain. Discrepancies stay open, not silently added. **Never count both source on-hand and destination on-hand for the same in-transit copy.**

### C. School surplus offers / requests

School identifies *available, unassigned, serviceable* extra copies and creates an offer by ISBN, condition and quantity. Other schools can request; a Division authorized officer approves reallocation. Dispatch, in-transit, acceptance and discrepancy handling follow B. Offers do **not** immediately debit stock, but accepted commitments reserve against available; teacher-assigned copies are ineligible until formally returned. No cross-Division school-to-school flow without a separately authorized workflow.

### D. School → teacher custody

Select an active teacher from the current school employee directory (not arbitrary free text), academic year/term, grade/section where useful, and title/batch/quantity. The school property custodian issues the copies against a signed acknowledgment; teacher accepts, or the custodian records verified physical acknowledgment with its paper reference. School retains ownership; quantities become *teacher held*, not *removed from school ownership*. Teacher submits end-of-term reconciliation with total issued to learners, returned, damaged/unreturned and SF3 file/reference. No learner names, IDs, individual issuance dates, or student payment details stored in IPDAMS.

### E. Damage and loss — formal approval, never an instant deduction

School custodian/teacher files incident with title/ISBN, quantity, date, actual circumstances, SF3 and documentary references. **Reported** ≠ **substantiated** ≠ **approved** ≠ **relieved/retired**. Pending incidents remain visible in accountability; do not silently reduce total owned holdings. Damaged physical copies are returned to the property custodian and placed in a *quarantined/damaged* pool, excluded from serviceable availability but still physically counted. The Division property authority reviews the school report and evidence. Disposal requires the legally competent committee/authority and supporting documents; Division approval alone must not bypass higher requirements. Lost books follow DepEd accountability-relief and COA process when applicable. No automatic teacher fine, payroll deduction, liability determination, or automatic relief.

### F. Reconciliation and closure

At term end, reconcile school held balances, teacher-held custody, SF3 aggregates, damaged quarantine, pending losses, confirmed disposals and dispatched/received transfers. Every discrepancy has a trackable task, not an unaudited count adjustment. Corrective transactions reference earlier entries; signed/finalized events are append-only. Every custody/stock transition must be idempotent and transaction-safe.

## 5. Minimum data objects (proposed, NOT live DDL)

- `textbook_catalog_editions`: canonical ISBN, normalized unique key, edition fields, Division steward review, official code when ISBN absent.
- `textbook_receipt_documents` / `..._lines`: external source, office, expected/accepted/short/damaged quantities, delivery evidence and immutable accepted snapshots.
- `textbook_transfer_documents` / `..._lines`: sender/receiver, offer/request/allocation, submitted/approved/dispatched/partially received/received/rejected states, evidence, reserved/in-transit/accepted quantities.
- `textbook_teacher_assignments` / `..._lines`: school, **active employee ID and school assignment**, academic period, issue/return/reconciliation, SF3 summary reference, signed acknowledgment evidence.
- `textbook_incidents` / `..._decisions`: report, inspection, photographs/document references, Divisional review, formal relief and disposal authority, decision trail.
- An append-only **inventory event ledger**, normalized by office, catalog/lot/condition pool, transaction reference and immutable delta. Existing `textbook_titles`, `textbook_batches`, `textbook_movements` must be carefully reconciled and retained. No bulk destructive migration; avoid dual posting by choosing a single authoritative balance source before introducing new writes.

**Balance invariant:** for each ISBN/lot, stock must reconcile between office storage (serviceable/quarantined), teacher custody, documented in-transit items, and formally resolved loss/disposal. *School-owned teacher-held copies remain included once in the school total.* “Available” equals serviceable physical storage minus reserved commitments, not total school-owned stock.

## 6. UI / responsibilities

**Division Textbooks:** `Book Directory` · `CO Receipts` · `Distribute to Schools` · `School Offers & Requests` · `Incoming Transfers` · `Incidents & Disposal Approvals` · `Division Title Breakdown` · `Audit Trail`.

**School Textbooks:** `Book Directory` · `My School Stocks` · `Incoming Receipts / Action Center` · `Extra Books / Request Books` · `Teacher Accountability` · `Report Damage / Loss` · `Return to Custodian` · `Movement History`.

**Teacher-facing:** no separate account required initially. The school's custodian may record a signed teacher acknowledgment against an existing employee and its actual signature/document reference. A limited teacher login can be considered later if operationally needed; no new identity or password is inferred from an employee record.

**Action Center:** receiving acceptance, requests pending Division approval, discrepancies, and incident/disposal decisions. Avoid badges over labels; statuses must be scoped to the logged-in office and appointed function.

**Division title breakdown** (each ISBN): total externally accepted, Division physical stock, allocated/dispatched/in transit, school physical stock, teacher-held, quarantined/damaged, unresolved loss, formally retired, and per-school details. Authorized Division officers can view only the permitted schools. Never sum ISBN editions with different identifiers. Use period filters only for dated movements; balances remain live unless historic snapshots are implemented.

## 7. Access / audit / privacy

- All current-workspace actions validate the **actual assigned** School/Division role, custodian appointment and permitted organizational scope on the server; `SUPER_ADMIN` does not silently impersonate a physical custodian.
- Teacher assignment references the selected school's employee assignment; signed paper evidence required for custody transfer when teacher has no login.
- Division approval cannot be done by the same user who prepared a transaction where separation of duties is configured; require an actual approver appointment.
- Lock source balances/commitments, reserve in a stable transaction order, and use version checks and idempotency keys for all writes. Prevent replay, over-allocation, approvals of changed drafts, and transfer re-acceptance.
- No live learner identity data in the textbook module. SF3 is an external controlled school document; do not upload student-level SF3 scans into broad Division reports by default.
- Uploaded evidence must be malware/size validated, private access-controlled, immutable by finalized document revision, and audit-referenced.

## 8. Controlled build and acceptance sequence

**Gate 0 · Current safety:** disable or strongly gate the *legacy generic* textbook `LOST`, `DISPOSAL`, direct `TRANSFER` and unsourced adjustment methods before real textbook stock is used for final transactions; current functionality cannot meet documentary approval obligations. Preserve all old records. No new write-off capability before formal approvals.

**Release A · ISBN directory and legacy reconciliation:** audit existing ISBN duplicate/empty values; create/validate canonical ISBN directory with one edition per ISBN; map existing titles without destructive rewrite; searchable by school/Division; no stock mutation. Test ISBN-10/13 equivalence, concurrent creation, duplicate metadata conflict, special official code.

**Release B · authenticated Central Office receipts:** support Division/school direct receipts, partial acceptance, damage on arrival and immutable evidence; no fiction of an IPDAMS Central Office custody account.

**Release C · physical interoffice distribution:** Division allocations, school-to-school offers, reservation/dispatch/receipt and action-center approvals; include partial/reject cases and stock conservation.

**Release D · teacher accountability and SF3 reconciliation:** issue/return to active assigned teacher, group by grade/section/term, school-owned count maintained, SF3 summary reference only, audited status.

**Release E · incident and disposal decisions:** report/inspect/quarantine/Division review/formal disposition, no automatic personal fine or inventory write-off; authority evidence and COA relief where applicable.

**Release F · Division reporting:** per ISBN / per school title matrix, exceptions, reconciliation and export, totals reconciled to office-level ledgers. Existing Consolidation `TEXTBOOK` reports should transition only after ledger and data checks.

**Testing sequence:** At each gate verify own-office authorization and cross-school denial, double-post prevention, transaction rollback, stock cannot go negative, in-transit only counted once, partial receipt, duplicate ISBN rejection, teacher return, evidence-locked loss, no premature disposal, and immutable source histories. Use independent site + SQL backups and the normal Settings → System Updates predecessor gate; do not deploy unreviewed GitHub files directly to Hostinger. No additional staging website or paid GitHub Actions are necessary for initial synthetic tests.

## 9. Regulatory basis and review boundary

- **DepEd Order 14, s. 2012**, guidelines on distribution, care, recording, retrieval and disposal of textbooks, teacher manuals and instructional materials: https://www.deped.gov.ph/2012/02/10/do-14-s-2012-policy-and-guidelines-on-the-proper-distribution-care-recording-retrieval-and-disposal-of-textbooks-txs-with-the-teachers-manuals-tms-and-other-instructional-materials/
- **DepEd Order 25, s. 2003**, Resolving Losses of Textbooks: https://www.deped.gov.ph/2003/04/04/do-25-s-2003-resolving-losses-of-textbooks/
- **DepEd Order 23, s. 2001**, no teacher fines for *normal* textbook loss by learners: https://www.deped.gov.ph/2001/05/21/do-23-s-2001-loss-of-textbooks-by-students/
- **SF3: Books Issued and Returned**, DepEd school forms field descriptions: https://catanduanes.deped.gov.ph/files/School-Forms-Data-Field-Descriptions.pdf

The ultimate approval chain, signatories and disposal delegation must be confirmed with the relevant current DepEd and COA issuances and the owner's Division Property Officer before production-write features are enabled. This blueprint does not establish new personal financial liability or override prescribed approvals.
