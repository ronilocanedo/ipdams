<?php
declare(strict_types=1);
/** Only included by the authorized read-only financial report endpoint. */
$a=$d['appendix43'];$meta=$a['meta'];$non=$a['class']==='NON_IUS'&&$a['cashCode']==='1990101000';
$e=static fn($v):string=>htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$n=static fn(int $c):string=>$c===0?'—':CashFundReports::peso($c);
$title=static fn($code):string=>$a['names'][$code]??((string)$code==='2020101000'?'Due to BIR (recorded liability settlement)':'Account title not recorded');
$date=static function($value):string {if(!$value)return ''; $dt=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$value);return $dt&&$dt->format('Y-m-d')===$value?$dt->format('n/j/Y'):(string)$value;};
// DV-backed rows display the saved DV document date, never an issue/release timestamp.
// The cash projection retains actual event ordering and cutoff. Missing DV dates stay blank.
$displayDate=static function(array $move) use ($date):string {
    if($move['sort']===0)return $date($move['date']);
    $value=(string)($move['documentDate']??'');
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    return $dt&&$dt->format('Y-m-d')===$value?$date($value):'';
};
$colgroup='<colgroup><col class="a43-date"><col class="a43-dv"><col class="a43-check"><col class="a43-part"><col class="a43-money" span="3"><col class="a43-tax" span="2"><col class="a43-due"><col class="a43-uacs" span="4"><col class="a43-other-name"><col class="a43-other-code"><col class="a43-other-value"></colgroup>';
$head=$meta['head']??[];
$blank='________________';
$divisionSignatories=$meta['divisionSignatories']??[];
$received=$divisionSignatories['received']??[];
$noted=$divisionSignatories['noted']??[];
$sds=$divisionSignatories['sds']??[];
?>
<div class="a43-document">
<div class="a43-appendix"><?= $non?'Appendix 43':'Internal monitoring register' ?></div>
<h2 class="a43-title">CASH DISBURSEMENTS REGISTER</h2>
<div class="a43-header">
 <dl><dt>Entity Name:</dt><dd>DEPARTMENT OF EDUCATION</dd><dt>Sub-Office/District/Division:</dt><dd><?=$e($meta['division']?:$blank)?></dd><dt>Municipality/City/Province:</dt><dd><?=$e($meta['address']?:$blank)?></dd><dt>Fund Cluster:</dt><dd><?=$blank?></dd></dl>
 <dl><dt>Name of Accountable Officer:</dt><dd><?=$e($head['name']??$blank)?></dd><dt>Official Designation:</dt><dd><?=$e($head['designation']??$blank)?></dd><dt>Station:</dt><dd><?=$e($meta['station'])?></dd><dt>Register No.:</dt><dd><?=$blank?></dd><dt>Sheet No.:</dt><dd><?=$blank?></dd></dl>
</div>
<div class="a43-scope">Period: <?=$e($periodTitle)?> &nbsp; | &nbsp; Fund: <?=$e($fund['source_code'].' - '.$fund['name'])?> &nbsp; | &nbsp; Bank: <?=$e($bank['bank_name'].' / '.$bank['account_label'])?></div>
<div class="a43-review">REVIEW COPY — NOT CERTIFIED<?=$non?'':' · INTERNAL CASH MONITORING (NOT OFFICIAL APPENDIX 43)'?><?= $a['reconciled']?'':' · CLASSIFICATION INCOMPLETE' ?></div>
<table class="a43-grid" aria-label="Cash Disbursements Register"><?=$colgroup?><thead>
<tr><th rowspan="3">Date</th><th rowspan="3">Disbursement<br>Voucher No.</th><th rowspan="3">Check<br>No.</th><th rowspan="3">Particulars</th><th colspan="3"><?=$e($a['label'])?><br><?=$e($a['cashCode']?'('.$a['cashCode'].')':'(Review required)')?></th><th colspan="10">BREAKDOWN OF PAYMENTS</th></tr>
<tr><th colspan="3">Amount</th><th colspan="2" class="a43-taxhead">Withholding Tax</th><th rowspan="2" class="a43-taxhead">Due to BIR<br><span>2020101000</span></th>
<?php foreach($a['fixed'] as $code):?><th rowspan="2" class="a43-account-title"><?=$code!==''?$e($title($code)):''?><span><?=$e($code)?></span></th><?php endforeach;?>
<th colspan="3">O T H E R S</th></tr>
<tr><th><?=$e($a['receiptLabel'])?></th><th>Payments</th><th>Balance</th><th class="a43-taxhead">VAT</th><th class="a43-taxhead">EWTX</th><th>Account Description</th><th>UACS Object<br>Code</th><th>Amount</th></tr></thead><tbody>
<tr class="a43-opening"><td><?=$e($date($d['prev']))?></td><td></td><td></td><td>Beginning balance</td><td></td><td></td><td class="a43-num"><?=$n($d['opening'])?></td><?php for($i=0;$i<10;$i++):?><td></td><?php endfor;?></tr>
<?php foreach($a['moves'] as $move):
 $other=array_diff_key($move['parts'],array_fill_keys(array_filter($a['fixed'],static fn($c)=>$c!==''),true));ksort($other,SORT_STRING);
 $items=[];foreach($other as $code=>$amount)$items[]=['code'=>$code,'amount'=>$amount];if(!$items)$items[]=['code'=>'','amount'=>0];
 foreach($items as $i=>$item):$first=$i===0;?>
<tr class="<?=$first?'a43-primary':'a43-continuation'?>">
<td><?=$first?$e($displayDate($move)):''?></td><td><?=$first?$e($move['dv']):''?></td><td><?=$first?$e($move['check']):''?></td>
<td><?php if($first):?><?=$e($move['particulars'])?><?php if(!empty($move['unclassified'])):?><span class="a43-note">Classification review</span><?php endif;?><?php endif;?></td>
<td class="a43-num"><?=$first?$n($move['receipt']):''?></td><td class="a43-num"><?=$first?$n($move['paid']):''?></td><td class="a43-num"><?=$first?$n($move['balance']):''?></td>
<td class="a43-num"><?=$first&&$move['paid']&&empty($move['unclassified'])?$n($move['vat']):''?></td><td class="a43-num"><?=$first&&$move['paid']&&empty($move['unclassified'])?$n($move['ewt']):''?></td><td class="a43-num"><?=$first&&$move['paid']&&empty($move['unclassified'])?$n($move['due']):''?></td>
<?php foreach($a['fixed'] as $code):?><td class="a43-num"><?=$first&&$code!==''&&isset($move['parts'][$code])?$n($move['parts'][$code]):''?></td><?php endforeach;?>
<td><?=$item['code']!==''?$e($title($item['code'])):''?></td><td class="a43-code"><?=$e($item['code'])?></td><td class="a43-num"><?=$item['code']!==''?$n($item['amount']):''?></td></tr>
<?php endforeach;endforeach;?>
<tr class="a43-total"><td colspan="3"></td><td>TOTAL</td><td class="a43-num"><?=CashFundReports::peso($d['credit'])?></td><td class="a43-num"><?=CashFundReports::peso($d['payment'])?></td><td class="a43-num"><?=CashFundReports::peso($d['closing'])?></td><td class="a43-num"><?=$n($a['totalVat'])?></td><td class="a43-num"><?=$n($a['totalEwt'])?></td><td class="a43-num"><?=$n($a['totalVat']+$a['totalEwt'])?></td>
<?php foreach($a['fixed'] as $code):?><td class="a43-num"><?=$code!==''?$n($a['grossByCode'][$code]??0):''?></td><?php endforeach;?><td colspan="2"></td><td class="a43-num"><?=$n(array_sum(array_diff_key($a['grossByCode'],array_fill_keys(array_filter($a['fixed'],static fn($c)=>$c!==''),true))))?></td></tr>
</tbody></table>
<div class="a43-recap-wrap"><table class="a43-grid a43-recap" aria-label="Recapitulation"><?=$colgroup?><thead><tr><th colspan="14"></th><th colspan="3">RECAPITULATION</th></tr><tr><th colspan="14"></th><th>Account Description</th><th>UACS Object<br>Code</th><th>Amount</th></tr></thead><tbody>
<?php $recap=$a['grossByCode'];ksort($recap,SORT_STRING);$recapRows=[];foreach($recap as $code=>$amount)$recapRows[]=[$title($code),$code,$amount];
 if($a['negativeRetained'])$recapRows[]=['Due to BIR - retained for school use','2020101000',-$a['negativeRetained']];
 if($a['negativeRemit'])$recapRows[]=['Due to BIR - withheld from supplier payment','2020101000',-$a['negativeRemit']];
 foreach($recapRows as [$label,$code,$amount]):?><tr><?php for($i=0;$i<14;$i++):?><td></td><?php endfor;?><td><?=$e($label)?></td><td class="a43-code"><?=$e($code)?></td><td class="a43-num"><?=CashFundReports::peso($amount)?></td></tr><?php endforeach;?>
 <tr class="a43-total"><?php for($i=0;$i<14;$i++):?><td></td><?php endfor;?><td colspan="2">TOTAL</td><td class="a43-num"><?=CashFundReports::peso($a['recap'])?></td></tr>
 <?php if(!$a['reconciled']):?><tr class="a43-discrepancy"><td colspan="14"></td><td colspan="2">Unreconciled difference</td><td class="a43-num"><?=CashFundReports::peso($a['difference'])?></td></tr><?php endif;?>
</tbody></table></div>
<div class="a43-signoff">
 <div class="a43-certify"><p>CERTIFIED CORRECT:</p><div class="a43-signature"><strong><?=$e($head['name']??$blank)?></strong><span><?=$e($head['designation']??'Accountable officer / authorized signatory')?></span></div></div>
 <div class="a43-division-sign"><p>Payments must equal the gross UACS breakdown less withholding included in the recap. VAT, EWTX and Due to BIR are informational and are not added again.</p>
 <div class="a43-receive">RECEIVED BY:<div class="a43-signature"><strong><?=$e($received['name']??$blank)?></strong><span><?=$e($received['designation']??($a['class']==='DIVISION'?'Authorized receiving officer':'Division MOOE In-Charge'))?></span></div></div>
 <div class="a43-receive">NOTED BY:<div class="a43-signature"><strong><?=$e($noted['name']??$blank)?></strong><span><?=$e($noted['designation']??($a['class']==='DIVISION'?'Authorized reviewing officer':'SGOD Chief'))?></span></div><div class="a43-signature"><strong><?=$e($sds['name']??$blank)?></strong><span><?=$e($sds['designation']??($a['class']==='DIVISION'?'Authorized approving official':'Schools Division Superintendent'))?></span></div></div></div>
</div>
<div class="a43-printed-review"><strong>Review before certification:</strong> Date shows the saved DV document date for check rows and bank credit date for fund receipts. Row order, monthly inclusion and running balances follow actual recorded cash events; missing DV dates are left blank. This is a monthly fund/bank projection, not a certified per-download liquidation. Municipality field uses the registered organization address. Blank register, sheet, fund cluster and signature fields require source verification; no values are invented. Tax and accounting snapshots require historical source review.<?php if(!$a['reconciled']):?> <strong>Unreconciled amounts must be resolved before official use.</strong><?php endif;?></div>
<?php if($a['notes']):?><ul class="a43-print-notes"><?php foreach(array_unique($a['notes']) as $note):?><li><?=$e($note)?></li><?php endforeach;?></ul><?php endif;?>
</div>
