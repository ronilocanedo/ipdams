<?php
declare(strict_types=1);
/**
 * IPDAMS Property Transfers. Typed, versioned ITR/PTR documents store the
 * transfer ledger: no secondary table or unverified general-purpose migration.
 * Internal property custody changes immediately on a recorded transfer, within
 * the same audited SQL transaction as the ITR/PTR document. Paper signatures
 * remain external supporting records and are not digitally attested here.
 * Inter-school custody reassignment remains disabled pending lineage safeguards.
 */
require_once __DIR__.'/AnnualDocumentNumbering.php';
final class PropertyTransferWorkflow {
    public function __construct(private PDO $db, private array $user) {}
    private static function check(bool $ok,string $msg,int $code=409): void { demand($ok,$msg,$code); }
    private static function today():string { return (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'); }
    private function school(int $id,bool $scopeRequired):array {
        self::check($id>0,'Select a school',422);
        if($scopeRequired)scope($this->db,$this->user,$id);
        $r=one($this->db,"SELECT id,name,kind,active,parent_division_id FROM organizations WHERE id=?",[$id]);
        self::check($r!==null&&$r['kind']==='SCHOOL'&&(int)$r['active']===1,'School is not active',403);
        return $r;
    }
    /** Receiving organizations may be schools or Division Offices, even in another Division. */
    private function destinationOrganization(int $id):array {
        self::check($id>0,'Select a receiving school or Division Office',422);
        $r=one($this->db,'SELECT id,name,kind,active,parent_division_id FROM organizations WHERE id=?',[$id]);
        self::check($r!==null && (int)$r['active']===1 && in_array($r['kind'],['SCHOOL','DIVISION'],true),
            'Receiving organization is inactive or unavailable',422);
        return $r;
    }
    private function mayWrite(int $org):void {
        self::check(in_array($this->user['role'],['SCHOOL','SUPER_ADMIN'],true),'School Administrator access is required',403);
        $this->school($org,true);
    }
    private function staff(int $org,int $employee):array {
        self::check($employee>0,'Select an accountable employee',422);
        $row=one($this->db,'SELECT a.employee_id,a.effective_from,a.effective_to,e.full_name,a.designation,e.position_title FROM employee_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.organization_id=? AND a.employee_id=? ORDER BY a.id DESC LIMIT 1',[$org,$employee]);
        $today=self::today();
        self::check($row!==null && (string)$row['effective_from']<=$today && ($row['effective_to']===null || (string)$row['effective_to']>$today),'Receiving employee is not actively assigned to this school',422);
        self::check(trim((string)$row['full_name'])!=='' && mbSafeLen((string)$row['full_name'])<=160,'Employee name requires correction',409);
        return ['id'=>(int)$row['employee_id'],'name'=>(string)$row['full_name'],'designation'=>(string)($row['designation']?:$row['position_title'])];
    }
    /** Configured custodian is authoritative. Fall back only to one active AO. */
    private function externalReceiver(int $org):array {
        $cfg=one($this->db,'SELECT id,config_json,effective_from FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',[$org,'ORG_SUPPLY_PROPERTY_CUSTODIAN_V1']);
        if($cfg!==null){
            self::check((string)$cfg['effective_from']<=self::today(),'Destination custodian appointment is not effective',409);
            $v=json_decode((string)$cfg['config_json'],true,32,JSON_THROW_ON_ERROR);
            self::check(is_array($v)&& (int)($v['employee_id']??0)>0,'Destination school must configure an active Property Custodian',409);
            $receiver=$this->staff($org,(int)$v['employee_id']);
            $receiver['appointment_version_id']=(int)$cfg['id'];return $receiver;
        }
        $rows=q($this->db,'SELECT a.employee_id,a.designation,a.effective_from,a.effective_to,e.position_title FROM employee_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.organization_id=? ORDER BY a.id DESC',[$org])->fetchAll(PDO::FETCH_ASSOC);
        $seen=[];$candidates=[];$now=self::today();
        foreach($rows as $row){$eid=(int)$row['employee_id'];if(isset($seen[$eid]))continue;$seen[$eid]=true;
            if((string)$row['effective_from']>$now || ($row['effective_to']!==null&&(string)$row['effective_to']<=$now))continue;
            if(preg_match('/\bAdministrative Officer\b/i',(string)$row['designation'].' '.(string)$row['position_title']))$candidates[]=$eid;
        }
        self::check(count($candidates)===1,'Destination school needs one verified active Administrative Officer or an appointed Property Custodian',409);
        $receiver=$this->staff($org,$candidates[0]);$receiver['appointment_version_id']=0;return $receiver;
    }
    private function head(int $id,bool $lock=false):array {
        $r=one($this->db,'SELECT r.id,r.organization_id,r.doc_type,r.display_number,v.snapshot_json,v.version_no FROM document_registry r JOIN document_versions v ON v.organization_id=r.organization_id AND v.doc_type=r.doc_type AND v.entity_id=r.entity_id WHERE r.id=? AND r.doc_type IN (\'ITR\',\'PTR\') AND v.version_no=(SELECT MAX(z.version_no) FROM document_versions z WHERE z.organization_id=r.organization_id AND z.doc_type=r.doc_type AND z.entity_id=r.entity_id)'.($lock?' FOR UPDATE':''),[$id]);
        self::check($r!==null,'Transfer record is unavailable',404);
        $s=json_decode((string)$r['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
        self::check(is_array($s)&&($s['type']??null)===$r['doc_type'] && (int)($s['source_school_id']??0)===(int)$r['organization_id'],'Transfer document failed verification',409);
        return ['id'=>(int)$r['id'],'organization_id'=>(int)$r['organization_id'],'type'=>$r['doc_type'],'number'=>$r['display_number'],'version'=>(int)$r['version_no'],'snapshot'=>$s];
    }
    private function canView(array $head,int $schoolId):void {
        $s=$head['snapshot'];self::check($schoolId===(int)$s['source_school_id']||$schoolId===(int)$s['destination_school_id'],'Transfer is not in this school',403);
        $this->school($schoolId,true);
    }
    private function noPending(int $org,int $assetId):void {
        $links=q($this->db,"SELECT target_id FROM document_links WHERE organization_id=? AND source_type='ASSET' AND source_id=? AND target_type IN ('ITR','PTR') ORDER BY id DESC LIMIT 200",[$org,$assetId])->fetchAll(PDO::FETCH_ASSOC);
        self::check(count($links)<200,'Too many transfer history links; request technical review',409);
        foreach($links as $link){$existing=$this->head((int)$link['target_id']);
            self::check(($existing['snapshot']['status']??'')!=='PENDING_RECEIPT','One of the properties has an outstanding transfer',409);
        }
    }
    private function number(int $org,string $type,int $id):string {
        $period=substr(self::today(),0,7);
        // Exclude this transaction's provisional PENDING transfer registry row.
        $num=AnnualDocumentNumbering::reserve($this->db,$org,$type,$period,$id);
        q($this->db,'UPDATE document_registry SET display_number=?,period=? WHERE id=?',[$num,$period,$id]);
        return $num;
    }
    private function version(int $org,string $type,int $id,int $v,array $snap,string $reason):void {
        q($this->db,'INSERT INTO document_versions(organization_id,doc_type,entity_id,version_no,snapshot_json,reason,actor_id) VALUES (?,?,?,?,?,?,?)',[$org,$type,$id,$v,json_encode($snap,JSON_THROW_ON_ERROR),$reason,$this->user['id']]);
    }
    private function audit(int $org,string $event,int $id,array $extra=[]):void {
        q($this->db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',[$org,$this->user['id'],$event,'TRANSFER',$id,json_encode($extra,JSON_THROW_ON_ERROR)]);
    }
    /**
     * Document-only internal transfer: change each asset custodian in the same
     * transaction as the ITR/PTR, never claiming a paper signature occurred.
     * Caller must hold one transaction and pass a locked, authorized school.
     */
    private function applyInternalCustody(int $school,int $documentId,string $type,string $number,array $snapshot):array {
        self::check(($snapshot['transfer_kind']??null)==='INTERNAL'
            && (int)($snapshot['source_school_id']??0)===$school
            && (int)($snapshot['destination_school_id']??0)===$school,
            'Only internal transfers in the same school can update a custodian automatically',409);
        self::check($this->db->inTransaction(),'Internal transfer requires an active database transaction',500);
        $receiver=$this->staff($school,(int)($snapshot['receiver_employee_id']??0));
        self::check((string)$receiver['name']===(string)$snapshot['receiver_name'],
            'Receiving employee assignment changed; review transfer before recording',409);
        $items=$snapshot['items']??null;
        self::check(is_array($items) && count($items)>=1 && count($items)<=20,
            'Internal transfer has invalid property lines',409);
        $seen=[];
        foreach($items as $item){
            self::check(is_array($item),'Invalid transfer line',409);
            $assetId=(int)($item['asset_id']??0);
            self::check($assetId>0 && !isset($seen[$assetId]),'Duplicate or invalid asset in transfer',409);
            $seen[$assetId]=true;
            $asset=one($this->db,'SELECT a.*,i.category FROM assets a JOIN item_catalog i ON i.id=a.item_id WHERE a.id=? AND a.organization_id=? FOR UPDATE',[$assetId,$school]);
            self::check($asset!==null && $asset['status']==='ACTIVE'
                && (string)$asset['property_tag']===(string)($item['property_tag']??'')
                && (int)$asset['item_id']===(int)($item['item_id']??0)
                && (string)$asset['category']===(string)($item['category']??''),
                'Property has changed since transfer selection; nothing was transferred',409);
            self::check(PropertyBatchMetadata::reviewState($asset['property_profile']??null)!=='PENDING_REVIEW',
                'Property requires registration review before transfer',409);
            self::check((string)($asset['custodian']??'')===(string)($item['from_custodian']??''),
                'Custodian changed after transfer was prepared; review required',409);
            self::check((string)($asset['custodian']??'')!==$receiver['name'],
                'This employee is already the recorded custodian',409);
            $eventReason=$type.' '.$number.'; internal custodian reassignment recorded in IPDAMS for documentation; paper signatures held externally';
            q($this->db,'INSERT INTO asset_movements(asset_id,event_type,from_custodian,to_custodian,reason,actor_id) VALUES (?,?,?,?,?,?)',
                [$assetId,'TRANSFER',(string)($asset['custodian']??''),$receiver['name'],$eventReason,$this->user['id']]);
            $updated=q($this->db,'UPDATE assets SET custodian=? WHERE id=? AND organization_id=? AND custodian <=> ?',
                [$receiver['name'],$assetId,$school,$asset['custodian']])->rowCount();
            self::check($updated===1,'Property changed during transfer; all changes rolled back',409);
        }
        $snapshot['status']='RECORDED';
        $snapshot['recorded_at']=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format(DATE_ATOM);
        $snapshot['recorded_by_user_id']=(int)$this->user['id'];
        $snapshot['recording_method']='INTERNAL_DOCUMENTARY_CUSTODY_UPDATE';
        $snapshot['wet_signature_captured']=false;
        $snapshot['receiver_electronic_confirmation']=false;
        $snapshot['paper_receipt_verified_by_origin_school']=false;
        $snapshot['receipt_reference']=null;
        $snapshot['physical_handover_date']=null;
        return $snapshot;
    }
    /** Internal records are posted immediately; external records are prepared only. */
    public function prepare(array $p):array {
        $org=(int)($p['source_school_id']??0);$this->mayWrite($org);
        $dst=(int)($p['destination_school_id']??0);$kind=(string)($p['transfer_kind']??'');
        self::check(in_array($kind,['INTERNAL','EXTERNAL'],true),'Choose internal or external transfer',422);
        $raw=$p['asset_ids']??[];
        self::check(is_array($raw)&&array_is_list($raw)&&count($raw)>=1&&count($raw)<=20,'Select 1–20 properties',422);
        $ids=[];foreach($raw as $val){self::check((is_int($val)||is_string($val)) && preg_match('/^[1-9][0-9]*$/D',(string)$val)===1,'Invalid property selection',422);$id=(int)$val;self::check(!isset($ids[$id]),'Duplicate selected property',422);$ids[$id]=true;}
        $ids=array_keys($ids);sort($ids,SORT_NUMERIC);
        $reason=trim((string)($p['reason']??''));self::check(mbSafeLen($reason)>=8&&mbSafeLen($reason)<=500,'Enter an 8–500 character transfer purpose',422);
        $outer=$this->db->inTransaction();if(!$outer)$this->db->beginTransaction();
        try {
            $source=$this->school($org,true);
            $sourceDivision=(int)$source['parent_division_id'];
            self::check($sourceDivision>0,'Source school must belong to an active Division',409);
            $mode=$kind==='EXTERNAL'?(string)($p['receiving_target_mode']??'REGISTERED'):'INTERNAL';
            self::check(in_array($mode,['INTERNAL','REGISTERED','OUTSIDE'],true),'Invalid receiving destination mode',422);
            if($mode==='OUTSIDE'){
                // Prepared transfer ONLY. These names are user-entered unverified
                // documentary references, never live organization / ownership keys.
                self::check($dst===0 && (int)($p['destination_division_id']??0)===0,
                    'Outside destination must not impersonate a registered organization',422);
                $divisionName=trim((string)($p['external_division_name']??''));
                $officeName=trim((string)($p['external_office_name']??''));
                self::check(mbSafeLen($divisionName)>=5 && mbSafeLen($divisionName)<=160,
                    'Enter the receiving Division official name (5–160 characters)',422);
                self::check(mbSafeLen($officeName)>=5 && mbSafeLen($officeName)<=160,
                    'Enter the receiving school or office name (5–160 characters)',422);
                $dest=['id'=>0,'name'=>$officeName,'kind'=>'EXTERNAL_OFFICE','parent_division_id'=>null];
                $division=['id'=>0,'name'=>$divisionName,'active'=>1];
                $destinationDivision=0;
            }else{
                $dest=$this->destinationOrganization($dst);
                $destinationDivision=$dest['kind']==='DIVISION'?(int)$dest['id']:(int)$dest['parent_division_id'];
                self::check($destinationDivision>0,'Receiving organization must belong to a Division',422);
                $division=one($this->db,"SELECT id,name,active FROM organizations WHERE id=? AND kind='DIVISION'",[$destinationDivision]);
                self::check($division!==null && (int)$division['active']===1,'Receiving Division must be active',422);
                if($kind==='EXTERNAL')self::check((int)($p['destination_division_id']??0)===$destinationDivision,
                    'Receiving Division does not match the selected school/office',422);
            }
            self::check($kind==='INTERNAL'?$org===$dst:($mode==='OUTSIDE'||$org!==$dst),
                'Choose a valid internal/external destination',422);
            if($kind==='INTERNAL'){
                self::check($dest['kind']==='SCHOOL' && $destinationDivision===$sourceDivision,'Internal transfers stay within one school',422);
                $receiver=$this->staff($dst,(int)($p['receiver_employee_id']??0));
            } else {
                // An external PTR/ITR is preparation only. Do not invent or assert the
                // receiving officer's identity until the destination verifies receipt.
                $receiver=['id'=>0,'name'=>'To be confirmed by receiving office',
                    'designation'=>'Property Custodian / Supply Officer (pending verification)',
                    'appointment_version_id'=>0];
            }
            $items=[];$type='';
            foreach($ids as $id){
                $a=one($this->db,'SELECT a.*,i.category,i.name AS item_name,i.owner_organization_id FROM assets a JOIN item_catalog i ON i.id=a.item_id WHERE a.id=? AND a.organization_id=? FOR UPDATE',[$id,$org]);
                self::check($a!==null && $a['status']==='ACTIVE','Selected property is unavailable or not active',409);
                // Lifecycle decisions are authoritative even while assets.status stays ACTIVE.
                $life=one($this->db,'SELECT snapshot_json FROM document_versions WHERE organization_id=? AND doc_type=? AND entity_id=? ORDER BY version_no DESC LIMIT 1',[$org,'ASSET_LIFECYCLE',$id]);
                if($life!==null){
                    $decision=json_decode((string)$life['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
                    self::check(is_array($decision)&&($decision['state']??null)==='SERVICEABLE',
                        'A property under unserviceability or disposal review cannot be transferred',409);
                }

                self::check(PropertyBatchMetadata::reviewState($a['property_profile']??null)!=='PENDING_REVIEW','Review every selected property first',409);
                $docType=$a['category']==='PPE'?'PTR':($a['category']==='SEMI_EXPENDABLE'?'ITR':'');
                self::check($docType!=='','Only PPE and semi-expendable properties can transfer',409);
                if($type!=='')self::check($type===$docType,'PPE and semi-expendable transfers require separate reports',409);$type=$docType;
                self::check(trim((string)$a['property_tag'])!=='','Permanent property number is required',409);
                self::check(is_string($a['acquisition_cost']) && preg_match('/^[0-9]{1,12}\.[0-9]{2}$/D',$a['acquisition_cost'])===1,
                    'Property acquisition cost requires verified two-decimal precision',409);
                $this->noPending($org,$id);
                if($kind==='INTERNAL')self::check(trim((string)($a['custodian']??''))!==$receiver['name'],
                    'The receiving employee is already recorded as this property custodian',409);
                $items[]=['asset_id'=>$id,'property_tag'=>(string)$a['property_tag'],'item_id'=>(int)$a['item_id'],'description'=>(string)$a['item_name'],'category'=>(string)$a['category'],'unit_cost'=>(string)$a['acquisition_cost'],'from_custodian'=>(string)($a['custodian']??''),'original_iar_line_id'=>$a['iar_line_id']===null?null:(int)$a['iar_line_id']];
            }
            // Document ID is its own entity ID (like reimbursement claims).
            $period=substr(self::today(),0,7);
            q($this->db,'INSERT INTO document_registry(organization_id,doc_type,period,display_number,entity_id) VALUES (?,?,?,?,0)',[$org,$type,$period,'PENDING']);
            $head=(int)$this->db->lastInsertId();self::check($head>0,'Transfer registration failed',500);
            q($this->db,'UPDATE document_registry SET entity_id=? WHERE id=?',[$head,$head]);
            $number=$this->number($org,$type,$head);
            $snapshot=['type'=>$type,'number'=>$number,'status'=>'PENDING_RECEIPT','transfer_kind'=>$kind,
                'source_school_id'=>$org,'source_school_name'=>$source['name'],'source_division_id'=>$sourceDivision,
                'destination_school_id'=>$dst,'destination_school_name'=>$dest['name'],
                'destination_organization_id'=>$dst,'destination_organization_kind'=>$dest['kind'],
                'destination_division_id'=>$destinationDivision,'destination_division_name'=>$division['name'],
                'external_destination_unverified'=>$mode==='OUTSIDE','receiving_target_mode'=>$mode,
                'receiver_employee_id'=>$receiver['id'],'receiver_name'=>$receiver['name'],'receiver_designation'=>$receiver['designation'],
                'receiver_appointment_version_id'=>$receiver['appointment_version_id']??0,'purpose'=>$reason,'items'=>$items,
                'prepared_at'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format(DATE_ATOM),
                'receipt_reference'=>null,'received_at'=>null,'wet_signature_captured'=>false];
            // Apply internal custody in this same transaction; no second receipt action.
            // External transfers stay document-only until cross-school lineage is supported.
            if($kind==='INTERNAL')$snapshot=$this->applyInternalCustody($org,$head,$type,$number,$snapshot);
            $this->version($org,$type,$head,1,$snapshot,$kind==='INTERNAL'
                ?'Internal custodian transfer recorded automatically; paper signature not attested'
                :'External transfer document prepared; no cross-school asset reassignment');
            foreach($items as $item)q($this->db,"INSERT INTO document_links(organization_id,source_type,source_id,target_type,target_id) VALUES (?,'ASSET',?,?,?)",[$org,$item['asset_id'],$type,$head]);
            $this->audit($org,$kind==='INTERNAL'?'PROPERTY_TRANSFER_CUSTODY_RECORDED':'PROPERTY_TRANSFER_PREPARED',$head,
                ['type'=>$type,'destination'=>$dst,'receiving_division_id'=>$destinationDivision,
                    'receiving_organization_kind'=>$dest['kind'],'asset_ids'=>$ids,'no_paper_signature_attested'=>true]);
            if(!$outer)$this->db->commit();return ['id'=>$head,'number'=>$number,'type'=>$type,'status'=>$snapshot['status']];
        }catch(Throwable $e){if(!$outer&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    /**
     * Reconcile an old-version pending INTERNAL test transfer. No signature claim,
     * and never auto-mutate older data merely because a page was viewed.
     */
    public function recordPendingInternal(array $p):array {
        $school=(int)($p['organization_id']??0);$this->mayWrite($school);
        $id=(int)($p['transfer_id']??0);
        self::check($id>0,'Select an existing pending internal transfer',422);
        $outer=$this->db->inTransaction();if(!$outer)$this->db->beginTransaction();
        try {
            $head=$this->head($id,true);$snapshot=$head['snapshot'];
            self::check((int)($snapshot['source_school_id']??0)===$school,
                'Only the originating school may record its transfer',403);
            self::check(($snapshot['status']??'')==='PENDING_RECEIPT'
                && ($snapshot['transfer_kind']??'')==='INTERNAL',
                'Only a legacy pending internal transfer may be reconciled',409);
            $new=$this->applyInternalCustody($school,$id,$head['type'],$head['number'],$snapshot);
            $this->version($school,$head['type'],$id,$head['version']+1,$new,
                'Legacy pending internal transfer recorded as a documentation-only custodian update');
            $this->audit($school,'PROPERTY_TRANSFER_CUSTODY_RECORDED',$id,
                ['legacy_pending'=>true,'no_paper_signature_attested'=>true]);
            if(!$outer)$this->db->commit();
            return ['id'=>$id,'number'=>$head['number'],'status'=>'RECORDED'];
        }catch(Throwable $e){if(!$outer&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    /** Cancel only a pending, not-yet-received transfer: original asset stays unchanged. */
    public function cancel(array $p):array {
        $school=(int)($p['organization_id']??0);$this->mayWrite($school);
        $id=(int)($p['transfer_id']??0);self::check($id>0,'Choose a transfer to cancel',422);
        $reason=trim((string)($p['reason']??''));self::check(mbSafeLen($reason)>=8&&mbSafeLen($reason)<=240,'Enter a cancellation reason of at least 8 characters',422);
        $outer=$this->db->inTransaction();if(!$outer)$this->db->beginTransaction();
        try {
            $head=$this->head($id,true);$s=$head['snapshot'];
            self::check((int)$s['source_school_id']===$school,'Only the originating school can cancel this transfer',403);
            self::check($s['status']==='PENDING_RECEIPT','Only a pending transfer can be cancelled',409);
            foreach($s['items'] as $item){
                $a=one($this->db,'SELECT id,organization_id,property_tag FROM assets WHERE id=? FOR UPDATE',[(int)$item['asset_id']]);
                self::check($a!==null&&(int)$a['organization_id']===$school&&(string)$a['property_tag']===(string)$item['property_tag'],'Asset changed since transfer preparation; cancellation requires review',409);
            }
            $s['status']='CANCELLED';$s['cancel_reason']=$reason;
            $s['cancelled_at']=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format(DATE_ATOM);
            $this->version($school,$head['type'],$id,$head['version']+1,$s,'Cancelled before physical handover; no asset changed');
            $this->audit($school,'PROPERTY_TRANSFER_CANCELLED',$id,['reason'=>$reason]);
            if(!$outer)$this->db->commit();return ['status'=>'CANCELLED','number'=>$head['number']];
        }catch(Throwable $e){if(!$outer&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function detail(int $id,int $school):array {
        // Division read-only printing also works for Super Administrators who
        // selected a Division workspace. A different Division's transfer must
        // never be exposed by guessing its document ID.
        if(in_array($this->user['role'],['DIVISION','SUPER_ADMIN'],true)){
            scope($this->db,$this->user,$school);
            $office=$this->destinationOrganization($school);
            if($office['kind']==='DIVISION'){
                $h=$this->head($id);
                self::check(($h['snapshot']['transfer_kind']??'')==='EXTERNAL'
                    && (int)($h['snapshot']['destination_division_id']??0)===$school,
                    'The transfer is not addressed to your Division',403);
                return $h;
            }
            self::check($this->user['role']==='SUPER_ADMIN',
                'Division receiving desk requires a Division workspace',403);
        }
        $this->mayWrite($school);$h=$this->head($id);$this->canView($h,$school);return $h;
    }
    /** Read-only Division intake. The action to accept assets is deliberately not available. */
    public function incomingForDivision(int $division):array {
        self::check(in_array($this->user['role'],['DIVISION','SUPER_ADMIN'],true),
            'Division receiving desk requires Division authority',403);
        scope($this->db,$this->user,$division);
        $office=$this->destinationOrganization($division);
        self::check($office['kind']==='DIVISION','Select your Division Office',403);
        $rows=q($this->db,"SELECT r.id,r.organization_id,r.doc_type,r.display_number,v.snapshot_json,v.version_no
            FROM document_registry r JOIN document_versions v ON v.organization_id=r.organization_id
              AND v.doc_type=r.doc_type AND v.entity_id=r.entity_id
              AND v.version_no=(SELECT MAX(z.version_no) FROM document_versions z
                WHERE z.organization_id=r.organization_id AND z.doc_type=r.doc_type AND z.entity_id=r.entity_id)
            WHERE r.doc_type IN ('ITR','PTR') ORDER BY r.id DESC LIMIT 401")->fetchAll(PDO::FETCH_ASSOC);
        self::check(count($rows)<=400,'Transfer register requires pagination; contact the administrator',409);
        $incoming=[];
        foreach($rows as $row){
            $snap=json_decode((string)$row['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
            if(!is_array($snap)||($snap['type']??null)!==$row['doc_type']||
                (int)($snap['source_school_id']??0)!==(int)$row['organization_id'])continue;
            if(($snap['transfer_kind']??'')!=='EXTERNAL'||(int)($snap['destination_division_id']??0)!==$division)continue;
            $incoming[]=['id'=>(int)$row['id'],'organization_id'=>(int)$row['organization_id'],
                'type'=>$row['doc_type'],'number'=>$row['display_number'],'snapshot'=>$snap];
            if(count($incoming)>=100)break;
        }
        return ['division'=>$office,'incoming'=>$incoming];
    }
    /** Registered external transfers addressed exactly to a school, excluding outside-IPDAMS names. */
    public function incomingForSchool(int $school):array {
        $this->school($school,true);
        self::check(in_array($this->user['role'],['SCHOOL','SUPER_ADMIN'],true),
            'School incoming register requires authorized school login',403);
        $rows=q($this->db,"SELECT r.id,r.organization_id,r.doc_type,r.display_number,v.snapshot_json
            FROM document_registry r JOIN document_versions v ON v.organization_id=r.organization_id
              AND v.doc_type=r.doc_type AND v.entity_id=r.entity_id
              AND v.version_no=(SELECT MAX(z.version_no) FROM document_versions z
                WHERE z.organization_id=r.organization_id AND z.doc_type=r.doc_type AND z.entity_id=r.entity_id)
            WHERE r.doc_type IN ('ITR','PTR') ORDER BY r.id DESC LIMIT 401")->fetchAll(PDO::FETCH_ASSOC);
        self::check(count($rows)<=400,'Incoming transfer list requires pagination',409);
        $incoming=[];foreach($rows as $r){
            $s=json_decode((string)$r['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
            if(!is_array($s)||($s['transfer_kind']??'')!=='EXTERNAL'||
                (int)($s['destination_organization_id']??0)!==$school||
                !empty($s['external_destination_unverified'])||
                (int)($s['source_school_id']??0)!==(int)$r['organization_id'])continue;
            $incoming[]=['id'=>(int)$r['id'],'type'=>$r['doc_type'],'number'=>$r['display_number'],'snapshot'=>$s];
            if(count($incoming)>=100)break;
        }
        return $incoming;
    }
    public function listForSchool(int $school):array {
        $this->mayWrite($school);
        // One bounded, read-only register query, not one request per transfer.
        $rows=q($this->db,"SELECT r.id,r.organization_id,r.doc_type,r.display_number,v.snapshot_json,v.version_no
            FROM document_registry r JOIN document_versions v ON v.organization_id=r.organization_id
              AND v.doc_type=r.doc_type AND v.entity_id=r.entity_id
              AND v.version_no=(SELECT MAX(z.version_no) FROM document_versions z
                WHERE z.organization_id=r.organization_id AND z.doc_type=r.doc_type AND z.entity_id=r.entity_id)
            WHERE r.doc_type IN ('ITR','PTR') ORDER BY r.id DESC LIMIT 401")->fetchAll(PDO::FETCH_ASSOC);
        self::check(count($rows)<=400,'Transfer register has grown beyond the current safe page; use administrative review',409);
        $out=[];
        foreach($rows as $row){
            $snap=json_decode((string)$row['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
            self::check(is_array($snap)&&($snap['type']??null)===$row['doc_type']
                && (int)($snap['source_school_id']??0)===(int)$row['organization_id'],
                'Invalid historical transfer snapshot; manual review required',409);
            if((int)$snap['source_school_id']===$school||(int)$snap['destination_school_id']===$school){
                $out[]=['id'=>(int)$row['id'],'organization_id'=>(int)$row['organization_id'],
                    'type'=>$row['doc_type'],'number'=>$row['display_number'],
                    'version'=>(int)$row['version_no'],'snapshot'=>$snap];
                if(count($out)>=100)break;
            }
        }
        return $out;
    }
    public function context(int $school):array {
        $this->mayWrite($school);$meta=$this->school($school,true);
        $divisions=q($this->db,"SELECT id,name FROM organizations WHERE kind='DIVISION' AND active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        $destinations=q($this->db,"SELECT id,name,kind,parent_division_id FROM organizations WHERE active=1 AND kind IN ('SCHOOL','DIVISION') ORDER BY kind,name")->fetchAll(PDO::FETCH_ASSOC);
        $assets=q($this->db,"SELECT a.id,a.property_tag,a.custodian,a.property_profile,i.category,i.name AS item_name FROM assets a JOIN item_catalog i ON i.id=a.item_id WHERE a.organization_id=? AND a.status='ACTIVE' AND i.category IN ('SEMI_EXPENDABLE','PPE') ORDER BY a.id DESC LIMIT 200",[$school])->fetchAll(PDO::FETCH_ASSOC);
        $eligible=[];foreach($assets as $a){if(PropertyBatchMetadata::reviewState($a['property_profile']??null)!=='PENDING_REVIEW'){
            try{
                $life=one($this->db,'SELECT snapshot_json FROM document_versions WHERE organization_id=? AND doc_type=? AND entity_id=? ORDER BY version_no DESC LIMIT 1',[$school,'ASSET_LIFECYCLE',(int)$a['id']]);
                if($life!==null){$l=json_decode((string)$life['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
                    self::check(is_array($l)&&($l['state']??null)==='SERVICEABLE','Property is in a lifecycle process',409);}
                $this->noPending($school,(int)$a['id']);$eligible[]=['id'=>(int)$a['id'],'tag'=>$a['property_tag'],'item'=>$a['item_name'],'category'=>$a['category'],'custodian'=>$a['custodian']];}catch(DomainError $ignored){} }}
        $assign=q($this->db,'SELECT a.employee_id,a.effective_from,a.effective_to,e.full_name FROM employee_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.organization_id=? ORDER BY a.id DESC',[$school])->fetchAll(PDO::FETCH_ASSOC);
        $seen=[];$employees=[];$today=self::today();foreach($assign as $row){$id=(int)$row['employee_id'];if(isset($seen[$id]))continue;$seen[$id]=true;if((string)$row['effective_from']<=$today&&($row['effective_to']===null||(string)$row['effective_to']>$today))$employees[]=['id'=>$id,'name'=>$row['full_name']];}
        return ['school'=>$meta,'divisions'=>$divisions,'destinations'=>$destinations,'assets'=>$eligible,'employees'=>$employees,'transfers'=>$this->listForSchool($school)];
    }
}
