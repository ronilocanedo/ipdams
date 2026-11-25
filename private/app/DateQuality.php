<?php
declare(strict_types=1);
/**
 * v0.2.82: explicitly triggered, read-only administrator date-quality review.
 *
 * Unlike SchemaHealth's INFORMATION_SCHEMA-only check, this scans two actual
 * operational date fields and relationship booleans. Return COUNT TOTALS
 * ONLY — never raw date values, organization IDs, document IDs or payments.
 * Fixed SQL with a bounded scan; no caller-provided table/column predicates.
 * The caller must enforce SUPER_ADMIN, CSRF, acknowledgement and a strictly
 * read-only database transaction. This class must never be a public endpoint.
 */
final class IPDAMSDateQuality {
    public const MAX_ROWS=5000;
    public static function classify(mixed $value,string $kind,string $today): string {
        if($value===null || $value==='')return 'missing';
        if(!is_string($value) ||
           !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value))
            return 'invalid_format';
        [$year,$month,$day]=array_map('intval',explode('-',$value));
        if(!checkdate($month,$day,$year) ||
           ($kind==='bank' && ($year<2000||$year>2099)) ||
           ($kind==='pr' && ($year<1900||$year>2100)))
            return 'invalid_calendar';
        if($kind==='bank' && $value>$today)return 'future_bank_credit';
        return 'valid';
    }

    private static function countDate(PDO $db,string $sql,string $kind,string $today): array {
        $out=[
            'scanned'=>0,'valid'=>0,'invalid_format'=>0,'invalid_calendar'=>0,
            'future_bank_credit'=>0,'missing_linked_or_finalized'=>0,
            'missing_unlinked_or_draft'=>0,'complete'=>true,
        ];
        $statement=$db->query($sql);
        while(($row=$statement->fetch(PDO::FETCH_ASSOC))!==false){
            if($out['scanned']===self::MAX_ROWS){$out['complete']=false;break;}
            $out['scanned']++;
            $class=self::classify($row['date_value']??null,$kind,$today);
            if($class==='missing'){
                if((int)$row['requires_date']===1)$out['missing_linked_or_finalized']++;
                else $out['missing_unlinked_or_draft']++;
            }else $out[$class]++;
        }
        $statement->closeCursor();
        return $out;
    }

    /** FIXED SQL: no caller-controlled table/column names or SQL. */
    public static function scan(PDO $db,string $today): array {
        if(!preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D',$today))
            throw new InvalidArgumentException('Invalid Manila calendar date');
        [$year,$month,$day]=array_map('intval',explode('-',$today));
        if(!checkdate($month,$day,$year))throw new InvalidArgumentException('Invalid Manila calendar date');
        $bank=self::countDate($db,
            "SELECT bank_credit_date AS date_value, (bank_account_id IS NOT NULL) AS requires_date
             FROM fund_events WHERE event_type='ALLOTMENT' ORDER BY id LIMIT 5001",'bank',$today);
        $pr=self::countDate($db,
            "SELECT document_date AS date_value, (status='FINALIZED') AS requires_date
             FROM purchase_requests ORDER BY id LIMIT 5001",'pr',$today);
        $complete=$bank['complete']&&$pr['complete'];
        $anomalies=0;
        foreach([$bank,$pr] as $row)
            $anomalies+=$row['invalid_format']+$row['invalid_calendar']
                +$row['missing_linked_or_finalized'];
        $anomalies+=$bank['future_bank_credit'];
        return [
            'contract'=>'IPDAMS-DATE-QUALITY-1',
            'assessment'=>!$complete?'INCOMPLETE_SCAN':
                ($anomalies>0?'VALUE_REVIEW_REQUIRED':'NO_VALUE_ANOMALIES_IN_BOUNDED_SCAN'),
            'bank_credit_dates'=>$bank,'pr_document_dates'=>$pr,
            'explanation'=>'Reads only ALLOTMENT bank-credit and PR document-date columns and relation flags. Returns aggregate counts, never actual dates or row identities.',
            'warning'=>'Even with no anomalies, VARCHAR is not a DATE constraint; this limited result does not authorize DV posting or certify financial readiness.',
        ];
    }
}
