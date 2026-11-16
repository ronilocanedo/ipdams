<?php
declare(strict_types=1);

/** Read-only PR print layout; authenticated/scope-checked by public/api/print.php.
 * Uses the same immutable source version as XLSX; no historical/signature edits.
 */
/**
 * Read-only proposed signatory for a school PR, as effective on its document date.
 * An appointment is documentary reference data, NOT electronic approval.
 * Never fall back to a role/title guess or another school's staff directory.
 */
function ipdamsPrSchoolApprover(PDO $db, int $organizationId, string $documentDate): array {
    $missing=['name'=>'','designation'=>'','status'=>'UNCONFIGURED'];
    if ($organizationId<=0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $documentDate)) return $missing;
    $org=one($db,'SELECT kind FROM organizations WHERE id=? AND active=1',[$organizationId]);
    if ($org===null || strtoupper((string)($org['kind']??''))!=='SCHOOL') {
        return ['name'=>'','designation'=>'','status'=>'NOT_SCHOOL'];
    }
    $head=one($db,'SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key=? AND effective_from<=? ORDER BY effective_from DESC,id DESC LIMIT 1',
        [$organizationId,'ORG_OFFICE_HEAD_V1',$documentDate]);
    if ($head===null) return $missing;
    $value=json_decode((string)$head['config_json'],true);
    if (!is_array($value) || !is_numeric($value['employee_id']??null) || (int)$value['employee_id']<=0) return $missing;
    $employeeId=(int)$value['employee_id'];
    $matches=q($db,'SELECT e.full_name,a.designation,e.position_title FROM employee_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.organization_id=? AND a.employee_id=? AND a.effective_from<=? AND (a.effective_to IS NULL OR a.effective_to>?) ORDER BY a.effective_from DESC,a.id DESC LIMIT 2',
        [$organizationId,$employeeId,$documentDate,$documentDate])->fetchAll();
    if (count($matches)!==1) return $missing; // Avoid guessing from overlapping/invalid personnel appointments.
    $name=trim((string)($matches[0]['full_name']??''));
    $designation=trim((string)($matches[0]['designation']??''));
    if ($designation==='') $designation=trim((string)($matches[0]['position_title']??''));
    if ($name==='' || $designation==='') return $missing;
    return ['name'=>$name,'designation'=>$designation,'status'=>'CONFIGURED'];
}

function ipdamsPrPrintHtml(array $pr): string {
    $e=static fn(mixed $v): string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    demand(is_array($pr['lines']??null)&&count($pr['lines'])>0,'PR must contain at least one item');
    $total=0;$rows='';
    foreach($pr['lines'] as $i=>$line){
        demand(is_array($line),'Invalid PR line');
        $qty=qtyMillis($line['quantity']??'');$unit=moneyCents($line['estimated_unit_price']??'');
        demand($qty>0&&$unit>=0,'Invalid PR line amounts');
        demand($qty<=intdiv(PHP_INT_MAX-500,max(1,$unit)),'PR amount exceeds supported range');
        $amount=totalCents($unit,$qty);demand($total<=PHP_INT_MAX-$amount,'PR sum exceeds supported range');$total+=$amount;
        $rows.='<tr><td class="center">'.($i+1).'</td><td class="center">'.$e($line['unit_snapshot']??'').'</td><td>'.$e($line['description_snapshot']??'').'</td>'
            .'<td class="number">'.$e(qtyString($qty)).'</td><td class="number">'.$e(moneyString($unit)).'</td><td class="number">'.$e(moneyString($amount)).'</td></tr>';
    }
    if(isset($pr['expected_total'])&&(string)$pr['expected_total']!=='')
        demand(moneyCents($pr['expected_total'])===$total,'Finalized PR amount mismatch; review snapshot before export',409);
    for($i=count($pr['lines']);$i<12;$i++)$rows.='<tr class="empty"><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr>';
    $number=$e($pr['number']);$org=$e($pr['organization_name']);$office=$e($pr['requesting_unit']);
    $fund=$e($pr['fund_name']);$date=$e($pr['document_date']);$purpose=$e($pr['purpose']);
    $fundCluster=$e($pr['fund_cluster']??'');$responsibility=$e($pr['responsibility_center_code']??'');
    $requestor=is_array($pr['requestor']??null)?$pr['requestor']:[];
    $requestorName=$e($requestor['name']??'');$requestorDesignation=$e($requestor['designation']??'');
    $approver=is_array($pr['approver']??null)?$pr['approver']:[];
    $approverName=$e($approver['name']??'');$approverDesignation=$e($approver['designation']??'');
    $missingApprover=((string)($approver['status']??'')==='UNCONFIGURED');
    $version=$e($pr['version']);$recorded=$e($pr['recorded']);
    $excelUrl='pr_xlsx.php?organization_id='.rawurlencode((string)$pr['organization_id']).'&id='.rawurlencode((string)$pr['id']);
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"/>'
        .'<title>Purchase Request '.$number.'</title><style>
    *{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;color:#191919;max-width:850px;margin:20px auto;padding:0 17px;font-size:11px;line-height:1.36}
    .toolbar{display:flex;justify-content:space-between;align-items:center;gap:15px;padding:12px 14px;background:#f3f7f7;border:1px solid #bbc8cc;border-radius:6px;margin-bottom:14px}
    .toolbar-actions{display:flex;gap:10px}.toolbar button,.toolbar a{font:600 12px Arial;text-decoration:none;cursor:pointer;padding:8px 12px;border:1px solid #556670;border-radius:4px;color:#152e40;background:white}
    .small{font-size:9px;color:#555}.heading{text-align:center;line-height:1.5}.heading .gov{font-size:12px}.heading .office{font-weight:700;font-size:13px}.heading h1{font-size:17px;letter-spacing:.2px;margin:13px 0 15px}
    .appendix{text-align:right;font-style:italic;font-size:10px}.metadata{width:100%;border-collapse:collapse;table-layout:fixed;margin:0 0 8px}.metadata th,.metadata td{text-align:left;padding:5px 6px;border:1px solid #777;overflow-wrap:anywhere;font-size:10px}.metadata th{font-weight:600;background:#fafafa}.metadata .wide{width:44%}
    .items{border-collapse:collapse;width:100%;table-layout:fixed;font-size:10px}.items th,.items td{border:1px solid #666;vertical-align:middle;padding:5px 6px;overflow-wrap:anywhere}.items th{text-align:center;background:#f6f6f6;font-weight:700}.items td.center{text-align:center}.items td.number{text-align:right;font-variant-numeric:tabular-nums}.items .empty{height:23px}.items tfoot td{font-weight:700;text-align:right}
    .purpose{border:1px solid #666;border-top:0;min-height:53px;padding:8px 9px}.purpose strong{margin-right:7px}.sign{border-collapse:collapse;width:100%;table-layout:fixed;margin-top:0}.sign th,.sign td{border:1px solid #666;padding:7px 9px;width:50%;text-align:left}.sign th{font-weight:700}.sign td{height:29px}.sign .sign-space{height:33px;vertical-align:top}
    .notice{font-size:9px;color:#5b6167;margin:10px 0}.version{font-size:9px;color:#666}
    @media print{@page{size:A4 portrait;margin:12mm}body{padding:0;margin:0;max-width:none;-webkit-print-color-adjust:exact;print-color-adjust:exact}.toolbar{display:none}.items thead{display:table-header-group}.items tr,.metadata tr,.sign tr{break-inside:avoid}.sign,.purpose{break-inside:avoid}}
    </style></head><body>'
        .'<div class="toolbar"><div><strong>Purchase Request '.$number.'</strong><div class="small">Working PR layout · Export uses the finalized, recorded document</div></div>'
        .'<div class="toolbar-actions"><a href="'.$e($excelUrl).'">Download Excel (.xlsx)</a><button type="button" onclick="window.print()">Print / Save as PDF</button></div></div>'
        .'<div class="appendix">Appendix 60-style arrangement · IPDAMS working layout</div>'
        .'<div class="heading"><div class="gov">Republic of the Philippines</div><div class="gov">Department of Education</div><div class="office">'.$org.'</div><h1>PURCHASE REQUEST</h1></div>'
        .'<table class="metadata"><tbody>'
        .'<tr><th>Entity Name</th><td class="wide">DEPARTMENT OF EDUCATION</td><th>Fund Cluster</th><td>'.$fundCluster.'</td></tr>'
        .'<tr><th>Office</th><td>'.$org.'</td><th>PR No.</th><td>'.$number.'</td></tr>'
        .'<tr><th>Requesting Unit</th><td>'.$office.'</td><th>Date</th><td>'.$date.'</td></tr>'
        .'<tr><th>Source of Funds</th><td>'.$fund.'</td><th>Responsibility Center Code</th><td>'.$responsibility.'</td></tr>'
        .'</tbody></table><table class="items"><colgroup><col style="width:7%"/><col style="width:13%"/><col style="width:38%"/><col style="width:11%"/><col style="width:15%"/><col style="width:16%"/></colgroup>'
        .'<thead><tr><th>No.</th><th>Unit</th><th>Item Description</th><th>Quantity</th><th>Unit Cost (PHP)</th><th>Total Cost (PHP)</th></tr></thead>'
        .'<tbody>'.$rows.'</tbody><tfoot><tr><td colspan="5">TOTAL</td><td class="number">'.$e(moneyString($total)).'</td></tr></tfoot></table>'
        .'<div class="purpose"><strong>Purpose:</strong> '.$purpose.'</div>'
        .'<table class="sign"><thead><tr><th>Requested by:</th><th>Approved by:</th></tr></thead><tbody>'
        .'<tr><td class="sign-space">Signature:</td><td class="sign-space">Signature:</td></tr>'
        .'<tr><td>Printed Name: '.($requestorName?:'_____________________').'</td><td>Printed Name: '.($approverName?:'_____________________').'</td></tr>'
        .'<tr><td>Designation: '.($requestorDesignation?:'_____________________').'</td><td>Designation: '.($approverDesignation?:'_____________________').'</td></tr></tbody></table>'
        .($missingApprover?'<p class="notice">School Head appointment for this PR date is not configured. Set the official head under Reference Data → BAC &amp; Heads before using this copy for signatures.</p>':'')
        .'<p class="notice">IPDAMS internal working form; not verified as the current prescribed DepEd/COA template. Manual signatures and required approvals remain necessary. Printing/exporting does not record approval.</p>'
        .'<div class="version">PR '.$number.' · Version '.$version.' · Recorded '.$recorded.' · Updated copies require fresh manual signatures.</div>'
        .'</body></html>';
}
