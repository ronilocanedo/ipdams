<?php
declare(strict_types=1);
/**
 * v0.2.202+ SECURITY-REVIEW candidate: user-specific read/print permission registry.
 * Append-only configuration snapshots and audit events are transactional.
 * IMPORTANT: Changes here DO NOT grant legacy-route access until the endpoint-by-
 * endpoint capability migration and real MariaDB adversarial UAT are accepted.
 * No user/employee/organization role or scope is created/changed by these APIs.
 */
final class RoleFunctions {
    public const FUNCTIONS = [
        'procurement.pr.view'=>['module'=>'Procurement','label'=>'View Purchase Requests','action'=>'VIEW'],
        'procurement.pr.print_existing'=>['module'=>'Procurement','label'=>'Reprint finalized Purchase Requests','action'=>'PRINT'],
        'procurement.rfq.view'=>['module'=>'Procurement','label'=>'View Requests for Quotation','action'=>'VIEW'],
        'procurement.po.view'=>['module'=>'Procurement','label'=>'View Purchase Orders','action'=>'VIEW'],
        'procurement.iar.view'=>['module'=>'Procurement','label'=>'View Inspection & Acceptance Reports','action'=>'VIEW'],
        'finance.dv.view'=>['module'=>'Disbursements','label'=>'View Disbursement Vouchers','action'=>'VIEW'],
        'finance.dv.print_existing'=>['module'=>'Disbursements','label'=>'Reprint finalized Vouchers','action'=>'PRINT'],
        'documents.ris.view_issued'=>['module'=>'Inventory & Property','label'=>'View issued RIS documents','action'=>'VIEW'],
        'documents.ris.print_issued'=>['module'=>'Inventory & Property','label'=>'Reprint issued RIS documents','action'=>'PRINT'],
        'documents.ics.view_issued'=>['module'=>'Inventory & Property','label'=>'View finalized ICS documents','action'=>'VIEW'],
        'documents.par.view_issued'=>['module'=>'Inventory & Property','label'=>'View finalized PAR documents','action'=>'VIEW'],
        'travel.documents.view'=>['module'=>'Travel','label'=>'View existing Travel documents','action'=>'VIEW'],
        // v0.2.209: separately granted, school-scoped READ ONLY register views.
        // Stock movements, asset writes, textbook movements and all legacy
        // module routes remain denied to assigned-school Division accounts.
        'inventory.stocks.view'=>['module'=>'Inventory & Property','label'=>'View Stock on Hand (read-only special register)','action'=>'VIEW'],
        'property.assets.view'=>['module'=>'Inventory & Property','label'=>'View Asset Register (read-only special register)','action'=>'VIEW'],
        'textbooks.batches.view'=>['module'=>'Textbooks','label'=>'View textbook batches (read-only special register)','action'=>'VIEW'],
        'inventory.stocks.manage'=>['module'=>'Inventory & Property','label'=>'Manage stock opening balances (requires future security activation)','action'=>'MANAGE'],
        'property.assets.manage'=>['module'=>'Inventory & Property','label'=>'Register or change assets (requires future security activation)','action'=>'MANAGE'],
        'textbooks.movements.manage'=>['module'=>'Textbooks','label'=>'Record textbook movements (requires future security activation)','action'=>'MANAGE'],
    ];
    public const SCHOOL_USER_REGISTER_DEFAULT_CODES = ['inventory.stocks.view','property.assets.view','textbooks.batches.view'];
    /** These action codes are BASELINE Division User functions in a specifically assigned school.
     * The legacy Domain issuance authorization is mapped separately and remains subject to stock/custody gates. */
    public const SCHOOL_DIVISION_DEFAULT_CODES = [
        'documents.ris.view_issued','documents.ris.print_issued',
        'documents.ics.view_issued','documents.par.view_issued',
    ];
    /** Human-readable legacy templates, NOT authorizations and NOT an ACL migration. */
    public const DEFAULT_TEMPLATES = [
        'SCHOOL'=>['title'=>'School User','summary'=>'Assigned-school operational functions. Existing checks remain authoritative.',
            'functions'=>['Procurement · own-school PR/RFQ/PO/IAR under workflow checks','Disbursements · authorized own-school documents only','Inventory/Property · stock and accountability subject to original controls','Travel · assigned-school document workflow']],
        'DIVISION'=>['title'=>'Division User','summary'=>'Division workspace defaults; assigned schools: RIS and ICS/PAR issuance only for inventory and property.',
            'functions'=>['Assigned school · RIS requests: view and create','Assigned school · issue available consumable stocks against a draft RIS','Assigned school · issue ICS/PAR from eligible accountable assets','Assigned school · view/reprint existing RIS and issued ICS/PAR slips','Not default: Stock on Hand, Asset Register, Textbooks or asset opening/adjustments']],
        'SUPER_ADMIN'=>['title'=>'Project Owner / Super Administrator','summary'=>'Existing administrator functions; no financial or signer-policy bypass.',
            'functions'=>['Organization and user administration','Reference data configuration','Read-only review of named Special Functions','Existing operational workflows remain subject to financial controls']],
        'VIEWER'=>['title'=>'Division MOOE Read-only','summary'=>'Assigned schools only; no posting or writes.',
            'functions'=>['Read/reprint existing school operational documents','Inventory/Property · only issued RIS/ICS/PAR of selected month','RSMI generator not yet available','Never stock or asset registers, drafts, approvals, edits or posting']],
    ];
    /** These four register views are deliberately narrow live permissions. They never
     * authorize broad Domain::list/detail, attachment, posting, or finance routes. */
    public const LIVE_REGISTER = [
        'PR'=>'procurement.pr.view',
        'RFQ'=>'procurement.rfq.view',
        'PO'=>'procurement.po.view',
        'IAR'=>'procurement.iar.view',
    ];
    public const LIVE_PRINT = ['PR'=>'procurement.pr.print_existing'];
    public const LIVE_SCHOOL_REGISTER = [
        'STOCK'=>'inventory.stocks.view',
        'ASSET'=>'property.assets.view',
        'TEXTBOOK'=>'textbooks.batches.view',
    ];
    private static function isLiveRegisterCode(string $code): bool {
        return in_array($code,self::LIVE_REGISTER,true)||in_array($code,self::LIVE_PRINT,true)||in_array($code,self::LIVE_SCHOOL_REGISTER,true);
    }
    private const KEY_PREFIX='IPDAMS_SPECIAL_FUNCTIONS_USER_V1_';
    private PDO $db;
    private array $actor;
    public function __construct(PDO $db,array $actor){$this->db=$db;$this->actor=$actor;}
    private static function today(): string {return (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');}
    private static function validDate(string $date): bool {
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))return false;
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        return $parsed!==false && $parsed->format('Y-m-d')===$date;
    }
    private function admin(): void {demand(($this->actor['role']??null)==='SUPER_ADMIN','Only the Project Owner security administrator may manage Special Functions during staging',403);}
    private function subject(int $userId,int $schoolId,bool $lock=false): array {
        demand($userId>0 && $schoolId>0,'User and school required',422);
        $suffix=$lock?' FOR UPDATE':'';
        $school=one($this->db,"SELECT id,kind,active FROM organizations WHERE id=?$suffix",[$schoolId]);
        demand($school!==null && $school['kind']==='SCHOOL' && (int)$school['active']===1,'Active school required',403);
        $user=one($this->db,"SELECT id,role,active,full_name FROM users WHERE id=?$suffix",[$userId]);
        demand($user!==null && (int)$user['active']===1,'An active account is required',403);
        demand(in_array($user['role'],['DIVISION','SCHOOL'],true),'The selected account type does not accept ordinary Special Functions',403);
        demand((int)$user['id']!==(int)$this->actor['id'],'Self-granting is forbidden',403);
        demand(one($this->db,'SELECT 1 FROM user_organization_scopes WHERE user_id=? AND organization_id=?',[$userId,$schoolId])!==null,
            'Special Functions cannot expand the authorized school assignment scope',403);
        return $user;
    }
    private function key(int $userId): string {return self::KEY_PREFIX.$userId;}
    private function current(int $userId,int $schoolId,bool $lock=false): array {
        $row=one($this->db,'SELECT id,config_json,created_at FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1'.($lock?' FOR UPDATE':''),[$schoolId,$this->key($userId)]);
        $grants=$row===null?[]:json_decode((string)$row['config_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($grants) && array_is_list($grants),'Invalid role-function history; administrator review required',409);
        return ['version_id'=>(int)($row['id']??0),'grants'=>$grants];
    }
    private function append(int $schoolId,int $userId,array $grants,string $reason,string $event,array $detail): int {
        demand(count($grants)<=100,'Special Function limit reached; audit review required',409);
        $json=json_encode($grants,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $today=self::today();
        q($this->db,'INSERT INTO configuration_versions (organization_id,config_key,config_json,effective_from,review_note) VALUES (?,?,?,?,?)',[$schoolId,$this->key($userId),$json,$today,$reason]);
        $version=(int)$this->db->lastInsertId();
        q($this->db,'INSERT INTO audit_events (organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
            [$schoolId,(int)$this->actor['id'],$event,'USER',$userId,json_encode(array_merge($detail,[
                'user_id'=>$userId,'school_id'=>$schoolId,'snapshot_version_id'=>$version,'reason'=>$reason,
                'policy_status'=>'NAMED_REGISTER_READ_ONLY_OR_CONFIGURED_ONLY_NO_LEGACY_ROUTE_OVERRIDE']),JSON_THROW_ON_ERROR)]);
        return $version;
    }
    public static function effectiveness(array $grant,string $today,bool $subjectSchoolAssigned,bool $subjectActive,bool $viewer): string {
        if($viewer || !$subjectActive || !$subjectSchoolAssigned)return 'RESTRICTED';
        if(($grant['status']??null)!=='ACTIVE')return 'REVOKED';
        if(!self::validDate($today) || !self::validDate((string)($grant['starts_on']??'')))return 'INVALID';
        if($today<$grant['starts_on'])return 'SCHEDULED';
        if(($grant['ends_on']??null)!==null){
            if(!self::validDate($grant['ends_on']))return 'INVALID';
            if($today>$grant['ends_on'])return 'EXPIRED';
        }
        return 'CONFIGURED_ONLY'; // Never claim an unmapped legacy endpoint is authorized.
    }
    /**
     * Return codes not already reserved by an unrevoked active or future-dated
     * grant for this *one user and school*. A past, expired or revoked function
     * can be assigned again with a new audited grant. Role-default descriptors
     * are NOT a permission-code matrix; special-route codes are never inferred
     * from those descriptions. Keep this predicate aligned with grant().
     */
    public static function availableFunctionCodes(array $grants,string $today,int $schoolId,string $role=''): array {
        demand(self::validDate($today),'Valid current date required',500);
        $assigned=[];
        foreach($grants as $grant){
            if(!is_array($grant) || ($grant['status']??null)!=='ACTIVE' || (int)($grant['school_id']??0)!==$schoolId)continue;
            $code=(string)($grant['permission']??'');
            if(!isset(self::FUNCTIONS[$code]))continue;
            $end=$grant['ends_on']??null;
            if($end!==null && is_string($end) && self::validDate($end) && $end<$today)continue;
            $assigned[$code]=true; // includes future-dated grants to avoid duplicates
        }
        $defaults=$role==='DIVISION'?array_fill_keys(self::SCHOOL_DIVISION_DEFAULT_CODES,true):($role==='SCHOOL'?array_fill_keys(self::SCHOOL_USER_REGISTER_DEFAULT_CODES,true):[]);
        return array_values(array_filter(array_keys(self::FUNCTIONS),static fn($code)=>!isset($assigned[$code])&&!isset($defaults[$code])));
    }
    public function catalog(): array {
        $this->admin();
        $functions=[];
        foreach(self::FUNCTIONS as $code=>$meta)$functions[]=array_merge(['code'=>$code,'grantable_in_staging'=>true,
            'effective_in_read_only_register'=>self::isLiveRegisterCode($code)],$meta);
        return ['functions'=>$functions,'role_templates'=>self::DEFAULT_TEMPLATES,
            'legacy_role_checks_unchanged'=>true,'grants_enforce_legacy_routes'=>false,
            'read_only_register_grants_live'=>true,
            'notice'=>'Named procurement VIEW and approved school Stock on Hand, Asset Register and Textbook VIEW functions activate read-only, school-scoped Special Function registers. PR reprint requires a separate grant. Manage actions remain configured-only; no legacy stock, asset or textbook route override.'];
    }
    public function userDetail(array $get): array {
        $this->admin();$userId=idOf($get,'user_id');$schoolId=idOf($get,'school_id');
        $user=$this->subject($userId,$schoolId);$state=$this->current($userId,$schoolId);$today=self::today();
        $grants=[];
        foreach($state['grants'] as $g){
            if(!is_array($g))continue;
            $g['effectiveness']=self::effectiveness($g,$today,true,true,false);
            if ($g['effectiveness']==='CONFIGURED_ONLY' && self::isLiveRegisterCode((string)($g['permission']??'')))
                $g['effectiveness']='ACTIVE_READ_ONLY_REGISTER';
            $g['label']=self::FUNCTIONS[$g['permission']]['label']??'Unrecognized (restricted)';
            $grants[]=$g;
        }
        $history=q($this->db,'SELECT a.id,a.created_at,a.event_type,a.actor_id,a.details,u.full_name AS actor_name FROM audit_events a LEFT JOIN users u ON u.id=a.actor_id WHERE a.organization_id=? AND a.entity_type=? AND a.entity_id=? AND a.event_type IN (?,?) ORDER BY a.id DESC LIMIT 40',
            [$schoolId,'USER',$userId,'SPECIAL_FUNCTION_GRANTED','SPECIAL_FUNCTION_REVOKED'])->fetchAll();
        // Filter events to this exact scope and special-function release; never mix other school/role audit events.
        $history=array_values(array_filter($history,static function($e)use($schoolId){$d=json_decode((string)$e['details'],true);return is_array($d)&&(int)($d['school_id']??0)===$schoolId && in_array(($d['policy_status']??''),['CONFIGURED_ONLY_NOT_LEGACY_ROUTE_ACCESS','NAMED_REGISTER_READ_ONLY_OR_CONFIGURED_ONLY_NO_LEGACY_ROUTE_OVERRIDE'],true);}));
        foreach($history as &$item){$detail=json_decode((string)$item['details'],true);unset($item['details']);$item['permission']=$detail['permission']??'';$item['reason']=$detail['reason']??'';$item['grant_id']=$detail['grant_id']??'';}unset($item);
        return ['user'=>['id'=>(int)$user['id'],'full_name'=>$user['full_name'],'role'=>$user['role']],
            'school_id'=>$schoolId,'grants'=>$grants,'version_id'=>$state['version_id'],
            'available_functions'=>self::availableFunctionCodes($state['grants'],$today,$schoolId,(string)$user['role']),
            'history'=>$history,'grants_enforce_legacy_routes'=>false,'read_only_register_grants_live'=>true];
    }

    /** Current, assigned-school, server-evaluated functions for the signed-in
     * non-viewer account. Never return revoked/expired history to the subject. */
    public function mine(): array {
        $this->member();
        $schools=q($this->db,"SELECT o.id,o.name FROM organizations o
            JOIN user_organization_scopes s ON s.organization_id=o.id
            WHERE s.user_id=? AND o.kind='SCHOOL' AND o.active=1
            ORDER BY o.name,o.id LIMIT 100",[(int)$this->actor['id']])->fetchAll();
        $all=[];$today=self::today();
        foreach($schools as $school){
            $id=(int)$school['id'];$perms=[];
            $state=$this->current((int)$this->actor['id'],$id);
            foreach($state['grants'] as $grant){
                if(!is_array($grant))continue;
                $code=(string)($grant['permission']??'');
                if(!isset(self::FUNCTIONS[$code]))continue;
                if((int)($grant['school_id']??0)!==$id)continue;
                if(self::effectiveness($grant,$today,true,true,false)!=='CONFIGURED_ONLY')continue;
                $perms[]=['code'=>$code,'label'=>self::FUNCTIONS[$code]['label'],
                    'register_enabled'=>in_array($code,self::LIVE_REGISTER,true),
                    'school_register_enabled'=>in_array($code,self::LIVE_SCHOOL_REGISTER,true),
                    'print_existing_enabled'=>in_array($code,self::LIVE_PRINT,true),
                    'ends_on'=>$grant['ends_on']??null];
            }
            if($perms)$all[]=['school_id'=>$id,'school_name'=>$school['name'],'functions'=>$perms];
        }
        return ['schools'=>$all,'register_live'=>true,'other_routes_unchanged'=>true];
    }
    private function member(): void {
        demand(in_array(($this->actor['role']??null),['DIVISION','SCHOOL'],true),
            'Only an active School or Division user may access individual Special Functions',403);
    }
    private function requireActive(string $permission,int $schoolId): void {
        $this->member();
        scope($this->db,$this->actor,$schoolId);
        $org=one($this->db,"SELECT id FROM organizations WHERE id=? AND kind='SCHOOL' AND active=1",[$schoolId]);
        demand($org!==null,'Active school required',403);
        $state=$this->current((int)$this->actor['id'],$schoolId);
        foreach($state['grants'] as $grant){
            if(!is_array($grant))continue;
            if(($grant['permission']??null)!==$permission || (int)($grant['school_id']??0)!==$schoolId)continue;
            if(self::effectiveness($grant,self::today(),true,true,false)==='CONFIGURED_ONLY')return;
        }
        demand(false,'No current special function for this school and document type',403);
    }
    /** Extra check on the opt-in Special Function print URL; the underlying
     * official PR renderer still validates snapshot, registry and final status. */
    public function authorizeSpecialPrint(int $schoolId,string $docType): void {
        demand(isset(self::LIVE_PRINT[$docType]),'Unsupported special print function',403);
        $this->requireActive(self::LIVE_PRINT[$docType],$schoolId);
    }
    /** A separate, status-bound projection. No source record, drafts, sensitive
     * financial fields, inventories, asset balances, or mutation exposed. */
    public function issuedRegister(array $input): array {
        $this->member();
        $schoolId=idOf($input,'organization_id');
        $docType=requiredString($input,'type',8);
        demand(isset(self::LIVE_REGISTER[$docType]),'Unsupported read-only document type',403);
        $month=requiredString($input,'month',7);
        demand(preg_match('/^(19|20|21)\d{2}-(0[1-9]|1[0-2])$/D',$month)===1,'Valid month YYYY-MM required',422);
        $this->requireActive(self::LIVE_REGISTER[$docType],$schoolId);
        $tables=['PR'=>'purchase_requests','RFQ'=>'rfqs','PO'=>'purchase_orders','IAR'=>'iars'];
        $table=$tables[$docType]; // fixed whitelist: never interpolated from input
        $rows=q($this->db,"SELECT d.entity_id AS id,d.display_number,d.period,d.created_at
            FROM document_registry d JOIN {$table} src
            ON src.id=d.entity_id AND src.organization_id=d.organization_id
            WHERE d.organization_id=? AND d.doc_type=? AND d.period=? AND src.status='FINALIZED'
            ORDER BY d.id DESC LIMIT 100",[$schoolId,$docType,$month])->fetchAll();
        return ['school_id'=>$schoolId,'type'=>$docType,'month'=>$month,'documents'=>$rows,
            'read_only'=>true,'printer_requires_separate_grant'=>true];
    }
    /** v0.2.209: a separate SCHOOL-ASSIGNED named-register projection.
     * Revalidates the grant, active assignment, dates and current account on
     * EVERY request. Never delegates to broad Domain list or returns stock
     * history, asset cost/serial, movements, attachments or write operations.
     * All table names are literal server-side whitelist choices.
     */
    public function schoolRegister(array $input): array {
        $schoolId=idOf($input,'organization_id');
        $type=requiredString($input,'type',12);
        demand(isset(self::LIVE_SCHOOL_REGISTER[$type]),'Unsupported school register',403);
        // Deliberately leave these optional functions OFF for VIEWER accounts.
        // Assigned school checks are enforced in requireActive(), independently
        // of what was visible in the sidebar or previously rendered client UI.
        $this->requireActive(self::LIVE_SCHOOL_REGISTER[$type],$schoolId);
        if($type==='STOCK'){
            $rows=q($this->db,"SELECT l.id,i.name AS item_name,l.batch,
                    COALESCE(SUM(e.quantity_delta),0) AS on_hand
                    FROM inventory_lots l JOIN item_catalog i ON i.id=l.item_id
                    LEFT JOIN stock_events e ON e.lot_id=l.id AND e.organization_id=l.organization_id
                    WHERE l.organization_id=? AND i.category='SUPPLY'
                    GROUP BY l.id,i.name,l.batch ORDER BY i.name,l.id LIMIT 100",[$schoolId])->fetchAll();
            $cols=['id','item_name','batch','on_hand'];
        }elseif($type==='ASSET'){
            $rows=q($this->db,"SELECT a.id,a.property_tag,i.name AS item_name,i.category,
                    a.custodian,a.location,a.status
                    FROM assets a JOIN item_catalog i ON i.id=a.item_id
                    WHERE a.organization_id=? AND a.status<>'TRANSFERRED' ORDER BY a.id DESC LIMIT 100",[$schoolId])->fetchAll();
            $cols=['id','property_tag','item_name','category','custodian','location','status'];
        }else{
            $rows=q($this->db,"SELECT b.id,t.title,t.grade,t.subject,b.batch,b.storage,
                    COALESCE(SUM(m.quantity_delta),0) AS on_hand
                    FROM textbook_batches b JOIN textbook_titles t ON t.id=b.title_id
                    LEFT JOIN textbook_movements m ON m.batch_id=b.id AND m.organization_id=b.organization_id
                    WHERE b.organization_id=?
                    GROUP BY b.id,t.title,t.grade,t.subject,b.batch,b.storage
                    ORDER BY b.id DESC LIMIT 100",[$schoolId])->fetchAll();
            $cols=['id','title','grade','subject','batch','storage','on_hand'];
        }
        return ['school_id'=>$schoolId,'type'=>$type,'rows'=>$rows,'columns'=>$cols,
            'read_only'=>true,'limit'=>100,'direct_legacy_module_access'=>false];
    }
    public function grant(array $input): array {
        $this->admin();$userId=idOf($input,'user_id');$schoolId=idOf($input,'school_id');
        $permission=requiredString($input,'permission',100);demand(isset(self::FUNCTIONS[$permission]),'Action not eligible for individual special configuration',403);
        $reason=requiredString($input,'reason',500);demand(mbSafeLen($reason)>=15,'Provide an authorization reference and clear reason (minimum 15 characters)',422);
        $start=requiredString($input,'starts_on',10);$end=trim((string)($input['ends_on']??''));
        demand(self::validDate($start)&&$start>=self::today(),'Effective start cannot be backdated',422);
        demand($end==='' || (self::validDate($end)&&$end>=$start),'End date must follow start date',422);
        demand(is_int($input['expected_version']??null)&&$input['expected_version']>=0,'Refresh current grants before saving',409);
        // API index owns the database and idempotency transaction.
        demand($this->db->inTransaction(),'Atomic idempotent transaction required',500);
        {
            $subject=$this->subject($userId,$schoolId,true);$state=$this->current($userId,$schoolId,true);
            demand($state['version_id']===$input['expected_version'],'Special Functions changed. Refresh and review before saving.',409);
            demand(in_array($permission,self::availableFunctionCodes($state['grants'],self::today(),$schoolId,(string)$subject['role']),true),
                'This function is already assigned to the user for this school. Refresh to review their access.',409);
            $id=bin2hex(random_bytes(16));$grant=['grant_id'=>$id,'permission'=>$permission,'school_id'=>$schoolId,
                'starts_on'=>$start,'ends_on'=>$end!==''?$end:null,'status'=>'ACTIVE','actor_id'=>(int)$this->actor['id'],
                'configured_on'=>self::today(),'reason'=>$reason];
            $all=$state['grants'];$all[]=$grant;
            $version=$this->append($schoolId,$userId,$all,$reason,'SPECIAL_FUNCTION_GRANTED',
                ['grant_id'=>$id,'permission'=>$permission,'starts_on'=>$start,'ends_on'=>$end!==''?$end:null,
                    'status_before'=>'NOT_PRESENT','status_after'=>self::isLiveRegisterCode($permission)?'ACTIVE_READ_ONLY_REGISTER':'CONFIGURED_ONLY',
                    'from_version'=>$state['version_id']]);
            return ['grant_id'=>$id,'version_id'=>$version,'register_enforcement_live'=>self::isLiveRegisterCode($permission),'legacy_route_enforcement_live'=>false];
        }
    }
    public function revoke(array $input): array {
        $this->admin();$userId=idOf($input,'user_id');$schoolId=idOf($input,'school_id');
        $grantId=requiredString($input,'grant_id',32);demand((bool)preg_match('/^[a-f0-9]{32}$/D',$grantId),'Invalid grant identifier',400);
        $reason=requiredString($input,'reason',500);demand(mbSafeLen($reason)>=10,'Reason for revocation is required',422);
        demand(is_int($input['expected_version']??null)&&$input['expected_version']>=0,'Refresh current grants before revoking',409);
        // API index owns the database and idempotency transaction.
        demand($this->db->inTransaction(),'Atomic idempotent transaction required',500);
        {
            $this->subject($userId,$schoolId,true);$state=$this->current($userId,$schoolId,true);
            demand($state['version_id']===$input['expected_version'],'Special Functions changed. Refresh before revoking.',409);
            $found=false;$permission='';$all=$state['grants'];
            foreach($all as &$g){if(($g['grant_id']??'')===$grantId && ($g['status']??'')==='ACTIVE'){
                $g['status']='REVOKED';$g['revoked_on']=self::today();$g['revoked_by']=(int)$this->actor['id'];
                $g['revocation_reason']=$reason;$permission=(string)($g['permission']??'');$found=true;break;
            }}unset($g);
            demand($found,'No active configured grant with that ID',409);
            $version=$this->append($schoolId,$userId,$all,$reason,'SPECIAL_FUNCTION_REVOKED',
                ['grant_id'=>$grantId,'permission'=>$permission,'status_before'=>self::isLiveRegisterCode($permission)?'ACTIVE_READ_ONLY_REGISTER':'CONFIGURED_ONLY',
                    'status_after'=>'REVOKED','from_version'=>$state['version_id']]);
            return ['grant_id'=>$grantId,'version_id'=>$version,'register_enforcement_live'=>self::isLiveRegisterCode($permission),'legacy_route_enforcement_live'=>false];
        }
    }
}
