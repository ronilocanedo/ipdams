<?php
declare(strict_types=1);
/**
 * IPDAMS property lifecycle, v0.2.244.
 * Existing document_versions provide append-only lifecycle decisions, one stream per
 * asset. There are NO new tables, background jobs or silent historical backfills.
 * All state decisions are made while locking the original school asset row.
 * COA/DepEd required physical approvals and evidence are not replaced by this log.
 */
final class PropertyLifecycleWorkflow {
    private const DOC='ASSET_LIFECYCLE'; // fits document_versions.doc_type VARCHAR(16)
    private const OFFICER_KEY='PROPERTY_DIVISION_SUPPLY_OFFICER_V1';
    public function __construct(private PDO $db,private array $actor) {}
    private static function ok(bool $value,string $message,int $http=409):void {demand($value,$message,$http);}
    private static function today():string{return (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');}
    private static function reason(array $p,string $key='reason'):string {
        $r=trim((string)($p[$key]??''));self::ok(mbSafeLen($r)>=8 && mbSafeLen($r)<=500,'Provide a meaningful 8–500 character '.str_replace('_',' ',$key),422);return $r;
    }
    private static function reference(array $p,string $key):string {
        $v=trim((string)($p[$key]??''));self::ok(mbSafeLen($v)>=8&&mbSafeLen($v)<=160,'Document reference required: '.$key,422);return $v;
    }
    private function school(int $id):array {
        self::ok($id>0,'Select an active school',422);
        $r=one($this->db,'SELECT id,name,kind,active,parent_division_id FROM organizations WHERE id=?',[$id]);
        self::ok($r!==null&&$r['kind']==='SCHOOL'&&(int)$r['active']===1&&(int)$r['parent_division_id']>0,'Active school and assigned Division required',403);
        return $r;
    }
    private function schoolWriter(int $school):void {
        self::ok(in_array($this->actor['role']??'', ['SCHOOL','SUPER_ADMIN'],true),'School property writer required',403);
        scope($this->db,$this->actor,$school);$this->school($school);
    }
    private function officerAssignment(int $division):?array {
        $r=one($this->db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',[$division,self::OFFICER_KEY]);
        if($r===null)return null;
        $a=json_decode((string)$r['config_json'],true,32,JSON_THROW_ON_ERROR);
        self::ok(is_array($a) && (int)($a['division_id']??0)===$division && (int)($a['user_id']??0)>0,'Division officer appointment needs review',409);
        return ['version_id'=>(int)$r['id'],'user_id'=>(int)$a['user_id']];
    }
    private function officer(int $school):array {
        $s=$this->school($school);$division=(int)$s['parent_division_id'];
        self::ok(($this->actor['role']??'')==='DIVISION','Only the appointed Division Supply Officer may approve this request',403);
        // Check the division scope on every request; do not rely on SUPER_ADMIN bypass.
        scope($this->db,$this->actor,$division);
        $a=$this->officerAssignment($division);
        self::ok($a!==null && $a['user_id']===(int)$this->actor['id'],'This Division Supply Officer appointment is not assigned to your account',403);
        return $a;
    }
    private function asset(int $school,int $asset,bool $lock):array {
        $r=one($this->db,'SELECT a.id,a.organization_id,a.property_tag,a.acquisition_cost,a.condition_note,a.custodian,a.status,a.property_profile,i.name AS item_name,i.category FROM assets a JOIN item_catalog i ON i.id=a.item_id WHERE a.id=? AND a.organization_id=?'.($lock?' FOR UPDATE':''),[$asset,$school]);
        self::ok($r!==null,'Property not found in this school',404);
        self::ok($r['category']==='SEMI_EXPENDABLE','This workflow handles semi-expendable items only; PPE requires its own policy',409);
        self::ok($r['acquisition_cost']!==null && preg_match('/^\d{1,14}\.\d{2}$/D',(string)$r['acquisition_cost'])===1,'Verified acquisition cost is required to classify the item',409);
        $cents=moneyCents((string)$r['acquisition_cost']);self::ok($cents>0 && $cents<5000000,'Positive verified semi-expendable acquisition cost below PPE threshold required',409);
        $r['value_class']=$cents<=500000?'LOW':'HIGH';return $r;
    }
    private function head(int $school,int $asset):array {
        $r=one($this->db,'SELECT version_no,snapshot_json FROM document_versions WHERE organization_id=? AND doc_type=? AND entity_id=? ORDER BY version_no DESC LIMIT 1',[$school,self::DOC,$asset]);
        if($r===null)return ['version'=>0,'state'=>'SERVICEABLE','history'=>[], 'last_actor'=>0];
        $snap=json_decode((string)$r['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
        self::ok(is_array($snap)&&in_array($snap['state']??null,['SERVICEABLE','PENDING_UNSERVICEABLE','UNSERVICEABLE','PENDING_DISPOSAL','DISPOSAL_APPROVED','DISPOSED'],true),'Lifecycle history requires administrator review',409);
        return ['version'=>(int)$r['version_no']]+$snap;
    }
    private function event(int $school,int $asset,array $previous,string $state,string $action,string $reason,array $extra=[]):array {
        $n=$previous['version']+1;
        $snapshot=['state'=>$state,'action'=>$action,'recorded_at'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format(DATE_ATOM),
            'actor_id'=>(int)$this->actor['id'],'previous_state'=>$previous['state'],'previous_version'=>$previous['version']]+$extra;
        q($this->db,'INSERT INTO document_versions (organization_id,doc_type,entity_id,version_no,snapshot_json,reason,actor_id) VALUES (?,?,?,?,?,?,?)',
            [$school,self::DOC,$asset,$n,json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$reason,(int)$this->actor['id']]);
        q($this->db,'INSERT INTO audit_events (organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
            [$school,(int)$this->actor['id'],'PROPERTY_'.strtoupper($action),'ASSET',$asset,json_encode(['lifecycle_version'=>$n,'from'=>$previous['state'],'to'=>$state,'reason'=>$reason]+$extra,JSON_THROW_ON_ERROR)]);
        return ['version'=>$n]+$snapshot;
    }
    private function transaction(callable $fn):mixed {
        self::ok(!$this->db->inTransaction(),'Nested lifecycle changes are unsupported',409);
        $this->db->beginTransaction();try{$r=$fn();$this->db->commit();return $r;}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function saveOfficer(int $division,int $userId,string $reason):array {
        self::ok(($this->actor['role']??'')==='SUPER_ADMIN','Security administrator required',403);
        self::ok($division>0&&$userId>0,'Select a Division and Division user',422);
        self::ok(mbSafeLen($reason)>=8&&mbSafeLen($reason)<=500,'Record the appointment authority',422);
        return $this->transaction(function()use($division,$userId,$reason){
            $d=one($this->db,"SELECT id FROM organizations WHERE id=? AND kind='DIVISION' AND active=1 FOR UPDATE",[$division]);self::ok($d!==null,'Active Division required',422);
            $u=one($this->db,"SELECT id,full_name FROM users WHERE id=? AND role='DIVISION' AND active=1",[$userId]);self::ok($u!==null,'Select an active Division user, not an administrator',422);
            self::ok(one($this->db,'SELECT 1 FROM user_organization_scopes WHERE organization_id=? AND user_id=?',[$division,$userId])!==null,'Selected officer must be directly authorized for the Division',403);
            $before=$this->officerAssignment($division);
            self::ok(($before['user_id']??0)!==$userId,'This appointment is already recorded',409);
            $json=json_encode(['division_id'=>$division,'user_id'=>$userId,'appointed_by'=>(int)$this->actor['id'],'authority_reference'=>$reason],JSON_THROW_ON_ERROR);
            q($this->db,'INSERT INTO configuration_versions (organization_id,config_key,config_json,effective_from,review_note) VALUES (?,?,?,?,?)',[$division,self::OFFICER_KEY,$json,self::today(),$reason]);
            $version=(int)$this->db->lastInsertId();
            q($this->db,'INSERT INTO audit_events (organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',[$division,(int)$this->actor['id'],'PROPERTY_DIVISION_OFFICER_APPOINTED','USER',$userId,json_encode(['previous'=>$before,'appointment_version_id'=>$version,'authority_reference'=>$reason],JSON_THROW_ON_ERROR)]);
            return ['division_id'=>$division,'officer_user_id'=>$userId,'appointment_version_id'=>$version];
        });
    }
    public function perform(int $school,int $assetId,string $action,array $p):array {
        self::ok(in_array($action,['declare_unserviceable','approve_unserviceable','reject_unserviceable','request_disposal','approve_disposal','reject_disposal','complete_disposal'],true),'Unrecognized property lifecycle action',422);
        $officerAction=in_array($action,['approve_unserviceable','reject_unserviceable'],true);
        if($officerAction)$assignment=$this->officer($school);
        elseif($action==='approve_disposal'||$action==='reject_disposal'){
            // Cost class checked again under row lock below; role is checked there.
        }else $this->schoolWriter($school);
        $reason=self::reason($p);
        return $this->transaction(function()use($school,$assetId,$action,$p,$reason){
            $a=$this->asset($school,$assetId,true);
            $head=$this->head($school,$assetId);$state=$head['state'];$low=$a['value_class']==='LOW';
            self::ok($a['status']!=='DISPOSED'&&$state!=='DISPOSED','Already disposed; original record is archived',409);
            self::ok($a['status']==='ACTIVE','Asset status requires reconciliation before lifecycle changes',409);
            // A prepared transfer must not coexist with a new unserviceability/disposal
            // declaration. This check and the transfer's own checks are both under
            // the same asset row lock, so concurrent requests serialize safely.
            if($action==='declare_unserviceable'){
                $pendingLinks=q($this->db,"SELECT target_id FROM document_links WHERE organization_id=? AND source_type='ASSET' AND source_id=? AND target_type IN ('ITR','PTR') ORDER BY id DESC LIMIT 100",[$school,$assetId])->fetchAll(PDO::FETCH_ASSOC);
                self::ok(count($pendingLinks)<100,'Too many transfer history links; technical review required',409);
                foreach($pendingLinks as $link){
                    $transfer=one($this->db,"SELECT snapshot_json FROM document_versions WHERE organization_id=? AND doc_type IN ('ITR','PTR') AND entity_id=? ORDER BY id DESC LIMIT 1",[$school,(int)$link['target_id']]);
                    if($transfer!==null){$snap=json_decode((string)$transfer['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
                        self::ok(is_array($snap)&&($snap['status']??'')!=='PENDING_RECEIPT','Finish or cancel the outstanding property transfer before changing condition',409);}
                }
            }
            if(in_array($action,['approve_unserviceable','reject_unserviceable'],true)){
                self::ok(!$low,'Low-value declarations do not need Division approval',409);
                $assignment=$this->officer($school);
                self::ok($state==='PENDING_UNSERVICEABLE','No pending unserviceable declaration',409);
                self::ok((int)($head['actor_id']??0)!==(int)$this->actor['id'],'Requester cannot approve their own request',403);
                $next=$action==='approve_unserviceable'?'UNSERVICEABLE':'SERVICEABLE';
                $extra=['officer_appointment_version_id'=>$assignment['version_id']];
            }elseif(in_array($action,['approve_disposal','reject_disposal'],true)){
                if($low)$this->schoolWriter($school);else $assignment=$this->officer($school);
                self::ok($state==='PENDING_DISPOSAL','No pending disposal request',409);
                if(!$low)self::ok((int)($head['actor_id']??0)!==(int)$this->actor['id'],'Requester cannot approve their own disposal request',403);
                $next=$action==='approve_disposal'?'DISPOSAL_APPROVED':'UNSERVICEABLE';
                $extra=$low?['school_approval'=>true]:['officer_appointment_version_id'=>$assignment['version_id']];
            }else{
                if($action==='declare_unserviceable'){
                    self::ok($state==='SERVICEABLE','Only serviceable assets can be submitted for unserviceability',409);
                    $next=$low?'UNSERVICEABLE':'PENDING_UNSERVICEABLE';$extra=['value_class'=>$a['value_class'],'acquisition_cost'=>$a['acquisition_cost']];
                }elseif($action==='request_disposal'){
                    self::ok($state==='UNSERVICEABLE','Declare unserviceable before starting disposal',409);
                    $next='PENDING_DISPOSAL';$extra=['value_class'=>$a['value_class']];
                }else{
                    self::ok($state==='DISPOSAL_APPROVED','Disposal requires a separately approved request',409);
                    $reference=self::reference($p,'disposal_reference');
                    $date=(string)($p['completed_on']??'');
                    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('Asia/Manila'));
                    self::ok($parsed!==false && $parsed->format('Y-m-d')===$date && $date<=self::today(),'Valid disposal completion date cannot be in the future',422);
                    self::ok($date>=substr((string)($head['recorded_at']??''),0,10),'Completion cannot precede the disposal approval',409);
                    $returnRef=trim((string)($p['return_reference']??''));
                    if(trim((string)($a['custodian']??''))!=='')$returnRef=self::reference($p,'return_reference');
                    $count=q($this->db,"UPDATE assets SET status='DISPOSED',custodian=NULL WHERE id=? AND organization_id=? AND status='ACTIVE'",[$assetId,$school])->rowCount();
                    self::ok($count===1,'Asset changed during disposal completion',409);
                    q($this->db,'INSERT INTO asset_movements(asset_id,event_type,from_custodian,to_custodian,reason,actor_id) VALUES (?,?,?,?,?,?)',[$assetId,'DISPOSAL',$a['custodian'],null,'Approved disposal: '.$reference.'; '.$reason,(int)$this->actor['id']]);
                    $next='DISPOSED';$extra=['disposal_reference'=>$reference,'completed_on'=>$date,'physical_return_reference'=>$returnRef];
                }
            }
            $saved=$this->event($school,$assetId,$head,$next,$action,$reason,$extra);
            return ['asset_id'=>$assetId,'property_tag'=>$a['property_tag'],'value_class'=>$a['value_class'],'state'=>$next,'version'=>$saved['version']];
        });
    }
    public function listForSchool(int $school):array {
        $s=$this->school($school);
        $isSchool=in_array($this->actor['role']??'',['SCHOOL','SUPER_ADMIN'],true);
        if($isSchool)$this->schoolWriter($school);else $this->officer($school);
        $rows=q($this->db,"SELECT a.id,a.property_tag,a.acquisition_cost,a.custodian,a.status,i.name AS item_name,i.category FROM assets a JOIN item_catalog i ON i.id=a.item_id WHERE a.organization_id=? AND i.category='SEMI_EXPENDABLE' ORDER BY a.id DESC LIMIT 250",[$school])->fetchAll(PDO::FETCH_ASSOC);
        $out=[];foreach($rows as $a){
            $cost=(string)($a['acquisition_cost']??'');
            if(!preg_match('/^\d{1,14}\.\d{2}$/D',$cost)){$a['value_class']='REVIEW';$a['state']='REVIEW_COST';}
            else { $a['value_class']=moneyCents($cost)===0?'REVIEW':(moneyCents($cost)<=500000?'LOW':(moneyCents($cost)<5000000?'HIGH':'PPE_REVIEW'));
                $a['state']=$a['status']==='DISPOSED'?'DISPOSED':$this->head($school,(int)$a['id'])['state']; }
            $out[]=$a;
        }
        return ['school'=>$s,'rows'=>$out,'role'=>$this->actor['role'],'appointed_officer'=>!$isSchool];
    }
    public function divisionsForAdmin():array {
        self::ok($this->actor['role']==='SUPER_ADMIN','Administrator only',403);
        $ds=q($this->db,"SELECT id,name FROM organizations WHERE kind='DIVISION' AND active=1 ORDER BY name LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        $users=q($this->db,"SELECT u.id,u.full_name,u.email,o.id AS division_id FROM users u JOIN user_organization_scopes s ON s.user_id=u.id JOIN organizations o ON o.id=s.organization_id AND o.kind='DIVISION' WHERE u.role='DIVISION' AND u.active=1 AND o.active=1 ORDER BY o.name,u.full_name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
        foreach($ds as &$d)$d['assigned']=$this->officerAssignment((int)$d['id']);unset($d);
        return ['divisions'=>$ds,'eligible_users'=>$users];
    }
}
