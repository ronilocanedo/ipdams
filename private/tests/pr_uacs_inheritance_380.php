<?php
declare(strict_types=1);
require_once is_file(__DIR__.'/PrUacsInheritance.php')
    ? __DIR__.'/PrUacsInheritance.php'
    : dirname(__DIR__).'/app/PrUacsInheritance.php';
function check(bool $condition,string $label): void {if(!$condition)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
$mapping=[['item_id'=>17,'uacs_code'=>'5021305002','effective_from'=>'2026-11-12']];
$miss=PrUacsInheritance::choose(17,'2026-11-05',$mapping,[]);
check(!$miss['ok'],'DV12 original PR date predates item classification');
$valid=PrUacsInheritance::choose(17,'2026-11-12',$mapping,[]);
check($valid['ok'] && $valid['uacs_code']==='5021305002','valid same-day PR resolves to approved Division debit');
$old=PrUacsInheritance::choose(17,'2026-11-05',[],[['uacs_code'=>'5021305002','effective_from'=>'2026-11-01']]);
check($old['ok'] && $old['source']==='LEGACY_GLOBAL','legacy global mapping only when Division mapping is absent');
$duplicate=PrUacsInheritance::choose(17,'2026-11-12',array_merge($mapping,$mapping),[]);
check(!$duplicate['ok'],'ambiguous same-day Division mappings fail closed');
$badCode=PrUacsInheritance::choose(17,'2026-11-12', [['item_id'=>17,'uacs_code'=>'2020101000','effective_from'=>'2026-11-12']],[]);
check(!$badCode['ok'],'tax liability cannot be a PR debit account');
$snapshot=['format'=>1,'pr_id'=>14,'pr_document_date'=>'2026-11-12','lines'=>[['pr_line_id'=>31,'item_id'=>17,'uacs_code'=>'5021305002','effective_from'=>'2026-11-12']]];
$inherit=PrUacsInheritance::inherited($snapshot,14,31,17,'2026-11-12');
check($inherit['ok'] && $inherit['uacs_code']==='5021305002','DV inherits immutable PR line UACS');
check(!PrUacsInheritance::inherited($snapshot,14,31,17,'2026-11-05')['ok'],'inconsistent PR date fails closed');
check(!PrUacsInheritance::inherited($snapshot,14,32,17,'2026-11-12')['ok'],'changed PR line ID fails closed');
check(!PrUacsInheritance::inherited($snapshot,14,31,18,'2026-11-12')['ok'],'changed catalog item fails closed');
$future=$snapshot;$future['lines'][0]['effective_from']='2026-11-13';
check(!PrUacsInheritance::inherited($future,14,31,17,'2026-11-12')['ok'],'future mapping in snapshot fails closed');
$dupe=$snapshot;$dupe['lines'][]=$dupe['lines'][0];
check(!PrUacsInheritance::inherited($dupe,14,31,17,'2026-11-12')['ok'],'duplicate snapshot lines fail closed');
echo "Synthetic pure-rule regression checks complete (11 PASS).\n";
