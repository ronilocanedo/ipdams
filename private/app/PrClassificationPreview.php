<?php
declare(strict_types=1);
/**
 * Read-only advisory for PR estimated UNIT price (not PR extended total).
 * Never alters a catalog category, Division UACS mapping, property record,
 * PR snapshot, inventory lot, or the statutory/accounting treatment.
 * Intended to be used after v0.2.120 registry-link functionality is verified.
 */
final class PrClassificationPreview {
    private const LV_CENTS = 500000;   // COA Circular 2022-004 §4.8 (<= P5,000)
    private const PPE_CENTS = 5000000; // COA Circular 2022-004 (>= P50,000)
    public function __construct(private PDO $db,private array $user,
        private Domain $domain,private CatalogRegistryLinks $links) {}

    private static function moneyLabel(int $cents): string {
        return number_format(intdiv($cents,100),0,'.',',').'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
    }
    /** Exposed for isolated decimal-boundary and negative-input tests. */
    public static function evaluate(array $source): array {
        $category=(string)($source['category']??'');
        $rawPrice=(string)($source['unit_price']??'');
        demand((bool)preg_match('/^\d{1,10}(\.\d{1,2})?$/D',$rawPrice),'Estimated unit price format is invalid',409);
        $price=moneyCents($rawPrice);
        demand($price>0 && $price<=100000000000,'Estimated unit price must be positive and within system limits',409);
        $asOf=(string)($source['as_of']??'');
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$asOf,new DateTimeZone('Asia/Manila'));
        demand($d!==false && $d->format('Y-m-d')===$asOf,'Choose a valid PR document date',409);
        $current=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        demand($asOf<=$current,'PR document date cannot be in the future',409);
        $linked=is_array($source['link']??null)?$source['link']:null;
        $mappingRows=is_array($source['mappings']??null)?$source['mappings']:[];
        $classRows=is_array($source['division_classes']??null)?$source['division_classes']:[];
        $itemId=(int)($source['item_id']??0);
        demand($itemId>0,'Choose a catalog item',409);
        $warnings=[];$costBand='NOT_APPLICABLE';$propertyCode='';$codeName='';
        if(in_array($category,['SEMI_EXPENDABLE','PPE'],true)){
            if($asOf<'2022-01-01'){
                // Circular 2022-004 must not be projected backwards as if it
                // had always governed the original PR transaction date.
                $costBand='HISTORICAL_POLICY_REVIEW';
                $warnings[]='This PR predates the 2022 capitalization guidance; verify the effective historical accounting rule manually.';
            } else {
            // Bracket is a cost-based guideline for equipment that qualifies
            // as tangible property. The actual recognition/classification
            // depends on the item nature and accountant's verified documents.
            $costBand=$price>=self::PPE_CENTS?'PPE_THRESHOLD':
                ($price>self::LV_CENTS?'HIGH_VALUED_SEMI_EXPENDABLE':'LOW_VALUED_SEMI_EXPENDABLE');
            if($category==='SEMI_EXPENDABLE' && $price>=self::PPE_CENTS)
                $warnings[]='Estimate meets or exceeds the PPE threshold; review item category and applicable account with the Division Accountant.';
            if($category==='PPE' && $price<self::PPE_CENTS)
                $warnings[]='Estimate is below the PPE capitalization threshold; review the category with the Division Accountant.';
            if($linked===null)
                $warnings[]='Item has no linked Division property type; choose an approved registry reference.';
            elseif(!empty($linked['review_required']))
                $warnings[]='The Division property type changed or was retired; recheck the recorded registry link.';
            elseif(($linked['mode']??'')!=='HV_LV')
                $warnings[]='Linked registry type is not an equipment HV/LV reference.';
            elseif($costBand==='PPE_THRESHOLD')
                $warnings[]='HV/LV reference codes must not be used as an automatically assigned PPE property number.';
            else {
                $codeName=$costBand==='LOW_VALUED_SEMI_EXPENDABLE'?'LV':'HV';
                $propertyCode=trim((string)($linked[strtolower($codeName).'_code']??''));
                if($propertyCode==='')$warnings[]='The selected Division property type has no '.$codeName.' reference code.';
            }
            } // End of 2022-onward cost guidance
        } elseif($category==='TEXTBOOK') {
            $costBand='TEXTBOOK_REVIEW';
            if($linked===null)$warnings[]='Textbook does not have a linked Division reference type.';
            elseif(!empty($linked['review_required']))$warnings[]='Textbook reference was revised; verify its Division registry link.';
            elseif(($linked['mode']??'')==='REFERENCE')$propertyCode=(string)($linked['reference_code']??'');
            else $warnings[]='Selected registry code does not represent a textbook reference.';
        }else {
            $costBand='NOT_APPLICABLE';
        }
        // Resolve effective mapping as of PR date; never fall forward to a
        // newer mapping or infer an actual UACS solely from the price band.
        $eligible=[];
        foreach($mappingRows as $m){
            if((int)($m['item_id']??0)===$itemId && is_string($m['effective_from']??null)
                && ($m['effective_from']??'')<=$asOf && preg_match('/^\d{4}-\d\d-\d\d$/',(string)$m['effective_from']))$eligible[]=$m;
        }
        usort($eligible,static fn($a,$b)=>strcmp($b['effective_from'],$a['effective_from']));
        $chosen=$eligible[0]??null;
        if(count($eligible)>1 && $eligible[0]['effective_from']===$eligible[1]['effective_from']){
            $warnings[]='Multiple effective item UACS mappings exist for this date; Division Accountant must resolve the conflict.';
            $chosen=null;
        }
        if($chosen===null)$warnings[]='No unambiguous Division item UACS mapping is effective on the PR date.';
        $uacs=(string)($chosen['uacs_code']??'');
        if($uacs!==''&&!preg_match('/^(502|104|106|108)[0-9]{7}$/D',$uacs)){
            $warnings[]='The configured item account is not an eligible debit UACS.';$uacs='';
        }
        if($uacs!==''){
            $prefix=substr($uacs,0,3);
            $allowed=match($category){'PPE'=>['106','108'],'SEMI_EXPENDABLE'=>['502','104','106','108'],
                'TEXTBOOK'=>['104','502'],'SUPPLY'=>['502'],'SERVICE'=>['502'],default=>[]};
            if(!in_array($prefix,$allowed,true)){
                $warnings[]='Mapped UACS is not consistent with the catalog category.';
            }
            if($category==='PPE'&&$price<self::PPE_CENTS&&$prefix==='106')
                $warnings[]='Capital-asset UACS and estimated unit price should be reviewed before procurement is finalized.';
            if($category==='SEMI_EXPENDABLE'&&$price>=self::PPE_CENTS&&$prefix==='104')
                $warnings[]='Semi-expendable inventory mapping and PPE-size estimated cost require review.';
            if($category==='SEMI_EXPENDABLE'&&$price<self::PPE_CENTS&&$prefix==='106')
                $warnings[]='PPE UACS on a sub-threshold semi-expendable item should be reviewed by the Division Accountant.';
        }
        $mapClass=(string)($chosen['classification_code']??'');
        if($mapClass!==''){
            $found=null;foreach($classRows as $c)if(($c['code']??'')===$mapClass){$found=$c;break;}
            if($found===null||empty($found['active'])||($found['applies_to']??'')!==$category)
                $warnings[]='Recorded Division classification is inactive, missing, or for a different catalog category.';
        }
        return [
            'stage'=>'PR_PRELIMINARY_DOCUMENTATION_ONLY',
            'item_id'=>$itemId,'as_of'=>$asOf,'catalog_category'=>$category,
            'estimated_unit_price'=>self::moneyLabel($price),
            'cost_band'=>$costBand,'division_property_type'=>$linked?['id'=>(string)($linked['registry_id']??''),
                'description'=>(string)($linked['description']??''),'reference_code'=>$propertyCode,
                'reference_kind'=>$codeName]:null,
            'uacs_code'=>$uacs,'uacs_effective_from'=>(string)($chosen['effective_from']??''),
            'uacs_source'=>(string)($chosen['source']??'DIVISION_MAPPING'),
            'mapping_classification'=>$mapClass,
            'review_required'=>count($warnings)>0,'warnings'=>array_values(array_unique($warnings)),
            'disclaimer'=>'Preliminary PR guidance only. Use actual accepted unit cost, eligibility, and wet-signed documents for later property and accounting review. Nothing is automatically posted or approved.'
        ];
    }
    public function preview(array $p): array {
        $org=idOf($p,'organization_id');$itemId=idOf($p,'item_id');
        // Links service checks workspace scope and inherits Division references.
        $links=$this->links->links(['organization_id'=>$org]);
        $division=(int)$links['division_id'];
        $item=one($this->db,'SELECT id,name,category,active,owner_organization_id FROM item_catalog WHERE id=?',[$itemId]);
        demand($item!==null && (int)($item['active']??1)===1,'Selected catalog item unavailable',404);
        $owner=$item['owner_organization_id'];
        demand($owner===null || (int)$owner===$org || (int)$owner===$division,
            'Selected catalog item is outside your organization scope',403);
        $m=$this->domain->divisionItemUacsSettings(['organization_id'=>$org]);
        $classes=$this->domain->listDivisionClassifications(['organization_id'=>$org]);
        $asOf=(string)($p['as_of']??'');$unit=(string)($p['unit_price']??'');
        $result=self::evaluate(['item_id'=>$itemId,'category'=>(string)$item['category'],
            'unit_price'=>$unit,'as_of'=>$asOf,
            'link'=>$links['links'][(string)$itemId]??null,
            'mappings'=>$m['mappings']??[], 'division_classes'=>$classes['categories']??[]]);
        $registry=$this->domain->listDivisionPropertyRegistry(['organization_id'=>$org]);
        // The older property settings page has a configurable HV threshold.
        // If it differs from COA's reference boundary, explain the conflict
        // instead of pretending the legacy value is the statutory cut-off.
        $thresholds=$registry['threshold_rules']??[];
        if(in_array((string)$item['category'],['PPE','SEMI_EXPENDABLE'],true)){
            $effective=null;$latest='';
            foreach($thresholds as $rule){
                $date=(string)($rule['effective_from']??'');
                if($date<=$asOf && $date>=$latest){$latest=$date;$effective=(int)($rule['cents']??0);}
            }
            if($effective!==null && $effective!==self::LV_CENTS){
                $result['warnings'][]='Configured Division HV/LV threshold differs from COA reference (P5,000.00); policy review required.';
                $result['review_required']=true;
            }
        }
        if($result['uacs_code']!==''){
            $account=one($this->db,'SELECT code,active FROM uacs_accounts WHERE code=?',[$result['uacs_code']]);
            if($account===null || (int)($account['active']??0)!==1){
                $result['warnings'][]='The Division-mapped UACS is not currently enabled; refer to the Division Accountant.';
                $result['review_required']=true;
            }
        }
        $result['catalog_name']=(string)$item['name'];
        $result['division_id']=$division;
        $result['registry_link_version_id']=((int)$owner===$org && $org!==$division)
            ?(int)$links['local_version_id']:(int)$links['division_version_id'];
        $result['uacs_mapping_version_id']=(int)($m['version_id']??0);
        return $result;
    }
}
