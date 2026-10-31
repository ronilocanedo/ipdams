<?php
declare(strict_types=1);
/** Diagnostic evidence only: matching timestamps never prove signed custody. */
final class IcsMovementEvidence {
    public static function inspect(array $ics,array $movements):array {
        foreach (['id','asset_id','custodian','created_at','status','doc_type'] as $field)
            if(!array_key_exists($field,$ics))throw new InvalidArgumentException('Missing ICS field: '.$field);
        if($ics['doc_type']!=='ICS'||$ics['status']!=='FINALIZED')
            return ['status'=>'NOT_ELIGIBLE','candidate_ids'=>[],'reason'=>'Not a finalized ICS'];
        $docTime=strtotime((string)$ics['created_at']);
        if($docTime===false)throw new InvalidArgumentException('Invalid ICS time');
        $candidates=[];$issueCount=0;
        foreach($movements as $movement){
            foreach(['id','asset_id','event_type','to_custodian','created_at'] as $field)
                if(!array_key_exists($field,$movement))throw new InvalidArgumentException('Missing movement field: '.$field);
            if((string)$movement['asset_id']!==(string)$ics['asset_id']||$movement['event_type']!=='ISSUE')continue;
            $issueCount++;
            $time=strtotime((string)$movement['created_at']);
            if($time===false)throw new InvalidArgumentException('Invalid movement time');
            if($time===$docTime&&trim((string)$movement['to_custodian'])===trim((string)$ics['custodian']))
                $candidates[]=(string)$movement['id'];
        }
        return ['status'=>'REVIEW_REQUIRED','candidate_ids'=>$candidates,
            'issue_event_count'=>$issueCount,
            'reason'=>count($candidates)===0?'NO_SAME_SECOND_CUSTODIAN_MATCH':
                (count($candidates)>1?'MULTIPLE_PLAUSIBLE_EVENTS':'PLAUSIBLE_EVENT_NOT_CAUSALLY_LINKED')];
    }
}
