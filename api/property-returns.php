<?php
declare(strict_types=1);
/** IPDAMS · school-scoped ICS/PAR property return receipt desk. */
require_once dirname(__DIR__).'/private/app/Domain.php';
require_once dirname(__DIR__).'/private/app/PropertyReturnWorkflow.php';
ini_set('display_errors','0');ini_set('log_errors','1');
$https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_samesite','Lax');ini_set('session.cookie_secure',$https?'1':'0');
session_name('IPDAMSSESSID');session_start();$_SESSION['csrf']??=bin2hex(random_bytes(24));
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
$embed=($_GET['embedded']??'')==='1'&&!isset($_GET['print']);
header('X-Frame-Options: '.($embed?'SAMEORIGIN':'DENY'));
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors ".($embed?"'self'":"'none'"));
function rrE(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$user=null;$context=null;$document=null;$error='';$flash='';$print=false;$org=0;
try {
    $db=database();$user=currentUser($db);
    demand(in_array($user['role'],['SCHOOL','SUPER_ADMIN'],true),'Authorized school property staff required',403);
    $org=(int)($_POST['organization_id']??$_GET['organization_id']??0);
    if(!$org){$row=$user['role']==='SUPER_ADMIN'?
        one($db,"SELECT id FROM organizations WHERE kind='SCHOOL' AND active=1 ORDER BY name LIMIT 1"):
        one($db,"SELECT o.id FROM organizations o JOIN user_organization_scopes s ON s.organization_id=o.id WHERE s.user_id=? AND o.kind='SCHOOL' AND o.active=1 ORDER BY o.name LIMIT 1",[$user['id']]);
        demand($row!==null,'No authorized active school',403);$org=(int)$row['id'];}
    scope($db,$user,$org);
    $flow=new PropertyReturnWorkflow($db,$user);
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        demand(hash_equals((string)($_SESSION['csrf']??''),(string)($_POST['_csrf']??'')),'Security token expired; refresh',403);
        demand(($_POST['action']??'')==='receive','Unsupported property return request',400);
        $selection=$_POST['asset_ids']??[];demand(is_array($selection),'Invalid asset selection',422);
        $done=$flow->receiveAll(['organization_id'=>$org,'employee'=>$_POST['employee']??null,'asset_ids'=>array_values($selection),
            'conditions'=>$_POST['conditions']??null,
            'returned_on'=>$_POST['returned_on']??null,'storage_location'=>$_POST['storage_location']??null,
            'reason'=>$_POST['reason']??null,'remarks'=>$_POST['remarks']??'',
            'physical_received'=>($_POST['physical_received']??'')==='1']);
        $_SESSION['property_return_notice']='Returned '.(int)$done['item_count'].' unit(s) to school custody on '.count($done['receipts']).' receipt(s): '.implode(', ',array_map(static fn($r)=>$r['type'].' '.$r['number'],$done['receipts'])).'. Print the unsigned receipt(s) from the Return Register.';
        header('Location: property-returns.php?organization_id='.rawurlencode((string)$org).($embed?'&embedded=1':''),true,303);exit;
    }
    $flash=(string)($_SESSION['property_return_notice']??'');unset($_SESSION['property_return_notice']);
    if(isset($_GET['print'],$_GET['id'])){
        demand($_GET['print']==='receipt','Invalid print request',422);
        $document=$flow->detail((int)$_GET['id'],$org);$print=true;
    }else $context=$flow->context($org,trim((string)($_GET['employee']??'')));
}catch(DomainError $e){http_response_code($e->status);$error=$e->getMessage();}
catch(Throwable $e){http_response_code(500);error_log('IPDAMS property return: '.$e->getMessage());$error='Return operation could not be verified. Refresh the Return Register before resubmitting.';}
if($print&&$document!==null){
    $s=$document['snapshot'];$semi=$document['type']==='RRSP';$name=$semi?'RECEIPT OF RETURNED SEMI-EXPENDABLE PROPERTY':'PROPERTY RETURN RECORD (PPE · INTERNAL DOCUMENT)';
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><title><?=rrE($document['number'].' · '.$name)?></title>
    <link rel="stylesheet" href="../assets/property-returns.css?v=0256"></head><body class="print-receipt"><div class="rr-tools"><button type="button" id="rr-print">Print receipt</button></div>
    <article class="rr-page"><p class="rr-head">Republic of the Philippines<br>Department of Education<br><?=rrE($s['school_name'])?></p>
    <p class="rr-annex"><?=$semi?'Annex A.6':'Internal return documentation · Not COA Annex A.6'?></p>
    <h1><?=rrE($name)?></h1><div class="rr-meta"><p><b>Entity Name:</b> <?=rrE($s['school_name'])?></p><p><b>Date:</b> <?=rrE($s['returned_on'])?></p><p><b><?=rrE($document['type'])?> No.:</b> <?=rrE($document['number'])?></p></div>
    <p>This is to acknowledge receipt of the returned <?= $semi?'Semi-expendable Property':'Property' ?>:</p>
    <table class="rr-table"><thead><tr><th>Item Description</th><th>Qty.</th><th><?=$semi?'ICS':'PAR'?> No.</th><th>End-user</th><th>Condition on return</th><th>Remarks</th></tr></thead><tbody>
    <?php foreach($s['items'] as $item):?><tr><td><?=rrE($item['description'])?><small>Property No.: <?=rrE($item['property_tag'])?></small></td><td class="rr-center">1</td><td><?=rrE($item['ics_or_par_number'])?></td><td><?=rrE($item['end_user'])?></td><td><?=rrE($item['condition_on_return']??'Not recorded')?><?php if(($item['formal_lifecycle_state']??'SERVICEABLE')!=='SERVICEABLE'):?><small>Formal status: <?=rrE($item['formal_lifecycle_state'])?></small><?php endif;?></td><td><?=rrE($item['remarks'])?></td></tr><?php endforeach;?></tbody></table>
    <p><b>Returned property location:</b> <?=rrE($s['storage_location'])?> · <b>Reason:</b> <?=rrE($s['reason'])?></p>
    <div class="rr-sign"><div><b>Returned by:</b><div class="rr-line"><?=rrE($s['returned_by'])?></div><small>End User / Signature over printed name</small><p>Date: _____________</p></div><div><b>Received by:</b><div class="rr-line">______________________</div><small>Head, Property &amp;/or Supply Division/Unit</small><p>Date: _____________</p></div></div>
    <p class="rr-notice">Printed from an audited IPDAMS return record. Original paper signatures and any legally required acknowledgment must be completed manually. This record does not certify a digital signature, condition approval or disposal.</p>
    </article><script src="../assets/property-returns.js?v=0257" defer></script></body></html><?php exit;
}
?>
<!doctype html><html lang="en" class="<?=$embed?'rr-embedded':''?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>IPDAMS · Property Returns</title><link rel="stylesheet" href="../assets/property-returns.css?v=0256"></head><body>
<main class="rr-main">
<header class="rr-title"><div><span class="rr-eyebrow">PROPERTY ACCOUNTABILITY</span><h1>Return Property</h1><p>Receive properties back into school custody. The ICS/PAR remains a permanent historical document; a return closes only the current employee assignment.</p></div></header>
<?php if($error!==''):?><p class="rr-error" role="alert"><?=rrE($error)?></p><?php endif;?>
<?php if($flash!==''):?><p class="rr-ok" role="status"><?=rrE($flash)?></p><?php endif;?>
<?php if($context!==null):?>
<section class="rr-card"><h2>Return property by accountable employee</h2>
<p class="rr-hint">Search for or choose the accountable employee; the list loads automatically. All currently assigned ICS/PAR units for that employee will then appear, with a separate condition selector for every property. <strong>Select all</strong> is convenient when staff leave the school; select only physically returned items.</p>
<form id="rr-employee-form" action="property-returns.php" method="get" class="rr-employee-form">
<input name="organization_id" type="hidden" value="<?=rrE($org)?>">
<?php if($embed):?><input name="embedded" type="hidden" value="1"><?php endif;?>
<label>1. Search / select accountable employee
<input type="search" name="employee" id="rr-employee-search" list="rr-employee-options" value="<?=rrE($context['selected_employee'])?>" placeholder="Type or choose the accountable employee" autocomplete="off" required></label>
<datalist id="rr-employee-options"><?php foreach($context['employees'] as $name):?><option value="<?=rrE($name)?>"></option><?php endforeach;?></datalist>
<noscript><button class="rr-secondary" type="submit">Load assigned properties</button></noscript>
</form>
<p class="rr-hint" id="rr-employee-live" role="status" aria-live="polite"><?php if($context['selected_employee']!==''):?>Showing open assignments for <?=rrE($context['selected_employee'])?>.<?php else:?>Select an employee from the suggested names to load their currently assigned properties automatically.<?php endif;?></p>
<?php if($context['selected_employee']!==''):?>
<form method="post" action="property-returns.php<?= $embed?'?embedded=1':''?>" id="rr-receive-form">
<input type="hidden" name="_csrf" value="<?=rrE($_SESSION['csrf']??'')?>"><input type="hidden" name="organization_id" value="<?=rrE($org)?>"><input type="hidden" name="action" value="receive"><input type="hidden" name="employee" value="<?=rrE($context['selected_employee'])?>">
<div class="rr-row-heading"><strong>2. Assigned properties of <?=rrE($context['selected_employee'])?></strong><span id="rr-selection-count" aria-live="polite">0 of <?=count($context['assets'])?> selected</span></div>
<?php if($context['too_many']):?><p class="rr-error">More than 200 open assignments exist. The first 200 are shown; record them in this batch, then reload this employee to process the remaining properties.</p><?php endif;?>
<div class="rr-bulk-actions"><button type="button" id="rr-select-all" class="rr-secondary">Select all <?=count($context['assets'])?> loaded</button><button type="button" id="rr-select-none" class="rr-secondary">Clear selection</button><span class="rr-hint">Select only units actually returned. Each one has its own observed condition.</span></div>
<div class="rr-list" id="rr-list">
<?php foreach($context['assets'] as $a):?>
<div class="rr-item" data-category="<?=rrE($a['category'])?>">
<label class="rr-item-pick"><input type="checkbox" name="asset_ids[]" value="<?=rrE($a['id'])?>" data-custodian="<?=rrE($a['custodian'])?>" data-category="<?=rrE($a['category'])?>"><span><strong><?=rrE($a['property_tag'])?> · <?=rrE($a['item_name'])?></strong><small><?=rrE($a['category']==='SEMI_EXPENDABLE'?'ICS':'PAR')?> <?=rrE($a['issued_document_number'])?> · <?=rrE($a['location']?:'Location not specified')?></small></span></label>
<div class="rr-condition"><label for="rr-cond-<?=rrE($a['id'])?>">Condition on return</label>
<?php if($a['condition_locked']):?>
<input type="hidden" name="conditions[<?=rrE($a['id'])?>]" value="<?=rrE($a['condition_note'])?>">
<div class="rr-locked-condition" id="rr-cond-<?=rrE($a['id'])?>">Formal status: <?=rrE($a['formal_lifecycle_state'])?></div>
<small>Previous observation: <?=rrE($a['condition_note']?:'Not recorded')?>. Formal status must be changed through Condition &amp; Disposal.</small>
<?php else:?>
<select name="conditions[<?=rrE($a['id'])?>]" id="rr-cond-<?=rrE($a['id'])?>" data-return-condition>
<option value="">Select observed condition</option>
<?php if(trim((string)$a['condition_note'])!==''&&!in_array($a['condition_note'],['Serviceable','Needs Maintenance','For Inspection','For Repair','Under Repair'],true)):?><option value="<?=rrE($a['condition_note'])?>" selected>Existing record: <?=rrE($a['condition_note'])?></option><?php endif;?>
<?php foreach(['Serviceable','Needs Maintenance','For Inspection','For Repair','Under Repair'] as $condition):?><option value="<?=rrE($condition)?>"<?=$a['condition_note']===$condition?' selected':''?>><?=rrE($condition)?></option><?php endforeach;?>
</select>
<small>Current record: <?=rrE($a['condition_note']?:'Not recorded')?>. Update if physical inspection differs.</small>
<?php endif;?></div>
</div><?php endforeach;?>
<?php if(!$context['assets']):?><p class="rr-hint">No open ICS/PAR assignments remain for this employee.</p><?php endif;?></div>
<p class="rr-hint" id="rr-selection-note">0 properties selected. RRSP and PPE returns are documented separately; groups of up to 20 units receive their own receipts.</p>
<div class="rr-fields"><label>Actual return date<input name="returned_on" type="date" max="<?=rrE((new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'))?>" value="<?=rrE((new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'))?>" required></label>
<label>School storage location<input name="storage_location" maxlength="180" required minlength="2" placeholder="e.g. School Supply Office / Storage Room"></label></div>
<label>Return reason / supporting basis<textarea name="reason" minlength="8" maxlength="500" required placeholder="e.g. Employee separation / clearance"></textarea></label>
<label>Remarks (optional)<textarea name="remarks" maxlength="500" placeholder="Other return observations. Formal unserviceable declaration requires Condition & Disposal."></textarea></label>
<label class="rr-verify"><input type="checkbox" name="physical_received" value="1" required><span>I confirm the selected units were actually physically received, and their individual observed conditions were checked. Paper acknowledgment remains required.</span></label>
<button class="rr-primary" type="submit" id="rr-save" disabled>Record selected property returns</button>
</form>
<?php else:?><p class="rr-hint">Choose an accountable employee above; their assigned properties load automatically. The return register remains available below.</p><?php endif;?>
</section>
<section class="rr-card"><h2>Return Register</h2><div class="rr-scroll"><table><thead><tr><th>Number</th><th>Type</th><th>Date</th><th>Returned by</th><th>Count</th><th>Document</th></tr></thead><tbody>
<?php foreach($context['receipts'] as $r):?><tr><td><?=rrE($r['number'])?></td><td><?=rrE($r['type'])?></td><td><?=rrE($r['date'])?></td><td><?=rrE($r['from'])?></td><td><?=rrE($r['count'])?></td><td><a href="property-returns.php?organization_id=<?=rrE($org)?>&amp;print=receipt&amp;id=<?=rrE($r['id'])?>" target="_blank" rel="noopener noreferrer">Print <?=rrE($r['type'])?></a></td></tr><?php endforeach;?>
<?php if(!$context['receipts']):?><tr><td colspan="6">No property-return receipts recorded for this school.</td></tr><?php endif;?></tbody></table></div></section>
<?php endif;?>
<p class="rr-hint">Returning an item does not mark it unserviceable, dispose of it or change earlier finalized ICS/PAR documents. Reissuance is possible after school custody is recorded, subject to ordinary review and lifecycle checks.</p>
</main><script src="../assets/property-returns.js?v=0257" defer></script></body></html>
