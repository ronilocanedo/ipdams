<?php
declare(strict_types=1);
/** School property lifecycle desk: no permission is inferred from a visible button. */
require_once dirname(__DIR__).'/private/app/Domain.php';
require_once dirname(__DIR__).'/private/app/PropertyLifecycleWorkflow.php';
ini_set('display_errors','0');ini_set('log_errors','1');
$secure=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_samesite','Lax');ini_set('session.cookie_secure',$secure?'1':'0');
session_name('IPDAMSSESSID');session_start();$_SESSION['csrf']??=bin2hex(random_bytes(24));
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
$embedded=(string)($_GET['embedded']??$_POST['embedded']??'')==='1';
header('X-Frame-Options: '.($embedded?'SAMEORIGIN':'DENY'));
header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors ".($embedded?"'self'":"'none'")."; base-uri 'none'");
function plEsc(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$org=(int)($_POST['organization_id']??$_GET['organization_id']??0);
$err='';$note='';$view=null;$admin=null;
try{
    $db=database();$user=currentUser($db);
    demand($user['role']!=='VIEWER','The MOOE viewer role does not have property lifecycle access',403);
    $service=new PropertyLifecycleWorkflow($db,$user);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        demand(is_string($_POST['_csrf']??null) && hash_equals((string)$_SESSION['csrf'],(string)$_POST['_csrf']),'Invalid CSRF token',403);
        $action=(string)($_POST['action']??'');
        if($action==='appoint_officer'){
            $v=$service->saveOfficer((int)($_POST['division_id']??0),(int)($_POST['officer_user_id']??0),trim((string)($_POST['reason']??'')));
            $note='Division Supply Officer appointment recorded (version '.$v['appointment_version_id'].').';
        }else{
            demand($org>0 && (int)($_POST['asset_id']??0)>0,'Choose a school and property',422);
            $r=$service->perform($org,(int)$_POST['asset_id'],$action,$_POST);
            $note='Property '.$r['property_tag'].' recorded as '.$r['state'].' (decision version '.$r['version'].').';
        }
    }
    if($org>0)$view=$service->listForSchool($org);
    if($user['role']==='SUPER_ADMIN')$admin=$service->divisionsForAdmin();
}catch(Throwable $e){
    $err=$e instanceof DomainError?$e->getMessage():'Property lifecycle temporarily unavailable. Contact the technical administrator.';
    if(!($e instanceof DomainError))error_log('IPDAMS property lifecycle exception '.get_class($e).': '.$e->getMessage());
}
$csrf=(string)($_SESSION['csrf']??'');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>IPDAMS · Property Condition & Disposal</title><style>
:root{font:14px system-ui,Arial,sans-serif;color:#20354b;background:#f5f7fa}*{box-sizing:border-box}body{margin:0}.page{max-width:1250px;margin:18px auto;padding:0 12px}.sheet{background:#fff;border:1px solid #d7e2ea;border-radius:10px;margin-bottom:14px;padding:18px}h1{font-size:21px;margin:3px 0 9px}h2{font-size:17px;margin:2px 0 10px}p{line-height:1.45}.hint{color:#576a78}.alert{background:#fef1ef;border:1px solid #e2aea7;border-radius:8px;padding:11px;margin-bottom:10px}.ok{background:#f1f9f5;border:1px solid #93c5ae;border-radius:8px;padding:11px;margin-bottom:10px}table{width:100%;border-collapse:collapse;font-size:13px}th,td{border-bottom:1px solid #e2e8ed;padding:9px;text-align:left;vertical-align:top}.scroll{overflow-x:auto}label{display:grid;gap:5px;font-weight:600;margin:8px 0}input,select,textarea{font:inherit;border:1px solid #adbdcc;border-radius:6px;padding:8px;max-width:100%}input:not([type=hidden]),textarea{width:100%}button{font:inherit;background:#146f78;color:white;border:0;border-radius:6px;padding:9px 12px;cursor:pointer;margin-top:7px}button:focus-visible{outline:3px solid #f4ae4e}form.actions{display:grid;grid-template-columns:1fr;gap:4px;min-width:225px}form.actions textarea{min-height:45px}details{border:1px solid #d9e2eb;border-radius:8px;padding:9px}summary{cursor:pointer;font-weight:700}.sub{font-size:12px;color:#576a78}.nowrap{white-space:nowrap}.headrow{display:flex;justify-content:space-between;gap:14px;align-items:center}.tag{background:#e5f4f2;padding:4px 8px;border-radius:8px;display:inline-block}.state{font-weight:700}.toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:end}.toolbar>label{min-width:210px}.admin{background:#f7faff;padding:12px;border-radius:8px}.admin-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 14px}@media(max-width:750px){.admin-grid{grid-template-columns:1fr}table{font-size:12px}}html.embedded{background:transparent}html.embedded .page{max-width:none;margin:0;padding:0 2px}
</style></head><body class="<?= $embedded?'embedded':'' ?>"><main class="page">
<section class="sheet"><div class="headrow"><h1>Property Condition & Disposal</h1><?php if(!$embedded):?><a href="../index.php?section=Property%20Accountability">Back to IPDAMS</a><?php endif;?></div>
<p class="hint">For semi-expendable property only. Low-value (₱5,000 or less): school handles both unserviceable declaration and disposal. High-value (more than ₱5,000 but below ₱50,000): two separate approvals by the appointed Division Supply Officer. Approval is not the same as completed physical disposal.</p>
<?php if($err):?><div class="alert" role="alert"><?=plEsc($err)?></div><?php endif;?>
<?php if($note):?><div class="ok" role="status"><?=plEsc($note)?></div><?php endif;?>
<form method="get" class="toolbar"><?php if($embedded):?><input type="hidden" name="embedded" value="1"><?php endif;?><label>School workspace<input type="number" min="1" name="organization_id" value="<?=plEsc($org?:'')?>" required></label><button>Open school</button></form>
</section>
<?php if($admin):?><section class="sheet"><details><summary>Technical administrator · Appoint Division Supply Officer</summary><p class="hint">Assign only an existing active Division user with a direct Division authorization. Enter the written appointment/delegation reference. This does not override any legally required disposal authority.</p>
<form method="post"><input type="hidden" name="_csrf" value="<?=plEsc($csrf)?>"><input type="hidden" name="action" value="appoint_officer"><input type="hidden" name="organization_id" value="<?=plEsc($org)?>">
<div class="admin-grid"><label>Division<select name="division_id" required><option value="">Choose division</option><?php foreach($admin['divisions'] as $d):?><option value="<?=plEsc($d['id'])?>"><?=plEsc($d['name'])?><?=!empty($d['assigned'])?' · current user #'.$d['assigned']['user_id']:''?></option><?php endforeach;?></select></label>
<label>Appointed Division user<select name="officer_user_id" required><option value="">Choose directly scoped Division user</option><?php foreach($admin['eligible_users'] as $u):?><option value="<?=plEsc($u['id'])?>">#<?=plEsc($u['id'])?> · <?=plEsc($u['full_name'])?> (division #<?=plEsc($u['division_id'])?>)</option><?php endforeach;?></select></label></div>
<label>Signed authority / appointment reference<input name="reason" minlength="8" maxlength="500" required placeholder="Official appointment or delegation reference"></label><button type="submit">Record Division Supply Officer appointment</button></form></details></section><?php endif;?>
<?php if($view):?><section class="sheet"><h2><?=plEsc($view['school']['name'])?> · Semi-Expendable Lifecycle</h2>
<p class="hint">The underlying school record remains active during unserviceability and while disposal is requested or approved. A DISPOSED record remains searchable as history and is removed from active accountability only after completed disposal is recorded.</p>
<div class="scroll"><table><thead><tr><th>Property</th><th>Value class</th><th>Cost</th><th>Custodian</th><th>Lifecycle</th><th>Next action</th></tr></thead><tbody>
<?php foreach($view['rows'] as $row):
 $st=(string)$row['state'];$low=$row['value_class']==='LOW';$high=$row['value_class']==='HIGH';
 $isSchool=in_array($user['role'],['SCHOOL','SUPER_ADMIN'],true);
 $isOfficer=($user['role']==='DIVISION');
 $actions=[];
 if($st==='SERVICEABLE'&&$isSchool&&($low||$high))$actions=['declare_unserviceable'=>'Declare unserviceable'.($high?' · request division approval':'')];
 elseif($st==='PENDING_UNSERVICEABLE'&&$high&&$isOfficer)$actions=['approve_unserviceable'=>'Approve unserviceable','reject_unserviceable'=>'Reject declaration'];
 elseif($st==='UNSERVICEABLE'&&$isSchool)$actions=['request_disposal'=>'Request disposal'];
 elseif($st==='PENDING_DISPOSAL'&&(($high&&$isOfficer)||($low&&$isSchool)))$actions=['approve_disposal'=>'Approve disposal','reject_disposal'=>'Reject disposal'];
 elseif($st==='DISPOSAL_APPROVED'&&$isSchool)$actions=['complete_disposal'=>'Record completed disposal'];
 ?><tr><td><strong><?=plEsc($row['property_tag'])?></strong><div class="sub"><?=plEsc($row['item_name'])?></div></td><td><?=plEsc($row['value_class'])?></td><td class="nowrap">₱<?=plEsc($row['acquisition_cost']??'—')?></td><td><?=plEsc($row['custodian']?:'Unassigned')?></td><td><span class="state"><?=plEsc($st)?></span><?php if($row['status']==='DISPOSED'):?><div class="sub">Historical record retained</div><?php endif;?></td><td>
<?php if($actions):?><details><summary>Choose an action</summary>
<?php foreach($actions as $action=>$label):?><form method="post" class="actions"><input type="hidden" name="_csrf" value="<?=plEsc($csrf)?>"><input type="hidden" name="embedded" value="<?= $embedded?'1':'0' ?>"><input type="hidden" name="organization_id" value="<?=plEsc($org)?>"><input type="hidden" name="asset_id" value="<?=plEsc($row['id'])?>"><input type="hidden" name="action" value="<?=plEsc($action)?>">
<label class="sub">Reason / inspection or approval basis<textarea name="reason" required minlength="8" maxlength="500" placeholder="Explain and reference supporting documents"></textarea></label>
<?php if($action==='complete_disposal'):?><label class="sub">Disposal completion date<input type="date" name="completed_on" max="<?=plEsc((new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'))?>" required></label><label class="sub">IIRUSP / actual disposal evidence reference<input name="disposal_reference" minlength="8" maxlength="160" required></label><label class="sub">Physical return / custody release reference<?php if(trim((string)$row['custodian'])!==''):?> (required)<?php endif;?><input name="return_reference" minlength="8" maxlength="160" <?=trim((string)$row['custodian'])!==''?'required':''?>></label><?php endif;?><button type="submit"><?=plEsc($label)?></button></form>
<?php endforeach;?></details><?php else:?><span class="sub"><?=($st==='DISPOSED'?'Disposal archived':($st==='REVIEW_COST'?'Resolve valuation before action':'No action for current role/stage'))?></span><?php endif;?></td></tr>
<?php endforeach;?><?php if(!$view['rows']):?><tr><td colspan="6">No semi-expendable property registered for this school.</td></tr><?php endif;?></tbody></table></div></section><?php endif;?>
<section class="sheet"><p class="sub">School disposal approval, Division approval, and physical completion are distinct events. Documents remain paper-signed where required. IPDAMS does not claim an electronic signature or replace any government-mandated committee, delegation, inspection, or disposal procedure.</p></section></main></body></html>
