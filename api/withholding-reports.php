<?php
declare(strict_types=1);
/** IPDAMS v0.2.400 - read-only withholding schedule. No financial writes. */
require_once dirname(__DIR__).'/private/app/Domain.php';
ini_set('display_errors','0');ini_set('log_errors','1');
$https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');ini_set('session.cookie_secure',$https?'1':'0');
session_name('IPDAMSSESSID');session_start();
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
$reportEmbedded=(($_GET['embedded']??'')==='1');
header('X-Frame-Options: '.($reportEmbedded?'SAMEORIGIN':'DENY'));
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; object-src 'none'; base-uri 'none'; frame-ancestors ".($reportEmbedded?"'self'":"'none'"));header('Referrer-Policy: no-referrer');
function wrEscape(mixed $s): string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function wrDate(string $s,string $name): string {
    demand((bool)preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$s),"Invalid $name",422);
    $v=DateTimeImmutable::createFromFormat('!Y-m-d',$s,new DateTimeZone('Asia/Manila'));
    demand($v!==false&&$v->format('Y-m-d')===$s,"Invalid $name",422);return $s;
}
function wrCents(string $value): int {
    demand((bool)preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D',$value),'Invalid saved tax amount',409);
    [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');
    return (int)$whole*100+(int)str_pad($fraction,2,'0');
}
function wrMoney(int $c): string{return number_format($c/100,2,'.',',');}
function wrCsvCell(mixed $v): string{
    $s=(string)$v;if(preg_match('/^\s*[=+@\-\t\r]/u',$s))$s="'".$s;
    return $s;
}
function wrRemittanceRecorded(array $r):bool {
    return ($r['disposition']??'')==='REMIT' && ($r['tax_state']??'')==='REMITTED' &&
        is_numeric($r['tax_state_amount']??null) && wrCents((string)$r['tax_state_amount'])===wrCents((string)$r['amount']) &&
        (trim((string)($r['remittance_reference']??''))!=='' || (in_array($r['transfer_status']??'', ['DO_ACCOUNTING_PENDING','RECONCILED'],true) && trim((string)($r['division_receipt']??''))!==''));
}
function wrTaxStatus(array $r):string {
    if($r['disposition']==='SCHOOL_UTILIZATION')return match($r['tax_state']){
        'CONFIRMED_CREDIT'=>'Retention confirmed','PENDING'=>'Retention pending confirmation',default=>'Source review required'};
    if(wrRemittanceRecorded($r))return in_array($r['transfer_status']??'', ['DO_ACCOUNTING_PENDING','RECONCILED'],true)&&trim((string)($r['division_receipt']??''))!==''?'Division receipt recorded (not BIR settlement)':'Remittance recorded — see evidence';
    if($r['disposition']!=='REMIT'||$r['tax_state']!=='PENDING')return 'Source review required';
    return match($r['transfer_status']??''){
        'DRAFT'=>'Transfer draft','CHECK_ASSIGNED'=>'Check assigned — not released','CHECK_RELEASED'=>'Check released — awaiting Division receipt',
        'DO_ACCOUNTING_PENDING','RECONCILED'=>'Review receipt / tax completion evidence',default=>'Awaiting remittance'};
}
function wrRead(PDO $db,array $user,array $request): array {
    demand(in_array($user['role']??'', ['SCHOOL','DIVISION','SUPER_ADMIN'],true),'Finance reporting not authorized',403);
    $org=idOf($request,'organization_id');scope($db,$user,$org);
    $office=one($db,'SELECT id,kind,name,active,parent_division_id FROM organizations WHERE id=?',[$org]);
    demand($office && (int)$office['active']===1,'Office not active',404);
    if($user['role']==='SCHOOL')demand($office['kind']==='SCHOOL','Select your school',403);
    if($user['role']==='DIVISION')demand($office['kind']==='DIVISION','Select your Division',403);
    $from=wrDate((string)($request['from']??date('Y-m-01')),'from date');
    $through=wrDate((string)($request['through']??date('Y-m-t')),'through date');
    demand($from<=$through,'Beginning date must not exceed ending date',422);
    $fund=trim((string)($request['fund']??''));
    demand($fund===''||(bool)preg_match('/^[A-Za-z0-9_-]{1,40}$/D',$fund),'Invalid fund source code',422);
    $atc=trim((string)($request['atc']??''));
    demand($atc===''||(bool)preg_match('/^[A-Za-z0-9_-]{1,25}$/D',$atc),'Invalid ATC',422);
    $disposition=(string)($request['disposition']??'');
    demand(in_array($disposition,['','SCHOOL_UTILIZATION','REMIT'],true),'Invalid withholding treatment',422);
    $completion=(string)($request['completion']??'');
    demand(in_array($completion,['','recorded','open'],true),'Invalid remittance completion filter',422);
    if($completion!=='')$disposition='REMIT';
    $fundRaw=(string)($request['fund_id']??'0');
    demand((bool)preg_match('/^(?:0|[1-9][0-9]{0,12})$/D',$fundRaw),'Invalid fund record',422);
    $fundId=(int)$fundRaw;$fundChoices=[];
    if($office['kind']==='SCHOOL'){
        $fundChoices=q($db,'SELECT id,name,source_code,fiscal_year FROM funds WHERE organization_id=? ORDER BY fiscal_year DESC,name,id LIMIT 1001',[$org])->fetchAll();
        demand(count($fundChoices)<=1000,'Too many fund records; narrow through the Division report',409);
        if($fundId>0)demand(in_array($fundId,array_map(static fn($f)=>(int)$f['id'],$fundChoices),true),'Fund is not in the selected school',403);
    }else demand($fundId===0,'Use the fund source code filter in the Division report',422);
    $schoolRaw=(string)($request['school_id']??'0');
    demand((bool)preg_match('/^(?:0|[1-9][0-9]{0,12})$/D',$schoolRaw),'Invalid school filter',422);
    $schoolId=(int)$schoolRaw;
    if($office['kind']==='SCHOOL')demand($schoolId===0||$schoolId===$org,'School filter is outside your office',403);
    $schools=$office['kind']==='DIVISION'
      ?q($db,"SELECT id,name FROM organizations WHERE kind='SCHOOL' AND active=1 AND parent_division_id=? ORDER BY name,id",[$org])->fetchAll()
      :[['id'=>$org,'name'=>$office['name']]];
    $allowed=array_fill_keys(array_map(static fn($r)=>(int)$r['id'],$schools),true);
    if($schoolId>0)demand(isset($allowed[$schoolId]),'School is not attached to the selected Division',403);
    $ids=$schoolId?[$schoolId]:array_keys($allowed);
    if(!$ids){$rows=$sum=[];return compact('rows','sum','schools','office','from','through','fund','atc','schoolId','fundId','fundChoices','disposition','completion');}
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $sql="SELECT w.id component_id,w.atc_code,w.amount,w.disposition,
        d.id dv_id,d.display_number dv_number,d.document_date,d.gross dv_gross,d.payee,
        o.id school_id,o.name school_name,f.source_code fund_code,f.name fund_name,f.fiscal_year,
        (SELECT e.state FROM tax_disposition_events e WHERE e.component_id=w.id ORDER BY e.id DESC LIMIT 1) tax_state,
        (SELECT e.amount FROM tax_disposition_events e WHERE e.component_id=w.id ORDER BY e.id DESC LIMIT 1) tax_state_amount,
        (SELECT e.evidence_reference FROM tax_disposition_events e WHERE e.component_id=w.id AND e.state='REMITTED' ORDER BY e.id DESC LIMIT 1) remittance_reference,
        (SELECT e.evidence_reference FROM tax_disposition_events e WHERE e.component_id=w.id AND e.state='CONFIRMED_CREDIT' ORDER BY e.id DESC LIMIT 1) confirmation_reference,
        (SELECT t.status FROM school_tax_transfer_components tc JOIN school_tax_transfers t ON t.id=tc.transfer_id WHERE tc.component_id=w.id AND t.status<>'CANCELLED' ORDER BY t.id DESC LIMIT 1) transfer_status,
        (SELECT t.receipt_reference FROM school_tax_transfer_components tc JOIN school_tax_transfers t ON t.id=tc.transfer_id WHERE tc.component_id=w.id AND t.status<>'CANCELLED' ORDER BY t.id DESC LIMIT 1) division_receipt,
        (SELECT t.jev_reference FROM school_tax_transfer_components tc JOIN school_tax_transfers t ON t.id=tc.transfer_id WHERE tc.component_id=w.id AND t.status<>'CANCELLED' ORDER BY t.id DESC LIMIT 1) jev_reference
        FROM withholding_components w JOIN disbursement_vouchers d ON d.id=w.dv_id
        JOIN organizations o ON o.id=d.organization_id
        JOIN funds f ON f.id=d.fund_id AND f.organization_id=d.organization_id
        WHERE d.organization_id IN ($marks) AND d.status='FINALIZED' AND d.document_date BETWEEN ? AND ?";
    $args=[...$ids,$from,$through];
    if($fund!==''){$sql.=' AND f.source_code=?';$args[]=$fund;}
    if($atc!==''){$sql.=' AND w.atc_code=?';$args[]=$atc;}
    if($fundId>0){$sql.=' AND f.id=?';$args[]=$fundId;}
    if($disposition!==''){$sql.=' AND w.disposition=?';$args[]=$disposition;}
    $sql.=' ORDER BY o.name,d.document_date,d.id,w.id LIMIT 10001';
    $rows=q($db,$sql,$args)->fetchAll();
    demand(count($rows)<10001,'Withholding report exceeds 10,000 records. Narrow the school, fund or period filter; no rows have been omitted.',409);
    // Utilization links are stored as versioned monitoring entries. Load only
    // the selected schools and validate every matching source amount below.
    $versions=$disposition==='REMIT'?[]:q($db,"SELECT organization_id,config_key,config_json FROM configuration_versions
        WHERE organization_id IN ($marks) AND LEFT(config_key,5)='WTU1_' ORDER BY id",$ids)->fetchAll();
    $usage=[];$seen=[];
    foreach($versions as $v){
        $entry=json_decode((string)$v['config_json'],true,32,JSON_THROW_ON_ERROR);
        demand(is_array($entry)&&isset($entry['source_component_id'],$entry['amount'],$entry['target_dv_id']),'Invalid saved tax utilization record',409);
        $cid=(int)$entry['source_component_id'];$target=(int)$entry['target_dv_id'];
        $expected='WTU1_'.$cid.'_'.$target;
        demand($cid>0&&$target>0&&(string)$v['config_key']===$expected,'Conflicting utilization register entry',409);
        $key=(int)$v['organization_id'].'|'.$expected;
        demand(!isset($seen[$key]),'Duplicate utilization record',409);$seen[$key]=true;
        $usage[(int)$v['organization_id']][$cid]=($usage[(int)$v['organization_id']][$cid]??0)+wrCents((string)$entry['amount']);
    }
    if($completion!=='')$rows=array_values(array_filter($rows,static fn($r)=>wrRemittanceRecorded($r)===($completion==='recorded')));
    $sum=['withheld'=>0,'remit'=>0,'retained'=>0,'confirmed'=>0,'applied'=>0,'remaining'=>0,'remitted'=>0,'remit_open'=>0];
    foreach($rows as &$r){
        $amount=wrCents((string)$r['amount']);$used=$usage[(int)$r['school_id']][(int)$r['component_id']]??0;
        $isSchool=$r['disposition']==='SCHOOL_UTILIZATION';$confirmed=$r['tax_state']==='CONFIRMED_CREDIT';
        demand($used>=0&&$used<=($confirmed?$amount:0),'Saved utilization exceeds confirmed withholding; accounting review required',409);
        $r['applied_amount']=wrMoney($used);$r['remaining_amount']=wrMoney($confirmed?$amount-$used:0);
        $r['report_status']=wrTaxStatus($r);
        $sum['withheld']+=$amount;
        if($isSchool){$sum['retained']+=$amount;if($confirmed){$sum['confirmed']+=$amount;$sum['applied']+=$used;$sum['remaining']+=$amount-$used;}}
        elseif($r['disposition']==='REMIT'){$sum['remit']+=$amount;if(wrRemittanceRecorded($r))$sum['remitted']+=$amount;else $sum['remit_open']+=$amount;}
    }unset($r);
    return compact('rows','schools','sum','office','from','through','fund','atc','schoolId','fundId','fundChoices','disposition','completion');
}
function wrRender(array $d,int $org): never {
    $office=$d['office'];$rows=$d['rows'];$sum=$d['sum'];$funds=[];$atcs=[];
    foreach($rows as $r){$funds[$r['fund_code']]=true;$atcs[$r['atc_code']]=true;}
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>IPDAMS · Withholding Tax Report</title><script src="../assets/cash-fund-reports.js" defer></script>';
    echo '<style>body{font:15px system-ui,sans-serif;background:#f4f8fa;color:#19344a;margin:0}main{max-width:1400px;margin:24px auto;background:white;padding:24px;border-radius:12px}h1{margin:0 0 6px}.muted{color:#537085}form{display:flex;flex-wrap:wrap;gap:12px;align-items:end;padding:18px 0}label{display:grid;gap:5px;font-weight:600}input,select,button,a.export{padding:9px;border:1px solid #cddde5;border-radius:6px;font:inherit}button,a.export{background:#128d7f;color:white;text-decoration:none;cursor:pointer}table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:9px;border-bottom:1px solid #dde7ed;text-align:left;vertical-align:top}th{background:#eef5f7}td.money{text-align:right;white-space:nowrap}.kpis{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0}.kpis>div{background:#eef7f6;padding:14px;min-width:130px;border-radius:8px}.kpis strong{display:block}section.scroll{overflow:auto}main{box-sizing:border-box;width:100%;min-width:0}label{min-width:0;max-width:100%}input,select{box-sizing:border-box;max-width:100%}@media(max-width:640px){main{margin:0;padding:12px}form label{flex:1 1 130px}form input,form select{width:100%}.kpis>div{min-width:0;flex:1 1 120px}}small{color:#587084}@media print{body{background:white}main{margin:0;padding:0}form,.export{display:none!important}table{font-size:10px}tr{break-inside:avoid}}</style></head><body><main>';
    echo '<h1>Withholding Tax Report</h1><p class="muted">'.wrEscape($office['name']).' · '.wrEscape($d['from']).' through '.wrEscape($d['through']).'</p>';
    $selection=['Treatment: '.([''=>'All','SCHOOL_UTILIZATION'=>'School-utilized','REMIT'=>'For remittance'][$d['disposition']])];
    if($d['completion']!=='')$selection[]='Completion: '.($d['completion']==='recorded'?'Recorded remitted / received':'Not recorded complete / review');
    if($d['fund']!=='')$selection[]='Source: '.$d['fund'];
    if($d['fundId'])foreach($d['fundChoices'] as $f)if((int)$f['id']===(int)$d['fundId'])$selection[]=$f['name'].' · FY '.$f['fiscal_year'];
    if($d['atc']!=='')$selection[]='ATC: '.$d['atc'];
    echo '<p class="muted">'.wrEscape(implode(' · ',$selection)).'</p>';
    echo '<p class="muted">Read-only original DV withholding schedule, current completion status. Division receipt is not BIR settlement. Legacy matches are audit-only; actual retained funding applied and adjusted balances are shown in Fund Management by fund and fiscal year. No additional cash or allotment is implied.</p>';
    echo '<form method="get"><input type="hidden" name="organization_id" value="'.$org.'">';
    if(($_GET['embedded']??'')==='1')echo '<input type="hidden" name="embedded" value="1">';
    foreach([['from','From',$d['from']],['through','Through',$d['through']]] as [$key,$label,$val])echo '<label>'.wrEscape($label).'<input type="date" name="'.$key.'" value="'.wrEscape($val).'" required></label>';
    echo '<label>Fund source<input name="fund" maxlength="40" placeholder="All sources" value="'.wrEscape($d['fund']).'"></label>';
    echo '<label>Tax code<input name="atc" maxlength="25" placeholder="All ATCs" value="'.wrEscape($d['atc']).'"></label>';
    if($office['kind']==='SCHOOL'){
        echo '<label>Fund / fiscal year<select name="fund_id"><option value="0">All fund records</option>';
        foreach($d['fundChoices'] as $f)echo '<option value="'.(int)$f['id'].'"'.((int)$d['fundId']===(int)$f['id']?' selected':'').'>'.wrEscape($f['name'].' · FY '.$f['fiscal_year']).'</option>';
        echo '</select></label>';
    }
    foreach([['disposition','Withholding treatment',[''=>'All treatments','SCHOOL_UTILIZATION'=>'School-utilized','REMIT'=>'For remittance']],['completion','Remittance completion (remittance only)',[''=>'All states','recorded'=>'Remitted / Division receipt recorded','open'=>'Not recorded complete / review']]] as [$key,$label,$options]){
        echo '<label>'.wrEscape($label).'<select name="'.$key.'">';
        foreach($options as $value=>$text)echo '<option value="'.wrEscape($value).'"'.($d[$key]===$value?' selected':'').'>'.wrEscape($text).'</option>';
        echo '</select></label>';
    }
    if($office['kind']==='DIVISION'){
        echo '<label>School<select name="school_id"><option value="0">All attached schools</option>';
        foreach($d['schools'] as $s)echo '<option value="'.(int)$s['id'].'"'.((int)$d['schoolId']===(int)$s['id']?' selected':'').'>'.wrEscape($s['name']).'</option>';
        echo '</select></label>';
    }
    echo '<button type="submit">Apply filters</button></form>';
    $vals=[['Taxes withheld','withheld']];
    if($d['disposition']!=='SCHOOL_UTILIZATION')$vals=array_merge($vals,[['For remittance','remit'],['Recorded remitted / received','remitted'],['Not recorded complete / review','remit_open']]);
    if($d['disposition']!=='REMIT')$vals=array_merge($vals,[['School retained','retained'],['Retained confirmed','confirmed'],['Legacy manual matches','applied']]);
    echo '<div class="kpis">';foreach($vals as [$label,$key])echo '<div><small>'.wrEscape($label).'</small><strong>₱'.wrMoney($sum[$key]??0).'</strong></div>';echo '</div>';
    $query=['organization_id'=>$org,'from'=>$d['from'],'through'=>$d['through'],'fund'=>$d['fund'],'atc'=>$d['atc'],'fund_id'=>$d['fundId'],'disposition'=>$d['disposition'],'completion'=>$d['completion']];
    if($office['kind']==='DIVISION')$query['school_id']=$d['schoolId'];
    echo '<p><a class="export" href="?'.wrEscape(http_build_query($query+['format'=>'csv'])).'">Export CSV</a> <button type="button" data-report-print>Print / Save PDF</button></p>';
    if(array_key_exists('current_retained_funds',$d)){
        echo '<h2>Current retained funding by fund / fiscal year</h2><p class="muted">All finalized DV funding evidence for the selected fund records, not the report date or ATC filter. These are allocation reconciliation amounts, not cash or BIR settlement. The CSV below remains the original component schedule.</p>';
        if($d['current_retained_funds']===null)echo '<p class="muted">Current fund reconciliation unavailable; open Fund Management to review.</p>';
        else{
            echo '<section class="scroll"><table><thead><tr>';
            foreach(['Fund / fiscal year','Eligible retention','Applied to DV funding','Eligible unapplied','Adjusted remaining allocation'] as $label)echo '<th>'.wrEscape($label).'</th>';
            echo '</tr></thead><tbody>';
            foreach($d['current_retained_funds'] as $f){echo '<tr><td>'.wrEscape($f['name'].' · FY '.$f['fiscal_year']).'</td>';
                foreach(['retained_eligible','retained_funding_applied','retained_unapplied','adjusted_remaining_allocation'] as $key)echo '<td>'.wrEscape($f[$key]??'Review required').'</td>';
                echo '</tr>';}
            if(!$d['current_retained_funds'])echo '<tr><td colspan="5">No school-utilized withholding for the selected fund records.</td></tr>';
            echo '</tbody></table></section>';
        }
    }
    echo '<section class="scroll"><table><thead><tr>';
    $showLegacy=$d['disposition']!=='REMIT';
    $columns=['School','DV / Date','Supplier / Payee','Fund','ATC','Gross DV','Withheld','Disposition','Status',...($showLegacy?['Legacy matched','Legacy unmatched confirmed']:[]),'Receipt / remittance ref','JEV'];
    foreach($columns as $h)echo '<th>'.wrEscape($h).'</th>';echo '</tr></thead><tbody>';
    foreach($rows as $r){$cols=[$r['school_name'],$r['dv_number'].' / '.$r['document_date'],$r['payee'],$r['fund_code'].' · FY '.$r['fiscal_year'],$r['atc_code'],$r['dv_gross'],$r['amount'],$r['disposition'],$r['report_status'],...($showLegacy?[$r['applied_amount'],$r['remaining_amount']]:[]),$r['division_receipt']??$r['remittance_reference']??'', $r['jev_reference']??''];echo '<tr>';foreach($cols as $c)echo '<td>'.wrEscape($c).'</td>';echo '</tr>';}
    if(!$rows)echo '<tr><td colspan="'.count($columns).'">No withholding components match this period and selected office.</td></tr>';
    echo '</tbody></table></section><p><small>Generated '.wrEscape((new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s')).' Asia/Manila. Original voucher withholding is not recalculated.</small></p></main></body></html>';exit;
}
try {
    demand(($_SERVER['REQUEST_METHOD']??'GET')==='GET','Read-only report',405);
    $db=database();$actor=currentUser($db);$org=idOf($_GET,'organization_id');
    $data=wrRead($db,$actor,$_GET);$format=(string)($_GET['format']??'html');
    demand(in_array($format,['html','csv'],true),'Invalid report format',422);
    if($format==='html'){
        // Reuse the fund summary only in an authorized school report. Do not
        // broaden Division scope or repeat full reconciliation for all schools.
        if($data['office']['kind']==='SCHOOL'&&$data['disposition']!=='REMIT'){
            try{$f=(new Domain($db,$actor))->list(['organization_id'=>$org,'kind'=>'funds']);
                $data['current_retained_funds']=array_values(array_filter($f,static fn($r)=>
                    (!$data['fundId']||(int)$r['id']===$data['fundId']) && ($data['fund']===''||$r['source_code']===$data['fund']) &&
                    (float)($r['division_tax_accountability']['withholding_recorded']??0)>0));
            }catch(Throwable $e){$data['current_retained_funds']=null;}
        }
        wrRender($data,$org);
    }
    header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="IPDAMS_Withholding_'.date('Ymd').'.csv"');
    $out=fopen('php://output','wb');fputcsv($out,['School','DV','Date','Payee','Fund','ATC','Gross DV','Withheld','Disposition','Status','Legacy matched','Legacy unmatched confirmed','Receipt / remittance ref','JEV','Fiscal year']);
    foreach($data['rows'] as $r)fputcsv($out,array_map('wrCsvCell',[$r['school_name'],$r['dv_number'],$r['document_date'],$r['payee'],$r['fund_code'],$r['atc_code'],$r['dv_gross'],$r['amount'],$r['disposition'],$r['report_status'],$r['applied_amount'],$r['remaining_amount'],$r['division_receipt']??$r['remittance_reference']??'',$r['jev_reference']??'',$r['fiscal_year']]));
    fclose($out);exit;
}catch(DomainError $e){http_response_code($e->status);header('Content-Type: text/plain; charset=UTF-8');echo $e->getMessage();}
catch(Throwable $e){http_response_code(500);header('Content-Type: text/plain; charset=UTF-8');error_log('IPDAMS withholding report: '.get_class($e).' '. $e->getMessage());echo 'Withholding report unavailable; finance review required.';}
