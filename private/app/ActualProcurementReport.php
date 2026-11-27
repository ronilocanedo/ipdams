<?php
declare(strict_types=1);
require_once __DIR__.'/FinanceAccess.php';
require_once __DIR__.'/FinancialClosePolicy.php';
require_once __DIR__.'/PrUacsInheritance.php';
/** Current-record, read-only actual procurement schedule, not an approved plan or cash ledger. */
final class ActualProcurementReport {
    public static function dates(string $from,string $to,string $today):void {
        demand(FinancialClosePolicy::day($from)&&FinancialClosePolicy::day($to)&&$from<=$to&&$to<=$today,'Select a valid inclusive date range through today.');
        demand(substr($from,0,4)===substr($to,0,4),'Generate a separate APP for each calendar year.');
    }
    private static function plus(int $a,int $b):int {demand($a>=0&&$b>=0&&$a<=PHP_INT_MAX-$b,'Report total exceeds supported capacity',409);return $a+$b;}
    private static function extended(int $price,int $qty):int {demand($price>0&&$qty>0&&$qty<=intdiv(PHP_INT_MAX-500,$price),'Item quantity or price exceeds supported capacity',409);return totalCents($price,$qty);}
    /** Do not count a replacement again, or infer valid payment from a stopped/voided instrument. */
    public static function checkPayment(array $dv,array $checks,array $events):?string {
        if(!$checks)return 'No recorded check';
        $by=[];$active=[];$children=[];$ev=[];
        foreach($events as $e){$ev[(int)$e['check_id']][$e['event_type']]=($ev[(int)$e['check_id']][$e['event_type']]??0)+1;}
        foreach($checks as $c){$id=(int)$c['id'];if(isset($by[$id])||(int)$c['dv_id']!==(int)$dv['id'])return 'Invalid check identity';$by[$id]=$c;if(array_key_exists('bank_scope_id',$c)&&!$c['bank_scope_id'])return 'Check bank is outside the selected organization';
            if(in_array($c['status'],['ISSUED','SIGNED','RELEASED','CLEARED'],true))$active[]=$c;
            $parent=(int)($c['replaces_check_id']??0);if($parent)$children[$parent]=($children[$parent]??0)+1;
        }
        if(count($active)!==1)return 'No single valid payable check; review split, stopped, voided or replacement history';
        $c=$active[0];$seen=[];$releaseCount=0;$bank=(int)$c['bank_account_id'];$amount=moneyCents($c['amount']);
        if($bank<=0||$amount!==moneyCents($dv['net'])||$amount<=0||trim((string)$c['check_number'])==='')return 'Check does not reconcile to DV net amount';
        $cursor=$c;
        while(true){$id=(int)$cursor['id'];if(isset($seen[$id])||($children[$id]??0)>1||(int)$cursor['bank_account_id']!==$bank||moneyCents($cursor['amount'])!==$amount)return 'Inconsistent replacement history';$seen[$id]=true;
            if(($ev[$id]['ISSUED']??0)!==1||($ev[$id]['RELEASED']??0)>1)return 'Missing or duplicate check issue/release event';
            $releaseCount+=$ev[$id]['RELEASED']??0;
            if($id===(int)$c['id']){if(($children[$id]??0)!==0)return 'Active check has a replacement';if(in_array($cursor['status'],['RELEASED','CLEARED'],true)&&($ev[$id]['RELEASED']??0)!==1)return 'Released check lacks release evidence';if(in_array($cursor['status'],['ISSUED','SIGNED'],true)&&($ev[$id]['RELEASED']??0)!==0)return 'Check state and release evidence disagree';}
            elseif($cursor['status']!=='REPLACED'||($ev[$id]['REPLACED']??0)!==1)return 'Prior check replacement not documented';
            $p=(int)($cursor['replaces_check_id']??0);if(!$p)break;if(!isset($by[$p]))return 'Missing prior check';$cursor=$by[$p];
        }
        if(count($seen)!==count($by)||$releaseCount>1)return 'Multiple or unrelated check histories need review';
        // Only the active, currently released/cleared instrument qualifies as paid.
        // A prior release does not establish payment of an unreleased replacement.
        if(!in_array($c['status'],['RELEASED','CLEARED'],true))return 'Unpaid — check not released';
        if($releaseCount!==1||($ev[(int)$c['id']]['RELEASED']??0)!==1)return 'Payment release evidence is missing or ambiguous';
        return null;
    }
    /** Bounded exact match. Do not choose an arbitrary assignment when equal totals admit alternatives. */
    private static function matchLegacy(array $rows,array $remaining): ?array {
        $unknown=[];$assigned=[];
        foreach($rows as $i=>$r){if($r['code']===''){$unknown[$i]=$r['amount'];continue;}
            if(!isset($remaining[$r['code']])||$remaining[$r['code']]<$r['amount'])return null;
            $remaining[$r['code']]-=$r['amount'];$assigned[$i]=$r['code'];}
        if(count($unknown)>24||count($remaining)>16)return null;
        arsort($unknown,SORT_NUMERIC);$ids=array_keys($unknown);$solutions=[];$nodes=0;$aborted=false;
        $walk=function(int $at,array $left,array $picked) use (&$walk,&$solutions,&$nodes,&$aborted,$ids,$unknown):void {
            if(count($solutions)>1||$aborted)return;
            if(++$nodes>20000){$aborted=true;return;}
            if($at===count($ids)){if(!array_filter($left))$solutions[]=$picked;return;}
            $i=$ids[$at];$amount=$unknown[$i];foreach($left as $code=>$balance){if($balance<$amount)continue;$next=$left;$next[$code]-=$amount;$a=$picked;$a[$i]=(string)$code;$walk($at+1,$next,$a);if(count($solutions)>1||$aborted)return;}
        };
        $walk(0,$remaining,$assigned);return !$aborted&&count($solutions)===1?$solutions[0]:null;
    }
    private static function labelKey(string $s):string {return strtolower(trim(preg_replace('/\s+/u',' ',$s)??$s));}
    /** Saved budget expense and procurement asset debits only. Cash, liability and other
     * non-budget movements require separate review, never automatic APP recognition. */
    private static function budgetDebit(string $code):bool {
        return PrUacsInheritance::debitAccount($code)||preg_match('/^5[0-9]{9}$/D',$code)===1;
    }
    /** Travel and training budget classes are amount-only even when an IAR exists. */
    private static function consolidatedExpense(string $code):bool {
        return preg_match('/^5020[12][0-9]{5}$/D',$code)===1;
    }
    private static function approvedDebits(array $dv,array $journal):?array {
        $gross=moneyCents($dv['gross']);$dr=$cr=0;$rows=[];
        if($gross<=0||$gross!==moneyCents($dv['net'])+moneyCents($dv['deductions']))return null;
        foreach($journal as $j){$d=moneyCents($j['debit']);$c=moneyCents($j['credit']);$dr=self::plus($dr,$d);$cr=self::plus($cr,$c);if(!$d)continue;
            $code=(string)$j['uacs_code'];$name=trim((string)($j['account_name']??''));if($name===''||!self::budgetDebit($code))return null;
            $rows[$code]??=['title'=>$name,'amount'=>0];$rows[$code]['amount']=self::plus($rows[$code]['amount'],$d);}
        return $dr===$gross&&$cr===$gross&&$rows?$rows:null;
    }
    /** All-or-nothing per voucher: every item must reconcile to approved debit lines. */
    public static function itemize(array $dv,array $alloc,array $journal):array {
        $gross=moneyCents($dv['gross']);$net=moneyCents($dv['net']);$ded=moneyCents($dv['deductions']);
        if($gross<=0||$gross!==$net+$ded)return ['issue'=>'DV gross, deductions and net do not reconcile'];
        $debits=[];$names=[];$dr=$cr=0;
        foreach($journal as $j){$d=moneyCents($j['debit']);$c=moneyCents($j['credit']);$dr=self::plus($dr,$d);$cr=self::plus($cr,$c);if(!$d)continue;$code=(string)$j['uacs_code'];if(!PrUacsInheritance::debitAccount($code)||trim((string)($j['account_name']??''))==='')return ['issue'=>'Approved procurement debit classification unavailable'];$debits[$code]=self::plus($debits[$code]??0,$d);$names[$code]=$j['account_name'];}
        if($dr!==$gross||$cr!==$gross||!$debits)return ['issue'=>'Approved accounting version does not reconcile to gross'];
        if(!$alloc)return ['issue'=>'No itemized procurement source'];
        $rows=[];$used=[];$sum=0;$ids=[];$iar=[];
        foreach($alloc as $a){$id=(int)$a['allocation_id'];if(isset($ids[$id]))return ['issue'=>'Duplicate DV source allocation'];$ids[$id]=true;
            $amount=moneyCents($a['amount']);$sum=self::plus($sum,$amount);
            if(empty($a['iar_line_id'])||empty($a['item_id'])||($a['iar_status']??'')!=='FINALIZED'||($a['po_status']??'')!=='FINALIZED'||($a['pr_status']??'')!=='FINALIZED'||(int)($a['source_fund_id']??0)!==(int)$dv['fund_id']||(int)($a['source_pr_id']??0)!==(int)($a['pr_id']??0))return ['issue'=>'Direct/service/reimbursement or missing finalized item evidence; verify actual quantity and price'];
            $iarId=(int)$a['iar_line_id'];if(isset($iar[$iarId]))return ['issue'=>'Repeated acceptance line within voucher'];$iar[$iarId]=true;
            $qty=qtyMillis($a['accepted_qty']);$price=moneyCents($a['unit_price']);$accepted=moneyCents($a['accepted_amount']);
            if($qty<=0||$price<=0||$amount!==$accepted||self::extended($price,$qty)!==$amount)return ['issue'=>'Partial or mismatched acceptance amount; actual paid item quantity not established'];
            if(trim((string)$a['description_snapshot'])===''||trim((string)$a['unit_snapshot'])==='')return ['issue'=>'Missing item description or unit'];
            $code=trim((string)($a['accounting_uacs_code']??''));
            $snapRaw=(string)($a['pr_snapshot']??'');$snap=json_decode($snapRaw,true);
            if($snapRaw!==''&&!is_array($snap))return ['issue'=>'Invalid saved PR snapshot'];
            $saved=$snap['uacs_line_snapshot']??null;
            if($saved!==null){$inherit=is_array($saved)?PrUacsInheritance::inherited($saved,(int)$a['source_pr_id'],(int)$a['pr_line_id'],(int)$a['item_id'],(string)$a['pr_date']):['ok'=>false];
                if(!$inherit['ok'])return ['issue'=>'Invalid saved PR item-level classification'];if($code==='')$code=$inherit['uacs_code'];}
            if($code===''&&count($debits)===1)$code=(string)array_key_first($debits);
            if($code!==''&&!isset($debits[$code]))return ['issue'=>'Saved item classification disagrees with approved DV accounting'];
            $rows[]=['code'=>$code,'item_id'=>(int)$a['item_id'],'description'=>trim($a['description_snapshot']),'unit'=>trim($a['unit_snapshot']),'qty'=>$qty,'price'=>$price,'amount'=>$amount,'iar_line_id'=>$iarId,'dv_id'=>(int)$dv['id'],'month'=>(int)substr($dv['document_date'],5,2)];
        }
        if($sum!==$gross)return ['issue'=>'Item allocation totals do not reconcile to gross'];
        $legacy=false;foreach($rows as $r)if($r['code']==='')$legacy=true;
        if($legacy){$match=self::matchLegacy($rows,$debits);if($match===null)return ['issue'=>'Legacy item classifications do not have a unique exact match to approved debit totals; source review required'];foreach($rows as $i=>&$r)$r['code']=$match[$i];unset($r);}
        foreach($rows as &$r){$code=$r['code'];$r['title']=$names[$code];$used[$code]=self::plus($used[$code]??0,$r['amount']);}unset($r);
        ksort($debits);ksort($used);if($used!==$debits)return ['issue'=>'Item allocation totals do not reconcile by approved UACS'];
        return ['rows'=>$rows,'legacy_match'=>$legacy];
    }
    public static function project(array $dvs,array $checks,array $events,array $alloc,array $journal):array {
        $byCheck=$byEv=$byAlloc=$byJournal=$iarDvs=[];
        foreach($checks as $c)$byCheck[(int)$c['dv_id']][]=$c;
        foreach($events as $e)$byEv[(int)$e['check_id']][]=$e;
        foreach($alloc as $a){$byAlloc[(int)$a['dv_id']][]=$a;if(!empty($a['iar_line_id']))$iarDvs[(int)$a['iar_line_id']][(int)$a['dv_id']]=true;}
        foreach($journal as $j)$byJournal[(int)$j['dv_id']][]=$j;
        $groups=[];$review=[];$excluded=[];$accountSummary=[];$legacyMatches=[];$unclassified=0;$total=$eligible=$unitemized=$nonproc=0;$seen=[];
        foreach($dvs as $dv){demand(FinancialClosePolicy::day((string)$dv['document_date']),'Missing or invalid DV document date',409);$id=(int)$dv['id'];demand(!isset($seen[$id]),'Duplicate DV source',409);$seen[$id]=true;$gross=moneyCents($dv['gross']);$cs=$byCheck[$id]??[];$es=[];foreach($cs as $c)foreach($byEv[(int)$c['id']]??[] as $e)$es[]=$e;
            $why=self::checkPayment($dv,$cs,$es);$brief=['id'=>$id,'number'=>$dv['display_number'],'date'=>$dv['document_date'],'gross'=>$gross,'particulars'=>$dv['particulars']??'','type'=>$dv['dv_type']];
            if($why!==null){$excluded[]=$brief+['reason'=>$why];continue;}
            $eligible=self::plus($eligible,$gross);
            if(!empty($dv['tax_transfer'])){$nonproc=self::plus($nonproc,$gross);$excluded[]=$brief+['reason'=>'Withholding transfer — not a new procurement'];continue;}
            $as=$byAlloc[$id]??[];$duplicate=false;foreach($as as $a)if(!empty($a['iar_line_id'])&&(count($iarDvs[(int)$a['iar_line_id']]??[])>1||!empty($a['other_finalized_dv'])))$duplicate=true;
            $itemized=$duplicate?['issue'=>'Acceptance line shared by multiple finalized DVs; review payment allocation']:self::itemize($dv,$as,$byJournal[$id]??[]);
            $approved=self::approvedDebits($dv,$byJournal[$id]??[]);
            $m=(int)substr($dv['document_date'],5,2)-1;$q=intdiv($m,3);
            $failed=isset($itemized['issue']);$needsReview=$approved===null;
            // Accounting titles are the grouping authority, not DV descriptions or payees.
            // All travel/training debits are aggregated, including those with item evidence.
            if($approved===null){$unclassified=self::plus($unclassified,$gross);$unitemized=self::plus($unitemized,$gross);}
            else foreach($approved as $code=>$v){
                $code=(string)$code;$key=self::labelKey($v['title']);$accountSummary[$key]??=['title'=>$v['title'],'codes'=>[],'quarters'=>array_fill(0,4,0),'reviewQuarters'=>array_fill(0,4,0),'amount'=>0,'itemized'=>0,'review'=>0,'amountOnlyRows'=>[]];$g=&$accountSummary[$key];
                $g['codes'][$code]=true;$g['quarters'][$q]=self::plus($g['quarters'][$q],$v['amount']);$g['amount']=self::plus($g['amount'],$v['amount']);
                $consolidated=self::consolidatedExpense($code);$amountOnlyLine=$failed||$consolidated;
                $field=$amountOnlyLine?'review':'itemized';$g[$field]=self::plus($g[$field],$v['amount']);
                if($amountOnlyLine){
                    // A single amount-only line per accounting title, across all months and DVs.
                    // For non-travel/training classifications lacking items, retain source review.
                    $lineReview=$failed&&!$consolidated;$needsReview=$needsReview||$lineReview;
                    $g['reviewQuarters'][$q]=self::plus($g['reviewQuarters'][$q],$v['amount']);
                    $g['amountOnlyRows']['account']??=['description'=>$g['title'],'codes'=>[],'amounts'=>array_fill(0,12,0),'dv_ids'=>[],'needsReview'=>false];
                    $l=&$g['amountOnlyRows']['account'];$l['codes'][$code]=true;$l['amounts'][$m]=self::plus($l['amounts'][$m],$v['amount']);$l['dv_ids'][$id]=true;$l['needsReview']=$l['needsReview']||$lineReview;unset($l);
                    $unitemized=self::plus($unitemized,$v['amount']);
                }unset($g);
            }
            if($needsReview)$review[]=$brief+['reason'=>$approved===null?'Saved budget debit classifications or gross reconciliation unavailable; review source accounting.':$itemized['issue']];
            if($failed||$approved===null)continue;
            if($itemized['legacy_match']&&array_filter($itemized['rows'],static fn($r)=>!self::consolidatedExpense((string)$r['code'])))$legacyMatches[]=$brief;
            foreach($itemized['rows'] as $r){if(self::consolidatedExpense((string)$r['code']))continue;$k=json_encode([$r['item_id'],self::labelKey($r['description']),self::labelKey($r['unit']),$r['price']],JSON_THROW_ON_ERROR);$code=$r['code'];$key=self::labelKey($r['title']);$groups[$key]??=['title'=>$r['title'],'codes'=>[],'rows'=>[],'quarters'=>array_fill(0,4,0),'amount'=>0];$g=&$groups[$key];$g['codes'][$code]=true;$g['rows'][$k]??=['description'=>$r['description'],'unit'=>$r['unit'],'price'=>$r['price'],'qty'=>array_fill(0,12,0),'amounts'=>array_fill(0,12,0),'dv_ids'=>[],'codes'=>[],'legacy_dv_ids'=>[]];$l=&$g['rows'][$k];$l['codes'][$code]=true;$l['qty'][$m]=self::plus($l['qty'][$m],$r['qty']);$l['amounts'][$m]=self::plus($l['amounts'][$m],$r['amount']);$l['dv_ids'][$id]=true;if($itemized['legacy_match'])$l['legacy_dv_ids'][$id]=true;$g['quarters'][$q]=self::plus($g['quarters'][$q],$r['amount']);$g['amount']=self::plus($g['amount'],$r['amount']);$total=self::plus($total,$r['amount']);unset($l,$g);}
        }
        demand($eligible===$total+$unitemized+$nonproc,'Report gross reconciliation failed',409);
        demand(array_sum(array_column($accountSummary,'amount'))+$unclassified===$eligible-$nonproc,'Accounting summary reconciliation failed',409);
        // One heading per normalized accounting title. Preserve every underlying UACS code.
        ksort($accountSummary,SORT_STRING);
        foreach($accountSummary as $key=>&$summary){
            ksort($summary['codes'],SORT_STRING);
            $groups[$key]??=['title'=>$summary['title'],'codes'=>[],'rows'=>[],'quarters'=>array_fill(0,4,0),'amount'=>0];
            $g=&$groups[$key];$g['codes']=$summary['codes'];$g['reviewQuarters']=$summary['reviewQuarters'];$g['reviewAmount']=$summary['review'];$g['classifiedAmount']=$summary['amount'];$g['classifiedQuarters']=$summary['quarters'];$g['amountOnlyRows']=array_values($summary['amountOnlyRows']);
            foreach($g['amountOnlyRows'] as &$line)ksort($line['codes'],SORT_STRING);unset($line);
            demand(array_sum(array_map(static fn($r)=>array_sum($r['amounts']),$g['amountOnlyRows']))===$summary['review'],'Amount-only rows do not reconcile',409);
            demand($g['amount']===$summary['itemized'],'Accounting title item totals do not reconcile',409);
            $g['rows']=array_values($g['rows']);usort($g['rows'],static fn($a,$b)=>strcasecmp($a['description'],$b['description'])?:strcmp($a['unit'],$b['unit'])?:($a['price']<=>$b['price']));unset($g);
        }unset($summary);
        ksort($groups,SORT_STRING);
        return compact('groups','review','excluded','total','eligible','unitemized','nonproc','accountSummary','unclassified','legacyMatches');
    }
    private static function limited(PDO $db,string $sql,array $p,int $cap):array {$r=q($db,$sql.' LIMIT '.($cap+1),$p)->fetchAll(PDO::FETCH_ASSOC);demand(count($r)<=$cap,'Report exceeds capacity; shorten the date range.',409);return $r;}
    public static function read(PDO $db,array $user,int $org,int $fund,string $from,string $to):array {
        $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');self::dates($from,$to,$today);
        $office=FinanceAccess::read($db,$user,$org);$funds=q($db,'SELECT id,source_code,name,fiscal_year FROM funds WHERE organization_id=? ORDER BY fiscal_year DESC,source_code',[$org])->fetchAll(PDO::FETCH_ASSOC);
        $f=null;foreach($funds as $v)if((int)$v['id']===$fund)$f=$v;
        if(!$fund)return ['office'=>$office,'funds'=>$funds,'fund'=>null];
        demand($f!==null,'Select a fund belonging to the active organization.',403);
        $where=" d.organization_id=? AND d.fund_id=? AND d.status='FINALIZED' AND d.document_date BETWEEN ? AND ?";$p=[$org,$fund,$from,$to];
        $dvs=self::limited($db,"SELECT d.id,d.fund_id,d.dv_type,d.display_number,d.document_date,d.particulars,d.gross,d.net,d.deductions,EXISTS(SELECT 1 FROM school_tax_transfers t WHERE t.dv_id=d.id AND t.organization_id=d.organization_id) tax_transfer FROM disbursement_vouchers d WHERE $where ORDER BY d.document_date,d.id",$p,3000);
        $checks=self::limited($db,"SELECT c.id,c.dv_id,c.bank_account_id,c.check_number,c.status,c.amount,c.replaces_check_id,b.id bank_scope_id FROM checks c JOIN disbursement_vouchers d ON d.id=c.dv_id AND d.organization_id=c.organization_id LEFT JOIN bank_accounts b ON b.id=c.bank_account_id AND b.organization_id=d.organization_id WHERE $where ORDER BY c.id",$p,10000);
        $events=self::limited($db,"SELECT e.id,e.check_id,e.event_type FROM payment_events e JOIN checks c ON c.id=e.check_id JOIN disbursement_vouchers d ON d.id=c.dv_id AND d.organization_id=c.organization_id WHERE $where AND e.event_type IN ('ISSUED','RELEASED','REPLACED') ORDER BY e.id",$p,30000);
        $alloc=self::limited($db,"SELECT a.id allocation_id,a.dv_id,a.pr_id,a.iar_line_id,a.amount,a.accounting_uacs_code,il.accepted_qty,il.accepted_amount,i.status iar_status,po.status po_status,pr.status pr_status,pr.fund_id source_fund_id,pr.id source_pr_id,pr.document_date pr_date,pl.item_id,pl.pr_line_id,pl.unit_price,prl.description_snapshot,prl.unit_snapshot,(SELECT v.snapshot_json FROM document_versions v WHERE v.organization_id=d.organization_id AND v.doc_type='PR' AND v.entity_id=pr.id ORDER BY v.version_no DESC LIMIT 1) pr_snapshot,EXISTS(SELECT 1 FROM dv_allocation_lines a2 JOIN disbursement_vouchers d2 ON d2.id=a2.dv_id AND d2.organization_id=d.organization_id WHERE a2.iar_line_id=a.iar_line_id AND a2.dv_id<>d.id AND d2.status='FINALIZED') other_finalized_dv FROM dv_allocation_lines a JOIN disbursement_vouchers d ON d.id=a.dv_id LEFT JOIN iar_lines il ON il.id=a.iar_line_id LEFT JOIN iars i ON i.id=il.iar_id AND i.organization_id=d.organization_id LEFT JOIN purchase_orders po ON po.id=i.po_id AND po.organization_id=d.organization_id LEFT JOIN po_lines pl ON pl.id=il.po_line_id AND pl.po_id=po.id LEFT JOIN purchase_requests pr ON pr.id=po.pr_id AND pr.organization_id=d.organization_id LEFT JOIN pr_lines prl ON prl.id=pl.pr_line_id AND prl.pr_id=pr.id AND prl.item_id=pl.item_id WHERE $where ORDER BY a.id",$p,15000);
        $journal=self::limited($db,"SELECT d.id dv_id,l.uacs_code,l.debit,l.credit,u.account_name FROM disbursement_vouchers d JOIN dv_accounting_versions v ON v.dv_id=d.id AND v.version_no=(SELECT MAX(v2.version_no) FROM dv_accounting_versions v2 WHERE v2.dv_id=d.id) JOIN voucher_accounting_lines l ON l.accounting_version_id=v.id LEFT JOIN uacs_accounts u ON u.code=l.uacs_code WHERE $where ORDER BY d.id,l.id",$p,20000);
        return ['office'=>$office,'funds'=>$funds,'fund'=>$f]+self::project($dvs,$checks,$events,$alloc,$journal);
    }
}
