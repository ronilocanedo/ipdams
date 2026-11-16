<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
function eq(mixed $actual,mixed $expected,string $label): void {if($actual!==$expected)throw new RuntimeException("$label: expected ".var_export($expected,true).' but got '.var_export($actual,true));echo "PASS $label\n";}
eq(moneyCents('10.20'),1020,'exact money parsing');
eq(moneyString(1020),'10.20','fixed two-decimal formatting');
eq(qtyMillis('1.125'),1125,'quantity in thousandths');
eq(totalCents(1999,1250),2499,'monetary multiplication rounded half-up');
eq(taxCents(10000,'0.050000'),500,'effective withholding math');
eq(taxCents(1555,'0.025000'),39,'tax half-up rounding');
try { moneyCents('3.456');throw new RuntimeException('Malformed amount accepted'); }catch(DomainError $e){eq($e->status,422,'reject monetary overprecision');}
try { qtyMillis('1.2345');throw new RuntimeException('Malformed qty accepted'); }catch(DomainError $e){eq($e->status,422,'reject quantity overprecision');}
try { taxCents(1000,'1.500000');throw new RuntimeException('Out-of-range tax accepted'); }catch(DomainError $e){eq($e->status,422,'reject invalid tax rate');}
echo "Unit tests complete.\n";
