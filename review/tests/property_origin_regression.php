<?php
declare(strict_types=1);

// Synthetic-only regression: edit payloads contain the persisted origin marker,
// which must be accepted only when it matches the verified asset source.
class DomainError extends RuntimeException {
    public function __construct(string $message, public int $status = 400) {
        parent::__construct($message);
    }
}
function demand(mixed $condition, string $message, int $status = 400): void {
    if (!$condition) throw new DomainError($message, $status);
}
function mbSafeLen(string $value): int { return strlen($value); }
require dirname(__DIR__) . '/app/PropertyBatchMetadata.php';

$shared = PropertyBatchMetadata::shared(
    ['brand' => 'Synthetic Printer', 'model' => 'M1', 'historical_po' => 'SYNTH-PO'],
    'HISTORICAL_OPENING'
);
if (PropertyBatchMetadata::shared($shared, 'HISTORICAL_OPENING')['origin'] !== 'HISTORICAL_OPENING') {
    throw new RuntimeException('Stored historical property origin was incorrectly rejected');
}
$forged = $shared;
$forged['origin'] = 'VERIFIED_IAR';
try {
    PropertyBatchMetadata::shared($forged, 'HISTORICAL_OPENING');
    throw new RuntimeException('Origin spoofing unexpectedly accepted');
} catch (DomainError $error) {
    if ($error->status !== 409) throw $error;
}
$verified = PropertyBatchMetadata::shared(['brand' => 'Synthetic Computer'], 'VERIFIED_IAR');
if (PropertyBatchMetadata::shared($verified, 'VERIFIED_IAR')['origin'] !== 'VERIFIED_IAR') {
    throw new RuntimeException('Stored verified IAR origin was incorrectly rejected');
}
try {
    PropertyBatchMetadata::shared(['origin' => 'HISTORICAL_OPENING'], 'VERIFIED_IAR');
    throw new RuntimeException('Forged IAR origin unexpectedly accepted');
} catch (DomainError $error) {
    if ($error->status !== 409) throw $error;
}
echo "PASS: saved origin accepted, forged origin denied for historical and verified IAR properties\n";
