<?php
declare(strict_types=1);
require_once __DIR__ . '/SemiExpendableLedger.php';

/**
 * Read-only report prototypes for COA Annex A.4 (registry) and A.7 (issued).
 * Input MUST be independently verified, scoped, immutable event snapshots.
 * No DB reads, source status changes, accounting or implicit approvals.
 */
final class SemiExpendableIssuedReports {
    public static function build(array $events, int $organizationId, string $from, string $through): array {
        if (!self::dateOk($from) || !self::dateOk($through) || $from>$through) {
            throw new InvalidArgumentException('Invalid reporting period');
        }
        $ledger=SemiExpendableLedger::project($events,$organizationId,$through);
        $byId=[];
        foreach ($events as $event) {
            // Mirror ledger selection, but do not silently coerce unknown metadata.
            if (($event['status']??null)!=='FINALIZED' || !isset($event['date']) || $event['date']>$through) continue;
            $id=(string)($event['event_id']??'');
            if ($id==='' || isset($byId[$id])) throw new InvalidArgumentException('Nonunique source identity');
            $byId[$id]=$event;
        }
        $register=[];$issued=[];
        foreach ($ledger['rows'] as $movement) {
            $event=$byId[$movement['event_id']];
            if (!in_array($movement['type'],['ISSUE','RETURN','TRANSFER_OUT','TRANSFER_IN'],true)) continue;
            foreach (['document_number','custodian','property_number','description'] as $required) {
                if (!is_string($event[$required]??null) || trim($event[$required])==='') {
                    throw new InvalidArgumentException('Missing verified property metadata: '.$required);
                }
            }
            $row=[
                'event_id'=>$movement['event_id'],'date'=>$movement['date'],
                'asset_id'=>$movement['asset_id'],'property_number'=>$event['property_number'],
                'description'=>$event['description'],'custodian'=>$event['custodian'],
                'document_number'=>$event['document_number'],
                'movement'=>$movement['type'],'quantity'=>abs($movement['quantity_change']),
                'unit_cost_cents'=>$movement['unit_cost_cents'],
                'total_cents'=>abs($movement['quantity_change'])*$movement['unit_cost_cents']
            ];
            $register[]=$row;
            if ($movement['type']==='ISSUE' && $movement['date'] >= $from) $issued[]=$row;
        }
        return [
            'organization_id'=>$organizationId,'from'=>$from,'through'=>$through,
            'annex_a4_registry_movements'=>$register,
            'annex_a7_issued_in_period'=>$issued,
            'note'=>'Draft projection only; custody/outstanding-by-ICS reconciliation and official print layouts require production adapter validation.'
        ];
    }
    private static function dateOk(string $value):bool {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        return $date!==false && $date->format('Y-m-d')===$value;
    }
}
