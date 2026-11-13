<?php
declare(strict_types=1);
// Requires dedicated APP_ENV=test MySQL database initialized with migration and demo seed.
require_once dirname(__DIR__).'/app/Domain.php';
if(PHP_SAPI!=='cli'||cfg('APP_ENV')!=='test'){fwrite(STDERR,"Integration tests require CLI and APP_ENV=test in an isolated database.\n");exit(1);}
$db=database();
function assertTrue(bool $ok,string $label): void {if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'PASS '.$label."\n";}
function expectFailure(callable $fn,string $label): void {try{$fn();throw new RuntimeException('FAIL unexpectedly allowed '.$label);}catch(DomainError $e){echo 'PASS denied '.$label.' — '.$e->getMessage()."\n";}}
function makePath(Domain $app,int $supplier,string $name): array {
    $pr=$app->createPR(['organization_id'=>2,'fund_id'=>1,'purpose'=>$name,'requesting_unit'=>'Test School','lines'=>[['item_id'=>1,'quantity'=>'2.000','unit_price'=>'250.00']]]);
    $app->finalizePR(['organization_id'=>2,'pr_id'=>$pr['id']]);
    $rfq=$app->createRFQ(['organization_id'=>2,'pr_id'=>$pr['id']]);
    $quote=$app->createQuote(['organization_id'=>2,'rfq_id'=>$rfq['id'],'supplier_id'=>$supplier,'evidence_note'=>'Synthetic quotation scanned','lines'=>[['pr_line_id'=>(int)one(database(),'SELECT id FROM pr_lines WHERE pr_id=?',[$pr['id']])['id'],'price'=>'250.00','compliant'=>true]]]);
    $award=$app->createAward(['organization_id'=>2,'pr_id'=>$pr['id'],'rfq_id'=>$rfq['id'],'supplier_id'=>$supplier,'determination_note'=>'Synthetic human determination for isolated test']);
    $line=one(database(),'SELECT id FROM pr_lines WHERE pr_id=?',[$pr['id']]);
    $po=$app->createPO(['organization_id'=>2,'award_id'=>$award['id'],'lines'=>[['pr_line_id'=>(int)$line['id'],'quantity'=>'2.000','unit_price'=>'250.00']]]);
    $pl=one(database(),'SELECT id FROM po_lines WHERE po_id=?',[$po['id']]);
    $iar=$app->createIAR(['organization_id'=>2,'po_id'=>$po['id'],'delivered_at'=>date('Y-m-d'),'accepted_at'=>date('Y-m-d'),'lines'=>[['po_line_id'=>(int)$pl['id'],'delivered_qty'=>'1.000','accepted_qty'=>'1.000']]]);
    $il=one(database(),'SELECT id FROM iar_lines WHERE iar_id=?',[$iar['id']]);
    $advance=$app->createAdvance(['organization_id'=>2,'iar_line_id'=>(int)$il['id'],'original_payer'=>'Synthetic Administrator','source'=>'PERSONAL','amount'=>'250.00','paid_at'=>date('Y-m-d'),'evidence_reference'=>'TEST-RECEIPT-'.$name]);
    return ['pr'=>$pr,'po'=>$po,'iar'=>$iar,'iar_line_id'=>(int)$il['id'],'advance'=>$advance,'po_line_id'=>(int)$pl['id']];
}
$db->beginTransaction();
try {
    $id=(int)$db->lastInsertId();
    q($db,"INSERT INTO users(email,password_hash,full_name,role) VALUES (?,?,?,'SUPER_ADMIN')",['test-'.bin2hex(random_bytes(4)).'@example.invalid',password_hash('isolated-test-password',PASSWORD_DEFAULT),'Isolated Test Administrator']);
    $adminId=(int)$db->lastInsertId();$admin=['id'=>$adminId,'role'=>'SUPER_ADMIN','full_name'=>'Test Administrator'];$app=new Domain($db,$admin);
    // Document numbers are per-school even if the printed number matches.
    $a=$app->createPR(['organization_id'=>2,'fund_id'=>1,'purpose'=>'School Two Numbering','requesting_unit'=>'Test','lines'=>[['item_id'=>1,'quantity'=>'1','unit_price'=>'2']]]);
    $b=$app->createPR(['organization_id'=>3,'fund_id'=>2,'purpose'=>'School Three Numbering','requesting_unit'=>'Test','lines'=>[['item_id'=>1,'quantity'=>'1','unit_price'=>'2']]]);
    $n1=$app->finalizePR(['organization_id'=>2,'pr_id'=>$a['id']]);$n2=$app->finalizePR(['organization_id'=>3,'pr_id'=>$b['id']]);
    assertTrue((int)$a['id']!==(int)$b['id'],'distinct internal PR identifiers');
    assertTrue($n1['number']===$n2['number'],'per-school counters permit identical printed numbers');
    // Direct utilities require only PR + billing reference; no RFQ/PO/IAR fabricated.
    $util=$app->createPR(['organization_id'=>2,'fund_id'=>1,'purpose'=>'Utility bill','requesting_unit'=>'School Head','lines'=>[['item_id'=>4,'quantity'=>'1','unit_price'=>'1000']]]);$app->finalizePR(['organization_id'=>2,'pr_id'=>$util['id']]);
    $direct=$app->createDV(['organization_id'=>2,'dv_type'=>'DIRECT','fund_id'=>1,'payee'=>'Demo Utility Provider','bill_reference'=>'UTILITY-TEST-2026','allocations'=>[['pr_id'=>$util['id'],'amount'=>'1000.00']]]);
    $dout=$app->finalizeDV(['organization_id'=>2,'dv_id'=>$direct['id'],'accounting'=>[['uacs_code'=>'DEMO-EXP','debit'=>'1000.00','credit'=>'0'],['uacs_code'=>'DEMO-AP','debit'=>'0','credit'=>'1000.00']]]);
    assertTrue((int)one($db,'SELECT COUNT(*) c FROM rfqs WHERE pr_id=?',[$util['id']])['c']===0,'direct DV without fictitious sourcing');
    $revision=$app->reviseAccounts(['organization_id'=>2,'dv_id'=>$direct['id'],'reason'=>'Correct expense classification in isolated test','accounting'=>[['uacs_code'=>'DEMO-INV','debit'=>'1000.00','credit'=>'0'],['uacs_code'=>'DEMO-AP','debit'=>'0','credit'=>'1000.00']]]);
    assertTrue($revision['accounting_version']===2&&$revision['gross']==='1000.00','accounting-only revision retains gross');
    $paths=[makePath($app,1,'first'),makePath($app,2,'second'),makePath($app,1,'third')];
    assertTrue((int)one($db,"SELECT COUNT(*) n FROM stock_events WHERE event_type='RECEIPT' AND source_type='IAR_LINE'")['n']===3,'one physical stock receipt per accepted IAR');
    $dv=$app->createDV(['organization_id'=>2,'dv_type'=>'REIMBURSEMENT','fund_id'=>1,'payee'=>'Synthetic Administrator','allocations'=>array_map(static fn($x)=>['advance_id'=>$x['advance']['id'],'amount'=>'200.00'],$paths)]);
    $app->finalizeDV(['organization_id'=>2,'dv_id'=>$dv['id'],'accounting'=>[['uacs_code'=>'DEMO-EXP','debit'=>'600.00','credit'=>'0'],['uacs_code'=>'DEMO-AP','debit'=>'0','credit'=>'600.00']]]);
    assertTrue((int)one($db,'SELECT COUNT(*) c FROM dv_allocation_lines WHERE dv_id=?',[$dv['id']])['c']===3,'three reimbursement sources combined under one payee');
    $overlap=$app->createDV(['organization_id'=>2,'dv_type'=>'REIMBURSEMENT','fund_id'=>1,'payee'=>'Synthetic Administrator','allocations'=>[['advance_id'=>$paths[0]['advance']['id'],'amount'=>'100.00']]]);
    expectFailure(fn()=>$app->finalizeDV(['organization_id'=>2,'dv_id'=>$overlap['id'],'accounting'=>[['uacs_code'=>'DEMO-EXP','debit'=>'100.00','credit'=>'0'],['uacs_code'=>'DEMO-AP','debit'=>'0','credit'=>'100.00']]]),'reimbursement over documented advance');
    $iar2=$app->createIAR(['organization_id'=>2,'po_id'=>$paths[0]['po']['id'],'delivered_at'=>date('Y-m-d'),'accepted_at'=>date('Y-m-d'),'lines'=>[['po_line_id'=>$paths[0]['po_line_id'],'delivered_qty'=>'1.000','accepted_qty'=>'1.000']]]);
    assertTrue((int)one($db,'SELECT COUNT(*) c FROM iars WHERE po_id=?',[$paths[0]['po']['id']])['c']===2,'PO accepts multiple partial IARs');
    $ris=$app->createRIS(['organization_id'=>2,'source_mode'=>'PR','pr_id'=>$paths[0]['pr']['id'],'requestor'=>'Demo Custodian']);$rl=one($db,'SELECT id FROM ris_lines WHERE ris_id=?',[$ris['id']]);$lot=one($db,'SELECT l.id FROM inventory_lots l JOIN iar_lines il ON il.id=l.iar_line_id WHERE il.iar_id=?',[$paths[0]['iar']['id']]);
    $app->issueRIS(['organization_id'=>2,'ris_id'=>$ris['id'],'lines'=>[['ris_line_id'=>(int)$rl['id'],'lot_id'=>(int)$lot['id'],'issued_qty'=>'1.000']]]);
    assertTrue((int)one($db,'SELECT SUM(quantity_delta) n FROM stock_events WHERE lot_id=?',[$lot['id']])['n']===0,'RIS consumes actual stock once');
    $app->reverseRIS(['organization_id'=>2,'ris_id'=>$ris['id'],'reason'=>'Test return to store']);
    assertTrue((int)one($db,'SELECT SUM(quantity_delta) n FROM stock_events WHERE lot_id=?',[$lot['id']])['n']===1,'RIS reversal restores stock with event');
    $directRIS=$app->createRIS(['organization_id'=>2,'source_mode'=>'DIRECT','requestor'=>'Sample Employee','lines'=>[['item_id'=>1,'requested_qty'=>'1']]]);
    assertTrue($directRIS['status']==='DRAFT','PR-free RIS available');
    q($db,"INSERT INTO tax_atcs(code,description) VALUES ('DEMO-TAX','Synthetic tax for integration test')");q($db,'INSERT INTO tax_rate_versions(atc_code,rate,starts,authority_reference) VALUES (?,?,?,?)',['DEMO-TAX','0.050000','2026-01-01','TEST-NOT-OFFICIAL']);$rate=(int)$db->lastInsertId();
    $util2=$app->createPR(['organization_id'=>2,'fund_id'=>1,'purpose'=>'Synthetic taxable utility','requesting_unit'=>'Test','lines'=>[['item_id'=>4,'quantity'=>'1','unit_price'=>'1000']]]);$app->finalizePR(['organization_id'=>2,'pr_id'=>$util2['id']]);
    $taxDv=$app->createDV(['organization_id'=>2,'dv_type'=>'DIRECT','fund_id'=>1,'payee'=>'Demo Payee','bill_reference'=>'TEST-BILL','allocations'=>[['pr_id'=>$util2['id'],'amount'=>'1000.00']],'withholding'=>[['rate_version_id'=>$rate,'tax_base'=>'1000.00','disposition'=>'SCHOOL_UTILIZATION']]]);
    $app->finalizeDV(['organization_id'=>2,'dv_id'=>$taxDv['id'],'accounting'=>[['uacs_code'=>'DEMO-EXP','debit'=>'1000.00','credit'=>'0'],['uacs_code'=>'DEMO-AP','debit'=>'0','credit'=>'1000.00']]]);
    $taxId=(int)one($db,'SELECT id FROM withholding_components WHERE dv_id=?',[$taxDv['id']])['id'];
    assertTrue((int)one($db,"SELECT COUNT(*) c FROM fund_events WHERE reference_type='TAX' AND reference_id=? AND event_type='CONFIRMED_CREDIT'",[$taxId])['c']===0,'pending tax not posted as spendable credit');
    expectFailure(fn()=>$app->confirmTax(['organization_id'=>2,'component_id'=>$taxId,'state'=>'CONFIRMED_CREDIT','evidence_reference'=>'TEST','authority_reference'=>'TEST']),'tax utilization without budget/cash proof');
    $app->confirmTax(['organization_id'=>2,'component_id'=>$taxId,'state'=>'CONFIRMED_CREDIT','evidence_reference'=>'Synthetic bank receipt','authority_reference'=>'TEST-NOT-OFFICIAL','budget_conditions_met'=>true,'cash_conditions_met'=>true]);
    assertTrue((int)one($db,"SELECT COUNT(*) c FROM fund_events WHERE reference_type='TAX' AND reference_id=? AND event_type='CONFIRMED_CREDIT'",[$taxId])['c']===1,'authorized confirmation creates one fund credit event');
    q($db,"INSERT INTO users(email,password_hash,full_name,role) VALUES (?,?,?,'SCHOOL')",['school-user-'.bin2hex(random_bytes(4)).'@example.invalid',password_hash('test-password',PASSWORD_DEFAULT),'Scoped Test School User']);$schoolId=(int)$db->lastInsertId();q($db,'INSERT INTO user_organization_scopes(user_id,organization_id) VALUES (?,2)',[$schoolId]);
    $schoolApp=new Domain($db,['id'=>$schoolId,'role'=>'SCHOOL']);expectFailure(fn()=>$schoolApp->detail(['organization_id'=>3,'type'=>'PR','id'=>$b['id']]),'cross-school document request');
    echo "All isolated integration assertions passed; rolling back fixture changes.\n";
    $db->rollBack();
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();fwrite(STDERR,$e->getMessage()."\n");exit(1);}
