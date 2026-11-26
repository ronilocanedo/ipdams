<?php
declare(strict_types=1);
/** Development foundation only. Pure validation; no database writes or posting. */
final class FinancialClosePolicy {
    private const MAX = 9999999999999999;
    private static function require(bool $ok,string $code):void {if(!$ok)throw new DomainException($code);}
    private static function amount(mixed $v,bool $positive=false):int {
        self::require(is_int($v)&&$v>=0&&$v<=self::MAX&&(!$positive||$v>0),'INVALID_CENTS');return $v;
    }
    private static function signed(mixed $v):int {self::require(is_int($v)&&$v>=-self::MAX&&$v<=self::MAX,'INVALID_CENTS');return $v;}
    private static function add(int $a,int $b):int {return self::signed($a+$b);}
    private static function id(mixed $v):int {self::require(is_int($v)&&$v>0,'INVALID_ID');return $v;}
    private static function evidence(mixed $v):void {self::require(is_string($v)&&trim($v)!==''&&strlen($v)<=255,'EVIDENCE_REQUIRED');}
    public static function day(string $d):bool {$x=DateTimeImmutable::createFromFormat('!Y-m-d',$d);return $x!==false&&$x->format('Y-m-d')===$d;}
    /** Signed bank and book adjustments are NOT interchangeable. Outstanding checks are bank-side only. */
    public static function reconcile(int $statement,int $book,array $lines):array {
        $bank=self::signed($statement);$books=self::signed($book);$seen=[];
        self::require(count($lines)<=500,'TOO_MANY_ADJUSTMENTS');
        $types=['DEPOSIT_IN_TRANSIT'=>['BANK',1],'OUTSTANDING_CHECK'=>['BANK',-1],'BANK_ERROR_ADD'=>['BANK',1],'BANK_ERROR_SUBTRACT'=>['BANK',-1],
            'BANK_CHARGE'=>['BOOK',-1],'BANK_INTEREST'=>['BOOK',1],'BOOK_ERROR_ADD'=>['BOOK',1],'BOOK_ERROR_SUBTRACT'=>['BOOK',-1]];
        foreach($lines as $l){self::require(is_array($l)&&isset($types[$l['kind']??'']),'INVALID_ADJUSTMENT');$type=$l['kind'];[$side,$direction]=$types[$type];
            self::require(($l['side']??null)===$side,'WRONG_ADJUSTMENT_SIDE');self::evidence($l['evidence']??null);
            // Source key must be a verified server-side source identity (not a user-generated dedupe key).
            $key=$l['source_key']??null;self::evidence($key);self::require(!isset($seen[$key]),'DUPLICATE_ADJUSTMENT');$seen[$key]=true;
            $amount=self::amount($l['cents']??null,true);if($side==='BANK')$bank=self::add($bank,$direction*$amount);else $books=self::add($books,$direction*$amount);
        }
        $difference=self::add($books,-$bank);return ['adjusted_bank_cents'=>$bank,'adjusted_book_cents'=>$books,'difference_cents'=>$difference,'balanced'=>$difference===0];
    }
    /** Input is explicit attribution by download and by saved UACS/tax source ID, not proportional guesses. */
    public static function allocation(array $dv,array $downloads,array $lines,array $grossSources,array $taxSources):array {
        $org=self::id($dv['organization_id']??null);$fund=self::id($dv['fund_id']??null);$bank=self::id($dv['bank_account_id']??null);$date=$dv['date']??'';
        self::require(is_string($date)&&self::day($date),'INVALID_DV_DATE');
        $gross=self::amount($dv['gross_cents']??null,true);$net=self::amount($dv['net_cents']??null,true);$ded=self::amount($dv['deductions_cents']??null);
        self::require($gross===$net+$ded,'DV_GROSS_NET_MISMATCH');
        self::require(count($lines)>0&&count($lines)<=500&&count($grossSources)>0&&count($grossSources)<=500&&count($taxSources)<=500,'INVALID_ALLOCATION_COUNT');
        $expected=['GROSS'=>[],'TAX'=>[]];foreach(['GROSS'=>$grossSources,'TAX'=>$taxSources] as $kind=>$sources){foreach($sources as $id=>$v){self::id($id);$expected[$kind][$id]=self::amount($v,true);}}
        $sum=function(array $xs):int{$n=0;foreach($xs as $x)$n=self::add($n,$x);return $n;};
        self::require($sum($expected['GROSS'])===$gross&&$sum($expected['TAX'])===$ded,'SOURCE_COMPONENT_TOTAL_MISMATCH');
        $seen=[];$by=[];$used=['GROSS'=>[],'TAX'=>[]];
        foreach($lines as $line){$did=self::id($line['download_id']??null);$sid=self::id($line['source_id']??null);$kind=$line['kind']??'';
            self::require(in_array($kind,['GROSS','TAX'],true)&&isset($expected[$kind][$sid]),'UNKNOWN_SOURCE_COMPONENT');
            $key="$did:$kind:$sid";self::require(!isset($seen[$key]),'DUPLICATE_ALLOCATION_LINE');$seen[$key]=true;
            $d=$downloads[$did]??null;self::require(is_array($d)&&($d['organization_id']??null)===$org&&($d['fund_id']??null)===$fund&&($d['bank_account_id']??null)===$bank,'DOWNLOAD_SCOPE_MISMATCH');
            self::require(($d['verified']??false)===true&&is_string($d['credit_date']??null)&&self::day($d['credit_date'])&&$d['credit_date']<=$date,'UNVERIFIED_OR_FUTURE_DOWNLOAD');
            $v=self::amount($line['cents']??null,true);$used[$kind][$sid]=self::add($used[$kind][$sid]??0,$v);
            $by[$did]??=['gross_cents'=>0,'tax_cents'=>0,'net_cents'=>0];$k=$kind==='GROSS'?'gross_cents':'tax_cents';$by[$did][$k]=self::add($by[$did][$k],$v);
        }
        foreach($expected as $kind=>$sources){foreach($sources as $id=>$v)self::require(($used[$kind][$id]??0)===$v,'SOURCE_COMPONENT_ALLOCATION_MISMATCH');}
        foreach($by as $did=>&$b){$d=$downloads[$did];$b['net_cents']=$b['gross_cents']-$b['tax_cents'];self::require($b['net_cents']>0,'INVALID_DOWNLOAD_NET');
            $capacity=self::amount($d['credit_cents']??null,true);$committed=self::amount($d['other_committed_cents']??null);
            self::require($committed<=$capacity&&$b['net_cents']<=$capacity-$committed,'DOWNLOAD_CASH_CAPACITY_EXCEEDED');
        }unset($b);ksort($by,SORT_NUMERIC);
        self::require($sum(array_column($by,'net_cents'))===$net,'NET_ALLOCATION_MISMATCH');return $by;
    }
    /** Canonical snapshot envelope: integer cents and source identifiers, no floating money. Capture ≠ certification. */
    private static function canonical(mixed $v):mixed {
        if(is_array($v)){if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as &$x)$x=self::canonical($x);unset($x);return $v;}
        self::require(is_null($v)||is_bool($v)||is_int($v)||is_string($v),'INVALID_SNAPSHOT_VALUE');return $v;
    }
    private static function encode(array $v):string {return json_encode(self::canonical($v),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    public static function snapshot(array $basis,array $payload):array {
        self::id($basis['organization_id']??null);self::id($basis['bank_account_id']??null);$period=$basis['period']??'';$cutoff=$basis['cutoff']??'';
        self::require(is_string($period)&&preg_match('/^20\d\d-(0[1-9]|1[0-2])$/D',$period)===1&&is_string($cutoff)&&self::day($cutoff)&&$cutoff===(new DateTimeImmutable($period.'-01'))->format('Y-m-t'),'INVALID_PERIOD_CUTOFF');
        self::require(in_array($basis['provenance']??null,['CAPTURED_AT_CLOSE','HISTORICAL_RECONSTRUCTION'],true),'INVALID_PROVENANCE');
        foreach(['accounting','tax','checks','credits','allocation','signatories','reconciliation'] as $key)self::require(array_key_exists($key,$payload)&&is_array($payload[$key]),'INCOMPLETE_SNAPSHOT');
        // No assertion of historical completeness or closure is made by this pure helper.
        $body=['schema'=>1,'basis'=>$basis,'payload'=>$payload,'certified'=>false];$encoded=self::encode($body);self::require(strlen($encoded)<=10000000,'SNAPSHOT_TOO_LARGE');return ['body'=>$body,'sha256'=>hash('sha256',$encoded)];
    }
    public static function verifySnapshot(array $envelope):bool {
        try{return is_array($envelope['body']??null)&&is_string($envelope['sha256']??null)&&hash_equals(hash('sha256',self::encode($envelope['body'])),$envelope['sha256']);}catch(Throwable){return false;}
    }
}
