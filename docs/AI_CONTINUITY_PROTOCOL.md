# IPDAMS — Continuity protocol for ChatGPT and all AI developers

**Status:** ACTIVE continuity protocol; original publication 2026-11-17, baseline references updated 2026-11-25 (Asia/Manila). Existing owner-approved project safeguards take precedence. This is documentation only, not a production change.

## Purpose

A change of chat session, model, AI developer or tool must **not reset IPDAMS decisions, rewrite project priorities, rerun one-time financial tests, or imply an unapproved release**. The repository and evidence—not conversational recall—are the persistent project record.

## Mandatory start-of-session procedure

1. Open the correct public repository `ronilocanedo/ipdams` and inspect the checked-out branch and clean/dirty status. Stable source is `main` (v0.2.458); start new development from current stable main or an approved feature/development branch. Do not treat source as automatic deployment authority.
2. Read root `AGENTS.md`, then this protocol, `docs/DECISION_REGISTER.md`, `docs/CURRENT_STATE.md`, `docs/HOSTINGER_GITHUB_SYNC_REVIEW_2026-11-17.md`, and `docs/QA_BASELINE_2026-11-16.md`. These last two are currently local, uncommitted review files; if missing from remote, do not invent their contents. Request the latest reviewed handoff or inspect available connected QA evidence.
3. Read the *actual code* and relevant official Drive documents for the task. Project decisions do not substitute for source verification.
4. Verify which reports are from Hostinger production, which are owner-confirmed, and which are synthetic QA. Reconcile conflicting dates or versions and **record the uncertainty**.
5. State the *next existing milestone*, what is proven, what is blocked, and exactly what you propose to change. Continue the established plan unless the owner directs otherwise.
6. Never claim a source commit, file upload, production installation, browser test, financial finalization or backup without a verifiable corresponding result.

## Authority hierarchy

- **Owner's explicit instructions and approvals** govern project scope, changes, and release authorizations; applicable safety controls cannot be waived by an AI handoff note.
- **Hostinger** establishes the currently installed production state, but never becomes the test environment; production-specific version data, credentials and financial records do not belong in GitHub.
- **GitHub** is the authority for reviewed source code, commit history, versioned decisions, and development checkpoint after an authorized push.
- **Google Drive / `IPDAMS Development`** is the authority for approved DepEd forms, official reference files, QA evidence, and historical development handoffs.
- **Owner's local GitHub Desktop checkout** can contain *newer uncommitted files* than GitHub. Such files must be recorded as LOCAL-ONLY until committed and pushed after approval.
- **Conversation history and summaries** are hints to locate evidence, not a substitute for code, reports or owner-approved decisions.

## Decision change control

- Each lasting decision gets a stable ID in `docs/DECISION_REGISTER.md`, a status, date, rationale, supporting reference, and affected areas.
- `ACCEPTED` means directly supported by established project instructions or owner approval; `PROPOSED` is not authority to execute; `SUPERSEDED` retains the old decision and references its replacement.
- New evidence may prompt a change proposal, but **the next AI must not silently reverse an accepted decision**. Show the tradeoff and obtain owner authorization when required.
- Historical checkpoint documents are immutable history, even when outdated; mark newer status in `docs/CURRENT_STATE.md` instead of rewriting historical claims.
- At each material milestone, update `docs/CURRENT_STATE.md`: UTC/PHT date, branch, source origin, file diff, commit SHA (if actually committed), installed production version confidence, tests executed and not executed, blockers, and the next action. Keep it short and factual.

## Quality and production safety

- Prefer a small reversible patch and targeted isolated synthetic tests; reuse earlier QA runs as **historical evidence only**. Run full regression at appropriate release gates; never extrapolate a past PASS to changed source.
- Preserve procurement, reimbursement, DV, tax, funds/bank, property, travel, official numbering, approvals, role scopes, and audit history.
- Reimbursement is the eligible approved claim amount; payment/settlement is through authorized DV, with same-employee consolidation and duplicate-allocation protection.
- Never run positive financial tests or Playwright against Hostinger production or any non-synthetic database.
- Owner approval required before commits, pushes, migrations, installation, deployment and releases. No automatic GitHub Actions, GitHub-to-Hostinger push or source-driven production deploy.
- Do not upload or commit `.env`, tokens, financial records, school identifiers, operational SQL, stored attachments, bank statements or private QA secrets.

## One-paragraph bootstrap for a new conversation or AI

> Continue IPDAMS from the **current verified checkpoint**, not a new plan. Repository: public `ronilocanedo/ipdams`, stable `main` at v0.2.458; start new development from the current stable source. First read `AGENTS.md`, `docs/AI_CONTINUITY_PROTOCOL.md`, `docs/DECISION_REGISTER.md`, and `docs/CURRENT_STATE.md`; inspect actual current GitHub/Hostinger/Drive evidence before changing anything. Respect every ACCEPTED decision, current blockers, synthetic-only tests and explicit owner-approval gates. Do not repeat completed QA without a reason. Explain any new evidence before proposing to change a decision. Continue the NEXT ACTION in `docs/CURRENT_STATE.md`.

**Limit:** This protocol is available in GitHub following owner-approved commits and push. Future AI agents must actually open these files (or receive their contents); no AI can guarantee automatic recall across systems that do not load them. The v0.2.449 reconstructed source is committed; older local-only artifacts remain historical unless separately verified and approved.
