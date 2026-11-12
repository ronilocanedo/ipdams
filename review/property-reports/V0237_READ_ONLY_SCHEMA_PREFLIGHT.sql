-- IPDAMS v0.2.237 / Gate D. READ ONLY information_schema inspection.
-- Run only in an authorized schema; never share credentials or data rows.
SELECT c.TABLE_NAME,c.COLUMN_NAME,c.COLUMN_TYPE,c.IS_NULLABLE,c.COLUMN_KEY,c.EXTRA
FROM INFORMATION_SCHEMA.COLUMNS c
WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME IN
('organizations','assets','accountability_documents','asset_movements','accountability_movement_links','ipdams_schema_migrations')
ORDER BY c.TABLE_NAME,c.ORDINAL_POSITION;

SELECT s.TABLE_NAME,s.INDEX_NAME,s.NON_UNIQUE,s.SEQ_IN_INDEX,s.COLUMN_NAME
FROM INFORMATION_SCHEMA.STATISTICS s
WHERE s.TABLE_SCHEMA=DATABASE() AND s.TABLE_NAME IN
('organizations','assets','accountability_documents','asset_movements','accountability_movement_links')
ORDER BY s.TABLE_NAME,s.INDEX_NAME,s.SEQ_IN_INDEX;

SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,
 k.REFERENCED_COLUMN_NAME,k.ORDINAL_POSITION,r.UPDATE_RULE,r.DELETE_RULE
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
LEFT JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r
 ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
 AND r.TABLE_NAME=k.TABLE_NAME
 AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME IN
('organizations','assets','accountability_documents','asset_movements','accountability_movement_links')
 AND k.REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION;

SELECT t.TABLE_NAME,t.ENGINE,t.TABLE_COLLATION
FROM INFORMATION_SCHEMA.TABLES t
WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME IN
('organizations','assets','accountability_documents','asset_movements','accountability_movement_links','ipdams_schema_migrations')
ORDER BY t.TABLE_NAME;