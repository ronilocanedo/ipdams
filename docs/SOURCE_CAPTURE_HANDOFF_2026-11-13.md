# IPDAMS v0.2.375 source capture handoff

**Status update (owner-reported, 2026-11-13):** The local SSH source capture completed; tool output reported **210/210 original-file SHA-256 matches**, **0 blocking flags**, and **6 review flags**. The machine-readable JSON report has **not yet been supplied or assessed**, so the six findings remain unresolved; this is **not approval to package, share, merge, or deploy source**. A separate, local-only toolkit download has been delivered in the ChatGPT conversation on 2026-11-13. The toolkit contains `capture_source.sh`, `verify_source.py`, the owner-provided 210-entry SHA-256 manifest and `README_FIRST.txt`.

## Latest reported capture outcome
- WSL local folder: `C:\\Users\\Loy\\IPDAMS-safe-source\\source_capture_results\\` (private local location; not synced by this step).
- Report requested: `source_validation_report.json` **only**. Do not upload the `hostinger_source_code_only.tar` archive or run `--pack --reviewed` until all six findings are classified and authorization/security review is complete.
- SSH client initially saw an unrecognized ED25519 host key and user accepted it; independently verify Hostinger's advertised SSH fingerprint from an authenticated channel before future transfers if not already verified. The displayed fingerprint is not itself proof of server identity.
- Source hashes matching a previous live-source manifest do not prove code has no secrets, full application security, all deployed assets, or that production stayed unchanged after capture.

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
