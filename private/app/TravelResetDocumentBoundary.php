<?php
declare(strict_types=1);
/**
 * IPDAMS Travel & Training custom reset safety gate — v0.2.223 staging implementation.
 * Read-only guards for selected document workflows; no writes, no public entrypoint.
 * This code has NO DELETE/UPDATE/INSERT/HTTP operations.
 */
final class IPDAMSTravelResetBoundary {
    public const DOCUMENT_TYPES = ['AR','TA','RC'];

    /** Reject committed financial activity even when nested in a version snapshot. */
    public static function hasFinancialEvidence(mixed $payload, int $depth=0): bool {
        if ($depth > 32) return true; // Unknown excessively deep content: fail closed.
        if (!is_array($payload)) return false;
        foreach ($payload as $rawKey => $value) {
            if (is_string($rawKey)) {
                $key=strtolower(preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $rawKey));
                $key=preg_replace('/[^a-z0-9]+/', '_', $key);
                $key=trim($key,'_');
                // Payment route/method are documentary planning fields, not evidence of a transaction.
                // They appear on legitimate AR/TA snapshots; do not classify them as paid.
                if (in_array($key,['payment_route','payment_method','payment_arrangement','payment_source'],true)) {
                    if(is_array($value) && self::hasFinancialEvidence($value,$depth+1)) return true;
                    continue;
                }
                $recognized=(bool)preg_match('/^(dv|disbursement_voucher|voucher|check|payment|paid|bank_transaction|fund_event|obligation|liquidation|cash_advance|accounting_posted|ledger_posting|settlement)(_|$)/',$key)
                    || (bool)preg_match('/^(posted_at|posted_amount|paid_at|paid_amount|finance_approval|accounting_entry)(_|$)/',$key);
                if ($recognized && self::isEvidenceValue($value)) return true;
            }
            if (is_array($value) && self::hasFinancialEvidence($value,$depth+1))return true;
        }
        return false;
    }
    private static function isEvidenceValue(mixed $value): bool {
        if ($value === null || $value === false || $value === 0 || $value === 0.0 || $value === '' || $value === '0') return false;
        if (is_string($value)) {
            $normalized=strtoupper(trim($value));
            if (in_array($normalized,['','NONE','NULL','NO','FALSE','UNPAID','NOT_PAID','NOT AUTHORIZED','NOT_AUTHORIZED','DRAFT'],true)) return false;
        }
        // Unknown array/map is evidence, even if empty: do not silently ignore future schema.
        return true;
    }
    private static function fail(string $message): never { throw new DomainException('Travel reset blocked: '.$message); }

    /**
     * $registry rows: organization_id,doc_type,id
     * $links rows: organization_id,source_type,source_id,target_type,target_id
     * $versions rows: organization_id,doc_type,entity_id,snapshot_json
     * This always scans historical versions, not only the latest version.
     */
    public static function inspect(array $registry,array $links,array $versions): array {
        $known=[]; $seenVersion=[]; $docCount=0; $versionCount=0; $linkCount=0;
        foreach($registry as $row) {
            if (!self::isTravelType((string)($row['doc_type']??'')))continue;
            // document_registry.id may differ from entity_id; links and versions refer to entity_id.
            $key=self::rowKey($row['organization_id']??null,$row['doc_type'],$row['entity_id']??null);
            if(isset($known[$key]))self::fail('duplicate document registry identity');
            $known[$key]=true; $docCount++;
        }
        foreach($links as $row) {
            $source=(string)($row['source_type']??'');$target=(string)($row['target_type']??'');
            $src=self::isTravelType($source);$dst=self::isTravelType($target);
            if (!$src && !$dst)continue;
            $linkCount++;
            if (!$src || !$dst)self::fail('a travel record links to an unrelated document type');
            if (!(($source==='AR' && $target==='TA') || ($source==='TA' && $target==='RC')))
                self::fail('unexpected Travel & Training document linkage');
            $org=$row['organization_id']??null;
            $sourceKey=self::rowKey($org,$source,$row['source_id']??null);
            $targetKey=self::rowKey($org,$target,$row['target_id']??null);
            if(!isset($known[$sourceKey]) || !isset($known[$targetKey]))self::fail('a travel link refers to a missing or cross-school document');
        }
        foreach($versions as $row) {
            $type=(string)($row['doc_type']??'');if(!self::isTravelType($type))continue;
            $versionCount++;
            $org=$row['organization_id']??null;$doc=$row['entity_id']??null;
            $key=self::rowKey($org,$type,$doc);
            if(!isset($known[$key]))self::fail('an orphaned document version was found');
            $seenVersion[$key]=true;
            try{$snapshot=json_decode((string)($row['snapshot_json']??''),true,512,JSON_THROW_ON_ERROR);}
            catch(Throwable $e){self::fail('a document snapshot cannot be decoded');}
            if (!is_array($snapshot) || array_is_list($snapshot)) self::fail('document snapshot is not an object');
            if (($snapshot['kind']??null)!==$type || !isset($snapshot['organization_id']) ||
                (int)$snapshot['organization_id']!==(int)$org)self::fail('a snapshot has a mismatched document identity or school');
            // The current known RC schema is documentary-only. Future financial marks need coordinated reset review.
            if(self::hasFinancialEvidence($snapshot))self::fail('a travel document has saved finance, DV, payment or settlement evidence');
        }
        // The document links must never silently detach a reimbursement from its authority.
        // Source identities are snapshots and can be tracked without modifying historical data.
        $linkKeys=[];
        foreach($links as $row){
            if(!self::isTravelType((string)($row['source_type']??'')) || !self::isTravelType((string)($row['target_type']??'')))continue;
            $key=self::rowKey($row['organization_id'],$row['source_type'],$row['source_id']).'->'.self::rowKey($row['organization_id'],$row['target_type'],$row['target_id']);
            if(isset($linkKeys[$key]))self::fail('duplicate travel relationship');
            $linkKeys[$key]=true;
        }
        foreach($versions as $row){
            $type=(string)($row['doc_type']??'');if($type!=='TA' && $type!=='RC')continue;
            $org=$row['organization_id']??null;$id=$row['entity_id']??null;
            $snapshot=json_decode((string)$row['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            if($type==='TA'){
                $ar=$snapshot['activity_request_id']??null;
                if(!is_numeric($ar) || (int)$ar<1)self::fail('Travel Authority is missing an Activity Request identity');
                $link=self::rowKey($org,'AR',$ar).'->'.self::rowKey($org,'TA',$id);
                if(!isset($linkKeys[$link]))self::fail('Travel Authority has no corresponding AR link');
            }else{
                $ta=$snapshot['travel_authority_id']??null;
                if(!is_numeric($ta) || (int)$ta<1)self::fail('reimbursement claim is missing its Travel Authority');
                $link=self::rowKey($org,'TA',$ta).'->'.self::rowKey($org,'RC',$id);
                if(!isset($linkKeys[$link]))self::fail('reimbursement claim has no matching TA link');
            }
        }
        foreach($known as $key=>$unused)if(!isset($seenVersion[$key]))self::fail('a travel document has no version snapshot');
        return ['documents'=>$docCount,'versions'=>$versionCount,'links'=>$linkCount,'total'=>$docCount+$versionCount+$linkCount];
    }
    private static function isTravelType(string $type): bool {
        return in_array($type,self::DOCUMENT_TYPES,true);
    }
    private static function rowKey(mixed $org,mixed $type,mixed $id): string {
        if(!is_numeric($org)|| (int)$org<1 || !is_numeric($id)|| (int)$id<1 || !is_string($type)||$type==='')self::fail('invalid document or school identifier');
        return (int)$org.'|'.$type.'|'.(int)$id;
    }

    /**
     * Block a Procurement reset that would delete an accounting/payment record
     * while retaining a travel RC containing financial evidence. This is also
     * required if the user selects Procurement + Travel together: the current
     * reset has no authoritative reconciliation of financial ledgers and claims.
     */
    public static function guardFinanceRemoval(PDO $db): void {
        $rows=$db->query("SELECT organization_id,doc_type,entity_id,snapshot_json FROM document_versions WHERE doc_type IN ('AR','TA','RC')")->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $row){
            try{$snapshot=json_decode((string)($row['snapshot_json']??''),true,512,JSON_THROW_ON_ERROR);}
            catch(Throwable $e){self::fail('travel financial references could not be reviewed');}
            if(!is_array($snapshot) || array_is_list($snapshot))self::fail('malformed travel snapshot prevents financial reset');
            if(self::hasFinancialEvidence($snapshot))self::fail('travel evidence references financial activity; reset related documents and financial records only under a separately approved plan');
        }
        $links=$db->query("SELECT source_type,target_type FROM document_links WHERE source_type IN ('AR','TA','RC') OR target_type IN ('AR','TA','RC')")->fetchAll(PDO::FETCH_ASSOC);
        foreach($links as $row){
            if(!self::isTravelType((string)($row['source_type']??'')) || !self::isTravelType((string)($row['target_type']??'')))
                self::fail('travel documents have cross-workflow links that block a financial reset');
        }
    }

    public static function guardFundLedger(PDO $db): void {
        $cols=$db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fund_events' AND COLUMN_NAME='reference_type'")->fetchAll(PDO::FETCH_COLUMN);
        if($cols){
            $count=(int)$db->query("SELECT COUNT(*) FROM fund_events WHERE UPPER(reference_type) IN ('AR','TA','RC','TRAVEL','TRAVEL_REIMBURSEMENT')")->fetchColumn();
            if($count>0)self::fail('travel-related financial ledger entries exist');
        }
    }

    /** Read-only MariaDB queries for preview/verify; never calls any write SQL. */
    public static function inspectDatabase(PDO $db): array {
        $registry=$db->query("SELECT organization_id,doc_type,entity_id FROM document_registry WHERE doc_type IN ('AR','TA','RC')")->fetchAll(PDO::FETCH_ASSOC);
        $links=$db->query("SELECT organization_id,source_type,source_id,target_type,target_id FROM document_links WHERE source_type IN ('AR','TA','RC') OR target_type IN ('AR','TA','RC')")->fetchAll(PDO::FETCH_ASSOC);
        $versions=$db->query("SELECT organization_id,doc_type,entity_id,snapshot_json FROM document_versions WHERE doc_type IN ('AR','TA','RC')")->fetchAll(PDO::FETCH_ASSOC);
        // The existing fund event ledger uses reference_type/reference_id rather than
        // document_links. Do not silently orphan a travel ledger reference.
        $cols=$db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fund_events' AND COLUMN_NAME IN ('reference_type','reference_id')")->fetchAll(PDO::FETCH_COLUMN);
        if(in_array('reference_type',$cols,true)){
            $count=(int)$db->query("SELECT COUNT(*) FROM fund_events WHERE UPPER(reference_type) IN ('AR','TA','RC','TRAVEL','TRAVEL_REIMBURSEMENT')")->fetchColumn();
            if($count>0)self::fail('the fund ledger already contains Travel & Training references');
        }
        return self::inspect($registry,$links,$versions);
    }
}
