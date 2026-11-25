<?php
declare(strict_types=1);
/**
 * Phase 3A: READ-ONLY evidence-chain review, not a historical accounting stamp.
 * Existing PR snapshots do NOT consistently contain the Division property/UACS
 * decisions. Never reconstruct a historical approval from today's configuration.
 * Nothing here inserts, edits, posts, signs or (re)classifies a record.
 */
final class PropertyLineageReview {
    private const MAX_LINES = 120;
    private const MAX_CHILDREN = 2500;
    public function __construct(private PDO $db, private array $user) {}

    public function review(array $p): array {
        $org=idOf($p,'organization_id');$prId=idOf($p,'pr_id');
        scope($this->db,$this->user,$org);
        $pr=one($this->db,'SELECT id,organization_id,display_number,status,document_date FROM purchase_requests WHERE id=? AND organization_id=?',[$prId,$org]);
        demand($pr!==null,'PR not found in the selected organization',404);
        $lines=q($this->db,'SELECT l.id AS pr_line_id,l.item_id,l.description_snapshot,l.unit_snapshot,l.quantity,l.estimated_unit_price,i.category AS current_catalog_category FROM pr_lines l JOIN item_catalog i ON i.id=l.item_id WHERE l.pr_id=? ORDER BY l.id',[$prId])->fetchAll();
        demand(count($lines)>0 && count($lines)<=self::MAX_LINES,'PR has no lines or exceeds the review limit',409);
        $orders=q($this->db,'SELECT pol.id AS po_line_id,pol.pr_line_id,pol.item_id,pol.quantity,pol.unit_price,po.id AS po_id,po.display_number AS po_number,po.status AS po_status FROM po_lines pol JOIN purchase_orders po ON po.id=pol.po_id JOIN purchase_requests pr ON pr.id=po.pr_id AND pr.organization_id=po.organization_id WHERE po.pr_id=? AND po.organization_id=? ORDER BY po.id,pol.id',[$prId,$org])->fetchAll();
        $receipts=q($this->db,'SELECT il.id AS iar_line_id,il.po_line_id,il.accepted_qty,il.delivered_qty,il.accepted_amount,ir.id AS iar_id,ir.display_number AS iar_number,ir.status AS iar_status,ir.accepted_at FROM iar_lines il JOIN iars ir ON ir.id=il.iar_id JOIN purchase_orders po ON po.id=ir.po_id AND po.organization_id=ir.organization_id WHERE po.pr_id=? AND ir.organization_id=? ORDER BY ir.id,il.id',[$prId,$org])->fetchAll();
        $assets=q($this->db,'SELECT a.id AS asset_id,a.iar_line_id,a.item_id,a.property_tag,a.acquisition_cost,a.status AS asset_status FROM assets a JOIN iar_lines il ON il.id=a.iar_line_id JOIN iars ir ON ir.id=il.iar_id JOIN purchase_orders po ON po.id=ir.po_id AND po.organization_id=ir.organization_id WHERE po.pr_id=? AND a.organization_id=? AND ir.organization_id=? ORDER BY a.id',[$prId,$org,$org])->fetchAll();
        $accountability=q($this->db,'SELECT ad.id AS accountability_id,ad.asset_id,ad.doc_type,ad.display_number,ad.status FROM accountability_documents ad JOIN assets a ON a.id=ad.asset_id AND a.organization_id=ad.organization_id JOIN iar_lines il ON il.id=a.iar_line_id JOIN iars ir ON ir.id=il.iar_id JOIN purchase_orders po ON po.id=ir.po_id AND po.organization_id=ir.organization_id WHERE po.pr_id=? AND ad.organization_id=? AND ir.organization_id=? ORDER BY ad.id',[$prId,$org,$org])->fetchAll();
        foreach([$orders,$receipts,$assets,$accountability] as $group)demand(count($group)<=self::MAX_CHILDREN,'Too many linked records for this preview. Request a paginated audit report.',409);
        $latest=one($this->db,"SELECT version_no,snapshot_json,created_at FROM document_versions WHERE organization_id=? AND doc_type='PR' AND entity_id=? ORDER BY version_no DESC LIMIT 1",[$org,$prId]);
        $snapshot=null;
        if($latest!==null){
            try{$snapshot=json_decode((string)$latest['snapshot_json'],true,512,JSON_THROW_ON_ERROR);}catch(Throwable $e){$snapshot=null;}
        }
        return self::assemble($pr,$lines,$orders,$receipts,$assets,$accountability,$snapshot,$latest);
    }

    /** For retrospective WARNINGS only; not a classification decision. */
    private static function estimatedBand(string $price,string $date): string {
        if($date<'2022-01-01')return 'HISTORICAL_REVIEW';
        if(!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D',$price))return 'INVALID';
        try{$cents=moneyCents($price);}catch(Throwable $e){return 'INVALID';}
        return $cents>=5000000?'PPE_REVIEW':($cents>500000?'HV_REVIEW':'LV_REVIEW');
    }
    public static function assemble(array $pr,array $lines,array $orders,array $receipts,array $assets,array $docs,?array $snapshot,?array $version): array {
        $alerts=[];$byLine=[];
        foreach($lines as $l){
            $id=(int)$l['pr_line_id'];
            demand($id>0 && !isset($byLine[$id]),'Duplicate PR line identity; audit required',409);
            $byLine[$id]=['pr_line_id'=>$id,'item_id'=>(int)$l['item_id'],
                'description'=>(string)$l['description_snapshot'],'unit'=>(string)$l['unit_snapshot'],
                'requested_qty'=>(string)$l['quantity'],'estimated_unit_price'=>(string)$l['estimated_unit_price'],
                'current_category'=>(string)$l['current_catalog_category'],
                'orders'=>[],'warnings'=>[]];
        }
        $snapshotMatches=false;$immutableMapping=false;
        if(is_array($snapshot) && is_array($snapshot['lines']??null)){
            $original=[];
            foreach($snapshot['lines'] as $l){if(!is_array($l) || !isset($l['id']))continue;
                $original[(int)$l['id']]=[(int)($l['item_id']??0),(string)($l['quantity']??''),(string)($l['estimated_unit_price']??'')];}
            $current=[];foreach($lines as $l)$current[(int)$l['pr_line_id']]=[(int)$l['item_id'],(string)$l['quantity'],(string)$l['estimated_unit_price']];
            // Historical display snapshot is verified independently of today's
            // registry/UACS mappings; text cannot be promoted to signed authority.
            $snapshotMatches=$original===$current;
            $immutableMapping=isset($snapshot['property_accounting_context'])&&is_array($snapshot['property_accounting_context']);
        }
        if(!$snapshotMatches)$alerts[]='Latest recorded PR line snapshot is missing or differs from current lines. Verify document revisions before relying on this chain.';
        if(!$immutableMapping)$alerts[]='The PR snapshot does not contain an immutable Division property/UACS decision. Do not treat current mappings as historical classification.';
        $poMap=[];
        foreach($orders as $r){
            $line=(int)$r['pr_line_id'];$poLine=(int)$r['po_line_id'];
            if(!isset($byLine[$line])||isset($poMap[$poLine])){ $alerts[]='PO contains an orphan or duplicate line reference; manual audit required.';continue; }
            $poMap[$poLine]=['pr_line_id'=>$line,'po_id'=>(int)$r['po_id'],'item_id'=>(int)$r['item_id'],
                'quantity'=>(string)$r['quantity'],'unit_price'=>(string)$r['unit_price']];
            $byLine[$line]['orders'][$poLine]=['po_line_id'=>$poLine,'po_id'=>(int)$r['po_id'],
                'number'=>(string)($r['po_number']??''),'status'=>(string)$r['po_status'],
                'ordered_qty'=>(string)$r['quantity'],'actual_unit_price'=>(string)$r['unit_price'],
                'receipts'=>[],'warnings'=>[]];
            if((int)$r['item_id']!==$byLine[$line]['item_id'])
                $byLine[$line]['orders'][$poLine]['warnings'][]='PO item identity differs from the original PR line.';
            if((string)$r['unit_price']!==(string)$byLine[$line]['estimated_unit_price'])
                $byLine[$line]['orders'][$poLine]['warnings'][]='PO unit price differs from PR estimate; accounting bracket must be reconsidered at receipt.';
            if(in_array($byLine[$line]['current_category'],['PPE','SEMI_EXPENDABLE'],true)){
                $date=(string)($pr['document_date']??'');
                $first=self::estimatedBand((string)$byLine[$line]['estimated_unit_price'],$date);
                $second=self::estimatedBand((string)$r['unit_price'],$date);
                if(!in_array('INVALID',[$first,$second],true) && $first!==$second)
                    $byLine[$line]['orders'][$poLine]['warnings'][]='PO price crosses the preliminary cost threshold from '.$first.' to '.$second.'; final treatment requires human review.';
            }

        }
        $iarMap=[];
        foreach($receipts as $r){
            $poLine=(int)$r['po_line_id'];$iarLine=(int)$r['iar_line_id'];
            if(!isset($poMap[$poLine])||isset($iarMap[$iarLine])){$alerts[]='IAR contains an orphan or duplicate PO-line reference; manual audit required.';continue;}
            $prLine=$poMap[$poLine]['pr_line_id'];$iarMap[$iarLine]=['pr_line_id'=>$prLine,'po_line_id'=>$poLine];
            $byLine[$prLine]['orders'][$poLine]['receipts'][$iarLine]=[
                'iar_line_id'=>$iarLine,'iar_id'=>(int)$r['iar_id'],
                'number'=>(string)($r['iar_number']??''),'status'=>(string)$r['iar_status'],
                'accepted_at'=>(string)($r['accepted_at']??''),'delivered_qty'=>(string)$r['delivered_qty'],
                'accepted_qty'=>(string)$r['accepted_qty'],'accepted_amount'=>(string)$r['accepted_amount'],
                'assets'=>[],'warnings'=>[]];
        }
        $assetMap=[];
        foreach($assets as $a){
            $iarLine=(int)$a['iar_line_id'];$assetId=(int)$a['asset_id'];
            if(!isset($iarMap[$iarLine])||isset($assetMap[$assetId])){$alerts[]='Asset contains an orphan or duplicate IAR reference; manual audit required.';continue;}
            $source=$iarMap[$iarLine];$li=$source['pr_line_id'];$poLi=$source['po_line_id'];
            $assetMap[$assetId]=['pr_line_id'=>$li,'po_line_id'=>$poLi,'iar_line_id'=>$iarLine];
            $byLine[$li]['orders'][$poLi]['receipts'][$iarLine]['assets'][$assetId]=[
                'asset_id'=>$assetId,'property_tag'=>(string)$a['property_tag'],
                'acquisition_cost'=>(string)($a['acquisition_cost']??''),'status'=>(string)$a['asset_status'],
                'accountability'=>[],'warnings'=>[]];
            if((int)$a['item_id']!==$byLine[$li]['item_id'])
                $byLine[$li]['orders'][$poLi]['receipts'][$iarLine]['assets'][$assetId]['warnings'][]='Asset item identity is inconsistent with PR.';
            $poPrice=(string)$poMap[$poLi]['unit_price'];$assetPrice=(string)($a['acquisition_cost']??'');
            if($assetPrice!=='' && self::estimatedBand($poPrice,'2022-01-01')!==self::estimatedBand($assetPrice,'2022-01-01'))
                $byLine[$li]['orders'][$poLi]['receipts'][$iarLine]['assets'][$assetId]['warnings'][]='Recorded asset cost crosses the PO unit-price classification bracket; inspect receipt and purchase-cost evidence.';

        }
        foreach($docs as $d){
            $assetId=(int)$d['asset_id'];if(!isset($assetMap[$assetId])){$alerts[]='Accountability record references an asset outside this chain.';continue;}
            $s=$assetMap[$assetId];$asset=&$byLine[$s['pr_line_id']]['orders'][$s['po_line_id']]['receipts'][$s['iar_line_id']]['assets'][$assetId];
            $kind=(string)$d['doc_type'];
            $asset['accountability'][]=['document_id'=>(int)$d['accountability_id'],'kind'=>$kind,
                'number'=>(string)($d['display_number']??''),'status'=>(string)$d['status']];
            $cat=$byLine[$s['pr_line_id']]['current_category'];
            if(($kind==='ICS' && $cat!=='SEMI_EXPENDABLE')||($kind==='PAR' && $cat!=='PPE'))
                $asset['warnings'][]='ICS/PAR type conflicts with the CURRENT catalog category; verify the category effective on issuance date.';
            unset($asset);
        }
        $out=[];foreach($byLine as $line){
            foreach($line['orders'] as &$po){
                foreach($po['receipts'] as &$ir){
                    foreach($ir['assets'] as &$asset){$asset['accountability']=array_values($asset['accountability']);}unset($asset);
                    $ir['assets']=array_values($ir['assets']);
                }unset($ir);$po['receipts']=array_values($po['receipts']);
            }unset($po);$line['orders']=array_values($line['orders']);$out[]=$line;
        }
        return ['stage'=>'DOCUMENTARY_LINEAGE_REVIEW_ONLY','organization_id'=>(int)($pr['organization_id']??0),'pr_id'=>(int)$pr['id'],
            'number'=>(string)($pr['display_number']??''),'status'=>(string)$pr['status'],
            'document_date'=>(string)($pr['document_date']??''),
            'pr_version'=>(int)($version['version_no']??0),'pr_snapshot_matches_current_lines'=>$snapshotMatches,
            'property_uacs_decision_in_snapshot'=>$immutableMapping,'lines'=>$out,
            'warnings'=>array_values(array_unique($alerts)),
            'notice'=>'Read-only documentary links, not accounting approval. This view does not establish the historical Division registry or UACS assignment unless a source-versioned decision was recorded. Physical wet signatures remain required.'];
    }
}
