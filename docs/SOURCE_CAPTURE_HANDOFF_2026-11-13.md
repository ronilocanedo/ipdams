# IPDAMS v0.2.375 source capture handoff

**Status update (owner-reported, 2026-11-13):** The local SSH source capture completed; tool output reported **210/210 original-file SHA-256 matches**, **0 blocking flags**, and **6 review flags**. The machine-readable JSON report has **not yet been supplied or assessed**, so the six findings remain unresolved; this is **not approval to package, share, merge, or deploy source**. A separate, local-only toolkit download has been delivered in the ChatGPT conversation on 2026-11-13. The toolkit contains `capture_source.sh`, `verify_source.py`, the owner-provided 210-entry SHA-256 manifest and `README_FIRST.txt`.

## Latest reported capture outcome
- WSL local folder: `C:\\Users\\Loy\\IPDAMS-safe-source\\source_capture_results\\` (private local location; not synced by this step).
- Report requested: `source_validation_report.json` **only**. Do not upload the `hostinger_source_code_only.tar` archive or run `--pack --reviewed` until all six findings are classified and authorization/security review is complete.
- SSH client initially saw an unrecognized ED25519 host key and user accepted it; independently verify Hostinger's advertised SSH fingerprint from an authenticated channel before future transfers if not already verified. The displayed fingerprint is not itself proof of server identity.
- Source hashes matching a previous live-source manifest do not prove code has no secrets, full application security, all deployed assets, or that production stayed unchanged after capture.

## 13 November security follow-up — six UI placeholder flags

Owner uploaded `source_validation_report.json` (archive SHA-256 `42e5b85cd67e61f7bd71257a55cdb7320aa95faf97a6e1328fb85e133fe4f312`). Its result was **210/210 file hashes matching**, **0 reported BLOCK**, **6 reported REVIEW**: five email-literal hits in `assets/app.js` and one in `private/frontend/src/app.tsx`.

A **read-only Hostinger review** of the six surrounding code locations found that each is an email input-field **placeholder/example** in Division/school/administrator forms or a login form. The reported strings do not appear to be saved user account records or credentials at those inspected locations. This is a *localized source-context classification*, not clearance for the whole application.

**Critical scanner limitation found:** The original `verify_source.py` uses `list(regex.finditer(data))[:5]` per pattern **per file**. Consequently it may have missed additional email-like literals and/or credentials. Six findings is a **capped count**, not proof that the rest of the 210-file archive is clear. This must be corrected before packaging or GitHub import.

A downloadable **local-only second-pass** `IPDAMS_source_review_v2.py` and README were prepared and synthetic-tested. The new checker:
- Reopens the same restricted local source tar (no live SSH required), checks the tar's prior SHA-256 and verifies every one of the 210 files against the original manifest.
- Scans **all** regex matches without a five-per-pattern cap; includes path/line/category/hint but **never includes matched email or token values** in the JSON.
- Marks identified email input placeholders as candidates for human review **without suppressing their REVIEW flags**.
- Has **no code packing or GitHub upload command**. It cannot authorize source release or bypass manual privacy, source ownership, licensing, financial integrity and external credential-scanner review.

**Next gate:** Owner runs the second-pass tool locally and shares **`source_validation_report_v2.json` only**, not the raw `.tar` archive or any source file. No `--pack --reviewed` yet.

## Second-pass v2 result (owner uploaded, 2026-11-13)

The owner ran the locally prepared **uncapped** regex scanner on the existing code-only tar and uploaded `source_validation_report_v2.json`. Recorded:
- `tool=ipdams-source-review-v2`; `status=VERIFY_OK_REVIEW_REQUIRED`
- **210/210** original file SHA-256 values match the owner-supplied manifest; **0 BLOCK**, **6 REVIEW**, all six category `literal_email_address`; `ui_placeholder_candidates_still_review_required=6`.
- Existing local archive SHA-256 `42e5b85cd67e61f7bd71257a55cdb7320aa95faf97a6e1328fb85e133fe4f312`.
- Findings: `assets/app.js` at scanner lines **5353, 5371, 5403, 5411, 14083**; `private/frontend/src/app.tsx` at scanner line **59**. Report lines are **scanner offsets**; prior text-API inspection of nearby content shows placeholder strings at the following rendered lines (usually one line later). Avoid modifying code due to indexing differences.
- Previously inspected Hostinger code context places the six matching email literals in **contact-email, account-provisioning and login UI placeholders**. They appear low-risk *at those specific locations*, and none of these six represent a reported hard-coded token or a retrieved employee/customer record.
- The **uncapped scan found no additional literal-email matches under its own regex rules**. This is a strong local result but **does not establish that the complete 210-file source is free of passwords, private records, proprietary dependencies or secrets not covered by those patterns**.

**Decision:** the six reviewed source locations are explainable as UI examples; no code edit is required for these examples. **Packaging remains blocked** until an independent secret-pattern scanner (e.g., local Gitleaks directory scan), manual PII/license inspection, and explicit owner clearance. Never upload a raw Gitleaks report because it may contain actual secret values. No server-side source, database, application or financial operation was changed as part of this review.

## Gitleaks independently scanned — 2 potential leaks and wrapper parser fix (2026-11-13)

Owner ran Gitleaks on the verified local code archive. Gitleaks itself reported **approximately 4.36 MB scanned and 2 potential leaks**, before the original PowerShell wrapper failed with `Cannot convert the "System.Object[]" value of type "System.Object[]" to type "System.Int32"`.

**Meaning:** Gitleaks completed detection, but the script did **not** produce a trustworthy sanitized summary. The two findings are **unclassified and must not be ignored or assumed false positives**. No complete-source GitHub import, packaging, merge or deployment may proceed on this basis.

The failure is consistent with Windows PowerShell 5.1 passing a **nested JSON object array** where the script expected one scalar record's `StartLine`. Prepared a revised **local-only** v1.1 wrapper `IPDAMS_Gitleaks_Parser_Fix_v1.1_2026-11-13.zip` that uses a JSON object wrapper and explicit nested-array flattening, ensures a scalar validated start-line, validates finding file path against the 210-file manifest, and emits **only relative path, numeric line and Gitleaks rule**. It preserves original tar SHA validation and 210/210 per-file hash checks, uses the same `gitleaks dir --redact=100` rules, and deletes temporary extracted source and raw finding output. Static checks passed; native Windows PowerShell runtime execution remains owner-side **unverified**.

**Next step:** Owner replaces only the local `IPDAMS_Gitleaks_Local_Scan.ps1` with the v1.1 script and reruns it. Share **only** `source_capture_results/gitleaks_sanitized_summary.json`, not the raw scan output, archive, credential, or source file. If warnings or parser errors persist, stop and share the error without raw secret values. The two findings must be individually reviewed before any clearance.

## Follow-up: Gitleaks PowerShell JSON parser v1.1 also failed (2026-11-13)

Owner re-ran the local Gitleaks scanner after the v1.1 parser patch. Gitleaks scanned **approximately 4.36 MB**, again reporting **2 potential leaks**, but the wrapper failed with `The property 'records' cannot be found on this object.` The source capture is unaffected; the **two Gitleaks detections remain unclassified**.

**v1.2 replacement prepared:** A local-only ZIP `IPDAMS_Gitleaks_Parser_Fix_v1.2_2026-11-13.zip` contains a PowerShell 5.1 runner, a standalone WSL Python JSON sanitizer, and instructions. This avoids *all* PowerShell `ConvertFrom-Json` logic. The runner rechecks original archive SHA-256 and all 210 exact file digests, runs the same local Gitleaks `dir --redact=100` scan, then invokes WSL Python to parse Gitleaks results into a sanitized **relative_file/start_line/rule_id only** summary. Both extracted source and raw Gitleaks report remain local and are cleaned afterward. No source ZIP, GitHub upload, Hostinger write, transaction or deployment occurs.

**Testing:** Python compilation and a synthetic test matrix passed for two findings including Windows absolute and relative paths, 210 manifest entries, removal of raw secret/match values, rejection of unknown paths, malformed JSON shape, nested line numbers, archive hash mismatch, contradictory scanner codes and empty reports when Gitleaks reported findings. ZIP integrity passed. Native Windows PowerShell runtime still needs owner confirmation; do not label it proven until the owner runs it successfully.

**Next acceptance gate:** Owner uploads only `source_capture_results/gitleaks_sanitized_summary.json` created by v1.2. Both detections must be classified before any source packing or further GitHub import. Never upload raw source tar or unredacted Gitleaks findings.

## Gitleaks v1.2 multi-WSL path selection issue and v1.3 fix (2026-11-13)

Owner ran v1.2. Gitleaks AGAIN scanned ~4.36 MB and found **2 potential leaks**; the scanner itself completed. Wrapper error: `The term 'C:\\WINDOWS\\system32\\wsl.exe C:\\Program Files\\WSL\\wsl.exe C:\\Users\\Loy\\AppData\\Local\\Microsoft\\WindowsApps\\wsl.exe' is not recognized...`. Root cause: PowerShell `Get-Command wsl.exe` returned **three ApplicationInfo results** and `$wsl.Source` expanded into a concatenated set of paths rather than one executable. The fix is to explicitly select **one** command using `| Select-Object -First 1` for WSL, tar and Gitleaks before invoking `.Source`.

Local-only `IPDAMS_Gitleaks_WSL_Path_Fix_v1.3_2026-11-13.zip` prepared; includes revised PowerShell scanner and same v1.2 Python sanitizer. Only executable discovery differs; original 210 SHA-256 verification, tar hash, Gitleaks `--redact=100` rules, values-free summary and temporary cleanup preserved. ZIP and static checks passed and v1.2 Python sanitizer synthetic tests passed. **Native Windows execution remains owner-side unverified**. Two Gitleaks results remain **UNCLASSIFIED**; owner should upload only `gitleaks_sanitized_summary.json` after successful run. No approval to package/import rest of code, merge or deploy.

## v1.3 WSL path conversion failed; local v1.4 conversion prepared (2026-11-13)

Owner ran the v1.3 local Gitleaks wrapper. Gitleaks completed a 4.36 MB scan and again reported **2 potential leaks**, but `Get-WslPath` reported `wslpath: C:UsersLoyIPDAMS-safe-sourceIPDAMS_Gitleaks_Sanitize_v1.2.py` and failed. The WSL default shell had removed Windows path backslashes from the argument passed to `wslpath -u`. The original verified source TAR, source hashes, Hostinger live application and database remain unchanged. The **two possible leaks remain unclassified**.

Created **local-only v1.4** toolkit `IPDAMS_Gitleaks_WSL_Path_Fix_v1.4_2026-11-13.zip` with `IPDAMS_Gitleaks_Local_Scan_v1.4.ps1`, unchanged `IPDAMS_Gitleaks_Sanitize_v1.2.py`, and README. Instead of invoking `wslpath` through the shell, v1.4:
- requires an absolute local drive-letter Windows path and constructs corresponding `/mnt/<drive>/...` path **without invoking a shell**;
- checks that WSL can access required local files using `wsl.exe --exec test -f`;
- uses `wsl.exe --exec python3` to pass each path as one argument;
- preserves original archive hash `42e5b85cd67e61f7bd71257a55cdb7320aa95faf97a6e1328fb85e133fe4f312`, **210/210** per-file verification, Gitleaks `dir --redact=100`, metadata-only sanitized report and temporary cleanup.

Toolkit ZIP validation, Python compilation, static script guards and representative drive/path conversion checks **PASS**; **not yet tested in native Windows PowerShell**. Owner must run v1.4 locally, then upload **only** `source_capture_results/gitleaks_sanitized_summary.json` for two-findings triage. Do not upload raw TAR or Gitleaks findings, package source, merge or deploy until reviewed.

## Gitleaks v1.4 successful: two findings independently inspected (2026-11-13)

Owner uploaded `gitleaks_sanitized_summary.json` produced by the local v1.4 Windows/WSL scan. Summary reports:
- `tool=ipdams-gitleaks-local-sanitizer-v1.2`, `status=GITLEAKS_FINDINGS_REVIEW_REQUIRED`.
- **210** source file hashes verified and original local archive SHA-256 `42e5b85cd67e61f7bd71257a55cdb7320aa95faf97a6e1328fb85e133fe4f312`.
- **2 findings** with `rule_id=generic-api-key`:
  - `private/app/DvAppendix32.php`, Gitleaks `start_line=100`.
  - `private/app/Domain.php`, Gitleaks `start_line=2537`.

**Localized read-only Hostinger inspection:** the first is a SQL `config_key` lookup for DV signatories; the second is a `DV_SIGNATORIES_KEY` constant. Compared the raw literal values *in memory without printing or persisting them*: they are **identical 25-character, human-readable, underscore-delimited identifiers**, contain a signatory-related term, and neither is an API key, credential, random token or stored person record. The one-line difference between Gitleaks and Hostinger API line displays is due to source API offset, **not a different file**.

**Classification:** the two **specific** Gitleaks findings are **false positives** from `generic-api-key` detecting a configuration key name. No security fix, credential revocation or production source change is indicated by these **two sites**. The prior six email-literal UI placeholder regex REVIEW hits were also inspected and classified low risk.

**Caveat / release gate:** This is **not** a comprehensive audit of all source, dependency provenance, licensing, personal data, application behavior or authorization boundaries; regex scans may miss secrets. No source ZIP has been approved or uploaded, no GitHub source merge or Hostinger deployment has occurred, and the code is still a partial pilot. Next step is **local manual privacy/licensing/source-content review**, plus strictly allowlisted, verified code-only packaging and another comparison before any complete source import. Keep raw source TAR and raw scanner results local.

## Private 210-file review candidate helper prepared (2026-11-13)

Created downloadable local-only `IPDAMS_Private_Source_Review_Candidate_Toolkit_2026-11-13.zip`, containing one Python 3 standard-library script and a safety README. The package contains **no production source** or credentials. Its integrity was checked, and it passed synthetic normal/tampered-file/changed-findings/explicit-authority/no-overwrite tests.

The script first dry-runs validation of the **unchanged owner local source TAR**, its original pinned SHA-256 `42e5b85cd67e61f7bd71257a55cdb7320aa95faf97a6e1328fb85e133fe4f312`, all 210 original-file digest values, and **both** metadata-only scanner reports. It requires the **exact six** previously reviewed UI email placeholder matches and the **exact two** DV-signatory configuration-identifier false positives; any changed signal, extra archive path or hash mismatch fails closed.

An explicit owner flag `--prepare-candidate --confirm-authorized-private-review` is required to **prepare only a restricted local code ZIP**, `source_capture_results/IPDAMS_source_210_PRIVATE_REVIEW_CANDIDATE.zip`. It also writes `candidate_review_summary.json`. No ZIP is produced in dry-run mode, and no existing candidate is overwritten. The ZIP is **for private source audit only**, not merge or deployment approval. External sources, scans and private school/employee records must never be added to this allowlist; the script does no network calls or source execution.

**Next user action:** run dry-run locally, then create candidate only with authority to share code for private review; upload only the candidate ZIP and sanitized summary to the existing private IPDAMS development conversation. The assistant must independently validate the archive, secret/privacy/license indicators and code scope before making **a separate draft full-source import PR**. User should never upload raw capture TAR, raw Gitleaks JSON or live credentials.

## Purpose
Obtain a byte-identical code-only archive of **only** the 210 source-shaped files in the previously recorded hash manifest, in order to reconcile with GitHub. Do not copy `.env`, uploads, private storage, production user/financial records, database backups or third-party runtime binaries.

## Workflow for owner (Windows PowerShell + WSL)
1. Extract `IPDAMS_Source_Capture_v0.2.375_2026-11-13.zip` onto the local computer.
2. Open PowerShell inside the extracted toolkit folder.
3. Run this block (it copies files to your local Windows profile, not a shared Google Drive folder):
   ```powershell
   $dest = Join-Path $env:USERPROFILE 'IPDAMS-safe-source'
   New-Item -ItemType Directory -Force $dest | Out-Null
   Copy-Item .\capture_source.sh, .\verify_source.py, .\IPDAMS_hostinger_source_SHA256_manifest.txt $dest
   wsl.exe --cd $dest bash ./capture_source.sh
   ```
4. The WSL script streams original file bytes over SSH using `tar` directly to local disk. It verifies the tar member set and each file digest against the supplied SHA-256 manifest and writes a findings summary **without secret values**.
5. Share **only** `source_capture_results/source_validation_report.json` for triage. **Never upload the unreviewed `.tar` or automatic source backup**.
6. If 210/210 SHA matches and zero credential/PII flags, manually review source confidentiality/licensing before running the explicit `--pack --reviewed` command in the toolkit. Only then upload an approved code-only ZIP for subsequent staged GitHub import.

## Local synthetic QA performed
- Three fabricated source paths with expected SHA-256: **PASS**.
- Changed-byte mismatch: **rejected**.
- Packaging without explicit human review flag: **rejected**.
- Zero-alert, explicit-review ZIP packaging: **PASS**.
- Synthetic literal email/privacy alert: **reported by pathname/category only and packaging blocked**.
- Bash syntax and Python module compile: **PASS**.

These checks **do not verify** live 210-file extraction or prove a complete credential/PII scan. External Gitleaks or equivalent plus manual review is still needed. The installer, payments, DV, withholding, fund ledgers and live site are outside this capture.

## Version accuracy and limitations
A fresh read of `https://ipdams.depedtagb.online/version.php` reported installed `0.2.375`. The code-only source manifest was collected at a different instant, so source version and hash capture are not atomic and must be rechecked at source acceptance. GitHub `main` is not currently an authoritative or deployable full mirror.
