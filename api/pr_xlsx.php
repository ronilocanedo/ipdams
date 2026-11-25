<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/app/bootstrap.php';
require_once dirname(__DIR__).'/private/app/PrXlsx.php';

// Read-only export. Never creates, re-numbers, edits or finalizes a PR.
ini_set('session.cookie_httponly','1');
session_name('IPDAMSSESSID');session_start();
try{
    $db=database();$user=currentUser($db);
    $org=idOf($_GET,'organization_id');scope($db,$user,$org);
    $id=idOf($_GET,'id');
    $current=one($db,'SELECT status,display_number FROM purchase_requests WHERE id=? AND organization_id=?',[$id,$org]);
    demand($current!==null&&$current['status']==='FINALIZED','Only finalized PRs may be exported',404);
    $registry=one($db,"SELECT display_number FROM document_registry WHERE organization_id=? AND doc_type='PR' AND entity_id=?",[$org,$id]);
    demand($registry!==null&&$registry['display_number']===$current['display_number'],'PR control number does not match registry',404);
    $version=one($db,"SELECT snapshot_json,version_no,created_at FROM document_versions WHERE organization_id=? AND doc_type='PR' AND entity_id=? ORDER BY version_no DESC LIMIT 1",[$org,$id]);
    demand($version!==null,'PR finalized snapshot unavailable',404);
    $snapshot=json_decode((string)$version['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
    demand(is_array($snapshot) && is_array($snapshot['header']??null) && is_array($snapshot['lines']??null) && count($snapshot['lines'])>0,'Invalid PR snapshot',404);
    demand((string)($snapshot['number']??'')===(string)$registry['display_number'],'PR snapshot number does not match registry',404);
    $orgRow=one($db,'SELECT name FROM organizations WHERE id=?',[$org]);
    demand($orgRow!==null,'Organization unavailable',404);
    $header=$snapshot['header'];
    $content=ipdamsPrXlsx([
        'organization_name'=>(string)($snapshot['organization_name']??$orgRow['name']),
        'number'=>(string)$registry['display_number'],
        'document_date'=>(string)($header['document_date']??''),
        'requesting_unit'=>(string)($header['requesting_unit']??''),
        'requestor'=>is_array($snapshot['requestor']??null)?$snapshot['requestor']:null,
        'fund_cluster'=>(string)($header['fund_cluster']??''),
        'responsibility_center_code'=>(string)($header['responsibility_center_code']??''),
        'fund_name'=>(string)($snapshot['fund_name']??'See linked source-of-funds record'),
        'purpose'=>(string)($header['purpose']??''),
        'version'=>(string)$version['version_no'],
        'recorded'=>substr((string)$version['created_at'],0,10),
        'expected_total'=>$header['total']??null,
        'lines'=>$snapshot['lines']
    ]);
    $filename='IPDAMS_PR_'.preg_replace('/[^A-Za-z0-9_.-]/','_', (string)$registry['display_number']).'.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Content-Length: '.strlen($content));
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    echo $content;
}catch(Throwable $e){
    error_log('IPDAMS PR XLSX export failed (no request details): '.get_class($e));
    http_response_code($e instanceof DomainError?$e->status:404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'PR Excel export unavailable. Review the finalized document and retry.';
}
