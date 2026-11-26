<?php
declare(strict_types=1);
/** Read-only Appendix 43 workpaper. All amounts are integer centavos.
 * No inference of per-download allocation, new liability, or bank movement. */
final class Appendix43Register {
    private const ADVANCE='1990101000';
    private const CASH=['1010404000'=>'Cash - MDS, Regular','1010202000'=>'Cash in Bank - Local Currency, Current Account'];
    private const BIR='2020101000';
    private static function cents($n):int { return moneyCents((string)$n); }
    private static function cashTitle(string $code,array $names):string {
        if(!preg_match('/^101(?:02|04)\d{5}$/D',$code))return '';
        $base=substr($code,0,8).'00';
        return $names[$code]??self::CASH[$base]??'';
    }
    public static function classification(array $versions):string {
        $superseded=[];$candidates=[];
        foreach($versions as $v){
            $c=json_decode((string)$v['config_json'],true,32,JSON_THROW_ON_ERROR);
            if(!is_array($c)||!in_array($c['classification']??'', ['IUS','NON_IUS'],true))return 'UNKNOWN';
            $prior=(int)($c['supersedes_version_id']??0);if($prior>0)$superseded[$prior]=true;
            $candidates[(int)$v['id']]=$c['classification'];
        }
        foreach($candidates as $id=>$c)if(!isset($superseded[$id]))return $c;
        return 'UNKNOWN';
    }
    /** School receiving/review officials, resolved only from the linked Division.
     * Read-only, dated metadata; names are not electronic signatures or approval. */
    private static function config(array|null $row):array {
        $value=json_decode((string)($row['config_json']??''),true,32);
        return is_array($value)?$value:[];
    }
    private static function person(array $row):array {
        $name=trim((string)($row['full_name']??$row['name']??''));
        $designation=trim((string)($row['designation']??''));
        if($designation==='')$designation=trim((string)($row['position_title']??''));
        return $name!==''&&$designation!==''?['name'=>$name,'designation'=>$designation]:[];
    }
    public static function divisionSignatories(array $assignment,array $staff,array $travel,array $officeHead,int $division):array {
        $out=['received'=>[],'noted'=>[],'sds'=>[]];
        if($division<=0)return $out;
        // The versioned school appointment is the authoritative historical snapshot.
        if((int)($assignment['division_id']??0)===$division && (int)($assignment['employee_id']??0)>0)
            $out['received']=self::person($assignment);
        $byId=[];
        foreach($staff as $row){
            $id=(int)($row['employee_id']??0);if($id<=0)continue;
            $byId[$id][]=$row;
        }
        // Use only the explicitly configured SGOD role effective on the report date.
        // Never infer the official from job-title text or substitute another Division.
        $sgodId=(int)($travel['roles']['sgod_chief']['employee_id']??0);
        if($sgodId>0&&count($byId[$sgodId]??[])===1){
            $chief=self::person($byId[$sgodId][0]);
            if($chief)$out['noted']=$chief;
        }
        // Prefer explicit SDS signatory configuration. A conflicting office head who
        // is also explicitly designated SDS requires clarification, not a guess.
        $isSds=static function(array $row):bool {
            $title=(string)($row['designation']??'').' '.(string)($row['position_title']??'');
            return !preg_match('/\b(?:Assistant|ASDS|Deputy)\b/i',$title)&&preg_match('/\b(?:SDS|Schools? Division Superintendent)\b/i',$title)===1;
        };
        $sdsId=(int)($travel['roles']['sds']['employee_id']??0);
        $headId=(int)($officeHead['employee_id']??0);
        $headIsSds=$headId>0&&count($byId[$headId]??[])===1&&$isSds($byId[$headId][0]);
        if($sdsId>0){
            if(count($byId[$sdsId]??[])===1 && (!$headIsSds||$headId===$sdsId) && $isSds($byId[$sdsId][0]))$out['sds']=self::person($byId[$sdsId][0]);
        }elseif($headIsSds)$out['sds']=self::person($byId[$headId][0]);
        return $out;
    }
    public static function metadata(PDO $db,array $org,string $end):array {
        $id=(int)$org['id'];$parent=(int)($org['parent_division_id']??0);
        $division=$org['kind']==='DIVISION'?$org['name']:($parent?((one($db,"SELECT name FROM organizations WHERE id=? AND kind='DIVISION'",[$parent])??[])['name']??''):'');
        $versions=$org['kind']==='SCHOOL'?q($db,"SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key='SCHOOL_IMPLEMENTATION_CLASS' AND effective_from<=? ORDER BY effective_from DESC,id DESC",[$id,$end])->fetchAll(PDO::FETCH_ASSOC):[];
        $class=$org['kind']==='DIVISION'?'DIVISION':self::classification($versions);
        // Office head is the school signatory in the supplied Non-IU reference.
        // Do not substitute a school head for an IU cashier/accountable officer.
        $head=[];
        if($class==='NON_IUS'){
            $appointment=one($db,"SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key='ORG_OFFICE_HEAD_V1' AND effective_from<=? ORDER BY effective_from DESC,id DESC LIMIT 1",[$id,$end]);
            $cfg=$appointment?json_decode((string)$appointment['config_json'],true,32,JSON_THROW_ON_ERROR):[];
            $employee=(int)($cfg['employee_id']??0);
            if($employee>0){
                $staff=q($db,'SELECT e.full_name,e.position_title,a.designation FROM employee_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.organization_id=? AND a.employee_id=? AND a.effective_from<=? AND (a.effective_to IS NULL OR a.effective_to>?) ORDER BY a.id DESC',[$id,$employee,$end,$end])->fetchAll(PDO::FETCH_ASSOC);
                if(count($staff)===1)$head=['name'=>$staff[0]['full_name'],'designation'=>$staff[0]['designation']?:$staff[0]['position_title']];
            }
        }
        $signatories=['received'=>[],'noted'=>[],'sds'=>[]];
        if($org['kind']==='SCHOOL'&&$parent>0&&$division!==''){
            $assignment=self::config(one($db,"SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key='SCHOOL_DIVISION_MOOE_POSTING_OFFICER_V1' AND effective_from<=? ORDER BY effective_from DESC,id DESC LIMIT 1",[$id,$end]));
            $staff=q($db,"SELECT a.employee_id,e.full_name,e.position_title,a.designation FROM employee_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.organization_id=? AND a.effective_from<=? AND (a.effective_to IS NULL OR a.effective_to>?) ORDER BY a.id DESC",[$parent,$end,$end])->fetchAll(PDO::FETCH_ASSOC);
            $travel=self::config(one($db,"SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key='travel_activity_request_signatories' AND effective_from<=? ORDER BY effective_from DESC,id DESC LIMIT 1",[$parent,$end]));
            $officeHead=self::config(one($db,"SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key='ORG_OFFICE_HEAD_V1' AND effective_from<=? ORDER BY effective_from DESC,id DESC LIMIT 1",[$parent,$end]));
            $signatories=self::divisionSignatories($assignment,$staff,$travel,$officeHead,$parent);
        }
        return ['classification'=>$class,'division'=>$division,'station'=>$org['name'],'address'=>$org['address']??'','head'=>$head,'divisionSignatories'=>$signatories,'signatoriesAsOf'=>$end];
    }
    public static function build(array $d,array $accounting,array $tax,array $meta):array {
        $notes=[];$byDv=[];$byTax=[];$names=[];$creditCodes=[];$groups=[];$seen=[];
        $class=$meta['classification']??'UNKNOWN';
        foreach($accounting as $a){$byDv[(int)$a['dv_id']][]=$a;$code=(string)$a['uacs_code'];if(trim((string)($a['account_name']??''))!=='')$names[$code]=(string)$a['account_name'];}
        foreach($tax as $w)$byTax[(int)$w['dv_id']][]=$w;
        foreach($d['dvRows'] as $row)$groups[(int)$row['check']['dv_id']][]=$row;
        // Retain original cash projection; classify only fully evidenced, unique DV settlements.
        $moves=[];$grossByCode=[];$frequency=[];$negativeRetained=0;$negativeRemit=0;$totalVat=0;$totalEwt=0;$unclassified=0;
        $history=$d['registerCheckHistory']??[];
        foreach($d['allPeriodDownloads'] as $e)$moves[]=['date'=>$e['bank_credit_date'],'sort'=>0,'id'=>(int)$e['id'],'particulars'=>($class==='NON_IUS'?'FUND DOWNLOADING':'FUND RECEIPT').(empty($e['bank_credit_ref'])?'':' - '.$e['bank_credit_ref']),'receipt'=>self::cents($e['amount']),'paid'=>0,'parts'=>[],'vat'=>0,'ewt'=>0,'due'=>0,'dv'=>'','check'=>'','documentDate'=>''];
        foreach($d['dvRows'] as $row){
            $c=$row['check'];$dv=(int)$c['dv_id'];$paid=(int)$row['amount_cents'];$ref=(string)($c['dv_number']??$dv);$parts=[];$vat=0;$ewt=0;$due=0;$retained=0;$remit=0;$reason=[];$debits=[];$credits=[];
            $related=$history[$dv]??[];
            // Multiple released checks (including prior/replaced instruments) cannot be
            // apportioned without an explicit DV-to-check allocation. Do not guess.
            if(count($groups[$dv])!==1 || (int)($c['register_check_count']??count($related))!==1 || !in_array($c['stage_at_end']??'',['RELEASED','CLEARED'],true) || !empty($c['replaces_check_id']))$reason[]='split, replaced or unresolved check settlement';
            if(isset($seen[$dv]))$reason[]='duplicate DV settlement';$seen[$dv]=true;
            $gross=self::cents($c['dv_gross']);$net=self::cents($c['dv_net']);$deductions=self::cents($c['dv_deductions']);
            if($paid!==$net||$gross-$deductions!==$net)$reason[]='gross/net/deduction mismatch';
            foreach($byDv[$dv]??[] as $a){
                $code=(string)$a['uacs_code'];$debit=self::cents($a['debit']);$credit=self::cents($a['credit']);
                if($debit){$debits[$code]=($debits[$code]??0)+$debit;}
                if($credit){$credits[$code]=($credits[$code]??0)+$credit;}
            }
            if(array_sum($debits)!==$gross||array_sum($credits)!==$gross||!$debits)$reason[]='saved accounting is missing or unbalanced';
            foreach($debits as $code=>$amount){
                $code=(string)$code;
                // A recorded Due to BIR debit is a liability settlement, not expenditure.
                // Other liabilities or cash/advance debits require separate accounting review.
                if(!preg_match('/^[15]\d{9}$/D',$code)&&$code!==self::BIR || str_starts_with($code,'101')||$code===self::ADVANCE)$reason[]='unsupported debit account '.$code;
                if(empty($names[$code]))$notes[]='UACS '.$code.' has no saved account title in the directory.';
            }
            foreach($byTax[$dv]??[] as $w){
                $amount=self::cents($w['amount']);$kind=strtoupper((string)$w['tax_kind']);
                if($kind==='VAT')$vat+=$amount;elseif($kind==='EWT')$ewt+=$amount;else $reason[]='unclassified tax component';
                if(($w['disposition']??'')==='SCHOOL_UTILIZATION'){
                    if($class!=='NON_IUS')$reason[]='school-utilization tax is not verified for this organization classification';
                    if(in_array($w['latest_state']??'',['REVERSED','REMITTED'],true))$reason[]='reversed or remitted school-utilization component';
                    else {$retained+=$amount;if(($w['latest_state']??'')!=='CONFIRMED_CREDIT')$notes[]='DV '.$ref.' retained withholding confirmation is pending.';}
                }elseif(($w['disposition']??'')==='REMIT'){if(($w['latest_state']??'')==='REVERSED')$reason[]='reversed remittance component';$remit+=$amount;}else $reason[]='unknown withholding disposition';
            }
            $due=$vat+$ewt;
            if($due!==$deductions||$retained+$remit!==$due||($due>0&&($credits[self::BIR]??0)!==$due))$reason[]='withholding components or saved Due to BIR credit do not reconcile';
            $cashCredits=array_diff_key($credits,[self::BIR=>true]);
            if(count($cashCredits)!==1||array_sum($cashCredits)!==$paid)$reason[]='net payment credit account not uniquely evidenced';
            $cashCode=count($cashCredits)===1?(string)array_key_first($cashCredits):'';
            if($cashCode!=='')$creditCodes[$cashCode]=true;
            if($class==='NON_IUS'&&$cashCode!==self::ADVANCE)$reason[]='Non-IU payment credit is not an operating advance';
            if(in_array($class,['IUS','DIVISION'],true)&&self::cashTitle($cashCode,$names)==='' )$reason[]='IU/Division cash credit is not verified';
            if($class==='UNKNOWN')$reason[]='school implementation classification is not verified';
            if($reason){$unclassified+=$paid;$notes[]='DV '.$ref.': '.implode('; ',array_unique($reason)).'. Amount is shown as cash only; classification withheld.';$vat=$ewt=$due=0;}
            else {
                $parts=$debits;$negativeRetained+=$retained;$negativeRemit+=$remit;$totalVat+=$vat;$totalEwt+=$ewt;
                foreach($parts as $code=>$amount){$grossByCode[$code]=($grossByCode[$code]??0)+$amount;$frequency[$code]=($frequency[$code]??0)+1;}
            }
            $moves[]=['date'=>$c['release_date'],'documentDate'=>$c['check_date']??'','sort'=>1,'id'=>(int)$c['id'],'particulars'=>(string)($c['payee_name']??$c['dv_payee']),'receipt'=>0,'paid'=>$paid,'parts'=>$parts,'vat'=>$vat,'ewt'=>$ewt,'due'=>$due,'dv'=>$ref,'check'=>$c['check_number'],'unclassified'=>(bool)$reason];
        }
        // Zero-cash cancellation rows are documentary only. A released cancellation
        // is already in the original cash projection and needs settlement review.
        $releasedIds=array_column($d['periodReleased'],'id');
        foreach($d['periodChecks'] as $c)if(($c['stage_at_end']??'')==='CANCELLED'&&!in_array($c['id'],$releasedIds)){
            $moves[]=['date'=>$c['issue_date'],'documentDate'=>$c['check_date']??'','sort'=>2,'id'=>(int)$c['id'],'particulars'=>'CANCELLED - '.($c['payee_name']??$c['dv_payee']),'receipt'=>0,'paid'=>0,'parts'=>[],'vat'=>0,'ewt'=>0,'due'=>0,'dv'=>$c['dv_number'],'check'=>$c['check_number']];
        }
        usort($moves,static fn($a,$b)=>strcmp($a['date'],$b['date'])?:($a['sort']<=>$b['sort'])?:($a['id']<=>$b['id']));
        // Most used = monthly gross amount, then frequency, then code. Liability
        // settlements remain in OTHERS instead of displacing expense/asset columns.
        $fixed=array_values(array_filter(array_keys($grossByCode),static fn($c)=>(string)$c!==self::BIR));
        usort($fixed,static fn($a,$b)=>($grossByCode[$b]<=>$grossByCode[$a])?:($frequency[$b]<=>$frequency[$a])?:strcmp((string)$a,(string)$b));
        $fixed=array_slice($fixed,0,4);$fixed=array_pad($fixed,4,'');
        $label='Cash account - classification pending';$cashCode='';$receiptLabel='Receipts';
        if($class==='NON_IUS'&&(($creditCodes===[]&&strtoupper((string)$d['fund']['source_code'])==='MOOE')||array_keys($creditCodes)===[(int)self::ADVANCE])){$label='Advances for Operating Expenses';$cashCode=self::ADVANCE;$receiptLabel='Cash Advance';}
        elseif(in_array($class,['IUS','DIVISION'],true)&&count($creditCodes)===1&&self::cashTitle((string)array_key_first($creditCodes),$names)!==''){$cashCode=(string)array_key_first($creditCodes);$label=self::cashTitle($cashCode,$names);$receiptLabel=str_starts_with($cashCode,'10104040')?'Cash Allocation':'Receipts';}
        else $notes[]='Cash account classification is unresolved or mixed; review the approved accounting records before certification.';
        $recap=array_sum($grossByCode)-$negativeRetained-$negativeRemit;$difference=$d['payment']-$recap;
        $running=$d['opening'];foreach($moves as &$move){$running+=$move['receipt']-$move['paid'];$move['balance']=$running;}unset($move);
        demand($running===$d['closing'],'Appendix 43 running cash projection does not reconcile',409);
        if($difference!==0||$unclassified!==0)$notes[]='Cash payment to classified net breakdown is not fully reconciled; no balancing entry was invented.';
        if(count($d['allPeriodDownloads'])>1)$notes[]='This is a monthly fund/bank register, not a separate liquidation per download. No DV allocation to an individual download is inferred.';
        if($class!=='NON_IUS')$notes[]='IU/Division variant is an internal cash monitoring register, not an official Appendix 43.';
        if(empty($meta['head']))$notes[]='Accountable officer / certification names require the appropriate approved appointment; blank signature fields remain for review.';
        if($class!=='DIVISION' && isset($meta['divisionSignatories']) && count(array_filter($meta['divisionSignatories']))!==3)$notes[]='One or more Division signatories are unconfigured or ambiguous at the report cutoff. Review the school MOOE assignment, SGOD Chief designation and SDS appointment.';
        return compact('class','meta','label','cashCode','receiptLabel','fixed','names','moves','grossByCode','negativeRetained','negativeRemit','totalVat','totalEwt','recap','difference','unclassified','notes')+['reconciled'=>$difference===0&&$unclassified===0,'certified'=>false];
    }
}
