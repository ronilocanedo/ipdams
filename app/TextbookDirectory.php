<?php
declare(strict_types=1);
require_once __DIR__.'/TextbookIsbn.php';

/**
 * Shared bibliographic catalog preview. This deliberately does NOT create,
 * merge or re-key textbook_titles or textbook_batches, and NEVER reads an
 * individual school's stock, teachers or transactions.
 *
 * Legacy textbook_titles has no canonical unique ISBN constraint. Until the
 * duplicate audit and migration have been approved, only read-only directory
 * operations are supported. The bounded scan reports coverage honestly.
 */
final class TextbookDirectory {
    private const SCAN_LIMIT = 3000;
    private const PAGE_SIZE = 50;

    public static function read(PDO $db, array $user, array $input): array {
        demand(in_array((string)($user['role']??''), ['SCHOOL','DIVISION','SUPER_ADMIN'], true),
            'Textbook directory requires an authorized school or Division account', 403);
        $org=idOf($input,'organization_id');
        scope($db,$user,$org);
        $office=one($db,"SELECT id,kind FROM organizations WHERE id=? AND active=1 AND kind IN ('SCHOOL','DIVISION')",[$org]);
        demand($office!==null,'An active school or Division workspace is required',403);
        $term=trim((string)($input['q']??''));
        demand((function_exists('mb_strlen')?mb_strlen($term,'UTF-8'):strlen($term))<=100,'Search text must be at most 100 characters',400);
        $rawPage=(string)($input['page']??'1');
        demand(ctype_digit($rawPage) && (int)$rawPage>=1 && (int)$rawPage<=100,
            'Invalid directory page',400);
        $page=(int)$rawPage;

        // Bounded on shared hosting. No organization-specific holdings are
        // retrieved; the shared title registry is just bibliographic metadata.
        $source=q($db,'SELECT id,title,isbn,grade,subject FROM textbook_titles ORDER BY id ASC LIMIT 3001')->fetchAll();
        $complete=count($source)<=self::SCAN_LIMIT;
        if(!$complete)$source=array_slice($source,0,self::SCAN_LIMIT);
        $groups=[];
        $missing=0;$invalid=0;
        foreach($source as $row){
            $id=(int)$row['id'];
            $isbn=trim((string)($row['isbn']??''));
            $canonical=null;
            if($isbn==='')$missing++;
            else{
                try {$canonical=TextbookIsbn::canonical($isbn);}
                catch(InvalidArgumentException $e){$invalid++;}
            }
            $key=$canonical!==null?'ISBN:'.$canonical:'LEGACY:'.$id;
            $entry=[
                'id'=>$id,'title'=>(string)$row['title'],'isbn'=>$isbn,
                'canonical_isbn'=>$canonical,'grade'=>(string)($row['grade']??''),
                'subject'=>(string)($row['subject']??'')
            ];
            if(!isset($groups[$key])){
                $groups[$key]=['entry'=>$entry,'members'=>[$entry],'status'=>$canonical!==null?'VALID':($isbn===''?'NO_ISBN':'INVALID_ISBN')];
            }else{
                $groups[$key]['members'][]=$entry;
                // A single canonical ISBN should describe one edition. Do not
                // silently resolve inconsistent original bibliographic fields.
                $initial=$groups[$key]['entry'];
                $same=self::metaKey($initial)===self::metaKey($entry);
                $groups[$key]['status']=(!$same||$groups[$key]['status']==='CONFLICT')?'CONFLICT':'DUPLICATE';
            }
        }
        $entries=[];$dup=0;$conflict=0;
        foreach($groups as $g){
            $main=$g['entry'];$members=$g['members'];$count=count($members);
            if($count>1)$dup++;
            if($g['status']==='CONFLICT')$conflict++;
            $main['source_rows']=$count;
            $main['source_ids']=implode(', ',array_map(static fn($a)=>(string)$a['id'],array_slice($members,0,25)))
                .($count>25?' …':'');
            $main['review']=$g['status'];
            $main['different_titles']=implode(' / ',array_slice(array_values(array_unique(array_map(
                static fn($a)=>$a['title'],$members))),0,4));
            $entries[]=$main;
        }
        usort($entries,static function($a,$b):int {
            $aKey=self::lower($a['title']);
            $bKey=self::lower($b['title']);
            return strcmp($aKey,$bKey) ?: ($a['id']<=>$b['id']);
        });
        if($term!==''){
            $needle=self::lower($term);
            $canonicalNeedle=null;
            try {$canonicalNeedle=TextbookIsbn::canonical($term);}
            catch(InvalidArgumentException $e){}
            $entries=array_values(array_filter($entries,static function($entry) use($needle,$canonicalNeedle):bool {
                $haystack=self::lower(implode(' ',[
                    $entry['title'],$entry['isbn'],$entry['canonical_isbn']??'',
                    $entry['grade'],$entry['subject'],$entry['different_titles'],$entry['review']
                ]));
                return str_contains($haystack,$needle)||($canonicalNeedle!==null&&$entry['canonical_isbn']===$canonicalNeedle);
            }));
        }
        $matching=count($entries);
        $start=($page-1)*self::PAGE_SIZE;
        return [
            'rows'=>array_slice($entries,$start,self::PAGE_SIZE),
            'page'=>$page,'page_size'=>self::PAGE_SIZE,'next_page'=>$matching>$start+self::PAGE_SIZE?$page+1:null,
            'matching_groups'=>$matching,'query'=>$term,
            'summary'=>[
                'scanned_title_rows'=>count($source),
                'coverage_complete'=>$complete,
                'canonical_isbn_groups'=>count(array_filter($groups,static fn($g)=>$g['entry']['canonical_isbn']!==null)),
                'duplicate_isbn_groups'=>$dup,'conflicting_isbn_groups'=>$conflict,
                'without_isbn_rows'=>$missing,'invalid_isbn_rows'=>$invalid
            ],
            'read_only'=>true,
            'note'=>'ISBN directory entries are shared bibliographic data, not school stock. Duplicated or invalid legacy rows are flagged, not merged or altered. Only the first 3,000 legacy rows are scanned; coverage is flagged if incomplete.'
        ];
    }
    private static function lower(string $s): string {
        return function_exists('mb_strtolower')?mb_strtolower($s,'UTF-8'):strtolower($s);
    }
    private static function metaKey(array $entry): string {
        return implode('|',array_map(static fn($s)=>self::lower(trim(preg_replace('/\s+/u',' ',(string)$s)??(string)$s)),
            [$entry['title'],$entry['grade'],$entry['subject']]));
    }
}
