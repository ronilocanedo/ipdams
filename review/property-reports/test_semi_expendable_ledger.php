<?php
declare(strict_types=1);
require __DIR__.'/SemiExpendableLedger.php';
function assertReport(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function rejects(callable $f,string $name):void{try{$f();}catch(InvalidArgumentException|DomainException $e){echo "PASS $name\n";return;}throw new RuntimeException($name);}
$base=['organization_id'=>2,'asset_id'=>9,'unit_cost_cents'=>150000,'status'=>'FINALIZED'];
$events=[
 array_merge($base,['event_id'=>'a','date'=>'2026-10-01','type'=>'RECEIPT','quantity'=>3]),
 array_merge($base,['event_id'=>'b','date'=>'2026-10-02','type'=>'ISSUE','quantity'=>2]),
 array_merge($base,['event_id'=>'c','date'=>'2026-10-03','type'=>'RETURN','quantity'=>1]),
 array_merge($base,['event_id'=>'d','date'=>'2026-10-04','type'=>'ISSUE','quantity'=>1,'status'=>'DRAFT'])
];
$r=SemiExpendableLedger::project($events,2,'2026-10-04');
assertReport($r['closing_quantities'][9]===2,'finalized running balance');
assertReport(count($r['rows'])===3,'draft excluded');
assertReport(SemiExpendableLedger::project($events,2,'2026-10-01')['closing_quantities'][9]===3,'historical cutoff');
rejects(fn()=>SemiExpendableLedger::project([array_merge($events[0],['organization_id'=>3])],2,'2026-10-04'),'cross-scope denial');
rejects(fn()=>SemiExpendableLedger::project([$events[0],$events[0]],2,'2026-10-04'),'duplicate event denial');
rejects(fn()=>SemiExpendableLedger::project([array_merge($events[0],['type'=>'ISSUE'])],2,'2026-10-04'),'negative balance denial');
rejects(fn()=>SemiExpendableLedger::project([array_merge($events[0],['date'=>'2026-02-30'])],2,'2026-10-04'),'invalid date denial');
