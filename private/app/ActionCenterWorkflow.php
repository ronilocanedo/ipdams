<?php
declare(strict_types=1);
/**
 * Read-only, organization-scoped work queue. Notifications are derived from the
 * verified latest transfer document, never written as a parallel status ledger.
 */
require_once __DIR__.'/PropertyTransferWorkflow.php';
require_once __DIR__.'/PropertyTransferAcceptance.php';
require_once __DIR__.'/TextbookTransferWorkflow.php';
final class ActionCenterWorkflow {
    public function __construct(private PDO $db,private array $user) {}
    public function forOrganization(int $organizationId):array {
        demand($organizationId>0,'Select an active organization',422);
        demand(in_array($this->user['role']??'', ['SCHOOL','DIVISION','SUPER_ADMIN'],true),
            'Action Center requires an authorized office account',403);
        scope($this->db,$this->user,$organizationId);
        $org=one($this->db,"SELECT id,name,kind,active FROM organizations WHERE id=?",[$organizationId]);
        demand($org!==null&&(int)$org['active']===1&&in_array($org['kind'],['SCHOOL','DIVISION'],true),
            'The selected receiving office is not active',403);
        demand($this->user['role']==='SUPER_ADMIN'||$this->user['role']===$org['kind'],
            'Select your authorized receiving school or Division Office workspace',403);
        $workflow=new PropertyTransferWorkflow($this->db,$this->user);
        $results=$org['kind']==='DIVISION'
            ?$workflow->incomingForDivision($organizationId)
            :$workflow->incomingForSchool($organizationId);
        $entries=$results['incoming']??$results;
        demand(is_array($entries)&&count($entries)<100,
            'Incoming transfer queue requires pagination; contact the technical administrator',409);
        $canAccept=false;
        if($this->user['role']===$org['kind']){
            // The existing appointed custodian + binding is authoritative. Do
            // not grant action authority based on visibility or SUPER_ADMIN role.
            try {
                $binding=(new PropertyTransferAcceptance($this->db,$this->user))
                    ->bindingOptions($organizationId);
                $canAccept=(int)($binding['binding']['user_id']??0)===(int)$this->user['id']
                    &&(int)($binding['binding']['employee_id']??0)===(int)($binding['appointment']['employee_id']??0)
                    &&(int)($binding['binding']['appointment_version']??0)===(int)($binding['appointment']['appointment_version']??0);
            }catch(DomainError $e){$canAccept=false;}
        }
        $pending=[];
        foreach($entries as $row){
            $s=$row['snapshot']??null;
            if(!is_array($s)||($s['status']??'')!=='PENDING_RECEIPT'||
                ($s['transfer_kind']??'')!=='EXTERNAL'||
                !empty($s['external_destination_unverified'])||
                (int)($s['destination_organization_id']??0)!==$organizationId||
                (int)($s['source_school_id']??0)!==(int)($row['organization_id']??$s['source_school_id']??0))continue;
            $pending[]=[
                'id'=>(int)$row['id'],
                'type'=>(string)$row['type'],
                'number'=>(string)$row['number'],
                'source'=>(string)($s['source_school_name']??'Sending office'),
                'items'=>count(is_array($s['items']??null)?$s['items']:[]),
                'status'=>'AWAITING_RECEIPT',
                'can_accept'=>$canAccept,
            ];
        }
        $textbook=new TextbookTransferWorkflow($this->db,$this->user);
        $bookReadAllowed=($this->user['role']??'')===$org['kind'];
        $bookIncoming=$bookReadAllowed && $org['kind']==='SCHOOL'?$textbook->pendingForSchool($organizationId):[];
        $bookOutgoing=$bookReadAllowed?$textbook->pendingForSource($organizationId):[];
        $textbookActions=array_merge($bookIncoming,$bookOutgoing);
        // Division sees school proposals awaiting a written disposal decision; no e-signature workflow.
        if($bookReadAllowed && $org['kind']==='DIVISION'){
            $disposals=(new TextbookLifecycleWorkflow($this->db,$this->user))->disposalList(['organization_id'=>$organizationId]);
            foreach($disposals['rows'] as $p){
                if($p['status']!=='MARKED')continue;
                $textbookActions[]=['id'=>$p['id'],'number'=>$p['number'],
                    'source'=>$p['source'],'status'=>'MARKED FOR DISPOSAL',
                    'task'=>'DISPOSAL_REVIEW','quantity'=>$p['remaining']];
            }
        }
        // Derive unconfirmed withholding from finalized vouchers; no parallel
        // notification table, state change or new spending authority. REMIT
        // and SCHOOL_UTILIZATION have distinct completion requirements.
        $taxCount=(int)one($this->db,"SELECT COUNT(*) AS n FROM withholding_components w
            JOIN disbursement_vouchers d ON d.id=w.dv_id
            WHERE d.organization_id=? AND d.status='FINALIZED'
              AND NOT EXISTS (SELECT 1 FROM tax_disposition_events e
                WHERE e.component_id=w.id AND e.state IN ('CONFIRMED_CREDIT','REMITTED'))",
            [$organizationId])['n'];
        $taxRows=q($this->db,"SELECT w.id,w.amount,w.atc_code,w.disposition,
                d.id AS dv_id,d.display_number AS dv_number,d.document_date,
                f.source_code AS fund_code,f.fiscal_year
            FROM withholding_components w
            JOIN disbursement_vouchers d ON d.id=w.dv_id
            JOIN funds f ON f.id=d.fund_id AND f.organization_id=d.organization_id
            WHERE d.organization_id=? AND d.status='FINALIZED'
              AND NOT EXISTS (SELECT 1 FROM tax_disposition_events e
                WHERE e.component_id=w.id AND e.state IN ('CONFIRMED_CREDIT','REMITTED'))
            ORDER BY d.id DESC,w.id DESC LIMIT 75",[$organizationId])->fetchAll();
        $taxActions=array_map(static fn($v)=>[
            'component_id'=>(int)$v['id'],'amount'=>(string)$v['amount'],
            'atc_code'=>(string)$v['atc_code'],'disposition'=>(string)$v['disposition'],
            'dv_id'=>(int)$v['dv_id'],'dv_number'=>(string)($v['dv_number']??'#'.$v['dv_id']),
            'document_date'=>(string)($v['document_date']??''),'fund_code'=>(string)$v['fund_code'],
            'fiscal_year'=>(int)$v['fiscal_year'],
            'task'=>$v['disposition']==='SCHOOL_UTILIZATION'?'CONFIRM_WITHHOLDING':'REMIT_TAX',
        ],$taxRows);
        // v0.2.400 Division-only incoming school remittance queue.
        // Source status is the authoritative tax-transfer record: no parallel
        // notification rows, no implicit bank receipt, no auto-confirmation.
        $incomingTax=[];$incomingTaxCount=0;
        if($org['kind']==='DIVISION'){
            $incomingTaxCount=(int)one($this->db,"SELECT COUNT(*) n FROM school_tax_transfers
                WHERE division_id=? AND status='CHECK_RELEASED'",[$organizationId])['n'];
            $incomingTax=q($this->db,"SELECT t.id transfer_id,t.organization_id school_id,
                    o.name school_name,v.id dv_id,v.display_number dv_number,
                    v.document_date,v.gross,t.status,
                    f.source_code fund_code,
                    (SELECT COUNT(*) FROM school_tax_transfer_components tc WHERE tc.transfer_id=t.id) component_count,
                    (SELECT c.check_number FROM checks c WHERE c.dv_id=t.dv_id AND c.organization_id=t.organization_id ORDER BY c.id DESC LIMIT 1) check_number
                FROM school_tax_transfers t
                JOIN organizations o ON o.id=t.organization_id AND o.parent_division_id=t.division_id AND o.kind='SCHOOL'
                JOIN disbursement_vouchers v ON v.id=t.dv_id AND v.organization_id=t.organization_id
                JOIN funds f ON f.id=t.fund_id AND f.organization_id=t.organization_id
                WHERE t.division_id=? AND t.status='CHECK_RELEASED'
                ORDER BY t.id DESC LIMIT 75",[$organizationId])->fetchAll();
        }
        return ['organization_id'=>$organizationId,'kind'=>$org['kind'],
            'pending_count'=>count($pending)+count($textbookActions)+$taxCount+$incomingTaxCount,'incoming_transfers'=>$pending,
            'textbook_actions'=>$textbookActions,'pending_tax_actions'=>$taxActions,
            'incoming_tax_remittances'=>$incomingTax,'incoming_tax_remittance_count'=>$incomingTaxCount,
            'pending_tax_count'=>$taxCount,'pending_tax_truncated'=>$taxCount>count($taxActions),
            'property_pending_count'=>count($pending),
            'checked_at'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format(DATE_ATOM)];
    }
}
