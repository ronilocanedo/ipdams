<?php
declare(strict_types=1);
require_once __DIR__.'/TextbookBookProfiles.php';
/**
 * v0.2.285 - Read-only, scoped edition-level book ledger.
 * Based on actual textbook_movements and latest documentary snapshots.
 * Catalog metadata, unposted allocations and reported losses never become stock.
 */
final class TextbookBookLedger {
    public function __construct(private PDO $db, private array $user) {}
    private static function guard(bool $ok,string $message,int $code=422):void {demand($ok,$message,$code);}
    private static function integer(mixed $v):int {
        self::guard((is_string($v)||is_int($v)) && preg_match('/^[1-9][0-9]{0,9}$/D',(string)$v)===1,
            'Invalid book or ledger identifier');
        return (int)$v;
    }
    private static function isGood(string $condition):bool {
        return in_array(strtolower(trim($condition)),['serviceable','good','new - serviceable','new/serviceable'],true);
    }
    /** Match the authenticated workspace, never a client-supplied organization hierarchy. */
    private function offices(int $org,string $view):array {
        scope($this->db,$this->user,$org);
        $office=one($this->db,'SELECT id,name,kind,active FROM organizations WHERE id=?',[$org]);
        self::guard($office!==null && (int)$office['active']===1 &&
            in_array($office['kind'],['SCHOOL','DIVISION'],true) && in_array($this->user['role']??'',[$office['kind'],'SUPER_ADMIN'],true),
            'An active school or Division workspace is required',403);
        if($view==='OFFICE')return [['id'=>$org,'name'=>(string)$office['name']]];
        self::guard($view==='DIVISION' && $office['kind']==='DIVISION',
            'Only the supervising Division may view its schools\' consolidated textbook ledger',403);
        $rows=q($this->db,"SELECT id,name FROM organizations WHERE active=1 AND
            (id=? OR (kind='SCHOOL' AND parent_division_id=?)) ORDER BY id LIMIT 501",[$org,$org])->fetchAll(PDO::FETCH_ASSOC);
        self::guard(count($rows)<=500 && count($rows)>0,'Division has too many offices for this overview; use the office ledger',409);
        return array_map(static fn(array $x):array=>['id'=>(int)$x['id'],'name'=>(string)$x['name']],$rows);
    }
    public function read(array $input):array {
        $org=self::integer($input['organization_id']??0);
        $bookId=self::integer($input['book_id']??0);
        $view=strtoupper(trim((string)($input['view']??'OFFICE')));
        self::guard(in_array($view,['OFFICE','DIVISION'],true),'Select a valid ledger view');
        $before=trim((string)($input['before_id']??''));
        self::guard($before==='' || (preg_match('/^[1-9][0-9]{0,18}$/D',$before)===1
                && (string)(int)$before===$before),
            'Invalid history page cursor');
        $offices=$this->offices($org,$view);
        // The existing book profile policy is the authority for visibility, including drafts.
        $profile=(new TextbookBookProfiles($this->db,$this->user))->detail(['organization_id'=>$org,'book_id'=>$bookId]);
        $snap=$profile['snapshot'];$fields=$snap['fields']??[];
        $titleId=(int)($snap['title_id']??0);
        $book=['id'=>$bookId,'title'=>(string)($fields['title']??''),
            'isbn'=>(string)($fields['isbn13']??''),'lr_code'=>(string)($fields['official_lr_code']??''),
            'grade'=>(string)($fields['grade']??''),'authors'=>(string)($fields['authors']??''),
            'edition'=>(string)($fields['edition']??''),'status'=>(string)($snap['status']??''),
            'linked_to_stock'=>$titleId>0];
        $totals=['received_or_opening'=>0,'received_from_transfers'=>0,'transferred_out'=>0,
            'issued_or_distributed'=>0,'returned'=>0,'disposed'=>0,'net_stock_movements'=>0,
            'on_hand'=>0,'serviceable_on_hand'=>0,'other_condition_on_hand'=>0,
            'teacher_unreturned'=>0,'loss_reported_pending_relief'=>0,'teacher_damage_reported'=>0,
            'marked_for_disposal'=>0,'disposed_documented'=>0,'movement_count'=>0];
        $out=['book'=>$book,'view'=>$view,'scope_label'=>$view==='DIVISION'?'Division and supervised schools':$offices[0]['name'],
            'offices'=>$offices,'totals'=>$totals,'batches'=>[],'movements'=>[],
            'documentary_events'=>[],'next_before_id'=>null,
            'note'=>'Read-only activity from actual recorded movements. Allocations, requests, losses and proposed disposals do not post stock. Teacher-issued copies are tracked outside storeroom stock.'];
        if(!$titleId){$out['note']='This book profile has no stock-linked ISBN edition yet. Drafts and LR-code-only profiles do not generate an inventory ledger.';return $out;}
        $ids=array_column($offices,'id');$slots=implode(',',array_fill(0,count($ids),'?'));
        $params=array_merge([$titleId],$ids);
        $batchRows=q($this->db,"SELECT b.id,b.organization_id,o.name AS office_name,b.batch,b.condition_note,b.storage,
             COALESCE(SUM(m.quantity_delta),0) AS on_hand FROM textbook_batches b
             JOIN organizations o ON o.id=b.organization_id
             LEFT JOIN textbook_movements m ON m.batch_id=b.id AND m.organization_id=b.organization_id
             WHERE b.title_id=? AND b.organization_id IN ($slots)
             GROUP BY b.id,b.organization_id,o.name,b.batch,b.condition_note,b.storage ORDER BY b.id LIMIT 2001",$params)
            ->fetchAll(PDO::FETCH_ASSOC);
        self::guard(count($batchRows)<=2000,'Too many batches to summarize safely. Narrow the ledger scope.',409);
        foreach($batchRows as $r){
            $on=(int)$r['on_hand'];$totals['on_hand']+=$on;
            if(self::isGood((string)$r['condition_note']))$totals['serviceable_on_hand']+=$on;
            else $totals['other_condition_on_hand']+=$on;
            $out['batches'][]=['id'=>(int)$r['id'],'organization_id'=>(int)$r['organization_id'],
                'office'=>(string)$r['office_name'],'batch'=>(string)$r['batch'],
                'condition'=>(string)($r['condition_note']??''),'storage'=>(string)($r['storage']??''),'on_hand'=>$on];
        }
        $movementSummary=one($this->db,"SELECT COUNT(*) AS movement_count,
            COALESCE(SUM(m.quantity_delta),0) AS net_stock_movements,
            COALESCE(SUM(CASE WHEN m.event_type='RECEIPT' AND m.reference NOT LIKE 'TBX %' THEN m.quantity_delta ELSE 0 END),0) AS received_or_opening,
            COALESCE(SUM(CASE WHEN m.event_type='RECEIPT' AND m.reference LIKE 'TBX %' THEN m.quantity_delta ELSE 0 END),0) AS received_from_transfers,
            COALESCE(SUM(CASE WHEN m.event_type='TRANSFER' AND m.reference LIKE 'TBX %' THEN -m.quantity_delta ELSE 0 END),0) AS transferred_out,
            COALESCE(SUM(CASE WHEN m.event_type='DISTRIBUTION' THEN -m.quantity_delta ELSE 0 END),0) AS issued_or_distributed,
            COALESCE(SUM(CASE WHEN m.event_type='RETURN' AND m.reference LIKE 'TBI %' THEN m.quantity_delta ELSE 0 END),0) AS returned,
            COALESCE(SUM(CASE WHEN m.event_type='DISPOSAL' THEN -m.quantity_delta ELSE 0 END),0) AS disposed
            FROM textbook_movements m JOIN textbook_batches b ON b.id=m.batch_id AND b.organization_id=m.organization_id
            WHERE b.title_id=? AND m.organization_id IN ($slots)",$params)??[];
        foreach(['movement_count','net_stock_movements','received_or_opening','received_from_transfers',
                 'transferred_out','issued_or_distributed','returned','disposed'] as $key)
            $totals[$key]=(int)($movementSummary[$key]??0);
        // Movement detail is paginated. Never infer lifetime totals from just the visible page.
        $movementParams=$params;
        $cursorSql='';
        if($before!==''){$cursorSql=' AND m.id<?';$movementParams[]=(int)$before;}
        $rows=q($this->db,"SELECT m.id,m.created_at,m.organization_id,o.name AS office_name,
             b.batch,b.condition_note,m.quantity_delta,m.event_type,m.reference
             FROM textbook_movements m
             JOIN textbook_batches b ON b.id=m.batch_id AND b.organization_id=m.organization_id
             JOIN organizations o ON o.id=m.organization_id
             WHERE b.title_id=? AND m.organization_id IN ($slots)$cursorSql ORDER BY m.id DESC LIMIT 101",
             $movementParams)->fetchAll(PDO::FETCH_ASSOC);
        $more=count($rows)>100;$rows=array_slice($rows,0,100);
        foreach($rows as $r)$out['movements'][]=['id'=>(int)$r['id'],'date'=>(string)$r['created_at'],
            'organization_id'=>(int)$r['organization_id'],'office'=>(string)$r['office_name'],
            'batch'=>(string)$r['batch'],'condition'=>(string)$r['condition_note'],
            'event'=>(string)$r['event_type'],'quantity_delta'=>(int)$r['quantity_delta'],
            'reference'=>(string)$r['reference']];
        $out['next_before_id']=$more && $rows?(int)end($rows)['id']:null;
        // Report documentary events which intentionally have no stock movement.
        // JSON title_id is exact; never match by title spelling or by edition label.
        $docs=q($this->db,"SELECT r.id,r.doc_type,r.display_number,r.organization_id,o.name AS office_name,v.snapshot_json
           FROM document_registry r
           JOIN organizations o ON o.id=r.organization_id
           JOIN document_versions v ON v.organization_id=r.organization_id AND v.doc_type=r.doc_type AND v.entity_id=r.entity_id
               AND v.version_no=(SELECT MAX(z.version_no) FROM document_versions z WHERE z.organization_id=r.organization_id
                   AND z.doc_type=r.doc_type AND z.entity_id=r.entity_id)
           WHERE r.organization_id IN ($slots) AND r.doc_type IN ('TBI','TBD','TBQ')
             AND CAST(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.title_id')) AS UNSIGNED)=?
           ORDER BY r.id DESC LIMIT 2001",array_merge($ids,[$titleId]))->fetchAll(PDO::FETCH_ASSOC);
        self::guard(count($docs)<=2000,'Too many documentary records for a complete ledger. Narrow to one office.',409);
        foreach($docs as $r){
            $s=json_decode((string)$r['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
            self::guard(is_array($s) && ($s['type']??'')===$r['doc_type'] &&
                (int)($s['organization_id']??0)===(int)$r['organization_id'] &&
                (int)($s['title_id']??0)===$titleId,'Book documentary record provenance needs review',409);
            $kind=(string)$r['doc_type'];$officeName=(string)$r['office_name'];$num=(string)$r['display_number'];
            if($kind==='TBI'){
                $totals['teacher_unreturned']+=max(0,(int)$s['issued_quantity']-(int)$s['returned_serviceable']-(int)$s['returned_damaged']);
                $totals['loss_reported_pending_relief']+=(int)($s['lost_pending']??0);
                $totals['teacher_damage_reported']+=(int)($s['damage_notified']??0);
                foreach($s['events']??[] as $e){
                    if(!in_array($e['kind']??'',['LOSS','DAMAGE'],true))continue;
                    $out['documentary_events'][]=['date'=>(string)($e['event_on']??''),'office'=>$officeName,
                        'type'=>(string)$e['kind'],'quantity'=>(int)($e['kind']==='LOSS'?($e['lost_quantity']??0):($e['damage_reported_quantity']??0)),
                        'reference'=>$num.' / '.(string)($e['paper_reference']??''),
                        'note'=>'Reported against teacher accountability; no additional stock movement.'];
                }
            }elseif($kind==='TBD'){
                if(in_array($s['status']??'',['MARKED','AUTHORIZED','PARTIALLY_DISPOSED'],true))
                    $totals['marked_for_disposal']+=max(0,(int)($s['quantity']??0)-(int)($s['disposed_quantity']??0));
                $totals['disposed_documented']+=(int)($s['disposed_quantity']??0);
                $out['documentary_events'][]=['date'=>(string)($s['marked_on']??''),'office'=>$officeName,
                    'type'=>'DISPOSAL '.(string)($s['status']??''),'quantity'=>(int)($s['quantity']??0),
                    'reference'=>$num.' / '.(string)($s['paper_reference']??''),
                    'note'=>'Proposal/authorization is documentary; only actual disposal reduces stock.'];
            }elseif($kind==='TBQ'){
                $out['documentary_events'][]=['date'=>(string)($s['date']??''),'office'=>$officeName,
                    'type'=>'QUARANTINE','quantity'=>(int)($s['quantity']??0),
                    'reference'=>$num.' / '.(string)($s['paper_reference']??''),
                    'note'=>'Internal condition reclassification; not a transfer to another office.'];
            }
        }
        usort($out['documentary_events'],static fn($a,$b)=>strcmp($b['date'],$a['date']));
        $out['totals']=$totals;
        if($totals['net_stock_movements']!==$totals['on_hand'])
            $out['warning']='Recorded batch stock does not reconcile to movements. Review the textbook ledger before relying on totals.';
        return $out;
    }
}
