<?php
declare(strict_types=1);
/**
 * Small self-contained OOXML Word generator for documents requiring wet signatures.
 * No external template processing, shell commands, PDF conversion or user file paths.
 * Both print and export read the exact same saved document version. Images come
 * from the authenticated organisation's private letterhead store only.
 */
final class TravelWord {
    private static function x(mixed $text):string {
        $text=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/','',(string)$text);
        return htmlspecialchars((string)$text,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
    private static function run(mixed $value,bool $bold=false,bool $underline=false,int $pt=22):string {
        $pr='<w:rPr><w:rFonts w:ascii="Bookman Old Style" w:hAnsi="Bookman Old Style" w:eastAsia="Bookman Old Style" w:cs="Bookman Old Style"/><w:sz w:val="'.$pt.'"/>' .($bold?'<w:b/>':'').($underline?'<w:u w:val="single"/>':'').'</w:rPr>';
        return '<w:r>'.$pr.'<w:t xml:space="preserve">'.self::x($value).'</w:t></w:r>';
    }
    private static function para(string $runs,string $align='left',int $before=0,int $after=100,int $left=0,int $first=0):string {
        return '<w:p><w:pPr><w:jc w:val="'.$align.'"/><w:spacing w:before="'.$before.'" w:after="'.$after.'" w:line="280" w:lineRule="auto"/>'.
            ($left||$first?'<w:ind w:left="'.$left.'" w:firstLine="'.$first.'"/>':'').'</w:pPr>'.$runs.'</w:p>';
    }
    private static function p(mixed $text,string $align='left',bool $bold=false,int $before=0,int $after=100,int $left=0,int $first=0):string {
        return self::para(self::run($text,$bold),$align,$before,$after,$left,$first);
    }
    private static function dateText(mixed $date):string {
        $date=(string)$date;
        $v=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        return $v&&$v->format('Y-m-d')===$date?$v->format('F j, Y'):$date;
    }
    private static function signatureCell(string $label,array $person,string $fallback,bool $withDate=false):string {
        $name=trim((string)($person['full_name']??''));
        $position=(string)($person['designation']??$person['position_title']??$fallback);
        if($position==='')$position=$fallback;
        return self::p($label,'left',false,0,430).
            self::para(self::run($name!==''?$name:'_________________________',true,$name!==''), 'left',0,0).
            self::p($position,'left',false,0,$withDate?0:160).
            ($withDate?self::p('Date: ______________','left',false,0,160):'');
    }
    private static function cell(string $body):string {
        return '<w:tc><w:tcPr><w:tcW w:w="4540" w:type="dxa"/><w:tcMar><w:left w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tcMar></w:tcPr>'.$body.'</w:tc>';
    }
    private static function row(string $l,string $r):string {
        return '<w:tr>'.self::cell($l).self::cell($r).'</w:tr>';
    }
    private static function table(string $rows):string {
        return '<w:tbl><w:tblPr><w:tblW w:w="9440" w:type="dxa"/><w:tblBorders><w:top w:val="nil"/><w:bottom w:val="nil"/><w:left w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders></w:tblPr><w:tblGrid><w:gridCol w:w="4720"/><w:gridCol w:w="4720"/></w:tblGrid>'.$rows.'</w:tbl>';
    }
    // A bordered, full-width TA paper form. The same stages are used by print/PDF.
    private static function taCell(string $body,int $width,bool $span=false):string {
        return '<w:tc><w:tcPr><w:tcW w:w="'.$width.'" w:type="dxa"/>'.($span?'<w:gridSpan w:val="2"/>':'').
            '<w:tcMar><w:top w:w="65" w:type="dxa"/><w:left w:w="110" w:type="dxa"/><w:bottom w:w="50" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tcMar></w:tcPr>'.$body.'</w:tc>';
    }
    private static function taInfoRow(string $label,mixed $value):string {
        return '<w:tr>'.self::taCell(self::para(self::run($label,true),'left',0,0),3200).
            self::taCell(self::p((string)$value,'left',false,0,0),6240).'</w:tr>';
    }
    private static function taStagePerson(string $label,string $name,string $position):string {
        // The blank space above the printed name is reserved for the wet signature.
        $nameBlock=self::p('','center',false,150,0).
            self::para(self::run($name,true,true),'center',0,0).
            self::p($position,'center',false,0,0).
            self::p($label,'center',false,0,0);
        $dateBlock=self::p('Date: ______________','center',false,400,0);
        $left='<w:tc><w:tcPr><w:tcW w:w="5650" w:type="dxa"/><w:tcBorders><w:top w:val="nil"/><w:bottom w:val="nil"/><w:left w:val="nil"/><w:right w:val="nil"/></w:tcBorders></w:tcPr>'.$nameBlock.'</w:tc>';
        $right='<w:tc><w:tcPr><w:tcW w:w="3500" w:type="dxa"/><w:tcBorders><w:top w:val="nil"/><w:bottom w:val="nil"/><w:left w:val="nil"/><w:right w:val="nil"/></w:tcBorders></w:tcPr>'.$dateBlock.'</w:tc>';
        $inner='<w:tbl><w:tblPr><w:tblW w:w="9200" w:type="dxa"/><w:tblBorders><w:top w:val="nil"/><w:bottom w:val="nil"/><w:left w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders></w:tblPr><w:tblGrid><w:gridCol w:w="5650"/><w:gridCol w:w="3500"/></w:tblGrid><w:tr>'.$left.$right.'</w:tr></w:tbl>';
        return $inner.self::p('','left',false,0,0);
    }
    private static function taWholeRow(string $body):string {
        return '<w:tr>'.self::taCell($body,9440,true).'</w:tr>';
    }
    private static function taPaperTable(string $rows):string {
        return '<w:tbl><w:tblPr><w:tblW w:w="9440" w:type="dxa"/><w:tblBorders><w:top w:val="single" w:sz="8"/><w:bottom w:val="single" w:sz="8"/><w:left w:val="single" w:sz="8"/><w:right w:val="single" w:sz="8"/><w:insideH w:val="single" w:sz="6"/><w:insideV w:val="single" w:sz="6"/></w:tblBorders><w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid><w:gridCol w:w="3200"/><w:gridCol w:w="6240"/></w:tblGrid>'.$rows.'</w:tbl>';
    }
    private static function imageXml(string $id,int $width,int $height,string $description):string {
        return '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="0"/></w:pPr><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="'.$width.'" cy="'.$height.'"/><wp:docPr id="1" name="IPDAMS approved letterhead" descr="'.self::x($description).'"/><a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="0" name="Letterhead"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="'.$id.'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$width.'" cy="'.$height.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }
    private static function dim(array $meta,string $slot):array {
        $w=max(1,(int)$meta['width']);$h=max(1,(int)$meta['height']);
        $boundW=167*36000;$boundH=($slot==='header'?42:18)*36000;
        $scale=min($boundW/$w,$boundH/$h);
        return [(int)round($w*$scale),(int)round($h*$scale)];
    }
    private static function baseXml(string $tag,string $body):string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:'.$tag.' xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'.$body.'</w:'.$tag.'>';
    }
    private static function rel(string $target,string $type):string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/'.$type.'" Target="'.$target.'"/></Relationships>';
    }
    public static function parts(array $record,array $org,string $division,array $letterhead,array $images=[]):array {
        $s=$record['snapshot'];$type=(string)($s['kind']??'');
        if(!in_array($type,['AR','TA'],true))throw new RuntimeException('Unsupported travel document');
        $holder=is_array($s['signatories']['roles']??null)?$s['signatories']['roles']:[];
        $official=static fn(string $r):array=>is_array($holder[$r]??null)?$holder[$r]:[];
        $budget=$official('budget_officer');$accountant=$official('division_accountant');$asds=$official('asds');$sds=$official('sds');
        $emp=$s['employee']??[];$full=(string)($emp['full_name']??'');$designation=(string)($emp['designation']??$emp['position_title']??'');
        $from=self::dateText($s['travel_from']??'');$to=self::dateText($s['travel_to']??'');
        $dates=$from=== $to?$from:$from.' to '.$to;
        $charge=$s['chargeability']??[];$chargeCode=$charge['code']??'SCHOOL_MOOE';
        $fund=(string)($s['fund']['name']??'SCHOOL MOOE');
        $chargeLabel=$chargeCode==='SCHOOL_MOOE'?$fund:(string)($charge['label']??'School MOOE');
        $chargeRef=trim((string)($charge['reference']??''));
        // A saved allocation breakdown describes where the activity is proposed
        // to be funded; it is not proof of an approved allotment or payment.
        $arFundingText=($chargeCode!=='SCHOOL_MOOE' && $chargeRef!=='')
            ?$chargeRef:mb_strtoupper_safe($chargeLabel);
        $body='';
        if($type==='AR'){
            $body.=self::p('ANNEX A','right',false,0,100).
                self::p('ACTIVITY REQUEST','center',true,130,330).
                self::p(self::dateText($s['document_date']??''),'left',false,100,240).
                self::para(self::run($sds['full_name']??'____________________________',true,true).self::run(''),'left',0,0).
                self::p('Schools Division Superintendent','left',false,0,190).
                self::p($s['signatories']['salutation']??'Madam:','left',false,0,270);
            $body.=self::para(self::run('This is to humbly request from your good office the approval of the local official travel of the undersigned to attend the ').
                self::run($s['activity_title']??'',true).self::run(' on ').self::run($dates,true).
                self::run(', which incurs expenses amounting to Php ______________. The funding for the travel shall be taken from the ').
                self::run($arFundingText,true).self::run('.'),'both',0,180,0,630);
            $body.=self::p('Please refer to the attached itinerary of travel.','left',false,100,170,0,630).
                self::p('It is hoped that this request merits your favorable approval.','left',false,100,170,0,630).
                self::p('Respectfully yours,','left',false,380,280).
                self::para(self::run($full,true,true),'left',0,0).
                self::p($designation,'left',false,0,220);
            $body.=self::table(
                self::row(self::signatureCell('Certification of Available Allotment:',$budget,'Division Budget Officer'),self::signatureCell('Certification of Availability of Funds:',$accountant,'Division Accountant')).
                self::row(self::signatureCell('Recommending Approval:',$asds,'Assistant Schools Division Superintendent'),self::signatureCell('Approved/Disapproved:',$sds,'Schools Division Superintendent')));
        }else{
            $body.=self::p('No.: '.($record['number']??''),'right',false,0,210).
                self::p('TRAVEL AUTHORITY FOR OFFICIAL TRAVEL','center',true,70,120);
            $rows=[
                'Name'=>$full,'Position/Designation'=>$designation,
                'Purpose of Travel (must be supported by attachments)'=>$s['purpose']??'',
                'Host of Activity'=>$s['host']??'','Inclusive Dates'=>$dates,
                'Destination'=>$s['destination']??'','Fund Source'=>$chargeLabel,
            ];
            if($chargeRef!=='')$rows['Funding allocation / reference']=$chargeRef;
            $taRows='';
            foreach($rows as $label=>$value)$taRows.=self::taInfoRow($label,$value);
            $taRows.=self::taWholeRow(
                self::para(self::run('I hereby attest that the information in this form and in the supporting documents attached hereto are true and correct.'),'left',0,30).
                self::taStagePerson('Requesting Employee',$full,$designation));
            $taRows.=self::taWholeRow(
                self::para(self::run('This is to certify that the trip of the requesting employee satisfies all the minimum conditions for authorized official travel and that alternatives to travel are insufficient for purposes stated herein.'),'left',0,30).
                self::taStagePerson('Recommending Authority',(string)($asds['full_name']??''),(string)($asds['designation']??$asds['position_title']??'Assistant Schools Division Superintendent')));
            $taRows.=self::taWholeRow(
                self::p('Approved','left',true,0,30).
                self::taStagePerson('Approving Authority',(string)($sds['full_name']??''),(string)($sds['designation']??$sds['position_title']??'Schools Division Superintendent')));
            $body.=self::taPaperTable($taRows);
        }
        // Print only the prescribed form. Actual wet signatures and signed-copy
        // evidence are managed outside this OOXML renderer, in document records.
        $body.='<w:sectPr><w:headerReference w:type="default" r:id="rId1"/><w:footerReference w:type="default" r:id="rId2"/><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="3130" w:right="1190" w:bottom="1640" w:left="1190" w:header="350" w:footer="300"/>'.($type==='TA'?'<w:pgBorders w:offsetFrom="page"><w:top w:val="single" w:sz="8" w:space="7"/><w:left w:val="single" w:sz="8" w:space="7"/><w:bottom w:val="single" w:sz="8" w:space="7"/><w:right w:val="single" w:sz="8" w:space="7"/></w:pgBorders>':'').'</w:sectPr>';
        $doc=self::baseXml('document','<w:body>'.$body.'</w:body>');
        $header='';$footer='';$rels=[];
        foreach(['header','footer'] as $slot){
            if(($letterhead[$slot]??null)!==null && isset($images[$slot])){
                $meta=$letterhead[$slot];$ext=$meta['mime']==='image/png'?'png':'jpg';
                [$w,$h]=self::dim($meta,$slot);
                if($slot==='header'){$header=self::imageXml('rId1',$w,$h,'Approved organization header');$rels['word/_rels/header1.xml.rels']=self::rel('media/header.'.$ext,'image');}
                else {$footer=self::imageXml('rId1',$w,$h,'Approved organization footer');$rels['word/_rels/footer1.xml.rels']=self::rel('media/footer.'.$ext,'image');}
                $rels['word/media/'.$slot.'.'.$ext]=$images[$slot];
            }elseif($slot==='header'){
                $region=$s['signatories']['region_heading']??'';
                $header=self::p('Republic of the Philippines','center',true,0,0).
                    self::p('Department of Education','center',true,0,0).
                    self::p($region,'center',false,0,0).
                    self::p($division,'center',false,0,0).
                    self::p($org['name']??'','center',false,0,0);
            }else {
                $footer=self::p((string)($org['address']??''),'center',false,0,0).
                    self::p(trim((string)($org['phone']??'')).' · '.trim((string)($org['email']??'')),'center',false,0,0);
            }
        }
        $contents='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Default Extension="jpg" ContentType="image/jpeg"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>';
        $docRel='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/></Relationships>';
        return array_merge([
            '[Content_Types].xml'=>$contents,
            '_rels/.rels'=>self::rel('word/document.xml','officeDocument'),
            'word/_rels/document.xml.rels'=>$docRel,
            'word/document.xml'=>$doc,
            'word/header1.xml'=>self::baseXml('hdr',$header),
            'word/footer1.xml'=>self::baseXml('ftr',$footer),
        ],$rels);
    }
    public static function write(array $parts,string $path):void {
        if(!class_exists('ZipArchive'))throw new RuntimeException('Word export requires PHP ZipArchive');
        $zip=new ZipArchive();
        if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)
            throw new RuntimeException('Unable to prepare Word download');
        try{
            foreach($parts as $name=>$bytes){if(!$zip->addFromString($name,$bytes))throw new RuntimeException('Unable to package Word document');}
        }finally{$zip->close();}
    }
}
function mb_strtoupper_safe(string $s):string {return function_exists('mb_strtoupper')?mb_strtoupper($s,'UTF-8'):strtoupper($s);}
