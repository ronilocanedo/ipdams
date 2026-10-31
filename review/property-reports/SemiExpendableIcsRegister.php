<?php
declare(strict_types=1);
/** Read-only ICS issuance evidence projection: not a certified COA print form. */
final class SemiExpendableIcsRegister {
    public function __construct(private PDO $db,private array $user){}
    public function report(array $params):array {
        $raw=$params['organization_id']??null;
        demand((is_string($raw)||is_int($raw))&&preg_match('/^[1-9][0-9]*$/D',(string)$raw)===1,'Select a valid organization',400);
        $org=(int)$raw;scope($this->db,$this->user,$org);
        $from=(string)($params['from']??'');$through=(string)($params['through']??'');
        foreach([$from,$through] as $value){
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
            demand($date!==false&&$date->format('Y-m-d')===$value,'Use valid YYYY-MM-DD report dates',400);
        }
        demand($from<=$through,'Start date cannot follow end date',400);
        $organization=one($this->db,'SELECT id,name,kind FROM organizations WHERE id=?',[$org]);
        demand($organization!==null,'Organization not found',404);
        $until=(new DateTimeImmutable($through))->modify('+1 day')->format('Y-m-d');
        $sql="SELECT d.id AS accountability_id,d.asset_id,d.display_number AS ics_number,d.custodian,
            d.created_at AS recorded_at,a.property_tag,a.acquisition_cost,a.acquired_on,
            a.opening_record,i.name AS description,i.category
            FROM accountability_documents d
            JOIN assets a ON a.id=d.asset_id AND a.organization_id=d.organization_id
            JOIN item_catalog i ON i.id=a.item_id
            WHERE d.organization_id=? AND d.doc_type='ICS' AND d.status='FINALIZED'
              AND i.category='SEMI_EXPENDABLE' AND d.created_at>=? AND d.created_at<?
            ORDER BY d.created_at,d.id LIMIT 5001";
        $rows=q($this->db,$sql,[$org,$from,$until])->fetchAll(PDO::FETCH_ASSOC);
        demand(count($rows)<=5000,'Report exceeds 5000 rows; narrow dates to avoid incomplete results',409);
        foreach($rows as &$row){
            $row['quantity']=1;
            $row['recorded_date']=substr((string)$row['recorded_at'],0,10);
            $row['value_available']=$row['acquisition_cost']!==null;
        }
        unset($row);
        return ['organization'=>$organization,'from'=>$from,'through'=>$through,'rows'=>$rows,'count'=>count($rows),
            'date_basis'=>'ICS database recording date; not necessarily signed/effective issuance date',
            'limitations'=>['No signature verification','No return/transfer reconciliation',
                'Missing acquisition values remain null','Not a complete A.1 balance or certified Annex A.7 print form']];
    }
}
