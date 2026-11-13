<?php
declare(strict_types=1);

/**
 * Cash & Bank 0.2.349: reconciliable PROJECTED bank book from authoritative
 * bank-linked fund downloads and real check commitments. This is NOT the
 * bank's issued statement and NOT a DV spend authorization.
 */
final class BankFundRegister {
    private static function cents($value): int {return moneyCents((string)$value);}
    private static function money(int $cents): string {return moneyString($cents);}

    public static function read(PDO $db,int $org): array {
        $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $banks=q($db,'SELECT id,bank_name,account_label,masked_account FROM bank_accounts WHERE organization_id=? ORDER BY bank_name,account_label,id',[$org])->fetchAll(PDO::FETCH_ASSOC);
        $funds=q($db,'SELECT id,name,source_code,fiscal_year FROM funds WHERE organization_id=? ORDER BY fiscal_year DESC,name,id',[$org])->fetchAll(PDO::FETCH_ASSOC);
        // Bank-linked credits only, effective as of today. Unlinked and future
        // downloads are reported separately, NEVER counted as actual cash.
        $credits=q($db,"SELECT e.id,e.fund_id,e.bank_account_id,e.amount,e.bank_credit_date,e.bank_credit_ref,
                e.bank_advice_ref,e.reason,e.created_at
            FROM fund_events e
            JOIN funds f ON f.id=e.fund_id AND f.organization_id=e.organization_id
            JOIN bank_accounts b ON b.id=e.bank_account_id AND b.organization_id=e.organization_id
            WHERE e.organization_id=? AND e.event_type='ALLOTMENT'
                AND e.bank_credit_date IS NOT NULL AND e.bank_credit_date<=?
            ORDER BY e.bank_credit_date,e.id LIMIT 5001",[$org,$today])->fetchAll(PDO::FETCH_ASSOC);
        // Count checks when physical serial is ASSIGNED/ISSUED, not only when
        // cleared. Do not release a stopped or stale check without audited
        // reversal; replaced/cancelled are superseded by current check chain.
        $checks=q($db,"SELECT c.id,c.bank_account_id,c.dv_id,c.check_number,c.amount,c.status,
                c.replaces_check_id,c.payee_name,c.created_at,d.fund_id,d.display_number AS dv_number
            FROM checks c JOIN disbursement_vouchers d ON d.id=c.dv_id AND d.organization_id=c.organization_id
            JOIN bank_accounts b ON b.id=c.bank_account_id AND b.organization_id=c.organization_id
            WHERE c.organization_id=? ORDER BY c.created_at,c.id LIMIT 5001",[$org])->fetchAll(PDO::FETCH_ASSOC);
        demand(count($credits)<=5000 && count($checks)<=5000,
            'Bank transaction register exceeds safe history capacity; use an audited paginated report before relying on these balances.',409);
        $unlinked=q($db,"SELECT fund_id,COUNT(*) AS rows_count,COALESCE(SUM(amount),0) AS total
            FROM fund_events WHERE organization_id=? AND event_type='ALLOTMENT'
                AND (bank_account_id IS NULL OR bank_credit_date IS NULL OR bank_credit_date>?)
            GROUP BY fund_id",[$org,$today])->fetchAll(PDO::FETCH_ASSOC);
        $reversals=q($db,"SELECT pe.check_id,pe.event_type,pe.created_at
            FROM payment_events pe JOIN checks c ON c.id=pe.check_id
            WHERE c.organization_id=? AND pe.event_type IN ('REPLACED','CANCELLED')
            ORDER BY pe.created_at,pe.id LIMIT 5001",[$org])->fetchAll(PDO::FETCH_ASSOC);
        demand(count($reversals)<=5000,'Bank check reversal history exceeds safe report capacity.',409);
        return self::build($banks,$funds,$credits,$checks,$unlinked,$today,$reversals);
    }

    /** Pure projection for synthetic regression tests, no database writes. */
    public static function build(array $banks,array $funds,array $credits,array $checks,array $unlinked=[],?string $asOf=null,array $reversals=[]): array {
        $asOf=$asOf??(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $bankIds=[];$fundIds=[];$pending=[];
        foreach($banks as $b)$bankIds[(int)$b['id']]=true;
        foreach($funds as $f)$fundIds[(int)$f['id']]=true;
        foreach($unlinked as $u)$pending[(int)$u['fund_id']]=['count'=>(int)$u['rows_count'],'amount'=>(string)$u['total']];
        $rows=[];$warnings=[];$sequence=0;
        foreach($credits as $e){
            $b=(int)$e['bank_account_id'];$f=(int)$e['fund_id'];
            demand(isset($bankIds[$b])&&isset($fundIds[$f]),'Bank credit ownership could not be verified',409);
            $date=(string)($e['bank_credit_date']??'');
            demand(preg_match('/^20\d\d-\d\d-\d\d$/D',$date)===1 && $date<=$asOf,'Invalid or future bank credit',409);
            $amount=self::cents($e['amount']);demand($amount>0,'Non-positive bank credit needs finance review',409);
            $rows[]=['key'=>'A'.$e['id'],'date'=>$date,'time'=>$date.' 00:00:00','priority'=>0,'seq'=>$sequence++,
                'bank_account_id'=>$b,'fund_id'=>$f,'kind'=>'DOWNLOAD','reference'=>(string)($e['bank_credit_ref']?:('Download #'.$e['id'])),
                'particulars'=>(string)($e['reason']?:'Verified fund download'),'status'=>'CREDITED',
                'credit_cents'=>$amount,'debit_cents'=>0];
        }
        $reversalTimes=[];
        foreach($reversals as $event){
            $id=(int)($event['check_id']??0);$eventType=(string)($event['event_type']??'');
            demand($id>0&&in_array($eventType,['REPLACED','CANCELLED'],true),'Unrecognized bank book correction',409);
            demand(!isset($reversalTimes[$id]),'Duplicate check reversal event requires finance review',409);
            $reversalTimes[$id]=['time'=>(string)$event['created_at'],'status'=>$eventType];
        }
        $active=['ISSUED','SIGNED','RELEASED','CLEARED','STOPPED','STALE'];
        foreach($checks as $c){
            $b=(int)$c['bank_account_id'];$f=(int)$c['fund_id'];
            demand(isset($bankIds[$b])&&isset($fundIds[$f]),'Bank check funding ownership could not be verified',409);
            $date=substr((string)$c['created_at'],0,10);
            demand(preg_match('/^20\d\d-\d\d-\d\d$/D',$date)===1,'Check issue date is missing; finance review required',409);
            if($date>$asOf)continue;
            $amount=self::cents($c['amount']);demand($amount>0,'Non-positive check amount needs finance review',409);
            $status=(string)$c['status'];
            demand(in_array($status,array_merge($active,['REPLACED','CANCELLED']),true),'Unrecognized check status needs finance review',409);
            if(in_array($status,['STOPPED','STALE'],true))$warnings['check_'.$c['id']]='Stopped/stale check #'.$c['check_number'].' is conservatively reserved until finance resolves it.';
            $rows[]=['key'=>'C'.$c['id'],'date'=>$date,'time'=>(string)$c['created_at'],'priority'=>1,'seq'=>$sequence++,
                'bank_account_id'=>$b,'fund_id'=>$f,'kind'=>'CHECK',
                'reference'=>'Check '.(string)$c['check_number'].' · '.(string)($c['dv_number']??'DV #'.$c['dv_id']),
                'particulars'=>(string)($c['payee_name']?:'Payment'),'status'=>$status,
                'credit_cents'=>0,'debit_cents'=>$amount,'original_amount'=>self::money($amount)];
            // Record an explicit release of the original BOOK commitment when
            // a check is cancelled or replaced. This is NOT a bank deposit.
            if(in_array($status,['REPLACED','CANCELLED'],true)){
                $reverse=$reversalTimes[(int)$c['id']]??null;
                demand($reverse!==null && $reverse['status']===$status,
                    'Historical check cancellation/replacement evidence missing; finance review required',409);
                $rdate=substr($reverse['time'],0,10);
                demand(preg_match('/^20\d\d-\d\d-\d\d$/D',$rdate)===1 && $rdate>=$date,
                    'Historical check reversal has an invalid date',409);
                if($rdate<=$asOf)$rows[]=['key'=>'R'.$c['id'],'date'=>$rdate,'time'=>$reverse['time'],'priority'=>0,'seq'=>$sequence++,
                    'bank_account_id'=>$b,'fund_id'=>$f,'kind'=>'CHECK_REVERSAL',
                    'reference'=>'Reverse check '.(string)$c['check_number'],
                    'particulars'=>$status==='REPLACED'?'Replaced check commitment — not a deposit':'Cancelled check commitment — not a deposit',
                    'status'=>$status,'credit_cents'=>$amount,'debit_cents'=>0,'original_amount'=>self::money($amount)];
            }
        }
        usort($rows,static fn($a,$b)=>strcmp($a['time'],$b['time'])?:($a['priority']<=>$b['priority'])?:($a['seq']<=>$b['seq']));
        $bankTotals=[];$fundTotals=[];$fundBankTotals=[];$combined=0;
        foreach($banks as $b)$bankTotals[(int)$b['id']]=0;
        foreach($funds as $f)$fundTotals[(int)$f['id']]=0;
        foreach($rows as &$r){
            $bid=$r['bank_account_id'];$fid=$r['fund_id'];$delta=$r['credit_cents']-$r['debit_cents'];
            $bankTotals[$bid]+=$delta;$fundTotals[$fid]+=$delta;$combined+=$delta;
            $k=$bid.':'.$fid;$fundBankTotals[$k]=($fundBankTotals[$k]??0)+$delta;
            $r['credit']=self::money($r['credit_cents']);$r['debit']=self::money($r['debit_cents']);
            $r['bank_book_balance']=self::money($bankTotals[$bid]);$r['combined_book_balance']=self::money($combined);
            $r['fund_book_balance']=self::money($fundTotals[$fid]);
            $r['fund_at_bank_balance']=self::money($fundBankTotals[$k]);
            unset($r['credit_cents'],$r['debit_cents'],$r['time'],$r['priority'],$r['seq']);
        }unset($r);
        $byFund=[];$byBank=[];
        foreach($funds as $f){$id=(int)$f['id'];$total=$fundTotals[$id]??0;
            $byFund[]=['fund_id'=>$id,'fund_name'=>$f['name'],'source_code'=>$f['source_code'],'fiscal_year'=>$f['fiscal_year'],
                'book_balance'=>self::money($total),'unlinked_or_future'=>$pending[$id]['amount']??'0.00',
                'pending_downloads'=>$pending[$id]['count']??0,'warning'=>$total<0?'Book cash is negative; finance review required.':''];
        }
        foreach($banks as $b){$id=(int)$b['id'];$total=$bankTotals[$id]??0;
            $byBank[]=['bank_account_id'=>$id,'bank_name'=>$b['bank_name'],'account_label'=>$b['account_label'],
                'masked_account'=>$b['masked_account'],'book_balance'=>self::money($total)];
        }
        $all=array_sum($bankTotals);
        demand($all===array_sum($fundTotals),'Bank and fund cash ledgers do not reconcile; finance review required',409);
        return ['as_of'=>$asOf,'basis'=>'SYSTEM_BOOK_AFTER_ISSUED_CHECKS','combined_book_balance'=>self::money($all),
            'banks'=>$byBank,'funds'=>$byFund,'rows'=>$rows,'warnings'=>array_values($warnings),
            'note'=>'System bank book projected from bank-linked downloads and check commitments. Does not include unrecorded bank fees, external deposits, holds, or statement adjustments; not an official bank statement or stand-alone DV authorization. Superseded checks remain visible without a second debit.'];
    }
}
