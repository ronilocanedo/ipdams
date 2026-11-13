<?php
declare(strict_types=1);
/**
 * Financially non-operative policy preview. It MUST NOT be used as DV payment
 * authorization until a separate, transactional fund-specific bank-cash ledger
 * and retained-withholding spending-authority ledger have been reviewed.
 * The caller MUST supply amounts derived from trusted server records, NEVER
 * an untrusted browser field. No database writes or bank postings occur.
 */
final class FundCashFundingPreview {
    public static function assess(
        string $classification, string $policy, int $gross, int $withheld,
        int $ordinaryAvailable, ?int $verifiedUncommittedCash=null,
        ?int $confirmedUnusedRetained=null
    ): array {
        if($gross<0 || $withheld<0 || $withheld>$gross) {
            throw new InvalidArgumentException('Invalid gross or withheld amounts');
        }
        if($verifiedUncommittedCash!==null && $verifiedUncommittedCash<0) {
            throw new InvalidArgumentException('Invalid bank cash amount');
        }
        if($confirmedUnusedRetained!==null && $confirmedUnusedRetained<0) {
            throw new InvalidArgumentException('Invalid retained withholding amount');
        }
        $bankBasis=$classification==='NON_IUS' && $policy==='DIVISION_SETTLES_UTILIZABLE';
        $net=$gross-$withheld;
        $ordinaryAvailableForDv=max(0,$ordinaryAvailable);
        $grossShortfall=max(0,$gross-$ordinaryAvailableForDv);
        $cashPass=$verifiedUncommittedCash!==null ? $verifiedUncommittedCash >= $net : null;
        $retainedAuthorityPass=$confirmedUnusedRetained!==null
            ? $confirmedUnusedRetained >= $grossShortfall : null;
        // A 'true' cash comparison is never, by itself, authorization to spend.
        // IMPORTANT: THIS IS A SHADOW PREVIEW. NO DV FINALIZATION CODE CALLS IT.
        return [
            'mode'=>$bankBasis?'NON_IUS_BANK_CASH_CANDIDATE':'EXISTING_GROSS_ALLOCATION',
            'classification'=>$classification ?: 'UNVERIFIED',
            'withholding_policy'=>$policy ?: 'NOT_CONFIGURED',
            'gross_cents'=>$gross,
            'withheld_cents'=>$withheld,
            'net_check_cents'=>$net,
            'ordinary_available_cents'=>$ordinaryAvailable,
            'ordinary_shortfall_cents'=>$grossShortfall,
            'verified_uncommitted_fund_cash_cents'=>$verifiedUncommittedCash,
            'net_cash_test_passes'=>$bankBasis?$cashPass:null,
            'confirmed_unused_retained_cents'=>$confirmedUnusedRetained,
            'extra_gross_authority_test_passes'=>$bankBasis?$retainedAuthorityPass:null,
            'active_finalization_basis'=>'GROSS_AUTHORIZED_ALLOCATION',
            'new_bank_cash_mode_enabled'=>false,
            'can_finalize_using_bank_cash_mode'=>false,
            'readiness'=>!$bankBasis?'EXISTING_RULE_UNCHANGED':(
                $ordinaryAvailable<0?'FUND_OVERCOMMITTED':
                ($verifiedUncommittedCash===null?'FUND_SPECIFIC_CASH_UNVERIFIED':
                ($confirmedUnusedRetained===null?'RETAINED_AUTHORITY_UNVERIFIED':'ADVISORY_ONLY'))),
        ];
    }
}
