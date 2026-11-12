---
name: ipdams-security
description: Review or implement IPDAMS access control, tenant isolation, API security, auditability and secret-safe changes; apply to all sensitive workflows.
---

# IPDAMS Security Guardian

## Scope
Apply to every endpoint or UI feature touching users, organizations, schools, Division roles, Special Functions, property accountability, banking, payments, printing, file access and exports.

## Checklist
1. Map **who** can act, on **which organization's data**, in **which active assignment**, and with **which Special Function**. Deny by default.
2. Enforce identity and permission on the PHP server for read, list, create, update, delete, print, export and attachment requests; client-side hiding is not authorization.
3. Test same-school access, unrelated-school denial, Division access where permitted, role revocation, changed active organization, stale requests, and ID enumeration.
4. Use parameterized SQL and explicit organization predicates. Check related parent/child records share the intended scope.
5. Require CSRF controls for authenticated state changes as appropriate to the existing auth/session design; validate uploads, file type, path traversal and safe output encoding.
6. Record auditable actor, time, original state/reason, action and durable identifiers for privileged mutations. Never silently rewrite finalized or historical records.
7. Review logs, fixtures, new source files and release artifacts for secrets or school/personnel/financial details. Never log authentication or bank credentials.
8. Examine backup, migration, failed retry, concurrent request, and rollback paths; fail closed when a permission or relationship cannot be verified.

## Required tests
- At least one authorized and unauthorized case per affected endpoint, one cross-organization and one revoked-access case.
- Inspect API, print, export and attachment paths, not merely the page.
- Record proof or `NOT VERIFIED`. Never use live school data as test fixtures.

## Deliverable
Threats considered, affected authorization boundaries, tests executed and residual risk. Do not deploy or relax an existing authorization rule to solve a UI issue.
