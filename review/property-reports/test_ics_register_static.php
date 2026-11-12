<?php
declare(strict_types=1);
/** Static contract guards; database integration tests still required. */
$path=__DIR__.'/SemiExpendableIcsRegister.php';
$src=file_get_contents($path);
function requireContract(bool $valid,string $label):void {
    if(!$valid) throw new RuntimeException($label);
    echo "PASS ".$label.PHP_EOL;
}
requireContract(str_contains($src,'scope($this->db,$this->user,$org)'),'server-side organization scope');
requireContract(str_contains($src,"d.status='FINALIZED'"),'only finalized ICS');
requireContract(str_contains($src,"i.category='SEMI_EXPENDABLE'"),'semi-expendable category');
requireContract(str_contains($src,'a.organization_id=d.organization_id'),'asset organization join');
requireContract(str_contains($src,'LIMIT 5001')&&str_contains($src,'count($rows)<=5000'),'reject truncated results');
requireContract(!preg_match('/\b(?:INSERT|UPDATE|DELETE)\b/i',$src),'read-only SQL');
