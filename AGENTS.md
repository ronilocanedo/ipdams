# IPDAMS — repository-wide instructions for AI coding agents

The Integrated Procurement, Disbursement and Asset Management System (IPDAMS) supports DepEd schools and Division workflows. Prefer the fastest **verifiable** implementation, never the fastest untested deployment.

## Source of truth and status
- As of 2026-11-13, GitHub `main` is a **partial coordination/review repository**, NOT a verified deployable copy of Hostinger. Do not reconstruct or deploy from historical patches or assume the README's version is the live installed version.
- Hosted IPDAMS and its verified release manifest are the authority for installed behavior. Obtain a sanitized, hash-verified **code-only** snapshot, inspect differences, and confirm the live predecessor before creating installation packages.
- No production write, schema change, install, reset, check issue, DV finalization, remittance or financial posting without explicit owner authorization and the established gates.
- Do not use GitHub → Hostinger auto-deploy; do not add or run Actions without separate owner approval and billing verification.

## Code boundaries
- PHP 8.2+, MariaDB; existing browser UI includes React 16. Confirm real dependencies from current source before modernizing.
- Keep a modular monolith: reusable domain services, shared authentication/authorization/audit, clear module boundaries, minimal front-end changes.
- Always enforce organization/school scoping and Special Functions permission on the **server**, for API, print, attachment and export endpoints; test revocation.
- Preserve immutable accounting history, document numbering, checkbook integrity, source-fund isolation, tax component lineage, inventory custody and transaction semantics.

## Required workflow for any change
1. Identify an issue, exact installed version and authoritative **current source** for each changed file.
2. State acceptance cases, affected workflows, security/financial risks and migration impact.
3. Change only the smallest necessary scope on a branch; do not silently refactor unrelated code.
4. Run syntax and relevant unit/integration/regression checks against synthetic data and an isolated MariaDB when applicable.
5. Independently review security, accounting, migration, recovery, cross-organization isolation, and version-gated update package.
6. Prepare release manifest, file hashes, verified predecessor, rollback/recovery instructions, and owner UAT checklist. No automation touching live data.
7. Record branch/commit, file hashes, Drive artifact, UAT results and actual **owner-confirmed installed version**. Distinguish prepared, tested and installed.

## Optional external AI coding gateways
- FreeLLMAPI and other multi-provider LLM routers are **experimental local development aids**, not IPDAMS services. A local gateway typically forwards prompts and source content to remote third-party providers.
- Do not send any actual IPDAMS proprietary source, personnel, bank/check, tax, attachment or production operational content to a free LLM provider without a provider-specific data-handling and authorization review. Default to fabricated fixtures only.
- Never enable an agent's unrestricted shell/network, autonomous deployment, GitHub write access or Hostinger credentials during a free-model pilot. See `docs/FREELLMAPI_LOCAL_PILOT.md`.

## Confidentiality
Never commit .env, credentials, tokens, database exports, production records, bank/check data, personnel files, signed forms, attachments, backups, or logs containing private information. `.gitignore` is NOT an adequate secret scanner. Use synthetic fixtures and review every imported file for embedded secrets.

## Task-specific reusable instructions
Read the relevant skills under `.github/skills/`:
- `ipdams-security`
- `ipdams-financial-integrity`
- `ipdams-regression-testing`
- `ipdams-safe-updates`
- `ipdams-module-architect`

If a verification is unavailable, mark it **NOT VERIFIED**, never claim it passed. Refer to `docs/FOUNDATION_V1.md` and `docs/SOURCE_BASELINE_RECONCILIATION.md`.
