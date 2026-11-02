# IPDAMS v0.2.248 — Historical RFQ canvasser and BAC chairperson correction
Date: 2026-11-02.

## User finding / root cause
After the owner reported a late-registered AO/Property Custodian appointment, the same problem appeared for the **RFQ canvasser**. In the installed application source, `RfqCanvassSignatories::forIssue` demanded an `employee_assignments` interval covering the RFQ date for both the canvasser and BAC Chairperson, although the current school employee directory may first have been populated many months later. The client let users select current school staff, but the backend rejected these date discrepancies.

## Built code-only version-gated packages
- Installed **v0.2.246** → `IPDAMS_v0.2.248_RFQ_HISTORICAL_CANVASSER_FROM_v0.2.246.zip`, SHA256 `1c4150beb9b800f427592fd5bb70966aeb5a3cf0257b6985d4d909c2eb28e4f6`. Carries v0.2.247 AO appointment-form enhancement.
- Installed **v0.2.247** → `IPDAMS_v0.2.248_RFQ_HISTORICAL_CANVASSER_FROM_v0.2.247.zip`, SHA256 `0e1df201a5d6aaa694e10a3e6146934bc874d48499b2a70e4544c46f8a27e8a4`.

Both install **v0.2.248**; three files `app/RfqCanvassSignatories.php`, `public/assets/app.js`, `public/app.html`; **no migrations**. User last explicitly confirmed installed v0.2.246, and has not confirmed installing the v0.2.247 patch. Choose exactly one matching the website's Technical Settings version after backups and preflight. These are local conversation installers and have **not** been uploaded to the GitHub repository as ZIPs. Do not merge the review PR into main merely on simulated tests.

## Implementation
- When a same-school employee has **no historic assignment covering the selected RFQ date**, but has a **currently active** same-school directory assignment entered after that date, allow the documentary participant only if the user supplies a genuine historical appointment/order **reference 20–500 characters** and explicit confirmation; separate reference/checkbox for canvasser and BAC Chairperson when necessary.
- Explicitly reject contradictory history showing that employee was assigned to a **different school/division on the RFQ date**, or where no current org assignment exists. Refuse multiple contradictory same-day assignment episodes. Continue to require a real BAC term whose start/end brackets RFQ date and matching BAC chairperson. Procurement date order and one-RFQ-per-PR rules remain unchanged.
- Store provenance (RFQ date, actual current directory start, employee id, genuine supporting reference, attestation) in `configuration_versions` append-only signatory sidecar and corresponding audit, preserving source document hash; no edits to `employee_assignments`, older RFQs, PRs or numbering records. Signatures remain paper-based.
- UI dynamically shows evidence controls only if RFQ date predates selected currently active employee's recorded `effective_from`. Clears evidence when date/employee/school changes. Same-origin client does not override server validation; all forms remain subject to authorization.

## Local validation and remaining acceptance
- PHP syntax PASS and Node syntax PASS.
- **25** PHP mock PDO participant validation/sidecar/audit assertions PASS.
- **14** frontend date helper, state isolation and payload/syntax checks PASS.
- ZIP CRC, hashes, version gates, no migrations PASS.
- **NOT yet verified:** real MariaDB and authenticated production-like website UAT, printed reissued RFQ and cross-organization employee overlap. No GitHub Actions run or live database changes.

Related `IPDAMS_v0.2.248_RFQ_HISTORICAL_CANVASSER_RELEASE_NOTES.md` is downloadable in this conversation.
