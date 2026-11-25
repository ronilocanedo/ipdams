<?php
declare(strict_types=1);
/** No SMTP. Authenticated Division/Super Admin approvals via Action Center. */
if((string)($_GET['mode']??'request')==='change')
    define('IPDAMS_PASSWORD_RECOVERY_CHANGE_PAGE',true);
require_once dirname(__DIR__).'/private/app/Domain.php';
require_once dirname(__DIR__).'/private/app/PasswordRecovery.php';
ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');
$https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
ini_set('session.cookie_secure',$https?'1':'0');
session_name('IPDAMSSESSID');session_start();
$_SESSION['csrf']??=bin2hex(random_bytes(24));
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
function preE(mixed $s):string { return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
$mode=(string)($_GET['mode']??'request');
if(!in_array($mode,['request','review','change'],true))$mode='request';
$error='';$success='';$rows=[];$scopeId=0;$forced=false;
try {
    $isHttps=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off') ||
        (($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https');
    demand($isHttps || cfg('APP_ENV')!=='production',
        'HTTPS is required for password recovery',403);
    $db=database();$recovery=new PasswordRecovery($db);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        demand(hash_equals((string)$_SESSION['csrf'],(string)($_POST['_csrf']??'')),
            'Security token expired. Refresh and retry.',403);
    }
    if($mode==='request') {
        if($_SERVER['REQUEST_METHOD']==='POST'){
            // Database-backed limits apply even after cookies are cleared or sessions change.
            $recovery->throttlePublicRequest((string)($_POST['username']??''),
                (string)($_SERVER['REMOTE_ADDR']??''));
            $attempts=(int)($_SESSION['password_recovery_tries']??0);
            demand($attempts<5,'Too many recovery submissions in this browser session.',429);
            $_SESSION['password_recovery_tries']=$attempts+1;
            $r=$recovery->request((string)($_POST['username']??''));
            $success=$r['message'].' Reference #'.$r['request_id'].'.';
        }
    }elseif($mode==='review') {
        $actor=currentUser($db);
        demand(in_array(($actor['role']??''),['DIVISION','SUPER_ADMIN'],true),
            'Division Office or Super Administrator access required',403);
        $scopeId=(int)($_POST['division_id']??$_GET['division_id']??0);
        demand($scopeId>0,'Choose a Division Office in the Action Center first',422);
        scope($db,$actor,$scopeId);
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $result=$recovery->approve($actor,$scopeId,(int)($_POST['request_id']??0));
            $success='Request #'.(int)$result['request_id'].' approved. Temporary password = full login ID (including @email.com, if applicable) + school ID (or Division Office code). Example: name@email.com306351. This temporary password is valid for 24 hours and must be changed immediately after login.';
        }
        $rows=$recovery->pending($actor,$scopeId);
    }else{
        demand(!empty($_SESSION['password_reset_force_change']),
            'The current session does not require a temporary password change',403);
        $actor=currentUser($db);
        $forced=true;
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $current=(string)($_POST['temporary_password']??'');
            $new=(string)($_POST['new_password']??'');
            $again=(string)($_POST['confirm_password']??'');
            demand(strlen($new)>=8&&strlen($new)<=256,'New password must be 8–256 characters',422);
            demand(hash_equals($new,$again),'New passwords do not match',422);
            $db->beginTransaction();
            try{
                (new Domain($db,$actor))->changeOwnPassword(['current_password'=>$current,'new_password'=>$new]);
                $recovery->markCompleted((int)$actor['id'],(int)$actor['id']);
                $db->commit();
            }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
            $r=one($db,'SELECT password_hash FROM users WHERE id=? AND active=1',[(int)$actor['id']]);
            demand($r!==null,'Account unavailable after password change',409);
            $_SESSION['credential_stamp']=ipdamsCredentialStamp((string)$r['password_hash']);
            unset($_SESSION['password_reset_force_change']);
            header('Location: ../index.php',true,303);exit;
        }
    }
}catch(DomainError $e){http_response_code($e->status);$error=$e->getMessage();}
catch(Throwable $e){error_log('IPDAMS recovery failure: '.get_class($e));http_response_code(500);$error='Recovery request could not be completed. Contact the technical administrator.';}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>IPDAMS · Password Recovery</title><style>
*{box-sizing:border-box}body{margin:0;font-family:system-ui,Arial,sans-serif;background:#eef3f6;color:#17304d}
main{max-width:650px;margin:42px auto;padding:24px;background:#fff;border:1px solid #d9e4ec;border-radius:14px}
h1{font-size:23px;margin:0 0 9px}p{line-height:1.5}label{display:block;font-weight:650;margin:18px 0 5px}
input,button{font:inherit}input{width:100%;padding:11px;border:1px solid #a9bac8;border-radius:6px}button{padding:11px 16px;border:0;border-radius:7px;background:#086d76;color:white;font-weight:700;cursor:pointer;margin-top:14px}a{color:#086d76}
.card{border:1px solid #d9e4ec;border-radius:10px;padding:16px;margin:14px 0}.alert{padding:12px;background:#fff1ee;color:#9e2a16}.ok{padding:12px;background:#ecf8f5;color:#13613e}.subtle{color:#526779;font-size:14px}.top{display:flex;align-items:center;justify-content:space-between;gap:12px}@media(max-width:650px){main{margin:0;min-height:100vh;border:0;border-radius:0}}
</style></head><body><main>
<div class="top"><h1><?=preE($mode==='review'?'Password Reset Approval Queue':($mode==='change'?'Change Temporary Password':'Forgot Password'))?></h1><a href="../index.php">IPDAMS</a></div>
<?php if($error):?><p class="alert" role="alert"><?=preE($error)?></p><?php endif;?>
<?php if($success):?><p class="ok" role="status"><?=preE($success)?></p><?php endif;?>
<?php if($mode==='request'):?>
<p>Enter the username portion of your existing login ID, or the complete login ID. Your Division Office will receive an in-app request. Email delivery is not required.</p>
<form method="post" autocomplete="off"><input type="hidden" name="_csrf" value="<?=preE($_SESSION['csrf'])?>">
<label for="username">Username or existing login ID</label><input id="username" name="username" maxlength="190" required placeholder="e.g. juandelacruz" autocomplete="username">
<button type="submit">Request password reset</button></form>
<p class="subtle">Ask an authorized Division user or the system owner to review the request in Action Center. After approval, sign in with your existing login ID and the approved temporary credential. It expires after 24 hours and must be changed immediately.</p>
<?php elseif($mode==='review'):?>
<p>Review the account details below, then click Approve. An authorized Division user may approve requests in their own Division; the Super Administrator (system owner) may approve requests from any Division. You cannot approve your own request. No email is sent.</p>
<?php foreach($rows as $row):?>
<section class="card"><b><?=preE($row['full_name'])?></b> · <?=preE($row['role'])?><p class="subtle">Login ID: <?=preE($row['email'])?> · Office: <?=preE($row['school_name']??'Division')?> · Submitted: <?=preE($row['requested_at'])?></p>
<form method="post"><input type="hidden" name="_csrf" value="<?=preE($_SESSION['csrf'])?>"><input type="hidden" name="division_id" value="<?=preE($scopeId)?>"><input type="hidden" name="request_id" value="<?=preE($row['id'])?>">
<button type="submit">Approve request #<?=preE($row['id'])?></button></form></section>
<?php endforeach;?>
<?php if(!$rows):?><p class="subtle">No pending requests from other users in this Division Office.</p><?php endif;?>
<?php else:?>
<p>Set your own new password before entering the financial workspace. Your temporary credential cannot be used for ordinary IPDAMS transactions.</p>
<?php if($forced):?><form method="post" autocomplete="off"><input type="hidden" name="_csrf" value="<?=preE($_SESSION['csrf'])?>">
<label>Temporary password</label><input type="password" name="temporary_password" autocomplete="current-password" required>
<label>New password (at least 8 characters)</label><input type="password" name="new_password" minlength="8" maxlength="256" autocomplete="new-password" required>
<label>Confirm new password</label><input type="password" name="confirm_password" minlength="8" maxlength="256" autocomplete="new-password" required>
<button type="submit">Save new password and enter IPDAMS</button></form><?php endif;?>
<?php endif;?></main></body></html>
