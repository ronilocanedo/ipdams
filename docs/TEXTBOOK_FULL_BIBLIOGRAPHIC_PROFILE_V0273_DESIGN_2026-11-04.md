# IPDAMS v0.2.273 design — full textbook bibliographic profile with optional fields

**Requested:** 2026-11-04; owner-installed baseline **v0.2.272**.  
**Status:** scoped development requirement / review specification; **not an installer or deployed change**.

## Goal
Let authorized Division and school users register complete bibliographic metadata in the shared Book Directory, without falsely requiring grade, subject, publisher, author or delivery paperwork when absent. Registration must never add physical stock. Preserve the CO receiving controls introduced in v0.2.272.

## Required and optional fields

### Minimum DRAFT save
- **Required:** book/resource title (trimmed, non-empty, max length), selected material type (`Textbook`, `Teacher's Manual`, `Learner's Material`, `Reference`, `Other Learning Resource`), and authenticated registering office/actor (from session, never free-form).
- **Optional:** all other bibliographic fields. Draft with no ISBN or official resource code remains `INCOMPLETE/UNVERIFIED` and cannot be picked for stock-affecting receipt/distribution.
- System assigns an internal immutable catalog-submission ID; **do not** pass it off as an official DepEd LR code or ISBN.

### Full optional bibliographic details
- Subtitle, alternate title, series/collection, volume/part, edition/revision, printing/printing year, authors/contributors, editor, translator, illustrator.
- Publisher/imprint, place of publication, publication year, copyright year, language, pages, book format/binding (print/digital/other), dimensions/size if meaningful.
- Grade/level, learning area/subject, quarter/strand/program, intended audience, curriculum edition, DepEd material category (TX/TM/LM/IM/reference).
- **Identity:** optional ISBN-10/ISBN-13 (canonicalized/validated if supplied), or an authentic official LR/material code when no ISBN exists. The two are not fabricated for incomplete draft records.
- Evidence/citation: source/reference URL or official document number, bibliographic verification note, remarks. CO delivery/packing reference belongs to **physical receipt**, not mandatory to create a book directory record. Do not require quantities or prices in catalog metadata.
- Attachment/cover image is a **separate enhancement** requiring validated MIME/size/auth/retention; do not add unsafe arbitrary uploads as part of this simple data-entry patch.

### Verification / availability gate
- A user can save an incomplete draft and return later, or submit it for Division catalog-steward review.
- A record becomes `VERIFIED` only after identity/evidence and duplicate review; at least a valid canonical ISBN **or** authentic official LR identifier is required. Valid ISBNs with duplicate normalized ISBN-10/13 values are rejected globally; duplicate LR codes must be rejected in their defined namespace. Same title with different official editions is not automatically merged.
- Current v0.2.272 CO-receipt backend only resolves canonical ISBNs in the existing `textbook_titles` table. Until a separately tested receipt-reader extension supports verified non-ISBN official LR codes, **do not** make non-ISBN titles selectable in CO receipt. Enforce server-side, not only via UI.
- Legacy missing/invalid/conflicting ISBN titles are never silently modified or discarded. Display review status and steward remediation options.

## Suggested interface
Within **Textbooks > Book Directory**, replace the single forced-ISBN form with **Add Book**:
1. **Basic Information** (title required, material type required, optional subtitle/grade/subject/language).
2. **Publication & Identity** (optional ISBN or LR code, authors, publisher, edition, date, pages, series).
3. **More Details** (other optional metadata, official supporting references, notes).
4. **Review & Save** (clearly show `Save Draft`, `Submit for Verification`, `Verified/eligible for CO receipt` only once separately authorized).

Use compact expandable sections instead of a long crowded form. Search before creating, then allow edits to the user's open drafts; corrections to verified identity require steward authorization and append-only before/after audit, never altering issued/received documents' snapshots.

## Technical boundaries requiring verification before packaging
- Current deployed `textbook_titles` is read through `id,title,isbn,grade,subject`; current `TextbookCoReceiptWorkflow::registerCatalog` insists on title, grade, subject, source reference and ISBN, and only writes those 4 table columns.
- Extra details **must actually persist**. Do not just add UI inputs that vanish. Inspect live table column types/nullability and `SettingsService` SQL whitelist, `ResetService` safe reset list and backup integration before choosing additive metadata tables or columns.
- Preferred phased model: an additive, revisioned global bibliographic profile/draft store linked by stable identifier to legacy titles where possible; track creator/office/review state and optional metadata, with a verified edition linked to existing receipt `title_id`. Normalize global identity under transactional uniqueness and preserve legacy IDs. No wholesale table rewrite, legacy title merge, or blind migration.
- If the current deployment migration whitelist or exact schema cannot be verified, stage a **readiness-only** patch first (no DDL), complete isolated MariaDB migration/rollback/restore test, then deliver strict **v0.2.272 → v0.2.273** version-gated package and independent website+SQL backups.
- No changes to CO receipt conditions, per-office scope, two-person inspection/custodian sign-off, idempotency, locked stock movements or history.
- Github `main` is **not** a complete live source baseline: generate patch only from exact verified on-host source and do not deploy main.

## Acceptance tests
1. Save a draft with **title + type only**. Reopen later; missing ISBN/grade/subject must not block, and stock must not change.
2. Save all supplied optional details; list/detail/edit refresh preserves all values including Unicode punctuation. Missing optional fields render as em-dash or blank, not `undefined`.
3. Valid ISBN-10 converts to canonical ISBN-13; bad check digits rejected. An equivalent second registration is rejected across different offices. ISBN omitted is allowed as draft without invented ISBN.
4. Valid official LR code without ISBN can be registered/reviewed without replacing the official code with a fake ISBN; is ineligible for current ISBN-only CO receipt until a separately implemented official LR-code path passes integration tests.
5. Potential duplicate title-author-edition causes review prompt, not a silent merge or unconditional denial of a genuinely different edition.
6. A school sees shared bibliographic metadata but not another office's physical stock, submissions requiring restricted review or teacher data.
7. Verify permissions, scoped draft edits, immutable verified history, input validation and stored-XSS escaping. Run source lint, simulated/isolated MariaDB transaction tests, ZIP/manifest hashes, existing v272 regression checks and authenticated UAT before claiming installed.
8. No background allocation, phantom stock increase, duplicate receipt, database reset or historical record mutation merely by catalog registration.

## Continuation
Once **v0.2.273** full-profile registration is reviewed, implement **Division dispatch to schools** as a separate stock ledger flow, with reservation, in-transit state and school Action Center acceptance, but not before v272 receipt UAT is performed.
