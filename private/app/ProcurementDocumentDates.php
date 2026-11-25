<?php
declare(strict_types=1);
/**
 * Version-linked documentary chronology for procurement.
 * Distinct from event/audit timestamps; operator dates must not predate their
 * source forms. Does not authorize, approve or modify procurement amounts.
 */
final class ProcurementDocumentDates {
    private const PO_PREFIX='PO_DOCUMENT_DATE_V1_';
    public static function date(mixed $value,string $label): string {
        demand(is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)===1,
            'Select a valid '.$label.' (YYYY-MM-DD)',422);
        [$y,$m,$d]=array_map('intval',explode('-',$value));
        demand($y>=1900&&$y<=2100&&checkdate($m,$d,$y),'Invalid '.$label,422);
        $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        demand($value<=$today,$label.' cannot be in the future',422);
        return $value;
    }
    private static function ordered(string $date,string $prior,string $label,string $source): void {
        demand(self::date($prior,$source)<=$date,$label.' cannot be earlier than the '.$source.' ('.$prior.'). Same-day dates are allowed.',422);
    }
    private static function sourceRegistry(PDO $db,int $org,string $type,int $id): string {
        $row=one($db,'SELECT created_at FROM document_registry WHERE organization_id=? AND doc_type=? AND entity_id=?',[$org,$type,$id]);
        demand($row!==null,'Source '.$type.' registry entry unavailable; review document sequence',409);
        return self::date(substr((string)$row['created_at'],0,10),'source '.$type.' recorded date');
    }
    /** Safely resolves new PO's selected document date; historical POs use a clearly labelled lower-bound only. */
    public static function poDate(PDO $db,int $org,int $poId): array {
        $rows=q($db,'SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 2',
            [$org,self::PO_PREFIX.$poId])->fetchAll();
        demand(count($rows)<=1,'Conflicting Purchase Order documentary dates',409);
        if(!$rows)return ['date'=>self::sourceRegistry($db,$org,'PO',$poId),'source'=>'RECORDED_ONLY'];
        $data=json_decode((string)$rows[0]['config_json'],true,32,JSON_THROW_ON_ERROR);
        demand(is_array($data)&&(int)($data['po_id']??0)===$poId,'PO documentary source requires review',409);
        $version=one($db,"SELECT snapshot_json FROM document_versions WHERE organization_id=? AND doc_type='PO' AND entity_id=? AND id=?",
            [$org,$poId,(int)($data['source_version_id']??0)]);
        demand($version!==null&&is_string($data['source_sha256']??null)&&hash_equals((string)$data['source_sha256'],hash('sha256',(string)$version['snapshot_json'])),
            'PO documentary date source integrity check failed',409);
        $original=self::date($data['document_date']??null,'Purchase Order date');
        // Corrections are immutable version overlays, not edits to the
        // issued date evidence. Read only a verified Appendix 61 correction
        // chain, so every downstream IAR date uses the effective printed PO.
        if(class_exists('PoDocuments',false)){
            $doc=PoDocuments::read($db,$org,$poId);
            if($doc!==null){
                $effective=self::date($doc['document_date']??null,'corrected Purchase Order date');
                return ['date'=>$effective,'source'=>$effective===$original?'SELECTED_DOCUMENT_DATE':'CORRECTED_DOCUMENT_DATE'];
            }
        }
        return ['date'=>$original,'source'=>'SELECTED_DOCUMENT_DATE'];
    }
    /** Within the same HTTP issuance transaction, before consuming a number. */
    public static function before(PDO $db,int $org,string $route,array $body): ?array {
        if($route==='rfq.create'||$route==='rfq.update'){
            $rfq=$route==='rfq.update' ? one($db,'SELECT pr_id FROM rfqs WHERE id=? AND organization_id=?',[idOf($body,'rfq_id'),$org]) : null;
            demand($route==='rfq.create'||$rfq!==null,'RFQ not found',404);
            $prId=$route==='rfq.create'?idOf($body,'pr_id'):(int)$rfq['pr_id'];
            $pr=one($db,'SELECT document_date FROM purchase_requests WHERE id=? AND organization_id=? AND status=?',[$prId,$org,'FINALIZED']);
            demand($pr!==null,'Finalized source PR unavailable',404);
            $day=self::date($body['issued_at']??$pr['document_date'],'RFQ date');
            self::ordered($day,(string)$pr['document_date'],'RFQ date','PR date');
        }
        if($route==='quote.create'){
            $rfq=one($db,'SELECT issued_at FROM rfqs WHERE id=? AND organization_id=? AND status=?',
                [idOf($body,'rfq_id'),$org,'FINALIZED']);
            demand($rfq!==null,'Finalized source RFQ unavailable',404);
            $day=self::date($body['offered_at']??null,'supplier quotation date');
            self::ordered($day,substr((string)$rfq['issued_at'],0,10),'Supplier quotation date','RFQ date');
            return ['date'=>$day];
        }
        if($route==='po.create'){
            $award=one($db,'SELECT id,rfq_id FROM awards WHERE id=? AND organization_id=? AND status=?',
                [idOf($body,'award_id'),$org,'FINALIZED']);
            demand($award!==null,'Finalized source Award unavailable',404);
            $day=self::date($body['document_date']??null,'Purchase Order date');
            $meta=AwardDocuments::read($db,$org,(int)$award['id']);
            $source=$meta?self::date($meta['resolution_date'],'BAC Resolution date'):self::sourceRegistry($db,$org,'AWARD',(int)$award['id']);
            self::ordered($day,$source,'Purchase Order date',$meta?'BAC Resolution date':'historical Award recorded date');
            return ['date'=>$day,'award_id'=>(int)$award['id'],'source_date'=>$source,'source_kind'=>$meta?'RESOLUTION_DATE':'RECORDED_ONLY'];
        }
        if($route==='iar.create'){
            $po=one($db,'SELECT id FROM purchase_orders WHERE id=? AND organization_id=? AND status=?',
                [idOf($body,'po_id'),$org,'FINALIZED']);
            demand($po!==null,'Finalized source PO unavailable',404);
            $min=self::poDate($db,$org,(int)$po['id']);
            $delivery=self::date($body['delivered_at']??null,'delivery date');
            $acceptance=self::date($body['accepted_at']??null,'inspection/acceptance date');
            self::ordered($delivery,$min['date'],'Delivery date', in_array($min['source'],['SELECTED_DOCUMENT_DATE','CORRECTED_DOCUMENT_DATE'],true)?'PO date':'historical PO recorded date');
            self::ordered($acceptance,$delivery,'Inspection/acceptance date','delivery date');
        }
        return null;
    }
    /** Must run after create, before idempotency response commit. */
    public static function after(PDO $db,int $org,int $actor,string $route,array $result,?array $prepared): void {
        $id=(int)($result['id']??0);
        if($route==='quote.create'){
            demand($id>0&&is_array($prepared),'Quotation date not captured',500);
            q($db,'UPDATE supplier_quotes SET offered_at=? WHERE id=? AND rfq_id IN (SELECT id FROM rfqs WHERE organization_id=?)',
                [$prepared['date'],$id,$org]);
            // MySQL may report zero affected rows if the selected date is today
            // (the default inserted value), even though the row is correct.
            $saved=one($db,'SELECT q.offered_at FROM supplier_quotes q JOIN rfqs r ON r.id=q.rfq_id WHERE q.id=? AND r.organization_id=?',[$id,$org]);
            demand($saved!==null && substr((string)$saved['offered_at'],0,10)===$prepared['date'],
                'Supplier quotation date was not recorded',500);
            q($db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES(?,?,?,?,?,?)',
                [$org,$actor,'QUOTE_DOCUMENT_DATE_RECORDED','QUOTE',$id,json_encode(['offered_at'=>$prepared['date']],JSON_THROW_ON_ERROR)]);
        }
        if($route==='po.create'){
            demand($id>0&&is_array($prepared),'Purchase Order documentary date not captured',500);
            $v=one($db,"SELECT id,snapshot_json FROM document_versions WHERE organization_id=? AND doc_type='PO' AND entity_id=? ORDER BY version_no DESC LIMIT 1",[$org,$id]);
            demand($v!==null,'PO snapshot missing',500);
            $json=json_encode(['po_id'=>$id,'document_date'=>$prepared['date'],'award_id'=>$prepared['award_id'],
                'preceding_date'=>$prepared['source_date'],'preceding_source'=>$prepared['source_kind'],
                'source_version_id'=>(int)$v['id'],'source_sha256'=>hash('sha256',(string)$v['snapshot_json'])],JSON_THROW_ON_ERROR);
            q($db,'INSERT INTO configuration_versions(organization_id,config_key,config_json,effective_from,review_note) VALUES(?,?,?,?,?)',
                [$org,self::PO_PREFIX.$id,$json,$prepared['date'],'Purchase Order document date recorded at issuance']);
            q($db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES(?,?,?,?,?,?)',
                [$org,$actor,'PO_DOCUMENT_DATE_RECORDED','PO',$id,json_encode(['document_date'=>$prepared['date'],'source'=>$prepared['source_kind']],JSON_THROW_ON_ERROR)]);
        }
    }
    public static function decoratePO(PDO $db,int $org,array $rows): array {
        foreach($rows as &$row){
            $info=self::poDate($db,$org,(int)$row['id']);
            $row['document_date']=$info['date'];
            $row['date_source']=$info['source'];
        }
        return $rows;
    }
}
