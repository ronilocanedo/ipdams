<?php
declare(strict_types=1);
/** IPDAMS v0.2.11 — supervised reset operations. Never expose this service as a public endpoint. */
require_once __DIR__.'/SettingsService.php';
const IPDAMS_RESET_TABLES = [
 'organizations','users','user_organization_scopes','departments','employees','employee_assignments','bac_terms','bac_members',
 'suppliers','funds','fund_events','units','item_catalog','uacs_accounts','item_account_mappings','tax_atcs','tax_rate_versions',
 'configuration_versions','document_counters','document_registry','document_versions','document_links','attachments',
 'audit_events','idempotency_keys','procurement_transactions','purchase_requests','pr_lines','rfqs','supplier_quotes',
 'quote_lines','awards','purchase_orders','po_lines','po_amendments','iars','iar_lines','advances','disbursement_vouchers',
 'dv_allocation_lines','dv_accounting_versions','voucher_accounting_lines','withholding_components','tax_disposition_events',
 'bank_accounts','checkbook_leaves','checks','payment_events','bank_reconciliations','reconciliation_lines','inventory_lots','stock_events',
 'requisition_issue_slips','ris_lines','assets','accountability_documents','asset_movements','textbook_titles',
 'textbook_batches','textbook_movements','manual_signature_records','supplier_atcs','ipdams_schema_migrations'
];
const IPDAMS_RESET_PRESERVE = ['organizations','users','user_organization_scopes'];
const IPDAMS_RESET_MAX_ROWS = 8000;
const IPDAMS_RESET_MAX_BYTES = 24000000;
function resetDir(): string {
    $dir=settingsPrivate().'/reset-archives';
    if(!is_dir($dir)) demand(mkdir($dir,0700,true),'Reset archive storage could not be created',500);
    demand(is_writable($dir),'Reset archive storage is not writable',500);
    return $dir;
}
function resetIdentifier(string $id): string {
    demand((bool)preg_match('/^[a-f0-9]{32}$/',$id),'Invalid reset backup identifier',400);
    return $id;
}
function resetMetadata(string $id): array {
    $p=resetDir().'/'.resetIdentifier($id).'.json';
    demand(is_file($p),'Backup not found',404);
    $v=json_decode((string)file_get_contents($p),true);
    demand(is_array($v),'Invalid backup metadata',500);
    return $v;
}
function resetWriteMeta(string $id,array $meta): void { settingsWriteJson(resetDir().'/'.resetIdentifier($id).'.json',$meta); }
function resetNames(PDO $db): array {
    $list=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    demand(is_array($list) && $list!==[],'No database tables found',500);
    $unknown=array_diff($list,IPDAMS_RESET_TABLES);
    demand(!$unknown,'Reset blocked: unrecognized tables require an updated safe-reset plan: '.implode(', ',array_slice($unknown,0,10)),409);
    $missing=array_diff(['organizations','users','user_organization_scopes','audit_events'],$list);
    demand(!$missing,'Required database tables missing: '.implode(', ',$missing),409);
    return array_values($list);
}
/** Child tables first, with foreign keys kept ON at all times; unknown dependencies fail closed. */
function resetOrder(array $names,array $references,bool $hard): array {
    $targets=array_values(array_diff($names,$hard?['ipdams_schema_migrations']:array_merge(IPDAMS_RESET_PRESERVE,['ipdams_schema_migrations'])));
    $set=array_fill_keys($targets,true);$edges=[];
    foreach($targets as $n)$edges[$n]=[];
    foreach($references as $edge){
        [$child,$parent]=$edge;
        if($child===$parent)continue;
        if(!isset($set[$child])&&isset($set[$parent]))throw new DomainError('Preserved table '.$child.' depends on table '.$parent.' scheduled for deletion; reset blocked',409);
        if(isset($set[$child])&&isset($set[$parent]))$edges[$child][$parent]=true;
    }
    $result=[];
    while($edges){
        $ready=[];
        foreach($edges as $table=>$parents){
            $isReferenced=false;
            foreach($edges as $candidate=>$refs)if($candidate!==$table && isset($refs[$table])){$isReferenced=true;break;}
            if(!$isReferenced)$ready[]=$table;
        }
        // Choose tables that have no remaining dependents: parent table can't be deleted before child.
        if(!$ready)throw new DomainError('Reset blocked: circular table dependencies require manual review',409);
        sort($ready);
        // The above selects children (no inbound edges), not parents.
        foreach($ready as $name){$result[]=$name;unset($edges[$name]);}
    }
    return $result;
}

/** Curated business groups. All tables in an independent workflow are cleared together. */
const IPDAMS_CUSTOM_RESET_GROUPS = [
 'procurement'=>['label'=>'Procurement → DV → Checks (entire transaction chain)','description'=>'PR, RFQ, supplier quotations, awards, PO, IAR, advances, DVs, withholding, check/payment records','tables'=>[
  'procurement_transactions','purchase_requests','pr_lines','rfqs','supplier_quotes','quote_lines','awards','purchase_orders','po_lines','po_amendments','iars','iar_lines','advances','disbursement_vouchers','dv_allocation_lines','dv_accounting_versions','voucher_accounting_lines','withholding_components','tax_disposition_events','checks','payment_events'],'roots'=>['procurement_transactions']],
 'stock_property'=>['label'=>'Supply, RIS and Property (whole movement histories)','description'=>'Inventory lots, stock events, requisitions, issued stock, assets, ICS/PAR and asset movements','tables'=>['inventory_lots','stock_events','requisition_issue_slips','ris_lines','assets','accountability_documents','asset_movements'],'roots'=>['requisition_issue_slips','assets','inventory_lots']],
 'textbooks'=>['label'=>'Textbooks (whole title/batch histories)','description'=>'Titles, book batches and all distribution/movement events','tables'=>['textbook_titles','textbook_batches','textbook_movements'],'roots'=>['textbook_titles']],
 'bank_recon'=>['label'=>'Bank Reconciliation Records','description'=>'Bank reconciliation headers and all reconciliation lines','tables'=>['bank_reconciliations','reconciliation_lines'],'roots'=>['bank_reconciliations']],
 'check_inventory'=>['label'=>'Physical Checkbook Leaf Inventory (all serial numbers)','description'=>'WARNING: removes physical check serials including available, issued, spoiled, lost and void leaves; issued checks require also selecting Procurement → DV → Checks','tables'=>['checkbook_leaves'],'roots'=>['checkbook_leaves']],
 'bank_accounts'=>['label'=>'Bank Account Master','description'=>'Bank accounts, subject to check and reconciliation dependencies','tables'=>['bank_accounts'],'roots'=>['bank_accounts']],
 'funds'=>['label'=>'Fund Sources and Fund Ledger','description'=>'Fund source records and all allotment, obligation, credit and payment ledger events','tables'=>['funds','fund_events'],'roots'=>['funds']],
 'fund_ledger'=>['label'=>'Fund Ledger Transactions — Preserve Fund Sources','description'=>'Clear all fund ledger transactions (allotments, releases, obligations, credits, disbursements and adjustments) while keeping the Fund Sources master records. If procurement exists, select the Procurement group too.','tables'=>['fund_events'],'roots'=>['fund_events']],
 'suppliers'=>['label'=>'Suppliers and Their ATC Assignments','description'=>'Supplier profiles and supplier-to-ATC assignments','tables'=>['suppliers','supplier_atcs'],'roots'=>['suppliers']],
 'atcs'=>['label'=>'Withholding Tax ATCs and Rate Versions','description'=>'ATC master entries and their entire rate history','tables'=>['tax_atcs','tax_rate_versions'],'roots'=>['tax_atcs']],
 'catalog'=>['label'=>'Item Catalog and Units of Measure','description'=>'Item records, units and item-to-account mappings','tables'=>['item_catalog','units','item_account_mappings'],'roots'=>['item_catalog']],
 'uacs'=>['label'=>'UACS / Accounting Chart of Accounts','description'=>'UACS master accounts, subject to item and DV dependencies','tables'=>['uacs_accounts'],'roots'=>['uacs_accounts']],
 'personnel'=>['label'=>'Employees, Departments and BAC','description'=>'Office departments, employees, assignments, BAC terms and membership; does not delete user logins','tables'=>['departments','employees','employee_assignments','bac_terms','bac_members'],'roots'=>['employees','bac_terms']],
];
/** Document records are scoped by type; do not reset unrelated document numbering. */
const IPDAMS_CUSTOM_DOCUMENT_TYPES = [
 'procurement'=>['PR','RFQ','AWARD','PO','IAR','DV','CHECK','IAR_LINE'],
 'stock_property'=>['RIS','ICS','PAR'],
];
function resetCustomSelection(array $requested): array {
    demand(count($requested)<=count(IPDAMS_CUSTOM_RESET_GROUPS),'Too many selected reset groups',400);
    $groups=[];
    foreach($requested as $name){
        demand(is_string($name)&&isset(IPDAMS_CUSTOM_RESET_GROUPS[$name]),'Unknown custom reset selection',400);
        $groups[$name]=true;
    }
    demand($groups!==[],'Select at least one data group',400);
    return array_keys($groups);
}
function resetCustomTables(array $selection): array {
    $tables=[];
    foreach($selection as $name)foreach(IPDAMS_CUSTOM_RESET_GROUPS[$name]['tables'] as $table)$tables[$table]=true;
    return array_keys($tables);
}
function resetCustomDocumentTypes(array $selection): array {
    $types=[];
    foreach($selection as $name)foreach(IPDAMS_CUSTOM_DOCUMENT_TYPES[$name]??[] as $type)$types[$type]=true;
    return array_keys($types);
}
function resetCustomOptions(PDO $db): array {
    $names=resetNames($db);$valid=array_fill_keys($names,true);$out=[];
    foreach(IPDAMS_CUSTOM_RESET_GROUPS as $key=>$group){
        $n=0;
        foreach($group['roots'] as $root){if(isset($valid[$root]))$n+=(int)$db->query('SELECT COUNT(*) FROM `'.$root.'`')->fetchColumn();}
        $out[$key]=['label'=>$group['label'],'description'=>$group['description'],'independent_count'=>$n];
    }
    return $out;
}
/** Fail closed if a retained, populated child table references a table to be wiped. */
function resetCustomOrder(array $names,array $references,array $targets,array $counts): array {
    $set=array_fill_keys($targets,true);
    foreach($references as $edge){[$child,$parent]=$edge;
        if(!isset($set[$parent])||isset($set[$child])||$child===$parent)continue;
        if(($counts[$child]??0)>0 && ($counts[$parent]??0)>0){
            $needed=[];
            foreach(IPDAMS_CUSTOM_RESET_GROUPS as $key=>$group)if(in_array($child,$group['tables'],true))$needed[]=$group['label'];
            throw new DomainError('Cannot delete '.$parent.' while '.$child.' contains records. Also select '.implode(' / ',$needed).', or keep the parent group.',409);
        }
    }
    $filtered=[];foreach($references as $edge){if(isset($set[$edge[0]])&&isset($set[$edge[1]]))$filtered[]=$edge;}
    return resetOrder($targets,$filtered,true);
}
/** Counts typed documents that belong to selected workflows, without touching other workflows. */
function resetScopedDocumentPlan(PDO $db,array $types): array {
    if(!$types)return ['types'=>[],'counts'=>[],'attachments'=>[]];
    $marks=implode(',',array_fill(0,count($types),'?'));
    $counts=[];
    foreach(['document_registry','document_versions','attachments','manual_signature_records'] as $table){
        $st=$db->prepare('SELECT COUNT(*) FROM `'.$table.'` WHERE doc_type IN ('.$marks.')');$st->execute($types);$counts[$table]=(int)$st->fetchColumn();
    }
    $st=$db->prepare('SELECT COUNT(*) FROM document_links WHERE source_type IN ('.$marks.') OR target_type IN ('.$marks.')');
    $st->execute(array_merge($types,$types));$counts['document_links']=(int)$st->fetchColumn();
    $st=$db->prepare('SELECT stored_filename FROM attachments WHERE doc_type IN ('.$marks.')');$st->execute($types);
    return ['types'=>$types,'counts'=>$counts,'attachments'=>$st->fetchAll(PDO::FETCH_COLUMN)];
}
function resetDeleteScopedDocuments(PDO $db,array $plan): void {
    $types=$plan['types']??[];if(!$types)return;
    $marks=implode(',',array_fill(0,count($types),'?'));
    $st=$db->prepare('DELETE FROM document_links WHERE source_type IN ('.$marks.') OR target_type IN ('.$marks.')');$st->execute(array_merge($types,$types));
    foreach(['manual_signature_records','attachments','document_versions','document_registry'] as $table){
        $st=$db->prepare('DELETE FROM `'.$table.'` WHERE doc_type IN ('.$marks.')');$st->execute($types);
    }
}

function resetFingerprint(PDO $db,array $names): string {
    $stream=hash_init('sha256');
    foreach($names as $table){
        $entryHashes=[];
        $stmt=$db->query('SELECT * FROM `'.$table.'`');
        while(($row=$stmt->fetch(PDO::FETCH_ASSOC))!==false){
            $entryHashes[]=hash('sha256',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR));
        }
        sort($entryHashes,SORT_STRING);
        hash_update($stream,$table.'='.implode(',', $entryHashes).'\n');
    }
    return hash_final($stream);
}
function resetPlan(PDO $db,string $mode,array $selectedGroups=[]): array {
    demand(in_array($mode,['test','hard','custom'],true),'Unknown reset mode');
    $names=resetNames($db);
    $deps=$db->query("SELECT TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll(PDO::FETCH_NUM);
    $counts=[];$total=0;
    foreach($names as $name){$n=(int)$db->query('SELECT COUNT(*) FROM `'.$name.'`')->fetchColumn();$counts[$name]=$n;$total+=$n;}
    demand($total<=IPDAMS_RESET_MAX_ROWS,'Too much data for web reset ('.$total.' rows). Use a dedicated maintenance and backup procedure.',409);
    $selected=[];$scoped=['types'=>[],'counts'=>[],'attachments'=>[]];
    if($mode==='custom'){
        $selected=resetCustomSelection($selectedGroups);
        $tables=resetCustomTables($selected);
        demand(!array_diff($tables,$names),'Custom reset requires updated database migration(s): '.implode(', ',array_diff($tables,$names)),409);
        $order=resetCustomOrder($names,$deps,$tables,$counts);
        $scoped=resetScopedDocumentPlan($db,resetCustomDocumentTypes($selected));
        // Check serials link to the check ledger by (bank_account_id, serial), not always a foreign key.
        // A selective reset must not silently orphan physical check leaves or issued-check records.
        $hasLeaves=($counts['checkbook_leaves']??0)>0;
        $hasChecks=($counts['checks']??0)>0;
        if(in_array('procurement',$selected,true) && $hasLeaves && !in_array('check_inventory',$selected,true)){
            throw new DomainError('Physical checkbook leaves are registered. A procurement/payment reset would leave check-number inventory out of sync. Explicitly include Physical Checkbook Leaf Inventory, or keep Procurement/Checks.',409);
        }
        if(in_array('check_inventory',$selected,true) && $hasChecks && !in_array('procurement',$selected,true)){
            throw new DomainError('Issued check records exist. Physical Checkbook Leaf Inventory cannot be cleared separately; also select Procurement → DV → Checks, or keep the leaf inventory.',409);
        }
        if(in_array('bank_accounts',$selected,true) && $hasLeaves && !in_array('check_inventory',$selected,true)){
            throw new DomainError('Bank accounts still have registered physical check leaves. Also select Physical Checkbook Leaf Inventory, or keep the bank accounts.',409);
        }
        // Fund events have a generic reference to PR/DV without a formal foreign key.
        if(in_array('procurement',$selected,true) && !in_array('fund_ledger',$selected,true) && !in_array('funds',$selected,true) && ($counts['fund_events']??0)>0){
            throw new DomainError('The fund ledger has entries that could refer to PRs or DVs. Select Fund Ledger Transactions — Preserve Fund Sources too, or keep the procurement group.',409);
        }
        // An independent ledger clear would strand existing PR/DV/check records whose financial
        // allotment and obligation trail is recorded through fund_events.reference_type/id.
        if(in_array('fund_ledger',$selected,true) && !in_array('procurement',$selected,true)){
            foreach(['procurement_transactions','purchase_requests','disbursement_vouchers','checks','payment_events'] as $linkedTable){
                if(($counts[$linkedTable]??0)>0){
                    throw new DomainError('Fund ledger transactions cannot be cleared while '.$linkedTable.' contains financial/procurement records. Also select Procurement → DV → Checks and its dependent groups, or preserve the fund ledger.',409);
                }
            }
        }
        if(in_array('procurement',$selected,true) && !in_array('bank_recon',$selected,true) && ($counts['bank_reconciliations']??0)>0){
            throw new DomainError('Bank reconciliation records exist. Select Bank Reconciliation Records with Procurement/Payments to avoid leaving reconciliation totals referencing deleted checks.',409);
        }
    }else $order=resetOrder($names,$deps,$mode==='hard');
    $rows=array_sum(array_intersect_key($counts,array_flip($order)))+array_sum($scoped['counts']);
    return ['mode'=>$mode,'selected_groups'=>$selected,'tables'=>$order,'scoped_documents'=>$scoped,'counts'=>$counts,'rows_to_delete'=>$rows,
        'preserved'=>$mode==='hard'?[]:IPDAMS_RESET_PRESERVE,'schema_tables'=>$names,'fingerprint'=>resetFingerprint($db,$names)];
}
function resetSqlValue(PDO $db,mixed $value): string {
    if($value===null)return 'NULL';
    $quoted=$db->quote((string)$value);
    demand(is_string($quoted),'Cannot safely quote a database value during backup',500);
    return $quoted;
}
function resetReadOnlyBackup(PDO $db,array $plan,string $file): array {
    demand(class_exists(ZipArchive::class),'PHP ZIP extension is required for safe reset',503);
    $base=dirname($file);$sqlfile=tempnam($base,'dump-');demand(is_string($sqlfile),'Cannot create backup SQL',500);
    @chmod($sqlfile,0600);
    $fp=fopen($sqlfile,'wb');demand($fp!==false,'Cannot write backup SQL',500);
    $files=[];$fileSum='';
    try {
        fwrite($fp,"-- IPDAMS protected reset backup. Contains sensitive data. Restore ONLY to a separate empty MySQL database.\n-- Requires the same MySQL/MariaDB capabilities as the source installation.\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        foreach($plan['schema_tables'] as $name){
            $name=(string)$name;$schema=$db->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_NUM);
            demand(is_array($schema)&&isset($schema[1]),'Schema backup failed for '.$name,500);
            fwrite($fp,(string)$schema[1].";\n");
        }
        foreach($plan['schema_tables'] as $name){
            $stmt=$db->query('SELECT * FROM `'.$name.'`');$cols=null;
            while(($row=$stmt->fetch(PDO::FETCH_ASSOC))!==false){
                if($cols===null)$cols=array_keys($row);
                $values=array_map(fn($v)=>resetSqlValue($db,$v),array_values($row));
                $quoted=array_map(fn($c)=>'`'.str_replace('`','``',$c).'`',$cols);
                fwrite($fp,'INSERT INTO `'.$name.'` ('.implode(',',$quoted).') VALUES ('.implode(',',$values).');' ."\n");
                demand(ftell($fp)<=IPDAMS_RESET_MAX_BYTES,'Backup exceeds safe shared-hosting size; no reset performed',409);
            }
        }
        fwrite($fp,"SET FOREIGN_KEY_CHECKS=1;\n");fflush($fp);fclose($fp);$fp=null;
        $zip=new ZipArchive();demand($zip->open($file,ZipArchive::CREATE|ZipArchive::EXCL)===true,'Cannot create reset backup ZIP',500);
        demand($zip->addFile($sqlfile,'database.sql'),'Cannot archive SQL',500);
        $attachments=$db->query('SELECT stored_filename,sha256 FROM attachments')->fetchAll(PDO::FETCH_ASSOC);
        $root=settingsRoot().'/storage/private';
        foreach($attachments as $a){
            $filename=(string)$a['stored_filename'];
            demand((bool)preg_match('/^[a-f0-9]{48}\.(pdf|jpg|png)$/',$filename),'Unsupported attachment filename; reset blocked',409);
            $path=$root.'/'.$filename;
            demand(is_file($path)&&!is_link($path),'An attachment is missing from private storage; reset blocked',409);
            demand(hash_equals((string)$a['sha256'],(string)hash_file('sha256',$path)),'Attachment checksum mismatch; reset blocked',409);
            demand($zip->addFile($path,'attachments/'.$filename),'Cannot add attachment to backup',500);
            $files[]=$filename;
        }
        $json=json_encode(['backup_generated'=>date('c'),'mode'=>$plan['mode'],'table_counts'=>$plan['counts'],'attachments'=>$files],JSON_THROW_ON_ERROR);
        demand($zip->addFromString('manifest.json',$json),'Cannot add backup metadata',500);
        demand($zip->close(),'Cannot finalize ZIP archive',500);
        demand(filesize($file)<=IPDAMS_RESET_MAX_BYTES,'ZIP too large for safe reset',409);
        $check=new ZipArchive();demand($check->open($file)===true,'Cannot verify ZIP',500);
        try{
            demand($check->numFiles===count($files)+2,'Backup entry-count mismatch',500);
            demand(hash_equals(hash_file('sha256',$sqlfile),hash('sha256',(string)$check->getFromName('database.sql'))),'SQL backup hash verification failed',500);
        }finally{$check->close();}
        @chmod($file,0600);
        return ['sha256'=>hash_file('sha256',$file),'attachments'=>$files,'size'=>filesize($file)];
    } finally {if(is_resource($fp))fclose($fp);@unlink($sqlfile);}
}
function resetPrepare(PDO $db,int $uid,string $mode,array $selectedGroups=[]): array {
    demand(!$db->inTransaction(),'Reset preview requires its own transaction',409);
    // A failed attempt to prepare a different selection must not expose the previous
    // destructive confirmation form (e.g. Reset Test Data after Custom Reset fails).
    if(session_status()===PHP_SESSION_ACTIVE){
        $previous=$_SESSION['reset_preview_id']??null;
        unset($_SESSION['reset_preview_id']);
        if(is_string($previous) && preg_match('/^[a-f0-9]{32}$/D',$previous)){
            $oldPath=resetDir().'/'.$previous.'.json';
            if(is_file($oldPath)){
                $old=resetMetadata($previous);
                if((int)($old['actor']??0)===$uid && ($old['status']??null)==='prepared'){
                    $old['status']='superseded';
                    resetWriteMeta($previous,$old);
                }
            }
        }
    }
    $db->beginTransaction();
    try{$plan=resetPlan($db,$mode,$selectedGroups);}catch(Throwable $e){$db->rollBack();throw $e;}
    if($plan['rows_to_delete']<=0){$db->rollBack();throw new DomainError('There are no records to delete under this reset mode');}
    $id=bin2hex(random_bytes(16));$archive=resetDir().'/'.$id.'.zip';
    try{$result=resetReadOnlyBackup($db,$plan,$archive);$db->commit();}
    catch(Throwable $e){if($db->inTransaction())$db->rollBack();@unlink($archive);throw $e;}
    $meta=['id'=>$id,'actor'=>$uid,'created'=>time(),'status'=>'prepared','mode'=>$mode,'plan'=>$plan,'archive'=>$result];
    resetWriteMeta($id,$meta);
    settingsAudit($uid,'reset.backup.prepared',['mode'=>$mode,'id'=>$id,'deleted_rows'=>$plan['rows_to_delete'],'groups'=>$plan['selected_groups']??[]]);
    return $meta;
}
function resetVerify(PDO $db,array $meta,int $uid): void {
    demand($meta['status']==='prepared'&&$meta['actor']===$uid,'Reset backup is unavailable for this user',403);
    demand(time()-(int)$meta['created']<=1200,'Reset preview expired. Create a new backup.',409);
    $path=resetDir().'/'.$meta['id'].'.zip';
    demand(is_file($path)&&hash_equals((string)$meta['archive']['sha256'],(string)hash_file('sha256',$path)),'Reset backup integrity check failed',409);
    $new=resetPlan($db,$meta['mode'],$meta['plan']['selected_groups']??[]);
    demand($new['fingerprint']===$meta['plan']['fingerprint'] && $new['tables']===$meta['plan']['tables'],'Database changed since backup. Prepare a new snapshot.',409);
}
function resetDownload(PDO $db,int $uid,string $id): never {
    $meta=resetMetadata($id);demand($meta['actor']===$uid,'Access denied',403);
    $path=resetDir().'/'.$id.'.zip';
    demand(is_file($path)&&hash_equals((string)$meta['archive']['sha256'],(string)hash_file('sha256',$path)),'Backup unavailable',409);
    $meta['download_initiated_at']=time();resetWriteMeta($id,$meta);
    header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="IPDAMS-Reset-Backup-'.$id.'.zip"');
    header('Content-Length: '.filesize($path));header('Cache-Control: no-store, private');readfile($path);exit;
}
/** Prepared backup required; action cannot complete without a verified archive and correct password. */
function resetApply(PDO $db,int $uid,string $id,string $mode,?array $newAdmin): array {
    $meta=resetMetadata($id);demand($meta['mode']===$mode,'Reset mode mismatch');
    demand(!empty($meta['download_initiated_at']),'Download the protected reset backup ZIP before continuing',409);
    $lock=settingsLock();
    $maintenance=settingsPrivate().'/reset-maintenance.lock';
    demand(!is_file($maintenance),'Reset is already underway',409);
    try {
        resetVerify($db,$meta,$uid);
        demand(file_put_contents($maintenance,$id,LOCK_EX)!==false,'Cannot enter maintenance mode',500);@chmod($maintenance,0600);
        $db->beginTransaction();
        try{
            // Revalidate row counts after starting the transaction and before the first delete.
            $fresh=resetPlan($db,$mode,$meta['plan']['selected_groups']??[]);
            demand($fresh['fingerprint']===$meta['plan']['fingerprint'],'Database changed before reset; no changes made',409);
            if($mode==='custom')resetDeleteScopedDocuments($db,$meta['plan']['scoped_documents']);
            foreach($meta['plan']['tables'] as $name){
                if($name==='organizations')$db->exec('UPDATE organizations SET parent_division_id=NULL'); // self FK only
                $db->exec('DELETE FROM `'.$name.'`');
            }
            $newId=null;
            if($mode==='hard'){
                demand(is_array($newAdmin),'Replacement administrator is required',400);
                $st=$db->prepare("INSERT INTO users(email,password_hash,full_name,role,active) VALUES (?,?,?,'SUPER_ADMIN',1)");
                $st->execute([$newAdmin['email'],password_hash($newAdmin['password'],PASSWORD_DEFAULT),$newAdmin['name']]);
                $newId=(int)$db->lastInsertId();
            }else{
                $newId=$uid;
                $db->prepare('INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES(NULL,?,?,?,?,?)')
                    ->execute([$uid,'system.test_reset','RESET',0,json_encode(['backup_id'=>$id,'mode'=>$mode],JSON_THROW_ON_ERROR)]);
            }
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        $meta['status']='completed';$meta['completed_at']=time();$meta['replacement_admin_id']=$newId;
        resetWriteMeta($id,$meta);
        settingsAudit($uid,'reset.completed',['mode'=>$mode,'backup_id'=>$id,'deleted_rows'=>$meta['plan']['rows_to_delete'],'groups'=>$meta['plan']['selected_groups']??[]]);
        $fileIssues=[];
        $filesToDelete=$mode==='custom'?($meta['plan']['scoped_documents']['attachments']??[]):$meta['archive']['attachments'];
        foreach($filesToDelete as $file){
            $path=settingsRoot().'/storage/private/'.$file;
            if(is_file($path)&&!@unlink($path))$fileIssues[]=$file;
        }
        return ['mode'=>$mode,'deleted_rows'=>$meta['plan']['rows_to_delete'],'attachments_not_removed'=>$fileIssues];
    }finally{ @unlink($maintenance);flock($lock,LOCK_UN);fclose($lock); }
}
