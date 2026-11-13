# IPDAMS — GitHub Copilot repository instructions

Follow `AGENTS.md` for cross-agent policies. Before editing, read the relevant `.github/skills/ipdams-*/SKILL.md` and check the actual source and release predecessor.

IMPORTANT: `main` currently contains coordination documents and partial patches, not a verified production source tree. Do not present historical files as deployable or enable any automatic deployment.

Default change process: issue → small feature branch → security/finance review → synthetic unit and isolated MariaDB tests → exact version-gated update package → owner-run UAT → documented installed checkpoint.

Protect school/Division tenant isolation, immutable audit, financial ledger/check/ATC semantics, document numbering and inventory custody. Never commit real data or secrets. Keep GitHub Actions disabled until explicitly approved.
