# IPDAMS — Integrated Procurement, Disbursement and Asset Management System

Private development repository for IPDAMS, supporting DepEd school and Division procurement, disbursement, inventory, property accountability, reporting, and audit workflows.

## Development checkpoint

- **Owner-confirmed installed application:** `v0.2.213`.
- **Next candidate:** `v0.2.214` historical property batch registration, individual-unit records and audited corrections — **not installable or validated on real MariaDB**.
- This repository is being initialized with project governance and documentation. **It is not yet a complete deployable copy of the installed application.**

## Important development controls

- Never commit production database exports, school/personnel data, credentials, tokens or signed official documents.
- Development work uses a separate review branch; release candidates require exact predecessor hashes, backup/restore rehearsal and staging testing.
- No CI/deployment workflows run on source changes. GitHub Actions remain off unless the owner approves specific manual, disposable-database validation.
- No automatic hosting deployments, financial postings, inventory updates, migrations or school-record edits.

See [Development Handoff](docs/DEVELOPMENT_HANDOFF.md), [Release Safety](docs/RELEASE_SAFETY.md), [Security](SECURITY.md) and [Actions & Cost Policy](docs/ACTIONS_AND_COST_POLICY.md).
