# IPDAMS development checkpoint — 26 October 2026

**Current owner-confirmed installed release: v0.2.215.** The owner reported that the selected **Custom Reset** succeeded, preserving Division, Schools, Users, and their access scopes. This is a hosted application confirmation, **not** an independent post-reset table reconciliation. **Do not perform another reset.**

## Immediate next steps

1. Verify through **read-only** hosted UI checks that preserved UACS/funds/catalog/units/suppliers/tax/BAC/permissions data remain, test PR/RFQ/PO/IAR/DV/stock/RIS/checkbook records targeted by the reset are clear, and document counters/audit history remain accessible.
2. Review the **exact deployed** `app/SettingsService.php` for updater migration parsing, allowlist, partial-apply behavior and recovery. No copy of this verified current file is available in the connected GitHub/Drive references; request that **one source file only** from the owner (no credentials, .env, database or private backup).
3. Preserve the already prepared **v0.2.216 historical property batch registration** as **STAGING REVIEW ONLY**. Six application files + two additive `assets` migrations; predecessor `v0.2.215`; `app/ResetService.php` intentionally unchanged.
4. Complete authenticated school-scoped IAR/ICS/PAR, editing, mutation/side-effect and backup/restore checks before considering live install. Owner prefers a **single site**; do not create a second hosted site or bypass risk controls.

## Current release candidate

- ZIP: [Drive v0.2.216 staging candidate](https://drive.google.com/file/d/1A3RIuJm1uGjFkno-ve85PMTfZQcS9I6g/view)
- SHA256 (previously verified): `aecb5727cbc271e6c18861ca475756f15f2bfe0e85bce3f78f70d491e979b265`
- [Acceptance guide](https://drive.google.com/file/d/1XWcYV5BcjOOdebvp3dw0lX8L5hA4K4fL/view)
- [GitHub draft PR #3](https://github.com/ronilocanedo/ipdams/pull/3) (DO NOT MERGE)
- [Successful manual MariaDB synthetic test run #2](https://github.com/ronilocanedo/ipdams/actions/runs/40265443265) — does not prove full hosted API or updater acceptance.
- Old property ZIPs called `v0.2.214` or `v0.2.215` are obsolete and **must not be installed**.

## GitHub ↔ Drive coordination

GitHub is private and holds review/issue/PR history, but **not** a complete deployable v0.2.215 application source tree. The full Drive working-source snapshot is historical v0.2.141. [GitHub sync register](SYNC_REGISTER.md) and [Drive sync register](https://docs.google.com/document/d/13NWruT1RTeFoxcL14l9-5UY9e2ENuX2RHEveAUGHOF8/edit) are checkpoint references, **not automatic mirroring**. Owner's hosted-site confirmation establishes installed version.

No migration, GitHub Actions run, live posting, reset, deployment or file-source baseline import was performed as part of this documentation checkpoint. [Full portable handoff](HANDOFF_2026-10-26_v0.2.215_to_v0.2.216.md).
