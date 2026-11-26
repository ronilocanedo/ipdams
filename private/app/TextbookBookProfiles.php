<?php
declare(strict_types=1);
require_once __DIR__.'/TextbookIsbn.php';

/**
 * v0.2.273: Shared, auditable bibliographic profiles and drafts.
 * Uses existing document_registry/document_versions/audit_events (TBP), so
 * no DDL is needed. ISBN-linked published books retain the existing title_id.
 * Never writes stock, batches, movements, documents of type TBR, or ownership
 * supplied by the client. All writes require the API idempotent transaction.
 */
final class TextbookBookProfiles {
    private const TYPE='TBP';
    private const MAX_PROFILES=3000;
    private const TYPES=['TEXTBOOK','TEACHERS_MANUAL','LEARNERS_MATERIAL','INSTRUCTIONAL_MATERIAL','REFERENCE','OTHER'];
    private const FIELDS=[
        'title'=>255,'material_type'=>40,'subtitle'=>255,'alternate_title'=>255,
        'isbn'=>80,'official_lr_code'=>120,'grade'=>30,'subject'=>100,
        'authors'=>800,'contributors'=>500,'publisher'=>180,'imprint'=>160,
        'edition'=>80,'series'=>160,'volume'=>80,'printing'=>120,
        'publication_place'=>180,'publication_year'=>4,'copyright_year'=>4,
        'printing_year'=>4,'language'=>100,'pages'=>20,'book_format'=>80,
        'dimensions'=>100,'quarter'=>60,'strand'=>120,'program'=>120,
        'curriculum'=>160,'intended_audience'=>160,'editor'=>255,
        'translator'=>255,'illustrator'=>255,'catalog_source_reference'=>160,
        'source_url'=>500,'verification_note'=>1000,'remarks'=>2000
    ];
    private array $schoolParentCache=[];
    public function __construct(private PDO $db,private array $user) {}

    /** Identity and text validation. Return only recognized client fields. */
    public static function normalize(array $body):array {
        $d=[];
        foreach(self::FIELDS as $key=>$max){
            $value=$body[$key]??'';
            demand(is_string($value)||is_int($value)||$value===null,
                'Invalid '.$key.' value',422);
            $str=trim((string)$value);
            demand(mbSafeLen($str)<=$max,'The '.$key.' field exceeds '.$max.' characters',422);
            demand(!preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$str),
                'Control characters are not allowed in '.$key,422);
            $d[$key]=$str;
        }
        demand($d['title']!=='' && mbSafeLen($d['title'])>=2,'Enter a book title',422);
        demand(in_array($d['material_type'],self::TYPES,true),'Choose the kind of learning resource',422);
        foreach(['publication_year','copyright_year','printing_year'] as $field){
            if($d[$field]==='')continue;
            demand(preg_match('/^(1[0-9]{3}|20[0-9]{2})$/D',$d[$field])===1,
                'Enter a four-digit '.$field.' (1000–2099)',422);
        }
        if($d['pages']!=='')demand(preg_match('/^[1-9][0-9]{0,5}$/D',$d['pages'])===1,'Enter a positive number of pages',422);
        $isbn=null;
        if($d['isbn']!==''){
            try{$isbn=TextbookIsbn::canonical($d['isbn']);}
            catch(InvalidArgumentException $e){demand(false,$e->getMessage(),422);}
        }
        $d['isbn13']=$isbn;
        $d['official_lr_code_normalized']=$d['official_lr_code']!==''
            ?strtoupper(preg_replace('/\s+/u',' ',trim($d['official_lr_code']))):null;
        // The official identifier must not be invented from title or internal ID.
        $d['identity_key']=$isbn!==null?'ISBN:'.$isbn:
            ($d['official_lr_code_normalized']!==null?'LR:'.$d['official_lr_code_normalized']:null);
        return $d;
    }
    private function office(int $org):array {
        demand($org>0,'Choose a school or Division workspace',422);
        scope($this->db,$this->user,$org);
        $o=one($this->db,"SELECT id,kind,parent_division_id FROM organizations WHERE id=? AND active=1 AND kind IN ('SCHOOL','DIVISION')",[$org]);
        demand($o!==null && in_array($this->user['role']??'',[$o['kind'],'SUPER_ADMIN'],true),
            'Use your receiving school or Division sign-in to manage books',403);
        if($o['kind']==='SCHOOL')demand((int)$o['parent_division_id']>0,
            'School must be linked to its supervising Division before registering books',409);
        return $o;
    }
    /** A Division edits its own entries and those from schools in its hierarchy. */
    private function canEdit(array $s,array $workspace):bool {
        // Super Admin operates in the selected office; never replace the audit actor.
        $role=($this->user['role']??'')==='SUPER_ADMIN'?$workspace['kind']:($this->user['role']??'');
        if($role==='SCHOOL')return $workspace['kind']==='SCHOOL'
            && (int)$workspace['id']===(int)$s['registered_by_organization_id'];
        if($role==='DIVISION'){
            if($workspace['kind']!=='DIVISION')return false;
            $owner=(int)$s['registered_by_organization_id'];
            if(($s['origin_kind']??'')==='DIVISION')return $owner===(int)$workspace['id'];
            if(($s['origin_kind']??'')!=='SCHOOL')return false;
            if(!array_key_exists($owner,$this->schoolParentCache)){
                $r=one($this->db,"SELECT parent_division_id FROM organizations WHERE id=? AND kind='SCHOOL' AND active=1",[$owner]);
                $this->schoolParentCache[$owner]=$r===null?0:(int)($r['parent_division_id']??0);
            }
            // Both recorded provenance and current active school hierarchy must
            // agree. A moved/reparented school is held for admin review.
            return $this->schoolParentCache[$owner]===(int)$workspace['id']
                && (int)$s['owning_division_id']===(int)$workspace['id'];
        }
        return false;
    }
    private function canView(array $s,array $workspace):bool {
        return ($s['status']??null)==='VERIFIED'||$this->canEdit($s,$workspace);
    }
    private function lockGlobal():void {
        demand($this->db->inTransaction(),'Book Directory writes need a protected transaction',500);
        $r=one($this->db,'SELECT id FROM organizations ORDER BY id ASC LIMIT 1 FOR UPDATE');
        demand($r!==null,'Shared book registry mutex is unavailable',409);
    }
    private function profiles():array {
        $rows=q($this->db,"SELECT r.id,r.organization_id,r.created_at,v.version_no,v.snapshot_json
            FROM document_registry r JOIN document_versions v ON v.organization_id=r.organization_id
            AND v.doc_type=r.doc_type AND v.entity_id=r.entity_id
            AND v.version_no=(SELECT MAX(z.version_no) FROM document_versions z
                WHERE z.doc_type=r.doc_type AND z.entity_id=r.entity_id)
            WHERE r.doc_type='TBP' ORDER BY r.id LIMIT 3001")->fetchAll(PDO::FETCH_ASSOC);
        demand(count($rows)<=self::MAX_PROFILES,
            'More than 3,000 book profiles require a catalog pagination upgrade before new edits',409);
        $out=[];
        foreach($rows as $r){
            $s=json_decode((string)$r['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
            demand(is_array($s) && ($s['type']??null)===self::TYPE &&
                (int)($s['registered_by_organization_id']??0)===(int)$r['organization_id'],
                'Book profile integrity requires technical review',409);
            $out[]=['id'=>(int)$r['id'],'version'=>(int)$r['version_no'],'snapshot'=>$s,
                'created_at'=>$r['created_at']];
        }
        return $out;
    }
    private function record(int $id,bool $lock=false):array {
        demand($id>0,'Select a saved book',422);
        $r=one($this->db,"SELECT id,organization_id,created_at FROM document_registry
            WHERE id=? AND entity_id=? AND doc_type='TBP'".($lock?' FOR UPDATE':''),[$id,$id]);
        demand($r!==null,'Book profile not found',404);
        $v=one($this->db,"SELECT version_no,snapshot_json,created_at FROM document_versions
            WHERE organization_id=? AND doc_type='TBP' AND entity_id=? ORDER BY version_no DESC LIMIT 1",
            [(int)$r['organization_id'],$id]);
        demand($v!==null,'Book profile versions are missing',409);
        $s=json_decode((string)$v['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
        demand(is_array($s) && ($s['type']??null)===self::TYPE &&
            (int)($s['registered_by_organization_id']??0)===(int)$r['organization_id'],
            'Book profile origin could not be verified',409);
        return ['id'=>$id,'version'=>(int)$v['version_no'],'snapshot'=>$s,'created_at'=>$r['created_at']];
    }
    private function checkIdentity(array $data,?int $excludeId=null,?int $knownLegacyId=null):void {
        if($data['identity_key']===null)return;
        foreach($this->profiles() as $entry){
            if($entry['id']===$excludeId)continue;
            // Discarded drafts remain in the audit ledger, but do not reserve
            // an ISBN/LR identity or block its later genuine registration.
            if(($entry['snapshot']['status']??null)==='DISCARDED')continue;
            $old=$entry['snapshot']['fields'];
            $sameIsbn=$data['isbn13']!==null && ($old['isbn13']??null)===$data['isbn13'];
            $sameCode=$data['official_lr_code_normalized']!==null &&
                ($old['official_lr_code_normalized']??null)===$data['official_lr_code_normalized'];
            demand(!$sameIsbn && !$sameCode,
                'This ISBN or official LR code already belongs to a book profile. Find that book in the directory',409);
        }
        if($data['isbn13']===null)return;
        $existing=q($this->db,'SELECT id,isbn FROM textbook_titles ORDER BY id LIMIT 3001')->fetchAll(PDO::FETCH_ASSOC);
        demand(count($existing)<=3000,'Full legacy ISBN reconciliation is required before registering another edition',409);
        foreach($existing as $r){
            if($knownLegacyId!==null && (int)$r['id']===$knownLegacyId)continue;
            try{$canonical=TextbookIsbn::canonical(trim((string)($r['isbn']??'')));}
            catch(InvalidArgumentException $e){continue;}
            demand($canonical!==$data['isbn13'],
                'This ISBN already exists in the shared Book Directory. Select the existing edition',409);
        }
    }
    private function version(int $org,int $id,int $number,array $data,string $reason):void {
        q($this->db,'INSERT INTO document_versions(organization_id,doc_type,entity_id,version_no,snapshot_json,reason,actor_id) VALUES (?,?,?,?,?,?,?)',
            [$org,self::TYPE,$id,$number,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$reason,(int)$this->user['id']]);
        q($this->db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
            [$org,(int)$this->user['id'],$number===1?'TEXTBOOK_BOOK_PROFILE_CREATED':'TEXTBOOK_BOOK_PROFILE_REVISED',
                'TEXTBOOK_BOOK_PROFILE',$id,json_encode(['version'=>$number,'reason'=>$reason,
                    'source_office'=>(int)$data['registered_by_organization_id'],
                    'owning_division'=>(int)$data['owning_division_id'],
                    'status'=>$data['status'],'editor_workspace'=>(int)($data['last_editor_organization_id']??$org)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
    }
    private function linkedTitle(array $data):int {
        demand($data['isbn13']!==null,'Only verified ISBN editions can be selected for Central Office stock receipts',422);
        q($this->db,'INSERT INTO textbook_titles(title,isbn,grade,subject,edition,publisher) VALUES (?,?,?,?,?,?)',
            [$data['title'],$data['isbn13'],$data['grade']?:null,$data['subject']?:null,
                $data['edition']?:null,$data['publisher']?:null]);
        $id=(int)$this->db->lastInsertId();
        demand($id>0,'Could not link the verified ISBN to the Book Directory',500);
        return $id;
    }
    public function create(array $body):array {
        $org=(int)($body['organization_id']??0);$o=$this->office($org);
        $d=self::normalize($body);
        $publish=($body['publish']??false)===true;
        if($publish){
            demand($d['identity_key']!==null,'An authentic ISBN or official LR code is needed to publish a book',422);
            demand(($body['confirmed_official_title']??false)===true,
                'Confirm the book identity against the actual book or authentic official record',422);
        }
        $this->lockGlobal();
        $this->checkIdentity($d);
        $division=$o['kind']==='DIVISION'?$org:(int)$o['parent_division_id'];
        $titleId=$publish && $d['isbn13']!==null?$this->linkedTitle($d):null;
        $month=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m');
        // Internal identity, not an official documentary number or artificial ISBN.
        $temporary='B'.strtoupper(bin2hex(random_bytes(5)));
        q($this->db,'INSERT INTO document_registry(organization_id,doc_type,period,display_number,entity_id) VALUES (?,?,?,?,0)',
            [$org,self::TYPE,$month,$temporary]);
        $id=(int)$this->db->lastInsertId();demand($id>0 && $id<10000000000,'Unable to assign a book profile ID',500);
        $num='BK'.str_pad((string)$id,10,'0',STR_PAD_LEFT);
        $saved=q($this->db,'UPDATE document_registry SET entity_id=?,display_number=? WHERE id=? AND organization_id=? AND entity_id=0',
            [$id,$num,$id,$org]);
        demand($saved->rowCount()===1,'Cannot bind book profile identity',500);
        $s=['type'=>self::TYPE,'registered_by_organization_id'=>$org,'registered_by_user_id'=>(int)$this->user['id'],
            'owning_division_id'=>$division,'origin_kind'=>$o['kind'],
            'status'=>$publish?'VERIFIED':'DRAFT','fields'=>$d,'title_id'=>$titleId,
            'confirmed_official_title'=>$publish,'confirmed_by'=>$publish?(int)$this->user['id']:null,
            'revisions'=>1];
        $this->version($org,$id,1,$s,$publish?'Verified registered book; no stock credited':'Book draft saved; no stock credited');
        return ['id'=>$id,'number'=>$num,'version'=>1,'status'=>$s['status'],'isbn13'=>$d['isbn13'],
            'can_receive'=>$s['status']==='VERIFIED' && $titleId!==null];
    }
    public function revise(array $body):array {
        $org=(int)($body['organization_id']??0);$office=$this->office($org);
        $id=(int)($body['book_id']??0);
        $this->lockGlobal();
        $old=$this->record($id,true);$s=$old['snapshot'];
        demand($this->canEdit($s,$office),'Only the registering school or supervising Division may edit this book',403);
        demand(in_array($s['status']??null,['DRAFT','VERIFIED'],true),
            'This book draft was discarded and cannot be edited or published',409);
        demand((int)($body['expected_version']??0)===$old['version'],
            'This book was modified by another user. Refresh its details before saving',409);
        $new=self::normalize($body);
        $publish=($body['publish']??false)===true || $s['status']==='VERIFIED';
        if($publish){
            demand($new['identity_key']!==null,'An official ISBN or LR code is required for a verified book',422);
            if($s['status']!=='VERIFIED')demand(($body['confirmed_official_title']??false)===true,
                'Confirm the ISBN or LR code against the physical book or official record',422);
        }
        if($s['status']==='VERIFIED'){
            // The core title identity is immutable after publication. New edition = new book.
            demand($new['identity_key']===($s['fields']['identity_key']??null),
                'ISBN/LR code cannot change on a verified edition; register a distinct edition or request official correction review',409);
            $reason=trim((string)($body['correction_reason']??''));
            demand(mbSafeLen($reason)>=8 && mbSafeLen($reason)<=500,
                'Enter a correction reason (at least 8 characters)',422);
        }else $reason='Draft book profile updated';
        $this->checkIdentity($new,$id,$s['title_id']!==null?(int)$s['title_id']:null);
        $changes=[];
        foreach(self::FIELDS as $name=>$max)if($new[$name]!==($s['fields'][$name]??''))
            $changes[$name]=['before'=>$s['fields'][$name]??'','after'=>$new[$name]];
        if($publish && $s['status']==='DRAFT'){
            $reason='Book verified from draft';
            if($new['isbn13']!==null)$s['title_id']=$this->linkedTitle($new);
            $s['status']='VERIFIED';
            $s['confirmed_official_title']=true;
            $s['confirmed_by']=(int)$this->user['id'];
        }elseif($s['status']==='VERIFIED' && $s['title_id']!==null){
            // Preserve title_id and original TBR snapshots. Only bibliographic columns change.
            $linked=one($this->db,'SELECT id FROM textbook_titles WHERE id=? AND isbn=? FOR UPDATE',
                [(int)$s['title_id'],$new['isbn13']]);
            demand($linked!==null,'The linked historical textbook title is missing or changed; do not edit this book until reviewed',409);
            q($this->db,'UPDATE textbook_titles SET title=?,grade=?,subject=?,edition=?,publisher=? WHERE id=? AND isbn=?',
                [$new['title'],$new['grade']?:null,$new['subject']?:null,
                    $new['edition']?:null,$new['publisher']?:null,(int)$s['title_id'],$new['isbn13']]);
        }
        if(!$changes && $s['status']===($old['snapshot']['status']??null))
            demand(false,'No book information was changed',422);
        $s['fields']=$new;$s['revisions']=$old['version']+1;
        $s['last_editor_organization_id']=$org;
        $s['last_changed_fields']=$changes;
        $this->version((int)$s['registered_by_organization_id'],$id,$old['version']+1,$s,$reason);
        // Also record editing workspace without changing original ownership.
        q($this->db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
            [$org,(int)$this->user['id'],'TEXTBOOK_BOOK_PROFILE_EDITED_BY_OFFICE','TEXTBOOK_BOOK_PROFILE',$id,
                json_encode(['origin_org'=>(int)$s['registered_by_organization_id'],
                    'version'=>$old['version']+1,'reason'=>$reason,'changes'=>$changes],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
        return ['id'=>$id,'number'=>'BK'.str_pad((string)$id,10,'0',STR_PAD_LEFT),
            'version'=>$old['version']+1,'status'=>$s['status'],'isbn13'=>$new['isbn13'],
            'can_receive'=>$s['status']==='VERIFIED' && $s['title_id']!==null];
    }
    /**
     * Soft-discard an unverified draft. Never delete its documentary versions.
     * No verified book, linked title, or accepted stock can be discarded.
     * An origin school or its supervising Division can discard after an
     * optimistic-version check, using the normal idempotent API transaction.
     */
    public function discardDraft(array $body):array {
        $org=(int)($body['organization_id']??0);$office=$this->office($org);
        $id=(int)($body['book_id']??0);
        $this->lockGlobal();
        $old=$this->record($id,true);$s=$old['snapshot'];
        demand($this->canEdit($s,$office),
            'Only the registering school or supervising Division may discard this draft',403);
        demand(($s['status']??null)==='DRAFT',
            'Only an unverified book draft can be discarded. Verified books are retained',409);
        demand((int)($body['expected_version']??0)===$old['version'],
            'This draft was changed by another user. Refresh before discarding',409);
        demand(($s['title_id']??null)===null && ($s['confirmed_official_title']??false)!==true,
            'This book has a linked verified title and cannot be discarded',409);
        $s['status']='DISCARDED';
        $s['discarded_by']=(int)$this->user['id'];
        $s['discarded_from_organization_id']=$org;
        $s['discarded_at']=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format(DATE_ATOM);
        $s['last_editor_organization_id']=$org;
        $s['last_changed_fields']=[];
        $s['revisions']=$old['version']+1;
        $this->version((int)$s['registered_by_organization_id'],$id,$old['version']+1,
            $s,'Unverified draft discarded; no stock or textbook title changed');
        q($this->db,'INSERT INTO audit_events(organization_id,actor_id,event_type,entity_type,entity_id,details) VALUES (?,?,?,?,?,?)',
            [$org,(int)$this->user['id'],'TEXTBOOK_BOOK_DRAFT_DISCARDED_BY_OFFICE','TEXTBOOK_BOOK_PROFILE',$id,
                json_encode(['origin_org'=>(int)$s['registered_by_organization_id'],
                    'version'=>$old['version']+1,'no_stock_change'=>true],JSON_THROW_ON_ERROR)]);
        return ['id'=>$id,'number'=>'BK'.str_pad((string)$id,10,'0',STR_PAD_LEFT),
            'status'=>'DISCARDED','version'=>$old['version']+1,'can_receive'=>false];
    }

    public function detail(array $input):array {
        $o=$this->office((int)($input['organization_id']??0));
        $r=$this->record((int)($input['book_id']??0));
        demand($this->canView($r['snapshot'],$o),'Book draft is not visible in this office',403);
        $canEdit=$this->canEdit($r['snapshot'],$o);
        $activeEditor=$canEdit && ($r['snapshot']['status']??null)!=='DISCARDED';
        // Only the registering office and supervising Division may see
        // previous draft evidence/metadata. Other offices receive the current
        // verified bibliographic profile but not historical private drafts.
        $history=[];
        if($canEdit){
            $versions=q($this->db,"SELECT version_no,snapshot_json,reason,actor_id,created_at FROM document_versions
                WHERE organization_id=? AND doc_type='TBP' AND entity_id=? ORDER BY version_no DESC LIMIT 100",
                [(int)$r['snapshot']['registered_by_organization_id'],$r['id']])->fetchAll(PDO::FETCH_ASSOC);
            foreach($versions as $v){
                $old=json_decode((string)$v['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
                demand(is_array($old) && ($old['type']??null)===self::TYPE,
                    'Historical book profile audit requires review',409);
                $history[]=['version'=>(int)$v['version_no'],'when'=>$v['created_at'],
                    'actor_id'=>(int)$v['actor_id'],'reason'=>$v['reason'],
                    'changed_fields'=>$old['last_changed_fields']??[],
                    'status'=>$old['status']];
            }
        }
        return ['id'=>$r['id'],'version'=>$r['version'],
            'snapshot'=>$r['snapshot'],'can_edit'=>$activeEditor,'history'=>$history,
            'can_receive'=>$r['snapshot']['status']==='VERIFIED' && $r['snapshot']['title_id']!==null];
    }
    /**
     * Global, office-authorized ISBN picker for CO receipts.
     * Only published bibliographic profiles and eligible legacy title metadata
     * are exposed. Draft notes, foreign-school drafts and all stock are excluded.
     * Uses the same ISBN uniqueness/conflict constraints as receipt checkTitles().
     * No physical stock writes and no catalog mutations.
     */
    public function receiptSearch(array $input):array {
        $this->office((int)($input['organization_id']??0));
        $term=trim((string)($input['q']??''));
        demand(mbSafeLen($term)<=100,'Book picker search must be at most 100 characters',422);
        if(mbSafeLen($term)<2)return ['rows'=>[],'has_more'=>false,'minimum_characters'=>2];

        $source=q($this->db,'SELECT id,title,isbn,grade,subject,edition,publisher FROM textbook_titles ORDER BY id ASC LIMIT 3001')
            ->fetchAll(PDO::FETCH_ASSOC);
        demand(count($source)<=3000,
            'Full ISBN directory review is required before selecting books for CO receiving',409);

        // Attach optional author/edition details only from globally VERIFIED
        // profiles whose ISBN is already linked to the physical title registry.
        $byTitle=[];
        foreach($this->profiles() as $record){
            $s=$record['snapshot'];
            if(($s['status']??null)!=='VERIFIED' || (int)($s['title_id']??0)<=0)continue;
            $fields=$s['fields']??[];
            if(!is_array($fields)||empty($fields['isbn13']))continue;
            $byTitle[(int)$s['title_id']]=$fields;
        }

        $groups=[];
        foreach($source as $row){
            try{$isbn=TextbookIsbn::canonical(trim((string)($row['isbn']??'')));}
            catch(InvalidArgumentException $e){continue;}
            $groups[$isbn][]=$row;
        }
        $simplify=static function(string $s):string {
            $s=trim(preg_replace('/\s+/u',' ',$s)??$s);
            return function_exists('mb_strtolower')?mb_strtolower($s,'UTF-8'):strtolower($s);
        };
        $needle=$simplify($term);
        $tokens=preg_split('/\s+/u',$needle,-1,PREG_SPLIT_NO_EMPTY)?:[];
        $isbnNeedle=preg_replace('/[^0-9X]/i','',$term)??'';
        $isbnOnlyQuery=$isbnNeedle!=='' && preg_match('/^[0-9X\\s-]+$/i',$term)===1;
        $canonicalQuery=null;
        if($isbnOnlyQuery){try{$canonicalQuery=TextbookIsbn::canonical($term);}
            catch(InvalidArgumentException $e){/* An ISBN prefix is still searchable. */}}
        $matches=[];
        foreach($groups as $isbn=>$group){
            $isbn=(string)$isbn; // PHP casts purely numeric array keys to integer.
            // Receipt processing itself rejects contradictory title/grade/subject
            // metadata for a repeated canonical ISBN; do not offer it here.
            $signatures=[];
            foreach($group as $r)$signatures[]=implode('|',[
                $simplify((string)$r['title']),$simplify((string)($r['grade']??'')),
                $simplify((string)($r['subject']??''))]);
            if(count(array_unique($signatures))!==1)continue;
            $row=$group[0];$verified=null;
            foreach($group as $candidate){
                $profile=$byTitle[(int)$candidate['id']]??null;
                if($profile!==null && ($profile['isbn13']??null)===$isbn){$verified=$profile;break;}
            }
            $entry=[
                'title'=>(string)$row['title'],
                'isbn13'=>$isbn,
                'grade'=>(string)($verified['grade']??$row['grade']??''),
                'subject'=>(string)($verified['subject']??$row['subject']??''),
                'authors'=>(string)($verified['authors']??''),
                'edition'=>(string)($verified['edition']??$row['edition']??''),
                'publisher'=>(string)($verified['publisher']??$row['publisher']??''),
                'material_type'=>(string)($verified['material_type']??'TEXTBOOK'),
                'catalog_status'=>$verified!==null?'VERIFIED':'LEGACY_ISBN',
            ];
            $haystack=$simplify(implode(' ',array_values($entry)).
                ($entry['grade']!==''?' Grade '.$entry['grade']:''));
            $found=true;
            foreach($tokens as $token){
                if(str_contains($haystack,$token))continue;
                // ISBN punctuation and spacing do not affect lookup.
                if($isbnOnlyQuery && (str_contains($isbn,$isbnNeedle)||$canonicalQuery===$isbn))continue;
                $found=false;break;
            }
            if(!$found)continue;
            $matches[]=$entry;
        }
        usort($matches,static function(array $a,array $b)use($needle,$simplify):int{
            $rank=static fn(array $r):int=>$simplify($r['title'])===$needle?0:
                (str_starts_with($simplify($r['title']),$needle)?1:2);
            return $rank($a)<=>$rank($b)?:strnatcasecmp($a['title'],$b['title'])?:strcmp($a['isbn13'],$b['isbn13']);
        });
        return ['rows'=>array_slice($matches,0,25),'has_more'=>count($matches)>25,
            'minimum_characters'=>2,
            'note'=>'Search covers shared verified books and eligible historical ISBN records. Conflicting or invalid ISBN entries are not receivable.'];
    }

    public function directory(array $input):array {
        $o=$this->office((int)($input['organization_id']??0));
        $term=trim((string)($input['q']??''));
        demand(mbSafeLen($term)<=100,'Book search may be at most 100 characters',422);
        $page=(int)($input['page']??1);
        demand($page>=1 && $page<=100,'Invalid book directory page',422);
        $show=(string)($input['include_discarded']??'0');
        demand(in_array($show,['0','1'],true),'Invalid discarded-drafts filter',422);
        $includeDiscarded=$show==='1';
        $rows=[];
        foreach($this->profiles() as $record){
            $s=$record['snapshot'];$d=$s['fields'];
            $canEdit=$this->canEdit($s,$o);
            $discarded=($s['status']??null)==='DISCARDED';
            if($discarded && (!$includeDiscarded || !$canEdit))continue;
            $shared=($s['status']??null)==='VERIFIED';
            // When another receiving school searches a known ISBN/LR code
            // that an office already registered as a draft, show ONLY the
            // minimal identity as pending. Never leak unpublished authors,
            // internal evidence notes or other metadata. Prevents a silent
            // duplicate-registration dead end while origin verification is pending.
            if(!$shared && !$canEdit && ($d['identity_key']??null)===null)continue;
            $text=implode(' ',array_map('strval',[$d['title'],$d['isbn'],$d['isbn13']??'',
                $d['official_lr_code'],$shared||$canEdit?$d['grade']:'',
                $shared||$canEdit?$d['subject']:'',$shared||$canEdit?$d['authors']:'',
                $shared||$canEdit?$d['publisher']:'',$shared||$canEdit?$d['edition']:'']));
            if($term!=='' && (function_exists('mb_stripos')?mb_stripos($text,$term,0,'UTF-8'):stripos($text,$term))===false)
                continue;
            $rows[]=['id'=>$record['id'],'version'=>$record['version'],'title'=>$d['title'],
                'isbn'=>$d['isbn13'],'lr_code'=>$d['official_lr_code'],
                'grade'=>$shared||$canEdit?$d['grade']:'',
                'subject'=>$shared||$canEdit?$d['subject']:'',
                'material_type'=>$d['material_type'],
                'publisher'=>$shared||$canEdit?$d['publisher']:'',
                'edition'=>$shared||$canEdit?$d['edition']:'',
                'status'=>$discarded?'DISCARDED':($shared?'VERIFIED':($canEdit?'DRAFT':'PENDING_VERIFICATION')),
                'origin_organization_id'=>(int)$s['registered_by_organization_id'],
                'can_edit'=>$canEdit && !$discarded,'can_view_details'=>$shared||$canEdit,
                'can_receive'=>$shared && $s['title_id']!==null];
        }
        usort($rows,static fn($a,$b)=>strnatcasecmp($a['title'],$b['title']) ?: $a['id']<=>$b['id']);
        $start=($page-1)*50;
        return ['rows'=>array_slice($rows,$start,50),'page'=>$page,
            'next_page'=>count($rows)>$start+50?$page+1:null,'matching'=>count($rows),
            'note'=>'Verified profiles are shared globally. Drafts are private to their origin and Division. Discarded drafts are hidden by default, audit-retained and only visible in the origin office/Division with the filter. Stock is never changed by book-profile actions.'];
    }
}
