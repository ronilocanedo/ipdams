<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/FinancialClosePolicy.php';
/** Organization mutex is acquired before any fund, DV or bank lock. The lock
 * lives until the API transaction commits/rolls back; no connection lock leaks. */
final class FinancialCutoff {
    private static ?WeakMap $coordinated=null;
    /** Only the transaction owner calls this after explicitly establishing the
     * READ COMMITTED financial transaction; callers must unmark in finally. */
    public static function markCoordinated(PDO $db):void {demand($db->inTransaction(),'Financial coordinator requires active transaction',500);self::$coordinated??=new WeakMap();self::$coordinated[$db]=true;}
    public static function isCoordinated(PDO $db):bool {return $db->inTransaction()&&(self::$coordinated[$db]??false)===true;}
    public static function unmarkCoordinated(PDO $db):void {if(self::$coordinated!==null&&isset(self::$coordinated[$db]))unset(self::$coordinated[$db]);}

    public static function lock(PDO $db,int $org):void {
        demand($db->inTransaction(),'Financial cutoff requires a transaction',500);
        demand(one($db,'SELECT id FROM organizations WHERE id=? FOR UPDATE',[$org])!==null,'Organization unavailable',404);
    }
    public static function closed(PDO $db,int $org):array {
        $rows=q($db,"SELECT id,config_key,config_json FROM configuration_versions WHERE organization_id=? AND LEFT(config_key,11)='FR456_BANK_' ORDER BY id DESC".($db->inTransaction()?' FOR UPDATE':''),[$org])->fetchAll();
        $seen=[];$closed=[];foreach($rows as $row){$key=$row['config_key'];if(isset($seen[$key]))continue;$seen[$key]=true;$body=json_decode($row['config_json'],true,512,JSON_THROW_ON_ERROR);if(($body['status']??'')==='CLOSED')$closed[]=['version_id'=>(int)$row['id']]+$body;}
        return $closed;
    }
    /** Original legacy rows are never edited; an audited reopening revision can
     * explicitly supersede only their cutoff gate, preserving original evidence. */
    public static function legacyClosed(PDO $db,int $org):array {
        $out=[];foreach(q($db,"SELECT r.* FROM bank_reconciliations r JOIN bank_accounts b ON b.id=r.bank_account_id WHERE b.organization_id=? AND r.status='CLOSED'".($db->inTransaction()?' FOR UPDATE':''),[$org])->fetchAll() as $r){
            $v=one($db,'SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1'.($db->inTransaction()?' FOR UPDATE':''),[$org,'FR456_BANK_'.$r['bank_account_id'].'_'.$r['period']]);
            $body=$v?json_decode($v['config_json'],true,512,JSON_THROW_ON_ERROR):[];
            if((($body['status']??'')==='REOPENED'||(($body['status']??'')==='DRAFT'&&($body['book_completeness']??'')==='VERIFIED_FROM_OPENING'))&&(int)($body['legacy_id']??0)===(int)$r['id'])continue;
            $out[]=$r;
        }return $out;
    }
    public static function assertOpen(PDO $db,int $org,string $date):void {
        demand(FinancialClosePolicy::day($date),'Financial effective date must be valid',422);
        foreach(self::closed($db,$org) as $close)demand($date>(new DateTimeImmutable($close['period'].'-01'))->format('Y-m-t'),'Financial period is closed. Reopen the dependent account closes before recording a backdated writer.',409);
        foreach(self::legacyClosed($db,$org) as $legacy)demand(substr($date,0,7)>$legacy['period'],'Legacy closed financial period requires reviewed reconciliation before backdating',409);
    }
    public static function beginWriter(PDO $db,array $user,string $route,array $body):void {
        if(!isset($body['organization_id']))return;
        $org=idOf($body,'organization_id');scope($db,$user,$org);
        // Share the linked Division's configuration gate; different schools can
        // proceed concurrently while their own organization remains exclusive.
        $row=one($db,'SELECT id,kind,parent_division_id FROM organizations WHERE id=?',[$org]);demand($row!==null,'Organization unavailable',404);
        $locks=[$org];if((int)($row['parent_division_id']??0)>0)$locks[]=(int)$row['parent_division_id'];
        if($row['kind']==='DIVISION'&&str_starts_with($route,'tax.transfer.')&&!empty($body['transfer_id'])){$transfer=one($db,'SELECT organization_id FROM school_tax_transfers WHERE id=? AND division_id=?',[(int)$body['transfer_id'],$org]);if($transfer)$locks[]=(int)$transfer['organization_id'];}
        if($row['kind']==='DIVISION'&&preg_match('/^(division\.|employee\.|staff\.|organization\.|travel.signatories)/',$route))foreach(q($db,'SELECT id FROM organizations WHERE parent_division_id=? ORDER BY id',[$org])->fetchAll() as $school)$locks[]=(int)$school['id'];
        $locks=array_unique($locks);sort($locks,SORT_NUMERIC);foreach($locks as $id){if($id!==$org&&$id===(int)($row['parent_division_id']??0)){demand($db->inTransaction(),'Financial cutoff requires a transaction',500);demand(one($db,'SELECT id FROM organizations WHERE id=? LOCK IN SHARE MODE',[$id])!==null,'Linked Division unavailable',409);}else self::lock($db,$id);}
        if(str_starts_with($route,'finance.review.'))return;
        if(!preg_match('/^(finance\.allocation\.|dv\.|check\.|fund\.|bank\.|tax\.|document.cancel|travel\.|division\.|organization\.|employee\.|staff\.|master\.|pr\.|po\.|iar\.|award\.|quote\.|rfq\.)/',$route))return;
        $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');$dates=[$today];
        foreach(['document_date','bank_credit_date','effective_from','effective_date','date','received_at','remitted_at'] as $key)if(isset($body[$key])&&is_string($body[$key])&&FinancialClosePolicy::day($body[$key]))$dates[]=$body[$key];
        // Corrections/cancellation of old documents must pass the same cutoff;
        // ordinary new check lifecycle events remain dated at their event time.
        if((preg_match('/^dv\./',$route)||str_starts_with($route,'finance.allocation.'))&&!empty($body['dv_id']??$body['id']??null)){$dv=one($db,'SELECT document_date FROM disbursement_vouchers WHERE id=? AND organization_id=?',[(int)($body['dv_id']??$body['id']),$org]);if(!empty($dv['document_date']))$dates[]=$dv['document_date'];}
        if($route==='document.cancel'&&($body['doc_type']??'')==='DV'&&!empty($body['id'])){$dv=one($db,'SELECT document_date FROM disbursement_vouchers WHERE id=? AND organization_id=?',[(int)$body['id'],$org]);if(!empty($dv['document_date']))$dates[]=$dv['document_date'];}
        foreach($locks as $id){if($id!==$org&&$id===(int)($row['parent_division_id']??0))continue;foreach(array_unique($dates) as $date)self::assertOpen($db,$id,$date);}
    }
}
