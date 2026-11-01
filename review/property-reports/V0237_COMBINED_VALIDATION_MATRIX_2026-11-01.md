# Combined validation suite — migration, rollback and scope UAT (2026-11-01)

Packaged local review artifact: `IPDAMS_v0237_COMBINED_VALIDATION_SUITE.zip`. Contains patched v0.2.236 source excerpt, additive migration, read-only SQL checks, a preflight script and an M1–M5 / T1–T6 / U1–U6 acceptance matrix.

**Executed locally:** PHP syntax PASS; source contract checks PASS; migration static checks PASS; ZIP CRC PASS. **Blocked:** PDO MySQL extension / MariaDB CLI and disposable MariaDB server are absent. M/T/U gates remain unexecuted. `run_preflight.sh` exits 3 on missing prerequisites; never report that as PASS. The SQL inspection must run against disposable sanitized fixtures only.

Known restrictions: migration DDL cannot be assumed transactionally reversible; need updater failure recovery; cross-tenant and custodian invariants are application validations, not guaranteed by current foreign keys. No production changes, no schema migration run, no paid GitHub Actions. One bundled validation plan lets the three gates run together on an authorized disposable database and authenticated staging website. Do not mark PR ready or release v0.2.237 until all three pass.