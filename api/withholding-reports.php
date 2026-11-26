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
    if(!$ids)return ['rows'=>[],'sum'=>[],'schools'=>$schools,'office'=>$office,'from'=>$from,'through'=>$through,'fund'=>$fund,'atc'=>$atc,'schoolId'=>$schoolId];
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $sql="SELECT w.id component_id,w.atc_code,w.amount,w.disposition,
        d.id dv_id,d.display_number dv_number,d.document_date,d.gross dv_gross,d.payee,
        o.id school_id,o.name school_name,f.source_code fund_code,f.name fund_name,
        (SELECT e.state FROM tax_disposition_events e WHERE e.component_id=w.id ORDER BY e.id DESC LIMIT 1) tax_state,
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
    $sql.=' ORDER BY o.name,d.document_date,d.id,w.id LIMIT 10001';
    $rows=q($db,$sql,$args)->fetchAll();
    demand(count($rows)<10001,'Withholding report exceeds 10,000 records. Narrow the school, fund or period filter; no rows have been omitted.',409);
    // Utilization links are stored as versioned monitoring entries. Load only
    // the selected schools and validate every matching source amount below.
    $versions=q($db,"SELECT organization_id,config_key,config_json FROM configuration_versions
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
    $sum=['withheld'=>0,'remit'=>0,'retained'=>0,'confirmed'=>0,'applied'=>0,'remaining'=>0,'remitted'=>0];
    foreach($rows as &$r){
        $amount=wrCents((string)$r['amount']);$used=$usage[(int)$r['school_id']][(int)$r['component_id']]??0;
        $isSchool=$r['disposition']==='SCHOOL_UTILIZATION';$confirmed=$r['tax_state']==='CONFIRMED_CREDIT';
        demand($used>=0&&$used<=($confirmed?$amount:0),'Saved utilization exceeds confirmed withholding; accounting review required',409);
        $r['applied_amount']=wrMoney($used);$r['remaining_amount']=wrMoney($confirmed?$amount-$used:0);
        $r['report_status']=$r['tax_state']==='REMITTED'?'Division receipt recorded':
            ($isSchool?($confirmed?'Retained confirmed':'Retained pending confirmation'):
            ($r['transfer_status']?:'Awaiting remittance'));
        $sum['withheld']+=$amount;
        if($isSchool){$sum['retained']+=$amount;if($confirmed){$sum['confirmed']+=$amount;$sum['applied']+=$used;$sum['remaining']+=$amount-$used;}}
        else {$sum['remit']+=$amount;if($r['tax_state']==='REMITTED')$sum['remitted']+=$amount;}
    }unset($r);
    return compact('rows','schools','sum','office','from','through','fund','atc','schoolId');
}
function wrRender(array $d,int $org): never {
    $office=$d['office'];$rows=$d['rows'];$sum=$d['sum'];$funds=[];$atcs=[];
    foreach($rows as $r){$funds[$r['fund_code']]=true;$atcs[$r['atc_code']]=true;}
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>IPDAMS · Withholding Tax Report</title><script src="../assets/cash-fund-reports.js" defer></script>';
    echo '<style>body{font:15px system-ui,sans-serif;background:#f4f8fa;color:#19344a;margin:0}main{max-width:1400px;margin:24px auto;background:white;padding:24px;border-radius:12px}h1{margin:0 0 6px}.muted{color:#537085}form{display:flex;flex-wrap:wrap;gap:12px;align-items:end;padding:18px 0}label{display:grid;gap:5px;font-weight:600}input,select,button,a.export{padding:9px;border:1px solid #cddde5;border-radius:6px;font:inherit}button,a.export{background:#128d7f;color:white;text-decoration:none;cursor:pointer}table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:9px;border-bottom:1px solid #dde7ed;text-align:left;vertical-align:top}th{background:#eef5f7}td.money{text-align:right;white-space:nowrap}.kpis{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0}.kpis>div{background:#eef7f6;padding:14px;min-width:130px;border-radius:8px}.kpis strong{display:block}section.scroll{overflow:auto}main{box-sizing:border-box;width:100%;min-width:0}label{min-width:0;max-width:100%}input,select{box-sizing:border-box;max-width:100%}@media(max-width:640px){main{margin:0;padding:12px}form label{flex:1 1 130px}form input,form select{width:100%}.kpis>div{min-width:0;flex:1 1 120px}}small{color:#587084}@media print{body{background:white}main{margin:0;padding:0}form,.export{display:none!important}table{font-size:10px}tr{break-inside:avoid}}</style></head><body><main>';
    echo '<h1>Withholding Tax Report</h1><p class="muted">'.wrEscape($office['name']).' · '.wrEscape($d['from']).' through '.wrEscape($d['through']).'</p>';
    echo '<p class="muted">Read-only schedule. Withheld, retained, remitted, and applied are distinct accounting measures and must not be added together.</p>';
    echo '<form method="get"><input type="hidden" name="organization_id" value="'.$org.'">';
    if(($_GET['embedded']??'')==='1')echo '<input type="hidden" name="embedded" value="1">';
    foreach([['from','From',$d['from']],['through','Through',$d['through']]] as [$key,$label,$val])echo '<label>'.wrEscape($label).'<input type="date" name="'.$key.'" value="'.wrEscape($val).'" required></label>';
    echo '<label>Fund source<input name="fund" maxlength="40" placeholder="All sources" value="'.wrEscape($d['fund']).'"></label>';
    echo '<label>Tax code<input name="atc" maxlength="25" placeholder="All ATCs" value="'.wrEscape($d['atc']).'"></label>';
    if($office['kind']==='DIVISION'){
        echo '<label>School<select name="school_id"><option value="0">All attached schools</option>';
        foreach($d['schools'] as $s)echo '<option value="'.(int)$s['id'].'"'.((int)$d['schoolId']===(int)$s['id']?' selected':'').'>'.wrEscape($s['name']).'</option>';
        echo '</select></label>';
    }
    echo '<button type="submit">Apply filters</button></form>';
    $vals=[['Taxes withheld','withheld'],['For Division remittance','remit'],['Remitted / received','remitted'],['School retained','retained'],['Retained confirmed','confirmed'],['Applied to expenditures','applied'],['Confirmed unused','remaining']];
    echo '<div class="kpis">';foreach($vals as [$label,$key])echo '<div><small>'.wrEscape($label).'</small><strong>₱'.wrMoney($sum[$key]??0).'</strong></div>';echo '</div>';
    $query=['organization_id'=>$org,'from'=>$d['from'],'through'=>$d['through'],'fund'=>$d['fund'],'atc'=>$d['atc']];
    if($office['kind']==='DIVISION')$query['school_id']=$d['schoolId'];
    echo '<p><a class="export" href="?'.wrEscape(http_build_query($query+['format'=>'csv'])).'">Export CSV</a> <button type="button" data-report-print>Print / Save PDF</button></p>';
    echo '<section class="scroll"><table><thead><tr>';
    foreach(['School','DV / Date','Supplier / Payee','Fund','ATC','Gross DV','Withheld','Disposition','Status','Utilized','Remaining','Division OR / Ref','JEV'] as $h)echo '<th>'.wrEscape($h).'</th>';echo '</tr></thead><tbody>';
    foreach($rows as $r){$cols=[$r['school_name'],$r['dv_number'].' / '.$r['document_date'],$r['payee'],$r['fund_code'],$r['atc_code'],$r['dv_gross'],$r['amount'],$r['disposition'],$r['report_status'],$r['applied_amount'],$r['remaining_amount'],$r['division_receipt']??$r['remittance_reference']??'', $r['jev_reference']??''];echo '<tr>';foreach($cols as $c)echo '<td>'.wrEscape($c).'</td>';echo '</tr>';}
    if(!$rows)echo '<tr><td colspan="13">No withholding components match this period and selected office.</td></tr>';
    echo '</tbody></table></section><p><small>Generated '.wrEscape((new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s')).' Asia/Manila. Original voucher withholding is not recalculated.</small></p></main></body></html>';exit;
}
try {
    demand(($_SERVER['REQUEST_METHOD']??'GET')==='GET','Read-only report',405);
    $db=database();$actor=currentUser($db);$org=idOf($_GET,'organization_id');
    $data=wrRead($db,$actor,$_GET);$format=(string)($_GET['format']??'html');
    demand(in_array($format,['html','csv'],true),'Invalid report format',422);
    if($format==='html')wrRender($data,$org);
    header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="IPDAMS_Withholding_'.date('Ymd').'.csv"');
    $out=fopen('php://output','wb');fputcsv($out,['School','DV','Date','Payee','Fund','ATC','Gross DV','Withheld','Disposition','Status','Utilized','Remaining','Division OR/Ref','JEV']);
    foreach($data['rows'] as $r)fputcsv($out,array_map('wrCsvCell',[$r['school_name'],$r['dv_number'],$r['document_date'],$r['payee'],$r['fund_code'],$r['atc_code'],$r['dv_gross'],$r['amount'],$r['disposition'],$r['report_status'],$r['applied_amount'],$r['remaining_amount'],$r['division_receipt']??$r['remittance_reference']??'',$r['jev_reference']??'']));
    fclose($out);exit;
}catch(DomainError $e){http_response_code($e->status);header('Content-Type: text/plain; charset=UTF-8');echo $e->getMessage();}
catch(Throwable $e){http_response_code(500);header('Content-Type: text/plain; charset=UTF-8');error_log('IPDAMS withholding report: '.get_class($e).' '. $e->getMessage());echo 'Withholding report unavailable; finance review required.';}
