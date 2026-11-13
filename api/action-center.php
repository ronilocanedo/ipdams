<?php
declare(strict_types=1);
/** IPDAMS Action Center v0.2.262 — authenticated, scoped, read-only work queue. */
require_once dirname(__DIR__).'/private/app/Domain.php';
require_once dirname(__DIR__).'/private/app/ActionCenterWorkflow.php';
ini_set('display_errors','0');ini_set('log_errors','1');
$https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');ini_set('session.cookie_secure',$https?'1':'0');
session_name('IPDAMSSESSID');session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
try {
    demand($_SERVER['REQUEST_METHOD']==='GET','Action Center is read-only',405);
    $org=filter_var($_GET['organization_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    demand($org!==false&&$org!==null,'Select an authorized organization',422);
    $db=database();$user=currentUser($db);
    $result=(new ActionCenterWorkflow($db,$user))->forOrganization((int)$org);
    echo json_encode(['ok'=>true,'data'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}catch(DomainError $e){http_response_code($e->status);echo json_encode(['error'=>$e->getMessage()],JSON_THROW_ON_ERROR);}
catch(Throwable $e){http_response_code(500);error_log('IPDAMS Action Center query: '.$e->getMessage());
    echo json_encode(['error'=>'Action Center could not load. Please refresh; no pending item is confirmed as cleared.'],JSON_THROW_ON_ERROR);}
