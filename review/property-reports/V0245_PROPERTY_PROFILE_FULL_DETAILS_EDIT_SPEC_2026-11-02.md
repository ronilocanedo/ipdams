# IPDAMS — Unified Edit Property Details, implementation specification
Date: 2026-11-02. Proposal based on current v0.2.244 source; **not implemented**. Last owner-confirmed installed version was v0.2.242; v0.2.244 is a prepared patch, not confirmed installed.

## User request
Allow school officers to edit **a property record as a whole** through an intuitive on-page action from the Property Accountability → Asset Register rather than the fragmented "Review Newly Received" workflow. Preserve finalized ICS/PAR and source accounting integrity, but do not unnecessarily block a simple typo correction merely because ICS/PAR has already been issued.

## Verified existing implementation
- Owner-uploaded baseline `private/app/Domain.php`: `propertyRegistrationDetail()`, `correctPropertyRegistration()`. Existing correction already supports `UNIT` vs `BATCH`, version checks, reason >=12 chars, unique serial enforcement, immutable IAR links/cost/property numbers, and per-asset audit old/new values.
- Existing `correctPropertyRegistration()` **denies all corrections after any accountability document** and allows only `assets.status=ACTIVE`.
- Existing `PropertyBatchMetadata::shared`: brand, model, acquisition_type, useful_life_years, historical_po, historical_iar, historical_dv, evidence_note; `unit`: serial_number, location, condition_note, legacy_property_number, reported_custodian. Item name/classification are derived from `item_catalog` and UACS mapping, rather than per-unit free text. `assets` separately stores item_id, property_tag, acquisition_cost, acquired_on, iar_line_id, opening_record, custodian, etc.
- v0.2.244 Domain patch adds lifecycle_state and prevents direct disposal. Maintain that policy.

## User interface
Asset Register each row: `View / Edit Property` (single property profile). Clicking opens a responsive in-IPDAMS drawer/modal, not a separate browser page. Show all details in one form with compact grouped headings; all properties visible, even locked values.
1. **Identification & description:** IPDAMS property number (read-only), legacy property number (historical text, editable with audit), item name and classification (read-only unless formal reclassification), brand, model, serial number, acquisition type, relevant item-specific technical specifications as supported.
2. **Acquisition & source:** acquisition date, acquisition cost, originating school, linked IAR / PO / DV, origin (IAR verified vs historical opening), useful life and evidence. Linked official records locked in normal edit; approved source document correction where applicable. Historical reference text is evidence and never a synthetic official IAR link.
3. **Assignment & condition:** custodian and current accountability (view, changed only via ITR/ICS/PAR), room/location (direct audited update if custodian unchanged), physical condition (view and/or textual observation only; formal unserviceable state via separate lifecycle workflow), repair/incident events and disposal state (read-only, separate actions).
4. **History:** latest corrections, who/when, before/after, linked ICS/PAR and movements.

Keep primary action `Save Changes` only for editable values, concise validation and inline locked-field explanations. Require a meaningful correction reason; show only changed fields in final review before Save. Offer `Edit this unit` and `Apply shared details to registration batch` with explicit affected-unit preview. Disable batch operation if it would reach conflicting issued records or distinct classifications without explicit per-unit decisions.

## Permission and data integrity rules
- Server enforces the active organization/school; user must have authorized school property edit permission, not VIEWER, unrelated Division, or passive SUPER_ADMIN default. CSRF session, input whitelist and validators, unique serial per school.
- **Even after finalized ICS/PAR**, allow audited **descriptive** corrections (e.g. brand/model, serial typo, physical location, free-form evidence), once print-snapshot immutability is verified; never back-edit finalized ICS/PAR records. If existing print uses live asset details, first preserve immutable historical print snapshots or explicitly versioned print-as-issued fields.
- **Never use Save Property to change:** property number/ID, school owner, registration batch key, official IAR/PO/DV relations, posted cost or fund amounts, official acquisition facts, item catalogue identity/UACS classification, custodian, custody events, approved unserviceable state, disposal. Use separately gated correction/source workflows, financial reconciliation when necessary, and owner-approved policy for exceptionally authorized reclassification/valuation corrections. Some erroneous historical-opening acquisition facts may be correctable in a specialized auditable non-financial workflow, but not through generic editor.
- No hard deletion; erroneous property registration void needs approved reversible archived action distinct from disposal.
- Use optimistic expected-version checks plus row lock to stop stale saves. Append field-level old/new snapshots, actor, reason, timestamp and source to audit; preserve source document IDs and original registered baseline. Use a single atomic DB transaction; no silent mutation of final issued document numbers or historical printer content. Report queries should consistently expose **current corrected details** and optionally as-of/issued original details for audit.
- Property condition workflow stays as agreed: low-value unserviceable declaration and disposal school-only; high-value requires **two independent Division Supply Officer approvals**, first for declaring unserviceable, second for disposal, while property stays in school record until documented physical disposal.
- Do not automatically change asset category due to edited displayed text; enforce COA low/high eligibility using trusted cost/source and proper thresholds.

## Implementation outline
1. Add one per-row action to Asset Register, reuse `propertyRegistrationDetail` as loader and `PropertyBatchMetadata` validators.
2. Refactor/extend `correctPropertyRegistration` field-level authorization so descriptive correction after issuance is safe without expanding mutation permission for immutable financial/custody fields. Preserve backwards compatibility with pre-issue Review Newly Received.
3. Add immutable correction versions if needed (can use versioned records already in IPDAMS) and verify prior finalized ICS/PAR print snapshot behavior before enabling post-issue edits.
4. Test historical opening vs verified IAR, single vs batch, before vs after ICS/PAR, duplicate serials, same-school scope, unauthorized role, stale optimistic version, property in pending unserviceable/disposal, permanently disposed asset, and failed DB transaction rollback. Test a printed prior ICS is **identical before and after descriptive edits**.

## Out of scope of this design
No new ZIP created by documenting the decision. No live hosted data modified. Do not imply every field is freely editable, or that corrections rewrite COA-issued source documents.
