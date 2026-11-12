# v0.2.237 explicit issuance link integration candidate — 2026-11-01

Built from owner-uploaded source archive labeled 0.2.236; no claim of deployed-version equivalence. Review ZIP: `IPDAMS_v0.2.237_ICS_LINK_INTEGRATION_CANDIDATE_REVIEW.zip` SHA256 `c5f1b433a535a2ef128c394a6decd9fcce8b0611672fc8241116c21b6e34ef4d`.

Includes patched existing `private/app/Domain.php`, additive `private/db/migrations/237_accountability_movement_links.sql`, local `tests/check.sh` and README. It does not add an ICS screen or overwrite historical records. Patch applied to supplied source and PHP syntax/static guards/ZIP CRC passed.

**Not an installable release:** local PHP environment has no MariaDB PDO driver or MariaDB server; transaction rollback/replay, migration failure behavior, deployment version match and authenticated cross-organization checks remain untested. No production deployment, GitHub Actions or database changes. The migration is shown for review; do not execute it against real records until independently rehearsed. Official eight COA annex reports remain outstanding.

Next: run disposable MariaDB fixture with wrong asset/org/duplicate link and injected failure; verify full rollback and one-to-one links, then validate actual hosted version and updater semantics before publishing a version-gated installer.