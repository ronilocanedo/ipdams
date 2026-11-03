<?php
declare(strict_types=1);
final class StubPDO extends PDO { public function __construct(){} }
final class StubRows { public function __construct(private array $rows){} public function fetchAll():array{return $this->rows;} }
final class TestRejected extends RuntimeException { public function __construct(public int $status,string $message){parent::__construct($message);} }
$GLOBALS['titles']=[];
$GLOBALS['queries']=[];
function q(PDO $db,string $sql,array $params=[]):StubRows {
    $GLOBALS['queries'][]=['sql'=>$sql,'args'=>$params];
    if(!preg_match('/^SELECT id,title,isbn,grade,subject FROM textbook_titles ORDER BY id ASC LIMIT 3001$/',$sql))throw new RuntimeException('Unexpected query '.$sql);
    return new StubRows(array_slice($GLOBALS['titles'],0,3001));
}
function one(PDO $db,string $sql,array $args=[]):?array {
    if(!str_contains($sql,"kind IN ('SCHOOL','DIVISION')"))throw new RuntimeException('Unauthorized office SQL');
    return in_array($args[0],[7,9],true)?['id'=>$args[0],'kind'=>$args[0]===7?'SCHOOL':'DIVISION']:null;
}
function demand(bool $ok,string $msg,int $status=400):void { if(!$ok)throw new TestRejected($status,$msg); }
function idOf(array $arr,string $field):int { $raw=$arr[$field]??'';demand(ctype_digit((string)$raw)&&(int)$raw>0,'Bad id');return (int)$raw; }
function scope(PDO $db,array $user,int $org):void {demand(in_array($org,$user['allow']??[],true),'Scope rejected',403);}
require_once dirname(__DIR__).'/app/TextbookDirectory.php';
$checks=0;
function pass(string $name,bool $ok):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name."\n";$checks++;}
function denied(string $name,array $user,array $args,int $expected):void {try{TextbookDirectory::read(new StubPDO(),$user,$args);}catch(TestRejected $e){pass($name,$e->status===$expected);return;}throw new RuntimeException($name.' must reject');}
$user=['role'=>'SCHOOL','allow'=>[7]];
$GLOBALS['titles']=[
 ['id'=>1,'title'=>'Science 5','isbn'=>'0-13-235088-2','grade'=>'5','subject'=>'Science'],
 ['id'=>2,'title'=>'Science 5','isbn'=>'9780132350884','grade'=>'5','subject'=>'Science'],
 ['id'=>3,'title'=>'Science Grade Five Other Edition','isbn'=>'978-0-13-235088-4','grade'=>'5','subject'=>'Science'],
 ['id'=>4,'title'=>'Mathematics 6','isbn'=>'9791090636071','grade'=>'6','subject'=>'Math'],
 ['id'=>5,'title'=>'DepEd Learning Packet','isbn'=>'','grade'=>'3','subject'=>'English'],
 ['id'=>6,'title'=>'Legacy Wrong ISBN','isbn'=>'9780132350883','grade'=>'4','subject'=>'Science'],
 ['id'=>7,'title'=>'Science 7','isbn'=>'9780804429573','grade'=>'7','subject'=>'Science']
];
$r=TextbookDirectory::read(new StubPDO(),$user,['organization_id'=>7]);
pass('Catalog read-only',($r['read_only']??false)===true);
pass('7 source titles',($r['summary']['scanned_title_rows']??0)===7);
pass('5 directory entries (canonical group plus 4 independent)',count($r['rows'])===5);
pass('One conflict group due to edition contradiction',($r['summary']['conflicting_isbn_groups']??0)===1);
pass('Duplicate ISBN counted once',($r['summary']['duplicate_isbn_groups']??0)===1);
pass('One ISBN absent flagged',($r['summary']['without_isbn_rows']??0)===1);
pass('One malformed ISBN flagged',($r['summary']['invalid_isbn_rows']??0)===1);
pass('Scanned catalog complete',($r['summary']['coverage_complete']??false)===true);
$g=array_values(array_filter($r['rows'],fn($x)=>$x['canonical_isbn']==='9780132350884'));
pass('ISBN-10 and ISBN-13 canonical group',count($g)===1&&$g[0]['source_rows']===3&&$g[0]['review']==='CONFLICT');
pass('Original source rows preserved',strpos($g[0]['source_ids'],'1')!==false&&strpos($g[0]['source_ids'],'3')!==false);
$search=TextbookDirectory::read(new StubPDO(),$user,['organization_id'=>7,'q'=>'0-13-235088-2']);
pass('ISBN10 finds canonical ISBN13',count($search['rows'])===1 && $search['rows'][0]['canonical_isbn']==='9780132350884');
$search=TextbookDirectory::read(new StubPDO(),$user,['organization_id'=>7,'q'=>'Math']);
pass('Title or subject search',count($search['rows'])===1 && $search['rows'][0]['title']==='Mathematics 6');
pass('Global bibliographic data contains no school balance or teacher details',array_intersect(['on_hand','teacher','organization_id','custodian'],array_keys($r['rows'][0]))===[]);
denied('VIEWER denied',['role'=>'VIEWER','allow'=>[7]],['organization_id'=>7],403);
denied('Other org denied',$user,['organization_id'=>9],403);
denied('Missing office denied',['role'=>'DIVISION','allow'=>[10]],['organization_id'=>10],403);
denied('Bad page denied',$user,['organization_id'=>7,'page'=>0],400);
denied('Long search denied',$user,['organization_id'=>7,'q'=>str_repeat('x',101)],400);
$GLOBALS['titles']=array_fill(0,3001,['id'=>0,'title'=>'Duplicated','isbn'=>'','grade'=>'','subject'=>'']);
foreach($GLOBALS['titles'] as $i=>&$entry)$entry['id']=$i+1;unset($entry);
$partial=TextbookDirectory::read(new StubPDO(),$user,['organization_id'=>7]);
pass('Large catalog coverage warned',($partial['summary']['coverage_complete']??true)===false&&$partial['summary']['scanned_title_rows']===3000);
pass('Bounded response size',count($partial['rows'])===50&&$partial['next_page']===2);
pass('Read-only SELECT only',array_reduce($GLOBALS['queries'],fn($good,$x)=>$good&&str_starts_with($x['sql'],'SELECT'),true));
$script=file_get_contents(dirname(__DIR__).'/public/api/index.php');
$gate=strpos($script,"if(in_array(\$route,['textbook.opening','textbook.move'],true))");
$begin=strpos($script,'$db->beginTransaction();');
pass('API blocked legacy writes BEFORE transaction',is_int($gate)&&is_int($begin)&&$gate<$begin);
pass('API GET directory has scope-guarded route',str_contains($script,"if(\$route==='textbook.directory')jsonOut(TextbookDirectory::read(\$db,\$user,\$_GET));"));
echo "$checks directory and safety regression tests passed.\n";
