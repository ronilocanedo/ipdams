<?php
declare(strict_types=1);
require __DIR__.'/IcsMovementEvidence.php';
$ics=['id'=>1,'asset_id'=>15,'custodian'=>'Test Custodian','created_at'=>'2026-10-31 12:00:00','status'=>'FINALIZED','doc_type'=>'ICS'];
$m=['id'=>41,'asset_id'=>15,'event_type'=>'ISSUE','to_custodian'=>'Test Custodian','created_at'=>'2026-10-31 12:00:00'];
function checkEvidence(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$r=IcsMovementEvidence::inspect($ics,[$m]);
checkEvidence($r['reason']==='PLAUSIBLE_EVENT_NOT_CAUSALLY_LINKED','plausible match not certified');
checkEvidence($r['status']==='REVIEW_REQUIRED','review always required');
checkEvidence(IcsMovementEvidence::inspect($ics,[])['reason']==='NO_SAME_SECOND_CUSTODIAN_MATCH','missing issue');
checkEvidence(IcsMovementEvidence::inspect($ics,[$m,$m])['reason']==='MULTIPLE_PLAUSIBLE_EVENTS','ambiguous duplicates');
checkEvidence(IcsMovementEvidence::inspect($ics,[array_merge($m,['to_custodian'=>'Other'])])['candidate_ids']===[],'custodian mismatch');
checkEvidence(IcsMovementEvidence::inspect(array_merge($ics,['status'=>'CANCELLED']),[$m])['status']==='NOT_ELIGIBLE','cancelled ICS');
checkEvidence(IcsMovementEvidence::inspect($ics,[array_merge($m,['asset_id'=>16])])['candidate_ids']===[],'asset isolation');
