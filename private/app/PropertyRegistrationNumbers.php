<?php
declare(strict_types=1);
/** Atomic per-school lifetime property number allocation. Must run inside a PDO transaction.
 * Does not use document_registry or the month-resetting official form counters.
 * Existing tags are never altered. The legacy max is a collision-resistance floor.
 */
final class PropertyRegistrationNumbers {
    private const TYPE = 'PROPERTY_TAG';
    private const PERIOD = 'ALLTIME';
    public static function format(string $schoolCode,string $yyMm,int $sequence): string {
        demand(preg_match('/^[0-9]{6}$/D',$schoolCode)===1,
            'School ID must contain exactly six digits for automatic property numbering',409);
        demand(preg_match('/^[0-9]{4}$/D',$yyMm)===1 && $sequence>0 && $sequence<=999999999,
            'Property number sequence is invalid',409);
        return $schoolCode.'-'.$yyMm.'-'.str_pad((string)$sequence,5,'0',STR_PAD_LEFT);
    }
    /** Reserve a whole batch without a gap on a rolled-back transaction. */
    public static function reserve(PDO $db,int $school,int $count): array {
        demand($db->inTransaction(),'Property numbering requires an active database transaction',500);
        demand($school>0 && $count>=1 && $count<=100,'Register 1–100 property units per batch',422);
        // Serialize all new property allocations and school ID edits through this row.
        $org=one($db,'SELECT id,code,kind,active FROM organizations WHERE id=? FOR UPDATE',[$school]);
        demand($org!==null && $org['kind']==='SCHOOL' && (int)$org['active']===1,
            'Automatic property registration requires an active school workspace',409);
        $code=(string)$org['code'];
        demand(preg_match('/^[0-9]{6}$/D',$code)===1,'School ID must be six digits',409);
        // A fixed all-time slot deliberately never resets with reporting period.
        q($db,'INSERT INTO document_counters (organization_id,doc_type,period,next_sequence) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE next_sequence=next_sequence',
            [$school,self::TYPE,self::PERIOD]);
        $counter=one($db,'SELECT next_sequence FROM document_counters WHERE organization_id=? AND doc_type=? AND period=? FOR UPDATE',
            [$school,self::TYPE,self::PERIOD]);
        demand($counter!==null,'Property registration sequence is unavailable',503);
        // Existing auto-format properties, including those under a prior School ID,
        // participate in the next number. Old manual tags remain unchanged.
        $legacy=one($db,"SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(property_tag,'-',-1) AS UNSIGNED)),0) AS n FROM assets
            WHERE organization_id=? AND property_tag REGEXP '^[0-9]{6}-[0-9]{4}-[0-9]{5,}$'",[$school]);
        $first=max((int)$counter['next_sequence'],(int)($legacy['n']??0)+1);
        demand($first>0 && $first+$count-1<=999999999,'Property numbering range exhausted; administrator review required',409);
        q($db,'UPDATE document_counters SET next_sequence=? WHERE organization_id=? AND doc_type=? AND period=?',
            [$first+$count,$school,self::TYPE,self::PERIOD]);
        $stamp=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('ym');
        $numbers=[];
        for($i=0;$i<$count;$i++)$numbers[]=self::format($code,$stamp,$first+$i);
        return $numbers;
    }
}
