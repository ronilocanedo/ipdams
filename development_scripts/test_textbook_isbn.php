<?php
declare(strict_types=1);
require_once __DIR__.'/../app/TextbookIsbn.php';

$checks=0;
function ok(string $name, mixed $expected, mixed $actual): void {
    global $checks;
    if ($expected !== $actual) throw new RuntimeException($name.': expected '.var_export($expected,true).' got '.var_export($actual,true));
    ++$checks;
    echo "PASS $name\n";
}
function invalid(string $name,string $isbn): void {
    global $checks;
    try{TextbookIsbn::canonical($isbn);}catch(InvalidArgumentException){++$checks;echo "PASS $name\n";return;}
    throw new RuntimeException($name.': should have been rejected');
}

ok('ISBN-13 canonical','9780132350884',TextbookIsbn::canonical('9780132350884'));
ok('ISBN-13 with hyphens','9780132350884',TextbookIsbn::canonical('978-0-13-235088-4'));
ok('ISBN-13 with whitespace','9780132350884',TextbookIsbn::canonical("  978 0 13 235088 4\t"));
ok('ISBN-10 equivalent to 13','9780132350884',TextbookIsbn::canonical('0-13-235088-2'));
ok('ISBN-10 with X check digit','9780804429573',TextbookIsbn::canonical('080442957X'));
ok('ISBN-13 979-prefix','9791090636071',TextbookIsbn::canonical('9791090636071'));
ok('No ISBN returns null',null,TextbookIsbn::canonicalOrNull(''));
ok('Blank ISBN returns null',null,TextbookIsbn::canonicalOrNull('   '));
invalid('Bad ISBN-13 checksum','9780132350883');
invalid('Bad ISBN-10 checksum','0132350883');
invalid('ISBN-13 X invalid','978013235088X');
invalid('ISBN-9 invalid','123456789');
invalid('Extra letters invalid','ISBN 9780132350884');
invalid('No placeholder ISBN','NO ISBN');
invalid('No title-only dedupe','Science 5');
invalid('Too long ISBN',str_repeat('8',90));
invalid('Empty is not a required canonical ISBN','');
echo "$checks ISBN tests passed; no database accessed.\n";
