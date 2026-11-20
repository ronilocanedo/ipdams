# IPDAMS source checkpoint through v0.2.413

- Baseline: `review/v0.2.400-source-reconciliation-20261120` at `8a1f8eb04980c1887525b9a6d83a05b4b095eaf1`.
- Source sequence: actual release manifests `0.2.401` through `0.2.413`, each verified SHA256 and `from_version` chain.
- The final v0.2.413 application sources are reconstructed by applying each Settings installer in order. This is not a production database dump or full backup.
- The v0.2.402 SQL migration is retained under `docs/release-history/v0.2.402/` for provenance. Its DDL is executed only through the vetted Settings updater, not automatically from this source-history location.
- Security: no environment secrets or generated QR signing keys should ever be committed. Runtime-generated private state and production backups belong outside Git.
- Verified by owner: v0.2.413 installed and fixed the Custody & Audit white screen. This is owner-reported and does not replace file hashes from the deployed Hostinger application.
- This review commit does **not** merge to `main` or `develop/hostinger-v0.2.375`, and does not change Hostinger. Source-only reconstruction requires final staging review.
- Pending: authenticated end-to-end financial testing, production-vs-commit checksum review, and reconciliation of uncommitted local QA files.
