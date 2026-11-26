<?php
declare(strict_types=1);
require_once __DIR__.'/private/app/ResetService.php';
require_once __DIR__.'/private/app/NumberingSetup.php';
require_once __DIR__.'/private/app/SchoolNumberingPage.php';
require_once __DIR__.'/private/app/BackupService.php';
require_once __DIR__.'/private/app/SchemaHealth.php';
require_once __DIR__.'/private/app/DateQuality.php';
require_once __DIR__.'/private/app/SystemBranding.php';
$isHttps=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
ini_set('display_errors','0');ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_samesite','Lax');ini_set('session.cookie_secure',$isHttps?'1':'0');
session_name('IPDAMSSESSID');session_start();
header('X-Frame-Options: DENY');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
$_SESSION['csrf']??=bin2hex(random_bytes(24));
function e(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function shortHash(string $hash):string{return substr($hash,0,12).'…';}
function settingsRenderReleaseRows(array $rows,array $state,bool $featured=false):void { ?>
<?php foreach($rows as $r):?>
<?php $manifest=$r['manifest']??[];$isLegacy=!isset($manifest['version']);$rid=(string)$r['id'];$critical=array_filter(array_keys($r['files']??[]),'settingsCritical');$preflight=(!$isLegacy&&$r['status']==='staged')?settingsPreflight($rid):null;
$rollbackCheck=(!$isLegacy&&$r['status']==='applied')?((($state['release_id']??null)===$rid)?settingsRollbackPreflight($rid):['ok'=>false,'issues'=>['Restore the newest installed release first.']]):null;
$isActiveUpdate=$rollbackCheck!==null&&($state['release_id']??null)===$rid;
$collapseRecord=!$isActiveUpdate && !$featured;
?>
<?php if($collapseRecord):?><details class="history-record"><summary><?=e($isLegacy?'Legacy package':('v'.$manifest['version'].' · '.$manifest['title']))?> — <?=e((string)$r['status'])?></summary><?php endif;?>
<div class="release"><div class="relhead"><strong><?=e($isLegacy?'Legacy package':('v'.$manifest['version'].' · '.$manifest['title']))?></strong><span class="pill <?=e((string)$r['status'])?>"><?=e((string)$r['status'])?></span></div>
<p class="muted"><?=e($rid)?> · <?=e((string)$r['created'])?> · <?=count($r['files']??[])?> file(s)</p>
<?php if(!$isLegacy):?><p><?=e((string)($manifest['notes']??''))?></p><p class="muted">Requires v<?=e((string)$manifest['from_version'])?> · PHP <?=e((string)$manifest['min_php'])?>+ · ZIP SHA256 <?=e(shortHash((string)$r['sha256']))?></p><?php endif;?>
<?php if(!empty($manifest['migrations'])):?><div class="preflight"><strong>Automatic database migrations: <?=count($manifest['migrations'])?></strong><p>Protected database backup is created and verified before the migration is applied. MariaDB schema changes cannot be reversed by file rollback.</p><ul><?php foreach($manifest['migrations'] as $migrationId=>$migrationInfo):?><li><code><?=e((string)$migrationId)?></code></li><?php endforeach;?></ul></div><?php endif;?>
<?php if(!empty($r['migration_backup']['sha256'])&&$r['status']==='applied'):?><p><a href="settings.php?tab=updates&amp;migration_backup=<?=e($rid)?>">Download verified pre-migration database backup</a> (confidential)</p><?php endif;?>
<details><summary>Changed files and checksums</summary><div class="scroll"><table><thead><tr><th>File</th><th>Change</th><th>SHA256 (first 12)</th></tr></thead><tbody>
<?php foreach(($r['files']??[]) as $path=>$sha):?><tr><td><code><?=e((string)$path)?></code></td><td><?=e((string)($r['changes'][$path]['type']??'Legacy'))?></td><td><code><?=e(shortHash((string)$sha))?></code></td></tr><?php endforeach;?></tbody></table></div></details>
<?php if($preflight!==null):?><div class="preflight"><strong>Automatic pre-installation checks</strong><p class="<?= $preflight['ok']?'ok-text':'bad-text' ?>"><?= $preflight['ok']?'✓ File hashes, required version, runtime, write access and recovery storage passed.':'✗ One or more checks need attention.' ?></p><?php foreach($preflight['issues'] as $issue):?><p class="alert bad"><?=e($issue)?></p><?php endforeach;?></div>
<form method="post" class="review-form"><input type="hidden" name="_csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="settings_tab" value="updates"><input type="hidden" name="release_id" value="<?=e($rid)?>"><label class="check-label"><input type="checkbox" name="reviewed" value="1" required> I have reviewed the update and have a separate database/private-file backup ready for recovery.</label>
<?php if($critical):?><label class="check-label"><input type="checkbox" name="critical_reviewed" value="1" required> This release changes core updater or public entrypoint code; I understand it may require manual Hostinger recovery if something goes wrong.</label><?php endif;?>
<?php if(!empty($manifest['migrations'])):?><label class="check-label"><input type="checkbox" name="migration_reviewed" value="1" required> I reviewed the database migrations and understand file rollback cannot undo database changes. A verified backup will be made before application.</label><?php endif;?>
<button name="action" value="apply" <?=$preflight['ok']?'':'disabled'?>>Apply v<?=e((string)$manifest['version'])?></button></form>
<?php elseif($rollbackCheck!==null):?>
<?php if($rollbackCheck['ok']):?>
<div class="rollback-availability"><strong>Rollback available</strong><span>Active release; original backup and installed files verified.</span></div>
<form method="post" class="rollback-form"><input type="hidden" name="_csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="settings_tab" value="updates"><input type="hidden" name="release_id" value="<?=e($rid)?>"><details><summary>Emergency file rollback</summary><p class="warning">Restore v<?=e((string)$manifest['version'])?> to v<?=e((string)$manifest['from_version'])?>. Database changes are not restored. Recheck eligibility when submitting.</p><label>Current password <input name="password" type="password" autocomplete="current-password" required></label><label>Type ROLLBACK <input name="confirm" required></label><button class="danger" type="submit" name="action" value="rollback">Rollback latest release</button></details></form>
<?php else:?>
<div class="rollback-locked" role="status"><button type="button" disabled aria-disabled="true" title="This release is not currently eligible for rollback">Rollback locked</button><div><strong><?=e($isActiveUpdate?'Recovery unavailable':'Older version — history only')?></strong>
<?php if($isActiveUpdate):?><p class="muted">The active release cannot be restored until the following issues are corrected:</p><ul><?php foreach($rollbackCheck['issues'] as $issue):?><li><?=e((string)$issue)?></li><?php endforeach;?></ul>
<?php else:?><p class="muted">Roll back the current release first. This older release is retained for reference and cannot be selected out of sequence.</p><?php endif;?></div></div>
<?php endif;?>
<?php elseif($r['status']==='rolled_back'):?><p class="rollback-historical">Previously rolled back<?=isset($r['rolled_back_at'])?' on '.e((string)$r['rolled_back_at']):''?>. Historical record only; no rollback action.</p>
<?php elseif($r['status']==='staged'):?><p class="rollback-historical">Staged only — not installed; rollback is unavailable.</p>
<form method="post"><input type="hidden" name="_csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="settings_tab" value="updates"><input type="hidden" name="release_id" value="<?=e($rid)?>"><button type="submit" class="secondary-action" name="action" value="discard_staged">Discard unused ZIP</button></form>
<?php elseif($r['status']==='discarded'):?><p class="rollback-historical">Unused upload discarded; history preserved.</p>
<?php endif;?></div>
<?php if($collapseRecord):?></details><?php endif;?>
<?php endforeach;?>
<?php }

$backupReady=null;$backupStatus='';$backupPreflight=null;$error='';$success='';$results=null;$user=null;$lastUpload=null;$resetPreview=null;$resetOutcome=null;$schemaHealth=null;$dateQuality=null;
$activeTab=(string)($_POST['settings_tab']??$_GET['tab']??'updates');if(!in_array($activeTab,['updates','health','sql','reset','numbering','branding','backups','maintenance'],true))$activeTab='updates';
try{
    $db=database();
    // School users can open the isolated numbering page only. They never enter
    // the technical Settings controller's reset, SQL, update or backup logic.
    if($activeTab==='numbering') {
        $numberingActor=currentUser($db);
        if(($numberingActor['role']??null)==='SCHOOL')
            IPDAMSSchoolNumberingPage::run($db,$numberingActor,$isHttps);
    }
    $user=settingsAdmin($db);
    // v0.2.185: consume the successful apply result once on a fresh GET. A
    // browser refresh must never replay the previous installation POST.
    if (($_SERVER['REQUEST_METHOD']??'GET')==='GET' && isset($_SESSION['ipdams_update_apply_notice'])) {
        $notice=$_SESSION['ipdams_update_apply_notice'];
        unset($_SESSION['ipdams_update_apply_notice']);
        if (is_array($notice) && (int)($notice['uid']??0)===(int)$user['id']
            && is_string($notice['text']??null)) $success=$notice['text'];
    }
    $canBrand=brandingOwner($db,$user);
    if($activeTab==='branding')brandingRequireOwner($db,$user);
    if($activeTab==='maintenance')maintenanceRequireOwner($db,$user);
    if($activeTab==='updates' && isset($_GET['migration_backup']))settingsDownloadMigrationBackup((string)$_GET['migration_backup'],(int)$user['id']);
    if($activeTab==='reset' && isset($_GET['reset_download'])) resetDownload($db,(int)$user['id'],(string)$_GET['reset_download']);
    if($activeTab==='backups' && isset($_GET['backup_download'])) throw new DomainError('Use the authenticated download form to retrieve backups',405);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        demand(hash_equals((string)($_SESSION['csrf']??''),(string)($_POST['_csrf']??'')),'Invalid CSRF token',403);
        $action=(string)($_POST['action']??'');
        if(in_array($action,['rollback','query_write','reset_apply','backup_create','backup_create_source','numbering_save','maintenance_schedule','maintenance_enable_now','maintenance_disable','maintenance_preset_save','maintenance_preset_delete'],true)){
            $row=one($db,'SELECT password_hash FROM users WHERE id=?',[$user['id']]);
            demand($row!==null&&password_verify((string)($_POST['password']??''),(string)$row['password_hash']),'Current password incorrect',403);
        }
        if(in_array($action,['query_write','apply','rollback','upload','branding_upload','branding_restore','backup_create','backup_create_source'],true))demand(!is_file(settingsPrivate().'/reset-maintenance.lock'),'System reset in progress',503);
        if(str_starts_with($action,'maintenance_')){
            demand($activeTab==='maintenance','Open Maintenance Control before changing system access',403);
            maintenanceRequireOwner($db,$user);
            demand($isHttps||cfg('APP_ENV')!=='production','HTTPS is required for owner maintenance controls',403);
            $success=maintenanceUpdate($_POST,(int)$user['id']);
        }elseif($action==='backup_create'){
            demand($activeTab==='backups','Open Backup Center to create a full backup',403);
            demand($isHttps||cfg('APP_ENV')!=='production','HTTPS is required for confidential backups',403);
            $mode=(string)($_POST['backup_mode']??'');
            demand(in_array($mode,['plain','encrypted'],true),'Select a full-backup protection option',422);
            if($mode==='plain'){
                demand(($_POST['acknowledge_unencrypted_full']??'')==='1',
                    'Confirm that this password-free full backup contains confidential database and account records.',422);
                // Never read or retain a stale passphrase for unencrypted exports.
                $pass='';
            }else{
                $pass=(string)($_POST['backup_passphrase']??'');
                demand(hash_equals($pass,(string)($_POST['backup_passphrase_confirm']??'')),
                    'Backup encryption password confirmation does not match',422);
                demand(strlen($pass)>=16&&strlen($pass)<=200,
                    'Backup encryption password must be 16–200 characters',422);
            }
            $backupReady=ipdamsBackupCreate($db,(int)$user['id'],$pass);
            $_SESSION['backup_access_id']=$backupReady['id'];
            $_SESSION['backup_access_uid']=(int)$user['id'];
            $_SESSION['backup_access_expires']=time()+IPDAMS_BACKUP_SESSION_TTL;
            $success=$mode==='plain'
                ?'Both full recovery ZIPs were created without a ZIP password and verified. They contain unencrypted confidential data: download BOTH immediately, secure them off-host, and delete any unnecessary copies. Temporary files expire in one hour.'
                :'Both AES-256 recovery ZIPs were created and verified. Download BOTH archives now and store the encryption password separately. Temporary files expire in one hour.';
        }elseif($action==='backup_create_source'){
            demand($activeTab==='backups','Open Backup Center to export source',403);
            demand($isHttps||cfg('APP_ENV')!=='production','HTTPS required for source downloads',403);
            $backupReady=ipdamsBackupCreateSourceOnly((int)$user['id']);
            $_SESSION['backup_access_id']=$backupReady['id'];
            $_SESSION['backup_access_uid']=(int)$user['id'];
            $_SESSION['backup_access_expires']=time()+IPDAMS_BACKUP_SESSION_TTL;
            $success='Verified source-only ZIP created without an encryption password. It excludes credentials, database and private records. Download within 20 minutes and store securely.';
        }elseif($action==='backup_download'){
            demand($activeTab==='backups','Open Backup Center to download a backup',403);
            demand($isHttps||cfg('APP_ENV')!=='production','HTTPS required for confidential backup downloads',403);
            ipdamsBackupDownload((int)$user['id'],(string)($_POST['backup_id']??''),(string)($_POST['backup_kind']??''));
        }elseif($action==='branding_upload'){
            demand($activeTab==='branding','Open System Branding to upload images',403);
            brandingSave($db,$user,(string)($_POST['kind']??''),$_FILES['image']??[]);
            $success='Branding image saved. Refresh the IPDAMS workspace to see it.';
        }elseif($action==='branding_restore'){
            demand($activeTab==='branding','Open System Branding to restore an image',403);
            brandingRestore($db,$user,(string)($_POST['kind']??''));
            $success='Original default branding image restored.';
        }elseif($action==='numbering_save'){
            demand($activeTab==='numbering','Open School Numbering Setup to change starting sequences',403);
            $savedNumber=IPDAMSNumberingSetup::saveMany($db,$user,
                $_POST['school_id']??null,$_POST['periods']??null,$_POST['sequences']??null);
            $success='Saved next control-number sequences for '.$savedNumber['count'].' form(s) at '.$savedNumber['school'].'. Existing issued documents were not renumbered.';
            // Successful submission should display empty inputs rather than accidentally resubmitting settings.
            unset($_POST['periods'],$_POST['sequences']);
        }elseif($action==='reset_prepare'){
            demand($isHttps||cfg('APP_ENV')!=='production','HTTPS is required for system reset',403);
            $resetPreview=resetPrepare($db,(int)$user['id'],(string)($_POST['reset_mode']??''),is_array($_POST['reset_groups']??null)?$_POST['reset_groups']:[]);
            $_SESSION['reset_preview_id']=$resetPreview['id'];
            $success='Protected backup ready. Download and verify it before confirming any deletion.';
        }elseif($action==='reset_apply'){
            demand($isHttps||cfg('APP_ENV')!=='production','HTTPS is required for system reset',403);
            $mode=(string)($_POST['reset_mode']??'');
            demand(($_POST['downloaded']??'')==='1','Confirm that you downloaded a safe external backup');
            demand(($_POST['test_only']??'')==='1','Confirm that this installation contains no official records subject to retention requirements');
            $phrase=$mode==='hard'?'FACTORY RESET':($mode==='custom'?'DELETE SELECTED DATA':'DELETE TEST DATA');
            demand(hash_equals($phrase,(string)($_POST['confirm_phrase']??'')),'Enter the exact confirmation phrase: '.$phrase);
            $admin=null;
            if($mode==='hard'){
                $email=strtolower(trim((string)($_POST['new_email']??'')));
                $fullName=trim((string)($_POST['new_name']??''));
                $password=(string)($_POST['new_password']??'');
                demand(filter_var($email,FILTER_VALIDATE_EMAIL)!==false&&strlen($email)<=190,'Enter a valid new Super Administrator email');
                demand($fullName!==''&&strlen($fullName)<=150,'Enter a new Super Administrator name');
                demand(strlen($password)>=12&&strlen($password)<=256,'New administrator password must be 12–256 characters');
                demand(hash_equals($password,(string)($_POST['new_password_confirm']??'')),'New password confirmation does not match');
                $admin=['email'=>$email,'name'=>$fullName,'password'=>$password];
            }
            $resetOutcome=resetApply($db,(int)$user['id'],(string)($_POST['reset_id']??''),$mode,$admin);
            unset($_SESSION['reset_preview_id']);
            if($mode==='hard'){
                $_SESSION=[];session_regenerate_id(true);
                $user=null;
                $success='Factory reset completed. Old accounts were deleted and the new Super Administrator was created. Please sign in again.';
            }else $success=$mode==='custom'?'Selected data groups were deleted. Division, Schools, Users and their access scopes were preserved.':'Operational records deleted; Division, Schools, Users and access scopes preserved. An independent backup remains available.';
        }elseif($action==='upload'){
            $lastUpload=settingsStage($_FILES['package']??[],$user['id']);
            $success='Release uploaded. Check compatibility and review the changes before applying.';
        }elseif($action==='apply'){
            $id=(string)($_POST['release_id']??'');$m=settingsMeta($id);
            demand(($_POST['reviewed']??'')==='1','Please confirm you reviewed the update and have a separate recovery backup');
            $critical=array_filter(array_keys($m['files']), 'settingsCritical');
            if($critical)demand(($_POST['critical_reviewed']??'')==='1','Core updater/entrypoint changes need the additional acknowledgement');
            if(!empty($m['manifest']['migrations']))demand(($_POST['migration_reviewed']??'')==='1','Database migrations need additional acknowledgement');
            $check=settingsPreflight($id);demand($check['ok'],implode(' | ',$check['issues']));
            $applied=settingsApply($id,(int)$user['id']);
            $success='Installed v'.$applied['manifest']['version'].(!empty($applied['migration_results'])?' with '.count($applied['migration_results']).' verified database migration(s)':'').'. Refresh and verify the website before further changes.';
            // Preserve the one-time success message, then switch the address-bar
            // history entry to GET. This is deliberately AFTER settingsApply();
            // failed preflight/apply still displays the genuine failure.
            $_SESSION['ipdams_update_apply_notice']=['uid'=>(int)$user['id'],'text'=>$success];
            session_write_close();
            header('Location: settings.php?tab=updates',true,303);
            exit;
        }elseif($action==='discard_staged'){
            settingsDiscardStaged((string)($_POST['release_id']??''),(int)$user['id']);
            $success='Unused staged package discarded. Installed files and backups were not touched.';
        }elseif($action==='rollback'){
            demand(($_POST['confirm']??'')==='ROLLBACK','Type ROLLBACK to confirm rollback');
            settingsRollback((string)($_POST['release_id']??''),(int)$user['id']);
            $success='Files restored. Database changes (if any) are not reversed.';
        }elseif($action==='schema_health_check'){
            demand($activeTab==='health','Open Database Health to run this check',403);
            // settingsAdmin($db) is already enforced before any POST branch;
            // csrfCheck happens before this action. Deliberately never log or
            // persist a report containing physical schema identifiers.
            $schemaHealth=M82SchemaContract::diagnose(M82SchemaContract::inventory($db));
            $success='Read-only database structure check completed. No financial records were accessed or changed.';
        }elseif($action==='date_quality_review'){
            demand($activeTab==='health','Open Database Health to review date quality',403);
            demand(($_POST['date_read_ack']??'')==='1',
                'Confirm that this read-only review will examine existing stored bank and PR date values',422);
            demand(!$db->inTransaction(),'Finish the previous database operation before checking date quality',409);
            // Enforce READ ONLY at database transaction level, in addition
            // to fixed SELECT-only SQL in the audited DateQuality class.
            $db->exec('SET TRANSACTION READ ONLY');
            $db->beginTransaction();
            try{
                $dateQuality=IPDAMSDateQuality::scan($db,
                    (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'));
            }finally{
                if($db->inTransaction())$db->rollBack();
            }
            $success='Date data-quality review completed. Only summary counts are shown; no values were changed.';
        }elseif($action==='query_read'||$action==='query_write'){
            if($action==='query_write')demand(($_POST['confirm']??'')==='EXECUTE WRITE','Type EXECUTE WRITE to confirm');
            $results=settingsQuery($db,(string)($_POST['sql']??''),$action==='query_write',(int)$user['id']);
            $success=$action==='query_write'?'Write committed; check associated business records.':'Query completed.';
        }else throw new DomainError('Unknown Settings action');
    }
}catch(DomainError $ex){$error=$ex->getMessage();http_response_code($ex->status);}
catch(PDOException $ex){$error='Database operation failed. Check server logs.';error_log('IPDAMS Settings DB: '.$ex->getCode());}
catch(Throwable $ex){$error='Settings operation failed; check server logs.';error_log('IPDAMS Settings: '.$ex->getMessage());}
if($user && $activeTab==='backups'){
    try {
        ipdamsBackupZipReady(false);
        $backupRoot=ipdamsBackupDir();
        demand(is_writable($backupRoot),'Backup destination is not writable',503);
        $fullEncryptedReady=true;
        try{ipdamsBackupZipReady(true);}catch(Throwable $x){$fullEncryptedReady=false;}
        $backupPreflight=['ok'=>true,'full_ok'=>true,'full_encrypted_ok'=>$fullEncryptedReady,
            'message'=>$fullEncryptedReady
                ?'Password-free source and full recovery ZIPs are available; AES-256 encryption is optional.'
                :'Password-free source and full recovery ZIPs are available. Optional AES-256 encryption is unavailable on this host.'];
    }catch(Throwable $e){
        $backupPreflight=['ok'=>false,'message'=>$e instanceof DomainError?$e->getMessage():'Backup preflight failed; contact the technical administrator.'];
    }
}
if($user && $activeTab==='backups' && !$backupReady && isset($_SESSION['backup_access_id'])
    && (int)($_SESSION['backup_access_uid']??0)===(int)$user['id']){
    try {
        $bid=(string)$_SESSION['backup_access_id'];
        if((int)($_SESSION['backup_access_expires']??0)>=time() && preg_match('/^[a-f0-9]{24}$/D',$bid)){
            $p=ipdamsBackupDir().'/'.$bid.'.json';
            if(is_file($p)){
                $m=json_decode((string)file_get_contents($p),true);
                if(is_array($m) && ($m['status']??'')==='verified' && ($m['id']??'')===$bid)$backupReady=$m;
            }
        }
    }catch(Throwable $ignored){}
}
if($user && $activeTab==='reset' && !$resetPreview && isset($_SESSION['reset_preview_id'])){
    try {$candidate=resetMetadata((string)$_SESSION['reset_preview_id']);if($candidate['actor']===(int)$user['id']&&$candidate['status']==='prepared')$resetPreview=$candidate;}
    catch(Throwable $ex){unset($_SESSION['reset_preview_id']);}
}
try{$state=settingsState();$releases=$user?settingsReleaseHistory():[];}catch(Throwable $ex){$state=['version'=>'unknown','installed_at'=>null,'release_id'=>null];$releases=[];$error=$error?:'Cannot read update state. Check private storage permissions.';}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>IPDAMS · System Settings</title><link rel="stylesheet" href="settings.css?v=02207-scroll"><link rel="stylesheet" href="assets/branding-ui.css?v=02132"><link rel="stylesheet" href="assets/branding-pages.css?v=0426-brand-home"><link rel="stylesheet" href="assets/dense-ui.css?v=0225"><script src="assets/settings-tools.js?v=02205-shell" defer></script><link rel="stylesheet" href="assets/maintenance-406.css?v=0406"><link rel="stylesheet" href="assets/maintenance-408.css?v=0408"><script src="assets/maintenance-406-settings.js?v=0407" defer></script><script src="assets/maintenance-406.js?v=0407" defer></script></head><body>
<div class="settings-shell"><aside class="settings-sidebar" aria-label="IPDAMS application sidebar">
<a class="settings-brand ipdams-home-link" href="index.php" aria-label="IPDAMS — Go to Dashboard" title="Go to Dashboard"><span class="settings-logo"><img src="api/branding.php?kind=icon" alt="" class="ipdams-settings-icon"></span><div><strong>IPDAMS</strong><small>OPERATIONS PLATFORM</small></div></a>
<div class="settings-side-label">WORKSPACE</div>
<details class="settings-nav-drawer" open><summary class="settings-nav-drawer-toggle">Browse workspace sections</summary>
<nav class="settings-main-nav" aria-label="Workspace sections">
<?php // Match the section names and group order in public/assets/app.js (v0.2.204).
// These links navigate to the existing React workspace; only Settings is served by this PHP shell.
$settingsNavigation = [
    ['Dashboard','▦','OVERVIEW'],
    ['Procurement','▤','DOCUMENT WORKFLOWS'],
    ['Disbursements','▣','DOCUMENT WORKFLOWS'],
    ['Travel & Training','↗','DOCUMENT WORKFLOWS'],
    ['Fund Management','◈','FINANCE'],
    ['Cash & Bank','▥','FINANCE'],
    ['Inventory Management','◫','STOCK & PROPERTY'],
    ['Property Accountability','▣','STOCK & PROPERTY'],
    ['Textbooks','▧','STOCK & PROPERTY'],
    ['Reference Data','▤','MANAGEMENT'],
    ['Reports','▨','MANAGEMENT'],
    ['Organization','⌂','MANAGEMENT'],
    ['Administration','⚙','SYSTEM'],
    ['Settings','⚙','SYSTEM'],
];
$settingsPreviousGroup = null;
foreach($settingsNavigation as [$section,$symbol,$group]):
    if($settingsPreviousGroup!==$group):?>
    <div class="settings-navgroup-label"><?=e($group)?></div>
    <?php $settingsPreviousGroup=$group; endif;
    $settingsActive = $section === 'Settings';
    $settingsHref = $settingsActive ? 'settings.php?tab='.rawurlencode($activeTab) : 'index.php?section='.rawurlencode($section);
    ?>
    <a class="settings-navitem<?=$settingsActive?' active':''?>" href="<?=e($settingsHref)?>" <?=$settingsActive?'aria-current="page"':''?>><span class="settings-nav-icon" aria-hidden="true"><?=e($symbol)?></span><span class="settings-nav-text"><?=e($section)?></span><?=$settingsActive?'<b class="settings-nav-current" aria-hidden="true">›</b>':''?></a>
<?php endforeach; unset($settingsNavigation,$settingsPreviousGroup,$settingsActive,$settingsHref); ?>
</nav></details>
<div class="settings-side-foot"><span class="settings-avatar"><?=e(strtoupper(substr((string)($user['full_name']??'I'),0,1)))?></span><div class="settings-side-account"><strong><?=e((string)($user['full_name']??'Administrator'))?></strong><small>IPDAMS v<?=e((string)$state['version'])?></small></div></div>
</aside>
<div class="settings-content"><main><div class="ipdams-settings-banner"><a class="ipdams-settings-home" href="index.php" aria-label="IPDAMS header — Go to Dashboard" title="Go to Dashboard"><img src="api/branding.php?kind=header" alt="IPDAMS — by Loy"></a><div><div class="settings-eyebrow">IPDAMS / SETTINGS</div><h1>System Settings</h1><p class="lead">Manage authorized system configuration and updates without leaving the IPDAMS layout.</p></div></div>
<?php if(!$user):?><section class="panel"><h2><?=!empty($success)?'Reset completed':'Access restricted'?></h2><?php if($success):?><p class="alert good"><?=e($success)?></p><p><a href="index.php">Return to login</a></p><?php else:?><p>Sign in using an active Super Administrator account, then reopen Settings.</p><?php endif;?></section><?php else:?>
<nav class="settings-tabs" aria-label="Settings sections"><a class="settings-tab <?=$activeTab==='updates'?'selected':''?>" href="settings.php?tab=updates" <?=$activeTab==='updates'?'aria-current="page"':''?>>System Updates</a><a class="settings-tab <?=$activeTab==='health'?'selected':''?>" href="settings.php?tab=health" <?=$activeTab==='health'?'aria-current="page"':''?>>Database Health</a><a class="settings-tab <?=$activeTab==='sql'?'selected':''?>" href="settings.php?tab=sql" <?=$activeTab==='sql'?'aria-current="page"':''?>>SQL Query</a><a class="settings-tab <?=$activeTab==='reset'?'selected':''?>" href="settings.php?tab=reset" <?=$activeTab==='reset'?'aria-current="page"':''?>>Data Reset</a><a class="settings-tab <?=$activeTab==='numbering'?'selected':''?>" href="settings.php?tab=numbering" <?=$activeTab==='numbering'?'aria-current="page"':''?>>School Numbering</a><a class="settings-tab <?=$activeTab==='backups'?'selected':''?>" href="settings.php?tab=backups" <?=$activeTab==='backups'?'aria-current="page"':''?>>Backups</a><?php if($canBrand):?><a class="settings-tab <?=$activeTab==='branding'?'selected':''?>" href="settings.php?tab=branding" <?=$activeTab==='branding'?'aria-current="page"':''?>>System Branding</a><a class="settings-tab <?=$activeTab==='maintenance'?'selected':''?>" href="settings.php?tab=maintenance" <?=$activeTab==='maintenance'?'aria-current="page"':''?>>Maintenance Control</a><?php endif;?></nav>
<?php if($error):?><p class="alert bad" role="alert"><?=e($error)?></p><?php endif;?><?php if($success):?><p class="alert good" role="status"><?=e($success)?></p><?php endif;?>
<?php if($activeTab==='maintenance' && $canBrand):
$maintenance=maintenanceRead();$liveMaintenance=maintenancePublicState(); ?>
<section class="panel ipdams-maintenance-control ipdams-maintenance-408" aria-labelledby="mc-title">
<header class="mc-header">
  <div class="mc-title-area">
    <div class="mc-eyebrow">OWNER ACCESS · SYSTEM OPERATIONS</div>
    <h2 id="mc-title">Maintenance Control</h2>
    <p>Schedule downtime, notify users in advance, and control the system lock. Unfinished transactions are never automatically finalized.</p>
  </div>
  <div class="mc-current" role="status">
    <span class="mc-current-label">CURRENT STATUS</span>
    <strong class="mc-status mc-status--<?=e(strtolower((string)$liveMaintenance['status']))?>"><?=e((string)$liveMaintenance['status'])?></strong>
    <small>Revision <?=e((string)$maintenance['revision'])?></small>
  </div>
</header>
<?php if(!empty($liveMaintenance['starts_at'])): ?>
<div class="mc-schedule-note"><span class="mc-note-dot" aria-hidden="true"></span><strong>Scheduled start</strong><span><?=e((string)$maintenance['starts_at'])?> · Philippine time</span></div>
<?php endif; ?>
<form method="post" action="settings.php?tab=maintenance" class="mc-form" autocomplete="off" id="maintenance-schedule-form">
<input type="hidden" name="_csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="settings_tab" value="maintenance">
<input type="hidden" name="action" value="maintenance_schedule"><input type="hidden" name="revision" value="<?=(int)$maintenance['revision']?>">
<section class="mc-section" aria-labelledby="mc-schedule-heading">
  <div class="mc-section-heading"><span class="mc-step" aria-hidden="true">01</span><div><h3 id="mc-schedule-heading">Schedule</h3><p>Set when maintenance begins and how often it repeats.</p></div></div>
  <div class="mc-grid mc-grid--3">
    <label>Start date &amp; time <small>Asia/Manila</small><input type="datetime-local" name="starts_at" value="<?=e(substr((string)$maintenance['starts_at'],0,16))?>" required></label>
    <label>Repeat schedule<select name="repeat" id="maintenance-repeat">
      <option value="NONE" <?=($maintenance['repeat']==='NONE'?'selected':'')?>>One time</option>
      <option value="DAILY" <?=($maintenance['repeat']==='DAILY'?'selected':'')?>>Daily</option>
      <option value="WEEKLY" <?=($maintenance['repeat']==='WEEKLY'?'selected':'')?>>Weekly</option>
      <option value="MONTHLY" <?=($maintenance['repeat']==='MONTHLY'?'selected':'')?>>Monthly</option>
      <option value="QUARTERLY" <?=($maintenance['repeat']==='QUARTERLY'?'selected':'')?>>Quarterly</option>
      <option value="SEMIANNUAL" <?=($maintenance['repeat']==='SEMIANNUAL'?'selected':'')?>>Semiannually</option>
      <option value="ANNUAL" <?=($maintenance['repeat']==='ANNUAL'?'selected':'')?>>Annually</option>
    </select></label>
    <label>Downtime duration <small>Minutes; 0 = manual unlock (one time only)</small><input type="number" name="duration_minutes" id="maintenance-duration" value="<?=(int)$maintenance['duration_minutes']?>" min="0" max="1440" required></label>
  </div>
</section>
<section class="mc-section" aria-labelledby="mc-notice-heading">
  <div class="mc-section-heading"><span class="mc-step" aria-hidden="true">02</span><div><h3 id="mc-notice-heading">Countdown &amp; activation</h3><p>The notice and the actual system lock are separate controls.</p></div></div>
  <div class="mc-grid mc-grid--2 mc-lead-fields">
    <label>Notice lead time <small>Custom 1–365 days</small><input type="number" name="notice_lead_days" id="maintenance-notice-days" value="<?=e((string)($maintenance['notice_lead_days']??max(1,(int)ceil(((int)$maintenance['lead_minutes'])/1440))))?>" min="1" max="365" step="1" required></label>
    <label>Quick selection <small>Optional shortcut</small><select id="maintenance-quick-days"><option value="">Use custom days</option><option value="1">1 day</option><option value="3">3 days</option><option value="5">5 days</option><option value="7">7 days</option><option value="14">14 days</option><option value="30">30 days</option><option value="31">31 days</option></select></label>
  </div>
  <div class="mc-switch-grid">
    <label class="mc-switch"><input type="checkbox" name="notice_auto_publish" value="1" <?=($maintenance['notice_auto_publish']?'checked':'')?>><span><strong>Auto-show announcement</strong><small>Display at the configured lead time.</small></span></label>
    <label class="mc-switch"><input type="checkbox" name="notice_manual_enabled" value="1" <?=($maintenance['notice_manual_enabled']?'checked':'')?>><span><strong>Show announcement now</strong><small>Display immediately, even before lead time.</small></span></label>
    <label class="mc-switch mc-switch--lock"><input type="checkbox" name="auto_activate" value="1" <?=($maintenance['auto_activate']?'checked':'')?>><span><strong>Automatically lock at start</strong><small>Block non-owner access when countdown ends.</small></span></label>
  </div>
</section>
<section class="mc-section" aria-labelledby="mc-message-heading">
  <div class="mc-section-heading"><span class="mc-step" aria-hidden="true">03</span><div><h3 id="mc-message-heading">Messages</h3><p>Choose a saved announcement or write your own notice.</p></div></div>
  <label class="mc-preset-picker">Message preset<select id="maintenance-preset-select"><option value="">Custom message</option><?php foreach($maintenance['presets'] as $i=>$preset):?><option value="<?=$i?>"><?=e((string)$preset['name'])?></option><?php endforeach;?></select></label>
  <div class="mc-message-grid">
    <div class="mc-message-card"><h4>Countdown notice</h4>
      <label>Notice heading<input name="header" maxlength="100" value="<?=e((string)$maintenance['header'])?>" required></label>
      <label>Message <small>Use [countdown] to show time remaining.</small><textarea name="message" maxlength="600" rows="2" required><?=e((string)$maintenance['message'])?></textarea></label>
    </div>
    <div class="mc-message-card"><h4>Maintenance lock screen</h4>
      <label>Page heading<input name="active_header" maxlength="100" value="<?=e((string)$maintenance['active_header'])?>" required></label>
      <label>Message<textarea name="active_message" maxlength="600" rows="2" required><?=e((string)$maintenance['active_message'])?></textarea></label>
    </div>
  </div>
</section>
<div class="mc-form-footer">
  <label>Owner password <small>Required to save changes</small><input type="password" name="password" autocomplete="current-password" required></label>
  <button type="submit" class="mc-primary-button">Save maintenance schedule</button>
</div>
</form>
<details class="mc-extra" id="mc-presets">
  <summary><span class="mc-summary-icon" aria-hidden="true">▤</span><span><strong>Saved message presets</strong><small><?=count($maintenance['presets'])?> saved · Reuse without changing schedule dates</small></span><span class="mc-chevron" aria-hidden="true">⌄</span></summary>
  <div class="mc-extra-body">
    <form method="post" action="settings.php?tab=maintenance" class="mc-preset-form">
      <input type="hidden" name="settings_tab" value="maintenance"><input type="hidden" name="_csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="maintenance_preset_save"><input type="hidden" name="revision" value="<?=(int)$maintenance['revision']?>">
      <label>Preset name<input name="preset_name" maxlength="48" placeholder="e.g., Christmas Maintenance" required></label>
      <label>Heading<input name="preset_header" maxlength="100" required></label>
      <label class="mc-full">Announcement text<textarea name="preset_message" maxlength="600" rows="2" required></textarea></label>
      <label>Owner password<input type="password" name="password" autocomplete="current-password" required></label>
      <button type="submit" class="mc-primary-button">Save preset</button>
    </form>
    <?php if($maintenance['presets']):?><div class="mc-preset-list"><?php foreach($maintenance['presets'] as $preset):?>
      <div class="mc-preset-row"><div><strong><?=e((string)$preset['name'])?></strong><small><?=e((string)$preset['header'])?></small></div>
      <form method="post" action="settings.php?tab=maintenance"><input type="hidden" name="settings_tab" value="maintenance"><input type="hidden" name="_csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="maintenance_preset_delete"><input type="hidden" name="revision" value="<?=(int)$maintenance['revision']?>"><input type="hidden" name="preset_name" value="<?=e((string)$preset['name'])?>"><label class="mc-inline-password"><span class="mc-sr-only">Owner password for deleting <?=e((string)$preset['name'])?></span><input type="password" name="password" placeholder="Owner password" aria-label="Owner password for deleting preset" autocomplete="current-password" required></label><button type="submit" class="mc-secondary-button" data-maintenance-confirm="Delete this announcement preset?">Delete</button></form></div>
    <?php endforeach;?></div><?php endif;?>
  </div>
</details>
<details class="mc-extra mc-extra--danger" id="mc-emergency" <?=!empty($liveMaintenance['active'])?'open':''?>>
  <summary><span class="mc-summary-icon" aria-hidden="true">⚑</span><span><strong>Emergency maintenance controls</strong><small>Lock now or unlock and cancel scheduled maintenance</small></span><span class="mc-chevron" aria-hidden="true">⌄</span></summary>
  <div class="mc-extra-body">
    <p class="mc-help">These actions take effect immediately. Locking blocks non-owner API access; unlocking also cancels future scheduled activation.</p>
    <div class="mc-emergency-grid">
      <form method="post" action="settings.php?tab=maintenance"><input type="hidden" name="settings_tab" value="maintenance"><input type="hidden" name="_csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="revision" value="<?=(int)$maintenance['revision']?>"><input type="hidden" name="action" value="maintenance_enable_now"><label>Owner password<input type="password" name="password" autocomplete="current-password" required></label><button type="submit" class="mc-danger-button" data-maintenance-confirm="Lock all non-owner IPDAMS sessions immediately?">Lock system now</button></form>
      <form method="post" action="settings.php?tab=maintenance"><input type="hidden" name="settings_tab" value="maintenance"><input type="hidden" name="_csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="revision" value="<?=(int)$maintenance['revision']?>"><input type="hidden" name="action" value="maintenance_disable"><label>Owner password<input type="password" name="password" autocomplete="current-password" required></label><button type="submit" class="mc-secondary-button" data-maintenance-confirm="Unlock the system and cancel its future schedule?">Disable / unlock maintenance</button></form>
    </div>
  </div>
</details>
<script type="application/json" id="maintenance-preset-data"><?=json_encode($maintenance['presets'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
</section>
<?php elseif($activeTab==='branding' && $canBrand):?>
<section class="panel ipdams-branding-panel">
<h2>System Branding — Owner Only</h2>
<p class="muted">Set the global IPDAMS application icon and wide header. These images appear in the application interface only; official DepEd document letterheads and procurement forms remain unchanged. Only the original owner account can replace them.</p>
<div class="ipdams-branding-grid">
<?php foreach(['icon'=>'Application icon','header'=>'Header logo'] as $kind=>$label):?>
<div class="ipdams-branding-slot">
<h3><?=e($label)?></h3>
<img src="api/branding.php?kind=<?=e($kind)?>&amp;ts=<?=time()?>" alt="<?=e($label)?> preview" class="ipdams-branding-preview ipdams-branding-preview--<?=e($kind)?>">
<p class="muted"><?= $kind==='icon'?'Square image. Displays in the IPDAMS sidebar and Settings.':'Wide image. Displays in the IPDAMS application header.' ?> PNG, JPEG or WebP, maximum 3 MB.</p>
<form method="post" enctype="multipart/form-data" action="settings.php?tab=branding">
<input type="hidden" name="settings_tab" value="branding"><input type="hidden" name="_csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="branding_upload"><input type="hidden" name="kind" value="<?=e($kind)?>">
<label for="branding-<?=e($kind)?>">Upload a new <?=e(strtolower($label))?><input id="branding-<?=e($kind)?>" type="file" name="image" accept="image/png,image/jpeg,image/webp,.png,.jpg,.jpeg,.webp" required></label>
<button type="submit">Save <?=e($label)?></button>
</form>
<form method="post" action="settings.php?tab=branding">
<input type="hidden" name="settings_tab" value="branding"><input type="hidden" name="_csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="branding_restore"><input type="hidden" name="kind" value="<?=e($kind)?>">
<button type="submit" class="secondary-action">Restore default</button>
</form>
</div>
<?php endforeach;?>
</div>
</section>
<?php elseif($activeTab==='health'):?>
<section class="panel schema-health">
<h2>Database Health Check</h2>
<p>Check whether the database structure supports the accounting and document controls IPDAMS expects. This is a <strong>read-only structure check</strong>; it will not view transaction amounts, suppliers, bank details, users or other business records.</p>
<p class="muted">Only Super Administrators can run this diagnostic. Results appear for this request only and are not saved. The check does not repair anything or authorize accounting posting.</p>
<form method="post" action="settings.php?tab=health">
<input type="hidden" name="_csrf" value="<?=e($_SESSION['csrf'])?>">
<input type="hidden" name="settings_tab" value="health">
<button type="submit" name="action" value="schema_health_check">Check Database Compatibility</button>
</form>
<?php if($schemaHealth!==null):?>
<?php $schemaPassed=$schemaHealth['assessment']==='MATCHES_BOUNDED_CONTRACT';$schemaIssues=$schemaHealth['issues']; ?>
<div class="preflight" role="status" aria-live="polite">
<strong>Structure review: <?= $schemaPassed?'Selected checks matched':'Technical review required' ?></strong>
<p class="<?= $schemaPassed?'ok-text':'bad-text' ?>"><?=$schemaPassed
?'The selected critical tables, columns and uniqueness rules match this limited contract.'
:'One or more required structures are missing or incompatible. No correction has been made.' ?></p>
<p class="muted">Contract: <?=e((string)$schemaHealth['contract_version'])?> · Evaluated elements: <?=number_format((int)$schemaHealth['checked_elements'])?> · Issues: <?=count($schemaIssues)?></p>
<?php if($schemaIssues):?>
<details open><summary>Structures requiring technical review (<?=count($schemaIssues)?>)</summary>
<div class="schema-health-issues">
<?php foreach($schemaIssues as $issue):?>
<div class="preflight">
<p><strong><code><?=e((string)$issue['path'])?></code></strong></p>
<p class="muted"><?=e(ucwords(strtolower(str_replace('_',' ',(string)$issue['code']))))?></p>
<?php if(isset($issue['expected_type'],$issue['detected_type'])):?>
<p><strong>Expected type:</strong> <code><?=e(strtoupper((string)$issue['expected_type']))?></code></p>
<p><strong>Detected type:</strong> <code><?=e(strtoupper((string)$issue['detected_type']))?></code></p>
<?php endif;?>
<?php if($issue['path']==='fund_events.bank_credit_date'):?>
<p class="muted">Bank credit dates determine when bank-linked allocations become available. The stored date type must be verified before changing fund controls.</p>
<?php elseif($issue['path']==='purchase_requests.document_date'):?>
<p class="muted">PR document dates are entered as calendar dates for procurement records. The stored type requires technical review.</p>
<?php endif;?>
</div>
<?php endforeach;?>
</div></details>
<?php endif;?>
<p class="muted"><strong>Scope limitation:</strong> This checks selected structures only—not every migration, existing data quality, government accounting policy, bank settlement or DV finalization readiness. If findings appear, request technical review; do not modify live tables through the SQL console to make the warning disappear.</p>
</div>
<?php endif;?>
</section>
<section class="panel date-quality-review">
<h2>Review Date Data Quality</h2>
<p><strong>Separate from Database Health's structure-only check:</strong> this optional review <strong>reads the existing stored date values</strong> in bank allocation events and purchase requests, along with whether a bank is linked or a PR is finalized. It shows only category counts, not any individual dates, record numbers, names or amounts.</p>
<p class="muted">Read-only. Maximum <?=number_format(IPDAMSDateQuality::MAX_ROWS)?> rows examined from each source; larger data sets produce an incomplete result. This review does not convert dates, release funds, correct any documents or authorize a DV.</p>
<form method="post" action="settings.php?tab=health">
<input type="hidden" name="_csrf" value="<?=e($_SESSION['csrf'])?>">
<input type="hidden" name="settings_tab" value="health">
<label class="check-label"><input type="checkbox" name="date_read_ack" value="1" required> I understand that this check will read existing bank-credit and PR document-date values, and display aggregate counts only.</label>
<button type="submit" name="action" value="date_quality_review">Review Date Data Quality</button>
</form>
<?php if($dateQuality!==null):?>
<div class="preflight" role="status" aria-live="polite">
<strong>Data-quality review: <?=e(match($dateQuality['assessment']){
    'INCOMPLETE_SCAN'=>'Incomplete — review required',
    'VALUE_REVIEW_REQUIRED'=>'Anomalies require technical review',
    default=>'No anomalies in the bounded scan',
})?></strong>
<p class="muted">Assessment <?=e((string)$dateQuality['contract'])?>. Only summary counts are shown. Dates and identifying records are not displayed or saved.</p>
<?php foreach(['bank_credit_dates'=>'Bank credit dates','pr_document_dates'=>'PR document dates'] as $category=>$heading):?>
<?php $counts=$dateQuality[$category];?>
<div class="preflight">
<h3><?=e($heading)?></h3>
<p><strong>Rows examined:</strong> <?=number_format((int)$counts['scanned'])?>
· <strong><?= $counts['complete']?'Within scan limit':'INCOMPLETE: scan limit reached' ?></strong></p>
<dl class="date-quality-counts">
<?php foreach([
    'valid'=>'Valid date values',
    'invalid_format'=>'Invalid format',
    'invalid_calendar'=>'Impossible/out-of-range dates',
    'future_bank_credit'=>'Future bank-credit dates',
    'missing_linked_or_finalized'=>'Missing dates on linked bank events / finalized PRs',
    'missing_unlinked_or_draft'=>'Missing dates on unlinked bank events / draft PRs',
] as $key=>$description):?>
<?php if($key==='future_bank_credit' && $category!=='bank_credit_dates')continue;?>
<div><dt><?=e($description)?></dt><dd><?=number_format((int)$counts[$key])?></dd></div>
<?php endforeach;?>
</dl>
</div>
<?php endforeach;?>
<p class="muted"><strong>Limitations:</strong> Counts reflect only examined rows; no anomalies does not prove that VARCHAR enforces valid dates. Even a complete review does not prove financial readiness. Any anomaly requires technical review before considering a separately authorized, backed-up schema correction.</p>
</div>
<?php endif;?>
</section>
<?php endif;?>
<?php if($activeTab==='updates'):?>
<?php
$activeReleases=array_values(array_filter($releases,fn($r)=>!in_array(($r['status']??''),['staged','discarded'],true)));
$stagedReleases=array_values(array_filter($releases,fn($r)=>in_array(($r['status']??''),['staged','discarded'],true)));
$latestStaged=null;
foreach($stagedReleases as $sr){if(($sr['status']??'')==='staged'){$latestStaged=$sr;break;}}
if($latestStaged!==null){$stagedReleases=array_values(array_filter($stagedReleases,fn($r)=>(string)$r['id']!==(string)$latestStaged['id']));}
?>
<section class="panel version-panel"><div class="version-top"><div><p class="smallcaps">Currently installed version</p><div class="version-number">v<?=e((string)$state['version'])?></div><p class="muted">Source: <?=e((string)($state['source']??'update history'))?></p></div><span class="version-chip">PHP <?=e(PHP_VERSION)?></span></div>
<div class="version-meta"><span>Last versioned update: <strong><?=e((string)($state['installed_at']??'Baseline installation'))?></strong></span><span>Release ID: <strong><?=e((string)($state['release_id']??'Not yet available'))?></strong></span></div></section>
<section class="panel"><h2>Upload versioned update</h2><p>Upload a reviewed IPDAMS release ZIP containing a <code>release.json</code> manifest plus approved <code>app/</code> and <code>public/</code> paths. Review the changes below before applying. Packages with reviewed, checksum-verified additive database migrations can install their SQL automatically after creating a protected database backup. Standard packages never execute SQL.</p>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="upload"><input type="hidden" name="settings_tab" value="updates"><input type="hidden" name="_csrf" value="<?=e($_SESSION['csrf'])?>"><label>Release ZIP <input type="file" name="package" accept=".zip,application/zip" required></label><button type="submit">Upload and validate</button></form>
<p class="muted">20 MB ZIP maximum · 350 entries · 60 MB expanded · Future versions require the current installed version as their declared base.</p></section>
<?php if($latestStaged!==null):?>
<section class="panel latest-staged-panel" id="latest-staged-package" aria-label="Latest uploaded package">
<h2>Latest uploaded package — ready to review</h2>
<p class="muted">This is the most recently staged ZIP. Inspect its file list and automatic checks here, then apply it below without searching through Update History.</p>
<?php settingsRenderReleaseRows([$latestStaged],$state,true); ?>
</section>
<?php endif;?>
<section class="panel"><h2>System readiness</h2><div class="readiness-grid"><div><b>Database connection</b><span class="ok-text">Connected</span></div><div><b>PHP ZIP support</b><span class="<?=extension_loaded('zip')?'ok-text':'bad-text'?>"><?=extension_loaded('zip')?'Available':'Missing'?></span></div><div><b>Updater storage</b><span class="<?=is_writable(settingsPrivate())?'ok-text':'bad-text'?>"><?=is_writable(settingsPrivate())?'Writable':'Not writable'?></span></div><div><b>SQL writes</b><span>Disabled — financial integrity</span></div></div></section>
<section class="panel"><h2>Update history</h2><p>The latest 40 packages are shown, including past updates for reference. <strong>Only the latest active installed update can be rolled back</strong>, and only when its file backups and installed checksums pass verification. Older update rollback controls stay locked until newer releases are restored first. File rollback does not restore SQL data.</p>
<?php if(!$releases):?><p class="muted">No staged versioned releases yet. Earlier legacy releases may appear without version metadata.</p><?php endif;?>

<?php foreach([['title'=>'Installed and rollback history','rows'=>$activeReleases,'open'=>true],['title'=>'Older / unused staged packages ('.count($stagedReleases).')','rows'=>$stagedReleases,'open'=>false]] as $group):?>
<details class="history-group" <?= $group['open']?'open':''?>><summary><?=e($group['title'])?></summary>
<?php settingsRenderReleaseRows($group['rows'],$state); ?></details><?php endforeach;?></section>
<section class="panel"><h2>Release safety notes</h2><p><b>Packages are not digitally signed.</b> SHA256 hashes help detect changes between upload and install, but do not prove who created an update. Only upload packages reviewed and obtained from a trusted source. Verify the school/Division staging website before deployment to production.</p><p>Updates modify approved application files only. Only reviewed additive SQL migrations explicitly bundled in approved update packages can run after a protected pre-migration backup; arbitrary SQL, `.env` changes and private documents remain outside normal application updates.</p></section>
<?php endif;?>
<?php if($activeTab==='backups'):?>
<section class="panel"><h2>Backup &amp; Recovery</h2>
<h3>Password-free development source ZIP</h3>
<p>Export only the current PHP, JavaScript, CSS, HTML and migration source. <strong>No ZIP encryption password is needed.</strong> The source-only archive excludes <code>.env</code>, database records, user uploads, private storage and backup files. Only the Super Administrator can prepare and download it; access expires after 20 minutes.</p>
<form method="post" action="settings.php?tab=backups" autocomplete="off">
<input type="hidden" name="settings_tab" value="backups"><input type="hidden" name="_csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="backup_create_source">
<label>Confirm Super Administrator password <input type="password" name="password" autocomplete="current-password" required></label>
<button type="submit">Create Password-Free Source Backup</button>
</form>
<hr>
<h3>Full website and database recovery ZIPs — no ZIP password required</h3>
<p>Creates <strong>two complete, verified ZIPs</strong>: Website and Database. This option removes the <em>ZIP encryption password</em> requirement; your regular Super Administrator login password is still required to confirm that you initiated this confidential export.</p>
<p class="warning"><strong>Contains confidential information.</strong> The password-free full ZIPs include database records, credentials, employee and supplier information, and financial transactions. Anyone who obtains them can read them. Download both over HTTPS, keep them off public/shared folders, protect them with device/drive encryption, and remove unnecessary local copies. Full backups do not include domain/DNS, SSL, email or unrelated Hostinger sites.</p>
<form method="post" action="settings.php?tab=backups" autocomplete="off">
<input type="hidden" name="settings_tab" value="backups"><input type="hidden" name="_csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="backup_create"><input type="hidden" name="backup_mode" value="plain">
<label>Confirm current Super Administrator login password (not a ZIP password) <input type="password" name="password" autocomplete="current-password" required></label>
<label class="check-label"><input type="checkbox" name="acknowledge_unencrypted_full" value="1" required> I understand both ZIPs will be unencrypted and contain confidential database and account records. I will store them securely outside the website.</label>
<?php if($backupPreflight):?><p class="<?= $backupPreflight['ok']?'ok-text':'bad-text' ?>"><?=e($backupPreflight['message'])?></p><?php endif;?>
<p class="muted">Archives are generated only after Super Administrator reauthentication, stored outside the web root with restricted file permissions, verified by SHA-256, and removed from temporary server storage after about one hour. The authenticated download window is 20 minutes.</p>
<button type="submit" <?=($backupPreflight!==null && !$backupPreflight['ok'])?'disabled':''?>>Create Full Backup — No ZIP Password</button>
</form>
<details class="history-group"><summary>Optional: AES-256-encrypted full recovery ZIPs</summary>
<p>For encrypted portable backups, choose your own 16–200 character ZIP password. This is optional and separate from your IPDAMS login password.</p>
<form method="post" action="settings.php?tab=backups" autocomplete="off">
<input type="hidden" name="settings_tab" value="backups"><input type="hidden" name="_csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="backup_create"><input type="hidden" name="backup_mode" value="encrypted">
<label>Confirm current Super Administrator login password <input type="password" name="password" autocomplete="current-password" required></label>
<label>Optional ZIP encryption password (16–200 characters) <input type="password" name="backup_passphrase" minlength="16" maxlength="200" autocomplete="new-password" required></label>
<label>Confirm ZIP encryption password <input type="password" name="backup_passphrase_confirm" minlength="16" maxlength="200" autocomplete="new-password" required></label>
<button type="submit" <?=($backupPreflight!==null && (!$backupPreflight['ok']||empty($backupPreflight['full_encrypted_ok'])))?'disabled':''?>>Create Encrypted Full Backup</button>
</form></details>
</section>
<?php if($backupReady):?>
<section class="panel"><h2>Verified backup ready</h2>
<?php $plainFull=($backupReady['backup_mode']??'')==='PASSWORD_FREE_FULL';?>
<p class="ok-text"><strong><?=isset($backupReady['source'])?'Source-only ZIP created and checked. No ZIP password is required.':($plainFull?'Both password-free full recovery ZIPs were created and verified.':'Both AES-256 recovery ZIPs were created and verified.')?></strong> Download the available archive(s) within 20 minutes while this Super Administrator session is active.</p>
<p class="muted">Created: <?=e((string)($backupReady['created_at']??''))?> · IPDAMS v<?=e((string)($backupReady['version']??''))?><?php if(isset($backupReady['database'])):?> · Database tables: <?=e((string)($backupReady['database']['tables']??'?'))?><?php endif;?></p>
<?php foreach(isset($backupReady['source'])?['source'=>'Download Password-Free Source ZIP']:['website'=>$plainFull?'Download Website ZIP (no password)':'Download Encrypted Website ZIP','database'=>$plainFull?'Download Database ZIP (no password)':'Download Encrypted Database ZIP'] as $kind=>$label):?>
<form method="post" action="settings.php?tab=backups">
<input type="hidden" name="_csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="settings_tab" value="backups"><input type="hidden" name="action" value="backup_download"><input type="hidden" name="backup_id" value="<?=e((string)$backupReady['id'])?>"><input type="hidden" name="backup_kind" value="<?=e($kind)?>">
<button class="secondary-action" type="submit"><?=e($label)?></button> <small><?=number_format((int)($backupReady[$kind]['bytes']??0)/1024/1024,2)?> MB · SHA256 <code><?=e((string)($backupReady[$kind]['sha256']??''))?></code></small>
</form>
<?php endforeach;?>
<p class="warning"><strong>Important:</strong> <?=isset($backupReady['source'])?'Source-only exports are NOT a disaster-recovery backup and omit all operational data. Keep this code archive private.':($plainFull?'These full recovery ZIPs are UNENCRYPTED; store them off-host in encrypted storage with restricted access.':'Keep your optional recovery ZIP encryption password securely.') .' Test restoring both full archives on separate isolated hosting/database.'?> No automatic restore or financial postings occur here.</p>
</section>
<?php endif;?>
<?php endif;?>
<?php if($activeTab==='numbering'):?>
<section class="panel"><h2>School Control Number Setup</h2>
<p>Select a school once, then enter the <strong>calendar year YYYY</strong> and the <strong>next series</strong> beside each form you want to configure. Click <strong>Save All Changes</strong> to apply all completed rows together. Leave both boxes empty to skip a form. Numbers are issued in the existing <code>YYYY-MM-NNNN</code> format, not as a new numbering pattern.</p>
<p class="warning">Existing issued documents cannot be renumbered or assigned a conflicting starting sequence. If any completed row is invalid or has issued documents for its school/form/year, <strong>the whole batch is rejected</strong>. Physical checkbook leaf serials are not configured here. Only the Super Administrator may save changes.</p>
<?php
$numberingLoadOk=true;
try{$numberingSchools=IPDAMSNumberingSetup::schoolOptions($db,$user);$numberingRows=IPDAMSNumberingSetup::existing($db,$user);}catch(Throwable $ex){$numberingLoadOk=false;$numberingSchools=[];$numberingRows=[];echo '<p class="alert bad">School numbering information is unavailable; changes have been disabled.</p>';}
$numberingEnteredPeriods=is_array($_POST['periods']??null)?$_POST['periods']:[];
$numberingEnteredSequences=is_array($_POST['sequences']??null)?$_POST['sequences']:[];
$numberingSchoolInput=$_POST['school_id']??$_GET['school_id']??'';
$numberingSelectedSchool=is_string($numberingSchoolInput)?$numberingSchoolInput:'';
$numberingSelectedSchoolValid=false;
foreach($numberingSchools as $school){if((string)$school['id']===$numberingSelectedSchool){$numberingSelectedSchoolValid=true;break;}}
$numberingLabels=[
 'Travel & Training'=>['AR'=>'Activity Request','TA'=>'Travel Authority','RC'=>'Reimbursement Claim'],
 'Procurement'=>['PR'=>'Purchase Request','RFQ'=>'Request for Quotation','AWARD'=>'Award','PO'=>'Purchase Order','IAR'=>'Inspection and Acceptance Report'],
 'Disbursement'=>['DV'=>'Disbursement Voucher'],
 'Supply & Property'=>['RIS'=>'Requisition and Issue Slip','ICS'=>'Inventory Custodian Slip','PAR'=>'Property Acknowledgement Receipt','ITR'=>'Inventory Transfer Report','PTR'=>'Property Transfer Report','RRSP'=>'Receipt of Returned Semi-Expendable Property','PRR'=>'Property Return Record (PPE, internal)'],
];
?>
<form method="post" action="settings.php?tab=numbering" autocomplete="off">
<input type="hidden" name="_csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="settings_tab" value="numbering"><input type="hidden" name="action" value="numbering_save">
<label for="numbering-school">School
<select name="school_id" id="numbering-school" required><option value="">Choose school</option><?php foreach($numberingSchools as $school):?><option value="<?=e((string)$school['id'])?>" <?=$numberingSelectedSchoolValid && (string)$school['id']===$numberingSelectedSchool?'selected':''?>><?=e((string)$school['name'])?></option><?php endforeach;?></select></label>
<div class="scroll"><table class="numbering-setup-table"><thead><tr><th scope="col">Form / document type</th><th scope="col">Calendar year (YYYY)</th><th scope="col">Next series (1–9999)</th></tr></thead><tbody>
<?php foreach($numberingLabels as $group=>$forms):?>
<tr><th colspan="3" scope="colgroup"><?=e($group)?></th></tr>
<?php foreach($forms as $type=>$label):?>
<tr><th scope="row"><strong><?=e($type)?></strong> — <?=e($label)?></th>
<td><input type="text" name="periods[<?=e($type)?>]" value="<?=e(is_string($numberingEnteredPeriods[$type]??null)?(string)$numberingEnteredPeriods[$type]:'')?>" inputmode="numeric" pattern="20[0-9]{2}" placeholder="YYYY" maxlength="4" aria-label="<?=e($type)?> year YYYY"></td>
<td><input type="text" name="sequences[<?=e($type)?>]" value="<?=e(is_string($numberingEnteredSequences[$type]??null)?(string)$numberingEnteredSequences[$type]:'')?>" inputmode="numeric" pattern="[1-9][0-9]{0,3}" placeholder="e.g. 125" maxlength="4" aria-label="<?=e($type)?> next series"></td></tr>
<?php endforeach;endforeach;?>
</tbody></table></div>
<p class="muted">For example: AR year <code>2026</code> and next series <code>125</code> will issue <code>2026-10-0125</code>, followed by <code>2026-11-0126</code>. Every official form follows its own school-level annual sequence; only completed rows are saved. Older monthly counters remain visible as legacy safeguards. Issued documents are never renumbered.</p>
<label>Current Super Administrator password<input name="password" type="password" autocomplete="current-password" required></label>
<button type="submit" <?=$numberingLoadOk?'':'disabled'?>>Save All Changes</button></form>
<?php if($numberingRows):?><details><summary>Review existing next sequences (latest 250)</summary><div class="scroll"><table><thead><tr><th>School</th><th>Form</th><th>Year / prior month setting</th><th>Next series</th></tr></thead><tbody><?php foreach($numberingRows as $row):?><tr><td><?=e((string)$row['school'])?></td><td><?=e((string)$row['doc_type'])?></td><td><?=e(str_ends_with((string)$row['period'],'-00') ? substr((string)$row['period'],0,4).' (annual)' : (string)$row['period'].' (prior monthly)')?></td><td><?=e((string)$row['next_sequence'])?></td></tr><?php endforeach;?></tbody></table></div></details><?php endif;?>
</section>
<?php endif;?>
<?php if($activeTab==='reset'):?>
<section class="panel reset-intro"><h2>Data Reset Center</h2><p>Use these operations only with an independently verified backup and records you are authorized to erase. <strong>App rollback cannot restore database records.</strong> The application schema, update history and version remain unchanged.</p>
<p class="warning"><strong>Custom Reset</strong> deletes selected business groups as complete workflows, not individual PR/PO/IAR/DV rows. If another group still depends on selected data, the reset is blocked with an explanation. Division, Schools, Users and access permissions are always preserved by Custom Reset.</p></section>
<section class="panel"><h2>1. Choose what to delete</h2><p>Select a reset type, then click <strong>Prepare backup and deletion preview</strong>. This step makes no deletions.</p>
<?php $displayResetMode = (string)($resetPreview['mode']??'test'); $preparedCustomGroups = $displayResetMode==='custom' ? ($resetPreview['plan']['selected_groups']??[]) : []; ?>
<form method="post"><input type="hidden" name="_csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="settings_tab" value="reset"><input type="hidden" name="action" value="reset_prepare">
<div class="reset-mode-options">
<label class="reset-mode-card"><input type="radio" name="reset_mode" id="reset-mode-test" value="test" required <?=$displayResetMode==='test'?'checked':''?>><span><strong>Reset Test Data</strong><small>Clear all operational/reference records. Retain Division, Schools and Users.</small></span></label>
<label class="reset-mode-card"><input type="radio" name="reset_mode" id="reset-mode-custom" value="custom" <?=$displayResetMode==='custom'?'checked':''?>><span><strong>Custom Reset — checklist</strong><small>Choose only the groups you want to remove; dependent records stay together.</small></span></label>
<label class="reset-mode-card"><input type="radio" name="reset_mode" id="reset-mode-hard" value="hard" <?=$displayResetMode==='hard'?'checked':''?>><span><strong>System Hard Reset</strong><small>Clear organizations and users too; replace the Super Administrator.</small></span></label>
<div class="reset-custom-options"><h3>Choose complete data groups</h3><p class="muted">Counts represent independent records or transaction heads, not every linked document. The final preview shows the exact table rows. Selecting a procurement group includes its complete PR → PO → IAR → DV → Check records. Related documents are included automatically; selected workflows restart only their own document counters; a complete Supply/RIS/Property reset also restarts lifetime property tags. Audit history is preserved.</p>
<div class="reset-groups">
<?php try{$customGroups=resetCustomOptions($db);}catch(Throwable $ex){$customGroups=[];echo '<p class="alert bad">Cannot load deletion groups; please check the database schema before reset.</p>';} ?>
<?php foreach($customGroups as $key=>$detail):?>
<label class="reset-group"><input type="checkbox" name="reset_groups[]" value="<?=e((string)$key)?>" <?=in_array((string)$key,$preparedCustomGroups,true)?'checked':''?>><span><strong><?=e((string)$detail['label'])?></strong><small><?=e((string)$detail['description'])?></small><em><?=number_format((int)$detail['independent_count'])?> independent record(s)</em></span></label>
<?php endforeach;?></div>
<p class="muted">Groups with live dependencies may need to be selected together. The server checks relationships and rejects unsafe combinations before preparing any backup.</p>
</div></div>
<button type="submit" class="secondary-action">Prepare backup and deletion preview</button><?php if($resetPreview!==null):?><p class="muted">The deletion confirmation below belongs to the <strong>prepared preview</strong>. Changing any selection here does not update it until you prepare a new backup and preview.</p><?php endif;?></form></section>
<?php if($resetPreview!==null):?>
<section class="panel reset-preview"><h2>2. Review backup and affected records</h2><p><strong><?=e($resetPreview['mode']==='hard'?'SYSTEM HARD RESET':($resetPreview['mode']==='custom'?'CUSTOM RESET':'RESET TEST DATA'))?></strong> · Backup ID <code><?=e($resetPreview['id'])?></code> · <?=number_format((int)$resetPreview['plan']['rows_to_delete'])?> rows scheduled for deletion: <?=count($resetPreview['plan']['tables'])?> complete-table clears<?php $counterRows=(int)($resetPreview['plan']['counter_reset']['count']??0); if($counterRows>0):?>, <?=number_format($counterRows)?> selected numbering counter rows<?php endif;?><?php $selectedDocRows=array_sum($resetPreview['plan']['scoped_documents']['counts']??[]); if($selectedDocRows>0):?> and <?=number_format($selectedDocRows)?> selected document rows<?php endif;?>.</p>
<?php if($resetPreview['mode']==='custom'):?><div class="reset-selected"><strong>Selected groups:</strong><ul><?php foreach(($resetPreview['plan']['selected_groups']??[]) as $group):?><li><?=e((string)IPDAMS_CUSTOM_RESET_GROUPS[$group]['label'])?></li><?php endforeach;?></ul><p class="muted">Related document entries included: <?=number_format(array_sum($resetPreview['plan']['scoped_documents']['counts']??[]))?>. Selected numbering counter rows restarted: <?=number_format((int)($resetPreview['plan']['counter_reset']['count']??0))?><?php $propertyCounterRows=(int)($resetPreview['plan']['counter_reset']['by_type']['PROPERTY_TAG']??0);if($propertyCounterRows>0):?>, including <?=number_format($propertyCounterRows)?> lifetime PROPERTY_TAG counter(s)<?php endif;?>. Property tags restart only after a complete Supply/RIS/Property deletion; other reset groups preserve the property tag sequence. Audit history is preserved. School-specific starting sequences may be configured after reset.</p></div><?php endif;?>
<p><a class="reset-download" href="settings.php?tab=reset&amp;reset_download=<?=e($resetPreview['id'])?>">Download protected SQL + attachments backup ZIP</a> (<?=number_format((int)$resetPreview['archive']['size'])?> bytes). Treat it as confidential, because it may contain user accounts and procurement information.</p>
<details><summary>View tables affected</summary><div class="scroll"><table><thead><tr><th>Table</th><th>Current rows</th><th>Action</th></tr></thead><tbody>
<?php foreach($resetPreview['plan']['counts'] as $table=>$count):?><tr><td><code><?=e((string)$table)?></code></td><td><?=number_format((int)$count)?></td><td><?=in_array($table,$resetPreview['plan']['tables'],true)?'Delete all':($table==='document_counters' && (int)($resetPreview['plan']['counter_reset']['count']??0)>0?'Restart selected document/property types ('.number_format((int)$resetPreview['plan']['counter_reset']['count']).' rows)':(isset($resetPreview['plan']['scoped_documents']['counts'][$table])?'Delete selected document types ('.number_format((int)$resetPreview['plan']['scoped_documents']['counts'][$table]).' rows)':'Preserve'))?></td></tr><?php endforeach;?></tbody></table></div></details>
<p class="muted">Backup and preview expire 20 minutes after creation. If records change before reset, deletion will be blocked. Do not continue until your backup has been downloaded and a restore procedure is understood.</p></section>
<section class="panel reset-confirm"><h2>3. Confirm permanent deletion</h2><form method="post" autocomplete="off">
<input type="hidden" name="_csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="settings_tab" value="reset"><input type="hidden" name="action" value="reset_apply"><input type="hidden" name="reset_id" value="<?=e($resetPreview['id'])?>"><input type="hidden" name="reset_mode" value="<?=e($resetPreview['mode'])?>">
<?php if($resetPreview['mode']==='hard'):?><div class="reset-new-admin"><h3>Replacement Super Administrator</h3><p>All existing users will be deleted. Set the NEW administrator credentials now; after reset, use them to sign in again.</p><label>Full name<input name="new_name" maxlength="150" required></label><label>Email<input type="email" name="new_email" maxlength="190" required></label><label>New password (12+ characters)<input type="password" name="new_password" minlength="12" autocomplete="new-password" required></label><label>Confirm new password<input type="password" name="new_password_confirm" minlength="12" autocomplete="new-password" required></label></div><?php endif;?>
<label class="check-label"><input type="checkbox" name="downloaded" value="1" required> I have downloaded and securely stored the verified backup outside this hosting account, and understand that normal application rollback cannot restore deleted records.</label>
<label class="check-label"><input type="checkbox" name="test_only" value="1" required> I confirm this installation contains only testing/disposable records and no official procurement, accounting, bank or property records that must be retained.</label>
<label>Current Super Administrator password<input type="password" name="password" autocomplete="current-password" required></label>
<label>Type <strong><?=e($resetPreview['mode']==='hard'?'FACTORY RESET':($resetPreview['mode']==='custom'?'DELETE SELECTED DATA':'DELETE TEST DATA'))?></strong> to permanently delete these records<input name="confirm_phrase" required autocomplete="off"></label>
<button class="danger" type="submit"><?=e($resetPreview['mode']==='hard'?'Perform SYSTEM HARD RESET':($resetPreview['mode']==='custom'?'Delete selected groups':'Delete operational/test data'))?></button></form></section>
<?php endif;?>
<?php if($resetOutcome!==null && !empty($resetOutcome['attachments_not_removed'])):?><p class="alert bad">Some old attachment files could not be removed; contact the technical administrator for protected storage cleanup.</p><?php endif;?>
<?php endif;?>
<?php if($activeTab==='sql'):?>
<section class="panel"><h2>SQL query console</h2><p>Read queries are restricted to approved statement types. SQL writes remain disabled by default and require explicit technical configuration and separate confirmation.</p>
<form method="post"><input type="hidden" name="_csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="settings_tab" value="sql"><label>SQL query <textarea id="ipdams-sql-input" name="sql" rows="7" spellcheck="false" placeholder="SELECT id, email, role FROM users" required><?=e((string)($_POST['sql']??''))?></textarea></label><div class="sql-toolbar"><button type="button" id="ipdams-copy-sql" class="secondary-action">Copy SQL</button><span id="ipdams-copy-sql-status" class="copy-status" role="status" aria-live="polite"></span></div><div class="buttons"><button name="action" value="query_read" type="submit">Run read query</button></div><p class="muted">Technical SQL access is read-only. Use authorized application workflows for corrections to preserve the financial cutoff, allocation and audit trail.</p></form></section>
<?php if($results!==null):?><section class="panel"><div class="sql-result-heading"><h2>Query results</h2><button type="button" id="ipdams-copy-results" class="secondary-action" <?=isset($results['rows'])?'':'disabled'?>>Copy Results</button></div><div id="ipdams-copy-results-status" class="copy-status" role="status" aria-live="polite"></div>
<?php if(isset($results['rows'])):?><p><?=count($results['rows'])?> row(s)<?=!empty($results['truncated'])?' · limited to 100':''?>.</p><div class="scroll"><table id="ipdams-query-results-table"><thead><tr><?php foreach(array_keys($results['rows'][0]??[]) as $col):?><th><?=e((string)$col)?></th><?php endforeach;?></tr></thead><tbody><?php foreach($results['rows'] as $row):?><tr><?php foreach($row as $cell):?><td><?=e($cell===null?'NULL':(string)$cell)?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table></div><?php else:?><p><?=e((string)$results['affected'])?> row(s) affected.</p><?php endif;?></section><?php endif;?>
<?php endif;?>
<?php endif;?></main></div></div></body></html>
