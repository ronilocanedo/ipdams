# Safe source baseline reconciliation — Hostinger → private GitHub

**Inventory recorded:** 2026-11-13 (Asia/Manila) using Hostinger read-only file listing.  
**Status:** SOURCE NOT YET IMPORTED OR AUTHENTICATED.

## Observed inventory (metadata only)
Hostinger has an IPDAMS website at `ipdams.depedtagb.online`. Its listed document-root tree includes `private/app` (**73 PHP application files**), `private/tests` (3 files), `private/scripts` (2 files), top-level API PHP endpoints, and first-party JS/CSS/HTML in the public tree. The site has a `private/.env`, database and runtime/storage directories; these are **not source-import candidates**. Hostinger did not report a linked website Git repository. Presence of version-numbered CSS filenames is **not proof** of the site's installed application version.

Read-only file-content inspection confirms `private/app/ReleaseVersion.php` declares only a **bootstrap baseline 0.1.9** and expressly says installed-version state in private storage supersedes it. **Do not mistake 0.1.9 for the installed version.** The website file-content readback did **not** independently establish the actual installed version or complete file hashes at this checkpoint. Prior GitHub README reported owner-installed v0.2.368 and prepared v0.2.369 on 2026-11-12; treat both as **historical and unverified for 2026-11-13** until readback.

## Do NOT import these
- `private/.env*`, secrets or credentials, production config, API tokens and signing keys, even if accidentally embedded in a PHP source file.
- Any database dump, SQL containing real records, live migrations copied without review, backups, logs, generated exports, uploads, branding that identifies individual schools, scanned documents, personnel records, banking/check data, signed forms or runtime/storage.
- Third-party minified libraries as first-party sources without their correct licenses/provenance.
- A ZIP of the entire document root. `.gitignore` alone does not protect against embedded secrets.

## Approved procedure for a future code-only import
1. Freeze an inventory checkpoint via **read-only** Hostinger listing; confirm precise installed version and updater release manifest through authenticated read-only access. Do not infer from file names.
2. Start an explicit **allowlist**, file by file, for first-party app classes, API routes, public first-party JS/CSS/HTML, **reviewed** tests, database schema definitions/migrations and development documentation. Verify ownership and source authenticity.
3. For each candidate, retrieve safely, scan for hard-coded passwords, tokens, private names/records, server paths, URLs with credentials and sensitive fixtures. Redact only after confirming behavior is preserved; never commit uncertain content.
4. Compute SHA-256 digests and record relative path, size, provenance, sanitization review, hosted hash, accepted Git blob/commit and any intentional difference. Capture full source snapshot before making changes; do not collect credentials.
5. Compare against GitHub `main`, identify missing and outdated files, inspect code dependencies, and prepare a **separate source-import branch/PR** containing only approved code.
6. Run PHP 8.2 syntax, JS compatibility tests, existing test suite and isolated MariaDB schema checks where required. Do not treat syntax alone as approval.
7. Owner/security reviewer accepts imported source; record the exact application version tied to this snapshot. Only then describe GitHub as source-authoritative for that version.
8. Keep Hostinger deployment **manual**, version-gated and independently backed up. Never overwrite live files by git pull or merge.

## Import manifest fields
`path | type | size | hosted_sha256 | sanitized_sha256 | git_blob_sha | reviewed_by | source_version | excluded_reason | tests | status`.

## Blockers / decision record
- Current version and verified full source SHA set: **NOT VERIFIED**.
- Safe download of complete code-only contents and embedded-secret scan: **NOT PERFORMED**.
- Live database integration/UAT: **NOT PERFORMED**.
- GitHub application parity with current live site: **NOT ESTABLISHED**.
- Do not enable automatic tests that assume full-source or connect to production.

A read-only inventory is a discovery step, **not** permission to sync runtime files or alter the existing single site.
