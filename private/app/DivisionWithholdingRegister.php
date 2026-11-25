<?php
declare(strict_types=1);
/**
 * v0.2.420 — Division-wide, READ-ONLY withholding monitoring workpaper.
 * Source: original finalized DVs and withholding_components. Never infer BIR
 * settlement from a notification, a Division acknowledgment or check release.
 * Does not create, adjust, reconcile or post any transaction.
 */
final class DivisionWithholdingRegister {
    private const PAGE_LIMIT=50;
    private const MAX_EXPORT_DVS=1500;
    private const MAX_EXPORT_COMPONENTS=15000;
    public function __construct(private PDO $db,private array $user) {}

    private function division(array $input):int {
        $division=idOf($input,'organization_id');
        demand(in_array((string)($this->user['role']??''),['DIVISION','SUPER_ADMIN'],true),
            'Division finance reporting requires an authorized Division or Super Administrator account',403);
        scope($this->db,$this->user,$division);
        $office=one($this->db,"SELECT id FROM organizations WHERE id=? AND kind='DIVISION' AND active=1",[$division]);
        demand($office!==null,'Select an active Division Office workspace',403);
        return $division;
    }
    private static function choice(array $input,string $key,array $valid,string $default='ALL'):string {
        $value=(string)($input[$key]??$default);
        demand(in_array($value,$valid,true),'Invalid '.$key.' report filter',422);
        return $value;
    }
    private static function optionalId(array $input,string $key):int {
        $raw=(string)($input[$key]??'');
        demand($raw===''||(ctype_digit($raw)&&(int)$raw>0),'Invalid '.$key.' filter',422);
        return $raw===''?0:(int)$raw;
    }
    private static function plainFilter(array $input,string $key,int $max):string {
        $value=trim((string)($input[$key]??''));
        demand(mbSafeLen($value)<=$max,'Filter is too long: '.$key,422);
        demand(!preg_match('/[\x00-\x1f]/',$value),'Invalid filter: '.$key,422);
        return $value;
    }
    /**
     * Only format the SAVED historical rate like the School DV screen (5.00%).
     * Never recalculate original tax base, withheld amount, gross or net.
     */
    private static function historicalRatePercent(string $rate):string {
        demand(preg_match('/^\d{1,4}(?:\.\d{1,6})?$/D',$rate)===1,
            'Invalid saved ATC rate version; accounting review required',409);
        [$whole,$fraction]=array_pad(explode('.',$rate,2),2,'');
        $micro=(int)str_pad($fraction,6,'0');
        $hundredths=(int)$whole*10000+intdiv($micro+50,100);
        return sprintf('%d.%02d',intdiv($hundredths,100),$hundredths%100);
    }
    private static function monthBounds(string $month):array {
        if($month==='')return [];
        demand(preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D',$month)===1,'Month must be YYYY-MM',422);
        $start=DateTimeImmutable::createFromFormat('!Y-m-d',$month.'-01',new DateTimeZone('Asia/Manila'));
        demand($start!==false,'Invalid report month',422);
        return [$start->format('Y-m-d'),$start->modify('+1 month')->format('Y-m-d')];
    }
    /** Optional inclusive DV-document-date bounds; never filter by created_at. */
    private static function documentDate(array $input,string $key):string {
        $date=self::plainFilter($input,$key,10);
        if($date==='')return '';
        demand(preg_match('/^20\d{2}-(0[1-9]|1[0-2])-([0-2]\d|3[01])$/D',$date)===1,
            'Date must be YYYY-MM-DD: '.$key,422);
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('Asia/Manila'));
        demand($parsed!==false && $parsed->format('Y-m-d')===$date,
            'Invalid calendar date: '.$key,422);
        return $date;
    }
    /** All filters apply at the source DV, not to client-side rows. */
    private function filters(array $input,int $division):array {
        $fromDate=self::documentDate($input,'from_date');
        $toDate=self::documentDate($input,'to_date');
        demand($fromDate==='' || $toDate==='' || $fromDate<=$toDate,
            'From Date must be on or before To Date',422);
        // Date range and calendar month are alternative date selectors.
        // Direct API requests containing both use the explicit date range.
        $rangeSelected=$fromDate!=='' || $toDate!=='';
        $month=$rangeSelected?'':self::plainFilter($input,'month',7);
        $bounds=self::monthBounds($month);
        $school=self::optionalId($input,'school_id');
        $fund=self::optionalId($input,'fund_id');
        $source=self::plainFilter($input,'source_code',60);
        $atc=self::plainFilter($input,'atc_code',24);
        $disposition=self::choice($input,'disposition',['ALL','REMIT','SCHOOL_UTILIZATION']);
        $notice=self::choice($input,'notice_status',['ALL','PENDING_REVIEW','REVIEWED','NO_NOTICE']);
        $check=self::choice($input,'check_status',['ALL','RELEASED','NOT_RELEASED']);
        $year=self::plainFilter($input,'fiscal_year',4);
        demand($year===''||preg_match('/^20\d\d$/D',$year)===1,'Invalid fiscal year',422);
        $where=["v.status='FINALIZED'",
            "((o.kind='SCHOOL' AND o.parent_division_id=?) OR (o.kind='DIVISION' AND o.id=?))"];
        $args=[$division,$division];
        if($rangeSelected){
            if($fromDate!==''){$where[]='v.document_date>=?';$args[]=$fromDate;}
            if($toDate!==''){$where[]='v.document_date<=?';$args[]=$toDate;}
        }elseif($bounds){$where[]='v.document_date>=? AND v.document_date<?';array_push($args,...$bounds);}
        if($school){$where[]='o.id=?';$args[]=$school;}
        if($fund){$where[]='f.id=?';$args[]=$fund;}
        if($source!==''){$where[]='f.source_code=?';$args[]=$source;}
        if($year!==''){$where[]='f.fiscal_year=?';$args[]=(int)$year;}
        // A filter selects which DVs appear. The entire original withholding
        // breakdown of each matching DV is displayed, as it is at the School.
        // Never truncate components or recompute a partial DV withholding.
        $componentClause="w.amount>0";
        $componentArgs=[];
        $match="EXISTS (SELECT 1 FROM withholding_components mx WHERE mx.dv_id=v.id AND mx.amount>0";
        if($atc!==''){$match.=' AND mx.atc_code=?';$args[]=$atc;}
        if($disposition!=='ALL'){$match.=' AND mx.disposition=?';$args[]=$disposition;}
        $where[]=$match.')';
        // Only current Division's saved recipient notice may become REVIEWED.
        // These EXISTS clauses are added solely when the user selects a notice filter.
        $notified="EXISTS (SELECT 1 FROM audit_events n WHERE n.organization_id=v.organization_id
            AND n.event_type='SCHOOL_RETAINED_TAX_NOTICE' AND n.entity_type='DV' AND n.entity_id=v.id
            AND CAST(JSON_UNQUOTE(JSON_EXTRACT(n.details,'$.division_id')) AS UNSIGNED)=".$division.")";
        $reviewed="EXISTS (SELECT 1 FROM audit_events n JOIN audit_events a
            ON a.entity_id=n.id AND a.entity_type='TAX_NOTICE'
            AND a.event_type='DIVISION_RETAINED_TAX_ACKNOWLEDGED' AND a.organization_id=".$division."
            WHERE n.organization_id=v.organization_id AND n.entity_id=v.id AND n.entity_type='DV'
            AND n.event_type='SCHOOL_RETAINED_TAX_NOTICE'
            AND CAST(JSON_UNQUOTE(JSON_EXTRACT(n.details,'$.division_id')) AS UNSIGNED)=".$division.")";
        if($notice==='NO_NOTICE')$where[]='NOT '.$notified;
        elseif($notice==='PENDING_REVIEW'){$where[]=$notified;$where[]='NOT '.$reviewed;}
        elseif($notice==='REVIEWED')$where[]=$reviewed;
        $released="EXISTS (SELECT 1 FROM checks c JOIN payment_events pe ON pe.check_id=c.id
            AND pe.event_type='RELEASED' WHERE c.dv_id=v.id AND c.organization_id=v.organization_id)";
        if($check==='RELEASED')$where[]=$released;
        elseif($check==='NOT_RELEASED')$where[]='NOT '.$released;
        $from=" FROM disbursement_vouchers v
            JOIN organizations o ON o.id=v.organization_id AND o.active=1
            JOIN funds f ON f.id=v.fund_id AND f.organization_id=o.id
            WHERE ".implode(' AND ',$where);
        // The totals use the same DV filters but join original tax component rows;
        // do not aggregate a correlated check subquery within the grouped SELECT.
        $taxFrom=" FROM disbursement_vouchers v
            JOIN organizations o ON o.id=v.organization_id AND o.active=1
            JOIN funds f ON f.id=v.fund_id AND f.organization_id=o.id
            JOIN withholding_components w ON w.dv_id=v.id
            WHERE ".implode(' AND ',$where).' AND '.$componentClause;
        return ['from'=>$from,'args'=>$args,'tax_from'=>$taxFrom,
            'tax_args'=>array_merge($args,$componentArgs),'component'=>$componentClause,
            'component_args'=>$componentArgs,
            'applied'=>['month'=>$month,'from_date'=>$fromDate,'to_date'=>$toDate,
                'school_id'=>$school,'fund_id'=>$fund,'source_code'=>$source,
                'atc_code'=>$atc,'disposition'=>$disposition,'notice_status'=>$notice,
                'check_status'=>$check,'fiscal_year'=>$year]];
    }
    private function options(int $division):array {
        // These options are current Division-owned offices, never arbitrary schools.
        $officeWhere="(o.id=? AND o.kind='DIVISION') OR (o.parent_division_id=? AND o.kind='SCHOOL')";
        $schools=q($this->db,"SELECT o.id,o.name,o.kind FROM organizations o
            WHERE o.active=1 AND (".$officeWhere.") ORDER BY o.kind,o.name LIMIT 501",
            [$division,$division])->fetchAll();
        demand(count($schools)<=500,'Too many assigned offices; request a paged report',409);
        $sources=q($this->db,"SELECT DISTINCT f.source_code FROM funds f JOIN organizations o ON o.id=f.organization_id
            WHERE o.active=1 AND (".$officeWhere.") ORDER BY f.source_code LIMIT 201",
            [$division,$division])->fetchAll();
        demand(count($sources)<=200,'Too many source-of-fund codes',409);
        $funds=q($this->db,"SELECT f.id,f.organization_id,f.name,f.fiscal_year,f.source_code
            FROM funds f JOIN organizations o ON o.id=f.organization_id
            WHERE o.active=1 AND (".$officeWhere.") ORDER BY f.fiscal_year DESC,o.name,f.name LIMIT 1501",
            [$division,$division])->fetchAll();
        demand(count($funds)<=1500,'Too many fund records; use school/source filters',409);
        $atcs=q($this->db,"SELECT DISTINCT w.atc_code
            FROM withholding_components w JOIN disbursement_vouchers v ON v.id=w.dv_id AND v.status='FINALIZED'
            JOIN organizations o ON o.id=v.organization_id AND o.active=1
            WHERE (".$officeWhere.") ORDER BY w.atc_code LIMIT 151",
            [$division,$division])->fetchAll();
        demand(count($atcs)<=150,'Too many withholding codes',409);
        return ['offices'=>$schools,'fund_sources'=>array_column($sources,'source_code'),
            'funds'=>$funds,'atcs'=>array_column($atcs,'atc_code')];
    }
    /** One DV per result row; original components remain in child details. */
    private function rows(array $filters,int $division,int $limit,int $offset):array {
        $sql='SELECT v.id dv_id,v.organization_id,v.display_number dv_number,v.document_date,
            v.gross gross_amount,v.deductions total_deductions,v.net net_payment,
            o.name office_name,o.kind office_kind,o.code office_code,
            f.id fund_id,f.name fund_name,f.fiscal_year,f.source_code
            '.$filters['from'].' ORDER BY v.document_date DESC,v.id DESC LIMIT '.$limit.' OFFSET '.$offset;
        $rows=q($this->db,$sql,$filters['args'])->fetchAll();
        if($rows===[])return [];
        $ids=array_map(static fn(array $r):int=>(int)$r['dv_id'],$rows);
        $marks=implode(',',array_fill(0,count($ids),'?'));
        // Resolve each component's original effective-dated rate version. Never
        // join to the CURRENT ATC rate or recompute historical withholding.
        $wsql='SELECT w.id,w.dv_id,w.atc_code,w.amount,w.tax_base,w.disposition,
                rv.rate rate_percent
            FROM withholding_components w
            JOIN tax_rate_versions rv ON rv.id=w.rate_version_id
            WHERE w.dv_id IN ('.$marks.') AND '.$filters['component'].
            ' ORDER BY w.dv_id,w.id';
        $components=q($this->db,$wsql,array_merge($ids,$filters['component_args']))->fetchAll();
        demand(count($components)<=max(150,self::MAX_EXPORT_COMPONENTS),
            'Too many tax components in one report; narrow the filter',409);
        $mapped=[];foreach($rows as $index=>$r){
            // School DV screen shows the saved deductions, not a new tax calculation.
            $rows[$index]['withheld']=(string)$r['total_deductions'];
            $rows[$index]['component_count']=0;
            $rows[$index]['components']=[];
            $rows[$index]['notice_status']='NOT_APPLICABLE';
            $rows[$index]['notice_id']=null;
            $rows[$index]['check_released_at']=null;
            $rows[$index]['bir_settlement']='NOT_VERIFIED_BY_THIS_REPORT';
            $mapped[(int)$r['dv_id']]=$index;
        }
        foreach($components as $w){
            $id=(int)$w['dv_id'];if(!isset($mapped[$id]))continue;
            $i=$mapped[$id];
            $rows[$i]['component_count']++;
            $rows[$i]['components'][]=['id'=>(int)$w['id'],'atc_code'=>(string)$w['atc_code'],
                'disposition'=>(string)$w['disposition'],'amount'=>(string)$w['amount'],
                'tax_base'=>(string)$w['tax_base'],
                'rate_percent'=>self::historicalRatePercent((string)$w['rate_percent'])];
        }
        // Eligibility is a property of the WHOLE saved DV. An ATC or
        // disposition filter must never make a mixed-tax DV look like an
        // eligible pure SCHOOL_UTILIZATION notice.
        $allTypes=q($this->db,"SELECT w.dv_id,COUNT(*) total_components,
            SUM(CASE WHEN w.disposition='SCHOOL_UTILIZATION' THEN 1 ELSE 0 END) utilized_components
            FROM withholding_components w WHERE w.dv_id IN (".$marks.") GROUP BY w.dv_id",$ids)->fetchAll();
        $onlySchoolUtilization=[];
        foreach($allTypes as $type)
            $onlySchoolUtilization[(int)$type['dv_id']]=(int)$type['total_components']>0 &&
                (int)$type['total_components']===(int)$type['utilized_components'];
        $release=q($this->db,"SELECT c.dv_id,MIN(pe.created_at) released_at
            FROM checks c JOIN payment_events pe ON pe.check_id=c.id AND pe.event_type='RELEASED'
            WHERE c.dv_id IN (".$marks.") GROUP BY c.dv_id",$ids)->fetchAll();
        $releaseByDv=[];foreach($release as $r)$releaseByDv[(int)$r['dv_id']]=$r['released_at'];
        $notices=q($this->db,"SELECT a.id,a.organization_id,a.entity_id,a.details,a.created_at
            FROM audit_events a WHERE a.entity_type='DV' AND a.event_type='SCHOOL_RETAINED_TAX_NOTICE'
            AND a.entity_id IN (".$marks.") ORDER BY a.id ASC",$ids)->fetchAll();
        $noticesByDv=[];$noticeIds=[];
        foreach($notices as $n){
            $id=(int)$n['entity_id'];$i=$mapped[$id]??null;
            if($i===null || (int)$n['organization_id']!==(int)$rows[$i]['organization_id'])continue;
            $details=json_decode((string)$n['details'],true);
            if(!is_array($details)||(int)($details['division_id']??0)!==$division)continue;
            if(isset($noticesByDv[$id]))continue;
            $noticesByDv[$id]=$n;$noticeIds[]=(int)$n['id'];
        }
        $ackByNotice=[];
        if($noticeIds){
            $nm=implode(',',array_fill(0,count($noticeIds),'?'));
            $acks=q($this->db,"SELECT entity_id,MIN(created_at) reviewed_at
                FROM audit_events WHERE organization_id=? AND event_type='DIVISION_RETAINED_TAX_ACKNOWLEDGED'
                AND entity_type='TAX_NOTICE' AND entity_id IN (".$nm.") GROUP BY entity_id",
                array_merge([$division],$noticeIds))->fetchAll();
            foreach($acks as $ack)$ackByNotice[(int)$ack['entity_id']]=$ack['reviewed_at'];
        }
        foreach($rows as &$r){
            $id=(int)$r['dv_id'];
            $r['check_released_at']=$releaseByDv[$id]??null;
            if($r['office_kind']==='SCHOOL'&&($onlySchoolUtilization[$id]??false)){
                $n=$noticesByDv[$id]??null;
                $r['notice_id']=$n===null?null:(int)$n['id'];
                $r['notice_status']=$n===null?'NO_NOTICE':
                    (isset($ackByNotice[(int)$n['id']])?'REVIEWED':'PENDING_REVIEW');
                $r['notified_at']=$n['created_at']??null;
                $r['reviewed_at']=$n===null?null:($ackByNotice[(int)$n['id']]??null);
            }
        }
        unset($r);
        return $rows;
    }
    public function report(array $input,bool $export=false):array {
        $division=$this->division($input);
        $filters=$this->filters($input,$division);
        $pageRaw=(string)($input['page']??'1');
        demand(ctype_digit($pageRaw)&&(int)$pageRaw>0&&(int)$pageRaw<=10000,'Invalid report page',422);
        $page=(int)$pageRaw;
        $count=(int)one($this->db,'SELECT COUNT(*) n'.$filters['from'],$filters['args'])['n'];
        if($export){
            demand($count<=self::MAX_EXPORT_DVS,
                'More than 1,500 DVs match. Choose a month, school, or fund before exporting.',409);
            $limit=max(1,$count);$offset=0;
        }else{
            $limit=self::PAGE_LIMIT;$offset=($page-1)*$limit;
            demand($offset<=100000,'Report page exceeds the navigation limit',422);
        }
        $rows=$this->rows($filters,$division,$limit,$offset);
        // Reuse the exact validated selection predicates, including notice and check
        // filters, from the DV selection; avoids giving a misleading dashboard total.
        $summary=one($this->db,'SELECT COUNT(w.id) component_count'.$filters['tax_from'],$filters['tax_args']);
        // Sum the saved DV deductions ONCE per matching DV. This is aggregation
        // for reporting, not a new tax calculation from rates or tax bases.
        $wholeDvTotals=one($this->db,'SELECT COALESCE(SUM(v.deductions),0) total_withheld'.
            $filters['from'],$filters['args']);
        $byAtc=q($this->db,'SELECT w.atc_code,COUNT(*) component_count,
            COALESCE(SUM(w.amount),0) withheld'.$filters['tax_from'].
            ' GROUP BY w.atc_code ORDER BY w.atc_code LIMIT 150',$filters['tax_args'])->fetchAll();
        $byDisposition=q($this->db,'SELECT w.disposition,COUNT(*) component_count,
            COALESCE(SUM(w.amount),0) withheld'.$filters['tax_from'].
            ' GROUP BY w.disposition ORDER BY w.disposition',$filters['tax_args'])->fetchAll();
        $bySource=q($this->db,'SELECT f.source_code,COUNT(*) dv_count,
            COALESCE(SUM(v.deductions),0) withheld'.$filters['from'].
            ' GROUP BY f.source_code ORDER BY f.source_code LIMIT 200',$filters['args'])->fetchAll();
        // Per-office subtotals are available even when the DV page is limited to 50.
        $byOffice=q($this->db,'SELECT o.id office_id,o.name office_name,o.kind office_kind,
            COUNT(*) dv_count,COALESCE(SUM(v.deductions),0) withheld'.$filters['from'].
            ' GROUP BY o.id,o.name,o.kind ORDER BY o.name LIMIT 500',$filters['args'])->fetchAll();
        $result=['office_kind'=>'DIVISION','division_id'=>$division,'filters'=>$filters['applied'],
            'page'=>$page,'limit'=>$limit,'total_dvs'=>$count,
            'total_pages'=>$count===0?0:(int)ceil($count/self::PAGE_LIMIT),
            'summary'=>['component_count'=>(int)$summary['component_count'],
                'total_withheld'=>(string)$wholeDvTotals['total_withheld'],
                'by_atc'=>$byAtc,'by_disposition'=>$byDisposition,'by_source'=>$bySource,'by_office'=>$byOffice],
            'rows'=>$rows,
            'note'=>'Gross, total withheld and net are the original saved DV figures. All tax bases, ATCs, rates and individual amounts are the original saved components; filters select matching DVs without recalculation. Review only means Division acknowledgment, not BIR remittance.'];
        if(!$export)$result['options']=$this->options($division);
        return $result;
    }
    private static function csvValue(mixed $value):string {
        $text=str_replace(["\r","\n","\0"],' ',(string)($value??''));
        // Stop spreadsheet formula injection from organization names and references.
        if(preg_match('/^[\s]*[=+\-@]/u',$text))$text="'".$text;
        return $text;
    }
    public function exportCsv(array $input):never {
        $shape=self::choice($input,'layout',['DV','ATC'],'DV');
        $report=$this->report($input,true);
        $entries=[];
        if($shape==='DV'){
            $entries[]=['Office','Office type','Fiscal year','Fund','Source code','DV number',
                'DV date','Total amount / gross DV (PHP)','Tax base(s) per ATC (PHP)',
                'ATC code(s)','Historical rate(s) (%)','Amount withheld (PHP)',
                'Net payment per DV (PHP)','ATC breakdown',
                'Division notice review','Check released at','BIR settlement evidence'];
            foreach($report['rows'] as $r){
                $bases=[];$codes=[];$rates=[];$detail=[];
                foreach($r['components'] as $c){
                    $bases[]=$c['tax_base'];$codes[]=$c['atc_code'];
                    $rates[]=$c['rate_percent'];
                    $detail[]=$c['atc_code'].' @ '.$c['rate_percent'].'%: base '.$c['tax_base'].', withheld '.$c['amount'].' ('.$c['disposition'].')';
                }
                $entries[]=[$r['office_name'],$r['office_kind'],$r['fiscal_year'],$r['fund_name'],
                    $r['source_code'],$r['dv_number'],$r['document_date'],$r['gross_amount'],
                    implode(' | ',$bases),implode(' | ',$codes),implode(' | ',$rates),
                    $r['withheld'],$r['net_payment'],implode('; ',$detail),
                    $r['notice_status'],$r['check_released_at'],'NOT VERIFIED IN THIS REPORT'];
            }
        }else{
            $entries[]=['Office','Office type','Fiscal year','Fund','Source code','DV number',
                'DV date','Gross DV (PHP)','Net payment per DV (PHP)',
                'ATC code','Historical rate (%)','Disposition','Tax base (PHP)','Withheld (PHP)',
                'Division notice review','Check released at','BIR settlement evidence'];
            foreach($report['rows'] as $r)foreach($r['components'] as $c)
                $entries[]=[$r['office_name'],$r['office_kind'],$r['fiscal_year'],$r['fund_name'],
                    $r['source_code'],$r['dv_number'],$r['document_date'],
                    $r['gross_amount'],$r['net_payment'],$c['atc_code'],$c['rate_percent'],
                    $c['disposition'],$c['tax_base'],$c['amount'],$r['notice_status'],
                    $r['check_released_at'],'NOT VERIFIED IN THIS REPORT'];
        }
        demand(count($entries)<=self::MAX_EXPORT_COMPONENTS+1,
            'Too many tax rows in one export; narrow your report filters',409);
        $applied=$report['filters'];
        $period=($applied['from_date']!=='' || $applied['to_date']!=='')
            ?($applied['from_date']?:'start').'_to_'.($applied['to_date']?:'latest')
            :($applied['month']?:'all-periods');
        $name='IPDAMS_Division_Withholding_'.$period.'_'.$shape.'.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$name.'"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        $out=fopen('php://output','wb');
        fwrite($out,"\xEF\xBB\xBF"); // Excel UTF-8 compatibility
        foreach($entries as $line)fputcsv($out,array_map([self::class,'csvValue'],$line),',','"','');
        fclose($out);exit;
    }
}
