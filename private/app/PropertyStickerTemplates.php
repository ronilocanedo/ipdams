<?php
declare(strict_types=1);
/** IPDAMS v0.2.383: property sticker templates. No asset/accounting mutations. */
require_once __DIR__.'/PropertyStickerRegistry.php';
final class PropertyStickerTemplates {
    public static function context(PDO $db,array $user,int $org): array {
        demand($org>0,'Select an organization',422);
        scope($db,$user,$org);
        demand(($user['role']??'')!=='VIEWER','Property details are not available to MOOE viewers',403);
        require_once __DIR__.'/DivisionSchoolAccess.php';
        demand(!DivisionSchoolAccess::isDivisionSchool($db,$user,$org),
            'Division school assignments do not grant a property-register view',403);
        $row=one($db,'SELECT id,name,code,kind,parent_division_id FROM organizations WHERE id=? AND active=1',[$org]);
        demand($row!==null,'Organization not found',404);
        demand(in_array($row['kind'],['SCHOOL','DIVISION'],true),'School or Division required',422);
        return $row;
    }
    public static function canManage(array $user,array $org): bool {
        return (($user['role']??'')==='SUPER_ADMIN') ||
           (($user['role']??'')==='DIVISION' && $org['kind']==='DIVISION') ||
           (($user['role']??'')==='SCHOOL' && $org['kind']==='SCHOOL');
    }
    private static function directory(): string {
        // Writable data stay under the existing private storage root, never web-root.
        return dirname(__DIR__).'/storage/private/property-stickers';
    }
    private static function config(int $org): ?array {
        $path=self::directory().'/org-'.$org.'.json';
        if(!is_file($path))return null;
        $value=json_decode((string)file_get_contents($path),true);
        demand(is_array($value)&&($value['organization_id']??null)===$org &&
            isset($value['sha256'],$value['extension']) &&
            is_string($value['sha256']) && preg_match('/^[a-f0-9]{64}$/D',$value['sha256'])===1 &&
            in_array($value['extension'],['png','jpg','webp'],true),
            'Stored sticker template needs administrator review',409);
        return $value;
    }
    public static function effective(PDO $db,array $user,array $org): array {
        $own=self::config((int)$org['id']);
        $divisionId=$org['kind']==='SCHOOL'?(int)($org['parent_division_id']??0):0;
        $division=$own===null && $divisionId>0?self::config($divisionId):null;
        $item=$own??$division;
        $origin=$own?'SCHOOL_OR_OFFICE':($division?'DIVISION_FALLBACK':'DEFAULT');
        return ['origin'=>$origin,'owner_id'=>(int)($item['organization_id']??0),
            'has_image'=>$item!==null,'image_sha256'=>(string)($item['sha256']??''),
            'updated_at'=>(string)($item['updated_at']??''),
            'can_manage'=>self::canManage($user,$org),
            'has_own_template'=>$own!==null];
    }
    public static function image(PDO $db,array $user,array $org): array {
        $effective=self::effective($db,$user,$org);
        demand($effective['has_image'],'No uploaded sticker template',404);
        $owner=$effective['owner_id'];$meta=self::config($owner);
        demand($meta!==null,'Sticker template unavailable',404);
        $extension=(string)$meta['extension'];
        demand(in_array($extension,['png','jpg','webp'],true),'Invalid stored image format',409);
        $path=self::directory().'/org-'.$owner.'-'.$meta['sha256'].'.'.$extension;
        demand(is_file($path),'Sticker template file is missing',409);
        demand(hash_file('sha256',$path)===$meta['sha256'],'Sticker image integrity check failed',409);
        return ['path'=>$path,'mime'=>['png'=>'image/png','jpg'=>'image/jpeg','webp'=>'image/webp'][$extension],
            'sha256'=>$meta['sha256']];
    }
    public static function save(PDO $db,array $user,array $org,array $file): array {
        demand(self::canManage($user,$org),'Template management requires the authorized office administrator',403);
        demand((int)($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK,'Choose an image for the sticker template',422);
        $tmp=(string)($file['tmp_name']??'');
        demand(is_uploaded_file($tmp),'Invalid image upload',422);
        $size=(int)($file['size']??0);
        demand($size>0 && $size<=5242880,'Template image must be 5 MB or smaller',422);
        $dim=@getimagesize($tmp);
        demand(is_array($dim) && (int)$dim[0]>=300 && (int)$dim[1]>=450 &&
            (int)$dim[0]<=5000 && (int)$dim[1]<=8000 && $dim[1]>$dim[0],
            'Use a portrait sticker image (minimum 300 × 450 pixels)',422);
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $formats=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
        demand(isset($formats[$mime]),'Only PNG, JPG and WEBP templates are permitted',422);
        $data=file_get_contents($tmp);
        demand(is_string($data)&&strlen($data)===$size,'Uploaded image could not be read',422);
        $dir=self::directory();
        if(!is_dir($dir))demand(@mkdir($dir,0700,true),'Cannot initialize private template directory',500);
        demand(is_dir($dir)&&!is_link($dir),'Invalid private template directory',500);
        $orgId=(int)$org['id'];$sha=hash('sha256',$data);$ext=$formats[$mime];
        $lock=fopen($dir.'/org-'.$orgId.'.lock','c');
        demand($lock!==false && flock($lock,LOCK_EX),'Cannot lock sticker template',500);
        try {
            $filename=$dir.'/org-'.$orgId.'-'.$sha.'.'.$ext;
            if(!is_file($filename)){
                $temp=tempnam($dir,'upload-');demand($temp!==false,'Cannot stage template upload',500);
                try {
                    demand(file_put_contents($temp,$data,LOCK_EX)===$size,'Cannot save template image',500);
                    chmod($temp,0600);
                    demand(rename($temp,$filename),'Cannot publish template image',500);
                } finally {if(is_file($temp))unlink($temp);}
            }
            $meta=['organization_id'=>$orgId,'sha256'=>$sha,'extension'=>$ext,
                'updated_at'=>date('c'),'updated_by'=>(int)$user['id']];
            $json=json_encode($meta,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            $temp=tempnam($dir,'meta-');demand($temp!==false,'Cannot stage template metadata',500);
            try {demand(file_put_contents($temp,$json,LOCK_EX)===strlen($json),'Cannot save template metadata',500);
                chmod($temp,0600);demand(rename($temp,$dir.'/org-'.$orgId.'.json'),'Cannot publish template metadata',500);
            } finally {if(is_file($temp))unlink($temp);}
            $event=json_encode(['time'=>date('c'),'organization_id'=>$orgId,'user_id'=>(int)$user['id'],
                'action'=>'PROPERTY_STICKER_TEMPLATE_SET','sha256'=>$sha],JSON_THROW_ON_ERROR)."\n";
            demand(file_put_contents($dir.'/changes.jsonl',$event,FILE_APPEND|LOCK_EX)!==false,
                'Template saved but change log failed; contact administrator',500);
            @chmod($dir.'/changes.jsonl',0600);
        } finally {flock($lock,LOCK_UN);fclose($lock);}
        return self::effective($db,$user,$org);
    }
    public static function reset(PDO $db,array $user,array $org): array {
        demand(self::canManage($user,$org),'Only this office administrator may clear its template',403);
        $dir=self::directory();$orgId=(int)$org['id'];
        demand(is_dir($dir),'No own template exists',404);
        $lock=fopen($dir.'/org-'.$orgId.'.lock','c');
        demand($lock!==false && flock($lock,LOCK_EX),'Cannot lock sticker template',500);
        try {
            $path=$dir.'/org-'.$orgId.'.json';
            demand(is_file($path),'No own template exists',404);
            // Audit before removing the override. Existing images are retained as historical evidence.
            $old=self::config($orgId);
            $event=json_encode(['time'=>date('c'),'organization_id'=>$orgId,'user_id'=>(int)$user['id'],
                'action'=>'PROPERTY_STICKER_TEMPLATE_RESET','previous_sha256'=>$old['sha256']],JSON_THROW_ON_ERROR)."\n";
            demand(file_put_contents($dir.'/changes.jsonl',$event,FILE_APPEND|LOCK_EX)!==false,
                'Cannot audit template reset',500);
            @chmod($dir.'/changes.jsonl',0600);
            demand(unlink($path),'Cannot remove office template override',500);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
        return self::effective($db,$user,$org);
    }
    /** Query selected existing property only. Never assign, reserve or modify records. */
    public static function search(PDO $db,array $org,array $input): array {
        $term=trim((string)($input['search']??''));
        demand(mbSafeLen($term)<=100,'Search is too long',422);
        $before=trim((string)($input['before_id']??''));
        demand($before===''||(ctype_digit($before)&&(int)$before>0),'Invalid page cursor',422);
        $where=['a.organization_id=?',"a.status<>'TRANSFERRED'"];$args=[(int)$org['id']];
        if($term!==''){
            $where[]="(a.property_tag LIKE ? OR i.name LIKE ? OR COALESCE(a.serial_number,'') LIKE ? OR COALESCE(a.custodian,'') LIKE ?)";
            $like='%'.addcslashes($term,'%_\\').'%';$args=array_merge($args,[$like,$like,$like,$like]);
        }
        if($before!==''){$where[]='a.id<?';$args[]=(int)$before;}
        $sql="SELECT a.id,a.item_id,a.property_tag,a.acquired_on,a.acquisition_cost,a.serial_number,a.custodian,a.property_profile,a.status,i.name AS item_name,
            i.category AS catalog_category,i.owner_organization_id AS catalog_owner,
            (SELECT ad.display_number FROM accountability_documents ad
              WHERE ad.asset_id=a.id AND ad.organization_id=a.organization_id AND ad.status='FINALIZED'
              ORDER BY ad.id DESC LIMIT 1) AS accountability_number
            FROM assets a JOIN item_catalog i ON i.id=a.item_id
            WHERE ".implode(' AND ',$where).' ORDER BY a.id DESC LIMIT 51';
        $all=q($db,$sql,$args)->fetchAll();$more=count($all)>50;
        $rows=array_slice($all,0,50);
        PropertyStickerRegistry::addCodes($db,$org,$rows);
        foreach($rows as &$row){
            $profile=json_decode((string)($row['property_profile']??''),true);
            $row['brand']=is_array($profile)?(string)($profile['shared']['brand']??($profile['brand']??'')):'';
            $row['model']=is_array($profile)?(string)($profile['shared']['model']??($profile['model']??'')):'';
            $row['school']=$org['name'];
            unset($row['property_profile'],$row['catalog_owner'],$row['catalog_category'],$row['item_id']);
        }unset($row);
        return ['rows'=>$rows,'has_more'=>$more,'next_before_id'=>$more?(int)end($rows)['id']:null];
    }
}
