<?php
declare(strict_types=1);
require __DIR__.'/SemiExpendableIssuedReports.php';
function check(bool $condition,string $label):void { if(!$condition) throw new RuntimeException($label); echo "PASS ".$label.PHP_EOL; }
function denied(callable $fn,string $label):void { try{$fn();}catch(InvalidArgumentException|DomainException $e){echo "PASS ".$label.PHP_EOL;return;} throw new RuntimeException($label); }
$meta=['organization_id'=>8,'asset_id'=>21,'status'=>'FINALIZED','unit_cost_cents'=>12345,'document_number'=>'ICS-2026-001','custodian'=>'Sample Custodian','property_number'=>'SE-00021','description'=>'Sample property'];
$events=[
 array_merge($meta,['event_id'=>'01','date'=>'2026-10-01','type'=>'RECEIPT','quantity'=>4]),
 array_merge($meta,['event_id'=>'02','date'=>'2026-10-02','type'=>'ISSUE','quantity'=>2]),
 array_merge($meta,['event_id'=>'03','date'=>'2026-10-03','type'=>'RETURN','quantity'=>1]),
 array_merge($meta,['event_id'=>'04','date'=>'2026-10-04','type'=>'ISSUE','quantity'=>1,'status'=>'DRAFT'])
];
$r=SemiExpendableIssuedReports::build($events,8,'2026-10-02','2026-10-03');
check(count($r['annex_a4_registry_movements'])===2,'A4 includes issue and return movements');
check(count($r['annex_a7_issued_in_period'])===1,'A7 finalized period issue only');
check($r['annex_a7_issued_in_period'][0]['total_cents']===24690,'A7 integer-cents amount');
check(SemiExpendableIssuedReports::build($events,8,'2026-10-03','2026-10-03')['annex_a7_issued_in_period']===[],'A7 date exclusion');
denied(fn()=>SemiExpendableIssuedReports::build($events,9,'2026-10-01','2026-10-03'),'organization isolation');
denied(fn()=>SemiExpendableIssuedReports::build($events,8,'2026-10-04','2026-10-03'),'invalid date interval');
denied(fn()=>SemiExpendableIssuedReports::build([array_merge($meta,['event_id'=>'X','date'=>'2026-10-01','type'=>'ISSUE','quantity'=>1,'custodian'=>''])],8,'2026-10-01','2026-10-03'),'missing custodian denied');
