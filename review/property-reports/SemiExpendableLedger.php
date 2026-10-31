<?php
declare(strict_types=1);
/** Read-only, synthetic-event ledger projection. NOT wired to deployed IPDAMS APIs. */
final class SemiExpendableLedger {
    public static function project(array $events, int $organizationId, string $asOf): array {
        if ($organizationId<=0 || !self::validDate($asOf)) throw new InvalidArgumentException('Invalid scope/cutoff');
        $seen=[];$rows=[];$balance=[];
        foreach ($events as $e) {
            foreach (['organization_id','event_id','asset_id','date','type','quantity','unit_cost_cents','status'] as $f)
                if (!array_key_exists($f,$e)) throw new InvalidArgumentException('Missing '.$f);
            if ((int)$e['organization_id']!==$organizationId) throw new InvalidArgumentException('Cross-organization event denied');
            if (!self::validDate((string)$e['date'])) throw new InvalidArgumentException('Invalid date');
            if ($e['status']!=='FINALIZED'||$e['date']>$asOf) continue;
            $key=(string)$e['event_id'];
            if ($key===''||isset($seen[$key])) throw new InvalidArgumentException('Duplicate event');
            $seen[$key]=true;
            if (!in_array($e['type'],['OPENING','RECEIPT','ISSUE','RETURN','TRANSFER_IN','TRANSFER_OUT','DISPOSAL'],true))
                throw new InvalidArgumentException('Unknown event type');
            if (!is_int($e['quantity'])||$e['quantity']<=0||!is_int($e['unit_cost_cents'])||$e['unit_cost_cents']<0||(int)$e['asset_id']<=0)
                throw new InvalidArgumentException('Invalid quantity/cost/asset');
            $rows[]=$e;
        }
        usort($rows,static fn($a,$b)=>[$a['date'],(string)$a['event_id']]<=>[$b['date'],(string)$b['event_id']]);
        $output=[];
        foreach ($rows as $e) {
            $asset=(int)$e['asset_id'];
            $change=in_array($e['type'],['OPENING','RECEIPT','RETURN','TRANSFER_IN'],true)?$e['quantity']:-$e['quantity'];
            $balance[$asset]=($balance[$asset]??0)+$change;
            if ($balance[$asset]<0) throw new DomainException('Negative quantity balance');
            $output[]=['event_id'=>(string)$e['event_id'],'asset_id'=>$asset,'date'=>$e['date'],'type'=>$e['type'],
                'quantity_change'=>$change,'unit_cost_cents'=>$e['unit_cost_cents'],'balance_quantity'=>$balance[$asset]];
        }
        return ['organization_id'=>$organizationId,'as_of'=>$asOf,'rows'=>$output,'closing_quantities'=>$balance];
    }
    private static function validDate(string $date): bool {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        return $d!==false&&$d->format('Y-m-d')===$date;
    }
}
