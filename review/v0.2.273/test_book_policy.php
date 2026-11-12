<?php
declare(strict_types=1);
require_once __DIR__.'/TextbookBookProfilePolicy.php';
$passed=0;
function test(bool $ok,string $name): void {
    global $passed;
    if (!$ok) {fwrite(STDERR,"FAIL: $name\n");exit(1);}
    $passed++;
}
function bad(callable $f): bool {
    try {$f();return false;} catch (InvalidArgumentException $e) {return true;}
}
$p=TextbookBookProfilePolicy::normalise(['title'=>'Mathematics 7','material_type'=>'TEXTBOOK']);
test($p['isbn13']===null&&!$p['verification_ready'],'Minimal draft');
test($p['subject']==='','Optional subject');
test(!isset($p['quantity'])&&!isset($p['actor_id']),'Never trust stock/actor fields');
$f=TextbookBookProfilePolicy::normalise(['title'=>'Math','material_type'=>'TEXTBOOK','publisher'=>'DepEd','authors'=>'A, B','isbn'=>'0-306-40615-2']);
test($f['isbn13']==='9780306406157','ISBN10 to ISBN13');
test($f['verification_ready'],'Ready after valid identity');
test(TextbookBookProfilePolicy::canonicalIsbn('9780306406157')===$f['isbn13'],'ISBN equivalent');
test(bad(fn()=>TextbookBookProfilePolicy::canonicalIsbn('9780306406158')),'Invalid ISBN13 rejected');
test(bad(fn()=>TextbookBookProfilePolicy::canonicalIsbn('0-306-40615-3')),'Invalid ISBN10 rejected');
test(bad(fn()=>TextbookBookProfilePolicy::normalise(['material_type'=>'TEXTBOOK'])),'Title required');
test(bad(fn()=>TextbookBookProfilePolicy::normalise(['title'=>'X','material_type'=>'HARDWARE'])),'Type allowlist');
test(bad(fn()=>TextbookBookProfilePolicy::normalise(['title'=>'X','material_type'=>'TEXTBOOK','publication_year'=>'19000'])),'Year validated');
$lr=TextbookBookProfilePolicy::normalise(['title'=>'Reading kit','material_type'=>'OTHER','official_lr_code'=>' deped  lr/123 ']);
test($lr['identity_key']==='LR:DEPED LR/123','Official LR code');
$a=['role'=>'SCHOOL','authorized_organizations'=>[11]];
$b=['role'=>'SCHOOL','authorized_organizations'=>[12]];
$d=['role'=>'DIVISION','authorized_organizations'=>[5]];
$other=['role'=>'DIVISION','authorized_organizations'=>[6]];
$admin=['role'=>'SUPER_ADMIN','authorized_organizations'=>[5,11]];
test(TextbookBookProfilePolicy::canCreate($a,11,'SCHOOL'),'Receiving school can create');
test(!TextbookBookProfilePolicy::canCreate($a,12,'SCHOOL'),'Another school cannot create under origin');
test(TextbookBookProfilePolicy::canCreate($d,5,'DIVISION'),'Division can create');
test(!TextbookBookProfilePolicy::canCreate($admin,5,'DIVISION'),'No technical-admin impersonation');
test(TextbookBookProfilePolicy::canEdit($a,11,'SCHOOL',11,5),'Original school can edit');
test(TextbookBookProfilePolicy::canEdit($d,5,'DIVISION',11,5),'Supervising Division can edit');
test(!TextbookBookProfilePolicy::canEdit($b,12,'SCHOOL',11,5),'Other school cannot edit');
test(!TextbookBookProfilePolicy::canEdit($other,6,'DIVISION',11,5),'Other Division cannot edit');
test(!TextbookBookProfilePolicy::canEdit($admin,5,'DIVISION',11,5),'Super Admin cannot edit');
test(!TextbookBookProfilePolicy::canEdit($a,11,'SCHOOL',5,5),'School cannot edit Division origin');
test(TextbookBookProfilePolicy::canEdit($d,5,'DIVISION',5,5),'Division owns its origin');
test(!TextbookBookProfilePolicy::canEdit($d,11,'SCHOOL',11,5),'No school workspace spoof');
test((TextbookBookProfilePolicy::diff($p,$f)['publisher']['after']??'')==='DepEd','Auditable changes');
echo "PASS $passed isolated catalog policy tests\n";
