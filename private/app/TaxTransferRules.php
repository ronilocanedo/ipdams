<?php
declare(strict_types=1);
/**
 * v0.2.374: Source-backed withholding transfer settlement; never a new expense,
 * tax assessment, fund allocation, bank receipt or Division journal posting.
 */
final class TaxTransferRules {
    public const BIR = '2020101000';
    public const ADVANCE = '1990101000';
    public const PENDING = 'DO_ACCOUNTING_PENDING';
    public const ALLOWED_STATUSES = ['DRAFT','CHECK_ASSIGNED','CHECK_RELEASED','DO_ACCOUNTING_PENDING','RECONCILED','CANCELLED'];
    public static function workpaper(string $value): array {
        $amount=moneyCents($value);
        demand($amount>0,'A withholding transfer must be positive.',409);
        $sum=moneyString($amount);
        return [
            'applicable'=>true,'ready'=>true,'treatment'=>'SCHOOL_WITHHOLDING_TRANSFER_TO_DIVISION',
            'issues'=>[],'message'=>'School settlement of existing Due to BIR liability; no second expense or fund allocation. Bank-book commitment is recorded once when the physical check is assigned; actual remittance is evidenced by release and Division receipt.',
            'debit_total'=>$sum,'credit_total'=>$sum,'gross'=>$sum,'net'=>$sum,'withheld'=>'0.00',
            'entries'=>[
                ['uacs_code'=>self::BIR,'account_name'=>'Due to BIR','debit'=>$sum,'credit'=>'0.00','explanation'=>'Existing withheld-tax liability transferred to Division'],
                ['uacs_code'=>self::ADVANCE,'account_name'=>'Advances for Operating Expenses','debit'=>'0.00','credit'=>$sum,'explanation'=>'Liquidation of school operating advance through Division tax remittance'],
            ],
        ];
    }
    public static function validateSources(array $componentRows,int $org,int $fundId): string {
        demand(count($componentRows)>0 && count($componentRows)<=100,'Select 1–100 original saved tax components.',409);
        $sum=0;$seen=[];
        foreach($componentRows as $row){
            $id=(int)($row['id']??0);
            demand($id>0&&!isset($seen[$id]),'Duplicate or missing source tax component.',409);
            $seen[$id]=true;
            demand((int)($row['organization_id']??0)===$org && (int)($row['fund_id']??0)===$fundId,
                'Tax component belongs to a different school or source fund.',409);
            demand(($row['dv_status']??'')==='FINALIZED' && ($row['disposition']??'')==='REMIT',
                'Only finalized, Division-remittable withholding can be transferred.',409);
            demand(($row['already_completed']??0)==0 && ($row['active_reservation']??0)==0,
                'Tax component was already remitted or reserved by another transfer.',409);
            demand(($row['source_paid']??0)==1,
                'Original supplier-DV check must actually be RELEASED or CLEARED before transferring its withholding.',409);
            $c=moneyCents((string)($row['amount']??''));
            demand($c>0,'Tax component must have a positive saved amount.',409);
            demand($sum<=PHP_INT_MAX-$c,'Tax sum exceeds safe bounds',409);
            $sum+=$c;
        }
        return moneyString($sum);
    }
    public static function next(string $status,string $action): string {
        $map=[
            'DRAFT:FINALIZE'=>'CHECK_ASSIGNED',
            'DRAFT:CANCEL'=>'CANCELLED',
            'CHECK_ASSIGNED:RELEASE'=>'CHECK_RELEASED',
            'CHECK_RELEASED:RECEIVE'=>self::PENDING,
            self::PENDING.':RECONCILE'=>'RECONCILED',
        ];
        $key=$status.':'.$action;
        demand(isset($map[$key]),'Illegal or duplicate withholding transfer transition.',409);
        return $map[$key];
    }
}
