# Hosted schema compatibility checkpoint

The owner supplied read-only SHOW CREATE TABLE outputs for assets, accountability_documents, and asset_movements. All use InnoDB and unsigned BIGINT primary/asset identity fields consistent with the proposed composite-index linkage. The new accountability_movement_links table does not yet exist, as expected.

The real asset_movements event enum is ISSUE, TRANSFER, RETURN, REPAIR, COUNT, DISPOSAL; synthetic report projections using TRANSFER_IN/TRANSFER_OUT are not yet real database adapters. Document statuses are FINALIZED and CANCELLED, and types ICS and PAR.

Open before release: hosted organizations.id exact definition and server flavor/version; actual website updater/recovery; authenticated access UAT; official COA annexes. Previously confirmed owner Docker tests A/B/C remain PASSED. No production SQL writes were performed.
