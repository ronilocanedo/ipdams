<?php
declare(strict_types=1);
/**
 * IPDAMS v0.2.339 -- Employee out-of-pocket reimbursement claim evidence.
 * Uses the same append-only, audited versioned-record facility as Fund Downloads.
 * NO source fund, bank, GL, check, or payment posting occurs here.
 * Draft vouchers containing these claims are explicitly not finalizable.
 */
require_once __DIR__.'/ReimbursementFinalization.php';
require_once __DIR__.'/PrClassificationPreview.php';
final class EmployeeClaims {
    private const PREFIX='ERC1_';
    private const REVIEW='ERW1_';
    private const LINK='ERL1_';
    private const FINAL_KIND='O';
    private static function today(): string {return (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');}
    private static function field(mixed $value,string $label,int $max=160): string {
        demand(is_string($value),$label.' is required',422);
        $v=trim($value);demand($v!=='' && mbSafeLen($v)<=$max && !preg_match('/[\x00-\x1F\x7F]/',$v),$label.' must be 1–'.$max.' printable characters',422);
        return $v;
    }
    private static function date(mixed $v,string $label): string {
        demand(is_string($v) && preg_match('/^20\d{2}-(0[1-9]|1[0-2])-([0-2]\d|3[01])$/D',$v),$label.' must be YYYY-MM-DD',422);
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('Asia/Manila'));
        demand($d!==false && $d->format('Y-m-d')===$v && $v<=self::today(),$label.' is invalid or in the future',422);
        return $v;
    }
    /** An identified procurement source is documentary authority, never a payment instruction.
     * PR-only: finalized DIRECT_DISBURSEMENT service, no PO/RFQ/other DV.
     * PO: finalized procurement, actual finalized IAR acceptance and no pre-existing
     * supplier DV/recorded advance. These checks repeat inside draft creation.
     */
    public static function procurementSource(PDO $db,int $org,string $type,int $id,int $fundId,
        string $date,string $category,int $amount,bool $lock=false,?int $expectedClaimId=null): array {
        demand(in_array($type,['PR','PO'],true) && $id>0,'Select an existing PR or PO',422);
        if($type==='PR'){
            $r=one($db,"SELECT pr.id,pr.display_number,pr.status,pr.total,pr.document_date,pr.fund_id,
                pt.method FROM purchase_requests pr JOIN procurement_transactions pt ON pt.id=pr.transaction_id
                WHERE pr.organization_id=? AND pr.id=?".($lock?' FOR UPDATE':''),[$org,$id]);
            demand($r!==null && $r['status']==='FINALIZED' && $r['method']==='DIRECT_DISBURSEMENT',
                'Only a finalized direct-service PR may support a PR-only employee reimbursement',409);
            demand($category==='OTHER','Supplies must use a finalized PO with accepted IAR, not a PR alone',409);
            $lines=one($db,"SELECT COUNT(*) n, SUM(CASE WHEN c.category='SERVICE' THEN 1 ELSE 0 END) service_count
                FROM pr_lines pl JOIN item_catalog c ON c.id=pl.item_id WHERE pl.pr_id=?",[$id]);
            demand((int)($lines['n']??0)>0 && (int)$lines['n']===(int)$lines['service_count'],
                'PR-only employee reimbursement is limited to direct services (for example telephone bills)',409);
            demand(one($db,"SELECT id FROM purchase_orders WHERE organization_id=? AND pr_id=? LIMIT 1",[$org,$id])===null,
                'This PR has a PO; select that PO after accepted IAR instead',409);
            demand(one($db,"SELECT id FROM rfqs WHERE pr_id=? LIMIT 1",[$id])===null,
                'This PR has an RFQ; complete procurement before claiming reimbursement',409);
            $ceiling=moneyCents((string)$r['total']);
            $supplier='';$acceptance='';
            $prId=$id;
        }else{
            $r=one($db,"SELECT po.id,po.display_number,po.status,po.pr_id,pr.fund_id,pr.document_date,
                pr.status AS pr_status,s.name AS supplier_name
                FROM purchase_orders po JOIN purchase_requests pr ON pr.id=po.pr_id
                JOIN suppliers s ON s.id=po.supplier_id
                WHERE po.organization_id=? AND po.id=?".($lock?' FOR UPDATE':''),[$org,$id]);
            demand($r!==null && $r['status']==='FINALIZED' && $r['pr_status']==='FINALIZED',
                'A finalized PO and PR are required',409);
            $accepted=one($db,"SELECT COALESCE(SUM(l.accepted_amount),0) accepted_total,
                COALESCE(SUM(CASE WHEN c.category IN ('SUPPLY','PPE','SEMI_EXPENDABLE')
                    THEN l.accepted_amount ELSE 0 END),0) goods_total,
                GROUP_CONCAT(DISTINCT i.display_number ORDER BY i.display_number SEPARATOR ', ') iar_numbers
                FROM iar_lines l JOIN iars i ON i.id=l.iar_id AND i.status='FINALIZED'
                JOIN po_lines pl ON pl.id=l.po_line_id
                JOIN item_catalog c ON c.id=pl.item_id
                WHERE i.organization_id=? AND i.po_id=?",[$org,$id]);
            $ceiling=moneyCents((string)($accepted['accepted_total']??'0.00'));
            demand($ceiling>0,'Complete inspection and finalize IAR acceptance before a PO reimbursement',409);
            if($category==='SUPPLIES'){
                $goods=moneyCents((string)($accepted['goods_total']??'0.00'));
                demand($goods>0,'Selected PO has no accepted supplies/property quantities',409);
                $ceiling=min($ceiling,$goods);
            }
            // Existing IAR advances are already a distinct reimbursement source.
            // Re-recording them as new personal-outlay claims would double count.
            demand(one($db,"SELECT a.id FROM advances a JOIN iar_lines il ON il.id=a.iar_line_id
                JOIN iars i ON i.id=il.iar_id WHERE i.organization_id=? AND i.po_id=? LIMIT 1",[$org,$id])===null,
                'This PO already has a recorded advance; use the existing procurement-advance review instead',409);
            $prId=(int)$r['pr_id'];$supplier=(string)$r['supplier_name'];
            $acceptance=(string)($accepted['iar_numbers']??'');
        }
        demand((int)$r['fund_id']===$fundId,'Selected PR/PO belongs to another paying fund',409);
        demand(empty($r['document_date']) || (string)$r['document_date']<=$date,
            'Employee payment cannot predate the linked PR document',409);
        demand($amount>0 && $amount<=$ceiling,'Claimed amount exceeds the authorized/accepted procurement amount',409);
        // Block source-based double payment. Check BOTH direct PR allocation and
        // PO's accepted IAR allocation/document link, including prior cancelled
        // records (finance must reconcile their check history first).
        $conflict=one($db,"SELECT v.id FROM disbursement_vouchers v WHERE v.organization_id=? AND
            (EXISTS(SELECT 1 FROM dv_allocation_lines al WHERE al.dv_id=v.id AND al.pr_id=?)
             OR EXISTS(SELECT 1 FROM dv_allocation_lines al JOIN iar_lines il ON il.id=al.iar_line_id
                 JOIN iars i ON i.id=il.iar_id WHERE al.dv_id=v.id AND i.po_id=?)
             OR EXISTS(SELECT 1 FROM document_links l WHERE l.organization_id=? AND l.source_type='PO'
                 AND l.source_id=? AND l.target_type='DV' AND l.target_id=v.id)
             OR EXISTS(SELECT 1 FROM document_links l WHERE l.organization_id=? AND l.source_type='PR'
                 AND l.source_id=? AND l.target_type='DV' AND l.target_id=v.id)) LIMIT 1",
            [$org,$prId,$type==='PO'?$id:0,$org,$type==='PO'?$id:0,$org,$prId]);
        demand($conflict===null,'An existing DV references this PR/PO. Review the original DV; do not reimburse twice',409);
        // Deterministic source reservation: one employee claim per PR-only or
        // PO/IAR document. Prevents alternative receipts from claiming again.
        $marker='ERS1_'.$type.'_'.$id;
        $prior=one($db,'SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',[$org,$marker]);
        if($expectedClaimId!==null)demand($prior!==null,
            'Procurement-source reservation is missing; claim history requires review',409);
        if($prior!==null){
            $priorClaim=json_decode((string)$prior['config_json'],true,16,JSON_THROW_ON_ERROR);
            demand($expectedClaimId!==null && is_array($priorClaim) &&
                (int)($priorClaim['claim_id']??0)===$expectedClaimId,
                'This procurement source already has an employee reimbursement claim',409);
        }
        return ['source_type'=>$type,'source_id'=>$id,'source_number'=>(string)($r['display_number']?:($type.' #'.$id)),
            'fund_id'=>$fundId,'authorized_ceiling'=>moneyString($ceiling),
            'supplier_name'=>$supplier,'acceptance_reference'=>$acceptance,'marker'=>$marker];
    }
    /** Read actual accepted PO or approved direct-service PR lines. One row per
     * original procurement line, never per repeated partial IAR receipt. Existing
     * manual, unlinked historical claims are represented by one evidence line.
     * Item IDs are scoped to this original claim and locked again at finalization.
     */
    private static function sourceItems(PDO $db,int $org,array $claim): array {
        $type=(string)($claim['procurement_source_type']??'');
        $id=(int)($claim['procurement_source_id']??0);
        $rows=[];
        if($type==='PO' && $id>0){
            $rows=q($db,"SELECT pl.id AS source_line_id,prl.description_snapshot AS item_name,
                cat.category AS item_category,
                COALESCE((SELECT SUM(il.accepted_amount) FROM iar_lines il
                    JOIN iars i ON i.id=il.iar_id
                    WHERE il.po_line_id=pl.id AND i.po_id=pl.po_id
                    AND i.organization_id=? AND i.status='FINALIZED'),0) AS ceiling
                FROM po_lines pl JOIN purchase_orders po ON po.id=pl.po_id
                JOIN pr_lines prl ON prl.id=pl.pr_line_id
                JOIN item_catalog cat ON cat.id=pl.item_id
                WHERE po.organization_id=? AND pl.po_id=? ORDER BY pl.id LIMIT 101",
                [$org,$org,$id])->fetchAll(PDO::FETCH_ASSOC);
        }elseif($type==='PR' && $id>0){
            $rows=q($db,"SELECT pl.id AS source_line_id,pl.description_snapshot AS item_name,
                cat.category AS item_category,
                ROUND(pl.quantity * pl.estimated_unit_price,2) AS ceiling
                FROM pr_lines pl JOIN purchase_requests pr ON pr.id=pl.pr_id
                JOIN item_catalog cat ON cat.id=pl.item_id
                WHERE pr.organization_id=? AND pl.pr_id=? ORDER BY pl.id LIMIT 101",
                [$org,$id])->fetchAll(PDO::FETCH_ASSOC);
        }else{
            $rows=[['source_line_id'=>1,'item_name'=>(string)$claim['description'],
                'item_category'=>(string)($claim['category']??'OTHER'),
                'ceiling'=>(string)$claim['claimed_amount']]];
        }
        demand(count($rows)<=100,'Procurement has too many lines for one reimbursement; Accounting review required',409);
        $out=[];
        foreach($rows as $row){
            $ceiling=moneyCents((string)$row['ceiling']);
            if($ceiling<=0)continue;
            $category=(string)$row['item_category'];
            if(($claim['category']??'')==='SUPPLIES' && !in_array($category,['SUPPLY','PPE','SEMI_EXPENDABLE'],true))continue;
            if($type==='PR' && $category!=='SERVICE')continue;
            $out[]=['source_line_id'=>(int)$row['source_line_id'],
                'item_name'=>mb_substr(trim((string)$row['item_name']),0,180),
                'item_category'=>$category,'ceiling'=>moneyString($ceiling)];
        }
        demand($out!==[],'No accepted or authorized item lines are available for this reimbursement',409);
        return $out;
    }
    /** Advisory only: reproduce PR's effective-dated Division item-UACS preview.
     * The PR screen currently previews a Division catalog mapping rather than
     * saving a per-PR-line debit account. Never infer an account from the item
     * name, change the PR snapshot, or change the immutable reimbursement hash.
     * A suggested code is an editable default, NOT authority to disburse.
     */
    private static function prItemUacsDefault(array $mappings,array $source,array $active): array {
        $id=(int)($source['item_id']??0);
        $date=(string)($source['pr_document_date']??'');
        $price=(string)($source['pr_unit_price']??'');
        $category=(string)($source['item_category']??'');
        $empty=['suggested_uacs_code'=>'','uacs_suggestion_date'=>'',
            'uacs_suggestion_label'=>'Review UACS'];
        if($id<1 || $date==='' || $price==='' || $mappings===[] ||
            !preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D',$date) ||
            $date>self::today() ||
            !preg_match('/^[0-9]{1,10}(\.[0-9]{1,2})?$/D',$price) ||
            moneyCents($price)<1 || moneyCents($price)>100000000000)return $empty;
        // Resolve with exactly the same read-only rule used by the PR preview.
        $advisory=PrClassificationPreview::evaluate(['item_id'=>$id,
            'category'=>$category,'unit_price'=>$price,'as_of'=>$date,
            'mappings'=>$mappings,'division_classes'=>[]]);
        $code=(string)($advisory['uacs_code']??'');
        $prefix=substr($code,0,3);
        $allowed=match($category){
            'PPE'=>['106','108'],
            'SEMI_EXPENDABLE'=>['502','104','106','108'],
            'TEXTBOOK'=>['104','502'],
            'SUPPLY','SERVICE'=>['502'],
            default=>[]
        };
        if($code==='' || !isset($active[$code]) || !in_array($prefix,$allowed,true))return $empty;
        return ['suggested_uacs_code'=>$code,
            'uacs_suggestion_date'=>(string)($advisory['uacs_effective_from']??''),
            'uacs_suggestion_label'=>'Division item mapping at PR date · verify before signing'];
    }
    private static function itemUacsDefaults(PDO $db,int $org,array $claim,array $items): array {
        $type=(string)($claim['procurement_source_type']??'');
        $sourceId=(int)($claim['procurement_source_id']??0);
        if(!in_array($type,['PR','PO'],true) || $sourceId<1)return [];
        $office=one($db,'SELECT kind,parent_division_id FROM organizations WHERE id=?',[$org]);
        $division=$office && $office['kind']==='DIVISION'?$org:(int)($office['parent_division_id']??0);
        if($division<1)return [];
        $config=one($db,"SELECT config_json FROM configuration_versions
            WHERE organization_id=? AND config_key='DIVISION_ITEM_UACS'
            ORDER BY id DESC LIMIT 1",[$division]);
        if(!$config)return [];
        $data=json_decode((string)$config['config_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($data)&&isset($data['mappings'])&&is_array($data['mappings']),
            'Division item UACS reference needs administrator review',409);
        $ids=array_fill_keys(array_map(static fn($r)=>(int)$r['source_line_id'],$items),true);
        if($type==='PO'){
            $rows=q($db,'SELECT pl.id source_line_id,pl.item_id,cat.category item_category,
                pr.document_date pr_document_date,prl.estimated_unit_price pr_unit_price
                FROM po_lines pl JOIN purchase_orders po ON po.id=pl.po_id
                JOIN purchase_requests pr ON pr.id=po.pr_id
                JOIN pr_lines prl ON prl.id=pl.pr_line_id AND prl.pr_id=pr.id
                JOIN item_catalog cat ON cat.id=pl.item_id
                WHERE po.organization_id=? AND po.id=? ORDER BY pl.id',[$org,$sourceId])->fetchAll(PDO::FETCH_ASSOC);
        }else{
            $rows=q($db,'SELECT pl.id source_line_id,pl.item_id,cat.category item_category,
                pr.document_date pr_document_date,pl.estimated_unit_price pr_unit_price
                FROM pr_lines pl JOIN purchase_requests pr ON pr.id=pl.pr_id
                JOIN item_catalog cat ON cat.id=pl.item_id
                WHERE pr.organization_id=? AND pr.id=? ORDER BY pl.id',[$org,$sourceId])->fetchAll(PDO::FETCH_ASSOC);
        }
        $codes=[];
        foreach($data['mappings'] as $m){
            if(is_array($m)&&preg_match('/^[15][0-9]{9}$/D',(string)($m['uacs_code']??'')))
                $codes[(string)$m['uacs_code']]=true;
        }
        $active=[];
        if($codes){
            $values=array_keys($codes);
            foreach(array_chunk($values,250) as $batch){
                $marks=implode(',',array_fill(0,count($batch),'?'));
                foreach(q($db,'SELECT code FROM uacs_accounts WHERE active=1 AND code IN ('.$marks.')',$batch)->fetchAll() as $a)
                    $active[(string)$a['code']]=true;
            }
        }
        $result=[];
        foreach($rows as $row){
            $id=(int)($row['source_line_id']??0);
            if(!isset($ids[$id]))continue; // not approved/reimbursable in this claim
            $result[$id]=self::prItemUacsDefault($data['mappings'],$row,$active);
        }
        return $result;
    }
    public static function itemOptions(PDO $db,array $actor,int $org,int $id): array {
        scope($db,$actor,$org);
        demand(in_array($actor['role']??'', ['SCHOOL','DIVISION','SUPER_ADMIN'],true),
            'Employee reimbursement details are restricted to the owning office',403);
        $claim=self::claim($db,$org,$id);
        $review=self::review($db,$org,$id);
        demand($review!==null && in_array($review['decision']??'',['ALLOWED','PARTIALLY_ALLOWED'],true),
            'Complete approved reimbursement review before itemizing its UACS',409);
        // Enrich only the read-only GET response. sourceItems() itself MUST stay
        // byte-for-byte stable because the v0.2.359 finalization digest and DV
        // source locks depend on the original immutable item evidence.
        $items=self::sourceItems($db,$org,$claim);
        $defaults=self::itemUacsDefaults($db,$org,$claim,$items);
        foreach($items as &$item){
            $id=(int)$item['source_line_id'];
            $item+=($defaults[$id]??['suggested_uacs_code'=>'',
                'uacs_suggestion_date'=>'','uacs_suggestion_label'=>'Select and review UACS']);
        }
        unset($item);
        return ['claim_id'=>$id,'approved_amount'=>(string)$review['allowed_amount'],
            'source_items'=>$items];
    }
    private static function checkedItemization(PDO $db,int $org,array $claim,int $approved,array $input): array {
        demand(array_is_list($input)&&count($input)>0&&count($input)<=100,
            'Select each reimbursed item and its debit UACS before finalizing',422);
        $options=self::sourceItems($db,$org,$claim);
        $lookup=[];foreach($options as $o)$lookup[$o['source_line_id']]=$o;
        $used=[];$sum=0;$out=[];
        foreach($input as $entry){
            demand(is_array($entry),'Invalid reimbursement item line',422);
            $id=$entry['source_line_id']??null;
            demand(is_int($id)&&$id>0&&isset($lookup[$id])&&!isset($used[$id]),
                'Item does not belong to the linked PR/PO or was repeated',422);
            $used[$id]=true;
            $cents=moneyCents((string)($entry['amount']??''));
            demand($cents>0&&$cents<=moneyCents($lookup[$id]['ceiling']),
                'Item reimbursement exceeds its accepted/authorized amount',409);
            $code=(string)($entry['debit_uacs_code']??'');
            demand(preg_match('/^[15][0-9]{9}$/D',$code)===1 &&
                one($db,'SELECT code FROM uacs_accounts WHERE code=? AND active=1',[$code])!==null,
                'Every reimbursed item needs an active expense/asset debit UACS',422);
            $out[]=['source_line_id'=>$id,'item_name'=>$lookup[$id]['item_name'],
                'amount'=>moneyString($cents),'debit_uacs_code'=>$code];
            $sum+=$cents;
        }
        demand($sum===$approved,'Itemized reimbursements must total the exact Accounting-approved claim amount',409);
        usort($out,static fn($a,$b)=>$a['source_line_id']<=>$b['source_line_id']);
        return ['debit_items'=>$out,'item_source_digest'=>ReimbursementFinalization::digest($options)];
    }
    private static function checkFinalItems(PDO $db,int $org,array $claim,array $final,int $approved): void {
        if(!isset($final['debit_items']))return; // Historical v0.2.353-v0.2.358 one-debit source.
        demand(is_array($final['debit_items']) && count($final['debit_items'])>0 &&
            hash_equals((string)($final['item_source_digest']??''),
                ReimbursementFinalization::digest(self::sourceItems($db,$org,$claim))),
            'Procurement item evidence changed after reimbursement finalization; stop for Accounting review',409);
        $checked=self::checkedItemization($db,$org,$claim,$approved,$final['debit_items']);
        demand($checked['debit_items']===$final['debit_items'],
            'Reimbursement itemized accounting record no longer matches the finalized source',409);
    }
    public static function procurementOptions(PDO $db,int $org): array {
        $options=[];
        $prs=q($db,"SELECT pr.id,pr.display_number,pr.fund_id,pr.total,pr.purpose
            FROM purchase_requests pr JOIN procurement_transactions pt ON pt.id=pr.transaction_id
            WHERE pr.organization_id=? AND pr.status='FINALIZED' AND pt.method='DIRECT_DISBURSEMENT'
                AND NOT EXISTS(SELECT 1 FROM purchase_orders po WHERE po.pr_id=pr.id)
                AND NOT EXISTS(SELECT 1 FROM rfqs r WHERE r.pr_id=pr.id)
                AND EXISTS(SELECT 1 FROM pr_lines pl WHERE pl.pr_id=pr.id)
                AND NOT EXISTS(SELECT 1 FROM pr_lines pl JOIN item_catalog cat ON cat.id=pl.item_id
                    WHERE pl.pr_id=pr.id AND cat.category<>'SERVICE')
                AND NOT EXISTS(SELECT 1 FROM configuration_versions cv
                    WHERE cv.organization_id=pr.organization_id AND cv.config_key=CONCAT('ERS1_PR_',pr.id))
                AND NOT EXISTS(SELECT 1 FROM dv_allocation_lines al JOIN disbursement_vouchers v ON v.id=al.dv_id
                    WHERE v.organization_id=pr.organization_id AND al.pr_id=pr.id)
                AND NOT EXISTS(SELECT 1 FROM document_links l
                    WHERE l.organization_id=pr.organization_id AND l.source_type='PR'
                    AND l.source_id=pr.id AND l.target_type='DV')
            ORDER BY pr.id DESC LIMIT 100",[$org])->fetchAll(PDO::FETCH_ASSOC);
        foreach($prs as $pr)$options[]=['type'=>'PR','id'=>(int)$pr['id'],
            'number'=>(string)$pr['display_number'],'fund_id'=>(int)$pr['fund_id'],
            'description'=>(string)$pr['purpose'],'amount'=>(string)$pr['total']];
        $pos=q($db,"SELECT po.id,po.display_number,pr.fund_id,s.name supplier_name,
                (SELECT COALESCE(SUM(l.accepted_amount),0) FROM iar_lines l JOIN iars i ON i.id=l.iar_id
                  WHERE i.po_id=po.id AND i.organization_id=po.organization_id AND i.status='FINALIZED') accepted_total,
                (SELECT GROUP_CONCAT(DISTINCT i.display_number ORDER BY i.display_number SEPARATOR ', ')
                  FROM iars i WHERE i.po_id=po.id AND i.organization_id=po.organization_id AND i.status='FINALIZED') iar_numbers,
                (SELECT COALESCE(SUM(CASE WHEN c.category IN ('SUPPLY','PPE','SEMI_EXPENDABLE')
                   THEN l.accepted_amount ELSE 0 END),0) FROM iar_lines l JOIN iars i ON i.id=l.iar_id
                   JOIN po_lines pl ON pl.id=l.po_line_id JOIN item_catalog c ON c.id=pl.item_id
                   WHERE i.po_id=po.id AND i.organization_id=po.organization_id AND i.status='FINALIZED') goods_total
            FROM purchase_orders po JOIN purchase_requests pr ON pr.id=po.pr_id
                JOIN suppliers s ON s.id=po.supplier_id
            WHERE po.organization_id=? AND po.status='FINALIZED' AND pr.status='FINALIZED'
                AND NOT EXISTS(SELECT 1 FROM configuration_versions cv
                    WHERE cv.organization_id=po.organization_id AND cv.config_key=CONCAT('ERS1_PO_',po.id))
                AND NOT EXISTS(SELECT 1 FROM advances a JOIN iar_lines il ON il.id=a.iar_line_id
                    JOIN iars i ON i.id=il.iar_id
                    WHERE i.organization_id=po.organization_id AND i.po_id=po.id)
                AND NOT EXISTS(SELECT 1 FROM dv_allocation_lines al JOIN disbursement_vouchers v ON v.id=al.dv_id
                    WHERE v.organization_id=po.organization_id AND al.pr_id=po.pr_id)
                AND NOT EXISTS(SELECT 1 FROM document_links l
                    WHERE l.organization_id=po.organization_id AND
                        ((l.source_type='PO' AND l.source_id=po.id) OR
                         (l.source_type='PR' AND l.source_id=po.pr_id)) AND l.target_type='DV')
            ORDER BY po.id DESC LIMIT 100",[$org])->fetchAll(PDO::FETCH_ASSOC);
        foreach($pos as $po)if(moneyCents((string)$po['accepted_total'])>0)
            $options[]=['type'=>'PO','id'=>(int)$po['id'],
                'number'=>(string)$po['display_number'],'fund_id'=>(int)$po['fund_id'],
                'description'=>(string)$po['supplier_name'].' · accepted IAR '.(string)$po['iar_numbers'],
                'amount'=>(string)$po['accepted_total'],
                // A mixed goods/service PO can have more accepted value than
                // the SUPPLIES claim is allowed to include. Suggest only the
                // applicable accepted goods value for that category.
                'suggested_amount'=>moneyCents((string)$po['goods_total'])>0
                    ?(string)$po['goods_total']:(string)$po['accepted_total'],
                'suggested_category'=>moneyCents((string)$po['goods_total'])>0?'SUPPLIES':'OTHER',
                'supplier_name'=>(string)$po['supplier_name'],
                'acceptance_reference'=>(string)$po['iar_numbers']];
        return $options;
    }
    public static function writer(PDO $db,array $actor,int $org): void {
        scope($db,$actor,$org);allowedToWrite($actor);
        $office=one($db,'SELECT kind FROM organizations WHERE id=? AND active=1',[$org]);
        demand($office!==null,'Workspace not found',403);
        $role=(string)($actor['role']??'');
        demand($role==='SUPER_ADMIN' || ($role==='SCHOOL' && $office['kind']==='SCHOOL') ||
            ($role==='DIVISION' && $office['kind']==='DIVISION'),
            'Only the owning office can prepare/review its employee reimbursement claims',403);
    }
    private static function audit(PDO $db,int $org,int $actor,string $event,int $id,array $details): void {
        q($db,'INSERT INTO audit_events (organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
            [$org,$actor,$event,'EMPLOYEE_REIMBURSEMENT',$id,json_encode($details,JSON_THROW_ON_ERROR)]);
    }
    public static function claim(PDO $db,int $org,int $id,bool $lock=false): array {
        $r=one($db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND id=? AND LEFT(config_key,5)=?'.($lock?' FOR UPDATE':''),[$org,$id,self::PREFIX]);
        demand($r!==null,'Employee reimbursement claim is unavailable in this office',404);
        $c=json_decode((string)$r['config_json'],true,64,JSON_THROW_ON_ERROR);
        demand(is_array($c)&&($c['kind']??'')==='OUT_OF_POCKET'&&(int)($c['organization_id']??0)===$org && ($c['state']??'')==='SUBMITTED',
            'Invalid reimbursement evidence record',409);
        $c['claim_id']=(int)$r['id'];return $c;
    }
    public static function review(PDO $db,int $org,int $id): ?array {
        $r=one($db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',
            [$org,self::REVIEW.$id]);
        if(!$r)return null;
        $v=json_decode((string)$r['config_json'],true,64,JSON_THROW_ON_ERROR);
        demand(is_array($v)&& (int)($v['claim_id']??0)===$id && ($v['kind']??'')==='REVIEW','Invalid reimbursement review',409);
        $v['review_id']=(int)$r['id'];return $v;
    }
    /** Most recent reservation wins, but a CANCELLED *unnumbered* draft can be rebooked. */
    public static function reservation(PDO $db,int $org,int $id): ?array {
        $r=one($db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND LEFT(config_key,5)=? AND config_key LIKE ? ORDER BY id DESC LIMIT 1',
            [$org,self::LINK,self::LINK.$id.'-%']);
        if(!$r)return null;
        $link=json_decode((string)$r['config_json'],true,32,JSON_THROW_ON_ERROR);
        demand(is_array($link)&&(int)($link['claim_id']??0)===$id,'Invalid employee reimbursement reservation',409);
        $dv=one($db,'SELECT id,status,display_number,dv_type FROM disbursement_vouchers WHERE id=? AND organization_id=?',[(int)$link['dv_id'],$org]);
        demand($dv!==null && ($dv['dv_type']??'')==='TRAVEL_REIMBURSEMENT','Reimbursement DV source link needs review',409);
        return ['dv_id'=>(int)$dv['id'],'status'=>(string)$dv['status'],
            'active'=>!($dv['status']==='CANCELLED' && empty($dv['display_number'])),
            'link_id'=>(int)$r['id']];
    }
    public static function eligible(PDO $db,int $org,int $id,bool $lock=false): array {
        $c=self::claim($db,$org,$id,$lock);
        $review=self::review($db,$org,$id);
        demand($review!==null && ($review['decision']??'')!=='EXCLUDED' &&
            in_array(($review['decision']??''),['ALLOWED','PARTIALLY_ALLOWED'],true),
            'This out-of-pocket claim has no approved documentary finding',409);
        $allowed=moneyCents((string)$review['allowed_amount']);$claimed=moneyCents((string)$c['claimed_amount']);
        demand($allowed>0 && $allowed<=$claimed,'Invalid reviewed allowance',409);
        demand(($c['receipt_confirmed']??false)===true && ($c['paid_by_employee']??false)===true &&
            ($c['authorized_purchase']??false)===true && ($c['no_supplier_dv']??false)===true,
            'Reimbursement evidence/authorization is incomplete',409);
        if(($c['category']??'')==='SUPPLIES')demand(($c['goods_accepted']??false)===true && trim((string)($c['acceptance_reference']??''))!=='',
            'Supplies must be accepted and linked to the stock or property evidence',409);
        if(in_array(($c['procurement_source_type']??''),['PR','PO'],true)) {
            $proof=self::procurementSource($db,$org,(string)$c['procurement_source_type'],
                (int)($c['procurement_source_id']??0),(int)$c['fund_id'],
                (string)$c['purchase_date'],(string)$c['category'],$claimed,$lock,$id);
            // No new source can be falsely substituted after the claim is stored.
            demand(($c['procurement_source_number']??'')===$proof['source_number'],
                'Procurement source document changed; office must review before another DV',409);
        }
        $reservation=self::reservation($db,$org,$id);
        $final=ReimbursementFinalization::load($db,$org,self::FINAL_KIND,$id,$lock);
        // Existing DV reservations created before the upgrade remain valid when validated
        // by their historical approval paths; NEW drafts require final source certification.
        if(!$reservation || !$reservation['active'])ReimbursementFinalization::requireMatching($final,
            ['claim'=>$c,'review'=>$review,'allowed_amount'=>moneyString($allowed)],'Employee-paid claim');
        if($final!==null)self::checkFinalItems($db,$org,$c,$final,$allowed);
        demand(!$reservation || !$reservation['active'],'This personal-outlay claim is already reserved by DV #'.($reservation['dv_id']??0),409);
        // Recheck vendor invoice at draft creation; a supplier DV may have
        // appeared since the original employee outlay was first recorded.
        demand(one($db,'SELECT id FROM disbursement_vouchers WHERE organization_id=? AND UPPER(TRIM(payee))=? AND UPPER(TRIM(bill_reference))=? LIMIT 1',
            [$org,strtoupper((string)$c['vendor']),strtoupper((string)$c['receipt_reference'])])===null,
            'Supplier invoice has since been linked to a DV; do not reimburse it again',409);
        $employee=one($db,'SELECT full_name FROM employees WHERE id=?',[(int)$c['employee_id']]);
        demand($employee!==null && trim((string)$employee['full_name'])===$c['employee_name'],'Employee identity changed',409);
        $fund=one($db,'SELECT id FROM funds WHERE organization_id=? AND id=?',[$org,(int)$c['fund_id']]);
        demand($fund!==null,'Reimbursement fund is no longer assigned to this office',409);
        return ['claim'=>$c,'review'=>$review,'finalization'=>$final,'amount'=>$allowed,'employee_name'=>$c['employee_name'],'employee_id'=>(int)$c['employee_id'],'fund_id'=>(int)$c['fund_id']];
    }
    public static function candidates(PDO $db,int $org): array {
        $rows=q($db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND LEFT(config_key,5)=? ORDER BY id DESC LIMIT 200',[$org,self::PREFIX])->fetchAll(PDO::FETCH_ASSOC);
        $out=[];
        foreach($rows as $r){$c=json_decode((string)$r['config_json'],true,64);
            if(!is_array($c)||($c['kind']??null)!=='OUT_OF_POCKET')continue;
            $id=(int)$r['id'];$review=self::review($db,$org,$id);$link=self::reservation($db,$org,$id);$sourceBlock='';
            $final=ReimbursementFinalization::load($db,$org,self::FINAL_KIND,$id);
            $eligible=$review!==null && in_array(($review['decision']??''),['ALLOWED','PARTIALLY_ALLOWED'],true) &&
                moneyCents((string)($review['allowed_amount']??'0.00'))>0 && (!$link || !$link['active']);
            if($eligible && $final!==null) {
                $eligible=$final['source_digest']===ReimbursementFinalization::digest(
                    ['claim'=>$c+['claim_id'=>$id],'review'=>$review,'allowed_amount'=>(string)($review['allowed_amount']??'0.00')]);
                if(!$eligible)$sourceBlock='Finalized source no longer matches its supporting claim';
                if($eligible)try{self::checkFinalItems($db,$org,$c+['claim_id'=>$id],$final,
                    moneyCents((string)$review['allowed_amount']));}
                    catch(DomainError $e){$eligible=false;$sourceBlock=$e->getMessage();}
            }
            if($eligible && in_array(($c['procurement_source_type']??''),['PR','PO'],true)) {
                try { self::procurementSource($db,$org,(string)$c['procurement_source_type'],
                    (int)$c['procurement_source_id'],(int)$c['fund_id'],
                    (string)$c['purchase_date'],(string)$c['category'],moneyCents((string)$c['claimed_amount']),false,$id); }
                catch(DomainError $e) {$eligible=false;$sourceBlock=$e->getMessage();}
            }
            $out[]=['claim_id'=>$id,'source_key'=>'O'.$id,'claim_kind'=>'OUT_OF_POCKET',
                'claim_number'=>'ER-'.$id,'employee_id'=>(int)$c['employee_id'],
                'employee_name'=>(string)$c['employee_name'],'fund_id'=>(int)$c['fund_id'],
                'claim_date'=>(string)$c['purchase_date'],
                'category'=>(string)$c['category'],'vendor'=>(string)$c['vendor'],
                'procurement_source_type'=>(string)($c['procurement_source_type']??''),
                'procurement_source_number'=>(string)($c['procurement_source_number']??''),
                'description'=>(string)$c['description'],'claimed'=>(string)$c['claimed_amount'],
                'preliminary_allowed'=>$review===null?null:(string)$review['allowed_amount'],
                'finalized_reference'=>$final['reference']??'',
                'debit_uacs_code'=>$final['debit_uacs_code']??'',
                'debit_items'=>$final['debit_items']??[],
                'approved_amount'=>$eligible && $final!==null?(string)$review['allowed_amount']:null,
                'documentary_ready'=>$review!==null,'ready_for_dv'=>$eligible && $final!==null,
                'active_dv_id'=>$link && $link['active']?$link['dv_id']:null,
                'readiness_message'=>$link && $link['active']?'Already reserved in another draft DV':
                    ($review===null?'Expense review pending':(($review['decision']??'')==='EXCLUDED'?'Expense excluded':($sourceBlock?:($final===null?'Finalize this PR/PO expense in the DV preparation window':'Ready for draft DV'))))];
        }
        return $out;
    }
    public static function listing(PDO $db,array $actor,int $org): array {
        scope($db,$actor,$org);
        demand(in_array($actor['role']??'', ['SCHOOL','DIVISION','SUPER_ADMIN'],true),
            'Employee reimbursement evidence is not available in the read-only monthly document viewer',403);
        $office=one($db,'SELECT kind FROM organizations WHERE id=? AND active=1',[$org]);
        demand($office!==null,'Office unavailable',404);
        $can=($actor['role']==='SUPER_ADMIN'||($actor['role']==='SCHOOL' && $office['kind']==='SCHOOL')||
            ($actor['role']==='DIVISION' && $office['kind']==='DIVISION'));
        $employees=q($db,'SELECT e.id,e.full_name FROM employee_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.organization_id=? AND a.effective_from<=? AND (a.effective_to IS NULL OR a.effective_to>?) GROUP BY e.id,e.full_name ORDER BY e.full_name LIMIT 400',
            [$org,self::today(),self::today()])->fetchAll();
        $funds=q($db,'SELECT id,source_code,fiscal_year FROM funds WHERE organization_id=? ORDER BY fiscal_year DESC,id DESC LIMIT 200',[$org])->fetchAll();
        return ['claims'=>self::candidates($db,$org),'employees'=>$employees,'funds'=>$funds,
            'procurement_sources'=>self::procurementOptions($db,$org),'can_prepare'=>$can];
    }
    public static function create(PDO $db,array $actor,array $d): array {
        $org=idOf($d,'organization_id');self::writer($db,$actor,$org);
        $employee=idOf($d,'employee_id');$fund=idOf($d,'fund_id');
        $date=self::date($d['purchase_date']??null,'Purchase date');
        $category=self::field($d['category']??null,'Claim category',20);
        demand(in_array($category,['SUPPLIES','OTHER'],true),'Choose Supplies or Other employee-paid expense',422);
        $vendor=self::field($d['vendor']??null,'Seller / supplier',150);
        $receipt=self::field($d['receipt_reference']??null,'Original receipt / invoice reference',120);
        $description=self::field($d['description']??null,'Expense particulars',300);
        $authority=self::field($d['authority_reference']??null,'Prior authorization / procurement basis',160);
        $payment=self::field($d['payment_reference']??null,'Personal payment evidence reference',160);
        $acceptance=$category==='SUPPLIES'?self::field($d['acceptance_reference']??null,'Supply/property acceptance record',160):'';
        $claimed=moneyCents((string)($d['claimed_amount']??''));
        demand($claimed>0 && $claimed<=200000000,'Enter a positive personal-outlay amount within the draft DV limit',422);
        demand(($d['receipt_confirmed']??null)===true && ($d['paid_by_employee']??null)===true &&
            ($d['authorized_purchase']??null)===true && ($d['no_supplier_dv']??null)===true &&
            ($category!=='SUPPLIES'||($d['goods_accepted']??null)===true),
            'Confirm original receipt, employee payment, purchase authority, no duplicate supplier DV, and acceptance where applicable',422);
        $sourceType=(string)($d['procurement_source_type']??'');
        demand(in_array($sourceType,['','PR','PO'],true),'Select PR, PO or manually documented authority',422);
        $sourceId=$sourceType!==''?idOf($d,'procurement_source_id'):0;
        $key=self::PREFIX.substr(hash('sha256',$org.'|'.strtoupper($vendor).'|'.strtoupper($receipt)),0,48);
        $db->beginTransaction();try {
            // The organization lock serializes duplicate receipt checks and reservations.
            demand(one($db,'SELECT id FROM organizations WHERE id=? AND active=1 FOR UPDATE',[$org])!==null,'Office unavailable',403);
            $staff=one($db,'SELECT e.full_name FROM employee_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.organization_id=? AND a.employee_id=? AND a.effective_from<=? AND (a.effective_to IS NULL OR a.effective_to>?) LIMIT 1',
                [$org,$employee,$date,$date]);
            demand($staff!==null,'Employee must be assigned to this office on the purchase date',422);
            $linkedSource=$sourceType!==''?self::procurementSource($db,$org,$sourceType,$sourceId,$fund,$date,$category,$claimed,true):null;
            if($linkedSource!==null && $sourceType==='PO')demand(
                strtoupper(trim($vendor))===strtoupper(trim((string)$linkedSource['supplier_name'])),
                'Receipt vendor must match the awarded PO supplier; review the documentary payee',409);
            demand(one($db,'SELECT id FROM funds WHERE id=? AND organization_id=?',[$fund,$org])!==null,
                'Choose a fund owned by the selected office',422);
            demand(one($db,'SELECT id FROM configuration_versions WHERE organization_id=? AND config_key=? LIMIT 1',[$org,$key])===null,
                'This vendor receipt, date and amount were already claimed',409);
            // A matching vendor bill already in an existing DV is not reimbursable again.
            demand(one($db,'SELECT id FROM disbursement_vouchers WHERE organization_id=? AND UPPER(TRIM(payee))=? AND UPPER(TRIM(bill_reference))=? LIMIT 1',
                [$org,strtoupper($vendor),strtoupper($receipt)])===null,
                'This supplier invoice is already linked to a Disbursement Voucher',409);
            $data=['kind'=>'OUT_OF_POCKET','state'=>'SUBMITTED','organization_id'=>$org,'employee_id'=>$employee,
                'employee_name'=>(string)$staff['full_name'],'fund_id'=>$fund,'purchase_date'=>$date,
                'category'=>$category,'vendor'=>$vendor,'receipt_reference'=>$receipt,'description'=>$description,
                'authority_reference'=>$authority,'payment_reference'=>$payment,'acceptance_reference'=>$acceptance,
                'procurement_source_type'=>$sourceType,'procurement_source_id'=>$sourceId,
                'procurement_source_number'=>$linkedSource['source_number']??'',
                'procurement_source_accepted_amount'=>$linkedSource['authorized_ceiling']??null,
                'claimed_amount'=>moneyString($claimed),'receipt_confirmed'=>true,'paid_by_employee'=>true,
                'authorized_purchase'=>true,'no_supplier_dv'=>true,'goods_accepted'=>$category==='SUPPLIES',
                'created_by'=>(int)$actor['id']];
            q($db,'INSERT INTO configuration_versions (organization_id,config_key,config_json,effective_from,review_note) VALUES (?,?,?,?,?)',
                [$org,$key,json_encode($data,JSON_THROW_ON_ERROR),$date,'Employee reimbursement source evidence, NOT DV/payment authorization']);
            $id=(int)$db->lastInsertId();
            if($linkedSource!==null){
                q($db,'INSERT INTO configuration_versions (organization_id,config_key,config_json,effective_from,review_note) VALUES (?,?,?,?,?)',
                  [$org,$linkedSource['marker'],json_encode(['kind'=>'PROCUREMENT_CLAIM_LINK','claim_id'=>$id,
                    'source_type'=>$sourceType,'source_id'=>$sourceId,'fund_id'=>$fund],JSON_THROW_ON_ERROR),$date,
                    'Identified procurement source reserved for this employee claim; no payment created']);
            }
            self::audit($db,$org,(int)$actor['id'],'EMPLOYEE_CLAIM_RECORDED',$id,
                ['employee_id'=>$employee,'fund_id'=>$fund,'category'=>$category,'amount'=>moneyString($claimed),
                 'procurement_source'=>$linkedSource?[$sourceType,$sourceId]:null,'no_payment_created'=>true]);
            $db->commit();return ['claim_id'=>$id,'status'=>'REVIEW_PENDING'];
        } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function recordReview(PDO $db,array $actor,array $d): array {
        $org=idOf($d,'organization_id');self::writer($db,$actor,$org);
        $id=idOf($d,'claim_id');$allowed=moneyCents((string)($d['allowed_amount']??''));
        $decision=self::field($d['decision']??null,'Review decision',30);
        demand(in_array($decision,['ALLOWED','PARTIALLY_ALLOWED','EXCLUDED'],true),'Choose a valid review finding',422);
        $reason=$decision!=='ALLOWED'?self::field($d['reason']??null,'Reason for reduction/exclusion',300):trim((string)($d['reason']??''));
        demand(($d['reviewed_papers']??null)===true && ($d['not_previously_paid']??null)===true,
            'Confirm paper evidence and absence of previous supplier/employee payment',422);
        $db->beginTransaction();try {
            demand(one($db,'SELECT id FROM organizations WHERE id=? AND active=1 FOR UPDATE',[$org])!==null,'Office unavailable',403);
            $c=self::claim($db,$org,$id,true);
            if(in_array(($c['procurement_source_type']??''),['PR','PO'],true))
                self::procurementSource($db,$org,(string)$c['procurement_source_type'],
                    (int)$c['procurement_source_id'],(int)$c['fund_id'],
                    (string)$c['purchase_date'],(string)$c['category'],moneyCents((string)$c['claimed_amount']),true,$id);
            demand(self::review($db,$org,$id)===null,'Review already recorded; audited correction is required before another approval',409);
            $claimed=moneyCents((string)$c['claimed_amount']);
            demand(($decision==='EXCLUDED' && $allowed===0)||
                ($decision==='ALLOWED' && $allowed===$claimed)||
                ($decision==='PARTIALLY_ALLOWED' && $allowed>0 && $allowed<$claimed),
                'Reviewed amount does not match the selected decision',422);
            $review=['kind'=>'REVIEW','claim_id'=>$id,'decision'=>$decision,'allowed_amount'=>moneyString($allowed),
                'reason'=>$reason,'reviewed_by'=>(int)$actor['id'],'reviewed_papers'=>true,'not_previously_paid'=>true,
                'review_mode'=>'OWNING_OFFICE_DOCUMENTARY_REVIEW_NOT_PAYMENT_AUTHORIZATION',
                'same_actor_as_claim_preparer'=>(int)$actor['id']===(int)$c['created_by']];
            q($db,'INSERT INTO configuration_versions (organization_id,config_key,config_json,effective_from,review_note) VALUES (?,?,?,?,?)',
                [$org,self::REVIEW.$id,json_encode($review,JSON_THROW_ON_ERROR),self::today(),
                 'Employee claim documentary review. Not final accounting/payment approval']);
            $rid=(int)$db->lastInsertId();self::audit($db,$org,(int)$actor['id'],'EMPLOYEE_CLAIM_REVIEWED',$id,
                ['review_id'=>$rid,'decision'=>$decision,'allowed_amount'=>moneyString($allowed),'independent_review'=>(int)$actor['id']!==(int)$c['created_by']]);
            $db->commit();return ['claim_id'=>$id,'status'=>$decision==='EXCLUDED'?'EXCLUDED':'READY_FOR_DRAFT_DV'];
        } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function finalizeClaim(PDO $db,array $actor,array $d): array {
        $org=idOf($d,'organization_id');self::writer($db,$actor,$org);
        $id=idOf($d,'claim_id');$fields=ReimbursementFinalization::fields($db,$d,'O');
        $db->beginTransaction();try {
            demand(one($db,'SELECT id FROM organizations WHERE id=? AND active=1 FOR UPDATE',[$org])!==null,
                'Office is unavailable',403);
            demand(ReimbursementFinalization::load($db,$org,self::FINAL_KIND,$id,true)===null,
                'This reimbursement is already finalized',409);
            $eligible=self::eligibleSourceForFinalization($db,$org,$id);
            $c=$eligible['claim'];$review=$eligible['review'];$approved=$eligible['amount'];
            $fields=array_merge($fields,self::checkedItemization($db,$org,$c,$approved,$fields['debit_items']));
            $ref='ER-'.$id;
            $snap=['claim'=>$c,'review'=>$review,'allowed_amount'=>moneyString($approved)];
            $final=ReimbursementFinalization::save($db,$org,(int)$actor['id'],self::FINAL_KIND,$id,[
                'reference'=>$ref,'approved_amount'=>moneyString($approved),
                'employee_id'=>(int)$c['employee_id'],'fund_id'=>(int)$c['fund_id'],
                'review_id'=>(int)$review['review_id'],
                'source_digest'=>ReimbursementFinalization::digest($snap)
            ],$fields);
            $db->commit();return ['claim_id'=>$id,'reference'=>$ref,'status'=>'FINALIZED_READY_FOR_DV',
                'approved_amount'=>$final['approved_amount'],'payment_created'=>false];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    private static function eligibleSourceForFinalization(PDO $db,int $org,int $id): array {
        $c=self::claim($db,$org,$id,true);$review=self::review($db,$org,$id);
        demand($review!==null && in_array($review['decision']??'',['ALLOWED','PARTIALLY_ALLOWED'],true),
            'An approved documentary review is required before reimbursement finalization',409);
        demand(($review['reviewed_papers']??false)===true && ($review['not_previously_paid']??false)===true,
            'Document review does not confirm original paid receipts or duplicate-payment search',409);
        $approved=moneyCents((string)($review['allowed_amount']??'0.00'));
        demand($approved>0 && $approved<=moneyCents((string)$c['claimed_amount']),
            'Approved reimbursement must be positive and not exceed the claim',409);
        demand(self::reservation($db,$org,$id)===null,
            'This claim already has a DV reservation; it cannot be finalized again',409);
        demand(one($db,'SELECT id FROM disbursement_vouchers WHERE organization_id=? AND UPPER(TRIM(payee))=? AND UPPER(TRIM(bill_reference))=? LIMIT 1',
            [$org,strtoupper((string)$c['vendor']),strtoupper((string)$c['receipt_reference'])])===null,
            'The supplier invoice already appears in a DV',409);
        if(in_array(($c['procurement_source_type']??''),['PR','PO'],true))
            self::procurementSource($db,$org,(string)$c['procurement_source_type'],
                (int)$c['procurement_source_id'],(int)$c['fund_id'],(string)$c['purchase_date'],
                (string)$c['category'],moneyCents((string)$c['claimed_amount']),true,$id);
        demand(one($db,'SELECT id FROM funds WHERE id=? AND organization_id=?',[(int)$c['fund_id'],$org])!==null,
            'Claim fund no longer belongs to this office',409);
        demand(one($db,'SELECT id FROM employees WHERE id=? AND full_name=?',[(int)$c['employee_id'],(string)$c['employee_name']])!==null,
            'Claim employee identity has changed',409);
        return ['claim'=>$c,'review'=>$review,'amount'=>$approved];
    }
    public static function dvSources(PDO $db,int $org,int $dv): array {
        $rows=q($db,'SELECT config_json FROM configuration_versions WHERE organization_id=? AND LEFT(config_key,5)=? ORDER BY id DESC LIMIT 500',
            [$org,self::LINK])->fetchAll(PDO::FETCH_ASSOC);
        $out=[];$seen=[];
        foreach($rows as $r){
            $v=json_decode((string)$r['config_json'],true,32,JSON_THROW_ON_ERROR);
            if(!is_array($v) || (int)($v['dv_id']??0)!==$dv)continue;
            $id=(int)($v['claim_id']??0);
            if($id<=0 || isset($seen[$id]))continue;
            $seen[$id]=true;$claim=self::claim($db,$org,$id);$review=self::review($db,$org,$id);
            $out[]=['claim_id'=>$id,'allocation_line_id'=>(int)($v['allocation_line_id']??0),
                'claim_number'=>'ER-'.$id,'category'=>$claim['category'],
                'description'=>$claim['description'],'vendor'=>$claim['vendor'],
                'receipt_reference'=>$claim['receipt_reference'],
                'procurement_source_type'=>$claim['procurement_source_type']??'',
                'procurement_source_number'=>$claim['procurement_source_number']??'',
                'claimed_amount'=>$claim['claimed_amount'],
                'allowed_amount'=>$review['allowed_amount']??'0.00',
                'finalized_reference'=>(ReimbursementFinalization::load($db,$org,'O',$id)['reference']??''),
                'debit_uacs_code'=>(ReimbursementFinalization::load($db,$org,'O',$id)['debit_uacs_code']??''),
                'debit_items'=>(ReimbursementFinalization::load($db,$org,'O',$id)['debit_items']??[]),
                'evidence_reference'=>$claim['acceptance_reference']?:$claim['payment_reference']];
        }
        return $out;
    }
    /** Validate ALL reserved out-of-pocket sources of an existing DRAFT DV.
     * Unlike `eligible`, the reservations MUST already point to this DV.
     * No source, review, amount, payer or tax is editable at payment stage.
     * Returns the allocation IDs and final verified claim amount.
     */
    public static function validateDvForPayment(PDO $db,int $org,int $dv,int $fund,string $payee): array {
        $rows=q($db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=?
            AND LEFT(config_key,5)=? ORDER BY id ASC LIMIT 500',[$org,self::LINK])->fetchAll(PDO::FETCH_ASSOC);
        $amount=0;$allocationIds=[];$claimIds=[];
        foreach($rows as $row){
            $link=json_decode((string)$row['config_json'],true,32,JSON_THROW_ON_ERROR);
            demand(is_array($link),'Invalid employee claim reservation record',409);
            if((int)($link['dv_id']??0)!==$dv)continue;
            $id=(int)($link['claim_id']??0);
            demand($id>0 && !isset($claimIds[$id]),'Duplicated employee claim reservation; finance review required',409);
            $claimIds[$id]=true;
            $active=self::reservation($db,$org,$id);
            demand($active!==null && $active['active'] && $active['dv_id']===$dv &&
                $active['link_id']===(int)$row['id'],'Employee claim reservation changed after DV preparation',409);
            $claim=self::claim($db,$org,$id,true);
            $review=self::review($db,$org,$id);
            $final=ReimbursementFinalization::load($db,$org,self::FINAL_KIND,$id);
            if($final!==null)self::checkFinalItems($db,$org,$claim,$final,
                moneyCents((string)($review['allowed_amount']??'0.00')));
            demand($review!==null && in_array($review['decision']??'',['ALLOWED','PARTIALLY_ALLOWED'],true),
                'Employee claim documentary review is no longer eligible',409);
            demand(($review['reviewed_papers']??false)===true && ($review['not_previously_paid']??false)===true &&
                (int)($review['reviewed_by']??0)>0,
                'Employee reimbursement source requires documented paper and prior-payment review',409);
            $allowed=moneyCents((string)($review['allowed_amount']??'0.00'));
            $claimed=moneyCents((string)($claim['claimed_amount']??'0.00'));
            demand($allowed>0 && $allowed<=$claimed &&
                (int)($claim['fund_id']??0)===$fund && (string)($claim['employee_name']??'')===$payee &&
                ($claim['receipt_confirmed']??false)===true && ($claim['paid_by_employee']??false)===true &&
                ($claim['authorized_purchase']??false)===true && ($claim['no_supplier_dv']??false)===true,
                'Employee reimbursement amount, identity, fund or source evidence needs review',409);
            if(($claim['category']??'')==='SUPPLIES')
                demand(($claim['goods_accepted']??false)===true && trim((string)($claim['acceptance_reference']??''))!=='',
                    'Accepted supplies and recorded IAR evidence required',409);
            if(in_array(($claim['procurement_source_type']??''),['PR','PO'],true)){
                $proof=self::procurementSource($db,$org,(string)$claim['procurement_source_type'],
                    (int)($claim['procurement_source_id']??0),$fund,(string)$claim['purchase_date'],
                    (string)$claim['category'],$claimed,true,$id);
                demand(($claim['procurement_source_number']??'')===$proof['source_number'],
                    'Source PR/PO changed; finalization requires finance review',409);
            }
            $employee=one($db,'SELECT full_name FROM employees WHERE id=?',[(int)$claim['employee_id']]);
            demand($employee!==null && (string)$employee['full_name']===$payee,
                'Reimbursement employee no longer matches original claim',409);
            $duplicateSupplier=one($db,'SELECT id FROM disbursement_vouchers WHERE organization_id=?
                AND UPPER(TRIM(payee))=? AND UPPER(TRIM(bill_reference))=? LIMIT 1',
                [$org,strtoupper((string)$claim['vendor']),strtoupper((string)$claim['receipt_reference'])]);
            demand($duplicateSupplier===null,'Supplier invoice has another DV. Employee cannot be paid twice',409);
            $allocationId=(int)($link['allocation_line_id']??0);
            demand($allocationId>0 && !isset($allocationIds[$allocationId]),
                'Invalid/repeated employee DV allocation reference',409);
            $line=one($db,'SELECT id,amount,pr_id,iar_line_id,advance_id FROM dv_allocation_lines WHERE id=? AND dv_id=? FOR UPDATE',
                [$allocationId,$dv]);
            demand($line!==null && moneyCents((string)$line['amount'])===$allowed &&
                $line['pr_id']===null && $line['iar_line_id']===null && $line['advance_id']===null,
                'Employee reimbursement allocation was changed after source reservation',409);
            $allocationIds[$allocationId]=true;$amount+=$allowed;
        }
        return ['claim_ids'=>array_keys($claimIds),'allocation_ids'=>array_keys($allocationIds),
            'amount_cents'=>$amount];
    }

    /** Call only under the same transaction and organization lock as draft DV creation. */
    public static function reserve(PDO $db,int $org,int $claimId,int $dv,int $allocationId,int $actor): void {
        $last=self::reservation($db,$org,$claimId);
        demand(!$last || !$last['active'],'This non-travel claim is already linked to an active DV',409);
        $key=self::LINK.$claimId.'-'.$dv;
        demand(one($db,'SELECT id FROM configuration_versions WHERE organization_id=? AND config_key=?',[$org,$key])===null,
            'This reimbursement is already reserved in the selected voucher',409);
        $payload=['kind'=>'DRAFT_RESERVATION','claim_id'=>$claimId,'dv_id'=>$dv,'allocation_line_id'=>$allocationId,
            'recorded_by'=>$actor,'no_payment_authorized'=>true];
        q($db,'INSERT INTO configuration_versions (organization_id,config_key,config_json,effective_from,review_note) VALUES (?,?,?,?,?)',
            [$org,$key,json_encode($payload,JSON_THROW_ON_ERROR),self::today(),
             'Claim source attached to one DRAFT employee DV; not payable']);
        self::audit($db,$org,$actor,'EMPLOYEE_CLAIM_RESERVED',$claimId,['dv_id'=>$dv,'allocation_line_id'=>$allocationId]);
    }
}
