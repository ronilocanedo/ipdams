<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/app/TravelWorkflow.php';
require_once dirname(__DIR__).'/private/app/TravelBranding.php';
ini_set('display_errors','0');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');
session_name('IPDAMSSESSID');session_start();
try {
    $db=database();$user=currentUser($db);$travel=new TravelWorkflow($db,$user);
    $record=$travel->detail($_GET);$s=$record['snapshot'];
    $type=(string)($_GET['type']??'');
    demand(in_array($type,['AR','TA'],true),'Invalid travel document');
    $org=(int)$s['organization_id'];
    $o=one($db,'SELECT name,kind,parent_division_id,address,contact_phone AS phone,contact_email AS email FROM organizations WHERE id=?',[$org]);
    demand($o!==null,'Organization unavailable',404);
    $division=(int)($o['kind']==='DIVISION'?$org:($o['parent_division_id']??0));
    $divisionRow=$division>0?one($db,'SELECT name FROM organizations WHERE id=? AND kind=?',[$division,'DIVISION']):null;
    $escape=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $capDate=static function(?string $value):string{
        if(!$value)return '';
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        return $d?$d->format('F j, Y'):$value;
    };
    $employee=$s['employee']['full_name']??'';
    $designation=$s['employee']['designation']??$s['employee']['position_title']??'';
    $charge=$s['chargeability']??[];
    $chargeCode=(string)($charge['code']??'SCHOOL_MOOE');
    $chargeLabel=(string)($charge['label']??'School MOOE');
    $chargeReference=trim((string)($charge['reference']??''));
    $localFund=$s['fund']??null;
    $fundLabel=is_array($localFund)?trim((string)($localFund['name']??'')):'';
    $chargeText=$chargeCode==='SCHOOL_MOOE'?($fundLabel?:'SCHOOL MOOE'):$chargeLabel;
    // For externally/split-funded Activity Requests, the user's saved funding
    // allocation breakdown is the documentary source wording. Never infer an
    // amount, approval, source code, or a school-funded obligation from it.
    // Retain the old generic label only for older snapshots without a reference.
    $arFundingText=($chargeCode!=='SCHOOL_MOOE' && $chargeReference!=='')
        ?$chargeReference:strtoupper($chargeText);
    $signatories=$s['signatories']??['version_id'=>0,'region_heading'=>'','salutation'=>'Sir/Madam:','roles'=>[]];
    $holders=is_array($signatories['roles']??null)?$signatories['roles']:[];
    $person=static function(string $role)use($holders):array{
        $r=$holders[$role]??null;
        return is_array($r)&&!empty($r['full_name'])?$r:[];
    };
    $budget=$person('budget_officer');$accountant=$person('division_accountant');$asds=$person('asds');$sds=$person('sds');
    $regionText=trim((string)($signatories['region_heading']??''));
    $salutation=(string)($signatories['salutation']??'Sir/Madam:');
    $dates=$capDate((string)($s['travel_from']??''));
    if(($s['travel_to']??'')!==($s['travel_from']??''))$dates.=' to '.$capDate((string)($s['travel_to']??''));
    $isTaloto=str_contains(strtoupper((string)$o['name']),'TALOTO NATIONAL HIGH SCHOOL');
    // Uploads are scoped to THIS originating organization; a school never
    // silently inherits a Division or other school's physical letterhead.
    $letterhead=(new TravelBranding($db,$user))->currentForPrint($org);
    $artUrl=static function(string $slot)use($org,$letterhead):string{
        return 'travel_branding_image.php?organization_id='.$org.'&amp;slot='.$slot.'&amp;version='.$letterhead['version_id'];
    };
    $asset='../assets/';
    $title=$type==='AR'?'Activity Request':'Travel Authority For Official Travel';
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Cache-Control: no-store');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$escape($title).' · '.$escape($record['number']).'</title><style>
        *{box-sizing:border-box} html,body{margin:0;padding:0} body{background:#e9eef0;color:#0a0a0a;font-family:"Bookman Old Style","URW Bookman","Bookman",Georgia,serif;font-size:11pt}
        .actions{font:14px/1.5 system-ui,Arial,sans-serif;background:#e7f3f5;padding:13px 16px;max-width:210mm;margin:10px auto;display:flex;align-items:center;gap:16px;justify-content:space-between;border:1px solid #abc5cc}
        .actions button{border:0;background:#116782;color:white;border-radius:5px;padding:9px 14px;cursor:pointer;white-space:nowrap}
        .sheet{width:210mm;min-height:297mm;margin:12px auto;position:relative;background:#fff;padding:12mm 21mm 25mm;box-shadow:0 3px 20px #0002;line-height:1.13}
        .header{height:49mm;text-align:center;position:relative;border-bottom:.35mm solid #111;}
        .seal{display:block;width:17mm;height:16mm;object-fit:contain;margin:0 auto .3mm}
        .header .republic{font-size:9.5pt;font-family:"Old English Text MT","Times New Roman",serif;font-weight:bold}
        .header .deped{font-size:11pt;font-weight:bold;font-family:"Old English Text MT",Georgia,"Times New Roman",serif}
        .header .line{font-size:8pt;line-height:1.02;text-transform:uppercase}
        .header.uploaded{border-bottom:0;display:flex;align-items:flex-start;justify-content:center}
        .header .official-header{display:block;width:100%;height:48mm;object-fit:contain;object-position:center top}
        .annex{text-align:right;font-size:11pt;font-style:italic;padding-top:2mm;padding-right:1mm}
        .doc-title{text-align:center;font-size:11pt;font-weight:bold;margin-top:3mm;margin-bottom:6mm;text-transform:uppercase}
        .doc-date{margin-bottom:5mm;font-size:11pt}
        .to{font-size:11pt;line-height:1.15;margin-bottom:4mm}
        .to .name{font-weight:bold;text-decoration:underline}
        .greeting{font-size:11pt;margin-bottom:5mm}
        .body-paragraph{margin:0 0 5.2mm 0;font-size:11pt;line-height:1.2;text-align:justify;text-indent:12mm}
        .body-paragraph.followup{margin-top:4mm}
        .salute{margin-top:11mm;margin-bottom:9mm}
        .requester .name,.sig-name{font-weight:bold;text-decoration:underline;text-transform:uppercase}
        .requester{font-size:11pt;line-height:1.13}
        .sig-grid{display:grid;grid-template-columns:1fr 1fr;column-gap:5mm;row-gap:7mm;margin-top:7mm;font-size:11pt}
        .sig-block{min-width:0;overflow-wrap:anywhere}.sig-block .role{min-height:12mm}.sig-block .sig-name{display:block;min-height:3.4mm}
        .sig-block .designation{display:block;font-size:11pt}.empty-name{display:block;min-height:3.4mm;width:75%;border-bottom:1px solid #222}
        .footer{position:absolute;left:21mm;right:21mm;bottom:3mm;height:20mm;border-top:.3mm solid #111;display:flex;align-items:center;gap:5mm;font-size:7.6pt;line-height:1.08}
        .marks{height:16mm;width:auto;object-fit:contain;max-width:64mm}.school-mark{height:14.5mm;object-fit:contain}
        .school-contact{margin-left:auto;min-width:63mm;max-width:76mm}
        .footer.uploaded{border-top:0;display:block}
        .footer .official-footer{width:100%;height:20mm;object-fit:contain;object-position:center bottom;display:block}.school-contact div{white-space:normal;overflow-wrap:anywhere}
        .ta{border:0.45mm solid #111;padding-left:12mm;padding-right:12mm}
        .ta .doc-title{margin-top:5mm;margin-bottom:5mm}.ta-number{text-align:right;font-size:11pt;margin-bottom:8mm}
        .ta-number{text-align:right;font-size:11pt;margin-top:1mm;margin-bottom:2mm}
        .ta-number span{border:1px solid #111;padding:1.8mm 3mm;display:inline-block;min-width:49mm;text-align:left}
        .ta-paper{width:100%;border:1px solid #111;font-size:11pt;margin-top:4mm;line-height:1.2}
        .ta-info{border-collapse:collapse;table-layout:fixed;width:100%;font-size:11pt}
        .ta-info th,.ta-info td{border:1px solid #111;vertical-align:middle;padding:1.2mm 2mm;overflow-wrap:anywhere;text-align:left}
        .ta-info th{width:34%;font-weight:bold}.ta-info td{width:66%}
        .ta-info .ta-purpose-note{font-size:8.7pt;font-style:italic;font-weight:normal;line-height:1.12}
        .ta-paper-section{border-top:1px solid #111;padding:2mm 3mm 2.8mm;break-inside:avoid;page-break-inside:avoid}
        .ta-paper-section .ta-statement{font-size:10pt;font-style:italic;margin:0 0 3mm;line-height:1.2}
        .ta-paper-section .ta-approved{font-weight:bold;margin-bottom:2mm}
        .ta-person-row{display:grid;grid-template-columns:58% 42%;align-items:end;min-height:18mm;gap:1mm}
        .ta-person-row .ta-person{min-width:0;text-align:center;align-self:end;padding:7mm 1mm 0}
        .ta-person .sig-name{display:block;font-weight:bold;text-decoration:underline}
        .ta-person .ta-position{display:block;font-size:9.5pt;font-style:italic;line-height:1.16}
        .ta-person .ta-caption{display:block;font-size:11pt;margin-top:1mm}
        .ta-person-row .ta-date{text-align:center;align-self:end;padding-bottom:2mm;white-space:nowrap}
        .ta .footer{left:12mm;right:12mm}
        .manual-note{margin-top:6mm;font-size:11pt;font-style:italic}
        @page{size:A4 portrait;margin:0}
        @media print{html,body{background:#fff}.actions{display:none}.sheet{margin:0;padding-top:12mm;box-shadow:none;width:210mm;height:297mm;min-height:297mm;page-break-after:always} .sheet:last-child{page-break-after:auto}img{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
        @media screen and (max-width:820px){.sheet{transform-origin:top left;transform:scale(.6);margin:0}.actions{margin:0 0 5px;max-width:none}}
    </style></head><body>';
    echo '<div class="actions"><span><strong>Unsigned draft for paper approval.</strong> Review official names, document content and funding before printing. No electronic approval, bank posting, or payment is recorded. Upload authorized organization letterhead artwork under Reference Data → Document Letterhead for exact printing. Until configured, the standard fallback is used.</span><button onclick="window.print()">Print / Save PDF</button></div>';
    echo '<main class="sheet '.($type==='TA'?'ta':'ar').'">';
    if($letterhead['header']!==null){
        echo '<header class="header uploaded"><img class="official-header" alt="Approved organization header" src="'.$artUrl('header').'"></header>';
    }else{
        echo '<header class="header"><img class="seal" alt="DepEd seal from supplied sample" src="'.$asset.'travel-sample-deped-seal.png"><div class="republic">Republic of the Philippines</div><div class="deped">Department of Education</div>';
        echo '<div class="line">'.$escape($regionText).'</div><div class="line">'.$escape((string)($divisionRow['name']??'')).'</div><div class="line">'.$escape((string)$o['name']).'</div></header>';
    }
    if($type==='AR'){
        $sdsName=(string)($sds['full_name']??'');
        $budgetName=(string)($budget['full_name']??'');$acctName=(string)($accountant['full_name']??'');
        $asdsName=(string)($asds['full_name']??'');
        $designationOf=static fn(array $r,string $default):string=>$r===[]?$default:(string)($r['designation']?:($r['position_title']?:$default));
        $slot=static function(array $holder,string $fallback)use($escape,$designationOf):string{
            $name=trim((string)($holder['full_name']??''));
            return ($name!==''?'<span class="sig-name">'.$escape($name).'</span>':'<span class="empty-name"></span>').
                '<span class="designation">'.$escape($designationOf($holder,$fallback)).'</span>';
        };
        echo '<div class="annex">ANNEX A</div><div class="doc-title">ACTIVITY REQUEST</div>';
        echo '<div class="doc-date">'.$escape($capDate((string)$s['document_date'])).'</div>';
        echo '<div class="to"><span class="name">'.($sdsName!==''?$escape($sdsName):'_____________________________').'</span><div>Schools Division Superintendent</div></div>';
        echo '<div class="greeting">'.$escape($salutation).'</div>';
        // The approved paper sample intentionally leaves the amount blank.
        // Never print the internal draft estimate or overwrite its historical value.
        echo '<p class="body-paragraph">This is to humbly request from your good office the approval of the local official travel of the undersigned to attend the <strong>'.$escape((string)$s['activity_title']).'</strong> on <strong>'.$escape($dates).'</strong>, which incurs expenses amounting to Php <span style="display:inline-block;min-width:25mm;border-bottom:1px solid #111">&nbsp;</span>. The funding for the travel shall be taken from the <strong>'.$escape($arFundingText).'</strong>.</p>';
        echo '<p class="body-paragraph">Please refer to the attached itinerary of travel.</p>';
        echo '<p class="body-paragraph">It is hoped that this request merits your favorable approval.</p>';
        echo '<div class="salute">Respectfully yours,</div>';
        echo '<div class="requester"><span class="name">'.$escape($employee).'</span><br>'.$escape($designation).'</div>';
        echo '<div class="sig-grid">';
        echo '<div class="sig-block"><div class="role">Certification of Available Allotment:</div>'.$slot($budget,'Division Budget Officer').'</div>';
        echo '<div class="sig-block"><div class="role">Certification of Availability of Funds:</div>'.$slot($accountant,'Division Accountant').'</div>';
        echo '<div class="sig-block"><div class="role">Recommending Approval:</div>'.$slot($asds,'Assistant Schools Division Superintendent').'</div>';
        echo '<div class="sig-block"><div class="role">Approved/Disapproved:</div>'.$slot($sds,'Schools Division Superintendent').'</div>';
        echo '</div>';
    }else{
        echo '<div class="ta-number"><span>No.: <strong>'.$escape((string)$record['number']).'</strong></span></div>';
        echo '<div class="doc-title">TRAVEL AUTHORITY FOR OFFICIAL TRAVEL</div>';
        echo '<section class="ta-paper"><table class="ta-info"><tbody>';
        foreach([
            'Name'=>$employee,'Position/Designation'=>$designation,
            'Purpose of Travel'=>$s['purpose']??'',
            'Host of Activity'=>$s['host']??'','Inclusive Dates'=>$dates,'Destination'=>$s['destination']??'',
            'Fund Source'=>$chargeText,
        ] as $label=>$value){
            // The note is a subordinate printing instruction, not part of the label.
            $printLabel=$label==='Purpose of Travel'
                ?'Purpose of Travel <span class="ta-purpose-note">(must be supported by attachments)</span>'
                :$escape($label);
            echo '<tr><th scope="row">'.$printLabel.'</th><td>'.$escape($value).'</td></tr>';
        }
        if($chargeReference!=='')echo '<tr><th scope="row">Funding allocation / reference</th><td>'.$escape($chargeReference).'</td></tr>';
        echo '</tbody></table>';
        $taSigner=static function(string $name,string $position,string $caption)use($escape):string{
            return '<div class="ta-person-row"><div class="ta-person"><span class="sig-name">'.$escape($name).'</span><span class="ta-position">'.$escape($position).'</span><span class="ta-caption">'.$escape($caption).'</span></div><div class="ta-date">Date: ______________</div></div>';
        };
        echo '<section class="ta-paper-section"><p class="ta-statement">I hereby attest that the information in this form and in the supporting documents attached hereto are true and correct.</p>';
        echo $taSigner($employee,$designation,'Requesting Employee').'</section>';
        echo '<section class="ta-paper-section"><p class="ta-statement">This is to certify that the trip of the requesting employee satisfies all the minimum conditions for authorized official travel and that alternatives to travel are insufficient for purposes stated herein.</p>';
        echo $taSigner((string)($asds['full_name']??''),(string)($asds['designation']??$asds['position_title']??'Assistant Schools Division Superintendent'),'Recommending Authority').'</section>';
        echo '<section class="ta-paper-section"><div class="ta-approved">Approved</div>';
        echo $taSigner((string)($sds['full_name']??''),(string)($sds['designation']??$sds['position_title']??'Schools Division Superintendent'),'Approving Authority').'</section></section>';
    }
    if($letterhead['footer']!==null){
        echo '<footer class="footer uploaded"><img class="official-footer" alt="Approved organization footer" src="'.$artUrl('footer').'"></footer>';
    }else{
        echo '<footer class="footer"><img class="marks" alt="DepEd Matatag and Bagong Pilipinas emblems" src="'.$asset.'travel-sample-national-marks.png">';
        if($isTaloto)echo '<img class="school-mark" alt="Taloto school seal from supplied sample" src="'.$asset.'travel-sample-taloto-school-mark.png">';
        echo '<div class="school-contact">';
        if(trim((string)($o['address']??''))!=='')echo '<div>Address: '.$escape($o['address']).'</div>';
        if(trim((string)($o['phone']??''))!=='')echo '<div>Telephone No. '.$escape($o['phone']).'</div>';
        if(trim((string)($o['email']??''))!=='')echo '<div>Email Address: '.$escape($o['email']).'</div>';
        echo '</div></footer>';
    }
    echo '</main></body></html>';
}catch(DomainError $e){http_response_code($e->status);header('Content-Type: text/plain; charset=utf-8');echo 'Cannot print: '.$e->getMessage();}
catch(Throwable $e){error_log('Travel print failed: '.$e->getMessage());http_response_code(500);header('Content-Type: text/plain; charset=utf-8');echo 'Travel form temporarily unavailable';}
