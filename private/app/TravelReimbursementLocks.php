<?php
declare(strict_types=1);
require_once __DIR__.'/ReimbursementFinalization.php';
/** Prevent any underlying RC/expense documentary mutation after finalization. */
final class TravelReimbursementLocks {
    public static function editable(PDO $db,int $org,int $claimId): void {
        demand(ReimbursementFinalization::load($db,$org,'T',$claimId)===null,
            'This reimbursement was finalized and its itinerary, documents and expenses are locked. Open its numbered record.',409);
    }
}
