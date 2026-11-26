<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/AnnualDocumentNumbering.php';
require_once __DIR__.'/FinancialCutoff.php';
/**
 * Activity Request & Travel Authority PREPARATION ONLY, v0.2.104.
 * Reuses document registry/immutable version ledger, with registry row as its own
 * entity identity. Does NOT confirm signatures, approval, an obligation or DV.
 * A travel document's issue date is not the planned activity date.
 */
final class TravelWorkflow {
    public function __construct(private PDO $db,private array $user){}
    private function organization(array $data,bool $write=false): int {
        $org=idOf($data,'organization_id');scope($this->db,$this->user,$org);
        if($write){
            allowedToWrite($this->user);
            $o=one($this->db,'SELECT kind FROM organizations WHERE id=? AND active=1',[$org]);
            demand($o!==null && ($this->user['role']==='SUPER_ADMIN' ||
                ($this->user['role']==='SCHOOL'&&$o['kind']==='SCHOOL') ||
                ($this->user['role']==='DIVISION'&&$o['kind']==='DIVISION')),
                'Selected workspace is not authorized for operational changes',403);
        }
        return $org;
    }
    private static function date(mixed $date,string $field,bool $allowFuture=false): string {
        demand(is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)===1,'Invalid '.$field);
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        demand($dt && $dt->format('Y-m-d')===$date,'Invalid '.$field);
        demand((int)$dt->format('Y')>=1900 && (int)$dt->format('Y')<=2100,'Invalid '.$field);
        if(!$allowFuture)demand($date<=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'),$field.' cannot be in the future');
        return $date;
    }
    private static function optionalString(array $data,string $key,int $max): string {
        $value=trim((string)($data[$key]??''));demand(mbSafeLen($value)<=$max,'Invalid '.$key);return $value;
    }
    private function employee(int $org,int $id): array {
        $e=one($this->db,'SELECT e.id,e.full_name,e.position_title,a.designation,a.effective_from,a.effective_to FROM employees e JOIN employee_assignments a ON a.employee_id=e.id WHERE e.id=? AND a.organization_id=? ORDER BY a.id DESC LIMIT 1',[$id,$org]);
        $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        demand($e!==null && $e['effective_from']<=$today && ($e['effective_to']===null || $e['effective_to']>$today),
            'Choose an active employee from the current workspace',422);
        return ['id'=>(int)$e['id'],'full_name'=>$e['full_name'],'position_title'=>$e['position_title']??'', 'designation'=>$e['designation']];
    }
    private function fund(int $org,int $id): array {
        $fund=one($this->db,'SELECT id,name,source_code,fiscal_year FROM funds WHERE id=? AND organization_id=?',[$id,$org]);
        demand($fund!==null,'Choose a valid funding source for this workspace');return $fund;
    }
    // Chargeability is documentary at this stage. No outside office's fund can
    // be certified, obligated, or disbursed from the school's ledger.
    private static function chargeability(array $data,int $org,self $service): array {
        $code=strtoupper(trim((string)($data['chargeable_to']??'SCHOOL_MOOE')));
        $labels=['SCHOOL_MOOE'=>'School MOOE','DIVISION_OFFICE'=>'Division Office',
            'REGIONAL_OFFICE'=>'Regional Office','SPLIT'=>'Split / Multiple funding offices'];
        demand(isset($labels[$code]),'Choose a valid office to which the travel is chargeable',422);
        $rawFund=$data['fund_id']??null;
        $hasFund=$rawFund!==null && $rawFund!=='' && $rawFund!==0 && $rawFund!=='0';
        if($code==='SCHOOL_MOOE')demand($hasFund,'Select the school funding source',422);
        if(in_array($code,['DIVISION_OFFICE','REGIONAL_OFFICE'],true))
            demand(!$hasFund,'External-office travel cannot be assigned a school funding source',422);
        $fund=$hasFund?$service->fund($org,idOf($data,'fund_id')):null;
        $reference=self::optionalString($data,'chargeability_reference',255);
        if($code==='SPLIT')demand($reference!=='','Describe the funding-office allocation for split expenses',422);
        return ['code'=>$code,'label'=>$labels[$code],'reference'=>$reference,'school_fund'=>$fund===null?null:[
            'id'=>(int)$fund['id'],'name'=>$fund['name'],'source_code'=>$fund['source_code']]];
    }
    /** Division-approved letter signatories. Schools may read ONLY the configured
     * approved office-holder names and heading, not the Division staff roster.
     * Previous snapshots remain untouched when an appointment changes. */
    private const SIGNATORY_KEY='travel_activity_request_signatories';
    // Travel forms retain the original four mandatory roles. SGOD is an optional
    // report signatory in the same versioned Division configuration.
    private const SIGNATORY_ROLES=['budget_officer','division_accountant','asds','sds'];
    private const REPORT_SIGNATORY_ROLES=['sgod_chief'];
    private function divisionFor(int $org): int {
        $o=one($this->db,'SELECT id,kind,parent_division_id FROM organizations WHERE id=? AND active=1',[$org]);
        demand($o!==null,'Workspace organization not found',404);
        $division=$o['kind']==='DIVISION'?(int)$o['id']:(int)($o['parent_division_id']??0);
        demand($division>0,'School must be linked to a Division for travel signatories',409);
        $parent=one($this->db,"SELECT id FROM organizations WHERE id=? AND kind='DIVISION' AND active=1",[$division]);
        demand($parent!==null,'The associated Division is unavailable',409);
        return $division;
    }
    private function signatoryConfig(int $org): array {
        $division=$this->divisionFor($org);
        $row=one($this->db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',[$division,self::SIGNATORY_KEY]);
        if($row===null)return ['division_id'=>$division,'version_id'=>0,'region_heading'=>'','salutation'=>'Sir/Madam:','roles'=>[]];
        $data=json_decode((string)$row['config_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($data) && is_array($data['roles']??null),'Travel signatory configuration requires administrative review',409);
        return ['division_id'=>$division,'version_id'=>(int)$row['id'],
            'region_heading'=>(string)($data['region_heading']??''),
            'salutation'=>(string)($data['salutation']??'Sir/Madam:'),
            'roles'=>$data['roles']];
    }
    private function currentDivisionStaff(int $division,int $employee): array {
        $r=one($this->db,'SELECT a.employee_id,a.designation,a.effective_from,a.effective_to,e.full_name,e.position_title FROM employee_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.organization_id=? AND a.employee_id=? ORDER BY a.id DESC LIMIT 1',[$division,$employee]);
        $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        demand($r!==null && (string)$r['effective_from']<=$today && ($r['effective_to']===null || (string)$r['effective_to']>$today),'Select an active Division employee for each signature role',422);
        return ['employee_id'=>(int)$r['employee_id'],'full_name'=>$r['full_name'],
            'position_title'=>$r['position_title']??'',
            'designation'=>$r['designation']?:($r['position_title']??'')];
    }
    /** This is a scoped office directory, not a signature or approval. */
    public function signatories(array $params): array {
        $org=$this->organization($params);$config=$this->signatoryConfig($org);
        $isDivision=$org===$config['division_id'];
        $canManage=$isDivision && in_array($this->user['role'],['DIVISION','SUPER_ADMIN'],true);
        $roles=[];
        foreach(array_merge(self::SIGNATORY_ROLES,self::REPORT_SIGNATORY_ROLES) as $role){
            $row=$config['roles'][$role]??null;
            $roles[$role]=is_array($row)?$row:null;
        }
        $result=['division_id'=>$config['division_id'],'version_id'=>$config['version_id'],
            'region_heading'=>$config['region_heading'],'salutation'=>$config['salutation'],
            'roles'=>$roles,'can_manage'=>$canManage,'staff'=>[]];
        if($canManage){
            $staff=q($this->db,'SELECT a.employee_id,a.designation,a.effective_from,a.effective_to,e.full_name,e.position_title FROM employee_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.organization_id=? ORDER BY a.id DESC',[$org])->fetchAll();
            $seen=[];$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
            foreach($staff as $s){$id=(int)$s['employee_id'];if(isset($seen[$id]))continue;$seen[$id]=true;
                if((string)$s['effective_from']<=$today && ($s['effective_to']===null || (string)$s['effective_to']>$today))
                    $result['staff'][]=['employee_id'=>$id,'full_name'=>$s['full_name'],
                        'position_title'=>$s['position_title']??'','designation'=>$s['designation']??''];
            }
            usort($result['staff'],static fn($a,$b)=>strnatcasecmp((string)$a['full_name'],(string)$b['full_name']));
        }
        return $result;
    }
    /** Uses the existing immutable configuration_versions and audit_events
     * ledgers; must be invoked inside api/index.php's transaction. */
    public function saveSignatories(array $data): array {
        $org=$this->organization($data,true);
        $division=$this->divisionFor($org);
        demand($org===$division && in_array($this->user['role'],['DIVISION','SUPER_ADMIN'],true),
            'Only authorized Division staff may configure Division signatories',403);
        demand(FinancialCutoff::isCoordinated($this->db),'Division report signatories require a coordinated READ COMMITTED transaction',409);
        FinancialCutoff::beginWriter($this->db,$this->user,'travel.signatories.save',$data);
        one($this->db,'SELECT id FROM organizations WHERE id=? FOR UPDATE',[$division]);
        $prev=$this->signatoryConfig($org);
        demand(isset($data['expected_version']) && is_numeric($data['expected_version']) &&
            (int)$data['expected_version']===$prev['version_id'],
            'Division signatories changed. Refresh before saving.',409);
        $region=requiredString($data,'region_heading',160);
        $salutation=requiredString($data,'salutation',15);
        demand(in_array($salutation,['Madam:','Sir:','Sir/Madam:'],true),'Choose a valid official salutation');
        // Appointments are versioned and audited; users are not asked for a change reason.
        $reason='Division signatories updated';
        $roles=[];
        demand(is_array($data['roles']??null),'Provide one approved Division employee for each signatory');
        foreach(self::SIGNATORY_ROLES as $role){
            $raw=$data['roles'][$role]??null;
            demand((is_int($raw) && $raw>0) || (is_string($raw) && ctype_digit($raw) && (int)$raw>0),'Choose the Division officer for '.str_replace('_',' ',$role));
            $roles[$role]=$this->currentDivisionStaff($division,(int)$raw);
        }
        // Older clients omit optional report roles. Preserve the saved appointment
        // in that case, rather than silently clearing it. An explicit empty value
        // clears the appointment; a new ID must be an active Division employee.
        foreach(self::REPORT_SIGNATORY_ROLES as $role){
            if(!array_key_exists($role,$data['roles'])){
                if(array_key_exists($role,$prev['roles']))$roles[$role]=$prev['roles'][$role];
                continue;
            }
            $raw=$data['roles'][$role];
            if($raw===null||$raw===''){
                if(isset($prev['roles'][$role]))$roles[$role]=null;
                elseif(array_key_exists($role,$prev['roles']))$roles[$role]=$prev['roles'][$role];
                continue;
            }
            demand((is_int($raw)&&$raw>0)||(is_string($raw)&&ctype_digit($raw)&&(int)$raw>0),'Choose an active Division employee for '.str_replace('_',' ',$role));
            $roles[$role]=$this->currentDivisionStaff($division,(int)$raw);
        }
        $record=['region_heading'=>$region,'salutation'=>$salutation,'roles'=>$roles];
        $prior=['region_heading'=>$prev['region_heading'],'salutation'=>$prev['salutation'],'roles'=>$prev['roles']];
        demand($record!==$prior,'No change to Division signatories');
        q($this->db,'INSERT INTO configuration_versions (organization_id,config_key,config_json,effective_from,review_note) VALUES (?,?,?,?,?)',
            [$division,self::SIGNATORY_KEY,json_encode($record,JSON_THROW_ON_ERROR),
                (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'),$reason]);
        $version=(int)$this->db->lastInsertId();
        $this->audit($division,'TRAVEL_SIGNATORIES_CONFIGURED','TRAVEL_SIGNATORIES',$version,
            ['before_version'=>$prev['version_id'],'officer_ids'=>array_map(static fn($r)=>$r['employee_id']??null,$roles),'reason'=>$reason]);
        return ['version_id'=>$version,'division_id'=>$division];
    }
    private function snapshottedSignatories(int $org): array {
        $config=$this->signatoryConfig($org);
        $snapshot=['version_id'=>$config['version_id'],'division_id'=>$config['division_id'],
            'region_heading'=>$config['region_heading'],'salutation'=>$config['salutation'],'roles'=>[]];
        foreach(self::SIGNATORY_ROLES as $role){
            $saved=$config['roles'][$role]??null;$official=null;
            if(is_array($saved)&&!empty($saved['employee_id'])){
                try{$official=$this->currentDivisionStaff($config['division_id'],(int)$saved['employee_id']);}
                catch(DomainError $ignored){/* An inactive role MUST NOT print a prior official as current. */}
            }
            $snapshot['roles'][$role]=$official;
        }
        return $snapshot;
    }
    private function register(int $org,string $type): array {
        $period=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m');
        $number=AnnualDocumentNumbering::reserve($this->db,$org,$type,$period);
        // Registry ID is also its durable entity ID. The temporary zero cannot
        // leave the transaction: a row is immediately self-bound and snapshotted.
        q($this->db,'INSERT INTO document_registry (organization_id,doc_type,period,display_number,entity_id) VALUES (?,?,?,?,0)',[$org,$type,$period,$number]);
        $id=(int)$this->db->lastInsertId();demand($id>0,'Document number could not be allocated',500);
        $bound=q($this->db,'UPDATE document_registry SET entity_id=? WHERE id=? AND organization_id=? AND entity_id=0',[$id,$id,$org]);
        demand($bound->rowCount()===1,'Could not bind document identity',500);
        return ['id'=>$id,'number'=>$number];
    }
    private function version(int $org,string $type,int $id,array $payload,string $reason): int {
        $v=one($this->db,'SELECT COALESCE(MAX(version_no),0) AS version FROM document_versions WHERE organization_id=? AND doc_type=? AND entity_id=?',[$org,$type,$id]);
        $next=(int)($v['version']??0)+1;
        q($this->db,'INSERT INTO document_versions (organization_id,doc_type,entity_id,version_no,snapshot_json,reason,actor_id) VALUES (?,?,?,?,?,?,?)',
            [$org,$type,$id,$next,json_encode($payload,JSON_THROW_ON_ERROR),$reason,$this->user['id']]);
        return $next;
    }
    private function audit(int $org,string $event,string $type,int $id,array $details): void {
        q($this->db,'INSERT INTO audit_events (organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
            [$org,$this->user['id'],$event,$type,$id,json_encode($details,JSON_THROW_ON_ERROR)]);
    }
    private function record(int $org,string $type,int $id,bool $lock=false): array {
        demand(in_array($type,['AR','TA'],true),'Unknown travel document');
        $row=one($this->db,'SELECT id,display_number,created_at FROM document_registry WHERE id=? AND organization_id=? AND doc_type=? AND entity_id=?'.($lock?' FOR UPDATE':''),[$id,$org,$type,$id]);
        demand($row!==null,'Travel document not found in this workspace',404);
        $v=one($this->db,'SELECT version_no,snapshot_json,created_at FROM document_versions WHERE organization_id=? AND doc_type=? AND entity_id=? ORDER BY version_no DESC LIMIT 1',[$org,$type,$id]);
        demand($v!==null,'Travel document snapshot unavailable',409);
        $payload=json_decode((string)$v['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($payload)&&($payload['kind']??'')===$type && (int)($payload['organization_id']??0)===$org,'Travel document snapshot invalid',409);
        return ['id'=>$id,'number'=>$row['display_number'],'created_at'=>$row['created_at'],'version'=>(int)$v['version_no'],'saved_at'=>$v['created_at'],'snapshot'=>$payload];
    }
    private function requestPayload(int $org,array $data): array {
        $employee=$this->employee($org,idOf($data,'employee_id'));
        $charge=self::chargeability($data,$org,$this);
        $fund=$charge['school_fund'];
        $issued=self::date(requiredString($data,'request_date',10),'Activity Request date');
        $from=self::date(requiredString($data,'travel_from',10),'Planned travel start',true);
        $to=self::date(requiredString($data,'travel_to',10),'Planned travel end',true);
        demand($to>=$from,'Travel end must not precede travel start');
        demand((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days<=120,'Planned travel exceeds 120 days; obtain administrator review');
        // The prescribed Annex A amount is calculated, handwritten and countersigned
        // by authorized Division personnel AFTER printing. Never accept a travel
        // participant's cost estimate, even from a modified/older browser/API call.
        $submittedEstimate=$data['estimated_cost']??null;
        demand($submittedEstimate===null ||
            (is_string($submittedEstimate) && trim($submittedEstimate)===''),
            'Estimated travel expenses must remain blank for Division calculation and countersignature',422);
        $purpose=requiredString($data,'purpose',600);
        return ['kind'=>'AR','organization_id'=>$org,'document_date'=>$issued,
            'employee'=>$employee,'fund'=>$fund,'chargeability'=>$charge,
            'signatories'=>is_array($data['_preserved_signatories']??null)?$data['_preserved_signatories']:$this->snapshottedSignatories($org),
            'activity_title'=>requiredString($data,'activity_title',240),'host'=>self::optionalString($data,'host',200),
            'purpose'=>$purpose,'destination'=>requiredString($data,'destination',240),
            'travel_from'=>$from,'travel_to'=>$to,'estimated_cost'=>'',
            'itinerary_reference'=>requiredString($data,'itinerary_reference',255),
            'supporting_reference'=>self::optionalString($data,'supporting_reference',255),
            'state'=>'DRAFT_FOR_MANUAL_SIGNATURE','cash_advance_requested'=>false,
            'certification'=>['allotment'=>'SIGNATURE_REQUIRED','funds'=>'SIGNATURE_REQUIRED',
                'recommendation'=>'SIGNATURE_REQUIRED','approval'=>'SIGNATURE_REQUIRED'],
            'payment_route'=>'REIMBURSEMENT_ONLY',
            'disclaimer'=>'Preparation only; not evidence of approval or certification.'];
    }
    public function createRequest(array $data): array {
        $org=$this->organization($data,true);$doc=$this->requestPayload($org,$data);
        $reg=$this->register($org,'AR');$doc['number']=$reg['number'];
        $this->version($org,'AR',$reg['id'],$doc,'Original draft, manual signatures required');
        $this->audit($org,'TRAVEL_REQUEST_DRAFT_CREATED','AR',$reg['id'],['number'=>$reg['number'],'employee_id'=>$doc['employee']['id'],'fund_id'=>$doc['fund']['id']??null,'chargeable_to'=>$doc['chargeability']['code']]);
        return ['id'=>$reg['id'],'number'=>$reg['number'],'version'=>1,'status'=>'DRAFT_FOR_MANUAL_SIGNATURE'];
    }
    public function reviseRequest(array $data): array {
        $org=$this->organization($data,true);$id=idOf($data,'request_id');
        $prior=$this->record($org,'AR',$id,true);
        demand((int)($data['expected_version']??-1)===$prior['version'],'Activity Request was changed. Refresh before saving.',409);
        $ta=one($this->db,"SELECT target_id FROM document_links WHERE organization_id=? AND source_type='AR' AND source_id=? AND target_type='TA' LIMIT 1",[$org,$id]);
        demand($ta===null,'Activity Request is locked because a Travel Authority has been prepared',409);
        // Preserve assigned names in audited revisions; do not silently change them when Division appointments change.
        $data['_preserved_signatories']=$prior['snapshot']['signatories']??$this->snapshottedSignatories($org);
        $reason=requiredString($data,'reason',300);$next=$this->requestPayload($org,$data);
        $next['number']=$prior['number'];
        demand($next!==$prior['snapshot'],'There are no document changes to save');
        $v=$this->version($org,'AR',$id,$next,$reason);
        $this->audit($org,'TRAVEL_REQUEST_DRAFT_REVISED','AR',$id,['from_version'=>$prior['version'],'to_version'=>$v,'reason'=>$reason]);
        return ['id'=>$id,'number'=>$prior['number'],'version'=>$v];
    }
    /** Explicit audited correction for previously drafted AR signatory snapshots.
     * Never revise linked Travel Authorities or paper-approved versions silently. */
    public function refreshRequestSignatories(array $data): array {
        $org=$this->organization($data,true);$id=idOf($data,'request_id');
        $prior=$this->record($org,'AR',$id,true);
        demand(isset($data['expected_version']) && (int)$data['expected_version']===$prior['version'],
            'Activity Request has changed. Refresh the document list first.',409);
        $linked=one($this->db,"SELECT target_id FROM document_links WHERE organization_id=? AND source_type='AR' AND source_id=? AND target_type='TA' LIMIT 1",[$org,$id]);
        demand($linked===null,'Linked Travel Authority exists; signatories may not be replaced retroactively',409);
        // Changing the draft's signatory snapshot retains prior versions automatically.
        $reason='Activity Request signatories updated from current Division appointments';
        $fresh=$this->snapshottedSignatories($org);
        demand((int)$fresh['version_id']>0,'Division must first configure its four appointed travel signatories',409);
        foreach(self::SIGNATORY_ROLES as $role){
            demand(!empty($fresh['roles'][$role]['full_name']),
                'Division must first complete all four appointed travel signatories',409);
        }
        $next=$prior['snapshot'];
        demand(($next['signatories']??null)!==$fresh,'These Division signatories are already assigned to this draft',409);
        $oldVer=(int)($next['signatories']['version_id']??0);
        $next['signatories']=$fresh;
        $v=$this->version($org,'AR',$id,$next,$reason);
        $this->audit($org,'TRAVEL_REQUEST_SIGNATORIES_REFRESHED','AR',$id,[
            'from_document_version'=>$prior['version'],'to_document_version'=>$v,
            'from_signatory_version'=>$oldVer,'to_signatory_version'=>$fresh['version_id'],
            'reason'=>$reason]);
        return ['id'=>$id,'number'=>$prior['number'],'version'=>$v,'signatory_version'=>$fresh['version_id']];
    }
    public function createAuthority(array $data): array {
        $org=$this->organization($data,true);$id=idOf($data,'request_id');
        $source=$this->record($org,'AR',$id,true);$ar=$source['snapshot'];
        demand(($ar['state']??'')==='DRAFT_FOR_MANUAL_SIGNATURE','Activity Request status must be reviewed first');
        $date=self::date(requiredString($data,'authority_date',10),'Travel Authority issue date');
        demand($date>=(string)$ar['document_date'],'Travel Authority date cannot precede the Activity Request date');
        demand(!empty($data['paper_approval_confirmed']) && $data['paper_approval_confirmed']===true,
            'Confirm that the signed Activity Request approval was checked before preparing the linked Travel Authority');
        $reference=requiredString($data,'approval_reference',255);
        $already=one($this->db,"SELECT target_id FROM document_links WHERE organization_id=? AND source_type='AR' AND source_id=? AND target_type='TA' LIMIT 1",[$org,$id]);
        demand($already===null,'A Travel Authority already exists for this Activity Request',409);
        $reg=$this->register($org,'TA');
        $payload=['kind'=>'TA','organization_id'=>$org,'number'=>$reg['number'],'document_date'=>$date,
            'activity_request_id'=>$id,'activity_request_number'=>$source['number'],'activity_request_version'=>$source['version'],
            'employee'=>$ar['employee'],'fund'=>$ar['fund']??null,
            'signatories'=>$ar['signatories']??['version_id'=>0,'roles'=>[],'region_heading'=>'','salutation'=>'Sir/Madam:'],
            'chargeability'=>$ar['chargeability']??[
                'code'=>'SCHOOL_MOOE','label'=>'School MOOE','reference'=>'','school_fund'=>$ar['fund']??null],
            'activity_title'=>$ar['activity_title'],
            'purpose'=>$ar['purpose'],'host'=>$ar['host'],'destination'=>$ar['destination'],
            'travel_from'=>$ar['travel_from'],'travel_to'=>$ar['travel_to'],
            'itinerary_reference'=>$ar['itinerary_reference'],'approval_reference'=>$reference,
            'paper_approval_reviewed_by'=>(int)$this->user['id'],
            'state'=>'DRAFT_FOR_MANUAL_SIGNATURE','authorized_for_payment'=>false,
            'disclaimer'=>'Preparation only; authorized approvers must execute the Travel Authority.'];
        $this->version($org,'TA',$reg['id'],$payload,'Travel Authority prepared from checked paper Activity Request');
        q($this->db,'INSERT INTO document_links (organization_id,source_type,source_id,target_type,target_id) VALUES (?,?,?,?,?)',[$org,'AR',$id,'TA',$reg['id']]);
        $this->audit($org,'TRAVEL_AUTHORITY_DRAFT_CREATED','TA',$reg['id'],['activity_request_id'=>$id,'activity_request_version'=>$source['version'],'approval_reference'=>$reference]);
        return ['id'=>$reg['id'],'number'=>$reg['number'],'version'=>1,'status'=>'DRAFT_FOR_MANUAL_SIGNATURE'];
    }
    public function listDocuments(array $params): array {
        $org=$this->organization($params);$result=['requests'=>[],'authorities'=>[]];
        foreach(['AR'=>'requests','TA'=>'authorities'] as $type=>$label){
            $rows=q($this->db,'SELECT r.id,r.display_number,r.created_at,v.version_no,v.snapshot_json FROM document_registry r JOIN document_versions v ON v.organization_id=r.organization_id AND v.doc_type=r.doc_type AND v.entity_id=r.entity_id AND v.version_no=(SELECT MAX(version_no) FROM document_versions WHERE organization_id=r.organization_id AND doc_type=r.doc_type AND entity_id=r.entity_id) WHERE r.organization_id=? AND r.doc_type=? AND r.entity_id=r.id ORDER BY r.id DESC LIMIT 100',[$org,$type])->fetchAll();
            foreach($rows as $r){$snap=json_decode((string)$r['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
                $result[$label][]=['id'=>(int)$r['id'],'number'=>$r['display_number'],'version'=>(int)$r['version_no'],
                    'document_date'=>$snap['document_date']??'','employee'=>$snap['employee']['full_name']??'',
                    'activity_title'=>$snap['activity_title']??'',
                    'chargeable_to'=>$snap['chargeability']['label']??'School MOOE',
                    'travel_from'=>$snap['travel_from']??'',
                    'travel_to'=>$snap['travel_to']??'','request_id'=>$snap['activity_request_id']??null,
                    'status'=>$snap['state']??'UNKNOWN',
                    'signatory_version'=>(int)($snap['signatories']['version_id']??0),
                    'signatories_complete'=>count(array_filter(['budget_officer','division_accountant','asds','sds'],
                        static fn($r)=>!empty($snap['signatories']['roles'][$r]['full_name'])))===4];}
        }
        return $result;
    }
    public function detail(array $params): array {
        $org=$this->organization($params);$type=requiredString($params,'type',2);$id=idOf($params,'id');
        return $this->record($org,$type,$id);
    }
}
