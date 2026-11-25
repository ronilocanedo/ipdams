# IPDAMS — Canonical decision register

**Prepared:** 2026-11-17 (PHT). **Status:** Active register; updated 2026-11-25 following owner approval. Entries marked PROPOSED remain proposals, not approved actions.

| ID | Status | Decision and reason | Evidence / authority |
| --- | --- | --- | --- |
| D-001 | ACCEPTED | Preserve IPDAMS for Philippine DepEd schools and Division Offices; simplest practical UI, strict backend security, financial integrity and approved forms. | Owner's standing IPDAMS project instructions. |
| D-002 | SUPERSEDED BY D-016 | GitHub private `ronilocanedo/ipdams` is source history authority. Work from `develop/hostinger-v0.2.375` until a newer source branch is verified; never mistake `main` for the current application. | Root `AGENTS.md`, GitHub README and 2026-11-13 source audit. |
| D-003 | ACCEPTED | Hostinger `ipdams.depedtagb.online` serves approved production releases. No automatic deployment, production DB experiment, live payment, or financial posting. | Standing project instructions and `docs/RELEASE_SAFETY.md`. |
| D-004 | ACCEPTED | Google Drive `IPDAMS Development` preserves approved official forms, templates, signatories, test evidence and handoffs; do not alter without authorization. | Standing project instructions. |
| D-005 | ACCEPTED | Reimbursement contains **only approved eligible claim value**; actual settlement occurs through authorized DV, which may consolidate multiple approved, unpaid claims for the *same employee*. Prevent duplicate reservations and disbursements. | Owner's reimbursement/DV instruction and root `AGENTS.md`. |
| D-006 | ACCEPTED | Preserve PR, RFQ, Abstract, Award, PO, IAR, DV, check, inventory, ICS/PAR, property transfer, travel and reimbursement workflow controls; fund, UACS, tax, signatory, audit and document-number invariants remain enforced. | Standing project instructions. |
| D-007 | ACCEPTED | Use Playwright for isolated synthetic Chromium desktop/mobile repeatable tests under `qa/playwright/`; do not call syntax checks executed functional tests. Protect one-time positive test fixtures and test markers. | Standing project instructions; `docs/QA_BASELINE_2026-11-16.md` (LOCAL-ONLY until pushed). |
| D-008 | ACCEPTED | Prefer small reviewed reversible patches and **targeted** regression after a change; full relevant functional/security/finance testing is required at genuine milestone/release gates. Earlier QA passes are evidence, not universal proof. | Owner's request for less repeated testing; `docs/RELEASE_SAFETY.md`. |
| D-009 | ACCEPTED | Explicit owner approval is required before Git commits, pushes, migrations, installations, Hostinger changes, deployments and releases. Independently verify backups before approved installation. | Standing project instructions. |
| D-010 | ACCEPTED | Official DepEd forms use approved wording/layout; Bookman Old Style preferred for communications. Travel and property histories/signatories must remain consistent with owner-accepted workflows. | Prior owner-confirmed design decisions; verify exact current approved Drive templates for each artifact. |
| D-011 | ACCEPTED | ChatGPT and owner lead design/review; Playwright performs browser tests; GitHub stores code; Drive stores official docs; Hostinger hosts production. OpenCode and FreeLLMAPI are not routine tools without specific owner authorization. | Standing project instructions. |
| D-012 | ACCEPTED | GitHub Desktop and VS Code are the owner's local working tools. Local uncommitted code is **not automatically in GitHub**. | Owner-confirmed setup, 2026-11-17 read-only branch and Git status. |
| D-013 | CURRENT PLAN | Reconcile existing production source against baseline before claiming GitHub/local/Hostinger parity; do not blindly overwrite production with the historical branch. | Owner's 2026-11-17 agreement to source reconciliation; unverified Hostinger content hashes remain a blocker. |
| D-014 | ACCEPTED | Persist this protocol, the decision register and one current handoff file in private GitHub; every new AI reads them before proposing changes. Accepted decisions require evidence and owner approval to amend where applicable. | Owner's 2026-11-17 approval and verified GitHub readback of all four continuity files on `develop/hostinger-v0.2.375`. |
| D-015 | SUPERSEDED BY D-016 | After byte-level source reconciliation, adopt a clearly named updated source branch or verified checkpoint, retaining `develop/hostinger-v0.2.375` and its history. Do not rename or merge branches just to improve appearances. | Synchronization design proposal; owner approval still required for branch/commit/push. |

| D-016 | ACCEPTED | Keep GitHub public by owner choice; stable `main` contains verified v0.2.449 source, active development uses `develop/hostinger-v0.2.449`. Preserve history; no automatic deployment. Do not publish secrets, production data or configuration. | Owner-approved source push and merge into main, 2026-11-25; source commit `e668405638e67db5fd855bf9bf81c418b1cf31a6`. |

## Non-decisions (do not infer authorization)

- Owner's request to **continue** or **reconcile** source is **not** blanket authorization to commit, push, migrate, reset, install or deploy.
- QA synthetic UACS `5029999099`, conditional credit `1990101000`, and fictional fund credit are **not** approved DepEd/COA accounting rules.
- A reported `v0.2.378` installation or matching Hostinger file size is not byte-verified source parity.
- A historical release document may be accurate *for its date* while superseded by later work; do not change ACCEPTED decisions based on an old historical statement.

## Change log

| Date (PHT) | Entry | State |
| --- | --- | --- |
| 2026-11-13 | GitHub verified integrated source v0.2.375; Hostinger installed version checked at that date | Historical verified checkpoint |
| 2026-11-16 | Local QA evidence: 162 PASS, 0 FAIL, 8 SKIPPED; financial positive finalization still blocked | Historical QA checkpoint |
| 2026-11-17 | Owner local GitHub Desktop source/QA review package copied, 13 paths, **no commit/push** | Current local checkout status |
| 2026-11-17 | Continuity protocol and decision record drafted in response to owner concern | Historical draft checkpoint |
| 2026-11-17 | Owner committed/pushed continuity-only documents; remote GitHub content and AGENTS.md references independently verified | D-014 ACCEPTED; application source sync still pending |

| 2026-11-25 | Owner confirmed v0.2.449 installed, approved public repository, verified source synchronization and promotion to main | D-016 ACCEPTED; live file hashes remain unverified |

For future updates, append the decision ID, status transition, evidence, owner approval and relevant commit SHA. Do not erase the earlier record.
