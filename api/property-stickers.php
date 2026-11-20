<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/app/bootstrap.php';
require_once dirname(__DIR__).'/private/app/PropertyStickerTemplates.php';
ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');
session_name('IPDAMSSESSID');session_start();
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; script-src 'self'; style-src 'self'; connect-src 'self'; form-action 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
try {
    $db=database();$user=currentUser($db);
    $orgId=idOf($_GET+$_POST,'organization_id');
    $org=PropertyStickerTemplates::context($db,$user,$orgId);
    $mode=(string)($_GET['mode']??'page');
    if($_SERVER['REQUEST_METHOD']==='POST'){
        demand(in_array($mode,['template.upload','template.reset'],true),'Unsupported property sticker action',405);
        csrfCheck();
        $result=$mode==='template.reset'?PropertyStickerTemplates::reset($db,$user,$org):
            PropertyStickerTemplates::save($db,$user,$org,$_FILES['template']??[]);
        jsonOut($result);
    }
    demand($_SERVER['REQUEST_METHOD']==='GET','Unsupported method',405);
    if($mode==='data')jsonOut(['organization'=>$org,'template'=>PropertyStickerTemplates::effective($db,$user,$org),
        'page'=>PropertyStickerTemplates::search($db,$org,$_GET)]);
    if($mode==='image'){
        $img=PropertyStickerTemplates::image($db,$user,$org);
        header('Content-Type: '.$img['mime']);header('ETag: "'.$img['sha256'].'"');
        readfile($img['path']);exit;
    }
    demand($mode==='page','Unknown sticker view',404);
} catch(Throwable $e) {
    $status=$e instanceof DomainError?$e->status:500;
    http_response_code($status);
    if(($mode??'page')==='page'){
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Property stickers</title><p>Access unavailable. Return to <a href="../index.php">IPDAMS</a>.</p></html>';
    }else{header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>$e instanceof DomainError?$e->getMessage():'Property sticker request failed']);}
    exit;
}
header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html lang="en"><head><base href="../"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>IPDAMS · Property Sticker Printing</title>
<link rel="stylesheet" href="assets/property-stickers-400.css">
<script src="assets/property-sticker-qr-383.js" defer></script>
<script src="assets/property-stickers-400.js" defer></script></head>
<body data-org="<?= (int)$orgId ?>" data-orgcode="<?= htmlspecialchars((string)$org['code'],ENT_QUOTES,'UTF-8') ?>">
<header class="bar"><h1>Property Sticker Printing</h1><a href="index.php">← Back to IPDAMS</a></header>
<main><p class="hint">Select recorded assets, review sticker details, and print nine portrait stickers per 8.5 × 13-inch sheet. Printing never changes property records.</p>
<section class="controls"><h2>Registered property</h2><label>Search property / description / serial / custodian <input id="search" maxlength="100" placeholder="Search current property"></label>
<button id="load" type="button">Search</button><button id="more" type="button" hidden>Load more</button>
<span id="count">0 selected</span><div id="assets" class="asset-list"></div></section>
<section class="controls"><h2>Sticker template</h2><p id="source" class="hint"></p>
<form id="template-form" hidden><label>Upload portrait sticker-background image (PNG/JPG/WEBP, up to 5 MB) <input type="file" id="template" name="template" accept="image/png,image/jpeg,image/webp" required></label>
<button type="submit">Save this office's template</button><button id="reset-template" type="button" hidden>Remove this office's template</button><p class="hint">A school's own template overrides its Division Office template. If absent, the Division template is used automatically. Keep text/QR areas clear in your background image.</p></form>
<p class="hint">The built-in DepEd reference layout uses the provided building template with compact navy-and-gold rows and a bottom-right QR. Uploaded office backgrounds remain available; schools may use the Division background when no school template exists.</p>
<label>Sticker background <select id="background-choice"><option value="reference">DepEd reference design (recommended)</option><option value="uploaded" id="background-uploaded" disabled>Office / Division uploaded background</option></select></label>
<div class="extras"><label>Additional field 1 label<input id="extra1-label" maxlength="28" placeholder="e.g., Location"></label><label>Additional field 1 value<input id="extra1-value" maxlength="75"></label>
<label>Additional field 2 label<input id="extra2-label" maxlength="28"></label><label>Additional field 2 value<input id="extra2-value" maxlength="75"></label></div>
<button id="preview" type="button">Preview selected stickers</button><button id="print" type="button" disabled>Print 8.5 × 13 in</button>
<p id="message" role="status" aria-live="polite"></p></section>
<div id="sheets" aria-label="Print sticker pages"></div>
</main><footer class="hint">Property sticker printing is a working identification aid; it does not issue or amend ICS/PAR documents.</footer>
</body></html>
