<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/app/bootstrap.php';
require_once dirname(__DIR__).'/private/app/RoleFunctions.php';
require_once dirname(__DIR__).'/private/app/RfqCanvassSignatories.php';
require_once dirname(__DIR__).'/private/app/RfqCanvassPrint.php';
require_once dirname(__DIR__).'/private/app/AwardDocuments.php';
require_once dirname(__DIR__).'/private/app/ProcurementDocumentDates.php';
require_once dirname(__DIR__).'/private/app/IarDocumentCorrections.php';
require_once dirname(__DIR__).'/private/app/IarAppendix62.php';
require_once dirname(__DIR__).'/private/app/PoDocuments.php';
ini_set('session.cookie_httponly','1');session_name('IPDAMSSESSID');session_start();
try{
    $db=database();$user=currentUser($db);$org=(int)($_GET['organization_id']??0);scope($db,$user,$org);
    $type=requiredString($_GET,'type',16);demand(in_array($type,['PR','RFQ','PO','IAR','DV','RIS','AWARD','ICS','PAR'],true),'Unsupported document');$id=idOf($_GET,'id');
    if(($_GET['special']??null)==='1') (new RoleFunctions($db,$user))->authorizeSpecialPrint($org,$type);
    if($user['role']==='VIEWER' && in_array($type,['RIS','ICS','PAR'],true))
        ipdamsMooeViewerRequireIssuedDocument($db,$user,$org,$type,$id,requiredString($_GET,'month',7));
    $v=one($db,'SELECT * FROM document_versions WHERE organization_id=? AND doc_type=? AND entity_id=? ORDER BY version_no ASC LIMIT 1',[$org,$type,$id]);demand($v!==null,'No immutable finalized snapshot',404);
    $reg=one($db,'SELECT display_number FROM document_registry WHERE organization_id=? AND doc_type=? AND entity_id=?',[$org,$type,$id]);
    $name=one($db,'SELECT name FROM organizations WHERE id=?',[$org]);$snapshot=json_decode((string)$v['snapshot_json'],true);
    $esc=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    header('Content-Type: text/html; charset=utf-8');header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store');
    if($type==='AWARD'){
        $form=(string)($_GET['form']??'ABSTRACT');
        demand(in_array($form,['ABSTRACT','RESOLUTION'],true),'Unsupported Abstract / BAC Resolution format',400);
        $loaded=AwardDocuments::load($db,$org,$id);
        header('X-Frame-Options: DENY');
        echo AwardDocuments::render($loaded,$form);
        exit;
    }
    if($type==='DV'){
        require_once dirname(__DIR__).'/private/app/DvAppendix32.php';
        header('X-Frame-Options: DENY');
        echo DvAppendix32::render(DvAppendix32::load($db,$org,$id));
        exit;
    }
    if($type==='PO'){
        demand($reg!==null,'Registered PO required',404);
        header('X-Frame-Options: DENY');
        echo PoDocuments::render(PoDocuments::load($db,$org,$id));
        exit;
    }
    // Formal authenticated Appendix 63 RIS, from an existing issued document.
    // Never create an RIS or populate absent paper-signature evidence.
    if($type==='RIS'){
        demand($reg!==null,'Registered RIS required',404);
        $ris=one($db,'SELECT id,display_number,status,requestor,pr_id FROM requisition_issue_slips WHERE id=? AND organization_id=?',[$id,$org]);
        demand($ris!==null && $ris['status']==='ISSUED' &&
            $ris['display_number']===$reg['display_number'],'Only issued RIS is printable',404);
        demand(is_array($snapshot) && (string)($snapshot['number']??'')===(string)$reg['display_number'] &&
            is_array($snapshot['lines']??null),'Incomplete immutable RIS issuance snapshot',409);
        $rows=q($db,'SELECT r.id,r.item_id,r.requested_qty,r.issued_qty,i.name,u.code AS unit
            FROM ris_lines r JOIN item_catalog i ON i.id=r.item_id
            JOIN units u ON u.id=i.unit_id WHERE r.ris_id=? ORDER BY r.id LIMIT 101',[$id])->fetchAll();
        demand(count($rows)>0 && count($rows)<=100,'Issued RIS contains an unsupported line count',409);
        $sourceLines=[];
        foreach($snapshot['lines'] as $line){
            if(!is_array($line))continue;
            $sourceLines[(int)($line['id']??0)]=$line;
        }
        demand(count($sourceLines)===count($rows),'RIS issuance source membership must be reconciled',409);
        foreach($rows as $line){
            $saved=$sourceLines[(int)$line['id']]??null;
            demand(is_array($saved) && (int)($saved['item_id']??0)===(int)$line['item_id'] &&
                (string)($saved['issued_qty']??'')===(string)$line['issued_qty'] &&
                (string)($saved['requested_qty']??'')===(string)$line['requested_qty'],
                'RIS issuance source has changed; reconcile before printing',409);
        }
        $purpose='';
        if(!empty($ris['pr_id'])){
            $pr=one($db,'SELECT purpose FROM purchase_requests WHERE id=? AND organization_id=?',[(int)$ris['pr_id'],$org]);
            $purpose=(string)($pr['purpose']??'');
        }
        require_once dirname(__DIR__).'/private/app/OfficialAccountabilityPrint.php';
        header('X-Frame-Options: DENY');
        echo OfficialAccountabilityPrint::ris([
            'number'=>$reg['display_number'],'school_name'=>$name['name']??'',
            'requestor'=>$ris['requestor'],'purpose'=>$purpose,'lines'=>$rows
        ]);
        exit;
    }
    if($type==='ICS'||$type==='PAR'){
        $doc=one($db,'SELECT id,asset_id,doc_type,custodian,display_number,status FROM accountability_documents WHERE id=? AND organization_id=? AND doc_type=?',[$id,$org,$type]);
        demand($doc!==null && $doc['status']==='FINALIZED' && $reg!==null && $doc['display_number']===$reg['display_number'],'Registered accountability document unavailable',404);
        demand(is_array($snapshot) && (int)($snapshot['asset_id']??0)===(int)$doc['asset_id'] && ($snapshot['number']??null)===$reg['display_number'] && ($snapshot['custodian']??null)===$doc['custodian'] && trim((string)($snapshot['tag']??''))!=='','Incomplete accountability snapshot',404);
        $asset=one($db,'SELECT a.id,a.iar_line_id,a.opening_record,a.acquisition_cost,a.property_profile,a.serial_number,i.name AS description,i.category,u.code AS unit FROM assets a JOIN item_catalog i ON i.id=a.item_id JOIN units u ON u.id=i.unit_id WHERE a.id=? AND a.organization_id=?',[$doc['asset_id'],$org]);
        demand($asset!==null && (($type==='ICS'&&$asset['category']==='SEMI_EXPENDABLE')||($type==='PAR'&&$asset['category']==='PPE')),'Property classification requires review',404);
        if($type==='ICS'){
            // Standalone one-asset ICS uses the same Appendix 59 layout as the
            // multi-asset browser form. Missing signing details stay blank.
            $profile=json_decode((string)($asset['property_profile']??''),true);
            $shared=is_array($profile)?($profile['shared']??[]):[];
            $life=is_array($shared)?($shared['useful_life_years']??''):'';
            $life=trim((string)$life);
            require_once dirname(__DIR__).'/private/app/OfficialAccountabilityPrint.php';
            header('X-Frame-Options: DENY');
            echo OfficialAccountabilityPrint::ics([
                'number'=>$doc['display_number'],
                'items'=>[[
                    'property_tag'=>$snapshot['tag'],
                    'name'=>$asset['description'],'unit'=>$asset['unit'],
                    'brand'=>is_array($shared)?(string)($shared['brand']??''):'',
                    'model'=>is_array($shared)?(string)($shared['model']??''):'',
                    'serial_number'=>(string)($asset['serial_number']??''),
                    'cost'=>$asset['acquisition_cost'],
                    'useful_life'=>$life===''?'':$life.' years'
                ]],
                'received_by'=>['name'=>$snapshot['custodian']],
            ]);
            exit;
        }
        header('X-Frame-Options: DENY');
        $heading=$type==='ICS'?'INVENTORY CUSTODIAN SLIP':'PROPERTY ACKNOWLEDGMENT RECEIPT';
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$esc($type.' '.$doc['display_number']).'</title><style>body{font:13px Arial,sans-serif;max-width:850px;margin:24px auto;padding:16px;color:#24313b}header{text-align:center;border-bottom:2px solid #24313b;padding-bottom:15px}h1{font-size:21px}dl{display:grid;grid-template-columns:180px 1fr;gap:12px;margin:28px 0}dt{font-weight:bold}dd{margin:0;overflow-wrap:anywhere}.notice{padding:12px;border:1px solid #aaa;font-size:11px}.sign{display:grid;grid-template-columns:1fr 1fr;gap:35px;margin-top:90px}.sign div{border-top:1px solid #333;padding-top:8px}.toolbar{text-align:right}@media print{@page{size:A4;margin:16mm}body{margin:0;max-width:none}.toolbar{display:none}}</style></head><body>';
        echo '<div class="toolbar"><button type="button" onclick="window.print()">Print '.$esc($type).'</button></div><header><div>IPDAMS · '.$esc($name['name']??'').'</div><h1>'.$esc($heading).'</h1><div>Control No. '.$esc($doc['display_number']).'</div></header>';
        echo '<p class="notice">Working accountability printout subject to Division/DepEd/COA template review. Signatures are blank for manual execution. Printing does not constitute approval, acceptance or a new issue.</p>';
        echo '<dl><dt>Property tag at issuance</dt><dd>'.$esc($snapshot['tag']).'</dd><dt>Item description</dt><dd>'.$esc($asset['description']??'').'</dd><dt>Classification</dt><dd>'.$esc($asset['category']).'</dd><dt>Recorded custodian</dt><dd>'.$esc($snapshot['custodian']).'</dd><dt>Acquisition source</dt><dd>'.$esc($asset['iar_line_id']?'IAR-linked record':'Opening / provenance not verified').'</dd></dl>';
        echo '<div class="sign"><div>Issued by — printed name, signature and date</div><div>Received by — printed name, signature and date</div></div></body></html>';exit;
    }
    if($type==='RFQ'){
        // Print the latest immutable corrected version, retaining prior versions
        // in document_versions for audit, without creating a new RFQ number.
        $v=one($db,"SELECT * FROM document_versions WHERE organization_id=? AND doc_type='RFQ' AND entity_id=? ORDER BY version_no DESC LIMIT 1",[$org,$id]);
        demand($v!==null,'No finalized RFQ snapshot',404);
        $snapshot=json_decode((string)$v['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($snapshot),'Invalid RFQ snapshot',404);
        // Use an existing finalized RFQ number and the immutable source PR snapshot.
        // Printing does not create a new RFQ or record a supplier quotation.
        $rfq=one($db,"SELECT r.id,r.pr_id,r.issued_at,r.status,p.purpose,p.requesting_unit,p.status AS pr_status
            FROM rfqs r JOIN purchase_requests p ON p.id=r.pr_id
            WHERE r.id=? AND r.organization_id=? AND p.organization_id=?",[$id,$org,$org]);
        demand($rfq!==null && $rfq['status']==='FINALIZED' && $rfq['pr_status']==='FINALIZED','Finalized RFQ and linked PR required',404);
        demand((int)($snapshot['pr_id']??0)===(int)$rfq['pr_id'],'RFQ snapshot does not match linked PR',404);
        $prSnapshot=one($db,"SELECT snapshot_json FROM document_versions
            WHERE organization_id=? AND doc_type='PR' AND entity_id=? ORDER BY version_no DESC LIMIT 1",[$org,$rfq['pr_id']]);
        demand($prSnapshot!==null,'Finalized source PR snapshot unavailable',404);
        $prData=json_decode((string)$prSnapshot['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        $lines=$snapshot['lines']??null;
        if(!is_array($lines)||!$lines){$lines=$prData['lines']??null;} // Legacy RFQs predate line selection.

        demand(is_array($lines) && count($lines)>0,'No PR lines to print',404);
        $number=(string)($reg['display_number']??$snapshot['number']??'');
        $prNumber=(string)($prData['number']??'');
        $school=(string)($name['name']??'');
        $issueDate=(string)$rfq['issued_at'];
        // Newly issued RFQs retain an immutable date in the snapshot. Legacy RFQs
        // that predate date snapshots are still permitted to print normally.
        if(array_key_exists('issued_at',$snapshot))demand($issueDate===(string)$snapshot['issued_at'],
            'RFQ issue date differs from its finalized snapshot. Administrative review required.',409);
        $organization=one($db,'SELECT name,address,contact_phone FROM organizations WHERE id=? AND active=1',[$org]);
        demand($organization!==null,'Active issuing organization unavailable',404);
        // Never resolve an earlier official from today's directory on reprint.
        // The original selected names are an immutable, dated RFQ sidecar.
        $participants=RfqCanvassSignatories::read($db,$org,$id);
        header('Content-Type: text/html; charset=utf-8');header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');header('Cache-Control: no-store');
        echo RfqCanvassPrint::render([
            'organization'=>$organization,'number'=>$number,'issued_at'=>$issueDate,
            'purpose'=>$rfq['purpose'],'lines'=>$lines,'participants'=>$participants
        ]);
        exit;
    }
    if($type==='PR'){
        $current=one($db,'SELECT status,display_number FROM purchase_requests WHERE id=? AND organization_id=?',[$id,$org]);
        demand($current!==null && $current['status']==='FINALIZED' && $reg!==null &&
            $current['display_number']===$reg['display_number'],'Only an active finalized PR may be printed',404);
        $v=one($db,"SELECT * FROM document_versions WHERE organization_id=? AND doc_type='PR' AND entity_id=? ORDER BY version_no DESC LIMIT 1",[$org,$id]);
        demand($v!==null,'PR finalized version unavailable',404);
        $snapshot=json_decode((string)$v['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($snapshot)&&is_array($snapshot['header']??null)&&is_array($snapshot['lines']??null),
            'Invalid PR snapshot',404);
        demand((string)($snapshot['number']??'')===(string)$reg['display_number'],
            'PR snapshot number does not match registry',404);
        $header=$snapshot['header'];
        require_once dirname(__DIR__).'/private/app/PrPrint.php';
        // Prefer immutable approver snapshots if newer issuance paths include them.
        // Older PR snapshots do not have an approver: use the school-head appointment
        // effective on the original PR date without changing stored records.
        $approvedInSnapshot=$snapshot['approver']??null;
        $approver=(is_array($approvedInSnapshot) &&
            trim((string)($approvedInSnapshot['name']??''))!=='' &&
            trim((string)($approvedInSnapshot['designation']??''))!=='')
            ? ['name'=>$approvedInSnapshot['name'],'designation'=>$approvedInSnapshot['designation'],'status'=>'SNAPSHOT']
            : ipdamsPrSchoolApprover($db,$org,(string)($header['document_date']??''));
        header('X-Frame-Options: DENY');
        echo ipdamsPrPrintHtml([
            'organization_id'=>$org,'id'=>$id,
            'organization_name'=>(string)($snapshot['organization_name']??$name['name']),
            'number'=>(string)$reg['display_number'],
            'document_date'=>(string)($header['document_date']??''),
            'requesting_unit'=>(string)($header['requesting_unit']??''),
            'requestor'=>is_array($snapshot['requestor']??null)?$snapshot['requestor']:null,
            'approver'=>$approver,
            'fund_name'=>(string)($snapshot['fund_name']??'See linked source-of-funds record'),
            'fund_cluster'=>(string)($header['fund_cluster']??''),
            'responsibility_center_code'=>(string)($header['responsibility_center_code']??''),
            'purpose'=>(string)($header['purpose']??''),
            'version'=>(string)$v['version_no'],
            'recorded'=>substr((string)$v['created_at'],0,10),
            'expected_total'=>$header['total']??null,'lines'=>$snapshot['lines']
        ]);
        exit;
    }
    if($type==='IAR'){
        header('X-Frame-Options: DENY');
        echo IarAppendix62::render(IarAppendix62::load($db,$org,$id));
        exit;
    }
    $poDateHeading='';
    if($type==='PO'){
        $poDate=ProcurementDocumentDates::poDate($db,$org,$id);
        $poDateHeading='<div>PO '.($poDate['source']==='SELECTED_DOCUMENT_DATE'?'document date: ':'historical recorded date (not verified issue date): ').$esc($poDate['date']).'</div>';
    }
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>'.$esc($type.' '.$reg['display_number']).'</title><style>body{font:14px Arial;color:#111;margin:50px auto;max-width:840px}header{border-bottom:2px solid #222;padding-bottom:20px;margin-bottom:22px}h1{font-size:22px}h2{font-size:16px;margin-top:25px}pre{font-family:inherit;white-space:pre-wrap;word-break:break-word;background:#f6f6f6;padding:12px}.notice{color:#8a5700;font-size:12px}@media print{body{margin:15mm}button{display:none}}</style></head><body><header><small>IPDAMS · Working draft / subject to official template verification</small><h1>'.$esc($name['name']).' — '.$esc($type).'</h1><div>Document number: '.$esc($reg['display_number']??'').'</div></header><p class="notice">This is a generated data snapshot, not a prescribed government form. Physical signatures and policy confirmation are required.</p><h2>Finalized snapshot</h2><pre>'.$esc(json_encode($snapshot,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre><button onclick="window.print()">Print</button></body></html>';
}catch(Throwable $e){http_response_code(404);header('Content-Type: text/plain');echo 'Document unavailable.';}
