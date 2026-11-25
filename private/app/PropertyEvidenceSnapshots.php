<?php
declare(strict_types=1);
/**
 * Phase 3B — transaction-bound, append-only DOCUMENTARY EVIDENCE sidecars.
 * Does NOT change authoritative document_versions, account mapping, payments,
 * inventory, or property tags. Not an accounting decision or wet signature.
 *
 * A sidecar is anchored to (organization,doc type,id,version, SHA256(raw snapshot_json)).
 * It is written AFTER the existing Domain action, BEFORE the outer API commit.
 * A failed database write rolls the whole issuance back. Missing classification
 * references are captured as REVIEW_REQUIRED, not approved/guessed.
 */
final class PropertyEvidenceSnapshots {
    public function __construct(
        private PDO $db,
        private array $user,
        private PrClassificationPreview $preview
    ) {}

    private static function key(string $type,int $entityId,int $version): string {
        demand(in_array($type,['PR','PO','IAR'],true),'Unsupported document evidence type',400);
        demand($entityId>0 && $version>0,'Invalid source document version',409);
        $key='DOC_PROPERTY_EVIDENCE_V1_'.$type.'_'.$entityId.'_'.$version;
        demand(strlen($key)<=80,'Document evidence key too long',409);
        return $key;
    }
    private static function today(): string {
        return (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    }
    public static function band(string $price,string $asOf): string {
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$asOf)||$asOf<'2022-01-01')return 'HISTORICAL_POLICY_REVIEW';
        if(!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D',$price))return 'COST_REVIEW_REQUIRED';
        $cents=moneyCents($price);
        return $cents>=5000000?'PPE_THRESHOLD':($cents>500000?'HIGH_VALUED_SEMI_EXPENDABLE':'LOW_VALUED_SEMI_EXPENDABLE');
    }
    private static function lineEvidence(array $source,array $effective): array {
        $category=(string)($source['catalog_category']??'');
        $itemPrice=(string)($source['estimated_unit_price']??'');
        $p=[
            'pr_line_id'=>(int)($source['pr_line_id']??0),
            'item_id'=>(int)($source['item_id']??0),
            'catalog_category'=>$category,
            'unit_price_recorded'=>$itemPrice,
            'cost_band'=>(string)($effective['cost_band']??'UNVERIFIED'),
            'division_property_type'=>$effective['division_property_type']??null,
            'registry_link_version_id'=>(int)($effective['registry_link_version_id']??0),
            'uacs_code'=>(string)($effective['uacs_code']??''),
            'uacs_effective_from'=>(string)($effective['uacs_effective_from']??''),
            'uacs_mapping_version_id'=>(int)($effective['uacs_mapping_version_id']??0),
            'mapping_classification'=>(string)($effective['mapping_classification']??''),
            'review_required'=>(bool)($effective['review_required']??true),
            'warnings'=>array_values(array_map('strval',$effective['warnings']??[])),
            'decision_status'=>'DOCUMENTARY_PREVIEW_NOT_APPROVED'
        ];
        return $p;
    }
    /** PO and IAR carry the ORIGINAL bound PR reference, not today's lookup. */
    public static function inherit(array $prior,string $currentPrice,string $asOf): array {
        $sourceBand=(string)($prior['cost_band']??'UNVERIFIED');
        $currentBand=self::band($currentPrice,$asOf);
        $warnings=is_array($prior['warnings']??null)?$prior['warnings']:[];
        if(in_array((string)($prior['catalog_category']??''),['PPE','SEMI_EXPENDABLE'],true)
            && $sourceBand!==$currentBand){
            $warnings[]='Price bracket differs from earlier document; an authorized officer must review actual acquisition cost.';
        }
        if((string)($prior['uacs_code']??'')==='')$warnings[]='No verified historical UACS reference available; do not infer one from current settings.';
        $result=$prior;
        $result['unit_price_recorded']=$currentPrice;
        $result['cost_band_at_this_stage']=$currentBand;
        $result['review_required']=(bool)($prior['review_required']??true) || count($warnings)>count($prior['warnings']??[]);
        $result['warnings']=array_values(array_unique(array_map('strval',$warnings)));
        $result['decision_status']='CARRIED_REFERENCE_NOT_APPROVED';
        return $result;
    }
    /** Refuse to bind evidence to a document whose source snapshot disagrees with
     * the saved line rows. This intentionally validates only immutable facts,
     * never treats an accounting preview as a signed determination.
     */
    public static function assertLineSnapshot(string $type,array $snapshot,array $rows): void {
        $original=$snapshot['lines']??null;
        demand(is_array($original) && count($original)===count($rows),
            'Document line snapshot count differs from recorded lines; manual audit required',409);
        $idKey=match($type){'PR'=>'id','PO'=>'id','IAR'=>'id',default=>throw new DomainError('Invalid type',409)};
        $priceKey=match($type){'PR'=>'estimated_unit_price','PO'=>'unit_price','IAR'=>'accepted_amount'};
        $byId=[];
        foreach($original as $v){
            demand(is_array($v) && (int)($v[$idKey]??0)>0,'Source line has no stable ID',409);
            $id=(int)$v[$idKey];
            demand(!isset($byId[$id]),'Duplicate document line identity',409);
            $byId[$id]=$v;
        }
        foreach($rows as $r){
            $id=(int)($r[$idKey]??0);$v=$byId[$id]??null;
            demand($v!==null,'Source document line is missing from immutable snapshot',409);
            demand((int)($v[$type==='PR'?'item_id':($type==='PO'?'pr_line_id':'po_line_id')]??0)
                ===(int)($r[$type==='PR'?'item_id':($type==='PO'?'pr_line_id':'po_line_id')]??0),
                'Recorded document line origin differs from its snapshot',409);
            demand(moneyCents((string)($v[$priceKey]??''))===moneyCents((string)($r[$priceKey]??'')),
                'Recorded line monetary amount differs from document snapshot',409);
        }
    }
    /** No retrospective backfill of legacy records; they remain honestly unverifiable. */
    private static function unverified(int $itemId,string $price): array {
        return ['item_id'=>$itemId,'catalog_category'=>'','unit_price_recorded'=>$price,
            'cost_band'=>'UNVERIFIED','division_property_type'=>null,'registry_link_version_id'=>0,
            'uacs_code'=>'','uacs_effective_from'=>'','uacs_mapping_version_id'=>0,
            'mapping_classification'=>'','review_required'=>true,
            'warnings'=>['An earlier linked document has no immutable property/UACS evidence. Historical approval cannot be reconstructed.'],
            'decision_status'=>'LEGACY_UNVERIFIED'];
    }
    private function stored(int $org,string $type,int $id,int $version,bool $locking=false): ?array {
        $sql='SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1';
        // Current read after locking the document version, even under REPEATABLE READ.
        // Otherwise concurrent finalizations may append two evidence rows for one version.
        $row=one($this->db,$sql.($locking?' FOR UPDATE':''),[$org,self::key($type,$id,$version)]);
        if($row===null)return null;
        $data=json_decode((string)$row['config_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($data) && ($data['schema']??'')==='DOCUMENTARY_PROPERTY_EVIDENCE_V1',
            'Recorded property evidence malformed; manual review required',409);
        return $data;
    }
    private function latest(int $org,string $type,int $id,bool $locking=false): array {
        $sql='SELECT id,version_no,snapshot_json,created_at FROM document_versions WHERE organization_id=? AND doc_type=? AND entity_id=? ORDER BY version_no DESC LIMIT 1';
        $row=one($this->db,$sql.($locking?' FOR UPDATE':''),[$org,$type,$id]);
        demand($row!==null,'Document version missing; cannot record immutable evidence',409);
        $json=(string)$row['snapshot_json'];
        $body=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        demand(is_array($body),'Recorded document snapshot is malformed',409);
        return ['version_id'=>(int)$row['id'],'version_no'=>(int)$row['version_no'],
            'json_sha256'=>hash('sha256',$json),'snapshot'=>$body];
    }
    private function prior(int $org,string $type,int $id): ?array {
        $v=$this->latest($org,$type,$id);
        $e=$this->stored($org,$type,$id,$v['version_no']);
        if($e!==null)demand(($e['document_snapshot_sha256']??'')===$v['json_sha256'],
            'Historical document evidence does not match its source version',409);
        return $e;
    }
    public function capture(string $route,array $request,array $response): ?array {
        demand($this->db->inTransaction(),'Property evidence must be recorded inside the issuance transaction',500);
        $type=match($route){'pr.finalize','pr.update'=>'PR','po.create','po.amend','po.document.correct'=>'PO','iar.create'=>'IAR',default=>null};
        if($type===null)return null;
        if($route==='pr.update' && (string)($response['status']??'')!=='FINALIZED')return null;
        $org=idOf($request,'organization_id');scope($this->db,$this->user,$org);
        $id=match($route){'pr.update','pr.finalize','po.create','iar.create'=>(int)($response['id']??0),
            'po.amend','po.document.correct'=>(int)($response['po_id']??0)};
        demand($id>0,'Created document ID missing; unable to anchor evidence',409);
        $record=match($type){
            'PR'=>one($this->db,'SELECT id,document_date,status FROM purchase_requests WHERE id=? AND organization_id=?',[$id,$org]),
            'PO'=>one($this->db,'SELECT id,pr_id,status FROM purchase_orders WHERE id=? AND organization_id=?',[$id,$org]),
            'IAR'=>one($this->db,'SELECT id,po_id,accepted_at,status FROM iars WHERE id=? AND organization_id=?',[$id,$org])
        };
        demand($record!==null && $record['status']==='FINALIZED','Only a finalized document may have issuance evidence',409);
        // Lock the exact authoritative document version before checking the sidecar.
        // Combined with a FOR UPDATE on config, this serializes same-version evidence.
        $latest=$this->latest($org,$type,$id,true);
        $existing=$this->stored($org,$type,$id,$latest['version_no'],true);
        if($existing!==null){
            demand($existing['document_snapshot_sha256']===$latest['json_sha256'],
                'Evidence collision: document snapshot changed; manual audit required',409);
            return $existing;
        }
        $asOf=$type==='PR'?(string)$record['document_date']:($type==='IAR'?(string)$record['accepted_at']:self::today());
        $lines=[];
        if($type==='PR'){
            $rows=q($this->db,'SELECT l.id AS pr_line_id,l.item_id,l.quantity,l.estimated_unit_price FROM pr_lines l WHERE l.pr_id=? ORDER BY l.id',[$id])->fetchAll();
            demand(count($rows)>0 && count($rows)<=120,'PR lines outside supported evidence limit',409);
            foreach($rows as $line){
                try {
                    $v=$this->preview->preview(['organization_id'=>$org,'item_id'=>$line['item_id'],
                        'unit_price'=>$line['estimated_unit_price'],'as_of'=>$asOf]);
                }catch(DomainError $e){
                    // Expected business-validation failure is documented, not
                    // promoted to authoritative historic classification.
                    if($e->status>=500)throw $e;
                    $v=['cost_band'=>'UNVERIFIED','review_required'=>true,'warnings'=>['Unable to verify effective classification and UACS for this PR line.']];
                }
                $lines[]=self::lineEvidence(['pr_line_id'=>$line['pr_line_id'],'item_id'=>$line['item_id'],
                    'estimated_unit_price'=>$line['estimated_unit_price'],'catalog_category'=>$v['catalog_category']??''], $v);
            }
        }elseif($type==='PO'){
            $source=$this->prior($org,'PR',(int)$record['pr_id']);
            $map=[];foreach(($source['lines']??[]) as $line)$map[(int)($line['pr_line_id']??0)]=$line;
            $rows=q($this->db,'SELECT id AS po_line_id,pr_line_id,item_id,quantity,unit_price FROM po_lines WHERE po_id=? ORDER BY id',[$id])->fetchAll();
            demand(count($rows)>0 && count($rows)<=120,'PO lines outside supported evidence limit',409);
            foreach($rows as $line){
                $p=$map[(int)$line['pr_line_id']]??self::unverified((int)$line['item_id'],(string)$line['unit_price']);
                $l=self::inherit($p,(string)$line['unit_price'],$asOf);
                $l['po_line_id']=(int)$line['po_line_id'];$l['pr_line_id']=(int)$line['pr_line_id'];
                $l['item_id']=(int)$line['item_id'];$l['quantity_ordered']=(string)$line['quantity'];
                if((int)($p['item_id']??0)!==(int)$line['item_id']){
                    $l['warnings'][]='Item ID differs from the source PR; verify recorded source links.';$l['review_required']=true;
                }
                $lines[]=$l;
            }
        }else{
            $source=$this->prior($org,'PO',(int)$record['po_id']);
            $map=[];foreach(($source['lines']??[]) as $line)$map[(int)($line['po_line_id']??0)]=$line;
            $rows=q($this->db,'SELECT id AS iar_line_id,po_line_id,delivered_qty,accepted_qty,accepted_amount FROM iar_lines WHERE iar_id=? ORDER BY id',[$id])->fetchAll();
            demand(count($rows)>0 && count($rows)<=120,'IAR lines outside supported evidence limit',409);
            foreach($rows as $line){
                $p=$map[(int)$line['po_line_id']]??self::unverified(0,'0.00');
                $l=$p;$l['iar_line_id']=(int)$line['iar_line_id'];$l['po_line_id']=(int)$line['po_line_id'];
                $l['accepted_qty']=(string)$line['accepted_qty'];$l['accepted_amount']=(string)$line['accepted_amount'];
                $l['receipt_cost_basis']='PO_UNIT_PRICE_ONLY__ACTUAL_ACQUISITION_COST_NOT_VERIFIED';
                $l['decision_status']='RECEIPT_EVIDENCE_NOT_ACCOUNTING_APPROVAL';
                $l['review_required']=true;
                $l['warnings']=array_values(array_unique(array_merge($l['warnings']??[],[
                    'Actual acquisition cost and property-accountability classification require human review at receipt.'])));
                $lines[]=$l;
            }
        }
        // Bind this sidecar to the exact original source-document line version.
        // The real snapshot includes the original identities and recorded values.
        $sourceRows=match($type){
            'PR'=>q($this->db,'SELECT id,item_id,estimated_unit_price FROM pr_lines WHERE pr_id=? ORDER BY id',[$id])->fetchAll(),
            'PO'=>q($this->db,'SELECT id,pr_line_id,unit_price FROM po_lines WHERE po_id=? ORDER BY id',[$id])->fetchAll(),
            'IAR'=>q($this->db,'SELECT id,po_line_id,accepted_amount FROM iar_lines WHERE iar_id=? ORDER BY id',[$id])->fetchAll()
        };
        self::assertLineSnapshot($type,$latest['snapshot'],$sourceRows);
        $data=['schema'=>'DOCUMENTARY_PROPERTY_EVIDENCE_V1','document_type'=>$type,
            'organization_id'=>$org,'document_id'=>$id,'document_version'=>$latest['version_no'],
            'document_version_id'=>$latest['version_id'],
            'document_snapshot_sha256'=>$latest['json_sha256'],
            'as_of'=>$asOf,'lines'=>$lines,
            'review_required'=>array_reduce($lines,fn(bool $carry,array $l):bool=>$carry||(bool)($l['review_required']??true),false),
            'status'=>'RECORDED_FOR_MANUAL_VERIFICATION',
            'notice'=>'Documentation-only immutable evidence. No electronic approval, accounting posting, or property-tag creation. Wet signatures remain required.'];
        q($this->db,'INSERT INTO configuration_versions (organization_id,config_key,config_json,effective_from,review_note) VALUES (?,?,?,?,?)',
            [$org,self::key($type,$id,$latest['version_no']),json_encode($data,JSON_THROW_ON_ERROR),self::today(),'Automatic documentary reference snapshot at document creation; NOT an accounting approval']);
        q($this->db,'INSERT INTO audit_events (organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
            [$org,(int)$this->user['id'],'PROPERTY_CONTEXT_CAPTURED',$type,$id,
                json_encode(['document_version'=>$latest['version_no'],'sha256'=>$latest['json_sha256'],
                    'review_required'=>$data['review_required']],JSON_THROW_ON_ERROR)]);
        return $data;
    }
    public function read(array $p): array {
        $org=idOf($p,'organization_id');scope($this->db,$this->user,$org);
        $type=strtoupper((string)($p['doc_type']??''));
        demand(in_array($type,['PR','PO','IAR'],true),'Invalid evidence document type',400);
        $id=idOf($p,'doc_id');$tables=['PR'=>'purchase_requests','PO'=>'purchase_orders','IAR'=>'iars'];
        $r=one($this->db,'SELECT id FROM '.$tables[$type].' WHERE id=? AND organization_id=?',[$id,$org]);
        demand($r!==null,'Document not found within the selected workspace',404);
        $latest=$this->latest($org,$type,$id);
        $saved=$this->stored($org,$type,$id,$latest['version_no']);
        if($saved===null)return ['document_type'=>$type,'document_id'=>$id,'status'=>'HISTORICAL_REFERENCE_NOT_RECORDED',
            'review_required'=>true,'notice'=>'No classification reference was recorded with this historical document. Do not reconstruct approval from current settings.'];
        demand($saved['document_snapshot_sha256']===$latest['json_sha256'],
            'Document snapshot and reference evidence do not match; manual audit required',409);
        return $saved;
    }
}
