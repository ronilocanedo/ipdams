<?php
declare(strict_types=1);
require_once __DIR__.'/AnnualDocumentNumbering.php';
require_once __DIR__.'/TextbookIsbn.php';
require_once __DIR__.'/IarSignatories.php';
/**
 * v0.2.284: Teacher stewardship, physical damage, disposal proposals and reports.
 * TBI teacher issue, TBQ damage-quarantine and TBD division-authorized disposal
 * are immutable document_registry/document_versions journals, not approvals or signatures.
 * All writes execute inside the API's idempotent transaction.
 */
final class TextbookLifecycleWorkflow {
    public function __construct(private PDO $db, private array $user) {}
    private static function today():string {return (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');}
    private static function check(bool $v,string $message,int $code=422):void {demand($v,$message,$code);}
    private static function text(array $d,string $k,int $max=250,int $min=0):string {
        $v=trim((string)($d[$k]??''));self::check(mbSafeLen($v)>=$min && mbSafeLen($v)<=$max,'Check '.str_replace('_',' ',$k).' ('.$min.'–'.$max.' characters)');return $v;
    }
    private static function qty(mixed $v,string $what):int {
        self::check((is_string($v)||is_int($v)) && preg_match('/^[1-9][0-9]{0,7}$/D',(string)$v)===1,'Enter a valid positive whole-number '.$what);return (int)$v;
    }
    private static function nonneg(mixed $v,string $what):int {
        self::check((is_string($v)||is_int($v)) && preg_match('/^(0|[1-9][0-9]{0,7})$/D',(string)$v)===1,'Enter a valid whole-number '.$what);return (int)$v;
    }
    private static function day(array $d,string $key,bool $pastOnly=true):string {
        $v=self::text($d,$key,11,10);
        self::check(preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$v)===1,'Enter a valid '.str_replace('_',' ',$key).' date');
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('Asia/Manila'));
        self::check($dt!==false && $dt->format('Y-m-d')===$v && (!$pastOnly || $v<=self::today()),'Date cannot be in the future or invalid');return $v;
    }
    private function office(int $org,string $kind='ANY'):array {
        self::check($org>0,'Select your office');scope($this->db,$this->user,$org);
        $o=one($this->db,'SELECT id,name,kind,active,parent_division_id FROM organizations WHERE id=?',[$org]);
        self::check($o!==null && (int)$o['active']===1 && in_array($o['kind'],['SCHOOL','DIVISION'],true),'Office is unavailable',403);
        self::check(($this->user['role']??'')===$o['kind'], 'Use the matching authorized school or Division workspace',403);
        self::check($kind==='ANY'||$o['kind']===$kind,'This action is not available in the selected workspace',403);
        return $o;
    }
    private function school(int $org):array {return $this->office($org,'SCHOOL');}
    private function mutex(int $org):void {
        self::check($this->db->inTransaction(),'Inventory posting requires an idempotent transaction',500);
        self::check(one($this->db,'SELECT id FROM organizations WHERE id=? AND active=1 FOR UPDATE',[$org])!==null,'Office inventory cannot be locked',409);
    }
    private function audit(int $org,string $event,string $type,int $id,array $info=[]):void {
        q($this->db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
            [$org,(int)$this->user['id'],$event,$type,$id,json_encode($info,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
    }
    private function saveVersion(int $org,string $type,int $id,int $ver,array $snapshot,string $reason):void {
        q($this->db,'INSERT INTO document_versions(organization_id,doc_type,entity_id,version_no,snapshot_json,reason,actor_id) VALUES (?,?,?,?,?,?,?)',
            [$org,$type,$id,$ver,json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$reason,(int)$this->user['id']]);
    }
    private function create(int $org,string $type,array $snapshot,string $reason):array {
        $period=substr(self::today(),0,7);$number=AnnualDocumentNumbering::reserve($this->db,$org,$type,$period);
        q($this->db,'INSERT INTO document_registry(organization_id,doc_type,period,display_number,entity_id) VALUES (?,?,?,?,0)',[$org,$type,$period,$number]);
        $id=(int)$this->db->lastInsertId();self::check($id>0,'Unable to record the documentary reference',500);
        self::check(q($this->db,'UPDATE document_registry SET entity_id=? WHERE id=? AND entity_id=0 AND organization_id=?',[$id,$id,$org])->rowCount()===1,'Document identity conflict',409);
        $snapshot['number']=$number;$this->saveVersion($org,$type,$id,1,$snapshot,$reason);
        $this->audit($org,'TEXTBOOK_'.$type.'_CREATED',$type,$id,['number'=>$number]);
        return ['id'=>$id,'number'=>$number,'status'=>$snapshot['status']??'RECORDED'];
    }
    private function doc(int $id,string $type,bool $lock=false):array {
        self::check($id>0,'Select a valid document');
        $r=one($this->db,'SELECT id,organization_id,display_number FROM document_registry WHERE id=? AND doc_type=?'.($lock?' FOR UPDATE':''),[$id,$type]);
        self::check($r!==null,'Document not found',404);
        $v=one($this->db,'SELECT version_no,snapshot_json FROM document_versions WHERE organization_id=? AND doc_type=? AND entity_id=? ORDER BY version_no DESC LIMIT 1',[(int)$r['organization_id'],$type,$id]);
        self::check($v!==null,'Document version missing; contact the administrator',409);
        $s=json_decode((string)$v['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
        self::check(is_array($s)&&($s['type']??'')===$type && (int)($s['organization_id']??0)===(int)$r['organization_id'],
            'Document provenance requires review',409);
        return ['id'=>$id,'org'=>(int)$r['organization_id'],'number'=>(string)$r['display_number'],'version'=>(int)$v['version_no'],'snapshot'=>$s];
    }
    /** Latest published documents only; strict per-office cap fails closed rather than silently omitting holds. */
    private function docs(int $org,string $type,int $limit=1000):array {
        $rs=q($this->db,"SELECT r.id,r.organization_id,r.display_number,v.version_no,v.snapshot_json FROM document_registry r
          JOIN document_versions v ON v.organization_id=r.organization_id AND v.doc_type=r.doc_type AND v.entity_id=r.entity_id
            AND v.version_no=(SELECT MAX(v2.version_no) FROM document_versions v2 WHERE v2.organization_id=r.organization_id AND v2.doc_type=r.doc_type AND v2.entity_id=r.entity_id)
          WHERE r.organization_id=? AND r.doc_type=? ORDER BY r.id DESC LIMIT ".($limit+1),[$org,$type])->fetchAll(PDO::FETCH_ASSOC);
        self::check(count($rs)<=$limit,'Textbook records exceed the safe reporting limit. Request pagination before continuing',409);
        $out=[];
        foreach($rs as $r){$s=json_decode((string)$r['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
            self::check(is_array($s)&&($s['type']??'')===$type && (int)($s['organization_id']??0)===$org,'Invalid textbook document source',409);
            $out[]=['id'=>(int)$r['id'],'number'=>(string)$r['display_number'],'version'=>(int)$r['version_no'],'snapshot'=>$s];
        }return $out;
    }
    /** Holds for future disposals must be protected against issuance AND textbook transfers. */
    public static function disposalHolds(PDO $db,int $org):array {
        $rs=q($db,"SELECT v.snapshot_json FROM document_registry r JOIN document_versions v
           ON v.organization_id=r.organization_id AND v.doc_type=r.doc_type AND v.entity_id=r.entity_id
           AND v.version_no=(SELECT MAX(z.version_no) FROM document_versions z WHERE z.organization_id=r.organization_id AND z.doc_type=r.doc_type AND z.entity_id=r.entity_id)
           WHERE r.organization_id=? AND r.doc_type='TBD' ORDER BY r.id DESC LIMIT 2001",[$org])->fetchAll(PDO::FETCH_ASSOC);
        demand(count($rs)<=2000,'Disposal records require pagination review before stock writes',409);$holds=[];
        foreach($rs as $r){$s=json_decode((string)$r['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
            demand(is_array($s)&&($s['type']??'')==='TBD'&&(int)($s['organization_id']??0)===$org,'Disposal hold provenance failed',409);
            if(!in_array($s['status']??'',['MARKED','AUTHORIZED','PARTIALLY_DISPOSED'],true))continue;
            $left=(int)($s['quantity']??0)-(int)($s['disposed_quantity']??0);
            demand($left>=0,'Disposal hold balance invalid',409);
            $batch=(int)($s['batch_id']??0);$holds[$batch]=($holds[$batch]??0)+$left;
        }return $holds;
    }
    private function transferHolds(int $org):array {
        $rs=q($this->db,"SELECT v.snapshot_json FROM document_registry r JOIN document_versions v ON v.organization_id=r.organization_id
            AND v.doc_type=r.doc_type AND v.entity_id=r.entity_id
            AND v.version_no=(SELECT MAX(z.version_no) FROM document_versions z WHERE z.organization_id=r.organization_id AND z.doc_type=r.doc_type AND z.entity_id=r.entity_id)
            WHERE r.organization_id=? AND r.doc_type='TBX' ORDER BY r.id DESC LIMIT 2001",[$org])->fetchAll(PDO::FETCH_ASSOC);
        self::check(count($rs)<=2000,'Open allocations require review',409);$holds=[];
        foreach($rs as $r){$s=json_decode((string)$r['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
            if(!is_array($s)||($s['type']??'')!=='TBX')continue;
            if(!in_array($s['status']??'',['ALLOCATED','REQUESTED'],true))continue;
            foreach($s['lines']??[] as $l){$batch=(int)($l['source_batch_id']??0);$holds[$batch]=($holds[$batch]??0)+(int)($l['allocated_quantity']??0);}
        }return $holds;
    }
    private static function serviceable(string $condition):bool {return in_array(strtolower(trim($condition)),['serviceable','good','new - serviceable','new/serviceable'],true);}
    private function stock(int $org,bool $holds=true):array {
        $rs=q($this->db,"SELECT b.id,b.organization_id,b.title_id,b.batch,b.storage,b.condition_note,t.title,t.isbn,t.grade,t.subject,
            COALESCE(SUM(m.quantity_delta),0) on_hand FROM textbook_batches b
            JOIN textbook_titles t ON t.id=b.title_id LEFT JOIN textbook_movements m ON m.batch_id=b.id AND m.organization_id=b.organization_id
            WHERE b.organization_id=? GROUP BY b.id,b.organization_id,b.title_id,b.batch,b.storage,b.condition_note,t.title,t.isbn,t.grade,t.subject
            ORDER BY b.id LIMIT 2001",[$org])->fetchAll(PDO::FETCH_ASSOC);
        self::check(count($rs)<=2000,'Textbook batches exceed safe read limit',409);
        $reserved=$holds?$this->transferHolds($org):[];$disposed=$holds?self::disposalHolds($this->db,$org):[];$out=[];
        foreach($rs as $r){$id=(int)$r['id'];$on=(int)$r['on_hand'];$held=(int)($reserved[$id]??0)+(int)($disposed[$id]??0);
            self::check($on>=0 && $held<=$on,'Book stock/holds need reconciliation before posting',409);
            $out[$id]=['batch_id'=>$id,'title_id'=>(int)$r['title_id'],'title'=>(string)$r['title'],'isbn'=>(string)($r['isbn']??''),
                'grade'=>(string)($r['grade']??''),'subject'=>(string)($r['subject']??''),'batch'=>(string)$r['batch'],
                'condition'=>(string)($r['condition_note']??''),'storage'=>(string)($r['storage']??''),
                'serviceable'=>self::serviceable((string)($r['condition_note']??'')),
                'on_hand'=>$on,'held'=>$held,'available'=>$on-$held];
        }return $out;
    }
    public function available(array $d):array {
        $org=(int)($d['organization_id']??0);$this->office($org);
        return array_values(array_filter($this->stock($org),static fn(array $x):bool=>$x['available']>0));
    }
    private function batch(int $org,int $id,int $qty,bool $serviceable=false):array {
        $row=$this->stock($org)[$id]??null;
        self::check($row!==null && $row['available'] >= $qty && (!$serviceable||$row['serviceable']),
            'Insufficient unreserved'.($serviceable?' serviceable':'').' books in this office; refresh balances',409);
        return $row;
    }
    private function movement(int $org,int $batch,int $delta,string $kind,string $reference):void {
        self::check($delta!==0 && mbSafeLen($reference)<=200,'Invalid textbook movement',409);
        q($this->db,'INSERT INTO textbook_movements(organization_id,batch_id,quantity_delta,event_type,reference,actor_id) VALUES (?,?,?,?,?,?)',
            [$org,$batch,$delta,$kind,$reference,(int)$this->user['id']]);
    }
    private function quarantine(int $org,array $original,int $qty,string $reference):int {
        self::check($qty>0,'Quarantined quantity must be positive');
        $batch='QUARANTINE-'.substr($original['batch'],0,65).'-'.substr(hash('sha256',$reference),0,12);
        $storage=substr('Quarantine · '.$original['storage'],0,180);
        q($this->db,'INSERT INTO textbook_batches(organization_id,title_id,batch,condition_note,storage,opening_record) VALUES (?,?,?,?,?,0)',
            [$org,$original['title_id'],$batch,'DAMAGED - QUARANTINE',$storage]);
        $id=(int)$this->db->lastInsertId();self::check($id>0,'Unable to create quarantined batch',500);
        $this->movement($org,$id,$qty,'RETURN',$reference);return $id;
    }
    /** School encoder records real teacher and paper release; issued copies exit storage but remain accountable. */
    public function issueTeacher(array $d):array {
        $org=(int)($d['organization_id']??0);$this->school($org);$this->mutex($org);
        $when=self::day($d,'issued_on');$name=self::text($d,'teacher_name',160,2);
        $reference=self::text($d,'paper_reference',160,3);$quantity=self::qty($d['quantity']??null,'issue quantity');
        $batch=$this->batch($org,(int)($d['batch_id']??0),$quantity,true);
        $employee=(int)($d['employee_id']??0);
        if($employee>0){$person=one($this->db,"SELECT e.id,e.full_name FROM employees e JOIN employee_assignments a ON a.employee_id=e.id
               WHERE e.id=? AND a.organization_id=? AND a.effective_from<=? AND (a.effective_to IS NULL OR a.effective_to>=?) ORDER BY a.id DESC LIMIT 1",
               [$employee,$org,$when,$when]);
            self::check($person!==null && strcasecmp(trim((string)$person['full_name']),$name)===0,
                'Selected teacher is not assigned to this school on the issuance date',403);
        }
        $appointed=IarSignatories::resolve($this->db,$org,$when)['supply_custodian']??null;
        $snapshot=['type'=>'TBI','organization_id'=>$org,'status'=>'OUTSTANDING','teacher_name'=>$name,'employee_id'=>$employee,
            'issued_on'=>$when,'paper_reference'=>$reference,'appointed_issuer_name'=>(string)($appointed['name']??''),
            'appointed_issuer_designation'=>(string)($appointed['designation']??''),'school_year'=>self::text($d,'school_year',30),
            'purpose'=>self::text($d,'purpose',240),'batch_id'=>$batch['batch_id'],'title_id'=>$batch['title_id'],
            'title'=>$batch['title'],'isbn'=>$batch['isbn'],'grade'=>$batch['grade'],'batch'=>$batch['batch'],
            'issued_quantity'=>$quantity,'returned_serviceable'=>0,'returned_damaged'=>0,'lost_pending'=>0,
            'damage_notified'=>0,'events'=>[], 'encoded_by'=>(int)$this->user['id']];
        $result=$this->create($org,'TBI',$snapshot,'Recorded paper teacher issuance');
        $this->movement($org,$batch['batch_id'],-$quantity,'DISTRIBUTION','TBI '.$result['number'].' · teacher issue');
        $this->audit($org,'TEXTBOOK_TEACHER_ISSUED','TEXTBOOK_ISSUE',$result['id'],['quantity'=>$quantity,'batch_id'=>$batch['batch_id']]);
        return $result;
    }
    private function schoolIssue(array $d):array {
        $org=(int)($d['organization_id']??0);$this->school($org);
        $r=$this->doc((int)($d['issue_id']??0),'TBI',true);
        self::check($r['org']===$org,'Teacher issuance belongs to another office',403);return $r;
    }
    public function teacherEvent(array $d,string $event):array {
        $r=$this->schoolIssue($d);$org=$r['org'];$s=$r['snapshot'];$this->mutex($org);
        $when=self::day($d,'event_on');self::check($when>=$s['issued_on'],'Event cannot predate actual issuance');
        $paper=self::text($d,'paper_reference',160,3);$reason=self::text($d,'remarks',500,$event==='LOSS'?8:0);
        $remaining=(int)$s['issued_quantity']-(int)$s['returned_serviceable']-(int)$s['returned_damaged']-(int)$s['lost_pending'];
        self::check($remaining>=0,'Teacher accountability ledger is inconsistent',409);
        $good=0;$bad=0;$lost=0;
        if($event==='RETURN'){
            $good=self::nonneg($d['serviceable_quantity']??null,'returned serviceable quantity');
            $bad=self::nonneg($d['damaged_quantity']??null,'returned damaged quantity');
            $recover=self::nonneg($d['recovered_lost_quantity']??0,'previously reported lost recovered quantity');
            self::check($good+$bad>0 && $recover<=$good+$bad && $recover<=(int)$s['lost_pending'],
                'Record returned copies and an eligible recovered-lost quantity');
            self::check($good+$bad<=$remaining+$recover,'Return exceeds outstanding teacher copies',409);
            self::check($bad===0 || mbSafeLen($reason)>=8,'Describe the damaged books in observations');
            if($good>0)$this->movement($org,(int)$s['batch_id'],$good,'RETURN','TBI '.$r['number'].' · '.substr($paper,0,125));
            if($bad>0){$stock=['title_id'=>(int)$s['title_id'],'batch'=>(string)$s['batch'],'storage'=>'Teacher return'];
                $this->quarantine($org,$stock,$bad,'TBI '.$r['number'].' · damaged return');}
            $s['returned_serviceable']+=(int)$good;$s['returned_damaged']+=(int)$bad;$s['lost_pending']-=$recover;
            $s['damage_notified']=max(0,(int)$s['damage_notified']-$bad);
        }elseif($event==='LOSS'){
            $lost=self::qty($d['quantity']??null,'reported loss');self::check($lost<=$remaining,'Reported loss exceeds outstanding teacher copies',409);
            $s['lost_pending']+=$lost;
            $s['damage_notified']=min((int)$s['damage_notified'], $remaining-$lost);
            // No second stock debit: the books already exited storeroom on teacher issue.
            // Outstanding loss remains accountable pending prescribed relief from accountability.
        }elseif($event==='DAMAGE'){
            $bad=self::qty($d['quantity']??null,'reported damage');
            self::check($bad+(int)$s['damage_notified']<=$remaining,'Damaged copies exceed outstanding teacher copies',409);
            self::check(mbSafeLen($reason)>=8,'Describe the damage and physical evidence');
            $s['damage_notified']+=$bad;
            // Damage while still with a teacher is documentary, not physical stock receipt.
        }else{throw new DomainError('Unsupported teacher book event',422);}
        $s['events'][]=['kind'=>$event,'event_on'=>$when,'paper_reference'=>$paper,'remarks'=>$reason,
            'serviceable_quantity'=>$good,'damaged_quantity'=>$bad,'lost_quantity'=>$lost,
            'damage_reported_quantity'=>$event==='DAMAGE'?$bad:0,
            'recovered_lost_quantity'=>$event==='RETURN'?$recover:0,'actor_id'=>(int)$this->user['id']];
        self::check(count($s['events'])<=400,'Teacher event history requires archival review',409);
        $s['status']=(int)$s['issued_quantity']===(int)$s['returned_serviceable']+(int)$s['returned_damaged']
            ? 'RETURNED' : ((int)$s['lost_pending']>0?'LOSS_REPORTED':'OUTSTANDING');
        $this->saveVersion($org,'TBI',$r['id'],$r['version']+1,$s,'Recorded '.$event.' from actual teacher accountability documents');
        $this->audit($org,'TEXTBOOK_TEACHER_'.$event,'TEXTBOOK_ISSUE',$r['id'],['paper_reference'=>$paper]);
        return ['id'=>$r['id'],'number'=>$r['number'],'status'=>$s['status']];
    }
    public function returnTeacher(array $d):array {return $this->teacherEvent($d,'RETURN');}
    public function reportLoss(array $d):array {return $this->teacherEvent($d,'LOSS');}
    public function reportTeacherDamage(array $d):array {return $this->teacherEvent($d,'DAMAGE');}
    /** Physical damage in storage: ledger-neutral transfer to quarantined lot. */
    public function markDamagedStock(array $d):array {
        $org=(int)($d['organization_id']??0);$this->office($org);$this->mutex($org);
        $quantity=self::qty($d['quantity']??null,'damage quantity');$stock=$this->batch($org,(int)($d['batch_id']??0),$quantity,true);
        $date=self::day($d,'damage_on');$reason=self::text($d,'reason',500,8);
        $paper=self::text($d,'paper_reference',160,3);
        $s=['type'=>'TBQ','organization_id'=>$org,'status'=>'QUARANTINED','date'=>$date,'paper_reference'=>$paper,
            'batch_id'=>$stock['batch_id'],'title_id'=>$stock['title_id'],'title'=>$stock['title'],'isbn'=>$stock['isbn'],
            'quantity'=>$quantity,'reason'=>$reason,'encoded_by'=>(int)$this->user['id']];
        $out=$this->create($org,'TBQ',$s,'Recorded damaged storeroom copies in quarantine');
        $this->movement($org,$stock['batch_id'],-$quantity,'TRANSFER','TBQ '.$out['number'].' · moved to quarantine');
        $this->quarantine($org,$stock,$quantity,'TBQ '.$out['number'].' · quarantined stock');
        return $out;
    }
    /** Marking is a hold only; no physical stock movement. */
    public function markForDisposal(array $d):array {
        $org=(int)($d['organization_id']??0);$o=$this->office($org);$this->mutex($org);
        $quantity=self::qty($d['quantity']??null,'proposed disposal quantity');
        $stock=$this->batch($org,(int)($d['batch_id']??0),$quantity);
        $reason=self::text($d,'reason',500,8);$reference=self::text($d,'paper_reference',160,3);
        $date=self::day($d,'marked_on');
        $s=['type'=>'TBD','organization_id'=>$org,'organization_name'=>$o['name'],'division_id'=>(int)($o['kind']==='DIVISION'?$o['id']:$o['parent_division_id']),
            'status'=>'MARKED','marked_on'=>$date,'batch_id'=>$stock['batch_id'],'title_id'=>$stock['title_id'],'title'=>$stock['title'],
            'isbn'=>$stock['isbn'],'batch'=>$stock['batch'],'condition'=>$stock['condition'],'quantity'=>$quantity,
            'reason'=>$reason,'paper_reference'=>$reference,'authorized'=>null,'disposed_quantity'=>0,'disposals'=>[],
            'encoded_by'=>(int)$this->user['id']];
        self::check($s['division_id']>0,'Supervising Division is required for disposal review',409);
        return $this->create($org,'TBD',$s,'Marked physical books for Division review; stock held without deduction');
    }
    private function disposalDoc(int $id):array {return $this->doc($id,'TBD',true);}
    /** Division records approval contained in an actual signed paper authorization. No in-app e-signature. */
    public function divisionDecision(array $d,bool $approve):array {
        $division=(int)($d['organization_id']??0);$this->office($division,'DIVISION');
        $r=$this->disposalDoc((int)($d['disposal_id']??0));$s=$r['snapshot'];
        self::check($s['status']==='MARKED','Only awaiting-authorization disposal proposals can be decided',409);
        self::check((int)$s['division_id']===$division,'Only the supervising Division can record this authorization',403);
        $source=one($this->db,'SELECT id,kind,active,parent_division_id FROM organizations WHERE id=?',[$r['org']]);
        self::check($source!==null && (int)$source['active']===1 && ($source['kind']==='DIVISION'?(int)$source['id']===$division:(int)$source['parent_division_id']===$division),
            'The proposal source is no longer under this Division; technical review required',409);
        $date=self::day($d,'decision_on');self::check($date>=$s['marked_on'],'Authorization cannot predate proposal');
        $ref=self::text($d,'authority_reference',160,5);$signatory=self::text($d,'authorizing_officer',160,3);
        $reason=self::text($d,'remarks',500,$approve?0:8);
        $s['authorized']=['decision'=>$approve?'AUTHORIZED':'NOT_AUTHORIZED','date'=>$date,'paper_reference'=>$ref,
            'authorizing_officer'=>$signatory,'remarks'=>$reason,'encoded_by'=>(int)$this->user['id']];
        $s['status']=$approve?'AUTHORIZED':'REJECTED';
        $this->saveVersion($r['org'],'TBD',$r['id'],$r['version']+1,$s,
            $approve?'Division recorded written disposal authorization':'Division recorded written denial of disposal');
        $this->audit($r['org'],$approve?'TEXTBOOK_DISPOSAL_AUTHORIZED':'TEXTBOOK_DISPOSAL_DENIED','TEXTBOOK_DISPOSAL',$r['id'],
            ['division_id'=>$division,'reference'=>$ref]);
        return ['id'=>$r['id'],'number'=>$r['number'],'status'=>$s['status']];
    }
    /** Owner may withdraw an undecided proposal based on a paper correction; release its stock hold. */
    public function withdrawDisposal(array $d):array {
        $org=(int)($d['organization_id']??0);$this->office($org);
        $r=$this->disposalDoc((int)($d['disposal_id']??0));
        self::check($r['org']===$org,'Only the issuing office may withdraw its proposal',403);
        $s=$r['snapshot'];self::check($s['status']==='MARKED',
            'Only undecided proposals may be withdrawn; record Division decision or actual disposal instead',409);
        $this->mutex($org);
        $reason=self::text($d,'reason',500,8);
        $s['status']='WITHDRAWN';$s['withdrawn_on']=self::today();$s['withdraw_reason']=$reason;
        $s['withdrawn_by']=(int)$this->user['id'];
        $this->saveVersion($org,'TBD',$r['id'],$r['version']+1,$s,'Corrected/withdrawn an undecided textbook disposal proposal');
        $this->audit($org,'TEXTBOOK_DISPOSAL_WITHDRAWN','TEXTBOOK_DISPOSAL',$r['id'],['reason'=>$reason]);
        return ['id'=>$r['id'],'number'=>$r['number'],'status'=>'WITHDRAWN'];
    }
    public function authorizeDisposal(array $d):array {return $this->divisionDecision($d,true);}
    public function denyDisposal(array $d):array {return $this->divisionDecision($d,false);}
    /** Actual disposal can only be recorded after a verified Division paper authority. */
    public function recordDisposal(array $d):array {
        $org=(int)($d['organization_id']??0);$this->office($org);
        $r=$this->disposalDoc((int)($d['disposal_id']??0));self::check($r['org']===$org,'Only the owning office records physical disposal',403);
        $s=$r['snapshot'];self::check(in_array($s['status'],['AUTHORIZED','PARTIALLY_DISPOSED'],true)
            && ($s['authorized']['decision']??null)==='AUTHORIZED' && mbSafeLen((string)($s['authorized']['paper_reference']??''))>=5,
            'Written Division disposal authorization must be recorded first',409);
        $this->mutex($org);
        $date=self::day($d,'disposed_on');self::check($date>=$s['authorized']['date'],'Disposal cannot predate written authorization');
        $qty=self::qty($d['quantity']??null,'disposal quantity');
        self::check($qty<=(int)$s['quantity']-(int)$s['disposed_quantity'],'Disposal exceeds authorized remaining quantity',409);
        $method=self::text($d,'method',35,3);
        self::check(in_array($method,['DESTRUCTION','RECYCLING','SALE','DONATION','OTHER'],true),'Select a valid recorded disposal method');
        $ref=self::text($d,'paper_reference',160,5);$remarks=self::text($d,'remarks',500);
        // Own hold is included in the generic available stock; check total current stock
        // and all OTHER active holds. This permits disposing one's own reserved copies.
        $all=$this->stock($org);
        $batch=$all[(int)$s['batch_id']]??null;
        $own=(int)$s['quantity']-(int)$s['disposed_quantity'];
        self::check($batch!==null && $batch['available']+$own >=$qty,
            'Physical stock is lower than the authorized disposal balance; reconcile first',409);
        $this->movement($org,(int)$s['batch_id'],-$qty,'DISPOSAL','TBD '.$r['number'].' · '.substr($ref,0,145));
        $s['disposed_quantity']+=$qty;
        $s['disposals'][]=['disposed_on'=>$date,'quantity'=>$qty,'method'=>$method,'paper_reference'=>$ref,
            'remarks'=>$remarks,'encoded_by'=>(int)$this->user['id']];
        self::check(count($s['disposals'])<=200,'Too many disposal events; archive review required',409);
        $s['status']=(int)$s['disposed_quantity']===(int)$s['quantity']?'DISPOSED':'PARTIALLY_DISPOSED';
        $this->saveVersion($org,'TBD',$r['id'],$r['version']+1,$s,'Actual authorized textbook disposal recorded');
        $this->audit($org,'TEXTBOOK_DISPOSED','TEXTBOOK_DISPOSAL',$r['id'],['quantity'=>$qty,'method'=>$method,'reference'=>$ref]);
        return ['id'=>$r['id'],'number'=>$r['number'],'status'=>$s['status'],'disposed_now'=>$qty];
    }
    public function teacherDetail(array $d):array {
        $r=$this->doc((int)($d['issue_id']??0),'TBI');$this->school((int)($d['organization_id']??0));
        self::check($r['org']===(int)$d['organization_id'],'Teacher issue not in this school',403);return $r;
    }
    public function teacherList(array $d):array {
        $org=(int)($d['organization_id']??0);$this->school($org);
        $docs=$this->docs($org,'TBI');$out=[];
        foreach($docs as $r){$s=$r['snapshot'];$returned=(int)$s['returned_serviceable']+(int)$s['returned_damaged'];
            $out[]=['id'=>$r['id'],'number'=>$r['number'],'version'=>$r['version'],
                'teacher_name'=>$s['teacher_name'],'title'=>$s['title'],'isbn'=>$s['isbn'],'issued_on'=>$s['issued_on'],
                'issued'=>$s['issued_quantity'],'returned_serviceable'=>$s['returned_serviceable'],'returned_damaged'=>$s['returned_damaged'],
                'lost_pending'=>$s['lost_pending'],'unreturned'=>(int)$s['issued_quantity']-$returned,
                'available_to_return'=>(int)$s['issued_quantity']-$returned-(int)$s['lost_pending'],
                'damage_notified'=>$s['damage_notified'],'status'=>$s['status'],'paper_reference'=>$s['paper_reference']];
        }return ['rows'=>$out];
    }
    /** Scoped single-query Division disposal register: no N+1 lookup over every school. */
    public function disposalList(array $d):array {
        $org=(int)($d['organization_id']??0);$office=$this->office($org);
        $scope=$office['kind']==='DIVISION'
            ? "(r.organization_id=? OR (o.kind='SCHOOL' AND o.active=1 AND o.parent_division_id=?))"
            : 'r.organization_id=?';
        $args=$office['kind']==='DIVISION'?[$org,$org]:[$org];
        $rows=q($this->db,"SELECT r.id,r.organization_id,r.display_number,v.snapshot_json FROM document_registry r
            JOIN organizations o ON o.id=r.organization_id
            JOIN document_versions v ON v.organization_id=r.organization_id AND v.doc_type=r.doc_type AND v.entity_id=r.entity_id
                AND v.version_no=(SELECT MAX(z.version_no) FROM document_versions z WHERE z.organization_id=r.organization_id AND z.doc_type=r.doc_type AND z.entity_id=r.entity_id)
            WHERE r.doc_type='TBD' AND $scope ORDER BY r.id DESC LIMIT 1001",$args)->fetchAll(PDO::FETCH_ASSOC);
        self::check(count($rows)<=1000,'Disposal report exceeds 1,000 records; pagination is required',409);
        $out=[];
        foreach($rows as $r){$s=json_decode((string)$r['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
            self::check(is_array($s)&&($s['type']??'')==='TBD'
                && (int)($s['organization_id']??0)===(int)$r['organization_id']
                && (int)($s['division_id']??0)===(int)($office['kind']==='DIVISION'?$org:$office['parent_division_id']),
                'Disposal record supervision/provenance requires review',409);
            $last=empty($s['disposals'])?null:$s['disposals'][array_key_last($s['disposals'])];
            $out[]=['id'=>(int)$r['id'],'number'=>(string)$r['display_number'],
                'source_organization_id'=>(int)$r['organization_id'],'source'=>(string)$s['organization_name'],
                'title'=>$s['title'],'isbn'=>$s['isbn'],'condition'=>$s['condition'],
                'quantity'=>$s['quantity'],'disposed_quantity'=>$s['disposed_quantity'],
                'remaining'=>(int)$s['quantity']-(int)$s['disposed_quantity'],'status'=>$s['status'],
                'marked_on'=>$s['marked_on'],'reason'=>$s['reason'],'paper_reference'=>$s['paper_reference'],
                'authorized'=>$s['authorized'],'disposals'=>$s['disposals'],
                'last_disposal_date'=>(string)($last['disposed_on']??''),
                'last_disposal_method'=>(string)($last['method']??''),
                'last_disposal_reference'=>(string)($last['paper_reference']??''),
                'withdrawn_on'=>(string)($s['withdrawn_on']??''),
                'withdraw_reason'=>(string)($s['withdraw_reason']??'')];
        }
        return ['rows'=>$out,'role'=>$office['kind']];
    }
    public function teachers(array $d):array {
        $org=(int)($d['organization_id']??0);$this->school($org);
        return q($this->db,"SELECT DISTINCT e.id,e.full_name FROM employees e JOIN employee_assignments a ON a.employee_id=e.id
            WHERE a.organization_id=? AND a.effective_from<=? AND (a.effective_to IS NULL OR a.effective_to>=?)
            ORDER BY e.full_name,e.id LIMIT 501",[$org,self::today(),self::today()])->fetchAll(PDO::FETCH_ASSOC);
    }
    /** Read-only, office-scoped report tables, never alter source stock. */
    public function reports(array $d):array {
        $org=(int)($d['organization_id']??0);$o=$this->office($org);
        $stock=array_values($this->stock($org));$serviceable=0;$quarantine=0;$held=0;
        foreach($stock as $b){if($b['serviceable'])$serviceable+=$b['on_hand'];else $quarantine+=$b['on_hand'];$held+=$b['held'];}
        $teacherRows=$o['kind']==='SCHOOL'?$this->teacherList($d)['rows']:[];
        $outstanding=0;$lost=0;$damagedTeacher=0;
        foreach($teacherRows as $t){$outstanding+=$t['unreturned'];$lost+=$t['lost_pending'];$damagedTeacher+=$t['returned_damaged'];}
        $damageDocs=$this->docs($org,'TBQ');$quarantineEvents=[];
        foreach($damageDocs as $r){$v=$r['snapshot'];$quarantineEvents[]=[
            'number'=>$r['number'],'date'=>$v['date'],'title'=>$v['title'],
            'quantity'=>$v['quantity'],'reason'=>$v['reason'],'paper_reference'=>$v['paper_reference'],
            'status'=>$v['status']];}
        $disposals=$this->disposalList($d)['rows'];$proposed=0;$completed=0;
        foreach($disposals as $x){if(in_array($x['status'],['MARKED','AUTHORIZED','PARTIALLY_DISPOSED'],true))$proposed+=$x['remaining'];$completed+=$x['disposed_quantity'];}
        return ['organization'=>$o['name'],'as_of'=>self::today(),
            'summary'=>['serviceable_in_storage'=>$serviceable,'quarantined_in_storage'=>$quarantine,
                'reserved_for_transfer_or_disposal'=>$held,'issued_to_teachers_unreturned'=>$outstanding,
                'reported_lost_pending_relief'=>$lost,'damaged_teacher_returns'=>$damagedTeacher,
                'proposed_disposal_remaining'=>$proposed,'disposed_documented'=>$completed],
            'stock'=>$stock,'teacher_accountability'=>$teacherRows,'damage_quarantine'=>$quarantineEvents,'disposals'=>$disposals];
    }
}
