<?php
declare(strict_types=1);
/**
 * Per-finalized-DV NON-IUS school-retained-tax notices to the named Division.
 * Documentary liability handoff only. No bank receipt, BIR settlement, netting,
 * allotment, ledger event, physical check or new tax disposition is created.
 *
 * Source of truth: append-only, typed audit_events referencing immutable DVs.
 * Lock the originating finalized DV to serialize concurrent notice/ack writes;
 * never infer the Division from a browser-supplied school or amount.
 */
final class RetainedTaxHandoff {
    private const NOTICE='SCHOOL_RETAINED_TAX_NOTICE';
    private const ACK='DIVISION_RETAINED_TAX_ACKNOWLEDGED';
    public function __construct(private PDO $db,private array $user) {}
    private function office(int $id,string $kind,bool $write=false): array {
        demand($id>0,'Choose your school or Division Office',422);
        scope($this->db,$this->user,$id);
        $row=one($this->db,'SELECT id,name,kind,parent_division_id,active FROM organizations WHERE id=?',[$id]);
        demand($row!==null && (int)$row['active']===1 && $row['kind']===$kind,
            'Selected office is unavailable or of the wrong type',403);
        $role=(string)($this->user['role']??'');
        demand($write ? $role===$kind : ($role===$kind||$role==='SUPER_ADMIN'),
            'The owning office must perform this action using its own account',403);
        return $row;
    }
    private static function today():string {
        return (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    }
    /** Recalculate all monetary components from the original saved DV, not input. */
    private function source(int $school,int $dvId,bool $lock):array {
        // Lock only the immutable source DV; do not lock its fund account in
        // reverse order to the ordinary DV/check finalization fund mutex.
        $dv=one($this->db,'SELECT * FROM disbursement_vouchers
            WHERE organization_id=? AND id=?'.($lock?' FOR UPDATE':''),[$school,$dvId]);
        demand($dv!==null && $dv['status']==='FINALIZED',
            'Choose an existing finalized school DV',409);
        $fund=one($this->db,'SELECT source_code FROM funds WHERE organization_id=? AND id=?',
            [$school,(int)$dv['fund_id']]);
        demand($fund!==null,'Source of funds is unavailable for the original DV',409);
        $dv['source_code']=(string)$fund['source_code'];
        $components=q($this->db,"SELECT id,atc_code,amount,disposition,fund_id
            FROM withholding_components WHERE dv_id=? ORDER BY id",[$dvId])->fetchAll();
        demand(count($components)>0 && count($components)<=30,
            'The DV must contain 1–30 recorded withholding components',409);
        $cents=0;$lines=[];
        foreach($components as $c){
            demand($c['disposition']==='SCHOOL_UTILIZATION' && (int)$c['fund_id']===(int)$dv['fund_id'],
                'This DV is not entirely in Division-settled school-utilization treatment; use the correct remittance workflow',409);
            $amount=moneyCents((string)$c['amount']);
            demand($amount>0,'Original withholding must be positive',409);
            $cents+=$amount;
            $lines[]=['component_id'=>(int)$c['id'],'atc_code'=>(string)$c['atc_code'],'amount'=>moneyString($amount)];
        }
        demand($cents===moneyCents((string)$dv['deductions']),
            'Saved DV withholding does not reconcile; accounting review required',409);
        $org=one($this->db,"SELECT parent_division_id FROM organizations WHERE id=? AND kind='SCHOOL' AND active=1",[$school]);
        $division=(int)($org['parent_division_id']??0);
        $div=one($this->db,"SELECT id,active FROM organizations WHERE id=? AND kind='DIVISION'",[$division]);
        demand($division>0 && $div!==null && (int)$div['active']===1,
            'This school has no active receiving Division Office',409);
        $version=one($this->db,"SELECT snapshot_json FROM document_versions
            WHERE organization_id=? AND doc_type='DV' AND entity_id=? ORDER BY version_no ASC LIMIT 1",[$school,$dvId]);
        $snap=$version?json_decode((string)$version['snapshot_json'],true,512,JSON_THROW_ON_ERROR):null;
        $policy=$snap['withholding_policy']??null;
        demand(is_array($policy) && ($policy['mode']??'')==='DIVISION_SETTLES_UTILIZABLE'
            && (int)($policy['version_id']??0)>0,
            'Original DV does not contain verifiable Division-retained withholding authority',409);
        $cfg=one($this->db,'SELECT config_json,effective_from FROM configuration_versions WHERE id=? AND organization_id=?',
            [(int)$policy['version_id'],$division]);
        $saved=$cfg?json_decode((string)$cfg['config_json'],true,64,JSON_THROW_ON_ERROR):null;
        demand(is_array($saved) && ($saved['school_class']??'')==='NON_IUS'
            && ($saved['source_code']??'')===(string)$dv['source_code']
            && ($saved['policy']??'')==='DIVISION_SETTLES_UTILIZABLE'
            && trim((string)($saved['authority']??''))!==''
            && (string)$cfg['effective_from']<=(string)$dv['document_date'],
            'Division policy is not historically verified for this school/fund/DV',409);
        return ['dv'=>$dv,'division_id'=>$division,'components'=>$lines,'total'=>moneyString($cents),
            'policy_version_id'=>(int)$policy['version_id']];
    }
    private function existingNotice(int $school,int $dvId):?array {
        return one($this->db,"SELECT id,details,created_at FROM audit_events WHERE organization_id=?
            AND event_type=? AND entity_type='DV' AND entity_id=? ORDER BY id LIMIT 1",
            [$school,self::NOTICE,$dvId]);
    }
    /** All notice creation shares one immutable payload. Never record cash or BIR settlement. */
    private function saveNotice(int $school,int $dvId,array $basis,string $trigger):array {
        $detail=['division_id'=>$basis['division_id'],'dv_id'=>$dvId,
            'document_date'=>(string)$basis['dv']['document_date'],
            'fund_id'=>(int)$basis['dv']['fund_id'],
            'policy_version_id'=>$basis['policy_version_id'],
            'amount'=>$basis['total'],'components'=>$basis['components'],
            'trigger'=>$trigger,
            'cash_transferred'=>false,'bir_paid'=>false,
            'purpose'=>'Division notice of original withholding liability for accounting follow-up'];
        q($this->db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details)
            VALUES (?,?,?,?,?,?)',[$school,(int)$this->user['id'],self::NOTICE,'DV',$dvId,
                json_encode($detail,JSON_THROW_ON_ERROR)]);
        return ['notice_id'=>(int)$this->db->lastInsertId(),'dv_id'=>$dvId,'division_id'=>$basis['division_id'],
            'amount'=>$basis['total'],'status'=>'DIVISION_NOTIFIED_NO_CASH_TRANSFER'];
    }
    /**
     * Called ONLY by Domain::finalizeDV while the fund and original DV rows
     * are locked. A non-school DV or a DV without exclusively school-utilized
     * withholding is not part of this documentary notification route.
     */
    public function ensureFromFinalizedDv(int $organizationId,int $dvId):array {
        demand($this->db->inTransaction(),'Automatic tax notice requires DV finalization transaction',500);
        $office=one($this->db,'SELECT kind,active FROM organizations WHERE id=?',[$organizationId]);
        if($office===null || (int)$office['active']!==1 || $office['kind']!=='SCHOOL')
            return ['status'=>'NOT_APPLICABLE'];
        // Retained withholding and remittances MUST remain separate workflows.
        $counts=one($this->db,"SELECT COUNT(*) n,
                SUM(CASE WHEN disposition='SCHOOL_UTILIZATION' THEN 1 ELSE 0 END) eligible
            FROM withholding_components WHERE dv_id=?",[$dvId]);
        $n=(int)($counts['n']??0);
        if($n===0 || (int)($counts['eligible']??0)!==$n)
            return ['status'=>'NOT_APPLICABLE'];
        $existing=$this->existingNotice($organizationId,$dvId);
        if($existing!==null)return ['status'=>'ALREADY_NOTIFIED','notice_id'=>(int)$existing['id']];
        // Source() checks saved original amounts, ATCs, Division address and
        // the versioned Non-IUS withholding policy; no browser values are used.
        $basis=$this->source($organizationId,$dvId,false);
        return $this->saveNotice($organizationId,$dvId,$basis,'DV_FINALIZED_AUTOMATIC');
    }
    /** Historical finalized DVs may still be notified once by the school. */
    public function notify(array $input):array {
        $school=idOf($input,'organization_id');$this->office($school,'SCHOOL',true);
        $dvId=idOf($input,'dv_id');
        demand($this->db->inTransaction(),'Tax notification must be an atomic, auditable action',500);
        // Same original DV lock protects historical manual backfill and ack.
        $basis=$this->source($school,$dvId,true);
        demand($this->existingNotice($school,$dvId)===null,
            'This finalized DV was already notified to the Division. Refresh the register.',409);
        return $this->saveNotice($school,$dvId,$basis,'HISTORICAL_MANUAL_NOTICE');
    }
    /** Receiving Division sees only notices addressed to THIS Division. */
    public function pendingForDivision(int $division,int $limit=75):array {
        $this->office($division,'DIVISION');
        $limit=max(1,min($limit,75));
        // Match both current office attachment and saved destination; do not let
        // a later school reassignment expose a prior Division's notice.
        $where="a.event_type='".self::NOTICE."' AND a.entity_type='DV'
            AND o.parent_division_id=?
            AND CAST(JSON_UNQUOTE(JSON_EXTRACT(a.details,'$.division_id')) AS UNSIGNED)=?
            AND v.status='FINALIZED'
            AND NOT EXISTS (SELECT 1 FROM audit_events ack WHERE ack.organization_id=?
                AND ack.event_type='".self::ACK."' AND ack.entity_type='TAX_NOTICE' AND ack.entity_id=a.id)";
        $join=' FROM audit_events a
            JOIN organizations o ON o.id=a.organization_id AND o.kind=\'SCHOOL\' AND o.active=1
            JOIN disbursement_vouchers v ON v.id=a.entity_id AND v.organization_id=o.id
            JOIN funds f ON f.id=v.fund_id AND f.organization_id=o.id ';
        $args=[$division,$division,$division];
        $total=(int)one($this->db,'SELECT COUNT(*) n'.$join.' WHERE '.$where,$args)['n'];
        $rows=q($this->db,'SELECT a.id notice_id,a.created_at notified_at,a.details,
                o.id school_id,o.name school_name,v.id dv_id,v.display_number dv_number,
                v.document_date,f.name fund_name,f.source_code fund_code,
                (SELECT MIN(pe.created_at) FROM checks c
                    JOIN payment_events pe ON pe.check_id=c.id AND pe.event_type=\'RELEASED\'
                    WHERE c.organization_id=v.organization_id AND c.dv_id=v.id) check_released_at '.$join.
            ' WHERE '.$where.' ORDER BY a.id DESC LIMIT '.$limit,$args)->fetchAll();
        $result=[];
        foreach($rows as $row){
            $detail=json_decode((string)$row['details'],true,32,JSON_THROW_ON_ERROR);
            demand(is_array($detail)&&isset($detail['amount'],$detail['components'])&&is_array($detail['components']),
                'Division notice evidence requires correction',409);
            unset($row['details']);
            $row['amount']=(string)$detail['amount'];$row['components']=$detail['components'];
            $row['payment_stage']=!empty($row['check_released_at'])
                ?'CHECK_RELEASE_RECORDED':'AWAITING_CHECK_RELEASE';
            $result[]=$row;
        }
        return ['count'=>$total,'rows'=>$result,'truncated'=>$total>count($result)];
    }
    /** Review receipt is NOT BIR remittance, cash receipt, tax clearance or JEV. */
    public function acknowledge(array $input):array {
        $division=idOf($input,'organization_id');$this->office($division,'DIVISION',true);
        $id=idOf($input,'notice_id');
        demand(($input['reviewed']??false)===true,
            'Confirm you reviewed the original school DV withholding before acknowledging',422);
        $note=requiredString($input,'review_note',300);
        demand(mbSafeLen($note)>=12,'Enter a review note of at least 12 characters',422);
        demand($this->db->inTransaction(),'Division acknowledgment must be atomic',500);
        $candidate=one($this->db,"SELECT a.organization_id,a.entity_id,a.details
            FROM audit_events a WHERE a.id=? AND a.event_type=? AND a.entity_type='DV'",[$id,self::NOTICE]);
        demand($candidate!==null,'School tax notice not found',404);
        $school=(int)$candidate['organization_id'];$dv=(int)$candidate['entity_id'];
        $saved=json_decode((string)$candidate['details'],true,32,JSON_THROW_ON_ERROR);
        $schoolOffice=one($this->db,"SELECT parent_division_id FROM organizations
            WHERE id=? AND kind='SCHOOL' AND active=1",[$school]);
        // Reject notices for other divisions BEFORE looking up school DV/tax details.
        demand(is_array($saved) && (int)($saved['division_id']??0)===$division
            && (int)($schoolOffice['parent_division_id']??0)===$division,
            'This withholding notice is not addressed to the selected Division',403);
        $basis=$this->source($school,$dv,true); // lock original DV first, then check ack status
        demand($basis['division_id']===$division
            && (int)($saved['dv_id']??0)===$dv
            && (string)($saved['amount']??'')===$basis['total'],
            'Notice is not addressed to this Division or its original amount changed',403);
        $existing=one($this->db,"SELECT id FROM audit_events WHERE organization_id=?
            AND event_type=? AND entity_type='TAX_NOTICE' AND entity_id=? LIMIT 1",
            [$division,self::ACK,$id]);
        demand($existing===null,'Division has already acknowledged this notice',409);
        $details=['notice_id'=>$id,'school_id'=>$school,'dv_id'=>$dv,
            'amount'=>$basis['total'],'review_note'=>$note,
            'cash_received'=>false,'bir_remitted'=>false,'jev_posted'=>false];
        q($this->db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details)
            VALUES (?,?,?,?,?,?)',[$division,(int)$this->user['id'],self::ACK,'TAX_NOTICE',$id,
                json_encode($details,JSON_THROW_ON_ERROR)]);
        return ['notice_id'=>$id,'dv_id'=>$dv,'status'=>'DIVISION_REVIEW_ACKNOWLEDGED_NO_BIR_SETTLEMENT'];
    }
    public function workspace(array $input):array {
        $org=idOf($input,'organization_id');
        $office=one($this->db,'SELECT kind FROM organizations WHERE id=? AND active=1',[$org]);
        demand($office!==null,'Organization unavailable',404);
        if($office['kind']==='DIVISION')return ['office_kind'=>'DIVISION','incoming'=>$this->pendingForDivision($org)];
        $this->office($org,'SCHOOL');
        // Preserve the long-running per-DV withholding register query. A
        // correlated aggregate over check-release events added to this GROUP BY
        // in v0.2.418 caused a SQL compatibility regression on some hosts.
        $rows=q($this->db,"SELECT v.id dv_id,v.display_number dv_number,v.document_date,
                f.id fund_id,f.name fund_name,f.source_code fund_code,
                COUNT(*) component_count,COALESCE(SUM(w.amount),0) withheld,
                (SELECT e.id FROM audit_events e WHERE e.organization_id=v.organization_id
                 AND e.event_type='".self::NOTICE."' AND e.entity_type='DV' AND e.entity_id=v.id
                 ORDER BY e.id LIMIT 1) notice_id
            FROM disbursement_vouchers v
            JOIN withholding_components w ON w.dv_id=v.id AND w.disposition='SCHOOL_UTILIZATION'
            JOIN funds f ON f.id=v.fund_id AND f.organization_id=v.organization_id
            WHERE v.organization_id=? AND v.status='FINALIZED'
            GROUP BY v.id,v.display_number,v.document_date,f.id,f.name,f.source_code
            ORDER BY v.id DESC LIMIT 75",[$org])->fetchAll();
        // Release is a READ-ONLY projection of existing payment_events. Fetch
        // dates separately for only the 75 authorized DVs above. This avoids
        // a nested aggregate inside the register's grouped SELECT, uses a
        // single bounded query, and never creates or changes any payment event.
        $released=[];
        if($rows!==[]){
            $ids=array_values(array_unique(array_map(static fn(array $row):int=>(int)$row['dv_id'],$rows)));
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $releaseRows=q($this->db,
                "SELECT c.dv_id,MIN(pe.created_at) AS released_at
                 FROM checks c
                 JOIN payment_events pe ON pe.check_id=c.id AND pe.event_type='RELEASED'
                 WHERE c.organization_id=? AND c.dv_id IN (".$marks.")
                 GROUP BY c.dv_id",
                array_merge([$org],$ids))->fetchAll();
            foreach($releaseRows as $release)
                $released[(int)$release['dv_id']]=$release['released_at'];
        }
        foreach($rows as &$row)
            $row['check_released_at']=$released[(int)$row['dv_id']]??null;
        unset($row);
        return ['office_kind'=>'SCHOOL','rows'=>$rows,
            'notice_kind'=>'Documentary Division tax liability notice, not cash remittance or BIR settlement'];
    }
}
