<?php
declare(strict_types=1);
/**
 * IPDAMS v0.2.352 — read-only, school-scoped, per-fund financial history.
 * Separate gross DV expenses, tax components and cash book movements. No writes.
 * Current/latest check status is labeled as such: the bank book is not a bank statement.
 */
final class FundTransactionAudit {
    private static function cents($number): int {return moneyCents((string)$number);}
    private static function amount(int $cents): string {return moneyString($cents);}
    private static function bounded(PDO $db,string $sql,array $args,string $label): array {
        $rows=q($db,$sql,$args)->fetchAll(PDO::FETCH_ASSOC);
        demand(count($rows)<=5000,$label.' exceeds safe report capacity; finance administrator review required.',409);
        return $rows;
    }
    public static function read(PDO $db,int $org,array $params,array $availability): array {
        $fundId=filter_var($params['fund_id']??0,FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        demand($fundId!==false,'Invalid selected fund',400);
        $month=trim((string)($params['month']??''));
        demand($month==='' || preg_match('/^20\d\d-(0[1-9]|1[0-2])$/D',$month)===1,
            'Choose a valid transaction month',400);
        $page=filter_var($params['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>10000]]);
        demand($page!==false,'Invalid transaction page',400);
        $pageSize=filter_var($params['page_size']??50,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>5000]]);
        demand($pageSize!==false,'Invalid transaction page size',400);
        $funds=self::bounded($db,'SELECT id,name,source_code,fiscal_year FROM funds WHERE organization_id=? ORDER BY fiscal_year DESC,name,id LIMIT 5001',[$org],'Fund directory');
        $known=[];foreach($funds as $fund)$known[(int)$fund['id']]=true;
        demand($fundId===0 || isset($known[$fundId]),'Selected fund does not belong to this workspace',404);
        require_once __DIR__.'/BankFundRegister.php';
        $bankBook=BankFundRegister::read($db,$org);
        $events=self::bounded($db,"SELECT e.id,e.fund_id,e.event_type,e.amount,e.reference_type,e.reference_id,
              e.reason,e.created_at,e.bank_account_id,e.bank_credit_date,e.bank_credit_ref,e.created_by,
              u.full_name actor
            FROM fund_events e LEFT JOIN users u ON u.id=e.created_by
            WHERE e.organization_id=? ORDER BY e.id LIMIT 5001",[$org],'Fund event history');
        $dvs=self::bounded($db,"SELECT d.id,d.fund_id,d.dv_type,d.display_number,d.gross,d.net,d.deductions,
              d.particulars,d.document_date,d.created_at,d.status,d.payee,
              tt.id tax_transfer_id,tt.status tax_transfer_status
            FROM disbursement_vouchers d
            LEFT JOIN school_tax_transfers tt ON tt.dv_id=d.id
            WHERE d.organization_id=? AND d.status='FINALIZED'
            ORDER BY d.id LIMIT 5001",[$org],'Finalized voucher history');
        $taxes=self::bounded($db,"SELECT w.id,w.dv_id,w.atc_code,w.amount,w.disposition,d.fund_id,d.document_date,
              d.display_number dv_number
            FROM withholding_components w JOIN disbursement_vouchers d ON d.id=w.dv_id AND d.organization_id=?
            WHERE d.status='FINALIZED' ORDER BY w.id LIMIT 5001",[$org],'Withholding history');
        $taxEvents=self::bounded($db,"SELECT e.id,e.component_id,e.state,e.created_at,e.created_by,
              e.evidence_reference,e.authority_reference,u.full_name actor
            FROM tax_disposition_events e
            JOIN withholding_components w ON w.id=e.component_id
            JOIN disbursement_vouchers d ON d.id=w.dv_id AND d.organization_id=? AND d.status='FINALIZED'
            LEFT JOIN users u ON u.id=e.created_by ORDER BY e.id LIMIT 5001",[$org],'Tax completion history');
        $payments=self::bounded($db,"SELECT p.id,p.check_id,p.event_type,p.created_at,p.evidence_reference,
              p.created_by,u.full_name actor,c.check_number,c.amount,c.bank_account_id,d.fund_id,
              d.display_number dv_number
            FROM payment_events p JOIN checks c ON c.id=p.check_id
            JOIN disbursement_vouchers d ON d.id=c.dv_id AND d.organization_id=c.organization_id
            LEFT JOIN users u ON u.id=p.created_by
            WHERE c.organization_id=? ORDER BY p.id LIMIT 5001",[$org],'Check status history');
        $audits=self::bounded($db,"SELECT a.id,a.entity_id dv_id,a.details,a.created_at,a.actor_id,
              u.full_name actor,d.fund_id
            FROM audit_events a JOIN disbursement_vouchers d ON d.id=a.entity_id AND d.organization_id=a.organization_id
            LEFT JOIN users u ON u.id=a.actor_id
            WHERE a.organization_id=? AND a.entity_type='DV' AND a.event_type='FINALIZED'
              AND d.status='FINALIZED' ORDER BY a.id LIMIT 5001",[$org],'DV finalization audit history');
        $matchRecords=self::bounded($db,"SELECT id,config_key,config_json,created_at
            FROM configuration_versions WHERE organization_id=? AND LEFT(config_key,5)='WTU1_'
            ORDER BY id LIMIT 5001",[$org],'Retained tax reconciliation history');
        $snapshots=self::bounded($db,"SELECT v.entity_id dv_id,v.snapshot_json
            FROM document_versions v
            JOIN (SELECT entity_id,MIN(version_no) first_version FROM document_versions
                  WHERE organization_id=? AND doc_type='DV' GROUP BY entity_id) first
              ON first.entity_id=v.entity_id AND first.first_version=v.version_no
            JOIN disbursement_vouchers d ON d.id=v.entity_id AND d.organization_id=v.organization_id
            WHERE v.organization_id=? AND v.doc_type='DV' AND d.status='FINALIZED'
            ORDER BY v.entity_id LIMIT 5001",[$org,$org],'DV policy snapshot history');
        return self::build($funds,$availability,$events,$dvs,$taxes,$taxEvents,$payments,$audits,
            $matchRecords,$snapshots,$bankBook,(int)$fundId,$month,(int)$page,(int)$pageSize);
    }
    /** Pure projection, inputs are exclusively source rows already verified for this organization. */
    public static function build(array $funds,array $availability,array $events,array $dvs,array $taxes,
        array $taxEvents,array $payments,array $audits,array $matchRecords,array $snapshots,array $bankBook,
        int $selected=0,string $month='',int $page=1,int $pageSize=50): array {
        demand($selected>=0 && $page>0 && $pageSize>0 && $pageSize<=5000,'Invalid report selection',400);
        demand($month===''||preg_match('/^20\d\d-(0[1-9]|1[0-2])$/D',$month)===1,
            'Invalid report month',400);
        $byFund=[];$dvMap=[];$taxMap=[];$policyMap=[];$taxStatus=[];$fundingMap=[];
        foreach($funds as $f){$id=(int)$f['id'];demand($id>0&&!isset($byFund[$id]),'Duplicate or invalid source fund',409);
            $a=$availability[$id]??null;demand(is_array($a),'Fund authority register unavailable',409);
            $byFund[$id]=['fund_id'=>$id,'fund_name'=>(string)$f['name'],'fund_code'=>(string)$f['source_code'],
                'fiscal_year'=>(int)$f['fiscal_year'],'authorized_allocation'=>(string)$a['authorized_allocation'],
                'ordinary_remaining'=>(string)$a['available_allocation'],'outstanding_obligations'=>(string)$a['outstanding_obligations'],
                'gross_finalized'=>(string)$a['finalized_dv_gross'],'fund_downloaded'=>'0.00','book_cash'=>'0.00','net_checks_committed'=>'0.00',
                'net_checks_cleared'=>'0.00','tax_withheld'=>'0.00','tax_for_division'=>'0.00',
                'tax_for_bir'=>'0.00','tax_policy_unverified'=>'0.00','retained_tax_confirmed'=>'0.00','retained_tax_applied'=>'0.00',
                'retained_tax_reconciled'=>'0.00','retained_tax_unused'=>'0.00',
                'retained_tax_pending'=>'0.00','retained_tax_self_applied_pending'=>'0.00',
                'division_check_transfer_verified'=>false];
        }
        demand($selected===0 || isset($byFund[$selected]),'Selected fund unavailable',404);
        $ensure=static function(int $id)use($byFund):void {demand(isset($byFund[$id]),'Cross-fund audit source requires review',409);};
        $eventRows=[];$idseq=0;
        $push=static function(string $date,string $type,int $fid,string $ref,string $detail,
            int $gross,int $check,int $tax,int $retained,string $status,string $actor='',string $recorded='',string $origin='')
            use (&$eventRows,&$idseq,$ensure):void {
            $ensure($fid);
            demand(preg_match('/^20\d\d-\d\d-\d\d$/D',$date)===1,'Invalid historical transaction date',409);
            $eventRows[]=['date'=>$date,'type'=>$type,'fund_id'=>$fid,'reference'=>$ref,'particulars'=>$detail,
                'gross'=>self::amount($gross),'check'=>self::amount($check),'bank_credit'=>'0.00','withholding'=>self::amount($tax),
                'retained'=>self::amount($retained),'status'=>$status,'actor'=>$actor,'recorded_at'=>$recorded,
                'source'=>$origin,'fund_book_balance'=>null,'_seq'=>$idseq++];
        };
        // Fund/book amounts are authoritative in their respective existing source ledgers.
        $bookCredits=[];$committed=[];$cleared=[];
        foreach(($bankBook['rows']??[]) as $r){$fid=(int)$r['fund_id'];$ensure($fid);
            $kind=(string)$r['kind'];$date=(string)$r['date'];$delta=self::cents($r['credit'])-self::cents($r['debit']);
            if($kind==='CHECK'){$committed[$fid]=($committed[$fid]??0)+self::cents($r['debit']);
                if($r['status']==='CLEARED')$cleared[$fid]=($cleared[$fid]??0)+self::cents($r['debit']);}
            elseif($kind==='CHECK_REVERSAL')$committed[$fid]=($committed[$fid]??0)-self::cents($r['credit']);
            elseif($kind==='DOWNLOAD'){
                $bookCredits[(string)$r['key']]=true;
                $byFund[$fid]['fund_downloaded']=self::amount(
                    self::cents($byFund[$fid]['fund_downloaded'])+self::cents($r['credit']));
            }
            $push($date,$kind==='CHECK'?'NET_CHECK_ISSUED':($kind==='CHECK_REVERSAL'?'CHECK_COMMITMENT_REVERSAL':'BANK_DOWNLOAD'),
                $fid,(string)$r['reference'],(string)$r['particulars'],0,$kind==='DOWNLOAD'?0:-$delta,0,0,
                (string)$r['status'],'',$date,$kind==='DOWNLOAD'?'Fund event':'Check ledger');
            $eventRows[count($eventRows)-1]['fund_book_balance']=(string)$r['fund_book_balance'];
            if($kind==='DOWNLOAD')$eventRows[count($eventRows)-1]['bank_credit']=(string)$r['credit'];
        }
        $cashRows=[];foreach(($bankBook['funds']??[]) as $f){$fid=(int)$f['fund_id'];$ensure($fid);
            demand(!isset($cashRows[$fid]),'Duplicate bank book fund',409);$cashRows[$fid]=$f;
            $byFund[$fid]['book_cash']=(string)$f['book_balance'];
        }
        foreach($byFund as $fid=>&$f){demand(isset($cashRows[$fid]),'Bank book missing one registered fund',409);
            $f['net_checks_committed']=self::amount($committed[$fid]??0);
            $f['net_checks_cleared']=self::amount($cleared[$fid]??0);
        }unset($f);
        foreach($events as $e){$fid=(int)$e['fund_id'];$ensure($fid);$kind=(string)$e['event_type'];$id=(int)$e['id'];
            if(in_array($kind,['PENDING_TAX','PENDING_TAX_REVERSAL','CONFIRMED_CREDIT','DISBURSEMENT','DISBURSEMENT_REVERSAL'],true))continue;
            if($kind==='ALLOTMENT' && isset($bookCredits['A'.$id]))continue;
            $amount=self::cents($e['amount']);$isBank=$kind==='ALLOTMENT';
            $date=$isBank && !empty($e['bank_credit_date'])?(string)$e['bank_credit_date']:
                substr((string)$e['created_at'],0,10);
            $push($date,$kind,$fid,(string)($e['bank_credit_ref']?:($e['reference_type'].' #'.($e['reference_id']??$id))),
                (string)($e['reason']?:'Recorded fund entry'),0,0,0,0,
                $isBank?'NOT_EFFECTIVE_OR_NOT_LINKED':'FUND_LEDGER_ONLY',(string)($e['actor']??''),
                (string)$e['created_at'],'Fund event #'.$id.' · '.self::amount($amount));
        }
        foreach($dvs as $d){$id=(int)$d['id'];$fid=(int)$d['fund_id'];$ensure($fid);
            demand(!isset($dvMap[$id]),'Duplicate finalized DV',409);$dvMap[$id]=$d;
            $gross=self::cents($d['gross']);$net=self::cents($d['net']);$tax=self::cents($d['deductions']);
            demand($gross>0&&$net>=0&&$tax>=0&&$gross===$net+$tax,'Finalized DV amount reconciliation needs finance review',409);
            $date=(string)($d['document_date']?:substr((string)$d['created_at'],0,10));
            $taxTransfer=(int)($d['tax_transfer_id']??0)>0;
            // A remittance of withheld tax is settlement of an already
            // expensed source DV, never a second finalized-gross expense.
            $push($date,$taxTransfer?'WITHHELD_TAX_TRANSFER_DV':'FINALIZED_DV_GROSS',$fid,
                'DV '.(string)($d['display_number']??('#'.$id)),
                (string)($d['particulars']??''),$taxTransfer?0:$gross,0,0,0,
                $taxTransfer?(string)($d['tax_transfer_status']??'REVIEW'):'FINALIZED',
                '',(string)$d['created_at'],
                'DV #'.$id.' · '.($taxTransfer?'Withholding liability settlement':(string)$d['dv_type']));
        }
        foreach($snapshots as $s){$id=(int)$s['dv_id'];
            if(!isset($dvMap[$id]))continue;
            $v=json_decode((string)$s['snapshot_json'],true,64);
            demand(is_array($v),'Invalid original DV policy snapshot',409);
            $policyMap[$id]=$v['withholding_policy']['mode']??'NOT_SAVED';
        }
        $taxByDv=[];$confirmedByFund=[];$reconByFund=[];
        foreach($taxes as $t){$id=(int)$t['id'];$dvId=(int)$t['dv_id'];$fid=(int)$t['fund_id'];$ensure($fid);
            demand(isset($dvMap[$dvId])&&!isset($taxMap[$id]),'Tax component does not match a finalized DV',409);
            $taxMap[$id]=$t;
            $amount=self::cents($t['amount']);demand($amount>0,'Invalid saved withholding amount',409);
            $taxByDv[$dvId]=($taxByDv[$dvId]??0)+$amount;
            $byFund[$fid]['tax_withheld']=self::amount(self::cents($byFund[$fid]['tax_withheld'])+$amount);
            if($t['disposition']==='REMIT'){
                $policy=$policyMap[$dvId]??'NOT_SAVED';
                if($policy==='REMIT_TO_DIVISION')$key='tax_for_division';
                elseif($policy==='IUS_REMIT_DIRECT_BIR')$key='tax_for_bir';
                else $key='tax_policy_unverified'; // do not reclassify historical policy from today's setting
                $byFund[$fid][$key]=self::amount(self::cents($byFund[$fid][$key])+$amount);
            }
            $date=(string)($t['document_date']?:$dvMap[$dvId]['document_date']);
            $push($date,'TAX_WITHHELD',$fid,'DV '.(string)$t['dv_number'].' · '.(string)$t['atc_code'],
                (string)$t['disposition'].' · Original tax component #'.$id,0,0,$amount,0,
                (string)($policyMap[$dvId]??'POLICY_NOT_SAVED'),'',$date,'Withholding component #'.$id);
        }
        foreach($dvMap as $id=>$d){
            if(isset($taxByDv[$id]) && $taxByDv[$id]>self::cents($d['deductions']))
                demand(false,'DV withholding exceeds recorded deductions; finance review required',409);
        }
        foreach($taxEvents as $e){$taxId=(int)$e['component_id'];demand(isset($taxMap[$taxId]),'Tax event has an unrecognized source',409);
            $t=$taxMap[$taxId];$fid=(int)$t['fund_id'];$state=(string)$e['state'];$amount=self::cents($t['amount']);
            $key=$taxId.':'.$state;
            if(in_array($state,['CONFIRMED_CREDIT','REMITTED'],true)){
                demand(!isset($taxStatus[$key]),'Repeated final tax disposition requires finance review',409);
                $taxStatus[$key]=true;
                if($state==='CONFIRMED_CREDIT'){
                    demand($t['disposition']==='SCHOOL_UTILIZATION','Tax credit does not match original disposition',409);
                    $confirmedByFund[$fid]=($confirmedByFund[$fid]??0)+$amount;
                }
            }
            if($state==='PENDING'){
                demand(!isset($taxStatus[$taxId.':PENDING']),'Duplicate pending tax source event',409);
                $taxStatus[$taxId.':PENDING']=true;
                continue;
            }
            $date=substr((string)$e['created_at'],0,10);
            $push($date,$state==='CONFIRMED_CREDIT'?'RETAINED_TAX_CONFIRMED':'TAX_STATUS_'.$state,
                $fid,'DV '.(string)$t['dv_number'].' · '.(string)$t['atc_code'],
                $state==='REMITTED'?'Tax completion marked in legacy system; not evidence of check-paid transfer':
                    'Confirmed with saved bank/budget evidence; not a new allocation or deposit',
                0,0,0,$state==='CONFIRMED_CREDIT'?$amount:0,$state,
                (string)($e['actor']??''),(string)$e['created_at'],'Tax disposition event #'.(int)$e['id']);
        }
        // Pending tax from finalized school-utilizable DVs is recorded
        // source tax, not cash and not automatically CONFIRMED. It may cover
        // the already-consumed gross shortfall subject to the saved policy.
        $pendingByFund=[];
        foreach($taxMap as $id=>$tax){
            if($tax['disposition']!=='SCHOOL_UTILIZATION')continue;
            demand(isset($taxStatus[$id.':PENDING']),
                'A finalized retained-tax component lacks its pending event.',409);
            if(isset($taxStatus[$id.':CONFIRMED_CREDIT']))continue;
            demand(!isset($taxStatus[$id.':REMITTED']),
                'Retained tax cannot simultaneously be remitted.',409);
            if(($policyMap[(int)$tax['dv_id']]??'')!=='DIVISION_SETTLES_UTILIZABLE')continue;
            $fundId=(int)$tax['fund_id'];
            $pendingByFund[$fundId]=($pendingByFund[$fundId]??0)+self::cents($tax['amount']);
        }
        $usedByFund=[];$selfPendingByFund=[];
        foreach($audits as $a){$dvId=(int)$a['dv_id'];demand(isset($dvMap[$dvId]),'Finalization audit has an unknown DV',409);
            demand(!isset($fundingMap[$dvId]),'Duplicate DV finalization audit requires review',409);
            $fundingMap[$dvId]=true;
            $json=json_decode((string)$a['details'],true,64);
            demand(is_array($json),'Invalid saved DV audit evidence',409);
            $basis=$json['funding_basis']??null;
            if(!is_array($basis))continue;
            $used=$basis['retained_gross_used_cents']??0;
            demand(is_int($used)&&$used>=0,'Invalid retained funding snapshot',409);
            if($used===0)continue;
            $fid=(int)$a['fund_id'];$ensure($fid);
            $usedByFund[$fid]=($usedByFund[$fid]??0)+$used;
            $date=(string)($dvMap[$dvId]['document_date']?:substr((string)$a['created_at'],0,10));
            $self=$basis['same_dv_withheld_used_cents']??0;
            demand(is_int($self)&&$self>=0&&$self<=$used,
                'Invalid current-DV tax utilization amount in audit',409);
            if($self>0){
                $alloc=$basis['same_dv_withholding_components']??null;
                demand(is_array($alloc),'Current-DV tax utilization lacks ATC lineage',409);
                $sum=0;$seen=[];$stillPending=0;
                foreach($alloc as $entry){
                    demand(is_array($entry)&&is_int($entry['component_id']??null)&&
                        is_int($entry['used_cents']??null),
                        'Current-DV retained tax component evidence is invalid',409);
                    $id=$entry['component_id'];$amount=$entry['used_cents'];
                    demand(!isset($seen[$id])&&isset($taxMap[$id])&&
                        (int)$taxMap[$id]['dv_id']===$dvId&&
                        (int)$taxMap[$id]['fund_id']===$fid&&
                        $taxMap[$id]['disposition']==='SCHOOL_UTILIZATION'&&
                        $amount>0&&$amount<=self::cents((string)$taxMap[$id]['amount']),
                        'Same-DV source tax was duplicated or exceeded',409);
                    $seen[$id]=true;$sum+=$amount;
                    if(!isset($taxStatus[$id.':CONFIRMED_CREDIT']))$stillPending+=$amount;
                }
                demand($sum===$self,'Same-DV retained tax does not balance',409);
                $selfPendingByFund[$fid]=($selfPendingByFund[$fid]??0)+$stillPending;
                $push($date,'SAME_DV_TAX_UTILIZATION',$fid,
                    'DV '.(string)$dvMap[$dvId]['display_number'],
                    'Current voucher withheld-tax component applied to gross authority; remains reportable to Division and is never a bank deposit',
                    0,0,0,0,$stillPending>0?'REPORT_PENDING':'CONFIRMED',
                    (string)($a['actor']??''),(string)$a['created_at'],
                    'DV tax utilization audit #'.(int)$a['id']);
            }
            $push($date,'RETAINED_TAX_APPLIED',$fid,'DV '.(string)$dvMap[$dvId]['display_number'],
                $self>0?'Prior recorded and current withheld tax applied once as gross authority; separate from bank cash':
                    'Recorded retained tax applied to gross authority; separate from bank cash',
                0,0,0,$used,'FINALIZED',(string)($a['actor']??''),(string)$a['created_at'],
                'DV finalization audit #'.(int)$a['id']);
        }
        $seenMatches=[];$reconciledSources=[];
        foreach($matchRecords as $r){
            $payload=json_decode((string)$r['config_json'],true,64);
            demand(is_array($payload),'Invalid tax utilization evidence',409);
            $source=(int)($payload['source_component_id']??0);$target=(int)($payload['target_dv_id']??0);
            $key='WTU1_'.$source.'_'.$target;
            demand($source>0 && $target>0 && $key===(string)$r['config_key']&&!isset($seenMatches[$key]),
                'Ambiguous tax utilization source/target',409);
            $seenMatches[$key]=true;
            demand(isset($taxMap[$source])&&isset($dvMap[$target]),'Tax utilization has missing original/target DV',409);
            demand($taxMap[$source]['disposition']==='SCHOOL_UTILIZATION' &&
                isset($taxStatus[$source.':CONFIRMED_CREDIT']),
                'A reconciled tax component must be previously confirmed retained withholding',409);
            demand((string)$dvMap[$target]['document_date']>=(string)$taxMap[$source]['document_date'],
                'Retained tax reconciliation target predates the original withholding',409);
            $fid=(int)$taxMap[$source]['fund_id'];
            demand((int)$dvMap[$target]['fund_id']===$fid,'Cross-fund tax utilization requires review',409);
            $amount=self::cents((string)($payload['amount']??'0'));demand($amount>0,'Invalid matched tax amount',409);
            $reconciledSources[$source]=($reconciledSources[$source]??0)+$amount;
            demand($reconciledSources[$source]<=self::cents($taxMap[$source]['amount']),
                'Reconciled amount exceeds the original component; finance review required',409);
            $reconByFund[$fid]=($reconByFund[$fid]??0)+$amount;
            $date=substr((string)($payload['recorded_on']??$r['created_at']),0,10);
            $push($date,'RETAINED_TAX_RECONCILED',$fid,
                'DV '.(string)$taxMap[$source]['dv_number'].' → DV '.(string)$dvMap[$target]['display_number'],
                'Bank-cleared later DV match to original confirmed withholding #'.$source,
                0,0,0,$amount,'RECONCILED','',(string)$r['created_at'],'Tax match #'.(int)$r['id']);
        }
        foreach($payments as $e){$fid=(int)$e['fund_id'];$ensure($fid);
            $event=(string)$e['event_type'];
            if($event==='ISSUED')continue; // book already records check issuance
            $date=substr((string)$e['created_at'],0,10);
            $push($date,'CHECK_STATUS',$fid,'Check '.(string)$e['check_number'].' · DV '.(string)$e['dv_number'],
                (string)($e['evidence_reference']?:'Physical check status update'),0,0,0,0,
                $event,(string)($e['actor']??''),(string)$e['created_at'],
                'Check #'.(int)$e['check_id'].' · Payment event #'.(int)$e['id']);
        }
        foreach($byFund as $fid=>&$fund){
            $confirmed=$confirmedByFund[$fid]??0;$pending=$pendingByFund[$fid]??0;
            $used=$usedByFund[$fid]??0;$recon=$reconByFund[$fid]??0;
            demand($confirmed+$pending>=$used && $confirmed>=$recon,
                'Retained tax consumption exceeds recorded eligible source or reconciled source; finance review required',409);
            $fund['retained_tax_confirmed']=self::amount($confirmed);
            $fund['retained_tax_pending']=self::amount($pending);
            $fund['retained_tax_self_applied_pending']=self::amount($selfPendingByFund[$fid]??0);
            $fund['retained_tax_applied']=self::amount($used);
            $fund['retained_tax_reconciled']=self::amount($recon);
            $fund['retained_tax_unused']=self::amount($confirmed+$pending-$used);
        }unset($fund);
        usort($eventRows,static fn($a,$b)=>strcmp($a['date'],$b['date'])?:strcmp($a['recorded_at'],$b['recorded_at'])?:($a['_seq']<=>$b['_seq']));
        $filtered=[];$periodTotals=['gross'=>0,'bank_credit'=>0,'check_debit'=>0,'check_reversal'=>0,'withholding'=>0,
            'retained_confirmed'=>0,'retained_applied'=>0,'retained_reconciled'=>0];
        foreach($eventRows as $row){if($selected>0 && $row['fund_id']!==$selected)continue;
            if($month!=='' && substr($row['date'],0,7)!==$month)continue;
            if($row['type']==='BANK_DOWNLOAD')$periodTotals['bank_credit']+=self::cents($row['bank_credit']);
            if($row['type']==='FINALIZED_DV_GROSS')$periodTotals['gross']+=self::cents($row['gross']);
            if($row['type']==='NET_CHECK_ISSUED')$periodTotals['check_debit']+=self::cents($row['check']);
            if($row['type']==='CHECK_COMMITMENT_REVERSAL')$periodTotals['check_reversal']+=-self::cents($row['check']);
            if($row['type']==='TAX_WITHHELD')$periodTotals['withholding']+=self::cents($row['withholding']);
            if($row['type']==='RETAINED_TAX_CONFIRMED')$periodTotals['retained_confirmed']+=self::cents($row['retained']);
            if($row['type']==='RETAINED_TAX_APPLIED')$periodTotals['retained_applied']+=self::cents($row['retained']);
            if($row['type']==='RETAINED_TAX_RECONCILED')$periodTotals['retained_reconciled']+=self::cents($row['retained']);
            unset($row['_seq']);$filtered[]=$row;
        }
        $count=count($filtered);$offset=($page-1)*$pageSize;
        return ['as_of'=>$bankBook['as_of']??null,'month'=>$month,'selected_fund_id'=>$selected,
            'funds'=>array_values($byFund),'entries'=>array_slice($filtered,$offset,$pageSize),
            'total_entries'=>$count,'page'=>$page,'page_size'=>$pageSize,
            'period_totals'=>array_map(static fn($c)=>self::amount($c),$periodTotals),
            'notes'=>[
                'Gross finalized supplier DVs are expenditures; withholding transfers are separate liability settlements, not a second gross expense or bank credit.',
                'Net check is a book commitment on issue; CLEARED is evidence of payment but not another debit.',
                'Recorded retained tax (pending and confirmed), the portion applied to gross DVs and later reconciliation are distinct stages. Pending tax may already be applied; never count confirmation as another deposit or authorization.',
                'The source policy snapshot is retained where available. REMIT status is not proof of a check-paid Division transfer or BIR remittance.',
                'System-generated cash book excludes unrecorded external bank movements and maintaining-balance restrictions.'
            ]];
    }
}
