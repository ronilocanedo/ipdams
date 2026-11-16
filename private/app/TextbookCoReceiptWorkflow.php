<?php
declare(strict_types=1);
require_once __DIR__.'/TextbookIsbn.php';
require_once __DIR__.'/TextbookReceiptDistributionRules.php';
require_once __DIR__.'/AnnualDocumentNumbering.php';

/**
 * Central Office → Division or Central Office → school textbook intake.
 * Uses IPDAMS's existing versioned documentary register (TBR), not ad hoc DDL.
 * The API MUST wrap every POST in its normal CSRF and idempotent SQL transaction.
 * No book quantity is posted before the authorized office encoder documents an actual physical receipt; neither paper signatory needs an IPDAMS login.
 */
final class TextbookCoReceiptWorkflow {
    private const TYPE='TBR';
    public function __construct(private PDO $db,private array $user) {}

    private static function input(array $v,string $key,int $min,int $max):string {
        $t=trim((string)($v[$key]??''));
        demand(mbSafeLen($t)>=$min && mbSafeLen($t)<=$max,'Provide '.$key.' ('.$min.'–'.$max.' characters)',422);
        return $t;
    }
    private static function date(string $v,string $name):string {
        demand((bool)preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v),"Invalid $name",422);
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('Asia/Manila'));
        demand($dt!==false && $dt->format('Y-m-d')===$v,"Invalid $name",422);
        demand($v<=self::today(),"$name must not be in the future",422);
        return $v;
    }
    private static function today():string {return (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');}
    private static function count(mixed $v,string $name,bool $zero=false):int {
        demand((is_int($v)||is_string($v)) && preg_match($zero?'/^(0|[1-9][0-9]{0,8})$/D':'/^[1-9][0-9]{0,8}$/D',(string)$v)===1,
            "Enter a valid whole-number $name",422);
        return (int)$v;
    }
    private function office(int $org):array {
        demand($org>0,'Select the receiving office',422);
        scope($this->db,$this->user,$org);
        $o=one($this->db,"SELECT id,kind,active FROM organizations WHERE id=? AND active=1 AND kind IN ('DIVISION','SCHOOL')",[$org]);
        demand($o!==null && ($this->user['role']??'')===(string)$o['kind'],
            'Sign in with the receiving Division or school account',403);
        return $o;
    }
    private function loggedCustodian(int $org):array {
        $now=self::today();
        $p=one($this->db,'SELECT id,config_json,effective_from FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',[$org,'ORG_SUPPLY_PROPERTY_CUSTODIAN_V1']);
        demand($p!==null && (string)$p['effective_from']<=$now,'Appoint the receiving Property Custodian before accepting textbooks',409);
        $c=json_decode((string)$p['config_json'],true,32,JSON_THROW_ON_ERROR);
        $emp=(int)($c['employee_id']??0);demand($emp>0,'Review the official Property Custodian appointment',409);
        $link=one($this->db,'SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',[$org,'PROPERTY_RECEIVING_LOGIN_V1']);
        $b=$link!==null?json_decode((string)$link['config_json'],true,32,JSON_THROW_ON_ERROR):null;
        demand(is_array($b) && (int)($b['employee_id']??0)===$emp && (int)($b['appointment_version']??0)===(int)$p['id']
            && (int)($b['user_id']??0)===(int)$this->user['id'],
            'Sign in with the currently appointed and linked Property Custodian',403);
        $active=one($this->db,'SELECT employee_id FROM employee_assignments WHERE organization_id=? AND employee_id=? AND effective_from<=? AND (effective_to IS NULL OR effective_to>?) ORDER BY id DESC LIMIT 1',[$org,$emp,$now,$now]);
        demand($active!==null,'The receiving Property Custodian is not actively assigned to this office',403);
        return ['employee_id'=>$emp,'appointment_version'=>(int)$p['id']];
    }
    private function auditor(int $org,string $event,int $id,array $details=[]):void {
        q($this->db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
            [$org,(int)$this->user['id'],$event,'TEXTBOOK_CO_RECEIPT',$id,json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
    }
    private function record(int $org,int $id,bool $lock=false):array {
        $this->office($org);demand($id>0,'Choose a textbook receipt',422);
        $row=one($this->db,'SELECT id,display_number,created_at FROM document_registry WHERE id=? AND entity_id=? AND organization_id=? AND doc_type=?'.($lock?' FOR UPDATE':''),
            [$id,$id,$org,self::TYPE]);
        demand($row!==null,'Textbook receipt does not exist in this office',404);
        $v=one($this->db,'SELECT version_no,snapshot_json,created_at FROM document_versions WHERE organization_id=? AND doc_type=? AND entity_id=? ORDER BY version_no DESC LIMIT 1',[$org,self::TYPE,$id]);
        demand($v!==null,'The recorded receipt version is missing; request technical review',409);
        $data=json_decode((string)$v['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
        demand(is_array($data) && ($data['type']??null)===self::TYPE && (int)($data['organization_id']??0)===$org,
            'Textbook receipt audit scope validation failed',409);
        return ['id'=>$id,'number'=>(string)$row['display_number'],'version'=>(int)$v['version_no'],
            'created_at'=>$row['created_at'],'saved_at'=>$v['created_at'],'snapshot'=>$data];
    }
    /**
     * CO-delivery IARs are separate from PO-backed purchase IAR rows. Their
     * official IAR serials use the SAME annual number allocator, but their
     * content is bound to the TBR's immutable versioned snapshot. No local
     * PO, supplier, payment or duplicate inventory ledger is invented.
     */
    private function addIarNumber(int $org,array &$snapshot):bool {
        if(trim((string)($snapshot['iar_number']??''))!=='')return false;
        demand($this->db->inTransaction(),'An automatic IAR needs a protected transaction',500);
        $snapshot['iar_number']=AnnualDocumentNumbering::reserve($this->db,$org,'IAR',substr(self::today(),0,7));
        $snapshot['iar_generated_on']=self::today();
        $snapshot['iar_document_source']='CENTRAL_OFFICE_TEXTBOOK_RECEIPT';
        return true;
    }
    /** Backfill a linked printable IAR for receipts saved before this release. */
    public function issueIar(array $body):array {
        $org=(int)($body['organization_id']??0);
        $r=$this->record($org,(int)($body['receipt_id']??0),true);
        demand($this->db->inTransaction(),'A protected IAR transaction is required',500);
        $snap=$r['snapshot'];
        demand(($snap['status']??'')!=='CANCELLED',
            'A cancelled textbook receipt cannot issue an IAR',409);
        if($this->addIarNumber($org,$snap)){
            $snap['iar_created_after_receipt']=true;
            $this->version($org,$r['id'],$r['version']+1,$snap,
                'Linked Central Office textbook IAR generated for earlier receipt; no stock posted');
            $this->auditor($org,'TEXTBOOK_CO_IAR_ISSUED',$r['id'],
                ['iar_number'=>$snap['iar_number'],'after_receipt'=>true]);
        }
        return ['id'=>$r['id'],'number'=>$r['number'],'status'=>$snap['status'],
            'iar_number'=>$snap['iar_number'],'stock_credited'=>false];
    }
    private function version(int $org,int $id,int $next,array $data,string $reason):void {
        q($this->db,'INSERT INTO document_versions(organization_id,doc_type,entity_id,version_no,snapshot_json,reason,actor_id) VALUES (?,?,?,?,?,?,?)',
            [$org,self::TYPE,$id,$next,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$reason,(int)$this->user['id']]);
    }
    private function checkTitles(array $incoming):array {
        // The current shared directory validates the first 3,000 historical records.
        // No new titles or silently merged metadata are allowed by receiving.
        $source=q($this->db,'SELECT id,title,isbn,grade,subject FROM textbook_titles ORDER BY id ASC LIMIT 3001')->fetchAll();
        demand(count($source)<=3000,'Full ISBN catalog audit is required before new receipts can be posted',409);
        $mapped=[];
        foreach($source as $row){
            try{$isbn=TextbookIsbn::canonical((string)($row['isbn']??''));}
            catch(InvalidArgumentException $e){continue;}
            $mapped[$isbn][]=$row;
        }
        $seen=[];$out=[];
        foreach($incoming as $line){
            demand(is_array($line),'Invalid textbook line',422);
            try{$isbn=TextbookIsbn::canonical(trim((string)($line['isbn']??'')));}
            catch(InvalidArgumentException $e){demand(false,'Select a valid ISBN-10 or ISBN-13 from the shared Book Directory',422);}
            demand(!isset($seen[$isbn]),'Only one line per ISBN edition is permitted in a receipt',409);
            $seen[$isbn]=true;$matches=$mapped[$isbn]??[];
            demand($matches!==[],'This ISBN is not registered. Request Book Directory review first',409);
            $signatures=[];
            foreach($matches as $r){
                $simplify=static fn(string $s)=>strtolower(trim(preg_replace('/\s+/u',' ',$s)??$s));
                $signatures[]=implode('|',[$simplify((string)$r['title']),$simplify((string)($r['grade']??'')),$simplify((string)($r['subject']??''))]);
            }
            demand(count(array_unique($signatures))===1,
                'This ISBN has conflicting historical title metadata. Resolve it in the Book Directory first',409);
            usort($matches,static fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);$match=$matches[0];
            $out[]=['line_no'=>count($out)+1,'title_id'=>(int)$match['id'],'isbn13'=>$isbn,
                'title'=>(string)$match['title'],'grade'=>(string)($match['grade']??''),'subject'=>(string)($match['subject']??''),
                'expected_quantity'=>self::count($line['expected_quantity']??null,'expected quantity'),
                'source_batch'=>self::input($line,'source_batch',1,120),'storage'=>self::input($line,'storage',1,160),
                'delivered_quantity'=>null,'serviceable_quantity'=>null,'damaged_quantity'=>null,'held_quantity'=>null];
        }
        return $out;
    }
    /**
     * Shared ISBN directory registration; does not create or credit any copies.
     * All new catalog authors serialize on the same stable organization row.
     * An existing equivalent ISBN-10/13 is never duplicated.
     */
    public function registerCatalog(array $body):array {
        $org=(int)($body['organization_id']??0);$this->office($org);
        demand($this->db->inTransaction(),'Catalog registration needs a protected idempotent transaction',500);
        $title=self::input($body,'title',4,255);
        $grade=self::input($body,'grade',1,50);
        $subject=self::input($body,'subject',2,100);
        $source=self::input($body,'catalog_source_reference',8,160);
        demand(($body['confirmed_official_title']??false)===true,
            'Confirm the title, grade, subject and ISBN against authentic book or Central Office documents',422);
        try{$isbn=TextbookIsbn::canonical((string)($body['isbn']??''));}
        catch(InvalidArgumentException $e){demand(false,'The ISBN-10 or ISBN-13 is invalid',422);}
        // One common lock for ALL offices. Existing legacy opening routes are
        // blocked, so all new title writes must use this serialized register.
        $lock=one($this->db,'SELECT id FROM organizations ORDER BY id ASC LIMIT 1 FOR UPDATE');
        demand($lock!==null,'Catalog registry lock is unavailable',409);
        $existing=q($this->db,'SELECT id,title,isbn,grade,subject FROM textbook_titles ORDER BY id ASC LIMIT 3001')->fetchAll();
        demand(count($existing)<=3000,'Global ISBN registry review is incomplete; request technical reconciliation',409);
        foreach($existing as $entry){
            try{$registered=TextbookIsbn::canonical((string)($entry['isbn']??''));}
            catch(InvalidArgumentException $e){continue;}
            demand($isbn!==$registered,
                'This ISBN already exists in the Book Directory. Select the existing record instead of creating a duplicate',409);
        }
        q($this->db,'INSERT INTO textbook_titles(title,isbn,grade,subject) VALUES (?,?,?,?)',
            [$title,$isbn,$grade,$subject]);
        $id=(int)$this->db->lastInsertId();demand($id>0,'Could not save the ISBN directory entry',500);
        $this->auditor($org,'TEXTBOOK_ISBN_DIRECTORY_REGISTERED',$id,['isbn13'=>$isbn,
            'title'=>$title,'grade'=>$grade,'subject'=>$subject,'source_reference'=>$source]);
        return ['id'=>$id,'isbn13'=>$isbn,'title'=>$title,'status'=>'REGISTERED_NO_STOCK'];
    }
    public function create(array $body):array {
        $org=(int)($body['organization_id']??0);$office=$this->office($org);
        demand($this->db->inTransaction(),'Protected receipt transaction required',500);
        $reference=self::input($body,'co_delivery_reference',6,120);
        $date=self::date((string)($body['received_on']??''),'Physical receipt date');
        // Documentary notes are useful but optional. An authentic CO delivery
        // reference, date and actual book lines already identify the source.
        $remarks=trim((string)($body['remarks']??''));
        demand(mbSafeLen($remarks)<=500,'Receipt remarks must be 500 characters or fewer',422);
        $lines=$body['lines']??null;
        demand(is_array($lines)&&array_is_list($lines)&&count($lines)>=1&&count($lines)<=40,
            'Select between 1 and 40 textbook editions',422);
        // Prevent concurrent receipts sharing the same authentic CO delivery ref.
        $o=one($this->db,'SELECT id FROM organizations WHERE id=? AND active=1 FOR UPDATE',[$org]);
        demand($o!==null,'Receiving office was disabled',409);
        $same=one($this->db,"SELECT r.id FROM document_registry r JOIN document_versions v ON v.organization_id=r.organization_id AND v.doc_type=r.doc_type AND v.entity_id=r.entity_id AND v.version_no=1 WHERE r.organization_id=? AND r.doc_type='TBR' AND JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.co_delivery_reference'))=? LIMIT 1",[$org,$reference]);
        demand($same===null,'This Central Office delivery reference already has a textbook receipt in this office',409);
        $titles=$this->checkTitles($lines);
        $period=substr(self::today(),0,7);
        $number=AnnualDocumentNumbering::reserve($this->db,$org,self::TYPE,$period);
        $iarNumber=AnnualDocumentNumbering::reserve($this->db,$org,'IAR',$period);
        q($this->db,'INSERT INTO document_registry(organization_id,doc_type,period,display_number,entity_id) VALUES (?,?,?,?,0)',[$org,self::TYPE,$period,$number]);
        $id=(int)$this->db->lastInsertId();demand($id>0,'Cannot reserve textbook receipt identity',500);
        $bound=q($this->db,'UPDATE document_registry SET entity_id=? WHERE id=? AND organization_id=? AND entity_id=0',[$id,$id,$org]);
        demand($bound->rowCount()===1,'Textbook receipt identity could not be bound',500);
        $data=['type'=>self::TYPE,'organization_id'=>$org,'source_kind'=>'CENTRAL_OFFICE',
            'receipt_path'=>TextbookReceiptDistributionRules::receivingPath((string)$office['kind']),
            'number'=>$number,'co_delivery_reference'=>$reference,'received_on'=>$date,
            'remarks'=>$remarks,'status'=>'DRAFT','created_by'=>(int)$this->user['id'],
            'iar_number'=>$iarNumber,'iar_generated_on'=>self::today(),
            'iar_document_source'=>'CENTRAL_OFFICE_TEXTBOOK_RECEIPT','iar_created_after_receipt'=>false,
            'inspected_by'=>null,'inspection_reference'=>null,'inspection_note'=>null,
            'accepted_by'=>null,'accepted_on'=>null,'custodian_employee_id'=>null,'signed_acceptance_reference'=>null,
            'lines'=>$titles,'postings'=>[]];
        $this->version($org,$id,1,$data,'Central Office delivery receipt created, stock not posted');
        $this->auditor($org,'TEXTBOOK_CO_RECEIPT_DRAFTED',$id,
            ['delivery_reference'=>$reference,'title_count'=>count($titles),'iar_number'=>$iarNumber]);
        return ['id'=>$id,'number'=>$number,'iar_number'=>$iarNumber,
            'status'=>'DRAFT','line_count'=>count($titles)];
    }
    public function submit(array $body):array {
        demand(false,'The former electronic inspection/approval sequence has been retired. Use Record Physical Receipt with actual paper signatory details.',409);
        $org=(int)($body['organization_id']??0);$r=$this->record($org,(int)($body['receipt_id']??0),true);
        $s=$r['snapshot'];demand((int)$s['created_by']===(int)$this->user['id'],'Only the preparer may submit their draft',403);
        demand(TextbookReceiptDistributionRules::allows('CO_RECEIPT',$s['status'],'SUBMITTED'),'Only a draft may be submitted',409);
        $this->addIarNumber($org,$s); // pre-upgrade draft: create official IAR automatically
        $s['status']='SUBMITTED';$s['submitted_by']=(int)$this->user['id'];
        $this->version($org,$r['id'],$r['version']+1,$s,'Submitted for physical inspection');
        $this->auditor($org,'TEXTBOOK_CO_RECEIPT_SUBMITTED',$r['id']);
        return ['id'=>$r['id'],'number'=>$r['number'],'status'=>'SUBMITTED'];
    }
    public function inspect(array $body):array {
        demand(false,'The former electronic inspection/approval sequence has been retired. Use Record Physical Receipt with actual paper signatory details.',409);
        $org=(int)($body['organization_id']??0);$r=$this->record($org,(int)($body['receipt_id']??0),true);
        $s=$r['snapshot'];
        demand(TextbookReceiptDistributionRules::allows('CO_RECEIPT',$s['status'],'INSPECTED'),'Receipt must be submitted before inspection',409);
        // The receiving custodian may KEY the inspection counts from the
        // ACTUAL separately signed inspectorate report. They cannot certify
        // that they were also the independent inspecting signatory.
        $inspectedOn=self::date((string)($body['inspected_on']??''),'Actual inspection date');
        demand($inspectedOn>=$s['received_on'],'Inspection cannot predate physical CO delivery',422);
        $inspector=self::input($body,'inspection_officer_name',4,160);
        $designation=self::input($body,'inspection_officer_designation',3,160);
        demand(($body['inspection_document_attested']??null)===true,
            'Confirm the named inspector actually inspected and signed the documentary evidence',422);
        $appointment=one($this->db,"SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key='ORG_SUPPLY_PROPERTY_CUSTODIAN_V1' ORDER BY id DESC LIMIT 1",[$org]);
        $custodianName='';
        if($appointment!==null){
            $config=json_decode((string)$appointment['config_json'],true,32,JSON_THROW_ON_ERROR);
            $custodianId=(int)($config['employee_id']??0);
            if($custodianId>0){
                $employee=one($this->db,'SELECT full_name FROM employees WHERE id=?',[$custodianId]);
                $custodianName=trim((string)($employee['full_name']??''));
            }
        }
        $simplify=static fn(string $name):string=>strtolower(trim(preg_replace('/\s+/u',' ',$name)??$name));
        demand($custodianName===''||$simplify($inspector)!==$simplify($custodianName),
            'Name the actual independent inspector or inspection-team officer, not the accepting Property Custodian',422);
        $this->addIarNumber($org,$s);
        $ref=self::input($body,'inspection_reference',8,160);
        $note=self::input($body,'inspection_note',15,500);
        $given=$body['lines']??null;
        demand(is_array($given)&&array_is_list($given)&&count($given)===count($s['lines']),
            'Inspection must account for every original textbook line',422);
        $byNo=[];foreach($given as $line){
            demand(is_array($line) && is_numeric($line['line_no']??null),'Invalid inspection line',422);
            $n=(int)$line['line_no'];demand($n>0&&!isset($byNo[$n]),'Duplicate inspection line',409);$byNo[$n]=$line;
        }
        foreach($s['lines'] as &$saved){
            $n=(int)$saved['line_no'];$row=$byNo[$n]??null;demand(is_array($row),'Inspection line was omitted',409);
            $values=[];foreach(['delivered_quantity','serviceable_quantity','damaged_quantity','held_quantity'] as $k)
                $values[$k]=self::count($row[$k]??null,$k,true);
            try{TextbookReceiptDistributionRules::externalReceiptLine((int)$saved['expected_quantity'],
                $values['delivered_quantity'],$values['serviceable_quantity'],$values['damaged_quantity'],$values['held_quantity']);}
            catch(InvalidArgumentException $e){demand(false,'Inspected quantities do not reconcile to physical delivery',422);}
            foreach($values as $k=>$v)$saved[$k]=$v;
        }unset($saved);
        $s['status']='INSPECTED';
        $s['inspected_by']=(int)$this->user['id']; // authenticated data recorder, not paper signatory
        $s['inspected_on']=$inspectedOn;
        $s['inspection_officer_name']=$inspector;
        $s['inspection_officer_designation']=$designation;
        $s['inspection_document_attested']=true;
        $s['inspection_recorded_by']=(int)$this->user['id'];
        $s['inspection_reference']=$ref;
        $s['inspection_note']=$note;$s['inspected_at']=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format(DATE_ATOM);
        $this->version($org,$r['id'],$r['version']+1,$s,'Physical quantities inspected; stock not posted');
        $this->auditor($org,'TEXTBOOK_CO_RECEIPT_INSPECTED',$r['id'],['evidence_reference'=>$ref]);
        return ['id'=>$r['id'],'number'=>$r['number'],'status'=>'INSPECTED'];
    }
    /**
     * DOCUMENTATION ONLY, NOT AN ELECTRONIC APPROVAL.
     * A scoped school/Division encoder records the inspection & receipt shown
     * on the real paper IAR. The persons named as inspector/receiver do not
     * authenticate, approve, or electronically sign inside IPDAMS.
     * The append-only TBR snapshot and physical stock movement are atomic.
     */
    public function recordPhysicalReceipt(array $body):array {
        $org=(int)($body['organization_id']??0);
        $r=$this->record($org,(int)($body['receipt_id']??0),true);
        demand($this->db->inTransaction(),'Protected documentary receipt transaction required',500);
        $s=$r['snapshot'];
        demand(in_array((string)($s['status']??''),['DRAFT','SUBMITTED','INSPECTED'],true),
            'This receipt has already been completed, rejected or cancelled. Reload before posting.',409);
        $inspector=self::input($body,'inspection_officer_name',4,160);
        $receiver=self::input($body,'receiving_officer_name',4,160);
        $inspectorTitle=trim((string)($body['inspection_officer_designation']??''));
        $receiverTitle=trim((string)($body['receiving_officer_designation']??''));
        demand(mbSafeLen($inspectorTitle)<=160 && mbSafeLen($receiverTitle)<=160,
            'Officer designations must be at most 160 characters',422);
        // The actual same person may be named in both roles if that is truly
        // what the paper record states. The software does not invent a second
        // officer or impersonate their signatures.
        $inspectedOn=self::date((string)($body['inspected_on']??''),'Documented inspection date');
        $acceptedOn=self::date((string)($body['accepted_on']??''),'Physical receipt date');
        demand($inspectedOn>=$s['received_on'],
            'Inspection cannot predate recorded physical delivery',422);
        demand($acceptedOn>=$s['received_on'] && $acceptedOn>=$inspectedOn,
            'Recorded receipt date cannot predate delivery or inspection',422);
        demand(($body['record_matches_paper']??null)===true,
            'Confirm the physical counts and printed signatory details match actual documentary records',422);
        $inspectionRef=trim((string)($body['inspection_reference']??''));
        $receivingRef=trim((string)($body['signed_acceptance_reference']??''));
        $note=trim((string)($body['inspection_note']??''));
        demand(mbSafeLen($inspectionRef)<=160 && mbSafeLen($receivingRef)<=160 && mbSafeLen($note)<=500,
            'Evidence reference or inspection observations exceed the allowed length',422);
        $given=$body['lines']??null;
        demand(is_array($given)&&array_is_list($given)&&count($given)===count($s['lines']),
            'Record the actual physical quantities for every receipt line',422);
        $byNo=[];
        foreach($given as $line){
            demand(is_array($line)&&is_numeric($line['line_no']??null),'Invalid receipt line',422);
            $no=(int)$line['line_no'];
            demand($no>0&&!isset($byNo[$no]),'Duplicate receipt line',409);
            $byNo[$no]=$line;
        }
        $postings=[];$accepted=0;$hasIssue=false;
        foreach($s['lines'] as &$saved){
            $no=(int)$saved['line_no'];$row=$byNo[$no]??null;
            demand(is_array($row),'A physical receipt line is missing',409);
            $vals=[];
            foreach(['delivered_quantity','serviceable_quantity','damaged_quantity','held_quantity'] as $key)
                $vals[$key]=self::count($row[$key]??null,$key,true);
            try{$result=TextbookReceiptDistributionRules::externalReceiptLine(
                (int)$saved['expected_quantity'],$vals['delivered_quantity'],$vals['serviceable_quantity'],
                $vals['damaged_quantity'],$vals['held_quantity']);}
            catch(InvalidArgumentException $e){demand(false,'Physical quantities do not reconcile to delivered copies',422);}
            if($result['has_discrepancy'])$hasIssue=true;
            // An earlier, separately recorded INSPECTED snapshot must not be
            // silently rewritten. Corrections are explicitly documented.
            if(($s['status']??'')==='INSPECTED'){
                $diff=false;
                foreach($vals as $k=>$v)if((int)($saved[$k]??-1)!==$v)$diff=true;
                if($diff){
                    $correction=trim((string)($body['paper_correction_reason']??''));
                    demand(mbSafeLen($correction)>=8 && mbSafeLen($correction)<=500,
                        'Explain corrections to previously documented inspection quantities',422);
                    $s['inspection_correction_reason']=$correction;
                }
            }
            foreach($vals as $key=>$value)$saved[$key]=$value;
            foreach(['serviceable_quantity'=>'Serviceable','damaged_quantity'=>'Damaged - Quarantined'] as $key=>$condition){
                $qty=$vals[$key];if($qty===0)continue;
                $batchName='CO-R'.$r['id'].'-L'.$no.'-'.($key==='serviceable_quantity'?'S':'Q');
                q($this->db,'INSERT INTO textbook_batches(organization_id,title_id,batch,condition_note,storage,opening_record) VALUES (?,?,?,?,?,?)',
                    [$org,(int)$saved['title_id'],$batchName,$condition,(string)$saved['storage'],0]);
                $batchId=(int)$this->db->lastInsertId();
                $reference='CO '.$s['co_delivery_reference'].' / TBR '.$r['number'].' / line '.$no.' / '.$condition;
                demand(mbSafeLen($reference)<=200,'Combined documentary reference is too long',422);
                q($this->db,'INSERT INTO textbook_movements(organization_id,batch_id,quantity_delta,event_type,reference,actor_id) VALUES (?,?,?,?,?,?)',
                    [$org,$batchId,$qty,'RECEIPT',$reference,(int)$this->user['id']]);
                $postings[]=['line_no'=>$no,'isbn13'=>$saved['isbn13'],'batch_id'=>$batchId,
                    'condition'=>$condition,'quantity'=>$qty];
                $accepted+=$qty;
            }
        }unset($saved);
        demand($accepted>0,'No physical copies are eligible for recording; use documented rejection instead',409);
        $this->addIarNumber($org,$s);
        $s['status']=$hasIssue?'ACCEPTED_WITH_DISCREPANCY':'ACCEPTED'; // historical ledger status; NOT electronic approval
        $s['documentation_mode']='PAPER_ONLY';
        $s['inspection_officer_name']=$inspector;
        $s['inspection_officer_designation']=$inspectorTitle;
        $s['inspected_on']=$inspectedOn;
        $s['inspection_reference']=$inspectionRef;
        $s['inspection_note']=$note;
        $s['receiving_officer_name']=$receiver;
        $s['receiving_officer_designation']=$receiverTitle;
        $s['accepted_on']=$acceptedOn;
        $s['signed_acceptance_reference']=$receivingRef;
        $s['inspection_recorded_by']=(int)$this->user['id'];
        $s['receipt_recorded_by']=(int)$this->user['id'];
        $s['receipt_recorded_at']=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format(DATE_ATOM);
        // Explicitly avoid suggesting that the encoder was a paper signatory.
        $s['inspected_by']=null;
        $s['accepted_by']=null;
        $s['custodian_employee_id']=null;
        $s['postings']=$postings;
        $this->version($org,$r['id'],$r['version']+1,$s,
            'Documented real physical delivery, inspection and receipt from paper records; stock credited once; no electronic approval');
        $this->auditor($org,'TEXTBOOK_CO_PHYSICAL_RECEIPT_RECORDED',$r['id'],
            ['recorded_by'=>(int)$this->user['id'],'iar_number'=>$s['iar_number'],
             'inspector'=>$inspector,'receiver'=>$receiver,'posted_copies'=>$accepted,'lots'=>$postings]);
        return ['id'=>$r['id'],'number'=>$r['number'],'iar_number'=>$s['iar_number'],
            'status'=>$s['status'],'recorded_copies'=>$accepted,'approval_required'=>false];
    }

    public function accept(array $body):array {
        demand(false,'The former electronic inspection/approval sequence has been retired. Use Record Physical Receipt with actual paper signatory details.',409);
        $org=(int)($body['organization_id']??0);$r=$this->record($org,(int)($body['receipt_id']??0),true);
        $c=$this->loggedCustodian($org);$s=$r['snapshot'];
        demand($s['status']==='INSPECTED','Only inspected textbook receipts may be accepted',409);
        // Earlier v0.2.278 receipts genuinely inspected by a distinct
        // authenticated user remain acceptable without rewriting history.
        // For a SAME-LOGIN entry, require the new signed independent paper
        // inspection evidence. This does not make the custodian an inspector.
        if((int)($s['inspected_by']??0)===(int)$this->user['id']){
            demand(($s['inspection_document_attested']??false)===true &&
                trim((string)($s['inspection_officer_name']??''))!=='' &&
                trim((string)($s['inspection_reference']??''))!=='',
                'Independent inspectorate evidence is required before the custodian can accept the inspection they recorded',403);
        }
        $ref=self::input($body,'signed_acceptance_reference',8,160);
        $date=self::date((string)($body['accepted_on']??''),'Acceptance date');
        demand($date>=$s['received_on'],'Acceptance cannot predate physical receipt',422);
        if(!empty($s['inspected_on']))demand($date>=$s['inspected_on'],'Acceptance cannot predate documented inspection',422);
        $postings=[];$accepted=0;$hasIssue=false;
        foreach($s['lines'] as $line){
            try{$result=TextbookReceiptDistributionRules::externalReceiptLine(
                (int)$line['expected_quantity'],(int)$line['delivered_quantity'],
                (int)$line['serviceable_quantity'],(int)$line['damaged_quantity'],(int)$line['held_quantity']);}
            catch(InvalidArgumentException $e){demand(false,'Inspection counts need correction before acceptance',409);}
            if($result['has_discrepancy'])$hasIssue=true;
            foreach(['serviceable_quantity'=>'Serviceable','damaged_quantity'=>'Damaged - Quarantined'] as $key=>$condition){
                $qty=(int)$line[$key];if($qty===0)continue;
                $batchName='CO-R'.$r['id'].'-L'.$line['line_no'].'-'.($key==='serviceable_quantity'?'S':'Q');
                q($this->db,'INSERT INTO textbook_batches(organization_id,title_id,batch,condition_note,storage,opening_record) VALUES (?,?,?,?,?,?)',
                    [$org,(int)$line['title_id'],$batchName,$condition,(string)$line['storage'],0]);
                $batchId=(int)$this->db->lastInsertId();
                $reference='CO '.$s['co_delivery_reference'].' / TBR '.$r['number'].' / line '.$line['line_no'].' / '.$condition;
                demand(mbSafeLen($reference)<=200,'Combined delivery reference exceeds textbook ledger limit',422);
                q($this->db,'INSERT INTO textbook_movements(organization_id,batch_id,quantity_delta,event_type,reference,actor_id) VALUES (?,?,?,?,?,?)',
                    [$org,$batchId,$qty,'RECEIPT',$reference,(int)$this->user['id']]);
                $postings[]=['line_no'=>(int)$line['line_no'],'isbn13'=>$line['isbn13'],'batch_id'=>$batchId,
                    'condition'=>$condition,'quantity'=>$qty];$accepted+=$qty;
            }
        }
        demand($accepted>0,'No accepted copies to credit; reject the delivery with supporting evidence instead',409);
        $s['status']=$hasIssue?'ACCEPTED_WITH_DISCREPANCY':'ACCEPTED';
        $s['accepted_by']=(int)$this->user['id'];$s['custodian_employee_id']=$c['employee_id'];
        $s['accepted_on']=$date;$s['signed_acceptance_reference']=$ref;$s['postings']=$postings;
        $s['accepted_at']=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format(DATE_ATOM);
        $this->version($org,$r['id'],$r['version']+1,$s,'Accepted after inspection; linked stock posted atomically');
        $this->auditor($org,'TEXTBOOK_CO_RECEIPT_ACCEPTED',$r['id'],['status'=>$s['status'],'accepted_copies'=>$accepted,
            'source_reference'=>$s['co_delivery_reference'],'paper_reference'=>$ref,'posted_lots'=>$postings]);
        return ['id'=>$r['id'],'number'=>$r['number'],'status'=>$s['status'],'accepted_copies'=>$accepted];
    }
    public function cancelDraft(array $body):array {
        $org=(int)($body['organization_id']??0);$r=$this->record($org,(int)($body['receipt_id']??0),true);$s=$r['snapshot'];
        demand((int)$s['created_by']===(int)$this->user['id'],'Only the preparer may cancel the draft',403);
        demand(TextbookReceiptDistributionRules::allows('CO_RECEIPT',$s['status'],'CANCELLED'),'Only a draft can be cancelled',409);
        $reason=self::input($body,'cancellation_reason',15,500);$s['status']='CANCELLED';
        $this->version($org,$r['id'],$r['version']+1,$s,'Draft cancellation: '.$reason);
        $this->auditor($org,'TEXTBOOK_CO_RECEIPT_CANCELLED',$r['id'],['reason'=>$reason]);
        return ['id'=>$r['id'],'number'=>$r['number'],'status'=>'CANCELLED'];
    }
    public function reject(array $body):array {
        $org=(int)($body['organization_id']??0);$r=$this->record($org,(int)($body['receipt_id']??0),true);$s=$r['snapshot'];
        // The office encoder records a documented rejected delivery; a
        // separate Property Custodian login/approval is not required.
        demand(in_array((string)($s['status']??''),['DRAFT','SUBMITTED','INSPECTED'],true),
            'Only an uncompleted physical delivery can be recorded as rejected',409);
        $reason=self::input($body,'rejection_reason',20,500);$evidence=self::input($body,'rejection_evidence_reference',8,160);
        $s['status']='REJECTED';$s['rejection_reason']=$reason;$s['rejection_evidence_reference']=$evidence;
        $this->version($org,$r['id'],$r['version']+1,$s,'Delivery rejected by appointed custodian');
        $this->auditor($org,'TEXTBOOK_CO_RECEIPT_REJECTED',$r['id'],['reason'=>$reason,'evidence_reference'=>$evidence]);
        return ['id'=>$r['id'],'number'=>$r['number'],'status'=>'REJECTED'];
    }
    public function list(array $input):array {
        $org=(int)($input['organization_id']??0);$this->office($org);
        $sql="SELECT r.id,r.display_number,r.created_at,v.version_no,v.snapshot_json FROM document_registry r
              JOIN document_versions v ON v.organization_id=r.organization_id AND v.doc_type=r.doc_type AND v.entity_id=r.entity_id
               AND v.version_no=(SELECT MAX(z.version_no) FROM document_versions z
                  WHERE z.organization_id=r.organization_id AND z.doc_type=r.doc_type AND z.entity_id=r.entity_id)
              WHERE r.organization_id=? AND r.doc_type='TBR' ORDER BY r.id DESC LIMIT 100";
        $rows=q($this->db,$sql,[$org])->fetchAll();$out=[];
        foreach($rows as $r){
            $s=json_decode((string)$r['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
            demand(is_array($s)&&($s['type']??null)===self::TYPE&&(int)($s['organization_id']??0)===$org,
                'Recorded textbook receipt ownership needs technical review',409);
            $out[]=['id'=>(int)$r['id'],'number'=>$r['display_number'],'created_at'=>$r['created_at'],
                'iar_number'=>$s['iar_number']??null,
                'version'=>(int)$r['version_no'],'co_delivery_reference'=>$s['co_delivery_reference'],
                'receipt_path'=>$s['receipt_path'],'received_on'=>$s['received_on'],'accepted_on'=>$s['accepted_on']??null,
                'status'=>$s['status'],'title_lines'=>count($s['lines']),
                'accepted_serviceable'=>array_sum(array_map(static fn($l)=>(int)($l['serviceable_quantity']??0),
                    in_array($s['status'],['ACCEPTED','ACCEPTED_WITH_DISCREPANCY'],true)?$s['lines']:[]))];
        }
        return $out;
    }
    public function detail(array $input):array {
        $org=(int)($input['organization_id']??0);$r=$this->record($org,(int)($input['receipt_id']??0));
        return ['id'=>$r['id'],'number'=>$r['number'],'version'=>$r['version'],'created_at'=>$r['created_at'],
            'iar_number'=>$r['snapshot']['iar_number']??null,
            'snapshot'=>$r['snapshot'],'lines'=>$r['snapshot']['lines']];
    }
}
