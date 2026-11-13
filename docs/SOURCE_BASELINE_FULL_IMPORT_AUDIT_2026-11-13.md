# IPDAMS v0.2.375 — full source import review (private draft, 13 November 2026)

**Status:** PREPARATION ONLY. This branch initially contains **audit metadata, not the 210 source files**. The verified code-only source candidate is still in the owner's local restricted folder. It must be pushed using the exact-hash gated helper before the draft PR can be reviewed as a source import.

**Repository controls:** `ronilocanedo/ipdams` was rechecked **PRIVATE**, with `main` as default and write permission. No action/workflow, merge, deploy, live DB or financial operation has been performed.

## Provenance and independent source ZIP validation
- Live website `version.php` reported `0.2.375` on 2026-11-13. This does not mean every captured source byte was obtained atomically with the version readback.
- Owner uploaded code-only `IPDAMS_source_210_PRIVATE_REVIEW_CANDIDATE.zip`; independent archive SHA-256: `f06202a2342d9d48716d6313dc038acfa295ea709c96d5fe9bbd46a9548a8da4` (exact match to owner's signed-off local summary).
- Owner's original local TAR SHA-256: `42e5b85cd67e61f7bd71257a55cdb7320aa95faf97a6e1328fb85e133fe4f312`. All **210/210 source files** compared byte-for-byte with the independent original Hostinger `sha256sum` manifest: no additions, omissions, duplicate names, symlinks, unsafe paths, encrypted entries, non-UTF8 content or archive checksum errors.
- Total original code bytes **4,364,158**. By path family: 73 `private/app`, 21 `api`, 98 `assets`, 5 `private/frontend`, 3 `private/tests`, 2 `private/scripts`, 2 `private/db/migrations`, 6 top-level.
- No `private/.env`, live private storage, uploads, financial/employee transaction records, database backups or bundled React JS runtimes imported from the restricted source candidate. SQL migration files are **source only** and must not be executed by source import.

## Syntax / offline tests
| Gate | Result | Notes |
| --- | --- | --- |
| PHP 8 CLI lint | **103/103 PASS** | Static syntax only |
| Node JavaScript `--check` | **13/13 PASS** | First-party .js only |
| TypeScript `tsc --noEmit` | **PASS** | Project tsconfig |
| PHP unit arithmetic | **9/9 PASS** | No production DB connection |
| Settings smoke test | **FAIL** | Test rejects `public/settings.php`; currently `settingsAllowed()` permits that path. Requires separate policy review and correction, not an import-baseline rewrite |
| DB integration tests | **NOT RUN** | Need isolated `APP_ENV=test` MariaDB; do not use production |

## Secret/privacy triage
- Owner's exhaustive local v2 regex review: **six REVIEW** findings, all examples/placeholders in contact/admin/login email input fields (five `assets/app.js`, one `private/frontend/src/app.tsx`); no other matches under this regex set.
- Owner's independent Gitleaks report: **two `generic-api-key` signals**, `private/app/DvAppendix32.php` and `private/app/Domain.php`. Direct read-only Hostinger review classified both as the same 25-character readable DV signatory **configuration identifier**, not authentication secrets. Only these two exact detections are cleared.
- Supplementary pattern checks on the uploaded 210-file ZIP: **zero** matching private-key boundaries, JWT tokens, common AWS/GitHub/OpenAI/Slack key patterns or embedded HTTP basic credentials. These heuristics are **not a full security audit**.
- No transfer of raw scanner findings/secrets, operational private data or full source to public services is authorized. Keep this repository PRIVATE and PR DRAFT.

## Source-recovery blockers
1. **Not reproducible/deployable yet.** `app.html` requires `assets/react.production.min.js` and `assets/react-dom.production.min.js`, intentionally excluded from the 210 allowlisted source files. `private/frontend/README.md` documents legacy React 16 UMD and an incomplete/development-only offline build. Reproducible modern dependency manifest/lockfile and licensed runtime assets are unresolved.
2. **Settings test discrepancy.** Investigate current update/installer allowlist versus smoke test expectation. Do not patch live without reviewed policy and staged tests.
3. **Ownership and licenses.** Source content rights, vendor artifacts, and embedded reference catalog provenance require owner/legal confirmation. No `LICENSE` or `NOTICE` file was in the allowlisted capture. This is neither proof of permission nor proof of infringement.
4. **Security/finance UAT** not performed: functional regression, accounting journal, taxes, fund postings and role authorization require isolated test database and formal review.
5. **Deployment absolutely blocked:** source baseline is not an approved release, no production backend or financial record should be modified.

## Private-only source import procedure
- Use the owner-local `IPDAMS_Private_GitHub_Source_Import_Toolkit_2026-11-13.zip` helper, after confirming authorization to upload the code to the PRIVATE repo.
- First `--dry-run` verifies the ZIP SHA-256, original 210 hashes, exact six placeholders and exact two Gitleaks flags; **no network request**.
- With `--push-to-draft --confirm-private-repo-import`, the helper clones **this exact branch** and stages only the 210 allowlisted original source paths. It validates every file before and after writing, then commits and pushes **this branch only**, with no force, no main push.
- The helper requires the owner's local GitHub authentication. No password/token should be entered into ChatGPT.
- After owner push: **verify 210 source paths in this PR**, compare selected GitHub source bytes with the manifest, run code review and request approvals. Import and review remain DRAFT until explicit release gates.

Other draft PRs remain separate: #81 foundation/210-hash reference and #82 8-file pilot. This draft intentionally does not rely on either being merged.

## Owner import attempt: WSL private GitHub authentication pending (2026-11-13)

Owner ran the local import helper successfully in `--dry-run`: ZIP SHA-256, all **210/210** file digests and sanitized security reports matched. Owner then explicitly invoked `--push-to-draft --confirm-private-repo-import`. The helper validated all inputs again but the **WSL `git clone` failed** with `remote: Repository not found` for the private HTTPS remote. This is consistent with missing WSL GitHub authentication, not a missing repository: the GitHub connector separately confirmed `ronilocanedo/ipdams` is **PRIVATE**, with owner `ronilocanedo`, default `main` and accessible draft PR #83. As of this checkpoint the PR still changes **only this audit document** and contains **none of the 210 source files**. No source Git commit or push was performed; no Hostinger or database changes were made.

**Recovery gate:** Authenticate **WSL GitHub CLI** to the correct GitHub account using browser/device sign-in (`gh auth login --hostname github.com --git-protocol https --web`, `gh auth setup-git`) and verify private branch access with read-only `git ls-remote --heads`. Never supply a token, password or SSH private key in the conversation or embed it in remote URLs. Then rerun the original exact-hash-gated helper. If the previously failed clone left a partial local directory, inspect its contents before removing anything. No merge/deploy authorization is implied.

## Owner private GitHub source push completed and independently verified (2026-11-13)

**Update to original preparation-only status above:** The owner successfully authenticated GitHub CLI in WSL as `ronilocanedo` using the browser/device flow, then ran the exact-hash-gated private source importer. The owner-reported terminal output documents:
- Local ZIP SHA-256, all **210/210** source SHA-256 hashes and sanitized scanner metadata **PASSED** again before import.
- The helper created one source-only commit `368fd98f65574ec53065947d1e92754f0bd3019a` on `chore/ipdams-full-source-review-v0.2.375-20261113` and pushed it successfully. Console reported **210 files changed** and **44,257 insertions**. No extra paths were staged by the helper.
- Authenticated GitHub connector independently verified repository visibility **PRIVATE**, default branch `main`, source commit SHA and message, and exactly **210 source paths in the commit**. Draft PR #83 now contains **211 changed paths**: those 210 source paths and this preexisting audit document.
- Independent GitHub blob SHA-1 comparison against the original approved local ZIP was performed for **20 representative paths, all 20/20 matched**, including `assets/app.js`, `private/app/Domain.php`, `private/app/DvAppendix32.php`, `private/app/EmployeeClaims.php`, `private/app/SettingsService.php`, `private/app/bootstrap.php`, `private/db/migrations/001_initial.sql`, `private/frontend/src/app.tsx`, settings tests and `version.php`. **This is a 20-file remote spot-check, not an independent remote readback of all 210 blob hashes.** The local import helper did verify all 210 source hashes prior to push.

**Outcome:** GitHub now has a **complete 210-allowlisted-file source snapshot for private code review**, not a complete runnable deployment bundle. The captured code baseline remains **v0.2.375** according to the earlier live version check; the live site may have changed since then and must be checked before any future deployment.

**Still outstanding:** failed settings smoke-test authorization discrepancy; absent separately supplied React runtime assets and reproducible dependency lockfile; manual privacy/ownership/license audit; isolated DB integration/financial UAT; code review and explicit release procedure. **Do not merge PR #83 or deploy** until these gates are addressed. Source import alone did not run migrations, change database rows, install code or post payments.

