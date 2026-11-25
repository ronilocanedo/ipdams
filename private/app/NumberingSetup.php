<?php
declare(strict_types=1);
/** IPDAMS v0.2.227 — scoped atomic multi-form annual starting sequences, protected Settings entrypoints. */
final class IPDAMSNumberingSetup {
    private static function requireRole(array $user): void {
        demand(in_array($user['role']??null,['SUPER_ADMIN','SCHOOL'],true)
            && (int)($user['id']??0)>0,'You cannot manage school numbering',403);
    }
    public const TYPES=['AR','TA','RC','PR','RFQ','AWARD','PO','IAR','DV','RIS','ICS','PAR','ITR','PTR','RRSP','PRR'];
    public static function schoolOptions(PDO $db,array $user): array {
        self::requireRole($user);
        if($user['role']==='SUPER_ADMIN')
            return $db->query("SELECT id,name FROM organizations WHERE kind='SCHOOL' AND active=1 ORDER BY name,id")->fetchAll(PDO::FETCH_ASSOC);
        $st=$db->prepare("SELECT o.id,o.name FROM organizations o JOIN user_organization_scopes s ON s.organization_id=o.id AND s.user_id=? WHERE o.kind='SCHOOL' AND o.active=1 ORDER BY o.name,o.id");
        $st->execute([(int)$user['id']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function existing(PDO $db,array $user): array {
        self::requireRole($user);
        $marks=implode(',',array_fill(0,count(self::TYPES),'?'));
        if ($user['role']==='SUPER_ADMIN') {
            $stmt=$db->prepare("SELECT o.name AS school,c.doc_type,c.period,c.next_sequence FROM document_counters c JOIN organizations o ON o.id=c.organization_id AND o.kind='SCHOOL' WHERE c.doc_type IN ($marks) ORDER BY o.name,c.doc_type,c.period DESC LIMIT 250");
            $stmt->execute(self::TYPES);
        } else {
            $stmt=$db->prepare("SELECT o.name AS school,c.doc_type,c.period,c.next_sequence FROM document_counters c JOIN organizations o ON o.id=c.organization_id AND o.kind='SCHOOL' JOIN user_organization_scopes s ON s.organization_id=o.id AND s.user_id=? WHERE c.doc_type IN ($marks) ORDER BY o.name,c.doc_type,c.period DESC LIMIT 250");
            $stmt->execute(array_merge([(int)$user['id']],self::TYPES));
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function validate(mixed $org,mixed $type,mixed $period,mixed $next): array {
        demand(is_string($org) && preg_match('/^[1-9][0-9]{0,17}$/D',$org)===1,'Choose a school',422);
        demand(is_string($type) && in_array($type,self::TYPES,true),'Unsupported control-number type',422);
        demand(is_string($period) && preg_match('/^20[0-9]{2}$/D',$period)===1,
            'Choose the calendar year (YYYY) for an annual continuing sequence',422);
        demand(is_string($next) && preg_match('/^[1-9][0-9]{0,3}$/D',$next)===1 && (int)$next<=9999,'Starting sequence must be 1 to 9999',422);
        return [(int)$org,$type,$period.'-00',(int)$next];
    }
    /** Only nonblank form rows are scheduled; incomplete/tampered entries fail closed. */
    public static function requestedRows(mixed $periods,mixed $sequences): array {
        demand(is_array($periods)&&is_array($sequences),'Invalid School Numbering form',422);
        foreach([$periods,$sequences] as $field){
            demand(count($field)<=count(self::TYPES),'Too many numbering entries',422);
            foreach(array_keys($field) as $type){
                demand(is_string($type)&&in_array($type,self::TYPES,true),'Unsupported form in School Numbering',422);
            }
        }
        $rows=[];
        foreach(self::TYPES as $type){
            $period=$periods[$type]??'';$next=$sequences[$type]??'';
            demand(is_string($period)&&is_string($next),'Invalid year or sequence for '.$type,422);
            $period=trim($period);$next=trim($next);
            if($period===''&&$next==='')continue;
            demand($period!==''&&$next!=='','Enter both YYYY and Next Series for '.$type.' or leave both empty',422);
            // The organization ID is validated separately; reuse the existing type/year/sequence contract.
            [,,$validPeriod,$validNext]=self::validate('1',$type,$period,$next);
            $rows[]=['type'=>$type,'period'=>$validPeriod,'next'=>$validNext];
        }
        demand($rows!==[],'Enter a year and next series for at least one form',422);
        return $rows;
    }
    /** All selected form sequences are saved in ONE transaction; any conflict rolls back all. */
    public static function saveMany(PDO $db,array $user,mixed $org,mixed $periods,mixed $sequences): array {
        self::requireRole($user);
        $actor=(int)$user['id'];
        demand(is_string($org)&&preg_match('/^[1-9][0-9]{0,17}$/D',$org)===1,'Choose a school',422);
        $schoolId=(int)$org;
        $rows=self::requestedRows($periods,$sequences);
        demand(!$db->inTransaction(),'Complete the previous operation first',409);
        demand(!is_file(settingsPrivate().'/reset-maintenance.lock'),'System reset is in progress',503);
        $lock=settingsLock();
        try{
            demand(!is_file(settingsPrivate().'/reset-maintenance.lock'),'System reset is in progress',503);
            $db->beginTransaction();
            try{
                // Permission is verified inside this same write transaction. A posted school_id
                // cannot grant access to another school, even if the UI is tampered with.
                if($user['role']==='SUPER_ADMIN')
                    $school=one($db,"SELECT id,name FROM organizations WHERE id=? AND kind='SCHOOL' AND active=1",[$schoolId]);
                else
                    $school=one($db,"SELECT o.id,o.name FROM organizations o JOIN user_organization_scopes s ON s.organization_id=o.id AND s.user_id=? WHERE o.id=? AND o.kind='SCHOOL' AND o.active=1",[$actor,$schoolId]);
                demand($school!==null,'School is not assigned to your account or is inactive',403);
                foreach($rows as $row){
                    $type=$row['type'];$period=$row['period'];$next=$row['next'];
                    // Use identical counter and registry locks as the previous single-form editor.
                    $st=$db->prepare('SELECT next_sequence FROM document_counters WHERE organization_id=? AND doc_type=? AND period=? FOR UPDATE');
                    $st->execute([$schoolId,$type,$period]);$st->fetchColumn();
                    $year=substr($period,0,4);
                    // Any month already issued locks the annual starting sequence.
                    $st=$db->prepare('SELECT id FROM document_registry WHERE organization_id=? AND doc_type=? AND period>=? AND period<=? LIMIT 1 FOR UPDATE');
                    $st->execute([$schoolId,$type,$year.'-01',$year.'-12']);
                    demand($st->fetchColumn()===false,
                        $type.' for '.$year.' already has issued documents. Its annual start is locked.',409);
                    // A year-level setting may not undercut an old configured monthly number.
                    $legacy=$db->prepare('SELECT next_sequence FROM document_counters WHERE organization_id=? AND doc_type=? AND period>=? AND period<=? FOR UPDATE');
                    $legacy->execute([$schoolId,$type,$year.'-01',$year.'-12']);
                    foreach($legacy->fetchAll(PDO::FETCH_ASSOC) as $older){
                        demand($next>=(int)$older['next_sequence'],
                            $type.' annual starting series cannot be below an older monthly setting. Review historic test settings.',409);
                    }
                    $st=$db->prepare('INSERT INTO document_counters (organization_id,doc_type,period,next_sequence) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE next_sequence=VALUES(next_sequence)');
                    $st->execute([$schoolId,$type,$period,$next]);
                    $details=json_encode(['organization_id'=>$schoolId,'document_type'=>$type,'period'=>$period,'next_sequence'=>$next],JSON_THROW_ON_ERROR);
                    $st=$db->prepare("INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,'document.counter.setup','DOCUMENT_COUNTER',0,?)");
                    $st->execute([$schoolId,$actor,$details]);
                }
                $db->commit();
            }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        }finally{flock($lock,LOCK_UN);fclose($lock);}
        settingsAudit($actor,'document.counter.batch_setup',['organization_id'=>$schoolId,'count'=>count($rows),'types'=>array_column($rows,'type')]);
        return ['school'=>(string)$school['name'],'count'=>count($rows),'forms'=>array_column($rows,'type')];
    }
    /** Keep the prior single-form server entry point for compatibility. */
    public static function save(PDO $db,array $user,mixed $org,mixed $type,mixed $period,mixed $next): array {
        [$validOrg,$validType,$validPeriod,$validNext]=self::validate($org,$type,$period,$next);
        $saved=self::saveMany($db,$user,(string)$validOrg,[$validType=>(string)$period],[$validType=>(string)$validNext]);
        return ['school'=>$saved['school'],'type'=>$validType,'period'=>substr($validPeriod,0,4),'next'=>$validNext];
    }
}
