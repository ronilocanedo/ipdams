# IPDAMS — validated Hostinger source hash checkpoint (13 November 2026)

**Source:** Owner-uploaded output of read-only `sha256sum` manifest script executed through SSH on the Hostinger IPDAMS document root. **Scope:** allowlisted source-shaped paths, *not* all files on site. No source files or financial/employee/database records were copied to GitHub.

## Validation
- **210 of 210** entries have 64 lowercase hex SHA-256 digits, two spaces and a relative path; **zero malformed entries, duplicates, or duplicate digests**; paths sorted.
- **8/8 previously calculated provisional hashes MATCH** the user-reported Hostinger server-byte hashes. This corroborates the eight API-text reconstructions, not the other 202 files' contents.
- No obvious credentials, `.env`, uploads, private storage or database backups appear as filenames in this allowlist. **This is not file-content secret scanning.**
- Owner's original uploaded manifest SHA-256, with Windows CRLF newline bytes: `93cb8f41a6497d0fa2637469b968eabf5d25b9118d2fedeadd9fb5f8a5916790`.
- GitHub normalized LF [complete 210-entry manifest](baselines/hostinger_source_sha256_2026-11-13.sha256) SHA-256: `fcaf47c76bcde3fcb28c1743c84018824d72635436c23157323ebd3bad02e1ae`. The hash difference refers solely to **manifest line endings**, not the source-file digests.

## Inventory
| Path group | Hashes |
| --- | ---: |
| `api` | 21 |
| `assets` | 98 |
| `private/app` | 73 |
| `private/db/migrations` | 2 |
| `private/frontend` | 5 |
| `private/scripts` | 2 |
| `private/tests` | 3 |
| `top-level` | 6 |
| **TOTAL** | **210** |

## Eight cross-checked source files
| File | SHA-256 | Result |
| --- | --- | --- |
| `private/app/ReleaseVersion.php` | `5678043972f71336b412bd00aaabc11f77f4c2bfd63ff670226de33f2da539ff` | MATCH |
| `private/app/TravelReimbursementLocks.php` | `e818d378ef40cae043bd16bde8eded7d90c9ddf706cfb1ab5dac8ac3f30aeb92` | MATCH |
| `private/app/TaxTransferRules.php` | `ca5f7d7d8ec825cf5a363faf308967483f278cbc60d1956880fd64e546c0b1f5` | MATCH |
| `private/tests/unit.php` | `952f927289d9d93bb50758e86ca1341e5ec7423bc2b1569f76c31edc11a3db35` | MATCH |
| `private/app/RfqQuotationEligibility.php` | `cc1a2e7d81a722864a2493577187b13bcbc155ae95e9560467d3d3a8619bbeb9` | MATCH |
| `private/app/FundCashFundingPreview.php` | `92dbd4df8294c78e7f83aa6a1b1001713cf1920e3ed6ad2e37145c190ae5840e` | MATCH |
| `private/frontend/package.json` | `f8a5f05d5bcf7959dfce5f5af2b1d485ad6a8a98801233c756706621152131ae` | MATCH |
| `private/frontend/tsconfig.json` | `a0b552dbf149c2f96b80fc0ac780def0733a193fa02791c68033cf7aa72bc8e3` | MATCH |

## What this does not establish
This validates **a reference digest manifest of reported server file bytes** and eight independent comparisons. It does **not** verify the current installed IPDAMS version, completeness of all source files, repository-to-server parity, PHP/CSS/JS correctness, third-party library safety, secrets embedded within code, database schema, or immutability of the live site. Source files may change during hashing; the result is not an atomic release snapshot. The PowerShell-to-SSH pipeline reported a terminal CR warning after emitting the 210 lines, which were nonetheless well formed.

## Next gate
Make one repeat **read-only** on-server hash capture at a stable checkpoint and compare to this full list. Verify installed-version readback separately. Then source export must be **first-party code only** with secret/provenance review, and an isolated draft source-import PR. No GitHub→Hostinger deployment, production SQL, or AI provider sharing is authorized by this hash verification.
