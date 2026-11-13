# IPDAMS code-only source discovery — 13 November 2026
**Status:** HOSTINGER READ-ONLY METADATA INVENTORY; NOT A SANITIZED SOURCE EXPORT OR DEPLOYABLE MIRROR.

Collected from read-only file listing of the existing IPDAMS website at Hostinger. File paths are relative to the hosted document root. Every candidate below requires first-party license/provenance review, embedded-secret and personal-data review, SHA-256 verification, and source-to-runtime matching before any GitHub import.

## Counts from specific directories
| Directory | Files at immediate level | Bytes (immediate files only) |
| --- | ---: | ---: |
| `private/app` | 73 | 2105413 |
| `api` | 21 | 247798 |
| `private/frontend` | 4 | 2331 |
| `private/frontend/src` | 1 | 30416 |
| `private/tests` | 3 | 12498 |
| `private/scripts` | 2 | 1582 |
| `private/db` | 0 | 0 |
| `private/db/migrations` | 2 | 23595 |
| `private/db/seed` | 1 | 2118 |
| `assets` | 105 | 2521065 |

**Candidate-first-party source metadata:** 202 files (NOT approved for import). **Additional files not in candidate table:** 10; notably vendor React UMD libraries, images, and database SQL requiring separate legal/security review.

## Review queue — likely code files only
| Path | Listed bytes | Status |
| --- | ---: | --- |
| `api/action-center.php` | 1705 | Metadata only; content/hashes unverified |
| `api/attachment.php` | 3861 | Metadata only; content/hashes unverified |
| `api/branding.php` | 680 | Metadata only; content/hashes unverified |
| `api/employee_reimbursements.php` | 2399 | Metadata only; content/hashes unverified |
| `api/index.php` | 40264 | Metadata only; content/hashes unverified |
| `api/monthly_issuances.php` | 7105 | Metadata only; content/hashes unverified |
| `api/mooe_monitor.php` | 33289 | Metadata only; content/hashes unverified |
| `api/pr_xlsx.php` | 3531 | Metadata only; content/hashes unverified |
| `api/print.php` | 17546 | Metadata only; content/hashes unverified |
| `api/property-lifecycle.php` | 11696 | Metadata only; content/hashes unverified |
| `api/property-returns.php` | 14975 | Metadata only; content/hashes unverified |
| `api/property-transfer-intake.php` | 21916 | Metadata only; content/hashes unverified |
| `api/property-transfers.php` | 26956 | Metadata only; content/hashes unverified |
| `api/property.php` | 4603 | Metadata only; content/hashes unverified |
| `api/semi_expendable_reports.php` | 16117 | Metadata only; content/hashes unverified |
| `api/tax_utilization.php` | 14994 | Metadata only; content/hashes unverified |
| `api/textbook-transfer-print.php` | 1482 | Metadata only; content/hashes unverified |
| `api/transactions.php` | 3028 | Metadata only; content/hashes unverified |
| `api/travel_branding_image.php` | 1113 | Metadata only; content/hashes unverified |
| `api/travel_print.php` | 17702 | Metadata only; content/hashes unverified |
| `api/travel_word.php` | 2836 | Metadata only; content/hashes unverified |
| `assets/action-center.css` | 3243 | Metadata only; content/hashes unverified |
| `assets/app.css` | 18066 | Metadata only; content/hashes unverified |
| `assets/app.js` | 1486205 | Metadata only; content/hashes unverified |
| `assets/branding-pages.css` | 15695 | Metadata only; content/hashes unverified |
| `assets/branding-ui.css` | 1592 | Metadata only; content/hashes unverified |
| `assets/cash-bank-349.css` | 971 | Metadata only; content/hashes unverified |
| `assets/cash-bank-tabs.css` | 4126 | Metadata only; content/hashes unverified |
| `assets/catalog-registry-links.css` | 477 | Metadata only; content/hashes unverified |
| `assets/check-print-proof.css` | 3641 | Metadata only; content/hashes unverified |
| `assets/check-print-proof.js` | 14366 | Metadata only; content/hashes unverified |
| `assets/compact-ui.css` | 6649 | Metadata only; content/hashes unverified |
| `assets/dense-ui.css` | 11226 | Metadata only; content/hashes unverified |
| `assets/division-classifications.css` | 435 | Metadata only; content/hashes unverified |
| `assets/division-consolidation-ui.css` | 10388 | Metadata only; content/hashes unverified |
| `assets/division-procurement-checkbox.css` | 1912 | Metadata only; content/hashes unverified |
| `assets/division-property-registry.css` | 1699 | Metadata only; content/hashes unverified |
| `assets/dv-compose.css` | 13664 | Metadata only; content/hashes unverified |
| `assets/dv-prpo-354.css` | 931 | Metadata only; content/hashes unverified |
| `assets/dv-register-compact.css` | 1384 | Metadata only; content/hashes unverified |
| `assets/employee-reimbursement-339.css` | 1811 | Metadata only; content/hashes unverified |
| `assets/fund-audit-352.css` | 2642 | Metadata only; content/hashes unverified |
| `assets/fund-policy-responsive-372.css` | 3226 | Metadata only; content/hashes unverified |
| `assets/fund-policy-setup-370.css` | 351 | Metadata only; content/hashes unverified |
| `assets/fund-workflow-364.css` | 924 | Metadata only; content/hashes unverified |
| `assets/ics-property-picker.css` | 3661 | Metadata only; content/hashes unverified |
| `assets/ics-ris-pair-print.css` | 8882 | Metadata only; content/hashes unverified |
| `assets/ics-ris-pair-print.js` | 18655 | Metadata only; content/hashes unverified |
| `assets/inventory-tabs.css` | 3143 | Metadata only; content/hashes unverified |
| `assets/monthly-issuances.css` | 3175 | Metadata only; content/hashes unverified |
| `assets/monthly-issuances.js` | 160 | Metadata only; content/hashes unverified |
| `assets/mooe-monitor-333.css` | 1458 | Metadata only; content/hashes unverified |
| `assets/official-print-init.js` | 366 | Metadata only; content/hashes unverified |
| `assets/organization-tabs.css` | 1114 | Metadata only; content/hashes unverified |
| `assets/pr-accounting-preview.css` | 731 | Metadata only; content/hashes unverified |
| `assets/pr-catalog-quick-add.css` | 344 | Metadata only; content/hashes unverified |
| `assets/pr-compact-items.css` | 2529 | Metadata only; content/hashes unverified |
| `assets/pr-create-modal.css` | 1247 | Metadata only; content/hashes unverified |
| `assets/pr-details.css` | 4830 | Metadata only; content/hashes unverified |
| `assets/pr-edit.css` | 3690 | Metadata only; content/hashes unverified |
| `assets/pr-property-lineage.css` | 856 | Metadata only; content/hashes unverified |
| `assets/pr-start.css` | 2094 | Metadata only; content/hashes unverified |
| `assets/pr-unit-fund-ui-365.css` | 3253 | Metadata only; content/hashes unverified |
| `assets/procurement-workflow.css` | 8357 | Metadata only; content/hashes unverified |
| `assets/property-auto-registration.js` | 2870 | Metadata only; content/hashes unverified |
| `assets/property-batch-registration.css` | 1160 | Metadata only; content/hashes unverified |
| `assets/property-detail-modal.css` | 7668 | Metadata only; content/hashes unverified |
| `assets/property-inventory-modern.css` | 14755 | Metadata only; content/hashes unverified |
| `assets/property-register-details.css` | 2579 | Metadata only; content/hashes unverified |
| `assets/property-returns.css` | 5854 | Metadata only; content/hashes unverified |
| `assets/property-returns.js` | 5477 | Metadata only; content/hashes unverified |
| `assets/property-transfers.js` | 5602 | Metadata only; content/hashes unverified |
| `assets/property-workspace-navigation.css` | 4223 | Metadata only; content/hashes unverified |
| `assets/property-workspace.css` | 1986 | Metadata only; content/hashes unverified |
| `assets/property-workspace.js` | 9587 | Metadata only; content/hashes unverified |
| `assets/reference-navigation.css` | 2552 | Metadata only; content/hashes unverified |
| `assets/reimbursement-pr-default-360.css` | 1975 | Metadata only; content/hashes unverified |
| `assets/reimbursement-uacs-359.css` | 1753 | Metadata only; content/hashes unverified |
| `assets/rfq-quotation-status-367.css` | 843 | Metadata only; content/hashes unverified |
| `assets/ris-363.css` | 2969 | Metadata only; content/hashes unverified |
| `assets/role-functions.css` | 2168 | Metadata only; content/hashes unverified |
| `assets/semi-expendable-reports.css` | 3748 | Metadata only; content/hashes unverified |
| `assets/semi-expendable-reports.js` | 166 | Metadata only; content/hashes unverified |
| `assets/semi-issued-report.css` | 4823 | Metadata only; content/hashes unverified |
| `assets/settings-tools.js` | 2749 | Metadata only; content/hashes unverified |
| `assets/siif-fund-accounting-371.css` | 990 | Metadata only; content/hashes unverified |
| `assets/supplier-tax-profiles.css` | 2390 | Metadata only; content/hashes unverified |
| `assets/tax-transfer-374.css` | 2578 | Metadata only; content/hashes unverified |
| `assets/textbook-book-ledger.css` | 2069 | Metadata only; content/hashes unverified |
| `assets/textbook-book-profiles.css` | 13443 | Metadata only; content/hashes unverified |
| `assets/textbook-lifecycle.css` | 3260 | Metadata only; content/hashes unverified |
| `assets/textbook-transfers.css` | 2271 | Metadata only; content/hashes unverified |
| `assets/transaction-archive.css` | 417 | Metadata only; content/hashes unverified |
| `assets/transaction-archive.js` | 6307 | Metadata only; content/hashes unverified |
| `assets/travel-299.css` | 5479 | Metadata only; content/hashes unverified |
| `assets/travel-305.css` | 4050 | Metadata only; content/hashes unverified |
| `assets/travel-310.css` | 1544 | Metadata only; content/hashes unverified |
| `assets/travel-311.css` | 7751 | Metadata only; content/hashes unverified |
| `assets/travel-312.css` | 1056 | Metadata only; content/hashes unverified |
| `assets/travel-313.css` | 1021 | Metadata only; content/hashes unverified |
| `assets/travel-315.css` | 1307 | Metadata only; content/hashes unverified |
| `assets/travel-316.css` | 981 | Metadata only; content/hashes unverified |
| `assets/travel-318-appendix47-print.css` | 3361 | Metadata only; content/hashes unverified |
| `assets/travel-320-cenrr-print.css` | 2978 | Metadata only; content/hashes unverified |
| `assets/travel-323.css` | 5510 | Metadata only; content/hashes unverified |
| `assets/travel-documents.css` | 1029 | Metadata only; content/hashes unverified |
| `assets/travel-dv-327.css` | 1731 | Metadata only; content/hashes unverified |
| `assets/travel-dv-332.css` | 4643 | Metadata only; content/hashes unverified |
| `assets/travel-itinerary.css` | 2450 | Metadata only; content/hashes unverified |
| `assets/travel-no-receipt-print.css` | 658 | Metadata only; content/hashes unverified |
| `assets/travel-reimbursement.css` | 3434 | Metadata only; content/hashes unverified |
| `assets/uacs-reference.css` | 4671 | Metadata only; content/hashes unverified |
| `assets/version-badge.js` | 1228 | Metadata only; content/hashes unverified |
| `assets/withheld-tax-344.css` | 2031 | Metadata only; content/hashes unverified |
| `assets/workflow-po-appendix61.css` | 1249 | Metadata only; content/hashes unverified |
| `assets/workflow-quotation-tree.css` | 1352 | Metadata only; content/hashes unverified |
| `assets/workflow-quote-live-total-369.css` | 1209 | Metadata only; content/hashes unverified |
| `assets/workflow-register-modal.css` | 2780 | Metadata only; content/hashes unverified |
| `assets/workspace-switch.css` | 1350 | Metadata only; content/hashes unverified |
| `private/app/ActionCenterWorkflow.php` | 6935 | Metadata only; content/hashes unverified |
| `private/app/AnnualDocumentNumbering.php` | 3452 | Metadata only; content/hashes unverified |
| `private/app/AwardDocuments.php` | 22597 | Metadata only; content/hashes unverified |
| `private/app/BackupService.php` | 24998 | Metadata only; content/hashes unverified |
| `private/app/BankFundRegister.php` | 10997 | Metadata only; content/hashes unverified |
| `private/app/bootstrap.php` | 10512 | Metadata only; content/hashes unverified |
| `private/app/CatalogRegistryLinks.php` | 13229 | Metadata only; content/hashes unverified |
| `private/app/DateQuality.php` | 4026 | Metadata only; content/hashes unverified |
| `private/app/DivisionConsolidation.php` | 22260 | Metadata only; content/hashes unverified |
| `private/app/DivisionPropertyRegistry.php` | 386905 | Metadata only; content/hashes unverified |
| `private/app/DivisionSchoolAccess.php` | 2887 | Metadata only; content/hashes unverified |
| `private/app/Domain.php` | 610257 | Metadata only; content/hashes unverified |
| `private/app/DvAppendix32.php` | 22430 | Metadata only; content/hashes unverified |
| `private/app/EmployeeClaims.php` | 58831 | Metadata only; content/hashes unverified |
| `private/app/FundCashFinalization.php` | 3458 | Metadata only; content/hashes unverified |
| `private/app/FundCashFundingPreview.php` | 3082 | Metadata only; content/hashes unverified |
| `private/app/FundTransactionAudit.php` | 27761 | Metadata only; content/hashes unverified |
| `private/app/IarAppendix62.php` | 19237 | Metadata only; content/hashes unverified |
| `private/app/IarAutoPropertyPolicy.php` | 740 | Metadata only; content/hashes unverified |
| `private/app/IarDocumentCorrections.php` | 9792 | Metadata only; content/hashes unverified |
| `private/app/IarSignatories.php` | 7809 | Metadata only; content/hashes unverified |
| `private/app/MonthlyIssuances.php` | 3999 | Metadata only; content/hashes unverified |
| `private/app/NumberingSetup.php` | 9103 | Metadata only; content/hashes unverified |
| `private/app/OfficialAccountabilityPrint.php` | 10144 | Metadata only; content/hashes unverified |
| `private/app/PoDocuments.php` | 43575 | Metadata only; content/hashes unverified |
| `private/app/PrClassificationPreview.php` | 11823 | Metadata only; content/hashes unverified |
| `private/app/ProcurementDocumentDates.php` | 9526 | Metadata only; content/hashes unverified |
| `private/app/PropertyBatchMetadata.php` | 6187 | Metadata only; content/hashes unverified |
| `private/app/PropertyEvidenceSnapshots.php` | 17807 | Metadata only; content/hashes unverified |
| `private/app/PropertyLifecycleWorkflow.php` | 16718 | Metadata only; content/hashes unverified |
| `private/app/PropertyLineageReview.php` | 12830 | Metadata only; content/hashes unverified |
| `private/app/PropertyRegistrationNumbers.php` | 3309 | Metadata only; content/hashes unverified |
| `private/app/PropertyReturnWorkflow.php` | 21901 | Metadata only; content/hashes unverified |
| `private/app/PropertyTransferAcceptance.php` | 43393 | Metadata only; content/hashes unverified |
| `private/app/PropertyTransferWorkflow.php` | 34057 | Metadata only; content/hashes unverified |
| `private/app/PrPrint.php` | 10055 | Metadata only; content/hashes unverified |
| `private/app/PrXlsx.php` | 12877 | Metadata only; content/hashes unverified |
| `private/app/ReimbursementFinalization.php` | 6019 | Metadata only; content/hashes unverified |
| `private/app/ReleaseVersion.php` | 158 | Metadata only; content/hashes unverified |
| `private/app/ResetService.php` | 38931 | Metadata only; content/hashes unverified |
| `private/app/RfqCanvassPrint.php` | 7299 | Metadata only; content/hashes unverified |
| `private/app/RfqCanvassSignatories.php` | 8869 | Metadata only; content/hashes unverified |
| `private/app/RfqQuotationEligibility.php` | 3733 | Metadata only; content/hashes unverified |
| `private/app/RoleFunctions.php` | 27197 | Metadata only; content/hashes unverified |
| `private/app/SchemaHealth.php` | 10520 | Metadata only; content/hashes unverified |
| `private/app/SchoolFundDownloadLink.php` | 9536 | Metadata only; content/hashes unverified |
| `private/app/SchoolNumberingPage.php` | 8274 | Metadata only; content/hashes unverified |
| `private/app/SemiExpendableReportCenter.php` | 6805 | Metadata only; content/hashes unverified |
| `private/app/SettingsService.php` | 67105 | Metadata only; content/hashes unverified |
| `private/app/SystemBranding.php` | 6556 | Metadata only; content/hashes unverified |
| `private/app/TaxTransferRules.php` | 3607 | Metadata only; content/hashes unverified |
| `private/app/TextbookBookLedger.php` | 12881 | Metadata only; content/hashes unverified |
| `private/app/TextbookBookProfiles.php` | 30855 | Metadata only; content/hashes unverified |
| `private/app/TextbookCoIar.php` | 11124 | Metadata only; content/hashes unverified |
| `private/app/TextbookCoReceiptWorkflow.php` | 38051 | Metadata only; content/hashes unverified |
| `private/app/TextbookDirectory.php` | 6441 | Metadata only; content/hashes unverified |
| `private/app/TextbookIsbn.php` | 2259 | Metadata only; content/hashes unverified |
| `private/app/TextbookLifecycleWorkflow.php` | 35079 | Metadata only; content/hashes unverified |
| `private/app/TextbookReceiptDistributionRules.php` | 5326 | Metadata only; content/hashes unverified |
| `private/app/TextbookTeacherPrint.php` | 4230 | Metadata only; content/hashes unverified |
| `private/app/TextbookTransferPrint.php` | 10134 | Metadata only; content/hashes unverified |
| `private/app/TextbookTransferWorkflow.php` | 39018 | Metadata only; content/hashes unverified |
| `private/app/TravelBranding.php` | 10749 | Metadata only; content/hashes unverified |
| `private/app/TravelDtePolicy.php` | 13023 | Metadata only; content/hashes unverified |
| `private/app/TravelExpenseTimeline.php` | 17643 | Metadata only; content/hashes unverified |
| `private/app/TravelExpenseWorkflow.php` | 22864 | Metadata only; content/hashes unverified |
| `private/app/TravelItineraryLines.php` | 7816 | Metadata only; content/hashes unverified |
| `private/app/TravelProvinceDirectory.php` | 2987 | Metadata only; content/hashes unverified |
| `private/app/TravelReimbursement.php` | 33520 | Metadata only; content/hashes unverified |
| `private/app/TravelReimbursementLocks.php` | 501 | Metadata only; content/hashes unverified |
| `private/app/TravelResetDocumentBoundary.php` | 11834 | Metadata only; content/hashes unverified |
| `private/app/TravelWord.php` | 17930 | Metadata only; content/hashes unverified |
| `private/app/TravelWorkflow.php` | 27040 | Metadata only; content/hashes unverified |
| `private/frontend/offline-build.sh` | 687 | Metadata only; content/hashes unverified |
| `private/frontend/package.json` | 271 | Metadata only; content/hashes unverified |
| `private/frontend/README.md` | 1154 | Metadata only; content/hashes unverified |
| `private/frontend/src/app.tsx` | 30416 | Metadata only; content/hashes unverified |
| `private/frontend/tsconfig.json` | 219 | Metadata only; content/hashes unverified |
| `private/scripts/generate_admin_sql.php` | 778 | Metadata only; content/hashes unverified |
| `private/scripts/migrate.php` | 804 | Metadata only; content/hashes unverified |
| `private/tests/integration.php` | 10677 | Metadata only; content/hashes unverified |
| `private/tests/settings_smoke.php` | 646 | Metadata only; content/hashes unverified |
| `private/tests/unit.php` | 1175 | Metadata only; content/hashes unverified |

## Explicit exclusions and open questions
- Exclude all `private/.env*`, `private/storage/`, uploads, transaction records, backups, runtime databases, downloaded files, staff data, bank information and any signed documents. These are **not** part of this inventory or a GitHub import.
- `private/db/`, `private/db/migrations/` and `private/db/seed/` may contain SQL and data fixtures; review independently. **Never assume a `demo.sql` seed is safe or synthetic.**
- `assets/react.production.min.js` and `assets/react-dom.production.min.js` are third-party UMD distribution artifacts and should not be copied as first-party code; verify licenses.
- The public `assets/` tree may contain generated production JS/CSS rather than canonical editable source. Cross-check with `private/frontend/` before committing.
- No content digest was provided by Hostinger's listing API. Bytes are inventory metadata, **not SHA-256 checksums** or evidence of identical content.
- Installed version remains unverified. The `private/app/ReleaseVersion.php` bootstrap constant is explicitly not the installed-version state. Do not infer current release from filenames.
- Read-only checks of `private/frontend/package.json` and `private/frontend/README.md` indicate TypeScript 5.8-family tooling and an older React 16 UMD offline build; confirm actual compiled/runtime source before designing modern build automation. Existing `private/tests/unit.php` contains arithmetic assertions; **they have not been executed by this inventory process**.

## Required next step
Make a narrowly scoped, approved **code-only source capture** after exact installed-version readback. Use locally controlled secret scanning, independent hashes, and file-by-file review before proposing any commit; imported files require authenticated role/tenant and synthetic MariaDB tests before they are called release-ready. Keep the live site untouched.
