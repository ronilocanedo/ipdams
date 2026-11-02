<?php
declare(strict_types=1);
/** Receiver-owned, authenticated External Transfer Acceptance and provenance viewer. */
require_once dirname(__DIR__).'/private/app/Domain.php';
require_once dirname(__DIR__).'/private/app/PropertyTransferWorkflow.php';
require_once dirname(__DIR__).'/private/app/PropertyTransferAcceptance.php';
ini_set('display_errors','0');ini_set('log_errors','1');
$https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_samesite','Lax');ini_set('session.cookie_secure',$https?'1':'0');
session_name('IPDAMSSESSID');session_start();$_SESSION['csrf']??=bin2hex(random_bytes(24));
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
$embedded=(($_GET['embedded']??'')==='1');
header('X-Frame-Options: '.($embedded?'SAMEORIGIN':'DENY'));
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors ".($embedded?"'self'":"'none'"));
function ptiE(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$error='';$notice='';$view=(string)($_GET['view']??'incoming');$org=(int)($_POST['organization_id']??$_GET['organization_id']??0);
$received=[];$incoming=[];$details=null;$history=null;$options=[];$binding=null;$user=[];$kind='';$loggedInReceiver=false;$assessment=null;$mayAssess=false;
try {
    $db=database();$user=currentUser($db);
    demand(in_array($user['role'],['SCHOOL','DIVISION','SUPER_ADMIN'],true),'Receiving property requires office authorization',403);
    demand($org>0,'Select an organization in your workspace',422);
    scope($db,$user,$org);
    $office=one($db,"SELECT id,name,kind,active FROM organizations WHERE id=? AND kind IN ('SCHOOL','DIVISION') AND active=1",[$org]);
    demand($office!==null&&($user['role']==='SUPER_ADMIN'||$user['role']===$office['kind']),
        'Select the correct receiving school or Division workspace',403);
    $kind=(string)$office['kind'];
    $acceptance=new PropertyTransferAcceptance($db,$user);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        demand(hash_equals((string)$_SESSION['csrf'],(string)($_POST['_csrf']??'')),
            'Security token expired; reload the receiving page',403);
        $action=(string)($_POST['action']??'');
        if($action==='bind_receiver'){
            $out=$acceptance->bind(['organization_id'=>$org,'user_id'=>(int)($_POST['user_id']??0),
                'reason'=>(string)($_POST['reason']??'')]);
            $_SESSION['pt_intake_notice']='Authorized receiving login linked to the appointed Property Custodian.';
        }elseif($action==='accept_transfer'){
            demand(is_array($_POST['inspection']??null),'Physical inspection details are required',422);
            $ins=[];foreach(($_POST['inspection']??[]) as $line)$ins[]= [
                'source_asset_id'=>(int)($line['source_asset_id']??0),
                'receiving_item_id'=>(int)($line['receiving_item_id']??0),
                'condition'=>(string)($line['condition']??''),'location'=>(string)($line['location']??'')];
            $out=$acceptance->accept(['organization_id'=>$org,'transfer_id'=>(int)($_POST['transfer_id']??0),
                'received_on'=>(string)($_POST['received_on']??''),
                'paper_reference'=>(string)($_POST['paper_reference']??''),
                'inspection_note'=>(string)($_POST['inspection_note']??''),
                'physical_received'=>($_POST['physical_received']??'')==='on',
                'inspections'=>$ins]);
            $_SESSION['pt_intake_notice']=$out['number'].' accepted. '.$out['count'].' linked receiving asset(s) registered; source property retained as historical. Paper signatures remain external.';
        }elseif($action==='assess_condition'){
            $out=$acceptance->assessReceivedCondition([
                'organization_id'=>$org,'asset_id'=>(int)($_POST['asset_id']??0),
                'expected_condition'=>(string)($_POST['expected_condition']??''),
                'condition'=>(string)($_POST['condition']??''),
                'inspected_on'=>(string)($_POST['inspected_on']??''),
                'inspection_reference'=>(string)($_POST['inspection_reference']??''),
                'remarks'=>(string)($_POST['remarks']??''),
                'physically_inspected'=>($_POST['physically_inspected']??'')==='on']);
            $_SESSION['pt_intake_notice']='Physical assessment saved for '.$out['property_tag'].
                ' as '.$out['condition'].'. A new ICS/PAR requires separate authorized issuance.';
        }else demand(false,'Unknown receiving-office action',400);
        header('Location: property-transfer-intake.php?organization_id='.$org.'&view='.($action==='assess_condition'?'received':'incoming').($embedded?'&embedded=1':''),true,303);exit;
    }
    $notice=(string)($_SESSION['pt_intake_notice']??'');unset($_SESSION['pt_intake_notice']);
    if(isset($_GET['history_asset_id'])){
        $view='history';$history=$acceptance->history($org,(int)$_GET['history_asset_id']);
    }elseif(isset($_GET['accept_id'])){
        $view='inspect';$id=(int)$_GET['accept_id'];
        $flow=new PropertyTransferWorkflow($db,$user);
        $all=$kind==='DIVISION'?$flow->incomingForDivision($org):$flow->incomingForSchool($org);
        foreach($all['incoming']??$all as $row)if($row['id']===$id){$details=$row;break;}
        demand($details!==null&&($details['snapshot']['transfer_kind']??'')==='EXTERNAL'
            &&(int)($details['snapshot']['destination_organization_id']??0)===$org
            &&empty($details['snapshot']['external_destination_unverified']),
            'Only the named receiving office can inspect this transfer for acceptance',403);
        $options=$acceptance->availableMappingItems($org);
        try{$auth=$acceptance->bindingOptions($org);$loggedInReceiver=(int)($auth['binding']['user_id']??0)===(int)$user['id']
            &&(int)($auth['binding']['employee_id']??0)===(int)($auth['appointment']['employee_id']??0)
            &&(int)($auth['binding']['appointment_version']??0)===(int)($auth['appointment']['appointment_version']??0)
            &&$user['role']===$kind;}catch(Throwable $ignored){$loggedInReceiver=false;}
    }elseif(isset($_GET['assess_asset_id'])){
        $view='assess';$assessment=$acceptance->receivedConditionPreview($org,(int)$_GET['assess_asset_id']);
    }elseif($view==='received'){
        $received=$acceptance->receivedRegister($org);
        $mayAssess=$acceptance->mayAssessReceivedCondition($org);
    }else{
        $view='incoming';$flow=new PropertyTransferWorkflow($db,$user);
        $entries=$kind==='DIVISION'?$flow->incomingForDivision($org):$flow->incomingForSchool($org);
        $incoming=$entries['incoming']??$entries;
        if($user['role']==='SUPER_ADMIN'){
            try{$binding=$acceptance->bindingOptions($org);}catch(DomainError $ignored){$binding=null;}
        }
    }
}catch(DomainError $e){http_response_code($e->status);$error=$e->getMessage();}
catch(Throwable $e){http_response_code(500);error_log('IPDAMS receiving property: '.$e->getMessage());
    $error='Receiving request failed. No outcome should be assumed; refresh Incoming Transfers and inspect status before resubmitting.';}
$base='property-transfer-intake.php?organization_id='.$org.($embedded?'&embedded=1':'');
$directAssessment='property-transfer-intake.php?organization_id='.$org;
?><!doctype html><html lang="en" class="<?=$embedded?'embedded':''?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>IPDAMS · Receiving Property</title>
<style>
:root{font-family:system-ui,sans-serif;color:#153349;background:#f3f6f8}*{box-sizing:border-box}body{margin:0}main{max-width:1180px;margin:auto;padding:16px}h1{font-size:22px}h2{font-size:18px}p{line-height:1.5}section{background:#fff;border:1px solid #d6e3e9;border-radius:10px;padding:18px;margin:12px 0}table{width:100%;border-collapse:collapse;font-size:13px}td,th{padding:10px 8px;border-bottom:1px solid #e0e7ea;text-align:left;vertical-align:top}th{background:#f5f8fa}.scroll{overflow-x:auto}.nav{display:flex;gap:10px;flex-wrap:wrap;margin:12px 0}.nav a,.link{color:#087d75;text-decoration:underline}label{font-size:13px;font-weight:650;display:block;margin:10px 0 5px}input,select,textarea{font:inherit;width:100%;border:1px solid #bfd0dc;border-radius:6px;padding:10px}input[type=checkbox]{width:18px;height:18px;vertical-align:middle;margin-right:8px}button{background:#0b8f82;color:#fff;padding:10px 16px;border:0;border-radius:6px;font-weight:700;cursor:pointer}button:disabled{opacity:.5;cursor:not-allowed}.note{padding:12px;border-left:4px solid #2fbba2;background:#eff9f7}.error{background:#fff0ed;color:#9b221e;padding:12px;border:1px solid #efadad}.muted{color:#567188}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.pill{border-radius:13px;background:#ebf3f5;padding:4px 8px}.item{border:1px solid #d8e5e9;border-radius:8px;margin-top:12px;padding:12px}.mark{font-weight:700;color:#156b69}html.embedded,html.embedded body{background:transparent}html.embedded main{max-width:none;padding:1px}@media(max-width:650px){.grid{grid-template-columns:1fr}table{font-size:12px}section{padding:12px}}
</style></head><body><main>
<h1>Receiving Property · <?=ptiE($office['name']??'Organization')?></h1>
<nav class="nav"><a href="<?=ptiE($base.'&view=incoming')?>">Incoming Transfers</a><a href="<?=ptiE($base.'&view=received')?>">Received Asset Register</a></nav>
<?php if($error):?><p class="error" role="alert"><?=ptiE($error)?></p><?php endif;?>
<?php if($notice):?><p class="note" role="status"><?=ptiE($notice)?></p><?php endif;?>
<?php if($view==='incoming'):?>
<section><h2>Incoming external transfers</h2><p class="note">Only the designated and authenticated receiving Property Custodian can confirm physical acceptance. Transfers addressed to a school must be accepted from that school's workspace, not its Division's oversight view. A transfer to an outside-IPDAMS name cannot be accepted electronically.</p>
<div class="scroll"><table><thead><tr><th>Number</th><th>From</th><th>Receiving office</th><th>Items</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach($incoming as $t):$s=$t['snapshot'];$addressed=(int)($s['destination_organization_id']??0)===$org&&!($s['external_destination_unverified']??false);?>
<tr><td><?=ptiE($t['type'].' '.$t['number'])?></td><td><?=ptiE($s['source_school_name']??'')?></td><td><?=ptiE($s['destination_school_name']??'')?></td><td><?=count($s['items']??[])?></td><td><span class="pill"><?=ptiE(($s['status']??'')==='PENDING_RECEIPT'?'Awaiting Receipt':($s['status']??'Unknown'))?></span></td><td>
<?php if($addressed):?><a class="link" href="<?=ptiE($base.'&accept_id='.(int)$t['id'])?>"><?=($s['status']??'')==='PENDING_RECEIPT'?'Inspect / Accept':'View acceptance'?></a><?php else:?>Division oversight only<?php endif;?>
</td></tr><?php endforeach;?>
<?php if(!$incoming):?><tr><td colspan="6">No external transfers addressed to this office.</td></tr><?php endif;?></tbody></table></div></section>
<?php if($binding!==null):?><section><h2>Link appointed custodian to receiving login</h2><p class="muted">Super Administrator configuration only. Link a login already scoped to this office and matching its role. This does not appoint a different employee or grant new organization access; the existing BAC & Heads appointment remains authoritative.</p>
<?php if(empty($binding['users'])):?><p>No active office-scoped receiving user accounts. Provision one through normal account administration first.</p><?php else:?><form method="post"><input type="hidden" name="_csrf" value="<?=ptiE($_SESSION['csrf'])?>"><input type="hidden" name="organization_id" value="<?=ptiE($org)?>"><input type="hidden" name="action" value="bind_receiver"><label>Authorized receiving login</label><select name="user_id" required><option value="">Choose login</option>
<?php foreach($binding['users'] as $row):?><option value="<?=ptiE($row['id'])?>" <?=((int)($binding['binding']['user_id']??0)===(int)$row['id'])?'selected':''?>><?=ptiE($row['full_name'])?></option><?php endforeach;?></select><label>Official delegation / appointment supporting reference</label><input name="reason" minlength="20" maxlength="500" required placeholder="Full official office order / memorandum reference"><button type="submit">Save audited login binding</button></form><?php endif;?></section><?php endif;?>
<?php elseif($view==='inspect'&&$details):$s=$details['snapshot'];$pending=($s['status']??'')==='PENDING_RECEIPT';?>
<section><h2><?=ptiE($details['type'].' '.$details['number'])?> · <?=ptiE($s['status']??'')?></h2><p><b>From:</b> <?=ptiE($s['source_school_name']??'')?> &nbsp; <b>To:</b> <?=ptiE($s['destination_school_name']??'')?></p>
<p class="note">Confirm each physical unit, condition, local catalog mapping, and receiving location. A completed acceptance moves it from the sender's active register into this office's active register while preserving original IAR and accountability history. Paper signatures are not captured here.</p>
<?php if(!$loggedInReceiver&&$pending):?><p class="error">You are not signed in as this office's formally linked Property Custodian. A Super Administrator may configure the login binding but cannot accept property on behalf of the custodian.</p><?php endif;?>
<?php if($pending):?><form method="post"><input type="hidden" name="_csrf" value="<?=ptiE($_SESSION['csrf'])?>"><input type="hidden" name="organization_id" value="<?=ptiE($org)?>"><input type="hidden" name="action" value="accept_transfer"><input type="hidden" name="transfer_id" value="<?=ptiE($details['id'])?>">
<div class="grid"><div><label>Actual physical receipt date</label><input type="date" name="received_on" value="<?=ptiE(date('Y-m-d'))?>" required></div><div><label>Paper handover / signed receipt reference</label><input name="paper_reference" maxlength="240" minlength="8" required></div></div>
<?php foreach($s['items'] as $i=>$item):?>
<div class="item"><div><strong><?=ptiE($item['property_tag'])?> · <?=ptiE($item['description'])?></strong> <span class="pill"><?=ptiE($item['category'])?></span></div>
<div class="muted">Acquisition cost <?=ptiE($item['unit_cost'])?> · Source asset #<?=ptiE($item['asset_id'])?></div><input type="hidden" name="inspection[<?=ptiE($i)?>][source_asset_id]" value="<?=ptiE($item['asset_id'])?>">
<div class="grid"><div><label>Matching receiving-office catalog item</label><select name="inspection[<?=ptiE($i)?>][receiving_item_id]" required><option value="">Choose matching catalog item (add it in Reference Data first if missing)</option>
<?php foreach($options as $op):if($op['category']!==$item['category'])continue;?><option value="<?=ptiE($op['id'])?>" <?=strcasecmp(trim((string)$op['name']),trim((string)$item['description']))===0?'selected':''?>><?=ptiE($op['name'])?></option><?php endforeach;?></select></div><div><label>Condition on receipt</label><select name="inspection[<?=ptiE($i)?>][condition]" required><option value="">Choose observed condition</option><?php foreach(['Serviceable','Needs Maintenance','For Inspection','For Repair','Under Repair'] as $choice):?><option value="<?=ptiE($choice)?>"><?=ptiE($choice)?></option><?php endforeach;?></select></div></div>
<label>Physical receiving location / room</label><input name="inspection[<?=ptiE($i)?>][location]" minlength="2" maxlength="180" required></div>
<?php endforeach;?><label>Optional inspection remarks</label><textarea name="inspection_note" maxlength="500" rows="2"></textarea><p><label><input type="checkbox" name="physical_received" required>I have physically received and inspected every listed property.</label></p><button type="submit" <?=!$loggedInReceiver?'disabled':''?>>Accept all inspected properties</button></form>
<?php else:?><p>Recorded as received on <?=ptiE($s['received_on']??'')?> by the authenticated receiving officer. Open Received Asset Register to review each linked property and its full history.</p><?php endif;?></section>
<?php elseif($view==='received'):?>
<section><h2>Received Asset Register</h2><p class="note">These are active or historically transferred-in assets of this office. Source-school records remain linked and read-only. Open Property History to review the custody chain, original IAR, ICS/PAR, returns, transfer documents, and condition/audit events.</p><div class="scroll"><table><thead><tr><th>Property number</th><th>Item</th><th>Status / condition</th><th>Receiving location</th><th>Original transfer</th><th>Actions</th></tr></thead><tbody>
<?php foreach($received as $item):?><tr><td><?=ptiE($item['property_tag'])?></td><td><?=ptiE($item['item_name'])?></td><td><?=ptiE($item['status'].' · '.$item['condition_note'])?></td><td><?=ptiE($item['location'])?></td><td><?=ptiE($item['provenance']['transfer_type'].' '.$item['provenance']['transfer_number'])?></td><td><a class="link" href="<?=ptiE($base.'&history_asset_id='.(int)$item['id'])?>">Property History</a><?php if($mayAssess&&($item['status']??'')==='ACTIVE'):?> · <a class="link" href="<?=ptiE($directAssessment.'&assess_asset_id='.(int)$item['id'])?>" target="_blank" rel="noopener noreferrer">Assess condition</a><?php endif;?></td></tr><?php endforeach;?><?php if(!$received):?><tr><td colspan="6">No accepted transferred assets in this office.</td></tr><?php endif;?></tbody></table></div></section>
<?php elseif($view==='assess'&&$assessment):?>
<section><h2>Physical Condition Assessment</h2>
<p class="note">Property <b><?=ptiE($assessment['property_tag'])?></b> · <?=ptiE($assessment['item_name'])?> · original transfer <?=ptiE($assessment['transfer_ref'])?>. Record only the result of a genuine post-receipt inspection. Existing transfer and property history remain unchanged.</p>
<p><b>Current recorded condition:</b> <?=ptiE($assessment['condition_note']?:'Not recorded')?> &nbsp; <b>Receiving location:</b> <?=ptiE($assessment['location'])?></p>
<form method="post">
<input type="hidden" name="_csrf" value="<?=ptiE($_SESSION['csrf'])?>"><input type="hidden" name="organization_id" value="<?=ptiE($org)?>"><input type="hidden" name="action" value="assess_condition"><input type="hidden" name="asset_id" value="<?=ptiE($assessment['id'])?>"><input type="hidden" name="expected_condition" value="<?=ptiE($assessment['condition_note']??'')?>">
<div class="grid"><div><label>Actual physical inspection date</label><input type="date" name="inspected_on" value="<?=ptiE((new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'))?>" min="<?=ptiE($assessment['received_on'])?>" max="<?=ptiE((new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'))?>" required></div><div><label>Observed condition after inspection</label><select name="condition" required><option value="">Select observed condition</option><?php foreach(['Serviceable','Needs Maintenance','For Inspection','For Repair','Under Repair'] as $choice):?><option value="<?=ptiE($choice)?>"><?=ptiE($choice)?></option><?php endforeach;?></select></div></div>
<label>Inspection report or repair evidence reference</label><input name="inspection_reference" minlength="8" maxlength="160" placeholder="Signed inspection report / work order reference" required>
<label>Physical findings and reason for changing the recorded condition</label><textarea name="remarks" rows="3" minlength="12" maxlength="500" placeholder="Describe what was inspected, repaired, or re-evaluated" required></textarea>
<p><label><input type="checkbox" name="physically_inspected" required>I confirm the property was physically inspected and the observation and cited evidence are accurate. This electronic record does not replace required official signatures or approvals.</label></p>
<p class="muted">Unserviceable and disposal decisions must use the formal Property Condition & Disposal workflow. Mark Serviceable only when physically justified. Issuing a new ICS/PAR is a separate step.</p>
<button type="submit">Save audited condition assessment</button> <a class="link" href="<?=ptiE($base.'&view=received')?>">Cancel</a>
</form></section>
<?php elseif($view==='history'&&$history):?>
<section><h2>Full property history · <?=ptiE($history['property_number'])?></h2><p class="note">Verified transfer provenance across organizations. Historical property events and official document numbers are readable; source records and supporting files remain unchanged. Sensitive original file attachments require independent access authorization.</p>
<h3>Chain of ownership</h3><div class="scroll"><table><thead><tr><th>School / Division Office</th><th>Asset ID</th><th>Property number</th><th>Origin</th><th>Acquired</th><th>Original value</th></tr></thead><tbody><?php foreach($history['ownership_chain'] as $link):?><tr><td><?=ptiE($link['organization_name'])?></td><td><?=ptiE($link['asset_id'])?></td><td><?=ptiE($link['property_number'])?></td><td><?=ptiE($link['source'])?></td><td><?=ptiE($link['acquired_on'])?></td><td><?=ptiE($link['acquisition_cost'])?></td></tr><?php endforeach;?></tbody></table></div>
<h3>Chronological property events</h3><div class="scroll"><table><thead><tr><th>Date/time</th><th>Organization</th><th>Event / document</th><th>Details</th></tr></thead><tbody><?php foreach($history['timeline'] as $ev):?><tr><td><?=ptiE($ev['on'])?></td><td><?=ptiE($ev['organization_id'])?></td><td><?=ptiE($ev['event'])?></td><td><?=ptiE($ev['details']??'')?><?php if(!empty($ev['from'])||!empty($ev['to'])):?> · <?=ptiE(($ev['from']??'').' → '.($ev['to']??''))?><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php endif;?></main></body></html>