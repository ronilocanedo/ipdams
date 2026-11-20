<?php
declare(strict_types=1);
/** v0.2.409 — signed property QR links, public minimal verification, no accounting writes. */
final class PropertyQrVerification {
    private const KEY_BYTES=32;
    private static ?string $cachedKey=null;
    private static function directory(): string {
        return dirname(__DIR__).'/storage/private/property-stickers';
    }
    /** Keep this original key with website backups: replacing it invalidates printed QRs. */
    private static function key(bool $mayCreate): string {
        if(self::$cachedKey!==null)return self::$cachedKey;
        $dir=self::directory();
        if($mayCreate && !is_dir($dir)) {
            demand(!is_link($dir),'Property QR key storage unsafe',503);
            if(!@mkdir($dir,0700,true) && !is_dir($dir))throw new DomainError('Property QR key storage unavailable',503);
        }
        demand(is_dir($dir) && !is_link($dir),'Property QR verification storage unavailable',503);
        $file=$dir.'/qr-signing-v1.key';
        demand(!is_link($file),'Property QR key integrity check failed',503);
        if($mayCreate) {
            $lockPath=$dir.'/.qr-signing-v1.lock';
            demand(!is_link($lockPath),'Property QR key lock unsafe',503);
            $lock=@fopen($lockPath,'c');
            demand($lock!==false,'Property QR key lock unavailable',503);
            try {
                demand(flock($lock,LOCK_EX),'Property QR key lock unavailable',503);
                if(!is_file($file)) {
                    $secret=random_bytes(self::KEY_BYTES);
                    $staged=$dir.'/.qr-key-'.bin2hex(random_bytes(12));
                    $fh=@fopen($staged,'x');
                    demand($fh!==false,'Cannot stage QR key',503);
                    try {
                        demand(fwrite($fh,$secret)===self::KEY_BYTES,'Cannot write QR key',503);
                        demand(fflush($fh),'Cannot persist QR key',503);
                        @chmod($staged,0600);
                    }finally{fclose($fh);}
                    if(!@rename($staged,$file)) {
                        @unlink($staged);
                        throw new DomainError('Cannot publish QR key',503);
                    }
                }
            }finally{flock($lock,LOCK_UN);fclose($lock);}
        }
        demand(is_file($file) && !is_link($file),'Property QR signing key unavailable; restore its independent backup',503);
        $key=@file_get_contents($file);
        demand(is_string($key) && strlen($key)===self::KEY_BYTES,'Property QR signing key integrity check failed',503);
        self::$cachedKey=$key;
        return $key;
    }
    /** Base64url 128-bit HMAC. No user-supplied host header is used. */
    public static function signature(int $assetId,string $key): string {
        demand($assetId>0,'Invalid property identifier',404);
        demand(strlen($key)===self::KEY_BYTES,'Invalid property QR secret',500);
        $mac=hash_hmac('sha256',"IPDAMS-PROPERTY-QR-V1\0".$assetId,$key,true);
        return rtrim(strtr(base64_encode(substr($mac,0,16)),'+/','-_'),'=');
    }
    public static function validSignature(int $assetId,string $supplied,string $key): bool {
        return $assetId>0 && preg_match('/^[A-Za-z0-9_-]{22}$/D',$supplied)===1
            && hash_equals(self::signature($assetId,$key),$supplied);
    }
    private static function baseUrl(): string {
        $url=rtrim(cfg('IPDAMS_PUBLIC_BASE_URL','https://ipdams.depedtagb.online'),'/');
        $p=parse_url($url);
        demand(is_array($p) && isset($p['host'],$p['scheme']) &&
            (strtolower($p['scheme'])==='https' || (strtolower($p['scheme'])==='http' && in_array(strtolower($p['host']),['localhost','127.0.0.1'],true))) &&
            !isset($p['user']) && !isset($p['pass']) && !isset($p['query']) && !isset($p['fragment']),
            'Invalid property QR public URL configuration',500);
        return $url;
    }
    public static function url(int $assetId): string {
        return self::baseUrl().'/api/property-verify.php?p='.$assetId.'&s='.self::signature($assetId,self::key(true));
    }
    /** Public operation: no currentUser(), no property history, personal data or financial fields. */
    public static function verify(PDO $db,string $rawId,string $signature): ?array {
        if(!preg_match('/^[1-9][0-9]{0,17}$/D',$rawId))return null;
        $id=(int)$rawId;
        if(!self::validSignature($id,$signature,self::key(false)))return null;
        $row=one($db,"SELECT a.id,a.property_tag,a.acquired_on,a.status,a.property_profile,
               o.name AS office_name,o.kind AS office_kind,i.name AS item_name
             FROM assets a
             JOIN organizations o ON o.id=a.organization_id AND o.active=1
             JOIN item_catalog i ON i.id=a.item_id
             WHERE a.id=? LIMIT 1",[$id]);
        if($row===null)return null;
        $profile=json_decode((string)($row['property_profile']??''),true);
        $shared=is_array($profile) ? ($profile['shared']??[]) : [];
        $shared=is_array($shared)?$shared:[];
        $brand=(string)($shared['brand']??($profile['brand']??''));
        $model=(string)($shared['model']??($profile['model']??''));
        return [
            'property_number'=>(string)$row['property_tag'],
            'office'=>(string)$row['office_name'],
            'office_kind'=>(string)$row['office_kind'],
            'description'=>(string)$row['item_name'],
            'date_acquired'=>(string)($row['acquired_on']??''),
            'brand'=>mbSafeLen($brand)<=120?$brand:substr($brand,0,120),
            'model'=>mbSafeLen($model)<=120?$model:substr($model,0,120),
            'record_current'=>strtoupper((string)$row['status'])!=='TRANSFERRED',
        ];
    }
}
