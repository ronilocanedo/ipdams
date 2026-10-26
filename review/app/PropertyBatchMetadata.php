<?php
declare(strict_types=1);
/** Conservative validated descriptive attributes for property batches and individual units.
 * Stored per asset in assets.property_profile, with one immutable batch key.
 * Not a custody-change or financial posting workflow. */
final class PropertyBatchMetadata {
    public static function text(mixed $raw, string $label, int $max=120): string {
        demand(is_string($raw)||$raw===null, $label.' must be text',422);
        $v=trim((string)$raw);
        demand(mbSafeLen($v)<=$max,$label.' is too long',422);
        demand(!preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$v),$label.' contains invalid characters',422);
        return $v;
    }
    public static function shared(array $data,string $origin): array {
        demand(in_array($origin,['HISTORICAL_OPENING','VERIFIED_IAR'],true),'Invalid property origin',422);
        demand(!array_diff(array_keys($data),['brand','model','acquisition_type','useful_life_years','historical_po','historical_iar','historical_dv','evidence_note']),
            'Unknown shared property attribute',422);
        $type=self::text($data['acquisition_type']??($origin==='VERIFIED_IAR'?'PURCHASE':'UNKNOWN'),'Acquisition type',40);
        demand(in_array($type,['PURCHASE','DONATION','TRANSFER','OTHER','UNKNOWN'],true),'Unsupported acquisition type',422);
        $years=$data['useful_life_years']??null;
        if($years===''||$years===null)$years=null;
        else demand((is_int($years)||is_string($years))&&preg_match('/^[1-9][0-9]?$/D',(string)$years)===1 && (int)$years<=99,'Invalid useful life',422);
        $result=['origin'=>$origin,'brand'=>self::text($data['brand']??'','Brand'),
            'model'=>self::text($data['model']??'','Model'), 'acquisition_type'=>$type,
            'useful_life_years'=>$years===null?null:(int)$years,
            'evidence_note'=>self::text($data['evidence_note']??'','Documentary source / explanation',500)];
        foreach(['historical_po','historical_iar','historical_dv'] as $key){
            $v=self::text($data[$key]??'',$key,100);
            demand($origin==='HISTORICAL_OPENING'||$v==='','Official linked IAR references must be resolved by IPDAMS, not typed',422);
            $result[$key]=$v;
        }
        return $result;
    }
    public static function unit(array $data): array {
        demand(!array_diff(array_keys($data),['serial_number','location','condition_note','legacy_property_number','reported_custodian']),
            'Unknown individual property attribute',422);
        return ['serial_number'=>self::text($data['serial_number']??'','Serial number',120),
            'location'=>self::text($data['location']??'','Physical location',180),
            'condition_note'=>self::text($data['condition_note']??'','Physical condition',255),
            'legacy_property_number'=>self::text($data['legacy_property_number']??'','Legacy property number',100),
            // Historical person reference does NOT digitally transfer accountability.
            'reported_custodian'=>self::text($data['reported_custodian']??'','Historical custodian reference',160)];
    }
    public static function units(mixed $raw,int $count): array {
        demand($count>=1&&$count<=100,'Register between 1 and 100 physical property units',422);
        if($raw===null)$raw=array_fill(0,$count,[]);
        demand(is_array($raw)&&array_is_list($raw)&&count($raw)===$count,
            'Individual property detail rows must match unit quantity exactly',422);
        $rows=[];$seen=[];
        foreach($raw as $i=>$row){
            demand(is_array($row),'Each physical unit must contain individual fields',422);
            $unit=self::unit($row);
            $serial=strtolower($unit['serial_number']);
            demand($serial===''||!isset($seen[$serial]),'Duplicate serial number in this batch (row '.($i+1).')',422);
            if($serial!=='')$seen[$serial]=true;
            $rows[]=$unit;
        }
        return $rows;
    }
    public static function profile(array $shared,array $unit): string {
        return json_encode(['shared'=>$shared,'unit'=>[
            'legacy_property_number'=>$unit['legacy_property_number'],
            'reported_custodian'=>$unit['reported_custodian']]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
    public static function decode(?string $profile): array {
        if(!$profile)return ['shared'=>[],'unit'=>[]];
        $decoded=json_decode($profile,true);
        demand(is_array($decoded)&&is_array($decoded['shared']??null)&&is_array($decoded['unit']??null),
            'Stored registration metadata requires technical review',409);
        return $decoded;
    }
}
