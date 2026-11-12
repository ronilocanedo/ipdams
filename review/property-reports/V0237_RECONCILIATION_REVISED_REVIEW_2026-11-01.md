# Revised v0.2.237 property evidence review — 2026-11-01

Source: owner-uploaded `ipdams.zip` reporting release version 0.2.236. Revised, **not-installed** package: `IPDAMS_v0.2.237_PROPERTY_EVIDENCE_RECONCILIATION_FROM_v0.2.236_REVIEW.zip`. SHA256: `db479357a6d37fd36845afbeece3c813258f1f2b21bc83e7af8f226d729ef5d4`.

Changes relative to prior v0.2.237 candidate:
- `app/SemiExpendableReports.php`: preserve read-only tenant-scoped finalized ICS evidence; add per-asset ISSUE movement counts and RETURN/TRANSFER/DISPOSAL movement counts as **review exceptions**, not as automatic custody certification.
- `public/api/semi_expendable_reports.php`: display issue evidence and subsequent custody-event counts in the grouped and detail tables.
- No migrations, no writes, no other app source replacements, no Actions execution.
- PHP 8.4 lint of both files passed; ten focused static contract checks passed; ZIP CRC and manifest SHA256 revalidated.
- **Not covered:** DB-backed join/effectivity linkage, division access UAT, official Annex A.1/A.4/A.7 print fidelity, all remaining annexes, production installation.
- The movement-count subqueries are correlated by asset identity but do not prove a causal link to a specific ICS; **never** infer balance or legal custody from these counts.
- The original `IPDAMS_v0.2.237_SEMI_EXPENDABLE_EVIDENCE_FROM_v0.2.236_REVIEW.zip` is superseded for review; do not install either until hosted database validation and security review.

Next: replace ambiguous counts with a verified document-to-event linkage or safely flag as unresolved; test report against sanitized database fixture and exact templates. Track all app code in GitHub after sanitized source alignment.