<?php
declare(strict_types=1);
/**
 * v0.2.380: pure, fail-closed PR line accounting inheritance rules.
 * These functions neither change accounting configuration nor post transactions.
 */
final class PrUacsInheritance {
    public static function debitAccount(string $code): bool {
        return preg_match('/^(?:502|104|106|108)[0-9]{7}$/D',$code) === 1;
    }
    /** Resolve a Division-scoped mapping as it existed for the original PR date.
     * Legacy global fallback is considered only if no Division mapping is effective.
     */
    public static function choose(int $itemId,string $date,array $divisionMappings,array $legacyMappings): array {
        $rows=array_values(array_filter($divisionMappings,static function($row) use($itemId,$date){
            return is_array($row) && (int)($row['item_id']??0)===$itemId &&
                (string)($row['effective_from']??'')<=$date;
        }));
        usort($rows,static fn($a,$b)=>strcmp((string)$b['effective_from'],(string)$a['effective_from']));
        if($rows){
            if(count($rows)>1 && ($rows[0]['effective_from']??'')===($rows[1]['effective_from']??''))
                return ['ok'=>false,'issue'=>'Competing Division mappings with the same effective date'];
            $chosen=$rows[0];$origin='DIVISION';
        }else{
            if(count($legacyMappings)!==1)
                return ['ok'=>false,'issue'=>'No single approved mapping effective on the original PR date'];
            $chosen=$legacyMappings[0];$origin='LEGACY_GLOBAL';
        }
        $code=(string)($chosen['uacs_code']??'');
        $effective=(string)($chosen['effective_from']??'');
        if(!self::debitAccount($code) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$effective) || $effective>$date)
            return ['ok'=>false,'issue'=>'Account code or mapping effective date is invalid for this PR'];
        return ['ok'=>true,'uacs_code'=>$code,'effective_from'=>$effective,'source'=>$origin];
    }
    /** Find exactly one frozen PR line; never fall back to today's mapping if a
     * versioned PR snapshot exists but its identity/date does not match. */
    public static function inherited(array $snapshot,int $prId,int $prLineId,int $itemId,string $prDate): array {
        if((int)($snapshot['format']??0)!==1 || (int)($snapshot['pr_id']??0)!==$prId ||
           (string)($snapshot['pr_document_date']??'')!==$prDate || !is_array($snapshot['lines']??null))
            return ['ok'=>false,'issue'=>'Saved PR UACS snapshot identity/date mismatch'];
        $matches=array_values(array_filter($snapshot['lines'],static fn($line)=>
            is_array($line) && (int)($line['pr_line_id']??0)===$prLineId));
        if(count($matches)!==1 || (int)($matches[0]['item_id']??0)!==$itemId)
            return ['ok'=>false,'issue'=>'Saved PR UACS line missing or changed'];
        $line=$matches[0];$code=(string)($line['uacs_code']??'');
        $effective=(string)($line['effective_from']??'');
        if(!self::debitAccount($code)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$effective)||$effective>$prDate)
            return ['ok'=>false,'issue'=>'Saved PR line UACS is invalid for its document date'];
        return ['ok'=>true,'uacs_code'=>$code,'effective_from'=>$effective,'source'=>'FINALIZED_PR_SNAPSHOT'];
    }
}
