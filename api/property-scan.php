<?php
declare(strict_types=1);
/** Authenticated QR assessment + same-person confirmation from Action Center. Public QR URL is only a locator. */
require_once dirname(__DIR__).'/private/app/Domain.php';
require_once dirname(__DIR__).'/private/app/PropertyQrVerification.php';
require_once dirname(__DIR__).'/private/app/PropertyTransferAcceptance.php';
require_once dirname(__DIR__).'/private/app/PropertyScanAssessment.php';
ini_set('display_errors','0');ini_set('log_errors','1');
$https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');ini_set('session.cookie_secure',$https?'1':'0');
session_name('IPDAMSSESSID');session_start();
$_SESSION['csrf']??=bin2hex(random_bytes(24));
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
$mode=(string)($_GET['mode']??'page');
$method=$_SERVER['REQUEST_METHOD']??'GET';
try {
    $db=database();
    $isReview=$mode==='review'||$mode==='decide';
    if(!$isReview){
        $raw=(string)($_GET['p']??'');$sign=(string)($_GET['s']??'');
        demand(preg_match('/^[1-9][0-9]{0,17}$/D',$raw)===1,'Invalid QR property link',404);
        $public=PropertyQrVerification::verify($db,$raw,$sign);
        demand($public!==null,'Invalid or outdated QR verification link',404);
        $assetId=(int)$raw;
    } else {$assetId=idOf($_GET,'asset_id');}
    $signedIn=true;
    try{$user=currentUser($db);}catch(DomainError $e){
        if($method==='GET'&&$e->status===401){$signedIn=false;$user=null;}
        else throw $e;
    }
    if($method==='POST'){
        csrfCheck();demand($signedIn,'Sign in to the real IPDAMS account first',401);
        $payload=json_decode((string)file_get_contents('php://input'),true,64,JSON_THROW_ON_ERROR);
        demand(is_array($payload),'Invalid assessment form',422);
        $workflow=new PropertyScanAssessment($db,$user);
        if($mode==='submit')jsonOut($workflow->submit($assetId,$payload));
        if($mode==='decide')jsonOut($workflow->decision(idOf($_GET,'organization_id'),$assetId,$payload));
        throw new DomainError('Unsupported assessment action',405);
    }
    demand($method==='GET'&&in_array($mode,['page','review'],true),'Unsupported request',405);
    $data=null;
    if($signedIn){$flow=new PropertyScanAssessment($db,$user);
        $data=$mode==='review'?$flow->review(idOf($_GET,'organization_id'),$assetId):$flow->preview($assetId);
    }
} catch(Throwable $e){
    $code=$e instanceof DomainError?$e->status:500;http_response_code($code);
    if($method==='POST'){
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error'=>$e instanceof DomainError?$e->getMessage():'Could not save the inspection; no condition change was confirmed'],JSON_THROW_ON_ERROR);
        exit;
    }
    $error=$e instanceof DomainError?$e->getMessage():'The property inspection service is temporarily unavailable';
    $data=null;$signedIn=false;
}
function scanEscape(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
header('Content-Type: text/html; charset=UTF-8');
?><!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Property Inspection · IPDAMS</title><link rel="stylesheet" href="../assets/property-scan-411.css?v=0411"><script defer src="../assets/property-scan-411.js?v=0411"></script>
</head><body data-mode="<?=scanEscape($mode)?>"><main class="scan-wrap">
<header class="scan-brand"><img src="branding.php?kind=icon" width="44" height="44" alt="IPDAMS logo"><div><strong>IPDAMS</strong><small>Integrated Procurement, Disbursement and Asset Management System</small></div></header>
<?php if(isset($error)): ?>
<section class="scan-panel"><h1>Property inspection unavailable</h1><p role="alert"><?=scanEscape($error)?></p><a href="../index.php">Return to IPDAMS</a></section>
<?php elseif(!$signedIn): ?>
<section class="scan-panel"><h1>Authorized property inspection</h1>
<p>A genuine QR link identifies a property record; scanning alone is not proof of a physical inspection. Sign in with your authorized IPDAMS school or Division property account to record an inspection. Then open Action Center in IPDAMS to review and confirm your own findings.</p>
<form id="scan-login" class="scan-form"><label>IPDAMS email<input name="email" type="email" autocomplete="username" required></label>
<label>Password<input name="password" type="password" autocomplete="current-password" required></label>
<button type="submit">Sign in securely</button></form><p id="scan-status" role="status"></p>
<p class="scan-hint">This uses the existing IPDAMS sign-in service. No separate QR account or public editing is permitted.</p></section>
<?php else: ?>
<section class="scan-panel" data-asset="<?= (int)$assetId ?>" data-org="<?=(int)$data['organization_id']?>" data-version="<?=scanEscape($data['version']??$data['request_version'])?>">
<h1><?= $mode==='review'?'Review proposed condition':'Record physical inspection' ?></h1>
<p class="scan-tag"><?=scanEscape($data['property_number'])?></p>
<p><strong><?=scanEscape($data['description'])?></strong></p>
<?php if($mode==='page'): ?>
<p>Office: <?=scanEscape($data['office'])?> · Current condition: <?=scanEscape($data['condition']?:'Unspecified')?></p>
<?php if($data['pending']): ?><p class="scan-warning">This property already has a pending inspection. The original assessor must review it in Action Center before another inspection is submitted.</p><?php else: ?>
<form id="scan-propose" class="scan-form">
<label>Observed condition<select name="condition" required><option value="">Choose condition</option>
<?php foreach($data['conditions'] as $condition): ?><option value="<?=scanEscape($condition)?>"><?=scanEscape($condition)?></option><?php endforeach; ?></select></label>
<label>Inspection date<input type="date" name="inspected_on" required max="<?=date('Y-m-d')?>" value="<?=date('Y-m-d')?>"></label>
<label>Evidence / inspection reference<input name="inspection_reference" minlength="8" maxlength="160" placeholder="Inspection log, photo reference or signed report" required></label>
<label>Physical observations<textarea name="remarks" minlength="12" maxlength="500" rows="3" required></textarea></label>
<label class="scan-check"><input type="checkbox" name="physically_inspected" required> I personally inspected the physical item. I understand that scanning its QR alone does not prove its condition.</label>
<button type="submit">Save inspection for Action Center review</button></form><?php endif; ?>
<p class="scan-hint">Unserviceable/disposal declarations must use the formal Property Condition & Disposal workflow. Submitting does not change the official condition.</p>
<?php else: ?>
<p>Current condition: <strong><?=scanEscape($data['current_condition']?:'Unspecified')?></strong></p>
<p>Proposed condition: <strong><?=scanEscape($data['proposed_condition'])?></strong></p>
<dl><div><dt>Before proposal</dt><dd><?=scanEscape($data['from_condition']?:'Unspecified')?></dd></div>
<div><dt>Inspection date</dt><dd><?=scanEscape($data['inspected_on'])?></dd></div>
<div><dt>Inspection reference</dt><dd><?=scanEscape($data['inspection_reference'])?></dd></div>
<div><dt>Physical findings</dt><dd><?=scanEscape($data['remarks'])?></dd></div></dl>
<p class="scan-hint">You are confirming your own previously recorded inspection. This is an authenticated second step, not an independent approval or an electronic signature.</p>
<form id="scan-decide" class="scan-form"><label>Confirmation / correction reason<textarea name="review_reason" rows="3" minlength="8" maxlength="500" required></textarea></label>
<div class="scan-actions"><button type="submit" name="decision" value="APPROVE">Confirm and apply condition</button><button type="submit" name="decision" value="REJECT" class="scan-secondary">Discard assessment</button></div></form>
<?php endif; ?><p id="scan-status" role="status"></p>
<p><a href="../index.php?section=Action+Center">Return to IPDAMS Action Center</a></p></section>
<?php endif; ?></main></body></html>
