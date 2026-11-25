<?php
declare(strict_types=1);
/** Appendix 61 Purchase Order documentary record and print layout.
 * Captured in the existing PO issuance transaction; never creates payment,
 * fund certification, signature, acceptance or electronic approval.
 */
final class PoDocuments {
    private const PREFIX = 'PO_APPENDIX_61_V1_';
    private const CORRECTION_PREFIX = 'PO_APPENDIX_61_TERMS_CORRECTION_V1_';
    private const FULL_CORRECTION_PREFIX = 'PO_APPENDIX_61_DOCUMENT_CORRECTION_V1_';
    private const DELIVERY_TERMS = ['Door-to-Door Delivery','For Pick-up','Courier / Freight','Other (as agreed)'];
    private const PAYMENT_TERMS = ['Full Payment upon Delivery','Payment upon Inspection and Acceptance','Progress Billing (subject to contract)','Other (as agreed)'];
    private const PAYMENT_TYPES = ['Check','Cash','Bank Transfer','Other (as agreed)'];
    private const METHODS = ['Small Value Procurement','Negotiated Procurement','Competitive Bidding','Other (documented)'];
    private static function field(array $d,string $key,int $limit): string {
        $v=$d[$key]??null;
        demand(is_string($v),'Enter a valid '.str_replace('_',' ',$key),422);
        $s=trim($v);
        demand($s!=='' && mbSafeLen($s)<=$limit && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$s),
            'Complete '.str_replace('_',' ',$key).' (maximum '.$limit.' characters)',422);
        return $s;
    }
    public static function prepare(PDO $db,int $org,array $body,string $poDate): array {
        $awardId=idOf($body,'award_id');
        $source=one($db,"SELECT a.id,p.id pr_id,p.organization_id,p.transaction_id,t.method
            FROM awards a JOIN purchase_requests p ON p.id=a.pr_id AND p.organization_id=a.organization_id
            JOIN procurement_transactions t ON t.id=p.transaction_id AND t.organization_id=p.organization_id
            WHERE a.id=? AND a.organization_id=? AND a.status='FINALIZED'",[$awardId,$org]);
        demand($source!==null,'Active scoped award unavailable',404);
        $place=self::field($body,'delivery_place',500);
        $date=$body['delivery_date']??null;
        demand(is_string($date)&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)===1,'Enter an expected delivery date',422);
        [$y,$m,$d]=array_map('intval',explode('-',$date));
        demand($y>=1900 && $y<=2100 && checkdate($m,$d,$y),'Invalid expected delivery date',422);
        demand($date>=$poDate,'Expected delivery date cannot precede the PO date (same-day allowed)',422);
        $delivery=self::field($body,'delivery_term',100);
        $payment=self::field($body,'payment_term',100);
        $type=self::field($body,'payment_type',100);
        $method=self::field($body,'procurement_method',100);
        demand(in_array($delivery,self::DELIVERY_TERMS,true),'Select a documented delivery term',422);
        demand(in_array($payment,self::PAYMENT_TERMS,true),'Select a documented payment term',422);
        demand(in_array($type,self::PAYMENT_TYPES,true),'Select a documented payment type',422);
        demand(in_array($method,self::METHODS,true),'Select the procurement mode for this PO',422);
        // The PO uses one awarded PR: do not misrepresent a future multi-PR expansion.
        return ['award_id'=>$awardId,'pr_id'=>(int)$source['pr_id'],'document_date'=>$poDate,
            'delivery_place'=>$place,'delivery_date'=>$date,'delivery_term'=>$delivery,
            'payment_term'=>$payment,'payment_type'=>$type,'procurement_method'=>$method,
            'pr_route'=>(string)$source['method']];
    }
    public static function record(PDO $db,int $org,int $actor,int $poId,array $prepared): void {
        demand(!one($db,'SELECT id FROM configuration_versions WHERE organization_id=? AND config_key=? LIMIT 1',[$org,self::PREFIX.$poId]),'PO terms already recorded',409);
        $po=one($db,"SELECT po.id,po.display_number,po.award_id,po.pr_id,po.supplier_id,po.status,
                pr.purpose,pr.display_number pr_number,pr.fund_id,
                f.name fund_name,f.source_code fund_source_code,
                s.name supplier_name,s.address supplier_address,s.tax_id supplier_tin,s.zip_code supplier_zip,s.proprietor_name,
                o.name organization_name,o.address organization_address
            FROM purchase_orders po JOIN purchase_requests pr ON pr.id=po.pr_id AND pr.organization_id=po.organization_id
            JOIN funds f ON f.id=pr.fund_id AND f.organization_id=po.organization_id
            JOIN suppliers s ON s.id=po.supplier_id
            JOIN organizations o ON o.id=po.organization_id
            WHERE po.id=? AND po.organization_id=?",[$poId,$org]);
        demand($po!==null&&$po['status']==='FINALIZED' && (int)$po['award_id']===$prepared['award_id'] &&
            (int)$po['pr_id']===$prepared['pr_id'],'New PO documentary linkage mismatch',409);
        $v=one($db,"SELECT id,snapshot_json FROM document_versions WHERE organization_id=? AND doc_type='PO' AND entity_id=? ORDER BY version_no DESC LIMIT 1",[$org,$poId]);
        demand($v!==null,'Finalized PO source snapshot unavailable',409);
        $lines=q($db,'SELECT l.id,l.pr_line_id,pl.description_snapshot AS description,pl.unit_snapshot AS unit,l.quantity,l.unit_price FROM po_lines l JOIN pr_lines pl ON pl.id=l.pr_line_id AND pl.pr_id=? WHERE l.po_id=? ORDER BY l.id',[$po['pr_id'],$poId])->fetchAll();
        demand(count($lines)>0,'PO line snapshot unavailable',409);
        $total=0;
        foreach($lines as $line){$cents=totalCents(moneyCents($line['unit_price']),qtyMillis($line['quantity']));
            demand($total<=PHP_INT_MAX-$cents,'PO total overflows supported amount',409);$total+=$cents;}
        $prVersion=one($db,"SELECT snapshot_json FROM document_versions WHERE organization_id=? AND doc_type='PR' AND entity_id=? ORDER BY version_no DESC LIMIT 1",[$org,$po['pr_id']]);
        $prData=$prVersion?json_decode((string)$prVersion['snapshot_json'],true):null;
        $fundCluster=is_array($prData)?trim((string)($prData['header']['fund_cluster']??'')):'';
        require_once __DIR__.'/PrPrint.php';
        $approver=ipdamsPrSchoolApprover($db,$org,$prepared['document_date']);
        $data=$prepared+[
            'po_id'=>$poId,'number'=>(string)$po['display_number'],
            'source_version_id'=>(int)$v['id'],'source_sha256'=>hash('sha256',(string)$v['snapshot_json']),
            'organization'=>['name'=>(string)$po['organization_name'],'address'=>(string)($po['organization_address']??'')],
            'supplier'=>['id'=>(int)$po['supplier_id'],'name'=>(string)$po['supplier_name'],
                'address'=>(string)($po['supplier_address']??''),'tin'=>(string)($po['supplier_tin']??''),
                'zip'=>(string)($po['supplier_zip']??''),'proprietor'=>(string)($po['proprietor_name']??'')],
            'pr_number'=>(string)$po['pr_number'],'purpose'=>(string)$po['purpose'],'fund_cluster'=>$fundCluster,
            'fund'=>['id'=>(int)$po['fund_id'],'name'=>(string)$po['fund_name'],'source_code'=>(string)$po['fund_source_code']],
            'school_head'=>$approver,'lines'=>$lines,'total'=>moneyString($total)];
        q($db,'INSERT INTO configuration_versions(organization_id,config_key,config_json,effective_from,review_note) VALUES(?,?,?,?,?)',
            [$org,self::PREFIX.$poId,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$prepared['document_date'],
                'Appendix 61 PO supplier/terms/line/source signatory snapshot at issuance']);
        q($db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES(?,?,?,?,?,?)',
            [$org,$actor,'PO_APPENDIX_61_RECORDED','PO',$poId,json_encode(['version_id'=>$v['id'],'document_date'=>$prepared['document_date'],'total'=>$data['total']],JSON_THROW_ON_ERROR)]);
    }
    public static function read(PDO $db,int $org,int $poId): ?array {
        $rows=q($db,'SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 2',[$org,self::PREFIX.$poId])->fetchAll();
        demand(count($rows)<=1,'Conflicting PO Appendix 61 records',409);
        if(!$rows)return null;
        $data=json_decode((string)$rows[0]['config_json'],true,64,JSON_THROW_ON_ERROR);
        demand(is_array($data)&&(int)($data['po_id']??0)===$poId&&is_array($data['lines']??null),'Invalid PO Appendix 61 source',409);
        $v=one($db,"SELECT snapshot_json FROM document_versions WHERE organization_id=? AND doc_type='PO' AND entity_id=? AND id=?",[$org,$poId,(int)($data['source_version_id']??0)]);
        demand($v!==null && is_string($data['source_sha256']??null) && hash_equals($data['source_sha256'],hash('sha256',(string)$v['snapshot_json'])),
            'PO source integrity check failed; review recorded original',409);
        // Combine legacy terms-only revisions and complete documentary revisions in
        // actual insertion order. Each revision is chained to the prior effective
        // PO snapshot, so original copies and their corrections remain auditable.
        $corrections=q($db,'SELECT id,config_key,config_json FROM configuration_versions WHERE organization_id=? AND config_key IN (?,?) ORDER BY id ASC',
            [$org,self::CORRECTION_PREFIX.$poId,self::FULL_CORRECTION_PREFIX.$poId])->fetchAll();
        $previousHash=hash('sha256',json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $revision=0;
        foreach($corrections as $row){
            $correction=json_decode((string)$row['config_json'],true,64,JSON_THROW_ON_ERROR);
            demand(is_array($correction) && (int)($correction['po_id']??0)===$poId &&
                (int)($correction['revision']??0)===$revision+1 &&
                hash_equals((string)($correction['previous_sha256']??''),$previousHash),
                'PO correction history requires administrator review',409);
            $terms=$correction['terms']??null;
            demand(is_array($terms),'Invalid PO correction history',409);
            foreach(['delivery_date','delivery_place','delivery_term','payment_term','payment_type','procurement_method'] as $key){
                demand(is_string($terms[$key]??null),'Incomplete PO correction history',409);
                $data[$key]=$terms[$key];
            }
            if($row['config_key']===self::FULL_CORRECTION_PREFIX.$poId){
                demand(is_string($correction['document_date']??null) &&
                    is_array($correction['lines']??null) && count($correction['lines'])>0 &&
                    is_string($correction['total']??null) && is_array($correction['supplier']??null) &&
                    is_array($correction['school_head']??null),
                    'Invalid complete PO correction record',409);
                $data['document_date']=$correction['document_date'];
                $data['lines']=$correction['lines'];
                $data['total']=$correction['total'];
                $data['supplier']=$correction['supplier'];
                $data['school_head']=$correction['school_head'];
            }
            $previousHash=hash('sha256',json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            $revision++;
        }
        $data['terms_revision']=$revision;
        return $data;
    }
    /** The first linked IAR, even if later cancelled, is the documentary PO
     * edit lock. Recorded wet signatures are external and do not lock a PO. */
    private static function editableLines(PDO $db,int $org,int $poId,array $po,array $doc): array {
        $award=one($db,'SELECT id,rfq_id,pr_id,supplier_id,status FROM awards WHERE id=? AND organization_id=?',[$po['award_id'],$org]);
        demand($award!==null && $award['status']==='FINALIZED' &&
            (int)$award['pr_id']===(int)$po['pr_id'] && (int)$award['supplier_id']===(int)$po['supplier_id'],
            'PO award/supplier lineage needs review',409);
        $rfq=one($db,'SELECT selected_lines_json FROM rfqs WHERE id=? AND organization_id=? AND status=?',
            [(int)$award['rfq_id'],$org,'FINALIZED']);
        demand($rfq!==null,'Awarded RFQ unavailable',409);
        $selected=json_decode((string)($rfq['selected_lines_json']??''),true);
        $limits=[];
        if(is_array($selected) && count($selected)>0){
            foreach($selected as $l){
                $id=(int)($l['pr_line_id']??0);
                demand($id>0&&!isset($limits[$id]),'Invalid awarded RFQ item selection',409);
                $limits[$id]=qtyMillis($l['quantity']??'');
            }
        }
        $quote=one($db,'SELECT id FROM supplier_quotes WHERE rfq_id=? AND supplier_id=?',
            [(int)$award['rfq_id'],(int)$award['supplier_id']]);
        demand($quote!==null,'Awarded supplier quotation unavailable',409);
        $quotes=q($db,'SELECT q.pr_line_id,q.offered_price,q.compliant,p.description_snapshot,p.unit_snapshot,p.quantity pr_quantity,p.item_id FROM quote_lines q JOIN pr_lines p ON p.id=q.pr_line_id AND p.pr_id=? WHERE q.supplier_quote_id=? ORDER BY p.id',
            [(int)$po['pr_id'],(int)$quote['id']])->fetchAll();
        $existing=[];
        foreach($doc['lines'] as $l)$existing[(int)$l['pr_line_id']]=$l;
        $options=[];
        foreach($quotes as $q){
            $id=(int)$q['pr_line_id'];
            if((int)$q['compliant']!==1 || ($limits && !isset($limits[$id])))continue;
            $rfqMax=$limits[$id]??qtyMillis($q['pr_quantity']);
            $prMax=qtyMillis($q['pr_quantity']);
            $otherPr=one($db,"SELECT COALESCE(SUM(l.quantity),0) total FROM po_lines l JOIN purchase_orders p ON p.id=l.po_id WHERE p.pr_id=? AND l.pr_line_id=? AND p.id!=? AND p.status!='CANCELLED'",[(int)$po['pr_id'],$id,$poId]);
            $otherAward=one($db,"SELECT COALESCE(SUM(l.quantity),0) total FROM po_lines l JOIN purchase_orders p ON p.id=l.po_id WHERE p.award_id=? AND l.pr_line_id=? AND p.id!=? AND p.status!='CANCELLED'",[(int)$award['id'],$id,$poId]);
            $max=min($prMax-qtyMillis($otherPr['total']),$rfqMax-qtyMillis($otherAward['total']));
            $current=$existing[$id]??null;
            demand($max>=0,'Conflicting PO quantities in awarded source',409);
            $options[]=['pr_line_id'=>$id,'item_id'=>(int)$q['item_id'],
                'description'=>(string)$q['description_snapshot'],'unit'=>(string)$q['unit_snapshot'],
                'unit_price'=>(string)$q['offered_price'],'quantity'=>(string)($current['quantity']??'0.000'),
                'included'=>$current!==null,'max_quantity'=>qtyString($max)];
            unset($existing[$id]);
        }
        demand(!$existing,'Existing PO has unverified or noncompliant item(s). Administrative review required',409);
        demand(count($options)>0,'No compliant quotation lines available for PO corrections',409);
        return $options;
    }
    /** Supplier Profile is the only place to edit vendor identity and contact fields.
     * PO correction retrieves these fields from the master supplier row and
     * preserves the resolved values in a new audited print version.
     */
    private static function supplierFromMaster(PDO $db,int $supplierId,bool $lock=false): array {
        $r=one($db,'SELECT id,name,address,tax_id,zip_code,proprietor_name FROM suppliers WHERE id=?'.($lock?' FOR UPDATE':''),[$supplierId]);
        demand($r!==null && (int)$r['id']===$supplierId && trim((string)$r['name'])!=='',
            'Linked Supplier Profile is unavailable. Update the Supplier Profile before correcting the PO.',409);
        return ['id'=>(int)$r['id'],'name'=>(string)$r['name'],
            'address'=>(string)($r['address']??''),'tin'=>(string)($r['tax_id']??''),
            'zip'=>(string)($r['zip_code']??''),'proprietor'=>(string)($r['proprietor_name']??'')];
    }
    public static function editable(PDO $db,int $org,int $poId): array {
        $row=one($db,'SELECT id,status,display_number,award_id,pr_id,supplier_id FROM purchase_orders WHERE organization_id=? AND id=?',[$org,$poId]);
        demand($row!==null && $row['status']==='FINALIZED','Finalized PO not available in this school',404);
        demand(one($db,'SELECT id FROM iars WHERE po_id=? LIMIT 1',[$poId])===null,
            'PO editing is locked because an IAR has been prepared for this PO',409);
        $d=self::read($db,$org,$poId);
        demand($d!==null,'This older PO lacks a verifiable Appendix 61 snapshot. Administrator review is required.',409);
        $supplier=self::supplierFromMaster($db,(int)$row['supplier_id']);
        return ['po_id'=>$poId,'number'=>$row['display_number'],'document_date'=>$d['document_date'],
            'supplier'=>$supplier['name'],'supplier_address'=>$supplier['address'],
            'supplier_tin'=>$supplier['tin'],'supplier_zip'=>$supplier['zip'],
            'supplier_proprietor'=>$supplier['proprietor'],
            'supplier_profile_changed'=>$supplier!==$d['supplier'],
            'supplier_profile_sha256'=>hash('sha256',json_encode($supplier,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),
            'award_id'=>(int)$row['award_id'],'total'=>$d['total'],
            'revision'=>$d['terms_revision']??0,'lines'=>self::editableLines($db,$org,$poId,$row,$d),
            'min_document_date'=>self::awardMinDate($db,$org,(int)$row['award_id']),
            'delivery_date'=>$d['delivery_date'],'delivery_place'=>$d['delivery_place'],
            'delivery_term'=>$d['delivery_term'],'payment_term'=>$d['payment_term'],
            'payment_type'=>$d['payment_type'],'procurement_method'=>$d['procurement_method']];
    }
    private static function awardMinDate(PDO $db,int $org,int $awardId): string {
        $meta=AwardDocuments::read($db,$org,$awardId);
        if($meta)return ProcurementDocumentDates::date($meta['resolution_date'],'Award date');
        $r=one($db,"SELECT created_at FROM document_registry WHERE organization_id=? AND doc_type='AWARD' AND entity_id=?",[$org,$awardId]);
        demand($r!==null,'Award recorded date unavailable',409);
        return ProcurementDocumentDates::date(substr((string)$r['created_at'],0,10),'Award recorded date');
    }
    public static function correctTerms(PDO $db,int $org,int $actor,array $body): array {
        $poId=idOf($body,'po_id');
        $po=one($db,'SELECT id,award_id,status FROM purchase_orders WHERE id=? AND organization_id=? FOR UPDATE',[$poId,$org]);
        demand($po!==null&&$po['status']==='FINALIZED','Finalized PO not available for correction',404);
        demand(one($db,'SELECT id FROM iars WHERE po_id=? LIMIT 1',[$poId])===null,
            'PO already has a delivery/inspection record; documentary correction requires downstream review',409);
        $old=self::read($db,$org,$poId);
        demand($old!==null,'Older PO source details were not captured and cannot be reconstructed',409);
        $revision=$body['expected_revision']??null;
        demand((is_int($revision)||is_string($revision)&&ctype_digit($revision))&&
            (int)$revision===(int)$old['terms_revision'],
            'PO terms changed since opening. Close Edit and reopen the latest record.',409);
        $reason=self::field($body,'reason',500);
        // Reuse the full issuance validation against the original award and
        // immutable official PO date (not the current clock or record timestamp).
        $prepared=self::prepare($db,$org,['award_id'=>(int)$po['award_id']]+$body,$old['document_date']);
        $keys=['delivery_date','delivery_place','delivery_term','payment_term','payment_type','procurement_method'];
        $terms=[];$previous=[];foreach($keys as $key){$terms[$key]=$prepared[$key];$previous[$key]=$old[$key];}
        demand($terms!==$previous,'No PO terms have changed',422);
        $original=$old;unset($original['terms_revision']);
        $newRevision=(int)$revision+1;
        $correction=['po_id'=>$poId,'revision'=>$newRevision,
            'previous_sha256'=>hash('sha256',json_encode($original,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),
            'terms'=>$terms,'reason'=>$reason,'actor_id'=>$actor];
        q($db,'INSERT INTO configuration_versions(organization_id,config_key,config_json,effective_from,review_note) VALUES(?,?,?,?,?)',
            [$org,self::CORRECTION_PREFIX.$poId,json_encode($correction,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
            $old['document_date'],'PO documentary terms corrected; revision '.$newRevision]);
        q($db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES(?,?,?,?,?,?)',
            [$org,$actor,'PO_TERMS_CORRECTED','PO',$poId,json_encode(['revision'=>$newRevision,'before'=>$previous,'after'=>$terms,'reason'=>$reason],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
        return ['po_id'=>$poId,'number'=>$old['number'],'revision'=>$newRevision];
    }
    /** Complete numbered-PO documentary correction, while NO IAR exists.
     * The recorded award/supplier and each quoted unit price stay authoritative;
     * ordered quantities, included item rows, PO date, master-linked supplier details,
     * and terms may change. Original snapshots and every correction are retained.
     * Transaction/tenant/role/idempotency checks are enforced by public/api/index.php.
     */
    public static function correctDocument(PDO $db,int $org,int $actor,array $body): array {
        $poId=idOf($body,'po_id');
        // Match PO creation lock order: award first, PO second. IAR creation
        // holds the PO lock, so one operation cannot race past the other.
        $link=one($db,'SELECT award_id FROM purchase_orders WHERE id=? AND organization_id=?',[$poId,$org]);
        demand($link!==null,'PO unavailable in this workspace',404);
        $award=one($db,'SELECT id,status FROM awards WHERE id=? AND organization_id=? FOR UPDATE',[(int)$link['award_id'],$org]);
        demand($award!==null && $award['status']==='FINALIZED','Linked award unavailable',409);
        $po=one($db,'SELECT id,award_id,pr_id,supplier_id,status,display_number FROM purchase_orders WHERE id=? AND organization_id=? FOR UPDATE',[$poId,$org]);
        demand($po!==null && (int)$po['award_id']===(int)$award['id'] && $po['status']==='FINALIZED','PO unavailable for editing',409);
        demand(one($db,'SELECT id FROM iars WHERE po_id=? LIMIT 1',[$poId])===null,
            'PO is locked: an IAR has already been prepared for this PO',409);
        $old=self::read($db,$org,$poId);
        demand($old!==null,'This historical PO cannot be edited without an original verified snapshot',409);
        $revision=$body['expected_revision']??null;
        demand((is_int($revision)||is_string($revision)&&ctype_digit($revision)) &&
            (int)$revision===(int)$old['terms_revision'],
            'Another correction was saved. Reopen Edit PO to load the latest version.',409);
        $date=ProcurementDocumentDates::date($body['document_date']??null,'PO document date');
        demand($date>=self::awardMinDate($db,$org,(int)$po['award_id']),
            'PO date cannot precede the recorded award/resolution date; same-day is allowed',422);
        $terms=self::prepare($db,$org,['award_id'=>(int)$po['award_id']]+$body,$date);
        $reason=trim((string)($body['reason']??''));
        if($reason==='')$reason='Documentary PO correction before IAR';
        demand(mbSafeLen($reason)<=500 && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$reason),'Invalid correction note',422);
        // Never accept supplier contact/identity from the PO client. Even a
        // forged POST must not overwrite printed supplier data: amend master
        // Supplier Profile first, then save a new documentary PO revision.
        foreach(array_keys($body) as $key){
            demand(is_string($key) && $key!=='supplier' && ($key==='supplier_profile_sha256' || !str_starts_with($key,'supplier_')),
                'Supplier information is read-only on a PO. Update Supplier Profile, then reopen and save the corrected PO.',422);
        }
        $originalName=trim((string)($old['supplier']['name']??''));
        demand($originalName!=='' && (int)($old['supplier']['id']??0)===(int)$po['supplier_id'],
            'The original awarded supplier identity must remain verifiable',409);
        // Resolve the current supplier MASTER inside the PO write transaction.
        // The corrected printed version captures these fields and retains the
        // original issued version; old signed paper is never silently rewritten.
        $supplier=self::supplierFromMaster($db,(int)$po['supplier_id'],true);
        $currentSupplierHash=hash('sha256',json_encode($supplier,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        demand(is_string($body['supplier_profile_sha256']??null) &&
            hash_equals($currentSupplierHash,$body['supplier_profile_sha256']),
            'Supplier Profile changed while editing this PO. Reopen Edit PO to review current details.',409);
        foreach(['address'=>500,'tin'=>180,'zip'=>180,'proprietor'=>180] as $field=>$limit){
            demand(mbSafeLen($supplier[$field])<=$limit &&
                !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$supplier[$field]),
                'Supplier Profile '.$field.' must be corrected before preparing this PO',422);
        }
        // Refuse a silent merge over any legacy PO item amendment that was
        // not reconciled into Appendix 61. Existing data is never rewritten
        // on the assumption that it matches the printed source.
        $current=q($db,'SELECT pr_line_id,quantity,unit_price FROM po_lines WHERE po_id=? ORDER BY id FOR UPDATE',[$poId])->fetchAll();
        $saved=[];foreach($old['lines'] as $l)$saved[(int)$l['pr_line_id']]=[
            qtyString(qtyMillis($l['quantity'])),moneyString(moneyCents($l['unit_price']))];
        $live=[];foreach($current as $l)$live[(int)$l['pr_line_id']]=[
            qtyString(qtyMillis($l['quantity'])),moneyString(moneyCents($l['unit_price']))];
        demand($saved==$live,
            'PO item ledger differs from its printed version. Administrator reconciliation is required before editing.',409);
        $options=self::editableLines($db,$org,$poId,$po,$old);
        $eligible=[];foreach($options as $line)$eligible[(int)$line['pr_line_id']]=$line;
        $submitted=rowsRequired($body,'lines');
        demand(count($submitted)<=count($eligible),'Too many PO items for the awarded quotation',422);
        $newLines=[];$seen=[];$total=0;
        foreach($submitted as $incoming){
            demand(is_array($incoming),'Invalid PO line',422);
            $id=idOf($incoming,'pr_line_id');
            demand(!isset($seen[$id]) && isset($eligible[$id]),'Duplicate or non-awarded PO item',422);
            $seen[$id]=true;$valid=$eligible[$id];
            $qty=qtyMillis($incoming['quantity']??'');
            demand($qty>0 && $qty<=qtyMillis($valid['max_quantity']),
                'Ordered quantity exceeds PR/RFQ award balance for '.$valid['description'],422);
            $unitPrice=moneyCents($valid['unit_price']);
            demand($unitPrice>0,'Awarded price is not valid',409);
            // A caller may send the rendered unit price; it must remain equal
            // to the awarded compliant quotation, never freely overwrite it.
            if(array_key_exists('unit_price',$incoming))
                demand(moneyCents($incoming['unit_price'])===$unitPrice,
                    'PO unit cost must match the awarded quotation',422);
            $amount=totalCents($unitPrice,$qty);
            demand($total<=PHP_INT_MAX-$amount,'PO amount too large',422);$total+=$amount;
            $newLines[]=['pr_line_id'=>$id,'item_id'=>$valid['item_id'],
                'description'=>$valid['description'],'unit'=>$valid['unit'],
                'quantity'=>qtyString($qty),'unit_price'=>moneyString($unitPrice)];
        }
        demand(count($newLines)>0,'Include at least one item in this PO',422);
        $head=$old['school_head'];
        if($date!==$old['document_date']){
            require_once __DIR__.'/PrPrint.php';
            $head=ipdamsPrSchoolApprover($db,$org,$date);
        }
        $oldComparable=$old;unset($oldComparable['terms_revision']);
        $newComparable=$oldComparable;
        foreach(['delivery_date','delivery_place','delivery_term','payment_term','payment_type','procurement_method'] as $key)
            $newComparable[$key]=$terms[$key];
        $newComparable['document_date']=$date;$newComparable['supplier']=$supplier;
        $newComparable['school_head']=$head;$newComparable['lines']=$newLines;
        $newComparable['total']=moneyString($total);
        // Include DB IDs in old lines? Original line snapshots include IDs;
        // preserve them for unchanged items, and assign new IDs before the
        // hash-chained correction record is written.
        $before=q($db,'SELECT * FROM po_lines WHERE po_id=? ORDER BY id FOR UPDATE',[$poId])->fetchAll();
        $oldIds=[];foreach($before as $row)$oldIds[(int)$row['pr_line_id']]=(int)$row['id'];
        foreach($newLines as &$line){
            if(isset($oldIds[(int)$line['pr_line_id']]))$line['id']=$oldIds[(int)$line['pr_line_id']];
        }unset($line);
        $newComparable['lines']=$newLines;
        // Check effective normalized content, not row identity differences.
        $oldNormal=array_map(static fn($l)=>[(int)$l['pr_line_id'],qtyString(qtyMillis($l['quantity'])),moneyString(moneyCents($l['unit_price']))],$old['lines']);
        $newNormal=array_map(static fn($l)=>[(int)$l['pr_line_id'],$l['quantity'],$l['unit_price']],$newLines);
        $termsChanged=false;foreach(['delivery_date','delivery_place','delivery_term','payment_term','payment_type','procurement_method'] as $key)
            if($old[$key]!==$terms[$key])$termsChanged=true;
        $changed=$termsChanged||$date!==$old['document_date']||$supplier!==$old['supplier']||
            $head!==$old['school_head']||$oldNormal!==$newNormal;
        demand($changed,'No PO details were changed',422);
        // Reconcile core po_lines for IAR and downstream computations, in this
        // transaction, not merely in the printed overlay.
        $newIds=[];
        foreach($newLines as &$line){
            $prId=(int)$line['pr_line_id'];
            if(isset($oldIds[$prId])){
                $lineId=$oldIds[$prId];
                q($db,'UPDATE po_lines SET quantity=?,unit_price=? WHERE id=? AND po_id=?',
                    [$line['quantity'],$line['unit_price'],$lineId,$poId]);
                $newIds[]=$lineId;
            }else{
                q($db,'INSERT INTO po_lines(po_id,pr_line_id,item_id,quantity,unit_price) VALUES(?,?,?,?,?)',
                    [$poId,$prId,(int)$line['item_id'],$line['quantity'],$line['unit_price']]);
                $line['id']=(int)$db->lastInsertId();$newIds[]=(int)$line['id'];
            }
        }unset($line);
        foreach($before as $oldLine){
            if(!in_array((int)$oldLine['id'],$newIds,true))
                q($db,'DELETE FROM po_lines WHERE id=? AND po_id=?',[(int)$oldLine['id'],$poId]);
        }
        $newComparable['lines']=$newLines;
        // The original issued document_versions row is never modified.
        // Every item correction receives a new version and amendment row.
        $version=one($db,"SELECT COALESCE(MAX(version_no),0) n FROM document_versions WHERE doc_type='PO' AND entity_id=? AND organization_id=?",[$poId,$org]);
        $nextVersion=(int)$version['n']+1;
        q($db,"INSERT INTO document_versions(organization_id,doc_type,entity_id,version_no,snapshot_json,reason,actor_id) VALUES(?,'PO',?,?,?,?,?)",
            [$org,$poId,$nextVersion,json_encode(['number'=>$po['display_number'],'award_id'=>(int)$po['award_id'],
                'date'=>$date,'lines'=>$newLines,'total'=>moneyString($total),'revision'=>(int)$revision+1],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$reason,$actor]);
        $ver=one($db,'SELECT COALESCE(MAX(version_no),0) n FROM po_amendments WHERE po_id=?',[$poId]);
        $amend=(int)$ver['n']+1;
        q($db,'INSERT INTO po_amendments(po_id,version_no,old_json,new_json,reason,actor_id) VALUES(?,?,?,?,?,?)',
            [$poId,$amend,json_encode($before,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
            json_encode($newLines,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$reason,$actor]);
        $revisionNew=(int)$revision+1;
        $correction=['po_id'=>$poId,'revision'=>$revisionNew,
            'previous_sha256'=>hash('sha256',json_encode($oldComparable,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),
            'document_date'=>$date,'terms'=>array_intersect_key($terms,array_flip(['delivery_date','delivery_place','delivery_term','payment_term','payment_type','procurement_method'])),
            'supplier'=>$supplier,'school_head'=>$head,'lines'=>$newLines,'total'=>moneyString($total),
            'reason'=>$reason,'actor_id'=>$actor,'document_version_no'=>$nextVersion];
        q($db,'INSERT INTO configuration_versions(organization_id,config_key,config_json,effective_from,review_note) VALUES(?,?,?,?,?)',
            [$org,self::FULL_CORRECTION_PREFIX.$poId,json_encode($correction,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
                $date,'Complete documentary PO correction before IAR; revision '.$revisionNew]);
        q($db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES(?,?,?,?,?,?)',
            [$org,$actor,'PO_DOCUMENT_CORRECTED','PO',$poId,json_encode(['revision'=>$revisionNew,
                'document_date_before'=>$old['document_date'],'document_date_after'=>$date,
                'old_total'=>$old['total'],'new_total'=>moneyString($total),
                'old_lines'=>$oldNormal,'new_lines'=>$newNormal,'supplier_refreshed_from_master'=>$supplier!==$old['supplier'],'reason'=>$reason,
                'locked_after_first_iar'=>true],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
        return ['po_id'=>$poId,'number'=>$po['display_number'],'revision'=>$revisionNew,'total'=>moneyString($total)];
    }
    private static function words(int $n): string {
        if($n===0)return 'Zero';
        $ones=['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
        $tens=['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
        if($n<20)return $ones[$n];
        if($n<100)return $tens[intdiv($n,10)].($n%10?' '.self::words($n%10):'');
        if($n<1000)return self::words(intdiv($n,100)).' Hundred'.($n%100?' '.self::words($n%100):'');
        foreach([1000000000000=>'Trillion',1000000000=>'Billion',1000000=>'Million',1000=>'Thousand'] as $value=>$label){
            if($n>=$value)return self::words(intdiv($n,$value)).' '.$label.($n%$value?' '.self::words($n%$value):'');
        }
        return '';
    }
    public static function render(array $d): string {
        $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $s=$d['supplier']??[];$org=$d['organization']??[];$head=$d['school_head']??[];
        $items=$d['lines']??[];demand(is_array($items)&&count($items)>0,'PO lines unavailable',409);
        $rows='';$sum=0;
        foreach($items as $i=>$line){
            $qty=qtyMillis($line['quantity']??'');$price=moneyCents($line['unit_price']??'');
            demand($qty>0&&$price>0,'PO line data invalid',409);
            $amt=totalCents($price,$qty);demand($sum<=PHP_INT_MAX-$amt,'PO total overflow',409);$sum+=$amt;
            $rows.='<tr><td class="c">'.($i+1).'</td><td class="c">'.$e($line['unit']??'').'</td><td>'.$e($line['description']??'').'</td><td class="n">'.$e(qtyString($qty)).'</td><td class="n">'.number_format($price/100,2).'</td><td class="n">'.number_format($amt/100,2).'</td></tr>';
        }
        demand(moneyCents($d['total']??'')===$sum,'PO recorded total mismatch',409);
        $formatDate=static function($v):string { if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$v))return '';
            return substr($v,5,2).'/'.substr($v,8,2).'/'.substr($v,0,4);};
        $peso=self::words(intdiv($sum,100)).' Pesos and '.sprintf('%02d',$sum%100).'/100 Only';
        $original=$e($d['number']??'');$historic=isset($d['legacy']);
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Purchase Order '.$original.'</title><style>'
        .'@page{size:A4 portrait;margin:11mm 11mm}*{box-sizing:border-box}body{font:11px "Bookman Old Style",Georgia,serif;color:#000;margin:15px auto;max-width:794px;padding:0 12px}.toolbar{text-align:right;margin-bottom:9px}.toolbar button{padding:5px 12px}.appendix{text-align:right;font-style:italic;font-size:10px}.heading{text-align:center;margin:16px auto 18px;line-height:1.35}.heading h1{font-size:16px;margin:0 0 3px}.heading p{margin:1px}.po{border-collapse:collapse;width:100%;table-layout:fixed}.po td,.po th{border:1px solid #000;padding:3px 4px;vertical-align:top;overflow-wrap:anywhere}.po th{font-weight:700;text-align:center}.po .c{text-align:center}.po .n{text-align:right;font-variant-numeric:tabular-nums}.po .label{font-weight:700}.po .headercell{height:107px}.identity{width:100%;border-collapse:collapse}.identity td{border:0;padding:0 3px 2px 0}.identity .key{width:93px;vertical-align:top}.identity .value{font-weight:bold}.narrow{font-size:10px}.po .paragraph{text-align:center;padding:4px}.po .subrow{height:22px}.po .totalcell{font-weight:700}.terms{width:100%;border-collapse:collapse}.terms td{border:0;padding:1px 3px;vertical-align:top}.po .signcell{height:145px;vertical-align:bottom;text-align:center;padding:10px}.sign-top{margin:0 0 38px}.signed-name{font-weight:700;min-height:20px}.sign-role{font-size:9px;font-style:italic}.sign-date{border-top:1px solid #000;max-width:160px;margin:10px auto 0;padding-top:3px}.fundbox{margin-top:5px}.fundbox td{height:105px}.fundbox .fund-title{font-weight:700;text-align:left}.fundbox .bottom-name{text-align:center;margin-top:36px;font-weight:700}.fundbox .bottom-role{text-align:center;font-size:9px}.note{font:10px Arial,sans-serif;color:#555;margin:8px 0}@media print{body{margin:0;padding:0;max-width:none}.toolbar,.note{display:none}.po tr,.po .headercell,.po .signcell{break-inside:avoid}.po thead{display:table-header-group}}'
        .'</style></head><body><div class="toolbar"><button onclick="window.print()">Print</button></div>'
        .'<div class="appendix">Appendix 61</div><div class="heading"><h1>PURCHASE ORDER</h1><p>DEPARTMENT OF EDUCATION</p><p>'.$e($org['name']??'').'</p><p>'.$e($org['address']??'').'</p></div>'
        .'<table class="po"><colgroup><col style="width:14%"><col style="width:10%"><col style="width:31%"><col style="width:13%"><col style="width:16%"><col style="width:16%"></colgroup><tbody>'
        .'<tr><td class="headercell" colspan="3"><table class="identity"><tr><td class="key">Supplier:</td><td class="value">'.$e($s['name']??'').'</td></tr><tr><td class="key">Address:</td><td>'.$e($s['address']??'').'</td></tr><tr><td class="key">TIN:</td><td>'.$e($s['tin']??'').'</td></tr></table></td>'
        .'<td class="headercell" colspan="3"><table class="identity"><tr><td class="key">P.O. No.:</td><td class="value">'.$original.'</td></tr><tr><td class="key">Date:</td><td class="value">'.$e($formatDate($d['document_date']??'')).'</td></tr><tr><td class="key">Mode of<br>Procurement:</td><td class="value">'.$e($d['procurement_method']??'').'</td></tr></table></td></tr>'
        .'<tr><td colspan="6">Gentlemen:<div class="paragraph">Please furnish this Office the following articles subject to the terms and conditions contained herein:</div></td></tr>'
        .'<tr><td colspan="3" class="narrow">Place of Delivery : <strong>'.$e($d['delivery_place']??'').'</strong></td><td colspan="3" class="narrow">Delivery Term : <strong>'.$e($d['delivery_term']??'').'</strong></td></tr>'
        .'<tr><td colspan="3" class="narrow">Date of Delivery : <strong>'.$e($formatDate($d['delivery_date']??'')).'</strong></td><td colspan="3" class="narrow">Payment Term : <strong>'.$e($d['payment_term']??'').'</strong></td></tr>'
        .'<tr><td colspan="3" class="narrow">&nbsp;</td><td colspan="3" class="narrow">Payment Type : <strong>'.$e($d['payment_type']??'').'</strong></td></tr>'
        .'<tr><th>Stock/ Property No.</th><th>Unit</th><th>Description</th><th>Quantity</th><th>Unit Cost</th><th>Amount</th></tr>'
        .$rows.'<tr><td colspan="5" class="n">Total</td><td class="n">'.number_format($sum/100,2).'</td></tr>'
        .'<tr><td colspan="5" class="n narrow"><em>Less: Discount</em></td><td class="n">&nbsp;</td></tr>'
        .'<tr><td colspan="5" class="n totalcell">Total</td><td class="n totalcell">'.number_format($sum/100,2).'</td></tr>'
        .'<tr><td colspan="2" class="narrow">(Total Amount in Words)</td><td colspan="4" class="c totalcell">'.$e($peso).'</td></tr>'
        .'<tr><td colspan="3" class="signcell"><div class="sign-top">Conforme:</div><div class="signed-name">'.$e($s['name']??'').'</div><div class="sign-role">Signature over Printed Name of Supplier</div><div class="sign-date">Date</div></td>'
        .'<td colspan="3" class="signcell"><div class="sign-top">Very truly yours,</div><div class="signed-name">'.$e($head['name']??'').'</div><div class="sign-role">Signature over Printed Name</div><div>'.$e($head['designation']??'').'</div><div class="narrow">Designation</div></td></tr>'
        .'</tbody></table><table class="po fundbox"><tbody><tr><td style="width:55%"><div class="fund-title">Fund Cluster : </div><div class="fund-title">Funds Available : </div><div class="bottom-name">'.$e($head['name']??'').' </div><div class="bottom-role">Signature over Printed Name of School Fund In-Charge</div></td>'
        .'<td><div><strong>ORS/BURS No. :</strong> __________________</div><div><strong>Date of the ORS/BURS:</strong> __________</div><div style="margin-top:14px"><strong>Amount :</strong> &nbsp;__________</div></td></tr></tbody></table>'
        .($historic?'<div class="note">Historical PO predates the Appendix 61 metadata capture; unsupported source details are blank.</div>':'')
        .'</body></html>';
    }
    public static function load(PDO $db,int $org,int $id): array {
        $po=one($db,'SELECT id,status,display_number FROM purchase_orders WHERE organization_id=? AND id=?',[$org,$id]);
        demand($po!==null&&$po['status']==='FINALIZED','Active PO unavailable',404);
        $meta=self::read($db,$org,$id);
        if($meta)return $meta;
        // Legacy POs: preserve valid quantities and prices, but never silently
        // substitute current supplier data or office-holder names for historical facts.
        $lines=q($db,'SELECT l.quantity,l.unit_price,p.description_snapshot description,p.unit_snapshot unit FROM po_lines l JOIN pr_lines p ON p.id=l.pr_line_id WHERE l.po_id=? ORDER BY l.id',[$id])->fetchAll();
        $total=0;foreach($lines as $line)$total+=totalCents(moneyCents($line['unit_price']),qtyMillis($line['quantity']));
        $date=ProcurementDocumentDates::poDate($db,$org,$id);
        return ['legacy'=>true,'number'=>$po['display_number'],'document_date'=>$date['source']==='SELECTED_DOCUMENT_DATE'?$date['date']:'',
            'supplier'=>[],'organization'=>[],'school_head'=>[],'lines'=>$lines,'total'=>moneyString($total),
            'delivery_place'=>'','delivery_date'=>'','delivery_term'=>'','payment_term'=>'','payment_type'=>'','procurement_method'=>''];
    }
}
