<?php
declare(strict_types=1);
/**
 * v0.2.364: links one already-confirmed Division release to one verified,
 * school-owned bank account and its funding ledger. No second deposit is
 * created when the exact bank evidence was recorded earlier by finance.
 * The caller MUST hold a transaction and a FOR UPDATE school org lock.
 */
final class SchoolFundDownloadLink {
    private const STANDARD=['MOOE','SBFP','SNED','SIIF'];
    public static function connect(PDO $db,int $school,int $bankId,int $releaseId,int $actor,string $evidence): array {
        demand($school>0 && $releaseId>0 && $bankId>0 && $actor>0,'Select a valid school, release and bank',422);
        $schoolRow=one($db,"SELECT id,kind,parent_division_id,active FROM organizations WHERE id=? AND kind IN ('SCHOOL','DIVISION') AND active=1 FOR UPDATE",[$school]);
        demand($schoolRow!==null,'Use the receiving school or Division workspace',403);
        $releaseRow=one($db,"SELECT config_json FROM configuration_versions WHERE id=? AND organization_id=? AND LEFT(config_key,5)='MRV1_' FOR UPDATE",[$releaseId,$school]);
        demand($releaseRow!==null,'Division release does not belong to this school',404);
        $release=json_decode((string)$releaseRow['config_json'],true,32,JSON_THROW_ON_ERROR);
        demand(is_array($release) && (int)($release['school_id']??0)===$school &&
            (int)($release['division_id']??0)===($schoolRow['kind']==='DIVISION' ? $school : (int)$schoolRow['parent_division_id']),
            'Release organization evidence is inconsistent',409);
        $changes=q($db,"SELECT config_json FROM configuration_versions WHERE organization_id=? AND LEFT(config_key,5)='MVR1_' ORDER BY id DESC",[$school])->fetchAll();
        $revision=0;
        foreach($changes as $c){$v=json_decode((string)$c['config_json'],true,32,JSON_THROW_ON_ERROR);
            if((int)($v['release_id']??0)===$releaseId && (int)($v['revision']??0)>$revision){
                $revision=(int)$v['revision'];$release['amount']=$v['amount'];
            }
        }
        $key=$revision===0?'MCV1_'.$releaseId:'MVC1_'.$releaseId.'_'.$revision;
        $receiptRow=one($db,'SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key=? FOR UPDATE',[$school,$key]);
        demand($receiptRow!==null,'School must verify the latest Division release against its actual bank record first',409);
        $receipt=json_decode((string)$receiptRow['config_json'],true,32,JSON_THROW_ON_ERROR);
        demand(is_array($receipt) && (int)($receipt['release_id']??0)===$releaseId &&
            (int)($receipt['school_id']??0)===$school && (int)($receipt['revision']??-1)===$revision &&
            ($receipt['bank_verified']??false)===true,'Verified school receipt is not current',409);
        $amount=moneyCents((string)$receipt['amount']);
        demand($amount>0 && $amount===moneyCents((string)$release['amount']) &&
            (string)$receipt['date']>=(string)$release['date'],
            'The school credit differs from the Division release; resolve discrepancy before funding',409);
        $date=(string)$receipt['date'];
        $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        demand((bool)preg_match('/^20\d\d-\d\d-\d\d$/D',$date) &&
            DateTimeImmutable::createFromFormat('!Y-m-d',$date)?->format('Y-m-d')===$date && $date<=$today,
            'Only a nonfuture actual bank credit may fund a PR',409);
        $code=strtoupper(trim((string)($release['fund_type']??'')));
        if(!in_array($code,self::STANDARD,true)){
            $division=$schoolRow['kind']==='DIVISION'?$school:(int)$schoolRow['parent_division_id'];
            $cfg=one($db,"SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key='DIVISION_FUND_SOURCES' ORDER BY id DESC LIMIT 1",[$division]);
            $allowed=false;
            if($cfg){$catalog=json_decode((string)$cfg['config_json'],true,32,JSON_THROW_ON_ERROR);
                foreach((array)($catalog['sources']??[]) as $source){
                    if(($source['code']??'')===$code && !empty($source['active']))$allowed=true;
                }
            }
            demand($allowed,'This custom fund must be active in the Division source directory before it can be used',409);
        }
        $bank=one($db,'SELECT id FROM bank_accounts WHERE id=? AND organization_id=? FOR UPDATE',[$bankId,$school]);
        demand($bank!==null,'Select a bank account registered to this school',403);
        $reference=trim((string)$receipt['reference']);
        $evidence=trim($evidence)?:$reference;
        demand(strlen($evidence)>=3 && strlen($evidence)<=255 && !preg_match('/[\x00-\x1F\x7F]/',$evidence),
            'The verified bank reference is required',422);
        $advice=trim((string)$release['reference']);
        demand((bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._\/#:-]{2,119}$/D',$reference),
            'The bank reference must use the valid official credit-reference format (3–120 characters)',409);
        demand(strlen($advice)>=3 && strlen($advice)<=120,'The Division advice reference is invalid',409);
        // Bank account and school locks serialize confirmation and manual finance
        // posting; check the entire school for conflicting evidence, not the
        // truncated front-end list or only this source/year.
        $matches=q($db,"SELECT e.id,e.fund_id,e.amount,e.bank_account_id,e.bank_credit_date,
                e.bank_credit_ref,e.bank_advice_ref,e.reference_type,e.reference_id,
                f.source_code,f.fiscal_year
            FROM fund_events e JOIN funds f ON f.id=e.fund_id AND f.organization_id=e.organization_id
            WHERE e.organization_id=? AND e.event_type='ALLOTMENT' AND
                (e.bank_credit_ref=? OR e.bank_advice_ref=? OR
                 (e.reference_type='DIVISION_RELEASE' AND e.reference_id=?))
            FOR UPDATE",[$school,$reference,$advice,$releaseId])->fetchAll();
        if($matches){
            demand(count($matches)===1,'Multiple allotments use this bank evidence. Finance reconciliation required',409);
            $m=$matches[0];
            demand((int)$m['bank_account_id']===$bankId &&
                (string)$m['bank_credit_date']===$date &&
                strcasecmp((string)$m['bank_credit_ref'],$reference)===0 &&
                strcasecmp((string)$m['bank_advice_ref'],$advice)===0 &&
                moneyCents((string)$m['amount'])===$amount &&
                strtoupper((string)$m['source_code'])===$code &&
                (int)$m['fiscal_year']===(int)substr($date,0,4),
                'A bank-linked fund entry already uses this advice/credit with different evidence. Finance review required; no second credit created',409);
            return ['status'=>'LINKED','fund_id'=>(int)$m['fund_id'],'fund_event_id'=>(int)$m['id'],'existing'=>true];
        }
        // A legacy unlinked allotment may already belong to this release.
        // A separate evidence review is required rather than double-authorizing.
        $unlinked=one($db,"SELECT e.id FROM fund_events e JOIN funds f ON f.id=e.fund_id
            WHERE e.organization_id=? AND f.organization_id=? AND e.event_type='ALLOTMENT'
              AND e.bank_account_id IS NULL AND f.source_code=? AND f.fiscal_year=?
              AND e.amount=? LIMIT 1 FOR UPDATE",
            [$school,$school,$code,(int)substr($date,0,4),moneyString($amount)]);
        demand($unlinked===null,'A historical unlinked allotment of this amount exists. Ask finance to reconcile it, not record a second credit',409);
        $year=(int)substr($date,0,4);
        $fund=one($db,'SELECT id FROM funds WHERE organization_id=? AND source_code=? AND fiscal_year=? FOR UPDATE',[$school,$code,$year]);
        $created=false;
        if(!$fund){
            // The Division's signed/referenced release is the authority for this
            // standard fund source. No school-controlled arbitrary source code.
            q($db,'INSERT INTO funds (organization_id,fiscal_year,name,source_code) VALUES (?,?,?,?)',
                [$school,$year,(string)($release['fund_label']??$code),$code]);
            $fundId=(int)$db->lastInsertId();$created=true;
        }else{$fundId=(int)$fund['id'];}
        q($db,"INSERT INTO fund_events (organization_id,fund_id,event_type,amount,reference_type,reference_id,
               reason,created_by,bank_account_id,bank_credit_date,bank_credit_ref,bank_advice_ref,bank_evidence_ref)
              VALUES (?,?,'ALLOTMENT',?,'DIVISION_RELEASE',?,?,?,?,?,?,?,?)",
            [$school,$fundId,moneyString($amount),$releaseId,
                'Verified Division '.$code.' release '.$advice.' · '.$release['period'],
                $actor,$bankId,$date,$reference,$advice,$evidence]);
        $event=(int)$db->lastInsertId();
        q($db,'INSERT INTO audit_events (organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
            [$school,$actor,'SCHOOL_VERIFIED_DIVISION_CREDIT_LINKED','FUND_EVENT',$event,
                json_encode(['release_id'=>$releaseId,'revision'=>$revision,'fund_id'=>$fundId,'bank_account_id'=>$bankId,
                    'bank_date'=>$date,'bank_reference'=>$reference,'advice_reference'=>$advice,
                    'amount'=>moneyString($amount),'source_created'=>$created],JSON_THROW_ON_ERROR)]);
        return ['status'=>'LINKED','fund_id'=>$fundId,'fund_event_id'=>$event,'existing'=>false,'source_created'=>$created];
    }
}
