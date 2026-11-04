<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/TextbookReceiptDistributionRules.php';
$checks=0;
function check(string $message,bool $condition):void{global $checks;if(!$condition)throw new RuntimeException('FAIL: '.$message);$checks++;echo 'PASS: '.$message."\n";}
function invalid(string $message,callable $fn):void{
    try{$fn();}catch(InvalidArgumentException $e){check($message,true);return;}
    throw new RuntimeException('FAIL: '.$message.' (expected validation error)');
}
$r=TextbookReceiptDistributionRules::externalReceiptLine(100,100,98,2,0);
check('Serviceable accepted into receiving office only', $r['available_after_acceptance']===98);
check('Damaged-on-arrival quarantined', $r['quarantined_after_acceptance']===2);
check('Damage flags discrepancy', $r['has_discrepancy']===true);
check('No shortage for full physical receipt', $r['shortage']===0);
$r=TextbookReceiptDistributionRules::externalReceiptLine(25,22,20,1,1);
check('Expected minus physical delivery is shortage', $r['shortage']===3);
check('Unaccepted remains pending', $r['unaccepted_pending']===1);
check('Accepted does not include rejected', $r['available_after_acceptance']===20);
$r=TextbookReceiptDistributionRules::externalReceiptLine(25,27,27,0,0);
check('CO overdelivery flagged rather than ignored', $r['overage']===2 && $r['has_discrepancy']);
invalid('No negative receipt counts',fn()=>TextbookReceiptDistributionRules::externalReceiptLine(10,-1,0,0,0));
invalid('Delivered classification must reconcile',fn()=>TextbookReceiptDistributionRules::externalReceiptLine(10,10,9,0,0));
invalid('Empty receipt line invalid',fn()=>TextbookReceiptDistributionRules::externalReceiptLine(0,0,0,0,0));
check('Division physical CO delivery path',TextbookReceiptDistributionRules::receivingPath('DIVISION')==='CO_TO_DIVISION');
check('Direct school CO delivery path',TextbookReceiptDistributionRules::receivingPath('SCHOOL')==='CO_TO_SCHOOL_DIRECT');
invalid('Other recipient cannot fabricate CO receiving workspace',fn()=>TextbookReceiptDistributionRules::receivingPath('CENTRAL_OFFICE'));
$d=TextbookReceiptDistributionRules::dispatchLine(100,0,40);
check('Division available reduced on dispatch', $d['available_after_dispatch']===60);
check('Dispatched quantity tracked as transit', $d['in_transit_increase']===40);
invalid('Cannot dispatch reserved units',fn()=>TextbookReceiptDistributionRules::dispatchLine(100,80,30));
invalid('No negative dispatch',fn()=>TextbookReceiptDistributionRules::dispatchLine(100,0,-1));
invalid('Zero dispatch not allowed',fn()=>TextbookReceiptDistributionRules::dispatchLine(100,0,0));
invalid('Reservation cannot exceed available',fn()=>TextbookReceiptDistributionRules::dispatchLine(50,51,1));
$a=TextbookReceiptDistributionRules::acceptDispatchLine(40,38,1);
check('Only serviceable credited available', $a['receiver_available_increase']===38);
check('Damaged school receipt quarantined', $a['receiver_quarantined_increase']===1);
check('Missing stays in transit/discrepancy', $a['in_transit_or_discrepancy_remaining']===1 && !$a['fully_reconciled']);
$a=TextbookReceiptDistributionRules::acceptDispatchLine(40,38,1,1);
check('Verified sender return closes remaining quantity', $a['source_return_increase']===1 && $a['fully_reconciled']);
invalid('Cannot accept more than dispatched',fn()=>TextbookReceiptDistributionRules::acceptDispatchLine(40,41,0));
invalid('Cannot count damaged and good twice',fn()=>TextbookReceiptDistributionRules::acceptDispatchLine(40,30,11));
invalid('Cannot acknowledge no dispatch',fn()=>TextbookReceiptDistributionRules::acceptDispatchLine(0,0,0));
check('Receipt draft may be submitted',TextbookReceiptDistributionRules::allows('CO_RECEIPT','DRAFT','SUBMITTED'));
check('Receipt draft cannot directly credit stock',!TextbookReceiptDistributionRules::allows('CO_RECEIPT','DRAFT','ACCEPTED'));
check('Inspected receipt may accept with discrepancy',TextbookReceiptDistributionRules::allows('CO_RECEIPT','INSPECTED','ACCEPTED_WITH_DISCREPANCY'));
check('Accepted receipt terminal',!TextbookReceiptDistributionRules::allows('CO_RECEIPT','ACCEPTED','DRAFT'));
check('Distribution must be approved before dispatch',!TextbookReceiptDistributionRules::allows('DISTRIBUTION','DRAFT','DISPATCHED'));
check('Authorized distribution may dispatch',TextbookReceiptDistributionRules::allows('DISTRIBUTION','APPROVED','DISPATCHED'));
check('School may accept partial dispatch',TextbookReceiptDistributionRules::allows('DISTRIBUTION','DISPATCHED','PARTIALLY_RECEIVED'));
check('Partial acceptance may close after resolution',TextbookReceiptDistributionRules::allows('DISTRIBUTION','PARTIALLY_RECEIVED','RECEIVED'));
check('Dispatched transaction cannot simply cancel',!TextbookReceiptDistributionRules::allows('DISTRIBUTION','DISPATCHED','CANCELLED'));
invalid('Unknown workflow kind rejected',fn()=>TextbookReceiptDistributionRules::allows('UNSUPPORTED','DRAFT','SUBMITTED'));
// End-to-end conservation example: Division receives 100, dispatches 40 and 30,
// School A accepts 40 and School B accepts 25. Five copies await reconciliation.
$co=TextbookReceiptDistributionRules::externalReceiptLine(100,100,100,0,0);
$afterA=TextbookReceiptDistributionRules::dispatchLine($co['available_after_acceptance'],0,40);
$afterB=TextbookReceiptDistributionRules::dispatchLine($afterA['available_after_dispatch'],0,30);
$schoolA=TextbookReceiptDistributionRules::acceptDispatchLine(40,40,0);
$schoolB=TextbookReceiptDistributionRules::acceptDispatchLine(30,25,0);
check('Division/A/B/transit conservation = 100', $afterB['available_after_dispatch']+$schoolA['receiver_available_increase']+$schoolB['receiver_available_increase']+$schoolB['in_transit_or_discrepancy_remaining']===100);
check('No double-credit before school accepts', $afterB['available_after_dispatch']===30 && $schoolB['receiver_available_increase']===25);
$direct=TextbookReceiptDistributionRules::externalReceiptLine(25,25,25,0,0);
check('Direct CO receipt school stock separately 25', $direct['available_after_acceptance']===25);
check('All offices with separate CO receipt + 125', 30+40+25+5+$direct['available_after_acceptance']===125);
echo "PASS: $checks textbook CO receipt and distribution rules tests\n";
