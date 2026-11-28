<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/AnnualDocumentNumbering.php';
require_once __DIR__.'/PropertyReturnWorkflow.php';
require_once __DIR__.'/DivisionSchoolAccess.php';
require_once __DIR__.'/DivisionPropertyRegistry.php';
require_once __DIR__.'/PropertyRegistrationNumbers.php';
require_once __DIR__.'/PropertyBatchMetadata.php';
require_once __DIR__.'/IarAutoPropertyPolicy.php';
require_once __DIR__.'/EmployeeClaims.php';
require_once __DIR__.'/PrUacsInheritance.php';
require_once __DIR__.'/ReimbursementFinalization.php';
require_once __DIR__.'/FundCashFundingPreview.php';
require_once __DIR__.'/FundCashFinalization.php';
require_once __DIR__.'/RetainedTaxBalance.php';
require_once __DIR__.'/TaxTransferRules.php';
require_once __DIR__.'/FinancialReview.php';
require_once __DIR__.'/FinancialAllocation.php';
final class Domain {
    // Stable reference codes for the Division's semi-expendable classifications.
    // The accounting parent category remains SEMI_EXPENDABLE (ICS and procurement).
    private const SEMI_SUBCATEGORIES = [
        'ICT_EQUIPMENT', 'ICT_EQUIPMENT_DCP', 'ICT_EQUIPMENT_ADM', 'FURNITURE_FIXTURES',
        'MEDICAL_DENTAL_EQUIPMENT', 'OFFICE_EQUIPMENT',
        'OTHER_MACHINERIES_EQUIPMENT', 'OTHER_SUPPLIES_MATERIALS',
        'TLE_TVL_EQUIPMENT', 'SPORTS_EQUIPMENT'
    ];
    // Versioned Division-owned reference list. No new table is needed: the
    // application's existing configuration_versions table stores revisions.
    // v0.2.49: append-only Division policy and school IUS classification.
    // These use the existing configuration_versions table, with one entry for
    // each effective-dated change. There is no destructive schema migration.
    private const SCHOOL_IMPLEMENTATION_KEY='SCHOOL_IMPLEMENTATION_CLASS';
    private const DIVISION_FUND_SOURCES_KEY='DIVISION_FUND_SOURCES';
    // IU direct remittance to BIR is a separate policy decision; the existing
    // withholding-components REMIT disposition and remittance evidence flow
    // remain intact. No school tax cash or fund allocation is created here.
    private const FUND_WITHHOLDING_MODES=['NOT_CONFIGURED','REMIT_TO_DIVISION','DIVISION_SETTLES_UTILIZABLE','IUS_REMIT_DIRECT_BIR'];
    private const DIVISION_ITEM_UACS_KEY='DIVISION_ITEM_UACS';
    private const DIVISION_STANDARD_VAT_KEY='DIVISION_STANDARD_VAT';
    private static function policyDate($value): string {
        demand(is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)===1,'Choose a valid policy effective date');
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        demand($dt && $dt->format('Y-m-d')===$value,'Invalid effective date');
        return $value;
    }
    /** Transaction-document date only. Future effective dates of policy/configuration
     * records are intentionally handled by a separate validator. */
    private static function transactionDocumentDate(string $value,string $label): string {
        demand((bool)preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value),
            $label.' must be a date in YYYY-MM-DD format.');
        [$year,$month,$day]=array_map('intval',explode('-',$value));
        demand($year>=1900 && $year<=2100 && checkdate($month,$day,$year),
            $label.' must be a real calendar date.');
        demand($value<=self::todayManila(),$label.' cannot be in the future.');
        return $value;
    }
    private static function todayManila(): string {
        return (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    }
    private static function taxCheckPayeeKey(string $code): string {
        return 'TAX_PAYEE_'.substr(hash('sha256',$code),0,32);
    }
    private function taxCheckPayeeConfig(int $division,string $code): array {
        $row=one($this->db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',
            [$division,self::taxCheckPayeeKey($code)]);
        if(!$row)return ['payee'=>'','version_id'=>0];
        $saved=json_decode((string)$row['config_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($saved)&&($saved['source_code']??null)===$code && is_string($saved['payee']??null)
            && trim($saved['payee'])!=='' && mbSafeLen($saved['payee'])<=180,'Invalid Division check payee configuration',500);
        return ['payee'=>$saved['payee'],'version_id'=>(int)$row['id']];
    }
    /** Update only the check payee for a Division fund SOURCE. This must not rename
     * a bank account or retroactively rewrite an existing DV/check. */
    public function saveTaxCheckPayee(array $d): array {
        $org=$this->org($d);$division=$this->scopedDivision($org);
        demand(in_array($this->user['role'],['DIVISION','SUPER_ADMIN'],true),'Division administrator required',403);
        $code=strtoupper(requiredString($d,'source_code',60));
        demand((bool)preg_match('/^[A-Z0-9_-]{1,60}$/D',$code),'Invalid fund source code',422);
        $payee=requiredString($d,'check_payee',180);
        demand(!preg_match('/[\x00-\x1F\x7F]/',$payee),'Invalid control character in check payee',422);
        $expected=$d['expected_version']??null;
        demand(is_int($expected)&&$expected>=0,'Refresh the payee settings before saving',409);
        return $this->tx(function()use($division,$code,$payee,$expected){
            $this->divisionForActor($division);
            $catalog=$this->divisionFundSourceList($division);$found=false;
            foreach($catalog['sources'] as $s)if(($s['code']??'')===$code&&($s['active']??false)===true)$found=true;
            demand($found,'Activate this fund in the Division source directory first',409);
            $prior=$this->taxCheckPayeeConfig($division,$code);
            demand($expected===$prior['version_id'],'Payee configuration changed in another session. Refresh.',409);
            demand($payee!==$prior['payee'],'Check payee has not changed',422);
            $id=$this->insert('configuration_versions',[
                'organization_id'=>$division,'config_key'=>self::taxCheckPayeeKey($code),
                'config_json'=>json_encode(['source_code'=>$code,'payee'=>$payee],JSON_THROW_ON_ERROR),
                'effective_from'=>self::todayManila(),'review_note'=>'Division tax-transfer check payee updated']);
            $this->audit($division,'FUND_TAX_CHECK_PAYEE_UPDATED','FUND',$id,[
                'source_code'=>$code,'previous_payee'=>$prior['payee'],'check_payee'=>$payee,
                'previous_version'=>$expected,'historical_dvs_unchanged'=>true]);
            return ['source_code'=>$code,'check_payee'=>$payee,'version_id'=>$id];
        });
    }
    private static function fundPolicyKey(string $code,string $classification): string {
        return 'FWP_'.substr(hash('sha256',$code.'|'.$classification),0,32);
    }
    private function currentSchoolClass(int $school,string $asOf): ?array {
        // Resolve effective-dated revisions without erasing earlier config rows.
        // If an administrator corrects a historical effective date, the new row
        // supersedes the prior *version*, even when its date is earlier. This
        // prevents the old, later date from remaining the displayed/current one.
        $rows=q($this->db,'SELECT id,config_json,effective_from FROM configuration_versions WHERE organization_id=? AND config_key=? AND effective_from<=? ORDER BY effective_from DESC,id DESC',
            [$school,self::SCHOOL_IMPLEMENTATION_KEY,$asOf])->fetchAll();
        $superseded=[];$candidates=[];
        foreach($rows as $row){
            $config=json_decode((string)$row['config_json'],true,512,JSON_THROW_ON_ERROR);
            demand(is_array($config) && in_array(($config['classification']??''),['IUS','NON_IUS'],true),'Invalid school implementation classification record',500);
            $prior=(int)($config['supersedes_version_id']??0);
            if($prior>0)$superseded[$prior]=true;
            $candidates[]=['classification'=>$config['classification'],'effective_from'=>$row['effective_from'],'version_id'=>(int)$row['id']];
        }
        foreach($candidates as $candidate)if(!isset($superseded[$candidate['version_id']]))return $candidate;
        return null;
    }
    private function divisionFundSourceList(int $division): array {
        $row=one($this->db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',[$division,self::DIVISION_FUND_SOURCES_KEY]);
        if(!$row)return ['version_id'=>0,'sources'=>[]];
        $config=json_decode((string)$row['config_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($config) && isset($config['sources']) && is_array($config['sources']),'Invalid Division source catalog',500);
        return ['version_id'=>(int)$row['id'],'sources'=>$config['sources']];
    }
    private function effectiveFundPolicy(int $division,string $code,string $schoolClass,string $asOf): array {
        $key=self::fundPolicyKey($code,$schoolClass);
        $row=one($this->db,'SELECT id,config_json,effective_from FROM configuration_versions WHERE organization_id=? AND config_key=? AND effective_from<=? ORDER BY effective_from DESC,id DESC LIMIT 1',[$division,$key,$asOf]);
        if(!$row)return ['policy'=>'NOT_CONFIGURED','version_id'=>0,'effective_from'=>null,'authority'=>'','payment_credit_uacs'=>'','withholding_liability_uacs'=>''];
        $config=json_decode((string)$row['config_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($config) && ($config['source_code']??null)===$code && ($config['school_class']??null)===$schoolClass &&
            in_array(($config['policy']??''),self::FUND_WITHHOLDING_MODES,true) &&
            !(($config['policy']??'')==='IUS_REMIT_DIRECT_BIR' && $schoolClass!=='IUS'),'Invalid Division fund policy',500);
        return ['policy'=>$config['policy'],'version_id'=>(int)$row['id'],'effective_from'=>$row['effective_from'],'authority'=>$config['authority']??'',
            'payment_credit_uacs'=>(string)($config['payment_credit_uacs']??''),
            'withholding_liability_uacs'=>(string)($config['withholding_liability_uacs']??'')];
    }
    private function scopedDivision(int $org): int {
        $division=$this->divisionId($org);
        return $division;
    }
    /** Division-wide read view; school may only see its own Division. */
    public function fundPolicySettings(array $d): array {
        $org=$this->org($d);$division=$this->scopedDivision($org);
        $catalog=$this->divisionFundSourceList($division);
        $present=q($this->db,"SELECT DISTINCT f.source_code,f.name FROM funds f JOIN organizations o ON o.id=f.organization_id WHERE o.parent_division_id=? OR o.id=? ORDER BY f.source_code",[$division,$division])->fetchAll();
        $sources=$catalog['sources'];$codes=array_column($sources,'code');
        foreach($present as $f)if(!in_array($f['source_code'],$codes,true)){
            $sources[]=['code'=>$f['source_code'],'name'=>$f['name'],'active'=>false,'legacy_unreviewed'=>true];$codes[]=$f['source_code'];
        }
        usort($sources,static fn($a,$b)=>strcmp($a['code'],$b['code']));
        $today=self::todayManila();$policies=[];
        // One scoped bulk read instead of issuing two queries per source.
        // This avoids overwhelming shared hosting when a Division has many funds.
        $keys=[];foreach($sources as $s)foreach(['NON_IUS','IUS'] as $type)
            $keys[]=self::fundPolicyKey($s['code'],$type);
        $current=[];
        if($keys){
            $in=implode(',',array_fill(0,count($keys),'?'));
            $rows=q($this->db,'SELECT id,config_key,config_json,effective_from FROM configuration_versions WHERE organization_id=? AND config_key IN ('.$in.') AND effective_from<=? ORDER BY effective_from DESC,id DESC',
                array_merge([$division],$keys,[$today]))->fetchAll();
            foreach($rows as $row){
                $key=(string)$row['config_key'];
                if(!array_key_exists($key,$current))$current[$key]=$row;
            }
        }
        foreach($sources as $s)foreach(['NON_IUS','IUS'] as $type){
            $key=self::fundPolicyKey($s['code'],$type);
            $row=$current[$key]??null;
            $policy=['policy'=>'NOT_CONFIGURED','version_id'=>0,'effective_from'=>null,'authority'=>'','payment_credit_uacs'=>'','withholding_liability_uacs'=>''];
            if($row){
                $saved=json_decode((string)$row['config_json'],true,512,JSON_THROW_ON_ERROR);
                demand(is_array($saved)&&($saved['source_code']??null)===$s['code']&&($saved['school_class']??null)===$type&&
                    in_array(($saved['policy']??''),self::FUND_WITHHOLDING_MODES,true) &&
                    !(($saved['policy']??'')==='IUS_REMIT_DIRECT_BIR' && $type!=='IUS'),
                    'Invalid Division fund policy configuration',500);
                $policy=['policy'=>$saved['policy'],'version_id'=>(int)$row['id'],
                    'effective_from'=>$row['effective_from'],'authority'=>$saved['authority']??'',
                    'payment_credit_uacs'=>(string)($saved['payment_credit_uacs']??''),
                    'withholding_liability_uacs'=>(string)($saved['withholding_liability_uacs']??'')];
            }
            $policies[]=['source_code'=>$s['code'],'school_class'=>$type]+$policy;
        }
        $school=one($this->db,'SELECT id,kind FROM organizations WHERE id=?',[$org]);
        $classification=$school['kind']==='SCHOOL'?$this->currentSchoolClass($org,$today):null;
        // School personnel view only their own effective classification's
        // policies; Division admins can compare and maintain both groups.
        if($this->user['role']==='SCHOOL')$policies=array_values(array_filter($policies,
            static fn($item)=>$item['school_class']===($classification['classification']??'')));
        // Source master, receiving fund and bank credit are linked but intentionally
        // distinct. Display confirmed Division fund credits without posting new ones.
        $linked=q($this->db,"SELECT f.source_code,f.fiscal_year,f.id AS fund_id,
                COUNT(*) AS credit_count,SUM(e.amount) AS amount
            FROM funds f JOIN fund_events e ON e.fund_id=f.id AND e.organization_id=f.organization_id
            WHERE f.organization_id=? AND e.event_type='ALLOTMENT'
              AND e.reference_type='DIVISION_RELEASE' AND e.bank_account_id IS NOT NULL
              AND e.bank_credit_date<=?
            GROUP BY f.source_code,f.fiscal_year,f.id",[$org,$today])->fetchAll();
        $checkPayees=[];foreach($sources as $source)$checkPayees[$source['code']]=$this->taxCheckPayeeConfig($division,$source['code']);
        return ['division_id'=>$division,'catalog_version'=>$catalog['version_id'],'sources'=>$sources,'policies'=>$policies,'check_payees'=>$checkPayees,
            'linked_division_credits'=>$linked,
            'school_classification'=>$classification,'division_only'=>in_array($this->user['role'],['DIVISION','SUPER_ADMIN'],true)];
    }
    /** Create/change Division-approved master code; deactivate, never delete. */
    public function saveDivisionFundSource(array $d): array {
        $org=$this->org($d);$division=$this->scopedDivision($org);
        demand(in_array($this->user['role'],['DIVISION','SUPER_ADMIN'],true),'Division finance administrator required',403);
        $code=strtoupper(requiredString($d,'code',60));
        demand((bool)preg_match('/^[A-Z0-9_-]{1,60}$/D',$code),'Invalid fund source code');
        $name=requiredString($d,'name',160);$active=$d['active']??null;
        demand(is_bool($active),'Choose active or inactive');$reason=requiredString($d,'reason',500);
        $version=$d['catalog_version']??null;demand(is_int($version)&&$version>=0,'Refresh the Division fund source catalog before saving',409);
        return $this->tx(function()use($division,$code,$name,$active,$reason,$version){
            $this->divisionForActor($division);
            $catalog=$this->divisionFundSourceList($division);
            demand($catalog['version_id']===$version,'Fund directory was changed by another administrator; refresh and retry',409);
            $rows=$catalog['sources'];$found=false;
            foreach($rows as &$row)if($row['code']===$code){$row=['code'=>$code,'name'=>$name,'active'=>$active];$found=true;break;}
            unset($row);
            if(!$found)$rows[]=['code'=>$code,'name'=>$name,'active'=>$active];
            demand(count($rows)<=200,'Division fund source catalog exceeds supported size');
            $id=$this->insert('configuration_versions',['organization_id'=>$division,'config_key'=>self::DIVISION_FUND_SOURCES_KEY,
                'config_json'=>json_encode(['sources'=>$rows],JSON_THROW_ON_ERROR),'effective_from'=>self::todayManila(),
                'review_note'=>$reason]);
            $this->audit($division,'FUND_SOURCE_CONFIGURED','FUND',$id,['code'=>$code,'active'=>$active,'reason'=>$reason,'version_id'=>$id]);
            return ['code'=>$code,'active'=>$active,'catalog_version'=>$id];
        });
    }
    /** Statutory STANDARD output VAT rate used only to derive a VAT-inclusive
     * invoice tax base. It is NOT an ATC/withholding rate. Historical revisions
     * remain in configuration_versions, scoped to the originating Division. */
    private function standardVatRate(int $division,string $asOf): array {
        $row=one($this->db,'SELECT id,config_json,effective_from FROM configuration_versions WHERE organization_id=? AND config_key=? AND effective_from<=? ORDER BY effective_from DESC,id DESC LIMIT 1',
            [$division,self::DIVISION_STANDARD_VAT_KEY,$asOf]);
        if(!$row)return ['rate_percent'=>'12.00','version_id'=>0,'effective_from'=>null,'authority'=>'System default: 12% standard VAT; verify invoice treatment','system_default'=>true];
        $saved=json_decode((string)$row['config_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($saved)&&is_string($saved['rate_percent']??null)&&preg_match('/^(?:\d{1,2})\.\d{2}$/D',$saved['rate_percent'])===1,
            'Invalid Division VAT rate configuration',500);
        return ['rate_percent'=>$saved['rate_percent'],'version_id'=>(int)$row['id'],
            'effective_from'=>$row['effective_from'],'authority'=>(string)($saved['authority']??''),'system_default'=>false];
    }
    /** Division master VAT settings are readable by its schools; only a
     * Division/Super Administrator can create the next audited revision. */
    public function divisionVatSettings(array $d): array {
        $org=$this->org($d);$division=$this->divisionId($org);
        $latest=one($this->db,'SELECT id FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',
            [$division,self::DIVISION_STANDARD_VAT_KEY]);
        $history=q($this->db,'SELECT id,config_json,effective_from,review_note FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 50',
            [$division,self::DIVISION_STANDARD_VAT_KEY])->fetchAll();
        $rows=[];
        foreach($history as $row){$value=json_decode((string)$row['config_json'],true,512,JSON_THROW_ON_ERROR);
            $rows[]=['version_id'=>(int)$row['id'],'rate_percent'=>$value['rate_percent'],
                'effective_from'=>$row['effective_from'],'authority'=>$value['authority']??'',
                'reason'=>$row['review_note']??''];}
        return ['division_id'=>$division,'latest_version'=>(int)($latest['id']??0),
            'current'=>$this->standardVatRate($division,self::todayManila()),'history'=>$rows,
            'can_edit'=>in_array($this->user['role'],['SUPER_ADMIN','DIVISION'],true)];
    }
    public function saveDivisionVatRate(array $d): array {
        $org=$this->org($d);$division=$this->divisionId($org);
        demand(in_array($this->user['role'],['DIVISION','SUPER_ADMIN'],true),'Division finance administrator required',403);
        $percent=requiredString($d,'rate_percent',6);
        demand(preg_match('/^\d{1,2}(?:\.\d{1,2})?$/D',$percent)===1,
            'Enter a valid standard VAT percentage');
        $pieces=explode('.',$percent,2);
        $rateHundredths=(int)$pieces[0]*100+(int)str_pad($pieces[1]??'',2,'0');
        demand($rateHundredths>0 && $rateHundredths<=2500,
            'Enter an approved standard VAT rate greater than 0.00% and not above 25.00%');
        $percent=sprintf('%d.%02d',intdiv($rateHundredths,100),$rateHundredths%100);
        $date=self::policyDate($d['effective_from']??null);
        $authority=requiredString($d,'authority',255);$reason=requiredString($d,'reason',500);
        $expected=$d['expected_version']??null;
        demand(is_int($expected)&&$expected>=0,'Refresh VAT configuration before saving',409);
        return $this->tx(function()use($division,$percent,$date,$authority,$reason,$expected){
            $this->divisionForActor($division); // Locks the same Division row for competing writers.
            $latest=one($this->db,'SELECT id FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',
                [$division,self::DIVISION_STANDARD_VAT_KEY]);
            demand((int)($latest['id']??0)===$expected,'VAT settings changed in another session. Refresh and review again.',409);
            $id=$this->insert('configuration_versions',['organization_id'=>$division,'config_key'=>self::DIVISION_STANDARD_VAT_KEY,
                'config_json'=>json_encode(['rate_percent'=>$percent,'authority'=>$authority],JSON_THROW_ON_ERROR),
                'effective_from'=>$date,'review_note'=>$reason]);
            $this->audit($division,'STANDARD_VAT_RATE_CONFIGURED','VAT_RATE',$id,['rate_percent'=>$percent,'effective_from'=>$date,
                'authority'=>$authority,'reason'=>$reason,'previous_version'=>$expected]);
            return ['version_id'=>$id,'rate_percent'=>$percent,'effective_from'=>$date];
        });
    }
    /** One immutable row per effective-dated fund + school-class policy change. */
    public function saveFundWithholdingPolicy(array $d): array {
        $org=$this->org($d);$division=$this->scopedDivision($org);
        demand(in_array($this->user['role'],['DIVISION','SUPER_ADMIN'],true),'Division finance administrator required',403);
        $code=strtoupper(requiredString($d,'source_code',60));$type=requiredString($d,'school_class',12);
        demand(in_array($type,['IUS','NON_IUS'],true),'Choose IUS or Non-IUS policy');
        $policy=requiredString($d,'policy',40);
        demand(in_array($policy,self::FUND_WITHHOLDING_MODES,true),'Invalid withholding policy');
        demand($policy!=='IUS_REMIT_DIRECT_BIR'||$type==='IUS',
            'Direct BIR remittance is available only for IUS school policies.',409);
        $effective=self::policyDate($d['effective_from']??null);
        $authority=requiredString($d,'authority',255);$reason=requiredString($d,'reason',500);
        $paymentCredit=trim((string)($d['payment_credit_uacs']??''));
        $taxCredit=trim((string)($d['withholding_liability_uacs']??''));
        // MOOE's fixed net-operating-advance and Due-to-BIR workpaper is
        // unchanged. Non-MOOE sources MUST have two independently approved
        // credit accounts; merely copying the MOOE numbers is prohibited.
        $configured=$type==='NON_IUS' && $code!=='MOOE' && $policy!=='NOT_CONFIGURED';
        if($configured){
            demand((bool)preg_match('/^[12][0-9]{9}$/D',$paymentCredit),
                'Division Accountant must select the approved net-payment credit UACS for this fund.',422);
            demand((bool)preg_match('/^2[0-9]{9}$/D',$taxCredit),
                'Division Accountant must select an approved withholding-liability credit UACS for this fund.',422);
            demand($taxCredit!==$paymentCredit,
                'Net-payment and withheld-tax liability must use distinct UACS.',422);
        }else{$paymentCredit='';$taxCredit='';}
        return $this->tx(function()use($division,$code,$type,$policy,$effective,$authority,$reason,$paymentCredit,$taxCredit,$configured){
            $this->divisionForActor($division);
            $catalog=$this->divisionFundSourceList($division);
            $found=false;foreach($catalog['sources'] as $s)if(($s['code']??null)===$code&&($s['active']??false)===true)$found=true;
            demand($found,'Division fund source must be active in the master catalog before assigning policy',409);
            if($configured){
                foreach([$paymentCredit,$taxCredit] as $account)
                    demand(one($this->db,'SELECT code FROM uacs_accounts WHERE code=? AND active=1',[$account])!==null,
                        'Payment or withholding-liability UACS is not active. Check the Division reference directory.',409);
            }
            $key=self::fundPolicyKey($code,$type);
            // Backdated and same-day entries can be recorded, but finalized DVs
            // keep their original disposition and policy version snapshot.
            $id=$this->insert('configuration_versions',['organization_id'=>$division,'config_key'=>$key,
                'config_json'=>json_encode(['source_code'=>$code,'school_class'=>$type,'policy'=>$policy,'authority'=>$authority,
                    'payment_credit_uacs'=>$paymentCredit,'withholding_liability_uacs'=>$taxCredit],JSON_THROW_ON_ERROR),
                'effective_from'=>$effective,'review_note'=>$reason]);
            $this->audit($division,'FUND_WITHHOLDING_POLICY_CONFIGURED','FUND',$id,['source_code'=>$code,'school_class'=>$type,'policy'=>$policy,'effective_from'=>$effective,'authority'=>$authority,'reason'=>$reason,
                    'payment_credit_uacs'=>$paymentCredit,'withholding_liability_uacs'=>$taxCredit]);
            return ['version_id'=>$id,'source_code'=>$code,'school_class'=>$type,'policy'=>$policy,'effective_from'=>$effective,
                'payment_credit_uacs'=>$paymentCredit,'withholding_liability_uacs'=>$taxCredit];
        });
    }
    /** Existing schools remain unclassified until the Division verifies them. */
    public function saveSchoolImplementationClass(array $d): array {
        $schoolId=idOf($d,'school_id');$type=requiredString($d,'classification',12);
        demand(in_array($type,['IUS','NON_IUS'],true),'Select IUS or Non-IUS');
        $effective=self::policyDate($d['effective_from']??null);$reason=requiredString($d,'reason',500);
        demand(in_array($this->user['role'],['DIVISION','SUPER_ADMIN'],true),'Division administrator required',403);
        $coordinator=$this->reportMetadataCoordinator($d,$schoolId);
        return $this->financialTx('organization.school.classification',$coordinator,function()use($schoolId,$type,$effective,$reason){
            $school=one($this->db,"SELECT id,parent_division_id FROM organizations WHERE id=? AND kind='SCHOOL' AND active=1 FOR UPDATE",[$schoolId]);
            demand($school!==null&&(int)$school['parent_division_id']>0,'Active Division-linked school required',404);
            $division=(int)$school['parent_division_id'];$this->divisionForActor($division);
            $id=$this->insert('configuration_versions',['organization_id'=>$schoolId,'config_key'=>self::SCHOOL_IMPLEMENTATION_KEY,
                'config_json'=>json_encode(['classification'=>$type],JSON_THROW_ON_ERROR),'effective_from'=>$effective,'review_note'=>$reason]);
            $this->audit($division,'IMPLEMENTATION_CLASS_CONFIGURED','SCHOOL',$schoolId,['classification'=>$type,'effective_from'=>$effective,'version_id'=>$id,'reason'=>$reason]);
            return ['school_id'=>$schoolId,'classification'=>$type,'effective_from'=>$effective,'version_id'=>$id];
        });
    }
    private const DIVISION_CLASS_KEY='DIVISION_ITEM_CLASSIFICATIONS';
    private const PROPERTY_VALUATION_KEY='DIVISION_PROPERTY_VALUATION';
    private const DIVISION_CLASS_DEFAULTS=[
        ['code'=>'ICT_EQUIPMENT','name'=>'ICT Equipment'],
        ['code'=>'ICT_EQUIPMENT_DCP','name'=>'ICT Equipment (DCP)'],
        ['code'=>'ICT_EQUIPMENT_ADM','name'=>'ICT Equipment (ADM)'],
        ['code'=>'FURNITURE_FIXTURES','name'=>'Furniture and Fixtures'],
        ['code'=>'MEDICAL_DENTAL_EQUIPMENT','name'=>'Medical Dental Equipment'],
        ['code'=>'OFFICE_EQUIPMENT','name'=>'Office Equipment'],
        ['code'=>'OTHER_MACHINERIES_EQUIPMENT','name'=>'Other Machineries and Equipment'],
        ['code'=>'OTHER_SUPPLIES_MATERIALS','name'=>'Other Supplies & Materials'],
        ['code'=>'TLE_TVL_EQUIPMENT','name'=>'TLE/TVL Equipment'],
        ['code'=>'SPORTS_EQUIPMENT','name'=>'Sports Equipment']
    ];
    private function divisionId(int $org): int {
        $o=one($this->db,'SELECT id,kind,parent_division_id FROM organizations WHERE id=?',[$org]);
        demand($o!==null,'Workspace organization not found',404);
        $id=$o['kind']==='DIVISION'?(int)$o['id']:(int)($o['parent_division_id']??0);
        demand($id>0,'School must be linked to a Division to use Division classifications',409);
        return $id;
    }
    private function divisionClasses(int $division): array {
        $record=one($this->db,'SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',[$division,self::DIVISION_CLASS_KEY]);
        if($record!==null){
            $arr=json_decode((string)$record['config_json'],true,512,JSON_THROW_ON_ERROR);
            demand(is_array($arr)&&isset($arr['categories'])&&is_array($arr['categories']),'Invalid Division classification configuration',500);
            return array_map(static function(array $c): array {
                $c['applies_to']=$c['applies_to']??'SEMI_EXPENDABLE';
                // Other MOOE expense references are opt-in per procurement item type.
                // Legacy records must never become eligible merely by upgrading.
                $types=$c['procurement_item_types']??[];
                $c['procurement_item_types']=($c['applies_to']==='OTHER_MOOE' && is_array($types))
                    ?array_values(array_intersect(['SUPPLY','SERVICE'],$types)):[];
                return $c;
            },$arr['categories']);
        }
        return array_map(static fn(array $c)=>$c+['active'=>true,'default_uacs_code'=>null,'applies_to'=>'SEMI_EXPENDABLE'],self::DIVISION_CLASS_DEFAULTS);
    }
    /** Per-Division property settings: dated threshold + individually reviewed useful life overrides. */
    private function propertyValuationConfig(int $division): array {
        $row=one($this->db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',[$division,self::PROPERTY_VALUATION_KEY]);
        if ($row===null) return ['version_id'=>0,'thresholds'=>[['effective_from'=>'1900-01-01','cents'=>500000]],'useful_life_overrides'=>[],'registry_overrides'=>[],'registry_added'=>[]];
        $config=json_decode((string)$row['config_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($config)&&isset($config['thresholds'])&&is_array($config['thresholds'])&&isset($config['useful_life_overrides'])&&is_array($config['useful_life_overrides']), 'Invalid property valuation configuration',500);
        $config['registry_overrides']=$config['registry_overrides']??[];
        $config['registry_added']=$config['registry_added']??[];
        demand(is_array($config['registry_overrides'])&&is_array($config['registry_added']),'Invalid registry revisions',500);
        $config['version_id']=(int)$row['id'];
        return $config;
    }
    private static function propertyEffectiveThreshold(array $rules,string $asOf): int {
        $best=500000;$last='';
        foreach($rules as $r)if(($r['effective_from']??'')<=$asOf && ($r['effective_from']??'')>=$last){
            $best=(int)$r['cents'];$last=$r['effective_from'];
        }
        return $best;
    }
    /** Merge immutable imported Division records with Division-approved revisions. */
    private function effectivePropertyRegistryRows(array $config): array {
        $rows=[];
        foreach(DivisionPropertyRegistry::rows() as $original){
            $id=$original['id'];
            $patch=$config['registry_overrides'][$id]??[];
            if(is_array($patch))foreach(['section','description','mode','hv_code','lv_code','reference_code','detail'] as $field){
                if(array_key_exists($field,$patch))$original[$field]=$patch[$field];
            }
            $rows[$id]=$original;
        }
        foreach($config['registry_added'] as $row){
            if(is_array($row)&&isset($row['id'])&&preg_match('/^D[0-9]{8}$/',(string)$row['id'])){
                $rows[$row['id']]=$row;
            }
        }
        foreach($rows as $id=>&$row){
            $row['effective_useful_life_months']=array_key_exists($id,$config['useful_life_overrides'])
                ?$config['useful_life_overrides'][$id]:$row['useful_life_months'];
            $row['useful_life_overridden']=array_key_exists($id,$config['useful_life_overrides']);
            $row['division_revised']=isset($config['registry_overrides'][$id])||str_starts_with($id,'D');
        }
        unset($row);
        return array_values($rows);
    }
    /** Division's master type code is not an accountable property's unique number. */
    public function listDivisionPropertyRegistry(array $p): array {
        $org=$this->org($p);DivisionSchoolAccess::requireGet($this->db,$this->user,'division.property_registry',$p);
        $division=$this->divisionId($org);
        $config=$this->propertyValuationConfig($division);
        $asOf=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $threshold=self::propertyEffectiveThreshold($config['thresholds'],$asOf);
        $records=$this->effectivePropertyRegistryRows($config);
        return ['division_id'=>$division,'version_id'=>$config['version_id'],'threshold'=>moneyString($threshold),
            'threshold_rules'=>array_values($config['thresholds']),
            'records'=>$records,'count'=>count($records),
            'can_edit'=>in_array($this->user['role'],['SUPER_ADMIN','DIVISION'],true),
            'note'=>'Division-controlled registry, read-only for schools. Codes may be pending. No automatic asset-tag assignment, accounting or historical record rewrites.'];
    }
    /** Add a Division entry or revise an existing entry without changing the imported workbook source. */
    public function savePropertyRegistryEntry(array $d): array {
        $org=$this->org($d);$this->referenceWriter();$division=$this->divisionId($org);
        if($this->user['role']==='DIVISION')scope($this->db,$this->user,$division);
        $entryId=trim((string)($d['registry_id']??''));
        demand($entryId===''||preg_match('/^(S[0-9]{2}R[0-9]{5}|D[0-9]{8})$/',$entryId)===1,'Invalid entry ID');
        $mode=requiredString($d,'mode',20);
        demand(in_array($mode,['HV_LV','REFERENCE'],true),'Select HV/LV or Reference code format');
        $section=requiredString($d,'section',160);
        $description=requiredString($d,'description',240);
        $detail=trim((string)($d['detail']??''));
        demand(mbSafeLen($detail)<=300 && !preg_match('/[\x00-\x1F\x7F]/',$detail),'Invalid detail');
        $codeValues=[];
        foreach(['hv_code','lv_code','reference_code'] as $field){
            $value=trim((string)($d[$field]??''));
            demand(mbSafeLen($value)<=100 && !preg_match('/[\x00-\x1F\x7F]/',$value),'Invalid Division code');
            $codeValues[$field]=$value;
        }
        demand($mode==='HV_LV'?$codeValues['reference_code']==='':($codeValues['hv_code']===''&&$codeValues['lv_code']===''),
            'Codes must match the chosen registry format');
        $months=$d['useful_life_months']??null;
        demand($months===null||(is_int($months)&&$months>=1&&$months<=1200), 'Useful life must be blank or 1–1200 whole months');
        $reason=requiredString($d,'reason',400);
        $expected=(int)($d['expected_version']??-1);
        return $this->tx(function()use($org,$division,$entryId,$mode,$section,$description,$detail,$codeValues,$months,$reason,$expected){
            q($this->db,"SELECT id FROM organizations WHERE id=? AND kind='DIVISION' FOR UPDATE",[$division])->fetch();
            $config=$this->propertyValuationConfig($division);
            demand($config['version_id']===$expected,'Registry changed in another session. Refresh and try again',409);
            $existing=$this->effectivePropertyRegistryRows($config);
            $old=null;$largest=0;
            foreach($existing as $row){
                if($row['id']===$entryId)$old=$row;
                if(preg_match('/^D([0-9]{8})$/',$row['id'],$m))$largest=max($largest,(int)$m[1]);
            }
            demand($entryId===''||$old!==null,'Registry item not found. Refresh',404);
            $id=$entryId!==''?$entryId:'D'.str_pad((string)($largest+1),8,'0',STR_PAD_LEFT);
            demand($largest<99999999,'Division registry ID range exhausted');
            // A valid workbook can contain duplicate legacy codes; only NEWLY changed codes are checked.
            if($mode==='HV_LV' && $codeValues['hv_code']!=='' && strtoupper($codeValues['hv_code'])===strtoupper($codeValues['lv_code']))
                demand(false,'HV and LV codes cannot be the same');
            foreach($existing as $row){
                if($row['id']===$id)continue;
                foreach($codeValues as $field=>$value)if($value!=='' && ($old===null || strtoupper($value)!==strtoupper((string)$old[$field]))){
                    // Do not introduce duplicates; preserve historical duplicates from the original source.
                    demand(!in_array(strtoupper($value),array_map('strtoupper',[(string)$row['hv_code'],(string)$row['lv_code'],(string)$row['reference_code']]),true),
                        'This Division code is already registered to another entry');
                }
            }
            $editable=['section'=>$section,'description'=>$description,'mode'=>$mode,
                'hv_code'=>$codeValues['hv_code'],'lv_code'=>$codeValues['lv_code'],'reference_code'=>$codeValues['reference_code'],'detail'=>$detail];
            $before=$old===null?null:array_intersect_key($old,$editable+['effective_useful_life_months'=>null]);
            if($entryId===''){
                $config['registry_added'][]=['id'=>$id]+$editable+['useful_life_months'=>$months];
            }elseif(str_starts_with($entryId,'D')){
                foreach($config['registry_added'] as &$added){
                    if($added['id']===$entryId){$added=array_merge($added,$editable);break;}
                }
                unset($added);
            }else{
                $original=DivisionPropertyRegistry::find($entryId);
                $diff=[];
                foreach($editable as $key=>$value)if($value!==$original[$key])$diff[$key]=$value;
                if($diff)$config['registry_overrides'][$entryId]=$diff;
                else unset($config['registry_overrides'][$entryId]);
            }
            // Useful life changes remain under the existing unified revision mechanism.
            if($entryId===''||$months!==$old['effective_useful_life_months']){
                $config['useful_life_overrides'][$id]=$months;
            }
            $revision=$this->savePropertyValuationSnapshot($org,$division,$config,
                'Division registry '.($old?'edited: ':'added: ').$reason,$old?'PROPERTY_REGISTRY_EDIT':'PROPERTY_REGISTRY_ADD',
                ['registry_id'=>$id,'before'=>$before,'after'=>$editable+['useful_life_months'=>$months], 'reason'=>$reason]);
            return ['registry_id'=>$id,'version_id'=>$revision,'created'=>$old===null];
        });
    }
    private function savePropertyValuationSnapshot(int $org,int $division,array $config,string $note,string $event,array $details): int {
        $id=$this->insert('configuration_versions',[
            'organization_id'=>$division,'config_key'=>self::PROPERTY_VALUATION_KEY,
            'config_json'=>json_encode(['thresholds'=>$config['thresholds'],'useful_life_overrides'=>$config['useful_life_overrides'],
                'registry_overrides'=>$config['registry_overrides'],'registry_added'=>$config['registry_added']],JSON_THROW_ON_ERROR),
            'effective_from'=>date('Y-m-d'),'review_note'=>$note]);
        $this->audit($org,'UPDATED',$event,$id,['division_id'=>$division]+$details);
        return $id;
    }
    public function savePropertyThreshold(array $d): array {
        $org=$this->org($d);$this->referenceWriter();$division=$this->divisionId($org);
        if($this->user['role']==='DIVISION')scope($this->db,$this->user,$division);
        $cents=moneyCents($d['threshold']??'');
        demand($cents>=1 && $cents<=1000000000,'HV threshold must be between 0.01 and 10,000,000.00');
        $effective=requiredString($d,'effective_from',10);
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$effective);
        demand($date!==false&&$date->format('Y-m-d')===$effective,'Invalid effective date');
        $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        demand($effective>=$today,'New valuation thresholds must take effect today or in the future');
        $reason=requiredString($d,'reason',400);
        $version=(int)($d['expected_version']??-1);
        return $this->tx(function()use($org,$division,$cents,$effective,$reason,$version){
            q($this->db,"SELECT id FROM organizations WHERE id=? AND kind='DIVISION' FOR UPDATE",[$division])->fetch();
            $config=$this->propertyValuationConfig($division);
            demand($config['version_id']===$version,'Property settings changed in another session. Refresh and try again',409);
            foreach($config['thresholds'] as $rule)demand($rule['effective_from']!==$effective,'A threshold rule already exists for this effective date; choose a later date');
            $config['thresholds'][]=['effective_from'=>$effective,'cents'=>$cents];
            usort($config['thresholds'],static fn($a,$b)=>strcmp($a['effective_from'],$b['effective_from']));
            $id=$this->savePropertyValuationSnapshot($org,$division,$config,'Division property threshold updated: '.$reason,'PROPERTY_VALUATION',
                ['effective_from'=>$effective,'threshold'=>moneyString($cents),'reason'=>$reason]);
            return ['version_id'=>$id,'threshold'=>moneyString($cents),'effective_from'=>$effective];
        });
    }
    public function savePropertyUsefulLife(array $d): array {
        $org=$this->org($d);$this->referenceWriter();$division=$this->divisionId($org);
        if($this->user['role']==='DIVISION')scope($this->db,$this->user,$division);
        $entry=requiredString($d,'registry_id',30);
        // Edited and Division-added entries are both eligible for useful-life maintenance.
        demand(preg_match('/^(S[0-9]{2}R[0-9]{5}|D[0-9]{8})$/',$entry)===1,'Invalid registry entry');
        $raw=$d['useful_life_months']??null;
        demand($raw===null || (is_int($raw) && $raw>=1 && $raw<=1200),'Useful life must be blank or 1–1200 months');
        $reason=requiredString($d,'reason',400);
        $version=(int)($d['expected_version']??-1);
        return $this->tx(function()use($org,$division,$entry,$raw,$reason,$version){
            q($this->db,"SELECT id FROM organizations WHERE id=? AND kind='DIVISION' FOR UPDATE",[$division])->fetch();
            $config=$this->propertyValuationConfig($division);
            demand($config['version_id']===$version,'Property settings changed in another session. Refresh and try again',409);
            $original=null;
            foreach($this->effectivePropertyRegistryRows($config) as $row)if($row['id']===$entry){$original=$row;break;}
            demand($original!==null,'Unknown Division registry entry',404);
            $prior=$original['effective_useful_life_months'];
            $config['useful_life_overrides'][$entry]=$raw;
            $id=$this->savePropertyValuationSnapshot($org,$division,$config,'Division useful life revised: '.$reason,'PROPERTY_USEFUL_LIFE',
                ['registry_id'=>$entry,'before_months'=>$prior,'after_months'=>$raw,'reason'=>$reason]);
            return ['version_id'=>$id,'registry_id'=>$entry,'useful_life_months'=>$raw];
        });
    }
    public function listDivisionClassifications(array $p): array {
        $org=$this->org($p);$div=$this->divisionId($org);
        $o=one($this->db,"SELECT id,name FROM organizations WHERE id=? AND kind='DIVISION'",[$div]);
        return ['division_id'=>$div,'division_name'=>$o['name']??'Division','categories'=>$this->divisionClasses($div)];
    }
    /** Category-specific eligibility is a conservative screen, not COA approval. */
    private static function divisionClassUacsAllowed(string $category,string $account): bool {
        if (!preg_match('/^[0-9]{10}$/D',$account)) return false;
        $prefixes=[
            'SUPPLY'=>['502'], // School-use, direct-expensed consumables only.
            'SEMI_EXPENDABLE'=>['502','104','106','108'], // Existing policy preserved.
            'PPE'=>['106','108'],
            'TEXTBOOK'=>['104','502'],
            'SERVICE'=>['502'],
            // Non-inventory expense reference. This is NEVER an item catalog category.
            'OTHER_MOOE'=>['502']
        ];
        return in_array(substr($account,0,3),$prefixes[$category]??[],true);
    }
    /** Change only one classification per save; preserve a complete, auditable revision. */
    public function saveDivisionClassification(array $d): array {
        $org=$this->org($d);$this->referenceWriter();$div=$this->divisionId($org);
        $code=strtoupper(requiredString($d,'code',64));
        demand((bool)preg_match('/^[A-Z][A-Z0-9_]{2,63}$/',$code),'Classification code must be 3–64 uppercase letters, digits or underscores');
        $name=requiredString($d,'name',120);
        $active=$d['active']??null;demand(is_bool($active),'Active must be true or false');
        $appliesTo=requiredString($d,'applies_to',32);
        demand(in_array($appliesTo,['SEMI_EXPENDABLE','SUPPLY','PPE','TEXTBOOK','SERVICE','OTHER_MOOE'],true),'Choose a supported Division classification type');
        $procurementItemTypes=$d['procurement_item_types']??[];
        demand(is_array($procurementItemTypes),'Select procurement item types');
        demand(count($procurementItemTypes)<=2 && count($procurementItemTypes)===count(array_unique($procurementItemTypes))
            && count(array_diff($procurementItemTypes,['SUPPLY','SERVICE']))===0,
            'Only direct-expensed goods and services can be authorized for Other MOOE procurement');
        demand($appliesTo==='OTHER_MOOE'||count($procurementItemTypes)===0,
            'Procurement item type overrides apply only to Other MOOE expense references');
        // Stable value ordering for version comparisons and audits.
        $procurementItemTypes=array_values(array_intersect(['SUPPLY','SERVICE'],$procurementItemTypes));
        $uacs=trim((string)($d['default_uacs_code']??''));
        demand(mbSafeLen($uacs)<=32,'UACS code is too long');
        if($uacs!==''){
            demand(self::divisionClassUacsAllowed($appliesTo,$uacs),
                'This Division class needs an eligible debit UACS for its catalog category. Refer the specific account to the Division Accountant.');
            $account=one($this->db,'SELECT code,active FROM uacs_accounts WHERE code=?',[$uacs]);
            demand($account!==null && (int)$account['active']===1,'Default UACS must be an enabled account');
        }
        return $this->tx(function()use($org,$div,$code,$name,$active,$uacs,$appliesTo,$procurementItemTypes){
            // Lock the Division row: multiple administrators cannot overwrite each other's edits.
            q($this->db,"SELECT id FROM organizations WHERE id=? AND kind='DIVISION' FOR UPDATE",[$div])->fetch();
            $categories=$this->divisionClasses($div);
            $found=false;$before=null;
            foreach($categories as &$row){
                if($row['code']===$code){$found=true;$before=$row;
                    demand(($row['applies_to']??'SEMI_EXPENDABLE')===$appliesTo,
                        'The classification type cannot change after creation; create a new code instead',409);
                    $row=['code'=>$code,'name'=>$name,'active'=>$active,'default_uacs_code'=>$uacs?:null,'applies_to'=>$appliesTo,
                        'procurement_item_types'=>$procurementItemTypes];break;}
            }
            unset($row);
            if(!$found){
                demand(count($categories)<75,'Maximum 75 Division classifications');
                $categories[]=['code'=>$code,'name'=>$name,'active'=>$active,'default_uacs_code'=>$uacs?:null,'applies_to'=>$appliesTo,
                    'procurement_item_types'=>$procurementItemTypes];
            }
            $after=['code'=>$code,'name'=>$name,'active'=>$active,'default_uacs_code'=>$uacs?:null,'applies_to'=>$appliesTo,
                'procurement_item_types'=>$procurementItemTypes];
            if($before!==$after){
                $this->insert('configuration_versions',[
                    'organization_id'=>$div,'config_key'=>self::DIVISION_CLASS_KEY,
                    'config_json'=>json_encode(['categories'=>$categories],JSON_THROW_ON_ERROR),
                    'effective_from'=>date('Y-m-d'),
                    'review_note'=>'Division classification edited by authorized administrator']);
                $this->audit($org,$found?'UPDATED':'CREATED','DIV_CLASS',0,
                    ['division_id'=>$div,'code'=>$code,'before'=>$before,'after'=>$after]);
            }
            return ['division_id'=>$div,'classification'=>$after,'changed'=>$before!==$after];
        });
    }
    private function requireDivisionSubcategory(int $org,string $code): void {
        $cats=$this->divisionClasses($this->divisionId($org));
        foreach($cats as $c)if(($c['code']??'')===$code && ($c['applies_to']??'SEMI_EXPENDABLE')==='SEMI_EXPENDABLE'){
            demand(!empty($c['active']),'This Division classification is disabled');return;
        }
        demand(false,'Choose an active Division classification');
    }
    public function __construct(private PDO $db, private array $user) {}
    private function org(array $d): int { $o=idOf($d,'organization_id'); scope($this->db,$this->user,$o); return $o; }
    /** Scope read access may include attached schools for Division monitoring.
     * Operational mutation authority is narrower than reporting scope.
     * Super Administrators must explicitly select the target workspace.
     */
    private static function operationalRoleMayWrite(string $role,string $kind): bool {
        return $role==='SUPER_ADMIN' || ($role==='SCHOOL' && $kind==='SCHOOL') ||
            ($role==='DIVISION' && $kind==='DIVISION');
    }
    private function operationalWriteAllowed(int $org,string $operation=''): void {
        allowedToWrite($this->user);
        $kind=one($this->db,'SELECT kind FROM organizations WHERE id=? AND active=1',[$org]);
        demand($kind!==null,'Selected workspace is unavailable',403);
        // Only RIS draft/issue and ICS/PAR issuance are Division school defaults.
        // All other mutation routes retain the narrower original ownership gate.
        demand(self::operationalRoleMayWrite((string)$this->user['role'],(string)$kind['kind']) ||
            DivisionSchoolAccess::canIssue((string)$this->user['role'],(string)$kind['kind'],$operation),
            'This school transaction requires specifically authorized operational access.',403);
    }
    private function tx(callable $work): mixed { $outer=$this->db->inTransaction(); if(!$outer)$this->db->beginTransaction(); try{$r=$work();if(!$outer)$this->db->commit();return $r;}catch(Throwable $e){if(!$outer&&$this->db->inTransaction())$this->db->rollBack();throw $e;} }
    /** Financial invariants belong to Domain as well as HTTP dispatch. Direct
     * callers get an owned READ COMMITTED transaction. Joining an arbitrary
     * caller's stale snapshot is refused; reviewed callers must coordinate it.
     */
    private function financialTx(string $route,array $input,callable $work):mixed {
        $outer=$this->db->inTransaction();
        if(!$outer)$this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        else demand(FinancialCutoff::isCoordinated($this->db),'Financial Domain calls require a coordinated READ COMMITTED transaction',409);
        return $this->tx(function()use($route,$input,$work,$outer){
            if(!$outer)FinancialCutoff::markCoordinated($this->db);
            try{FinancialCutoff::beginWriter($this->db,$this->user,$route,$input);$out=$work();(new FinancialAllocation($this->db,$this->user))->afterMutation($route,$input,$out);return $out;}
            finally{if(!$outer)FinancialCutoff::unmarkCoordinated($this->db);}
        });
    }
    /** Target-based profile/classification endpoints need a gate even when a
     * direct caller omits the HTTP workspace field. The linked Division's
     * configuration gate coordinates the report identities of its schools. */
    private function reportMetadataCoordinator(array $input,int $target):array {
        $row=one($this->db,'SELECT id,kind,parent_division_id FROM organizations WHERE id=?',[$target]);
        demand($row!==null,'Organization not found',404);
        $input['organization_id']=$row['kind']==='SCHOOL'&&(int)($row['parent_division_id']??0)>0?(int)$row['parent_division_id']:(int)$row['id'];
        return $input;
    }
    private function insert(string $table,array $data): int {
        demand((bool)preg_match('/^[a-z_]+$/',$table),'Invalid table');
        foreach(array_keys($data) as $field) demand((bool)preg_match('/^[a-z_]+$/',$field),'Invalid column');
        $cols=array_keys($data);q($this->db,'INSERT INTO '.$table.' ('.implode(',',$cols).') VALUES ('.implode(',',array_fill(0,count($cols),'?')).')',array_values($data));return (int)$this->db->lastInsertId();
    }
    private function audit(int $org,string $verb,string $type,int $id,array $details=[]): void {
        $this->insert('audit_events',['organization_id'=>$org,'actor_id'=>$this->user['id'],'event_type'=>$verb,'entity_type'=>$type,'entity_id'=>$id,'details'=>json_encode($details,JSON_THROW_ON_ERROR)]);
    }
    private function registered(int $org,string $type,int $id,?string $documentDate=null): string {
        $period=$documentDate!==null?substr(self::transactionDocumentDate($documentDate,'Document date'),0,7):date('Y-m');
        $number=AnnualDocumentNumbering::reserve($this->db,$org,$type,$period);
        $this->insert('document_registry',['organization_id'=>$org,'doc_type'=>$type,'period'=>$period,'display_number'=>$number,'entity_id'=>$id]);
        return $number;
    }
    private function snapshot(int $org,string $type,int $id,array $body,string $reason='Original finalization'): void {
        $count=one($this->db,'SELECT COALESCE(MAX(version_no),0) AS n FROM document_versions WHERE doc_type=? AND entity_id=?',[$type,$id]);
        $this->insert('document_versions',['organization_id'=>$org,'doc_type'=>$type,'entity_id'=>$id,'version_no'=>(int)$count['n']+1,'snapshot_json'=>json_encode($body,JSON_THROW_ON_ERROR),'reason'=>$reason,'actor_id'=>$this->user['id']]);
    }
    private function link(int $org,string $source,int $sid,string $target,int $tid): void {
        q($this->db,'INSERT IGNORE INTO document_links (organization_id,source_type,source_id,target_type,target_id) VALUES (?,?,?,?,?)',[$org,$source,$sid,$target,$tid]);
    }
    private function record(string $table,int $id,int $org,bool $lock=false): array {
        $allowed=['purchase_requests','rfqs','awards','purchase_orders','iars','disbursement_vouchers','requisition_issue_slips','funds','checks','bank_accounts','inventory_lots'];
        demand(in_array($table,$allowed,true),'Unsupported record');
        $r=one($this->db,"SELECT * FROM $table WHERE id=? AND organization_id=?".($lock?' FOR UPDATE':''),[$id,$org]);
        demand($r!==null,'Record not found in authorized organization',404);return $r;
    }
    private function ledger(int $org,int $fund,string $type,int $amount,string $ref,int $id,string $reason=''): void {
        $this->insert('fund_events',['organization_id'=>$org,'fund_id'=>$fund,'event_type'=>$type,'amount'=>moneyString($amount),'reference_type'=>$ref,'reference_id'=>$id,'reason'=>$reason,'created_by'=>$this->user['id']]);
    }
    /** Authorized allocation is separate from bank cash and UACS reporting.
     * A posted/finalized DV consumes its GROSS only once, even after check release.
     * Unsettled manual obligations also consume allocation; they must be explicitly
     * reversed by a finance administrator when they are converted to a DV.
     * RELEASE, cash in bank, pending/confirmed withheld tax and check payments
     * cannot increase authorized allocation.
     */
    private static function fundAvailableCents(int $allotment,int $adjustments,int $outstandingObligations,int $finalizedGross): int {
        demand($allotment>=0 && $adjustments>=0 && $outstandingObligations>=0 && $finalizedGross>=0,
            'Fund ledger contains inconsistent entries; finance administrator review required',409);
        return ($allotment+$adjustments)-($outstandingObligations+$finalizedGross);
    }
    private function fundAvailabilityTotals(int $org,int $fundId): array {
        $events=one($this->db,"SELECT
            COALESCE(SUM(CASE WHEN event_type='ALLOTMENT' AND bank_account_id IS NOT NULL AND bank_credit_date IS NOT NULL AND bank_credit_date<=? THEN amount ELSE 0 END),0) authorized,
            COALESCE(SUM(CASE WHEN event_type='ALLOTMENT' AND bank_account_id IS NULL THEN amount ELSE 0 END),0) unlinked,
            COALESCE(SUM(CASE WHEN event_type='ALLOTMENT' AND bank_account_id IS NOT NULL AND (bank_credit_date IS NULL OR bank_credit_date>?) THEN amount ELSE 0 END),0) not_yet_effective,
            COALESCE(SUM(CASE WHEN event_type='ADJUSTMENT' THEN amount ELSE 0 END),0) adjustments,
            COALESCE(SUM(CASE WHEN event_type='OBLIGATION' THEN amount ELSE 0 END),0) obligations,
            COALESCE(SUM(CASE WHEN event_type='OBLIGATION_REVERSAL' THEN amount ELSE 0 END),0) obligation_reversals
            FROM fund_events WHERE organization_id=? AND fund_id=?",[
            (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'),
            (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'),$org,$fundId]);
        $dv=one($this->db,"SELECT COALESCE(SUM(v.gross),0) committed_gross
            FROM disbursement_vouchers v LEFT JOIN school_tax_transfers tt ON tt.dv_id=v.id
            WHERE v.organization_id=? AND v.fund_id=? AND v.status='FINALIZED' AND tt.id IS NULL",[$org,$fundId]);
        $allot=moneyCents($events['authorized']);$adjust=moneyCents($events['adjustments']);
        $obligations=moneyCents($events['obligations'])-moneyCents($events['obligation_reversals']);
        $gross=moneyCents($dv['committed_gross']);
        $remaining=self::fundAvailableCents($allot,$adjust,$obligations,$gross);
        return ['fund_id'=>$fundId,'authorized_allocation'=>moneyString($allot+$adjust),
            'outstanding_obligations'=>moneyString($obligations),'finalized_dv_gross'=>moneyString($gross),
            'available_allocation'=>moneyString($remaining),'has_allocation'=>($allot+$adjust)>0,
            'awaiting_bank_link'=>moneyString(moneyCents($events['unlinked'])),
            'future_bank_credit'=>moneyString(moneyCents($events['not_yet_effective']))];
    }
    /** Read projection only; ordinary availability remains the posting gate. */
    private function retainedBalanceView(int $org,array $fund,array $totals,?array $liability):array {
        $ordinary=RetainedTaxBalance::signed((string)$totals['available_allocation']);
        $gross=moneyCents((string)$totals['finalized_dv_gross']);
        if(!$liability || moneyCents((string)$liability['withholding_recorded'])===0)
            return RetainedTaxBalance::summarize($ordinary,$gross,0,0,0);
        $stage='School and Division identity';$reviewDv=null;
        try {
            $fundId=(int)$fund['id'];
            $office=one($this->db,"SELECT id,parent_division_id FROM organizations WHERE id=? AND kind='SCHOOL' AND active=1",[$org]);
            demand($office!==null && (int)$office['parent_division_id']>0,'Retained funding requires an active school and Division',409);
            $division=(int)$office['parent_division_id'];
            demand(one($this->db,"SELECT id FROM organizations WHERE id=? AND kind='DIVISION' AND active=1",[$division])!==null,'Division unavailable',409);
            $stage='Original withholding events and Division authority';
            $recorded=$this->confirmedRetainedAuthority($org,$fundId);
            $eligible=$recorded['recorded_eligible_cents'];$confirmed=$recorded['confirmed_cents'];
            demand($eligible===moneyCents((string)$liability['withholding_recorded']) && $confirmed===moneyCents((string)$liability['retention_confirmed']),'Retained component totals disagree',409);
            $stage='Withholding component status and amounts';
            $rows=q($this->db,"SELECT w.id,w.dv_id,w.fund_id,w.amount,w.atc_code,
                (SELECT e.state FROM tax_disposition_events e WHERE e.component_id=w.id ORDER BY e.id DESC LIMIT 1) latest_state,
                (SELECT e.amount FROM tax_disposition_events e WHERE e.component_id=w.id ORDER BY e.id DESC LIMIT 1) latest_amount,
                (SELECT COUNT(*) FROM tax_disposition_events e WHERE e.component_id=w.id AND e.state='PENDING' AND e.amount=w.amount) matching_pending
                FROM withholding_components w JOIN disbursement_vouchers d ON d.id=w.dv_id
                WHERE d.organization_id=? AND d.fund_id=? AND d.status='FINALIZED' AND w.disposition='SCHOOL_UTILIZATION' LIMIT 501",[$org,$fundId])->fetchAll();
            demand(count($rows)<=500,'Retained source capacity exceeded',409);
            $sources=[];$sum=0;$confirmedSum=0;
            foreach($rows as $r){$reviewDv='DV record #'.(int)$r['dv_id'];$id=(int)$r['id'];$amount=moneyCents((string)$r['amount']);
                demand(!isset($sources[$id]) && (int)$r['fund_id']===$fundId && $amount>0 && in_array($r['latest_state'],['PENDING','CONFIRMED_CREDIT'],true) && moneyCents((string)$r['latest_amount'])===$amount && (int)$r['matching_pending']===1,'Retained component has conflicting status, amount or fund',409);
                $sources[$id]=['dv_id'=>(int)$r['dv_id'],'cents'=>$amount,'atc_code'=>(string)$r['atc_code'],'confirmed'=>$r['latest_state']==='CONFIRMED_CREDIT'];$sum+=$amount;
                if($r['latest_state']==='CONFIRMED_CREDIT')$confirmedSum+=$amount;
            }
            $reviewDv=null;
            demand($sum===$eligible&&$confirmedSum===$confirmed,'Retained source totals changed',409);
            $stage='Finalized DV and funding history';
            $vouchers=q($this->db,"SELECT d.id,d.fund_id,d.gross,d.net,d.document_date,d.display_number,
                (SELECT v.snapshot_json FROM document_versions v WHERE v.organization_id=d.organization_id AND v.doc_type='DV' AND v.entity_id=d.id ORDER BY v.version_no,v.id LIMIT 1) snapshot_json,
                (SELECT COUNT(*) FROM audit_events a WHERE a.organization_id=d.organization_id AND a.entity_type='DV' AND a.entity_id=d.id AND a.event_type='FINALIZED') audit_count,
                (SELECT a.details FROM audit_events a WHERE a.organization_id=d.organization_id AND a.entity_type='DV' AND a.entity_id=d.id AND a.event_type='FINALIZED' ORDER BY a.id LIMIT 1) audit_json
                FROM disbursement_vouchers d LEFT JOIN school_tax_transfers t ON t.dv_id=d.id
                WHERE d.organization_id=? AND d.fund_id=? AND d.status='FINALIZED' AND t.id IS NULL ORDER BY d.id LIMIT 5001",[$org,$fundId])->fetchAll();
            demand(count($vouchers)<=5000,'DV funding history exceeds review capacity',409);
            $used=0;$grossSum=0;$seen=[];$policies=[];$classes=[];$sourceDvs=array_fill_keys(array_column($sources,'dv_id'),true);
            foreach($vouchers as $v){$id=(int)$v['id'];
                $stage='Finalized DV and funding history';$reviewDv='DV '.(trim((string)($v['display_number']??''))!==''?(string)$v['display_number']:'record #'.$id);
                demand(!isset($seen[$id]) && (int)$v['audit_count']<=1,'Duplicate finalized DV or audit',409);$seen[$id]=true;
                $grossSum+=moneyCents((string)$v['gross']);
                $snapshot=$v['snapshot_json']===null?[]:json_decode((string)$v['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
                $audit=$v['audit_json']===null?[]:json_decode((string)$v['audit_json'],true,64,JSON_THROW_ON_ERROR);
                demand(is_array($snapshot)&&is_array($audit),'Invalid retained funding records',409);
                $basis=$snapshot['funding_basis']??null;
                if(RetainedTaxBalance::requiresPolicy($basis)||isset($sourceDvs[$id])){
                    $stage='Historical school classification and Division policy';
                    $p=$snapshot['withholding_policy']??[];$pid=(int)($p['version_id']??0);$cid=(int)($p['school_class_version_id']??0);
                    demand(($p['mode']??'')==='DIVISION_SETTLES_UTILIZABLE'&&$pid>0&&$cid>0,'Original school retention authority missing',409);
                    if(!isset($policies[$pid]))$policies[$pid]=one($this->db,'SELECT config_json,effective_from FROM configuration_versions WHERE id=? AND organization_id=? AND config_key=?',[$pid,$division,self::fundPolicyKey((string)$fund['source_code'],'NON_IUS')]);
                    if(!isset($classes[$cid]))$classes[$cid]=one($this->db,'SELECT config_json,effective_from FROM configuration_versions WHERE id=? AND organization_id=? AND config_key=?',[$cid,$org,self::SCHOOL_IMPLEMENTATION_KEY]);
                    $policy=$policies[$pid];$class=$classes[$cid];
                    $pd=$policy?json_decode((string)$policy['config_json'],true,64,JSON_THROW_ON_ERROR):[];
                    $cd=$class?json_decode((string)$class['config_json'],true,64,JSON_THROW_ON_ERROR):[];
                    demand($policy&&$class&&$policy['effective_from']<=$v['document_date']&&$class['effective_from']<=$v['document_date']&&
                        ($pd['school_class']??'')==='NON_IUS'&&($pd['source_code']??'')===(string)$fund['source_code']&&
                        ($pd['policy']??'')==='DIVISION_SETTLES_UTILIZABLE'&&trim((string)($pd['authority']??''))!==''&&($cd['classification']??'')==='NON_IUS','Historical school or Division policy is not verified',409);
                }
                $stage='Saved funding snapshot and finalization audit';
                $used+=RetainedTaxBalance::applied($v,$snapshot,$audit,$sources);
            }
            $reviewDv=null;$stage='Fund-level reconciliation';
            demand($grossSum===$gross&&count(array_diff_key($sourceDvs,$seen))===0,'Fund gross or source coverage changed',409);
            return RetainedTaxBalance::summarize($ordinary,$gross,$eligible,$confirmed,$used);
        } catch(DomainError|JsonException|TypeError $e) {
            return RetainedTaxBalance::unavailable($ordinary,RetainedTaxBalance::reviewReason($e,$stage,$reviewDv));
        }
    }
    /** Read-only NON-IU policy workpaper for existing school-retained withholding.
     * This is NOT a remittance, Division bank receipt, tax clearance, additional
     * allotment or automatic offset to the ordinary allocation difference.
     * Each component is counted exactly once; confirmation is an eligibility
     * attestation and never proof that the BIR received payment.
     */
    private function schoolDivisionTaxAccountability(int $org): array {
        $rows=q($this->db,"SELECT v.fund_id,
                COALESCE(SUM(w.amount),0) withholding_recorded,
                COALESCE(SUM(CASE WHEN EXISTS (
                    SELECT 1 FROM tax_disposition_events e WHERE e.component_id=w.id
                    AND e.state='CONFIRMED_CREDIT') THEN w.amount ELSE 0 END),0) retention_confirmed,
                COUNT(*) component_count
            FROM withholding_components w
            JOIN disbursement_vouchers v ON v.id=w.dv_id
            WHERE v.organization_id=? AND v.status='FINALIZED'
              AND w.disposition='SCHOOL_UTILIZATION'
            GROUP BY v.fund_id ORDER BY v.fund_id LIMIT 101",[$org])->fetchAll();
        demand(count($rows)<=100,
            'Withholding accountabilities exceed the overview limit. Ask Division Finance for a scoped report.',409);
        $result=[];
        foreach($rows as $row){
            $gross=moneyCents((string)$row['withholding_recorded']);
            $confirmed=moneyCents((string)$row['retention_confirmed']);
            demand($confirmed<=$gross,'Retained tax confirmation exceeds the original liability; accounting review required',409);
            $result[(int)$row['fund_id']]=[
                'withholding_recorded'=>moneyString($gross),
                'retention_confirmed'=>moneyString($confirmed),
                'awaiting_confirmation'=>moneyString($gross-$confirmed),
                'component_count'=>(int)$row['component_count'],
                'division_bir_settlement_status'=>'EVIDENCE_NOT_TRACKED_IN_THIS_REGISTER',
                'cash_transferred_to_division'=>false,
                'no_allocation_adjustment'=>true];
        }
        return $result;
    }
    /** Division oversight: named receiving-office view, no fictitious cash transfer.
     * Does not modify the source DV, component, check, ledger, or tax event.
     */
    private function divisionUtilizationLiabilityWorkpaper(int $division): array {
        $rows=q($this->db,"SELECT o.id school_id,o.name school_name,f.id fund_id,
                f.name fund_name,f.source_code,f.fiscal_year,
                COALESCE(SUM(w.amount),0) withholding_recorded,
                COALESCE(SUM(CASE WHEN EXISTS (
                    SELECT 1 FROM tax_disposition_events e WHERE e.component_id=w.id
                    AND e.state='CONFIRMED_CREDIT') THEN w.amount ELSE 0 END),0) retention_confirmed,
                COUNT(*) component_count
            FROM withholding_components w
            JOIN disbursement_vouchers v ON v.id=w.dv_id AND v.status='FINALIZED'
            JOIN organizations o ON o.id=v.organization_id AND o.kind='SCHOOL' AND o.active=1
            JOIN funds f ON f.id=v.fund_id AND f.organization_id=o.id
            WHERE o.parent_division_id=? AND w.disposition='SCHOOL_UTILIZATION'
            GROUP BY o.id,o.name,f.id,f.name,f.source_code,f.fiscal_year
            ORDER BY o.name,f.fiscal_year,f.name LIMIT 201",[$division])->fetchAll();
        demand(count($rows)<=200,
            'More than 200 school/fund tax-liability groups. Request a paginated Division accounting report.',409);
        foreach($rows as &$row){
            $gross=moneyCents((string)$row['withholding_recorded']);
            $confirmed=moneyCents((string)$row['retention_confirmed']);
            demand($confirmed<=$gross,'Division retained-tax evidence exceeds original components',409);
            $row['withholding_recorded']=moneyString($gross);
            $row['retention_confirmed']=moneyString($confirmed);
            $row['awaiting_confirmation']=moneyString($gross-$confirmed);
            $row['division_bir_settlement_status']='REQUIRES_SEPARATE_BIR_AND_ACCOUNTING_EVIDENCE';
            $row['cash_transferred_to_division']=false;
        }unset($row);
        return $rows;
    }
    /** Verified bank downloads are the single authoritative source for both
     * allocation authority and the bank-receipts workpaper. The bank receipt
     * is a PROJECTION of this fund event, not a second posted deposit.
     * This is not a complete bank cash ledger or bank reconciliation.
     */
    private static function bankCreditDate(string $date): string {
        demand((bool)preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])-([0-2][0-9]|3[01])$/D',$date),'Bank credit date must be YYYY-MM-DD');
        $t=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('Asia/Manila'));
        demand($t!==false && $t->format('Y-m-d')===$date,'Invalid bank credit date');
        demand($date <= (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'),
            'A future bank credit cannot be confirmed or allocated',409);
        return $date;
    }
    private function bankDownloadInput(array $d,int $org): array {
        $bank=$this->record('bank_accounts',idOf($d,'bank_account_id'),$org,true);
        $credited=self::bankCreditDate(requiredString($d,'bank_credit_date',10));
        $ref=requiredString($d,'bank_credit_ref',120);
        $advice=requiredString($d,'bank_advice_ref',120);
        $evidence=requiredString($d,'bank_evidence_ref',255);
        demand((bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._\/#:-]{2,119}$/D',$ref),
            'Use the official bank credit or transaction reference (3–120 characters)');
        demand(!preg_match('/[\x00-\x1F]/',$advice.$evidence),'Invalid control characters in bank evidence');
        // The bank row is held FOR UPDATE before this check, so submissions for
        // the same bank cannot concurrently claim the same transaction reference,
        // even when funded by different source-of-fund rows.
        $duplicate=one($this->db,"SELECT id FROM fund_events WHERE organization_id=? AND bank_account_id=? AND bank_credit_ref=? AND event_type='ALLOTMENT' LIMIT 1",[$org,$bank['id'],$ref]);
        demand($duplicate===null,'This bank credit reference is already linked to an allocation. Review the bank download journal.',409);
        return ['bank_account_id'=>(int)$bank['id'],'bank_credit_date'=>$credited,
            'bank_credit_ref'=>$ref,'bank_advice_ref'=>$advice,'bank_evidence_ref'=>$evidence];
    }
    public function recordBankDownload(array $d): array {
        $org=$this->org($d);
        demand(in_array($this->user['role'],['SUPER_ADMIN','DIVISION'],true),'Finance administrator required',403);
        return $this->financialTx('bank.download.record',$d,function()use($d,$org){
            $fund=$this->record('funds',idOf($d,'fund_id'),$org,true);
            $amount=moneyCents($d['amount']??'');demand($amount>0,'Positive bank download required');
            $credit=$this->bankDownloadInput($d,$org);
            $particulars=requiredString($d,'particulars',300);
            $id=$this->insert('fund_events',array_merge([
                'organization_id'=>$org,'fund_id'=>$fund['id'],'event_type'=>'ALLOTMENT',
                'amount'=>moneyString($amount),'reference_type'=>'BANK_DOWNLOAD',
                'reference_id'=>$credit['bank_account_id'],'reason'=>$particulars,
                'created_by'=>$this->user['id']],$credit));
            $this->audit($org,'BANK_DOWNLOAD_RECORDED','FUND_EVENT',$id,[
                'fund_id'=>(int)$fund['id'],'bank_account_id'=>$credit['bank_account_id'],
                'bank_credit_date'=>$credit['bank_credit_date'],'bank_credit_ref'=>$credit['bank_credit_ref'],
                'bank_advice_ref'=>$credit['bank_advice_ref'],'evidence_ref'=>$credit['bank_evidence_ref'],
                'amount'=>moneyString($amount),'status'=>'UNRECONCILED']);
            return ['id'=>$id,'fund_id'=>(int)$fund['id'],'bank_account_id'=>$credit['bank_account_id'],
                'credit_date'=>$credit['bank_credit_date'],'amount'=>moneyString($amount),'status'=>'UNRECONCILED'];
        });
    }
    public function linkHistoricalBankDownload(array $d): array {
        $org=$this->org($d);
        demand(in_array($this->user['role'],['SUPER_ADMIN','DIVISION'],true),'Finance administrator required',403);
        return $this->financialTx('bank.download.link_existing',$d,function()use($d,$org){
            $eventId=idOf($d,'fund_event_id');
            $candidate=one($this->db,"SELECT fund_id FROM fund_events WHERE id=? AND organization_id=? AND event_type='ALLOTMENT'",[$eventId,$org]);
            demand($candidate!==null,'Original allotment not found in this school',404);
            $fund=$this->record('funds',(int)$candidate['fund_id'],$org,true);
            $event=one($this->db,"SELECT * FROM fund_events WHERE id=? AND organization_id=? FOR UPDATE",[$eventId,$org]);
            demand($event!==null && $event['event_type']==='ALLOTMENT' && $event['bank_account_id']===null,
                'Allotment is already bank-linked or is not eligible for historical linking',409);
            $credit=$this->bankDownloadInput($d,$org);
            $evidenceReason=requiredString($d,'link_reason',300);
            $saved=q($this->db,'UPDATE fund_events SET bank_account_id=?, bank_credit_date=?, bank_credit_ref=?, bank_advice_ref=?, bank_evidence_ref=? WHERE id=? AND organization_id=? AND bank_account_id IS NULL',[
                $credit['bank_account_id'],$credit['bank_credit_date'],$credit['bank_credit_ref'],
                $credit['bank_advice_ref'],$credit['bank_evidence_ref'],$eventId,$org]);
            demand($saved->rowCount()===1,'The original allotment was already linked. Refresh before retrying.',409);
            // Original event amount, event_type, reason, created_at and author
            // remain untouched; only proof/linkage metadata is attached.
            $this->audit($org,'BANK_DOWNLOAD_HISTORICAL_LINK','FUND_EVENT',$eventId,[
                'fund_id'=>(int)$fund['id'],'original_amount'=>(string)$event['amount'],
                'original_created_at'=>(string)$event['created_at'],
                'bank_account_id'=>$credit['bank_account_id'],'bank_credit_date'=>$credit['bank_credit_date'],
                'bank_credit_ref'=>$credit['bank_credit_ref'],'bank_advice_ref'=>$credit['bank_advice_ref'],
                'evidence_ref'=>$credit['bank_evidence_ref'],'link_reason'=>$evidenceReason,'status'=>'UNRECONCILED']);
            return ['id'=>$eventId,'fund_id'=>(int)$fund['id'],'amount'=>(string)$event['amount'],
                'credit_date'=>$credit['bank_credit_date'],'status'=>'UNRECONCILED','linked_existing'=>true];
        });
    }
    public function fundAvailability(array $d): array {
        $org=$this->org($d);$fund=$this->record('funds',idOf($d,'fund_id'),$org);
        $totals=$this->fundAvailabilityTotals($org,(int)$fund['id']);
        if(isset($d['bank_account_id']) && (int)$d['bank_account_id']>0){
            $mode=$this->schoolUtilizableFundingMode($org,(int)$fund['id']);
            $isTaxTransfer=false;
            if(isset($d['tax_transfer_dv_id'])){
                $transfer=$this->taxTransferRecord($org,idOf($d,'tax_transfer_dv_id'));
                demand($transfer!==null && (int)$transfer['fund_id']===(int)$fund['id'] && $transfer['status']==='DRAFT',
                    'Only an original pending tax-transfer DV may request a tax-liability bank-cash preview.',409);
                $isTaxTransfer=true;
            }
            if($mode['enabled']||$isTaxTransfer){
                try{
                    $bank=$this->verifiedFundBankCash($org,(int)$fund['id'],idOf($d,'bank_account_id'));
                    $confirmed=$isTaxTransfer
                        ?['confirmed_cents'=>0,'pending_cents'=>0,'recorded_eligible_cents'=>0]
                        :$this->confirmedRetainedAuthority($org,(int)$fund['id']);
                    $totals['bank_cash_readiness']=['ready'=>true,'cash_basis'=>$bank['cash_basis'],
                        'actual_bank_cash_requires_officer_attestation'=>true,
                        'bank_cash'=>moneyString($bank['fund_cash_cents']),
                        'required_minimum'=>moneyString($bank['minimum_cents']),
                        'confirmed_retained'=>moneyString(max(0,$confirmed['confirmed_cents']-max(0,-(
                            str_starts_with((string)$totals['available_allocation'],'-')
                                ?-moneyCents(ltrim((string)$totals['available_allocation'],'-'))
                                :moneyCents((string)$totals['available_allocation']))))),
                        'retained_pending'=>moneyString($confirmed['pending_cents']),
                        'gross_authority_headroom'=>moneyString(max(0,(
                            str_starts_with((string)$totals['available_allocation'],'-')
                                ?-moneyCents(ltrim((string)$totals['available_allocation'],'-'))
                                :moneyCents((string)$totals['available_allocation']))+$confirmed['recorded_eligible_cents'])),
                        'bank_reconciliation_id'=>$bank['bank_reconciliation_id']];
                }catch(DomainError $e){
                    $totals['bank_cash_readiness']=['ready'=>false,'reason'=>$e->getMessage()];
                }catch(Throwable $e){
                    $totals['bank_cash_readiness']=['ready'=>false,
                        'reason'=>'Bank-cash verification is unavailable. Ask technical support to examine server logs; no DV was finalized.'];
                }
            }
        }
        return $totals;
    }
    /**
     * v0.2.351: One canonical organization-scoped account/fund BOOK projection.
     * Use the same accountable bank-linked download and physical-check
     * history shown in Cash & Bank, rather than a separate SQL calculation.
     * The bank row is locked by finalizeDV BEFORE this read; no future,
     * unlinked, tax liability or reconciliation memo is an additional credit.
     * This is a BOOK cash cap, not an official bank statement. The officer's
     * separate explicit cash-availability attestation is required at posting.
     */
    private function verifiedFundBankCash(int $org,int $fundId,int $bankId,bool $lock=false): array {
        $bank=$this->record('bank_accounts',$bankId,$org,$lock);
        // Under the bank-row lock, take the identical cash snapshot used in
        // the now-tested Cash & Bank register. This validates all original
        // credit/check links and historical cancel/replace evidence.
        require_once __DIR__.'/BankFundRegister.php';
        $book=BankFundRegister::read($this->db,$org,true);
        $knownBank=false;$knownFund=false;
        foreach($book['banks'] as $row)if((int)$row['bank_account_id']===$bankId)$knownBank=true;
        foreach($book['funds'] as $row)if((int)$row['fund_id']===$fundId)$knownFund=true;
        demand($knownBank && $knownFund,'The selected bank or source fund is missing from the verified school book.',409);
        $fundCredits=0;$accountCash=0;$fundCash=0;$fundChecks=0;
        foreach($book['rows'] as $row){
            if((int)$row['bank_account_id']!==$bankId)continue;
            $credit=moneyCents((string)$row['credit']);
            $debit=moneyCents((string)$row['debit']);
            $accountCash+=$credit-$debit;
            if((int)$row['fund_id']!==$fundId)continue;
            $fundCash+=$credit-$debit;
            if(($row['kind']??'')==='DOWNLOAD')$fundCredits+=$credit;
            if(($row['kind']??'')==='CHECK')$fundChecks+=$debit;
        }
        // Historical finalized vouchers without any physical check must not
        // disappear from the cash commitments just because the book projection
        // has no check row. Require finance review rather than overstate cash.
        $unchecked=one($this->db,"SELECT v.id FROM disbursement_vouchers v
            WHERE v.organization_id=? AND v.fund_id=? AND v.status='FINALIZED'
            AND NOT EXISTS (SELECT 1 FROM checks c WHERE c.organization_id=v.organization_id
              AND c.dv_id=v.id) LIMIT 1",[$org,$fundId]);
        demand($unchecked===null,'Historical finalized DV is missing a check commitment; finance reconciliation is required.',409);
        demand($fundCredits>0,'No genuine bank-linked download is recorded for this fund in the selected account.',409);
        demand($fundCash>=0 && $accountCash>=0,
            'The fund/account cash book has a negative balance; finance review is required.',409);
        // Spendability is bounded BOTH by the selected-fund subledger and
        // by the pooled physical bank book, never by another fund's credits.
        $minimum=0;
        if(class_exists('BankReconciliation')&&FinancialAllocation::installed($this->db)){
            $accountCash+=BankReconciliation::bookAdjustments($this->db,$org,$bankId,self::todayManila());
            $minimum=BankReconciliation::maintaining($this->db,$org,$bankId);
        }
        $cash=min($fundCash,$accountCash);
        return ['fund_cash_cents'=>$cash,'minimum_cents'=>$minimum,
            'bank_reconciliation_id'=>null,'cash_basis'=>'SCHOOL_FUND_BANK_BOOK_AFTER_ISSUED_CHECKS',
            'actual_bank_cash_requires_officer_attestation'=>true,
            'fund_credits_cents'=>$fundCredits,'fund_committed_checks_cents'=>$fundChecks,
            'bank_account_id'=>$bankId,'bank_name'=>(string)$bank['bank_name']];
    }

    /** Confirmed per-component retained withholding is not another ALLOTMENT.
     * Its **approved utilization** may cover only a gross shortfall in the
     * Division school-utilizable policy mode. Each finalized gross DV already
     * reduces the ordinary allocation in fundAvailabilityTotals; therefore
     * these credits are counted ONCE, without subtracting bank checks again.
     */
    private function confirmedRetainedAuthority(int $org,int $fundId): array {
        // v0.2.362: both PENDING and CONFIRMED retained tax are one recorded
        // eligible source. Confirmation changes reporting stage, not cash.
        $rows=q($this->db,"SELECT w.id,w.amount,v.id original_dv_id,v.document_date,
            (SELECT COUNT(*) FROM tax_disposition_events t WHERE t.component_id=w.id AND t.state='PENDING') pending_count,
            (SELECT COUNT(*) FROM tax_disposition_events t WHERE t.component_id=w.id AND t.state='REMITTED') remitted_count,
            (SELECT COUNT(*) FROM tax_disposition_events t WHERE t.component_id=w.id AND t.state='CONFIRMED_CREDIT'
                AND t.amount=w.amount AND COALESCE(t.authority_reference,'')<>'') verified_count,
            (SELECT COUNT(*) FROM tax_disposition_events t WHERE t.component_id=w.id AND t.state='CONFIRMED_CREDIT') closed_count
            FROM withholding_components w
            JOIN disbursement_vouchers v ON v.id=w.dv_id
            WHERE v.organization_id=? AND v.fund_id=? AND v.status='FINALIZED'
              AND w.disposition='SCHOOL_UTILIZATION' AND w.fund_id=?",[$org,$fundId,$fundId])->fetchAll();
        demand(count($rows)<=500,'Retained-tax component history requires finance review',409);
        $confirmed=0;$eligible=0;$ids=[];$pendingIds=[];$fund=$this->record('funds',$fundId,$org);
        $division=$this->divisionId($org);$historic=[];
        foreach($rows as $row){
            $closed=(int)$row['closed_count'];
            demand((int)$row['pending_count']===1 && $closed<=1 && (int)$row['remitted_count']===0,
                'Recorded school-retained tax has conflicting or missing disposition history.',409);
            if($closed===1)demand((int)$row['verified_count']===1,
                'Confirmed retained tax is missing authority evidence.',409);
            $dvId=(int)$row['original_dv_id'];
            if(!isset($historic[$dvId])){
                $ver=one($this->db,"SELECT snapshot_json FROM document_versions WHERE organization_id=?
                    AND doc_type='DV' AND entity_id=? ORDER BY version_no ASC LIMIT 1",[$org,$dvId]);
                $snap=$ver===null?null:json_decode((string)$ver['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
                $record=$snap['withholding_policy']??null;
                demand(is_array($record)&&($record['mode']??'')==='DIVISION_SETTLES_UTILIZABLE'&&
                    (int)($record['version_id']??0)>0,
                    'Recorded withholding lacks an original school-utilization policy snapshot.',409);
                $version=(int)$record['version_id'];
                $saved=one($this->db,'SELECT config_json,effective_from FROM configuration_versions
                    WHERE id=? AND organization_id=?',[$version,$division]);
                $policy=$saved===null?null:json_decode((string)$saved['config_json'],true,512,JSON_THROW_ON_ERROR);
                demand(is_array($policy)&&($policy['school_class']??'')==='NON_IUS'&&
                    ($policy['source_code']??'')===(string)$fund['source_code']&&
                    ($policy['policy']??'')==='DIVISION_SETTLES_UTILIZABLE'&&
                    trim((string)($policy['authority']??''))!==''&&
                    (string)$saved['effective_from']<=(string)$row['document_date'],
                    'Recorded retained tax is not traceable to effective Division authority.',409);
                $historic[$dvId]=true;
            }
            $amount=moneyCents((string)$row['amount']);
            demand($amount>0,'Invalid recorded retained tax amount.',409);
            $eligible+=$amount;
            if($closed===1){$confirmed+=$amount;$ids[]=(int)$row['id'];}
            else $pendingIds[]=(int)$row['id'];
        }
        return ['confirmed_cents'=>$confirmed,'source_component_ids'=>$ids,
            'recorded_eligible_cents'=>$eligible,'pending_cents'=>$eligible-$confirmed,
            'pending_component_ids'=>$pendingIds];
    }

    /** Active only for verified NON_IUS / Division school-utilization funds.
     * Used from finalization INSIDE locks; read path never authorizes payment.
     */
    private function schoolUtilizableFundingMode(int $org,int $fundId,bool $lock=false): array {
        $policy=$this->regularDvTaxPolicy($org,$fundId,false,$lock);
        $enabled=$policy['ready'] && $policy['school_classification']==='NON_IUS'
            && $policy['mode']==='DIVISION_SETTLES_UTILIZABLE' &&
            (int)$policy['version_id']>0 && trim((string)$policy['authority'])!=='';
        return ['enabled'=>$enabled,'policy'=>$policy];
    }

    public function addBankAccount(array $d): array {
        $org=$this->org($d);
        // Bank profiles belong to their owning workspace: school staff register
        // their own approved school account; Division staff their own Division
        // account. Division reporting scope alone never permits school writes.
        // No fund allocation, cash deposit or disbursement is posted here.
        $this->operationalWriteAllowed($org);
        return $this->tx(function()use($d,$org){
            $name=requiredString($d,'bank_name',120);$label=requiredString($d,'account_label',120);
            $mask=requiredString($d,'masked_account',20);
            demand((bool)preg_match('/^[A-Za-z0-9*\- ]{3,20}$/',$mask),'Enter a masked account reference only');
            demand(!preg_match('/\d{7,}/',$mask),'Do not store a complete bank account number');
            demand(one($this->db,'SELECT id FROM bank_accounts WHERE organization_id=? AND bank_name=? AND account_label=?',[$org,$name,$label])===null,'Bank account label already exists');
            $id=$this->insert('bank_accounts',['organization_id'=>$org,'bank_name'=>$name,'account_label'=>$label,'masked_account'=>$mask]);
            $this->audit($org,'BANK_ACCOUNT_REGISTERED','BANK',$id,['bank_name'=>$name,'account_label'=>$label]);
            return ['id'=>$id,'bank_name'=>$name,'account_label'=>$label];
        });
    }
    /** Change a bank DIRECTORY label only. No account identity, funds or payments change.
     * Division can correct labels of its attached schools; all access remains
     * scoped and is audited in the account-owning organization.
     */
    public function renameBankAccountLabel(array $d): array {
        $org=$this->org($d);
        allowedToWrite($this->user);
        $office=one($this->db,'SELECT id,kind,parent_division_id FROM organizations WHERE id=? AND active=1',[$org]);
        demand($office!==null,'Selected organization is unavailable',404);
        $role=(string)$this->user['role'];
        if($role==='DIVISION' && $office['kind']==='SCHOOL'){
            demand((int)$office['parent_division_id']>0,'School has no Division Office',403);
            $this->divisionForActor((int)$office['parent_division_id']);
        }else{
            $this->operationalWriteAllowed($org);
        }
        $id=idOf($d,'bank_account_id');
        $label=requiredString($d,'account_label',120);
        return $this->financialTx('bank.label.rename',$d,function()use($org,$id,$label){
            $existing=$this->record('bank_accounts',$id,$org,true);
            $old=(string)$existing['account_label'];
            demand($old!==$label,'The account label has not changed',422);
            demand(one($this->db,'SELECT id FROM bank_accounts WHERE organization_id=? AND bank_name=? AND account_label=? AND id<>?',
                [$org,$existing['bank_name'],$label,$id])===null,'This bank already has an account with that label',409);
            // A Division bank label is the payee snapshot on a school's tax transfer DV.
            // Prevent changing the label while an active transfer relies on it.
            demand(one($this->db,"SELECT id FROM school_tax_transfers WHERE division_bank_account_id=? AND status<>'CANCELLED' LIMIT 1",[$id])===null,
                'This account is linked to a tax transfer; its payee label must remain stable. Finance review required.',409);
            q($this->db,'UPDATE bank_accounts SET account_label=? WHERE id=? AND organization_id=?',[$label,$id,$org]);
            $this->audit($org,'BANK_ACCOUNT_LABEL_RENAMED','BANK',$id,[
                'previous_label'=>$old,'new_label'=>$label,'bank_name'=>$existing['bank_name'],
                'masked_account_unchanged'=>true,'fund_cash_unchanged'=>true]);
            return ['id'=>$id,'account_label'=>$label,'previous_label'=>$old];
        });
    }
    public function list(array $p): mixed {
        $name=requiredString($p,'kind',40);$org=$this->org($p);
        DivisionSchoolAccess::requireGet($this->db,$this->user,'list',$p);
        // v0.2.218 Asset Register: bounded, school-scoped, read-only filtered pages.
        // Reuse the established list route / school-scope authorization; this
        // branch never returns records from another organization's workspace.
        // v0.2.350 read-only, organization-scoped bank book and per-fund cash register.
        // No new financial entries or altered DV finalization rules.
        // v0.2.352: scoped read-only fund expense, tax and check audit facets.
        // Compute authorization from the existing canonical Domain function.
        // Neither this route nor the new helper creates or alters entries.
        if($name==='fund_transaction_audit'){
            demand(($this->user['role']??'')!=='VIEWER',
                'Fund transaction details are unavailable to issued-document-only reviewers',403);
            $ids=q($this->db,'SELECT id,source_code FROM funds WHERE organization_id=? ORDER BY id LIMIT 101',[$org])->fetchAll();
            demand(count($ids)<=100,'Fund directory exceeds report capacity; finance review required',409);
            $authority=[];$liabilities=$this->schoolDivisionTaxAccountability($org);
            foreach($ids as $fundRow){
                $id=(int)$fundRow['id'];$authority[$id]=$this->fundAvailabilityTotals($org,$id);
                $authority[$id]+=$this->retainedBalanceView($org,$fundRow,$authority[$id],$liabilities[$id]??null);
            }
            require_once __DIR__.'/FundTransactionAudit.php';
            return FundTransactionAudit::read($this->db,$org,$p,$authority);
        }
        if($name==='cash_fund_register'){
            demand(($this->user['role']??'')!=='VIEWER',
                'Bank cash and funding balances are not available to issued-document-only reviewers',403);
            require_once __DIR__.'/BankFundRegister.php';
            return BankFundRegister::read($this->db,$org);
        }
        if($name==='assets' && (string)($p['register_page']??'')==='1')
            return $this->propertyAssetRegister($org,$p);
        // Jules PR #39 integration: read-only textbook movement ledger, scoped to
        // BOTH the movement and its parent batch. Bounded keyset pages avoid
        // unbounded queries on shared hosting and make history browsable.
        if($name==='textbook_movements'){
            $where=['m.organization_id=?'];$args=[$org];
            $rawBatch=$p['batch_id']??'';
            if($rawBatch!=='' && $rawBatch!==null){
                $where[]='m.batch_id=?';$args[]=idOf($p,'batch_id');
            }
            $month=trim((string)($p['month']??''));
            if($month!==''){
                demand((bool)preg_match('/^\\d{4}-(0[1-9]|1[0-2])$/D',$month),'Invalid textbook history month');
                $where[]='m.created_at>=? AND m.created_at<DATE_ADD(?,INTERVAL 1 MONTH)';
                $args[]=$month.'-01';$args[]=$month.'-01';
            }
            $rawBefore=$p['before_id']??'';
            if($rawBefore!=='' && $rawBefore!==null){
                $where[]='m.id<?';$args[]=idOf($p,'before_id');
            }
            $sql="SELECT m.id,m.batch_id,m.quantity_delta,m.event_type,m.reference,m.actor_id,m.created_at,
                         b.batch,t.title,t.grade,t.subject
                  FROM textbook_movements m
                  JOIN textbook_batches b ON b.id=m.batch_id AND b.organization_id=m.organization_id
                  JOIN textbook_titles t ON t.id=b.title_id
                  WHERE ".implode(' AND ',$where)." ORDER BY m.id DESC LIMIT 101";
            return q($this->db,$sql,$args)->fetchAll();
        }
        $map=[
            // v0.2.361: retrieve the saved PR requestor, not the requesting unit.
            // The Boolean flags are computed from the complete scoped history
            // BEFORE the 100-row register limit; never guess from the RFQ list's
            // separate 100-row page. This listing is read-only.
            'pr'=>"SELECT p.id,p.display_number,p.status,p.purpose,p.total,p.document_date,p.created_at,p.requesting_unit,
                (SELECT NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(cv.config_json,'$.name'))),'')
                   FROM configuration_versions cv
                   WHERE cv.organization_id=p.organization_id
                     AND cv.config_key=CONCAT('PR_REQUESTOR_V1_',p.id)
                   ORDER BY cv.id DESC LIMIT 1) AS requestor_name,
                EXISTS(SELECT 1 FROM rfqs r WHERE r.organization_id=p.organization_id AND r.pr_id=p.id) AS has_rfq,
                EXISTS(SELECT 1 FROM awards a WHERE a.organization_id=p.organization_id AND a.pr_id=p.id AND a.status='FINALIZED') AS has_award,
                f.name fund_name FROM purchase_requests p JOIN funds f ON f.id=p.fund_id
                WHERE p.organization_id=? ORDER BY p.id DESC LIMIT 100",
            'rfq'=>"SELECT r.*,p.display_number pr_number,p.purpose AS pr_purpose,p.total AS pr_total,
                (SELECT TIMESTAMPDIFF(SECOND,v.created_at,NOW()) FROM document_versions v
                 WHERE v.organization_id=r.organization_id AND v.doc_type='RFQ' AND v.entity_id=r.id
                 AND v.version_no=1 LIMIT 1) AS age_seconds,
                (SELECT COUNT(*) FROM supplier_quotes sq WHERE sq.rfq_id=r.id) AS quote_count,
                (SELECT COUNT(*) FROM awards a WHERE a.rfq_id=r.id) AS award_count
                FROM rfqs r JOIN purchase_requests p ON p.id=r.pr_id AND p.organization_id=r.organization_id
                WHERE r.organization_id=? ORDER BY r.id DESC LIMIT 100",

            'quotes'=>"SELECT q.id,q.rfq_id,r.pr_id,r.display_number rfq_number,q.supplier_id,s.name supplier,q.offered_at,q.evidence_note,(SELECT COUNT(*) FROM quote_lines l WHERE l.supplier_quote_id=q.id) line_count,(SELECT COUNT(*) FROM quote_lines l WHERE l.supplier_quote_id=q.id AND l.compliant<>1) noncompliant_lines,(SELECT COALESCE(SUM(l.offered_price * COALESCE(l.rfq_quantity,pl.quantity)),0) FROM quote_lines l JOIN pr_lines pl ON pl.id=l.pr_line_id WHERE l.supplier_quote_id=q.id) offered_total,CASE WHEN EXISTS(SELECT 1 FROM awards a WHERE a.rfq_id=q.rfq_id AND a.supplier_id=q.supplier_id AND a.organization_id=r.organization_id) THEN 1 ELSE 0 END already_awarded FROM supplier_quotes q JOIN rfqs r ON r.id=q.rfq_id JOIN suppliers s ON s.id=q.supplier_id WHERE r.organization_id=? ORDER BY q.id DESC LIMIT 100",
            'awards'=>"SELECT a.id,a.display_number,a.pr_id,a.rfq_id,a.supplier_id,a.determination_note,a.status,s.name supplier,r.display_number rfq_number,p.display_number pr_number,p.purpose pr_purpose,t.method pr_procurement_route,po_existing.id existing_po_id,po_existing.display_number existing_po_number,po_existing.status existing_po_status
                FROM awards a JOIN suppliers s ON s.id=a.supplier_id JOIN rfqs r ON r.id=a.rfq_id JOIN purchase_requests p ON p.id=a.pr_id AND p.organization_id=a.organization_id JOIN procurement_transactions t ON t.id=p.transaction_id AND t.organization_id=a.organization_id
                LEFT JOIN purchase_orders po_existing ON po_existing.id=(SELECT po2.id FROM purchase_orders po2 JOIN awards aw2 ON aw2.id=po2.award_id AND aw2.organization_id=po2.organization_id WHERE po2.organization_id=a.organization_id AND aw2.rfq_id=a.rfq_id AND aw2.supplier_id=a.supplier_id ORDER BY po2.id ASC LIMIT 1)
                WHERE a.organization_id=? ORDER BY a.id DESC LIMIT 100",
            'po'=>"SELECT po.*,s.name supplier,p.display_number pr_number,
                    GREATEST(0,
                        (SELECT COALESCE(SUM(pl.quantity),0) FROM po_lines pl WHERE pl.po_id=po.id)
                        - (SELECT COALESCE(SUM(il.delivered_qty),0) FROM iar_lines il
                             JOIN iars ir ON ir.id=il.iar_id AND ir.organization_id=po.organization_id
                             JOIN po_lines pl2 ON pl2.id=il.po_line_id AND pl2.po_id=po.id
                             WHERE ir.status!='CANCELLED')) AS remaining_delivery_qty
                    FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id
                    JOIN purchase_requests p ON p.id=po.pr_id
                    WHERE po.organization_id=? ORDER BY po.id DESC LIMIT 100",
            'iar'=>"SELECT i.*,po.display_number po_number FROM iars i JOIN purchase_orders po ON po.id=i.po_id WHERE i.organization_id=? ORDER BY i.id DESC LIMIT 100",
            'dv'=>"SELECT d.*,f.name fund_name FROM disbursement_vouchers d JOIN funds f ON f.id=d.fund_id WHERE d.organization_id=? ORDER BY d.id DESC LIMIT 100",
            'ris'=>"SELECT r.*,p.display_number AS pr_number,CASE WHEN r.status='REVERSED' AND r.display_number IS NULL THEN 'CANCELLED' ELSE r.status END AS display_status FROM requisition_issue_slips r LEFT JOIN purchase_requests p ON p.id=r.pr_id AND p.organization_id=r.organization_id WHERE r.organization_id=? ORDER BY r.id DESC LIMIT 100",
             'banks'=>"SELECT b.id,b.bank_name,b.account_label,b.masked_account FROM bank_accounts b WHERE b.organization_id=? ORDER BY b.bank_name,b.account_label LIMIT 100",
            'bank_downloads'=>"SELECT e.id,e.fund_id,f.name fund_name,f.source_code,f.fiscal_year,e.amount,e.reason,e.created_at,e.bank_account_id,e.bank_credit_date,e.bank_credit_ref,e.bank_advice_ref,e.bank_evidence_ref,b.bank_name,b.account_label,b.masked_account,CASE WHEN e.bank_account_id IS NULL THEN 'AWAITING_BANK_LINK' ELSE 'UNRECONCILED' END bank_status FROM fund_events e JOIN funds f ON f.id=e.fund_id LEFT JOIN bank_accounts b ON b.id=e.bank_account_id AND b.organization_id=e.organization_id WHERE e.organization_id=? AND e.event_type='ALLOTMENT' ORDER BY e.id DESC LIMIT 250",
            'funds'=>"SELECT f.*, COALESCE((SELECT SUM(CASE WHEN event_type='ALLOTMENT' AND bank_account_id IS NOT NULL AND bank_credit_date<=DATE_FORMAT(CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','+08:00'),'%Y-%m-%d') THEN amount ELSE 0 END) FROM fund_events e WHERE e.fund_id=f.id),0) allotment, COALESCE((SELECT SUM(CASE WHEN event_type='RELEASE' THEN amount ELSE 0 END) FROM fund_events e WHERE e.fund_id=f.id),0) releases, COALESCE((SELECT SUM(CASE WHEN event_type='OBLIGATION' THEN amount WHEN event_type='OBLIGATION_REVERSAL' THEN -amount ELSE 0 END) FROM fund_events e WHERE e.fund_id=f.id),0) obligations, COALESCE((SELECT SUM(CASE WHEN event_type='DISBURSEMENT' THEN amount WHEN event_type='DISBURSEMENT_REVERSAL' THEN -amount ELSE 0 END) FROM fund_events e WHERE e.fund_id=f.id),0) disbursements, COALESCE((SELECT SUM(CASE WHEN event_type='PENDING_TAX' THEN amount WHEN event_type='PENDING_TAX_REVERSAL' THEN -amount ELSE 0 END) FROM fund_events e WHERE e.fund_id=f.id),0) pending_tax, COALESCE((SELECT SUM(CASE WHEN event_type='CONFIRMED_CREDIT' THEN amount ELSE 0 END) FROM fund_events e WHERE e.fund_id=f.id),0) confirmed_credit FROM funds f WHERE f.organization_id=? ORDER BY f.name LIMIT 100",
            'received_property'=>"SELECT il.id AS iar_line_id,ir.id AS iar_id,ir.display_number AS iar_number,ir.accepted_at,ic.name AS item_name,ic.category,il.accepted_qty,pl.unit_price,COUNT(a.id) AS registered_units FROM iar_lines il JOIN iars ir ON ir.id=il.iar_id JOIN purchase_orders po ON po.id=ir.po_id AND po.organization_id=ir.organization_id JOIN po_lines pl ON pl.id=il.po_line_id AND pl.po_id=po.id JOIN item_catalog ic ON ic.id=pl.item_id LEFT JOIN assets a ON a.iar_line_id=il.id AND a.organization_id=ir.organization_id WHERE ir.organization_id=? AND ir.status='FINALIZED' AND po.status='FINALIZED' AND ic.category IN ('PPE','SEMI_EXPENDABLE') GROUP BY il.id,ir.id,ir.display_number,ir.accepted_at,ic.name,ic.category,il.accepted_qty,pl.unit_price ORDER BY il.id DESC LIMIT 100",
            'assets'=>"SELECT a.id,a.property_tag,a.serial_number,a.acquisition_cost,a.acquired_on,a.opening_record,a.iar_line_id,a.custodian,a.location,a.status,a.condition_note,a.property_profile,i.name AS item_name,i.category, EXISTS(SELECT 1 FROM document_links ret WHERE ret.organization_id=a.organization_id AND ret.source_type='ASSET' AND ret.source_id=a.id AND ret.target_type IN ('RRSP','PRR')) AS ever_returned_to_school, CASE WHEN i.category='PPE' THEN 'PAR' WHEN i.category='SEMI_EXPENDABLE' THEN 'ICS' ELSE NULL END AS required_document, (SELECT CASE WHEN NOT EXISTS(SELECT 1 FROM document_links rl WHERE rl.organization_id=ad.organization_id AND rl.source_type=ad.doc_type AND rl.source_id=ad.id AND rl.target_type IN ('RRSP','PRR')) THEN ad.display_number ELSE NULL END FROM accountability_documents ad WHERE ad.organization_id=a.organization_id AND ad.asset_id=a.id AND ad.status='FINALIZED' ORDER BY ad.id DESC LIMIT 1) AS latest_accountability_number FROM assets a JOIN item_catalog i ON i.id=a.item_id WHERE a.organization_id=? AND a.status<>'TRANSFERRED' ORDER BY a.id DESC LIMIT 100",
            // One numbered ICS can cover several asset rows.  Only the anchor
            // row has the immutable document_registry entity entry; additional
            // custody rows share its number but do not allocate new numbers.
            'accountability'=>"SELECT d.id,d.asset_id,d.doc_type,d.display_number,d.custodian,d.status,d.created_at,a.property_tag,i.name AS item_name,
                EXISTS(SELECT 1 FROM document_links rl WHERE rl.organization_id=d.organization_id AND rl.source_type=d.doc_type AND rl.source_id=d.id AND rl.target_type IN ('RRSP','PRR')) AS returned_to_school,
                EXISTS(SELECT 1 FROM document_links tx WHERE tx.organization_id=d.organization_id AND tx.source_type=d.doc_type AND tx.source_id=d.id AND tx.target_type IN ('ITR','PTR')) AS transferred_out,
                (SELECT COUNT(*) FROM accountability_documents x WHERE x.organization_id=d.organization_id AND x.doc_type=d.doc_type AND x.display_number=d.display_number AND x.status=d.status) AS property_count,
                (SELECT GROUP_CONCAT(x.asset_id ORDER BY x.id SEPARATOR ',') FROM accountability_documents x WHERE x.organization_id=d.organization_id AND x.doc_type=d.doc_type AND x.display_number=d.display_number AND x.status=d.status) AS property_asset_ids
                FROM accountability_documents d
                JOIN document_registry dr ON dr.organization_id=d.organization_id AND dr.doc_type=d.doc_type AND dr.entity_id=d.id AND dr.display_number=d.display_number
                JOIN assets a ON a.id=d.asset_id AND a.organization_id=d.organization_id JOIN item_catalog i ON i.id=a.item_id
                WHERE d.organization_id=? ORDER BY d.id DESC LIMIT 100",
            // These lists deliberately keep historical lot/event records intact. A
            // property/service receipt never becomes an issuable supply merely
            // because an old IAR happened to create a stock_events RECEIPT.
            'stocks'=>"SELECT l.id,l.item_id,i.name AS item_name,i.category,l.batch,l.opening_record,COALESCE(SUM(e.quantity_delta),0) AS on_hand FROM inventory_lots l JOIN item_catalog i ON i.id=l.item_id LEFT JOIN stock_events e ON e.lot_id=l.id AND e.organization_id=l.organization_id WHERE l.organization_id=? AND i.category='SUPPLY' GROUP BY l.id,l.item_id,i.name,i.category,l.batch,l.opening_record ORDER BY i.name LIMIT 100",
            'stock_exceptions'=>"SELECT l.id,l.item_id,i.name AS item_name,i.category,l.batch,l.opening_record,ir.display_number AS iar_number,COALESCE(SUM(e.quantity_delta),0) AS on_hand FROM inventory_lots l JOIN item_catalog i ON i.id=l.item_id LEFT JOIN iar_lines il ON il.id=l.iar_line_id LEFT JOIN iars ir ON ir.id=il.iar_id AND ir.organization_id=l.organization_id LEFT JOIN stock_events e ON e.lot_id=l.id AND e.organization_id=l.organization_id WHERE l.organization_id=? AND i.category<>'SUPPLY' GROUP BY l.id,l.item_id,i.name,i.category,l.batch,l.opening_record,ir.display_number ORDER BY i.name,l.id LIMIT 100",

            'textbook_batches'=>"SELECT b.id,b.title_id,t.title,t.isbn,t.grade,t.subject,b.batch,b.storage,b.condition_note,b.opening_record,COALESCE(SUM(m.quantity_delta),0) AS on_hand FROM textbook_batches b JOIN textbook_titles t ON t.id=b.title_id LEFT JOIN textbook_movements m ON m.batch_id=b.id AND m.organization_id=b.organization_id WHERE b.organization_id=? GROUP BY b.id,b.title_id,t.title,t.isbn,t.grade,t.subject,b.batch,b.storage,b.condition_note,b.opening_record ORDER BY b.id DESC LIMIT 100",
            'checks'=>"SELECT c.*,d.display_number dv_number,d.payee dv_payee FROM checks c JOIN disbursement_vouchers d ON d.id=c.dv_id WHERE c.organization_id=? ORDER BY c.id DESC LIMIT 100",
            'suppliers'=>"SELECT s.id,s.name,s.proprietor_name,s.tax_id,s.address,s.zip_code,s.business_classification,s.vat_status,s.goods_profile_active,s.services_profile_active,s.rental_profile_active,s.contractor_profile_active,s.utilities_profile_active,s.other_profile_active,s.active,
                (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa WHERE sa.supplier_id=s.id) AS atc_codes,
                (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs a ON a.code=sa.atc_code WHERE sa.supplier_id=s.id AND a.tax_kind='VAT') AS vat_atc_codes,
                (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs a ON a.code=sa.atc_code WHERE sa.supplier_id=s.id AND a.tax_kind='EWT') AS ewt_atc_codes,
                (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs a ON a.code=sa.atc_code WHERE sa.supplier_id=s.id AND (a.tax_kind IS NULL OR a.tax_kind NOT IN ('VAT','EWT'))) AS unclassified_atc_codes,
                (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs a ON a.code=sa.atc_code WHERE sa.supplier_id=s.id AND a.tax_kind='VAT' AND COALESCE(NULLIF(sa.profile_type,''),a.supply_type) IN ('BOTH','GOODS')) AS goods_vat_atc_codes,
                (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs a ON a.code=sa.atc_code WHERE sa.supplier_id=s.id AND a.tax_kind='EWT' AND COALESCE(NULLIF(sa.profile_type,''),a.supply_type) IN ('BOTH','GOODS')) AS goods_ewt_atc_codes,
                (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs a ON a.code=sa.atc_code WHERE sa.supplier_id=s.id AND a.tax_kind='VAT' AND COALESCE(NULLIF(sa.profile_type,''),a.supply_type) IN ('BOTH','SERVICES')) AS services_vat_atc_codes,
                (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs a ON a.code=sa.atc_code WHERE sa.supplier_id=s.id AND a.tax_kind='EWT' AND COALESCE(NULLIF(sa.profile_type,''),a.supply_type) IN ('BOTH','SERVICES')) AS services_ewt_atc_codes,
                (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs a ON a.code=sa.atc_code WHERE sa.supplier_id=s.id AND a.tax_kind='VAT' AND COALESCE(sa.rental_profile,0)=1) AS rental_vat_atc_codes,
                (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs a ON a.code=sa.atc_code WHERE sa.supplier_id=s.id AND a.tax_kind='EWT' AND COALESCE(sa.rental_profile,0)=1) AS rental_ewt_atc_codes,
                (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs a ON a.code=sa.atc_code WHERE sa.supplier_id=s.id AND a.tax_kind='VAT' AND COALESCE(sa.contractor_profile,0)=1) AS contractor_vat_atc_codes,
                (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs a ON a.code=sa.atc_code WHERE sa.supplier_id=s.id AND a.tax_kind='EWT' AND COALESCE(sa.contractor_profile,0)=1) AS contractor_ewt_atc_codes,
                (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs a ON a.code=sa.atc_code WHERE sa.supplier_id=s.id AND a.tax_kind='VAT' AND COALESCE(sa.utilities_profile,0)=1) AS utilities_vat_atc_codes,
                (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs a ON a.code=sa.atc_code WHERE sa.supplier_id=s.id AND a.tax_kind='EWT' AND COALESCE(sa.utilities_profile,0)=1) AS utilities_ewt_atc_codes,
                (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs a ON a.code=sa.atc_code WHERE sa.supplier_id=s.id AND a.tax_kind='VAT' AND COALESCE(sa.other_profile,0)=1) AS other_vat_atc_codes,
                (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs a ON a.code=sa.atc_code WHERE sa.supplier_id=s.id AND a.tax_kind='EWT' AND COALESCE(sa.other_profile,0)=1) AS other_ewt_atc_codes
                FROM suppliers s ORDER BY s.name LIMIT 200",
            'atcs'=>"SELECT code,COALESCE(NULLIF(description_full,''),description) AS description,supply_type,goods_applicable,services_applicable,rental_applicable,contractor_applicable,utilities_applicable,other_applicable,COALESCE(NULLIF(business_type,''),'BOTH') AS business_type,COALESCE(NULLIF(tax_kind,''),'UNCLASSIFIED') AS tax_kind,active FROM tax_atcs ORDER BY code LIMIT 400",
            'atc_rates'=>"SELECT v.id,v.atc_code,COALESCE(NULLIF(a.description_full,''),a.description) AS description,a.supply_type,COALESCE(NULLIF(a.business_type,''),'BOTH') AS business_type,COALESCE(NULLIF(a.tax_kind,''),'UNCLASSIFIED') AS tax_kind,a.active AS atc_active,v.rate,v.starts,v.ends,v.authority_reference FROM tax_rate_versions v JOIN tax_atcs a ON a.code=v.atc_code ORDER BY v.starts DESC,v.id DESC LIMIT 500",
            'units'=>"SELECT id,code,name FROM units ORDER BY code LIMIT 200",
            'all_items'=>"SELECT i.id,i.name,i.category,i.subcategory,i.active,i.unit_id,u.code unit FROM item_catalog i JOIN units u ON u.id=i.unit_id ORDER BY i.name LIMIT 200",
            'items'=>"SELECT i.id,i.name,i.category,i.subcategory,u.code unit FROM item_catalog i JOIN units u ON u.id=i.unit_id WHERE 1=? AND i.active=1 ORDER BY i.name LIMIT 100",
            'organizations'=>"SELECT id,code,name,kind FROM organizations WHERE id=? OR ?=0 ORDER BY name LIMIT 100",
        ];
        demand(isset($map[$name]),'Unsupported listing');
        if(in_array($name,['all_items','items'],true)){
            $division=$this->divisionId($org);
            $sql="SELECT i.id,i.name,i.category,i.subcategory,i.active,i.unit_id,u.code unit,
                    i.owner_organization_id,
                    CASE WHEN i.owner_organization_id IS NULL THEN 'Legacy shared'
                         WHEN i.owner_organization_id=? THEN ?
                         ELSE 'Division catalog' END AS catalog_scope
                FROM item_catalog i JOIN units u ON u.id=i.unit_id
                
                WHERE (i.owner_organization_id IS NULL OR i.owner_organization_id=? OR i.owner_organization_id=?)";
            if($name==='items')$sql.=' AND i.active=1';
            return q($this->db,$sql.' ORDER BY i.name,i.id LIMIT '.($name==='items'?'100':'200'),
                [$org,$org===$division?'Division catalog':'School catalog',$org,$division])->fetchAll();
        }
        if(in_array($name,['suppliers','units','atcs','atc_rates'],true)) return q($this->db,$map[$name])->fetchAll();
        if($name==='organizations') {
            if($this->user['role']==='SUPER_ADMIN')return q($this->db,'SELECT id,code,name,kind FROM organizations ORDER BY name')->fetchAll();
            if($this->user['role']==='VIEWER'){
                $ids=ipdamsMooeViewerSchools($this->db,$this->user);
                if(!$ids)return [];
                $marks=implode(',',array_fill(0,count($ids),'?'));
                return q($this->db,"SELECT id,code,name,kind FROM organizations WHERE id IN ($marks) AND active=1 AND kind='SCHOOL' ORDER BY name",$ids)->fetchAll();
            }
            return q($this->db,'SELECT o.id,o.code,o.name,o.kind FROM organizations o JOIN user_organization_scopes s ON s.organization_id=o.id WHERE s.user_id=? ORDER BY o.name',[$this->user['id']])->fetchAll();
        }
        // Optional archive reporting period. Apply SQL filtering before LIMIT 100,
        // never use a frontend slice of current records for historical searches.
        // Dates are based on the document's official document/issuance date where
        // available; draft documents without a date may not match a period.
        $yearText=trim((string)($p['year']??''));
        $monthText=trim((string)($p['month']??''));
        demand($yearText==='' || (preg_match('/^(19|20|21)\\d{2}$/',$yearText)===1 && (int)$yearText>=1900 && (int)$yearText<=2100),
            'Year must be 1900–2100',400);
        demand($monthText==='' || (preg_match('/^(?:[1-9]|0[1-9]|1[0-2])$/',$monthText)===1),
            'Month must be 1–12',400);
        $periodExpression=[
            'pr'=>'COALESCE(p.document_date,p.created_at)',
            'rfq'=>'r.issued_at',
            'quotes'=>'COALESCE(q.offered_at,r.issued_at)',
            // Archive filters use the SAME documentary dates shown in the registers.
            // Saved issue dates can differ from the day the records were entered.
            // Fall back to the recorded timestamp only for historical records without date evidence.
            'awards'=>"COALESCE(
                (SELECT cv.effective_from FROM configuration_versions cv
                 WHERE cv.organization_id=a.organization_id AND cv.config_key=CONCAT('AWARD_DOC_V1_',a.id)
                 ORDER BY cv.id DESC LIMIT 1),
                (SELECT dr.created_at FROM document_registry dr
                 WHERE dr.organization_id=a.organization_id AND dr.doc_type='AWARD' AND dr.entity_id=a.id LIMIT 1))",
            // Appendix 61 dated corrections supersede the original PO date.
            // Original issue-date configuration remains immutable and is never rewritten.
            'po'=>"COALESCE(
                (SELECT NULLIF(JSON_UNQUOTE(JSON_EXTRACT(cv.config_json,'$.document_date')),'')
                 FROM configuration_versions cv
                 WHERE cv.organization_id=po.organization_id
                   AND cv.config_key=CONCAT('PO_APPENDIX_61_DOCUMENT_CORRECTION_V1_',po.id)
                 ORDER BY cv.id DESC LIMIT 1),
                (SELECT cv.effective_from FROM configuration_versions cv
                 WHERE cv.organization_id=po.organization_id
                   AND cv.config_key=CONCAT('PO_DOCUMENT_DATE_V1_',po.id)
                 ORDER BY cv.id DESC LIMIT 1),
                (SELECT dr.created_at FROM document_registry dr
                 WHERE dr.organization_id=po.organization_id AND dr.doc_type='PO' AND dr.entity_id=po.id LIMIT 1))",
            // IAR report date is independent of the physical acceptance date.
            'iar'=>"COALESCE(
                (SELECT NULLIF(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.iar_date')),'')
                 FROM document_versions v
                 WHERE v.organization_id=i.organization_id AND v.doc_type='IAR' AND v.entity_id=i.id
                 ORDER BY v.version_no DESC LIMIT 1),i.accepted_at)",
            'dv'=>"(SELECT dr.created_at FROM document_registry dr WHERE dr.organization_id=d.organization_id AND dr.doc_type='DV' AND dr.entity_id=d.id LIMIT 1)",
            'ris'=>"(SELECT dr.created_at FROM document_registry dr WHERE dr.organization_id=r.organization_id AND dr.doc_type='RIS' AND dr.entity_id=r.id LIMIT 1)",
            'checks'=>'c.created_at',
            'accountability'=>'d.created_at',
            'assets'=>'a.acquired_on',
        ];
        $params=[$org];$sql=$map[$name];
        if(($yearText!=='' || $monthText!=='') && isset($periodExpression[$name])){
            $expression=$periodExpression[$name];
            $clauses=[];
            if($yearText!==''){$clauses[]='YEAR('.$expression.')=?';$params[]=(int)$yearText;}
            if($monthText!==''){$clauses[]='MONTH('.$expression.')=?';$params[]=(int)$monthText;}
            // Replace only the outer ORDER BY; asset subqueries have their own sorting.
            $orderAt=strrpos($sql,' ORDER BY ');
            demand($orderAt!==false,'Archive listing needs deterministic ordering',500);
            $sql=substr_replace($sql,' AND '.implode(' AND ',$clauses),$orderAt,0);
        }
        $rows=q($this->db,$sql,$params)->fetchAll();
        if($name==='assets')foreach($rows as &$row){
            $row['review_state']=PropertyBatchMetadata::reviewState($row['property_profile']??null);
            unset($row['property_profile']);
        }unset($row);
        if($name==='funds'){
            $liabilities=$this->schoolDivisionTaxAccountability($org);
            foreach($rows as &$row){
                $row += $this->fundAvailabilityTotals($org,(int)$row['id']);
                $row['division_tax_accountability']=$liabilities[(int)$row['id']]??null;
                $row += $this->retainedBalanceView($org,$row,$row,$row['division_tax_accountability']);
            }unset($row);
        }
        if($name==='rfq' && $rows){
            // v0.2.367: compute quote eligibility from complete source PR quantities
            // and non-cancelled PO history, not the independently capped PO list.
            require_once __DIR__.'/RfqQuotationEligibility.php';
            $ids=array_values(array_unique(array_map(static fn($r)=>(int)$r['pr_id'],$rows)));
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $prLines=q($this->db,"SELECT l.id,l.pr_id,l.quantity FROM pr_lines l
                JOIN purchase_requests p ON p.id=l.pr_id AND p.organization_id=?
                WHERE l.pr_id IN ($marks) ORDER BY l.pr_id,l.id LIMIT 10001",[$org,...$ids])->fetchAll();
            demand(count($prLines)<=10000,'RFQ item history exceeds safe register limit. Contact the technical administrator.',409);
            $poRows=q($this->db,"SELECT l.pr_line_id,COALESCE(SUM(l.quantity),0) AS ordered_quantity
                FROM po_lines l JOIN purchase_orders po ON po.id=l.po_id
                WHERE po.organization_id=? AND po.pr_id IN ($marks) AND po.status!='CANCELLED'
                GROUP BY l.pr_line_id",[$org,...$ids])->fetchAll();
            $rows=RfqQuotationEligibility::annotate($rows,$prLines,$poRows);
        }
        return $rows;
    }
    /** Explicit RFQ lines, with a non-destructive legacy fallback for pre-v0.2.35 RFQs. */
    private function rfqSelectedLines(int $rfqId,int $prId): array {
        $rfq=one($this->db,'SELECT selected_lines_json FROM rfqs WHERE id=?',[$rfqId]);
        $selected=json_decode((string)($rfq['selected_lines_json']??''),true);
        if(is_array($selected)&&count($selected)>0){
            $lines=[];
            foreach($selected as $entry){
                $item=one($this->db,'SELECT id,pr_id,description_snapshot AS item_name,unit_snapshot AS unit,quantity AS pr_quantity,estimated_unit_price FROM pr_lines WHERE id=?',[(int)($entry['pr_line_id']??0)]);
                demand($item!==null&&(int)$item['pr_id']===$prId,'Saved RFQ item does not match the PR');
                $item['pr_line_id']=$item['id'];$item['quantity']=$entry['quantity'];$lines[]=$item;
            }
            return $lines;
        }
        return q($this->db,'SELECT p.id AS pr_line_id,p.quantity,p.description_snapshot AS item_name,p.unit_snapshot AS unit,p.quantity AS pr_quantity,p.estimated_unit_price FROM pr_lines p WHERE p.pr_id=? ORDER BY p.id',[$prId])->fetchAll();
    }
    /** RFQ corrections are limited to eight consecutive hours from the first
     * recorded immutable version, NOT the editable issued_at document date.
     * An absent or invalid first version fails closed rather than reopening old
     * RFQs. Called while the RFQ row is locked for any write.
     */
    private function rfqEditState(array $rfq): array {
        $id=(int)$rfq['id'];$org=(int)$rfq['organization_id'];
        $first=one($this->db,"SELECT version_no,created_at,
            TIMESTAMPDIFF(SECOND,created_at,NOW()) age_seconds
            FROM document_versions WHERE organization_id=? AND doc_type='RFQ' AND entity_id=?
            ORDER BY version_no ASC LIMIT 1",[$org,$id]);
        $latest=one($this->db,"SELECT version_no FROM document_versions
            WHERE organization_id=? AND doc_type='RFQ' AND entity_id=?
            ORDER BY version_no DESC LIMIT 1",[$org,$id]);
        $age=$first===null||$first['age_seconds']===null?null:(int)$first['age_seconds'];
        $reason='';
        if(($rfq['status']??'')!=='FINALIZED')$reason='Only active finalized RFQs may be corrected.';
        elseif(trim((string)($rfq['display_number']??''))==='')$reason='RFQ number is missing; correction requires administrative review.';
        elseif(!$first||!$latest||(int)$first['version_no']!==1||$age===null||$age<0)$reason='Original RFQ creation time cannot be verified; correction is locked.';
        elseif($age>=8*3600)$reason='RFQ locked: eight-hour correction period has expired.';
        elseif(one($this->db,'SELECT id FROM supplier_quotes WHERE rfq_id=? LIMIT 1',[$id])!==null)
            $reason='RFQ locked: supplier quotation already recorded.';
        elseif(one($this->db,'SELECT id FROM awards WHERE rfq_id=? LIMIT 1',[$id])!==null)
            $reason='RFQ locked: award already recorded.';
        return ['allowed'=>$reason==='','reason'=>$reason,'expected_version'=>(int)($latest['version_no']??0),
            'age_seconds'=>$age,'remaining_seconds'=>$age===null?0:max(0,28800-$age)];
    }
    public function workflowRfqLines(array $p): array {
        $org=$this->org($p);$rfq=$this->record('rfqs',idOf($p,'rfq_id'),$org);
        return ['rfq'=>$rfq,'lines'=>$this->rfqSelectedLines((int)$rfq['id'],(int)$rfq['pr_id']),
            'edit'=>$this->rfqEditState($rfq)];
    }
    /** Safe, READ-ONLY consolidated-RFQ preview. No RFQ/claim is written here.
     * Backfill of all prior RFQ line claims and database uniqueness are mandatory
     * before enabling a consolidated RFQ CREATE endpoint.
     */
    public function workflowRfqCandidates(array $d): array {
        $org=$this->org($d);
        $first=$this->record('purchase_requests',idOf($d,'pr_id'),$org);
        demand($first['status']==='FINALIZED','Choose a finalized PR before consolidating requests.',409);
        $fund=(int)$first['fund_id'];
        $prs=q($this->db,'SELECT p.id,p.display_number,p.requesting_unit,p.purpose,p.fund_id,p.document_date,
            f.name AS fund_name FROM purchase_requests p JOIN funds f ON f.id=p.fund_id
            WHERE p.organization_id=? AND p.status=\'FINALIZED\' AND p.fund_id=? ORDER BY p.id DESC LIMIT 201',
            [$org,$fund])->fetchAll();
        demand(count($prs)<=200,'Too many finalized PRs for one preview; refine by period before continuing.',409);
        $lines=q($this->db,'SELECT l.id AS pr_line_id,l.pr_id,l.item_id,l.description_snapshot,l.unit_snapshot,
            l.quantity,l.estimated_unit_price,p.display_number AS pr_number,p.requesting_unit
            FROM pr_lines l JOIN purchase_requests p ON p.id=l.pr_id
            WHERE p.organization_id=? AND p.status=\'FINALIZED\' AND p.fund_id=?
            ORDER BY p.id,l.id LIMIT 3001',[$org,$fund])->fetchAll();
        demand(count($lines)<=3000,'Too many PR items for one consolidation preview; request an indexed batch.',409);
        // Current and historical selections both consume a PR line. Removed,
        // cancelled or superseded RFQ lines are deliberately NOT returned to pool.
        $rfqs=q($this->db,'SELECT id,pr_id,selected_lines_json FROM rfqs WHERE organization_id=?',[$org])->fetchAll();
        $history=q($this->db,"SELECT v.entity_id,v.snapshot_json FROM document_versions v
            JOIN rfqs r ON r.id=v.entity_id AND r.organization_id=v.organization_id
            WHERE v.organization_id=? AND v.doc_type='RFQ' ORDER BY v.entity_id,v.version_no",[$org])->fetchAll();
        $claims=[];$legacyPrs=[];
        foreach($rfqs as $r){
            $selected=json_decode((string)($r['selected_lines_json']??''),true);
            if(!is_array($selected)||!count($selected)){$legacyPrs[(int)$r['pr_id']]=true;continue;}
            foreach($selected as $entry){
                demand(is_array($entry)&&isset($entry['pr_line_id'])&&(int)$entry['pr_line_id']>0,
                    'Recorded RFQ selection is malformed; administrative review required.',409);
                $claims[(int)$entry['pr_line_id']]=true;
            }
        }
        foreach($history as $version){
            $snap=json_decode((string)$version['snapshot_json'],true);
            if(!is_array($snap)||!is_array($snap['lines']??null)||!count($snap['lines'])){
                // An unresolvable legacy version is conservatively handled by
                // withholding EVERY line of the RFQ anchor PR.
                foreach($rfqs as $r)if((int)$r['id']===(int)$version['entity_id'])
                    $legacyPrs[(int)$r['pr_id']]=true;
                continue;
            }
            foreach($snap['lines'] as $entry){
                if(!is_array($entry)||!(int)($entry['pr_line_id']??0)){
                    foreach($rfqs as $r)if((int)$r['id']===(int)$version['entity_id'])
                        $legacyPrs[(int)$r['pr_id']]=true;
                    continue;
                }
                $claims[(int)$entry['pr_line_id']]=true;
            }
        }
        $counts=[];$eligible=[];$claimedCount=0;
        foreach($lines as $line){
            $prId=(int)$line['pr_id'];
            if(isset($legacyPrs[$prId])||isset($claims[(int)$line['pr_line_id']])){$claimedCount++;continue;}
            $counts[$prId]=($counts[$prId]??0)+1;
            $line['pr_line_id']=(int)$line['pr_line_id'];$line['pr_id']=$prId;
            $line['item_id']=(int)$line['item_id'];
            // Only the same item ID, UOM AND exact original specification are
            // eligible for suggested grouping; never fuzzy-match descriptions.
            $line['group_key']=hash('sha256',json_encode([$line['item_id'],
                trim((string)$line['description_snapshot']),trim((string)$line['unit_snapshot'])],JSON_THROW_ON_ERROR));
            $eligible[]=$line;
        }
        foreach($prs as &$pr){$pr['eligible_count']=$counts[(int)$pr['id']]??0;}unset($pr);
        return ['source_pr_id'=>(int)$first['id'],'fund_id'=>$fund,
            'fund_name'=>((string)($prs[0]['fund_name']??'')),
            'prs'=>$prs,'lines'=>$eligible,'unavailable_lines'=>$claimedCount,
            'note'=>'Preview only. Final RFQ creation is withheld pending database claim/backfill and one-Award/one-PO compatibility checks.'];
    }

    /** Read-only quotation detail for a guided, organization-scoped procurement form. */
    public function workflowQuote(array $p): array {
        $org=$this->org($p);$id=idOf($p,'quote_id');
        $quote=one($this->db,'SELECT q.id,q.rfq_id,q.supplier_id,r.pr_id,s.name supplier FROM supplier_quotes q JOIN rfqs r ON r.id=q.rfq_id JOIN suppliers s ON s.id=q.supplier_id WHERE q.id=? AND r.organization_id=?',[$id,$org]);
        demand($quote!==null,'Quotation not found in active workspace',404);
        $lines=q($this->db,'SELECT l.pr_line_id,l.offered_price,l.compliant,p.description_snapshot AS item_name,p.unit_snapshot AS unit,COALESCE(l.rfq_quantity,p.quantity) AS quantity FROM quote_lines l JOIN pr_lines p ON p.id=l.pr_line_id WHERE l.supplier_quote_id=? ORDER BY p.id',[$id])->fetchAll();
        $saved=one($this->db,'SELECT offered_at,evidence_note FROM supplier_quotes WHERE id=?',[$id]);
        $quote['offered_at']=$saved['offered_at'];$quote['evidence_note']=$saved['evidence_note'];
        return ['quote'=>$quote,'lines'=>$lines,'edit_token'=>$this->quotationEditToken($id)];
    }
    /** Remaining ordered quantities, including prior accepted/rejected deliveries. */
    public function workflowPoLines(array $p): array {
        $org=$this->org($p);$id=idOf($p,'po_id');
        $po=$this->record('purchase_orders',$id,$org);
        $lines=q($this->db,"SELECT l.id AS po_line_id,l.pr_line_id,l.item_id,l.quantity,l.unit_price,p.description_snapshot AS item_name,p.unit_snapshot AS unit,COALESCE((SELECT SUM(il.delivered_qty) FROM iar_lines il JOIN iars i ON i.id=il.iar_id WHERE il.po_line_id=l.id AND i.status!='CANCELLED'),0) AS delivered_to_date FROM po_lines l JOIN pr_lines p ON p.id=l.pr_line_id WHERE l.po_id=? ORDER BY l.id",[$id])->fetchAll();
        require_once __DIR__.'/PoDocuments.php';
        $document=PoDocuments::load($this->db,$org,$id);
        $source=trim((string)($document['fund']['source_code']??''));
        if($source==='')$source=trim((string)($document['fund']['name']??''));
        return ['po'=>$po,'lines'=>$lines,'document_meta'=>[
            'number'=>(string)($document['number']??$po['display_number']??''),
            'document_date'=>(string)($document['document_date']??''),
            'supplier_name'=>(string)($document['supplier']['name']??''),
            'supplier_address'=>(string)($document['supplier']['address']??''),
            'responsibility_center'=>$source]];
    }
    /** Catalog ownership is additive. Historical rows without an owner stay legacy-shared. */
    private function catalogItemAccessible(int $org,int $itemId,bool $activeOnly=true): bool {
        $division=$this->divisionId($org);
        $row=one($this->db,'SELECT i.id FROM item_catalog i  '
            .'WHERE i.id=? AND (i.owner_organization_id IS NULL OR i.owner_organization_id=? OR i.owner_organization_id=?)'
            .($activeOnly?' AND i.active=1':''),[$itemId,$org,$division]);
        return $row!==null;
    }
    private function catalogItemMayMaintain(int $org,int $itemId): void {
        $this->operationalWriteAllowed($org);
        $row=one($this->db,'SELECT i.owner_organization_id FROM item_catalog i '
            .' WHERE i.id=?',[$itemId]);
        demand($row!==null,'Item not found',404);
        if($row['owner_organization_id']===null){
            demand(in_array($this->user['role'],['SUPER_ADMIN','DIVISION'],true),
                'Only Division/technical staff can maintain a legacy shared item',403);
        }else{
            demand((int)$row['owner_organization_id']===$org,
                'Only the owning school or Division Office can maintain this catalog item',403);
        }
    }
    /** Server-side item lookup, not limited by the first 100 catalog entries. */
    public function searchCatalog(array $p): array {
        $org=$this->org($p);$division=$this->divisionId($org);
        $term=trim((string)($p['term']??''));
        demand(mbSafeLen($term)<=90,'Catalog search is too long');
        $like='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$term).'%';
        return q($this->db,"SELECT i.id,i.name,i.category,i.subcategory,u.code AS unit,
                CASE WHEN i.owner_organization_id IS NULL THEN 'Legacy shared'
                     WHEN i.owner_organization_id=? THEN ?
                     ELSE 'Division catalog' END AS catalog_scope
            FROM item_catalog i JOIN units u ON u.id=i.unit_id
            
            WHERE i.active=1 AND (i.owner_organization_id IS NULL OR i.owner_organization_id=? OR i.owner_organization_id=?)
            AND (i.name LIKE ? OR i.category LIKE ? OR COALESCE(i.subcategory,'') LIKE ? OR u.code LIKE ?)
            ORDER BY i.name,i.id LIMIT 30",[$org,$org===$division?'Division catalog':'School catalog',$org,$division,$like,$like,$like,$like])->fetchAll();
    }
    /** Shared reference tables may only be changed by Division/technical administrators. */
    private function referenceWriter(): void {
        demand(in_array($this->user['role'],['SUPER_ADMIN','DIVISION'],true), 'Reference maintenance requires Division/technical administration',403);
    }
    /**
     * v0.2.366: suppliers and tax ATC/rate references are shared across offices.
     * A school may contribute only from its own active, scoped workspace.
     * This deliberately does NOT grant general master-reference authority,
     * Division policy writes, UACS administration or cross-office mutations.
     * Every mutation continues through the existing locked, audited methods.
     */
    private function sharedSupplierAtcWriter(int $org): void {
        $this->operationalWriteAllowed($org);
    }
    /** The Super Administrator owns setup and can administer every Division or School. */
    public function bootstrapOrganization(array $d): array { return $this->createDivision($d); }

    private function profileFields(array $d): array {
        $result=[];
        foreach (['address'=>500, 'contact_email'=>190, 'contact_phone'=>60] as $key=>$limit) {
            $value=trim((string)($d[$key]??''));
            demand(mbSafeLen($value)<=$limit, 'Invalid '.$key);
            if($key==='contact_email' && $value!=='')demand(filter_var($value,FILTER_VALIDATE_EMAIL)!==false,'Invalid contact email');
            $result[$key]=$value?:null;
        }
        return $result;
    }
    private function divisionForActor(int $divisionId): array {
        demand(in_array($this->user['role'],['DIVISION','SUPER_ADMIN'],true),'Division or Super Administrator access required',403);
        $division=one($this->db,'SELECT * FROM organizations WHERE id=? AND kind=\'DIVISION\' AND active=1 FOR UPDATE',[$divisionId]);
        demand($division!==null,'Active Division Office not found',404);
        scope($this->db,$this->user,$divisionId);
        return $division;
    }
    public function createDivision(array $d): array {
        demand($this->user['role']==='SUPER_ADMIN','Super Administrator required to establish the Division Office',403);
        $code=strtoupper(requiredString($d,'code',24));
        demand((bool)preg_match('/^[A-Z0-9_-]{2,24}$/',$code),'Division code must be 2–24 letters, numbers, - or _');
        $name=requiredString($d,'name',200); $fields=$this->profileFields($d);
        return $this->tx(function()use($code,$name,$fields){
            // Existing standalone school(s) are preserved and linked later by Division staff.
            q($this->db,'SELECT id FROM users ORDER BY id LIMIT 1 FOR UPDATE')->fetch();
            demand(one($this->db,"SELECT id FROM organizations WHERE kind='DIVISION' LIMIT 1")===null,'Division Office already exists',409);
            demand(one($this->db,'SELECT id FROM organizations WHERE code=?',[$code])===null,'Organization code is already in use',409);
            $id=$this->insert('organizations',array_merge(['code'=>$code,'name'=>$name,'kind'=>'DIVISION','parent_division_id'=>null],$fields));
            $this->audit($id,'CREATED','DIVISION',$id,['code'=>$code]);
            return ['id'=>$id,'code'=>$code,'name'=>$name,'kind'=>'DIVISION'];
        });
    }
    public function createSchool(array $d): array {
        demand(in_array($this->user['role'],['DIVISION','SUPER_ADMIN'],true),'Division or Super Administrator required to register schools',403);
        $divisionId=idOf($d,'division_id');
        // School IDs are globally unique official six-digit strings, not numeric counters.
        // Keep leading zeros, and never use organization_id as a public School ID.
        $code=requiredString($d,'code',6);
        demand((bool)preg_match('/^[0-9]{6}$/D',$code),'School ID must contain exactly six digits');
        $name=requiredString($d,'name',200);$fields=$this->profileFields($d);
        $classification=requiredString($d,'classification',12);
        demand(in_array($classification,['IUS','NON_IUS'],true),'Select IUS or Non-IUS before registering the school');
        $effective=self::policyDate($d['effective_from']??null);
        return $this->tx(function()use($divisionId,$code,$name,$fields,$classification,$effective){
            $this->divisionForActor($divisionId);
            demand(one($this->db,'SELECT id FROM organizations WHERE code=?',[$code])===null,'Organization code already exists',409);
            try {
                $id=$this->insert('organizations',array_merge(['code'=>$code,'name'=>$name,'kind'=>'SCHOOL','parent_division_id'=>$divisionId],$fields));
            } catch (PDOException $e) {
                // The globally UNIQUE organizations.code index is the final protection
                // if two administrators submit the same School ID simultaneously.
                if ((int)($e->errorInfo[1]??0)===1062) throw new DomainError('School ID already registered. Select the existing school or correct the ID.',409);
                throw $e;
            }
            $this->grantDivisionUsersSchool($divisionId,$id);
            $this->insert('configuration_versions',['organization_id'=>$id,'config_key'=>self::SCHOOL_IMPLEMENTATION_KEY,
                'config_json'=>json_encode(['classification'=>$classification],JSON_THROW_ON_ERROR),
                'effective_from'=>$effective,'review_note'=>'Initial verified school implementation classification']);
            $this->audit($divisionId,'CREATED','SCHOOL',$id,['school_code'=>$code,'classification'=>$classification,'effective_from'=>$effective]);
            return ['id'=>$id,'code'=>$code,'name'=>$name,'parent_division_id'=>$divisionId];
        });
    }
    private function grantDivisionUsersSchool(int $divisionId,int $schoolId): void {
        q($this->db,"INSERT IGNORE INTO user_organization_scopes(user_id,organization_id)
            SELECT u.id,? FROM users u JOIN user_organization_scopes s ON s.user_id=u.id
            WHERE s.organization_id=? AND u.role='DIVISION' AND u.active=1",[$schoolId,$divisionId]);
    }
    public function attachLegacySchool(array $d): array {
        demand(in_array($this->user['role'],['DIVISION','SUPER_ADMIN'],true),'Division or Super Administrator required to attach schools',403);
        $divisionId=idOf($d,'division_id');$schoolId=idOf($d,'school_id');
        return $this->tx(function()use($divisionId,$schoolId){
            $this->divisionForActor($divisionId);
            $school=one($this->db,"SELECT * FROM organizations WHERE id=? AND kind='SCHOOL' FOR UPDATE",[$schoolId]);
            demand($school!==null,'Existing school not found',404);
            demand($school['parent_division_id']===null,'School is already assigned to a Division Office',409);
            q($this->db,'UPDATE organizations SET parent_division_id=? WHERE id=? AND parent_division_id IS NULL',[$divisionId,$schoolId]);
            $this->grantDivisionUsersSchool($divisionId,$schoolId);
            $this->audit($divisionId,'ATTACHED','SCHOOL',$schoolId,['original_id'=>$schoolId]);
            return ['id'=>$schoolId,'parent_division_id'=>$divisionId];
        });
    }
    public function createOrganizationUser(array $d): array {
        $role=requiredString($d,'role',20);$organizationId=idOf($d,'target_organization_id');
        demand(in_array($role,['DIVISION','SCHOOL'],true),'Invalid role',422);
        demand(($role==='DIVISION'&&$this->user['role']==='SUPER_ADMIN') || ($role==='SCHOOL'&&in_array($this->user['role'],['DIVISION','SUPER_ADMIN'],true)),'Not permitted to provision this role',403);
        $name=requiredString($d,'full_name',150);$email=strtolower(requiredString($d,'email',190));
        demand(filter_var($email,FILTER_VALIDATE_EMAIL)!==false,'Invalid email address');
        $pass=(string)($d['password']??'');
        demand(strlen($pass)>=12&&strlen($pass)<=256,'Initial password must be 12–256 characters');
        return $this->tx(function()use($role,$organizationId,$name,$email,$pass){
            $org=one($this->db,'SELECT * FROM organizations WHERE id=? AND active=1 FOR UPDATE',[$organizationId]);
            demand($org!==null,'Organization unavailable',404);
            if($role==='DIVISION'){
                demand($this->user['role']==='SUPER_ADMIN','Only Super Administrator can provision Division Administrators',403);
                demand($org['kind']==='DIVISION','Division account requires Division Office');
            }else{
                demand($org['kind']==='SCHOOL' && $org['parent_division_id']!==null,'School must belong to a Division Office');
                $this->divisionForActor((int)$org['parent_division_id']);
            }
            demand(one($this->db,'SELECT id FROM users WHERE email=?',[$email])===null,'Email already registered',409);
            $id=$this->insert('users',['email'=>$email,'password_hash'=>password_hash($pass,PASSWORD_DEFAULT),'full_name'=>$name,'role'=>$role,'active'=>1]);
            q($this->db,'INSERT INTO user_organization_scopes(user_id,organization_id) VALUES (?,?)',[$id,$organizationId]);
            if($role==='DIVISION')q($this->db,"INSERT IGNORE INTO user_organization_scopes(user_id,organization_id) SELECT ?,id FROM organizations WHERE parent_division_id=?",[$id,$organizationId]);
            $this->audit($role==='DIVISION'?$organizationId:(int)$org['parent_division_id'],'USER_PROVISIONED','USER',$id,['role'=>$role,'organization_id'=>$organizationId]);
            return ['id'=>$id,'role'=>$role,'email'=>$email];
        });
    }
    /** Only the Division/Super Administrator provisions a SEPARATE read-only login.
     * No Division or School administrator scope is granted to this new user. */
    public function createMooeViewerAccount(array $d): array {
        $division=$this->org($d);
        demand(in_array($this->user['role'],['DIVISION','SUPER_ADMIN'],true),
            'Only a Division administrator can provision a MOOE reviewer account',403);
        $employee=idOf($d,'employee_id');
        $email=strtolower(requiredString($d,'email',190));
        demand(filter_var($email,FILTER_VALIDATE_EMAIL)!==false,'Invalid email address');
        $password=(string)($d['password']??'');
        demand(strlen($password)>=16 && strlen($password)<=256,'Initial password must contain 16–256 characters');
        return $this->tx(function()use($division,$employee,$email,$password){
            $drow=one($this->db,"SELECT id FROM organizations WHERE id=? AND kind='DIVISION' AND active=1 FOR UPDATE",[$division]);
            demand($drow!==null,'Select an active Division Office to provision this account',403);
            $person=$this->staffActive($division,$employee);
            demand($person!==null,'Choose a currently active Division employee',403);
            $today=self::todayManila();
            $schools=q($this->db,"SELECT id FROM organizations WHERE parent_division_id=? AND kind='SCHOOL' AND active=1 ORDER BY id",[$division])->fetchAll();
            $assigned=false;
            foreach($schools as $school){
                $history=$this->schoolMooeVersions((int)$school['id'],$today);
                if((int)($history['current']['employee_id']??0)===$employee){$assigned=true;break;}
            }
            demand($assigned,'Assign the Division officer to an active school before creating a viewer login',409);
            demand(one($this->db,'SELECT id FROM users WHERE email=?',[$email])===null,'Email already registered',409);
            $existing=q($this->db,"SELECT c.config_json FROM configuration_versions c WHERE c.organization_id=?
                AND c.config_key LIKE 'MOOE_VIEWER_USER_V1_%'",[$division])->fetchAll();
            foreach($existing as $row){
                $v=json_decode((string)$row['config_json'],true);
                demand((int)($v['employee_id']??0)!==$employee,
                    'This employee already has a MOOE viewer login; use account recovery instead',409);
            }
            $name=(string)$person['full_name'];
            $id=$this->insert('users',['email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),
                'full_name'=>$name,'role'=>'VIEWER','active'=>1]);
            $cfgId=$this->insert('configuration_versions',['organization_id'=>$division,
                'config_key'=>'MOOE_VIEWER_USER_V1_'.$id,
                'config_json'=>json_encode(['user_id'=>$id,'division_id'=>$division,'employee_id'=>$employee],JSON_THROW_ON_ERROR),
                'effective_from'=>$today,'review_note'=>'Division MOOE school-scoped read-only account created']);
            $this->audit($division,'CREATED','DIVISION_MOOE_VIEWER',$id,
                ['employee_id'=>$employee,'config_version'=>$cfgId,'role'=>'VIEWER']);
            return ['user_id'=>$id,'employee_id'=>$employee,'email'=>$email,'role'=>'VIEWER'];
        });
    }
    /**
     * Recover an existing Division/School account. Passwords are one-way hashes;
     * nobody can view the previous password. All existing sessions for that user
     * become unusable on their next authenticated request (credential epoch).
     */
    public function resetOrganizationUserPassword(array $d): array {
        demand($this->user['role']==='SUPER_ADMIN','Only the Super Administrator can reset user passwords',403);
        $targetId=idOf($d,'target_user_id');
        demand($targetId!==(int)$this->user['id'],'Use My Account to change your own password',403);
        demand(($d['identity_confirmed']??false)===true,'Confirm the intended account owner before resetting',422);
        $actorPassword=(string)($d['current_admin_password']??'');
        $new=(string)($d['new_password']??'');
        $confirmation=(string)($d['confirm_password']??'');
        demand(strlen($new)>=16&&strlen($new)<=256,'New password must contain 16–256 characters');
        demand(hash_equals($new,$confirmation),'New passwords do not match');
        demand($actorPassword!=='' && $actorPassword!==$new,'Choose a different password from your own password');
        $isHttps=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
        demand($isHttps||cfg('APP_ENV')!=='production','HTTPS required for password recovery',403);
        demand((int)($_SESSION['user_reset_bad_password_attempts']??0)<5,'Too many password confirmations. Sign in again before retrying.',429);
        return $this->tx(function() use($targetId,$actorPassword,$new){
            $actor=one($this->db,'SELECT password_hash FROM users WHERE id=? AND active=1 FOR UPDATE',[$this->user['id']]);
            demand($actor!==null,'Administrator account unavailable',403);
            if(!password_verify($actorPassword,(string)$actor['password_hash'])){
                $_SESSION['user_reset_bad_password_attempts']=(int)($_SESSION['user_reset_bad_password_attempts']??0)+1;
                throw new DomainError('Current administrator password is incorrect',403);
            }
            $target=one($this->db,'SELECT id,role,password_hash,active FROM users WHERE id=? FOR UPDATE',[$targetId]);
            demand($target!==null&&(int)$target['active']===1,'Active user account not found',404);
            demand(in_array((string)$target['role'],['DIVISION','SCHOOL'],true),'This role cannot be reset here',403);
            demand(!password_verify($new,(string)$target['password_hash']),'New password must differ from the account’s current password',422);
            $hash=password_hash($new,PASSWORD_DEFAULT);
            demand(is_string($hash)&&$hash!=='','Password hashing unavailable',503);
            q($this->db,'UPDATE users SET password_hash=? WHERE id=? AND active=1',[$hash,$targetId]);
            $this->insert('audit_events',[
                'organization_id'=>null,'actor_id'=>$this->user['id'],
                'event_type'=>'PASSWORD_RESET_BY_ADMIN','entity_type'=>'USER','entity_id'=>$targetId,
                'details'=>json_encode(['role'=>$target['role'],'purpose'=>'forgotten_password'],JSON_THROW_ON_ERROR)
            ]);
            $_SESSION['user_reset_bad_password_attempts']=0;
            return ['reset'=>true,'user_id'=>$targetId,'role'=>$target['role']];
        });
    }
    public function changeOwnPassword(array $d): array {
        $current=(string)($d['current_password']??''); $new=(string)($d['new_password']??'');
        $minimum=!empty($_SESSION['password_reset_force_change'])?8:12;
        demand(strlen($new)>=$minimum&&strlen($new)<=256,
            'New password must contain '.$minimum.'–256 characters');
        demand($current!==$new,'Choose a new password different from the current password');
        return $this->tx(function()use($current,$new){
            $row=one($this->db,'SELECT password_hash FROM users WHERE id=? AND active=1 FOR UPDATE',[$this->user['id']]);
            demand($row!==null && password_verify($current,$row['password_hash']),'Current password is incorrect',403);
            q($this->db,'UPDATE users SET password_hash=? WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),$this->user['id']]);
            $this->insert('audit_events',['organization_id'=>null,'actor_id'=>$this->user['id'],'event_type'=>'PASSWORD_CHANGED','entity_type'=>'USER','entity_id'=>$this->user['id'],'details'=>'{}']);
            return ['changed'=>true];
        });
    }
    /** Division staff may correct a School ID at any time, without replacing its
     *  immutable organizations.id or any dependent financial/document references.
     *  Retain before/after ID and the authorizing reason in the audit trail. */
    public function updateOrganizationProfile(array $d): array {
        $orgId=idOf($d,'target_organization_id');$name=requiredString($d,'name',200);$profile=$this->profileFields($d);
        $requestedCode=array_key_exists('code',$d)?trim((string)$d['code']):null;
        $expectedCode=array_key_exists('expected_code',$d)?trim((string)$d['expected_code']):null;
        $changeReason=trim((string)($d['code_change_reason']??''));
        // The unified editor optionally carries effective-dated implementation status.
        // Retain the standalone endpoint for existing integrations, but never issue a
        // second client transaction from the combined school-profile form.
        $hasImplementation=array_key_exists('classification',$d);
        $implementation=$hasImplementation?(string)$d['classification']:null;
        $implementationDate=$hasImplementation?self::policyDate($d['classification_effective_from']??null):null;
        $implementationReason=trim((string)($d['classification_reason']??''));
        $expectedClassVersion=$hasImplementation ? ($d['expected_classification_version']??null) : null;
        // v0.2.436: one Division profile submission also carries its BIR registered
        // identity. Do not accept it on a School profile: IUS schools retain
        // their existing dedicated, authorized BIR profile editor.
        $hasBirIdentity=array_key_exists('bir_registered',$d);
        $birIdentity=$hasBirIdentity?$d['bir_registered']:null;
        if($hasBirIdentity)demand(is_array($birIdentity),'Invalid registered BIR identity.',422);
        // Keep ordinary Division contact/name maintenance available before the
        // first tax identity is configured. Once any BIR field is supplied or a
        // version exists, enforce the complete, verified identity atomically.
        if($hasBirIdentity && (int)($birIdentity['expected_version']??-1)===0 &&
            trim((string)($birIdentity['registered_name']??''))==='' &&
            trim((string)($birIdentity['tin']??''))==='' &&
            trim((string)($birIdentity['zip']??''))==='')$hasBirIdentity=false;
        // Historical records with an older nonstandard ID remain readable/editable.
        // Enforce six-digit formatting whenever the ID itself is being changed.
        demand(mbSafeLen($changeReason)<=500,'School ID correction reason must be 500 characters or fewer');
        if($hasImplementation){
            demand(in_array($implementation,['IUS','NON_IUS'],true),'Select IUS or Non-IUS');
            demand(mbSafeLen($implementationReason)<=500,'School classification reason must be 500 characters or fewer');
            demand(filter_var($expectedClassVersion,FILTER_VALIDATE_INT)!==false && (int)$expectedClassVersion>=0,
                'Refresh the school profile before changing its classification');
        }
        $coordinator=$this->reportMetadataCoordinator($d,$orgId);
        return $this->financialTx('organization.profile.update',$coordinator,function()use($orgId,$name,$profile,$requestedCode,$expectedCode,$changeReason,
            $hasImplementation,$implementation,$implementationDate,$implementationReason,$expectedClassVersion,
            $hasBirIdentity,$birIdentity){
            $org=one($this->db,'SELECT * FROM organizations WHERE id=? FOR UPDATE',[$orgId]);demand($org!==null,'Organization not found',404);
            if($org['kind']==='DIVISION') {
                demand(in_array($this->user['role'],['SUPER_ADMIN','DIVISION'],true),'Not authorized to edit Division',403);
                if($this->user['role']==='DIVISION') scope($this->db,$this->user,$orgId);
                // Division codes cannot be edited through the school-ID correction path.
                demand($requestedCode===null,'School ID correction is only available for schools',422);
            }else{
                demand(in_array($this->user['role'],['DIVISION','SUPER_ADMIN'],true),'Division or Super Administrator required to edit schools',403);
                // A Division Admin may only edit a school assigned to its Division.
                // Super Admin may also repair legacy unassigned school profiles.
                if($this->user['role']==='DIVISION') demand($org['parent_division_id']!==null,'School is not yet linked to a Division',403);
                if($org['parent_division_id']!==null) $this->divisionForActor((int)$org['parent_division_id']);
            }
            if($hasBirIdentity){
                demand($org['kind']==='DIVISION','Combined BIR identity may only be saved for a Division Office.',422);
                demand($profile['address']!==null && $profile['address']!=='',
                    'Enter the Division address; this is also the registered BIR address.',422);
            }
            if($hasImplementation){
                demand($org['kind']==='SCHOOL','Implementation classification is only available for schools',422);
                $previousClass=$this->currentSchoolClass($orgId,self::todayManila());
                demand((int)($previousClass['version_id']??0)===(int)$expectedClassVersion,
                    'School classification changed since this profile was opened. Refresh before saving.',409);
                $classChanged=$previousClass===null ||
                    $previousClass['classification']!==$implementation ||
                    $previousClass['effective_from']!==$implementationDate;
                if($classChanged){
                    demand($implementationReason!=='','Explain the change to implementation classification or its effective date');
                    FinancialCutoff::assertOpen($this->db,$orgId,$implementationDate);
                }
            }
            $newCode=(string)$org['code'];
            if($org['kind']==='SCHOOL' && $requestedCode!==null && $requestedCode!==$newCode){
                demand((bool)preg_match('/^[0-9]{6}$/D',$requestedCode),'School ID must contain exactly six digits');
                // Prevent a stale browser form from overwriting another Division edit.
                demand($expectedCode!==null && $expectedCode===$newCode,
                    'School ID changed since the form was opened. Refresh and review the current ID before saving.',409);
                demand($changeReason!=='','Explain why the official School ID is being corrected');
                demand(one($this->db,'SELECT id FROM organizations WHERE code=? AND id<>?',[$requestedCode,$orgId])===null,
                    'School ID already registered. Select the existing school or use a different official ID.',409);
                $newCode=$requestedCode;
            }
            try {
                q($this->db,'UPDATE organizations SET code=?,name=?,address=?,contact_email=?,contact_phone=? WHERE id=?',
                    [$newCode,$name,$profile['address'],$profile['contact_email'],$profile['contact_phone'],$orgId]);
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1]??0)===1062)
                    throw new DomainError('School ID already registered. Select the existing school or use a different official ID.',409);
                throw $e;
            }
            $auditOrg=(int)($org['parent_division_id']?:$orgId);
            if($hasImplementation && $classChanged){
                $newConfig=['classification'=>$implementation];
                // An earlier/same-day date corrects the active dated entry;
                // a later date is a normal future classification transition.
                if($previousClass!==null && $implementationDate<=$previousClass['effective_from'])
                    $newConfig['supersedes_version_id']=$previousClass['version_id'];
                $version=$this->insert('configuration_versions',[
                    'organization_id'=>$orgId,'config_key'=>self::SCHOOL_IMPLEMENTATION_KEY,
                    'config_json'=>json_encode($newConfig,JSON_THROW_ON_ERROR),
                    'effective_from'=>$implementationDate,'review_note'=>$implementationReason]);
                $this->audit($auditOrg,'IMPLEMENTATION_CLASS_CONFIGURED','SCHOOL',$orgId,[
                    'previous_classification'=>$previousClass['classification']??null,
                    'previous_effective_from'=>$previousClass['effective_from']??null,
                    'classification'=>$implementation,'effective_from'=>$implementationDate,
                    'version_id'=>$version,'reason'=>$implementationReason]);
            }
            if($newCode!==(string)$org['code']){
                $this->audit($auditOrg,'SCHOOL_ID_CHANGED','SCHOOL',$orgId,[
                    'previous_school_id'=>$org['code'],'new_school_id'=>$newCode,'reason'=>$changeReason]);
            }
            $birSaved=null;
            if($hasBirIdentity){
                // Both writes participate in this Domain transaction. A stale
                // BIR version, invalid TIN, or authorization error rolls back
                // the office change as well, eliminating partial saves.
                require_once __DIR__.'/Bir2307.php';
                $birSaved=Bir2307::saveProfile($this->db,$this->user,$orgId,[
                    'registered_name'=>$birIdentity['registered_name']??'',
                    'tin'=>$birIdentity['tin']??'',
                    'zip'=>$birIdentity['zip']??'',
                    // Single source of truth for Division address.
                    'address'=>$profile['address'],
                    'expected_version'=>$birIdentity['expected_version']??null,
                    'expected_payor_organization_id'=>$birIdentity['expected_payor_organization_id']??null,
                ]);
            }
            $this->audit($auditOrg,'PROFILE_UPDATED','ORGANIZATION',$orgId,
                ['old_name'=>$org['name'],'new_name'=>$name]);
            return ['id'=>$orgId,'code'=>$newCode,'name'=>$name,'school_id_changed'=>$newCode!==(string)$org['code'],
                'classification_recorded'=>$hasImplementation&&$classChanged,
                'bir_profile_version_id'=>$birSaved['version_id']??null];
        });
    }
    public function organizationHierarchy(): array {
        demand(in_array($this->user['role'],['SUPER_ADMIN','DIVISION','SCHOOL','VIEWER'],true),'Organization management requires an authorized account',403);
        $role=$this->user['role'];
        $fields='o.id,o.code,o.name,o.kind,o.active,o.parent_division_id,o.address,o.contact_email,o.contact_phone';
        if($role==='SUPER_ADMIN'){
            $orgs=q($this->db,"SELECT $fields FROM organizations o ORDER BY CASE WHEN o.kind='DIVISION' THEN 0 ELSE 1 END,o.name")->fetchAll();
        }elseif($role==='DIVISION'){
            // Only the Division(s) assigned to this user, constituent schools and
            // legacy unassigned schools eligible for manual linkage are visible.
            $orgs=q($this->db,"SELECT $fields FROM organizations o WHERE
                EXISTS(SELECT 1 FROM organizations d JOIN user_organization_scopes s ON s.organization_id=d.id
                    WHERE d.kind='DIVISION' AND s.user_id=? AND
                        (o.id=d.id OR (o.kind='SCHOOL' AND (o.parent_division_id=d.id OR o.parent_division_id IS NULL))))
                ORDER BY CASE WHEN o.kind='DIVISION' THEN 0 ELSE 1 END,o.name",[$this->user['id']])->fetchAll();
        }elseif($role==='VIEWER'){
            $ids=ipdamsMooeViewerSchools($this->db,$this->user);
            $orgs=[];
            if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));
                $orgs=q($this->db,"SELECT $fields FROM organizations o WHERE o.id IN ($marks) AND o.kind='SCHOOL' AND o.active=1 ORDER BY o.name",$ids)->fetchAll();}
        }else{
            $orgs=q($this->db,"SELECT DISTINCT $fields FROM organizations o JOIN user_organization_scopes s ON s.organization_id=o.id
                WHERE s.user_id=? AND o.kind='SCHOOL' ORDER BY o.name",[$this->user['id']])->fetchAll();
        }
        $today=self::todayManila();
        foreach($orgs as &$entry)if($entry['kind']==='SCHOOL'){
            $class=$this->currentSchoolClass((int)$entry['id'],$today);
            $entry['implementation_class']=$class['classification']??null;
            $entry['implementation_effective_from']=$class['effective_from']??null;
            $entry['implementation_class_version_id']=$class['version_id']??0;
        }
        unset($entry);
        $ids=array_column($orgs,'id');$users=[];
        if(in_array($role,['SUPER_ADMIN','DIVISION'],true) && count($ids)>0){
            $placeholders=implode(',',array_fill(0,count($ids),'?'));
            $users=q($this->db,"SELECT DISTINCT u.id,u.full_name,u.email,u.role,u.active FROM users u JOIN user_organization_scopes s ON s.user_id=u.id WHERE s.organization_id IN ($placeholders) AND u.role IN ('DIVISION','SCHOOL') ORDER BY u.role,u.full_name",$ids)->fetchAll();
        }
        return ['organizations'=>$orgs,'users'=>$users,'is_super_admin'=>$role==='SUPER_ADMIN','can_manage_schools'=>in_array($role,['DIVISION','SUPER_ADMIN'],true)];
    }

    /** VAT registration and business classification belong to the supplier.
     *  The six tax profiles are independent. Goods/Services keep the legacy
     *  profile_type encoding while the four special profiles use additive flags.
     *  Legacy supplier.supply_type and default_atc_code columns remain unused. */
    private function supplierProfileTypes(): array {
        return ['GOODS','SERVICES','RENTAL','CONTRACTOR','UTILITIES','OTHER'];
    }
    private function supplierProfileFlagColumn(string $type): string {
        $map=[
            'GOODS'=>'goods_profile_active','SERVICES'=>'services_profile_active',
            'RENTAL'=>'rental_profile_active','CONTRACTOR'=>'contractor_profile_active',
            'UTILITIES'=>'utilities_profile_active','OTHER'=>'other_profile_active'
        ];
        demand(isset($map[$type]),'Invalid supplier tax profile');
        return $map[$type];
    }
    private function supplierAtcSpecialColumn(string $type): ?string {
        return [
            'RENTAL'=>'rental_profile','CONTRACTOR'=>'contractor_profile',
            'UTILITIES'=>'utilities_profile','OTHER'=>'other_profile'
        ][$type]??null;
    }
    private function atcSpecialColumn(string $type): ?string {
        return [
            'RENTAL'=>'rental_applicable','CONTRACTOR'=>'contractor_applicable',
            'UTILITIES'=>'utilities_applicable','OTHER'=>'other_applicable'
        ][$type]??null;
    }
    private function atcSupportsProfile(array $atc,string $type): bool {
        if($type==='GOODS'||$type==='SERVICES'){
            $column=$type==='GOODS'?'goods_applicable':'services_applicable';
            if(array_key_exists($column,$atc) && $atc[$column]!==null)return (int)$atc[$column]===1;
            $legacy=(string)($atc['supply_type']??'BOTH');
            return $legacy==='BOTH'||$legacy===$type;
        }
        $column=$this->atcSpecialColumn($type);
        return $column!==null && (int)($atc[$column]??0)===1;
    }
    private function previousSupplierAtcHasProfile(?array $row,array $atc,string $type): bool {
        if($row===null)return false;
        if($type==='GOODS'||$type==='SERVICES'){
            $legacy=trim((string)($row['profile_type']??''));
            if($legacy!=='')return $legacy==='BOTH'||$legacy===$type;
            // NULL profile_type is a pre-profile assignment. Preserve its original
            // Goods/Services meaning from the legacy ATC supply_type snapshot.
            $supply=(string)($atc['supply_type']??'BOTH');
            return $supply==='BOTH'||$supply===$type;
        }
        $column=$this->supplierAtcSpecialColumn($type);
        return $column!==null && (int)($row[$column]??0)===1;
    }

    private function supplierFields(array $d, ?int $existingId=null): array {
        $name=requiredString($d,'name',200);
        $tax=trim((string)($d['tax_id']??''));
        $address=trim((string)($d['address']??''));
        $zip=strtoupper(trim((string)($d['zip_code']??'')));
        $business=requiredString($d,'business_classification',25);
        $proprietor=trim((string)($d['proprietor_name']??''));
        $vat=requiredString($d,'vat_status',20);
        demand(mbSafeLen($tax)<=40&&mbSafeLen($address)<=2000,'Supplier tax ID or address too long');
        demand($zip===''||preg_match('/^[A-Z0-9 -]{2,16}$/',$zip)===1,'ZIP/postal code must be 2-16 letters or digits');
        demand(in_array($business,['COMMERCIAL','INDIVIDUAL','UNSPECIFIED'],true),'Business classification must be Corporate or Individual');
        demand(in_array($vat,['VAT','NON_VAT','UNKNOWN'],true),'Select VAT registration status');
        demand(mbSafeLen($proprietor)<=180,'Proprietor name is too long');
        if($business==='INDIVIDUAL')demand($proprietor!=='' && preg_match('/[\p{L}]/u',$proprietor)===1,'Individual suppliers require the proprietor’s full legal name');
        else demand($proprietor==='','Proprietor name is only for individual business classification');

        $profileAware=array_key_exists('tax_profiles',$d);
        $profileTypes=$this->supplierProfileTypes();
        $scope=[];$profileFlags=array_fill_keys($profileTypes,0);
        if($profileAware){
            demand(is_array($d['tax_profiles'])&&array_is_list($d['tax_profiles'])&&count($d['tax_profiles'])<=6,'Invalid supplier tax profiles');
            $perFamily=['VAT'=>[],'EWT'=>[],'UNCLASSIFIED'=>[]];
            foreach($d['tax_profiles'] as $profile){
                demand(is_array($profile),'Invalid supplier tax profile');
                $type=strtoupper(trim((string)($profile['profile_type']??$profile['supply_type']??'')));
                demand(in_array($type,$profileTypes,true),'Invalid supplier tax profile type');
                demand(!$profileFlags[$type],'Duplicate '.$type.' profile');
                $profileFlags[$type]=1;
                foreach(['VAT'=>'vat_atc_codes','EWT'=>'ewt_atc_codes'] as $family=>$field){
                    $values=$profile[$field]??[];
                    demand(is_array($values)&&array_is_list($values),'Invalid '.$family.' ATCs in '.$type.' profile');
                    foreach($values as $value){
                        demand(is_string($value),'Invalid ATC assignment');
                        $code=strtoupper(trim($value));
                        demand(preg_match('/^[A-Z0-9_-]{2,24}$/',$code)===1,'Invalid ATC code');
                        $scope[$code][$type]=true;
                        $perFamily[$family][$code]=$family;
                    }
                }
            }
            $legacy=$d['legacy_atc_codes']??[];
            demand(is_array($legacy)&&array_is_list($legacy),'Invalid legacy ATCs');
            foreach($legacy as $raw){
                demand(is_string($raw)&&preg_match('/^[A-Z0-9_-]{2,24}$/',strtoupper(trim($raw)))===1,'Invalid legacy ATC');
                $code=strtoupper(trim($raw));
                demand($existingId!==null&&one($this->db,'SELECT 1 FROM supplier_atcs WHERE supplier_id=? AND atc_code=?',[$existingId,$code])!==null,'Cannot add a new unclassified ATC');
                $perFamily['UNCLASSIFIED'][$code]='UNCLASSIFIED';
            }
            $d['vat_atc_codes']=array_keys($perFamily['VAT']);
            $d['ewt_atc_codes']=array_keys($perFamily['EWT']);
            $d['unclassified_atc_codes']=array_keys($perFamily['UNCLASSIFIED']);
        }

        $split=array_key_exists('vat_atc_codes',$d)||array_key_exists('ewt_atc_codes',$d);
        $families=$split?['VAT'=>$d['vat_atc_codes']??[],'EWT'=>$d['ewt_atc_codes']??[],'UNCLASSIFIED'=>$d['unclassified_atc_codes']??[]]:['ANY'=>$d['atc_codes']??[]];
        $codes=[];$requestedKind=[];
        foreach($families as $kind=>$group){
            demand(is_array($group)&&array_is_list($group),'Invalid '.$kind.' ATC assignments');
            foreach($group as $code){$codes[]=$code;$requestedKind[]=$kind;}
        }
        demand(count($codes)<=80,'Select up to 80 supplier ATC links');

        $selected=[];$codeProfiles=[];$specialProfiles=[];$assignmentAudit=[];
        foreach($codes as $position=>$raw){
            demand(is_string($raw),'Invalid ATC assignment');
            $code=strtoupper(trim($raw));
            demand(preg_match('/^[A-Z0-9_-]{2,24}$/',$code)===1,'Invalid ATC code');
            demand(!isset($selected[$code]),'Duplicate supplier ATC');
            $found=one($this->db,"SELECT code,active,supply_type,goods_applicable,services_applicable,rental_applicable,contractor_applicable,utilities_applicable,other_applicable,COALESCE(NULLIF(business_type,''),'BOTH') AS business_type,COALESCE(NULLIF(tax_kind,''),'UNCLASSIFIED') AS tax_kind FROM tax_atcs WHERE code=?",[$code]);
            demand($found!==null,'Unknown ATC: '.$code);
            $previous=$existingId!==null?one($this->db,'SELECT profile_type,rental_profile,contractor_profile,utilities_profile,other_profile FROM supplier_atcs WHERE supplier_id=? AND atc_code=?',[$existingId,$code]):null;
            $previouslyAssigned=$previous!==null;
            $supportedProfileCount=0;
            foreach($this->supplierProfileTypes() as $candidate){
                if($this->atcSupportsProfile($found,$candidate))$supportedProfileCount++;
            }
            demand($supportedProfileCount===1||$previouslyAssigned,
                'ATC '.$code.' needs a single supplier profile classification before new assignments');
            if(!(bool)$found['active'])demand($previouslyAssigned,'Inactive ATCs cannot be newly assigned');

            $taxKind=(string)$found['tax_kind'];
            demand($requestedKind[$position]==='ANY'||$requestedKind[$position]===$taxKind,'ATC '.$code.' is not in the selected VAT/EWT tax group');
            demand($taxKind!=='UNCLASSIFIED'||$previouslyAssigned,'Classify existing ATC '.$code.' before new supplier assignment');

            $supplierBusiness=$business==='COMMERCIAL'?'CORPORATE':$business;
            $atcBusiness=(string)$found['business_type'];
            demand($atcBusiness==='BOTH'||$supplierBusiness===$atcBusiness||$previouslyAssigned,'ATC '.$code.' does not apply to this business type');

            $assignedTypes=array_keys($scope[$code]??[]);
            if($profileAware && $taxKind!=='UNCLASSIFIED'){
                demand(count($assignedTypes)>0,'ATC assignment requires a supplier tax profile');
                foreach($assignedTypes as $type){
                    $supported=$this->atcSupportsProfile($found,$type);
                    $wasAssigned=$this->previousSupplierAtcHasProfile($previous,$found,$type);
                    demand($supported||$wasAssigned,'ATC '.$code.' is not configured for the '.$type.' profile');
                }
            }

            if($taxKind==='UNCLASSIFIED'){
                $codeProfiles[$code]=$previous['profile_type']??null;
            }else{
                $standard=array_values(array_intersect($assignedTypes,['GOODS','SERVICES']));
                $codeProfiles[$code]=count($standard)===2?'BOTH':($standard[0]??null);
            }
            $specialProfiles[$code]=[];
            foreach(['RENTAL','CONTRACTOR','UTILITIES','OTHER'] as $type){
                $column=$this->supplierAtcSpecialColumn($type);
                $specialProfiles[$code][$column]=$taxKind==='UNCLASSIFIED'
                    ? (int)($previous[$column]??0)
                    : (isset($scope[$code][$type])?1:0);
            }
            $assignmentAudit[$code]=[
                'profile_type'=>$codeProfiles[$code],
                'rental'=>(bool)$specialProfiles[$code]['rental_profile'],
                'contractor'=>(bool)$specialProfiles[$code]['contractor_profile'],
                'utilities'=>(bool)$specialProfiles[$code]['utilities_profile'],
                'other'=>(bool)$specialProfiles[$code]['other_profile']
            ];
            $selected[$code]=true;
        }

        $fields=['name'=>$name,'tax_id'=>$tax?:null,'address'=>$address?:null,'zip_code'=>$zip?:null,
            'business_classification'=>$business,'vat_status'=>$vat,'proprietor_name'=>$proprietor?:null];
        if($profileAware){
            foreach($profileTypes as $type)$fields[$this->supplierProfileFlagColumn($type)]=$profileFlags[$type];
        }
        return [
            'fields'=>$fields,'codes'=>array_keys($selected),'code_profiles'=>$codeProfiles,
            'special_profiles'=>$specialProfiles,'profile_aware'=>$profileAware,'assignment_audit'=>$assignmentAudit
        ];
    }
    private function insertSupplierAtc(int $supplierId,string $code,array $data): void {
        $special=$data['special_profiles'][$code]??[];
        q($this->db,'INSERT INTO supplier_atcs(supplier_id,atc_code,profile_type,rental_profile,contractor_profile,utilities_profile,other_profile) VALUES (?,?,?,?,?,?,?)',[
            $supplierId,$code,$data['code_profiles'][$code]??null,
            (int)($special['rental_profile']??0),(int)($special['contractor_profile']??0),
            (int)($special['utilities_profile']??0),(int)($special['other_profile']??0)
        ]);
    }
    public function addSupplier(array $d): array {
        $org=$this->org($d);$this->sharedSupplierAtcWriter($org);return $this->tx(function()use($org,$d){
            $data=$this->supplierFields($d);$fields=$data['fields'];
            demand(one($this->db,'SELECT id FROM suppliers WHERE LOWER(name)=LOWER(?) LIMIT 1',[$fields['name']])===null,'Supplier with this name already exists');
            $id=$this->insert('suppliers',$fields);
            foreach($data['codes'] as $code)$this->insertSupplierAtc($id,$code,$data);
            $this->audit($org,'CREATED','SUPPLIER',$id,['fields'=>$fields,'atcs'=>$data['assignment_audit'],'scope'=>'SHARED_ALL_OFFICES']);return ['id'=>$id];
        });
    }
    public function updateSupplier(array $d): array {
        $org=$this->org($d);$this->sharedSupplierAtcWriter($org);$id=idOf($d,'supplier_id');
        return $this->tx(function()use($org,$d,$id){
            $old=one($this->db,'SELECT * FROM suppliers WHERE id=? FOR UPDATE',[$id]);
            demand($old!==null,'Supplier not found',404);
            $prior=q($this->db,'SELECT atc_code,profile_type,rental_profile,contractor_profile,utilities_profile,other_profile FROM supplier_atcs WHERE supplier_id=? ORDER BY atc_code FOR UPDATE',[$id])->fetchAll();
            $data=$this->supplierFields($d,$id);$fields=$data['fields'];
            demand(one($this->db,'SELECT id FROM suppliers WHERE LOWER(name)=LOWER(?) AND id<>? LIMIT 1',[$fields['name'],$id])===null,'Another supplier uses this name');
            if($data['profile_aware']){
                q($this->db,'UPDATE suppliers SET name=?,tax_id=?,address=?,zip_code=?,business_classification=?,vat_status=?,proprietor_name=?,goods_profile_active=?,services_profile_active=?,rental_profile_active=?,contractor_profile_active=?,utilities_profile_active=?,other_profile_active=? WHERE id=?',[
                    $fields['name'],$fields['tax_id'],$fields['address'],$fields['zip_code'],$fields['business_classification'],$fields['vat_status'],$fields['proprietor_name'],
                    $fields['goods_profile_active'],$fields['services_profile_active'],$fields['rental_profile_active'],$fields['contractor_profile_active'],
                    $fields['utilities_profile_active'],$fields['other_profile_active'],$id]);
            } else {
                q($this->db,'UPDATE suppliers SET name=?,tax_id=?,address=?,zip_code=?,business_classification=?,vat_status=?,proprietor_name=? WHERE id=?',[
                    $fields['name'],$fields['tax_id'],$fields['address'],$fields['zip_code'],$fields['business_classification'],$fields['vat_status'],$fields['proprietor_name'],$id]);
            }
            q($this->db,'DELETE FROM supplier_atcs WHERE supplier_id=?',[$id]);
            foreach($data['codes'] as $code)$this->insertSupplierAtc($id,$code,$data);
            $this->audit($org,'PROFILE_UPDATED','SUPPLIER',$id,['before'=>$old,'previous_atcs'=>$prior,'after'=>$fields,'new_atcs'=>$data['assignment_audit'],'scope'=>'SHARED_ALL_OFFICES']);
            return ['id'=>$id];
        });
    }
    /** Preserve official text. No arbitrary 180-character description cutoff.
     * The existing release installer can safely add a TEXT column, preserving all
     * legacy rows; the old VARCHAR(180) remains a backward-compatible preview.
     */
    private function atcDescription(array $d): string {
        $value=$d['description']??null;
        demand(is_string($value),'Invalid or missing description');
        $full=trim($value);
        demand($full!=='','ATC description is required');
        // MySQL/MariaDB TEXT holds up to 65,535 bytes (not characters).
        // Enforce its physical capacity rather than an arbitrary short form limit.
        demand(strlen($full)<=65000,'ATC description exceeds database TEXT capacity');
        return $full;
    }
    private function atcLegacyPreview(string $full): string {
        // Unicode-safe first 180 characters for the old compatibility column.
        // The full, authoritative description is always retained in description_full.
        return preg_match('/^.{0,180}/us',$full,$matches)===1 ? $matches[0] : substr($full,0,180);
    }
    /** ATC business applicability is CORPORATE, INDIVIDUAL, or BOTH.
     *  VAT registration remains supplier-specific and is never inferred here. */
    private function atcBusinessType(array $d): string {
        $type=strtoupper(trim((string)($d['business_type']??'BOTH')));
        demand(in_array($type,['CORPORATE','INDIVIDUAL','BOTH'],true),
            'ATC business type must be Corporate, Individual or Both');
        return $type;
    }
    /** The VAT/EWT family is independent of the supplier's VAT registration. */
    private function atcTaxKind(array $d): string {
        $kind=strtoupper(trim((string)($d['tax_kind']??'')));
        demand(in_array($kind,['VAT','EWT'],true),
            'Select a withholding tax type: VAT Withholding or EWT');
        return $kind;
    }
    /** v0.2.34: exactly one supplier-profile category for any new or updated ATC.
     *  Legacy multi-profile records are kept intact until an administrator
     *  explicitly classifies each one; there is no destructive migration. */
    private function atcProfileApplicability(array $d,?array $prior=null): array {
        $types=$this->supplierProfileTypes();
        $selected=strtoupper(trim((string)($d['profile_category']??'')));
        demand(in_array($selected,$types,true),
            'Select exactly one supplier profile category for this ATC');
        $result=[];
        foreach($types as $type){
            $result[strtolower($type).'_applicable']=$type===$selected?1:0;
        }
        return $result;
    }
    private function atcLegacySupplyType(array $profiles): string {
        if((int)$profiles['goods_applicable']===1 && (int)$profiles['services_applicable']===1)return 'BOTH';
        if((int)$profiles['goods_applicable']===1)return 'GOODS';
        // A special-only ATC still needs a value in the historical enum. New
        // v0.2.33 logic ignores this placeholder because explicit flags are set.
        return 'SERVICES';
    }

    public function addAtc(array $d): array {
        $org=$this->org($d);$this->sharedSupplierAtcWriter($org);return $this->tx(function()use($org,$d){
            $code=strtoupper(requiredString($d,'code',24));$description=$this->atcDescription($d);
            $businessType=$this->atcBusinessType($d);$taxKind=$this->atcTaxKind($d);
            $profiles=$this->atcProfileApplicability($d);
            $supply=$this->atcLegacySupplyType($profiles);
            demand(preg_match('/^[A-Z0-9_-]{2,24}$/',$code)===1,'ATC code must use letters, digits, hyphens or underscore');
            demand(one($this->db,'SELECT code FROM tax_atcs WHERE code=?',[$code])===null,'ATC code already exists');
            q($this->db,'INSERT INTO tax_atcs(code,description,description_full,supply_type,business_type,tax_kind,goods_applicable,services_applicable,rental_applicable,contractor_applicable,utilities_applicable,other_applicable) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[
                $code,$this->atcLegacyPreview($description),$description,$supply,$businessType,$taxKind,
                $profiles['goods_applicable'],$profiles['services_applicable'],$profiles['rental_applicable'],
                $profiles['contractor_applicable'],$profiles['utilities_applicable'],$profiles['other_applicable']
            ]);
            $rate=$this->addAtcRate(array_merge($d,['atc_code'=>$code]));
            $this->audit($org,'CREATED','TAX_ATC',0,['code'=>$code,'description'=>$description,'business_type'=>$businessType,'tax_kind'=>$taxKind,'profiles'=>$profiles,'initial_rate_id'=>$rate['id'],'scope'=>'SHARED_ALL_OFFICES']);
            return ['code'=>$code,'rate_version_id'=>$rate['id'],'rate'=>$rate['rate']];
        });
    }
    /** Edit future-facing ATC metadata. Historical rate versions and prior
     *  supplier assignments remain unchanged. */
    public function updateAtc(array $d): array {
        $org=$this->org($d);$this->sharedSupplierAtcWriter($org);return $this->tx(function()use($org,$d){
            $code=strtoupper(requiredString($d,'code',24));
            $description=$this->atcDescription($d);
            $businessType=$this->atcBusinessType($d);$taxKind=$this->atcTaxKind($d);
            $prior=one($this->db,"SELECT code,COALESCE(NULLIF(description_full,''),description) AS description,supply_type,goods_applicable,services_applicable,rental_applicable,contractor_applicable,utilities_applicable,other_applicable,COALESCE(NULLIF(business_type,''),'BOTH') AS business_type,COALESCE(NULLIF(tax_kind,''),'UNCLASSIFIED') AS tax_kind,active FROM tax_atcs WHERE code=? FOR UPDATE",[$code]);
            demand($prior!==null,'ATC not found',404);
            $profiles=$this->atcProfileApplicability($d,$prior);
            if($prior['tax_kind']!==$taxKind){
                demand(one($this->db,'SELECT w.id FROM withholding_components w WHERE w.atc_code=? LIMIT 1',[$code])===null,
                    'This ATC is already used in a voucher; its VAT/EWT tax type cannot be changed');
            }
            // Deliberately keep the historical supply_type snapshot unchanged.
            // Pre-v0.2.31 supplier links with NULL profile_type continue to resolve
            // exactly as before, while new assignments use the explicit flags.
            q($this->db,'UPDATE tax_atcs SET description=?,description_full=?,business_type=?,tax_kind=?,goods_applicable=?,services_applicable=?,rental_applicable=?,contractor_applicable=?,utilities_applicable=?,other_applicable=? WHERE code=?',[
                $this->atcLegacyPreview($description),$description,$businessType,$taxKind,
                $profiles['goods_applicable'],$profiles['services_applicable'],$profiles['rental_applicable'],
                $profiles['contractor_applicable'],$profiles['utilities_applicable'],$profiles['other_applicable'],$code
            ]);
            $this->audit($org,'UPDATED','TAX_ATC',0,['code'=>$code,'before'=>$prior,'after'=>['description'=>$description,'business_type'=>$businessType,'tax_kind'=>$taxKind,'profiles'=>$profiles]]);
            return ['code'=>$code,'description'=>$description,'business_type'=>$businessType,'tax_kind'=>$taxKind,'profiles'=>$profiles];
        });
    }
    public function setAtcStatus(array $d): array {
        $org=$this->org($d);$this->sharedSupplierAtcWriter($org);return $this->tx(function()use($org,$d){
            $code=strtoupper(requiredString($d,'code',24));$active=$d['active']??null;
            demand(is_bool($active),'ATC active flag must be boolean');
            demand(one($this->db,'SELECT code FROM tax_atcs WHERE code=? FOR UPDATE',[$code])!==null,'ATC not found');
            q($this->db,'UPDATE tax_atcs SET active=? WHERE code=?',[$active?1:0,$code]);
            $this->audit($org,'STATUS_CHANGED','TAX_ATC',0,['code'=>$code,'active'=>$active]);return ['code'=>$code,'active'=>$active];
        });
    }
    public function addAtcRate(array $d): array {
        $org=$this->org($d);$this->sharedSupplierAtcWriter($org);return $this->tx(function()use($org,$d){
            $code=strtoupper(requiredString($d,'atc_code',24));
            $atc=one($this->db,'SELECT * FROM tax_atcs WHERE code=? FOR UPDATE',[$code]);
            demand($atc!==null&&(int)$atc['active']===1,'An active ATC code is required');
            $percent=requiredString($d,'rate_percent',10);
            demand(preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,4})?$/',$percent)===1,'Rate must be a percentage with at most 4 decimal places');
            [$whole,$fraction]=array_pad(explode('.',$percent,2),2,'');
            $scaled=(int)$whole*10000+(int)str_pad($fraction,4,'0');
            demand($scaled>0&&$scaled<=1000000,'Withholding rate must be greater than 0% and no more than 100%');
            $rate=sprintf('%d.%06d',intdiv($scaled,1000000),$scaled%1000000);
            $start=requiredString($d,'starts',10);$end=trim((string)($d['ends']??''));
            foreach([$start,$end] as $date){if($date==='')continue;
                demand(preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)===1,'Dates must use YYYY-MM-DD');
                [$yy,$mm,$dd]=array_map('intval',explode('-',$date));demand(checkdate($mm,$dd,$yy),'Invalid calendar date');
            }
            demand($end===''||$end>=$start,'End date must be on/after start date');
            $conflict=one($this->db,"SELECT id FROM tax_rate_versions WHERE atc_code=? AND starts<=COALESCE(?,'9999-12-31') AND (ends IS NULL OR ends>=?) LIMIT 1",[$code,$end?:null,$start]);
            demand($conflict===null,'Rate period overlaps an existing ATC rate; close the earlier period through a reviewed correction');
            $authority=requiredString($d,'authority_reference',255);
            $id=$this->insert('tax_rate_versions',['atc_code'=>$code,'rate'=>$rate,'starts'=>$start,'ends'=>$end?:null,'authority_reference'=>$authority]);
            $this->audit($org,'RATE_CREATED','TAX_ATC_RATE',$id,['code'=>$code,'rate'=>$rate,'starts'=>$start,'ends'=>$end,'authority'=>$authority]);
            return ['id'=>$id,'rate'=>$rate,'atc_code'=>$code];
        });
    }
    public function closeAtcRate(array $d): array {
        $org=$this->org($d);$this->sharedSupplierAtcWriter($org);$id=idOf($d,'rate_version_id');
        return $this->tx(function()use($org,$d,$id){
            $rate=one($this->db,'SELECT * FROM tax_rate_versions WHERE id=? FOR UPDATE',[$id]);
            demand($rate!==null,'ATC rate version not found',404);
            demand($rate['ends']===null,'Only an open-ended rate version can be closed');
            $end=requiredString($d,'ends',10);
            demand(preg_match('/^\d{4}-\d{2}-\d{2}$/',$end)===1,'Effective end date must be YYYY-MM-DD');
            [$year,$month,$day]=array_map('intval',explode('-',$end));
            demand(checkdate($month,$day,$year),'Invalid effective end date');
            demand($end>=$rate['starts'],'End date cannot predate the rate start');
            $used=one($this->db,"SELECT w.id FROM withholding_components w JOIN disbursement_vouchers v ON v.id=w.dv_id WHERE w.rate_version_id=? AND DATE(v.created_at)>? LIMIT 1",[$id,$end]);
            demand($used===null,'Rate already used by a voucher after that date; cannot shorten its effective period');
            q($this->db,'UPDATE tax_rate_versions SET ends=? WHERE id=?',[$end,$id]);
            $this->audit($org,'RATE_CLOSED','TAX_ATC_RATE',$id,['atc'=>$rate['atc_code'],'from'=>$rate['starts'],'ends'=>$end]);
            return ['id'=>$id,'ends'=>$end];
        });
    }
    public function setSupplierStatus(array $d): array {
        $org=$this->org($d);$this->sharedSupplierAtcWriter($org);$id=idOf($d,'supplier_id');
        return $this->tx(function()use($org,$d,$id){
            demand(one($this->db,'SELECT id FROM suppliers WHERE id=? FOR UPDATE',[$id])!==null,'Supplier not found',404);
            $active=$d['active']??null;demand(is_bool($active),'Active flag must be true or false');
            q($this->db,'UPDATE suppliers SET active=? WHERE id=?',[$active?1:0,$id]);
            $this->audit($org,'STATUS_CHANGED','SUPPLIER',$id,['active'=>$active]);return ['id'=>$id,'active'=>$active];
        });
    }
    /**
     * PR-specific unit quick-add, accessible to a staff member who can create
     * procurement documents in the selected office. Units are global shared
     * reference values: never grant the wider master reference-editor role.
     */
    public function addPrUnitQuick(array $d): array {
        $org=$this->org($d);
        $this->operationalWriteAllowed($org);
        $code=strtoupper(requiredString($d,'code',30));
        $name=requiredString($d,'name',80);
        demand((bool)preg_match('/^[A-Z0-9_-]{1,30}$/D',$code),
            'Unit code must contain only letters, numbers, hyphens or underscores');
        demand((bool)preg_match('/[\p{L}\p{N}]/u',$name),'Unit name must contain a letter or number');
        return $this->tx(function()use($org,$code,$name){
            // Lock the existing shared unit namespace before checking for
            // duplicates: office workspaces must not create synonymous codes.
            $rows=q($this->db,'SELECT id,code,name FROM units ORDER BY id FOR UPDATE')->fetchAll();
            foreach($rows as $row){
                demand(strtoupper((string)$row['code'])!==$code,
                    'That unit code already exists. Choose it from the unit list instead.',409);
                $existingName=trim((string)$row['name']);
                $sameName=function_exists('mb_strtolower')
                    ? mb_strtolower($existingName,'UTF-8')===mb_strtolower(trim($name),'UTF-8')
                    : strtolower($existingName)===strtolower(trim($name));
                demand(!$sameName,
                    'That unit name already exists. Select the existing unit instead.',409);
            }
            $id=$this->insert('units',['code'=>$code,'name'=>$name]);
            $this->audit($org,'PR_QUICK_UNIT_CREATED','UNIT',$id,
                ['code'=>$code,'name'=>$name,'scope'=>'SHARED_REFERENCE','source'=>'PR_CATALOG_QUICK_ADD']);
            return ['id'=>$id,'code'=>$code,'name'=>$name];
        });
    }
    public function addUnit(array $d): array {
        $org=$this->org($d);$this->referenceWriter();$code=strtoupper(requiredString($d,'code',30));
        demand((bool)preg_match('/^[A-Z0-9_-]{1,30}$/',$code),'Unit code may contain letters, digits, - and _');
        return $this->tx(function()use($org,$d,$code){
            demand(one($this->db,'SELECT id FROM units WHERE code=?',[$code])===null,'Unit code exists');
            $id=$this->insert('units',['code'=>$code,'name'=>requiredString($d,'name',80)]);
            $this->audit($org,'CREATED','UNIT',$id,['code'=>$code]);return ['id'=>$id];
        });
    }
    public function addItem(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        return $this->tx(function()use($org,$d){
            $category=requiredString($d,'category',32);
            demand(in_array($category,['SUPPLY','SEMI_EXPENDABLE','PPE','TEXTBOOK','SERVICE'],true),'Invalid item classification');
            $subcategory=trim((string)($d['subcategory']??''));
            $division=$this->divisionId($org);
            // Serialize classification changes and item creation for a Division.
            demand(one($this->db,"SELECT id FROM organizations WHERE id=? AND kind='DIVISION' FOR UPDATE",[$division])!==null,
                'Division classification owner is unavailable',409);
            $classes=array_values(array_filter($this->divisionClasses($division),static fn($c)=>
                !empty($c['active']) && (
                    ($c['applies_to']??'SEMI_EXPENDABLE')===$category ||
                    (($c['applies_to']??'')==='OTHER_MOOE' &&
                        in_array($category,$c['procurement_item_types']??[],true) &&
                        in_array($category,['SUPPLY','SERVICE'],true))
                )));
            // Keep both pre-v0.2.67 consumable callers and semi-expendable
            // subcategory forms compatible. Never infer a class across types.
            $chosenCode=trim((string)($d['division_class_code']??($category==='SUPPLY'
                ?($d['school_class_code']??''):$subcategory)));
            if($chosenCode===''&&count($classes)===1 && ($classes[0]['applies_to']??'')===$category)
                $chosenCode=(string)$classes[0]['code'];
            $chosenClass=null;
            foreach($classes as $c)if($c['code']===$chosenCode){$chosenClass=$c;break;}
            demand($chosenClass!==null,
                'Choose an enabled Division-approved classification for this item category before saving',409);
            if($category==='SEMI_EXPENDABLE'){
                demand($subcategory===$chosenCode,
                    'Semi-expendable subcategory must match the chosen Division classification',409);
            }else{
                demand($subcategory==='','Subcategories apply only to semi-expendable items');
            }
            $uacs=(string)($chosenClass['default_uacs_code']??'');
            demand(self::divisionClassUacsAllowed($category,$uacs),
                'The selected Division classification has no eligible default UACS. Ask the Division Accountant to approve one first',409);
            $acc=one($this->db,'SELECT active FROM uacs_accounts WHERE code=?',[$uacs]);
            demand($acc!==null&&(int)$acc['active']===1,
                'The selected Division classification account is not enabled',409);
            $unit=idOf($d,'unit_id');demand(one($this->db,'SELECT id FROM units WHERE id=?',[$unit])!==null,'Choose an existing unit');
            $name=requiredString($d,'name',255);
            // Duplicate within the *visible* scope is confusing and disallowed;
            // separate schools may still have their own similarly named items.
            demand(one($this->db,'SELECT i.id FROM item_catalog i  '
                .'WHERE LOWER(i.name)=LOWER(?) AND i.category=? AND i.unit_id=? '
                .'AND (i.owner_organization_id IS NULL OR i.owner_organization_id=? OR i.owner_organization_id=?) LIMIT 1',
                [$name,$category,$unit,$org,$division])===null,
                'An item with this name, unit and classification is already visible in your catalog');
            $id=$this->insert('item_catalog',['name'=>$name,'category'=>$category,'subcategory'=>$subcategory?:null,'unit_id'=>$unit,'owner_organization_id'=>$org]);
            // One transaction inserts the shared catalog item and a separately
            // scoped, dated, immutable-provenance Division accounting mapping.
            $config=$this->divisionItemUacsConfig($division);
            $mappings=$config['mappings'];
            demand(count($mappings)<5000,'Division UACS mapping capacity reached',409);
            $classVersion=one($this->db,'SELECT id FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',
                [$division,self::DIVISION_CLASS_KEY]);
            $mapping=['item_id'=>$id,'uacs_code'=>$uacs,
                'effective_from'=>self::todayManila(),'classification_code'=>$chosenCode,
                'classification_name'=>(string)$chosenClass['name'], 'classification_type'=>$category,
                'expense_reference_type'=>(string)($chosenClass['applies_to']??$category),
                'classification_version_id'=>(int)($classVersion['id']??0),
                'source'=>'AUTO_DIVISION_CLASS'];
            $mappings[]=$mapping;
            $version=$this->insert('configuration_versions',[
                'organization_id'=>$division,'config_key'=>self::DIVISION_ITEM_UACS_KEY,
                'config_json'=>json_encode(['mappings'=>$mappings],JSON_THROW_ON_ERROR),
                'effective_from'=>self::todayManila(),
                'review_note'=>'New catalog item inherits approved Division class '.$chosenCode]);
            $this->audit($org,'AUTO_DIVISION_CLASS_UACS_MAPPED','ITEM',$id,
                ['division_id'=>$division,'mapping'=>$mapping,'version_id'=>$version]);
            $this->audit($org,'CREATED','ITEM',$id,['category'=>$category,
                'subcategory'=>$subcategory?:null,'division_class_code'=>$chosenCode,'auto_uacs'=>$mapping]);
            return ['id'=>$id,'name'=>$name,'category'=>$category,'unit_id'=>$unit,'unit'=>(string)one($this->db,'SELECT code FROM units WHERE id=?',[$unit])['code'],'catalog_scope'=>$org===$division?'Division catalog':'School catalog','auto_uacs'=>$mapping];
        });
    }
    public function updateItemSubcategory(array $d): array {
        $org=$this->org($d);$id=idOf($d,'item_id');$this->catalogItemMayMaintain($org,$id);
        $subcategory=requiredString($d,'subcategory',80);
        $this->requireDivisionSubcategory($org,$subcategory);
        return $this->tx(function()use($org,$id,$subcategory){
            $item=one($this->db,'SELECT id,category,subcategory FROM item_catalog WHERE id=? FOR UPDATE',[$id]);
            demand($item!==null,'Item not found',404);
            demand($item['category']==='SEMI_EXPENDABLE','Only semi-expendable items can be assigned this subcategory');
            $before=$item['subcategory'];
            if($before!==$subcategory){
                q($this->db,'UPDATE item_catalog SET subcategory=? WHERE id=?',[$subcategory,$id]);
                $this->audit($org,'SUBCATEGORY_UPDATED','ITEM',$id,['before'=>$before,'after'=>$subcategory]);
            }
            return ['id'=>$id,'category'=>'SEMI_EXPENDABLE','subcategory'=>$subcategory];
        });
    }
    public function setItemStatus(array $d): array {
        $org=$this->org($d);$id=idOf($d,'item_id');$this->catalogItemMayMaintain($org,$id);
        return $this->tx(function()use($org,$d,$id){
            demand(one($this->db,'SELECT id FROM item_catalog WHERE id=? FOR UPDATE',[$id])!==null,'Item not found',404);
            $active=$d['active']??null;demand(is_bool($active),'Active flag must be true or false');
            q($this->db,'UPDATE item_catalog SET active=? WHERE id=?',[$active?1:0,$id]);
            $this->audit($org,'STATUS_CHANGED','ITEM',$id,['active'=>$active]);return ['id'=>$id,'active'=>$active];
        });
    }
    public function addFund(array $d): array {
        $org=$this->org($d);
        demand(in_array($this->user['role'],['DIVISION','SUPER_ADMIN'],true),'Only Division administrators can add school funding sources',403);
        return $this->tx(function()use($org,$d){
            $division=$this->scopedDivision($org);$this->divisionForActor($division);
            $target=one($this->db,'SELECT kind FROM organizations WHERE id=?',[$org]);
            demand($target!==null && $target['kind']==='SCHOOL','Select a school workspace before creating a school fund',409);
            $year=$d['fiscal_year']??null;
            demand((is_int($year)||(is_string($year)&&ctype_digit($year)))&&(int)$year>=2000&&(int)$year<=2100,'Invalid fiscal year');
            $code=strtoupper(requiredString($d,'source_code',60));
            demand((bool)preg_match('/^[A-Z0-9_\-]{1,60}$/',$code),'Use letters, digits, hyphens or underscores for fund code');
            $catalog=$this->divisionFundSourceList($division);$master=null;
            foreach($catalog['sources'] as $s)if(($s['code']??'')===$code && ($s['active']??false)===true)$master=$s;
            demand($master!==null,'Division must approve this source in the master fund directory first',409);
            demand(one($this->db,'SELECT id FROM funds WHERE organization_id=? AND fiscal_year=? AND source_code=?',[$org,(int)$year,$code])===null,'Fund code already exists for this school and year');
            $id=$this->insert('funds',['organization_id'=>$org,'fiscal_year'=>(int)$year,'source_code'=>$code,'name'=>$master['name']]);
            $this->audit($org,'CREATED','FUND',$id,['year'=>(int)$year,'source_code'=>$code]);return ['id'=>$id];
        });
    }
    public function dashboard(array $d): array {
        $org=$this->org($d);$counts=[];
        foreach(['purchase_requests'=>'pr','purchase_orders'=>'po','iars'=>'iar','disbursement_vouchers'=>'dv','requisition_issue_slips'=>'ris'] as $table=>$key){$counts[$key]=(int)one($this->db,"SELECT COUNT(*) n FROM $table WHERE organization_id=?",[$org])['n'];}
        if(($this->user['role']??'')==='VIEWER')unset($counts['ris']); // No draft/issued inventory counts on dashboard.
        $counts['pending_tax']=(string)one($this->db,"SELECT COALESCE(SUM(w.amount),0) a FROM withholding_components w JOIN disbursement_vouchers d ON d.id=w.dv_id WHERE d.organization_id=? AND w.disposition='SCHOOL_UTILIZATION' AND NOT EXISTS(SELECT 1 FROM tax_disposition_events e WHERE e.component_id=w.id AND e.state='CONFIRMED_CREDIT')",[$org])['a'];
        return $counts;
    }
    private function prMethod(array $d): string {
        $method=(string)($d['method']??'REVIEW_REQUIRED');
        demand(in_array($method,['REVIEW_REQUIRED','RFQ_CANVASS','DIRECT_DISBURSEMENT'],true),'Select a valid proposed procurement route');
        return $method;
    }
    /** Printed document date is an operator-entered date, not an audit timestamp. */
    private function prDocumentDate(array $d): string {
        $raw=$d['document_date']??null;
        demand(is_string($raw) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$raw)===1,
            'Enter a valid PR document date (YYYY-MM-DD).');
        [$year,$month,$day]=array_map('intval',explode('-',$raw));
        demand($year>=1900 && $year<=2100 && checkdate($month,$day,$year),
            'The PR document date must be a real calendar date between 1900 and 2100.');
        demand($raw<=self::todayManila(),'PR document date cannot be in the future.');
        return $raw;
    }
    /** RFQ issue date: defaults to the linked finalized PR document date.
     * An explicit operator selection overrides that default BEFORE issuance.
     * A finalized RFQ may be corrected only through audited updateRFQ within the
     * original eight-hour window, if there are no dependent transactions.
     */
    private function rfqIssuedDate(array $d,array $pr): string {
        $raw=array_key_exists('issued_at',$d)?$d['issued_at']:($pr['document_date']??null);
        demand(is_string($raw) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$raw)===1,
            'Select a valid RFQ date (YYYY-MM-DD).');
        [$year,$month,$day]=array_map('intval',explode('-',$raw));
        demand($year>=1900 && $year<=2100 && checkdate($month,$day,$year),
            'RFQ date must be a real calendar date between 1900 and 2100.');
        demand($raw<=self::todayManila(),'RFQ date cannot be in the future.');
        demand(!empty($pr['document_date']) && $raw >= (string)$pr['document_date'], 'RFQ date cannot precede the source PR document date.',422);
        return $raw;
    }
    /** Organization-specific PR requesting units, stored as immutable configuration versions. */
    private function requestingUnitCatalog(int $org): array {
        $row=one($this->db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',[$org,'pr_requesting_units']);
        if(!$row)return ['version_id'=>0,'units'=>[],'records'=>[]];
        $raw=json_decode((string)$row['config_json'],true);
        demand(is_array($raw)&&isset($raw['units'])&&is_array($raw['units']),'Requesting unit configuration requires administrator review',500);
        demand(count($raw['units'])<=100,'Invalid requesting unit directory',500);
        $records=[];$names=[];
        foreach($raw['units'] as $i=>$unit){
            // Older configuration versions stored plain names. Convert only when the next change is saved.
            $record=is_string($unit)?['id'=>'legacy-'.hash('sha256',(string)$i.'|'.$unit),'name'=>$unit,'active'=>true]:$unit;
            demand(is_array($record)&&is_string($record['id']??null)&&preg_match('/^[a-zA-Z0-9-]{1,80}$/',$record['id'])
                &&is_string($record['name']??null)&&trim($record['name'])!==''&&mbSafeLen($record['name'])<=160
                &&is_bool($record['active']??null),'Invalid requesting unit directory',500);
            $key=strtolower($record['name']);
            demand(!isset($names[$key]),'Duplicate requesting units require administrator review',500);
            $names[$key]=true;$records[]=$record;
        }
        return ['version_id'=>(int)$row['id'],'units'=>array_values(array_map(
            static fn($record)=>$record['name'],array_filter($records,static fn($record)=>$record['active']))),'records'=>$records];
    }
    public function requestingUnits(array $p): array {
        $org=$this->org($p);
        return ['organization_id'=>$org]+$this->requestingUnitCatalog($org);
    }
    /** Per-record mutations are serialized on the organization lock; never accept a full directory from a browser. */
    public function changeRequestingUnit(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        $action=requiredString($d,'action',15);
        demand(in_array($action,['ADD','RENAME','PROFILE','DEACTIVATE','REACTIVATE'],true),'Invalid requesting unit action');
        $expected=$d['expected_version']??null;
        demand(is_int($expected)&&$expected>=0,'Refresh the requesting unit directory first',409);
        $name='';
        if(in_array($action,['ADD','RENAME'],true)){
            $name=trim(requiredString($d,'name',160));
            demand($name!==''&&!preg_match('/[\\x00-\\x1F\\x7F]/',$name),'Invalid requesting unit name');
        }
        $selected=$action==='ADD'?'':requiredString($d,'record_id',80);
        $reason=$action==='ADD'?'Department added':requiredString($d,'reason',500);
        return $this->tx(function()use($org,$action,$expected,$name,$selected,$reason){
            $locked=q($this->db,'SELECT id FROM organizations WHERE id=? AND active=1 FOR UPDATE',[$org])->fetch();
            demand($locked!==false,'Organization unavailable',404);
            $prior=$this->requestingUnitCatalog($org);
            demand($prior['version_id']===$expected,'Directory was changed by another user. Refresh and retry.',409);
            $records=$prior['records'];$previous='';
            if($action==='ADD'){
                demand(count($records)<100,'Maximum of 100 departments per organization');
                foreach($records as $record)demand(strtolower($record['name'])!==strtolower($name),'Department already exists (including inactive departments)',409);
                $records[]=['id'=>bin2hex(random_bytes(12)),'name'=>$name,'active'=>true];
            }else{
                $index=null;
                foreach($records as $i=>$record)if($record['id']===$selected){$index=$i;break;}
                demand($index!==null,'Department changed. Refresh and retry.',409);
                $previous=$records[$index]['name'];
                if($action==='RENAME'){
                    demand($records[$index]['active'],'Reactivate this department before renaming',409);
                    foreach($records as $i=>$record)if($i!==$index)demand(strtolower($record['name'])!==strtolower($name),'Department name already exists',409);
                    $records[$index]['name']=$name;
                }elseif($action==='DEACTIVATE'){
                    demand($records[$index]['active'],'Department already inactive',409);
                    $records[$index]['active']=false;
                }else{
                    demand(!$records[$index]['active'],'Department already active',409);
                    $records[$index]['active']=true;
                }
            }
            $id=$this->insert('configuration_versions',['organization_id'=>$org,'config_key'=>'pr_requesting_units',
                'config_json'=>json_encode(['units'=>$records],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
                'effective_from'=>date('Y-m-d'),'review_note'=>$reason]);
            $this->audit($org,'PR_REQUESTING_UNIT_'.$action,'CONFIGURATION',$id,
                ['action'=>$action,'previous_version'=>$expected,'record_id'=>$selected,'prior_name'=>$previous,'new_name'=>$name,'reason'=>$reason]);
            return ['organization_id'=>$org,'version_id'=>$id,'units'=>array_values(array_map(
                static fn($record)=>$record['name'],array_filter($records,static fn($record)=>$record['active']))),
                'records'=>$records,'action'=>$action];
        });
    }
    /** Old bulk route explicitly refuses overwrite/delete-all requests. */
    public function saveRequestingUnits(array $d): array {
        $this->org($d);
        demand(false,'Bulk replacement is disabled. Use individual department actions.',409);
    }
    private function validatePrRequestingUnit(int $org,array $d): string {
        $unit=requiredString($d,'requesting_unit',160);
        $catalog=$this->requestingUnitCatalog($org);
        demand(in_array($unit,$catalog['units'],true),
            'Choose an active configured requesting unit; refresh the department list',409);
        return $unit;
    }
    // Staff records are scoped to the *selected* organization. Existing employees,
    // employment episodes and BAC terms are retained rather than bulk-replaced.
    // Documentary payment identity only; no new employee table columns. Stored
    // per organization + employee, with append-only versions and scoped reads.
    private const EMPLOYEE_DV_IDENTITY_PREFIX='EMPLOYEE_DV_IDENTITY_V1_';
    private function employeeDvIdentityKey(int $employeeId): string {
        return self::EMPLOYEE_DV_IDENTITY_PREFIX.$employeeId;
    }
    private function employeeDvIdentityVersion(int $org,int $employeeId): array {
        $row=one($this->db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',
            [$org,$this->employeeDvIdentityKey($employeeId)]);
        if($row===null)return ['version_id'=>0,'employee_no'=>'','tin'=>'','address'=>''];
        $d=json_decode((string)$row['config_json'],true,32,JSON_THROW_ON_ERROR);
        demand(is_array($d),'Saved employee documentary payment details require technical review.',409);
        return ['version_id'=>(int)$row['id'],
            'employee_no'=>(string)($d['employee_no']??''),
            'tin'=>(string)($d['tin']??''),
            'address'=>(string)($d['address']??'')];
    }
    private function employeeDvIdentityInput(array $d): array {
        $v=[];
        foreach(['employee_no'=>50,'tin'=>40,'address'=>500] as $field=>$max){
            demand(!isset($d[$field])||is_string($d[$field]),'Invalid employee payment detail: '.$field,400);
            $v[$field]=trim((string)($d[$field]??''));
            demand(mbSafeLen($v[$field])<=$max,'Employee '.$field.' is too long.',400);
            demand(!preg_match('/[\x00-\x1F\x7F]/',$v[$field]),'Employee payment details contain unsupported characters.',400);
        }
        demand($v['tin']===''||preg_match('/^[0-9][0-9 -]*[0-9]$/D',$v['tin'])===1,
            'Enter a valid numeric TIN (spaces and hyphens allowed), or leave it blank.',400);
        return $v;
    }
    private function saveEmployeeDvIdentity(int $org,int $employeeId,array $fields): int {
        $version=$this->insert('configuration_versions',[
            'organization_id'=>$org,'config_key'=>$this->employeeDvIdentityKey($employeeId),
            'config_json'=>json_encode($fields,JSON_THROW_ON_ERROR),
            'effective_from'=>self::todayManila(),'review_note'=>'Employee DV payment details updated'
        ]);
        // Never put a TIN or personal address into general-purpose audit JSON.
        $this->audit($org,'UPDATED','EMPLOYEE_DV_IDENTITY',$employeeId,
            ['configuration_version_id'=>$version,'fields'=>array_keys($fields)]);
        return $version;
    }
    /** Profile is readable only by users authorized to maintain the selected organization. */
    public function employeeDvIdentity(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        $employee=idOf($d,'employee_id');
        demand(one($this->db,'SELECT id FROM employee_assignments WHERE organization_id=? AND employee_id=? LIMIT 1',[$org,$employee])!==null,
            'Employee not assigned to this school or Division Office.',404);
        return ['employee_id'=>$employee]+$this->employeeDvIdentityVersion($org,$employee);
    }
    /** Freeze payee documentary details upon real DV finalization, not during printing. */
    private function dvPayeeIdentityAtIssuance(int $org,array $dv,string $date): array {
        if(($dv['dv_type']??'')==='TRAVEL_REIMBURSEMENT'){
            $x=one($this->db,'SELECT l.employee_id,e.full_name FROM travel_dv_claim_links l JOIN employees e ON e.id=l.employee_id WHERE l.organization_id=? AND l.dv_id=? ORDER BY l.id LIMIT 1',[$org,$dv['id']]);
            if($x===null){
                // An employee-paid PR/PO claim may legitimately be the ONLY
                // source when the original travel DV was already finalized.
                // Resolve identity from its existing, scoped, immutable DV
                // reservations. Never invent a travel link or change history.
                $sources=EmployeeClaims::dvSources($this->db,$org,(int)$dv['id']);
                demand(count($sources)>0,'Employee DV has no linked reimbursement sources',409);
                $employeeId=0;$recordedName='';
                foreach($sources as $row){
                    $claim=EmployeeClaims::claim($this->db,$org,(int)$row['claim_id']);
                    $id=(int)($claim['employee_id']??0);
                    $name=(string)($claim['employee_name']??'');
                    demand($id>0 && $name!=='' && ($employeeId===0 ||
                        ($employeeId===$id && $recordedName===$name)),
                        'Employee DV sources identify different employees; finance review required',409);
                    $employeeId=$id;$recordedName=$name;
                }
                $x=one($this->db,'SELECT id AS employee_id,full_name FROM employees WHERE id=?',[$employeeId]);
                demand($x!==null && (string)$x['full_name']===$recordedName,
                    'Employee identity differs from the locked PR/PO reimbursement source',409);
            }
            demand((string)$x['full_name']===(string)$dv['payee'],
                'Employee DV payee differs from its locked original claim',409);
            $profile=$this->employeeDvIdentityVersion($org,(int)$x['employee_id']);
            return ['kind'=>'EMPLOYEE','source_id'=>(int)$x['employee_id'],'name'=>(string)$x['full_name'],
                'tin'=>$profile['tin'],'employee_no'=>$profile['employee_no'],'address'=>$profile['address'],
                'profile_version'=>$profile['version_id'],
                'review_required'=>$profile['address']===''||($profile['tin']===''&&$profile['employee_no']==='')];
        }
        $type=(string)($dv['dv_type']??'');
        if($type==='REGULAR' && (int)($dv['supplier_id']??0)>0){
            $supplier=one($this->db,'SELECT id,name,tax_id,address FROM suppliers WHERE id=?',[(int)$dv['supplier_id']]);
            demand($supplier!==null,'DV supplier identity cannot be confirmed for issuance.',409);
            demand((string)$supplier['name']===(string)($dv['business_name_snapshot']??''),
                'Original supplier name has changed since the DV draft. Review documentary identity before issuance.',409);
            return ['kind'=>'SUPPLIER','source_id'=>(int)$supplier['id'],'name'=>(string)$supplier['name'],
                'tin'=>(string)($supplier['tax_id']??''),'employee_no'=>'','address'=>(string)($supplier['address']??''),
                'review_required'=>trim((string)($supplier['tax_id']??''))==='' || trim((string)($supplier['address']??''))===''];
        }
        if($type==='REIMBURSEMENT'){
            // The verified original payer has been saved in the DV payee field.
            // Never guess by partial names or select one of several matching employees.
            $matches=q($this->db,"SELECT DISTINCT e.id,e.full_name FROM employee_assignments a
                JOIN employees e ON e.id=a.employee_id
                WHERE a.organization_id=? AND BINARY e.full_name=BINARY ?
                AND a.effective_from<=? AND (a.effective_to IS NULL OR a.effective_to>?)",
                [$org,(string)$dv['payee'],$date,$date])->fetchAll();
            if(count($matches)===1){
                $person=$matches[0];$profile=$this->employeeDvIdentityVersion($org,(int)$person['id']);
                return ['kind'=>'EMPLOYEE','source_id'=>(int)$person['id'],'name'=>(string)$person['full_name'],
                    'tin'=>$profile['tin'],'employee_no'=>$profile['employee_no'],
                    'address'=>$profile['address'],'profile_version'=>$profile['version_id'],
                    'review_required'=>$profile['address']===''||($profile['tin']===''&&$profile['employee_no']==='')];
            }
            return ['kind'=>'UNRESOLVED_EMPLOYEE','name'=>(string)$dv['payee'],
                'tin'=>'','employee_no'=>'','address'=>'','review_required'=>true];
        }
        // DIRECT/manual payees need explicit documentary identity evidence; do
        // not attribute the identity of a similarly named supplier/employee.
        return ['kind'=>'UNVERIFIED','name'=>(string)$dv['payee'],
            'tin'=>'','employee_no'=>'','address'=>'','review_required'=>true];
    }
    private const DV_SIGNATORIES_KEY='DV_SECTION_SIGNATORIES_V1';
    private const ORG_HEAD_KEY='ORG_OFFICE_HEAD_V1';
    private const ORG_CUSTODIAN_KEY='ORG_SUPPLY_PROPERTY_CUSTODIAN_V1';
    private function staffCurrent(array $assignment,string $today): bool {
        return (string)$assignment['effective_from']<=$today &&
            ($assignment['effective_to']===null || (string)$assignment['effective_to']>$today);
    }
    private function staffRows(int $org): array {
        $today=self::todayManila();
        $rows=q($this->db,'SELECT a.id AS assignment_id,a.employee_id,a.organization_id,a.designation,a.effective_from,a.effective_to,e.full_name,e.position_title FROM employee_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.organization_id=? ORDER BY a.id DESC',[$org])->fetchAll();
        $seen=[];$out=[];
        foreach($rows as $row){
            $eid=(int)$row['employee_id'];
            if(isset($seen[$eid]))continue;  // Last employment episode is authoritative; history remains in DB.
            $seen[$eid]=true;
            $row['active']=$this->staffCurrent($row,$today);
            $out[]=$row;
        }
        usort($out,static fn($a,$b)=>strnatcasecmp((string)$a['full_name'],(string)$b['full_name']));
        return $out;
    }
    private function staffActive(int $org,int $employeeId): array {
        $row=one($this->db,'SELECT a.id AS assignment_id,a.employee_id,a.designation,a.effective_from,a.effective_to,e.full_name,e.position_title FROM employee_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.organization_id=? AND a.employee_id=? ORDER BY a.id DESC LIMIT 1',[$org,$employeeId]);
        demand($row!==null && $this->staffCurrent($row,self::todayManila()),'Choose an active employee assigned to this school or Division Office',422);
        return $row;
    }
    private function schoolHeadVersion(int $org): array {
        $row=one($this->db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',[$org,self::ORG_HEAD_KEY]);
        if($row===null)return ['version_id'=>0,'employee_id'=>null];
        $value=json_decode((string)$row['config_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($value)&&array_key_exists('employee_id',$value),'Invalid office-head reference',500);
        return ['version_id'=>(int)$row['id'],'employee_id'=>$value['employee_id']===null?null:(int)$value['employee_id']];
    }
    /** School-specific, append-only documentary signatories per Appendix 32 part. */
    private function dvSignatoriesVersion(int $org): array {
        $row=one($this->db,'SELECT id,config_json,effective_from FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',[$org,self::DV_SIGNATORIES_KEY]);
        if($row===null)return ['version_id'=>0,'effective_from'=>null,'part_a'=>null,'part_c'=>null,'part_d'=>null];
        $value=json_decode((string)$row['config_json'],true,32,JSON_THROW_ON_ERROR);
        demand(is_array($value),'DV signatory configuration is invalid and requires review.',409);
        $out=['version_id'=>(int)$row['id'],'effective_from'=>(string)$row['effective_from']];
        foreach(['part_a','part_c','part_d'] as $part){
            demand(array_key_exists($part,$value),'Incomplete DV section-signatory configuration.',409);
            $id=$value[$part];
            demand($id===null || (is_int($id)&&$id>0),'Invalid DV section signatory reference.',409);
            $out[$part]=$id;
        }
        return $out;
    }
    /** Only the active school directory can be selected. A name on a print is NOT an electronic certification. */
    public function saveDvSectionSignatories(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        return $this->financialTx('staff.dv.signatories.save',$d,function()use($d,$org){
            $meta=one($this->db,"SELECT id,kind FROM organizations WHERE id=? AND active=1 FOR UPDATE",[$org]);
            demand($meta!==null && $meta['kind']==='SCHOOL','School workspace required for DV appointments.',403);
            $previous=$this->dvSignatoriesVersion($org);
            demand(is_numeric($d['expected_version']??null) && (int)$d['expected_version']===$previous['version_id'],
                'DV signatories have changed. Refresh the directory and review them.',409);
            $assignments=[];
            foreach(['part_a','part_c','part_d'] as $part){
                demand(array_key_exists($part,$d),'Specify the person for each DV section (or leave unassigned).',422);
                $raw=$d[$part];
                $id=($raw===null||$raw==='')?null:idOf($d,$part);
                if($id!==null)$this->staffActive($org,$id);
                $assignments[$part]=$id;
            }
            demand($assignments!==array_intersect_key($previous,$assignments),'No change in DV signatories.',422);
            $effective=self::todayManila();
            $version=$this->insert('configuration_versions',['organization_id'=>$org,
                'config_key'=>self::DV_SIGNATORIES_KEY,'config_json'=>json_encode($assignments,JSON_THROW_ON_ERROR),
                'effective_from'=>$effective,'review_note'=>'Appendix 32 documentary signatory assignments (Parts A, C and D)']);
            $this->audit($org,'UPDATED','DV_SECTION_SIGNATORIES',$version,[
                'part_a'=>$assignments['part_a'],'part_c'=>$assignments['part_c'],'part_d'=>$assignments['part_d'],
                'previous_part_a'=>$previous['part_a'],'previous_part_c'=>$previous['part_c'],'previous_part_d'=>$previous['part_d'],
                'effective_from'=>$effective,'previous_version_id'=>$previous['version_id']]);
            return ['version_id'=>$version,'effective_from'=>$effective]+$assignments;
        });
    }
    /** Snapshotted at DV number issuance: later appointments may not rewrite that printed voucher. */
    private function dvSignatoriesAtIssuance(int $org,string $date): array {
        $row=one($this->db,"SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? AND effective_from<=? ORDER BY effective_from DESC,id DESC LIMIT 1",
            [$org,self::DV_SIGNATORIES_KEY,$date]);
        $names=[];$config=$row?json_decode((string)$row['config_json'],true,32,JSON_THROW_ON_ERROR):[];
        foreach(['part_a','part_c','part_d'] as $part){
            $id=is_array($config)?($config[$part]??null):null;
            $person=null;
            if(is_int($id)&&$id>0){
                $rows=q($this->db,"SELECT e.full_name,e.position_title,a.designation FROM employee_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.organization_id=? AND a.employee_id=? AND a.effective_from<=? AND (a.effective_to IS NULL OR a.effective_to>?) ORDER BY a.effective_from DESC,a.id DESC LIMIT 2",[$org,$id,$date,$date])->fetchAll();
                if(count($rows)===1 && trim((string)$rows[0]['full_name'])!==''){
                    $p=$rows[0];$person=['employee_id'=>$id,'name'=>(string)$p['full_name'],
                        'designation'=>trim((string)($p['designation']?:$p['position_title']?:''))];
                }
            }
            $names[$part]=$person;
        }
        return ['version_id'=>$row?(int)$row['id']:0,'parts'=>$names];
    }
    /** Custodian appointment is separate from BAC membership and Head of Office. */
    private function custodianVersion(int $org): array {
        $row=one($this->db,'SELECT id,config_json,effective_from FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',[$org,self::ORG_CUSTODIAN_KEY]);
        if(!$row)return ['version_id'=>0,'employee_id'=>null,'effective_from'=>null];
        $value=json_decode((string)$row['config_json'],true,32,JSON_THROW_ON_ERROR);
        demand(is_array($value)&&array_key_exists('employee_id',$value),'Custodian configuration requires technical review',409);
        return ['version_id'=>(int)$row['id'],'employee_id'=>$value['employee_id']===null?null:(int)$value['employee_id'],
            'effective_from'=>(string)$row['effective_from']];
    }
    /** No bulk replacement or implied electronic appointment approval. */
    public function saveSupplyCustodian(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        return $this->tx(function()use($org,$d){
            $meta=one($this->db,'SELECT id,kind FROM organizations WHERE id=? AND active=1 FOR UPDATE',[$org]);
            demand($meta!==null && in_array($meta['kind'],['SCHOOL','DIVISION'],true),'School/Division workspace required',403);
            $old=$this->custodianVersion($org);
            demand(is_numeric($d['expected_version']??null) && (int)$d['expected_version']===$old['version_id'],
                'Custodian assignment changed in another session. Refresh the employee directory.',409);
            $raw=$d['employee_id']??null;
            $employee=($raw===null || $raw==='')?null:idOf($d,'employee_id');
            $effective=self::transactionDocumentDate(requiredString($d,'effective_from',10),'Custodian appointment effective date');
            $reason=trim((string)($d['reason']??''));
            demand(mbSafeLen($reason)<=500 && !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$reason),'Invalid appointment note',422);
            $assignmentGap=false;$recordedStaffStart=null;
            if($employee!==null){
                // Staff may have been entered into IPDAMS after their actual historical appointment.
                // Never change employee_assignments to manufacture earlier employment.
                $staff=$this->staffActive($org,$employee);
                $recordedStaffStart=(string)$staff['effective_from'];
                // A reactivated employee can have an older valid assignment episode.
                $dateCovered=one($this->db,'SELECT id FROM employee_assignments WHERE organization_id=? AND employee_id=? AND effective_from<=? AND (effective_to IS NULL OR effective_to>?) LIMIT 1',
                    [$org,$employee,$effective,$effective]);
                $assignmentGap=$dateCovered===null;
                if($assignmentGap){
                    demand(($d['historical_appointment_attested']??false)===true,
                        'Confirm that the earlier custodian appointment is supported by an official record',422);
                    demand(mbSafeLen($reason)>=20,
                        'For an appointment earlier than the recorded employee assignment, enter the appointment memorandum or other supporting record (at least 20 characters)',422);
                }
            }
            demand($employee!==$old['employee_id']||$effective!==$old['effective_from'],
                'No change to the custodian appointment',422);
            if($effective<self::todayManila())demand(mbSafeLen($reason)>=10,
                'Explain the documented historical effective date (at least 10 characters)',422);
            if($reason==='')$reason='Supply/Property Custodian assignment recorded';
            $id=$this->insert('configuration_versions',['organization_id'=>$org,'config_key'=>self::ORG_CUSTODIAN_KEY,
                'config_json'=>json_encode(['employee_id'=>$employee,
                    'historical_appointment_attested'=>$assignmentGap,
                    'recorded_staff_start_at_entry'=>$assignmentGap?$recordedStaffStart:null,
                    'supersedes_version_id'=>$old['version_id']],JSON_THROW_ON_ERROR),
                'effective_from'=>$effective,'review_note'=>$reason]);
            $this->audit($org,'UPDATED','SUPPLY_PROPERTY_CUSTODIAN',$id,[
                'employee_id'=>$employee,'previous_employee_id'=>$old['employee_id'],
                'effective_from'=>$effective,'previous_version'=>$old['version_id'],'reason'=>$reason,
                'historical_appointment_attested'=>$assignmentGap,
                'recorded_staff_start_at_entry'=>$assignmentGap?$recordedStaffStart:null]);
            return ['version_id'=>$id,'employee_id'=>$employee,'effective_from'=>$effective];
        });
    }
    /** Appendix 64: every SCHOOL has an explicitly assigned Division MOOE in-charge.
     * Versions are append-only; a later officer never changes the identities saved on
     * earlier signed/posted reports. This endpoint does not post an RSMI. */
    private const SCHOOL_MOOE_KEY='SCHOOL_DIVISION_MOOE_POSTING_OFFICER_V1';
    private function schoolMooeVersions(int $schoolId,string $asOf): array {
        $entries=q($this->db,'SELECT id,effective_from,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY effective_from DESC,id DESC LIMIT 50',
            [$schoolId,self::SCHOOL_MOOE_KEY])->fetchAll();
        $history=[];$current=null;$latestId=0;$latestDate=null;
        foreach($entries as $i=>$entry){
            $raw=json_decode((string)$entry['config_json'],true,32,JSON_THROW_ON_ERROR);
            demand(is_array($raw) && isset($raw['division_id'],$raw['employee_id'],$raw['name'],$raw['designation']),
                'A historic school MOOE assignment needs administrator review',409);
            $row=['version_id'=>(int)$entry['id'],'effective_from'=>(string)$entry['effective_from'],
                'employee_id'=>(int)$raw['employee_id'],'division_id'=>(int)$raw['division_id'],
                'name'=>(string)$raw['name'],'designation'=>(string)$raw['designation']];
            if($i===0){$latestId=$row['version_id'];$latestDate=$row['effective_from'];}
            if($current===null && $row['effective_from']<=$asOf)$current=$row;
            $history[]=$row;
        }
        return ['current'=>$current,'latest_version_id'=>$latestId,'latest_effective_from'=>$latestDate,'history'=>$history];
    }
    /** A school sees only its assigned officer; the Division sees its own schools and employee choices. */
    public function divisionMooeAssignments(array $p): array {
        $org=$this->org($p);
        $meta=one($this->db,'SELECT id,name,kind,parent_division_id FROM organizations WHERE id=? AND active=1',[$org]);
        demand($meta!==null,'Organization not found',404);
        $division=(int)($meta['kind']==='DIVISION'?$org:($meta['parent_division_id']??0));
        demand($division>0,'The school must be attached to an active Division',409);
        $canManage=in_array((string)$this->user['role'],['DIVISION','SUPER_ADMIN'],true) && $meta['kind']==='DIVISION';
        $schools=$meta['kind']==='SCHOOL'?[['id'=>$org,'name'=>$meta['name']]]:
            q($this->db,"SELECT id,name FROM organizations WHERE parent_division_id=? AND kind='SCHOOL' AND active=1 ORDER BY name,id LIMIT 300",[$division])->fetchAll();
        // A read-only print lookup may resolve the documented Division MOOE
        // in-charge as of the actual ICS issuance date. No future assignment
        // is allowed to appear on an older Appendix 64 report.
        $asOf=self::todayManila();
        if(isset($p['as_of']) && $p['as_of']!==''){
            demand(is_string($p['as_of']), 'School MOOE lookup date must be a string.',422);
            $asOf=self::transactionDocumentDate($p['as_of'],'School MOOE lookup date');
        }
        $rows=[];
        foreach($schools as $school){
            $id=(int)$school['id'];$rows[]=['id'=>$id,'name'=>(string)$school['name']]+
                $this->schoolMooeVersions($id,$asOf);
        }
        $staff=$canManage?$this->staffRows($division):[];
        $viewerAccounts=[];
        if($canManage){
            $accounts=q($this->db,"SELECT c.config_json,u.id,u.email,u.active FROM configuration_versions c
                JOIN users u ON c.config_key=CONCAT('MOOE_VIEWER_USER_V1_',u.id)
                WHERE c.organization_id=? AND u.role='VIEWER' ORDER BY u.id",[$division])->fetchAll();
            foreach($accounts as $account){
                $bound=json_decode((string)$account['config_json'],true);
                $viewerAccounts[]=['user_id'=>(int)$account['id'],'email'=>$account['email'],
                    'employee_id'=>(int)($bound['employee_id']??0),'active'=>(bool)$account['active']];
            }
        }
        return ['organization_id'=>$org,'division_id'=>$division,'as_of'=>$asOf,'can_manage'=>$canManage,
            'viewer_accounts'=>$viewerAccounts,'schools'=>$rows,'division_employees'=>array_values(array_map(static function($e){
                return ['employee_id'=>(int)$e['employee_id'],'full_name'=>(string)$e['full_name'],
                    'designation'=>(string)($e['designation']?:$e['position_title']),
                    'active'=>(bool)$e['active'],'effective_from'=>(string)$e['effective_from']];
            },$staff))];
    }
    /** Only Division authority can assign, never school operational users. */
    public function saveDivisionMooeAssignment(array $d): array {
        $division=$this->org($d);$this->operationalWriteAllowed($division);
        demand(in_array((string)$this->user['role'],['DIVISION','SUPER_ADMIN'],true),
            'Only Division personnel can assign school MOOE in-charge officers.',403);
        $school=idOf($d,'school_id');$employee=idOf($d,'employee_id');
        $effective=self::transactionDocumentDate(requiredString($d,'effective_from',10),'MOOE assignment effectivity');
        $expected=$d['expected_version']??null;
        demand(is_numeric($expected) && (int)$expected>=0,'Refresh assignments before saving.',422);
        return $this->financialTx('division.mooe.assignment.save',$d,function()use($d,$division,$school,$employee,$effective,$expected){
            $drow=one($this->db,"SELECT id FROM organizations WHERE id=? AND kind='DIVISION' AND active=1 FOR UPDATE",[$division]);
            demand($drow!==null,'Select a Division workspace to manage school MOOE assignments.',403);
            $srow=one($this->db,"SELECT id,name FROM organizations WHERE id=? AND parent_division_id=? AND kind='SCHOOL' AND active=1 FOR UPDATE",[$school,$division]);
            demand($srow!==null,'Choose a school belonging to the selected Division.',403);
            $last=one($this->db,'SELECT id,effective_from,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY effective_from DESC,id DESC LIMIT 1',
                [$school,self::SCHOOL_MOOE_KEY]);
            demand(($last?(int)$last['id']:0)===(int)$expected,'The assignment changed elsewhere. Refresh before saving.',409);
            demand($last===null || $effective>(string)$last['effective_from'],
                'New assignment effectivity must follow the preceding assignment. Earlier assignments remain preserved.',422);
            // Current Division employment is required, but a genuine earlier appointment
            // may predate entry into IPDAMS. Document it without rewriting staff history.
            $staff=$this->staffActive($division,$employee);
            $covered=one($this->db,"SELECT a.id,a.designation,a.effective_from,a.effective_to,e.full_name,e.position_title
                FROM employee_assignments a JOIN employees e ON e.id=a.employee_id
                WHERE a.organization_id=? AND a.employee_id=? AND a.effective_from<=?
                  AND (a.effective_to IS NULL OR a.effective_to>?)
                ORDER BY a.effective_from DESC,a.id DESC LIMIT 1 FOR UPDATE",
                [$division,$employee,$effective,$effective]);
            $gap=$covered===null;
            $reference=trim((string)($d['appointment_reference']??''));
            if($gap){
                demand(($d['historical_appointment_attested']??false)===true,
                    'Confirm that the earlier MOOE assignment is supported by an official Division document.',422);
                demand(mbSafeLen($reference)>=15 && mbSafeLen($reference)<=300,
                    'Enter the official Division assignment memorandum/reference (15–300 characters).',422);
            }
            $person=$covered??$staff;
            $name=trim((string)$person['full_name']);$designation=trim((string)($person['designation']?:$person['position_title']));
            demand($name!=='' && $designation!=='','Officer name and designation must be verified.',422);
            if($last!==null){
                $prior=json_decode((string)$last['config_json'],true,32,JSON_THROW_ON_ERROR);
                demand((int)($prior['employee_id']??0)!==$employee,
                    'This employee is already the assigned officer; no change is needed.',422);
            }
            $data=['division_id'=>$division,'employee_id'=>$employee,'employee_assignment_id'=>(int)$staff['assignment_id'],
                'name'=>$name,'designation'=>$designation,'previous_version_id'=>$last?(int)$last['id']:0,
                'historical_appointment_attested'=>$gap,'official_reference'=>$gap?$reference:null,
                'recorded_staff_start_at_entry'=>$gap?(string)$staff['effective_from']:null];
            $id=$this->insert('configuration_versions',['organization_id'=>$school,'config_key'=>self::SCHOOL_MOOE_KEY,
                'config_json'=>json_encode($data,JSON_THROW_ON_ERROR),'effective_from'=>$effective,
                'review_note'=>'School Division MOOE accounting in-charge appointment']);
            $this->audit($school,'UPDATED','MOOE_ASSIGNMENT',$id,[
                'division_id'=>$division,'employee_id'=>$employee,'previous_version'=>$data['previous_version_id'],
                'effective_from'=>$effective,'historical_appointment_attested'=>$gap]);
            return ['version_id'=>$id,'school_id'=>$school,'effective_from'=>$effective,'employee_id'=>$employee,
                'name'=>$name,'designation'=>$designation];
        });
    }
    /** Read-only employee and official-designation directory, never shared across workspaces. */
    public function staffDirectory(array $p): array {
        $org=$this->org($p);
        $meta=one($this->db,'SELECT id,name,kind FROM organizations WHERE id=? AND active=1',[$org]);
        demand($meta!==null,'Organization not found',404);
        $today=self::todayManila();
        $terms=q($this->db,'SELECT id,starts,ends FROM bac_terms WHERE organization_id=? AND starts<=? AND (ends IS NULL OR ends>?) ORDER BY starts DESC,id DESC',[$org,$today,$today])->fetchAll();
        demand(count($terms)<=1,'Multiple current BAC terms need administrator review before editing',409);
        $term=$terms[0]??null;
        $bac=['term_id'=>$term===null?0:(int)$term['id'],'starts'=>$term['starts']??null,'chair'=>null,'cochair'=>null,'members'=>[]];
        if($term!==null){
            foreach(q($this->db,'SELECT employee_id,role_title FROM bac_members WHERE term_id=? ORDER BY employee_id',[$term['id']])->fetchAll() as $member){
                $eid=(int)$member['employee_id'];
                if($member['role_title']==='CHAIR')$bac['chair']=$eid;
                elseif($member['role_title']==='COCHAIR')$bac['cochair']=$eid;
                elseif($member['role_title']==='MEMBER')$bac['members'][]=$eid;
                else demand(false,'Existing BAC role needs administrator review',409);
            }
        }
        return ['organization'=>$meta,'employees'=>$this->staffRows($org),'head'=>$this->schoolHeadVersion($org),'dv_signatories'=>$this->dvSignatoriesVersion($org),'custodian'=>$this->custodianVersion($org),'bac'=>$bac];
    }
    /** Single-person change only: NEVER delete the employee or bulk-replace staff. */
    public function changeStaffEmployee(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        $action=requiredString($d,'action',20);
        demand(in_array($action,['ADD','EDIT','PROFILE','RENAME','DEACTIVATE','REACTIVATE'],true),'Unsupported employee action');
        return $this->financialTx('staff.employee.change',$d,function()use($org,$d,$action){
            $meta=one($this->db,'SELECT id FROM organizations WHERE id=? AND active=1 FOR UPDATE',[$org]);
            demand($meta!==null,'Organization unavailable',404);
            $today=self::todayManila();
            if($action==='ADD'){
                $name=requiredString($d,'full_name',160);
                $position=requiredString($d,'position_title',160);
                $designation=trim((string)($d['designation']??$position));
                if($designation==='')$designation=$position;
                demand(mbSafeLen($designation)<=160,'Designation too long');
                foreach($this->staffRows($org) as $member)
                    demand(!$member['active'] || strcasecmp((string)$member['full_name'],$name)!==0,'Active employee with this name already exists; review the staff directory first',409);
                $fields=$this->employeeDvIdentityInput($d);
                $employee=$this->insert('employees',['full_name'=>$name,'position_title'=>$position]);
                $assignment=$this->insert('employee_assignments',['employee_id'=>$employee,'organization_id'=>$org,'designation'=>$designation,'effective_from'=>$today]);
                if(implode('',array_values($fields))!=='')$this->saveEmployeeDvIdentity($org,$employee,$fields);
                $this->audit($org,'ADDED','EMPLOYEE',$employee,['assignment_id'=>$assignment]);
                return ['id'=>$employee,'assignment_id'=>$assignment,'active'=>true];
            }
            $employee=idOf($d,'employee_id');
            $row=one($this->db,'SELECT a.id AS assignment_id,a.employee_id,a.designation,a.effective_from,a.effective_to,e.full_name,e.position_title FROM employee_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.organization_id=? AND a.employee_id=? ORDER BY a.id DESC LIMIT 1 FOR UPDATE',[$org,$employee]);
            demand($row!==null,'Employee not assigned to this workspace',404);
            $active=$this->staffCurrent($row,$today);
            if($action==='EDIT'){
                demand($active,'Activate this employee before editing.',409);
                // Compare the directory row and payment profile *under the same transaction*.
                // Never overwrite a simultaneous correction made by another user.
                demand(is_int($d['expected_assignment_id']??null) &&
                    $d['expected_assignment_id']===(int)$row['assignment_id'] &&
                    is_string($d['expected_full_name']??null) &&
                    $d['expected_full_name']===(string)$row['full_name'] &&
                    is_string($d['expected_position_title']??null) &&
                    $d['expected_position_title']===(string)$row['position_title'] &&
                    is_string($d['expected_designation']??null) &&
                    $d['expected_designation']===(string)$row['designation'],
                    'Employee directory changed. Refresh and reopen Edit before saving.',409);
                $current=$this->employeeDvIdentityVersion($org,$employee);
                demand(is_int($d['expected_version']??null) &&
                    $d['expected_version']===$current['version_id'],
                    'Employee payment details changed in another session. Reopen Edit.',409);
                $name=requiredString($d,'full_name',160);
                $position=requiredString($d,'position_title',160);
                demand(!isset($d['designation']) || is_string($d['designation']),
                    'Invalid designation.',400);
                $designation=trim((string)($d['designation']??''));
                if($designation==='')$designation=$position;
                demand(mbSafeLen($designation)<=160 &&
                    !preg_match('/[\x00-\x1F\x7F]/',$designation),
                    'Invalid employee designation.',422);
                $fields=$this->employeeDvIdentityInput($d);
                $oldFields=['employee_no'=>$current['employee_no'],'tin'=>$current['tin'],'address'=>$current['address']];
                $identityChanged=$fields!==$oldFields;
                $nameChanged=$name!==(string)$row['full_name'];
                $positionChanged=$position!==(string)$row['position_title'];
                $designationChanged=$designation!==(string)$row['designation'];
                demand($identityChanged||$nameChanged||$positionChanged||$designationChanged,
                    'No employee details have changed.',422);
                if($nameChanged||$positionChanged){
                    // The legal name and position live on the global employee record;
                    // changing a multiply-assigned identity would affect another workspace.
                    $other=one($this->db,'SELECT id FROM employee_assignments WHERE employee_id=? AND organization_id<>? LIMIT 1',
                        [$employee,$org]);
                    demand($other===null,
                        'This employee is assigned to another organization. Request an identity review before changing name or position.',409);
                }
                if($nameChanged){
                    $reason=requiredString($d,'reason',500);
                    foreach($this->staffRows($org) as $member)
                        demand((int)$member['employee_id']===$employee || !$member['active'] ||
                            strcasecmp((string)$member['full_name'],$name)!==0,
                            'Another active employee already uses this name.',409);
                }else{$reason='';}
                if($nameChanged||$positionChanged){
                    q($this->db,'UPDATE employees SET full_name=?,position_title=? WHERE id=?',
                        [$name,$position,$employee]);
                }
                $assignmentId=(int)$row['assignment_id'];
                if($designationChanged){
                    // Keep the earlier employment episode intact when the edit
                    // occurs on a later calendar date. Same-day corrections are
                    // audited, since DATE granularity cannot express two daily periods.
                    if((string)$row['effective_from']<$today){
                        q($this->db,'UPDATE employee_assignments SET effective_to=? WHERE id=? AND organization_id=?',
                            [$today,$assignmentId,$org]);
                        $assignmentId=$this->insert('employee_assignments',[
                            'employee_id'=>$employee,'organization_id'=>$org,
                            'designation'=>$designation,'effective_from'=>$today]);
                    }else{
                        q($this->db,'UPDATE employee_assignments SET designation=? WHERE id=? AND organization_id=?',
                            [$designation,$assignmentId,$org]);
                    }
                }
                $version=$identityChanged?$this->saveEmployeeDvIdentity($org,$employee,$fields):$current['version_id'];
                // No TIN, address, or employee number in general-purpose audit metadata.
                $this->audit($org,'UPDATED','EMPLOYEE',$employee,[
                    'before'=>['full_name'=>$row['full_name'],'position_title'=>$row['position_title'],
                        'designation'=>$row['designation'],'assignment_id'=>(int)$row['assignment_id']],
                    'after'=>['full_name'=>$name,'position_title'=>$position,
                        'designation'=>$designation,'assignment_id'=>$assignmentId],
                    'payment_profile_changed'=>$identityChanged,'profile_version_id'=>$version,
                    'legal_name_correction_reason'=>$reason]);
                return ['id'=>$employee,'assignment_id'=>$assignmentId,'version_id'=>$version,'active'=>true];
            }
            if($action==='PROFILE'){
                demand($active,'Activate this employee before updating their DV details.',409);
                $current=$this->employeeDvIdentityVersion($org,$employee);
                demand(isset($d['expected_version']) && is_int($d['expected_version']) &&
                    $d['expected_version']===$current['version_id'],
                    'Employee payment details changed in another session. Reload before saving.',409);
                $fields=$this->employeeDvIdentityInput($d);
                $old=['employee_no'=>$current['employee_no'],'tin'=>$current['tin'],'address'=>$current['address']];
                demand($old!==$fields,'No employee payment-detail changes to save.',409);
                $version=$this->saveEmployeeDvIdentity($org,$employee,$fields);
                return ['id'=>$employee,'version_id'=>$version,'active'=>true];
            }
            if($action==='RENAME'){
                demand($active,'Activate the employee before editing their name');
                // Global employee identities must not silently change in another school.
                $other=one($this->db,'SELECT id FROM employee_assignments WHERE employee_id=? AND organization_id<>? LIMIT 1',[$employee,$org]);
                demand($other===null,'Employee has another organization assignment; request identity review',409);
                $name=requiredString($d,'full_name',160);
                $reason=requiredString($d,'reason',500);
                foreach($this->staffRows($org) as $member)
                    demand((int)$member['employee_id']===$employee || !$member['active'] || strcasecmp((string)$member['full_name'],$name)!==0,'Another active employee already uses this name',409);
                $old=(string)$row['full_name'];
                q($this->db,'UPDATE employees SET full_name=? WHERE id=?',[$name,$employee]);
                $this->audit($org,'RENAMED','EMPLOYEE',$employee,['before'=>$old,'after'=>$name,'reason'=>$reason]);
                return ['id'=>$employee,'active'=>true];
            }
            if($action==='DEACTIVATE'){
                demand($active,'Employee already inactive',409);
                // Keep active official posts resolvable until explicitly reassigned.
                $head=$this->schoolHeadVersion($org);
                demand((int)($head['employee_id']??0)!==$employee,'Reassign the office head before deactivating this employee',409);
                $custodian=$this->custodianVersion($org);
                demand((int)($custodian['employee_id']??0)!==$employee,
                    'Reassign the Supply/Property Custodian before deactivating this employee',409);
                $roles=$this->staffDirectory(['organization_id'=>$org])['bac'];
                demand(!in_array($employee,array_merge([(int)($roles['chair']??0),(int)($roles['cochair']??0)],$roles['members']),true),
                    'Update BAC appointments before deactivating this employee',409);
                q($this->db,'UPDATE employee_assignments SET effective_to=? WHERE id=? AND organization_id=?',[$today,$row['assignment_id'],$org]);
                $this->audit($org,'DEACTIVATED','EMPLOYEE',$employee,['assignment_id'=>$row['assignment_id'],'effective_to'=>$today]);
                return ['id'=>$employee,'active'=>false];
            }
            demand(!$active,'Employee is already active',409);
            foreach($this->staffRows($org) as $member)
                demand(!$member['active'] || (int)$member['employee_id']===$employee || strcasecmp((string)$member['full_name'],(string)$row['full_name'])!==0,
                    'Another active employee already uses this name',409);
            $assignment=$this->insert('employee_assignments',['employee_id'=>$employee,'organization_id'=>$org,'designation'=>$row['designation'],'effective_from'=>$today]);
            $this->audit($org,'REACTIVATED','EMPLOYEE',$employee,['assignment_id'=>$assignment]);
            return ['id'=>$employee,'assignment_id'=>$assignment,'active'=>true];
        });
    }
    /** Office head is a documented directory appointment; not a digital approval. */
    public function saveOfficeHead(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        return $this->financialTx('staff.head.save',$d,function()use($org,$d){
            $meta=one($this->db,'SELECT id FROM organizations WHERE id=? AND active=1 FOR UPDATE',[$org]);
            demand($meta!==null,'Organization unavailable',404);
            $previous=$this->schoolHeadVersion($org);
            $expected=$d['expected_version']??null;
            demand(is_numeric($expected)&&(int)$expected===$previous['version_id'],'Office head changed. Refresh and try again',409);
            $employee=($d['employee_id']??null);
            $employee=($employee===null||$employee==='')?null:idOf($d,'employee_id');
            if($employee!==null)$this->staffActive($org,$employee);
            demand($employee!==$previous['employee_id'],'No change to office head');
            // Signatory appointments are audited automatically; no manual reason is required.
            $reason='Office head appointment updated';
            $version=$this->insert('configuration_versions',['organization_id'=>$org,'config_key'=>self::ORG_HEAD_KEY,
                'config_json'=>json_encode(['employee_id'=>$employee],JSON_THROW_ON_ERROR),
                'effective_from'=>self::todayManila(),'review_note'=>$reason]);
            $this->audit($org,'UPDATED','OFFICE_HEAD',$version,['employee_id'=>$employee,'previous_employee_id'=>$previous['employee_id'],'reason'=>$reason]);
            return ['version_id'=>$version,'employee_id'=>$employee];
        });
    }
    /** Correct an existing BAC term's documentary start date, without changing membership.
     * This is not an appointment approval and never mutates employee-assignment episodes.
     * Existing document versions keep their snapshotted officer identities. */
    public function correctBacTermEffectiveDate(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        return $this->tx(function()use($org,$d){
            $meta=one($this->db,'SELECT id,kind FROM organizations WHERE id=? AND active=1 FOR UPDATE',[$org]);
            demand($meta!==null && in_array((string)$meta['kind'],['SCHOOL','DIVISION'],true),'School/Division workspace required',403);
            $termId=idOf($d,'term_id');
            $terms=q($this->db,'SELECT id,starts,ends FROM bac_terms WHERE organization_id=? ORDER BY starts,id FOR UPDATE',[$org])->fetchAll();
            $target=null;
            foreach($terms as $row)if((int)$row['id']===$termId){$target=$row;break;}
            demand($target!==null,'The BAC term is unavailable for this organization. Refresh.',409);
            $today=self::todayManila();
            demand((string)$target['starts']<=$today && ($target['ends']===null || (string)$target['ends']>$today),
                'Only the current BAC term may be corrected in this form',409);
            $active=array_filter($terms,static fn($row)=>(string)$row['starts']<=$today &&
                ($row['ends']===null || (string)$row['ends']>$today));
            demand(count($active)===1,'Overlapping current BAC terms require administrator review',409);
            $original=(string)$target['starts'];
            demand((string)($d['expected_starts']??'')===$original,
                'BAC term date changed in another session. Refresh before saving.',409);
            $corrected=self::transactionDocumentDate(requiredString($d,'effective_from',10),'BAC term effective date');
            demand($corrected!==$original,'The BAC term already has this effective date',422);
            if($target['ends']!==null)demand($corrected<(string)$target['ends'],
                'BAC term cannot begin on or after its end date',409);
            foreach($terms as $row){
                if((int)$row['id']===$termId)continue;
                $otherStart=(string)$row['starts'];$otherEnd=$row['ends'];
                if(($otherEnd===null || $corrected<(string)$otherEnd) &&
                    ($target['ends']===null || $otherStart<(string)$target['ends']))
                    demand(false,'This date overlaps another BAC term. Review the term history first.',409);
            }
            $reason=trim((string)($d['appointment_reference']??''));
            demand(mbSafeLen($reason)>=20 && mbSafeLen($reason)<=500 &&
                !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$reason),
                'Enter the authentic BAC appointment/order reference (at least 20 characters)',422);
            demand(($d['official_record_confirmed']??false)===true,
                'Confirm that the official BAC appointment supports this effective date',422);
            $members=q($this->db,'SELECT employee_id,role_title FROM bac_members WHERE term_id=? ORDER BY employee_id',[$termId])->fetchAll();
            $chair=0;$gaps=[];$memberIds=[];
            foreach($members as $member){
                $person=(int)$member['employee_id'];$memberIds[]=$person;
                if($member['role_title']==='CHAIR'){
                    demand($chair===0,'Multiple BAC Chairs require administrator review',409);$chair=$person;
                }
                // BAC membership already exists; historical officers may now be retired.
                $owned=one($this->db,'SELECT id FROM employee_assignments WHERE organization_id=? AND employee_id=? ORDER BY id DESC LIMIT 1',[$org,$person]);
                demand($owned!==null,'A BAC member no longer has verifiable assignment to this organization',409);
                $covered=one($this->db,'SELECT id FROM employee_assignments WHERE organization_id=? AND employee_id=? AND effective_from<=? AND (effective_to IS NULL OR effective_to>?) LIMIT 1',
                    [$org,$person,$corrected,$corrected]);
                if($covered===null)$gaps[]=$person;
            }
            demand($chair>0,'The current BAC Chair must be configured before correcting term dates',409);
            // A signed appointment may predate employee entry in IPDAMS. Explicit,
            // detailed attestation is the only way to resolve that archival gap.
            $snapshot=['term_id'=>$termId,'previous_starts'=>$original,'corrected_starts'=>$corrected,
                'chair_employee_id'=>$chair,'member_employee_ids'=>$memberIds,
                'historical_assignment_gaps'=>$gaps,'official_record_confirmed'=>true,
                'reference'=>$reason];
            $version=$this->insert('configuration_versions',['organization_id'=>$org,
                'config_key'=>'ORG_BAC_EFFECTIVITY_CORRECTION_V1_'.$termId,
                'config_json'=>json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
                'effective_from'=>$corrected,'review_note'=>$reason]);
            q($this->db,'UPDATE bac_terms SET starts=? WHERE id=? AND organization_id=? AND starts=?',
                [$corrected,$termId,$org,$original]);
            $this->audit($org,'EFFECTIVITY_CORRECTED','BAC_TERM',$termId,
                ['previous_effective_from'=>$original,'corrected_effective_from'=>$corrected,
                 'appointment_reference'=>$reason,'attested'=>true,'staff_date_gaps'=>$gaps,'config_version_id'=>$version]);
            return ['term_id'=>$termId,'starts'=>$corrected,'previous_starts'=>$original,
                'version_id'=>$version,'historical_assignment_gaps'=>$gaps];
        });
    }
    /** BAC membership is term-based. Previous terms remain intact and auditable. */
    public function saveBacTerm(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        return $this->tx(function()use($org,$d){
            $meta=one($this->db,'SELECT id FROM organizations WHERE id=? AND active=1 FOR UPDATE',[$org]);
            demand($meta!==null,'Organization unavailable',404);
            $today=self::todayManila();
            $terms=q($this->db,'SELECT id FROM bac_terms WHERE organization_id=? AND starts<=? AND (ends IS NULL OR ends>?) ORDER BY id DESC FOR UPDATE',[$org,$today,$today])->fetchAll();
            demand(count($terms)<=1,'Multiple active BAC terms require technical review',409);
            $previous=$terms[0]['id']??0;
            demand(is_numeric($d['expected_term_id']??null) && (int)$d['expected_term_id']===(int)$previous,
                'BAC membership changed. Refresh before saving',409);
            $chair=idOf($d,'chair_employee_id');
            $cochair=($d['cochair_employee_id']??null);
            $cochair=($cochair===null||$cochair==='')?null:idOf($d,'cochair_employee_id');
            $members=$d['member_employee_ids']??[];
            demand(is_array($members)&&count($members)<=50,'BAC member list is invalid');
            $ids=[$chair];if($cochair!==null)$ids[]=$cochair;
            foreach($members as $value){demand((is_string($value)&&ctype_digit($value))||is_int($value),'Invalid BAC member ID');$ids[]=(int)$value;}
            demand(count($ids)===count(array_unique($ids)),'Each BAC employee must have only one role in the current term');
            foreach($ids as $id)$this->staffActive($org,$id);
            // The dated term and full before/after officer IDs provide the audit record.
            $reason='BAC appointments updated';
            if($previous)q($this->db,'UPDATE bac_terms SET ends=? WHERE id=? AND organization_id=?',[$today,$previous,$org]);
            $term=$this->insert('bac_terms',['organization_id'=>$org,'starts'=>$today]);
            $this->insert('bac_members',['term_id'=>$term,'employee_id'=>$chair,'role_title'=>'CHAIR']);
            if($cochair!==null)$this->insert('bac_members',['term_id'=>$term,'employee_id'=>$cochair,'role_title'=>'COCHAIR']);
            foreach($members as $member)$this->insert('bac_members',['term_id'=>$term,'employee_id'=>(int)$member,'role_title'=>'MEMBER']);
            $this->audit($org,'APPOINTED','BAC_TERM',$term,['prior_term_id'=>(int)$previous,'chair'=>$chair,'cochair'=>$cochair,'members'=>$members,'reason'=>$reason]);
            return ['term_id'=>$term,'chair'=>$chair,'cochair'=>$cochair,'members'=>$members];
        });
    }

    /** Requestor for a PR, versioned in existing configuration history, without schema changes.
     * Both org and PR id are checked by callers; identities are snapshots at selection.
     * Legacy PRs without a recorded requester deliberately have null metadata.
     */
    private function prRequestor(int $org,int $prId): ?array {
        $row=one($this->db,'SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',
            [$org,'PR_REQUESTOR_V1_'.$prId]);
        if($row===null)return null;
        $value=json_decode((string)$row['config_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($value) && (int)($value['employee_id']??0)>0 &&
            is_string($value['name']??null) && trim($value['name'])!=='' &&
            is_string($value['designation']??null), 'Invalid recorded PR requestor reference',409);
        return ['employee_id'=>(int)$value['employee_id'],'name'=>$value['name'],'designation'=>$value['designation']];
    }
    private function choosePrRequestor(int $org,array $d): array {
        $employee=$this->staffActive($org,idOf($d,'requestor_employee_id'));
        return ['employee_id'=>(int)$employee['employee_id'],
            'name'=>(string)$employee['full_name'],
            'designation'=>(string)($employee['designation']?:$employee['position_title'])];
    }
    private function recordPrRequestor(int $org,int $prId,array $requestor,string $reason): void {
        $this->insert('configuration_versions',[
            'organization_id'=>$org,'config_key'=>'PR_REQUESTOR_V1_'.$prId,
            'config_json'=>json_encode($requestor,JSON_THROW_ON_ERROR),
            'effective_from'=>self::todayManila(),'review_note'=>$reason]);
    }
    /** Freeze the eligible debit UACS against the actual PR document date.
     * Only an approved, effective-dated Division or legacy mapping is allowed.
     * One line-level record is preserved in the immutable PR document version.
     * There is no DV/check/fund journal or accounting change here.
     */
    private function prLineAccountingSnapshot(int $org,array $pr,array $lines): array {
        $date=self::transactionDocumentDate((string)($pr['document_date']??''),'PR item UACS date');
        $division=$this->divisionId($org);
        $config=$this->divisionItemUacsConfig($division);
        $saved=[];
        foreach($lines as $line){
            $item=(int)($line['item_id']??0);
            $lineId=(int)($line['id']??0);
            demand($item>0 && $lineId>0,'PR item identity is incomplete; cannot freeze accounting UACS',409);
            $global=q($this->db,"SELECT m.uacs_code,m.effective_from FROM item_account_mappings m
                WHERE m.item_id=? AND m.effective_from<=?
                  AND (m.effective_to IS NULL OR m.effective_to>=?)
                ORDER BY m.effective_from DESC,m.id DESC LIMIT 2",[$item,$date,$date])->fetchAll();
            $resolved=PrUacsInheritance::choose($item,$date,$config['mappings'],$global);
            demand($resolved['ok'],
                'PR item #'.$item.' has no eligible debit UACS effective on '.$date.'. '.
                (string)($resolved['issue']??'').'. Ask the Division Accountant to review the dated mapping before continuing.',409);
            $code=(string)$resolved['uacs_code'];
            demand(one($this->db,'SELECT code FROM uacs_accounts WHERE code=? AND active=1',[$code])!==null,
                'PR item #'.$item.' UACS '.$code.' is not an active approved debit account.',409);
            $saved[]=['pr_line_id'=>$lineId,'item_id'=>$item,'uacs_code'=>$code,
                'effective_from'=>$resolved['effective_from'],'source'=>$resolved['source']];
        }
        demand(count($saved)>0,'Purchase Request needs at least one item before accounting classification',409);
        return ['format'=>1,'pr_id'=>(int)$pr['id'],'pr_document_date'=>$date,
            'division_id'=>$division,'division_mapping_version_id'=>(int)$config['version_id'],
            'lines'=>$saved];
    }
    public function createPR(array $d): array { $org=$this->org($d);$this->operationalWriteAllowed($org);return $this->tx(function()use($org,$d){
        $fund=$this->record('funds',idOf($d,'fund_id'),$org);
        $requestor=$this->choosePrRequestor($org,$d);
        $tid=$this->insert('procurement_transactions',['organization_id'=>$org,'title'=>requiredString($d,'purpose',240),'method'=>$this->prMethod($d),'created_by'=>$this->user['id']]);
        $pid=$this->insert('purchase_requests',['organization_id'=>$org,'transaction_id'=>$tid,'fund_id'=>$fund['id'],'purpose'=>requiredString($d,'purpose',500),'requesting_unit'=>$this->validatePrRequestingUnit($org,$d),'document_date'=>$this->prDocumentDate($d),'created_by'=>$this->user['id']]);
        $this->recordPrRequestor($org,$pid,$requestor,'Selected PR requestor');
        $sum=0;foreach(rowsRequired($d,'lines') as $line){demand(is_array($line),'Invalid line');$item=one($this->db,'SELECT i.*,u.code FROM item_catalog i JOIN units u ON u.id=i.unit_id WHERE i.id=? AND i.active=1',[idOf($line,'item_id')]);demand($item!==null && $this->catalogItemAccessible($org,(int)$item['id']), 'Item not found in this workspace catalog');$qty=qtyMillis($line['quantity']??'');$unit=moneyCents($line['unit_price']??'');demand($qty>0&&$unit>0,'Quantity and price must be positive');$sum+=totalCents($unit,$qty);$this->insert('pr_lines',['pr_id'=>$pid,'item_id'=>$item['id'],'description_snapshot'=>$item['name'],'unit_snapshot'=>$item['code'],'quantity'=>qtyString($qty),'estimated_unit_price'=>moneyString($unit)]);}
        q($this->db,'UPDATE purchase_requests SET total=? WHERE id=?',[moneyString($sum),$pid]);
        $createdPr=$this->record('purchase_requests',$pid,$org);
        $createdLines=q($this->db,'SELECT * FROM pr_lines WHERE pr_id=? ORDER BY id',[$pid])->fetchAll();
        $this->prLineAccountingSnapshot($org,$createdPr,$createdLines);
        $this->audit($org,'CREATED','PR',$pid,['requestor_employee_id'=>$requestor['employee_id']]);return ['id'=>$pid,'transaction_id'=>$tid,'status'=>'DRAFT','total'=>moneyString($sum)];
    }); }
    /** A draft revision guard without changing the live database schema. */
    private function prDraftToken(array $pr,array $lines,string $method,?array $requestor=null): string {
        $header=array_map(static fn($v)=>(string)$v,[
            $pr['id'],$pr['organization_id'],$pr['transaction_id'],$pr['fund_id'],
            $pr['purpose'],$pr['requesting_unit'],$pr['status'],$pr['total'],$pr['document_date']??'',$method
        ]);
        $items=array_map(static fn($x)=>array_map(static fn($v)=>(string)$v,[
            $x['id'],$x['item_id'],$x['description_snapshot'],$x['unit_snapshot'],
            $x['quantity'],$x['estimated_unit_price']
        ]),$lines);
        return hash('sha256',json_encode([$header,$items,$requestor],JSON_THROW_ON_ERROR));
    }
    /** The PR is documentation: finalization assigns a number, not an electronic approval.
     * Its contents can still change before canvass or any accountable downstream transaction.
     * This method is reused by detail display AND the locked server-side mutation.
     */
    private function prEditBlockReason(int $org,array $pr): ?string {
        if($pr['status']==='CANCELLED')return 'Cancelled PRs cannot be edited.';
        if($pr['status']==='DRAFT'){
            if($pr['display_number']!==null)return 'Inconsistent draft PR numbering; technical review required.';
        } elseif($pr['status']==='FINALIZED'){
            if(!is_string($pr['display_number']) || $pr['display_number']==='')return 'Finalized PR has no control number.';
            $registered=one($this->db,"SELECT display_number FROM document_registry WHERE organization_id=? AND doc_type='PR' AND entity_id=?",[$org,$pr['id']]);
            if($registered===null || $registered['display_number']!==$pr['display_number'])
                return 'PR registry does not match its control number.';
        } else return 'Only draft or finalized PRs can be edited.';
        if(one($this->db,'SELECT 1 FROM rfqs WHERE pr_id=? LIMIT 1',[$pr['id']])!==null)
            return 'Canvassing has begun. The PR is locked, including cancelled canvasses.';
        foreach([['awards','pr_id'],['purchase_orders','pr_id'],
                 ['requisition_issue_slips','pr_id'],['dv_allocation_lines','pr_id']] as [$table,$field]){
            if(one($this->db,"SELECT 1 FROM $table WHERE $field=? LIMIT 1",[$pr['id']])!==null)
                return 'This PR is linked to downstream records and cannot be edited.';
        }
        if(one($this->db,"SELECT 1 FROM document_links WHERE organization_id=? AND ((source_type='PR' AND source_id=?) OR (target_type='PR' AND target_id=?)) LIMIT 1",[$org,$pr['id'],$pr['id']])!==null)
            return 'This PR has registered document links and cannot be edited.';
        return null;
    }
    /** A numbered finalized PR stays editable only before canvassing starts. */
    public function updateDraftPR(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        return $this->tx(function()use($org,$d){
            $pr=$this->record('purchase_requests',idOf($d,'pr_id'),$org,true);
            $reason=$this->prEditBlockReason($org,$pr);
            demand($reason===null,$reason??'PR cannot be edited',409);
            $tx=one($this->db,'SELECT id,method FROM procurement_transactions WHERE id=? AND organization_id=? FOR UPDATE',[$pr['transaction_id'],$org]);
            demand($tx!==null,'Procurement transaction is missing',409);
            $old=q($this->db,'SELECT * FROM pr_lines WHERE pr_id=? ORDER BY id FOR UPDATE',[$pr['id']])->fetchAll();
            $previousRequestor=$this->prRequestor($org,(int)$pr['id']);
            $token=requiredString($d,'draft_token',64);
            demand(hash_equals($this->prDraftToken($pr,$old,(string)$tx['method'],$previousRequestor),$token),
                'This PR draft has changed since you opened it. Close and reload before editing.',409);
            $purpose=requiredString($d,'purpose',500);
            $requesting=requiredString($d,'requesting_unit',160);
            // Existing PR values remain valid when editing unrelated fields.
            if($requesting!==(string)$pr['requesting_unit'])$requesting=$this->validatePrRequestingUnit($org,$d);
            $documentDate=$this->prDocumentDate($d);
            $requestor=$previousRequestor;
            if(isset($d['requestor_employee_id']) && (string)$d['requestor_employee_id']!==''){
                $requested=idOf($d,'requestor_employee_id');
                if($previousRequestor===null || $requested!==(int)$previousRequestor['employee_id'])
                    $requestor=$this->choosePrRequestor($org,$d);
            }
            $fund=$this->record('funds',idOf($d,'fund_id'),$org);
            $method=$this->prMethod($d);
            $submitted=rowsRequired($d,'lines');
            demand(count($submitted)<=100,'A draft PR may have at most 100 item lines');
            $previousItemIds=array_map(static fn($x)=>(int)$x['item_id'],$old);
            $ready=[];$sum=0;
            foreach($submitted as $line){
                demand(is_array($line),'Invalid PR item line');
                $item=one($this->db,'SELECT i.id,i.name,i.active,u.code FROM item_catalog i JOIN units u ON u.id=i.unit_id WHERE i.id=?',[idOf($line,'item_id')]);
                demand($item!==null,'Catalog item was not found');
                demand((int)$item['active']===1 || in_array((int)$item['id'],$previousItemIds,true),
                    'A newly selected item is inactive. Choose an active catalog item.');
                if(!in_array((int)$item['id'],$previousItemIds,true))
                    demand($this->catalogItemAccessible($org,(int)$item['id']),
                        'The newly selected item is outside your school/Division catalog',403);
                $quantity=qtyMillis($line['quantity']??'');$price=moneyCents($line['unit_price']??'');
                demand($quantity>0 && $price>0,'Quantity and estimated unit price must be positive');
                $amount=totalCents($price,$quantity);
                demand($sum<=PHP_INT_MAX-$amount,'Purchase Request amount is too large');
                $sum+=$amount;
                $ready[]=['item_id'=>$item['id'],'description_snapshot'=>$item['name'],
                    'unit_snapshot'=>$item['code'],'quantity'=>qtyString($quantity),
                    'estimated_unit_price'=>moneyString($price)];
            }
            // All validation happens before removing any previous line. All writes are in
            // the same transaction as the API idempotency key and audit event.
            q($this->db,'DELETE FROM pr_lines WHERE pr_id=?',[$pr['id']]);
            foreach($ready as $line)$this->insert('pr_lines',['pr_id'=>$pr['id']]+$line);
            q($this->db,'UPDATE purchase_requests SET fund_id=?,purpose=?,requesting_unit=?,document_date=?,total=? WHERE id=?',
                [$fund['id'],$purpose,$requesting,$documentDate,moneyString($sum),$pr['id']]);
            q($this->db,'UPDATE procurement_transactions SET title=?,method=? WHERE id=? AND organization_id=?',
                [(function_exists('mb_substr')?mb_substr($purpose,0,240):substr($purpose,0,240)),$method,$tx['id'],$org]);
            if($requestor!==$previousRequestor){
                $this->recordPrRequestor($org,(int)$pr['id'],$requestor,'PR requestor corrected before canvass');
            }
            $current=$this->record('purchase_requests',(int)$pr['id'],$org);
            $currentLines=q($this->db,'SELECT * FROM pr_lines WHERE pr_id=? ORDER BY id',[$pr['id']])->fetchAll();
            $prUacs=$this->prLineAccountingSnapshot($org,$current,$currentLines);
            $this->audit($org,'UPDATED','PR',(int)$pr['id'],[
                'previous'=>['status'=>$pr['status'],'display_number'=>$pr['display_number'],
                    'fund_id'=>$pr['fund_id'],'purpose'=>$pr['purpose'],
                    'requesting_unit'=>$pr['requesting_unit'],'document_date'=>$pr['document_date']??null,'method'=>$tx['method'],
                    'total'=>$pr['total'],'requestor'=>$previousRequestor,'lines'=>$old],
                'current'=>['status'=>$pr['status'],'display_number'=>$pr['display_number'],
                    'fund_id'=>$fund['id'],'purpose'=>$purpose,
                    'requesting_unit'=>$requesting,'document_date'=>$documentDate,'method'=>$method,
                    'total'=>moneyString($sum),'requestor'=>$requestor,'lines'=>$ready]
            ]);
            if($pr['status']==='FINALIZED'){
                // Earlier immutable versions remain intact. The latest version is
                // the print source. A previously signed paper must be re-signed.
                $this->snapshot($org,'PR',(int)$pr['id'],[
                    'header'=>$current,'lines'=>$currentLines,'requestor'=>$requestor,
                    'uacs_line_snapshot'=>$prUacs,
                    'number'=>$pr['display_number'],
                    'fund_name'=>one($this->db,'SELECT name FROM funds WHERE id=?',[$fund['id']])['name'],
                    'organization_name'=>one($this->db,'SELECT name FROM organizations WHERE id=?',[$org])['name']
                ],'Revised finalized PR before canvass; reprint and obtain fresh manual signatures');
            }
            return ['id'=>(int)$pr['id'],'status'=>$pr['status'],'number'=>$pr['display_number'],
                'total'=>moneyString($sum),'draft_token'=>$this->prDraftToken($current,$currentLines,$method,$requestor)];
        });
    }
    public function finalizePR(array $d): array { $org=$this->org($d);$this->operationalWriteAllowed($org);return $this->tx(function()use($org,$d){
        $p=$this->record('purchase_requests',idOf($d,'pr_id'),$org,true);demand($p['status']==='DRAFT','Only a draft PR can be finalized');
        demand(!empty($p['document_date']),'Set the PR document date before finalization.');
        self::transactionDocumentDate((string)$p['document_date'],'PR document date');
        $lines=q($this->db,'SELECT * FROM pr_lines WHERE pr_id=? ORDER BY id',[$p['id']])->fetchAll();
        $prUacs=$this->prLineAccountingSnapshot($org,$p,$lines);
        $n=$this->registered($org,'PR',(int)$p['id']);
        q($this->db,"UPDATE purchase_requests SET status='FINALIZED',display_number=? WHERE id=?",[$n,$p['id']]);
        $this->snapshot($org,'PR',(int)$p['id'],[
            'header'=>$p,'lines'=>$lines,'requestor'=>$this->prRequestor($org,(int)$p['id']),
            'number'=>$n,'uacs_line_snapshot'=>$prUacs,
            'fund_name'=>one($this->db,'SELECT name FROM funds WHERE id=?',[$p['fund_id']])['name'],
            'organization_name'=>one($this->db,'SELECT name FROM organizations WHERE id=?',[$org])['name']]);
        $this->audit($org,'FINALIZED','PR',(int)$p['id']);
        return ['id'=>(int)$p['id'],'number'=>$n];
    }); }
    public function createRFQ(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org);return $this->tx(function()use($org,$d){
        $pr=$this->record('purchase_requests',idOf($d,'pr_id'),$org,true);demand($pr['status']==='FINALIZED','Finalized PR required');
        $issuedAt=$this->rfqIssuedDate($d,$pr);
        // The PR row lock serializes concurrent RFQ creation for the same PR.
        // Preserve historical duplicate RFQs; disallow any *new* duplicate, even
        // when an earlier RFQ is cancelled. Existing RFQs remain reprintable.
        demand(one($this->db,'SELECT id FROM rfqs WHERE pr_id=? LIMIT 1',[$pr['id']])===null,
            'This PR already has an RFQ/Canvass. Reprint and reuse its number; record each supplier quotation separately.',409);
        $submitted=rowsRequired($d,'lines');demand(count($submitted)<=100,'Too many RFQ items');
        $seen=[];$prepared=[];
        foreach($submitted as $line){
            $pl=one($this->db,'SELECT * FROM pr_lines WHERE id=? AND pr_id=? FOR UPDATE',[idOf($line,'pr_line_id'),$pr['id']]);demand($pl!==null,'RFQ item does not belong to the PR');
            demand((int)$pl['pr_id']===(int)$pr['id'],'RFQ line does not belong to this PR');
            demand(!isset($seen[$pl['id']]),'Duplicate RFQ item');$seen[$pl['id']]=true;
            $qty=qtyMillis($line['quantity']??'');demand($qty>0&&$qty<=qtyMillis($pl['quantity']),'RFQ quantity must be positive and no greater than PR quantity');
            $ordered=one($this->db,"SELECT COALESCE(SUM(l.quantity),0) total FROM po_lines l JOIN purchase_orders po ON po.id=l.po_id WHERE po.pr_id=? AND l.pr_line_id=? AND po.status!='CANCELLED'",[$pr['id'],$pl['id']]);
            demand($qty+qtyMillis($ordered['total'])<=qtyMillis($pl['quantity']),'RFQ item quantity exceeds currently un-ordered PR balance');
            $prepared[]=['pr_line_id'=>$pl['id'],'quantity'=>qtyString($qty),'description_snapshot'=>$pl['description_snapshot'],'unit_snapshot'=>$pl['unit_snapshot']];
        }
        $id=$this->insert('rfqs',['organization_id'=>$org,'pr_id'=>$pr['id'],'issued_at'=>$issuedAt,'created_by'=>$this->user['id'],'selected_lines_json'=>json_encode($prepared,JSON_THROW_ON_ERROR)]);
        $n=$this->registered($org,'RFQ',$id);q($this->db,'UPDATE rfqs SET display_number=? WHERE id=?',[$n,$id]);
        $this->link($org,'PR',(int)$pr['id'],'RFQ',$id);
        $this->snapshot($org,'RFQ',$id,['pr_id'=>$pr['id'],'number'=>$n,'issued_at'=>$issuedAt,'lines'=>$prepared]);
        $this->audit($org,'FINALIZED','RFQ',$id,['issued_at'=>$issuedAt,
            'source_pr_document_date'=>(string)($pr['document_date']??''),'selected_lines'=>$prepared]);
        return ['id'=>$id,'number'=>$n,'issued_at'=>$issuedAt];
    });}
    /** Audited, same-number RFQ correction before recorded distribution is assumed.
     * Server row lock coordinates with quotation entry and award creation.
     */
    public function updateRFQ(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        return $this->tx(function()use($d,$org){
            $rfq=$this->record('rfqs',idOf($d,'rfq_id'),$org,true);
            $edit=$this->rfqEditState($rfq);
            demand($edit['allowed'],$edit['reason'],409);
            $expected=$d['expected_version']??null;
            demand((is_int($expected)||(is_string($expected)&&ctype_digit($expected))) &&
                (int)$expected===$edit['expected_version'],
                'RFQ changed after you opened the edit form. Reload it before saving.',409);
            $reason=requiredString($d,'reason',500);
            // Time alone cannot prove whether paper/email copies were sent out.
            // Require an explicit user attestation before changing the RFQ.
            demand(($d['not_distributed_confirmed']??false)===true,
                'Confirm this RFQ has not been distributed to suppliers.',409);
            $pr=$this->record('purchase_requests',(int)$rfq['pr_id'],$org);
            demand($pr['status']==='FINALIZED','Source Purchase Request is no longer finalized',409);
            $date=$this->rfqIssuedDate($d,$pr);
            $lines=rowsRequired($d,'lines');demand(count($lines)<=100,'Too many RFQ items');
            $prepared=[];$seen=[];
            foreach($lines as $line){
                $item=one($this->db,'SELECT * FROM pr_lines WHERE id=? AND pr_id=?',
                    [idOf($line,'pr_line_id'),$pr['id']]);
                demand($item!==null,'RFQ item is not from its source Purchase Request',409);
                demand(!isset($seen[$item['id']]),'Duplicate RFQ item',409);
                $seen[$item['id']]=true;
                $qty=qtyMillis($line['quantity']??'');
                demand($qty>0&&$qty<=qtyMillis($item['quantity']),
                    'RFQ quantity must be positive and no greater than PR quantity',409);
                $ordered=one($this->db,"SELECT COALESCE(SUM(l.quantity),0) total FROM po_lines l
                    JOIN purchase_orders po ON po.id=l.po_id WHERE po.pr_id=? AND l.pr_line_id=? AND po.status!='CANCELLED'",
                    [$pr['id'],$item['id']]);
                demand($qty+qtyMillis($ordered['total'])<=qtyMillis($item['quantity']),
                    'RFQ quantity exceeds currently un-ordered PR balance',409);
                $prepared[]=['pr_line_id'=>$item['id'],'quantity'=>qtyString($qty),
                    'description_snapshot'=>$item['description_snapshot'],'unit_snapshot'=>$item['unit_snapshot']];
            }
            $prior=one($this->db,"SELECT version_no,snapshot_json FROM document_versions
                WHERE organization_id=? AND doc_type='RFQ' AND entity_id=?
                ORDER BY version_no DESC LIMIT 1",[$org,$rfq['id']]);
            demand($prior!==null && (int)$prior['version_no']===$edit['expected_version'],
                'RFQ changed during correction. Reload the edit form.',409);
            $before=json_decode((string)$prior['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            demand(is_array($before),'RFQ snapshot requires administrative review',409);
            $priorLines=$before['lines']??null;
            demand(is_array($priorLines),'RFQ item snapshot is incomplete; correction blocked',409);
            demand($date!==(string)$rfq['issued_at'] || $prepared!==$priorLines,
                'No RFQ changes to save',409);
            // A late gate closes any eligibility that changed after validation.
            $lastGate=$this->rfqEditState($rfq);
            demand($lastGate['allowed'],$lastGate['reason'],409);
            q($this->db,'UPDATE rfqs SET issued_at=?,selected_lines_json=? WHERE id=? AND organization_id=?',
                [$date,json_encode($prepared,JSON_THROW_ON_ERROR),$rfq['id'],$org]);
            $next=['pr_id'=>$pr['id'],'number'=>$rfq['display_number'],'issued_at'=>$date,'lines'=>$prepared];
            $this->snapshot($org,'RFQ',(int)$rfq['id'],$next,$reason);
            $this->audit($org,'CORRECTED','RFQ',(int)$rfq['id'],[
                'reason'=>$reason,'before'=>$before,'after'=>$next,
                'previous_version'=>$edit['expected_version'],'new_version'=>$edit['expected_version']+1,
                'elapsed_seconds_at_gate'=>$lastGate['age_seconds'],
                'not_distributed_confirmed'=>true]);
            return ['id'=>(int)$rfq['id'],'number'=>$rfq['display_number'],'version'=>$edit['expected_version']+1,
                'issued_at'=>$date];
        });
    }
    public function createQuote(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org);return $this->tx(function()use($org,$d){
        $rfq=$this->record('rfqs',idOf($d,'rfq_id'),$org,true);demand($rfq['status']==='FINALIZED','RFQ not active');
        // The RFQ row is locked during this transaction. PO creation also
        // locks its parent RFQ; a stale browser cannot quote after award/order.
        demand(one($this->db,'SELECT id FROM awards WHERE rfq_id=? AND organization_id=? LIMIT 1',[$rfq['id'],$org])===null,
            'RFQ already awarded. Open the existing Abstract / PO, not another quotation.',409);
        $selected=$this->rfqSelectedLines((int)$rfq['id'],(int)$rfq['pr_id']);
        $poOutstanding=q($this->db,"SELECT l.pr_line_id,COALESCE(SUM(l.quantity),0) AS ordered_quantity
            FROM po_lines l JOIN purchase_orders po ON po.id=l.po_id
            WHERE po.organization_id=? AND po.pr_id=? AND po.status!='CANCELLED'
            GROUP BY l.pr_line_id",[$org,$rfq['pr_id']])->fetchAll();
        require_once __DIR__.'/RfqQuotationEligibility.php';
        // Reuse the same evaluator for back-end last gate. Historical selections
        // are read from immutable source, not caller-supplied quote lines.
        $quantities=[];
        foreach($poOutstanding as $ordered)$quantities[(int)$ordered['pr_line_id']]=(string)$ordered['ordered_quantity'];
        $progress=RfqQuotationEligibility::one(
            ['selected_lines_json'=>json_encode(array_map(static fn($r)=>['pr_line_id'=>$r['pr_line_id'],'quantity'=>$r['quantity']],$selected),JSON_THROW_ON_ERROR),
             'status'=>$rfq['status'],'quote_count'=>0,'award_count'=>0],
            array_map(static fn($r)=>['id'=>$r['pr_line_id'],'quantity'=>$r['pr_quantity']],$selected),$quantities);
        demand((int)$progress['quote_entry_allowed']===1,
            'This RFQ has already entered purchasing or all of its selected PR items are ordered. Do not record another supplier quotation.',409);
        $supplier=idOf($d,'supplier_id');demand(one($this->db,'SELECT id FROM suppliers WHERE id=? AND active=1',[$supplier])!==null,'Supplier not available');
        demand(one($this->db,'SELECT id FROM supplier_quotes WHERE rfq_id=? AND supplier_id=? LIMIT 1',[$rfq['id'],$supplier])===null,'Supplier quotation already recorded for this RFQ');
        // A reference number is helpful but not every supplier quotation has one.
        // Keep the non-null legacy column compatible by storing a blank string.
        // This never relaxes RFQ, supplier, line prices, compliance or duplicate guards.
        demand(!isset($d['evidence_note']) || is_string($d['evidence_note']),
            'Quotation reference must be text when supplied.',422);
        $quoteEvidence=trim((string)($d['evidence_note']??''));
        demand(mbSafeLen($quoteEvidence)<=500 && !preg_match('/[\x00-\x1F\x7F]/',$quoteEvidence),
            'Quotation reference is invalid or exceeds 500 characters.',422);
        $id=$this->insert('supplier_quotes',['rfq_id'=>$rfq['id'],'supplier_id'=>$supplier,'offered_at'=>date('Y-m-d'),'evidence_note'=>$quoteEvidence]);
        $allowed=array_map('intval',array_column($selected,'pr_line_id'));
        $submitted=rowsRequired($d,'lines');demand(count($submitted)===count($allowed),'Quotation must cover exactly the RFQ items');$seen=[];
        foreach($submitted as $line){$pl=idOf($line,'pr_line_id');demand(in_array($pl,$allowed,true),'Item is not in this RFQ');demand(!isset($seen[$pl]),'Duplicate quotation item');$seen[$pl]=true;$this->insert('quote_lines',['supplier_quote_id'=>$id,'pr_line_id'=>$pl,'offered_price'=>moneyString(moneyCents($line['price']??'')),'rfq_quantity'=>qtyString(qtyMillis($selected[array_search($pl,$allowed,true)]['quantity'])),'compliant'=>(int)!!($line['compliant']??false)]);}
        $this->audit($org,'RECEIVED','QUOTE',$id);return ['id'=>$id];
    });}
    /** Optimistic edit token from the committed quote and all original offer lines. */
    private function quotationEditToken(int $id): string {
        $header=one($this->db,'SELECT rfq_id,supplier_id,offered_at,evidence_note FROM supplier_quotes WHERE id=?',[$id]);
        demand($header!==null,'Quotation not found',404);
        $lines=q($this->db,'SELECT pr_line_id,offered_price,rfq_quantity,compliant FROM quote_lines WHERE supplier_quote_id=? ORDER BY pr_line_id',[$id])->fetchAll();
        return hash('sha256',json_encode(['header'=>$header,'lines'=>$lines],JSON_THROW_ON_ERROR));
    }
    /** A saved offer may be corrected until its RFQ enters Award or ordering.
     * Corrections remain tied to the original supplier/quote ID with before/after audit. */
    public function updateQuote(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        return $this->tx(function()use($org,$d){
            $id=idOf($d,'quote_id');
            $ref=one($this->db,'SELECT sq.rfq_id FROM supplier_quotes sq JOIN rfqs r ON r.id=sq.rfq_id WHERE sq.id=? AND r.organization_id=?',[$id,$org]);
            demand($ref!==null,'Quotation not found in active workspace',404);
            // Serialize with award and PO creation, which also lock this RFQ.
            $rfq=$this->record('rfqs',(int)$ref['rfq_id'],$org,true);
            demand($rfq['status']==='FINALIZED','RFQ is no longer active',409);
            demand(one($this->db,'SELECT id FROM awards WHERE rfq_id=? LIMIT 1',[$rfq['id']])===null,
                'Quotation correction is locked after an Award. Use a documented procurement correction instead.',409);
            demand(one($this->db,'SELECT id FROM purchase_orders WHERE pr_id=? AND organization_id=? LIMIT 1',[$rfq['pr_id'],$org])===null,
                'Quotation correction is locked once purchasing has begun.',409);
            $quote=one($this->db,'SELECT id,rfq_id,supplier_id,offered_at,evidence_note FROM supplier_quotes WHERE id=? AND rfq_id=?',[$id,$rfq['id']]);
            demand($quote!==null,'Quotation no longer available',409);
            $expected=(string)($d['edit_token']??'');
            demand((bool)preg_match('/^[a-f0-9]{64}$/D',$expected),'Refresh the quotation before correcting it.',409);
            demand(hash_equals($expected,$this->quotationEditToken($id)),
                'Quotation changed since it was opened. Refresh and review the latest amounts.',409);
            require_once __DIR__.'/ProcurementDocumentDates.php';
            $date=ProcurementDocumentDates::date($d['offered_at']??null,'supplier quotation date');
            $rfqDate=ProcurementDocumentDates::date(substr((string)$rfq['issued_at'],0,10),'RFQ date');
            demand($date >= $rfqDate,'Supplier quotation date cannot precede its RFQ date',422);
            demand(!isset($d['evidence_note'])||is_string($d['evidence_note']),
                'Quotation reference must be text',422);
            $evidence=trim((string)($d['evidence_note']??''));
            demand(mbSafeLen($evidence)<=500&&!preg_match('/[\x00-\x1F\x7F]/',$evidence),
                'Quotation reference invalid or too long',422);
            $selected=$this->rfqSelectedLines((int)$rfq['id'],(int)$rfq['pr_id']);
            $byId=[];foreach($selected as $sl)$byId[(int)$sl['pr_line_id']]=$sl;
            $submitted=rowsRequired($d,'lines');
            demand(count($submitted)===count($byId),'Corrected quotation must cover all RFQ lines',422);
            $before=q($this->db,'SELECT pr_line_id,offered_price,rfq_quantity,compliant FROM quote_lines WHERE supplier_quote_id=? ORDER BY pr_line_id',[$id])->fetchAll();
            demand(count($before)===count($byId),'Saved quotation line count needs review',409);
            $next=[];
            foreach($submitted as $line){
                $pl=idOf($line,'pr_line_id');
                demand(isset($byId[$pl])&&!isset($next[$pl]),'Invalid or duplicate quotation item',422);
                $price=moneyCents($line['price']??'');
                demand($price>0,'Offered unit prices must be positive',422);
                demand(array_key_exists('compliant',$line)&&is_bool($line['compliant']),
                    'Confirm compliance for each RFQ item',422);
                $next[$pl]=['pr_line_id'=>$pl,'offered_price'=>moneyString($price),
                    'rfq_quantity'=>qtyString(qtyMillis($byId[$pl]['quantity'])),
                    'compliant'=>$line['compliant']?1:0];
            }
            ksort($next,SORT_NUMERIC);
            $oldSorted=[];foreach($before as $line)$oldSorted[(int)$line['pr_line_id']]=[
                'pr_line_id'=>(int)$line['pr_line_id'],
                'offered_price'=>moneyString(moneyCents($line['offered_price'])),
                'rfq_quantity'=>qtyString(qtyMillis($line['rfq_quantity'])),
                'compliant'=>(int)$line['compliant']];
            ksort($oldSorted,SORT_NUMERIC);
            demand($oldSorted!==$next || $date!==(string)$quote['offered_at'] || $evidence!==(string)$quote['evidence_note'],
                'No quotation changes to save',409);
            q($this->db,'UPDATE supplier_quotes SET offered_at=?,evidence_note=? WHERE id=?',[$date,$evidence,$id]);
            foreach($next as $pl=>$row){
                q($this->db,'UPDATE quote_lines SET offered_price=?,compliant=? WHERE supplier_quote_id=? AND pr_line_id=?',
                    [$row['offered_price'],$row['compliant'],$id,$pl]);
                // A no-op UPDATE can report zero affected rows. Validate full inventory before and after.
            }
            $after=q($this->db,'SELECT pr_line_id,offered_price,rfq_quantity,compliant FROM quote_lines WHERE supplier_quote_id=? ORDER BY pr_line_id',[$id])->fetchAll();
            demand(count($after)===count($next),'Quotation correction did not preserve its lines',500);
            $this->audit($org,'CORRECTED','QUOTE',$id,[
                'reason'=>trim((string)($d['reason']??''))?:'Corrected saved supplier quotation',
                'supplier_id'=>(int)$quote['supplier_id'],'rfq_id'=>(int)$rfq['id'],
                'before'=>['offered_at'=>$quote['offered_at'],'evidence_note'=>$quote['evidence_note'],'lines'=>$before],
                'after'=>['offered_at'=>$date,'evidence_note'=>$evidence,'lines'=>$after]]);
            return ['id'=>$id,'rfq_id'=>(int)$rfq['id'],'edit_token'=>$this->quotationEditToken($id)];
        });
    }
    /** The verified quotation totals are measured in cent-thousandths (cents * quantity-millis),
     * avoiding floating-point ties and requiring all selected RFQ lines to be compliant. */
    private function eligibleRfqQuoteTotals(int $rfqId,int $prId): array {
        $expected=[];
        foreach($this->rfqSelectedLines($rfqId,$prId) as $line){
            $id=(int)$line['pr_line_id'];
            demand(!isset($expected[$id]),'RFQ has duplicate item lines',409);
            $expected[$id]=qtyMillis($line['quantity']);
        }
        demand(count($expected)>0,'RFQ has no item lines',409);
        $rows=q($this->db,'SELECT sq.id AS quote_id,sq.supplier_id,l.pr_line_id,l.offered_price,l.rfq_quantity,l.compliant FROM supplier_quotes sq LEFT JOIN quote_lines l ON l.supplier_quote_id=sq.id WHERE sq.rfq_id=? ORDER BY sq.id,l.pr_line_id',[$rfqId])->fetchAll();
        $quotes=[];
        foreach($rows as $r){
            $qid=(int)$r['quote_id'];$supplier=(int)$r['supplier_id'];
            if(!isset($quotes[$qid]))$quotes[$qid]=['supplier_id'=>$supplier,'total'=>0,'valid'=>true,'seen'=>[]];
            $qv=&$quotes[$qid];$item=(int)($r['pr_line_id']??0);
            if($item===0||!isset($expected[$item])||isset($qv['seen'][$item])||(int)$r['compliant']!==1){$qv['valid']=false;unset($qv);continue;}
            $qv['seen'][$item]=true;
            try{
                $qty=qtyMillis($r['rfq_quantity']??'');$price=moneyCents($r['offered_price']);
                if($qty!==$expected[$item]||$price<=0||$qty<=0||$price>intdiv(PHP_INT_MAX,$qty)){
                    $qv['valid']=false;
                }else{
                    $value=$price*$qty;
                    if($qv['total']>PHP_INT_MAX-$value)$qv['valid']=false;
                    else $qv['total']+=$value;
                }
            }catch(Throwable $e){$qv['valid']=false;}
            unset($qv);
        }
        $totals=[];
        foreach($quotes as $quote){
            if($quote['valid']&&count($quote['seen'])===count($expected)){
                $supplier=$quote['supplier_id'];
                if(!isset($totals[$supplier])||$quote['total']<$totals[$supplier])$totals[$supplier]=$quote['total'];
            }
        }
        return $totals;
    }
    public function createAward(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org);return $this->tx(function()use($org,$d){
        $pr=$this->record('purchase_requests',idOf($d,'pr_id'),$org,true);demand($pr['status']==='FINALIZED','Finalized PR required');
        $rfq=$this->record('rfqs',idOf($d,'rfq_id'),$org,true);demand((int)$rfq['pr_id']===(int)$pr['id'],'RFQ must belong to PR');
        demand($rfq['status']==='FINALIZED','Active RFQ required');
        $supplier=idOf($d,'supplier_id');$note=requiredString($d,'determination_note',4000);
        // The RFQ is row-locked above inside this same transaction. Every award
        // creator serializes on that lock before checking for a previous award,
        // preventing concurrent requests from issuing two award numbers to the
        // same RFQ/supplier. Keep old/voided rows accountable; replacements need
        // a separate audited exception process, not another ordinary award.
        demand(one($this->db,'SELECT id FROM awards WHERE organization_id=? AND rfq_id=? AND supplier_id=? LIMIT 1',
            [$org,$rfq['id'],$supplier])===null,
            'This supplier already has an award for the selected RFQ. Open the existing Abstract / Award instead of recording a duplicate.',409);
        $totals=$this->eligibleRfqQuoteTotals((int)$rfq['id'],(int)$pr['id']);
        demand(isset($totals[$supplier]),'Selected supplier quotation must cover all RFQ items and be compliant',409);
        $transaction=one($this->db,'SELECT method FROM procurement_transactions WHERE id=? AND organization_id=?',[$pr['transaction_id'],$org]);
        demand($transaction!==null,'Procurement transaction not found',409);
        $best=min($totals);
        $higher=$totals[$supplier]>$best;
        if($higher&&$transaction['method']==='RFQ_CANVASS'){
            demand(($d['higher_bid_exception']??false)===true,
                'The selected offer is higher than the lowest compliant quotation. Explicitly confirm an exception.',409);
            demand(mbSafeLen(trim($note))>=20,
                'Explain the documented reason for selecting a higher-priced compliant quotation (at least 20 characters).',409);
        }
        $id=$this->insert('awards',['organization_id'=>$org,'pr_id'=>$pr['id'],'rfq_id'=>$rfq['id'],'supplier_id'=>$supplier,'determination_note'=>$note,'created_by'=>$this->user['id']]);$n=$this->registered($org,'AWARD',$id);q($this->db,'UPDATE awards SET display_number=? WHERE id=?',[$n,$id]);$this->link($org,'PR',(int)$pr['id'],'AWARD',$id);$this->snapshot($org,'AWARD',$id,['pr'=>$pr['id'],'supplier'=>$supplier,'reason'=>$d['determination_note'],'number'=>$n]);$this->audit($org,'DETERMINED','AWARD',$id,['lowest_compliant_total_units'=>$best,'selected_total_units'=>$totals[$supplier],'higher_bid_exception'=>$higher&&$transaction['method']==='RFQ_CANVASS']);return ['id'=>$id,'number'=>$n];
    });}
    public function createPO(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org);return $this->tx(function()use($org,$d){
        $award=one($this->db,'SELECT * FROM awards WHERE id=? AND organization_id=? FOR UPDATE',[idOf($d,'award_id'),$org]);demand($award!==null&&$award['status']==='FINALIZED','Finalized award required');
        // Lock the parent RFQ as well as the award. Two different historical award
        // rows for the same RFQ/supplier must not create concurrent POs. This
        // lock serializes all PO creation attempts for that RFQ in the workspace.
        $rfq=$this->record('rfqs',(int)$award['rfq_id'],$org,true);demand($rfq['status']==='FINALIZED','Active awarded RFQ required');
        // All numbered POs remain accountable, including CANCELLED ones. A
        // replacement needs a separately documented exception, not a second
        // ordinary po.create request. Query the database, not the 100-row UI list.
        $priorPO=one($this->db,'SELECT po.id,po.display_number,po.status FROM purchase_orders po JOIN awards aw ON aw.id=po.award_id AND aw.organization_id=po.organization_id WHERE po.organization_id=? AND aw.rfq_id=? AND aw.supplier_id=? ORDER BY po.id ASC LIMIT 1 FOR UPDATE',[$org,$rfq['id'],$award['supplier_id']]);
        demand($priorPO===null,
            'A Purchase Order has already been issued for this RFQ and awarded supplier'.($priorPO===null?'':(' ('.($priorPO['display_number']?:'PO #'.$priorPO['id']).'; status '.$priorPO['status'].').')).' Open the existing PO; another ordinary PO is not allowed.',409);
        $quote=one($this->db,'SELECT id FROM supplier_quotes WHERE rfq_id=? AND supplier_id=?',[$rfq['id'],$award['supplier_id']]);demand($quote!==null,'Awarded supplier quotation not found');
        $rfqLines=$this->rfqSelectedLines((int)$rfq['id'],(int)$award['pr_id']);$rfqQty=[];
        foreach($rfqLines as $selected)$rfqQty[(int)$selected['pr_line_id']]=qtyMillis($selected['quantity']);
        $submitted=rowsRequired($d,'lines');$seen=[];
        demand(count($submitted)<=count($rfqQty),'More PO lines than awarded RFQ items');
        $id=$this->insert('purchase_orders',['organization_id'=>$org,'pr_id'=>$award['pr_id'],'award_id'=>$award['id'],'supplier_id'=>$award['supplier_id'],'created_by'=>$this->user['id']]);
        foreach($submitted as $line){$pl=one($this->db,'SELECT * FROM pr_lines WHERE id=? AND pr_id=? FOR UPDATE',[idOf($line,'pr_line_id'),$award['pr_id']]);demand($pl!==null,'Invalid PR line');demand(isset($rfqQty[(int)$pl['id']]),'PO item is not in awarded RFQ');demand(!isset($seen[$pl['id']]),'Duplicate PO item');$seen[$pl['id']]=true;
        $quoted=one($this->db,'SELECT offered_price,compliant FROM quote_lines WHERE supplier_quote_id=? AND pr_line_id=?',[$quote['id'],$pl['id']]);
        demand($quoted!==null && (int)$quoted['compliant']===1,'PO item must have a compliant awarded supplier quotation');
        $qty=qtyMillis($line['quantity']??'');$price=moneyCents($line['unit_price']??'');demand($qty>0&&$price>0,'Positive quantities and prices required');demand($price===moneyCents($quoted['offered_price']),'PO price must match awarded quotation');
        $awardedUsed=one($this->db,"SELECT COALESCE(SUM(l.quantity),0) total FROM po_lines l JOIN purchase_orders po ON po.id=l.po_id WHERE po.award_id=? AND l.pr_line_id=? AND po.status!='CANCELLED'",[$award['id'],$pl['id']]);
        demand($qty+qtyMillis($awardedUsed['total'])<=$rfqQty[(int)$pl['id']],'PO quantity exceeds awarded RFQ balance');$prior=one($this->db,"SELECT COALESCE(SUM(l.quantity),0) total FROM po_lines l JOIN purchase_orders po ON po.id=l.po_id WHERE po.pr_id=? AND l.pr_line_id=? AND po.status!='CANCELLED'",[$award['pr_id'],$pl['id']]);demand(qtyMillis($prior['total'])+$qty<=qtyMillis($pl['quantity']),'PO quantity exceeds PR remaining quantity');$this->insert('po_lines',['po_id'=>$id,'pr_line_id'=>$pl['id'],'item_id'=>$pl['item_id'],'quantity'=>qtyString($qty),'unit_price'=>moneyString($price)]);}
        $n=$this->registered($org,'PO',$id);q($this->db,'UPDATE purchase_orders SET display_number=? WHERE id=?',[$n,$id]);$this->link($org,'AWARD',(int)$award['id'],'PO',$id);$this->snapshot($org,'PO',$id,['award'=>$award['id'],'number'=>$n,'lines'=>q($this->db,'SELECT * FROM po_lines WHERE po_id=?',[$id])->fetchAll()]);$this->audit($org,'FINALIZED','PO',$id);return ['id'=>$id,'number'=>$n];
    });}
    public function createIAR(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        // A checked operator review is required because this workflow creates
        // real receipt/stock events. This is NOT a digital approval/signature.
        demand(($d['review_confirmed']??null)===true,
            'Confirm you reviewed actual delivery dates, inspection dates and quantities before finalizing the IAR',422);
        return $this->tx(function()use($org,$d){
        $po=$this->record('purchase_orders',idOf($d,'po_id'),$org,true);
        demand($po['status']==='FINALIZED','Active PO required');
        $invoiceNo=IarDocumentCorrections::invoice($d['invoice_no']??'');
        $invoiceDate=IarDocumentCorrections::invoiceDate($d['invoice_date']??'');
        $deliveredAt=self::transactionDocumentDate(requiredString($d,'delivered_at',10),'Delivery date');
        $acceptedAt=self::transactionDocumentDate(requiredString($d,'accepted_at',10),'Inspection / acceptance date');
        demand($acceptedAt>=$deliveredAt,'Inspection / acceptance date cannot precede delivery date.');
        // The IAR report date is separately chosen by the preparer. Never infer it from DB inserted_at.
        $iarDate=self::transactionDocumentDate(requiredString($d,'iar_date',10),'IAR date');
        demand($iarDate>=$acceptedAt,'IAR date cannot precede the inspection/acceptance date',422);
        demand($invoiceDate===''||$invoiceDate<=$iarDate,'Invoice date cannot be later than the IAR report date',422);
        require_once __DIR__.'/IarSignatories.php';
        $iarSignatories=IarSignatories::resolve($this->db,$org,$iarDate);
        $autoRegisteredUnits=0;$manualPropertyLines=[];
        $id=$this->insert('iars',['organization_id'=>$org,'po_id'=>$po['id'],'delivered_at'=>$deliveredAt,
            'accepted_at'=>$acceptedAt,'created_by'=>$this->user['id']]);
        foreach(rowsRequired($d,'lines') as $line){$pl=one($this->db,'SELECT * FROM po_lines WHERE id=? AND po_id=? FOR UPDATE',[idOf($line,'po_line_id'),$po['id']]);demand($pl!==null,'PO line mismatch');$del=qtyMillis($line['delivered_qty']??'');$acc=qtyMillis($line['accepted_qty']??'');$rej=$del-$acc;demand($del>0&&$acc>=0&&$rej>=0,'Invalid delivered/accepted quantities');$used=one($this->db,"SELECT COALESCE(SUM(i.delivered_qty),0) quantity FROM iar_lines i JOIN iars r ON r.id=i.iar_id WHERE i.po_line_id=? AND r.status!='CANCELLED'",[$pl['id']]);demand(qtyMillis($used['quantity'])+$del<=qtyMillis($pl['quantity']),'Deliveries exceed ordered quantity');$lineId=$this->insert('iar_lines',['iar_id'=>$id,'po_line_id'=>$pl['id'],'delivered_qty'=>qtyString($del),'accepted_qty'=>qtyString($acc),'rejected_qty'=>qtyString($rej),'accepted_amount'=>moneyString(totalCents(moneyCents($pl['unit_price']),$acc))]);
            // Accepted SERVICES are documentary performance, not physical
            // stock. TEXTBOOK stock is managed in its distinct textbook ledger.
            // PPE and SEMI_EXPENDABLE keep a source lot for the existing locked
            // property-tag registration path, but never appear in issuable
            // SUPPLY stock or pass the RIS issuance guard.
            $itemCategory=one($this->db,'SELECT category FROM item_catalog WHERE id=?',[$pl['item_id']]);
            demand($itemCategory!==null,'PO line catalog classification is unavailable; IAR receipt not posted',409);
            if($acc>0 && in_array($itemCategory['category'],['SUPPLY','PPE','SEMI_EXPENDABLE'],true)){
                $lot=$this->insert('inventory_lots',['organization_id'=>$org,'item_id'=>$pl['item_id'],'iar_line_id'=>$lineId,'acquisition_cost'=>moneyString(totalCents(moneyCents($pl['unit_price']),$acc)),'acquired_on'=>$acceptedAt,'created_by'=>$this->user['id']]);
                $this->insert('stock_events',['organization_id'=>$org,'lot_id'=>$lot,'event_type'=>'RECEIPT','quantity_delta'=>qtyString($acc),'source_type'=>'IAR_LINE','source_id'=>$lineId,'created_by'=>$this->user['id']]);
            }
            // Automate the individually numbered property ledger only for
            // verified, whole-unit PPE/semi-expendable IAR receipts. Receipt,
            // individual property records, and tag reservation share the SAME
            // transaction, so any failure rolls the IAR/receipt back together.
            // Avoid unbounded per-request inserts on shared hosting: older or
            // unusually large receipts remain in the existing safe batch path.
            $autoPlan=IarAutoPropertyPolicy::plan((string)$itemCategory['category'],$acc,$autoRegisteredUnits);
            if($autoPlan['deferred']){
                $manualPropertyLines[]=$lineId;
                $this->audit($org,'IAR_PROPERTY_REGISTRATION_DEFERRED','IAR',$id,
                    ['iar_line_id'=>$lineId,'reason'=>'NON_WHOLE_OR_GT_100_UNIT_TRANSACTION_LIMIT']);
            }elseif($autoPlan['units']>0){
                    $count=$autoPlan['units'];
                    $numbers=PropertyRegistrationNumbers::reserve($this->db,$org,$count);
                    $batchKey=bin2hex(random_bytes(16));
                    $shared=PropertyBatchMetadata::shared([],'VERIFIED_IAR');
                    $unit=PropertyBatchMetadata::unit([]);
                    $review=['source'=>'IAR_AUTO','state'=>'PENDING_REVIEW'];
                    foreach($numbers as $tag){
                        $assetId=$this->insert('assets',[
                            'organization_id'=>$org,'item_id'=>$pl['item_id'],'iar_line_id'=>$lineId,
                            'property_tag'=>$tag,'serial_number'=>'',
                            'acquisition_cost'=>moneyString(moneyCents($pl['unit_price'])),
                            'acquired_on'=>$acceptedAt,'opening_record'=>0,'custodian'=>null,
                            'location'=>'','condition_note'=>'','registration_batch_key'=>$batchKey,
                            'property_profile'=>PropertyBatchMetadata::profile($shared,$unit,$review)
                        ]);
                        $this->audit($org,'IAR_PROPERTY_AUTO_REGISTERED','ASSET',$assetId,
                            ['iar_line_id'=>$lineId,'property_tag'=>$tag,'review_state'=>'PENDING_REVIEW']);
                    }
                    $autoRegisteredUnits+=$count;
            }
        }
        $n=$this->registered($org,'IAR',$id);q($this->db,'UPDATE iars SET display_number=? WHERE id=?',[$n,$id]);$this->link($org,'PO',(int)$po['id'],'IAR',$id);$this->snapshot($org,'IAR',$id,['po'=>$po['id'],'number'=>$n,'iar_date'=>$iarDate,'delivered_at'=>$deliveredAt,'accepted_at'=>$acceptedAt,'invoice_no'=>$invoiceNo,'invoice_date'=>$invoiceDate,
            'iar_signatories'=>$iarSignatories,'lines'=>q($this->db,'SELECT * FROM iar_lines WHERE iar_id=?',[$id])->fetchAll()]);$this->audit($org,'FINALIZED','IAR',$id);return ['id'=>$id,'number'=>$n,'auto_registered_property_units'=>$autoRegisteredUnits,
            'property_review_required'=>$autoRegisteredUnits,'deferred_property_iar_lines'=>$manualPropertyLines];
    });}
    /** Register individually accountable units from a finalized IAR without
     * creating stock events, disbursements, ledger entries or duplicate receipts.
     * Lock the IAR header before its lines/lots, matching cancellation order.
     */
    public function registerIarProperty(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        $candidate=one($this->db,'SELECT il.iar_id FROM iar_lines il JOIN iars ir ON ir.id=il.iar_id WHERE il.id=? AND ir.organization_id=?',[idOf($d,'iar_line_id'),$org]);
        demand($candidate!==null,'IAR line not found in authorized organization',404);
        return $this->tx(function()use($org,$d,$candidate){
            $header=$this->record('iars',(int)$candidate['iar_id'],$org,true);
            demand($header!==null && $header['status']==='FINALIZED','Finalized IAR required for accountable-property registration',409);
            $source=one($this->db,"SELECT il.id,il.accepted_qty,il.po_line_id,ir.accepted_at,ir.status AS iar_status,
                ir.organization_id,po.status AS po_status,pl.item_id,pl.unit_price,ic.category
                FROM iar_lines il JOIN iars ir ON ir.id=il.iar_id
                JOIN purchase_orders po ON po.id=ir.po_id AND po.organization_id=ir.organization_id
                JOIN po_lines pl ON pl.id=il.po_line_id AND pl.po_id=po.id
                JOIN item_catalog ic ON ic.id=pl.item_id
                WHERE il.id=? AND ir.organization_id=? FOR UPDATE",[idOf($d,'iar_line_id'),$org]);
            demand($source!==null && $source['iar_status']==='FINALIZED' && $source['po_status']==='FINALIZED',
                'Finalized IAR and PO required for accountable-property registration',409);
            demand(in_array($source['category'],['PPE','SEMI_EXPENDABLE'],true),
                'Only PPE or semi-expendable IAR items can be registered as accountable property',409);
            $qty=qtyMillis($source['accepted_qty']);
            demand($qty>0 && $qty%1000===0,'Accepted quantity must be a positive whole number of accountable units',409);
            $units=intdiv($qty,1000);
            // Legacy RIS issuance must not be silently converted into property
            // registration. Tagging leaves the stock ledger unchanged; until an
            // approved physical reclassification exists, fail closed if any lot
            // units have already left the physically received stock.
            $lot=one($this->db,'SELECT id FROM inventory_lots WHERE organization_id=? AND iar_line_id=? FOR UPDATE',[$org,$source['id']]);
            demand($lot!==null,'Accepted IAR stock lot unavailable; inventory review required',409);
            // Net on-hand alone is insufficient: a previously issued-and-returned
            // RIS can restore the quantity without restoring accountable custody.
            // Until an explicit reconciliation workflow is approved, register only
            // original accepted receipts with no intervening stock movements.
            $balance=one($this->db,"SELECT
                COALESCE(SUM(quantity_delta),0) AS available,
                COALESCE(SUM(CASE WHEN event_type='RECEIPT' THEN quantity_delta ELSE 0 END),0) AS receipts,
                COALESCE(SUM(CASE WHEN event_type<>'RECEIPT' THEN 1 ELSE 0 END),0) AS changed_events
                FROM stock_events WHERE organization_id=? AND lot_id=?",[$org,$lot['id']]);
            demand(qtyMillis((string)$balance['available'])===$qty &&
                qtyMillis((string)$balance['receipts'])===$qty &&
                (int)$balance['changed_events']===0,
                'Physical stock for this IAR has changed or has prior stock movements. Reconcile any RIS issuance, return or adjustment before property registration',409);
            demand(!isset($d['assets']) && !isset($d['property_tag']),
                'Property numbers are automatic. Refresh the registration form and enter only the number of units.',422);
            $rawCount=$d['unit_count']??null;
            demand((is_int($rawCount)||is_string($rawCount)) &&
                preg_match('/^[1-9][0-9]{0,2}$/D',(string)$rawCount)===1 &&
                (int)$rawCount<=100,'Register between 1 and 100 property units',422);
            $unitCount=(int)$rawCount;
            $already=(int)one($this->db,'SELECT COUNT(*) n FROM assets WHERE organization_id=? AND iar_line_id=?',[$org,$source['id']])['n'];
            demand($already+$unitCount<=$units,'Registration exceeds accepted IAR quantity',409);
            // Descriptive batch/unit details cannot forge PO, IAR or DV source links.
            demand(!isset($d['shared']) || is_array($d['shared']),'Invalid shared property details',422);
            $shared=PropertyBatchMetadata::shared($d['shared']??[],'VERIFIED_IAR');
            $unitRows=PropertyBatchMetadata::units($d['units']??null,$unitCount);
            $batchKey=bin2hex(random_bytes(16));
            // Allocate with a stable all-time counter in this SAME transaction.
            $numbers=PropertyRegistrationNumbers::reserve($this->db,$org,$unitCount);
            $ids=[];
            foreach($numbers as $position=>$tag){
                $unit=$unitRows[$position];
                $serial=$unit['serial_number'];$location=$unit['location'];
                // A real school lock, held by the sequence allocator, serializes this new batch.
                if($serial!=='')demand(one($this->db,'SELECT id FROM assets WHERE organization_id=? AND serial_number=? LIMIT 1',[$org,$serial])===null,
                    'Serial number already registered in this school',409);
                // The database unique index is the final concurrency guard.
                // Translate its duplicate-tag error into an actionable staff message.
                try {
                    $id=$this->insert('assets',[
                        'organization_id'=>$org,'item_id'=>$source['item_id'],'iar_line_id'=>$source['id'],
                        'property_tag'=>$tag,'serial_number'=>$serial,
                        'acquisition_cost'=>moneyString(moneyCents($source['unit_price'])),
                        'acquired_on'=>$source['accepted_at'],'opening_record'=>0,
                        'custodian'=>null,'location'=>$location,'condition_note'=>$unit['condition_note'],
                        'registration_batch_key'=>$batchKey,'property_profile'=>PropertyBatchMetadata::profile($shared,$unit)
                    ]);
                } catch(\PDOException $e) {
                    if($e->getCode()==='23000' && (int)($e->errorInfo[1]??0)===1062)
                        throw new DomainError('Property tag is already registered in this organization: '.$tag,409);
                    throw $e;
                }
                $ids[]=$id;
                $this->audit($org,'IAR_PROPERTY_REGISTERED','ASSET',$id,
                    ['iar_line_id'=>(int)$source['id'],'property_tag'=>$tag,'registration_batch_key'=>$batchKey]);
            }
            return ['asset_ids'=>$ids,'iar_line_id'=>(int)$source['id'],
                'accepted_units'=>$units,'registered_units'=>$already+count($ids),
                'remaining_units'=>$units-$already-count($ids),
                'property_numbers'=>$numbers,'registration_batch_key'=>$batchKey,'numbering_rule'=>'SCHOOL_ID-YYMM-LIFETIME_SEQUENCE',
                'stock_note'=>'Property tagging only: existing IAR stock ledger is unchanged. Custody/physical-stock reconciliation awaits approved policy.'];
        });
    }
    /** A PR/PO reserved by an employee-funded procurement claim cannot also
     * be sent through the normal supplier DV / legacy IAR advance route.
     * Never alter either source in place; finance must resolve its history.
     */
    private function forbidEmployeeProcurementDoubleClaim(int $org,string $source,int $id): void {
        demand(in_array($source,['PR','PO'],true) && $id>0,'Invalid procurement source identity',409);
        $found=one($this->db,'SELECT config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',
            [$org,'ERS1_'.$source.'_'.$id]);
        if($found!==null){
            // A procurement-source marker protects against double payment even
            // BEFORE its employee claim is attached to a DV. Explain that
            // distinction; NEVER delete the marker or create a supplier DV.
            $record=json_decode((string)$found['config_json'],true);
            $claimId=is_array($record)?(int)($record['claim_id']??0):0;
            $label=$claimId>0?'ER-'.$claimId:'an existing employee reimbursement claim';
            $where='Open Disbursements > Prepare DV > Employee reimbursements and review that claim';
            if($claimId>0){
                $link=EmployeeClaims::reservation($this->db,$org,$claimId);
                if($link!==null && $link['active'])
                    $where='Already linked to DV #'.$link['dv_id'].'. Review that voucher in the DV register';
                elseif(ReimbursementFinalization::load($this->db,$org,'O',$claimId)!==null)
                    $where='Finalized but not linked to a DV. Select '.$label.' in Employee reimbursements (not Regular / PO)';
                else
                    $where='Finish documentary review and Finalize '.$label.' under Employee reimbursements, then select it for a DV';
            }
            throw new DomainError('This '.$source.' is reserved by employee-paid claim '.$label.'. '.$where.'. A separate supplier/advance DV is blocked to prevent duplicate payment.',409);
        }
    }
    public function createAdvance(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org);return $this->tx(function()use($org,$d){
        $line=one($this->db,'SELECT l.*,po.organization_id FROM iar_lines l JOIN iars i ON i.id=l.iar_id JOIN purchase_orders po ON po.id=i.po_id WHERE l.id=? AND i.status=\'FINALIZED\' FOR UPDATE',[idOf($d,'iar_line_id')]);demand($line!==null&&(int)$line['organization_id']===$org,'Accepted IAR required');
        $iarPo=one($this->db,'SELECT i.po_id FROM iar_lines l JOIN iars i ON i.id=l.iar_id WHERE l.id=?',[$line['id']]);
        demand($iarPo!==null,'IAR procurement identity unavailable',409);
        $this->forbidEmployeeProcurementDoubleClaim($org,'PO',(int)$iarPo['po_id']);
        $source=requiredString($d,'source',30);demand(in_array($source,['PERSONAL','OTHER_SCHOOL_FUND'],true),'Invalid advance source');$amount=moneyCents($d['amount']??'');demand($amount>0&&$amount<=moneyCents($line['accepted_amount']),'Advance exceeds eligible acceptance');
        $already=one($this->db,'SELECT COALESCE(SUM(amount),0) n FROM advances WHERE iar_line_id=?',[$line['id']]);demand(moneyCents($already['n'])+$amount<=moneyCents($line['accepted_amount']),'Advances exceed accepted amount');
        $id=$this->insert('advances',['organization_id'=>$org,'iar_line_id'=>$line['id'],'original_payer'=>requiredString($d,'original_payer',180),'source'=>$source,'amount'=>moneyString($amount),'paid_at'=>self::transactionDocumentDate(requiredString($d,'paid_at',10),'Payment date'),'evidence_reference'=>requiredString($d,'evidence_reference',255),'created_by'=>$this->user['id']]);$this->audit($org,'RECORDED','ADVANCE',$id);return ['id'=>$id];
    });}
    /**
     * One DV per authoritative source document. Existing records are never
     * deleted, recreated or silently reused. Legacy pre-PO-link Regular DVs
     * are also discovered through their accepted IAR allocation lines.
     *
     * Call this only after locking the source row in a write transaction, or
     * from a read-only eligibility listing. The caller must use READ COMMITTED
     * on write requests so the existence check sees newly committed DVs.
     */
    private function sourceVouchers(int $org,string $source,int $sourceId,?int $excludeId=null): array {
        $condition=match($source){
            'PO'=>"v.dv_type='REGULAR' AND (EXISTS (SELECT 1 FROM document_links l
                WHERE l.organization_id=v.organization_id AND l.source_type='PO' AND l.source_id=?
                    AND l.target_type='DV' AND l.target_id=v.id)
                OR EXISTS (SELECT 1 FROM dv_allocation_lines a
                    JOIN iar_lines il ON il.id=a.iar_line_id
                    JOIN iars i ON i.id=il.iar_id
                    WHERE a.dv_id=v.id AND i.po_id=?))",
            'PR'=>"EXISTS (SELECT 1 FROM dv_allocation_lines a
                WHERE a.dv_id=v.id AND a.pr_id=?)",
            'ADVANCE'=>"v.dv_type='REIMBURSEMENT' AND EXISTS (SELECT 1 FROM dv_allocation_lines a
                WHERE a.dv_id=v.id AND a.advance_id=?)",
            default=>throw new DomainError('Invalid DV source type',400)
        };
        $ids=$source==='PO'?[$sourceId,$sourceId]:[$sourceId];
        $sql="SELECT v.id,v.status,v.dv_type FROM disbursement_vouchers v
            WHERE v.organization_id=? AND v.id<>? AND ($condition) ORDER BY v.id ASC LIMIT 20";
        return q($this->db,$sql,array_merge([$org,$excludeId??0],$ids))->fetchAll();
    }
    private function forbidSecondSourceVoucher(int $org,string $source,int $id,?int $excludeId=null,bool $activeOnly=false): void {
        $found=$this->sourceVouchers($org,$source,$id,$excludeId);
        if($activeOnly)$found=array_values(array_filter($found,static fn($v)=>$v['status']!=='CANCELLED'));
        if(!$found)return;
        $refs=implode(', ',array_map(static fn($v)=>'DV #'.$v['id'].' ('.$v['status'].')',$found));
        $label=$source==='PO'?'Purchase Order':($source==='PR'?'Purchase Request':'recorded advance');
        $action=count($found)===1&&$found[0]['status']==='DRAFT'
            ? 'Open the existing DV in Disbursements and use Edit Draft DV.'
            : 'Review the linked vouchers with the finance administrator before proceeding.';
        demand(false,'Another DV already references this '.$label.': '.$refs.'. '.$action,409);
    }
    private function eligibleDvSourceRows(int $org,string $source,array $rows,string $idKey): array {
        foreach($rows as &$row){
            $found=$this->sourceVouchers($org,$source,(int)$row[$idKey]);
            $row['existing_dvs']=array_map(static fn($v)=>['id'=>(int)$v['id'],'status'=>$v['status']],$found);
        }
        unset($row);
        return $rows;
    }
    private function guardDvFinalizeSource(int $org,array $dv): void {
        if($this->taxTransferRecord($org,(int)$dv['id'])!==null){
            $this->taxTransferValidate($org,$dv,true);return;
        }
        if($dv['dv_type']==='TRAVEL_REIMBURSEMENT'){$this->validateEmployeeDvForPayment($org,$dv);return;}
        if($dv['dv_type']==='REGULAR'){
            $sources=q($this->db,"SELECT DISTINCT i.po_id FROM dv_allocation_lines a
                JOIN iar_lines il ON il.id=a.iar_line_id JOIN iars i ON i.id=il.iar_id
                WHERE a.dv_id=?",[$dv['id']])->fetchAll();
            demand(count($sources)===1,'Regular DV must refer to one PO; finance review required',409);
            $this->forbidSecondSourceVoucher($org,'PO',(int)$sources[0]['po_id'],(int)$dv['id'],true);
        }elseif($dv['dv_type']==='DIRECT'){
            $sources=q($this->db,'SELECT DISTINCT pr_id FROM dv_allocation_lines WHERE dv_id=?',[$dv['id']])->fetchAll();
            demand(count($sources)===1,'Direct DV must refer to one PR; finance review required',409);
            $this->forbidSecondSourceVoucher($org,'PR',(int)$sources[0]['pr_id'],(int)$dv['id'],true);
        }elseif($dv['dv_type']==='REIMBURSEMENT'){
            $sources=q($this->db,'SELECT DISTINCT advance_id FROM dv_allocation_lines WHERE dv_id=?',[$dv['id']])->fetchAll();
            demand(count($sources)===1,'Reimbursement DV must refer to one advance; finance review required',409);
            $this->forbidSecondSourceVoucher($org,'ADVANCE',(int)$sources[0]['advance_id'],(int)$dv['id'],true);
        }
    }
    /** The Division controls each school-class / fund-source tax disposition.
     * Provisional classification or an unconfigured source is NOT permission to
     * infer REMIT. This read path does not create credits or bank receipts. */
    private function regularDvTaxPolicy(int $org,int $fundId,bool $required=false,bool $lockDivision=false): array {
        $fund=$this->record('funds',$fundId,$org);
        $division=$this->divisionId($org);
        if($lockDivision){
            $lock=one($this->db,"SELECT id FROM organizations WHERE id=? AND kind='DIVISION' LOCK IN SHARE MODE",[$division]);
            demand($lock!==null,'Division funding configuration cannot be verified.',409);
        }
        $classification=$this->currentSchoolClass($org,self::todayManila());
        $policy=$classification===null
            ?['policy'=>'NOT_CONFIGURED','version_id'=>0,'effective_from'=>null,'authority'=>'']
            :$this->effectiveFundPolicy($division,(string)$fund['source_code'],$classification['classification'],self::todayManila());
        $disposition=match($policy['policy']){
            'DIVISION_SETTLES_UTILIZABLE'=>'SCHOOL_UTILIZATION',
            'REMIT_TO_DIVISION'=>'REMIT',
            // IU pays BIR directly, rather than passing school withholding to Division.
            // Both are saved as REMIT components (not SCHOOL_UTILIZATION).
            'IUS_REMIT_DIRECT_BIR'=>'REMIT',
            default=>null,
        };
        $active=true;
        if($disposition==='SCHOOL_UTILIZATION'){
            $catalog=$this->divisionFundSourceList($division);$active=false;
            foreach($catalog['sources'] as $row)if(($row['code']??null)===$fund['source_code'] && ($row['active']??false)===true){$active=true;break;}
        }
        $ready=$disposition!==null && $active;
        if($required)demand($ready,
            $classification===null
                ?'School IUS/Non-IUS implementation status must be verified by Division before preparing a taxable Regular DV.'
                :('Withholding disposition is not configured/active for fund source "'.$fund['source_code'].'" and '.$classification['classification'].'. Ask Division to configure the effective policy before preparing or finalizing this DV.'),409);
        return ['mode'=>(string)$policy['policy'],'disposition'=>$ready?$disposition:null,
            'remittance_destination'=>$ready?match($policy['policy']){
                'IUS_REMIT_DIRECT_BIR'=>'BIR',
                'REMIT_TO_DIVISION'=>'DIVISION',
                default=>null,
            }:null,
            'fund_source_code'=>(string)$fund['source_code'],
            'school_classification'=>$classification['classification']??null,
            'school_classification_version'=>(int)($classification['version_id']??0),
            'version_id'=>(int)$policy['version_id'],'effective_from'=>$policy['effective_from'],
            'authority'=>(string)$policy['authority'],'ready'=>$ready,
            'payment_credit_uacs'=>(string)($policy['payment_credit_uacs']??''),
            'withholding_liability_uacs'=>(string)($policy['withholding_liability_uacs']??'')];
    }
    /** Optimistic lock on each real saved component, not browser-computed taxes. */
    private static function dvDispositionToken(int $dvId,array $components): string {
        return hash('sha256',json_encode(['dv_id'=>$dvId,'tax'=>array_map(
            static fn($t)=>[(int)$t['id'],(string)$t['atc_code'],(string)$t['tax_base'],
                (string)$t['amount'],(string)$t['disposition'],(string)($t['fund_id']??'')],$components)],JSON_THROW_ON_ERROR));
    }
    /** Draft-only administrative correction, NEVER a tax calculation edit. */
    public function correctDraftDvTaxDisposition(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        foreach(array_keys($d) as $key)demand(in_array($key,['organization_id','dv_id','expected_token','reason'],true),
            'Tax disposition is determined by the Division fund policy; rates, bases, amounts and accounts cannot be submitted.',400);
        return $this->financialTx('dv.tax.disposition.correct',$d,function()use($org,$d){
            $dv=$this->record('disbursement_vouchers',idOf($d,'dv_id'),$org,true);
            demand($dv['status']==='DRAFT' && empty($dv['display_number']) && $dv['dv_type']==='REGULAR',
                'Only unnumbered Regular Draft DVs may correct the tax disposition here.',409);
            demand(one($this->db,'SELECT id FROM checks WHERE dv_id=? LIMIT 1',[$dv['id']])===null,
                'Voucher has a recorded check: use a separately documented financial correction.',409);
            demand(one($this->db,'SELECT e.id FROM tax_disposition_events e JOIN withholding_components w ON w.id=e.component_id WHERE w.dv_id=? LIMIT 1',[$dv['id']])===null,
                'Voucher has tax settlement activity; a simple draft correction cannot change its disposition.',409);
            $taxes=q($this->db,'SELECT * FROM withholding_components WHERE dv_id=? ORDER BY id FOR UPDATE',[$dv['id']])->fetchAll();
            demand(count($taxes)>0,'This DV has no withholding components to correct.',409);
            $expected=requiredString($d,'expected_token',64);
            demand(hash_equals(self::dvDispositionToken((int)$dv['id'],$taxes),$expected),
                'Withholding disposition changed elsewhere. Reopen the DV and review again.',409);
            $reason=requiredString($d,'reason',500);
            demand(mbSafeLen($reason)>=12,'Enter an explanation of at least 12 characters.',409);
            $policy=$this->regularDvTaxPolicy($org,(int)$dv['fund_id'],true,true);
            $before=[];$after=[];
            foreach($taxes as $tax){
                $prior=(string)$tax['disposition'];$next=(string)$policy['disposition'];
                demand(in_array($prior,['REMIT','SCHOOL_UTILIZATION'],true),'Unknown saved disposition requires accountant review.',409);
                $before[]=['id'=>(int)$tax['id'],'atc'=>$tax['atc_code'],'disposition'=>$prior];
                $after[]=['id'=>(int)$tax['id'],'atc'=>$tax['atc_code'],'disposition'=>$next];
                if($prior!==$next)q($this->db,'UPDATE withholding_components SET disposition=?,fund_id=? WHERE id=? AND dv_id=?',
                    [$next,$next==='SCHOOL_UTILIZATION'?$dv['fund_id']:null,$tax['id'],$dv['id']]);
            }
            demand($before!==$after,'DV withholding already matches the effective Division policy; no change was needed.',409);
            $evidence=['before'=>$before,'after'=>$after,'reason'=>$reason,'policy'=>$policy,
                'gross_unchanged'=>$dv['gross'],'deductions_unchanged'=>$dv['deductions'],'net_unchanged'=>$dv['net'],
                'document_stage'=>'DRAFT_NOT_ISSUED'];
            $this->snapshot($org,'DV',(int)$dv['id'],$evidence,'Draft withholding disposition corrected per Division fund policy');
            $this->audit($org,'DRAFT_DV_TAX_DISPOSITION_CORRECTED','DV',(int)$dv['id'],$evidence);
            return ['dv_id'=>(int)$dv['id'],'status'=>'DRAFT','disposition'=>$policy['disposition'],
                'policy_version_id'=>$policy['version_id'],'component_count'=>count($after),
                'amounts_unchanged'=>true];
        });
    }
    /** PO-wide withholding is generated from the currently configured supplier
     * profile and effective ATC versions. The caller must not supply arbitrary
     * codes, rates, amounts or a client-computed taxable base.
     * Standard VAT-inclusive invoices derive VAT from a Division-configured
     * rate; other treatments require documented invoice VAT exceptions.
     */
    private function regularPoTaxPlan(array $supplier, string $profile, int $gross, mixed $invoiceVat,
        int $org, string $vatMode='STANDARD', string $exceptionReason=''): array {
        $profiles=$this->supplierProfileTypes();
        demand(in_array($profile,$profiles,true),'Select an applicable supplier tax profile',409);
        $flag=$this->supplierProfileFlagColumn($profile);
        $active=$supplier[$flag]??null;
        $vat=(string)($supplier['vat_status']??'UNKNOWN');
        demand(in_array($vat,['VAT','NON_VAT'],true),'Supplier VAT registration must be verified before automatic withholding',409);
        $supplierBusiness=($supplier['business_classification']??'')==='COMMERCIAL'?'CORPORATE':($supplier['business_classification']??'');
        $today=self::todayManila();
        // Legacy supplier links with a blank profile_type use the ATC's
        // historical supply_type, matching the current supplier directory.
        if(in_array($profile,['GOODS','SERVICES'],true)){
            $where="COALESCE(NULLIF(sa.profile_type,''),a.supply_type) IN (?, 'BOTH')";
            $args=[$supplier['supplier_id']??$supplier['id'],$profile];
        }else{
            $column=strtolower($profile).'_profile';
            $where="COALESCE(sa.$column,0)=1";
            $args=[$supplier['supplier_id']??$supplier['id']];
        }
        demand($active===null || (int)$active===1,'Selected supplier profile is not active',409);
        $rows=q($this->db,"SELECT a.code,a.active,a.tax_kind,a.business_type,a.vat_scope,a.supply_type,
                 v.id rate_version_id,v.rate,v.starts,v.ends
                 FROM supplier_atcs sa JOIN tax_atcs a ON a.code=sa.atc_code
                 LEFT JOIN tax_rate_versions v ON v.atc_code=a.code AND v.starts<=? AND (v.ends IS NULL OR v.ends>=?)
                 WHERE sa.supplier_id=? AND $where ORDER BY a.code,v.id",
                 array_merge([$today,$today],$args))->fetchAll();
        demand(count($rows)>0,'This supplier profile has no configured ATCs; ask Division to complete the supplier profile',409);
        demand(count($rows)<=30,'Too many withholding components for one DV; accountant review required',409);
        demand($gross>0,'PO has no accepted unpaid value',409);
        $vatCents=0;
        $standardVat=$this->standardVatRate($this->divisionId($org),$today);
        demand(in_array($vatMode,['STANDARD','INVOICE_OVERRIDE'],true),'Choose a supported invoice VAT treatment',409);
        if($vat==='VAT'){
            if($vatMode==='STANDARD'){
                demand($invoiceVat===null || $invoiceVat==='','Standard VAT-inclusive invoice is calculated from the Division rate; do not supply a manual VAT amount',409);
                $hundredths=(int)str_replace('.','',$standardVat['rate_percent']);
                // Inclusive VAT component = gross x rate/(100 + rate), rounded
                // to the nearest cent using integer arithmetic (no floats).
                $denom=10000+$hundredths;
                $vatCents=intdiv($gross*$hundredths+intdiv($denom,2),$denom);
            }else{
                demand(strlen(trim($exceptionReason))>=10 && mbSafeLen($exceptionReason)<=500,
                    'Explain the exceptional VAT treatment and cite the supporting invoice (at least 10 characters)',409);
                demand(is_string($invoiceVat) && preg_match('/^\d{1,15}\.\d{2}$/D',$invoiceVat)===1,
                    'Enter the VAT component confirmed on the exceptional invoice',409);
                $vatCents=moneyCents($invoiceVat);
            }
            demand($vatCents>=0 && $vatCents<$gross,'Invoice VAT must be less than PO payable gross',409);
        }else{
            demand($vatMode==='STANDARD' && ($invoiceVat===null || $invoiceVat==='' || $invoiceVat==='0.00'),
                'Non-VAT supplier cannot claim an invoice VAT component or VAT exception',409);
        }
        $fundPolicy=$this->regularDvTaxPolicy($org,(int)$supplier['fund_id'],true);
        $base=$gross-$vatCents;$components=[];$seen=[];$total=0;$kindCounts=['VAT'=>0,'EWT'=>0];
        foreach($rows as $r){
            $code=(string)$r['code'];
            demand(!isset($seen[$code]),'Overlapping effective rates for ATC '.$code.'; ask Division to correct the rate history',409);
            $seen[$code]=true;
            demand((int)$r['active']===1,'Inactive supplier ATC '.$code.' cannot be used for a new DV',409);
            demand(in_array($r['tax_kind'],['VAT','EWT'],true),'Supplier ATC '.$code.' needs an approved withholding type',409);
            $kindCounts[$r['tax_kind']]++;
            demand($kindCounts[$r['tax_kind']]<=1,
                'Multiple '.$r['tax_kind'].' ATCs are assigned to this one supplier profile; have Division resolve which ATC applies before automatic withholding',409);
            demand($vat!=='VAT'||$vatCents>0||$r['tax_kind']!=='VAT',
                'A VAT-registered supplier with no invoice VAT cannot automatically use a VAT-withholding ATC; request tax-profile review',409);
            demand($r['rate_version_id']!==null,'Supplier ATC '.$code.' has no effective tax rate on '.$today,409);
            demand(($r['business_type']??'BOTH')==='BOTH' || $r['business_type']===$supplierBusiness,
                'Supplier ATC '.$code.' does not match the supplier business classification',409);
            demand(($r['vat_scope']??'ANY')==='ANY' || $r['vat_scope']===$vat,
                'Supplier ATC '.$code.' does not match VAT registration status',409);
            $tax=taxCents($base,(string)$r['rate']);
            demand($tax>0,'Withholding for ATC '.$code.' rounds to zero; accountant review required',409);
            $total+=$tax;
            $components[]=['tax_kind'=>$r['tax_kind'],'atc_code'=>$code,'rate_version_id'=>(int)$r['rate_version_id'],
                'rate'=>(string)$r['rate'],'tax_base'=>moneyString($base),'amount'=>moneyString($tax),'disposition'=>$fundPolicy['disposition']];
        }
        demand($total<=$gross,'Withholding exceeds gross PO payable amount',409);
        return ['profile'=>$profile,'vat_status'=>$vat,'gross'=>moneyString($gross),
            'vat_mode'=>$vatMode,'vat_rate_percent'=>$standardVat['rate_percent'],
            'vat_rate_version'=>(int)$standardVat['version_id'],
            'vat_system_default'=>$standardVat['system_default'],
            'vat_exception_reason'=>$vatMode==='INVOICE_OVERRIDE'?$exceptionReason:'',
            'invoice_vat'=>moneyString($vatCents),'tax_base'=>moneyString($base),
            'deductions'=>moneyString($total),'net'=>moneyString($gross-$total),'components'=>$components,
            'fund_policy'=>$fundPolicy,
            'review_notice'=>'The saved withholding disposition follows the Division fund-source policy. Due to BIR remains a liability; utilization is not a BIR remittance, actual cash expenditure or new fund allocation.'];
    }
    /** Do not apply Services/Rental/etc tax ATCs to a PO containing goods, or
     * Goods ATCs to a services PO. Mixed POs require a line-aware tax basis
     * design and must not silently use a single whole-PO profile. */
    private function validatePoTaxCategory(int $poId,string $profile): void {
        $counts=one($this->db,"SELECT
            COALESCE(SUM(CASE WHEN c.category='SERVICE' THEN 1 ELSE 0 END),0) services,
            COALESCE(SUM(CASE WHEN c.category<>'SERVICE' THEN 1 ELSE 0 END),0) goods
            FROM po_lines l JOIN item_catalog c ON c.id=l.item_id WHERE l.po_id=?",[$poId]);
        $goods=(int)$counts['goods'];$services=(int)$counts['services'];
        demand($goods+$services>0,'PO has no item classification for tax review',409);
        demand(!($goods>0&&$services>0),
            'PO contains both goods and services; automatic whole-PO withholding requires an accountant-reviewed split of taxable bases',409);
        demand(($goods>0&&$profile==='GOODS')||($services>0&&$profile!=='GOODS'),
            'Selected supplier tax profile does not match the PO item classification',409);
    }
    /** Read-only preview; server repeats the same rate calculation inside
     * createDV's locked transaction before saving the draft. */
    public function previewRegularDvTax(array $d): array {
        $org=$this->org($d);
        $po=one($this->db,"SELECT po.id po_id,po.status,pr.status pr_status,pr.fund_id,s.*
            FROM purchase_orders po JOIN purchase_requests pr ON pr.id=po.pr_id
            JOIN suppliers s ON s.id=po.supplier_id
            WHERE po.id=? AND po.organization_id=?",[idOf($d,'po_id'),$org]);
        demand($po!==null&&$po['status']==='FINALIZED'&&$po['pr_status']==='FINALIZED','Finalized PO not available in this workspace',404);
        $accepted=one($this->db,"SELECT COALESCE(SUM(l.accepted_amount),0) total FROM iar_lines l JOIN iars i ON i.id=l.iar_id
            WHERE i.po_id=? AND i.status='FINALIZED'",[$po['po_id']]);
        $committed=one($this->db,"SELECT COALESCE(SUM(a.amount),0) total FROM dv_allocation_lines a
            JOIN disbursement_vouchers v ON v.id=a.dv_id JOIN iar_lines l ON l.id=a.iar_line_id
            JOIN iars i ON i.id=l.iar_id WHERE i.po_id=? AND v.status='FINALIZED'",[$po['po_id']]);
        $gross=moneyCents($accepted['total'])-moneyCents($committed['total']);
        $profile=requiredString($d,'profile_type',20);
        $this->validatePoTaxCategory((int)$po['po_id'],$profile);
        return $this->regularPoTaxPlan($po,$profile,$gross,$d['invoice_vat']??null,$org,
            (string)($d['vat_mode']??'STANDARD'),(string)($d['exception_reason']??''));
    }
    /** Combined travel and out-of-pocket employee DV verification. The
     * existing travel-only validator intentionally does not cover ERL1 claims.
     */
    private function validateEmployeeDvForPayment(int $org,array $dv): array {
        demand($dv['dv_type']==='TRAVEL_REIMBURSEMENT',
            'Employee source validation called for another DV type',409);
        $travel=q($this->db,'SELECT allocation_line_id FROM travel_dv_claim_links
            WHERE organization_id=? AND dv_id=? ORDER BY claim_id',[$org,$dv['id']])->fetchAll();
        $allocationIds=[];$amount=0;
        if($travel){
            $links=$this->validateTravelDvLinks($org,$dv,false);
            foreach($links as $l){
                $allocationIds[(int)$l['allocation_line_id']]=true;
                $amount+=moneyCents((string)$l['allocated_amount']);
            }
        }
        $other=EmployeeClaims::validateDvForPayment($this->db,$org,(int)$dv['id'],
            (int)$dv['fund_id'],(string)$dv['payee']);
        foreach($other['allocation_ids'] as $id){
            demand(!isset($allocationIds[$id]),'Employee DV allocation belongs to more than one source',409);
            $allocationIds[$id]=true;
        }
        $amount+=$other['amount_cents'];
        demand(count($allocationIds)>0 && count($allocationIds)<=20,
            'Employee reimbursement has no verified payable claims',409);
        $all=q($this->db,'SELECT id,amount FROM dv_allocation_lines WHERE dv_id=? FOR UPDATE',[$dv['id']])->fetchAll();
        demand(count($all)===count($allocationIds),'Unverified reimbursement allocation; request finance review',409);
        $sum=0;
        foreach($all as $row){
            demand(isset($allocationIds[(int)$row['id']]),'Unlinked employee reimbursement allocation',409);
            $sum+=moneyCents((string)$row['amount']);
        }
        demand($sum===$amount && $sum===moneyCents((string)$dv['gross']) &&
            moneyCents((string)$dv['net'])===$sum && moneyCents((string)$dv['deductions'])===0,
            'Employee reimbursement DV gross, net or linked-source totals differ; remain draft',409);
        return ['travel_links'=>count($travel),'out_of_pocket_claim_ids'=>$other['claim_ids'],
            'verified_amount'=>moneyString($amount)];
    }

    /** Suggested documentary description, not an accounting classification.
     * Counts distinct accepted PO line types linked to this DV, not units. */
    private function dvSuggestedParticulars(int $dvId,string $type,string $gross): string {
        $pr=one($this->db,"SELECT pr.id,pr.purpose FROM dv_allocation_lines a
            JOIN purchase_requests pr ON pr.id=a.pr_id WHERE a.dv_id=? ORDER BY a.id LIMIT 1",[$dvId]);
        $purpose=trim((string)($pr['purpose']??''));
        if($purpose==='')return '';
        $purpose=preg_replace('/\s+/u',' ',$purpose);
        $count=0;
        if($type==='DIRECT'){
            $row=one($this->db,'SELECT COUNT(*) n FROM pr_lines WHERE pr_id=?',[(int)$pr['id']]);
            $count=(int)($row['n']??0);
        }else{
            $row=one($this->db,"SELECT COUNT(DISTINCT l.po_line_id) n FROM dv_allocation_lines a
                JOIN iar_lines l ON l.id=a.iar_line_id WHERE a.dv_id=?",[$dvId]);
            $count=(int)($row['n']??0);
        }
        $amount='₱'.number_format((float)$gross,2,'.',',');
        $countLabel=$count>0?' ('.$count.' '.($count===1?'Item':'Items').')':'';
        $lead=$type==='REIMBURSEMENT'?'Reimbursement of the following:':'Payment of:';
        return $lead."\n- ".$purpose.$countLabel.' - '.$amount;
    }
    /** Draft-only narrative correction. No digital approval or change to taxes/amounts. */
    public function updateDvParticulars(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org);return $this->financialTx('dv.particulars.update',$d,function()use($org,$d){
        foreach(array_keys($d) as $key)demand(in_array($key,['organization_id','dv_id','particulars','expected_particulars'],true),
            'This action can update only DV Particulars; amounts, tax, payee and accounting entries remain locked.',400);
        $dv=$this->record('disbursement_vouchers',idOf($d,'dv_id'),$org,true);
        demand($this->taxTransferRecord($org,(int)$dv['id'])===null,
            'Tax-transfer DV amounts and sources are locked to original withholding; cancel draft and recreate if necessary.',409);
        demand($dv['status']==='DRAFT' && empty($dv['display_number']),
            'Particulars can be edited here only before finalization; numbered DV history is immutable.',409);
        $old=(string)($dv['particulars']??'');
        $expected=(string)($d['expected_particulars']??'');
        demand(hash_equals($old,$expected),'The particulars were changed elsewhere. Reopen this DV.',409);
        $new=trim((string)($d['particulars']??''));
        demand($new!=='' && mbSafeLen($new)<=1200,'Enter Particulars (maximum 1,200 characters).',409);
        demand(!preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$new),'Particulars contains unsupported control characters.',400);
        demand($new!==$old,'Particulars have not changed.',409);
        q($this->db,'UPDATE disbursement_vouchers SET particulars=? WHERE id=?',[$new,$dv['id']]);
        $this->snapshot($org,'DV',(int)$dv['id'],['particulars_before'=>$old,'particulars_after'=>$new,
            'amounts_unchanged'=>true,'status'=>'DRAFT'],'DV draft Particulars edited');
        $this->audit($org,'DV_PARTICULARS_UPDATED','DV',(int)$dv['id'],['before'=>$old,'after'=>$new]);
        return ['id'=>(int)$dv['id'],'particulars'=>$new,'status'=>'DRAFT'];
    });}
    /**
     * A claim preparer may record paper documents without a separate Division action.
     * This is documentary completeness, never approval or authorization to pay.
     * Owning office staff confirm the reviewed amount for DRAFT only.
     */
    private static function travelDvPaperEvidenceReady(array $claim): bool {
        $compliance=$claim['document_compliance']??null;
        if(!is_array($compliance) || ($compliance['method']??'')!=='PAPER_DOCUMENTS_ON_FILE' ||
            (int)($compliance['confirmed_by']??0)<=0)return false;
        $items=$compliance['items']??null;
        if(!is_array($items))return false;
        $required=['approved_request','signed_authority','completion','actual_expenses','actual_itinerary'];
        if(count($items)!==count($required))return false;
        foreach($required as $key)if(($items[$key]??null)!==true)return false;
        $certificate=$claim['completion_certificate']??null;
        if(!is_array($certificate) || ($certificate['format']??'')!=='APPENDIX_47' ||
            !is_string($certificate['certificate_date']??null) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$certificate['certificate_date']))return false;
        return true;
    }
    /** A reviewed itinerary is documentary evidence, NOT approval for a DV. */
    private function travelDvClaim(int $org,int $claimId,bool $lock=false,bool $forFinalization=false): array {
        $row=one($this->db,"SELECT id,display_number FROM document_registry WHERE organization_id=? AND doc_type='RC' AND id=? AND entity_id=?".($lock?' FOR UPDATE':''),[$org,$claimId,$claimId]);
        demand($row!==null,'Travel reimbursement claim not found in this office',404);
        $ver=one($this->db,"SELECT version_no,snapshot_json FROM document_versions WHERE organization_id=? AND doc_type='RC' AND entity_id=? ORDER BY version_no DESC LIMIT 1",[$org,$claimId]);
        demand($ver!==null,'Travel claim snapshot is missing',409);
        $claim=json_decode((string)$ver['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($claim)&&($claim['kind']??null)==='RC'&&(int)($claim['organization_id']??0)===$org,
            'Travel claim snapshot is not valid for this office',409);
        // Historical independently verified documents remain valid. For current claims,
        // saved Appendix 47, complete paper checks and version-current expense findings
        // are checked here. An owning-office actor must CONFIRM the provisional amount
        // for a DRAFT DV; no separate Division review is needed or represented.
        $divisionVerified=($claim['state']??'')==='DOCUMENTS_CHECKED_FOR_ACCOUNTING_REVIEW' &&
            (int)($claim['document_review']['reviewed_by']??0)>0;
        $paperReady=($claim['state']??'')==='DRAFT_DOCUMENTS_PENDING' && self::travelDvPaperEvidenceReady($claim);
        demand($divisionVerified || $paperReady,
            'Save all required paper-document checks and Appendix 47 before draft DV amount confirmation',409);
        demand(($claim['payment_authorized']??false)===false && ($claim['cash_advance_requested']??false)===false,
            'A travel claim with prior authorization/advance needs a separate verified reconciliation',409);
        demand(is_array($claim['itinerary']??null)&&($claim['itinerary']['format']??null)==='APPENDIX_45',
            'Travel reimbursement requires a saved actual Appendix 45 itinerary',409);
        $employeeId=(int)($claim['employee']['id']??0);demand($employeeId>0,'Claim employee is missing',409);
        $employee=one($this->db,'SELECT id,full_name FROM employees WHERE id=?',[$employeeId]);
        demand($employee!==null && trim((string)$employee['full_name'])!=='' &&
            (string)($claim['employee']['full_name']??'')===(string)$employee['full_name'],
            'Travel claimant identity needs verification before DV preparation',409);
        $fundId=(int)($claim['school_fund']['id']??$claim['chargeability']['school_fund']['id']??0);
        $fund=one($this->db,'SELECT id FROM funds WHERE id=? AND organization_id=?',[$fundId,$org]);
        demand($fund!==null,'Travel claim funding source is not assigned to the paying office',409);
        $review=one($this->db,'SELECT id,source_claim_version,version_no,snapshot_json FROM travel_claim_expense_reviews WHERE organization_id=? AND claim_id=?'.($lock?' FOR UPDATE':''),[$org,$claimId]);
        demand($review!==null && (int)$review['source_claim_version']===(int)$ver['version_no'],
            'Expense review is missing or is based on an earlier claim version',409);
        $finding=json_decode((string)$review['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($finding)&&($finding['state']??'')==='DOCUMENTARY_REVIEW_ONLY' &&
            (int)($finding['claim_version']??0)===(int)$ver['version_no'],
            'Expense review is not a current, versioned documentary finding',409);
        $decisions=$finding['decisions']??null;
        demand(is_array($decisions)&&count($decisions)>0,'Every claimed expense must be reviewed',409);
        $rechecked=0;
        foreach($decisions as $decision){
            demand(is_array($decision)&&in_array(($decision['decision']??''),['ALLOWED','PARTIALLY_ALLOWED','EXCLUDED'],true),
                'At least one expense is not reviewed, or still needs evidence',409);
            $allowed=moneyCents((string)($decision['allowed']??'0.00'));
            $claimedLine=moneyCents((string)($decision['claimed']??'0.00'));
            demand($allowed>=0 && $claimedLine>=0 && $allowed<=$claimedLine,
                'Travel expense review contains an invalid allowed amount',409);
            $rechecked+=$allowed;
        }
        $claimed=moneyCents((string)($claim['claim_total']??'0.00'));
        $reviewed=moneyCents((string)($finding['totals']['reviewed_allowed']??'0.00'));
        demand($reviewed===$rechecked && $reviewed>0 &&
            moneyCents((string)($finding['totals']['claimed']??'0.00'))===$claimed &&
            moneyCents((string)($finding['totals']['unreviewed']??'0.00'))===0,
            'Review totals do not reconcile to the saved claimed amounts',409);
        $approval=one($this->db,'SELECT * FROM travel_dv_accounting_approvals WHERE organization_id=? AND claim_id=? ORDER BY version_no DESC LIMIT 1'.($lock?' FOR UPDATE':''),[$org,$claimId]);
        $approvalValid=$approval!==null && (int)$approval['claim_version']===(int)$ver['version_no'] &&
            (int)$approval['review_version']===(int)$review['version_no'] &&
            (int)$approval['employee_id']===$employeeId && (int)$approval['fund_id']===$fundId &&
            moneyCents((string)$approval['preliminary_allowed'])===$reviewed &&
            moneyCents((string)$approval['claimed_amount'])===$claimed &&
            moneyCents((string)$approval['approved_amount'])>0 && moneyCents((string)$approval['approved_amount'])<=$reviewed;
        $link=one($this->db,'SELECT dv_id,status FROM travel_dv_claim_links WHERE organization_id=? AND active_claim_id=? LIMIT 1'.($lock?' FOR UPDATE':''),[$org,$claimId]);
        $final=ReimbursementFinalization::load($this->db,$org,'T',$claimId,$lock);
        $fingerprint=['claim'=>$claim,'review'=>$finding,'approved_amount'=>$final['approved_amount']??'0.00'];
        if($final!==null){
            ReimbursementFinalization::requireMatching($final,$fingerprint,'Travel claim');
            if(isset($final['debit_splits']))demand(
                $final['debit_splits']===$this->reviewedTravelSplits(
                    ['claim'=>$claim,'finding'=>$finding,'preliminary'=>$reviewed],$final['debit_splits']),
                'Finalized itinerary debit UACS evidence changed',409);
            demand($approvalValid && (int)$final['approval_id']===(int)$approval['id'] &&
                moneyCents((string)$final['approved_amount'])===moneyCents((string)$approval['approved_amount']) &&
                ($final['reference']??'')==='REIM-'.$row['display_number'] &&
                ($final['itinerary_number']??'')==='AIT-'.$row['display_number'],
                'Finalized travel approval, itinerary number or source amount changed; finance review required',409);
        }
        // Historical linked draft DVs are validated with their preserved older approvals.
        // New DV source selection requires an immutable finalization record.
        if($link===null && !$forFinalization)demand($final!==null,
            'Finalize this reimbursement in the Reimbursement page before selecting it for a new DV',409);
        return ['id'=>$claimId,'number'=>(string)$row['display_number'],
            'itinerary_number'=>$final['itinerary_number']??'', 'finalization'=>$final,'claim'=>$claim,
            'claim_version'=>(int)$ver['version_no'],'review_version'=>(int)$review['version_no'],
            'finding'=>$finding,
            'claimed'=>$claimed,'preliminary'=>$reviewed,'employee_id'=>$employeeId,
            'employee_name'=>(string)$employee['full_name'],'fund_id'=>$fundId,
            'claim_date'=>(string)($claim['claim_date']??''),
            'approval'=>$approvalValid?$approval:null,'active_dv'=>$link===null?null:(int)$link['dv_id']];
    }
    /** Reconcile UACS splits to the *reviewed* fare/per-diem/other columns,
     * not the claimed itinerary amounts. More than one account per column is OK.
     * Keys in decisions are the source itinerary row index plus component.
     */
    private function reviewedTravelColumns(array $c): array {
        $rows=$c['claim']['itinerary']['rows']??[];
        $decisions=$c['finding']['decisions']??[];
        $totals=['fare'=>0,'per_diem'=>0,'other'=>0];$seen=[];$claimed=0;$allow=0;
        demand(is_array($rows)&&is_array($decisions)&&count($decisions)>0,'Reviewed travel itinerary is missing',409);
        foreach($decisions as $d){
            $key=(string)($d['key']??'');
            demand(preg_match('/^([1-9][0-9]*):(fare|per_diem|other)$/D',$key,$m)===1 &&
                isset($rows[(int)$m[1]-1])&&!isset($seen[$key]),
                'Travel decision is not linked to a unique itinerary expense column',409);
            $seen[$key]=true;$component=$m[2];
            $original=moneyCents((string)($rows[(int)$m[1]-1][$component]??'0'));
            $amount=moneyCents((string)($d['allowed']??'0'));
            demand($original===moneyCents((string)($d['claimed']??'0')) &&
                $amount>=0 && $amount<=$original,
                'Travel reviewed component differs from the actual itinerary',409);
            $totals[$component]+=$amount;$claimed+=$original;$allow+=$amount;
        }
        demand($allow===$c['preliminary'] && $allow>0,
            'Travel expense review components do not balance',409);
        return $totals;
    }
    private function reviewedTravelSplits(array $c,array $rows): array {
        demand(array_is_list($rows)&&count($rows)>0&&count($rows)<=60,
            'Enter 1–60 travel UACS allocation lines',422);
        $totals=$this->reviewedTravelColumns($c);$sum=['fare'=>0,'per_diem'=>0,'other'=>0];$out=[];
        foreach($rows as $line){
            demand(is_array($line),'Invalid travel UACS allocation',422);
            $component=(string)($line['component']??'');
            demand(array_key_exists($component,$totals) && $totals[$component]>0,
                'UACS allocation must belong to an approved itinerary column',422);
            $cents=moneyCents((string)($line['amount']??'0'));
            demand($cents>0 && $cents<=$totals[$component],
                'Travel UACS amount is not an approved positive component amount',422);
            $code=(string)($line['debit_uacs_code']??'');
            demand(preg_match('/^[15][0-9]{9}$/D',$code)===1 &&
                one($this->db,'SELECT code FROM uacs_accounts WHERE code=? AND active=1',[$code])!==null,
                'Each travel allocation needs an enabled expense/asset debit UACS',422);
            $out[]=['component'=>$component,'amount'=>moneyString($cents),'debit_uacs_code'=>$code];
            $sum[$component]+=$cents;
        }
        demand($sum===$totals,'Travel, Per Diem and Others UACS totals must each exactly equal their reviewed allowed amounts',409);
        return $out;
    }
    /**
     * Finalized claim source, approval and AIT number are recorded atomically.
     * This records signed reimbursement authorization evidence, not DV payment.
     */
    public function finalizeTravelReimbursement(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        $id=idOf($d,'claim_id');$fields=ReimbursementFinalization::fields($this->db,$d);
        return $this->tx(function()use($org,$id,$fields,$d){
            demand(one($this->db,'SELECT id FROM organizations WHERE id=? AND active=1 FOR UPDATE',[$org])!==null,
                'Owning office is unavailable',403);
            $row=one($this->db,"SELECT id,display_number FROM document_registry WHERE organization_id=? AND doc_type='RC' AND id=? AND entity_id=? FOR UPDATE",[$org,$id,$id]);
            demand($row!==null,'Travel reimbursement claim not found in owning office',404);
            demand(ReimbursementFinalization::load($this->db,$org,'T',$id,true)===null,
                'This travel reimbursement is already finalized',409);
            $c=$this->travelDvClaim($org,$id,false,true);
            $fields['debit_splits']=$this->reviewedTravelSplits($c,$fields['debit_splits']);
            demand((int)($d['expected_claim_version']??-1)===$c['claim_version'] &&
                (int)($d['expected_review_version']??-1)===$c['review_version'],
                'Claim or expense review changed. Refresh before finalization.',409);
            demand($c['active_dv']===null,'Claim already linked to a Disbursement Voucher',409);
            $legacyVerified=($c['claim']['state']??'')==='DOCUMENTS_CHECKED_FOR_ACCOUNTING_REVIEW' &&
                (int)($c['claim']['document_review']['reviewed_by']??0)>0;
            demand(self::travelDvPaperEvidenceReady($c['claim']) || $legacyVerified,
                'Complete all paper-document checks and Appendix 47, or provide existing verified Division travel-document evidence, before finalization.',409);
            $amount=$c['preliminary'];
            demand($amount>0 && $amount<=$c['claimed'],'Finalized amount must be within reviewed allowances',409);
            $prior=one($this->db,'SELECT COALESCE(MAX(version_no),0) n FROM travel_dv_accounting_approvals WHERE organization_id=? AND claim_id=?',[$org,$id]);
            $next=(int)($prior['n']??0)+1;
            $approvedId=$this->insert('travel_dv_accounting_approvals',[
                'organization_id'=>$org,'claim_id'=>$id,'claim_version'=>$c['claim_version'],
                'review_version'=>$c['review_version'],'version_no'=>$next,
                'employee_id'=>$c['employee_id'],'fund_id'=>$c['fund_id'],
                'claimed_amount'=>moneyString($c['claimed']),
                'preliminary_allowed'=>moneyString($c['preliminary']),
                'approved_amount'=>moneyString($amount),'paper_reference'=>$fields['payment_authority_reference'],
                'approved_by'=>$this->user['id']]);
            $ref='REIM-'.$row['display_number'];$ait='AIT-'.$row['display_number'];
            demand(preg_match('/^20[0-9]{2}-(?:0[1-9]|1[0-2])-[0-9]{4}$/D',(string)$row['display_number'])===1,
                'Existing claim number cannot produce an accountable itinerary number',409);
            $final=ReimbursementFinalization::save($this->db,$org,(int)$this->user['id'],'T',$id,[
                'reference'=>$ref,'itinerary_number'=>$ait,
                'approved_amount'=>moneyString($amount),'approval_id'=>$approvedId,
                'claim_version'=>$c['claim_version'],'review_version'=>$c['review_version'],
                'employee_id'=>$c['employee_id'],'fund_id'=>$c['fund_id'],
                'source_digest'=>ReimbursementFinalization::digest([
                    'claim'=>$c['claim'],'review'=>$c['finding'],'approved_amount'=>moneyString($amount)])
            ],$fields);
            return ['claim_id'=>$id,'reference'=>$ref,'itinerary_number'=>$ait,
                'approved_amount'=>$final['approved_amount'],'status'=>'FINALIZED_READY_FOR_DV',
                'dv_created'=>false,'paid'=>false];
        });
    }
    /** Legacy API action remains present only to explain where to confirm. */
    /** Audited owning-office provisional amount confirmation, NOT accounting/payment approval. */
    public function approveTravelDvClaim(array $d): array {
        demand(false,'Complete reimbursement finalization in the Reimbursement page, not in DV preparation.',409);
        $org=$this->org($d);
        // Owner-only write controls: school claims can be confirmed by school staff
        // in their operational workspace. Division read/oversight does not confer
        // permission to confirm or create another office's DV.
        $this->operationalWriteAllowed($org);
        demand(($d['accounting_verified']??false)===true,
            'Confirm the saved itinerary, paper evidence and allowable amount for an office draft DV',422);
        $id=idOf($d,'claim_id');$amount=moneyCents($d['approved_amount']??'');
        $paper=trim((string)($d['paper_reference']??''));
        demand(mbSafeLen($paper)<=255,'Paper-review reference is too long',422);
        return $this->tx(function()use($org,$id,$amount,$paper){
            $record=$this->travelDvClaim($org,$id,true);
            $preparer=(int)($record['claim']['created_by']??0);
            demand($preparer>0,'Travel claim preparer identity is missing',409);
            // This is the office's provisional amount confirmation only. Same-actor
            // confirmation is transparently recorded, never labelled independent.
            // Travel DV finalization/check issuance remain blocked separately.
            demand($record['active_dv']===null,'This claim is already reserved by a DV',409);
            demand($amount>0 && $amount<=$record['preliminary'],
                'Office-confirmed amount must be positive and cannot exceed the reviewed allowance',422);
            $old=one($this->db,'SELECT COALESCE(MAX(version_no),0) version_no FROM travel_dv_accounting_approvals WHERE organization_id=? AND claim_id=?',[$org,$id]);
            $v=(int)($old['version_no']??0)+1;
            $approvalId=$this->insert('travel_dv_accounting_approvals',[
                'organization_id'=>$org,'claim_id'=>$id,'claim_version'=>$record['claim_version'],
                'review_version'=>$record['review_version'],'version_no'=>$v,
                'employee_id'=>$record['employee_id'],'fund_id'=>$record['fund_id'],
                'claimed_amount'=>moneyString($record['claimed']),
                'preliminary_allowed'=>moneyString($record['preliminary']),
                'approved_amount'=>moneyString($amount),'paper_reference'=>$paper,
                'approved_by'=>$this->user['id']]);
            $this->audit($org,'TRAVEL_ACCOUNTING_REVIEW','RC',$id,['approval_id'=>$approvalId,
                'claim_version'=>$record['claim_version'],'review_version'=>$record['review_version'],
                'approved_amount'=>moneyString($amount),
                'review_mode'=>'OWNING_OFFICE_DRAFT_AMOUNT_CONFIRMATION',
                'independent_accounting_approval'=>false,
                'same_actor_as_claim_preparer'=>$preparer===(int)$this->user['id'],
                'document_compliance_attested'=>true,'no_dv_or_payment_created'=>true]);
            return ['claim_id'=>$id,'approval_version'=>$v,'approved_amount'=>moneyString($amount)];
        });
    }
    /** Show every scoped RC with an actionable readiness status. Never infer DV eligibility from previews. */
    public function eligibleTravelDvClaims(array $d): array {
        $org=$this->org($d);
        $registrations=q($this->db,"SELECT id,display_number FROM document_registry WHERE organization_id=? AND doc_type='RC' AND id=entity_id ORDER BY id DESC LIMIT 100",[$org])->fetchAll(PDO::FETCH_ASSOC);
        $out=[];
        foreach($registrations as $registered){
            $id=(int)$registered['id'];
            $version=one($this->db,"SELECT snapshot_json FROM document_versions WHERE organization_id=? AND doc_type='RC' AND entity_id=? ORDER BY version_no DESC LIMIT 1",[$org,$id]);
            $snapshot=$version===null?null:json_decode((string)$version['snapshot_json'],true);
            // An invalid/out-of-scope RC cannot be represented as a selectable financial source.
            if(!is_array($snapshot)||($snapshot['kind']??'')!=='RC'||(int)($snapshot['organization_id']??0)!==$org)continue;
            $employee=$snapshot['employee']??[];
            $employeeId=(int)($employee['id']??0);
            $employeeName=trim((string)($employee['full_name']??''));
            $fundId=(int)($snapshot['school_fund']['id']??$snapshot['chargeability']['school_fund']['id']??0);
            $item=['claim_id'=>$id,'claim_number'=>(string)$registered['display_number'],
                'employee_id'=>$employeeId,'employee_name'=>$employeeName,
                'fund_id'=>$fundId,'claim_date'=>(string)($snapshot['claim_date']??''),
                'claimed'=>(string)($snapshot['claim_total']??'0.00'),
                'preliminary_allowed'=>null,'approved_amount'=>null,'approved_by'=>null,
                'finalized_reference'=>'','debit_uacs_code'=>'','debit_splits'=>[],
                'approval_version'=>null,'active_dv_id'=>null,
                'documentary_ready'=>false,'ready_for_dv'=>false,
                'readiness_message'=>'Claim requires completed paper-document checklist, Appendix 47 and expense review.'];
            try{
                // Keep the strict source/identity/fund/review/version checks authoritative.
                $claim=$this->travelDvClaim($org,$id);
                $approval=$claim['approval'];$active=$claim['active_dv'];
                $item['employee_id']=$claim['employee_id'];
                $item['employee_name']=$claim['employee_name'];
                $item['fund_id']=$claim['fund_id'];
                $item['claimed']=moneyString($claim['claimed']);
                $item['preliminary_allowed']=moneyString($claim['preliminary']);
                $item['documentary_ready']=true;
                $item['approved_amount']=$approval!==null?(string)$approval['approved_amount']:null;
                $item['approved_by']=$approval!==null?(int)$approval['approved_by']:null;
                $item['approval_version']=$approval!==null?(int)$approval['version_no']:null;
                $item['finalized_reference']=$claim['finalization']['reference']??'';
                $item['debit_uacs_code']=$claim['finalization']['debit_uacs_code']??'';
                $item['debit_splits']=$claim['finalization']['debit_splits']??[];
                $item['active_dv_id']=$active;
                $item['ready_for_dv']=$approval!==null && $active===null;
                $item['readiness_message']=$active!==null?'Already reserved in DV #'.$active:
                    ($approval===null?'Documentary review complete — confirm allowable amount in the owning office.':'Ready for draft DV.');
            }catch(DomainError $e){
                // Explain missing prerequisites, never convert an incomplete claim into a payable source.
                $item['readiness_message']=$e->getMessage();
            }
            $item['source_key']='T'.$id;
            $item['claim_kind']='TRAVEL';
            $out[]=$item;
        }
        // Other out-of-pocket claims remain separate audited sources and do NOT
        // inherit travel-specific Appendix 45/47 requirements.
        return array_merge($out,EmployeeClaims::candidates($this->db,$org));
    }
    /** One employee and funding source, potentially multiple approved unpaid claims. */
    private function createTravelDV(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        demand(!isset($d['allocations'])&&!isset($d['payee'])&&!isset($d['gross'])&&!isset($d['withholding']),
            'Employee DV amounts, payee, and sources are calculated from locked reviewed claims',422);
        $travelIds=$d['claim_ids']??[];$otherIds=$d['other_claim_ids']??[];
        demand(is_array($travelIds)&&array_is_list($travelIds)&&is_array($otherIds)&&array_is_list($otherIds) &&
            count($travelIds)+count($otherIds)>=1 && count($travelIds)+count($otherIds)<=20,
            'Select 1–20 reviewed travel or other employee reimbursement claims',422);
        foreach([$travelIds,$otherIds] as $ids){$seen=[];foreach($ids as $id){
            demand(is_int($id) && $id>0 && !isset($seen[$id]),'Invalid or repeated reimbursement source',422);
            $seen[$id]=true;
        }}
        sort($travelIds,SORT_NUMERIC);sort($otherIds,SORT_NUMERIC);
        $fundId=idOf($d,'fund_id');
        return $this->tx(function()use($org,$fundId,$travelIds,$otherIds){
            // Fund mutex first, same as the normal DV creation/finalization order.
            $fund=$this->record('funds',$fundId,$org,true);
            // Within this fund lock, organization lock serializes non-travel
            // review/claim/reservation writers and overlapping draft creations.
            demand(one($this->db,'SELECT id FROM organizations WHERE id=? FOR UPDATE',[$org])!==null,
                'Owning organization unavailable',409);
            $travel=[];$other=[];$employeeId=null;$payee=null;$total=0;
            foreach($travelIds as $claimId){
                $c=$this->travelDvClaim($org,$claimId,true);
                demand((int)$c['fund_id']===$fundId,'Claims must use one paying fund',409);
                demand($employeeId===null||(int)$c['employee_id']===$employeeId,
                    'One employee reimbursement DV cannot combine different employees',409);
                demand($c['active_dv']===null,'Travel claim is already linked to a DV',409);
                demand($c['approval']!==null,'Travel claim lacks a current office amount confirmation',409);
                $approved=moneyCents((string)$c['approval']['approved_amount']);
                demand($approved>0 && $approved<=$c['preliminary'],'Travel amount exceeds its reviewed allowance',409);
                $employeeId=(int)$c['employee_id'];$payee=$c['employee_name'];$total+=$approved;$travel[]=$c;
            }
            foreach($otherIds as $claimId){
                $c=EmployeeClaims::eligible($this->db,$org,$claimId,true);
                demand($c['fund_id']===$fundId,'Claims must use one paying fund',409);
                demand($employeeId===null||$c['employee_id']===$employeeId,
                    'One employee reimbursement DV cannot combine different employees',409);
                $employeeId=$c['employee_id'];$payee=$c['employee_name'];$total+=$c['amount'];$other[]=$c;
            }
            demand($total>0 && $total<=200000000 && $employeeId!==null,
                'Employee reimbursement exceeds supported ₱2,000,000 draft ceiling',422);
            $refs=array_merge(array_map(static fn($c)=>$c['itinerary_number']!==''?$c['itinerary_number']:$c['number'],$travel),
                array_map(static fn($c)=>'ER-'.$c['claim']['claim_id'],$other));
            $particulars='Reimbursement of reviewed employee-paid expenses: '.implode(', ',$refs);
            demand(mbSafeLen($particulars)<=1200,'Too many reimbursement sources for one DV',422);
            // Preserve existing enum value and historical travel-linked DVs;
            // do not pretend a new voucher type or payment approval exists.
            $dv=$this->insert('disbursement_vouchers',[
                'organization_id'=>$org,'fund_id'=>$fundId,'dv_type'=>'TRAVEL_REIMBURSEMENT',
                'status'=>'DRAFT','payee'=>$payee,'gross'=>moneyString($total),
                'deductions'=>'0.00','net'=>moneyString($total),'particulars'=>$particulars,
                'bill_reference'=>'Employee claims ('.count($refs).')','created_by'=>$this->user['id']]);
            foreach($travel as $c){
                $approved=moneyCents((string)$c['approval']['approved_amount']);
                $line=$this->insert('dv_allocation_lines',['dv_id'=>$dv,'amount'=>moneyString($approved)]);
                $this->insert('travel_dv_claim_links',[
                    'organization_id'=>$org,'dv_id'=>$dv,'claim_id'=>$c['id'],
                    'allocation_line_id'=>$line,'approval_id'=>(int)$c['approval']['id'],
                    'employee_id'=>$c['employee_id'],'fund_id'=>$fundId,
                    'claim_version'=>$c['claim_version'],'review_version'=>$c['review_version'],
                    'claimed_amount'=>moneyString($c['claimed']),
                    'preliminary_allowed'=>moneyString($c['preliminary']),
                    'allocated_amount'=>moneyString($approved),'claim_date'=>$c['claim_date'],
                    'active_claim_id'=>$c['id'],'status'=>'ACTIVE','created_by'=>$this->user['id']]);
                $this->link($org,'RC',$c['id'],'DV',$dv);
            }
            foreach($other as $c){
                $line=$this->insert('dv_allocation_lines',['dv_id'=>$dv,'amount'=>moneyString($c['amount'])]);
                EmployeeClaims::reserve($this->db,$org,(int)$c['claim']['claim_id'],$dv,$line,(int)$this->user['id']);
            }
            $this->audit($org,'DRAFT_CREATED','DV',$dv,[
                'type'=>'EMPLOYEE_REIMBURSEMENTS_DRAFT','employee_id'=>$employeeId,
                'travel_claim_ids'=>$travelIds,'out_of_pocket_claim_ids'=>$otherIds,
                'gross'=>moneyString($total),'withholding_not_assessed_on_employee_payee'=>true,
                'payment_authorized'=>false,'financial_posting'=>false]);
            return ['id'=>$dv,'type'=>'TRAVEL_REIMBURSEMENT','employee_reimbursement_draft'=>true,
                'status'=>'DRAFT','gross'=>moneyString($total),'net'=>moneyString($total),
                'travel_claim_ids'=>$travelIds,'other_claim_ids'=>$otherIds];
        });
    }
    /** The source approval and version must remain unchanged through DV finalization. */
    private function validateTravelDvLinks(int $org,array $dv,bool $requireWholeDv=true): array {
        $links=q($this->db,'SELECT * FROM travel_dv_claim_links WHERE organization_id=? AND dv_id=? ORDER BY claim_id FOR UPDATE',[$org,$dv['id']])->fetchAll(PDO::FETCH_ASSOC);
        demand(count($links)>0 && count($links)<=20,'Travel DV has no valid selected claim links',409);
        $total=0;$person=0;
        foreach($links as $l){
            demand($l['status']==='ACTIVE' && (int)$l['active_claim_id']===(int)$l['claim_id'],
                'A travel DV source was cancelled or has an invalid reservation',409);
            $c=$this->travelDvClaim($org,(int)$l['claim_id'],true);
            demand((int)$l['employee_id']===$c['employee_id'] && (int)$l['fund_id']===(int)$dv['fund_id'] &&
                (int)$l['claim_version']===$c['claim_version'] && (int)$l['review_version']===$c['review_version'] &&
                (int)$l['approval_id']===(int)($c['approval']['id']??0) &&
                (int)$c['active_dv']===(int)$dv['id'],
                'A travel claim, expense review or office amount confirmation changed after this draft was made. Finance must reconcile before finalization.',409);
            demand((string)$dv['payee']===$c['employee_name'],
                'Voucher payee differs from the verified employee',409);
            $amount=moneyCents((string)$l['allocated_amount']);
            demand($amount===moneyCents((string)$c['approval']['approved_amount']) &&
                moneyCents((string)$l['claimed_amount'])===$c['claimed'] &&
                moneyCents((string)$l['preliminary_allowed'])===$c['preliminary'],
                'Source reimbursement amount changed since DV draft',409);
            $allocation=one($this->db,'SELECT * FROM dv_allocation_lines WHERE id=? AND dv_id=? FOR UPDATE',[$l['allocation_line_id'],$dv['id']]);
            demand($allocation!==null && moneyCents((string)$allocation['amount'])===$amount &&
                $allocation['pr_id']===null && $allocation['iar_line_id']===null && $allocation['advance_id']===null,
                'A travel DV allocation was modified or linked to procurement',409);
            demand($person===0||$person===$c['employee_id'],'A travel DV cannot mix employees',409);
            $person=$c['employee_id'];$total+=$amount;
        }
        if($requireWholeDv)demand($total===moneyCents((string)$dv['gross']) &&
            moneyCents((string)$dv['net'])===$total && moneyCents((string)$dv['deductions'])===0,
            'Travel voucher gross/net reconciliation failed; use accountant-reviewed corrective workflow',409);
        return $links;
    }

    /** v0.2.373: An existing tax liability may be settled only once through an
     * original school DV and check. A Division JEV remains a separate record. */
    private function taxTransferRecord(int $org,int $dvId,bool $lock=false): ?array {
        return one($this->db,'SELECT * FROM school_tax_transfers WHERE organization_id=? AND dv_id=?'.($lock?' FOR UPDATE':''),[$org,$dvId]);
    }
    private function taxTransferSources(int $org,int $transferId,bool $lock=false): array {
        return q($this->db,"SELECT w.id,w.atc_code,w.amount,w.disposition,v.id source_dv_id,v.organization_id,v.fund_id,
                v.status dv_status,v.document_date,v.display_number,tc.amount transfer_amount,
                (SELECT COUNT(*) FROM tax_disposition_events e WHERE e.component_id=w.id AND e.state IN ('REMITTED','CONFIRMED_CREDIT')) already_completed,
                0 active_reservation,
                (SELECT COUNT(*) FROM checks c WHERE c.dv_id=v.id AND c.organization_id=v.organization_id
                    AND c.id=(SELECT MAX(c2.id) FROM checks c2 WHERE c2.dv_id=v.id AND c2.organization_id=v.organization_id)
                    AND c.status IN ('RELEASED','CLEARED')) source_paid
            FROM school_tax_transfer_components tc
            JOIN withholding_components w ON w.id=tc.component_id
            JOIN disbursement_vouchers v ON v.id=w.dv_id
            WHERE tc.transfer_id=? AND v.organization_id=? ORDER BY w.id".($lock?' FOR UPDATE':''),[$transferId,$org])->fetchAll();
    }
    private function taxTransferValidate(int $org,array $dv,bool $lock=false): ?array {
        $transfer=$this->taxTransferRecord($org,(int)$dv['id'],$lock);
        if($transfer===null)return null;
        demand($dv['dv_type']==='DIRECT' && (int)$dv['fund_id']===(int)$transfer['fund_id'] &&
            (int)$transfer['organization_id']===$org,
            'Tax-transfer DV identity/fund mismatch; request Finance review.',409);
        demand($transfer['status']==='DRAFT' || $dv['status']!=='DRAFT',
            'A draft transfer has changed state; reopen its record.',409);
        $rows=$this->taxTransferSources($org,(int)$transfer['id'],$lock);
        $sum=TaxTransferRules::validateSources($rows,$org,(int)$transfer['fund_id']);
        foreach($rows as $source)demand(moneyCents($source['transfer_amount'])===moneyCents($source['amount']),
            'Withholding transfer does not equal original saved tax component; no partial/fictitious settlement.',409);
        demand(moneyCents($sum)===moneyCents($dv['gross']) && moneyCents($dv['gross'])===moneyCents($dv['net']) &&
            moneyCents($dv['deductions'])===0,
            'Tax transfer must settle the exact tax liability without recalculating withholding.',409);
        $alloc=q($this->db,'SELECT * FROM dv_allocation_lines WHERE dv_id=?'.($lock?' FOR UPDATE':''),[$dv['id']])->fetchAll();
        demand(count($alloc)===1 && $alloc[0]['pr_id']===null && $alloc[0]['iar_line_id']===null &&
            $alloc[0]['advance_id']===null && moneyCents($alloc[0]['amount'])===moneyCents($sum),
            'Tax transfer allocation must be the original tax amount without a new procurement source.',409);
        $policy=$this->regularDvTaxPolicy($org,(int)$transfer['fund_id'],true,$lock);
        demand($policy['mode']==='REMIT_TO_DIVISION' && $policy['school_classification']==='NON_IUS',
            'Current Division source policy must explicitly authorize Non-IUS remittance to Division.',409);
        $legacyBankId=($transfer['division_bank_account_id']===null?null:(int)$transfer['division_bank_account_id']);
        $bank=$legacyBankId===null?null:one($this->db,
            'SELECT id,organization_id,account_label FROM bank_accounts WHERE id=? AND organization_id=?',
            [$legacyBankId,$transfer['division_id']]);
        if($legacyBankId!==null)
            demand($bank!==null,'Original Division receiving bank for legacy transfer is unavailable; Finance review required.',409);
        $created=one($this->db,"SELECT details FROM audit_events WHERE organization_id=? AND event_type='TAX_TRANSFER_DRAFT_CREATED'
            AND entity_type='DV' AND entity_id=? ORDER BY id DESC LIMIT 1",[$org,$dv['id']]);
        $createDetails=$created?json_decode((string)$created['details'],true):[];
        $payeeSnapshot=is_array($createDetails)?trim((string)($createDetails['check_payee_snapshot']??'')):'';
        if($payeeSnapshot!==''){
            demand($dv['payee']===$payeeSnapshot,'Original remittance check payee snapshot changed; Finance review required.',409);
        }else{
            // Legacy transfers created before a Division-configurable check payee.
            demand($bank!==null,'A bank-free transfer must have an immutable check-payee snapshot.',409);
            demand($bank['account_label']===$dv['payee'],
                'Legacy Division receiving account/payee changed; Finance review required.',409);
        }
        $transfer['source_rows']=$rows;
        $transfer['earliest_date']=max(array_column($rows,'document_date'));
        $transfer['policy']=$policy;
        return $transfer;
    }
    public function taxTransferWorkspace(array $d): array {
        $org=$this->org($d);
        $office=one($this->db,'SELECT kind,parent_division_id FROM organizations WHERE id=? AND active=1',[$org]);
        demand($office!==null,'Selected organization is unavailable',404);
        $division=$office['kind']==='DIVISION'?$org:(int)$office['parent_division_id'];
        demand($division>0,'School has no assigned Division',409);
        if($office['kind']==='DIVISION'){
            demand(in_array($this->user['role'],['DIVISION','SUPER_ADMIN'],true),
                'Division Accountant/Administrator required to review transferred taxes.',403);
            $rows=q($this->db,"SELECT t.*,v.display_number,v.gross,v.document_date,school.name school_name,
                b.account_label division_bank_label,
                (SELECT GROUP_CONCAT(c.check_number ORDER BY c.id SEPARATOR ', ') FROM checks c WHERE c.dv_id=t.dv_id AND c.organization_id=t.organization_id) check_numbers,
                (SELECT COUNT(*) FROM school_tax_transfer_components x WHERE x.transfer_id=t.id) component_count
                FROM school_tax_transfers t JOIN disbursement_vouchers v ON v.id=t.dv_id
                JOIN organizations school ON school.id=t.organization_id
                LEFT JOIN bank_accounts b ON b.id=t.division_bank_account_id
                WHERE t.division_id=? ORDER BY t.id DESC LIMIT 150",[$org])->fetchAll();
            return ['office_kind'=>'DIVISION','rows'=>$rows,'eligible'=>[],
                'receiving_banks'=>[],
                'division_utilization_workpaper'=>$this->divisionUtilizationLiabilityWorkpaper($org)];
        }
        $eligible=q($this->db,"SELECT w.id,w.atc_code,w.amount,v.id source_dv_id,v.display_number,
                v.document_date,v.fund_id,f.name fund_name,f.source_code
            FROM withholding_components w JOIN disbursement_vouchers v ON v.id=w.dv_id
            JOIN funds f ON f.id=v.fund_id AND f.organization_id=v.organization_id
            WHERE v.organization_id=? AND v.status='FINALIZED' AND w.disposition='REMIT'
              AND NOT EXISTS (SELECT 1 FROM tax_disposition_events e WHERE e.component_id=w.id AND e.state IN ('REMITTED','CONFIRMED_CREDIT'))
              AND NOT EXISTS (SELECT 1 FROM school_tax_transfer_components tc JOIN school_tax_transfers t ON t.id=tc.transfer_id WHERE tc.component_id=w.id AND t.status<>'CANCELLED')
              AND EXISTS (SELECT 1 FROM checks c WHERE c.dv_id=v.id AND c.organization_id=v.organization_id
                AND c.id=(SELECT MAX(c2.id) FROM checks c2 WHERE c2.dv_id=v.id AND c2.organization_id=v.organization_id)
                AND c.status IN ('RELEASED','CLEARED'))
            ORDER BY v.id DESC,w.id LIMIT 150",[$org])->fetchAll();
        $excluded=q($this->db,"SELECT w.id,w.atc_code,w.amount,v.id source_dv_id,v.display_number,
                v.document_date,v.fund_id,f.name fund_name,f.source_code,v.status dv_status,
                (SELECT c.status FROM checks c WHERE c.dv_id=v.id AND c.organization_id=v.organization_id ORDER BY c.id DESC LIMIT 1) latest_check_status,
                (SELECT COUNT(*) FROM tax_disposition_events e WHERE e.component_id=w.id AND e.state IN ('REMITTED','CONFIRMED_CREDIT')) completed_count,
                (SELECT COUNT(*) FROM school_tax_transfer_components tc JOIN school_tax_transfers t ON t.id=tc.transfer_id WHERE tc.component_id=w.id AND t.status<>'CANCELLED') reserved_count
            FROM withholding_components w JOIN disbursement_vouchers v ON v.id=w.dv_id
            JOIN funds f ON f.id=v.fund_id AND f.organization_id=v.organization_id
            WHERE v.organization_id=? AND w.disposition='REMIT'
              AND NOT EXISTS (SELECT 1 FROM tax_disposition_events e WHERE e.component_id=w.id AND e.state IN ('REMITTED','CONFIRMED_CREDIT'))
              AND (v.status<>'FINALIZED'
                OR EXISTS (SELECT 1 FROM school_tax_transfer_components tc JOIN school_tax_transfers t ON t.id=tc.transfer_id WHERE tc.component_id=w.id AND t.status<>'CANCELLED')
                OR NOT EXISTS (SELECT 1 FROM checks c WHERE c.dv_id=v.id AND c.organization_id=v.organization_id
                  AND c.id=(SELECT MAX(c2.id) FROM checks c2 WHERE c2.dv_id=v.id AND c2.organization_id=v.organization_id)
                  AND c.status IN ('RELEASED','CLEARED')))
            ORDER BY v.id DESC,w.id LIMIT 501",[$org])->fetchAll();
        $excludedLimited=count($excluded)>500;
        if($excludedLimited)$excluded=array_slice($excluded,0,500);
        foreach($excluded as &$item){
            $item['reason']=$item['dv_status']!=='FINALIZED'?'Supplier DV not finalized':
                ((int)$item['reserved_count']>0?'Already reserved in a tax-transfer DV':
                (!$item['latest_check_status']?'Supplier check not issued':
                (!in_array($item['latest_check_status'],['RELEASED','CLEARED'],true)?
                    'Supplier check is '.$item['latest_check_status'].' (requires actual release or clearing)':'Not yet eligible')));
        }unset($item);
        $rows=q($this->db,"SELECT t.*,v.display_number,v.gross,v.document_date,b.account_label division_bank_label,
            (SELECT GROUP_CONCAT(c.check_number ORDER BY c.id SEPARATOR ', ') FROM checks c WHERE c.dv_id=t.dv_id AND c.organization_id=t.organization_id) check_numbers,
            (SELECT COUNT(*) FROM school_tax_transfer_components x WHERE x.transfer_id=t.id) component_count
            FROM school_tax_transfers t JOIN disbursement_vouchers v ON v.id=t.dv_id
            LEFT JOIN bank_accounts b ON b.id=t.division_bank_account_id
            WHERE t.organization_id=? ORDER BY t.id DESC LIMIT 150",[$org])->fetchAll();
        return ['office_kind'=>'SCHOOL','rows'=>$rows,'eligible'=>$eligible,'ineligible'=>$excluded,'ineligible_truncated'=>$excludedLimited,'eligible_truncated'=>count($eligible)===150,'receiving_banks'=>[]];
    }
    public function createTaxTransferDV(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        demand(in_array($this->user['role'],['SCHOOL','SUPER_ADMIN'],true),
            'Tax transfer DV must be prepared by an authorized school finance user.',403);
        $raw=$d['component_ids']??null;
        demand(is_array($raw) && array_is_list($raw) && count($raw)>0 && count($raw)<=100,
            'Choose 1–100 original withholding entries from the same fund.',422);
        $ids=[];
        foreach($raw as $value){
            demand((is_int($value)||is_string($value))&&preg_match('/^[1-9][0-9]*$/D',(string)$value),
                'Invalid tax component selection.',422);
            $id=(int)$value;demand($id>0&&!isset($ids[$id]),'Duplicate component ID.',422);$ids[$id]=true;
        }
        $ids=array_keys($ids);sort($ids,SORT_NUMERIC);
        // Division determines deposit destination after acknowledging actual receipt.
        // The school is never asked to choose or invent a receiving bank account.
        demand(!isset($d['division_bank_account_id'])&&!isset($d['confirmed_receiving_account']),
            'Do not provide a Division bank for new tax-remittance DVs.',422);
        $fundId=idOf($d,'fund_id');
        return $this->financialTx('tax.transfer.create',$d,function()use($org,$fundId,$ids,$d){
            $fund=$this->record('funds',$fundId,$org,true);
            $school=one($this->db,"SELECT id,kind,parent_division_id FROM organizations WHERE id=? AND active=1 FOR UPDATE",[$org]);
            demand($school && $school['kind']==='SCHOOL' && (int)$school['parent_division_id']>0,
                'Only an active Division-linked school can transfer its tax liability.',409);
            $division=(int)$school['parent_division_id'];
            $policy=$this->regularDvTaxPolicy($org,$fundId,true,true);
            demand($policy['mode']==='REMIT_TO_DIVISION' && $policy['school_classification']==='NON_IUS',
                'Division must authorize REMIT_TO_DIVISION for this Non-IUS fund before preparing a tax-transfer DV.',409);
            $payeeSettings=$this->taxCheckPayeeConfig($division,(string)$fund['source_code']);
            demand($payeeSettings['payee']!=='',
                'Division must configure the official check payee under Fund Configuration for this fund source before a new tax-remittance DV is prepared.',409);
            $checkPayee=$payeeSettings['payee'];
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $rows=q($this->db,"SELECT w.id,w.atc_code,w.amount,w.disposition,v.id source_dv_id,v.organization_id,v.fund_id,v.status dv_status,
                v.document_date, v.display_number,
                (SELECT COUNT(*) FROM tax_disposition_events e WHERE e.component_id=w.id AND e.state IN ('REMITTED','CONFIRMED_CREDIT')) already_completed,
                (SELECT COUNT(*) FROM school_tax_transfer_components tc JOIN school_tax_transfers t ON t.id=tc.transfer_id WHERE tc.component_id=w.id AND t.status<>'CANCELLED') active_reservation,
                (SELECT COUNT(*) FROM checks c WHERE c.dv_id=v.id AND c.organization_id=v.organization_id
                    AND c.id=(SELECT MAX(c2.id) FROM checks c2 WHERE c2.dv_id=v.id AND c2.organization_id=v.organization_id)
                    AND c.status IN ('RELEASED','CLEARED')) source_paid
                FROM withholding_components w JOIN disbursement_vouchers v ON v.id=w.dv_id
                WHERE w.id IN ($marks) ORDER BY w.id FOR UPDATE",$ids)->fetchAll();
            demand(count($rows)===count($ids),'Some source tax components are missing.',409);
            $sum=TaxTransferRules::validateSources($rows,$org,$fundId);
            $remark=trim((string)($d['reference']??''));
            demand(mbSafeLen($remark)<=255,'Optional school cover note is too long.',422);
            $dv=$this->insert('disbursement_vouchers',['organization_id'=>$org,'fund_id'=>$fundId,'dv_type'=>'DIRECT',
                'payee'=>$checkPayee,'gross'=>$sum,'deductions'=>'0.00','net'=>$sum,
                'bill_reference'=>'PENDING-TAX-TRANSFER','particulars'=>'Remittance of previously withheld taxes to Division; not another expense or new withholding',
                'created_by'=>$this->user['id']]);
            $batchReference='TAX-REMIT-'.$org.'-'.$dv;
            q($this->db,'UPDATE disbursement_vouchers SET bill_reference=? WHERE id=? AND organization_id=?',
                [$batchReference,$dv,$org]);
            $this->insert('dv_allocation_lines',['dv_id'=>$dv,'amount'=>$sum]);
            $id=$this->insert('school_tax_transfers',['organization_id'=>$org,'division_id'=>$division,'fund_id'=>$fundId,
                'dv_id'=>$dv,'division_bank_account_id'=>null,'status'=>'DRAFT','created_by'=>$this->user['id']]);
            foreach($rows as $row){
                $this->insert('school_tax_transfer_components',['transfer_id'=>$id,'component_id'=>$row['id'],'amount'=>$row['amount']]);
                $this->link($org,'DV',(int)$row['source_dv_id'],'DV',$dv);
            }
            $this->audit($org,'TAX_TRANSFER_DRAFT_CREATED','DV',$dv,['transfer_id'=>$id,'amount'=>$sum,
                'component_ids'=>$ids,'division_id'=>$division,'division_bank_account_id'=>null,
                'internal_batch_reference'=>$batchReference,'school_cover_note'=>$remark,
                'division_receipt_not_yet_issued'=>true,
                'policy_version'=>$policy['version_id'],'check_payee_snapshot'=>$checkPayee,
                'check_payee_config_version'=>$payeeSettings['version_id']]);
            return ['id'=>$dv,'transfer_id'=>$id,'status'=>'DRAFT','gross'=>$sum,'net'=>$sum,'source_count'=>count($rows)];
        });
    }
    public function receiveTaxTransfer(array $d): array {
        $org=$this->org($d);
        demand(in_array($this->user['role'],['DIVISION','SUPER_ADMIN'],true),
            'Division receipt acknowledgment requires an authorized Division finance user.',403);
        return $this->financialTx('tax.transfer.receive',$d,function()use($org,$d){
            $transfer=one($this->db,'SELECT * FROM school_tax_transfers WHERE id=? AND division_id=? FOR UPDATE',
                [idOf($d,'transfer_id'),$org]);
            demand($transfer!==null,'Transfer not assigned to the current Division.',404);
            demand($transfer['status']==='CHECK_RELEASED','Only a released school payment may be acknowledged once.',409);
            $evidence=requiredString($d,'receipt_reference',255);
            // Distinguish an actual Division OR/transaction reference from the school's
            // internal draft batch code. The acknowledgement itself is the receipt event.
            demand(one($this->db,"SELECT id FROM school_tax_transfers
                WHERE division_id=? AND receipt_reference=? AND status IN ('DO_ACCOUNTING_PENDING','RECONCILED') LIMIT 1",
                [$org,$evidence])===null,'This Division OR / transaction reference is already used.',409);
            $date=self::transactionDocumentDate(requiredString($d,'received_at',10),'Division receipt date');
            $dv=one($this->db,'SELECT id,gross,status,document_date FROM disbursement_vouchers WHERE id=? AND organization_id=?',
                [$transfer['dv_id'],$transfer['organization_id']]);
            demand($dv && $dv['status']==='FINALIZED' && $date>=$dv['document_date'],
                'Division receipt cannot precede the finalized school DV.',409);
            $receivedAmount=requiredString($d,'received_amount',32);
            demand(moneyCents($receivedAmount)===moneyCents((string)$dv['gross']),
                'Division receipt amount must match the exact school remittance; report a discrepancy instead.',409);
            $latest=one($this->db,"SELECT id,check_number,status FROM checks WHERE dv_id=? AND organization_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE",
                [$dv['id'],$transfer['organization_id']]);
            demand($latest && in_array($latest['status'],['RELEASED','CLEARED'],true),
                'School check must be released and not stopped or replaced before Division acknowledges receipt.',409);
            $released=one($this->db,"SELECT DATE(MIN(created_at)) released_on FROM payment_events
                WHERE check_id=? AND event_type='RELEASED'",[$latest['id']]);
            demand($released && !empty($released['released_on']) && $date>=$released['released_on'],
                'Division receipt date cannot precede actual school check release evidence.',409);
            $sources=q($this->db,'SELECT tc.component_id,tc.amount FROM school_tax_transfer_components tc WHERE tc.transfer_id=? ORDER BY tc.component_id',[$transfer['id']])->fetchAll();
            demand(count($sources)>0,'No linked source tax components.',409);
            $due=0;
            foreach($sources as $source){
                $tax=one($this->db,'SELECT id,amount FROM withholding_components WHERE id=? FOR UPDATE',[$source['component_id']]);
                demand($tax && moneyCents($tax['amount'])===moneyCents($source['amount']),
                    'Source tax amount changed; request accounting review.',409);
                demand(one($this->db,"SELECT id FROM tax_disposition_events WHERE component_id=? AND state IN ('REMITTED','CONFIRMED_CREDIT') LIMIT 1",[$tax['id']])===null,
                    'Source tax has already been completed; duplicate remittance blocked.',409);
                $due+=moneyCents($tax['amount']);
                $this->insert('tax_disposition_events',['component_id'=>$tax['id'],'state'=>'REMITTED',
                    'amount'=>$tax['amount'],'evidence_reference'=>$evidence,'authority_reference'=>'Division receipt of school check '.$latest['check_number'],
                    'created_by'=>$this->user['id']]);
            }
            demand($due===moneyCents($dv['gross']),'Division receipt does not reconcile to school tax total.',409);
            q($this->db,"UPDATE school_tax_transfers SET status='DO_ACCOUNTING_PENDING',received_by=?,received_at=?,receipt_reference=? WHERE id=? AND status='CHECK_RELEASED'",
                [$this->user['id'],$date,$evidence,$transfer['id']]);
            $this->audit($org,'TAX_TRANSFER_RECEIVED_PENDING_ACCOUNTING','DV',(int)$dv['id'],[
                'transfer_id'=>$transfer['id'],'school_id'=>$transfer['organization_id'],'check_number'=>$latest['check_number'],
                'receipt_reference'=>$evidence,'received_at'=>$date,'amount'=>$dv['gross'],
                'amount_confirmed_by_receiver'=>true,'no_division_bank_deposit_posted'=>true]);
            return ['transfer_id'=>(int)$transfer['id'],'status'=>'DO_ACCOUNTING_PENDING','receipt_reference'=>$evidence];
        });
    }
    public function reconcileTaxTransfer(array $d): array {
        $org=$this->org($d);
        demand(in_array($this->user['role'],['DIVISION','SUPER_ADMIN'],true),
            'Division accounting reconciliation requires an authorized Division finance user.',403);
        return $this->financialTx('tax.transfer.reconcile',$d,function()use($org,$d){
            $transfer=one($this->db,'SELECT * FROM school_tax_transfers WHERE id=? AND division_id=? FOR UPDATE',
                [idOf($d,'transfer_id'),$org]);
            demand($transfer && $transfer['status']==='DO_ACCOUNTING_PENDING',
                'Division receipt must be acknowledged before accounting reconciliation.',409);
            $jev=requiredString($d,'jev_reference',255);$authority=requiredString($d,'accounting_authority',255);
            demand(mbSafeLen($jev)>=5 && mbSafeLen($authority)>=8,'Reference the actual approved Division JEV and accounting authority.',422);
            demand(one($this->db,'SELECT id FROM school_tax_transfers WHERE division_id=? AND jev_reference=? LIMIT 1',[$org,$jev])===null,
                'This Division JEV reference is already linked to another tax transfer.',409);
            q($this->db,"UPDATE school_tax_transfers SET status='RECONCILED',reconciled_by=?,reconciled_at=CURRENT_TIMESTAMP,jev_reference=? WHERE id=? AND status='DO_ACCOUNTING_PENDING'",
                [$this->user['id'],$jev,$transfer['id']]);
            $this->audit($org,'TAX_TRANSFER_JEV_RECONCILED','DV',(int)$transfer['dv_id'],[
                'transfer_id'=>$transfer['id'],'jev_reference'=>$jev,'accounting_authority'=>$authority,
                'original_school_dv_unchanged'=>true,'no_new_bank_posting'=>true]);
            return ['transfer_id'=>(int)$transfer['id'],'status'=>'RECONCILED','jev_reference'=>$jev];
        });
    }

    public function createDV(array $d): array {if(($d['dv_type']??null)==='TRAVEL_REIMBURSEMENT')return $this->createTravelDV($d);$org=$this->org($d);$this->operationalWriteAllowed($org);return $this->tx(function()use($org,$d){
        $type=requiredString($d,'dv_type',20);demand(in_array($type,['REGULAR','DIRECT','REIMBURSEMENT'],true),'Invalid DV type');
        $supplierIdentity=null;$poMode=$type==='REGULAR';
        if($poMode){
            // A regular DV is based on exactly one finalized PO. The client
            // supplies ONLY po_id, never a self-declared fund, supplier,
            // individual IAR allocation or invoice total. All monetary lines
            // are derived from finalized IARs and unpaid accepted amounts.
            demand(!isset($d['allocations']) && !isset($d['fund_id']),
                'For a regular DV, select only the PO; refresh the Disbursements page');
            $po=one($this->db,"SELECT po.id,po.status,pr.status pr_status,pr.fund_id,s.id supplier_id,s.name,s.proprietor_name,s.business_classification,s.vat_status,s.goods_profile_active,s.services_profile_active,s.rental_profile_active,s.contractor_profile_active,s.utilities_profile_active,s.other_profile_active
                FROM purchase_orders po JOIN purchase_requests pr ON pr.id=po.pr_id
                JOIN suppliers s ON s.id=po.supplier_id
                WHERE po.id=? AND po.organization_id=? FOR UPDATE",[idOf($d,'po_id'),$org]);
            demand($po!==null && $po['status']==='FINALIZED' && $po['pr_status']==='FINALIZED','Select a finalized PO with a valid finalized PR for this school');
            $this->forbidEmployeeProcurementDoubleClaim($org,'PO',(int)$po['id']);
            $this->forbidSecondSourceVoucher($org,'PO',(int)$po['id']);
            // A PR used for Direct/PR-only payment cannot also be paid by a Regular DV.
            $direct=one($this->db,"SELECT v.id,v.status FROM disbursement_vouchers v
                JOIN dv_allocation_lines a ON a.dv_id=v.id
                WHERE v.organization_id=? AND v.dv_type='DIRECT' AND a.pr_id=(SELECT pr_id FROM purchase_orders WHERE id=?)
                ORDER BY v.id LIMIT 1",[$org,$po['id']]);
            if($direct!==null)demand(false,'This PR already has Direct DV #'.$direct['id'].'; review the prior payment before preparing a Regular DV',409);
            $fund=$this->record('funds',(int)$po['fund_id'],$org);
            $supplierIdentity=['id'=>$po['supplier_id'],'name'=>$po['name'],
                'proprietor_name'=>$po['proprietor_name'],'business_classification'=>$po['business_classification']];
            if($supplierIdentity['business_classification']==='INDIVIDUAL')
                demand(trim((string)$supplierIdentity['proprietor_name'])!=='',
                    'Update the individual supplier profile with the proprietor’s legal name before preparing the DV');
            $accepted=q($this->db,"SELECT l.id iar_line_id,l.accepted_amount,
                COALESCE((SELECT SUM(a.amount) FROM dv_allocation_lines a
                    JOIN disbursement_vouchers v ON v.id=a.dv_id
                    WHERE a.iar_line_id=l.id AND v.status='FINALIZED'),0) committed
                FROM iar_lines l JOIN iars i ON i.id=l.iar_id
                WHERE i.po_id=? AND i.status='FINALIZED' ORDER BY l.id FOR UPDATE",[$po['id']])->fetchAll();
            $alloc=[];
            foreach($accepted as $source){
                $remaining=moneyCents($source['accepted_amount'])-moneyCents($source['committed']);
                demand($remaining>=0,'Accepted PO amount is already overcommitted; finance review required',409);
                if($remaining>0)$alloc[]=['iar_line_id'=>(int)$source['iar_line_id'],'amount'=>moneyString($remaining)];
            }
            demand(count($alloc)>0,'This PO has no accepted unpaid deliveries; finalize an IAR or review earlier DVs',409);
            demand(count($alloc)<=100,'This PO exceeds the current 100-line voucher limit; contact the administrator',409);
        }else{
            $fund=$this->record('funds',idOf($d,'fund_id'),$org);
            $alloc=rowsRequired($d,'allocations');
            demand(count($alloc)===1,'Select exactly one PR or one recorded advance per DV. Edit an existing draft instead.',409);
            if($type==='DIRECT'){
                $sourcePr=$this->record('purchase_requests',idOf($alloc[0],'pr_id'),$org,true);
                demand($sourcePr['status']==='FINALIZED'&&(int)$sourcePr['fund_id']===(int)$fund['id'],
                    'Finalized PR with matching fund required',409);
                $this->forbidEmployeeProcurementDoubleClaim($org,'PR',(int)$sourcePr['id']);
                $this->forbidSecondSourceVoucher($org,'PR',(int)$sourcePr['id']);
            }else{
                $sourceAdvance=one($this->db,'SELECT id FROM advances WHERE id=? AND organization_id=? FOR UPDATE',
                    [idOf($alloc[0],'advance_id'),$org]);
                demand($sourceAdvance!==null,'Recorded advance not found for this school',404);
                $this->forbidSecondSourceVoucher($org,'ADVANCE',(int)$sourceAdvance['id']);
            }
        }
        $gross=0;
        $payee=$supplierIdentity!==null ? (string)$supplierIdentity['name'] : requiredString($d,'payee',180);
        // Supplier identity is snapshotted when the DRAFT is created. Editing
        // the supplier master later never rewrites a recorded DV or check.
        $dv=$this->insert('disbursement_vouchers',['organization_id'=>$org,'fund_id'=>$fund['id'],'dv_type'=>$type,
            'payee'=>$payee,'supplier_id'=>$supplierIdentity['id']??null,
            'business_name_snapshot'=>$supplierIdentity['name']??null,
            'proprietor_name_snapshot'=>($supplierIdentity['business_classification']??null)==='INDIVIDUAL'?($supplierIdentity['proprietor_name']??null):null,
            'gross'=>'0.00','net'=>'0.00','bill_reference'=>$type==='DIRECT'?requiredString($d,'bill_reference',255):null,'created_by'=>$this->user['id']]);
        if($poMode)$this->link($org,'PO',(int)$po['id'],'DV',$dv);
        foreach($alloc as $entry){demand(is_array($entry),'Invalid allocation');$amount=moneyCents($entry['amount']??'');demand($amount>0,'Allocation amount must be positive');
            if($type==='DIRECT'){
                $pr=$this->record('purchase_requests',idOf($entry,'pr_id'),$org);demand($pr['status']==='FINALIZED'&&(int)$pr['fund_id']===(int)$fund['id'],'Finalized PR with matching fund required');demand($amount<=moneyCents($pr['total']),'Direct DV allocation exceeds PR total');
                $this->insert('dv_allocation_lines',['dv_id'=>$dv,'pr_id'=>$pr['id'],'amount'=>moneyString($amount)]);$this->link($org,'PR',(int)$pr['id'],'DV',$dv);
            } elseif($type==='REGULAR'){
                $source=one($this->db,"SELECT l.id,l.accepted_amount,po.supplier_id,pr.id pr_id,pr.fund_id,pr.organization_id FROM iar_lines l JOIN iars i ON i.id=l.iar_id JOIN purchase_orders po ON po.id=i.po_id JOIN purchase_requests pr ON pr.id=po.pr_id WHERE l.id=? AND i.status='FINALIZED' AND po.status='FINALIZED'",[idOf($entry,'iar_line_id')]);
                demand($source!==null&&(int)$source['organization_id']===$org&&(int)$source['fund_id']===(int)$fund['id'],'Accepted IAR with same fund required');demand($amount<=moneyCents($source['accepted_amount']),'Amount exceeds acceptance');$this->insert('dv_allocation_lines',['dv_id'=>$dv,'pr_id'=>$source['pr_id'],'iar_line_id'=>$source['id'],'original_supplier_id'=>$source['supplier_id'],'amount'=>moneyString($amount)]);$this->link($org,'IAR_LINE',(int)$source['id'],'DV',$dv);
            } else {
                $adv=one($this->db,"SELECT a.*,l.accepted_amount,pr.id pr_id,pr.fund_id,pr.organization_id,po.supplier_id FROM advances a JOIN iar_lines l ON l.id=a.iar_line_id JOIN iars i ON i.id=l.iar_id JOIN purchase_orders po ON po.id=i.po_id JOIN awards aw ON aw.id=po.award_id JOIN purchase_requests pr ON pr.id=po.pr_id JOIN rfqs r ON r.id=aw.rfq_id WHERE a.id=? AND i.status='FINALIZED' AND po.status='FINALIZED' AND aw.status='FINALIZED' AND r.status='FINALIZED'",[idOf($entry,'advance_id')]);
                demand($adv!==null&&(int)$adv['organization_id']===$org&&(int)$adv['fund_id']===(int)$fund['id'],'Reimbursement requires completed PR → RFQ → Award → PO → accepted IAR in the same school/fund');
                demand($adv['original_payer']===trim((string)$d['payee']),'Reimbursement payee must match original verified payer');demand($amount<=moneyCents($adv['amount']),'Amount exceeds proven advance');
                $this->insert('dv_allocation_lines',['dv_id'=>$dv,'pr_id'=>$adv['pr_id'],'iar_line_id'=>$adv['iar_line_id'],'advance_id'=>$adv['id'],'original_supplier_id'=>$adv['supplier_id'],'amount'=>moneyString($amount)]);$this->link($org,'IAR_LINE',(int)$adv['iar_line_id'],'DV',$dv);
            }$gross+=$amount;
        }
        $deduct=0;
        $sourceAllocations=q($this->db,'SELECT * FROM dv_allocation_lines WHERE dv_id=? ORDER BY id',[$dv])->fetchAll();
        $generatedTaxPlan=null;
        if($poMode){
            demand(!isset($d['withholding']),'Regular DV withholding is generated by the server from the selected supplier profile',409);
            $profile=requiredString($d,'tax_profile',20);
            $this->validatePoTaxCategory((int)$po['id'],$profile);
            $generatedTaxPlan=$this->regularPoTaxPlan($po,$profile,$gross,$d['invoice_vat']??null,$org,
                (string)($d['vat_mode']??'STANDARD'),(string)($d['exception_reason']??''));
            demand(isset($d['expected_vat_version']) && is_int($d['expected_vat_version']) &&
                $d['expected_vat_version']===$generatedTaxPlan['vat_rate_version'],
                'Division VAT configuration changed since the draft preview. Recalculate and review before saving.',409);
        }
        $taxInput=$poMode?$generatedTaxPlan['components']:($d['withholding']??[]);
        foreach($taxInput as $tax){demand(is_array($tax),'Invalid tax');$rate=one($this->db,"SELECT v.*,a.active,COALESCE(NULLIF(a.tax_kind,''),'UNCLASSIFIED') AS tax_kind FROM tax_rate_versions v JOIN tax_atcs a ON a.code=v.atc_code WHERE v.id=?",[idOf($tax,'rate_version_id')]);demand($rate!==null&&(int)$rate['active']===1,'Unknown or inactive withholding rate/ATC');demand(in_array($rate['tax_kind'],['VAT','EWT'],true),'ATC tax type is not classified; review the ATC before adding it to a DV');demand(strtoupper(trim((string)($tax['tax_kind']??'')))===$rate['tax_kind'],'Selected withholding tax type does not match the ATC rate');demand($rate['starts']<=date('Y-m-d')&&($rate['ends']===null||$rate['ends']>=date('Y-m-d')),'Tax rate version is not effective on voucher date');$index=$tax['allocation_index']??(count($sourceAllocations)===1?0:-1);
            $sourceAllocation=null;
            if(!$poMode){demand(is_int($index)&&isset($sourceAllocations[$index]),'Specify valid zero-based allocation_index for each tax component');$sourceAllocation=$sourceAllocations[$index];}
            $base=moneyCents($tax['tax_base']??'');
            // The withholding_components table permits a NULL allocation-line
            // reference, allowing one genuine PO-wide tax base. This avoids
            // falsely attributing the entire PO's withholding to its first item.
            demand($base>0 && $base<=($poMode?$gross:moneyCents($sourceAllocation['amount'])),
                'Tax base exceeds '.($poMode?'PO payable gross':'selected source allocation'));
            $value=taxCents($base,(string)$rate['rate']);$disp=requiredString($tax,'disposition',30);demand(in_array($disp,['REMIT','SCHOOL_UTILIZATION'],true),'Invalid tax disposition');demand($value>0,'Withholding must be positive');
            $this->insert('withholding_components',['dv_id'=>$dv,'dv_allocation_line_id'=>$sourceAllocation['id']??null,'atc_code'=>$rate['atc_code'],'rate_version_id'=>$rate['id'],'tax_base'=>moneyString($base),'amount'=>moneyString($value),'disposition'=>$disp,'fund_id'=>$disp==='SCHOOL_UTILIZATION'?$fund['id']:null]);$deduct+=$value;
        }
        demand($gross>0 && $deduct<=$gross,'Deductions cannot exceed gross');
        $particulars=$this->dvSuggestedParticulars((int)$dv,$type,moneyString($gross));
        q($this->db,'UPDATE disbursement_vouchers SET gross=?,deductions=?,net=?,particulars=? WHERE id=?',
            [moneyString($gross),moneyString($deduct),moneyString($gross-$deduct),$particulars===''?null:$particulars,$dv]);$this->audit($org,'DRAFT_CREATED','DV',$dv,['type'=>$type,'supplier_id'=>$supplierIdentity['id']??null,'business_name'=>$supplierIdentity['name']??null,'proprietor_name'=>($supplierIdentity['business_classification']??null)==='INDIVIDUAL'?($supplierIdentity['proprietor_name']??null):null,'automated_tax'=> $generatedTaxPlan===null?null:['profile'=>$generatedTaxPlan['profile'],'vat_mode'=>$generatedTaxPlan['vat_mode'],
            'vat_rate_percent'=>$generatedTaxPlan['vat_rate_percent'],'vat_rate_version'=>$generatedTaxPlan['vat_rate_version'],
            'vat_exception_reason'=>$generatedTaxPlan['vat_exception_reason'],
            'invoice_vat'=>$generatedTaxPlan['invoice_vat'],'tax_base'=>$generatedTaxPlan['tax_base'],
            'fund_policy'=>$generatedTaxPlan['fund_policy'],'components'=>$generatedTaxPlan['components']]]);return ['id'=>$dv,'type'=>$type,'status'=>'DRAFT','gross'=>moneyString($gross),'net'=>moneyString($gross-$deduct)];
    });}
    /** Documentation-only per-DV debit UACS correction. Never changes the original
     * source classification, monetary amount, withholding or existing credit lines.
     * No digital accountant/preparer/verifier/approver workflow is implied.
     * Finalized vouchers with recorded checks require separate adjusting-entry
     * documentation; this path never overwrites a paid historical workpaper.
     */
    public function updateDraftDV(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org);return $this->financialTx('dv.update',$d,function()use($org,$d){
        demand($this->taxTransferRecord($org,idOf($d,'dv_id'))===null,
            'A withholding-transfer DV uses locked source components and automatic two-line accounting; update of source allocations is not allowed.',409);
        foreach(array_keys($d) as $key)demand(in_array($key,['organization_id','dv_id','expected_token','bill_reference','uacs_allocations','reason'],true),
            'Only the document reference and item debit UACS can be corrected. Amounts, tax, credit, payee, fund and source are locked.',400);
        $dv=$this->record('disbursement_vouchers',idOf($d,'dv_id'),$org,true);
        $draft=$dv['status']==='DRAFT' && empty($dv['display_number']);
        $final=$dv['status']==='FINALIZED' && !empty($dv['display_number']);
        demand($draft||$final,'Only existing Draft or Finalized DVs can be corrected',409);
        demand($dv['dv_type']==='REGULAR','Only Regular PO/IAR item debit classification is supported here',409);
        demand(one($this->db,'SELECT id FROM checks WHERE dv_id=? LIMIT 1',[$dv['id']])===null,
            'This DV has a recorded check. Preserve payment history and document any reclassification using a separate adjusting entry.',409);
        demand(one($this->db,"SELECT e.id FROM tax_disposition_events e JOIN withholding_components w ON w.id=e.component_id WHERE w.dv_id=? AND e.state NOT IN ('PENDING') LIMIT 1",[$dv['id']])===null,
            'The DV has completed tax disposition. Use a separately documented accounting adjustment.',409);
        $alloc=q($this->db,'SELECT * FROM dv_allocation_lines WHERE dv_id=? ORDER BY id FOR UPDATE',[$dv['id']])->fetchAll();
        demand(count($alloc)>0,'Voucher allocations are missing',409);
        $taxes=q($this->db,'SELECT * FROM withholding_components WHERE dv_id=? ORDER BY id FOR UPDATE',[$dv['id']])->fetchAll();
        $accountingVersion=one($this->db,'SELECT id,version_no FROM dv_accounting_versions WHERE dv_id=? ORDER BY version_no DESC LIMIT 1',[$dv['id']]);
        $versionNo=(int)($accountingVersion['version_no']??0);
        demand(hash_equals($this->dvCorrectionToken($dv,$alloc,$taxes,$versionNo),requiredString($d,'expected_token',64)),
            'The voucher was updated elsewhere. Reopen it before saving.',409);
        demand(!$final||$versionNo>0,'Finalized DV accounting version is missing. Do not overwrite history.',409);
        $priorWorkpaper=$this->advanceLiquidationWorkpaper($org,$dv,$taxes);
        demand($priorWorkpaper['applicable'],'This DV accounting mode needs a separate item-to-journal correction design; no records were changed.',409);
        $reason=requiredString($d,'reason',500);
        $bill=trim((string)($d['bill_reference']??''));
        demand(mbSafeLen($bill)<=255,'Bill/evidence reference is too long');
        $oldBill=(string)($dv['bill_reference']??'');
        $newBill=$bill===''?null:$bill;
        demand(!$final || $oldBill===$bill,'A finalized DV bill reference is locked. Only debit UACS may be corrected.',409);
        $corrections=$d['uacs_allocations']??[];
        demand(is_array($corrections) && array_is_list($corrections) && count($corrections)<=count($alloc),'Invalid item corrections',400);
        $byId=[];foreach($alloc as $row)$byId[(int)$row['id']]=$row;
        $seen=[];$updates=[];
        foreach($corrections as $row){
            demand(is_array($row),'Invalid UACS correction');
            foreach(array_keys($row) as $key)demand(in_array($key,['allocation_id','uacs_code'],true),'Unrecognized UACS correction field',400);
            $id=idOf($row,'allocation_id');
            demand(isset($byId[$id]) && !isset($seen[$id]),'UACS allocation does not belong to this voucher',409);
            demand(!empty($byId[$id]['iar_line_id']),'Accepted IAR item required for this correction',409);
            $seen[$id]=true;
            $code=trim((string)($row['uacs_code']??''));
            demand($code==='' || (bool)preg_match('/^(?:502|104|106|108)[0-9]{7}$/D',$code),
                'Select an expense, inventory or property debit account; credit, cash, payable and tax accounts are not editable here.',409);
            if($code!=='')demand(one($this->db,'SELECT 1 FROM uacs_accounts WHERE code=? AND active=1',[$code])!==null,
                'Selected debit UACS is not enabled. The account must be configured first.',409);
            $old=trim((string)($byId[$id]['accounting_uacs_code']??''));
            if($code!==$old)$updates[]=['allocation_id'=>$id,'amount'=>$byId[$id]['amount'],'before'=>$old===''?null:$old,'after'=>$code===''?null:$code];
        }
        demand($updates || ($draft && $oldBill!==$bill),'No changes to save.',409);
        $oldJournal=[];
        if($final){
            demand($priorWorkpaper['ready'],'The current finalized DV workpaper no longer matches a valid accounting reference; correction needs manual reconciliation.',409);
            $oldJournal=q($this->db,'SELECT uacs_code,debit,credit,explanation FROM voucher_accounting_lines WHERE accounting_version_id=? ORDER BY id FOR UPDATE',[$accountingVersion['id']])->fetchAll();
            demand($this->dvJournalSignature($oldJournal,'debit')===$this->dvJournalSignature($priorWorkpaper['entries'],'debit') &&
                $this->dvJournalSignature($oldJournal,'credit')===$this->dvJournalSignature($priorWorkpaper['entries'],'credit'),
                'Saved finalized accounting differs from the current item workpaper. Preserve it and record a separate accounting adjustment.',409);
        }
        foreach($updates as $change)q($this->db,'UPDATE dv_allocation_lines SET accounting_uacs_code=? WHERE id=? AND dv_id=?',
            [$change['after'],$change['allocation_id'],$dv['id']]);
        if($draft && $oldBill!==$bill)q($this->db,'UPDATE disbursement_vouchers SET bill_reference=? WHERE id=?',[$newBill,$dv['id']]);
        $after=$this->advanceLiquidationWorkpaper($org,$dv,$taxes);
        if($final){
            demand($after['ready'],'Corrected UACS does not produce a complete balanced debit workpaper. Nothing was changed.',409);
            $credits=array_values(array_filter($oldJournal,static fn($line)=>moneyCents($line['credit'])>0));
            $newCredits=array_values(array_filter($after['entries'],static fn($line)=>moneyCents($line['credit'])>0));
            demand($this->dvJournalSignature($credits,'credit')===$this->dvJournalSignature($newCredits,'credit'),
                'Original credit account/amount would change; accounting correction blocked.',409);
            $newDebits=array_values(array_filter($after['entries'],static fn($line)=>moneyCents($line['debit'])>0));
            $oldGross=moneyCents($dv['gross']);$total=0;
            foreach($newDebits as $line)$total+=moneyCents($line['debit']);
            demand($total===$oldGross,'Debit-side reclassification must preserve the exact gross amount',409);
            $this->addAccounts((int)$dv['id'],array_merge($newDebits,$credits),
                'DOCUMENTARY UACS DEBIT CORRECTION: '.$reason,$versionNo+1);
        }
        $this->snapshot($org,'DV',(int)$dv['id'],[
            'accounting_classification_correction'=>true,'reason'=>$reason,
            'status_unchanged'=>$dv['status'],'display_number_unchanged'=>$dv['display_number'],
            'accounting_version_before'=>$versionNo,'accounting_version_after'=>$final?$versionNo+1:$versionNo,
            'debit_changes'=>$updates,'credit_unchanged'=>true,
            'gross_unchanged'=>$dv['gross'],'withholding_unchanged'=>$dv['deductions'],'net_unchanged'=>$dv['net'],
            'bill_reference_before'=>$dv['bill_reference'],'bill_reference_after'=>$draft?$newBill:$dv['bill_reference']],$reason);
        $this->audit($org,'DV_DEBIT_UACS_CORRECTED','DV',(int)$dv['id'],[
            'reason'=>$reason,'allocations'=>$updates,'accounting_version_before'=>$versionNo,
            'accounting_version_after'=>$final?$versionNo+1:$versionNo,
            'gross_unchanged'=>$dv['gross'],'withholding_unchanged'=>$dv['deductions'],
            'net_unchanged'=>$dv['net'],'credit_unchanged'=>true]);
        return ['id'=>(int)$dv['id'],'status'=>$dv['status'],'display_number'=>$dv['display_number'],
            'gross'=>$dv['gross'],'deductions'=>$dv['deductions'],'net'=>$dv['net'],
            'accounting_version'=>$final?$versionNo+1:$versionNo,'uacs_changes'=>count($updates)];
    });}
    /** Every locked claim may contribute MULTIPLE debit entries, but exactly
     * one payment-credit entry covers the WHOLE consolidated DV. Ignore browser
     * presentation order; compare a multiset of allocation+UACS+cents lines.
     */
    private function validateEmployeePaymentJournal(int $org,array $dv,array $entries): array {
        demand($dv['dv_type']==='TRAVEL_REIMBURSEMENT','Incorrect employee DV accounting type',409);
        $alloc=q($this->db,'SELECT id,amount FROM dv_allocation_lines WHERE dv_id=? ORDER BY id FOR UPDATE',[$dv['id']])->fetchAll();
        demand(count($alloc)>0 && count($alloc)<=20 && count($entries)<=1200,'Employee DV accounting exceeds allowed size',409);
        $original=[];foreach($alloc as $a)$original[(string)$a['id']]=moneyCents((string)$a['amount']);
        $evidence=$this->employeeReimbursementEvidence($org,(int)$dv['id']);
        demand($evidence['all_sources_finalized'],'A linked reimbursement lacks finalized itemized debit UACS evidence',409);
        $expected=[];$expectedAmount=[];
        foreach($evidence['debit_lines_by_allocation'] as $allocId=>$lines){
            demand(isset($original[(string)$allocId]),'Saved claim allocations no longer match the DV',409);
            foreach($lines as $line){
                $cent=moneyCents((string)$line['amount']);$code=(string)$line['debit_uacs_code'];
                $key=$allocId.'|'.$code.'|'.$cent;
                $expected[$key]=($expected[$key]??0)+1;
                $expectedAmount[(string)$allocId]=($expectedAmount[(string)$allocId]??0)+$cent;
            }
        }
        demand(count($original)===count($expectedAmount),'All reimbursement claims require locked debit evidence',409);
        foreach($original as $id=>$cents)demand(($expectedAmount[$id]??-1)===$cents,
            'Locked reimbursement UACS lines do not total their original claim',409);
        $got=[];$debitTotal=0;$creditTotal=0;$creditLines=0;
        $fund=$this->record('funds',(int)$dv['fund_id'],$org);
        $policy=$this->schoolUtilizableFundingMode($org,(int)$dv['fund_id']);
        $nonIus=$policy['policy']['school_classification']==='NON_IUS' &&
            (string)$fund['source_code']==='MOOE';
        foreach($entries as $entry){
            demand(is_array($entry),'Invalid employee journal entry',422);
            $debit=moneyCents((string)($entry['debit']??'0'));
            $credit=moneyCents((string)($entry['credit']??'0'));
            $code=requiredString($entry,'uacs_code',32);
            if($debit>0){
                $id=(string)($entry['allocation_id']??'');
                demand(isset($original[$id]) && $credit===0 && preg_match('/^[15][0-9]{9}$/D',$code)===1 &&
                    one($this->db,'SELECT code FROM uacs_accounts WHERE code=? AND active=1',[$code])!==null,
                    'Invalid employee debit allocation or inactive expense/asset UACS',409);
                $key=$id.'|'.$code.'|'.$debit;$got[$key]=($got[$key]??0)+1;$debitTotal+=$debit;
            }else{
                demand($credit>0 && !isset($entry['allocation_id']) && $debit===0,
                    'Employee payment must have one separate credit entry',409);
                $creditTotal+=$credit;$creditLines++;
                if($nonIus)demand($code==='1990101000',
                    'Non-IUS MOOE reimbursements must credit Advances for Operating Expenses',409);
            }
        }
        ksort($got);ksort($expected);
        demand($got===$expected && $creditLines===1 && $debitTotal===$creditTotal &&
            $debitTotal===moneyCents((string)$dv['gross']) &&
            moneyCents((string)$dv['net'])===$creditTotal,
            'Itemized travel/PR/PO debits must reconcile to one consolidated DV credit without changes',409);
        return $entries;
    }

    /** Only approved, immutable source lines can become DV accounting debits.
     * Historical finalized sources with one debit UACS remain readable.
     */
    private function employeeReimbursementEvidence(int $org,int $dvId): array {
        $codes=[];$lines=[];$references=[];$complete=true;$count=0;
        $linked=[];
        $travel=q($this->db,'SELECT claim_id,allocation_line_id FROM travel_dv_claim_links WHERE organization_id=? AND dv_id=? ORDER BY claim_id',[$org,$dvId])->fetchAll(PDO::FETCH_ASSOC);
        foreach($travel as $r)$linked[]=['kind'=>'T','claim_id'=>(int)$r['claim_id'],'line_id'=>(int)$r['allocation_line_id']];
        foreach(EmployeeClaims::dvSources($this->db,$org,$dvId) as $r)
            $linked[]=['kind'=>'O','claim_id'=>(int)$r['claim_id'],'line_id'=>(int)$r['allocation_line_id']];
        foreach($linked as $link){
            $count++;$source=ReimbursementFinalization::load($this->db,$org,$link['kind'],$link['claim_id']);
            $line=(string)$link['line_id'];
            if($source===null || (int)$line<=0 || empty($source['payment_authority_reference'])){$complete=false;continue;}
            $blocks=$link['kind']==='T'?($source['debit_splits']??[]):($source['debit_items']??[]);
            if(!$blocks && !empty($source['debit_uacs_code'])){
                $blocks=[['debit_uacs_code'=>$source['debit_uacs_code'],'amount'=>$source['approved_amount']]];
                $codes[$line]=(string)$source['debit_uacs_code'];
            }
            if(!$blocks){$complete=false;continue;}
            $verified=[];$sum=0;
            foreach($blocks as $block){
                $cents=moneyCents((string)($block['amount']??'0'));
                $code=(string)($block['debit_uacs_code']??'');
                demand($cents>0 && preg_match('/^[15][0-9]{9}$/D',$code)===1,
                    'Locked reimbursement itemization is invalid',409);
                $verified[]=['debit_uacs_code'=>$code,'amount'=>moneyString($cents),
                    'description'=>(string)($block['component']??$block['item_name']??'Claim expense')];
                $sum+=$cents;
            }
            demand($sum===moneyCents((string)$source['approved_amount']),
                'Finalized reimbursement itemized total changed',409);
            $lines[$line]=$verified;
            $references[]=$source['payment_authority_reference'];
        }
        $references=array_values(array_unique($references));$summary=implode('; ',$references);
        if(mbSafeLen($summary)>255)$summary='Signed reimbursement approvals on '.count($references).
            ' linked finalized claims · DV #'.$dvId.'; see each immutable source authority reference';
        return ['all_sources_finalized'=>$complete && $count>0,
            'debit_uacs_by_allocation'=>$codes,'debit_lines_by_allocation'=>$lines,
            'signed_authority_refs'=>$references,'payment_authority_summary'=>$summary,'source_count'=>$count];
    }

    /** Compare accounting by UACS and amount without depending on presentation order. */
    private function dvJournalSignature(array $lines,string $side): array {
        $total=[];
        foreach($lines as $line){$c=moneyCents($line[$side]??'0.00');if($c<=0)continue;
            $code=(string)$line['uacs_code'];$total[$code]=($total[$code]??0)+$c;}
        ksort($total,SORT_STRING);return $total;
    }
    private function dvCorrectionToken(array $dv,array $alloc,array $taxes,int $accountingVersion): string {
        return hash('sha256',json_encode([$dv,$alloc,$taxes,$accountingVersion],JSON_THROW_ON_ERROR));
    }
    private function draftDvToken(array $dv,array $alloc,array $taxes): string {
        return hash('sha256',json_encode([$dv,$alloc,$taxes],JSON_THROW_ON_ERROR));
    }
    private function validateAllocations(array $dv): void {
        if($this->taxTransferRecord((int)$dv['organization_id'],(int)$dv['id'])!==null){
            $this->taxTransferValidate((int)$dv['organization_id'],$dv,true);return;
        }
        if($dv['dv_type']==='TRAVEL_REIMBURSEMENT'){$this->validateEmployeeDvForPayment((int)$dv['organization_id'],$dv);return;}
        $id=(int)$dv['id'];$kind=$dv['dv_type'];$all=q($this->db,'SELECT * FROM dv_allocation_lines WHERE dv_id=? ORDER BY id',[$id])->fetchAll();demand(count($all)>0,'Voucher has no allocations');
        $seen=[];
        foreach($all as $l){$amt=moneyCents($l['amount']);
            if($kind==='DIRECT'){$source=$this->record('purchase_requests',(int)$l['pr_id'],(int)$dv['organization_id'],true);demand($source['status']==='FINALIZED'&&(int)$source['fund_id']===(int)$dv['fund_id'],'PR invalid for this Direct DV');$limit=moneyCents($source['total']);$sql="SELECT COALESCE(SUM(a.amount),0) total FROM dv_allocation_lines a JOIN disbursement_vouchers v ON v.id=a.dv_id WHERE a.pr_id=? AND a.iar_line_id IS NULL AND v.status='FINALIZED'";$key='PR'.$l['pr_id'];$params=[$l['pr_id']];}
            else{
                $src=one($this->db,"SELECT l.*,i.status iar_status,po.organization_id,po.status po_status,pr.fund_id FROM iar_lines l JOIN iars i ON i.id=l.iar_id JOIN purchase_orders po ON po.id=i.po_id JOIN purchase_requests pr ON pr.id=po.pr_id WHERE l.id=? FOR UPDATE",[$l['iar_line_id']]);demand($src!==null&&(int)$src['organization_id']===(int)$dv['organization_id']&&(int)$src['fund_id']===(int)$dv['fund_id']&&$src['iar_status']==='FINALIZED'&&$src['po_status']==='FINALIZED','Source IAR invalid');$limit=moneyCents($src['accepted_amount']);$sql="SELECT COALESCE(SUM(a.amount),0) total FROM dv_allocation_lines a JOIN disbursement_vouchers v ON v.id=a.dv_id WHERE a.iar_line_id=? AND v.status='FINALIZED'";$key='IAR'.$l['iar_line_id'];$params=[$l['iar_line_id']];
                if($kind==='REIMBURSEMENT'){$adv=one($this->db,'SELECT * FROM advances WHERE id=? AND iar_line_id=? FOR UPDATE',[$l['advance_id'],$l['iar_line_id']]);demand($adv!==null && $adv['original_payer']===$dv['payee'] && $adv['evidence_reference']!=='','Verified matching advance required');$advancePrior=one($this->db,"SELECT COALESCE(SUM(a.amount),0) total FROM dv_allocation_lines a JOIN disbursement_vouchers v ON v.id=a.dv_id WHERE a.advance_id=? AND v.status='FINALIZED'",[$adv['id']]);$ak='ADV'.$adv['id'];$usedAdvance=$seen[$ak]??0;demand(moneyCents($advancePrior['total'])+$usedAdvance+$amt<=moneyCents($adv['amount']),'Advance already reimbursed or insufficient');$seen[$ak]=$usedAdvance+$amt;}
            }
            $prior=one($this->db,$sql,$params);$used=$seen[$key]??0;demand(moneyCents($prior['total'])+$used+$amt<=$limit,'Allocation exceeds available unpaid/unreimbursed amount');$seen[$key]=$used+$amt;
        }
    }
    /** Division-specific UACS mapping, versioned in existing configuration history.
     * Item Catalog is shared, so global item-account writes would leak a
     * Division's classification into every other Division. We never do that.
     */
    private function divisionItemUacsConfig(int $division): array {
        $row=one($this->db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',
            [$division,self::DIVISION_ITEM_UACS_KEY]);
        if($row===null)return ['version_id'=>0,'mappings'=>[]];
        $json=json_decode((string)$row['config_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($json)&&isset($json['mappings'])&&is_array($json['mappings']),
            'Invalid Division item accounting configuration',500);
        return ['version_id'=>(int)$row['id'],'mappings'=>$json['mappings']];
    }
    public function divisionItemUacsSettings(array $d): array {
        $org=$this->org($d);$division=$this->divisionId($org);
        $settings=$this->divisionItemUacsConfig($division);
        $ids=array_values(array_unique(array_filter(array_map(static fn($m)=>(int)($m['item_id']??0),$settings['mappings']))));
        $allowed=[];
        if($ids){
            $params=array_merge([$org,$division],$ids);
            $placeholders=implode(',',array_fill(0,count($ids),'?'));
            $allowedRows=q($this->db,'SELECT i.id FROM item_catalog i  '
                .'WHERE (i.owner_organization_id IS NULL OR i.owner_organization_id=? OR i.owner_organization_id=?) AND i.id IN ('.$placeholders.')',$params)->fetchAll();
            foreach($allowedRows as $row)$allowed[(int)$row['id']]=true;
        }
        $settings['mappings']=array_values(array_filter($settings['mappings'],
            static fn($m)=>isset($allowed[(int)($m['item_id']??0)])));
        // Read-only official UACS titles for scoped, approved item mappings.
        // An account may be inactive today yet valid on an earlier physical issue.
        $codes=array_values(array_unique(array_filter(array_map(
            static fn($m)=>(string)($m['uacs_code']??''),$settings['mappings']))));
        $titles=[];
        if($codes){
            foreach(array_chunk($codes,300) as $batch){
                $placeholders=implode(',',array_fill(0,count($batch),'?'));
                foreach(q($this->db,'SELECT code,account_name FROM uacs_accounts WHERE code IN ('.$placeholders.')',$batch)->fetchAll() as $account)
                    $titles[(string)$account['code']]=(string)$account['account_name'];
            }
        }
        $settings['account_titles']=$titles;
        return ['division_id'=>$division]+$settings;
    }

    /** Replace exactly one dated Division mapping in the new configuration
     * snapshot. Older snapshots in configuration_versions remain untouched.
     * This is a correction, never an additional overlapping date entry.
     */
    private static function correctedItemUacsMappings(array $mappings,int $itemId,string $oldDate,string $newDate,string $code): array {
        demand($oldDate!==$newDate,'The corrected effective date must differ from the current date',409);
        $index=null;
        foreach($mappings as $i=>$mapping){
            if((int)($mapping['item_id']??0)!==$itemId)continue;
            if((string)($mapping['effective_from']??'')===$newDate)
                demand(false,'Another mapping for this item already has the proposed effective date',409);
            if((string)($mapping['effective_from']??'')!==$oldDate)continue;
            demand($index===null,'Multiple mappings have the original effective date. Accounting administrator review required',409);
            $index=$i;
        }
        demand($index!==null,'Original dated mapping not found. Refresh before correcting',409);
        $old=$mappings[$index];
        demand((string)($old['uacs_code']??'')===$code,'UACS selection changed. Refresh before correcting',409);
        $mappings[$index]['effective_from']=$newDate;
        $mappings[$index]['source']='AUTHORIZED_EFFECTIVITY_CORRECTION';
        $mappings[$index]['correction_of_effective_from']=$oldDate;
        $new=$mappings[$index];
        usort($mappings,static fn($a,$b)=>((int)$a['item_id']<=>(int)$b['item_id'])
            ?:strcmp((string)$a['effective_from'],(string)$b['effective_from']));
        return ['mappings'=>$mappings,'old'=>$old,'new'=>$new];
    }

    /** Read-only candidate resolver for impact review; no journal is posted. */
    private static function divisionMappingAt(array $mappings,int $itemId,string $date): ?string {
        $matching=array_values(array_filter($mappings,static fn($row)=>
            (int)($row['item_id']??0)===$itemId &&
            (string)($row['effective_from']??'')<=$date));
        usort($matching,static fn($a,$b)=>strcmp((string)$b['effective_from'],(string)$a['effective_from']));
        if(!$matching)return null;
        if(count($matching)>1 && $matching[0]['effective_from']===$matching[1]['effective_from'])
            return 'AMBIGUOUS';
        return (string)$matching[0]['uacs_code'];
    }
    /** Preview all finalized IAR lines in this Division (including its schools)
     * whose applicable Division mapping would change. Do not update source rows.
     * Keep preview bounded; never silently skip undisplayed impacted records.
     */
    private function itemEffectivityImpact(int $division,int $itemId,array $before,array $after): array {
        $records=q($this->db,"SELECT il.id iar_line_id,i.id iar_id,i.display_number iar_number,
                   i.accepted_at,org.name organization_name,il.accepted_amount,
                   GROUP_CONCAT(DISTINCT CASE WHEN dv.status='DRAFT' THEN dv.id END ORDER BY dv.id) draft_dv_ids,
                   MAX(CASE WHEN dv.status='FINALIZED' THEN 1 ELSE 0 END) finalized_dv
                FROM iar_lines il
                JOIN iars i ON i.id=il.iar_id AND i.status='FINALIZED'
                JOIN purchase_orders po ON po.id=i.po_id AND po.status='FINALIZED'
                JOIN po_lines pl ON pl.id=il.po_line_id AND pl.po_id=po.id
                JOIN organizations org ON org.id=po.organization_id
                LEFT JOIN dv_allocation_lines al ON al.iar_line_id=il.id
                LEFT JOIN disbursement_vouchers dv ON dv.id=al.dv_id AND dv.organization_id=po.organization_id
                WHERE pl.item_id=? AND (org.id=? OR org.parent_division_id=?)
                GROUP BY il.id,i.id,i.display_number,i.accepted_at,org.name,il.accepted_amount
                ORDER BY i.accepted_at,il.id LIMIT 201",[$itemId,$division,$division])->fetchAll();
        demand(count($records)<=200,'More than 200 accepted lines reference this item. Ask the technical administrator for a scoped accounting review before correcting',409);
        $impacts=[];$finalized=0;
        foreach($records as $row){
            $date=(string)$row['accepted_at'];
            $old=self::divisionMappingAt($before,$itemId,$date);
            $new=self::divisionMappingAt($after,$itemId,$date);
            if($old===$new)continue;
            $hasFinalized=(int)$row['finalized_dv']>0;
            if($hasFinalized)$finalized++;
            $impacts[]=['iar_id'=>(int)$row['iar_id'],'iar_line_id'=>(int)$row['iar_line_id'],
                'iar_number'=>(string)($row['iar_number']??''),
                'organization_name'=>(string)$row['organization_name'],
                'accepted_at'=>$date,'accepted_amount'=>(string)$row['accepted_amount'],
                'old_division_uacs'=>$old,'new_division_uacs'=>$new,
                'draft_dv_ids'=>(string)($row['draft_dv_ids']??''),
                'has_finalized_dv'=>$hasFinalized];
        }
        return ['affected_iars'=>$impacts,'affected_line_count'=>count($impacts),
            'affected_finalized_line_count'=>$finalized];
    }
    private function prepareItemEffectivityCorrection(array $d,int $division): array {
        $item=idOf($d,'item_id');
        $oldDate=self::policyDate(requiredString($d,'old_effective_from',10));
        $newDate=self::policyDate(requiredString($d,'new_effective_from',10));
        $code=requiredString($d,'uacs_code',32);
        $expected=(int)($d['expected_version']??-1);
        demand($expected>0,'Refresh Division UACS mappings before correcting',409);
        $cfg=$this->divisionItemUacsConfig($division);
        demand((int)$cfg['version_id']===$expected,
            'Division item UACS configuration changed. Refresh and preview again',409);
        $catalog=one($this->db,'SELECT id,name FROM item_catalog WHERE id=?',[$item]);
        demand($catalog!==null,'Mapped catalog item no longer exists',409);
        $revised=self::correctedItemUacsMappings($cfg['mappings'],$item,$oldDate,$newDate,$code);
        $impact=$this->itemEffectivityImpact($division,$item,$cfg['mappings'],$revised['mappings']);
        $token=hash('sha256',json_encode([$division,$expected,$item,$oldDate,$newDate,$code,
            $impact],JSON_THROW_ON_ERROR));
        return ['division_id'=>$division,'item_id'=>$item,'item_name'=>(string)$catalog['name'],
            'uacs_code'=>$code,'old_effective_from'=>$oldDate,'new_effective_from'=>$newDate,
            'expected_version'=>$expected,'old_mapping'=>$revised['old'],
            'new_mapping'=>$revised['new'],'review_token'=>$token,
            'can_apply'=>$impact['affected_finalized_line_count']===0]+$impact;
    }
    public function previewItemUacsEffectivityCorrection(array $d): array {
        $org=$this->org($d);$this->referenceWriter();$division=$this->divisionId($org);
        demand($org===$division,'Select the owning Division Office workspace to correct shared UACS reference data',403);
        return $this->prepareItemEffectivityCorrection($d,$division);
    }
    public function applyItemUacsEffectivityCorrection(array $d): array {
        $org=$this->org($d);$this->referenceWriter();$division=$this->divisionId($org);
        demand($org===$division,'Select the owning Division Office workspace to correct shared UACS reference data',403);
        $reason=requiredString($d,'reason',200);
        $authority=requiredString($d,'authority_reference',200);
        demand(mbSafeLen($reason)>=12 && mbSafeLen($authority)>=5,
            'Provide the correction explanation and supporting authority reference',409);
        $token=requiredString($d,'review_token',64);
        demand((bool)preg_match('/^[a-f0-9]{64}$/D',$token),'Invalid review token',409);
        return $this->tx(function()use($d,$org,$division,$reason,$authority,$token){
            // One Division row serializes corrections, mappings and bulk writers.
            demand(one($this->db,"SELECT id FROM organizations WHERE id=? AND kind='DIVISION' FOR UPDATE",[$division])!==null,
                'Division reference authority unavailable',409);
            $preview=$this->prepareItemEffectivityCorrection($d,$division);
            demand(hash_equals($preview['review_token'],$token),
                'Impact preview is stale. Refresh and preview the mapping correction again',409);
            demand($preview['can_apply'],
                'A finalized DV references an affected IAR. Do not alter its effective historical classification; request formal accounting review',409);
            $cfg=$this->divisionItemUacsConfig($division);
            $corrected=self::correctedItemUacsMappings($cfg['mappings'],(int)$preview['item_id'],
                $preview['old_effective_from'],$preview['new_effective_from'],$preview['uacs_code']);
            // Append new full mapping snapshot; preserve old snapshots and DV data.
            $version=$this->insert('configuration_versions',[
                'organization_id'=>$division,'config_key'=>self::DIVISION_ITEM_UACS_KEY,
                'config_json'=>json_encode(['mappings'=>$corrected['mappings']],JSON_THROW_ON_ERROR),
                'effective_from'=>self::todayManila(),
                'review_note'=>'UACS EFFECTIVITY CORRECTION: '.$reason.' | authority: '.$authority]);
            $this->audit($org,'DIVISION_ITEM_UACS_EFFECTIVITY_CORRECTED','ITEM',(int)$preview['item_id'],[
                'division_id'=>$division,'prior_config_version'=>$cfg['version_id'],
                'new_config_version'=>$version,'old_mapping'=>$corrected['old'],
                'corrected_mapping'=>$corrected['new'],'affected_line_count'=>$preview['affected_line_count'],
                'reason'=>$reason,'authority_reference'=>$authority]);
            return ['changed'=>true,'version_id'=>$version,'item_id'=>$preview['item_id'],
                'old_effective_from'=>$preview['old_effective_from'],
                'new_effective_from'=>$preview['new_effective_from'],
                'affected_line_count'=>$preview['affected_line_count']];
        });
    }
    public function saveDivisionItemUacs(array $d): array {
        $org=$this->org($d);$this->referenceWriter();$division=$this->divisionId($org);
        $itemId=idOf($d,'item_id');$account=requiredString($d,'uacs_code',32);
        demand((bool)preg_match('/^(502|104|106|108)[0-9]{7}$/D',$account),
            'Select an eligible expense, inventory or asset debit UACS (502/104/106/108)');
        $effective=self::policyDate(requiredString($d,'effective_from',10));
        $reason=requiredString($d,'reason',500);
        $expected=(int)($d['expected_version']??-1);
        demand($expected>=0,'Refresh Division UACS mappings before saving',409);
        return $this->tx(function()use($org,$division,$itemId,$account,$effective,$reason,$expected){
            demand(one($this->db,"SELECT id FROM organizations WHERE id=? AND kind='DIVISION' FOR UPDATE",[$division])!==null,
                'Division record unavailable',409);
            $config=$this->divisionItemUacsConfig($division);
            demand((int)$config['version_id']===$expected,'Division UACS mappings changed. Refresh before saving.',409);
            demand(one($this->db,'SELECT id FROM item_catalog WHERE id=?',[$itemId])!==null,'Catalog item not found',404);
            $uacs=one($this->db,'SELECT code,active FROM uacs_accounts WHERE code=?',[$account]);
            demand($uacs!==null&&(int)$uacs['active']===1,'Enable and review the chosen debit account in UACS Accounts first',409);
            $rows=$config['mappings'];$before=null;$changed=false;
            foreach($rows as &$row){
                if((int)($row['item_id']??0)===$itemId && ($row['effective_from']??'')===$effective){
                    $before=$row;
                    if(($row['uacs_code']??'')!==$account){$row['uacs_code']=$account;$row['source']='MANUAL_OVERRIDE';$changed=true;}
                    break;
                }
            }unset($row);
            if($before===null){demand(count($rows)<5000,'Division UACS mapping capacity reached');
                $rows[]=['item_id'=>$itemId,'uacs_code'=>$account,'effective_from'=>$effective];$changed=true;}
            if(!$changed)return ['changed'=>false,'version_id'=>$expected,'item_id'=>$itemId,'uacs_code'=>$account];
            usort($rows,static fn($a,$b)=>((int)$a['item_id']<=>(int)$b['item_id'])
                ?:strcmp((string)$a['effective_from'],(string)$b['effective_from']));
            $new=$this->insert('configuration_versions',['organization_id'=>$division,
                'config_key'=>self::DIVISION_ITEM_UACS_KEY,
                'config_json'=>json_encode(['mappings'=>$rows],JSON_THROW_ON_ERROR),
                'effective_from'=>self::todayManila(),'review_note'=>$reason]);
            $this->audit($org,'DIVISION_ITEM_UACS_MAPPED','ITEM',$itemId,
                ['division_id'=>$division,'old'=>$before,'new'=>['uacs_code'=>$account,'effective_from'=>$effective],
                 'version_id'=>$new,'reason'=>$reason]);
            return ['changed'=>true,'version_id'=>$new,'item_id'=>$itemId,'uacs_code'=>$account];
        });
    }
    /** Preview/apply a bounded bulk map for EXISTING, still-unmapped Division consumables.
     * A Division row lock serializes approvals and prevents two administrators
     * from silently writing competing configuration versions.
     */
    private function prepareBulkConsumableUacs(array $d,int $org,int $division): array {
        $raw=$d['item_ids']??null;
        demand(is_array($raw)&&count($raw)>=1&&count($raw)<=100,
            'Select between 1 and 100 unmapped consumables per batch');
        $ids=[];
        foreach($raw as $value){
            demand(is_int($value)&&$value>0,'Invalid catalog item selection');
            demand(!isset($ids[$value]),'The same catalog item was selected twice');
            $ids[$value]=true;
        }
        $ids=array_keys($ids);sort($ids,SORT_NUMERIC);
        $code=requiredString($d,'classification_code',64);
        $date=self::policyDate(requiredString($d,'effective_from',10));
        demand(one($this->db,"SELECT id FROM organizations WHERE id=? AND kind='DIVISION' FOR UPDATE",[$division])!==null,
            'Division is unavailable',409);
        $cfg=$this->divisionItemUacsConfig($division);
        demand((int)$cfg['version_id']===(int)($d['expected_version']??-1),
            'Division UACS mappings changed. Refresh the list and preview again.',409);
        $version=one($this->db,'SELECT id FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',
            [$division,self::DIVISION_CLASS_KEY]);
        $classVersion=(int)($version['id']??0);
        $chosen=null;
        foreach($this->divisionClasses($division) as $entry){
            if($entry['code']===$code && ($entry['applies_to']??'')==='SUPPLY' && !empty($entry['active'])){
                $chosen=$entry;break;
            }
        }
        demand($chosen!==null,'Select an enabled Division school-use consumable class',409);
        $account=(string)($chosen['default_uacs_code']??'');
        demand(self::divisionClassUacsAllowed('SUPPLY',$account),
            'The selected consumable class has no eligible approved 502 account',409);
        $active=one($this->db,'SELECT code,active FROM uacs_accounts WHERE code=?',[$account]);
        demand($active!==null&&(int)$active['active']===1,
            'The Division class UACS account must be enabled before bulk mapping',409);
        $existing=[];
        foreach($cfg['mappings'] as $r)$existing[(int)($r['item_id']??0)]=true;
        foreach($ids as $id)demand(!isset($existing[$id]),
            'At least one selected consumable already has a Division mapping. Refresh and exclude mapped items.',409);
        $params=$ids;
        $sql='SELECT id,name,category,active FROM item_catalog WHERE id IN (' .
            implode(',',array_fill(0,count($ids),'?')).') ORDER BY id FOR UPDATE';
        $items=q($this->db,$sql,$params)->fetchAll();
        demand(count($items)===count($ids),'A selected catalog item was not found',409);
        foreach($items as $item)demand($item['category']==='SUPPLY' && (int)$item['active']===1,
            'Only active consumable supplies can be bulk-mapped',409);
        demand(count($cfg['mappings'])+count($ids)<=5000,'Division UACS mapping capacity reached',409);
        $snapshot=[$division,$cfg['version_id'],$classVersion,$code,$account,$date,
            array_map(static fn($it)=>[(int)$it['id'],(string)$it['name']],$items)];
        return ['division_id'=>$division,'expected_version'=>$cfg['version_id'],
            'class_version'=>$classVersion,'classification_code'=>$code,
            'classification_name'=>(string)$chosen['name'], 'uacs_code'=>$account,
            'effective_from'=>$date,'items'=>array_map(static fn($it)=>['id'=>(int)$it['id'],'name'=>(string)$it['name']],$items),
            'review_token'=>hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR)),
            'mappings'=>$cfg['mappings']];
    }
    public function previewBulkConsumableUacs(array $d): array {
        $org=$this->org($d);$this->referenceWriter();$division=$this->divisionId($org);
        return $this->tx(function()use($d,$org,$division){
            $result=$this->prepareBulkConsumableUacs($d,$org,$division);
            unset($result['mappings']);return $result;
        });
    }
    public function applyBulkConsumableUacs(array $d): array {
        $org=$this->org($d);$this->referenceWriter();$division=$this->divisionId($org);
        $reason=requiredString($d,'reason',500);
        $token=requiredString($d,'review_token',64);
        demand(preg_match('/^[a-f0-9]{64}$/D',$token)===1,'Invalid bulk review token',409);
        return $this->tx(function()use($d,$org,$division,$reason,$token){
            $review=$this->prepareBulkConsumableUacs($d,$org,$division);
            demand(hash_equals($review['review_token'],$token),
                'Bulk preview is stale. Preview the items and UACS again before applying.',409);
            $rows=$review['mappings'];
            foreach($review['items'] as $item)$rows[]=[
                'item_id'=>$item['id'],'uacs_code'=>$review['uacs_code'],
                'effective_from'=>$review['effective_from'],
                'classification_code'=>$review['classification_code'],
                'classification_name'=>$review['classification_name'],
                'classification_type'=>'SUPPLY',
                'classification_version_id'=>$review['class_version'],
                'source'=>'BULK_DIVISION_CLASS'
            ];
            usort($rows,static fn($a,$b)=>(int)$a['item_id']<=>(int)$b['item_id']
                ?:strcmp((string)$a['effective_from'],(string)$b['effective_from']));
            $new=$this->insert('configuration_versions',[
                'organization_id'=>$division,'config_key'=>self::DIVISION_ITEM_UACS_KEY,
                'config_json'=>json_encode(['mappings'=>$rows],JSON_THROW_ON_ERROR),
                'effective_from'=>self::todayManila(),
                'review_note'=>'Bulk consumable classification: '.$reason]);
            foreach($review['items'] as $item)$this->audit($org,'BULK_DIVISION_ITEM_UACS_MAPPED','ITEM',$item['id'],[
                'division_id'=>$division,'classification_code'=>$review['classification_code'],
                'account'=>$review['uacs_code'],'effective_from'=>$review['effective_from'],
                'class_version'=>$review['class_version'],'version_id'=>$new,'reason'=>$reason]);
            return ['changed'=>count($review['items']),'item_ids'=>array_column($review['items'],'id'),
                'version_id'=>$new,'uacs_code'=>$review['uacs_code'],
                'classification_code'=>$review['classification_code']];
        });
    }
    /** A document-level liquidation workpaper, NOT a second bank transaction.
     * For Non-IUS MOOE only, derive expense/asset debits from effective-dated
     * explicit item mappings and credit Advances for Operating Expenses for the
     * NET supplier payment; withholding stays a separate Due-to-BIR component.
     * Never guess an expense code from an item category or silently use AP.
     */
    /** Locate the next Division item mapping for explanation only; never backdate it. */
    private static function nextItemMappingDate(array $mappings,int $itemId,string $iarDate): ?string {
        $dates=[];
        foreach($mappings as $mapping){
            if((int)($mapping['item_id']??0)!==$itemId)continue;
            $date=(string)($mapping['effective_from']??'');
            if($date!=='' && $date>$iarDate)$dates[]=$date;
        }
        sort($dates,SORT_STRING);
        return $dates[0]??null;
    }
    private function advanceLiquidationWorkpaper(int $org,array $dv,?array $taxes=null): array {
        $fund=$this->record('funds',(int)$dv['fund_id'],$org);
        $result=['applicable'=>false,'ready'=>false,'stage'=>'DOCUMENT_WORKPAPER_NOT_BANK_POSTING',
            'treatment'=>'UNCONFIGURED','entries'=>[],'issues'=>[],
            'item_diagnostics'=>[],'debit_total'=>'0.00','credit_total'=>'0.00',
            'debit_difference'=>'0.00','unmapped_amount'=>'0.00',
            'gross'=>(string)$dv['gross'],'withheld'=>(string)$dv['deductions'],'net'=>(string)$dv['net']];
        if($dv['dv_type']!=='REGULAR'){
            $result['message']='This workpaper applies only to Regular PO/IAR vouchers.';
            return $result;
        }
        $isMooe=strtoupper((string)$fund['source_code'])==='MOOE';
        $organization=one($this->db,'SELECT kind FROM organizations WHERE id=?',[$org]);
        demand($organization!==null,'Accounting organization is unavailable',409);
        if($organization['kind']!=='SCHOOL'){
            $result['message']='Division-office accounting must not be classified as a Non-IUS school advance.';
            return $result;
        }
        $class=$this->currentSchoolClass($org,self::todayManila());
        if($class!==null&&$class['classification']!=='NON_IUS'){
            $result['message']='IUS accounting treatment is not assumed to be an operating advance.';
            return $result;
        }
        $result['applicable']=true;
        $result['treatment']=$isMooe?'NON_IUS_MOOE_OPERATING_ADVANCE':'NON_IUS_DIVISION_APPROVED_SOURCE_PAYMENT';
        if($class===null)$result['issues'][]='The Division has not verified the school’s IUS/Non-IUS classification.';
        $paymentCredit='1990101000';$taxCredit='2020101000';$accountingPolicyVersion=0;
        if(!$isMooe){
            $policy=$this->regularDvTaxPolicy($org,(int)$fund['id']);
            $catalog=$this->divisionFundSourceList($this->divisionId($org));
            $active=false;
            foreach($catalog['sources'] as $source)if(($source['code']??'')===$fund['source_code'] && !empty($source['active'])){$active=true;break;}
            if(!$active)$result['issues'][]='This source is not active in the Division master directory. Activation does not create another bank credit.';
            $accountingPolicyVersion=(int)$policy['version_id'];
            $paymentCredit=(string)($policy['payment_credit_uacs']??'');
            $taxCredit=(string)($policy['withholding_liability_uacs']??'');
            if(!$policy['ready'])$result['issues'][]='Division withholding policy for this source/class is incomplete.';
            if(!preg_match('/^[12][0-9]{9}$/D',$paymentCredit) ||
                !preg_match('/^2[0-9]{9}$/D',$taxCredit) || $paymentCredit===$taxCredit)
                $result['issues'][]='Division Accountant must approve two distinct active credit UACS under this fund source policy: net payment and withheld-tax liability.';
            $result['message']='The PR-date item UACS are proposed expense/asset debits. The two credit accounts must come from the approved Division fund/class policy; check and bank movements remain separate.';
        }
        $result['accounting_policy_version_id']=$accountingPolicyVersion;
        $result['payment_credit_uacs']=$paymentCredit;
        $result['withholding_liability_uacs']=$taxCredit;
        $rows=q($this->db,"SELECT al.id allocation_id,al.amount,al.accounting_uacs_code,pl.item_id,pl.pr_line_id,pr.id AS pr_id,cat.name item_name,i.accepted_at,pr.document_date AS pr_document_date
            FROM dv_allocation_lines al
            JOIN iar_lines il ON il.id=al.iar_line_id
            JOIN iars i ON i.id=il.iar_id AND i.status='FINALIZED'
            JOIN po_lines pl ON pl.id=il.po_line_id
            JOIN item_catalog cat ON cat.id=pl.item_id
            JOIN purchase_orders po ON po.id=pl.po_id AND po.organization_id=? AND po.status='FINALIZED'
            JOIN purchase_requests pr ON pr.id=po.pr_id AND pr.organization_id=?
            WHERE al.dv_id=? ORDER BY al.id",[$org,$org,$dv['id']])->fetchAll();
        if(count($rows)===0)$result['issues'][]='This DV has no validated PO/IAR allocations.';
        $divisionMappings=$this->divisionItemUacsConfig($this->divisionId($org))['mappings'];
        $group=[];$sum=0;$unmapped=0;$prUacsCache=[];
        foreach($rows as $row){
            $amount=moneyCents($row['amount']);$sum+=$amount;
            // PR displays Division item accounting at PR date. Preserve MOOE's
            // existing IAR-date mapping; for SIIF/etc use original PR date.
            $asOf=$isMooe?(string)$row['accepted_at']:(string)$row['pr_document_date'];
            if(!$isMooe)self::transactionDocumentDate($asOf,'Original PR UACS classification date');
            // Read-only diagnostics use this DV's saved allocation, not a
            // catalog price or the full IAR total for partially claimed lines.
            $override=trim((string)($row['accounting_uacs_code']??''));
            $diagnostic=['allocation_id'=>(int)$row['allocation_id'],
                'item_id'=>(int)$row['item_id'],
                'item_name'=>(string)$row['item_name'],
                'iar_accepted_at'=>(string)$row['accepted_at'],
                'pr_document_date'=>(string)$row['pr_document_date'],
                'mapping_as_of'=>$asOf,'mapping_basis'=>$isMooe?'IAR_ACCEPTANCE':'ORIGINAL_PR_DATE',
                'allocated_amount'=>moneyString($amount),
                'status'=>$override===''?'MAPPED':'DV_CORRECTION',
                'uacs_code'=>null,'override_uacs_code'=>$override,
                'effective_from'=>null,'next_effective_from'=>null];
            if($override!==''){
                // A DV-only correction must never conceal an invalid finalized PR
                // accounting snapshot. Check the original source FIRST, without
                // replacing the source classification or altering the correction.
                $overridePrId=(int)$row['pr_id'];
                if(!array_key_exists($overridePrId,$prUacsCache)){
                    $overridePrVersion=one($this->db,"SELECT snapshot_json FROM document_versions
                        WHERE organization_id=? AND doc_type='PR' AND entity_id=?
                        ORDER BY version_no DESC LIMIT 1",[$org,$overridePrId]);
                    $overrideSavedPr=$overridePrVersion===null?null:json_decode((string)$overridePrVersion['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
                    demand($overrideSavedPr===null || is_array($overrideSavedPr),'Saved PR version is invalid',409);
                    $prUacsCache[$overridePrId]=is_array($overrideSavedPr)?($overrideSavedPr['uacs_line_snapshot']??null):null;
                }
                $overrideSourceUacs=$prUacsCache[$overridePrId];
                if($overrideSourceUacs!==null){
                    $overrideInherited=is_array($overrideSourceUacs)
                        ?PrUacsInheritance::inherited($overrideSourceUacs,$overridePrId,(int)$row['pr_line_id'],
                            (int)$row['item_id'],(string)$row['pr_document_date'])
                        :['ok'=>false,'issue'=>'PR accounting snapshot is invalid'];
                    if(!$overrideInherited['ok']){
                        $diagnostic['status']='INVALID_PR_UACS_SNAPSHOT';
                        $result['issues'][]='PR #'.$overridePrId.' item #'.$row['item_id'].': '.$overrideInherited['issue'];
                        $result['item_diagnostics'][]=$diagnostic;
                        $unmapped+=$amount;
                        continue;
                    }
                }
                // Explicit draft-only reclassification; source item history and
                // Division mapping remain unchanged. Active status is rechecked
                // below during journal readiness and finalization.
                $diagnostic['uacs_code']=$override;
                $result['item_diagnostics'][]=$diagnostic;
                $group[$override]=($group[$override]??0)+$amount;
                continue;
            }
            // New PR versions carry their own audited, effective-dated line UACS.
            // Legacy PRs (which have no snapshot) continue the original lookup.
            $prId=(int)$row['pr_id'];
            if(!array_key_exists($prId,$prUacsCache)){
                $version=one($this->db,"SELECT snapshot_json FROM document_versions
                    WHERE organization_id=? AND doc_type='PR' AND entity_id=?
                    ORDER BY version_no DESC LIMIT 1",[$org,$prId]);
                $savedPr=$version===null?null:json_decode((string)$version['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
                demand($savedPr===null || is_array($savedPr),'Saved PR version is invalid',409);
                $prUacsCache[$prId]=is_array($savedPr)?($savedPr['uacs_line_snapshot']??null):null;
            }
            $sourceUacs=$prUacsCache[$prId];
            if($sourceUacs!==null){
                $inherited=is_array($sourceUacs)
                    ?PrUacsInheritance::inherited($sourceUacs,$prId,(int)$row['pr_line_id'],
                        (int)$row['item_id'],(string)$row['pr_document_date'])
                    :['ok'=>false,'issue'=>'PR accounting snapshot is invalid'];
                if(!$inherited['ok']){
                    $diagnostic['status']='INVALID_PR_UACS_SNAPSHOT';
                    $result['issues'][]='PR #'.$prId.' item #'.$row['item_id'].': '.$inherited['issue'];
                    $result['item_diagnostics'][]=$diagnostic;
                    $unmapped+=$amount;
                    continue;
                }
                $code=(string)$inherited['uacs_code'];
                $diagnostic['status']='PR_UACS_SNAPSHOT';
                $diagnostic['uacs_code']=$code;
                $diagnostic['effective_from']=$inherited['effective_from'];
                $diagnostic['mapping_basis']='FINALIZED_PR_SNAPSHOT';
                $result['item_diagnostics'][]=$diagnostic;
                $group[$code]=($group[$code]??0)+$amount;
                continue;
            }
            $divisionMatches=array_values(array_filter($divisionMappings,static fn($map)=>
                (int)($map['item_id']??0)===(int)$row['item_id']
                && (string)($map['effective_from']??'')<=$asOf));
            usort($divisionMatches,static fn($a,$b)=>strcmp((string)$b['effective_from'],(string)$a['effective_from']));
            if($divisionMatches){
                $code=(string)$divisionMatches[0]['uacs_code'];
                $effective=(string)$divisionMatches[0]['effective_from'];
                if(count($divisionMatches)>1 && ($divisionMatches[1]['effective_from']??'')===$divisionMatches[0]['effective_from']){
                    $result['issues'][]='Multiple competing Division item UACS mappings for item #'.$row['item_id'];
                    $diagnostic['status']='AMBIGUOUS_MAPPING';
                    $result['item_diagnostics'][]=$diagnostic;
                    $unmapped+=$amount;
                    continue;
                }
            }else{
                // Legacy global mappings are read-only fallback for older data.
                // Division-specific configuration always takes precedence.
                $matches=q($this->db,"SELECT m.uacs_code,m.effective_from FROM item_account_mappings m
                    WHERE m.item_id=? AND m.effective_from<=?
                      AND (m.effective_to IS NULL OR m.effective_to>=?)
                    ORDER BY m.effective_from DESC,m.id DESC LIMIT 2",[(int)$row['item_id'],$asOf,$asOf])->fetchAll();
                if(count($matches)!==1){
                    $result['issues'][]='Item #'.$row['item_id'].' ('.$row['item_name'].') has '.
                        (count($matches)===0?'no':'overlapping').' effective UACS mapping for its '.($isMooe?'IAR':'PR').' date.';
                    $diagnostic['status']=count($matches)===0?'NO_EFFECTIVE_MAPPING':'AMBIGUOUS_MAPPING';
                    if(count($matches)===0)$diagnostic['next_effective_from']=self::nextItemMappingDate(
                        $divisionMappings,(int)$row['item_id'],$asOf);
                    $result['item_diagnostics'][]=$diagnostic;
                    $unmapped+=$amount;
                    continue;
                }
                $code=(string)$matches[0]['uacs_code'];
                $effective=(string)$matches[0]['effective_from'];
            }
            $diagnostic['uacs_code']=$code;
            $diagnostic['effective_from']=$effective;
            $result['item_diagnostics'][]=$diagnostic;
            $group[$code]=($group[$code]??0)+$amount;
        }
        $gross=moneyCents($dv['gross']);$deductions=moneyCents($dv['deductions']);$net=moneyCents($dv['net']);
        if($gross<0 || $deductions<0 || $net<0 || $gross!==$deductions+$net || $sum!==$gross)
            $result['issues'][]='DV gross, withholding, net and accepted allocations do not balance.';
        $taxes??=q($this->db,'SELECT amount FROM withholding_components WHERE dv_id=?',[$dv['id']])->fetchAll();
        $totalTax=0;foreach($taxes as $tax)$totalTax+=moneyCents($tax['amount']);
        if($totalTax!==$deductions)$result['issues'][]='Withholding components do not equal DV deductions.';
        // Explicit codes are a COA workpaper convention, not an automatic
        // bank posting or evidence that Division settled the withholding.
        $entries=[];ksort($group);
        foreach($group as $code=>$amount)if($amount>0)$entries[]=['uacs_code'=>(string)$code,
            'debit'=>moneyString($amount),'credit'=>'0.00',
            'explanation'=>'DV item expense/asset UACS allocation (source classification unchanged)'];
        if($net>0&&$paymentCredit!=='')$entries[]=['uacs_code'=>$paymentCredit,'debit'=>'0.00',
            'credit'=>moneyString($net),'explanation'=>$isMooe?'Net payment: Advances for Operating Expenses (workpaper)':'Net payment liability/advance per approved Division SIIF/source policy'];
        if($deductions>0&&$taxCredit!=='')$entries[]=['uacs_code'=>$taxCredit,'debit'=>'0.00',
            'credit'=>moneyString($deductions),'explanation'=>$isMooe?'Tax withheld: Due to BIR; settlement separately tracked':'Tax liability per Division-approved SIIF/source withholding policy; settlement separately tracked'];
        $accountNames=[];
        foreach(array_unique(array_column($entries,'uacs_code')) as $code){
            $account=one($this->db,'SELECT active,account_name FROM uacs_accounts WHERE code=?',[$code]);
            $accountNames[$code]=$account===null?null:(string)$account['account_name'];
            if($account===null||(int)$account['active']!==1)
                $result['issues'][]='UACS '.$code.' must be reviewed and enabled by the authorized administrator.';
        }
        foreach($entries as &$entry)$entry['account_name']=$accountNames[$entry['uacs_code']]??null;
        unset($entry);
        $debits=0;$credits=0;foreach($entries as $entry){$debits+=moneyCents($entry['debit']);$credits+=moneyCents($entry['credit']);}
        if($debits!==$credits||$debits!==$gross)$result['issues'][]='Proposed workpaper is not balanced.';
        $result['debit_total']=moneyString($debits);
        $result['credit_total']=moneyString($credits);
        $result['debit_difference']=moneyString(abs($credits-$debits));
        $result['unmapped_amount']=moneyString($unmapped);
        $result['entries']=$entries;
        $result['ready']=count($result['issues'])===0;
        $result['message']=$isMooe
            ?'The voucher workpaper credits the operating advance for NET payment and Due to BIR for withholding. Checks remain separate bank instruments and must not create another expense.'
            :'PR-date item UACS classify the debits; the Division-approved net-payment and withholding-liability UACS classify the credits. This is a journal workpaper, not a second bank posting.';
        return $result;
    }
    /** A manual journal cannot silently conceal a tax liability as a gross
     * payable. Return issues without modifying the source voucher or its tax
     * components. A Division-approved multi-line policy is a separate release.
     */
    private static function manualAccountingIssues(array $dv,array $taxes): array {
        $issues=[];
        $gross=moneyCents($dv['gross']);
        $withheld=moneyCents($dv['deductions']);
        $net=moneyCents($dv['net']);
        if($gross<=0 || $withheld<0 || $net<0 || $gross!==$net+$withheld)
            $issues[]='DV gross, deductions and net payment are inconsistent.';
        $taxTotal=0;
        foreach($taxes as $tax){
            $amount=moneyCents($tax['amount']??'0');
            if($amount<=0)$issues[]='Withholding components must have positive amounts.';
            $taxTotal+=$amount;
        }
        if($taxTotal!==$withheld)
            $issues[]='Saved withholding components do not reconcile to DV deductions.';
        if($withheld>0)
            $issues[]='A taxable DV using manual accounting requires an approved multi-line journal that separately recognizes net payable and withheld-tax liability. The existing gross debit/gross credit shortcut is not permitted. Ask the Division Accountant to confirm the applicable UACS and accounting policy.';
        return $issues;
    }
    private function addAccounts(int $dv,array $entries,string $reason,int $next): void {
        $dr=0;$cr=0;foreach($entries as $entry){demand(is_array($entry),'Invalid accounting line');$debit=moneyCents($entry['debit']??'0');$credit=moneyCents($entry['credit']??'0');demand(($debit>0 xor $credit>0),'Exactly one debit or credit per accounting line required');$code=requiredString($entry,'uacs_code',32);demand(one($this->db,'SELECT 1 FROM uacs_accounts WHERE code=? AND active=1',[$code])!==null,'Inactive or unknown UACS code');$dr+=$debit;$cr+=$credit;}
        demand(count($entries)>=2&&$dr===$cr&&$dr>0,'Accounting entries must have balanced debit and credit');$version=$this->insert('dv_accounting_versions',['dv_id'=>$dv,'version_no'=>$next,'reason'=>$reason,'actor_id'=>$this->user['id']]);foreach($entries as $e)$this->insert('voucher_accounting_lines',['accounting_version_id'=>$version,'uacs_code'=>$e['uacs_code'],'debit'=>moneyString(moneyCents($e['debit']??'0')),'credit'=>moneyString(moneyCents($e['credit']??'0')),'explanation'=>trim((string)($e['explanation']??''))]);
    }
    /** Earliest verifiable linked documentary date for this specific voucher. */
    private function dvDateSourceEarliest(int $org,array $dv): ?string {
        $transfer=$this->taxTransferRecord($org,(int)$dv['id']);
        if($transfer!==null){
            $r=one($this->db,"SELECT MAX(v.document_date) last_date FROM school_tax_transfer_components tc
                JOIN withholding_components w ON w.id=tc.component_id
                JOIN disbursement_vouchers v ON v.id=w.dv_id AND v.organization_id=?
                WHERE tc.transfer_id=?",[$org,$transfer['id']]);
            return !empty($r['last_date'])?(string)$r['last_date']:null;
        }
        if($dv['dv_type']==='TRAVEL_REIMBURSEMENT'){
            $x=one($this->db,'SELECT MAX(claim_date) AS earliest FROM travel_dv_claim_links WHERE organization_id=? AND dv_id=?',[$org,$dv['id']]);
            $latest=!empty($x['earliest'])?(string)$x['earliest']:null;
            foreach(EmployeeClaims::dvSources($this->db,$org,(int)$dv['id']) as $c){
                $source=EmployeeClaims::claim($this->db,$org,(int)$c['claim_id']);
                $date=(string)($source['purchase_date']??'');
                if(preg_match('/^20\d\d-\d\d-\d\d$/D',$date) && ($latest===null||$date>$latest))$latest=$date;
            }
            return $latest;
        }
        if($dv['dv_type']==='REGULAR'){
            $r=one($this->db,"SELECT MAX(GREATEST(i.accepted_at,COALESCE(p.document_date,i.accepted_at))) AS earliest
                FROM dv_allocation_lines a JOIN iar_lines il ON il.id=a.iar_line_id
                JOIN iars i ON i.id=il.iar_id AND i.organization_id=?
                JOIN purchase_orders po ON po.id=i.po_id AND po.organization_id=?
                JOIN purchase_requests p ON p.id=po.pr_id AND p.organization_id=? WHERE a.dv_id=?",
                [$org,$org,$org,$dv['id']]);
        }elseif($dv['dv_type']==='DIRECT'){
            $r=one($this->db,"SELECT MAX(p.document_date) AS earliest FROM dv_allocation_lines a
                JOIN purchase_requests p ON p.id=a.pr_id AND p.organization_id=? WHERE a.dv_id=?",
                [$org,$dv['id']]);
        }else{
            $r=one($this->db,"SELECT MAX(GREATEST(ad.paid_at,i.accepted_at)) AS earliest
                FROM dv_allocation_lines a JOIN advances ad ON ad.id=a.advance_id
                JOIN iar_lines il ON il.id=ad.iar_line_id JOIN iars i ON i.id=il.iar_id AND i.organization_id=?
                WHERE a.dv_id=?",[$org,$dv['id']]);
        }
        return !empty($r['earliest'])?(string)$r['earliest']:null;
    }
    /** Legacy numbered DVs have no original documentary date stored.
     * As a conservative control, do not allow newly numbered DVs to predate
     * their recorded issuance timestamp; NEVER rewrite that timestamp as
     * a fictitious official document date. */
    private function dvLastAccountableDate(int $org): ?string {
        $row=one($this->db,"SELECT MAX(COALESCE(document_date,DATE(created_at))) AS last_date
            FROM disbursement_vouchers WHERE organization_id=? AND status IN ('FINALIZED','CANCELLED')
            AND display_number IS NOT NULL",[$org]);
        return !empty($row['last_date'])?(string)$row['last_date']:null;
    }
    public function finalizeDV(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org);return $this->financialTx('dv.finalize',$d,function()use($org,$d){
        $dvId=idOf($d,'dv_id');
        // Acquire the SOURCE-FUND mutex before the DV row. Fund events and DV
        // cancellation take the same mutex and lock order. This prevents
        // competing finalizations from claiming the same source allocation.
        $candidate=one($this->db,'SELECT fund_id FROM disbursement_vouchers WHERE id=? AND organization_id=?',[$dvId,$org]);
        demand($candidate!==null,'Voucher not found in authorized organization',404);
        $fund=$this->record('funds',(int)$candidate['fund_id'],$org,true);
        $dv=$this->record('disbursement_vouchers',$dvId,$org,true);
        $taxTransfer=$this->taxTransferRecord($org,(int)$dv['id'],true);
        demand((int)$dv['fund_id']===(int)$fund['id'],'Voucher fund changed; refresh and retry',409);
        demand($dv['status']==='DRAFT','Voucher must be a draft');
        // v0.2.348: employee reimbursement requires a separate, accountable
        // FINANCIAL approval in addition to existing documentary review. The
        // paying officer must explicitly attest after original paid receipts,
        // PR/PO/IAR and employee identity have been checked.
        $employeePaymentAuthority=null;
        if($dv['dv_type']==='TRAVEL_REIMBURSEMENT'){
            demand(($d['reimbursement_payment_verified']??false)===true,
                'Confirm actual employee-paid receipts, original claim documents and absence of earlier payment before finalization.',409);
            $employeePaymentAuthority=requiredString($d,'reimbursement_payment_authority',255);
            demand(mbSafeLen($employeePaymentAuthority)>=8,
                'Reference the actual approved employee reimbursement authority (at least 8 characters).',409);
        }
        $documentDate=self::transactionDocumentDate(requiredString($d,'document_date',10),'DV Date');
        // Different fund rows may finalize concurrently. Serialize sequence
        // and date decisions on the owning organization, including voided DVs.
        $dateLock=one($this->db,'SELECT id FROM organizations WHERE id=? FOR UPDATE',[$org]);
        demand($dateLock!==null,'School workspace unavailable for DV finalization.',409);
        $lastDate=$this->dvLastAccountableDate($org);
        demand($lastDate===null || $documentDate>=$lastDate,
            'DV Date cannot be earlier than the preceding numbered DV date ('.$lastDate.'). Same-day issuance is allowed.',409);
        $sourceDate=$this->dvDateSourceEarliest($org,$dv);
        demand($sourceDate!==null && $documentDate>=$sourceDate,
            'DV Date cannot precede its linked source PR/IAR/payment record. Earliest: '.($sourceDate??'unknown').'.',409);
        $this->guardDvFinalizeSource($org,$dv);
        if($dv['dv_type']==='TRAVEL_REIMBURSEMENT'){
            $savedEvidence=$this->employeeReimbursementEvidence($org,(int)$dv['id']);
            if($savedEvidence['all_sources_finalized'])demand(hash_equals(
                (string)$savedEvidence['payment_authority_summary'],(string)$employeePaymentAuthority),
                'Payment authority must exactly match the locked signed approval evidence on all finalized reimbursement claims.',409);
        }
        // API starts DV-finalization transactions at READ COMMITTED, so these
        // totals are committed CURRENT values after the fund-row wait, rather
        // than the request's potentially old REPEATABLE READ snapshot.
        $fundAvailable=$this->fundAvailabilityTotals($org,(int)$fund['id']);
        $fundLabel=trim((string)$fund['name']);
        demand($fundAvailable['has_allocation'],
            'No authorized allocation recorded for source of fund "'.$fundLabel.'". DV remains draft.',409);
        $availableText=(string)$fundAvailable['available_allocation'];
        $available=str_starts_with($availableText,'-')
            ?-moneyCents(ltrim($availableText,'-')):moneyCents($availableText);
        $required=moneyCents($dv['gross']);
        $taxes=q($this->db,'SELECT * FROM withholding_components WHERE dv_id=? ORDER BY id FOR UPDATE',[$dv['id']])->fetchAll();
        $retainedFunding=null;
        $fundingMode=$this->schoolUtilizableFundingMode($org,(int)$fund['id'],true);
        // Existing gross allocation mode remains byte-for-byte equivalent in
        // value and failure message for remittance / IUS / unconfigured funds.
        if($taxTransfer!==null){
            demand(!$taxes,'Withholding-transfer DV cannot calculate new withholding.',409);
            $transferSource=$this->taxTransferValidate($org,$dv,true);
            demand($transferSource!==null,'Transfer source unavailable.',409);
            $bankId=idOf($d,'bank_account_id');
            $this->record('bank_accounts',$bankId,$org,true);
            demand(($d['bank_cash_verified']??false)===true,
                'Verify actual school bank cash and outstanding checks before tax-transfer DV finalization.',409);
            $cash=$this->verifiedFundBankCash($org,(int)$fund['id'],$bankId,true);
            demand($cash['fund_cash_cents']>=moneyCents((string)$dv['net']),
                'Insufficient linked school bank cash to remit this existing withheld-tax liability.',409);
            $retainedFunding=['mode'=>'SOURCE_TAX_LIABILITY_TRANSFER_NO_NEW_ALLOTMENT',
                'fund_cash_cents'=>$cash['fund_cash_cents'],'cash_basis'=>$cash['cash_basis'],
                'bank_account_id'=>$bankId,'officer_verified_actual_bank_cash'=>true,
                'original_tax_component_ids'=>array_map(static fn($r)=>(int)$r['id'],$transferSource['source_rows'])];
        }elseif(!$fundingMode['enabled']){
            demand($available>=0,
                'Source of fund "'.$fundLabel.'" is overcommitted. Finance administrator review required; DV remains draft.',409);
            demand($available >= $required,
                'Insufficient allocation for source of fund "'.$fundLabel.'". Available: ₱'.moneyString($available).
                '; DV required: ₱'.moneyString($required).'; shortfall: ₱'.moneyString($required-$available).'. DV remains draft.',409);
        }else{
            // The check bank is locked now, before the physical leaf is
            // claimed, to serialize concurrent usage on shared accounts.
            $bankId=idOf($d,'bank_account_id');
            $this->record('bank_accounts',$bankId,$org,true);
            demand(($d['bank_cash_verified']??false)===true,
                'Confirm you checked actual bank cash, unrecorded charges, outstanding commitments and required maintaining balance before finalization.',409);
            $cash=$this->verifiedFundBankCash($org,(int)$fund['id'],$bankId,true);
            $recorded=$this->confirmedRetainedAuthority($org,(int)$fund['id']);
            $currentEligible=0;
            if($dv['dv_type']==='REGULAR')foreach($taxes as $tax){
                if(($tax['disposition']??'')==='SCHOOL_UTILIZATION'&&
                    (int)($tax['fund_id']??0)===(int)$fund['id'])
                    $currentEligible+=moneyCents((string)$tax['amount']);
            }
            $retainedFunding=FundCashFinalization::authorize(
                $required,moneyCents($dv['deductions']),$available,
                $recorded['recorded_eligible_cents'],$cash['fund_cash_cents'],
                $cash['minimum_cents'],$currentEligible);
            $selfLeft=$retainedFunding['same_dv_withheld_used_cents'];
            $retainedFunding['same_dv_withholding_components']=[];
            if($selfLeft>0){
                foreach($taxes as $tax){
                    if(($tax['disposition']??'')!=='SCHOOL_UTILIZATION'||
                        (int)($tax['fund_id']??0)!==(int)$fund['id'])continue;
                    $use=min($selfLeft,moneyCents((string)$tax['amount']));
                    if($use>0)$retainedFunding['same_dv_withholding_components'][]=[
                        'component_id'=>(int)$tax['id'],
                        'atc_code'=>(string)$tax['atc_code'],
                        'used_cents'=>$use];
                    $selfLeft-=$use;
                    if($selfLeft===0)break;
                }
                demand($selfLeft===0,'Current-DV tax utilization does not reconcile to its recorded ATC components.',409);
            }
            $retainedFunding['confirmed_source_component_ids']=$recorded['source_component_ids'];
            $retainedFunding['prior_pending_source_ids']=$recorded['pending_component_ids'];
            $retainedFunding['prior_pending_tax_cents']=$recorded['pending_cents'];
            $retainedFunding['bank_reconciliation_id']=$cash['bank_reconciliation_id'];
            $retainedFunding['cash_basis']=$cash['cash_basis'];
            $retainedFunding['officer_verified_actual_bank_cash']=true;
            $retainedFunding['division_policy_version_id']=(int)$fundingMode['policy']['version_id'];
            $retainedFunding['mode']='NON_IUS_POLICY_GATED_NET_FUND_BANK_CASH';
        }
        $utilizes=false;foreach($taxes as $tax)if($tax['disposition']==='SCHOOL_UTILIZATION')$utilizes=true;
        $policyVersion=$retainedFunding?(int)$fundingMode['policy']['version_id']:0;
        $classificationVersion=$retainedFunding?(int)$fundingMode['policy']['school_classification_version']:0;
        $policyMode=$retainedFunding?(string)$fundingMode['policy']['mode']:'NOT_CONFIGURED';
        if($taxTransfer!==null){
            $policyVersion=(int)$transferSource['policy']['version_id'];
            $classificationVersion=(int)$transferSource['policy']['school_classification_version'];
            $policyMode='REMIT_TO_DIVISION';
        }
        if($dv['dv_type']==='REGULAR' && count($taxes)>0){
            $current=$this->regularDvTaxPolicy($org,(int)$fund['id'],true,true);
            foreach($taxes as $tax)demand($tax['disposition']===$current['disposition'],
                'Draft withholding for ATC '.$tax['atc_code'].' does not match the current Division policy ('.$current['mode'].'). Open DV Details and use Correct Draft Tax Disposition before finalization.',409);
            $policyVersion=$current['version_id'];$classificationVersion=$current['school_classification_version'];
            $policyMode=$current['mode'];
        }
        if($utilizes){
            $class=$this->currentSchoolClass($org,self::todayManila());
            demand($class!==null,'School IUS/Non-IUS status has not been verified by Division; withheld-tax utilization is not allowed',409);
            $classificationVersion=$class['version_id'];
            $division=$this->divisionId($org);
            // Coordinate with Division policy writers, which lock this same
            // Division row before appending a new policy version.
            $policyLock=one($this->db,"SELECT id FROM organizations WHERE id=? AND kind='DIVISION' LOCK IN SHARE MODE",[$division]);
            demand($policyLock!==null,'Division configuration unavailable',409);
            $selected=$this->effectiveFundPolicy($division,(string)$fund['source_code'],$class['classification'],self::todayManila());
            $policyVersion=$selected['version_id'];$policyMode=$selected['policy'];
            $directory=$this->divisionFundSourceList($division);$activeSource=false;
            foreach($directory['sources'] as $s)if(($s['code']??null)===$fund['source_code'] && ($s['active']??false)===true)$activeSource=true;
            demand($activeSource,'Division fund source is not active; withheld-tax utilization blocked',409);
            demand($policyMode==='DIVISION_SETTLES_UTILIZABLE',
                'This fund is not configured by Division for withheld-tax utilization for '.$class['classification'].' schools. Select remittance disposition or ask Division to configure the effective policy.',409);
            // A school-utilization disposition is not cash actually utilized,
            // a new allocation, or evidence of BIR settlement. Those separate
            // financial events need a distinct verified ledger in a later release.
        }
        $this->validateAllocations($dv);
        // For verified Non-IUS Regular MOOE, generate the journal workpaper on
        // the server, never trust arbitrary browser account selection. The
        // document workpaper is not an additional bank debit or cleared check.
        $workpaper=$taxTransfer!==null
            ?TaxTransferRules::workpaper((string)$dv['gross'])
            :$this->advanceLiquidationWorkpaper($org,$dv,$taxes);
        if($workpaper['applicable']){
            demand($workpaper['ready'],
                'Source-specific automatic item workpaper is incomplete: '.implode(' ',array_slice($workpaper['issues'],0,3)).
                ' Configure the required UACS mappings/accounts before finalizing. DV remains draft.',409);
            $this->addAccounts((int)$dv['id'],$workpaper['entries'],
                'Source-specific Division-authorized item accounting workpaper; not a second bank entry',1);
        }else{
            $manualIssues=self::manualAccountingIssues($dv,$taxes);
            demand(!$manualIssues,'Manual DV accounting cannot be finalized: '.implode(' ',$manualIssues).' DV remains draft.',409);
            $entries=rowsRequired($d,'accounting');
            $journalDebit=0;
            foreach($entries as $entry){
                demand(is_array($entry),'Invalid accounting line');
                $journalDebit+=moneyCents($entry['debit']??'0');
            }
            demand($journalDebit===moneyCents($dv['gross']),
                'Manual accounting debit total must equal the DV gross amount. DV remains draft.',409);
            if($dv['dv_type']==='TRAVEL_REIMBURSEMENT')
                $entries=$this->validateEmployeePaymentJournal($org,$dv,$entries);
            $this->addAccounts((int)$dv['id'],$entries,'Original accounting workpaper',1);
        }
        $particulars=trim((string)($dv['particulars']??''));
        if($particulars==='')$particulars=$this->dvSuggestedParticulars((int)$dv['id'],(string)$dv['dv_type'],(string)$dv['gross']);
        demand($particulars!=='' && mbSafeLen($particulars)<=1200,
            'DV Particulars are missing. Open DV Details and save a clear payment description before finalization.',409);
        $dv['particulars']=$particulars;
        $dv['document_date']=$documentDate;
        // v0.2.191: paper leaf is physically verified and ASSIGNED in the same
        // transaction as the numbered DV. The bank row serializes all issuers
        // for this physical checkbook; stale preview requests must fail closed.
        // ISSUED here is the existing check-register status for custody of a
        // numbered leaf, NOT bank clearing or cash disbursement. Bank/fund
        // ledgers continue posting only on the original RELEASED transition.
        demand(moneyCents($dv['net'])>0,
            'A zero-net DV cannot receive a physical payment check. Request Accounting review.',409);
        $bank=$this->record('bank_accounts',idOf($d,'bank_account_id'),$org,true);
        $leaf=$this->claimNextPhysicalLeaf($org,(int)$bank['id'],$d);
        demand(one($this->db,'SELECT 1 FROM checks WHERE dv_id=? LIMIT 1',[$dv['id']])===null,
            'DV already has a recorded physical check; do not finalize or assign another leaf.',409);
        $basis=strtoupper(trim((string)($d['payee_basis']??'DV_PAYEE')));
        $supplierBacked=$dv['dv_type']==='REGULAR' && (int)($dv['supplier_id']??0)>0;
        if($supplierBacked){
            demand(in_array($basis,['BUSINESS','PROPRIETOR'],true),
                'Select business or proprietor for the check payee.',409);
            $payeeName=$basis==='BUSINESS'?(string)($dv['business_name_snapshot']??''):(string)($dv['proprietor_name_snapshot']??'');
            demand(trim($payeeName)!=='','The selected verified check payee name is missing.',409);
        }else{
            demand($basis==='DV_PAYEE','This voucher requires the verified DV payee.',409);
            $payeeName=(string)$dv['payee'];
        }
        demand(trim($payeeName)!=='' && mbSafeLen($payeeName)<=200,'Invalid verified check payee.',409);
        $number=$this->registered($org,'DV',(int)$dv['id'],$documentDate);
        q($this->db,"UPDATE disbursement_vouchers SET display_number=?,status='FINALIZED',particulars=?,document_date=? WHERE id=?",
            [$number,$particulars,$documentDate,$dv['id']]);
        // Persist the check/physical-leaf claim after the accountable DV number,
        // but before the issuance snapshot. Any failure rolls back BOTH numbers.
        $checkId=$this->insert('checks',['organization_id'=>$org,'bank_account_id'=>$bank['id'],
            'dv_id'=>$dv['id'],'check_number'=>$leaf['serial'],'payee_name'=>$payeeName,
            'payee_basis'=>$basis,'amount'=>$dv['net'],'created_by'=>$this->user['id']]);
        $claimed=q($this->db,"UPDATE checkbook_leaves SET status='ISSUED' WHERE id=? AND organization_id=? AND status='AVAILABLE'",[$leaf['id'],$org]);
        demand($claimed->rowCount()===1,'The selected check leaf was taken by another document. Reopen the finalization window.',409);
        $this->insert('payment_events',['check_id'=>$checkId,'event_type'=>'ISSUED',
            'evidence_reference'=>'Assigned for printing with finalized DV '.$number.'; not signed, released or cleared',
            'created_by'=>$this->user['id']]);
        $this->link($org,'DV',(int)$dv['id'],'CHECK',$checkId);
        if($taxTransfer!==null){
            $state=TaxTransferRules::next((string)$taxTransfer['status'],'FINALIZE');
            $updated=q($this->db,"UPDATE school_tax_transfers SET status=? WHERE id=? AND status='DRAFT'",
                [$state,$taxTransfer['id']]);
            demand($updated->rowCount()===1,'Tax-transfer state changed while finalizing; no check was issued.',409);
        }
        // PENDING_TAX is INFORMATIONAL, never fund availability; no credit on DV creation.
        foreach(q($this->db,'SELECT * FROM withholding_components WHERE dv_id=?',[$dv['id']])->fetchAll() as $tax){$this->insert('tax_disposition_events',['component_id'=>$tax['id'],'state'=>'PENDING','amount'=>$tax['amount'],'evidence_reference'=>'DV '.$number,'created_by'=>$this->user['id']]);if($tax['disposition']==='SCHOOL_UTILIZATION')$this->ledger($org,(int)$dv['fund_id'],'PENDING_TAX',moneyCents($tax['amount']),'TAX',(int)$tax['id']);}
        $this->snapshot($org,'DV',(int)$dv['id'],['header'=>$dv,'number'=>$number,'allocations'=>q($this->db,'SELECT * FROM dv_allocation_lines WHERE dv_id=?',[$dv['id']])->fetchAll(),
            'dv_signatories'=>$this->dvSignatoriesAtIssuance($org,$documentDate),
            'payee_document_identity'=>$this->dvPayeeIdentityAtIssuance($org,$dv,$documentDate),
            'withholding_policy'=>['mode'=>$policyMode,'version_id'=>$policyVersion,'school_class_version_id'=>$classificationVersion],
            'funding_basis'=>$retainedFunding,
            'employee_payment_authority'=>$employeePaymentAuthority,
            'withholding_transfer'=>$taxTransfer===null?null:[
                'transfer_id'=>(int)$taxTransfer['id'],
                'division_id'=>(int)$taxTransfer['division_id'],
                'division_bank_account_id'=>$taxTransfer['division_bank_account_id']===null?null:(int)$taxTransfer['division_bank_account_id'],
                'source_component_ids'=>array_map(static fn($r)=>(int)$r['id'],$transferSource['source_rows'])],
            'accounting_workpaper'=>['treatment'=>$workpaper['treatment'],'stage'=>'DOCUMENT_WORKPAPER_NOT_BANK_POSTING',
                'system_generated'=>$workpaper['applicable'],
                'accounting_policy_version_id'=>(int)($workpaper['accounting_policy_version_id']??0),
                'payment_credit_uacs'=>(string)($workpaper['payment_credit_uacs']??''),
                'withholding_liability_uacs'=>(string)($workpaper['withholding_liability_uacs']??'')]]);
        $this->audit($org,'FINALIZED','DV',(int)$dv['id'],['withholding_policy_version_id'=>$policyVersion,
            'funding_basis'=>$retainedFunding,'employee_payment_authority'=>$employeePaymentAuthority,
            'assigned_check_id'=>$checkId,
            'assigned_check_number'=>$leaf['serial'],'bank_account_id'=>(int)$bank['id']]);
        // One accountable Division notice at DV finalization, not a second tax
        // transaction when the paper check is later released. The notice and
        // DV finalization commit or roll back together. Check release remains
        // an independently audited payment event that updates the notice view.
        require_once __DIR__.'/RetainedTaxHandoff.php';
        (new RetainedTaxHandoff($this->db,$this->user))->ensureFromFinalizedDv($org,(int)$dv['id']);
        return ['id'=>(int)$dv['id'],'number'=>$number,'document_date'=>$documentDate,'gross'=>$dv['gross'],
            'net'=>$dv['net'],'check_id'=>$checkId,'check_number'=>(string)$leaf['serial'],
            'check_status'=>'ASSIGNED_FOR_PRINTING'];
    });}
    public function reviseAccounts(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org);return $this->financialTx('dv.accounting',$d,function()use($org,$d){
        $dv=$this->record('disbursement_vouchers',idOf($d,'dv_id'),$org,true);demand($dv['status']==='FINALIZED','Only finalized DV accounting can be revised');
        demand(one($this->db,'SELECT id FROM checks WHERE dv_id=? LIMIT 1',[$dv['id']])===null,
            'A recorded check requires a separate adjustment; the DV journal is historical',409);
        demand($this->taxTransferRecord($org,(int)$dv['id'])===null,
            'School tax-transfer UACS are an immutable liability settlement; Division corrections require a linked JEV.',409);
        $linkedWorkpaper=$this->advanceLiquidationWorkpaper($org,$dv);
        demand(!$linkedWorkpaper['applicable'],'Use Correct Accounting UACS in the DV details to keep per-item classifications and reports consistent.',409);
        $previous=one($this->db,'SELECT id FROM dv_accounting_versions WHERE dv_id=? ORDER BY version_no DESC LIMIT 1',[$dv['id']]);
        demand($previous!==null,'Original DV accounting version is missing',409);
        $priorLines=q($this->db,'SELECT uacs_code,debit,credit FROM voucher_accounting_lines WHERE accounting_version_id=?',[$previous['id']])->fetchAll();
        $newLines=rowsRequired($d,'accounting');
        demand($this->dvJournalSignature($priorLines,'credit')===$this->dvJournalSignature($newLines,'credit'),
            'The original DV credit accounts and amounts are immutable for debit reclassifications',409);
        demand($this->dvJournalSignature($priorLines,'debit')!==$this->dvJournalSignature($newLines,'debit'),
            'No changed debit classifications were supplied',409);
        $reason=requiredString($d,'reason',500);$row=one($this->db,'SELECT COALESCE(MAX(version_no),0) last_version FROM dv_accounting_versions WHERE dv_id=?',[$dv['id']]);$next=(int)$row['last_version']+1;
        $this->addAccounts((int)$dv['id'],$newLines,$reason,$next);$this->snapshot($org,'DV',(int)$dv['id'],['accounting_version'=>$next,'gross_unchanged'=>$dv['gross']],$reason);$this->audit($org,'ACCOUNTING_REVISED','DV',(int)$dv['id'],['version'=>$next]);return ['id'=>(int)$dv['id'],'accounting_version'=>$next,'gross'=>$dv['gross']];
    });}
    /** Confirm retained Non-IU withholding from immutable DV/Division policy records.
     * The evidence text is an attribution to the original DV PLUS the operator's
     * explicit bank-cash attestation: it is NOT a fabricated bank statement ID.
     * Used only for a specifically requested CONFIRMED_CREDIT completion. */
    private function savedRetainedTaxReferences(int $org,array $tax): array {
        demand(in_array($this->user['role'],['SCHOOL','SUPER_ADMIN'],true),
            'Only an operational owning-school account can use saved-source confirmation',403);
        $dv=one($this->db,"SELECT id,display_number,document_date,fund_id,status FROM disbursement_vouchers
            WHERE id=? AND organization_id=? FOR UPDATE",[(int)$tax['dv_id'],$org]);
        demand($dv!==null && $dv['status']==='FINALIZED' && (int)$dv['fund_id']===(int)$tax['dv_fund'],
            'Original finalized DV is unavailable; request finance review',409);
        $fund=$this->record('funds',(int)$dv['fund_id'],$org);
        $versions=q($this->db,"SELECT snapshot_json FROM document_versions
            WHERE organization_id=? AND doc_type='DV' AND entity_id=?
            ORDER BY version_no ASC LIMIT 100",[$org,(int)$dv['id']])->fetchAll();
        $policySnapshot=null;
        foreach($versions as $v){
            $snapshot=json_decode((string)$v['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            if(is_array($snapshot) && isset($snapshot['withholding_policy']) && is_array($snapshot['withholding_policy'])){
                $policySnapshot=$snapshot['withholding_policy'];break;
            }
        }
        demand($policySnapshot!==null && ($policySnapshot['mode']??'')==='DIVISION_SETTLES_UTILIZABLE',
            'Original finalized DV has no verified school-utilization policy snapshot',409);
        $version=(int)($policySnapshot['version_id']??0);
        demand($version>0,'Original Division fund-policy version is missing',409);
        $division=$this->divisionId($org);
        $policy=one($this->db,'SELECT id,config_json,effective_from FROM configuration_versions WHERE id=? AND organization_id=?',
            [$version,$division]);
        demand($policy!==null && (string)$policy['effective_from']<=(string)$dv['document_date'],
            'Original Division policy version cannot be verified',409);
        $config=json_decode((string)$policy['config_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($config) && ($config['school_class']??'')==='NON_IUS' &&
            ($config['source_code']??'')===(string)$fund['source_code'] &&
            ($config['policy']??'')==='DIVISION_SETTLES_UTILIZABLE',
            'Original Division fund policy does not support Non-IU utilization',409);
        $authority=trim((string)($config['authority']??''));
        demand($authority!=='' && mbSafeLen($authority)<=255,
            'Saved Division utilization authority is missing; ask Division to review the original policy',409);
        $display=trim((string)($dv['display_number']??''));
        demand($display!=='' && mbSafeLen($display)<=30,'Original DV number unavailable',409);
        $evidence='Original DV '.$display.'; ATC '.(string)$tax['atc_code'].'; component #'.(int)$tax['id'].
            '; retained bank cash verified by officer attestation (not bank statement ID)';
        demand(mbSafeLen($evidence)<=255,'Automatic reference too long; contact administrator',409);
        return ['evidence_reference'=>$evidence,'authority_reference'=>$authority,'policy_version_id'=>$version];
    }
    public function retainedAllocationAuthority(array $d):array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        demand(FinancialCutoff::isCoordinated($this->db),'Retained authority requires a coordinated transaction',409);
        $tax=one($this->db,'SELECT w.*,v.fund_id AS dv_fund FROM withholding_components w JOIN disbursement_vouchers v ON v.id=w.dv_id WHERE w.id=? AND v.organization_id=?', [idOf($d,'component_id'),$org]);
        demand($tax!==null&&$tax['disposition']==='SCHOOL_UTILIZATION','Eligible retained component required',409);
        $refs=$this->savedRetainedTaxReferences($org,$tax);$fund=$this->record('funds',(int)$tax['dv_fund'],$org);$record=one($this->db,'SELECT config_key FROM configuration_versions WHERE id=?',[$refs['policy_version_id']]);demand(($record['config_key']??'')===self::fundPolicyKey((string)$fund['source_code'],'NON_IUS'),'Original retained authority is not an approved fund-policy record',409);$snapshot=one($this->db,"SELECT snapshot_json FROM document_versions WHERE organization_id=? AND doc_type='DV' AND entity_id=? ORDER BY version_no,id LIMIT 1",[$org,$tax['dv_id']]);
        $policy=json_decode((string)($snapshot['snapshot_json']??''),true,512,JSON_THROW_ON_ERROR)['withholding_policy']??[];$classification=(int)($policy['school_class_version_id']??0);
        $row=one($this->db,"SELECT config_json,effective_from FROM configuration_versions WHERE id=? AND organization_id=? AND config_key='SCHOOL_IMPLEMENTATION_CLASS'",[$classification,$org]);$dv=one($this->db,'SELECT document_date FROM disbursement_vouchers WHERE id=?',[$tax['dv_id']]);
        demand($row!==null&&$row['effective_from']<=$dv['document_date']&&(json_decode($row['config_json'],true,512,JSON_THROW_ON_ERROR)['classification']??'')==='NON_IUS','Original Non-IU classification version cannot be verified',409);return $refs;
    }
    public function confirmTax(array $d): array {$org=$this->org($d);allowedToWrite($this->user);return $this->financialTx('tax.confirm',$d,function()use($org,$d){
        $tax=one($this->db,"SELECT w.*,v.organization_id,v.status dv_status,v.fund_id dv_fund FROM withholding_components w JOIN disbursement_vouchers v ON v.id=w.dv_id WHERE w.id=? FOR UPDATE",[idOf($d,'component_id')]);demand($tax!==null&&(int)$tax['organization_id']===$org&&$tax['dv_status']==='FINALIZED','Withholding component unavailable');$state=requiredString($d,'state',30);demand(in_array($state,['CONFIRMED_CREDIT','REMITTED'],true),'Invalid tax completion');
        demand(($state==='CONFIRMED_CREDIT'&&$tax['disposition']==='SCHOOL_UTILIZATION')||($state==='REMITTED'&&$tax['disposition']==='REMIT'),'Tax disposition mismatch');
        demand(one($this->db,"SELECT 1 FROM tax_disposition_events WHERE component_id=? AND state IN ('CONFIRMED_CREDIT','REMITTED')",[$tax['id']])===null,'Tax component already closed');
        if($state==='REMITTED')
            demand(one($this->db,"SELECT 1 FROM school_tax_transfer_components tc JOIN school_tax_transfers tr ON tr.id=tc.transfer_id WHERE tc.component_id=? AND tr.status<>'CANCELLED' LIMIT 1",[$tax['id']])===null,
                'This tax is reserved in a school-to-Division transfer DV. Division must acknowledge its released check instead.',409);
        if($state==='CONFIRMED_CREDIT')demand(($d['budget_conditions_met']??false)===true&&($d['cash_conditions_met']??false)===true,'Documented budget and cash conditions must be confirmed');
        $saved=(($d['auto_references_from_saved_records']??false)===true);
        demand(!$saved || $state==='CONFIRMED_CREDIT','Saved-source evidence is valid only for retained tax confirmation');
        if($saved){
            demand(!array_key_exists('evidence_reference',$d) && !array_key_exists('authority_reference',$d),
                'Do not override saved-source evidence or Division authority',409);
            $refs=$this->savedRetainedTaxReferences($org,$tax);
            $evidence=$refs['evidence_reference'];$authority=$refs['authority_reference'];
        }else{
            $evidence=requiredString($d,'evidence_reference',255);$authority=requiredString($d,'authority_reference',255);
        }
        $this->insert('tax_disposition_events',['component_id'=>$tax['id'],'state'=>$state,'amount'=>$tax['amount'],'evidence_reference'=>$evidence,'authority_reference'=>$authority,'created_by'=>$this->user['id']]);
        if($state==='CONFIRMED_CREDIT')$this->ledger($org,(int)$tax['dv_fund'],'CONFIRMED_CREDIT',moneyCents($tax['amount']),'TAX',(int)$tax['id'],$authority);
        $this->audit($org,$state,'TAX',(int)$tax['id'],$saved?
            ['evidence_source'=>'ORIGINAL_FINALIZED_DV_AND_OFFICER_ATTESTATION',
             'division_policy_version_id'=>$refs['policy_version_id']]:[]);
        return ['component_id'=>(int)$tax['id'],'state'=>$state];
    });}
    /** RIS draft line picker: no stock ledger or on-hand totals. */
    public function divisionRisLines(array $p): array {
        $org=$this->org($p);
        demand(DivisionSchoolAccess::isDivisionSchool($this->db,$this->user,$org),
            'School-assigned Division user required',403);
        return q($this->db,"SELECT l.id,i.name AS item_name,l.requested_qty
            FROM ris_lines l JOIN requisition_issue_slips r ON r.id=l.ris_id
            JOIN item_catalog i ON i.id=l.item_id AND i.category='SUPPLY'
            WHERE r.organization_id=? AND r.id=? AND r.status='DRAFT'
            ORDER BY l.id LIMIT 100",[$org,idOf($p,'ris_id')])->fetchAll();
    }
    /** Positive eligible lots for ONE selected draft RIS line; no on-hand balance. */
    public function divisionRisLots(array $p): array {
        $org=$this->org($p);
        demand(DivisionSchoolAccess::isDivisionSchool($this->db,$this->user,$org),
            'School-assigned Division user required',403);
        $line=one($this->db,"SELECT rl.item_id FROM ris_lines rl JOIN requisition_issue_slips r
            ON r.id=rl.ris_id WHERE rl.id=? AND r.organization_id=? AND r.status='DRAFT'",
            [idOf($p,'ris_line_id'),$org]);
        demand($line!==null,'Choose a draft RIS line in the assigned school',403);
        return q($this->db,"SELECT l.id,c.name AS item_name,l.batch
            FROM inventory_lots l JOIN item_catalog c ON c.id=l.item_id
            JOIN stock_events e ON e.lot_id=l.id AND e.organization_id=l.organization_id
            WHERE l.organization_id=? AND l.item_id=? AND c.category='SUPPLY' AND c.active=1
            AND NOT EXISTS (SELECT 1 FROM assets a WHERE a.organization_id=l.organization_id AND a.iar_line_id=l.iar_line_id)
            GROUP BY l.id,c.name,l.batch
            HAVING SUM(e.quantity_delta)>0 ORDER BY l.id LIMIT 100",[$org,$line['item_id']])->fetchAll();
    }
    /** Only ACTIVE, never issued ICS/PAR eligible assets; no acquisition cost,
     * serial number, custodian register, transfer history or asset ledger. */
    public function divisionEligibleAccountability(array $p): array {
        $org=$this->org($p);
        demand(DivisionSchoolAccess::isDivisionSchool($this->db,$this->user,$org),
            'School-assigned Division user required',403);
        return q($this->db,"SELECT a.id,a.property_tag,i.name AS item_name,a.status,
            CASE WHEN i.category='PPE' THEN 'PAR' ELSE 'ICS' END AS required_document
            FROM assets a JOIN item_catalog i ON i.id=a.item_id
            WHERE a.organization_id=? AND a.status='ACTIVE' AND i.category IN ('PPE','SEMI_EXPENDABLE')
            AND NOT EXISTS(SELECT 1 FROM accountability_documents d WHERE d.asset_id=a.id
                AND d.organization_id=a.organization_id AND d.status='FINALIZED')
            ORDER BY a.id DESC LIMIT 100",[$org])->fetchAll();
    }
    /** Prepare a scoped RIS in one form, without exposing unrelated staff records.
     * No stock movement or document numbering happens until the existing issueRIS.
     */
    public function risOptions(array $p): array {
        $org=$this->org($p);
        $assignmentRows=q($this->db,'SELECT a.employee_id,a.designation,a.effective_from,a.effective_to,
            e.full_name,e.position_title FROM employee_assignments a JOIN employees e ON e.id=a.employee_id
            WHERE a.organization_id=? ORDER BY a.id DESC',[$org])->fetchAll();
        $seen=[];$employees=[];
        foreach($assignmentRows as $row){
            $id=(int)$row['employee_id'];if(isset($seen[$id]))continue;$seen[$id]=true;
            if(!$this->staffCurrent($row,self::todayManila()))continue;
            $employees[]=['id'=>$id,'name'=>(string)$row['full_name'],
                'position'=>(string)($row['designation']?:$row['position_title'])];
        }
        usort($employees,static fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
        $prs=q($this->db,"SELECT p.id,p.display_number,p.purpose,p.total,
            (SELECT NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(cv.config_json,'$.name'))),'')
             FROM configuration_versions cv WHERE cv.organization_id=p.organization_id
             AND cv.config_key=CONCAT('PR_REQUESTOR_V1_',p.id) ORDER BY cv.id DESC LIMIT 1) requestor_name,
            EXISTS(SELECT 1 FROM pr_lines pl JOIN item_catalog i ON i.id=pl.item_id
                WHERE pl.pr_id=p.id AND i.category='SUPPLY' AND i.active=1) AS has_supplies
            FROM purchase_requests p WHERE p.organization_id=? AND p.status='FINALIZED'
            ORDER BY p.id DESC LIMIT 150",[$org])->fetchAll();
        return ['employees'=>$employees,'prs'=>$prs];
    }
    /** Read-only, exact PR line preview. Never accept a browser-supplied PR item list. */
    public function risPrPreview(array $p): array {
        $org=$this->org($p);$pr=$this->record('purchase_requests',idOf($p,'pr_id'),$org);
        demand($pr['status']==='FINALIZED','A finalized PR is required for an RIS',409);
        $rows=q($this->db,'SELECT l.id,l.item_id,l.quantity,i.name,i.category,i.active,
            u.code AS unit,i.owner_organization_id FROM pr_lines l JOIN item_catalog i ON i.id=l.item_id
            JOIN units u ON u.id=i.unit_id WHERE l.pr_id=? ORDER BY l.id',[$pr['id']])->fetchAll();
        $lines=[];$excluded=0;
        foreach($rows as $line){
            if($line['category']!=='SUPPLY'||(int)$line['active']!==1 ||
                !$this->catalogItemAccessible($org,(int)$line['item_id'])){$excluded++;continue;}
            $lines[]=['pr_line_id'=>(int)$line['id'],'item_id'=>(int)$line['item_id'],
                'name'=>(string)$line['name'],'quantity'=>(string)$line['quantity'],
                'unit'=>(string)$line['unit']];
        }
        return ['pr_id'=>(int)$pr['id'],'number'=>(string)($pr['display_number']??''),
            'requestor'=>$this->prRequestor($org,(int)$pr['id']),
            'lines'=>$lines,'excluded_count'=>$excluded];
    }
    /** Supply-only server search; browse is never capped by the 100-row item register. */
    public function risSupplySearch(array $p): array {
        $org=$this->org($p);$division=$this->divisionId($org);
        $term=trim((string)($p['term']??''));demand(mbSafeLen($term)<=90,'Search is too long',422);
        $like='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$term).'%';
        return q($this->db,"SELECT i.id,i.name,u.code AS unit FROM item_catalog i
            JOIN units u ON u.id=i.unit_id WHERE i.active=1 AND i.category='SUPPLY'
            AND (i.owner_organization_id IS NULL OR i.owner_organization_id=? OR i.owner_organization_id=?)
            AND (i.name LIKE ? OR u.code LIKE ?) ORDER BY i.name,i.id LIMIT 35",
            [$org,$division,$like,$like])->fetchAll();
    }
    public function createRIS(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org,'ris.create');return $this->tx(function()use($org,$d){
        $mode=requiredString($d,'source_mode',10);demand(in_array($mode,['PR','DIRECT'],true),'Invalid RIS source');
        $pr=null;$prRequestor=null;
        if($mode==='PR'){
            $pr=$this->record('purchase_requests',idOf($d,'pr_id'),$org);
            demand($pr['status']==='FINALIZED','Finalized PR needed for import');
            $prRequestor=$this->prRequestor($org,(int)$pr['id']);
            // Import ONLY original eligible PR supply lines. No arbitrary user-provided
            // subset, forged quantity, stock category or additional item is accepted.
            demand(!array_key_exists('lines',$d),'PR RIS items must be imported from the original finalized PR',422);
            $inputs=q($this->db,"SELECT pl.item_id,pl.quantity requested_qty FROM pr_lines pl
                JOIN item_catalog i ON i.id=pl.item_id
                WHERE pl.pr_id=? AND i.category='SUPPLY' AND i.active=1 ORDER BY pl.id",[$pr['id']])->fetchAll();
            demand(count($inputs)>0,'The selected PR has no active consumable supplies to request',409);
        }else{
            $inputs=rowsRequired($d,'lines');
            demand(count($inputs)<=60,'Select no more than 60 supply lines per RIS',422);
        }
        $employee=$prRequestor===null?$this->staffActive($org,idOf($d,'employee_id')):null;
        $risRequestor=$prRequestor!==null?$prRequestor['name']:(string)$employee['full_name'];
        $requestorId=$prRequestor!==null?(int)$prRequestor['employee_id']:(int)$employee['employee_id'];
        // Validate every requested line BEFORE inserting the header, under the same
        // transaction. A failed item/category/quantity rolls the whole RIS back.
        $verified=[];$seen=[];
        foreach($inputs as $line){
            demand(is_array($line),'Invalid RIS line',422);
            $item=idOf($line,'item_id');$qty=qtyMillis($line['requested_qty']??'');
            demand($qty>0,'Requested quantity must be positive',422);
            demand(!isset($seen[$item])||$mode==='PR','Each direct supply should appear once; adjust its quantity instead',422);
            $seen[$item]=true;
            demand($this->catalogItemAccessible($org,$item),'Supply item unavailable in this workspace',409);
            demand(one($this->db,"SELECT id FROM item_catalog WHERE id=? AND category='SUPPLY' AND active=1",[$item])!==null,
                'RIS can request active consumable supplies only; services and accountable property use other workflows',409);
            $verified[]=['item_id'=>$item,'qty'=>qtyString($qty)];
        }
        demand(count($verified)>0,'Add at least one consumable supply to the RIS',422);
        $id=$this->insert('requisition_issue_slips',['organization_id'=>$org,'source_mode'=>$mode,
            'pr_id'=>$pr['id']??null,'requestor'=>$risRequestor,'created_by'=>$this->user['id']]);
        foreach($verified as $line)$this->insert('ris_lines',['ris_id'=>$id,
            'item_id'=>$line['item_id'],'requested_qty'=>$line['qty']]);
        if($pr)$this->link($org,'PR',(int)$pr['id'],'RIS',$id);
        $this->audit($org,'DRAFT_CREATED','RIS',$id,['requestor_employee_id'=>$requestorId,
            'source_mode'=>$mode,'source_pr_id'=>(int)($pr['id']??0),'line_count'=>count($verified)]);
        return ['id'=>$id,'status'=>'DRAFT'];
    });}
    /** Cancel a NEVER-ISSUED draft. Existing schema has no CANCELLED enum value:
     * terminal REVERSED is used internally; distinct audit + no document number
     * identify unissued cancellation in all read views. No stock ledger changes.
     */
    public function cancelRIS(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org,'ris.cancel');return $this->tx(function()use($org,$d){
        $ris=$this->record('requisition_issue_slips',idOf($d,'ris_id'),$org,true);
        demand($ris['status']==='DRAFT' && empty($ris['display_number']),
            'Only an unissued, unnumbered RIS draft can be cancelled',409);
        $reason=requiredString($d,'reason',500);
        $qty=one($this->db,'SELECT COUNT(*) n FROM ris_lines WHERE ris_id=? AND issued_qty<>0',[$ris['id']]);
        demand((int)$qty['n']===0,'This RIS has recorded issuance; cancellation is not permitted',409);
        $movements=one($this->db,"SELECT COUNT(*) n FROM stock_events e JOIN ris_lines l
            ON l.id=e.source_id WHERE l.ris_id=? AND e.source_type='RIS_LINE'",[$ris['id']]);
        demand((int)$movements['n']===0,'RIS has stock events and cannot be cancelled as a draft',409);
        q($this->db,"UPDATE requisition_issue_slips SET status='REVERSED' WHERE id=? AND organization_id=? AND status='DRAFT'",
            [$ris['id'],$org]);
        $this->snapshot($org,'RIS',(int)$ris['id'],['status'=>'CANCELLED_DRAFT',
            'source_mode'=>(string)$ris['source_mode'],'source_pr_id'=>$ris['pr_id'],
            'no_stock_issued'=>true],$reason);
        $this->audit($org,'CANCELLED_DRAFT','RIS',(int)$ris['id'],['reason'=>$reason,
            'source_pr_id'=>$ris['pr_id'],'requestor'=>$ris['requestor']]);
        return ['id'=>(int)$ris['id'],'status'=>'CANCELLED'];
    });}
    public function issueRIS(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org,'ris.issue');return $this->tx(function()use($org,$d){
        $ris=$this->record('requisition_issue_slips',idOf($d,'ris_id'),$org,true);demand($ris['status']==='DRAFT','RIS must be draft');$selections=rowsRequired($d,'lines');
        $totals=[];foreach($selections as $line){$rid=idOf($line,'ris_line_id');$lotId=idOf($line,'lot_id');$qty=qtyMillis($line['issued_qty']??'');demand($qty>0,'Issue quantity must be positive');$ri=one($this->db,'SELECT * FROM ris_lines WHERE id=? AND ris_id=? FOR UPDATE',[$rid,$ris['id']]);demand($ri!==null,'RIS line not found');$totals[$rid]=($totals[$rid]??0)+$qty;demand($totals[$rid]<=qtyMillis($ri['requested_qty']),'Issue exceeds requested quantity');$lot=$this->record('inventory_lots',$lotId,$org,true);demand((int)$lot['item_id']===(int)$ri['item_id'],'Stock item mismatch');
            // Enforce at the write boundary, not just via a dropdown: even
            // a hand-crafted request must not issue historic service/asset lots.
            demand(one($this->db,"SELECT id FROM item_catalog WHERE id=? AND category='SUPPLY' AND active=1",[$lot['item_id']])!==null,
                'RIS issuance is restricted to consumable SUPPLY lots; service/property receipts cannot be issued as stock.',409);
            if($lot['iar_line_id']!==null){
                $registered=one($this->db,'SELECT id FROM assets WHERE organization_id=? AND iar_line_id=? LIMIT 1',[$org,$lot['iar_line_id']]);
                demand($registered===null,'This IAR lot is under accountable-property registration. RIS issuance is blocked pending inventory custody policy review',409);
            }
            $onhand=one($this->db,'SELECT COALESCE(SUM(quantity_delta),0) available FROM stock_events WHERE lot_id=?',[$lotId]);$available=qtyMillis((string)$onhand['available']);demand($qty<=$available,'Insufficient physically received stock');
            // One issued selection per RIS line per lot. Multiple lots are supported per item via separate lines.
            $this->insert('stock_events',['organization_id'=>$org,'lot_id'=>$lotId,'event_type'=>'RIS_ISSUE','quantity_delta'=>'-'.qtyString($qty),'source_type'=>'RIS_LINE','source_id'=>$rid,'created_by'=>$this->user['id']]);q($this->db,'UPDATE ris_lines SET issued_qty=issued_qty+?,lot_id=? WHERE id=?',[qtyString($qty),$lotId,$rid]);
        }
        $number=$this->registered($org,'RIS',(int)$ris['id']);q($this->db,"UPDATE requisition_issue_slips SET status='ISSUED',display_number=? WHERE id=?",[$number,$ris['id']]);$this->snapshot($org,'RIS',(int)$ris['id'],['number'=>$number,'lines'=>q($this->db,'SELECT * FROM ris_lines WHERE ris_id=?',[$ris['id']])->fetchAll()]);$this->audit($org,'ISSUED','RIS',(int)$ris['id']);return ['id'=>(int)$ris['id'],'number'=>$number];
    });}
    public function reverseRIS(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org);return $this->tx(function()use($org,$d){
        $ris=$this->record('requisition_issue_slips',idOf($d,'ris_id'),$org,true);demand($ris['status']==='ISSUED','Only issued RIS can be reversed');$reason=requiredString($d,'reason',500);
        $events=q($this->db,"SELECT e.* FROM stock_events e JOIN ris_lines l ON l.id=e.source_id WHERE e.source_type='RIS_LINE' AND e.event_type='RIS_ISSUE' AND l.ris_id=?",[$ris['id']])->fetchAll();foreach($events as $e){$this->record('inventory_lots',(int)$e['lot_id'],$org,true);$this->insert('stock_events',['organization_id'=>$org,'lot_id'=>$e['lot_id'],'event_type'=>'RIS_RETURN','quantity_delta'=>substr($e['quantity_delta'],1),'source_type'=>'RIS_REVERSAL','source_id'=>$e['id'],'created_by'=>$this->user['id']]);}
        q($this->db,"UPDATE requisition_issue_slips SET status='REVERSED' WHERE id=?",[$ris['id']]);$this->snapshot($org,'RIS',(int)$ris['id'],['status'=>'REVERSED'],$reason);$this->audit($org,'REVERSED','RIS',(int)$ris['id'],['reason'=>$reason]);return ['id'=>(int)$ris['id'],'status'=>'REVERSED'];
    });}
    public function addFundEvent(array $d): array {$org=$this->org($d);demand(in_array($this->user['role'],['SUPER_ADMIN','DIVISION'],true),'Finance administrator required',403);return $this->financialTx('fund.event',$d,function()use($org,$d){
        $fund=$this->record('funds',idOf($d,'fund_id'),$org,true);$event=requiredString($d,'event_type',30);demand(in_array($event,['RELEASE','OBLIGATION','OBLIGATION_REVERSAL','ADJUSTMENT'],true),'Bank downloads must be recorded using Record D.O. Bank Download; direct manual allotments are disabled');$amount=moneyCents($d['amount']??'');demand($amount>0,'Positive ledger amount required');$ref=requiredString($d,'reference',100);$reason=requiredString($d,'reason',500);
        // D.O. MOOE downloading is verified through a bank-linked event, not
        // manual ALLOTMENT or an unbacked positive ADJUSTMENT. Other fund types
        // retain their existing authorized adjustment workflow pending review.
        if($event==='ADJUSTMENT' && strtoupper(trim((string)$fund['source_code']))==='MOOE')
            demand(false,'MOOE allocation changes require a bank-linked download or separately reviewed correction; unbacked positive adjustments are disabled',409);
        // RELEASE is informational cash, not budget. Manual obligations reserve
        // allocation immediately. Over-reversals are forbidden.
        if($event==='OBLIGATION' || $event==='OBLIGATION_REVERSAL'){
            $totals=$this->fundAvailabilityTotals($org,(int)$fund['id']);
            if($event==='OBLIGATION')demand(!str_starts_with($totals['available_allocation'],'-') && moneyCents($totals['available_allocation']) >= $amount, 'Insufficient source-of-fund allocation for this obligation');
            else demand(moneyCents($totals['outstanding_obligations']) >= $amount, 'Cannot reverse more than outstanding obligations');
        }
        $this->ledger($org,(int)$fund['id'],$event,$amount,'MANUAL',0,$ref.' — '.$reason);$this->audit($org,'LEDGER_POSTED','FUND',(int)$fund['id'],['type'=>$event,'amount'=>moneyString($amount),'reference'=>$ref]);return ['fund_id'=>(int)$fund['id'],'event'=>$event];
    });}
    /** Physical numbered cheque paper is distinct from an issued payment check.
     * Preview has NO side effect; no provisional number is ever put on the printed DV.
     */
    public function nextPhysicalCheck(array $d): array {
        $org=$this->org($d);
        $bank=$this->record('bank_accounts',idOf($d,'bank_account_id'),$org);
        $leaf=one($this->db,"SELECT l.id,l.serial,l.book_reference FROM checkbook_leaves l
            WHERE l.organization_id=? AND l.bank_account_id=? AND l.status='AVAILABLE'
            AND NOT EXISTS (SELECT 1 FROM checks c WHERE c.bank_account_id=l.bank_account_id AND c.check_number=l.serial)
            ORDER BY l.id LIMIT 1",[$org,$bank['id']]);
        return ['bank_account_id'=>(int)$bank['id'],'bank_name'=>$bank['bank_name'],
            'leaf_id'=>$leaf? (int)$leaf['id']:null,'check_number'=>$leaf['serial']??null,
            'book_reference'=>$leaf['book_reference']??null,
            'status'=>$leaf?'PROVISIONAL_NOT_ISSUED':'NO_AVAILABLE_VERIFIED_LEAF',
            'message'=>$leaf?'Verify this serial against the actual intact physical check. The serial is only assigned after you confirm and successfully finalize the DV.':'No verified blank check leaves are registered for this bank. Register the actual physical checkbook in Cash & Bank.'];
    }
    public function listPhysicalChecks(array $d): array {
        $org=$this->org($d);
        $rows=q($this->db,"SELECT l.id,l.bank_account_id,l.book_reference,l.serial,l.status,l.created_at,b.bank_name,b.account_label,
                c.id AS issued_check_id,c.dv_id,c.status AS issued_check_status
            FROM checkbook_leaves l JOIN bank_accounts b ON b.id=l.bank_account_id AND b.organization_id=l.organization_id
            LEFT JOIN checks c ON c.bank_account_id=l.bank_account_id AND c.check_number=l.serial AND c.organization_id=l.organization_id
            WHERE l.organization_id=? ORDER BY l.id DESC LIMIT 250",[$org])->fetchAll(PDO::FETCH_ASSOC);
        $stats=q($this->db,"SELECT status,COUNT(*) AS n FROM checkbook_leaves WHERE organization_id=? GROUP BY status",[$org])->fetchAll(PDO::FETCH_ASSOC);
        return ['rows'=>$rows,'stats'=>$stats,'note'=>'Only the first 250 most recently registered leaves are shown. Existing issued check rows remain separately accountable.'];
    }
    /** School printer alignment only — never changes payable check, DV, or bank amounts. */
    private static function schoolCheckPrintDefaults(): array {
        return [
            'width_mm'=>205,'height_mm'=>82,'font_pt'=>10,
            'positions'=>[
                'date_month'=>['x'=>143,'y'=>11],
                'date_day'=>['x'=>155,'y'=>11],
                'date_year'=>['x'=>169,'y'=>11],
                'payee'=>['x'=>22,'y'=>32],
                'numeric'=>['x'=>158,'y'=>31],
                'words'=>['x'=>18,'y'=>48],
                'signatory'=>['x'=>142,'y'=>65],
            ],
        ];
    }
    private static function validateSchoolCheckPrintProfile(array $data): array {
        $defaults=self::schoolCheckPrintDefaults();
        $profile=[];
        foreach(['width_mm'=>[140,255],'height_mm'=>[65,115],'font_pt'=>[8,14]] as $field=>$limits){
            $n=$data[$field]??null;
            demand(is_int($n)||is_float($n)||is_string($n)&&preg_match('/^[0-9]+(?:\.[0-9]+)?$/D',$n),
                'Check printer '.$field.' must be numeric.');
            $value=(float)$n;
            demand(is_finite($value)&&$value>=$limits[0]&&$value<=$limits[1],
                'Check printer '.$field.' is outside its approved range.');
            $profile[$field]=$field==='font_pt'?(int)round($value):round($value,1);
        }
        demand(is_array($data['positions']??null),'Provide printer offsets for all check fields.');
        $positions=[];
        foreach($defaults['positions'] as $field=>$default){
            $xy=$data['positions'][$field]??null;
            demand(is_array($xy),'Check printer position missing: '.$field);
            foreach(['x','y'] as $axis){
                $n=$xy[$axis]??null;
                demand(is_int($n)||is_float($n)||(is_string($n)&&preg_match('/^[0-9]+(?:\.[0-9]+)?$/D',$n)),
                    'Check printer position must be numeric: '.$field);
                $value=(float)$n;
                $max=($axis==='x'?$profile['width_mm']:$profile['height_mm'])-3;
                demand(is_finite($value)&&$value>=0&&$value<=$max,
                    'Check printer offset is outside the paper: '.$field);
                $positions[$field][$axis]=round($value,1);
            }
        }
        $profile['positions']=$positions;
        return $profile;
    }
    public function schoolCheckPrintProfile(array $d): array {
        $org=$this->org($d);
        $kind=one($this->db,'SELECT kind FROM organizations WHERE id=? AND active=1',[$org]);
        demand($kind!==null && in_array($kind['kind'],['SCHOOL','DIVISION'],true),'Check printer profile requires an active issuing office.',403);
        $configKey=$kind['kind']==='DIVISION'?'DIVISION_CHECK_PRINT_LAYOUT_V1':'SCHOOL_CHECK_PRINT_LAYOUT_V1';
        $row=one($this->db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',[$org,$configKey]);
        $json=$row?json_decode((string)$row['config_json'],true,64,JSON_THROW_ON_ERROR):null;
        $profile=$json!==null?self::validateSchoolCheckPrintProfile($json):self::schoolCheckPrintDefaults();
        return ['organization_id'=>$org,'version_id'=>(int)($row['id']??0),'profile'=>$profile];
    }
    public function saveSchoolCheckPrintProfile(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        $profile=self::validateSchoolCheckPrintProfile($d['profile']??[]);
        $expected=$d['expected_version']??null;
        demand((is_int($expected)||is_string($expected)&&ctype_digit($expected)) && (int)$expected>=0,
            'Reload the current school check printer profile first.');
        return $this->tx(function()use($org,$profile,$expected){
            // Serialize layouts per school before comparing the expected version.
            $row=one($this->db,'SELECT kind FROM organizations WHERE id=? AND active=1 FOR UPDATE',[$org]);
            demand($row && in_array($row['kind'],['SCHOOL','DIVISION'],true),'Check printer profile requires an active issuing office.',403);
            $previous=$this->schoolCheckPrintProfile(['organization_id'=>$org]);
            demand($previous['version_id']===(int)$expected,'Check printer settings changed elsewhere. Reload before saving.',409);
            $configKey=$row['kind']==='DIVISION'?'DIVISION_CHECK_PRINT_LAYOUT_V1':'SCHOOL_CHECK_PRINT_LAYOUT_V1';
            $version=$this->insert('configuration_versions',[
                'organization_id'=>$org,'config_key'=>$configKey,
                'config_json'=>json_encode($profile,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
                'effective_from'=>self::todayManila(),
                'review_note'=>'Printer alignment profile saved by authorized issuing-office user',
            ]);
            $this->audit($org,'CHECK_PRINT_LAYOUT_SAVED','CONFIGURATION',$version,
                ['before_version'=>$previous['version_id'],'previous'=>$previous['profile'],'after'=>$profile]);
            return ['organization_id'=>$org,'version_id'=>$version,'profile'=>$profile];
        });
    }

    /** v0.2.445: Authoritative, organization-scoped physical check print snapshot.
     * The DV gross/net is NEVER a fallback for the saved issued-check amount.
     * READ ONLY: this method does not commit, print, pay, or release anything.
     */
    public function actualCheckPrintSnapshot(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        if(isset($d['check_id']) && (string)$d['check_id']!==''){
            $check=$this->record('checks',idOf($d,'check_id'),$org);
        }else{
            $dv=$this->record('disbursement_vouchers',idOf($d,'dv_id'),$org);
            $candidates=q($this->db,"SELECT id FROM checks WHERE organization_id=? AND dv_id=?
                AND status NOT IN ('CANCELLED','REPLACED','STOPPED','STALE')
                ORDER BY id DESC LIMIT 2",[$org,$dv['id']])->fetchAll(PDO::FETCH_ASSOC);
            demand(count($candidates)>0,'This DV has no currently active issued check; open Cash & Bank and verify its physical check record.',409);
            demand(count($candidates)===1,'More than one active check is linked to this DV; select the specific check in Cash & Bank.',409);
            $check=$this->record('checks',(int)$candidates[0]['id'],$org);
        }
        $dv=$this->record('disbursement_vouchers',(int)$check['dv_id'],$org);
        $bank=$this->record('bank_accounts',(int)$check['bank_account_id'],$org);
        $profile=$this->schoolCheckPrintProfile(['organization_id'=>$org]);
        $leaf=one($this->db,'SELECT id,status FROM checkbook_leaves
            WHERE organization_id=? AND bank_account_id=? AND serial=? LIMIT 1',
            [$org,$check['bank_account_id'],$check['check_number']]);
        // Print attempts are not check status transitions. Keep the full history,
        // allow repeat attempts, and preserve the original printing date for copies.
        $printed=one($this->db,"SELECT COUNT(*) AS n FROM payment_events
            WHERE check_id=? AND event_type='CHECK_PRINT_PREPARED'",[$check['id']]);
        $printCount=(int)($printed['n']??0);
        $prior=one($this->db,"SELECT id,created_at,evidence_reference FROM payment_events
            WHERE check_id=? AND event_type='CHECK_PRINT_PREPARED' ORDER BY id DESC LIMIT 1",[$check['id']]);
        $lastPrintDate=null;
        if($prior && preg_match('/(?:^|;)CHECK_DATE=(\d{4}-\d{2}-\d{2})(?:;|$)/',(string)($prior['evidence_reference']??''),$dateMatch))
            $lastPrintDate=$dateMatch[1];
        $warnings=[];
        if(!in_array($check['status'],['ISSUED','SIGNED','RELEASED','CLEARED'],true))
            $warnings[]='Stopped, stale, cancelled or replaced checks cannot produce another negotiable check. Use the non-negotiable alignment proof.';
        if($dv['status']!=='FINALIZED')$warnings[]='The linked DV is not finalized.';
        if(!$leaf || $leaf['status']!=='ISSUED')$warnings[]='Physical check stock is missing or no longer issued to this record.';
        if((int)$profile['version_id']<=0)$warnings[]='Save and verify the issuing office check alignment layout before printing.';
        $amount=trim((string)($check['amount']??''));
        if(!preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?$/D',$amount)||!preg_match('/[1-9]/',$amount))
            $warnings[]='The saved check amount is unavailable or invalid. Check the original record.';
        $payee=trim((string)($check['payee_name']??''));
        if($payee==='' || mbSafeLen($payee)>200)$warnings[]='The saved check payee is missing or too long.';
        if(!preg_match('/^[0-9]{1,24}$/D',(string)$check['check_number']))
            $warnings[]='Physical serial must be verified against the bank checkbook.';
        return [
            'ready'=>count($warnings)===0,'warnings'=>$warnings,
            'check'=>['id'=>(int)$check['id'],'dv_id'=>(int)$dv['id'],
                'check_number'=>(string)$check['check_number'],'status'=>(string)$check['status'],
                'amount'=>$amount,'payee_name'=>$payee,'payee_basis'=>(string)($check['payee_basis']??''),
                'created_at'=>(string)($check['created_at']??'')],
            'dv'=>['id'=>(int)$dv['id'],'display_number'=>(string)($dv['display_number']??''),
                'document_date'=>(string)($dv['document_date']??''),'status'=>(string)$dv['status']],
            'bank'=>['id'=>(int)$bank['id'],'bank_name'=>(string)$bank['bank_name'],
                'account_label'=>(string)($bank['account_label']??'')],
            'layout'=>$profile,
            'already_prepared'=>$prior!==null,
            'print_count'=>$printCount,
            'last_print_date'=>$lastPrintDate,
            'physical_leaf_verified'=>$leaf!==null && $leaf['status']==='ISSUED',
        ];
    }
    /** Records EACH check print preparation attempt. No artificial attempt limit:
     * printing, including repeats, never changes status, posts funds, or signs a check.
     * Every requested actual print is accountable to one check/serial/operator.
     */
    public function prepareActualCheckPrint(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        $checkId=idOf($d,'check_id');
        $date=self::transactionDocumentDate(trim((string)($d['check_date']??'')),'Check print date');
        $version=idOf($d,'layout_version');
        // v0.2.448: the explicit Print button is the operator's print request.
        // No duplicate checkbox attestations; all authoritative checks below remain enforced.
        $serial=trim((string)($d['physical_serial']??''));
        return $this->tx(function()use($org,$checkId,$date,$version,$serial){
            // Match check-status mutations: lock DV before check to avoid
            // deadlocks with a concurrent signing/release transition.
            $candidate=$this->record('checks',$checkId,$org);
            $dv=$this->record('disbursement_vouchers',(int)$candidate['dv_id'],$org,true);
            $check=$this->record('checks',$checkId,$org,true);
            demand((int)$check['dv_id']===(int)$dv['id'],
                'Linked DV changed; reload check printing before proceeding.',409);
            demand($dv['status']==='FINALIZED' && in_array($check['status'],['ISSUED','SIGNED','RELEASED','CLEARED'],true),
                'The check is no longer valid for printing, or its DV is not finalized; reload before printing.',409);
            demand($serial!=='' && hash_equals((string)$check['check_number'],$serial),
                'Physical check serial does not match the saved check number.',409);
            demand($date === (string)$dv['document_date'],
                'Check print date must exactly match the finalized DV document date.',409);
            $current=$this->schoolCheckPrintProfile(['organization_id'=>$org]);
            demand((int)$current['version_id']===$version && $version>0,
                'The approved alignment layout changed or is not saved. Reload before printing.',409);
            $leaf=one($this->db,'SELECT id FROM checkbook_leaves
                WHERE organization_id=? AND bank_account_id=? AND serial=? AND status=? LIMIT 1',
                [$org,$check['bank_account_id'],$serial,'ISSUED']);
            demand($leaf!==null,'The verified physical leaf is missing or no longer issued.',409);
            // The locked check row serializes print preparations for a single
            // accountable instrument; repeat attempts are recorded, not blocked.
            $previous=one($this->db,"SELECT COUNT(*) AS n FROM payment_events
                WHERE check_id=? AND event_type='CHECK_PRINT_PREPARED'",[$checkId]);
            $attempt=(int)($previous['n']??0)+1;
            $amount=trim((string)($check['amount']??''));
            demand(preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?$/D',$amount)===1 &&
                preg_match('/[1-9]/',$amount)===1,'Saved check amount is not printable.',409);
            $payee=trim((string)($check['payee_name']??''));
            demand($payee!=='' && mbSafeLen($payee)<=200,'Saved check payee is not printable.',409);
            $evidence='CHECK_DATE='.$date.';LAYOUT_VERSION='.$version.';PRINT_ATTEMPT='.$attempt.';CHECK_STATUS='.$check['status'].';PRINT_PREPARED_NOT_SIGNED';
            $event=$this->insert('payment_events',['check_id'=>$checkId,'event_type'=>'CHECK_PRINT_PREPARED',
                'evidence_reference'=>$evidence,'created_by'=>$this->user['id']]);
            $this->audit($org,'CHECK_PRINT_PREPARED','CHECK',$checkId,
                ['check_date'=>$date,'layout_version'=>$version,'event_id'=>$event,'print_attempt'=>$attempt,
                 'status_unchanged'=>$check['status'],'manual_wet_signatures_required'=>true,
                 'source_document_date_verified'=>true]);
            return ['prepared'=>true,'check_id'=>$checkId,'check_date'=>$date,
                'layout_version'=>$version,'saved_amount'=>$amount,'saved_payee'=>$payee,'print_count'=>$attempt,
                'status_unchanged'=>$check['status'],'notice'=>'Print attempt recorded without changing check status or posting a payment.'];
        });
    }

    /** Registered checkbooks are verified physical blank stock, never generated financial proceeds. */
    public function registerPhysicalCheckbook(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        return $this->tx(function()use($org,$d){
            $bank=$this->record('bank_accounts',idOf($d,'bank_account_id'),$org,true);
            demand(($d['physically_verified_blank']??null)===true,
                'Personally confirm all paper leaves are blank, intact, never printed/signed and never previously issued.',409);
            $reference=requiredString($d,'book_reference',100);
            $first=requiredString($d,'first_serial',24);$last=requiredString($d,'last_serial',24);
            demand(preg_match('/^[0-9]{1,24}$/D',$first)===1 && preg_match('/^[0-9]{1,24}$/D',$last)===1,
                'Check serial range must be numeric; preserve printed leading zeroes.');
            demand(strlen($first)===strlen($last),'Physical serial widths must match, including leading zeroes.');
            demand(strlen(ltrim($first,'0'))<=15 && strlen(ltrim($last,'0'))<=15,
                'Serial number exceeds safe numeric range; require administrative review.');
            $start=(int)$first;$end=(int)$last;
            demand($end>=$start && ($end-$start)<200,'Enter up to 200 consecutive unused leaves per registration.');
            $width=strlen($first);$numbers=[];
            for($n=$start;$n<=$end;$n++){
                $serial=str_pad((string)$n,$width,'0',STR_PAD_LEFT);$numbers[]=$serial;
                demand(one($this->db,'SELECT id FROM checks WHERE bank_account_id=? AND check_number=?',[$bank['id'],$serial])===null,
                    'Check '.$serial.' already appears in issued-check records. Cannot register as blank.',409);
                demand(one($this->db,'SELECT id FROM checkbook_leaves WHERE bank_account_id=? AND serial=?',[$bank['id'],$serial])===null,
                    'Leaf '.$serial.' already registered (including unusable leaves). Cannot double-register.',409);
            }
            foreach($numbers as $serial)$this->insert('checkbook_leaves',['organization_id'=>$org,'bank_account_id'=>$bank['id'],
                'book_reference'=>$reference,'serial'=>$serial,'status'=>'AVAILABLE','created_by'=>$this->user['id']]);
            $this->audit($org,'PHYSICAL_CHECKBOOK_VERIFIED','BANK',(int)$bank['id'],
                ['checkbook'=>$reference,'first'=>$first,'last'=>$last,'count'=>count($numbers)]);
            return ['registered'=>count($numbers),'first'=>$first,'last'=>$last,'bank_account_id'=>(int)$bank['id']];
        });
    }
    public function markPhysicalLeafUnusable(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        return $this->tx(function()use($org,$d){
            $leaf=one($this->db,'SELECT * FROM checkbook_leaves WHERE id=? AND organization_id=? FOR UPDATE',
                [idOf($d,'leaf_id'),$org]);
            demand($leaf!==null && $leaf['status']==='AVAILABLE','Only available unissued paper leaves may be marked unusable.',409);
            demand(one($this->db,'SELECT id FROM checks WHERE bank_account_id=? AND check_number=?',[$leaf['bank_account_id'],$leaf['serial']])===null,
                'Issued check is accountable in the Check Register, not as unused paper.',409);
            $status=requiredString($d,'status',16);
            demand(in_array($status,['SPOILED','LOST','VOID'],true),'Choose spoiled, lost, or void paper leaf.');
            $reason=requiredString($d,'reason',500);
            demand(mbSafeLen($reason)>=12,'Give a specific reason of at least 12 characters.');
            q($this->db,"UPDATE checkbook_leaves SET status=? WHERE id=? AND status='AVAILABLE'",[$status,$leaf['id']]);
            $this->audit($org,'PHYSICAL_CHECK_LEAF_'.$status,'BANK',(int)$leaf['bank_account_id'],
                ['leaf_id'=>(int)$leaf['id'],'serial'=>$leaf['serial'],'reason'=>$reason]);
            return ['id'=>(int)$leaf['id'],'serial'=>$leaf['serial'],'status'=>$status];
        });
    }
    /** Audited correction of a paper-inventory error, only for stock NEVER
     * linked to a payable check. No signed/issued leaf can be rehabilitated.
     * A recorded check remains an immutable bank/check accountability number.
     */
    public function correctPhysicalLeafStatus(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        return $this->tx(function()use($org,$d){
            $leaf=one($this->db,'SELECT * FROM checkbook_leaves WHERE id=? AND organization_id=? FOR UPDATE',
                [idOf($d,'leaf_id'),$org]);
            demand($leaf!==null,'This physical check leaf is not in your workspace.',404);
            $new=requiredString($d,'status',16);
            demand($new==='AVAILABLE' && in_array($leaf['status'],['SPOILED','VOID'],true),
                'Only mistakenly spoiled/voided NEVER-ISSUED physical stock can be restored as available.',409);
            demand(one($this->db,'SELECT 1 FROM checks WHERE bank_account_id=? AND check_number=? LIMIT 1',
                [$leaf['bank_account_id'],$leaf['serial']])===null,
                'This physical serial has a historical check record and cannot be restored through stock correction.',409);
            demand(($d['confirmed_physical_leaf']??false)===true && ($d['never_printed_signed_released']??false)===true,
                'Physically confirm this exact leaf is blank, unprinted, unsigned, intact and was never issued.',409);
            $reason=requiredString($d,'reason',500);
            demand(mbSafeLen($reason)>=20,'Give a specific correction reason of at least 20 characters.',409);
            q($this->db,"UPDATE checkbook_leaves SET status='AVAILABLE' WHERE id=? AND status=?",[$leaf['id'],$leaf['status']]);
            $this->audit($org,'PHYSICAL_CHECK_LEAF_STATUS_CORRECTED','BANK',(int)$leaf['bank_account_id'],
                ['leaf_id'=>(int)$leaf['id'],'serial'=>$leaf['serial'],'previous'=>$leaf['status'],
                    'corrected'=>'AVAILABLE','reason'=>$reason,'physically_verified'=>true]);
            return ['id'=>(int)$leaf['id'],'serial'=>$leaf['serial'],'previous'=>$leaf['status'],'status'=>'AVAILABLE'];
        });
    }
    /** Must run UNDER parent bank row lock; no free-form serial selection. */
    private function claimNextPhysicalLeaf(int $org,int $bankId,array $d): array {
        $leaf=one($this->db,"SELECT l.id,l.serial,l.book_reference FROM checkbook_leaves l
            WHERE l.organization_id=? AND l.bank_account_id=? AND l.status='AVAILABLE'
            AND NOT EXISTS (SELECT 1 FROM checks c WHERE c.bank_account_id=l.bank_account_id AND c.check_number=l.serial)
            ORDER BY l.id LIMIT 1 FOR UPDATE",[$org,$bankId]);
        demand($leaf!==null,'No verified available blank physical check for this bank. Register stock first.',409);
        demand((int)($d['expected_leaf_id']??0)===(int)$leaf['id'] &&
            hash_equals((string)$leaf['serial'],trim((string)($d['check_number']??''))),
            'Next available physical serial changed. Refresh and compare with the actual checkbook.',409);
        demand(($d['confirmed_physical_leaf']??false)===true,
            'Confirm the proposed serial matches the actual blank, intact physical check before recording issuance.',409);
        return $leaf;
    }
    public function createCheck(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org);return $this->financialTx('check.create',$d,function()use($org,$d){
        $dv=$this->record('disbursement_vouchers',idOf($d,'dv_id'),$org,true);demand($dv['status']==='FINALIZED','Finalized DV needed');
        // Legacy issuance must retain the same gross authority control as a
        // new finalization. An explicit download map cannot cure an already
        // overcommitted fund or turn an unpaid legacy DV into new authority.
        $budget=$this->fundAvailabilityTotals($org,(int)$dv['fund_id']);$text=(string)$budget['available_allocation'];$remaining=str_starts_with($text,'-')?-moneyCents(substr($text,1)):moneyCents($text);
        demand($budget['has_allocation'],'Source fund has no verified gross allocation authority',409);
        if($remaining<0){$mode=$this->schoolUtilizableFundingMode($org,(int)$dv['fund_id'],true);demand($mode['enabled'],'Source fund gross authority is overcommitted; reconcile it before legacy check issuance',409);$eligible=$this->confirmedRetainedAuthority($org,(int)$dv['fund_id']);demand($remaining+$eligible['recorded_eligible_cents']>=0,'Source fund gross shortfall exceeds recorded eligible retained authority',409);}
        demand(one($this->db,'SELECT id FROM checks WHERE dv_id=? LIMIT 1',[$dv['id']])===null,
            'This finalized DV has a check record. Use an audited check replacement procedure; ordinary check creation cannot duplicate an assigned serial.',409);
        // Legacy finalized vouchers without any linked check remain eligible for
        // the historical separate physical issuance path.
        $bank=$this->record('bank_accounts',idOf($d,'bank_account_id'),$org,true);
        $leaf=$this->claimNextPhysicalLeaf($org,(int)$bank['id'],$d);$checkNo=(string)$leaf['serial'];
        demand(one($this->db,'SELECT id FROM checks WHERE bank_account_id=? AND check_number=? LIMIT 1',[$bank['id'],$checkNo])===null,
            'This check number is already recorded for the selected bank, including cancelled checks',409);
        $amount=moneyCents($d['amount']??'');demand($amount>0,'Check amount must be positive');
        $previous=one($this->db,"SELECT COALESCE(SUM(amount),0) total FROM checks WHERE dv_id=? AND status NOT IN ('CANCELLED','REPLACED','STOPPED')",[$dv['id']]);demand($amount+moneyCents($previous['total'])<=moneyCents($dv['net']),'Check issuance exceeds voucher net');
        $supplierBacked=$dv['dv_type']==='REGULAR' && (int)($dv['supplier_id']??0)>0;
        $basis=strtoupper(trim((string)($d['payee_basis']??($supplierBacked?'BUSINESS':'DV_PAYEE'))));
        if($supplierBacked){
            demand(in_array($basis,['BUSINESS','PROPRIETOR'],true),'Select business or proprietor check payee');
            $payeeName=$basis==='BUSINESS'?(string)($dv['business_name_snapshot']??''):(string)($dv['proprietor_name_snapshot']??'');
            demand(trim($payeeName)!=='','This DV has no verified '.strtolower($basis).' name; check payee cannot be selected');
        }else{
            demand($basis==='DV_PAYEE','Only the verified DV payee is permitted for this payment');
            $payeeName=(string)$dv['payee'];
        }
        demand(mbSafeLen($payeeName)<=200,'Check payee name too long');
        $id=$this->insert('checks',['organization_id'=>$org,'bank_account_id'=>$bank['id'],'dv_id'=>$dv['id'],'check_number'=>$checkNo,
            'payee_name'=>$payeeName,'payee_basis'=>$basis,'amount'=>moneyString($amount),'created_by'=>$this->user['id']]);
        q($this->db,"UPDATE checkbook_leaves SET status='ISSUED' WHERE id=? AND organization_id=? AND status='AVAILABLE'",[$leaf['id'],$org]);
        $this->insert('payment_events',['check_id'=>$id,'event_type'=>'ISSUED','created_by'=>$this->user['id']]);
        $this->link($org,'DV',(int)$dv['id'],'CHECK',$id);
        $this->audit($org,'ISSUED','CHECK',$id,['payee_basis'=>$basis,'payee_name'=>$payeeName,'dv_id'=>(int)$dv['id']]);
        return ['id'=>$id,'status'=>'ISSUED','payee_name'=>$payeeName,'payee_basis'=>$basis];
    });}
    public function updateCheck(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org);return $this->financialTx('check.status',$d,function()use($org,$d){
        // Match DV cancellation's DV-before-check order and serialize all leaves
        // in a replacement chain, including concurrent releases.
        $candidate=$this->record('checks',idOf($d,'check_id'),$org);
        $dv=$this->record('disbursement_vouchers',(int)$candidate['dv_id'],$org,true);
        $check=$this->record('checks',idOf($d,'check_id'),$org,true);$next=requiredString($d,'status',25);
        $taxTransfer=$this->taxTransferRecord($org,(int)$dv['id'],true);
        if($taxTransfer!==null && in_array($taxTransfer['status'],['DO_ACCOUNTING_PENDING','RECONCILED'],true))
            demand(!in_array($next,['STOPPED','STALE','CANCELLED','REPLACED'],true),
                'Division already acknowledged the remittance. A check reversal needs separate signed Division accounting and tax correction.',409);
        demand(!in_array($next,['CANCELLED','REPLACED'],true),
            'Use the controlled DV void for an unusable unsigned check, or the physical check replacement action. Other cancellation requires formal accounting review.',409);
        $valid=['ISSUED'=>['SIGNED','STOPPED'],'SIGNED'=>['RELEASED','STOPPED'],'RELEASED'=>['CLEARED','STOPPED','STALE'],'STALE'=>[],'STOPPED'=>[],'CLEARED'=>[],'CANCELLED'=>[],'REPLACED'=>[]];
        demand(in_array($next,$valid[$check['status']]??[],true),'Invalid check status transition');
        demand($dv['status']==='FINALIZED','Cannot change payment status for a voided or unfinalized DV.',409);
        // Reference is optional, but an explicit current-event attestation is not.
        // Store a truthful marker instead of fabricating a physical supporting document.
        demand(($d['event_confirmed']??null)===true,'Confirm that the check event actually occurred.',422);
        $providedEvidence=trim((string)($d['evidence_reference']??''));
        demand(mbSafeLen($providedEvidence)<=190,'Supporting reference must be 190 characters or fewer.',422);
        $hasReference=$providedEvidence!=='';
        $evidence=$hasReference?$providedEvidence:'USER_ATTESTED_NO_REFERENCE';
        q($this->db,'UPDATE checks SET status=? WHERE id=?',[$next,$check['id']]);$this->insert('payment_events',['check_id'=>$check['id'],'event_type'=>$next,'evidence_reference'=>$evidence,'created_by'=>$this->user['id']]);
        if($next==='RELEASED' && !$this->replacementAncestorReleased($org,$check))
            $this->ledger($org,(int)$dv['fund_id'],'DISBURSEMENT',moneyCents($check['amount']),'CHECK',(int)$check['id']);
        if($next==='RELEASED'){
            $transfer=$this->taxTransferRecord($org,(int)$dv['id'],true);
            if($transfer!==null && $transfer['status']==='CHECK_ASSIGNED'){
                $state=TaxTransferRules::next((string)$transfer['status'],'RELEASE');
                q($this->db,'UPDATE school_tax_transfers SET status=? WHERE id=?',[$state,$transfer['id']]);
            }
        }
        // Fund reversals for stopped/replaced released checks need confirmed accounting review: no implicit refund.
        $this->audit($org,$next,'CHECK',(int)$check['id'],['dv_id'=>(int)$dv['id'],'previous_status'=>$check['status'],'evidence_reference'=>$evidence,'reference_provided'=>$hasReference,'user_attested'=>true]);return ['id'=>(int)$check['id'],'status'=>$next];
    });}
    /** Called while the owning DV is locked. Inspect the entire chain, not
     * only the immediately preceding leaf, which may never have been released.
     * Do not suppress legitimate historic split payments on other root checks.
     */
    private function replacementAncestorReleased(int $org,array $check): bool {
        $seen=[(int)$check['id']=>true];$cursor=$check;$released=false;
        while(!empty($cursor['replaces_check_id'])){
            $id=(int)$cursor['replaces_check_id'];
            demand(!isset($seen[$id]),'Cyclic check replacement history; accounting review required.',409);
            $seen[$id]=true;
            $cursor=$this->record('checks',$id,$org,true);
            demand((int)$cursor['dv_id']===(int)$check['dv_id'] &&
                (int)$cursor['bank_account_id']===(int)$check['bank_account_id'] &&
                moneyCents($cursor['amount'])===moneyCents($check['amount']),
                'Inconsistent check replacement history; accounting review required.',409);
            if(one($this->db,"SELECT 1 FROM payment_events WHERE check_id=? AND event_type='RELEASED' LIMIT 1",[$id])!==null)$released=true;
        }
        return $released;
    }
    public function replaceCheck(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org);return $this->financialTx('check.replace',$d,function()use($org,$d){
        $candidate=$this->record('checks',idOf($d,'old_check_id'),$org);
        $dv=$this->record('disbursement_vouchers',(int)$candidate['dv_id'],$org,true);
        $taxTransfer=$this->taxTransferRecord($org,(int)$dv['id'],true);
        if($taxTransfer!==null) demand(!in_array($taxTransfer['status'],['DO_ACCOUNTING_PENDING','RECONCILED'],true),
            'Division already received this transfer; replacement requires separate signed Division accounting correction.',409);
        demand($dv['status']==='FINALIZED','Replacement requires an active finalized DV.',409);
        $old=$this->record('checks',idOf($d,'old_check_id'),$org,true);
        demand(in_array($old['status'],['STALE','STOPPED'],true),'Only stale/stopped checks can be replaced');
        $reason=requiredString($d,'reason',500);
        demand(mbSafeLen($reason)>=12,'Record the authorized documentary reason for replacement.',409);
        $this->replacementAncestorReleased($org,$old); // Fail closed on corrupt legacy chains.
        $bank=$this->record('bank_accounts',(int)$old['bank_account_id'],$org,true);
        $leaf=$this->claimNextPhysicalLeaf($org,(int)$bank['id'],$d);$number=(string)$leaf['serial'];
        demand(one($this->db,'SELECT id FROM checks WHERE bank_account_id=? AND check_number=? LIMIT 1',[$bank['id'],$number])===null,
            'This bank check number has already been recorded',409);
        $new=$this->insert('checks',['organization_id'=>$org,'bank_account_id'=>$old['bank_account_id'],'dv_id'=>$old['dv_id'],'check_number'=>$number,'amount'=>$old['amount'],'replaces_check_id'=>$old['id'],'payee_name'=>$old['payee_name'],'payee_basis'=>$old['payee_basis'],'created_by'=>$this->user['id']]);
        q($this->db,"UPDATE checks SET status='REPLACED' WHERE id=?",[$old['id']]);
        $claimed=q($this->db,"UPDATE checkbook_leaves SET status='ISSUED' WHERE id=? AND organization_id=? AND status='AVAILABLE'",[$leaf['id'],$org]);
        demand($claimed->rowCount()===1,'Replacement leaf changed; refresh the checkbook.',409);
        $this->link($org,'DV',(int)$dv['id'],'CHECK',$new);
        $this->link($org,'CHECK',(int)$old['id'],'CHECK',$new);
        $this->insert('payment_events',['check_id'=>$old['id'],'event_type'=>'REPLACED','evidence_reference'=>'Replacement check ID '.$new,'created_by'=>$this->user['id']]);
        $this->insert('payment_events',['check_id'=>$new,'event_type'=>'ISSUED','evidence_reference'=>'Replaces check ID '.$old['id'],'created_by'=>$this->user['id']]);
        $this->audit($org,'REPLACED','CHECK',(int)$old['id'],['new'=>$new,'dv_id'=>(int)$dv['id'],'leaf_id'=>(int)$leaf['id'],'reason'=>$reason]);
        return ['id'=>$new,'replaces'=>$old['id']];
    });}
    public function openingStock(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org);return $this->tx(function()use($org,$d){
        $item=idOf($d,'item_id');demand($this->catalogItemAccessible($org,$item),'Unknown item in this workspace catalog');
        demand(one($this->db,"SELECT id FROM item_catalog WHERE id=? AND category='SUPPLY' AND active=1",[$item])!==null,
            'Only active consumable SUPPLY items can receive opening stock. Use Property Accountability for asset opening records.',409);
        $qty=qtyMillis($d['quantity']??'');demand($qty>0,'Opening quantity must be positive');
        $lot=$this->insert('inventory_lots',['organization_id'=>$org,'item_id'=>$item,'opening_record'=>1,'acquisition_cost'=>isset($d['acquisition_cost'])&&$d['acquisition_cost']!==''?moneyString(moneyCents($d['acquisition_cost'])):null,'acquired_on'=>$d['acquired_on']??null,'batch'=>trim((string)($d['batch']??'')),'created_by'=>$this->user['id']]);$this->insert('stock_events',['organization_id'=>$org,'lot_id'=>$lot,'event_type'=>'OPENING','quantity_delta'=>qtyString($qty),'source_type'=>'OPENING','source_id'=>$lot,'created_by'=>$this->user['id']]);$this->audit($org,'OPENING_RECORDED','STOCK',$lot);return ['lot_id'=>$lot,'quantity'=>qtyString($qty)];
    });}
    /** Historical/opening registration; one asset per physical unit, never a fake PO/IAR/DV. */
    public function recordAsset(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        return $this->tx(function()use($org,$d){
            $item=idOf($d,'item_id');
            demand($this->catalogItemAccessible($org,$item) &&
                one($this->db,"SELECT 1 FROM item_catalog WHERE id=? AND category IN ('PPE','SEMI_EXPENDABLE') AND active=1",[$item])!==null,
                'Item must be an active PPE or semi-expendable catalog item',422);
            demand(!isset($d['property_tag'])&&!isset($d['iar_line_id'])&&!isset($d['po_id'])&&!isset($d['dv_id']),
                'Historical opening numbers are automatic; document references cannot create official source links',422);
            $rawCount=$d['unit_count']??1;
            demand((is_int($rawCount)||is_string($rawCount)) && preg_match('/^[1-9][0-9]{0,2}$/D',(string)$rawCount)===1
                && (int)$rawCount<=100,'Enter 1–100 whole property units',422);
            $count=(int)$rawCount;
            demand(!isset($d['shared']) || is_array($d['shared']),'Invalid shared property details',422);
            $shared=PropertyBatchMetadata::shared($d['shared']??[],'HISTORICAL_OPENING');
            // Registration records physical property only. An employee becomes accountable
            // through ICS/PAR issuance or a documented Property Transfer, never this form.
            demand(!isset($d['custodian']) || trim((string)$d['custodian'])==='',
                'Do not assign a custodian during registration; use ICS/PAR or Property Transfers',409);
            $rawUnits=$d['units']??null;
            if($rawUnits===null && $count===1)$rawUnits=[[
                'serial_number'=>$d['serial_number']??'', 'location'=>$d['location']??'',
                'condition_note'=>$d['condition_note']??'']];
            $units=PropertyBatchMetadata::units($rawUnits,$count);
            foreach($units as $unit)
                demand($unit['reported_custodian']==='',
                    'Custodian is not a registration field; use ICS/PAR or Property Transfers',409);
            $acquiredOn=$d['acquired_on']??null;
            if($acquiredOn!==null&&$acquiredOn!=='')$acquiredOn=self::transactionDocumentDate((string)$acquiredOn,'Date acquired');
            else $acquiredOn=null;
            $cost=isset($d['acquisition_cost'])&&$d['acquisition_cost']!==''
                ?moneyString(moneyCents($d['acquisition_cost'])):null;
            $batchKey=bin2hex(random_bytes(16));
            $numbers=PropertyRegistrationNumbers::reserve($this->db,$org,$count);
            $ids=[];
            foreach($numbers as $i=>$number){
                $unit=$units[$i];
                if($unit['serial_number']!=='')demand(one($this->db,
                    'SELECT id FROM assets WHERE organization_id=? AND serial_number=? LIMIT 1',
                    [$org,$unit['serial_number']])===null,'Serial number already registered in this school',409);
                $id=$this->insert('assets',[
                    'organization_id'=>$org,'item_id'=>$item,'property_tag'=>$number,
                    'serial_number'=>$unit['serial_number'],'acquisition_cost'=>$cost,
                    'acquired_on'=>$acquiredOn,'opening_record'=>1,
                    // No custody is created; historical metadata, if any, stays in prior audit history.
                    'custodian'=>null,'location'=>$unit['location'],'condition_note'=>$unit['condition_note'],
                    'registration_batch_key'=>$batchKey,
                    'property_profile'=>PropertyBatchMetadata::profile($shared,$unit)]);
                $ids[]=$id;
                $this->audit($org,'HISTORICAL_PROPERTY_REGISTERED','ASSET',$id,[
                    'property_tag'=>$number,'registration_batch_key'=>$batchKey,
                    'metadata'=>$shared,'unit'=>$unit,'historical_references_are_unlinked'=>true]);
            }
            return ['asset_ids'=>$ids,'property_numbers'=>$numbers,'registration_batch_key'=>$batchKey,
                'numbering_rule'=>'SCHOOL_ID-YYMM-LIFETIME_SEQUENCE',
                'note'=>'Historical property only; no official PO/IAR/DV, stock receipt, fund posting or custody slip created'];
        });
    }
    /** School-owned Asset Register pages; no cross-school expansion or mutation. */
    private function propertyAssetRegister(int $org,array $p): array {
        $this->operationalWriteAllowed($org);
        $term=trim((string)($p['search']??''));
        demand(mbSafeLen($term)<=100,'Asset search is too long',422);
        $category=(string)($p['category']??'ALL');
        demand(in_array($category,['ALL','PPE','SEMI_EXPENDABLE'],true),'Invalid asset type',422);
        $source=(string)($p['source']??'ALL');
        demand(in_array($source,['ALL','HISTORICAL_OPENING','VERIFIED_IAR','TRANSFER_IN'],true),'Invalid acquisition source',422);
        $status=(string)($p['status']??'ALL');
        demand((bool)preg_match('/^(ALL|[A-Z_]{1,20})$/D',$status),'Invalid property status',422);
        $custodian=trim((string)($p['custodian']??'ALL'));
        demand(mbSafeLen($custodian)<=160,'Invalid custodian filter',422);
        $itemFilter=trim((string)($p['item_id']??'ALL'));
        demand($itemFilter==='ALL'||(ctype_digit($itemFilter)&&(int)$itemFilter>0),'Invalid catalog item filter',422);
        $classification=trim((string)($p['classification']??'ALL'));
        demand(mbSafeLen($classification)<=80,'Invalid classification filter',422);
        $before=$p['before_id']??null;
        if($before!==null&&$before!=='')demand(ctype_digit((string)$before)&&(int)$before>0,'Invalid register page cursor',422);

        // Present the school's actual registered catalog classifications.
        // Semi-expendable uses the configured item subcategory. PPE can inherit
        // a Division-configured item/UACS classification mapping. No hard-coded
        // "ICT" or "Office" labels; honor current reference data.
        $itemRows=q($this->db,'SELECT DISTINCT i.id,i.name,i.category,i.subcategory FROM assets a JOIN item_catalog i ON i.id=a.item_id WHERE a.organization_id=? ORDER BY i.name,i.id LIMIT 3000',[$org])->fetchAll();
        $classes=$this->divisionClasses($this->divisionId($org));
        $names=[];foreach($classes as $cl)$names[(string)($cl['code']??'')]=(string)($cl['name']??$cl['code']??'');
        $mappings=$this->divisionItemUacsConfig($this->divisionId($org))['mappings'];
        $itemClass=[];
        foreach($mappings as $mapping){
            $id=(int)($mapping['item_id']??0);
            if($id>0 && !empty($mapping['classification_code']))
                $itemClass[$id]=['code'=>(string)$mapping['classification_code'],
                    'name'=>(string)($mapping['classification_name']??$mapping['classification_code'])];
        }
        $items=[];$facets=[];$matchedItemIds=[];
        foreach($itemRows as $row){
            $iid=(int)$row['id'];$code=(string)($itemClass[$iid]['code']??($row['subcategory']?:$row['category']));
            $label=(string)($names[$code]??($itemClass[$iid]['name']??$code));
            $items[$iid]=['id'=>$iid,'name'=>$row['name'],'category'=>$row['category'],
                'classification_code'=>$code,'classification_name'=>$label];
            $facets[$code]=['code'=>$code,'name'=>$label];
            if($classification==='ALL'||$classification===$code)$matchedItemIds[]=$iid;
        }
        if($classification!=='ALL')
            demand(isset($facets[$classification]),'Classification is no longer registered at this school',422);
        $where=['a.organization_id=?'];$args=[$org];
        if($term!==''){
            $where[]="(a.property_tag LIKE ? OR i.name LIKE ? OR COALESCE(a.serial_number,'') LIKE ? OR COALESCE(a.location,'') LIKE ? OR COALESCE(a.custodian,'') LIKE ? OR COALESCE(a.condition_note,'') LIKE ?)";
            $like='%'.addcslashes($term,'%_\\').'%';
            for($n=0;$n<6;$n++)$args[]=$like;
        }
        if($category!=='ALL'){$where[]='i.category=?';$args[]=$category;}
        if($status==='ALL')$where[]="a.status<>'TRANSFERRED'";
        else {$where[]='a.status=?';$args[]=$status;}
        if($source!=='ALL')$where[]=$source==='VERIFIED_IAR'?'a.iar_line_id IS NOT NULL':($source==='TRANSFER_IN'?"COALESCE(JSON_CONTAINS_PATH(a.property_profile,'one','$.transfer_origin'),0)=1":($source==='HISTORICAL_OPENING'?"a.iar_line_id IS NULL AND COALESCE(JSON_CONTAINS_PATH(a.property_profile,'one','$.transfer_origin'),0)=0":'1=0'));
        if($custodian!=='ALL'){
            $where[]=$custodian==='UNASSIGNED'?'(a.custodian IS NULL OR a.custodian=\'\')':'a.custodian=?';
            if($custodian!=='UNASSIGNED')$args[]=$custodian;
        }
        if($itemFilter!=='ALL'){$where[]='a.item_id=?';$args[]=(int)$itemFilter;}
        if($classification!=='ALL'){
            demand(count($matchedItemIds)<=500,'Classification contains too many items to filter; refine the item catalog',422);
            if(!$matchedItemIds)$where[]='1=0';
            else{$where[]='a.item_id IN ('.implode(',',array_fill(0,count($matchedItemIds),'?')).')';$args=array_merge($args,$matchedItemIds);}
        }
        if($before!==null&&$before!==''){$where[]='a.id<?';$args[]=(int)$before;}
        $sql='SELECT a.id,a.item_id,a.property_tag,a.serial_number,a.acquisition_cost,a.acquired_on,a.opening_record,a.iar_line_id,a.registration_batch_key,a.custodian,a.location,a.status,a.condition_note,a.property_profile,i.name AS item_name,i.category,i.subcategory FROM assets a JOIN item_catalog i ON i.id=a.item_id WHERE '.implode(' AND ',$where).' ORDER BY a.id DESC LIMIT 26';
        $rows=q($this->db,$sql,$args)->fetchAll();
        $hasMore=count($rows)>25;$rows=array_slice($rows,0,25);
        foreach($rows as &$row){$cl=$items[(int)$row['item_id']]??null;$row['classification_code']=$cl['classification_code']??($row['subcategory']?:$row['category']);$row['classification_name']=$cl['classification_name']??$row['classification_code'];
            $row['source']=isset(PropertyBatchMetadata::decode($row['property_profile']??null)['transfer_origin'])?'TRANSFER_IN':($row['iar_line_id']===null?'HISTORICAL_OPENING':'VERIFIED_IAR');
            $row['review_state']=PropertyBatchMetadata::reviewState($row['property_profile']??null);
            $life=one($this->db,'SELECT snapshot_json FROM document_versions WHERE organization_id=? AND doc_type=? AND entity_id=? ORDER BY version_no DESC LIMIT 1',[$org,'ASSET_LIFECYCLE',(int)$row['id']]);
            $row['lifecycle_state']='SERVICEABLE';
            if($life!==null){
                $decision=json_decode((string)$life['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
                $row['lifecycle_state']=is_array($decision)?(string)($decision['state']??'REVIEW_REQUIRED'):'REVIEW_REQUIRED';
            }
            unset($row['property_profile']);
        }unset($row);
        $custodians=q($this->db,"SELECT DISTINCT custodian FROM assets WHERE organization_id=? AND custodian IS NOT NULL AND custodian<>'' ORDER BY custodian LIMIT 250",[$org])->fetchAll(PDO::FETCH_COLUMN);
        $statuses=q($this->db,'SELECT DISTINCT status FROM assets WHERE organization_id=? ORDER BY status LIMIT 20',[$org])->fetchAll(PDO::FETCH_COLUMN);
        usort($facets,static fn($a,$b)=>strcmp($a['name'],$b['name']));
        return ['rows'=>$rows,'has_more'=>$hasMore,'next_before_id'=>$hasMore?(int)end($rows)['id']:null,
            'facets'=>['items'=>array_values($items),'classifications'=>array_values($facets),'custodians'=>$custodians,'statuses'=>$statuses]];
    }

    private static function propertyRegistrationVersion(array $asset): string {
        // Snapshot covers every editable field plus official custody/status locks.
        return hash('sha256',json_encode([
            $asset['id'],$asset['organization_id'],$asset['property_tag'],
            $asset['registration_batch_key']??null,$asset['property_profile']??null,
            $asset['serial_number']??null,$asset['location']??null,
            $asset['condition_note']??null,$asset['custodian']??null,
            $asset['status']??null,$asset['iar_line_id']??null
        ],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }
    /** Edit preview: owner and authorized school writer only. No viewer or Division school write. */
    public function propertyRegistrationDetail(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        $asset=one($this->db,'SELECT a.* ,i.name AS item_name,i.category FROM assets a JOIN item_catalog i ON i.id=a.item_id WHERE a.id=? AND a.organization_id=?',
            [idOf($d,'asset_id'),$org]);
        demand($asset!==null,'Asset not found for this school',404);
        $key=(string)($asset['registration_batch_key']??'');
        $siblings=$key!==''?q($this->db,'SELECT * FROM assets WHERE organization_id=? AND registration_batch_key=? ORDER BY id',[$org,$key])->fetchAll():[];
        $versions=$siblings?array_map(static fn($row)=>['id'=>(int)$row['id'],'version'=>self::propertyRegistrationVersion($row)],$siblings):
            [['id'=>(int)$asset['id'],'version'=>self::propertyRegistrationVersion($asset)]];
        $batchRaw=$siblings;
        $siblings=array_map(static fn($row)=>['id'=>(int)$row['id'],'property_tag'=>$row['property_tag'],'serial_number'=>$row['serial_number']],$siblings);
        $specificClass=(string)($asset['subcategory']?:$asset['category']);
        $classLabel=$specificClass;
        $allMappings=$this->divisionItemUacsConfig($this->divisionId($org))['mappings'];
        foreach($allMappings as $entry){
            if((int)($entry['item_id']??0)===(int)$asset['item_id'] && !empty($entry['classification_code'])){
                $specificClass=(string)$entry['classification_code'];
                $classLabel=(string)($entry['classification_name']??$specificClass);
            }
        }
        foreach($this->divisionClasses($this->divisionId($org)) as $cl)
            if((string)($cl['code']??'')===$specificClass)$classLabel=(string)($cl['name']??$specificClass);
        $details=[
            'id'=>(int)$asset['id'],'property_tag'=>$asset['property_tag'],
            'item_id'=>(int)$asset['item_id'],
            'item_name'=>$asset['item_name'],'category'=>$asset['category'],
            'transfer_origin'=>PropertyBatchMetadata::decode($asset['property_profile']??null)['transfer_origin']??null,
            'classification_code'=>$specificClass,'classification_name'=>$classLabel,
            'status'=>$asset['status'],'review_state'=>PropertyBatchMetadata::reviewState($asset['property_profile']??null),'acquired_on'=>$asset['acquired_on'],
            'acquisition_cost'=>$asset['acquisition_cost'],'opening_record'=>$asset['opening_record'],
            'custodian'=>$asset['custodian'],'location'=>$asset['location'],
            'serial_number'=>$asset['serial_number'],'condition_note'=>$asset['condition_note'],
            'iar_line_id'=>$asset['iar_line_id'],
            'registration_batch_key'=>$asset['registration_batch_key'],
            'shared'=>PropertyBatchMetadata::decode($asset['property_profile']??null)['shared'],
            'unit'=>PropertyBatchMetadata::decode($asset['property_profile']??null)['unit']
        ];
        $batchData=[];
        foreach(($batchRaw?:[$asset]) as $member){
            $meta=PropertyBatchMetadata::decode($member['property_profile']??null);
            $batchData[]=['id'=>(int)$member['id'],'property_tag'=>$member['property_tag'],
                'serial_number'=>$member['serial_number'],'location'=>$member['location'],
                'condition_note'=>$member['condition_note'],'status'=>$member['status'],
                'custodian'=>$member['custodian'],'unit'=>$meta['unit']];
        }
        $movements=q($this->db,'SELECT m.event_type,m.from_custodian,m.to_custodian,m.reason,m.created_at,u.full_name AS actor FROM asset_movements m LEFT JOIN users u ON u.id=m.actor_id WHERE m.asset_id=? ORDER BY m.id DESC LIMIT 30',[(int)$asset['id']])->fetchAll();
        $accountability=q($this->db,'SELECT doc_type,display_number,custodian,status,created_at FROM accountability_documents WHERE organization_id=? AND asset_id=? ORDER BY id DESC LIMIT 30',[$org,(int)$asset['id']])->fetchAll();
        $history=q($this->db,"SELECT e.event_type,e.details,e.created_at,u.full_name AS actor FROM audit_events e LEFT JOIN users u ON u.id=e.actor_id WHERE e.organization_id=? AND e.entity_type='ASSET' AND e.entity_id=? ORDER BY e.id DESC LIMIT 30",[$org,(int)$asset['id']])->fetchAll();
        foreach($history as &$entry){$parsed=json_decode((string)($entry['details']??''),true);$entry['details']=is_array($parsed)?$parsed:[];}unset($entry);
        $iarRef=null;
        if($asset['iar_line_id']!==null){
            $iarRef=one($this->db,'SELECT ir.display_number,ir.status,ir.accepted_at FROM iar_lines l JOIN iars ir ON ir.id=l.iar_id AND ir.organization_id=? WHERE l.id=?',[$org,$asset['iar_line_id']]);
        }
        return ['asset_id'=>(int)$asset['id'],'property_number'=>$asset['property_tag'],
            'item_id'=>(int)$asset['item_id'],
            'item_name'=>$asset['item_name'],'source'=>isset(PropertyBatchMetadata::decode($asset['property_profile']??null)['transfer_origin'])?'TRANSFER_IN':($asset['iar_line_id']?'VERIFIED_IAR':'HISTORICAL_OPENING'),
            'batch_key'=>$key,'batch_units'=>$siblings,'versions'=>$versions,
            'shared'=>PropertyBatchMetadata::decode($asset['property_profile']??null)['shared'],
            'unit'=>array_merge(PropertyBatchMetadata::decode($asset['property_profile']??null)['unit'],[
                'serial_number'=>$asset['serial_number']??'','location'=>$asset['location']??'',
                'condition_note'=>$asset['condition_note']??'']),
            'details'=>$details,'batch_details'=>$batchData,'movements'=>$movements,
            'accountability'=>$accountability,'history'=>$history,'iar_reference'=>$iarRef,
            'review_state'=>PropertyBatchMetadata::reviewState($asset['property_profile']??null),
            'issued'=>one($this->db,"SELECT 1 FROM accountability_documents WHERE organization_id=? AND asset_id=? LIMIT 1",[$org,$asset['id']])!==null];
    }
    /** Audited descriptive correction. Never edits property number, owner, cost, status, or custody. */
    public function correctPropertyRegistration(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org);
        // The signed-in operator must attest physical inspection to release an
        // automatically registered IAR unit. A separate free-text note is optional
        // for that verification, but still mandatory for an ordinary correction.
        $physicalReview=($d['confirm_registration']??false)===true;
        if($physicalReview){
            $reason=PropertyBatchMetadata::text($d['reason']??'',
                'Optional physical verification note',500);
        }else{
            $reason=requiredString($d,'reason',500);
            demand(mbSafeLen($reason)>=12,
                'Give a meaningful property correction reason (12+ characters)',422);
        }
        $mode=requiredString($d,'mode',10);
        demand(in_array($mode,['UNIT','BATCH'],true),'Choose one unit or its exact registration batch',422);
        $profileEdit=($d['profile_edit']??false)===true;
        if($profileEdit)demand(($d['confirm_registration']??false)!==true,'Newly received property review must use its separate verification workflow',409);
        return $this->tx(function()use($org,$d,$reason,$mode,$profileEdit){
            // Lock the school parent row before opening asset rows, same order as batch creation.
            $school=one($this->db,'SELECT id FROM organizations WHERE id=? AND active=1 FOR UPDATE',[$org]);
            demand($school!==null,'Organization unavailable',403);
            $asset=one($this->db,'SELECT * FROM assets WHERE id=? AND organization_id=? FOR UPDATE',[idOf($d,'asset_id'),$org]);
            demand($asset!==null,'Asset not found',404);
            $key=(string)($asset['registration_batch_key']??'');
            $members=$mode==='BATCH'&&$key!==''?
                q($this->db,'SELECT * FROM assets WHERE organization_id=? AND registration_batch_key=? ORDER BY id FOR UPDATE',[$org,$key])->fetchAll():[$asset];
            if($mode==='BATCH')demand($key!=='' && count($members)>0,'Batch editing unavailable for legacy registration; use unit correction',409);
            $actualIds=array_map(static fn($a)=>(int)$a['id'],$members);
            $expected=$d['expected_asset_ids']??null;
            demand(is_array($expected)&&array_is_list($expected)&&array_map('intval',$expected)===$actualIds,
                'The batch changed; reload and review the exact affected units',409);
            $snapshots=$d['expected_versions']??null;
            demand(is_array($snapshots)&&array_is_list($snapshots)&&count($snapshots)===count($members),
                'Reopen the property record and review the latest details before correction',409);
            foreach($members as $index=>$member){
                demand(is_array($snapshots[$index]) && (int)($snapshots[$index]['id']??0)===(int)$member['id'] &&
                    is_string($snapshots[$index]['version']??null) &&
                    hash_equals(self::propertyRegistrationVersion($member),$snapshots[$index]['version']),
                    'Property changed since your preview; reload before editing',409);
            }
            foreach($members as $member){
                demand($member['status']==='ACTIVE','Disposed or inactive property cannot be edited',409);
                if($profileEdit){
                    demand(PropertyBatchMetadata::reviewState($member['property_profile']??null)!=='PENDING_REVIEW',
                        'Finish the newly received property review before editing its profile',409);
                    $life=one($this->db,'SELECT snapshot_json FROM document_versions WHERE organization_id=? AND doc_type=? AND entity_id=? ORDER BY version_no DESC LIMIT 1',
                        [$org,'ASSET_LIFECYCLE',(int)$member['id']]);
                    if($life!==null){
                        $state=json_decode((string)$life['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
                        demand(is_array($state)&&($state['state']??null)==='SERVICEABLE',
                            'Property has an active condition or disposal process; edit through that workflow',409);
                    }
                }else{
                    demand(one($this->db,"SELECT 1 FROM accountability_documents WHERE organization_id=? AND asset_id=? LIMIT 1",[$org,$member['id']])===null,
                        'This property has issued ICS/PAR accountability; use the audited Asset Register editor',409);
                }
            }
            $changes=[];
            foreach($members as $member){
                $before=PropertyBatchMetadata::decode($member['property_profile']??null);
                $after=$before;
                if($profileEdit){
                    $origin=($member['iar_line_id']??null)!==null?'VERIFIED_IAR':'HISTORICAL_OPENING';
                    demand(is_array($d['shared']??null),'Provide descriptive and acquisition reference fields',422);
                    $after['shared']=PropertyBatchMetadata::shared($d['shared'],$origin);
                    if($mode==='UNIT'){
                        demand(is_array($d['unit']??null),'Provide individual property details',422);
                        $unit=PropertyBatchMetadata::unit($d['unit']);
                        if($unit['serial_number']!=='')demand(one($this->db,
                            'SELECT id FROM assets WHERE organization_id=? AND serial_number=? AND id<>? LIMIT 1',
                            [$org,$unit['serial_number'],$member['id']])===null,
                            'Serial number is already registered to another unit',409);
                        $after['unit']=['legacy_property_number'=>$unit['legacy_property_number'],
                            'reported_custodian'=>$unit['reported_custodian']];
                    }
                    $issued=one($this->db,
                        'SELECT 1 FROM accountability_documents WHERE organization_id=? AND asset_id=? LIMIT 1',
                        [$org,$member['id']])!==null;
                    if($issued){
                        // Existing finalized ICS/PAR print paths read the current useful-life and
                        // source-item details. These are immutable after an accountable issuance.
                        $originalShared=PropertyBatchMetadata::shared($before['shared']??[],$origin);
                        foreach($after['shared'] as $attr=>$value){
                            if(!in_array($attr,['brand','model','evidence_note'],true))
                                demand($value===$originalShared[$attr],
                                    'Issued property: acquisition type, useful life, origin and source references are locked',409);
                        }
                        if($mode==='UNIT')demand($after['unit']['reported_custodian']===($before['unit']['reported_custodian']??''),
                            'Issued property: historical custodian cannot be rewritten; use the custody history',409);
                    }
                }elseif($mode==='BATCH'){
                    demand(is_array($d['shared']??null),'Provide shared property details',422);
                    $origin=($member['iar_line_id']??null)!==null?'VERIFIED_IAR':'HISTORICAL_OPENING';
                    $after['shared']=PropertyBatchMetadata::shared($d['shared'],$origin);
                } else {
                    demand(is_array($d['unit']??null),'Provide individual property details',422);
                    $unit=PropertyBatchMetadata::unit($d['unit']);
                    if($unit['serial_number']!=='')demand(one($this->db,
                        'SELECT id FROM assets WHERE organization_id=? AND serial_number=? AND id<>? LIMIT 1',
                        [$org,$unit['serial_number'],$member['id']])===null,
                        'Serial number is already registered to another unit',409);
                    $after['unit']=['legacy_property_number'=>$unit['legacy_property_number'],
                        'reported_custodian'=>$unit['reported_custodian']];
                }
                // Existing historical reported-custodian metadata is preserved as evidence,
                // but property review/profile edits may never rewrite it or make it custody.
                if($mode==='UNIT')
                    demand(($after['unit']['reported_custodian']??'')===($before['unit']['reported_custodian']??''),
                        'Custodian references are not editable here; use ICS/PAR or Property Transfers',409);
                if(($d['confirm_registration']??false)===true){
                    demand($mode==='UNIT' && PropertyBatchMetadata::reviewState($member['property_profile']??null)==='PENDING_REVIEW',
                        'Only a pending automatically received unit can be confirmed here',409);
                    demand($unit['location']!=='' && $unit['condition_note']!=='',
                        'Confirm the physical location and condition before enabling ICS/PAR',422);
                    // One-step physical verification may capture brand/model as
                    // well as per-unit serial, location and condition. Existing
                    // authoritative cost, IAR source and tags remain immutable.
                    demand(is_array($d['shared']??null),'Confirm the known brand/model information for this physical unit',422);
                    $after['shared']=PropertyBatchMetadata::shared($d['shared'],'VERIFIED_IAR');
                    $after['review']=['source'=>'IAR_AUTO','state'=>'REVIEWED',
                        'reviewed_by'=>(int)$this->user['id'],
                        'reviewed_at'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format(DATE_ATOM)];
                }
                $previous=['profile'=>$before,'serial_number'=>$member['serial_number'],
                    'location'=>$member['location'],'condition_note'=>$member['condition_note']];
                $updated=['profile'=>$after,'serial_number'=>$mode==='UNIT'?$unit['serial_number']:$member['serial_number'],
                    'location'=>$mode==='UNIT'?$unit['location']:$member['location'],
                    'condition_note'=>$mode==='UNIT'?$unit['condition_note']:$member['condition_note']];
                if($previous===$updated)continue;
                if(trim((string)$previous['condition_note'])!==trim((string)$updated['condition_note'])){
                    demand(!preg_match('/\b(unserviceable|for disposal|disposed)\b/i',(string)$updated['condition_note']),
                        'Use the Property Condition & Disposal workflow for unserviceable and disposal declarations',409);
                    // The UI condition dropdown is backed by the same server-side whitelist.
                    // Legacy free-form observations may remain unchanged when correcting other fields,
                    // but a new condition value cannot bypass the formal lifecycle workflow.
                    demand(in_array($updated['condition_note'],
                        ['Serviceable','Needs Maintenance','For Inspection','For Repair','Under Repair',''],true),
                        'Select a valid property condition from the dropdown',422);
                }
                q($this->db,'UPDATE assets SET property_profile=?,serial_number=?,location=?,condition_note=? WHERE id=? AND organization_id=?',
                    [json_encode($after,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$updated['serial_number'],
                        $updated['location'],$updated['condition_note'],$member['id'],$org]);
                // A blank optional review note is distinguishable from a user-entered
                // explanation. The checkbox, reviewer, review timestamp and the
                // complete before/after values remain in the immutable audit.
                $auditReason=$reason!==''?$reason:
                    'Physical verification checkbox confirmed; optional note left blank';
                $this->audit($org,'PROPERTY_DETAILS_CORRECTED','ASSET',(int)$member['id'],[
                    'mode'=>$mode,'registration_batch_key'=>$key,
                    'reason'=>$auditReason,
                    'reason_source'=>$reason===''?'SYSTEM_REVIEW_NOTE':'USER_ENTERED',
                    'before'=>$previous,'after'=>$updated]);
                $changes[]=(int)$member['id'];
            }
            return ['corrected_asset_ids'=>$changes,'mode'=>$mode,'batch_key'=>$key,
                'note'=>'No property number, IAR, custody, DV, cost, stock or accounting movement was changed'];
        });
    }
    public function moveAsset(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org);return $this->tx(function()use($org,$d){
        $asset=one($this->db,'SELECT * FROM assets WHERE id=? AND organization_id=? FOR UPDATE',[idOf($d,'asset_id'),$org]);demand($asset!==null&&$asset['status']!=='DISPOSED','Active asset required');$kind=requiredString($d,'event_type',30);demand(in_array($kind,['ISSUE','TRANSFER','RETURN','REPAIR','COUNT','DISPOSAL'],true),'Invalid asset movement');demand(!in_array($kind,['TRANSFER','ISSUE','RETURN','DISPOSAL'],true),'Use the documented Property Return, Property Transfer, ICS/PAR issuance, or approved Property Condition & Disposal workflow; direct custody or disposal movement is blocked',409);demand(PropertyBatchMetadata::reviewState($asset['property_profile']??null)!=='PENDING_REVIEW','Newly received property must be reviewed before recording any custody or status movement',409);$to=trim((string)($d['to_custodian']??''));demand($kind==='DISPOSAL'||$to===''||$to===(string)($asset['custodian']??''),
            'Changing accountable employees requires a documented ICS/PAR or Property Transfer',409);$id=$this->insert('asset_movements',['asset_id'=>$asset['id'],'event_type'=>$kind,'from_custodian'=>$asset['custodian'],'to_custodian'=>$to,'reason'=>requiredString($d,'reason',500),'actor_id'=>$this->user['id']]);q($this->db,'UPDATE assets SET custodian=?,status=? WHERE id=?',[$kind==='DISPOSAL'?null:($to?:$asset['custodian']),$kind==='DISPOSAL'?'DISPOSED':'ACTIVE',$asset['id']]);$this->audit($org,$kind,'ASSET',(int)$asset['id']);return ['id'=>$id,'asset_id'=>$asset['id']];
    });}
    public function textbookOpening(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org);return $this->tx(function()use($org,$d){
        $title=$this->insert('textbook_titles',['title'=>requiredString($d,'title',255),'isbn'=>trim((string)($d['isbn']??'')),'grade'=>trim((string)($d['grade']??'')),'subject'=>trim((string)($d['subject']??''))]);$batch=$this->insert('textbook_batches',['organization_id'=>$org,'title_id'=>$title,'batch'=>requiredString($d,'batch',120),'condition_note'=>trim((string)($d['condition_note']??'')),'storage'=>trim((string)($d['storage']??'')),'opening_record'=>1]);$rawQty=$d['quantity']??null;demand((is_int($rawQty)||is_string($rawQty)) && preg_match('/^[1-9][0-9]{0,8}$/',(string)$rawQty)===1,'Quantity must be a positive whole number');$qty=(int)$rawQty;$this->insert('textbook_movements',['organization_id'=>$org,'batch_id'=>$batch,'quantity_delta'=>$qty,'event_type'=>'OPENING','reference'=>'Opening balance; original source unknown','actor_id'=>$this->user['id']]);$this->audit($org,'OPENING_RECORDED','TEXTBOOK',$batch);return ['batch_id'=>$batch];
    });}
    public function textbookMove(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org);return $this->tx(function()use($org,$d){
        $batch=one($this->db,'SELECT * FROM textbook_batches WHERE id=? AND organization_id=? FOR UPDATE',[idOf($d,'batch_id'),$org]);demand($batch!==null,'Textbook batch unavailable');$kind=requiredString($d,'event_type',30);$kinds=['RECEIPT'=>1,'RETURN'=>1,'DISTRIBUTION'=>-1,'TRANSFER'=>-1,'LOST'=>-1,'DISPOSAL'=>-1,'COUNT_ADJUSTMENT'=>0];demand(array_key_exists($kind,$kinds),'Unsupported textbook movement');$rawQty=$d['quantity']??null;demand((is_int($rawQty)||is_string($rawQty)) && preg_match('/^[1-9][0-9]{0,8}$/',(string)$rawQty)===1,'Movement quantity must be a positive whole number');$qty=(int)$rawQty;if($kind==='COUNT_ADJUSTMENT')demand(in_array($d['direction']??null,['IN','OUT'],true),'Select IN or OUT for textbook count adjustment');$delta=$qty*($kinds[$kind]===0?($d['direction']==='IN'?1:-1):$kinds[$kind]);$sum=(int)one($this->db,'SELECT COALESCE(SUM(quantity_delta),0) n FROM textbook_movements WHERE batch_id=?',[$batch['id']])['n'];demand($sum+$delta>=0,'Insufficient textbook stock');$id=$this->insert('textbook_movements',['organization_id'=>$org,'batch_id'=>$batch['id'],'quantity_delta'=>$delta,'event_type'=>$kind,'reference'=>requiredString($d,'reference',200),'actor_id'=>$this->user['id']]);$this->audit($org,$kind,'TEXTBOOK',(int)$batch['id']);return ['id'=>$id,'on_hand'=>$sum+$delta];
    });}
    public function search(array $d): array {$org=$this->org($d);$term=requiredString($d,'term',100);$needle='%'.str_replace(['%','_'],['\\%','\\_'],$term).'%';
        $data=[];foreach(['purchase_requests'=>'PR','purchase_orders'=>'PO','iars'=>'IAR','disbursement_vouchers'=>'DV','requisition_issue_slips'=>'RIS'] as $table=>$type){$rows=q($this->db,"SELECT id,display_number,status FROM $table WHERE organization_id=? AND display_number LIKE ? LIMIT 30",[$org,$needle])->fetchAll();foreach($rows as $r)$data[]=['type'=>$type]+$r;}
        return $data;
    }
    /** Accessible, draft-only cancellation entry point. Reuse the existing
     * serialized DV cancellation/audit path, but enforce draft status and the
     * exact operator-reviewed gross amount within the locked transaction.
     */
    public function cancelDraftDV(array $d): array {
        $d['doc_type']='DV';
        return $this->cancelDocument($d,true);
    }
    public function cancelDocument(array $d,bool $draftOnly=false): array {$org=$this->org($d);$this->operationalWriteAllowed($org);return $this->financialTx('document.cancel',$d,function()use($org,$d,$draftOnly){
        $type=requiredString($d,'doc_type',10);$table=['PR'=>'purchase_requests','PO'=>'purchase_orders','DV'=>'disbursement_vouchers','IAR'=>'iars'][$type]??null;demand($table!==null,'Unsupported cancellation type');
        // Cancellation releases a FINALIZED DV's fund claim. Serialize it with
        // new DV finalizations and finance ledger postings, fund row FIRST.
        if($type==='DV'){
            $candidate=one($this->db,'SELECT fund_id FROM disbursement_vouchers WHERE id=? AND organization_id=?',[idOf($d,'id'),$org]);
            demand($candidate!==null,'Voucher not found in authorized organization',404);
            $this->record('funds',(int)$candidate['fund_id'],$org,true);
        }
        $doc=$this->record($table,idOf($d,'id'),$org,true);
        // Finalized DVs remain accountable issued documents even when voided.
        // Never let a stale review, generic document.cancel caller or a
        // client-supplied status erase the issued voucher number or bypass the
        // separate linked-check/tax guards below. Finalization assigns the
        // physical leaf atomically; voiding must preserve its accountability.
        if($type==='DV' && !$draftOnly){
            demand($doc['status']==='FINALIZED' && !empty($doc['display_number']),
                'Only a numbered finalized DV may be voided here; use the draft cancellation action for drafts.',409);
            demand(($d['expected_status']??null)==='FINALIZED' &&
                hash_equals((string)$doc['display_number'],requiredString($d,'expected_number',90)),
                'This accountable DV number changed since review; reopen and confirm the issued number.',409);
            demand(moneyCents(requiredString($d,'expected_gross',30))===moneyCents((string)$doc['gross']),
                'The DV gross amount changed since your review. Reopen the DV before voiding.',409);
            demand(mbSafeLen(trim((string)($d['reason']??'')))>=12,
                'State the documentary reason for voiding this issued DV (at least 12 characters).',409);
        }
        if($draftOnly){
            demand($type==='DV','Draft cancellation may only target a DV',403);
            demand($doc['status']==='DRAFT','Only an unfinalized draft DV can be cancelled here. Refresh the voucher before proceeding',409);
            $expectedGross=requiredString($d,'expected_gross',30);
            demand(moneyCents($expectedGross)===moneyCents((string)$doc['gross']),
                'Voucher gross changed since review. Reopen the DV and confirm its details',409);
        }
        demand($doc['status']!=='CANCELLED','Already cancelled');
        $taxTransfer=$type==='DV'?$this->taxTransferRecord($org,(int)$doc['id'],true):null;
        if($taxTransfer!==null && !$draftOnly)
            demand(false,'A numbered tax-transfer DV requires a Division-approved correction/return and check reconciliation; generic void is blocked.',409);
        $reason=requiredString($d,'reason',500);
        if($draftOnly)demand(mbSafeLen($reason)>=12,'Explain why this draft must be cancelled',409);
        $down=['PR'=>['purchase_orders'=>'pr_id','rfqs'=>'pr_id','disbursement_vouchers'=>'XXX'],'PO'=>['iars'=>'po_id'],'IAR'=>['dv_allocation_lines'=>'XXX'],'DV'=>['checks'=>'dv_id']];
        if($type==='PR'){
            demand(one($this->db,'SELECT 1 FROM rfqs WHERE pr_id=? LIMIT 1',[$doc['id']])===null,'Cannot cancel PR with RFQ');demand(one($this->db,'SELECT 1 FROM dv_allocation_lines WHERE pr_id=? LIMIT 1',[$doc['id']])===null,'Cannot cancel linked PR');
        }elseif($type==='PO')demand(one($this->db,'SELECT 1 FROM iars WHERE po_id=? LIMIT 1',[$doc['id']])===null,'Cannot cancel PO with IAR');
        elseif($type==='IAR'){
            // Hold every source lot until cancellation commits. RIS issuance uses
            // the same lot mutex, so its movement cannot slip past these checks.
            q($this->db,'SELECT t.id FROM inventory_lots t JOIN iar_lines l ON l.id=t.iar_line_id WHERE l.iar_id=? ORDER BY t.id FOR UPDATE',[$doc['id']])->fetchAll();
            demand(one($this->db,'SELECT 1 FROM assets a JOIN iar_lines l ON l.id=a.iar_line_id WHERE l.iar_id=? LIMIT 1',[$doc['id']])===null,
                'IAR has registered accountable property; controlled property reconciliation is required before cancellation',409);
            demand(one($this->db,'SELECT 1 FROM iar_lines l JOIN dv_allocation_lines a ON a.iar_line_id=l.id WHERE l.iar_id=? LIMIT 1',[$doc['id']])===null && one($this->db,'SELECT 1 FROM iar_lines l JOIN inventory_lots t ON t.iar_line_id=l.id JOIN stock_events e ON e.lot_id=t.id AND e.event_type!=\'RECEIPT\' WHERE l.iar_id=? LIMIT 1',[$doc['id']])===null,'IAR requires downstream stock and financial reversals first');
        }
        elseif($type==='DV'){
            demand(one($this->db,"SELECT 1 FROM withholding_components w JOIN tax_disposition_events e ON e.component_id=w.id AND e.state NOT IN ('PENDING') WHERE w.dv_id=? LIMIT 1",[$doc['id']])===null,
                'A completed tax disposition requires formal reconciliation; direct DV void is blocked.',409);
            $linked=q($this->db,'SELECT * FROM checks WHERE dv_id=? ORDER BY id FOR UPDATE',[$doc['id']])->fetchAll(PDO::FETCH_ASSOC);
            if($linked){
                demand(!$draftOnly && count($linked)===1,
                    'More than one historical check is attached to this DV. Use a finance-approved reconciliation rather than direct void.',409);
                $attached=$linked[0];
                demand($attached['status']==='ISSUED',
                    'Only an assigned, unsigned, unreleased check can be voided with the DV here. Signed, released, replaced or cleared checks require formal review.',409);
                demand(($d['linked_check_condition']??null)==='UNUSABLE' && ($d['confirmed_physical_leaf']??false)===true,
                    'State the actual check condition. An unusable physical check can be cancelled with the DV after confirmation; a still-usable check requires a separate audited recovery path.',409);
                $leaf=one($this->db,'SELECT * FROM checkbook_leaves WHERE organization_id=? AND bank_account_id=? AND serial=? FOR UPDATE',
                    [$org,$attached['bank_account_id'],$attached['check_number']]);
                demand($leaf!==null && $leaf['status']==='ISSUED',
                    'Physical check custody record does not match the recorded check. Stop and reconcile the checkbook.',409);
                $affected=q($this->db,"UPDATE checkbook_leaves SET status='SPOILED' WHERE id=? AND status='ISSUED'",[$leaf['id']]);
                demand($affected->rowCount()===1,'Concurrent change to physical check stock. Reopen the DV.',409);
                q($this->db,"UPDATE checks SET status='CANCELLED' WHERE id=? AND status='ISSUED'",[$attached['id']]);
                $this->insert('payment_events',['check_id'=>$attached['id'],'event_type'=>'CANCELLED',
                    'evidence_reference'=>substr('Unusable check DV '.$doc['display_number'].': '.requiredString($d,'reason',500),0,190),
                    'created_by'=>$this->user['id']]);
                $this->audit($org,'CHECK_SPOILED_WITH_DV_VOID','CHECK',(int)$attached['id'],
                    ['dv_id'=>(int)$doc['id'],'leaf_id'=>(int)$leaf['id'],'serial'=>$attached['check_number'],
                        'reason'=>$d['reason'],'physically_verified'=>true]);
            }elseif(isset($d['linked_check_condition']) && $d['linked_check_condition']!=='NONE'){
                demand(false,'Voucher has no recorded physical check. Refresh the DV void review.',409);
            }
        }
        if($type==='IAR'){
            demand(one($this->db,'SELECT 1 FROM iar_lines l JOIN advances a ON a.iar_line_id=l.id WHERE l.iar_id=? LIMIT 1',[$doc['id']])===null,'IAR with advance proof cannot be cancelled without advance reversal');
            foreach(q($this->db,'SELECT t.id,t.iar_line_id FROM inventory_lots t JOIN iar_lines l ON l.id=t.iar_line_id WHERE l.iar_id=?',[$doc['id']])->fetchAll() as $lot){$qty=one($this->db,'SELECT COALESCE(SUM(quantity_delta),0) n FROM stock_events WHERE lot_id=?',[$lot['id']]);$millis=qtyMillis((string)$qty['n']);if($millis>0)$this->insert('stock_events',['organization_id'=>$org,'lot_id'=>$lot['id'],'event_type'=>'DISPOSAL','quantity_delta'=>'-'.qtyString($millis),'source_type'=>'IAR_CANCEL','source_id'=>$lot['iar_line_id'],'created_by'=>$this->user['id']]);}
        }
        if($type==='DV'){
            foreach(q($this->db,"SELECT w.* FROM withholding_components w WHERE w.dv_id=? AND w.disposition='SCHOOL_UTILIZATION'",[$doc['id']])->fetchAll() as $tax){$this->ledger($org,(int)$doc['fund_id'],'PENDING_TAX_REVERSAL',moneyCents($tax['amount']),'TAX',(int)$tax['id'],'DV cancelled');}
        }
        // Draft-only travel cancellation releases current reservations but preserves audit/source history.
        // Numbered or financially active vouchers NEVER release the claim automatically.
        if($type==='DV' && $doc['dv_type']==='TRAVEL_REIMBURSEMENT' && $draftOnly){
            q($this->db,"UPDATE travel_dv_claim_links SET status='DRAFT_CANCELLED', active_claim_id=NULL WHERE organization_id=? AND dv_id=? AND status='ACTIVE' AND active_claim_id IS NOT NULL",[$org,$doc['id']]);
        }
        q($this->db,"UPDATE $table SET status='CANCELLED' WHERE id=?",[$doc['id']]);
        if($taxTransfer!==null){
            $newState=TaxTransferRules::next((string)$taxTransfer['status'],'CANCEL');
            q($this->db,'UPDATE school_tax_transfers SET status=? WHERE id=?',[$newState,$taxTransfer['id']]);
        }
        $this->snapshot($org,$type,(int)$doc['id'],['cancelled'=>true,'original_number'=>$doc['display_number']],$reason);$this->audit($org,'CANCELLED',$type,(int)$doc['id'],['reason'=>$reason,'draft_only_flow'=>$draftOnly]);return ['id'=>(int)$doc['id'],'status'=>'CANCELLED','number'=>$doc['display_number']];
    });}
    /** Read-only labels for DV link history. Never replace persisted link IDs with display numbers. */
    private function dvLinkDisplayLabel(int $org,string $type,int $id): string {
        // The linked ID can refer to a header, a child line or a physical check.
        // Resolve through the parent header in the SAME authorized organization.
        $lineParents=[
            'IAR_LINE'=>['iar_lines','iar_id','iars','IAR'],
            'PR_LINE'=>['pr_lines','pr_id','purchase_requests','PR'],
            'PO_LINE'=>['po_lines','po_id','purchase_orders','PO'],
            'RIS_LINE'=>['ris_lines','ris_id','requisition_issue_slips','RIS'],
            'DV_ALLOCATION_LINE'=>['dv_allocation_lines','dv_id','disbursement_vouchers','DV'],
        ];
        if(isset($lineParents[$type])){
            [$lineTable,$parentField,$parentTable,$parentType]=$lineParents[$type];
            $parent=one($this->db,"SELECT p.display_number,
                    (SELECT COUNT(*) FROM $lineTable seq WHERE seq.$parentField=l.$parentField AND seq.id<=l.id) line_no
                FROM $lineTable l JOIN $parentTable p ON p.id=l.$parentField
                WHERE l.id=? AND p.organization_id=?",[$id,$org]);
            if($parent!==null){
                $number=trim((string)($parent['display_number']??''));
                return $parentType.' line '.(int)$parent['line_no'].' ('.$parentType.' '.
                    ($number!==''?'No. '.$number:'unnumbered draft').')';
            }
            return $parentType.' line (internal reference #'.$id.' — parent unavailable)';
        }
        if($type==='CHECK'){
            $row=one($this->db,'SELECT check_number FROM checks WHERE id=? AND organization_id=?',[$id,$org]);
            if($row!==null && trim((string)$row['check_number'])!=='')
                return 'Check No. '.trim((string)$row['check_number']);
        }
        if($type==='ADVANCE'){
            $row=one($this->db,'SELECT i.display_number FROM advances a
                JOIN iar_lines il ON il.id=a.iar_line_id
                JOIN iars i ON i.id=il.iar_id
                WHERE a.id=? AND a.organization_id=? AND i.organization_id=?',[$id,$org,$org]);
            if($row!==null && trim((string)($row['display_number']??''))!=='')
                return 'Advance (IAR No. '.trim((string)$row['display_number']).')';
        }
        if($type==='ASSET'){
            $row=one($this->db,'SELECT property_tag FROM assets WHERE id=? AND organization_id=?',[$id,$org]);
            if($row!==null && trim((string)$row['property_tag'])!=='')
                return 'Property No. '.trim((string)$row['property_tag']);
        }
        $documentTables=[
            'PR'=>'purchase_requests','RFQ'=>'rfqs','AWARD'=>'awards',
            'PO'=>'purchase_orders','IAR'=>'iars','DV'=>'disbursement_vouchers',
            'RIS'=>'requisition_issue_slips',
        ];
        if(isset($documentTables[$type])){
            $table=$documentTables[$type];
            $row=one($this->db,"SELECT display_number FROM $table
                WHERE organization_id=? AND id=?",[$org,$id]);
            if($row!==null && trim((string)($row['display_number']??''))!=='')
                return $type.' No. '.trim((string)$row['display_number']);
        }elseif(in_array($type,['RC','AR','TA','ICS','PAR','ITR','PTR','RRSP','PRR'],true)){
            // Registry-backed document types (not all have a separate header table).
            $row=one($this->db,'SELECT display_number FROM document_registry
                WHERE organization_id=? AND doc_type=? AND entity_id=?',[$org,$type,$id]);
            if($row!==null && trim((string)($row['display_number']??''))!=='')
                return $type.' No. '.trim((string)$row['display_number']);
        }
        // Drafts and legacy unnumbered links have no official number to invent.
        // Keep an explicitly marked internal fallback so the record remains traceable.
        return $type.' (unnumbered / internal reference #'.$id.')';
    }
    public function detail(array $d): array {$org=$this->org($d);$type=requiredString($d,'type',10);$table=['PR'=>'purchase_requests','PO'=>'purchase_orders','IAR'=>'iars','DV'=>'disbursement_vouchers','RIS'=>'requisition_issue_slips'][$type]??null;demand($table!==null,'Unsupported detail');$rec=$this->record($table,idOf($d,'id'),$org);
        if($type==='RIS' && $rec['status']==='REVERSED' && empty($rec['display_number']))$rec['status']='CANCELLED';
        $links=q($this->db,'SELECT source_type,source_id,target_type,target_id FROM document_links WHERE organization_id=? AND (source_type=? AND source_id=? OR target_type=? AND target_id=?)',[$org,$type,$rec['id'],$type,$rec['id']])->fetchAll();
        $versions=q($this->db,'SELECT version_no,reason,created_at FROM document_versions WHERE organization_id=? AND doc_type=? AND entity_id=? ORDER BY version_no DESC',[$org,$type,$rec['id']])->fetchAll();
        $lines=match($type){'PR'=>q($this->db,'SELECT * FROM pr_lines WHERE pr_id=? ORDER BY id',[$rec['id']])->fetchAll(),'PO'=>q($this->db,'SELECT * FROM po_lines WHERE po_id=?',[$rec['id']])->fetchAll(),'IAR'=>q($this->db,'SELECT * FROM iar_lines WHERE iar_id=?',[$rec['id']])->fetchAll(),'DV'=>q($this->db,'SELECT * FROM dv_allocation_lines WHERE dv_id=?',[$rec['id']])->fetchAll(),'RIS'=>q($this->db,'SELECT * FROM ris_lines WHERE ris_id=?',[$rec['id']])->fetchAll()};
        $manual=q($this->db,'SELECT id,evidence_reference,received_at FROM manual_signature_records WHERE organization_id=? AND doc_type=? AND entity_id=? ORDER BY id DESC',[$org,$type,$rec['id']])->fetchAll();
        $result=['record'=>$rec,'lines'=>$lines,'links'=>$links,'versions'=>$versions,'manual_signature_records'=>$manual];
        if($type==='DV'){
            // Display human-readable references while preserving source_type/id and
            // target_type/id exactly for accounting, audit and link integrity.
            $labels=[];
            foreach($result['links'] as &$link){
                foreach(['source','target'] as $side){
                    $linkType=(string)$link[$side.'_type'];
                    $linkId=(int)$link[$side.'_id'];
                    $key=$linkType.':'.$linkId;
                    if(!array_key_exists($key,$labels))
                        $labels[$key]=$this->dvLinkDisplayLabel($org,$linkType,$linkId);
                    $link[$side.'_label']=$labels[$key];
                }
            }
            unset($link);
            $result['linked_checks']=q($this->db,'SELECT id,check_number,status,bank_account_id,amount,payee_name,payee_basis FROM checks WHERE organization_id=? AND dv_id=? ORDER BY id',[$org,$rec['id']])->fetchAll(PDO::FETCH_ASSOC);
            $result['dv_source_earliest_date']=$this->dvDateSourceEarliest($org,$rec);
            $result['dv_last_accountable_date']=$this->dvLastAccountableDate($org);
            // Show legacy source conflicts without modifying either draft. The
            // finalization path independently rechecks these against the DB.
            $source=null;$sourceId=0;
            if($rec['dv_type']==='REGULAR'){
                $src=one($this->db,'SELECT i.po_id FROM dv_allocation_lines a JOIN iar_lines il ON il.id=a.iar_line_id JOIN iars i ON i.id=il.iar_id WHERE a.dv_id=? LIMIT 1',[$rec['id']]);
                if($src){$source='PO';$sourceId=(int)$src['po_id'];}
            }elseif($rec['dv_type']==='DIRECT' && $lines && $lines[0]['pr_id']!==null){$source='PR';$sourceId=(int)$lines[0]['pr_id'];}
            elseif($rec['dv_type']==='REIMBURSEMENT' && $lines){$source='ADVANCE';$sourceId=(int)$lines[0]['advance_id'];}
            $result['duplicate_source_dvs']=$source!==null ? array_values(array_filter(
                $this->sourceVouchers($org,$source,$sourceId,(int)$rec['id']),static fn($v)=>$v['status']!=='CANCELLED')) : [];
            if($rec['dv_type']==='TRAVEL_REIMBURSEMENT'){
                $result['travel_claims']=q($this->db,'SELECT l.claim_id,r.display_number claim_number,l.claimed_amount,l.preliminary_allowed,l.allocated_amount,l.claim_version,l.review_version,l.status FROM travel_dv_claim_links l JOIN document_registry r ON r.id=l.claim_id AND r.organization_id=l.organization_id WHERE l.organization_id=? AND l.dv_id=? ORDER BY l.claim_id',[$org,$rec['id']])->fetchAll(PDO::FETCH_ASSOC);
                foreach($result['travel_claims'] as &$travelSource){
                    $evidence=ReimbursementFinalization::load($this->db,$org,'T',(int)$travelSource['claim_id']);
                    $travelSource['finalized_reference']=$evidence['reference']??'';
                    $travelSource['itinerary_number']=$evidence['itinerary_number']??'';
                    $travelSource['debit_uacs_code']=$evidence['debit_uacs_code']??'';
                    $travelSource['payment_authority_reference']=$evidence['payment_authority_reference']??'';
                }unset($travelSource);
                $result['other_employee_claims']=EmployeeClaims::dvSources($this->db,$org,(int)$rec['id']);
                $result['reimbursement_finalized_evidence']=$this->employeeReimbursementEvidence($org,(int)$rec['id']);
            }
            $result['withholding']=q($this->db,"SELECT w.*,rv.rate,
                COALESCE(NULLIF(a.tax_kind,''),'UNCLASSIFIED') AS tax_kind
                FROM withholding_components w
                JOIN tax_rate_versions rv ON rv.id=w.rate_version_id
                LEFT JOIN tax_atcs a ON a.code=w.atc_code
                WHERE w.dv_id=? ORDER BY w.id",[$rec['id']])->fetchAll();
            if(trim((string)($rec['particulars']??''))==='')
                $result['suggested_particulars']=$this->dvSuggestedParticulars((int)$rec['id'],(string)$rec['dv_type'],(string)$rec['gross']);
            if($rec['dv_type']==='REGULAR' && $result['withholding']){
                $effective=$this->regularDvTaxPolicy($org,(int)$rec['fund_id']);
                // Only unissued DRAFT vouchers may be compared with today's
                // policy: an issued DV retains its historical disposition even
                // if Division later changes the effective configuration.
                $mismatch=false;
                if($rec['status']==='DRAFT'){
                    $mismatch=!$effective['ready'];
                    foreach($result['withholding'] as $tax)if($tax['disposition']!==$effective['disposition'])$mismatch=true;
                }
                $result['tax_policy']=$effective;
                $result['tax_policy_is_current_not_original']=$rec['status']!=='DRAFT';
                $result['tax_disposition_mismatch']=$mismatch;
                $result['tax_disposition_edit_allowed']=$mismatch && $effective['ready'] &&
                    $rec['status']==='DRAFT' && empty($rec['display_number']);
                if($result['tax_disposition_edit_allowed'])
                    $result['tax_disposition_token']=self::dvDispositionToken((int)$rec['id'],$result['withholding']);
            }
            $transfer=$this->taxTransferRecord($org,(int)$rec['id']);
            $workpaper=$transfer!==null
                ?TaxTransferRules::workpaper((string)$rec['gross'])
                :$this->advanceLiquidationWorkpaper($org,$rec,$result['withholding']);
            if($transfer!==null){
                $result['tax_transfer']=['id'=>(int)$transfer['id'],'status'=>$transfer['status'],
                    'division_bank_account_id'=>$transfer['division_bank_account_id']===null?null:(int)$transfer['division_bank_account_id'],
                    'source_components'=>q($this->db,'SELECT component_id,amount FROM school_tax_transfer_components WHERE transfer_id=? ORDER BY component_id',[$transfer['id']])->fetchAll()];
            }
            $result['advance_liquidation']=$workpaper;
            $manualIssues=$workpaper['applicable']?[]:self::manualAccountingIssues($rec,$result['withholding']);
            // Read-only accounting readiness: do not confuse draft withholding
            // calculations with saved accounting versions or cash/bank posting.
            // Finalization must independently recheck source-fund authority.
            $authority=$this->fundAvailabilityTotals($org,(int)$rec['fund_id']);
            $saved=one($this->db,'SELECT COALESCE(MAX(version_no),0) n FROM dv_accounting_versions WHERE dv_id=?',[$rec['id']]);
            $balance=(string)$authority['available_allocation'];
            $enough=$authority['has_allocation'] && !str_starts_with($balance,'-') &&
                ($transfer!==null || moneyCents($balance)>=moneyCents($rec['gross']));
            // v0.2.346: read-only funding-method preview, deliberately NOT a
            // bank-cash balance or override of finalizeDV(). A real bank-cash
            // mode needs transactional, per-fund cash and separately approved
            // retained-withholding spending authority before activation.
            if($rec['status']==='DRAFT'){
                try {
                    $fundingPolicy=$this->regularDvTaxPolicy($org,(int)$rec['fund_id']);
                    $ordinaryCents=moneyCents(ltrim($balance,'-')) *
                        (str_starts_with($balance,'-')?-1:1);
                    $result['funding_basis_preview']=FundCashFundingPreview::assess(
                        (string)($fundingPolicy['school_classification']??''),
                        (string)$fundingPolicy['mode'],
                        moneyCents($rec['gross']),moneyCents($rec['deductions']),
                        $ordinaryCents
                        // Fund-attributable, uncommitted and independently reconciled
                        // bank cash/retained authority do not exist in this release.
                    );
                    $result['funding_basis_preview']['policy_version_id']=(int)$fundingPolicy['version_id'];
                    if($result['funding_basis_preview']['mode']==='NON_IUS_BANK_CASH_CANDIDATE'){
                        $verified=$this->schoolUtilizableFundingMode($org,(int)$rec['fund_id']);
                        if($verified['enabled']){
                            $confirmed=$this->confirmedRetainedAuthority($org,(int)$rec['fund_id']);
                            $previouslyConsumed=max(0,-$ordinaryCents);
                            $unused=max(0,$confirmed['recorded_eligible_cents']-$previouslyConsumed);
                            $currentTax=0;
                            if($rec['dv_type']==='REGULAR')foreach($result['withholding'] as $tax){
                                if(($tax['disposition']??'')==='SCHOOL_UTILIZATION'&&
                                    (int)($tax['fund_id']??0)===(int)$rec['fund_id'])
                                    $currentTax+=moneyCents((string)$tax['amount']);
                            }
                            $selfNeeded=max(0,moneyCents($rec['gross'])-max(0,$ordinaryCents)-$unused);
                            $result['funding_basis_preview']['recorded_unused_retained_cents']=$unused;
                            $result['funding_basis_preview']['recorded_retained_pending_cents']=$confirmed['pending_cents'];
                            $result['funding_basis_preview']['self_dv_eligible_withheld_cents']=$currentTax;
                            $result['funding_basis_preview']['self_dv_required_cents']=$selfNeeded;
                            $result['funding_basis_preview']['gross_authority_passes']=$selfNeeded<=$currentTax;
                            $result['funding_basis_preview']['new_bank_cash_mode_enabled']=true;
                            $result['funding_basis_preview']['readiness']=
                                $result['funding_basis_preview']['gross_authority_passes']
                                    ?'BANK_BOOK_AND_OFFICER_CASH_VERIFICATION_REQUIRED'
                                    :'GROSS_AUTHORITY_INSUFFICIENT';
                            $result['funding_basis_preview']['active_finalization_basis']='POLICY_GATED_VERIFIED_NET_BANK_CASH';
                        }
                    }
                } catch (Throwable $e) {
                    // Do not let a legacy missing policy conceal this DV record.
                    // No cash-mode decision can be made from an incomplete preview.
                    $result['funding_basis_preview']=[
                        'mode'=>'NOT_VERIFIABLE','readiness'=>'POLICY_OR_FUND_REVIEW_REQUIRED',
                        'active_finalization_basis'=>'GROSS_AUTHORIZED_ALLOCATION',
                        'new_bank_cash_mode_enabled'=>false,
                        'can_finalize_using_bank_cash_mode'=>false];
                }
            }
            $result['accounting_readiness']=[
                'mode'=>$workpaper['applicable']?(string)$workpaper['treatment']:'MANUAL_ACCOUNTING_REVIEW',
                'explanation'=>(string)($workpaper['message']??''),
                'saved_accounting_versions'=>(int)($saved['n']??0),
                'has_authorized_allocation'=>(bool)$authority['has_allocation'],
                'available_allocation'=>$balance,
                'sufficient_allocation'=>$enough,
                'gross'=>(string)$rec['gross'],
                'withholding'=>(string)$rec['deductions'],
                'net'=>(string)$rec['net'],
                'manual_withholding_review'=>!$workpaper['applicable']&&moneyCents($rec['deductions'])>0,
                'manual_posting_issues'=>$manualIssues,
                'manual_posting_blocked'=>(bool)$manualIssues,
            ];
            $isDocumentEditable=($rec['status']==='DRAFT' && empty($rec['display_number'])) ||
                ($rec['status']==='FINALIZED' && !empty($rec['display_number']));
            $hasCheck=one($this->db,'SELECT id FROM checks WHERE dv_id=? LIMIT 1',[$rec['id']])!==null;
            $hasTaxCompletion=one($this->db,"SELECT e.id FROM tax_disposition_events e JOIN withholding_components w ON w.id=e.component_id WHERE w.dv_id=? AND e.state NOT IN ('PENDING') LIMIT 1",[$rec['id']])!==null;
            $result['edit_allowed']=$isDocumentEditable && $rec['dv_type']==='REGULAR' &&
                $workpaper['applicable'] && !$hasCheck && !$hasTaxCompletion &&
                ($rec['status']==='DRAFT' || ((int)($saved['n']??0)>0 && $workpaper['ready']));
            $result['correction_readonly_reason']=$hasCheck
                ?'A check is recorded for this DV. The original entries must remain intact; use a separately documented reclassification adjustment.'
                :($hasTaxCompletion?'Completed tax disposition requires a separately documented adjustment.'
                :(!$workpaper['applicable']?'This DV accounting treatment does not support verified item-based correction yet.':null));
            if($result['edit_allowed']){
                // The detail withholding list includes joined rate/tax_kind display
                // columns. Correction writes hash raw withholding_components rows.
                // Read that same canonical saved row set for the optimistic-lock
                // token so a fresh taxable draft is not falsely marked stale.
                $tokenWithholding=q($this->db,'SELECT * FROM withholding_components WHERE dv_id=? ORDER BY id',[$rec['id']])->fetchAll();
                $result['draft_token']=$this->dvCorrectionToken($rec,$lines,$tokenWithholding,(int)($saved['n']??0));
            }
        }
        if($type==='PR'){
            $result['requestor']=$this->prRequestor($org,(int)$rec['id']);
            $tx=one($this->db,'SELECT method FROM procurement_transactions WHERE id=? AND organization_id=?',[$rec['transaction_id'],$org]);
            $result['method']=$tx['method']??'REVIEW_REQUIRED';
            $block=$this->prEditBlockReason($org,$rec);
            $result['edit_allowed']=$block===null;
            $result['edit_block_reason']=$block;
            if($block===null)$result['draft_token']=$this->prDraftToken($rec,$lines,$result['method'],$result['requestor']);
        }
        return $result;
    }
    /** The UACS list is global reference data; filtering never exposes another organization's transactions. */
    public function listUacs(array $p): array {
        $this->org($p);
        $term=trim((string)($p['q']??''));demand(mbSafeLen($term)<=80,'Search is too long');
        $status=strtoupper(trim((string)($p['status']??'ALL')));
        $category=strtoupper(trim((string)($p['classification']??'ALL')));
        demand(in_array($status,['ALL','ACTIVE','INACTIVE'],true),'Invalid UACS status filter');
        demand(in_array($category,['ALL','INVENTORY','MOOE','OTHER'],true),'Invalid UACS classification filter');
        $offset=(int)($p['offset']??0);demand($offset>=0&&$offset<=100000,'Invalid UACS page');
        $where=[];$params=[];
        if($term!==''){$where[]='(code LIKE ? OR account_name LIKE ?)';$params[]='%'.$term.'%';$params[]='%'.$term.'%';}
        if($status!=='ALL'){$where[]='active=?';$params[]=$status==='ACTIVE'?1:0;}
        if($category==='INVENTORY')$where[]="code LIKE '104%'";
        elseif($category==='MOOE')$where[]="code LIKE '502%'";
        elseif($category==='OTHER')$where[]="code NOT LIKE '104%' AND code NOT LIKE '502%'";
        $tail=$where?' WHERE '.implode(' AND ',$where):'';
        $total=(int)one($this->db,'SELECT COUNT(*) n FROM uacs_accounts'.$tail,$params)['n'];
        $pageSize=80;
        $entries=q($this->db,'SELECT code,account_name,active FROM uacs_accounts'.$tail.' ORDER BY code LIMIT '.$pageSize.' OFFSET '.$offset,$params)->fetchAll();
        $summary=one($this->db,"SELECT COUNT(*) total, COALESCE(SUM(active=1),0) enabled, COALESCE(SUM(active=0),0) disabled FROM uacs_accounts");
        return ['rows'=>$entries,'total'=>$total,'offset'=>$offset,'limit'=>$pageSize,
            'summary'=>['total'=>(int)$summary['total'],'enabled'=>(int)$summary['enabled'],'disabled'=>(int)$summary['disabled']]];
    }
    /** Enable non-default accounts only after accounting review; history stays intact. */
    public function setUacsStatus(array $d): array {
        $org=$this->org($d);$this->referenceWriter();
        $code=requiredString($d,'code',32);$active=$d['active']??null;
        demand(is_bool($active),'Account status must be true or false');
        return $this->tx(function()use($org,$code,$active){
            $old=one($this->db,'SELECT code,active FROM uacs_accounts WHERE code=? FOR UPDATE',[$code]);
            demand($old!==null,'UACS account not found',404);
            $updated=(int)$active;
            if((int)$old['active']!==$updated){
                q($this->db,'UPDATE uacs_accounts SET active=? WHERE code=?',[$updated,$code]);
                $this->audit($org,'STATUS_CHANGED','UACS',0,['code'=>$code,'before'=>(int)$old['active'],'after'=>$updated]);
            }
            return ['code'=>$code,'active'=>$updated];
        });
    }
    public function accounts(): array { return q($this->db,'SELECT code,account_name FROM uacs_accounts WHERE active=1 ORDER BY code LIMIT 2000')->fetchAll(); }
    public function eligible(array $d): array {
        $org=$this->org($d);$type=requiredString($d,'type',25);
        if($type==='DIRECT')return $this->eligibleDvSourceRows($org,'PR',q($this->db,"SELECT pr.id,pr.display_number,pr.total,pr.fund_id,pr.purpose,
            COALESCE((SELECT SUM(a.amount) FROM dv_allocation_lines a
                JOIN disbursement_vouchers v ON v.id=a.dv_id
                WHERE a.pr_id=pr.id AND a.iar_line_id IS NULL AND v.status='FINALIZED'),0) committed
            FROM purchase_requests pr WHERE pr.organization_id=? AND pr.status='FINALIZED'
            HAVING total>committed ORDER BY pr.id DESC LIMIT 100",[$org])->fetchAll(),'id');

        $supplierProfiles="s.goods_profile_active,s.services_profile_active,s.rental_profile_active,s.contractor_profile_active,s.utilities_profile_active,s.other_profile_active,
            (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs atc ON atc.code=sa.atc_code WHERE sa.supplier_id=s.id AND atc.tax_kind='VAT' AND COALESCE(NULLIF(sa.profile_type,''),atc.supply_type) IN ('BOTH','GOODS')) AS goods_vat_atc_codes,
            (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs atc ON atc.code=sa.atc_code WHERE sa.supplier_id=s.id AND atc.tax_kind='EWT' AND COALESCE(NULLIF(sa.profile_type,''),atc.supply_type) IN ('BOTH','GOODS')) AS goods_ewt_atc_codes,
            (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs atc ON atc.code=sa.atc_code WHERE sa.supplier_id=s.id AND atc.tax_kind='VAT' AND COALESCE(NULLIF(sa.profile_type,''),atc.supply_type) IN ('BOTH','SERVICES')) AS services_vat_atc_codes,
            (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs atc ON atc.code=sa.atc_code WHERE sa.supplier_id=s.id AND atc.tax_kind='EWT' AND COALESCE(NULLIF(sa.profile_type,''),atc.supply_type) IN ('BOTH','SERVICES')) AS services_ewt_atc_codes,
            (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs atc ON atc.code=sa.atc_code WHERE sa.supplier_id=s.id AND atc.tax_kind='VAT' AND COALESCE(sa.rental_profile,0)=1) AS rental_vat_atc_codes,
            (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs atc ON atc.code=sa.atc_code WHERE sa.supplier_id=s.id AND atc.tax_kind='EWT' AND COALESCE(sa.rental_profile,0)=1) AS rental_ewt_atc_codes,
            (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs atc ON atc.code=sa.atc_code WHERE sa.supplier_id=s.id AND atc.tax_kind='VAT' AND COALESCE(sa.contractor_profile,0)=1) AS contractor_vat_atc_codes,
            (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs atc ON atc.code=sa.atc_code WHERE sa.supplier_id=s.id AND atc.tax_kind='EWT' AND COALESCE(sa.contractor_profile,0)=1) AS contractor_ewt_atc_codes,
            (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs atc ON atc.code=sa.atc_code WHERE sa.supplier_id=s.id AND atc.tax_kind='VAT' AND COALESCE(sa.utilities_profile,0)=1) AS utilities_vat_atc_codes,
            (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs atc ON atc.code=sa.atc_code WHERE sa.supplier_id=s.id AND atc.tax_kind='EWT' AND COALESCE(sa.utilities_profile,0)=1) AS utilities_ewt_atc_codes,
            (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs atc ON atc.code=sa.atc_code WHERE sa.supplier_id=s.id AND atc.tax_kind='VAT' AND COALESCE(sa.other_profile,0)=1) AS other_vat_atc_codes,
            (SELECT GROUP_CONCAT(sa.atc_code ORDER BY sa.atc_code SEPARATOR ',') FROM supplier_atcs sa JOIN tax_atcs atc ON atc.code=sa.atc_code WHERE sa.supplier_id=s.id AND atc.tax_kind='EWT' AND COALESCE(sa.other_profile,0)=1) AS other_ewt_atc_codes";

        if($type==='REGULAR'){
            // The default eligible API is intentionally strict: already-linked POs
            // cannot appear even in a stale/cached pre-v0.2.160 client.
            // Updated client requests include_existing=1 only to show a separate,
            // read-only "open existing DV" search result; it filters selectable POs.
            $rows=$this->eligibleDvSourceRows($org,'PO',q($this->db,"SELECT po.id po_id,po.display_number po_number,
            po.supplier_id,s.name original_supplier,s.proprietor_name,s.business_classification,s.vat_status,
            pr.fund_id,pr.display_number pr_number,$supplierProfiles,
            CASE WHEN EXISTS(SELECT 1 FROM po_lines pl JOIN item_catalog cat ON cat.id=pl.item_id
                WHERE pl.po_id=po.id AND cat.category='SERVICE') AND EXISTS(SELECT 1 FROM po_lines pl
                JOIN item_catalog cat ON cat.id=pl.item_id WHERE pl.po_id=po.id AND cat.category<>'SERVICE')
                THEN 'MIXED'
                WHEN EXISTS(SELECT 1 FROM po_lines pl JOIN item_catalog cat ON cat.id=pl.item_id
                WHERE pl.po_id=po.id AND cat.category='SERVICE') THEN 'SERVICE'
                ELSE 'GOODS' END item_category,
            (SELECT COALESCE(SUM(l.accepted_amount),0) FROM iar_lines l JOIN iars i ON i.id=l.iar_id
                WHERE i.po_id=po.id AND i.status='FINALIZED') accepted_total,
            (SELECT COALESCE(SUM(a.amount),0) FROM dv_allocation_lines a
                JOIN disbursement_vouchers v ON v.id=a.dv_id
                JOIN iar_lines il ON il.id=a.iar_line_id JOIN iars i ON i.id=il.iar_id
                WHERE i.po_id=po.id AND i.status='FINALIZED' AND v.status='FINALIZED') committed
            FROM purchase_orders po JOIN purchase_requests pr ON pr.id=po.pr_id
                JOIN suppliers s ON s.id=po.supplier_id
            WHERE po.organization_id=? AND po.status='FINALIZED' AND pr.status='FINALIZED'
            HAVING accepted_total>committed ORDER BY po.id DESC LIMIT 100",[$org])->fetchAll(),'po_id');
            if((string)($d['include_existing']??'')==='1')return $rows;
            return array_values(array_filter($rows,static fn(array $row):bool=>empty($row['existing_dvs'])));
        }

        if($type==='TRAVEL_REIMBURSEMENT')return $this->eligibleTravelDvClaims($d);

        if($type==='REIMBURSEMENT')return $this->eligibleDvSourceRows($org,'ADVANCE',q($this->db,"SELECT a.id advance_id,a.iar_line_id,a.original_payer,a.source,a.evidence_reference,a.amount,po.supplier_id,s.name original_supplier,s.business_classification,s.vat_status,cat.category AS item_category,$supplierProfiles,i.display_number iar_number,pr.display_number pr_number,po.display_number po_number,pr.fund_id,COALESCE((SELECT SUM(al.amount) FROM dv_allocation_lines al JOIN disbursement_vouchers v ON v.id=al.dv_id WHERE al.advance_id=a.id AND v.status='FINALIZED'),0) reimbursed FROM advances a JOIN iar_lines l ON l.id=a.iar_line_id JOIN po_lines pl ON pl.id=l.po_line_id JOIN item_catalog cat ON cat.id=pl.item_id JOIN iars i ON i.id=l.iar_id JOIN purchase_orders po ON po.id=i.po_id JOIN awards aw ON aw.id=po.award_id JOIN rfqs r ON r.id=aw.rfq_id JOIN purchase_requests pr ON pr.id=po.pr_id JOIN suppliers s ON s.id=po.supplier_id WHERE a.organization_id=? AND i.status='FINALIZED' HAVING amount>reimbursed ORDER BY a.id DESC LIMIT 100",[$org])->fetchAll(),'advance_id');

        throw new DomainError('Invalid DV type');
    }
    public function amendPO(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org);return $this->tx(function()use($org,$d){
        $po=$this->record('purchase_orders',idOf($d,'po_id'),$org,true);demand($po['status']==='FINALIZED','Only finalized PO can be amended');demand(one($this->db,'SELECT 1 FROM iars WHERE po_id=? LIMIT 1',[$po['id']])===null,'Amendment with existing IAR requires controlled downstream reconciliation; not enabled in this build');$reason=requiredString($d,'reason',500);
        $before=q($this->db,'SELECT * FROM po_lines WHERE po_id=? ORDER BY id FOR UPDATE',[$po['id']])->fetchAll();$new=rowsRequired($d,'lines');demand(count($new)===count($before),'All PO lines must be included in amendment');
        foreach($before as $idx=>$old){$line=$new[$idx];demand((int)idOf($line,'po_line_id')===(int)$old['id'],'PO line order and identity must be preserved');$qty=qtyMillis($line['quantity']??'');$unit=moneyCents($line['unit_price']??'');demand($qty>0&&$unit>0,'Positive values required');$pr=one($this->db,'SELECT * FROM pr_lines WHERE id=? FOR UPDATE',[$old['pr_line_id']]);$others=one($this->db,"SELECT COALESCE(SUM(l.quantity),0) total FROM po_lines l JOIN purchase_orders p ON p.id=l.po_id WHERE l.pr_line_id=? AND l.id!=? AND p.status='FINALIZED'",[$old['pr_line_id'],$old['id']]);demand(qtyMillis($others['total'])+$qty<=qtyMillis($pr['quantity']),'Amendment exceeds remaining PR quantity');q($this->db,'UPDATE po_lines SET quantity=?,unit_price=? WHERE id=?',[qtyString($qty),moneyString($unit),$old['id']]);}
        $count=one($this->db,'SELECT COALESCE(MAX(version_no),0) n FROM po_amendments WHERE po_id=?',[$po['id']]);$version=(int)$count['n']+1;$after=q($this->db,'SELECT * FROM po_lines WHERE po_id=? ORDER BY id',[$po['id']])->fetchAll();$this->insert('po_amendments',['po_id'=>$po['id'],'version_no'=>$version,'old_json'=>json_encode($before),'new_json'=>json_encode($after),'reason'=>$reason,'actor_id'=>$this->user['id']]);$this->snapshot($org,'PO',(int)$po['id'],['number'=>$po['display_number'],'lines'=>$after],$reason);$this->audit($org,'AMENDED','PO',(int)$po['id'],['version'=>$version]);return ['po_id'=>(int)$po['id'],'amendment_version'=>$version];
    });}
    /** Issue one numbered ICS for one or more reviewed semi-expendable assets.
     * No schema migration: the first row owns the document_registry entry and
     * immutable snapshot; sibling custody rows share its display number.  PAR
     * remains strictly single-asset.  All locks, movements and counter updates
     * occur inside one transaction, so failures cannot leave partial custody.
     */
    public function issueAccountability(array $d): array {
        $org=$this->org($d);$this->operationalWriteAllowed($org,'accountability.issue');
        $ids=$d['asset_ids']??null;
        if($ids===null)$ids=[idOf($d,'asset_id')];
        demand(is_array($ids)&&array_is_list($ids)&&count($ids)>=1&&count($ids)<=20,
            'Select 1–20 individual properties per accountability slip',422);
        $unique=[];
        foreach($ids as $raw){
            demand(is_int($raw)||is_string($raw),'Invalid property selection',422);
            demand(preg_match('/^[1-9][0-9]*$/D',(string)$raw)===1,'Invalid property selection',422);
            $id=(int)$raw;demand($id>0,'Invalid property selection',422);
            demand(!isset($unique[$id]),'The same physical property was selected more than once',422);
            $unique[$id]=true;
        }
        $assetIds=array_keys($unique);sort($assetIds,SORT_NUMERIC);
        $kind=requiredString($d,'doc_type',10);
        demand(in_array($kind,['ICS','PAR'],true),'Unsupported accountability document',422);
        demand($kind==='ICS'||count($assetIds)===1,
            'Only ICS supports multiple properties. Issue each PAR separately.',422);
        $custodianEmployeeId=idOf($d,'custodian_employee_id');
        return $this->tx(function()use($org,$assetIds,$kind,$custodianEmployeeId){
            $assigned=$this->staffActive($org,$custodianEmployeeId);
            $custodian=trim((string)$assigned['full_name']);
            demand($custodian!==''&&mbSafeLen($custodian)<=160,'Employee name requires administrator review',409);
            $assets=[];
            foreach($assetIds as $assetId){
                $asset=one($this->db,'SELECT a.*,i.category,i.name AS item_name FROM assets a JOIN item_catalog i ON i.id=a.item_id WHERE a.id=? AND a.organization_id=? FOR UPDATE',[$assetId,$org]);
                demand($asset!==null&&$asset['status']==='ACTIVE','Active accountable asset required',409);
                // Do not re-issue an ICS/PAR for an item in a lifecycle approval or disposal stage.
                $life=one($this->db,'SELECT snapshot_json FROM document_versions WHERE organization_id=? AND doc_type=? AND entity_id=? ORDER BY version_no DESC LIMIT 1',[$org,'ASSET_LIFECYCLE',$assetId]);
                if($life!==null){
                    $decision=json_decode((string)$life['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
                    demand(is_array($decision)&&($decision['state']??null)==='SERVICEABLE',
                        'Property is under unserviceability/disposal review. ICS/PAR issuance is restricted.',409);
                }

                demand(($kind==='ICS'&&$asset['category']==='SEMI_EXPENDABLE')||($kind==='PAR'&&$asset['category']==='PPE'),
                    'Selected properties must match the required ICS/PAR classification',409);
                demand(PropertyBatchMetadata::reviewState($asset['property_profile']??null)!=='PENDING_REVIEW',
                    'Review every selected property before issuing ICS/PAR',409);
                $existing=PropertyReturnWorkflow::openAccountability($this->db,$org,$assetId);
                demand($existing===null,'A selected property has an open ICS/PAR. Record the physical return before reissuing.',409);
                demand(trim((string)($asset['custodian']??''))==='',
                    'Property still has an accountable custodian; reconcile the transfer or return before reissuing.',409);
                // A returned item marked For Repair / Inspection / Under Repair must
                // not be reissued merely because its historical ICS has been closed.
                // A formal lifecycle decision continues to take precedence above.
                $returnEvidence=one($this->db,"SELECT id FROM document_links WHERE organization_id=? AND source_type='ASSET'
                    AND source_id=? AND target_type IN ('RRSP','PRR') ORDER BY id DESC LIMIT 1",[$org,$assetId]);
                if($returnEvidence!==null||isset(PropertyBatchMetadata::decode($asset['property_profile']??null)['transfer_origin']))demand(trim((string)($asset['condition_note']??''))==='Serviceable',
                    'Returned property requires a Serviceable condition before a new ICS/PAR; complete inspection or repair and record the correction first.',409);
                $assets[]=$asset;
            }
            $anchor=0;$number='';$items=[];
            foreach($assets as $asset){
                $row=$this->insert('accountability_documents',[
                    'organization_id'=>$org,'asset_id'=>$asset['id'],'doc_type'=>$kind,
                    'custodian'=>$custodian,'display_number'=>'PENDING']);
                if($anchor===0){
                    $anchor=$row;$number=$this->registered($org,$kind,$anchor);
                }
                q($this->db,'UPDATE accountability_documents SET display_number=? WHERE id=?',[$number,$row]);
                $movementId=$this->insert('asset_movements',[
                    'asset_id'=>$asset['id'],'event_type'=>'ISSUE',
                    'from_custodian'=>$asset['custodian'],'to_custodian'=>$custodian,
                    'reason'=>count($assets)>1?'Manual group issue recorded; signature remains external':'Manual issue recorded; signature remains external',
                    'actor_id'=>$this->user['id']]);
                // Verify identity and custody within this transaction before making an immutable link.
                $evidence=one($this->db,
                    'SELECT m.id,m.asset_id,m.event_type,m.to_custodian,d.id AS document_id,d.asset_id AS document_asset_id,d.organization_id,d.custodian AS document_custodian,d.status,d.doc_type FROM asset_movements m JOIN accountability_documents d ON d.id=? WHERE m.id=? FOR UPDATE',
                    [$row,$movementId]);
                demand($evidence!==null && (int)$evidence['asset_id']===(int)$asset['id']
                    && (int)$evidence['document_asset_id']===(int)$asset['id']
                    && (int)$evidence['organization_id']===$org
                    && $evidence['event_type']==='ISSUE'
                    && $evidence['status']==='FINALIZED'
                    && $evidence['doc_type']===$kind
                    && trim((string)$evidence['document_custodian'])===$custodian
                    && trim((string)$evidence['to_custodian'])===$custodian,
                    'Issuance link consistency check failed; no partial custody changes committed',409);
                $this->insert('accountability_movement_links',[
                    'organization_id'=>$org,'accountability_document_id'=>$row,
                    'asset_movement_id'=>$movementId,'asset_id'=>(int)$asset['id']]);
                q($this->db,'UPDATE assets SET custodian=? WHERE id=? AND organization_id=?',[$custodian,$asset['id'],$org]);
                $items[]=['asset_id'=>(int)$asset['id'],'tag'=>(string)$asset['property_tag'],
                    'description'=>(string)$asset['item_name'],'accountability_row_id'=>$row];
            }
            $snapshot=['asset_id'=>$items[0]['asset_id'],'custodian'=>$custodian,
                'custodian_employee_id'=>$custodianEmployeeId,'tag'=>$items[0]['tag'],
                'number'=>$number,'items'=>$items,'asset_ids'=>$assetIds];
            $this->snapshot($org,$kind,$anchor,$snapshot);
            $this->audit($org,'FINALIZED',$kind,$anchor,['asset_ids'=>$assetIds,'item_count'=>count($items)]);
            return ['id'=>$anchor,'number'=>$number,'asset_ids'=>$assetIds,'item_count'=>count($items)];
        });
    }
    public function reconcileBank(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org);return $this->tx(function()use($org,$d){
        // Legacy rows remain read-only. Direct callers cannot bypass the typed
        // draft/evidence/version gates by using the historical route name.
        return (new FinancialReview($this->db,$this->user))->saveDraft($d);
    });}

    public function recordManualSignature(array $d): array {$org=$this->org($d);$this->operationalWriteAllowed($org);return $this->tx(function()use($org,$d){
        $type=requiredString($d,'doc_type',16);$id=idOf($d,'entity_id');demand(one($this->db,'SELECT 1 FROM document_registry WHERE organization_id=? AND doc_type=? AND entity_id=?',[$org,$type,$id])!==null,'Existing finalized document required');
        $reference=trim((string)($d['evidence_reference']??''));
        $record=$this->insert('manual_signature_records',['organization_id'=>$org,'doc_type'=>$type,'entity_id'=>$id,'evidence_reference'=>$reference,'received_at'=>date('Y-m-d H:i:s'),'recorded_by'=>$this->user['id']]);
        $this->audit($org,'EXTERNAL_SIGNED_COPY_RECORDED',$type,$id,['signature_record_id'=>$record,'evidence_reference'=>$reference,'note'=>'Attestation of external paper signature only; not approval by software']);
        return ['id'=>$record,'document_id'=>$id,'status'=>'External signed copy recorded (no e-approval)'];
    });}

}
