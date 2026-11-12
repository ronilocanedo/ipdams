-- READ ONLY. Bind :organization_id using authorized server-side organization scope.
SELECT d.id AS accountability_document_id,d.organization_id,d.asset_id,d.doc_type,d.status,d.display_number,
 l.id AS link_id,l.asset_movement_id,m.asset_id AS movement_asset_id,m.event_type,
 CASE
 WHEN l.id IS NULL THEN 'MISSING_EXPLICIT_LINK'
 WHEN l.organization_id<>d.organization_id THEN 'ORGANIZATION_MISMATCH'
 WHEN m.id IS NULL THEN 'MISSING_MOVEMENT'
 WHEN m.asset_id<>d.asset_id THEN 'ASSET_MISMATCH'
 WHEN m.event_type<>'ISSUE' THEN 'NOT_ISSUE'
 WHEN NOT (m.to_custodian <=> d.custodian) THEN 'CUSTODIAN_MISMATCH'
 ELSE 'LINK_CONSISTENT'
 END AS review_status
 FROM accountability_documents d
 LEFT JOIN accountability_movement_links l ON l.accountability_document_id=d.id
 LEFT JOIN asset_movements m ON m.id=l.asset_movement_id
 WHERE d.organization_id=:organization_id AND d.status='FINALIZED' AND d.doc_type IN ('ICS','PAR')
 ORDER BY d.id;
