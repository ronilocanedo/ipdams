<?php
declare(strict_types=1);
/** Pure bounded decision policy. All quantities are mills; no SQL or writes. */
final class IarAutoPropertyPolicy {
    public const MAX_ASSETS_PER_IAR=100;
    public static function plan(string $category,int $acceptedMillis,int $alreadyAuto): array {
        if($acceptedMillis<=0 || !in_array($category,['PPE','SEMI_EXPENDABLE'],true))
            return ['units'=>0,'deferred'=>false];
        if($acceptedMillis%1000!==0 || $acceptedMillis>self::MAX_ASSETS_PER_IAR*1000 ||
            $alreadyAuto<0 || $alreadyAuto+intdiv($acceptedMillis,1000)>self::MAX_ASSETS_PER_IAR)
            return ['units'=>0,'deferred'=>true];
        return ['units'=>intdiv($acceptedMillis,1000),'deferred'=>false];
    }
}
