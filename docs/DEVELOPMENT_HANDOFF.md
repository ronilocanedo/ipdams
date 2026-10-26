# IPDAMS development checkpoint — 26 October 2026

## Last owner-confirmed installed version

**v0.2.213**: automatic property registration numbers `SCHOOLID-YYMM-00001`, with independent lifetime counters per school; counter does not restart on month/year rollover. Existing property numbers remain unchanged.

## Current development candidate: v0.2.214 (NOT INSTALLABLE)

Historical property batch registration: shared brand/model/acquisition/optional historical PO, IAR and DV references, individual unit serial/location/condition/legacy tag, school-specific generated property numbers, and audited corrections. Historical document references are evidence only; they do not produce official PO/IAR/DV or accounting/stock entries.

Two proposed additive columns on existing asset rows: `registration_batch_key` and `property_profile`. Existing IAR protections and issued ICS/PAR locks must be preserved.

Source, browser, migration-format and synthetic transaction checks have passed. Real MariaDB multi-session concurrency, rollback, authentication and migration-recovery UAT have **not** yet been executed. Do not distribute the v0.2.214 source candidate as an installable update.

## Source of truth

This new GitHub repository is **not yet a complete mirror of the installed IPDAMS application**. Do not deploy from `main` or label any draft branch production-ready until the exact vetted code-only snapshot and updater chain have been restored, reviewed, tested and tagged.
