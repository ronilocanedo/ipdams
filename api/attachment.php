<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/app/bootstrap.php';
require_once dirname(__DIR__).'/private/app/DivisionSchoolAccess.php';
ini_set('display_errors','0');ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');
session_name('IPDAMSSESSID');session_start();
header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store');
try{
    $db=database();$user=currentUser($db);$org=(int)($_REQUEST['organization_id']??0);demand($org>0,'Organization required');scope($db,$user,$org);
    $root=dirname(__DIR__).'/private/storage/private';
    // School-assigned Division access to issued RIS/ICS/PAR does not grant
    // arbitrary stock, asset-register or textbook evidence attachments.
    $denySensitiveAttachment=static function(string $type)use($db,$user,$org):void {
        if(DivisionSchoolAccess::isDivisionSchool($db,$user,$org))
            demand(!in_array(strtoupper($type),['ASSET','ASSETS','STOCK','STOCKS',
                'STOCK_LOT','TEXTBOOK','TEXTBOOKS','PROPERTY','PROPERTY_REGISTER'],true),
                'Stock, asset register and textbook attachments require additional authorization',403);
    };
    if($_SERVER['REQUEST_METHOD']==='POST'){
        csrfCheck();allowedToWrite($user);
        $type=requiredString($_POST,'doc_type',16);$denySensitiveAttachment($type);$entity=idOf($_POST,'entity_id');
        demand(one($db,'SELECT 1 FROM document_registry WHERE organization_id=? AND doc_type=? AND entity_id=?',[$org,$type,$entity])!==null,'Finalized document required');
        demand(isset($_FILES['attachment'])&&$_FILES['attachment']['error']===UPLOAD_ERR_OK,'Valid uploaded file required');$f=$_FILES['attachment'];
        demand($f['size']>0&&$f['size']<=8388608,'Maximum file size is 8 MB');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);$allowed=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'];demand(isset($allowed[$mime]),'Only PDFs, JPEG and PNG files are permitted');
        demand(is_dir($root)||mkdir($root,0700,true),'Private storage inaccessible',500);$stored=bin2hex(random_bytes(24)).'.'.$allowed[$mime];
        demand(move_uploaded_file($f['tmp_name'],$root.'/'.$stored),'Upload could not be stored',500);chmod($root.'/'.$stored,0600);
        $hash=hash_file('sha256',$root.'/'.$stored);
        try{q($db,'INSERT INTO attachments(organization_id,doc_type,entity_id,stored_filename,original_name,mime,sha256,uploaded_by) VALUES (?,?,?,?,?,?,?,?)',[$org,$type,$entity,$stored,substr(basename($f['name']),0,200),$mime,$hash,$user['id']]);}
        catch(Throwable $e){@unlink($root.'/'.$stored);throw $e;}
        header('Content-Type: application/json');echo json_encode(['data'=>['id'=>(int)$db->lastInsertId(),'sha256'=>$hash]]);exit;
    }
    $id=idOf($_GET,'id');$doc=one($db,'SELECT * FROM attachments WHERE id=? AND organization_id=?',[$id,$org]);demand($doc!==null,'Attachment unavailable',404);
    $denySensitiveAttachment((string)$doc['doc_type']);
    if($user['role']==='VIEWER')demand(!in_array((string)$doc['doc_type'],['RIS','ICS','PAR','RSMI'],true),
        'Use the finalized monthly issued-document printout',403);
    $path=$root.'/'.$doc['stored_filename'];demand(is_file($path),'Attachment missing',404);
    header('Content-Type: '.$doc['mime']);header('Content-Length: '.filesize($path));header('Content-Disposition: attachment; filename="attachment-'.$id.'.'.pathinfo($doc['stored_filename'],PATHINFO_EXTENSION).'"');readfile($path);
}catch(DomainError $e){http_response_code($e->status);header('Content-Type: application/json');echo json_encode(['error'=>$e->getMessage()]);}
catch(Throwable $e){error_log('IPDAMS attachment error: '.$e->getMessage());http_response_code(500);header('Content-Type: application/json');echo json_encode(['error'=>'Attachment operation failed']);}
