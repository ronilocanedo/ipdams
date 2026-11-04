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

## Approved creator-and-Division edit authorization (owner decision, 2026-11-04)

**The receiving office registers new books.** Central Office is recorded only as the external source of the physical delivery; it does not create an IPDAMS login or a shared mutable catalog entry. When a Division physically receives a previously unlisted book, an authorized Division user adds it. When a school directly receives a previously unlisted book, an authorized user of **that school** adds it. Catalog registration is still distinct from acceptance and never posts physical copies.

**Record provenance immutably at creation:** `registered_by_organization_id` (the authenticated receiving school or Division), `registered_by_user_id`, `owning_division_id` from the verified organization hierarchy, and timestamps. Never accept these fields from arbitrary client-supplied JSON. When a school creates a title, that school and **its parent/supervising Division Office only** may edit its bibliographic details, using authorized accounts with current scope. When Division creates a title, that Division alone may edit it. No unrelated school, other Division or technical `SUPER_ADMIN` can bypass this ordinary edit rule.

| Actor | Add book when physically receiving | View verified shared book | Edit school-added book | Edit Division-added book |
| --- | --- | --- | --- | --- |
| Registering school | Yes | Yes | **Yes, its own submissions only** | No |
| Another school in same Division | Yes, only if truly new edition | Yes | No | No |
| Supervising Division | Yes | Yes | **Yes, in its Division** | **Yes, its own submissions** |
| Unrelated Division | Yes, for genuinely new entry in its scope | Yes | No | No |
| Technical Super Admin | No ordinary operational registration | According to authorized read role | No | No |

**Shared title is registered once, not separately per school.** Receiving offices must search ISBN/LR code before creating. If School B receives a title previously registered by School A, School B selects that same verified edition and records its **own physical receipt/stock**. It must not create a duplicate catalog title or gain edit privileges just because it now holds copies. School B may **propose a correction with documentary evidence** to the supervising Division, but cannot directly overwrite School A's metadata. For a cross-Division correction the registered record's owning Division remains the editor; a future cross-Division correction request should be a separately authorized, non-mutating workflow.

**Draft visibility:** unfinished submissions are private to the originating school and its supervising Division, or to the originating Division. The global Book Directory exposes only approved/verifiable shared bibliographic information (not unrelated draft notes or any office's stock). Origin-school or Division editors can update drafts; title/metadata corrections to published records must create append-only before/after versions with actor, office, time and reason. After use in any receipt or stock posting, edits to ISBN/LR identity or edition must not silently rewrite past receipt/ledger snapshots. A truly new edition receives a separate identity. Preserve old values in transaction evidence.

**Server authorization:** resolve the current signed-in user, office and Division ancestry from the database for every create/edit/detail/correction endpoint; enforce org-scoped edit checks inside the write transaction (including ownership re-check under lock). Do not rely on hiding an Edit button, a user role name alone, or a client `organization_id`. Test ID tampering, forged originating office, school-to-school attempts, unrelated-Division attempts, and a `SUPER_ADMIN` attempting to bypass operating authority. The owning Division relationship must be validated against authoritative school↔Division assignments; if unavailable, fail closed rather than grant broader editing.

## Suggested interface
Within **Textbooks > Book Directory**, replace the single forced-ISBN form with **Add Book**:
1. **Basic Information** (title required, material type required, optional subtitle/grade/subject/language).
2. **Publication & Identity** (optional ISBN or LR code, authors, publisher, edition, date, pages, series).
3. **More Details** (other optional metadata, official supporting references, notes).
4. **Review & Save** (clearly show `Save Draft`, `Submit for Verification`, `Verified/eligible for CO receipt` only once separately authorized).

Use compact expandable sections instead of a long crowded form. Search before creating; only the registering school and its supervising Division (or the registering Division) have edit access. Other receiving schools reuse the existing verified edition and may request a correction, but cannot edit the master. Corrections to verified identity require controlling-Division authorization and append-only before/after audit, never altering issued/received documents' snapshots.

## Technical boundaries requiring verification before packaging
- Current deployed `textbook_titles` is read through `id,title,isbn,grade,subject`; current `TextbookCoReceiptWorkflow::registerCatalog` insists on title, grade, subject, source reference and ISBN, and only writes those 4 table columns.
- Extra details **must actually persist**. Do not just add UI inputs that vanish. Inspect live table column types/nullability and `SettingsService` SQL whitelist, `ResetService` safe reset list and backup integration before choosing additive metadata tables or columns.
- Preferred phased model: an additive, revisioned global bibliographic profile/draft store linked by stable identifier to legacy titles where possible; track creator/office/review state and optional metadata, with a verified edition linked to existing receipt `title_id`. Normalize global identity under transactional uniqueness and preserve legacy IDs. No wholesale table rewrite, legacy title merge, or blind migration.
- If the current deployment migration whitelist or exact schema cannot be verified, stage a **readiness-only** patch first (no DDL), complete isolated MariaDB migration/rollback/restore test, then deliver strict **v0.2.272 → v0.2.273** version-gated package and independent website+SQL backups.
- No changes to CO receipt conditions, per-office scope, two-person inspection/custodian sign-off, idempotency, locked stock movements or history.
- Github `main` is **not** a complete live source baseline: generate patch only from exact verified on-host source and do not deploy main.

## Acceptance tests
0. **Permission matrix:** School A creates book; School A and its owning Division can edit; School B, an unrelated Division and technical Super Admin cannot. If a Division creates the book, only that Division can edit. Reusing the shared ISBN for School B's receipt never grants School B edit privilege. Cross-office spoofing or tampered creator fields is rejected server-side. Every accepted change has an append-only audit snapshot.
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
