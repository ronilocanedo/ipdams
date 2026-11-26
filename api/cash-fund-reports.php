<?php
declare(strict_types=1);
// Hosted private root, with sibling-root support for isolated deployments.
$privateApp = dirname(__DIR__).'/private/app';
if (!is_file($privateApp.'/bootstrap.php')) $privateApp = dirname(__DIR__,2).'/private/app';
require_once $privateApp.'/bootstrap.php';
require_once $privateApp.'/CashFundReports.php';
require_once $privateApp.'/FinanceMonitoring.php';
require_once $privateApp.'/FinancialSnapshots.php';
ini_set('display_errors','0');ini_set('log_errors','1');
ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_samesite','Lax');
$isHttps=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
ini_set('session.cookie_secure',$isHttps?'1':'0');
session_name('IPDAMSSESSID');session_start();
header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'self'");
function er(mixed $v):string{return htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function mo(int $cents):string{return CashFundReports::peso($cents);}
function cell(mixed $v):string{return er($v!==null&&$v!==''?$v:'—');}
function moneyRead(mixed $v):string{return mo(moneyCents((string)$v));}
function oneRow(array $cols):void{echo '<tr>';foreach($cols as $cell)echo '<td>'.er($cell).'</td>';echo '</tr>';}
$reportCodes=['A80'=>'Appendix 80 · Monthly Bank Reconciliation','S1'=>'Schedule 1 · Outstanding Checks',
    'A35'=>'Appendix 35 · Report of Checks Issued','A40'=>'Appendix 40 · Cash Disbursements Record',
    'A43'=>'Appendix 43 · Cash Disbursements Register','A67'=>'Appendix 67 · Accountable Forms'];
$reportEmbedded=(($_GET['embedded']??'')==='1');
$r=(string)($_GET['report']??'A43');if(!isset($reportCodes[$r]))$r='A43';
$period=(string)($_GET['period']??date('Y-m'));
$org=(int)($_GET['organization_id']??0);$bankId=(int)($_GET['bank_account_id']??0);$fundId=(int)($_GET['fund_id']??0);
$err='';$data=null;$choices=['funds'=>[],'banks'=>[]];$organizations=[];
try{
    demand(in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true),'Read-only report endpoint',405);
    $db=database();$user=currentUser($db);
    demand(($user['role']??'')!=='VIEWER','Detailed finance reports are not available to this role',403);
    $organizations=$user['role']==='SUPER_ADMIN'
        ?q($db,'SELECT id,name,kind FROM organizations WHERE active=1 ORDER BY name LIMIT 201')->fetchAll(PDO::FETCH_ASSOC)
        :q($db,'SELECT o.id,o.name,o.kind FROM organizations o JOIN user_organization_scopes s ON s.organization_id=o.id WHERE s.user_id=? AND o.active=1 ORDER BY o.name LIMIT 201',[(int)$user['id']])->fetchAll(PDO::FETCH_ASSOC);
    if(!$org)$org=(int)($organizations[0]['id']??0);
    if($org){FinanceAccess::read($db,$user,$org);
        demand(one($db,'SELECT id FROM organizations WHERE id=? AND active=1',[$org])!==null,'Organization unavailable',404);
        $choices=['funds'=>q($db,'SELECT id,name,source_code,fiscal_year FROM funds WHERE organization_id=? ORDER BY fiscal_year DESC,source_code LIMIT 201',[$org])->fetchAll(PDO::FETCH_ASSOC),
          'banks'=>q($db,'SELECT id,bank_name,account_label,masked_account FROM bank_accounts WHERE organization_id=? ORDER BY bank_name LIMIT 201',[$org])->fetchAll(PDO::FETCH_ASSOC)];
        if(!$fundId)$fundId=(int)($choices['funds'][0]['id']??0);
        if(!$bankId)$bankId=(int)($choices['banks'][0]['id']??0);
        if(!empty($_GET['snapshot_id'])){
            $data=FinancialSnapshots::report($db,$user,$_GET+['organization_id'=>$org,'period'=>$period,'fund_id'=>$fundId,'report'=>$r]);
            $fundId=(int)$data['fund']['id'];$bankId=(int)$data['bank']['id'];$period=substr($data['from'],0,7);
        }
        elseif(!empty($_GET['download_id'])){
            demand($r==='A43','Download-specific reporting is available in the cash disbursements register; bank reconciliation remains account-wide',422);
            $data=(new FinanceMonitoring($db,$user))->downloadReport($_GET+['organization_id'=>$org,'period'=>$period]);
            $fundId=(int)$data['fund']['id'];$bankId=(int)$data['bank']['id'];
        }
        elseif($fundId&&$bankId)$data=CashFundReports::load($db,$user,['organization_id'=>$org,'fund_id'=>$fundId,'bank_account_id'=>$bankId,'period'=>$period,'report'=>$r]);
        else $err='Configure a fund source and a bank account before preparing financial reports.';
    }else $err='There is no authorized organization for this account.';
}catch(DomainError $e){http_response_code($e->status);$err=$e->getMessage();}
catch(Throwable $e){error_log('IPDAMS CashFundReports '.$e->getMessage());http_response_code(500);$err='Financial report could not be prepared. Technical review is required; nothing was posted.';}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>IPDAMS · Cash and Fund Reports</title><link rel="stylesheet" href="../assets/cash-fund-reports.css?v=0.2.460"><script src="../assets/cash-fund-reports.js" defer></script></head>
<body class="report-<?=er($r)?>"><div class="toolbar noprint">
 <div><div class="eyebrow">IPDAMS / CASH &amp; BANK</div><h1>Fund liquidation &amp; cash reports</h1><p>Prepared from existing source records. Printing never posts funds, alters taxes or changes check status.</p></div>
 <button type="button" data-report-print <?=$data?'':'disabled'?>>Print selected report</button>
</div>
<form class="filters noprint" method="get">
 <?php if($reportEmbedded):?><input type="hidden" name="embedded" value="1"><input type="hidden" name="organization_id" value="<?=er($org)?>"><?php else:?><label>School / Division<select name="organization_id" data-report-organization-change>
 <?php foreach($organizations as $o):?><option value="<?=er($o['id'])?>" <?=(int)$o['id']===$org?'selected':''?>><?=er($o['name'])?></option><?php endforeach;?></select></label><?php endif;?>
 <label>Fund source<select name="fund_id"><?php foreach($choices['funds'] as $f):?><option value="<?=er($f['id'])?>" <?=(int)$f['id']===$fundId?'selected':''?>><?=er($f['source_code'].' · FY '.$f['fiscal_year'].' · '.$f['name'])?></option><?php endforeach;?></select></label>
 <label>Bank account<select name="bank_account_id"><?php foreach($choices['banks'] as $b):?><option value="<?=er($b['id'])?>" <?=(int)$b['id']===$bankId?'selected':''?>><?=er($b['bank_name'].' / '.$b['account_label'].' / '.$b['masked_account'])?></option><?php endforeach;?></select></label>
 <label>Report month<input name="period" type="month" required value="<?=er($period)?>"></label>
 <label>Individual download (register only)<input name="download_id" type="number" min="1" value="<?=er($_GET['download_id']??'')?>"></label>
 <label>Historical snapshot ID<input name="snapshot_id" type="number" min="1" value="<?=er($_GET['snapshot_id']??'')?>"></label>
 <label>Report<select name="report"><?php foreach($reportCodes as $code=>$name):?><option value="<?=er($code)?>" <?=$code===$r?'selected':''?>><?=er($name)?></option><?php endforeach;?></select></label>
 <button type="submit">Generate report</button>
</form>
<nav class="report-tabs noprint"><?php foreach($reportCodes as $code=>$title):
 $tabParams=['organization_id'=>$org,'fund_id'=>$fundId,'bank_account_id'=>$bankId,'period'=>$period,'report'=>$code];
 if($reportEmbedded)$tabParams['embedded']=1;
 if(!empty($_GET['snapshot_id']))$tabParams['snapshot_id']=(int)$_GET['snapshot_id'];
 if($code==='A43'&&!empty($_GET['download_id']))$tabParams['download_id']=(int)$_GET['download_id'];
 $query=http_build_query($tabParams); ?>
 <a class="<?=$r===$code?'active':''?>" href="?<?=er($query)?>"><?=er($code)?></a><?php endforeach;?></nav>
<?php if($err):?><div class="alert">Review required: <?=er($err)?></div><?php elseif($data):
 $d=$data;$fund=$d['fund'];$bank=$d['bank'];$orgName=(string)($d['organization']['name']??'');
 $periodTitle=(new DateTimeImmutable($d['from']))->format('F Y');$asOf=(new DateTimeImmutable($d['end']))->format('F j, Y');
 $name=(string)$reportCodes[$r];$review=CashFundReports::reviewForReport($d,$r);$warningCount=count($review['review']); ?>
 <div class="context noprint"><strong><?=er($name)?></strong><span><?=er($orgName)?> · <?=er($fund['source_code'])?> · <?=er($periodTitle)?></span><span><?= $warningCount ? 'Review items: '.$warningCount : 'No additional report-specific review issues detected' ?></span><?php if($review['provisional']):?><strong>Provisional · month in progress</strong><?php endif;?></div>
 <div class="sheet">
 <?php if($r!=='A43'):?>
 <div class="appendix"><?=er($r==='S1'?'Schedule 1':'Appendix '.substr($r,1))?></div>
 <div class="center heading"><h2><?=er(match($r){'A80'=>'Monthly Bank Reconciliation Statement','S1'=>'List of Outstanding Checks','A35'=>'Report of Checks Issued','A40'=>'Cash Disbursements Record','A43'=>'Cash Disbursements Register','A67'=>'Report of Accountability for Accountable Forms'})?></h2>
 <strong><?=er($orgName)?></strong><div><?=er($fund['source_code'].' · '.$fund['name'].' · FY '.$fund['fiscal_year'])?></div>
 <div><?=er($bank['bank_name'].' / '.$bank['account_label'].' / '.$bank['masked_account'])?></div>
 <div><?=er(in_array($r,['A80','S1'],true)?'As of '.$asOf:'Period: '.$periodTitle)?></div></div>
 <div class="review-banner">REVIEW COPY — NOT CERTIFIED<?= $review['provisional']?' · PROVISIONAL PERIOD':'' ?></div>
 <?php endif;?>
 <?php if($r==='A80'):?>
 <h3>Bank reconciliation — whole account</h3>
 <p class="caption">Use the actual bank statement and account-wide books, including all funds using this bank account. The saved reconciliation is not a fund-specific statement.</p>
 <?php if(!empty($d['reconTyped'])):$typed=$d['reconTyped']; ?>
 <table class="statement" aria-label="Typed account-wide bank reconciliation"><thead><tr><th>Side / verified particulars</th><th>Amount</th><th>Evidence</th></tr></thead><tbody>
 <tr><th>Bank statement balance</th><td class="num"><?=mo((int)$typed['statement_cents'])?></td><td><?=er($typed['statement_evidence'])?></td></tr>
 <?php foreach($typed['lines'] as $line)if($line['side']==='BANK'):?><tr><td><?=er($line['kind'])?></td><td class="num"><?=mo((int)$line['cents'])?></td><td><?=er($line['evidence'])?></td></tr><?php endif;?>
 <tr class="total"><th>Adjusted bank balance</th><td class="num"><?=mo((int)$typed['totals']['adjusted_bank_cents'])?></td><td>Bank side only</td></tr>
 <tr><th>Verified account book balance</th><td class="num"><?=mo((int)$typed['book_cents'])?></td><td><?=er($typed['book_evidence'])?></td></tr>
 <?php foreach($typed['lines'] as $line)if($line['side']==='BOOK'):?><tr><td><?=er($line['kind'])?></td><td class="num"><?=mo((int)$line['cents'])?></td><td><?=er($line['evidence'])?></td></tr><?php endif;?>
 <tr class="total"><th>Adjusted book balance</th><td class="num"><?=mo((int)$typed['totals']['adjusted_book_cents'])?></td><td>Book side only</td></tr>
 <tr><th>Required maintaining balance</th><td class="num"><?=mo((int)$typed['maintaining_cents'])?></td><td>Preserved verified account requirement</td></tr>
 <tr class="total"><th>Difference (adjusted books less adjusted bank)</th><td class="num"><?=mo((int)$typed['totals']['difference_cents'])?></td><td><?=er($typed['status'])?></td></tr></tbody></table>
 <?php elseif($d['recon']): $savedBook=moneyCents((string)$d['recon']['book_balance']);$savedStatement=moneyCents((string)$d['recon']['statement_balance']);$adjustedBook=$savedBook+$d['reconAdjust']; ?>
 <table class="statement"><thead><tr><th>Particulars</th><th>Amount</th><th>Reference / status</th></tr></thead><tbody>
 <tr><th>Recorded book balance</th><td class="num"><?=mo($savedBook)?></td><td>Whole bank account</td></tr>
 <?php foreach($d['reconLines'] as $line):?><tr><td><?=er($line['kind'])?></td><td class="num"><?=er($line['amount'])?></td><td><?=er($line['reference'])?></td></tr><?php endforeach;?>
 <tr class="total"><th>Adjusted book balance</th><td class="num"><?=mo($adjustedBook)?></td><td>Book balance plus signed recorded adjustments</td></tr>
 <tr><th>Recorded bank statement balance</th><td class="num"><?=mo($savedStatement)?></td><td>Whole bank account</td></tr>
 <tr class="total"><th>Difference (adjusted book less statement)</th><td class="num"><?=mo($adjustedBook-$savedStatement)?></td><td><?=er($d['recon']['status'])?></td></tr>
 </tbody></table>
 <?php else:?><p class="caption">No saved reconciliation for this account and period. No bank statement or book balance has been inferred.</p><?php endif;?>
 <h3>Selected fund — separate cash projection</h3>
 <table><thead><tr><th>Particulars</th><th>Amount</th></tr></thead><tbody>
 <tr><td>Opening released-check projection</td><td class="num"><?=mo($d['opening'])?></td></tr>
 <tr><td>Verified bank-linked fund credits</td><td class="num"><?=mo($d['credit'])?></td></tr>
 <tr><td>Recorded released checks</td><td class="num"><?=mo($d['payment'])?></td></tr>
 <tr class="total"><td>Closing fund cash projection</td><td class="num"><?=mo($d['closing'])?></td></tr>
 <tr><td>Outstanding checks for selected fund (Schedule 1)</td><td class="num"><?=mo($d['outstandingCents'])?></td></tr>
 </tbody></table>
 <p class="caption">Do not subtract these fund-specific figures again from the saved account-wide reconciliation. Verify actual statement, book balances and adjustment evidence in Cash &amp; Bank → Bank Reconciliation.</p>
 <?php elseif($r==='S1'):?>
 <table><thead><tr><th>Check date (same as DV)</th><th>Recorded issuance date</th><th>Payee</th><th>Check No.</th><th>DV No.</th><th>Amount</th><th>Status at cutoff</th></tr></thead><tbody>
 <?php foreach($d['outstanding'] as $c):?><tr><td><?=er($c['check_date']?:'DV date missing')?></td><td><?=er($c['issue_date'])?></td><td><?=er($c['payee_name']??$c['dv_payee'])?></td><td><?=er($c['check_number'])?></td><td><?=er($c['dv_number'])?></td><td class="num"><?=moneyRead($c['amount'])?></td><td><?=er($c['stage_at_end'])?></td></tr><?php endforeach;?>
 <tr class="total"><td colspan="5">Grand total</td><td class="num"><?=mo($d['outstandingCents'])?></td><td><?=count($d['outstanding'])?> check(s)</td></tr>
 </tbody></table><p class="caption">The check date always matches its linked finalized DV. The separate recorded issuance date is audit evidence. Outstanding is determined from actual lifecycle events as of month-end; later clearing does not erase prior-month history.</p>
 <?php elseif($r==='A35'):?>
 <table><thead><tr><th>Date of check (DV)</th><th>Check serial</th><th>DV / Payroll</th><th>ORS / BURS</th><th>Fund / responsibility center</th><th>Payee</th><th>UACS</th><th>Nature of payment</th><th>Check amount</th></tr></thead><tbody>
 <?php foreach($d['periodChecks'] as $c):
 $dv=$c['dv_id'];$first=null;foreach($d['dvRows'] as $row)if((int)$row['check']['id']===(int)$c['id']){$first=$row;break;}
 $codes=$first?implode(', ',array_keys($first['uacs'])):''; ?>
 <tr><td><?=er($c['check_date'])?></td><td><?=er($c['check_number'])?></td><td><?=er($c['dv_number'])?></td><td>Not recorded</td><td><?=er($fund['source_code'])?></td><td><?=er($c['payee_name']??$c['dv_payee'])?></td><td><?=er($codes?:'Review')?></td><td><?=er($c['dv_type'])?></td><td class="num"><?=moneyRead($c['amount'])?></td></tr>
 <?php endforeach;?><tr class="total"><td colspan="8">TOTAL (issued check values, including any later-cleared checks)</td><td class="num"><?=mo($d['issuedValue'])?></td></tr></tbody></table>
 <p class="caption">Date printed on check comes from finalized DV. Inclusion in this monthly issued register uses the actual check issuance record date.</p>
 <?php elseif($r==='A40'):?>
 <table><thead><tr><th>Date</th><th>ADA / Check / DV reference</th><th>Payee / Source</th><th>UACS / nature</th><th>Cash receipt</th><th>Disbursement</th><th>Projected balance</th></tr></thead><tbody>
 <tr><td><?=er($d['prev'])?></td><td>Opening book projection</td><td><?=er($fund['source_code'])?></td><td></td><td></td><td></td><td class="num"><?=mo($d['opening'])?></td></tr>
 <?php $moves=[];foreach($d['allPeriodDownloads'] as $e)$moves[]=['date'=>$e['bank_credit_date'],'sort'=>0,'type'=>'DOWNLOAD','e'=>$e];foreach($d['periodReleased'] as $c)$moves[]=['date'=>$c['release_date'],'sort'=>1,'type'=>'CHECK','e'=>$c];
 usort($moves,static fn($a,$b)=>strcmp($a['date'],$b['date'])?:($a['sort']<=>$b['sort']));$running=$d['opening'];
 foreach($moves as $m):$e=$m['e'];$received=$m['type']==='DOWNLOAD'?moneyCents((string)$e['amount']):0;$paid=$m['type']==='CHECK'?moneyCents((string)$e['amount']):0;$running+=$received-$paid;?>
 <tr><td><?=er($m['date'])?></td><td><?=er($m['type']==='DOWNLOAD'?($e['bank_credit_ref']??'Bank download'):$e['check_number'].' / '.$e['dv_number'])?></td>
 <td><?=er($m['type']==='DOWNLOAD'?($e['reason']??'Verified bank download'):($e['payee_name']??$e['dv_payee']).' · Check/DV date '.($e['check_date']?:'UNVERIFIED'))?></td>
 <td><?=er($m['type']==='DOWNLOAD'?'Verified bank credit':'Released check payment')?></td><td class="num"><?=$received?mo($received):'—'?></td><td class="num"><?=$paid?mo($paid):'—'?></td><td class="num"><?=mo($running)?></td></tr>
 <?php endforeach;?><tr class="total"><td colspan="4">Period totals (reversals shown separately)</td><td class="num"><?=mo($d['credit'])?></td><td class="num"><?=mo($d['payment'])?></td><td class="num"><?=mo($d['closing'])?></td></tr>
 <?php if($d['periodReversals']):?><tr><td colspan="7">The issued-check commitment book has <?=mo($d['periodReversals'])?> of cancellation/replacement reversals; this is not a bank cash receipt or confirmed refund.</td></tr><?php endif;?></tbody></table>
 <p class="caption">The running balance uses verified bank credits minus recorded RELEASED checks. It is a draft cash workpaper, not a certified bank statement or independent cash advance record.</p>
 <?php elseif($r==='A43'): require $privateApp.'/Appendix43Print.php'; ?>
 <?php elseif($r==='A67'):?>
 <table><thead><tr><th>Name of accountable form</th><th>Checkbook reference</th><th>Face value</th><th>Beginning quantity*</th><th>Registered this month</th><th>Issued this month</th><th>Remaining quantity*</th><th>Inclusive serials</th></tr></thead><tbody>
 <?php $byBook=[];foreach($d['leavesPeriod'] as $leaf)$byBook[$leaf['book_reference']][]=$leaf;
 foreach($byBook as $book=>$leaves):$start=0;$receipts=0;$issued=0;$serials=[];
 foreach($leaves as $leaf){$serials[]=(string)$leaf['serial'];if(!empty($leaf['open_at_start']))$start++;if(!empty($leaf['received_this_month']))$receipts++;if(!empty($leaf['issued_this_month']))$issued++;}
 usort($serials,'strnatcmp');?><tr><td>Bank checks</td><td><?=er($book)?></td><td>Bank checks (see verified physical stock)</td><td class="num"><?=$start?></td><td class="num"><?=$receipts?></td><td class="num"><?=$issued?></td><td class="num"><?=($start+$receipts-$issued)?></td><td><?=er(($serials[0]??'—').' – '.($serials[count($serials)-1]??'—'))?></td></tr><?php endforeach;?>
 </tbody></table><p class="caption">*Historical beginning/ending quantities are projections from registration and issuance timestamps, not independently certified book-stock counts. Spoiled/void/lost and transferred leaves require evidence review.</p>
 <?php endif;?>
 <?php if($r!=='A43'):?><div class="signatures"><div>Prepared by:<div class="sigline"></div><small>Printed name, designation and date</small></div><div>Certified correct by:<div class="sigline"></div><small>Authorized official's physical signature and date</small></div></div><?php endif;?>
 </div>
 <?php if($review['review']):?><section class="warnings noprint"><h3>Review items for this report</h3><ul><?php foreach($review['review'] as $w):?><li><?=er($w)?></li><?php endforeach;?></ul>
 <?php if($r==='A80'):?><p class="caption">To resolve reconciliation issues, verify the account-wide bank statement and book balance, enter supported signed adjustments in Cash &amp; Bank → Bank Reconciliation, and close only when the adjusted book balance equals the statement.</p><?php endif;?></section><?php endif;?>
 <?php if($review['provisional']||$review['context']):?><section class="report-notes noprint"><h3>Reporting basis</h3><?php foreach($review['provisional'] as $w):?><p><strong><?=er($w)?></strong></p><?php endforeach;?><?php foreach($review['context'] as $w):?><p><?=er($w)?></p><?php endforeach;?></section><?php endif;?>
 <?php if(!empty($d['report_basis_note'])&&$r!=='A43'):?><p class="caption"><?=er($d['report_basis_note'])?></p><?php endif;?>
 <p class="footer noprint">Read-only reporting workpaper · Source records remain unchanged · Not a certification of liquidation or bank reconciliation.</p>
<?php endif;?></body></html>
