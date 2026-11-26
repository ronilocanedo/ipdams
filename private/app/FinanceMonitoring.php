<?php
declare(strict_types=1);
require_once __DIR__.'/FinanceAccess.php';
require_once __DIR__.'/CashFundReports.php';
require_once __DIR__.'/FinancialClosePolicy.php';
require_once __DIR__.'/FinancialAllocation.php';
require_once __DIR__.'/FundUtilizationSummary.php';

/** Read-only utilization of explicitly attributed downloads. School membership
 * and active parent membership are checked independently on every request.
 * A credit is never assigned to an old payment by date, proportion or FIFO. */
final class FinanceMonitoring {
    public function __construct(private PDO $db,private array $user) {}
    private static function dateRange(array $p):array {
        $from=requiredString($p,'from',10);$to=requiredString($p,'to',10);
        demand(FinancialClosePolicy::day($from)&&FinancialClosePolicy::day($to)&&$from<=$to,'Invalid monitoring date range',422);
        $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        demand($to<=$today,'Future utilization cutoff is unavailable',422);
        return [$from,$to,(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d').' 00:00:00'];
    }
    private function organizations(int $org):array {
        $root=FinanceAccess::read($this->db,$this->user,$org);
        if($root['kind']!=='DIVISION')return [$root];
        demand(in_array($this->user['role'],['DIVISION','SUPER_ADMIN'],true),'Division monitoring is restricted',403);
        $rows=$this->user['role']==='SUPER_ADMIN'
            ?q($this->db,"SELECT o.* FROM organizations o WHERE o.kind='SCHOOL' AND o.active=1 AND o.parent_division_id=? ORDER BY o.name,o.id",[$org])->fetchAll()
            :q($this->db,"SELECT o.* FROM organizations o JOIN user_organization_scopes s ON s.organization_id=o.id AND s.user_id=? WHERE o.kind='SCHOOL' AND o.active=1 AND o.parent_division_id=? ORDER BY o.name,o.id",[$this->user['id'],$org])->fetchAll();
        foreach($rows as $row)FinanceAccess::read($this->db,$this->user,(int)$row['id']);
        return $rows;
    }
    private function metadata(array $org,string $to):array {
        $assignment=one($this->db,"SELECT id,config_json,effective_from FROM configuration_versions WHERE organization_id=? AND config_key='SCHOOL_DIVISION_MOOE_POSTING_OFFICER_V1' AND effective_from<=? ORDER BY effective_from DESC,id DESC LIMIT 1",[$org['id'],$to]);
        $officer=$assignment?json_decode((string)$assignment['config_json'],true,32,JSON_THROW_ON_ERROR):[];
        if((int)($officer['division_id']??0)!==(int)($org['parent_division_id']??0))$officer=[];
        $versions=q($this->db,"SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key='SCHOOL_IMPLEMENTATION_CLASS' AND effective_from<=? ORDER BY effective_from DESC,id DESC",[$org['id'],$to])->fetchAll();
        return ['id'=>(int)$org['id'],'name'=>$org['name'],'code'=>$org['code'],'classification'=>$org['kind']==='DIVISION'?'DIVISION':Appendix43Register::classification($versions),
            'mooe_employee_id'=>(int)($officer['employee_id']??0),'mooe_in_charge'=>(string)($officer['name']??$officer['full_name']??''),'mooe_assignment_version'=>$officer?(int)$assignment['id']:null,'metadata_as_of'=>$to];
    }
    /** A reviewed attribution or accounting carry-forward does not create a new
     * payment date. Follow its immutable predecessor and original payment event.
     * The current accounting classification may be a later reconstruction. */
    private function cashEvents(array $set,string $boundary,array $seen=[]):array {
        $id=(int)$set['id'];demand(!isset($seen[$id])&&count($seen)<100,'Allocation cash lineage is incomplete or cyclic',409);$seen[$id]=true;
        $events=q($this->db,'SELECT e.*,c.check_number,p.created_at AS payment_occurred_at FROM finance_allocation_events e LEFT JOIN checks c ON c.id=e.check_id AND c.organization_id=? LEFT JOIN payment_events p ON p.id=e.payment_event_id AND p.check_id=e.check_id WHERE e.allocation_set_id=? AND e.created_at<? ORDER BY e.created_at,e.id',[$set['organization_id'],$id,$boundary])->fetchAll();$out=[];
        foreach($events as $event){
            if($event['event_type']==='CASH_CARRY_FORWARD'&&(int)($set['predecessor_id']??0)>0){
                $prior=one($this->db,'SELECT * FROM finance_allocation_sets WHERE id=? AND organization_id=? AND dv_id=?',[$set['predecessor_id'],$set['organization_id'],$set['dv_id']]);
                demand($prior!==null,'Cash carry-forward predecessor is missing',409);$priorCash=$this->cashEvents($prior,$boundary,$seen);$total=0;
                foreach($priorCash as $old)if((int)$old['cash_delta_cents']!==0){$total+=(int)$old['cash_delta_cents'];$out[]=$old+['carried_by_allocation_event_id'=>(int)$event['id']];}
                demand($total===(int)$event['cash_delta_cents'],'Carried cash does not equal preserved payment history',409);
            }else{$event['cash_effective_at']=$event['payment_occurred_at']??$event['created_at'];$out[]=$event;}
        }return $out;
    }
    /** Returns exact source amounts for the latest explicit DV allocation as of
     * cutoff. Current mutable set.state is deliberately not historical evidence.
     * Whole-set cash events can be decomposed into explicit download net shares
     * only when that event is exactly the saved set net, never proportionally. */
    public function read(array $p):array {
        [$from,$to,$boundary]=self::dateRange($p);$org=idOf($p,'organization_id');
        $organizations=$this->organizations($org);$allSchools=[];$schools=[];
        $class=(string)($p['classification']??'');demand(in_array($class,['','IUS','NON_IUS','UNKNOWN'],true),'Invalid implementation classification',422);
        $schoolFilter=!empty($p['school_id'])?idOf($p,'school_id'):0;
        if($schoolFilter)demand(in_array($schoolFilter,array_map(static fn($r)=>(int)$r['id'],$organizations),true),'School is outside authorized monitoring scope',403);
        foreach($organizations as $o){$m=$this->metadata($o,$to);$allSchools[]=$m;
            if($schoolFilter&&(int)$o['id']!==$schoolFilter)continue;
            if($class!==''&&$m['classification']!==$class)continue;
            if(!empty($p['mooe_employee_id'])&&$m['mooe_employee_id']!==idOf($p,'mooe_employee_id'))continue;
            $schools[(int)$o['id']]=$m;
        }
        foreach(['fund_id'=>['funds','id'],'bank_account_id'=>['bank_accounts','id'],'download_id'=>['fund_events','id']] as $key=>[$table,$column])if(!empty($p[$key])){
            $found=false;$id=idOf($p,$key);
            foreach($organizations as $authorized)if(one($this->db,'SELECT '.$column.' FROM '.$table.' WHERE '.$column.'=? AND organization_id=?',[$id,$authorized['id']])!==null){$found=true;break;}
            demand($found,'Report filter is outside authorized school finance scope',403);
        }
        $rows=[];$options=['schools'=>$allSchools,'funds'=>[],'banks'=>[],'officers'=>[],'downloads'=>[]];$unmapped=[];$fundSummary=[];
        foreach($allSchools as $s)if($s['mooe_employee_id'])$options['officers'][$s['mooe_employee_id']]=['id'=>$s['mooe_employee_id'],'name'=>$s['mooe_in_charge']];
        foreach($schools as $school=>$m){
            $schoolFunds=q($this->db,'SELECT id,name,source_code,fiscal_year FROM funds WHERE organization_id=? ORDER BY source_code,id',[$school])->fetchAll();
            foreach($schoolFunds as $f)$options['funds'][]=$f+['organization_id'=>$school,'school_name'=>$m['name']];
            $schoolBanks=q($this->db,'SELECT id,bank_name,account_label,masked_account FROM bank_accounts WHERE organization_id=? ORDER BY id',[$school])->fetchAll();
            foreach($schoolBanks as $b)$options['banks'][]=$b+['organization_id'=>$school,'school_name'=>$m['name']];
            if(empty($p['download_id']))foreach(FundUtilizationSummary::read($this->db,$school,$from,$to,$p,$schoolFunds,$schoolBanks) as $summary)$fundSummary[]=$summary+['school'=>$m,'organization_id'=>$school];
            $args=[$school,$from,$to];$extra='';
            foreach(['fund_id','bank_account_id','download_id'] as $key)if(!empty($p[$key])){$extra.=' AND e.'.($key==='download_id'?'id':$key).'=?';$args[]=idOf($p,$key);}
            if(trim((string)($p['source_code']??''))!==''){$extra.=' AND f.source_code=?';$args[]=requiredString($p,'source_code',40);}
            $credits=q($this->db,"SELECT e.*,f.name AS fund_name,f.source_code,f.fiscal_year,b.bank_name,b.account_label,b.masked_account FROM fund_events e JOIN funds f ON f.id=e.fund_id AND f.organization_id=e.organization_id LEFT JOIN bank_accounts b ON b.id=e.bank_account_id AND b.organization_id=e.organization_id WHERE e.organization_id=? AND e.event_type='ALLOTMENT' AND e.bank_credit_date BETWEEN ? AND ?".$extra.' ORDER BY e.bank_credit_date,e.id LIMIT 5001',$args)->fetchAll();
            demand(count($credits)<=5000,'Narrow monitoring filters',409);
            $sets=q($this->db,'SELECT s.*,d.display_number,d.document_date,d.dv_type,d.payee FROM finance_allocation_sets s JOIN disbursement_vouchers d ON d.id=s.dv_id AND d.organization_id=s.organization_id WHERE s.organization_id=? AND s.created_at<? AND NOT EXISTS(SELECT 1 FROM finance_allocation_sets n WHERE n.dv_id=s.dv_id AND n.organization_id=s.organization_id AND n.created_at<? AND (n.created_at>s.created_at OR (n.created_at=s.created_at AND n.id>s.id))) ORDER BY s.id LIMIT 10001',[$school,$boundary,$boundary])->fetchAll();
            demand(count($sets)<=10000,'Narrow utilization cutoff',409);$byDownload=[];
            foreach($sets as $set){
                $events=$this->cashEvents($set,$boundary);
                // Cancellation/reversal is documented by its dated event, not the
                // present state field. REPLACED is a check event, not a DV release.
                $reversed=(bool)array_filter($events,static fn($e)=>in_array($e['event_type'],['ALLOCATION_REVERSED','RESERVATION_REVERSED','DV_CANCELLED'],true));
                if($reversed)continue;
                $lines=q($this->db,'SELECT l.*,u.account_name,w.atc_code,a.tax_kind FROM finance_allocation_lines l LEFT JOIN uacs_accounts u ON u.code=l.uacs_code LEFT JOIN withholding_components w ON l.kind=\'TAX\' AND w.id=l.source_id LEFT JOIN tax_atcs a ON a.code=w.atc_code WHERE l.allocation_set_id=? ORDER BY l.id',[$set['id']])->fetchAll();
                $parts=[];foreach($lines as $l){$did=(int)$l['download_id'];$parts[$did]??=['gross_cents'=>0,'tax_cents'=>0,'retained_cents'=>0,'remittable_cents'=>0,'uacs'=>[],'tax'=>[]];$n=(int)$l['cents'];
                    if($l['kind']==='GROSS'){$parts[$did]['gross_cents']+=$n;$parts[$did]['uacs'][]=$l;}
                    else {$parts[$did]['tax_cents']+=$n;$parts[$did][$l['tax_disposition']==='SCHOOL_UTILIZATION'?'retained_cents':'remittable_cents']+=$n;$parts[$did]['tax'][]=$l;}}
                foreach($parts as $did=>$part){$net=$part['gross_cents']-$part['tax_cents'];$cash=0;$complete=true;$cashRows=[];
                    foreach($events as $e){$delta=(int)$e['cash_delta_cents'];if(!$delta)continue;
                        if(abs($delta)!==(int)$set['net_cents']){$complete=false;continue;}
                        $share=$delta<0?-$net:$net;$cash+=$share;$cashRows[]=$e+['download_cash_delta_cents'=>$share];
                    }
                    if($cash<0||$cash>$net)$complete=false;
                    $relief=0;$budgetComplete=true;
                    try{$relief=FinancialAllocation::remittanceRelief($this->db,(int)$set['dv_id'],$did);}
                    catch(DomainError $e){$budgetComplete=false;}
                    if($relief>0){
                        // The operational helper validates current paid sources.
                        // Historical monitoring additionally requires that source
                        // attribution/payment already existed at this cutoff.
                        $origins=q($this->db,"SELECT DISTINCT s.id,s.created_at,s.net_cents FROM school_tax_transfers t JOIN school_tax_transfer_components tc ON tc.transfer_id=t.id JOIN finance_allocation_lines l ON l.kind='TAX' AND l.source_id=tc.component_id AND l.tax_disposition='REMIT' JOIN finance_allocation_sets s ON s.id=l.allocation_set_id AND s.active_dv_id IS NOT NULL WHERE t.dv_id=? AND l.download_id=?",[$set['dv_id'],$did])->fetchAll();
                        foreach($origins as $origin){$paid=(int)one($this->db,'SELECT COALESCE(SUM(cash_delta_cents),0) n FROM finance_allocation_events WHERE allocation_set_id=? AND created_at<?',[$origin['id'],$boundary])['n'];if($origin['created_at']>=$boundary||$paid!==(int)$origin['net_cents'])$budgetComplete=false;}
                    }
                    $byDownload[$did][]= $part+['allocation_set_id'=>(int)$set['id'],'dv_id'=>(int)$set['dv_id'],'dv_number'=>$set['display_number'],'dv_type'=>$set['dv_type'],'document_date'=>$set['document_date'],'payee'=>$set['payee'],'accounting_version_id'=>(int)$set['accounting_version_id'],'net_cents'=>$net,'released_cash_cents'=>$complete?$cash:null,'cash_evidence_complete'=>$complete,'cash_events'=>$cashRows,'created_at'=>$set['created_at'],'remittance_relief_cents'=>$budgetComplete?$relief:0,'budget_evidence_complete'=>$budgetComplete];
                }
            }
            foreach($credits as $credit){$id=(int)$credit['id'];$alloc=$byDownload[$id]??[];$gross=$tax=$retained=$remit=$cash=$reservation=$relief=0;$cashComplete=$budgetComplete=true;
                foreach($alloc as $a){$gross+=$a['gross_cents'];$tax+=$a['tax_cents'];$retained+=$a['retained_cents'];$remit+=$a['remittable_cents'];$relief+=$a['remittance_relief_cents'];if(!$a['budget_evidence_complete'])$budgetComplete=false;if(!$a['cash_evidence_complete'])$cashComplete=false;else{$cash+=$a['released_cash_cents'];$reservation+=$a['net_cents']-$a['released_cash_cents'];}}
                $amount=moneyCents((string)$credit['amount']);$evidence=FundUtilizationSummary::verified($credit);
                $rows[]=$credit+['school'=>$m,'credit_cents'=>$amount,'evidence_complete'=>$evidence,'gross_committed_cents'=>$gross,'budget_authority_used_cents'=>$budgetComplete?$gross-$retained-$relief:null,'remittance_relief_cents'=>$relief,'budget_evidence_complete'=>$budgetComplete,'withholding_cents'=>$tax,'retained_cents'=>$retained,'remittable_cents'=>$remit,'net_committed_cents'=>$gross-$tax,'reserved_cash_cents'=>$cashComplete?$reservation:null,'released_cash_cents'=>$cashComplete?$cash:null,'remaining_cash_cents'=>$cashComplete?$amount-$cash:null,'cash_capacity_after_reservations_cents'=>$cashComplete?$amount-$cash-$reservation:null,'remaining_gross_authority_cents'=>$budgetComplete?$amount-$gross+$retained+$relief:null,'cash_evidence_complete'=>$cashComplete,'allocations'=>$alloc,'attribution_status'=>$alloc?'EXPLICIT_VERIFIED_SOURCES':'UNATTRIBUTED'];
                $options['downloads'][]=['id'=>$id,'organization_id'=>$school,'school_name'=>$m['name'],'reference'=>$credit['bank_credit_ref'],'date'=>$credit['bank_credit_date']];
            }
            // A visible separate exception bucket; never subtract or allocate it
            // against a particular download without an explicit reviewed record.
            $missing=q($this->db,"SELECT d.id,d.display_number,d.document_date,d.dv_type,d.gross,d.net,d.fund_id FROM disbursement_vouchers d WHERE d.organization_id=? AND d.status='FINALIZED' AND d.document_date<=? AND NOT EXISTS(SELECT 1 FROM finance_allocation_sets s WHERE s.dv_id=d.id AND s.organization_id=d.organization_id AND s.created_at<?) ORDER BY d.id LIMIT 10001",[$school,$to,$boundary])->fetchAll();
            demand(count($missing)<=10000,'Narrow unmapped payment range',409);
            $selectedCredit=!empty($p['download_id'])?($credits[0]??null):null;
            foreach($missing as $u){
                if(!empty($p['download_id'])&&(!$selectedCredit||(int)$u['fund_id']!==(int)$selectedCredit['fund_id']))continue;
                if(!empty($p['fund_id'])&&(int)$u['fund_id']!==idOf($p,'fund_id'))continue;
                if(trim((string)($p['source_code']??''))!==''){
                    $fund=one($this->db,'SELECT source_code FROM funds WHERE id=? AND organization_id=?',[$u['fund_id'],$school]);
                    if(($fund['source_code']??'')!==(string)$p['source_code'])continue;
                }
                $bankIds=array_map('intval',array_column(q($this->db,'SELECT DISTINCT bank_account_id FROM checks WHERE dv_id=? AND organization_id=? AND created_at<? ORDER BY bank_account_id',[$u['id'],$school,$boundary])->fetchAll(),'bank_account_id'));
                if(!empty($p['bank_account_id'])&&$bankIds&&!in_array(idOf($p,'bank_account_id'),$bankIds,true))continue;
                if($selectedCredit&&$bankIds&&!in_array((int)$selectedCredit['bank_account_id'],$bankIds,true))continue;
                $unmapped[]=$u+['organization_id'=>$school,'school_name'=>$m['name'],'bank_ids'=>$bankIds,'can_review'=>$this->user['role']==='SUPER_ADMIN'||($this->user['role']==='SCHOOL'&&$m['classification']!=='DIVISION'),'attribution_status'=>'UNMAPPED_NOT_ASSIGNED'];
            }
        }
        $unmappedScopes=[];foreach($unmapped as $u){$key=$u['organization_id'].':'.$u['fund_id'].':';foreach($u['bank_ids']?:[0] as $bid)$unmappedScopes[$key.$bid]=true;}
        foreach($rows as &$row){$key=$row['organization_id'].':'.$row['fund_id'].':';$row['attribution_complete']=!isset($unmappedScopes[$key.'0'])&&!isset($unmappedScopes[$key.$row['bank_account_id']]);
            if(!$row['attribution_complete']||!$row['evidence_complete'])foreach(['remaining_cash_cents','cash_capacity_after_reservations_cents','remaining_gross_authority_cents'] as $key)$row[$key]=null;
        }unset($row);
        usort($rows,static fn($a,$b)=>strcmp($a['school']['name'],$b['school']['name'])?:strcmp($a['bank_credit_date'],$b['bank_credit_date'])?:((int)$a['id']<=>(int)$b['id']));
        $totals=['verified_download_cents'=>0,'gross_committed_cents'=>0,'budget_authority_used_cents'=>0,'remittance_relief_cents'=>0,'budget_evidence_complete'=>true,'net_committed_cents'=>0,'withholding_cents'=>0,'retained_cents'=>0,'remittable_cents'=>0,'released_cash_cents'=>0,'reserved_cash_cents'=>0,'remaining_cash_cents'=>0,'cash_capacity_after_reservations_cents'=>0,'remaining_gross_authority_cents'=>0,'evidence_gaps'=>0,'unmapped_dv_count'=>count($unmapped),'cash_evidence_complete'=>true];
        foreach($rows as $r){if($r['evidence_complete'])$totals['verified_download_cents']+=$r['credit_cents'];else $totals['evidence_gaps']++;
            foreach(['gross_committed_cents','budget_authority_used_cents','remittance_relief_cents','net_committed_cents','withholding_cents','retained_cents','remittable_cents','remaining_gross_authority_cents'] as $k)$totals[$k]+=$r[$k];
            if(!$r['budget_evidence_complete'])$totals['budget_evidence_complete']=false;
            if(!$r['cash_evidence_complete'])$totals['cash_evidence_complete']=false;else foreach(['released_cash_cents','reserved_cash_cents','remaining_cash_cents','cash_capacity_after_reservations_cents'] as $k)$totals[$k]+=$r[$k];}
        if(!$totals['cash_evidence_complete'])foreach(['released_cash_cents','reserved_cash_cents','remaining_cash_cents','cash_capacity_after_reservations_cents'] as $k)$totals[$k]=null;
        if(!$totals['budget_evidence_complete'])foreach(['budget_authority_used_cents','remaining_gross_authority_cents'] as $k)$totals[$k]=null;
        $totals['attribution_complete']=!array_filter($rows,static fn($r)=>!$r['attribution_complete']||!$r['evidence_complete']);
        if(!$totals['attribution_complete'])foreach(['remaining_cash_cents','cash_capacity_after_reservations_cents','remaining_gross_authority_cents'] as $key)$totals[$key]=null;
        $options['officers']=array_values($options['officers']);
        return ['organization_id'=>$org,'from'=>$from,'to'=>$to,'rows'=>$rows,'fund_summary'=>$fundSummary,'totals'=>$totals,'unmapped'=>$unmapped,'options'=>$options,'read_only'=>true,'certified'=>false,'note'=>'Individual-download figures reflect explicit attribution only. Credit dates select downloads; allocation events are read through cutoff. Fund summary separately uses bank-linked credits and recorded releases, including historical payments. No historical payment is guessed into a download.'];
    }
    /** Existing Appendix 43 renderer receives a register built solely from the
     * selected download's saved gross/tax lines and dated cash events. */
    public function downloadReport(array $p):array {
        $org=idOf($p,'organization_id');$organization=FinanceAccess::read($this->db,$this->user,$org);$download=idOf($p,'download_id');
        $period=requiredString($p,'period',7);demand(preg_match('/^20\d\d-(0[1-9]|1[0-2])$/D',$period)===1,'Invalid report month',422);
        $from=$period.'-01';$end=(new DateTimeImmutable($from))->format('Y-m-t');$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');$to=min($end,$today);
        demand($from<=$today,'Future report month unavailable',422);
        // Live per-download historical printing is an explicitly labelled
        // reconstruction from presently reviewed allocations. Captured reports
        // must use the immutable snapshot endpoint instead.
        $report=$this->read(['organization_id'=>$org,'download_id'=>$download,'from'=>'2000-01-01','to'=>$today]);
        demand(count($report['rows'])===1,'Download is unavailable in this organization and cutoff',404);$credit=$report['rows'][0];
        demand($credit['bank_credit_date']<=$to,'Download credit is after the selected report period',404);
        demand($credit['evidence_complete']&&$credit['cash_evidence_complete']&&$credit['attribution_complete'],'Download source/cash evidence is incomplete',409);
        if(!empty($p['fund_id']))demand(idOf($p,'fund_id')===(int)$credit['fund_id'],'Download fund mismatch',422);
        if(!empty($p['bank_account_id']))demand(idOf($p,'bank_account_id')===(int)$credit['bank_account_id'],'Download bank mismatch',422);
        $fund=one($this->db,'SELECT id,name,source_code,fiscal_year FROM funds WHERE id=? AND organization_id=?',[$credit['fund_id'],$org]);
        $bank=one($this->db,'SELECT id,bank_name,account_label,masked_account FROM bank_accounts WHERE id=? AND organization_id=?',[$credit['bank_account_id'],$org]);
        $meta=Appendix43Register::metadata($this->db,$organization,$to);$class=$meta['classification'];
        $opening=$credit['bank_credit_date']<$from?$credit['credit_cents']:0;$creditPeriod=$credit['bank_credit_date']>=$from?$credit['credit_cents']:0;$moves=[];$notes=[];$names=[];$grossByCode=[];$frequency=[];$negativeRetained=$negativeRemit=$totalVat=$totalEwt=$payment=0;$cashCodes=[];
        if($creditPeriod)$moves[]=['date'=>$credit['bank_credit_date'],'sort'=>0,'id'=>(int)$credit['id'],'particulars'=>'FUND DOWNLOAD #'.$download.' — '.$credit['bank_credit_ref'],'receipt'=>$creditPeriod,'paid'=>0,'parts'=>[],'vat'=>0,'ewt'=>0,'due'=>0,'dv'=>'','check'=>'','documentDate'=>''];
        foreach($credit['allocations'] as $allocation){
            $cash=q($this->db,"SELECT uacs_code,credit FROM voucher_accounting_lines WHERE accounting_version_id=? AND credit>0 AND uacs_code<>'2020101000' ORDER BY id",[$allocation['accounting_version_id']])->fetchAll();
            foreach($cash as $line)$cashCodes[(string)$line['uacs_code']]=true;
            foreach($allocation['cash_events'] as $event){$day=substr((string)($event['cash_effective_at']??$event['created_at']),0,10);$paid=(int)$event['download_cash_delta_cents'];if($day>$to)continue;
                if($day<$from){$opening-=$paid;continue;}
                $sign=$paid<0?-1:1;$parts=[];$vat=$ewt=0;
                foreach($allocation['uacs'] as $line){$code=(string)$line['uacs_code'];$parts[$code]=($parts[$code]??0)+$sign*(int)$line['cents'];$names[$code]=(string)($line['account_name']??'');$grossByCode[$code]=($grossByCode[$code]??0)+$sign*(int)$line['cents'];$frequency[$code]=($frequency[$code]??0)+1;}
                foreach($allocation['tax'] as $line){$amount=$sign*(int)$line['cents'];if($line['tax_kind']==='VAT')$vat+=$amount;elseif($line['tax_kind']==='EWT')$ewt+=$amount;else $notes[]='An explicitly allocated tax source has an unclassified tax kind; verify saved source '.$line['source_id'].'.';}
                $negativeRetained+=$sign*$allocation['retained_cents'];$negativeRemit+=$sign*$allocation['remittable_cents'];$totalVat+=$vat;$totalEwt+=$ewt;$payment+=$paid;
                $moves[]=['date'=>$day,'sort'=>1,'id'=>(int)$event['id'],'documentDate'=>$allocation['document_date'],'particulars'=>($paid<0?'EVIDENCED CASH REVERSAL — ':'').$allocation['payee'],'receipt'=>0,'paid'=>$paid,'parts'=>$parts,'vat'=>$vat,'ewt'=>$ewt,'due'=>$vat+$ewt,'dv'=>$allocation['dv_number'],'check'=>$event['check_number']??''];
            }
        }
        usort($moves,static fn($a,$b)=>strcmp($a['date'],$b['date'])?:($a['sort']<=>$b['sort'])?:($a['id']<=>$b['id']));$running=$opening;
        foreach($moves as &$move){$running+=$move['receipt']-$move['paid'];$move['balance']=$running;}unset($move);
        $fixed=array_keys($grossByCode);usort($fixed,static fn($a,$b)=>($grossByCode[$b]<=>$grossByCode[$a])?:($frequency[$b]<=>$frequency[$a])?:strcmp((string)$a,(string)$b));$fixed=array_pad(array_slice($fixed,0,4),4,'');
        $cashCode=count($cashCodes)===1?(string)array_key_first($cashCodes):'';$label='Cash account — source review required';$receiptLabel='Receipts';
        if($class==='NON_IUS'&&$cashCode==='1990101000'){$label='Advances for Operating Expenses';$receiptLabel='Cash Advance';}
        elseif(in_array($class,['IUS','DIVISION'],true)&&preg_match('/^101(?:02|04)\d{5}$/D',$cashCode)){$name=one($this->db,'SELECT account_name FROM uacs_accounts WHERE code=?',[$cashCode]);$label=$name['account_name']??'Cash in Bank';}
        elseif(!$credit['allocations']){$cashCode='';$notes[]='No allocated payment has been recorded; the cash account classification has no payment accounting source.';}
        else $notes[]='Implementation classification and saved cash/operating-advance credit require review.';
        if($class!=='NON_IUS')$notes[]='IU/Division variant remains an internal monitoring register, not official Appendix 43.';
        if(empty($meta['head']))$notes[]='Configured accountable officer is incomplete; no signatory is inferred.';
        $notes[]='Download #'.$download.' includes only explicit verified allocation sources. Gross budget authority, reservations, cash payments and retained/remittable withholding are distinct. Historical unmapped payments are excluded and remain exceptions in monitoring.';
        $recap=array_sum($grossByCode)-$negativeRetained-$negativeRemit;$difference=$payment-$recap;
        demand($difference===0,'Explicit allocation register does not reconcile to its cash events',409);
        $a=compact('class','meta','label','cashCode','receiptLabel','fixed','names','moves','grossByCode','negativeRetained','negativeRemit','totalVat','totalEwt','recap','difference','notes')+['unclassified'=>0,'reconciled'=>true,'certified'=>false];
        $d=['fund'=>$fund,'bank'=>$bank,'organization'=>$organization,'from'=>$from,'end'=>$end,'prev'=>(new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d'),'opening'=>$opening,'credit'=>$creditPeriod,'payment'=>$payment,'closing'=>$running,'appendix43'=>$a,'warnings'=>$notes,'reviewItems'=>[],'download_id'=>$download,
            'report_basis_note'=>'Individual download #'.$download.' register reconstructed from presently reviewed explicit gross UACS/tax allocations and original dated payment events. Later attribution or accounting revision does not create a new cash payment date. Dates display saved DV document dates. Retained and remittable withholding reduce gross to cash; neither creates a second bank deposit. Unmapped historical payments remain excluded and require separate evidence. This read-only reconstruction is not a certification or genuine captured-at-close snapshot.'];
        foreach($notes as $note)$d['reviewItems'][]=['code'=>'explicit_download_basis','message'=>$note,'reports'=>['A43'],'kind'=>'context'];
        if($end>=$today)$d['reviewItems'][]=['code'=>'period_open','message'=>'The reporting month is still in progress.','reports'=>['A43'],'kind'=>'provisional'];
        return $d;
    }
}
