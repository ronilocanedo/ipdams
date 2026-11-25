<?php
declare(strict_types=1);
/**
 * BIR Form 2307 preparation. Read-only grouping of ORIGINAL saved
 * withholding components for the SELECTED DV within its original quarter.
 * Other vouchers for the same supplier are NEVER included.
 * No rate-based tax calculations or postings.
 * The office head is the authorized paper signatory; personal TIN is read from
 * the EXISTING employee documentary profile, not from the office payor TIN.
 */
final class Bir2307 {
    public const PAYOR_KEY='BIR2307_PAYOR_IDENTITY_V1';
    private static function compactTin(string $tin): string {
        return preg_replace('/[^0-9]/','',$tin) ?? '';
    }
    public static function validTin(string $tin): bool {
        if(preg_match('/^[0-9][0-9 -]*[0-9]$/D',trim($tin))!==1)return false;
        $digits=self::compactTin($tin);
        return strlen($digits)===12 || strlen($digits)===14;
    }
    /** @return array{from:string,to:string,month:int} */
    public static function quarter(string $date): array {
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        demand($dt!==false && $dt->format('Y-m-d')===$date,'This DV has no verified document date.',409);
        $m=(int)$dt->format('n');$start=1+3*intdiv($m-1,3);
        $from=sprintf('%s-%02d-01',$dt->format('Y'),$start);
        $end=DateTimeImmutable::createFromFormat('!Y-m-d',$from)->modify('+3 months -1 day')->format('Y-m-d');
        return ['from'=>$from,'to'=>$end,'month'=>$start];
    }
    /** Sum only the saved source amounts, NEVER recompute withholding from rate. */
    public static function consolidate(array $rows,array $period): array {
        $result=[];$sources=[];$unknown=[];
        foreach($rows as $r){
            $kind=(string)($r['tax_kind']??'');
            if(!in_array($kind,['EWT','VAT'],true)){$unknown[]=(int)$r['dv_id'];continue;}
            $date=(string)$r['document_date'];
            demand($date >= $period['from'] && $date <= $period['to'],'Out-of-quarter tax component encountered.',409);
            $month=(int)substr($date,5,2)-$period['month'];
            demand($month>=0 && $month<3,'Withholding month does not match the selected quarter.',409);
            $code=(string)$r['atc_code'];
            demand(preg_match('/^[A-Z0-9]{3,24}$/D',$code)===1,'Saved ATC requires review.',409);
            $key=$kind.'|'.$code;
            if(!isset($result[$key]))$result[$key]=['kind'=>$kind,'atc'=>$code,'description'=>(string)($r['description']??$code),'monthly_cents'=>[0,0,0],'withheld_cents'=>0,'source_components'=>0];
            $result[$key]['monthly_cents'][$month]+=moneyCents($r['tax_base']);
            $result[$key]['withheld_cents']+=moneyCents($r['amount']);
            $result[$key]['source_components']++;
            $sources[(int)$r['dv_id']]=(string)$r['dv_number'];
        }
        ksort($result);
        $out=[];$totals=['EWT'=>0,'VAT'=>0];
        foreach($result as $row){
            $totals[$row['kind']]+=$row['withheld_cents'];
            $row['monthly']=array_map('moneyString',$row['monthly_cents']);
            $row['total']=moneyString(array_sum($row['monthly_cents']));
            $row['withheld']=moneyString($row['withheld_cents']);
            unset($row['monthly_cents'],$row['withheld_cents']);$out[]=$row;
        }
        return ['rows'=>$out,'sources'=>$sources,'unknown_dv_ids'=>array_values(array_unique($unknown)),
            'totals'=>['EWT'=>moneyString($totals['EWT']),'VAT'=>moneyString($totals['VAT'])]];
    }
    /**
     * Resolve the entity whose registered BIR identity belongs on Form 2307.
     * Source DVs always belong to the requesting school. Non-IUS schools use
     * their parent Division's registered payor identity, never a copied or
     * independently editable school-level TIN. School Head remains signatory.
     * Classification is read as of the source DV date (historical safety).
     */
    private static function payorOffice(PDO $db,array $office,string $asOf): array {
        if((string)$office['kind']==='DIVISION')return [
            'identity_office'=>$office,'classification'=>'DIVISION','issue'=>null];
        demand((string)$office['kind']==='SCHOOL','BIR 2307 requires a school or Division Office.',409);
        $school=(int)$office['id'];
        $history=q($db,"SELECT id,config_json FROM configuration_versions
            WHERE organization_id=? AND config_key='SCHOOL_IMPLEMENTATION_CLASS'
              AND effective_from<=?
            ORDER BY effective_from DESC,id DESC",[$school,$asOf])->fetchAll();
        // Use the same effective-dated, superseded-version resolution used by
        // IPDAMS's existing Organization policy screens.
        $superseded=[];$candidates=[];$classification=null;
        foreach($history as $version){
            $config=json_decode((string)$version['config_json'],true);
            demand(is_array($config) && in_array(($config['classification']??null),['IUS','NON_IUS'],true),
                'School implementation classification needs administrator review.',409);
            $prior=(int)($config['supersedes_version_id']??0);
            if($prior>0)$superseded[$prior]=true;
            $candidates[]=['id'=>(int)$version['id'],'class'=>(string)$config['classification']];
        }
        foreach($candidates as $candidate){
            if(!isset($superseded[$candidate['id']])){
                $classification=$candidate['class'];break;
            }
        }
        if($classification===null)return ['identity_office'=>null,'classification'=>null,
            'issue'=>'School IUS / Non-IUS classification is not verified for the source DV date. Ask the Division to configure it.'];
        if($classification==='IUS')return ['identity_office'=>$office,'classification'=>'IUS','issue'=>null];
        $divisionId=(int)($office['parent_division_id']??0);
        if($divisionId<=0)return ['identity_office'=>null,'classification'=>'NON_IUS',
            'issue'=>'Non-IUS school must be linked to its Division Office before BIR 2307 can be prepared.'];
        $division=one($db,"SELECT id,name,kind,address,parent_division_id FROM organizations
            WHERE id=? AND kind='DIVISION' AND active=1",[$divisionId]);
        if($division===null)return ['identity_office'=>null,'classification'=>'NON_IUS',
            'issue'=>'The parent Division Office is unavailable. Verify the school-to-Division linkage.'];
        return ['identity_office'=>$division,'classification'=>'NON_IUS','issue'=>null];
    }
    private static function mayEditPayor(PDO $db,array $actor,int $org,int $payorOrg,string $classification): bool {
        $role=(string)($actor['role']??'');
        if(!in_array($role,['SUPER_ADMIN','DIVISION','SCHOOL'],true) || $payorOrg<=0)return false;
        if($classification==='NON_IUS'){
            if($role==='SUPER_ADMIN')return true;
            if($role!=='DIVISION')return false;
            return one($db,'SELECT 1 FROM user_organization_scopes WHERE user_id=? AND organization_id=?',
                [(int)$actor['id'],$payorOrg])!==null;
        }
        // A School user cannot edit another office's registered BIR profile.
        return $role!=='SCHOOL' || $org===$payorOrg;
    }
    public static function read(PDO $db,array $actor,int $org,int $dvId): array {
        scope($db,$actor,$org);
        demand(($actor['role']??'')!=='VIEWER','BIR certificate preparation requires an authorized operational account.',403);
        $dv=one($db,'SELECT id,organization_id,supplier_id,dv_type,status,display_number,document_date,gross,deductions,net,payee,business_name_snapshot FROM disbursement_vouchers WHERE organization_id=? AND id=?',[$org,$dvId]);
        demand($dv!==null,'Disbursement voucher unavailable for this office.',404);
        demand($dv['status']==='FINALIZED' && trim((string)$dv['display_number'])!=='','Only a finalized numbered DV can be used for BIR 2307.',409);
        demand((int)($dv['supplier_id']??0)>0,'This DV does not have an identified supplier; BIR 2307 requires verified payee identity.',409);
        $period=self::quarter((string)($dv['document_date']??''));
        $office=one($db,'SELECT id,name,kind,address,parent_division_id FROM organizations WHERE id=? AND active=1',[$org]);
        demand($office!==null,'Office unavailable.',404);
        $supplier=one($db,'SELECT id,name,tax_id,address,zip_code FROM suppliers WHERE id=?',[(int)$dv['supplier_id']]);
        demand($supplier!==null,'The original DV supplier needs review.',409);
        $resolution=self::payorOffice($db,$office,(string)$dv['document_date']);
        $identityOffice=$resolution['identity_office'];
        $identityOrg=(int)($identityOffice['id']??0);
        $conf=$identityOrg>0?one($db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',[$identityOrg,self::PAYOR_KEY]):null;
        $saved=$conf?json_decode((string)$conf['config_json'],true):[];
        if(!is_array($saved))$saved=[];
        $payor=['registered_name'=>(string)($saved['registered_name']??$identityOffice['name']??'Unverified registered payor'),
            'tin'=>(string)($saved['tin']??''),
            // For a Division (including Non-IUS inherited payor), the
            // Organization Division address is the single address source.
            'address'=>(string)((($identityOffice['kind']??'')==='DIVISION')?
                ($identityOffice['address']??''):($saved['address']??$identityOffice['address']??'')),
            'zip'=>(string)($saved['zip']??''),'version_id'=>(int)($conf['id']??0),
            'source_organization_id'=>$identityOrg,
            'source_kind'=>(string)($identityOffice['kind']??''),
            'classification'=>$resolution['classification'],
            'editable'=>self::mayEditPayor($db,$actor,$org,$identityOrg,(string)($resolution['classification']??''))];
        // Link existing Head of Office appointment -> staff assignment ->
        // EXISTING employee documentary identity TIN. Never infer from user login.
        $headConfig=one($db,"SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key='ORG_OFFICE_HEAD_V1' ORDER BY id DESC LIMIT 1",[$org]);
        $headData=$headConfig?json_decode((string)$headConfig['config_json'],true):[];
        $headId=(int)($headData['employee_id']??0);$head=null;
        if($headId>0){
            $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
            $person=one($db,'SELECT e.full_name,e.position_title,a.designation FROM employees e JOIN employee_assignments a ON a.employee_id=e.id WHERE a.organization_id=? AND e.id=? AND a.effective_from<=? AND (a.effective_to IS NULL OR a.effective_to>?) ORDER BY a.effective_from DESC,a.id DESC LIMIT 1',[$org,$headId,$today,$today]);
            if($person!==null){
                $profile=one($db,'SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',[$org,'EMPLOYEE_DV_IDENTITY_V1_'.$headId]);
                $personal=$profile?json_decode((string)$profile['config_json'],true):[];
                $head=['employee_id'=>$headId,'name'=>(string)$person['full_name'],
                    'designation'=>trim((string)($person['designation']?:$person['position_title'])),
                    'tin'=>(string)($personal['tin']??'')];
            }
        }
        // Selected-DV certificate: NEVER add other transactions from the same
        // supplier (even in the same month/quarter). Keep the original DV's
        // saved tax bases and withheld amounts; do not recalculate any tax.
        // All predicates are checked server-side, independent of the UI link.
        $rows=q($db,"SELECT w.dv_id,w.atc_code,w.tax_base,w.amount,t.tax_kind,t.description,
                    v.display_number AS dv_number,v.document_date
                FROM withholding_components w
                JOIN disbursement_vouchers v ON v.id=w.dv_id
                LEFT JOIN tax_atcs t ON t.code=w.atc_code
                WHERE v.organization_id=? AND v.id=? AND v.supplier_id=?
                  AND v.status='FINALIZED' AND v.document_date BETWEEN ? AND ?
                ORDER BY w.id LIMIT 501",[$org,$dvId,$dv['supplier_id'],$period['from'],$period['to']])->fetchAll();
        demand(count($rows)<=500,'Selected DV has more than 500 saved tax components; accounting review is required.',409);
        $consolidated=self::consolidate($rows,$period);
        // A certificate opened from DV X must never contain DV Y. This is
        // checked again after consolidation for defense in depth.
        foreach(array_keys($consolidated['sources']) as $sourceId){
            demand((int)$sourceId===$dvId,'Cross-voucher BIR 2307 source rejected.',409);
        }
        $sourceIds=array_keys($consolidated['sources']);
        $unreleased=[];
        if($sourceIds){
            $marks=implode(',',array_fill(0,count($sourceIds),'?'));
            $checks=q($db,"SELECT dv_id,status FROM checks WHERE organization_id=? AND dv_id IN ($marks)",[$org,...$sourceIds])->fetchAll();
            $released=[];
            foreach($checks as $check){if(in_array($check['status'],['RELEASED','CLEARED'],true))$released[(int)$check['dv_id']]=true;}
            foreach($consolidated['sources'] as $sourceId=>$no)if(!isset($released[(int)$sourceId]))$unreleased[]=$no;
        }
        $issues=[];
        if($resolution['issue']!==null)$issues[]=$resolution['issue'];
        $identityLabel=$resolution['classification']==='NON_IUS'?'Division Office':'Office';
        if($identityOrg>0 && !trim((string)($saved['registered_name']??'')))$issues[]=$identityLabel.' registered BIR name must be confirmed under Organization Profile.';
        if(!self::validTin($payor['tin']))$issues[]=$identityLabel.' registered TIN is missing or requires a 12/14-digit verification.';
        if(trim($payor['address'])===''||trim($payor['zip'])==='')$issues[]=$identityLabel.' registered address and ZIP must be configured.';
        if(!self::validTin((string)($supplier['tax_id']??'')))$issues[]='Supplier registered TIN is missing or invalid.';
        if(trim((string)($supplier['address']??''))===''||trim((string)($supplier['zip_code']??''))==='')$issues[]='Supplier registered address and ZIP must be completed in Reference Data.';
        if($head===null)$issues[]='Assign an active Head of Office employee in Organization > Officials.';
        elseif(!self::validTin($head['tin']))$issues[]='The appointed Head of Office employee needs a verified personal TIN in their employee DV profile.';
        if(!$consolidated['rows'])$issues[]='The selected DV has no saved EWT / business-tax withholding components.';
        if($consolidated['unknown_dv_ids'])$issues[]='Some withholding ATCs have no supported EWT/VAT classification; review the ATC registry.';
        $ewt=count(array_filter($consolidated['rows'],static fn($r)=>$r['kind']==='EWT'));
        $vat=count($consolidated['rows'])-$ewt;
        if($ewt>10||$vat>10)$issues[]='Form 2307 row capacity exceeded; accounting must review certificate layout.';
        if($unreleased)$issues[]='Payment/release not verified for: '.implode(', ',array_slice($unreleased,0,8)).'. Preview is not proof of issuance.';
        return ['office'=>$office,'payor'=>$payor,'head'=>$head,
            'supplier'=>['id'=>(int)$supplier['id'],'name'=>(string)$supplier['name'],'tin'=>(string)($supplier['tax_id']??''),
                'address'=>(string)($supplier['address']??''),'zip'=>(string)($supplier['zip_code']??'')],
            'dv'=>['id'=>(int)$dv['id'],'number'=>(string)$dv['display_number']],
            'period'=>$period,'rows'=>$consolidated['rows'],'saved_withholding_totals'=>$consolidated['totals'],
            'source_dvs'=>array_values($consolidated['sources']),
            'unreleased_dvs'=>$unreleased,'issues'=>$issues,'ready'=>!$issues,
            'form_template'=>'2307 Jan 2018 ENCS (user-provided reference)',
            'disclaimer'=>'Unsigned certificate preparation, not evidence of remittance, payment or BIR filing.'];
    }
    /**
     * Read the single versioned BIR identity used by certificate preparation.
     * The organization profile is only a second entrypoint to the same record;
     * Non-IUS School profiles inherit Division identity read-only.
     */
    public static function profile(PDO $db,array $actor,int $org): array {
        scope($db,$actor,$org);
        demand(($actor['role']??'')!=='VIEWER','An operational account is required for BIR identity.',403);
        $office=one($db,'SELECT id,name,kind,address,parent_division_id FROM organizations WHERE id=? AND active=1',[$org]);
        demand($office!==null,'Office unavailable.',404);
        $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $resolution=self::payorOffice($db,$office,$today);
        $identityOffice=$resolution['identity_office'];
        $identityOrg=(int)($identityOffice['id']??0);
        $conf=$identityOrg>0?one($db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',[$identityOrg,self::PAYOR_KEY]):null;
        $saved=$conf?json_decode((string)$conf['config_json'],true):[];
        if(!is_array($saved))$saved=[];
        $class=(string)($resolution['classification']??'');
        return ['target_organization_id'=>$org,'payor_organization_id'=>$identityOrg,
            'classification'=>$class,'source_kind'=>(string)($identityOffice['kind']??''),
            'operational_name'=>(string)($identityOffice['name']??''),
            'registered_name'=>(string)($saved['registered_name']??''),
            'tin'=>(string)($saved['tin']??''),
            // For a Division (including Non-IUS inherited payor), the
            // Organization Division address is the single address source.
            'address'=>(string)((($identityOffice['kind']??'')==='DIVISION')?
                ($identityOffice['address']??''):($saved['address']??$identityOffice['address']??'')),
            'zip'=>(string)($saved['zip']??''),
            'version_id'=>(int)($conf['id']??0),
            // Configure shared Division credentials on the Division Profile,
            // not by writing through a child School profile.
            'editable'=>$identityOrg===$org && self::mayEditPayor($db,$actor,$org,$identityOrg,$class),
            'issue'=>$resolution['issue']];
    }
    /** Common audited write for Organization Profile and DV readiness. */
    private static function writeIdentity(PDO $db,array $actor,int $sourceOrg,int $identityOrg,array $input): array {
        demand($identityOrg>0,'Registered payor organization is missing.',409);
        demand(isset($input['expected_payor_organization_id']) &&
            (int)$input['expected_payor_organization_id']===$identityOrg,
            'The payor office has changed. Refresh and review before saving.',409);
        $tin=trim((string)($input['tin']??''));
        $addr=trim((string)($input['address']??''));
        $zip=trim((string)($input['zip']??''));
        demand(self::validTin($tin),'Enter the verified 12- or 14-digit registered office TIN.',422);
        demand(mbSafeLen($addr)>=8&&mbSafeLen($addr)<=400&&!preg_match('/[\x00-\x1F\x7F]/',$addr),'Registered office address must be 8–400 characters.',422);
        demand(preg_match('/^[0-9]{4,6}$/D',$zip)===1,'Enter the registered 4–6 digit postal code.',422);
        $expected=$input['expected_version']??null;
        demand(is_numeric($expected)&&(int)$expected>=0,'Refresh office payor identity before saving.',409);
        // A unified Organization update owns an outer transaction. Use that
        // transaction for both organization details and the versioned BIR record;
        // standalone BIR saves still open/commit their own transaction.
        $ownsTransaction=!$db->inTransaction();
        if($ownsTransaction)$db->beginTransaction();
        try{
            $orgRow=one($db,'SELECT id,name,kind,address FROM organizations WHERE id=? AND active=1 FOR UPDATE',[$identityOrg]);
            demand($orgRow!==null,'Payor Office unavailable.',404);
            if((string)$orgRow['kind']==='DIVISION'){
                // Even an older BIR-readiness form cannot create a second,
                // divergent Division address. Only Organization's address wins.
                $addr=trim((string)($orgRow['address']??''));
                demand(mbSafeLen($addr)>=8&&mbSafeLen($addr)<=400&&
                    !preg_match('/[\x00-\x1F\x7F]/',$addr),
                    'Complete the Division address under Organization before saving the BIR identity.',422);
            }
            $previous=one($db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',[$identityOrg,self::PAYOR_KEY]);
            demand((int)($previous['id']??0)===(int)$expected,'Payor identity changed; refresh before saving.',409);
            $prior=$previous?json_decode((string)$previous['config_json'],true):[];
            if(!is_array($prior))$prior=[];
            // Older readiness clients omitted registered_name: retain a verified
            // previously saved name, never erase it on a subsequent TIN edit.
            $registeredName=trim((string)($input['registered_name']??$prior['registered_name']??''));
            demand(mbSafeLen($registeredName)>=3 && mbSafeLen($registeredName)<=200 &&
                !preg_match('/[\x00-\x1F\x7F]/',$registeredName),
                'Enter the registered BIR name (3–200 characters) as shown in official tax records.',422);
            q($db,'INSERT INTO configuration_versions (organization_id,config_key,config_json,effective_from,review_note) VALUES (?,?,?,CURRENT_DATE(),?)',
                [$identityOrg,self::PAYOR_KEY,json_encode(['registered_name'=>$registeredName,'tin'=>$tin,'address'=>$addr,'zip'=>$zip],JSON_THROW_ON_ERROR),'Registered BIR payor identity (Organization Profile / Form 2307)']);
            $version=(int)$db->lastInsertId();
            q($db,'INSERT INTO audit_events (organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
                [$identityOrg,$actor['id'],'BIR2307_PAYOR_IDENTITY_UPDATED','CONFIGURATION',$version,json_encode([
                    'fields'=>['registered_name','tin','address','zip'],'previous_version'=>(int)($previous['id']??0),
                    'source_organization_id'=>$sourceOrg,'payor_organization_id'=>$identityOrg],JSON_THROW_ON_ERROR)]);
            if($ownsTransaction)$db->commit();
            return ['version_id'=>$version,'saved'=>true,'payor_organization_id'=>$identityOrg];
        }catch(Throwable $e){
            if($ownsTransaction && $db->inTransaction())$db->rollBack();
            throw $e;
        }
    }
    public static function saveProfile(PDO $db,array $actor,int $org,array $input): array {
        scope($db,$actor,$org);allowedToWrite($actor);
        $profile=self::profile($db,$actor,$org);
        demand($profile['issue']===null,$profile['issue']??'Office classification needs verification.',409);
        demand($profile['editable'] && (int)$profile['payor_organization_id']===$org,
            'This profile cannot edit another office’s shared BIR details.',403);
        return self::writeIdentity($db,$actor,$org,$org,$input);
    }
    public static function savePayor(PDO $db,array $actor,int $org,int $dvId,array $input): array {
        scope($db,$actor,$org);allowedToWrite($actor);
        $dv=one($db,'SELECT id,document_date,status FROM disbursement_vouchers WHERE organization_id=? AND id=?',[$org,$dvId]);
        demand($dv!==null && $dv['status']==='FINALIZED','Finalized source DV required to configure BIR identity.',409);
        $office=one($db,'SELECT id,name,kind,address,parent_division_id FROM organizations WHERE id=? AND active=1',[$org]);
        demand($office!==null,'Office unavailable.',404);
        $resolution=self::payorOffice($db,$office,(string)$dv['document_date']);
        demand($resolution['issue']===null,$resolution['issue']??'Payor Office must be verified.',409);
        $identityOrg=(int)$resolution['identity_office']['id'];
        demand(self::mayEditPayor($db,$actor,$org,$identityOrg,(string)$resolution['classification']),
            'You cannot edit this registered BIR identity.',403);
        if($identityOrg!==$org)scope($db,$actor,$identityOrg);
        return self::writeIdentity($db,$actor,$org,$identityOrg,$input);
    }
}
