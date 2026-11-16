<?php
declare(strict_types=1);
/** IPDAMS v0.2.11 — supervised reset operations. Never expose this service as a public endpoint. */
require_once __DIR__.'/SettingsService.php';
require_once __DIR__.'/TravelResetDocumentBoundary.php';
const IPDAMS_RESET_TABLES = [
 'organizations','users','user_organization_scopes','departments','employees','employee_assignments','bac_terms','bac_members',
 'suppliers','funds','fund_events','units','item_catalog','uacs_accounts','item_account_mappings','tax_atcs','tax_rate_versions',
 'configuration_versions','document_counters','document_registry','document_versions','document_links','attachments',
 'audit_events','idempotency_keys','procurement_transactions','purchase_requests','pr_lines','rfqs','supplier_quotes',
 'quote_lines','awards','purchase_orders','po_lines','po_amendments','iars','iar_lines','advances','disbursement_vouchers',
 'dv_allocation_lines','dv_accounting_versions','voucher_accounting_lines','withholding_components','tax_disposition_events','school_tax_transfer_components','school_tax_transfers',
 'bank_accounts','checkbook_leaves','checks','payment_events','bank_reconciliations','reconciliation_lines','inventory_lots','stock_events',
 'requisition_issue_slips','ris_lines','assets','accountability_documents','asset_movements','textbook_titles',
 'textbook_batches','textbook_movements','manual_signature_records','supplier_atcs','accountability_movement_links','travel_itinerary_plans','travel_itinerary_plan_versions',
  'travel_claim_expense_reviews','travel_claim_expense_review_versions','travel_dv_accounting_approvals','travel_dv_claim_links','ipdams_schema_migrations'
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
    $travelTables=['travel_itinerary_plans','travel_itinerary_plan_versions',
        'travel_claim_expense_reviews','travel_claim_expense_review_versions'];
    $present=array_intersect($travelTables,$list);
    demand(count($present)===0 || count($present)===count($travelTables),
        'Reset blocked: partially installed travel-timeline schema requires technical review',409);
    $travelDvNew=['travel_dv_accounting_approvals','travel_dv_claim_links'];
    $dvPresent=array_intersect($travelDvNew,$list);
    demand(count($dvPresent)===0 || count($dvPresent)===count($travelDvNew),
        'Reset blocked: partial travel-DV schema requires technical review',409);
    $taxTransferTables=['school_tax_transfers','school_tax_transfer_components'];
    $taxTransferPresent=array_intersect($taxTransferTables,$list);
    demand(count($taxTransferPresent)===0 || count($taxTransferPresent)===count($taxTransferTables),
        'Reset blocked: partial tax-transfer schema requires technical review',409);
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
 'travel_documents'=>[
  'label'=>'Travel & Training — Activity Requests, Travel Authorities & Reimbursement Claims',
  'description'=>'AR, TA and RC documents plus temporary itineraries, chronological reviews, versions and evidence; restarts only their monthly control counters. Preserves signatories, letterheads, staff, audit and finances. Blocks financial or unrelated links.',
   'tables'=>['travel_itinerary_plan_versions','travel_itinerary_plans','travel_claim_expense_review_versions','travel_claim_expense_reviews','travel_dv_accounting_approvals','travel_dv_claim_links'],
   'roots'=>['travel_itinerary_plans','travel_claim_expense_reviews']],
 'procurement'=>['label'=>'Procurement → DV → Checks (entire transaction chain)','description'=>'PR, RFQ, supplier quotations, awards, PO, IAR, advances, DVs, withholding, check/payment records','tables'=>[
  'procurement_transactions','purchase_requests','pr_lines','rfqs','supplier_quotes','quote_lines','awards','purchase_orders','po_lines','po_amendments','iars','iar_lines','advances','disbursement_vouchers','dv_allocation_lines','travel_dv_claim_links','dv_accounting_versions','voucher_accounting_lines','withholding_components','tax_disposition_events','school_tax_transfer_components','school_tax_transfers','checks','payment_events'],'roots'=>['procurement_transactions']],
 'stock_property'=>['label'=>'Supply, RIS and Property (whole movement histories)','description'=>'Inventory lots, stock events, requisitions, issued stock, assets, ICS/PAR and asset movements; after a complete property wipe, restart the per-school lifetime PROPERTY_TAG sequence too','tables'=>['inventory_lots','stock_events','requisition_issue_slips','ris_lines','assets','accountability_documents','asset_movements','accountability_movement_links'],'roots'=>['requisition_issue_slips','assets','inventory_lots']],
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
/** Document records and their monthly counters are scoped by type; never touch unrelated numbering. */
const IPDAMS_CUSTOM_DOCUMENT_TYPES = [
 'travel_documents'=>['AR','TA','RC'],
 'procurement'=>['PR','RFQ','AWARD','PO','IAR','DV','CHECK','IAR_LINE'],
 'stock_property'=>['RIS','ICS','PAR','ITR','PTR','RRSP','PRR'],
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
/** A property number is NOT an ICS/PAR monthly document number.
 * It uses document_counters(doc_type='PROPERTY_TAG', period='ALLTIME').
 * Only a full Supply/RIS/Property group reset may rewind the lifetime sequence.
 * All other Custom Reset groups leave this lifetime counter untouched.
 */
function resetCustomCounterTypes(array $selection): array {
    $types=resetCustomDocumentTypes($selection);
    if(in_array('stock_property',$selection,true))$types[]='PROPERTY_TAG';
    return array_values(array_unique($types));
}
function resetCustomOptions(PDO $db): array {
    $names=resetNames($db);$valid=array_fill_keys($names,true);$out=[];
    foreach(IPDAMS_CUSTOM_RESET_GROUPS as $key=>$group){
        $n=0;
        foreach($group['roots'] as $root){if(isset($valid[$root]))$n+=(int)$db->query('SELECT COUNT(*) FROM `'.$root.'`')->fetchColumn();}
        // Document-only workflow has no independent SQL table root.
        if($key==='travel_documents')$n+=(int)$db->query("SELECT COUNT(*) FROM document_registry WHERE doc_type IN ('AR','TA','RC')")->fetchColumn();
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

/** Select only monthly counters belonging to workflows explicitly chosen for Custom Reset.
 * Existing system/test reset already clears document_counters as a full table.
 * A selective reset must never wipe other schools' unrelated types or physical check leaves.
 */
function resetScopedCounterPlan(PDO $db,array $types): array {
    if(!$types)return ['types'=>[],'count'=>0,'by_type'=>[]];
    // Exactly one all-time ledger is authorized for permanent property tags.
    // A malformed property counter period is unexpected and blocks the reset.
    if(in_array('PROPERTY_TAG',$types,true)){
        $unexpected=$db->query("SELECT COUNT(*) FROM document_counters WHERE doc_type='PROPERTY_TAG' AND period<>'ALLTIME'");
        demand((int)$unexpected->fetchColumn()===0,'Unexpected property number counter period; review required',409);
    }
    $marks=implode(',',array_fill(0,count($types),'?'));
    $st=$db->prepare('SELECT doc_type,COUNT(*) AS amount FROM document_counters WHERE doc_type IN ('.$marks.') GROUP BY doc_type');
    $st->execute($types);$rows=$st->fetchAll(PDO::FETCH_ASSOC);$count=0;$byType=[];
    foreach($rows as $row){$n=(int)$row['amount'];$byType[(string)$row['doc_type']]=$n;$count+=$n;}
    return ['types'=>$types,'count'=>$count,'by_type'=>$byType];
}
function resetDeleteScopedCounters(PDO $db,array $plan): void {
    $types=$plan['types']??[];if(!$types)return;
    $allowed=array_merge(...array_values(IPDAMS_CUSTOM_DOCUMENT_TYPES));
    $allowed[]='PROPERTY_TAG';
    demand(is_array($types) && !array_diff($types,$allowed),
        'Invalid selected document/property counter types',409);
    if(in_array('PROPERTY_TAG',$types,true)){
        // This check runs AFTER the full property tables are deleted, inside
        // the same transaction. If even one property survives, roll back all.
        foreach(['assets','accountability_documents','asset_movements'] as $table){
            $remaining=(int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
            demand($remaining===0,'Property-number restart blocked: '.$table.' still contains records',409);
        }
    }
    $marks=implode(',',array_fill(0,count($types),'?'));
    // No issuance record for selected types may survive the scoped document deletion.
    $st=$db->prepare('SELECT COUNT(*) FROM document_registry WHERE doc_type IN ('.$marks.')');
    $st->execute($types);
    demand((int)$st->fetchColumn()===0,'Selected document records remain: numbering reset refused',409);
    $st=$db->prepare('DELETE FROM document_counters WHERE doc_type IN ('.$marks.')');
    $st->execute($types);
    demand($st->rowCount()===(int)($plan['count']??-1),
        'Document counter rows changed after preview; reset rolled back',409);
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
    $selected=[];$scoped=['types'=>[],'counts'=>[],'attachments'=>[]];$counterReset=['types'=>[],'count'=>0,'by_type'=>[]];
    if($mode==='custom'){
        $selected=resetCustomSelection($selectedGroups);
        // v0.2.273: Book Directory profiles are append-only TBP documents with
        // ISBN-linked textbook_title IDs. Do not allow a selective textbook reset
        // to erase linked titles while silently preserving their official profiles.
        // Hard reset remains subject to the existing whole-database backup plan.
        if(in_array('textbooks',$selected,true)){
            $profileCount=(int)$db->query("SELECT COUNT(*) FROM document_registry WHERE doc_type='TBP'")->fetchColumn();
            demand($profileCount===0,
                'Textbook reset blocked: shared book profiles have documented identities. Use a reviewed full textbook/profile reset workflow; no records were removed.',409);
        }
        // All historical snapshots and inbound/outbound links must be checked before deletion.
        // The same resetPlan is rerun before prepare, verify and the final DELETE transaction.
        if(in_array('travel_documents',$selected,true)){
            try{IPDAMSTravelResetBoundary::inspectDatabase($db);}
            catch(DomainException $e){throw new DomainError($e->getMessage(),409);}
        }
        // Financial reset must not strand a preserved reimbursement claim that already
        // has documented financial activity, even when Travel is not selected.
        if(array_intersect($selected,['procurement','fund_ledger','funds','bank_recon','check_inventory','bank_accounts'])){
            try{IPDAMSTravelResetBoundary::guardFinanceRemoval($db);IPDAMSTravelResetBoundary::guardFundLedger($db);}
            catch(DomainException $e){throw new DomainError($e->getMessage(),409);}
        }
        $tables=resetCustomTables($selected);
        // A travel-linked DV crosses the documentary and financial reset boundaries.
        // Never delete only one side of a live or historical consolidated DV.
        if(($counts['travel_dv_claim_links']??0)>0 && array_intersect($selected,['travel_documents','procurement','fund_ledger','funds','bank_recon','check_inventory','bank_accounts'])){
            demand(in_array('travel_documents',$selected,true) && in_array('procurement',$selected,true),
                'Travel-linked DVs exist: select both Travel Documents and Procurement → DV → Checks for a reviewed whole-workflow reset, or preserve both.',409);
        }
        // The new tables may be entirely absent before the v0.2.327 migration;
        // never accept an incomplete partial DDL installation.
        $newTravelDv=['travel_dv_accounting_approvals','travel_dv_claim_links'];
        $newPresent=array_values(array_intersect($newTravelDv,$names));
        demand(count($newPresent)===0 || count($newPresent)===count($newTravelDv),
            'Incomplete Travel-to-DV schema; selective reset blocked',409);
        if(!$newPresent)$tables=array_values(array_diff($tables,$newTravelDv));
        // During the updater-only release, the four documentary travel tables do not exist yet.
        // Accept an entirely absent travel group for an existing pre-migration travel reset,
        // but fail closed on a partial table inventory (e.g. interrupted DDL).
        $travelNew=['travel_itinerary_plans','travel_itinerary_plan_versions',
            'travel_claim_expense_reviews','travel_claim_expense_review_versions'];
        $travelPresent=array_values(array_intersect($travelNew,$names));
        demand(count($travelPresent)===0 || count($travelPresent)===count($travelNew),
            'Incomplete travel-timeline migration; reset blocked pending technical review',409);
        if(count($travelPresent)===0 && in_array('travel_documents',$selected,true))
            $tables=array_values(array_diff($tables,$travelNew));
        // The separately approved property-link table is optional on installations
        // which never used its migration; do not block an otherwise valid reset.
        if(!in_array('accountability_movement_links',$names,true) && in_array('stock_property',$selected,true))
            $tables=array_values(array_diff($tables,['accountability_movement_links']));
        demand(!array_diff($tables,$names),'Custom reset requires updated database migration(s): '.implode(', ',array_diff($tables,$names)),409);
        $order=resetCustomOrder($names,$deps,$tables,$counts);
        $scoped=resetScopedDocumentPlan($db,resetCustomDocumentTypes($selected));
        $counterReset=resetScopedCounterPlan($db,resetCustomCounterTypes($selected));
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
    $rows=array_sum(array_intersect_key($counts,array_flip($order)))+array_sum($scoped['counts'])+$counterReset['count'];
    return ['mode'=>$mode,'selected_groups'=>$selected,'tables'=>$order,'scoped_documents'=>$scoped,'counts'=>$counts,'rows_to_delete'=>$rows,
        'counter_reset'=>$counterReset,'preserved'=>$mode==='hard'?[]:IPDAMS_RESET_PRESERVE,'schema_tables'=>$names,'fingerprint'=>resetFingerprint($db,$names)];
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
    demand($new['fingerprint']===$meta['plan']['fingerprint'] &&
        $new['tables']===$meta['plan']['tables'] &&
        $new['counter_reset']['types']===($meta['plan']['counter_reset']['types']??null) &&
        $new['counter_reset']['count']===($meta['plan']['counter_reset']['count']??null) &&
        $new['scoped_documents']['types']===($meta['plan']['scoped_documents']['types']??null) &&
        $new['scoped_documents']['counts']===($meta['plan']['scoped_documents']['counts']??null) &&
        $new['rows_to_delete']===$meta['plan']['rows_to_delete'],
        'Reset plan or numbering scope changed since backup. Prepare a fresh backup and preview.',409);
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
            demand($fresh['fingerprint']===$meta['plan']['fingerprint'] &&
                $fresh['counter_reset']['types']===($meta['plan']['counter_reset']['types']??null) &&
                $fresh['counter_reset']['count']===($meta['plan']['counter_reset']['count']??null) &&
                $fresh['rows_to_delete']===$meta['plan']['rows_to_delete'],
                'Reset plan or numbering scope changed before deletion; no changes made',409);
            if($mode==='custom')resetDeleteScopedDocuments($db,$meta['plan']['scoped_documents']);
            foreach($meta['plan']['tables'] as $name){
                if($name==='organizations')$db->exec('UPDATE organizations SET parent_division_id=NULL'); // self FK only
                $db->exec('DELETE FROM `'.$name.'`');
            }
            // Custom document/property counters are cleared LAST so PROPERTY_TAG
            // only restarts after all assets, custody and movement rows are gone.
            // The transaction guarantees rollback of all earlier deletions on error.
            if($mode==='custom')resetDeleteScopedCounters($db,$meta['plan']['counter_reset']??[]);
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
