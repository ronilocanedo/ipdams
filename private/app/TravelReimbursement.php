<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/AnnualDocumentNumbering.php';
require_once __DIR__.'/TravelItineraryLines.php';
require_once __DIR__.'/TravelReimbursementLocks.php';
/**
 * Travel reimbursement claims: non-posting documentary intake (v0.2.112).
 * Uses existing AR/TA/RC registry, document snapshots, links and attachments.
 * NOTHING in this class authorizes payment, posts an obligation, allocates a
 * fund, creates a DV, grants a cash advance or marks Division/Region paid.
 */
final class TravelReimbursement {
    private const REQUIRED_EVIDENCE=[
        'approved_request'=>'Returned APPROVED Activity Request with Division handwritten and countersigned estimate',
        'signed_authority'=>'Signed Travel Authority for Official Travel',
        'completion'=>'Certificate of Travel Completed / Appearance or required equivalent',
        'actual_expenses'=>'Travel expense statement and applicable receipts or authorized alternatives'
    ];
    private const ITINERARY_EVIDENCE=['actual_itinerary'=>'Signed/endorsed Appendix 45 Itinerary of Travel (where applicable)'];
    private const COST_TYPES=[
        'TRANSPORT'=>'Transportation / fares',
        'DAILY_EXPENSE'=>'Allowable daily travel expenses',
        'LODGING'=>'Lodging',
        'OTHER_TRAVEL'=>'Other eligible travel costs (for review)'
    ];
    public function __construct(private PDO $db,private array $user){}
    private function org(array $data,bool $write=false): int {
        $org=idOf($data,'organization_id');scope($this->db,$this->user,$org);
        if($write){allowedToWrite($this->user);
            $o=one($this->db,'SELECT kind FROM organizations WHERE id=? AND active=1',[$org]);
            demand($o!==null && ($this->user['role']==='SUPER_ADMIN'||
                ($this->user['role']==='SCHOOL'&&$o['kind']==='SCHOOL')||
                ($this->user['role']==='DIVISION'&&$o['kind']==='DIVISION')),
                'You cannot prepare a reimbursement claim for this workspace',403);
        }
        return $org;
    }
    private static function date(mixed $value,string $label): string {
        demand(is_string($value)&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)===1,'Invalid '.$label);
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        demand($date!==false && $date->format('Y-m-d')===$value,'Invalid '.$label);
        demand($value<=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'),$label.' cannot be in the future');
        demand((int)$date->format('Y')>=1900,'Invalid '.$label);
        return $value;
    }
    private static function note(array $data,string $key,int $max=500): string {
        $v=$data[$key]??'';demand(is_string($v),'Invalid '.$key);
        $s=trim($v);demand(mbSafeLen($s)<=$max,'Invalid '.$key);return $s;
    }
    private function document(int $org,string $type,int $id,bool $lock=false): array {
        demand(in_array($type,['AR','TA','RC'],true),'Invalid travel document type');
        $row=one($this->db,'SELECT id,display_number FROM document_registry WHERE organization_id=? AND doc_type=? AND id=? AND entity_id=?'.($lock?' FOR UPDATE':''),[$org,$type,$id,$id]);
        demand($row!==null,'Linked travel document is unavailable in this workspace',404);
        $v=one($this->db,'SELECT version_no,snapshot_json FROM document_versions WHERE organization_id=? AND doc_type=? AND entity_id=? ORDER BY version_no DESC LIMIT 1',[$org,$type,$id]);
        demand($v!==null,'Linked document has no saved version',409);
        $s=json_decode((string)$v['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($s)&&($s['kind']??'')===$type&&(int)($s['organization_id']??0)===$org,'Document snapshot failed scope check',409);
        return ['id'=>$id,'number'=>$row['display_number'],'version'=>(int)$v['version_no'],'snapshot'=>$s];
    }
    private function source(int $org,int $taId,bool $lock=false): array {
        $ta=$this->document($org,'TA',$taId,$lock);
        $arId=(int)($ta['snapshot']['activity_request_id']??0);
        demand($arId>0,'Travel Authority must link to an Activity Request',409);
        $link=one($this->db,"SELECT id FROM document_links WHERE organization_id=? AND source_type='AR' AND source_id=? AND target_type='TA' AND target_id=?",[$org,$arId,$taId]);
        demand($link!==null,'Travel Authority relationship is invalid',409);
        $ar=$this->document($org,'AR',$arId);
        demand((int)($ta['snapshot']['employee']['id']??0)===(int)($ar['snapshot']['employee']['id']??0),
            'Travel Authority and Activity Request employee mismatch',409);
        demand(($ta['snapshot']['payment_route']??$ar['snapshot']['payment_route']??'REIMBURSEMENT_ONLY')==='REIMBURSEMENT_ONLY',
            'Cash advances are not eligible for this reimbursement workflow',409);
        $charge=$ta['snapshot']['chargeability']??$ar['snapshot']['chargeability']??[];
        $code=(string)($charge['code']??'SCHOOL_MOOE');
        demand(in_array($code,['SCHOOL_MOOE','SPLIT'],true) && (int)($charge['school_fund']['id']??$ar['snapshot']['fund']['id']??0)>0,
            'This travel is charged to an external office. The school cannot file its payment as a local reimbursement; retain it for documentary tracking.',409);
        return ['ta'=>$ta,'ar'=>$ar,'charge'=>$charge];
    }
    private function lines(array $data): array {
        $lines=$data['lines']??null;
        demand(is_array($lines)&&count($lines)>0&&count($lines)<=20&&array_is_list($lines),
            'Enter 1 to 20 actual travel expense lines',422);
        $clean=[];$sum=0;
        foreach($lines as $index=>$line){
            demand(is_array($line),'Invalid expense line',422);
            $category=$line['category']??'';
            demand(is_string($category)&&isset(self::COST_TYPES[$category]),'Choose the correct actual travel cost category',422);
            $description=$line['description']??'';
            demand(is_string($description),'Invalid expense description',422);
            $description=trim($description);
            demand($description!==''&&mbSafeLen($description)<=240,'Each travel expense needs a short description',422);
            $raw=$line['amount']??null;
            demand(is_string($raw)||is_int($raw),'Enter monetary amounts as Philippine pesos',422);
            $cents=moneyCents($raw);
            demand($cents>0 && $cents<=100000000,'Travel expense line must be greater than zero and at most ₱1,000,000',422);
            $sum+=$cents;
            demand($sum<=200000000,'Review expense total; maximum draft supported is ₱2,000,000',422);
            $clean[]=['category'=>$category,'category_label'=>self::COST_TYPES[$category],
                'description'=>$description,'amount'=>moneyString($cents)];
        }
        return ['lines'=>$clean,'total'=>moneyString($sum)];
    }
    private function register(int $org): array {
        $period=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m');
        $number=AnnualDocumentNumbering::reserve($this->db,$org,'RC',$period);
        q($this->db,'INSERT INTO document_registry (organization_id,doc_type,period,display_number,entity_id) VALUES (?,?,?,?,0)',[$org,'RC',$period,$number]);
        $id=(int)$this->db->lastInsertId();demand($id>0,'Claim identity unavailable',500);
        $changed=q($this->db,'UPDATE document_registry SET entity_id=? WHERE id=? AND organization_id=? AND entity_id=0',[$id,$id,$org]);
        demand($changed->rowCount()===1,'Claim identity could not be bound',500);
        return ['id'=>$id,'number'=>$number];
    }
    private function version(int $org,int $id,array $snapshot,string $note): int {
        $v=one($this->db,"SELECT COALESCE(MAX(version_no),0) AS version FROM document_versions WHERE organization_id=? AND doc_type='RC' AND entity_id=?",[$org,$id]);
        $next=(int)($v['version']??0)+1;
        q($this->db,'INSERT INTO document_versions (organization_id,doc_type,entity_id,version_no,snapshot_json,reason,actor_id) VALUES (?,?,?,?,?,?,?)',
            [$org,'RC',$id,$next,json_encode($snapshot,JSON_THROW_ON_ERROR),$note,$this->user['id']]);
        return $next;
    }
    private function audit(int $org,int $id,string $event,array $details): void {
        q($this->db,'INSERT INTO audit_events (organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
            [$org,$this->user['id'],$event,'RC',$id,json_encode($details,JSON_THROW_ON_ERROR)]);
    }
    public function create(array $data): array {
        $org=$this->org($data,true);
        $source=$this->source($org,idOf($data,'authority_id'),true);
        $ta=$source['ta'];$ar=$source['ar'];
        // The TA row lock serializes competing claim creations across users.
        $prior=one($this->db,"SELECT target_id FROM document_links WHERE organization_id=? AND source_type='TA' AND source_id=? AND target_type='RC' LIMIT 1",[$org,$ta['id']]);
        demand($prior===null,'A reimbursement claim already exists for this Travel Authority. Open that draft instead.',409);
        $created=self::date($data['claim_date']??null,'Claim date');
        $from=self::date($data['actual_from']??null,'Actual travel start');
        $to=self::date($data['actual_to']??null,'Actual travel end');
        demand($to>=$from,'Actual travel end precedes its start',422);
        demand($to<=$created,'Claim date cannot precede the last day of actual travel',422);
        demand((string)($ta['snapshot']['travel_from']??'')<=$to,
            'The approved Travel Authority is for a later trip; resolve travel-date authorization before claiming expenses',409);
        demand((string)($ta['snapshot']['document_date']??'1900-01-01')<=$created,
            'Claim date cannot precede Travel Authority issue date',422);
        // Versioned Appendix 45 is documentary data in the existing RC snapshot.
        // Legacy pre-Appendix 45 claim editing remains compatible.
        $itinerary=array_key_exists('itinerary',$data)?TravelItineraryLines::normalize($data['itinerary'],$from,$to):null;
        $lines=$itinerary===null?$this->lines($data):['lines'=>$itinerary['claim_lines'],'total'=>$itinerary['claim_total']];
        $reg=$this->register($org);
        $payload=['kind'=>'RC','organization_id'=>$org,'number'=>$reg['number'],'claim_date'=>$created,
            'travel_authority_id'=>$ta['id'],'travel_authority_number'=>$ta['number'],'travel_authority_version'=>$ta['version'],
            'activity_request_id'=>$ar['id'],'activity_request_number'=>$ar['number'],'activity_request_version'=>$ar['version'],
            'employee'=>$ta['snapshot']['employee'],'activity_title'=>$ta['snapshot']['activity_title']??'',
            'travel_from'=>$ta['snapshot']['travel_from'],'travel_to'=>$ta['snapshot']['travel_to'],
            'actual_from'=>$from,'actual_to'=>$to,'chargeability'=>$source['charge'],
            'school_fund'=>$source['charge']['school_fund']??$ar['snapshot']['fund']??null,
            'claim_lines'=>$lines['lines'],'claim_total'=>$lines['total'],
            'itinerary'=>$itinerary===null?null:$itinerary['itinerary'],
            'expense_note'=>self::note($data,'expense_note',600),
            'created_by'=>(int)$this->user['id'],'evidence'=>[],
            'state'=>'DRAFT_DOCUMENTS_PENDING','cash_advance_requested'=>false,
            'payment_authorized'=>false,'dv_id'=>null,
            'notice'=>'Amount is claimed only, not certified, obligated, approved or payable; accounting checks follow.' ];
        $this->version($org,$reg['id'],$payload,'Draft reimbursement claim created; documents not verified');
        q($this->db,'INSERT INTO document_links (organization_id,source_type,source_id,target_type,target_id) VALUES (?,?,?,?,?)',
            [$org,'TA',$ta['id'],'RC',$reg['id']]);
        $this->audit($org,$reg['id'],'TRAVEL_REIMBURSEMENT_DRAFT_CREATED',[
            'source_ta'=>$ta['id'],'source_ar'=>$ar['id'],'amount'=>$lines['total']]);
        return ['id'=>$reg['id'],'number'=>$reg['number'],'version'=>1,'status'=>$payload['state']];
    }
    public function revise(array $data): array {
        $org=$this->org($data,true);$id=idOf($data,'claim_id');
        $prior=$this->document($org,'RC',$id,true);
        demand((int)($data['expected_version']??-1)===$prior['version'],'Claim changed. Refresh and reopen it before saving',409);
        demand(($prior['snapshot']['state']??'')==='DRAFT_DOCUMENTS_PENDING','Claim is locked after document review',409);
        TravelReimbursementLocks::editable($this->db,$org,$id);
        $old=$prior['snapshot'];$this->source($org,(int)$old['travel_authority_id']);
        $from=self::date($data['actual_from']??null,'Actual travel start');
        $to=self::date($data['actual_to']??null,'Actual travel end');
        demand($to>=$from,'Actual travel end precedes its start');
        $claim=self::date($data['claim_date']??null,'Claim date');
        demand($to<=$claim,'Claim date precedes actual travel completion');
        demand(!is_array($old['itinerary']??null)||array_key_exists('itinerary',$data),
            'The saved itinerary must remain linked to this claim; reopen it before correcting',409);
        $itinerary=array_key_exists('itinerary',$data)?TravelItineraryLines::normalize($data['itinerary'],$from,$to):null;
        $lines=$itinerary===null?$this->lines($data):['lines'=>$itinerary['claim_lines'],'total'=>$itinerary['claim_total']];
        $next=$old;$next['claim_date']=$claim;$next['actual_from']=$from;$next['actual_to']=$to;
        $next['claim_lines']=$lines['lines'];$next['claim_total']=$lines['total'];
        if($itinerary!==null)$next['itinerary']=$itinerary['itinerary'];
        $next['expense_note']=self::note($data,'expense_note',600);
        demand($next!==$old,'No change to reimbursement claim',409);
        // Any substantive claim revision needs a fresh paper checklist; historical versions are intact.
        if(isset($old['document_compliance']))unset($next['document_compliance']);
        if(isset($old['completion_certificate']))unset($next['completion_certificate']);
        $version=$this->version($org,$id,$next,'Draft reimbursement expense correction (manual finance review still required)');
        $this->audit($org,$id,'TRAVEL_REIMBURSEMENT_DRAFT_REVISED',['from_version'=>$prior['version'],'to_version'=>$version]);
        return ['id'=>$id,'number'=>$prior['number'],'version'=>$version];
    }
    private function attachment(int $org,int $id,int $claimId): array {
        $a=one($this->db,"SELECT id,original_name,mime,sha256,created_at FROM attachments WHERE organization_id=? AND doc_type='RC' AND entity_id=? AND id=?",[$org,$claimId,$id]);
        demand($a!==null,'Choose an attachment uploaded to this claim and workspace',422);
        // The attachment ledger can outlive a filesystem restore: never verify
        // a missing or corrupted scan as documentary evidence.
        $stored=one($this->db,"SELECT stored_filename FROM attachments WHERE organization_id=? AND doc_type='RC' AND entity_id=? AND id=?",[$org,$claimId,$id]);
        $base=dirname(__DIR__).'/storage/private';
        $filename=(string)($stored['stored_filename']??'');
        $path=$base.'/'.$filename;
        demand($filename!=='' && basename($filename)===$filename && is_file($path) &&
            hash_file('sha256',$path)===(string)$a['sha256'],
            'This supporting file is missing or damaged; restore or upload a valid copy',409);
        return ['id'=>(int)$a['id'],'original_name'=>$a['original_name'],'mime'=>$a['mime'],
            'sha256'=>$a['sha256'],'uploaded_at'=>$a['created_at']];
    }
    public function attachEvidence(array $data): array {
        $org=$this->org($data,true);$id=idOf($data,'claim_id');
        $record=$this->document($org,'RC',$id,true);
        demand((int)($data['expected_version']??-1)===$record['version'],'Claim was changed. Refresh and retry',409);
        TravelReimbursementLocks::editable($this->db,$org,$id);
        $snap=$record['snapshot'];
        demand(($snap['state']??'')==='DRAFT_DOCUMENTS_PENDING','Reviewed claims cannot have evidence replaced',409);
        $kind=$data['evidence_kind']??null;
        demand(is_string($kind)&&(array_key_exists($kind,self::REQUIRED_EVIDENCE)||
            (array_key_exists($kind,self::ITINERARY_EVIDENCE)&&is_array($snap['itinerary']??null))),
            'Unknown reimbursement document kind',422);
        $aid=idOf($data,'attachment_id');$verified=$this->attachment($org,$aid,$id);
        foreach($snap['evidence']??[] as $other=>$value){
            demand($other===$kind || (int)($value['id']??0)!==$aid,
                'Use distinct uploaded files for each evidence category',422);
        }
        $snap['evidence'][$kind]=$verified;
        $version=$this->version($org,$id,$snap,'Document evidence attached; review remains pending');
        $this->audit($org,$id,'TRAVEL_REIMBURSEMENT_EVIDENCE_LINKED',[
            'kind'=>$kind,'attachment_id'=>$aid,'from_version'=>$record['version'],'to_version'=>$version]);
        return ['id'=>$id,'version'=>$version,'evidence_kind'=>$kind,'status'=>$snap['state']];
    }
    /** Paper-document compliance, stored in versioned claim JSON; zero attachment bytes. */
    public function saveDocumentChecklist(array $data): array {
        $org=$this->org($data,true);$id=idOf($data,'claim_id');
        $record=$this->document($org,'RC',$id,true);
        demand((int)($data['expected_version']??-1)===$record['version'],
            'Claim changed. Refresh before saving documentary checklist',409);
        TravelReimbursementLocks::editable($this->db,$org,$id);
        $snap=$record['snapshot'];
        demand(($snap['state']??'')==='DRAFT_DOCUMENTS_PENDING',
            'Document checklist cannot be changed after Division verification',409);
        $expected=array_keys(self::REQUIRED_EVIDENCE);
        if(is_array($snap['itinerary']??null))$expected[]='actual_itinerary';
        $raw=$data['items']??null;
        demand(is_array($raw)&&!array_is_list($raw),'Supply the document checklist as named checkboxes',422);
        $provided=array_keys($raw);
        sort($provided);$allowed=$expected;sort($allowed);
        demand($provided===$allowed,'Document checklist categories are incomplete or unexpected',422);
        $items=[];
        foreach($expected as $key){
            demand(is_bool($raw[$key]),'Each documentary check must be true or false',422);
            $items[$key]=$raw[$key];
        }
        $previous=$snap['document_compliance']['items']??null;
        demand($items!==$previous,'Document checklist has no changes',409);
        $timestamp=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format(DateTimeInterface::ATOM);
        $snap['document_compliance']=[
            'method'=>'PAPER_DOCUMENTS_ON_FILE',
            'items'=>$items,'confirmed_by'=>(int)$this->user['id'],'confirmed_at'=>$timestamp,
            'note'=>'Checkboxes attest that the originals or authorized documentary equivalents are retained for inspection. No file was uploaded. This is not approval or payment.'
        ];
        $version=$this->version($org,$id,$snap,'Paper-document compliance checklist saved; independent review pending');
        $this->audit($org,$id,'TRAVEL_REIMBURSEMENT_PAPER_CHECKLIST_SAVED',[
            'from_version'=>$record['version'],'to_version'=>$version,
            'confirmed'=>array_keys(array_filter($items)),
            'unconfirmed'=>array_keys(array_filter($items,static fn($v)=>!$v)),
            'method'=>'PAPER_DOCUMENTS_ON_FILE','payment_authorized'=>false]);
        return ['id'=>$id,'version'=>$version,'status'=>$snap['state'],
            'confirmed_count'=>count(array_filter($items)),'required_count'=>count($items)];
    }
    /** Appendix 47 paper certification is an audited, unsigned claim attachment record.
     * A saved draft is NOT a signed certificate, Division approval or financial posting.
     */
    private static function normalizeCompletionCertificate(array $data,string $travelEnd): array {
        $completedOn=self::date($data['certificate_date']??null,'Certificate of Travel Completed date');
        demand($completedOn >= $travelEnd,'The certificate date cannot precede the actual travel completion date',422);
        $condition=$data['condition']??null;
        demand(is_string($condition) && in_array($condition,
            ['AS_AUTHORIZED','CUT_SHORT','EXTENDED','OTHER_DEVIATION'],true),
            'Select what actually happened during the authorized travel',422);
        $explanation=self::note($data,'explanation',1100);
        if($condition!=='AS_AUTHORIZED')
            demand(mbSafeLen($explanation)>=8,'Explain how the trip differed from the approved itinerary',422);
        $evidence=self::note($data,'evidence_of_travel',1200);
        // Actual cash refunds are never generated or assumed by this draft.
        $refund='0.00';$orNumber='';$orDate='';
        if($condition==='CUT_SHORT'){
            $raw=$data['refund_amount']??'0.00';
            demand(is_string($raw)||is_int($raw),'Enter a valid refund amount',422);
            $cents=moneyCents($raw);
            demand($cents>=0 && $cents<=100000000,'Invalid or excessive refund amount',422);
            $refund=moneyString($cents);
            $orNumber=self::note($data,'refund_or_number',100);
            $dateInput=$data['refund_or_date']??'';
            demand(is_string($dateInput),'Invalid refund official receipt date',422);
            $orDate=$dateInput!==''?self::date($dateInput,'Refund official receipt date'):'';
            if($cents>0)demand($orNumber!=='' && $orDate!=='',
                'Enter the verified refund official receipt number and date, or leave the refund amount at zero',422);
        }
        return ['format'=>'APPENDIX_47','certificate_date'=>$completedOn,'condition'=>$condition,
            'explanation'=>$explanation,'evidence_of_travel'=>$evidence,
            'refund_amount'=>$refund,'refund_or_number'=>$orNumber,'refund_or_date'=>$orDate,
            'status'=>'UNSIGNED_PAPER_DRAFT'];
    }
    public function saveCompletionCertificate(array $data): array {
        $org=$this->org($data,true);$id=idOf($data,'claim_id');
        $record=$this->document($org,'RC',$id,true);
        demand((int)($data['expected_version']??-1)===$record['version'],
            'Claim changed. Refresh the record before saving the certificate',409);
        TravelReimbursementLocks::editable($this->db,$org,$id);
        $snapshot=$record['snapshot'];
        demand(($snapshot['state']??'')==='DRAFT_DOCUMENTS_PENDING',
            'This claim was already reviewed. Its saved certificate is read-only.',409);
        $this->source($org,(int)$snapshot['travel_authority_id']);
        $certificate=self::normalizeCompletionCertificate($data,(string)$snapshot['actual_to']);
        $certificate['prepared_by']=(int)$this->user['id'];
        $certificate['prepared_at']=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format(DateTimeInterface::ATOM);
        $old=$snapshot['completion_certificate']??null;
        // A deliberate resave is allowed and recorded as a new audit version.
        // The original certificate remains available in historical claim versions.
        $snapshot['completion_certificate']=$certificate;
        // Reconfirm the on-file paper checklist after creating/revising the CTC.
        unset($snapshot['document_compliance']);
        $version=$this->version($org,$id,$snapshot,'Appendix 47 paper certificate saved for signature; document checklist must be reconfirmed');
        $this->audit($org,$id,'TRAVEL_COMPLETION_CERTIFICATE_DRAFT_SAVED',[
            'from_version'=>$record['version'],'to_version'=>$version,'condition'=>$certificate['condition'],
            'prior_certificate'=>is_array($old),'signed'=>false,'payment_authorized'=>false]);
        return ['id'=>$id,'number'=>$record['number'],'version'=>$version,
            'status'=>$certificate['status'],'checklist_reconfirmation_required'=>true];
    }
    public function verifyEvidence(array $data): array {
        $org=$this->org($data);
        demand(in_array($this->user['role'],['DIVISION','SUPER_ADMIN'],true),
            'Only a designated Division reviewer may record manual travel-document verification',403);
        $id=idOf($data,'claim_id');$record=$this->document($org,'RC',$id,true);
        demand((int)($data['expected_version']??-1)===$record['version'],'Claim changed. Refresh and review again',409);
        TravelReimbursementLocks::editable($this->db,$org,$id);
        $snap=$record['snapshot'];
        demand(($snap['state']??'')==='DRAFT_DOCUMENTS_PENDING','Claim already reviewed or locked',409);
        demand((int)$snap['created_by']!==(int)$this->user['id'],'Claim preparer cannot verify their own reimbursement evidence',403);
        // Reviewer accepts a saved paper-document compliance checklist, not a checkbox in a forged request.
        // Historical fully scanned claims remain eligible via their validated attachments.
        $expected=array_keys(self::REQUIRED_EVIDENCE);
        if(is_array($snap['itinerary']??null))$expected[]='actual_itinerary';
        $checks=$snap['document_compliance']['items']??null;
        $hasPaperChecklist=is_array($checks) &&
            ($snap['document_compliance']['method']??'')==='PAPER_DOCUMENTS_ON_FILE';
        if($hasPaperChecklist){
            $checked=array_keys($checks);$required=$expected;sort($checked);sort($required);
            demand($checked===$required,'Saved paper-document checklist does not match the claim',409);
            foreach($expected as $key){
                demand(($checks[$key]??null)===true,'Paper document remains unchecked: '.
                    (self::REQUIRED_EVIDENCE[$key]??self::ITINERARY_EVIDENCE[$key]??$key),422);
            }
        }else{
            demand(!empty($snap['evidence'])&&is_array($snap['evidence']),
                'Save the paper-document compliance checklist before Division verification',422);
            foreach(self::REQUIRED_EVIDENCE as $key=>$label){
                demand(isset($snap['evidence'][$key]['id']),'Missing '.$label,422);
                $this->attachment($org,(int)$snap['evidence'][$key]['id'],$id);
            }
            if(is_array($snap['itinerary']??null)){
                demand(isset($snap['evidence']['actual_itinerary']['id']),
                    'Missing endorsed actual Appendix 45 itinerary',422);
                $this->attachment($org,(int)$snap['evidence']['actual_itinerary']['id'],$id);
            }
        }
        foreach(['approved_request_checked','division_countersign_checked','authority_signed_checked',
            'travel_completed_checked','receipts_checked','duplicate_claim_checked'] as $confirm){
            demand(($data[$confirm]??false)===true,'Complete the manual verification checklist before sending for accounting review',422);
        }
        $source=$this->source($org,(int)$snap['travel_authority_id']);
        demand((int)$source['ta']['id']===(int)$snap['travel_authority_id'] &&
            (int)$source['ar']['id']===(int)$snap['activity_request_id'],'Linked source documents changed',409);
        $snap['state']='DOCUMENTS_CHECKED_FOR_ACCOUNTING_REVIEW';
        $snap['document_review']=['reviewed_by'=>(int)$this->user['id'],
            'reviewed_at'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format(DateTimeInterface::ATOM),
            'checks'=>['approved_request','handwritten_countersignature','signed_authority','completed_travel','receipts','duplicate_claim'],
            'method'=>$hasPaperChecklist?'PAPER_DOCUMENTS_ON_FILE':'VERIFIED_SCANNED_ATTACHMENTS',
            'checklist_confirmed_by'=>$hasPaperChecklist?($snap['document_compliance']['confirmed_by']??null):null,
            'payment_authorized'=>false];
        $version=$this->version($org,$id,$snap,'Independent documentary compliance reviewed; NOT approved for payment');
        $this->audit($org,$id,'TRAVEL_REIMBURSEMENT_DOCUMENTS_CHECKED',[
            'from_version'=>$record['version'],'to_version'=>$version,
            'method'=>$hasPaperChecklist?'PAPER_DOCUMENTS_ON_FILE':'VERIFIED_SCANNED_ATTACHMENTS',
            'payment_authorized'=>false]);
        return ['id'=>$id,'version'=>$version,'status'=>$snap['state'],
            'notice'=>'Documents checked. Accounting eligibility, allocation, amount and DV remain pending.'];
    }
    public function list(array $data): array {
        $org=$this->org($data);$rows=q($this->db,
            "SELECT r.id,r.display_number,v.version_no,v.snapshot_json FROM document_registry r JOIN document_versions v ON v.organization_id=r.organization_id AND v.doc_type=r.doc_type AND v.entity_id=r.entity_id AND v.version_no=(SELECT MAX(version_no) FROM document_versions WHERE organization_id=r.organization_id AND doc_type=r.doc_type AND entity_id=r.entity_id) WHERE r.organization_id=? AND r.doc_type='RC' AND r.entity_id=r.id ORDER BY r.id DESC LIMIT 100",[$org])->fetchAll();
        $out=[];
        foreach($rows as $row){$s=json_decode((string)$row['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            $out[]=['id'=>(int)$row['id'],'number'=>$row['display_number'],'version'=>(int)$row['version_no'],
                'employee'=>$s['employee']['full_name']??'','activity_title'=>$s['activity_title']??'',
                'authority_id'=>$s['travel_authority_id']??null,'activity_request_id'=>$s['activity_request_id']??null,
                'status'=>$s['state']??'UNKNOWN','total'=>$s['claim_total']??'0.00','claim_date'=>$s['claim_date']??'',
                'uploaded_count'=>count(array_intersect_key($s['evidence']??[],self::REQUIRED_EVIDENCE)),
                'checked_count'=>count(array_filter($s['document_compliance']['items']??[],static fn($v)=>$v===true)),
                'required_count'=>count(self::REQUIRED_EVIDENCE)+(is_array($s['itinerary']??null)?1:0),
                'itinerary_reference'=>$s['itinerary']['reference']??'',
                'finalization_reference'=>(ReimbursementFinalization::load($this->db,$org,'T',(int)$row['id'])['reference']??''),
                'without_receipt_total'=>$s['itinerary']['totals']['without_receipt']??'0.00'];}
        return ['claims'=>$out,'required_evidence'=>self::REQUIRED_EVIDENCE,
            'optional_evidence'=>self::ITINERARY_EVIDENCE,'cost_types'=>self::COST_TYPES];
    }
    /** Scoped, paginated DOCUMENTARY report of itinerary expenses without official receipts.
     * Alternative documentary support is labeled, not mistaken for an official receipt.
     * No eligibility or settlement statement is made by this report.
     */
    public function withoutReceiptReport(array $data): array {
        demand(($this->user['role']??'')!=='VIEWER',
            'Issued-only MOOE viewer accounts cannot read private employee travel claims',403);
        $org=$this->org($data);
        $before=$data['before_id']??null;
        demand($before===null || ((is_string($before)&&ctype_digit($before))||is_int($before)) && (int)$before>0,
            'Invalid report page cursor',422);
        $limit=40;
        $params=[$org];$filter='';
        if($before!==null){$filter=' AND r.id<?';$params[]=(int)$before;}
        $rows=q($this->db,
            "SELECT r.id,r.display_number,v.snapshot_json FROM document_registry r JOIN document_versions v
             ON v.organization_id=r.organization_id AND v.doc_type=r.doc_type AND v.entity_id=r.entity_id
             AND v.version_no=(SELECT MAX(version_no) FROM document_versions
                 WHERE organization_id=r.organization_id AND doc_type=r.doc_type AND entity_id=r.entity_id)
             WHERE r.organization_id=? AND r.doc_type='RC' AND r.entity_id=r.id".$filter.
             " ORDER BY r.id DESC LIMIT 41",$params)->fetchAll(PDO::FETCH_ASSOC);
        $hasMore=count($rows)>$limit;$page=array_slice($rows,0,$limit);$out=[];
        foreach($page as $row){
            $snapshot=json_decode((string)$row['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($snapshot)||($snapshot['organization_id']??null)!==$org)continue;
            foreach(TravelItineraryLines::withoutReceipts($snapshot,(int)$row['id'],(string)$row['display_number']) as $entry)$out[]=$entry;
        }
        return ['rows'=>$out,'has_more'=>$hasMore,
            'next_cursor'=>$hasMore&&$page?(int)end($page)['id']:null,
            'claims_scanned'=>count($page),
            'notice'=>'Amounts have no official receipts on the itinerary; alternative evidence and eligibility must be reviewed. This is not a payable or paid report.'];
    }
    public function detail(array $data): array {
        $org=$this->org($data);$id=idOf($data,'claim_id');
        $row=$this->document($org,'RC',$id);
        $finalization=ReimbursementFinalization::load($this->db,$org,'T',$id);
        $uploads=q($this->db,"SELECT id,original_name,mime,sha256,created_at FROM attachments WHERE organization_id=? AND doc_type='RC' AND entity_id=? ORDER BY id ASC LIMIT 100",[$org,$id])->fetchAll();
        foreach($uploads as &$a)$a['id']=(int)$a['id'];unset($a);
        return $row+['finalization'=>$finalization, 'attachments'=>$uploads,'required_evidence'=>self::REQUIRED_EVIDENCE,
            'optional_evidence'=>self::ITINERARY_EVIDENCE,'cost_types'=>self::COST_TYPES];
    }
}
