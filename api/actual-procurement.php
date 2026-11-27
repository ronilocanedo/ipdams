<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/app/ActualProcurementReport.php';
ini_set('display_errors','0');ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_samesite','Lax');ini_set('session.cookie_secure',(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'1':'0');session_name('IPDAMSSESSID');session_start();
header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');$embedded=($_GET['embedded']??'')==='1';header('X-Frame-Options: '.($embedded?'SAMEORIGIN':'DENY'));header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; form-action 'self'; object-src 'none'; base-uri 'none'; frame-ancestors ".($embedded?"'self'":"'none'"));
function ape(mixed $v):string{return htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function apm(int $v):string{return number_format($v/100,2);}
function apq(int $v):string{return $v?rtrim(rtrim(qtyString($v),'0'),'.'):'';}
$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');$from=$to='';$org=$fund=0;$report=null;$error='';$db=null;
try{
    demand(in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true),'Read-only report',405);$db=database();$user=currentUser($db);
    $org=idOf($_GET,'organization_id');$fund=isset($_GET['fund_id'])&&$_GET['fund_id']!==''?idOf($_GET,'fund_id'):0;
    $from=(string)($_GET['from']??substr($today,0,4).'-01-01');$to=(string)($_GET['to']??$today);
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->beginTransaction();$report=ActualProcurementReport::read($db,$user,$org,$fund,$from,$to);$db->commit();
}catch(DomainError $e){if($db&&$db->inTransaction())$db->rollBack();http_response_code($e->status);$error=$e->getMessage();}
catch(Throwable $e){if($db&&$db->inTransaction())$db->rollBack();http_response_code(500);error_log('Actual procurement report '.$e->getMessage());$error='Actual procurement report unavailable. No records were modified.';}
require dirname(__DIR__).'/private/app/ActualProcurementPrint.php';
