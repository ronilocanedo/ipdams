# IPDAMS v0.2.259 — Division-aware external property transfer preparation

Owner confirms v0.2.258 is installed and working, requests External Property Transfer to include the receiving Division, a tidier transfer desk, and guidance on when transferred property enters receiving Asset Register.

**Scope:** Existing external transfers are presently `PENDING_RECEIPT`, documentary only, with the asset still owned and held by the originating school. An official receiving-office acceptance workflow and verified source IAR/catalog lineage are not implemented. The safe treatment is **NOT** to create an active receiving asset when a document is prepared. On future verified acceptance, move ownership/active register in an atomic, audited source-to-destination transaction; preserve original IAR/ICS/PAR/property number history, and issue new individual ICS/PAR separately when appropriate. Never duplicate as an unrelated receiving asset.

## Version-gated update

- ZIP: `IPDAMS_v0.2.259_DIVISION_AWARE_EXTERNAL_TRANSFER_FROM_v0.2.258.zip`; SHA256 `12575f67d74675da0f52d772d90aaab8026ac954f08a299d130a313f4367a89b`.
- Exactly **v0.2.258 → v0.2.259**, **five** application files, **no SQL migrations**: `app/PropertyTransferWorkflow.php`, `public/api/property-transfers.php`, `public/assets/property-transfers.js`, `public/assets/app.js`, `public/app.html`.
- External form selects **Receiving Division → Registered school or Division Office**, validates matching organization and division on server, snapshots their names and IDs and prints them.
- Existing organizational setup permits just one Division Office. **Outside IPDAMS** supports manually entered, officially named Division and school/office as `external_destination_unverified=true`, IDs `0` (not fabricated active organizations). Such transfers are **unverified documentary references** and cannot be viewed as authenticated receipts or automatically activated under an unrelated organization.
- Cleaner 3-step form, text search, select visible/clear, live count, mixed asset class guard, `AWAITING RECEIPT` register label, separate internal vs external controls. Internal transfer semantics and annual school numbering unchanged.
- Authenticated receiving Division user can view **Incoming Transfers** addressed to its registered Division as a *read-only* section of the grouped Property Accountability tabs. No incoming acceptance POST or remote asset writes.
- Critical: no IAR/ICS/PAR rewrites, source asset movement, destination active register creation, cross-division custody assignments, fabricated receiving staff/signed documents, or new permissions.

## Test evidence

- PHP syntax for both changed PHP files, Node syntax for both changed JavaScript files: PASS.
- **31** simulated PHP school/Division authorization and destination/receipt-preparation assertions: PASS.
- **25** executable UI interaction assertions: PASS.
- ZIP CRC/manifest/file SHA256 and exact version gate: PASS.
- Hosted browser / live MariaDB / official print-fidelity and real receiving acceptance tests: **pending**. No site writes, test-data resets, migrations or GitHub Actions in patch preparation.
- Full current private application source is not claimed synchronized to the review branch; do not upload backup ZIP/.env/database to GitHub. PR #16 remains draft.

Release notes are downloadable in this conversation as `IPDAMS_v0.2.259_EXTERNAL_DIVISION_TRANSFER_RELEASE_NOTES.md`. Source changes and package prepared from actual v0.2.258 frontend and v0.2.254 latest transfer domain baseline (unchanged by intervening releases).
