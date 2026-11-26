<?php
declare(strict_types=1);
require_once __DIR__.'/FinanceAccess.php';
require_once __DIR__.'/FinancialClosePolicy.php';
require_once __DIR__.'/BankFundRegister.php';
require_once __DIR__.'/FinancialCutoff.php';

/** Explicit source attribution. This ledger never creates another fund credit.
 * All mutations run inside the API's idempotent transaction and cutoff mutex,
 * followed by fund, DV, account, sorted download and current-allocation locks.
 */
final class FinancialAllocation {
    public function __construct(private PDO $db,private array $user) {}
    public static function installed(PDO $db):bool {
        return (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='finance_allocation_sets'")->fetchColumn()===1;
    }
    private function auth(int $org,bool $write=false):void {
        $o=FinanceAccess::read($this->db,$this->user,$org);
        if($write){allowedToWrite($this->user);demand($this->user['role']==='SUPER_ADMIN'||($this->user['role']==='SCHOOL'&&$o['kind']==='SCHOOL')||($this->user['role']==='DIVISION'&&$o['kind']==='DIVISION'),'Division school finance is read-only',403);demand($this->db->inTransaction()&&FinancialCutoff::isCoordinated($this->db),'Financial allocation requires a coordinated transaction',409);demand(self::installed($this->db),'Reviewed finance migrations are required',409);}
    }
    private function dv(int $org,int $id,bool $lock=false):array {
        $d=one($this->db,'SELECT * FROM disbursement_vouchers WHERE id=? AND organization_id=?',[$id,$org]);demand($d!==null,'Voucher unavailable',404);
        if($lock){one($this->db,'SELECT id FROM funds WHERE id=? AND organization_id=? FOR UPDATE',[$d['fund_id'],$org]);$d=one($this->db,'SELECT * FROM disbursement_vouchers WHERE id=? AND organization_id=? FOR UPDATE',[$id,$org]);}return $d;
    }
    private function current(int $dv,bool $lock=false):?array {
        if(!self::installed($this->db))return null;
        $r=one($this->db,'SELECT * FROM finance_allocation_sets WHERE active_dv_id=?'.($lock?' FOR UPDATE':''),[$dv]);
        if($r)$r['lines']=q($this->db,'SELECT * FROM finance_allocation_lines WHERE allocation_set_id=? ORDER BY id',[$r['id']])->fetchAll();return $r;
    }
    private function planRow(int $org,int $dv):?array {
        $r=one($this->db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',[$org,'FA456_PLAN_'.$dv]);
        return $r?['version_id'=>(int)$r['id']]+json_decode($r['config_json'],true,512,JSON_THROW_ON_ERROR):null;
    }
    private function sources(int $dv):array {
        $version=one($this->db,'SELECT * FROM dv_accounting_versions WHERE dv_id=? ORDER BY version_no DESC,id DESC LIMIT 1',[$dv]);
        $accounting=$version?q($this->db,'SELECT * FROM voucher_accounting_lines WHERE accounting_version_id=? ORDER BY id',[$version['id']])->fetchAll():[];
        $gross=[];$tax=[];$by=[];$taxBy=[];$index=0;$debit=0;$credit=0;
        foreach($accounting as $a){$index++;$debit+=moneyCents($a['debit']);$credit+=moneyCents($a['credit']);if(moneyCents($a['debit'])>0){$a['cents']=moneyCents($a['debit']);$a['accounting_line_index']=$index;$by[(int)$a['id']]=$a;$gross[(int)$a['id']]=$a['cents'];}}
        foreach(q($this->db,'SELECT * FROM withholding_components WHERE dv_id=? ORDER BY id',[$dv])->fetchAll() as $t){$t['cents']=moneyCents($t['amount']);if($t['cents']>0){$tax[(int)$t['id']]=$t['cents'];$taxBy[(int)$t['id']]=$t;}}
        return compact('version','accounting','gross','tax','by','taxBy','debit','credit');
    }
    public function options(array $d):array {
        $org=idOf($d,'organization_id');$this->auth($org);$dv=$this->dv($org,idOf($d,'dv_id'));$bank=idOf($d,'bank_account_id');
        demand(one($this->db,'SELECT id FROM bank_accounts WHERE id=? AND organization_id=?',[$bank,$org])!==null,'Bank scope mismatch',403);
        $s=$this->sources((int)$dv['id']);$accounting=array_values($s['by']);
        if(!$accounting){$group=[];foreach(q($this->db,'SELECT accounting_uacs_code,amount FROM dv_allocation_lines WHERE dv_id=? ORDER BY id',[$dv['id']])->fetchAll() as $a){$code=trim((string)$a['accounting_uacs_code']);if($code!=='')$group[$code]=($group[$code]??0)+moneyCents($a['amount']);}foreach($group as $code=>$c)$accounting[]=['id'=>0,'uacs_code'=>(string)$code,'debit'=>moneyString($c),'cents'=>$c,'accounting_line_index'=>0];}
        $downloads=q($this->db,"SELECT id,organization_id,fund_id,bank_account_id,amount,bank_credit_date,bank_credit_ref,bank_evidence_ref FROM fund_events WHERE organization_id=? AND fund_id=? AND bank_account_id=? AND event_type='ALLOTMENT' ORDER BY bank_credit_date,id LIMIT 1001",[$org,$dv['fund_id'],$bank])->fetchAll();demand(count($downloads)<=1000,'Narrow download selection',409);
        foreach($downloads as &$r){$used=$this->usage((int)$r['id'],(int)$dv['id']);$r+=$used;}unset($r);
        return ['dv'=>$dv,'bank_account_id'=>$bank,'downloads'=>$downloads,'accounting_sources'=>$accounting,'tax_sources'=>array_values($s['taxBy']),'current'=>$this->current((int)$dv['id']),'plan'=>$this->planRow($org,(int)$dv['id'])];
    }
    public function plan(array $d):array {
        $org=idOf($d,'organization_id');$this->auth($org,true);$dv=$this->dv($org,idOf($d,'dv_id'),true);demand($dv['status']==='DRAFT','Only draft allocation plans can be saved',409);$bank=idOf($d,'bank_account_id');
        demand(one($this->db,'SELECT id FROM bank_accounts WHERE id=? AND organization_id=? FOR UPDATE',[$bank,$org])!==null,'Bank scope mismatch',403);
        $prior=$this->planRow($org,(int)$dv['id']);$expected=$d['expected_version']??0;demand(is_int($expected)&&$expected===(int)($prior['version_id']??0),'Allocation plan changed; refresh before saving',409);
        $lines=rowsRequired($d,'lines');$evidence=requiredString($d,'evidence_reference',255);$gross=0;$tax=0;
        foreach($lines as $l){demand(is_array($l)&&is_int($l['cents']??null)&&$l['cents']>0&&in_array($l['kind']??'', ['GROSS','TAX'],true),'Explicit positive integer-cents source lines required');$source=one($this->db,"SELECT id FROM fund_events WHERE id=? AND organization_id=? AND fund_id=? AND bank_account_id=? AND event_type='ALLOTMENT'",[idOf($l,'download_id'),$org,$dv['fund_id'],$bank]);demand($source!==null,'Download does not belong to this voucher fund/bank',403);if($l['kind']==='GROSS')$gross+=$l['cents'];else $tax+=$l['cents'];}
        demand($gross===moneyCents($dv['gross'])&&$tax===moneyCents($dv['deductions']),'Plan gross and tax must exactly match the saved voucher');
        $body=['bank_account_id'=>$bank,'lines'=>$lines,'evidence_reference'=>$evidence,'predecessor_id'=>(int)($prior['version_id']??0),'actor_id'=>(int)$this->user['id']];
        q($this->db,'INSERT INTO configuration_versions(organization_id,config_key,config_json,effective_from,review_note) VALUES (?,?,?,?,?)',[$org,'FA456_PLAN_'.$dv['id'],json_encode($body,JSON_THROW_ON_ERROR),date('Y-m-d'),'Explicit download plan; no financial reservation']);$id=(int)$this->db->lastInsertId();$this->audit($org,'DOWNLOAD_PLAN_SAVED',$id,['dv_id'=>(int)$dv['id']]);return ['version_id'=>$id]+$body;
    }
    /** Remitting an existing obligation uses cash but cannot charge its original
     * gross authority a second time. The exact original TAX allocation fixes
     * the download; missing or partial attribution is never guessed.
     */
    public static function remittanceRelief(PDO $db,int $dv,int $download):int {
        $tr=one($db,'SELECT t.*,d.organization_id AS dv_org,d.fund_id AS dv_fund FROM school_tax_transfers t JOIN disbursement_vouchers d ON d.id=t.dv_id WHERE t.dv_id=?',[$dv]);if(!$tr)return 0;$relief=0;
        foreach(q($db,'SELECT component_id,amount FROM school_tax_transfer_components WHERE transfer_id=? ORDER BY component_id',[$tr['id']])->fetchAll() as $tc){
            demand(one($db,'SELECT t.id FROM school_tax_transfers t JOIN school_tax_transfer_components c ON c.transfer_id=t.id JOIN finance_allocation_sets a ON a.active_dv_id=t.dv_id WHERE c.component_id=? AND t.dv_id<>? LIMIT 1',[$tc['component_id'],$dv])===null,'Remittable tax component already has an active settlement allocation',409);
            $parts=q($db,"SELECT l.download_id,l.cents,s.organization_id,d.fund_id,s.bank_account_id,s.state FROM finance_allocation_lines l JOIN finance_allocation_sets s ON s.id=l.allocation_set_id JOIN disbursement_vouchers d ON d.id=s.dv_id WHERE l.kind='TAX' AND l.source_id=? AND l.tax_disposition='REMIT' AND s.active_dv_id IS NOT NULL",[$tc['component_id']])->fetchAll();$total=0;
            foreach($parts as $part){demand((int)$part['organization_id']===(int)$tr['dv_org']&&(int)$part['fund_id']===(int)$tr['dv_fund']&&$part['state']==='PAID','Original remittance tax requires paid, same-fund explicit attribution',409);$total+=(int)$part['cents'];if((int)$part['download_id']===$download)$relief+=(int)$part['cents'];}
            demand($total===moneyCents($tc['amount']),'Original remittable component attribution is missing or incomplete',409);
        }return $relief;
    }
    private function usage(int $download,int $exclude):array {
        if(!self::installed($this->db))return ['other_committed_cents'=>0,'other_gross_cents'=>0,'other_retained_cents'=>0];
        $r=one($this->db,"SELECT COALESCE(SUM(CASE WHEN l.kind='GROSS' THEN l.cents ELSE -CAST(l.cents AS SIGNED) END),0) AS net,COALESCE(SUM(CASE WHEN l.kind='GROSS' THEN l.cents ELSE 0 END),0) AS gross,COALESCE(SUM(CASE WHEN l.kind='TAX' AND l.tax_disposition='SCHOOL_UTILIZATION' THEN l.cents ELSE 0 END),0) AS retained FROM finance_allocation_lines l JOIN finance_allocation_sets s ON s.id=l.allocation_set_id WHERE l.download_id=? AND s.active_dv_id IS NOT NULL AND s.dv_id<>?",[$download,$exclude]);$relief=0;
        foreach(q($this->db,'SELECT DISTINCT s.dv_id FROM finance_allocation_sets s JOIN finance_allocation_lines l ON l.allocation_set_id=s.id JOIN school_tax_transfers t ON t.dv_id=s.dv_id WHERE l.download_id=? AND s.active_dv_id IS NOT NULL AND s.dv_id<>?',[$download,$exclude])->fetchAll() as $t)$relief+=self::remittanceRelief($this->db,(int)$t['dv_id'],$download);
        return ['other_committed_cents'=>(int)$r['net'],'other_gross_cents'=>(int)$r['gross']-$relief,'other_retained_cents'=>(int)$r['retained']];
    }
    private function physicalCapacity(int $org,int $fund,int $bank,int $candidate,int $candidateNet):void {
        $book=BankFundRegister::read($this->db,$org,true);$account=0;$fundCash=0;
        foreach($book['rows'] as $r)if((int)$r['bank_account_id']===$bank){$delta=moneyCents($r['credit'])-moneyCents($r['debit']);$account+=$delta;if((int)$r['fund_id']===$fund)$fundCash+=$delta;}
        // Checkless reservations move into the ordinary book at issuance. Count
        // each commitment once across that transition, including other funds.
        $pending=q($this->db,'SELECT s.dv_id,s.net_cents,d.fund_id FROM finance_allocation_sets s JOIN disbursement_vouchers d ON d.id=s.dv_id WHERE s.organization_id=? AND s.bank_account_id=? AND s.active_dv_id IS NOT NULL AND s.dv_id<>? AND NOT EXISTS(SELECT 1 FROM checks c WHERE c.dv_id=s.dv_id) ORDER BY s.id',[$org,$bank,$candidate])->fetchAll();
        foreach($pending as $s){$account-=(int)$s['net_cents'];if((int)$s['fund_id']===$fund)$fundCash-=(int)$s['net_cents'];}
        if(one($this->db,'SELECT id FROM checks WHERE dv_id=? LIMIT 1',[$candidate])===null){$account-=$candidateNet;$fundCash-=$candidateNet;}
        $minimum=0;if(class_exists('BankReconciliation')){$account+=BankReconciliation::bookAdjustments($this->db,$org,$bank,date('Y-m-d'));$minimum=BankReconciliation::maintaining($this->db,$org,$bank);}
        demand($account>=$minimum&&$fundCash>=0,'Actual fund/account cash after every check and reservation must preserve the maintaining balance',409);
    }
    /** Called after all original Domain controls, before the shared transaction commits. */
    public function reserve(array $d,bool $newFinalization=false):array {
        $org=idOf($d,'organization_id');$this->auth($org,true);$dv=$this->dv($org,idOf($d,'dv_id'),true);demand($dv['status']==='FINALIZED','A finalized voucher is required',409);$bank=idOf($d,'bank_account_id');
        demand(one($this->db,'SELECT id FROM bank_accounts WHERE id=? AND organization_id=? FOR UPDATE',[$bank,$org])!==null,'Bank scope mismatch',403);
        FinancialCutoff::assertOpen($this->db,$org,(string)($d['effective_date']??date('Y-m-d')));
        FinancialCutoff::assertOpen($this->db,$org,(string)$dv['document_date']);
        $previous=$this->current((int)$dv['id'],true);
        if($previous)demand(($d['replace_allocation']??false)===true&&(int)($d['expected_allocation_id']??0)===(int)$previous['id'],'Voucher already has an active allocation; use an explicitly versioned revision',409);
        if(!$newFinalization&&!$previous)demand(($d['historical_review']??false)===true,'Unmapped historical payments require explicit source review',409);
        $evidence=requiredString($d,'evidence_reference',255);$lines=rowsRequired($d,'lines');$s=$this->sources((int)$dv['id']);
        demand($s['version']!==null&&$s['debit']===$s['credit']&&$s['debit']===moneyCents($dv['gross']),'Saved approved journal is missing or unbalanced',409);
        $checks=q($this->db,'SELECT * FROM checks WHERE organization_id=? AND dv_id=? ORDER BY id FOR UPDATE',[$org,$dv['id']])->fetchAll();$roots=array_filter($checks,static fn($c)=>empty($c['replaces_check_id']));
        demand(!$checks||count($roots)===1,'Historical split or rootless checks need explicit payment-source mapping; attribution is blocked',409);
        $checkBy=[];$children=[];
        foreach($checks as $c){
            demand((int)$c['bank_account_id']===$bank&&moneyCents($c['amount'])===moneyCents($dv['net']),'Check bank or net differs from exact voucher amount',409);
            $checkBy[(int)$c['id']]=$c;
            if(!empty($c['replaces_check_id'])){
                $parent=(int)$c['replaces_check_id'];$children[$parent]=($children[$parent]??0)+1;
                demand($children[$parent]===1,'Branching historical check replacements require explicit payment-source review; attribution is blocked',409);
            }
        }
        foreach($checks as $c){
            $seen=[];
            while(!empty($c['replaces_check_id'])){
                $ancestor=(int)$c['replaces_check_id'];
                demand(isset($checkBy[$ancestor])&&!isset($seen[$ancestor]),'Cyclic or incomplete replacement history requires review',409);
                $parent=$checkBy[$ancestor];
                demand($parent['status']==='REPLACED'&&(int)q($this->db,"SELECT COUNT(*) FROM payment_events WHERE check_id=? AND event_type='REPLACED'",[$ancestor])->fetchColumn()===1,'Replacement predecessor lacks one matching audited REPLACED event; historical attribution is blocked',409);
                $seen[$ancestor]=true;$c=$parent;
            }
        }
        $normalized=[];$downloadIds=[];$meta=[];$retained=0;
        foreach($lines as $l){$kind=$l['kind']??'';$id=(int)($l['source_id']??0);
            if($kind==='GROSS'&&!$id){$matches=array_filter($s['by'],static fn($a)=>!empty($l['accounting_line_index'])?(int)$a['accounting_line_index']===(int)$l['accounting_line_index']:(isset($l['uacs_code'])&&(string)$a['uacs_code']===(string)$l['uacs_code']));demand(count($matches)===1,'Select one exact saved debit line; ambiguous UACS cannot be inferred',409);$id=(int)array_key_first($matches);}
            demand(($kind==='GROSS'&&isset($s['by'][$id]))||($kind==='TAX'&&isset($s['taxBy'][$id])),'Source line does not belong to current saved accounting/tax version',409);
            $did=idOf($l,'download_id');$line=['kind'=>$kind,'source_id'=>$id,'download_id'=>$did,'cents'=>$l['cents']??null];$normalized[]=$line;$downloadIds[$did]=true;
            $m=['uacs_code'=>$kind==='GROSS'?$s['by'][$id]['uacs_code']:null,'tax_disposition'=>$kind==='TAX'?$s['taxBy'][$id]['disposition']:null,'evidence_reference'=>trim((string)($l['evidence']??$evidence))];demand($m['evidence_reference']!==''&&strlen($m['evidence_reference'])<=255,'Allocation evidence required');$meta[]=$m;
            if($m['tax_disposition']==='SCHOOL_UTILIZATION')$retained+=(int)($l['cents']??0);
        }
        if($previous){$oldTax=[];$newTax=[];foreach($previous['lines'] as $l)if($l['kind']==='TAX')$oldTax[(int)$l['source_id']][(int)$l['download_id']]=(int)$l['cents'];foreach($normalized as $l)if($l['kind']==='TAX')$newTax[$l['source_id']][$l['download_id']]=$l['cents'];foreach($oldTax as $component=>$shares){ksort($shares);$next=$newTax[$component]??[];ksort($next);if($shares!==$next)demand(one($this->db,'SELECT t.id FROM school_tax_transfer_components c JOIN school_tax_transfers t ON t.id=c.transfer_id JOIN finance_allocation_sets a ON a.active_dv_id=t.dv_id WHERE c.component_id=? LIMIT 1',[$component])===null,'A settled remittable component cannot change its download attribution independently of its settlement',409);}}
        if($retained>0){require_once __DIR__.'/Domain.php';$domain=new Domain($this->db,$this->user);$checked=[];foreach($normalized as $i=>$line)if($meta[$i]['tax_disposition']==='SCHOOL_UTILIZATION'&&!isset($checked[$line['source_id']])){$domain->retainedAllocationAuthority(['organization_id'=>$org,'component_id'=>$line['source_id']]);$checked[$line['source_id']]=true;}}
        ksort($downloadIds,SORT_NUMERIC);$downloads=[];
        foreach(array_keys($downloadIds) as $id){$r=one($this->db,"SELECT e.* FROM fund_events e JOIN funds f ON f.id=e.fund_id AND f.organization_id=e.organization_id JOIN bank_accounts b ON b.id=e.bank_account_id AND b.organization_id=e.organization_id WHERE e.id=? AND e.event_type='ALLOTMENT' FOR UPDATE",[$id]);demand($r!==null,'Verified download source unavailable',409);$u=$this->usage($id,(int)$dv['id']);$downloads[$id]=['organization_id'=>(int)$r['organization_id'],'fund_id'=>(int)$r['fund_id'],'bank_account_id'=>(int)$r['bank_account_id'],'verified'=>trim((string)$r['bank_credit_ref'])!==''&&trim((string)$r['bank_evidence_ref'])!==''&&in_array($r['reference_type'],['BANK_DOWNLOAD','DIVISION_RELEASE'],true),'credit_date'=>$r['bank_credit_date'],'credit_cents'=>moneyCents($r['amount'])]+$u;}
        try{$by=FinancialClosePolicy::allocation(['organization_id'=>$org,'fund_id'=>(int)$dv['fund_id'],'bank_account_id'=>$bank,'date'=>(string)$dv['document_date'],'gross_cents'=>moneyCents($dv['gross']),'deductions_cents'=>moneyCents($dv['deductions']),'net_cents'=>moneyCents($dv['net'])],$downloads,$normalized,$s['gross'],$s['tax']);}catch(DomainException $e){throw new DomainError($e->getMessage(),409);}
        $transfer=one($this->db,'SELECT id FROM school_tax_transfers WHERE dv_id=?',[$dv['id']]);
        foreach($by as $did=>$totals){$r=0;foreach($normalized as $i=>$line)if($line['download_id']===$did&&$meta[$i]['tax_disposition']==='SCHOOL_UTILIZATION')$r+=$line['cents'];$src=$downloads[$did];$relief=$transfer?self::remittanceRelief($this->db,(int)$dv['id'],$did):0;if($transfer)demand($relief===$totals['gross_cents'],'Remittance cash must use the original explicitly attributed tax download',409);demand($src['other_gross_cents']-$src['other_retained_cents']+$totals['gross_cents']-$r-$relief<=$src['credit_cents'],'Download gross budget authority exceeded; retained and remittable tax are distinct',409);}
        // Unknown prior payments cannot silently consume arbitrary downloads.
        if($newFinalization){$unmapped=one($this->db,"SELECT c.id FROM checks c JOIN disbursement_vouchers d ON d.id=c.dv_id WHERE c.organization_id=? AND c.bank_account_id=? AND d.fund_id=? AND d.id<>? AND c.status NOT IN ('CANCELLED','REPLACED') AND NOT EXISTS(SELECT 1 FROM finance_allocation_sets a WHERE a.active_dv_id=d.id) LIMIT 1",[$org,$bank,$dv['fund_id'],$dv['id']]);demand($unmapped===null,'Historical payments in this fund/bank require explicit download review before new spending',409);}
        $this->physicalCapacity($org,(int)$dv['fund_id'],$bank,(int)$dv['id'],moneyCents($dv['net']));
        $paid=$previous?(int)one($this->db,'SELECT COALESCE(SUM(cash_delta_cents),0) n FROM finance_allocation_events WHERE allocation_set_id=?',[$previous['id']])['n']:0;
        if($previous)q($this->db,"UPDATE finance_allocation_sets SET active_dv_id=NULL,state='SUPERSEDED' WHERE id=?",[$previous['id']]);
        $revision=(int)one($this->db,'SELECT COALESCE(MAX(revision),0)+1 n FROM finance_allocation_sets WHERE dv_id=?',[$dv['id']])['n'];
        q($this->db,'INSERT INTO finance_allocation_sets(organization_id,dv_id,bank_account_id,accounting_version_id,revision,active_dv_id,state,predecessor_id,gross_cents,tax_cents,net_cents,retained_cents,evidence_reference,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$org,$dv['id'],$bank,$s['version']['id'],$revision,$dv['id'],$paid>0?'PAID':'RESERVED',$previous['id']??null,moneyCents($dv['gross']),moneyCents($dv['deductions']),moneyCents($dv['net']),$retained,$evidence,$this->user['id']]);$set=(int)$this->db->lastInsertId();
        foreach($normalized as $i=>$l)q($this->db,'INSERT INTO finance_allocation_lines(allocation_set_id,download_id,kind,source_id,cents,uacs_code,tax_disposition,evidence_reference) VALUES (?,?,?,?,?,?,?,?)',[$set,$l['download_id'],$l['kind'],$l['source_id'],$l['cents'],$meta[$i]['uacs_code'],$meta[$i]['tax_disposition'],$meta[$i]['evidence_reference']]);
        $this->event($set,null,null,'RESERVED',0,'reserve:'.$set,$evidence);if($paid>0)$this->event($set,null,null,'CASH_CARRY_FORWARD',$paid,'carry:'.$set,$evidence);
        if(!$previous&&!$newFinalization){$released=[];foreach($checks as $c){$events=q($this->db,"SELECT id FROM payment_events WHERE check_id=? AND event_type='RELEASED' ORDER BY id",[$c['id']])->fetchAll();if($events)$released[]=[(int)$c['id'],(int)$events[0]['id']];}if($released){demand(count($roots)===1,'Ambiguous historical payment roots',409);$this->event($set,$released[0][0],$released[0][1],'HISTORICAL_RELEASE_REVIEW',moneyCents($dv['net']),'historical-release:'.$set,$evidence);q($this->db,"UPDATE finance_allocation_sets SET state='PAID' WHERE id=?",[$set]);}}
        $this->audit($org,'DOWNLOAD_ALLOCATION_RESERVED',$set,['dv_id'=>(int)$dv['id'],'revision'=>$revision,'historical_review'=>!$newFinalization]);return $this->current((int)$dv['id']);
    }
    private function event(int $set,?int $check,?int $payment,string $kind,int $cash,string $key,string $evidence):void {
        q($this->db,'INSERT INTO finance_allocation_events(allocation_set_id,check_id,payment_event_id,event_type,cash_delta_cents,event_key,evidence_reference,created_by) VALUES (?,?,?,?,?,?,?,?)',[$set,$check,$payment,$kind,$cash,$key,$evidence,$this->user['id']]);
    }
    private function audit(int $org,string $kind,int $id,array $detail):void {q($this->db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',[$org,$this->user['id'],$kind,'DOWNLOAD_ALLOCATION',$id,json_encode($detail,JSON_THROW_ON_ERROR)]);}
    /** Append a debit-only reclassification and exact new attribution together.
     * Cash, liabilities, tax identities, DV/check numbers and payment states stay
     * unchanged. Prior accounting/allocation versions remain reportable.
     */
    public function correctAccounting(array $d):array {
        $org=idOf($d,'organization_id');$this->auth($org,true);$dv=$this->dv($org,idOf($d,'dv_id'),true);demand($dv['status']==='FINALIZED','Finalized voucher required',409);
        $set=$this->current((int)$dv['id'],true);demand($set!==null,'Explicit allocation must be reviewed before correcting accounting',409);$old=$this->sources((int)$dv['id']);
        demand((int)($d['expected_accounting_version']??0)===(int)$old['version']['id']&&(int)($d['expected_allocation_id']??0)===(int)$set['id'],'Accounting/allocation changed; refresh before correction',409);
        $reason=requiredString($d,'reason',255);$entries=rowsRequired($d,'accounting');$debit=0;
        foreach($entries as $e){demand(is_array($e)&&preg_match('/^(?:502|104|106|108)[0-9]{7}$/D',(string)($e['uacs_code']??''))===1,'Select an authorized expense, property or inventory debit UACS');demand(one($this->db,'SELECT code FROM uacs_accounts WHERE code=? AND active=1',[$e['uacs_code']])!==null,'Debit UACS is not active',409);demand(moneyCents($e['credit']??'0')===0,'Credit entries are immutable');$debit+=moneyCents($e['debit']??'0');}
        demand($debit===moneyCents($dv['gross']),'Reclassification must preserve exact saved gross',409);
        FinancialCutoff::assertOpen($this->db,$org,(string)($d['effective_date']??date('Y-m-d')));
        q($this->db,'INSERT INTO dv_accounting_versions(dv_id,version_no,reason,actor_id) VALUES (?,?,?,?)',[$dv['id'],(int)$old['version']['version_no']+1,$reason,$this->user['id']]);$version=(int)$this->db->lastInsertId();
        foreach($entries as $e)q($this->db,'INSERT INTO voucher_accounting_lines(accounting_version_id,uacs_code,debit,credit,explanation) VALUES (?,?,?,?,?)',[$version,$e['uacs_code'],moneyString(moneyCents($e['debit'])), '0.00',$reason]);
        foreach($old['accounting'] as $e)if(moneyCents($e['credit'])>0)q($this->db,'INSERT INTO voucher_accounting_lines(accounting_version_id,uacs_code,debit,credit,explanation) VALUES (?,?,?,?,?)',[$version,$e['uacs_code'],'0.00',$e['credit'],$e['explanation']]);
        $saved=$this->reserve(['organization_id'=>$org,'dv_id'=>(int)$dv['id'],'bank_account_id'=>(int)$set['bank_account_id'],'lines'=>rowsRequired($d,'lines'),'evidence_reference'=>$reason,'replace_allocation'=>true,'expected_allocation_id'=>(int)$set['id'],'effective_date'=>$d['effective_date']??date('Y-m-d')]);
        $this->audit($org,'DOWNLOAD_ACCOUNTING_RECLASSIFIED',(int)$saved['id'],['dv_id'=>(int)$dv['id'],'previous_version'=>(int)$old['version']['id'],'new_version'=>$version,'cash_unchanged'=>true]);return ['accounting_version_id'=>$version,'allocation'=>$saved];
    }
    public function afterMutation(string $route,array $d,array $out):void {
        if(!self::installed($this->db)){demand(!in_array($route,['dv.finalize','check.create','check.replace'],true)&&!($route==='check.status'&&($d['status']??'')==='RELEASED'),'Reviewed finance schema must be applied before payment allocation',409);return;}
        $org=(int)($d['organization_id']??0);if(!$org)return;
        if(in_array($route,['dv.finalize','check.create'],true)){
            $dvId=(int)($route==='dv.finalize'?$out['id']:$d['dv_id']);$bank=(int)($d['bank_account_id']??0);$current=$this->current($dvId);if(!$current){$plan=$this->planRow($org,$dvId);$lines=$d['download_allocations']??$plan['lines']??null;demand(is_array($lines)&&$lines,'Save explicit download/source attribution before finalizing or issuing the check',409);$this->reserve(['organization_id'=>$org,'dv_id'=>$dvId,'bank_account_id'=>$bank,'lines'=>$lines,'evidence_reference'=>$d['allocation_evidence']??$plan['evidence_reference']??'','historical_review'=>$d['historical_review']??false],$route==='dv.finalize');}
        }
        if(in_array($route,['dv.finalize','check.create'],true)){
            $active=$this->current($dvId);$check=one($this->db,'SELECT * FROM checks WHERE id=? AND organization_id=?',[(int)($out['check_id']??$out['id']),$org]);
            demand($check!==null&&$active!==null&&(int)$check['bank_account_id']===(int)$active['bank_account_id']&&moneyCents($check['amount'])===(int)$active['net_cents'],'Physical issuance must match the exact whole allocated net and bank',409);
            $voucher=$this->dv($org,$dvId);$this->physicalCapacity($org,(int)$voucher['fund_id'],(int)$active['bank_account_id'],$dvId,(int)$active['net_cents']);
        }
        if(in_array($route,['check.status','check.replace'],true)){
            $id=(int)($route==='check.replace'?$out['id']:$d['check_id']);$check=one($this->db,'SELECT * FROM checks WHERE id=? AND organization_id=?',[$id,$org]);demand($check!==null,'Check scope mismatch',403);$set=$this->current((int)$check['dv_id'],true);
            if(!$set){demand($route!=='check.replace'&&($d['status']??'')!=='RELEASED','Explicit historical attribution is required before payment/replacement',409);return;}
            $kind=$route==='check.replace'?'REPLACEMENT_ISSUED':(string)$d['status'];$payment=one($this->db,'SELECT id FROM payment_events WHERE check_id=? ORDER BY id DESC LIMIT 1',[$id]);$cash=0;
            if($kind==='RELEASED'){$paid=(int)one($this->db,'SELECT COALESCE(SUM(cash_delta_cents),0) n FROM finance_allocation_events WHERE allocation_set_id=?',[$set['id']])['n'];demand(in_array($paid,[0,(int)$set['net_cents']],true),'Recorded payment attribution is inconsistent',409);if($paid===0)$cash=(int)$set['net_cents'];q($this->db,"UPDATE finance_allocation_sets SET state='PAID' WHERE id=?",[$set['id']]);}
            $this->event((int)$set['id'],$id,$payment?(int)$payment['id']:null,$kind,$cash,'payment:'.($payment['id']??$id).':'.$kind,(string)($d['evidence_reference']??$d['reason']??'Operator confirmed payment event'));
        }
        if(in_array($route,['document.cancel','dv.draft.cancel'],true)&&($d['doc_type']??($route==='dv.draft.cancel'?'DV':''))==='DV'){
            $set=$this->current((int)$d['id'],true);if($set){$paid=(int)one($this->db,'SELECT COALESCE(SUM(cash_delta_cents),0) n FROM finance_allocation_events WHERE allocation_set_id=?',[$set['id']])['n'];demand($paid===0,'Paid allocation requires a separately evidenced refund; cancellation cannot create cash',409);$this->event((int)$set['id'],null,null,'RESERVATION_REVERSED',0,'cancel:'.$set['id'],requiredString($d,'reason',255));q($this->db,"UPDATE finance_allocation_sets SET active_dv_id=NULL,state='REVERSED' WHERE id=?",[$set['id']]);}
        }
        if(in_array($route,['dv.accounting','dv.update','master.item.uacs_effectivity.apply'],true)){
            $dvId=(int)($d['dv_id']??0);$set=$dvId?$this->current($dvId):null;if($set){$s=$this->sources($dvId);if((int)$s['version']['id']!==(int)$set['accounting_version_id']){demand(isset($d['download_allocations']),'Accounting correction requires explicit new source attribution',409);$this->reserve(['organization_id'=>$org,'dv_id'=>$dvId,'bank_account_id'=>(int)$set['bank_account_id'],'lines'=>$d['download_allocations'],'evidence_reference'=>$d['allocation_evidence']??$d['reason']??'','replace_allocation'=>true,'expected_allocation_id'=>(int)$set['id']]);}}
        }
    }
}
