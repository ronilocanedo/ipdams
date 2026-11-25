<?php
declare(strict_types=1);
/** No-SMTP password recovery; school and Division scopes are authoritative. */
final class PasswordRecovery {
    private const MAX_PENDING_AGE_HOURS = 168;
    private const TEMP_LIFETIME_HOURS = 24;
    public function __construct(private PDO $db) {}
    /** Use the exact login identifier, including the email domain when present. */
    private static function loginIdentifier(string $email): string {
        $loginId=strtolower(trim($email));
        demand(strlen($loginId)>=3 && strlen($loginId)<=190 &&
            preg_match('/^[a-z0-9._+@\-]+$/D',$loginId)===1,
            'The account identifier cannot form a temporary password',422);
        return $loginId;
    }
    /** A school login must resolve to one school, a Division login to one Division. */
    private function owningOffice(int $uid,string $role):array {
        demand(in_array($role,['SCHOOL','DIVISION','VIEWER'],true),'This account requires administrator recovery',403);
        $kind=$role==='VIEWER'?'DIVISION':$role;
        $rows=q($this->db,'SELECT o.id,o.kind,o.code,o.parent_division_id FROM organizations o
          JOIN user_organization_scopes s ON s.organization_id=o.id
          WHERE s.user_id=? AND o.kind=? AND o.active=1 ORDER BY o.id LIMIT 2',[$uid,$kind])->fetchAll();
        demand(count($rows)===1,'This account has no single authorized office; contact the administrator',409);
        $office=$rows[0];
        $div=$kind==='DIVISION'?(int)$office['id']:(int)$office['parent_division_id'];
        demand($div>0,'Division assignment is missing',409);
        $division=one($this->db,"SELECT id FROM organizations WHERE id=? AND kind='DIVISION' AND active=1",[$div]);
        demand($division!==null,'Assigned Division is unavailable',409);
        return ['division_id'=>$div,'school_id'=>$kind==='SCHOOL'?(int)$office['id']:null,
                'office_code'=>(string)$office['code']];
    }
    /** Persist public request attempts before account lookup, including unknown users.
     * Do not trust browser session limits or client-supplied forwarding headers.
     * The attempt is committed independently so error responses cannot roll it back.
     */
    public function throttlePublicRequest(string $identifier,string $remoteAddress):void {
        $identifier=strtolower(trim($identifier));
        $ip=trim($remoteAddress);if($ip==='')$ip='unknown';
        $ipHash=hash('sha256','ipdams-recovery-ip-v1|'.$ip);
        $idHash=hash('sha256','ipdams-recovery-id-v1|'.$identifier);
        $this->db->beginTransaction();
        try {
            // One row per attempt; prune old rows opportunistically, not on every request.
            $this->db->exec("DELETE FROM password_reset_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 7 DAY) LIMIT 100");
            q($this->db,'INSERT INTO password_reset_attempts(ip_hash,identifier_hash) VALUES (?,?)',[$ipHash,$idHash]);
            $ipCount=(int)one($this->db,"SELECT COUNT(*) n FROM password_reset_attempts
                WHERE ip_hash=? AND attempted_at >= DATE_SUB(NOW(),INTERVAL 1 HOUR)",[$ipHash])['n'];
            $idCount=(int)one($this->db,"SELECT COUNT(*) n FROM password_reset_attempts
                WHERE identifier_hash=? AND attempted_at >= DATE_SUB(NOW(),INTERVAL 1 HOUR)",[$idHash])['n'];
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        demand($ipCount<=12 && $idCount<=5,
            'Too many password recovery attempts. Contact your Division Office or retry later.',429);
    }
    public function request(string $identifier):array {
        $identifier=strtolower(trim($identifier));
        demand(strlen($identifier)>=3&&strlen($identifier)<=190&&preg_match('/^[a-z0-9._+@\-]+$/D',$identifier)===1,
            'Enter a valid username or existing login ID',422);
        $results= str_contains($identifier,'@')
          ? q($this->db,'SELECT id,email,role,active FROM users WHERE email=? LIMIT 2',[$identifier])->fetchAll()
          : q($this->db,"SELECT id,email,role,active FROM users WHERE LOWER(SUBSTRING_INDEX(email,'@',1))=? LIMIT 2",[$identifier])->fetchAll();
        demand(count($results)===1 && (int)$results[0]['active']===1 &&
            in_array($results[0]['role'],['SCHOOL','DIVISION','VIEWER'],true),'Username not found',404);
        $user=$results[0];$owner=$this->owningOffice((int)$user['id'],(string)$user['role']);
        $this->db->beginTransaction();
        try {
            $locked=one($this->db,'SELECT id FROM users WHERE id=? AND active=1 FOR UPDATE',[$user['id']]);
            demand($locked!==null,'Account no longer active',409);
            $recent=one($this->db,"SELECT id,status,requested_at FROM password_reset_requests WHERE user_id=?
              ORDER BY id DESC LIMIT 1 FOR UPDATE",[$user['id']]);
            if($recent) {
                $age=strtotime((string)$recent['requested_at']);
                if($recent['status']==='APPROVED'){
                    $latest=one($this->db,"SELECT expires_at FROM password_reset_requests WHERE id=? FOR UPDATE",[(int)$recent['id']]);
                    if($latest && $latest['expires_at']!==null && strtotime((string)$latest['expires_at'])<time()){
                        q($this->db,"UPDATE password_reset_requests SET status='EXPIRED' WHERE id=? AND status='APPROVED'",[(int)$recent['id']]);
                        $recent['status']='EXPIRED';
                    }
                }
                if($recent['status']==='PENDING' && ($age===false||$age<=time()-self::MAX_PENDING_AGE_HOURS*3600)){
                    q($this->db,"UPDATE password_reset_requests SET status='EXPIRED' WHERE id=? AND status='PENDING'",[(int)$recent['id']]);
                    $recent['status']='EXPIRED';
                }
                if($recent['status']==='PENDING'&&$age!==false&&$age>time()-self::MAX_PENDING_AGE_HOURS*3600)
                    demand(false,'A reset request for this account is already pending Division review',409);
                if($age!==false&&$age>time()-300)
                    demand(false,'A recent request already exists. Please contact your Division Office.',429);
                if($recent['status']==='APPROVED')
                    demand(false,'A reset was recently approved. Contact your Division Office for the temporary-password instructions.',409);
            }
            q($this->db,"INSERT INTO password_reset_requests(user_id,division_id,school_id,status)
                VALUES (?,?,?,'PENDING')",[(int)$user['id'],$owner['division_id'],$owner['school_id']]);
            $id=(int)$this->db->lastInsertId();
            q($this->db,"INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details)
                VALUES (?,?,'PASSWORD_RESET_REQUESTED','USER',?,?)",
                [$owner['division_id'],0,(int)$user['id'],json_encode(['request_id'=>$id,'delivery'=>'IN_APP_ONLY','requester'=>'UNVERIFIED_PUBLIC_IDENTIFIER'],JSON_THROW_ON_ERROR)]);
            $this->db->commit();return ['request_id'=>$id,'status'=>'PENDING','message'=>'Request sent to your Division Action Center. No email is required.'];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function divisionReviewer(array $actor,int $division):void {
        // The system owner signs in as SUPER_ADMIN; no separate OWNER role exists.
        // Division accounts remain strictly office-scoped. Super Admin may review
        // any active Division without acquiring financial posting privileges.
        demand(in_array(($actor['role']??''),['DIVISION','SUPER_ADMIN'],true),
            'An authorized Division user or Super Administrator must review reset requests',403);
        scope($this->db,$actor,$division);
        demand(one($this->db,"SELECT 1 FROM organizations WHERE id=? AND kind='DIVISION' AND active=1",[$division])!==null,
            'Active Division Office required',403);
    }
    public function pendingCount(array $actor,int $division):int {
        $this->divisionReviewer($actor,$division);
        return (int)one($this->db,"SELECT COUNT(*) n FROM password_reset_requests
            WHERE division_id=? AND status='PENDING' AND requested_at>=DATE_SUB(NOW(),INTERVAL 168 HOUR) AND user_id<>?",[$division,(int)$actor['id']])['n'];
    }
    public function pending(array $actor,int $division):array {
        $this->divisionReviewer($actor,$division);
        return q($this->db,"SELECT r.id,r.user_id,r.requested_at,u.full_name,u.email,u.role,
          o.name AS school_name,o.code AS office_code FROM password_reset_requests r
          JOIN users u ON u.id=r.user_id AND u.active=1
          LEFT JOIN organizations o ON o.id=COALESCE(r.school_id,r.division_id)
          WHERE r.division_id=? AND r.status='PENDING' AND r.requested_at>=DATE_SUB(NOW(),INTERVAL 168 HOUR) AND r.user_id<>?
          ORDER BY r.id ASC LIMIT 100",[$division,(int)$actor['id']])->fetchAll();
    }
    public function approve(array $actor,int $division,int $requestId):array {
        // One-click approval still requires a live authenticated Division/Super Admin
        // session, CSRF validation at the endpoint, scope checks and a distinct reviewer.
        $this->divisionReviewer($actor,$division);
        demand($requestId>0,'Select a reset request',422);
        $this->db->beginTransaction();
        try {
            $reviewer=one($this->db,'SELECT id FROM users WHERE id=? AND active=1 FOR UPDATE',[(int)$actor['id']]);
            demand($reviewer!==null,'Approver account is inactive',403);
            $r=one($this->db,"SELECT * FROM password_reset_requests
                WHERE id=? AND division_id=? FOR UPDATE",[$requestId,$division]);
            demand($r!==null&&$r['status']==='PENDING' && strtotime((string)$r['requested_at'])>=time()-self::MAX_PENDING_AGE_HOURS*3600,'Request is unavailable or has expired',409);
            demand((int)$r['user_id']!==(int)$actor['id'],'You cannot approve your own reset request',403);
            $target=one($this->db,'SELECT id,email,role,active,password_hash FROM users WHERE id=? FOR UPDATE',[(int)$r['user_id']]);
            demand($target!==null&&(int)$target['active']===1,'Target account is unavailable',409);
            $owner=$this->owningOffice((int)$target['id'],(string)$target['role']);
            demand($owner['division_id']===$division && $owner['school_id']===($r['school_id']===null?null:(int)$r['school_id']),
                'Target account office has changed; reset cannot proceed',409);
            $temp=self::loginIdentifier((string)$target['email']).$owner['office_code'];
            demand(strlen($temp)>=8 && strlen($temp)<=256,'The temporary credential is invalid; contact system administrator',409);
            $hash=password_hash($temp,PASSWORD_DEFAULT);
            demand(is_string($hash)&&$hash!=='','Password hashing failed',503);
            q($this->db,'UPDATE users SET password_hash=? WHERE id=? AND active=1',[$hash,(int)$target['id']]);
            q($this->db,"UPDATE password_reset_requests SET status='APPROVED',approved_by=?,decided_at=NOW(),
                temporary_credential_stamp=?,
                expires_at=DATE_ADD(NOW(),INTERVAL ".self::TEMP_LIFETIME_HOURS." HOUR) WHERE id=? AND status='PENDING'",
                [(int)$actor['id'],ipdamsCredentialStamp($hash),$requestId]);
            q($this->db,"UPDATE password_reset_requests SET status='EXPIRED' WHERE user_id=? AND id<>? AND status='APPROVED'",[(int)$target['id'],$requestId]);
            q($this->db,"INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details)
                VALUES (?,?,'PASSWORD_RESET_APPROVED','USER',?,?)",
                [$division,(int)$actor['id'],(int)$target['id'],json_encode(['request_id'=>$requestId,'force_change'=>true,'one_use'=>true,'approval_method'=>(($actor['role']??'')==='SUPER_ADMIN'?'AUTHENTICATED_SUPER_ADMIN_SESSION':'AUTHENTICATED_DIVISION_SESSION')],JSON_THROW_ON_ERROR)]);
            $this->db->commit();
            return ['approved'=>true,'request_id'=>$requestId,'expires_in_hours'=>self::TEMP_LIFETIME_HOURS,
                'message'=>'Approved. Instruct the account owner to sign in with the full login ID (including the email domain) plus office ID, then immediately set a new password.'];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    /** Called after password_verify, before a session is authenticated. */
    public function activeTemporaryCredential(int $uid,string $currentPasswordHash):bool {
        $r=one($this->db,"SELECT id,status,expires_at,temporary_credential_stamp
          FROM password_reset_requests WHERE user_id=? AND temporary_credential_stamp=?
          AND status IN ('APPROVED','EXPIRED','COMPLETED') ORDER BY id DESC LIMIT 1",[$uid,ipdamsCredentialStamp($currentPasswordHash)]);
        if(!$r)return false;
        // If a different authorized channel reset the password later, do not
        // force the unrelated new credential into this obsolete request.
        if(!is_string($r['temporary_credential_stamp']) ||
            !hash_equals($r['temporary_credential_stamp'],ipdamsCredentialStamp($currentPasswordHash)))return false;
        demand($r['status']==='APPROVED' && strtotime((string)$r['expires_at'])>=time(),
            'Temporary password expired. Request a new Division-approved reset.',403);
        return true;
    }
    /** Must be called inside the same transaction as the password hash change. */
    public function markCompleted(int $uid,int $actorId):void {
        q($this->db,"UPDATE password_reset_requests SET status='COMPLETED',completed_at=NOW()
            WHERE user_id=? AND status='APPROVED'",[$uid]);
        q($this->db,"INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details)
            VALUES (NULL,?,'PASSWORD_RESET_COMPLETED','USER',?,'{}')",[$actorId,$uid]);
    }
}
