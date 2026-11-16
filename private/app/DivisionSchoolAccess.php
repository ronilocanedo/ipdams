<?php
declare(strict_types=1);
/**
 * v0.2.208 staging: narrow school-scoped Division inventory defaults.
 * This helper never expands organization scope or MOOE viewer rights.
 * Sensitive register reads remain disabled until a separately audited,
 * endpoint-complete individual Special Function implementation is accepted.
 */
final class DivisionSchoolAccess {
    private const BLOCKED_LISTS = [
        'stocks','stock_exceptions','assets','received_property',
        'textbook_batches','textbook_movements',
    ];
    private const BLOCKED_GET = [
        'division.property_registry','pr.property.lineage','document.property.evidence',
    ];
    private const BLOCKED_POST = [
        'stock.opening','asset.opening','asset.iar.register','asset.move',
        'textbook.opening','textbook.move','ris.reverse',
        'master.property.registry.save','master.property.threshold.save',
        'master.property.useful_life.save',
    ];
    private const DEFAULT_ACTIONS = [
        'ris.create','ris.cancel','ris.issue','accountability.issue',
    ];
    public static function isDivisionSchool(PDO $db,array $user,int $org): bool {
        if(($user['role']??'')!=='DIVISION' || $org<1)return false;
        $row=one($db,"SELECT 1 FROM organizations WHERE id=? AND kind='SCHOOL' AND active=1",[$org]);
        return $row!==null;
    }
    public static function canIssue(string $role,string $kind,string $operation): bool {
        return $role==='DIVISION' && $kind==='SCHOOL'
            && in_array($operation,self::DEFAULT_ACTIONS,true);
    }
    public static function requireGet(PDO $db,array $user,string $route,array $input): void {
        $org=isset($input['organization_id']) && ctype_digit((string)$input['organization_id'])
            ? (int)$input['organization_id'] : 0;
        if(!self::isDivisionSchool($db,$user,$org))return;
        // An exact user-scoped school membership is necessary even for denied routes.
        scope($db,$user,$org);
        if($route==='list'){
            $kind=(string)($input['kind']??'');
            demand(!in_array($kind,self::BLOCKED_LISTS,true),
                'Division school assignment does not grant stock, asset register or textbook access',403);
        }
        demand(!in_array($route,self::BLOCKED_GET,true),
            'School property register information requires a separately authorized function',403);
    }
    public static function requirePost(PDO $db,array $user,string $route,array $body): void {
        $org=isset($body['organization_id']) && ctype_digit((string)$body['organization_id'])
            ? (int)$body['organization_id'] : 0;
        if(!self::isDivisionSchool($db,$user,$org))return;
        scope($db,$user,$org);
        demand(!in_array($route,self::BLOCKED_POST,true),
            'This stock/property operation is not included in Division User school defaults',403);
    }
}
