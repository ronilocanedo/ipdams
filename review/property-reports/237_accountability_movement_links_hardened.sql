-- REVIEW-ONLY MIGRATION CANDIDATE. Requires verified 0.2.236 schema and isolated backup/restore rehearsal.
-- These additional unique indexes contain the parent primary key and cannot introduce row collisions,
-- but ALTER TABLE may lock/rebuild large tables. The actual updater must plan this safely.
ALTER TABLE assets ADD UNIQUE KEY uq_assets_link_identity (id, organization_id);
ALTER TABLE accountability_documents ADD UNIQUE KEY uq_accountability_link_identity (id, asset_id, organization_id);
ALTER TABLE asset_movements ADD UNIQUE KEY uq_movements_link_identity (id, asset_id);

CREATE TABLE accountability_movement_links (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 organization_id BIGINT UNSIGNED NOT NULL,
 asset_id BIGINT UNSIGNED NOT NULL,
 accountability_document_id BIGINT UNSIGNED NOT NULL,
 asset_movement_id BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_aml_document (accountability_document_id),
 UNIQUE KEY uq_aml_movement (asset_movement_id),
 KEY idx_aml_asset_org (asset_id, organization_id),
 KEY idx_aml_doc_identity (accountability_document_id, asset_id, organization_id),
 KEY idx_aml_movement_identity (asset_movement_id, asset_id),
 CONSTRAINT fk_aml_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
 CONSTRAINT fk_aml_asset_org FOREIGN KEY (asset_id, organization_id)
   REFERENCES assets (id, organization_id),
 CONSTRAINT fk_aml_doc_identity FOREIGN KEY (accountability_document_id, asset_id, organization_id)
   REFERENCES accountability_documents (id, asset_id, organization_id),
 CONSTRAINT fk_aml_move_identity FOREIGN KEY (asset_movement_id, asset_id)
   REFERENCES asset_movements (id, asset_id)
) ENGINE=InnoDB;
-- Database enforces same organization and asset across document/movement/asset.
-- Doc FINALIZED, ISSUE event and custodian are checked in the existing PHP transaction;
-- they are NOT immutable/constraint-enforced by this SQL. Do not allow direct link inserts.
