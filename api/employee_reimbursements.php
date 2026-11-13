<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/app/Domain.php';
require_once dirname(__DIR__).'/private/app/EmployeeClaims.php';
ini_set('display_errors','0');ini_set('log_errors','1');
header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');
$https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');ini_set('session.cookie_secure',$https?'1':'0');
session_name('IPDAMSSESSID');session_start();
try {
    $db=database();$actor=currentUser($db);$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
    if($method==='GET'){
        $org=idOf($_GET,'organization_id');
        if(isset($_GET['claim_id']))jsonOut(EmployeeClaims::itemOptions($db,$actor,$org,idOf($_GET,'claim_id')));
        jsonOut(EmployeeClaims::listing($db,$actor,$org));
    }
    demand($method==='POST','Unsupported method',405);csrfCheck();
    $body=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
    demand(is_array($body),'Invalid reimbursement request',400);
    if(($body['action']??'')==='create')jsonOut(EmployeeClaims::create($db,$actor,$body),201);
    if(($body['action']??'')==='review')jsonOut(EmployeeClaims::recordReview($db,$actor,$body),201);
    if(($body['action']??'')==='finalize')jsonOut(EmployeeClaims::finalizeClaim($db,$actor,$body),201);
    demand(false,'Unknown reimbursement action',404);
} catch(DomainError $e){http_response_code($e->status);header('Content-Type: application/json');echo json_encode(['error'=>$e->getMessage()]);}
catch(JsonException $e){http_response_code(400);header('Content-Type: application/json');echo json_encode(['error'=>'Invalid JSON request']);}
catch(PDOException $e){$code=bin2hex(random_bytes(4));error_log('IPDAMS employee claim '.$code.' SQLSTATE='.$e->getCode());http_response_code(500);header('Content-Type: application/json');echo json_encode(['error'=>'Employee reimbursement database error. Reference '.$code]);}
catch(Throwable $e){error_log('IPDAMS employee claim failed '.get_class($e));http_response_code(500);header('Content-Type: application/json');echo json_encode(['error'=>'Employee reimbursement request could not complete']);}
