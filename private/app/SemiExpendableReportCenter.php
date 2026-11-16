<?php
declare(strict_types=1);
/**
 * Read-only COA semi-expendable reporting evidence, v0.2.239 review.
 * Uses the installed v0.2.238 explicit ICS/PAR->ISSUE link; does not certify
 * signatures, physical inventory, balances, RRSPs, inspections or disposals.
 */
final class SemiExpendableReportCenter {
    public function __construct(private PDO $db, private array $user) {}

    public static function date(string $raw): bool {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$raw);
        return $d!==false && $d->format('Y-m-d')===$raw;
    }
    public static function linkStatus(array $r): string {
        if((string)$r['status']!=='FINALIZED')return 'NOT_FINALIZED';
        if($r['movement_id']===null)return 'UNLINKED_HISTORICAL_REVIEW';
        if((int)$r['document_asset_id']!==(int)$r['movement_asset_id'] ||
            (int)$r['document_asset_id']!==(int)$r['asset_id'] ||
            (int)$r['organization_id']!==(int)$r['link_organization_id'])return 'LINK_IDENTITY_REVIEW';
        if(isset($r['current_asset_organization_id']) && (int)$r['current_asset_organization_id']!==(int)$r['organization_id'])return 'ASSET_ORGANIZATION_CHANGED_REVIEW';
        if((string)$r['event_type']!=='ISSUE')return 'LINK_EVENT_REVIEW';
        if(isset($r['cutoff_exclusive']) && (string)$r['movement_recorded_at']>=(string)$r['cutoff_exclusive'])return 'MOVEMENT_AFTER_CUTOFF_REVIEW';
        if(trim((string)$r['issued_to'])!==trim((string)$r['custodian']))return 'LINK_CUSTODIAN_REVIEW';
        return 'VERIFIED_ISSUE_EVIDENCE';
    }
    public function load(array $params): array {
        $id=$params['organization_id']??null;
        demand((is_int($id)||is_string($id))&&preg_match('/^[1-9][0-9]*$/D',(string)$id)===1,
            'Choose an authorized organization',400);
        $org=(int)$id;
        demand(($this->user['role']??'')!=='VIEWER','Property-level reports are not available to the document-only viewer',403);
        scope($this->db,$this->user,$org);
        $from=(string)($params['from']??'');$through=(string)($params['through']??'');
        demand(self::date($from)&&self::date($through)&&$from<=$through,'Use a valid inclusive report date range',400);
        $end=(new DateTimeImmutable($through))->modify('+1 day')->format('Y-m-d');
        $organization=one($this->db,"SELECT id,name,kind,code FROM organizations WHERE id=? AND active=1",[$org]);
        demand($organization!==null,'Authorized organization not found or inactive',404);
        // A row represents one physically registered unit; do not multiply by an IAR quantity.
        $assets=q($this->db,"SELECT a.id,a.organization_id,a.property_tag,a.acquisition_cost,
            a.acquired_on,a.opening_record,a.custodian AS current_custodian,a.status AS current_status,
            a.location,a.condition_note,i.name AS item_name,i.category,u.name AS unit
            FROM assets a JOIN item_catalog i ON i.id=a.item_id
            LEFT JOIN units u ON u.id=i.unit_id
            WHERE a.organization_id=? AND i.category='SEMI_EXPENDABLE' AND a.status<>'TRANSFERRED'
            ORDER BY a.id LIMIT 2001",[$org])->fetchAll(PDO::FETCH_ASSOC);
        demand(count($assets)<=2000,'More than 2000 matching assets; this report refuses to truncate records',409);
        $byId=[];foreach($assets as &$asset){
            $asset['id']=(int)$asset['id'];
            $asset['unit_cost_cents']=$asset['acquisition_cost']===null?null:moneyCents($asset['acquisition_cost']);
            $byId[$asset['id']]=$asset;
        }unset($asset);
        // Include cancelled and unlinked historical ICS in the evidence/exception count,
        // but never classify them as finalized verified issuance.
        $documents=q($this->db,"SELECT d.id AS document_id,d.organization_id,d.asset_id AS document_asset_id,
            d.doc_type,d.status,d.display_number,d.custodian,d.created_at AS document_recorded_at,
            a.id AS asset_id,a.organization_id AS current_asset_organization_id,a.property_tag,i.name AS item_name,a.acquisition_cost,u.name AS unit,
            l.organization_id AS link_organization_id,l.asset_movement_id AS movement_id,
            m.asset_id AS movement_asset_id,m.event_type,m.to_custodian AS issued_to,m.created_at AS movement_recorded_at
            FROM accountability_documents d
            JOIN assets a ON a.id=d.asset_id
            JOIN item_catalog i ON i.id=a.item_id AND i.category='SEMI_EXPENDABLE'
            LEFT JOIN units u ON u.id=i.unit_id
            LEFT JOIN accountability_movement_links l ON l.accountability_document_id=d.id
            LEFT JOIN asset_movements m ON m.id=l.asset_movement_id
            WHERE d.organization_id=? AND d.created_at<?
            ORDER BY d.created_at,d.id LIMIT 5001",[$org,$end])->fetchAll(PDO::FETCH_ASSOC);
        demand(count($documents)<=5000,'More than 5000 accountability records; narrow the report or request a paginated export',409);
        $documentByMovement=[];$issueRows=[];$exceptions=[];
        foreach($documents as &$d){
            $d['cutoff_exclusive']=$end;
            $d['link_status']=self::linkStatus($d);
            if($d['movement_id']!==null)$documentByMovement[(int)$d['movement_id']]=$d;
            if($d['link_status']!=='VERIFIED_ISSUE_EVIDENCE')$exceptions[]=$d;
            if((string)$d['doc_type']==='ICS' && (string)$d['status']==='FINALIZED' &&
                (string)$d['document_recorded_at']>=$from && $d['link_status']==='VERIFIED_ISSUE_EVIDENCE'){
                $d['amount_cents']=$d['acquisition_cost']===null?null:moneyCents($d['acquisition_cost']);
                $issueRows[]=$d;
            }
        }unset($d);
        // Custody movements are evidence only: their notes are not a signed ITR/RRSP or stock ledger.
        $movements=q($this->db,"SELECT m.id,m.asset_id,m.event_type,m.from_custodian,m.to_custodian,
            m.created_at,m.reason,a.property_tag,i.name AS item_name
            FROM asset_movements m JOIN assets a ON a.id=m.asset_id
            JOIN item_catalog i ON i.id=a.item_id AND i.category='SEMI_EXPENDABLE'
            WHERE a.organization_id=? AND m.created_at<? ORDER BY m.created_at,m.id LIMIT 5001",[$org,$end])->fetchAll(PDO::FETCH_ASSOC);
        demand(count($movements)<=5000,'More than 5000 property movements; report cannot show incomplete history',409);
        $movementRows=[];
        foreach($movements as $m){
            $link=$documentByMovement[(int)$m['id']]??null;
            $m['document_number']=$link['display_number']??null;
            $m['link_status']=$link['link_status']??'NO_ICS_LINK';
            if((string)$m['created_at']>=$from)$movementRows[]=$m;
        }
        return compact('organization','from','through','assets','documents','issueRows','exceptions','movements','movementRows');
    }
}
