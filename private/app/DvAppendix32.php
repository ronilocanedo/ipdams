<?php
declare(strict_types=1);

/**
 * Appendix 32 disbursement voucher: printable DOCUMENTATION ONLY.
 *
 * Preserves the issued DV number, the saved editable narrative, saved tax
 * components and current versioned journal. No issuing, approving, payment,
 * clearing, check allocation or tax-remittance operations occur on a print.
 * Unverified documentary fields are deliberately blank; actual signatory names are read from dated appointments.
 */
final class DvAppendix32
{
    private static function escape(mixed $value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function cash(mixed $v): string
    {
        return number_format((float)$v, 2, '.', ',');
    }

    /** Currency values are stored in SQL DECIMAL(18,2); avoid float addition. */
    private static function cents(mixed $v): int
    {
        return moneyCents((string)($v ?? '0'));
    }

    /** @return array<string,mixed> */
    public static function load(PDO $db, int $org, int $id): array
    {
        $dv = one($db, 'SELECT * FROM disbursement_vouchers WHERE id=? AND organization_id=?', [$id,$org]);
        demand($dv !== null && $dv['status'] === 'FINALIZED' && trim((string)$dv['display_number']) !== '',
            'Only an issued finalized DV is printable as Appendix 32.', 404);
        $reg = one($db, "SELECT display_number FROM document_registry WHERE organization_id=? AND doc_type='DV' AND entity_id=?", [$org,$id]);
        demand($reg !== null && hash_equals((string)$reg['display_number'], (string)$dv['display_number']),
            'Issued DV number does not reconcile to accountable register.', 409);
        $orgRec = one($db, 'SELECT name, code, kind, parent_division_id FROM organizations WHERE id=?', [$org]);
        demand($orgRec !== null, 'Organization record unavailable.', 404);
        $divisionName='';
        if($orgRec['kind']==='SCHOOL' && $orgRec['parent_division_id']!==null){
            $division=one($db,"SELECT name FROM organizations WHERE id=? AND kind='DIVISION'",[(int)$orgRec['parent_division_id']]);
            if($division!==null)$divisionName=(string)$division['name'];
        }
        $fund = one($db, 'SELECT name,source_code FROM funds WHERE id=? AND organization_id=?', [(int)$dv['fund_id'],$org]);
        demand($fund !== null, 'Source fund record unavailable.', 409);
        $taxes = q($db, "SELECT w.*,rv.rate, COALESCE(NULLIF(a.tax_kind,''),'UNCLASSIFIED') AS tax_kind
            FROM withholding_components w JOIN tax_rate_versions rv ON rv.id=w.rate_version_id
            LEFT JOIN tax_atcs a ON a.code=w.atc_code
            WHERE w.dv_id=? ORDER BY w.id", [$id])->fetchAll();
        $withheld = 0;
        foreach ($taxes as $tax) $withheld += self::cents($tax['amount']);
        demand($withheld === self::cents($dv['deductions']) &&
            self::cents($dv['gross']) - $withheld === self::cents($dv['net']),
            'Saved tax components do not reconcile to DV gross/net. Do not print an incorrect computation.',409);
        $version = one($db, 'SELECT id,version_no FROM dv_accounting_versions WHERE dv_id=? ORDER BY version_no DESC LIMIT 1',[$id]);
        demand($version !== null, 'DV accounting workpaper is missing.',409);
        $entries = q($db, 'SELECT l.uacs_code,a.account_name,l.debit,l.credit FROM voucher_accounting_lines l
            LEFT JOIN uacs_accounts a ON a.code=l.uacs_code
            WHERE l.accounting_version_id=? ORDER BY l.id', [(int)$version['id']])->fetchAll();
        $dr=0; $cr=0;
        foreach ($entries as $line) { $dr += self::cents($line['debit']); $cr += self::cents($line['credit']); }
        demand(count($entries)>=2 && $dr > 0 && $dr===$cr,
            'DV accounting journal is not balanced. Printable financial details require review.',409);
        $check = one($db, "SELECT c.check_number,c.status,b.bank_name,b.masked_account
            FROM checks c JOIN bank_accounts b ON b.id=c.bank_account_id
            WHERE c.dv_id=? AND c.organization_id=?
                AND c.status IN ('ISSUED','SIGNED','RELEASED','CLEARED')
            ORDER BY c.id DESC LIMIT 1", [$id,$org]);
        $printedDate=trim((string)($dv['document_date']??'')); // Legacy unverified document dates remain blank.
        // Draft corrections may have created earlier document_versions rows.
        // Find the actual immutable issuance snapshot by its registered number.
        $snapshots=q($db,"SELECT snapshot_json FROM document_versions WHERE organization_id=? AND doc_type='DV'
            AND entity_id=? ORDER BY version_no ASC",[$org,$id])->fetchAll();
        $issuedSnapshot=null;
        foreach($snapshots as $snap){
            $candidate=json_decode((string)$snap['snapshot_json'],true);
            if(is_array($candidate) && (string)($candidate['number']??'')===(string)$dv['display_number']){
                $issuedSnapshot=$candidate;break;
            }
        }
        demand($issuedSnapshot!==null,'Issued DV snapshot number requires review.',409);
        // Prefer the immutable per-section names captured at DV issuance. Existing
        // numbered legacy DVs may only reconstruct from an as-of-date record, with
        // an explicit paper review notice, never substituting a current employee.
        $signatories=['part_a'=>null,'part_c'=>null,'part_d'=>null];
        $signatorySource='ISSUANCE_SNAPSHOT';
        if(array_key_exists('dv_signatories',$issuedSnapshot) &&
            is_array($issuedSnapshot['dv_signatories']) &&
            is_array($issuedSnapshot['dv_signatories']['parts']??null)){
            foreach(array_keys($signatories) as $part) {
                $person=$issuedSnapshot['dv_signatories']['parts'][$part]??null;
                if(is_array($person) && isset($person['name'],$person['designation']))
                    $signatories[$part]=['name'=>(string)$person['name'],'designation'=>(string)$person['designation']];
            }
        }else{
            $signatorySource='LEGACY_RECONSTRUCTED_REVIEW';
            if($orgRec['kind']==='SCHOOL' && preg_match('/^\d{4}-\d{2}-\d{2}$/D',$printedDate)){
                $conf=one($db,"SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key='DV_SECTION_SIGNATORIES_V1' AND effective_from<=? ORDER BY effective_from DESC,id DESC LIMIT 1",[$org,$printedDate]);
                $mapping=$conf?json_decode((string)$conf['config_json'],true):[];
                foreach(array_keys($signatories) as $part){
                    $id=is_array($mapping)?($mapping[$part]??null):null;
                    if(!is_int($id)||$id<=0)continue;
                    $matches=q($db,"SELECT e.full_name,e.position_title,a.designation FROM employee_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.organization_id=? AND a.employee_id=? AND a.effective_from<=? AND (a.effective_to IS NULL OR a.effective_to>?) ORDER BY a.effective_from DESC,a.id DESC LIMIT 2",[$org,$id,$printedDate,$printedDate])->fetchAll();
                    if(count($matches)===1 && trim((string)$matches[0]['full_name'])!=='') {
                        $person=$matches[0];$signatories[$part]=['name'=>(string)$person['full_name'],
                            'designation'=>(string)($person['designation']?:$person['position_title']?:'')];
                    }
                }
            }
        }
        // No live supplier/employee master fallback for pre-update vouchers:
        // reprinting an accountable DV must not silently substitute newer PII.
        $payeeDocument=is_array($issuedSnapshot['payee_document_identity']??null)
            ?$issuedSnapshot['payee_document_identity']
            :['kind'=>'LEGACY_NOT_CAPTURED','tin'=>'','employee_no'=>'','address'=>'','review_required'=>true];
        return compact('dv','orgRec','fund','taxes','entries','check','printedDate','dr','cr','signatories','signatorySource','divisionName','payeeDocument')+
            ['accounting_version'=>(int)$version['version_no']];
    }

    /** @param array<string,mixed> $data */
    public static function render(array $data): string
    {
        $d=$data['dv'];$org=$data['orgRec'];
        $e=static fn(mixed $x):string=>self::escape($x);
        $c=static fn(mixed $x):string=>self::cash($x);
        $documentDate=(string)($data['printedDate']??'');
        $displayDate=(preg_match('/^\d{4}-\d{2}-\d{2}$/',$documentDate))?date('m/d/Y',strtotime($documentDate)):'';
        $sections=$data['signatories']??[];
        $partName=static fn(string $key):string=>(string)($sections[$key]['name']??'');
        $partTitle=static fn(string $key):string=>(string)($sections[$key]['designation']??'');
        $missing=[];
        if($documentDate==='')$missing[]='DV issue date is not recorded (legacy DV).';
        foreach(['part_a'=>'Part A','part_c'=>'Part C','part_d'=>'Part D'] as $key=>$label)
            if($partName($key)==='')$missing[]=$label.' printed-name assignment not verified for this DV.';
        $payeeDocument=$data['payeeDocument']??[];
        if(!empty($payeeDocument['review_required']))$missing[]='Payee identifier/address is incomplete or not captured at issuance; verify against the original documents.';
        $payeeNumber=(string)($payeeDocument['tin']??'');
        $employeeNo=(string)($payeeDocument['employee_no']??'');
        if($payeeNumber!=='' && $employeeNo!=='')$payeeNumber='TIN: '.$payeeNumber.' / Employee No.: '.$employeeNo;
        elseif($payeeNumber==='')$payeeNumber=$employeeNo;
        $payeeAddress=(string)($payeeDocument['address']??'');
        if(($data['signatorySource']??'')==='LEGACY_RECONSTRUCTED_REVIEW')
            $missing[]='This older voucher has no frozen signatory snapshot. Any reconstructed as-of-date names require independent paper record verification.';
        $currentCheck=$data['check']??null;
        $checkNo=$currentCheck?$e($currentCheck['check_number']):'';
        $bank=$currentCheck?$e($currentCheck['bank_name'].' / '.$currentCheck['masked_account']):'';
        $gross=$e($c($d['gross']));$due=$e($c($d['net']));$deduct=$e($c($d['deductions']));
        $particulars=trim((string)($d['particulars']??''));
        $narrative=$particulars!==''?$e($particulars):'Particulars not recorded';
        $taxRows='';
        foreach($data['taxes'] as $tax){
            $kind=($tax['tax_kind']==='VAT'?'VAT':($tax['tax_kind']==='EWT'?'Expanded WT':'WTX'));
            $taxRows.='<div class="tax-line"><span>Less: '.$e($kind.' ('.$tax['atc_code'].')').'</span><span>'.$e($c($tax['tax_base'])).' × '.$e(number_format(((float)$tax['rate'])*100,2)).'% = ('.$e($c($tax['amount'])).')</span></div>';
        }
        $taxBlock='<div class="calc-block"><div class="tax-line"><span>Gross Amount</span><span>'.$gross.'</span></div>';
        if($taxRows!=='')$taxBlock.=$taxRows.'<div class="tax-line"><strong>Total Taxes Withheld</strong><strong>('.$deduct.')</strong></div>';
        $taxBlock.='<div class="tax-line"><strong>Amount Due</strong><strong>'.$due.'</strong></div></div>';
        $journalRows='';
        foreach($data['entries'] as $line){
            $credit=self::cents($line['credit'])>0;
            $journalRows.='<tr class="account-line"><td'.($credit?' class="credit-title"':'').'>'.$e($line['account_name']?:'Account title not recorded').'</td>'.
                '<td class="center">'.$e($line['uacs_code']).'</td><td class="money">'.
                (self::cents($line['debit'])>0?$e($c($line['debit'])):'').'</td><td class="money">'.
                ($credit?$e($c($line['credit'])):'').'</td></tr>';
        }
        $hasCheck=$currentCheck!==null;
        $mode='<span class="choice">'.($hasCheck?'☑':'□').' Commercial Check</span>';
        $headHtml='<div class="appendix">Appendix 32</div><div class="sheet">'.
            '<table class="form-table masthead"><colgroup><col style="width:79%"><col style="width:21%"></colgroup><tr>'.
            '<td class="mast-center"><div>Republic of the Philippines</div><div>Department of Education</div>'.
            '<div>'.$e($data['divisionName']??'').'</div><div class="school">'.$e($org['name']).'</div>'.
            '<h1>DISBURSEMENT VOUCHER</h1></td>'.
            '<td class="right-info"><div class="cluster">Fund Cluster :<div class="cluster-value"></div></div>'.
            '<div class="dv-ident"><div><span>Date:</span><b>'.$e($displayDate).'</b></div>'.
            '<div><span>DV:</span><b>'.$e($d['display_number']).'</b></div></div></td></tr></table>';
        $first='<table class="form-table metadata"><colgroup><col style="width:12%"><col style="width:42%"><col style="width:26%"><col style="width:20%"></colgroup>'.
            '<tr class="mode"><th>Mode of<br>Payment</th><td colspan="3"><div class="mode-options"><span class="choice">□ MDS Check</span>'.$mode.'<span class="choice">□ ADA</span><span class="choice">□ Others (Please specify)</span></div></td></tr>'.
            '<tr class="payee"><th>Payee</th><td><b>'.$e($d['business_name_snapshot']??$d['payee']).'</b></td>'.
            '<td class="small">TIN/Employee No.:<div class="center">'.$e($payeeNumber).'</div></td>'.
            '<td class="small">ORS/BURS No.:</td></tr>'.
            '<tr class="address"><th>Address</th><td colspan="3">'.$e($payeeAddress).'</td></tr></table>';
        $part='<table class="form-table particulars"><colgroup><col style="width:49%"><col style="width:15%"><col style="width:15%"><col style="width:21%"></colgroup>'.
            '<thead><tr class="part-head"><td class="center">Particulars</td><td class="center">Responsibility<br>Center</td><td class="center">MFO/PAP</td><td class="center">Amount</td></tr></thead>'.
            '<tbody><tr class="part-body"><td class="part-text"><div class="narrative">'.$narrative.'</div>'.$taxBlock.'</td><td></td><td></td><td></td></tr>'.
            '<tr class="amount-row"><td colspan="3" class="center"><b>AMOUNT DUE</b></td><td class="money"><b>'.$due.'</b></td></tr></tbody></table>';
        $a='<table class="form-table certified-a"><tr><td class="section-letter">A.</td><td><b>Certified:</b> &nbsp;Expenses/Cash Advance necessary, lawful and incurred under my direct supervision.'.
            '<div class="a-sig"><b>'.$e($partName('part_a')).'</b><div>Printed Name, Designation and Signature of Supervisor</div></div></td></tr></table>';
        $b='<table class="form-table journal"><colgroup><col style="width:52%"><col style="width:16%"><col style="width:16%"><col style="width:16%"></colgroup>'.
            '<tr class="journal-header"><td colspan="4" style="text-align:left"><b>B.</b> &nbsp; Accounting Entry:</td></tr>'.
            '<tr class="journal-columns"><td class="center">Account Title</td><td class="center">UACS Code</td><td class="center">Debit</td><td class="center">Credit</td></tr>'.$journalRows.'</table>';
        $ccash='<div class="checkbox-row"><span class="cb checked">✓</span><span>Cash available</span></div>'.
            '<div class="checkbox-row"><span class="cb"></span><span>Subject to Authority to Debit Account (when applicable)</span></div>'.
            '<div class="checkbox-row"><span class="cb"></span><span>Supporting documents complete and amount claimed proper</span></div>';
        $names=function(string $name,string $role)use($e):string{
            return '<table class="sign-grid"><tr><td class="sign-label">Signature</td><td></td></tr>'.
                '<tr><td class="sign-label">Printed Name</td><td class="center"><b>'.$e($name).'</b></td></tr>'.
                '<tr><td class="sign-label">Position</td><td class="center">'.$e($role).'</td></tr>'.
                '<tr><td class="sign-label">Date</td><td></td></tr></table>';
        };
        $cSection='<td class="cd-cell"><div class="cd-label"><b>C.</b> &nbsp;<b>Certified:</b></div>'.
            '<div class="cd-checks">'.$ccash.'</div>'. $names($partName('part_c'),$partTitle('part_c')?:'Head, Accounting Unit/Authorized Representative').'</td>';
        $dSection='<td class="cd-cell"><div class="cd-label"><b>D.</b> &nbsp;<b>Approved for Payment</b></div>'.
            '<div class="cd-checks"></div>'. $names($partName('part_d'),$partTitle('part_d')?:'Agency Head/Authorized Representative').'</td>';
        $cd='<table class="form-table cd"><tr>'.$cSection.$dSection.'</tr></table>';
        $receipt='<table class="form-table receipt"><colgroup><col style="width:12%"><col style="width:26%"><col style="width:16%"><col style="width:26%"><col style="width:20%"></colgroup>'.
            '<tr><td class="receipt-title" colspan="4"><b>E.</b> &nbsp;<b>Receipt of Payment</b></td><td>JEV No.</td></tr>'.
            '<tr class="receipt-row"><td>Check/ ADA No.:</td><td class="center">'.$checkNo.'</td>'.
            '<td>Date : <span class="check-face-date">'.($currentCheck?$e($displayDate):'').'</span></td><td>Bank Name &amp; Account Number:<div class="center">'.$bank.'</div></td><td></td></tr>'.
            '<tr class="receipt-row"><td>Signature :</td><td></td><td>Date :</td><td>Printed Name:<div class="center"><b>'.$e($d['business_name_snapshot']??$d['payee']).'</b></div></td><td>Date :</td></tr>'.
            '<tr><td colspan="4">Official Receipt No. &amp; Date/Other Documents</td><td></td></tr></table></div>';
        $css=<<<'CSS'
@page{size:letter;margin:18mm 18mm 16mm}
*{box-sizing:border-box}html,body{padding:0;margin:0;color:#090909;background:#fff}
body{font-family:'Bookman Old Style','Times New Roman',serif;font-size:10pt;line-height:1.16}
.toolbar{max-width:850px;margin:8px auto;text-align:right;font:12px Arial,sans-serif}
.notice{max-width:850px;margin:7px auto 12px;padding:8px;background:#fff8db;border:1px solid #d4b46d;font:11px Arial,sans-serif}
.appendix{text-align:right;font-style:italic;font-size:10pt;margin-bottom:2px}
.sheet{border:1.5px solid #111;width:100%;margin:0 auto;max-width:850px}
.form-table{border-collapse:collapse;border-spacing:0;width:100%;table-layout:fixed;margin:0;border:0}
.form-table td,.form-table th{border:1px solid #111;padding:2px 3px;vertical-align:middle;overflow-wrap:break-word;font-weight:normal}
.form-table th{font-weight:700;text-align:left}
.center{text-align:center}.money{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.masthead{height:91px}.masthead td{padding:2px}.masthead .mast-center{text-align:center;line-height:1.3;font-weight:700}
.masthead .school{font-size:11pt;margin-top:1px}.masthead h1{font-size:12pt;letter-spacing:0;margin:8px 0 1px;font-weight:700}
.right-info{padding:0!important;vertical-align:top!important}.cluster{height:47px;border-bottom:1.5px solid #111;padding:3px 3px 0;font-weight:700}.cluster-value{font-weight:700;text-align:center;font-size:10pt;padding-top:6px}
.dv-ident{padding:2px}.dv-ident div{display:flex;gap:5px;justify-content:space-between;white-space:nowrap}.dv-ident span{font-weight:normal}.dv-ident b{font-size:10pt}
.metadata .mode{height:35px}.metadata .payee{min-height:40px}.metadata .address{height:23px}
.mode-options{display:flex;gap:8px;align-items:center;justify-content:space-around;flex-wrap:wrap;font-size:10pt}.choice{white-space:nowrap}
.metadata .small{font-size:10pt}.metadata .small .center{margin-top:4px;font-weight:700;overflow-wrap:anywhere}
.particulars{font-size:8pt}.particulars .part-head{height:24px}.particulars .part-body{height:85px}.particulars .part-body td{vertical-align:top}.particulars .part-text{padding:2px 3px!important}.narrative{white-space:pre-wrap;min-height:20px;font-size:8pt}
.calc-block{font-size:8pt;max-width:96%;margin-top:3px;line-height:1.13}
.tax-line{display:flex;gap:5px;justify-content:space-between;align-items:baseline;white-space:nowrap}.tax-line span:first-child{overflow:hidden;text-overflow:ellipsis}
.amount-row{height:14px}.amount-row td{padding:1px 3px!important}
.certified-a td{height:78px;vertical-align:top}.certified-a td.section-letter{width:25px!important;padding:1px!important;text-align:center;font-weight:700}.a-sig{text-align:center;margin-top:19px;font-size:10pt}.a-sig b{font-size:10pt}
.journal{font-size:8pt}.journal .journal-header td{height:15px;border-bottom:1px solid #111;padding:1px 3px}
.journal .journal-columns td{height:15px;border-top:0;border-bottom:1px solid #111;text-align:center;padding:1px 2px}
.journal .account-line td{height:11px;line-height:1.05;font-size:8pt;padding:0 2px;vertical-align:middle;border-top:0!important;border-bottom:0!important}
.journal .account-line:last-child td{padding-bottom:3px}.journal .credit-title{padding-left:5px}
.cd>.form-table{border:0}.cd>.form-table td{border-top:0}.cd .cd-cell{width:50%;padding:0;vertical-align:top}
.cd-label{min-height:21px;padding:3px;border-bottom:1px solid #111}
.cd-checks{min-height:79px;padding:4px 8px;font-size:10pt}
.checkbox-row{display:flex;gap:5px;align-items:flex-start;margin-bottom:3px}
.cb{display:inline-flex;width:12px;height:12px;border:1px solid #111;align-items:center;justify-content:center;flex-shrink:0;line-height:1;margin-top:0px;font-size:10pt}.cb.checked{font-weight:bold}
.sign-grid{border-collapse:collapse;width:100%;table-layout:fixed}.sign-grid td{border:1px solid #111;height:26px;padding:2px 4px;vertical-align:bottom;font-size:10pt}
.sign-grid .sign-label{width:34%;text-align:center}.sign-grid .center{font-size:10pt}
.receipt td{height:20px;vertical-align:top;font-size:10pt;padding:2px 3px}.check-face-date{display:block;text-align:center;font-weight:700;white-space:nowrap;margin-top:3px}.receipt .receipt-title{height:21px}.receipt .receipt-row td{height:37px}
@media print{.toolbar,.notice{display:none!important}.sheet{max-width:none}.form-table{page-break-inside:avoid}.masthead{page-break-after:avoid}}
CSS;
        $warning='Appendix 32 sample-aligned layout for school review. Printing does not electronically sign, certify or issue a check. Unverified fields remain blank.';
        if($missing)$warning.=' Review: '.implode(' ', $missing);
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Appendix 32 — DV '.$e($d['display_number']).'</title><style>'.$css.'</style></head><body>'.
            '<div class="toolbar"><button type="button" onclick="window.print()">Print DV</button></div>'.
            '<div class="notice" role="status">'.$e($warning).'</div>'.$headHtml.$first.$part.$a.$b.$cd.$receipt.'</body></html>';
    }
}
