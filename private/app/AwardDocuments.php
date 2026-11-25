<?php
declare(strict_types=1);
/** v0.2.138: immutable, organization-scoped Award documentary metadata.
 * BAC Resolution/Abstract are documentary printouts; never digital approval. */
final class AwardDocuments {
    private const PREFIX='AWARD_DOC_V1_';
    private static function name(array $a): string {return trim((string)($a['full_name']??''));}
    private static function person(PDO $db,int $org,int $emp,string $date): array {
        $rows=q($db,'SELECT e.full_name,e.position_title,a.designation FROM employee_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.organization_id=? AND a.employee_id=? AND a.effective_from<=? AND (a.effective_to IS NULL OR a.effective_to>?) ORDER BY a.id DESC',[$org,$emp,$date,$date])->fetchAll();
        demand(count($rows)===1,'BAC/Head employee appointment on selected date needs review',409);
        $r=$rows[0];demand(self::name($r)!=='','Employee name unavailable',409);
        return ['employee_id'=>$emp,'name'=>self::name($r),'designation'=>(string)(($r['designation']??'')?:($r['position_title']??''))];
    }
    public static function forIssue(PDO $db,int $org,array $body): array {
        $date=requiredString($body,'resolution_date',10);
        demand(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)===1,'Enter an Abstract / BAC Resolution date',422);
        [$y,$m,$d]=array_map('intval',explode('-',$date));
        demand(checkdate($m,$d,$y),'Invalid Abstract / BAC Resolution date',422);
        $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        demand($date<=$today,'Abstract / BAC Resolution date cannot be in the future',422);
        $rfq=one($db,'SELECT issued_at FROM rfqs WHERE id=? AND organization_id=? AND status=?',[idOf($body,'rfq_id'),$org,'FINALIZED']);
        demand($rfq!==null,'RFQ unavailable for award',404);
        demand($date>=substr((string)$rfq['issued_at'],0,10),'Resolution date cannot precede RFQ issuance',422);
        // Award evaluation cannot precede a submitted/received supplier quotation.
        $lastQuote=one($db,'SELECT MAX(offered_at) AS last_offered_at FROM supplier_quotes WHERE rfq_id=?',
            [idOf($body,'rfq_id')]);
        if(!empty($lastQuote['last_offered_at']))demand($date>=substr((string)$lastQuote['last_offered_at'],0,10),
            'Abstract / Resolution date cannot precede a recorded supplier quotation date',422);
        $terms=q($db,'SELECT id FROM bac_terms WHERE organization_id=? AND starts<=? AND (ends IS NULL OR ends>?) ORDER BY id DESC',[$org,$date,$date])->fetchAll();
        demand(count($terms)===1,'Exactly one BAC term must be effective on the Resolution date',409);
        $members=q($db,'SELECT employee_id,role_title FROM bac_members WHERE term_id=? AND role_title IN (?,?,?) ORDER BY CASE role_title WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END,employee_id',[$terms[0]['id'],'CHAIR','COCHAIR','MEMBER','CHAIR','COCHAIR'])->fetchAll();
        $chair=[];$others=[];$cochairCount=0;$seenEmployee=[];
        foreach($members as $member){
            $employeeId=(int)$member['employee_id'];
            demand($employeeId>0 && !isset($seenEmployee[$employeeId]),'Duplicate or invalid BAC member appointment',409);
            $seenEmployee[$employeeId]=true;
            $person=self::person($db,$org,$employeeId,$date);
            if($member['role_title']==='COCHAIR')$cochairCount++;
            $person['bac_role']=(string)$member['role_title'];
            if($member['role_title']==='CHAIR')$chair[]=$person;
            else $others[]=$person;
        }
        demand(count($chair)===1,'Exactly one BAC Chairperson must be configured for the Resolution date',409);
        demand($cochairCount<=1,'Only one BAC Co-Chairperson may be configured for this Resolution date',409);
        $heads=q($db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? AND effective_from<=? ORDER BY effective_from DESC,id DESC LIMIT 1',[$org,'ORG_OFFICE_HEAD_V1',$date])->fetchAll();
        $head=null;
        if($heads){
            $config=json_decode((string)$heads[0]['config_json'],true,16,JSON_THROW_ON_ERROR);
            if(isset($config['employee_id']) && (int)$config['employee_id']>0){
                $head=self::person($db,$org,(int)$config['employee_id'],$date);
            }
        }
        demand($head!==null,'Configure the School Principal / Head of Procuring Entity in BAC & Heads before creating the BAC Resolution',409);
        return ['resolution_date'=>$date,'bac_term_id'=>(int)$terms[0]['id'],'chair'=>$chair[0],
            'members'=>$others,'head'=>$head];
    }
    private static function quotes(PDO $db,int $org,int $rfqId): array {
        $rows=q($db,'SELECT q.id,q.supplier_id,s.name AS supplier,COUNT(ql.id) AS lines_count,
            COALESCE(SUM(ql.offered_price*COALESCE(ql.rfq_quantity,pl.quantity)),0) AS amount,
            COALESCE(SUM(CASE WHEN ql.compliant<>1 THEN 1 ELSE 0 END),0) AS noncompliant
            FROM supplier_quotes q JOIN rfqs r ON r.id=q.rfq_id AND r.organization_id=?
            JOIN suppliers s ON s.id=q.supplier_id
            LEFT JOIN quote_lines ql ON ql.supplier_quote_id=q.id
            LEFT JOIN pr_lines pl ON pl.id=ql.pr_line_id
            WHERE q.rfq_id=? GROUP BY q.id,q.supplier_id,s.name ORDER BY amount ASC,q.id ASC',[$org,$rfqId])->fetchAll();
        demand(count($rows)<=100,'Too many supplier offers to safely print',409);
        return array_map(static fn($r)=>['quotation_id'=>(int)$r['id'],'supplier_id'=>(int)$r['supplier_id'],
            'supplier'=>(string)$r['supplier'],'amount'=>(string)$r['amount'],
            'line_count'=>(int)$r['lines_count'],'noncompliant_lines'=>(int)$r['noncompliant']],$rows);
    }
    public static function record(PDO $db,int $org,int $actor,int $id,array $people): void {
        demand($id>0,'Award record missing after issuance',500);
        demand(one($db,'SELECT id FROM configuration_versions WHERE organization_id=? AND config_key=? LIMIT 1',[$org,self::PREFIX.$id])===null,
            'Award documentary date was already recorded',409);
        $award=one($db,'SELECT id,rfq_id,pr_id,supplier_id,display_number FROM awards WHERE id=? AND organization_id=?',[$id,$org]);
        demand($award!==null,'Award outside current workspace',404);
        $v=one($db,"SELECT id,version_no,snapshot_json FROM document_versions WHERE organization_id=? AND doc_type='AWARD' AND entity_id=? ORDER BY version_no DESC LIMIT 1",[$org,$id]);
        demand($v!==null,'Award snapshot unavailable',500);
        $offers=self::quotes($db,$org,(int)$award['rfq_id']);
        demand(count($offers)>0,'Award supplier quotations unavailable',409);
        $data=['award_id'=>$id,'rfq_id'=>(int)$award['rfq_id'],'pr_id'=>(int)$award['pr_id'],
            'supplier_id'=>(int)$award['supplier_id'],'award_number'=>(string)$award['display_number'],
            'source_version_id'=>(int)$v['id'],'source_sha256'=>hash('sha256',(string)$v['snapshot_json']),
            'offers'=>$offers]+$people;
        q($db,'INSERT INTO configuration_versions(organization_id,config_key,config_json,effective_from,review_note) VALUES(?,?,?,?,?)',
            [$org,self::PREFIX.$id,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
                $people['resolution_date'],'Award documentary date, BAC appointees and compared supplier offers captured at issuance']);
        q($db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES(?,?,?,?,?,?)',
            [$org,$actor,'AWARD_DOCUMENT_RECORDED','AWARD',$id,
                json_encode(['resolution_date'=>$people['resolution_date'],'source_version_id'=>$v['id'],
                    'quotation_count'=>count($offers)],JSON_THROW_ON_ERROR)]);
    }
    public static function read(PDO $db,int $org,int $id): ?array {
        $rows=q($db,'SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 2',
            [$org,self::PREFIX.$id])->fetchAll();
        demand(count($rows)<=1,'Conflicting award documentary versions',409);
        if(!$rows)return null;
        $data=json_decode((string)$rows[0]['config_json'],true,48,JSON_THROW_ON_ERROR);
        demand(is_array($data)&&(int)($data['award_id']??0)===$id && !empty($data['resolution_date']) && is_array($data['offers']??null),
            'Award documentary source requires review',409);
        $v=one($db,"SELECT snapshot_json FROM document_versions WHERE id=? AND organization_id=? AND doc_type='AWARD' AND entity_id=?",
            [(int)($data['source_version_id']??0),$org,$id]);
        demand($v!==null&&is_string($data['source_sha256']??null)&&hash_equals($data['source_sha256'],hash('sha256',(string)$v['snapshot_json'])),
            'Award source snapshot integrity check failed',409);
        return $data;
    }
    public static function decorateList(PDO $db,int $org,array $rows): array {
        foreach($rows as &$row){
            $meta=self::read($db,$org,(int)$row['id']);
            $reg=one($db,'SELECT created_at FROM document_registry WHERE organization_id=? AND doc_type=? AND entity_id=?',
                [$org,'AWARD',$row['id']]);
            $row['recorded_at']=substr((string)($reg['created_at']??''),0,10);
            $row['resolution_date']=$meta['resolution_date']??null;
            $row['determined_at']=$meta['resolution_date']??$row['recorded_at'];
            $row['date_source']=$meta?'RESOLUTION_DATE':'RECORDED_AT_ONLY';
            $row['quote_amount']=null;
            if($meta){foreach($meta['offers'] as $quote){if((int)$quote['supplier_id']===(int)$row['supplier_id']){
                $row['quote_amount']=$quote['amount'];break;}}}
        }
        return $rows;
    }
    public static function load(PDO $db,int $org,int $id): array {
        $award=one($db,"SELECT a.id,a.display_number,a.rfq_id,a.pr_id,a.supplier_id,a.determination_note,a.status,
            r.display_number rfq_number,r.issued_at,p.display_number pr_number,p.purpose,s.name supplier
            FROM awards a JOIN rfqs r ON r.id=a.rfq_id AND r.organization_id=a.organization_id
            JOIN purchase_requests p ON p.id=a.pr_id AND p.organization_id=a.organization_id
            JOIN suppliers s ON s.id=a.supplier_id WHERE a.organization_id=? AND a.id=?",[$org,$id]);
        demand($award!==null&&$award['status']==='FINALIZED','Finalized award unavailable',404);
        $orgRow=one($db,'SELECT name,address FROM organizations WHERE id=? AND active=1',[$org]);
        demand($orgRow!==null,'Organization unavailable',404);
        $meta=self::read($db,$org,$id);
        // Historical records precede the immutable documentary metadata. Retain
        // the genuine registry date, but never pretend it was a selected resolution date.
        $reg=one($db,'SELECT created_at FROM document_registry WHERE organization_id=? AND doc_type=? AND entity_id=?',[$org,'AWARD',$id]);
        $offers=$meta['offers']??self::quotes($db,$org,(int)$award['rfq_id']);
        return ['award'=>$award,'organization'=>$orgRow,'meta'=>$meta,'offers'=>$offers,
            'recorded_on'=>substr((string)($reg['created_at']??''),0,10)];
    }
    /** Government template-derived printouts; snapshots remain fixed after issue. */
    public static function render(array $loaded,string $form): string {
        demand(in_array($form,['ABSTRACT','RESOLUTION'],true),'Invalid award print format',400);
        $a=$loaded['award']; $org=$loaded['organization']; $meta=$loaded['meta']??null;
        $offers=$loaded['offers']??[];
        $e=static fn(mixed $value): string => htmlspecialchars((string)($value??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $money=static fn(mixed $value): string => number_format((float)$value,2,'.',',');
        $selectedId=(int)($a['supplier_id']??0);
        $selected=array_values(array_filter($offers,static fn($o)=>((int)($o['supplier_id']??0))===$selectedId));
        $selectedOffer=$selected[0]??null;
        // The award's human-entered Evaluation and Determination Note is the only
        // award-wide remarks source. Do not fabricate an LCRB/compliance finding.
        $determinationNote=trim((string)($a['determination_note']??''));
        $documentDate=$meta['resolution_date']??($loaded['recorded_on']??'');
        $verifiedDate=is_array($meta)&&!empty($meta['resolution_date']);
        $heading=$form==='ABSTRACT'?'ABSTRACT OF QUOTATIONS':'BAC Resolution Declaring LCRB and Recommending Approval';
        $html='<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'.
            '<title>'.$e($heading).' · '.$e($a['display_number']??'').'</title>'.
            '<style>@page{size:A4;margin:12mm 16mm 13mm}*{box-sizing:border-box}'.
            'body{font:10pt "Bookman Old Style",Georgia,serif;color:#111;line-height:1.26;max-width:920px;margin:14px auto;padding:0 12px}'.
            '.agency{text-align:center;line-height:1.22;margin-bottom:12px}.agency strong{font-size:11pt}'.
            '.heading{text-align:center;font-weight:bold;font-size:12pt;margin:11px 0 10px}'.
            '.meta{margin:10px 0 12px}.prose{text-align:justify;margin:11px 0;line-height:1.33}'.
            'table{border-collapse:collapse;width:100%;font-size:9pt;margin:6px 0 12px}thead{display:table-header-group}'.
            'th,td{border:1px solid #777;padding:4px 6px;vertical-align:top;text-align:left}th{font-weight:bold;background:#f7f7f7}tr{break-inside:avoid}'.
            '.num{text-align:right;white-space:nowrap}.remark{white-space:pre-line}.bold{font-weight:bold}.caps{text-transform:uppercase}'.
            '.signoffs{break-inside:avoid;page-break-inside:avoid}.signature-section{margin-top:15px}'.
            '.signature-title{text-align:center;font-weight:bold;margin:6px auto 0;text-transform:uppercase}'.
            '.signature-role{text-align:center;margin:1px auto 0}.chair{margin:0 auto 11px;max-width:300px;min-height:35px;padding-top:12px}'.
            '.members{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));column-gap:28px;row-gap:11px;margin:4px auto 12px}'.
            '.member{min-height:40px;padding-top:12px;break-inside:avoid;text-align:center}'.
            '.approved{margin-top:17px;text-align:center;break-inside:avoid;page-break-inside:avoid}.approved-block{text-align:center;max-width:340px;margin:8px auto 0}'.
            '.approve-label{text-align:center;font-weight:bold;margin:0 auto 26px}.date-approved{margin-top:6px}.footnote{font:8pt Arial,sans-serif;color:#555;margin:9px 0}'.
            '.toolbar{text-align:right;margin:0 0 9px}.evidence{font-size:8pt;color:#555;margin:8px 0}'.
            '@media print{body{margin:0;max-width:none;padding:0}.toolbar{display:none}th{print-color-adjust:exact;-webkit-print-color-adjust:exact}}'.
            '</style></head><body><div class="toolbar"><button type="button" onclick="window.print()">Print</button></div>'.
            '<div class="agency">Republic of the Philippines<br><strong>Department of Education</strong><br>'.
            '<strong>'.$e($org['name']??'').'</strong>'.
            (!empty($org['address'])?'<br>'.$e($org['address']):'').'</div>';
        $selectionRemark=static function(array $o)use($selectedId,$determinationNote): string {
            // The saved note applies to the awarded supplier; other bidders do not
            // inherit it or receive an inferred compliance determination.
            return (int)($o['supplier_id']??0)===$selectedId?$determinationNote:'';
        };
        if($form==='ABSTRACT'){
            $html.='<h1 class="heading">ABSTRACT OF QUOTATIONS</h1>'.
                '<div class="meta"><strong>School Name:</strong><br><span class="caps">'.$e($org['name']??'').'</span>'.
                '<br><strong>RFQ No.:</strong> '.$e($a['rfq_number']??'').
                ' &nbsp; <strong>Award No.:</strong> '.$e($a['display_number']??'').
                '<br><strong>'.($verifiedDate?'Abstract Date':'Registry Recorded Date (not the original Abstract date)').':</strong> '.$e($documentDate).'</div>'.
                '<table><thead><tr><th style="width:6%">No.</th><th>NAME OF SUPPLIER/OFFEROR</th><th style="width:21%">QUOTATION/BID AMOUNT</th><th style="width:28%">REMARKS</th></tr></thead><tbody>';
            foreach($offers as $index=>$offer){
                $html.='<tr><td>'.($index+1).'</td><td>'.$e($offer['supplier']??'').'</td>'.
                    '<td class="num">'.$money($offer['amount']??0).'</td><td class="remark">'.$e($selectionRemark($offer)).'</td></tr>';
            }
            $html.='</tbody></table>';
        }else{
            $html.='<h1 class="heading">BAC Resolution Declaring LCRB and Recommending Approval</h1>'.
                '<div class="meta"><strong>Resolution No.:</strong> '.$e($a['display_number']??'').
                ' &nbsp; <strong>RFQ No.:</strong> '.$e($a['rfq_number']??'').
                '<br><strong>'.($verifiedDate?'Resolution Date':'Registry Recorded Date (original Resolution date not captured)').':</strong> '.$e($documentDate).'</div>'.
                '<p class="prose"><strong>WHEREAS,</strong> '.$e(strtoupper((string)($org['name']??''))).
                ' issued the Request for Quotation (RFQ) for '.$e($a['purpose']??'').';</p>'.
                '<p class="prose"><strong>WHEREAS,</strong> '.count($offers).
                ' supplier quotation(s) were recorded for the referenced RFQ from the bidders listed below:</p>'.
                '<table><thead><tr><th>Name of Bidder</th><th style="width:25%">Bid Amount (As Read)</th><th style="width:35%">Remarks</th></tr></thead><tbody>';
            foreach($offers as $offer){
                $html.='<tr><td>'.$e($offer['supplier']??'').'</td><td class="num">'.$money($offer['amount']??0).'</td>'.
                    '<td class="remark">'.$e($selectionRemark($offer)).'</td></tr>';
            }
            $html.='</tbody></table>'.
                '<p class="prose"><strong>WHEREAS,</strong> the detailed evaluation of bids submitted resulted in the following recorded selection:</p>'.
                '<table><thead><tr><th>Name of Bidder</th><th style="width:25%">Bid Amount (As Calculated)</th><th style="width:35%">Remarks</th></tr></thead><tbody>';
            if($selectedOffer){
                $html.='<tr><td>'.$e($selectedOffer['supplier']??'').'</td><td class="num">'.
                    $money($selectedOffer['amount']??0).'</td><td class="remark">'.$e($selectionRemark($selectedOffer)).'</td></tr>';
            }else{
                $html.='<tr><td colspan="3">Recorded winning supplier was not found in the saved supplier comparison. Verify supporting originals.</td></tr>';
            }
            $html.='</tbody></table>'.
                '<p class="prose"><strong>WHEREAS,</strong> the recorded evaluation identifies '.
                '<strong>'.$e($a['supplier']??'').'</strong> as the selected supplier for the referenced RFQ;</p>'.
                '<p class="prose"><strong>NOW, THEREFORE,</strong> We, the Members of the Bids and Awards Committee, hereby RESOLVED:</p>'.
                '<p class="prose">a) To recommend <strong>'.$e($a['supplier']??'').'</strong> as the recorded selected bidder in the amount of Php '.
                $money($selectedOffer['amount']??0).' for '.$e($a['purpose']??'').';</p>'.
                '<p class="prose">b) To recommend for approval by <strong>'.$e($meta['head']['name']??'').'</strong> of '.
                '<strong>'.$e($org['name']??'').'</strong> the above-mentioned findings.</p>';
            $formalDate='____________________________';
            if($verifiedDate){
                try{$dt=new DateTimeImmutable($documentDate);$day=(int)$dt->format('j');$suffix=($day>=11&&$day<=13)?'th':match($day%10){1=>'st',2=>'nd',3=>'rd',default=>'th'};
                    $formalDate=$day.$suffix.' day of '.$dt->format('F Y');}
                catch(Throwable $ignored){}
            }
            $html.='<p class="prose"><strong>RESOLVED,</strong> in '.$e(strtoupper((string)($org['name']??''))).
                ', this '.$e($formalDate).'.</p>';
        }
        // Signatories are bound to the Award issue-date snapshot; never substitute
        // today\'s BAC/Principal for an historical award.
        $chair=is_array($meta)?($meta['chair']??null):null;
        $members=is_array($meta)?($meta['members']??[]):[];
        $cochair=[];$regular=[];
        if(is_array($members))foreach($members as $person){
            if(($person['bac_role']??'')==='COCHAIR')$cochair[]=$person;
            elseif(($person['bac_role']??'')==='MEMBER')$regular[]=$person;
        }
        $renderPerson=static function(array $person,string $role,string $css)use($e):string {
            return '<div class="'.$css.'"><div class="signature-title">'.$e($person['name']??'').
                '</div><div class="signature-role">'.$e($role).'</div></div>';
        };
        // Keep the committee roster with Principal approval when the whole sign-off
        // group fits a page; very long rosters may still paginate naturally.
        $html.='<section class="signoffs"><section class="signature-section">';
        $html.=$renderPerson(is_array($chair)?$chair:[],'Chairman','chair');
        if(count($cochair)===1)$html.=$renderPerson($cochair[0],'Co-Chairperson','chair');
        if(!$meta){$regular=[[],[]];}
        if($regular){
            $html.='<div class="members">';
            foreach($regular as $person)$html.=$renderPerson($person,'Member','member');
            $html.='</div>';
        }
        $html.='</section>';
        if($form==='RESOLUTION'){
            $html.='<section class="approved"><div class="approved-block"><div class="approve-label">Approved by:</div>'.
                '<div class="signature-title">'.$e($meta['head']['name']??'').'</div>'.
                '<div class="signature-role">Head of Procuring Entity</div>'.
                '<div class="date-approved">Date Approved: __________________</div></div></section>';
        }
        $html.='</section>';
        if(!$meta){$html.='<p class="footnote historical">Historical award: original date, BAC roster and School Head appointment were not captured when issued. Verify the original signed records; blank signature names are intentional.</p>';}
        // No software review/disclaimer text on current official printable forms.
        // Missing historic source data remains visibly labelled above and below.
        return $html.'</body></html>';
    }
}
