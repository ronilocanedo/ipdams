<?php
declare(strict_types=1);
require_once __DIR__.'/TextbookCoReceiptWorkflow.php';

/**
 * CO textbook IAR: printable Annex A / Appendix 62-style document derived
 * exclusively from the audited TBR snapshot, never from procurement PO/IAR.
 * All numbers & amounts originate from server-side records. This is not an
 * electronic signature, or a second inventory posting.
 */
final class TextbookCoIar {
    public static function render(PDO $db,array $user,array $input):string {
        $org=idOf($input,'organization_id');
        $id=idOf($input,'receipt_id');
        $receipt=(new TextbookCoReceiptWorkflow($db,$user))->detail(['organization_id'=>$org,'receipt_id'=>$id]);
        $s=$receipt['snapshot'];
        $iar=trim((string)($s['iar_number']??''));
        demand($iar!=='' && preg_match('/^20\d{2}-(?:0[1-9]|1[0-2])-\d{4}$/D',$iar)===1,
            'This earlier receipt needs its linked CO textbook IAR generated first',409);
        $name=one($db,'SELECT name FROM organizations WHERE id=? AND active=1',[$org]);
        demand($name!==null,'Receiving office no longer exists',409);
        $accepted=in_array((string)$s['status'],['ACCEPTED','ACCEPTED_WITH_DISCREPANCY'],true);
        $inspected=in_array((string)$s['status'],['INSPECTED','ACCEPTED','ACCEPTED_WITH_DISCREPANCY','REJECTED'],true)
            && (trim((string)($s['inspection_officer_name']??''))!=='' || trim((string)($s['inspection_reference']??''))!=='');
        $damaged=0;$held=0;$shortage=0;$delivered=0;$serviceable=0;$expected=0;
        $rows='';
        foreach($s['lines'] as $i=>$l){
            $exp=(int)$l['expected_quantity'];$expected+=$exp;
            $real=$l['delivered_quantity']===null?null:(int)$l['delivered_quantity'];
            if($real!==null){
                $delivered+=$real;$serviceable+=(int)$l['serviceable_quantity'];
                $damaged+=(int)$l['damaged_quantity'];$held+=(int)$l['held_quantity'];
                $shortage+=max(0,$exp-$real);
            }
            $description=trim((string)$l['title']).' — ISBN '.(string)$l['isbn13'];
            $extra=array_filter([trim((string)($l['grade']??''))!==''?'Grade '.trim((string)$l['grade']):'',
                trim((string)($l['subject']??''))]);
            if($extra)$description.=' ('.implode(' · ',$extra).')';
            $rows.='<tr><td class="center">'.self::e($i+1).'</td><td>'.self::e($description).'</td>'
                .'<td class="center">copy</td><td class="center">'.self::e($real===null?'—':$real).'</td></tr>';
        }
        for($i=count($s['lines']);$i<min(12,max(8,count($s['lines'])));$i++)
            $rows.='<tr class="blank"><td>&nbsp;</td><td></td><td></td><td></td></tr>';
        $complete=$accepted && $expected===$delivered && $damaged===0 && $held===0;
        $status=(string)$s['status'];
        $inspectedAndInOrder=$inspected && $status!=='REJECTED' && $expected===$delivered && $damaged===0 && $held===0;
        $inspector=trim((string)($s['inspection_officer_name']??''));
        $inspectorDesignation=trim((string)($s['inspection_officer_designation']??''));
        $custodian=trim((string)($s['receiving_officer_name']??''));
        $custodianDesignation=trim((string)($s['receiving_officer_designation']??''));
        $employee=(int)($s['custodian_employee_id']??0);
        if($custodian==='' && $employee>0){
            $person=one($db,'SELECT full_name FROM employees WHERE id=?',[$employee]);
            $custodian=trim((string)($person['full_name']??''));
        }
        $inspectionDate=$inspected?(string)($s['inspected_on']??''):''; // never invent the historical actual inspection date
        $acceptedDate=$accepted?(string)($s['accepted_on']??''):'';
        $note=$accepted?'Physical receipt recorded on '.self::e(self::date($acceptedDate)).'; no digital approval/signature':
            'Document prepared automatically. Acceptance is not yet recorded; NO STOCK is credited by this printout.';
        $provenance=!empty($s['iar_created_after_receipt'])?
            '<p class="notice">This IAR number was issued after the original TBR was created. Original inspection/acceptance dates remain unchanged and require original signed evidence.</p>':'';
        $documentaryObservations=trim((string)($s['inspection_note']??''));
        $observationsHtml=$documentaryObservations!==''?
            '<p class="qty-note"><b>Inspection observations / notes:</b> '.self::e($documentaryObservations).'</p>':'';
        $shortageNote=$inspected?
            '<p class="qty-note">Physical inspection: expected <b>'.self::e($expected).'</b>, delivered <b>'.self::e($delivered).
            '</b>, serviceable <b>'.self::e($serviceable).'</b>, damaged/quarantined <b>'.self::e($damaged).
            '</b>, held/rejected <b>'.self::e($held).'</b>, shortage <b>'.self::e($shortage).'</b>.</p>':
            '<p class="qty-note">Expected copies: '.self::e($expected).'. Delivery counts and inspection are pending; quantities are not inferred.</p>';
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>CO Textbook IAR '.self::e($iar).'</title><style>
            *{box-sizing:border-box}body{max-width:780px;margin:15px auto;padding:8px 14px;color:#111;font:10.5px "Bookman Old Style",Georgia,serif;line-height:1.35}
            .tools{text-align:right;font:12px Arial;margin-bottom:14px}.tools button{border:1px solid #8ca1b0;background:#f8fbff;border-radius:4px;padding:7px 14px;cursor:pointer}
            .appendix{display:flex;justify-content:space-between;font-style:italic;font-size:9px}.heading{text-align:center;font-size:16px;font-weight:700;margin:13px 0}
            .sub{text-align:center;margin-bottom:11px;font-weight:bold}.meta{display:grid;grid-template-columns:1fr 1fr;gap:3px 16px;border:1px solid #222;padding:8px}.meta b{font-weight:bold}
            table{width:100%;border-collapse:collapse;table-layout:fixed;margin-top:0}td,th{border:1px solid #111;padding:4px;vertical-align:middle;overflow-wrap:anywhere}
            th{font-weight:700;text-align:center;background:#f7f8f9}.center{text-align:center}.blank td{height:19px}.qty-note{border:1px solid #111;border-top:0;margin:0;padding:7px}
            .signs{display:grid;grid-template-columns:1fr 1fr;border:1px solid #111;border-top:0}.signs>section{padding:9px 11px;min-height:174px}.signs>section+section{border-left:1px solid #111}
            .signs h3{text-align:center;font-size:11px;margin:0 0 7px;text-transform:uppercase}.check{width:14px;height:14px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #222;vertical-align:middle;margin-right:6px}
            .sig{margin-top:21px;text-align:center;border-bottom:1px solid #111;min-height:16px;font-weight:bold}.signs small{display:block;text-align:center;font-size:9px}
            .note,.notice{font-size:9px;color:#333;margin:8px 0 0}.notice{border-left:3px solid #999;padding:6px}.status{font-size:11px;font-weight:bold;text-align:center;padding:6px;border:1px solid #555;border-top:0;background:#f7f7f7}
            @media print{@page{size:A4 portrait;margin:10mm}body{max-width:none;margin:0;padding:0}.tools{display:none}.signs{break-inside:avoid}.blank td{height:18px}th{background:#fff}}
            @media(max-width:520px){.meta{grid-template-columns:1fr}.signs{grid-template-columns:1fr}.signs>section+section{border-left:0;border-top:1px solid #111}}
            </style></head><body><div class="tools"><button type="button" onclick="window.print()">Print IAR</button></div>'
            .'<div class="appendix"><span>Annex A</span><span>Appendix 62-style — CO Textbook Delivery</span></div>'
            .'<div class="heading">INSPECTION AND ACCEPTANCE REPORT</div><div class="sub">Central Office textbook delivery · linked to TBR '.self::e($receipt['number']).'</div>'
            .'<div class="meta"><div><b>Entity Name:</b> '.self::e($name['name']).'</div><div><b>IAR No.:</b> '.self::e($iar).'</div>'
            .'<div><b>Source:</b> Department of Education — Central Office</div><div><b>IAR Date:</b> '.self::e(self::date($acceptedDate)).'</div>'
            .'<div><b>CO Delivery / Packing List:</b> '.self::e($s['co_delivery_reference']).'</div><div><b>Actual delivery date:</b> '.self::e(self::date($s['received_on'])).'</div>'
            .'<div><b>Local PO:</b> Not applicable (CO delivery)</div><div><b>Receipt reference:</b> '.self::e($receipt['number']).'</div></div>'
            .'<table aria-label="CO textbook IAR books"><colgroup><col style="width:12%"><col style="width:58%"><col style="width:11%"><col style="width:19%"></colgroup>'
            .'<thead><tr><th>Stock /<br>Property No.</th><th>Description</th><th>Unit</th><th>Quantity<br>Delivered</th></tr></thead><tbody>'.$rows.'</tbody></table>'
            .$shortageNote.$observationsHtml.'<div class="signs"><section><h3>Inspection</h3><div>Date Inspected: '.self::e(self::date($inspectionDate)).'</div>'
            .'<p><span class="check">'.($inspectedAndInOrder?'✓':'&nbsp;').'</span>Inspected, verified and found in order as to quantity and specification</p>'
            .'<div class="sig">'.self::e($inspector).'&nbsp;</div><small>'.self::e($inspectorDesignation?:'Inspector / Inspection Team').'</small>'
            .'<small>Inspection supporting reference: '.self::e($s['inspection_reference']??'').'</small>'
            .'<small>Signature must be obtained on the official supporting document.</small></section>'
            .'<section><h3>Acceptance</h3><div>Date Accepted: '.self::e(self::date($acceptedDate)).'</div>'
            .'<p><span class="check">'.($complete?'✓':'&nbsp;').'</span>Complete</p>'
            .'<p><span class="check">'.($accepted && !$complete?'✓':'&nbsp;').'</span>Partial / with discrepancy (see quantities)</p>'
            .'<div class="sig">'.self::e($custodian).'&nbsp;</div><small>'.self::e($custodianDesignation?:'Receiving Supply / Property Custodian').'</small>'
            .'<small>Acceptance supporting reference: '.self::e($s['signed_acceptance_reference']??'').'</small>'
            .'<small>Signature must be obtained on the original IAR.</small></section></div>'
            .'<div class="status">'.self::e($status).' · '.self::e(strip_tags($note)).'</div>'.$provenance
            .'<p class="note">Automatically prepared from the audited physical CO textbook receipt. Named inspection and receiving persons are transcribed from the paper records, NOT digitally authenticated or approved. Signatures must be obtained on the actual paper IAR. No duplicate procurement IAR, Purchase Order, Disbursement Voucher, or inventory posting is created by printing.</p>'
            .'</body></html>';
    }
    private static function e(mixed $v):string {
        return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
    private static function date(mixed $value):string {
        $s=trim((string)$value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/D',$s)?substr($s,5,2).'/'.substr($s,8,2).'/'.substr($s,0,4):'';
    }
}
