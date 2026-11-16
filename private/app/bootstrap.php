<?php
declare(strict_types=1);
const IPDAMS_ROOT = __DIR__ . '/..';
date_default_timezone_set('Asia/Manila');
function loadEnv(string $path): void {
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key,$val] = explode('=', $line, 2);
        $key = trim($key); $val = trim($val, " \t\n\r\0\x0B\"'");
        if (getenv($key) === false) putenv($key.'='.$val);
    }
}
loadEnv(IPDAMS_ROOT.'/.env');
function cfg(string $key, string $default = ''): string { $v=getenv($key); return $v===false?$default:$v; }
function database(): PDO {
    static $db = null;
    if (!$db) {
        $db = new PDO('mysql:host='.cfg('DB_HOST','localhost').';port='.cfg('DB_PORT','3306').';dbname='.cfg('DB_NAME','ipdams').';charset=utf8mb4',cfg('DB_USER'),cfg('DB_PASSWORD'),[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
        $db->exec("SET time_zone = '+08:00'");
    }
    return $db;
}
final class DomainError extends RuntimeException {
    public function __construct(string $message, public readonly int $status=422) { parent::__construct($message); }
}
function demand(bool $condition, string $message, int $status=422): void { if (!$condition) throw new DomainError($message,$status); }
function moneyCents(mixed $value): int {
    $value=(string)$value;
    demand((bool)preg_match('/^\d{1,16}(\.\d{1,2})?$/',$value),'Invalid monetary amount');
    [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');
    demand(strlen($whole)<=14,'Monetary amount exceeds supported bounds');
    return (int)$whole*100+(int)str_pad($fraction,2,'0');
}
function moneyString(int $cents): string { $sign=$cents<0?'-':''; $abs=abs($cents); return $sign.sprintf('%d.%02d',intdiv($abs,100),$abs%100); }
function qtyMillis(mixed $value): int {
    $value=(string)$value;
    demand((bool)preg_match('/^\d{1,11}(\.\d{1,3})?$/',$value),'Invalid quantity');
    [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');
    return (int)$whole*1000+(int)str_pad($fraction,3,'0');
}
function qtyString(int $n): string { return sprintf('%d.%03d',intdiv($n,1000),$n%1000); }
function totalCents(int $unitCents, int $qtyMilli): int { return intdiv($unitCents*$qtyMilli+500,1000); }
function taxCents(int $baseCents,string $decimalRate): int {
    demand((bool)preg_match('/^0(\.\d{1,6})?$|^1(\.0{1,6})?$/',$decimalRate),'Invalid tax rate');
    [$w,$f]=array_pad(explode('.',$decimalRate,2),2,'');
    $million=(int)$w*1000000+(int)str_pad($f,6,'0');
    return intdiv($baseCents*$million+500000,1000000);
}
function q(PDO $db,string $sql,array $params=[]): PDOStatement { $stmt=$db->prepare($sql); $stmt->execute($params); return $stmt; }
function one(PDO $db,string $sql,array $params=[]): ?array { $row=q($db,$sql,$params)->fetch(); return $row===false?null:$row; }
function requiredString(array $data,string $key,int $max=240): string {
    $s=trim((string)($data[$key]??''));
    demand($s!=='' && mbSafeLen($s)<=$max,"Invalid or missing $key"); return $s;
}
function mbSafeLen(string $s): int { return function_exists('mb_strlen')?mb_strlen($s):strlen($s); }
function idOf(array $data,string $key): int {
    $v=$data[$key]??null; demand(is_int($v)||is_string($v)&&ctype_digit($v),"Invalid $key");
    demand((int)$v>0,"Invalid $key"); return (int)$v;
}
function rowsRequired(array $d,string $key): array { demand(isset($d[$key])&&is_array($d[$key])&&count($d[$key])>0&&count($d[$key])<=100,"Required nonempty $key (max 100)"); return $d[$key]; }
function ipdamsCredentialStamp(string $passwordHash): string {
    return hash('sha256','IPDAMS-credential-epoch-v1\0'.$passwordHash);
}
function currentUser(PDO $db): array {
    demand(isset($_SESSION['uid']),'Sign in to continue',401);
    // Each PHP entrypoint calls currentUser(); compare the login credential epoch
    // with the current password hash on every authenticated request. A password
    // reset invalidates all *other* sessions for that account on their next request.
    $u=one($db,'SELECT id,email,full_name,role,active,password_hash FROM users WHERE id=?',[(int)$_SESSION['uid']]);
    demand($u!==null&&(int)$u['active']===1,'Session expired',401);
    $expected=ipdamsCredentialStamp((string)$u['password_hash']);
    demand(is_string($_SESSION['credential_stamp']??null) &&
        hash_equals($expected,$_SESSION['credential_stamp']),
        'Session expired. Please sign in again.',401);
    unset($u['password_hash']); // Never include credential hashes in API/domain user payloads.
    return $u;
}
/** School-scoped Division MOOE viewer scope is derived on EVERY request from the
 * effective Division assignment, never from stale stored user_organization_scopes.
 * An unbound VIEWER fails closed; ordinary administrator semantics do not change. */
function ipdamsMooeViewerSchools(PDO $db,array $user): array {
    demand(($user['role']??'')==='VIEWER','Not a viewer account',403);
    $userId=(int)($user['id']??0);
    demand($userId>0,'Invalid viewer identity',403);
    $link=one($db,"SELECT c.organization_id,c.config_json FROM configuration_versions c
        JOIN organizations d ON d.id=c.organization_id AND d.kind='DIVISION' AND d.active=1
        WHERE c.config_key=? ORDER BY c.id DESC LIMIT 1",['MOOE_VIEWER_USER_V1_'.$userId]);
    demand($link!==null,'Viewer account is not assigned to a Division employee',403);
    $value=json_decode((string)$link['config_json'],true);
    $division=(int)($link['organization_id']??0);
    $employee=(int)($value['employee_id']??0);
    demand(is_array($value) && (int)($value['user_id']??0)===$userId &&
        (int)($value['division_id']??0)===$division && $employee>0,
        'Viewer account assignment needs administrator verification',403);
    $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    // An expired, inactivated or unassigned employee receives no school access.
    $active=one($db,"SELECT e.id FROM employees e JOIN employee_assignments a ON a.employee_id=e.id
        WHERE e.id=? AND a.organization_id=?
        AND a.effective_from<=? AND (a.effective_to IS NULL OR a.effective_to>?)
        ORDER BY a.id DESC LIMIT 1",[$employee,$division,$today,$today]);
    if($active===null)return [];
    $rows=q($db,"SELECT o.id,c.config_json FROM organizations o
        JOIN configuration_versions c ON c.organization_id=o.id
        AND c.config_key='SCHOOL_DIVISION_MOOE_POSTING_OFFICER_V1'
        AND c.effective_from<=?
        WHERE o.kind='SCHOOL' AND o.active=1 AND o.parent_division_id=?
        AND c.id=(SELECT newer.id FROM configuration_versions newer
            WHERE newer.organization_id=o.id AND newer.config_key=c.config_key
            AND newer.effective_from<=? ORDER BY newer.effective_from DESC,newer.id DESC LIMIT 1)
        ORDER BY o.name,o.id",[$today,$division,$today])->fetchAll();
    $ids=[];
    foreach($rows as $row){
        $assignment=json_decode((string)$row['config_json'],true);
        if(is_array($assignment) && (int)($assignment['employee_id']??0)===$employee
            && (int)($assignment['division_id']??0)===$division)$ids[]=(int)$row['id'];
    }
    return $ids;
}
function scope(PDO $db,array $user,int $org): void {
    if($user['role']==='SUPER_ADMIN') return;
    if(($user['role']??'')==='VIEWER'){
        demand(in_array($org,ipdamsMooeViewerSchools($db,$user),true),
            'Access denied for school workspace',403);
        return;
    }
    $ok=one($db,'SELECT 1 FROM user_organization_scopes WHERE user_id=? AND organization_id=?',[(int)$user['id'],$org]);
    demand($ok!==null,'Access denied for organization',403);
}
/** The only inventory/property information MOOE VIEWER may fetch is the
 * official issued document number, type and period. No asset tags, stock or
 * ledger rows are returned. The RSMI reporter is not yet implemented. */
function ipdamsMooeIssuedDocuments(PDO $db,array $user,int $org,string $month): array {
    demand(($user['role']??'')==='VIEWER','Read-only reviewer required',403);
    scope($db,$user,$org);
    demand(preg_match('/^(19|20|21)\d{2}-(0[1-9]|1[0-2])$/D',$month)===1,
        'Select a valid report month (YYYY-MM)',400);
    $rows=q($db,"SELECT d.doc_type,d.entity_id AS id,d.display_number,d.period,d.created_at
        FROM document_registry d
        WHERE d.organization_id=? AND d.period=? AND
          ((d.doc_type='RIS' AND EXISTS(SELECT 1 FROM requisition_issue_slips r
              WHERE r.id=d.entity_id AND r.organization_id=d.organization_id AND r.status='ISSUED'))
          OR (d.doc_type IN ('ICS','PAR') AND EXISTS(SELECT 1 FROM accountability_documents a
              WHERE a.id=d.entity_id AND a.organization_id=d.organization_id
                AND a.doc_type=d.doc_type AND a.status='FINALIZED')))
        ORDER BY d.created_at,d.id LIMIT 501",[$org,$month])->fetchAll();
    demand(count($rows)<=500,'Too many issued documents this month; administrative review required',409);
    return ['month'=>$month,'rows'=>$rows,'rsmi_available'=>false];
}
/** A direct PDF or HTML link must apply exactly the same authorization as the
 * issued-only monthly document register. Missing month fails closed. */
function ipdamsMooeViewerRequireIssuedDocument(PDO $db,array $user,int $org,string $type,int $id,string $month): void {
    demand(in_array($type,['RIS','ICS','PAR'],true),'Document is outside reviewer access',403);
    demand($id>0,'Invalid document',403);
    $result=ipdamsMooeIssuedDocuments($db,$user,$org,$month);
    $ok=false;
    foreach($result['rows'] as $row){
        if((string)$row['doc_type']===$type && (int)$row['id']===$id){$ok=true;break;}
    }
    demand($ok,'Issued document unavailable for selected month or school',403);
}
function allowedToWrite(array $user): void { demand($user['role']!=='VIEWER','Read-only account',403); }
function csrfCheck(): void {
    $provided=$_SERVER['HTTP_X_CSRF_TOKEN']??'';
    demand(is_string($provided)&&hash_equals((string)($_SESSION['csrf']??''),$provided),'CSRF token invalid',403);
}
function jsonOut(mixed $data,int $status=200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['data'=>$data],JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
