<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
/**
 * v0.2.449 READ-ONLY financial reporting WORKPAPERS. No post, mutation, or inferred
 * accounting settlement. Official certification requires source review.
 */
final class CashFundReports {
    private static function cents(mixed $n): int {return moneyCents((string)$n);}
    public static function peso(int $n): string {return ($n<0?'(':'').number_format(abs($n)/100,2).($n<0?')':'');}
    private static function cutoff(string $period): string {
        demand(preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D',$period)===1,'Reporting period must be YYYY-MM',422);
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$period.'-01',new DateTimeZone('Asia/Manila'));
        demand($d!==false && $d->format('Y-m')===$period,'Invalid reporting period',422);
        return $d->format('Y-m-t');
    }
    /** Pure as-of lifecycle: current status must never rewrite history retroactively. */
    public static function stageAt(array $check,array $events,string $cutoff): string {
        $stage='ISSUED';
        foreach($events as $e){
            if((int)$e['check_id']!==(int)$check['id'])continue;
            if(substr((string)$e['created_at'],0,10)>$cutoff)continue;
            $v=strtoupper((string)$e['event_type']);
            if(in_array($v,['ISSUED','SIGNED','RELEASED','CLEARED','STOPPED','STALE','REPLACED','CANCELLED'],true))$stage=$v;
        }
        return $stage;
    }
    public static function pure(array $fund,array $bank,array $downloads,array $checks,array $events,
        array $tax,array $accounting,array $leaves,array $recon,array $reconLines,string $period,int $bankFundCount=1): array {
        $end=self::cutoff($period);$from=$period.'-01';$prev=(new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
        $warnings=[];$periodChecks=[];$periodReleased=[];$outstanding=[];$allPeriodDownloads=[];
        $taxByDv=[];$acctByDv=[];
        foreach($tax as $w)$taxByDv[(int)$w['dv_id']][]=$w;
        foreach($accounting as $a)$acctByDv[(int)$a['dv_id']][]=$a;
        $bookAt=static function(string $date)use($downloads,$checks,$events):int{
            $balance=0;
            foreach($downloads as $r)if((string)$r['bank_credit_date']<=$date)$balance+=self::cents($r['amount']);
            foreach($checks as $c){
                if(substr((string)$c['created_at'],0,10)>$date)continue;
                $status=self::stageAt($c,$events,$date);
                if(!in_array($status,['CANCELLED','REPLACED'],true))$balance-=self::cents($c['amount']);
            }
            return $balance;
        };
        $bookOpening=$bookAt($prev);$bookClosing=$bookAt($end);
        // Paid-cash workpaper uses recorded RELEASED events, NOT ISSUED check
        // commitments. Keep the two projected balances explicitly separate.
        $releaseDates=[];$clearDates=[];
        foreach($events as $e){
            $id=(int)$e['check_id'];$kind=(string)$e['event_type'];$day=substr((string)$e['created_at'],0,10);
            if($day>$end)continue;
            if($kind==='RELEASED' && !isset($releaseDates[$id]))$releaseDates[$id]=$day;
            if($kind==='CLEARED' && !isset($clearDates[$id]))$clearDates[$id]=$day;
        }
        $checksById=[];foreach($checks as $c)$checksById[(int)$c['id']]=$c;
        foreach($checks as $c){
            $ancestor=(int)($c['replaces_check_id']??0);
            if($ancestor<=0)continue;
            $warnings[]='Replacement check '.$c['check_number'].' requires bank settlement and reversal evidence review; released-event totals are not certified cash settlement.';
            $seen=[(int)$c['id']=>true];
            while($ancestor>0){
                if(isset($seen[$ancestor]) || !isset($checksById[$ancestor])){
                    $warnings[]='Replacement history is cyclic or incomplete for check '.$c['check_number'].'. Review required.';break;
                }
                $seen[$ancestor]=true;
                if(isset($releaseDates[(int)$c['id']]) && isset($releaseDates[$ancestor])){
                    $warnings[]='Replacement check '.$c['check_number'].' and its ancestor both have RELEASED events. Review bank settlement evidence before certifying the cash report.';break;
                }
                $ancestor=(int)($checksById[$ancestor]['replaces_check_id']??0);
            }
        }
        $releaseBalanceAt=static function(string $date)use($downloads,$checks,$releaseDates):int{
            $sum=0;
            foreach($downloads as $e)if((string)$e['bank_credit_date']<=$date)$sum+=self::cents($e['amount']);
            foreach($checks as $c)if(isset($releaseDates[(int)$c['id']])&&$releaseDates[(int)$c['id']]<=$date)
                $sum-=self::cents($c['amount']);
            return $sum;
        };
        $opening=$releaseBalanceAt($prev);$closing=$releaseBalanceAt($end);
        $credit=0;$payment=0;$issuedValue=0;
        foreach($downloads as $r){
            if((string)$r['bank_credit_date']>=$from&&(string)$r['bank_credit_date']<=$end){
                $allPeriodDownloads[]=$r;$credit+=self::cents($r['amount']);
            }
        }
        foreach($checks as $c){
            // The date printed on a physical check must be the finalized DV's document date.
            // A check's issuance/release/clear timestamps are separate audit events, not
            // alternative dates to put on the instrument or into its document-date field.
            $issueDate=substr((string)$c['created_at'],0,10);
            if($issueDate>$end)continue;
            $dvDate=(string)($c['dv_date']??'');
            if(!preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$dvDate)){
                $warnings[]='Check '.($c['check_number']??$c['id']).' lacks a valid finalized DV document date. Do not certify a printed check date until the original DV is verified.';
                $dvDate='';
            }
            $stage=self::stageAt($c,$events,$end);
            $c['stage_at_end']=$stage;
            $c['check_date']=$dvDate; // printed instrument date, not inferred from issuance
            $c['issue_date']=$issueDate; // when the recorded check was actually issued
            if($stage==='STOPPED'||$stage==='STALE')$warnings[]='Check '.$c['check_number'].' is '.$stage.'; its bank-book treatment needs finance review.';
            if($issueDate>=$from){$periodChecks[]=$c;$issuedValue+=self::cents($c['amount']);}
            $releaseDate=$releaseDates[(int)$c['id']]??null;
            if($releaseDate!==null){
                $c['release_date']=$releaseDate;
                if($releaseDate>=$from){$periodReleased[]=$c;$payment+=self::cents($c['amount']);}
                if(!isset($clearDates[(int)$c['id']]) && !in_array($stage,['CANCELLED','REPLACED','STOPPED','STALE'],true))
                    $outstanding[]=$c;
            } elseif(in_array($stage,['RELEASED','CLEARED'],true)) {
                $warnings[]='Check '.$c['check_number'].' has status '.$stage.' but lacks a recorded RELEASED event; cannot include it in certified cash disbursements.';
            }
        }
        $periodReversals=0;
        foreach($checks as $c){
            $issue=substr((string)$c['created_at'],0,10);
            foreach($events as $e){
                if((int)$e['check_id']!==(int)$c['id'])continue;
                $date=substr((string)$e['created_at'],0,10);
                if($date>=$from&&$date<=$end && in_array($e['event_type'],['CANCELLED','REPLACED'],true))$periodReversals+=self::cents($c['amount']);
            }
        }
        demand($opening+$credit-$payment===$closing,
            'Released-check cash projection does not reconcile; reporting suspended',409);
        demand($bookOpening+$credit-$issuedValue+$periodReversals===$bookClosing,
            'Issued-check commitment projection does not reconcile; reporting suspended',409);
        $listedFunds=0;$checkHistory=[];
        foreach($checks as $c)$checkHistory[(int)$c['dv_id']][]=$c;
        $includedDv=[];$dvRows=[];$uacs=[];$negativeTax=0;$taxStats=['EWT'=>0,'VAT'=>0,'OTHER'=>0];$reconciliationReady=true;
        foreach($periodReleased as $c){
            $dv=(int)$c['dv_id'];$amount=self::cents($c['amount']);
            $isFirst=!isset($includedDv[$dv]);$includedDv[$dv]=true;
            $uacsParts=[];$savedTax=0;$schoolUtilization=0;
            if($isFirst){
                foreach($taxByDv[$dv]??[] as $w){
                    $cents=self::cents($w['amount']);$savedTax+=$cents;
                    $kind=strtoupper((string)($w['tax_kind']??'OTHER'));if(!isset($taxStats[$kind]))$kind='OTHER';
                    $taxStats[$kind]+=$cents;
                    if(($w['disposition']??'')==='SCHOOL_UTILIZATION'){
                        $latest=(string)($w['latest_state']??'');
                        if(in_array($latest,['REVERSED','REMITTED'],true)){
                            $warnings[]='DV '.($c['dv_number']??$dv).' has reversed/remitted school-utilization evidence; review tax status before recapitulating.';
                            $reconciliationReady=false;
                        } else {
                            $schoolUtilization+=$cents;
                            if(!in_array($latest,['CONFIRMED_CREDIT'],true)){
                                $warnings[]='DV '.($c['dv_number']??$dv).' school-utilized withholding is '.$latest.'; confirmation is still required for certification.';
                                $reconciliationReady=false;
                            }
                        }
                    }
                }
                foreach($acctByDv[$dv]??[] as $a){
                    $debit=self::cents($a['debit']);
                    if($debit===0)continue;
                    $code=(string)$a['uacs_code'];
                    // Do not treat liability, bank/cash or operating advance debits as expenditure categories.
                    if(str_starts_with($code,'2') || str_starts_with($code,'101') || str_starts_with($code,'1990101000'))continue;
                    $uacs[$code]=($uacs[$code]??0)+$debit;
                    $uacsParts[$code]=($uacsParts[$code]??0)+$debit;
                }
                $negativeTax+=$schoolUtilization;
                if(!($acctByDv[$dv]??[])){
                    $warnings[]='DV '.($c['dv_number']??$dv).' lacks an approved/saved accounting version for UACS classification.';
                    $reconciliationReady=false;
                }
                // A DV may be paid by multiple checks. Do not assign its full gross to a single check without evidence.
                $activeTotal=0;$allSamePeriod=true;
                foreach($checkHistory[$dv]??[] as $linked){
                    $rid=(int)$linked['id'];
                    if(in_array(self::stageAt($linked,$events,$end),['CANCELLED','REPLACED'],true))continue;
                    if(!isset($releaseDates[$rid]))continue;
                    $activeTotal+=self::cents($linked['amount']);
                    if($releaseDates[$rid]<$from || $releaseDates[$rid]>$end)$allSamePeriod=false;
                }
                if(!$allSamePeriod||$activeTotal!==self::cents($c['dv_net'])){
                    $warnings[]='DV '.($c['dv_number']??$dv).' has partial, split or mismatched check settlement; its full gross allocations cannot yet be certified against this period.';
                    $reconciliationReady=false;
                }
            }
            $dvRows[]=['check'=>$c,'amount_cents'=>$amount,'tax_cents'=>$isFirst?$savedTax:0,
                'school_utilization_cents'=>$isFirst?$schoolUtilization:0,'uacs'=>$uacsParts,
                'duplicate_dv'=>!$isFirst];
        }
        // This can be informative but is not a legitimate report-balance tie-out when
        // gross expenses include other periods, split checks or non-utilized REMIT taxes.
        $grossClassified=array_sum($uacs);
        $recapCash=$grossClassified-$negativeTax;
        if($recon===[] || ($recon['status']??'')!=='CLOSED')$warnings[]='No CLOSED bank reconciliation for this account and month; Appendix 80 cannot be certified.';
        if($bankFundCount>1)$warnings[]='The selected bank account has transactions for multiple funds. Account-wide bank statement figures are not a separate statement for this fund.';
        if(count($allPeriodDownloads)>1)$warnings[]='Multiple downloads in the month share this fund; individual DV liquidation cannot be assigned to a specific download without an explicit source link.';
        if(!$reconciliationReady)$warnings[]='Appendix 43 is a draft workpaper until accounting classifications and utilization evidence are reviewed.';
        if($grossClassified>0 && $recapCash!==$payment)$warnings[]='Appendix 43 recapitulation (gross less utilization) does not match period check commitments; review split checks, other tax dispositions and source accounting.';
        if(!$downloads)$warnings[]='No verified bank-linked fund download was found through the reporting date.';
        if($period===(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m'))
            $warnings[]='The selected calendar month is not yet closed; printed totals are provisional.';
        if(!$leaves)$warnings[]='No registered physical check leaves for Appendix 67.';
        $reconAdjust=0;foreach($reconLines as $r){$v=(string)$r['amount'];$reconAdjust+=str_starts_with($v,'-')?-self::cents(substr($v,1)):self::cents($v);}
        if($recon && self::cents($recon['book_balance'])+$reconAdjust!==self::cents($recon['statement_balance']))
            $warnings[]='Saved bank reconciliation adjustment total does not tie to recorded statement balance.';
        $leavesPeriod=[];$leafOpen=0;$leafReceipts=0;$leafIssues=0;
        foreach($leaves as $leaf){
            $date=substr((string)$leaf['created_at'],0,10);
            if($date>$end)continue;
            $serial=(string)$leaf['serial'];
            $match=null;
            foreach($checks as $c)if((string)$c['check_number']===$serial){$match=$c;break;}
            $issue=$match?substr((string)$match['created_at'],0,10):null;
            $leaf['open_at_start']=$date<$from && ($issue===null || $issue>=$from);
            $leaf['received_this_month']=$date>=$from;
            $leaf['issued_this_month']=$issue!==null && $issue>=$from && $issue<=$end;
            if($leaf['open_at_start'])$leafOpen++;
            if($leaf['received_this_month'])$leafReceipts++;
            if($leaf['issued_this_month'])$leafIssues++;
            $leavesPeriod[]=$leaf;
            if(in_array((string)($leaf['status']??''),['SPOILED','VOID','LOST'],true))
                $warnings[]='Appendix 67 has unusable check leaves. Verify dated spoiled/lost/void evidence before reporting historical book stock.';
        }
        $outstandingCents=array_sum(array_map(static fn($c)=>self::cents($c['amount']),$outstanding));
        return compact('fund','bank','period','from','end','prev','opening','closing','credit','payment','periodReversals','allPeriodDownloads',
            'periodChecks','periodReleased','bookOpening','bookClosing','issuedValue','outstanding','outstandingCents','dvRows','uacs','negativeTax','taxStats','grossClassified','recapCash',
            'warnings','recon','reconLines','reconAdjust','leavesPeriod','leafOpen','leafReceipts','leafIssues')+
            ['certified'=>false,'report_basis'=>'READ-ONLY / SOURCE-DOCUMENT WORKPAPER'];
    }
    public static function load(PDO $db,array $user,array $p): array {
        demand(($user['role']??'')!=='VIEWER','Detailed bank and liquidation reports are restricted',403);
        $org=idOf($p,'organization_id');scope($db,$user,$org);
        $fundId=idOf($p,'fund_id');$bankId=idOf($p,'bank_account_id');$period=(string)($p['period']??'');$end=self::cutoff($period);
        $exclusiveEnd=(new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d').' 00:00:00';
        demand($period<=date('Y-m'),'Future reporting period is not available',422);
        $fund=one($db,'SELECT id,organization_id,name,source_code,fiscal_year FROM funds WHERE id=? AND organization_id=?',[$fundId,$org]);
        $bank=one($db,'SELECT id,organization_id,bank_name,account_label,masked_account FROM bank_accounts WHERE id=? AND organization_id=?',[$bankId,$org]);
        demand($fund!==null && $bank!==null,'Fund or bank account is not registered under this organization',404);
        $downloads=q($db,"SELECT id,amount,bank_credit_date,bank_credit_ref,bank_advice_ref,reason,created_at FROM fund_events
            WHERE organization_id=? AND fund_id=? AND bank_account_id=? AND event_type='ALLOTMENT'
              AND bank_credit_date IS NOT NULL AND bank_credit_date<=? ORDER BY bank_credit_date,id LIMIT 10001",
            [$org,$fundId,$bankId,$end])->fetchAll(PDO::FETCH_ASSOC);
        $checks=q($db,"SELECT c.id,c.bank_account_id,c.dv_id,c.check_number,c.payee_name,c.amount,c.status,c.created_at,c.replaces_check_id,
            d.display_number AS dv_number,d.document_date AS dv_date,d.payee AS dv_payee,d.net AS dv_net,d.gross AS dv_gross,
            d.deductions AS dv_deductions,d.dv_type,d.status AS dv_status
            FROM checks c JOIN disbursement_vouchers d ON d.id=c.dv_id AND d.organization_id=c.organization_id
            WHERE c.organization_id=? AND d.organization_id=? AND d.fund_id=? AND c.bank_account_id=?
              AND c.created_at<? ORDER BY c.created_at,c.id LIMIT 10001",
            [$org,$org,$fundId,$bankId,$exclusiveEnd])->fetchAll(PDO::FETCH_ASSOC);
        demand(count($downloads)<=10000 && count($checks)<=10000,'History exceeds safe reporting capacity. Request paginated finance review.',409);
        $events=q($db,"SELECT e.check_id,e.event_type,e.created_at,e.id FROM payment_events e JOIN checks c ON c.id=e.check_id
            JOIN disbursement_vouchers d ON d.id=c.dv_id AND d.organization_id=c.organization_id
            WHERE c.organization_id=? AND c.bank_account_id=? AND d.fund_id=? AND e.created_at<?
            ORDER BY e.created_at,e.id LIMIT 30001",[$org,$bankId,$fundId,$exclusiveEnd])->fetchAll(PDO::FETCH_ASSOC);
        demand(count($events)<=30000,'Check lifecycle history exceeds report capacity.',409);
        $tax=q($db,"SELECT w.dv_id,w.atc_code,w.amount,w.disposition,a.tax_kind,
              (SELECT e.state FROM tax_disposition_events e WHERE e.component_id=w.id ORDER BY e.id DESC LIMIT 1) latest_state
            FROM withholding_components w JOIN disbursement_vouchers d ON d.id=w.dv_id
            LEFT JOIN tax_atcs a ON a.code=w.atc_code
            WHERE d.organization_id=? AND d.fund_id=? AND d.status='FINALIZED'
              AND EXISTS (SELECT 1 FROM checks c WHERE c.organization_id=? AND c.bank_account_id=? AND c.dv_id=d.id AND c.created_at<?)
            ORDER BY w.dv_id,w.id LIMIT 10001",[$org,$fundId,$org,$bankId,$exclusiveEnd])->fetchAll(PDO::FETCH_ASSOC);
        $accounting=q($db,"SELECT v.dv_id,l.uacs_code,l.debit,l.credit,l.explanation
            FROM dv_accounting_versions v JOIN voucher_accounting_lines l ON l.accounting_version_id=v.id
            JOIN disbursement_vouchers d ON d.id=v.dv_id
            WHERE d.organization_id=? AND d.fund_id=? AND d.status='FINALIZED'
              AND v.id=(SELECT v2.id FROM dv_accounting_versions v2 WHERE v2.dv_id=v.dv_id ORDER BY v2.version_no DESC,v2.id DESC LIMIT 1)
              AND EXISTS (SELECT 1 FROM checks c WHERE c.dv_id=d.id AND c.organization_id=? AND c.bank_account_id=? AND c.created_at<?)
            ORDER BY v.dv_id,l.id LIMIT 10001",[$org,$fundId,$org,$bankId,$exclusiveEnd])->fetchAll(PDO::FETCH_ASSOC);
        demand(count($tax)<=10000 && count($accounting)<=10000,'Tax or accounting source history exceeds safe report capacity.',409);
        $leaves=q($db,"SELECT id,bank_account_id,book_reference,serial,status,created_at FROM checkbook_leaves
            WHERE organization_id=? AND bank_account_id=? AND created_at<? ORDER BY book_reference,id LIMIT 10001",
            [$org,$bankId,$exclusiveEnd])->fetchAll(PDO::FETCH_ASSOC);
        demand(count($leaves)<=10000,'Physical check inventory exceeds reporting capacity.',409);
        $recon=one($db,'SELECT id,bank_account_id,period,status,statement_balance,book_balance,notes FROM bank_reconciliations WHERE bank_account_id=? AND period=?',[$bankId,$period])??[];
        $reconLines=$recon?q($db,'SELECT kind,amount,reference FROM reconciliation_lines WHERE reconciliation_id=? ORDER BY id LIMIT 501',[(int)$recon['id']])->fetchAll(PDO::FETCH_ASSOC):[];
        demand(count($reconLines)<=500,'Reconciliation adjustment source exceeds reporting capacity.',409);
        $fundCount=(int)(one($db,"SELECT COUNT(DISTINCT fund_id) n FROM fund_events WHERE organization_id=? AND bank_account_id=? AND event_type='ALLOTMENT' AND bank_credit_date<=?",[$org,$bankId,$end])['n']??1);
        $out=self::pure($fund,$bank,$downloads,$checks,$events,$tax,$accounting,$leaves,$recon,$reconLines,$period,$fundCount);
        $out['warnings'][]='Tax disposition and accounting versions are current saved snapshots, not a certified historical as-of ledger. Review original approved documents for the selected month.';
        $out['organization']=one($db,'SELECT id,name,code,kind,address FROM organizations WHERE id=?',[$org]);
        $out['options']=[
            'funds'=>q($db,'SELECT id,name,source_code,fiscal_year FROM funds WHERE organization_id=? ORDER BY fiscal_year DESC,source_code LIMIT 201',[$org])->fetchAll(PDO::FETCH_ASSOC),
            'banks'=>q($db,'SELECT id,bank_name,account_label,masked_account FROM bank_accounts WHERE organization_id=? ORDER BY bank_name,account_label LIMIT 201',[$org])->fetchAll(PDO::FETCH_ASSOC),
        ];
        return $out;
    }
}
