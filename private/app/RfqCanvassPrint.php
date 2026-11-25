<?php
declare(strict_types=1);
/** Read-only printable counterpart of the user's Canvass Sample.xlsx, sheet Canvass.
 * No supplier prices, supplier identity or approvals are invented. */
final class RfqCanvassPrint {
    private static function e(mixed $s): string {
        return htmlspecialchars((string)($s??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
    public static function render(array $a): string {
        $e=static fn(mixed $v):string=>self::e($v);
        $org=$a['organization'];$number=(string)$a['number'];$date=(string)$a['issued_at'];
        $data=$a['participants']??null;
        $chair=$data['bac_chair']['name']??'';$canvasser=$data['canvasser']['name']??'';
        $school=(string)($org['name']??'');$address=trim((string)($org['address']??''));
        $contact=trim((string)($org['contact_phone']??''));
        $intro='Please quote your price(s) at the blank space hereunder provided for the following articles to be delivered at the '.$school;
        if($address!=='')$intro.=', '.$address;
        $intro.='.';
        if($contact!=='')$intro.=' For more information, please contact the School BAC at '.$contact.'.';
        else $intro.=' For more information, please contact the School BAC.';
        $html='<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Canvass '.$e($number).'</title><style>'.
            '*{box-sizing:border-box}body{font-family:"Bookman Old Style",Georgia,serif;color:#111;margin:12mm auto;max-width:200mm;font-size:11pt;line-height:1.25}'.
            '.toolbar{font:12px Arial,sans-serif;background:#eef7f6;padding:9px;margin-bottom:12px;display:flex;justify-content:space-between}.toolbar button{padding:8px 16px}'.
            '.mast{text-align:center;line-height:1.15}.mast div{margin:0}.mast .dept{font-weight:500}.mast .school{font-weight:700}.title{text-align:center;font-weight:bold;margin:12px 0 18px}'.
            '.refrow{display:flex;justify-content:space-between;gap:18px;margin:4px 0 10px}.recipient-lines{width:37%;padding-top:7px}.recipient-lines span{display:block;border-bottom:1px solid #444;height:16px}.references{text-align:right;white-space:nowrap}.references div{margin:3px 0}.references b{border-bottom:1px solid #333;min-width:110px;display:inline-block;text-align:center}'.
            '.greeting{margin-top:12px;padding-top:12px}.invitation{text-indent:30px;text-align:justify;margin:12px 0}.chair-block{margin:28px 0 13px}.chair-name{font-weight:bold;text-transform:uppercase}.purpose{margin:14px 0}.purpose strong{font-weight:700}'.
            'table{border-collapse:collapse;width:100%;table-layout:fixed}.grid{font-size:8.6pt;line-height:1.05}.grid th,.grid td{border:1px solid #222;padding:1px 2px;vertical-align:middle}.grid th{text-align:center;font-weight:bold;white-space:nowrap}.grid .center{text-align:center}.grid td{height:17px}.grid .description{overflow-wrap:anywhere}.grid .total-label{text-align:center;font-weight:bold}'.
            '.cert{margin-top:18px;text-align:justify}.closing{break-inside:avoid;page-break-inside:avoid}.sign-block{display:grid;grid-template-columns:1fr 1fr;gap:18%;margin-top:40px;break-inside:avoid}.party{text-align:center}.party .line{border-bottom:1px solid #333;min-height:20px}.party .name{font-weight:bold;text-transform:uppercase;min-height:21px}.party .role{font-style:italic}.notice{font:10px Arial,sans-serif;color:#845b06;margin-bottom:10px}'.
            '@media print{@page{size:A4 portrait;margin:12mm}body{margin:0;max-width:none;font-size:10pt}.toolbar{display:none}.grid{break-inside:auto}.grid tr{break-inside:avoid;page-break-inside:avoid}.grid thead{display:table-header-group}.closing{break-inside:avoid;page-break-inside:avoid}.sign-block{page-break-inside:avoid}}'.
            '</style></head><body><div class="toolbar"><span>Request for Quotation · blank supplier response form</span><button type="button" onclick="window.print()">Print / Save as PDF</button></div>';
        if(!$data)$html.='<div class="notice">Historical RFQ: canvasser and BAC Chairperson were not recorded when this RFQ was issued. Confirm the original officials before using the printed copy.</div>';
        elseif((string)($data['issued_at']??'')!==$date)$html.='<div class="notice">RFQ date was corrected after participant recording; verify appointments before distribution.</div>';
        $html.='<div class="mast"><div>Republic of the Philippines</div><div class="dept">Department of Education</div>'.
            // A Region line may be supplied through an organization-specific print header in a future version.
            '<div class="school">'.$e($school).'</div></div><div class="title">REQUEST FOR QUOTATION</div>';
        $html.='<div class="refrow"><div class="recipient-lines"><span></span><span></span><span></span></div>'.
            '<div class="references"><div>Canvass No.: <b>'.$e($number).'</b></div><div>Date: <b>'.$e($date).'</b></div></div></div>'.
            '<p class="greeting">Sir/Madam:</p><p class="invitation">'.$e($intro).'</p>'.
            '<div class="chair-block"><div class="chair-name">'.$e($chair).'</div><div>BAC Chairperson</div></div>'.
            '<div class="purpose">Purpose: <strong>'.$e($a['purpose']??'').'</strong></div>'.
            '<table class="grid"><thead><tr><th style="width:10%">Item No.</th><th style="width:10%">Quantity</th>'.
            '<th style="width:11%">Unit Used</th><th style="width:47%">Articles and Description</th>'.
            '<th style="width:11%">Unit Price</th><th style="width:11%">Total Price</th></tr></thead><tbody>';
        $lines=$a['lines']??[];
        demand(is_array($lines)&&count($lines)>0&&count($lines)<=100,'RFQ items unavailable',409);
        foreach($lines as $i=>$line){
            demand(is_array($line),'Malformed RFQ item',409);
            $html.='<tr><td class="center">'.$e($i+1).'</td><td class="center">'.$e($line['quantity']??'').'</td>'.
                '<td class="center">'.$e($line['unit_snapshot']??'').'</td><td class="description">'.$e($line['description_snapshot']??'').'</td>'.
                '<td>&nbsp;</td><td>&nbsp;</td></tr>';
        }
        // Only a few spare write-in rows are helpful for short quotations. The previous
        // unconditional 30-row table forced even a one-item RFQ onto two A4 pages.
        // For larger requests, keep every actual item and let the print engine paginate
        // intact rows with the same column headings repeated on following pages.
        for($i=count($lines);$i<5;$i++)$html.='<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr>';
        $html.='<tr><td class="total-label">Total</td><td></td><td></td><td></td><td></td><td></td></tr></tbody></table>'.
            '<div class="closing"><p class="cert">I hereby certify that the above supplies/materials are actually carried in stock by me. The price(s) above as quoted are hereby offered.</p>'.
            '<div class="sign-block"><div class="party"><div class="line"></div><div class="role">Bidder</div></div>'.
            '<div class="party"><div class="line"></div><div class="name">'.$e($canvasser).'</div><div class="role">Canvasser</div></div></div></div>'.
            '</body></html>';
        return $html;
    }
}
