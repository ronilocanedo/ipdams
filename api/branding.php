<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/app/SystemBranding.php';
ini_set('display_errors','0');
try{
    $kind=(string)($_GET['kind']??'');
    $asset=brandingAsset($kind);
    header('Content-Type: '.$asset['mime']);
    header('Cache-Control: public, max-age=0, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'");
    header('X-Frame-Options: DENY');
    header('Content-Length: '.(string)filesize($asset['path']));
    readfile($asset['path']);
}catch(Throwable $e){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');echo 'Brand asset unavailable';}
