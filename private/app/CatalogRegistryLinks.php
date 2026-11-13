<?php
declare(strict_types=1);
/**
 * Additive item-type association service. Designed to sit alongside the
 * installed Domain.php rather than replacing it. Registry types are NOT
 * individual accountable property numbers and never post accounting.
 * The API's existing idempotency transaction wraps addItem + linkNewItem.
 */
final class CatalogRegistryLinks {
    private const KEY='CATALOG_PROPERTY_LINKS';
    public function __construct(private PDO $db,private array $user,private Domain $domain) {}
    private function workspace(array $p,bool $write=false): array {
        $org=idOf($p,'organization_id');
        scope($this->db,$this->user,$org);
        $row=one($this->db,'SELECT id,kind,parent_division_id,active FROM organizations WHERE id=?',[$org]);
        demand($row!==null && (int)($row['active']??1)===1,'Workspace unavailable',403);
        $kind=(string)$row['kind'];
        demand(in_array($kind,['SCHOOL','DIVISION'],true),'Invalid organization type',409);
        $division=$kind==='DIVISION'?$org:(int)($row['parent_division_id']??0);
        demand($division>0,'School is not linked to a Division',409);
        if($write){
            allowedToWrite($this->user);
            $role=(string)$this->user['role'];
            demand($role==='SUPER_ADMIN'||($role==='SCHOOL'&&$kind==='SCHOOL')||
                ($role==='DIVISION'&&$kind==='DIVISION'),
                'Choose the owning school or Division workspace',403);
        }
        return [$org,$division,$kind];
    }
    /** All registry reads go through the installed Division master policy. */
    private function registry(int $org): array {
        $record=$this->domain->listDivisionPropertyRegistry(['organization_id'=>$org]);
        return $record['records']??[];
    }
    private function currentConfig(int $owner,bool $locking=false): array {
        $sql='SELECT id,config_json FROM configuration_versions WHERE organization_id=? AND config_key=? ORDER BY id DESC LIMIT 1';
        // A locking/current read is required during writes even under MariaDB
        // REPEATABLE READ; otherwise an earlier snapshot can drop other links.
        $v=one($this->db,$sql.($locking?' FOR UPDATE':''),[$owner,self::KEY]);
        if($v===null)return ['version_id'=>0,'links'=>[]];
        $j=json_decode((string)$v['config_json'],true,512,JSON_THROW_ON_ERROR);
        demand(is_array($j)&&isset($j['links'])&&is_array($j['links']),'Invalid registry link configuration',500);
        return ['version_id'=>(int)$v['id'],'links'=>$j['links']];
    }
    public function search(array $p): array {
        [$org]=$this->workspace($p);
        $term=trim((string)($p['term']??''));
        demand(mbSafeLen($term)<=90,'Registry search is too long');
        if(mbSafeLen($term)<2)return [];
        $out=[];
        foreach($this->registry($org) as $r){
            foreach(['description','section','hv_code','lv_code','reference_code'] as $field){
                if(stripos((string)($r[$field]??''),$term)===false)continue;
                $out[]=['registry_id'=>$r['id'],'description'=>$r['description'],
                    'section'=>$r['section'],'mode'=>$r['mode'],'hv_code'=>$r['hv_code'],
                    'lv_code'=>$r['lv_code'],'reference_code'=>$r['reference_code']];
                break;
            }
            if(count($out)>=15)break;
        }
        return $out;
    }
    public function links(array $p): array {
        [$org,$division]=$this->workspace($p);
        $divisionConfig=$this->currentConfig($division);
        $schoolConfig=$org===$division?['version_id'=>0,'links'=>[]]:$this->currentConfig($org);
        $items=q($this->db,'SELECT id,category,owner_organization_id FROM item_catalog WHERE owner_organization_id IS NULL OR owner_organization_id=? OR owner_organization_id=? ORDER BY id LIMIT 4000',[$org,$division])->fetchAll();
        $registry=[];foreach($this->registry($org) as $r)$registry[(string)$r['id']]=$r;
        $linked=[];
        foreach($items as $item){
            $owner=$item['owner_organization_id']===null?$division:(int)$item['owner_organization_id'];
            $snapshot=($owner===$org && $org!==$division)?$schoolConfig:$divisionConfig;
            $data=$snapshot['links'][(string)$item['id']]??null;
            if(!is_array($data))continue;
            $r=$registry[(string)($data['registry_id']??'')]??null;
            $snapshot=is_array($data['registry_snapshot']??null)?$data['registry_snapshot']:[];
            if($r===null){
                // Preserve the last approved reference rather than misleadingly
                // showing 'Not linked' when a Division retires a registry type.
                $linked[(string)$item['id']]=['registry_id'=>(string)($data['registry_id']??''),
                    'description'=>(string)($snapshot['description']??'Retired Division property type'),
                    'section'=>(string)($snapshot['section']??''),
                    'mode'=>(string)($snapshot['mode']??''),
                    'hv_code'=>(string)($snapshot['hv_code']??''),
                    'lv_code'=>(string)($snapshot['lv_code']??''),
                    'reference_code'=>(string)($snapshot['reference_code']??''),
                    'owner_organization_id'=>$owner,'division_id'=>$division,'review_required'=>true,
                    'registry_missing'=>true];
                continue;
            }
            // A change in the underlying Division type, including its useful
            // life or section, must be reviewed instead of adopted silently.
            $currentType=array_intersect_key($r,array_flip(['description','section','mode','hv_code','lv_code','reference_code','effective_useful_life_months']));
            $expectedMode=(string)$item['category']==='TEXTBOOK'?'REFERENCE':'HV_LV';
            $reviewRequired=($r['mode']??null)!==$expectedMode ||
                ($data['classification']??null)!==(string)$item['category'] ||
                ($data['registry_snapshot']??null)!==$currentType;
            $linked[(string)$item['id']]=['registry_id'=>$r['id'],'description'=>$r['description'],
                'section'=>$r['section'],'mode'=>$r['mode'],'hv_code'=>$r['hv_code'],
                'lv_code'=>$r['lv_code'],'reference_code'=>$r['reference_code'],
                'owner_organization_id'=>$owner,'division_id'=>$division,'review_required'=>$reviewRequired];
        }
        return ['links'=>$linked,'organization_id'=>$org,'division_id'=>$division,
            'division_version_id'=>$divisionConfig['version_id'],
            'local_version_id'=>$schoolConfig['version_id']];
    }
    /**
     * Division lock FIRST, then owner school and item: consistent with the
     * existing addItem()'s Division-level lock. This prevents lost concurrent
     * configuration updates between quick-add and manual registry links.
     */
    private function lockOwners(int $org,int $division): void {
        demand(one($this->db,"SELECT id FROM organizations WHERE id=? AND kind='DIVISION' AND active=1 FOR UPDATE",[$division])!==null,
            'Division unavailable',409);
        if($org!==$division)
            demand(one($this->db,"SELECT id FROM organizations WHERE id=? AND kind='SCHOOL' AND parent_division_id=? AND active=1 FOR UPDATE",[$org,$division])!==null,
                'School unavailable',409);
    }
    private function writeLink(int $org,int $division,int $owner,int $itemId,string $category,string $registryId): array {
        demand(in_array($category,['PPE','SEMI_EXPENDABLE','TEXTBOOK'],true),
            'Only property and textbook catalog items may reference the registry',409);
        $registryRecord=$this->domain->listDivisionPropertyRegistry(['organization_id'=>$org]);
        $matched=null;
        foreach(($registryRecord['records']??[]) as $r)if((string)$r['id']===$registryId){$matched=$r;break;}
        demand($matched!==null,'Unknown Division property registry entry',404);
        demand($category==='TEXTBOOK'?($matched['mode']==='REFERENCE'):($matched['mode']==='HV_LV'),
            'Registry entry does not match item classification',409);
        $cfg=$this->currentConfig($owner,true);$links=$cfg['links'];
        $prior=$links[(string)$itemId]??null;
        $registrySnapshot=array_intersect_key($matched,array_flip(['description','section','mode','hv_code','lv_code','reference_code','effective_useful_life_months']));
        if(is_array($prior)&&($prior['registry_id']??'')===$registryId &&
            ($prior['registry_snapshot']??null)===$registrySnapshot &&
            ($prior['classification']??null)===$category)
            return ['changed'=>false,'registry_id'=>$registryId,'version_id'=>$cfg['version_id']];
        demand(count($links)<250||$prior!==null,'Catalog registry link capacity reached; contact administrator',409);
        $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $links[(string)$itemId]=['registry_id'=>$registryId,'division_id'=>$division,
            'classification'=>$category,'linked_at'=>$today,
            'registry_version_id'=>(int)($registryRecord['version_id']??0),
            'registry_snapshot'=>$registrySnapshot];
        $json=json_encode(['links'=>$links],JSON_THROW_ON_ERROR);
        demand(strlen($json)<55000,'Registry link configuration capacity exceeded',409);
        q($this->db,'INSERT INTO configuration_versions (organization_id,config_key,config_json,effective_from,review_note) VALUES (?,?,?,?,?)',
            [$owner,self::KEY,$json,$today,'Catalog item type linked to Division registry; no posting']);
        $version=(int)$this->db->lastInsertId();
        q($this->db,'INSERT INTO audit_events (organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
            [$org,(int)$this->user['id'],'CATALOG_PROPERTY_REGISTRY_LINKED','ITEM',$itemId,
                json_encode(['owner_id'=>$owner,'division_id'=>$division,'before'=>$prior,
                    'registry_id'=>$registryId,'description'=>$matched['description'],
                    'configuration_version'=>$version],JSON_THROW_ON_ERROR)]);
        return ['changed'=>true,'registry_id'=>$registryId,'version_id'=>$version];
    }
    public function saveExisting(array $p): array {
        [$org,$division]=$this->workspace($p,true);
        $itemId=idOf($p,'item_id');$registryId=requiredString($p,'registry_id',30);
        // A manual link edit must be based on the version the user saw.
        // This prevents an old form replacing another operator's later decision.
        demand(array_key_exists('expected_version_id',$p) &&
            is_int($p['expected_version_id']) && $p['expected_version_id']>=0,
            'Refresh the catalog links before choosing a property type',409);
        $expectedVersion=$p['expected_version_id'];
        $outer=$this->db->inTransaction();if(!$outer)$this->db->beginTransaction();
        try{
            $this->lockOwners($org,$division);
            $item=one($this->db,'SELECT id,category,owner_organization_id FROM item_catalog WHERE id=? FOR UPDATE',[$itemId]);
            demand($item!==null,'Catalog item unavailable',404);
            $owner=$item['owner_organization_id']===null?$division:(int)$item['owner_organization_id'];
            demand($owner===$org,'Only the owning workspace may link this item',403);
            if($item['owner_organization_id']===null)
                demand(in_array($this->user['role'],['SUPER_ADMIN','DIVISION'],true),
                    'Legacy shared item maintenance requires Division staff',403);
            $current=$this->currentConfig($owner,true);
            demand($current['version_id']===$expectedVersion,
                'This catalog link changed in another session. Refresh and try again',409);
            $result=['item_id'=>$itemId]+$this->writeLink($org,$division,$owner,$itemId,(string)$item['category'],$registryId);
            if(!$outer)$this->db->commit();return $result;
        }catch(Throwable $e){if(!$outer&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    /** Called immediately after Domain::addItem inside the same API transaction. */
    public function linkNewItem(array $p,int $itemId): array {
        demand($this->db->inTransaction(),'Registry link must share item creation transaction',500);
        [$org,$division]=$this->workspace($p,true);
        // Domain::addItem already acquired the Division row lock. Lock the
        // school before reading/writing its configuration to serialize links.
        if($org!==$division)
            demand(one($this->db,"SELECT id FROM organizations WHERE id=? AND kind='SCHOOL' AND parent_division_id=? AND active=1 FOR UPDATE",[$org,$division])!==null,
                'School unavailable',409);
        $item=one($this->db,'SELECT id,category,owner_organization_id FROM item_catalog WHERE id=? FOR UPDATE',[$itemId]);
        demand($item!==null&&(int)($item['owner_organization_id']??0)===$org,
            'New catalog item ownership mismatch',409);
        $registryId=requiredString($p,'registry_id',30);
        return ['item_id'=>$itemId]+$this->writeLink($org,$division,$org,$itemId,(string)$item['category'],$registryId);
    }
}
