<?php
declare(strict_types=1);
/**
 * v0.2.362: fund-attributable BANK BOOK cash determines the allowable NET check
 * for an expressly Division-authorized Non-IUS retained-tax utilization fund.
 * Prior PENDING and CONFIRMED withholding are the SAME recorded eligible source,
 * not two spending balances. Current DV withholding may count only once.
 * No bank credit, budget allotment or tax settlement is created by this helper.
 */
final class FundCashFinalization {
    public static function authorize(int $gross,int $withheld,int $ordinaryRemaining,
        int $recordedEligibleBeforeCurrent,int $bankCash,int $maintainingMinimum,
        int $currentDvEligibleWithheld=0): array {
        if($gross<=0||$withheld<0||$withheld>$gross||$recordedEligibleBeforeCurrent<0||
            $bankCash<0||$maintainingMinimum<0||$currentDvEligibleWithheld<0||
            $currentDvEligibleWithheld>$withheld){
            throw new InvalidArgumentException('Invalid recorded tax or verified bank amounts');
        }
        $net=$gross-$withheld;
        if($net<=0)throw new DomainError('A zero-net voucher cannot be assigned a check',409);
        // Cash is the first operational gate, after checking source-fund bank
        // book commitments and the actual account attested by the officer.
        if($net>$bankCash-$maintainingMinimum){
            throw new DomainError('Insufficient selected-fund bank-book cash for the NET check after bank maintaining balance; DV remains draft.',409);
        }
        // Integrity reconciliation: if gross has exceeded ordinary authority,
        // the difference must correspond to REAL withheld tax recorded on
        // finalized DVs under the same historical policy, not fake credits.
        // A current DV's own withholding can cover the exact remaining amount.
        $previousRetained=max(0,-$ordinaryRemaining);
        if($previousRetained>$recordedEligibleBeforeCurrent){
            throw new DomainError('Prior gross expenditure exceeds recorded eligible retained withholding; finance reconciliation is required.',409);
        }
        $previousUnused=$recordedEligibleBeforeCurrent-$previousRetained;
        $ordinaryUsed=min($gross,max(0,$ordinaryRemaining));
        $retainedUsed=$gross-$ordinaryUsed;
        $sameDvUsed=max(0,$retainedUsed-$previousUnused);
        if($sameDvUsed>$currentDvEligibleWithheld){
            throw new DomainError('Net bank cash is sufficient, but the gross-over-ordinary amount is not backed by recorded eligible retained tax; review withholding and fund lineage.',409);
        }
        return [
            'mode'=>'NON_IUS_POLICY_GATED_NET_FUND_BANK_CASH',
            'ordinary_used_cents'=>$ordinaryUsed,
            'retained_gross_used_cents'=>$retainedUsed,
            'retained_previously_consumed_cents'=>$previousRetained,
            'previous_retained_applied_this_dv_cents'=>$retainedUsed-$sameDvUsed,
            'same_dv_withheld_used_cents'=>$sameDvUsed,
            'current_dv_eligible_withheld_cents'=>$currentDvEligibleWithheld,
            'recorded_eligible_prior_cents'=>$recordedEligibleBeforeCurrent,
            'recorded_eligible_remaining_prior_cents'=>$previousUnused,
            'division_tax_reporting_state'=>'PENDING_DIVISION_REPORT_OR_CONFIRMATION',
            'net_check_cents'=>$net,
            'verified_fund_bank_cash_cents'=>$bankCash,
            'bank_minimum_cents'=>$maintainingMinimum,
        ];
    }
}
