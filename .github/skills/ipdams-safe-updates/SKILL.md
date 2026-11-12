---
name: ipdams-safe-updates
description: Prepare auditable version-locked IPDAMS update installers and verify backups, schema safety, hashes and owner UAT.
---

# IPDAMS Safe Update Builder

## Preflight (mandatory)
- Independently establish the owner-reported and, when possible, read-only authenticated installed application version.
- Obtain **exact** deployed predecessor source for every changed file and updater/migration implementation. If missing: STOP release packaging and mark blocked.
- Capture baseline SHA-256 hashes, package member list and source provenance; no reconstruction from old docs or patches.
- Assess SQL and DDL effects against the exact current schema, including safe-reset allowlists, parser and failed-interruption behavior.

## Package
- Make the smallest coherent change and an exact predecessor version gate.
- Validate PHP/JS syntax, migration safety, synthetic fixtures, compatibility, UI actions, negative roles, and interaction with existing flows.
- Manifest: from→to version, changed files and hashes, migrations, expected versions, schema effects, test evidence, known gaps.
- Document independent website and database backups, restore rehearsal requirements for risky migrations, and UAT/abort criteria.
- No silent writes on install to DV, bank, check inventory, tax, property, numbers, audit history or allocation.
- Publishing ZIP to Drive or code to GitHub does NOT mean installed. No auto-deploy or self-triggering Actions workflow.

## Release decision
Use four distinct labels: **DESIGN**, **PREPARED**, **TESTED (scope specified)**, **OWNER-INSTALLED**. Do not label completed if authenticated MariaDB and role UAT are pending.

On error: stop; preserve technical-review state, backups, logs and exact version, and never brute-force rerun a partial migration.
