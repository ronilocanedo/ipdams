<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/TravelExpenseTimeline.php';
require_once __DIR__.'/TravelReimbursementLocks.php';
/** Document-only pre-trip plan and actual per-component finding register.
 * Not a voucher, appropriation, charge, approval or payment path.
 */
final class TravelExpenseWorkflow {
    public function __construct(private PDO $db,private array $user){}
    private function org(array $data,bool $write=false): int {
        demand(($this->user['role']??'')!=='VIEWER','Private travel information is unavailable to MOOE viewers',403);
        $org=idOf($data,'organization_id');scope($this->db,$this->user,$org);
        if($write){allowedToWrite($this->user);
            $row=one($this->db,'SELECT kind FROM organizations WHERE id=? AND active=1',[$org]);
            demand($row!==null && ($this->user['role']==='SUPER_ADMIN'||
                ($this->user['role']==='SCHOOL'&&$row['kind']==='SCHOOL')||
                ($this->user['role']==='DIVISION'&&$row['kind']==='DIVISION')),
                'Only the owning workspace may record travel planning and review',403);
        }
        return $org;
    }
    private function doc(int $org,string $kind,int $id,bool $lock=false): array {
        demand(in_array($kind,['AR','TA','RC'],true),'Invalid travel source kind',422);
        $row=one($this->db,'SELECT id,display_number FROM document_registry WHERE organization_id=? AND doc_type=? AND id=? AND entity_id=?'.($lock?' FOR UPDATE':''),[$org,$kind,$id,$id]);
        demand($row!==null,'Travel document not found in this workspace',404);
        $v=one($this->db,'SELECT version_no,snapshot_json FROM document_versions WHERE organization_id=? AND doc_type=? AND entity_id=? ORDER BY version_no DESC LIMIT 1',[$org,$kind,$id]);
        demand($v!==null,'Travel document version unavailable',409);
        $snap=json_decode((string)$v['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($snap)&&($snap['kind']??'')===$kind&&(int)($snap['organization_id']??0)===$org,'Travel document scope mismatch',409);
        return ['id'=>$id,'number'=>$row['display_number'],'version'=>(int)$v['version_no'],'snapshot'=>$snap];
    }
    private function authority(int $org,int $id,bool $lock=false): array {
        $ta=$this->doc($org,'TA',$id,$lock);
        $arId=(int)($ta['snapshot']['activity_request_id']??0);
        demand($arId>0,'Travel Authority has no source request',409);
        $link=one($this->db,"SELECT id FROM document_links WHERE organization_id=? AND source_type='AR' AND source_id=? AND target_type='TA' AND target_id=?",[$org,$arId,$id]);
        demand($link!==null,'Activity Request / Authority relationship is invalid',409);
        $ar=$this->doc($org,'AR',$arId);
        demand((int)($ta['snapshot']['employee']['id']??0)>0 &&
            (int)($ta['snapshot']['employee']['id']??0)===(int)($ar['snapshot']['employee']['id']??0),
            'Employee mismatch between linked travel documents',409);
        $from=(string)($ta['snapshot']['travel_from']??'');$to=(string)($ta['snapshot']['travel_to']??'');
        demand($from!==''&&$to!==''&&$to>=$from,'Travel Authority lacks valid travel dates',409);
        return ['ta'=>$ta,'ar'=>$ar,'from'=>$from,'to'=>$to];
    }
    /** Official station is sourced from the employee's dated assignment in
     * the TA-owning organization, not free-text supplied by a requestor.
     * Fallback to the same scoped organization only when historical assignment
     * metadata cannot confirm the date; the UI flags that uncertainty.
     */
    private function permanentStation(int $org,array $source): array {
        $emp=(int)($source['ta']['snapshot']['employee']['id']??0);
        $on=$source['from'];
        $assigned=one($this->db,'SELECT o.name FROM employee_assignments a JOIN organizations o ON o.id=a.organization_id WHERE a.organization_id=? AND a.employee_id=? AND a.effective_from<=? AND (a.effective_to IS NULL OR a.effective_to>=?) ORDER BY a.effective_from DESC,a.id DESC LIMIT 1',
            [$org,$emp,$on,$on]);
        if($assigned!==null && trim((string)($assigned['name']??''))!=='')
            return ['name'=>(string)$assigned['name'],'basis'=>'DATED_EMPLOYEE_ASSIGNMENT'];
        $orgRow=one($this->db,'SELECT name FROM organizations WHERE id=?',[$org]);
        demand($orgRow!==null && trim((string)($orgRow['name']??''))!=='',
            'Official station unavailable for the Travel Authority organization',409);
        return ['name'=>(string)$orgRow['name'],'basis'=>'OWNING_OFFICE_REQUIRES_ASSIGNMENT_REVIEW'];
    }
    private function audit(int $org,string $entity,int $id,string $event,array $data): void {
        q($this->db,'INSERT INTO audit_events (organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
            [$org,$this->user['id'],$event,$entity,$id,json_encode($data,JSON_THROW_ON_ERROR)]);
    }
    public function listPlans(array $data): array {
        $org=$this->org($data);
        $before=$data['before_id']??null;
        demand($before===null||((is_string($before)&&ctype_digit($before)||is_int($before))&&(int)$before>0),
            'Invalid temporary-itinerary list cursor',422);
        $params=[$org];$filter='';
        if($before!==null){$filter=' AND id<?';$params[]=(int)$before;}
        $rows=q($this->db,'SELECT id,authority_id,version_no,snapshot_json,updated_at FROM travel_itinerary_plans WHERE organization_id=?'.$filter.' ORDER BY id DESC LIMIT 41',$params)->fetchAll(PDO::FETCH_ASSOC);
        $hasMore=count($rows)>40;$page=array_slice($rows,0,40);
        $out=[];foreach($page as $r){$s=json_decode($r['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            $out[]=['id'=>(int)$r['id'],'authority_id'=>(int)$r['authority_id'],'version'=>(int)$r['version_no'],
                'authority_number'=>$s['authority_number']??'','employee'=>$s['employee']['full_name']??'',
                'estimated'=>$s['plan']['totals']['estimated']??'0.00','updated_at'=>$r['updated_at']];}
        return ['plans'=>$out,'has_more'=>$hasMore,'next_cursor'=>$hasMore&&$page?(int)end($page)['id']:null,
            'note'=>'Only tentative estimates; not an approved Activity Request amount, certified claim, or payable expense.'];
    }
    public function planDetail(array $data): array {
        $org=$this->org($data);$id=idOf($data,'authority_id');$source=$this->authority($org,$id);
        $record=one($this->db,'SELECT id,version_no,snapshot_json FROM travel_itinerary_plans WHERE organization_id=? AND authority_id=?',[$org,$id]);
        $versions=[];
        if($record!==null){
            $history=q($this->db,'SELECT version_no,snapshot_json,actor_id,created_at FROM travel_itinerary_plan_versions WHERE organization_id=? AND plan_id=? ORDER BY version_no DESC LIMIT 20',
                [$org,(int)$record['id']])->fetchAll(PDO::FETCH_ASSOC);
            foreach($history as $v){$snap=json_decode($v['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
                $versions[]=['version'=>(int)$v['version_no'],'actor_id'=>(int)$v['actor_id'],
                    'created_at'=>$v['created_at'],'estimate'=>$snap['plan']['totals']['estimated']??'0.00'];}
        }
        return ['history'=>$versions,'authority_id'=>$id,'authority_number'=>$source['ta']['number'],'employee'=>$source['ta']['snapshot']['employee'],
            'station'=>$this->permanentStation($org,$source),
            'travel_from'=>$source['from'],'travel_to'=>$source['to'],
            'activity_title'=>$source['ta']['snapshot']['activity_title']??'',
            'plan_id'=>$record===null?null:(int)$record['id'],'version'=>$record===null?0:(int)$record['version_no'],
            'snapshot'=>$record===null?null:json_decode($record['snapshot_json'],true,512,JSON_THROW_ON_ERROR)];
    }
    /** Only preserve a historical unlinked DTE worksheet if the real stored
     * snapshot is unchanged and the incoming expense change touches NO DTE.
     * Prevents converting an old fare-only edit into a fictitious new allowance.
     */
    private static function preservedLegacyDte(array $prior,array $incoming): ?array {
        if(($prior['ui_mode']??'')==='SINGLE_EXPENSE_ENTRY_V1' ||
           ($incoming['ui_mode']??'')!=='SINGLE_EXPENSE_ENTRY_V1')return null;
        $old=$prior['dte']??null;$new=$incoming['dte']??null;
        if(!is_array($old)||!is_array($new))return null;
        $priorRows=$prior['rows']??[];$newRows=$incoming['rows']??[];
        if(!is_array($priorRows)||!is_array($newRows))return null;
        $cents=static function(mixed $raw): int {
            $value=trim((string)($raw??''));
            return moneyCents($value===''?'0':$value);
        };
        $rowDte=static function(array $rows) use ($cents): array {
            $map=[];
            foreach($rows as $row){
                if(!is_array($row))return [];
                $c=$cents($row['per_diem']??'0');
                if($c>0){$date=(string)($row['date']??'');
                    $map[$date][]=[ $c, (string)($row['per_diem_proof']??''),
                        (string)($row['per_diem_time']??''),
                        (string)($row['per_diem_purpose']??''),
                        (string)($row['per_diem_reference']??'') ];}
            }
            ksort($map);foreach($map as &$amounts)sort($amounts);unset($amounts);
            return $map;
        };
        // Changing or adding any per-diem claim must follow the strict new path.
        if($rowDte($priorRows)!==$rowDte($newRows))return null;
        // Do not let a fare-only edit silently change the DTE policy inputs.
        foreach(['province','region','city_municipality','distance_class',
            'alternate_policy_reference','alternate_policy_effective_date','permanent_station'] as $key)
            if(($old[$key]??'')!==($new[$key]??''))return null;
        $legacy=[];
        foreach(($old['days']??[]) as $day){
            if(!is_array($day))return null;
            if($cents($day['proposed_amount']??'0')>0)
                $legacy[(string)($day['date']??'')]=$day;
        }
        // This compatibility path is only for unmatched positive OLD proposals.
        $unmatched=[];
        $priorDteRowsByDate=$rowDte($priorRows);
        foreach($legacy as $date=>$day){
            $proposed=$cents($day['proposed_amount']);
            $hasMatchingRow=false;
            foreach(($priorDteRowsByDate[$date]??[]) as $savedDteRow)
                if($savedDteRow[0]===$proposed){$hasMatchingRow=true;break;}
            if(!$hasMatchingRow)$unmatched[$date]=$day;
        }
        if(!$unmatched)return null;
        // Every unlinked positive DTE entry sent by the client must be identical
        // to the corresponding verified prior stored record; new entries denied.
        $incomingPositive=[];
        foreach(($new['days']??[]) as $day){
            if(!is_array($day))return null;
            if($cents($day['proposed_amount']??'0')>0){
                $dayDate=(string)($day['date']??'');
                if(isset($incomingPositive[$dayDate]))return null;
                $incomingPositive[$dayDate]=$day;
            }
        }
        if(count($incomingPositive)!==count($legacy))return null;
        foreach($legacy as $date=>$day){
            if(!isset($incomingPositive[$date]))return null;
            // Imported UI records may be normalized earlier; compare the
            // editable source fields and documentary evidence, not computed flags.
            foreach(['date','day_type','proposed_amount','override_reason','meals',
                'return_departure_time','home_arrival_time','return_ticket_reference'] as $field)
                if(($incomingPositive[$date][$field]??null)!==($day[$field]??null))return null;
        }
        return $old; // trustworthy existing snapshot, not a client-supplied override
    }
    public function savePlan(array $data): array {
        $org=$this->org($data,true);$id=idOf($data,'authority_id');$src=$this->authority($org,$id,true);
        $expected=$data['expected_version']??null;
        demand((is_int($expected)||is_string($expected))&&ctype_digit((string)$expected),'Refresh the itinerary before saving',409);
        $rawPlan=$data['plan']??null;
        demand(is_array($rawPlan),'A temporary itinerary is required',422);
        $station=$this->permanentStation($org,$src);
        // The employee/tenant identity comes only from the scoped Travel Authority.
        // Preserve an already saved historical station when a saved revision is
        // edited, otherwise fill it from the dated employee assignment.
        $row=one($this->db,'SELECT id,version_no,snapshot_json FROM travel_itinerary_plans WHERE organization_id=? AND authority_id=? FOR UPDATE',[$org,$id]);
        $priorPlan=null;
        if($row!==null){
            $priorSnapshot=json_decode((string)$row['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            $priorPlan=$priorSnapshot['plan']??null;
        }
        if(isset($rawPlan['dte']) && is_array($rawPlan['dte'])){
            $savedStation='';
            if(is_array($priorPlan)){
                $savedStation=trim((string)($priorPlan['dte']['permanent_station']??''));
            }
            $rawPlan['dte']['permanent_station']=$savedStation!==''?$savedStation:$station['name'];
        }
        $legacyDte=is_array($priorPlan)?self::preservedLegacyDte($priorPlan,$rawPlan):null;
        $plan=TravelExpenseTimeline::plan($rawPlan,$src['from'],$src['to'],$legacyDte);
        $oldVersion=$row===null?0:(int)$row['version_no'];
        demand((int)$expected===$oldVersion,'Temporary itinerary changed in another session. Refresh and reopen.',409);
        $snapshot=['kind'=>'TEMPORARY_TRAVEL_ITINERARY','organization_id'=>$org,
            'authority_id'=>$id,'authority_number'=>$src['ta']['number'],'authority_version'=>$src['ta']['version'],
            'activity_request_id'=>$src['ar']['id'],'employee'=>$src['ta']['snapshot']['employee'],
            'activity_title'=>$src['ta']['snapshot']['activity_title']??'',
            'travel_from'=>$src['from'],'travel_to'=>$src['to'],'plan'=>$plan,
            'not_an_approved_budget'=>true,'payment_authorized'=>false];
        $serialized=json_encode($snapshot,JSON_THROW_ON_ERROR);
        $next=$oldVersion+1;
        if($row===null){
            q($this->db,'INSERT INTO travel_itinerary_plans (organization_id,authority_id,version_no,snapshot_json,created_by,updated_by) VALUES (?,?,?,?,?,?)',
                [$org,$id,$next,$serialized,$this->user['id'],$this->user['id']]);$planId=(int)$this->db->lastInsertId();
        }else{
            $planId=(int)$row['id'];
            $changed=q($this->db,'UPDATE travel_itinerary_plans SET version_no=?,snapshot_json=?,updated_by=? WHERE id=? AND organization_id=? AND version_no=?',
                [$next,$serialized,$this->user['id'],$planId,$org,$oldVersion]);
            demand($changed->rowCount()===1,'Conflicting temporary itinerary save',409);
        }
        q($this->db,'INSERT INTO travel_itinerary_plan_versions (plan_id,organization_id,version_no,snapshot_json,actor_id) VALUES (?,?,?,?,?)',
            [$planId,$org,$next,$serialized,$this->user['id']]);
        $this->audit($org,'TRAVEL_ITINERARY_PLAN',$planId,'TRAVEL_TEMPORARY_ITINERARY_SAVED',
            ['authority_id'=>$id,'previous_version'=>$oldVersion,'next_version'=>$next,'estimate'=>$plan['totals']['estimated'],'legacy_dte_preserved'=>$legacyDte!==null]);
        return ['plan_id'=>$planId,'authority_id'=>$id,'version'=>$next,'estimated'=>$plan['totals']['estimated'],
            'legacy_dte_preserved'=>$legacyDte!==null,'notice'=>'Draft estimate recorded. No official budget, disbursement or reimbursement claim was created.'];
    }
    private function claim(int $org,int $id,bool $lock=false): array {
        $claim=$this->doc($org,'RC',$id,$lock);$s=$claim['snapshot'];
        demand(is_array($s['itinerary']??null)&&($s['itinerary']['format']??'')==='APPENDIX_45',
            'A completed Appendix 45 claim is required before chronological eligibility review',409);
        $src=$this->authority($org,(int)($s['travel_authority_id']??0));
        demand((int)($s['employee']['id']??0)===(int)($src['ta']['snapshot']['employee']['id']??0),
            'Reimbursement claim employee no longer agrees with Travel Authority',409);
        demand(in_array(($s['state']??''),['DRAFT_DOCUMENTS_PENDING','DOCUMENTS_CHECKED_FOR_ACCOUNTING_REVIEW'],true),
            'This claim state is not available for documentary review',409);
        return $claim;
    }
    private function reviewHead(int $org,int $claimId,bool $lock=false): ?array {
        return one($this->db,'SELECT id,source_claim_version,version_no,snapshot_json FROM travel_claim_expense_reviews WHERE organization_id=? AND claim_id=?'.($lock?' FOR UPDATE':''),[$org,$claimId]);
    }
    public function reviewDetail(array $data): array {
        $org=$this->org($data);$id=idOf($data,'claim_id');$claim=$this->claim($org,$id);
        $head=$this->reviewHead($org,$id);
        $current=$head!==null && (int)$head['source_claim_version']===$claim['version'];
        $saved=$current?json_decode($head['snapshot_json'],true,512,JSON_THROW_ON_ERROR):null;
        $plan=one($this->db,'SELECT snapshot_json FROM travel_itinerary_plans WHERE organization_id=? AND authority_id=?',
            [$org,(int)$claim['snapshot']['travel_authority_id']]);
        $planData=$plan===null?null:json_decode($plan['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        $sessions=$saved['schedule']??$planData['plan']['sessions']??[];
        $timeline=TravelExpenseTimeline::actualTimeline($claim['snapshot'],$sessions);
        $old=$saved['decisions']??[];$mapped=[];foreach($old as $v)$mapped[$v['key']]=$v;
        foreach($timeline as &$item)$item['review']=$mapped[$item['key']]??[
            'key'=>$item['key'],'decision'=>'UNREVIEWED','allowed'=>'0.00','reason'=>'',
            'note'=>'','paper_reviewer'=>'','paper_reference'=>''];unset($item);
        $versions=[];
        if($head!==null){
            $history=q($this->db,'SELECT version_no,source_claim_version,snapshot_json,actor_id,created_at FROM travel_claim_expense_review_versions WHERE organization_id=? AND review_id=? ORDER BY version_no DESC LIMIT 20',
                [$org,(int)$head['id']])->fetchAll(PDO::FETCH_ASSOC);
            foreach($history as $v){$snap=json_decode($v['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
                $versions[]=['version'=>(int)$v['version_no'],'claim_version'=>(int)$v['source_claim_version'],
                    'actor_id'=>(int)$v['actor_id'],'created_at'=>$v['created_at'],
                    'preliminary_allowed'=>$snap['totals']['reviewed_allowed']??'0.00'];}
        }
        return ['history'=>$versions,'claim_id'=>$id,'claim_number'=>$claim['number'],'claim_version'=>$claim['version'],
            'review_version'=>$head===null?0:(int)$head['version_no'],'stale_review'=>$head!==null&&!$current,
            'employee'=>$claim['snapshot']['employee']['full_name']??'',
            'travel_from'=>$claim['snapshot']['actual_from']??'', 'travel_to'=>$claim['snapshot']['actual_to']??'',
            'claim_state'=>$claim['snapshot']['state']??'',
            'timeline'=>$timeline,'sessions'=>$sessions,
            'totals'=>$saved['totals']??null,'notice'=>'Preliminary documentary findings only. A receipt or seminar time alone does not prove eligibility; this does not create a payable claim or DV.'];
    }
    public function saveReview(array $data): array {
        $org=$this->org($data,true);$id=idOf($data,'claim_id');$claim=$this->claim($org,$id,true);
        TravelReimbursementLocks::editable($this->db,$org,$id);
        // A travel DV locks its underlying financial source; never permit post-reservation
        // documentary edits that would invalidate accountable voucher history.
        $reserved=one($this->db,'SELECT dv_id FROM travel_dv_claim_links WHERE organization_id=? AND active_claim_id=? LIMIT 1',[$org,$id]);
        demand($reserved===null,'This claim is already linked to DV #'.($reserved['dv_id']??'').'; resolve the voucher before altering the review',409);
        demand((int)($data['expected_claim_version']??-1)===$claim['version'],
            'The claim changed since review began. Reopen the current itinerary.',409);
        $head=$this->reviewHead($org,$id,true);$oldVersion=$head===null?0:(int)$head['version_no'];
        demand((int)($data['expected_review_version']??-1)===$oldVersion,
            'Expense review was changed by another user. Refresh before saving.',409);
        // Reviews are tied to immutable claim version; any revision invalidates
        // old conclusions without deleting the older version records.
        $out=TravelExpenseTimeline::review($data['review']??null,$claim['snapshot'],
            (string)$claim['snapshot']['actual_from'],(string)$claim['snapshot']['actual_to']);
        $out['claim_id']=$id;$out['claim_version']=$claim['version'];
        $out['review_recorded_by']=(int)$this->user['id'];
        $json=json_encode($out,JSON_THROW_ON_ERROR);$next=$oldVersion+1;
        if($head===null){
            q($this->db,'INSERT INTO travel_claim_expense_reviews (organization_id,claim_id,source_claim_version,version_no,snapshot_json,updated_by) VALUES (?,?,?,?,?,?)',
                [$org,$id,$claim['version'],$next,$json,$this->user['id']]);$reviewId=(int)$this->db->lastInsertId();
        }else{
            $reviewId=(int)$head['id'];
            $updated=q($this->db,'UPDATE travel_claim_expense_reviews SET source_claim_version=?,version_no=?,snapshot_json=?,updated_by=? WHERE id=? AND organization_id=? AND version_no=?',
                [$claim['version'],$next,$json,$this->user['id'],$reviewId,$org,$oldVersion]);
            demand($updated->rowCount()===1,'Conflicting expense review save',409);
        }
        q($this->db,'INSERT INTO travel_claim_expense_review_versions (review_id,organization_id,version_no,source_claim_version,snapshot_json,actor_id) VALUES (?,?,?,?,?,?)',
            [$reviewId,$org,$next,$claim['version'],$json,$this->user['id']]);
        $this->audit($org,'TRAVEL_EXPENSE_REVIEW',$reviewId,'TRAVEL_EXPENSE_REVIEW_SAVED',
            ['claim_id'=>$id,'claim_version'=>$claim['version'],'review_version'=>$next,
                'claimed'=>$out['totals']['claimed'],'preliminary_allowed'=>$out['totals']['reviewed_allowed'],
                'unreviewed'=>$out['totals']['unreviewed'],'no_financial_posting'=>true]);
        return ['claim_id'=>$id,'claim_version'=>$claim['version'],'review_version'=>$next,
            'totals'=>$out['totals'],'notice'=>'Chronological documentary findings saved; no reimbursement entitlement, DV, or payment was approved.'];
    }
}
