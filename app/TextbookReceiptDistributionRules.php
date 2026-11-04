<?php
declare(strict_types=1);
/**
 * Staged, side-effect-free rules for CO textbook receipts and office-to-office
 * distribution. Not wired to a live route or data schema. All inputs are copy
 * counts; no learner identifiers, SQL, inventory writes or approvals occur here.
 */
final class TextbookReceiptDistributionRules {
    private const RECEIPT = [
        'DRAFT' => ['SUBMITTED','CANCELLED'],
        'SUBMITTED' => ['INSPECTED','REJECTED'],
        'INSPECTED' => ['ACCEPTED','ACCEPTED_WITH_DISCREPANCY','REJECTED'],
        'ACCEPTED' => [],
        'ACCEPTED_WITH_DISCREPANCY' => [],
        'REJECTED' => [],
        'CANCELLED' => [],
    ];
    private const TRANSFER = [
        'DRAFT' => ['APPROVED','CANCELLED'],
        'APPROVED' => ['DISPATCHED','CANCELLED'],
        'DISPATCHED' => ['RECEIVED','PARTIALLY_RECEIVED','DISCREPANCY'],
        'PARTIALLY_RECEIVED' => ['RECEIVED','DISCREPANCY'],
        'DISCREPANCY' => ['PARTIALLY_RECEIVED','RECEIVED'],
        'RECEIVED' => [],
        'CANCELLED' => [],
    ];

    public static function allows(string $kind, string $current, string $next): bool {
        $states = match($kind) {
            'CO_RECEIPT' => self::RECEIPT,
            'DISTRIBUTION' => self::TRANSFER,
            default => throw new InvalidArgumentException('Unknown textbook document kind'),
        };
        return in_array($next,$states[$current]??[],true);
    }

    /**
     * Check the quantities observed at an external CO delivery. The accepted
     * serviceable and quarantined copies are stock only after formal acceptance.
     */
    public static function externalReceiptLine(
        int $expected,int $physicallyDelivered,int $acceptedServiceable,
        int $acceptedDamaged,int $rejectedOrHeld
    ): array {
        self::nonnegative(compact('expected','physicallyDelivered','acceptedServiceable','acceptedDamaged','rejectedOrHeld'));
        if($expected===0 && $physicallyDelivered===0)throw new InvalidArgumentException('Receipt line cannot be empty');
        if($acceptedServiceable+$acceptedDamaged+$rejectedOrHeld!==$physicallyDelivered)
            throw new InvalidArgumentException('Delivered copies must be fully classified');
        return [
            'shortage'=>max(0,$expected-$physicallyDelivered),
            'overage'=>max(0,$physicallyDelivered-$expected),
            'available_after_acceptance'=>$acceptedServiceable,
            'quarantined_after_acceptance'=>$acceptedDamaged,
            'unaccepted_pending'=>$rejectedOrHeld,
            'has_discrepancy'=>$expected!==$physicallyDelivered || $acceptedDamaged>0 || $rejectedOrHeld>0,
        ];
    }

    /** Validate a new dispatch against available serviceable stock only. */
    public static function dispatchLine(int $availableServiceable,int $reservedCommitments,int $toDispatch): array {
        self::nonnegative(compact('availableServiceable','reservedCommitments','toDispatch'));
        if($toDispatch===0)throw new InvalidArgumentException('Dispatch quantity must be positive');
        if($reservedCommitments>$availableServiceable || $toDispatch>$availableServiceable-$reservedCommitments)
            throw new InvalidArgumentException('Dispatch exceeds unreserved serviceable stock');
        return ['available_after_dispatch'=>$availableServiceable-$toDispatch,
            'in_transit_increase'=>$toDispatch];
    }

    /**
     * An acknowledged destination acceptance is the only receiver stock credit.
     * Remaining copies stay in a documented transit/exception pool, not credited
     * to either office. Returned copies require their own recorded return event.
     */
    public static function acceptDispatchLine(
        int $dispatched,int $acceptedServiceable,int $acceptedDamaged,int $returnedToSender=0
    ): array {
        self::nonnegative(compact('dispatched','acceptedServiceable','acceptedDamaged','returnedToSender'));
        if($dispatched===0)throw new InvalidArgumentException('No dispatched copies to accept');
        $resolved=$acceptedServiceable+$acceptedDamaged+$returnedToSender;
        if($resolved>$dispatched)throw new InvalidArgumentException('Accepted/returned copies exceed dispatched quantity');
        return [
            'receiver_available_increase'=>$acceptedServiceable,
            'receiver_quarantined_increase'=>$acceptedDamaged,
            'source_return_increase'=>$returnedToSender,
            'in_transit_or_discrepancy_remaining'=>$dispatched-$resolved,
            'fully_reconciled'=>$resolved===$dispatched,
        ];
    }

    /** Branching authority is defined by real physical delivery, never a fake receipt. */
    public static function receivingPath(string $physicalReceiverKind): string {
        return match ($physicalReceiverKind) {
            'DIVISION' => 'CO_TO_DIVISION',
            'SCHOOL' => 'CO_TO_SCHOOL_DIRECT',
            default => throw new InvalidArgumentException('Only a real Division or school receiving office is supported'),
        };
    }

    private static function nonnegative(array $counts): void {
        foreach($counts as $name=>$count){
            if($count<0)throw new InvalidArgumentException($name.' cannot be negative');
            if($count>999999999)throw new InvalidArgumentException($name.' exceeds supported count');
        }
    }
}
