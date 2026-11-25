<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/app/bootstrap.php';
require_once dirname(__DIR__).'/private/app/Bir2307.php';
ini_set('session.cookie_httponly','1');session_name('IPDAMSSESSID');session_start();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
$escape=static fn($v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
try{
    $db=database();$actor=currentUser($db);
    $org=idOf($_GET,'organization_id');
    // Organization Profile uses the same audited, versioned BIR configuration
    // as certificate readiness. Do not require a finalized DV to configure it.
    $profileMode=($_GET['profile']??'')==='1';
    if($profileMode){
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            csrfCheck();
            demand(str_contains(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json'),'JSON body required.',415);
            $raw=file_get_contents('php://input');demand(strlen($raw)<=4096,'Payor identity request too large.',413);
            $payload=json_decode($raw,true,16,JSON_THROW_ON_ERROR);demand(is_array($payload),'Invalid request.',422);
            jsonOut(Bir2307::saveProfile($db,$actor,$org,$payload));
        }
        demand(($_SERVER['REQUEST_METHOD']??'GET')==='GET','Unsupported method.',405);
        jsonOut(Bir2307::profile($db,$actor,$org));
    }
    $dv=idOf($_GET,'dv_id');
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        csrfCheck();
        demand(str_contains(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json'),'JSON body required.',415);
        $raw=file_get_contents('php://input');demand(strlen($raw)<=4096,'Payor identity request too large',413);
        $payload=json_decode($raw,true,16,JSON_THROW_ON_ERROR);demand(is_array($payload),'Invalid request.',422);
        jsonOut(Bir2307::savePayor($db,$actor,$org,$dv,$payload));
    }
    demand(($_SERVER['REQUEST_METHOD']??'GET')==='GET','Unsupported method.',405);
    $data=Bir2307::read($db,$actor,$org,$dv);
    $csrf=(string)($_SESSION['csrf']??'');
} catch(Throwable $e){
    $status=$e instanceof DomainError?$e->status:500;http_response_code($status);
    if($status===500)error_log('IPDAMS BIR2307 print-prep failure '.get_class($e));
    $msg=$status===500?'Cannot prepare BIR 2307. Contact the administrator.':$e->getMessage();
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST' || (($_GET['profile']??'')==='1')){header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>$msg]);exit;}
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>BIR 2307 preparation</title><main style="max-width:680px;margin:50px auto;padding:24px;font-family:Arial,sans-serif"><h1>BIR 2307 preparation</h1><p>'.htmlspecialchars($msg,ENT_QUOTES,'UTF-8').'</p><p><a href="../index.php?section=Disbursements" onclick="if(window.parent!==window){window.parent.postMessage({type:&quot;IPDAMS_BIR2307_CLOSE&quot;},location.origin);return false;}">Return to Disbursements</a></p></main>';exit;
}
header('Content-Type: text/html; charset=utf-8');
$ready=!empty($data['ready']);
$embedded=($_GET['embedded']??'')==='1';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BIR Form 2307 - <?= $escape($data['dv']['number']) ?></title>
<style>
:root{font-family:system-ui,'Segoe UI',Arial,sans-serif;color:#17354b;background:#f2f6fa}*{box-sizing:border-box}body{margin:0}main{max-width:1020px;margin:24px auto;padding:0 16px 40px}header{padding:16px 0;border-bottom:1px solid #cedde3;display:flex;justify-content:space-between;gap:12px;align-items:center}h1{font-size:24px;margin:6px 0}h2{font-size:18px;margin:0 0 12px}.muted{color:#55758a}small{font-size:12px}section{background:white;border:1px solid #d6e5eb;padding:20px;border-radius:12px;margin-top:14px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.field{display:flex;flex-direction:column;gap:6px}.field label{font-size:13px;font-weight:650}.field input,.field textarea{font:inherit;padding:10px;border:1px solid #bccfdb;border-radius:6px;width:100%}.span2{grid-column:1/-1}button,.btn{padding:10px 14px;background:#087d80;color:white;border:0;border-radius:7px;cursor:pointer;font-weight:650;text-decoration:none;display:inline-block}button:disabled{opacity:.55;cursor:not-allowed}.btn.alt{background:white;border:1px solid #beced9;color:#17445c}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.notice{border-left:4px solid #3bbdaf;background:#edf8f6;padding:12px 14px;font-size:13px;line-height:1.55}.warn{border-left-color:#d47b15;background:#fff7e9}table{width:100%;border-collapse:collapse;font-size:13px}td,th{border-bottom:1px solid #e0e9ed;padding:9px 7px;text-align:left}th{background:#edf5f7}.money{text-align:right;white-space:nowrap}ul{padding-left:24px}li{margin:6px 0}code{white-space:normal;overflow-wrap:anywhere}#status{margin-top:12px;font-size:13px}a{color:#087d80}.pdf-preview{margin-top:14px;background:#e9f1f5;border:1px solid #c9d8e0;border-radius:10px;padding:10px;overflow:hidden}.pdf-preview iframe{display:block;width:100%;height:min(76vw,960px);min-height:620px;border:0;background:#fff;border-radius:5px}.pdf-preview .preview-label{display:block;margin:0 0 8px;font-size:13px;font-weight:650;color:#305568}.pdf-actions button{min-width:130px}.pdf-actions button.alt{background:#fff;border:1px solid #b2cbd7;color:#17445c}@media(max-width:640px){main{margin:0 auto;padding:0 10px 30px}section{padding:14px}.grid{grid-template-columns:1fr}.span2{grid-column:auto}h1{font-size:20px}header{align-items:start}.scroll{overflow-x:auto}.pdf-preview{padding:5px}.pdf-preview iframe{height:75vh;min-height:430px}.pdf-actions button{min-width:0;flex:1}}
.is-embedded main{max-width:none;margin:0;padding:0 12px 18px}.is-embedded main>header{display:none}.is-embedded section{margin-top:8px}.is-embedded .pdf-preview iframe{height:780px;min-height:430px}@media(max-width:640px){.is-embedded main{padding:0 7px 18px}.is-embedded .pdf-preview iframe{height:68vh;min-height:340px}}
</style></head><body class="<?= $embedded ? 'is-embedded' : '' ?>"><main>
<header><div><small class="muted">IPDAMS / Disbursements / Read-only certificate preparation</small><h1>BIR Form 2307 - Quarter <?= $escape($data['period']['from']) ?> to <?= $escape($data['period']['to']) ?></h1><small class="muted">Selected DV No. <?= $escape($data['dv']['number']) ?> - only this DV's saved tax components</small></div><a href="../index.php?section=Disbursements" class="btn alt">Return to Disbursements</a></header>
<section><h2>Certificate readiness</h2>
<?php if($ready): ?><p class="notice" role="status">Required identities, source tax classifications and payment-release checks are present. Review the printed form before signing or issuing it.</p>
<?php else: ?><div class="notice warn" role="alert"><b>Not ready to issue BIR 2307.</b> Correct the following items:
<ul><?php foreach($data['issues'] as $issue): ?><li><?= $escape($issue) ?></li><?php endforeach ?></ul></div><?php endif ?>
<p class="muted"><small><?php if($data['payor']['classification']==='NON_IUS'): ?>For this <strong>Non-IUS school</strong>, the registered payor is its <strong>Division Office</strong> (Division registered TIN, name, address and ZIP). The authorized signatory remains this <strong>School's Head of Office</strong>, with that employee's personal TIN.<?php elseif($data['payor']['classification']==='IUS'): ?>For this <strong>IUS school</strong>, the registered payor is the <strong>School itself</strong>, using its own registered TIN and address. The School Head signs using their own employee TIN.<?php else: ?>The registered payor must be verified against the school's IUS/Non-IUS classification. The appointed Head of Office is the certificate signatory and uses their personal TIN.<?php endif ?></small></p>
<div class="grid">
 <div><strong>Payee</strong><div><?= $escape($data['supplier']['name']) ?></div><small class="muted">TIN: <?= $escape($data['supplier']['tin']?:'Missing') ?> - ZIP: <?= $escape($data['supplier']['zip']?:'Missing') ?></small><p class="muted"><small><?= $escape($data['supplier']['address']?:'Missing address') ?></small></p></div>
 <div><strong>Payor</strong><div><?= $escape($data['payor']['registered_name']) ?></div><small class="muted">Registered TIN: <?= $escape($data['payor']['tin']?:'Missing') ?></small><p><strong>Head of Office (signatory)</strong><br><?= $escape($data['head']['name']??'Not appointed') ?> - <?= $escape($data['head']['designation']??'') ?><br><small class="muted">Employee TIN: <?= $escape($data['head']['tin']??'Missing') ?></small></p></div>
</div>
<?php if($data['unreleased_dvs']): ?><p class="notice warn"><b>Payment evidence incomplete:</b> source DV(s) <?= $escape(implode(', ',$data['unreleased_dvs'])) ?> do not have a released/cleared check in the recorded check register. Certificate issuance must await accounting verification.</p><?php endif ?>
<?php if($ready): ?>
<div class="actions pdf-actions"><button id="print" type="button" disabled>Print A4</button><button id="download" type="button" class="alt" disabled>Download A4 PDF</button><button id="retry-preview" type="button" class="alt">Refresh preview</button></div>
<p id="status" role="status" aria-live="polite">Preparing A4 print preview. No download will begin automatically.</p>
<div class="pdf-preview"><span class="preview-label">Form 2307 preview - A4 portrait, near-edge fit (210 x 297 mm)</span><iframe id="certificate-preview" title="BIR Form 2307 A4 PDF preview" referrerpolicy="no-referrer"></iframe></div>
<p class="muted"><small>The complete January 2018 (ENCS) legal-size form is scaled proportionally to A4 with approximately 2 mm top/bottom PDF margins (side margins follow the original form proportions); no rows are removed or tax figures recalculated. Check legibility and confirm the receiving office accepts A4 before official issuance.</small></p>
<?php else: ?>
<p id="status" role="status" aria-live="polite">Complete the readiness items above before a certificate can be previewed, printed or downloaded.</p>
<?php endif ?>
<p class="muted"><small>Uses the supplied BIR 2307 January 2018 (ENCS) reference. Generated PDFs contain printed signatory details but no electronic signature. Certificates are subject to authorized review and signing; printing never posts funds, adjusts taxes, or proves remittance. Coordinate/layout and current BIR template applicability must be approved before official issuance.</small></p></section>
<section><h2>Registered payor identity - <?= $escape($data['payor']['source_kind']?:'Unverified') ?><?php if($data['payor']['classification']==='NON_IUS'): ?> (shared Division TIN)<?php endif ?></h2>
<p class="muted"><small>These details belong to <strong><?= $escape($data['payor']['registered_name']) ?></strong>. The authorized signatory always comes from the source School's Head of Office and that employee's TIN; the signatory's personal TIN must not replace the registered payor TIN.</small></p>
<?php if($data['payor']['editable']): ?>
<?php if($data['payor']['classification']==='NON_IUS'): ?><p class="notice">Division administrator: you are updating the <strong>Division Office's shared registered BIR identity</strong> for all linked Non-IUS schools. This is not a school-specific identity.</p><?php endif ?>
<form id="identity"><div class="grid"><div class="field span2"><label for="registered_name">Registered BIR name*</label><input id="registered_name" name="registered_name" required maxlength="200" value="<?= $escape($data['payor']['registered_name']) ?>" autocomplete="organization"></div><div class="field"><label for="tin">Registered office TIN (12 or 14 digits)*</label><input id="tin" name="tin" required maxlength="22" value="<?= $escape($data['payor']['tin']) ?>" autocomplete="off"></div><div class="field"><label for="zip">Office ZIP*</label><input id="zip" name="zip" required maxlength="6" value="<?= $escape($data['payor']['zip']) ?>"></div><div class="field span2"><label for="address">Office registered address*</label><textarea id="address" name="address" required rows="2" maxlength="400"><?= $escape($data['payor']['address']) ?></textarea></div></div><div class="actions"><button type="submit" id="save">Save registered office identity</button></div></form>
<?php else: ?>
<p class="notice warn" role="note"><?php if($data['payor']['classification']==='NON_IUS'): ?>This Non-IUS school inherits its registered BIR payor details from the Division Office. A Division administrator or the Super Administrator must configure the Division identity. School users cannot change the shared TIN here.<?php elseif(!$data['payor']['classification']): ?>Have the Division administrator first verify the school's IUS/Non-IUS classification for the DV date.<?php else: ?>You are not authorized to edit this office's registered BIR identity.<?php endif ?></p>
<?php endif ?>
</section>
<section><h2>Original saved withholding details - selected DV only</h2><p class="muted"><small>Only DV No. <?= $escape($data['dv']['number']) ?> is included. Other transactions with the same supplier are excluded even when dated within this quarter. The monthly columns show this DV's saved tax bases; no tax rate or withholding amount is recalculated.</small></p>
<div class="scroll"><table><thead><tr><th>Type</th><th>ATC</th><th>Description</th><th class="money">Month 1</th><th class="money">Month 2</th><th class="money">Month 3</th><th class="money">Saved income base</th><th class="money">Saved withheld</th></tr></thead><tbody>
<?php foreach($data['rows'] as $row): ?><tr><td><?= $escape($row['kind']) ?></td><td><?= $escape($row['atc']) ?></td><td><?= $escape($row['description']) ?></td><?php foreach($row['monthly'] as $month): ?><td class="money"><?= $escape($month) ?></td><?php endforeach ?><td class="money"><?= $escape($row['total']) ?></td><td class="money"><?= $escape($row['withheld']) ?></td></tr><?php endforeach ?>
<?php if(!$data['rows']): ?><tr><td colspan="8">No eligible saved component lines.</td></tr><?php endif ?></tbody></table></div><p><small class="muted">Selected voucher: <?= $escape($data['dv']['number']) ?>. BIR 2307 is a creditable-withholding certificate, not a Division bank receipt or BIR tax-remittance confirmation.</small></p></section>
</main>
<script src="../assets/pdf-lib-1.17.1.min.js"></script>
<script src="../assets/bir2307-template-data.js"></script>
<script>
const model=<?=json_encode($data,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE)?>;
const csrf=<?=json_encode($csrf,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const statusEl=document.getElementById('status');
if(window.parent!==window){
 document.addEventListener('keydown',event=>{if(event.key==='Escape'){
  event.preventDefault();window.parent.postMessage({type:'IPDAMS_BIR2307_CLOSE'},location.origin);
 }});
}
// The ?print=1 route remains supported as a preview shortcut; never auto-download.
document.getElementById('identity')?.addEventListener('submit',async e=>{e.preventDefault();const save=document.getElementById('save');save.disabled=true;statusEl.textContent='Saving...';try{
 const data={registered_name:document.getElementById('registered_name').value,tin:document.getElementById('tin').value,address:document.getElementById('address').value,zip:document.getElementById('zip').value,expected_version:model.payor.version_id,expected_payor_organization_id:model.payor.source_organization_id};
 const response=await fetch(location.href,{method:'POST',headers:{'X-CSRF-Token':csrf,'Content-Type':'application/json'},body:JSON.stringify(data),credentials:'same-origin'});
 const json=await response.json();if(!response.ok)throw Error(json.error||json.message||'Unable to save');location.reload();
}catch(err){statusEl.textContent=err.message||String(err);save.disabled=false;}});
const money=s=>Number(s).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
function pdfStampDigits(draw,value,x,y,size=10,step=12){for(const c of String(value).replace(/\D/g,'')){draw(c,x,y,12,size);x+=step;}}
async function createPdf(){
 if(!window.PDFLib)throw Error('The bundled PDF printing library could not load. Contact the administrator.');
 const template64=window.IPDAMS_BIR2307_TEMPLATE_BASE64;
 if(typeof template64!=='string'||template64.length<1000)throw Error('BIR 2307 template is not installed.');
 const templateBytes=Uint8Array.from(atob(template64),ch=>ch.charCodeAt(0));
 const {PDFDocument,StandardFonts,rgb}=window.PDFLib;
 const doc=await PDFDocument.load(templateBytes);while(doc.getPageCount()>1)doc.removePage(1);
 const page=doc.getPages()[0];const font=await doc.embedFont(StandardFonts.Helvetica);
 // Keep every legal identity, TIN and amount complete; do not hide an overlong
 // registered field behind the previous generic error message. Descriptions are
 // presentational ATC labels and can be visibly shortened while ATC stays intact.
 const draw=(value,x,y,max=520,size=9,min=5,label='Form field')=>{
   const str=String(value??'').replace(/\s+/g,' ').trim();if(!str)return;
   const width=font.widthOfTextAtSize(str,size);
   const fitted=width>max?size*max/width:size;
   if(fitted<min)throw Error(label+' is too long for the official BIR 2307 field. Review the corresponding source profile.');
   page.drawText(str,{x,y,size:fitted,font,color:rgb(0,0,0)});
 };
 const lineWrap=(value,maxWidth,fontSize,maxLines=2)=>{
   const words=String(value??'').replace(/\s+/g,' ').trim().split(' ').filter(Boolean);
   if(!words.length)return [];
   const lines=[];let line='';
   for(const word of words){
     if(font.widthOfTextAtSize(word,fontSize)>maxWidth)return null;
     const candidate=line?line+' '+word:word;
     if(font.widthOfTextAtSize(candidate,fontSize)<=maxWidth){line=candidate;continue;}
     if(line)lines.push(line);line=word;
     if(lines.length>=maxLines)return null;
   }
   if(line)lines.push(line);
   return lines.length<=maxLines?lines:null;
 };
 const drawRegistered=(value,x,y,maxWidth,baseSize,label)=>{
   const str=String(value??'').replace(/\s+/g,' ').trim();if(!str)return;
   // Normal one-line printing is unchanged for short names and addresses.
   const fitted=Math.min(baseSize,baseSize*maxWidth/font.widthOfTextAtSize(str,baseSize));
   if(fitted>=7){draw(str,x,y,maxWidth,baseSize,7,label);return;}
   // The original template has two lines of usable height in these fields.
   // Keep the COMPLETE saved text; never abbreviate the registered identity.
   for(const size of [8,7.5,7]){
     const lines=lineWrap(str,maxWidth,size,2);
     if(lines){
       lines.forEach((line,i)=>draw(line,x,y+(lines.length===2?3.6:0)-i*7.8,maxWidth,size,size,label));
       return;
     }
   }
   throw Error(label+' cannot fit legibly on Form 2307. Verify the registered name/address in the appropriate Organization or supplier profile.');
 };
 // The original ENCS form has 10 physical rows in each of the EWT and
 // business-tax sections.  As in NONIUSWTAX, an ATC description continues
 // into the blank row(s) immediately below its first row.  Reserve those
 // rows before placing the next ATC; codes and SAVED amounts stay on the
 // first row only.  Never shorten the official explanatory description.
 const ATC_ROW_STEP=13.75, ATC_MAX_ROWS=10;
 const wrappedAtc=[];
 const wrapAtcDescription=(value,maxWidth,size)=>{
   const words=String(value??'').replace(/\s+/g,' ').trim().split(' ').filter(Boolean);
   const lines=[];let line='';
   const width=text=>font.widthOfTextAtSize(text,size);
   for(const word of words){
     if(width(word)<=maxWidth){
       const candidate=line?line+' '+word:word;
       if(width(candidate)<=maxWidth){line=candidate;continue;}
       if(line)lines.push(line);line=word;continue;
     }
     // Rare unbroken identifiers: wrap by glyph rather than crop them.
     if(line){lines.push(line);line='';}
     let fragment='';
     for(const glyph of word){
       if(fragment && width(fragment+glyph)>maxWidth){lines.push(fragment);fragment='';}
       fragment+=glyph;
       if(width(fragment)>maxWidth)throw Error('An ATC description contains a glyph too wide for Form 2307.');
     }
     line=fragment;
   }
   if(line)lines.push(line);
   return lines;
 };
 const planAtcRows=(rows,kind)=>{
   // Keep text as large as the available section permits.  The same chosen
   // size is used for all descriptions within a section for consistency.
   for(const size of [8,7.5,7,6.5]){
     let occupied=0;
     const plan=rows.map(row=>{
       const lines=wrapAtcDescription(row.description,143,size);
       const slots=Math.max(1,lines.length);
       occupied+=slots;
       return {row,lines,slots};
     });
     if(occupied<=ATC_MAX_ROWS)return {plan,size};
   }
   throw Error(kind+' ATC descriptions and entries exceed the 10 available Form 2307 rows. Full descriptions were not shortened; review the certificate coverage.');
 };
 const date=(str,x)=>{const [yy,mm,dd]=str.split('-');for(const part of [mm,dd,yy]){pdfStampDigits(draw,part,x,818,10,12);x+=part.length*12+4;}};
 const tin=(str,y)=>{const raw=String(str).replace(/\D/g,'');if(![12,14].includes(raw.length))throw Error('A verified payor/payee TIN is missing.');let x=210;const groups=[raw.slice(0,3),raw.slice(3,6),raw.slice(6,9),raw.slice(9)];groups.forEach((part,i)=>{pdfStampDigits(draw,part,x,y,10, i===3?14:11.25);x+=part.length*(i===3?14:11.25)+19;});};
 date(model.period.from,155);date(model.period.to,403);
 tin(model.supplier.tin,787);tin(model.payor.tin,672);
 drawRegistered(model.supplier.name,50,760,530,11,'Supplier registered name');drawRegistered(model.supplier.address,50,732,477,9,'Supplier registered address');pdfStampDigits(draw,model.supplier.zip,545,732,10,13);
 drawRegistered(model.payor.registered_name,50,645,530,11,'Payor registered BIR name');drawRegistered(model.payor.address,50,617,477,9,'Payor registered address');pdfStampDigits(draw,model.payor.zip,545,617,10,13);
 // Aggregate only the already-saved monthly bases in integer cents; never apply a tax rate.
 const savedCents=value=>{const raw=String(value??'0').trim();if(!/^-?\d+(?:\.\d{1,2})?$/.test(raw))throw Error('Invalid saved tax base.');const neg=raw.startsWith('-');const [major,minor='']=raw.replace('-','').split('.');const cents=BigInt(major)*100n+BigInt(minor.padEnd(2,'0'));return neg?-cents:cents;};
 const formatCents=cents=>{const neg=cents<0n;const value=neg?-cents:cents;const major=value/100n;const fraction=String(value%100n).padStart(2,'0');return (neg?'-':'')+String(major).replace(/\B(?=(\d{3})+(?!\d))/g,',')+'.'+fraction;};
 const footerBase=(rows,field)=>formatCents(rows.reduce((total,r)=>total+savedCents(field==='total'?r.total:r.monthly[field]),0n));
 const rowsE=model.rows.filter(r=>r.kind==='EWT'), rowsV=model.rows.filter(r=>r.kind==='VAT');
 const addRows=(rows,firstY,kind)=>{
   const {plan,size}=planAtcRows(rows,kind);
   let physicalRow=0;
   for(const {row:r,lines,slots} of plan){
     const y=firstY-physicalRow*ATC_ROW_STEP;
     lines.forEach((line,i)=>draw(line,28,y-i*ATC_ROW_STEP,143,size,size,'ATC '+r.atc+' description'));
     if(lines.length>1)wrappedAtc.push(r.atc);
     draw(r.atc,178,y,44,8.5,6,'ATC code');
     [230,300,390].forEach((x,j)=>{if(Number(r.monthly[j])!==0)draw(money(r.monthly[j]),x,y,65,8,5.5);});
     draw(money(r.total),450,y,68,8,5.5);draw(money(r.withheld),550,y,55,8,5.5);
     physicalRow+=slots;
   }
 };addRows(rowsE,560,'Expanded withholding');addRows(rowsV,390,'Business-tax withholding');
 // The Total rows use sums of the stored monthly bases; saved withheld totals remain unchanged.
 const footer=(rows,kind,y)=>{if(!rows.length)return;
   [230,300,390].forEach((x,j)=>draw(footerBase(rows,j),x,y,65,8,5.5));
   draw(footerBase(rows,'total'),450,y,68,8,5.5);
   draw(money(model.saved_withholding_totals[kind]),550,y,55,8,5.5);
 };
 footer(rowsE,'EWT',425);footer(rowsV,'VAT',255);
 // Printed name/title only; the signature line itself stays blank.
 const center=(value,y,max=500,size=8)=>{const str=String(value??'');if(!str)return;const fitted=Math.min(size,size*max/font.widthOfTextAtSize(str,size));if(fitted<5)throw Error('Head of Office details too long for certificate.');const width=font.widthOfTextAtSize(str,fitted);page.drawText(str,{x:(page.getWidth()-width)/2,y,size:fitted,font,color:rgb(0,0,0)});};
 center(model.head.name,203.5,500,8.5);center([model.head.designation,'TIN '+model.head.tin].join(' | '),191.5,500,7);
 // Render the complete legal-sized source page into a new A4 page at uniform
 // scale.  Reflowing or cropping the official form would risk moving entries
 // into the wrong boxes.  Page dimensions are PDF points (1 pt = 1/72 inch).
 const originalBytes=await doc.save();
 const a4Doc=await PDFDocument.create();
 const [embedded]=await a4Doc.embedPdf(originalBytes,[0]);
 const A4_WIDTH=595.28, A4_HEIGHT=841.89, MARGIN=5.67; // ~2 mm top/bottom PDF margin; centering preserves aspect ratio
 const scale=Math.min((A4_WIDTH-2*MARGIN)/embedded.width,(A4_HEIGHT-2*MARGIN)/embedded.height);
 const a4Page=a4Doc.addPage([A4_WIDTH,A4_HEIGHT]);
 a4Page.drawPage(embedded,{x:(A4_WIDTH-embedded.width*scale)/2,y:(A4_HEIGHT-embedded.height*scale)/2,width:embedded.width*scale,height:embedded.height*scale});
 a4Doc.setTitle('BIR 2307 A4 - DV '+model.dv.number+' - '+model.supplier.name);
 a4Doc.setSubject('A4-scaled original BIR form; selected DV '+model.dv.number+' only; saved tax components, unsigned, no evidence of remittance');
 // The app, not a GitHub page, generated this certificate. Keep PDF properties
 // readable while preserving honest attribution of the bundled PDF library.
 a4Doc.setCreator('IPDAMS');
 a4Doc.setProducer('IPDAMS PDF generation (pdf-lib 1.17.1)');
 return {bytes:await a4Doc.save(),wrappedAtc};
}
// Preview is generated in memory, never posted to the server and never
// downloaded without an explicit user click. The selected DV is unchanged.
let previewUrl=null;
let previewBusy=false;
const printButton=document.getElementById('print');
const downloadButton=document.getElementById('download');
const retryButton=document.getElementById('retry-preview');
const previewFrame=document.getElementById('certificate-preview');
const fileName='BIR2307-DV-'+String(model.dv.number).replace(/[^A-Za-z0-9._-]/g,'_')+'-A4.pdf';
async function preparePreview(){
 if(!previewFrame||previewBusy)return;
 previewBusy=true;
 printButton.disabled=true;downloadButton.disabled=true;
 if(retryButton)retryButton.disabled=true;
 statusEl.textContent='Preparing A4 certificate preview from this DV’s saved withholding values...';
 try{
  const result=await createPdf();
  const url=URL.createObjectURL(new Blob([result.bytes],{type:'application/pdf'}));
  const previous=previewUrl;
  previewUrl=url;
  previewFrame.src=url;
  if(previous)URL.revokeObjectURL(previous);
  printButton.disabled=false;downloadButton.disabled=false;
  statusEl.textContent=result.wrappedAtc.length
    ?'A4 preview ready. Full ATC descriptions continue into reserved rows ('+[...new Set(result.wrappedAtc)].join(', ')+'). Review before printing or downloading.'
    :'A4 preview ready. Review all certificate fields, then choose Print or Download.';
 }catch(err){statusEl.textContent='Unable to prepare A4 preview: '+(err.message||String(err));}
 finally{previewBusy=false;if(retryButton)retryButton.disabled=false;}
}
if(printButton)printButton.addEventListener('click',()=>{
 if(!previewUrl)return;
 try{
  previewFrame.contentWindow.focus();
  previewFrame.contentWindow.print();
  statusEl.textContent='Print dialog requested. Select A4 paper, no extra browser margins, and 100% / Actual Size where your printer supports the PDF. Check the printer preview for clipping.';
 }catch(err){
  const w=window.open(previewUrl,'_blank','noopener,noreferrer');
  statusEl.textContent=w?'A4 PDF opened in another tab. Use the PDF viewer Print control (or Ctrl+P).':'Browser blocked the PDF viewer. Choose Download A4 PDF, then print from your PDF reader.';
 }
});
if(downloadButton)downloadButton.addEventListener('click',()=>{
 if(!previewUrl)return;
 const a=document.createElement('a');a.href=previewUrl;a.download=fileName;
 document.body.appendChild(a);a.click();a.remove();
 statusEl.textContent='A4 PDF downloaded. Review the certificate before signing or issuing.';
});
if(retryButton)retryButton.addEventListener('click',preparePreview);
window.addEventListener('pagehide',()=>{if(previewUrl)URL.revokeObjectURL(previewUrl);});
if(previewFrame)preparePreview();
</script></body></html>
