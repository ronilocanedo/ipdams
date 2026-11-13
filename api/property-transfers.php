<?php
declare(strict_types=1);
/** IPDAMS · authenticated school property transfer desk, no public writes. */
require_once dirname(__DIR__).'/private/app/Domain.php';
require_once dirname(__DIR__).'/private/app/PropertyTransferWorkflow.php';
ini_set('display_errors','0');ini_set('log_errors','1');
$https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_samesite','Lax');ini_set('session.cookie_secure',$https?'1':'0');
session_name('IPDAMSSESSID');session_start();$_SESSION['csrf']??=bin2hex(random_bytes(24));
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
// Embedded presentation is allowed only in a same-origin IPDAMS tab.
// Standalone reports retain DENY. Authentication and school scope are unchanged.
$ptEmbedded=(($_GET['embedded']??'')==='1') && !isset($_GET['print']);
header('X-Frame-Options: '.($ptEmbedded?'SAMEORIGIN':'DENY'));
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors ".($ptEmbedded?"'self'":"'none'"));
function ptE(mixed $v):string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$err='';$flash='';$info=null;$context=null;$doc=null;$print=false;$user=null;$schools=[];
try {
    $db=database();$user=currentUser($db);
    demand(in_array($user['role'],['SCHOOL','SUPER_ADMIN','DIVISION'],true),'Property Transfers requires an authorized school or Division user',403);
    $flow=new PropertyTransferWorkflow($db,$user);
    $requested=(int)($_POST['organization_id']??$_GET['organization_id']??0);
    if($requested===0){
        if($user['role']==='SUPER_ADMIN')$r=one($db,"SELECT id FROM organizations WHERE kind='SCHOOL' AND active=1 ORDER BY name LIMIT 1");
        else $r=one($db,"SELECT o.id FROM organizations o JOIN user_organization_scopes s ON s.organization_id=o.id WHERE s.user_id=? AND o.kind=? AND o.active=1 ORDER BY o.name LIMIT 1",[$user['id'],$user['role']==='DIVISION'?'DIVISION':'SCHOOL']);
        demand($r!==null,'No active organization assigned',403);$requested=(int)$r['id'];
    }
    scope($db,$user,$requested);
    // A Super Administrator may select either an active SCHOOL or DIVISION.
    // Determine which transfer desk to display from the *validated organization kind*,
    // not from the Super Administrator's global role alone.
    $office=one($db,"SELECT id,kind FROM organizations WHERE id=? AND kind IN ('SCHOOL','DIVISION') AND active=1",[$requested]);
    demand($office!==null,'Select an active organization in your workspace',403);
    $officeKind=(string)$office['kind'];
    demand($user['role']==='SUPER_ADMIN'||$officeKind===$user['role'],
        'Selected organization is not available to this transfer role',403);
    $divisionReceivingDesk=$officeKind==='DIVISION';
    if($user['role']==='SUPER_ADMIN')$schools=q($db,"SELECT id,name FROM organizations WHERE kind='SCHOOL' AND active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        // The Division receiving inbox is documentary/read-only in v0.2.260.
        // Never dispatch a school mutation when a Division workspace is selected.
        demand(!$divisionReceivingDesk,'Incoming Division transfers are read-only until receiving-office acceptance is implemented',403);
        demand(hash_equals((string)$_SESSION['csrf'],(string)($_POST['_csrf']??'')),'Security token expired; refresh this page',403);
        $action=(string)($_POST['action']??'');
        if($action==='prepare'){
            $picked=$_POST['asset_ids']??[];demand(is_array($picked),'Invalid asset selection',422);
            $resp=$flow->prepare(['source_school_id'=>$requested,'destination_school_id'=>(int)($_POST['destination_school_id']??0),
                'transfer_kind'=>(string)($_POST['transfer_kind']??''),'destination_division_id'=>(int)($_POST['destination_division_id']??0),
                'receiving_target_mode'=>(string)($_POST['receiving_target_mode']??'REGISTERED'),
                'external_division_name'=>(string)($_POST['external_division_name']??''),
                'external_office_name'=>(string)($_POST['external_office_name']??''),'asset_ids'=>array_values($picked),
                'receiver_employee_id'=>(int)($_POST['receiver_employee_id']??0),'reason'=>(string)($_POST['reason']??'')]);
            $_SESSION['pt_notice']=$resp['status']==='RECORDED'
                ?'Internal transfer '.$resp['type'].' '.$resp['number'].' recorded. Custodian updated automatically; print the ITR/PTR for documentation. No paper signature has been verified in IPDAMS.'
                :'External transfer '.$resp['type'].' '.$resp['number'].' prepared for documentation only; cross-school custody has not changed.';
        }elseif($action==='record_pending'){
            $resp=$flow->recordPendingInternal(['organization_id'=>$requested,'transfer_id'=>(int)($_POST['transfer_id']??0)]);
            $_SESSION['pt_notice']='Previously pending internal transfer '.$resp['number'].' now recorded; asset custodian updated. No signed handover was attested.';
        }elseif($action==='cancel'){
            $resp=$flow->cancel(['organization_id'=>$requested,'transfer_id'=>(int)($_POST['transfer_id']??0),'reason'=>(string)($_POST['reason']??'')]);
            $_SESSION['pt_notice']='Transfer '.$resp['number'].' cancelled before receipt; original properties were not moved.';
        }else demand(false,'Unknown transfer action',400);
        header('Location: property-transfers.php?organization_id='.rawurlencode((string)$requested).($ptEmbedded?'&embedded=1':''),true,303);exit;
    }
    $flash=(string)($_SESSION['pt_notice']??'');unset($_SESSION['pt_notice']);
    if(isset($_GET['print'],$_GET['id'])){
        $doc=$flow->detail((int)$_GET['id'],$requested);$mode=(string)$_GET['print'];
        demand($mode==='transfer'||$mode==='ris','Invalid print form',422);$print=true;
    }else $context=$divisionReceivingDesk?$flow->incomingForDivision($requested):$flow->context($requested);
}catch(DomainError $e){http_response_code($e->status);$err=$e->getMessage();}
catch(Throwable $e){http_response_code(500);error_log('IPDAMS property transfers: '.$e->getMessage());$err='Transfer operation failed. No change should be assumed; refresh and check the Transfer Register before retrying.';}
if($print && is_array($doc)){
    $s=$doc['snapshot'];$type=$doc['type'];$title=($_GET['print']==='ris')?'REQUISITION AND ISSUE SLIP':($type==='ITR'?'INVENTORY TRANSFER REPORT':'PROPERTY TRANSFER REPORT');
    $toCents=static function(string $raw):int{demand(preg_match('/^[0-9]{1,12}\.[0-9]{2}$/D',$raw)===1,'Invalid saved property cost',409);[$whole,$minor]=explode('.',$raw);return (int)$whole*100+(int)$minor;};
    $money=static fn(int $cents)=>number_format(intdiv($cents,100),0,'.',',').'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
    $sum=0;foreach($s['items'] as $item)$sum+=$toCents((string)$item['unit_cost']);
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><title><?=ptE($title.' '.$doc['number'])?></title><style>
    @page{size:letter portrait;margin:12mm}body{font:11px 'Bookman Old Style',Georgia,serif;color:#101820;margin:15px auto;max-width:850px}.tools{text-align:right;margin-bottom:18px}button{padding:8px 16px}h1{text-align:center;font-size:17px;margin:26px 0 16px}.head{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:0 0 14px}table{border-collapse:collapse;width:100%;font-size:10px}th,td{border:1px solid #252525;padding:7px 5px;text-align:left;vertical-align:top}th{background:#eee}.center{text-align:center}.right{text-align:right}.sign{display:grid;grid-template-columns:1fr 1fr;gap:38px;margin-top:52px}.sign div{border-top:1px solid #333;text-align:center;padding:8px 0}.notice{border:1px solid #777;padding:9px;margin-bottom:14px}.muted{font-size:9px}tr{break-inside:avoid}@media print{body{max-width:none;margin:0}.tools{display:none}} </style></head><body>
    <script src="../assets/property-transfers.js?v=0234" defer></script>
    <div class="tools"><button id="pt-print" type="button">Print</button></div>
    <div class="muted" style="text-align:right"><?=ptE($_GET['print']==='ris'?'Companion RIS (read-only)':'ITR/PTR · transfer documentation')?></div>
    <h1><?=ptE($title)?></h1>
    <div class="head"><div><b>From:</b> <?=ptE($s['source_school_name'])?></div><div><b>To:</b> <?=ptE($s['destination_school_name'])?></div>
    <div><b><?=($_GET['print']==='ris')?'RIS No.':ptE($type).' No.'?>:</b> <?=ptE($doc['number'])?></div><div><b>Transfer type:</b> <?=ptE($s['transfer_kind'])?></div>
    <div><b>Receiving Division:</b> <?=ptE(($s['destination_division_name']??'Not recorded in legacy transfer').(!empty($s['external_destination_unverified'])?' (unverified external reference)':''))?></div><div><b>Receiving office type:</b> <?=ptE($s['destination_organization_kind']??'SCHOOL')?></div>
    <div><b>Intended receiver:</b> <?=ptE($s['receiver_name'])?></div><div><b>Status:</b> <?=ptE($s['status'])?></div>
    <?php if(($s['status']??'')==='RECEIVED'):?><div><b>Receipt date:</b> <?=ptE($s['received_on']??'')?></div><div><b>Paper reference:</b> <?=ptE($s['receipt_reference']??'')?></div><?php endif;?></div>
    <div class="notice muted">Prepared from saved IPDAMS property records. <?=($s['transfer_kind']??'')==='EXTERNAL'?(($s['status']??'')==='RECEIVED'?'Receiving-office acceptance has been recorded. Source and destination asset history remains linked. ':'External transfer is awaiting receiving-office acceptance; no asset has moved into the destination register. '):''?>This printout does not itself prove delivery, acceptance, signature, approval or financial posting. Verify official formats and signed documents before official use.</div>
    <?php if($_GET['print']==='ris'):?>
    <table><thead><tr><th style="width:9%">Stock No.</th><th>Unit</th><th>Description / property number</th><th>Qty requested</th><th>Stock available? Yes / No</th><th>Qty issued</th><th>Remarks</th></tr></thead><tbody>
    <?php foreach($s['items'] as $i=>$item):?><tr><td class="center"><?=ptE($i+1)?></td><td class="center">unit</td><td><?=ptE($item['description'])?><div class="muted">Property No. <?=ptE($item['property_tag'])?></div></td><td class="center">1</td><td class="center">___ / ___</td><td class="center">____</td><td></td></tr><?php endforeach;?></tbody></table>
    <?php else:?>
    <table><thead><tr><th style="width:7%">No.</th><th>Description / individual property number</th><th style="width:10%">Qty</th><th style="width:15%">Unit cost</th><th style="width:15%">Amount</th></tr></thead><tbody>
    <?php foreach($s['items'] as $i=>$item):?><tr><td class="center"><?=ptE($i+1)?></td><td><?=ptE($item['description'])?><div class="muted">Property No. <?=ptE($item['property_tag'])?></div></td><td class="center">1</td><td class="right"><?=ptE($money($toCents((string)$item['unit_cost'])))?></td><td class="right"><?=ptE($money($toCents((string)$item['unit_cost'])))?></td></tr><?php endforeach;?>
    <tr><th colspan="4" class="right">TOTAL</th><th class="right"><?=ptE($money($sum))?></th></tr></tbody></table>
    <?php endif;?>
    <p><b>Purpose:</b> <?=ptE($s['purpose'])?></p>
    <p><b>Record date:</b> <?=ptE($s['recorded_at']??$s['received_at']??$s['prepared_at']??'—')?> · <b>Signed paper reference (if available):</b> <?=ptE($s['receipt_reference']?:'To be kept with paper ITR/PTR')?></p>
    <div class="sign"><div>Issued / transferred by<br>Signature over printed name, position and date</div><div>Received by: <?=ptE($s['receiver_name'])?><br>Signature, position and date (manually executed)</div></div>
    </body></html><?php exit;}
?><!doctype html><html lang="en" class="<?=$ptEmbedded?'embedded':''?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>IPDAMS · Property Transfers</title><style>
:root{font-family:system-ui,Arial,sans-serif;color:#20354b;background:#f3f6f8}*{box-sizing:border-box}body{margin:0}header{background:#0e2a45;color:#fff;padding:18px 26px}header a{color:#b1e6e0}.shell{max-width:1180px;margin:22px auto;padding:0 16px}h1{margin:0;font-size:24px}h2{font-size:19px;margin-top:0}p{line-height:1.5}section{background:#fff;border:1px solid #d5e1e8;border-radius:10px;padding:20px;margin-bottom:18px}label{font-weight:650;font-size:14px;display:block;margin:12px 0 6px}input,select,textarea{width:100%;padding:10px;border:1px solid #bdcbd8;border-radius:6px;font:inherit}input[type=checkbox],input[type=radio]{width:auto}button{padding:11px 18px;border:0;border-radius:6px;background:#078f83;color:#fff;font-weight:700;cursor:pointer}button:disabled{opacity:.55}.muted{color:#5c6e7f}.note{border-left:4px solid #10ad99;padding:12px;background:#f0faf8}.danger{color:#992b2b}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 18px}table{border-collapse:collapse;width:100%;font-size:13px}th,td{border-bottom:1px solid #dce4ea;text-align:left;padding:10px;vertical-align:middle}.scroll{overflow-x:auto}.notice{border:1px solid #c4e9dd;background:#f1faf6;padding:12px;border-radius:6px}.error{border:1px solid #efb0a5;background:#fff3ef;color:#852f2b;padding:12px;border-radius:6px}.pill{display:inline-block;padding:4px 7px;border-radius:12px;background:#eaf2f6}.rowActions{display:flex;gap:12px;flex-wrap:wrap}.rowActions a{color:#116d75}.small{font-size:12px}.pt-count{font-weight:700;color:#087a78}.pt-control{background:#f6fafb;border:1px solid #dbe8ea;border-radius:8px;padding:12px}.pt-inline{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.pt-inline button{padding:7px 11px;background:#246d79}.pt-inline label{display:inline;font-weight:500;margin:0}.pt-property-check{width:17px!important;height:17px!important;accent-color:#078f83}.pt-filter-row[hidden],.pt-external[hidden],.pt-internal[hidden],tr[hidden]{display:none!important}.pt-pill{border-radius:8px;background:#e7f4f1;color:#276b65;font-size:12px;font-weight:700;padding:4px 7px}.pt-status{font-size:12px;color:#536d75}.pt-destination{border:1px solid #ddede8;border-radius:8px;padding:12px}.pt-steps{font-size:13px;color:#3d6577;margin:6px 0 14px}.pt-empty{padding:15px;color:#52677a}@media(max-width:700px){.grid{grid-template-columns:1fr}section{padding:14px}table{font-size:12px}}
html.embedded{background:transparent}html.embedded .shell{max-width:none;margin:0;padding:0 1px}html.embedded body{overflow-x:hidden}html.embedded header{display:none}
</style><script src="../assets/property-transfers.js?v=0259" defer></script></head><body>
<?php if(!$ptEmbedded):?><header><h1>IPDAMS · Property Transfers</h1><a href="../index.php?section=Property%20Accountability">← Back to Property Accountability</a></header><?php endif;?><main class="shell">
<?php if($err):?><p class="error" role="alert"><?=ptE($err)?></p><?php endif;?>
<?php if($flash):?><p class="notice" role="status"><?=ptE($flash)?></p><?php endif;?>
<?php if($context && $divisionReceivingDesk):?>
<section><h2>Incoming external transfers — <?=ptE($context['division']['name'])?></h2><p><a href="property-transfer-intake.php?organization_id=<?=ptE($requested)?>&amp;view=incoming<?=($ptEmbedded)?'&amp;embedded=1':''?>">Open secure Receiving Desk · Inspect / Accept</a></p>
<p class="note">These are documentary transfer requests addressed to this Division or its schools. Items do <strong>not</strong> enter the receiving Asset Register at preparation. The appointed receiving Property Custodian may inspect and accept a transfer addressed to this Division through the secure Receiving Desk. Until acceptance, the sender retains active custody.</p>
<div class="scroll"><table><thead><tr><th>Transfer</th><th>Origin</th><th>Receiving school/office</th><th>Properties</th><th>Status</th><th>Document</th></tr></thead><tbody>
<?php foreach($context['incoming'] as $t):$s=$t['snapshot'];?><tr><td><?=ptE($t['type'].' '.$t['number'])?></td><td><?=ptE($s['source_school_name'])?></td><td><?=ptE($s['destination_school_name'])?></td><td><?=ptE(count($s['items']))?></td><td><span class="pill"><?=ptE($s['status']==='PENDING_RECEIPT'?'AWAITING RECEIPT':$s['status'])?></span></td><td><a target="_blank" rel="noopener" href="property-transfers.php?organization_id=<?=ptE($requested)?>&amp;id=<?=ptE($t['id'])?>&amp;print=transfer">View / Print</a></td></tr><?php endforeach;?>
<?php if(!$context['incoming']):?><tr><td colspan="6">No incoming external transfers addressed to your Division.</td></tr><?php endif;?></tbody></table></div></section>
<?php elseif($context):?>
<section><h2>Transfer workspace</h2><p><a href="property-transfer-intake.php?organization_id=<?=ptE($requested)?>&amp;view=incoming<?=($ptEmbedded)?'&amp;embedded=1':''?>">Open Incoming Transfers · Receive property</a></p><p class="muted">Internal transfers update the assigned employee immediately and keep printable ITR/PTR history. External transfers identify the receiving <b>Division</b> and <b>school or Division Office</b>, but remain <b>Awaiting Receipt</b> until the receiving custodian confirms the handover in Incoming Transfers. No second active asset is created by preparing a transfer.</p>
<?php if($user['role']==='SUPER_ADMIN'):?><form method="get"><?php if($ptEmbedded):?><input type="hidden" name="embedded" value="1"><?php endif;?><label>School workspace</label><select name="organization_id"><?php foreach($schools as $opt):?><option value="<?=ptE($opt['id'])?>" <?=((int)$opt['id']===$requested)?'selected':''?>><?=ptE($opt['name'])?></option><?php endforeach;?></select><button type="submit">Open school</button></form><?php else:?><p><b>School:</b> <?=ptE($context['school']['name'])?></p><?php endif;?></section>
<section><h2>Prepare property transfer</h2><p class="pt-steps">1 · Choose transfer type and destination &nbsp; → &nbsp; 2 · Select properties &nbsp; → &nbsp; 3 · Prepare documents</p><p class="note">Semi-expendable property uses ITR; PPE uses PTR. Each transfer may contain 1–20 properties of the same classification. External transfers remain documentary until the receiving custodian confirms actual receipt in Incoming Transfers.</p>
<form method="post" autocomplete="off" id="pt-transfer-form" data-source-id="<?=ptE($requested)?>" data-source-division="<?=ptE($context['school']['parent_division_id'])?>">
<input type="hidden" name="_csrf" value="<?=ptE($_SESSION['csrf'])?>"><input type="hidden" name="organization_id" value="<?=ptE($requested)?>"><input type="hidden" name="action" value="prepare">
<div class="pt-destination"><div class="grid"><div><label for="pt-kind">Transfer type</label><select id="pt-kind" name="transfer_kind" required><option value="INTERNAL">Internal — another employee in this school</option><option value="EXTERNAL">External — another school or Division Office</option></select></div>
<div class="pt-external" hidden><label for="pt-target-mode">Receiving destination</label><select id="pt-target-mode" name="receiving_target_mode"><option value="REGISTERED">Registered IPDAMS school or Division Office</option><option value="OUTSIDE">Another DepEd Division / office outside IPDAMS</option></select></div>
<div class="pt-external pt-registered" hidden><label for="pt-division">Receiving Division</label><select id="pt-division" name="destination_division_id"><option value="">Choose receiving Division</option><?php foreach($context['divisions'] as $div):?><option value="<?=ptE($div['id'])?>" <?=((int)$div['id']===(int)$context['school']['parent_division_id'])?'selected':''?>><?=ptE($div['name'])?></option><?php endforeach;?></select></div>
<div class="pt-external pt-registered" hidden><label for="pt-destination">Receiving school / Division Office</label><select id="pt-destination" name="destination_school_id" required><option value="">Choose receiving office</option><?php foreach($context['destinations'] as $opt):$did=$opt['kind']==='DIVISION'?(int)$opt['id']:(int)$opt['parent_division_id'];?><option value="<?=ptE($opt['id'])?>" data-division="<?=ptE($did)?>" data-kind="<?=ptE($opt['kind'])?>" <?=((int)$opt['id']===$requested)?'disabled':''?>><?=ptE($opt['kind']==='DIVISION'?'Division Office — '.$opt['name']:$opt['name'])?></option><?php endforeach;?></select></div>
<div class="pt-external pt-outside" hidden><label for="pt-external-division">Receiving Division (official name)</label><input id="pt-external-division" name="external_division_name" minlength="5" maxlength="160" placeholder="e.g., Schools Division of ..."></div>
<div class="pt-external pt-outside" hidden><label for="pt-external-office">Receiving school / office (official name)</label><input id="pt-external-office" name="external_office_name" minlength="5" maxlength="160" placeholder="Official receiving school or office"></div>
<div class="pt-internal"><label for="pt-internal-destination">Internal school</label><input id="pt-internal-destination" value="<?=ptE($context['school']['name'])?>" readonly><input type="hidden" id="pt-self" name="destination_school_id" value="<?=ptE($requested)?>"></div>
<div class="pt-internal"><label for="pt-employee">Receiving employee (internal only)</label><select id="pt-employee" name="receiver_employee_id"><option value="">Choose employee</option><?php foreach($context['employees'] as $emp):?><option value="<?=ptE($emp['id'])?>"><?=ptE($emp['name'])?></option><?php endforeach;?></select></div>
<div><label for="pt-reason">Purpose / reason</label><textarea id="pt-reason" name="reason" rows="2" minlength="8" maxlength="500" placeholder="State the purpose of this transfer" required></textarea></div></div>
<div class="pt-external pt-status" hidden>Preparing the transfer records its destination and reserves the selected properties. The receiving office <strong>must confirm actual receipt</strong> before assets enter its register. Destinations outside IPDAMS are unverified documentary references and require subsequent authoritative organization mapping. No online acceptance is implied.</div></div>
<div class="pt-control"><label for="pt-property-search">2 · Select reviewed properties (1–20)</label><input type="search" id="pt-property-search" placeholder="Search property number, item, custodian..." autocomplete="off">
<div class="pt-inline"><button type="button" id="pt-select-visible">Select visible</button><button type="button" id="pt-clear">Clear selection</button><span class="pt-count" id="pt-selected-count" aria-live="polite">0 properties selected</span><span class="pt-status" id="pt-selection-message"></span></div></div>
<div class="scroll"><table><thead><tr><th style="width:42px">Select</th><th>Property number</th><th>Item</th><th>Class</th><th>Current custodian</th></tr></thead><tbody>
<?php foreach($context['assets'] as $a):?><tr class="pt-filter-row" data-haystack="<?=ptE(mb_strtolower((string)$a['tag'].' '.$a['item'].' '.$a['category'].' '.($a['custodian']??'')))?>"><td><input class="pt-property-check" type="checkbox" name="asset_ids[]" value="<?=ptE($a['id'])?>" data-type="<?=ptE($a['category'])?>" aria-label="Select <?=ptE($a['tag'])?>"></td><td><?=ptE($a['tag'])?></td><td><?=ptE($a['item'])?></td><td><?=ptE($a['category'])?></td><td><?=ptE($a['custodian']?:'Unassigned')?></td></tr><?php endforeach;?>
<?php if(!$context['assets']):?><tr><td colspan="5">No reviewed, active, unreserved properties available in this school.</td></tr><?php endif;?></tbody></table></div>
<div class="pt-control"><span class="pt-count" id="pt-selected-footer">0 properties selected</span> <span class="pt-status">Mixed PPE/semi-expendable selections are not allowed in one document.</span></div>
<button type="submit" id="pt-submit" <?=(!$context['assets'])?'disabled':''?>>Record internal transfer</button></form></section>
<section><h2>Transfer register</h2><p class="muted">Internal transfers update the current custodian immediately. External transfers remain Awaiting Receipt; their properties stay in the sender's active register until receiving-office inspection and acceptance are implemented.</p><div class="scroll"><table><thead><tr><th>Number / report</th><th>From → To</th><th>Properties</th><th>Status</th><th>Print</th><th>Custody record</th></tr></thead><tbody>
<?php foreach($context['transfers'] as $t):$s=$t['snapshot'];?><tr><td><?=ptE($t['type'].' '.$t['number'])?></td><td><?=ptE($s['source_school_name'].' → '.($s['destination_division_name']??'').' / '.$s['destination_school_name'])?></td><td><?=count($s['items'])?></td><td><span class="pill"><?=ptE($s['status']==='PENDING_RECEIPT'?'AWAITING RECEIPT':$s['status'])?></span></td><td><div class="rowActions"><a target="_blank" rel="noopener" href="property-transfers.php?organization_id=<?=ptE($requested)?>&amp;id=<?=ptE($t['id'])?>&amp;print=transfer">Print <?=ptE($t['type'])?></a><a target="_blank" rel="noopener" href="property-transfers.php?organization_id=<?=ptE($requested)?>&amp;id=<?=ptE($t['id'])?>&amp;print=ris">Companion RIS</a></div></td><td>
<?php if($s['status']==='PENDING_RECEIPT'&&$s['transfer_kind']==='INTERNAL'&&(int)$s['source_school_id']===$requested):?>
<p>Older internal transfer awaiting IPDAMS custodian update.</p><form method="post"><input type="hidden" name="_csrf" value="<?=ptE($_SESSION['csrf'])?>"><input type="hidden" name="organization_id" value="<?=ptE($requested)?>"><input type="hidden" name="action" value="record_pending"><input type="hidden" name="transfer_id" value="<?=ptE($t['id'])?>"><button type="submit">Apply saved internal transfer</button></form>
<?php elseif($s['status']==='PENDING_RECEIPT'&&$s['transfer_kind']==='EXTERNAL'):?>Awaiting receiving-office inspection. The receiving school or Division Office can open Incoming Transfers to record physical acceptance.
<?php elseif($s['status']==='RECORDED'):?>Custodian updated in IPDAMS; paper copy kept separately
<?php elseif($s['status']==='RECEIVED'):?>Historical signed handover recorded
<?php elseif($s['status']==='CANCELLED'):?>Cancelled
<?php else:?>Requires review<?php endif;?>
<?php if($s['status']==='PENDING_RECEIPT'&&(int)$s['source_school_id']===$requested):?><form method="post"><input type="hidden" name="_csrf" value="<?=ptE($_SESSION['csrf'])?>"><input type="hidden" name="organization_id" value="<?=ptE($requested)?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="transfer_id" value="<?=ptE($t['id'])?>"><label>Cancel pending transfer</label><input name="reason" minlength="8" maxlength="240" required placeholder="Reason for cancellation"><button type="submit">Cancel pending</button></form><?php endif;?></td></tr><?php endforeach;?>
<?php if(!$context['transfers']):?><tr><td colspan="6">No property transfers yet.</td></tr><?php endif;?></tbody></table></div></section><?php endif;?></main></body></html>
