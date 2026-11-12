# Explicit ICS/PAR-to-ISSUE movement linkage review — 2026-11-01

Based on owner-uploaded IPDAMS source version 0.2.236, not the old GitHub snapshot.

Created local **review-only** ZIP: `IPDAMS_ICS_EXPLICIT_MOVEMENT_LINK_REVIEW_2026-11-01.zip` SHA256 `45621ca16ff82aa4708453c86391a7ac25bf8e94967dec46e0d0950cfd2e633a`.

It includes a narrowly modified `private/app/Domain.php` (same existing ICS/PAR finalization transaction captures the inserted ISSUE movement ID and records an explicit link), plus `private/db/review_migrations/ics_movement_links.sql` (append-only table with unique accountability and movement FKs). No automatic historical backfill. PHP syntax and ZIP CRC pass.

**Not an installer:** migration intentionally not registered with updater; no database fixture rehearsal, rollback/failure test, verified all issuance paths, or multi-tenant consistency enforcement yet. Foreign keys do not prove linked asset+organization consistency. Existing rows remain unlinked; do not heuristically backfill by timestamp or custodian. Needs stronger guard, migration safety testing, and reviewed integration before installation.

No production changes or paid GitHub Actions.