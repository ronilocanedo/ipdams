<?php
declare(strict_types=1);
// IPDAMS 0.2.333 — school MOOE monitoring; no financial or bank events.
require_once dirname(__DIR__).'/private/app/Domain.php';
require_once dirname(__DIR__).'/private/app/SchoolFundDownloadLink.php';
ini_set('display_errors','0');ini_set('log_errors','1');
header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');
$https=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');ini_set('session.cookie_secure',$https?'1':'0');
session_name('IPDAMSSESSID');session_start();
const MR_PREFIX='MRV1_';const MC_PREFIX='MCV1_';const MI_PREFIX='MIV1_';
const MV_PREFIX='MVR1_';const MVC_PREFIX='MVC1_';
function mmDate(mixed $v,string $name): string {
    demand(is_string($v)&&preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$v)===1,$name.' must use YYYY-MM-DD');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('Asia/Manila'));
    demand($date!==false&&$date->format('Y-m-d')===$v,$name.' is invalid');
    demand($v<=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'),$name.' cannot be future');
    return $v;
}
function mmText(mixed $v,string $name,int $limit): string {
    demand(is_string($v),$name.' must be entered');$v=trim($v);
    demand($v!=='' && strlen($v)<=$limit && !preg_match('/[\x00-\x1f\x7f]/',$v),$name.' required; maximum '.$limit.' characters');
    return $v;
}

/** Monitoring categories are labels, never a fund/authority posting. */
function mmFund(array $d): array {
    $type=(string)($d['fund_type']??'MOOE');
    demand((bool)preg_match('/^[A-Z0-9_-]{1,60}$/D',$type),'Select a recognized Division fund source');
    $label=$type==='OTHER'?mmText($d['fund_label']??'OTHER','Other fund name',80):$type;
    return ['fund_type'=>$type,'fund_label'=>$label];
}
function mmReceiptKey(int $id,int $revision): string {
    return $revision===0?MC_PREFIX.$id:MVC_PREFIX.$id.'_'.$revision;
}

function mmSchoolClass(PDO $db,int $school,string $date): ?string {
    // The same versioned, effective-dated school IUS/Non-IUS classification as Fund Management.
    $records=q($db,"SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key='SCHOOL_IMPLEMENTATION_CLASS' AND effective_from<=? ORDER BY effective_from DESC,id DESC",[$school,$date])->fetchAll();
    $superseded=[];$candidates=[];
    foreach($records as $r){
        $j=json_decode((string)$r['config_json'],true,32,JSON_THROW_ON_ERROR);
        demand(is_array($j)&&in_array($j['classification']??null,['IUS','NON_IUS'],true),'Invalid school implementation record',500);
        $sup=(int)($j['supersedes_version_id']??0);if($sup>0)$superseded[$sup]=true;
        $candidates[]=['id'=>(int)$r['id'],'type'=>$j['classification']];
    }
    foreach($candidates as $c)if(!isset($superseded[$c['id']]))return $c['type'];
    return null;
}
function mmClass(PDO $db,int $school,string $date): bool {return mmSchoolClass($db,$school,$date)==='NON_IUS';}

function mmReleaseKey(int $division,int $school,string $reference,string $fundType='MOOE'): string {
    $material=$division.'|'.$school.'|'.strtoupper($reference);
    if($fundType!=='MOOE')$material.='|'.$fundType;
    return MR_PREFIX.substr(hash('sha256',$material),0,48);
}
function mmAudit(PDO $db,int $org,int $actor,string $event,int $id,array $facts): void {
    q($db,'INSERT INTO audit_events (organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
        [$org,$actor,$event,'MOOE_MONITOR',$id,json_encode($facts,JSON_THROW_ON_ERROR)]);
}
function mmList(PDO $db,array $actor,int $org): array {
    scope($db,$actor,$org);
    demand(in_array($actor['role'],['SCHOOL','DIVISION','SUPER_ADMIN'],true),'MOOE monitoring not authorized',403);
    $office=one($db,'SELECT id,kind FROM organizations WHERE id=? AND active=1',[$org]);demand($office!==null,'Office not found',404);
    $division=$office['kind']==='DIVISION';
    if($division)demand(in_array($actor['role'],['DIVISION','SUPER_ADMIN'],true),'Division officer required',403);
    else demand($office['kind']==='SCHOOL','Choose a school or Division office',403);
    $schools=[];
    if($division){
        $candidates=q($db,"SELECT id,name FROM organizations WHERE parent_division_id=? AND kind='SCHOOL' AND active=1 ORDER BY name LIMIT 300",[$org])->fetchAll();
        $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        // One scoped query for all school classifications (avoid a database request per school).
        $status=q($db,"SELECT c.id,c.organization_id,c.config_json FROM configuration_versions c
            JOIN organizations s ON s.id=c.organization_id
            WHERE s.parent_division_id=? AND s.kind='SCHOOL' AND c.config_key='SCHOOL_IMPLEMENTATION_CLASS'
              AND c.effective_from<=? ORDER BY c.effective_from DESC,c.id DESC",[$org,$today])->fetchAll();
        $bySchool=[];$superseded=[];
        foreach($status as $v){$schoolId=(int)$v['organization_id'];
            $decoded=json_decode((string)$v['config_json'],true);
            if(!is_array($decoded)||!isset($decoded['classification']))continue;
            $prior=(int)($decoded['supersedes_version_id']??0);if($prior>0)$superseded[$schoolId][$prior]=true;
            $bySchool[$schoolId][]=['id'=>(int)$v['id'],'type'=>(string)$decoded['classification']];}
        $classMap=[];
        foreach($bySchool as $schoolId=>$history)foreach($history as $v)
            if(!isset($superseded[$schoolId][$v['id']])){$classMap[$schoolId]=$v['type'];break;}
        foreach($candidates as $s)$schools[]=['id'=>(int)$s['id'],'name'=>(string)$s['name'],
            'classification'=>$classMap[(int)$s['id']]??'UNCONFIGURED'];
        $releases=q($db,"SELECT c.id,c.organization_id,c.config_json,s.name school_name FROM configuration_versions c JOIN organizations s ON s.id=c.organization_id WHERE (s.parent_division_id=? OR s.id=?) AND LEFT(c.config_key,5)=? ORDER BY c.id DESC LIMIT 250",[$org,$org,MR_PREFIX])->fetchAll();
    }else{
        $releases=q($db,"SELECT c.id,c.organization_id,c.config_json,s.name school_name FROM configuration_versions c JOIN organizations s ON s.id=c.organization_id WHERE c.organization_id=? AND LEFT(c.config_key,5)=? ORDER BY c.id DESC LIMIT 250",[$org,MR_PREFIX])->fetchAll();
    }
    // IU (IUS) schools record DIRECT national MOOE receipts; they do not appear
    // as recipients of a Division release. Division can monitor, never record on their behalf.
    if($division){
        $direct=q($db,"SELECT c.id,c.organization_id,c.config_json,s.name school_name
            FROM configuration_versions c JOIN organizations s ON s.id=c.organization_id
            WHERE s.parent_division_id=? AND s.kind='SCHOOL' AND LEFT(c.config_key,5)=?
            ORDER BY c.id DESC LIMIT 250",[$org,MI_PREFIX])->fetchAll();
    }else{
        $direct=q($db,"SELECT c.id,c.organization_id,c.config_json,s.name school_name
            FROM configuration_versions c JOIN organizations s ON s.id=c.organization_id
            WHERE c.organization_id=? AND LEFT(c.config_key,5)=?
            ORDER BY c.id DESC LIMIT 250",[$org,MI_PREFIX])->fetchAll();
    }
    // Append-only correction records always supersede only the displayed notice,
    // never the school's recorded bank evidence or original Division release.
    if($division){
        $changes=q($db,"SELECT c.config_json FROM configuration_versions c
            JOIN organizations s ON s.id=c.organization_id
            WHERE s.parent_division_id=? AND LEFT(c.config_key,5)=?
            ORDER BY c.id DESC",[$org,MV_PREFIX])->fetchAll();
    }else{
        $changes=q($db,"SELECT config_json FROM configuration_versions
            WHERE organization_id=? AND LEFT(config_key,5)=?
            ORDER BY id DESC",[$org,MV_PREFIX])->fetchAll();
    }
    $changeMap=[];
    $releaseIds=array_fill_keys(array_map(static fn($r)=>(int)$r['id'],$releases),true);
    foreach($changes as $r){
        $d=json_decode((string)$r['config_json'],true,16,JSON_THROW_ON_ERROR);
        $id=(int)($d['release_id']??0);$rev=(int)($d['revision']??0);
        if(!isset($releaseIds[$id])||$rev<1||$rev>50)continue;
        if(!isset($changeMap[$id])||$rev>(int)$changeMap[$id]['revision'])$changeMap[$id]=$d;
    }
    $keys=[];
    foreach($releases as $r){
        $id=(int)$r['id'];$rev=(int)($changeMap[$id]['revision']??0);
        for($v=0;$v<=$rev;$v++)$keys[]=mmReceiptKey($id,$v);
    }
    $receiptByKey=[];
    if($keys){
        $marks=implode(',',array_fill(0,count($keys),'?'));
        $receipts=q($db,"SELECT config_key,config_json,id,organization_id FROM configuration_versions
            WHERE config_key IN ($marks) ORDER BY id DESC",$keys)->fetchAll();
        foreach($receipts as $r){$k=(string)$r['config_key'];
            if(!isset($receiptByKey[$k]))$receiptByKey[$k]=['id'=>(int)$r['id'],
                'organization_id'=>(int)$r['organization_id']]+json_decode((string)$r['config_json'],true,16,JSON_THROW_ON_ERROR);
        }
    }
    $rows=[];
    foreach($releases as $r){
        $id=(int)$r['id'];$original=json_decode((string)$r['config_json'],true,16,JSON_THROW_ON_ERROR);
        demand(is_array($original)&&(int)($original['school_id']??0)===(int)$r['organization_id'],
            'Malformed release monitor record',500);
        $change=$changeMap[$id]??null;$rev=(int)($change['revision']??0);
        $d=$change?array_replace($original,['amount'=>$change['amount']]):$original;
        $a=$receiptByKey[mmReceiptKey($id,$rev)]??null;
        demand($a===null||(int)$a['organization_id']===(int)$r['organization_id'],
            'Malformed school confirmation record',500);
        $previous=null;
        for($v=$rev-1;$v>=0;$v--){$prior=$receiptByKey[mmReceiptKey($id,$v)]??null;
            if($prior){$previous=$prior;break;}}
        $status=$a===null?($previous?'RECONFIRMATION_REQUIRED':'PENDING'):
            (moneyCents($d['amount'])===moneyCents($a['amount']) && $a['date'] >= $d['date']?'RECEIVED':'DISCREPANCY');
        $fund=mmFund($d);
        $rows[]=['id'=>$id,'school_id'=>(int)$r['organization_id'],'school_name'=>$r['school_name'],
            'period'=>$d['period'],'amount'=>$d['amount'],'original_amount'=>$original['amount'],
            'date'=>$d['date'],'reference'=>$d['reference'],
            'fund_type'=>$fund['fund_type'],'fund_label'=>$fund['fund_label'],
            'source'=>'DIVISION','status'=>$status,'revision'=>$rev,
            'correction_reason'=>$change['reason']??null,'receipt'=>$a?['amount'=>$a['amount'],
                'date'=>$a['date'],'reference'=>$a['reference']]:null,
            'previous_receipt'=>$previous?['amount'=>$previous['amount'],
                'date'=>$previous['date'],'reference'=>$previous['reference']]:null];
    }
    foreach($direct as $r){
        $d=json_decode((string)$r['config_json'],true,32,JSON_THROW_ON_ERROR);
        demand(is_array($d)&&(int)($d['school_id']??0)===(int)$r['organization_id'] &&
            ($d['source']??null)==='NATIONAL','Malformed IU MOOE record',500);
        $fund=mmFund($d);
        $rows[]=['id'=>(int)$r['id'],'school_id'=>(int)$r['organization_id'],'school_name'=>$r['school_name'],
            'period'=>$d['period'],'amount'=>$d['amount'],'date'=>$d['date'],'reference'=>$d['reference'],
            'fund_type'=>$fund['fund_type'],'fund_label'=>$fund['fund_label'],
            'authority_type'=>$d['authority_type'],'source'=>'NATIONAL','status'=>'DIRECT_RECORDED','receipt'=>null];
    }
    usort($rows,static fn(array $a,array $b)=>(int)$b['id']<=>(int)$a['id']);
    // v0.2.364: read-only status of the recipient's own verified fund credit;
    // no credit is posted by a GET or by the Division reading child-school rows.
    // Reconcile only the receiving office's own downloads. Division can see
    // child-school notices but must never treat their bank events as its cash.
    {
        $refs=[];$advices=[];
        foreach($rows as $row){
            if((int)($row['school_id']??0)===$org && ($row['source']??'')==='DIVISION' &&
                ($row['status']??'')==='RECEIVED' && !empty($row['receipt']['reference'])){
                $refs[]=(string)$row['receipt']['reference'];
                $advices[]=(string)$row['reference'];
            }
        }
        $refs=array_values(array_unique($refs));$advices=array_values(array_unique($advices));
        $eventsByBank=[];$eventsByAdvice=[];
        if($refs){
            $bankMarks=implode(',',array_fill(0,count($refs),'?'));
            $adviceMarks=implode(',',array_fill(0,count($advices),'?'));
            // A finance officer may have entered an official bank reference that
            // differs from the monitor's descriptive school entry. Matching by
            // advice as well prevents a second allocation, but NEVER auto-links
            // conflicting evidence; it requires a manual investigation.
            $result=q($db,"SELECT e.id,e.bank_credit_ref,e.bank_credit_date,e.bank_account_id,e.bank_advice_ref,e.amount,
                e.fund_id,f.source_code,f.fiscal_year
                FROM fund_events e JOIN funds f ON f.id=e.fund_id AND f.organization_id=e.organization_id
                WHERE e.organization_id=? AND e.event_type='ALLOTMENT' AND
                   (e.bank_credit_ref IN ($bankMarks) OR e.bank_advice_ref IN ($adviceMarks))
                ORDER BY e.id DESC",array_merge([$org],$refs,$advices))->fetchAll();
            foreach($result as $e){
                $eventsByBank[strtoupper((string)$e['bank_credit_ref'])][(int)$e['id']]=$e;
                $eventsByAdvice[strtoupper((string)$e['bank_advice_ref'])][(int)$e['id']]=$e;
            }
        }
        foreach($rows as &$row){
            if((int)($row['school_id']??0)!==$org || ($row['source']??'')!=='DIVISION')continue;
            if(($row['status']??'')!=='RECEIVED'){
                $row['allocation']=['state'=>'NOT_READY'];continue;
            }
            $receipt=$row['receipt'];$matched=[];
            $candidates=array_values(($eventsByBank[strtoupper((string)$receipt['reference'])]??[]) +
                ($eventsByAdvice[strtoupper((string)$row['reference'])]??[]));
            foreach($candidates as $event){
                if($event['bank_account_id']!==null &&
                    strcasecmp((string)$event['bank_credit_ref'],(string)$receipt['reference'])===0 &&
                    (string)$event['bank_credit_date']===(string)$receipt['date'] &&
                    moneyCents($event['amount'])===moneyCents($receipt['amount']) &&
                    strcasecmp((string)$event['bank_advice_ref'],(string)$row['reference'])===0 &&
                    (string)$event['fiscal_year']===substr((string)$receipt['date'],0,4) &&
                    strtoupper((string)$event['source_code'])===strtoupper((string)$row['fund_type']))$matched[]=$event;
            }
            if(count($matched)===1 && count($candidates)===1){
                $row['allocation']=['state'=>'LINKED','fund_event_id'=>(int)$matched[0]['id'],
                    'fund_id'=>(int)$matched[0]['fund_id']];
            }elseif($candidates || count($matched)>1){
                $row['allocation']=['state'=>'REVIEW_EXISTING'];
            }else{$row['allocation']=['state'=>'NOT_LINKED'];}
        }
        unset($row);
    }
    $fundSources=[];
    if($division){
        $cfg=one($db,"SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key='DIVISION_FUND_SOURCES' ORDER BY id DESC LIMIT 1",[$org]);
        if($cfg){
            $j=json_decode((string)$cfg['config_json'],true,32,JSON_THROW_ON_ERROR);
            foreach((array)($j['sources']??[]) as $v){
                if(!empty($v['active']) && preg_match('/^[A-Z0-9_-]{1,60}$/D',(string)($v['code']??'')))
                    $fundSources[]=['code'=>(string)$v['code'],'name'=>(string)($v['name']??$v['code'])];
            }
        }
    }
    $schoolClass=$division?null:mmSchoolClass($db,$org,(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'));
    return ['mode'=>$division?'DIVISION':'SCHOOL','school_class'=>$schoolClass,'schools'=>$schools,'rows'=>$rows,'fund_sources'=>$fundSources,
        'can_release'=>$division && in_array($actor['role'],['DIVISION','SUPER_ADMIN'],true),
        'can_confirm'=>(!$division && $actor['role']==='SCHOOL') || ($division && $actor['role']==='DIVISION'),
        'can_direct'=>!$division && $actor['role']==='SCHOOL' && $schoolClass==='IUS'];
}
function mmRelease(PDO $db,array $actor,array $d): array {
    allowedToWrite($actor);
    demand(in_array($actor['role'],['DIVISION','SUPER_ADMIN'],true),'Only Division finance may record a release',403);
    $org=idOf($d,'organization_id');scope($db,$actor,$org);
    $school=idOf($d,'school_id');$date=mmDate($d['date']??null,'Release date');
    $period=mmText($d['period']??null,'Covered month',7);
    demand(preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D',$period)===1,'Covered month must be YYYY-MM');
    demand($period<=substr((new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'),0,7),'No future funding period');
    $ref=mmText($d['reference']??null,'Release advice reference',120);
    $fund=mmFund($d);
    $amount=moneyCents($d['amount']??'');demand($amount>0,'Enter a positive amount');
    $key=mmReleaseKey($org,$school,$ref,$fund['fund_type']);
    $db->beginTransaction();try{
        demand(one($db,"SELECT id FROM organizations WHERE id=? AND kind='DIVISION' AND active=1 FOR UPDATE",[$org])!==null,'Use the Division workspace',403);
        $self=$school===$org;
        $target=$self?one($db,"SELECT id FROM organizations WHERE id=? AND kind='DIVISION' AND active=1",[$org]):
            one($db,"SELECT id FROM organizations WHERE id=? AND parent_division_id=? AND kind='SCHOOL' AND active=1",[$school,$org]);
        demand($target!==null,'Choose this Division Office or an active school in the Division',403);
        // Eligibility for later DV use is enforced by the fund's effective policy.
        // Do not block the Division from notifying an IUS school of a release.
        demand(one($db,'SELECT id FROM configuration_versions WHERE organization_id=? AND config_key=? LIMIT 1',[$school,$key])===null,
            'A release with this reference is already recorded for this school',409);
        if(!in_array($fund['fund_type'],['MOOE','SIIF','SBFP','SNED'],true)){
            $cfg=one($db,"SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key='DIVISION_FUND_SOURCES' ORDER BY id DESC LIMIT 1",[$org]);
            $active=null;
            if($cfg){$j=json_decode((string)$cfg['config_json'],true,32,JSON_THROW_ON_ERROR);
                foreach((array)($j['sources']??[]) as $v)if(($v['code']??'')===$fund['fund_type'] && !empty($v['active']))$active=$v;
            }
            demand($active!==null,'Configure this fund as an active source under Division fund policies before releasing it',409);
            $fund['fund_label']=(string)$active['name'];
        }
        $record=['school_id'=>$school,'division_id'=>$org,'period'=>$period,'amount'=>moneyString($amount),
            'fund_type'=>$fund['fund_type'],'fund_label'=>$fund['fund_label'],'date'=>$date,'reference'=>$ref,'recorded_by'=>(int)$actor['id']];
        q($db,'INSERT INTO configuration_versions (organization_id,config_key,config_json,effective_from,review_note) VALUES (?,?,?,?,?)',
            [$school,$key,json_encode($record,JSON_THROW_ON_ERROR),$date,'Division release awaiting receiving-office confirmation']);
        $id=(int)$db->lastInsertId();mmAudit($db,$school,(int)$actor['id'],'SCHOOL_FUND_RELEASE_MONITORED',$id,
            ['division'=>$org,'period'=>$period,'amount'=>$record['amount'],'reference'=>$ref]);
        $db->commit();return ['id'=>$id,'status'=>'PENDING'];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function mmConfirm(PDO $db,array $actor,array $d): array {
    allowedToWrite($actor);
    demand(in_array($actor['role'],['SCHOOL','DIVISION'],true),'Receiving school or Division officer must confirm its own bank credit',403);
    $school=idOf($d,'organization_id');scope($db,$actor,$school);
    $id=idOf($d,'release_id');$date=mmDate($d['date']??null,'Bank credit date');
    $ref=mmText($d['reference']??null,'Bank reference',120);
    $amount=moneyCents($d['amount']??'');demand($amount>0,'Enter a positive bank credit amount');
    $bankId=idOf($d,'bank_account_id');
    // Existing bank credit reference is enough; do not demand a second proof.
    $evidence='';
    demand(($d['bank_verified']??null)===true,'Confirm you verified the actual bank credit against bank records');
    demand(isset($d['expected_revision'])&&is_int($d['expected_revision'])&&$d['expected_revision']>=0,
        'Refresh the release before confirming');
    $db->beginTransaction();try{
        $receivingKind=$actor['role']==='DIVISION'?'DIVISION':'SCHOOL';
        demand(one($db,"SELECT id FROM organizations WHERE id=? AND kind=? AND active=1 FOR UPDATE",[$school,$receivingKind])!==null,
            'Receiving office unavailable',403);
        $release=one($db,'SELECT config_json FROM configuration_versions WHERE id=? AND organization_id=? AND LEFT(config_key,5)=? FOR UPDATE',[$id,$school,MR_PREFIX]);
        demand($release!==null,'Release not found for this school',404);
        $source=json_decode((string)$release['config_json'],true,16,JSON_THROW_ON_ERROR);
        $changes=q($db,'SELECT config_json FROM configuration_versions WHERE organization_id=? AND LEFT(config_key,5)=? ORDER BY id DESC',[$school,MV_PREFIX])->fetchAll();
        $revision=0;
        foreach($changes as $c){$cv=json_decode((string)$c['config_json'],true,16,JSON_THROW_ON_ERROR);
            if((int)($cv['release_id']??0)===$id && (int)($cv['revision']??0)>$revision){
                $revision=(int)$cv['revision'];$source['amount']=$cv['amount'];
            }}
        demand($revision===$d['expected_revision'],'The Division changed this release. Refresh before confirming',409);
        $key=mmReceiptKey($id,$revision);
        demand(one($db,'SELECT id FROM configuration_versions WHERE organization_id=? AND config_key=? LIMIT 1',[$school,$key])===null,
            'This school receipt has already been confirmed',409);
        // One school bank reference must not be reused for a second receipt claim.
        foreach([MC_PREFIX,MVC_PREFIX] as $prefix){
            $others=q($db,'SELECT config_json FROM configuration_versions WHERE organization_id=? AND LEFT(config_key,5)=?',[$school,$prefix])->fetchAll();
            foreach($others as $x){$prior=json_decode((string)$x['config_json'],true);
                if((int)($prior['release_id']??0)!==$id)
                    demand(strcasecmp((string)($prior['reference']??''),$ref)!==0,
                        'This bank reference was already used for another download',409);
            }
        }
        $match=$date>=$source['date']&&$amount===moneyCents($source['amount']);
        $record=['release_id'=>$id,'revision'=>$revision,'bank_verified'=>true,'school_id'=>$school,'amount'=>moneyString($amount),
            'date'=>$date,'reference'=>$ref,'recorded_by'=>(int)$actor['id'],'matches_release'=>$match];
        q($db,'INSERT INTO configuration_versions (organization_id,config_key,config_json,effective_from,review_note) VALUES (?,?,?,?,?)',
            [$school,$key,json_encode($record,JSON_THROW_ON_ERROR),$date,'Receiving office bank receipt verified; linked to one fund credit in same transaction']);
        $receiptId=(int)$db->lastInsertId();mmAudit($db,$school,(int)$actor['id'],'SCHOOL_FUND_RECEIPT_MONITORED',$receiptId,
            ['release'=>$id,'amount'=>$record['amount'],'status'=>$match?'RECEIVED':'DISCREPANCY']);
        $fundType=strtoupper((string)($source['fund_type']??'MOOE'));
        $linked=$match
            ?SchoolFundDownloadLink::connect($db,$school,$bankId,$id,(int)$actor['id'],$evidence)
            :null;
        $db->commit();return ['id'=>$receiptId,'status'=>$match?'RECEIVED':'DISCREPANCY',
            'allocation'=>$linked,'monitoring_only'=>$match && $linked===null];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
/** An existing verified release can be linked ONCE to its real bank entry.
 * The same narrow transaction is used at confirmation time for new releases.
 * No generic school ALLOTMENT route is exposed. */
function mmLinkVerified(PDO $db,array $actor,array $d): array {
    allowedToWrite($actor);
    demand(in_array(($actor['role']??''),['SCHOOL','DIVISION'],true),'Only the receiving office may link its verified Division credit',403);
    $school=idOf($d,'organization_id');scope($db,$actor,$school);
    $release=idOf($d,'release_id');$bank=idOf($d,'bank_account_id');
    $evidence='';
    $db->beginTransaction();
    try {
        $linked=SchoolFundDownloadLink::connect($db,$school,$bank,$release,(int)$actor['id'],$evidence);
        $db->commit();return $linked;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
/** IU schools report their actual national/direct MOOE notice or receipt for monitoring.
 *  Not a simulated Division transfer or a bank/cash-book entry. */
function mmDirect(PDO $db,array $actor,array $d): array {
    allowedToWrite($actor);
    demand($actor['role']==='SCHOOL','Only the owning IU school may record its direct MOOE',403);
    $school=idOf($d,'organization_id');scope($db,$actor,$school);
    $date=mmDate($d['date']??null,'National credit/evidence date');
    $period=mmText($d['period']??null,'Covered month',7);
    demand(preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D',$period)===1,'Covered month must use YYYY-MM');
    demand($period<=substr($date,0,7),'Funding month cannot be after recorded evidence date');
    $reference=mmText($d['reference']??null,'National document reference',120);
    $kind=mmText($d['authority_type']??null,'National document type',20);
    demand(in_array($kind,['NCA','MDS','OTHER'],true),'Select NCA, MDS or Other national advice');
    $fund=mmFund($d);
    $amount=moneyCents($d['amount']??'');demand($amount>0,'Enter a positive downloaded amount');
    $material=$school.'|'.strtoupper($reference).($fund['fund_type']==='MOOE'?'':'|'.$fund['fund_type']);
    $key=MI_PREFIX.substr(hash('sha256',$material),0,48);
    $db->beginTransaction();try{
        demand(one($db,"SELECT id FROM organizations WHERE id=? AND kind='SCHOOL' AND active=1 FOR UPDATE",[$school])!==null,
            'The school workspace is unavailable',403);
        demand(mmSchoolClass($db,$school,$date)==='IUS','Direct national MOOE may be recorded only by an IU (IUS) school',422);
        demand(one($db,'SELECT id FROM configuration_versions WHERE organization_id=? AND config_key=? LIMIT 1',[$school,$key])===null,
            'This national funding reference is already recorded for this school',409);
        $record=['source'=>'NATIONAL','school_id'=>$school,'period'=>$period,'amount'=>moneyString($amount),
            'fund_type'=>$fund['fund_type'],'fund_label'=>$fund['fund_label'],
            'date'=>$date,'reference'=>$reference,'authority_type'=>$kind,'recorded_by'=>(int)$actor['id']];
        q($db,'INSERT INTO configuration_versions (organization_id,config_key,config_json,effective_from,review_note) VALUES (?,?,?,?,?)',
            [$school,$key,json_encode($record,JSON_THROW_ON_ERROR),$date,
            'Direct national IU fund notice — monitoring only; NOT a bank posting']);
        $id=(int)$db->lastInsertId();
        mmAudit($db,$school,(int)$actor['id'],'IU_FUND_NATIONAL_RECORDED',$id,
            ['period'=>$period,'amount'=>$record['amount'],'reference'=>$reference,'document_type'=>$kind]);
        $db->commit();return ['id'=>$id,'status'=>'DIRECT_RECORDED'];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
/** Division correction is a new notice version, never an UPDATE to the original. */
function mmCorrect(PDO $db,array $actor,array $d): array {
    allowedToWrite($actor);
    demand(in_array($actor['role'],['DIVISION','SUPER_ADMIN'],true),'Division finance required',403);
    $org=idOf($d,'organization_id');scope($db,$actor,$org);
    $id=idOf($d,'release_id');$amount=moneyCents($d['amount']??'');
    demand($amount>0,'Corrected amount must be positive');
    $reason=mmText($d['reason']??null,'Correction reason',300);
    demand(strlen($reason)>=12,'Describe why the correction was required (at least 12 characters)');
    demand(isset($d['expected_revision'])&&is_int($d['expected_revision'])&&$d['expected_revision']>=0,
        'Refresh release before correcting');
    $db->beginTransaction();try{
        $office=one($db,"SELECT id FROM organizations WHERE id=? AND kind='DIVISION' AND active=1 FOR UPDATE",[$org]);
        demand($office!==null,'Division workspace required',403);
        $row=one($db,"SELECT c.id,c.organization_id,c.config_json FROM configuration_versions c
            JOIN organizations s ON s.id=c.organization_id
            WHERE c.id=? AND s.parent_division_id=? AND LEFT(c.config_key,5)=? FOR UPDATE",
            [$id,$org,MR_PREFIX]);
        demand($row!==null,'Division release not found',404);
        $initial=json_decode((string)$row['config_json'],true,16,JSON_THROW_ON_ERROR);
        $school=(int)$row['organization_id'];$prevAmount=moneyCents($initial['amount']);$revision=0;
        $history=q($db,'SELECT config_json FROM configuration_versions WHERE organization_id=? AND LEFT(config_key,5)=? ORDER BY id DESC',
            [$school,MV_PREFIX])->fetchAll();
        foreach($history as $h){$v=json_decode((string)$h['config_json'],true,16,JSON_THROW_ON_ERROR);
            if((int)($v['release_id']??0)===$id && (int)($v['revision']??0)>$revision){
                $revision=(int)$v['revision'];$prevAmount=moneyCents($v['amount']);
            }}
        demand($revision===$d['expected_revision'],'Another correction was made. Refresh and review',409);
        demand($revision<50,'Maximum corrections reached; contact system administrator',409);
        demand($amount!==$prevAmount,'Enter an amount different from the current release');
        $next=$revision+1;$date=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $entry=['release_id'=>$id,'school_id'=>$school,'revision'=>$next,
            'old_amount'=>moneyString($prevAmount),'amount'=>moneyString($amount),
            'date'=>$date,'reason'=>$reason,'recorded_by'=>(int)$actor['id']];
        q($db,'INSERT INTO configuration_versions (organization_id,config_key,config_json,effective_from,review_note) VALUES (?,?,?,?,?)',
            [$school,MV_PREFIX.$id.'_'.$next,json_encode($entry,JSON_THROW_ON_ERROR),$date,
            'Division correction notice — requires school re-verification; no bank/fund posting']);
        $newId=(int)$db->lastInsertId();
        mmAudit($db,$school,(int)$actor['id'],'FUND_RELEASE_CORRECTION_MONITORED',$newId,
            ['release_id'=>$id,'revision'=>$next,'old_amount'=>$entry['old_amount'],
             'amount'=>$entry['amount'],'reason'=>$reason]);
        $db->commit();return ['id'=>$newId,'release_id'=>$id,'revision'=>$next,'status'=>'RECONFIRMATION_REQUIRED'];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
try{
    $db=database();$user=currentUser($db);$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
    if($method==='GET')jsonOut(mmList($db,$user,idOf($_GET,'organization_id')));
    demand($method==='POST','Unsupported method',405);csrfCheck();
    $body=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
    demand(is_array($body),'Invalid form');
    if(($body['action']??'')==='release')jsonOut(mmRelease($db,$user,$body),201);
    if(($body['action']??'')==='confirm')jsonOut(mmConfirm($db,$user,$body),201);
    if(($body['action']??'')==='link_verified')jsonOut(mmLinkVerified($db,$user,$body),200);
    if(($body['action']??'')==='direct')jsonOut(mmDirect($db,$user,$body),201);
    if(($body['action']??'')==='correct')jsonOut(mmCorrect($db,$user,$body),201);
    demand(false,'Unknown monitoring action',404);
}catch(DomainError $e){http_response_code($e->status);header('Content-Type: application/json');echo json_encode(['error'=>$e->getMessage()]);}
catch(JsonException $e){http_response_code(400);header('Content-Type: application/json');echo json_encode(['error'=>'Invalid request JSON']);}
catch(PDOException $e){$incident=bin2hex(random_bytes(4));error_log('IPDAMS MOOE monitoring failure '.$incident.' SQLSTATE='.$e->getCode());http_response_code(500);header('Content-Type: application/json');echo json_encode(['error'=>'MOOE monitoring database error; reference '.$incident]);}
catch(Throwable $e){error_log('IPDAMS MOOE monitoring failed '.get_class($e));http_response_code(500);header('Content-Type: application/json');echo json_encode(['error'=>'MOOE monitoring could not complete']);}
