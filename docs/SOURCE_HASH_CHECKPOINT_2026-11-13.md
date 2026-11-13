**UPDATE — validated after owner SHA manifest upload:** The owner-generated 210-file server-byte SHA-256 manifest was checked and eight historical provisional digests matched **8/8**. Full register: [`SOURCE_HASH_VALIDATION_2026-11-13.md`](SOURCE_HASH_VALIDATION_2026-11-13.md). The **provisional status below is historical** and describes the point *before* the later SSH cross-check. GitHub source parity is still not established.

# SHA-256 source hash checkpoint — 13 November 2026

**Status: PROVISIONAL API-TEXT HASHES, NOT YET SERVER-BYTE VERIFIED.**  
GitHub source import remains blocked. No live Hostinger file, database, credential, Actions workflow or installed application was modified.

## Hashing method and limitations
- Used the connected Hostinger **read-only** `hosting_files_website-content` endpoint to read eight approved, non-secret, short text files only.
- For each response, the API-provided content had a UTF-8 byte count exactly **one byte below** its reported file size; the returned text ended without a newline. We inferred one trailing LF (`0A`) for hashing and verified the reconstructed length equals the reported file size.
- Computed SHA-256 from the reconstructed UTF-8 content using a JavaScript implementation self-tested against known `abc`, empty-string and `hello world` vectors.
- This validates our **API-response reconstruction**, not the actual on-disk file bytes. Different line-ending handling, normalization or a concurrent file update could still give a different server-side SHA. These hashes **must not** be used as authoritative file-integrity evidence until independently checked with `sha256sum` on the server.
- The actual installed version remains unknown from these hashes. The `ReleaseVersion.php` bootstrap constant `0.1.9` is not the current installed release.

## Eight provisional SHA-256 values

| Relative path | Reported bytes | Provisional reconstructed SHA-256 |
| --- | ---: | --- |
| `private/app/ReleaseVersion.php` | 158 | `5678043972f71336b412bd00aaabc11f77f4c2bfd63ff670226de33f2da539ff` |
| `private/app/TravelReimbursementLocks.php` | 501 | `e818d378ef40cae043bd16bde8eded7d90c9ddf706cfb1ab5dac8ac3f30aeb92` |
| `private/app/TaxTransferRules.php` | 3607 | `ca5f7d7d8ec825cf5a363faf308967483f278cbc60d1956880fd64e546c0b1f5` |
| `private/tests/unit.php` | 1175 | `952f927289d9d93bb50758e86ca1341e5ec7423bc2b1569f76c31edc11a3db35` |
| `private/app/RfqQuotationEligibility.php` | 3733 | `cc1a2e7d81a722864a2493577187b13bcbc155ae95e9560467d3d3a8619bbeb9` |
| `private/app/FundCashFundingPreview.php` | 3082 | `92dbd4df8294c78e7f83aa6a1b1001713cf1920e3ed6ad2e37145c190ae5840e` |
| `private/frontend/package.json` | 271 | `f8a5f05d5bcf7959dfce5f5af2b1d485ad6a8a98801233c756706621152131ae` |
| `private/frontend/tsconfig.json` | 219 | `a0b552dbf149c2f96b80fc0ac780def0733a193fa02791c68033cf7aa72bc8e3` |

## Exact source-byte verification helper
A **manual-only**, read-only script is stored at [`tools/ipdams_source_sha256_manifest.sh`](../tools/ipdams_source_sha256_manifest.sh).
It allows only candidate PHP, first-party-looking JS/CSS, TypeScript, development tests, selected SQL migrations (**hashes only**), and selected public entrypoints; excludes `.env`, private storage, uploads, backups, logs, private data, seed SQL and bundled third-party React runtime JS.

Local synthetic test: created three allowed text files plus deliberate `.env`, storage and third-party React files. The helper produced only the three allowed source digests; `sha256sum --check` returned OK for all. **Synthetic PASS does not establish Hostinger runtime success.**

### Execute with no file copied to the server
From a local computer with SSH access, using the actual SSH host and port shown in Hostinger hPanel:

```bash
ssh -p YOUR_PORT YOUR_USER@YOUR_SSH_HOST \
  'bash -s -- ~/domains/depedtagb.online/public_html/ipdams' \
  < IPDAMS_source_sha256_manifest.sh \
  > IPDAMS_hostinger_source_SHA256_manifest.txt
```

The SSH command streams the script over standard input, hashes whitelisted existing server files **without altering them**, and saves only the digest manifest **locally**. Do not supply SSH passwords, web credentials, original source archives or database exports in a chat.

Compare manifest values with the eight provisional digests above. Differences must be investigated by reviewing exact bytes and timestamps without overriding the server, and never 'fixed' by altering live files to match a provisional hash.

## Next acceptance steps
1. Obtain exact server-byte checksums via SSH and review file count and allowed paths.
2. Re-run at a stable source checkpoint, confirm unchanged digests and read back actual installed IPDAMS application version through an authorized method.
3. Review first-party source ownership and possible embedded secrets before extracting **code only** for a separate GitHub source-import branch; `sha256sum` does not attest confidentiality or security.
4. Match sanitized GitHub source to original server digests where unmodified; document intentional redactions and rebuild differences independently.

**Decision:** No source parity, auto-deployment, financial UAT or installed-version proof is claimed by this checkpoint.
