<?php
declare(strict_types=1);
/** Annex A / Appendix 62-inspired official IAR print, with wet-signature spaces.
 * Source-only rendering: no inventory mutations, no inferred historic date or signatory.
 */
final class IarAppendix62 {
    public static function load(PDO $db,int $org,int $id): array {
        $iar=one($db,"SELECT id,po_id,status,display_number,delivered_at,accepted_at FROM iars WHERE organization_id=? AND id=?",[$org,$id]);
        demand($iar!==null && $iar['status']==='FINALIZED','Finalized IAR unavailable',404);
        $version=one($db,"SELECT version_no,snapshot_json FROM document_versions WHERE organization_id=? AND doc_type='IAR' AND entity_id=? ORDER BY version_no DESC LIMIT 1",[$org,$id]);
        demand($version!==null,'Original IAR document history unavailable',409);
        $snap=json_decode((string)$version['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($snap)&&($snap['number']??null)===$iar['display_number'] &&
            (int)($snap['po']??0)===(int)$iar['po_id'] && is_array($snap['lines']??null),
            'IAR source version requires document review',409);
        $po=PoDocuments::load($db,$org,(int)$iar['po_id']);
        $source=one($db,"SELECT po.pr_id,pr.requesting_unit,pr.display_number AS pr_number,
            po.supplier_id, s.name AS master_supplier_name,s.address AS master_supplier_address,
            f.source_code AS fund_source_code,f.name AS fund_name
            FROM purchase_orders po JOIN purchase_requests pr ON pr.id=po.pr_id AND pr.organization_id=po.organization_id
            JOIN funds f ON f.id=pr.fund_id AND f.organization_id=po.organization_id
            JOIN suppliers s ON s.id=po.supplier_id
            WHERE po.id=? AND po.organization_id=?",[$iar['po_id'],$org]);
        demand($source!==null,'IAR-linked PO and PR are unavailable',409);
        $lines=q($db,"SELECT il.id,il.po_line_id,il.delivered_qty,il.accepted_qty,il.rejected_qty,pl.pr_line_id,prl.description_snapshot AS description,prl.unit_snapshot AS unit FROM iar_lines il JOIN po_lines pl ON pl.id=il.po_line_id JOIN pr_lines prl ON prl.id=pl.pr_line_id WHERE il.iar_id=? ORDER BY il.id LIMIT 101",[$id])->fetchAll();
        demand(count($lines)>0&&count($lines)<=100,'IAR item count requires review',409);
        // Inspect the ENTIRE linked PO, not only this IAR's delivered lines.
        // Earlier FINALIZED IARs may have received other quantities or PO items.
        // IMPORTANT: exclude later IARs so an old partial IAR is never
        // retroactively printed as complete after a later receipt.
        // This is a read-only classification for the printed checkboxes.
        // Canceled IARs are excluded; no stock or accounting data is altered.
        $poCoverage=q($db,"SELECT pl.id AS po_line_id, pl.quantity AS ordered_qty,
                COALESCE(SUM(CASE WHEN ir.organization_id=? AND ir.po_id=? AND ir.id<=? AND ir.status='FINALIZED' THEN il.delivered_qty ELSE 0 END),0) AS delivered_total,
                COALESCE(SUM(CASE WHEN ir.organization_id=? AND ir.po_id=? AND ir.id<=? AND ir.status='FINALIZED' THEN il.accepted_qty ELSE 0 END),0) AS accepted_total
            FROM po_lines pl
            LEFT JOIN iar_lines il ON il.po_line_id=pl.id
            LEFT JOIN iars ir ON ir.id=il.iar_id
            WHERE pl.po_id=?
            GROUP BY pl.id,pl.quantity ORDER BY pl.id LIMIT 101",[$org,$iar['po_id'],$id,$org,$iar['po_id'],$id,$iar['po_id']])->fetchAll();
        demand(count($poCoverage)>0&&count($poCoverage)<=100,'Linked PO item count requires review',409);
        $checkboxState=self::checkboxState($lines,$poCoverage);
        $known=[];foreach($snap['lines'] as $row){$known[(int)($row['id']??0)]=$row;}
        demand(count($known)===count($lines),'IAR original line history mismatch',409);
        foreach($lines as $line){
            $old=$known[(int)$line['id']]??null;
            demand(is_array($old) && (int)($old['po_line_id']??0)===(int)$line['po_line_id'] &&
                qtyMillis($old['delivered_qty']??'')===qtyMillis($line['delivered_qty']) &&
                qtyMillis($old['accepted_qty']??'')===qtyMillis($line['accepted_qty']),
                'IAR posted quantities do not match the original document; review required',409);
        }
        $entity=one($db,'SELECT name FROM organizations WHERE id=?',[$org]);
        // Requested IAR Responsibility Center Code is the PO/PR's recorded source of fund.
        // A free-text PR responsibility code is not the same as the source of fund.
        $responsibility=trim((string)($po['fund']['source_code']??$source['fund_source_code']??''));
        if($responsibility==='')$responsibility=trim((string)($po['fund']['name']??$source['fund_name']??''));
        $supplier=$po['supplier']??[];
        $poDate=(string)($po['document_date']??'');
        $dateSource='ISSUED_DOCUMENT';
        $isLegacy=!empty($po['legacy']);
        if($isLegacy){
            // A legacy PO never stored frozen Appendix 61 supplier details.
            // The linked supplier ID is authoritative for identity, but today's
            // address is only today's master value, not a historical PO snapshot.
            $supplier=['id'=>(int)$source['supplier_id'],
                'name'=>(string)($source['master_supplier_name']??''),
                'address'=>(string)($source['master_supplier_address']??'')];
            $poDateInfo=ProcurementDocumentDates::poDate($db,$org,(int)$iar['po_id']);
            $poDate=(string)$poDateInfo['date'];
            $dateSource=(string)$poDateInfo['source'];
        }
        $fundCluster=trim((string)($po['fund_cluster']??''));
        if($fundCluster===''){
            $prVersion=one($db,"SELECT snapshot_json FROM document_versions WHERE organization_id=? AND doc_type='PR' AND entity_id=? ORDER BY version_no DESC LIMIT 1",[$org,$source['pr_id']]);
            $prSnapshot=$prVersion?json_decode((string)$prVersion['snapshot_json'],true):null;
            if(is_array($prSnapshot))$fundCluster=trim((string)($prSnapshot['header']['fund_cluster']??''));
        }
        $signatories=$snap['iar_signatories']??null;
        if(!is_array($signatories)){
            require_once __DIR__.'/IarSignatories.php';
            $asOf=trim((string)($snap['iar_date']??''));
            if($asOf==='')$asOf=(string)$iar['accepted_at'];
            $signatories=IarSignatories::resolve($db,$org,$asOf);
        }elseif(empty($signatories['bac_chair'])){
            // An authentic later-recorded BAC date correction may explain a
            // previously blank historical print. Never change the saved IAR version.
            require_once __DIR__.'/IarSignatories.php';
            $asOf=trim((string)($snap['iar_date']??''));
            if($asOf==='')$asOf=(string)$iar['accepted_at'];
            $corrected=IarSignatories::correctedBacChair($db,$org,$asOf);
            if($corrected!==null)$signatories['bac_chair']=$corrected;
        }
        return ['number'=>(string)$iar['display_number'],'version'=>(int)$version['version_no'],
            'iar_date'=>(string)($snap['iar_date']??''),'delivered_at'=>(string)$iar['delivered_at'],
            'accepted_at'=>(string)$iar['accepted_at'],'invoice_no'=>(string)($snap['invoice_no']??''),
            'invoice_date'=>(string)($snap['invoice_date']??''),
            'inspection_chair'=>$signatories['bac_chair']??null,
            'supply_custodian'=>$signatories['supply_custodian']??null,
            'entity'=>(string)($entity['name']??''),
            'fund_cluster'=>$fundCluster,'supplier'=>$supplier,'legacy_supplier_source'=>$isLegacy,'po_date_source'=>$dateSource,
            'po_number'=>(string)($po['number']??''),'po_date'=>$poDate,
            'requesting_unit'=>(string)($source['requesting_unit']??''),
            'responsibility_center'=>$responsibility,'lines'=>$lines,'checkbox_state'=>$checkboxState,
            'downstream_discrepancy'=>(bool)($snap['downstream_date_reconciliation_required']??false)];
    }
    /** Derive factual IAR ticks from posted quantities (not from a new approval).
     * Complete means every linked PO line has been cumulatively RECEIVED AND
     * ACCEPTED through finalized IARs. Accepted=delivered within this IAR
     * permits the inspection 'found in order' tick, even if the PO is partial.
     * Missing/mismatched source quantities fail closed: no checked boxes.
     */
    public static function checkboxState(array $iarLines,array $poCoverage): array {
        $unknown=['inspected'=>false,'complete'=>false,'partial'=>false,'label'=>'Requires quantity review'];
        if(!$iarLines||!$poCoverage)return $unknown;
        $lineIds=[];$inspected=true;
        foreach($iarLines as $line){
            $id=(int)($line['po_line_id']??0);
            if($id<=0||isset($lineIds[$id]))return $unknown;
            $lineIds[$id]=true;
            try {
                $del=qtyMillis($line['delivered_qty']??'0');
                $acc=qtyMillis($line['accepted_qty']??'0');
                $rej=qtyMillis($line['rejected_qty']??'0');
            } catch(Throwable $e){return $unknown;}
            if($del<=0||$acc<0||$acc>$del||$rej<0||$rej!==($del-$acc))return $unknown;
            // Rejected or nonaccepted delivery must not be called 'found in order'.
            if($rej>0||$acc!==$del)$inspected=false;
        }
        $coverageIds=[];$complete=true;
        foreach($poCoverage as $line){
            $id=(int)($line['po_line_id']??0);
            if($id<=0||isset($coverageIds[$id]))return $unknown;
            $coverageIds[$id]=true;
            try {
                $ordered=qtyMillis($line['ordered_qty']??'0');
                $delivered=qtyMillis($line['delivered_total']??'0');
                $accepted=qtyMillis($line['accepted_total']??'0');
            } catch(Throwable $e){return $unknown;}
            if($ordered<=0||$delivered<0||$accepted<0||$accepted>$delivered||$delivered>$ordered)return $unknown;
            if($accepted<$ordered)$complete=false;
        }
        foreach($lineIds as $id=>$unused){if(!isset($coverageIds[$id]))return $unknown;}
        return ['inspected'=>$inspected,'complete'=>$complete,'partial'=>!$complete,
            'label'=>$complete?'Complete PO acceptance':'Partial PO acceptance'];
    }
    private static function qty(mixed $v): string {
        $n=qtyMillis($v);$s=qtyString($n);return str_ends_with($s,'.000')?substr($s,0,-4):rtrim(rtrim($s,'0'),'.');
    }
    public static function render(array $d): string {
        $e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $date=static function(mixed $v):string { $s=trim((string)$v);return preg_match('/^\d{4}-\d{2}-\d{2}$/',$s)?substr($s,5,2).'/'.substr($s,8,2).'/'.substr($s,0,4):''; };
        $supplier=is_array($d['supplier']??null)?$d['supplier']:[];
        $chair=is_array($d['inspection_chair']??null)?trim((string)($d['inspection_chair']['name']??'')):'';
        $custodian=is_array($d['supply_custodian']??null)?trim((string)($d['supply_custodian']['name']??'')):'';
        // The source loader includes cumulative PO coverage. Do not infer
        // 'Complete' from this report's accepted/delivered ratio alone.
        $state=is_array($d['checkbox_state']??null)?$d['checkbox_state']:[];
        $inspectedTick=($state['inspected']??false)===true?' checked':'';
        $completeTick=($state['complete']??false)===true?' checked':'';
        $partialTick=($state['partial']??false)===true?' checked':'';
        $rows='';
        foreach($d['lines'] as $i=>$line){
            $rows.='<tr><td class="seq">'.($i+1).'</td><td class="desc">'.$e($line['description']??'').'</td><td class="unit">'.$e($line['unit']??'').'</td><td class="qty">'.$e(self::qty($line['delivered_qty']??'0')).'</td></tr>';
        }
        // A modest number of blank rows matches the sample's ruled space without forcing extra A4 pages.
        $padding=max(0,min(12,16-count($d['lines'])));
        for($i=0;$i<$padding;$i++)$rows.='<tr class="empty"><td>&nbsp;</td><td></td><td></td><td></td></tr>';
        $classification=(string)($state['label']??'Requires quantity review');
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        .'<title>IAR '.$e($d['number']).'</title><style>
        *{box-sizing:border-box}body{max-width:770px;margin:14px auto;padding:4px 10px;font:10.7px "Bookman Old Style",Georgia,serif;color:#080808;line-height:1.24}.tools{text-align:right;font:13px Arial}.tools button{padding:5px 12px}
        .appendix{display:flex;justify-content:space-between;font-style:italic;font-size:9px}.heading{text-align:center;margin:17px 0 13px;font-size:14px;font-weight:700;letter-spacing:.2px}
        .entity{display:flex;gap:20px;margin:0 0 3px}.entity .label{font-weight:700}.entity .name{flex:1;font-weight:700}.entity .fund{width:40%}
        table{width:100%;border-collapse:collapse;table-layout:fixed}td,th{border:1px solid #111;vertical-align:middle;padding:2px 4px}td{overflow-wrap:anywhere}.top td{border-bottom:0;padding:1px 4px}.top td:first-child{border-right:0}.top td:last-child{border-left:0}.split{display:grid;grid-template-columns:61% 39%;border:1px solid #111;border-bottom:0}.split > div{padding:2px 4px}.split > div:first-child{border-right:1px solid #111}
        .meta-line{display:grid;grid-template-columns:87px 1fr;gap:2px}.meta-line .v{font-weight:700}.items th{text-align:center;font-style:italic}.items .seq{width:17%;text-align:center}.items .desc{width:45%}.items .unit{width:15%;text-align:center}.items .qty{width:23%;text-align:center}.items .empty td{height:17px}
        .signs{display:grid;grid-template-columns:1fr 1fr;border:1px solid #111;border-top:0}.signs>div+div{border-left:1px solid #111}.sign-title{text-align:center;border-bottom:1px solid #111;font-weight:700;padding:3px}.sign-date{display:grid;grid-template-columns:auto 1fr;border-bottom:1px solid #111}.sign-date span{padding:6px 5px}.sign-date span:first-child{border-right:1px solid #111;font-weight:700}.sign-body{min-height:125px;padding:7px 8px;position:relative}.choice{display:flex;align-items:start;gap:6px;margin:2px 0 4px}.square{width:15px;height:15px;border:1px solid #111;flex-shrink:0;position:relative}.square.checked::after{content:"";position:absolute;left:4px;top:0;width:5px;height:10px;border:solid #111;border-width:0 2px 2px 0;transform:rotate(45deg)}.choice .words{font-size:9.1px}.sign-line{text-align:center;margin-top:30px;font-weight:700}.person-name{text-align:center;font-weight:700;margin:18px auto 5px;border-bottom:1px solid #111;width:78%;min-height:13px}.role{text-align:center;font-style:italic;font-size:9px}.accept-name{margin-top:38px;text-align:center}.blankname{border-bottom:1px solid #111;width:75%;margin:25px auto 4px}.small{font-size:8px}.note{font-size:8px;margin:4px 0 0}
        @media print{@page{size:A4 portrait;margin:9mm 10mm}body{max-width:none;margin:0;padding:0}.tools,.note{display:none}.items thead{display:table-header-group}.items tr{break-inside:avoid}.signs{break-inside:avoid}.heading{margin-top:14px}}
        </style></head><body>'
        .'<div class="tools"><button type="button" onclick="window.print()">Print</button></div>'
        .'<div class="appendix"><span>Annex A</span><span>Appendix 62</span></div><div class="heading">INSPECTION AND ACCEPTANCE REPORT</div>'
        .'<div class="entity"><span class="label">Entity Name:</span><span class="name">'.$e($d['entity']).'</span><span class="fund">Fund Cluster: '.$e($d['fund_cluster']??'').'</span></div>'
        .'<div class="split"><div>'
        .'<div class="meta-line"><span>Supplier:</span><span class="v">'.$e($supplier['name']??'').'</span></div>'
        .'<div class="meta-line"><span>Address:</span><span class="v">'.$e($supplier['address']??'').'</span></div>'
        .'<div class="meta-line"><span>PO No. / Date:</span><span class="v">'.$e($d['po_number']).' / '.$e($date($d['po_date']??'')).(!empty($d['po_date_source'])&&$d['po_date_source']==='RECORDED_ONLY'?' (recorded)':'').'</span></div>'
        .'<div class="meta-line"><span>Requisitioning Office / Dept:</span><span>'.$e($d['requesting_unit']??'').'</span></div>'
        .'<div class="meta-line"><span>Responsibility Center Code:</span><span>'.$e($d['responsibility_center']??'').'</span></div>'
        .'</div><div>'
        .'<div class="meta-line"><span>IAR No.:</span><span class="v">'.$e($d['number']).'</span></div>'
        .'<div class="meta-line"><span>IAR Date:</span><span class="v">'.$e($date($d['iar_date']??'')).'</span></div>'
        .'<div class="meta-line"><span>Invoice No.:</span><span class="v">'.$e($d['invoice_no']??'').'</span></div>'
        .'<div class="meta-line"><span>Invoice Date:</span><span class="v">'.$e($date($d['invoice_date']??'')).'</span></div>'
        .'</div></div>'
        .'<table class="items"><colgroup><col style="width:17%"><col style="width:45%"><col style="width:15%"><col style="width:23%"></colgroup><thead><tr><th>Stock /<br> Property No.</th><th>Description</th><th>Unit</th><th>Quantity</th></tr></thead><tbody>'.$rows.'</tbody></table>'
        .'<div class="signs"><div><div class="sign-title">INSPECTION</div><div class="sign-date"><span>Date Inspected:</span><span style="text-align:center;font-weight:bold">'.$e($date($d['accepted_at'])).'</span></div>'
        .'<div class="sign-body"><div class="choice"><span class="square'.$inspectedTick.'"></span><span class="words">Inspected, verified and found in order as to quantity and specification</span></div>'
        .'<div class="person-name">'.$e($chair).'&nbsp;</div><div class="role">Chairman, Inspection Committee</div><div class="blankname"></div><div class="role">Member</div><div class="blankname"></div><div class="role">End-User as Inspector</div></div></div>'
        .'<div><div class="sign-title">ACCEPTANCE</div><div class="sign-date"><span>Date Received:</span><span style="text-align:center;font-weight:bold">'.$e($date($d['delivered_at'])).'</span></div>'
        .'<div class="sign-body"><div class="choice"><span class="square'.$completeTick.'"></span><span>Complete</span></div><div class="choice"><span class="square'.$partialTick.'"></span><span>Partial (pls. specify quantity)</span></div>'
        .'<div style="border-bottom:1px solid #111;width:75%;margin-left:23px;margin-top:15px"></div><div class="accept-name"><div class="person-name">'.$e($custodian).'&nbsp;</div><div class="role">Supply and/or Property Custodian</div></div></div></div></div>'
        .'<p class="note">'.(empty($d['iar_date'])?'IAR Date was not recorded in this earlier report; the system has not invented one. ':'').(!empty($d['legacy_supplier_source'])?'Legacy PO: supplier identity/address are retrieved from the current supplier registry, not an original PO snapshot. Verify against the actual invoice before signing. ':'').'Receipt classification from recorded PO/IAR quantities: '.$e($classification).'. Tick marks reflect system-recorded quantities; authorized inspection and acceptance signatures must be handwritten. No electronic approval is implied.</p>'
        .'</body></html>';
    }
}
