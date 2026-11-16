# Partial Hostinger source import pilot — live v0.2.375 (2026-11-13)

**Status: DRAFT / REFERENCE-ONLY / INCOMPLETE — DO NOT DEPLOY OR MERGE AS A FULL APP.**

## Source version
- On 2026-11-13, a fresh read of the website's official public `https://ipdams.depedtagb.online/version.php` endpoint returned `{"version":"0.2.375"}`. This is the **installed application version reported by the server** during the readback.
- `private/app/ReleaseVersion.php` retains bootstrap default `0.1.9` by design; **0.1.9 is not the installed runtime**.
- The version endpoint and hashes are independent observations. The hash manifest was collected earlier, not as an atomic transaction together with the version readback. **Do not claim this is a complete v0.2.375 release package.**

## Import method
The eight exact paths below were selected because their contents were available through Hostinger's read-only text retrieval endpoint and their sha256s had previously been compared with the owner-produced SSH `sha256sum` manifest.

For each file in this separate source pilot:
1. Re-read text through the connected Hostinger read-only API.
2. Normalize the omitted final LF only when the documented file byte length indicates exactly one trailing newline was removed; no other transformation allowed.
3. Independently calculate SHA-256 over reconstructed UTF-8 bytes, compare to the owner-provided original server-file hash.
4. Run a narrow precommit scan for common embedded secret patterns (private keys, cloud credentials and known API-token patterns, plain-text password assignments).
5. Commit the exact reconstructed code content to the same relative path in this **review branch**, not `main`.

The eight files verified **8/8 SHA-256 matches before import**; the initial narrow secret-pattern preflight did not flag those eight. This **does not replace** Gitleaks/trufflehog-style comprehensive scanning, licensing review, manual code audit, or GitHub readback verification.

| Source path | SHA-256 of reported server file bytes |
| --- | --- |
| `private/app/ReleaseVersion.php` | `5678043972f71336b412bd00aaabc11f77f4c2bfd63ff670226de33f2da539ff` |
| `private/app/TravelReimbursementLocks.php` | `e818d378ef40cae043bd16bde8eded7d90c9ddf706cfb1ab5dac8ac3f30aeb92` |
| `private/app/TaxTransferRules.php` | `ca5f7d7d8ec825cf5a363faf308967483f278cbc60d1956880fd64e546c0b1f5` |
| `private/app/RfqQuotationEligibility.php` | `cc1a2e7d81a722864a2493577187b13bcbc155ae95e9560467d3d3a8619bbeb9` |
| `private/app/FundCashFundingPreview.php` | `92dbd4df8294c78e7f83aa6a1b1001713cf1920e3ed6ad2e37145c190ae5840e` |
| `private/tests/unit.php` | `952f927289d9d93bb50758e86ca1341e5ec7423bc2b1569f76c31edc11a3db35` |
| `private/frontend/package.json` | `f8a5f05d5bcf7959dfce5f5af2b1d485ad6a8a98801233c756706621152131ae` |
| `private/frontend/tsconfig.json` | `a0b552dbf149c2f96b80fc0ac780def0733a193fa02791c68033cf7aa72bc8e3` |

## Coverage and release gates
- This PR contains only **8 files out of 210** source-shaped paths in the source baseline manifest (plus this explanatory document), i.e. **3.8% of those paths**. It is **not a runnable copy** and makes no claim that unit tests can run standalone; they require the missing bootstrap/domain files.
- Not copied: `private/.env`, runtime private settings, storage, databases, backups, uploads, personal/financial records, production state, seed SQL, third-party React binaries, assets/images, or other code files not yet individually verified.
- No live Hostinger file, PHP settings, database record, check number, payment, tax settlement, release state, or DNS/cache settings were changed.
- No external AI/free-model provider was given these files. No GitHub Actions workflow or installer/deployment was run.
- Before expanding this PR, obtain an independently repeatable complete safe source archive, verify each imported file's SHA against the 210-line server manifest, scan file contents locally for credentials and sensitive records, and review provenance. Prefer a **second dedicated PR** for the complete import if the review scope becomes large.
- The Foundation v1 guidance is in separate draft PR #81. Do not assume draft PR #81 has been merged.

## Readback next
For a future complete source import: `docs/baselines/hostinger_source_sha256_2026-11-13.sha256` is currently only on the Foundation v1 review branch, not necessarily `main`. Compare the source against the owner's uploaded manifest locally, not against a fabricated path in `main`.

**Decision:** This pilot demonstrates safe path-level retrieval and hash checks for 8 small source files, but **the authoritative source baseline is not established until all relevant files are reconciled and reviewed**.
