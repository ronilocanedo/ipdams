<?php
declare(strict_types=1);
/** v0.2.132 - global system-branding images (not DepEd official document letterhead). */
require_once __DIR__.'/SettingsService.php';

const IPDAMS_BRANDING_MAX_BYTES = 3_000_000;
const IPDAMS_BRANDING_TYPES = ['image/png'=>'png', 'image/jpeg'=>'jpg', 'image/webp'=>'webp'];

function brandingStorage(): string {
    $root=settingsRoot().'/storage/private/branding';
    if (is_link($root)) throw new RuntimeException('Unsafe branding storage');
    if(!is_dir($root) && !mkdir($root,0700,true) && !is_dir($root)) throw new RuntimeException('Branding storage is unavailable');
    return $root;
}
function brandingOwner(PDO $db, array $user): bool {
    if(($user['role']??'')!=='SUPER_ADMIN' || (int)($user['id']??0)<1) return false;
    // First account is the original super-admin (the bootstrap owner). Pin it
    // permanently on first owner visit, so deleting the owner account cannot
    // transfer branding control to another Super Administrator.
    $ownerFile=brandingStorage().'/owner.json';
    if(is_link($ownerFile)) return false;
    if(is_file($ownerFile)){
        $record=json_decode((string)file_get_contents($ownerFile),true);
        return is_array($record) && is_int($record['user_id']??null) &&
            $record['user_id']>0 && $record['user_id']===(int)$user['id'];
    }
    $first=one($db, 'SELECT id FROM users ORDER BY id ASC LIMIT 1');
    if($first===null || (int)$first['id']!==(int)$user['id'])return false;
    settingsWriteJson($ownerFile,['user_id'=>(int)$user['id'],'bound_at'=>gmdate('c')]);
    settingsAudit((int)$user['id'],'branding.owner_bound',[]);
    return true;
}
function brandingRequireOwner(PDO $db, array $user): void {
    demand(brandingOwner($db,$user),'System branding is restricted to the original owner account',403);
}
function brandingCurrent(): array {
    $file=brandingStorage().'/current.json';
    if(!is_file($file)) return ['icon'=>null,'header'=>null];
    if(is_link($file)) throw new RuntimeException('Unsafe branding settings');
    $r=json_decode((string)file_get_contents($file),true);
    if(!is_array($r)) throw new RuntimeException('Invalid branding settings');
    return ['icon'=>$r['icon']??null,'header'=>$r['header']??null];
}
/** Validate real decoded image headers and enforce reasonable aspect ratios. */
function brandingValidate(string $file, string $kind): array {
    demand(in_array($kind,['icon','header'],true),'Invalid branding image slot',422);
    demand(is_file($file) && !is_link($file),'Image upload is unavailable',422);
    $size=filesize($file);
    demand($size!==false && $size>0 && $size<=IPDAMS_BRANDING_MAX_BYTES,'Choose an image smaller than 3 MB',422);
    $type=(new finfo(FILEINFO_MIME_TYPE))->file($file);
    demand(isset(IPDAMS_BRANDING_TYPES[$type]),'Only PNG, JPEG, and WebP images are accepted',422);
    $info=@getimagesize($file);
    demand(is_array($info) && ($info['mime']??'')===$type,'Image file is invalid',422);
    $width=(int)$info[0];$height=(int)$info[1];
    demand($width>=64 && $height>=64 && $width<=6000 && $height<=6000,'Image dimensions must be 64–6000 pixels',422);
    $ratio=$width/$height;
    if($kind==='icon') demand($ratio>=0.75 && $ratio<=1.33,'Application icon must be approximately square',422);
    else demand($ratio>=2.5 && $ratio<=6.0,'Header logo must be a wide image',422);
    return ['type'=>$type,'ext'=>IPDAMS_BRANDING_TYPES[$type], 'width'=>$width,'height'=>$height,'sha256'=>hash_file('sha256',$file),'bytes'=>$size];
}
function brandingSave(PDO $db, array $user, string $kind, array $upload): void {
    brandingRequireOwner($db,$user);
    demand(in_array($kind,['icon','header'],true),'Invalid image slot',422);
    demand(!is_file(settingsPrivate().'/reset-maintenance.lock'),'System reset is in progress',503);
    demand(($upload['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK,'Select a valid image file',422);
    $source=$upload['tmp_name']??null;
    demand(is_string($source) && is_uploaded_file($source),'Invalid uploaded image',422);
    $info=brandingValidate($source,$kind);
    $dir=brandingStorage();
    $name=$kind.'-'.substr($info['sha256'],0,32).'.'.$info['ext'];
    $dest=$dir.'/'.$name;
    if(is_file($dest)) demand(!is_link($dest) && hash_equals($info['sha256'],(string)hash_file('sha256',$dest)), 'Stored image conflict',409);
    else {
        demand(!file_exists($dest) && !is_link($dest),'Stored image slot is unavailable',409);
        $tmp=$dir.'/staging-'.bin2hex(random_bytes(12));
        demand(move_uploaded_file($source,$tmp),'Could not store branding image',500);
        @chmod($tmp,0600);
        if(!rename($tmp,$dest)) {@unlink($tmp);throw new RuntimeException('Could not activate branding image');}
    }
    $current=brandingCurrent();
    $current[$kind]=['name'=>$name,'sha256'=>$info['sha256'],'mime'=>$info['type'],'width'=>$info['width'],'height'=>$info['height'],'uploaded_at'=>gmdate('c'),'owner_id'=>(int)$user['id']];
    settingsWriteJson($dir.'/current.json',$current);
    settingsAudit((int)$user['id'],'branding.upload',['kind'=>$kind,'sha256'=>$info['sha256']]);
}
function brandingRestore(PDO $db, array $user, string $kind): void {
    brandingRequireOwner($db,$user);
    demand(in_array($kind,['icon','header'],true),'Invalid image slot',422);
    demand(!is_file(settingsPrivate().'/reset-maintenance.lock'),'System reset is in progress',503);
    $current=brandingCurrent();$current[$kind]=null;
    settingsWriteJson(brandingStorage().'/current.json',$current);
    settingsAudit((int)$user['id'],'branding.restore_default',['kind'=>$kind]);
}
/** Serve only a referenced image under an immutable hashed file name, otherwise bundled default. */
function brandingAsset(string $kind): array {
    demand(in_array($kind,['icon','header'],true),'Unknown branding asset',404);
    $current=brandingCurrent();$entry=$current[$kind];
    if(is_array($entry) && is_string($entry['name']??null) &&
       preg_match('/^'.preg_quote($kind,'/').'-[a-f0-9]{32}\.(?:png|jpg|webp)$/D',$entry['name'])===1){
        $file=brandingStorage().'/'.$entry['name'];
        if(is_file($file) && !is_link($file) && hash_equals((string)($entry['sha256']??''),(string)hash_file('sha256',$file))) {
            return ['path'=>$file,'mime'=>(string)$entry['mime']];
        }
    }
    $file=settingsPublicRoot().'/assets/ipdams-default-'.($kind==='icon'?'icon':'header').'.png';
    demand(is_file($file) && !is_link($file),'Default branding image unavailable',503);
    return ['path'=>$file,'mime'=>'image/png'];
}
