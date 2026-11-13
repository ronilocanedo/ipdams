<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
/**
 * Private, organization-scoped letterhead artwork for travel document printing.
 * Neither public URLs nor the client can choose a filesystem path.
 * Images are immutable SHA256-named files below private/branding; reference data
 * and prior versions are append-only, with explicit replacement or removal.
 */
final class TravelBranding {
    private const CONFIG_KEY='travel_document_letterhead_v1';
    public const MAX_BYTES=614400;
    public function __construct(private PDO $db,private array $user){}
    private function organization(array $params,bool $write=false): int {
        $org=idOf($params,'organization_id');scope($this->db,$this->user,$org);
        $row=one($this->db,'SELECT kind FROM organizations WHERE id=? AND active=1',[$org]);
        demand($row!==null && in_array($row['kind'],['SCHOOL','DIVISION'],true),'Choose an active school or Division workspace',404);
        if($write){
            allowedToWrite($this->user);
            demand($this->user['role']==='SUPER_ADMIN' ||
                ($this->user['role']==='SCHOOL' && $row['kind']==='SCHOOL') ||
                ($this->user['role']==='DIVISION' && $row['kind']==='DIVISION'),
                'Only an authorized officer of this school or Division can change its letterhead',403);
        }
        return $org;
    }
    private static function slot(string $value): string {
        $slot=strtolower(trim($value));demand(in_array($slot,['header','footer'],true),'Choose header or footer',422);return $slot;
    }
    private function latest(int $org,bool $lock=false): array {
        $row=one($this->db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1'.($lock?' FOR UPDATE':''),[$org,self::CONFIG_KEY]);
        if(!$row)return ['version_id'=>0,'header'=>null,'footer'=>null];
        $config=json_decode((string)$row['config_json'],true,64,JSON_THROW_ON_ERROR);
        demand(is_array($config),'Letterhead settings require administrative review',409);
        return ['version_id'=>(int)$row['id'],
            'header'=>self::cleanMeta($config['header']??null),
            'footer'=>self::cleanMeta($config['footer']??null)];
    }
    private static function cleanMeta(mixed $value): ?array {
        if(!is_array($value) || !isset($value['sha256'],$value['mime'],$value['width'],$value['height'],$value['bytes']))return null;
        demand(is_string($value['sha256']) && preg_match('/^[a-f0-9]{64}$/D',$value['sha256'])===1,
            'Stored letterhead file reference requires administrative review',409);
        demand(in_array($value['mime'],['image/png','image/jpeg'],true),'Stored letterhead type requires administrative review',409);
        return ['sha256'=>$value['sha256'],'mime'=>$value['mime'],'width'=>(int)$value['width'],
            'height'=>(int)$value['height'],'bytes'=>(int)$value['bytes']];
    }
    public function settings(array $params): array {
        $org=$this->organization($params);$config=$this->latest($org);
        $kind=one($this->db,'SELECT kind FROM organizations WHERE id=?',[$org])['kind'];
        $config['organization_id']=$org;
        $config['can_manage']=$this->user['role']==='SUPER_ADMIN' ||
            ($kind==='SCHOOL' && $this->user['role']==='SCHOOL') ||
            ($kind==='DIVISION' && $this->user['role']==='DIVISION');
        return $config;
    }
    /** This is called inside the standard CSRF/idempotency-protected API transaction. */
    public function save(array $data): array {
        $org=$this->organization($data,true);
        $slot=self::slot((string)($data['slot']??''));
        $action=strtoupper(trim((string)($data['action']??'UPLOAD')));
        demand(in_array($action,['UPLOAD','REMOVE'],true),'Unknown letterhead operation');
        $reason=requiredString($data,'reason',500);
        // Locking the org serializes updates to both slots. A different slot may
        // otherwise be lost if two users save from the same configuration version.
        one($this->db,'SELECT id FROM organizations WHERE id=? FOR UPDATE',[$org]);
        $prev=$this->latest($org);
        demand(isset($data['expected_version']) && (is_int($data['expected_version']) || is_string($data['expected_version'])) && ctype_digit((string)$data['expected_version']) &&
            (int)$data['expected_version']===$prev['version_id'],
            'Letterhead changed. Refresh the settings before saving.',409);
        $next=['header'=>$prev['header'],'footer'=>$prev['footer']];
        if($action==='REMOVE'){
            demand($next[$slot]!==null,'No uploaded artwork exists for this slot');
            $next[$slot]=null;
        }else{
            $encoded=$data['image_base64']??null;
            demand(is_string($encoded) && $encoded!=='' && strlen($encoded)<=self::MAX_BYTES*4/3+16,
                'Choose a PNG/JPEG smaller than 600 KB',413);
            demand((bool)preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D',$encoded),
                'Only encoded image bytes are accepted',422);
            $bytes=base64_decode($encoded,true);
            demand(is_string($bytes) && strlen($bytes)>0 && strlen($bytes)<=self::MAX_BYTES,
                'Image must be 600 KB or smaller',413);
            $next[$slot]=self::validateImage($bytes);
            // Store privately BEFORE pointing the database to the new immutable
            // image. An interrupted DB transaction can only leave an unreferenced
            // content-hash file; it cannot break the previous version.
            self::writeImage($org,$next[$slot],$bytes);
        }
        demand($prev[$slot]!==$next[$slot],'That slot already has the same approved image');
        q($this->db,'INSERT INTO configuration_versions (organization_id,config_key,config_json,effective_from,review_note) VALUES (?,?,?,?,?)',
            [$org,self::CONFIG_KEY,json_encode($next,JSON_THROW_ON_ERROR),
                (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'),$reason]);
        $id=(int)$this->db->lastInsertId();
        $audit=['slot'=>$slot,'action'=>$action,'previous_version'=>$prev['version_id'],
            'new_sha256'=>$next[$slot]['sha256']??null,'reason'=>$reason];
        q($this->db,'INSERT INTO audit_events (organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
            [$org,$this->user['id'],'TRAVEL_LETTERHEAD_UPDATED','TRAVEL_LETTERHEAD',$id,json_encode($audit,JSON_THROW_ON_ERROR)]);
        return ['version_id'=>$id,'organization_id'=>$org,'slot'=>$slot];
    }
    public static function validateImage(string $bytes): array {
        demand(strlen($bytes)>0 && strlen($bytes)<=self::MAX_BYTES,'Image must be 600 KB or smaller',413);
        $info=@getimagesizefromstring($bytes);
        demand(is_array($info),'File is not a readable image',422);
        $mime=$info['mime']??'';
        demand(in_array($mime,['image/png','image/jpeg'],true),'Use PNG or JPEG images; PDF and SVG are not accepted',422);
        $finfo=new finfo(FILEINFO_MIME_TYPE);
        demand($finfo->buffer($bytes)===$mime,'Image format could not be verified',422);
        $width=(int)($info[0]??0);$height=(int)($info[1]??0);
        demand($width>=400 && $width<=6000 && $height>=60 && $height<=2000 &&
            $width*$height<=12000000 && $width/$height>=2 && $width/$height<=18,
            'Upload a wide, print-ready strip (400–6000 px wide; header/footer landscape aspect ratio 2:1 to 18:1)',422);
        return ['sha256'=>hash('sha256',$bytes),'mime'=>$mime,'width'=>$width,
            'height'=>$height,'bytes'=>strlen($bytes)];
    }
    private static function privatePath(int $org,array $meta): string {
        $extension=$meta['mime']==='image/png'?'png':'jpg';
        return dirname(__DIR__).'/branding/org-'.$org.'/'.$meta['sha256'].'.'.$extension;
    }
    private static function writeImage(int $org,array $meta,string $bytes): void {
        $name=self::privatePath($org,$meta);$directory=dirname($name);
        if(!is_dir($directory))demand(@mkdir($directory,0750,true)||is_dir($directory),
            'Private letterhead storage cannot be created. Contact the technical administrator.',503);
        if(is_file($name)){
            demand(hash_file('sha256',$name)===$meta['sha256'],'Stored image integrity check failed',500);
            return;
        }
        $temporary=@tempnam($directory,'.letterhead-');
        demand(is_string($temporary),'Private image storage unavailable',503);
        try{
            demand(file_put_contents($temporary,$bytes,LOCK_EX)===strlen($bytes),'Could not save approved artwork',503);
            @chmod($temporary,0640);
            demand(@rename($temporary,$name)||is_file($name),'Could not activate uploaded artwork',503);
            demand(hash_file('sha256',$name)===$meta['sha256'],'Uploaded image verification failed',500);
        }finally{if(is_file($temporary))@unlink($temporary);}
    }
    /** Data always remains under private/branding; no supplied path is followed. */
    public function image(array $params): array {
        $org=$this->organization($params);$slot=self::slot((string)($params['slot']??''));
        $version=$params['version']??null;
        if($version===null||$version==='')$config=$this->latest($org);
        else{
            demand(ctype_digit((string)$version)&&(int)$version>0,'Invalid branding version',422);
            $row=one($this->db,'SELECT config_json FROM configuration_versions WHERE id=? AND organization_id=? AND config_key=?',[(int)$version,$org,self::CONFIG_KEY]);
            demand($row!==null,'Letterhead version not found',404);
            $saved=json_decode((string)$row['config_json'],true,64,JSON_THROW_ON_ERROR);
            $config=['header'=>self::cleanMeta($saved['header']??null),'footer'=>self::cleanMeta($saved['footer']??null)];
        }
        $meta=$config[$slot];demand(is_array($meta),'No artwork has been configured for this section',404);
        $path=self::privatePath($org,$meta);demand(is_file($path),'Approved artwork file is missing; contact your administrator',503);
        $bytes=file_get_contents($path);
        demand(is_string($bytes)&&hash_equals($meta['sha256'],hash('sha256',$bytes)),
            'Approved artwork failed integrity verification',503);
        return ['mime'=>$meta['mime'],'bytes'=>$bytes];
    }
    /** Used by print: same org was already authorized by TravelWorkflow::detail. */
    public function currentForPrint(int $org): array {
        $setting=$this->settings(['organization_id'=>$org]);
        return ['version_id'=>$setting['version_id'],'header'=>$setting['header'],'footer'=>$setting['footer']];
    }
}
