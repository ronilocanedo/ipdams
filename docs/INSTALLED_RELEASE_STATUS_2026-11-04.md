# IPDAMS — Installed and Staged Release Checkpoint (2026-11-04)

## Owner-confirmed installed baseline

**v0.2.283** is installed; owner confirmed the textbook transfer Property Custodian auto-signatory defaults work. Earlier v0.2.280 paper-only CO textbook receipt and v0.2.281 navigation refresh/logo-home were also owner-tested working, as were v0.2.282 textbook transfers and v0.2.283 custodian defaults.

## NEXT RELEASE: STAGED, NOT YET INSTALLED

**v0.2.284 — Reports, Teacher Issuance/Returns, Loss/Damage and Division-Authorized Disposal** is packaged and locally tested, awaiting owner installation and live UAT.

- [v0.2.284 release notes, stock safeguards and testing](V0.2.284_TEXTBOOK_TEACHER_ACCOUNTABILITY_REPORTS_DISPOSAL_UAT_2026-11-04.md)
- [v0.2.284 version-gated installer ZIP](https://drive.google.com/file/d/1H1AtQytmL8m4m2VuJlL03Gl9m3F-ldlv/view)
- [v0.2.284 detailed installation and UAT guide](https://drive.google.com/file/d/1tjQ2kuZ8ChiGrXiZOQNtaX9w2ISi5eeA/view)
- Installer `from_version=0.2.283`, `version=0.2.284`, 9 changed files, **zero migrations**; SHA256 `fea6b84d7841b1a5350f621dfe9f926bab2669aa23115ef44c083bebacb64a98`.

The new Textbooks tabs are **Teacher Issuance**, **Disposal**, and **Reports**. Actual teacher issues debit storeroom but remain accountable; returns replenish good stock or quarantine damaged stock. Reported loss does not double-debit inventory or automatically grant relief from accountability. Proposal for disposal holds stock; **only supervising Division** can record the genuine paper authorization; **only actual authorized disposal** debits stock. Wrong undecided proposals may be withdrawn with an audit reason. Disposal proposals appear in Division's Action Center. Reports support stock, teacher accountability, loss, quarantine, disposal, transfers and movements with print/CSV.

**Business policy remains paper-based recording, not electronic signatures/approvals.** Named teachers, inspectors, custodians and approvers do not need separate sign-ins. The logged-in encoder is recorded in audit. For disposal, the Division workspace records authentic signed paper authorization and actual date/reference, which does not replace mandatory government procedures.

**Testing distinction:** v0.2.284 passed 33 isolated PHP stock/permission assertions plus local Chromium UI and syntax/package validation. Actual production MariaDB, real physical paper documents, stock reconciliation, Division roles, and CSV/printing acceptance still need owner UAT. Do not fabricate production stock transactions.

## Workflow priorities after v0.2.284 acceptance

1. User UAT: Teacher Issuance -> partial return -> lost/damaged -> quarantine; school disposal mark -> Division written authority -> school actual disposal -> report reconciliation; no over-issuance or double postings.
2. Finish any textbook defects and documentary reports (e.g. adjust forms to supplied official templates).
3. Then apply the acclaimed Textbooks compact tabs, action dialogs and high-contrast registers to **Property Accountability** and **Inventory Management**, separately and without changing financial/property authorization rules.

## Deployment caution

- The developer-connected **IPDAMS Development** Google Drive and private GitHub `ronilocanedo/ipdams` are documentation and release artifacts; GitHub `main` is NOT a verified synchronized deployable mirror of Hostinger.
- Never deploy GitHub `main` wholesale over the site. Base each version-gated update on actual installed-version files; retain independent website and SQL backups. No live website/DB writes, resets, GitHub Actions jobs or unauthorized financial/stock postings were performed to create v0.2.284.
