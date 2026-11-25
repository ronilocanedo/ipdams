<?php
declare(strict_types=1);
/**
 * IPDAMS school property returns. A return is an independently numbered,
 * documentary receipt, not an amendment/cancellation of the original ICS/PAR.
 * No database migrations; versioned, append-only document evidence and links.
 */
require_once __DIR__.'/AnnualDocumentNumbering.php';
final class PropertyReturnWorkflow {
    public function __construct(private PDO $db, private array $user) {}
    private static function need(bool $pass,string $message,int $code=409):void { demand($pass,$message,$code); }
    private static function today():string { return (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'); }
    private function school(int $id):array {
        self::need($id>0,'Choose a school',422);scope($this->db,$this->user,$id);
        $o=one($this->db,"SELECT id,name,kind,active FROM organizations WHERE id=?",[$id]);
        self::need($o!==null && $o['kind']==='SCHOOL' && (int)$o['active']===1,'Active school workspace required',403);
        return $o;
    }
    private function writer(int $school):array {
        self::need(in_array($this->user['role']??null,['SCHOOL','SUPER_ADMIN'],true),'School property officer access required',403);
        self::need((int)($this->user['id']??0)>0,'Authenticated employee required',403);
        return $this->school($school);
    }
    private static function day(mixed $input):string {
        self::need(is_string($input)&&preg_match('/^\d{4}-\d\d-\d\d$/D',$input)===1,'Choose the actual return date',422);
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$input,new DateTimeZone('Asia/Manila'));
        self::need($date!==false&&$date->format('Y-m-d')===$input&&$input<=self::today(),'Return date cannot be invalid or in the future',422);
        return $input;
    }
    private static function text(mixed $raw,int $max,string $label,int $min=0):string {
        self::need(is_string($raw),'Invalid '.$label,422);$v=trim($raw);
        self::need(mbSafeLen($v)>=$min&&mbSafeLen($v)<=$max,$label.' must contain '.$min.'–'.$max.' characters',422);
        return $v;
    }
    private static function ids(mixed $list,int $limit=20):array {
        self::need(is_array($list)&&array_is_list($list)&&count($list)>=1&&count($list)<=$limit,'Select between 1 and '.$limit.' physical properties',422);
        $seen=[];foreach($list as $v){
            self::need((is_string($v)||is_int($v))&&preg_match('/^[1-9][0-9]*$/D',(string)$v)===1,'Invalid property selection',422);
            $id=(int)$v;self::need($id>0&&!isset($seen[$id]),'Repeated or invalid property',422);$seen[$id]=true;
        }
        $out=array_keys($seen);sort($out,SORT_NUMERIC);return $out;
    }
    private const RETURN_CONDITIONS=['Serviceable','Needs Maintenance','For Inspection','For Repair','Under Repair'];
    /** A full employee return is one transaction. Each official receipt holds <=20 same-type units. */
    public function receiveAll(array $p):array {
        $org=(int)($p['organization_id']??0);$this->writer($org);
        $ids=self::ids($p['asset_ids']??null,200);
        $employee=self::text($p['employee']??null,160,'Accountable employee',1);
        self::need(!$this->db->inTransaction(),'Return preparation is already in progress',409);
        $this->db->beginTransaction();
        try {
            $grouped=['SEMI_EXPENDABLE'=>[],'PPE'=>[]];
            foreach($ids as $id){
                $a=one($this->db,"SELECT a.id,a.custodian,i.category FROM assets a JOIN item_catalog i ON i.id=a.item_id WHERE a.id=? AND a.organization_id=? FOR UPDATE",[$id,$org]);
                self::need($a!==null && (string)$a['custodian']===$employee,'Selected property is no longer assigned to the chosen employee; refresh before saving',409);
                self::need(isset($grouped[$a['category']]),'Property category cannot be returned by this workflow',409);
                $grouped[$a['category']][]=$id;
            }
            $receipts=[];
            foreach($grouped as $list)foreach(array_chunk($list,20) as $chunk){
                $receipts[]=$this->receive(array_merge($p,['asset_ids'=>$chunk]));
            }
            self::need(count($receipts)>0,'No return receipts were created',409);
            $this->db->commit();
            return ['item_count'=>count($ids),'receipts'=>$receipts];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    /** Formal unserviceable/disposal state is not a routine dropdown value. */
    private function conditionState(int $org,int $assetId):string {
        $row=one($this->db,"SELECT snapshot_json FROM document_versions WHERE organization_id=? AND doc_type='ASSET_LIFECYCLE' AND entity_id=? ORDER BY version_no DESC LIMIT 1",[$org,$assetId]);
        if($row===null)return 'SERVICEABLE';
        $snap=json_decode((string)$row['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
        self::need(is_array($snap)&&in_array($snap['state']??null,['SERVICEABLE','PENDING_UNSERVICEABLE','UNSERVICEABLE','PENDING_DISPOSAL','DISPOSAL_APPROVED','DISPOSED'],true),
            'Formal property condition history requires reconciliation',409);
        return $snap['state'];
    }
    /** Active means latest FINALIZED custody document has no immutable return link. */
    public static function openAccountability(PDO $db,int $org,int $assetId):?array {
        $doc=one($db,"SELECT id,organization_id,asset_id,doc_type,display_number,custodian,created_at
            FROM accountability_documents WHERE organization_id=? AND asset_id=? AND status='FINALIZED'
            ORDER BY id DESC LIMIT 1",[$org,$assetId]);
        if($doc===null)return null;
        $closed=one($db,"SELECT id FROM document_links WHERE organization_id=? AND source_type=? AND source_id=?
            AND target_type IN ('RRSP','PRR','ITR','PTR') LIMIT 1",[$org,$doc['doc_type'],(int)$doc['id']]);
        return $closed===null?$doc:null;
    }
    /** Return one or more same-custodian assets; split ICS and PAR into separate receipts. */
    public function receive(array $p):array {
        $org=(int)($p['organization_id']??0);$school=$this->writer($org);
        $ids=self::ids($p['asset_ids']??null);$on=self::day($p['returned_on']??null);
        self::need(($p['physical_received']??false)===true,'Confirm that the actual property was physically received',422);
        $reason=self::text($p['reason']??null,500,'Return explanation',8);
        $storage=self::text($p['storage_location']??null,180,'School property storage location',2);
        $remarks=self::text($p['remarks']??'',500,'Return remarks');
        $conditions=$p['conditions']??null;
        self::need(is_array($conditions),'Select a return condition for each property; refresh the return form',422);
        $outer=$this->db->inTransaction();if(!$outer)$this->db->beginTransaction();
        try {
            $items=[];$type='';$from='';$sourceRows=[];
            foreach($ids as $assetId){
                $a=one($this->db,"SELECT a.id,a.organization_id,a.property_tag,a.custodian,a.location,a.condition_note,a.status,a.property_profile,a.item_id,a.acquisition_cost,
                    i.name AS item_name,i.category FROM assets a JOIN item_catalog i ON i.id=a.item_id WHERE a.id=? AND a.organization_id=? FOR UPDATE",[$assetId,$org]);
                self::need($a!==null&&$a['status']==='ACTIVE','Active property in this school required',409);
                self::need(in_array($a['category'],['SEMI_EXPENDABLE','PPE'],true),'Only ICS/PAR property may be returned',409);
                $curr=$a['category']==='SEMI_EXPENDABLE'?'RRSP':'PRR';
                self::need($type===''||$type===$curr,'Return semi-expendable and PPE properties on separate receipts',409);$type=$curr;
                self::need(trim((string)$a['custodian'])!=='','Property has no current employee custodian; cannot return it twice',409);
                self::need($from===''||$from===(string)$a['custodian'],'Select properties currently held by the same accountable employee',409);$from=(string)$a['custodian'];
                self::need(PropertyBatchMetadata::reviewState($a['property_profile']??null)!=='PENDING_REVIEW','Complete physical review first',409);
                $oldCondition=trim((string)($a['condition_note']??''));
                self::need(array_key_exists($assetId,$conditions)||array_key_exists((string)$assetId,$conditions),
                    'Record the condition of every selected property',422);
                $incoming=$conditions[$assetId]??$conditions[(string)$assetId];
                $receivedCondition=self::text($incoming,255,'Condition on return',0);
                $lifecycle=$this->conditionState($org,$assetId);
                $unchanged=($oldCondition===$receivedCondition);
                if(!$unchanged){
                    self::need($lifecycle==='SERVICEABLE',
                        'Formal unserviceable/disposal status cannot be changed through Return Property',409);
                    self::need(!preg_match('/\b(unserviceable|for disposal|disposed)\b/i',$oldCondition),
                        'Prior unserviceable condition requires formal review, not a return-form override',409);
                    self::need(in_array($receivedCondition,self::RETURN_CONDITIONS,true),
                        'Choose a valid per-property condition from the dropdown',422);
                }
                self::need($receivedCondition!=='' || $lifecycle!=='SERVICEABLE',
                    'Choose the observed return condition for each selected property',422);
                $doc=self::openAccountability($this->db,$org,$assetId);
                self::need($doc!==null,'No open issued ICS/PAR for this property; refer for historical reconciliation',409);
                self::need($doc['doc_type']===($type==='RRSP'?'ICS':'PAR'),'Accountability document type does not match property',409);
                self::need($on>=substr((string)$doc['created_at'],0,10),'Return date cannot predate the recorded ICS/PAR issuance',409);
                // Pending inter-school transfers must not be closed by an unrelated return.
                $links=q($this->db,"SELECT target_id FROM document_links WHERE organization_id=? AND source_type='ASSET'
                    AND source_id=? AND target_type IN ('ITR','PTR') ORDER BY id DESC LIMIT 150",[$org,$assetId])->fetchAll(PDO::FETCH_ASSOC);
                self::need(count($links)<150,'Too many transfers; request technical reconciliation',409);
                foreach($links as $link){
                    $v=one($this->db,"SELECT snapshot_json FROM document_versions WHERE organization_id=? AND doc_type IN ('ITR','PTR')
                        AND entity_id=? ORDER BY id DESC LIMIT 1",[$org,(int)$link['target_id']]);
                    if($v!==null){$s=json_decode((string)$v['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
                        self::need(is_array($s)&&($s['status']??'')!=='PENDING_RECEIPT','Resolve pending transfer before property return',409);}
                }
                // A receipt cannot be dated before the last known custody event.
                $recentMovement=one($this->db,'SELECT created_at FROM asset_movements WHERE asset_id=? ORDER BY id DESC LIMIT 1',[$assetId]);
                if($recentMovement!==null)self::need($on>=substr((string)$recentMovement['created_at'],0,10),
                    'Return date cannot predate the latest recorded custody event',409);
                $items[]=['asset_id'=>$assetId,'property_tag'=>(string)$a['property_tag'],'description'=>(string)$a['item_name'],
                    'qty'=>1,'category'=>$a['category'],'accountability_document_id'=>(int)$doc['id'],
                    'accountability_type'=>(string)$doc['doc_type'],'ics_or_par_number'=>(string)$doc['display_number'],
                    'end_user'=>(string)$a['custodian'],'previous_location'=>(string)($a['location']??''),
                    'condition_before'=>$oldCondition,'condition_on_return'=>$receivedCondition,'formal_lifecycle_state'=>$lifecycle,'remarks'=>$remarks];
                $sourceRows[]=$doc;
            }
            // Preserve chronological document dates for each school's receipt series.
            $priorReceipt=one($this->db,"SELECT v.snapshot_json FROM document_registry r JOIN document_versions v
                ON v.organization_id=r.organization_id AND v.doc_type=r.doc_type
                AND v.entity_id=r.entity_id AND v.version_no=1
                WHERE r.organization_id=? AND r.doc_type=? ORDER BY r.id DESC LIMIT 1",[$org,$type]);
            if($priorReceipt!==null){
                $prior=json_decode((string)$priorReceipt['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
                self::need(is_array($prior)&&isset($prior['returned_on'])&&is_string($prior['returned_on']),
                    'Earlier return receipt is invalid; technical reconciliation required',409);
                self::need($on>=$prior['returned_on'],
                    'Return date cannot precede the latest recorded return receipt',409);
            }
            // Provisional registry identifier belongs to this receipt and reserves one number.
            $period=substr($on,0,7);
            q($this->db,"INSERT INTO document_registry (organization_id,doc_type,period,display_number,entity_id) VALUES (?,?,?,?,0)",[$org,$type,$period,'PENDING']);
            $head=(int)$this->db->lastInsertId();self::need($head>0,'Return receipt registration failed',500);
            q($this->db,'UPDATE document_registry SET entity_id=? WHERE id=?',[$head,$head]);
            $number=AnnualDocumentNumbering::reserve($this->db,$org,$type,$period,$head);
            q($this->db,'UPDATE document_registry SET display_number=? WHERE id=?',[$number,$head]);
            foreach($items as $item){
                $assetId=(int)$item['asset_id'];
                $m=one($this->db,'SELECT id,organization_id,custodian,status FROM assets WHERE id=? FOR UPDATE',[$assetId]);
                self::need($m!==null&&(int)$m['organization_id']===$org&&$m['status']==='ACTIVE'
                    &&(string)$m['custodian']===$from,'Property custody changed during return; no partial records saved',409);
                q($this->db,"INSERT INTO asset_movements(asset_id,event_type,from_custodian,to_custodian,reason,actor_id)
                    VALUES (?,'RETURN',?,?,?,?)",[$assetId,$from,null,$type.' '.$number.'; physically received on '.$on.'; '.$reason,(int)$this->user['id']]);
                $changed=q($this->db,'UPDATE assets SET custodian=NULL,location=?,condition_note=? WHERE id=? AND organization_id=? AND custodian=? AND status=?',
                    [$storage,$item['condition_on_return'],$assetId,$org,$from,'ACTIVE'])->rowCount();
                self::need($changed===1,'Custodian changed during return; all return actions rolled back',409);
                q($this->db,"INSERT INTO document_links (organization_id,source_type,source_id,target_type,target_id)
                    VALUES (?,'ASSET',?,?,?)",[$org,$assetId,$type,$head]);
                q($this->db,"INSERT INTO document_links (organization_id,source_type,source_id,target_type,target_id)
                    VALUES (?,?,?,?,?)",[$org,$item['accountability_type'],$item['accountability_document_id'],$type,$head]);
            }
            $snapshot=['type'=>$type,'number'=>$number,'status'=>'RECORDED','school_id'=>$org,'school_name'=>$school['name'],
                'returned_on'=>$on,'recorded_at'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format(DATE_ATOM),
                'returned_by'=>$from,'received_by_user_id'=>(int)$this->user['id'],
                'storage_location'=>$storage,'reason'=>$reason,'remarks'=>$remarks,'items'=>$items,
                'paper_signatures_verified'=>false];
            q($this->db,'INSERT INTO document_versions(organization_id,doc_type,entity_id,version_no,snapshot_json,reason,actor_id) VALUES (?,?,?,?,?,?,?)',
                [$org,$type,$head,1,json_encode($snapshot,JSON_THROW_ON_ERROR),'Property physically received; paper signatures remain external',(int)$this->user['id']]);
            q($this->db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
                [$org,(int)$this->user['id'],'PROPERTY_RETURN_RECORDED','RETURN',$head,json_encode(['number'=>$number,'type'=>$type,'asset_ids'=>$ids,'prior_accountability_ids'=>array_column($items,'accountability_document_id'),'physical_receipt_confirmed'=>true,'conditions'=>array_map(static fn($it)=>['asset_id'=>$it['asset_id'],'before'=>$it['condition_before'],'on_return'=>$it['condition_on_return'],'formal_state'=>$it['formal_lifecycle_state']],$items)],JSON_THROW_ON_ERROR)]);
            if(!$outer)$this->db->commit();
            return ['id'=>$head,'type'=>$type,'number'=>$number,'status'=>'RECORDED','item_count'=>count($items)];
        }catch(Throwable $e){if(!$outer&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function detail(int $id,int $org):array {
        $this->writer($org);
        $row=one($this->db,"SELECT r.id,r.organization_id,r.doc_type,r.display_number,v.snapshot_json FROM document_registry r
            JOIN document_versions v ON v.organization_id=r.organization_id AND v.doc_type=r.doc_type AND v.entity_id=r.entity_id AND v.version_no=1
            WHERE r.id=? AND r.organization_id=? AND r.doc_type IN ('RRSP','PRR')",[$id,$org]);
        self::need($row!==null,'Return receipt is unavailable in this school',404);
        $snapshot=json_decode((string)$row['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
        self::need(is_array($snapshot)&&$snapshot['number']===$row['display_number']
            &&(int)$snapshot['school_id']===$org,'Return evidence failed verification',409);
        return ['id'=>(int)$row['id'],'type'=>$row['doc_type'],'number'=>$row['display_number'],'snapshot'=>$snapshot];
    }
    public function context(int $org,string $selectedEmployee=''):array {
        $school=$this->writer($org);
        $employees=q($this->db,"SELECT DISTINCT a.custodian FROM assets a JOIN item_catalog i ON i.id=a.item_id
            WHERE a.organization_id=? AND a.status='ACTIVE' AND a.custodian IS NOT NULL AND a.custodian<>''
                AND i.category IN ('SEMI_EXPENDABLE','PPE')
                AND EXISTS (SELECT 1 FROM accountability_documents d WHERE d.organization_id=a.organization_id
                    AND d.asset_id=a.id AND d.status='FINALIZED'
                    AND d.id=(SELECT MAX(z.id) FROM accountability_documents z WHERE z.organization_id=a.organization_id
                        AND z.asset_id=a.id AND z.status='FINALIZED')
                    AND NOT EXISTS(SELECT 1 FROM document_links l WHERE l.organization_id=a.organization_id
                        AND l.source_type=d.doc_type AND l.source_id=d.id AND l.target_type IN ('RRSP','PRR')))
            ORDER BY a.custodian LIMIT 500",[$org])->fetchAll(PDO::FETCH_COLUMN);
        self::need($selectedEmployee==='' || in_array($selectedEmployee,$employees,true),
            'Selected employee has no open property accountability at this school',422);
        $rows=[];$tooMany=false;
        if($selectedEmployee!==''){
            $rows=q($this->db,"SELECT a.id,a.property_tag,a.custodian,a.location,a.condition_note,a.status,i.name AS item_name,i.category,
                    d.id AS issued_document_id,d.doc_type AS issued_document_type,d.display_number AS issued_document_number
                FROM assets a JOIN item_catalog i ON i.id=a.item_id
                JOIN accountability_documents d ON d.organization_id=a.organization_id AND d.asset_id=a.id AND d.status='FINALIZED'
                  AND d.id=(SELECT MAX(z.id) FROM accountability_documents z WHERE z.organization_id=a.organization_id AND z.asset_id=a.id AND z.status='FINALIZED')
                WHERE a.organization_id=? AND a.custodian=? AND a.status='ACTIVE'
                    AND i.category IN ('SEMI_EXPENDABLE','PPE')
                    AND NOT EXISTS(SELECT 1 FROM document_links l WHERE l.organization_id=a.organization_id
                        AND l.source_type=d.doc_type AND l.source_id=d.id AND l.target_type IN ('RRSP','PRR'))
                ORDER BY a.id ASC LIMIT 201",[$org,$selectedEmployee])->fetchAll(PDO::FETCH_ASSOC);
            $tooMany=count($rows)>200;
            if($tooMany)array_pop($rows);
            foreach($rows as &$a){
                $a['formal_lifecycle_state']=$this->conditionState($org,(int)$a['id']);
                $a['condition_locked']=$a['formal_lifecycle_state']!=='SERVICEABLE'
                    || preg_match('/\b(unserviceable|for disposal|disposed)\b/i',(string)$a['condition_note'])===1;
            }unset($a);
        }
        $list=q($this->db,"SELECT r.id,r.doc_type,r.display_number,r.created_at,v.snapshot_json FROM document_registry r
            JOIN document_versions v ON v.organization_id=r.organization_id AND v.doc_type=r.doc_type AND v.entity_id=r.entity_id AND v.version_no=1
            WHERE r.organization_id=? AND r.doc_type IN ('RRSP','PRR') ORDER BY r.id DESC LIMIT 80",[$org])->fetchAll(PDO::FETCH_ASSOC);
        $receipts=[];foreach($list as $r){$s=json_decode((string)$r['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
            self::need(is_array($s)&&($s['number']??'')===$r['display_number'],'Return document register requires review',409);
            $receipts[]=['id'=>(int)$r['id'],'type'=>$r['doc_type'],'number'=>$r['display_number'],
                'date'=>$s['returned_on'],'from'=>$s['returned_by'],'count'=>count($s['items']??[]),'storage'=>$s['storage_location']];}
        return ['school'=>$school,'employees'=>$employees,'selected_employee'=>$selectedEmployee,
            'assets'=>$rows,'too_many'=>$tooMany,'receipts'=>$receipts];
    }
}
