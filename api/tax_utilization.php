<?php
declare(strict_types=1);
// IPDAMS 0.2.344: Non-IU retained-withholding confirmation and utilization monitoring.
// This endpoint never posts budget, bank cash, checks, tax remittance or funds.
require_once dirname(__DIR__).'/private/app/Domain.php';
ini_set('display_errors','0'); ini_set('log_errors','1');
header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer'); header('Cache-Control: no-store');
$https=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');ini_set('session.cookie_secure',$https?'1':'0');
session_name('IPDAMSSESSID');session_start();
const TU_KEY='WTU1_';
function tuDate(): string {return (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');}
function tuText(mixed $value,string $label,int $limit=255): string {
    demand(is_string($value),$label.' is required');$value=trim($value);
    demand($value!=='' && strlen($value)<=$limit && !preg_match('/[\x00-\x1f\x7f]/u',$value),$label.' is required (max '.$limit.' characters)');
    return $value;
}
function tuSchoolClass(PDO $db,int $org,string $date): ?string {
    $records=q($db,"SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key='SCHOOL_IMPLEMENTATION_CLASS' AND effective_from<=? ORDER BY effective_from DESC,id DESC",[$org,$date])->fetchAll();
    $superseded=[];$versions=[];
    foreach($records as $r){
        $data=json_decode((string)$r['config_json'],true,32,JSON_THROW_ON_ERROR);
        demand(is_array($data) && in_array(($data['classification']??''),['NON_IUS','IUS'],true),'Invalid school class history',500);
        $old=(int)($data['supersedes_version_id']??0);if($old>0)$superseded[$old]=true;
        $versions[]=['id'=>(int)$r['id'],'class'=>$data['classification']];
    }
    foreach($versions as $v)if(!isset($superseded[$v['id']]))return $v['class'];
    return null;
}
/** Read-only utilization links. The config key is deterministic per source and target DV. */
function tuLinks(PDO $db,int $org): array {
    $raw=q($db,'SELECT id,config_key,config_json FROM configuration_versions WHERE organization_id=? AND LEFT(config_key,5)=? ORDER BY id',[$org,TU_KEY])->fetchAll();
    $seen=[];$out=[];
    foreach($raw as $r){
        $payload=json_decode((string)$r['config_json'],true,32,JSON_THROW_ON_ERROR);
        demand(is_array($payload) && (int)($payload['source_component_id']??0)>0 && (int)($payload['target_dv_id']??0)>0 && isset($payload['amount']),'Invalid tax utilization register entry',500);
        $expected=TU_KEY.(int)$payload['source_component_id'].'_'.(int)$payload['target_dv_id'];
        demand($r['config_key']===$expected && !isset($seen[$expected]),'Duplicate/conflicting tax utilization record: finance review required',409);
        $seen[$expected]=true;
        $out[]=$payload+['id'=>(int)$r['id']];
    }
    return $out;
}
function tuTotals(array $links,int $sourceId,?int $targetId=null): int {
    $sum=0;
    foreach($links as $link){
        if($targetId!==null && (int)$link['target_dv_id']!==$targetId)continue;
        if($targetId===null && (int)$link['source_component_id']!==$sourceId)continue;
        $sum+=moneyCents((string)$link['amount']);
    }
    return $sum;
}
function tuRead(PDO $db,array $actor,int $org): array {
    scope($db,$actor,$org);
    demand(in_array($actor['role'],['SCHOOL','DIVISION','SUPER_ADMIN'],true),'Fund tax monitoring is unavailable',403);
    $office=one($db,"SELECT id,kind,name FROM organizations WHERE id=? AND active=1",[$org]);
    demand($office && $office['kind']==='SCHOOL','Select the receiving school workspace',422);
    $class=tuSchoolClass($db,$org,tuDate());
    if($class!=='NON_IUS')return ['school_class'=>$class??'NOT_CONFIGURED','rows'=>[],'usage'=>[],
        'targets'=>[],'summary'=>['withheld'=>'0.00','pending'=>'0.00','confirmed'=>'0.00','utilized'=>'0.00','remaining'=>'0.00']];
    $components=q($db,"SELECT w.id component_id,w.dv_id,w.atc_code,w.amount,w.disposition,d.fund_id,
            d.display_number dv_number,d.document_date,d.status dv_status,
            f.name fund_name,f.source_code,f.fiscal_year,
            (SELECT e.state FROM tax_disposition_events e WHERE e.component_id=w.id ORDER BY e.id DESC LIMIT 1) completion_state,
            (SELECT e.evidence_reference FROM tax_disposition_events e WHERE e.component_id=w.id AND e.state='CONFIRMED_CREDIT' ORDER BY e.id DESC LIMIT 1) confirmation_evidence,
            (SELECT e.authority_reference FROM tax_disposition_events e WHERE e.component_id=w.id AND e.state='CONFIRMED_CREDIT' ORDER BY e.id DESC LIMIT 1) confirmation_authority,
            (SELECT DATE(e.created_at) FROM tax_disposition_events e WHERE e.component_id=w.id AND e.state='CONFIRMED_CREDIT' ORDER BY e.id DESC LIMIT 1) confirmation_date
        FROM withholding_components w
        JOIN disbursement_vouchers d ON d.id=w.dv_id AND d.organization_id=?
        JOIN funds f ON f.id=d.fund_id AND f.organization_id=d.organization_id
        WHERE d.status='FINALIZED' AND w.disposition='SCHOOL_UTILIZATION'
        ORDER BY w.id DESC LIMIT 600",[$org])->fetchAll();
    $links=tuLinks($db,$org);$known=[];$classByDate=[];$tot=['withheld'=>0,'pending'=>0,'confirmed'=>0,'utilized'=>0,'remaining'=>0];$rows=[];
    foreach($components as $row){
        $issued=substr((string)($row['document_date']??''),0,10);
        if(!array_key_exists($issued,$classByDate))$classByDate[$issued]=tuSchoolClass($db,$org,$issued);
        if($classByDate[$issued]!=='NON_IUS')continue;
        $sourceId=(int)$row['component_id'];$known[$sourceId]=true;$amount=moneyCents((string)$row['amount']);
        $used=tuTotals($links,$sourceId);$confirmed=$row['completion_state']==='CONFIRMED_CREDIT';
        demand($used>=0 && $used<=($confirmed?$amount:0),'Tax utilization exceeds a confirmed component; finance review required',409);
        $tot['withheld']+=$amount;
        if($confirmed){$tot['confirmed']+=$amount;$tot['utilized']+=$used;$tot['remaining']+=$amount-$used;}
        else $tot['pending']+=$amount;
        $rows[]=['component_id'=>$sourceId,'dv_id'=>(int)$row['dv_id'],'dv_number'=>$row['dv_number'],
            'document_date'=>$row['document_date'],'fund_id'=>(int)$row['fund_id'],
            'fund_name'=>$row['fund_name'],'fund_code'=>$row['source_code'],'fiscal_year'=>$row['fiscal_year'],
            'atc_code'=>$row['atc_code'],'amount'=>moneyString($amount),'state'=>$confirmed?'CONFIRMED_CREDIT':'PENDING',
            'confirmed_amount'=>moneyString($confirmed?$amount:0),'utilized_amount'=>moneyString($used),
            'remaining_amount'=>moneyString($confirmed?$amount-$used:0),
            'confirmation_evidence'=>$row['confirmation_evidence'],'confirmation_date'=>$row['confirmation_date'],
            'confirmation_authority'=>$row['confirmation_authority']];
    }
    // Never silently omit sources used in prior audited links when the result reaches its limit.
    demand(count($components)<600,'Tax register exceeds supported result window; finance administrator review required',409);
    foreach($links as $entry)demand(isset($known[(int)$entry['source_component_id']]),'Tax utilization source is no longer valid; finance review required',409);
    $targets=q($db,"SELECT d.id,d.display_number,d.fund_id,d.document_date,d.net
        FROM disbursement_vouchers d
        WHERE d.organization_id=? AND d.status='FINALIZED'
          AND EXISTS (SELECT 1 FROM checks c WHERE c.dv_id=d.id AND c.organization_id=d.organization_id AND c.status='CLEARED')
        ORDER BY d.id DESC LIMIT 350",[$org])->fetchAll();
    $targetOptions=[];
    foreach($targets as $v)$targetOptions[]=['dv_id'=>(int)$v['id'],'dv_number'=>$v['display_number'],
        'fund_id'=>(int)$v['fund_id'],'document_date'=>$v['document_date'],
        'net_amount'=>$v['net'],'remaining_limit'=>moneyString(max(0,moneyCents((string)$v['net'])-tuTotals($links,0,(int)$v['id'])))];
    $usage=[];
    foreach($links as $link)$usage[]=['source_component_id'=>(int)$link['source_component_id'],
        'target_dv_id'=>(int)$link['target_dv_id'],'amount'=>$link['amount'],
        'evidence_reference'=>$link['evidence_reference'],'authority_reference'=>$link['authority_reference'],
        'recorded_on'=>$link['recorded_on']??null];
    return ['school_class'=>$class,'rows'=>$rows,'usage'=>$usage,'targets'=>$targetOptions,
        'summary'=>array_map(static fn($v)=>moneyString($v),$tot),'limited_to_monitoring'=>true];
}
/** Audit-only matching of already-cleared expenditure; no new disbursement. */
function tuRecord(PDO $db,array $actor,array $d): array {
    allowedToWrite($actor);
    demand(in_array($actor['role'],['SCHOOL','SUPER_ADMIN'],true),'The owning school must reconcile tax utilization',403);
    $org=idOf($d,'organization_id');scope($db,$actor,$org);
    $source=idOf($d,'component_id');$target=idOf($d,'target_dv_id');
    $amount=moneyCents((string)($d['amount']??''));demand($amount>0,'Utilized amount must be positive');
    $evidence=tuText($d['evidence_reference']??null,'Bank clearance / supporting evidence');
    $authority=tuText($d['authority_reference']??null,'Division utilization authority');
    demand(($d['bank_verified']??false)===true && ($d['budget_verified']??false)===true,
        'Bank clearance and budget authority confirmations are required');
    $db->beginTransaction();
    try {
        $sourceRow=one($db,"SELECT w.id,w.dv_id,w.amount,w.disposition,d.organization_id,d.fund_id,d.document_date,d.status,
            (SELECT e.state FROM tax_disposition_events e WHERE e.component_id=w.id ORDER BY e.id DESC LIMIT 1) tax_state,
            (SELECT DATE(e.created_at) FROM tax_disposition_events e WHERE e.component_id=w.id AND e.state='CONFIRMED_CREDIT' ORDER BY e.id DESC LIMIT 1) confirmed_on
            FROM withholding_components w JOIN disbursement_vouchers d ON d.id=w.dv_id
            WHERE w.id=? AND d.organization_id=? FOR UPDATE",[$source,$org]);
        demand($sourceRow && $sourceRow['status']==='FINALIZED' && $sourceRow['disposition']==='SCHOOL_UTILIZATION' && $sourceRow['tax_state']==='CONFIRMED_CREDIT',
            'Only finalized and confirmed school-utilizable tax may be reconciled',409);
        $fund=one($db,'SELECT id FROM funds WHERE id=? AND organization_id=? FOR UPDATE',[$sourceRow['fund_id'],$org]);
        demand($fund!==null,'The original fund is no longer available',409);
        demand(tuSchoolClass($db,$org,tuDate())==='NON_IUS' &&
            tuSchoolClass($db,$org,(string)$sourceRow['document_date'])==='NON_IUS','Only Non-IUS school withholding can be utilized',403);
        $spent=one($db,"SELECT d.id,d.net,d.document_date,d.status,MAX(CASE WHEN c.status='CLEARED' THEN 1 ELSE 0 END) cleared
            FROM disbursement_vouchers d JOIN checks c ON c.dv_id=d.id AND c.organization_id=d.organization_id
            WHERE d.id=? AND d.organization_id=? AND d.fund_id=? GROUP BY d.id,d.net,d.document_date,d.status",
            [$target,$org,$sourceRow['fund_id']]);
        demand($spent && $spent['status']==='FINALIZED' && (int)$spent['cleared']===1,
            'Choose a finalized DV with a bank-cleared check from the same source fund',409);
        demand($target!==(int)$sourceRow['dv_id'] &&
            (string)$spent['document_date']>=(string)$sourceRow['document_date'] &&
            !empty($sourceRow['confirmed_on']) && (string)$spent['document_date']>(string)$sourceRow['confirmed_on'],
            'The utilization DV must be later than the withholding and its confirmation',409);
        $links=tuLinks($db,$org);
        $key=TU_KEY.$source.'_'.$target;
        foreach($links as $link)demand(!((int)$link['source_component_id']===$source && (int)$link['target_dv_id']===$target),
            'This tax and DV have already been reconciled; use another eligible DV',409);
        demand(tuTotals($links,$source)+$amount<=moneyCents((string)$sourceRow['amount']),
            'Amount exceeds remaining confirmed withholding',409);
        demand(tuTotals($links,0,$target)+$amount<=moneyCents((string)$spent['net']),
            'Amount exceeds remaining reconciliable net amount of the paid DV',409);
        $payload=['source_component_id'=>$source,'source_dv_id'=>(int)$sourceRow['dv_id'],
            'target_dv_id'=>$target,'fund_id'=>(int)$sourceRow['fund_id'],'amount'=>moneyString($amount),
            'evidence_reference'=>$evidence,'authority_reference'=>$authority,'recorded_by'=>(int)$actor['id'],
            'recorded_on'=>tuDate(),'monitoring_only'=>true];
        q($db,'INSERT INTO configuration_versions (organization_id,config_key,config_json,effective_from,review_note) VALUES (?,?,?,?,?)',
            [$org,$key,json_encode($payload,JSON_THROW_ON_ERROR),tuDate(),
            'Tax retained cash utilization matching to already cleared DV (monitoring only)']);
        $id=(int)$db->lastInsertId();
        q($db,'INSERT INTO audit_events (organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
            [$org,(int)$actor['id'],'RETAINED_TAX_UTILIZATION_RECONCILED','TAX',$source,
                json_encode(['register_id'=>$id,'target_dv_id'=>$target,'amount'=>$payload['amount'],'authority'=>$authority],JSON_THROW_ON_ERROR)]);
        $db->commit();return ['record_id'=>$id,'source_component_id'=>$source,'target_dv_id'=>$target,'amount'=>$payload['amount']];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
try {
    $db=database();$user=currentUser($db);$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
    if($method==='GET')jsonOut(tuRead($db,$user,idOf($_GET,'organization_id')));
    demand($method==='POST','Unsupported method',405);csrfCheck();
    $body=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
    demand(is_array($body),'Invalid utilization request');
    demand(($body['action']??'')==='link_cleared_dv','Unknown utilization action',404);
    jsonOut(tuRecord($db,$user,$body),201);
}catch(DomainError $e){http_response_code($e->status);header('Content-Type: application/json');echo json_encode(['error'=>$e->getMessage()]);}
catch(JsonException $e){http_response_code(400);header('Content-Type: application/json');echo json_encode(['error'=>'Invalid request JSON']);}
catch(PDOException $e){$incident=bin2hex(random_bytes(4));error_log('IPDAMS tax utilization failure '.$incident.' SQLSTATE='.$e->getCode());http_response_code(500);header('Content-Type: application/json');echo json_encode(['error'=>'Tax utilization database error; reference '.$incident]);}
catch(Throwable $e){error_log('IPDAMS tax utilization failure '.get_class($e));http_response_code(500);header('Content-Type: application/json');echo json_encode(['error'=>'Tax utilization request could not be completed']);}
