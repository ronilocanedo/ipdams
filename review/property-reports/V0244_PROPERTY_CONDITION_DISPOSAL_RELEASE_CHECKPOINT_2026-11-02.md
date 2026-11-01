# IPDAMS v0.2.244 — property condition and disposal implementation checkpoint
Date: 2026-11-02. **Test-site candidate; not yet authenticated-hosted UAT.**

## Produced updater ZIPs
- **Installed v0.2.242 → v0.2.244:** `IPDAMS_v0.2.244_PROPERTY_APPROVALS_FROM_v0.2.242.zip`, SHA256 `1bf380b63b8264630650e3e6570bd33e1a0dbd53f7d18884bb57d6faab3c6a33`. Seven code files. Includes previously prepared v0.2.243 immediate-internal-transfer functionality and lifecycle changes.
- **Installed v0.2.243 → v0.2.244:** `IPDAMS_v0.2.244_PROPERTY_APPROVALS_FROM_v0.2.243.zip`, SHA256 `e3a847c689d00e1120402f334520dd4dab7ca7dbaa7b71509f51f4c62774c31d`. Six code files.

Do **not install both**. The owner's last explicitly confirmed site version is **0.2.242**; owner has not yet confirmed installing the prepared 0.2.243. Respect settings preflight and source drift detection. The ZIPs are conversation artifacts, not GitHub release assets, and this GitHub review checkpoint is **not** a claim that the full current application source has been synced to GitHub.

## Actual code change
New:
- `app/PropertyLifecycleWorkflow.php`: school-scoped, locked, append-only lifecycle state machine stored using existing `document_versions` type `ASSET_LIFECYCLE`; audited decisions in `audit_events`, assigned Division Supply Officer in `configuration_versions`, physical disposal movement in `asset_movements`; no schema migration.
- `public/api/property-lifecycle.php`: authenticated same-origin embedded condition/disposal register, administrative officer appointment and CSRF-verified forms.

Patched:
- `app/Domain.php`: block legacy `asset.move DISPOSAL`; block ICS/PAR issuance for assets in lifecycle process; include independently derived lifecycle status in Asset Register and reserve formal condition labels for audited workflow.
- `app/PropertyTransferWorkflow.php`: immediate internal custody behavior from v0.2.243 plus prevent new transfers of unserviceable/pending-disposal assets under locked asset row. Exclude lifecycle-locked assets from transfer candidate list.
- `public/assets/app.js`, `public/app.html`: new integrated Condition & Disposal tab; cache-busted JS asset.
- `public/api/property-transfers.php`: only in v0.2.242 bridge; carries v0.2.243 documentary internal transfer behavior.
- No `migrations/` entries, updater/version/security core changes, or data backfills.

## Approved policy encoded
- Low (PHP 0.01–5,000): school declares unserviceable; school requests and approves disposal; school records physical completion.
- High (above PHP 5,000 and below PHP 50,000): school requests declaration, appointed Division Supply Officer approves/rejects; school requests disposal, Division Supply Officer provides a **distinct second approval**; school completes physical disposal. All stages before documented completion remain in school register and custody history; original records are never hard-deleted.
- Division Supply Officer is a named, actively scoped `DIVISION` user formally assigned by `SUPER_ADMIN` via append-only authorized configuration, not a generic Division/SUPER_ADMIN privilege.
- Disposal completion requires signed/documentary references and physical-return reference when custodian still recorded. Separate actual disposal event/status/movement and audit in one atomic DB transaction.
- Unserviceable declaration is distinct from actual disposal and never extinguishes accountability automatically.
- This workflow is only for semi-expendable assets, not PPE >= PHP 50,000. Missing/zero verified value is not auto-classified.
- Still requires legally mandated committee, physical inspection, delegation, signatures and disposal documentation outside software. UI actions are not themselves legal approvals when statutory delegation is absent.
- The separate erroneous-registration deletion/void process and COA Annex A.10 exact official print form are not included.

## Verification
- PHP syntax on new endpoint/service and patched Domain/PropertyTransferWorkflow: PASS.
- Node syntax on patched app.js: PASS.
- ZIP CRC/manifest hashes, version gates and zero migrations: PASS.
- Mock-PDO transactional lifecycle integration: **31 PASS assertions** (low/high role boundaries, two separate high-value approvals, unchanged asset before disposal, recorded physical movement, duplicate/foreign-school denial, absent return reference, late audit-failure rollback and historical event retention). This is synthetic PDO, **not live MySQL**.
- Real MariaDB and owner-hosted authenticated browser tests: **NOT yet performed**.

## Recommended focused owner validation
Verify installed version and backup, stage correct ZIP, preflight must be clean. Under Property Accountability → Condition & Disposal, test low- and high-value school items; appoint a directly scoped Division user in administrator panel; validate approvals are denied to school/unappointed Division roles; check two distinct high-value approvals; record a test physical return and disposal, confirm asset leaves *active* list only after completion, and verify original asset/ICS remains archived. Try legacy `asset.move DISPOSAL` and expect explicit rejection. No GitHub Actions or new deployment websites needed.

Related design: PR #16 remains a draft; property reporting eight COA annexes still preliminary.
