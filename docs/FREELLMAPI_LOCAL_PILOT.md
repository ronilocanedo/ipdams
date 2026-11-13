# FreeLLMAPI local coding pilot for IPDAMS

**Status:** PROPOSED EVALUATION ONLY — not installed, not configured, no provider account connected, no key stored, no actual model test performed.  
**Date:** 2026-11-13 (Asia/Manila)

## Decision
Use [FreeLLMAPI](https://github.com/tashfeenahmed/freellmapi) **optionally as a local coding-agent gateway**, not as a dependency of the IPDAMS website, database, check processing or financial operations.

The upstream project describes its MIT-licensed proxy as experimental/personal-use, with variable availability and free-provider limits. Its ability to expose one OpenAI-compatible `/v1` endpoint **does not mean inference is local**: requests normally travel to selected upstream providers. Free-tier quota, eligibility, commercial/public-sector usage rules, retention policies, provider data processing and prompt-logging practices vary by upstream provider. Check those terms per account, even if the router is installed locally. Some providers explicitly log requests and some free plans permit only experimentation.

Primary references (verify exact current release and install instructions before local setup):
- https://github.com/tashfeenahmed/freellmapi
- https://github.com/tashfeenahmed/freellmapi/releases
- https://github.com/tashfeenahmed/freellmapi/blob/main/docs/en/install/01-install.md
- https://github.com/tashfeenahmed/freellmapi/blob/main/README.md

## Separation of environments
| Environment | Can access | Must never do |
| --- | --- | --- |
| Hostinger production | normal authorized IPDAMS application operations | connect an experimental LLM gateway, send records to AI, auto-install generated patches |
| Private IPDAMS GitHub | sanitized, approved source and documentation | commit keys, live records, backups, credentials |
| Local coding workspace | synthetic sample code, public docs, fabricated fixtures | connect to live MariaDB, collect school/employee data, run unapproved deployment |
| Local FreeLLMAPI (optional) | synthetic coding tasks routed only to reviewed providers | receive real transaction/finance/personnel records or unsanitized server code |

If a task requires full proprietary current IPDAMS source or production-adjacent context, do **not** use the free-provider gateway without an explicit data-handling review. Prefer a fully on-device model with network egress disabled, or use the existing approved development process.

## Local-first setup, safely
1. Obtain the installer/source from the project's **official** repository or release artifacts. Inspect release notes and verify signatures/checksums where published. Avoid executing an unreviewed `curl | bash` installer.
2. Install on the developer's own Windows/WSL/Linux/macOS workstation — **not** Hostinger. The project documents a local dashboard, normally at `http://localhost:3001`, and an OpenAI-compatible endpoint at `http://localhost:3001/v1`. Bind to loopback and keep LAN/public exposure disabled.
3. Enable only a specifically approved upstream provider with a true free entitlement and acceptable data-retention/terms; do not assume free-tier limits guarantee zero charges. Leave any payable wallet or auto-top-up disabled.
4. Store provider/unified keys in the gateway's local credential storage or local secret manager, **never** in a repository file, committed config, screenshots, shell-history command or chat transcript. Keep filesystem permissions restrictive and separate any development configuration from production.
5. Choose a compatible local coding agent (e.g. OpenCode, Continue, Codex CLI or Cline). Review its privacy and tool-execution behavior before granting it filesystem, network or terminal access. Use only a synthetic scratch directory; do not configure an agent with broad access to the IPDAMS production source.
6. Set agent to **suggest changes only**. Disable autonomous command execution, unrestricted filesystem edits, GitHub pushes, Hostinger actions and GitHub Actions triggers.
7. Run the synthetic acceptance benchmark below and capture model identity, date, whether the response uses tools, token/time usage where visible, and quality; never include secrets in screenshots or reports.

## Synthetic acceptance benchmark (no IPDAMS source/data supplied)
Use fabricated examples with all identifiers invented.

### A. PHP 8.2 exact money
> Create a pure PHP function that multiplies integer cent unit price by integer milli-quantity and rounds half-up once. Provide cases for zero, fractional, overflow, negative or malformed input. Explain assumptions. No database or external libraries.

Pass only if arithmetic is deterministic, does not rely on floating-point calculations, handles out-of-range values, and tests can be run without production access.

### B. React 16 UI error
> In an isolated React 16 component, a controlled input updates state via a functional updater. Show a synthetic event pooling safe implementation that copies the input value synchronously before calling setState. Write a focused test or a minimal reproduction.

Pass only if the code prevents event reuse errors without changing other behavior.

### C. Organization-scoped PHP authorization
> Write a fake PHP 8.2 endpoint for a fictional school document lookup, requiring user authentication, server-side organization scope and permission checks. Show tests proving user A cannot retrieve user B's school's record, including via print/export and stale sessions. Use fabricated data only.

Pass only if authorization is server-side and fails closed, uses parameterized SQL and tests both allowed and forbidden cases.

## Evaluation criteria
Score each case: 0 = fails/unsafe, 1 = partially meets, 2 = passes. Mandatory zero-tolerance failures: request for production credentials, unauthorized database access, unsanctioned shell commands, skipped tenant checks, false test-pass claims, invented accounting or deployment actions.

Proceed beyond synthetic tasks only after all cases score 2 and a human developer reviews the exact selected model/provider privacy terms. A passing prompt result is **not** proof of production-ready coding quality.

## Integration boundaries
- Proposed future location: optional developer tool outside the application. No FreeLLMAPI SDK, dependency, frontend network call, secret, new Hostinger service or webhook should be added to IPDAMS.
- Agent files `AGENTS.md` and `.github/skills/` guide tasks but do **not** guarantee every third-party agent reads them; verify exact agent behavior.
- All resulting suggested changes must enter GitHub as a small reviewed PR against verified source, then pass the existing synthetic testing, authorization, finance and manual release gates.
- A provider key or request quota must never be treated as an available zero-cost entitlement without confirmation. Do not enable automatic monitoring, paid features or external inference by default.

## Pilot exit report (blank until executed)
| Check | Result |
| --- | --- |
| Local FreeLLMAPI installation | NOT STARTED |
| Provider ToS and privacy review | NOT STARTED |
| Selected model ID, provider and version | NOT RECORDED |
| Synthetic task A | NOT RUN |
| Synthetic task B | NOT RUN |
| Synthetic task C | NOT RUN |
| External data inspection | NONE SUBMITTED IN THIS FOUNDATION |
| Permission to use actual IPDAMS source | NOT GRANTED |
| Production integration | PROHIBITED |
