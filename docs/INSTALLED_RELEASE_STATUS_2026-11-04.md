# IPDAMS Development Checkpoint — November 6, 2026

## Last explicitly owner-confirmed installed release

**v0.2.291** is installed and working; the owner confirmed the updated Appendix-style formal RIS and ICS printouts render. Earlier v0.2.288 Historical Property quick-add appeared after refresh. Release v0.2.291 was delivered with a direct-from-0.2.290 installer and roll-ups from 0.2.288/0.2.289; the exact upgrade route chosen wasn't specified, but the **installed version v0.2.291** was confirmed.

## NEXT: v0.2.292 PACKAGED, awaiting owner installation

**Print RIS + ICS on the same A4 sheet for short 1–4 item finalized ICS batches**; show saved per-asset **Brand, Model and Serial No.** beneath the item name in small italic font on both Appendix 63 RIS and Appendix 59 ICS. Long batches, long names and unusual descriptions fall back to multiple separate pages. Standalone ICS printing receives the same subtext. No metadata is invented.

- [v0.2.292 release, tests and owner UAT](V0.2.292_RIS_ICS_ONE_PAGE_PRINT_AND_ITEM_DETAILS_UAT_2026-11-06.md).
- [v0.2.292 strict installer](https://drive.google.com/file/d/1zLAKyB-0Gv1SOERVoMaY321Kotz04m-d/view).
- Gate: **v0.2.291 → v0.2.292**, **six print/frontend files, zero SQL migrations**.
- SHA256 `fe8766598cb5ac672273ef958da3c8c939df9dd6cdc509bd6dea9775bec0b582`.
- Local Chromium print-to-PDF fixtures confirm combined **1/2/3/4 item output = one A4 page**, larger batches use separate/continuation pages, no missing signature sections. Standalone PHP ICS/RIS and escaping tested. **Live authenticated user print acceptance pending**.

## General product policy and work priorities

IPDAMS documents paper transactions and genuine signatory names; it does not require named inspectors, custodians, approvers or teachers to log in to electronically approve/sign. Issuing/receiving stock and property movements are guarded by existing backend permissions and immutable audit trails. RIS/ICS print-only changes never create new property, stock, accounting transactions, documents, approvals or document numbers.

After v0.2.292 owner testing: continue Property Accountability/Inventory Management testing; ensure school current holdings exclude transferred-out property while retaining cross-office transfer history and school/Division audit visibility. Preserve exact installed code; GitHub main is a release/documentation index, not a verified deployable mirror of Hostinger. All future patches require independent website/SQL backups and exact version gate. No live production website/SQL writes, migrations or GitHub Actions were performed while preparing v0.2.292.
