<?php
declare(strict_types=1);
/**
 * Two-office receipt for prepared registered external ITR/PTR.
 * Does NOT move the original asset, IAR or procurement records between tenants.
 * Creates a separately scoped receiving asset with a provable chain of title.
 */
final class PropertyTransferAcceptance {
    private const BINDING='PROPERTY_RECEIVING_LOGIN_V1';
    public function __construct(private PDO $db, private array $user) {}
    private static function must(bool $ok,string $msg,int $http=409):void {demand($ok,$msg,$http);}
    private static function today():string {return (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');}
    private function organization(int $id,bool $scope=true):array {
        self::must($id>0,'Choose the receiving school or Division Office',422);
        if($scope)scope($this->db,$this->user,$id);
        $r=one($this->db,"SELECT id,kind,name,active,parent_division_id FROM organizations WHERE id=?",[$id]);
        self::must($r!==null&&(int)$r['active']===1&&in_array($r['kind'],['SCHOOL','DIVISION'],true),'Receiving organization must be active',403);
        self::must(($this->user['role']??'')==='SUPER_ADMIN'||($this->user['role']??'')===$r['kind'],
            'Open the correct authenticated receiving organization workspace',403);
        return $r;
    }
    private function appointment(int $org):array {
        $row=one($this->db,'SELECT id,config_json,effective_from FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',
            [$org,'ORG_SUPPLY_PROPERTY_CUSTODIAN_V1']);
        self::must($row!==null&&(string)$row['effective_from']<=self::today(),
            'Receiving school/Division must first appoint its Property Custodian in BAC & Heads',409);
        $cfg=json_decode((string)$row['config_json'],true,32,JSON_THROW_ON_ERROR);
        self::must(is_array($cfg)&&(int)($cfg['employee_id']??0)>0,'Property Custodian appointment needs correction',409);
        return ['employee_id'=>(int)$cfg['employee_id'],'appointment_version'=>(int)$row['id']];
    }
    private function receiverRole(array $org):string {return (string)$org['kind'];}
    private function binding(int $org):?array {
        $row=one($this->db,'SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1',
            [$org,self::BINDING]);
        if($row===null)return null;
        $v=json_decode((string)$row['config_json'],true,32,JSON_THROW_ON_ERROR);
        self::must(is_array($v),'Receiving user authorization needs administrator review',409);
        return $v+['version_id'=>(int)$row['id']];
    }
    /** Official appointment identifies the employee; an audited binding identifies the login. */
    private function receiver(int $org,bool $enforceLogin=true):array {
        $o=$this->organization($org);
        $a=$this->appointment($org);$binding=$this->binding($org);
        $assignment=one($this->db,'SELECT a.employee_id,a.effective_from,a.effective_to FROM employee_assignments a WHERE a.organization_id=? AND a.employee_id=? ORDER BY a.id DESC LIMIT 1',
            [$org,$a['employee_id']]);
        self::must($assignment!==null&&(string)$assignment['effective_from']<=self::today()&&
            ($assignment['effective_to']===null||(string)$assignment['effective_to']>self::today()),
            'The appointed receiving Property Custodian needs an active employee assignment in this office',403);
        self::must($binding!==null&&(int)($binding['employee_id']??0)===$a['employee_id']
            && (int)($binding['appointment_version']??0)===$a['appointment_version'],
            'Link the currently appointed Property Custodian to a receiving login before accepting property',409);
        $id=(int)($binding['user_id']??0);
        $account=one($this->db,'SELECT id,role,active,full_name FROM users WHERE id=?',[$id]);
        self::must($account!==null&&(int)$account['active']===1&&(string)$account['role']===$this->receiverRole($o),
            'The appointed receiving login is not an active account of this office type',403);
        self::must(one($this->db,'SELECT 1 FROM user_organization_scopes WHERE user_id=? AND organization_id=?',[$id,$org])!==null,
            'Receiving login lacks authorization for the office',403);
        if($enforceLogin)self::must((int)$this->user['id']===$id,
            'Sign in with the Property Custodian account formally linked to this office to accept the transfer',403);
        return ['actor_user_id'=>$id,'employee_id'=>$a['employee_id'],'appointment_version'=>$a['appointment_version'],
            'binding_version'=>(int)$binding['version_id'],'name'=>(string)$account['full_name']];
    }
    /** Audited, explicit administrator matching of a login to the formally appointed custodian. */
    public function bind(array $p):array {
        $org=(int)($p['organization_id']??0);$o=$this->organization($org);
        self::must($this->user['role']==='SUPER_ADMIN',
            'Super Administrator must link the appointed custodian to an authorized receiving login',403);
        $reason=trim((string)($p['reason']??''));self::must(mbSafeLen($reason)>=20 && mbSafeLen($reason)<=500,
            'Provide an official account-to-custodian authorization reference (20–500 characters)',422);
        $uid=(int)($p['user_id']??0);self::must($uid>0,'Select an existing receiving-officer login',422);
        $outer=$this->db->inTransaction();if(!$outer)$this->db->beginTransaction();
        try {
            $row=one($this->db,'SELECT id FROM organizations WHERE id=? AND active=1 FOR UPDATE',[$org]);
            self::must($row!==null,'Receiving organization is inactive',409);
            $a=$this->appointment($org);
            $login=one($this->db,'SELECT id,role,active,full_name FROM users WHERE id=? FOR UPDATE',[$uid]);
            self::must($login!==null&&(int)$login['active']===1&&$login['role']===$o['kind'],'Choose an active account whose role matches the receiving office',422);
            self::must(one($this->db,'SELECT 1 FROM user_organization_scopes WHERE user_id=? AND organization_id=?',[$uid,$org])!==null,
                'The receiving user must have an existing explicit organization scope',403);
            $payload=['user_id'=>$uid,'employee_id'=>$a['employee_id'],'appointment_version'=>$a['appointment_version']];
            q($this->db,'INSERT INTO configuration_versions(organization_id,config_key,config_json,effective_from,review_note) VALUES (?,?,?,?,?)',
                [$org,self::BINDING,json_encode($payload,JSON_THROW_ON_ERROR),self::today(),$reason]);
            q($this->db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
                [$org,(int)$this->user['id'],'PROPERTY_RECEIVING_LOGIN_BOUND','USER',$uid,
                    json_encode(['employee_id'=>$a['employee_id'],'appointment_version'=>$a['appointment_version'],'reason'=>$reason],JSON_THROW_ON_ERROR)]);
            if(!$outer)$this->db->commit();return ['assigned_user_id'=>$uid,'employee_id'=>$a['employee_id']];
        }catch(Throwable $e){if(!$outer&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function transfer(int $id,bool $lock=false):array {
        $query="SELECT r.id,r.organization_id,r.doc_type,r.display_number,v.snapshot_json,v.version_no
            FROM document_registry r JOIN document_versions v ON v.organization_id=r.organization_id
              AND v.doc_type=r.doc_type AND v.entity_id=r.entity_id
              AND v.version_no=(SELECT MAX(x.version_no) FROM document_versions x WHERE x.organization_id=r.organization_id AND x.doc_type=r.doc_type AND x.entity_id=r.entity_id)
            WHERE r.id=? AND r.doc_type IN ('ITR','PTR')".($lock?' FOR UPDATE':'');
        $r=one($this->db,$query,[$id]);self::must($r!==null,'Transfer record cannot be found',404);
        $s=json_decode((string)$r['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
        self::must(is_array($s)&&($s['type']??null)===$r['doc_type']&&(int)($s['source_school_id']??0)===(int)$r['organization_id'],
            'Transfer record failed source verification',409);
        return ['id'=>(int)$r['id'],'source'=>(int)$r['organization_id'],'type'=>(string)$r['doc_type'],
            'number'=>(string)$r['display_number'],'version'=>(int)$r['version_no'],'snapshot'=>$s];
    }
    private static function receiptDate(mixed $value,string $prepared):string {
        self::must(is_string($value)&&preg_match('/^\d{4}-\d\d-\d\d$/D',$value)===1,'Choose receipt date',422);
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Asia/Manila'));
        self::must($dt!==false&&$dt->format('Y-m-d')===$value&&$value<=self::today()&&$value>=substr($prepared,0,10),
            'Receipt must be a valid date on or after transfer preparation, and not in the future',422);
        return $value;
    }
    private static function note(mixed $value,string $label,int $min,int $max):string {
        self::must(is_string($value),'Invalid '.$label,422);$s=trim($value);
        self::must(mbSafeLen($s)>=$min&&mbSafeLen($s)<=$max,$label.' must contain '.$min.'–'.$max.' characters',422);
        return $s;
    }
    private static function receivedValues(array $items,mixed $input):array {
        self::must(is_array($input)&&array_is_list($input)&&count($input)===count($items),
            'Review the complete set of transferred property lines',422);
        $result=[];foreach($input as $i=>$row){
            self::must(is_array($row),'Invalid inspection line',422);
            self::must((int)($row['source_asset_id']??0)===(int)$items[$i]['asset_id'],
                'Physical inspection rows do not match the saved transfer',409);
            $condition=(string)($row['condition']??'');
            self::must(in_array($condition,['Serviceable','Needs Maintenance','For Inspection','For Repair','Under Repair'],true),
                'Select an observed condition for each property',422);
            $itemId=(int)($row['receiving_item_id']??0);self::must($itemId>0,'Select a matching destination catalog item for each property',422);
            $location=self::note($row['location']??'','Physical receipt location',2,180);
            $result[]=['source_asset_id'=>(int)$items[$i]['asset_id'],'condition'=>$condition,
                'location'=>$location,'receiving_item_id'=>$itemId];
        }
        return $result;
    }
    private function mappedItem(int $dest,int $itemId,string $category):array {
        $r=one($this->db,'SELECT id,name,category,active,owner_organization_id FROM item_catalog WHERE id=?',[$itemId]);
        self::must($r!==null&&(int)$r['active']===1&&(string)$r['category']===$category&&
            ($r['owner_organization_id']===null||(int)$r['owner_organization_id']===$dest),
            'Destination item must belong to the receiving office or shared catalog and have the same property category',409);
        return $r;
    }
    /** Receipts are all-or-nothing. Never clone acquisition journals or reassign source IAR. */
    public function accept(array $p):array {
        $dest=(int)($p['organization_id']??0);$this->organization($dest);$receiver=$this->receiver($dest,true);
        $id=(int)($p['transfer_id']??0);self::must($id>0,'Choose an incoming transfer',422);
        $confirmed=($p['physical_received']??false)===true;
        self::must($confirmed,'Physically inspect and confirm receipt of every listed property',422);
        $reference=self::note($p['paper_reference']??'','Receiving document / handover reference',8,240);
        $reason=self::note($p['inspection_note']??'','Inspection remarks',0,500);
        $initial=$this->transfer($id);$s=$initial['snapshot'];
        self::must(($s['transfer_kind']??null)==='EXTERNAL'&&($s['status']??null)==='PENDING_RECEIPT'&&
            !($s['external_destination_unverified']??false)&&(int)($s['destination_organization_id']??0)===$dest,
            'Only the registered receiving office can accept its pending external transfer',403);
        $date=self::receiptDate($p['received_on']??null,(string)$s['prepared_at']);
        $saved=$s['items']??[];self::must(is_array($saved)&&count($saved)>0&&count($saved)<=20,
            'Transfer property lines require review',409);
        $inspect=self::receivedValues($saved,$p['inspections']??null);
        $source=(int)$initial['source'];self::must($source!==$dest,'External receipt requires distinct organizations',409);
        $outer=$this->db->inTransaction();if(!$outer)$this->db->beginTransaction();
        try {
            $lockedOrgs=[$source,$dest];sort($lockedOrgs,SORT_NUMERIC);
            foreach($lockedOrgs as $oid){$r=one($this->db,'SELECT id,active FROM organizations WHERE id=? FOR UPDATE',[$oid]);
                self::must($r!==null&&(int)$r['active']===1,'Source/receiving office changed, receipt cancelled',409);}
            // The administrator's binding update locks this office row too: after
            // that lock is obtained, recheck appointment and login binding to
            // prevent acceptance under a newly superseded officer appointment.
            $freshReceiver=$this->receiver($dest,true);
            self::must($freshReceiver['appointment_version']===$receiver['appointment_version'] &&
                $freshReceiver['binding_version']===$receiver['binding_version'] &&
                $freshReceiver['actor_user_id']===$receiver['actor_user_id'],
                'Property Custodian appointment or login binding changed during receipt; reload',409);
            // Lock original source assets before transfer registry, consistent with transfer preparation.
            $sourceAssets=[];$ids=array_column($saved,'asset_id');sort($ids,SORT_NUMERIC);
            foreach($ids as $aid){$a=one($this->db,'SELECT a.*,i.category,i.name AS item_name FROM assets a JOIN item_catalog i ON i.id=a.item_id WHERE a.id=? AND a.organization_id=? FOR UPDATE',[(int)$aid,$source]);
                self::must($a!==null&&$a['status']==='ACTIVE','Source property is no longer active',409);
                $sourceAssets[(int)$aid]=$a;}
            $head=$this->transfer($id,true);$current=$head['snapshot'];
            self::must($head['version']===$initial['version']&&$current['status']==='PENDING_RECEIPT'
                &&(int)($current['destination_organization_id']??0)===$dest
                &&(string)$head['number']===(string)$initial['number'],
                'The transfer changed while receiving; refresh before accepting',409);
            $accepted=[];
            foreach($saved as $index=>$line){
                $aid=(int)$line['asset_id'];$src=$sourceAssets[$aid]??null;$review=$inspect[$index];
                self::must($src!==null&&(string)$src['property_tag']===(string)$line['property_tag']
                    &&(int)$src['item_id']===(int)$line['item_id']
                    &&(string)$src['acquisition_cost']===(string)$line['unit_cost']
                    &&(string)$src['category']===(string)$line['category'],
                    'Original source asset does not match the prepared transfer',409);
                $life=one($this->db,"SELECT snapshot_json FROM document_versions WHERE organization_id=? AND doc_type='ASSET_LIFECYCLE' AND entity_id=? ORDER BY version_no DESC LIMIT 1",[$source,$aid]);
                if($life){$l=json_decode((string)$life['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
                    self::must(is_array($l)&&($l['state']??'')==='SERVICEABLE','Formal unserviceable/disposal state prevents receiving until reconciled',409);}
                $mapped=$this->mappedItem($dest,$review['receiving_item_id'],(string)$line['category']);
                self::must(one($this->db,'SELECT id FROM assets WHERE organization_id=? AND property_tag=? LIMIT 1',[$dest,$src['property_tag']])===null,
                    'The receiving office already has this permanent property number; reconcile before acceptance',409);
                if(trim((string)($src['serial_number']??''))!=='')self::must(one($this->db,'SELECT id FROM assets WHERE organization_id=? AND serial_number=? LIMIT 1',[$dest,$src['serial_number']])===null,
                    'Duplicate serial number in receiving register',409);
                $meta=PropertyBatchMetadata::decode($src['property_profile']??null);
                $shared=$meta['shared']??[];$unit=$meta['unit']??[];
                $shared['origin']='HISTORICAL_OPENING';$shared['acquisition_type']='TRANSFER';
                foreach(['historical_po','historical_iar','historical_dv'] as $k)$shared[$k]='';
                $shared=PropertyBatchMetadata::shared($shared,'HISTORICAL_OPENING');
                $unit=PropertyBatchMetadata::unit(['serial_number'=>$src['serial_number']??'','location'=>$review['location'],
                    'condition_note'=>$review['condition'],'legacy_property_number'=>$unit['legacy_property_number']??'',
                    'reported_custodian'=>'']);
                $profile=json_decode(PropertyBatchMetadata::profile($shared,$unit),true,16,JSON_THROW_ON_ERROR);
                $profile['transfer_origin']=['source_organization_id'=>$source,'source_asset_id'=>$aid,
                    'transfer_document_id'=>$id,'transfer_type'=>$head['type'],
                    'transfer_number'=>$head['number'],'received_on'=>$date,
                    'original_iar_line_id'=>$src['iar_line_id']===null?null:(int)$src['iar_line_id']];
                q($this->db,"INSERT INTO assets (organization_id,item_id,iar_line_id,property_tag,serial_number,acquisition_cost,acquired_on,opening_record,custodian,location,condition_note,status,registration_batch_key,property_profile)
                    VALUES (?,?,NULL,?,?,?,?,?,NULL,?,?,'ACTIVE',?,?)",[$dest,$mapped['id'],$src['property_tag'],$src['serial_number'],$src['acquisition_cost'],$src['acquired_on'],0,
                    $review['location'],$review['condition'],bin2hex(random_bytes(16)),json_encode($profile,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
                $destAsset=(int)$this->db->lastInsertId();self::must($destAsset>0,'Destination property registration failed',500);
                // Original finalized ICS/PAR survives, with a transfer evidence link to close active responsibility.
                $open=PropertyReturnWorkflow::openAccountability($this->db,$source,$aid);
                self::must(trim((string)($src['custodian']??''))===''||$open!==null,
                    'Source employee custody has no validated current ICS/PAR; reconcile before external acceptance',409);
                if($open!==null){
                    q($this->db,'INSERT INTO document_links(organization_id,source_type,source_id,target_type,target_id) VALUES (?,?,?,?,?)',
                        [$source,$open['doc_type'],(int)$open['id'],$head['type'],$id]);
                }
                q($this->db,'INSERT INTO asset_movements(asset_id,event_type,from_custodian,to_custodian,reason,actor_id) VALUES (?,\'TRANSFER\',?,?,?,?)',
                    [$aid,$src['custodian'],null,$head['type'].' '.$head['number'].' accepted by '.(string)$receiver['name'].' on '.$date,(int)$this->user['id']]);
                $changed=q($this->db,"UPDATE assets SET custodian=NULL,status='TRANSFERRED' WHERE id=? AND organization_id=? AND status='ACTIVE'",[$aid,$source])->rowCount();
                self::must($changed===1,'Source asset changed during receipt; transaction rolled back',409);
                q($this->db,'INSERT INTO asset_movements(asset_id,event_type,from_custodian,to_custodian,reason,actor_id) VALUES (?,\'TRANSFER\',?,?,?,?)',
                    [$destAsset,null,null,$head['type'].' '.$head['number'].' received as school/Division property from '.$source.' on '.$date,(int)$this->user['id']]);
                q($this->db,'INSERT INTO document_links(organization_id,source_type,source_id,target_type,target_id) VALUES (?,?,?,?,?)',
                    [$dest,$head['type'],$id,'ASSET',$destAsset]);
                q($this->db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
                    [$dest,(int)$this->user['id'],'EXTERNAL_PROPERTY_RECEIVED','ASSET',$destAsset,json_encode([
                        'origin_org'=>$source,'origin_asset'=>$aid,'transfer_id'=>$id,'tag'=>$src['property_tag'],
                        'condition'=>$review['condition'],'location'=>$review['location'],'catalog_id'=>$mapped['id']],JSON_THROW_ON_ERROR)]);
                q($this->db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
                    [$source,(int)$this->user['id'],'EXTERNAL_PROPERTY_TRANSFERRED_OUT','ASSET',$aid,json_encode([
                        'receiver_org'=>$dest,'receiver_asset'=>$destAsset,'transfer_id'=>$id],JSON_THROW_ON_ERROR)]);
                $accepted[]=['source_asset_id'=>$aid,'receiving_asset_id'=>$destAsset,'property_tag'=>$src['property_tag'],
                    'condition_on_receipt'=>$review['condition'],'receiving_location'=>$review['location'],
                    'receiving_catalog_item_id'=>(int)$mapped['id']];
            }
            $current['status']='RECEIVED';$current['received_on']=$date;
            $current['received_at']=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format(DATE_ATOM);
            $current['received_by_user_id']=(int)$this->user['id'];$current['receiving_employee_id']=$receiver['employee_id'];
            $current['receiver_appointment_version_id']=$receiver['appointment_version'];
            $current['receiver_binding_version_id']=$receiver['binding_version'];
            $current['receipt_reference']=$reference;$current['inspection_note']=$reason;
            $current['received_items']=$accepted;$current['wet_signature_captured']=false;
            q($this->db,'INSERT INTO document_versions(organization_id,doc_type,entity_id,version_no,snapshot_json,reason,actor_id) VALUES (?,?,?,?,?,?,?)',
                [$source,$head['type'],$id,$head['version']+1,json_encode($current,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                    'Verified receiving-office acceptance; wet signatures remain external',(int)$this->user['id']]);
            foreach([$source,$dest] as $oid)q($this->db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
                [$oid,(int)$this->user['id'],'PROPERTY_TRANSFER_RECEIVED','TRANSFER',$id,
                    json_encode(['number'=>$head['number'],'source_org'=>$source,'receiving_org'=>$dest,'asset_count'=>count($accepted),
                        'physical_received'=>true,'paper_reference'=>$reference,'appointment_version'=>$receiver['appointment_version']],JSON_THROW_ON_ERROR)]);
            if(!$outer)$this->db->commit();
            return ['id'=>$id,'number'=>$head['number'],'status'=>'RECEIVED','count'=>count($accepted)];
        }catch(Throwable $e){if(!$outer&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    /** Assessments require the office-role login itself, never the technical administrator. */
    private function assessmentReceiver(int $org):array {
        $office=$this->organization($org);
        self::must(($this->user['role']??'')===$office['kind'],
            'Sign in using the designated receiving-office Property Custodian account',403);
        return $this->receiver($org,true);
    }
    /** Only the login bound to the current active receiving-custodian appointment can assess. */
    public function mayAssessReceivedCondition(int $org):bool {
        try {$this->assessmentReceiver($org);return true;}
        catch(DomainError $e){return false;}
    }
    /** Validate current office ownership and original signed transfer/receipt provenance. */
    private function assessableReceivedAsset(int $org,int $assetId,bool $lock):array {
        self::must($assetId>0,'Select a received property unit',422);
        $sql="SELECT a.id,a.organization_id,a.property_tag,a.status,a.custodian,a.condition_note,a.location,
                     a.property_profile,i.name AS item_name,i.category
              FROM assets a JOIN item_catalog i ON i.id=a.item_id
              WHERE a.id=? AND a.organization_id=?".($lock?' FOR UPDATE':'');
        $asset=one($this->db,$sql,[$assetId,$org]);
        self::must($asset!==null,'This property does not belong to the receiving office',404);
        self::must((string)$asset['status']==='ACTIVE','Only active received property may be reassessed',409);
        $meta=PropertyBatchMetadata::decode($asset['property_profile']??null);
        $origin=$meta['transfer_origin']??null;
        self::must(is_array($origin) && (int)($origin['transfer_document_id']??0)>0
            && (int)($origin['source_organization_id']??0)>0
            && (int)($origin['source_asset_id']??0)>0,
            'Property has no verified inter-office transfer receipt',409);
        $head=$this->transfer((int)$origin['transfer_document_id']);
        self::must($head['source']===(int)$origin['source_organization_id']
            && ($head['snapshot']['status']??null)==='RECEIVED'
            && (int)($head['snapshot']['destination_organization_id']??0)===$org,
            'Original transfer evidence does not match this office',409);
        $matched=false;
        foreach(($head['snapshot']['received_items']??[]) as $item){
            if((int)($item['source_asset_id']??0)===(int)$origin['source_asset_id']
                && (int)($item['receiving_asset_id']??0)===$assetId){$matched=true;break;}
        }
        self::must($matched,'Received asset is missing from the transfer receipt',409);
        self::must(trim((string)($asset['custodian']??''))==='',
            'Property is assigned to an employee; complete the official return first',409);
        self::must(PropertyReturnWorkflow::openAccountability($this->db,$org,$assetId)===null,
            'An open ICS/PAR must be returned before reassessment',409);
        $life=one($this->db,"SELECT snapshot_json FROM document_versions WHERE organization_id=?
            AND doc_type='ASSET_LIFECYCLE' AND entity_id=? ORDER BY version_no DESC LIMIT 1",[$org,$assetId]);
        if($life!==null){
            $decision=json_decode((string)$life['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
            self::must(is_array($decision) && ($decision['state']??null)==='SERVICEABLE',
                'An unserviceable or disposal decision must be resolved through the formal lifecycle workflow',409);
        }
        self::must(!preg_match('/\b(unserviceable|for disposal|disposed)\b/i',(string)($asset['condition_note']??'')),
            'Previously unserviceable or disposal-marked property requires formal lifecycle review',409);
        $asset['received_on']=(string)($origin['received_on']??'');
        $asset['transfer_ref']=(string)$head['type'].' '.(string)$head['number'];
        return $asset;
    }
    /** GET preview is scoped to the appointed custodian; it cannot change property. */
    public function receivedConditionPreview(int $org,int $assetId):array {
        $this->assessmentReceiver($org);
        $asset=$this->assessableReceivedAsset($org,$assetId,false);
        unset($asset['property_profile']);
        return $asset;
    }
    /** Append an audited post-receipt physical condition change without altering custody. */
    public function assessReceivedCondition(array $p):array {
        $org=(int)($p['organization_id']??0);
        $receiver=$this->assessmentReceiver($org);
        $assetId=(int)($p['asset_id']??0);
        $condition=trim((string)($p['condition']??''));
        self::must(in_array($condition,
            ['Serviceable','Needs Maintenance','For Inspection','For Repair','Under Repair'],true),
            'Choose an observed condition; unserviceable/disposal must use the formal lifecycle workflow',422);
        $reason=trim((string)($p['remarks']??''));
        $reference=trim((string)($p['inspection_reference']??''));
        self::must(mbSafeLen($reason)>=12 && mbSafeLen($reason)<=500,
            'Record 12–500 characters describing the physical findings and correction',422);
        self::must(mbSafeLen($reference)>=8 && mbSafeLen($reference)<=160,
            'Record an 8–160 character physical inspection or repair evidence reference',422);
        self::must(($p['physically_inspected']??false)===true,
            'Confirm the property was physically inspected before recording its new condition',422);
        $on=(string)($p['inspected_on']??'');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$on,new DateTimeZone('Asia/Manila'));
        self::must($date!==false && $date->format('Y-m-d')===$on && $on<=self::today(),
            'Use a valid inspection date that is not in the future',422);
        $expected=$p['expected_condition']??null;
        self::must(is_string($expected) && mbSafeLen($expected)<=255,
            'Reload the receiving property before its assessment',422);
        self::must(!$this->db->inTransaction(),'Nested condition assessment is not supported',409);
        $this->db->beginTransaction();
        try {
            // Same lock order as receiving transfer acceptance and custodian binding.
            $office=one($this->db,'SELECT id,active FROM organizations WHERE id=? FOR UPDATE',[$org]);
            self::must($office!==null && (int)$office['active']===1,'Receiving office is inactive',403);
            $fresh=$this->assessmentReceiver($org);
            self::must($fresh['actor_user_id']===$receiver['actor_user_id']
                && $fresh['appointment_version']===$receiver['appointment_version']
                && $fresh['binding_version']===$receiver['binding_version'],
                'Property Custodian appointment or login changed; reload before assessment',409);
            $asset=$this->assessableReceivedAsset($org,$assetId,true);
            $old=(string)($asset['condition_note']??'');
            self::must(hash_equals($old,$expected),'Property condition changed; reload before assessing',409);
            self::must($condition!==$old,'Select a different condition to record a correction',422);
            self::must($asset['received_on']!=='' && $on>=$asset['received_on'],
                'The assessment cannot predate this office’s recorded physical receipt',422);
            $n=q($this->db,"UPDATE assets SET condition_note=?
                WHERE id=? AND organization_id=? AND status='ACTIVE'",
                [$condition,$assetId,$org])->rowCount();
            self::must($n===1,'Property changed before the condition assessment was saved',409);
            q($this->db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details)
                VALUES (?,?,?,?,?,?)',[$org,(int)$this->user['id'],
                'RECEIVED_PROPERTY_CONDITION_ASSESSED','ASSET',$assetId,
                json_encode(['reason'=>$reason,'inspection_reference'=>$reference,
                    'inspected_on'=>$on,'physically_inspected'=>true,
                    'transfer_number'=>$asset['transfer_ref'],
                    'receiver_appointment_version_id'=>$receiver['appointment_version'],
                    'receiver_binding_version_id'=>$receiver['binding_version'],
                    'before'=>['condition_note'=>$old],
                    'after'=>['condition_note'=>$condition]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            $this->db->commit();
            return ['asset_id'=>$assetId,'property_tag'=>$asset['property_tag'],'condition'=>$condition];
        } catch(Throwable $e) {
            if($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }

    /** Read-only chain, traversed from verified transfer-origin edges (not a tag-name search). */
    public function history(int $org,int $assetId):array {
        $this->organization($org);self::must($assetId>0,'Select a receiving asset',422);
        $current=one($this->db,'SELECT id,organization_id,property_tag,item_id,iar_line_id,acquisition_cost,acquired_on,property_profile FROM assets WHERE id=? AND organization_id=?',[$assetId,$org]);
        self::must($current!==null,'Property is not registered in this receiving office',404);
        $chain=[];$events=[];$seen=[];
        for($hop=0;$hop<20&&$current!==null;$hop++){
            $key=$current['organization_id'].'/'.$current['id'];self::must(!isset($seen[$key]),'Property provenance contains a cycle',409);$seen[$key]=true;
            $aid=(int)$current['id'];$owner=(int)$current['organization_id'];
            $meta=PropertyBatchMetadata::decode($current['property_profile']??null);
            $origin=$meta['transfer_origin']??null;
            $orgName=one($this->db,'SELECT name FROM organizations WHERE id=?',[$owner]);
            $chain[]=['organization_id'=>$owner,'organization_name'=>(string)($orgName['name']??('Organization #'.$owner)),'asset_id'=>$aid,'property_number'=>$current['property_tag'],
                'acquisition_cost'=>$current['acquisition_cost'],'acquired_on'=>$current['acquired_on'],
                'source'=>$origin?'RECEIVED_TRANSFER':($current['iar_line_id']!==null?'FINALIZED_IAR':'HISTORICAL_OPENING')];
            $movement=q($this->db,'SELECT m.event_type,m.from_custodian,m.to_custodian,m.reason,m.created_at FROM asset_movements m WHERE m.asset_id=? ORDER BY m.id DESC LIMIT 400',[$aid])->fetchAll(PDO::FETCH_ASSOC);
            self::must(count($movement)<400,'Movement history requires pagination; cannot claim to show complete provenance',409);
            foreach($movement as $row)$events[]=['organization_id'=>$owner,'event'=>'MOVEMENT · '.$row['event_type'],'on'=>$row['created_at'],
                'details'=>trim((string)$row['reason']),'from'=>$row['from_custodian'],'to'=>$row['to_custodian']];
            $docs=q($this->db,'SELECT doc_type,display_number,custodian,status,created_at FROM accountability_documents WHERE organization_id=? AND asset_id=? ORDER BY id DESC LIMIT 250',[$owner,$aid])->fetchAll(PDO::FETCH_ASSOC);
            self::must(count($docs)<250,'ICS/PAR history requires pagination; cannot claim to show complete provenance',409);
            foreach($docs as $row)$events[]=['organization_id'=>$owner,'event'=>$row['doc_type'].' '.$row['status'],'on'=>$row['created_at'],
                'details'=>$row['display_number'],'custodian'=>$row['custodian']];
            $audit=q($this->db,"SELECT event_type,created_at,details FROM audit_events WHERE organization_id=? AND entity_type='ASSET' AND entity_id=? ORDER BY id DESC LIMIT 400",[$owner,$aid])->fetchAll(PDO::FETCH_ASSOC);
            self::must(count($audit)<400,'Audit history requires pagination; cannot claim to show complete provenance',409);
            foreach($audit as $row){
                $changes=json_decode((string)($row['details']??''),true);
                // Show a readable change summary while excluding arbitrary sensitive JSON and credentials.
                $summary=is_array($changes)?array_intersect_key($changes,array_flip(['reason','property_tag','condition','location','state','to_state','from_state','document_number'])):[];
                if(is_array($changes))foreach(['before','after'] as $position){
                    $o=$changes[$position]??null;if(!is_array($o))continue;
                    foreach(['condition_note','location','serial_number','status','custodian'] as $key)
                        if(array_key_exists($key,$o))$summary[$position.'_'.$key]=$o[$key];
                }
                $events[]=['organization_id'=>$owner,'event'=>'AUDIT · '.$row['event_type'],'on'=>$row['created_at'],
                    'details'=>$summary?json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):''];
            }
            $links=q($this->db,"SELECT source_type,target_type,target_id FROM document_links WHERE organization_id=? AND source_type='ASSET' AND source_id=? AND target_type IN ('RRSP','PRR','ITR','PTR') ORDER BY id DESC LIMIT 100",[$owner,$aid])->fetchAll(PDO::FETCH_ASSOC);
            self::must(count($links)<100,'Transfer/return history requires pagination; cannot claim to show complete provenance',409);
            foreach($links as $link){
                $type=(string)$link['target_type'];$doc=one($this->db,"SELECT r.display_number,r.created_at,v.snapshot_json FROM document_registry r JOIN document_versions v ON v.organization_id=r.organization_id AND v.doc_type=r.doc_type AND v.entity_id=r.entity_id AND v.version_no=(SELECT MAX(z.version_no) FROM document_versions z WHERE z.organization_id=r.organization_id AND z.doc_type=r.doc_type AND z.entity_id=r.entity_id) WHERE r.id=? AND r.organization_id=? AND r.doc_type=?",[(int)$link['target_id'],$owner,$type]);
                if(!$doc)continue;
                $snap=json_decode((string)$doc['snapshot_json'],true);
                $events[]=['organization_id'=>$owner,'event'=>$type.' DOCUMENT','on'=>$doc['created_at'],
                    'details'=>(string)$doc['display_number'].' · '.(is_array($snap)?(string)($snap['status']??''):'')];
            }
            $lifecycle=q($this->db,"SELECT version_no,snapshot_json,created_at FROM document_versions WHERE organization_id=? AND doc_type='ASSET_LIFECYCLE' AND entity_id=? ORDER BY version_no ASC LIMIT 251",[$owner,$aid])->fetchAll(PDO::FETCH_ASSOC);
            self::must(count($lifecycle)<=250,'Lifecycle history requires pagination; cannot claim to show complete provenance',409);
            foreach($lifecycle as $life){
                $snap=json_decode((string)$life['snapshot_json'],true);
                $events[]=['organization_id'=>$owner,'event'=>'LIFECYCLE CHANGE · version '.$life['version_no'],
                    'on'=>$life['created_at'],'details'=>is_array($snap)?(string)($snap['state']??'Unknown'):'Unknown'];
            }
            if($current['iar_line_id']!==null){
                $iar=one($this->db,'SELECT ir.display_number,ir.accepted_at,ir.status,po.display_number AS po_number FROM iar_lines l JOIN iars ir ON ir.id=l.iar_id AND ir.organization_id=? LEFT JOIN purchase_orders po ON po.id=ir.po_id AND po.organization_id=ir.organization_id WHERE l.id=?',[$owner,$current['iar_line_id']]);
                if($iar){
                    $events[]=['organization_id'=>$owner,'event'=>'ORIGINAL IAR','on'=>$iar['accepted_at'],
                        'details'=>$iar['display_number'].' · '.$iar['status']];
                    if(!empty($iar['po_number']))$events[]=['organization_id'=>$owner,'event'=>'SOURCE PURCHASE ORDER','on'=>$iar['accepted_at'],
                        'details'=>(string)$iar['po_number']];
                }
            }
            if(!$origin){
                $original=$meta['shared']??[];
                foreach(['historical_po'=>'Historical PO reference','historical_iar'=>'Historical IAR reference','historical_dv'=>'Historical DV reference','evidence_note'=>'Original source evidence'] as $name=>$label){
                    $val=trim((string)($original[$name]??''));
                    if($val!=='')$events[]=['organization_id'=>$owner,'event'=>$label,'on'=>(string)($current['acquired_on']??''),'details'=>$val];
                }
                break;
            }
            self::must(is_array($origin)&&(int)($origin['transfer_document_id']??0)>0 &&
                (int)($origin['source_asset_id']??0)>0 && (int)($origin['source_organization_id']??0)>0,
                'Saved property provenance is incomplete',409);
            $tid=(int)$origin['transfer_document_id'];$sid=(int)$origin['source_asset_id'];$so=(int)$origin['source_organization_id'];
            $head=$this->transfer($tid);self::must($head['source']===$so && ($head['snapshot']['status']??'')==='RECEIVED'
                && (int)($head['snapshot']['destination_organization_id']??0)===$owner,
                'Transfer provenance could not be verified',409);
            $match=false;foreach(($head['snapshot']['received_items']??[]) as $entry)if((int)($entry['source_asset_id']??0)===$sid
                &&(int)($entry['receiving_asset_id']??0)===$aid){$match=true;break;}
            self::must($match,'Receiving asset has no verified transfer evidence',409);
            $events[]=['organization_id'=>$so,'event'=>'ITR/PTR ACCEPTED','on'=>$head['snapshot']['received_at']??$head['snapshot']['prepared_at'],
                'details'=>$head['type'].' '.$head['number'].' · '.$so.' → '.$owner
                    .' · Receipt '.(string)($head['snapshot']['received_on']??'')
                    .' · Supporting reference '.(string)($head['snapshot']['receipt_reference']??'')];
            $current=one($this->db,'SELECT id,organization_id,property_tag,item_id,iar_line_id,acquisition_cost,acquired_on,property_profile FROM assets WHERE id=? AND organization_id=?',[$sid,$so]);
            self::must($current!==null,'Source historical property was not retained',409);
        }
        self::must(count($chain)<20,'Property provenance is unusually long; contact an administrator for review',409);
        usort($events,static fn($a,$b)=>strcmp((string)$a['on'],(string)$b['on']));
        return ['asset_id'=>$assetId,'property_number'=>$chain[0]['property_number'],
            'ownership_chain'=>array_reverse($chain),'timeline'=>$events];
    }
    /** Bounded receiving office register, including school and Division Office. */
    public function receivedRegister(int $org):array {
        $this->organization($org);
        $rows=q($this->db,"SELECT a.id,a.property_tag,a.serial_number,a.acquisition_cost,a.status,a.location,a.condition_note,i.name AS item_name,i.category,a.property_profile
            FROM assets a JOIN item_catalog i ON i.id=a.item_id WHERE a.organization_id=?
              AND COALESCE(JSON_CONTAINS_PATH(a.property_profile,'one','$.transfer_origin'),0)=1
              ORDER BY a.id DESC LIMIT 251",[$org])->fetchAll(PDO::FETCH_ASSOC);
        self::must(count($rows)<=250,'Receiving register requires pagination before further growth',409);
        $out=[];foreach($rows as $r){$meta=PropertyBatchMetadata::decode($r['property_profile']??null);
            if(!isset($meta['transfer_origin']))continue;
            unset($r['property_profile']);$r['provenance']=$meta['transfer_origin'];$out[]=$r;}
        return $out;
    }
    public function availableMappingItems(int $org):array {
        $this->organization($org);
        return q($this->db,"SELECT id,name,category FROM item_catalog WHERE active=1 AND category IN ('PPE','SEMI_EXPENDABLE')
            AND (owner_organization_id=? OR owner_organization_id IS NULL) ORDER BY category,name,id LIMIT 301",[$org])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function bindingOptions(int $org):array {
        $o=$this->organization($org);$appointed=$this->appointment($org);$existing=$this->binding($org);
        $users=q($this->db,'SELECT u.id,u.full_name,u.role FROM users u JOIN user_organization_scopes s ON s.user_id=u.id WHERE s.organization_id=? AND u.role=? AND u.active=1 ORDER BY u.full_name LIMIT 101',
            [$org,$o['kind']])->fetchAll(PDO::FETCH_ASSOC);
        return ['appointment'=>$appointed,'binding'=>$existing,'users'=>$users];
    }
}