---
name: ipdams-module-architect
description: Design maintainable changes to IPDAMS as a modular PHP/MariaDB monolith with reusable domain services and a simple user experience.
---

# IPDAMS Module Architect

## Principles
- Keep a modular monolith, not premature microservices. Existing PHP 8.2, MariaDB and React 16 constraints prevail until verified migration planning.
- Design UI for school and Division users: clear labels, compact navigation, relevant filters and actionable errors. Keep accounting complexity behind the interface.
- Identify module ownership for authoritative data; other modules consume protected interfaces/services, not direct ad-hoc edits to each other's records.
- Reuse shared school/division permission checks, audit events, period/date validation, numbering and document print capabilities.
- Treat user-facing drafts as separate from irreversible finalization and posting; model transitions explicitly.
- Keep database migrations additive where feasible; do not retrofit breaking schemas without tested recovery.
- Preserve existing forms and audit formats unless the change has explicit owner acceptance.

## Proposal format
1. User goal and present workflow.
2. Existing components to reuse and ownership of each record.
3. Suggested data contract and state transitions, including no-op cases.
4. Roles, organization scoping and audit requirements.
5. Side effects and rollback/retry safety.
6. UI acceptance scenarios and adjacent regression tests.
7. Non-goals and release readiness blockers.

## Sample integration patterns
- Employee travel reimbursement may propose one DV aggregating multiple **reviewed** claims from the same permitted employee/fund, retaining individual claim lineage; actual financial posting remains in DV.
- Procurement IAR acceptance should feed inventory accountability exactly once; ICS/PAR, transfer and return retain event history.
- Withheld-tax transfer to Division is a liability settlement, not a second procurement expense.

Build only what can be tested against exact current source. Avoid broad speculative refactoring.
