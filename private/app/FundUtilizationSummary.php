<?php
declare(strict_types=1);
require_once __DIR__.'/FinanceAccess.php';
require_once __DIR__.'/FinancialClosePolicy.php';
/** Read-only current reconstruction of bank-linked fund activity. Independent
 * of allocation events: a historical review never creates a second payment.
 * Do not interpret this projection as a bank statement or spendable authority. */
final class FundUtilizationSummary {
    public static function verified(array $c):bool {
        return (int)($c['bank_account_id']??0)>0 && in_array($c['reference_type']??'',['BANK_DOWNLOAD','DIVISION_RELEASE'],true)
            && trim((string)($c['bank_credit_ref']??''))!=='' && trim((string)($c['bank_evidence_ref']??''))!==''
            && FinancialClosePolicy::day((string)($c['bank_credit_date']??''));
    }
    public static function read(PDO $db,int $org,string $from,string $to,array $filters,array $funds,array $banks):array {
        $boundary=(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d').' 00:00:00';
        $credits=q($db,"SELECT e.* FROM fund_events e JOIN funds f ON f.id=e.fund_id AND f.organization_id=e.organization_id LEFT JOIN bank_accounts b ON b.id=e.bank_account_id AND b.organization_id=e.organization_id WHERE e.organization_id=? AND e.event_type='ALLOTMENT' AND (e.bank_credit_date<=? OR (e.bank_credit_date IS NULL AND e.created_at<?)) ORDER BY e.id LIMIT 10001",[$org,$to,$boundary])->fetchAll();
        $checks=q($db,"SELECT c.id,c.dv_id,c.bank_account_id,c.amount,c.status,c.created_at,c.replaces_check_id,d.fund_id,d.gross,d.net,d.deductions,d.status dv_status FROM checks c JOIN disbursement_vouchers d ON d.id=c.dv_id AND d.organization_id=c.organization_id WHERE c.organization_id=? AND c.created_at<? ORDER BY c.id LIMIT 10001",[$org,$boundary])->fetchAll();
        $events=q($db,'SELECT e.id,e.check_id,e.event_type,e.created_at FROM payment_events e JOIN checks c ON c.id=e.check_id WHERE c.organization_id=? AND e.created_at<? ORDER BY e.created_at,e.id LIMIT 30001',[$org,$boundary])->fetchAll();
        $tax=q($db,"SELECT w.dv_id,w.amount,w.disposition,(SELECT e.state FROM tax_disposition_events e WHERE e.component_id=w.id ORDER BY e.id DESC LIMIT 1) latest_state FROM withholding_components w JOIN disbursement_vouchers d ON d.id=w.dv_id WHERE d.organization_id=? AND EXISTS(SELECT 1 FROM checks c WHERE c.dv_id=d.id AND c.organization_id=d.organization_id AND c.created_at<?) ORDER BY w.id LIMIT 30001",[$org,$boundary])->fetchAll();
        demand(count($credits)<=10000&&count($checks)<=10000&&count($events)<=30000&&count($tax)<=30000,'Fund history exceeds reporting capacity',409);
        $rows=self::project($credits,$checks,$events,$tax,$from,$to,$to===(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'));
        $fundBy=array_column($funds,null,'id');$bankBy=array_column($banks,null,'id');$out=[];
        foreach($rows as $r){$f=$fundBy[$r['fund_id']]??null;$b=$bankBy[$r['bank_account_id']]??null;
            demand($f!==null&&($r['bank_account_id']===0||$b!==null),'Fund/bank scope is incomplete',409);
            if(!empty($filters['fund_id'])&&(int)$filters['fund_id']!==$r['fund_id'])continue;
            if(!empty($filters['source_code'])&&$filters['source_code']!==$f['source_code'])continue;
            // Unknown bank credit is still an exception for a selected bank.
            if(!empty($filters['bank_account_id'])&&$r['bank_account_id']!==0&&(int)$filters['bank_account_id']!==$r['bank_account_id'])continue;
            $out[]=$r+['fund_name'=>$f['name'],'source_code'=>$f['source_code'],'fiscal_year'=>$f['fiscal_year'],'bank_name'=>$b['bank_name']??'Bank not linked','account_label'=>$b['account_label']??''];
        }
        return $out;
    }
    public static function project(array $credits,array $checks,array $events,array $tax,string $from,string $to,bool $current=false):array {
        demand(FinancialClosePolicy::day($from)&&FinancialClosePolicy::day($to)&&$from<=$to,'Invalid summary dates');
        $rows=[];$creditIds=[];$checkBy=[];$eventsBy=[];$taxBy=[];$dvChecks=[];
        $init=static function(int $f,int $b)use(&$rows):string{$k="$f:$b";$rows[$k]??=['fund_id'=>$f,'bank_account_id'=>$b,'credit_cents'=>0,'period_credit_cents'=>0,'released_cash_cents'=>0,'period_released_cents'=>0,'issued_unreleased_cents'=>0,'gross_paid_cents'=>0,'retained_cents'=>0,'remittable_cents'=>0,'unverified_credit_cents'=>0,'cash_complete'=>true,'tax_complete'=>true,'review'=>[]];return $k;};
        $issue=static function(string $k,string $message,bool $cash=true)use(&$rows):void{$rows[$k]['review'][$message]=true;if($cash)$rows[$k]['cash_complete']=false;else $rows[$k]['tax_complete']=false;};
        foreach($credits as $c){demand(!isset($creditIds[(int)$c['id']]),'Duplicate bank credit source',409);$creditIds[(int)$c['id']]=true;$day=(string)($c['bank_credit_date']??'');if($day!==''&&$day>$to)continue;$k=$init((int)$c['fund_id'],(int)($c['bank_account_id']??0));$n=moneyCents($c['amount']);
            if(!self::verified($c)||$n<=0){$rows[$k]['unverified_credit_cents']+=$n;$issue($k,'Unverified or unlinked fund credit');continue;}
            $rows[$k]['credit_cents']+=$n;if($day>=$from)$rows[$k]['period_credit_cents']+=$n;
        }
        foreach($checks as $c){if(substr((string)$c['created_at'],0,10)>$to)continue;$id=(int)$c['id'];demand(!isset($checkBy[$id]),'Duplicate check source',409);$checkBy[$id]=$c;$dvChecks[(int)$c['dv_id']][]=$id;}
        foreach($events as $e){if(substr((string)$e['created_at'],0,10)<=$to)$eventsBy[(int)$e['check_id']][]=$e;}
        foreach($tax as $t)$taxBy[(int)$t['dv_id']][]=$t;
        $paidByDv=[];$children=[];foreach($checkBy as $c){$parent=(int)($c['replaces_check_id']??0);if($parent)$children[$parent]=($children[$parent]??0)+1;}
        foreach($checkBy as $id=>$c){$k=$init((int)$c['fund_id'],(int)$c['bank_account_id']);$es=$eventsBy[$id]??[];usort($es,static fn($a,$b)=>strcmp($a['created_at'],$b['created_at'])?:((int)$a['id']<=>(int)$b['id']));$stage='ISSUED';$released=[];$terminal=false;
            foreach($es as $e){$type=$e['event_type'];if(!in_array($type,['ISSUED','SIGNED','RELEASED','CLEARED','STOPPED','STALE','CANCELLED','REPLACED'],true))continue;
                if($terminal)$issue($k,'Lifecycle event after cancellation or replacement');
                if($type==='RELEASED')$released[]=$e;
                if(in_array($type,['CANCELLED','REPLACED'],true))$terminal=true;
                $stage=$type;
            }
            if($current&&$stage!==$c['status'])$issue($k,'Current check status does not match dated lifecycle evidence');
            if(in_array($stage,['RELEASED','CLEARED'],true)&&!$released)$issue($k,'Released or cleared status lacks a release event');
            if(in_array($stage,['STOPPED','STALE'],true))$issue($k,'Stopped or stale check requires settlement review');
            if(count($released)>1)$issue($k,'Duplicate release events require review');
            if($released&&$terminal)$issue($k,'Released check subsequently cancelled or replaced; cash recovery requires evidence');
            $parent=(int)($c['replaces_check_id']??0);
            if($parent){if(($children[$parent]??0)>1)$issue($k,'Branching replacement history requires review');$seen=[$id=>true];$child=$c;while($parent){if(isset($seen[$parent])||!isset($checkBy[$parent])){$issue($k,'Incomplete or cyclic replacement lineage');break;}$seen[$parent]=true;$p=$checkBy[$parent];
                if((int)$p['dv_id']!==(int)$c['dv_id']||(int)$p['bank_account_id']!==(int)$c['bank_account_id']||moneyCents($p['amount'])!==moneyCents($c['amount'])||count(array_filter($eventsBy[$parent]??[],static fn($e)=>$e['event_type']==='REPLACED'))!==1)$issue($k,'Replacement source requires review');
                $parent=(int)($p['replaces_check_id']??0);
            }}
            $n=moneyCents($c['amount']);if($n<=0)$issue($k,'Invalid check amount');
            if($released){$rows[$k]['released_cash_cents']+=$n;if(substr($released[0]['created_at'],0,10)>=$from)$rows[$k]['period_released_cents']+=$n;$paidByDv[(int)$c['dv_id']][]=[$id,$k];}
            elseif(!$terminal)$rows[$k]['issued_unreleased_cents']+=$n;
        }
        foreach($paidByDv as $dv=>$paid){$keys=array_unique(array_column($paid,1));$c=$checkBy[$paid[0][0]];$gross=moneyCents($c['gross']);$net=moneyCents($c['net']);$ded=moneyCents($c['deductions']);
            if(count($paid)!==1||count($keys)!==1||moneyCents($c['amount'])!==$net||$gross!==$net+$ded||$c['dv_status']!=='FINALIZED'){foreach($keys as $k){$issue($k,'Partial, multiple, or inconsistent paid DV: gross and tax not classified',false);if(count($paid)>1)$issue($k,'Multiple released checks for one DV require settlement review');}continue;}
            $k=$keys[0];$sameBank=true;foreach($dvChecks[$dv] as $cid)if((int)$checkBy[$cid]['bank_account_id']!==(int)$c['bank_account_id'])$sameBank=false;
            if(!$sameBank){$issue($k,'Cross-bank DV history requires review',false);continue;}
            $ret=$rem=0;$ok=true;foreach($taxBy[$dv]??[] as $t){$v=moneyCents($t['amount']);if($t['disposition']==='SCHOOL_UTILIZATION'&&!in_array($t['latest_state']??'',['REVERSED','REMITTED'],true))$ret+=$v;elseif($t['disposition']==='REMIT'&&($t['latest_state']??'')!=='REVERSED')$rem+=$v;else $ok=false;}
            $rows[$k]['gross_paid_cents']+=$gross;
            if(!$ok||$ret+$rem!==$ded){$issue($k,'Saved tax components do not reconcile to paid DV deductions',false);continue;}
            $rows[$k]['retained_cents']+=$ret;$rows[$k]['remittable_cents']+=$rem;
        }
        // An unlinked credit cannot be distributed to a bank by inference.
        foreach($rows as $k=>$r)if($r['bank_account_id']!==0&&isset($rows[$r['fund_id'].':0']))$issue($k,'Fund contains unlinked bank credits');
        foreach($rows as &$r){$r['opening_cash_cents']=$r['cash_complete']?$r['credit_cents']-$r['period_credit_cents']-$r['released_cash_cents']+$r['period_released_cents']:null;$r['remaining_cash_cents']=$r['cash_complete']?$r['credit_cents']-$r['released_cash_cents']:null;$r['review']=array_keys($r['review']);if(!$r['tax_complete'])foreach(['gross_paid_cents','retained_cents','remittable_cents'] as $key)$r[$key]=null;}unset($r);
        return array_values($rows);
    }
}
