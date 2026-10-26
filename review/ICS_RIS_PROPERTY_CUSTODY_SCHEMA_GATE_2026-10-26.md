# ICS–RIS property custody and Division Registry — schema review
**26 October 2026 • Staging • Design only; no installer, migration or code deployment.**

## Confirmed user-supplied SQL schema
`accountability_documents`: id, organization_id, asset_id NOT NULL, doc_type ICS/PAR, custodian, status FINALIZED/CANCELLED, display_number CHAR(12), created_at. No `ris_id` and one asset per accountability record.
`requisition_issue_slips`: id, organization_id, source_mode PR/DIRECT, pr_id, requestor, status DRAFT/ISSUED/REVERSED, display_number, created_by. No property issue kind or independent approval/signed-evidence fields.
`ris_lines`: id, ris_id, item_id, lot_id, requested_qty, issued_qty. No asset ID.

## Owner's exact sample and requirements
- [ICS Appendix 59 sample](https://drive.google.com/file/d/1XeTGy9JM4g1dRziGb9425SJL9jOKSgd5/view): one ICS header with multiple item rows, each with Quantity, Unit, Unit Cost, Total Cost, Description, Inventory Item No., Estimated Useful Life, and Received from / Receive by signatory blocks. Therefore one-asset-per-ICS database layout is insufficient to reproduce the approved sample.
- [RIS Appendix 63 sample](https://drive.google.com/file/d/14gcNrB5Cc7qP1j--AtzfFrlWa_8dNBm_/view): Requisition / Stock Available / Issue matrix, purpose and four independent signatory identities/dates. RIS Stock No. is row sequence 1..N, not catalog code.
- ICS Inventory Item No. must equal the immutable per-physical-asset `assets.property_tag`, not Division Registry HV/LV/reference code. Every ICS must link to a real school-scoped and approved property RIS, not a typed number. One receiving custodian may receive multiple physical units on one ICS.
- Distinguish accounting item category, Division classification (ICT Equipment, ICT ADM, Office Equipment), Division registry type and code, and unique property number. Registry has 1,586 entries in screenshot. Catalog has an existing Link Property Type workflow via catalog.registry.links/search and master.item.registry.link. Use this mapping for filter/search/eligibility; show unlinked and pending mappings, never infer category solely from names. Effective cost thresholds determine applicable HV/LV codes.

## Current source gap (v0.2.218 Domain.php, v0.2.220/221 frontend lineage)
- createRIS permits only category SUPPLY. issueRIS progresses DRAFT directly to ISSUED and creates consumable stock events. issueAccountability directly issues one ICS/PAR record for one asset and does not require RIS.
- No direct property RIS flow and no multi-line ICS header/line structure. Do not use SUPPLY lots as substitute for accountable property, duplicate RIS_ISSUE stock movements or mark a text-only link as compliant.
- The exact user-supplied SettingsService.php updater allows narrowly checked nullable ADD COLUMN and one exact checkbook table. Generic new CREATE TABLE/indexes/enum changes are not authorized. Proposed schema must pass a separately designed allowlist, migration interruption/restore rehearsal and audit review before staging installation.

## Target normalized model, subject to actual indices and document numbering review
1. RIS header retains its school-scoped unique document sequence and records `issue_kind=PROPERTY_CUSTODY` vs legacy SUPPLY_CONSUMPTION, purpose and dated request/approve/issue/acknowledge snapshots.
2. Property RIS lines associate each selected `assets.id`, matching organization and catalog item, with a line row, requested/approved/issued quantities, and allocation status; no consumable lot movement.
3. New ICS header + ICS line structure (or equivalent reviewed normalization) permits one ICS number per recipient and several distinct asset lines, each linked to a real issued property RIS line. Existing `accountability_documents` history must remain readable and printable without mutation.
4. Atomic `FOR UPDATE` same-org validation, deterministic lock order, unique constraints on line membership, existing finalized custody collision checks, idempotency and row-level audit. Signature selection/preview alone must never create issued status.
5. ICS Appendix 59 Inventory Item No. = property tag per line; linked RIS displayed and printed; no change to PAR/PPE policy and no automatic fund/accounting/UACS postings. Future RSMI uses single issuance ledger without double posting and assigned Division MOOE posted-by officer.

## Next staging gate
Require exact index/key metadata for requisition_issue_slips, ris_lines, accountability_documents, assets, asset_movements, document_registry, document_counters via SELECT on information_schema.STATISTICS; no data rows or writes. Confirm exact current print.php and registry link classes before generating installer. Source branch is design only; no Actions or added staging site.

```sql
SELECT TABLE_NAME, INDEX_NAME,
       CASE WHEN MIN(NON_UNIQUE)=0 THEN 'UNIQUE' ELSE 'NONUNIQUE' END AS index_type,
       GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ', ') AS columns_in_order
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE()
AND TABLE_NAME IN ('requisition_issue_slips','ris_lines','accountability_documents',
                   'assets','asset_movements','document_registry','document_counters')
GROUP BY TABLE_NAME,INDEX_NAME
ORDER BY TABLE_NAME,INDEX_NAME
```

## Tracking
[Owner Drive RIS/ICS/RSMI design](https://docs.google.com/document/d/1M3O6uNq2vPobBYittk_4u9Dv4pYeUKwscfQMGDrhRT0/edit) · [GitHub issue #13](https://github.com/ronilocanedo/ipdams/issues/13).
Latest owner-confirmed installed staging release v0.2.220; v0.2.221 offered but installation not confirmed. Do not guess next predecessor.