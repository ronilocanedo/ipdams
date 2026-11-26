<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/app/bootstrap.php';
require_once dirname(__DIR__).'/private/app/MonthlyIssuances.php';
ini_set('display_errors','0');
ini_set('log_errors','1');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$reportEmbedded=(($_GET['embedded']??'')==='1');
header('X-Frame-Options: '.($reportEmbedded?'SAMEORIGIN':'DENY'));
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; form-action 'self'; object-src 'none'; base-uri 'self'; frame-ancestors ".($reportEmbedded?"'self'":"'none'"));
ini_set('session.use_strict_mode','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');
session_name('IPDAMSSESSID');session_start();
function reportEsc(mixed $v): string {return htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$error='';$report=null;$organizations=[];$target=0;$selectedType='ALL';$availableTypes=[];
$timezone=new DateTimeZone('Asia/Manila');
$now=new DateTimeImmutable('now',$timezone);
$chosenYear=(string)($_GET['year']??$now->format('Y'));
$chosenMonth=(string)($_GET['month']??$now->format('n'));
try {
    $db=database();$user=currentUser($db);
    demand($user['role']!=='VIEWER','Use monthly issued RIS/ICS/PAR documents in the reviewer workspace',403);
    if($user['role']==='VIEWER'){
        $ids=ipdamsMooeViewerSchools($db,$user);
        $organizations=[];
        if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));
            $organizations=q($db,"SELECT id,name,kind FROM organizations WHERE kind='SCHOOL' AND active=1 AND id IN ($marks) ORDER BY name",$ids)->fetchAll();}
    }else{
        $organizations=$user['role']==='SUPER_ADMIN'
            ?q($db,'SELECT id,name,kind FROM organizations ORDER BY name')->fetchAll()
            :q($db,'SELECT o.id,o.name,o.kind FROM organizations o JOIN user_organization_scopes s ON s.organization_id=o.id WHERE s.user_id=? ORDER BY o.name',[(int)$user['id']])->fetchAll();
    }
    $target=$_GET['organization_id']??($organizations[0]['id']??0);
    $selectedType=(string)($_GET['type']??'ALL');
    foreach($organizations as $office)if((string)$office['id']===(string)$target){$availableTypes=MonthlyIssuances::typesFor($user,$office);break;}
    if($target){
        scope($db,$user,(int)$target);
        $report=(new MonthlyIssuances($db,$user))->report(['organization_id'=>$target,'year'=>$chosenYear,'month'=>$chosenMonth,'type'=>$selectedType]);
    }else{$error='No authorized organization is available for reporting.';}
} catch(DomainError $e){http_response_code($e->status);$error=$e->getMessage();}
catch(Throwable $e){error_log('IPDAMS monthly issuances report failure: '.$e->getMessage());http_response_code(500);$error='Unable to load monthly issuances. Ask the technical administrator to review server logs.';}
$months=[1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>IPDAMS · Monthly Document Issuances</title>
<link rel="stylesheet" href="../assets/monthly-issuances.css"><script src="../assets/monthly-issuances.js" defer></script></head>
<body<?=$reportEmbedded?' class="reports-embedded"':''?>>
<nav class="report-tools" aria-label="Report tools">
  <?php if(!$reportEmbedded):?><a href="../index.php">← Back to IPDAMS</a><?php endif;?>
  <strong>Monthly Document Issuances</strong>
  <button id="print-report" type="button" <?php if(!$report): ?>disabled<?php endif; ?>>Print / Save as PDF</button>
</nav>
<main class="report-sheet">
  <header class="report-heading">
    <div class="kicker">INTEGRATED PROCUREMENT, DISBURSEMENT & ASSET MANAGEMENT SYSTEM</div>
    <h1>Monthly Document Issuance Register</h1>
    <p>Read-only register of allocated control numbers</p>
  </header>
  <form method="get" action="monthly_issuances.php" class="filters report-no-print">
    <?php if($reportEmbedded):?><input type="hidden" name="embedded" value="1"><input type="hidden" name="organization_id" value="<?=reportEsc($target)?>"><?php else:?><label>Organization<select name="organization_id" required>
    <?php foreach($organizations as $org): ?><option value="<?=reportEsc($org['id'])?>" <?php if((string)$org['id']===(string)$target): ?>selected<?php endif; ?>><?=reportEsc($org['name'])?> (<?=reportEsc($org['kind'])?>)</option><?php endforeach; ?>
    </select></label><?php endif;?>
    <label>Year<select name="year"><?php for($y=(int)$now->format('Y')+1;$y>=1900;$y--): ?><option value="<?=$y?>" <?php if((string)$y===$chosenYear): ?>selected<?php endif; ?>><?=$y?></option><?php endfor; ?></select></label>
    <label>Month<select name="month"><?php foreach($months as $n=>$label): ?><option value="<?=$n?>" <?php if((int)$chosenMonth===$n): ?>selected<?php endif; ?>><?=reportEsc($label)?></option><?php endforeach; ?></select></label>
    <label>Document<select name="type"><option value="ALL" <?php if($selectedType==='ALL'): ?>selected<?php endif; ?>>All numbered types</option><?php foreach(($report['available_types']??$availableTypes) as $key=>$label): ?><option value="<?=reportEsc($key)?>" <?php if($selectedType===$key): ?>selected<?php endif; ?>><?=reportEsc($label)?></option><?php endforeach; ?></select></label>
    <button type="submit">Show register</button>
  </form>
<?php if($error!==''): ?>
  <p class="report-error" role="alert"><?=reportEsc($error)?></p>
<?php elseif($report): ?>
  <section class="report-metadata" aria-label="Report summary">
    <div><span>Organization</span><strong><?=reportEsc($report['organization']['name'])?></strong></div>
    <div><span>Control-number month</span><strong><?=reportEsc($months[$report['month']])?> <?=reportEsc($report['year'])?></strong></div>
    <div><span>Document category</span><strong><?=reportEsc($selectedType==='ALL'?'All numbered types':MonthlyIssuances::TYPES[$selectedType])?></strong></div>
    <div><span>Total numbers</span><strong><?=reportEsc($report['total'])?></strong></div>
  </section>
  <?php if($report['counts']): ?><div class="type-summary"><strong>Issued numbers by type:</strong> <?php foreach($report['counts'] as $key=>$total): ?><span><?=reportEsc($key)?>: <?=reportEsc($total)?></span><?php endforeach; ?></div><?php endif; ?>
  <table class="report-table">
    <thead><tr><th scope="col">No.</th><th scope="col">Document</th><th scope="col">Control Number</th><th scope="col">Recorded timestamp</th></tr></thead>
    <tbody><?php if(!$report['rows']): ?><tr><td colspan="4" class="empty">No control numbers were issued in this period for the selected organization and type.</td></tr>
    <?php else: ?><?php foreach($report['rows'] as $i=>$row): ?><tr><td><?=reportEsc($i+1)?></td><td><?=reportEsc($row['label'])?></td><td class="mono"><?=reportEsc($row['display_number'])?></td><td><?=reportEsc($row['created_at'])?></td></tr><?php endforeach; ?><?php endif; ?></tbody>
  </table>
  <footer class="report-notes">
    <p><strong>Basis:</strong> The month is the period of the allocated control number. Recorded timestamps come from the system registry and may differ from document dates. All allocated numbers, including subsequently cancelled numbers, remain in the register; refer to the individual document for its status and wet-signed copy.</p>
    <p class="prepared">Register viewed: <?=reportEsc($report['prepared_at'])?> (Philippine time)</p>
  </footer>
<?php endif; ?>
</main>
</body></html>
