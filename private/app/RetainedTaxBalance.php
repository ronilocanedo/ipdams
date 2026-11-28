<?php
declare(strict_types=1);
/** v0.2.465: presentation only. Never used to authorize a DV or post cash. */
final class RetainedTaxBalance {
    private const HISTORICAL_CONFIRMED_MODE='NON_IUS_CONFIRMED_RETAINED_WITHHOLDING_NET_BANK_CASH';
    public static function signed(string $s):int {
        return str_starts_with($s,'-')?-moneyCents(substr($s,1)):moneyCents($s);
    }
    /** The ordinary deficit is reconciled only by recorded use. Excess historic
     * use cannot turn into a fresh positive spending balance after new funding. */
    public static function summarize(int $ordinary,int $gross,int $eligible,int $confirmed,int $used):array {
        demand($gross>=0 && $eligible>=0 && $confirmed>=0 && $confirmed<=$eligible && $used>=0 && $used<=$eligible && $used<=$gross,
            'Retained funding exceeds verified source or gross expenses',409);
        $offset=min(max(0,-$ordinary),$used);
        $remaining=$ordinary+$offset;
        return ['ordinary_allocation_difference'=>moneyString($ordinary),
            'retained_eligible'=>moneyString($eligible),'retained_confirmed_verified'=>moneyString($confirmed),
            'retained_funding_applied'=>moneyString($used),'retained_deficit_offset'=>moneyString($offset),
            'retained_unapplied'=>moneyString($eligible-$used),'adjusted_utilization'=>moneyString($gross-$offset),
            'adjusted_remaining_allocation'=>moneyString($remaining),
            'retained_balance_status'=>$remaining<0?'UNRESOLVED_DEFICIT':'VERIFIED',
            'retained_balance_issue'=>$remaining<0?'Ordinary deficit exceeds verified retained funding applied. Review original accounting evidence.':null];
    }
    /** Safe, read-only diagnostic: expose only known validation messages, never raw
     * SQL, exception traces, policy payloads or unrelated record details. */
    public static function reviewReason(Throwable $e,string $stage,?string $dv):string {
        $messages=[
            'Retained funding requires an active school and Division','Division unavailable',
            'Retained-tax component history requires finance review','Record not found in authorized organization',
            'Recorded school-retained tax has conflicting or missing disposition history.',
            'Confirmed retained tax is missing authority evidence.',
            'Recorded withholding lacks an original school-utilization policy snapshot.',
            'Recorded retained tax is not traceable to effective Division authority.',
            'Invalid recorded retained tax amount.','Retained component totals disagree',
            'Retained source capacity exceeded','Retained component has conflicting status, amount or fund',
            'Retained source totals changed','DV funding history exceeds review capacity',
            'Duplicate finalized DV or audit','Invalid retained funding records',
            'Original school retention authority missing','Historical school or Division policy is not verified',
            'DV funding snapshot and finalization audit disagree','Invalid DV funding snapshot',
            'Unknown retained funding mode','Invalid saved retained funding identity or policy',
            'Legacy retained funding lacks complete bank or source evidence',
            'Legacy retained funding source amounts do not reconcile',
            'Legacy retained funding bank evidence does not reconcile',
            'Historical confirmed retention amount invalid',
            'Historical confirmed retention sources missing or duplicated',
            'Historical confirmed retention balance does not reconcile',
            'Historical confirmed retention bank evidence does not reconcile',
            'Historical confirmed retention conflicts with newer funding fields',
            'Invalid retained funding amount','Saved DV funding amounts do not reconcile',
            'Same-DV withholding source invalid','Same-DV withholding evidence does not balance',
            'Prior retained funding source missing or duplicated','Prior retained funding exceeds source evidence',
            'Fund gross or source coverage changed','Retained funding exceeds verified source or gross expenses',
            'Invalid monetary amount','Monetary amount exceeds supported bounds'
        ];
        $reason=$e instanceof JsonException?'Saved evidence is not valid JSON.':
            ($e instanceof DomainError && in_array($e->getMessage(),$messages,true)?$e->getMessage():'Saved evidence has an unsupported or incomplete format.');
        return $stage.($dv!==null?' — '.$dv:'').': '.$reason;
    }
    public static function unavailable(int $ordinary,?string $reason=null):array {
        return ['ordinary_allocation_difference'=>moneyString($ordinary),'retained_eligible'=>null,
            'retained_confirmed_verified'=>null,'retained_funding_applied'=>null,'retained_deficit_offset'=>null,
            'retained_unapplied'=>null,'adjusted_utilization'=>null,'adjusted_remaining_allocation'=>null,
            'retained_balance_status'=>'REVIEW_REQUIRED','retained_balance_issue'=>$reason??'Retained funding evidence is incomplete or inconsistent. Review the original DV, tax and funding history; ordinary difference is not cleared.'];
    }
    /** Positive historical retained use must undergo the original policy checks
     * even when the saved discriminator predates the current funding mode. */
    public static function requiresPolicy(mixed $basis):bool {
        return is_array($basis) && (in_array($basis['mode']??'',['NON_IUS_POLICY_GATED_NET_FUND_BANK_CASH',self::HISTORICAL_CONFIRMED_MODE],true) ||
            (array_key_exists('retained_gross_used_cents',$basis) && $basis['retained_gross_used_cents']!==0));
    }
    /** Validate immutable funding evidence against the live finalized DV and
     * its independently stored finalization audit. Tax source map is scoped
     * to this fund and validated by Domain's historical policy checks. */
    public static function applied(array $dv,array $snapshot,array $audit,array $sources):int {
        $basis=$snapshot['funding_basis']??null;
        $other=$audit['funding_basis']??null;
        demand($basis===$other,'DV funding snapshot and finalization audit disagree',409);
        if($basis===null)return 0;
        demand(is_array($basis),'Invalid DV funding snapshot',409);
        $legacy=($basis['mode']??'')!=='NON_IUS_POLICY_GATED_NET_FUND_BANK_CASH';
        $confirmedHistory=($basis['mode']??'')===self::HISTORICAL_CONFIRMED_MODE;
        if($legacy&&!$confirmedHistory){
            if(!self::requiresPolicy($basis))return 0;
            // A discriminator is not accounting evidence. A historical label
            // can qualify only through the complete, independently verified
            // funding contract below, never by its name alone.
            $mode=$basis['mode']??null;
            demand(is_string($mode)&&preg_match('/^[A-Z][A-Z0-9_]{0,79}$/D',$mode)===1 &&
                !in_array($mode,['SOURCE_TAX_LIABILITY_TRANSFER_NO_NEW_ALLOTMENT','GROSS_AUTHORIZED_ALLOCATION',
                    'EXISTING_GROSS_ALLOCATION','NON_IUS_BANK_CASH_CANDIDATE'],true) &&
                is_int($basis['retained_gross_used_cents']??null)&&$basis['retained_gross_used_cents']>0,
                'Unknown retained funding mode',409);
        }
        $h=$snapshot['header']??[];$p=$snapshot['withholding_policy']??[];
        demand((int)($h['id']??0)===(int)$dv['id'] && (int)($h['fund_id']??0)===(int)$dv['fund_id'] &&
            moneyCents((string)($h['gross']??''))===moneyCents((string)$dv['gross']) &&
            moneyCents((string)($h['net']??''))===moneyCents((string)$dv['net']) &&
            ($p['mode']??'')==='DIVISION_SETTLES_UTILIZABLE' && (int)($p['version_id']??0)>0 &&
            (int)$p['version_id']===(int)($basis['division_policy_version_id']??0) &&
            (int)$p['version_id']===(int)($audit['withholding_policy_version_id']??0) &&
            ($basis['officer_verified_actual_bank_cash']??false)===true,'Invalid saved retained funding identity or policy',409);
        if($confirmedHistory)return self::appliedHistoricalConfirmed($dv,$basis,$sources);
        foreach(['ordinary_used_cents','retained_gross_used_cents','previous_retained_applied_this_dv_cents','same_dv_withheld_used_cents','net_check_cents'] as $key)
            demand(is_int($basis[$key]??null)&&$basis[$key]>=0,'Invalid retained funding amount',409);
        $used=$basis['retained_gross_used_cents'];$self=$basis['same_dv_withheld_used_cents'];$prior=$basis['previous_retained_applied_this_dv_cents'];
        demand($basis['ordinary_used_cents']+$used===moneyCents((string)$dv['gross']) && $prior+$self===$used &&
            $basis['net_check_cents']===moneyCents((string)$dv['net']),'Saved DV funding amounts do not reconcile',409);
        $seen=[];$sum=0;
        foreach($basis['same_dv_withholding_components']??[] as $item){
            $id=$item['component_id']??null;$c=$item['used_cents']??null;
            demand(is_int($id)&&is_int($c)&&$c>0&&!isset($seen[$id])&&isset($sources[$id])&&
                $sources[$id]['dv_id']===(int)$dv['id']&&$c<=$sources[$id]['cents'] &&
                ($item['atc_code']??null)===$sources[$id]['atc_code'],'Same-DV withholding source invalid',409);
            $seen[$id]=true;$sum+=$c;
        }
        demand($sum===$self,'Same-DV withholding evidence does not balance',409);
        $priorIds=[];$priorTotal=0;
        foreach(array_merge($basis['confirmed_source_component_ids']??[],$basis['prior_pending_source_ids']??[]) as $id){
            demand(is_int($id)&&!isset($priorIds[$id])&&isset($sources[$id])&&
                $sources[$id]['dv_id']!==(int)$dv['id'],'Prior retained funding source missing or duplicated',409);
            $priorIds[$id]=true;$priorTotal+=$sources[$id]['cents'];
        }
        demand($prior<=$priorTotal,'Prior retained funding exceeds source evidence',409);
        if($legacy){
            // Additional legacy proof: these are saved integer-cent fields of
            // the existing authorization algorithm. Do not manufacture missing
            // fields from the current balance or from tax confirmations.
            foreach(['retained_previously_consumed_cents','recorded_eligible_prior_cents',
                'recorded_eligible_remaining_prior_cents','current_dv_eligible_withheld_cents',
                'verified_fund_bank_cash_cents','bank_minimum_cents'] as $key)
                demand(is_int($basis[$key]??null)&&$basis[$key]>=0,'Legacy retained funding lacks complete bank or source evidence',409);
            foreach(['confirmed_source_component_ids','prior_pending_source_ids','same_dv_withholding_components'] as $key)
                demand(is_array($basis[$key]??null)&&array_is_list($basis[$key]),'Legacy retained funding lacks complete bank or source evidence',409);
            $consumed=$basis['retained_previously_consumed_cents'];
            $before=$basis['recorded_eligible_prior_cents'];
            $unused=$basis['recorded_eligible_remaining_prior_cents'];
            $current=$basis['current_dv_eligible_withheld_cents'];
            $currentTotal=0;
            foreach($sources as $source)if($source['dv_id']===(int)$dv['id'])$currentTotal+=$source['cents'];
            demand($before===$priorTotal&&$consumed<=$before&&$unused===$before-$consumed&&
                $prior<=$unused&&$current===$currentTotal&&$self<=$current&&
                $self===max(0,$used-$unused), 'Legacy retained funding source amounts do not reconcile',409);
            $net=moneyCents((string)$dv['net']);
            demand($net>0&&$basis['verified_fund_bank_cash_cents']>=$basis['bank_minimum_cents']&&
                $net<=$basis['verified_fund_bank_cash_cents']-$basis['bank_minimum_cents'],
                'Legacy retained funding bank evidence does not reconcile',409);
        }
        return $used;
    }
    /** Older, confirmed-only authorization contract. No current-DV or pending
     * retention existed in this contract. Read immutable old fields directly;
     * never backfill the snapshot or fabricate newer fields in the database. */
    private static function appliedHistoricalConfirmed(array $dv,array $basis,array $sources):int {
        foreach(['ordinary_used_cents','retained_gross_used_cents','retained_previously_consumed_cents',
            'retained_remaining_before_this_dv_cents','net_check_cents',
            'verified_fund_bank_cash_cents','bank_minimum_cents'] as $key)
            demand(is_int($basis[$key]??null)&&$basis[$key]>=0,'Historical confirmed retention amount invalid',409);
        $used=$basis['retained_gross_used_cents'];
        $net=moneyCents((string)$dv['net']);
        demand($basis['ordinary_used_cents']+$used===moneyCents((string)$dv['gross'])&&
            $basis['net_check_cents']===$net,'Saved DV funding amounts do not reconcile',409);
        // The old contract can only spend previously confirmed components.
        // If a mixed-format record also contains newer fields, they must not
        // contradict that contract. Missing newer fields are not errors.
        foreach(['previous_retained_applied_this_dv_cents'=>$used,'same_dv_withheld_used_cents'=>0] as $key=>$expected)
            demand(!array_key_exists($key,$basis)||$basis[$key]===$expected,
                'Historical confirmed retention conflicts with newer funding fields',409);
        foreach(['prior_pending_source_ids','same_dv_withholding_components'] as $key)
            demand(!array_key_exists($key,$basis)||$basis[$key]===[],
                'Historical confirmed retention conflicts with newer funding fields',409);
        $ids=$basis['confirmed_source_component_ids']??null;
        demand(is_array($ids)&&array_is_list($ids),'Historical confirmed retention sources missing or duplicated',409);
        $seen=[];$total=0;
        foreach($ids as $id){
            demand(is_int($id)&&$id>0&&!isset($seen[$id])&&isset($sources[$id])&&
                $sources[$id]['dv_id']!==(int)$dv['id']&&($sources[$id]['confirmed']??false)===true&&
                is_int($sources[$id]['cents']??null)&&$sources[$id]['cents']>0,
                'Historical confirmed retention sources missing or duplicated',409);
            $seen[$id]=true;$total+=$sources[$id]['cents'];
        }
        $consumed=$basis['retained_previously_consumed_cents'];
        $remaining=$basis['retained_remaining_before_this_dv_cents'];
        demand($consumed<=$total&&$remaining===$total-$consumed&&$used<=$remaining,
            'Historical confirmed retention balance does not reconcile',409);
        demand($net>0&&$basis['verified_fund_bank_cash_cents']>=$basis['bank_minimum_cents']&&
            $net<=$basis['verified_fund_bank_cash_cents']-$basis['bank_minimum_cents'],
            'Historical confirmed retention bank evidence does not reconcile',409);
        return $used;
    }

}
