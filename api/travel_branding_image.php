<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/app/TravelBranding.php';
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('session.use_strict_mode','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');
session_name('IPDAMSSESSID');session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, no-store');
try {
    demand(($_SERVER['REQUEST_METHOD']??'GET')==='GET','Method not allowed',405);
    $db=database();$user=currentUser($db);
    $record=(new TravelBranding($db,$user))->image($_GET);
    header('Content-Type: '.$record['mime']);
    header('Content-Length: '.strlen($record['bytes']));
    echo $record['bytes'];
}catch(DomainError $e){http_response_code($e->status);header('Content-Type: text/plain; charset=utf-8');echo 'Artwork unavailable';}
catch(Throwable $e){error_log('Letterhead image error: '.$e->getMessage());http_response_code(500);header('Content-Type: text/plain; charset=utf-8');echo 'Artwork unavailable';}
