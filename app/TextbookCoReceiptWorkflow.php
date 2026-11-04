<?php
declare(strict_types=1);
require_once __DIR__.'/TextbookIsbn.php';
require_once __DIR__.'/TextbookReceiptDistributionRules.php';

/**
 * Staged CO textbook intake implementation. Requires the two specifically
 * approved textbook_co_* tables before this class is ever routed in production.
 * It does not create titles, merge ISBN records or permit learner-level data.
 * All writes must execute inside the API's idempotent transaction.
 */
final class TextbookCoReceiptWorkflow {
    public function __construct(private PDO $db, private array $user) {}

    private static function text(array $body,string $key,int $min,int $max):string {
        $val=trim((string)($body[$key]??''));
        demand(strlen($val)>=$min&&strlen($val)<=$max,"Invalid {$key} ({$min}–{$max} characters)",422);
        return $val;
    }
    private static function dateOnly(string $raw,string $label):string {
        demand((bool)preg_match('/^\d{4}-\d{2}-\d{2}$/D',$raw),"Invalid $label",422);
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$raw,new DateTimeZone('Asia/Manila'));
        demand($parsed!==false&&$parsed->format('Y-m-d')===$raw,"Invalid $label",422);
        $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        demand($raw<=$today,"$label cannot be a future date",422);
        return $raw;
    }
    private static function qty(mixed $value,string $name,bool $zero=false):int {
        demand((is_int($value)||is_string($value))&&
            preg_match($zero?'/^(0|[1-9][0-9]{0,8})$/D':'/^[1-9][0-9]{0,8}$/D',(string)$value)===1,
            "Invalid positive whole-number $name",422);
        return (int)$value;
    }
    private function office(int $org):array {
        demand($org>0,'Choose the receiving office',422);
        scope($this->db,$this->user,$org);
        $office=one($this->db,"SELECT id,kind,active FROM organizations WHERE id=? AND active=1 AND kind IN ('DIVISION','SCHOOL')",[$org]);
        demand($office!==null && ($this->user['role']??'')===(string)$office['kind'],
            'Sign in with the receiving Division or school account',403);
        return $office;
    }
    private function record(int $org,int $id,bool $lock=false):array {
        $this->office($org);
        $rec=one($this->db,'SELECT * FROM textbook_co_receipts WHERE id=? AND organization_id=?'.($lock?' FOR UPDATE':''),[$id,$org]);
        demand($rec!==null,'CO textbook receipt not found in this office',404);
        return $rec;
    }
    private function custodyBinding(int $org):array {
        // Reuse the explicitly linked, currently appointed receiving Property Custodian;
        // no fresh free-text 'custodian' role or SUPER_ADMIN impersonation.
        $appointment=one($this->db,'SELECT id,config_json,effective_from FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',
            [$org,'ORG_SUPPLY_PROPERTY_CUSTODIAN_V1']);
        $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        demand($appointment!==null&&(string)$appointment['effective_from']<=$today,
            'Appoint the receiving Property Custodian before accepting textbooks',409);
        $appointed=json_decode((string)$appointment['config_json'],true,32,JSON_THROW_ON_ERROR);
        $employee=(int)($appointed['employee_id']??0);
        demand($employee>0,'Property Custodian appointment needs correction',409);
        $link=one($this->db,'SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',
            [$org,'PROPERTY_RECEIVING_LOGIN_V1']);
        $binding=$link!==null?json_decode((string)$link['config_json'],true,32,JSON_THROW_ON_ERROR):null;
        demand(is_array($binding)&&(int)($binding['employee_id']??0)===$employee &&
            (int)($binding['appointment_version']??0)===(int)$appointment['id'] &&
            (int)($binding['user_id']??0)===(int)$this->user['id'],
            'Log in with the currently appointed and linked Property Custodian',403);
        $active=one($this->db,'SELECT employee_id FROM employee_assignments WHERE organization_id=? AND employee_id=? AND effective_from<=? AND (effective_to IS NULL OR effective_to>?) ORDER BY id DESC LIMIT 1',
            [$org,$employee,$today,$today]);
        demand($active!==null,'The custodian employee assignment is not active',403);
        return ['employee_id'=>$employee,'appointment_version'=>(int)$appointment['id']];
    }
    private function audited(int $org,string $event,int $entity,array $data=[]):void {
        q($this->db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
            [$org,(int)$this->user['id'],$event,'TEXTBOOK_CO_RECEIPT',$entity,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
    }
    private function checkedTitles(array $lines):array {
        // Read-only scan is intentionally bounded as in the installed directory.
        // Fail closed if the ISBN catalog is larger than the audited population.
        $source=q($this->db,'SELECT id,title,isbn,grade,subject FROM textbook_titles ORDER BY id ASC LIMIT 3001')->fetchAll();
        demand(count($source)<=3000,'ISBN audit coverage is incomplete. Review the full catalog before posting receipts',409);
        $byIsbn=[];
        foreach($source as $row){
            try{$isbn=TextbookIsbn::canonical((string)($row['isbn']??''));}catch(InvalidArgumentException $e){continue;}
            $byIsbn[$isbn][]=$row;
        }
        $done=[];
        foreach($lines as $i=>$line){
            demand(is_array($line),'Invalid receipt line',422);
            $isbn=trim((string)($line['isbn']??''));
            try{$canon=TextbookIsbn::canonical($isbn);}catch(InvalidArgumentException $e){demand(false,'Receipt lines require an approved valid ISBN',422);}
            demand(!isset($done[$canon]),'Use one receipt line per ISBN edition; separate lots require documented batch allocation',409);
            $done[$canon]=true;
            $matches=$byIsbn[$canon]??[];
            demand(count($matches)>0,'ISBN is not yet in the approved Book Directory. Request catalog review first',409);
            $signatures=[];
            foreach($matches as $row){
                $normalize=static fn(string $v)=>strtolower(trim(preg_replace('/\s+/u',' ',$v)??$v));
                $signatures[]=implode('|',[$normalize((string)$row['title']),$normalize((string)($row['grade']??'')),$normalize((string)($row['subject']??''))]);
            }
            demand(count(array_unique($signatures))===1,
                'The ISBN has conflicting legacy title metadata; resolve the catalog conflict before receiving',409);
            usort($matches,static fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
            $chosen=$matches[0];
            demand(!isset($line['title_id'])||(int)$line['title_id']===(int)$chosen['id'],
                'The supplied title ID is not the approved canonical legacy row',409);
            $expected=self::qty($line['expected_quantity']??null,'expected_quantity');
            $batch=self::text($line,'source_batch',1,120);
            $storage=self::text($line,'storage',1,160);
            $done[$canon]=true;
            $out[]=['title_id'=>(int)$chosen['id'],'isbn13'=>$canon,'expected_quantity'=>$expected,
                'source_batch'=>$batch,'storage'=>$storage];
        }
        return $out??[];
    }
    public function create(array $body):array {
        $org=(int)($body['organization_id']??0);
        $office=$this->office($org);
        demand($this->db->inTransaction(),'CO receipt creation requires a protected idempotent transaction',500);
        $reference=self::text($body,'co_delivery_reference',6,120);
        $date=self::dateOnly((string)($body['received_on']??''),'Physical receiving date');
        $remarks=self::text($body,'remarks',10,500);
        $rows=$body['lines']??null;
        demand(is_array($rows)&&array_is_list($rows)&&count($rows)>=1&&count($rows)<=40,
            'Choose 1–40 ISBN title lines',422);
        // Serialize per-office reference checks, including concurrent authors.
        $lock=one($this->db,'SELECT id FROM organizations WHERE id=? AND active=1 FOR UPDATE',[$org]);
        demand($lock!==null,'Receiving office is unavailable',409);
        $existing=one($this->db,'SELECT id FROM textbook_co_receipts WHERE organization_id=? AND co_delivery_reference=?',[$org,$reference]);
        demand($existing===null,'A CO receipt with this delivery reference already exists',409);
        $titles=$this->checkedTitles($rows);
        q($this->db,'INSERT INTO textbook_co_receipts (organization_id,source_kind,receipt_path,co_delivery_reference,received_on,remarks,status,created_by) VALUES (?,?,?,?,?,?,?,?)',
            [$org,'CENTRAL_OFFICE',TextbookReceiptDistributionRules::receivingPath((string)$office['kind']),$reference,$date,$remarks,'DRAFT',(int)$this->user['id']]);
        $id=(int)$this->db->lastInsertId();
        foreach($titles as $line){
            q($this->db,'INSERT INTO textbook_co_receipt_lines(receipt_id,title_id,isbn13,expected_quantity,source_batch,storage) VALUES (?,?,?,?,?,?)',
                [$id,$line['title_id'],$line['isbn13'],$line['expected_quantity'],$line['source_batch'],$line['storage']]);
        }
        $this->audited($org,'TEXTBOOK_CO_RECEIPT_DRAFTED',$id,['reference'=>$reference,'line_count'=>count($titles),'path'=>TextbookReceiptDistributionRules::receivingPath((string)$office['kind'])]);
        return ['id'=>$id,'status'=>'DRAFT','line_count'=>count($titles)];
    }
    public function submit(array $body):array {
        $org=(int)($body['organization_id']??0);$id=(int)($body['receipt_id']??0);
        $r=$this->record($org,$id,true);
        demand((int)$r['created_by']===(int)$this->user['id'],'Only the draft preparer can submit this receipt',403);
        demand(TextbookReceiptDistributionRules::allows('CO_RECEIPT',(string)$r['status'],'SUBMITTED'),
            'Only a draft CO receipt can be submitted',409);
        q($this->db,'UPDATE textbook_co_receipts SET status=?,submitted_at=NOW(),version=version+1 WHERE id=? AND organization_id=? AND status=?',
            ['SUBMITTED',$id,$org,'DRAFT']);
        $this->audited($org,'TEXTBOOK_CO_RECEIPT_SUBMITTED',$id);
        return ['id'=>$id,'status'=>'SUBMITTED'];
    }
    public function cancelDraft(array $body):array {
        $org=(int)($body['organization_id']??0);$id=(int)($body['receipt_id']??0);
        $r=$this->record($org,$id,true);
        demand((int)$r['created_by']===(int)$this->user['id'],
            'Only the draft preparer can cancel this draft',403);
        demand(TextbookReceiptDistributionRules::allows('CO_RECEIPT',(string)$r['status'],'CANCELLED'),
            'A submitted or accepted CO receipt cannot be cancelled as a draft',409);
        $reason=self::text($body,'cancellation_reason',15,500);
        q($this->db,'UPDATE textbook_co_receipts SET status=?,version=version+1 WHERE id=? AND organization_id=? AND status=?',
            ['CANCELLED',$id,$org,'DRAFT']);
        $this->audited($org,'TEXTBOOK_CO_RECEIPT_DRAFT_CANCELLED',$id,['reason'=>$reason]);
        return ['id'=>$id,'status'=>'CANCELLED'];
    }
    public function reject(array $body):array {
        $org=(int)($body['organization_id']??0);$id=(int)($body['receipt_id']??0);
        $r=$this->record($org,$id,true);
        $this->custodyBinding($org); // Signed rejection must come from appointed receiving custodian.
        demand(TextbookReceiptDistributionRules::allows('CO_RECEIPT',(string)$r['status'],'REJECTED'),
            'Only a submitted or inspected CO delivery can be rejected',409);
        $reason=self::text($body,'rejection_reason',20,500);
        $reference=self::text($body,'rejection_evidence_reference',8,160);
        // Rejection posts no textbook lot or stock movement; a new physical
        // delivery requires a separately numbered authentic source reference.
        q($this->db,'UPDATE textbook_co_receipts SET status=?,version=version+1 WHERE id=? AND organization_id=? AND status=?',
            ['REJECTED',$id,$org,(string)$r['status']]);
        $this->audited($org,'TEXTBOOK_CO_RECEIPT_REJECTED',$id,['reason'=>$reason,'evidence'=>$reference]);
        return ['id'=>$id,'status'=>'REJECTED'];
    }
    public function inspect(array $body):array {
        $org=(int)($body['organization_id']??0);$id=(int)($body['receipt_id']??0);
        $r=$this->record($org,$id,true);
        // The appointed receiving custodian cannot also serve as the verifying
        // inspector; catch this before setting an irreversible INSPECTED status.
        $assigned=one($this->db,'SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',
            [$org,'PROPERTY_RECEIVING_LOGIN_V1']);
        if($assigned!==null){
            $linked=json_decode((string)$assigned['config_json'],true,32,JSON_THROW_ON_ERROR);
            demand(is_array($linked)&&(int)($linked['user_id']??0)!==(int)$this->user['id'],
                'The receiving custodian and physical inspector must use different authenticated accounts',403);
        }
        demand(TextbookReceiptDistributionRules::allows('CO_RECEIPT',(string)$r['status'],'INSPECTED'),
            'The receipt is not awaiting physical inspection',409);
        $reference=self::text($body,'inspection_reference',8,160);
        $observations=self::text($body,'inspection_note',15,500);
        $lines=$body['lines']??null;
        $stored=q($this->db,'SELECT id,expected_quantity FROM textbook_co_receipt_lines WHERE receipt_id=? ORDER BY id FOR UPDATE',[$id])->fetchAll();
        demand(is_array($lines)&&count($lines)===count($stored),'Provide inspection counts for every original receipt line',422);
        $byId=[];foreach($lines as $line){
            demand(is_array($line)&&is_numeric($line['line_id']??null),'Invalid inspected line',422);
            $lineId=(int)$line['line_id'];demand(!isset($byId[$lineId]),'Duplicate inspected line',409);
            $byId[$lineId]=$line;
        }
        foreach($stored as $row){
            $line=$byId[(int)$row['id']]??null;
            demand(is_array($line),'Receipt lines cannot be changed during inspection',409);
            $values=[];
            foreach(['delivered_quantity','serviceable_quantity','damaged_quantity','held_quantity'] as $name)
                $values[$name]=self::qty($line[$name]??null,$name,true);
            try {TextbookReceiptDistributionRules::externalReceiptLine((int)$row['expected_quantity'],
                $values['delivered_quantity'],$values['serviceable_quantity'],$values['damaged_quantity'],$values['held_quantity']);}
            catch(InvalidArgumentException $e){demand(false,'Receipt inspection counts do not reconcile',422);}
            q($this->db,'UPDATE textbook_co_receipt_lines SET delivered_quantity=?,serviceable_quantity=?,damaged_quantity=?,held_quantity=? WHERE id=? AND receipt_id=?',
                [$values['delivered_quantity'],$values['serviceable_quantity'],$values['damaged_quantity'],$values['held_quantity'],(int)$row['id'],$id]);
        }
        q($this->db,'UPDATE textbook_co_receipts SET status=?,inspected_by=?,inspection_reference=?,inspection_note=?,inspected_at=NOW(),version=version+1 WHERE id=? AND organization_id=? AND status=?',
            ['INSPECTED',(int)$this->user['id'],$reference,$observations,$id,$org,'SUBMITTED']);
        $this->audited($org,'TEXTBOOK_CO_RECEIPT_INSPECTED',$id,['reference'=>$reference,'line_count'=>count($stored)]);
        return ['id'=>$id,'status'=>'INSPECTED'];
    }
    public function accept(array $body):array {
        $org=(int)($body['organization_id']??0);$id=(int)($body['receipt_id']??0);
        $r=$this->record($org,$id,true);
        $custodian=$this->custodyBinding($org);
        demand((string)$r['status']==='INSPECTED','Physical inspection must be recorded first',409);
        demand((int)($r['inspected_by']??0)!==(int)$this->user['id'],
            'Inspection and custodian acceptance must be performed by different authenticated people',403);
        $reference=self::text($body,'signed_acceptance_reference',8,160);
        $date=self::dateOnly((string)($body['accepted_on']??''),'Acceptance date');
        demand($date>=(string)$r['received_on'],'Acceptance cannot predate delivery',422);
        $lines=q($this->db,'SELECT * FROM textbook_co_receipt_lines WHERE receipt_id=? ORDER BY id FOR UPDATE',[$id])->fetchAll();
        demand(count($lines)>0,'No receipt lines to accept',409);
        $hasIssue=false;$total=0;
        foreach($lines as $line){
            try {$result=TextbookReceiptDistributionRules::externalReceiptLine((int)$line['expected_quantity'],
                (int)$line['delivered_quantity'],(int)$line['serviceable_quantity'],
                (int)$line['damaged_quantity'],(int)$line['held_quantity']);}
            catch(InvalidArgumentException $e){demand(false,'Recorded inspection counts need correction before acceptance',409);}
            if($result['has_discrepancy'])$hasIssue=true;
            foreach(['serviceable_quantity'=>'Serviceable','damaged_quantity'=>'Damaged - Quarantined'] as $qtyKey=>$condition){
                $qty=(int)$line[$qtyKey];if($qty===0)continue;
                $lotReference='CO-'.$id.'-'.(int)$line['id'].'-'.($qtyKey==='serviceable_quantity'?'S':'Q');
                q($this->db,'INSERT INTO textbook_batches(organization_id,title_id,batch,condition_note,storage,opening_record) VALUES (?,?,?,?,?,?)',
                    [$org,(int)$line['title_id'],$lotReference,$condition,(string)$line['storage'],0]);
                $batchId=(int)$this->db->lastInsertId();
                q($this->db,'INSERT INTO textbook_movements(organization_id,batch_id,quantity_delta,event_type,reference,actor_id) VALUES (?,?,?,?,?,?)',
                    [$org,$batchId,$qty,'RECEIPT','Verified Central Office delivery '.$r['co_delivery_reference'].'; receipt '.$id.'; line '.(int)$line['id'].'; condition '.$condition,(int)$this->user['id']]);
                q($this->db,'INSERT INTO textbook_co_receipt_postings(receipt_line_id,batch_id,condition_kind,quantity) VALUES (?,?,?,?)',
                    [(int)$line['id'],$batchId,$qtyKey==='serviceable_quantity'?'SERVICEABLE':'QUARANTINED',$qty]);
                $total+=$qty;
            }
        }
        demand($total>0,'No accepted copies can be credited; record discrepancy or reject instead',409);
        $status=$hasIssue?'ACCEPTED_WITH_DISCREPANCY':'ACCEPTED';
        demand(TextbookReceiptDistributionRules::allows('CO_RECEIPT','INSPECTED',$status),'Invalid receipt transition',409);
        q($this->db,'UPDATE textbook_co_receipts SET status=?,accepted_by=?,custodian_employee_id=?,signed_acceptance_reference=?,accepted_on=?,accepted_at=NOW(),version=version+1 WHERE id=? AND organization_id=? AND status=?',
            [$status,(int)$this->user['id'],$custodian['employee_id'],$reference,$date,$id,$org,'INSPECTED']);
        $this->audited($org,'TEXTBOOK_CO_RECEIPT_ACCEPTED',$id,['status'=>$status,'accepted_copies'=>$total,'accepted_on'=>$date,'document'=>$reference,'custodian_appointment_version'=>$custodian['appointment_version']]);
        return ['id'=>$id,'status'=>$status,'accepted_copies'=>$total];
    }
    public function list(array $input):array {
        $org=(int)($input['organization_id']??0);$this->office($org);
        return q($this->db,'SELECT r.id,r.receipt_path,r.co_delivery_reference,r.received_on,r.status,r.created_at,r.accepted_on,r.inspection_reference, COUNT(l.id) AS title_lines,COALESCE(SUM(CASE WHEN r.status IN (\'ACCEPTED\',\'ACCEPTED_WITH_DISCREPANCY\') THEN l.serviceable_quantity ELSE 0 END),0) AS accepted_serviceable FROM textbook_co_receipts r LEFT JOIN textbook_co_receipt_lines l ON l.receipt_id=r.id WHERE r.organization_id=? GROUP BY r.id ORDER BY r.id DESC LIMIT 100',[$org])->fetchAll();
    }
    public function detail(array $input):array {
        $org=(int)($input['organization_id']??0);$receipt=$this->record($org,(int)($input['receipt_id']??0));
        $lines=q($this->db,'SELECT l.id,l.isbn13,l.expected_quantity,l.delivered_quantity,l.serviceable_quantity,l.damaged_quantity,l.held_quantity,l.source_batch,l.storage,t.title,t.grade,t.subject FROM textbook_co_receipt_lines l JOIN textbook_titles t ON t.id=l.title_id WHERE l.receipt_id=? ORDER BY l.id',[(int)$receipt['id']])->fetchAll();
        return ['receipt'=>$receipt,'lines'=>$lines];
    }
}
