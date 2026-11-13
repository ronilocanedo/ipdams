# IPDAMS frontend — reproducible legacy static snapshot

## What is deployed

The official v0.2.375 frontend loads top-level app.html and assets/app.js (about 1.49 MB), all required local CSS/JS assets, and the legacy React and ReactDOM UMD files. Source revision v0.2.375 was captured as 210 first-party paths in separate draft PR #83. This companion draft branch adds the two original deployed third-party UMD files byte-for-byte, with hashes and MIT license in THIRD_PARTY_NOTICES.md.

Important: private/frontend/src/app.tsx is an outdated development prototype. It does NOT rebuild the deployed assets/app.js and must never overwrite it. React 16 is unsupported and is NOT a security-approved upgrade target.

## Offline reproducibility, no network or npm

From private/frontend run:

    python3 build_static.py

Or run:

    ./offline-build.sh

Python 3.9+ is required; no npm download, Node build, web requests, or production server access. The assembler verifies exact SHA-256 for deployed React 16.0.0 and ReactDOM 16.0.1, requires their original version headers, validates every local asset reference in app.html, and checks React loads before app.js. It copies app.html, all top-level assets/ source files and the MIT notice into a new private/frontend/dist-static/ directory, then writes a deterministic build-manifest.json with SHA-256 for every file.

The builder refuses to overwrite an existing output. For a repeat build, choose another empty output path:

    python3 build_static.py --output /tmp/ipdams-frontend-rebuild2

The generated directory contains only static frontend assets. It cannot authenticate, transact, or serve API endpoints without the separate PHP and MariaDB backend. No changes to the actual installed website are made.

## Prototype TypeScript — strictly separate

The checked-in legacy TypeScript 5.8.3 prototype can be typechecked with npm tooling when available, but is not part of static reproducibility. The legacy compiler output is isolated to dist-legacy/. No TypeScript code is allowed to overwrite the production-sized assets/app.js without an independently reviewed migration.

## Controls and future work

1. Run python3 test_build_static.py (offline, no services) and python3 build_static.py.
2. Preserve original code hashes in draft PR #83; changes in this branch must not modify the 210 original first-party files or silently swap the current React runtime.
3. Keep GitHub repository PRIVATE and this PR DRAFT; do not merge, deploy, run migrations, issue checks, post DVs or touch real funds.
4. React 16 is obsolete. A later migration to a supported React release requires a maintained build source for the REAL app.js, pinned dependencies, browser/UI parity checks, security review and isolated procurement/travel/DV/property regression testing.
5. Confirm third-party rights, complete privacy review, and run isolated MariaDB tests before any live deployment.

This snapshot is intended to make archived legacy frontend bytes reproducible for auditing; it does not assert a secure production build or a supported modern dependency stack.
