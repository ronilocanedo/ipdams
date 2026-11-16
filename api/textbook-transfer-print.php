<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/app/Domain.php';
require_once dirname(__DIR__).'/private/app/TextbookTransferPrint.php';
ini_set('display_errors','0');ini_set('log_errors','1');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; form-action 'none'; frame-ancestors 'none'");
$https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_samesite','Lax');ini_set('session.cookie_secure',$https?'1':'0');
session_name('IPDAMSSESSID');session_start();
try {
    demand($_SERVER['REQUEST_METHOD']==='GET','Unsupported printing request',405);
    $db=database();$user=currentUser($db);
    $html=TextbookTransferPrint::render($db,$user,$_GET);
    header('Content-Type: text/html; charset=utf-8');echo $html;
}catch(DomainError $e){http_response_code($e->status);header('Content-Type: text/plain; charset=utf-8');echo $e->getMessage();}
catch(Throwable $e){error_log('IPDAMS textbook transfer print: '.$e->getMessage());http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');echo 'Unable to print textbook transfer document. Check the saved record.';}
