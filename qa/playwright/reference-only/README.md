# Imported historical Playwright source references — disabled by design

Source: Google Drive / IPDAMS-DV-Local-QA / qa/playwright, November 16, 2026.
These files are **not executable tests** in this repository until explicitly
reviewed, renamed and provided with an approved local isolated QA runner.
The `.txt` suffix is deliberate to prevent accidental test discovery.

- `14-qa-sidebar-sweep` and `18-qa-all-finalized-dv-print`: previously used in read-only restored Chromium testing; still perform synthetic login.
- `04-reimbursement-recovery-readonly`: previously used for read-only draft reconciliation; synthetic login.
- `05-reimbursement-payment-guards`: **negative financial API POST tests**; do not run against any non-isolated environment.
- `_qa-support` and `_sim-support`: historical matching helpers, requiring re-review before activation.

Do not copy original `.env`, secrets.clixml, SQL dumps, node_modules, screenshots or reports into Git.
Do not automatically run these tests or reinterpret historical PASS as a new execution.
The positive one-time DV finalization test is intentionally **excluded**.
