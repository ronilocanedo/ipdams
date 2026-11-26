<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
/** Detailed finance reads require both explicit membership and active hierarchy.
 * VIEWER remains confined to its existing MOOE review functions.
 */
final class FinanceAccess {
    public static function read(PDO $db,array $user,int $org):array {
        demand(($user['role']??'')!=='VIEWER','Detailed finance access is restricted',403);
        scope($db,$user,$org);
        $row=one($db,'SELECT * FROM organizations WHERE id=? AND active=1',[$org]);
        demand($row!==null,'Organization unavailable',404);
        if($user['role']==='DIVISION'&&$row['kind']==='SCHOOL'){
            $parent=(int)($row['parent_division_id']??0);
            demand($parent>0&&one($db,"SELECT id FROM organizations WHERE id=? AND kind='DIVISION' AND active=1",[$parent])!==null,'Active linked Division required',403);
            scope($db,$user,$parent);
        }
        return $row;
    }
}
