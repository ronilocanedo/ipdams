<?php
declare(strict_types=1);
/** Resolve documentary IAR wet-signature names against date-effective school records.
 * Never infer a person when there is no appointment or several possible AOs.
 * BAC Chair for the IAR Inspection chairman is used at the user's express request.
 */
final class IarSignatories {
    private static function uniqueStaff(PDO $db,int $org,int $employee,string $day): ?array {
        $rows=q($db,'SELECT e.full_name,a.designation,e.position_title FROM employee_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.organization_id=? AND a.employee_id=? AND a.effective_from<=? AND (a.effective_to IS NULL OR a.effective_to>?) ORDER BY a.id DESC',[$org,$employee,$day,$day])->fetchAll();
        if(count($rows)!==1 || trim((string)($rows[0]['full_name']??''))==='')return null;
        return ['employee_id'=>$employee,'name'=>trim((string)$rows[0]['full_name']),
            'designation'=>(string)($rows[0]['designation']?:$rows[0]['position_title']??'')];
    }
    /** The only historical fallback is an explicitly attested, audited custodian appointment.
     * Keep ordinary BAC and AO lookups bound to actual dated assignment rows. */
    private static function historicalCustodian(PDO $db,int $org,int $employee): ?array {
        $rows=q($db,'SELECT e.full_name,a.designation,e.position_title FROM employee_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.organization_id=? AND a.employee_id=? ORDER BY a.id DESC LIMIT 1',[$org,$employee])->fetchAll();
        if(count($rows)!==1 || trim((string)($rows[0]['full_name']??''))==='')return null;
        return ['employee_id'=>$employee,'name'=>trim((string)$rows[0]['full_name']),
            'designation'=>(string)(($rows[0]['designation']??'')?:($rows[0]['position_title']??''))];
    }
    private static function bacEffectivityCorrection(PDO $db,int $org,int $termId,int $chairId,string $day): ?array {
        $version=one($db,'SELECT config_json,effective_from FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',
            [$org,'ORG_BAC_EFFECTIVITY_CORRECTION_V1_'.$termId]);
        if(!$version || (string)$version['effective_from']>$day)return null;
        $v=json_decode((string)$version['config_json'],true,32,JSON_THROW_ON_ERROR);
        if(!is_array($v) || ($v['official_record_confirmed']??false)!==true ||
           (int)($v['term_id']??0)!==$termId || (int)($v['chair_employee_id']??0)!==$chairId)return null;
        return $v;
    }
    /** Re-print an older IAR's originally blank chairman only after an authenticated,
     * audited historical BAC effectivity correction. Stored IAR snapshot is unchanged. */
    public static function correctedBacChair(PDO $db,int $org,string $day): ?array {
        $terms=q($db,'SELECT id FROM bac_terms WHERE organization_id=? AND starts<=? AND (ends IS NULL OR ends>?) ORDER BY id DESC',[$org,$day,$day])->fetchAll();
        if(count($terms)!==1)return null;
        $termId=(int)$terms[0]['id'];
        $chairs=q($db,'SELECT employee_id FROM bac_members WHERE term_id=? AND role_title=?',[$termId,'CHAIR'])->fetchAll();
        if(count($chairs)!==1)return null;
        $chairId=(int)$chairs[0]['employee_id'];
        if(self::bacEffectivityCorrection($db,$org,$termId,$chairId,$day)===null)return null;
        return self::resolve($db,$org,$day)['bac_chair'];
    }
    public static function resolve(PDO $db,int $org,string $day): array {
        demand(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$day)===1,'IAR date required for date-bound signatures',422);
        $result=['bac_chair'=>null,'supply_custodian'=>null];
        $terms=q($db,'SELECT id FROM bac_terms WHERE organization_id=? AND starts<=? AND (ends IS NULL OR ends>?) ORDER BY id DESC',[$org,$day,$day])->fetchAll();
        if(count($terms)===1){
            $termId=(int)$terms[0]['id'];
            $chairs=q($db,'SELECT employee_id FROM bac_members WHERE term_id=? AND role_title=?',[$termId,'CHAIR'])->fetchAll();
            if(count($chairs)===1){
                $chairId=(int)$chairs[0]['employee_id'];
                $result['bac_chair']=self::uniqueStaff($db,$org,$chairId,$day);
                if($result['bac_chair']===null){
                    $c=self::bacEffectivityCorrection($db,$org,$termId,$chairId,$day);
                    if($c!==null && in_array($chairId,$c['historical_assignment_gaps']??[],true))
                        $result['bac_chair']=self::historicalCustodian($db,$org,$chairId);
                }
            }
        }
        $organization=one($db,'SELECT kind FROM organizations WHERE id=? AND active=1',[$org]);
        if(!in_array($organization['kind']??'',['SCHOOL','DIVISION'],true))return $result;
        // Newer corrective appointment revisions can have an EARLIER effective date.
        // Honor their supersession chain rather than reviving an incorrect later date.
        $appointments=q($db,"SELECT id,config_json,effective_from FROM configuration_versions
            WHERE organization_id=? AND config_key='ORG_SUPPLY_PROPERTY_CUSTODIAN_V1'
            AND effective_from<=? ORDER BY effective_from DESC,id DESC",[$org,$day])->fetchAll();
        if($appointments){
            $superseded=[];$values=[];
            foreach($appointments as $row){
                $v=json_decode((string)$row['config_json'],true,32,JSON_THROW_ON_ERROR);
                demand(is_array($v)&&array_key_exists('employee_id',$v),'Invalid custodian appointment history',409);
                $prior=(int)($v['supersedes_version_id']??0);
                if($prior>0)$superseded[$prior]=true;
                $values[]=['id'=>(int)$row['id'],'config'=>$v];
            }
            foreach($values as $record){
                if(isset($superseded[$record['id']]))continue;
                $v=$record['config'];
                if($v['employee_id']!==null){
                    $employee=(int)$v['employee_id'];
                    if($employee>0){
                        $result['supply_custodian']=self::uniqueStaff($db,$org,$employee,$day);
                        if($result['supply_custodian']===null && ($v['historical_appointment_attested']??false)===true)
                            $result['supply_custodian']=self::historicalCustodian($db,$org,$employee);
                    }
                }
                return $result; // Explicit unassignment is authoritative.
            }
            return $result; // Invalid all-superseded chain must never guess an AO.
        }
        // Legacy SCHOOL-only fallback for IARs predating explicit custodian configuration.
        // Do not guess a Division custodian or infer a person from multiple AOs.
        if(($organization['kind']??'')!=='SCHOOL')return $result;
        $rows=q($db,'SELECT a.employee_id,a.designation,a.effective_from,a.effective_to,e.full_name,e.position_title FROM employee_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.organization_id=? ORDER BY a.id DESC',[$org])->fetchAll();
        $current=[];$seen=[];
        foreach($rows as $row){
            $eid=(int)$row['employee_id'];
            if(isset($seen[$eid]))continue;
            $seen[$eid]=true;
            if((string)$row['effective_from']>$day || ($row['effective_to']!==null&&(string)$row['effective_to']<=$day))continue;
            $role=trim((string)($row['designation']??'').' '.(string)($row['position_title']??''));
            if(!preg_match('/\b(?:ADMINISTRATIVE\s+OFFICER|AO)(?:\s+(?:[IVX]+|[1-9]))?\b/i',$role))continue;
            if(trim((string)$row['full_name'])==='')continue;
            $current[]=['employee_id'=>$eid,'name'=>trim((string)$row['full_name']),'designation'=>$role];
        }
        if(count($current)===1)$result['supply_custodian']=$current[0];
        return $result;
    }
}
