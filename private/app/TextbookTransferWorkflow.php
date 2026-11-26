<?php
declare(strict_types=1);
require_once __DIR__.'/AnnualDocumentNumbering.php';
require_once __DIR__.'/TextbookIsbn.php';
require_once __DIR__.'/IarSignatories.php';
require_once __DIR__.'/TextbookLifecycleWorkflow.php';

/**
 * v0.2.282: Documentary textbook allocation, request, direct dispatch and school receipt.
 * A named paper signatory DOES NOT need an account or supply an electronic approval.
 * Only scoped encoding users create immutable versions and stock movements.
 * Transfer journal: existing TBX document_registry/document_versions; no DDL.
 */
final class TextbookTransferWorkflow {
    private const TYPE='TBX';
    public function __construct(private PDO $db, private array $user) {}
    private static function today():string {return (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');}
    private static function check(bool $v,string $msg,int $code=422):void {demand($v,$msg,$code);}
    private static function str(array $body,string $key,int $max,int $min=0):string {
        $value=trim((string)($body[$key]??''));
        self::check(mbSafeLen($value)>=$min && mbSafeLen($value)<=$max,'Check '.str_replace('_',' ',$key).' ('.$min.'–'.$max.' characters)');
        return $value;
    }
    private static function qty(mixed $v,string $name,bool $allowZero=false):int {
        self::check((is_int($v)||is_string($v)) && preg_match($allowZero?'/^(0|[1-9][0-9]{0,7})$/D':'/^[1-9][0-9]{0,7}$/D',(string)$v)===1,
            'Enter a valid whole-number '.$name);
        return (int)$v;
    }
    private static function day(string $date,string $label,bool $future=false):string {
        self::check(preg_match('/^20\d\d-\d\d-\d\d$/D',$date)===1,'Enter the actual '.$label.' date');
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('Asia/Manila'));
        self::check($d!==false && $d->format('Y-m-d')===$date,'Invalid '.$label.' date');
        self::check($future?$date>=self::today():$date<=self::today(),
            $future?'Preferred delivery date must not be in the past':$label.' date cannot be in the future');
        return $date;
    }
    private function office(int $id,bool $requireScope=true):array {
        self::check($id>0,'Select a school or Division Office');
        if($requireScope)scope($this->db,$this->user,$id);
        $o=one($this->db,"SELECT id,name,kind,active,parent_division_id FROM organizations WHERE id=?",[$id]);
        self::check($o!==null && (int)$o['active']===1 && in_array($o['kind'],['DIVISION','SCHOOL'],true),
            'Office is not active or accessible',403);
        if($requireScope)self::check(in_array($this->user['role']??'',[$o['kind'],'SUPER_ADMIN'],true),
            'Use an authenticated office account for the current workspace',403);
        return $o;
    }
    private function source(int $org):array {return $this->office($org,true);}
    private function destination(array $source,int $schoolId):array {
        $s=$this->office($schoolId,false);
        self::check($s['kind']==='SCHOOL' && $schoolId!==(int)$source['id'],
            'Choose another active school as receiver',403);
        $division=(int)($source['kind']==='DIVISION'?$source['id']:$source['parent_division_id']);
        self::check($division>0 && (int)$s['parent_division_id']===$division,
            'Textbook transfers currently require schools within the same Division',403);
        return $s;
    }
    private function version(int $org,int $id,int $version,array $s,string $reason):void {
        q($this->db,'INSERT INTO document_versions(organization_id,doc_type,entity_id,version_no,snapshot_json,reason,actor_id) VALUES (?,?,?,?,?,?,?)',
            [$org,self::TYPE,$id,$version,json_encode($s,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$reason,(int)$this->user['id']]);
    }
    private function audit(int $org,string $event,int $id,array $info=[]):void {
        q($this->db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
            [$org,(int)$this->user['id'],$event,'TEXTBOOK_TRANSFER',$id,json_encode($info,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
    }
    private function document(int $id,bool $lock=false):array {
        self::check($id>0,'Select a transfer');
        $row=one($this->db,'SELECT id,organization_id,display_number,created_at FROM document_registry WHERE doc_type=? AND id=?'.($lock?' FOR UPDATE':''),[self::TYPE,$id]);
        self::check($row!==null,'Textbook transfer not found',404);
        $latest=one($this->db,'SELECT version_no,snapshot_json,created_at FROM document_versions WHERE organization_id=? AND doc_type=? AND entity_id=? ORDER BY version_no DESC LIMIT 1',
            [(int)$row['organization_id'],self::TYPE,$id]);
        self::check($latest!==null,'Transfer history is incomplete; contact the administrator',409);
        $s=json_decode((string)$latest['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
        self::check(is_array($s)&&($s['type']??'')===self::TYPE && (int)($s['source_organization_id']??0)===(int)$row['organization_id']
            && (int)($s['destination_school_id']??0)>0,'Transfer provenance failed verification',409);
        return ['id'=>$id,'source_organization_id'=>(int)$row['organization_id'],
            'number'=>(string)$row['display_number'],'version'=>(int)$latest['version_no'],'snapshot'=>$s];
    }
    private function viewed(int $id,int $workspaceId,bool $lock=false):array {
        $o=$this->office($workspaceId,true);
        $r=$this->document($id,$lock);$s=$r['snapshot'];
        self::check($workspaceId===(int)$s['source_organization_id']||
            ($o['kind']==='SCHOOL' && $workspaceId===(int)$s['destination_school_id']),
            'This transfer does not belong to the selected office',403);
        return $r;
    }
    private function sourceDocument(int $id,int $workspaceId,bool $lock=true):array {
        $r=$this->viewed($id,$workspaceId,$lock);
        self::check($r['source_organization_id']===$workspaceId,'Only the issuing office can perform this action',403);
        return $r;
    }
    private function destDocument(int $id,int $workspaceId,bool $lock=true):array {
        $r=$this->viewed($id,$workspaceId,$lock);
        self::check((int)$r['snapshot']['destination_school_id']===$workspaceId,
            'Only the receiving school can perform this action',403);
        return $r;
    }
    private function sourceLock(int $org):void {
        self::check($this->db->inTransaction(),'Transfer requires an idempotent database transaction',500);
        self::check(one($this->db,'SELECT id FROM organizations WHERE id=? AND active=1 FOR UPDATE',[$org])!==null,
            'Issuing office is no longer active',409);
    }
    /** Only date-effective appointed Property Custodians; never infer a Division officer. */
    private function custodian(int $office,string $day):?array {
        $result=IarSignatories::resolve($this->db,$office,$day);
        $person=$result['supply_custodian']??null;
        return is_array($person)&&trim((string)($person['name']??''))!==''
            ? ['employee_id'=>(int)($person['employee_id']??0),
               'name'=>trim((string)$person['name']),
               'designation'=>trim((string)($person['designation']??''))] : null;
    }
    /** Read-only signatories, exposed only to an office party to this transfer. */
    public function signatories(array $input):array {
        $workspace=(int)($input['organization_id']??0);
        $this->office($workspace,true);
        $transfer=(int)($input['transfer_id']??0);
        if($transfer>0){
            $r=$this->viewed($transfer,$workspace);
            $src=(int)$r['snapshot']['source_organization_id'];
            $dest=(int)$r['snapshot']['destination_school_id'];
        }else{
            $office=$this->source($workspace);$src=(int)$office['id'];
            $dest=(int)($input['destination_school_id']??0);
            if($dest>0)$this->destination($office,$dest);
        }
        $issue=self::day((string)($input['issued_on']??self::today()),'signatory dispatch');
        $receive=self::day((string)($input['received_on']??self::today()),'signatory receiving');
        return ['issuer'=>$this->custodian($src,$issue),
            'receiver'=>$dest>0?$this->custodian($dest,$receive):null,
            'issuer_office_id'=>$src,'receiver_school_id'=>$dest,
            'issuer_as_of'=>$issue,'receiver_as_of'=>$receive];
    }
    /** The real paper name may differ, but the appointed person is never silently replaced. */
    private static function signatory(array $body,string $field,string $reasonField,?array $appointed):array {
        $entered=self::str($body,$field,160);
        $official=trim((string)($appointed['name']??''));
        $name=$entered!==''?$entered:$official;
        self::check(mbSafeLen($name)>=2,'Record the actual paper signatory or configure the office Property Custodian first');
        $acting=$official!=='' && strcasecmp($name,$official)!==0;
        $reason=self::str($body,$reasonField,500,$acting?8:0);
        return ['actual_name'=>$name,'official_name'=>$official,
            'official_designation'=>(string)($appointed['designation']??''),
            'official_employee_id'=>(int)($appointed['employee_id']??0),
            'acting_reason'=>$acting?$reason:''];
    }
    /** SUM latest active, unissued allocations. Acquired source org mutex first. */
    private function reservations(int $org,?int $except=null):array {
        $rs=q($this->db,"SELECT r.id,v.snapshot_json FROM document_registry r JOIN document_versions v
            ON v.organization_id=r.organization_id AND v.doc_type=r.doc_type AND v.entity_id=r.entity_id
            AND v.version_no=(SELECT MAX(z.version_no) FROM document_versions z WHERE z.organization_id=r.organization_id
                AND z.doc_type=r.doc_type AND z.entity_id=r.entity_id)
            WHERE r.organization_id=? AND r.doc_type='TBX' ORDER BY r.id DESC LIMIT 2001",[$org])->fetchAll(PDO::FETCH_ASSOC);
        self::check(count($rs)<=2000,'Too many textbook allocations to verify safely; request archive review',409);
        $held=[];
        foreach($rs as $r){
            if($except!==null&&(int)$r['id']===$except)continue;
            $s=json_decode((string)$r['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
            if(!is_array($s)||!in_array($s['status']??'',['ALLOCATED','REQUESTED'],true))continue;
            foreach($s['lines']??[] as $line){
                $batch=(int)($line['source_batch_id']??0);
                $held[$batch]=($held[$batch]??0)+(int)($line['allocated_quantity']??0);
            }
        }
        // Quarantine proposed disposal quantities from new allocations and dispatches.
        foreach(TextbookLifecycleWorkflow::disposalHolds($this->db,$org) as $batch=>$qty)
            $held[$batch]=($held[$batch]??0)+$qty;
        return $held;
    }
    private static function serviceable(string $condition):bool {
        return in_array(strtolower(trim($condition)),['serviceable','good','new - serviceable','new/serviceable'],true);
    }
    private function batches(int $org,array $reservations,bool $lock=false):array {
        $rows=q($this->db,"SELECT b.id,b.title_id,b.batch,b.storage,b.condition_note,t.title,t.isbn,t.grade,t.subject,
                COALESCE(SUM(m.quantity_delta),0) AS on_hand
            FROM textbook_batches b JOIN textbook_titles t ON t.id=b.title_id
            LEFT JOIN textbook_movements m ON m.batch_id=b.id AND m.organization_id=b.organization_id
            WHERE b.organization_id=? GROUP BY b.id,b.title_id,b.batch,b.storage,b.condition_note,t.title,t.isbn,t.grade,t.subject
            ORDER BY b.id LIMIT 2001",[$org])->fetchAll(PDO::FETCH_ASSOC);
        self::check(count($rows)<=2000,'Too many textbook batches to verify safely; request paginated inventory review',409);
        $out=[];
        foreach($rows as $r){
            if(!self::serviceable((string)($r['condition_note']??'')))continue;
            try{$isbn=TextbookIsbn::canonical((string)$r['isbn']);}catch(InvalidArgumentException $e){continue;}
            $onHand=(int)$r['on_hand'];$reserved=(int)($reservations[(int)$r['id']]??0);
            self::check($reserved<=$onHand,'Open allocation exceeds source stock. Reconcile before transfer',409);
            $out[(int)$r['id']]=['id'=>(int)$r['id'],'title_id'=>(int)$r['title_id'],'title'=>(string)$r['title'],
                'isbn13'=>$isbn,'grade'=>(string)($r['grade']??''),'subject'=>(string)($r['subject']??''),
                'batch'=>(string)$r['batch'],'storage'=>(string)($r['storage']??''),
                'on_hand'=>$onHand,'reserved'=>$reserved,'available'=>$onHand-$reserved];
        }
        return $out;
    }
    public function destinations(array $input):array {
        $source=$this->source((int)($input['organization_id']??0));
        $division=(int)($source['kind']==='DIVISION'?$source['id']:$source['parent_division_id']);
        self::check($division>0,'A current parent Division is required',409);
        return q($this->db,"SELECT id,name FROM organizations WHERE active=1 AND kind='SCHOOL' AND parent_division_id=? AND id<>? ORDER BY name,id LIMIT 300",
            [$division,(int)$source['id']])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function available(array $input):array {
        $org=(int)($input['organization_id']??0);$this->source($org);
        return array_values(array_filter($this->batches($org,$this->reservations($org)),static fn(array $r):bool=>$r['available']>0));
    }
    private function lines(int $org,array $provided,array $reservations):array {
        self::check(array_is_list($provided) && count($provided)>=1 && count($provided)<=30,
            'Select between 1 and 30 serviceable textbook batches');
        $stocks=$this->batches($org,$reservations);$out=[];$seen=[];
        foreach($provided as $row){
            self::check(is_array($row),'Invalid textbook allocation line');
            $batch=(int)($row['batch_id']??0);
            self::check($batch>0 && !isset($seen[$batch]),'Select each source batch once');$seen[$batch]=true;
            self::check(isset($stocks[$batch]),'Select serviceable source books with valid ISBN',409);
            $qty=self::qty($row['quantity']??null,'allocated quantity');
            self::check($qty<=$stocks[$batch]['available'],'Requested quantity exceeds currently unreserved serviceable stock',409);
            $b=$stocks[$batch];
            $out[]=['line_no'=>count($out)+1,'source_batch_id'=>$batch,'title_id'=>$b['title_id'],
                'title'=>$b['title'],'isbn13'=>$b['isbn13'],'grade'=>$b['grade'],'subject'=>$b['subject'],
                'source_batch'=>$b['batch'],'allocated_quantity'=>$qty,'requested_quantity'=>null,
                'issued_quantity'=>0,'received_serviceable'=>0,'received_damaged'=>0];
        }
        return $out;
    }
    private function createDocument(int $org,array $s,string $action):array {
        $period=substr(self::today(),0,7);
        $number=AnnualDocumentNumbering::reserve($this->db,$org,self::TYPE,$period);
        q($this->db,'INSERT INTO document_registry(organization_id,doc_type,period,display_number,entity_id) VALUES (?,?,?,?,0)',
            [$org,self::TYPE,$period,$number]);
        $id=(int)$this->db->lastInsertId();self::check($id>0,'Transfer could not be registered',500);
        self::check(q($this->db,'UPDATE document_registry SET entity_id=? WHERE id=? AND organization_id=? AND entity_id=0',[$id,$id,$org])->rowCount()===1,
            'Transfer identity could not be linked',500);
        $s['number']=$number;$this->version($org,$id,1,$s,$action);
        $this->audit($org,'TEXTBOOK_TRANSFER_'.$action,$id,['path'=>$s['path'],'destination'=>$s['destination_school_id']]);
        return ['id'=>$id,'number'=>$number,'status'=>$s['status'],'path'=>$s['path']];
    }
    private function base(array $src,array $dest,string $path,array $lines):array {
        return ['type'=>self::TYPE,'source_organization_id'=>(int)$src['id'],'source_office_name'=>(string)$src['name'],
            'source_office_kind'=>(string)$src['kind'],'destination_school_id'=>(int)$dest['id'],
            'destination_school_name'=>(string)$dest['name'],'path'=>$path,
            'status'=>$path==='ALLOCATION_REQUEST'?'ALLOCATED':'PREPARED',
            'allocated_on'=>$path==='ALLOCATION_REQUEST'?self::today():null,
            'created_on'=>self::today(),'created_by'=>(int)$this->user['id'],
            'request'=>null,'dispatch'=>null,'ris_number'=>null,'itr_number'=>null,
            'receipt_events'=>[],'lines'=>$lines];
    }
    /** Option A: sender publishes allocation, receiver can view/request. Stock is reserved only. */
    public function allocate(array $body):array {
        $org=(int)($body['organization_id']??0);$src=$this->source($org);
        $dest=$this->destination($src,(int)($body['destination_school_id']??0));
        $this->sourceLock($org);
        $lines=$this->lines($org,$body['lines']??[],$this->reservations($org));
        $s=$this->base($src,$dest,'ALLOCATION_REQUEST',$lines);
        $s['purpose']=self::str($body,'purpose',240);
        return $this->createDocument($org,$s,'ALLOCATED');
    }
    private static function mode(string $v):string {
        self::check(in_array($v,['ISSUER_DELIVERY','SCHOOL_PICKUP','CARGO_COURIER','OTHER'],true),
            'Select a valid delivery mode');
        return $v;
    }
    /** Option A: ONLY destination school can request; transport preference and date are retained. */
    public function request(array $body):array {
        $school=(int)($body['organization_id']??0);
        $r=$this->destDocument((int)($body['transfer_id']??0),$school,true);$s=$r['snapshot'];
        self::check($s['path']==='ALLOCATION_REQUEST' && $s['status']==='ALLOCATED',
            'Only a new allocation can be requested',409);
        $requestLines=$body['lines']??null;
        self::check(is_array($requestLines)&&array_is_list($requestLines)&&count($requestLines)===count($s['lines']),
            'Specify the number of books requested for every allocated line');
        $byNo=[];$requested=0;
        foreach($requestLines as $line){
            self::check(is_array($line),'Invalid requested line');$n=(int)($line['line_no']??0);
            self::check($n>0&&!isset($byNo[$n]),'Duplicate requested line number');$byNo[$n]=$line;
        }
        foreach($s['lines'] as &$line){
            self::check(isset($byNo[$line['line_no']]),'Request is missing a book line');
            $qty=self::qty($byNo[$line['line_no']]['quantity']??null,'request quantity',true);
            self::check($qty<=(int)$line['allocated_quantity'],'Request exceeds the advertised allocation',409);
            $line['requested_quantity']=$qty;$requested+=$qty;
        }unset($line);
        self::check($requested>0,'Request at least one copy');
        $mode=self::mode((string)($body['preferred_delivery_mode']??''));
        $date=self::day((string)($body['preferred_delivery_date']??''),'preferred delivery',true);
        $s['request']=['request_on'=>self::today(),'requested_by_name'=>self::str($body,'requester_name',160,2),
            'preferred_delivery_mode'=>$mode,'preferred_delivery_date'=>$date,
            'delivery_instructions'=>self::str($body,'delivery_instructions',400),
            'requested_quantity'=>$requested,'encoded_by'=>(int)$this->user['id']];
        $s['status']='REQUESTED';
        $this->version($r['source_organization_id'],$r['id'],$r['version']+1,$s,
            'School recorded requisition with preferred mode and delivery date; no stock movement');
        $this->audit($r['source_organization_id'],'TEXTBOOK_TRANSFER_REQUESTED',$r['id'],
            ['request_school'=>$school,'preferred_mode'=>$mode,'preferred_date'=>$date]);
        return ['id'=>$r['id'],'number'=>$r['number'],'status'=>$s['status'],'requested_quantity'=>$requested];
    }
    private function dispatchLines(int $org,array &$s,array $provided,?int $except):void {
        self::check(is_array($provided)&&array_is_list($provided)&&count($provided)===count($s['lines']),
            'Enter actual issued copies for every book line');
        $stocks=$this->batches($org,$this->reservations($org,$except));$byNo=[];$total=0;
        foreach($provided as $l){
            self::check(is_array($l),'Invalid issued line');$n=(int)($l['line_no']??0);
            self::check($n>0&&!isset($byNo[$n]),'Duplicate issued line');$byNo[$n]=$l;
        }
        foreach($s['lines'] as &$line){
            $no=$line['line_no'];self::check(isset($byNo[$no]),'Missing issued book line');
            $count=self::qty($byNo[$no]['quantity']??null,'issue quantity',true);
            $cap=$s['path']==='ALLOCATION_REQUEST'?(int)$line['requested_quantity']:(int)$line['allocated_quantity'];
            self::check($count<=$cap,'Cannot issue more than the approved school request or direct transfer quantity',409);
            $batch=(int)$line['source_batch_id'];$row=$stocks[$batch]??null;
            self::check($row!==null && $count<=$row['available'],
                'Serviceable source quantity changed; refresh before issuing',409);
            $line['issued_quantity']=$count;$total+=$count;
        }unset($line);
        self::check($total>0,'Issue at least one textbook');
    }
    private function postDispatch(int $org,int $id,string $number,int $version,array &$s,array $body):void {
        $issuedOn=self::day((string)($body['issued_on']??''),'actual dispatch');
        self::check($issuedOn>=($s['allocated_on']??$s['created_on']),
            'Physical dispatch cannot predate the recorded allocation or transfer creation');
        $this->dispatchLines($org,$s,$body['lines']??null,$s['path']==='ALLOCATION_REQUEST'?$id:null);
        $mode=self::mode((string)($body['actual_delivery_mode']??''));
        if($s['path']==='DIRECT_TRANSFER')self::check($mode==='ISSUER_DELIVERY',
            'Direct transfer without advance request must be delivered by the issuing office');
        $short=0;
        foreach($s['lines'] as $line){
            $expect=$s['path']==='ALLOCATION_REQUEST'?(int)$line['requested_quantity']:(int)$line['allocated_quantity'];
            $short+=$expect-(int)$line['issued_quantity'];
        }
        $shortReason=self::str($body,'short_issue_reason',500);
        self::check($short===0||mbSafeLen($shortReason)>=8,
            'Explain why the number of books issued is less than the recorded request/transfer');
        $ris=AnnualDocumentNumbering::reserve($this->db,$org,'RIS',substr(self::today(),0,7));
        $itr=AnnualDocumentNumbering::reserve($this->db,$org,'ITR',substr(self::today(),0,7));
        $s['ris_number']=$ris;$s['itr_number']=$itr;
        $officialIssuer=$this->custodian($org,$issuedOn);
        $officialReceiver=$this->custodian((int)$s['destination_school_id'],$issuedOn);
        $issuer=self::signatory($body,'issued_by_name','issued_by_reason',$officialIssuer);
        $s['dispatch']=['issued_on'=>$issuedOn,'delivery_mode'=>$mode,'issued_by_name'=>$issuer['actual_name'],
            'appointed_issuer_name'=>$issuer['official_name'],
            'appointed_issuer_designation'=>$issuer['official_designation'],
            'appointed_issuer_employee_id'=>$issuer['official_employee_id'],
            'acting_issuer_reason'=>$issuer['acting_reason'],
            'receiving_custodian_name'=>(string)($officialReceiver['name']??''),
            'receiving_custodian_designation'=>(string)($officialReceiver['designation']??''),
            'receiving_custodian_employee_id'=>(int)($officialReceiver['employee_id']??0),
            'carrier_or_receiver'=>self::str($body,'released_to_name',160),
            'paper_reference'=>self::str($body,'dispatch_reference',160),
            'remarks'=>self::str($body,'dispatch_remarks',500),'short_issue_reason'=>$shortReason,
            'encoded_by'=>(int)$this->user['id']];
        foreach($s['lines'] as $line){
            $qty=(int)$line['issued_quantity'];if($qty===0)continue;
            $label='TBX '.$number.' / ITR '.$itr.' / ISBN '.$line['isbn13'];
            self::check(mbSafeLen($label)<=200,'Transfer reference is too long',409);
            q($this->db,'INSERT INTO textbook_movements(organization_id,batch_id,quantity_delta,event_type,reference,actor_id) VALUES (?,?,?,?,?,?)',
                [$org,(int)$line['source_batch_id'],-$qty,'TRANSFER',$label,(int)$this->user['id']]);
        }
        $s['status']='IN_TRANSIT';
        $this->version($org,$id,$version+1,$s,
            'Physical dispatch recorded with RIS/ITR; source stock debited, receiving school not credited');
        $this->audit($org,'TEXTBOOK_TRANSFER_DISPATCHED',$id,['ris'=>$ris,'itr'=>$itr,'issued_on'=>$issuedOn]);
    }
    /** Option A: source records issue after receiving school's documentary request. */
    public function dispatchRequested(array $body):array {
        $org=(int)($body['organization_id']??0);
        $r=$this->sourceDocument((int)($body['transfer_id']??0),$org,true);$s=$r['snapshot'];
        self::check($s['path']==='ALLOCATION_REQUEST'&&$s['status']==='REQUESTED',
            'This allocation is not ready for issue',409);
        $this->sourceLock($org);
        $this->postDispatch($org,$r['id'],$r['number'],$r['version'],$s,$body);
        return ['id'=>$r['id'],'number'=>$r['number'],'status'=>'IN_TRANSIT','ris_number'=>$s['ris_number'],'itr_number'=>$s['itr_number']];
    }
    /** Option B: no pre-allocation notice or school request. Dispatch is the first receiver-visible record. */
    public function dispatchDirect(array $body):array {
        $org=(int)($body['organization_id']??0);$src=$this->source($org);
        $dest=$this->destination($src,(int)($body['destination_school_id']??0));
        $this->sourceLock($org);
        $lines=$this->lines($org,$body['lines']??[],$this->reservations($org));
        $s=$this->base($src,$dest,'DIRECT_TRANSFER',$lines);
        $s['purpose']=self::str($body,'purpose',240);
        $created=$this->createDocument($org,$s,'DIRECT_PREPARED');
        $this->postDispatch($org,$created['id'],$created['number'],1,$s,
            array_merge($body,['lines'=>array_map(static fn(array $l):array=>['line_no'=>$l['line_no'],'quantity'=>$l['allocated_quantity']],$lines)]));
        return ['id'=>$created['id'],'number'=>$created['number'],'status'=>'IN_TRANSIT',
            'ris_number'=>$s['ris_number'],'itr_number'=>$s['itr_number']];
    }
    /** Receiving school transcribes actual paper receipt. Repeat for genuine partial deliveries. */
    public function receive(array $body):array {
        $school=(int)($body['organization_id']??0);
        $r=$this->destDocument((int)($body['transfer_id']??0),$school,true);$s=$r['snapshot'];
        self::check(in_array($s['status'],['IN_TRANSIT','PARTIALLY_RECEIVED'],true),
            'Only dispatched textbooks awaiting actual receipt may be recorded',409);
        $date=self::day((string)($body['received_on']??''),'physical receipt');
        self::check($date>=(string)$s['dispatch']['issued_on'],'Receiving date cannot precede physical dispatch');
        $receiver=self::signatory($body,'received_by_name','received_by_reason',
            $this->custodian($school,$date));
        $receivedBy=$receiver['actual_name'];
        $reference=self::str($body,'receipt_reference',160);
        $remarks=self::str($body,'receipt_remarks',500);
        $rows=$body['lines']??null;
        self::check(is_array($rows)&&array_is_list($rows)&&count($rows)===count($s['lines']),
            'Record the physical counts for every issued book line');
        $byNo=[];foreach($rows as $line){
            self::check(is_array($line),'Invalid receiving line');$n=(int)($line['line_no']??0);
            self::check($n>0&&!isset($byNo[$n]),'Duplicate receiving line');$byNo[$n]=$line;
        }
        $batchRows=[];$received=0;$pending=0;$eventNo=count($s['receipt_events'])+1;
        foreach($s['lines'] as &$line){
            $n=(int)$line['line_no'];$input=$byNo[$n]??null;
            self::check(is_array($input),'A book line was omitted');
            $good=self::qty($input['serviceable_quantity']??null,'received serviceable count',true);
            $bad=self::qty($input['damaged_quantity']??null,'received damaged count',true);
            $remaining=(int)$line['issued_quantity']-(int)$line['received_serviceable']-(int)$line['received_damaged'];
            self::check($remaining>=0 && $good+$bad<=$remaining,'Recorded receipt exceeds unresolved dispatched books',409);
            $storage=self::str($input,'storage',160);
            foreach([['qty'=>$good,'condition'=>'Serviceable','suffix'=>'S'],['qty'=>$bad,'condition'=>'Damaged - Quarantined','suffix'=>'Q']] as $kind){
                if($kind['qty']===0)continue;
                $lot='TBX-'.$r['id'].'-L'.$n.'-E'.$eventNo.'-'.$kind['suffix'];
                q($this->db,'INSERT INTO textbook_batches(organization_id,title_id,batch,condition_note,storage,opening_record) VALUES (?,?,?,?,?,0)',
                    [$school,(int)$line['title_id'],$lot,$kind['condition'],$storage]);
                $newBatch=(int)$this->db->lastInsertId();
                self::check($newBatch>0,'Receiving batch could not be created',500);
                $label='TBX '.$r['number'].' / ITR '.$s['itr_number'].' / '.$kind['condition'];
                self::check(mbSafeLen($label)<=200,'Receiving reference is too long',409);
                q($this->db,'INSERT INTO textbook_movements(organization_id,batch_id,quantity_delta,event_type,reference,actor_id) VALUES (?,?,?,?,?,?)',
                    [$school,$newBatch,$kind['qty'],'RECEIPT',$label,(int)$this->user['id']]);
                $batchRows[]=['line_no'=>$n,'batch_id'=>$newBatch,'condition'=>$kind['condition'],'quantity'=>$kind['qty']];
            }
            $line['received_serviceable']+=$good;$line['received_damaged']+=$bad;
            $received+=$good+$bad;
            $pending+=(int)$line['issued_quantity']-(int)$line['received_serviceable']-(int)$line['received_damaged'];
        }unset($line);
        self::check($received>0,'Enter at least one actually received textbook');
        $s['receipt_events'][]=['received_on'=>$date,'received_by_name'=>$receivedBy,
            'appointed_receiver_name'=>$receiver['official_name'],
            'appointed_receiver_designation'=>$receiver['official_designation'],
            'appointed_receiver_employee_id'=>$receiver['official_employee_id'],
            'acting_receiver_reason'=>$receiver['acting_reason'],
            'receipt_reference'=>$reference,'remarks'=>$remarks,'encoded_by'=>(int)$this->user['id'],
            'batch_postings'=>$batchRows,'quantity'=>$received];
        $s['status']=$pending===0?'RECEIVED':'PARTIALLY_RECEIVED';
        $this->version($r['source_organization_id'],$r['id'],$r['version']+1,$s,
            'Receiving school recorded actual paper receipt; destination stock credited exactly once');
        $this->audit($school,'TEXTBOOK_TRANSFER_PHYSICAL_RECEIPT',$r['id'],
            ['quantity'=>$received,'outstanding'=>$pending,'source'=>$r['source_organization_id']]);
        return ['id'=>$r['id'],'number'=>$r['number'],'status'=>$s['status'],
            'received_now'=>$received,'still_in_transit'=>$pending];
    }
    public function cancelAllocation(array $body):array {
        $org=(int)($body['organization_id']??0);
        $r=$this->sourceDocument((int)($body['transfer_id']??0),$org,true);$s=$r['snapshot'];
        self::check($s['path']==='ALLOCATION_REQUEST'&&in_array($s['status'],['ALLOCATED','REQUESTED'],true),
            'Only unissued allocations may be cancelled',409);
        $reason=self::str($body,'reason',500,8);
        $s['status']='CANCELLED';$s['cancel_reason']=$reason;
        $this->version($org,$r['id'],$r['version']+1,$s,'Allocation cancelled: '.$reason);
        $this->audit($org,'TEXTBOOK_TRANSFER_CANCELLED',$r['id'],['reason'=>$reason]);
        return ['id'=>$r['id'],'number'=>$r['number'],'status'=>'CANCELLED'];
    }
    public function detail(array $input):array {
        $r=$this->viewed((int)($input['transfer_id']??0),(int)($input['organization_id']??0));
        return $r;
    }
    public function listing(array $input):array {
        $org=(int)($input['organization_id']??0);$this->office($org,true);
        $before=(int)($input['before_id']??0);$before=$before>0?$before:PHP_INT_MAX;
        $sql="SELECT r.id,r.organization_id,r.display_number,v.version_no,v.snapshot_json
            FROM document_registry r JOIN document_versions v ON v.organization_id=r.organization_id
                AND v.doc_type=r.doc_type AND v.entity_id=r.entity_id
                AND v.version_no=(SELECT MAX(z.version_no) FROM document_versions z WHERE z.organization_id=r.organization_id
                    AND z.doc_type=r.doc_type AND z.entity_id=r.entity_id)
            WHERE r.doc_type='TBX' AND r.id<? AND (r.organization_id=? OR
                CAST(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.destination_school_id')) AS UNSIGNED)=?)
            ORDER BY r.id DESC LIMIT 51";
        $rows=q($this->db,$sql,[$before,$org,$org])->fetchAll(PDO::FETCH_ASSOC);
        $more=count($rows)>50;$rows=array_slice($rows,0,50);$result=[];
        foreach($rows as $r){
            $s=json_decode((string)$r['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
            self::check(is_array($s)&&($s['type']??'')===self::TYPE
                && (int)$s['source_organization_id']===(int)$r['organization_id']
                && ($org===(int)$s['source_organization_id']||$org===(int)$s['destination_school_id']),
                'Transfer directory scope requires review',409);
            if($s['path']==='DIRECT_TRANSFER'&&$org===(int)$s['destination_school_id']&&$s['status']==='PREPARED')continue;
            $result[]=['id'=>(int)$r['id'],'number'=>$r['display_number'],'version'=>(int)$r['version_no'],
                'source'=>$s['source_office_name'],'destination'=>$s['destination_school_name'],
                'source_organization_id'=>(int)$s['source_organization_id'],'destination_school_id'=>(int)$s['destination_school_id'],
                'path'=>$s['path'],'status'=>$s['status'],'ris_number'=>$s['ris_number'], 'itr_number'=>$s['itr_number'],
                'lines'=>count($s['lines']),
                'total_allocated'=>array_sum(array_column($s['lines'],'allocated_quantity')),
                'total_issued'=>array_sum(array_column($s['lines'],'issued_quantity')),
                'total_received'=>array_sum(array_map(static fn($l)=>(int)$l['received_serviceable']+(int)$l['received_damaged'],$s['lines'])),
                'preferred_delivery_date'=>$s['request']['preferred_delivery_date']??null,
                'preferred_delivery_mode'=>$s['request']['preferred_delivery_mode']??null];
        }
        return ['rows'=>$result,'next_before_id'=>$more&&count($rows)?(int)$rows[count($rows)-1]['id']:null];
    }
    /**
     * Action Center must not silently lose an open transfer once more than 50
     * historical rows exist. Query pending snapshots directly and cap at 500.
     * No stock, cross-school drafts, or other offices' records are exposed.
     */
    private function pendingRows(int $org,bool $asDestination):array {
        $kind=$asDestination?'destination_school_id':'source_organization_id';
        $filter=$asDestination?"CAST(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.destination_school_id')) AS UNSIGNED)=?"
            :'r.organization_id=?';
        $sql="SELECT r.id,r.organization_id,r.display_number,v.snapshot_json
            FROM document_registry r JOIN document_versions v ON v.organization_id=r.organization_id
                AND v.doc_type=r.doc_type AND v.entity_id=r.entity_id
                AND v.version_no=(SELECT MAX(z.version_no) FROM document_versions z WHERE z.organization_id=r.organization_id
                    AND z.doc_type=r.doc_type AND z.entity_id=r.entity_id)
            WHERE r.doc_type='TBX' AND ".$filter." AND JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.status'))
                IN ('ALLOCATED','REQUESTED','IN_TRANSIT','PARTIALLY_RECEIVED') ORDER BY r.id DESC LIMIT 501";
        $rs=q($this->db,$sql,[$org])->fetchAll(PDO::FETCH_ASSOC);
        self::check(count($rs)<=500,'Open textbook transfers exceed the Action Center limit; request record review',409);
        $items=[];
        foreach($rs as $r){
            $s=json_decode((string)$r['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
            self::check(is_array($s)&&($s['type']??'')===self::TYPE
                && (int)($s['source_organization_id']??0)===(int)$r['organization_id']
                && (int)($s[$kind]??0)===$org,'Transfer Action Center scope failed verification',409);
            $items[]=['id'=>(int)$r['id'],'number'=>(string)$r['display_number'],'snapshot'=>$s];
        }
        return $items;
    }
    /** Issuing office Action Center: awaiting a physical issue for a school's recorded request. */
    public function pendingForSource(int $org):array {
        $this->source($org);
        $list=$this->pendingRows($org,false);$items=[];
        foreach($list as $r){
            $s=$r['snapshot'];if(($s['status']??'')!=='REQUESTED')continue;
            $items[]=['id'=>$r['id'],'number'=>$r['number'],'source'=>$s['destination_school_name'],
                'status'=>'REQUESTED','task'=>'ISSUE_REQUEST',
                'quantity'=>array_sum(array_map(static fn($l)=>(int)($l['requested_quantity']??0),$s['lines'])),
                'preferred_delivery_date'=>$s['request']['preferred_delivery_date']??null,
                'preferred_delivery_mode'=>$s['request']['preferred_delivery_mode']??null];
        }
        return $items;
    }
    /** For Action Center: only allocation/request and dispatched receiving tasks, not other offices' stock. */
    public function pendingForSchool(int $school):array {
        $this->office($school,true);
        $list=$this->pendingRows($school,true);$items=[];
        foreach($list as $r){
            $s=$r['snapshot'];$status=$s['status'];
            if($s['path']==='ALLOCATION_REQUEST'&&$status==='ALLOCATED')$task='REQUEST_ALLOCATION';
            elseif(in_array($status,['IN_TRANSIT','PARTIALLY_RECEIVED'],true))$task='RECORD_RECEIPT';
            else continue;
            $alloc=array_sum(array_map(static fn($l)=>(int)($l['allocated_quantity']??0),$s['lines']));
            $issued=array_sum(array_map(static fn($l)=>(int)($l['issued_quantity']??0),$s['lines']));
            $received=array_sum(array_map(static fn($l)=>(int)($l['received_serviceable']??0)+(int)($l['received_damaged']??0),$s['lines']));
            $items[]=['id'=>$r['id'],'number'=>$r['number'],'source'=>$s['source_office_name'],
                'status'=>$status,'task'=>$task,'quantity'=>$task==='REQUEST_ALLOCATION'?$alloc:$issued-$received];
        }
        return $items;
    }
}
