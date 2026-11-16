<?php
declare(strict_types=1);
require_once __DIR__.'/TravelDtePolicy.php';
/** Pure, non-posting travel planning and chronological claim-review rules.
 * All calculations use integer centavos; an apparent session overlap is a FLAG,
 * never a machine-issued disallowance. Unknown actual times stay unknown.
 */
final class TravelExpenseTimeline {
    public const COMPONENTS=['fare'=>'Transportation / fare','per_diem'=>'Daily travel expense','other'=>'Other expense'];
    public const EXPECTATIONS=['RECEIPT_EXPECTED','NO_RECEIPT_EXPECTED','ALTERNATIVE_EXPECTED','UNKNOWN','DTE_EXEMPT_EXPECTED'];
    public const DECISIONS=['UNREVIEWED','NEEDS_EVIDENCE','ALLOWED','PARTIALLY_ALLOWED','EXCLUDED'];
    private static function str(mixed $raw,int $max,string $field,bool $required=false): string {
        demand(is_string($raw),'Invalid '.$field,422);
        $v=trim($raw);
        demand(mbSafeLen($v)<=$max && (!$required || $v!==''),'Invalid '.$field,422);
        return $v;
    }
    private static function validDate(string $raw,string $start,string $end): string {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$raw);
        demand($date!==false && $date->format('Y-m-d')===$raw && $raw>=$start && $raw<=$end,'Travel expense/session date must be within the linked authority',422);
        return $raw;
    }
    private static function time(mixed $raw,string $field): string {
        $s=self::str($raw,5,$field);
        demand($s===''||preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$s)===1,'Invalid 24-hour '.$field,422);
        return $s;
    }
    private static function cents(mixed $raw,string $field): int {
        demand(is_string($raw)||is_int($raw),'Invalid '.$field,422);
        $s=trim((string)$raw);if($s==='')$s='0';
        demand(preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D',$s)===1,'Invalid peso amount for '.$field,422);
        $v=moneyCents($s);
        demand($v>=0&&$v<=100000000,'Amount for '.$field.' must not exceed ₱1,000,000',422);
        return $v;
    }
    private static function basis(array $session): string {
        $v=$session['basis']??'PLANNED';
        demand(in_array($v,['PLANNED','DOCUMENTED_ACTUAL'],true),'Invalid seminar schedule evidence basis',422);
        demand($v!=='DOCUMENTED_ACTUAL'||trim((string)($session['reference']??''))!=='','Documented actual seminar sessions need a source reference',422);
        return $v;
    }
    public static function sessions(mixed $raw,string $from,string $to): array {
        demand(is_array($raw)&&array_is_list($raw)&&count($raw)<=40,'Enter up to 40 seminar schedule sessions',422);
        $out=[];
        foreach($raw as $i=>$session){
            demand(is_array($session),'Invalid session row',422);
            $date=self::validDate(self::str($session['date']??'',10,'session date',true),$from,$to);
            $start=self::time($session['start']??'','session start');
            $end=self::time($session['end']??'','session end');
            demand($start!==''&&$end!==''&&$end>$start,'Seminar session must have an end time after its start',422);
            $out[]=['date'=>$date,'start'=>$start,'end'=>$end,
                'title'=>self::str($session['title']??'',180,'activity title',true),
                'venue'=>self::str($session['venue']??'',180,'session venue',true),
                'reference'=>self::str($session['reference']??'',180,'session reference'),
                'basis'=>self::basis($session)];
        }
        usort($out,static fn($a,$b)=>[$a['date'],$a['start'],$a['end']]<=>[$b['date'],$b['start'],$b['end']]);
        return $out;
    }
    public static function plan(mixed $raw,string $from,string $to,?array $preservedLegacyDte=null): array {
        demand(is_array($raw),'A temporary itinerary is required',422);
        $rows=$raw['rows']??null;
        demand(is_array($rows)&&array_is_list($rows)&&count($rows)>=1&&count($rows)<=60,'Enter 1 to 60 estimated itinerary lines',422);
        $clean=[];$totals=['fare'=>0,'per_diem'=>0,'other'=>0];
        $expectationTotals=array_fill_keys(self::EXPECTATIONS,0);
        foreach($rows as $i=>$row){
            demand(is_array($row),'Invalid planned leg',422);
            $item=[
                'date'=>self::validDate(self::str($row['date']??'',10,'planned date',true),$from,$to),
                'places'=>self::str($row['places']??'',240,'planned route/venue',true),
                'departure'=>self::time($row['departure']??'','planned departure'),
                'arrival'=>self::time($row['arrival']??'','planned arrival'),
                'means'=>self::str($row['means']??'',120,'transport'),
                'note'=>self::str($row['note']??'',260,'planning note')];
            $lineCents=0;
            foreach(self::COMPONENTS as $key=>$label){
                $c=self::cents($row[$key]??'0',$label);
                $proof=self::str($row[$key.'_proof']??'',25,'receipt expectation');
                $ref=self::str($row[$key.'_reference']??'',160,'expected evidence reference');
                $purpose=self::str($row[$key.'_purpose']??'',220,'planned official purpose');
                $eventTime=self::time($row[$key.'_time']??'','planned component time');
                if($c>0){demand(in_array($proof,self::EXPECTATIONS,true),'Classify expected receipt for '.$label.' on line '.($i+1),422);
                    demand($proof!=='DTE_EXEMPT_EXPECTED'||$key==='per_diem','Only daily travel expense may have a DTE receipt exemption',422);
                    $expectationTotals[$proof]+=$c;
                }else{demand($proof===''||$proof==='NONE','A zero-valued estimate cannot be classified as a cost',422);
                    demand($ref==='' && $eventTime==='' && $purpose==='','Zero-value estimate has component details',422);$proof='NONE';}
                $item[$key]=moneyString($c);$item[$key.'_proof']=$proof;
                $item[$key.'_reference']=$ref;$item[$key.'_purpose']=$purpose;$item[$key.'_time']=$eventTime;
                $totals[$key]+=$c;$lineCents+=$c;
            }
            $item['total']=moneyString($lineCents);$clean[]=$item;
        }
        $all=array_sum($totals);
        demand($all>0&&$all<=200000000,'Estimated total must be greater than zero and at most ₱2,000,000',422);
        demand(array_sum($expectationTotals)===$all,'Estimate receipt classification does not reconcile',422);
        $summary=[];
        foreach($totals as $k=>$v)$summary[$k]=moneyString($v);
        foreach($expectationTotals as $k=>$v)$summary[strtolower($k)]=moneyString($v);
        $summary['estimated']=moneyString($all);
        // Activity dates and dates spent travelling are distinct. Both must stay
        // within the approved TA period; no extra day is authorized by this plan.
        $activityFrom=self::str($raw['activity_from']??'',10,'activity start date');
        $activityTo=self::str($raw['activity_to']??'',10,'activity end date');
        demand(($activityFrom==='')===($activityTo===''),'Provide both activity dates or leave both blank for review',422);
        if($activityFrom!==''){
            self::validDate($activityFrom,$from,$to);self::validDate($activityTo,$from,$to);
            demand($activityTo>=$activityFrom,'Activity end date must not precede its start',422);
        }
        $venueArrival=self::str($raw['venue_arrival_on']??'',10,'destination arrival date');
        $venueDeparture=self::str($raw['venue_departure_on']??'',10,'departure from destination date');
        if($venueArrival!=='')self::validDate($venueArrival,$from,$to);
        if($venueDeparture!=='')self::validDate($venueDeparture,$from,$to);
        demand($venueArrival==='' || $venueDeparture==='' || $venueArrival<=$venueDeparture,
            'Departure from destination cannot precede arrival there',422);
        $travelJustification=self::str($raw['travel_days_justification']??'',500,'multi-day travel justification');
        // One-entry UI: DTE lives in an itinerary component, not a second payable worksheet.
        // Restrict this invariant to new submissions so older signed/versioned plans remain readable.
        $uiMode=self::str($raw['ui_mode']??'',40,'itinerary interface mode');
        if($preservedLegacyDte!==null){
            demand($uiMode==='SINGLE_EXPENSE_ENTRY_V1','Invalid legacy DTE preservation request',422);
            $uiMode=''; // keep the old-mode classification until it is reconciled
        }
        demand($uiMode==='' || $uiMode==='SINGLE_EXPENSE_ENTRY_V1','Unsupported itinerary interface mode',422);
        // A previously saved, unmatched worksheet proposal may only be preserved by
        // the workflow after verifying its unchanged authoritative snapshot.
        $dte=$preservedLegacyDte??TravelDtePolicy::normalize($raw['dte']??null,$from,$to,$uiMode==='SINGLE_EXPENSE_ENTRY_V1');
        if($uiMode==='SINGLE_EXPENSE_ENTRY_V1'){
            $rowsByDate=[];
            foreach($clean as $row){
                $c=moneyCents((string)$row['per_diem']);
                if($c===0)continue;
                $date=(string)$row['date'];
                demand(!isset($rowsByDate[$date]),'Only one DTE entry is permitted per travel day; edit the existing entry',422);
                demand(($row['per_diem_proof']??'')==='DTE_EXEMPT_EXPECTED',
                    'DTE must use the receipt-exempt classification',422);
                $rowsByDate[$date]=$c;
            }
            $daysByDate=[];
            foreach(($dte['days']??[]) as $day){
                $c=moneyCents((string)$day['proposed_amount']);
                if($c<=0)continue;
                $dayDate=(string)$day['date'];
                demand(isset($rowsByDate[$dayDate]) && $rowsByDate[$dayDate]===$c,
                    'DTE amount differs between the itinerary and dated allowance record: '.$dayDate,422);
                $daysByDate[$dayDate]=$c;
            }
            demand(count($rowsByDate)===count($daysByDate),
                'Every proposed DTE needs one matching dated allowance entry',422);
        }
        return ['format'=>'TEMPORARY_ITINERARY_1','reference'=>self::str($raw['reference']??'',80,'planning reference'),
            'source_note'=>self::str($raw['source_note']??'',500,'source note'),
            'activity_from'=>$activityFrom,'activity_to'=>$activityTo,
            'venue_arrival_on'=>$venueArrival,'venue_departure_on'=>$venueDeparture,
            'travel_days_justification'=>$travelJustification,
            'rows'=>$clean,'sessions'=>self::sessions($raw['sessions']??[],$from,$to),
            'ui_mode'=>$uiMode,'dte'=>$dte,'totals'=>$summary,
            'not_an_approved_budget'=>true,'payment_authorized'=>false,'dv_id'=>null];
    }
    /** Returns one chronological row PER nonzero component. */
    public static function actualTimeline(array $claim,array $sessions): array {
        $itinerary=$claim['itinerary']??null;
        if(!is_array($itinerary)||($itinerary['format']??'')!=='APPENDIX_45')return [];
        $out=[];
        foreach(($itinerary['rows']??[]) as $i=>$row){
            if(!is_array($row))continue;
            foreach(self::COMPONENTS as $key=>$label){
                $amount=(string)($row[$key]??'0.00');
                if(moneyCents($amount)<=0)continue;
                $date=(string)($row['date']??'');$time=(string)($row[$key.'_time']??'');
                // A travel leg's departure/arrival is never silently treated as
                // proof of when a specific cost was incurred.
                $overlaps=[];
                if($time!==''&&$key==='fare')foreach($sessions as $session){
                    if(($session['date']??'')===$date && $time>=($session['start']??'') && $time<($session['end']??''))
                        $overlaps[]=['title'=>$session['title']??'Seminar','time'=>($session['start']??'').'–'.($session['end']??''),
                            'basis'=>$session['basis']??'PLANNED','reference'=>$session['reference']??''];
                }
                $out[]=['key'=>($i+1).':'.$key,'row'=>(int)$i+1,'component'=>$key,'category'=>$label,
                    'date'=>$date,'time'=>$time,'places'=>(string)($row['places']??''),
                    'means'=>(string)($row['means']??''),'purpose'=>(string)($row[$key.'_purpose']??''),
                    'claimed'=>$amount,'proof_status'=>$row[$key.'_proof']??'UNKNOWN',
                    'support_reference'=>$row[$key.'_reference']??'',
                    'overlaps'=>$overlaps,
                    'flags'=>$overlaps?['FARE_DURING_SESSION']:($time===''?['TIME_UNKNOWN']:[])];
            }
        }
        usort($out,static fn($a,$b)=>[$a['date'],$a['time']===''?'99:99':$a['time'],$a['row'],$a['component']]
            <=>[$b['date'],$b['time']===''?'99:99':$b['time'],$b['row'],$b['component']]);
        return $out;
    }
    public static function review(mixed $raw,array $claim,string $from,string $to): array {
        demand(is_array($raw),'Invalid expense review',422);
        $sessions=self::sessions($raw['sessions']??[],$from,$to);
        $actual=self::actualTimeline($claim,$sessions);
        // Only the server-fetched actual itinerary is used for review.
        $expected=[];foreach($actual as $line)$expected[$line['key']]=$line;
        $submitted=$raw['decisions']??[];
        demand(is_array($submitted)&&array_is_list($submitted),'Invalid review decisions',422);
        $clean=[];$seen=[];$claimed=0;$allowed=0;$pending=0;
        foreach($submitted as $d){
            demand(is_array($d),'Invalid decision entry',422);
            $key=$d['key']??null;
            demand(is_string($key)&&isset($expected[$key])&&!isset($seen[$key]),'Duplicate or non-existent expense review component',422);
            $seen[$key]=true;$source=$expected[$key];
            $decision=$d['decision']??'UNREVIEWED';
            demand(is_string($decision)&&in_array($decision,self::DECISIONS,true),'Invalid review decision',422);
            $whole=moneyCents($source['claimed']);
            $reason=self::str($d['reason']??'',60,'decision reason');
            demand($reason===''||in_array($reason,[
                'FARE_DURING_SESSION','DTE_COVERED','DUPLICATE','NO_OFFICIAL_PURPOSE',
                'EVIDENCE_VERIFIED','AUTHORIZED_MOVEMENT','OTHER'],true),
                'Unsupported paper review reason',422);
            $note=self::str($d['note']??'',400,'decision note');
            $paper=self::str($d['paper_reviewer']??'',160,'paper reviewer');
            $reference=self::str($d['paper_reference']??'',180,'paper review reference');
            if(in_array($decision,['UNREVIEWED','NEEDS_EVIDENCE'],true)){
                $amount='0.00';$pending+=$whole;
            }else{
                $c=self::cents($d['allowed']??'0','allowed expense');
                demand($c<=$whole,'Allowed amount cannot exceed the documented claimed expense',422);
                demand(($decision==='ALLOWED'&&$c===$whole)||($decision==='EXCLUDED'&&$c===0)||
                    ($decision==='PARTIALLY_ALLOWED'&&$c>0&&$c<$whole),'Review decision and allowed amount disagree',422);
                // The review decision and its centavo-reconciled allowance are
                // sufficient for ordinary findings. Coded reason, additional
                // paper reviewer, reference and narrative are optional. The
                // authenticated reviewer is captured in the version/audit log.
                // Keep the exceptional documented-session fare rule below.
                // If an evidenced ACTUAL seminar session overlaps a fare, a
                // nonzero allowance requires a specifically documented official
                // off-site movement. No blanket rejection of true exceptions.
                $documentedOverlap=$source['component']==='fare' && array_filter($source['overlaps'],
                    static fn($x)=>($x['basis']??'')==='DOCUMENTED_ACTUAL');
                if($documentedOverlap && $c>0){
                    demand($reason==='AUTHORIZED_MOVEMENT',
                        'Fare during documented seminar proper may only be allowed with a supported authorized-movement finding',422);
                    // This exceptional allowance still needs a traceable paper
                    // reference. Reuse one already recorded in the source
                    // itinerary when present; never force duplicate entry.
                    demand($reference!==''||trim((string)($source['support_reference']??''))!=='',
                        'Fare during documented seminar hours needs an existing source or paper review evidence reference',422);
                }
                $amount=moneyString($c);$allowed+=$c;
            }
            $claimed+=$whole;
            $clean[]=['key'=>$key,'decision'=>$decision,'claimed'=>$source['claimed'],
                'allowed'=>$amount,'disallowed'=>in_array($decision,['UNREVIEWED','NEEDS_EVIDENCE'],true)?'0.00':moneyString($whole-moneyCents($amount)),
                'reason'=>$reason,'note'=>$note,'paper_reviewer'=>$paper,'paper_reference'=>$reference];
        }
        demand(count($seen)===count($expected),'Review must contain every current expense component',422);
        demand($claimed<=200000000,'Expense amount exceeds supported limit',422);
        return ['schedule'=>$sessions,'decisions'=>$clean,
            'totals'=>['claimed'=>moneyString($claimed),'reviewed_allowed'=>moneyString($allowed),
                'unreviewed'=>moneyString($pending),
                'reviewed_disallowed'=>moneyString($claimed-$pending-$allowed)],
            'state'=>'DOCUMENTARY_REVIEW_ONLY','ready_for_dv'=>false,'payment_authorized'=>false];
    }
}
