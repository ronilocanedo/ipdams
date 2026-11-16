<?php
declare(strict_types=1);
/**
 * Appendix 45 documentary itinerary transcription. No payment eligibility,
 * fund charge, DV, journal, or official receipt is asserted by this class.
 * A receipt status is recorded per positive monetary COMPONENT of each leg.
 */
final class TravelItineraryLines {
    private const COMPONENTS=[
        'fare'=>['category'=>'TRANSPORT','label'=>'Fare / transportation'],
        'per_diem'=>['category'=>'DAILY_EXPENSE','label'=>'Per diem / daily expense'],
        'other'=>['category'=>'OTHER_TRAVEL','label'=>'Other travel expense']
    ];
    private const PROOF=['RECEIPT','NO_RECEIPT','ALTERNATIVE','DTE_EXEMPT'];
    private static function field(array $data,string $key,int $max,bool $required=false): string {
        $value=$data[$key]??'';
        demand(is_string($value),'Invalid itinerary '.$key,422);
        $text=trim($value);
        demand(mbSafeLen($text)<=$max && (!$required || $text!==''),'Enter a valid itinerary '.$key,422);
        return $text;
    }
    private static function date(string $value,string $from,string $to): string {
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        demand($parsed!==false && $parsed->format('Y-m-d')===$value && $value>=$from && $value<=$to,
            'Each itinerary date must be within the actual travel dates',422);
        return $value;
    }
    private static function amount(array $line,string $key): int {
        $raw=$line[$key]??'';
        demand(is_string($raw)||is_int($raw),'Invalid itinerary '.$key.' amount',422);
        $value=trim((string)$raw);if($value==='')$value='0';
        demand(preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D',$value)===1,'Invalid itinerary '.$key.' amount',422);
        $cents=moneyCents($value);
        demand($cents>=0 && $cents<=100000000,'Each itinerary amount must be between zero and ₱1,000,000',422);
        return $cents;
    }
    public static function normalize(mixed $raw,string $from,string $to): array {
        demand(is_array($raw),'Provide Appendix 45 itinerary information',422);
        $reference=self::field($raw,'reference',80);
        $notes=self::field($raw,'source_note',500);
        $rows=$raw['rows']??null;
        demand(is_array($rows)&&array_is_list($rows)&&count($rows)>=1&&count($rows)<=60,
            'Enter 1 to 60 itinerary travel legs',422);
        $clean=[];$sums=['fare'=>0,'per_diem'=>0,'other'=>0];$unreceipted=0;
        foreach($rows as $i=>$row){
            demand(is_array($row),'Invalid itinerary row',422);
            $date=self::date(self::field($row,'date',10,true),$from,$to);
            $places=self::field($row,'places',240,true);
            $departure=self::field($row,'departure',5);$arrival=self::field($row,'arrival',5);
            foreach(['departure'=>$departure,'arrival'=>$arrival] as $field=>$time){
                demand($time==='' || preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$time)===1,
                    'Use a valid 24-hour '.$field.' time in itinerary row '.($i+1),422);
            }
            $means=self::field($row,'means',120);
            $receiptNote=self::field($row,'receipt_note',260);
            $result=['date'=>$date,'places'=>$places,'departure'=>$departure,'arrival'=>$arrival,
                'means'=>$means,'receipt_note'=>$receiptNote];
            $rowTotal=0;
            foreach(self::COMPONENTS as $key=>$info){
                $value=self::amount($row,$key);
                $status=self::field($row,$key.'_proof',16);
                $proofReference=self::field($row,$key.'_reference',160);
                // Optional actual expense timestamp is independent of leg times.
                // Never infer a fare time from departure/arrival or receipt upload.
                $eventTime=self::field($row,$key.'_time',5);
                demand($eventTime===''||preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$eventTime)===1,
                    'Use a valid actual expense time for '.$info['label'],422);
                $purpose=self::field($row,$key.'_purpose',220);
                if($value>0){
                    demand(in_array($status,self::PROOF,true),
                        'Select receipt status for '.$info['label'].' in itinerary row '.($i+1),422);
                    demand($status!=='DTE_EXEMPT'||$key==='per_diem','DTE receipt exemption may only be used for daily travel expenses',422);
                    if(in_array($status,['NO_RECEIPT','ALTERNATIVE'],true))$unreceipted+=$value;
                }else{
                    demand($status==='' || $status==='NONE','A zero expense must not be marked as receipted',422);
                    demand($proofReference===''&&$eventTime===''&&$purpose==='','A zero expense cannot carry component evidence or time',422);
                    $status='NONE';
                }
                $result[$key]=moneyString($value);
                $result[$key.'_proof']=$status;
                $result[$key.'_reference']=$proofReference;
                $result[$key.'_time']=$eventTime;$result[$key.'_purpose']=$purpose;
                $rowTotal+=$value;$sums[$key]+=$value;
            }
            // A real travel leg may carry no reimbursable expense; retain it in Appendix 45.
            $result['total']=moneyString($rowTotal);
            $clean[]=$result;
        }
        $sum=array_sum($sums);
        demand($sum>0 && $sum<=200000000,'Review itinerary amount; total must not exceed ₱2,000,000',422);
        $claimLines=[];
        foreach(self::COMPONENTS as $key=>$info){
            if($sums[$key]===0)continue;
            $claimLines[]=['category'=>$info['category'],
                'category_label'=>$info['label'],
                'description'=>'Appendix 45 itinerary: '.$info['label'],
                'amount'=>moneyString($sums[$key])];
        }
        return ['itinerary'=>['format'=>'APPENDIX_45','reference'=>$reference,'source_note'=>$notes,
                    'rows'=>$clean,'totals'=>[
                        'fare'=>moneyString($sums['fare']),
                        'per_diem'=>moneyString($sums['per_diem']),
                        'other'=>moneyString($sums['other']),
                        'claimed'=>moneyString($sum),
                        'without_receipt'=>moneyString($unreceipted)]],
                'claim_lines'=>$claimLines,'claim_total'=>moneyString($sum)];
    }
    public static function withoutReceipts(array $snapshot,int $claimId,string $claimNumber): array {
        $itinerary=$snapshot['itinerary']??null;
        if(!is_array($itinerary)||($itinerary['format']??'')!=='APPENDIX_45')return [];
        $result=[];
        foreach(($itinerary['rows']??[]) as $lineIndex=>$row){
            if(!is_array($row))continue;
            foreach(self::COMPONENTS as $key=>$info){
                $status=(string)($row[$key.'_proof']??'');
                if(!in_array($status,['NO_RECEIPT','ALTERNATIVE'],true))continue;
                $amount=(string)($row[$key]??'0.00');
                if(moneyCents($amount)<=0)continue;
                $result[]=['claim_id'=>$claimId,'claim_number'=>$claimNumber,
                    'employee'=>$snapshot['employee']['full_name']??'',
                    'travel_authority_number'=>$snapshot['travel_authority_number']??'',
                    'itinerary_reference'=>$itinerary['reference']??'',
                    'date'=>$row['date']??'', 'places'=>$row['places']??'',
                    'category'=>$info['label'],'amount'=>$amount,'proof_status'=>$status,
                    'support_reference'=>$row[$key.'_reference']??'',
                    'remarks'=>$row['receipt_note']??'',
                    'claim_status'=>$snapshot['state']??'',
                    'itinerary_line'=>(int)$lineIndex+1];
            }
        }
        return $result;
    }
}
