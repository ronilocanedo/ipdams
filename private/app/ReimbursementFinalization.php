<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
/**
 * v0.2.353: one immutable source-level reimbursement finalization record.
 * Reuses existing versioned configuration + audit facilities, no migration.
 * This is documentary/payment authority EVIDENCE, not a DV, journal, cash event,
 * tax disposition, bank payment, or check assignment.
 */
final class ReimbursementFinalization {
    private const PREFIX='ERF353_';
    public static function key(string $kind,int $id): string {
        demand(in_array($kind,['T','O'],true)&&$id>0,'Invalid reimbursement evidence key',422);
        return self::PREFIX.$kind.'_'.$id;
    }
    public static function load(PDO $db,int $org,string $kind,int $id,bool $lock=false): ?array {
        $r=one($db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1'.($lock?' FOR UPDATE':''),[$org,self::key($kind,$id)]);
        if(!$r)return null;
        $s=json_decode((string)$r['config_json'],true,64,JSON_THROW_ON_ERROR);
        demand(is_array($s)&&($s['kind']??'')==='REIMBURSEMENT_FINAL_V353' &&
            ($s['source_kind']??'')===$kind && (int)($s['source_id']??0)===$id &&
            (int)($s['organization_id']??0)===$org,'Finalized reimbursement source record failed integrity verification',409);
        $s['record_id']=(int)$r['id'];return $s;
    }
    public static function digest(array $value): string {
        return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
    public static function fields(PDO $db,array $d,string $kind='T'): array {
        demand(($d['signed_approval_verified']??null)===true && ($d['no_previous_payment_verified']??null)===true,
            'Confirm the signed reimbursement authority and absence of prior supplier or employee payment.',422);
        $authority=trim((string)($d['payment_authority_reference']??''));
        demand(mbSafeLen($authority)>=8 && mbSafeLen($authority)<=180 &&
            !preg_match('/[\x00-\x1F\x7F]/',$authority),
            'Enter the official signed reimbursement approval reference (8–180 characters).',422);
        if($kind==='O'){
            demand(is_array($d['debit_items']??null)&&array_is_list($d['debit_items']) &&
                count($d['debit_items'])>0 && count($d['debit_items'])<=100,
                'Itemize each reimbursed PR/PO item and assign its debit UACS',422);
            return ['payment_authority_reference'=>$authority,'debit_items'=>$d['debit_items']];
        }
        demand(is_array($d['debit_splits']??null)&&array_is_list($d['debit_splits']) &&
            count($d['debit_splits'])>0 && count($d['debit_splits'])<=60,
            'Add reviewed UACS allocation lines for Travel, Per Diem and Others',422);
        return ['payment_authority_reference'=>$authority,'debit_splits'=>$d['debit_splits']];
    }
    public static function save(PDO $db,int $org,int $actor,string $kind,int $id,array $source,array $fields): array {
        demand($db->inTransaction(),'Reimbursement finalization must be atomic',500);
        demand(self::load($db,$org,$kind,$id,true)===null,
            'This reimbursement claim was already finalized. Open its permanent reference.',409);
        $now=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')));
        $data=['kind'=>'REIMBURSEMENT_FINAL_V353','source_kind'=>$kind,'source_id'=>$id,
            'organization_id'=>$org,'finalized_by'=>$actor,'finalized_at'=>$now->format(DateTimeInterface::ATOM),
            'payment_authority_reference'=>$fields['payment_authority_reference'],
            'debit_uacs_code'=>$fields['debit_uacs_code']??'',
            'debit_items'=>$fields['debit_items']??null,
            'debit_splits'=>$fields['debit_splits']??null,
            'item_source_digest'=>$fields['item_source_digest']??null]+$source;
        if($kind==='T'){unset($data['debit_items'],$data['item_source_digest']);}
        if($kind==='O')unset($data['debit_splits']);
        demand(moneyCents((string)($data['approved_amount']??'0.00'))>0,
            'Only an approved positive reimbursement may be finalized',409);
        q($db,'INSERT INTO configuration_versions (organization_id,config_key,config_json,effective_from,review_note) VALUES (?,?,?,?,?)',
            [$org,self::key($kind,$id),json_encode($data,JSON_THROW_ON_ERROR),$now->format('Y-m-d'),
                'Immutable approved reimbursement claim source; NOT a voucher or bank payment']);
        $data['record_id']=(int)$db->lastInsertId();
        q($db,'INSERT INTO audit_events (organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
            [$org,$actor,'EMPLOYEE_REIMBURSEMENT_FINALIZED',$kind==='T'?'RC':'EMPLOYEE_REIMBURSEMENT',$id,
                json_encode(['source_kind'=>$kind,'number'=>$data['reference'],'record_id'=>$data['record_id'],
                    'amount'=>$data['approved_amount'],'source_digest'=>$data['source_digest'],
                    'payment_authority_reference'=>$fields['payment_authority_reference'],
                    'debit_uacs_code'=>$fields['debit_uacs_code']??'',
                    'debit_items'=>$fields['debit_items']??[],
                    'debit_splits'=>$fields['debit_splits']??[],
                    'item_source_digest'=>$fields['item_source_digest']??'',
                    'no_dv_or_cash_posting'=>true,'no_check_or_bir_event'=>true],JSON_THROW_ON_ERROR)]);
        return $data;
    }
    public static function requireMatching(?array $final,array $source,string $label): array {
        demand($final!==null,$label.' has not been finalized in Reimbursement; complete it there first.',409);
        demand(isset($final['source_digest'])&&is_string($final['source_digest'])&&
            hash_equals($final['source_digest'],self::digest($source)),
            $label.' changed after finalization. Stop and request an audited source correction.',409);
        return $final;
    }
}
