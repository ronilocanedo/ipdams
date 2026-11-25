<?php
declare(strict_types=1);
/** IPDAMS v0.2.227 — School-only numbering view. No technical Settings controls. */
final class IPDAMSSchoolNumberingPage {
    private static function e(mixed $v): string {
        return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
    public static function run(PDO $db,array $user,bool $https): never {
        demand(($user['role']??null)==='SCHOOL','School Administrator access required',403);
        demand(in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','POST'],true),'Unsupported method',405);
        $error='';$success='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            try{
                demand($https||cfg('APP_ENV')!=='production','HTTPS required for numbering setup',403);
                demand(hash_equals((string)($_SESSION['csrf']??''),(string)($_POST['_csrf']??'')),'Invalid CSRF token',403);
                demand(($_POST['settings_tab']??'')==='numbering' && ($_POST['action']??'')==='numbering_save',
                    'Only School Numbering may be changed here',403);
                $row=one($db,'SELECT password_hash FROM users WHERE id=?',[(int)$user['id']]);
                demand($row!==null && password_verify((string)($_POST['password']??''),(string)$row['password_hash']),
                    'Current account password incorrect',403);
                $saved=IPDAMSNumberingSetup::saveMany($db,$user,
                    $_POST['school_id']??null,$_POST['periods']??null,$_POST['sequences']??null);
                $success='Saved '.(int)$saved['count'].' form sequence(s) for '.$saved['school'].'. Issued documents were not renumbered.';
                unset($_POST['periods'],$_POST['sequences']);
            }catch(DomainError $e){$error=$e->getMessage();http_response_code($e->status);}
            catch(Throwable $e){error_log('IPDAMS school numbering: '.$e->getMessage());$error='Numbering update failed. Contact the technical administrator.';http_response_code(500);}
        }
        try{
            $schools=IPDAMSNumberingSetup::schoolOptions($db,$user);
            $existing=IPDAMSNumberingSetup::existing($db,$user);
        }catch(Throwable $e){error_log('IPDAMS school numbering list: '.$e->getMessage());$schools=[];$existing=[];$error=$error?:'School numbering is unavailable; contact your technical administrator.';http_response_code(503);}
        $selected=is_string($_POST['school_id']??null)?(string)$_POST['school_id']:'';
        if($selected==='' && count($schools)===1)$selected=(string)$schools[0]['id'];
        $validSchool=array_filter($schools,fn($s)=>(string)$s['id']===$selected);
        if(!$validSchool)$selected=count($schools)===1?(string)$schools[0]['id']:'';
        $periods=is_array($_POST['periods']??null)?$_POST['periods']:[];
        $series=is_array($_POST['sequences']??null)?$_POST['sequences']:[];
        $labels=[
            'Travel & Training'=>['AR'=>'Activity Request','TA'=>'Travel Authority','RC'=>'Reimbursement Claim'],
            'Procurement'=>['PR'=>'Purchase Request','RFQ'=>'Request for Quotation','AWARD'=>'Award','PO'=>'Purchase Order','IAR'=>'Inspection and Acceptance Report'],
            'Disbursement'=>['DV'=>'Disbursement Voucher'],
            'Supply & Property'=>['RIS'=>'Requisition and Issue Slip','ICS'=>'Inventory Custodian Slip','PAR'=>'Property Acknowledgement Receipt','ITR'=>'Inventory Transfer Report','PTR'=>'Property Transfer Report','RRSP'=>'Receipt of Returned Semi-Expendable Property','PRR'=>'Property Return Record (PPE, internal)'],
        ];
        $e=fn(mixed $v)=>self::e($v);
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>IPDAMS · School Numbering</title><link rel="stylesheet" href="settings.css?v=02227-school-numbering"><link rel="stylesheet" href="assets/dense-ui.css?v=0225"></head><body>
<div class="settings-shell"><aside class="settings-sidebar" aria-label="School settings navigation">
<div class="settings-brand"><div><strong>IPDAMS</strong><small>OPERATIONS PLATFORM</small></div></div>
<div class="settings-side-label">SCHOOL SETTINGS</div>
<nav class="settings-main-nav"><a class="settings-navitem active" href="settings.php?tab=numbering" aria-current="page">School Numbering</a><a class="settings-navitem" href="index.php">Return to Workspace</a></nav>
</aside><div class="settings-content"><main>
<div class="ipdams-settings-banner"><div><div class="settings-eyebrow">IPDAMS / SCHOOL SETTINGS</div><h1>School Numbering</h1><p class="lead">Manage continuing annual document-number sequences for your assigned school.</p><p><a href="index.php">Return to IPDAMS workspace</a></p></div></div>
<?php if($error!==''):?><p class="alert bad" role="alert"><?=$e($error)?></p><?php endif;?>
<?php if($success!==''):?><p class="alert good" role="status"><?=$e($success)?></p><?php endif;?>
<section class="panel"><h2>Configure control numbers</h2>
<p class="muted">Only assigned schools are available. Fill both fields for each form you want to configure. Blank rows are skipped. If any row conflicts with an issued document, no rows are saved. Official document numbers and physical checkbook serials are never changed.</p>
<?php if(!$schools):?><p class="alert bad">No active school is assigned to this account. Ask your Division Administrator to review school access.</p><?php else:?>
<form method="post" action="settings.php?tab=numbering" autocomplete="off"><input type="hidden" name="_csrf" value="<?=$e($_SESSION['csrf']??'')?>"><input type="hidden" name="settings_tab" value="numbering"><input type="hidden" name="action" value="numbering_save">
<label for="school-numbering-organization">School
<select id="school-numbering-organization" name="school_id" required><option value="">Choose your school</option><?php foreach($schools as $school):?><option value="<?=$e($school['id'])?>" <?=(string)$school['id']===$selected?'selected':''?>><?=$e($school['name'])?></option><?php endforeach;?></select></label>
<div class="scroll"><table class="numbering-setup-table"><thead><tr><th scope="col">Form</th><th scope="col">Calendar year (YYYY)</th><th scope="col">Next series (1–9999)</th></tr></thead><tbody>
<?php foreach($labels as $group=>$forms):?><tr><th colspan="3" scope="colgroup"><?=$e($group)?></th></tr>
<?php foreach($forms as $type=>$label):?><tr><th scope="row"><strong><?=$e($type)?></strong> — <?=$e($label)?></th>
<td><input type="text" name="periods[<?=$e($type)?>]" value="<?=$e(is_string($periods[$type]??null)?$periods[$type]:'')?>" pattern="20[0-9]{2}" inputmode="numeric" maxlength="4" placeholder="YYYY" aria-label="<?=$e($type)?> year"></td>
<td><input type="text" name="sequences[<?=$e($type)?>]" value="<?=$e(is_string($series[$type]??null)?$series[$type]:'')?>" pattern="[1-9][0-9]{0,3}" inputmode="numeric" maxlength="4" placeholder="e.g. 125" aria-label="<?=$e($type)?> next series"></td></tr><?php endforeach;endforeach;?></tbody></table></div>
<p class="muted">All official forms continue their own annual series per school. The document number still shows the issue month, e.g. 2026-10-0001 then 2026-11-0002. Existing issued documents lock the starting number for that year. Older monthly settings remain as protective minimums; no issued numbers are changed.</p>
<label>Current account password<input name="password" type="password" autocomplete="current-password" required></label>
<button type="submit">Save All Changes</button></form><?php endif;?></section>
<?php if($existing):?><section class="panel"><details><summary>Review existing school sequences</summary><div class="scroll"><table><thead><tr><th>School</th><th>Form</th><th>Year / prior month setting</th><th>Next series</th></tr></thead><tbody><?php foreach($existing as $row):?><tr><td><?=$e($row['school'])?></td><td><?=$e($row['doc_type'])?></td><td><?=$e(str_ends_with((string)$row['period'],'-00') ? substr((string)$row['period'],0,4).' (annual)' : (string)$row['period'].' (prior monthly)')?></td><td><?=$e($row['next_sequence'])?></td></tr><?php endforeach;?></tbody></table></div></details></section><?php endif;?>
</main></div></div></body></html>
<?php exit;
    }
}
