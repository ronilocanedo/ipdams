<?php
declare(strict_types=1);
require_once __DIR__.'/Domain.php';
require_once __DIR__.'/ReleaseVersion.php';
const SETTINGS_MAX_ZIP = 20_000_000;
const SETTINGS_MAX_FILES = 350;
const SETTINGS_MAX_EXPANDED = 60_000_000;
const SETTINGS_MANIFEST = 'release.json';
const SETTINGS_MAX_MIGRATIONS = 20;
const SETTINGS_MAX_MIGRATION_SQL = 180_000;


function settingsRoot(): string { return dirname(__DIR__); }
function settingsPublicRoot(): string { return rtrim(cfg('IPDAMS_PUBLIC_DIR',dirname(settingsRoot())),'/'); }
function settingsTarget(string $path): string {
    return str_starts_with($path,'public/') ? settingsPublicRoot().'/'.substr($path,7) : settingsRoot().'/'.$path;
}
function settingsPrivate(): string {
    $p=settingsRoot().'/storage/private/settings';
    if (!is_dir($p) && !mkdir($p,0700,true) && !is_dir($p)) throw new RuntimeException('Settings storage unavailable');
    return $p;
}
function settingsAdmin(PDO $db): array {
    $user=currentUser($db);
    demand($user['role']==='SUPER_ADMIN','Only the Super Administrator can access technical settings',403);
    return $user;
}
function settingsAudit(int $uid,string $action,array $context=[]): void {
    $path=settingsPrivate().'/audit.jsonl';
    $entry=['time'=>date('c'),'user_id'=>$uid,'action'=>$action]+$context;
    if (file_put_contents($path,json_encode($entry,JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES)."\n",FILE_APPEND|LOCK_EX)===false)
        error_log('IPDAMS settings audit write failed');
    @chmod($path,0600);
}
// Keep compatibility with prior settings helpers.
function settingsLog(int $uid,string $event,array $extra=[]): void {settingsAudit($uid,$event,$extra);}
function settingsWriteJson(string $path,array $data): void {
    $tmp=$path.'.tmp-'.bin2hex(random_bytes(5));
    $json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(file_put_contents($tmp,$json,LOCK_EX)===false){@unlink($tmp);throw new RuntimeException('Cannot save update metadata');}
    @chmod($tmp,0600);
    if(!rename($tmp,$path)) {@unlink($tmp);throw new RuntimeException('Cannot replace update metadata');}
}
function settingsState(): array {
    $p=settingsPrivate().'/installed-version.json';
    if(!is_file($p)) return ['version'=>IPDAMS_RELEASE_BASELINE,'release_id'=>null,'installed_at'=>null,'source'=>'v0.1.9 bootstrap'];
    $data=json_decode((string)file_get_contents($p),true);
    if(!is_array($data)||!isset($data['version'])||!is_string($data['version'])) throw new RuntimeException('Installed version state is invalid');
    return $data;
}
function settingsAllowed(string $path): bool {
    if ($path==='' || str_contains($path,'\\') || str_contains($path,"\0") || str_contains($path,'..') || str_starts_with($path,'/') || preg_match('/[\x00-\x1F]/',$path)) return false;
    if (preg_match('#^public/assets/[A-Za-z0-9_./-]+\.(js|css|svg|png|jpg|jpeg|webp|ico|woff2)$#',$path)) return true;
    if (in_array($path,['public/app.html','public/index.php','public/settings.php','public/settings.css','public/version.php'],true)) return true;
    // Authentication bootstrap upgrades require the additional critical-code review.
    // ReleaseVersion.php stays protected to prevent source/version metadata tampering.
    if ($path==='app/bootstrap.php') return true;
    if (preg_match('#^app/[A-Za-z][A-Za-z0-9_-]*\.php$#',$path)) return !in_array($path,['app/bootstrap.php','app/ReleaseVersion.php'],true);
    if (preg_match('#^public/api/[A-Za-z][A-Za-z0-9_-]*\.php$#',$path)) return true;
    return false;
}
function settingsCritical(string $path): bool {
    return in_array($path,['app/bootstrap.php','app/SettingsService.php','public/settings.php','public/index.php','public/version.php'],true);
}

/** Exact allowlist for one designated, fail-closed property-link schema rollout. */
function settingsPropertyLinkMigrationStatements(): array {
    return [
        '20261101_link_assets_idx' => 'ALTER TABLE assets ADD UNIQUE KEY IF NOT EXISTS uq_assets_link_identity (id, organization_id)',
        '20261101_link_documents_idx' => 'ALTER TABLE accountability_documents ADD UNIQUE KEY IF NOT EXISTS uq_accountability_link_identity (id, asset_id, organization_id)',
        '20261101_link_movements_idx' => 'ALTER TABLE asset_movements ADD UNIQUE KEY IF NOT EXISTS uq_movements_link_identity (id, asset_id)',
        '20261101_link_table' => 'CREATE TABLE IF NOT EXISTS accountability_movement_links ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, organization_id BIGINT UNSIGNED NOT NULL, asset_id BIGINT UNSIGNED NOT NULL, accountability_document_id BIGINT UNSIGNED NOT NULL, asset_movement_id BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_aml_document (accountability_document_id), UNIQUE KEY uq_aml_movement (asset_movement_id), KEY idx_aml_asset_org (asset_id, organization_id), KEY idx_aml_doc_identity (accountability_document_id, asset_id, organization_id), KEY idx_aml_movement_identity (asset_movement_id, asset_id), CONSTRAINT fk_aml_org FOREIGN KEY (organization_id) REFERENCES organizations(id), CONSTRAINT fk_aml_asset_org FOREIGN KEY (asset_id, organization_id) REFERENCES assets (id, organization_id), CONSTRAINT fk_aml_doc_identity FOREIGN KEY (accountability_document_id, asset_id, organization_id) REFERENCES accountability_documents (id, asset_id, organization_id), CONSTRAINT fk_aml_move_identity FOREIGN KEY (asset_movement_id, asset_id) REFERENCES asset_movements (id, asset_id) ) ENGINE=InnoDB'
    ];
}
/** Verify DDL state, including retried DDL that was committed before its ledger entry. */
function settingsVerifyPropertyLinkMigration(PDO $db,string $migrationKey): void {
    $parentIndexes=[
        '20261101_link_assets_idx'=>['assets','uq_assets_link_identity','id,organization_id'],
        '20261101_link_documents_idx'=>['accountability_documents','uq_accountability_link_identity','id,asset_id,organization_id'],
        '20261101_link_movements_idx'=>['asset_movements','uq_movements_link_identity','id,asset_id']
    ];
    $assertIndex=static function(string $table,string $name,string $cols) use ($db): void {
        $q=$db->prepare("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols, MAX(NON_UNIQUE) AS non_unique FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?");
        $q->execute([$table,$name]);$r=$q->fetch(PDO::FETCH_ASSOC);
        demand($r && $r['cols']===$cols && (int)$r['non_unique']===0,'Property-link index verification failed: '.$table.'.'.$name);
    };
    if(isset($parentIndexes[$migrationKey])){
        $assertIndex(...$parentIndexes[$migrationKey]);return;
    }
    demand($migrationKey==='20261101_link_table','Unknown property-link migration');
    $stmt=$db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accountability_movement_links'");
    demand($stmt->fetchColumn()==='InnoDB','Property-link table missing or non-InnoDB');
    foreach([['uq_aml_document','accountability_document_id'],['uq_aml_movement','asset_movement_id']] as [$name,$cols])
        $assertIndex('accountability_movement_links',$name,$cols);
    $columns=$db->query("SELECT COLUMN_NAME,IS_NULLABLE,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accountability_movement_links'")->fetchAll(PDO::FETCH_ASSOC);
    $found=[];foreach($columns as $col)$found[$col['COLUMN_NAME']]=$col;
    foreach(['id','organization_id','asset_id','accountability_document_id','asset_movement_id'] as $col)
        demand(isset($found[$col]) && $found[$col]['IS_NULLABLE']==='NO' && str_contains($found[$col]['COLUMN_TYPE'],'bigint') && str_contains($found[$col]['COLUMN_TYPE'],'unsigned'),'Invalid property-link column: '.$col);
    $query=$db->query("SELECT CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME,ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accountability_movement_links' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME,ORDINAL_POSITION");
    $fk=[];foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row) $fk[$row['CONSTRAINT_NAME']][]=[$row['COLUMN_NAME'],$row['REFERENCED_TABLE_NAME'],$row['REFERENCED_COLUMN_NAME']];
    $expected=[
      'fk_aml_org'=>[['organization_id','organizations','id']],
      'fk_aml_asset_org'=>[['asset_id','assets','id'],['organization_id','assets','organization_id']],
      'fk_aml_doc_identity'=>[['accountability_document_id','accountability_documents','id'],['asset_id','accountability_documents','asset_id'],['organization_id','accountability_documents','organization_id']],
      'fk_aml_move_identity'=>[['asset_movement_id','asset_movements','id'],['asset_id','asset_movements','asset_id']]
    ];
    foreach($expected as $name=>$edges) demand(($fk[$name]??null)===$edges,'Property-link FK verification failed: '.$name);
}


/** Exact, immutable allowlist for travel itinerary/timeline documentary tables only. */
function settingsTravelTimelineMigrationStatements(): array {
    return [
        '20261106_travel_claim_review_versions' => 'CREATE TABLE IF NOT EXISTS travel_claim_expense_review_versions ( review_id BIGINT UNSIGNED NOT NULL, organization_id BIGINT UNSIGNED NOT NULL, version_no INT UNSIGNED NOT NULL, source_claim_version INT UNSIGNED NOT NULL, snapshot_json LONGTEXT NOT NULL, actor_id BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (review_id,version_no), KEY idx_ti_review_history_scope (organization_id,review_id) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        '20261106_travel_claim_reviews' => 'CREATE TABLE IF NOT EXISTS travel_claim_expense_reviews ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, organization_id BIGINT UNSIGNED NOT NULL, claim_id BIGINT UNSIGNED NOT NULL, source_claim_version INT UNSIGNED NOT NULL, version_no INT UNSIGNED NOT NULL, snapshot_json LONGTEXT NOT NULL, updated_by BIGINT UNSIGNED NOT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY uq_ti_claim_review (organization_id,claim_id) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        '20261106_travel_temporary_plan_versions' => 'CREATE TABLE IF NOT EXISTS travel_itinerary_plan_versions ( plan_id BIGINT UNSIGNED NOT NULL, organization_id BIGINT UNSIGNED NOT NULL, version_no INT UNSIGNED NOT NULL, snapshot_json LONGTEXT NOT NULL, actor_id BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (plan_id,version_no), KEY idx_ti_plan_history_scope (organization_id,plan_id) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        '20261106_travel_temporary_plans' => 'CREATE TABLE IF NOT EXISTS travel_itinerary_plans ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, organization_id BIGINT UNSIGNED NOT NULL, authority_id BIGINT UNSIGNED NOT NULL, version_no INT UNSIGNED NOT NULL, snapshot_json LONGTEXT NOT NULL, created_by BIGINT UNSIGNED NOT NULL, updated_by BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY uq_ti_plan_authority (organization_id, authority_id), KEY idx_ti_plan_org (organization_id, id) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ];
}

/** Fail closed on pre-existing tables and interruption/retry DDL states. */
function settingsVerifyTravelTimelineMigration(PDO $db,string $migrationKey): void {
    $specs=[
        '20261106_travel_temporary_plans'=>[
            'table'=>'travel_itinerary_plans',
            'columns'=>['id'=>'bigint unsigned auto_increment','organization_id'=>'bigint unsigned',
                'authority_id'=>'bigint unsigned','version_no'=>'int unsigned','snapshot_json'=>'longtext',
                'created_by'=>'bigint unsigned','updated_by'=>'bigint unsigned',
                'created_at'=>'timestamp current','updated_at'=>'timestamp on_update'],
            'indexes'=>['PRIMARY'=>[0,['id']], 'uq_ti_plan_authority'=>[0,['organization_id','authority_id']],
                'idx_ti_plan_org'=>[1,['organization_id','id']]],
        ],
        '20261106_travel_temporary_plan_versions'=>[
            'table'=>'travel_itinerary_plan_versions',
            'columns'=>['plan_id'=>'bigint unsigned','organization_id'=>'bigint unsigned',
                'version_no'=>'int unsigned','snapshot_json'=>'longtext',
                'actor_id'=>'bigint unsigned','created_at'=>'timestamp current'],
            'indexes'=>['PRIMARY'=>[0,['plan_id','version_no']],
                'idx_ti_plan_history_scope'=>[1,['organization_id','plan_id']]],
        ],
        '20261106_travel_claim_reviews'=>[
            'table'=>'travel_claim_expense_reviews',
            'columns'=>['id'=>'bigint unsigned auto_increment','organization_id'=>'bigint unsigned',
                'claim_id'=>'bigint unsigned','source_claim_version'=>'int unsigned',
                'version_no'=>'int unsigned','snapshot_json'=>'longtext','updated_by'=>'bigint unsigned',
                'updated_at'=>'timestamp on_update'],
            'indexes'=>['PRIMARY'=>[0,['id']], 'uq_ti_claim_review'=>[0,['organization_id','claim_id']]],
        ],
        '20261106_travel_claim_review_versions'=>[
            'table'=>'travel_claim_expense_review_versions',
            'columns'=>['review_id'=>'bigint unsigned','organization_id'=>'bigint unsigned',
                'version_no'=>'int unsigned','source_claim_version'=>'int unsigned',
                'snapshot_json'=>'longtext','actor_id'=>'bigint unsigned','created_at'=>'timestamp current'],
            'indexes'=>['PRIMARY'=>[0,['review_id','version_no']],
                'idx_ti_review_history_scope'=>[1,['organization_id','review_id']]],
        ],
    ];
    demand(isset($specs[$migrationKey]),'Unrecognized approved travel migration');
    $spec=$specs[$migrationKey];$table=$spec['table'];
    $q=$db->prepare('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND TABLE_TYPE=\'BASE TABLE\'');
    $q->execute([$table]);$actual=$q->fetch(PDO::FETCH_ASSOC);
    demand($actual!==false && $actual['ENGINE']==='InnoDB' && $actual['TABLE_COLLATION']==='utf8mb4_unicode_ci',
        'Travel schema table/engine/collation mismatch: '.$table,409);
    $q=$db->prepare('SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    $q->execute([$table]);$columns=$q->fetchAll(PDO::FETCH_ASSOC);
    demand(count($columns)===count($spec['columns']),'Travel schema unexpected column inventory: '.$table,409);
    $seen=[];
    foreach($columns as $col){
        $name=(string)$col['COLUMN_NAME'];$seen[]=$name;
        $wanted=$spec['columns'][$name]??null;
        demand(is_string($wanted) && $col['IS_NULLABLE']==='NO','Travel schema nullability/column mismatch: '.$table.'.'.$name,409);
        $parts=explode(' ',$wanted);
        $type=$parts[0];$unsigned=in_array('unsigned',$parts,true);
        demand(strtolower((string)$col['DATA_TYPE'])===$type &&
            (str_contains(strtolower((string)$col['COLUMN_TYPE']),'unsigned')===$unsigned),
            'Travel schema column type mismatch: '.$table.'.'.$name,409);
        $extra=strtolower((string)($col['EXTRA']??''));
        demand(str_contains($extra,'auto_increment')===in_array('auto_increment',$parts,true),
            'Travel schema auto-increment mismatch: '.$table.'.'.$name,409);
        $onUpdate=in_array('on_update',$parts,true);
        demand((str_contains($extra,'on update'))===$onUpdate,
            'Travel schema on-update mismatch: '.$table.'.'.$name,409);
        if($type==='timestamp'){
            $default=strtolower((string)($col['COLUMN_DEFAULT']??''));
            demand(str_contains($default,'current_timestamp'),
                'Travel schema timestamp default mismatch: '.$table.'.'.$name,409);
        }else{
            demand($col['COLUMN_DEFAULT']===null,'Travel schema unexpected column default: '.$table.'.'.$name,409);
        }
    }
    demand($seen===array_keys($spec['columns']),'Travel schema column order mismatch: '.$table,409);
    $q=$db->prepare('SELECT INDEX_NAME,SEQ_IN_INDEX,COLUMN_NAME,NON_UNIQUE,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX');
    $q->execute([$table]);$indexes=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $index){
        $name=(string)$index['INDEX_NAME'];
        demand($index['INDEX_TYPE']==='BTREE','Travel schema unexpected index type: '.$table.'.'.$name,409);
        $indexes[$name]['non_unique']=(int)$index['NON_UNIQUE'];
        $indexes[$name]['columns'][]=(string)$index['COLUMN_NAME'];
    }
    demand(count($indexes)===count($spec['indexes']),'Travel schema index inventory mismatch: '.$table,409);
    foreach($spec['indexes'] as $name=>[$nonUnique,$cols]){
        demand(isset($indexes[$name]) && $indexes[$name]['non_unique']===$nonUnique &&
            $indexes[$name]['columns']===$cols,
            'Travel schema required index mismatch: '.$table.'.'.$name,409);
    }
    $q=$db->prepare('SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL');
    $q->execute([$table]);
    demand((int)$q->fetchColumn()===0,'Travel schema has unexpected foreign keys: '.$table,409);
}

/** Dedicated, immutable, versioned travel-DV schema allowlist; never accept arbitrary ALTER/CREATE. */
function settingsTravelDvMigrationStatements(): array {
    return [
        '20261108_travel_dv_type' => 'ALTER TABLE disbursement_vouchers MODIFY COLUMN dv_type ENUM(\'REGULAR\',\'DIRECT\',\'REIMBURSEMENT\',\'TRAVEL_REIMBURSEMENT\') NOT NULL',
        '20261108_travel_dv_approvals' => 'CREATE TABLE IF NOT EXISTS travel_dv_accounting_approvals ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, organization_id BIGINT UNSIGNED NOT NULL, claim_id BIGINT UNSIGNED NOT NULL, claim_version INT UNSIGNED NOT NULL, review_version INT UNSIGNED NOT NULL, version_no INT UNSIGNED NOT NULL, employee_id BIGINT UNSIGNED NOT NULL, fund_id BIGINT UNSIGNED NOT NULL, claimed_amount DECIMAL(18,2) NOT NULL, preliminary_allowed DECIMAL(18,2) NOT NULL, approved_amount DECIMAL(18,2) NOT NULL, paper_reference VARCHAR(255) NOT NULL, approved_by BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_tda_claim_version (organization_id, claim_id, version_no), KEY ix_tda_employee_fund (organization_id, employee_id, fund_id, claim_id), CONSTRAINT fk_tda_org FOREIGN KEY (organization_id) REFERENCES organizations(id), CONSTRAINT fk_tda_claim FOREIGN KEY (claim_id) REFERENCES document_registry(id), CONSTRAINT fk_tda_employee FOREIGN KEY (employee_id) REFERENCES employees(id), CONSTRAINT fk_tda_fund FOREIGN KEY (fund_id) REFERENCES funds(id), CONSTRAINT fk_tda_user FOREIGN KEY (approved_by) REFERENCES users(id) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        '20261108_travel_dv_links' => 'CREATE TABLE IF NOT EXISTS travel_dv_claim_links ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, organization_id BIGINT UNSIGNED NOT NULL, dv_id BIGINT UNSIGNED NOT NULL, claim_id BIGINT UNSIGNED NOT NULL, allocation_line_id BIGINT UNSIGNED NOT NULL, approval_id BIGINT UNSIGNED NOT NULL, employee_id BIGINT UNSIGNED NOT NULL, fund_id BIGINT UNSIGNED NOT NULL, claim_version INT UNSIGNED NOT NULL, review_version INT UNSIGNED NOT NULL, claimed_amount DECIMAL(18,2) NOT NULL, preliminary_allowed DECIMAL(18,2) NOT NULL, allocated_amount DECIMAL(18,2) NOT NULL, claim_date DATE NOT NULL, active_claim_id BIGINT UNSIGNED NULL, status VARCHAR(24) NOT NULL DEFAULT \'ACTIVE\', created_by BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_tdl_org_active (organization_id, active_claim_id), UNIQUE KEY uq_tdl_dv_claim (dv_id, claim_id), UNIQUE KEY uq_tdl_dv_allocation (dv_id, allocation_line_id), KEY ix_tdl_org_claim (organization_id, claim_id), CONSTRAINT fk_tdl_org FOREIGN KEY (organization_id) REFERENCES organizations(id), CONSTRAINT fk_tdl_dv FOREIGN KEY (dv_id) REFERENCES disbursement_vouchers(id), CONSTRAINT fk_tdl_claim FOREIGN KEY (claim_id) REFERENCES document_registry(id), CONSTRAINT fk_tdl_allocation FOREIGN KEY (allocation_line_id) REFERENCES dv_allocation_lines(id), CONSTRAINT fk_tdl_approval FOREIGN KEY (approval_id) REFERENCES travel_dv_accounting_approvals(id), CONSTRAINT fk_tdl_user FOREIGN KEY (created_by) REFERENCES users(id) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ];
}
/** Verify retry-safe additive DDL before acknowledging migration ledger completion. */
function settingsVerifyTravelDvMigration(PDO $db,string $id): void {
    if($id==='20261108_travel_dv_type'){
        $q=$db->query("SELECT COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='disbursement_vouchers' AND COLUMN_NAME='dv_type'");
        $x=$q->fetch(PDO::FETCH_ASSOC);
        demand($x && strtolower(str_replace(' ', '',(string)$x['COLUMN_TYPE']))==="enum('regular','direct','reimbursement','travel_reimbursement')" && $x['IS_NULLABLE']==='NO',
            'Travel DV type migration did not produce the approved enum',409);return;
    }
    $specs=[
      '20261108_travel_dv_approvals'=>['travel_dv_accounting_approvals',
        ['id','organization_id','claim_id','claim_version','review_version','version_no','employee_id','fund_id',
         'claimed_amount','preliminary_allowed','approved_amount','paper_reference','approved_by','created_at'],
        ['PRIMARY'=>['id'],'uq_tda_claim_version'=>['organization_id','claim_id','version_no'],
         'ix_tda_employee_fund'=>['organization_id','employee_id','fund_id','claim_id']],
        ['fk_tda_org','fk_tda_claim','fk_tda_employee','fk_tda_fund','fk_tda_user']],
      '20261108_travel_dv_links'=>['travel_dv_claim_links',
        ['id','organization_id','dv_id','claim_id','allocation_line_id','approval_id','employee_id','fund_id',
         'claim_version','review_version','claimed_amount','preliminary_allowed','allocated_amount','claim_date',
         'active_claim_id','status','created_by','created_at'],
        ['PRIMARY'=>['id'],'uq_tdl_org_active'=>['organization_id','active_claim_id'],
         'uq_tdl_dv_claim'=>['dv_id','claim_id'],'uq_tdl_dv_allocation'=>['dv_id','allocation_line_id'],
         'ix_tdl_org_claim'=>['organization_id','claim_id']],
        ['fk_tdl_org','fk_tdl_dv','fk_tdl_claim','fk_tdl_allocation','fk_tdl_approval','fk_tdl_user']],
    ];
    demand(isset($specs[$id]),'Unknown approved travel DV migration',409);
    [$table,$expectedColumns,$expectedIndexes,$expectedForeignKeys]=$specs[$id];
    $q=$db->prepare('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);$row=$q->fetch(PDO::FETCH_ASSOC);
    demand($row && $row['ENGINE']==='InnoDB' && $row['TABLE_COLLATION']==='utf8mb4_unicode_ci',
        'Travel DV schema table/engine/collation mismatch: '.$table,409);
    $q=$db->prepare('SELECT COLUMN_NAME,IS_NULLABLE,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    $q->execute([$table]);$columns=$q->fetchAll(PDO::FETCH_ASSOC);
    demand(array_column($columns,'COLUMN_NAME')===$expectedColumns,'Travel DV schema column inventory mismatch: '.$table,409);
    foreach($columns as $c){
        $name=$c['COLUMN_NAME'];$expectedNullable=($name==='active_claim_id')?'YES':'NO';
        demand($c['IS_NULLABLE']===$expectedNullable,'Travel DV column nullability mismatch: '.$table.'.'.$name,409);
        if($name==='id'||$name==='organization_id'||$name==='claim_id'||$name==='dv_id'||$name==='allocation_line_id'||$name==='approval_id'||$name==='employee_id'||$name==='fund_id'||$name==='active_claim_id'||$name==='created_by')
            demand(strtolower((string)$c['COLUMN_TYPE'])==='bigint(20) unsigned'||strtolower((string)$c['COLUMN_TYPE'])==='bigint unsigned',
                'Travel DV unsigned identifier mismatch: '.$table.'.'.$name,409);
    }
    $q=$db->prepare('SELECT INDEX_NAME,COLUMN_NAME,SEQ_IN_INDEX,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX');
    $q->execute([$table]);$indexes=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $i){$indexes[$i['INDEX_NAME']]['cols'][]=$i['COLUMN_NAME'];$indexes[$i['INDEX_NAME']]['non_unique']=(int)$i['NON_UNIQUE'];}
    foreach($expectedIndexes as $index=>$cols)
        demand(isset($indexes[$index])&&$indexes[$index]['cols']===$cols&&$indexes[$index]['non_unique']===($index==='PRIMARY'||str_starts_with($index,'uq_')?0:1),
            'Travel DV schema required index mismatch: '.$table.'.'.$index,409);
    $q=$db->prepare('SELECT DISTINCT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL');
    $q->execute([$table]);$fk=array_column($q->fetchAll(PDO::FETCH_ASSOC),'CONSTRAINT_NAME');sort($fk);sort($expectedForeignKeys);
    demand($fk===$expectedForeignKeys,'Travel DV foreign key inventory mismatch: '.$table,409);
}


/** Approved, additive tax-transfer ledger tables. No arbitrary DDL. */
function settingsTaxTransferMigrationStatements(): array {
    return [
        '20261112_tax_transfer_vouchers'=>"CREATE TABLE IF NOT EXISTS school_tax_transfers (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, organization_id BIGINT UNSIGNED NOT NULL, division_id BIGINT UNSIGNED NOT NULL, fund_id BIGINT UNSIGNED NOT NULL, dv_id BIGINT UNSIGNED NOT NULL, division_bank_account_id BIGINT UNSIGNED NOT NULL, status VARCHAR(32) NOT NULL DEFAULT 'DRAFT', created_by BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, received_by BIGINT UNSIGNED NULL, received_at DATE NULL, receipt_reference VARCHAR(255) NULL, reconciled_by BIGINT UNSIGNED NULL, reconciled_at TIMESTAMP NULL, jev_reference VARCHAR(255) NULL, UNIQUE KEY uq_stt_dv (dv_id), KEY ix_stt_division (division_id,status,id), UNIQUE KEY uq_stt_jev (division_id,jev_reference), KEY ix_stt_school (organization_id,status,id), CONSTRAINT fk_stt_org FOREIGN KEY (organization_id) REFERENCES organizations(id), CONSTRAINT fk_stt_div FOREIGN KEY (division_id) REFERENCES organizations(id), CONSTRAINT fk_stt_fund FOREIGN KEY (fund_id) REFERENCES funds(id), CONSTRAINT fk_stt_dv FOREIGN KEY (dv_id) REFERENCES disbursement_vouchers(id), CONSTRAINT fk_stt_div_bank FOREIGN KEY (division_bank_account_id) REFERENCES bank_accounts(id), CONSTRAINT fk_stt_creator FOREIGN KEY (created_by) REFERENCES users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        '20261112_tax_transfer_components'=>"CREATE TABLE IF NOT EXISTS school_tax_transfer_components (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, transfer_id BIGINT UNSIGNED NOT NULL, component_id BIGINT UNSIGNED NOT NULL, amount DECIMAL(18,2) NOT NULL, UNIQUE KEY uq_sttc_link (transfer_id,component_id), KEY ix_sttc_component (component_id), CONSTRAINT fk_sttc_transfer FOREIGN KEY (transfer_id) REFERENCES school_tax_transfers(id), CONSTRAINT fk_sttc_component FOREIGN KEY (component_id) REFERENCES withholding_components(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}
function settingsVerifyTaxTransferMigration(PDO $db,string $id): void {
    $tables=['20261112_tax_transfer_vouchers'=>'school_tax_transfers',
        '20261112_tax_transfer_components'=>'school_tax_transfer_components'];
    demand(isset($tables[$id]),'Unrecognized tax-transfer schema migration',409);
    $table=$tables[$id];
    $res=$db->prepare('SELECT COLUMN_NAME,DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    $res->execute([$table]);
    $columns=[];foreach($res->fetchAll(PDO::FETCH_ASSOC) as $row)$columns[$row['COLUMN_NAME']]=$row['DATA_TYPE'];
    $expected=$id==='20261112_tax_transfer_vouchers'
        ?['id'=>'bigint','organization_id'=>'bigint','division_id'=>'bigint','fund_id'=>'bigint','dv_id'=>'bigint',
          'division_bank_account_id'=>'bigint','status'=>'varchar','created_by'=>'bigint','created_at'=>'timestamp',
          'received_by'=>'bigint','received_at'=>'date','receipt_reference'=>'varchar','reconciled_by'=>'bigint',
          'reconciled_at'=>'timestamp','jev_reference'=>'varchar']
        :['id'=>'bigint','transfer_id'=>'bigint','component_id'=>'bigint','amount'=>'decimal'];
    demand($columns===$expected,'Tax-transfer table columns do not match approved schema: '.$table,409);
    $name=$id==='20261112_tax_transfer_vouchers'?'uq_stt_dv':'uq_sttc_link';
    $unique=$db->prepare('SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ",") FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? AND NON_UNIQUE=0');
    $unique->execute([$table,$name]);
    demand($unique->fetchColumn()===($name==='uq_stt_dv'?'dv_id':'transfer_id,component_id'),
        'Tax-transfer uniqueness constraint missing: '.$table,409);
    if($id==='20261112_tax_transfer_vouchers'){
        $unique->execute([$table,'uq_stt_jev']);
        demand($unique->fetchColumn()==='division_id,jev_reference',
            'Division JEV reference must have a unique per-Division constraint.',409);
    }
    $fk=$db->prepare('SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL');
    $fk->execute([$table]);
    demand((int)$fk->fetchColumn()===($id==='20261112_tax_transfer_vouchers'?6:2),
        'Approved tax-transfer foreign keys are missing. Do not use this schema.',409);
}

/** v0.2.401: one explicitly approved existing-column nullability relaxation.
 * No bank is fabricated: old bank IDs remain unchanged; new transfers may have NULL.
 * Because MariaDB DDL is not transactional, schema evidence and a full SQL backup
 * are mandatory before v0.2.402 is approved. */
function settingsTaxTransferOptionalBankDdl(): string {
    return 'ALTER TABLE school_tax_transfers DROP FOREIGN KEY fk_stt_div_bank, MODIFY COLUMN division_bank_account_id BIGINT UNSIGNED NULL DEFAULT NULL, ADD CONSTRAINT fk_stt_div_bank_v0402 FOREIGN KEY (division_bank_account_id) REFERENCES bank_accounts(id), ADD UNIQUE KEY uq_stt_division_receipt (division_id, receipt_reference)';
}
function settingsVerifyOptionalBankColumn(PDO $db,bool $after): void {
    $stmt=$db->prepare("SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='school_tax_transfers' AND COLUMN_NAME='division_bank_account_id'");
    $stmt->execute();$column=$stmt->fetch(PDO::FETCH_ASSOC);
    $columnType=strtolower(preg_replace('/\([0-9]+\)/','',(string)($column['COLUMN_TYPE']??'')));
    demand($column && $columnType==='bigint unsigned' &&
        ($column['IS_NULLABLE']===($after?'YES':'NO')) &&
        ($column['COLUMN_DEFAULT']===null || strtoupper((string)$column['COLUMN_DEFAULT'])==='NULL'),
        'Tax-transfer bank column schema mismatch. Stop and request technical review.',409);
    $foreignKeys=$db->query("SELECT CONSTRAINT_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='school_tax_transfers'
        AND COLUMN_NAME='division_bank_account_id' AND REFERENCED_TABLE_NAME='bank_accounts'")->fetchAll(PDO::FETCH_ASSOC);
    $requiredFk=$after?'fk_stt_div_bank_v0402':'fk_stt_div_bank';
    demand(count($foreignKeys)===1 &&
        ($foreignKeys[0]['CONSTRAINT_NAME']??'')===$requiredFk &&
        ($foreignKeys[0]['REFERENCED_COLUMN_NAME']??'')==='id',
        'Expected Division-bank foreign key name and reference must be preserved.',409);
    $indexes=$db->query("SELECT COLUMN_NAME,SEQ_IN_INDEX,NON_UNIQUE FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='school_tax_transfers'
        AND INDEX_NAME='uq_stt_division_receipt' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
    if($after){
        demand(count($indexes)===2 && $indexes[0]['COLUMN_NAME']==='division_id'
            && $indexes[1]['COLUMN_NAME']==='receipt_reference'
            && (int)$indexes[0]['NON_UNIQUE']===0 && (int)$indexes[1]['NON_UNIQUE']===0,
            'Division receipt reference unique index must be preserved.',409);
    }else{
        demand(count($indexes)===0,'Unexpected existing Division receipt index; review before migration.',409);
        $duplicates=$db->query("SELECT COUNT(*) FROM
            (SELECT division_id,receipt_reference FROM school_tax_transfers
            WHERE receipt_reference IS NOT NULL
            GROUP BY division_id,receipt_reference HAVING COUNT(*)>1) repeated")->fetchColumn();
        demand((int)$duplicates===0,'Duplicate historical Division receipt references require accounting review before migration.',409);
    }
}
/** Only curated, additive statements may run from update ZIPs. Never accept DROP, UPDATE or DELETE. */
function settingsMigrationPath(string $path): bool {
    return (bool)preg_match('#^migrations/[0-9]{8}_[a-z0-9_]{3,60}\.sql$#',$path);
}
/** Exact, restricted password-recovery DDL whitelist. No general CREATE TABLE privilege. */
function settingsPasswordRecoveryMigrationStatements(): array {
    return [
        '20261122_password_reset_requests' => <<<'IPDAMS_RECOVERY_REQUESTS_DDL'
CREATE TABLE IF NOT EXISTS password_reset_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  division_id BIGINT UNSIGNED NOT NULL,
  school_id BIGINT UNSIGNED NULL,
  status ENUM('PENDING','APPROVED','COMPLETED','REJECTED','EXPIRED') NOT NULL DEFAULT 'PENDING',
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at DATETIME NULL,
  approved_by BIGINT UNSIGNED NULL,
  temporary_credential_stamp CHAR(64) NULL,
  identity_verification_note VARCHAR(500) NULL,
  expires_at DATETIME NULL,
  completed_at DATETIME NULL,
  INDEX ix_prr_division_status (division_id,status,requested_at),
  INDEX ix_prr_user_status (user_id,status,id),
  CONSTRAINT fk_prr_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_prr_div FOREIGN KEY (division_id) REFERENCES organizations(id),
  CONSTRAINT fk_prr_school FOREIGN KEY (school_id) REFERENCES organizations(id),
  CONSTRAINT fk_prr_approver FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB
IPDAMS_RECOVERY_REQUESTS_DDL,
        '20261122_password_reset_attempts' => <<<'IPDAMS_RECOVERY_ATTEMPTS_DDL'
CREATE TABLE IF NOT EXISTS password_reset_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_hash CHAR(64) NOT NULL,
  identifier_hash CHAR(64) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_pra_ip_recent (ip_hash,attempted_at),
  INDEX ix_pra_identifier_recent (identifier_hash,attempted_at)
) ENGINE=InnoDB
IPDAMS_RECOVERY_ATTEMPTS_DDL,
    ];
}
/** Schema verification occurs after CREATE TABLE and also on replay; fail closed. */
function settingsVerifyPasswordRecoveryMigration(PDO $db,string $migrationKey): void {
    $tables=[
        '20261122_password_reset_requests'=>[
            'table'=>'password_reset_requests',
            'columns'=>[
                'id'=>['bigint','NO'],'user_id'=>['bigint','NO'],'division_id'=>['bigint','NO'],
                'school_id'=>['bigint','YES'],'status'=>['enum','NO'],'requested_at'=>['datetime','NO'],
                'decided_at'=>['datetime','YES'],'approved_by'=>['bigint','YES'],
                'temporary_credential_stamp'=>['char','YES'],
                'identity_verification_note'=>['varchar','YES'],'expires_at'=>['datetime','YES'],
                'completed_at'=>['datetime','YES'],
            ],
            'indexes'=>[
                'PRIMARY'=>'id',
                'ix_prr_division_status'=>'division_id,status,requested_at',
                'ix_prr_user_status'=>'user_id,status,id',
            ],
            'foreign_keys'=>[
                'fk_prr_user'=>['user_id','users','id'],
                'fk_prr_div'=>['division_id','organizations','id'],
                'fk_prr_school'=>['school_id','organizations','id'],
                'fk_prr_approver'=>['approved_by','users','id'],
            ],
        ],
        '20261122_password_reset_attempts'=>[
            'table'=>'password_reset_attempts',
            'columns'=>[
                'id'=>['bigint','NO'],'ip_hash'=>['char','NO'],
                'identifier_hash'=>['char','NO'],'attempted_at'=>['datetime','NO'],
            ],
            'indexes'=>[
                'PRIMARY'=>'id',
                'ix_pra_ip_recent'=>'ip_hash,attempted_at',
                'ix_pra_identifier_recent'=>'identifier_hash,attempted_at',
            ],
            'foreign_keys'=>[],
        ],
    ];
    demand(isset($tables[$migrationKey]),'Unrecognized password recovery migration',409);
    $spec=$tables[$migrationKey];$table=$spec['table'];
    $stmt=$db->prepare("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $stmt->execute([$table]);
    demand(strcasecmp((string)$stmt->fetchColumn(),'InnoDB')===0,'Password recovery table engine is missing or invalid: '.$table,409);
    $stmt=$db->prepare("SELECT COLUMN_NAME,DATA_TYPE,IS_NULLABLE,COLUMN_TYPE,EXTRA
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $stmt->execute([$table]);$columns=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)$columns[$row['COLUMN_NAME']]=$row;
    demand(count($columns)===count($spec['columns']),'Password recovery table has unexpected columns: '.$table,409);
    foreach($spec['columns'] as $name=>$check){
        $found=$columns[$name]??null;
        demand($found!==null && $found['DATA_TYPE']===$check[0] && $found['IS_NULLABLE']===$check[1],
            'Password recovery schema mismatch: '.$table.'.'.$name,409);
    }
    demand(str_contains(strtolower((string)$columns['id']['EXTRA']),'auto_increment'),
        'Password recovery ID is not auto-incrementing',409);
    if($table==='password_reset_requests') {
        demand($columns['status']['COLUMN_TYPE']==="enum('PENDING','APPROVED','COMPLETED','REJECTED','EXPIRED')",
            'Password recovery status values are not approved',409);
    }
    $stmt=$db->prepare("SELECT INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS index_columns
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? GROUP BY INDEX_NAME");
    $stmt->execute([$table]);$indexes=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)$indexes[$row['INDEX_NAME']]=$row['index_columns'];
    foreach($spec['indexes'] as $name=>$list)
        demand(($indexes[$name]??'')===$list,'Password recovery index missing or different: '.$table.'.'.$name,409);
    if($spec['foreign_keys']) {
        $stmt=$db->prepare("SELECT CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL");
        $stmt->execute([$table]);$foreignKeys=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)
            $foreignKeys[$row['CONSTRAINT_NAME']]=[$row['COLUMN_NAME'],$row['REFERENCED_TABLE_NAME'],$row['REFERENCED_COLUMN_NAME']];
        foreach($spec['foreign_keys'] as $name=>$parts)
            demand(($foreignKeys[$name]??null)===$parts,'Password recovery foreign key is missing: '.$name,409);
    }
}
function settingsMigrationSql(string $content): string {
    demand(strlen($content)>0 && strlen($content)<=SETTINGS_MAX_MIGRATION_SQL,'Migration SQL file is empty or too large');
    demand(!str_contains($content,"\0") && !str_contains($content,'/*') && !str_contains($content,'*/'),'Unsupported migration comments or binary data');
    $lines=[];
    foreach(preg_split('/\r?\n/',$content) as $line){
        $trim=ltrim($line);
        if($trim==='' || str_starts_with($trim,'-- '))continue;
        demand(!str_contains($line,'--') && !str_contains($line,'#'),'Inline migration comments are not supported');
        $lines[]=$line;
    }
    $statement=trim(implode("\n",$lines));
    demand(substr_count($statement,';')<=1,'Each migration must contain exactly one SQL statement');
    if(str_ends_with($statement,';'))$statement=rtrim(substr($statement,0,-1));
    demand(!str_contains($statement,';'),'Multiple SQL statements are forbidden');
    $addColumn=(bool)preg_match('/^ALTER\s+TABLE\s+`?[a-z_][a-z0-9_]*`?\s+ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+`?[a-z_][a-z0-9_]*`?\s+(?:VARCHAR\([1-9][0-9]{0,3}\)|(?:BIGINT|INT|TINYINT)(?:\s+UNSIGNED)?|TEXT)\s+NULL(?:\s+DEFAULT\s+NULL)?(?:\s+AFTER\s+`?[a-z_][a-z0-9_]*`?)?$/i',$statement);
    $insertUacs=(bool)preg_match('/^INSERT\s+IGNORE\s+INTO\s+`?uacs_accounts`?\s*\(\s*`?code`?\s*,\s*`?account_name`?\s*,\s*`?active`?\s*\)\s+VALUES\s*\(.+\)$/is',$statement);
    // Strictly approved accounted physical check-leaf table. No general CREATE TABLE permission.
    // Single exact additive DDL and no arbitrary table names/columns/secondary statements.
    $approvedCheckStockSql='CREATE TABLE IF NOT EXISTS checkbook_leaves (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, organization_id BIGINT UNSIGNED NOT NULL, bank_account_id BIGINT UNSIGNED NOT NULL, book_reference VARCHAR(100) NOT NULL, serial VARCHAR(40) NOT NULL, status VARCHAR(16) NOT NULL DEFAULT \'AVAILABLE\', created_by BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_check_leaf_bank_serial (bank_account_id, serial), KEY ix_check_leaf_status (organization_id, bank_account_id, status, id), FOREIGN KEY (organization_id) REFERENCES organizations(id), FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id), FOREIGN KEY (created_by) REFERENCES users(id)) ENGINE=InnoDB';
    $createCheckStock=hash_equals($approvedCheckStockSql,preg_replace('/\s+/',' ',trim($statement)));
    $approvedPropertyLink=in_array(preg_replace('/\s+/',' ',trim($statement)),settingsPropertyLinkMigrationStatements(),true);
    $approvedTravelTimeline=in_array(preg_replace('/\s+/',' ',trim($statement)),settingsTravelTimelineMigrationStatements(),true);
    $approvedTravelDv=in_array(preg_replace('/\s+/',' ',trim($statement)),settingsTravelDvMigrationStatements(),true);
    $approvedTaxTransfer=in_array(preg_replace('/\s+/',' ',trim($statement)),settingsTaxTransferMigrationStatements(),true);
    $approvedNullableBank=hash_equals(settingsTaxTransferOptionalBankDdl(),preg_replace('/\s+/',' ',trim($statement)));
    $approvedPasswordRecovery=in_array(preg_replace('/\s+/',' ',trim($statement)),
        array_map(static fn(string $ddl)=>preg_replace('/\s+/',' ',trim($ddl)),settingsPasswordRecoveryMigrationStatements()),true);
    demand($addColumn||$insertUacs||$createCheckStock||$approvedPropertyLink||$approvedTravelTimeline||$approvedTravelDv||$approvedTaxTransfer||$approvedNullableBank||$approvedPasswordRecovery,
        'Only explicitly allowlisted migrations are supported');
    return $statement;
}
function settingsMigrationVerifyResult(PDO $db,string $sql): void {
    $normalized=preg_replace('/\s+/',' ',trim($sql));
    if(hash_equals(settingsTaxTransferOptionalBankDdl(),$normalized)){
        settingsVerifyOptionalBankColumn($db,true);return;
    }
    foreach(settingsPropertyLinkMigrationStatements() as $migrationKey=>$approved){
        if(hash_equals($approved,$normalized)){
            settingsVerifyPropertyLinkMigration($db,$migrationKey);return;
        }
    }
    foreach(settingsTravelTimelineMigrationStatements() as $migrationKey=>$approved){
        if(hash_equals($approved,$normalized)){
            settingsVerifyTravelTimelineMigration($db,$migrationKey);return;
        }
    }
    foreach(settingsTravelDvMigrationStatements() as $migrationKey=>$approved){
        if(hash_equals($approved,$normalized)){
            settingsVerifyTravelDvMigration($db,$migrationKey);return;
        }
    }
    foreach(settingsTaxTransferMigrationStatements() as $id=>$approved){
        if(hash_equals($approved,$normalized)){settingsVerifyTaxTransferMigration($db,$id);return;}
    }
    foreach(settingsPasswordRecoveryMigrationStatements() as $id=>$ddl){
        if(hash_equals(preg_replace('/\s+/',' ',trim($ddl)),$normalized)){
            settingsVerifyPasswordRecoveryMigration($db,$id);return;
        }
    }
    if(str_starts_with($sql,'CREATE TABLE IF NOT EXISTS checkbook_leaves ')){
        $c=$db->query("SELECT COLUMN_NAME,DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='checkbook_leaves'")->fetchAll(PDO::FETCH_ASSOC);
        $found=[];foreach($c as $row)$found[$row['COLUMN_NAME']]=$row['DATA_TYPE'];
        foreach(['id'=>'bigint','organization_id'=>'bigint','bank_account_id'=>'bigint',
            'book_reference'=>'varchar','serial'=>'varchar','status'=>'varchar','created_by'=>'bigint','created_at'=>'timestamp'] as $col=>$kind)
            demand(($found[$col]??null)===$kind,'Check-leaf inventory schema mismatch: '.$col);
        $unique=$db->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='checkbook_leaves' AND INDEX_NAME='uq_check_leaf_bank_serial'")->fetchColumn();
        demand($unique==='bank_account_id,serial','Check-leaf bank+serial unique key is missing');
        return;
    }
    if(!preg_match('/^ALTER\s+TABLE\s+`?([a-z_][a-z0-9_]*)`?\s+ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+`?([a-z_][a-z0-9_]*)`?/i',$sql,$matches))return;
    $check=$db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $check->execute([$matches[1],$matches[2]]);
    demand((int)$check->fetchColumn()===1,'Expected database column was not found after migration: '.$matches[1].'.'.$matches[2]);
}
function settingsMigrationDbLedgerExists(PDO $db): bool {
    return (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ipdams_schema_migrations'")->fetchColumn()===1;
}
function settingsMigrationApplied(PDO $db,string $id,string $sha): bool {
    if(!settingsMigrationDbLedgerExists($db))return false;
    $stmt=$db->prepare('SELECT checksum FROM ipdams_schema_migrations WHERE migration_id=?');
    $stmt->execute([$id]);$prior=$stmt->fetchColumn();
    if($prior===false)return false;
    demand(hash_equals((string)$prior,$sha),'A database migration ID was previously applied with different content: '.$id,409);
    return true;
}
function settingsApplyMigrations(PDO $db,ZipArchive $zip,array $manifest,int $uid): array {
    $migrations=$manifest['migrations']??[];
    if(!$migrations)return [];
    // Created only during apply, after the full database backup succeeds.
    $db->exec("CREATE TABLE IF NOT EXISTS ipdams_schema_migrations (migration_id VARCHAR(100) NOT NULL PRIMARY KEY, checksum CHAR(64) NOT NULL, release_version VARCHAR(20) NOT NULL, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results=[];
    foreach($migrations as $id=>$entry){
        $raw=$zip->getFromName($entry['path']);
        demand(is_string($raw) && hash_equals((string)$entry['sha256'],hash('sha256',$raw)),'Migration contents changed: '.$id);
        $statement=settingsMigrationSql($raw);
        if(settingsMigrationApplied($db,$id,(string)$entry['sha256'])){
            settingsMigrationVerifyResult($db,$statement);
            $results[$id]='previously_applied';continue;
        }
        // These additive statements are designed to be safely retried after a process interruption.
        if(str_starts_with(strtoupper(ltrim($statement)),'INSERT IGNORE')){
            $db->beginTransaction();
            try{
                $db->exec($statement);
                $insert=$db->prepare('INSERT INTO ipdams_schema_migrations (migration_id,checksum,release_version) VALUES (?,?,?)');
                $insert->execute([$id,$entry['sha256'],$manifest['version']]);
                $db->commit();
            }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        }else{
            // DDL auto-commits in MariaDB. Never claim that file rollback reverses it.
            if(hash_equals(settingsTaxTransferOptionalBankDdl(),preg_replace('/\s+/',' ',trim($statement))))
                settingsVerifyOptionalBankColumn($db,false);
            $db->exec($statement);
            $insert=$db->prepare('INSERT INTO ipdams_schema_migrations (migration_id,checksum,release_version) VALUES (?,?,?)');
            $insert->execute([$id,$entry['sha256'],$manifest['version']]);
        }
        settingsMigrationVerifyResult($db,$statement);
        $results[$id]='applied';
        settingsAudit($uid,'release.database_migration',['id'=>$id,'release'=>$manifest['version'],'sha256'=>$entry['sha256']]);
    }
    return $results;
}
function settingsMigrationBackup(PDO $db,string $id): array {
    demand(function_exists('resetNames')&&function_exists('resetReadOnlyBackup'),'Protected database backup is unavailable; migration refused');
    demand(!$db->inTransaction(),'Cannot start a migration backup during another database transaction');
    $path=settingsPrivate().'/db-backup-'.$id.'.zip';
    demand(!file_exists($path),'A database backup already exists for this release');
    // Snapshot app tables consistently. Schedule schema upgrades when other users are idle;
    // attachment file writes cannot be held in the MySQL transaction.
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->beginTransaction();
    try{
        $names=resetNames($db);$counts=[];
        foreach($names as $table)$counts[$table]=(int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
        $result=resetReadOnlyBackup($db,['schema_tables'=>$names,'mode'=>'release_migration','counts'=>$counts],$path);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();@unlink($path);throw $e;}
    return $result+['path'=>$path];
}
function settingsDownloadMigrationBackup(string $id,int $uid): never {
    $meta=settingsMeta($id);
    demand(($meta['status']??'')==='applied' && !empty($meta['migration_backup']['sha256']),'No verified migration backup for this release',404);
    $path=settingsPrivate().'/db-backup-'.$id.'.zip';
    demand(is_file($path)&&!is_link($path),'Backup archive missing',404);
    demand(hash_equals((string)$meta['migration_backup']['sha256'],(string)hash_file('sha256',$path)),'Backup archive checksum mismatch',409);
    settingsAudit($uid,'release.database_backup.download',['release_id'=>$id]);
    header('Content-Type: application/zip');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="IPDAMS_PreMigration_Backup_'.$id.'.zip"');
    header('Content-Length: '.(string)filesize($path));readfile($path);exit;
}

function settingsValidateManifest(array $manifest,array $files,array $migrationHashes): array {
    demand(($manifest['format']??null)===1,'release.json format must be 1');
    foreach(['version','from_version','title'] as $key) demand(isset($manifest[$key]) && is_string($manifest[$key]),'Invalid release manifest '.$key);
    $semver='/^\d{1,4}\.\d{1,4}\.\d{1,4}$/';
    demand((bool)preg_match($semver,$manifest['version']) && (bool)preg_match($semver,$manifest['from_version']),'Use numeric three-part release versions, e.g. 0.1.10');
    demand(version_compare($manifest['version'],$manifest['from_version'],'>'),'Release version must be newer than its base');
    demand(strlen($manifest['title'])>=3&&strlen($manifest['title'])<=120,'Release title is required (3–120 characters)');
    $manifest['notes']=$manifest['notes']??'';
    demand(is_string($manifest['notes'])&&strlen($manifest['notes'])<=2000,'Invalid release notes');
    $manifest['min_php']=$manifest['min_php']??'8.2';
    demand(is_string($manifest['min_php']) && (bool)preg_match('/^\d+\.\d+$/',$manifest['min_php']),'Invalid minimum PHP version');
    $manifest['extensions']=$manifest['extensions']??[];
    demand(is_array($manifest['extensions'])&&count($manifest['extensions'])<=15,'Invalid required extensions');
    foreach($manifest['extensions'] as $ext) demand(is_string($ext)&&(bool)preg_match('/^[a-z0-9_]+$/i',$ext),'Invalid extension name');
    demand(isset($manifest['files'])&&is_array($manifest['files'])&&!array_is_list($manifest['files']),'release.json needs a file-to-SHA256 map');
    demand(count($manifest['files'])===count($files),'Manifest does not cover the same number of files');
    foreach($manifest['files'] as $name=>$checksum){
        demand(is_string($name)&&settingsAllowed($name),'Manifest has disallowed file: '.$name);
        demand(is_string($checksum)&&(bool)preg_match('/^[a-f0-9]{64}$/',$checksum),'Invalid file checksum: '.$name);
        demand(isset($files[$name])&&hash_equals($checksum,$files[$name]),'Manifest checksum mismatch: '.$name);
    }
    $migrations=$manifest['migrations']??[];
    demand(is_array($migrations)&&count($migrations)<=SETTINGS_MAX_MIGRATIONS,'Invalid migration inventory');
    demand(count($migrations)===count($migrationHashes),'Migration inventory does not match ZIP');
    foreach($migrations as $id=>$entry){
        demand(is_string($id)&&(bool)preg_match('/^[0-9]{8}_[a-z0-9_]{3,60}$/',$id),'Invalid migration identifier');
        demand(is_array($entry)&&isset($entry['path'],$entry['sha256'])&&count($entry)===2,'Invalid migration manifest entry');
        $path=$entry['path'];$checksum=$entry['sha256'];
        demand(is_string($path)&&settingsMigrationPath($path),'Unapproved migration file path');
        demand($path==='migrations/'.$id.'.sql','Migration name and filename mismatch');
        demand(is_string($checksum)&&(bool)preg_match('/^[a-f0-9]{64}$/',$checksum),'Invalid migration checksum');
        demand(isset($migrationHashes[$path])&&hash_equals($checksum,$migrationHashes[$path]),'Migration checksum mismatch: '.$id);
    }
    $manifest['migrations']=$migrations;
    return $manifest;
}
function settingsReadPackage(ZipArchive $zip): array {
    demand($zip->numFiles>=2&&$zip->numFiles<=SETTINGS_MAX_FILES,'Invalid number of ZIP entries');
    $files=[];$migrations=[];$total=0;$seen=[];$manifest=null;
    for($i=0;$i<$zip->numFiles;$i++){
        $entry=$zip->statIndex($i);$path=$entry['name'];
        if(str_ends_with($path,'/')){
            demand(!str_contains($path,'..')&&!str_contains($path,'\\')&&!str_starts_with($path,'/'),'Unsafe directory in archive');
            continue;
        }
        demand($path===SETTINGS_MANIFEST||settingsAllowed($path)||settingsMigrationPath($path),'Disallowed update path: '.$path);
        demand(!isset($seen[$path]),'Duplicate file in archive');$seen[$path]=true;
        $total+=(int)($entry['size']??0);demand($total<=SETTINGS_MAX_EXPANDED,'Expanded package too large');
        $external=$zip->getExternalAttributesIndex($i,$ops,$attrs);
        if($external){$kind=($attrs>>16)&0170000;demand($kind!==0120000,'Symlink entries are forbidden');}
        $data=$zip->getFromIndex($i);demand(is_string($data),'Cannot inspect archive entry');
        demand(strlen($data)===(int)$entry['size'],'Invalid archive entry length');
        if($path===SETTINGS_MANIFEST){
            demand(strlen($data)<=20_000,'Release manifest too large');
            $manifest=json_decode($data,true,32,JSON_THROW_ON_ERROR);
        } elseif(settingsMigrationPath($path)) {settingsMigrationSql($data);$migrations[$path]=hash('sha256',$data);} else $files[$path]=hash('sha256',$data);
    }
    demand(is_array($manifest),'release.json is required for versioned updates');
    demand(count($files)>0,'Update has no application files');
    return ['manifest'=>settingsValidateManifest($manifest,$files,$migrations),'files'=>$files,'migrations'=>$migrations];
}
function settingsStage(array $upload,int $uid): array {
    demand(class_exists(ZipArchive::class),'PHP ZIP extension is required',503);
    demand(($upload['error']??99)===UPLOAD_ERR_OK,'Upload failed');
    demand(is_uploaded_file((string)($upload['tmp_name']??'')),'Invalid uploaded file');
    demand((int)($upload['size']??0)>0&&(int)$upload['size']<=SETTINGS_MAX_ZIP,'ZIP must be smaller than 20 MB');
    $zip=new ZipArchive();demand($zip->open($upload['tmp_name'])===true,'Cannot open update archive');
    try{$package=settingsReadPackage($zip);}finally{$zip->close();}
    $changes=[];
    foreach($package['files'] as $path=>$sha){
        $target=settingsTarget($path);$prior=is_file($target)?hash_file('sha256',$target):null;
        $changes[$path]=['type'=>$prior===null?'new':($prior===$sha?'unchanged':'modified'),'before_sha256'=>$prior,'after_sha256'=>$sha];
    }
    $id=date('YmdHis').'-'.bin2hex(random_bytes(6));
    $base=settingsPrivate().'/staged-'.$id;
    demand(move_uploaded_file($upload['tmp_name'],$base.'.zip'),'Cannot store uploaded ZIP');
    @chmod($base.'.zip',0600);
    $meta=['id'=>$id,'status'=>'staged','created'=>date('c'),'user_id'=>$uid,'sha256'=>hash_file('sha256',$base.'.zip'),'files'=>$package['files'],'migrations'=>$package['migrations'],'manifest'=>$package['manifest'],'changes'=>$changes];
    settingsWriteJson($base.'.json',$meta);
    settingsAudit($uid,'release.staged',['id'=>$id,'version'=>$package['manifest']['version']]);
    return $meta;
}
function settingsMeta(string $id): array {
    demand((bool)preg_match('/^\d{14}-[a-f0-9]{12}$/',$id),'Invalid release identifier');
    $path=settingsPrivate().'/staged-'.$id.'.json';demand(is_file($path),'Release not found',404);
    $data=json_decode((string)file_get_contents($path),true);
    demand(is_array($data),'Invalid release metadata');return $data;
}
function settingsLock() {
    $fh=fopen(settingsPrivate().'/update.lock','c');
    demand($fh!==false && flock($fh,LOCK_EX|LOCK_NB),'Another update or rollback is running',409);
    return $fh;
}
function settingsSafePath(string $path): void {
    demand(settingsAllowed($path),'Unapproved release path');
    $root=str_starts_with($path,'public/')?settingsPublicRoot():settingsRoot();
    demand(is_dir($root) && !is_link($root),'Invalid application root');
    $target=settingsTarget($path);
    $relative=str_starts_with($path,'public/')?substr($path,7):$path;
    $cursor=$root;
    foreach(explode('/',$relative) as $part){
        $cursor.='/'.$part;
        demand(!is_link($cursor),'Symbolic link in update path');
        if(file_exists($cursor)) demand(is_file($cursor)||is_dir($cursor),'Invalid target type');
    }
    demand(!is_dir($target),'Target is a directory');
}
function settingsPreflight(string $id): array {
    $issues=[];$details=[];
    try{
        $meta=settingsMeta($id);$manifest=$meta['manifest']??[];
        if(($meta['status']??'')!=='staged')$issues[]='The release is no longer staged.';
        $state=settingsState();
        if(($manifest['from_version']??'')!==$state['version'])$issues[]='Version mismatch: package needs '.$manifest['from_version'].'; installed '.$state['version'].'.';
        if(!isset($manifest['version'])||!version_compare($manifest['version'],$state['version'],'>'))$issues[]='Version is not newer than installed version.';
        if(!isset($manifest['min_php'])||version_compare(PHP_VERSION,$manifest['min_php'],'<'))$issues[]='This release needs PHP '.$manifest['min_php'].' or newer.';
        foreach(($manifest['extensions']??[]) as $ext) if(!extension_loaded($ext))$issues[]='Missing PHP extension: '.$ext;
        $zipfile=settingsPrivate().'/staged-'.$id.'.zip';
        if(!is_file($zipfile)||!hash_equals((string)$meta['sha256'],(string)hash_file('sha256',$zipfile)))$issues[]='Staged package changed or is missing.';
        elseif(!class_exists(ZipArchive::class))$issues[]='PHP ZIP extension unavailable.';
        else{
            $zip=new ZipArchive();
            if($zip->open($zipfile)!==true)$issues[]='Cannot reopen the ZIP.';
            else {
                try {
                    $content=settingsReadPackage($zip);
                    if($content['files']!==($meta['files']??[]))$issues[]='Staged file list changed.';
                    if($content['migrations']!==($meta['migrations']??[]))$issues[]='Staged migration list changed.';
                    if($content['manifest']!==$manifest)$issues[]='Release manifest changed.';
                }catch(Throwable $e){$issues[]='Archive validation: '.$e->getMessage();}
                finally{$zip->close();}
            }
        }
        foreach(($meta['changes']??[]) as $path=>$change){
            try{settingsSafePath($path);}catch(Throwable $e){$issues[]='Unsafe path '.$path.': '.$e->getMessage();continue;}
            $target=settingsTarget($path);
            $current=is_file($target)?hash_file('sha256',$target):null;
            if($current!==($change['before_sha256']??null))$issues[]='File changed since upload: '.$path.' (upload again after reviewing).';
            $parent=dirname($target);while(!file_exists($parent) && dirname($parent)!==$parent)$parent=dirname($parent);
            if(!is_dir($parent)||!is_writable($parent))$issues[]='Target folder not writable: '.$path;
            if(is_file($target)&&!is_readable($target))$issues[]='Cannot back up file: '.$path;
        }
        $migrations=$manifest['migrations']??[];
        if($migrations){
            $journal=settingsPrivate().'/migration-review-required.json';
            if(is_file($journal))$issues[]='A previous database migration requires technical review before another migration can be installed.';
            if(!function_exists('resetReadOnlyBackup')||!function_exists('resetNames'))$issues[]='Protected database backup service unavailable.';
            else{
                try{
                    $db=database();
                    foreach($migrations as $migrationId=>$entry){
                        settingsMigrationApplied($db,$migrationId,(string)$entry['sha256']);
                    }
                    $details['migrations']=count($migrations);
                }catch(Throwable $e){$issues[]='Database migration preflight failed: '.$e->getMessage();}
            }
        }
        if(!is_writable(settingsPrivate()))$issues[]='Private update storage not writable.';
        $bytes=(int)(@disk_free_space(settingsPrivate())?:0);
        if($bytes>0 && $bytes<SETTINGS_MAX_EXPANDED*2)$issues[]='Low free disk space for update and rollback files.';
    }catch(Throwable $ex){$issues[]='Preflight failed: '.$ex->getMessage();}
    return ['ok'=>count($issues)===0,'issues'=>array_values(array_unique($issues)),'details'=>$details];
}
function settingsRestoreFiles(array $meta,string $backup): void {
    foreach(array_reverse(array_keys($meta['files'])) as $path){
        $destination=settingsTarget($path);$old=$backup.'/'.$path;
        if(($meta['changes'][$path]['before_sha256']??null)!==null){
            if(!is_file($old)||!copy($old,$destination))throw new RuntimeException('Restore failed for '.$path);
        }else{if(is_file($destination)&&!unlink($destination))throw new RuntimeException('Cannot remove new file '.$path);}
    }
}
function settingsApply(string $id,int $uid,string $unusedMode='apply'): array {
    $lock=settingsLock();
    try{
        $check=settingsPreflight($id);demand($check['ok'],implode(' | ',$check['issues']));
        $meta=settingsMeta($id);$zipfile=settingsPrivate().'/staged-'.$id.'.zip';
        $zip=new ZipArchive();demand($zip->open($zipfile)===true,'Archive cannot be reopened');
        $backup=settingsPrivate().'/backup-'.$id;
        demand(!file_exists($backup)&&mkdir($backup,0700),'Cannot create backup folder');
        $previous=settingsState();$pending=[];$written=[];
        try{
            // Read and verify every file, make ALL backups and write temporary files BEFORE replacing any destination.
            foreach($meta['files'] as $path=>$expected){
                settingsSafePath($path);
                $data=$zip->getFromName($path);
                demand(is_string($data)&&hash_equals($expected,hash('sha256',$data)),'Package contents changed');
                $target=settingsTarget($path);$parent=dirname($target);
                if(!is_dir($parent))demand(mkdir($parent,0755,true),'Cannot create destination directory');
                if(($meta['changes'][$path]['before_sha256']??null)!==null){
                    $stored=$backup.'/'.$path;$folder=dirname($stored);
                    if(!is_dir($folder))demand(mkdir($folder,0700,true),'Cannot create backup subdirectory');
                    demand(copy($target,$stored) && hash_file('sha256',$stored)===$meta['changes'][$path]['before_sha256'],'Backup verification failed: '.$path);
                }
                $tmp=$target.'.ipdams-tmp-'.bin2hex(random_bytes(6));
                demand(file_put_contents($tmp,$data,LOCK_EX)!==false && hash_file('sha256',$tmp)===$expected,'Cannot write update file');
                $pending[$path]=$tmp;
            }
            $migrationEntries=$meta['manifest']['migrations']??[];
            if($migrationEntries){
                $db=database();
                $backupInfo=settingsMigrationBackup($db,$id);
                $meta['migration_backup']=['sha256'=>$backupInfo['sha256'],'size'=>$backupInfo['size']];
                // Fail closed if an earlier unfinished migration requires review.
                $review=settingsPrivate().'/migration-review-required.json';
                demand(!is_file($review),'Earlier database migration requires technical review');
                settingsWriteJson($review,['release_id'=>$id,'version'=>$meta['manifest']['version'],'started_at'=>date('c'),'backup_sha256'=>$backupInfo['sha256']]);
                try{
                    $meta['migration_results']=settingsApplyMigrations($db,$zip,$meta['manifest'],$uid);
                }catch(Throwable $failure){
                    settingsAudit($uid,'release.database_migration.failed',['id'=>$id,'error'=>substr($failure->getMessage(),0,120)]);
                    throw new RuntimeException('Database migration failed. The prior application files remain active. An isolated restore/technical review is required before retrying.');
                }
            }
            foreach($pending as $path=>$tmp){
                demand(rename($tmp,settingsTarget($path)),'Cannot activate updated file: '.$path);
                $written[]=$path;unset($pending[$path]);
            }
            $meta['status']='applied';$meta['installed_by']=$uid;$meta['applied_at']=date('c');
            $meta['previous_state']=$previous;
            settingsWriteJson(settingsPrivate().'/installed-version.json',['version'=>$meta['manifest']['version'],'release_id'=>$id,'installed_at'=>$meta['applied_at'],'source'=>'release manifest']);
            settingsWriteJson(settingsPrivate().'/staged-'.$id.'.json',$meta);
            if(!empty($meta['migration_results']))@unlink(settingsPrivate().'/migration-review-required.json');
            settingsAudit($uid,'release.applied',['id'=>$id,'from'=>$previous['version'],'to'=>$meta['manifest']['version'],'files'=>count($written)]);
            return $meta;
        }catch(Throwable $error){
            foreach($pending as $tmp)@unlink($tmp);
            if($written){
                try{foreach(array_reverse($written) as $path){
                    $destination=settingsTarget($path);$old=$backup.'/'.$path;
                    if(($meta['changes'][$path]['before_sha256']??null)!==null)demand(is_file($old)&&copy($old,$destination),'Recovery failed: '.$path);
                    elseif(is_file($destination))demand(unlink($destination),'Recovery unlink failed: '.$path);
                }}catch(Throwable $recovery){error_log('CRITICAL IPDAMS release recovery: '.$recovery->getMessage());}
            }
            // Previous version is authoritative if a write was interrupted after state change.
            try{settingsWriteJson(settingsPrivate().'/installed-version.json',$previous);}catch(Throwable $ignored){}
            settingsAudit($uid,'release.failed',['id'=>$id,'error'=>substr($error->getMessage(),0,160)]);
            throw $error;
        }finally{$zip->close();}
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
/**
 * Read-only recovery eligibility check. This is also called under the
 * update lock by settingsRollback(), so hidden forms or forged requests
 * cannot bypass newest-first rollback ordering.
 */
function settingsRollbackPreflight(string $id): array {
    $issues=[];
    try {
        $meta=settingsMeta($id);
        $state=settingsState();
        if(($meta['status']??'')!=='applied')
            $issues[]='Only a successfully applied release can be rolled back.';
        if(($state['release_id']??null)!==$id)
            $issues[]='This is not the active release. Roll back the newest installed update first.';
        if(($meta['manifest']['version']??null)!==($state['version']??null))
            $issues[]='Release version does not match the currently installed version.';
        $backup=settingsPrivate().'/backup-'.$id;
        if(!is_dir($backup)||is_link($backup))$issues[]='The recovery backup folder is missing or invalid.';
        if(is_dir($backup.'/rollback-recovery'))$issues[]='A prior recovery attempt requires technical review before retrying.';
        $files=$meta['files']??[];
        if(!is_array($files)||!$files)$issues[]='The release has no verifiable file inventory.';
        else foreach($files as $path=>$sha) {
            if(!is_string($path)||!is_string($sha)){$issues[]='Invalid release file metadata.';continue;}
            try {settingsSafePath($path);}catch(Throwable $ex){$issues[]='Unsafe installed path: '.$path;continue;}
            $target=settingsTarget($path);
            if(!is_file($target)||!is_readable($target)||!hash_equals($sha,(string)hash_file('sha256',$target)))
                $issues[]='Installed file missing or modified: '.$path;
            if(!is_writable(dirname($target)))$issues[]='Cannot restore files in: '.$path;
            $prior=$meta['changes'][$path]['before_sha256']??null;
            if($prior!==null) {
                $saved=$backup.'/'.$path;
                if(!is_string($prior)||!is_file($saved)||!is_readable($saved)||!hash_equals($prior,(string)hash_file('sha256',$saved)))
                    $issues[]='Recovery backup missing or modified: '.$path;
            }
        }
        // A release that executed migrations cannot be rolled back by restoring files alone.
        // Check this release's manifest, not whether the updater itself was modified.
        if(!empty($meta['manifest']['migrations'])){
            $issues[]='This release contains database migrations. File-only rollback cannot reverse the schema; use technical review and a verified pre-migration database and site backup.';
        }
        if(!is_writable(settingsPrivate()))$issues[]='Protected update storage is not writable.';
    }catch(Throwable $ex){$issues[]='Could not verify rollback readiness: '.$ex->getMessage();}
    return ['ok'=>!$issues,'issues'=>array_values(array_unique($issues))];
}

function settingsRollback(string $id,int $uid): void {
    $lock=settingsLock();
    try{
        $readiness=settingsRollbackPreflight($id);
        demand($readiness['ok'],implode(' | ',$readiness['issues']),409);
        $meta=settingsMeta($id);
        $state=settingsState();
        demand(($meta['status']??'')==='applied'&&($state['release_id']??null)===$id,'Roll back the newest installed release first',409);
        $backup=settingsPrivate().'/backup-'.$id;
        foreach($meta['files'] as $path=>$sha){
            settingsSafePath($path);
            demand(is_file(settingsTarget($path))&&hash_file('sha256',settingsTarget($path))===$sha,'Installed file changed after update: '.$path);
            $old=$backup.'/'.$path;
            if(($meta['changes'][$path]['before_sha256']??null)!==null)
                demand(is_file($old) && hash_file('sha256',$old)===$meta['changes'][$path]['before_sha256'],'Rollback backup missing/corrupted: '.$path);
        }
        // Keep recovery copies of current files until rollback completes.
        $recovery=$backup.'/rollback-recovery';
        demand(!is_dir($recovery)&&mkdir($recovery,0700),'Cannot prepare rollback safety copy');
        foreach($meta['files'] as $path=>$sha){
            $dst=$recovery.'/'.$path;$parent=dirname($dst);
            if(!is_dir($parent))demand(mkdir($parent,0700,true),'Cannot make rollback recovery folder');
            demand(copy(settingsTarget($path),$dst),'Cannot safeguard current release');
        }
        try{
            settingsRestoreFiles($meta,$backup);
            $prior=$meta['previous_state']??['version'=>$meta['manifest']['from_version'],'release_id'=>null,'installed_at'=>null,'source'=>'rollback'];
            settingsWriteJson(settingsPrivate().'/installed-version.json',$prior);
            $meta['status']='rolled_back';$meta['rolled_back_at']=date('c');
            settingsWriteJson(settingsPrivate().'/staged-'.$id.'.json',$meta);
            settingsAudit($uid,'release.rolled_back',['id'=>$id,'restored_version'=>$prior['version']]);
        }catch(Throwable $e){
            foreach($meta['files'] as $path=>$sha){$dst=$recovery.'/'.$path;if(is_file($dst))@copy($dst,settingsTarget($path));}
            throw $e;
        }
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
/** Discards only unused upload artifacts; never remove installed releases or recovery backups. */
function settingsDiscardStaged(string $id,int $uid): void {
    $lock=settingsLock();
    try {
        $meta=settingsMeta($id);
        demand(($meta['status']??'')==='staged','Only unused staged packages can be removed',409);
        $archive=settingsPrivate().'/staged-'.$id.'.zip';
        $metadata=settingsPrivate().'/staged-'.$id.'.json';
        demand(!is_link($archive)&&!is_link($metadata),'Invalid stored package');
        if(is_file($archive))demand(unlink($archive),'Unable to delete unused ZIP');
        // Keep a metadata tombstone so History records the user's action.
        $meta['status']='discarded';$meta['discarded_at']=date('c');$meta['discarded_by']=$uid;
        settingsWriteJson($metadata,$meta);
        settingsAudit($uid,'release.staged_discarded',['id'=>$id]);
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function settingsReleaseHistory(): array {
    $releases=[];
    foreach(glob(settingsPrivate().'/staged-*.json')?:[] as $file){
        $row=json_decode((string)file_get_contents($file),true);
        if(is_array($row))$releases[]=$row;
    }
    usort($releases,fn($a,$b)=>strcmp((string)($b['created']??''),(string)($a['created']??'')) ?: strcmp((string)($b['id']??''),(string)($a['id']??'')));
    return array_slice($releases,0,40);
}
// The SQL console behavior remains unchanged; write access is separately gated by environment configuration.
function settingsQuery(PDO $db,string $sql,bool $write,int $uid): array {
    $sql=trim($sql);demand(strlen($sql)>0&&strlen($sql)<=12000,'SQL must be 1–12000 characters');
    demand(!preg_match('/(;|--|#|\/\*|\*\/|\\x00|\\\\)/',$sql),'Comments, semicolons and multiple statements are not permitted');
    demand(!preg_match('/\b(INTO\s+(OUTFILE|DUMPFILE)|LOAD_FILE|BENCHMARK|SLEEP|FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s+MODE)\b/i',$sql),'Unsupported or unsafe SQL construct');
    if(!$write){demand((bool)preg_match('/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i',$sql),'Only read queries permitted');$stmt=$db->query($sql);$rows=[];while(count($rows)<101&&($row=$stmt->fetch(PDO::FETCH_ASSOC))!==false)$rows[]=$row;return ['rows'=>array_slice($rows,0,100),'shown'=>min(count($rows),100),'truncated'=>count($rows)>100];}
    demand(cfg('ALLOW_ADMIN_SQL_WRITE')==='1','SQL write operations disabled in environment',403);
    demand((bool)preg_match('/^(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?([a-z][a-z0-9_]*)`?\b/i',$sql,$m),'Only INSERT, UPDATE and DELETE statements are available');
    $table=strtolower($m[2]);demand(!in_array($table,['users','user_organization_scopes','audit_events','audit_logs','idempotency_keys','document_sequences','schema_migrations','migrations'],true),'Protected table cannot be modified using SQL console');
    if(preg_match('/^(UPDATE|DELETE)\b/i',$sql))demand((bool)preg_match('/\bWHERE\b/i',$sql),'UPDATE and DELETE require WHERE');
    $db->beginTransaction();try{$stmt=$db->prepare($sql);$stmt->execute();$count=$stmt->rowCount();demand($count<=100,'Write changed more than 100 rows; rolled back');$db->commit();settingsAudit($uid,'sql.write',['table'=>$table,'affected'=>$count,'sha256'=>hash('sha256',$sql)]);return ['affected'=>$count];}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
