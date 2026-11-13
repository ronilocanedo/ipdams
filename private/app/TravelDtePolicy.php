<?php
declare(strict_types=1);
require_once __DIR__.'/TravelProvinceDirectory.php';
/** Non-posting DepEd local travel DTE REFERENCE ceilings.
 * EO 77 (2019); DepEd DO 043/046 (2022). The place, permanent station,
 * distance and benefit evidence require human verification. No liability,
 * approved claim or check can result from these computations.
 */
final class TravelDtePolicy {
    public const REGIONS = [
        'I'=>['I',1500], 'II'=>['I',1500], 'III'=>['I',1500],
        'V'=>['I',1500], 'VIII'=>['I',1500], 'IX'=>['I',1500],
        'XII'=>['I',1500], 'XIII'=>['I',1500], 'BARMM'=>['I',1500],
        'VI'=>['II',1800], 'VII'=>['II',1800], 'X'=>['II',1800],
        'XI'=>['II',1800], 'CAR'=>['II',1800], 'NIR'=>['II',1800],
        'IV-A'=>['III',2200], 'IV-B'=>['III',2200], 'NCR'=>['III',2200],
    ];
    private static function string(mixed $raw, int $max, string $name): string {
        demand(is_string($raw),'Invalid '.$name,422);
        $value=trim($raw);
        demand(mbSafeLen($value)<=$max,'Invalid '.$name,422);
        return $value;
    }
    private static function date(mixed $raw,string $from,string $to): string {
        demand(is_string($raw),'Invalid DTE date',422);
        $v=trim($raw);$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
        demand($d!==false && $d->format('Y-m-d')===$v && $v>=$from && $v<=$to,'DTE date outside Travel Authority',422);
        return $v;
    }
    private static function time(mixed $raw,string $name): string {
        $v=self::string($raw,5,$name);
        demand($v===''||preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$v)===1,'Invalid time: '.$name,422);
        return $v;
    }
    public static function normalize(mixed $raw,string $from,string $to,bool $singleExpenseEntry=false): ?array {
        if($raw===null || $raw===[])return null; // historical plans have no DTE worksheet.
        demand(is_array($raw),'Invalid DTE worksheet',422);
        $place=self::string($raw['city_municipality']??'',160,'DTE destination locality');
        $province=self::string($raw['province']??'',120,'DTE destination province');
        $region=self::string($raw['region']??'',10,'DTE region');
        $expectedRegion=TravelProvinceDirectory::region($province);
        demand($province!=='' && $expectedRegion!==null,'Select an official destination province or Metro Manila / independent city',422);
        demand($region===$expectedRegion,'Destination region must match the government geographic directory',422);
        demand(isset(self::REGIONS[$region]),'No published regional reference rate for selected destination',422);
        $station=self::string($raw['permanent_station']??'',180,'permanent official station');
        demand($station!=='','Record the permanent official station',422);
        $distance=self::string($raw['distance_class']??'UNVERIFIED',32,'50-kilometer classification');
        demand(in_array($distance,['UNVERIFIED','BEYOND_50KM','WITHIN_50KM_OVERNIGHT','WITHIN_50KM_COMMUTE'],true),'Invalid verified distance classification',422);
        $distanceRef=self::string($raw['distance_reference']??'',200,'distance/route reference');
        $alternatePolicyRef=self::string($raw['alternate_policy_reference']??'',260,'alternative policy reference');
        $alternateEffective=self::string($raw['alternate_policy_effective_date']??'',10,'alternative policy effective date');
        if($alternateEffective!==''){
            $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$alternateEffective);
            demand($parsed!==false && $parsed->format('Y-m-d')===$alternateEffective,'Invalid alternative policy effective date',422);
        }
        // Pre-travel estimates must not require an invented route/distance citation.
        // Keep any historical reference but defer distance evidence to eligibility review.
        $distanceEvidencePending=$distance!=='UNVERIFIED' && $distanceRef==='';
        $days=$raw['days']??[];
        demand(is_array($days)&&array_is_list($days)&&count($days)<=90,'DTE worksheet allows up to 90 dated entries',422);
        [$cluster,$daily]=self::REGIONS[$region];$seen=[];$normalized=[];$totalCeiling=0;$totalProposed=0;$manualChanges=0;$aboveCeiling=0;$unverified=0;
        foreach($days as $record){
            demand(is_array($record),'Invalid DTE day',422);
            $date=self::date($record['date']??'', $from,$to);
            demand(!isset($seen[$date]),'Duplicate DTE date',422);$seen[$date]=true;
            $kind=self::string($record['day_type']??'REVIEW_REQUIRED',28,'DTE day type');
            demand(in_array($kind,['FULL_DAY','RETURN_DAY','DEPARTURE_DAY','RETURN_TRANSIT','REVIEW_REQUIRED','NOT_CLAIMED'],true),'Invalid DTE day type',422);
            if($kind==='RETURN_DAY')demand($date>$from,'A 50% return day must be different from initial travel day',422);
            $mealEvidence=$record['meals']??[];
            demand(is_array($mealEvidence)&&array_is_list($mealEvidence)&&count($mealEvidence)<=3,'Up to three meal entries per day',422);
            $meals=[];$seenMeals=[];$manualReview=false;
            foreach($mealEvidence as $meal){
                demand(is_array($meal),'Invalid organizer meal detail',422);
                $name=self::string($meal['meal']??'',12,'meal name');
                demand(in_array($name,['BREAKFAST','LUNCH','DINNER'],true),'Invalid meal name',422);
                demand(!isset($seenMeals[$name]),'Duplicate scheduled meal',422);$seenMeals[$name]=true;
                $status=self::string($meal['status']??'UNKNOWN',32,'meal availability');
                demand(in_array($status,['UNKNOWN','NOT_PROVIDED','PROVIDED_AVAILED','PROVIDED_NOT_AVAILED'],true),'Invalid meal availability',422);
                $time=self::time($meal['scheduled_time']??'','organizer meal time');
                $reason=self::string($meal['reason']??'',400,'meal exception reason');
                $approval=self::string($meal['approval_reference']??'',160,'authorized departure or official conflict reference');
                $travelProof=self::string($meal['travel_reference']??'',160,'ticket/transport reference');
                if($status==='PROVIDED_NOT_AVAILED'){
                    demand($reason!==''&&$approval!==''&&$travelProof!==''&&$time!=='',
                        'Unavailed organizer meal requires scheduled time, justification, authorized movement and travel evidence',422);
                    $manualReview=true;
                }
                if($status==='PROVIDED_AVAILED')$manualReview=true;
                $meals[]=['meal'=>$name,'status'=>$status,'scheduled_time'=>$time,'reason'=>$reason,
                    'approval_reference'=>$approval,'travel_reference'=>$travelProof,
                    'requires_independent_eligibility_review'=>$status==='PROVIDED_NOT_AVAILED'||$status==='PROVIDED_AVAILED'];
            }
            $depart=self::time($record['return_departure_time']??'','return departure');
            $arrive=self::time($record['home_arrival_time']??'','arrival at home');
            $returnRef=self::string($record['return_ticket_reference']??'',180,'return ticket reference');
            // Treat the DTE amount as a ceiling, not an eligibility/payment finding.
            $computable=$distance==='BEYOND_50KM' && $region!=='NIR' && in_array($kind,['FULL_DAY','RETURN_DAY'],true);
            $lodging=$computable && $kind==='FULL_DAY'?intdiv($daily*50,100):0;
            $meal=$computable?intdiv($daily*30,100):0;
            $incidental=$computable?intdiv($daily*20,100):0;
            if(!$computable && $kind!=='NOT_CLAIMED')$unverified++;
            $tentative=$lodging+$meal+$incidental;$ceilingCents=$tentative*100;$totalCeiling+=$ceilingCents;
            // A proposed amount is documentary planning input, not an allowed DTE or
            // a change to the statutory reference rate. Never trust a client ceiling.
            $entered=$record['proposed_amount']??null;
            // Under the one-window itinerary, an empty DTE field is NO claim.
            // Older worksheet-only snapshots retain their legacy default behavior.
            if($entered===null || $entered==='')$proposedCents=$singleExpenseEntry?0:$ceilingCents;
            else{
                demand(is_string($entered)||is_int($entered),'Invalid proposed DTE amount',422);
                $v=trim((string)$entered);
                demand(preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D',$v)===1,'Proposed DTE must be an exact nonnegative peso amount',422);
                $proposedCents=moneyCents($v);
                demand($proposedCents<=100000000,'Proposed DTE may not exceed ₱1,000,000',422);
            }
            $reason=self::string($record['override_reason']??'',500,'DTE manual adjustment justification');
            // No DTE entered on this date cannot be a manual DTE adjustment.
            // Do not demand a reason when the only new entry was a ferry/bus fare.
            // Documentary entry is NOT an eligibility decision. An encoder may record
            // the employee's actual proposed DTE above (or below) the reference without
            // inventing a new official issuance or forcing a reason just to save.
            // Only compare against a computed entitlement when the 50km/day inputs
            // support that calculation: an unverified zero is NOT a zero-peso ceiling.
            $changed=$computable && $proposedCents!==$ceilingCents && !($singleExpenseEntry && $proposedCents===0);
            if($changed)$manualChanges++;
            $publicCeiling=$region==='NIR'?null:($kind==='FULL_DAY'?$daily*100:
                ($kind==='RETURN_DAY'?intdiv($daily*100,2):null));
            $abovePublished=$publicCeiling!==null && $proposedCents>$publicCeiling;
            $higher=($computable && $proposedCents>$ceilingCents)||$abovePublished;
            if($higher)$aboveCeiling++;
            $referenceCents=$computable?$ceilingCents:$publicCeiling;
            $excessCents=$referenceCents===null?null:max(0,$proposedCents-$referenceCents);
            // A citation is optional documentary evidence, not a permit to enter a claim.
            // Missing, future-effective or NIR policy references remain review questions.
            $policyNeedsReview=$higher||($region==='NIR' && $proposedCents>0)||
                ($alternateEffective!=='' && $alternateEffective>$date);
            $totalProposed+=$proposedCents;
            $normalized[]=['date'=>$date,'day_type'=>$kind,'meals'=>$meals,
                'return_departure_time'=>$depart,'home_arrival_time'=>$arrive,'return_ticket_reference'=>$returnRef,
                'proposed_amount'=>moneyString($proposedCents), 'override_reason'=>$reason,
                'manually_adjusted'=>$changed,'exceeds_reference_ceiling'=>$higher,
                'published_reference_ceiling'=>$publicCeiling===null?null:moneyString($publicCeiling),
                'reference_comparable'=>$referenceCents!==null,
                'excess_over_reference'=>$excessCents===null?null:moneyString($excessCents),
                'policy_review_required'=>$policyNeedsReview,
                'ceiling'=>['lodging'=>moneyString($lodging*100),'meals'=>moneyString($meal*100),
                    'incidental'=>moneyString($incidental*100),'maximum'=>moneyString($tentative*100)],
                'requires_independent_review'=>$manualReview||!$computable||$changed||$higher||$policyNeedsReview||$distanceEvidencePending,
                'eligibility_approved'=>false];
        }
        usort($normalized,static fn($a,$b)=>strcmp($a['date'],$b['date']));
        return ['policy'=>'EO77-2019_DEPED-043-046-2022_PSGC-Q3-2026','city_municipality'=>$place,'province'=>$province,
            'region'=>$region,'cluster'=>$cluster,'maximum_daily_rate'=>moneyString($daily*100),
            'permanent_station'=>$station,'distance_class'=>$distance,'distance_reference'=>$distanceRef,
            'distance_evidence_pending'=>$distanceEvidencePending,
            'alternate_policy_reference'=>$alternatePolicyRef,'alternate_policy_effective_date'=>$alternateEffective,
            'location_requires_authorized_verification'=>true,'regional_policy_needs_review'=>$region==='NIR','days'=>$normalized,
            'provisional_ceiling'=>moneyString($totalCeiling),
            'proposed_total'=>moneyString($totalProposed),'manually_adjusted_days'=>$manualChanges,
            'above_reference_ceiling_days'=>$aboveCeiling,
            'days_pending_classification'=>$unverified,
            'receipt_requirement'=>'DTE_EXEMPT','is_reimbursement_approval'=>false,
            'notice'=>'DTE is a documented CLAIM, not an approved allowance. Amounts above the applicable published ceiling are saved and visibly flagged for accounting review, not blocked or automatically allowed. Verify region, station, 50km radius, day category, organizer benefits and exceptions. Unverified/NIR/transit days need an independent policy assessment. No payment, DV or entitlement is approved here.'];
    }
}
