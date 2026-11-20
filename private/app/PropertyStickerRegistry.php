<?php
declare(strict_types=1);
/**
 * Property sticker read-only Division registry code lookup (v0.2.410).
 * The Division registry is an item TYPE, not a replacement for the asset's
 * unique property number. Use only the last saved, reviewed catalog link.
 * No records, accounting classification, or official numbering are changed.
 */
final class PropertyStickerRegistry {
    private const LINK_KEY='CATALOG_PROPERTY_LINKS';
    private const VALUATION_KEY='DIVISION_PROPERTY_VALUATION';
    private const DEFAULT_THRESHOLD_CENTS=500000;

    /** Evaluate the Division's dated HV/LV rule against the actual asset acquisition date. */
    public static function tier(?string $acquiredOn,?string $amount,array $rules): ?string {
        if($acquiredOn===null || preg_match('/^\d{4}-\d\d-\d\d$/D',$acquiredOn)!==1 ||
           $amount===null || trim($amount)==='')return null;
        // moneyCents comes from the existing IPDAMS bootstrap and uses exact cents.
        $cents=moneyCents($amount);
        if($cents<0)return null;
        $threshold=self::DEFAULT_THRESHOLD_CENTS;$last='';
        foreach($rules as $rule){
            if(!is_array($rule))continue;
            $effective=(string)($rule['effective_from']??'');
            if($effective!=='' && $effective<=$acquiredOn && $effective>=$last &&
               isset($rule['cents']) && is_numeric($rule['cents']) && (int)$rule['cents']>0){
                $last=$effective;$threshold=(int)$rule['cents'];
            }
        }
        return $cents>=$threshold?'HV':'LV';
    }
    /** Source is the reviewed Division registry snapshot linked to this catalog item. */
    public static function code(array $link,int $division,string $classification,?string $acquiredOn,?string $amount,array $rules): string {
        if((int)($link['division_id']??0)!==$division ||
           (string)($link['classification']??'')!==$classification ||
           (string)($link['registry_id']??'')==='')return '';
        $snap=$link['registry_snapshot']??null;
        if(!is_array($snap))return '';
        $mode=(string)($snap['mode']??'');
        if($mode==='REFERENCE')return $classification==='TEXTBOOK'?trim((string)($snap['reference_code']??'')):'';
        if($mode!=='HV_LV'||!in_array($classification,['PPE','SEMI_EXPENDABLE'],true))return '';
        $tier=self::tier($acquiredOn,$amount,$rules);
        if($tier===null)return '';
        return trim((string)($snap[$tier==='HV'?'hv_code':'lv_code']??''));
    }
    /** Append registry code to 50-or-fewer sticker rows without per-row DB queries. */
    public static function addCodes(PDO $db,array $org,array &$rows): void {
        $orgId=(int)($org['id']??0);
        $division=($org['kind']??'')==='DIVISION'?$orgId:(int)($org['parent_division_id']??0);
        foreach($rows as &$row)$row['division_registry_code']='';
        unset($row);
        if($division<=0||!$rows)return;
        $owners=[$division];if($orgId!==$division)$owners[]=$orgId;
        $configs=[];
        foreach($owners as $owner){
            $saved=one($db,'SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',[$owner,self::LINK_KEY]);
            if($saved===null){$configs[$owner]=[];continue;}
            $json=json_decode((string)$saved['config_json'],true,512,JSON_THROW_ON_ERROR);
            demand(is_array($json)&&isset($json['links'])&&is_array($json['links']),
                'Division catalog registry linkage needs review',409);
            $configs[$owner]=$json['links'];
        }
        $valuation=one($db,'SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',[$division,self::VALUATION_KEY]);
        $rules=[];
        if($valuation!==null){
            $json=json_decode((string)$valuation['config_json'],true,512,JSON_THROW_ON_ERROR);
            demand(is_array($json)&&isset($json['thresholds'])&&is_array($json['thresholds']),
                'Division property valuation settings need review',409);
            $rules=$json['thresholds'];
        }
        foreach($rows as &$row){
            // Division-owned shared catalog items are linked in the Division config;
            // school-owned items are linked in the school's own config.
            $owner=$row['catalog_owner']===null?$division:(int)$row['catalog_owner'];
            if(!in_array($owner,$owners,true))continue;
            $link=$configs[$owner][(string)$row['item_id']]??null;
            if(!is_array($link))continue;
            $row['division_registry_code']=self::code($link,$division,(string)$row['catalog_category'],
                isset($row['acquired_on'])?(string)$row['acquired_on']:null,
                isset($row['acquisition_cost'])?(string)$row['acquisition_cost']:null,$rules);
        }
        unset($row);
    }
}
