<?php
declare(strict_types=1);
/** v0.2.411: two-step, same-assessor QR property condition confirmation.
 * Scanning is a locator only. Real account authorization and a physical
 * inspection attestation are required for any proposal or decision.
 * Uses existing document_versions and audit_events; no schema changes.
 */
final class PropertyScanAssessment {
    private const DOC='ASSET_SCAN';
    private const CONDITIONS=['Serviceable','Needs Maintenance','For Inspection','For Repair','Under Repair'];
    public function __construct(private PDO $db,private array $user) {}
    private static function must(bool $ok,string $message,int $status=409):void {demand($ok,$message,$status);}
    private static function text(mixed $input,string $name,int $min,int $max):string {
        self::must(is_string($input),$name.' must be text',422);
        $s=trim($input);self::must(mbSafeLen($s)>=$min&&mbSafeLen($s)<=$max,
            "$name must be {$min}–{$max} characters",422);return $s;
    }
    private function asset(int $assetId,bool $lock=false,bool $strict=true):array {
        self::must($assetId>0,'Choose a registered property',422);
        $row=one($this->db,"SELECT a.id,a.organization_id,a.property_tag,a.property_profile,a.condition_note,a.status,
                o.kind,o.name AS office,o.parent_division_id,o.active AS office_active,i.name AS description
            FROM assets a JOIN organizations o ON o.id=a.organization_id
            JOIN item_catalog i ON i.id=a.item_id WHERE a.id=?".($lock?' FOR UPDATE':''),[$assetId]);
        self::must($row!==null && (int)$row['office_active']===1,'Property record unavailable',404);
        if($strict)self::must($row['status']==='ACTIVE','Only active registered property can be assessed by QR',409);
        $profile=json_decode((string)($row['property_profile']??''),true);
        if($strict)self::must(!is_array($profile) || (($profile['review']['state']??'')!=='PENDING_REVIEW'),
            'Complete the newly received property registration review first',409);
        $life=one($this->db,"SELECT snapshot_json FROM document_versions WHERE organization_id=? AND doc_type='ASSET_LIFECYCLE' AND entity_id=? ORDER BY version_no DESC LIMIT 1",
            [(int)$row['organization_id'],$assetId]);
        if($strict && $life!==null){$l=json_decode((string)$life['snapshot_json'],true);
            self::must(is_array($l)&&($l['state']??'')==='SERVICEABLE',
                'An open unserviceable or disposal process must be resolved through Property Condition & Disposal',409);}
        return $row;
    }
    private static function version(array $asset):string {
        return hash('sha256',implode("\0",[(string)$asset['id'],(string)$asset['organization_id'],
            (string)$asset['property_tag'],(string)$asset['status'],(string)($asset['condition_note']??''),
            (string)($asset['property_profile']??'')]));
    }
    private function access(array $asset,string $mode):void {
        $role=(string)($this->user['role']??'');$org=(int)$asset['organization_id'];
        self::must(in_array($role,['SCHOOL','DIVISION'],true),
            'Use a designated school or Division property account, not technical administration',403);
        if($role==='DIVISION') {
            $division=$asset['kind']==='DIVISION'?(int)$asset['organization_id']:(int)$asset['parent_division_id'];
            self::must($division>0,'School has no active Division assignment',403);
            scope($this->db,$this->user,$division);
            // The Division Property Custodian's formal employee appointment
            // and linked authenticated login are both required.
            $binding=(new PropertyTransferAcceptance($this->db,$this->user))->bindingOptions($division);
            self::must((int)($binding['binding']['user_id']??0)===(int)$this->user['id'] &&
                (int)($binding['binding']['employee_id']??0)===(int)($binding['appointment']['employee_id']??0) &&
                (int)($binding['binding']['appointment_version']??0)===(int)($binding['appointment']['appointment_version']??0),
                'Only the currently appointed and linked Division Property Custodian may assess or confirm this property',403);
            return;
        }
        self::must($role===$asset['kind'],'Select your authorized office account',403);
        scope($this->db,$this->user,$org);
        // School's authenticated, office-scoped administrative login may
        // record and later confirm *its own* physical inspection. Never grant
        // SUPER_ADMIN or cross-school confirmation authority.
    }
    private function head(int $org,int $assetId):array {
        $r=one($this->db,'SELECT id,version_no,snapshot_json FROM document_versions WHERE organization_id=? AND doc_type=? AND entity_id=? ORDER BY version_no DESC LIMIT 1',[$org,self::DOC,$assetId]);
        if(!$r)return ['version'=>0,'state'=>'NONE'];
        $s=json_decode((string)$r['snapshot_json'],true,24,JSON_THROW_ON_ERROR);
        self::must(is_array($s)&&in_array($s['state']??null,['PENDING','APPROVED','REJECTED'],true),
            'Assessment history needs technical review',409);
        return ['version'=>(int)$r['version_no'],'state'=>(string)$s['state'],'snapshot'=>$s];
    }
    private function audit(int $org,int $assetId,string $event,array $details):void {
        q($this->db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
            [$org,(int)$this->user['id'],$event,'ASSET',$assetId,
                json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }
    private function append(int $org,int $assetId,int $version,array $snapshot,string $reason):void {
        q($this->db,'INSERT INTO document_versions(organization_id,doc_type,entity_id,version_no,snapshot_json,reason,actor_id) VALUES(?,?,?,?,?,?,?)',
            [$org,self::DOC,$assetId,$version,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                $reason,(int)$this->user['id']]);
    }
    public function preview(int $assetId):array {
        $a=$this->asset($assetId);$this->access($a,'ASSESS');
        $head=$this->head((int)$a['organization_id'],$assetId);
        return ['asset_id'=>$assetId,'organization_id'=>(int)$a['organization_id'],'office'=>$a['office'],
            'property_number'=>$a['property_tag'],'description'=>$a['description'],
            'condition'=>(string)($a['condition_note']??''),'version'=>self::version($a),
            'pending'=>$head['state']==='PENDING','conditions'=>self::CONDITIONS];
    }
    public function submit(int $assetId,array $p):array {
        $condition=self::text($p['condition']??null,'Proposed condition',3,50);
        self::must(in_array($condition,self::CONDITIONS,true),
            'Unserviceable/disposal must use the formal Property Condition & Disposal workflow',422);
        $remarks=self::text($p['remarks']??null,'Physical findings',12,500);
        $ref=self::text($p['inspection_reference']??null,'Inspection evidence reference',8,160);
        self::must(($p['physically_inspected']??false)===true,'Confirm the property was physically inspected',422);
        $date=(string)($p['inspected_on']??'');
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('Asia/Manila'));
        $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        self::must($parsed!==false && $parsed->format('Y-m-d')===$date && $date<=$today,
            'Select a valid inspection date not in the future',422);
        $expected=self::text($p['expected_version']??null,'Property version',64,64);
        self::must(preg_match('/^[a-f0-9]{64}$/D',$expected)===1,'Reload the latest property record',409);
        self::must(!$this->db->inTransaction(),'Nested property assessment not supported',409);
        $this->db->beginTransaction();try {
            $a=$this->asset($assetId,true);$this->access($a,'ASSESS');$org=(int)$a['organization_id'];
            self::must(hash_equals(self::version($a),$expected),'Property changed since scan; reload to inspect again',409);
            self::must(trim((string)($a['condition_note']??''))!==$condition,'Choose a different condition',422);
            $head=$this->head($org,$assetId);
            self::must($head['state']!=='PENDING','This property already has a pending assessment',409);
            $request=['state'=>'PENDING','submitted_by'=>(int)$this->user['id'],
                'submitted_at'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format(DATE_ATOM),
                'inspected_on'=>$date,'physically_inspected'=>true,'from_condition'=>(string)($a['condition_note']??''),
                'proposed_condition'=>$condition,'asset_version'=>$expected,
                'remarks'=>$remarks,'inspection_reference'=>$ref,'request_version'=>$head['version']+1];
            $this->append($org,$assetId,$head['version']+1,$request,'QR inspection saved; awaiting original assessor confirmation in Action Center');
            $this->audit($org,$assetId,'PROPERTY_SCAN_ASSESSMENT_SUBMITTED',$request);
            $this->db->commit();return ['asset_id'=>$assetId,'state'=>'PENDING','version'=>$head['version']+1,
                'message'=>'Inspection saved for your confirmation in Action Center; official condition unchanged'];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function pending(int $org):array {
        $office=one($this->db,'SELECT id,kind,active FROM organizations WHERE id=?',[$org]);
        self::must($office!==null&&(int)$office['active']===1,'Office not available',403);
        self::must(($this->user['role']??'')===$office['kind'] || $this->user['role']==='SUPER_ADMIN',
            'Use the selected school or Division workspace',403);
        scope($this->db,$this->user,$org);
        // An assessor alone may confirm their pending inspection. The Division
        // custodian can also inspect a school within their appointed Division:
        // route those inspections to their *Division* Action Center, not to a
        // different school account or another Division officer.
        $userId=(int)$this->user['id'];
        $activeUserRole=($this->user['role']??'')===$office['kind'];
        $canConfirm=$activeUserRole;
        if($activeUserRole && $office['kind']==='DIVISION') {
            try{
                $binding=(new PropertyTransferAcceptance($this->db,$this->user))->bindingOptions($org);
                $canConfirm=(int)($binding['binding']['user_id']??0)===$userId &&
                    (int)($binding['binding']['employee_id']??0)===(int)($binding['appointment']['employee_id']??0) &&
                    (int)($binding['binding']['appointment_version']??0)===(int)($binding['appointment']['appointment_version']??0);
            }catch(DomainError $e){$canConfirm=false;}
        }
        if(!$canConfirm)return ['count'=>0,'pending_count'=>0,'rows'=>[],'truncated'=>false];
        $divisionView=$office['kind']==='DIVISION';
        $scopeCondition=$divisionView
            ? '(v.organization_id=? OR (ao.kind=\'SCHOOL\' AND ao.parent_division_id=?))'
            : 'v.organization_id=?';
        $scopeParams=$divisionView?[$org,$org]:[$org];
        $query="FROM document_versions v
            JOIN assets a ON a.id=v.entity_id AND a.organization_id=v.organization_id
            JOIN organizations ao ON ao.id=a.organization_id AND ao.active=1
            WHERE $scopeCondition AND v.doc_type='ASSET_SCAN'
            AND v.version_no=(SELECT MAX(n.version_no)
                FROM document_versions n WHERE n.organization_id=v.organization_id
                    AND n.doc_type=v.doc_type AND n.entity_id=v.entity_id)
            AND JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.state'))='PENDING'
            AND CAST(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.submitted_by')) AS UNSIGNED)=?";
        $params=[...$scopeParams,$userId];
        $count=(int)(one($this->db,'SELECT COUNT(*) n '.$query,$params)['n']??0);
        $rows=q($this->db,'SELECT v.organization_id,v.entity_id AS asset_id,v.version_no,v.snapshot_json,a.property_tag '.$query.
            ' ORDER BY v.id DESC LIMIT 75',$params)->fetchAll(PDO::FETCH_ASSOC);
        $items=[];
        foreach($rows as $r){
            $s=json_decode((string)$r['snapshot_json'],true,24,JSON_THROW_ON_ERROR);
            if(!is_array($s)||((int)($s['submitted_by']??0)!==$userId))continue;
            $items[]=['asset_id'=>(int)$r['asset_id'],'organization_id'=>(int)$r['organization_id'],
                'version'=>(int)$r['version_no'],'property_number'=>$r['property_tag'],
                'from_condition'=>(string)$s['from_condition'],
                'proposed_condition'=>(string)$s['proposed_condition'],
                'submitted_by'=>$userId,'inspected_on'=>(string)$s['inspected_on'],
                'can_decide'=>true];
        }
        return ['count'=>$count,'pending_count'=>$count,'rows'=>$items,'truncated'=>$count>count($items)];
    }
    public function review(int $org,int $assetId):array {
        $a=$this->asset($assetId,false,false);self::must((int)$a['organization_id']===$org,'Property no longer belongs to this office',404);
        $this->access($a,'APPROVE');$head=$this->head($org,$assetId);
        self::must($head['state']==='PENDING','No pending condition assessment',409);
        $s=$head['snapshot'];
        self::must((int)($s['submitted_by']??0)===(int)$this->user['id'],
            'Only the original assessor can confirm this inspection in Action Center',403);
        return ['asset_id'=>$assetId,'organization_id'=>$org,
            'property_number'=>$a['property_tag'],'description'=>$a['description'],'current_condition'=>$a['condition_note'],
            'request_version'=>$head['version'],'from_condition'=>$s['from_condition'],
            'proposed_condition'=>$s['proposed_condition'],'inspected_on'=>$s['inspected_on'],
            'inspection_reference'=>$s['inspection_reference'],'remarks'=>$s['remarks'],
            'submitted_by'=>$s['submitted_by'],'same_actor'=>(int)$s['submitted_by']===(int)$this->user['id']];
    }
    public function decision(int $org,int $assetId,array $p):array {
        $action=self::text($p['decision']??null,'Decision',6,7);
        self::must(in_array($action,['APPROVE','REJECT'],true),'Select Approve or Reject',422);
        $reason=self::text($p['review_reason']??null,'Review basis',8,500);
        $expected=(int)($p['request_version']??0);
        self::must($expected>0,'Review the latest pending request',409);
        self::must(!$this->db->inTransaction(),'Nested approval not supported',409);
        $this->db->beginTransaction();try{
            $a=$this->asset($assetId,true,$action==='APPROVE');
            self::must((int)$a['organization_id']===$org,'Property moved since request',409);
            $this->access($a,'APPROVE');$head=$this->head($org,$assetId);
            self::must($head['state']==='PENDING' && $head['version']===$expected,
                'This assessment has been handled or updated; refresh Action Center',409);
            $s=$head['snapshot'];self::must((int)($s['submitted_by']??0)===(int)$this->user['id'],
                'Only the original assessor can confirm or discard this inspection',403);
            if($action==='APPROVE'){
                self::must(hash_equals((string)$s['asset_version'],self::version($a)),
                    'Property changed after inspection; reject and request a fresh assessment',409);
                $condition=(string)$s['proposed_condition'];
                self::must(in_array($condition,self::CONDITIONS,true),'Invalid condition requiring formal review',409);
                $profile=json_decode((string)($a['property_profile']??''),true);
                if(is_array($profile) && is_array($profile['unit']??null)){
                    $profile['unit']['condition_note']=$condition;
                    $newProfile=json_encode($profile,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                    $changed=q($this->db,"UPDATE assets SET condition_note=?,property_profile=? WHERE id=? AND organization_id=? AND status='ACTIVE'",
                        [$condition,$newProfile,$assetId,$org])->rowCount();
                }else $changed=q($this->db,"UPDATE assets SET condition_note=? WHERE id=? AND organization_id=? AND status='ACTIVE'",
                    [$condition,$assetId,$org])->rowCount();
                self::must($changed===1,'Property changed before approval; no condition change was accepted',409);
            }
            $result=['state'=>$action==='APPROVE'?'APPROVED':'REJECTED','decision_by'=>(int)$this->user['id'],
                'decided_at'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format(DATE_ATOM),
                'decision_reason'=>$reason,'request_version'=>$expected,'submitted_by'=>$s['submitted_by'],
                'confirmation_mode'=>'SAME_ASSESSOR_TWO_STEP',
                'from_condition'=>$s['from_condition'],'proposed_condition'=>$s['proposed_condition'],
                'inspected_on'=>$s['inspected_on'],'inspection_reference'=>$s['inspection_reference'],
                'asset_version'=>$s['asset_version']];
            $this->append($org,$assetId,$head['version']+1,$result,$reason);
            $this->audit($org,$assetId,'PROPERTY_SCAN_ASSESSMENT_'.$result['state'],
                ['proposal'=>$s,'decision'=>$result]);
            $this->db->commit();return ['asset_id'=>$assetId,'state'=>$result['state'],
                'condition_changed'=>$action==='APPROVE'];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
