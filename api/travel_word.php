<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/app/TravelWorkflow.php';
require_once dirname(__DIR__).'/private/app/TravelBranding.php';
require_once dirname(__DIR__).'/private/app/TravelWord.php';
ini_set('display_errors','0');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');
session_name('IPDAMSSESSID');session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');
try {
    demand(($_SERVER['REQUEST_METHOD']??'GET')==='GET','Unsupported request',405);
    $db=database();$user=currentUser($db);
    $record=(new TravelWorkflow($db,$user))->detail($_GET);
    $s=$record['snapshot'];
    $org=(int)$s['organization_id'];
    $o=one($db,'SELECT name,kind,parent_division_id,address,contact_phone AS phone,contact_email AS email FROM organizations WHERE id=?',[$org]);
    demand($o!==null,'Organization unavailable',404);
    $division=(int)($o['kind']==='DIVISION'?$org:($o['parent_division_id']??0));
    $divisionRow=$division>0?one($db,"SELECT name FROM organizations WHERE id=? AND kind='DIVISION'",[$division]):null;
    $branding=new TravelBranding($db,$user);
    $letterhead=$branding->currentForPrint($org);
    $images=[];
    foreach(['header','footer'] as $slot){
        if($letterhead[$slot]!==null){
            $img=$branding->image(['organization_id'=>$org,'slot'=>$slot,'version'=>$letterhead['version_id']]);
            $images[$slot]=$img['bytes'];
        }
    }
    $parts=TravelWord::parts($record,$o,(string)($divisionRow['name']??''),$letterhead,$images);
    $temp=tempnam(sys_get_temp_dir(),'ipdams-word-');
    demand($temp!==false,'Temporary Word file unavailable',503);
    try {
        TravelWord::write($parts,$temp);
        $size=filesize($temp);
        demand($size!==false && $size>1000,'Generated Word file unavailable',503);
        $type=(string)($s['kind']??'AR');
        $number=preg_replace('/[^A-Za-z0-9_-]/','-',(string)($record['number']??'Draft'));
        $name=($type==='AR'?'Activity_Request_':'Travel_Authority_').$number.'.docx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="'.$name.'"');
        header('Content-Length: '.(string)$size);
        readfile($temp);
    } finally { if(is_file($temp))@unlink($temp); }
}catch(DomainError $e){http_response_code($e->status);header('Content-Type: text/plain; charset=utf-8');echo 'Word download unavailable: '.$e->getMessage();}
catch(Throwable $e){error_log('Travel Word generation failed: '.$e->getMessage());http_response_code(500);header('Content-Type: text/plain; charset=utf-8');echo 'Travel Word document temporarily unavailable';}
