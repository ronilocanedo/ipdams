<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
/**
 * Read-only Division Office + authorized school consolidation. A receiving
 * office owns a transferred-in ACTIVE asset; the sending copy remains history.
 * Never expands school grants or mutates source property or accountability.
 */
final class DivisionConsolidation {
    private const TYPES = ['OVERVIEW','STOCK','STOCK_EVENT','STOCK_EXCEPTION','RIS',
        'ASSET','ASSET_EVENT','ICS_PAR','RECEIPTS','TEXTBOOK','TEXTBOOK_EVENT','STOCK_TOTALS','TEXTBOOK_TOTALS'];
    private const CATEGORIES = [
        'STOCK'=>'inventory.stocks.view','STOCK_EVENT'=>'inventory.stocks.view','STOCK_TOTALS'=>'inventory.stocks.view',
        'STOCK_EXCEPTION'=>'property.assets.view',
        'ASSET'=>'property.assets.view','ASSET_EVENT'=>'property.assets.view',
        'RECEIPTS'=>'property.assets.view',
        'TEXTBOOK'=>'textbooks.batches.view','TEXTBOOK_EVENT'=>'textbooks.batches.view','TEXTBOOK_TOTALS'=>'textbooks.batches.view',
    ];
    private const FIELDS = [
        'STOCK'=>['id','school','item','unit','batch','on_hand'],
        'STOCK_TOTALS'=>['id','item','unit','school_count','lot_count','on_hand'],
        'STOCK_EVENT'=>['id','school','item','event','change','source','date'],
        'STOCK_EXCEPTION'=>['id','school','item','category','batch','on_hand'],
        'RIS'=>['id','school','document','status','requestor','date'],
        'ASSET'=>['id','school','property_tag','item','category','origin','serial','cost','custodian','location','condition','status'],
        'ASSET_EVENT'=>['id','school','property_tag','event','from','to','date'],
        'ICS_PAR'=>['id','school','type','document','property_tag','custodian','status','date'],
        'RECEIPTS'=>['id','school','iar_number','item','accepted_qty','registered_units','date'],
        'TEXTBOOK'=>['id','school','title','grade','subject','batch','on_hand'],
        'TEXTBOOK_TOTALS'=>['id','title','grade','subject','school_count','batch_count','on_hand'],
        'TEXTBOOK_EVENT'=>['id','school','title','event','change','reference','date'],
    ];
    public static function requestedType(array $params): string {
        $view=strtoupper(trim((string)($params['view']??'OVERVIEW')));
        demand(in_array($view,self::TYPES,true),'Unsupported consolidated inventory view',400);
        return $view;
    }
    private static function access(PDO $db,array $user,int $division): array {
        demand(in_array(($user['role']??''),['SUPER_ADMIN','DIVISION'],true),
            'Division consolidation requires an authorized Division account',403);
        $row=one($db,"SELECT id,name,code,kind FROM organizations WHERE id=? AND kind='DIVISION' AND active=1",[$division]);
        demand($row!==null,'Active Division workspace required',403);
        // This office is itself an authorized holding organization, not a school.
        // Its own operational inventory is always eligible under its Division role.
        if($user['role']==='DIVISION')scope($db,$user,$division);
        $sql="SELECT o.id,o.name,o.code,o.kind FROM organizations o WHERE o.parent_division_id=? AND o.kind='SCHOOL' AND o.active=1";
        $params=[$division];
        if($user['role']==='DIVISION'){
            $sql.=' AND EXISTS(SELECT 1 FROM user_organization_scopes s WHERE s.user_id=? AND s.organization_id=o.id)';
            $params[]=(int)$user['id'];
        }
        $rows=q($db,$sql.' ORDER BY o.name,o.id',$params)->fetchAll();
        demand(count($rows)<=2000,'Large Division requires paginated school inventory authorization review',409);
        return array_merge([$row],$rows);
    }
    private static function permissions(PDO $db,array $user,array $offices): array {
        $perms=[];$schools=[];
        foreach($offices as $office){
            $id=(int)$office['id'];
            $perms[$id]=['RIS'=>true,'ICS_PAR'=>true];
            // The Division Office's own records are covered by its workspace
            // authority. Schools continue requiring per-user, per-school grants.
            if(($office['kind']??'')==='DIVISION'||$user['role']==='SUPER_ADMIN'){
                foreach(self::CATEGORIES as $type=>$code)$perms[$id][$type]=true;
            }else{$schools[]=$office;}
        }
        if($user['role']==='SUPER_ADMIN'||!$schools)return $perms;
        $ids=array_map(static fn($s)=>(int)$s['id'],$schools);
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $rows=q($db,"SELECT organization_id,config_json FROM configuration_versions
            WHERE config_key=? AND organization_id IN ($marks) ORDER BY id DESC",
            array_merge(['IPDAMS_SPECIAL_FUNCTIONS_USER_V1_'.(int)$user['id']],$ids))->fetchAll();
        $read=[];$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        foreach($rows as $row){
            $id=(int)$row['organization_id'];if(isset($read[$id]))continue;
            $read[$id]=true;
            $grants=json_decode((string)$row['config_json'],true);
            demand(is_array($grants) && array_is_list($grants),'Invalid scope permission record',500);
            foreach($grants as $grant){
                if(!is_array($grant)||($grant['status']??'')!=='ACTIVE'||(int)($grant['school_id']??0)!==$id)continue;
                $start=(string)($grant['starts_on']??'');$end=(string)($grant['ends_on']??'');
                if($start===''||$start>$today||($end!==''&&$end<$today))continue;
                foreach(self::CATEGORIES as $type=>$code)if(($grant['permission']??'')===$code)$perms[$id][$type]=true;
            }
        }
        return $perms;
    }
    private static function dataset(PDO $db,string $type,array $schoolIds,int $before,string $month): array {
        // All SQL identifiers are fixed server-side constants; user inputs are bound.
        $defs=[
          'STOCK'=>["FROM inventory_lots l JOIN item_catalog i ON i.id=l.item_id JOIN units u ON u.id=i.unit_id JOIN organizations o ON o.id=l.organization_id",
            "l.id AS id,o.name AS school,i.name AS item,u.code AS unit,l.batch,(SELECT COALESCE(SUM(e.quantity_delta),0) FROM stock_events e WHERE e.lot_id=l.id AND e.organization_id=l.organization_id) AS on_hand", "i.category='SUPPLY'",'l',''],
          'STOCK_EVENT'=>["FROM stock_events e JOIN inventory_lots l ON l.id=e.lot_id AND l.organization_id=e.organization_id JOIN item_catalog i ON i.id=l.item_id JOIN organizations o ON o.id=e.organization_id",
            "e.id AS id,o.name AS school,i.name AS item,e.event_type AS event,e.quantity_delta AS `change`,e.source_type AS source,e.created_at AS date", "i.category='SUPPLY'",'e','e.created_at'],
          'STOCK_EXCEPTION'=>["FROM inventory_lots l JOIN item_catalog i ON i.id=l.item_id JOIN organizations o ON o.id=l.organization_id",
            "l.id AS id,o.name AS school,i.name AS item,i.category,l.batch,(SELECT COALESCE(SUM(e.quantity_delta),0) FROM stock_events e WHERE e.lot_id=l.id AND e.organization_id=l.organization_id) AS on_hand", "i.category<>'SUPPLY'",'l',''],
          'RIS'=>["FROM requisition_issue_slips r JOIN organizations o ON o.id=r.organization_id LEFT JOIN document_registry rd ON rd.organization_id=r.organization_id AND rd.doc_type='RIS' AND rd.entity_id=r.id",
            "r.id AS id,o.name AS school,r.display_number AS document,r.status,r.requestor AS requestor,rd.created_at AS date", '1=1','r','rd.created_at'],
          'ASSET'=>["FROM assets a JOIN item_catalog i ON i.id=a.item_id JOIN organizations o ON o.id=a.organization_id",
            "a.id AS id,o.name AS school,a.property_tag,i.name AS item,i.category,
             CASE WHEN COALESCE(JSON_CONTAINS_PATH(a.property_profile,'one','$.transfer_origin'),0)=1
               THEN 'Transferred in' WHEN a.iar_line_id IS NOT NULL THEN 'Accepted IAR'
               ELSE 'Historical opening' END AS origin,
             a.serial_number AS serial,a.acquisition_cost AS cost,a.custodian,a.location,
             a.condition_note AS `condition`,a.status",
            "a.status='ACTIVE'",'a',''],
          'ASSET_EVENT'=>["FROM asset_movements m JOIN assets a ON a.id=m.asset_id JOIN organizations o ON o.id=a.organization_id",
            "m.id AS id,o.name AS school,a.property_tag,m.event_type AS event,m.from_custodian AS `from`,m.to_custodian AS `to`,m.created_at AS date",'1=1','m','m.created_at','a'],
          'ICS_PAR'=>["FROM accountability_documents d JOIN assets a ON a.id=d.asset_id AND a.organization_id=d.organization_id JOIN organizations o ON o.id=d.organization_id",
            "d.id AS id,o.name AS school,d.doc_type AS type,d.display_number AS document,a.property_tag,d.custodian,d.status,d.created_at AS date", "d.doc_type IN ('ICS','PAR')",'d','d.created_at'],
          'RECEIPTS'=>["FROM iar_lines il JOIN iars ir ON ir.id=il.iar_id JOIN purchase_orders po ON po.id=ir.po_id AND po.organization_id=ir.organization_id JOIN po_lines pl ON pl.id=il.po_line_id AND pl.po_id=po.id JOIN item_catalog i ON i.id=pl.item_id JOIN organizations o ON o.id=ir.organization_id",
            "il.id AS id,o.name AS school,ir.display_number AS iar_number,i.name AS item,il.accepted_qty,(SELECT COUNT(*) FROM assets a WHERE a.organization_id=ir.organization_id AND a.iar_line_id=il.id) AS registered_units,ir.accepted_at AS date", "ir.status='FINALIZED' AND po.status='FINALIZED' AND i.category IN ('PPE','SEMI_EXPENDABLE')",'il','ir.accepted_at','ir'],
          'TEXTBOOK'=>["FROM textbook_batches b JOIN textbook_titles t ON t.id=b.title_id JOIN organizations o ON o.id=b.organization_id",
            "b.id AS id,o.name AS school,t.title,t.grade,t.subject,b.batch,(SELECT COALESCE(SUM(m.quantity_delta),0) FROM textbook_movements m WHERE m.batch_id=b.id AND m.organization_id=b.organization_id) AS on_hand", '1=1','b',''],
          'TEXTBOOK_EVENT'=>["FROM textbook_movements m JOIN textbook_batches b ON b.id=m.batch_id AND b.organization_id=m.organization_id JOIN textbook_titles t ON t.id=b.title_id JOIN organizations o ON o.id=m.organization_id",
            "m.id AS id,o.name AS school,t.title,m.event_type AS event,m.quantity_delta AS `change`,m.reference,m.created_at AS date",'1=1','m','m.created_at'],
        ];
        // Division totals group the SAME catalog item or textbook title and
        // unit, never dissimilar items/units. Detail pages retain the school.
        if($type==='STOCK_TOTALS'||$type==='TEXTBOOK_TOTALS'){
            $marks=implode(',',array_fill(0,count($schoolIds),'?'));
            $where=$type==='STOCK_TOTALS'?"l.organization_id IN ($marks) AND i.category='SUPPLY'":"b.organization_id IN ($marks)";
            $idCol=$type==='STOCK_TOTALS'?'i.id':'t.id';$params=$schoolIds;
            if($before>0){$where.=" AND $idCol<?";$params[]=$before;}
            if($type==='STOCK_TOTALS'){
                $sql="SELECT i.id AS id,i.name AS item,u.code AS unit,COUNT(DISTINCT l.organization_id) AS school_count,
                    COUNT(l.id) AS lot_count,
                    COALESCE(SUM((SELECT COALESCE(SUM(e.quantity_delta),0) FROM stock_events e
                    WHERE e.lot_id=l.id AND e.organization_id=l.organization_id)),0) AS on_hand
                    FROM inventory_lots l JOIN item_catalog i ON i.id=l.item_id
                    JOIN units u ON u.id=i.unit_id WHERE $where
                    GROUP BY i.id,i.name,u.code ORDER BY i.id DESC LIMIT 51";
            }else{
                $sql="SELECT t.id AS id,t.title,t.grade,t.subject,COUNT(DISTINCT b.organization_id) AS school_count,
                    COUNT(b.id) AS batch_count,
                    COALESCE(SUM((SELECT COALESCE(SUM(m.quantity_delta),0) FROM textbook_movements m
                    WHERE m.batch_id=b.id AND m.organization_id=b.organization_id)),0) AS on_hand
                    FROM textbook_batches b JOIN textbook_titles t ON t.id=b.title_id WHERE $where
                    GROUP BY t.id,t.title,t.grade,t.subject ORDER BY t.id DESC LIMIT 51";
            }
            return q($db,$sql,$params)->fetchAll();
        }
        $d=$defs[$type];$alias=$d[5]??$d[3];$marks=implode(',',array_fill(0,count($schoolIds),'?'));
        $where="$alias.organization_id IN ($marks) AND ".$d[2];$params=$schoolIds;
        if($before>0){$where.=" AND {$d[3]}.id<?";$params[]=$before;}
        if($month!=='' && $d[4]!==''){
            $where.=' AND '.$d[4].'>=? AND '.$d[4].'<DATE_ADD(?, INTERVAL 1 MONTH)';
            $params[]=$month.'-01';$params[]=$month.'-01';
        }
        $sql='SELECT '.$d[1].' '.$d[0].' WHERE '.$where.' ORDER BY '.$d[3].'.id DESC LIMIT 51';
        return q($db,$sql,$params)->fetchAll();
    }
    public static function read(PDO $db,array $user,array $input): array {
        $division=idOf($input,'organization_id');
        $type=self::requestedType($input);
        $allOffices=self::access($db,$user,$division);
        $selected=trim((string)($input['school_id']??'')); // Existing API filter key; also accepts Division Office ID.
        if($selected!==''){
            demand(ctype_digit($selected)&&(int)$selected>0,'Invalid office selection',400);
            demand(in_array((int)$selected,array_map(static fn($x)=>(int)$x['id'],$allOffices),true),
                'Office not authorized in this Division consolidation',403);
            $allOffices=array_values(array_filter($allOffices,static fn($s)=>(int)$s['id']===(int)$selected));
        }
        $perms=self::permissions($db,$user,$allOffices);
        $accessible=[];foreach(self::TYPES as $t){
            if($t==='OVERVIEW')continue;
            $accessible[$t]=array_values(array_filter($allOffices,static fn($s)=>!empty($perms[(int)$s['id']][$t])));
        }
        $offices=[];
        foreach($allOffices as $s){$id=(int)$s['id'];$cats=[];
            foreach(self::TYPES as $t)if($t!=='OVERVIEW' && !empty($perms[$id][$t]))$cats[]=$t;
            $kind=(string)$s['kind'];
            $offices[]=['id'=>$id,'name'=>$s['name'],'code'=>$s['code'],'kind'=>$kind,
                'label'=>($kind==='DIVISION'?'Division Office · ':'School · ').$s['name'],'views'=>$cats];
        }
        $month=trim((string)($input['month']??''));
        demand($month===''||preg_match('/^(19|20|21)\d{2}-(0[1-9]|1[0-2])$/D',$month)===1,
            'Invalid report month',400);
        $before=trim((string)($input['before_id']??''));
        demand($before===''||(ctype_digit($before)&&(int)$before>0),'Invalid page cursor',400);
        $base=['division_id'=>$division,'scope'=>'DIVISION_AND_AUTHORIZED_SCHOOLS',
            'offices'=>$offices,'schools'=>$offices,'month'=>$month,'read_only'=>true,
            'note'=>'The Current Asset Register shows ACTIVE property under its present owning office; transferred-out source copies remain historical and are not double-counted. ICS/PAR and movements remain dated historical records. Stock balances are live, not historic month-end snapshots.'];
        if($type==='OVERVIEW'){
            // Summary counts are visibility-aware: never aggregate restricted asset,
            // stock or textbook categories for a user lacking per-school access.
            $counts=[];
            $tableByType=['STOCK'=>'inventory_lots','STOCK_EVENT'=>'stock_events','STOCK_EXCEPTION'=>'inventory_lots',
                'RIS'=>'requisition_issue_slips','ASSET'=>'assets','ASSET_EVENT'=>'asset_movements',
                'ICS_PAR'=>'accountability_documents','RECEIPTS'=>'iar_lines',
                'TEXTBOOK'=>'textbook_batches','TEXTBOOK_EVENT'=>'textbook_movements',
                'STOCK_TOTALS'=>'inventory_lots','TEXTBOOK_TOTALS'=>'textbook_batches'];
            foreach(self::TYPES as $t){if($t==='OVERVIEW')continue;
                $schoolIds=array_map(static fn($s)=>(int)$s['id'],$accessible[$t]);
                if(!$schoolIds){$counts[$t]=null;continue;}
                // COUNT distinct source IDs via whitelisted view, where max row
                // aggregates can be expensive: counts are computed from dedicated
                // scoped source tables, rather than loading complete source rows.
                $table=$tableByType[$t];$marks=implode(',',array_fill(0,count($schoolIds),'?'));
                if($t==='STOCK'||$t==='STOCK_EXCEPTION'){
                    $condition=$t==='STOCK'?"i.category='SUPPLY'":"i.category<>'SUPPLY'";
                    $row=one($db,"SELECT COUNT(*) AS n FROM inventory_lots l JOIN item_catalog i ON i.id=l.item_id WHERE l.organization_id IN ($marks) AND $condition",$schoolIds);
                }elseif($t==='STOCK_TOTALS'){
                    $row=one($db,"SELECT COUNT(DISTINCT l.item_id) AS n FROM inventory_lots l JOIN item_catalog i ON i.id=l.item_id WHERE l.organization_id IN ($marks) AND i.category='SUPPLY'",$schoolIds);
                }elseif($t==='TEXTBOOK_TOTALS'){
                    $row=one($db,"SELECT COUNT(DISTINCT b.title_id) AS n FROM textbook_batches b WHERE b.organization_id IN ($marks)",$schoolIds);
                }elseif($t==='STOCK_EVENT'){
                    $row=one($db,"SELECT COUNT(*) AS n FROM stock_events e JOIN inventory_lots l ON l.id=e.lot_id AND l.organization_id=e.organization_id JOIN item_catalog i ON i.id=l.item_id WHERE e.organization_id IN ($marks) AND i.category='SUPPLY'",$schoolIds);
                }elseif($t==='ASSET'){
                    // An accepted transfer has a historical source row plus one
                    // ACTIVE destination row: count ONLY the current owner.
                    $row=one($db,"SELECT COUNT(*) AS n FROM assets WHERE organization_id IN ($marks) AND status='ACTIVE'",$schoolIds);
                }elseif($t==='ASSET_EVENT'){
                    $row=one($db,"SELECT COUNT(*) AS n FROM asset_movements m JOIN assets a ON a.id=m.asset_id WHERE a.organization_id IN ($marks)",$schoolIds);
                }elseif($t==='RECEIPTS'){
                    $row=one($db,"SELECT COUNT(*) AS n FROM iar_lines il JOIN iars ir ON ir.id=il.iar_id JOIN purchase_orders po ON po.id=ir.po_id AND po.organization_id=ir.organization_id JOIN po_lines pl ON pl.id=il.po_line_id AND pl.po_id=po.id JOIN item_catalog i ON i.id=pl.item_id WHERE ir.organization_id IN ($marks) AND ir.status='FINALIZED' AND po.status='FINALIZED' AND i.category IN ('PPE','SEMI_EXPENDABLE')",$schoolIds);
                }elseif($t==='ICS_PAR'){
                    $row=one($db,"SELECT COUNT(*) AS n FROM accountability_documents WHERE organization_id IN ($marks) AND doc_type IN ('ICS','PAR')",$schoolIds);
                }else{
                    $row=one($db,"SELECT COUNT(*) AS n FROM $table WHERE organization_id IN ($marks)",$schoolIds);
                }
                $counts[$t]=(int)$row['n'];
            }
            return $base+['view'=>'OVERVIEW','counts'=>$counts,'count_definition'=>'Record counts, not distinct items or interchangeable quantities; null means not authorized.'];
        }
        $allowed=$accessible[$type];
        demand((bool)$allowed,'No authorized offices for this consolidated record type',403);
        $ids=array_map(static fn($s)=>(int)$s['id'],$allowed);
        $rows=self::dataset($db,$type,$ids,$before===''?0:(int)$before,$month);
        $hasMore=count($rows)>50;$rows=array_slice($rows,0,50);
        return $base+['view'=>$type,'columns'=>self::FIELDS[$type],'rows'=>$rows,
            'has_more'=>$hasMore,'next_before_id'=>$hasMore?(int)end($rows)['id']:null,
            'page_size'=>50,'eligible_office_count'=>count($ids),
            'eligible_school_count'=>count(array_filter($allowed,static fn($s)=>($s['kind']??'')==='SCHOOL'))];
    }
}