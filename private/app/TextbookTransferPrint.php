<?php
declare(strict_types=1);
require_once __DIR__.'/TextbookTransferWorkflow.php';
/** Two linked paper forms; these printouts never grant authority or post stock. */
final class TextbookTransferPrint {
    private static function e(mixed $v):string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function fmt(mixed $v):string {
        $s=trim((string)$v);
        return preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$s)?substr($s,5,2).'/'.substr($s,8,2).'/'.substr($s,0,4):'—';
    }
    private static function mode(string $v):string {
        return ['ISSUER_DELIVERY'=>'Delivery by issuing office','SCHOOL_PICKUP'=>'Pickup by receiving school',
            'CARGO_COURIER'=>'Cargo / courier','OTHER'=>'Other documented arrangement'][$v]??'Not specified';
    }
    public static function render(PDO $db,array $user,array $input):string {
        $org=(int)($input['organization_id']??0);
        $r=(new TextbookTransferWorkflow($db,$user))->detail(['organization_id'=>$org,'transfer_id'=>(int)($input['transfer_id']??0)]);
        $s=$r['snapshot'];$format=(string)($input['print']??'');
        demand(in_array($format,['ris','itr'],true),'Choose RIS or ITR',422);
        demand(in_array($s['status'],['IN_TRANSIT','PARTIALLY_RECEIVED','RECEIVED'],true),
            'RIS and ITR can only be printed after actual physical dispatch is recorded',409);
        demand($s['ris_number']!==null && $s['itr_number']!==null,'Linked RIS/ITR numbering is missing',409);
        $isRIS=$format==='ris';$num=$isRIS?$s['ris_number']:$s['itr_number'];
        $title=$isRIS?'REQUISITION AND ISSUE SLIP':'INVENTORY TRANSFER REPORT';
        $requested=$s['request']??null;$issued=$s['dispatch'];
        $rows='';$total=0;$receivedTotal=0;
        foreach($s['lines'] as $i=>$line){
            if((int)$line['issued_quantity']===0)continue;
            $qty=(int)$line['issued_quantity'];$total+=$qty;
            $recv=(int)$line['received_serviceable']+(int)$line['received_damaged'];$receivedTotal+=$recv;
            $detail=(string)$line['title'].' — ISBN '.(string)$line['isbn13'];
            $extra=array_filter([trim((string)($line['grade']??''))!==''?'Grade '.trim((string)$line['grade']):'',
                trim((string)($line['subject']??''))]);
            if($extra)$detail.=' ('.implode(' · ',$extra).')';
            $rows.='<tr><td class="center">'.self::e($i+1).'</td><td>'.self::e($detail).'</td>'
                .'<td class="center">copy</td><td class="center">'.self::e($isRIS?($requested!==null?$line['requested_quantity']:$line['allocated_quantity']):$qty).'</td>'
                .'<td class="center">'.self::e($qty).'</td><td class="center">'.self::e($recv).'</td></tr>';
        }
        for($i=count($s['lines']);$i<8;$i++)$rows.='<tr class="blank"><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr>';
        $preferred=$requested?'<div><b>Requested delivery:</b> '.self::e(self::mode((string)$requested['preferred_delivery_mode'])).'</div>'
            .'<div><b>Requested date:</b> '.self::e(self::fmt($requested['preferred_delivery_date'])).'</div>'
            .'<div><b>Delivery instructions:</b> '.self::e($requested['delivery_instructions']??'').'</div>':'';
        $receiptNames=array_values(array_unique(array_filter(array_map(static fn($e)=>trim((string)($e['received_by_name']??'')),$s['receipt_events']))));
        $receiver=implode('; ',$receiptNames);
        $destinationCustodian=trim((string)($issued['receiving_custodian_name']??''));
        if($receiver==='')$receiver=$destinationCustodian;
        $appointedIssuer=trim((string)($issued['appointed_issuer_name']??''));
        $actingIssuer=$appointedIssuer!=='' && strcasecmp($appointedIssuer,trim((string)$issued['issued_by_name']))!==0;
        $issuerLine=$actingIssuer?$appointedIssuer:(string)$issued['issued_by_name'];
        $issuerCounterSign=$actingIssuer
            ?'<div class="line">'.self::e($issued['issued_by_name']).' (acting signatory)</div>'
                .'<small>Acting reference: '.self::e($issued['acting_issuer_reason']??'').'</small>':'';
        $receiverWithOffice=$receiver;
        $receiverCounterSign='';
        if($receiptNames){
            $shown=[];$actingEntries=[];
            foreach($s['receipt_events'] as $event){
                $actual=trim((string)($event['received_by_name']??''));
                if($actual==='')continue;
                $official=trim((string)($event['appointed_receiver_name']??''));
                $acting=$official!=='' && strcasecmp($official,$actual)!==0;
                $shown[]=$acting?$official:$actual;
                if($acting)$actingEntries[]=[$actual,(string)($event['acting_receiver_reason']??'')];
            }
            if($shown)$receiverWithOffice=implode('; ',array_unique($shown));
            foreach($actingEntries as [$name,$reason])
                $receiverCounterSign.='<div class="line">'.self::e($name).' (acting signatory)</div>'
                    .'<small>Acting reference: '.self::e($reason).'</small>';
        }
        $foot=$isRIS?'<div class="signs ris-signs"><section><b>Requested by (paper):</b><div class="line">'.self::e($requested['requested_by_name']??'Direct transfer — no advance request').'</div></section>'
                .'<section><b>Issued by (paper):</b><div class="line">'.self::e($issuerLine).'</div>'.$issuerCounterSign.'</section>'
                .'<section><b>Receiving school custodian (paper):</b><div class="line">'.self::e($receiverWithOffice).'</div>'.$receiverCounterSign.'</section>'
                .'<section><b>Received for transport (paper):</b><div class="line">'.self::e($issued['carrier_or_receiver']?:'').'</div></section></div>'
            :'<div class="signs"><section><b>Releasing officer (paper):</b><div class="line">'.self::e($issuerLine).'</div>'.$issuerCounterSign.'</section>'
                .'<section><b>Receiving school / receiving official (paper):</b><div class="line">'.self::e($receiverWithOffice).'</div>'.$receiverCounterSign.'</section>'
                .'<section><b>Date of physical receipt:</b><div class="line">'.self::e(implode('; ',array_map(static fn($e)=>self::fmt($e['received_on']??''),$s['receipt_events']))).'</div></section></div>';
        $notes='Physical dispatch: '.self::e(self::fmt($issued['issued_on'])).' · '.self::e(self::mode($issued['delivery_mode'])).
            ' · Total issued: '.self::e($total).' · Total physically recorded received: '.self::e($receivedTotal).'.';
        if($receivedTotal<$total)$notes.=' '.self::e($total-$receivedTotal).' copies remain pending or discrepant in transit.';
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.self::e($title.' '.$num).'</title><style>*{box-sizing:border-box}body{max-width:850px;margin:14px auto;padding:10px;color:#111;font:11px "Bookman Old Style",Georgia,serif;line-height:1.4}'
            .'h1{text-align:center;font-size:17px;margin:18px 0}h2{text-align:center;font-size:12px;margin:0 0 12px}.top{display:grid;grid-template-columns:1fr 1fr;gap:6px 20px;padding:10px;border:1px solid #222}'
            .'table{width:100%;border-collapse:collapse}td,th{border:1px solid #222;padding:5px;overflow-wrap:anywhere}th{background:#eee;text-align:center}.center{text-align:center}.blank td{height:18px}'
            .'.notes{border:1px solid #222;border-top:0;padding:9px}.signs{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:40px 0 16px}.ris-signs{grid-template-columns:repeat(2,1fr)}.signs small{display:block;text-align:center;font-size:9px;overflow-wrap:anywhere}.line{overflow-wrap:anywhere;border-bottom:1px solid #111;min-height:29px;text-align:center;padding:9px 0 2px;font-weight:700}'
            .'.disclaimer{font-size:9px;border-top:1px solid #888;margin-top:20px;padding-top:6px}.tools{text-align:right}.tools button{padding:8px 16px}tr{break-inside:avoid}'
            .'@media print{@page{size:A4 portrait;margin:12mm}body{margin:0;padding:0;max-width:none}.tools{display:none}th{background:#fff}}'
            .'@media(max-width:520px){.top,.signs,.ris-signs{grid-template-columns:1fr}}</style></head><body><div class="tools"><button type="button" onclick="window.print()">Print '.strtoupper($format).'</button></div>'
            .'<h1>'.self::e($title).'</h1><h2>Division / school textbook distribution · linked transfer '.self::e($r['number']).'</h2>'
            .'<div class="top"><div><b>Form number:</b> '.self::e($num).'</div><div><b>Companion '.($isRIS?'ITR':'RIS').':</b> '.self::e($isRIS?$s['itr_number']:$s['ris_number']).'</div>'
            .'<div><b>Issued by:</b> '.self::e($s['source_office_name']).'</div><div><b>Receiving school:</b> '.self::e($s['destination_school_name']).'</div>'
            .'<div><b>Mode:</b> '.self::e($s['path']==='DIRECT_TRANSFER'?'Direct transfer (without advance notice)':'Allocation → school request → issuance').'</div>'
            .'<div><b>Dispatch reference:</b> '.self::e($issued['paper_reference']).'</div>'
            .$preferred.'<div><b>Actual dispatch date:</b> '.self::e(self::fmt($issued['issued_on'])).'</div>'
            .'<div><b>Actual delivery mode:</b> '.self::e(self::mode($issued['delivery_mode'])).'</div></div>'
            .'<table><thead><tr><th>No.</th><th>Description / ISBN</th><th>Unit</th><th>'.($isRIS?'Requested':'Allocated').'</th><th>Issued</th><th>Received to date</th></tr></thead>'
            .'<tbody>'.$rows.'</tbody></table><div class="notes">'.$notes.'</div>'.$foot
            .'<p class="disclaimer">This form is automatically transcribed from the versioned IPDAMS textbook transfer register. Signatories are recorded as named on the actual paperwork and are NOT electronically authenticated or approved. Print the RIS and ITR for physical signatures. Stock is debited on recorded dispatch, and only copies physically acknowledged by the receiving school are credited to its inventory. Outstanding counts are not credited.</p>'
            .'</body></html>';
    }
}
