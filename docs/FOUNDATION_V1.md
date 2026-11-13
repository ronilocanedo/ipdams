# IPDAMS Development Foundation v1

**Created:** 2026-11-13 (Asia/Manila)  
**Scope:** repository process and AI-agent guidance only; **no application patch**, installation, schema change, financial posting, Actions run or live-site change.

## Why this foundation exists
IPDAMS covers procurement, disbursements, funds, banking, employee travel, inventory/property, textbook flows, organization/role management and audit. Changes must remain small, safe, traceable and compatible.

The highest current constraint is that GitHub `main` contains coordination documents and incomplete source/patches, not a complete verified code mirror of the current Hostinger installation. This branch **does not fix that gap**. It documents the safe reconciliation procedure before code can be considered authoritative.

## Artifacts in this proposal
| File | Purpose |
| --- | --- |
| `AGENTS.md` | Repository-wide AI and contributor instructions |
| `.github/copilot-instructions.md` | Copilot-specific guidance |
| `.github/skills/ipdams-*/SKILL.md` | Five domain-specific reusable agent skills |
| `.github/ISSUE_TEMPLATE/*.md` | Structured bug/enhancement intake |
| `.github/pull_request_template.md` | Release, security, database and UAT review gates |
| `docs/SOURCE_BASELINE_RECONCILIATION.md` | Safe Hostinger→GitHub source acceptance procedure |
| `docs/TEST_MATRIX.md` | Progressive cross-module regression plan |
| `docs/SOURCE_DISCOVERY_2026-11-13.md` | Read-only metadata inventory of candidate Hostinger application files; **not** code import or SHA audit |
| `docs/FREELLMAPI_LOCAL_PILOT.md` | Optional local, synthetic-data-only coding-agent evaluation; **not** an installation |

## Working architecture principle
Adopt a **modular monolith**, gradually: one application and a carefully governed MariaDB, with separated domain responsibilities and reusable authorization, audit, numbering, reference data and document-print services. **No architecture rewrite is authorized by this document.**

Proposed boundaries:
- **Access and organizations:** identity, assignment, Special Functions, school/Division scoping.
- **Procurement:** PR, RFQ, quotations, abstract/award, PO and IAR.
- **Disbursements:** DV, accounting review, tax components, bank/check lifecycle.
- **Funds and withholding:** allocation, authorization, per-fund book, tax treatment and evidence.
- **Travel and employee claims:** authority, itinerary, review, reimbursement claim, DV handoff.
- **Inventory/property/textbooks:** custody, receipt, transfer, return, accountability forms and reports.
- **Shared services:** audited events, numbering, print/export, reference data and cross-module contracts.

Use single-authority writes: an approval or claim may feed DV preparation, but actual reimbursement occurs in DV; a Division tax transfer settles liability, not a second expense. Design idempotency and valid state transitions explicitly.

## Status and next gates
- **Complete on review branch:** instructions, reusable skill definitions, issue/PR templates, inventory/reconciliation plan and test matrix.
- **Blocked until verified code-only source:** actionable application-wide static analysis, dependency audit, automatic PHPStan/PHPUnit configuration and executable end-to-end tests.
- **Not authorized:** import of unreviewed hosted `private/` tree, production SQL or attachments; GitHub→Hostinger deployment, database migrations, GitHub Actions triggers, automatic purchases/financial changes.
- **Future phase 1:** independently verify site version, build sanitization manifest, capture per-file checksums, review first-party source, reconcile into a separate source-import PR.
- **Future phase 2:** establish safe local quality tools on the actual source. Prefer PHP syntax, JS parse, Gitleaks and regression unit tests first; add PHPStan/PHPUnit/Playwright after examining dependencies and testability.
- **Future phase 3:** optional manual-only synthetic MariaDB CI after owner's explicit approval and billing check.

## Source and release authority
- GitHub: reviewed source **once imported and reconciled**.
- Hostinger: installed-code behavior and current release readback.
- Google Drive: guides, test artifacts and version-locked installers.
- Each checkpoint must record Git commit + source snapshot hashes + candidate ZIP hash + Drive link + acceptance/UAT result + owner-confirmed installation, keeping these states separate.

Retain the existing `docs/ACTIONS_AND_COST_POLICY.md`, `docs/RELEASE_SAFETY.md` and `SECURITY.md`; this document supplements rather than overrides them.
