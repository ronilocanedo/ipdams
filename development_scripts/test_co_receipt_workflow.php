<?php
declare(strict_types=1);
final class FakePDO extends PDO {
    public bool $tx=true;
    public int $last=0;
    public function __construct(){}
    public function inTransaction():bool{return $this->tx;}
    public function lastInsertId(?string $name=null):string|false{return (string)$this->last;}
}
final class FakeRows {
    public function __construct(private array $rows=[], private int $affected=1){}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,...$args):array{return $this->rows;}
    public function rowCount():int{return $this->affected;}
}
final class Rejected extends RuntimeException { public function __construct(string $message,public int $status=422){parent::__construct($message);} }
$GLOBALS['state'] = [];
function resetState():void {
  $GLOBALS['state']=[
    'organizations'=>[7=>['id'=>7,'kind'=>'SCHOOL','active'=>1],9=>['id'=>9,'kind'=>'DIVISION','active'=>1]],
    'allowed'=>[7,9],
    'titles'=>[
      ['id'=>10,'title'=>'Science 5','isbn'=>'0-13-235088-2','grade'=>'5','subject'=>'Science'],
      ['id'=>11,'title'=>'Science 5','isbn'=>'9780132350884','grade'=>'5','subject'=>'Science'],
      ['id'=>12,'title'=>'Mathematics 6','isbn'=>'9791090636071','grade'=>'6','subject'=>'Math'],
    ],
    'receipts'=>[], 'lines'=>[], 'batches'=>[], 'movements'=>[], 'postings'=>[], 'audit'=>[],
    'next_receipt'=>100,'next_line'=>200,'next_batch'=>300,'next_move'=>400,'next_post'=>500,
    'appointment'=>['id'=>17,'config_json'=>'{"employee_id":22}','effective_from'=>'2025-01-01'],
    'binding'=>['config_json'=>'{"employee_id":22,"appointment_version":17,"user_id":42}'],
    'assignment'=>['employee_id'=>22],
  ];
}
function demand(bool $ok,string $msg,int $status=400):void { if(!$ok)throw new Rejected($msg,$status); }
function scope(PDO $db,array $user,int $org):void { demand(in_array($org,$user['allows']??[],true),'Wrong office',403); }
function one(PDO $db,string $sql,array $args=[]):?array {
 $s=&$GLOBALS['state'];
 if(str_contains($sql,'FROM organizations'))return $s['organizations'][$args[0]]??null;
 if(str_contains($sql,'FROM textbook_co_receipts')){
   if(str_contains($sql,'co_delivery_reference'))foreach($s['receipts'] as $r)if($r['organization_id']===$args[0] && $r['co_delivery_reference']===$args[1])return ['id'=>$r['id']];
   if(str_contains($sql,'WHERE id=? AND organization_id=?')){ $r=$s['receipts'][$args[0]]??null;return $r&&$r['organization_id']===$args[1]?$r:null; }
   return null;
 }
 if(str_contains($sql,'FROM configuration_versions'))return ($args[1]??'')==='ORG_SUPPLY_PROPERTY_CUSTODIAN_V1'?$s['appointment']:$s['binding'];
 if(str_contains($sql,'FROM employee_assignments'))return $s['assignment'];
 throw new RuntimeException('Unexpected SQL one(): '.$sql);
}
function q(PDO $db,string $sql,array $args=[]):FakeRows {
 $s=&$GLOBALS['state'];
 if(str_starts_with($sql,'SELECT id,title,isbn,grade,subject FROM textbook_titles'))return new FakeRows($s['titles']);
 if(str_starts_with($sql,'INSERT INTO textbook_co_receipts ')){
   $id=$s['next_receipt']++;$db->last=$id;$s['receipts'][$id]=[
     'id'=>$id,'organization_id'=>$args[0],'source_kind'=>$args[1],'receipt_path'=>$args[2],
     'co_delivery_reference'=>$args[3],'received_on'=>$args[4],'remarks'=>$args[5],
     'status'=>$args[6],'created_by'=>$args[7],'inspected_by'=>null,'accepted_by'=>null,
   ];return new FakeRows();
 }
 if(str_starts_with($sql,'INSERT INTO textbook_co_receipt_lines(')){
   $id=$s['next_line']++;$db->last=$id;$s['lines'][$id]=[
     'id'=>$id,'receipt_id'=>$args[0],'title_id'=>$args[1],'isbn13'=>$args[2],
     'expected_quantity'=>$args[3],'source_batch'=>$args[4],'storage'=>$args[5],
     'delivered_quantity'=>null,'serviceable_quantity'=>null,'damaged_quantity'=>null,'held_quantity'=>null,
   ];return new FakeRows();
 }
 if(str_starts_with($sql,'SELECT id,expected_quantity FROM textbook_co_receipt_lines')){
   return new FakeRows(array_map(fn($r)=>['id'=>$r['id'],'expected_quantity'=>$r['expected_quantity']],array_values(array_filter($s['lines'],fn($r)=>$r['receipt_id']===$args[0]))));
 }
 if(str_starts_with($sql,'SELECT * FROM textbook_co_receipt_lines'))return new FakeRows(array_values(array_filter($s['lines'],fn($r)=>$r['receipt_id']===$args[0])));
 if(str_starts_with($sql,'UPDATE textbook_co_receipt_lines SET')){
   foreach(['delivered_quantity','serviceable_quantity','damaged_quantity','held_quantity'] as $i=>$k)$s['lines'][$args[4]][$k]=$args[$i];return new FakeRows();
 }
 if(str_starts_with($sql,'UPDATE textbook_co_receipts SET status=')){
   if(str_contains($sql,'submitted_at'))$s['receipts'][$args[1]]['status']='SUBMITTED';
   elseif(str_contains($sql,'inspected_by')){ $s['receipts'][$args[4]]['status']='INSPECTED';$s['receipts'][$args[4]]['inspected_by']=$args[1]; }
   elseif(str_contains($sql,'accepted_by')){ $s['receipts'][$args[5]]['status']=$args[0];$s['receipts'][$args[5]]['accepted_by']=$args[1]; }
   else $s['receipts'][$args[1]]['status']=$args[0];
   return new FakeRows();
 }
 if(str_starts_with($sql,'INSERT INTO textbook_batches(')){
   $id=$s['next_batch']++;$db->last=$id;$s['batches'][$id]=['id'=>$id,'organization_id'=>$args[0],'title_id'=>$args[1],'batch'=>$args[2],'condition_note'=>$args[3],'storage'=>$args[4],'opening_record'=>$args[5]];return new FakeRows();
 }
 if(str_starts_with($sql,'INSERT INTO textbook_movements(')){
   $id=$s['next_move']++;$db->last=$id;$s['movements'][$id]=['id'=>$id,'organization_id'=>$args[0],'batch_id'=>$args[1],'quantity_delta'=>$args[2],'event_type'=>$args[3],'reference'=>$args[4],'actor_id'=>$args[5]];return new FakeRows();
 }
 if(str_starts_with($sql,'INSERT INTO textbook_co_receipt_postings(')){
   $id=$s['next_post']++;$db->last=$id;$s['postings'][$id]=['receipt_line_id'=>$args[0],'batch_id'=>$args[1],'condition_kind'=>$args[2],'quantity'=>$args[3]];return new FakeRows();
 }
 if(str_starts_with($sql,'INSERT INTO audit_events(')){$s['audit'][]=['org'=>$args[0],'actor'=>$args[1],'event'=>$args[2],'receipt'=>$args[4],'json'=>$args[5]];return new FakeRows();}
 if(str_starts_with($sql,'SELECT r.id,r.receipt_path')){
    return new FakeRows(array_values(array_filter($s['receipts'],fn($x)=>$x['organization_id']===$args[0])));
 }
 if(str_starts_with($sql,'SELECT l.id,l.isbn13'))return new FakeRows(array_values(array_filter($s['lines'],fn($x)=>$x['receipt_id']===$args[0])));
 throw new RuntimeException('Unexpected SQL q(): '.$sql);
}
require_once dirname(__DIR__).'/app/TextbookCoReceiptWorkflow.php';
$checks=0;
function ok(string $name,bool $passed):void {global $checks; if(!$passed)throw new RuntimeException('FAIL '.$name);$checks++;echo 'PASS '.$name."\n";}
function denied(string $name,callable $fn,int $code):void {try{$fn();}catch(Rejected $e){if($e->status!==$code)fwrite(STDERR,"DENIAL WRONG: $name expected $code got {$e->status} {$e->getMessage()}\n");ok($name,$e->status===$code);return;}throw new RuntimeException('FAIL: '.$name.' allowed');}
function actor(int $uid,string $role,int $org):TextbookCoReceiptWorkflow {return new TextbookCoReceiptWorkflow(new FakePDO(),['id'=>$uid,'role'=>$role,'allows'=>[$org]]);}
function draft(int $org=9):array {return ['organization_id'=>$org,'co_delivery_reference'=>'CO-2026-BOX-123','received_on'=>'2026-11-01','remarks'=>'CO shipment with signed delivery receipt','lines'=>[
 ['isbn'=>'0-13-235088-2','expected_quantity'=>100,'source_batch'=>'CO/2026/A','storage'=>'Textbook room 1']]];}
resetState();
$division=actor(41,'DIVISION',9);$school=actor(41,'SCHOOL',7);$inspector=actor(43,'DIVISION',9);$custodian=actor(42,'DIVISION',9);
$create=$division->create(draft());
ok('Division draft created', $create['status']==='DRAFT'&&$create['id']===100);
ok('CO to Division path', $GLOBALS['state']['receipts'][100]['receipt_path']==='CO_TO_DIVISION');
ok('Canonical ISBN is stable', $GLOBALS['state']['lines'][200]['isbn13']==='9780132350884');
ok('Duplicate legacy metadata not double-created',count($GLOBALS['state']['lines'])===1);
ok('No stock movement on draft',count($GLOBALS['state']['movements'])===0);
ok('No stock batch on draft',count($GLOBALS['state']['batches'])===0);
denied('Duplicate CO delivery reference rejected',fn()=>$division->create(draft()),409);
denied('Wrong office role rejected',fn()=>actor(41,'SCHOOL',9)->create(draft()),403);
denied('Scoped school cannot create Division receipt',fn()=>actor(41,'SCHOOL',7)->create(draft()),403);
denied('Admin cannot impersonate custodian',fn()=>actor(99,'SUPER_ADMIN',9)->create(draft()),403);
$double=draft(7);$double['lines'][]=$double['lines'][0];denied('Duplicate ISBN per receipt rejected',fn()=>$school->create($double),409);
$invalid=draft(7);$invalid['lines'][0]['isbn']='9780132350883';denied('Invalid ISBN blocked',fn()=>$school->create($invalid),422);
$missing=draft(7);$missing['lines'][0]['isbn']='9780804429573';denied('Catalog review required for missing ISBN',fn()=>$school->create($missing),409);
$badQty=draft(7);$badQty['lines'][0]['expected_quantity']=-1;denied('No negative expected counts',fn()=>$school->create($badQty),422);
$future=draft(7);$future['received_on']='2040-01-01';denied('No future delivery dates',fn()=>$school->create($future),422);
$tooMany=draft(7);$tooMany['lines']=array_fill(0,41,$tooMany['lines'][0]);denied('Bounded lines',fn()=>$school->create($tooMany),422);
$submit=$division->submit(['organization_id'=>9,'receipt_id'=>100]);ok('Submitted status', $submit['status']==='SUBMITTED');
ok('No stock posted on submit',count($GLOBALS['state']['movements'])===0);
denied('Only draft may submit',fn()=>$division->submit(['organization_id'=>9,'receipt_id'=>100]),409);
denied('Other preparer may not submit',fn()=>actor(44,'DIVISION',9)->submit(['organization_id'=>9,'receipt_id'=>100]),403);
$inspection=['organization_id'=>9,'receipt_id'=>100,'inspection_reference'=>'IAR-CO-2026-010','inspection_note'=>'Physical inspection and signed official inspection record',
  'lines'=>[['line_id'=>200,'delivered_quantity'=>99,'serviceable_quantity'=>96,'damaged_quantity'=>2,'held_quantity'=>1]]];
$bad=$inspection;$bad['lines'][0]['serviceable_quantity']=97;denied('Reconcile all delivered copies',fn()=>$inspector->inspect($bad),422);
$inspected=$inspector->inspect($inspection);ok('Inspection saves', $inspected['status']==='INSPECTED');
ok('No stock posted before final acceptance',count($GLOBALS['state']['movements'])===0);
denied('Same inspector cannot accept',fn()=>$inspector->accept(['organization_id'=>9,'receipt_id'=>100,'signed_acceptance_reference'=>'SIGNED-2026-010','accepted_on'=>'2026-11-02']),403);
denied('Unbound receiving login cannot accept',fn()=>actor(44,'DIVISION',9)->accept(['organization_id'=>9,'receipt_id'=>100,'signed_acceptance_reference'=>'SIGNED-2026-010','accepted_on'=>'2026-11-02']),403);
denied('Acceptance cannot predate delivery',fn()=>$custodian->accept(['organization_id'=>9,'receipt_id'=>100,'signed_acceptance_reference'=>'SIGNED-2026-010','accepted_on'=>'2026-10-31']),422);
$result=$custodian->accept(['organization_id'=>9,'receipt_id'=>100,'signed_acceptance_reference'=>'SIGNED-2026-010','accepted_on'=>'2026-11-02']);
ok('Receipt accepted with discrepancy', $result['status']==='ACCEPTED_WITH_DISCREPANCY'&&$result['accepted_copies']===98);
ok('Two condition-separated lots',count($GLOBALS['state']['batches'])===2);
$stocks=array_values($GLOBALS['state']['batches']);ok('Serviceable lot has correct owner', $stocks[0]['organization_id']===9&&$stocks[0]['condition_note']==='Serviceable');
ok('Damaged lot quarantined', $stocks[1]['condition_note']==='Damaged - Quarantined');
ok('Receipt movement quantity conserved',array_sum(array_column($GLOBALS['state']['movements'],'quantity_delta'))===98);
ok('No school stock credited for Division receipt',array_sum(array_map(fn($m)=>$m['organization_id']===7?$m['quantity_delta']:0,$GLOBALS['state']['movements']))===0);
ok('Posting linkage for both lots',count($GLOBALS['state']['postings'])===2);
ok('Audit recorded all stages',count($GLOBALS['state']['audit'])===4);
denied('No second acceptance',fn()=>$custodian->accept(['organization_id'=>9,'receipt_id'=>100,'signed_acceptance_reference'=>'SIGNED-2026-010','accepted_on'=>'2026-11-02']),409);
$schoolDraft=$school->create(draft(7));ok('Direct school receipt created',$GLOBALS['state']['receipts'][$schoolDraft['id']]['receipt_path']==='CO_TO_SCHOOL_DIRECT');
ok('Division reference may also appear for a different direct receiver',$schoolDraft['id']===101);
$school->submit(['organization_id'=>7,'receipt_id'=>101]);
$schoolInspector=actor(43,'SCHOOL',7);$schoolInspector->inspect(['organization_id'=>7,'receipt_id'=>101,'inspection_reference'=>'IAR-SCHOOL-005','inspection_note'=>'Direct Central Office signed school inspection',
'lines'=>[['line_id'=>201,'delivered_quantity'=>100,'serviceable_quantity'=>100,'damaged_quantity'=>0,'held_quantity'=>0]]]);
// The school custodian has independently appointed and linked account.
$GLOBALS['state']['binding']=['config_json'=>'{"employee_id":22,"appointment_version":17,"user_id":42}'];
$schoolCustodian=actor(42,'SCHOOL',7);
$accepted=$schoolCustodian->accept(['organization_id'=>7,'receipt_id'=>101,'signed_acceptance_reference'=>'SIGNED-SCHOOL-2026-05','accepted_on'=>'2026-11-02']);
ok('Direct school receipt accepted',$accepted['status']==='ACCEPTED'&&$accepted['accepted_copies']===100);
ok('School receipt credits school only',array_sum(array_map(fn($m)=>$m['organization_id']===7?$m['quantity_delta']:0,$GLOBALS['state']['movements']))===100);
ok('Division remains 98 from its own acceptance',array_sum(array_map(fn($m)=>$m['organization_id']===9?$m['quantity_delta']:0,$GLOBALS['state']['movements']))===98);
ok('User-specific office filter on list',count($school->list(['organization_id'=>7]))===1);
denied('Other office cannot read receipt',fn()=>$school->detail(['organization_id'=>7,'receipt_id'=>100]),404);
// Separate signed rejection and draft cancellation must never credit stock.
$preCount=count($GLOBALS['state']['movements']);
$forCancel=draft(7);$forCancel['co_delivery_reference']='CO-CANCEL-2026';
$cancel=$school->create($forCancel);
denied('Other preparer may not cancel draft',fn()=>actor(55,'SCHOOL',7)->cancelDraft([
  'organization_id'=>7,'receipt_id'=>$cancel['id'],'cancellation_reason'=>'Cancelled upon official pre-submission review']),403);
$cancelled=$school->cancelDraft(['organization_id'=>7,'receipt_id'=>$cancel['id'],
 'cancellation_reason'=>'Delivery reference was entered against wrong draft']);
ok('Draft cancellation terminal without inventory post',$cancelled['status']==='CANCELLED'&&count($GLOBALS['state']['movements'])===$preCount);
$forReject=draft(7);$forReject['co_delivery_reference']='CO-REJECT-2026';
$rejectId=$school->create($forReject)['id'];
$school->submit(['organization_id'=>7,'receipt_id'=>$rejectId]);
denied('Ordinary preparer cannot reject delivery',fn()=>$school->reject([
 'organization_id'=>7,'receipt_id'=>$rejectId,'rejection_reason'=>'Official delivery rejected for damaged materials',
 'rejection_evidence_reference'=>'IAR-REJECT-2026-01']),403);
$rejected=$schoolCustodian->reject(['organization_id'=>7,'receipt_id'=>$rejectId,
 'rejection_reason'=>'Signed physical delivery rejected due to identified defects',
 'rejection_evidence_reference'=>'IAR-REJECT-2026-01']);
ok('Appointed custodian may reject with evidence, without stock post',$rejected['status']==='REJECTED'&&count($GLOBALS['state']['movements'])===$preCount);
$forInspection=draft(7);$forInspection['co_delivery_reference']='CO-INSPECT-2026';
$inspectId=$school->create($forInspection)['id'];$school->submit(['organization_id'=>7,'receipt_id'=>$inspectId]);
$inspectLineId=$GLOBALS['state']['next_line']-1;
denied('Appointed custodian cannot self-inspect pending CO receipt',fn()=>$schoolCustodian->inspect([
 'organization_id'=>7,'receipt_id'=>$inspectId,
 'inspection_reference'=>'IAR-SCHOOL-SELF-10','inspection_note'=>'Signed inspection workpaper delivered',
 'lines'=>[['line_id'=>$inspectLineId,'delivered_quantity'=>100,'serviceable_quantity'=>100,'damaged_quantity'=>0,'held_quantity'=>0]]]),403);
// Conflicting legacy ISBN must never be chosen silently.
resetState();$GLOBALS['state']['titles'][]=['id'=>99,'title'=>'Other edition','isbn'=>'9780132350884','grade'=>'5','subject'=>'Science'];
denied('Bibliographic ISBN conflict blocked',fn()=>actor(41,'SCHOOL',7)->create(draft(7)),409);
resetState();$GLOBALS['state']['titles']=array_fill(0,3001,['id'=>1,'title'=>'Test','isbn'=>'','grade'=>'','subject'=>'']);
denied('Incomplete legacy ISBN audit blocks intake',fn()=>actor(41,'SCHOOL',7)->create(draft(7)),409);
echo "PASS: $checks CO receipt workflow regression tests\n";
