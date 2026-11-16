<?php
declare(strict_types=1);
/** Read-only control-number issuance report (NOT evidence of physical approval). */
final class MonthlyIssuances {
    public const TYPES = [
        'PR'=>'Purchase Request (PR)',
        'RFQ'=>'Request for Quotation (RFQ)',
        'AWARD'=>'Award Determination',
        'PO'=>'Purchase Order (PO)',
        'IAR'=>'Inspection and Acceptance Report (IAR)',
        'DV'=>'Disbursement Voucher (DV)',
        'RIS'=>'Requisition and Issue Slip (RIS)',
        'ICS'=>'Inventory Custodian Slip (ICS)',
        'PAR'=>'Property Acknowledgement Receipt (PAR)',
        'AR'=>'Activity Request (AR)',
        'TA'=>'Travel Authority (TA)',
        'RC'=>'Travel Reimbursement Claim (RC)',
    ];
    public function __construct(private PDO $db,private array $user) {}

    /** Month corresponds to the period in the original allocated control number,
     * not a claim that the document received wet signatures in that month. */
    public function report(array $params): array {
        $id=$params['organization_id']??null;
        demand((is_string($id)||is_int($id)) && preg_match('/^[1-9][0-9]*$/D',(string)$id)===1,
            'Select an authorized organization for the report',400);
        $org=(int)$id;
        demand($org>0,'Invalid organization',400);
        scope($this->db,$this->user,$org);
        $organization=one($this->db,'SELECT id,name,kind FROM organizations WHERE id=?',[$org]);
        demand($organization!==null,'Organization not found',404);
        $now=new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));
        $year=$params['year']??$now->format('Y');
        $month=$params['month']??$now->format('n');
        demand((is_string($year)||is_int($year)) && preg_match('/^(19|20|21)[0-9]{2}$/D',(string)$year)===1 && (int)$year<=2100,
            'Choose a valid report year (1900–2100)',400);
        demand((is_string($month)||is_int($month)) && preg_match('/^(?:[1-9]|0[1-9]|1[0-2])$/D',(string)$month)===1,
            'Choose a valid month',400);
        $type=(string)($params['type']??'ALL');
        demand($type==='ALL'||array_key_exists($type,self::TYPES),'Select a supported document type',400);
        $period=sprintf('%04d-%02d',(int)$year,(int)$month);
        $sql='SELECT id,entity_id,doc_type,display_number,period,created_at FROM document_registry WHERE organization_id=? AND period=?';
        $bind=[$org,$period];
        if($type!=='ALL'){$sql.=' AND doc_type=?';$bind[]=$type;}
        else {$sql.=' AND doc_type IN ('.implode(',',array_fill(0,count(self::TYPES),'?')).')';$bind=array_merge($bind,array_keys(self::TYPES));}
        // Stable report order even if historical counters had gaps. Never omit
        // issued/cancelled numbers by checking downstream document statuses.
        $order=array_keys(self::TYPES);
        $cases='';
        foreach($order as $index=>$kind)$cases.=" WHEN '".$kind."' THEN ".($index+1);
        $sql.=' ORDER BY CASE doc_type'.$cases.' ELSE 99 END,display_number ASC,id ASC LIMIT 5001';
        $rows=q($this->db,$sql,$bind)->fetchAll(PDO::FETCH_ASSOC);
        demand(count($rows)<=5000,'More than 5000 control numbers match. The register cannot print an incomplete month; contact the technical administrator for a paginated export.',409);
        $counts=array_fill_keys(array_keys(self::TYPES),0);
        foreach($rows as &$row){
            demand(isset(self::TYPES[$row['doc_type']]),'Unexpected document type in report',409);
            $row['label']=self::TYPES[$row['doc_type']];
            $counts[$row['doc_type']]++;
        }
        unset($row);
        return [
            'organization'=>$organization,
            'period'=>$period,
            'year'=>(int)$year,
            'month'=>(int)$month,
            'type'=>$type,
            'rows'=>$rows,
            'counts'=>array_filter($counts,static fn(int $count)=>$count>0),
            'total'=>count($rows),
            'prepared_at'=>$now->format('Y-m-d H:i'),
        ];
    }
}
