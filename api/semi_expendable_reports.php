<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/app/bootstrap.php';
require_once dirname(__DIR__).'/private/app/SemiExpendableReportCenter.php';
ini_set('display_errors','0');ini_set('log_errors','1');
ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_samesite','Lax');
session_name('IPDAMSSESSID');session_start();
header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');
$reportEmbedded=(($_GET['embedded']??'')==='1');
$reportPreview=$reportEmbedded && (($_GET['preview']??'')==='1');
header('X-Content-Type-Options: nosniff');header('X-Frame-Options: '.($reportEmbedded?'SAMEORIGIN':'DENY'));header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; form-action 'self'; object-src 'none'; base-uri 'none'; frame-ancestors ".($reportEmbedded?"'self'":"'none'"));
function sre(mixed $v):string{return htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function moneyFmt(mixed $cents):string{return $cents===null?'Not recorded':number_format(((int)$cents)/100,2);}
function srv(string $code):string{return match($code){
 'A1'=>'Semi-Expendable Property Card',
 'A4'=>'Registry of Semi-Expendable Property Issued',
 'A5'=>'Inventory Transfer Report',
 'A6'=>'Receipt of Returned Semi-Expendable Property',
 'A7'=>'Report of Semi-Expendable Property Issued',
 'A8'=>'Report on the Physical Count of Semi-Expendable Property',
 'A9'=>'Report of Lost, Stolen, Damaged or Destroyed Semi-Expendable Property',
 'A10'=>'Inventory and Inspection Report of Unserviceable Semi-Expendable Property',
 default=>'Unknown form'};}
$errors='';$data=null;$available=[];$selected='A7';$org=0;
$now=new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));
$from=(string)($_GET['from']??$now->format('Y-m-01'));$through=(string)($_GET['through']??$now->format('Y-m-d'));
$selected=(string)($_GET['annex']??'A7');
if(!in_array($selected,['A1','A4','A5','A6','A7','A8','A9','A10'],true))$selected='A7';
try{
 $db=database();$user=currentUser($db);
 demand(($user['role']??'')!=='VIEWER','No property-level reporting access for this role',403);
 $available=$user['role']==='SUPER_ADMIN'
    ?q($db,'SELECT id,name,kind FROM organizations WHERE active=1 ORDER BY name LIMIT 201')->fetchAll(PDO::FETCH_ASSOC)
    :q($db,'SELECT o.id,o.name,o.kind FROM organizations o JOIN user_organization_scopes s ON s.organization_id=o.id WHERE s.user_id=? AND o.active=1 ORDER BY o.name LIMIT 201',[(int)$user['id']])->fetchAll(PDO::FETCH_ASSOC);
 $org=(int)($_GET['organization_id']??($available[0]['id']??0));
 if($org>0){scope($db,$user,$org);$data=(new SemiExpendableReportCenter($db,$user))->load(['organization_id'=>$org,'from'=>$from,'through'=>$through]);}
 else{$errors='There is no authorized organization for your account.';}
}catch(DomainError $e){http_response_code($e->status);$errors=$e->getMessage();}
catch(Throwable $e){error_log('IPDAMS semi-expendable reporting: '.$e->getMessage());http_response_code(500);$errors='Unable to load report. Contact the technical administrator if this continues.';}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>IPDAMS · Semi-Expendable Property Reports</title><link rel="stylesheet" href="../assets/semi-expendable-reports.css"><script src="../assets/semi-expendable-reports.js" defer></script></head><body class="<?=$reportEmbedded?'embedded':''?><?=$reportPreview?' report-preview':''?>">
<nav class="tools noprint"><a href="../index.php">← IPDAMS</a><strong>Property reports · COA semi-expendable annexes</strong><button id="print-report" type="button" <?=!$data?'disabled':''?>>Print preview</button></nav>
<main class="wrap">
 <?php if(!$reportPreview):?><div class="report-controls noprint"><section class="card noprint"><h2>Property reporting</h2><p class="caption">Use official source transactions only. Printing this preliminary evidence is not certification, approval, a signed RRSP, or an accounting adjustment.</p>
 <form class="filters" method="get" action="semi_expendable_reports.php">
  <?php if($reportEmbedded):?><input type="hidden" name="embedded" value="1"><?php endif;?>
 <?php if($reportEmbedded):?><input type="hidden" name="organization_id" value="<?=sre($org)?>"><?php else:?><label>Authorized organization<select name="organization_id" required><?php foreach($available as $o):?><option value="<?=sre($o['id'])?>" <?=((int)$o['id']===$org)?'selected':''?>><?=sre($o['name'])?> (<?=sre($o['kind'])?>)</option><?php endforeach;?></select></label><?php endif;?>
 <label>From<input type="date" name="from" value="<?=sre($from)?>" required></label>
 <label>Through<input type="date" name="through" value="<?=sre($through)?>" required></label>
 <label>Annex<select name="annex"><?php foreach(['A1','A4','A5','A6','A7','A8','A9','A10'] as $code):?><option value="<?=sre($code)?>" <?=$selected===$code?'selected':''?>><?=sre($code)?> · <?=sre(srv($code))?></option><?php endforeach;?></select></label>
 <button type="submit">Show report</button><?php if($reportEmbedded):?><button type="button" id="embedded-print-report" <?=!$data?'disabled':''?>>Print / Save PDF</button><?php endif;?></form></section>
 <?php if($errors==='' && $data):?>
 <nav class="tabs noprint" aria-label="COA report forms"><?php foreach(['A1','A4','A5','A6','A7','A8','A9','A10'] as $code):?><a class="<?=$selected===$code?'active':''?>" href="?organization_id=<?=rawurlencode((string)$org)?>&amp;from=<?=rawurlencode($from)?>&amp;through=<?=rawurlencode($through)?>&amp;annex=<?=$code?><?=$reportEmbedded?'&amp;embedded=1':''?>"><?=sre(str_replace('A','A.',$code))?></a><?php endforeach;?></nav>
 <?php endif;?></div><?php endif;?>
 <?php if($errors!==''):?><div class="card warning" role="alert"><?=sre($errors)?></div><?php elseif($data):?>
 <section class="warning"><strong>Preliminary reporting evidence — not a certified COA form.</strong> Historical unlinked documents, unsigned transactions, missing costs, RRSP numbers, and physical-count results cannot be invented. <?=count($data['exceptions'])?> accountability record(s) need linkage/status review. Report dates use system-recorded timestamps rather than manually signed document dates.</section>
 <article class="sheet" aria-label="Semi-expendable report preview"><header class="heading"><small>Republic of the Philippines<br>Department of Education</small>
 <p class="caption"><?=sre($data['organization']['name'])?></p><p class="caption">Annex <?=sre(str_replace('A','A.',$selected))?> · PRELIMINARY REVIEW COPY</p><h1><?=sre(srv($selected))?></h1>
 <small>Entity: <?=sre($data['organization']['name'])?> · Reporting period: <?=sre($data['from'])?> through <?=sre($data['through'])?></small></header>
 <?php if($selected==='A7'): ?>
 <p class="caption">To be filled by Property and/or Supply Unit. Accounting posting and signatures are intentionally blank.</p>
 <table><thead><tr><th>ICS No.</th><th>Responsibility Center Code</th><th>Semi-expendable Property No.</th><th>Item Description</th><th>Unit</th><th>Quantity Issued</th><th>Unit Cost</th><th>Amount</th></tr></thead><tbody>
 <?php foreach($data['issueRows'] as $r):?><tr><td><?=sre($r['display_number'])?></td><td>—</td><td><?=sre($r['property_tag'])?></td><td><?=sre($r['item_name'])?></td><td><?=sre($r['unit']?:'unit')?></td><td>1</td><td class="money"><?=sre(moneyFmt($r['amount_cents']))?></td><td class="money"><?=sre(moneyFmt($r['amount_cents']))?></td></tr><?php endforeach;?>
 <?php if(!$data['issueRows']):?><tr><td class="empty" colspan="8">No explicitly linked, finalized ICS issues were recorded in this period.</td></tr><?php endif;?></tbody></table>
 <p class="caption">Verified linked finalized ICS rows shown: <?=count($data['issueRows'])?>. Documents requiring review: <?=count($data['exceptions'])?>. Incomplete costs and responsibility codes require authorized completion before certification.</p>
 <?php if($data['exceptions']):?><h3>Records excluded from certified issuance evidence — manual reconciliation</h3><table><thead><tr><th>ICS/PAR No.</th><th>Type</th><th>Property No.</th><th>Review reason</th></tr></thead><tbody><?php foreach($data['exceptions'] as $r):?><tr><td><?=sre($r['display_number'])?></td><td><?=sre($r['doc_type'])?></td><td><?=sre($r['property_tag'])?></td><td><?=sre($r['link_status'])?></td></tr><?php endforeach;?></tbody></table><?php endif;?>
 <div class="signatures"><div>Property and/or Supply Custodian<br>Signature over printed name (manual)</div><div>Accounting Staff<br>Posted by (manual)</div></div>
 <?php elseif($selected==='A4'):?>
 <table><thead><tr><th>Date recorded</th><th>ICS / RRSP No.</th><th>Property No.</th><th>Item Description</th><th>Issued Qty.</th><th>Returned Qty.</th><th>Reissued Qty.</th><th>Disposed Qty.</th><th>Custody/event evidence</th><th>Review status</th></tr></thead><tbody>
 <?php foreach($data['movementRows'] as $m):?><tr><td><?=sre(substr((string)$m['created_at'],0,10))?></td><td><?=sre($m['document_number']??'Not linked')?></td><td><?=sre($m['property_tag'])?></td><td><?=sre($m['item_name'])?></td><td><?=($m['event_type']==='ISSUE')?'1':'—'?></td><td><?=($m['event_type']==='RETURN')?'1':'—'?></td><td>—</td><td><?=($m['event_type']==='DISPOSAL')?'1':'—'?></td><td><?=sre($m['from_custodian']??'')?> → <?=sre($m['to_custodian']??'')?></td><td><?=sre($m['link_status'])?></td></tr><?php endforeach;?>
 <?php if(!$data['movementRows']):?><tr><td colspan="10" class="empty">No recorded movements in the selected period.</td></tr><?php endif;?></tbody></table>
 <p class="caption">Returned movements do not carry an official RRSP number in this dataset; reissuance cannot be inferred. Outstanding balance/amounts require signed receipt and approved custody reconciliation.</p>
 <?php elseif($selected==='A1'):?>
 <table><thead><tr><th>Date recorded</th><th>Reference</th><th>Property No.</th><th>Semi-expendable property</th><th>Receipt Qty</th><th>Receipt Unit Cost</th><th>Issue / Transfer / Disposal</th><th>Office / Officer</th><th>Balance Qty</th><th>Amount</th><th>Remarks</th></tr></thead><tbody>
 <?php foreach($data['assets'] as $a):?><tr><td><?=sre($a['acquired_on']??'—')?></td><td><?=((int)$a['opening_record']===1)?'Opening registration':'Registered asset'?></td><td><?=sre($a['property_tag'])?></td><td><?=sre($a['item_name'])?></td><td>1</td><td class="money"><?=sre(moneyFmt($a['unit_cost_cents']))?></td><td>See custody movements</td><td><?=sre($a['current_custodian']??'—')?> (current)</td><td>Review</td><td>Review</td><td>Not a reconciled stock-balance ledger</td></tr><?php endforeach;?>
 <?php if(!$data['assets']):?><tr><td colspan="11" class="empty">No semi-expendable assets in this organization.</td></tr><?php endif;?></tbody></table>
 <p class="caption">Current-office records exclude assets transferred out after the receiving office acknowledged them. Their original school-side transfer evidence remains under Annex A.5 and movement history. Each registered asset represents one physical unit; this is not a reconciled stock-balance ledger.</p>
 <?php elseif($selected==='A5'):?>
 <div class="blocker"><strong>Transfer movement evidence — not a signed ITR.</strong><p>Use the existing documented transfer workflow for approvals and signed paper handover. The movement log alone does not prove the transfer was officially completed.</p><p class="noprint"><a href="property-transfers.php?organization_id=<?=rawurlencode((string)$org)?>" target="_blank" rel="noopener noreferrer">Open Property Transfers workflow</a> (School Administrator permission required).</p></div>
 <table><thead><tr><th>Date recorded</th><th>Property No.</th><th>Item description</th><th>Released by</th><th>Received by</th><th>Recorded reason / reference</th><th>Approval / signature</th></tr></thead><tbody><?php $count=0;foreach($data['movementRows'] as $m):if($m['event_type']!=='TRANSFER')continue;$count++;?><tr><td><?=sre(substr((string)$m['created_at'],0,10))?></td><td><?=sre($m['property_tag'])?></td><td><?=sre($m['item_name'])?></td><td><?=sre($m['from_custodian'])?></td><td><?=sre($m['to_custodian'])?></td><td><?=sre($m['reason'])?></td><td>Verify signed ITR</td></tr><?php endforeach;if(!$count):?><tr><td colspan="7" class="empty">No transfer movements recorded in this period.</td></tr><?php endif;?></tbody></table>
 <?php elseif($selected==='A6'):?>
 <div class="blocker"><strong>RRSP cannot be finalized from movements alone.</strong><p>Recorded returns below are evidence for preparation, not an issued Receipt of Returned Semi-Expendable Property. RRSP number, signed acknowledgment and received condition must come from an approved document.</p></div>
 <table><thead><tr><th>Date recorded</th><th>Item description</th><th>Qty.</th><th>Original ICS No.</th><th>End-user / returned by</th><th>Received by</th><th>Remarks</th></tr></thead><tbody><?php $count=0;foreach($data['movementRows'] as $m):if($m['event_type']!=='RETURN')continue;$count++;?><tr><td><?=sre(substr((string)$m['created_at'],0,10))?></td><td><?=sre($m['item_name'])?></td><td>1</td><td>Review original ICS</td><td><?=sre($m['from_custodian'])?></td><td><?=sre($m['to_custodian'])?></td><td><?=sre($m['reason'])?></td></tr><?php endforeach;if(!$count):?><tr><td colspan="7" class="empty">No return movements recorded in this period.</td></tr><?php endif;?></tbody></table>
 <?php elseif($selected==='A8'):?>
 <div class="blocker"><strong>Physical inventory preparation list only.</strong><p>The preparation list excludes transferred-out assets; their historical transfer evidence remains under Annex A.5. The on-hand quantity, card balance and shortage/overage must come from an authorized actual count and reconciled card. Asset registration alone is not evidence of physical presence. Count committee signatures remain external.</p></div>
 <table><thead><tr><th>Article / Description</th><th>Property No.</th><th>Unit Value</th><th>Card Balance Qty.</th><th>Count On Hand Qty.</th><th>Shortage / Overage Qty.</th><th>Value</th><th>Remarks</th></tr></thead><tbody><?php foreach($data['assets'] as $a):?><tr><td><?=sre($a['item_name'])?></td><td><?=sre($a['property_tag'])?></td><td class="money"><?=sre(moneyFmt($a['unit_cost_cents']))?></td><td>Verify</td><td>Not counted</td><td>—</td><td>—</td><td><?=sre($a['current_status'])?> (current database status only)</td></tr><?php endforeach;if(!$data['assets']):?><tr><td colspan="8" class="empty">No registered semi-expendable assets.</td></tr><?php endif;?></tbody></table>
 <?php elseif($selected==='A9'):?>
 <div class="blocker"><strong>Incident report requires accountable-person evidence.</strong><p>Required: loss/damage category, ICS link, police notification where applicable, circumstances, accountable officer's statement, supervisor acknowledgment and notarial details. No incident is inferred from an asset status.</p></div>
 <?php elseif($selected==='A10'):?>
 <div class="blocker"><strong>Disposal evidence — not an approved IIRUSP.</strong><p>Items with recorded DISPOSAL events are listed for inspection reconciliation. Unserviceability, accumulated impairment, appraisal, disposal approval and sales proceeds are not established by these events.</p></div>
 <table><thead><tr><th>Recorded date</th><th>Property No.</th><th>Particulars</th><th>Qty.</th><th>Acquisition Cost</th><th>Carrying Amount</th><th>Approved disposal method</th><th>Inspection and sale evidence</th></tr></thead><tbody><?php $count=0;foreach($data['movementRows'] as $m):if($m['event_type']!=='DISPOSAL')continue;$count++;?><tr><td><?=sre(substr((string)$m['created_at'],0,10))?></td><td><?=sre($m['property_tag'])?></td><td><?=sre($m['item_name'])?></td><td>1</td><td>Verify source asset</td><td>Review impairment</td><td>Unverified</td><td><?=sre($m['reason'])?> (unapproved evidence)</td></tr><?php endforeach;if(!$count):?><tr><td colspan="8" class="empty">No disposal movements in this period.</td></tr><?php endif;?></tbody></table>
 <?php endif;?>
 <footer class="print-footer">System-generated preliminary review · no electronic/wet signature or accounting posting created · School and Division scope enforced server-side · <?=sre($now->format('Y-m-d H:i'))?> Asia/Manila</footer></article>
 <?php endif;?></main></body></html>
