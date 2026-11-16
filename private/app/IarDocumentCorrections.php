<?php
declare(strict_types=1);
/**
 * IAR documentary header corrections, never receipt or quantity amendments.
 * Invoice references live in immutable document_versions: no schema migration.
 */
final class IarDocumentCorrections {
    public static function invoice(mixed $value): string {
        demand(is_string($value) || $value===null,'Invoice number must be text',422);
        $number=trim((string)$value);
        demand(strlen($number)<=120 && !preg_match('/[\x00-\x1f\x7f]/',$number),'Invoice number must be at most 120 characters on one line',422);
        return $number;
    }
    public static function invoiceDate(mixed $value): string {
        demand(is_string($value)||$value===null,'Invoice date must be a date or blank',422);
        if($value===null || trim($value)==='')return '';
        return ProcurementDocumentDates::date(trim($value),'Invoice date');
    }
    private static function latest(PDO $db,int $org,int $id,bool $lock=false): array {
        $row=one($db,"SELECT id,version_no,snapshot_json FROM document_versions WHERE organization_id=? AND doc_type='IAR' AND entity_id=? ORDER BY version_no DESC LIMIT 1".($lock?' FOR UPDATE':''),[$org,$id]);
        demand($row!==null,'IAR document history is unavailable; administrative review required',409);
        $snap=json_decode((string)$row['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($snap),'Invalid IAR version history; administrative review required',409);
        $row['snapshot']=$snap;
        return $row;
    }
    public static function read(PDO $db,int $org,int $id): array {
        $row=one($db,'SELECT id,po_id,status,display_number,delivered_at,accepted_at FROM iars WHERE id=? AND organization_id=?',[$id,$org]);
        demand($row!==null,'IAR not found in this workspace',404);
        $version=self::latest($db,$org,$id);
        $locked=self::dateLock($db,$org,$id);
        return ['iar_id'=>(int)$id,'po_id'=>(int)$row['po_id'],'number'=>(string)$row['display_number'],
            'status'=>(string)$row['status'],'delivered_at'=>(string)$row['delivered_at'],
            'accepted_at'=>(string)$row['accepted_at'],
            'invoice_no'=>(string)($version['snapshot']['invoice_no']??''),
            'invoice_date'=>(string)($version['snapshot']['invoice_date']??''),
            'version'=>(int)$version['version_no'],'iar_date'=>(string)($version['snapshot']['iar_date']??''),
            'date_dependency_warning'=>$locked];
    }
    public static function decorateList(PDO $db,int $org,array $rows): array {
        foreach($rows as &$row){
            $version=one($db,"SELECT version_no,snapshot_json FROM document_versions WHERE organization_id=? AND doc_type='IAR' AND entity_id=? ORDER BY version_no DESC LIMIT 1",[$org,(int)$row['id']]);
            // A historical row without a version must remain visible in the register.
            // Editing/printing still fails closed until its provenance is reviewed.
            $snap=$version?json_decode((string)$version['snapshot_json'],true):null;
            $row['invoice_no']=is_array($snap)?(string)($snap['invoice_no']??''):'';
            $row['invoice_date']=is_array($snap)?(string)($snap['invoice_date']??''):'';
            $row['iar_date']=is_array($snap)?(string)($snap['iar_date']??''):'';
            $row['document_version']=(int)($version['version_no']??0);
        }
        unset($row);
        return $rows;
    }
    private static function dateLock(PDO $db,int $org,int $iarId): string {
        $linked=one($db,"SELECT COUNT(*) n FROM dv_allocation_lines a
            JOIN iar_lines il ON il.id=a.iar_line_id
            JOIN disbursement_vouchers dv ON dv.id=a.dv_id AND dv.organization_id=?
            WHERE il.iar_id=? AND dv.status<>'CANCELLED'",[$org,$iarId]);
        if((int)($linked['n']??0)>0)return 'A draft or finalized DV references this IAR';
        $assets=one($db,"SELECT COUNT(*) n FROM assets a JOIN iar_lines il ON il.id=a.iar_line_id
            WHERE a.organization_id=? AND il.iar_id=?",[$org,$iarId]);
        if((int)($assets['n']??0)>0)return 'Accountable property has been registered from this IAR';
        $stock=one($db,"SELECT COUNT(*) n FROM stock_events se
            JOIN inventory_lots lot ON lot.id=se.lot_id AND lot.organization_id=?
            JOIN iar_lines il ON il.id=lot.iar_line_id WHERE il.iar_id=? AND se.event_type<>'RECEIPT'",[$org,$iarId]);
        if((int)($stock['n']??0)>0)return 'Inventory has subsequent stock movements';
        return '';
    }
    public static function correct(PDO $db,int $org,int $actor,array $d): array {
        $allowed=['organization_id','iar_id','expected_version','delivered_at','accepted_at','invoice_no','invoice_date','reason'];
        demand(!array_diff(array_keys($d),$allowed),'Only IAR delivery/inspection dates, invoice number/date and an audit reason can be edited',422);
        $id=idOf($d,'iar_id');
        $iar=one($db,'SELECT * FROM iars WHERE organization_id=? AND id=? FOR UPDATE',[$org,$id]);
        demand($iar!==null && $iar['status']==='FINALIZED','Only finalized IARs of this organization can receive documentary corrections',409);
        $v=self::latest($db,$org,$id,true);
        demand((int)($d['expected_version']??0)===(int)$v['version_no'],'IAR changed in another session; reopen Edit IAR',409);
        $del=ProcurementDocumentDates::date($d['delivered_at']??null,'Delivery date');
        $acc=ProcurementDocumentDates::date($d['accepted_at']??null,'Inspection/acceptance date');
        demand($acc>=$del,'Inspection/acceptance date cannot precede delivery date',422);
        $po=one($db,"SELECT id,status FROM purchase_orders WHERE id=? AND organization_id=?",[$iar['po_id'],$org]);
        demand($po!==null && $po['status']==='FINALIZED','Linked finalized Purchase Order unavailable',409);
        $invoice=self::invoice($d['invoice_no']??null);
        $invoiceDate=self::invoiceDate(array_key_exists('invoice_date',$d)?$d['invoice_date']:($v['snapshot']['invoice_date']??''));
        $originalIarDate=(string)($v['snapshot']['iar_date']??'');
        demand($invoiceDate===''||$invoiceDate<=($originalIarDate!==''?$originalIarDate:(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d')),
            'Invoice date cannot be later than the recorded IAR date or today',422);
        $before=['delivered_at'=>(string)$iar['delivered_at'],'accepted_at'=>(string)$iar['accepted_at'],
            'invoice_no'=>(string)($v['snapshot']['invoice_no']??''),
            'invoice_date'=>(string)($v['snapshot']['invoice_date']??'')];
        $after=['delivered_at'=>$del,'accepted_at'=>$acc,'invoice_no'=>$invoice,'invoice_date'=>$invoiceDate];
        demand($before!==$after,'No IAR documentary fields have changed',422);
        $dateChanged=$before['delivered_at']!==$del||$before['accepted_at']!==$acc;
        if($dateChanged&&$originalIarDate!=='')
            demand($acc<=$originalIarDate,'Corrected inspection date cannot be after the issued IAR Date ('.$originalIarDate.'); refer for administrative review',422);
        if($dateChanged){
            $earliest=ProcurementDocumentDates::poDate($db,$org,(int)$po['id']);
            demand($del>=$earliest['date'],'Delivery date cannot precede the linked PO date ('.$earliest['date'].')',422);
            // Documentary header updates never mutate posted lot dates, stock or DV entries.
            // Preserve and flag any discrepancy with dependent records for administrative reconciliation.
            $block=self::dateLock($db,$org,$id);
        }
        $reason=trim((string)($d['reason']??''));
        demand(strlen($reason)<=500 && !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$reason),'Invalid correction note',422);
        if($dateChanged && !empty($block))
            demand($reason!=='','Explain the documentary date correction: downstream inventory/accounting evidence remains unchanged',422);
        if($reason==='')$reason='Documentary correction of IAR date / supplier invoice reference';
        $snap=$v['snapshot'];
        $snap['po']=$snap['po']??(int)$iar['po_id'];
        $snap['number']=$snap['number']??(string)$iar['display_number'];
        $snap['delivered_at']=$del;$snap['accepted_at']=$acc;$snap['invoice_no']=$invoice;$snap['invoice_date']=$invoiceDate;
        $snap['header_corrected_from_version']=(int)$v['version_no'];
        $snap['downstream_date_reconciliation_required']=($dateChanged&&!empty($block)) || (bool)($v['snapshot']['downstream_date_reconciliation_required']??false);
        if(!empty($block))$snap['date_dependency_warning']=$block;
        // Do not mutate iar_lines, inventory_lots, stock_events, assets or DV accounting.
        if($dateChanged)q($db,'UPDATE iars SET delivered_at=?,accepted_at=? WHERE organization_id=? AND id=?',[$del,$acc,$org,$id]);
        q($db,"INSERT INTO document_versions(organization_id,doc_type,entity_id,version_no,snapshot_json,reason,actor_id)
            VALUES(?,'IAR',?,?,?,?,?)",[$org,$id,(int)$v['version_no']+1,json_encode($snap,JSON_THROW_ON_ERROR),$reason,$actor]);
        q($db,"INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES(?,?,'IAR_HEADER_CORRECTED','IAR',?,?)",
            [$org,$actor,$id,json_encode(['before'=>$before,'after'=>$after,'prior_version'=>(int)$v['version_no'],
                'inventory_receipts_unchanged'=>true,'lot_acquisition_dates_unchanged'=>true,
                'downstream_date_reconciliation_required'=>$dateChanged&&!empty($block),
                'downstream_note'=>$block??''],JSON_THROW_ON_ERROR)]);
        return ['id'=>$id,'number'=>(string)$iar['display_number'],'version'=>(int)$v['version_no']+1,'updated_fields'=>array_keys(array_diff_assoc($after,$before))];
    }
}
