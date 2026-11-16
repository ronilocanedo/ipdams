<?php
declare(strict_types=1);
/**
 * Shared YEAR-CONTINUOUS official-form serial allocator.
 * Database period YYYY-00 is reserved as the year-level counter only;
 * the official document_registry period and printed number stay YYYY-MM.
 * Caller must already be in its own atomic DB transaction.
 */
final class AnnualDocumentNumbering {
    public const SCHOOL_TYPES = ['AR','TA','RC','PR','RFQ','AWARD','PO','IAR','DV','RIS','ICS','PAR','ITR','PTR','RRSP','PRR','TBR','TBX','TBI','TBQ','TBD'];
    public static function reserve(PDO $db,int $org,string $type,string $period,?int $excludedRegistryId=null):string {
        demand($db->inTransaction(),'Document numbering requires an active transaction',500);
        demand($org>0 && in_array($type,self::SCHOOL_TYPES,true),'Unsupported document number type',422);
        demand(preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])$/D',$period)===1,'Invalid document issue period',422);
        demand($excludedRegistryId===null || $excludedRegistryId>0,'Invalid unfinalized document identity',409);
        $year=substr($period,0,4);
        $annual=$year.'-00';
        // Unique PK is the serialization point for same-school/type/year issuances.
        q($db,'INSERT INTO document_counters (organization_id,doc_type,period,next_sequence) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE next_sequence=next_sequence',[$org,$type,$annual]);
        $counter=one($db,'SELECT next_sequence FROM document_counters WHERE organization_id=? AND doc_type=? AND period=? FOR UPDATE',[$org,$type,$annual]);
        demand($counter!==null && (int)$counter['next_sequence']>=1,'Annual document counter is invalid; review School Numbering',409);
        // Respect earlier configured MONTHLY counters: they are never altered by this upgrade.
        $legacy=q($db,'SELECT next_sequence FROM document_counters WHERE organization_id=? AND doc_type=? AND period>=? AND period<=? FOR UPDATE',
            [$org,$type,$year.'-01',$year.'-12'])->fetchAll(PDO::FETCH_ASSOC);
        $floor=1;
        foreach($legacy as $row) {
            demand((int)$row['next_sequence']>=1,'Previous monthly document counter needs review',409);
            $floor=max($floor,(int)$row['next_sequence']);
        }
        $sql='SELECT id,display_number FROM document_registry WHERE organization_id=? AND doc_type=? AND period>=? AND period<=?';
        $params=[$org,$type,$year.'-01',$year.'-12'];
        if($excludedRegistryId!==null){$sql.=' AND id<>?';$params[]=$excludedRegistryId;}
        $existing=q($db,$sql.' ORDER BY id',$params)->fetchAll(PDO::FETCH_ASSOC);
        $highest=0;
        foreach($existing as $row) {
            $number=(string)$row['display_number'];
            demand(preg_match('/^'.preg_quote($year,'/').'-(?:0[1-9]|1[0-2])-([0-9]{4})$/D',$number,$match)===1,
                'Existing '.$type.' document number needs technical review',409);
            $highest=max($highest,(int)$match[1]);
        }
        // count() avoids returning a repeated serial when earlier months both issued 0001.
        $next=max((int)$counter['next_sequence'],$floor,$highest+1,count($existing)+1);
        demand($next>=1 && $next<=9999,'Annual '.$type.' document numbering limit reached',409);
        q($db,'UPDATE document_counters SET next_sequence=? WHERE organization_id=? AND doc_type=? AND period=?',
            [$next+1,$org,$type,$annual]);
        return $period.'-'.str_pad((string)$next,4,'0',STR_PAD_LEFT);
    }
}
