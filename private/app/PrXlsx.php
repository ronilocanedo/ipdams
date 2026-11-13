<?php
declare(strict_types=1);

/** Generate a read-only, Appendix-60-inspired XLSX from a finalized PR snapshot.
 * No example signatories, region, fund cluster or responsibility code are invented.
 * 'No.' represents sequential line numbers, not stock/inventory numbers.
 */
function ipdamsPrXlsx(array $pr): string {
    $xml=static function(mixed $value): string {
        $s=(string)$value;
        $s=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/','',$s)??'';
        return htmlspecialchars($s,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    };
    $cellText=static fn(string $at,mixed $v,int $style=0): string=>
        '<c r="'.$at.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$xml($v).'</t></is></c>';
    $cellNum=static function(string $at,string $value,int $style=0): string {
        if(!preg_match('/^-?\d+(\.\d+)?$/D',$value))throw new RuntimeException('Invalid spreadsheet numeric value');
        return '<c r="'.$at.'" s="'.$style.'"><v>'.$value.'</v></c>';
    };
    $cellFormula=static function(string $at,string $formula,string $cached,int $style=0): string {
        if(!preg_match('/^-?\d+(\.\d+)?$/D',$cached))throw new RuntimeException('Invalid spreadsheet cached value');
        return '<c r="'.$at.'" s="'.$style.'"><f>'.$formula.'</f><v>'.$cached.'</v></c>';
    };
    $rows=[];$merged=[];
    $put=static function(int $index,array $cells,int $height=0)use(&$rows): void {
        $rows[]='<row r="'.$index.'"'.($height?' ht="'.$height.'" customHeight="1"':'').'>'.implode('',$cells).'</row>';
    };
    $put(1,[$cellText('A1','IPDAMS working format · Appendix 60-style arrangement (not verified official form)',12)],17);$merged[]='A1:F1';
    $put(2,[$cellText('A2','Republic of the Philippines',1)],20);$merged[]='A2:F2';
    $put(3,[$cellText('A3','Department of Education',1)],20);$merged[]='A3:F3';
    $put(4,[$cellText('A4',$pr['organization_name'],2)],22);$merged[]='A4:F4';
    $put(6,[$cellText('A6','PURCHASE REQUEST',3)],26);$merged[]='A6:F6';
    $put(8,[$cellText('A8','Entity Name:',4),$cellText('B8','DEPARTMENT OF EDUCATION',5),$cellText('E8','Fund Cluster:',4),$cellText('F8',$pr['fund_cluster']??'',5)],24);
    $merged[]='B8:D8';
    $put(9,[$cellText('A9','Office:',4),$cellText('B9',$pr['organization_name'],5),$cellText('D9','PR No.:',4),$cellText('E9',$pr['number'],5)],26);
    $merged[]='B9:C9';$merged[]='E9:F9';
    $put(10,[$cellText('A10','Requesting Unit:',4),$cellText('B10',$pr['requesting_unit'],5),$cellText('D10','Date:',4),$cellText('E10',$pr['document_date'],5)],25);
    $merged[]='B10:C10';$merged[]='E10:F10';
    $put(11,[$cellText('A11','Source of Funds:',4),$cellText('B11',$pr['fund_name'],5),$cellText('D11','Responsibility Code:',4),$cellText('E11',$pr['responsibility_center_code']??'',5)],28);
    $merged[]='B11:C11';$merged[]='E11:F11';
    $put(13,[$cellText('A13','No.',7),$cellText('B13','Unit',7),$cellText('C13','Item Description',7),$cellText('D13','Quantity',7),$cellText('E13','Unit Cost (PHP)',7),$cellText('F13','Total Cost (PHP)',7)],31);
    demand(is_array($pr['lines'])&&count($pr['lines'])>0,'No PR lines to export');
    $totalCents=0;$start=14;
    foreach($pr['lines'] as $i=>$line){
        demand(is_array($line),'Invalid PR line');
        $row=$start+$i;
        $quantity=qtyMillis($line['quantity']??'');$unitCents=moneyCents($line['estimated_unit_price']??'');
        demand($quantity>0&&$unitCents>=0,'Invalid PR line amounts');
        demand($quantity<=intdiv(PHP_INT_MAX-500,max(1,$unitCents)),'PR amount exceeds supported range');
        $cents=totalCents($unitCents,$quantity);
        demand($totalCents<=PHP_INT_MAX-$cents,'PR sum exceeds supported range');$totalCents+=$cents;
        $put($row,[$cellNum('A'.$row,(string)($i+1),8),$cellText('B'.$row,$line['unit_snapshot']??'',8),
            $cellText('C'.$row,$line['description_snapshot']??'',9),$cellNum('D'.$row,qtyString($quantity),10),
            $cellNum('E'.$row,moneyString($unitCents),11),
            $cellFormula('F'.$row,'ROUND(D'.$row.'*E'.$row.',2)',moneyString($cents),11)],27);
    }
    $n=count($pr['lines']);$lastItem=$start+max(12,$n)-1;
    for($row=$start+$n;$row<=$lastItem;$row++){
        $put($row,array_map(static fn($col)=>$cellText($col.$row,'',8),['A','B','C','D','E','F']),24);
    }
    if(isset($pr['expected_total'])&&(string)$pr['expected_total']!=='')
        demand(moneyCents($pr['expected_total'])===$totalCents,'Finalized PR amount mismatch; review snapshot before export',409);
    $totalRow=$lastItem+1;
    $put($totalRow,[$cellText('A'.$totalRow,'TOTAL',6),$cellFormula('F'.$totalRow,'SUM(F'.$start.':F'.($start+$n-1).')',moneyString($totalCents),6)],25);
    $merged[]='A'.$totalRow.':E'.$totalRow;
    $purposeRow=$totalRow+1;
    $put($purposeRow,[$cellText('A'.$purposeRow,'Purpose:',4),$cellText('B'.$purposeRow,$pr['purpose'],5)],35);
    $merged[]='B'.$purposeRow.':F'.$purposeRow;
    $headRow=$purposeRow+3;
    $put($headRow,[$cellText('A'.$headRow,'Requested by:',13),$cellText('D'.$headRow,'Approved by:',13)],25);
    $merged[]='A'.$headRow.':C'.$headRow;$merged[]='D'.$headRow.':F'.$headRow;
    foreach([['Signature:',37],['Printed Name:',25],['Designation:',25]] as $i=>$line){
        $rr=$headRow+$i+1;
        $requestor=is_array($pr['requestor']??null)?$pr['requestor']:[];
        $person=$i===1?($requestor['name']??''):($i===2?($requestor['designation']??''):'');
        $put($rr,[$cellText('A'.$rr,$line[0],14),$cellText('B'.$rr,$person,15),$cellText('D'.$rr,$line[0],14),$cellText('E'.$rr,'',15)],$line[1]);
        $merged[]='B'.$rr.':C'.$rr;$merged[]='E'.$rr.':F'.$rr;
    }
    $noticeRow=$headRow+5;
    $put($noticeRow,[$cellText('A'.$noticeRow,'IPDAMS internal working layout; confirm approved DepEd/COA format. Signatures/approvals are manual. Excel edits do not update IPDAMS.',12)],23);
    $merged[]='A'.$noticeRow.':F'.$noticeRow;
    $versionRow=$noticeRow+1;
    $put($versionRow,[$cellText('A'.$versionRow,'PR '.$pr['number'].' · Version '.$pr['version'].' · Recorded '.$pr['recorded'].' · Updated copies require new manual signatures.',12)],19);
    $merged[]='A'.$versionRow.':F'.$versionRow;
    $sheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:F'.$versionRow.'"/>'
        .'<sheetViews><sheetView showGridLines="0" workbookViewId="0"/></sheetViews>'
        .'<sheetFormatPr defaultRowHeight="19"/>'
        .'<cols><col min="1" max="1" width="19" customWidth="1"/><col min="2" max="2" width="16" customWidth="1"/>'
        .'<col min="3" max="3" width="43" customWidth="1"/><col min="4" max="4" width="22" customWidth="1"/>'
        .'<col min="5" max="5" width="22" customWidth="1"/><col min="6" max="6" width="23" customWidth="1"/></cols>'
        .'<sheetData>'.implode('',$rows).'</sheetData>'
        .'<mergeCells count="'.count($merged).'">'.implode('',array_map(static fn($r)=>'<mergeCell ref="'.$r.'"/>',$merged)).'</mergeCells>'
        .'<printOptions horizontalCentered="1"/><pageMargins left="0.3" right="0.3" top="0.4" bottom="0.4" header="0.2" footer="0.2"/>'
        .'<pageSetup paperSize="9" orientation="portrait" fitToWidth="1" fitToHeight="0"/></worksheet>';
    $fonts='<fonts count="5">'
        .'<font><sz val="10"/><name val="Arial"/></font>'
        .'<font><sz val="10"/><name val="Arial"/></font>'
        .'<font><b/><sz val="11"/><name val="Arial"/></font>'
        .'<font><b/><sz val="16"/><name val="Arial"/></font>'
        .'<font><sz val="9"/><color rgb="FF59616B"/><name val="Arial"/></font>'
        .'</fonts>';
    $fills='<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>';
    $borders='<borders count="3"><border><left/><right/><top/><bottom/><diagonal/></border>'
        .'<border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/><diagonal/></border>'
        .'<border><left/><right/><top style="thin"/><bottom/><diagonal/></border></borders>';
    // Font, border, custom number format, and alignment. Monochrome like the user's sample.
    $formats=[
      [0,0,0,''],[1,0,0,' horizontal="center"'],[2,0,0,' horizontal="center"'],
      [3,0,0,' horizontal="center"'],[2,0,0,' wrapText="1"'],[0,0,0,' wrapText="1"'],
      [2,1,166,' horizontal="right"'],[2,1,0,' horizontal="center" wrapText="1"'],
      [0,1,0,' horizontal="center" wrapText="1"'],[0,1,0,' wrapText="1"'],
      [0,1,165,' horizontal="right"'],[0,1,166,' horizontal="right"'],
      [4,0,0,' wrapText="1"'],[2,1,0,' horizontal="center"'],
      [0,1,0,''],[0,1,0,' wrapText="1"']
    ];
    $xfs=[];foreach($formats as [$font,$border,$num,$alignment]){
        $xfs[]='<xf numFmtId="'.$num.'" fontId="'.$font.'" fillId="0" borderId="'.$border.'" xfId="0" applyAlignment="1"'.($num?' applyNumberFormat="1"':'').'><alignment vertical="center"'.$alignment.'/></xf>';
    }
    $styles='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<numFmts count="2"><numFmt numFmtId="165" formatCode="#,##0.000"/><numFmt numFmtId="166" formatCode="#,##0.00"/></numFmts>'
        .$fonts.$fills.$borders.'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        .'<cellXfs count="'.count($xfs).'">'.implode('',$xfs).'</cellXfs></styleSheet>';
    $contents='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';
    $rels='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $workbook='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        .'<sheets><sheet name="Purchase Request" sheetId="1" r:id="rId1"/></sheets>'
        .'<definedNames><definedName name="_xlnm.Print_Area" localSheetId="0">\'Purchase Request\'!$A$1:$F$'.$versionRow.'</definedName></definedNames>'
        .'<calcPr fullCalcOnLoad="1"/></workbook>';
    $wbRels='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $parts=['[Content_Types].xml'=>$contents,'_rels/.rels'=>$rels,'xl/workbook.xml'=>$workbook,
        'xl/_rels/workbook.xml.rels'=>$wbRels,'xl/styles.xml'=>$styles,'xl/worksheets/sheet1.xml'=>$sheet];
    $tmp=tempnam(sys_get_temp_dir(),'ipdams_pr_');
    if($tmp===false)throw new RuntimeException('Temporary spreadsheet storage unavailable');
    @unlink($tmp);$tmp.='.zip';
    try {
        if(class_exists('ZipArchive')){
            $zip=new ZipArchive();
            if($zip->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Unable to build Excel archive');
            foreach($parts as $path=>$value)if(!$zip->addFromString($path,$value))throw new RuntimeException('Excel archive write failed');
            if(!$zip->close())throw new RuntimeException('Excel archive finalize failed');
        }else{
            $zip=new PharData($tmp,0,null,Phar::ZIP);
            foreach($parts as $path=>$value)$zip->addFromString($path,$value);
            unset($zip);
        }
        $out=file_get_contents($tmp);
        if($out===false||strlen($out)<500)throw new RuntimeException('Excel archive empty');
        return $out;
    }finally {@unlink($tmp);}
}
