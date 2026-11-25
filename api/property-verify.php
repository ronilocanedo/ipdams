<?php
declare(strict_types=1);
/** Public, deliberately minimal IPDAMS property QR verification. No authentication bypass for asset APIs. */
require_once dirname(__DIR__).'/private/app/bootstrap.php';
require_once dirname(__DIR__).'/private/app/PropertyQrVerification.php';
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
header('Content-Type: text/html; charset=utf-8');
$state='unverified';$data=null;$note='This QR could not be matched to a current IPDAMS verification link.';
try {
    if(maintenancePublicState()['active']) {
        $state='maintenance';
        $note='Property verification is temporarily unavailable during scheduled system maintenance.';
        http_response_code(503);
        header('Retry-After: 600');
    } else {
        $data=PropertyQrVerification::verify(database(),(string)($_GET['p']??''),(string)($_GET['s']??''));
        if($data!==null){$state='verified';$note='This QR matches a property record in IPDAMS.';}
        else http_response_code(404);
    }
} catch(Throwable $e) {
    error_log('IPDAMS QR verification unavailable: '.get_class($e));
    $state='unavailable';$note='Verification is temporarily unavailable. Please try again or contact the issuing office.';
    http_response_code(503);
}
function qrEsc(mixed $s): string {return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Property Verification · IPDAMS</title><link rel="stylesheet" href="../assets/property-verify-409.css?v=0410"></head>
<body><main class="verification" aria-labelledby="heading">
<header class="branding"><img class="brand-mark" src="../api/branding.php?kind=icon" alt="IPDAMS application logo" width="44" height="44"><div><strong>IPDAMS</strong><span>Integrated Procurement, Disbursement and Asset Management System</span></div></header>
<div class="status <?=qrEsc($state)?>" role="status"><?= $state==='verified'?'✓ Record verified':($state==='maintenance'?'Scheduled maintenance':($state==='unavailable'?'Verification unavailable':'Record not verified')) ?></div>
<h1 id="heading"><?= $state==='verified'?'IPDAMS Property Verification':($state==='maintenance'?'System maintenance':'Property QR verification') ?></h1>
<p class="intro"><?=qrEsc($note)?></p>
<?php if($data!==null): ?>
<dl>
<div><dt>Property Number</dt><dd><?=qrEsc($data['property_number'])?></dd></div>
<div><dt><?= $data['office_kind']==='SCHOOL'?'School':'Office' ?></dt><dd><?=qrEsc($data['office'])?></dd></div>
<div><dt>Property Description</dt><dd><?=qrEsc($data['description'])?></dd></div>
<?php if($data['date_acquired']!==''): ?><div><dt>Date acquired</dt><dd><?=qrEsc($data['date_acquired'])?></dd></div><?php endif; ?>
<?php if($data['brand']!==''): ?><div><dt>Brand</dt><dd><?=qrEsc($data['brand'])?></dd></div><?php endif; ?>
<?php if($data['model']!==''): ?><div><dt>Model</dt><dd><?=qrEsc($data['model'])?></dd></div><?php endif; ?>
<div><dt>Record status</dt><dd><?= $data['record_current']?'Registered in IPDAMS':'Transferred — contact the issuing office' ?></dd></div>
</dl>
<p class="fineprint">Verification confirms that the scanned link matches an IPDAMS property record at the time of scanning. It does not certify the physical item or authorize its possession or transfer.</p>
<?php else: ?>
<p class="fineprint">For verification assistance, contact the school or Division Office that issued the property sticker.</p>
<?php endif; ?>
<?php if($data!==null && $data['record_current']): ?>
<a class="login" href="property-scan.php?p=<?=rawurlencode((string)($_GET['p']??''))?>&amp;s=<?=rawurlencode((string)($_GET['s']??''))?>">Authorized staff: record physical inspection</a>
<p class="fineprint">This requires a real IPDAMS staff login and a second confirmation by the original assessor in Action Center. The official condition does not change when an assessment is submitted.</p>
<?php endif; ?>
<a class="login" href="../index.php">Sign in to IPDAMS for authorized records</a>
<footer>IPDAMS · Philippine DepEd schools and Division Offices</footer>
</main></body></html>
