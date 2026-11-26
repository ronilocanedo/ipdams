<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/FinancialClosePolicy.php';
require_once __DIR__.'/CashFundReports.php';
require_once __DIR__.'/FinanceAccess.php';
require_once __DIR__.'/BankReconciliation.php';
require_once __DIR__.'/FinancialSnapshots.php';

/** Development review workpapers. Never creates funding, payment or certification.
 * Existing configuration_versions provides append-only storage; no ledger DDL.
 * Historical reconstruction is always incomplete until all writers participate
 * in an audited cutoff protocol. It must never become a spending authority.
 */
final class FinancialReview {
    public function __construct(private PDO $db,private array $user) {}
    private function authorize(int $org,bool $write=false):array {
        $o=FinanceAccess::read($this->db,$this->user,$org);
        if($write) {
            allowedToWrite($this->user);
            demand($this->user['role']==='SUPER_ADMIN'||($this->user['role']==='SCHOOL'&&$o['kind']==='SCHOOL')||($this->user['role']==='DIVISION'&&$o['kind']==='DIVISION'),'School finance is read-only for Division users',403);
        }
        return $o;
    }
    private function bank(int $org,int $bank,bool $lock=false):array {
        $row=one($this->db,'SELECT * FROM bank_accounts WHERE id=? AND organization_id=?'.($lock?' FOR UPDATE':''),[$bank,$org]);
        demand($row!==null,'Bank does not belong to workspace',403);return $row;
    }
    private static function period(array $d):string {
        $p=requiredString($d,'period',7);
        demand(preg_match('/^20\d\d-(0[1-9]|1[0-2])$/D',$p)===1,'Period must be YYYY-MM');return $p;
    }
    private static function signed(mixed $v):int {
        demand(is_string($v),'Use an exact decimal monetary string');
        return str_starts_with($v,'-')?-moneyCents(substr($v,1)):moneyCents($v);
    }
    private function latest(int $org,string $key,bool $lock=false):?array {
        return one($this->db,'SELECT id,config_json,created_at FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1'.($lock?' FOR UPDATE':''),[$org,$key]);
    }
    private function append(int $org,string $key,array $body,string $reason):int {
        q($this->db,'INSERT INTO configuration_versions(organization_id,config_key,config_json,effective_from,review_note) VALUES (?,?,?,?,?)',[$org,$key,json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),date('Y-m-d'),$reason]);return (int)$this->db->lastInsertId();
    }
    private function audit(int $org,string $event,int $id,array $body):void {
        q($this->db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',[$org,$this->user['id'],$event,'FINANCE_REVIEW',$id,json_encode($body,JSON_THROW_ON_ERROR)]);
    }
    public function read(array $d):array {return (new BankReconciliation($this->db,$this->user))->read($d);}
    public function opening(array $d):array {return (new BankReconciliation($this->db,$this->user))->opening($d);}
    public function source(array $d):array {return (new BankReconciliation($this->db,$this->user))->source($d);}
    public function close(array $d):array {return (new BankReconciliation($this->db,$this->user))->close($d);}
    public function reopen(array $d):array {return (new BankReconciliation($this->db,$this->user))->reopen($d);}
    /** All draft writers serialize on bank before reading the version guard.
     * Called within the API's idempotent transaction; no nested commits.
     */
    public function saveDraft(array $d):array {
        if($this->latest(idOf($d,'organization_id'),'FR456_BANK_BASE_'.idOf($d,'bank_account_id'))!==null)return (new BankReconciliation($this->db,$this->user))->saveDraft($d);
        demand(FinancialCutoff::isCoordinated($this->db),'Draft save requires a coordinated READ COMMITTED transaction',409);
        $org=idOf($d,'organization_id');$this->authorize($org,true);FinancialCutoff::beginWriter($this->db,$this->user,'finance.review.draft',$d);$bank=idOf($d,'bank_account_id');$this->bank($org,$bank,true);$p=self::period($d);
        demand(($d['close']??false)===false,'Closure blocked: authoritative ledger completeness and coordinated cutoff not yet verified',409);
        demand(($d['reopen']??false)===false,'Reopening legacy closed books requires a separately reviewed dependency protocol',409);
        $expected=$d['expected_version']??null;demand(is_int($expected)&&$expected>=0,'Refresh draft version before saving',409);
        $key='FR456_BANK_'.$bank.'_'.$p;$prior=$this->latest($org,$key,true);
        demand((int)($prior['id']??0)===$expected,'Draft changed in another session. Reload before saving.',409);
        $statement=self::signed($d['statement_balance']??null);$book=self::signed($d['book_balance']??null);
        $evidence=requiredString($d,'statement_evidence',255);$bookEvidence=requiredString($d,'book_evidence',255);
        $reason=requiredString($d,'reason',500);$lines=$d['lines']??[];demand(is_array($lines)&&count($lines)<=500,'Invalid adjustment list');
        // Only bank-side outstanding checks can presently be resolved to an
        // authoritative source identity. No client-generated plug identifiers.
        $end=(new DateTimeImmutable($p.'-01'))->format('Y-m-t');$verified=[];
        foreach($lines as $l) {
            demand(is_array($l)&&($l['kind']??'')==='OUTSTANDING_CHECK'&&($l['side']??'')==='BANK','Unsupported adjustment source; no charges, credits or corrections are posted by this workpaper',409);
            $id=idOf($l,'check_id');
            $check=one($this->db,'SELECT c.id,c.amount,c.created_at,c.replaces_check_id FROM checks c JOIN disbursement_vouchers d ON d.id=c.dv_id AND d.organization_id=c.organization_id WHERE c.id=? AND c.organization_id=? AND c.bank_account_id=?',[$id,$org,$bank]);
            demand($check!==null && substr($check['created_at'],0,10)<=$end && !(int)$check['replaces_check_id'],'Check source is missing, future, foreign or replaced',409);
            $events=q($this->db,'SELECT * FROM payment_events WHERE check_id=? ORDER BY created_at,id',[$id])->fetchAll();
            demand(count($events)>0,'Check issue history missing',409);
            demand((bool)array_filter($events,static fn($e)=>$e['event_type']==='ISSUED'&&substr((string)$e['created_at'],0,10)<=$end),'Dated check issue evidence missing at cutoff',409);
            $stage=CashFundReports::stageAt($check,$events,$end);
            demand(in_array($stage,['ISSUED','SIGNED','RELEASED'],true),'Check is not a verified outstanding item at cutoff',409);
            demand(!array_filter($events,static fn($e)=>in_array($e['event_type'],['REPLACED','CANCELLED','STOPPED','STALE'],true)),'Check correction history requires explicit review',409);
            $verified[]=['kind'=>'OUTSTANDING_CHECK','side'=>'BANK','cents'=>moneyCents((string)$check['amount']),'source_key'=>'check:'.$id,'evidence'=>requiredString($l,'evidence',255),'check_id'=>$id];
        }
        try{$totals=FinancialClosePolicy::reconcile($statement,$book,$verified);}catch(DomainException $e){throw new DomainError($e->getMessage(),422);}
        $body=['schema'=>1,'report_version'=>'0.2.456-development','status'=>'DRAFT','bank_account_id'=>$bank,'period'=>$p,'statement_cents'=>$statement,'book_cents'=>$book,'statement_evidence'=>$evidence,'book_evidence'=>$bookEvidence,'lines'=>$verified,'totals'=>$totals,'predecessor_id'=>$expected,'actor_id'=>(int)$this->user['id'],'reason'=>$reason,'captured_at'=>date('c'),'book_completeness'=>'UNVERIFIED','certified'=>false];
        $id=$this->append($org,$key,$body,$reason);$this->audit($org,'FINANCE_REVIEW_DRAFT_SAVED',$id,['bank_id'=>$bank,'period'=>$p,'predecessor_id'=>$expected]);
        return ['version_id'=>$id,'draft'=>$body,'closed'=>false];
    }
    /** Review capture remains clearly distinguished from an audited close. */
    public function capture(array $d):array {return (new FinancialSnapshots($this->db,$this->user))->capture($d);}
    public function snapshot(array $d):array {return (new FinancialSnapshots($this->db,$this->user))->snapshot($d);}
    public function downloads(array $d):array {
        require_once __DIR__.'/FinanceMonitoring.php';$r=(new FinanceMonitoring($this->db,$this->user))->read($d);$t=$r['totals'];$complete=(int)$t['evidence_gaps']===0&&$t['cash_evidence_complete']&&(int)$t['unmapped_dv_count']===0;
        return $r+['verified_credit_cents'=>$t['verified_download_cents'],'evidence_gaps'=>$t['evidence_gaps'],'attributed_commitments_cents'=>$t['net_committed_cents'],'released_cash_cents'=>$t['released_cash_cents'],'remaining_cash_cents'=>(int)$t['unmapped_dv_count']===0?$t['remaining_cash_cents']:null,'complete'=>$complete,'status'=>$complete?'EXPLICIT_VERIFIED':'INCOMPLETE_UNMAPPED'];
    }
}
